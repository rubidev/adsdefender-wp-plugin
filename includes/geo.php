<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * AdsDefender — Vị trí IP (IP2Location.io / ip-api.com)
 *
 * Tra quốc gia / tỉnh / thành phố / nhà mạng + cờ di động / datacenter / proxy cho
 * các IP đang HIỂN THỊ trong trang quản trị (Live Traffic, log chặn…). Không tra
 * lúc khách truy cập → không làm chậm website. Cache mỗi IP 30 ngày trong bảng riêng.
 *
 *  • IP2Location.io (mặc định — vị trí tỉnh / thành ở VN sát hơn): 1 IP / request,
 *    gọi song song. Không key: 1.000 lượt / ngày; key Free: 50.000 lượt / tháng.
 *    Gói Free không có usage_type → cờ "datacenter" suy từ ASN của các nhà cung cấp
 *    cloud lớn; cờ "di động" chỉ có khi key thuộc gói trả phí (usage_type = MOB)
 *  • ip-api.com: batch 100 IP / request, có sẵn cờ mobile / hosting / proxy. Bản
 *    miễn phí qua HTTP, 15 batch / phút, chỉ phi thương mại; key Pro → HTTPS
 *  • Hết lượt / bị giới hạn → nghỉ (transient adsdefender_geo_wait), không cache lỗi
 *  • Vị trí theo IP chỉ là ƯỚC LƯỢNG: IP 4G ở VN thường hiện nơi đặt gateway nhà mạng
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_GEO_TABLE', 'adsdefender_geo');
define('ADSDEFENDER_GEO_TTL_DAYS', 30);

/** Mặc định BẬT (chưa từng lưu cài đặt = bật) */
function adsdefender_geo_enabled(): bool
{
    $o = adsdefender_settings();
    return !array_key_exists('geo_enabled', $o) || !empty($o['geo_enabled']);
}

/** Nguồn tra: ip2location (mặc định) | ipapi */
function adsdefender_geo_provider(): string
{
    return (adsdefender_settings()['geo_provider'] ?? '') === 'ipapi' ? 'ipapi' : 'ip2location';
}

// Đổi nguồn tra → xoá cache để không trộn kết quả 2 nguồn
add_action('update_option_adsdefender_settings', function ($old, $new) {
    $p = fn($o) => (is_array($o) ? ($o['geo_provider'] ?? '') : '') === 'ipapi' ? 'ipapi' : 'ip2location';
    if ($p($old) === $p($new)) return;
    global $wpdb;
    $suppress = $wpdb->suppress_errors(true);
    $wpdb->query("DELETE FROM `{$wpdb->prefix}" . ADSDEFENDER_GEO_TABLE . "`");
    $wpdb->suppress_errors($suppress);
    delete_transient('adsdefender_geo_wait');
}, 10, 2);

/**
 * ASN của nhà cung cấp cloud / datacenter / CDN lớn — khách thật gần như không
 * dùng IP của các mạng này (trừ VPN). Dùng khi nguồn tra không có usage_type.
 */
function adsdefender_geo_hosting_asns(): array
{
    return [
        15169, 396982, 19527, 36040, 139070, 139190,          // Google / Google Cloud
        16509, 14618, 8987, 38895,                             // Amazon AWS
        8075, 8068, 8069, 12076,                               // Microsoft Azure
        45102, 37963, 134963, 24429,                           // Alibaba Cloud
        132203, 45090, 133478,                                 // Tencent Cloud
        136907, 55990,                                         // Huawei Cloud
        31898,                                                 // Oracle Cloud
        14061,                                                 // DigitalOcean
        63949,                                                 // Linode / Akamai
        20473,                                                 // Vultr / Choopa
        16276,                                                 // OVH
        24940, 213230,                                         // Hetzner
        51167,                                                 // Contabo
        13335, 209242,                                         // Cloudflare
        54113,                                                 // Fastly
        32934,                                                 // Meta
        396986, 138699, 137718,                                // ByteDance
        9009,                                                  // M247
        60068, 212238,                                         // Datacamp / CDN77
        62240,                                                 // Clouvider
        202425, 204428,                                        // IP Volume / SS-Net
        49981, 36352, 46606, 40021, 29802,                     // WorldStream, ColoCrossing, Unified Layer, Contabo US, HiVelocity
        131414, 135905, 63737,                                 // Long Vân, VNPT IDC, VietServer (hosting VN)
    ];
}

function adsdefender_geo_create_table(): void
{
    global $wpdb;
    $table   = $wpdb->prefix . ADSDEFENDER_GEO_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
        ip          VARCHAR(45)  NOT NULL,
        data        VARCHAR(500) NOT NULL DEFAULT '',
        fetched_at  DATETIME     NOT NULL,
        PRIMARY KEY  (ip),
        KEY fetched_at (fetched_at)
    ) {$charset};");
}

/** IP công khai hợp lệ (IP nội bộ / dành riêng thì không tra) */
function adsdefender_geo_public_ip(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * Vị trí của danh sách IP: đọc cache, IP chưa có thì tra ip-api (tối đa 100 / lần).
 * Trả về [ip => ['cc','country','region','city','isp','as','mobile','proxy','hosting'] | ['local'=>1] | ['err'=>…]].
 * IP chưa tra được (đang hết lượt) không có trong kết quả → trình duyệt hỏi lại sau.
 */
function adsdefender_geo_get(array $ips): array
{
    global $wpdb;
    $out = [];
    $ips = array_values(array_unique(array_filter(array_map('strval', $ips), fn($ip) => filter_var($ip, FILTER_VALIDATE_IP))));
    $ips = array_slice($ips, 0, 100);
    if (!$ips) return [];

    $ask = [];
    foreach ($ips as $ip) {
        if (adsdefender_geo_public_ip($ip)) $ask[] = $ip; else $out[$ip] = ['local' => 1];
    }
    if (!$ask) return $out;

    $table = $wpdb->prefix . ADSDEFENDER_GEO_TABLE;
    $suppress = $wpdb->suppress_errors(true);
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) adsdefender_geo_create_table();

    $ph   = implode(',', array_fill(0, count($ask), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ip, data FROM `{$table}` WHERE ip IN ({$ph}) AND fetched_at > NOW() - INTERVAL %d DAY",
        array_merge($ask, [ADSDEFENDER_GEO_TTL_DAYS])), ARRAY_A);
    foreach ((array) $rows as $r) {
        $d = json_decode((string) $r['data'], true);
        if (is_array($d)) $out[$r['ip']] = $d;
    }

    $miss = array_values(array_diff($ask, array_keys($out)));
    if ($miss) {
        foreach (adsdefender_geo_fetch($miss) as $ip => $d) {
            $out[$ip] = $d;
            $wpdb->replace($table, [
                'ip'         => $ip,
                'data'       => substr((string) wp_json_encode($d, JSON_UNESCAPED_UNICODE), 0, 500),
                'fetched_at' => current_time('mysql'),
            ]);
        }
        // Dọn cache hết hạn thỉnh thoảng
        if (mt_rand(1, 50) === 1) {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE fetched_at < NOW() - INTERVAL %d DAY", ADSDEFENDER_GEO_TTL_DAYS));
        }
    }
    $wpdb->suppress_errors($suppress);
    return $out;
}

/** Tra IP theo nguồn đang chọn. Hết lượt / lỗi mạng → bỏ IP đó (không cache, lần sau hỏi lại). */
function adsdefender_geo_fetch(array $ips): array
{
    if (get_transient('adsdefender_geo_wait')) return [];
    return adsdefender_geo_provider() === 'ipapi'
        ? adsdefender_geo_fetch_ipapi($ips)
        : adsdefender_geo_fetch_ip2location($ips);
}

/** Chuẩn hoá về một dạng chung + suy cờ datacenter từ ASN nếu nguồn không báo */
function adsdefender_geo_pack(array $d, int $asn): array
{
    if (empty($d['hosting']) && $asn && in_array($asn, adsdefender_geo_hosting_asns(), true)) $d['hosting'] = 1;
    foreach (['country', 'region', 'city'] as $k) $d[$k] = substr((string) ($d[$k] ?? ''), 0, 60);
    $d['cc']  = substr((string) ($d['cc'] ?? ''), 0, 2);
    $d['isp'] = substr((string) ($d['isp'] ?? ''), 0, 80);
    $d['as']  = substr((string) ($d['as'] ?? ''), 0, 80);
    foreach (['mobile', 'proxy', 'hosting'] as $k) $d[$k] = !empty($d[$k]) ? 1 : 0;
    return array_filter($d);
}

/**
 * IP2Location.io — 1 IP / request, gửi song song từng đợt 20.
 * Gói trả phí trả thêm isp / usage_type; gói Free / không key chỉ có as / asn / is_proxy.
 */
function adsdefender_geo_fetch_ip2location(array $ips): array
{
    $key  = trim((string) (adsdefender_settings()['geo_key_i2l'] ?? ''));
    $reqs = [];
    foreach ($ips as $ip) {
        $reqs[$ip] = ['url'  => 'https://api.ip2location.io/?format=json&ip=' . rawurlencode($ip) . ($key !== '' ? '&key=' . rawurlencode($key) : ''),
                      'type' => 'GET'];
    }
    $out = [];
    foreach (array_chunk($reqs, 20, true) as $chunk) {
        foreach (adsdefender_geo_http_multi($chunk) as $ip => [$code, $body]) {
            $r = json_decode((string) $body, true);
            if (!is_array($r)) continue;
            if ($code === 429 || isset($r['error'])) {
                // Hết lượt ngày / tháng hoặc key sai → nghỉ 15 phút, các IP còn lại để lần sau
                set_transient('adsdefender_geo_wait', 1, 15 * MINUTE_IN_SECONDS);
                return $out;
            }
            if ($code !== 200) continue;
            if (empty($r['country_code']) || $r['country_code'] === '-') { $out[$ip] = ['err' => 'unknown']; continue; }
            $ut = strtoupper((string) ($r['usage_type'] ?? ''));          // chỉ gói trả phí: MOB, DCH, CDN, SES, ISP…
            $out[$ip] = adsdefender_geo_pack([
                'cc'      => $r['country_code'],
                'country' => $r['country_name'] ?? '',
                'region'  => $r['region_name'] ?? '',
                'city'    => $r['city_name'] ?? '',
                'isp'     => ($r['isp'] ?? '') ?: ($r['as'] ?? ''),
                'as'      => !empty($r['asn']) ? 'AS' . $r['asn'] . ' ' . ($r['as'] ?? '') : '',
                'mobile'  => str_contains($ut, 'MOB'),
                'hosting' => (bool) preg_match('/\b(DCH|CDN|SES)\b/', $ut),
                'proxy'   => !empty($r['is_proxy']),
            ], (int) ($r['asn'] ?? 0));
        }
    }
    return $out;
}

/** ip-api.com — batch 100 IP / request, có sẵn cờ mobile / hosting / proxy. */
function adsdefender_geo_fetch_ipapi(array $ips): array
{
    $key    = trim((string) (adsdefender_settings()['geo_key'] ?? ''));
    $fields = 'status,message,country,countryCode,regionName,city,isp,org,as,mobile,proxy,hosting,query';
    $url    = $key !== ''
        ? 'https://pro.ip-api.com/batch?fields=' . $fields . '&key=' . rawurlencode($key)
        : 'http://ip-api.com/batch?fields=' . $fields;

    $res = wp_remote_post($url, [
        'timeout' => 8,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(array_values($ips)),
    ]);
    if (is_wp_error($res)) return [];

    $code = (int) wp_remote_retrieve_response_code($res);
    $rl   = wp_remote_retrieve_header($res, 'x-rl');
    $ttl  = (int) wp_remote_retrieve_header($res, 'x-ttl');
    if ($code === 429 || ($rl !== '' && (int) $rl === 0)) {
        set_transient('adsdefender_geo_wait', 1, max(5, min(120, $ttl ?: 60)));
    }
    if ($code !== 200) return [];

    $list = json_decode((string) wp_remote_retrieve_body($res), true);
    if (!is_array($list)) return [];

    $out = [];
    foreach ($list as $r) {
        if (!is_array($r) || empty($r['query']) || !in_array($r['query'], $ips, true)) continue;
        if (($r['status'] ?? '') !== 'success') {
            $out[$r['query']] = ['err' => substr((string) ($r['message'] ?? 'fail'), 0, 40)];
            continue;
        }
        $asn = preg_match('/^AS(\d+)/', (string) ($r['as'] ?? ''), $m) ? (int) $m[1] : 0;
        $out[$r['query']] = adsdefender_geo_pack([
            'cc'      => $r['countryCode'] ?? '',
            'country' => $r['country'] ?? '',
            'region'  => $r['regionName'] ?? '',
            'city'    => $r['city'] ?? '',
            'isp'     => ($r['isp'] ?? '') ?: ($r['org'] ?? ''),
            'as'      => $r['as'] ?? '',
            'mobile'  => !empty($r['mobile']),
            'proxy'   => !empty($r['proxy']),
            'hosting' => !empty($r['hosting']),
        ], $asn);
    }
    return $out;
}

/**
 * Gửi nhiều GET song song (thư viện Requests có sẵn trong WordPress).
 * Trả [khoá => [http code, body]]. Không có Requests → gửi lần lượt bằng wp_remote_get.
 */
function adsdefender_geo_http_multi(array $reqs): array
{
    $out = [];
    $cls = class_exists('\WpOrg\Requests\Requests') ? '\WpOrg\Requests\Requests' : (class_exists('Requests') ? 'Requests' : '');
    if ($cls !== '') {
        try {
            foreach ($cls::request_multiple($reqs, ['timeout' => 8, 'connect_timeout' => 5]) as $k => $r) {
                if (is_object($r) && isset($r->status_code)) $out[$k] = [(int) $r->status_code, (string) $r->body];
            }
            return $out;
        } catch (\Throwable $e) {
            $out = [];
        }
    }
    foreach ($reqs as $k => $q) {
        $r = wp_remote_get($q['url'], ['timeout' => 8]);
        if (!is_wp_error($r)) $out[$k] = [(int) wp_remote_retrieve_response_code($r), (string) wp_remote_retrieve_body($r)];
    }
    return $out;
}

add_action('wp_ajax_adsdefender_geo', function () {
    check_ajax_referer('adsdefender_geo', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
    if (!adsdefender_geo_enabled()) wp_send_json_success(['off' => 1, 'geo' => (object) []]);

    $ips = isset($_POST['ips']) ? (array) wp_unslash($_POST['ips']) : [];
    wp_send_json_success([
        'geo'  => (object) adsdefender_geo_get(array_map('sanitize_text_field', $ips)),
        'wait' => (bool) get_transient('adsdefender_geo_wait'),
    ]);
});
