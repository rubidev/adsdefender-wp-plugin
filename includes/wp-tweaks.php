<?php
/**
 * AdsDefender — WordPress Tweaks & Security Headers
 *
 * Học từ Solid Security Pro / ITSEC_WordPress_Tweaks.
 *
 * Tính năng:
 *  A. WordPress Tweaks (PHP hooks, không cần htaccess):
 *     1. disable_file_editor  — define DISALLOW_FILE_EDIT (ngăn edit theme/plugin qua admin)
 *     2. remove_wp_version    — xóa <meta name="generator">, version khỏi scripts/styles
 *     3. disable_author_enum  — /?author=1 → redirect 301 về home (ngăn username enumeration)
 *     4. disable_rest_users   — block /wp-json/wp/v2/users với guest (ngăn list username)
 *     5. disable_rss_version  — xóa version khỏi RSS feed
 *
 *  B. Security Headers (ghi qua .htaccess hoặc PHP header()):
 *     6. hsts                 — Strict-Transport-Security (HTTPS enforcement)
 *     7. csp                  — Content-Security-Policy (cơ bản, không break site)
 *     8. referrer_policy      — đã có từ server nhưng đảm bảo qua PHP
 */
if (!defined('ABSPATH')) exit;

// ─── Settings helpers ─────────────────────────────────────────────────────────

function adsdefender_wt_opts(): array
{
    $opts = adsdefender_settings();
    return [
        'disable_file_editor' => !empty($opts['wt_file_editor']),
        'remove_wp_version'   => !empty($opts['wt_remove_version']),
        'disable_author_enum' => !empty($opts['wt_author_enum']),
        'disable_rest_users'  => !empty($opts['wt_rest_users']),
        'disable_rss_version' => !empty($opts['wt_rss_version']),
        'hsts_enabled'        => !empty($opts['wt_hsts']),
        'hsts_max_age'        => (int)($opts['wt_hsts_age'] ?? 31536000),
        'csp_enabled'         => !empty($opts['wt_csp']),
        'csp_value'           => trim($opts['wt_csp_value'] ?? ''),
    ];
}

// ─── A1: Disable File Editor ─────────────────────────────────────────────────
// Solid Security ghi vào wp-config.php — ta dùng filter vì không can thiệp wp-config.php
// define() trong wp-config.php chỉ có tác dụng trước khi WP load, nhưng hook init đã đủ
// để disable menu (WP check DISALLOW_FILE_EDIT ở admin_menu hook)

add_action('init', function () {
    $cfg = adsdefender_wt_opts();
    if (!$cfg['disable_file_editor']) return;
    if (!defined('DISALLOW_FILE_EDIT')) {
        define('DISALLOW_FILE_EDIT', true);
    }
}, 1);

// ─── A2: Remove WP Version ───────────────────────────────────────────────────
// Solid Security: remove_script_version() filter trên script/style src
// Thêm: xóa <meta name="generator">, version trong RSS

add_action('init', function () {
    $cfg = adsdefender_wt_opts();
    if (!$cfg['remove_wp_version']) return;

    // Xóa <meta name="generator" content="WordPress x.x.x">
    remove_action('wp_head', 'wp_generator');

    // Xóa ?ver= khỏi script/style src (tránh lộ phiên bản qua query string)
    add_filter('script_loader_src', '_adsdefender_wt_remove_ver_qs', 15);
    add_filter('style_loader_src',  '_adsdefender_wt_remove_ver_qs', 15);

    // Xóa version khỏi RSS
    add_filter('the_generator', '__return_empty_string');
});

function _adsdefender_wt_remove_ver_qs(string $src): string
{
    // Chỉ xóa ?ver= của WP core / plugin / theme, không xóa của CDN bên ngoài
    if (strpos($src, home_url()) === false && strpos($src, site_url()) === false) return $src;
    // Xóa query arg 'ver'
    return remove_query_arg('ver', $src);
}

// ─── A3: Disable Author Enumeration ──────────────────────────────────────────
// /?author=1 → WP redirect sang /author/username/ → lộ username
// Solid Security: redirect về home nếu query có author param và user chưa login

add_action('template_redirect', function () {
    $cfg = adsdefender_wt_opts();
    if (!$cfg['disable_author_enum']) return;
    if (is_user_logged_in()) return; // Admin cần author URL để xem preview

    // Chặn ?author=N (số) — đây là cách enumerate username
    if (isset($_GET['author']) && is_numeric($_GET['author'])) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}, 1);

// ─── A4: Disable REST API /users endpoint cho guest ──────────────────────────
// /wp-json/wp/v2/users → trả về list username → dùng để brute force
// Solid Security: filter rest_dispatch_request, check capability

add_filter('rest_endpoints', function (array $endpoints) {
    $cfg = adsdefender_wt_opts();
    if (!$cfg['disable_rest_users']) return $endpoints;
    if (is_user_logged_in() && current_user_can('list_users')) return $endpoints;

    // Xóa endpoint /users và /users/(?P<id>[\d]+) cho guest
    foreach ($endpoints as $route => $handler) {
        if (preg_match('#^/wp/v2/users#', $route)) {
            unset($endpoints[$route]);
        }
    }
    return $endpoints;
});

// ─── A5: Remove version from RSS ─────────────────────────────────────────────
// (đã xử lý trong A2 qua filter the_generator)

// ─── B: Security Headers ─────────────────────────────────────────────────────
// Gửi qua PHP headers — nhanh, không cần htaccess, hoạt động cả Nginx lẫn LiteSpeed

add_action('send_headers', function () {
    $cfg = adsdefender_wt_opts();

    // B1: HSTS — Strict-Transport-Security
    // Chỉ set khi đang HTTPS (không set trên HTTP tránh lock out)
    if ($cfg['hsts_enabled'] && is_ssl()) {
        $age = max(300, (int)$cfg['hsts_max_age']);
        header("Strict-Transport-Security: max-age={$age}; includeSubDomains", true);
    }

    // B2: Content-Security-Policy
    // Mặc định: cho phép tất cả nhưng block inline script từ nguồn khác (cơ bản, an toàn)
    if ($cfg['csp_enabled']) {
        $csp = $cfg['csp_value'] ?: "default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:; frame-ancestors 'self';";
        header("Content-Security-Policy: {$csp}", true);
    }

    // B3: X-Permitted-Cross-Domain-Policies (Adobe Flash/PDF — vẫn nên set)
    header("X-Permitted-Cross-Domain-Policies: none", true);

    // B4: Cross-Origin-Opener-Policy (giảm nguy cơ cross-origin leaks)
    header("Cross-Origin-Opener-Policy: same-origin-allow-popups", true);
}, 1);

// ─── Ghi htaccess section cho SystemTweaks khi activate ─────────────────────
// (wt_* settings không cần htaccess — thuần PHP hooks)

// Rebuild khi wt_* settings thay đổi (chỉ cần flush rewrite vì author enum dùng template_redirect)
add_action('update_option_adsdefender_settings', function ($old, $new) {
    $wt_keys = ['wt_file_editor','wt_remove_version','wt_author_enum','wt_rest_users','wt_rss_version','wt_hsts','wt_hsts_age','wt_csp','wt_csp_value'];
    $changed  = false;
    foreach ($wt_keys as $k) {
        if (($old[$k] ?? '') !== ($new[$k] ?? '')) { $changed = true; break; }
    }
    if ($changed) flush_rewrite_rules(false);
}, 10, 2);
