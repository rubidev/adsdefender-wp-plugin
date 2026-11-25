<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * AdsDefender — Live Traffic
 *
 * Ghi lại MỌI lượt truy cập trang (không chỉ request bị chặn) để admin xem theo
 * thời gian thực: ai đang vào, từ quảng cáo hay tìm kiếm, người thật hay bot,
 * có bị chặn không — và chặn / whitelist ngay trên dòng.
 *
 * Hiệu năng (mặc định TẮT):
 *  • Ghi ở hook shutdown — sau khi trang đã gửi xong cho khách, biết được mã HTTP
 *    cuối cùng, kể cả request bị chặn rồi exit().
 *  • Chỉ 1 câu INSERT / lượt. Bỏ qua: wp-admin, AJAX, cron, CLI, REST, file tĩnh,
 *    tài khoản quản trị (tuỳ chọn).
 *  • Không tra DNS: bot "thật" chỉ được xác nhận khi kết quả reverse DNS đã có sẵn
 *    trong cache (do firewall/brute force tra trước đó); còn lại gắn nhãn theo UA.
 *  • Bảng tự cắt: giữ N giờ gần nhất và tối đa M dòng (dọn 1% số request + cron).
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_TRAFFIC_TABLE', 'adsdefender_traffic');
define('ADSDEFENDER_LT_DB_VER', 6);   // 2: campaign / keyword / track / via — 3: click_hash / click_flag — 4: conv* — 5: fraud

function adsdefender_lt_opts(): array
{
    $o = adsdefender_settings();
    return [
        'enabled'     => !empty($o['lt_enabled']),
        'skip_admins' => !array_key_exists('lt_skip_admins', $o) || !empty($o['lt_skip_admins']),
        'hours'       => max(1, min(720, (int) ($o['lt_hours'] ?? 24))),
        'max_rows'    => max(1000, min(200000, (int) ($o['lt_max_rows'] ?? 20000))),
    ];
}

function adsdefender_lt_create_table(): void
{
    global $wpdb;
    // Khoá chống chạy song song: 2 request admin cùng lúc (nhiều tab, heartbeat, admin-ajax) cùng thấy
    // bảng cũ → cùng ALTER TABLE ADD COLUMN → lỗi "Duplicate column". INSERT IGNORE trên option_name
    // (UNIQUE) là nguyên tử → chỉ 1 request giành được khoá (add_option dùng ON DUPLICATE KEY UPDATE
    // nên không dùng được). Khoá quá 2 phút coi như treo → xoá và giành lại.
    $lock = 'adsdefender_lt_db_lock';
    $take = fn() => (int) $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lock, (string) time()));
    if (!$take()) {
        $at = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock));
        if (time() - $at < 120) return;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, (string) $at));
        if (!$take()) return;
    }
    $table   = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at  DATETIME     NOT NULL,
        ip          VARCHAR(45)  NOT NULL DEFAULT '',
        method      VARCHAR(8)   NOT NULL DEFAULT 'GET',
        url         VARCHAR(500) NOT NULL DEFAULT '',
        status      SMALLINT UNSIGNED NOT NULL DEFAULT 200,
        kind        VARCHAR(12)  NOT NULL DEFAULT 'human',
        source      VARCHAR(16)  NOT NULL DEFAULT 'direct',
        referer     VARCHAR(500) NOT NULL DEFAULT '',
        user_agent  VARCHAR(300) NOT NULL DEFAULT '',
        visitor_id  VARCHAR(16)  NOT NULL DEFAULT '',
        user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        note        VARCHAR(40)  NOT NULL DEFAULT '',
        campaign    VARCHAR(150) NOT NULL DEFAULT '',
        keyword     VARCHAR(150) NOT NULL DEFAULT '',
        track       VARCHAR(500) NOT NULL DEFAULT '',
        via         TINYINT(1)   NOT NULL DEFAULT 0,
        click_hash  CHAR(16)     NOT NULL DEFAULT '',
        click_flag  VARCHAR(16)  NOT NULL DEFAULT '',
        conv        VARCHAR(20)  NOT NULL DEFAULT '',
        conv_label  VARCHAR(150) NOT NULL DEFAULT '',
        conv_value  DECIMAL(15,2) NOT NULL DEFAULT 0,
        fraud       VARCHAR(40)  NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY ip (ip),
        KEY campaign (campaign),
        KEY click_hash (click_hash)
    ) {$charset};");
    update_option('adsdefender_lt_db', ADSDEFENDER_LT_DB_VER, false);
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $lock));
}

// Nâng cấp cấu trúc bảng (thêm cột) khi admin vào trang quản trị
add_action('admin_init', function () {
    if ((int) get_option('adsdefender_lt_db', 0) >= ADSDEFENDER_LT_DB_VER) return;
    if (adsdefender_lt_opts()['enabled']) adsdefender_lt_create_table();
});

// ─── Phân loại ────────────────────────────────────────────────────────────────

/** Host $h là $domain hoặc subdomain của nó (so khớp theo nhãn, không dùng chuỗi con: box.com ≠ x.com) */
function adsdefender_lt_host_is(string $h, array $domains): bool
{
    foreach ($domains as $d) {
        if ($h === $d || str_ends_with($h, '.' . $d)) return true;
    }
    return false;
}

/** Host là trang tìm kiếm Google: google.com, www.google.com.vn, m.google.co.uk, ứng dụng Google trên Android */
function adsdefender_lt_is_google_search(string $h): bool
{
    if (preg_match('/^(www\.|m\.|news\.)?google\.(com?\.)?[a-z]{2,3}$/', $h)) return true;
    return $h === 'com.google.android.googlequicksearchbox';     // referer android-app:// của ứng dụng Google
}

/**
 * Nguồn truy cập:
 *   google_ads, meta_ads, tiktok_ads, ads (quảng cáo khác) — theo click ID / UTM trả phí / referer mạng QC
 *   google_organic (Google Search / Discover / app Google), organic (công cụ tìm kiếm khác)
 *   social, referral, direct, internal
 */
function adsdefender_lt_source(string $url, string $referer): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $q   = array_change_key_case(is_array($q) ? $q : []);
    $has = fn(string $k): bool => isset($q[$k]) && adsdefender_lt_clean_param($q[$k], 200) !== '';   // bỏ trống & tag chưa thay {gclid}
    $rh  = $referer !== '' ? strtolower((string) parse_url($referer, PHP_URL_HOST)) : '';

    // 1. Click ID — chắc chắn nhất
    if ($has('gclid') || $has('gbraid') || $has('wbraid') || $has('dclid') || $has('gad_source') || $has('gad_campaignid')) return 'google_ads';
    if ($has('ttclid')) return 'tiktok_ads';

    // 2. UTM trả phí → phân theo utm_source
    $medium = strtolower($has('utm_medium') ? (string) $q['utm_medium'] : '');
    $usrc   = strtolower($has('utm_source') ? (string) $q['utm_source'] : '');
    $paid   = (bool) preg_match('/^(cpc|ppc|cpm|cpv|paid|paid[_ -]?(search|social|media)|paidsearch|paidsocial|sem|display|banner|ads?|retargeting|remarketing)$/', $medium);
    $is_g   = (bool) preg_match('/^(google|adwords|googleads|google[_ -]?ads|gads|youtube|yt)$/', $usrc);
    $is_m   = (bool) preg_match('/^(facebook|fb|meta|instagram|ig|messenger|audience[_ ]?network)$/', $usrc);
    $is_t   = (bool) preg_match('/^(tiktok|tt)$/', $usrc);
    // Meta: fbclid có cả ở link chia sẻ thường → chỉ tính QC khi có medium trả phí hoặc macro ID của Meta Ads
    $meta_ids = $has('campaign_id') || $has('adset_id') || $has('ad_id') || $has('hsa_acc');
    if ($paid || ($has('fbclid') && $meta_ids)) {
        if ($is_g) return 'google_ads';
        if ($is_m || $has('fbclid')) return 'meta_ads';
        if ($is_t) return 'tiktok_ads';
        return 'ads';
    }
    if ($has('msclkid')) return 'ads';                       // Microsoft (Bing) Ads
    if ($is_g && $medium === 'organic') return 'google_organic';

    // 3. Referer
    if ($rh === '') return 'direct';
    $self = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
    if ($rh === $self || str_ends_with($rh, '.' . $self)) return 'internal';
    // Mạng hiển thị / YouTube Ads chuyển hướng qua máy chủ quảng cáo của Google
    if (adsdefender_lt_host_is($rh, ['googleadservices.com', 'doubleclick.net', 'googlesyndication.com', 'googleads.g.doubleclick.net'])) return 'google_ads';
    if (adsdefender_lt_is_google_search($rh)) return 'google_organic';
    if (preg_match('/(^|\.)(bing|coccoc|duckduckgo|yandex|baidu|ecosia|naver|seznam|qwant)\.[a-z.]+$/', $rh)
        || adsdefender_lt_host_is($rh, ['search.yahoo.com', 'search.brave.com', 'search.aol.com'])) return 'organic';
    if (adsdefender_lt_host_is($rh, ['facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'messenger.com', 'zalo.me', 'zaloapp.com', 'zalo.vn',
                                     'tiktok.com', 'youtube.com', 'youtu.be', 'twitter.com', 'x.com', 't.co', 'linkedin.com', 'lnkd.in',
                                     'pinterest.com', 'threads.net', 'reddit.com', 'telegram.org', 't.me'])
        || in_array($rh, ['com.facebook.katana', 'com.zing.zalo', 'com.google.android.youtube', 'com.instagram.android'], true)) return 'social';
    return 'referral';
}

/** Nhóm nguồn cho bộ lọc / thống kê */
function adsdefender_lt_source_groups(): array
{
    return [
        'ads_all'    => ['google_ads', 'meta_ads', 'tiktok_ads', 'ads'],
        'google_all' => ['google_ads', 'google_organic'],
        'search_all' => ['google_organic', 'organic'],
    ];
}

/**
 * Bóc tách tham số theo dõi quảng cáo từ URL (UTM + ValueTrack Google Ads + Meta/TikTok/Bing)
 * và từ khoá tìm kiếm tự nhiên từ referer (Bing, Cốc Cốc, Yahoo, Yandex… — Google không gửi).
 * Trả về ['campaign' => tên hoặc #id, 'keyword' => …, 'track' => [khoá ngắn => giá trị]].
 */
function adsdefender_lt_clean_param($v, int $max): string
{
    if (!is_scalar($v)) return '';
    $v = trim(wp_strip_all_tags((string) $v));
    // Bỏ tag ValueTrack / macro chưa được thay: {keyword}, {{campaign.name}}, __CAMPAIGN_NAME__
    if ($v === '' || str_contains($v, '{') || preg_match('/^__\w+__$/', $v)) return '';
    return mb_substr($v, 0, $max);
}

function adsdefender_lt_tracking(string $url, string $referer): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $q = array_change_key_case(is_array($q) ? $q : []);
    $pick = function (array $keys, int $max = 150) use ($q): string {
        foreach ($keys as $k) {
            if (isset($q[$k]) && ($v = adsdefender_lt_clean_param($q[$k], $max)) !== '') return $v;
        }
        return '';
    };

    $name = $pick(['utm_campaign', 'campaign', 'campaignname', 'campaign_name', 'utm_campaign_name', 'cmp']);
    $cid  = $pick(['campaignid', 'campaign_id', 'gad_campaignid', 'utm_id', 'hsa_cam'], 40);
    $kw   = $pick(['utm_term', 'keyword', 'kw', 'keywords', 'term', 'matchedkeyword']);
    $kwsrc = '';
    if ($kw === '' && $referer !== '') {
        $rh = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if (preg_match('/(bing|coccoc|yahoo|duckduckgo|yandex|baidu|ecosia|google)\./', $rh)) {
            parse_str((string) parse_url($referer, PHP_URL_QUERY), $rq);
            foreach (['q', 'p', 'text', 'wd', 'query'] as $k) {
                if (isset($rq[$k]) && ($v = adsdefender_lt_clean_param($rq[$k], 150)) !== '') { $kw = $v; $kwsrc = 'search'; break; }
            }
        }
    }
    $clk = '';
    foreach (['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid'] as $k) {
        if (isset($q[$k]) && ($v = adsdefender_lt_clean_param($q[$k], 24)) !== '') { $clk = $k . ':' . $v; break; }
    }

    $t = array_filter([
        'src'  => $pick(['utm_source'], 60),
        'med'  => $pick(['utm_medium'], 40),
        'cnt'  => $pick(['utm_content'], 80),
        'cid'  => $name !== '' ? $cid : '',
        'ag'   => $pick(['adgroup', 'adgroupid', 'adgroup_id', 'ad_group', 'adset', 'adset_name', 'adset_id', 'hsa_grp'], 80),
        'mt'   => strtolower($pick(['matchtype', 'match_type'], 10)),
        'net'  => strtolower($pick(['network', 'hsa_net'], 12)),
        'dev'  => strtolower($pick(['device'], 10)),
        'plc'  => $pick(['placement', 'site_source_name'], 80),
        'cr'   => $pick(['creative', 'creativeid', 'ad_id', 'adid', 'hsa_ad'], 40),
        'tgt'  => $pick(['targetid', 'target_id'], 40),
        'gsrc' => $pick(['gad_source'], 4),
        'clk'  => $clk,
        'kws'  => $kwsrc,
    ], 'strlen');

    return ['campaign' => $name !== '' ? $name : ($cid !== '' ? '#' . $cid : ''), 'keyword' => $kw, 'track' => $t];
}

/** Loại khách: blocked / good_bot / bot / human */
function adsdefender_lt_kind(string $ip, string $ua, int $status): array
{
    if (!empty($GLOBALS['adsdefender_block_reason'])) return ['blocked', (string) $GLOBALS['adsdefender_block_reason']];

    $lua = strtolower($ua);
    if ($ua === '') return ['bot', 'no-ua'];

    // Bot tự khai (Googlebot, Bingbot…): "thật" nếu reverse DNS đã xác nhận trong cache
    foreach (adsdefender_bot_ua_map() as $frag => $hosts) {
        if (str_contains($lua, $frag)) {
            $c = get_transient('adsdefender_vbot_' . md5($ip));
            if ($c === '1') return ['good_bot', $frag];
            return ['bot', $c === '0' ? 'fake:' . $frag : $frag];
        }
    }
    if (preg_match('#(bot|crawl|spider|slurp|scan|curl|wget|python|go-http|java/|okhttp|axios|headless|phantom|httpclient|libwww|scrapy|node-fetch)#i', $ua, $m)) {
        return ['bot', strtolower($m[1])];
    }
    return ['human', ''];
}

// ─── Ghi ──────────────────────────────────────────────────────────────────────

function adsdefender_lt_should_log(): bool
{
    $o = adsdefender_lt_opts();
    if (!$o['enabled']) return false;
    if (PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI)) return false;
    if (defined('DOING_CRON') && DOING_CRON) return false;
    if (wp_doing_ajax()) return false;
    if (defined('REST_REQUEST') && REST_REQUEST && empty($GLOBALS['adsdefender_block_reason'])) return false;
    if (is_admin() && empty($GLOBALS['adsdefender_block_reason'])) return false;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') return false;

    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (preg_match('#\.(css|js|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|mp4|webm|mp3|pdf|zip)$#i', $path)) return false;
    if (str_contains($path, '/wp-content/') || str_contains($path, '/wp-includes/') || $path === '/favicon.ico') return false;
    if (str_contains($path, 'wp-cron.php')) return false;

    if ($o['skip_admins'] && empty($GLOBALS['adsdefender_block_reason'])
        && function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('edit_posts')) return false;
    return true;
}

/**
 * Kiểm tra click ID Google Ads có dấu hiệu giả mạo không. Không thể xác minh tuyệt đối
 * (chỉ Google Ads API biết) nhưng gclid giả thường lộ qua 1 trong 3 dấu hiệu:
 *   format  — sai định dạng (gclid thật: base64url ≥ 40 ký tự; gbraid / wbraid bắt đầu "0AAAAA")
 *   reuse   — gclid đã gặp ở IP khác hoặc cùng IP cách > 30 phút (mỗi click thật có gclid riêng)
 *   referer — có referer nhưng không phải Google / mạng quảng cáo Google (referer rỗng vẫn hợp lệ:
 *             app, Display, trình duyệt chặn referer)
 * Trả về [hash 16 ký tự của click ID (để dò dùng lại), cờ ('' = bình thường)].
 */
function adsdefender_lt_check_click(string $url, string $referer, string $ip, string $table): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $q = array_change_key_case(is_array($q) ? $q : []);
    $type = $id = '';
    foreach (['gclid', 'gbraid', 'wbraid'] as $k) {
        if (isset($q[$k]) && is_scalar($q[$k]) && trim((string) $q[$k]) !== '' && !str_contains((string) $q[$k], '{')) { $type = $k; $id = trim((string) $q[$k]); break; }
    }
    if ($type === '') return ['', ''];
    $hash = substr(hash('sha256', $type . ':' . $id), 0, 16);

    if ($type === 'gclid' ? !preg_match('/^[A-Za-z0-9_-]{40,250}$/', $id) : !preg_match('/^0AAAAA[A-Za-z0-9_-]{14,250}$/', $id)) {
        return [$hash, 'format'];
    }
    global $wpdb;
    $seen = $wpdb->get_row($wpdb->prepare(
        "SELECT ip, created_at FROM `{$table}` WHERE click_hash = %s AND (ip <> %s OR created_at < %s) ORDER BY id ASC LIMIT 1",
        $hash, $ip, date('Y-m-d H:i:s', current_time('timestamp') - 30 * MINUTE_IN_SECONDS)), ARRAY_A);
    if ($seen) return [$hash, 'reuse'];

    if ($referer !== '') {
        $rh   = strtolower((string) parse_url($referer, PHP_URL_HOST));
        $self = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        $ok   = $rh === '' || $rh === $self || str_ends_with($rh, '.' . $self)
             || adsdefender_lt_is_google_search($rh)
             || preg_match('/(^|\.)google\.(com?\.)?[a-z]{2,3}$/', $rh)
             || adsdefender_lt_host_is($rh, ['googleadservices.com', 'doubleclick.net', 'googlesyndication.com', 'youtube.com',
                                             'youtu.be', 'googlevideo.com', 'gstatic.com', 'googleusercontent.com', 'gmail.com'])
             || str_starts_with($rh, 'com.google.android.');
        if (!$ok) return [$hash, 'referer'];
    }
    return [$hash, ''];
}

add_action('shutdown', function () {
    if (!adsdefender_lt_should_log()) return;
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;

    $ip      = adsdefender_get_real_ip();
    $ua      = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    $status  = (int) (http_response_code() ?: 200);
    $scheme  = is_ssl() ? 'https' : 'http';
    $url     = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $referer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
    [$kind, $note] = adsdefender_lt_kind($ip, $ua, $status);
    if ($kind !== 'blocked' && function_exists('adsdefender_ti_lookup') && ($ti = adsdefender_ti_lookup($ip))) {
        $note = 'ti:' . $ti['source'];
    }
    $source = adsdefender_lt_source($url, $referer);
    $trk    = adsdefender_lt_tracking($url, $referer);
    $via    = 0;
    $chash  = $cflag = '';

    $suppress = $wpdb->suppress_errors(true);     // bảng chưa tạo → im lặng, không làm hỏng trang
    // Khách xem tiếp trang khác (referer nội bộ, URL không còn tham số) → kế thừa nguồn gốc
    // (Google Ads / Google tự nhiên / …) + chiến dịch / từ khoá của phiên (hoạt động gần nhất cùng IP
    // trong 30 phút); đánh dấu via=1 (không tính là click / lượt vào mới).
    if ($trk['campaign'] === '' && $trk['keyword'] === '' && $source === 'internal' && $ip !== '') {
        if ($a = adsdefender_lt_attribution($ip, 30)) {
            $trk = ['campaign' => $a['campaign'], 'keyword' => $a['keyword'], 'track' => $a['track']];
            $via = 1;
        }
    }
    if ($source === 'google_ads') [$chash, $cflag] = adsdefender_lt_check_click($url, $referer, $ip, $table);
    $track_json = $trk['track'] ? (string) wp_json_encode($trk['track'], JSON_UNESCAPED_UNICODE) : '';
    if (strlen($track_json) > 500) $track_json = '';
    $wpdb->insert($table, [
        'created_at' => current_time('mysql'),
        'ip'         => $ip,
        'method'     => substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 8),
        'url'        => substr($url, 0, 500),
        'status'     => $status,
        'kind'       => $kind,
        'source'     => $source,
        'referer'    => $referer,
        'user_agent' => $ua,
        'visitor_id' => (string) (adsdefender_get_matomo_visitor_id() ?: ''),
        'user_id'    => get_current_user_id(),
        'note'       => substr($note, 0, 40),
        'campaign'   => $trk['campaign'],
        'keyword'    => $trk['keyword'],
        'track'      => $track_json,
        'via'        => $via,
        'click_hash' => $chash,
        'click_flag' => $cflag,
    ]);
    $row_id = (int) $wpdb->insert_id;
    // Click quảng cáo (lượt vào, không phải trang xem tiếp) → chạy bộ phát hiện click ảo ngay
    if ($row_id && $via === 0 && in_array($source, ['google_ads', 'meta_ads', 'tiktok_ads', 'ads'], true)) {
        adsdefender_cf_detect($row_id, $ip, $kind, $cflag, $note, $trk);
    }
    $wpdb->suppress_errors($suppress);

    if (mt_rand(1, 100) === 1) adsdefender_lt_prune();
}, 1);

// ─── Phát hiện click ảo tại chỗ ───────────────────────────────────────────────

function adsdefender_cf_opts(): array
{
    $o  = adsdefender_settings();
    $on = fn(string $k): bool => !array_key_exists($k, $o) || !empty($o[$k]);    // mặc định BẬT
    return [
        'enabled'  => $on('cf_enabled'),
        'mode'     => ($o['cf_mode'] ?? 'lock') === 'flag' ? 'flag' : 'lock',
        'clicks'   => max(2, min(20, (int) ($o['cf_clicks'] ?? 3))),
        'hours'    => max(1, min(168, (int) ($o['cf_hours'] ?? 24))),
        'bot'      => $on('cf_bot'),
        'ti'       => $on('cf_ti'),
        'gclid'    => !empty($o['cf_gclid']),
        'subnet'   => !empty($o['cf_subnet']),
        'subnet_n' => max(3, min(50, (int) ($o['cf_subnet_n'] ?? 5))),
    ];
}

function adsdefender_cf_reasons(): array
{
    return [
        'repeat' => 'Click quảng cáo lặp lại, không chuyển đổi',
        'bot'    => 'Bot / công cụ tự động click quảng cáo',
        'threat' => 'IP trong danh sách đen (Threat Intel) click quảng cáo',
        'gclid'  => 'Dùng lại gclid của lượt click khác',
        'subnet' => 'Nhiều IP cùng dải /24 click quảng cáo dồn dập',
    ];
}

/**
 * Chạy ở mỗi click quảng cáo (dòng via = 0, nguồn quảng cáo). Quy tắc (theo thứ tự):
 *   bot     — UA bot / không có UA (bot thật đã xác minh như AdsBot được bỏ qua)
 *   threat  — IP nằm trong danh sách Threat Intel (Spamhaus / FireHOL / Tor) đang bật
 *   gclid   — (tuỳ chọn) gclid đã dùng ở IP khác
 *   repeat  — ≥ N click quảng cáo trong X giờ từ cùng IP và IP đó CHƯA có chuyển đổi nào
 *             (khách thật bấm quảng cáo nhiều lần rồi gọi điện → không khoá)
 *   subnet  — (tuỳ chọn) ≥ M IP khác nhau cùng /24 click quảng cáo trong 1 giờ → khoá cả nhóm
 * Hành động: ghi lý do vào cột fraud; chế độ "lock" → lockout (dùng thời gian khoá + leo thang
 * ban vĩnh viễn sẵn có) + báo Telegram. Bỏ qua IP whitelist, IP admin tạm, bot thật.
 */
function adsdefender_cf_detect(int $row_id, string $ip, string $kind, string $cflag, string $note, array $trk = []): string
{
    $c = adsdefender_cf_opts();
    if (!$c['enabled'] || $ip === '' || $kind === 'good_bot') return '';
    $opts = adsdefender_settings();
    $wl   = adsdefender_parse_whitelist($opts['whitelist'] ?? '');
    if (($wl && adsdefender_ip_matches($ip, $wl)) || adsdefender_is_temp_whitelisted($ip)) return '';

    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;
    $now   = current_time('timestamp');
    $since = date('Y-m-d H:i:s', $now - $c['hours'] * HOUR_IN_SECONDS);
    $ads   = "('google_ads','meta_ads','tiktok_ads','ads')";
    $reason = ''; $detail = ''; $targets = [$ip];

    if ($c['bot'] && $kind === 'bot') {
        $reason = 'bot'; $detail = $note;
    } elseif ($c['ti'] && str_starts_with($note, 'ti:')) {
        $reason = 'threat'; $detail = substr($note, 3);
    } elseif ($c['gclid'] && $cflag === 'reuse') {
        $reason = 'gclid';
    } else {
        $n = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE ip = %s AND via = 0 AND source IN {$ads} AND created_at >= %s", $ip, $since));
        if ($n >= $c['clicks']) {
            // Chuyển đổi ghi phía máy chủ (form / lead / đơn hàng) → khách thật, miễn hẳn.
            // Chuyển đổi do trình duyệt tự báo (CONV-JS: bấm gọi / Zalo) thì ai cũng gửi giả được
            // → chỉ nới ngưỡng thêm 2 click, không miễn vĩnh viễn (bot gửi 1 beacon là né được).
            $cv = $wpdb->get_row($wpdb->prepare(
                "SELECT SUM(method <> 'CONV-JS') AS srv, SUM(method = 'CONV-JS') AS js FROM `{$table}` WHERE ip = %s AND via = 2 AND created_at >= %s",
                $ip, $since), ARRAY_A);
            $limit = $c['clicks'] + ((int) ($cv['js'] ?? 0) > 0 ? 2 : 0);
            if ((int) ($cv['srv'] ?? 0) === 0 && $n >= $limit) { $reason = 'repeat'; $detail = $n . '/' . $c['hours'] . 'h'; }
        }
        if ($reason === '' && $c['subnet'] && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $prefix = substr($ip, 0, strrpos($ip, '.') + 1);
            $ips = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT ip FROM `{$table}` WHERE ip LIKE %s AND via = 0 AND source IN {$ads} AND created_at >= %s
                 AND ip NOT IN (SELECT ip FROM `{$table}` WHERE ip LIKE %s AND via = 2 AND method <> 'CONV-JS' AND created_at >= %s)",
                $wpdb->esc_like($prefix) . '%', date('Y-m-d H:i:s', $now - HOUR_IN_SECONDS),
                $wpdb->esc_like($prefix) . '%', date('Y-m-d H:i:s', $now - HOUR_IN_SECONDS)));
            if (count($ips) >= $c['subnet_n']) {
                $reason = 'subnet'; $detail = $prefix . '0/24 x' . count($ips);
                $targets = array_values(array_filter($ips, fn($x) => !($wl && adsdefender_ip_matches($x, $wl))));
            }
        }
    }
    if ($reason === '') return '';

    $wpdb->update($table, ['fraud' => substr($reason . ($detail !== '' ? ':' . $detail : ''), 0, 40)], ['id' => $row_id]);

    // Đã bị khoá sẵn (click lại khi đang bị chặn) → vẫn tăng lockout để leo thang ban vĩnh viễn, không báo lại
    if ($c['mode'] === 'lock') {
        foreach ($targets as $t) adsdefender_upsert_lockout($t, 'clickfraud');
        if ($kind !== 'blocked') {
            $tg = function_exists('adsdefender_telegram_cfg') ? adsdefender_telegram_cfg() : [];
            if (!empty($tg['enabled']) && !empty($tg['on_block'])) {
                $kw = trim(($trk['keyword'] ?? '') . ' · ' . ($trk['campaign'] ?? ''), ' ·');
                adsdefender_telegram_send("🛑 <b>Click ảo</b> — " . get_bloginfo('name') . "
"
                    . esc_html(adsdefender_cf_reasons()[$reason]) . ($detail !== '' ? ' (' . esc_html($detail) . ')' : '') . "
"
                    . "IP: <code>" . esc_html(implode(', ', array_slice($targets, 0, 5))) . "</code>"
                    . ($kw !== '' ? "
🔑 " . esc_html($kw) : '') . "
→ Đã khoá. Nhớ thêm IP vào loại trừ IP của Google Ads.");
            }
        }
    }
    return $reason;
}

/**
 * Danh sách IP nên loại trừ trong Google Ads (Cài đặt chiến dịch → Loại trừ IP, tối đa 500 / chiến dịch):
 * IP bị khoá / ban (do Matomo, click ảo tại chỗ, thủ công) gần đây nhất trước. Chỉ IP đơn hợp lệ.
 */
function adsdefender_cf_export_ips(int $days = 30, int $limit = 500): array
{
    global $wpdb;
    $out = [];
    $add = function ($ip) use (&$out) {
        $ip = trim((string) $ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !isset($out[$ip])) $out[$ip] = true;
    };
    $suppress = $wpdb->suppress_errors(true);
    // 1. Click ảo phát hiện tại chỗ (mới nhất trước)
    foreach ((array) $wpdb->get_col("SELECT ip FROM `{$wpdb->prefix}" . ADSDEFENDER_TRAFFIC_TABLE . "` WHERE fraud <> '' GROUP BY ip ORDER BY MAX(id) DESC") as $ip) $add($ip);
    // 2. Lockout (Matomo + click ảo) trong N ngày
    foreach ((array) $wpdb->get_col($wpdb->prepare(
        "SELECT ip FROM `{$wpdb->prefix}" . ADSDEFENDER_LOCKOUT_TABLE . "` WHERE last_seen >= NOW() - INTERVAL %d DAY ORDER BY last_seen DESC", $days)) as $ip) $add($ip);
    $wpdb->suppress_errors($suppress);
    // 3. Ban vĩnh viễn / thủ công (mới thêm trước)
    foreach (array_reverse(adsdefender_get_manual_ips()) as $m) $add($m['ip'] ?? '');
    $wl = adsdefender_parse_whitelist(adsdefender_settings()['whitelist'] ?? '');
    $ips = array_keys($out);
    if ($wl) $ips = array_values(array_filter($ips, fn($ip) => !adsdefender_ip_matches($ip, $wl)));
    return ['ips' => array_slice($ips, 0, $limit), 'total' => count($ips)];
}

add_action('admin_post_adsdefender_cf_export', function () {
    if (!current_user_can('manage_options')) wp_die('Không có quyền');
    check_admin_referer('adsdefender_cf_export');
    $r = adsdefender_cf_export_ips();
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="google-ads-ip-exclusions-' . gmdate('Ymd') . '.txt"');
    echo implode("\r\n", $r['ips']);
    exit;
});

/**
 * Nguồn của phiên hiện tại của IP: dòng hoạt động gần nhất (trang xem, via < 2) trong $minutes phút.
 *   via = 1 → đã mang nguồn kế thừa (track.from) → dùng lại (phiên dài vẫn giữ nguồn)
 *   via = 0 → là lượt vào; bỏ qua nếu nội bộ / trực tiếp không có chiến dịch
 * Trả về ['source', 'campaign', 'keyword', 'track' (có 'from')] hoặc null.
 */
function adsdefender_lt_attribution(string $ip, int $minutes): ?array
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;
    $prev = $wpdb->get_row($wpdb->prepare(
        "SELECT source, campaign, keyword, track, via FROM `{$table}` WHERE ip = %s AND created_at >= %s AND via < 2 ORDER BY id DESC LIMIT 1",
        $ip, date('Y-m-d H:i:s', current_time('timestamp') - $minutes * MINUTE_IN_SECONDS)), ARRAY_A);
    if (!$prev) return null;
    $t = $prev['track'] !== '' ? json_decode($prev['track'], true) : [];
    if (!is_array($t)) $t = [];
    if ((int) $prev['via'] === 1) {
        if (empty($t['from'])) return null;
        return ['source' => $t['from'], 'campaign' => $prev['campaign'], 'keyword' => $prev['keyword'], 'track' => $t];
    }
    if ($prev['source'] === 'internal' || ($prev['source'] === 'direct' && $prev['campaign'] === '' && $prev['keyword'] === '')) return null;
    $t['from'] = $prev['source'];
    return ['source' => $prev['source'], 'campaign' => $prev['campaign'], 'keyword' => $prev['keyword'], 'track' => $t];
}

// ─── Chuyển đổi ───────────────────────────────────────────────────────────────

function adsdefender_lt_conv_types(): array
{
    return [
        'phone' => '📞 Gọi điện', 'zalo' => '💬 Zalo', 'messenger' => '💬 Messenger', 'whatsapp' => '💬 WhatsApp',
        'telegram' => '💬 Telegram', 'email' => '✉️ Email', 'form' => '📝 Gửi form', 'lead' => '🎯 Lead popup',
        'purchase' => '🛒 Đơn hàng', 'custom' => '⭐ Tuỳ chỉnh',
    ];
}

/**
 * Ghi 1 chuyển đổi cho khách hiện tại (gọi trong request của chính khách: AJAX, REST, hook form…).
 * Dòng chuyển đổi (via = 2, method CONV = phía máy chủ / CONV-JS = beacon trình duyệt) mang nguồn / chiến dịch / từ khoá của phiên (hoạt động gần nhất trong 24h)
 * → biết cuộc gọi / form / đơn hàng đến từ Google Ads từ khoá nào.
 * Chống trùng: cùng IP + loại + nhãn trong 60 giây; tối đa 30 chuyển đổi / IP / giờ.
 * Hàm public — theme / plugin khác có thể gọi: adsdefender_lt_conversion('custom', 'Đặt lịch', 500000);
 */
function adsdefender_lt_conversion(string $type, string $label = '', float $value = 0, string $page = '', bool $need_visit = false): bool
{
    $o = adsdefender_lt_opts();
    if (!$o['enabled']) return false;
    if ($o['skip_admins'] && is_user_logged_in() && current_user_can('edit_posts')) return false;
    $ip = adsdefender_get_real_ip();
    if (!$ip) return false;
    $type  = array_key_exists($type, adsdefender_lt_conv_types()) ? $type : 'custom';
    $label = mb_substr(trim(wp_strip_all_tags($label)), 0, 150);

    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;
    $now   = current_time('timestamp');
    $suppress = $wpdb->suppress_errors(true);
    $done = function ($r) use ($wpdb, $suppress) { $wpdb->suppress_errors($suppress); return $r; };

    $dup = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE ip = %s AND via = 2 AND created_at >= %s AND conv = %s AND conv_label = %s",
        $ip, date('Y-m-d H:i:s', $now - 60), $type, $label));
    if ($dup > 0) return $done(false);
    $hour = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE ip = %s AND via = 2 AND created_at >= %s", $ip, date('Y-m-d H:i:s', $now - HOUR_IN_SECONDS)));
    if ($hour >= 30) return $done(false);
    // Endpoint công khai (beacon từ trình duyệt): chỉ nhận khi IP thực sự đã xem trang trong 2 giờ qua
    if ($need_visit && !(int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE ip = %s AND via < 2 AND created_at >= %s", $ip, date('Y-m-d H:i:s', $now - 2 * HOUR_IN_SECONDS)))) {
        return $done(false);
    }

    $a    = adsdefender_lt_attribution($ip, 24 * 60);
    $home = (string) parse_url(home_url('/'), PHP_URL_HOST);
    if ($page !== '' && strcasecmp((string) parse_url($page, PHP_URL_HOST), $home) !== 0) $page = '';
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    [$kind, $note] = adsdefender_lt_kind($ip, $ua, 200);
    $track = $a['track'] ?? [];
    $ok = $wpdb->insert($table, [
        'created_at' => current_time('mysql'),
        'ip'         => $ip,
        'method'     => $need_visit ? 'CONV-JS' : 'CONV',   // CONV-JS: trình duyệt tự báo (beacon) — không kiểm chứng được
        'url'        => substr(esc_url_raw($page), 0, 500),
        'status'     => 0,
        'kind'       => $kind,
        'source'     => $a['source'] ?? 'direct',
        'referer'    => '',
        'user_agent' => $ua,
        'visitor_id' => (string) (adsdefender_get_matomo_visitor_id() ?: ''),
        'user_id'    => get_current_user_id(),
        'note'       => substr($note, 0, 40),
        'campaign'   => $a['campaign'] ?? '',
        'keyword'    => $a['keyword'] ?? '',
        'track'      => $track ? (string) wp_json_encode($track, JSON_UNESCAPED_UNICODE) : '',
        'via'        => 2,
        'conv'       => $type,
        'conv_label' => $label,
        'conv_value' => max(0, min(9999999999999, round($value, 2))),
    ]);
    return $done((bool) $ok);
}

// Beacon từ trình duyệt (click tel: / Zalo / Messenger / WhatsApp / Telegram / mailto, hoặc adsLtConv())
add_action('wp_ajax_nopriv_adsdefender_lt_conv', 'adsdefender_lt_conv_ajax');
add_action('wp_ajax_adsdefender_lt_conv', 'adsdefender_lt_conv_ajax');
function adsdefender_lt_conv_ajax(): void
{
    $type  = sanitize_key($_POST['type'] ?? '');
    $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
    $page  = esc_url_raw(wp_unslash($_POST['page'] ?? ''));
    $value = is_numeric($_POST['value'] ?? '') ? (float) $_POST['value'] : 0;
    if ($type === 'purchase' || $type === 'lead' || $type === 'form') $type = 'custom';   // các loại này chỉ ghi phía máy chủ
    adsdefender_lt_conversion($type, $label, $value, $page, true);
    wp_send_json_success();
}

// Script theo dõi click liên hệ — listener thụ động (passive, không preventDefault, bọc try/catch)
// nên không thể chặn tel: / Zalo mở ra; không phụ thuộc module Marketing; HTML tĩnh → hợp page cache.
add_action('wp_footer', function () {
    if (is_admin() || !adsdefender_lt_opts()['enabled']) return;
    $u = esc_url(admin_url('admin-ajax.php'));
    ?>
<script>
(function(){var u='<?php echo $u; ?>',d={},T=[[/^tel:/i,'phone'],[/zalo\.me|zaloapp\.com|^zalo:/i,'zalo'],[/\/\/(www\.)?m\.me\/|messenger\.com/i,'messenger'],[/wa\.me|whatsapp\.com|^whatsapp:/i,'whatsapp'],[/\/\/t\.me\/|^tg:/i,'telegram'],[/^mailto:/i,'email']];
function s(t,l,v){try{var k=t+'|'+l,n=Date.now();if(d[k]&&n-d[k]<5000)return;d[k]=n;var f=new FormData();f.append('action','adsdefender_lt_conv');f.append('type',t);f.append('label',String(l||'').slice(0,150));f.append('page',location.href);if(v)f.append('value',v);if(navigator.sendBeacon)navigator.sendBeacon(u,f);else fetch(u,{method:'POST',body:f,keepalive:true,credentials:'same-origin'});}catch(e){}}
window.adsLtConv=s;
document.addEventListener('click',function(e){try{var a=e.target&&e.target.closest&&e.target.closest('a[href]');if(!a)return;var h=a.getAttribute('href')||'';for(var i=0;i<T.length;i++)if(T[i][0].test(h)){s(T[i][1],h.replace(/^(tel:|mailto:)/i,'').split('?')[0]);return;}}catch(x){}},{capture:true,passive:true});})();
</script>
<?php
}, 99);

// Form & đơn hàng — ghi phía máy chủ (chắc chắn hơn JS, không đếm form gửi lỗi)
function adsdefender_lt_ref(): string { return (string) ($_SERVER['HTTP_REFERER'] ?? ''); }
add_action('wpcf7_mail_sent', function ($cf) {
    adsdefender_lt_conversion('form', 'CF7: ' . (is_object($cf) && method_exists($cf, 'title') ? $cf->title() : ''), 0, adsdefender_lt_ref());
});
add_action('wpforms_process_complete', function ($fields, $entry, $form) {
    adsdefender_lt_conversion('form', 'WPForms: ' . ($form['settings']['form_title'] ?? ''), 0, adsdefender_lt_ref());
}, 10, 3);
add_action('elementor_pro/forms/new_record', function ($record) {
    adsdefender_lt_conversion('form', 'Elementor: ' . (is_object($record) && method_exists($record, 'get_form_settings') ? (string) $record->get_form_settings('form_name') : ''), 0, adsdefender_lt_ref());
});
add_action('fluentform/submission_inserted', function ($id, $data, $form) {
    adsdefender_lt_conversion('form', 'Fluent Forms: ' . (is_object($form) ? (string) ($form->title ?? '') : ''), 0, adsdefender_lt_ref());
}, 10, 3);
add_action('gform_after_submission', function ($entry, $form) {
    adsdefender_lt_conversion('form', 'Gravity Forms: ' . ($form['title'] ?? ''), 0, adsdefender_lt_ref());
}, 10, 2);
add_action('woocommerce_checkout_order_processed', function ($order_id) {
    $o = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if ($o) adsdefender_lt_conversion('purchase', 'Đơn #' . $o->get_order_number(), (float) $o->get_total(), adsdefender_lt_ref());
});
add_action('woocommerce_store_api_checkout_order_processed', function ($o) {
    if (is_object($o) && method_exists($o, 'get_total')) adsdefender_lt_conversion('purchase', 'Đơn #' . $o->get_order_number(), (float) $o->get_total(), adsdefender_lt_ref());
});

function adsdefender_lt_prune(): void
{
    global $wpdb;
    $o     = adsdefender_lt_opts();
    $table = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;
    $suppress = $wpdb->suppress_errors(true);
    $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE created_at < %s",
        date('Y-m-d H:i:s', current_time('timestamp') - $o['hours'] * HOUR_IN_SECONDS)));
    $max_id = (int) $wpdb->get_var("SELECT MAX(id) FROM `{$table}`");
    if ($max_id > $o['max_rows']) {
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE id <= %d", $max_id - $o['max_rows']));
    }
    $wpdb->suppress_errors($suppress);
}
add_action(ADSDEFENDER_CRON_HOOK, 'adsdefender_lt_prune', 20);

// ─── Đọc (AJAX cho màn hình thời gian thực) ───────────────────────────────────

function adsdefender_lt_query(array $f, int $after_id = 0, int $limit = 100): array
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;
    $where = ['1=1']; $args = [];
    if ($after_id > 0)            { $where[] = 'id > %d';      $args[] = $after_id; }
    if (!empty($f['kind']))       {
        if ($f['kind'] === 'bots') { $where[] = "kind IN ('bot','good_bot')"; }
        elseif ($f['kind'] === 'conv') { $where[] = 'via = 2'; }
        elseif ($f['kind'] === 'fraud') { $where[] = "fraud <> ''"; }
        else                       { $where[] = 'kind = %s';   $args[] = $f['kind']; }
    }
    if (($f['source'] ?? '') === 'google_ads_fake') {
        $where[] = "source = 'google_ads' AND click_flag <> ''";
    } elseif (!empty($f['source']))     {
        // Khớp lượt vào từ nguồn đó + các trang khách đó xem tiếp (via=1, track.from)
        $list = adsdefender_lt_source_groups()[$f['source']] ?? [$f['source']];
        $or   = ['source IN (' . implode(',', array_fill(0, count($list), '%s')) . ')'];
        array_push($args, ...$list);
        foreach ($list as $src) { $or[] = '(via = 1 AND track LIKE %s)'; $args[] = '%' . $wpdb->esc_like('"from":"' . $src . '"') . '%'; }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if (!empty($f['ip']))         { $where[] = 'ip = %s';      $args[] = $f['ip']; }
    if (!empty($f['vid']))        { $where[] = 'visitor_id = %s'; $args[] = $f['vid']; }
    if (!empty($f['status']))     { $where[] = $f['status'] === 'error' ? 'status >= 400' : 'status < 400'; }
    if (!empty($f['tracked']))    { $where[] = "(campaign <> '' OR keyword <> '')"; }
    if (isset($f['campaign']) && $f['campaign'] !== '') { $where[] = 'campaign = %s'; $args[] = $f['campaign']; }
    if (isset($f['keyword']) && $f['keyword'] !== '')   { $where[] = 'keyword = %s';  $args[] = $f['keyword']; }
    if (!empty($f['q'])) {
        $like = '%' . $wpdb->esc_like($f['q']) . '%';
        $where[] = '(campaign LIKE %s OR keyword LIKE %s OR url LIKE %s)'; array_push($args, $like, $like, $like);
    }
    $sql = "SELECT * FROM `{$table}` WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . (int) $limit;
    $suppress = $wpdb->suppress_errors(true);
    $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    $wpdb->suppress_errors($suppress);
    return is_array($rows) ? $rows : [];
}

function adsdefender_lt_stats(): array
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;
    $now   = current_time('timestamp');
    $suppress = $wpdb->suppress_errors(true);
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT SUM(via < 2) AS hits, COUNT(DISTINCT ip) AS ips,
                SUM(via < 2 AND kind IN ('bot','good_bot')) AS bots, SUM(via < 2 AND kind = 'blocked') AS blocked,
                SUM(via = 0 AND source IN ('google_ads','meta_ads','tiktok_ads','ads')) AS ads,
                SUM(via = 0 AND source = 'google_ads') AS g_ads, SUM(via = 0 AND source = 'google_organic') AS g_org,
                SUM(via = 0 AND source = 'google_ads' AND click_flag <> '') AS g_fake,
                SUM(via = 2) AS conv, SUM(via = 2 AND source = 'google_ads') AS conv_g,
                SUM(fraud <> '') AS cf, COUNT(DISTINCT CASE WHEN fraud <> '' THEN ip END) AS cf_ips
         FROM `{$table}` WHERE created_at >= %s", date('Y-m-d H:i:s', $now - HOUR_IN_SECONDS)), ARRAY_A);
    $online = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT ip) FROM `{$table}` WHERE created_at >= %s AND kind = 'human' AND via < 2", date('Y-m-d H:i:s', $now - 5 * MINUTE_IN_SECONDS)));
    $wpdb->suppress_errors($suppress);
    return [
        'online'  => $online,
        'hits'    => (int) ($r['hits'] ?? 0),
        'ips'     => (int) ($r['ips'] ?? 0),
        'bots'    => (int) ($r['bots'] ?? 0),
        'blocked' => (int) ($r['blocked'] ?? 0),
        'ads'     => (int) ($r['ads'] ?? 0),
        'g_ads'   => (int) ($r['g_ads'] ?? 0),
        'g_org'   => (int) ($r['g_org'] ?? 0),
        'g_fake'  => (int) ($r['g_fake'] ?? 0),
        'conv'    => (int) ($r['conv'] ?? 0),
        'conv_g'  => (int) ($r['conv_g'] ?? 0),
        'cf'      => (int) ($r['cf'] ?? 0),
        'cf_ips'  => (int) ($r['cf_ips'] ?? 0),
    ];
}

/**
 * Top chiến dịch / từ khoá: chỉ đếm lượt VÀO (via = 0), các trang xem tiếp (via = 1) đếm riêng.
 * Nhiều click trên ít IP, nhiều bot / bị chặn, không ai xem tiếp → dấu hiệu click ảo.
 */
function adsdefender_lt_top(int $hours, string $by = 'keyword'): array
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_TRAFFIC_TABLE;
    $group = $by === 'campaign' ? 'campaign' : 'campaign, keyword';
    $cond  = $by === 'campaign' ? "campaign <> ''" : "keyword <> ''";
    $since = date('Y-m-d H:i:s', current_time('timestamp') - $hours * HOUR_IN_SECONDS);
    $suppress = $wpdb->suppress_errors(true);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT {$group}, COUNT(*) AS clicks, COUNT(DISTINCT ip) AS ips,
                SUM(kind IN ('bot','good_bot')) AS bots, SUM(kind = 'blocked') AS blocked,
                SUM(kind NOT IN ('bot','good_bot','blocked') AND (click_flag <> '' OR fraud <> '')) AS fake,
                MAX(created_at) AS last_at
         FROM `{$table}` WHERE created_at >= %s AND via = 0 AND {$cond}
         GROUP BY {$group} ORDER BY clicks DESC LIMIT 20", $since), ARRAY_A);
    $deep = $wpdb->get_results($wpdb->prepare(
        "SELECT {$group}, COUNT(*) AS pv FROM `{$table}` WHERE created_at >= %s AND via = 1 AND {$cond} GROUP BY {$group}", $since), ARRAY_A);
    $convs = $wpdb->get_results($wpdb->prepare(
        "SELECT {$group}, COUNT(*) AS conv, SUM(conv_value) AS val FROM `{$table}`
         WHERE created_at >= %s AND via = 2 AND kind NOT IN ('bot','good_bot','blocked') AND {$cond} GROUP BY {$group}", $since), ARRAY_A);
    $wpdb->suppress_errors($suppress);
    $pv = [];
    foreach ((array) $deep as $d) $pv[$d['campaign'] . "\0" . ($d['keyword'] ?? '')] = (int) $d['pv'];
    $cv = [];
    foreach ((array) $convs as $d) $cv[$d['campaign'] . "\0" . ($d['keyword'] ?? '')] = [(int) $d['conv'], (float) $d['val']];
    $out = [];
    foreach ((array) $rows as $r) {
        $clicks = (int) $r['clicks'];
        $bad    = (int) $r['bots'] + (int) $r['blocked'] + (int) $r['fake'];   // + gclid đáng ngờ (không đếm trùng bot)
        $out[] = [
            'campaign' => (string) $r['campaign'],
            'keyword'  => (string) ($r['keyword'] ?? ''),
            'clicks'   => $clicks,
            'ips'      => (int) $r['ips'],
            'bad'      => $bad,
            'pv'       => $pv[$r['campaign'] . "\0" . ($r['keyword'] ?? '')] ?? 0,
            'conv'     => $cv[$r['campaign'] . "\0" . ($r['keyword'] ?? '')][0] ?? 0,
            'conv_val' => $cv[$r['campaign'] . "\0" . ($r['keyword'] ?? '')][1] ?? 0,
            // % đáng ngờ = (bot + bị chặn + click lặp lại cùng IP) / tổng click
            'risk'     => $clicks ? min(100, (int) round(100 * ($bad + max(0, $clicks - (int) $r['ips'])) / $clicks)) : 0,
            'last'     => mysql2date('H:i d/m', $r['last_at']),
        ];
    }
    return $out;
}

function adsdefender_lt_track_labels(array $t): array
{
    $mt  = ['e' => 'Chính xác', 'p' => 'Cụm từ', 'b' => 'Rộng'];
    $net = ['g' => 'Google Search', 's' => 'Search Partners', 'd' => 'Display', 'ytv' => 'YouTube', 'yt' => 'YouTube',
            'vp' => 'Video partners', 'x' => 'Performance Max', 'u' => 'Smart / đa kênh'];
    $dev = ['m' => 'Di động', 't' => 'Máy tính bảng', 'c' => 'Máy tính'];
    $map = [
        'src' => 'utm_source', 'med' => 'utm_medium', 'cnt' => 'utm_content', 'cid' => 'Campaign ID',
        'ag' => 'Nhóm QC / Ad set', 'mt' => 'Kiểu khớp', 'net' => 'Mạng', 'dev' => 'Thiết bị', 'plc' => 'Vị trí (placement)',
        'cr' => 'Mẫu QC (creative)', 'tgt' => 'Target ID', 'gsrc' => 'gad_source', 'clk' => 'Click ID',
    ];
    $out = [];
    foreach ($map as $k => $label) {
        if (!isset($t[$k]) || $t[$k] === '') continue;
        $v = (string) $t[$k];
        if ($k === 'mt')  $v = $mt[$v]  ?? $v;
        if ($k === 'net') $v = $net[$v] ?? $v;
        if ($k === 'dev') $v = $dev[$v] ?? $v;
        $out[] = [$label, $v];
    }
    if (!empty($t['kws'])) $out[] = ['Từ khoá lấy từ', 'trang kết quả tìm kiếm (referer)'];
    return $out;
}

function adsdefender_lt_present(array $row): array
{
    $t = !empty($row['track']) ? json_decode($row['track'], true) : [];
    if (!is_array($t)) $t = [];
    $path = (string) parse_url($row['url'], PHP_URL_PATH);
    $qs   = (string) parse_url($row['url'], PHP_URL_QUERY);
    $ti   = str_starts_with($row['note'], 'ti:') ? substr($row['note'], 3) : '';
    if ($ti === '' && function_exists('adsdefender_ti_lookup') && ($hit = adsdefender_ti_lookup($row['ip']))) $ti = $hit['source'];
    return [
        'id'      => (int) $row['id'],
        'time'    => mysql2date('H:i:s', $row['created_at']),
        'date'    => mysql2date('d/m', $row['created_at']),
        'ip'      => $row['ip'],
        'ref'     => function_exists('adsdefender_block_ref') ? adsdefender_block_ref($row['ip']) : '',
        'method'  => $row['method'],
        'path'    => $path . ($qs !== '' ? '?' . $qs : ''),
        'url'     => $row['url'],
        'status'  => (int) $row['status'],
        'kind'    => $row['kind'],
        'note'    => str_starts_with($row['note'], 'ti:') ? '' : $row['note'],
        'source'  => $row['source'],
        'referer' => $row['referer'],
        'ua'      => $row['user_agent'],
        'vid'     => $row['visitor_id'],
        'ti'      => $ti ? (function_exists('adsdefender_ti_sources') ? (adsdefender_ti_sources()[$ti]['label'] ?? $ti) : $ti) : '',
        'campaign'=> (string) ($row['campaign'] ?? ''),
        'keyword' => (string) ($row['keyword'] ?? ''),
        'mt'      => (string) ($t['mt'] ?? ''),
        'via'     => (int) ($row['via'] ?? 0),
        'from'    => (string) ($t['from'] ?? ''),
        'cflag'   => (string) ($row['click_flag'] ?? ''),
        'fraud'      => (string) ($row['fraud'] ?? ''),
        'conv'       => (string) ($row['conv'] ?? ''),
        'conv_name'  => ($row['conv'] ?? '') !== '' ? (adsdefender_lt_conv_types()[$row['conv']] ?? $row['conv']) : '',
        'conv_label' => (string) ($row['conv_label'] ?? ''),
        'conv_value' => (float) ($row['conv_value'] ?? 0),
        'track'   => adsdefender_lt_track_labels($t),
    ];
}

add_action('wp_ajax_adsdefender_lt_feed', function () {
    check_ajax_referer('adsdefender_lt', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền');
    $f = [
        'kind'   => sanitize_key($_POST['kind'] ?? ''),
        'source' => sanitize_key($_POST['source'] ?? ''),
        'ip'     => filter_var(trim((string) ($_POST['ip'] ?? '')), FILTER_VALIDATE_IP) ?: '',
        'vid'    => preg_replace('/[^a-f0-9]/', '', strtolower((string) ($_POST['vid'] ?? ''))),
        'status' => sanitize_key($_POST['status'] ?? ''),
        'tracked'  => !empty($_POST['tracked']),
        'campaign' => isset($_POST['campaign']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['campaign'])), 0, 150) : '',
        'keyword'  => isset($_POST['keyword'])  ? mb_substr(sanitize_text_field(wp_unslash($_POST['keyword'])), 0, 150) : '',
        'q'        => isset($_POST['q']) ? mb_substr(sanitize_text_field(wp_unslash($_POST['q'])), 0, 100) : '',
    ];
    $after = max(0, (int) ($_POST['after'] ?? 0));
    $rows  = array_map('adsdefender_lt_present', adsdefender_lt_query($f, $after, $after ? 200 : 100));
    wp_send_json_success(['rows' => $rows, 'stats' => adsdefender_lt_stats()]);
});

add_action('wp_ajax_adsdefender_lt_top', function () {
    check_ajax_referer('adsdefender_lt', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không có quyền');
    $hours = (int) ($_POST['hours'] ?? 24);
    if (!in_array($hours, [1, 6, 24, 168, 720], true)) $hours = 24;
    $by = ($_POST['by'] ?? '') === 'campaign' ? 'campaign' : 'keyword';
    wp_send_json_success(['rows' => adsdefender_lt_top($hours, $by)]);
});

// Chặn / whitelist ngay trên dòng
add_action('wp_ajax_adsdefender_lt_action', function () {
    check_ajax_referer('adsdefender_lt', 'nonce');
    if (!current_user_can('manage_options') || !adsdefender_is_manager()) wp_send_json_error('Chỉ AdsDefender Manager mới thao tác được');
    $ip = trim((string) ($_POST['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) wp_send_json_error('IP không hợp lệ');
    $do = sanitize_key($_POST['do'] ?? '');

    if ($do === 'block') {
        $mine = adsdefender_get_real_ip();
        if ($mine && $mine === $ip) wp_send_json_error('Đây là IP của chính bạn — không chặn.');
        $manual = adsdefender_get_manual_ips();
        if (in_array($ip, array_column($manual, 'ip'), true)) wp_send_json_success(['msg' => 'IP đã nằm trong danh sách chặn']);
        $manual[] = ['ip' => $ip, 'note' => 'Live Traffic', 'added' => current_time('mysql')];
        update_option(ADSDEFENDER_OPTION_MANUAL, $manual, false);
        wp_send_json_success(['msg' => 'Đã chặn ' . $ip]);
    }
    if ($do === 'whitelist') {
        $opts = adsdefender_settings();
        $wl   = adsdefender_parse_whitelist($opts['whitelist'] ?? '');
        if (in_array($ip, $wl, true)) wp_send_json_success(['msg' => 'IP đã có trong whitelist']);
        $opts['whitelist'] = trim(($opts['whitelist'] ?? '') . "\n" . $ip);
        update_option('adsdefender_settings', $opts);
        wp_send_json_success(['msg' => 'Đã thêm ' . $ip . ' vào whitelist']);
    }
    wp_send_json_error('Thao tác không hợp lệ');
});

// Bật Live Traffic lần đầu → tạo bảng ngay (migration chỉ chạy khi cập nhật version)
add_action('update_option_adsdefender_settings', function ($old, $new) {
    if (!empty($new['lt_enabled']) && (empty($old['lt_enabled']) || (int) get_option('adsdefender_lt_db', 0) < ADSDEFENDER_LT_DB_VER)) adsdefender_lt_create_table();
}, 20, 2);
