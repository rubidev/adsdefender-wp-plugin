<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════════════════
// Menu quản trị AdsDefender
//
//   📊 Tổng quan          adsdefender            admin/pages/dashboard.php
//   📡 Live Traffic       adsdefender-live       admin/pages/fraud.php      (adsdefender_fraud_tab_live)
//   🎯 Chống Click Fraud  adsdefender-fraud      admin/pages/fraud.php
//   🛡 Bảo mật            adsdefender-security   admin/pages/security.php   (module security)
//   🚀 Marketing          adsdefender-marketing  admin/pages/marketing.php  (module marketing)
//   ⚙️ Cài đặt chung      adsdefender-settings   admin/pages/settings.php
//
// Nguyên tắc: cấu hình của một tính năng đặt cùng trang với kết quả / nhật ký
// của nó, mỗi tab là một form riêng (xem adsdefender_settings_sections()).
// ════════════════════════════════════════════════════════════════════════════

require_once ADSDEFENDER_DIR . 'admin/common.php';
require_once ADSDEFENDER_DIR . 'admin/pages/dashboard.php';
require_once ADSDEFENDER_DIR . 'admin/pages/fraud.php';
require_once ADSDEFENDER_DIR . 'admin/pages/settings.php';
if (adsdefender_module_enabled('security'))  require_once ADSDEFENDER_DIR . 'admin/pages/security.php';
if (adsdefender_module_enabled('marketing')) require_once ADSDEFENDER_DIR . 'admin/pages/marketing.php';

add_action('admin_menu', function () {
    $log_badge = adsdefender_menu_badge(adsdefender_unread_log_count());

    $remote    = adsdefender_fetch_update_info();
    $has_upd   = $remote && version_compare($remote['version'] ?? '0', ADSDEFENDER_VERSION, '>');
    $upd_badge = $has_upd ? adsdefender_menu_badge(1) : '';

    // Menu cha: chỉ báo cập nhật. Badge log block thuộc về "Chống Click Fraud".
    add_menu_page('AdsDefender', 'AdsDefender' . $upd_badge,
        'manage_options', 'adsdefender', 'adsdefender_page_dashboard', 'dashicons-shield', 80);

    add_submenu_page('adsdefender', 'Tổng quan',         '📊 Tổng quan',                      'manage_options', 'adsdefender',           'adsdefender_page_dashboard');
    add_submenu_page('adsdefender', 'Live Traffic',      '📡 Live Traffic',                   'manage_options', 'adsdefender-live',      'adsdefender_page_live');
    add_submenu_page('adsdefender', 'Chống Click Fraud', '🎯 Chống Click Fraud' . $log_badge, 'manage_options', 'adsdefender-fraud',     'adsdefender_page_fraud');
    if (adsdefender_module_enabled('security')) {
        add_submenu_page('adsdefender', 'Bảo mật',       '🛡 Bảo mật',                        'manage_options', 'adsdefender-security',  'adsdefender_page_security');
    }
    if (adsdefender_module_enabled('marketing')) {
        add_submenu_page('adsdefender', 'Marketing',     '🚀 Marketing',                      'manage_options', 'adsdefender-marketing', 'adsdefender_page_marketing');
    }
    add_submenu_page('adsdefender', 'Cài đặt chung',     '⚙️ Cài đặt chung' . $upd_badge,     'manage_options', 'adsdefender-settings',  'adsdefender_page_general');
});

// ─── 🎯 Chống Click Fraud ─────────────────────────────────────────────────────

function adsdefender_page_fraud(): void
{
    $opts = adsdefender_settings();
    // Chưa kết nối Matomo thì mở tab Kết nối; đã chạy rồi thì việc hằng ngày là xem log
    $default = empty($opts['api_url']) || empty($opts['site_id']) ? 'connect' : 'log';

    adsdefender_render_tabbed_page('adsdefender-fraud', '🎯 AdsDefender — Chống Click Fraud', [
        'connect'   => ['🔗 Kết nối & Hành động', 'adsdefender_fraud_tab_connect'],
        'log'       => ['🚫 Block Log' . adsdefender_menu_badge(adsdefender_unread_log_count()), 'adsdefender_page_log'],
        'manual'    => ['✋ Block thủ công',      'adsdefender_page_manual'],
        'whitelist' => ['✅ Whitelist',           'adsdefender_page_whitelist'],
        'groups'    => ['🔗 Cross-site',          'adsdefender_fraud_tab_groups'],
        'tools'     => ['🧪 Sync & Kiểm tra',     'adsdefender_fraud_tab_tools'],
    ], $default);
}

// ─── 📡 Live Traffic ──────────────────────────────────────────────────────────

function adsdefender_page_live(): void
{
    echo '<div class="wrap"><h1>📡 AdsDefender — Live Traffic</h1>';
    settings_errors();
    adsdefender_fraud_tab_live();
    echo '</div>';
}

// ─── 🛡 Bảo mật ───────────────────────────────────────────────────────────────

function adsdefender_page_security(): void
{
    adsdefender_render_tabbed_page('adsdefender-security', '🛡 AdsDefender — Bảo mật', [
        'firewall'    => ['🛡️ Firewall',          'adsdefender_security_tab_firewall'],
        'login'       => ['🔐 Đăng nhập & Form',  'adsdefender_security_tab_login'],
        'hardening'   => ['🔧 Server & WP',       'adsdefender_security_tab_hardening'],
        'threat'      => ['🌐 Threat Intel',      'adsdefender_security_tab_threat'],
        'scanner'     => ['🦠 Quét mã độc',       'adsdefender_security_tab_scanner'],
        'filemonitor' => ['🔍 File Monitor',      'adsdefender_security_tab_filemonitor'],
        'events'      => ['🔒 Security Events',   'adsdefender_page_seclog'],
        'adminlog'    => ['📋 Admin Log',         'adsdefender_security_tab_adminlog'],
    ], 'firewall');
}

// ─── 🚀 Marketing ─────────────────────────────────────────────────────────────

function adsdefender_page_marketing(): void
{
    adsdefender_render_tabbed_page('adsdefender-marketing', '🚀 AdsDefender — Marketing', [
        'contact' => ['📞 Contact Bar',          'adsdefender_page_contact'],
        'popup'   => ['💬 Popup & Lead',         'adsdefender_page_popup_form'],
        'leads'   => ['📥 Leads',                'adsdefender_page_leads'],
        'utm'     => ['📊 UTM & Attribution',    'adsdefender_page_utm'],
        'scripts' => ['📝 Scripts & Tracking',   'adsdefender_page_scripts'],
    ], 'contact');
}

// ─── ⚙️ Cài đặt chung ─────────────────────────────────────────────────────────

function adsdefender_page_general(): void
{
    $remote  = adsdefender_fetch_update_info();
    $has_upd = $remote && version_compare($remote['version'] ?? '0', ADSDEFENDER_VERSION, '>');

    adsdefender_render_tabbed_page('adsdefender-settings', '⚙️ AdsDefender — Cài đặt chung', [
        'modules'   => ['🧩 Module',             'adsdefender_settings_tab_modules'],
        'blockpage' => ['🚫 Trang chặn',         'adsdefender_settings_tab_blockpage'],
        'notify'    => ['📲 Thông báo Telegram', 'adsdefender_settings_tab_notify'],
        'access'  => ['🔑 Phân quyền',         'adsdefender_page_access'],
        'update'  => ['⬆️ Cập nhật' . ($has_upd ? adsdefender_menu_badge(1) : ''), 'adsdefender_page_update'],
    ], 'modules');
}
