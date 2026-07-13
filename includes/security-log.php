<?php
/**
 * AdsDefender — Security Event Log
 *
 * Bảng tập trung ghi mọi sự kiện bảo mật:
 *   brute_force | firewall | rate_limit | flood_404 | ip_block | visitor_block
 *
 * Thay thế các log rải rác. Các module cũ vẫn hoạt động song song.
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_SEC_LOG_TABLE', 'adsdefender_security_log');

// ─── Create Table ─────────────────────────────────────────────────────────────

function adsdefender_create_sec_log_table(): void
{
    global $wpdb;
    $table   = $wpdb->prefix . ADSDEFENDER_SEC_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(20)  NOT NULL,
        ip         VARCHAR(45)  NOT NULL DEFAULT '',
        detail     VARCHAR(500) NOT NULL DEFAULT '',
        url        VARCHAR(500) NOT NULL DEFAULT '',
        user_agent VARCHAR(300) NOT NULL DEFAULT '',
        created_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_type_time (event_type, created_at),
        KEY idx_ip_time   (ip, created_at),
        KEY idx_time      (created_at)
    ) {$charset};");
}

// ─── Write Event ──────────────────────────────────────────────────────────────

function adsdefender_sec_log(string $event_type, string $ip = '', string $detail = ''): void
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_SEC_LOG_TABLE;

    // Bỏ qua nếu bảng chưa tồn tại (tránh crash trước migration)
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

    $url = substr(
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        0, 500
    );

    $wpdb->insert($table, [
        'event_type' => substr($event_type, 0, 20),
        'ip'         => substr($ip, 0, 45),
        'detail'     => substr($detail, 0, 500),
        'url'        => $url,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
        'created_at' => current_time('mysql'),
    ]);

    // Cleanup 1%: giữ tối đa 50k dòng
    if (mt_rand(1, 100) === 1) {
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 50000) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` ORDER BY id ASC LIMIT %d",
                $count - 50000
            ));
        }
    }
}

// ─── Stats Helpers (dùng cho Dashboard) ──────────────────────────────────────

function adsdefender_sec_stats(int $days = 7): array
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_SEC_LOG_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return ['total' => 0, 'by_type' => [], 'top_ips' => [], 'hourly' => []];
    }

    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

    // Tổng theo loại
    $by_type = $wpdb->get_results($wpdb->prepare(
        "SELECT event_type, COUNT(*) as cnt FROM `{$table}`
         WHERE created_at >= %s GROUP BY event_type ORDER BY cnt DESC",
        $since
    ), ARRAY_A);

    // Top 10 IPs tấn công nhiều nhất
    $top_ips = $wpdb->get_results($wpdb->prepare(
        "SELECT ip, COUNT(*) as cnt, MAX(created_at) as last_seen,
                GROUP_CONCAT(DISTINCT event_type ORDER BY event_type SEPARATOR ',') as types
         FROM `{$table}` WHERE created_at >= %s AND ip != ''
         GROUP BY ip ORDER BY cnt DESC LIMIT 10",
        $since
    ), ARRAY_A);

    // 24h theo giờ (biểu đồ)
    $since24 = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
    $hourly_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE_FORMAT(created_at, '%%H') as hr, COUNT(*) as cnt
         FROM `{$table}` WHERE created_at >= %s
         GROUP BY hr ORDER BY hr",
        $since24
    ), ARRAY_A);
    $hourly = array_fill(0, 24, 0);
    foreach ($hourly_raw as $row) {
        $hourly[(int)$row['hr']] = (int)$row['cnt'];
    }

    $total = array_sum(array_column($by_type, 'cnt'));

    return compact('total', 'by_type', 'top_ips', 'hourly');
}

function adsdefender_sec_log_get(int $limit = 100, int $offset = 0, string $type = '', string $ip = ''): array
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_SEC_LOG_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return [];

    $where = ['1=1'];
    $args  = [];
    if ($type) { $where[] = 'event_type = %s'; $args[] = $type; }
    if ($ip)   { $where[] = 'ip = %s';         $args[] = $ip; }

    $sql = "SELECT * FROM `{$table}` WHERE " . implode(' AND ', $where)
         . " ORDER BY id DESC LIMIT %d OFFSET %d";
    $args[] = $limit;
    $args[] = $offset;

    return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [];
}

function adsdefender_sec_log_count(string $type = '', string $ip = ''): int
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_SEC_LOG_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return 0;

    $where = ['1=1'];
    $args  = [];
    if ($type) { $where[] = 'event_type = %s'; $args[] = $type; }
    if ($ip)   { $where[] = 'ip = %s';         $args[] = $ip; }

    $sql = "SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' AND ', $where);
    return (int) ($args ? $wpdb->get_var($wpdb->prepare($sql, $args)) : $wpdb->get_var($sql));
}

// ─── IP Reputation (AbuseIPDB) ────────────────────────────────────────────────

function adsdefender_ip_reputation(string $ip): ?array
{
    $opts   = adsdefender_settings();
    $apikey = trim($opts['abuseipdb_key'] ?? '');
    if (!$apikey || !filter_var($ip, FILTER_VALIDATE_IP)) return null;

    $cache_key = 'adsdefender_iprep_' . md5($ip);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $response = wp_remote_get(
        'https://api.abuseipdb.com/api/v2/check?' . http_build_query([
            'ipAddress'    => $ip,
            'maxAgeInDays' => 90,
            'verbose'      => '',
        ]),
        [
            'timeout' => 8,
            'headers' => [
                'Key'    => $apikey,
                'Accept' => 'application/json',
            ],
        ]
    );

    if (is_wp_error($response)) return null;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['data'])) return null;

    $result = [
        'ip'              => $data['data']['ipAddress']          ?? $ip,
        'score'           => (int)($data['data']['abuseConfidenceScore'] ?? 0),
        'country'         => $data['data']['countryCode']        ?? '',
        'isp'             => $data['data']['isp']                ?? '',
        'domain'          => $data['data']['domain']             ?? '',
        'usage_type'      => $data['data']['usageType']          ?? '',
        'total_reports'   => (int)($data['data']['totalReports'] ?? 0),
        'last_reported'   => $data['data']['lastReportedAt']     ?? '',
        'is_tor'          => (bool)($data['data']['isTor']       ?? false),
        'is_public'       => (bool)($data['data']['isPublic']    ?? true),
    ];

    // Cache 6h — tránh hit API quota
    set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
    return $result;
}

// ─── AJAX: tra cứu reputation ────────────────────────────────────────────────

add_action('wp_ajax_adsdefender_ip_reputation', function () {
    check_ajax_referer('adsdefender_ip_rep', '_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);

    $ip   = sanitize_text_field($_POST['ip'] ?? '');
    $data = adsdefender_ip_reputation($ip);

    if (!$data) {
        $opts = adsdefender_settings();
        if (empty($opts['abuseipdb_key'])) {
            wp_send_json_error('Chưa cấu hình AbuseIPDB API Key trong Hệ thống → Cài đặt.');
        }
        wp_send_json_error('Không lấy được thông tin IP.');
    }

    wp_send_json_success($data);
});
