<?php
/**
 * AdsDefender — Request Firewall
 *
 * Phát hiện và block các request độc hại:
 *  • SQL Injection (URL, POST, query string)
 *  • XSS (script injection)
 *  • Path traversal (../../../etc/passwd)
 *  • Bad User-Agent (scanner, bot độc hại — danh sách HackRepair)
 *  • Shellcode / RFI / LFI patterns
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
        'nuclei', 'hakrawler', 'gospider', 'crackmapexec',

        // ── Proxy tấn công / framework khai thác ──
        'burpsuite', 'burp collaborator', 'owasp zap', 'zaproxy',
        'metasploit', 'core impact',

        // ── Tool SQLi / exploit cũ nhưng vẫn gặp ──
        'havij', 'pangolin', 'jsky', 'absinthe', 'bsqlbf', 'sqlninja',

        // ── Bot khai thác lỗ hổng đã biết ──
        'zmeu', 'morfeus', 'zgrab', 'l9explore', 'l9tcpid',
        'xmrig', 'mozlila', 'chroot-apach0day',

        // ── Payload Log4Shell / RCE nhét thẳng vào UA ──
        '${jndi:', 'jndi:ldap', '${lower:', 'log4shell',

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
        '/\bselect\b.{0,60}?\bfrom\b\s+(information_schema|mysql\.|pg_|wp_users|wp_options)/is',
        // Câu lệnh ghi/xoá kèm ký tự cắt câu phía trước
        '/[\'"();]\s*\b(insert\s+into|delete\s+from|update\s+\w+\s+set|drop\s+(table|database)|truncate\s+table|alter\s+table)\b/is',
        // Boolean-based: \' OR \'1\'=\'1 , " OR 1=1-- , \') OR (1=1
        '/[\'"`]\s*\)?\s*\b(or|and|\|\||&&)\b\s*\(?\s*[\'"`]?\w+[\'"`]?\s*(=|<>|!=|like)\s*[\'"`]?\w+/i',
        '/\b(or|and)\b\s+\d+\s*(=|<>|!=)\s*\d+\s*(--|#|\/\*|;|$)/i',
        // Time-based blind
        '/\b(sleep|benchmark|pg_sleep|waitfor\s+delay|dbms_pipe\.receive_message)\s*\(/i',
        // Stacked query / comment cắt câu ngay sau dấu nháy
        '/[\'"`]\s*(;|--\s|--$|#|\/\*)/',
        // Hàm đọc/ghi file
        '/\b(load_file|into\s+(out|dump)file|xp_cmdshell|openrowset)\s*[\(\s]/i',
        // Trích xuất schema
        '/\b(information_schema|@@version|@@datadir|database\(\)|user\(\)|version\(\))/i',
        '/\bgroup_concat\s*\(/i',
        // Hex/char encoding né filter chuỗi
        '/\bchar\s*\(\s*\d+\s*(,\s*\d+\s*)+\)/i',
        '/\b(unhex|hex2bin|from_base64)\s*\(/i',
        // Toán tử nối chuỗi kiểu injection: '||(SELECT
        '/[\'"`]\s*(\|\||\+)\s*\(?\s*select\b/i',
        '/\bexec(ute)?\s*\(\s*@/i',
    ];
}

// ─── XSS patterns ────────────────────────────────────────────────────────────
//
// Bỏ các pattern bắt tên hàm JS đứng một mình (eval, document.cookie,
// atob, fromcharcode) — bài viết hướng dẫn lập trình dùng chúng liên tục.
// Chỉ bắt cấu trúc HTML/JS thực sự được inject.

function adsdefender_xss_patterns(): array
{
    return [
        '/<\s*script\b/i',
        '/<\s*\/\s*script\s*>/i',
        '/<\s*(iframe|object|embed|applet|meta|base|form)\b[^>]*>/i',
        // Thẻ kèm event handler — bắt MỌI on*= chứ không chỉ 9 cái như bản cũ
        '/<[^>]+\son[a-z]{3,20}\s*=/i',
        // svg/math/details — vector XSS phổ biến
        '/<\s*(svg|math|details|marquee)\b[^>]*(on[a-z]+\s*=|>)/i',
        // Giao thức nguy hiểm trong thuộc tính
        '/(href|src|action|formaction|data|poster)\s*=\s*[\'"]?\s*(javascript|vbscript|livescript)\s*:/i',
        '/\bdata\s*:\s*(text\/html|image\/svg\+xml)/i',
        // CSS expression / import
        '/\bexpression\s*\(\s*\w/i',
        '/@import\s+[\'"]?\s*(javascript|data):/i',
        '/\bsrcdoc\s*=/i',
        // Template injection phía client
        '/\{\{.*?(constructor|__proto__|\$eval|\$on).*?\}\}/is',
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
        '/\.\.[\/\\\\](etc|proc|sys|windows|winnt|boot|var|usr|home|root)\b/i',
        '/\.\.\.\.[\/\\\\]{2}/',
        '/\.\.(%c0%af|%c1%9c|%uff0f)/i',
        // File nhạy cảm hệ thống
        '/\/etc\/(passwd|shadow|group|hosts|my\.cnf)\b/i',
        '/\/proc\/self\/(environ|cmdline|fd)\b/i',
        '/(boot|win)\.ini\b/i',
        '/\/windows\/system32\//i',
        // File nhạy cảm của WordPress / ứng dụng
        '/\bwp-config\.php[^a-z0-9]/i',
        '/\/\.(env|git\/config|svn|htpasswd|aws\/credentials|ssh\/id_rsa)\b/i',
        '/\bconfiguration\.php\b|\bsettings\.py\b|\bdatabase\.yml\b/i',
        // PHP stream wrapper — kỹ thuật LFI→RCE kinh điển
        '/\bphp:\/\/(input|filter|memory|temp)/i',
        '/\b(expect|zip|phar|glob|ogg|rar):\/\//i',
        '/\bdata:\/\/text\/plain/i',
        // RFI: link .php?id=1 trong redirect_to/comment là bình thường, nên chỉ
        // bắt khi payload trỏ tới file text/log remote (kiểu shell.txt) hoặc
        // đưa thẳng URL vào include/require.
        '/=\s*(https?|ftps?):\/\/[^\s&]+\.(txt|log)\b/i',
        '/\b(include|require)(_once)?\s*\(?\s*[\'"]?(https?|ftp|php):\/\//i',
    ];
}

// ─── Shellcode / RCE / deserialization ───────────────────────────────────────
//
// Bỏ các pattern bắt tên hàm PHP trần (base64_decode, system, assert) —
// bài viết kỹ thuật nhắc tới chúng liên tục. Chỉ bắt khi có dấu hiệu THỰC THI:
// thẻ PHP mở, biến superglobal làm tham số, pipe shell, chuỗi serialize.

function adsdefender_shellcode_patterns(): array
{
    return [
        // Thẻ PHP nhét trong tham số — gần như luôn là webshell
        '/<\?(php\b|=)/i',
        // Hàm thực thi nhận thẳng input người dùng
        '/\b(system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec|assert|eval)\s*\(\s*[\'"]?\s*\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/i',
        '/\b(eval|assert|create_function)\s*\(\s*(base64_decode|gzinflate|str_rot13|gzuncompress|hex2bin)\s*\(/i',
        '/\bpreg_replace\s*\(\s*[\'"][^\'"]*[\'"]\s*e\s*[\'"]/i',
        // Callback RCE
        '/\b(call_user_func(_array)?|array_map|usort)\s*\(\s*[\'"](system|exec|shell_exec|passthru|assert)[\'"]/i',
        // Shell command injection
        '/[;|&`]\s*\$?\(?\s*(cat|ls|id|whoami|uname|wget|curl|nc|ncat|bash|sh|python|perl|chmod|rm)\s+[\-\/\w]/i',
        '/\$\{IFS\}/',
        '/\|\s*(base64|xxd|od)\s+/i',
        // PHP object injection / deserialization
        '/\bO:\d+:"[^"]+":\d+:\{/',
        '/\b__(destruct|wakeup|toString|invoke)\b\s*\(/i',
        // Server-side template injection
        '/\{\{\s*[\d\x27"]+\s*[\*\+\-]\s*[\d\x27"]+\s*\}\}/',
        '/\{\{.*?(config|self|request|__class__|__globals__|popen).*?\}\}/is',
        '/\$\{\s*(T\(|new\s|@)/i',
        // XXE
        '/<!ENTITY\b[^>]*\bSYSTEM\b/i',
        '/<!DOCTYPE\b[^>]*\[\s*<!ENTITY/i',
        // Log4Shell và biến thể JNDI
        '/\$\{jndi:(ldap|ldaps|rmi|dns|iiop|corba|nis)/i',
        '/\$\{(lower|upper|env|sys|date|::-)/i',
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
    // Header thường bị nhét payload (Log4Shell, SQLi qua X-Forwarded-For)
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
    foreach (['wp-login.php', 'xmlrpc.php', '/wp-admin', 'wp-config', '.env', '/wp-json/wp/v2/users'] as $needle) {
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

    // Ngay lập tức trả 403 — không qua WP để tránh tải nặng
    if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
    if (!defined('DONOTCACHEDB'))     define('DONOTCACHEDB', true);

    nocache_headers();
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden. Request blocked by AdsDefender Firewall.';
    exit;
}

// ─── Hook vào init priority 1 (cùng với check_ip) ────────────────────────────

add_action('init', function () {
    adsdefender_ua_check();
    adsdefender_firewall_check();
}, 1);
