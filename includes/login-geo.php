<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * AdsDefender — Chỉ cho đăng nhập từ Việt Nam
 *
 * Trang đăng nhập (wp-login.php, URL ẩn của Hide Login) và mọi kênh xác thực của tài khoản
 * quản trị (XML-RPC, Application Password, form đăng nhập WooCommerce…) chỉ mở cho:
 *  • IP Việt Nam — danh sách dải IP APNIC cấp cho VN (delegated-apnic-latest), tải hằng tuần,
 *    tra cục bộ bằng tìm kiếm nhị phân → không gọi API ngoài mỗi lần đăng nhập (botnet hàng
 *    nghìn IP sẽ đốt hết hạn mức, API chậm / sập thì hoặc khoá nhầm admin hoặc bỏ lọt)
 *  • Dịch vụ proxy lớn: Google (goog.json TRỪ dải Google Cloud cho thuê — bot hay thuê ở đó)
 *    và Cloudflare (ips-v4 / ips-v6)
 *  • IP whitelist và IP admin tạm (24h) sẵn có của AdsDefender
 *  • Site sau Cloudflare mà chưa bật lấy IP thật: dùng header CF-IPCountry — chỉ tin khi
 *    REMOTE_ADDR thật sự là IP Cloudflare
 *
 * An toàn chống tự khoá:
 *  • Chưa tải được danh sách VN → KHÔNG áp dụng (cho qua), báo trong trang cài đặt
 *  • Bật tuỳ chọn mà IP hiện tại của admin không được phép → từ chối bật
 *  • Khẩn cấp: define('ADSDEFENDER_NO_COUNTRY_LOCK', true); trong wp-config.php
 *  • Người đã đăng nhập không bị ảnh hưởng; khách nước ngoài vẫn đăng nhập tài khoản
 *    mua hàng (WooCommerce) được — chỉ chặn tài khoản có quyền biên tập trở lên
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_LG_OPTION', 'adsdefender_login_geo');
define('ADSDEFENDER_LG_CRON',   'adsdefender_login_geo_sync');

function adsdefender_lg_enabled(): bool
{
    if (defined('ADSDEFENDER_NO_COUNTRY_LOCK') && ADSDEFENDER_NO_COUNTRY_LOCK) return false;
    return !empty(adsdefender_settings()['lg_vn_only']);
}

// ─── Tải + biên dịch danh sách ───────────────────────────────────────────────

function adsdefender_lg_fetch(array $urls, int $timeout = 30): string
{
    foreach ($urls as $url) {
        $res = wp_remote_get($url, ['timeout' => $timeout, 'sslverify' => adsdefender_sslverify(),
                                    'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo('version') . ')']);
        if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) {
            $body = (string) wp_remote_retrieve_body($res);
            if ($body !== '') return $body;
        }
    }
    return '';
}

/** Dải v4 [start, end] TRỪ đi các dải $minus (cả 2 đã sắp xếp + gộp qua adsdefender_ti_pack) */
function adsdefender_lg_subtract(string $bin, string $minus): string
{
    $a = []; $b = [];
    for ($i = 0; $i < strlen($bin); $i += 8)   { $r = unpack('Ns/Ne', $bin, $i);   $a[] = [$r['s'], $r['e']]; }
    for ($i = 0; $i < strlen($minus); $i += 8) { $r = unpack('Ns/Ne', $minus, $i); $b[] = [$r['s'], $r['e']]; }
    $out = [];
    foreach ($a as [$s, $e]) {
        foreach ($b as [$bs, $be]) {
            if ($be < $s || $bs > $e) continue;
            if ($bs > $s) $out[] = [$s, $bs - 1];
            $s = $be + 1;
            if ($s > $e) break;
        }
        if ($s <= $e) $out[] = [$s, $e];
    }
    return adsdefender_ti_pack($out);
}

/** Tải lại toàn bộ. Lỗi / danh sách bất thường → GIỮ bản cũ, ghi lỗi. */
function adsdefender_lg_sync(): array
{
    $old = get_option(ADSDEFENDER_LG_OPTION, []);
    if (!is_array($old)) $old = [];
    $fail = function (string $err) use ($old) {
        $old['error'] = $err; $old['error_time'] = time();
        update_option(ADSDEFENDER_LG_OPTION, $old, false);
        return ['ok' => false, 'error' => $err];
    };

    // 1. Việt Nam — APNIC (kèm mirror RIPE)
    $body = adsdefender_lg_fetch(['https://ftp.apnic.net/stats/apnic/delegated-apnic-latest',
                                  'https://ftp.ripe.net/pub/stats/apnic/delegated-apnic-latest'], 45);
    if ($body === '') return $fail('Không tải được danh sách IP Việt Nam từ APNIC');
    $v4 = []; $v6 = [];
    preg_match_all('/^apnic\|VN\|(ipv4|ipv6)\|([0-9a-fA-F.:]+)\|(\d+)\|/m', $body, $m, PREG_SET_ORDER);
    unset($body);
    foreach ($m as [, $type, $start, $n]) {
        if ($type === 'ipv4') {
            $s = ip2long($start);
            if ($s !== false && (int) $n > 0) $v4[] = [$s, $s + (int) $n - 1];
        } elseif ((int) $n >= 16 && (int) $n <= 64) {
            $v6[] = $start . '/' . (int) $n;
        }
    }
    if (count($v4) < 500) return $fail('Danh sách IP Việt Nam bất thường (' . count($v4) . ' dải) — giữ bản cũ');

    // 2. Proxy lớn: Google (trừ Google Cloud cho thuê) + Cloudflare. Lỗi thì giữ phần cũ.
    $pv4h = (string) ($old['pv4h'] ?? ''); $pv6 = (array) ($old['pv6'] ?? []);
    $goog  = json_decode(adsdefender_lg_fetch(['https://www.gstatic.com/ipranges/goog.json']), true);
    $cloud = json_decode(adsdefender_lg_fetch(['https://www.gstatic.com/ipranges/cloud.json']), true);
    $cf4   = adsdefender_lg_fetch(['https://www.cloudflare.com/ips-v4']);
    $cf6   = adsdefender_lg_fetch(['https://www.cloudflare.com/ips-v6']);
    if (!empty($goog['prefixes']) && !empty($cloud['prefixes']) && $cf4 !== '') {
        $g4 = []; $c4 = []; $p6 = [];
        foreach ($goog['prefixes'] as $p) {
            if (!empty($p['ipv4Prefix']) && ($r = adsdefender_ti_v4_range($p['ipv4Prefix']))) $g4[] = $r;
            if (!empty($p['ipv6Prefix'])) $p6[] = $p['ipv6Prefix'];
        }
        $cloud6 = [];
        foreach ($cloud['prefixes'] as $p) {
            if (!empty($p['ipv4Prefix']) && ($r = adsdefender_ti_v4_range($p['ipv4Prefix']))) $c4[] = $r;
            if (!empty($p['ipv6Prefix'])) $cloud6[] = $p['ipv6Prefix'];
        }
        $p6 = array_values(array_diff($p6, $cloud6));
        $proxy = adsdefender_lg_subtract(adsdefender_ti_pack($g4), adsdefender_ti_pack($c4));
        $cfr = [];
        foreach (preg_split('/\s+/', trim($cf4)) as $c) if ($r = adsdefender_ti_v4_range($c)) $cfr[] = $r;
        foreach (preg_split('/\s+/', trim($cf6)) as $c) if (strpos($c, ':') !== false) $p6[] = $c;
        $all = [];
        for ($i = 0; $i < strlen($proxy); $i += 8) { $r = unpack('Ns/Ne', $proxy, $i); $all[] = [$r['s'], $r['e']]; }
        $pv4h = bin2hex(adsdefender_ti_pack(array_merge($all, $cfr)));
        $pv6  = array_values(array_unique($p6));
    }

    $vn = adsdefender_ti_pack($v4);
    update_option(ADSDEFENDER_LG_OPTION, [
        'v4h'     => bin2hex($vn),            // hex thay base64 — tránh scanner mã độc trên hosting soi nhầm
        'v6'      => $v6,
        'pv4h'    => $pv4h,
        'pv6'     => $pv6,
        'vn_count'=> count($v4) + count($v6),
        'updated' => time(),
        'error'   => '',
    ], false);
    $GLOBALS['adsdefender_lg_cache'] = null;
    return ['ok' => true, 'count' => count($v4) + count($v6)];
}

add_action(ADSDEFENDER_LG_CRON, function () { if (adsdefender_lg_enabled()) adsdefender_lg_sync(); });
add_action('init', function () {
    if (adsdefender_lg_enabled() && !wp_next_scheduled(ADSDEFENDER_LG_CRON)) {
        wp_schedule_event(time() + 600, 'weekly', ADSDEFENDER_LG_CRON);
    }
});

// ─── Tra cứu ──────────────────────────────────────────────────────────────────

function adsdefender_lg_data(): array
{
    if (!empty($GLOBALS['adsdefender_lg_cache'])) return $GLOBALS['adsdefender_lg_cache'];
    $d   = get_option(ADSDEFENDER_LG_OPTION, []);
    $bin = fn($h) => ($h !== '' && strlen($h) % 16 === 0 && ctype_xdigit($h)) ? (string) hex2bin($h) : '';
    return $GLOBALS['adsdefender_lg_cache'] = [
        'vn4' => $bin((string) ($d['v4h'] ?? '')), 'vn6' => (array) ($d['v6'] ?? []),
        'px4' => $bin((string) ($d['pv4h'] ?? '')), 'px6' => (array) ($d['pv6'] ?? []),
    ];
}

function adsdefender_lg_in(string $ip, string $bin4, array $list6): bool
{
    if (strpos($ip, ':') === false) {
        $l = ip2long($ip);
        return $l !== false && $bin4 !== '' && adsdefender_ti_bsearch($bin4, $l);
    }
    return $list6 && adsdefender_ip_matches($ip, $list6);
}

/**
 * IP có được vào trang đăng nhập không. Trả [bool cho phép, lý do ngắn].
 * Danh sách VN chưa có → cho phép (không bao giờ khoá admin vì thiếu dữ liệu).
 */
function adsdefender_lg_check(string $ip): array
{
    $d = adsdefender_lg_data();
    if ($d['vn4'] === '') return [true, 'chưa có danh sách VN'];
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [true, 'IP nội bộ'];

    $wl = adsdefender_parse_whitelist(adsdefender_settings()['whitelist'] ?? '');
    if (($wl && adsdefender_ip_matches($ip, $wl)) || adsdefender_is_temp_whitelisted($ip)) return [true, 'whitelist'];

    // Site sau Cloudflare mà chưa bật lấy IP thật → IP thấy được là IP Cloudflare: dùng quốc gia
    // Cloudflare báo. Chỉ tin header khi kết nối thật sự đến từ Cloudflare (không thì giả được).
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $cc     = strtoupper((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if ($cc !== '' && $remote !== '' && adsdefender_lg_in($remote, $d['px4'], $d['px6']) && $ip === $remote) {
        return [$cc === 'VN', 'cloudflare:' . $cc];
    }

    if (adsdefender_lg_in($ip, $d['vn4'], $d['vn6'])) return [true, 'VN'];
    if (adsdefender_lg_in($ip, $d['px4'], $d['px6'])) return [true, 'proxy Google / Cloudflare'];
    return [false, 'ngoài VN'];
}

function adsdefender_lg_deny(string $ip, string $where): void
{
    if (function_exists('adsdefender_sec_log')) adsdefender_sec_log('login_geo', $ip, $where . ' | IP ngoài Việt Nam');
    // Hide Login đang bật → 404 như trang không tồn tại (không lộ là có trang đăng nhập)
    $hidden = function_exists('adsdefender_hl_enabled') && adsdefender_hl_enabled();
    adsdefender_block_response($hidden ? 'not_found' : 'login_geo', $hidden ? 404 : 403);
}

// 1. Trang đăng nhập (wp-login.php và URL ẩn — cả 2 đều chạy wp-login.php → login_init)
add_action('login_init', function () {
    if (!adsdefender_lg_enabled() || is_user_logged_in()) return;
    $action = (string) ($_REQUEST['action'] ?? 'login');
    if (in_array($action, ['postpass', 'logout'], true)) return;   // form trang có mật khẩu dành cho mọi khách
    $ip = adsdefender_get_real_ip();
    [$ok] = adsdefender_lg_check($ip);
    if (!$ok) adsdefender_lg_deny($ip, 'wp-login.php');
}, 0);

// 2. Mọi kênh xác thực khác (XML-RPC, Application Password, form WooCommerce…): chặn tài khoản
//    quản trị / biên tập đăng nhập từ ngoài VN. Khách mua hàng nước ngoài vẫn đăng nhập được.
add_filter('authenticate', function ($user) {
    if (!adsdefender_lg_enabled() || !($user instanceof WP_User)) return $user;
    if (!user_can($user, 'edit_posts')) return $user;
    $ip = adsdefender_get_real_ip();
    [$ok] = adsdefender_lg_check($ip);
    if ($ok) return $user;
    if (function_exists('adsdefender_sec_log')) adsdefender_sec_log('login_geo', $ip, 'authenticate | user=' . $user->user_login);
    return new WP_Error('adsdefender_login_geo', '<strong>Lỗi:</strong> Tài khoản này chỉ đăng nhập được từ Việt Nam.');
}, 99);

// Bật tuỳ chọn khi chưa có danh sách → tải ngay
add_action('update_option_adsdefender_settings', function ($old, $new) {
    if (!empty($new['lg_vn_only']) && empty($old['lg_vn_only']) && !get_option(ADSDEFENDER_LG_OPTION)) adsdefender_lg_sync();
}, 20, 2);

add_action('wp_ajax_adsdefender_lg_sync', function () {
    check_ajax_referer('adsdefender_lg_sync', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền');
    $r = adsdefender_lg_sync();
    $r['ok'] ? wp_send_json_success($r) : wp_send_json_error($r['error']);
});
