<?php
/**
 * AdsDefender — CAPTCHA Protection
 *
 * Hỗ trợ 3 provider:
 *  • Google reCAPTCHA v2 — checkbox "I'm not a robot"
 *  • Google reCAPTCHA v3 — invisible, score-based (không cần tương tác)
 *  • Cloudflare Turnstile — widget nhẹ, không tracking, GDPR-friendly
 *
 * Tích hợp: login / lost-password / register / comment forms.
 * Pattern học từ Solid Security Pro (ITSEC_Recaptcha + Provider/CloudFlare.php).
 */
if (!defined('ABSPATH')) exit;

// ─── Settings helpers ────────────────────────────────────────────────────────

function adsdefender_rc_enabled(): bool
{
    return !empty(adsdefender_settings()['rc_enabled']);
}

function adsdefender_rc_opts(): array
{
    $opts = adsdefender_settings();
    return [
        'provider'     => $opts['rc_provider']      ?? 'google_v2',  // google_v2|google_v3|turnstile
        'site_key'     => trim($opts['rc_site_key']    ?? ''),
        'secret_key'   => trim($opts['rc_secret_key']  ?? ''),
        'v3_threshold' => min(1.0, max(0.1, (float)($opts['rc_v3_threshold'] ?? 0.5))),
        'on_login'     => !empty($opts['rc_on_login']),
        'on_lostpass'  => !empty($opts['rc_on_lostpass']),
        'on_register'  => !empty($opts['rc_on_register']),
        'on_comment'   => !empty($opts['rc_on_comment']),
    ];
}

// ─── SDK URL theo provider ────────────────────────────────────────────────────

function adsdefender_rc_sdk_url(): string
{
    $cfg = adsdefender_rc_opts();
    switch ($cfg['provider']) {
        case 'turnstile':
            return 'https://challenges.cloudflare.com/turnstile/v0/api.js';
        case 'google_v3':
            return 'https://www.google.com/recaptcha/api.js?render=' . urlencode($cfg['site_key']);
        default: // google_v2
            return 'https://www.google.com/recaptcha/api.js';
    }
}

// ─── Enqueue scripts ──────────────────────────────────────────────────────────

function adsdefender_rc_enqueue(): void
{
    if (!adsdefender_rc_enabled()) return;
    $cfg = adsdefender_rc_opts();
    if (empty($cfg['site_key'])) return;

    wp_enqueue_script('adsdefender-captcha-sdk', adsdefender_rc_sdk_url(), [], null, false);

    // v3: inject inline JS để tự lấy token trước khi submit
    if ($cfg['provider'] === 'google_v3') {
        add_action('login_footer', '_adsdefender_rc_v3_script');
        add_action('wp_footer',    '_adsdefender_rc_v3_script');
    }
}

function _adsdefender_rc_v3_script(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $site_key = esc_js(adsdefender_rc_opts()['site_key']);
    echo '<script>
(function(){
    function adsRcReady(fn){ if(typeof grecaptcha!=="undefined"&&grecaptcha.ready){ grecaptcha.ready(fn); } else { setTimeout(function(){ adsRcReady(fn); }, 100); } }
    function adsRcTokenize(form, action){
        adsRcReady(function(){
            grecaptcha.execute("' . $site_key . '",{action:action}).then(function(t){
                var i=form.querySelector(\'[name="ads_rc_token"]\');
                if(!i){i=document.createElement("input");i.type="hidden";i.name="ads_rc_token";form.appendChild(i);}
                i.value=t;
            });
        });
    }
    document.addEventListener("DOMContentLoaded",function(){
        var map={loginform:"login",lostpasswordform:"lostpassword",registerform:"register",commentform:"comment"};
        Object.keys(map).forEach(function(id){
            var f=document.getElementById(id);
            if(!f) return;
            adsRcTokenize(f, map[id]);
            f.addEventListener("submit",function(){ adsRcTokenize(f, map[id]); });
        });
    });
})();
</script>';
}

// ─── Widget HTML ──────────────────────────────────────────────────────────────

function adsdefender_rc_widget(string $action = ''): void
{
    if (!adsdefender_rc_enabled()) return;
    $cfg = adsdefender_rc_opts();
    if (empty($cfg['site_key'])) return;

    $key = esc_attr($cfg['site_key']);
    echo '<div class="ads-captcha-wrap" style="margin:10px 0;">';

    switch ($cfg['provider']) {
        case 'turnstile':
            // Cloudflare Turnstile: widget explicit render, response field = cf-turnstile-response
            echo '<div class="cf-turnstile" data-sitekey="' . $key . '"'
               . ($action ? ' data-action="' . esc_attr($action) . '"' : '') . '></div>';
            break;

        case 'google_v2':
            echo '<div class="g-recaptcha" data-sitekey="' . $key . '"></div>';
            break;

        case 'google_v3':
            // v3 invisible — không có widget HTML, chỉ cần hidden input (JS tự điền)
            echo '<input type="hidden" name="ads_rc_token" value="">';
            if ($action) echo '<input type="hidden" name="ads_rc_action" value="' . esc_attr($action) . '">';
            break;
    }

    echo '</div>';
}

// ─── Verify token ─────────────────────────────────────────────────────────────

function adsdefender_rc_verify_post(string $action = ''): bool
{
    if (!adsdefender_rc_enabled()) return true;
    $cfg = adsdefender_rc_opts();
    if (empty($cfg['site_key']) || empty($cfg['secret_key'])) return true; // chưa cấu hình → pass

    switch ($cfg['provider']) {
        case 'turnstile':
            return _adsdefender_rc_verify_turnstile($cfg, $action);
        case 'google_v3':
            return _adsdefender_rc_verify_google_v3($cfg, $action);
        default:
            return _adsdefender_rc_verify_google_v2($cfg);
    }
}

function _adsdefender_rc_verify_google_v2(array $cfg): bool
{
    $token = sanitize_text_field($_POST['g-recaptcha-response'] ?? '');
    if (empty($token)) return false;

    $res = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'timeout' => 10,
        'body'    => ['secret' => $cfg['secret_key'], 'response' => $token, 'remoteip' => adsdefender_get_real_ip()],
    ]);
    if (is_wp_error($res)) return true; // timeout → pass
    $body = json_decode(wp_remote_retrieve_body($res), true);
    return !empty($body['success']);
}

function _adsdefender_rc_verify_google_v3(array $cfg, string $action): bool
{
    $token = sanitize_text_field($_POST['ads_rc_token'] ?? '');
    if (empty($token)) return false;

    $res = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'timeout' => 10,
        'body'    => ['secret' => $cfg['secret_key'], 'response' => $token, 'remoteip' => adsdefender_get_real_ip()],
    ]);
    if (is_wp_error($res)) return true;
    $body = json_decode(wp_remote_retrieve_body($res), true);
    if (empty($body['success'])) return false;

    $score = (float)($body['score'] ?? 0);
    if ($score < $cfg['v3_threshold']) return false;
    if ($action && isset($body['action']) && $body['action'] !== $action) return false;
    return true;
}

function _adsdefender_rc_verify_turnstile(array $cfg, string $action): bool
{
    $token = sanitize_text_field($_POST['cf-turnstile-response'] ?? '');
    if (empty($token)) return false;

    $res = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 10,
        'body'    => ['secret' => $cfg['secret_key'], 'response' => $token, 'remoteip' => adsdefender_get_real_ip()],
    ]);
    if (is_wp_error($res)) return true; // Cloudflare timeout → pass
    $body = json_decode(wp_remote_retrieve_body($res), true);
    if (empty($body['success'])) return false;

    // Validate action khớp (Turnstile trả về action trong response)
    if ($action && isset($body['action']) && $body['action'] !== $action) return false;

    // Bỏ qua lỗi timeout-or-duplicate (Cloudflare docs: safe to ignore)
    $ignored = ['timeout-or-duplicate', 'invalid-input-response'];
    $errors  = $body['error-codes'] ?? [];
    if ($errors && !array_diff($errors, $ignored)) return true;

    return true;
}

// ─── Hooks: enqueue ───────────────────────────────────────────────────────────

add_action('login_enqueue_scripts', function () {
    if (!adsdefender_rc_enabled()) return;
    $cfg = adsdefender_rc_opts();
    if ($cfg['on_login'] || $cfg['on_lostpass'] || $cfg['on_register']) {
        adsdefender_rc_enqueue();
    }
});

add_action('wp_enqueue_scripts', function () {
    if (!adsdefender_rc_enabled()) return;
    $cfg = adsdefender_rc_opts();
    if ($cfg['on_comment'] && !is_user_logged_in()) {
        adsdefender_rc_enqueue();
    }
});

// ─── Hooks: widget trong form ─────────────────────────────────────────────────

add_action('login_form', function () {
    $cfg = adsdefender_rc_opts();
    if (adsdefender_rc_enabled() && $cfg['on_login']) adsdefender_rc_widget('login');
});

add_action('lostpassword_form', function () {
    $cfg = adsdefender_rc_opts();
    if (adsdefender_rc_enabled() && $cfg['on_lostpass']) adsdefender_rc_widget('lostpassword');
});

add_action('register_form', function () {
    $cfg = adsdefender_rc_opts();
    if (adsdefender_rc_enabled() && $cfg['on_register']) adsdefender_rc_widget('register');
});

add_filter('comment_form_submit_button', function (string $html) {
    if (!adsdefender_rc_enabled()) return $html;
    $cfg = adsdefender_rc_opts();
    if (!$cfg['on_comment'] || is_user_logged_in()) return $html;
    ob_start(); adsdefender_rc_widget('comment'); return ob_get_clean() . $html;
}, 10, 1);

// ─── Hooks: validate ─────────────────────────────────────────────────────────

// Login — priority 20, trước brute-force (30)
add_filter('authenticate', function ($user, $username, $password) {
    if (!adsdefender_rc_enabled()) return $user;
    if (!adsdefender_rc_opts()['on_login']) return $user;
    if (empty($_POST)) return $user;

    if (!adsdefender_rc_verify_post('login')) {
        return new WP_Error('rc_failed', '<strong>Lỗi:</strong> Xác minh CAPTCHA thất bại. Vui lòng thử lại.');
    }
    return $user;
}, 20, 3);

// Lost password
add_action('lostpassword_post', function (WP_Error $errors) {
    if (!adsdefender_rc_enabled() || !adsdefender_rc_opts()['on_lostpass']) return;
    if (!adsdefender_rc_verify_post('lostpassword')) {
        $errors->add('rc_failed', '<strong>Lỗi:</strong> Xác minh CAPTCHA thất bại.');
    }
}, 10, 1);

// Register
add_filter('registration_errors', function (WP_Error $errors) {
    if (!adsdefender_rc_enabled() || !adsdefender_rc_opts()['on_register']) return $errors;
    if (!adsdefender_rc_verify_post('register')) {
        $errors->add('rc_failed', '<strong>Lỗi:</strong> Xác minh CAPTCHA thất bại.');
    }
    return $errors;
}, 10, 1);

// Comment
add_filter('preprocess_comment', function (array $data) {
    if (!adsdefender_rc_enabled() || !adsdefender_rc_opts()['on_comment'] || is_user_logged_in()) return $data;
    if (!adsdefender_rc_verify_post('comment')) {
        wp_die('<strong>Lỗi:</strong> Xác minh CAPTCHA thất bại. Vui lòng quay lại và thử lại.',
               'CAPTCHA thất bại', ['back_link' => true, 'response' => 403]);
    }
    return $data;
}, 10, 1);
