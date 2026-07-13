<?php
/**
 * AdsDefender — Honeypot
 *
 * Thêm hidden field vào form login và comment.
 * Human sẽ không thấy field này (display:none + tabindex=-1).
 * Bot thường điền đầy đủ tất cả fields → điền vào honeypot → block ngay.
 *
 * Kỹ thuật: CSS-hidden field + tên ngẫu nhiên (tránh bot học patterns cố định)
 */
if (!defined('ABSPATH')) exit;

// ─── Settings Helper ─────────────────────────────────────────────────────────

function adsdefender_hp_enabled(): bool
{
    return !empty(adsdefender_settings()['hp_enabled']);
}

// ─── Honeypot field name (nhất quán trong 1 session) ─────────────────────────

function adsdefender_hp_field_name(): string
{
    // Dùng site-unique token thay vì random mỗi request — để POST validation khớp
    static $name = null;
    if (!$name) {
        $token = get_option('adsdefender_hp_token', '');
        if (!$token) {
            try { $token = bin2hex(random_bytes(8)); } catch (Exception $e) { $token = wp_generate_password(16, false); }
            update_option('adsdefender_hp_token', $token, false);
        }
        $name = 'hp_' . substr($token, 0, 8);
    }
    return $name;
}

// ─── Render honeypot field (CSS hidden) ──────────────────────────────────────

function adsdefender_hp_field_html(): string
{
    $name = adsdefender_hp_field_name();
    return '<div style="position:absolute;left:-9999px;top:-9999px;visibility:hidden" aria-hidden="true">'
         . '<label for="' . esc_attr($name) . '">Leave this field empty</label>'
         . '<input type="text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" '
         . 'value="" tabindex="-1" autocomplete="off">'
         . '</div>';
}

// ─── Validate honeypot: nếu field được điền → block ─────────────────────────

function adsdefender_hp_validate(): void
{
    if (!adsdefender_hp_enabled()) return;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $field = adsdefender_hp_field_name();
    // Nếu field tồn tại trong POST và có giá trị → bot
    if (isset($_POST[$field]) && $_POST[$field] !== '') {
        $ip = adsdefender_get_real_ip();
        if ($ip) {
            adsdefender_upsert_lockout($ip, 'honeypot');
            adsdefender_log_block($ip);
        }

        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        http_response_code(403);
        echo '403 Forbidden.';
        exit;
    }
}

// ─── Login form ───────────────────────────────────────────────────────────────

add_action('login_form', function () {
    if (adsdefender_hp_enabled()) {
        echo adsdefender_hp_field_html();
    }
});

add_action('login_init', function () {
    if (!adsdefender_hp_enabled()) return;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    adsdefender_hp_validate();
});

// ─── Comment form ─────────────────────────────────────────────────────────────

add_action('comment_form_after_fields', function () {
    if (adsdefender_hp_enabled()) {
        echo adsdefender_hp_field_html();
    }
});

add_action('pre_comment_on_post', function () {
    if (!adsdefender_hp_enabled()) return;
    adsdefender_hp_validate();
}, 1);

// ─── Registration form ────────────────────────────────────────────────────────

add_action('register_form', function () {
    if (adsdefender_hp_enabled()) {
        echo adsdefender_hp_field_html();
    }
});

add_filter('registration_errors', function (WP_Error $errors, string $sanitized_user_login, string $user_email) {
    if (!adsdefender_hp_enabled()) return $errors;

    $field = adsdefender_hp_field_name();
    if (isset($_POST[$field]) && $_POST[$field] !== '') {
        $ip = adsdefender_get_real_ip();
        if ($ip) adsdefender_upsert_lockout($ip, 'honeypot');
        $errors->add('hp_failed', '<strong>Lỗi:</strong> Đăng ký thất bại.');
    }
    return $errors;
}, 10, 3);
