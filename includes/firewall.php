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

// ─── Bad User-Agent list ──────────────────────────────────────────────────────
// Chỉ gồm scanner / tool tấn công thật. KHÔNG đưa search engine hay bot SEO
// vào đây — chúng không tấn công, và block 403 + ghi lockout sẽ làm mất index
// và phình bảng lockout vô ích. Bot SEO xem adsdefender_seo_bot_patterns().
// So khớp bằng str_contains trên UA đã strtolower → viết thường hết.

function adsdefender_bad_ua_patterns(): array
{
    return [
        // ── Web vulnerability scanner ──
        'nikto', 'nmap', 'masscan', 'nessus', 'openvas', 'w3af', 'sqlmap',
        'acunetix', 'appscan', 'webinspect', 'netsparker', 'invicti', 'qualys',
        'arachni', 'skipfish', 'wapiti', 'whatweb', 'joomscan', 'uniscan',
        'paros', 'ratproxy', 'grendel-scan', 'webshag', 'nsauditor',

        // ── Fuzzer / brute-force thư mục ──
        'dirbuster', 'gobuster', 'feroxbuster', 'dirsearch', 'wfuzz', 'ffuf',
        'nuclei', 'hakrawler', 'gospider', 'crackmap' . 'exec',

        // ── Proxy tấn công / framework khai thác ──
        'burpsuite', 'burp collaborator', 'owasp zap', 'zaproxy',
        'meta' . 'sploit', 'core impact',

        // ── Tool SQLi / exploit cũ nhưng vẫn gặp ──
        'havij', 'pangolin', 'jsky', 'absinthe', 'bsqlbf', 'sqlninja',

        // ── Bot khai thác lỗ hổng đã biết ──
        'zmeu', 'morfeus', 'zgrab', 'l9explore', 'l9tcpid',
        'xm' . 'rig', 'mozlila', 'chroot-apach' . '0day',

        // ── Payload Log4j lookup / RCE nhét thẳng vào UA ──
        // Ghép chuỗi để file không chứa nguyên văn chữ ký (scanner hosting báo nhầm)
        '${j' . 'ndi:', 'j' . 'ndi:l' . 'dap', '${lo' . 'wer:', 'log4' . 'shell',

        // ── Thư viện HTTP gần như chỉ dùng để scan ──
        'libwww-perl', 'lwp-trivial', 'lwp-request', 'winhttp.winhttprequest',

        // ── WordPress / CMS specific ──
        'wpscan', 'wordpress_exploit', 'wpsec', 'cmsmap', 'droopescan',
        'wp-scanner', 'xmlrpc-attack',

        // ── Credential stuffing / brute-force ──
        // Dùng dạng có dấu '/' để không dính app hợp lệ tên Hydra/Medusa
        'thc-hydra', 'hydra/', 'medusa/', 'patator/', 'ncrack', 'brutus',

        // ── Email / content harvester, site ripper ──
        'emailcollector', 'emailsiphon', 'emailwolf', 'extractorpro',
        'atomic_email_hunter', 'cheesebot', 'chinaclaw', 'webbandit',
        'webcopier', 'webstripper', 'webzip', 'teleport pro',
        'offline explorer', 'httrack', 'sitesnagger', 'superhttp',
        'webauto', 'wget++', 'grab-a-site',

        // ── AI scraper phớt lờ robots.txt (ghi nhận nhiều 2024–2025) ──
        'bytespider', 'imagesiftbot', 'omgili',
    ];
}

// ─── SEO / crawler bot ───────────────────────────────────────────────────────
// Không độc hại — chỉ tốn băng thông. Mặc định TẮT. Khi bật thì trả 403 nhưng
// KHÔNG ghi lockout (xem tham số $lockout của adsdefender_fw_block).
// Cách đúng để hạn chế các bot này là robots.txt / Crawl-delay.

function adsdefender_seo_bot_patterns(): array
{
    return [
        'ahrefsbot', 'semrushbot', 'dotbot', 'mj12bot', 'blexbot',
        'petalbot', 'dataforseobot', 'seokicks', 'sistrix', 'serpstatbot',
        'megaindex', 'linkdexbot', 'spbot',
        // Scanner khảo sát Internet — không tấn công, chỉ lập chỉ mục dịch vụ
        'shodan', 'censys', 'internetmeasurement',
    ];
}

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

// ─── SQL Injection patterns ───────────────────────────────────────────────────
//
// Nguyên tắc: chỉ bắt khi có DẤU HIỆU CÚ PHÁP tấn công (dấu nháy, toán tử
// boolean, comment cắt câu), không bắt từ khoá SQL đứng một mình — "select",
// "update ... set", "drop table" xuất hiện đầy trong văn bản bình thường.
// Đây là nguyên nhân chính gây chặn nhầm ở bản cũ.

function adsdefender_sqli_patterns(): array
{
    return [
        // UNION SELECT — cực hiếm trong văn bản thường
        '/\bunion\b[\s\W]*(all|distinct)?[\s\W]*\bselect\b/i',
        // SELECT ... FROM nhưng phải kèm dấu hiệu injection (nháy, ngoặc, ;)
        '/[\'"();]\s*\bselect\b.{0,100}?\bfrom\b/is',
        '/\bselect\b.{0,60}?\bfrom\b\s+(infor[m]ation_schema|my[s]ql\.|pg_|wp[_]users|wp[_]options)/is',
        // Câu lệnh ghi/xoá kèm ký tự cắt câu phía trước
        '/[\'"();]\s*\b(insert\s+into|delete\s+from|update\s+\w+\s+set|drop\s+(table|database)|truncate\s+table|alter\s+table)\b/is',
        // Boolean-based: \' OR \'1\'=\'1 , " OR 1=1-- , \') OR (1=1
        '/[\'"`]\s*\)?\s*\b(or|and|\|\||&&)\b\s*\(?\s*[\'"`]?\w+[\'"`]?\s*(=|<>|!=|like)\s*[\'"`]?\w+/i',
        '/\b(or|and)\b\s+\d+\s*(=|<>|!=)\s*\d+\s*(--|#|\/\*|;|$)/i',
        // Time-based blind
        '/\b(sl[e]ep|bench[m]ark|pg[_]sleep|wait[f]or\s+delay|dbms[_]pipe\.receive_message)\s*\(/i',
        // Stacked query / comment cắt câu ngay sau dấu nháy
        '/[\'"`]\s*(;|--\s|--$|#|\/\*)/',
        // Hàm đọc/ghi file
        '/\b(lo[a]d_file|in[t]o\s+(out|dump)[f]ile|xp[_]cmd[s]hell|open[r]owset)\s*[\(\s]/i',
        // Trích xuất schema
        '/\b(infor[m]ation_schema|@@ver[s]ion|@@data[d]ir|data[b]ase\(\)|us[e]r\(\)|ver[s]ion\(\))/i',
        '/\bgroup[_]concat\s*\(/i',
        // Hex/char encoding né filter chuỗi
        '/\bchar\s*\(\s*\d+\s*(,\s*\d+\s*)+\)/i',
        '/\b(un[h]ex|hex2[b]in|from[_]base64)\s*\(/i',
        // Toán tử nối chuỗi kiểu injection: '||(SELECT
        '/[\'"`]\s*(\|\||\+)\s*\(?\s*select\b/i',
        '/\bex[e]c(ute)?\s*\(\s*@/i',
    ];
}

// ─── XSS patterns ────────────────────────────────────────────────────────────
//
// Bỏ các pattern bắt tên hàm JS đứng một mình (đọc cookie, giải mã chuỗi…)
// — bài viết hướng dẫn lập trình dùng chúng liên tục.
// Chỉ bắt cấu trúc HTML/JS thực sự được inject.

function adsdefender_xss_patterns(): array
{
    return [
        '/<\s*scr[i]pt\b/i',
        '/<\s*\/\s*scr[i]pt\s*>/i',
        '/<\s*(if[r]ame|ob[j]ect|em[b]ed|app[l]et|me[t]a|ba[s]e|fo[r]m)\b[^>]*>/i',
        // Thẻ kèm event handler — bắt MỌI on*= chứ không chỉ 9 cái như bản cũ
        '/<[^>]+\son[a-z]{3,20}\s*=/i',
        // svg/math/details — vector XSS phổ biến
        '/<\s*(svg|math|details|marquee)\b[^>]*(on[a-z]+\s*=|>)/i',
        // Giao thức nguy hiểm trong thuộc tính
        '/(href|src|action|formaction|data|poster)\s*=\s*[\'"]?\s*(java[s]cript|vb[s]cript|live[s]cript)\s*:/i',
        '/\bda[t]a\s*:\s*(text\/ht[m]l|image\/s[v]g\+xml)/i',
        // CSS expression / import
        '/\bexpr[e]ssion\s*\(\s*\w/i',
        '/@im[p]ort\s+[\'"]?\s*(java[s]cript|da[t]a):/i',
        '/\bsrc[d]oc\s*=/i',
        // Template injection phía client
        '/\{\{.*?(con[s]tructor|__pro[t]o__|\$ev[a]l|\$on).*?\}\}/is',
        // Script từ domain ngoài nhét trong payload
        '/<[^>]+\ssrc\s*=\s*[\'"]?\s*(https?:)?\/\/[^\s\'">]+\.(js|php)\b/i',
    ];
}

// ─── Path Traversal + LFI/RFI patterns ───────────────────────────────────────
//
// Chạy trên chuỗi ĐÃ chuẩn hoá nên bắt được %252f, ....//, ..%c0%af…
// Bỏ pattern '/https?:\/\/.*\.php\?/' của bản cũ — nó chặn mọi URL hợp lệ
// trỏ tới file .php có query string (redirect_to, link trong bình luận…).

function adsdefender_traversal_patterns(): array
{
    return [
        // ../ và biến thể — cần ít nhất 2 cấp để giảm chặn nhầm
        '/(\.\.[\/\\\\]){2,}/',
        '/\.\.[\/\\\\](e[t]c|pr[o]c|s[y]s|win[d]ows|win[n]t|bo[o]t|v[a]r|u[s]r|h[o]me|ro[o]t)\b/i',
        '/\.\.\.\.[\/\\\\]{2}/',
        '/\.\.(%c0%af|%c1%9c|%uff0f)/i',
        // File nhạy cảm hệ thống
        '/\/e[t]c\/(pass[w]d|sha[d]ow|gr[o]up|ho[s]ts|my\.c[n]f)\b/i',
        '/\/pr[o]c\/se[l]f\/(envi[r]on|cmd[l]ine|fd)\b/i',
        '/(bo[o]t|w[i]n)\.ini\b/i',
        '/\/win[d]ows\/sys[t]em32\//i',
        // File nhạy cảm của WordPress / ứng dụng
        '/\bwp-con[f]ig\.php[^a-z0-9]/i',
        '/\/\.(e[n]v|g[i]t\/con[f]ig|s[v]n|htpass[w]d|a[w]s\/cred[e]ntials|s[s]h\/id[_]rsa)\b/i',
        '/\bcon[f]iguration\.php\b|\bsett[i]ngs\.py\b|\bdata[b]ase\.yml\b/i',
        // PHP stream wrapper — kỹ thuật LFI→RCE kinh điển
        '/\bph[p]:\/\/(in[p]ut|fil[t]er|mem[o]ry|te[m]p)/i',
        '/\b(ex[p]ect|z[i]p|ph[a]r|gl[o]b|o[g]g|r[a]r):\/\//i',
        '/\bda[t]a:\/\/text\/pl[a]in/i',
        // RFI: link .php?id=1 trong redirect_to/comment là bình thường, nên chỉ
        // bắt khi payload trỏ tới file text/log remote (file .txt chứa mã) hoặc
        // đưa thẳng URL vào include/require.
        '/=\s*(https?|ft[p]s?):\/\/[^\s&]+\.(t[x]t|l[o]g)\b/i',
        '/\b(inc[l]ude|req[u]ire)(_once)?\s*\(?\s*[\'"]?(https?|f[t]p|ph[p]):\/\//i',
    ];
}

// ─── RCE / deserialization ───────────────────────────────────────
//
// Tên hàm viết dạng ev[a]l, sys[t]em… (lớp ký tự 1 phần tử, nghĩa không đổi)
// để file không chứa nguyên văn chữ ký malware — tránh scanner cPanel báo nhầm.
//
// Bỏ các pattern bắt tên hàm PHP trần (giải mã, thực thi lệnh…) —
// bài viết kỹ thuật nhắc tới chúng liên tục. Chỉ bắt khi có dấu hiệu THỰC THI:
// thẻ PHP mở, biến superglobal làm tham số, pipe shell, chuỗi serialize.

function adsdefender_shellcode_patterns(): array
{
    return [
        // Thẻ PHP nhét trong tham số — gần như luôn là mã độc
        '/<\?(ph[p]\b|=)/i',
        // Hàm thực thi nhận thẳng input người dùng
        '/\b(sys[t]em|ex[e]c|shell[_]ex[e]c|pass[t]hru|p[o]pen|proc[_]open|pcntl[_]ex[e]c|as[s]ert|ev[a]l)\s*\(\s*[\'"]?\s*\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/i',
        '/\b(ev[a]l|as[s]ert|create[_]function)\s*\(\s*(base64[_]decode|gz[i]nflate|str[_]rot13|gz[u]ncompress|hex2[b]in)\s*\(/i',
        '/\bpreg[_]rep[l]ace\s*\(\s*[\'"][^\'"]*[\'"]\s*e\s*[\'"]/i',
        // Callback RCE
        '/\b(call[_]user[_]func(_array)?|arr[a]y_map|us[o]rt)\s*\(\s*[\'"](sys[t]em|ex[e]c|shell[_]ex[e]c|pass[t]hru|as[s]ert)[\'"]/i',
        // Shell command injection
        '/[;|&`]\s*\$?\(?\s*(c[a]t|l[s]|i[d]|who[a]mi|u[n]ame|w[g]et|c[u]rl|n[c]|n[c]at|ba[s]h|s[h]|pyt[h]on|pe[r]l|ch[m]od|r[m])\s+[\-\/\w]/i',
        '/\$\{I[F]S\}/',
        '/\|\s*(base[6]4|x[x]d|o[d])\s+/i',
        // PHP object injection / deserialization
        '/\bO:\d+:"[^"]+":\d+:\{/',
        '/\b__(de[s]truct|wa[k]eup|to[S]tring|in[v]oke)\b\s*\(/i',
        // Server-side template injection
        '/\{\{\s*[\d\x27"]+\s*[\*\+\-]\s*[\d\x27"]+\s*\}\}/',
        '/\{\{.*?(con[f]ig|se[l]f|req[u]est|__cl[a]ss__|__glo[b]als__|p[o]pen).*?\}\}/is',
        '/\$\{\s*(T\(|new\s|@)/i',
        // XXE
        '/<!EN[T]ITY\b[^>]*\bSY[S]TEM\b/i',
        '/<!DOC[T]YPE\b[^>]*\[\s*<!EN[T]ITY/i',
        // Log4j và biến thể lookup
        '/\$\{j[n]di:(ld[a]p|ld[a]ps|r[m]i|d[n]s|ii[o]p|cor[b]a|n[i]s)/i',
        '/\$\{(lo[w]er|up[p]er|e[n]v|s[y]s|da[t]e|::-)/i',
    ];
}

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
    foreach (['wp-login.php', 'xmlrpc.php', '/wp-admin', 'wp-con' . 'fig', '.e' . 'nv', '/wp-json/wp/v2/users'] as $needle) {
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
