<?php
/**
 * AdsDefender — Comment Security
 *
 * Bổ sung những gì WP core + Honeypot module KHÔNG có:
 *   1. Rate limit per IP/giờ  — chống flood comment
 *   2. Spam blocklist tự động — 40k+ domains/keywords từ splorp/wordpress-comment-blacklist
 *      Cache 7 ngày, auto-update qua cron
 *
 * Đã có sẵn, KHÔNG duplicate:
 *   - Honeypot/time check → includes/honeypot.php
 *   - Keyword blacklist tự nhập, link limit → WP Settings → Discussion
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_CS_BLOCKLIST_URL',
    'https://raw.githubusercontent.com/splorp/wordpress-comment-blacklist/master/blacklist.txt');
define('ADSDEFENDER_CS_BLOCKLIST_OPTION', 'adsdefender_cs_blocklist');
define('ADSDEFENDER_CS_BLOCKLIST_TIME',   'adsdefender_cs_blocklist_time');

// ─── Settings helpers ─────────────────────────────────────────────────────────

function adsdefender_cs_enabled(): bool
{
    return !empty(adsdefender_settings()['cs_enabled']);
}

function adsdefender_cs_opts(): array
{
    $s = adsdefender_settings();
    return [
        'rate_limit' => max(1, (int)($s['cs_rate_limit'] ?? 5)), // comments/hour/IP
        'blocklist'  => (bool)($s['cs_blocklist'] ?? true),
    ];
}

// ─── Spam blocklist — fetch + cache ──────────────────────────────────────────

function adsdefender_cs_get_blocklist(): array
{
    $time = (int) get_option(ADSDEFENDER_CS_BLOCKLIST_TIME, 0);
    if ($time && (time() - $time) < 7 * DAY_IN_SECONDS) {
        return get_option(ADSDEFENDER_CS_BLOCKLIST_OPTION, []);
    }
    return adsdefender_cs_fetch_blocklist();
}

function adsdefender_cs_fetch_blocklist(): array
{
    $response = wp_remote_get(ADSDEFENDER_CS_BLOCKLIST_URL, [
        'timeout'    => 15,
        'user-agent' => 'AdsDefender/' . ADSDEFENDER_VERSION,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        // Retry sau 1h thay vì sau 7 ngày
        update_option(ADSDEFENDER_CS_BLOCKLIST_TIME, time() - 6 * DAY_IN_SECONDS, false);
        return get_option(ADSDEFENDER_CS_BLOCKLIST_OPTION, []);
    }

    $body = wp_remote_retrieve_body($response);
    $list = array_values(array_filter(
        array_map('trim', explode("\n", $body)),
        fn($l) => $l !== '' && $l[0] !== '#'
    ));

    update_option(ADSDEFENDER_CS_BLOCKLIST_OPTION, $list, false);
    update_option(ADSDEFENDER_CS_BLOCKLIST_TIME, time(), false);

    return $list;
}

// Cron: refresh blocklist mỗi 7 ngày
add_action(ADSDEFENDER_CRON_HOOK, function () {
    if (!adsdefender_cs_enabled()) return;
    if (!(adsdefender_cs_opts()['blocklist'])) return;
    $time = (int) get_option(ADSDEFENDER_CS_BLOCKLIST_TIME, 0);
    if ((time() - $time) >= 7 * DAY_IN_SECONDS) {
        adsdefender_cs_fetch_blocklist();
    }
}, 30);

// ─── Main validation ──────────────────────────────────────────────────────────

add_filter('preprocess_comment', function (array $data): array {
    if (!adsdefender_cs_enabled()) return $data;
    if (is_user_logged_in() && current_user_can('moderate_comments')) return $data;

    $opts  = adsdefender_cs_opts();
    $ip    = $data['comment_author_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

    // 1. Rate limit per IP/giờ
    if ($ip) {
        global $wpdb;
        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments}
             WHERE comment_author_IP = %s
               AND comment_date > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $ip
        ));
        if ($count >= $opts['rate_limit']) {
            adsdefender_cs_reject("Rate limit ({$count}/h)", $ip);
        }
    }

    // 2. Spam blocklist — kiểm tra content + author + url + email
    if ($opts['blocklist']) {
        $haystack = strtolower(implode(' ', [
            $data['comment_content']    ?? '',
            $data['comment_author']     ?? '',
            $data['comment_author_url'] ?? '',
            $data['comment_author_email'] ?? '',
        ]));
        foreach (adsdefender_cs_get_blocklist() as $term) {
            if ($term !== '' && strpos($haystack, strtolower($term)) !== false) {
                adsdefender_cs_reject("Blocklist: {$term}", $ip);
            }
        }
    }

    return $data;
});

// ─── Reject helper ────────────────────────────────────────────────────────────

function adsdefender_cs_reject(string $reason, string $ip = ''): never
{
    adsdefender_sec_log('comment_spam', $ip, $reason);
    wp_die(
        '<p>Bình luận của bạn không thể được gửi. Vui lòng thử lại.</p>',
        'Bình luận bị từ chối',
        ['response' => 403, 'back_link' => true]
    );
}

// ─── AJAX: manual refresh blocklist ──────────────────────────────────────────

add_action('wp_ajax_adsdefender_cs_refresh_blocklist', function () {
    check_ajax_referer('adsdefender_cs_refresh', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $list = adsdefender_cs_fetch_blocklist();
    wp_send_json_success([
        'count'      => count($list),
        'updated_at' => wp_date('H:i d/m/Y', (int)get_option(ADSDEFENDER_CS_BLOCKLIST_TIME)),
    ]);
});

// ─── Stats helper ─────────────────────────────────────────────────────────────

function adsdefender_cs_stats(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'adsdefender_security_log';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return ['blocked_today' => 0, 'blocked_7d' => 0, 'blocklist_count' => 0, 'blocklist_updated' => 'Chưa tải'];
    }
    return [
        'blocked_today'     => (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE event_type='comment_spam' AND created_at >= CURDATE()"
        ),
        'blocked_7d'        => (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE event_type='comment_spam' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        ),
        'blocklist_count'   => count(get_option(ADSDEFENDER_CS_BLOCKLIST_OPTION, [])),
        'blocklist_updated' => get_option(ADSDEFENDER_CS_BLOCKLIST_TIME, 0)
            ? wp_date('d/m/Y', (int)get_option(ADSDEFENDER_CS_BLOCKLIST_TIME))
            : 'Chưa tải',
    ];
}
