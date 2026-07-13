<?php
/**
 * AdsDefender — Hide Login URL
 *
 * Học từ Solid Security Pro / iThemes Security hide-backend module.
 *
 * CƠ CHẾ (giống Solid Security):
 *  1. Hook setup_theme — sớm hơn init, template_redirect
 *  2. Token = chính slug (plain, không cần encrypt)
 *  3. /custom-slug → redirect /wp-login.php?ads-hb-token=slug + set cookie 1h
 *  4. /wp-login.php GET trực tiếp → check token query param hoặc cookie
 *     Nếu không có → block (404 hoặc redirect)
 *  5. is_user_logged_in() → luôn cho qua
 *  6. Bỏ WP built-in wp_redirect_admin_locations (sẽ gây loop)
 *  7. Skip REST API, WP-CLI, cron
 */
if (!defined('ABSPATH')) exit;

// ─── Settings helpers ────────────────────────────────────────────────────────

function adsdefender_hl_enabled(): bool
{
    return !empty(adsdefender_settings()['hl_enabled']);
}

function adsdefender_hl_slug(): string
{
    $slug = trim(adsdefender_settings()['hl_slug'] ?? '');
    return $slug ?: '';
}

function adsdefender_xmlrpc_disabled(): bool
{
    return !empty(adsdefender_settings()['xmlrpc_disabled']);
}

// Token = slug (pattern của Solid Security)
function adsdefender_hl_token(): string
{
    return adsdefender_hl_slug();
}

function adsdefender_hl_token_var(): string
{
    return 'ads-hb-token';
}

function adsdefender_hl_cookie_name(): string
{
    return 'ads-hb-login-' . COOKIEHASH;
}

// ─── Disable XML-RPC ─────────────────────────────────────────────────────────

add_filter('xmlrpc_enabled', function ($enabled) {
    if (adsdefender_xmlrpc_disabled()) return false;
    return $enabled;
});

add_action('setup_theme', function () {
    if (!adsdefender_xmlrpc_disabled()) return;
    $path = adsdefender_hl_get_request_path();
    if ($path === 'xmlrpc.php') {
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'XML-RPC disabled.';
        exit;
    }
}, 5);

// ─── Helper: lấy request path (first segment, không có slash đầu) ───────────

function adsdefender_hl_get_request_path(): string
{
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = ltrim(parse_url($uri, PHP_URL_PATH) ?? '/', '/');
    // Bỏ home path prefix nếu WP không ở root (subdir install)
    $home_path = ltrim(parse_url(home_url(), PHP_URL_PATH) ?? '/', '/');
    if ($home_path && strpos($path, $home_path) === 0) {
        $path = ltrim(substr($path, strlen($home_path)), '/');
    }
    return $path;
}

// ─── Cookie: set + validate ───────────────────────────────────────────────────

function adsdefender_hl_set_cookie(): void
{
    $token   = adsdefender_hl_token();
    $expires = time() + 3600; // 1 giờ — giống Solid Security
    $path    = defined('COOKIEPATH')    ? COOKIEPATH    : '/';
    $domain  = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
    setcookie(adsdefender_hl_cookie_name(), $token, $expires, $path, $domain, is_ssl(), true);
}

function adsdefender_hl_is_validated(): bool
{
    $token    = adsdefender_hl_token();
    $tok_var  = adsdefender_hl_token_var();
    $cook     = adsdefender_hl_cookie_name();

    // Query param match → set cookie + return true (renew cookie)
    if (!empty($_REQUEST[$tok_var]) && hash_equals($token, (string) $_REQUEST[$tok_var])) {
        adsdefender_hl_set_cookie();
        return true;
    }
    // Cookie match
    if (!empty($_COOKIE[$cook]) && hash_equals($token, (string) $_COOKIE[$cook])) {
        return true;
    }
    return false;
}

// ─── Block access ─────────────────────────────────────────────────────────────

function adsdefender_hl_block_access(): void
{
    // User đã login → luôn cho qua
    if (is_user_logged_in()) return;

    // Có valid token/cookie → cho qua
    if (adsdefender_hl_is_validated()) return;

    // Redirect về trang blocked (giống block_visitor trong core.php)
    $opts     = adsdefender_settings();
    $lock_url = trim($opts['lock_url'] ?? '/adsdefender-blocked.html');
    if (empty($lock_url)) $lock_url = '/adsdefender-blocked.html';

    nocache_headers();
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

    // Nếu là file HTML tĩnh → redirect về đó (tránh loop)
    $current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($current !== $lock_url) {
        header('Location: ' . $lock_url, true, 302);
        exit;
    }

    // Đang ở chính trang lock_url mà vẫn gọi block → trả 403 fallback
    status_header(403);
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1></body></html>';
    exit;
}

// ─── Serve wp-login.php tại slug — không redirect, không lộ URL ──────────────
//
// Giả mạo $_SERVER để wp-login.php nghĩ nó đang được gọi trực tiếp.
// wp-login.php dùng REQUEST_URI và PHP_SELF để build form action URL —
// cần giữ nguyên hoặc map về /wp-login.php.
//
// KHÔNG dùng require_once trong hook (gây undefined var) — dùng ob_start +
// require trong global scope sau khi WP đã init xong constants.

function adsdefender_hl_serve_login(): void
{
    // Set cookie để validate trong request này và request kế tiếp
    adsdefender_hl_set_cookie();

    // Fake SERVER vars để wp-login.php build đúng form action và URLs
    $wp_login_path = '/wp-login.php';
    $qs = $_SERVER['QUERY_STRING'] ?? '';

    $_SERVER['REQUEST_URI'] = $qs ? $wp_login_path . '?' . $qs : $wp_login_path;
    $_SERVER['PHP_SELF']    = $wp_login_path;
    $_SERVER['SCRIPT_NAME'] = $wp_login_path;

    // Xóa PATH_INFO nếu có (gây lỗi dòng 518-519 wp-login.php)
    unset($_SERVER['PATH_INFO']);

    // wp-login.php khai báo $user_login, $error ở global scope.
    // require trong function → PHP tạo scope mới → undefined variable warning.
    // Fix: khai báo global trước khi require để các biến wp-login.php ghi vào
    // đều visible ở scope đó (tương đương global scope với declare global).
    global $user_login, $error, $errors, $interim_login, $action, $user_email,
           $redirect_to, $rememberme, $customize_login, $aria_describedby;

    // Load wp-login.php — nó sẽ tự định nghĩa biến và exit sau khi render
    require ABSPATH . 'wp-login.php';
    exit;
}

// ─── Main: setup_theme hook ───────────────────────────────────────────────────

add_action('setup_theme', function () {
    if (!adsdefender_hl_enabled()) return;

    $slug = adsdefender_hl_slug();
    if (!$slug) return;

    // Skip REST API, WP-CLI, cron, AJAX
    if (defined('WP_CLI')       && WP_CLI)       return;
    if (defined('DOING_CRON')   && DOING_CRON)   return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;
    if (wp_doing_ajax()) return;

    // Lấy first path segment
    $full_path = adsdefender_hl_get_request_path();
    // First segment (vd: "rubi-admin" từ "rubi-admin/something")
    $first = explode('/', $full_path)[0];
    // Script filename segment (cho trường hợp PHP chạy qua FastCGI)
    $script = ltrim(substr($_SERVER['SCRIPT_FILENAME'] ?? '', strlen(ABSPATH ?? '')), '/');

    foreach (array_unique([$first, urldecode($first), $script]) as $path) {
        $path = trim($path, '/');

        // Case 1: Custom slug → serve wp-login.php trực tiếp, KHÔNG redirect
        if ($path === $slug) {
            adsdefender_hl_serve_login();
            return;
        }

        // Case 2: wp-login.php trực tiếp → chỉ cho qua nếu validated
        if ($path === 'wp-login.php' || $path === 'wp-login') {
            adsdefender_hl_handle_login_page();
            return;
        }

        // Case 3: wp-admin → 404 nếu chưa login (không redirect để không lộ slug)
        if ($path === 'wp-admin' || strpos($full_path, 'wp-admin/') === 0) {
            if (!is_user_logged_in()) {
                adsdefender_hl_block_access();
                return;
            }
            return; // Đã login → WP tự xử lý
        }
    }
}, 10);

function adsdefender_hl_handle_login_page(): void
{
    $action = $_REQUEST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // POST → luôn cho qua (login submit, password reset submit, postpass)
    if ($method === 'POST') return;

    // User đã login → cho qua
    if (is_user_logged_in()) return;

    // Có valid token hoặc cookie → cho qua
    if (adsdefender_hl_is_validated()) return;

    // Actions WordPress cần wp-login.php trực tiếp không qua slug
    $allowed_actions = ['logout', 'rp', 'resetpass', 'validateresetkey', 'lostpassword', 'postpass'];
    if (in_array($action, $allowed_actions, true)) {
        // logout cần nonce hợp lệ
        if ($action === 'logout' && empty($_GET['_wpnonce'])) {
            adsdefender_hl_block_access();
            return;
        }
        return; // Cho qua
    }

    // Mọi GET khác → block
    adsdefender_hl_block_access();
}

// ─── Bỏ WP built-in redirect admin locations (giống Solid Security) ──────────
// WP core có wp_redirect_admin_locations() ở template_redirect priority 1000.
// Nó redirect /wp-admin và /wp-login về đúng URL.
// Plugin có cơ chế riêng sớm hơn → bỏ cái của WP để tránh conflict.

add_action('init', function () {
    remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
}, 0);

// ─── Filter URL generation để thêm token vào links ───────────────────────────
// Giống Solid Security: filter site_url + network_site_url để link wp-login.php
// trong HTML luôn có token → user click link nào cũng pass validation.

// ─── Filter site_url: wp-login.php links trong HTML → trỏ về slug ────────────
// Form action, "Back to login" links, lost password links... đều trỏ về /rubi-admin/
// để browser không bao giờ thấy /wp-login.php trong source page

function _adsdefender_hl_mask_login_url(string $url, string $path): string
{
    if (!adsdefender_hl_enabled()) return $url;
    $slug = adsdefender_hl_slug();
    if (!$slug) return $url;

    $clean = explode('?', $path)[0];
    if ($clean !== 'wp-login.php') return $url;

    // postpass không đổi (password-protected posts)
    if (strpos($url, 'action=postpass') !== false) return $url;

    // Đổi domain/path giữ nguyên query string
    $qs = parse_url($url, PHP_URL_QUERY);
    $slug_url = home_url('/' . $slug . '/');
    return $qs ? $slug_url . '?' . $qs : $slug_url;
}

add_filter('site_url',         '_adsdefender_hl_mask_login_url', 100, 2);
add_filter('network_site_url', '_adsdefender_hl_mask_login_url', 100, 2);

// ─── Login URL → trỏ về custom slug ─────────────────────────────────────────
// Links "Login" trong frontend trỏ về /slug thay vì /wp-login.php

add_filter('login_url', function (string $url, string $redirect, bool $force_reauth) {
    if (!adsdefender_hl_enabled()) return $url;
    $slug = adsdefender_hl_slug();
    if (!$slug) return $url;

    $login = home_url('/' . $slug . '/');
    if ($redirect) {
        $login = add_query_arg('redirect_to', urlencode($redirect), $login);
    }
    if ($force_reauth) {
        $login = add_query_arg('reauth', '1', $login);
    }
    return $login;
}, 10, 3);

// ─── Flush rewrite rules khi thay đổi settings ───────────────────────────────

function adsdefender_hl_flush_rules(): void
{
    flush_rewrite_rules(false);
}

add_action('update_option_adsdefender_settings', 'adsdefender_hl_flush_rules');
