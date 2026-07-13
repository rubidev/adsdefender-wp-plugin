<?php
/**
 * AdsDefender — Rate Limiting
 *
 * Giới hạn số request mỗi IP trong cửa sổ thời gian (sliding window).
 * Dùng lại bảng adsdefender_brute_events để lưu request events.
 *
 *  • Default: 300 requests / 60 giây / IP
 *  • Khi vượt ngưỡng: upsert lockout + trả 429 Too Many Requests
 *  • Chỉ áp dụng cho trang frontend (không phải admin, REST, cron)
 *  • Exclude: static assets (jpg, css, js, png, ...)
 */
if (!defined('ABSPATH')) exit;

// ─── Settings Helpers ─────────────────────────────────────────────────────────

function adsdefender_rl_enabled(): bool
{
    return !empty(adsdefender_settings()['rl_enabled']);
}

function adsdefender_rl_opts(): array
{
    $opts = adsdefender_settings();
    return [
        'max_requests' => max(10,  (int)($opts['rl_max_requests'] ?? 300)),  // requests
        'window_secs'  => max(10,  (int)($opts['rl_window_secs']  ?? 60)),   // giây
        'lockout_mins' => max(5,   (int)($opts['rl_lockout_mins'] ?? 10)),   // phút
    ];
}

// ─── Skip static file requests ───────────────────────────────────────────────

function adsdefender_rl_is_static(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (bool) preg_match('/\.(jpg|jpeg|gif|png|webp|svg|ico|css|js|woff|woff2|ttf|eot|otf|mp4|mp3|pdf|zip)(\?.*)?$/i', $uri);
}

// ─── Record request ───────────────────────────────────────────────────────────

function adsdefender_rl_record(string $ip): void
{
    global $wpdb;
    // Dùng lại brute_events table nếu tồn tại
    $table = $wpdb->prefix . ADSDEFENDER_BF_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

    $wpdb->insert($table, [
        'type'       => 'rl',
        'value'      => substr($ip, 0, 45),
        'created_at' => current_time('mysql'),
    ]);
}

// ─── Count requests in window ─────────────────────────────────────────────────

function adsdefender_rl_count(string $ip, int $window_secs): int
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_BF_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return 0;

    $since = gmdate('Y-m-d H:i:s', time() - $window_secs);
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE type='rl' AND value=%s AND created_at >= %s",
        $ip, $since
    ));
}

// ─── Main check ──────────────────────────────────────────────────────────────

function adsdefender_rate_limit_check(): void
{
    if (!adsdefender_rl_enabled()) return;

    // Skip hệ thống
    if (defined('WP_CLI')         && WP_CLI)         return;
    if (defined('DOING_CRON')     && DOING_CRON)     return;
    if (defined('REST_REQUEST')   && REST_REQUEST)   return;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;
    if (is_admin()) return;
    if (wp_doing_ajax()) return;

    // Skip static files
    if (adsdefender_rl_is_static()) return;

    $ip = adsdefender_get_real_ip();
    if (!$ip) return;

    // Temp/permanent whitelist không bị rate limit
    if (adsdefender_is_temp_whitelisted($ip)) return;
    $opts_s = adsdefender_settings();
    $whitelist = array_filter(array_map('trim', explode("\n", $opts_s['whitelist'] ?? '')));
    if (!empty($whitelist) && adsdefender_ip_matches($ip, $whitelist)) return;

    $cfg = adsdefender_rl_opts();

    // Ghi request trước khi đếm (đếm bao gồm request hiện tại)
    adsdefender_rl_record($ip);

    $count = adsdefender_rl_count($ip, $cfg['window_secs']);
    if ($count > $cfg['max_requests']) {
        // Lockout tạm thời
        adsdefender_upsert_lockout($ip, 'rate_limit');
        adsdefender_log_block($ip);
        adsdefender_sec_log('rate_limit', $ip, "{$count} req / {$cfg['window_secs']}s");

        if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
        nocache_headers();

        http_response_code(429);
        header('Retry-After: ' . ($cfg['lockout_mins'] * 60));
        header('Content-Type: text/plain; charset=utf-8');
        echo '429 Too Many Requests. Please slow down.';
        exit;
    }
}

// ─── Hook vào init priority 1 ────────────────────────────────────────────────

add_action('init', 'adsdefender_rate_limit_check', 1);
