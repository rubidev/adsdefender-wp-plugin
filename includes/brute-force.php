<?php
/**
 * AdsDefender — Brute Force Protection
 *
 * Theo dõi số lần đăng nhập thất bại theo mô hình Solid Security Pro:
 *  • 5 lần thất bại từ cùng IP trong 5 phút → lockout 30 phút
 *  • 10 lần thất bại cùng username trong 5 phút → lockout 30 phút
 *  • Chặn thử đăng nhập với username "admin" (nếu không tồn tại)
 *  • Dùng bảng adsdefender_brute_events (sliding window, auto-cleanup)
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_BF_TABLE', 'adsdefender_brute_events');

// ─── Create DB Table ──────────────────────────────────────────────────────────

function adsdefender_create_bf_table(): void
{
    global $wpdb;
    $table   = $wpdb->prefix . ADSDEFENDER_BF_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        type       VARCHAR(10)  NOT NULL DEFAULT 'ip',
        value      VARCHAR(100) NOT NULL,
        created_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_type_value (type, value, created_at)
    ) {$charset};");
}

// ─── Settings Helpers ─────────────────────────────────────────────────────────

function adsdefender_bf_enabled(): bool
{
    $opts = adsdefender_settings();
    return !empty($opts['bf_enabled']);
}

function adsdefender_bf_opts(): array
{
    $opts = adsdefender_settings();
    return [
        'check_period'   => max(1,  (int)($opts['bf_check_period']   ?? 5)),   // phút
        'max_ip'         => max(1,  (int)($opts['bf_max_ip']         ?? 5)),   // lần
        'max_user'       => max(1,  (int)($opts['bf_max_user']       ?? 10)),  // lần
        'lockout_mins'   => max(5,  (int)($opts['bf_lockout_mins']   ?? 30)),  // phút
        'block_admin'    => !empty($opts['bf_block_admin']),
    ];
}

// ─── Record failed attempt ────────────────────────────────────────────────────

function adsdefender_bf_record(string $ip, string $username): void
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_BF_TABLE;
    $now   = current_time('mysql');

    if ($ip) {
        $wpdb->insert($table, ['type' => 'ip', 'value' => substr($ip, 0, 45), 'created_at' => $now]);
    }
    if ($username) {
        $wpdb->insert($table, ['type' => 'user', 'value' => substr(strtolower($username), 0, 100), 'created_at' => $now]);
    }

    // Cleanup 1% chance — xóa events cũ > 24h
    if (mt_rand(1, 100) === 1) {
        $wpdb->query("DELETE FROM `{$table}` WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    }
}

// ─── Check if IP/user should be locked out ───────────────────────────────────

function adsdefender_bf_check(string $ip, string $username): bool
{
    if (!adsdefender_bf_enabled()) return false;

    $cfg   = adsdefender_bf_opts();
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_BF_TABLE;
    $since = gmdate('Y-m-d H:i:s', time() - $cfg['check_period'] * 60);

    // Kiểm tra IP
    if ($ip) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE type='ip' AND value=%s AND created_at >= %s",
            $ip, $since
        ));
        if ($count >= $cfg['max_ip']) return true;
    }

    // Kiểm tra username
    if ($username) {
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE type='user' AND value=%s AND created_at >= %s",
            strtolower($username), $since
        ));
        if ($count >= $cfg['max_user']) return true;
    }

    return false;
}

// ─── Hook: authenticate ───────────────────────────────────────────────────────

add_filter('authenticate', function ($user, $username, $password) {
    if (!adsdefender_bf_enabled()) return $user;
    if (empty($username)) return $user;

    $ip  = adsdefender_get_real_ip();
    $cfg = adsdefender_bf_opts();

    // Kiểm tra IP/username đã vượt ngưỡng → lockout ngay, không cần record thêm
    if (adsdefender_bf_check($ip, $username)) {
        $lockout_mins = $cfg['lockout_mins'];
        adsdefender_upsert_lockout($ip, 'brute_force');
        adsdefender_sec_log('brute_force', $ip, "Login fail: user={$username}");
        return new WP_Error('bf_lockout',
            "<strong>Quá nhiều lần thử đăng nhập thất bại.</strong> Vui lòng thử lại sau {$lockout_mins} phút.",
            ['sanitized_user_login' => $username]
        );
    }

    // Block thử đăng nhập với "admin" nếu user đó không tồn tại
    // Record TRƯỚC khi return error để wp_login_failed không cần ghi lại
    if ($cfg['block_admin'] && strtolower($username) === 'admin' && !username_exists('admin')) {
        adsdefender_bf_record($ip, $username);
        return new WP_Error('bf_admin_blocked',
            '<strong>Lỗi:</strong> Username không hợp lệ.',
            ['sanitized_user_login' => $username]
        );
    }

    return $user;
}, 30, 3);

// ─── Hook: ghi lại failed login ───────────────────────────────────────────────

add_action('wp_login_failed', function (string $username) {
    if (!adsdefender_bf_enabled()) return;
    $ip = adsdefender_get_real_ip();
    adsdefender_bf_record($ip, $username);
}, 10, 1);

// ─── Hook: reset count sau login thành công ───────────────────────────────────

add_action('wp_login', function (string $username) {
    if (!adsdefender_bf_enabled()) return;
    $ip = adsdefender_get_real_ip();
    if (!$ip) return;
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_BF_TABLE;
    $wpdb->delete($table, ['type' => 'ip', 'value' => $ip]);
    $wpdb->delete($table, ['type' => 'user', 'value' => strtolower($username)]);
}, 10, 1);
