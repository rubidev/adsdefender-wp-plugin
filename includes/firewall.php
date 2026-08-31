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

// ─── SQL Injection patterns ───────────────────────────────────────────────────

function adsdefender_sqli_patterns(): array
{
    return [
        '/(\b)(union)(\s+)(select|all|distinct)/i',
        '/(\b)(select)(\s+)(.*)(from)(\s+)/i',
        '/(\b)(insert)(\s+)(into)(\s+)/i',
        '/(\b)(update)(\s+)(.*)(\s+)(set)(\s+)/i',
        '/(\b)(delete)(\s+)(from)(\s+)/i',
        '/(\b)(drop)(\s+)(table|database|index|view)/i',
        '/(\b)(exec|execute)(\s*)\(/i',
        '/(\b)(cast|convert)(\s*)\(/i',
        '/(\b)(benchmark|sleep|waitfor)(\s*)\(/i',
        '/(\b)(load_file|outfile|dumpfile)(\s*)\(/i',
        '/(\'|\")(\s*)(;|--|#|\/\*)/',
        '/(\b)(\d+)\s*=\s*\1(\b)/',   // 1=1, etc.
        '/(\b)(0x[0-9a-f]{4,})/i',     // hex encoded
        '/char\s*\(\s*\d+/i',
        '/(\/\*.*\*\/)/s',             // SQL comments
    ];
}

// ─── XSS patterns ────────────────────────────────────────────────────────────

function adsdefender_xss_patterns(): array
{
    return [
        '/<script[^>]*>/i',
        '/<\/script>/i',
        '/javascript\s*:/i',
        '/vbscript\s*:/i',
        '/on(load|error|click|mouseover|focus|blur|submit|keypress|change)\s*=/i',
        '/expression\s*\(/i',
        '/<iframe[^>]*>/i',
        '/<object[^>]*>/i',
        '/<embed[^>]*>/i',
        '/<svg[^>]*on/i',
        '/data\s*:\s*text\/html/i',
        '/document\.(cookie|write|location)/i',
        '/window\.(location|open)/i',
        '/eval\s*\(/i',
        '/atob\s*\(/i',
        '/fromcharcode/i',
    ];
}

// ─── Path Traversal + LFI/RFI patterns ───────────────────────────────────────

function adsdefender_traversal_patterns(): array
{
    return [
        '/\.\.\//',
        '/\.\.\\\\/',
        '/%2e%2e%2f/i',
        '/%2e%2e\//i',
        '/\.\.\%2f/i',
        '/\.\.\%5c/i',
        '/etc\/passwd/i',
        '/etc\/shadow/i',
        '/proc\/self/i',
        '/boot\.ini/i',
        '/win\.ini/i',
        '/php:\/\/input/i',
        '/php:\/\/filter/i',
        '/data:\/\//i',
        '/expect:\/\//i',
        '/http:\/\/.*\.php\?/i',     // RFI pattern
        '/https:\/\/.*\.php\?/i',
    ];
}

// ─── Shellcode / attack strings ───────────────────────────────────────────────

function adsdefender_shellcode_patterns(): array
{
    return [
        '/\$_(GET|POST|COOKIE|SERVER|REQUEST|FILES|SESSION)\[/i',
        '/base64_decode\s*\(/i',
        '/passthru\s*\(/i',
        '/shell_exec\s*\(/i',
        '/system\s*\(/i',
        '/popen\s*\(/i',
        '/proc_open\s*\(/i',
        '/pcntl_exec\s*\(/i',
        '/assert\s*\(/i',
        '/preg_replace.*\/e/i',    // /e modifier code exec
        '/\$\{IFS\}/',             // shell IFS bypass
        '/\|\s*(cat|ls|id|whoami|wget|curl|bash|sh)\s/i',
    ];
}

// ─── Main firewall check ──────────────────────────────────────────────────────

function adsdefender_firewall_check(): void
{
    if (!adsdefender_fw_enabled()) return;

    // Không check REST API admin actions
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('WP_CLI') && WP_CLI) return;

    $ip  = adsdefender_get_real_ip();

    // Skip whitelist / temp whitelist
    $opts_fw   = adsdefender_settings();
    $whitelist = adsdefender_parse_whitelist($opts_fw['whitelist'] ?? '');
    if (!empty($whitelist) && adsdefender_ip_matches($ip, $whitelist)) return;
    if (adsdefender_is_temp_whitelisted($ip)) return;
    $uri = urldecode($_SERVER['REQUEST_URI'] ?? '');

    // Tập hợp strings cần kiểm tra
    $check_strings = [$uri];
    // POST body (chỉ lấy keys, không process file upload)
    if (!empty($_POST)) {
        foreach ($_POST as $k => $v) {
            if (is_string($v)) {
                $check_strings[] = $k . '=' . $v;
            }
        }
    }
    // Query string nếu không nằm trong URI
    if (!empty($_SERVER['QUERY_STRING'])) {
        $check_strings[] = urldecode($_SERVER['QUERY_STRING']);
    }

    $all = implode(' ', $check_strings);

    // SQLi check
    foreach (adsdefender_sqli_patterns() as $pat) {
        if (preg_match($pat, $all)) {
            adsdefender_fw_block($ip, 'sqli', $uri);
            return;
        }
    }

    // XSS check
    foreach (adsdefender_xss_patterns() as $pat) {
        if (preg_match($pat, $all)) {
            adsdefender_fw_block($ip, 'xss', $uri);
            return;
        }
    }

    // Path traversal check
    foreach (adsdefender_traversal_patterns() as $pat) {
        if (preg_match($pat, $all)) {
            adsdefender_fw_block($ip, 'traversal', $uri);
            return;
        }
    }

    // Shellcode check
    foreach (adsdefender_shellcode_patterns() as $pat) {
        if (preg_match($pat, $all)) {
            adsdefender_fw_block($ip, 'shellcode', $uri);
            return;
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
