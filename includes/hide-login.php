<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * AdsDefender — Hide Login URL
 *
 * Trang đăng nhập chỉ mở ở URL ẩn (/<slug>/). Người CHƯA đăng nhập:
 *  • /wp-login.php → 404. Chỉ cho qua: form trang có mật khẩu (postpass), link đặt lại
 *    mật khẩu có key HỢP LỆ, và POST đăng nhập từ trình duyệt đã mở URL ẩn (cookie 1 giờ)
 *  • Mọi trang trong /wp-admin/ → 404 (trừ admin-ajax.php và admin-post.php mà form /
 *    AJAX ngoài website cần). KHÔNG chuyển hướng về URL ẩn kể cả khi có cookie.
 *  • wp-signup.php / wp-activate.php (site đơn) → 404
 *  • Lưới an toàn: MỌI chuyển hướng trỏ tới URL ẩn phát sinh từ request không phải URL ẩn
 *    (wp-register.php, link đặt lại mật khẩu hỏng, auth_redirect…) → 404
 * Người đã đăng nhập: /wp-login.php và URL ẩn không kèm action → vào thẳng trang quản trị.
 *
 * Nhận diện request theo cách WordPress đã xác định (hằng WP_ADMIN, $pagenow — đã chữ
 * thường), KHÔNG so chuỗi URL: /WP-ADMIN/, //wp-admin/, %77p-login.php… không lọt được.
 * Không áp dụng cho WP-CLI, cron, REST API, XML-RPC.
 *
 * Giới hạn: wp-admin/install.php, upgrade.php chạy khi WordPress KHÔNG nạp plugin nên không
 * chặn được ở đây (chỉ hiện thông báo của WordPress, không có form đăng nhập).
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

// ─── Phản hồi 404 ────────────────────────────────────────────────────────────

/** 404 tại chỗ như trang không tồn tại — không chuyển hướng (không lộ có trang đăng nhập ẩn) */
function adsdefender_hl_404(): void
{
    adsdefender_block_response('not_found', 404);
    exit;
}

/** Giữ cho code cũ gọi tới: chặn trừ khi đã đăng nhập / có cookie URL ẩn */
function adsdefender_hl_block_access(): void
{
    if (is_user_logged_in() || adsdefender_hl_is_validated()) return;
    adsdefender_hl_404();
}

/** Request hiện tại có phải URL ẩn (/<slug> hoặc /<slug>/…) không. Không phân biệt hoa thường. */
function adsdefender_hl_is_slug_request(): bool
{
    static $r = null;
    if ($r !== null) return $r;
    $slug = strtolower(adsdefender_hl_slug());
    if ($slug === '') return $r = false;
    $path  = rawurldecode(adsdefender_hl_get_request_path());
    $first = strtolower(explode('/', trim(preg_replace('#/+#', '/', $path), '/'))[0]);
    return $r = ($first === $slug);
}

/** Link đặt lại mật khẩu có key hợp lệ (trên URL hoặc trong cookie wp-resetpass của WordPress) */
function adsdefender_hl_valid_reset_key(): bool
{
    $key = (string) ($_REQUEST['key'] ?? ''); $login = (string) ($_REQUEST['login'] ?? '');
    $ck  = 'wp-resetpass-' . COOKIEHASH;
    if (($key === '' || $login === '') && !empty($_COOKIE[$ck]) && strpos((string) $_COOKIE[$ck], ':') !== false) {
        [$login, $key] = explode(':', wp_unslash((string) $_COOKIE[$ck]), 2);
    }
    if ($key === '' || $login === '') return false;
    return !is_wp_error(check_password_reset_key($key, $login));
}

// ─── Serve wp-login.php tại slug — không redirect, không lộ URL ──────────────
//
// Giả mạo $_SERVER để wp-login.php nghĩ nó đang được gọi trực tiếp.
// wp-login.php dùng REQUEST_URI và PHP_SELF để build form action URL —
// cần giữ nguyên hoặc map về /wp-login.php.

function adsdefender_hl_serve_login(): void
{
    // Đã đăng nhập mở URL ẩn không kèm action → vào thẳng trang quản trị
    $action = (string) ($_REQUEST['action'] ?? '');
    if (is_user_logged_in() && ($action === '' || $action === 'login') && empty($_REQUEST['interim-login'])
        && empty($_REQUEST['reauth']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        wp_safe_redirect(admin_url());
        exit;
    }

    // Cookie đánh dấu trình duyệt đã mở URL ẩn (cho POST đăng nhập gửi thẳng vào wp-login.php)
    adsdefender_hl_set_cookie();

    $wp_login_path = '/wp-login.php';
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $_SERVER['REQUEST_URI'] = $qs ? $wp_login_path . '?' . $qs : $wp_login_path;
    $_SERVER['PHP_SELF']    = $wp_login_path;
    $_SERVER['SCRIPT_NAME'] = $wp_login_path;
    unset($_SERVER['PATH_INFO']);   // gây lỗi ở wp-login.php

    // wp-login.php khai báo biến ở global scope; require trong hàm → phải khai báo global trước
    global $user_login, $error, $errors, $interim_login, $action, $user_email,
           $redirect_to, $rememberme, $customize_login, $aria_describedby;
    $GLOBALS['pagenow'] = 'wp-login.php';
    require ABSPATH . 'wp-login.php';
    exit;
}

// ─── Main: setup_theme hook ───────────────────────────────────────────────────

add_action('setup_theme', function () {
    if (!adsdefender_hl_enabled() || adsdefender_hl_slug() === '') return;
    if (defined('WP_CLI')         && WP_CLI)         return;
    if (defined('DOING_CRON')     && DOING_CRON)     return;
    if (defined('REST_REQUEST')   && REST_REQUEST)   return;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;

    // 1. URL ẩn → phục vụ trang đăng nhập tại chỗ
    if (adsdefender_hl_is_slug_request()) { adsdefender_hl_serve_login(); return; }

    $page   = strtolower((string) ($GLOBALS['pagenow'] ?? ''));
    $logged = is_user_logged_in();

    // 2. Khu vực quản trị — WP_ADMIN do chính file trong /wp-admin/ đặt nên không lách được
    //    bằng chữ hoa / // / mã hoá URL. Chưa đăng nhập → 404, kể cả có cookie URL ẩn (trước
    //    đây có cookie thì WordPress chuyển hướng /wp-admin/ về URL ẩn → tưởng chưa chặn).
    if (defined('WP_ADMIN') && WP_ADMIN) {
        if ($logged) return;
        if (wp_doing_ajax() || $page === 'admin-post.php') return;   // AJAX / form ngoài website
        adsdefender_hl_404();
    }

    // 3. wp-login.php
    if ($page === 'wp-login.php') { adsdefender_hl_handle_login_page(); return; }

    // 4. Đăng ký kiểu multisite trên site đơn → WordPress chuyển hướng về trang đăng ký = URL ẩn
    if (!$logged && !is_multisite() && in_array($page, ['wp-signup.php', 'wp-activate.php'], true)) adsdefender_hl_404();
}, 10);

function adsdefender_hl_handle_login_page(): void
{
    $action = (string) ($_REQUEST['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Đã đăng nhập: không action → vào trang quản trị; có action (logout…) → để WordPress xử lý
    if (is_user_logged_in()) {
        if ($method === 'GET' && ($action === '' || $action === 'login') && empty($_REQUEST['interim-login'])) {
            wp_safe_redirect(admin_url());
            exit;
        }
        return;
    }

    // Form trang có mật khẩu — dành cho mọi khách, URL này WordPress giữ nguyên /wp-login.php
    if ($action === 'postpass') return;

    // Link đặt lại mật khẩu (email gửi trước khi bật Hide Login): chỉ khi key còn hợp lệ.
    // Key sai / thiếu → WordPress chuyển về form quên mật khẩu = URL ẩn → lộ slug → 404.
    if (in_array($action, ['rp', 'resetpass'], true) && adsdefender_hl_valid_reset_key()) return;

    // POST đăng nhập từ trình duyệt đã mở URL ẩn (cookie) — form tự viết gửi thẳng vào wp-login.php
    if ($method === 'POST' && ($action === '' || $action === 'login') && adsdefender_hl_is_validated()) return;

    if ($method === 'POST' && function_exists('adsdefender_sec_log')) {
        adsdefender_sec_log('brute_force', adsdefender_get_real_ip(),
            'hide_login | POST wp-login.php không qua URL ẩn | user=' . substr(sanitize_user(wp_unslash((string) ($_POST['log'] ?? ''))), 0, 60));
    }
    // Mọi trường hợp khác (form đăng nhập, quên mật khẩu, logout giả, đăng ký…) → 404
    adsdefender_hl_404();
}

// ─── Lưới an toàn: không chuyển hướng người lạ tới URL ẩn ────────────────────
//
// WordPress có nhiều chỗ tự chuyển hướng về trang đăng nhập / đăng ký (wp-register.php,
// wp-signup.php, auth_redirect, key đặt lại mật khẩu hỏng…) — mà URL đó đã bị đổi thành
// URL ẩn. Người chưa đăng nhập, request không phải URL ẩn → thay chuyển hướng bằng 404.

add_filter('wp_redirect', function ($location) {
    if (!is_string($location) || !adsdefender_hl_enabled()) return $location;
    $slug = adsdefender_hl_slug();
    if ($slug === '' || adsdefender_hl_is_slug_request() || is_user_logged_in()) return $location;
    if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return $location;
    $path = strtolower(rawurldecode((string) parse_url($location, PHP_URL_PATH)));
    $home = strtolower(rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/'));
    if ($path === $home . '/' . strtolower($slug) || str_starts_with($path, $home . '/' . strtolower($slug) . '/')) {
        adsdefender_hl_404();
    }
    return $location;
}, PHP_INT_MAX);

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
