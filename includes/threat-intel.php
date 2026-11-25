<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * AdsDefender — Threat Intel
 *
 * Đồng bộ hằng ngày các danh sách IP độc hại CÔNG KHAI, MIỄN PHÍ, KHÔNG CẦN KEY:
 *
 *   spamhaus  Spamhaus DROP (v4 + v6) — mạng bị chiếm dụng / do tội phạm vận hành.
 *             Gần như không có người dùng thật → an toàn để chặn.
 *   firehol   FireHOL Level 1 — tổng hợp nhiều nguồn (DROP, dshield, feodo…).
 *             Rộng hơn, đã lọc bỏ dải private / reserved có sẵn trong danh sách.
 *   tor       Tor exit nodes — người dùng Tor có thể là người thật cần riêng tư,
 *             nhưng gần như không bao giờ là khách hàng từ quảng cáo.
 *
 * Mỗi nguồn có 3 chế độ: off / label (chỉ gắn nhãn trong Live Traffic, Block Log)
 * / block (chặn bằng trang chặn chung, mã 403).
 *
 * Hiệu năng: danh sách IPv4 được biên dịch thành dải [start, end] đã sắp xếp,
 * gộp chồng lấn, đóng gói nhị phân (8 byte/dải) → tra cứu O(log n) bằng tìm kiếm
 * nhị phân, ~10 lần so sánh cho vài nghìn dải. IPv6 ít (vài chục) nên duyệt tuần tự.
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_TI_CRON', 'adsdefender_ti_sync');

function adsdefender_ti_sources(): array
{
    return [
        'spamhaus' => [
            'label' => 'Spamhaus DROP',
            'desc'  => 'Mạng bị chiếm dụng hoặc do tội phạm vận hành. Gần như không có người dùng thật — an toàn để chặn.',
            'urls'  => ['https://www.spamhaus.org/drop/drop_v4.json', 'https://www.spamhaus.org/drop/drop_v6.json'],
            'min'   => 200,       // ít hơn → coi như tải lỗi, giữ bản cũ
            'link'  => 'https://www.spamhaus.org/blocklists/do-not-route-or-peer/',
        ],
        'firehol' => [
            'label' => 'FireHOL Level 1',
            'desc'  => 'Tổng hợp nhiều nguồn (Spamhaus, DShield, Feodo, botnet C&C…). Phủ rộng hơn, rủi ro chặn nhầm vẫn thấp.',
            'urls'  => ['https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset'],
            'min'   => 500,
            'link'  => 'https://iplists.firehol.org/?ipset=firehol_level1',
        ],
        'tor' => [
            'label' => 'Tor exit nodes',
            'desc'  => 'Máy chủ thoát của mạng ẩn danh Tor. Hay bị dùng để click ảo / dò quét, nhưng cũng có người dùng thật cần riêng tư — nên bắt đầu ở chế độ gắn nhãn.',
            'urls'  => ['https://check.torproject.org/torbulkexitlist'],
            'min'   => 100,
            'link'  => 'https://metrics.torproject.org/',
        ],
    ];
}

function adsdefender_ti_mode(string $source): string
{
    $m = adsdefender_settings()['ti_' . $source] ?? 'off';
    return in_array($m, ['off', 'label', 'block'], true) ? $m : 'off';
}

function adsdefender_ti_option(string $source): string { return 'adsdefender_ti_' . $source; }

// ─── Biên dịch danh sách ──────────────────────────────────────────────────────

/** Rút mọi IP / CIDR từ nội dung (text 1 dòng 1 mục, "cidr ; SBL", JSON lines…) */
function adsdefender_ti_parse(string $body): array
{
    preg_match_all('#(?<![\w.:/])((?:\d{1,3}\.){3}\d{1,3}(?:/\d{1,2})?|[0-9a-fA-F]{0,4}(?::[0-9a-fA-F]{0,4}){2,7}(?:/\d{1,3})?)(?![\w.:])#', $body, $m);
    return $m[1];
}

/**
 * IP / CIDR → [start, end] (int IPv4) hoặc null nếu không dùng được.
 * Loại dải private / loopback / reserved / multicast và dải quá rộng (< /8):
 * FireHOL level1 cố ý chứa 0.0.0.0/8, 10.0.0.0/8, 127.0.0.0/8, 192.168.0.0/16,
 * 224.0.0.0/3… — chặn chúng là chặn luôn proxy, load balancer, mạng nội bộ.
 */
function adsdefender_ti_v4_range(string $entry): ?array
{
    [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, '32');
    $bits = (int) $bits;
    if ($bits < 8 || $bits > 32) return null;
    $long = ip2long($ip);
    if ($long === false) return null;
    $mask  = $bits === 0 ? 0 : ((-1 << (32 - $bits)) & 0xFFFFFFFF);
    $start = $long & $mask;
    $end   = $start | (~$mask & 0xFFFFFFFF);
    // Xét CHỒNG LẤN, không chỉ 2 đầu mút: 96.0.0.0/3 có 2 đầu là IP công khai
    // nhưng bao trọn 100.64.0.0/10 bên trong.
    foreach (adsdefender_ti_special_v4() as [$s, $e]) {
        if ($start <= $e && $end >= $s) return null;
    }
    return [$start, $end];
}

/**
 * Dải IPv4 đặc biệt (RFC 6890) — không bao giờ là địa chỉ của khách thật trên
 * Internet. FILTER_FLAG_NO_RES_RANGE của PHP thiếu vài dải, nổi bật là CGNAT
 * 100.64.0.0/10 mà load balancer / proxy của nhiều hosting dùng làm REMOTE_ADDR.
 */
function adsdefender_ti_special_v4(): array
{
    static $ranges = null;
    if ($ranges !== null) return $ranges;
    $ranges = [];
    foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
              '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
              '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3'] as $cidr) {
        [$ip, $bits] = explode('/', $cidr);
        $mask = (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;
        $s = ip2long($ip) & $mask;
        $ranges[] = [$s, $s | (~$mask & 0xFFFFFFFF)];
    }
    return $ranges;
}

/** Sắp xếp + gộp dải chồng lấn / liền kề, đóng gói nhị phân 'N*' */
function adsdefender_ti_pack(array $ranges): string
{
    usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
    $merged = [];
    foreach ($ranges as [$s, $e]) {
        $n = count($merged);
        if ($n && $s <= $merged[$n - 1][1] + 1) {
            if ($e > $merged[$n - 1][1]) $merged[$n - 1][1] = $e;
        } else {
            $merged[] = [$s, $e];
        }
    }
    $bin = '';
    foreach ($merged as [$s, $e]) $bin .= pack('NN', $s, $e);
    return $bin;
}

function adsdefender_ti_build(string $body): array
{
    $v4 = []; $v6 = [];
    foreach (adsdefender_ti_parse($body) as $entry) {
        if (strpos($entry, ':') !== false) {
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, '128');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                && (int) $bits >= 16 && (int) $bits <= 128) {
                $v6[] = $ip . '/' . (int) $bits;
            }
            continue;
        }
        $r = adsdefender_ti_v4_range($entry);
        if ($r) $v4[] = $r;
    }
    $v6 = array_values(array_unique($v6));
    return ['v4' => adsdefender_ti_pack($v4), 'v6' => $v6, 'count' => count($v4) + count($v6)];
}

// ─── Đồng bộ ──────────────────────────────────────────────────────────────────

/** Tải + biên dịch 1 nguồn. Lỗi hoặc danh sách bất thường → GIỮ bản cũ, ghi lỗi. */
function adsdefender_ti_sync_source(string $source): array
{
    $src = adsdefender_ti_sources()[$source] ?? null;
    if (!$src) return ['ok' => false, 'error' => 'Nguồn không tồn tại'];

    $body = '';
    foreach ($src['urls'] as $url) {
        $res = wp_remote_get($url, ['timeout' => 25, 'sslverify' => adsdefender_sslverify(),
                                    'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo('version') . ')']);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            $err = is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . $code;
            return adsdefender_ti_store_error($source, 'Không tải được ' . $url . ' — ' . $err);
        }
        $body .= "\n" . wp_remote_retrieve_body($res);
    }

    $built = adsdefender_ti_build($body);
    if ($built['count'] < $src['min']) {
        return adsdefender_ti_store_error($source, 'Danh sách bất thường (' . $built['count'] . ' mục) — giữ bản cũ');
    }

    update_option(adsdefender_ti_option($source), [
        // Dải IPv4 đã đóng gói nhị phân (pack 'NN') lưu dạng hex — không dùng base64 để scanner
        // mã độc trên hosting không soi nhầm (base64 + chuỗi dài là dấu hiệu chúng hay bắt)
        'v4h'     => bin2hex($built['v4']),
        'v6'      => $built['v6'],
        'count'   => $built['count'],
        'ranges'  => intdiv(strlen($built['v4']), 8),
        'updated' => time(),
        'error'   => '',
    ], false);
    adsdefender_ti_cache_reset();
    return ['ok' => true, 'count' => $built['count']];
}

function adsdefender_ti_store_error(string $source, string $error): array
{
    $data = get_option(adsdefender_ti_option($source), []);
    if (!is_array($data)) $data = [];
    $data['error']      = $error;
    $data['error_time'] = time();
    update_option(adsdefender_ti_option($source), $data, false);
    return ['ok' => false, 'error' => $error];
}

/** Đồng bộ các nguồn đang bật (cron hằng ngày) */
function adsdefender_ti_sync_all(bool $force = false): array
{
    $out = [];
    foreach (array_keys(adsdefender_ti_sources()) as $s) {
        if (!$force && adsdefender_ti_mode($s) === 'off') continue;
        $out[$s] = adsdefender_ti_sync_source($s);
    }
    return $out;
}

add_action(ADSDEFENDER_TI_CRON, function () { adsdefender_ti_sync_all(); });
add_action('init', function () {
    if (!wp_next_scheduled(ADSDEFENDER_TI_CRON)) {
        wp_schedule_event(time() + 300, 'daily', ADSDEFENDER_TI_CRON);
    }
});

// Bật một nguồn chưa có dữ liệu → tải ngay ở request kế tiếp của admin
add_action('update_option_adsdefender_settings', function ($old, $new) {
    foreach (array_keys(adsdefender_ti_sources()) as $s) {
        $was = $old['ti_' . $s] ?? 'off';
        $now = $new['ti_' . $s] ?? 'off';
        if ($was === 'off' && $now !== 'off' && !get_option(adsdefender_ti_option($s))) {
            adsdefender_ti_sync_source($s);
        }
    }
}, 20, 2);

// ─── Tra cứu ──────────────────────────────────────────────────────────────────

function adsdefender_ti_cache_reset(): void { $GLOBALS['adsdefender_ti_cache'] = null; }

/** Dữ liệu nhị phân dải IPv4 của 1 nguồn (hex → bin). Bản lưu cũ / hỏng → chuỗi rỗng (đợi lần tải sau). */
function adsdefender_ti_v4_bin(array $d): string
{
    $h = (string) ($d['v4h'] ?? '');
    if ($h === '' || strlen($h) % 16 !== 0 || !ctype_xdigit($h)) return '';
    return (string) hex2bin($h);
}

function adsdefender_ti_loaded(): array
{
    if (!empty($GLOBALS['adsdefender_ti_cache'])) return $GLOBALS['adsdefender_ti_cache'];
    $out = [];
    foreach (array_keys(adsdefender_ti_sources()) as $s) {
        $mode = adsdefender_ti_mode($s);
        if ($mode === 'off') continue;
        $d = get_option(adsdefender_ti_option($s));
        if (!is_array($d) || empty($d['count'])) continue;
        $out[$s] = ['mode' => $mode, 'v4' => adsdefender_ti_v4_bin($d), 'v6' => (array) ($d['v6'] ?? [])];
    }
    return $GLOBALS['adsdefender_ti_cache'] = $out;
}

function adsdefender_ti_bsearch(string $bin, int $ip): bool
{
    $lo = 0; $hi = intdiv(strlen($bin), 8) - 1;
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1;
        $r   = unpack('Ns/Ne', $bin, $mid * 8);
        if ($ip < $r['s'])      $hi = $mid - 1;
        elseif ($ip > $r['e'])  $lo = $mid + 1;
        else return true;
    }
    return false;
}

/**
 * Nguồn khớp với IP (chỉ xét nguồn đang bật), ưu tiên nguồn ở chế độ block.
 * @return array{source:string,mode:string}|null
 */
function adsdefender_ti_lookup(string $ip): ?array
{
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return null;
    $hit = null;
    $is4 = strpos($ip, ':') === false;
    $long = $is4 ? ip2long($ip) : false;
    foreach (adsdefender_ti_loaded() as $s => $d) {
        $match = $is4
            ? ($long !== false && $d['v4'] !== '' && adsdefender_ti_bsearch($d['v4'], $long))
            : (!empty($d['v6']) && adsdefender_ip_matches($ip, $d['v6']));
        if (!$match) continue;
        if ($d['mode'] === 'block') return ['source' => $s, 'mode' => 'block'];
        $hit = $hit ?? ['source' => $s, 'mode' => 'label'];
    }
    return $hit;
}

function adsdefender_ti_badge(string $ip): string
{
    $hit = function_exists('adsdefender_ti_lookup') ? adsdefender_ti_lookup($ip) : null;
    if (!$hit) return '';
    $label = adsdefender_ti_sources()[$hit['source']]['label'] ?? $hit['source'];
    $color = $hit['mode'] === 'block' ? '#d63638' : '#996800';
    return '<span title="Threat Intel: ' . esc_attr($label) . '" style="display:inline-block;font-size:10px;font-weight:600;padding:1px 6px;border-radius:8px;margin-left:4px;color:#fff;background:' . $color . '">' . esc_html($label) . '</span>';
}

// ─── Chặn ─────────────────────────────────────────────────────────────────────
//
// Chạy ở init priority 0 — trước kiểm tra click fraud và firewall. Bỏ qua: admin
// đã đăng nhập, cron, CLI, IP whitelist (vĩnh viễn + tạm), bot tìm kiếm đã xác minh.

add_action('init', function () {
    if (defined('WP_CLI') && WP_CLI) return;
    if (defined('DOING_CRON') && DOING_CRON) return;
    if (empty(adsdefender_ti_loaded())) return;
    if (is_user_logged_in() && current_user_can('edit_posts')) return;

    $ip = adsdefender_get_real_ip();
    if (!$ip) return;
    $server_ip = $_SERVER['SERVER_ADDR'] ?? '';
    if ($server_ip && $ip === $server_ip) return;

    $hit = adsdefender_ti_lookup($ip);
    if (!$hit || $hit['mode'] !== 'block') return;

    $opts = adsdefender_settings();
    $wl   = adsdefender_parse_whitelist($opts['whitelist'] ?? '');
    if (!empty($wl) && adsdefender_ip_matches($ip, $wl)) return;
    if (adsdefender_is_temp_whitelisted($ip)) return;
    if (adsdefender_is_verified_bot($ip)) return;

    if (function_exists('adsdefender_sec_log')) {
        adsdefender_sec_log('threat_intel', $ip, (adsdefender_ti_sources()[$hit['source']]['label'] ?? $hit['source']));
    }
    $GLOBALS['adsdefender_ti_blocked'] = $hit['source'];
    adsdefender_block_response('threat', 403);
}, 0);

// ─── AJAX: đồng bộ ngay ───────────────────────────────────────────────────────

add_action('wp_ajax_adsdefender_ti_sync', function () {
    check_ajax_referer('adsdefender_ti_sync', 'nonce');
    if (!current_user_can('manage_options') || !adsdefender_is_manager()) wp_send_json_error('Không có quyền');
    $source = sanitize_key($_POST['source'] ?? '');
    $res = $source ? [$source => adsdefender_ti_sync_source($source)] : adsdefender_ti_sync_all(true);
    wp_send_json_success($res);
});
