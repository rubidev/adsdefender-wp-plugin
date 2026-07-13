<?php
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
    adsdefender_create_blocked_html();
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
    // Migration 2.5.79 — tạo bảng scan_results
    if (version_compare($from, '2.5.79', '<')) {
        adsdefender_scan_create_table();
    }

    // Migration 2.5.78 — tạo bảng file_monitor
    if (version_compare($from, '2.5.78', '<')) {
        adsdefender_fm_create_table();
    }

    // Migration 2.5.59 — tạo bảng admin_log
    if (version_compare($from, '2.5.59', '<')) {
        adsdefender_create_admin_log_table();
    }

    // Migration 2.5.58 — tạo bảng security_log tập trung
    if (version_compare($from, '2.5.58', '<')) {
        adsdefender_create_sec_log_table();
    }

    // Migration 2.5.39 — tạo bảng brute_events cho security modules
    if (version_compare($from, '2.5.39', '<')) {
        adsdefender_create_bf_table();
    }

    // Migration 2.5.37 — tạo bảng lockouts + dọn dẹp dữ liệu cũ + xóa IP khỏi .htaccess
    if (version_compare($from, '2.5.37', '<')) {
        // 1. Tạo / cập nhật tất cả DB tables
        adsdefender_create_lockouts_table();
        adsdefender_create_log_table();
        adsdefender_create_utm_table();
        adsdefender_create_lead_table();

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

    global $wpdb;
    $table    = $wpdb->prefix . ADSDEFENDER_LOCKOUT_TABLE;
    $duration = max(1, (int)($opts['lockout_duration'] ?? 1440)); // phút
    $expires  = gmdate('Y-m-d H:i:s', time() + $duration * 60);

    $wpdb->query($wpdb->prepare(
        "INSERT INTO `{$table}` (ip, source, lockout_count, first_seen, last_seen, expires_at, is_active)
         VALUES (%s, %s, 1, NOW(), NOW(), %s, 1)
         ON DUPLICATE KEY UPDATE
             lockout_count = lockout_count + 1,
             last_seen     = NOW(),
             expires_at    = %s,
             is_active     = 1",
        $ip, $source, $expires, $expires
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
        "SELECT lockout_count, first_seen FROM `{$table}` WHERE ip = %s",
        $ip
    ));
    if (!$row) return;

    $days_since = (time() - strtotime($row->first_seen)) / DAY_IN_SECONDS;
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
    adsdefender_capture_utm();
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
        $headers = ['HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            $ip = trim($_SERVER[$h] ?? '');
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
        }
    } else {
        $ip = trim($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
    }
    // Fallback không filter private (local/staging)
    $ip = trim($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
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
    // Solid Security pattern: đảm bảo cache plugin không cache trang blocked
    if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
    if (!defined('DONOTCACHEDB'))     define('DONOTCACHEDB', true);
    nocache_headers();
    do_action('litespeed_control_set_nocache', 'adsdefender_blocked');

    switch ($action) {
        case 'redirect':
            wp_redirect($opts['redirect_url'] ?? home_url('/'), 302); exit;
        case '403':
            status_header(403);
            echo adsdefender_403_page(); exit;
        case 'blank':
            status_header(200);
            echo '<html><body></body></html>'; exit;
    }
}

function adsdefender_403_page(): string
{
    $ip         = adsdefender_get_real_ip() ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua         = $_SERVER['HTTP_USER_AGENT'] ?? 'Không xác định';
    $time       = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('H:i:s d/m/Y');
    $host       = $_SERVER['HTTP_HOST'] ?? '';
    $uri        = $_SERVER['REQUEST_URI'] ?? '/';
    $referer    = $_SERVER['HTTP_REFERER'] ?? '';
    $lang       = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $ref_law_vn = 'Điều 15, 17 Nghị định 13/2023/NĐ-CP';
    $ref_law_eu = 'GDPR Art. 5 & Computer Fraud and Abuse Act (CFAA)';

    ob_start(); ?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Truy cập bị từ chối — Click fraud detected</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f1117;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.wrap{max-width:680px;width:100%}
.card{background:#1a1d27;border:1px solid #2d3148;border-radius:12px;padding:32px;margin-bottom:16px}
.card.danger{border-color:#e53e3e;background:#1a0d0d}
.badge{display:inline-flex;align-items:center;gap:6px;background:#e53e3e;color:#fff;font-size:12px;font-weight:700;padding:4px 10px;border-radius:20px;letter-spacing:.5px;margin-bottom:16px}
h1{font-size:22px;font-weight:700;color:#fff;margin-bottom:8px}
.sub{font-size:14px;color:#94a3b8;line-height:1.6;margin-bottom:0}
.info-grid{display:grid;grid-template-columns:140px 1fr;gap:10px 16px;font-size:13px;margin-top:0}
.info-grid .label{color:#64748b;font-weight:600;align-self:start;padding-top:2px}
.info-grid .val{color:#e2e8f0;font-family:monospace;word-break:break-all}
.val.highlight{color:#f87171;font-weight:700;font-size:15px}
.section-title{font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#475569;margin-bottom:12px}
.law-box{background:#0f1117;border:1px solid #2d3148;border-radius:8px;padding:14px 16px;font-size:12px;color:#94a3b8;line-height:1.7}
.law-box strong{color:#cbd5e1}
.law-box ul{padding-left:16px;margin-top:6px}
.pulse{display:inline-block;width:8px;height:8px;background:#e53e3e;border-radius:50%;margin-right:6px;animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}
.footer{text-align:center;font-size:11px;color:#334155;margin-top:8px}
hr{border:none;border-top:1px solid #2d3148;margin:20px 0}
</style>
</head>
<body>
<div class="wrap">
  <div class="card danger">
    <div class="badge">🚨 CLICK FRAUD DETECTED</div>
    <h1>Truy cập của bạn đã bị chặn</h1>
    <p class="sub">Hệ thống phát hiện hành vi <strong style="color:#f87171">click gian lận quảng cáo</strong> từ thiết bị của bạn.
    Toàn bộ thông tin phiên truy cập này đã được ghi lại và lưu trữ.</p>
  </div>
  <div class="card">
    <div class="section-title"><span class="pulse"></span>Thông tin đã được ghi lại</div>
    <div class="info-grid">
      <span class="label">Địa chỉ IP</span>
      <span class="val highlight"><?php echo htmlspecialchars($ip); ?></span>
      <span class="label">Thời điểm</span>
      <span class="val"><?php echo htmlspecialchars($time); ?> (GMT+7)</span>
      <span class="label">Trang truy cập</span>
      <span class="val"><?php echo htmlspecialchars($host . $uri); ?></span>
      <?php if ($referer): ?>
      <span class="label">Nguồn truy cập</span>
      <span class="val"><?php echo htmlspecialchars($referer); ?></span>
      <?php endif; ?>
      <span class="label">Trình duyệt</span>
      <span class="val"><?php echo htmlspecialchars(substr($ua, 0, 120)); ?></span>
      <?php if ($lang): ?>
      <span class="label">Ngôn ngữ</span>
      <span class="val"><?php echo htmlspecialchars($lang); ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="section-title">⚖️ Cơ sở pháp lý</div>
    <div class="law-box">
      <strong>Hành vi click fraud quảng cáo có thể bị xử lý theo:</strong>
      <ul>
        <li><strong>Việt Nam:</strong> <?php echo $ref_law_vn; ?> về bảo vệ dữ liệu cá nhân và hành vi gian lận thương mại điện tử. Mức phạt hành chính từ 10 - 70 triệu đồng; hình sự nếu gây thiệt hại lớn.</li>
        <li><strong>Quốc tế:</strong> <?php echo $ref_law_eu; ?> — áp dụng với hành vi truy cập hệ thống máy tính trái phép và gian lận quảng cáo trực tuyến.</li>
        <li><strong>Google Ads Policy:</strong> Vi phạm chính sách lưu lượng không hợp lệ của Google — có thể dẫn đến khóa tài khoản Google Ads của người thực hiện.</li>
      </ul>
    </div>
    <hr>
    <p style="font-size:13px;color:#94a3b8;line-height:1.7">
      Nếu bạn cho rằng đây là nhầm lẫn, hãy liên hệ quản trị viên website cùng địa chỉ IP
      <strong style="color:#e2e8f0"><?php echo htmlspecialchars($ip); ?></strong>
      để được xem xét gỡ chặn.
    </p>
  </div>
  <div class="footer">
    Được bảo vệ bởi AdsDefender &nbsp;·&nbsp; Mã sự kiện: <?php echo strtoupper(substr(md5($ip . $time), 0, 12)); ?>
  </div>
</div>
</body>
</html>
<?php
    return ob_get_clean();
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

// ─── Static blocked page ─────────────────────────────────────────────────────
//
// Tạo file HTML tĩnh /adsdefender-blocked.html trong thư mục gốc WP.
// File tĩnh không qua PHP, không qua block rules của .htaccess → không bị loop.
// ErrorDocument 403 /adsdefender-blocked.html
// Plugin tự tạo file và tự điền lock_url khi cần.

function adsdefender_create_blocked_html(): void
{
    $file = ABSPATH . 'adsdefender-blocked.html';
    $html = adsdefender_403_page_static();
    file_put_contents($file, $html, LOCK_EX);
}

function adsdefender_ensure_lock_url(): void
{
    $opts     = adsdefender_settings();
    $lock_url = trim($opts['lock_url'] ?? '');
    $file_url = '/adsdefender-blocked.html';
    $html_file = ABSPATH . 'adsdefender-blocked.html';

    $need_html   = !file_exists($html_file);
    // Chỉ cần set khi: trống, hoặc còn là URL cũ không phải path tĩnh đúng
    $need_url    = empty($lock_url) || ($lock_url !== $file_url && strpos($lock_url, 'adsdefender-blocked') !== false && strpos($lock_url, '.html') === false);

    // Tạo file tĩnh nếu chưa có
    if ($need_html) {
        adsdefender_create_blocked_html();
    }

    if ($need_url) {
        $opts['lock_url'] = $file_url;
        update_option('adsdefender_settings', $opts);
        adsdefender_settings(true);
    }
}

add_action('wp_loaded', 'adsdefender_ensure_lock_url');

// Tái tạo file tĩnh khi plugin sync (nội dung có thể thay đổi theo version)
add_action(ADSDEFENDER_CRON_HOOK, 'adsdefender_create_blocked_html', 5);

function adsdefender_403_page_static(): string
{
    // Phiên bản static — không có PHP variables (IP, time...)
    // Dùng JS để hiển thị thông tin client-side
    ob_start(); ?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Truy cập tạm thời bị hạn chế</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;background:#f8f9fb;color:#1a1a2e;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;line-height:1.6}
.container{max-width:560px;width:100%;text-align:center}
.icon-wrap{margin-bottom:28px}
.icon-wrap svg{width:56px;height:56px;opacity:.85}
.code{font-size:13px;font-weight:600;letter-spacing:2px;text-transform:uppercase;color:#8b9cb0;margin-bottom:12px}
h1{font-size:28px;font-weight:700;color:#1a1a2e;margin-bottom:12px;letter-spacing:-.3px;line-height:1.25}
.desc{font-size:15px;color:#5a6478;max-width:420px;margin:0 auto 32px}
.divider{height:1px;background:#e4e8ef;margin:28px 0}
.meta{display:flex;flex-direction:column;gap:8px;text-align:left;background:#fff;border:1px solid #e4e8ef;border-radius:10px;padding:18px 20px;margin-bottom:28px}
.meta-row{display:flex;gap:12px;font-size:13px}
.meta-label{color:#8b9cb0;min-width:90px;font-weight:500;flex-shrink:0}
.meta-val{color:#374151;font-family:'SF Mono',SFMono-Regular,Consolas,monospace;word-break:break-all;font-size:12px}
.notice{font-size:13px;color:#8b9cb0;line-height:1.7}
.notice a{color:#5a6478;text-decoration:underline;text-underline-offset:3px}
.footer{margin-top:36px;font-size:11px;color:#c0c8d4;letter-spacing:.5px}
.promo{margin-top:20px;padding-top:20px;border-top:1px solid #e9ecf0;font-size:12px;color:#a0aab8}
.promo a{color:#5a7fc4;text-decoration:none;font-weight:600}
.promo a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="container">
  <div class="icon-wrap">
    <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="28" cy="28" r="27" stroke="#d1d9e6" stroke-width="2"/>
      <path d="M28 16v14" stroke="#8b9cb0" stroke-width="2.5" stroke-linecap="round"/>
      <circle cx="28" cy="37" r="1.5" fill="#8b9cb0"/>
    </svg>
  </div>
  <div class="code">403 — Truy cập bị hạn chế</div>
  <h1>Không thể tải trang này</h1>
  <p class="desc">Truy cập từ địa chỉ IP này tạm thời bị hạn chế.</p>

  <div class="meta">
    <div class="meta-row">
      <span class="meta-label">Thời điểm</span>
      <span class="meta-val" id="ts">—</span>
    </div>
    <div class="meta-row">
      <span class="meta-label">Trang web</span>
      <span class="meta-val" id="host">—</span>
    </div>
    <div class="meta-row">
      <span class="meta-label">Trình duyệt</span>
      <span class="meta-val" id="ua">—</span>
    </div>
  </div>

  <p class="notice">Nếu đây là nhầm lẫn, vui lòng liên hệ quản trị viên website<br>để được hỗ trợ gỡ hạn chế.</p>
  <div class="footer">Bảo vệ bởi AdsDefender</div>
  <div class="promo">
    Bạn đang chạy Google Ads? <a href="https://saigon.pro" target="_blank" rel="noopener">saigon.pro</a> cung cấp dịch vụ chặn click ảo, bad click tự động — bảo vệ ngân sách quảng cáo hiệu quả.
  </div>
</div>
<script>
var n=new Date();
document.getElementById('ts').textContent=n.toLocaleString('vi-VN',{hour:'2-digit',minute:'2-digit',second:'2-digit',day:'2-digit',month:'2-digit',year:'numeric'});
document.getElementById('host').textContent=location.hostname;
document.getElementById('ua').textContent=navigator.userAgent.slice(0,100);
</script>
</body>
</html>
<?php
    return ob_get_clean();
}

// ─── REST: sync trigger ────────────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('adsdefender/v1', '/sync', [
        'methods'             => 'POST',
        'callback'            => function () {
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
        'permission_callback' => 'adsdefender_verify_rest_token',
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
