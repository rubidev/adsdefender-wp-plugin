<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
if (!defined('ABSPATH')) exit;

// ─── Settings cache ────────────────────────────────────────────────────────────

function adsdefender_settings(bool $refresh = false): array
{
    static $s = null;
    if ($s === null || $refresh) $s = get_option('adsdefender_settings', []);
    return $s;
}

add_action('update_option_adsdefender_settings', function () {
    adsdefender_settings(true);
}, 10, 0);

// ─── Auto-upgrade khi update plugin (không cần deactivate/activate) ──────────

add_action('plugins_loaded', function () {
    $installed = get_option('adsdefender_db_version', '0');
    if (version_compare($installed, ADSDEFENDER_VERSION, '>=')) return;

    // Chạy tất cả migration theo thứ tự version
    adsdefender_run_migrations($installed);

    update_option('adsdefender_db_version', ADSDEFENDER_VERSION, false);
}, 5);

function adsdefender_run_migrations(string $from): void
{
    // Migration 2.5.122 — trang chặn render tại chỗ, bỏ file tĩnh trong thư mục gốc;
    // bảng Live Traffic
    if (version_compare($from, '2.5.122', '<')) {
        if (function_exists('adsdefender_lt_create_table')) adsdefender_lt_create_table();
        $static = ABSPATH . 'adsdefender-blocked.html';
        if (is_file($static) && strpos((string) @file_get_contents($static, false, null, 0, 20000), 'AdsDefender') !== false) {
            @unlink($static);
        }
    }

    // Migration 2.5.79 — tạo bảng scan_results
    if (version_compare($from, '2.5.79', '<')) {
        if (function_exists('adsdefender_scan_create_table')) adsdefender_scan_create_table();
    }

    // Migration 2.5.78 — tạo bảng file_monitor
    if (version_compare($from, '2.5.78', '<')) {
        if (function_exists('adsdefender_fm_create_table')) adsdefender_fm_create_table();
    }

    // Migration 2.5.59 — tạo bảng admin_log
    if (version_compare($from, '2.5.59', '<')) {
        if (function_exists('adsdefender_create_admin_log_table')) adsdefender_create_admin_log_table();
    }

    // Migration 2.5.58 — tạo bảng security_log tập trung
    if (version_compare($from, '2.5.58', '<')) {
        adsdefender_create_sec_log_table();
    }

    // Migration 2.5.39 — tạo bảng brute_events cho security modules
    if (version_compare($from, '2.5.39', '<')) {
        if (function_exists('adsdefender_create_bf_table')) adsdefender_create_bf_table();
    }

    // Migration 2.5.37 — tạo bảng lockouts + dọn dẹp dữ liệu cũ + xóa IP khỏi .htaccess
    if (version_compare($from, '2.5.37', '<')) {
        // 1. Tạo / cập nhật tất cả DB tables
        adsdefender_create_lockouts_table();
        adsdefender_create_log_table();
        if (function_exists('adsdefender_create_utm_table'))  adsdefender_create_utm_table();
        if (function_exists('adsdefender_create_lead_table')) adsdefender_create_lead_table();

        // 2. Xóa block log cũ vượt 10k dòng
        global $wpdb;
        $log_table = $wpdb->prefix . ADSDEFENDER_LOG_TABLE;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$log_table}`");
        if ($count > 10000) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$log_table}` ORDER BY id ASC LIMIT %d",
                $count - 10000
            ));
        }

        // 4. Xóa temp whitelist cũ (nếu có từ version lỗi)
        delete_option('adsdefender_temp_whitelist');

        // 5. Cấu hình lại cache plugins
        adsdefender_configure_cache_plugins();
    }
}

// ─── REST Token ───────────────────────────────────────────────────────────────

function adsdefender_sslverify(): bool
{
    return (bool) apply_filters('adsdefender_sslverify', true);
}

function adsdefender_rest_token(): string
{
    $t = get_option('adsdefender_rest_token', '');
    if (!$t) {
        try {
            $t = bin2hex(random_bytes(24));
        } catch (Exception $e) {
            $t = wp_generate_password(48, false, false);
        }
        update_option('adsdefender_rest_token', $t, false);
    }
    return $t;
}

/**
 * Token BÍ MẬT cho /sync (lệnh đồng bộ từ máy chủ). Tách khỏi adsdefender_rest_token() vì token kia
 * được in ra mọi trang (window.adUTM.token) cho popup / conversion → ai xem mã nguồn cũng có.
 */
function adsdefender_sync_token(): string
{
    $t = get_option('adsdefender_sync_token', '');
    if (!$t) {
        $t = bin2hex(random_bytes(24));
        update_option('adsdefender_sync_token', $t, false);
    }
    return $t;
}

function adsdefender_verify_sync_token(WP_REST_Request $req)
{
    $token = (string) ($req->get_header('X-ADS-Token') ?: $req->get_param('_ads_token'));
    if (!hash_equals(adsdefender_sync_token(), $token)) {
        return new WP_Error('adsdefender_forbidden', 'Invalid token', ['status' => 403]);
    }
    return true;
}

/** Token CÔNG KHAI (in trong trang) — chỉ chống gửi form tự động đơn giản, không phải bí mật. */
function adsdefender_verify_rest_token(WP_REST_Request $req) {
    $token = $req->get_header('X-ADS-Token') ?: $req->get_param('_ads_token');
    if (!hash_equals(adsdefender_rest_token(), (string)$token)) {
        return new WP_Error('adsdefender_forbidden', 'Invalid token', ['status' => 403]);
    }
    return true;
}

// ─── Cron ─────────────────────────────────────────────────────────────────────

add_filter('cron_schedules', function ($schedules) {
    $schedules['adsdefender_30min'] = ['interval' => 1800, 'display' => 'Every 30 minutes'];
    return $schedules;
});
add_action(ADSDEFENDER_CRON_HOOK, 'adsdefender_sync_now');

// ─── DB Tables ────────────────────────────────────────────────────────────────

// ─── Lockout Table ────────────────────────────────────────────────────────────

function adsdefender_create_lockouts_table()
{
    global $wpdb;
    $table   = $wpdb->prefix . ADSDEFENDER_LOCKOUT_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip            VARCHAR(45)  NOT NULL,
        source        VARCHAR(20)  NOT NULL DEFAULT 'matomo',
        lockout_count INT UNSIGNED NOT NULL DEFAULT 1,
        first_seen    DATETIME     NOT NULL,
        last_seen     DATETIME     NOT NULL,
        expires_at    DATETIME     NOT NULL,
        is_active     TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ip (ip),
        KEY idx_active_expires (is_active, expires_at)
    ) {$charset};");
}

function adsdefender_create_log_table()
{
    global $wpdb;
    $table   = $wpdb->prefix . ADSDEFENDER_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip          VARCHAR(45)  NOT NULL,
        url         VARCHAR(500) NOT NULL DEFAULT '',
        user_agent  VARCHAR(300) NOT NULL DEFAULT '',
        blocked_at  DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_ip (ip),
        KEY idx_blocked_at (blocked_at)
    ) {$charset};");
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

// Luôn trả về array — tránh crash khi DB corrupt (serialize encoding lỗi)
function adsdefender_get_manual_ips(): array
{
    $raw = get_option(ADSDEFENDER_OPTION_MANUAL, []);
    return is_array($raw) ? $raw : [];
}

function adsdefender_parse_whitelist(string $raw): array
{
    $lines = explode("\n", $raw);
    $ips   = [];
    foreach ($lines as $line) {
        // Strip inline comments (# ...)
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line !== '') $ips[] = $line;
    }
    return $ips;
}

// ─── Lockout Core Logic ───────────────────────────────────────────────────────

/**
 * Upsert lockout cho 1 IP — atomic ON DUPLICATE KEY, không race condition.
 * Nếu lockout_count >= ban_threshold → tự động ban vĩnh viễn.
 */
function adsdefender_upsert_lockout(string $ip, string $source = 'matomo'): void
{
    // IP trong permanent ban → không cần track thêm
    $manual = adsdefender_get_manual_ips();
    if (!empty($manual) && adsdefender_ip_matches($ip, array_column($manual, 'ip'))) return;

    // IP trong whitelist → không lockout
    $opts      = adsdefender_settings();
    $whitelist = adsdefender_parse_whitelist($opts['whitelist'] ?? '');
    if (!empty($whitelist) && adsdefender_ip_matches($ip, $whitelist)) return;
    if (adsdefender_is_temp_whitelisted($ip)) return;

    // Chốt chặn cuối: không bao giờ khoá bot hợp pháp đã xác minh, bất kể
    // module nào gọi tới. Chỉ áp dụng khi khoá chính IP đang request.
    if ($ip === adsdefender_get_real_ip() && adsdefender_is_verified_bot($ip)) return;

    global $wpdb;
    $table    = $wpdb->prefix . ADSDEFENDER_LOCKOUT_TABLE;
    $duration = max(1, (int)($opts['lockout_duration'] ?? 1440)); // phút

    // Mọi mốc thời gian của bảng lockout đều do MySQL tính (NOW()) — trước đây expires_at tính bằng
    // gmdate (UTC) rồi so với NOW() (giờ MySQL, thường là +07 ở hosting VN) → lockout < 7 giờ hết hạn
    // ngay khi vừa tạo, lockout 24h thực tế chỉ còn 17h.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO `{$table}` (ip, source, lockout_count, first_seen, last_seen, expires_at, is_active)
         VALUES (%s, %s, 1, NOW(), NOW(), NOW() + INTERVAL %d MINUTE, 1)
         ON DUPLICATE KEY UPDATE
             lockout_count = lockout_count + 1,
             last_seen     = NOW(),
             expires_at    = NOW() + INTERVAL %d MINUTE,
             is_active     = 1",
        $ip, $source, $duration, $duration
    ));

    adsdefender_maybe_permanent_ban($ip);
}

/**
 * Kiểm tra xem IP có đang trong lockout tạm thời không.
 */
function adsdefender_check_lockout(string $ip): bool
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_LOCKOUT_TABLE;
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE ip = %s AND is_active = 1 AND expires_at > NOW()",
        $ip
    ));
    return $count > 0;
}

/**
 * Nếu IP tái phạm đủ lần trong ban_period ngày → ban vĩnh viễn + ghi .htaccess.
 */
function adsdefender_maybe_permanent_ban(string $ip): void
{
    $opts      = adsdefender_settings();
    $threshold = max(1, (int)($opts['ban_threshold'] ?? 3));
    $period    = max(1, (int)($opts['ban_period']    ?? 7));

    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_LOCKOUT_TABLE;
    $row   = $wpdb->get_row($wpdb->prepare(
        "SELECT lockout_count, TIMESTAMPDIFF(SECOND, first_seen, NOW()) AS age FROM `{$table}` WHERE ip = %s",
        $ip
    ));
    if (!$row) return;

    $days_since = max(0, (int) $row->age) / DAY_IN_SECONDS;   // tính trong MySQL — tránh lệch múi giờ PHP / MySQL
    if ((int)$row->lockout_count < $threshold || $days_since > $period) return;

    // Ban vĩnh viễn
    $manual = adsdefender_get_manual_ips();
    $exists = !empty(array_filter($manual, fn($m) => ($m['ip'] ?? '') === $ip));
    if (!$exists) {
        $manual[] = [
            'ip'      => $ip,
            'note'    => "Auto-ban after {$row->lockout_count} lockouts",
            'added'   => current_time('mysql'),
            'source'  => 'lockout',
        ];
        update_option(ADSDEFENDER_OPTION_MANUAL, $manual, false);

    }

    // Mark lockout as escalated
    $wpdb->update($table, ['is_active' => 0], ['ip' => $ip], ['%d'], ['%s']);
}

/**
 * Cleanup lockout records hết hạn — chạy đầu mỗi cron.
 */
function adsdefender_cleanup_lockouts(): void
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_LOCKOUT_TABLE;
    $wpdb->query("UPDATE `{$table}` SET is_active = 0 WHERE expires_at < NOW() AND is_active = 1");
}

// ─── Verified Bot (Google / Bing / Yandex …) ─────────────────────────────────
//
// Bot hợp pháp KHÔNG được rate-limit, 404-flood hay honeypot khoá IP.
// Đặc biệt quan trọng với AdsBot-Google: nó crawl landing page rất nhanh để
// chấm điểm chất lượng — bị 429 một lần là quảng cáo có thể bị từ chối.
//
// Không tin UA suông (UA giả mạo rất dễ). Xác minh theo cách Google khuyến nghị:
// rDNS về đúng domain của hãng, rồi forward-confirm hostname đó trở lại IP.
// Kết quả cache 24h vì DNS lookup tốn thời gian.

function adsdefender_bot_ua_map(): array
{
    return [
        // UA fragment (lowercase) => các hậu tố hostname hợp lệ
        'adsbot-google'        => ['.googlebot.com', '.google.com'],
        'mediapartners-google' => ['.googlebot.com', '.google.com'],
        'googlebot'            => ['.googlebot.com', '.google.com'],
        'google-inspectiontool' => ['.googlebot.com', '.google.com'],
        'storebot-google'      => ['.googlebot.com', '.google.com'],
        'google-safety'        => ['.googlebot.com', '.google.com'],
        'apis-google'          => ['.googlebot.com', '.google.com'],
        'feedfetcher-google'   => ['.googlebot.com', '.google.com'],
        'bingbot'              => ['.search.msn.com'],
        'adidxbot'             => ['.search.msn.com'],
        'msnbot'               => ['.search.msn.com'],
        'yandexbot'            => ['.yandex.ru', '.yandex.net', '.yandex.com'],
        'yandexads'            => ['.yandex.ru', '.yandex.net', '.yandex.com'],
        'baiduspider'          => ['.baidu.com', '.baidu.jp'],
        'duckduckbot'          => ['.duckduckgo.com'],
        'applebot'             => ['.applebot.apple.com', '.apple.com'],
    ];
}

/**
 * IP hiện tại có phải bot hợp pháp đã xác minh không?
 * Trả false nếu UA không khai là bot, hoặc rDNS/forward-confirm thất bại.
 */
function adsdefender_is_verified_bot(string $ip = ''): bool
{
    static $memo = null;
    if ($memo !== null) return $memo;   // 1 request chỉ tính 1 lần

    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return $memo = false;

    $suffixes = [];
    foreach (adsdefender_bot_ua_map() as $frag => $hosts) {
        if (str_contains($ua, $frag)) { $suffixes = $hosts; break; }
    }
    if (!$suffixes) return $memo = false;   // UA không khai là bot → khỏi lookup

    $ip = $ip !== '' ? $ip : adsdefender_get_real_ip();
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return $memo = false;

    $key    = 'adsdefender_vbot_' . md5($ip);
    $cached = get_transient($key);
    if ($cached !== false) return $memo = ($cached === '1');

    $ok = adsdefender_bot_rdns_confirm($ip, $suffixes);
    // Cache kết quả tốt lâu hơn kết quả xấu (tránh khoá nhầm vì DNS chập chờn)
    set_transient($key, $ok ? '1' : '0', $ok ? DAY_IN_SECONDS : HOUR_IN_SECONDS);
    return $memo = $ok;
}

/** rDNS + forward-confirm. */
function adsdefender_bot_rdns_confirm(string $ip, array $suffixes): bool
{
    $host = @gethostbyaddr($ip);
    if (!$host || $host === $ip) return false;

    $host = strtolower(rtrim($host, '.'));
    $match = false;
    foreach ($suffixes as $suffix) {
        if (str_ends_with($host, $suffix)) { $match = true; break; }
    }
    if (!$match) return false;

    // Forward-confirm: hostname phải resolve ngược lại chính IP đó
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $recs = @dns_get_record($host, DNS_AAAA);
        foreach ($recs ?: [] as $r) {
            if (!empty($r['ipv6']) && inet_pton($r['ipv6']) === inet_pton($ip)) return true;
        }
        return false;
    }

    $ips = @gethostbynamel($host);
    return is_array($ips) && in_array($ip, $ips, true);
}

// ─── Temp Whitelist (Admin IP tự động) ───────────────────────────────────────

function adsdefender_is_temp_whitelisted(string $ip): bool
{
    $whitelist = get_option('adsdefender_temp_whitelist', []);
    return isset($whitelist[$ip]) && $whitelist[$ip] > time();
}

// Priority 0 — trước check_ip (priority 1)
add_action('init', function () {
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    $ip = adsdefender_get_real_ip();
    if (!$ip) return;
    $whitelist = get_option('adsdefender_temp_whitelist', []);
    $expiry    = time() + DAY_IN_SECONDS;
    // Refresh chỉ khi còn < 1h
    if (isset($whitelist[$ip]) && $whitelist[$ip] > $expiry - HOUR_IN_SECONDS) return;
    // Dọn entries hết hạn
    $whitelist = array_filter($whitelist, fn($e) => $e > time());
    $whitelist[$ip] = $expiry;
    update_option('adsdefender_temp_whitelist', $whitelist, false);
}, 0);

// ─── Telegram ─────────────────────────────────────────────────────────────────

function adsdefender_telegram_cfg(): array
{
    return get_option('adsdefender_telegram', [
        'enabled'   => 0,
        'bot_token' => '',
        'chat_id'   => '',
        'on_lead'   => 1,
        'on_block'  => 1,
    ]);
}

function adsdefender_telegram_send(string $text): bool
{
    $cfg = adsdefender_telegram_cfg();
    if (empty($cfg['enabled']) || empty($cfg['bot_token']) || empty($cfg['chat_id'])) return false;

    $resp = wp_remote_post(
        'https://api.telegram.org/bot' . $cfg['bot_token'] . '/sendMessage',
        [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'chat_id'    => $cfg['chat_id'],
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]),
            'timeout' => 8,
        ]
    );
    return !is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200;
}

// ─── Sync IPs từ Matomo ───────────────────────────────────────────────────────

function adsdefender_sync_now(): bool
{
    $opts    = adsdefender_settings();
    $api_url = $opts['api_url'] ?? '';
    $secret  = $opts['secret']  ?? '';
    $site_id = (int) ($opts['site_id'] ?? 0);
    if (!$api_url || !$secret || !$site_id) return false;

    $url = trailingslashit($api_url) . 'index.php?' . http_build_query([
        'i_am_super_user' => $secret,
        'module'          => 'AdsDefender',
        'action'          => 'getBlockedIPs',
        'idSite'          => $site_id,
    ]);
    $response = wp_remote_get($url, ['timeout' => 60, 'sslverify' => adsdefender_sslverify()]);
    if (is_wp_error($response)) {
        update_option('adsdefender_last_error', $response->get_error_message());
        return false;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['success']) || !isset($data['ips'])) {
        update_option('adsdefender_last_error', 'Invalid response: ' . substr(wp_remote_retrieve_body($response), 0, 200));
        return false;
    }
    $old_ips  = get_option(ADSDEFENDER_OPTION_IPS, []);
    $new_ips  = array_values(array_diff($data['ips'], $old_ips));

    // Cleanup lockout hết hạn trước khi upsert
    adsdefender_cleanup_lockouts();

    // IP mới từ Matomo → lockout tạm thời (có thể leo thang lên ban vĩnh viễn)
    foreach ($new_ips as $ip) {
        // Chỉ upsert IP đơn hợp lệ — bỏ subnet/CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP) && strpos($ip, '/') === false) {
            adsdefender_upsert_lockout($ip, 'matomo');
        }
    }

    update_option(ADSDEFENDER_OPTION_IPS,      $data['ips'],          false);
    update_option(ADSDEFENDER_OPTION_UPDATED,  current_time('mysql'), false);
    delete_option('adsdefender_last_error');

    // Sync visitor blacklist cùng lúc
    adsdefender_sync_visitors(); // lỗi ghi vào adsdefender_last_error_visitors, không block IP sync

    // Telegram alert khi có IP mới bị block
    if (!empty($new_ips)) {
        $tg_cfg = get_option('adsdefender_telegram', []);
        if (!empty($tg_cfg['enabled']) && !empty($tg_cfg['on_block'])) {
            $site    = get_bloginfo('name');
            $count   = count($new_ips);
            $preview = implode(', ', array_slice($new_ips, 0, 5));
            $more    = $count > 5 ? " (+".($count-5)." nữa)" : '';
            $msg     = "🚫 <b>IP mới bị block</b> — {$site}\n"
                     . "Phát hiện <b>{$count}</b> IP từ Matomo:\n"
                     . "<code>{$preview}</code>{$more}";
            adsdefender_telegram_send($msg);
        }
    }

    return true;
}

// ─── Init: IP check + UTM capture ─────────────────────────────────────────────

add_action('init', function () {
    adsdefender_check_ip();
    if (function_exists('adsdefender_capture_utm')) adsdefender_capture_utm();   // module Marketing
}, 1);

// ─── IP Protection ────────────────────────────────────────────────────────────

function adsdefender_check_ip()
{
    $opts = adsdefender_settings();
    if (empty($opts['enabled'])) return;

    // Skip các request hệ thống — không block
    if (defined('WP_CLI')         && WP_CLI)         return;
    if (defined('DOING_CRON')     && DOING_CRON)     return;
    if (defined('REST_REQUEST')   && REST_REQUEST)   return;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;
    if (is_admin()) return;

    // Heartbeat AJAX → skip
    if (wp_doing_ajax() && ($_POST['action'] ?? '') === 'heartbeat') return;

    // Trang block — không được check/redirect, tránh redirect loop
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/adsdefender-blocked') !== false) return;

    $ip = adsdefender_get_real_ip();
    if (!$ip) return;

    // Server's own IP → không block chính mình
    $server_ip = $_SERVER['SERVER_ADDR'] ?? '';
    if ($server_ip && $ip === $server_ip) return;

    // Temp whitelist — admin IP 24h
    if (adsdefender_is_temp_whitelisted($ip)) return;

    // Permanent whitelist
    $whitelist = adsdefender_parse_whitelist($opts['whitelist'] ?? '');
    if (!empty($whitelist) && adsdefender_ip_matches($ip, $whitelist)) return;

    // Kiểm tra visitor ID từ cookie Matomo — block dù IP mới (P2: IP rotation fraud)
    $visitor_id = adsdefender_get_matomo_visitor_id();
    if ($visitor_id && adsdefender_is_visitor_blocked($visitor_id)) {
        adsdefender_log_block($ip);
        adsdefender_block_visitor($opts['block_action'] ?? 'redirect', $opts);
        return;
    }

    $manual = adsdefender_get_manual_ips();
    if (!empty($manual) && adsdefender_ip_matches($ip, array_column($manual, 'ip'))) {
        adsdefender_log_block($ip);
        adsdefender_block_visitor($opts['block_action'] ?? 'redirect', $opts);
        return;
    }

    // Lockout tạm thời (PHP-level, có expire)
    if (adsdefender_check_lockout($ip)) {
        adsdefender_log_block($ip);
        adsdefender_block_visitor($opts['block_action'] ?? 'redirect', $opts);
        return;
    }

    $blocked = get_option(ADSDEFENDER_OPTION_IPS, []);
    if (empty($blocked)) return;

    if (adsdefender_ip_matches($ip, $blocked)) {
        adsdefender_log_block($ip);
        adsdefender_block_visitor($opts['block_action'] ?? 'redirect', $opts);
    }
}

function adsdefender_get_real_ip(): string
{
    $opts = adsdefender_settings();

    if (!empty($opts['behind_cloudflare'])) {
        $remote = trim($_SERVER['REMOTE_ADDR'] ?? '');
        if (adsdefender_is_cloudflare_ip($remote)) {
            $cf = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
            if ($cf && filter_var($cf, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $cf;
            }
        }
        // REMOTE_ADDR không thuộc Cloudflare → không tin CF header, dùng REMOTE_ADDR trực tiếp
        if ($remote && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $remote;
        }
    } elseif (!empty($opts['behind_proxy'])) {
        // Chỉ tin X-Real-IP khi kết nối THỰC SỰ đến từ proxy (REMOTE_ADDR là proxy đáng tin).
        // Trước đây tin header từ bất kỳ ai → kẻ tấn công gọi thẳng vào IP gốc của server với
        // X-Real-IP tuỳ ý: né mọi lệnh chặn / brute force / rate limit (đổi IP mỗi request),
        // mạo danh IP trong whitelist để né firewall, hoặc gán click ảo cho IP người khác để họ bị khoá.
        $remote = trim($_SERVER['REMOTE_ADDR'] ?? '');
        if (adsdefender_is_trusted_proxy($remote)) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP'] ?? '');
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
        }
        if ($remote && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $remote;
    } else {
        $ip = trim($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
    }
    // Fallback không filter private (local/staging)
    $ip = trim($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Proxy đáng tin: loopback / mạng nội bộ (proxy chạy cùng máy hoặc cùng LAN — nginx trước PHP-FPM,
 * load balancer nội bộ) hoặc IP / CIDR admin khai báo ở "Proxy tin cậy" (CDN khác Cloudflare).
 */
function adsdefender_is_trusted_proxy(string $remote): bool
{
    if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_IP)) return false;
    if (!filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return true;
    $list = adsdefender_parse_whitelist(adsdefender_settings()['trusted_proxies'] ?? '');
    return $list && adsdefender_ip_matches($remote, $list);
}

function adsdefender_is_cloudflare_ip(string $ip): bool
{
    // Cloudflare published IP ranges — cập nhật lần cuối 2025-01
    // https://www.cloudflare.com/ips/
    static $cf_ranges = [
        '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '104.16.0.0/13',   '104.24.0.0/14',   '108.162.192.0/18',
        '131.0.72.0/22',   '141.101.64.0/18',  '162.158.0.0/15',
        '172.64.0.0/13',   '173.245.48.0/20',  '188.114.96.0/20',
        '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
        // IPv6
        '2400:cb00::/32',  '2606:4700::/32',   '2803:f800::/32',
        '2405:b500::/32',  '2405:8100::/32',   '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
    return adsdefender_ip_matches($ip, $cf_ranges);
}

function adsdefender_ip_matches(string $ip, array $list): bool
{
    $isIPv6 = strpos($ip, ':') !== false;
    if ($isIPv6) {
        $ipBin = inet_pton($ip);
        if ($ipBin === false) return false;
        foreach ($list as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            if (strpos($entry, '/') !== false) {
                [$subnet, $bits] = explode('/', $entry, 2);
                $bits    = (int) $bits;
                if ($bits < 0 || $bits > 128) continue;
                $netBin  = inet_pton($subnet);
                if ($netBin === false) continue;
                $bytes   = (int) ceil($bits / 8);
                $ipPart  = substr($ipBin,  0, $bytes);
                $netPart = substr($netBin, 0, $bytes);
                if ($bits % 8 !== 0) {
                    $mask    = 0xFF << (8 - ($bits % 8)) & 0xFF;
                    $ipPart  = substr($ipPart,  0, -1) . chr(ord(substr($ipPart,  -1)) & $mask);
                    $netPart = substr($netPart, 0, -1) . chr(ord(substr($netPart, -1)) & $mask);
                }
                if ($ipPart === $netPart) return true;
            } else {
                // So sánh binary để normalize ::1 vs 0:0:0:0:0:0:0:1
                $entryBin = inet_pton(trim($entry));
                if ($entryBin !== false && $entryBin === $ipBin) return true;
            }
        }
        return false;
    }
    $ipLong = ip2long($ip);
    if ($ipLong === false) return false;
    foreach ($list as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        if (strpos($entry, '/') !== false) {
            [$subnet, $bits] = explode('/', $entry, 2);
            $bits    = (int) $bits;
            if ($bits < 0 || $bits > 32) continue;
            $mask    = $bits === 0 ? 0 : (int)((-1 << (32 - $bits)) & 0xFFFFFFFF);
            $netLong = ip2long($subnet);
            if ($netLong !== false && ($ipLong & $mask) === ($netLong & $mask)) return true;
        } elseif ($entry === $ip) {
            return true;
        }
    }
    return false;
}

function adsdefender_log_block(string $ip): void
{
    if (empty(adsdefender_settings()['log_enabled'])) return;
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_LOG_TABLE;
    $url   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
           . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    $wpdb->insert($table, [
        'ip'         => $ip,
        'url'        => substr($url, 0, 500),
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
        'blocked_at' => current_time('mysql'),
    ]);
    if (mt_rand(1, 100) === 1) {
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 10000) {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` ORDER BY id ASC LIMIT %d", $count - 10000));
        }
    }
}

function adsdefender_block_visitor(string $action, array $opts): void
{
    $GLOBALS['adsdefender_block_reason'] = 'fraud';
    switch ($action) {
        case 'redirect':
            // Chỉ chuyển sang website KHÁC. URL trống hoặc trỏ về chính site này
            // (vd. trang chủ) sẽ bị chặn lại ngay → vòng lặp redirect vô hạn,
            // hoặc wp_redirect('') không làm gì → khách thấy trang trắng.
            $url  = trim((string) ($opts['redirect_url'] ?? ''));
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $self = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ($host !== '' && $host !== $self && preg_match('#^https?://#i', $url)) {
                if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
                nocache_headers();
                header('X-Robots-Tag: noindex, nofollow', true);
                wp_redirect($url, 302, 'AdsDefender');
                exit;
            }
            adsdefender_block_response('fraud', 403);
            break;
        case 'blank':
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            nocache_headers();
            status_header(403);
            header('X-Robots-Tag: noindex, nofollow', true);
            echo '<!DOCTYPE html><html><head><meta name="robots" content="noindex,nofollow"></head><body></body></html>';
            exit;
        default:   // '403' — trang chặn (includes/block-page.php)
            adsdefender_block_response('fraud', 403);
    }
}

// ─── Visitor Blacklist ────────────────────────────────────────────────────────

/**
 * Sync danh sách visitor IDs bị block từ Matomo về WP option.
 * Gọi cùng lúc với adsdefender_sync_now() trong cron.
 */
function adsdefender_sync_visitors(): bool
{
    $opts    = adsdefender_settings();
    $api_url = $opts['api_url'] ?? '';
    $secret  = $opts['secret']  ?? '';
    $site_id = (int) ($opts['site_id'] ?? 0);
    if (!$api_url || !$secret || !$site_id) return false;

    $url = trailingslashit($api_url) . 'index.php?' . http_build_query([
        'i_am_super_user' => $secret,
        'module'          => 'AdsDefender',
        'action'          => 'getBlockedVisitors',
        'idSite'          => $site_id,
    ]);
    $response = wp_remote_get($url, ['timeout' => 60, 'sslverify' => adsdefender_sslverify()]);
    if (is_wp_error($response)) {
        update_option('adsdefender_last_error_visitors', $response->get_error_message());
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['success']) || !isset($data['visitors'])) {
        update_option('adsdefender_last_error_visitors', 'Invalid response: ' . substr(wp_remote_retrieve_body($response), 0, 200));
        return false;
    }

    $map = array_fill_keys(array_map('strtolower', $data['visitors']), true);
    update_option(ADSDEFENDER_OPTION_VISITORS, $map, false);
    delete_option('adsdefender_last_error_visitors');
    return true;
}

/**
 * Parse Matomo visitor ID từ cookie _pk_id.N.domain
 * Cookie format: visitorId.firstVisitTime.visitCount.lastVisitTime.lastEcomOrderTime
 * visitorId = 16 ký tự hex (8 bytes).
 */
function adsdefender_get_matomo_visitor_id(): string
{
    $opts    = adsdefender_settings();
    $site_id = (int) ($opts['site_id'] ?? 0);
    if (!$site_id) return '';

    // Matomo set cookie với 2 format tùy server config:
    // Format chuẩn:     _pk_id.12.xxxx  (dùng dấu chấm)
    // Format underscore: _pk_id_12_xxxx  (PHP tự đổi . → _ trong cookie name)
    // PHP tự động convert dấu chấm trong cookie name thành underscore
    // nên "_pk_id.12.7275" khi đọc qua $_COOKIE thành "_pk_id_12_7275"
    $prefixes = [
        '_pk_id.' . $site_id . '.',   // format gốc (nếu server giữ nguyên)
        '_pk_id_' . $site_id . '_',   // PHP auto-convert . → _
    ];

    foreach ($_COOKIE as $name => $value) {
        foreach ($prefixes as $prefix) {
            if (strpos($name, $prefix) === 0) {
                $parts = explode('.', $value);
                $vid   = strtolower(trim($parts[0] ?? ''));
                if (strlen($vid) === 16 && ctype_xdigit($vid)) {
                    return $vid;
                }
            }
        }
    }
    return '';
}

/**
 * Kiểm tra visitor ID có trong blacklist không.
 * Blacklist lưu dưới dạng 16-char hex (8 bytes → HEX = 16 chars).
 * Matomo block_ads_history lưu id_visitor BINARY(8), HEX() = 16 chars.
 */
function adsdefender_is_visitor_blocked(string $visitor_id): bool
{
    if (empty($visitor_id)) return false;
    $map = get_option(ADSDEFENDER_OPTION_VISITORS, []);
    if (empty($map)) return false;
    return isset($map[strtolower($visitor_id)]);
}

// ─── REST: sync trigger ────────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('adsdefender/v1', '/sync', [
        'methods'             => 'POST',
        'callback'            => function () {
            // Tối đa 1 lần / phút — mỗi lần sync là 1 request ra máy chủ Matomo kèm secret
            if (get_transient('adsdefender_sync_rl')) {
                return new WP_Error('adsdefender_rate_limited', 'Too many requests', ['status' => 429]);
            }
            set_transient('adsdefender_sync_rl', 1, MINUTE_IN_SECONDS);
            $ok = adsdefender_sync_now();
            if (!$ok) {
                return new WP_Error('adsdefender_sync_failed',
                    get_option('adsdefender_last_error', 'Sync failed'),
                    ['status' => 502]
                );
            }
            return [
                'synced'   => true,
                'ips'      => count(get_option(ADSDEFENDER_OPTION_IPS, [])),
                'visitors' => count(get_option(ADSDEFENDER_OPTION_VISITORS, [])),
            ];
        },
        'permission_callback' => 'adsdefender_verify_sync_token',
    ]);
});

// ─── Site Groups: cross-site block sharing ────────────────────────────────────

function adsdefender_get_site_groups(): array
{
    return get_option('adsdefender_site_groups', []);
    // Cấu trúc: [['tag' => 'dien-lanh', 'site_id' => 5, 'label' => 'abc.vn'], ...]
}

function adsdefender_save_site_groups(array $groups): void
{
    update_option('adsdefender_site_groups', $groups, false);
}

/**
 * Gọi Matomo để lấy danh sách sites cùng tag của site hiện tại.
 * Returns: ['dien-lanh' => [['id' => 5, 'name' => 'abc.vn'], ...], ...]
 */
function adsdefender_fetch_site_group_preview(): array
{
    $opts    = adsdefender_settings();
    $api_url = rtrim($opts['api_url'] ?? '', '/');
    $secret  = $opts['secret'] ?? '';
    $site_id = (int) ($opts['site_id'] ?? 0);
    if (!$api_url || !$secret || !$site_id) return [];

    $url = $api_url . '/index.php?' . http_build_query([
        'module'          => 'AdsDefender',
        'action'          => 'getSiteGroups',
        'idSite'          => $site_id,
        'i_am_super_user' => $secret,
        'format'          => 'json',
    ]);

    $resp = wp_remote_get($url, ['timeout' => 15, 'sslverify' => adsdefender_sslverify()]);
    if (is_wp_error($resp)) return [];
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    return $data['groups'] ?? [];
}

// ─── Cache Plugin Compatibility ──────────────────────────────────────────────
//
// Chiến lược theo loại server (cấu hình tại Settings → Web Server):
//
//  OLS / Nginx  → cookie "adsdefender_nc=1" + header no-cache
//                 OLS cần rule "Do Not Cache" khi cookie này tồn tại:
//                 OLS Admin → VHost → Cache → Do Not Cache →
//                 Type: Cookie Name | Value: adsdefender_nc
//
//  LiteSpeed Enterprise / Apache → ghi Deny vào .htaccess (đọc ở tầng server)
//
//  auto → đọc SERVER_SOFTWARE để tự chọn
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Tự động cấu hình các cache plugin phổ biến:
 * thêm _pk_id + adsdefender_nc vào danh sách cookie bypass cache.
 * Idempotent — chỉ ghi khi chưa có, không ghi đè toàn bộ config.
 */
function adsdefender_configure_cache_plugins(): array
{
    $cookies = ['_pk_id', 'adsdefender_nc'];
    $results = [];

    // ── LiteSpeed Cache ───────────────────────────────────────────────────────
    if (defined('LSCWP_V') || class_exists('LiteSpeed\Core')) {
        $key     = 'litespeed.conf.cache-exc_cookies';
        $current = get_option($key, '');
        $changed = false;
        foreach ($cookies as $c) {
            if (!preg_match('/(?:^|[\r\n])' . preg_quote($c, '/') . '(?:$|[\r\n])/', $current)) {
                $current = trim($current . "\n" . $c);
                $changed = true;
            }
        }
        if ($changed) {
            update_option($key, $current);
            do_action('litespeed_purge_all');
            $results[] = 'LiteSpeed Cache';
        }
    }

    // ── WP Rocket ────────────────────────────────────────────────────────────
    if (defined('WP_ROCKET_VERSION')) {
        $settings = get_option('wp_rocket_settings', []);
        $current  = (array) ($settings['cache_reject_cookies'] ?? []);
        $changed  = false;
        foreach ($cookies as $c) {
            if (!in_array($c, $current, true)) {
                $current[] = $c;
                $changed   = true;
            }
        }
        if ($changed) {
            $settings['cache_reject_cookies'] = $current;
            update_option('wp_rocket_settings', $settings);
            if (function_exists('rocket_clean_domain')) rocket_clean_domain();
            $results[] = 'WP Rocket';
        }
    }

    // ── W3 Total Cache ────────────────────────────────────────────────────────
    if (defined('W3TC')) {
        $settings = get_option('w3tc_pgcache', []);
        $current  = (array) ($settings['reject.cookie'] ?? []);
        $changed  = false;
        foreach ($cookies as $c) {
            if (!in_array($c, $current, true)) {
                $current[] = $c;
                $changed   = true;
            }
        }
        if ($changed) {
            $settings['reject.cookie'] = $current;
            update_option('w3tc_pgcache', $settings);
            $results[] = 'W3 Total Cache';
        }
    }

    // ── WP Super Cache ────────────────────────────────────────────────────────
    if (defined('WPCACHEHOME')) {
        $config_file = WPCACHEHOME . 'wp-cache-config.php';
        if (is_writable($config_file)) {
            $content = file_get_contents($config_file);
            $changed = false;
            foreach ($cookies as $c) {
                // wpsc_rejected_cookies = array('cookie1', 'cookie2')
                if (strpos($content, "'$c'") === false && strpos($content, "\"$c\"") === false) {
                    $content = preg_replace(
                        '/(\$wpsc_rejected_cookies\s*=\s*array\s*\()/',
                        "$1 '$c',",
                        $content
                    );
                    $changed = true;
                }
            }
            if ($changed) {
                file_put_contents($config_file, $content, LOCK_EX);
                $results[] = 'WP Super Cache';
            }
        }
    }

    // ── Cache Enabler ─────────────────────────────────────────────────────────
    if (defined('CACHE_ENABLER_VERSION')) {
        $settings = get_option('cache_enabler', []);
        $current  = array_filter(array_map('trim', explode("\n", $settings['excluded_cookies'] ?? '')));
        $changed  = false;
        foreach ($cookies as $c) {
            if (!in_array($c, $current, true)) {
                $current[] = $c;
                $changed   = true;
            }
        }
        if ($changed) {
            $settings['excluded_cookies'] = implode("\n", $current);
            update_option('cache_enabler', $settings);
            $results[] = 'Cache Enabler';
        }
    }

    // ── Breeze (Cloudways) ───────────────────────────────────────────────────
    if (defined('BREEZE_VERSION')) {
        $settings = get_option('breeze-advanced-settings', []);
        $current  = array_filter(array_map('trim', explode(',', $settings['breeze-disable-cookies'] ?? '')));
        $changed  = false;
        foreach ($cookies as $c) {
            if (!in_array($c, $current, true)) {
                $current[] = $c;
                $changed   = true;
            }
        }
        if ($changed) {
            $settings['breeze-disable-cookies'] = implode(',', $current);
            update_option('breeze-advanced-settings', $settings);
            $results[] = 'Breeze';
        }
    }

    return $results;
}

// Alias để activation hook gọi
function adsdefender_configure_litespeed_cache(): bool
{
    return count(adsdefender_configure_cache_plugins()) > 0;
}

// Tự động chạy khi plugin load — idempotent, chỉ ghi khi chưa có
add_action('plugins_loaded', function () {
    static $done = false;
    if (!$done) {
        $done = true;
        adsdefender_configure_cache_plugins();
    }
}, 20);

function adsdefender_detect_server(): string
{
    $sw = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');
    if (strpos($sw, 'openlitespeed') !== false) return 'openlitespeed';
    if (strpos($sw, 'litespeed')     !== false) return 'litespeed';
    if (strpos($sw, 'nginx')         !== false) return 'nginx';
    if (strpos($sw, 'apache')        !== false) return 'apache';
    return 'apache'; // fallback an toàn
}

function adsdefender_detect_server_type(): string
{
    $setting = adsdefender_settings()['web_server'] ?? 'auto';
    return $setting === 'auto' ? adsdefender_detect_server() : $setting;
}

function adsdefender_effective_server(): string
{
    $setting = adsdefender_settings()['web_server'] ?? 'auto';
    return $setting === 'auto' ? adsdefender_detect_server() : $setting;
}

// Chạy ở priority 0 — trước adsdefender_check_ip (priority 1)
add_action('init', function () {
    $opts = adsdefender_settings();
    if (empty($opts['enabled'])) return;
    if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) return;

    // Trang block — bỏ qua hoàn toàn, tránh redirect loop
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/adsdefender-blocked') !== false) return;

    $ip = adsdefender_get_real_ip();
    if (!$ip) return;

    $whitelist = adsdefender_parse_whitelist($opts['whitelist'] ?? '');
    if (!empty($whitelist) && adsdefender_ip_matches($ip, $whitelist)) {
        // IP whitelist: dọn cookie blocked nếu sót
        if (!empty($_COOKIE['adsdefender_nc'])) {
            setcookie('adsdefender_nc', '', time() - 3600, '/', '', is_ssl(), true);
        }
        return;
    }

    $blocked     = get_option(ADSDEFENDER_OPTION_IPS, []);
    $manual      = adsdefender_get_manual_ips();
    if (!is_array($manual)) $manual = [];
    $all_blocked = array_merge((array)$blocked, array_column($manual, 'ip'));

    $visitor_id = adsdefender_get_matomo_visitor_id();
    $is_blocked = ($visitor_id && adsdefender_is_visitor_blocked($visitor_id))
               || (!empty($all_blocked) && adsdefender_ip_matches($ip, $all_blocked));

    $server = adsdefender_effective_server();

    if ($is_blocked) {
        if (in_array($server, ['openlitespeed', 'nginx'])) {
            // Cookie approach: OLS/Nginx không đọc .htaccess
            if (empty($_COOKIE['adsdefender_nc'])) {
                setcookie('adsdefender_nc', '1', [
                    'expires'  => time() + 3600,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            header('X-LiteSpeed-Cache-Control: no-cache');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            // LSCWP plugin API nếu có
            if (defined('LSCWP_V') || class_exists('LiteSpeed\Core')) {
                do_action('litespeed_control_set_nocache', 'adsdefender_blocked');
            }
        }
        // LiteSpeed Enterprise / Apache: .htaccess đã block ở tầng server
        // (cập nhật qua adsdefender_update_htaccess() khi sync)
    } else {
        // IP sạch: dọn cookie cũ nếu sót
        if (!empty($_COOKIE['adsdefender_nc'])) {
            setcookie('adsdefender_nc', '', time() - 3600, '/', '', is_ssl(), true);
        }
    }
}, 0);

// IP block qua .htaccess đã bị tắt — block IP chỉ thực hiện qua PHP
function adsdefender_update_htaccess(array $ips): void {} // no-op

function adsdefender_remove_htaccess_block(): void {} // no-op

// AJAX: add tag
add_action('wp_ajax_adsdefender_add_group_tag', function () {
    check_ajax_referer('adsdefender_groups', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $tag = sanitize_text_field($_POST['tag'] ?? '');
    $tag = preg_replace('/[^a-z0-9\-]/', '', strtolower($tag));
    if (empty($tag)) wp_send_json_error('Tag không hợp lệ');

    $opts    = adsdefender_settings();
    $api_url = rtrim($opts['api_url'] ?? '', '/');
    $secret  = $opts['secret'] ?? '';
    $site_id = (int) ($opts['site_id'] ?? 0);

    if (!$api_url || !$secret || !$site_id) {
        wp_send_json_error('Chưa cấu hình Matomo URL/Secret/Site ID');
    }

    $url  = $api_url . '/index.php?' . http_build_query([
        'module'          => 'AdsDefender',
        'action'          => 'addSiteGroup',
        'idSite'          => $site_id,
        'tag'             => $tag,
        'i_am_super_user' => $secret,
        'format'          => 'json',
    ]);
    $resp = wp_remote_post($url, ['timeout' => 15, 'sslverify' => adsdefender_sslverify()]);
    if (is_wp_error($resp)) wp_send_json_error($resp->get_error_message());

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!empty($data['success'])) {
        $preview = adsdefender_fetch_site_group_preview();
        wp_send_json_success(['tag' => $tag, 'groups' => $preview]);
    }
    wp_send_json_error($data['message'] ?? 'Lỗi không xác định');
});

// AJAX: remove tag
add_action('wp_ajax_adsdefender_remove_group_tag', function () {
    check_ajax_referer('adsdefender_groups', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $tag = sanitize_text_field($_POST['tag'] ?? '');
    if (empty($tag)) wp_send_json_error('Tag không hợp lệ');

    $opts    = adsdefender_settings();
    $api_url = rtrim($opts['api_url'] ?? '', '/');
    $secret  = $opts['secret'] ?? '';
    $site_id = (int) ($opts['site_id'] ?? 0);

    $url  = $api_url . '/index.php?' . http_build_query([
        'module'          => 'AdsDefender',
        'action'          => 'removeSiteGroup',
        'idSite'          => $site_id,
        'tag'             => $tag,
        'i_am_super_user' => $secret,
        'format'          => 'json',
    ]);
    $resp = wp_remote_post($url, ['timeout' => 15, 'sslverify' => adsdefender_sslverify()]);
    if (is_wp_error($resp)) wp_send_json_error($resp->get_error_message());

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!empty($data['success'])) {
        $preview = adsdefender_fetch_site_group_preview();
        wp_send_json_success(['tag' => $tag, 'groups' => $preview]);
    }
    wp_send_json_error($data['message'] ?? 'Lỗi không xác định');
});

// AJAX: auto-detect Matomo Site ID từ URL + Secret Key
add_action('wp_ajax_adsdefender_detect_matomo', function () {
    check_ajax_referer('adsdefender_detect_matomo', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $api_url = esc_url_raw(trim($_POST['api_url'] ?? ''));
    $secret  = sanitize_text_field($_POST['secret'] ?? '');

    if (empty($api_url) || empty($secret)) {
        wp_send_json_error('Cần nhập Matomo URL và Secret Key trước.');
    }

    $api_url = rtrim($api_url, '/');

    // Call AdsDefender getSitesList endpoint (uses i_am_super_user pattern, bypasses Matomo login)
    $url = $api_url . '/index.php?' . http_build_query([
        'i_am_super_user' => $secret,
        'module'          => 'AdsDefender',
        'action'          => 'getSitesList',
    ]);

    $resp = wp_remote_get($url, ['timeout' => 15, 'sslverify' => adsdefender_sslverify()]);
    if (is_wp_error($resp)) {
        wp_send_json_error('Không kết nối được Matomo: ' . $resp->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);

    if ($code !== 200 || empty($data['success']) || !isset($data['sites'])) {
        $msg = $data['message'] ?? ('HTTP ' . $code . ' — ' . substr($body, 0, 100));
        wp_send_json_error('Lỗi: ' . $msg);
    }

    // Match WP home_url() against site URLs in Matomo
    $home      = trailingslashit(strtolower(home_url()));
    $home_host = parse_url($home, PHP_URL_HOST);

    $matched   = null;
    $all_sites = [];

    foreach ($data['sites'] as $site) {
        $sid   = (int)($site['id'] ?? 0);
        $sname = $site['name'] ?? '';
        $surl  = trailingslashit(strtolower($site['url'] ?? ''));
        $shost = parse_url($surl, PHP_URL_HOST);

        $all_sites[] = ['id' => $sid, 'name' => $sname, 'url' => $site['url']];

        // Exact match first, then host match
        if ($surl === $home || $shost === $home_host) {
            $matched      = $sid;
            $matched_name = $sname;
            $matched_url  = $site['url'];
        }
    }

    if ($matched) {
        wp_send_json_success([
            'site_id'      => $matched,
            'site_name'    => $matched_name,
            'site_url'     => $matched_url,
            'all_sites'    => $all_sites,
            'message'      => "✅ Tìm thấy: [{$matched}] {$matched_name} ({$matched_url})",
        ]);
    }

    // Not matched — return list so user can pick
    wp_send_json_success([
        'site_id'   => null,
        'all_sites' => $all_sites,
        'message'   => '⚠️ Không tự nhận diện được — chọn thủ công bên dưới.',
    ]);
});

// ─── AJAX: Auto-detect GTM / Meta Pixel from homepage HTML ───────────────────

add_action('wp_ajax_adsdefender_detect_tracking', function () {
    check_ajax_referer('adsdefender_detect_tracking', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $home_url = home_url('/');
    $resp = wp_remote_get($home_url, [
        'timeout'    => 15,
        'user-agent' => 'Mozilla/5.0 (compatible; AdsDefender-Scanner/1.0)',
        'sslverify'  => adsdefender_sslverify(),
    ]);

    if (is_wp_error($resp)) {
        wp_send_json_error('Không fetch được trang: ' . $resp->get_error_message());
    }

    $html = wp_remote_retrieve_body($resp);

    // ── Detect GTM ID ─────────────────────────────────────────────────────────
    $gtm_id = '';
    // Pattern 1: GTM snippet standard  id='+i  hoặc  ?id=GTM-XXXXX
    if (preg_match('/[\'"]GTM-([A-Z0-9]{4,12})[\'"&]/', $html, $m)) {
        $gtm_id = 'GTM-' . $m[1];
    }
    // Pattern 2: googletagmanager.com/gtm.js?id=GTM-XXXXX
    if (!$gtm_id && preg_match('/googletagmanager\.com\/gtm\.js\?id=(GTM-[A-Z0-9]{4,12})/i', $html, $m)) {
        $gtm_id = $m[1];
    }
    // Pattern 3: GTM-XXXXX bare
    if (!$gtm_id && preg_match('/\b(GTM-[A-Z0-9]{4,12})\b/', $html, $m)) {
        $gtm_id = $m[1];
    }

    // ── Detect Meta Pixel ID ──────────────────────────────────────────────────
    $pixel_id = '';
    // Pattern: fbq('init', '123456789')
    if (preg_match("/fbq\s*\(\s*['\"]init['\"]\s*,\s*['\"](\d{10,20})['\"]/", $html, $m)) {
        $pixel_id = $m[1];
    }
    // Pattern: facebook.com/tr?id=123456789
    if (!$pixel_id && preg_match('/facebook\.com\/tr\?id=(\d{10,20})/', $html, $m)) {
        $pixel_id = $m[1];
    }

    // ── TrackSG: use current site_id setting ──────────────────────────────────
    $opts = adsdefender_settings();
    $tracksg_id = (string)($opts['site_id'] ?? '');

    // Also try detect from HTML (Matomo snippet: setSiteId)
    if (!$tracksg_id && preg_match("/setSiteId['\"\s,]+['\"]?(\d+)/", $html, $m)) {
        $tracksg_id = $m[1];
    }

    $found = array_filter([
        'gtm'     => $gtm_id,
        'pixel'   => $pixel_id,
        'tracksg' => $tracksg_id,
    ]);

    $msgs = [];
    if ($gtm_id)     $msgs[] = "GTM: {$gtm_id}";
    if ($pixel_id)   $msgs[] = "Pixel: {$pixel_id}";
    if ($tracksg_id) $msgs[] = "TrackSG: {$tracksg_id}";

    if (empty($found)) {
        wp_send_json_success([
            'gtm_id'     => '',
            'pixel_id'   => '',
            'tracksg_id' => '',
            'message'    => '⚠️ Không tìm thấy GTM/Pixel trên trang chủ.',
        ]);
    }

    wp_send_json_success([
        'gtm_id'     => $gtm_id,
        'pixel_id'   => $pixel_id,
        'tracksg_id' => $tracksg_id,
        'message'    => '✅ Phát hiện: ' . implode(', ', $msgs),
    ]);
});
