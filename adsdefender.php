<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * Plugin Name:  AdsDefender
 * Plugin URI:   https://saigon.pro
 * Description:  Bảo vệ ngân sách quảng cáo Google Ads khỏi click fraud — tự động sync IP từ Matomo,
 *               block ở tầng PHP, tích hợp marketing & tracking đầy đủ.
 * Version:      2.5.131
 * Author:       saigon.pro
 * Author URI:   https://saigon.pro
 * Donate link:  https://www.paypal.com/paypalme/dangngocsoncom
 * Copyright:    (c) 2026 saigon.pro
 * Update URI:   https://github.com/rubidev/adsdefender-wp-plugin
 * Requires PHP: 7.4
 *
 * ════════════════════════════════════════════════════════════════════════════════
 *  TÍNH NĂNG CHÍNH
 * ════════════════════════════════════════════════════════════════════════════════
 *
 *  🛡  CHẶN CLICK FRAUD
 *      • Sync danh sách IP fraud từ Matomo AdsDefender mỗi 30 phút (WP-Cron)
 *      • Block ở 3 lớp: .htaccess (server-level), PHP (init hook), visitor cookie
 *      • Hỗ trợ IPv4, IPv6, CIDR (vd: 1.2.3.0/24, 2405::/32)
 *      • 3 hành động khi block: 403 Forbidden, Redirect, Trang trắng
 *      • Trang 403 tĩnh /adsdefender-blocked.html — không qua PHP, không redirect loop
 *      • Block thủ công: thêm IP/CIDR với ghi chú, không bị sync ghi đè
 *      • Visitor Blacklist: block theo Matomo visitor cookie dù đổi IP
 *      • Whitelist: IP/CIDR whitelist bảo vệ admin, văn phòng khỏi bị block nhầm
 *
 *  🌐  TƯƠNG THÍCH WEB SERVER
 *      • LiteSpeed Enterprise / Apache: ghi Deny vào .htaccess — block trước PHP
 *      • OpenLiteSpeed / Nginx: cookie bypass cache (adsdefender_nc=1)
 *      • Auto-detect SERVER_SOFTWARE hoặc chọn thủ công trong Settings
 *
 *  ☁️  CLOUDFLARE SUPPORT
 *      • Khi bật "Behind Cloudflare": lấy IP thật từ CF-Connecting-IP
 *      • Tự xác minh REMOTE_ADDR thuộc dải Cloudflare (bundle 23 CIDR ranges)
 *        trước khi tin header → chống spoofing header
 *
 *  🔗  CROSS-SITE BLOCK SHARING
 *      • Gán tag cho site (vd: "dien-lanh") — sites cùng tag chia sẻ block list
 *      • IP fraud site A → tự block trên tất cả sites cùng tag
 *
 *  🚀  MARKETING
 *      • Contact Bar: thanh liên hệ cố định (call/Zalo/Messenger) cấu hình trong admin
 *      • Popup & Lead: popup tùy chỉnh, thu thập lead vào DB
 *      • Leads Manager: xem, filter, export leads
 *      • UTM Attribution: ghi lại UTM source/medium/campaign mỗi session
 *
 *  📊  TRACKING TÍCH HỢP (1-click bật tắt)
 *      • Google Tag Manager (GTM)
 *      • Matomo / TrackSG
 *      • Meta Pixel (Facebook)
 *
 *  📝  CUSTOM SCRIPTS
 *      • Inject script vào: <head>, sau <body>, body bottom, trước </body>
 *      • Dùng cho chat widget, schema JSON-LD, phone tracking...
 *
 *  📲  THÔNG BÁO TELEGRAM
 *      • Alert khi có IP mới bị block (mỗi lần cron)
 *      • Alert khi có lead mới
 *
 *  🔄  TỰ ĐỘNG CẬP NHẬT
 *      • Hiển thị badge update trong WordPress admin khi có phiên bản mới
 *      • Cập nhật 1-click từ GitHub release — không cần vào WP.org
 *
 * ════════════════════════════════════════════════════════════════════════════════
 *  CÀI ĐẶT
 * ════════════════════════════════════════════════════════════════════════════════
 *
 *  1. Upload thư mục adsdefender/ vào /wp-content/plugins/
 *  2. Kích hoạt plugin trong WordPress → Plugins
 *  3. Vào AdsDefender → Hệ thống → Cài đặt:
 *       • Matomo URL: https://track.saigon.pro/
 *       • Secret Key: lấy tại Matomo → Personal Settings → Security → Auth token
 *       • Site ID: bấm "Tự động phát hiện" hoặc nhập tay
 *       • Web Server: chọn đúng loại server (hoặc để Auto)
 *       • Bật chặn IP: tick checkbox
 *  4. Bấm "Sync ngay" để lấy danh sách IP đầu tiên
 *
 * ════════════════════════════════════════════════════════════════════════════════
 *  CẤU TRÚC FILE
 * ════════════════════════════════════════════════════════════════════════════════
 *
 *  adsdefender.php          — Plugin entry, hằng số, bộ nạp module, activation hooks
 *  includes/                  [lõi] luôn nạp · [BM] module Bảo mật · [MK] module Marketing
 *    core.php               — [lõi] IP check, sync Matomo, .htaccess, REST API, site groups
 *    security-log.php       — [lõi] Nhật ký sự kiện bảo mật tập trung
 *    block-page.php         — [lõi] Trang chặn dùng chung + mã tham chiếu
 *    live-traffic.php       — [lõi] Ghi & xem truy cập thời gian thực (mặc định tắt)
 *    threat-intel.php       — [BM] Đồng bộ Spamhaus DROP / FireHOL / Tor, chặn theo nguồn
 *    scripts.php            — [lõi] GTM, TrackSG, Meta Pixel, custom scripts
 *    update.php             — [lõi] Tự cập nhật từ GitHub release
 *    firewall.php           — [BM] Request firewall: SQLi, XSS, path traversal, bad UA
 *    brute-force.php, rate-limit.php, honeypot.php, 404-detector.php, recaptcha.php,
 *    hide-login.php, comment-security.php, system-tweaks.php, wp-tweaks.php,
 *    file-monitor.php, malware-scanner.php, config-audit.php, admin-log.php — [BM]
 *    contact-bar.php, popup.php, utm.php — [MK]
 *  admin/
 *    menu.php               — Đăng ký menu + các trang có tab
 *    common.php             — Quyền Manager, lưu cài đặt theo mục, chuyển hướng URL cũ
 *    pages/dashboard.php    — 📊 Tổng quan
 *    pages/fraud.php        — 🎯 Chống Click Fraud
 *    pages/security.php     — 🛡 Bảo mật
 *    pages/marketing.php    — 🚀 Marketing (Scripts & Tracking)
 *    pages/settings.php     — ⚙️ Cài đặt chung (Module, Telegram, Phân quyền, Cập nhật)
 *
 * ════════════════════════════════════════════════════════════════════════════════
 *  CHANGELOG: xem CHANGELOG.md trong repo (để ngoài file PHP cho scanner
 *  malware trên hosting không báo nhầm vì các từ khoá kỹ thuật bảo mật).
 * ════════════════════════════════════════════════════════════════════════════════
 */

if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_VERSION',        '2.5.131');
define('ADSDEFENDER_DIR',            plugin_dir_path(__FILE__));
define('ADSDEFENDER_OPTION_IPS',     'adsdefender_blocked_ips');
define('ADSDEFENDER_OPTION_UPDATED', 'adsdefender_last_sync');
define('ADSDEFENDER_OPTION_MANUAL',  'adsdefender_manual_ips');
define('ADSDEFENDER_OPTION_SCRIPTS', 'adsdefender_scripts');
define('ADSDEFENDER_OPTION_CONTACT', 'adsdefender_contact_bar');
define('ADSDEFENDER_OPTION_POPUP',   'adsdefender_popups');
define('ADSDEFENDER_UTM_TABLE',      'adsdefender_utm_log');
define('ADSDEFENDER_LEAD_TABLE',     'adsdefender_leads');
define('ADSDEFENDER_CRON_HOOK',      'adsdefender_sync_cron');
define('ADSDEFENDER_LOG_TABLE',      'adsdefender_block_log');
define('ADSDEFENDER_OPTION_VISITORS','adsdefender_blocked_visitors');
define('ADSDEFENDER_LOCKOUT_TABLE',  'adsdefender_lockouts');
define('ADSDEFENDER_UPDATE_URL',     'https://raw.githubusercontent.com/rubidev/adsdefender-wp-plugin/main/adsdefender-update.json');
define('ADSDEFENDER_PLUGIN_SLUG',    plugin_basename(__FILE__));

define('ADSDEFENDER_OPTION_MODULES', 'adsdefender_modules');

// ─── Module ───────────────────────────────────────────────────────────────────
//
// Chống click fraud là lõi, luôn chạy. Bảo mật và Marketing là module bật/tắt
// được ở Cài đặt chung → Module. Module tắt thì file của nó KHÔNG được nạp:
// site nhẹ hơn và không có hook nào của module đó chạy.
//
// scripts.php luôn nạp dù nằm ở menu Marketing: nó chèn mã TrackSG (Matomo) —
// nguồn dữ liệu để phát hiện click fraud. Tắt Marketing không được làm mất nó.

function adsdefender_modules_registry(): array
{
    return [
        'security' => [
            'label' => '🛡 Bảo mật',
            'desc'  => 'Firewall, chống brute force, rate limit, CAPTCHA, ẩn trang đăng nhập, hardening .htaccess, security headers, quét mã độc, theo dõi thay đổi file, Admin Log.',
        ],
        'marketing' => [
            'label' => '🚀 Marketing',
            'desc'  => 'Contact Bar, Popup & thu thập Lead, UTM & Attribution, REST API /conversion và /lead.',
        ],
    ];
}

function adsdefender_module_enabled(string $module): bool
{
    $mods = get_option(ADSDEFENDER_OPTION_MODULES, []);
    // Chưa từng cấu hình → bật hết, để site đang chạy giữ nguyên hành vi khi cập nhật
    return !is_array($mods) || !array_key_exists($module, $mods) || !empty($mods[$module]);
}

// Thứ tự nạp giữ nguyên như trước khi có module (thứ tự đăng ký hook không đổi)
foreach ([
    ['core.php',             null],
    ['block-page.php',       null],        // trang chặn dùng chung cho mọi module
    ['live-traffic.php',     null],        // ghi truy cập — tự tắt khi lt_enabled = 0
    ['security-log.php',     null],        // dashboard đọc thống kê kể cả khi tắt Bảo mật
    ['geo.php',              null],        // vị trí IP (ip-api.com) cho các trang danh sách IP
    ['admin-log.php',        'security'],
    ['brute-force.php',      'security'],
    ['firewall.php',         'security'],
    ['hide-login.php',       'security'],
    ['login-geo.php',        'security'],      // chỉ cho đăng nhập từ VN (dùng hàm của threat-intel.php)
    ['rate-limit.php',       'security'],
    ['honeypot.php',         'security'],
    ['404-detector.php',     'security'],
    ['system-tweaks.php',    'security'],
    ['wp-tweaks.php',        'security'],
    ['recaptcha.php',        'security'],
    ['scripts.php',          null],
    ['file-monitor.php',     'security'],
    ['malware-scanner.php',  'security'],
    ['config-audit.php',     'security'],
    ['comment-security.php', 'security'],
    ['threat-intel.php',     'security'],
    ['update.php',           null],
    ['utm.php',              'marketing'],
    ['contact-bar.php',      'marketing'],
    ['popup.php',            'marketing'],
] as [$adsdefender_file, $adsdefender_module]) {
    if ($adsdefender_module === null || adsdefender_module_enabled($adsdefender_module)) {
        // Scanner malware trên hosting (cPGuard, ImunifyAV…) có thể cách ly nhầm
        // một file → require_once sẽ fatal làm sập CẢ site. Bỏ qua file thiếu,
        // tính năng đó tạm tắt, và báo cho admin.
        if (is_readable(ADSDEFENDER_DIR . 'includes/' . $adsdefender_file)) {
            require_once ADSDEFENDER_DIR . 'includes/' . $adsdefender_file;
        } else {
            $GLOBALS['adsdefender_missing_files'][] = 'includes/' . $adsdefender_file;
        }
    }
}
unset($adsdefender_file, $adsdefender_module);

if (!empty($GLOBALS['adsdefender_missing_files'])) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error"><p><strong>AdsDefender:</strong> thiếu file '
            . esc_html(implode(', ', $GLOBALS['adsdefender_missing_files']))
            . ' — có thể bị scanner bảo mật của hosting cách ly nhầm. Tính năng liên quan đang tạm tắt. '
            . 'Hãy cập nhật / cài lại AdsDefender bản mới nhất và báo "false positive" cho hosting.</p></div>';
    });
}

require_once ADSDEFENDER_DIR . 'admin/menu.php';

/**
 * Tạo mọi bảng DB của các module đang nạp. Các hàm đều dùng
 * CREATE TABLE IF NOT EXISTS nên gọi lại bao nhiêu lần cũng an toàn.
 */
function adsdefender_install_tables(): void
{
    foreach ([
        'adsdefender_create_log_table', 'adsdefender_create_lockouts_table', 'adsdefender_create_sec_log_table',
        'adsdefender_create_utm_table', 'adsdefender_create_lead_table',
        'adsdefender_create_bf_table', 'adsdefender_create_admin_log_table',
        'adsdefender_fm_create_table', 'adsdefender_scan_create_table', 'adsdefender_lt_create_table',
        'adsdefender_geo_create_table',
    ] as $fn) {
        if (function_exists($fn)) $fn();
    }
}

// Sau khi đổi module: request kế tiếp (lúc file module mới bật đã được nạp) tạo
// bảng còn thiếu, dựng lại .htaccess hardening và flush rewrite của Hide Login.
add_action('init', function () {
    if (!get_option('adsdefender_modules_pending')) return;
    delete_option('adsdefender_modules_pending');
    adsdefender_install_tables();
    if (function_exists('adsdefender_st_update_htaccess')) adsdefender_st_update_htaccess();
    flush_rewrite_rules(false);
}, 99);

// ─── Activation / Deactivation ────────────────────────────────────────────────

register_activation_hook(__FILE__, function () {
    adsdefender_install_tables();
    if (!wp_next_scheduled(ADSDEFENDER_CRON_HOOK)) {
        wp_schedule_event(time(), 'adsdefender_30min', ADSDEFENDER_CRON_HOOK);
    }
    adsdefender_configure_litespeed_cache();
    // Hide Login và System Tweaks thuộc module Bảo mật — có thể đang tắt
    if (function_exists('adsdefender_hl_flush_rules'))     adsdefender_hl_flush_rules();
    if (function_exists('adsdefender_st_update_htaccess')) adsdefender_st_update_htaccess();
    adsdefender_sync_now();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(ADSDEFENDER_CRON_HOOK);
    if (function_exists('adsdefender_st_remove_htaccess')) adsdefender_st_remove_htaccess();
});
