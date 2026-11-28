<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════════════════
// Helper dùng chung cho mọi trang admin: badge, quyền Manager, lưu cài đặt
// theo từng mục, khung trang có tab, chuyển hướng URL cũ.
// ════════════════════════════════════════════════════════════════════════════

// ─── Admin Menu ───────────────────────────────────────────────────────────────

/**
 * Badge số cho menu. Dùng class 'update-plugins' của WordPress — 'awaiting-mod'
 * là class dành riêng cho comment chờ duyệt, gắn vào đây sẽ sai ngữ nghĩa.
 * Số lớn rút gọn thành "999+" để badge không kéo dãn menu.
 */
function adsdefender_menu_badge(int $n): string
{
    if ($n < 1) return '';
    $label = $n > 999 ? '999+' : (string) $n;
    return " <span class='update-plugins count-{$n}'><span class='update-count'>{$label}</span></span>";
}

function adsdefender_unread_log_count(): int
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_LOG_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return 0;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE id > %d",
        (int) get_option('adsdefender_log_last_seen', 0)
    ));
}

// ─── AdsDefender Manager capability ──────────────────────────────────────────

function adsdefender_is_manager(): bool
{
    // Super admin (WP multisite) hoặc user được gán adsdefender_manager
    if (is_super_admin()) return true;
    return current_user_can('adsdefender_manager');
}

function adsdefender_get_manager_user(): ?WP_User
{
    $uid = (int) get_option('adsdefender_manager_user_id', 0);
    if (!$uid) return null;
    $u = get_userdata($uid);
    return ($u && $u->exists()) ? $u : null;
}

function adsdefender_set_manager_user(int $new_uid): void
{
    $old = adsdefender_get_manager_user();
    if ($old && $old->ID !== $new_uid) {
        $old->remove_cap('adsdefender_manager');
    }
    if ($new_uid) {
        $u = get_userdata($new_uid);
        if ($u) {
            $u->add_cap('adsdefender_manager');
            update_option('adsdefender_manager_user_id', $new_uid, false);
        }
    } else {
        delete_option('adsdefender_manager_user_id');
    }
}

add_action('admin_init', function () {
    register_setting('adsdefender', 'adsdefender_settings', [
        'sanitize_callback' => 'adsdefender_sanitize_settings',
    ]);
    // Xử lý gán quyền manager
    if (isset($_POST['adsdefender_save_manager']) && current_user_can('manage_options')) {
        check_admin_referer('adsdefender_manager_nonce');
        if (!adsdefender_is_manager()) {
            wp_die('Bạn không có quyền thực hiện thao tác này.');
        }
        $new_uid = (int)($_POST['adsdefender_manager_uid'] ?? 0);
        adsdefender_set_manager_user($new_uid);
        wp_safe_redirect(adsdefender_admin_url('adsdefender-settings', 'access', ['updated' => 1]));
        exit;
    }
});

// ─── Lưu cài đặt theo từng mục ────────────────────────────────────────────────
//
// Mọi cài đặt nằm chung option adsdefender_settings, nhưng MỖI TAB là một form
// riêng. Form gửi kèm adsdefender_settings[_section]; khi lưu chỉ các khoá của
// mục đó được cập nhật, phần còn lại giữ nguyên giá trị cũ.
//
// Trước đây cả 65 trường nằm trong một form khổng lồ: trường ẩn ở tab khác lỗi
// validation là chặn lưu toàn bộ (lỗi Site ID), và trường nào quên render thì
// mỗi lần lưu bị reset về mặc định.

function adsdefender_settings_sections(): array
{
    return [
        'fraud_connect'   => ['enabled', 'log_enabled', 'api_url', 'secret', 'site_id', 'web_server',
                              'behind_cloudflare', 'behind_proxy', 'trusted_proxies',
                              'block_action', 'redirect_url',
                              'lockout_duration', 'ban_threshold', 'ban_period', 'ban_limit_htaccess',
                              'abuseipdb_key', 'geo_enabled', 'geo_provider', 'geo_key_i2l', 'geo_key'],
        'sec_firewall'    => ['fw_enabled', 'ua_enabled', 'seo_bot_block', 'geo_site_vn_only',
                              'rl_enabled', 'rl_max_requests', 'rl_window_secs', 'rl_lockout_mins',
                              'detect_404', 'detect_404_max', 'detect_404_window', 'detect_404_lockout'],
        'sec_login'       => ['bf_enabled', 'bf_check_period', 'bf_max_ip', 'bf_max_user', 'bf_lockout_mins', 'bf_block_admin', 'bf_require_cookie',
                              'hp_enabled',
                              'rc_enabled', 'rc_provider', 'rc_site_key', 'rc_secret_key', 'rc_v3_threshold',
                              'rc_on_login', 'rc_on_lostpass', 'rc_on_register', 'rc_on_comment',
                              'hl_enabled', 'hl_slug', 'xmlrpc_disabled', 'lg_vn_only',
                              'cs_enabled', 'cs_rate_limit', 'cs_blocklist'],
        'sec_hardening'   => ['st_enabled', 'st_protect_files', 'st_dir_browsing', 'st_uploads_php', 'st_plugins_php', 'st_themes_php',
                              'wt_file_editor', 'wt_remove_version', 'wt_author_enum', 'wt_rest_users', 'wt_rss_version',
                              'wt_hsts', 'wt_hsts_age', 'wt_csp', 'wt_csp_value'],
        'sec_scanner'     => ['scan_enabled'],
        'sec_filemonitor' => ['fm_enabled', 'fm_depth'],
        'sec_adminlog'    => ['admin_log_enabled'],
        'block_page'      => ['bp_title', 'bp_message', 'bp_contact', 'bp_promo'],
        'live_traffic'    => ['lt_enabled', 'lt_skip_admins', 'lt_hours', 'lt_max_rows',
                              'cf_enabled', 'cf_mode', 'cf_clicks', 'cf_hours', 'cf_bot', 'cf_ti', 'cf_gclid', 'cf_subnet', 'cf_subnet_n'],
        'sec_threat'      => ['ti_spamhaus', 'ti_firehol', 'ti_tor'],
    ];
}

/**
 * Bật "chỉ đăng nhập từ VN" mà IP hiện tại của admin không được phép → từ chối bật,
 * tránh tự khoá mình ở lần đăng nhập sau. Chưa có danh sách thì tải trước rồi mới kiểm tra.
 */
function adsdefender_sanitize_lg_vn_only(bool $want, string $key = 'lg_vn_only', string $label = 'Chỉ đăng nhập từ Việt Nam'): int
{
    if (!$want || !function_exists('adsdefender_lg_check')) return $want ? 1 : 0;
    if (!get_option(ADSDEFENDER_LG_OPTION)) adsdefender_lg_sync();
    $ip = adsdefender_get_real_ip();
    [$ok, $why] = adsdefender_lg_check($ip);
    if ($ok) return 1;
    add_settings_error('adsdefender_settings', $key,
        'Không bật "' . $label . '": IP hiện tại của bạn (' . esc_html($ip) . ') không phải IP Việt Nam / Google / Cloudflare — bật lên bạn sẽ không đăng nhập lại được. Thêm IP này vào Whitelist trước nếu cần.');
    return 0;
}

function adsdefender_sanitize_settings($in): array
{
    $old = get_option('adsdefender_settings', []);
    if (!is_array($old)) $old = [];
    if (!is_array($in))  return $old;

    $section = isset($in['_section']) ? sanitize_key($in['_section']) : '';
    unset($in['_section']);

    // Không có _section: lưu bằng code (update_option với mảng đầy đủ, vd. thêm
    // whitelist). Khoá mà sanitizer không quản lý nhưng đang có trong option cũ
    // (cài đặt của bản mới hơn / do code khác ghi) được GIỮ — bản cũ âm thầm xoá.
    if ($section === '') {
        $clean = adsdefender_sanitize_settings_full($in);
        foreach ($old as $k => $v) {
            if (!array_key_exists($k, $clean)) $clean[$k] = array_key_exists($k, $in) && is_scalar($in[$k]) ? $in[$k] : $v;
        }
        return $clean;
    }

    $sections = adsdefender_settings_sections();
    if (!isset($sections[$section])) return $old;      // mục lạ → không đụng gì

    if (!adsdefender_is_manager()) {
        add_settings_error('adsdefender_settings', 'no_permission',
            '⛔ Bạn không có quyền thay đổi cài đặt AdsDefender. Chỉ AdsDefender Manager mới được phép.', 'error');
        return $old;
    }

    $clean = adsdefender_sanitize_settings_full($in);
    $out   = $old;
    foreach ($sections[$section] as $k) {
        if (array_key_exists($k, $clean)) $out[$k] = $clean[$k];
    }
    return $out;
}

function adsdefender_sanitize_settings_full(array $in): array
{
    if (!adsdefender_is_manager()) {
        add_settings_error('adsdefender_settings', 'no_permission',
            '⛔ Bạn không có quyền thay đổi cài đặt AdsDefender. Chỉ AdsDefender Manager mới được phép.', 'error');
        return get_option('adsdefender_settings', []);
    }

    // Whitelist có form riêng — nếu không submit trong $in thì giữ nguyên giá trị cũ
    $old_settings = get_option('adsdefender_settings', []);
    if (!isset($in['whitelist']) || $in['whitelist'] === '') {
        $whitelist_val = $old_settings['whitelist'] ?? '';
    } else {
        $whitelist_lines = array_filter(array_map(function ($line) {
            $line = trim($line);
            if (filter_var($line, FILTER_VALIDATE_IP)) return $line;
            if (preg_match('/^[\da-fA-F:\.]+\/\d{1,3}$/', $line)) return $line;
            return '';
        }, explode("\n", $in['whitelist'])));
        $whitelist_val = implode("\n", $whitelist_lines);
    }
    return [
        'enabled'      => !empty($in['enabled'])     ? 1 : 0,
        'log_enabled'  => !empty($in['log_enabled']) ? 1 : 0,
        'api_url'      => (function(string $u): string {
                            $u = esc_url_raw(trim($u));
                            return in_array(parse_url($u, PHP_URL_SCHEME), ['http', 'https'], true) ? $u : '';
                         })(trim($in['api_url'] ?? '')),
        'secret'       => sanitize_text_field($in['secret'] ?? ''),
        // Để trống = 0 (chưa cấu hình). Không render "0" ra form: input có min="1"
        // nên value="0" làm trình duyệt chặn submit, buộc phải nhập Site ID mới lưu được.
        'site_id'      => max(0, (int) ($in['site_id'] ?? 0)),
        'block_action' => in_array($in['block_action'] ?? '', ['redirect', '403', 'blank'])
                            ? $in['block_action'] : '403',
        'redirect_url' => sanitize_text_field(trim($in['redirect_url'] ?? '')),
        'web_server'   => in_array($in['web_server'] ?? '', ['openlitespeed', 'litespeed', 'nginx', 'apache', 'auto'])
                            ? $in['web_server'] : 'auto',
        // ── IP thật sau Cloudflare / reverse proxy (core.php adsdefender_get_real_ip) ──
        'behind_cloudflare'  => !empty($in['behind_cloudflare']) ? 1 : 0,
        'behind_proxy'       => !empty($in['behind_proxy'])      ? 1 : 0,
        'trusted_proxies'    => implode("\n", array_filter(array_map(function ($line) {
            $line = trim((string) $line);
            if (filter_var($line, FILTER_VALIDATE_IP)) return $line;
            if (preg_match('/^[\da-fA-F:\.]+\/\d{1,3}$/', $line)) return $line;
            return '';
        }, explode("\n", (string) ($in['trusted_proxies'] ?? ''))))),

        // ── Live Traffic ──
        'lt_enabled'         => !empty($in['lt_enabled']) ? 1 : 0,
        'lt_skip_admins'     => array_key_exists('lt_skip_admins', $in) ? (!empty($in['lt_skip_admins']) ? 1 : 0) : 1,
        'lt_hours'           => max(1, min(720, (int) ($in['lt_hours'] ?? 24))),
        'lt_max_rows'        => max(1000, min(200000, (int) ($in['lt_max_rows'] ?? 20000))),

        // ── Click ảo tại chỗ (chạy trên dữ liệu Live Traffic) ──
        'cf_enabled'         => array_key_exists('cf_enabled', $in) ? (!empty($in['cf_enabled']) ? 1 : 0) : 1,
        'cf_mode'            => ($in['cf_mode'] ?? 'lock') === 'flag' ? 'flag' : 'lock',
        'cf_clicks'          => max(2, min(20, (int) ($in['cf_clicks'] ?? 3))),
        'cf_hours'           => max(1, min(168, (int) ($in['cf_hours'] ?? 24))),
        'cf_bot'             => array_key_exists('cf_bot', $in) ? (!empty($in['cf_bot']) ? 1 : 0) : 1,
        'cf_ti'              => array_key_exists('cf_ti', $in) ? (!empty($in['cf_ti']) ? 1 : 0) : 1,
        'cf_gclid'           => !empty($in['cf_gclid']) ? 1 : 0,
        'cf_subnet'          => !empty($in['cf_subnet']) ? 1 : 0,
        'cf_subnet_n'        => max(3, min(50, (int) ($in['cf_subnet_n'] ?? 5))),

        // ── Threat Intel: off | label | block ──
        'ti_spamhaus'        => in_array($in['ti_spamhaus'] ?? '', ['off', 'label', 'block'], true) ? $in['ti_spamhaus'] : 'off',
        'ti_firehol'         => in_array($in['ti_firehol']  ?? '', ['off', 'label', 'block'], true) ? $in['ti_firehol']  : 'off',
        'ti_tor'             => in_array($in['ti_tor']      ?? '', ['off', 'label', 'block'], true) ? $in['ti_tor']      : 'off',

        // ── Trang chặn (includes/block-page.php) ──
        'bp_title'           => sanitize_text_field($in['bp_title']   ?? ''),
        'bp_message'         => sanitize_textarea_field($in['bp_message'] ?? ''),
        'bp_contact'         => sanitize_textarea_field($in['bp_contact'] ?? ''),
        // Mặc định BẬT: form gửi kèm hidden bp_promo=0 nên bỏ tick vẫn nhận được 0
        'bp_promo'           => array_key_exists('bp_promo', $in) ? (!empty($in['bp_promo']) ? 1 : 0) : 1,
        'whitelist'          => $whitelist_val,
        'lockout_duration'   => max(5,  (int)($in['lockout_duration']   ?? 1440)),
        'ban_threshold'      => max(1,  (int)($in['ban_threshold']      ?? 3)),
        'ban_period'         => max(1,  (int)($in['ban_period']         ?? 7)),
        'ban_limit_htaccess' => max(10, (int)($in['ban_limit_htaccess'] ?? 100)),
        'abuseipdb_key'      => sanitize_text_field($in['abuseipdb_key'] ?? ''),
        // Vị trí IP — mặc định BẬT: form gửi kèm hidden geo_enabled=0 nên bỏ tick vẫn nhận được 0
        'geo_enabled'        => array_key_exists('geo_enabled', $in) ? (!empty($in['geo_enabled']) ? 1 : 0) : 1,
        'geo_provider'       => ($in['geo_provider'] ?? '') === 'ipapi' ? 'ipapi' : 'ip2location',
        'geo_key_i2l'        => preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['geo_key_i2l'] ?? '')),
        'geo_key'            => preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['geo_key'] ?? '')),
        'admin_log_enabled'  => !empty($in['admin_log_enabled']) ? 1 : 0,

        // ── Brute Force ──
        'bf_enabled'         => !empty($in['bf_enabled'])       ? 1 : 0,
        'bf_check_period'    => max(1,  (int)($in['bf_check_period']  ?? 5)),
        'bf_max_ip'          => max(1,  (int)($in['bf_max_ip']        ?? 5)),
        'bf_max_user'        => max(1,  (int)($in['bf_max_user']      ?? 10)),
        'bf_lockout_mins'    => max(5,  (int)($in['bf_lockout_mins']  ?? 30)),
        'bf_block_admin'     => !empty($in['bf_block_admin'])   ? 1 : 0,
        // Mặc định BẬT: form gửi kèm hidden bf_require_cookie=0 nên bỏ tick vẫn nhận được 0
        'bf_require_cookie'  => array_key_exists('bf_require_cookie', $in) ? (!empty($in['bf_require_cookie']) ? 1 : 0) : 1,

        // ── Firewall ──
        'fw_enabled'         => !empty($in['fw_enabled'])       ? 1 : 0,
        'geo_site_vn_only'   => adsdefender_sanitize_lg_vn_only(!empty($in['geo_site_vn_only']), 'geo_site_vn_only', 'Chỉ cho khách Việt Nam truy cập website'),
        'ua_enabled'         => !empty($in['ua_enabled'])       ? 1 : 0,
        'seo_bot_block'      => !empty($in['seo_bot_block'])    ? 1 : 0,

        // ── Hide Login ──
        'hl_enabled'         => !empty($in['hl_enabled'])       ? 1 : 0,
        'lg_vn_only'         => adsdefender_sanitize_lg_vn_only(!empty($in['lg_vn_only'])),
        'hl_slug'            => preg_replace('/[^a-z0-9\-_]/i', '', trim($in['hl_slug'] ?? '')),
        'xmlrpc_disabled'    => !empty($in['xmlrpc_disabled'])  ? 1 : 0,

        // ── Rate Limit ──
        'rl_enabled'         => !empty($in['rl_enabled'])       ? 1 : 0,
        'rl_max_requests'    => max(10,  (int)($in['rl_max_requests'] ?? 300)),
        'rl_window_secs'     => max(10,  (int)($in['rl_window_secs']  ?? 60)),
        'rl_lockout_mins'    => max(5,   (int)($in['rl_lockout_mins'] ?? 10)),

        // ── Honeypot ──
        'hp_enabled'         => !empty($in['hp_enabled'])       ? 1 : 0,

        // ── CAPTCHA ──
        'rc_enabled'         => !empty($in['rc_enabled'])       ? 1 : 0,
        'rc_provider'        => in_array($in['rc_provider'] ?? '', ['google_v2','google_v3','turnstile'], true) ? $in['rc_provider'] : 'google_v2',
        'rc_site_key'        => sanitize_text_field($in['rc_site_key']   ?? ''),
        'rc_secret_key'      => sanitize_text_field($in['rc_secret_key'] ?? ''),
        'rc_v3_threshold'    => min(1.0, max(0.1, (float)($in['rc_v3_threshold'] ?? 0.5))),
        'rc_on_login'        => !empty($in['rc_on_login'])    ? 1 : 0,
        'rc_on_lostpass'     => !empty($in['rc_on_lostpass']) ? 1 : 0,
        'rc_on_register'     => !empty($in['rc_on_register']) ? 1 : 0,
        'rc_on_comment'      => !empty($in['rc_on_comment'])  ? 1 : 0,

        // ── System Tweaks ──
        'st_enabled'       => !empty($in['st_enabled'])       ? 1 : 0,
        'st_protect_files' => !empty($in['st_protect_files']) ? 1 : 0,
        'st_dir_browsing'  => !empty($in['st_dir_browsing'])  ? 1 : 0,
        'st_uploads_php'   => !empty($in['st_uploads_php'])   ? 1 : 0,
        'st_plugins_php'   => !empty($in['st_plugins_php'])   ? 1 : 0,
        'st_themes_php'    => !empty($in['st_themes_php'])    ? 1 : 0,

        // ── WP Tweaks ──
        'wt_file_editor'   => !empty($in['wt_file_editor'])   ? 1 : 0,
        'wt_remove_version'=> !empty($in['wt_remove_version'])? 1 : 0,
        'wt_author_enum'   => !empty($in['wt_author_enum'])   ? 1 : 0,
        'wt_rest_users'    => !empty($in['wt_rest_users'])    ? 1 : 0,
        'wt_rss_version'   => !empty($in['wt_rss_version'])   ? 1 : 0,
        'wt_hsts'          => !empty($in['wt_hsts'])          ? 1 : 0,
        'wt_hsts_age'      => max(300, (int)($in['wt_hsts_age'] ?? 31536000)),
        'wt_csp'           => !empty($in['wt_csp'])           ? 1 : 0,
        'wt_csp_value'     => sanitize_textarea_field($in['wt_csp_value'] ?? ''),

        // ── 404 Detection ──
        'detect_404'         => !empty($in['detect_404'])       ? 1 : 0,
        'detect_404_max'     => max(5,  (int)($in['detect_404_max']    ?? 20)),
        'detect_404_window'  => max(30, (int)($in['detect_404_window'] ?? 60)),
        'detect_404_lockout' => max(5,  (int)($in['detect_404_lockout'] ?? 30)),

        // ── File Monitor ──
        'fm_enabled'         => !empty($in['fm_enabled'])       ? 1 : 0,
        'fm_depth'           => max(1, min(6, (int)($in['fm_depth'] ?? 3))),   // khớp max="6" của input

        // ── Malware Scanner ──
        'scan_enabled'       => !empty($in['scan_enabled'])     ? 1 : 0,

        // ── Comment Security ──
        'cs_enabled'         => !empty($in['cs_enabled'])       ? 1 : 0,
        'cs_rate_limit'      => max(1, (int)($in['cs_rate_limit'] ?? 5)),
        'cs_blocklist'       => !empty($in['cs_blocklist'])     ? 1 : 0,
    ];
}

// ─── Khung trang có tab ───────────────────────────────────────────────────────

function adsdefender_admin_url(string $page, string $tab = '', array $args = []): string
{
    $q = ['page' => $page];
    if ($tab !== '') $q['tab'] = $tab;
    return add_query_arg(array_merge($q, $args), admin_url('admin.php'));
}

/**
 * Trang có thanh tab. $tabs = ['key' => ['Nhãn (có thể chứa badge HTML)', callable], ...]
 * Nhãn và tiêu đề là hằng số trong code (không phải input) nên in thẳng.
 */
function adsdefender_render_tabbed_page(string $page, string $title, array $tabs, string $default): void
{
    $tab = sanitize_key($_GET['tab'] ?? $default);
    if (!isset($tabs[$tab])) $tab = $default;

    echo '<div class="wrap"><h1>' . $title . '</h1>';
    settings_errors();
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
    foreach ($tabs as $k => [$label]) {
        echo '<a href="' . esc_url(adsdefender_admin_url($page, $k)) . '" class="nav-tab ' . ($tab === $k ? 'nav-tab-active' : '') . '">' . $label . '</a>';
    }
    echo '</nav>';
    call_user_func($tabs[$tab][1]);
    echo '</div>';
}

// Form cài đặt của một mục — gửi qua options.php, quay lại đúng tab đang mở
function adsdefender_settings_form_open(string $section): void
{
    echo '<form method="post" action="options.php">';
    settings_fields('adsdefender');
    echo '<input type="hidden" name="adsdefender_settings[_section]" value="' . esc_attr($section) . '">';
}

function adsdefender_settings_form_close(string $label = 'Lưu cài đặt'): void
{
    submit_button($label);
    echo '</form>';
}

// ─── Chuyển hướng URL cũ (trước 2.5.122) ─────────────────────────────────────
//
// Menu "Bảo vệ" và "Hệ thống" đã được chia lại theo module. Bookmark, link trong
// email/Telegram cũ vẫn phải mở đúng chỗ.

function adsdefender_legacy_page_target(string $page, string $tab, string $stab): ?array
{
    // 2.5.128: Live Traffic tách khỏi Chống Click Fraud thành menu riêng
    if ($page === 'adsdefender-fraud' && $tab === 'live') return ['adsdefender-live', ''];
    if ($page === 'adsdefender-protect') {
        return [
            'log'       => ['adsdefender-fraud', 'log'],
            'seclog'    => ['adsdefender-security', 'events'],
            'manual'    => ['adsdefender-fraud', 'manual'],
            'whitelist' => ['adsdefender-fraud', 'whitelist'],
        ][$tab] ?? ['adsdefender-fraud', 'log'];
    }
    if ($page === 'adsdefender-system') {
        if ($tab === '' || $tab === 'settings') {
            return [
                'connect' => ['adsdefender-fraud', 'connect'],
                'protect' => ['adsdefender-security', 'firewall'],
                'server'  => ['adsdefender-security', 'hardening'],
                'groups'  => ['adsdefender-fraud', 'groups'],
                'tools'   => ['adsdefender-fraud', 'tools'],
            ][$stab] ?? ['adsdefender-fraud', 'connect'];
        }
        return [
            'scripts'     => ['adsdefender-marketing', 'scripts'],
            'adminlog'    => ['adsdefender-security', 'adminlog'],
            'filemonitor' => ['adsdefender-security', 'filemonitor'],
            'sitescanner' => ['adsdefender-security', 'scanner'],
            'access'      => ['adsdefender-settings', 'access'],
            'update'      => ['adsdefender-settings', 'update'],
        ][$tab] ?? ['adsdefender-fraud', 'connect'];
    }
    return null;
}

// Phải chạy ở admin_menu, KHÔNG phải admin_init: wp-admin/menu.php kiểm tra quyền
// và wp_die() với slug không còn đăng ký ngay sau admin_menu, trước admin_init.
add_action('admin_menu', function () {
    if (($GLOBALS['pagenow'] ?? '') !== 'admin.php' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $page   = sanitize_key($_GET['page'] ?? '');
    $target = adsdefender_legacy_page_target($page, sanitize_key($_GET['tab'] ?? ''), sanitize_key($_GET['stab'] ?? ''));
    if (!$target) return;
    $args = array_diff_key(wp_unslash($_GET), ['page' => 1, 'tab' => 1, 'stab' => 1]);
    wp_safe_redirect(adsdefender_admin_url($target[0], $target[1], $args));
    exit;
}, 0);

// ─── IP Reputation Modal ──────────────────────────────────────────────────────

function adsdefender_ip_rep_modal(): void
{
    $nonce  = wp_create_nonce('adsdefender_ip_rep');
    $ajax   = admin_url('admin-ajax.php');
    $opts   = adsdefender_settings();
    $has_key = !empty($opts['abuseipdb_key']);
    ?>
    <div id="ads-ip-rep-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999999;align-items:center;justify-content:center">
      <div style="background:#fff;border-radius:8px;padding:24px 28px;max-width:480px;width:92%;position:relative;max-height:80vh;overflow-y:auto">
        <button id="ads-ip-rep-close" style="position:absolute;top:10px;right:14px;background:none;border:none;font-size:22px;cursor:pointer;color:#666">&times;</button>
        <h3 style="margin:0 0 14px">🔍 IP Reputation</h3>
        <div id="ads-ip-rep-body">
          <?php if (!$has_key): ?>
          <div style="color:#d63638;background:#fff0f0;border:1px solid #ffc2c2;border-radius:4px;padding:12px;font-size:13px">
            ⚠️ Chưa cấu hình <strong>AbuseIPDB API Key</strong>.<br>
            Vào <a href="<?php echo esc_url(adsdefender_admin_url('adsdefender-fraud', 'connect') . '#abuseipdb'); ?>">Chống Click Fraud → Kết nối &amp; Hành động</a> để thêm.
            Key miễn phí tại <a href="https://www.abuseipdb.com/account/api" target="_blank">abuseipdb.com</a> (1000 check/ngày).
          </div>
          <?php else: ?>
          <div id="ads-ip-rep-loading" style="text-align:center;padding:24px;color:#666">⏳ Đang tra cứu...</div>
          <div id="ads-ip-rep-content" style="display:none"></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <script>
    (function($){
      var nonce='<?php echo esc_js($nonce); ?>', ajax='<?php echo esc_js($ajax); ?>', hasKey=<?php echo $has_key?'true':'false'; ?>;
      function e(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
      function openModal(ip){
        var $m=$('#ads-ip-rep-modal'); $m.css('display','flex');
        $m.find('h3').text('🔍 IP Reputation: '+ip);
        if(!hasKey) return;
        $('#ads-ip-rep-loading').show(); $('#ads-ip-rep-content').hide().empty();
        $.post(ajax,{action:'adsdefender_ip_reputation',ip:ip,_ajax_nonce:nonce},function(r){
          $('#ads-ip-rep-loading').hide();
          if(!r.success){$('#ads-ip-rep-content').html('<div style="color:#d63638;font-size:13px">'+e(r.data)+'</div>').show();return;}
          var d=r.data, sc=(+d.score)>=75?'#d63638':d.score>=25?'#f0860a':'#00a32a';
          var h='<div style="display:grid;grid-template-columns:140px 1fr;gap:8px 12px;font-size:13px">';
          h+='<b style="color:#555">Abuse Score</b><span style="font-size:22px;font-weight:700;color:'+sc+'">'+(+d.score||0)+'%</span>';
          h+='<b style="color:#555">Quốc gia</b><span>'+(e(d.country)||'—')+'</span>';
          h+='<b style="color:#555">ISP</b><span>'+(e(d.isp)||'—')+'</span>';
          h+='<b style="color:#555">Usage Type</b><span>'+(e(d.usage_type)||'—')+'</span>';
          h+='<b style="color:#555">Reports</b><span>'+(+d.total_reports||0)+' lần</span>';
          h+='<b style="color:#555">Báo cáo cuối</b><span>'+(d.last_reported?e(String(d.last_reported).substring(0,10)):'—')+'</span>';
          if(d.is_tor) h+='<b style="color:#555">Tor</b><span style="color:#d63638;font-weight:700">⚠️ Tor Exit Node</span>';
          h+='</div><div style="margin-top:14px"><a href="https://www.abuseipdb.com/check/'+encodeURIComponent(d.ip)+'" target="_blank" class="button button-small">Xem AbuseIPDB →</a></div>';
          $('#ads-ip-rep-content').html(h).show();
        }).fail(function(){$('#ads-ip-rep-loading').hide();$('#ads-ip-rep-content').html('<div style="color:#d63638;font-size:13px">Lỗi kết nối.</div>').show();});
      }
      $(document).on('click','.ads-ip-rep',function(e){e.preventDefault();openModal($(this).data('ip'));});
      $(document).on('click','#ads-ip-rep-close',function(){$('#ads-ip-rep-modal').hide();});
      $(document).on('click','#ads-ip-rep-modal',function(e){if($(e.target).is('#ads-ip-rep-modal'))$(this).hide();});
    })(jQuery);
    </script>
    <?php
    adsdefender_geo_script();
}

// ─── Vị trí IP ────────────────────────────────────────────────────────────────
//
// Mọi phần tử <span class="ads-geo" data-ip="…"></span> trên trang được điền vị trí
// (kể cả dòng Live Traffic thêm vào sau — theo dõi bằng MutationObserver). IP đã
// tra được nhớ trong trang, chỉ hỏi server những IP mới.

function adsdefender_geo_script(): void
{
    static $done = false;
    if ($done || !function_exists('adsdefender_geo_enabled') || !adsdefender_geo_enabled()) return;
    $done = true;
    ?>
    <style>
    .ads-geo{display:block;font-size:11px;color:#50575e;line-height:1.35;margin-top:2px;max-width:260px}
    .ads-geo:empty{display:none}
    .ads-geo.sm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px}
    .ads-geo .cc{display:inline-block;font:600 10px/14px monospace;background:#f0f0f1;border-radius:3px;padding:0 3px;margin-right:3px;color:#1d2327}
    .ads-geo .isp{color:#8c8f94}
    .ads-geo .tg{display:inline-block;font-size:10px;border-radius:3px;padding:0 4px;margin-left:3px}
    .ads-geo .tg.dc{background:#fcf0f1;color:#b32d2e}
    .ads-geo .tg.mb{background:#edfaef;color:#00712d}
    .ads-geo .tg.px{background:#fcf9e8;color:#8a6d00}
    </style>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, nonce = <?php echo wp_json_encode(wp_create_nonce('adsdefender_geo')); ?>;
        var cache = {}, pending = {}, timer = null, stop = false;
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
        // So khớp tên địa danh bỏ dấu cách / hoa thường: "Hanoi" ≈ "Ha Noi", "Ho Chi Minh City" ≈ "Ho Chi Minh"
        function same(a, b) { a = String(a).toLowerCase().replace(/[^a-z]/g, ''); b = String(b).toLowerCase().replace(/[^a-z]/g, ''); return a.indexOf(b) === 0 || b.indexOf(a) === 0; }
        // sm = bản gọn 1 dòng (Live Traffic): chỉ mã nước + thành phố + nhãn; nhà mạng xem ở tooltip
        function html(g, sm) {
            if (!g) return '';
            if (g.local) return '<span class="isp">IP nội bộ</span>';
            if (g.err) return '<span class="isp">Không rõ vị trí</span>';
            // Trong nước: thành phố, tỉnh — nước ngoài: thành phố, quốc gia (bỏ trùng: "Hanoi, Ha Noi")
            var parts = (sm ? [g.city || g.region || g.country] : (g.cc === 'VN' ? [g.city, g.region] : [g.city, g.country])), place = [];
            parts.forEach(function (x) { if (x && !place.some(function (y) { return same(x, y); })) place.push(x); });
            place = place.join(', ') || g.country || '';
            var h = (g.cc ? '<span class="cc">' + esc(g.cc) + '</span>' : '') + esc(place);
            if (g.hosting) h += '<span class="tg dc" title="IP của datacenter / cloud — thường là bot, VPN hoặc proxy, không phải khách thật">' + (sm ? 'DC' : 'datacenter') + '</span>';
            else if (g.mobile) h += '<span class="tg mb" title="Mạng di động 3G/4G/5G — vị trí thường là nơi đặt gateway của nhà mạng, không chính xác tới tỉnh">' + (sm ? '4G' : 'di động') + '</span>';
            if (g.proxy) h += '<span class="tg px" title="Proxy / VPN / Tor">proxy</span>';
            if (g.isp && !sm) h += '<br><span class="isp">' + esc(g.isp) + '</span>';
            return h;
        }
        function title(g) {
            if (!g || g.local || g.err) return '';
            return 'Vị trí ước lượng theo IP\n' + [g.city, g.region, g.country].filter(Boolean).join(', ') +
                (g.isp ? '\nNhà mạng: ' + g.isp : '') + (g.as ? '\n' + g.as : '');
        }
        function paint(root) {
            var need = [];
            (root || document).querySelectorAll('.ads-geo[data-ip]:not([data-done])').forEach(function (el) {
                var ip = el.getAttribute('data-ip');
                if (cache[ip]) { el.innerHTML = html(cache[ip], el.classList.contains('sm')); el.title = title(cache[ip]); el.setAttribute('data-done', '1'); }
                else if (!pending[ip] && need.indexOf(ip) < 0) need.push(ip);
            });
            return need;
        }
        function run() {
            timer = null;
            if (stop) return;
            var need = paint().slice(0, 100);
            if (!need.length) return;
            need.forEach(function (ip) { pending[ip] = 1; });
            var fd = new FormData();
            fd.append('action', 'adsdefender_geo'); fd.append('nonce', nonce);
            need.forEach(function (ip) { fd.append('ips[]', ip); });
            fetch(ajax, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (res) {
                need.forEach(function (ip) { delete pending[ip]; });
                if (!res || !res.success) return;
                if (res.data.off) { stop = true; return; }
                var geo = res.data.geo || {};
                for (var ip in geo) cache[ip] = geo[ip];
                paint();
                // Còn IP chưa tra được (hết lượt miễn phí) → thử lại sau
                if (need.some(function (ip) { return !cache[ip]; })) setTimeout(schedule, res.data.wait ? 30000 : 3000);
            }).catch(function () { need.forEach(function (ip) { delete pending[ip]; }); });
        }
        function schedule() { if (!timer) timer = setTimeout(run, 300); }
        window.adsGeo = {refresh: schedule};
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule); else schedule();
        new MutationObserver(function (ms) {
            for (var i = 0; i < ms.length; i++) if (ms[i].addedNodes.length) { schedule(); return; }
        }).observe(document.body, {childList: true, subtree: true});
    })();
    </script>
    <?php
}

// Modal trên Dashboard page
add_action('admin_footer', function() {
    if (($GLOBALS['plugin_page'] ?? '') !== 'adsdefender') return;
    adsdefender_ip_rep_modal();
});

// ─── Bản quyền & ủng hộ ───────────────────────────────────────────────────────

define('ADSDEFENDER_AUTHOR_URL', 'https://saigon.pro');
define('ADSDEFENDER_DONATE_URL', 'https://www.paypal.com/paypalme/dangngocsoncom');

// Trang Plugins: thêm "saigon.pro" + "Ủng hộ" cạnh "Phiên bản … | Tác giả …"
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file !== plugin_basename(ADSDEFENDER_DIR . 'adsdefender.php')) return $links;
    $links[] = '<a href="' . esc_url(ADSDEFENDER_AUTHOR_URL) . '" target="_blank" rel="noopener">🌐 saigon.pro</a>';
    $links[] = '<a href="' . esc_url(ADSDEFENDER_DONATE_URL) . '" target="_blank" rel="noopener" style="color:#d63638;font-weight:600">❤ Ủng hộ (PayPal)</a>';
    return $links;
}, 10, 2);

// Chân trang các trang AdsDefender
add_filter('admin_footer_text', function ($text) {
    $page = (string) ($GLOBALS['plugin_page'] ?? '');
    if (!str_starts_with($page, 'adsdefender')) return $text;
    return 'AdsDefender ' . esc_html(ADSDEFENDER_VERSION) . ' — © ' . esc_html(wp_date('Y')) . ' <a href="' . esc_url(ADSDEFENDER_AUTHOR_URL) . '" target="_blank" rel="noopener">saigon.pro</a>'
         . ' · <a href="' . esc_url(ADSDEFENDER_DONATE_URL) . '" target="_blank" rel="noopener" style="color:#d63638">❤ Ủng hộ tác giả qua PayPal</a>';
});
