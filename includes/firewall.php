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
// Tổng hợp từ HackRepair BadBots list + phổ biến trong thực tế VN

function adsdefender_bad_ua_patterns(): array
{
    return [
        // Security scanners
        'nikto', 'nmap', 'masscan', 'nessus', 'openvas', 'w3af', 'sqlmap',
        'dirbuster', 'dirb', 'gobuster', 'wfuzz', 'ffuf', 'nuclei', 'burpsuite',
        'acunetix', 'appscan', 'webinspect', 'netsparker', 'qualys', 'rapid7',
        'metasploit', 'havij', 'pangolin', 'jsky', 'absinthe',
        // Web scrapers / bots xấu
        'zmeu', 'morfeus', 'ZmEu', 'libwww-perl', 'python-requests/2.1',
        'curl/7.1', 'curl/7.2', 'curl/7.3', 'curl/7.4', 'curl/7.5',
        'curl/7.6', 'curl/7.7', 'curl/7.8', 'curl/7.9',
        'lwp-trivial', 'lwp-request', 'LWP', 'wget/', 'urllib',
        'go-http-client/1.1', 'go-http-client/2.0',
        // DDoS / click fraud tools
        'masscan', 'zgrab', 'shodan', 'censys', 'internetmeasurement',
        'MJ12bot', 'SemrushBot', 'DotBot', 'BLEXBot',
        'PetalBot', 'DataForSeoBot', 'AhrefsBot',
        // WordPress-specific attack bots
        'WPScan', 'wordpress_exploit',
        // Shell / exploit strings in UA
        '/bin/sh', '/bin/bash', 'cmd.exe', 'wget http', 'curl http',
        // Empty UA or known bad patterns
        'Java/', 'Baiduspider', 'YandexBot',
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

    // Skip whitelist
    $ip_ua = adsdefender_get_real_ip();
    $opts_ua = adsdefender_settings();
    $wl_ua = adsdefender_parse_whitelist($opts_ua['whitelist'] ?? '');
    if (!empty($wl_ua) && adsdefender_ip_matches($ip_ua, $wl_ua)) return;
    if (adsdefender_is_temp_whitelisted($ip_ua)) return;

    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') {
        // Không có UA → thường là scanner, bot
        $ip = adsdefender_get_real_ip();
        adsdefender_fw_block($ip, 'empty_ua', $_SERVER['REQUEST_URI'] ?? '/');
        return;
    }

    $ip = adsdefender_get_real_ip();
    foreach (adsdefender_bad_ua_patterns() as $bad) {
        if (str_contains($ua, strtolower($bad))) {
            adsdefender_fw_block($ip, 'bad_ua:' . $bad, $_SERVER['REQUEST_URI'] ?? '/');
            return;
        }
    }
}

function adsdefender_fw_block(string $ip, string $reason, string $uri): void
{
    // IP trong whitelist → không block, không ghi lockout
    if ($ip) {
        $opts = adsdefender_settings();
        $whitelist = adsdefender_parse_whitelist($opts['whitelist'] ?? '');
        if (!empty($whitelist) && adsdefender_ip_matches($ip, $whitelist)) return;
        if (adsdefender_is_temp_whitelisted($ip)) return;
    }

    // Ghi lockout
    if ($ip) {
        adsdefender_upsert_lockout($ip, 'firewall');
        adsdefender_log_block($ip);
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
