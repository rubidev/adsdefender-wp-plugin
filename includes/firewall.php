<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * AdsDefender — Request Firewall
 *
 * Phát hiện và block các request độc hại:
 *  • SQL Injection (URL, POST, query string)
 *  • XSS (script injection)
 *  • Path traversal (../../ lên thư mục hệ thống)
 *  • Bad User-Agent (scanner, bot độc hại — danh sách HackRepair)
 *  • RCE / RFI / LFI patterns
 *
 * Block ngay lập tức với HTTP 403, không qua WP_Error để
 * tránh khởi động đầy đủ WordPress cho các request rõ ràng là độc hại.
 */
if (!defined('ABSPATH')) exit;

// ─── Settings Helper ─────────────────────────────────────────────────────────

function adsdefender_fw_enabled(): bool
{
    return !empty(adsdefender_settings()['fw_enabled']);
}

function adsdefender_ua_enabled(): bool
{
    return !empty(adsdefender_settings()['ua_enabled']);
}

// ─── Bộ luật ─────────────────────────────────────────────────────────────────
//
// Toàn bộ luật (UA xấu, bot SEO, pattern theo nhóm, URI nhạy cảm) nằm trong
// includes/firewall-rules.dat dạng hex — sinh từ dev/firewall-rules.json bằng
// dev/build-rules.py. KHÔNG viết luật trực tiếp vào file PHP này: scanner
// malware trên hosting so khớp text và cách ly cả file (đã gặp với cPGuard).
//
// Nguyên tắc chọn luật (xem chi tiết ở file nguồn JSON):
//  • Chỉ bắt khi có DẤU HIỆU CÚ PHÁP tấn công, không bắt từ khoá đứng một mình
//  • UA: chỉ scanner / tool tấn công thật, không đưa search engine vào
//  • Bot SEO: không độc hại, mặc định TẮT, chặn thì không ghi lockout

function adsdefender_fw_rules(string $group): array
{
    static $rules = null;
    if ($rules === null) {
        $rules = [];
        $file  = __DIR__ . '/firewall-rules.dat';
        $hex   = is_readable($file) ? preg_replace('/\s+/', '', (string) file_get_contents($file)) : '';
        if ($hex !== '' && ctype_xdigit($hex) && strlen($hex) % 2 === 0) {
            $data = json_decode((string) hex2bin($hex), true);
            if (is_array($data)) $rules = $data;
        }
    }
    $list = $rules[$group] ?? [];
    return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
}

// So khớp UA bằng str_contains trên UA đã strtolower → luật viết thường hết.
function adsdefender_bad_ua_patterns(): array  { return adsdefender_fw_rules('bad_ua'); }
function adsdefender_seo_bot_patterns(): array { return adsdefender_fw_rules('seo_bot'); }


// ─── Chuẩn hoá payload trước khi so khớp ─────────────────────────────────────
//
// Kẻ tấn công né filter bằng cách mã hoá nhiều lớp (%252f = '/' sau 2 lần decode),
// chèn comment SQL (UNI/**/ON), hoặc dùng HTML entity (javas&#99;ript).
// Chuẩn hoá trước rồi mới so khớp thì pattern gọn hơn mà bắt được nhiều hơn.

function adsdefender_fw_normalize(string $s): string
{
    // Giải mã tối đa 3 lớp URL-encode (đủ cho mọi kỹ thuật thực tế)
    for ($i = 0; $i < 3; $i++) {
        $dec = rawurldecode($s);
        if ($dec === $s) break;
        $s = $dec;
    }
    // HTML entity: &#99; &#x63; &lt; …
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Bỏ null byte dùng để cắt chuỗi
    $s = str_replace("\0", '', $s);
    // Gom khoảng trắng (kể cả tab/newline dùng để né \s+)
    $s = preg_replace('/[\s\x0B]+/', ' ', $s);

    return $s;
}

// Bỏ comment SQL — dùng riêng cho nhóm SQLi để bắt UNION/**/SELECT.
// Thay bằng KHOẢNG TRẮNG chứ không xoá trắng: xoá trắng biến
// "UNION/**/SELECT" thành "UNIONSELECT" làm \b không còn khớp.
function adsdefender_fw_strip_sql_comments(string $s): string
{
    $s = preg_replace('#/\*.*?\*/#s', ' ', $s);
    // Comment lồng nhau kiểu /*!50000UNION*/ của MySQL
    $s = preg_replace('#/\*![\d]*(.*?)\*/#s', ' $1 ', $s);
    return $s;
}

// ─── Pattern theo nhóm (chạy trên chuỗi ĐÃ chuẩn hoá) ────────────────────────

function adsdefender_sqli_patterns(): array      { return adsdefender_fw_rules('sqli'); }
function adsdefender_xss_patterns(): array       { return adsdefender_fw_rules('xss'); }
function adsdefender_traversal_patterns(): array { return adsdefender_fw_rules('traversal'); }
function adsdefender_shellcode_patterns(): array { return adsdefender_fw_rules('shellcode'); }

// ─── Main firewall check ──────────────────────────────────────────────────────

// Field mà nội dung dài, giàu ký tự đặc biệt là chuyện bình thường (soạn thảo
// bài viết, bình luận). Người dùng có quyền edit đã qua kiểm tra của WordPress,
// nên quét các field này chỉ tạo chặn nhầm.
function adsdefender_fw_skip_keys(): array
{
    return [
        'content', 'post_content', 'excerpt', 'post_excerpt', 'description',
        'comment', 'comment_content', 'text', 'html', 'body', 'message',
        'custom_css', 'custom_html', 'widget-text', '_wp_http_referer',
    ];
}

// Gom mọi giá trị chuỗi trong mảng lồng nhau (bản cũ chỉ lấy cấp 1 nên
// payload nhét trong data[user][name] lọt hết).
function adsdefender_fw_flatten(array $arr, array $skip, int $depth = 0): array
{
    if ($depth > 6) return [];               // chặn mảng lồng quá sâu
    $out = [];
    foreach ($arr as $k => $v) {
        if (is_string($k) && in_array(strtolower($k), $skip, true)) continue;
        if (is_array($v)) {
            $out = array_merge($out, adsdefender_fw_flatten($v, $skip, $depth + 1));
        } elseif (is_string($v)) {
            if (is_string($k)) $out[] = $k;   // tên field cũng có thể chứa payload
            $out[] = $v;
        }
    }
    return $out;
}

function adsdefender_firewall_check(): void
{
    if (!adsdefender_fw_enabled()) return;

    if (defined('WP_CLI') && WP_CLI) return;
    if (defined('DOING_CRON') && DOING_CRON) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;

    $ip = adsdefender_get_real_ip();

    // Bot hợp pháp đã xác minh không bị quét
    if (adsdefender_is_verified_bot($ip)) return;

    // Skip whitelist / temp whitelist
    $opts_fw   = adsdefender_settings();
    $whitelist = adsdefender_parse_whitelist($opts_fw['whitelist'] ?? '');
    if (!empty($whitelist) && adsdefender_ip_matches($ip, $whitelist)) return;
    if (adsdefender_is_temp_whitelisted($ip)) return;

    // Người dùng đã đăng nhập có quyền sửa nội dung: bỏ qua để không chặn
    // nhầm biên tập viên dán HTML/mã ví dụ vào bài viết.
    if (is_user_logged_in() && current_user_can('edit_posts')) return;

    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    $skip = adsdefender_fw_skip_keys();

    // Quét TỪNG chuỗi riêng, không gộp thành một — gộp lại làm pattern khớp
    // chéo giữa hai field vô hại cạnh nhau (bản cũ mắc lỗi này).
    $targets = [$uri];

    if (!empty($_SERVER['QUERY_STRING'])) {
        $targets[] = $_SERVER['QUERY_STRING'];
    }
    if (!empty($_GET)) {
        $targets = array_merge($targets, adsdefender_fw_flatten($_GET, $skip));
    }
    if (!empty($_POST)) {
        $targets = array_merge($targets, adsdefender_fw_flatten($_POST, $skip));
    }
    // Cookie là vector SQLi/XSS rất hay bị bỏ sót
    if (!empty($_COOKIE)) {
        foreach ($_COOKIE as $ck => $cv) {
            // Bỏ qua cookie của chính WordPress (chứa hash, dễ khớp nhầm)
            if (str_starts_with($ck, 'wordpress') || str_starts_with($ck, 'wp-')
                || str_starts_with($ck, 'comment_author')) continue;
            if (is_string($cv)) $targets[] = $cv;
        }
    }
    // Header thường bị nhét payload (Log4j lookup, SQLi qua X-Forwarded-For)
    foreach (['HTTP_REFERER', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP',
              'HTTP_X_REAL_IP', 'HTTP_ACCEPT_LANGUAGE'] as $h) {
        if (!empty($_SERVER[$h]) && is_string($_SERVER[$h])) $targets[] = $_SERVER[$h];
    }

    $groups = [
        'sqli'      => 'adsdefender_sqli_patterns',
        'xss'       => 'adsdefender_xss_patterns',
        'traversal' => 'adsdefender_traversal_patterns',
        'shellcode' => 'adsdefender_shellcode_patterns',
    ];

    foreach ($targets as $raw) {
        if ($raw === '' || !is_string($raw)) continue;
        // Chuỗi quá dài gần như luôn là nội dung thật, không phải payload;
        // quét tiếp chỉ tốn CPU và tăng nguy cơ chặn nhầm.
        if (strlen($raw) > 8192) continue;

        $norm = adsdefender_fw_normalize($raw);
        $sql  = adsdefender_fw_strip_sql_comments($norm);

        foreach ($groups as $reason => $fn) {
            $subject = ($reason === 'sqli') ? $sql : $norm;
            foreach ($fn() as $pat) {
                if (preg_match($pat, $subject)) {
                    adsdefender_fw_block($ip, $reason, $uri);
                    return;
                }
            }
        }
    }
}

function adsdefender_ua_check(): void
{
    if (!adsdefender_ua_enabled()) return;
    if (defined('WP_CLI') && WP_CLI) return;
    if (defined('DOING_CRON') && DOING_CRON) return;

    // Skip whitelist
    $ip = adsdefender_get_real_ip();
    $opts_ua = adsdefender_settings();
    $wl_ua = adsdefender_parse_whitelist($opts_ua['whitelist'] ?? '');
    if (!empty($wl_ua) && adsdefender_ip_matches($ip, $wl_ua)) return;
    if (adsdefender_is_temp_whitelisted($ip)) return;

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $ua  = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

    // UA rỗng: nhiều thứ hợp lệ (cron hosting, uptime monitor, health check)
    // gửi UA rỗng → chỉ chặn khi nhắm vào endpoint nhạy cảm.
    if ($ua === '') {
        if (adsdefender_ua_is_sensitive_uri($uri)) {
            adsdefender_fw_block($ip, 'empty_ua', $uri);
        }
        return;
    }

    foreach (adsdefender_bad_ua_patterns() as $bad) {
        if (str_contains($ua, $bad)) {
            adsdefender_fw_block($ip, 'bad_ua:' . $bad, $uri);
            return;
        }
    }

    // Bot SEO — tuỳ chọn riêng, chặn nhưng không ghi lockout
    if (!empty($opts_ua['seo_bot_block'])) {
        foreach (adsdefender_seo_bot_patterns() as $bot) {
            if (str_contains($ua, $bot)) {
                adsdefender_fw_block($ip, 'seo_bot:' . $bot, $uri, false);
                return;
            }
        }
    }
}

// Endpoint mà UA rỗng gần như chắc chắn là bot dò tìm
function adsdefender_ua_is_sensitive_uri(string $uri): bool
{
    $u = strtolower($uri);
    foreach (adsdefender_fw_rules('sensitive_uri') as $needle) {
        if (str_contains($u, $needle)) return true;
    }
    return false;
}

function adsdefender_fw_block(string $ip, string $reason, string $uri, bool $lockout = true): void
{
    // IP trong whitelist → không block, không ghi lockout
    if ($ip) {
        $opts = adsdefender_settings();
        $whitelist = adsdefender_parse_whitelist($opts['whitelist'] ?? '');
        if (!empty($whitelist) && adsdefender_ip_matches($ip, $whitelist)) return;
        if (adsdefender_is_temp_whitelisted($ip)) return;
    }

    // Ghi lockout — bỏ qua với bot vô hại ($lockout = false) để không phình
    // bảng lockout và che mất cảnh báo tấn công thật
    if ($ip) {
        if ($lockout) {
            adsdefender_upsert_lockout($ip, 'firewall');
            adsdefender_log_block($ip);
        }
        adsdefender_sec_log('firewall', $ip, $reason . ' | ' . substr($uri, 0, 200));
    }

    // Trả 403 ngay qua trang chặn chung (không lộ tên plugin / lý do kỹ thuật)
    adsdefender_block_response('firewall', 403);
}

// ─── Hook vào init priority 1 (cùng với check_ip) ────────────────────────────

add_action('init', function () {
    adsdefender_ua_check();
    adsdefender_firewall_check();
}, 1);
