<?php
/**
 * AdsDefender — 404 Flood Detection
 *
 * Phát hiện IP scan thư mục/file bằng cách đếm 404 requests:
 *  • Default: 20 lần 404 trong 60 giây → lockout
 *  • Dùng lại bảng adsdefender_brute_events
 *  • Chỉ track 404 thật (không phải search, không phải trang WP)
 *  • Exclude: favicon.ico, robots.txt (404 bình thường)
 */
if (!defined('ABSPATH')) exit;

// ─── Settings Helpers ─────────────────────────────────────────────────────────

function adsdefender_404_enabled(): bool
{
    return !empty(adsdefender_settings()['detect_404']);
}

function adsdefender_404_opts(): array
{
    $opts = adsdefender_settings();
    return [
        'max_404'      => max(5,  (int)($opts['detect_404_max']    ?? 20)),  // lần
        'window_secs'  => max(30, (int)($opts['detect_404_window'] ?? 60)),  // giây
        'lockout_mins' => max(5,  (int)($opts['detect_404_lockout'] ?? 30)), // phút
    ];
}

// ─── Exclude common benign 404s ───────────────────────────────────────────────

function adsdefender_404_is_excluded(string $uri): bool
{
    return (bool) preg_match('#(favicon\.ico|robots\.txt|apple-touch-icon|sitemap\.xml|\.well-known/)#i', $uri);
}

// ─── Record 404 event ─────────────────────────────────────────────────────────

function adsdefender_404_record(string $ip, string $uri): void
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_BF_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

    $wpdb->insert($table, [
        'type'       => '404',
        'value'      => substr($ip, 0, 45),
        'created_at' => current_time('mysql'),
    ]);
}

// ─── Count 404s ───────────────────────────────────────────────────────────────

function adsdefender_404_count(string $ip, int $window_secs): int
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_BF_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return 0;

    $since = gmdate('Y-m-d H:i:s', time() - $window_secs);
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE type='404' AND value=%s AND created_at >= %s",
        $ip, $since
    ));
}

// ─── Hook: wp → check 404 sau khi WP resolve request ───────────────────────

add_action('wp', function () {
    if (!adsdefender_404_enabled()) return;
    if (!is_404()) return;

    // Skip hệ thống
    if (defined('WP_CLI')       && WP_CLI)       return;
    if (defined('DOING_CRON')   && DOING_CRON)   return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (is_admin()) return;

    $ip  = adsdefender_get_real_ip();
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    if (!$ip) return;

    // Whitelist không bị count
    if (adsdefender_is_temp_whitelisted($ip)) return;

    if (adsdefender_404_is_excluded($uri)) return;

    $cfg = adsdefender_404_opts();

    adsdefender_404_record($ip, $uri);
    $count = adsdefender_404_count($ip, $cfg['window_secs']);

    if ($count >= $cfg['max_404']) {
        adsdefender_upsert_lockout($ip, '404_flood');
        adsdefender_log_block($ip);
        adsdefender_sec_log('flood_404', $ip, "{$count}x 404 | {$uri}");
        // Không exit ở đây — để WP render trang 404 bình thường
        // IP sẽ bị block ở request tiếp theo qua check_ip()
    }
}, 10);
