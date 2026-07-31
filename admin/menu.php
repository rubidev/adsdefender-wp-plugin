<?php
if (!defined('ABSPATH')) exit;

// ─── Admin Menu ───────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    $log_bubble = adsdefender_unread_log_count();
    $log_badge  = $log_bubble ? " <span class='awaiting-mod'>{$log_bubble}</span>" : '';

    $remote    = adsdefender_fetch_update_info();
    $has_upd   = $remote && version_compare($remote['version'] ?? '0', ADSDEFENDER_VERSION, '>');
    $upd_badge = $has_upd ? " <span class='update-plugins count-1'><span class='update-count'>1</span></span>" : '';

    add_menu_page('AdsDefender', 'AdsDefender' . $log_badge . $upd_badge,
        'manage_options', 'adsdefender', 'adsdefender_page_dashboard', 'dashicons-shield', 80);

    add_submenu_page('adsdefender', 'Tổng quan',  '📊 Tổng quan',               'manage_options', 'adsdefender',          'adsdefender_page_dashboard');
    add_submenu_page('adsdefender', 'Marketing',   '🚀 Marketing' . $log_badge,  'manage_options', 'adsdefender-marketing', 'adsdefender_page_marketing');
    add_submenu_page('adsdefender', 'Bảo vệ',      '🛡 Bảo vệ',                  'manage_options', 'adsdefender-protect',   'adsdefender_page_protect');
    add_submenu_page('adsdefender', 'Hệ thống',    '⚙️ Hệ thống' . $upd_badge,   'manage_options', 'adsdefender-system',    'adsdefender_page_system');
});

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
        wp_safe_redirect(add_query_arg(['page' => 'adsdefender-system', 'tab' => 'access', 'updated' => 1], admin_url('admin.php')));
        exit;
    }
});

function adsdefender_sanitize_settings(array $in): array
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
        'site_id'      => (int) ($in['site_id'] ?? 0),
        'block_action' => in_array($in['block_action'] ?? '', ['redirect', '403', 'blank'])
                            ? $in['block_action'] : 'redirect',
        'redirect_url' => sanitize_text_field(trim($in['redirect_url'] ?? '')),
        'web_server'   => in_array($in['web_server'] ?? '', ['openlitespeed', 'litespeed', 'nginx', 'apache', 'auto'])
                            ? $in['web_server'] : 'auto',
        'lock_url'           => sanitize_text_field(trim($in['lock_url'] ?? '')),
        'whitelist'          => $whitelist_val,
        'lockout_duration'   => max(5,  (int)($in['lockout_duration']   ?? 1440)),
        'ban_threshold'      => max(1,  (int)($in['ban_threshold']      ?? 3)),
        'ban_period'         => max(1,  (int)($in['ban_period']         ?? 7)),
        'ban_limit_htaccess' => max(10, (int)($in['ban_limit_htaccess'] ?? 100)),
        'abuseipdb_key'      => sanitize_text_field($in['abuseipdb_key'] ?? ''),
        'admin_log_enabled'  => !empty($in['admin_log_enabled']) ? 1 : 0,

        // ── Brute Force ──
        'bf_enabled'         => !empty($in['bf_enabled'])       ? 1 : 0,
        'bf_check_period'    => max(1,  (int)($in['bf_check_period']  ?? 5)),
        'bf_max_ip'          => max(1,  (int)($in['bf_max_ip']        ?? 5)),
        'bf_max_user'        => max(1,  (int)($in['bf_max_user']      ?? 10)),
        'bf_lockout_mins'    => max(5,  (int)($in['bf_lockout_mins']  ?? 30)),
        'bf_block_admin'     => !empty($in['bf_block_admin'])   ? 1 : 0,

        // ── Firewall ──
        'fw_enabled'         => !empty($in['fw_enabled'])       ? 1 : 0,
        'ua_enabled'         => !empty($in['ua_enabled'])       ? 1 : 0,

        // ── Hide Login ──
        'hl_enabled'         => !empty($in['hl_enabled'])       ? 1 : 0,
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
        'fm_depth'           => max(1, min(10, (int)($in['fm_depth'] ?? 3))),

        // ── Malware Scanner ──
        'scan_enabled'       => !empty($in['scan_enabled'])     ? 1 : 0,

        // ── Comment Security ──
        'cs_enabled'         => !empty($in['cs_enabled'])       ? 1 : 0,
        'cs_rate_limit'      => max(1, (int)($in['cs_rate_limit'] ?? 5)),
        'cs_blocklist'       => !empty($in['cs_blocklist'])     ? 1 : 0,
    ];
}

// ─── Dashboard ────────────────────────────────────────────────────────────────

function adsdefender_page_dashboard()
{
    $opts    = adsdefender_settings();
    $count   = count(get_option(ADSDEFENDER_OPTION_IPS, []));
    $manual  = count(get_option(ADSDEFENDER_OPTION_MANUAL, []));
    $updated = get_option(ADSDEFENDER_OPTION_UPDATED, '');
    $error   = get_option('adsdefender_last_error', '');
    global $wpdb;
    $lt = $wpdb->prefix . ADSDEFENDER_LEAD_TABLE;
    $bt = $wpdb->prefix . ADSDEFENDER_LOG_TABLE;
    $ut = $wpdb->prefix . ADSDEFENDER_UTM_TABLE;
    $lead_new  = $wpdb->get_var("SHOW TABLES LIKE '{$lt}'")===$lt ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$lt}` WHERE status=%s", 'new')) : 0;
    $blocks_7d = $wpdb->get_var("SHOW TABLES LIKE '{$bt}'")===$bt ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$bt}` WHERE blocked_at >= %s", date('Y-m-d H:i:s', strtotime('-7 days')))) : 0;
    $utm_7d    = $wpdb->get_var("SHOW TABLES LIKE '{$ut}'")===$ut ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$ut}` WHERE created_at >= %s", date('Y-m-d H:i:s', strtotime('-7 days')))) : 0;
    $conv_7d   = $wpdb->get_var("SHOW TABLES LIKE '{$ut}'")===$ut ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$ut}` WHERE created_at >= %s AND conversion_type != ''", date('Y-m-d H:i:s', strtotime('-7 days')))) : 0;
    $visitors  = count(get_option(ADSDEFENDER_OPTION_VISITORS, []));
    // Debug cache config
    if (isset($_GET['adsdefender_fix_cache']) && check_admin_referer('adsdefender_fix_cache')) {
        $fixed = adsdefender_configure_cache_plugins();
        if ($fixed) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Đã cấu hình cache: <strong>' . implode(', ', $fixed) . '</strong> — thêm _pk_id + adsdefender_nc vào Do Not Cache Cookies.</p></div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>⚠️ Không tìm thấy cache plugin nào cần cấu hình (đã đúng rồi hoặc không dùng cache plugin được hỗ trợ).</p></div>';
        }
    }
    ?>
<div class="wrap">
<h1>🛡 AdsDefender <small style="font-size:13px;color:#999">v<?php echo ADSDEFENDER_VERSION; ?></small></h1>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px;max-width:900px">
<?php
$cards = [
    ['🛡 IP Đang Block',      $count,     '#2271b1', admin_url('admin.php?page=adsdefender-protect&tab=log')],
    ['🍪 Visitor Blacklist',  $visitors,  $visitors > 0 ? '#d63638' : '#888', admin_url('admin.php?page=adsdefender-protect&tab=log')],
    ['✋ Block Thủ Công',     $manual,    '#f0860a', admin_url('admin.php?page=adsdefender-protect&tab=manual')],
    ['🚫 Block 7 ngày',       $blocks_7d, '#d63638', admin_url('admin.php?page=adsdefender-protect&tab=log')],
    ['👥 Sessions 7 ngày',    $utm_7d,    '#2271b1', admin_url('admin.php?page=adsdefender-marketing&tab=utm')],
    ['✅ Conv. 7 ngày',       $conv_7d,   '#00a32a', admin_url('admin.php?page=adsdefender-marketing&tab=utm')],
    ['📥 Leads mới',          $lead_new,  $lead_new > 0 ? '#d63638' : '#888', admin_url('admin.php?page=adsdefender-marketing&tab=leads')],
];
foreach ($cards as [$lbl, $val, $col, $url]): ?>
<a href="<?php echo esc_url($url); ?>" style="text-decoration:none">
  <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px 18px;border-top:3px solid <?php echo $col; ?>;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.1)'" onmouseout="this.style.boxShadow=''">
    <div style="font-size:26px;font-weight:700;color:<?php echo $col; ?>"><?php echo $val; ?></div>
    <div style="font-size:12px;color:#666;margin-top:4px"><?php echo $lbl; ?></div>
  </div>
</a>
<?php endforeach; ?>
</div>

<?php
// ── Cache Plugin Status ───────────────────────────────────────────────────────
$cache_status = [];

// LiteSpeed Cache
if (defined('LSCWP_V') || class_exists('LiteSpeed\Core')) {
    $ls_cookies = get_option('litespeed.conf.cache-exc_cookies', '');
    $pk_ok  = strpos($ls_cookies, '_pk_id') !== false;
    $nc_ok  = strpos($ls_cookies, 'adsdefender_nc') !== false;
    $cache_status[] = [
        'name'  => 'LiteSpeed Cache ' . LSCWP_V,
        'pk'    => $pk_ok,
        'nc'    => $nc_ok,
        'value' => nl2br(esc_html($ls_cookies ?: '(trống)')),
    ];
}
if (defined('WP_ROCKET_VERSION')) {
    $r = get_option('wp_rocket_settings', []);
    $cookies = (array)($r['cache_reject_cookies'] ?? []);
    $cache_status[] = [
        'name'  => 'WP Rocket ' . WP_ROCKET_VERSION,
        'pk'    => in_array('_pk_id', $cookies),
        'nc'    => in_array('adsdefender_nc', $cookies),
        'value' => esc_html(implode(', ', $cookies) ?: '(trống)'),
    ];
}
if (defined('W3TC')) {
    $r = get_option('w3tc_pgcache', []);
    $cookies = (array)($r['reject.cookie'] ?? []);
    $cache_status[] = [
        'name'  => 'W3 Total Cache',
        'pk'    => in_array('_pk_id', $cookies),
        'nc'    => in_array('adsdefender_nc', $cookies),
        'value' => esc_html(implode(', ', $cookies) ?: '(trống)'),
    ];
}

if (!empty($cache_status)):
    $fix_url = wp_nonce_url(add_query_arg('adsdefender_fix_cache', 1), 'adsdefender_fix_cache');
?>
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;margin-bottom:24px;max-width:900px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
    <h3 style="margin:0;font-size:14px">⚡ Cache Plugin — Do Not Cache Cookies</h3>
    <a href="<?php echo esc_url($fix_url); ?>" class="button button-small">🔧 Tự động sửa</a>
  </div>
  <table class="widefat" style="font-size:13px">
    <thead><tr><th>Plugin</th><th>_pk_id</th><th>adsdefender_nc</th><th>Cookies hiện tại</th></tr></thead>
    <tbody>
    <?php foreach ($cache_status as $cs): ?>
    <tr>
      <td><strong><?php echo esc_html($cs['name']); ?></strong></td>
      <td><?php echo $cs['pk'] ? '<span style="color:#00a32a">✅ OK</span>' : '<span style="color:#d63638">❌ Chưa có</span>'; ?></td>
      <td><?php echo $cs['nc'] ? '<span style="color:#00a32a">✅ OK</span>' : '<span style="color:#d63638">❌ Chưa có</span>'; ?></td>
      <td style="font-family:monospace;font-size:11px;color:#666"><?php echo $cs['value']; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  $all_ok = array_reduce($cache_status, fn($carry, $cs) => $carry && $cs['pk'] && $cs['nc'], true);
  if (!$all_ok): ?>
  <p style="margin:10px 0 0;color:#d63638;font-size:13px">⚠️ Visitor Blacklist sẽ không hoạt động khi trang bị cache. Bấm <strong>"Tự động sửa"</strong> để cấu hình.</p>
  <?php else: ?>
  <p style="margin:10px 0 0;color:#00a32a;font-size:13px">✅ Cache plugin đã cấu hình đúng — Visitor Blacklist hoạt động bình thường.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div style="background:linear-gradient(135deg,#0f1117 0%,#1a1d27 100%);border-radius:10px;padding:24px 28px;margin-bottom:24px;max-width:900px;position:relative;overflow:hidden">
  <div style="position:absolute;top:-30px;right:-30px;width:180px;height:180px;background:radial-gradient(circle,#e53e3e22 0%,transparent 70%);pointer-events:none"></div>
  <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap">
    <div style="flex:1;min-width:260px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <span style="font-size:28px">🛡</span>
        <div>
          <div style="font-size:20px;font-weight:700;color:#fff;line-height:1.2">AdsDefender</div>
          <div style="font-size:12px;color:#64748b;margin-top:2px">v<?php echo ADSDEFENDER_VERSION; ?> &nbsp;·&nbsp; Bảo vệ ngân sách Google Ads</div>
        </div>
      </div>
      <p style="color:#94a3b8;font-size:13px;line-height:1.7;margin:0 0 14px">
        Tự động phát hiện và chặn IP <strong style="color:#f87171">click fraud</strong> từ Matomo — block ở tầng server trước khi PHP chạy,
        không ảnh hưởng tốc độ website. Tích hợp marketing, tracking và Telegram alert.
      </p>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php
        $features = [
            ['🔄', 'Sync 30 phút/lần'],
            ['🌐', '.htaccess server-level'],
            ['☁️', 'Cloudflare-aware'],
            ['🍪', 'Visitor cookie block'],
            ['🔗', 'Cross-site sharing'],
            ['📲', 'Telegram alert'],
        ];
        foreach ($features as [$icon, $label]):
        ?>
        <span style="display:inline-flex;align-items:center;gap:4px;background:#ffffff12;border:1px solid #ffffff18;border-radius:20px;padding:3px 10px;font-size:12px;color:#cbd5e1">
            <?php echo $icon; ?> <?php echo $label; ?>
        </span>
        <?php endforeach; ?>
      </div>
    </div>
    <div style="min-width:200px;background:#ffffff08;border:1px solid #ffffff12;border-radius:8px;padding:14px 16px">
      <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#475569;margin-bottom:10px">Luồng hoạt động</div>
      <?php
      $steps = [
          ['🎯', 'Google Ads click',      '#64748b'],
          ['📊', 'Matomo ghi nhận fraud', '#64748b'],
          ['🔄', 'Sync → WP nhận IP',     '#2271b1'],
          ['⛔', '.htaccess block',        '#d63638'],
          ['📄', 'Trang 403 tĩnh',        '#00a32a'],
      ];
      foreach ($steps as $i => [$icon, $label, $col]):
      ?>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:<?php echo $i < count($steps)-1 ? '6' : '0'; ?>px">
        <span style="font-size:14px"><?php echo $icon; ?></span>
        <span style="font-size:12px;color:#94a3b8"><?php echo $label; ?></span>
        <?php if ($i < count($steps)-1): ?>
        <span style="flex:1;border-top:1px dashed #334155"></span>
        <span style="font-size:9px;color:#334155">↓</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px">
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px">
  <h3 style="margin-top:0">⚡ Trạng thái</h3>
  <table class="widefat" style="font-size:13px">
    <tr><th style="width:160px">Chặn IP</th><td><?php echo !empty($opts['enabled']) ? '<span style="color:#00a32a">✅ Đang bật</span>' : '<span style="color:#d63638">❌ Tắt</span>'; ?></td></tr>
    <tr><th>Sync gần nhất</th><td><?php echo esc_html($updated ?: 'Chưa sync'); ?></td></tr>
    <tr><th>IP Matomo</th><td><strong><?php echo $count; ?></strong> IPs</td></tr>
    <tr><th>Hành động block</th><td><?php echo esc_html($opts['block_action'] ?? 'redirect'); ?></td></tr>
    <?php if ($error): ?><tr><th>Lỗi</th><td style="color:#d63638"><?php echo esc_html($error); ?></td></tr><?php endif; ?>
  </table>
</div>

<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px">
  <h3 style="margin-top:0">🔗 Truy cập nhanh</h3>
  <div style="display:flex;flex-direction:column;gap:8px">
    <?php $links = [
      ['🚀 Marketing — Popup, UTM, Contact Bar', admin_url('admin.php?page=adsdefender-marketing')],
      ['🛡 Bảo vệ — Block Log, Whitelist, Block thủ công', admin_url('admin.php?page=adsdefender-protect')],
      ['⚙️ Hệ thống — Scripts, Tracking, Cập nhật', admin_url('admin.php?page=adsdefender-system')],
    ];
    foreach ($links as [$l, $u]): ?>
    <a href="<?php echo esc_url($u); ?>" style="padding:8px 12px;background:#f6f7f7;border-radius:4px;text-decoration:none;color:#2271b1;font-size:13px;border:1px solid #e0e0e0">
      <?php echo $l; ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
</div>

<?php
// ── Security Stats (7 ngày) ───────────────────────────────────────────────────
$sec = adsdefender_sec_stats(7);
$type_labels = [
    'brute_force' => ['🔐 Brute Force', '#d63638'],
    'firewall'    => ['🔥 Firewall',    '#f0860a'],
    'rate_limit'  => ['⏱ Rate Limit',  '#2271b1'],
    'flood_404'   => ['🔍 404 Flood',   '#888888'],
    'ip_block'    => ['🚫 IP Block',    '#6741d9'],
];
if ($sec['total'] > 0):
?>
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-top:20px;max-width:900px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h3 style="margin:0">🔒 Security Events — 7 ngày qua (<?php echo $sec['total']; ?> sự kiện)</h3>
    <a href="<?php echo esc_url(admin_url('admin.php?page=adsdefender-protect&tab=seclog')); ?>" class="button button-small">Xem log đầy đủ →</a>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:16px">
  <?php foreach ($sec['by_type'] as $row):
    [$lbl, $col] = $type_labels[$row['event_type']] ?? [$row['event_type'], '#888'];
  ?>
  <div style="background:#f6f7f7;border-left:3px solid <?php echo $col; ?>;border-radius:4px;padding:10px 12px">
    <div style="font-size:20px;font-weight:700;color:<?php echo $col; ?>"><?php echo (int)$row['cnt']; ?></div>
    <div style="font-size:11px;color:#666;margin-top:2px"><?php echo esc_html($lbl); ?></div>
  </div>
  <?php endforeach; ?>
  </div>

  <?php if (!empty($sec['top_ips'])): ?>
  <h4 style="margin:0 0 8px;font-size:13px">Top IPs tấn công</h4>
  <table class="widefat" style="font-size:12px">
    <thead><tr><th>IP</th><th>Sự kiện</th><th>Loại</th><th>Lần cuối</th><th>Tra cứu</th></tr></thead>
    <tbody>
    <?php foreach ($sec['top_ips'] as $row): ?>
    <tr>
      <td><code><?php echo esc_html($row['ip']); ?></code></td>
      <td><strong><?php echo (int)$row['cnt']; ?></strong></td>
      <td style="color:#666;font-size:11px"><?php echo esc_html($row['types']); ?></td>
      <td style="color:#999;font-size:11px"><?php echo esc_html(substr($row['last_seen'], 0, 16)); ?></td>
      <td><a href="#" class="ads-ip-rep button button-small" data-ip="<?php echo esc_attr($row['ip']); ?>">🔍</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

</div>
</div>
<?php
}

// ─── Grouped page: Marketing ──────────────────────────────────────────────────

function adsdefender_page_marketing()
{
    $tab  = sanitize_key($_GET['tab'] ?? 'contact');
    $tabs = [
        'contact' => '📞 Contact Bar',
        'popup'   => '💬 Popup & Lead',
        'leads'   => '📥 Leads',
        'utm'     => '📊 UTM & Attribution',
    ];
    echo '<div class="wrap"><h1>🚀 AdsDefender — Marketing</h1>';
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
    foreach ($tabs as $k => $label) {
        $url = esc_url(add_query_arg(['page' => 'adsdefender-marketing', 'tab' => $k], admin_url('admin.php')));
        echo '<a href="' . $url . '" class="nav-tab ' . ($tab === $k ? 'nav-tab-active' : '') . '">' . $label . '</a>';
    }
    echo '</nav>';
    match ($tab) {
        'contact' => adsdefender_tab_contact(),
        'popup'   => adsdefender_tab_popup(),
        'leads'   => adsdefender_tab_leads(),
        'utm'     => adsdefender_tab_utm(),
        default   => adsdefender_tab_contact(),
    };
    echo '</div>';
}

// ─── Grouped page: Protect ────────────────────────────────────────────────────

function adsdefender_page_protect()
{
    $tab  = sanitize_key($_GET['tab'] ?? 'log');
    $tabs = [
        'log'       => '🚫 Block Log',
        'seclog'    => '🔒 Security Events',
        'manual'    => '✋ Block Thủ Công',
        'whitelist' => '✅ Whitelist',
    ];
    $log_bubble = adsdefender_unread_log_count();
    if ($log_bubble) $tabs['log'] .= " <span class='awaiting-mod'>{$log_bubble}</span>";

    echo '<div class="wrap"><h1>🛡 AdsDefender — Bảo vệ</h1>';
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
    foreach ($tabs as $k => $label) {
        $url = esc_url(add_query_arg(['page' => 'adsdefender-protect', 'tab' => $k], admin_url('admin.php')));
        echo '<a href="' . $url . '" class="nav-tab ' . ($tab === $k ? 'nav-tab-active' : '') . '">' . $label . '</a>';
    }
    echo '</nav>';
    match ($tab) {
        'log'       => adsdefender_tab_log(),
        'seclog'    => adsdefender_page_seclog(),
        'manual'    => adsdefender_tab_manual(),
        'whitelist' => adsdefender_tab_whitelist(),
        default     => adsdefender_tab_log(),
    };
    echo '</div>';
}

// ─── Grouped page: System ─────────────────────────────────────────────────────

function adsdefender_page_system()
{
    $tab     = sanitize_key($_GET['tab'] ?? 'settings');
    $remote  = adsdefender_fetch_update_info();
    $has_upd = $remote && version_compare($remote['version'] ?? '0', ADSDEFENDER_VERSION, '>');
    $tabs    = [
        'settings'    => '⚙️ Cài đặt',
        'scripts'     => '📝 Scripts & Tracking',
        'adminlog'    => '📋 Admin Log',
        'filemonitor' => '🔍 File Monitor',
        'sitescanner' => '🦠 Site Scanner',
        'access'      => '🔑 Phân quyền',
        'update'      => '⬆️ Cập nhật' . ($has_upd ? " <span class='update-plugins count-1'><span class='update-count'>1</span></span>" : ''),
    ];
    echo '<div class="wrap"><h1>⚙️ AdsDefender — Hệ thống</h1>';
    echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px">';
    foreach ($tabs as $k => $label) {
        $url = esc_url(add_query_arg(['page' => 'adsdefender-system', 'tab' => $k], admin_url('admin.php')));
        echo '<a href="' . $url . '" class="nav-tab ' . ($tab === $k ? 'nav-tab-active' : '') . '">' . $label . '</a>';
    }
    echo '</nav>';
    match ($tab) {
        'settings'    => adsdefender_tab_settings(),
        'scripts'     => adsdefender_tab_scripts(),
        'adminlog'    => adsdefender_page_adminlog(),
        'filemonitor' => adsdefender_page_filemonitor(),
        'sitescanner' => adsdefender_page_sitescanner(),
        'access'      => adsdefender_page_access(),
        'update'      => adsdefender_tab_update(),
        default       => adsdefender_tab_settings(),
    };
    echo '</div>';
}

// ─── Tab alias functions ──────────────────────────────────────────────────────

function adsdefender_tab_settings()  { adsdefender_page_settings(); }
function adsdefender_tab_scripts()   { adsdefender_page_scripts(); }
function adsdefender_tab_update()    { adsdefender_page_update(); }
function adsdefender_tab_log()       { adsdefender_page_log(); }
function adsdefender_tab_manual()    { adsdefender_page_manual(); }
function adsdefender_tab_whitelist() { adsdefender_page_whitelist(); }
function adsdefender_tab_contact()   { adsdefender_page_contact(); }
function adsdefender_tab_popup()     { adsdefender_page_popup_form(); }
function adsdefender_tab_leads()     { adsdefender_page_leads(); }
function adsdefender_tab_utm()       { adsdefender_page_utm(); }

// ─── Trang Cài đặt ───────────────────────────────────────────────────────────

function adsdefender_page_settings()
{
    $opts    = adsdefender_settings();
    $updated = get_option(ADSDEFENDER_OPTION_UPDATED, '');
    $count   = count(get_option(ADSDEFENDER_OPTION_IPS, []));
    $error   = get_option('adsdefender_last_error', '');
    $stab    = sanitize_key($_GET['stab'] ?? 'connect');

    if (isset($_POST['adsdefender_sync_now']) && check_admin_referer('adsdefender_sync')) {
        adsdefender_sync_now();
        $updated = get_option(ADSDEFENDER_OPTION_UPDATED, '');
        $count   = count(get_option(ADSDEFENDER_OPTION_IPS, []));
        $error   = get_option('adsdefender_last_error', '');
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã sync — <strong>' . $count . '</strong> IPs.</p></div>';
    }

    $test_ip       = sanitize_text_field($_POST['adsdefender_test_ip'] ?? '');
    $test_vid      = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $_POST['adsdefender_test_vid'] ?? ''));
    $test_result   = null;
    $test_vid_result = null;

    // ── Kiểm tra IP ──
    if ($test_ip && isset($_POST['adsdefender_do_test']) && check_admin_referer('adsdefender_test')) {
        if (!filter_var($test_ip, FILTER_VALIDATE_IP)) {
            $test_result = ['status' => 'error', 'msg' => 'IP không hợp lệ'];
        } else {
            $blocked   = get_option(ADSDEFENDER_OPTION_IPS, []);
            $manual    = get_option(ADSDEFENDER_OPTION_MANUAL, []);
            $whitelist = array_filter(array_map('trim', explode("\n", $opts['whitelist'] ?? '')));
            if (!empty($whitelist) && adsdefender_ip_matches($test_ip, $whitelist)) {
                $test_result = ['status' => 'whitelist', 'msg' => '✅ PASS — IP nằm trong Whitelist, sẽ không bị chặn.'];
            } elseif (!empty($manual) && adsdefender_ip_matches($test_ip, array_column($manual, 'ip'))) {
                $matched = '';
                foreach ($manual as $m) {
                    if (adsdefender_ip_matches($test_ip, [$m['ip']])) { $matched = $m['ip']; break; }
                }
                $note = $manual[array_search($matched, array_column($manual, 'ip'))]['note'] ?? '';
                $test_result = ['status' => 'blocked', 'msg' => "🚫 BLOCKED (thủ công) — <code>" . esc_html($matched) . "</code>" . ($note ? " · <em>" . esc_html($note) . "</em>" : "")];
            } elseif (adsdefender_ip_matches($test_ip, $blocked)) {
                $matched = '';
                foreach ($blocked as $entry) {
                    $entry = trim($entry);
                    if ($entry === '') continue;
                    if (adsdefender_ip_matches($test_ip, [$entry])) { $matched = $entry; break; }
                }
                $test_result = ['status' => 'blocked', 'msg' => "🚫 BLOCKED (Matomo) — Khớp với entry: <code>" . esc_html($matched) . "</code>"];
            } else {
                $test_result = ['status' => 'pass', 'msg' => '✅ PASS — IP này không có trong bất kỳ danh sách block nào.'];
            }
        }
    }

    // ── Kiểm tra Visitor ID ──
    if ($test_vid && isset($_POST['adsdefender_do_test_vid']) && check_admin_referer('adsdefender_test_vid')) {
        if (strlen($test_vid) !== 16 || !ctype_xdigit($test_vid)) {
            $test_vid_result = ['status' => 'error', 'msg' => 'Visitor ID phải là 16 ký tự hex (vd: <code>efb92be6b33b2ded</code>)'];
        } else {
            $visitors = get_option(ADSDEFENDER_OPTION_VISITORS, []);
            if (isset($visitors[$test_vid])) {
                $test_vid_result = ['status' => 'blocked', 'msg' => "🚫 BLOCKED — Visitor ID <code>" . esc_html($test_vid) . "</code> có trong Visitor Blacklist. Sẽ bị chặn dù đổi IP."];
            } else {
                $test_vid_result = ['status' => 'pass', 'msg' => "✅ PASS — Visitor ID <code>" . esc_html($test_vid) . "</code> không có trong Visitor Blacklist."];
            }
        }
    }

    // ── Visitor ID của browser hiện tại (đọc từ cookie _pk_id) ──
    $my_visitor_id = adsdefender_get_matomo_visitor_id();
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('adsdefender'); ?>

        <?php /* ── Tab Navigation ── */ ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:0">
            <?php
            $stabs = [
                'connect' => '🔗 Kết nối',
                'protect' => '🛡 Bảo vệ',
                'server'  => '🔒 Server & WP',
                'groups'  => '🔗 Cross-site',
                'tools'   => '🔍 Công cụ',
            ];
            foreach ($stabs as $key => $label):
            ?>
            <a href="#" class="nav-tab ads-stab-link <?php echo $stab === $key ? 'nav-tab-active' : ''; ?>"
               data-stab="<?php echo esc_attr($key); ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>
        <hr style="margin:0 0 16px">

        <?php /* ══════════════════════════════════════════════
                  TAB 1: Kết nối
               ══════════════════════════════════════════════ */ ?>
        <div id="stab-connect" <?php echo $stab !== 'connect' ? 'style="display:none"' : ''; ?>>
        <table class="form-table">
            <tr>
                <th>Bật chặn IP</th>
                <td><label><input type="checkbox" name="adsdefender_settings[enabled]" value="1"
                    <?php checked($opts['enabled'] ?? 0, 1); ?>> Kích hoạt — check IP mỗi request</label></td>
            </tr>
            <tr>
                <th>Ghi log khi block</th>
                <td><label><input type="checkbox" name="adsdefender_settings[log_enabled]" value="1"
                    <?php checked($opts['log_enabled'] ?? 0, 1); ?>> Lưu vào Block Log</label></td>
            </tr>
        </table>
        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">Matomo</h3>
        <table class="form-table">
            <tr>
                <th>Matomo URL</th>
                <td><input type="url" name="adsdefender_settings[api_url]" id="ads-api-url"
                    value="<?php echo esc_attr($opts['api_url'] ?? ''); ?>"
                    class="regular-text" placeholder="https://track.saigon.pro/"></td>
            </tr>
            <tr>
                <th>Secret Key</th>
                <td>
                    <input type="password" name="adsdefender_settings[secret]" id="ads-secret"
                        value="<?php echo esc_attr($opts['secret'] ?? ''); ?>" class="regular-text" autocomplete="new-password">
                    <p class="description" style="margin-top:4px">
                        Lấy tại: <b>Matomo → ⚙️ → Personal Settings → Security → Auth token</b>
                    </p>
                </td>
            </tr>
            <tr>
                <th>Site ID</th>
                <td>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <input type="number" name="adsdefender_settings[site_id]" id="ads-site-id"
                            value="<?php echo esc_attr($opts['site_id'] ?? ''); ?>" class="small-text" min="1">
                        <button type="button" id="ads-detect-btn"
                                style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;background:#2271b1;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:13px;">
                            🔍 Tự động phát hiện
                        </button>
                        <span id="ads-detect-spinner" style="display:none;">⏳</span>
                    </div>
                    <div id="ads-detect-msg" style="margin-top:6px; font-size:13px;"></div>
                    <div id="ads-site-picker" style="display:none; margin-top:8px;">
                        <label style="font-size:13px; font-weight:600;">Chọn site phù hợp:</label><br>
                        <select id="ads-site-select" style="margin-top:4px; min-width:320px; font-size:13px;">
                            <option value="">— Chọn site —</option>
                        </select>
                        <button type="button" id="ads-site-apply"
                                style="margin-left:8px; padding:4px 10px; font-size:13px; cursor:pointer;">
                            ✅ Chọn
                        </button>
                    </div>
                </td>
            </tr>
        </table>
        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">Hành động</h3>
        <table class="form-table">
            <tr>
                <th>Web Server</th>
                <td>
                    <select name="adsdefender_settings[web_server]">
                        <option value="auto"          <?php selected($opts['web_server'] ?? 'auto', 'auto'); ?>>Tự động phát hiện</option>
                        <option value="openlitespeed" <?php selected($opts['web_server'] ?? '', 'openlitespeed'); ?>>OpenLiteSpeed (OLS)</option>
                        <option value="litespeed"     <?php selected($opts['web_server'] ?? '', 'litespeed'); ?>>LiteSpeed Enterprise</option>
                        <option value="nginx"         <?php selected($opts['web_server'] ?? '', 'nginx'); ?>>Nginx</option>
                        <option value="apache"        <?php selected($opts['web_server'] ?? '', 'apache'); ?>>Apache</option>
                    </select>
                    <p class="description" style="margin-top:4px">
                        <b>OLS/Nginx:</b> dùng cookie bypass cache &nbsp;|&nbsp;
                        <b>LiteSpeed/Apache:</b> ghi Deny vào <code>.htaccess</code> &nbsp;|&nbsp;
                        <b>Tự động:</b> đọc <code>$_SERVER['SERVER_SOFTWARE']</code>
                    </p>
                </td>
            </tr>
            <tr>
                <th>Hành động khi block</th>
                <td>
                    <select name="adsdefender_settings[block_action]">
                        <option value="redirect" <?php selected($opts['block_action'] ?? '', 'redirect'); ?>>Redirect về URL khác</option>
                        <option value="403"      <?php selected($opts['block_action'] ?? '', '403'); ?>>403 Forbidden</option>
                        <option value="blank"    <?php selected($opts['block_action'] ?? '', 'blank'); ?>>Trang trắng</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>URL redirect</th>
                <td><input type="text" name="adsdefender_settings[redirect_url]"
                    value="<?php echo esc_attr($opts['redirect_url'] ?? ''); ?>"
                    class="regular-text" placeholder="https://example.com/"></td>
            </tr>
            <tr>
                <th>URL trang Lock (403)</th>
                <td>
                    <input type="text" name="adsdefender_settings[lock_url]"
                        value="<?php echo esc_attr($opts['lock_url'] ?? ''); ?>"
                        class="regular-text" placeholder="/adsdefender-blocked.html">
                    <p class="description">Để trống → dùng <code>/adsdefender-blocked.html</code> (trang tĩnh mặc định). Có thể nhập path tương đối hoặc URL đầy đủ.</p>
                </td>
            </tr>
        </table>
        </div><!-- /stab-connect -->

        <?php /* ══════════════════════════════════════════════
                  TAB 2: Bảo vệ
               ══════════════════════════════════════════════ */ ?>
        <div id="stab-protect" <?php echo $stab !== 'protect' ? 'style="display:none"' : ''; ?>>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔒 Lockout &amp; Ban tự động</h3>
        <table class="form-table">
            <tr>
                <th>Thời gian lockout tạm (phút)</th>
                <td>
                    <input type="number" name="adsdefender_settings[lockout_duration]"
                        value="<?php echo esc_attr($opts['lockout_duration'] ?? 1440); ?>"
                        min="5" max="44640" class="small-text"> phút
                    <p class="description">Mặc định 1440 phút (24h). IP từ Matomo bị block tạm trong thời gian này.</p>
                </td>
            </tr>
            <tr>
                <th>Số lần tái phạm → ban vĩnh viễn</th>
                <td>
                    <input type="number" name="adsdefender_settings[ban_threshold]"
                        value="<?php echo esc_attr($opts['ban_threshold'] ?? 3); ?>"
                        min="1" max="100" class="small-text"> lần
                    <p class="description">Mặc định 3 lần. IP xuất hiện lại sau khi hết lockout sẽ bị ban vĩnh viễn vào .htaccess.</p>
                </td>
            </tr>
            <tr>
                <th>Khoảng thời gian xét tái phạm (ngày)</th>
                <td>
                    <input type="number" name="adsdefender_settings[ban_period]"
                        value="<?php echo esc_attr($opts['ban_period'] ?? 7); ?>"
                        min="1" max="365" class="small-text"> ngày
                    <p class="description">Đếm số lần lockout trong N ngày gần nhất. Vượt ngưỡng → ban vĩnh viễn.</p>
                </td>
            </tr>
            <tr>
                <th>Tối đa IP trong .htaccess</th>
                <td>
                    <input type="number" name="adsdefender_settings[ban_limit_htaccess]"
                        value="<?php echo esc_attr($opts['ban_limit_htaccess'] ?? 100); ?>"
                        min="10" max="1000" class="small-text"> IP
                    <p class="description">Mặc định 100. Chỉ permanent ban mới ghi vào .htaccess (server-level). Lockout tạm thời chỉ PHP.</p>
                </td>
            </tr>
        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔥 Brute Force Protection</h3>
        <table class="form-table">
            <tr>
                <th>Bật chống Brute Force</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[bf_enabled]" value="1"
                        <?php checked(!empty($opts['bf_enabled'])); ?>>
                    Bật giới hạn số lần thử đăng nhập</label>
                    <p class="description">Block IP/username khi thử đăng nhập thất bại quá nhiều lần.</p>
                </td>
            </tr>
            <tr>
                <th>Cửa sổ thời gian (phút)</th>
                <td>
                    <input type="number" name="adsdefender_settings[bf_check_period]"
                        value="<?php echo esc_attr($opts['bf_check_period'] ?? 5); ?>"
                        min="1" max="60" class="small-text"> phút
                    <p class="description">Đếm số lần thất bại trong N phút gần nhất (mặc định: 5 phút).</p>
                </td>
            </tr>
            <tr>
                <th>Tối đa theo IP / Username</th>
                <td>
                    <input type="number" name="adsdefender_settings[bf_max_ip]"
                        value="<?php echo esc_attr($opts['bf_max_ip'] ?? 5); ?>"
                        min="1" max="100" class="small-text"> lần/IP &nbsp;|&nbsp;
                    <input type="number" name="adsdefender_settings[bf_max_user]"
                        value="<?php echo esc_attr($opts['bf_max_user'] ?? 10); ?>"
                        min="1" max="100" class="small-text"> lần/Username
                    <p class="description">Mặc định: 5 lần/IP và 10 lần/username.</p>
                </td>
            </tr>
            <tr>
                <th>Lockout sau brute force (phút)</th>
                <td>
                    <input type="number" name="adsdefender_settings[bf_lockout_mins]"
                        value="<?php echo esc_attr($opts['bf_lockout_mins'] ?? 30); ?>"
                        min="5" max="1440" class="small-text"> phút
                    <p class="description">Mặc định: 30 phút. Dùng hệ thống lockout chung của AdsDefender.</p>
                </td>
            </tr>
            <tr>
                <th>Chặn đăng nhập "admin"</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[bf_block_admin]" value="1"
                        <?php checked(!empty($opts['bf_block_admin'])); ?>>
                    Block mọi thử đăng nhập với username "admin" (nếu user đó không tồn tại)</label>
                </td>
            </tr>

        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🛡️ Request Firewall</h3>
        <table class="form-table">
            <tr>
                <th>Bật Firewall</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[fw_enabled]" value="1"
                        <?php checked(!empty($opts['fw_enabled'])); ?>>
                    Phát hiện và block SQLi, XSS, Path Traversal, Shellcode</label>
                    <p class="description">Kiểm tra URL và POST data — block ngay với 403 khi phát hiện tấn công.</p>
                </td>
            </tr>
            <tr>
                <th>Chặn Bad User-Agent</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[ua_enabled]" value="1"
                        <?php checked(!empty($opts['ua_enabled'])); ?>>
                    Block scanner, bot độc hại theo User-Agent (HackRepair list + thực tế VN)</label>
                    <p class="description">Block Nikto, SQLmap, WPScan, DirBuster và các tool quét lỗ hổng phổ biến.</p>
                </td>
            </tr>

        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">⚡ Rate Limiting</h3>
        <table class="form-table">
            <tr>
                <th>Bật Rate Limiting</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[rl_enabled]" value="1"
                        <?php checked(!empty($opts['rl_enabled'])); ?>>
                    Giới hạn số request mỗi IP trong cửa sổ thời gian</label>
                    <p class="description">Block IP gửi quá nhiều request liên tiếp (DDoS nhỏ, bot scan).</p>
                </td>
            </tr>
            <tr>
                <th>Tối đa request / thời gian</th>
                <td>
                    <input type="number" name="adsdefender_settings[rl_max_requests]"
                        value="<?php echo esc_attr($opts['rl_max_requests'] ?? 300); ?>"
                        min="10" max="10000" class="small-text"> request /
                    <input type="number" name="adsdefender_settings[rl_window_secs]"
                        value="<?php echo esc_attr($opts['rl_window_secs'] ?? 60); ?>"
                        min="10" max="3600" class="small-text"> giây
                    <p class="description">Mặc định: 300 request / 60 giây. Vượt ngưỡng → block và lockout.</p>
                </td>
            </tr>
            <tr>
                <th>Lockout sau Rate Limit (phút)</th>
                <td>
                    <input type="number" name="adsdefender_settings[rl_lockout_mins]"
                        value="<?php echo esc_attr($opts['rl_lockout_mins'] ?? 10); ?>"
                        min="1" max="1440" class="small-text"> phút
                </td>
            </tr>

        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🍯 Honeypot</h3>
        <table class="form-table">
            <tr>
                <th>Bật Honeypot</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[hp_enabled]" value="1"
                        <?php checked(!empty($opts['hp_enabled'])); ?>>
                    Thêm field ẩn vào form đăng nhập, đăng ký, bình luận</label>
                    <p class="description">Bot thường điền đầy đủ tất cả fields → bị block ngay. Human không thấy field này.</p>
                </td>
            </tr>

        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🤖 CAPTCHA</h3>
        <table class="form-table">
            <tr>
                <th>Bật CAPTCHA</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[rc_enabled]" value="1"
                        <?php checked(!empty($opts['rc_enabled'])); ?>>
                    Bảo vệ forms đăng nhập, bình luận bằng CAPTCHA</label>
                </td>
            </tr>
            <tr>
                <th>Provider</th>
                <td>
                    <?php $prov = $opts['rc_provider'] ?? 'google_v2'; ?>
                    <label style="display:block;margin-bottom:8px">
                        <input type="radio" name="adsdefender_settings[rc_provider]" value="google_v2"
                            <?php checked($prov, 'google_v2'); ?>>
                        <strong>Google reCAPTCHA v2</strong> — Checkbox "I'm not a robot"
                        <br><span style="margin-left:20px;color:#666">Key tại: <a href="https://www.google.com/recaptcha/admin" target="_blank">google.com/recaptcha/admin</a> → chọn "reCAPTCHA v2"</span>
                    </label>
                    <label style="display:block;margin-bottom:8px">
                        <input type="radio" name="adsdefender_settings[rc_provider]" value="google_v3"
                            <?php checked($prov, 'google_v3'); ?>>
                        <strong>Google reCAPTCHA v3</strong> — Invisible, score-based (không cần tương tác)
                        <br><span style="margin-left:20px;color:#666">Key tại: <a href="https://www.google.com/recaptcha/admin" target="_blank">google.com/recaptcha/admin</a> → chọn "reCAPTCHA v3"</span>
                    </label>
                    <label style="display:block">
                        <input type="radio" name="adsdefender_settings[rc_provider]" value="turnstile"
                            <?php checked($prov, 'turnstile'); ?>>
                        <strong>Cloudflare Turnstile</strong> — Nhẹ, không tracking, GDPR-friendly
                        <br><span style="margin-left:20px;color:#666">Key tại: <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank">dash.cloudflare.com → Turnstile</a></span>
                    </label>
                </td>
            </tr>
            <tr>
                <th>Site Key</th>
                <td>
                    <input type="text" name="adsdefender_settings[rc_site_key]"
                        value="<?php echo esc_attr($opts['rc_site_key'] ?? ''); ?>"
                        class="regular-text" id="ads-rc-site-key"
                        placeholder="<?php echo ($prov === 'turnstile') ? '0x4AAAAAAA...' : '6Le...'; ?>" />
                </td>
            </tr>
            <tr>
                <th>Secret Key</th>
                <td>
                    <input type="password" name="adsdefender_settings[rc_secret_key]"
                        value="<?php echo esc_attr($opts['rc_secret_key'] ?? ''); ?>"
                        class="regular-text" id="ads-rc-secret-key" autocomplete="new-password"
                        placeholder="<?php echo ($prov === 'turnstile') ? '0x4AAAAAAA...' : '6Le...'; ?>" />
                </td>
            </tr>
            <tr id="ads-rc-v3-row" <?php echo ($prov !== 'google_v3') ? 'style="display:none"' : ''; ?>>
                <th>Ngưỡng v3</th>
                <td>
                    <input type="number" name="adsdefender_settings[rc_v3_threshold]"
                        value="<?php echo esc_attr($opts['rc_v3_threshold'] ?? '0.5'); ?>"
                        min="0.1" max="1.0" step="0.1" class="small-text">
                    <p class="description">Score 0.0 (bot) → 1.0 (người). Dưới ngưỡng → block. Khuyến nghị: <strong>0.5</strong></p>
                </td>
            </tr>
            <tr>
                <th>Áp dụng trên</th>
                <td>
                    <label style="margin-right:15px"><input type="checkbox" name="adsdefender_settings[rc_on_login]" value="1"
                        <?php checked(!empty($opts['rc_on_login'])); ?>> Form đăng nhập</label>
                    <label style="margin-right:15px"><input type="checkbox" name="adsdefender_settings[rc_on_lostpass]" value="1"
                        <?php checked(!empty($opts['rc_on_lostpass'])); ?>> Quên mật khẩu</label>
                    <label style="margin-right:15px"><input type="checkbox" name="adsdefender_settings[rc_on_register]" value="1"
                        <?php checked(!empty($opts['rc_on_register'])); ?>> Đăng ký</label>
                    <label><input type="checkbox" name="adsdefender_settings[rc_on_comment]" value="1"
                        <?php checked(!empty($opts['rc_on_comment'])); ?>> Bình luận</label>
                </td>
            </tr>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var placeholders = { google_v2:'6Le...', google_v3:'6Le...', turnstile:'0x4AAAAAAA...' };
    document.querySelectorAll('input[name="adsdefender_settings[rc_provider]"]').forEach(function(r){
        r.addEventListener('change',function(){
            document.getElementById('ads-rc-v3-row').style.display = (this.value==='google_v3') ? '' : 'none';
            var ph = placeholders[this.value] || '6Le...';
            document.getElementById('ads-rc-site-key').placeholder   = ph;
            document.getElementById('ads-rc-secret-key').placeholder = ph;
        });
    });
});
</script>

        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">📡 Phát Hiện 404 Flood</h3>
        <table class="form-table">
            <tr>
                <th>Bật 404 Detection</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[detect_404]" value="1"
                        <?php checked(!empty($opts['detect_404'])); ?>>
                    Phát hiện IP scan thư mục/file qua nhiều lần 404</label>
                    <p class="description">IP gây nhiều 404 liên tiếp (thường là scanner) sẽ bị lockout tự động.</p>
                </td>
            </tr>
            <tr>
                <th>Ngưỡng / Cửa sổ / Lockout</th>
                <td>
                    <input type="number" name="adsdefender_settings[detect_404_max]"
                        value="<?php echo esc_attr($opts['detect_404_max'] ?? 20); ?>"
                        min="5" max="1000" class="small-text"> lần 404 /
                    <input type="number" name="adsdefender_settings[detect_404_window]"
                        value="<?php echo esc_attr($opts['detect_404_window'] ?? 60); ?>"
                        min="30" max="3600" class="small-text"> giây →
                    lockout <input type="number" name="adsdefender_settings[detect_404_lockout]"
                        value="<?php echo esc_attr($opts['detect_404_lockout'] ?? 30); ?>"
                        min="5" max="1440" class="small-text"> phút
                    <p class="description">Mặc định: 20 lần 404 trong 60 giây → lockout 30 phút.</p>
                </td>
            </tr>
        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔍 IP Reputation (AbuseIPDB)</h3>
        <table class="form-table">
            <tr id="abuseipdb">
                <th>AbuseIPDB API Key</th>
                <td>
                    <input type="password" name="adsdefender_settings[abuseipdb_key]"
                        value="<?php echo esc_attr($opts['abuseipdb_key'] ?? ''); ?>"
                        class="regular-text" placeholder="Dán API Key từ abuseipdb.com" autocomplete="new-password">
                    <p class="description">Miễn phí 1000 check/ngày tại <a href="https://www.abuseipdb.com/account/api" target="_blank">abuseipdb.com/account/api</a>. Dùng để tra cứu độ nguy hiểm của IP trong Security Log.</p>
                </td>
            </tr>
        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">📋 Admin Activity Log</h3>
        <table class="form-table">
            <tr>
                <th>Bật Admin Log</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[admin_log_enabled]" value="1"
                        <?php checked(!empty($opts['admin_log_enabled'])); ?>>
                    Ghi lại hành động của admin (login, settings, post, user)</label>
                    <p class="description">Lưu tối đa 20.000 dòng. Xem tại <a href="<?php echo esc_url(admin_url('admin.php?page=adsdefender-system&tab=adminlog')); ?>">Hệ thống → Admin Log</a>.</p>
                </td>
            </tr>
        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔍 File Change Detection</h3>
        <table class="form-table">
            <tr>
                <th>Bật File Monitor</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[fm_enabled]" value="1"
                        <?php checked(!empty($opts['fm_enabled'])); ?>>
                    Tự động kiểm tra thay đổi file mỗi 30 phút</label>
                    <p class="description">So sánh SHA-256 hash của wp-config, wp-includes, wp-admin, plugins, themes. Alert khi phát hiện file mới, sửa, hoặc xóa.</p>
                </td>
            </tr>
            <tr>
                <th>Độ sâu quét</th>
                <td>
                    <input type="number" name="adsdefender_settings[fm_depth]"
                        value="<?php echo esc_attr($opts['fm_depth'] ?? 3); ?>"
                        min="1" max="6" style="width:70px"> tầng thư mục
                    <p class="description">Mặc định 3. Tăng lên 5-6 để quét sâu hơn (chậm hơn).</p>
                </td>
            </tr>
        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🦠 Site Scanner / Malware</h3>
        <table class="form-table">
            <tr>
                <th>Bật Malware Scan</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[scan_enabled]" value="1"
                        <?php checked(!empty($opts['scan_enabled'])); ?>>
                    Tự động quét malware 1 lần/ngày</label>
                    <p class="description">31 loại signature: eval/base64, webshell, C2 callback, crypto miner, spam inject... Xem kết quả tại <a href="<?php echo esc_url(admin_url('admin.php?page=adsdefender-system&tab=sitescanner')); ?>">Hệ thống → Site Scanner</a>.</p>
                </td>
            </tr>
        </table>

        <h3 style="font-size:14px;margin:24px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">💬 Comment Security</h3>
        <?php
        $cs_stats      = function_exists('adsdefender_cs_stats') ? adsdefender_cs_stats() : [];
        $cs_bl_count   = $cs_stats['blocklist_count']   ?? 0;
        $cs_bl_updated = $cs_stats['blocklist_updated']  ?? 'Chưa tải';
        $nonce_cs_ref  = wp_create_nonce('adsdefender_cs_refresh');
        ?>
        <table class="form-table">
            <tr>
                <th>Bật Comment Security</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[cs_enabled]" value="1"
                        <?php checked(!empty($opts['cs_enabled'])); ?>>
                    Bật rate limit + spam blocklist tự động cho comment</label>
                    <p class="description">Honeypot và time check đã được xử lý bởi module <strong>Honeypot</strong> riêng.</p>
                </td>
            </tr>
            <tr>
                <th>Rate limit</th>
                <td>
                    Tối đa
                    <input type="number" name="adsdefender_settings[cs_rate_limit]" value="<?php echo (int)($opts['cs_rate_limit'] ?? 5); ?>"
                        min="1" max="100" style="width:60px">
                    comments / IP / giờ
                    <p class="description">IP gửi nhiều hơn số này trong 1 giờ sẽ bị reject.</p>
                </td>
            </tr>
            <tr>
                <th>Spam Blocklist</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[cs_blocklist]" value="1"
                        <?php checked($opts['cs_blocklist'] ?? true); ?>>
                    Bật spam blocklist tự động</label>
                    <p class="description">
                        Nguồn: <strong>splorp/wordpress-comment-blacklist</strong> — <?php echo number_format($cs_bl_count); ?> terms
                        (cập nhật: <?php echo esc_html($cs_bl_updated); ?>).
                        Auto-refresh mỗi 7 ngày.
                        <button type="button" id="cs-refresh-btn" class="button button-small"
                            data-nonce="<?php echo esc_attr($nonce_cs_ref); ?>"
                            style="margin-left:8px">🔄 Cập nhật ngay</button>
                        <span id="cs-refresh-status" style="margin-left:8px;font-size:12px;color:#718096"></span>
                    </p>
                    <script>
                    document.getElementById('cs-refresh-btn').addEventListener('click', function(){
                        var btn = this, status = document.getElementById('cs-refresh-status');
                        btn.disabled = true; status.textContent = '⏳ Đang tải...';
                        var fd = new FormData();
                        fd.append('action','adsdefender_cs_refresh_blocklist');
                        fd.append('nonce', btn.dataset.nonce);
                        fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'})
                            .then(r=>r.json())
                            .then(function(res){
                                btn.disabled = false;
                                if(res.success) status.textContent = '✅ ' + res.data.count.toLocaleString() + ' terms — ' + res.data.updated_at;
                                else status.textContent = '❌ ' + (res.data||'Lỗi');
                            })
                            .catch(function(){ btn.disabled=false; status.textContent='❌ Network error'; });
                    });
                    </script>
                </td>
            </tr>
        </table>

        </div><!-- /stab-protect -->

        <?php /* ══════════════════════════════════════════════
                  TAB 3: Server & WP
               ══════════════════════════════════════════════ */ ?>
        <div id="stab-server" <?php echo $stab !== 'server' ? 'style="display:none"' : ''; ?>>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔧 System Tweaks (Bảo vệ File Server)</h3>
        <table class="form-table">
            <tr>
                <th>Bật System Tweaks</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[st_enabled]" value="1"
                        <?php checked(!empty($opts['st_enabled'])); ?>>
                    Bật hardening .htaccess cho server (Apache/LiteSpeed)</label>
                    <p class="description">Ghi các rule bảo mật vào .htaccess. Không ảnh hưởng Nginx (Nginx không dùng .htaccess).</p>
                </td>
            </tr>
            <tr>
                <th>Bảo vệ file hệ thống</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[st_protect_files]" value="1"
                        <?php checked(!empty($opts['st_protect_files'])); ?>>
                    Block <code>readme.html</code>, <code>readme.txt</code>, <code>install.php</code>, direct PHP trong <code>wp-includes/</code>, <code>wp-admin/includes/</code>, thư mục <code>.git/.svn</code></label>
                    <p class="description">Ngăn hacker đọc file lộ thông tin version và cấu trúc site.</p>
                </td>
            </tr>
            <tr>
                <th>Tắt Directory Listing</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[st_dir_browsing]" value="1"
                        <?php checked(!empty($opts['st_dir_browsing'])); ?>>
                    <code>Options -Indexes</code> — tắt hiển thị danh sách file khi không có index.php</label>
                </td>
            </tr>
            <tr>
                <th>Block PHP trong Uploads</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[st_uploads_php]" value="1"
                        <?php checked(!empty($opts['st_uploads_php'])); ?>>
                    Không cho thực thi PHP trong <code>wp-content/uploads/</code> — ngăn webshell sau upload</label>
                    <p class="description"><strong>Quan trọng nhất</strong>: hacker upload shell.php vào uploads → không chạy được.</p>
                </td>
            </tr>
            <tr>
                <th>Block PHP trong Plugins/Themes</th>
                <td>
                    <label style="display:block"><input type="checkbox" name="adsdefender_settings[st_plugins_php]" value="1"
                        <?php checked(!empty($opts['st_plugins_php'])); ?>>
                    Block direct PHP request trong <code>wp-content/plugins/</code></label>
                    <label style="display:block;margin-top:4px"><input type="checkbox" name="adsdefender_settings[st_themes_php]" value="1"
                        <?php checked(!empty($opts['st_themes_php'])); ?>>
                    Block direct PHP request trong <code>wp-content/themes/</code></label>
                    <p class="description">Ngăn tấn công khai thác trực tiếp các file PHP trong plugin/theme bị lỗi.</p>
                </td>
            </tr>

        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔐 Ẩn URL Đăng Nhập (Hide Login)</h3>
        <table class="form-table">
            <tr>
                <th>Bật Hide Login</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[hl_enabled]" value="1"
                        <?php checked(!empty($opts['hl_enabled'])); ?>>
                    Ẩn /wp-login.php đằng sau URL tùy chỉnh</label>
                    <p class="description">⚠️ Sau khi bật, phải truy cập theo URL mới bên dưới. Bookmark lại trước khi lưu!</p>
                </td>
            </tr>
            <tr>
                <th>Login URL slug</th>
                <td>
                    <code><?php echo esc_html(home_url('/')); ?></code>
                    <input type="text" name="adsdefender_settings[hl_slug]"
                        value="<?php echo esc_attr($opts['hl_slug'] ?? ''); ?>"
                        class="regular-text" placeholder="dang-nhap-admin">
                    <p class="description">Chỉ chứa chữ, số, dấu gạch ngang. Ví dụ: <code>quan-tri-vien</code> → URL: <code><?php echo esc_html(home_url('/quan-tri-vien/')); ?></code></p>
                    <?php if (!empty($opts['hl_enabled']) && !empty($opts['hl_slug'])): ?>
                    <p><strong style="color:#2271b1">🔗 URL đăng nhập hiện tại: <a href="<?php echo esc_url(home_url('/' . $opts['hl_slug'] . '/')); ?>" target="_blank"><?php echo esc_html(home_url('/' . $opts['hl_slug'] . '/')); ?></a></strong></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Tắt XML-RPC</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[xmlrpc_disabled]" value="1"
                        <?php checked(!empty($opts['xmlrpc_disabled'])); ?>>
                    Disable /xmlrpc.php (thường dùng để brute force hàng loạt)</label>
                    <p class="description">Chỉ tắt nếu không dùng XML-RPC (Jetpack, WP Mobile app cần XML-RPC).</p>
                </td>
            </tr>
        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">⚙️ WordPress Tweaks</h3>
        <table class="form-table">
            <tr>
                <th>Tắt File Editor</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[wt_file_editor]" value="1"
                        <?php checked(!empty($opts['wt_file_editor'])); ?>>
                    <code>DISALLOW_FILE_EDIT = true</code> — tắt Theme Editor và Plugin Editor trong WP Admin</label>
                    <p class="description">Ngăn hacker chiếm tài khoản admin rồi inject code qua editor. Vẫn sửa được qua FTP/SSH.</p>
                </td>
            </tr>
            <tr>
                <th>Ẩn WP Version</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[wt_remove_version]" value="1"
                        <?php checked(!empty($opts['wt_remove_version'])); ?>>
                    Xóa <code>&lt;meta name="generator"&gt;</code>, <code>?ver=</code> trong script/style, version trong RSS</label>
                    <p class="description">Hacker dùng version để tìm CVE phù hợp. Ẩn version làm khó trinh sát.</p>
                </td>
            </tr>
            <tr>
                <th>Chặn Author Enumeration</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[wt_author_enum]" value="1"
                        <?php checked(!empty($opts['wt_author_enum'])); ?>>
                    Redirect <code>/?author=1</code> về trang chủ — ngăn đoán username qua author archive</label>
                </td>
            </tr>
            <tr>
                <th>Block REST API /users</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[wt_rest_users]" value="1"
                        <?php checked(!empty($opts['wt_rest_users'])); ?>>
                    Block <code>/wp-json/wp/v2/users</code> cho khách (chưa login) — ngăn list username</label>
                    <p class="description">Brute force tool thường dùng REST API để lấy danh sách username trước khi tấn công.</p>
                </td>
            </tr>
        </table>

        <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔒 Security Headers</h3>
        <table class="form-table">
            <tr>
                <th>HSTS</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[wt_hsts]" value="1"
                        <?php checked(!empty($opts['wt_hsts'])); ?>>
                    <code>Strict-Transport-Security</code> — bắt buộc HTTPS, chống HTTPS downgrade attack</label>
                    <p class="description">Max-age:
                        <input type="number" name="adsdefender_settings[wt_hsts_age]"
                            value="<?php echo esc_attr($opts['wt_hsts_age'] ?? 31536000); ?>"
                            min="300" max="63072000" class="small-text"> giây
                        (mặc định 31536000 = 1 năm). Chỉ set khi site đã full HTTPS.
                    </p>
                </td>
            </tr>
            <tr>
                <th>Content-Security-Policy</th>
                <td>
                    <label><input type="checkbox" name="adsdefender_settings[wt_csp]" value="1"
                        <?php checked(!empty($opts['wt_csp'])); ?>>
                    Bật CSP header — giảm nguy cơ XSS</label>
                    <p class="description">Để trống = dùng policy mặc định (an toàn, không break site thông thường):<br>
                    <code>default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:; frame-ancestors 'self';</code></p>
                    <textarea name="adsdefender_settings[wt_csp_value]" rows="2"
                        class="large-text" placeholder="Để trống = dùng mặc định"
                    ><?php echo esc_textarea($opts['wt_csp_value'] ?? ''); ?></textarea>
                </td>
            </tr>
        </table>

        </div><!-- /stab-server -->

        <?php /* ══════════════════════════════════════════════
                  TAB 4: Cross-site (Groups)
               ══════════════════════════════════════════════ */ ?>
        <div id="stab-groups" <?php echo $stab !== 'groups' ? 'style="display:none"' : ''; ?>>

        <p style="color:#666;max-width:640px">Sites cùng tag sẽ chia sẻ danh sách IP fraud. Khi site này phát hiện IP click fraud → block ngay trên tất cả sites cùng tag.</p>
        <?php
        $api_url = rtrim($opts['api_url'] ?? '', '/');
        $secret  = $opts['secret'] ?? '';
        $site_id = (int) ($opts['site_id'] ?? 0);
        $groups_preview = [];
        if ($api_url && $secret && $site_id) {
            $groups_preview = adsdefender_fetch_site_group_preview();
        }
        ?>
        <div id="ads-groups-wrap">
            <?php if (empty($api_url) || empty($secret) || empty($site_id)): ?>
            <p style="color:#b32d2e">⚠️ Cần cấu hình Matomo URL, Secret Key và Site ID trước.</p>
            <?php else: ?>

            <div id="ads-current-tags" style="margin-bottom:12px">
                <?php if (!empty($groups_preview)): ?>
                    <strong>Tags của site này:</strong>&nbsp;
                    <?php foreach (array_keys($groups_preview) as $t): ?>
                    <span class="ads-tag-chip" data-tag="<?php echo esc_attr($t); ?>"
                        style="display:inline-flex;align-items:center;gap:4px;background:#e8f0fe;border:1px solid #4a90d9;border-radius:12px;padding:2px 10px;margin:2px;font-size:13px">
                        <?php echo esc_html($t); ?>
                        <button type="button" class="ads-remove-tag" data-tag="<?php echo esc_attr($t); ?>"
                            style="background:none;border:none;cursor:pointer;color:#d63638;font-size:15px;line-height:1;padding:0 0 0 2px">&times;</button>
                    </span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <em style="color:#888">Chưa có tag nào.</em>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:8px;align-items:center;margin-bottom:16px">
                <input type="text" id="ads-new-tag" placeholder="Tên tag (vd: dien-lanh)" class="regular-text"
                    style="max-width:220px;font-family:monospace" pattern="[a-z0-9\-]+" title="Chỉ chữ thường, số và dấu gạch ngang">
                <button type="button" id="ads-add-tag" class="button button-primary">+ Thêm tag</button>
                <span id="ads-tag-msg" style="font-size:13px;color:#2271b1"></span>
            </div>

            <div id="ads-group-preview">
                <?php if (!empty($groups_preview)): ?>
                <strong>Sites cùng group:</strong>
                <table class="widefat" style="max-width:600px;margin-top:8px">
                    <thead><tr><th>Tag</th><th>Sites cùng group</th></tr></thead>
                    <tbody>
                    <?php foreach ($groups_preview as $tag => $sites): ?>
                    <tr data-tag="<?php echo esc_attr($tag); ?>">
                        <td><code><?php echo esc_html($tag); ?></code></td>
                        <td>
                            <?php if (empty($sites)): ?>
                            <em style="color:#888">Chỉ có site này</em>
                            <?php else: ?>
                            <?php foreach ($sites as $s): ?>
                            <span style="display:inline-block;background:#f0f0f1;border-radius:3px;padding:1px 7px;margin:2px;font-size:12px">
                                Site <?php echo (int)$s['id']; ?><?php if (!empty($s['name'])): ?> — <?php echo esc_html($s['name']); ?><?php endif; ?>
                            </span>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <script>
            (function(){
                const nonce = '<?php echo esc_js(wp_create_nonce('adsdefender_groups')); ?>';
                const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

                function refreshPreview(groups) {
                    const wrap = document.getElementById('ads-current-tags');
                    const preview = document.getElementById('ads-group-preview');
                    if (!groups || Object.keys(groups).length === 0) {
                        wrap.innerHTML = '<em style="color:#888">Chưa có tag nào.</em>';
                        preview.innerHTML = '';
                        return;
                    }
                    // Tags
                    let tagsHtml = '<strong>Tags của site này:</strong>&nbsp;';
                    for (const tag of Object.keys(groups)) {
                        tagsHtml += `<span class="ads-tag-chip" data-tag="${tag}" style="display:inline-flex;align-items:center;gap:4px;background:#e8f0fe;border:1px solid #4a90d9;border-radius:12px;padding:2px 10px;margin:2px;font-size:13px">
                            ${tag} <button type="button" class="ads-remove-tag" data-tag="${tag}" style="background:none;border:none;cursor:pointer;color:#d63638;font-size:15px;line-height:1;padding:0 0 0 2px">&times;</button></span>`;
                    }
                    wrap.innerHTML = tagsHtml;
                    bindRemove();
                    // Preview table
                    let rows = '';
                    for (const [tag, sites] of Object.entries(groups)) {
                        const sitesHtml = sites.length === 0
                            ? '<em style="color:#888">Chỉ có site này</em>'
                            : sites.map(s => `<span style="display:inline-block;background:#f0f0f1;border-radius:3px;padding:1px 7px;margin:2px;font-size:12px">Site ${s.id}${s.name ? ' — ' + s.name : ''}</span>`).join('');
                        rows += `<tr data-tag="${tag}"><td><code>${tag}</code></td><td>${sitesHtml}</td></tr>`;
                    }
                    preview.innerHTML = rows
                        ? `<strong>Sites cùng group:</strong><table class="widefat" style="max-width:600px;margin-top:8px"><thead><tr><th>Tag</th><th>Sites cùng group</th></tr></thead><tbody>${rows}</tbody></table>`
                        : '';
                }

                function callAjax(action, tag, cb) {
                    const msg = document.getElementById('ads-tag-msg');
                    msg.textContent = '⏳ Đang xử lý...';
                    const fd = new FormData();
                    fd.append('action', action);
                    fd.append('tag', tag);
                    fd.append('nonce', nonce);
                    fetch(ajaxUrl, {method:'POST', body: fd})
                        .then(r => r.json())
                        .then(d => {
                            if (d.success) {
                                msg.style.color = '#00a32a';
                                msg.textContent = '✅ ' + (d.data?.tag ? 'Tag "' + d.data.tag + '" đã cập nhật' : 'Thành công');
                                cb(d.data?.groups || {});
                            } else {
                                msg.style.color = '#d63638';
                                msg.textContent = '❌ ' + (d.data || 'Lỗi');
                            }
                            setTimeout(() => msg.textContent = '', 3000);
                        })
                        .catch(() => { msg.style.color = '#d63638'; msg.textContent = '❌ Lỗi kết nối'; });
                }

                document.getElementById('ads-add-tag').addEventListener('click', function() {
                    const input = document.getElementById('ads-new-tag');
                    const tag = input.value.trim().toLowerCase().replace(/[^a-z0-9\-]/g,'');
                    if (!tag) { document.getElementById('ads-tag-msg').textContent = 'Nhập tên tag hợp lệ'; return; }
                    callAjax('adsdefender_add_group_tag', tag, function(groups) {
                        input.value = '';
                        refreshPreview(groups);
                    });
                });

                document.getElementById('ads-new-tag').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('ads-add-tag').click(); }
                });

                function bindRemove() {
                    document.querySelectorAll('.ads-remove-tag').forEach(btn => {
                        btn.onclick = function() {
                            const tag = this.dataset.tag;
                            if (!confirm('Xóa tag "' + tag + '" khỏi site này?')) return;
                            callAjax('adsdefender_remove_group_tag', tag, refreshPreview);
                        };
                    });
                }
                bindRemove();
            })();
            </script>
            <?php endif; ?>
        </div><!-- /ads-groups-wrap -->

        </div><!-- /stab-groups -->

        <?php /* ══════════════════════════════════════════════
                  SUBMIT (outside all tab divs, inside form)
               ══════════════════════════════════════════════ */ ?>
        <?php submit_button('Lưu cài đặt'); ?>
    </form>

    <?php /* ══════════════════════════════════════════════
              TAB 5: Công cụ  (outside main options form)
           ══════════════════════════════════════════════ */ ?>
    <div id="stab-tools" <?php echo $stab !== 'tools' ? 'style="display:none"' : ''; ?>>

    <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">Trạng thái Sync</h3>
    <?php
    $visitor_count = count(get_option(ADSDEFENDER_OPTION_VISITORS, []));
    ?>
    <table class="widefat" style="max-width:500px">
        <tr><th>Sync gần nhất</th><td><?php echo esc_html($updated ?: '(chưa sync)'); ?></td></tr>
        <tr><th>IP đang block</th><td><strong><?php echo (int)$count; ?></strong></td></tr>
        <tr>
            <th>Visitor Blacklist</th>
            <td>
                <strong><?php echo $visitor_count; ?></strong> visitor IDs
                <span style="font-size:11px;color:#666;margin-left:8px">
                    (cookie <code>_pk_id</code> — block dù đổi IP)
                </span>
            </td>
        </tr>
        <tr><th>REST Token</th><td>
            <?php
            $rest_tok = adsdefender_rest_token();
            $tok_masked = substr($rest_tok, 0, 6) . str_repeat('•', max(0, strlen($rest_tok) - 10)) . substr($rest_tok, -4);
            ?>
            <span id="ads-tok-masked"><code style="font-size:11px;background:#f6f7f7;padding:3px 6px;border-radius:3px"><?php echo esc_html($tok_masked); ?></code></span>
            <span id="ads-tok-full" style="display:none"><code style="font-size:11px;background:#f6f7f7;padding:3px 6px;border-radius:3px"><?php echo esc_html($rest_tok); ?></code></span>
            <button type="button" id="ads-tok-toggle" class="button-link" style="margin-left:8px;font-size:12px">👁 Hiện</button>
            <script>document.getElementById('ads-tok-toggle').addEventListener('click',function(){var show=document.getElementById('ads-tok-full').style.display==='none';document.getElementById('ads-tok-full').style.display=show?'inline':'none';document.getElementById('ads-tok-masked').style.display=show?'none':'inline';this.textContent=show?'🙈 Ẩn':'👁 Hiện';});</script>
            <p class="description">Token bảo mật cho REST API (/conversion, /lead). Không chia sẻ.</p>
        </td></tr>
        <?php if ($error): ?>
        <tr><th>Lỗi</th><td style="color:red"><?php echo esc_html($error); ?></td></tr>
        <?php endif; ?>
    </table>
    <form method="post" style="margin-top:10px">
        <?php wp_nonce_field('adsdefender_sync'); ?>
        <button type="submit" name="adsdefender_sync_now" class="button">🔄 Sync ngay</button>
    </form>
    <?php if ($count > 0):
        $sample = array_slice(get_option(ADSDEFENDER_OPTION_IPS, []), 0, 8); ?>
    <p style="margin-top:8px;color:#666;font-size:12px">Mẫu: <code><?php echo esc_html(implode(', ', $sample)); ?></code> ...</p>
    <?php endif; ?>

    <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔍 Kiểm tra IP &amp; Visitor ID</h3>

    <?php
    $my_ip  = adsdefender_get_real_ip() ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    $my_ip6 = trim($_SERVER['REMOTE_ADDR'] ?? '');
    ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px">

    <?php /* ── Panel 1: Kiểm tra IP ── */ ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px">
        <h3 style="margin-top:0">🌐 Kiểm tra IP</h3>
        <p style="color:#666;font-size:13px;margin-top:0">Nhập IP để kiểm tra có bị block không.</p>

        <?php if ($test_result): ?>
        <div style="padding:10px 12px;margin-bottom:12px;border-radius:4px;font-size:13px;border-left:4px solid <?php
            echo $test_result['status'] === 'blocked' ? '#d63638' : ($test_result['status'] === 'whitelist' ? '#2271b1' : ($test_result['status'] === 'error' ? '#f0860a' : '#00a32a'));
        ?>;background:<?php
            echo $test_result['status'] === 'blocked' ? '#fcf0f1' : ($test_result['status'] === 'whitelist' ? '#f0f6fc' : ($test_result['status'] === 'error' ? '#fef8f0' : '#f0faf0'));
        ?>">
            <?php echo $test_result['msg']; ?>
        </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('adsdefender_test'); ?>
            <div style="display:flex;gap:6px;margin-bottom:8px">
                <input type="text" name="adsdefender_test_ip"
                    value="<?php echo esc_attr($test_ip); ?>"
                    placeholder="IPv4 hoặc IPv6..."
                    style="flex:1;font-family:monospace;font-size:13px">
                <button type="submit" name="adsdefender_do_test" class="button button-primary">Kiểm tra</button>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="button" style="font-size:12px" onclick="
                    document.querySelector('[name=adsdefender_test_ip]').value = '<?php echo esc_js($my_ip); ?>';
                ">📍 <?php echo esc_html($my_ip); ?></button>
                <?php if ($my_ip6 && $my_ip6 !== $my_ip): ?>
                <button type="button" class="button" style="font-size:12px" onclick="
                    document.querySelector('[name=adsdefender_test_ip]').value = '<?php echo esc_js($my_ip6); ?>';
                ">📍 <?php echo esc_html(substr($my_ip6, 0, 30)); ?></button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php /* ── Panel 2: Kiểm tra Visitor ID ── */ ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px">
        <h3 style="margin-top:0">🍪 Kiểm tra Visitor ID</h3>
        <p style="color:#666;font-size:13px;margin-top:0">Kiểm tra visitor cookie Matomo có trong Visitor Blacklist không.</p>

        <?php if ($test_vid_result): ?>
        <div style="padding:10px 12px;margin-bottom:12px;border-radius:4px;font-size:13px;border-left:4px solid <?php
            echo $test_vid_result['status'] === 'blocked' ? '#d63638' : ($test_vid_result['status'] === 'error' ? '#f0860a' : '#00a32a');
        ?>;background:<?php
            echo $test_vid_result['status'] === 'blocked' ? '#fcf0f1' : ($test_vid_result['status'] === 'error' ? '#fef8f0' : '#f0faf0');
        ?>">
            <?php echo $test_vid_result['msg']; ?>
        </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('adsdefender_test_vid'); ?>
            <div style="display:flex;gap:6px;margin-bottom:8px">
                <input type="text" name="adsdefender_test_vid"
                    value="<?php echo esc_attr($test_vid); ?>"
                    placeholder="16 ký tự hex (vd: efb92be6...)"
                    style="flex:1;font-family:monospace;font-size:13px">
                <button type="submit" name="adsdefender_do_test_vid" class="button button-primary">Kiểm tra</button>
            </div>
            <?php if ($my_visitor_id): ?>
            <button type="button" class="button" style="font-size:12px" onclick="
                document.querySelector('[name=adsdefender_test_vid]').value = '<?php echo esc_js($my_visitor_id); ?>';
            ">🍪 Cookie của tôi: <code><?php echo esc_html($my_visitor_id); ?></code></button>
            <p style="font-size:11px;color:#888;margin:6px 0 0">
                Đọc từ cookie <code>_pk_id.<?php echo (int)($opts['site_id'] ?? 0); ?>.*</code>
            </p>
            <?php else: ?>
            <p style="font-size:12px;color:#999;margin:6px 0 0">
                ⚠️ Không tìm thấy cookie Matomo trong browser này.<br>
                Thử truy cập site từ browser khác rồi quay lại, hoặc nhập thủ công.
            </p>
            <?php endif; ?>
        </form>

        <div style="margin-top:14px;padding:10px 12px;background:#f6f7f7;border-radius:4px;font-size:12px;color:#555">
            <strong>Tổng Visitor Blacklist:</strong>
            <strong style="color:#d63638"><?php echo count(get_option(ADSDEFENDER_OPTION_VISITORS, [])); ?></strong> visitors
            &nbsp;·&nbsp; Sync cùng lúc với IP mỗi 30 phút
        </div>
    </div>

    </div><!-- /grid -->

    </div><!-- /stab-tools -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ── Matomo auto-detect ──
    var nonce   = '<?php echo esc_js(wp_create_nonce('adsdefender_detect_matomo')); ?>';
    var btn     = document.getElementById('ads-detect-btn');
    var spinner = document.getElementById('ads-detect-spinner');
    var msg     = document.getElementById('ads-detect-msg');
    var picker  = document.getElementById('ads-site-picker');
    if (btn) {
        btn.addEventListener('click', function() {
            var url    = document.getElementById('ads-api-url').value.trim();
            var secret = document.getElementById('ads-secret').value.trim();

            if (!url || !secret) {
                msg.style.color = '#d63638';
                msg.textContent = '⚠️ Cần nhập Matomo URL và Secret Key trước.';
                return;
            }

            btn.disabled = true;
            spinner.style.display = 'inline';
            msg.textContent = '';
            picker.style.display = 'none';

            var fd = new FormData();
            fd.append('action',  'adsdefender_detect_matomo');
            fd.append('nonce',   nonce);
            fd.append('api_url', url);
            fd.append('secret',  secret);

            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    btn.disabled = false;
                    spinner.style.display = 'none';

                    if (!d.success) {
                        msg.style.color = '#d63638';
                        msg.textContent = '❌ ' + d.data;
                        return;
                    }

                    var res = d.data;
                    msg.style.color = res.site_id ? '#008a20' : '#996800';
                    msg.textContent = res.message;

                    if (res.site_id) {
                        document.getElementById('ads-site-id').value = res.site_id;
                        picker.style.display = 'none';
                    } else if (res.all_sites && res.all_sites.length) {
                        var sel = document.getElementById('ads-site-select');
                        sel.innerHTML = '<option value="">— Chọn site —</option>';
                        res.all_sites.forEach(function(s) {
                            var opt = document.createElement('option');
                            opt.value = s.id;
                            opt.textContent = '[' + s.id + '] ' + s.name + ' — ' + s.url;
                            sel.appendChild(opt);
                        });
                        picker.style.display = 'block';
                    }
                })
                .catch(function(e) {
                    btn.disabled = false;
                    spinner.style.display = 'none';
                    msg.style.color = '#d63638';
                    msg.textContent = '❌ Lỗi kết nối: ' + e.message;
                });
        });

        document.getElementById('ads-site-apply').addEventListener('click', function() {
            var sel = document.getElementById('ads-site-select');
            if (!sel.value) return;
            document.getElementById('ads-site-id').value = sel.value;
            picker.style.display = 'none';
            msg.style.color = '#008a20';
            msg.textContent = '✅ Đã chọn Site ID: ' + sel.value;
        });
    }

    // ── Tab switching ──
    var stabs = ['connect','protect','server','groups','tools'];

    function showStab(key) {
        stabs.forEach(function(k) {
            var el = document.getElementById('stab-' + k);
            if (el) el.style.display = (k === key) ? '' : 'none';
        });
        document.querySelectorAll('.ads-stab-link').forEach(function(a) {
            if (a.dataset.stab === key) {
                a.classList.add('nav-tab-active');
            } else {
                a.classList.remove('nav-tab-active');
            }
        });
        // Update URL ?stab= without page reload
        var url = new URL(window.location.href);
        url.searchParams.set('stab', key);
        history.replaceState(null, '', url.toString());
    }

    document.querySelectorAll('.ads-stab-link').forEach(function(a) {
        a.addEventListener('click', function(e) {
            e.preventDefault();
            showStab(this.dataset.stab);
        });
    });
});
</script>
    <?php
}

// ─── Trang Block Log ─────────────────────────────────────────────────────────

function adsdefender_page_log()
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_LOG_TABLE;

    // Mark đã đọc
    $last_id = (int) $wpdb->get_var("SELECT MAX(id) FROM `{$table}`");
    update_option('adsdefender_log_last_seen', $last_id);

    // Actions
    if (isset($_POST['adsdefender_clear_log']) && check_admin_referer('adsdefender_clear_log')) {
        $wpdb->query("TRUNCATE TABLE `{$table}`");
        update_option('adsdefender_log_last_seen', 0);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã xóa toàn bộ log.</p></div>';
    }

    // Block thủ công từ log
    if (isset($_POST['ads_log_block_ip']) && check_admin_referer('ads_log_block_ip')) {
        $block_ip = sanitize_text_field($_POST['ads_log_ip'] ?? '');
        $note     = sanitize_text_field($_POST['ads_log_note'] ?? 'Block from log');
        if (filter_var($block_ip, FILTER_VALIDATE_IP)) {
            $manual = adsdefender_get_manual_ips();
            $exists = array_filter($manual, fn($m) => ($m['ip'] ?? '') === $block_ip);
            if (!$exists) {
                $manual[] = ['ip' => $block_ip, 'note' => $note, 'added' => current_time('mysql')];
                update_option(ADSDEFENDER_OPTION_MANUAL, $manual, false);
            }
            echo '<div class="notice notice-success is-dismissible"><p>✅ Đã block <code>' . esc_html($block_ip) . '</code> vào danh sách thủ công.</p></div>';
        }
    }

    // Filters
    $ip_filter   = sanitize_text_field($_GET['lip'] ?? '');
    $date_filter = sanitize_text_field($_GET['ldate'] ?? ''); // YYYY-MM-DD
    $per_page    = 50;
    $page        = max(1, (int)($_GET['paged'] ?? 1));
    $offset      = ($page - 1) * $per_page;

    // Build query
    $where = ['1=1'];
    $args  = [];
    if ($ip_filter)   { $where[] = 'ip = %s';                          $args[] = $ip_filter; }
    if ($date_filter) { $where[] = 'DATE(blocked_at) = %s';            $args[] = $date_filter; }
    $where_sql = implode(' AND ', $where);

    $total = $args
        ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $args))
        : (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

    $query_args = array_merge($args, [$per_page, $offset]);
    $rows = $args
        ? $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY blocked_at DESC LIMIT %d OFFSET %d", $query_args))
        : $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` ORDER BY blocked_at DESC LIMIT %d OFFSET %d", $per_page, $offset));

    // Top IPs (luôn tính trên toàn bộ, không filter)
    $top_ips = $wpdb->get_results(
        "SELECT ip, COUNT(*) as cnt FROM `{$table}` GROUP BY ip ORDER BY cnt DESC LIMIT 10"
    ) ?: [];

    $pages = max(1, (int)ceil($total / $per_page));

    // Manual block list (để check đã block chưa)
    $already_blocked = array_column(adsdefender_get_manual_ips(), 'ip');
    $matomo_ips      = get_option(ADSDEFENDER_OPTION_IPS, []);
    ?>

    <?php // ── Toolbar: Filter + Actions ── ?>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <form method="get" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="page" value="adsdefender-protect">
        <input type="hidden" name="tab" value="log">
        <input type="text" name="lip" value="<?php echo esc_attr($ip_filter); ?>"
               placeholder="Lọc IP..." style="width:150px;height:30px">
        <input type="date" name="ldate" value="<?php echo esc_attr($date_filter); ?>"
               style="height:30px">
        <button type="submit" class="button" style="height:30px;line-height:29px">Lọc</button>
        <?php if ($ip_filter || $date_filter): ?>
        <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-protect','tab'=>'log'], admin_url('admin.php'))); ?>"
           class="button" style="height:30px;line-height:29px">✕ Xóa lọc</a>
        <?php endif; ?>
      </form>

      <span style="color:#666;font-size:13px;margin-left:auto">
        Tổng <strong><?php echo $total; ?></strong> lần block<?php
          if ($ip_filter) echo ' — IP: <code>' . esc_html($ip_filter) . '</code>';
          if ($date_filter) echo ' — Ngày: <code>' . esc_html($date_filter) . '</code>';
        ?>
      </span>

      <form method="post" style="margin:0">
        <?php wp_nonce_field('adsdefender_clear_log'); ?>
        <button type="submit" name="adsdefender_clear_log" class="button button-secondary"
            style="height:30px;line-height:29px"
            onclick="return confirm('Xóa toàn bộ log?')">🗑 Xóa log</button>
      </form>
    </div>

    <?php // ── Top IPs sidebar + Table ── ?>
    <div style="display:grid;grid-template-columns:200px 1fr;gap:16px;align-items:start">

    <?php if (!empty($top_ips)): ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;font-size:12px">
      <div style="font-weight:700;font-size:11px;text-transform:uppercase;color:#666;letter-spacing:.5px;margin-bottom:8px">Top 10 IPs</div>
      <?php foreach ($top_ips as $r):
        $is_blocked = in_array($r->ip, $already_blocked) || in_array($r->ip, $matomo_ips);
        $filter_url = esc_url(add_query_arg(['page'=>'adsdefender-protect','tab'=>'log','lip'=>$r->ip,'paged'=>1], admin_url('admin.php')));
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;gap:4px">
        <a href="<?php echo $filter_url; ?>" style="font-family:monospace;color:#2271b1;font-size:11px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr($r->ip); ?>"><?php echo esc_html($r->ip); ?></a>
        <span style="background:#f0f0f0;border-radius:3px;padding:0 5px;font-size:11px;color:#555;white-space:nowrap"><?php echo (int)$r->cnt; ?>×</span>
        <?php if ($is_blocked): ?>
        <span title="Đã block" style="color:#00a32a;font-size:14px">🛡</span>
        <?php else: ?>
        <a href="#" class="ads-quick-block" data-ip="<?php echo esc_attr($r->ip); ?>" title="Block IP này" style="color:#d63638;font-size:14px;text-decoration:none">⊘</a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div></div>
    <?php endif; ?>

    <div>
    <table class="widefat striped" style="font-size:12px">
      <thead>
        <tr>
          <th style="width:130px">Thời gian</th>
          <th style="width:125px">IP</th>
          <th>URL</th>
          <th style="width:160px">User Agent</th>
          <th style="width:80px">Thao tác</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" style="text-align:center;color:#999;padding:20px">Chưa có log nào.</td></tr>
      <?php else: foreach ($rows as $row):
        $is_manual = in_array($row->ip, $already_blocked);
        $is_matomo = in_array($row->ip, $matomo_ips);
      ?>
        <tr>
          <td style="color:#999;font-size:11px"><?php echo esc_html(substr($row->blocked_at, 0, 16)); ?></td>
          <td>
            <code style="font-size:11px"><?php echo esc_html($row->ip); ?></code>
            <?php if ($is_manual): ?><span title="Block thủ công" style="color:#f0860a;font-size:11px"> ✋</span>
            <?php elseif ($is_matomo): ?><span title="Block từ Matomo" style="color:#2271b1;font-size:11px"> 📊</span>
            <?php endif; ?>
          </td>
          <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <span title="<?php echo esc_attr($row->url); ?>" style="color:#555"><?php echo esc_html($row->url); ?></span>
          </td>
          <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#999;font-size:11px">
            <span title="<?php echo esc_attr($row->user_agent ?? ''); ?>"><?php echo esc_html($row->user_agent ?? ''); ?></span>
          </td>
          <td style="white-space:nowrap">
            <a href="#" class="ads-ip-rep button button-small" data-ip="<?php echo esc_attr($row->ip); ?>" title="IP Reputation" style="padding:2px 6px;height:24px;line-height:22px;font-size:11px">🔍</a>
            <?php if (!$is_manual && !$is_matomo): ?>
            <a href="#" class="ads-quick-block button button-small" data-ip="<?php echo esc_attr($row->ip); ?>" title="Block IP này" style="padding:2px 6px;height:24px;line-height:22px;font-size:11px;color:#d63638">⊘</a>
            <?php endif; ?>
            <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-protect','tab'=>'log','lip'=>$row->ip,'paged'=>1], admin_url('admin.php'))); ?>" class="button button-small" title="Lọc IP này" style="padding:2px 6px;height:24px;line-height:22px;font-size:11px">▼</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php // ── Pagination ── ?>
    <?php if ($pages > 1):
      $base_args = ['page'=>'adsdefender-protect','tab'=>'log','lip'=>$ip_filter,'ldate'=>$date_filter];
    ?>
    <div style="margin-top:10px;display:flex;gap:5px;align-items:center;flex-wrap:wrap">
      <span style="color:#666;font-size:12px">Trang <?php echo $page; ?>/<?php echo $pages; ?> &nbsp;</span>
      <?php if ($page > 1): ?><a href="<?php echo esc_url(add_query_arg(array_merge($base_args,['paged'=>1]), admin_url('admin.php'))); ?>" class="button button-small">«</a><?php endif; ?>
      <?php for ($p = max(1,$page-4); $p <= min($pages,$page+4); $p++):
        $purl = esc_url(add_query_arg(array_merge($base_args,['paged'=>$p]), admin_url('admin.php')));
      ?><a href="<?php echo $purl; ?>" class="button button-small<?php echo $p===$page?' button-primary':''; ?>" style="min-width:30px;text-align:center"><?php echo $p; ?></a><?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?php echo esc_url(add_query_arg(array_merge($base_args,['paged'=>$pages]), admin_url('admin.php'))); ?>" class="button button-small">»</a><?php endif; ?>
    </div>
    <?php endif; ?>
    </div><!-- /table col -->
    </div><!-- /grid -->

    <?php // ── Quick Block Modal ── ?>
    <div id="ads-quick-block-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999998;align-items:center;justify-content:center">
      <div style="background:#fff;border-radius:8px;padding:24px 28px;max-width:400px;width:90%;position:relative">
        <button onclick="document.getElementById('ads-quick-block-modal').style.display='none'"
                style="position:absolute;top:10px;right:14px;background:none;border:none;font-size:22px;cursor:pointer;color:#666">&times;</button>
        <h3 style="margin:0 0 14px">⊘ Block IP thủ công</h3>
        <form method="post">
          <?php wp_nonce_field('ads_log_block_ip'); ?>
          <input type="hidden" name="ads_log_ip" id="ads-block-ip-val" value="">
          <p style="margin:0 0 10px;font-size:13px">IP: <code id="ads-block-ip-show"></code></p>
          <p style="margin:0 0 10px">
            <label style="font-size:13px;font-weight:600">Ghi chú:</label><br>
            <input type="text" name="ads_log_note" value="Block from log" class="regular-text" style="margin-top:4px">
          </p>
          <button type="submit" name="ads_log_block_ip" class="button button-primary">Xác nhận Block</button>
        </form>
      </div>
    </div>
    <script>
    document.querySelectorAll('.ads-quick-block').forEach(function(el){
      el.addEventListener('click', function(e){
        e.preventDefault();
        var ip = this.dataset.ip;
        document.getElementById('ads-block-ip-val').value = ip;
        document.getElementById('ads-block-ip-show').textContent = ip;
        document.getElementById('ads-quick-block-modal').style.display = 'flex';
      });
    });
    </script>

    <?php adsdefender_ip_rep_modal(); ?>
    <?php
}

// ─── Trang Whitelist ─────────────────────────────────────────────────────────

function adsdefender_page_whitelist()
{
    $opts = adsdefender_settings();

    if (isset($_POST['adsdefender_save_whitelist']) && check_admin_referer('adsdefender_whitelist')) {
        $lines = array_filter(array_map(function ($line) {
            $line = trim($line);
            if (filter_var($line, FILTER_VALIDATE_IP)) return $line;
            if (preg_match('/^[\da-fA-F:\.]+\/\d{1,3}$/', $line)) return $line;
            return '';
        }, explode("\n", sanitize_textarea_field($_POST['whitelist_raw'] ?? ''))));
        $opts['whitelist'] = implode("\n", $lines);
        update_option('adsdefender_settings', $opts);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu whitelist.</p></div>';
    }

    $whitelist = $opts['whitelist'] ?? '';
    $lines     = array_filter(array_map('trim', explode("\n", $whitelist)));
    $my_ip     = adsdefender_get_real_ip() ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    ?>
    <p>IP trong danh sách này sẽ <strong>không bao giờ bị block</strong>.<br>
       Mỗi dòng 1 IP (<code>1.2.3.4</code>) hoặc CIDR (<code>192.168.1.0/24</code>, <code>2405:4803::/32</code>).</p>

    <?php if (!empty($lines)): ?>
    <div style="margin-bottom:16px;padding:10px 14px;background:#f0f6fc;border-left:4px solid #2271b1;border-radius:2px">
        <strong><?php echo count($lines); ?> entries đang whitelist:</strong><br style="margin-bottom:4px">
        <?php foreach ($lines as $line): ?>
            <code style="margin:2px 6px 2px 0;display:inline-block"><?php echo esc_html($line); ?></code>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field('adsdefender_whitelist'); ?>
        <textarea name="whitelist_raw" rows="12" class="large-text code"
            placeholder="1.2.3.4&#10;192.168.1.0/24&#10;2405:4803::/32&#10;..."><?php echo esc_textarea($whitelist); ?></textarea>
        <p class="description">IP/CIDR không hợp lệ sẽ tự động bị bỏ qua khi lưu.</p>
        <br>
        <button type="submit" name="adsdefender_save_whitelist" class="button button-primary">💾 Lưu Whitelist</button>
    </form>

    <hr>
    <p>IP hiện tại của bạn: <code><strong><?php echo esc_html($my_ip); ?></strong></code>
    <a href="#" style="margin-left:8px" onclick="
        var ta=document.querySelector('textarea[name=whitelist_raw]');
        var ip='<?php echo esc_js($my_ip); ?>';
        if(ta.value.indexOf(ip)===-1){ta.value+=(ta.value?'\n':'')+ip;}
        return false;">+ Thêm vào whitelist</a></p>
    <?php
}

// ─── Trang Scripts & Tracking ────────────────────────────────────────────────

function adsdefender_page_scripts()
{
    $scripts  = adsdefender_get_scripts();
    $tracking = adsdefender_get_tracking_settings();
    $tg       = adsdefender_telegram_cfg();

    if (isset($_POST['adstg_save']) && check_admin_referer('adstg_save')) {
        $tg = [
            'enabled'   => !empty($_POST['tg_enabled'])   ? 1 : 0,
            'bot_token' => sanitize_text_field(trim($_POST['tg_bot_token'] ?? '')),
            'chat_id'   => sanitize_text_field(trim($_POST['tg_chat_id']   ?? '')),
            'on_lead'   => !empty($_POST['tg_on_lead'])   ? 1 : 0,
            'on_block'  => !empty($_POST['tg_on_block'])  ? 1 : 0,
        ];
        update_option('adsdefender_telegram', $tg, false);
        // Test gửi nếu tick
        if (!empty($_POST['tg_test_send']) && !empty($tg['enabled'])) {
            $ok = adsdefender_telegram_send('✅ <b>AdsDefender</b> — Kết nối Telegram thành công!');
            echo $ok
                ? '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu và gửi tin nhắn test thành công!</p></div>'
                : '<div class="notice notice-warning is-dismissible"><p>✅ Đã lưu nhưng gửi test thất bại — kiểm tra Bot Token và Chat ID.</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu cấu hình Telegram.</p></div>';
        }
    }

    if (isset($_POST['adsdefender_save_tracking']) && check_admin_referer('adsdefender_tracking')) {
        $tracking = [
            'gtm_id'          => preg_replace('/[^A-Z0-9\-]/', '', strtoupper(trim($_POST['gtm_id'] ?? ''))),
            'gtm_enabled'     => !empty($_POST['gtm_enabled'])     ? 1 : 0,
            'tracksg_id'      => sanitize_text_field(trim($_POST['tracksg_id'] ?? '')),
            'tracksg_enabled' => !empty($_POST['tracksg_enabled']) ? 1 : 0,
            'pixel_id'        => preg_replace('/[^0-9]/', '', trim($_POST['pixel_id'] ?? '')),
            'pixel_enabled'   => !empty($_POST['pixel_enabled'])   ? 1 : 0,
        ];
        update_option('adsdefender_tracking', $tracking, false);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu cấu hình tracking.</p></div>';
    }

    if (isset($_POST['adsdefender_save_scripts']) && check_admin_referer('adsdefender_scripts')) {
        $scripts = [
            'header'      => wp_unslash($_POST['script_header']      ?? ''),
            'body_top'    => wp_unslash($_POST['script_body_top']    ?? ''),
            'body_bottom' => wp_unslash($_POST['script_body_bottom'] ?? ''),
            'footer'      => wp_unslash($_POST['script_footer']      ?? ''),
        ];
        update_option(ADSDEFENDER_OPTION_SCRIPTS, $scripts, false);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu custom scripts.</p></div>';
    }

    $gtm_id          = $tracking['gtm_id']          ?? '';
    $gtm_enabled     = $tracking['gtm_enabled']      ?? 1;
    $tracksg_id      = $tracking['tracksg_id']       ?? '';
    $tracksg_enabled = $tracking['tracksg_enabled']  ?? 1;
    $pixel_id        = $tracking['pixel_id']         ?? '';
    $pixel_enabled   = $tracking['pixel_enabled']    ?? 1;

    $tools = [
        [
            'key'         => 'gtm',
            'id_field'    => 'gtm_id',
            'id_val'      => $gtm_id,
            'enabled'     => $gtm_enabled,
            'label'       => 'Google Tag Manager',
            'icon'        => '🏷',
            'placeholder' => 'GTM-XXXXXXX',
            'desc'        => 'Lấy tại Google Tag Manager › Admin › Container ID',
            'color'       => '#246fdb',
        ],
        [
            'key'         => 'tracksg',
            'id_field'    => 'tracksg_id',
            'id_val'      => $tracksg_id,
            'enabled'     => $tracksg_enabled,
            'label'       => 'TrackSG (Matomo)',
            'icon'        => '📊',
            'placeholder' => '54',
            'desc'        => 'Site ID tại track.saigon.pro › Cài đặt › Site',
            'color'       => '#1a7a4a',
        ],
        [
            'key'         => 'pixel',
            'id_field'    => 'pixel_id',
            'id_val'      => $pixel_id,
            'enabled'     => $pixel_enabled,
            'label'       => 'Meta Pixel (Facebook)',
            'icon'        => '📘',
            'placeholder' => '499327509637576',
            'desc'        => 'Lấy tại Events Manager › Pixel › Cài đặt',
            'color'       => '#0866ff',
        ],
    ];
    ?>
    <div style="background:#fff;border:2px solid #2271b1;border-radius:6px;padding:20px 24px;margin-bottom:28px">
        <h2 style="margin-top:0;color:#2271b1">🚀 Tích hợp nhanh</h2>
        <p style="color:#555;margin-bottom:12px">
            Nhập ID và bật/tắt từng công cụ — plugin tự generate đúng script và inject vào <code>&lt;head&gt;</code>.
        </p>

        <div style="margin-bottom:16px">
            <button type="button" id="ads-scan-tracking" class="button" style="display:inline-flex;align-items:center;gap:6px">
                🔍 Quét tự động từ trang chủ
            </button>
            <span id="ads-scan-msg" style="margin-left:12px;font-size:13px;color:#555"></span>
        </div>

        <form method="post">
            <?php wp_nonce_field('adsdefender_tracking'); ?>
            <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px">
            <?php foreach ($tools as $tool):
                $active = !empty($tool['id_val']) && !empty($tool['enabled']);
            ?>
            <div style="border:1px solid <?php echo $active ? $tool['color'] : '#ddd'; ?>;border-radius:6px;padding:14px 18px;background:<?php echo $active ? 'linear-gradient(135deg,'.($tool['color']).'08,transparent)' : '#fafafa'; ?>;transition:border-color .2s" id="ads-tool-row-<?php echo $tool['key']; ?>">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <span style="font-size:22px"><?php echo $tool['icon']; ?></span>
                    <strong style="font-size:14px;color:#1d2327;min-width:180px"><?php echo $tool['label']; ?></strong>
                    <input type="text"
                        id="<?php echo $tool['id_field']; ?>"
                        name="<?php echo $tool['id_field']; ?>"
                        value="<?php echo esc_attr($tool['id_val']); ?>"
                        placeholder="<?php echo esc_attr($tool['placeholder']); ?>"
                        style="font-family:monospace;font-size:13px;width:220px;border-color:<?php echo $active ? $tool['color'] : '#8c8f94'; ?>">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin-left:4px">
                        <input type="hidden" name="<?php echo $tool['key']; ?>_enabled" value="0">
                        <input type="checkbox"
                            id="<?php echo $tool['key']; ?>_enabled_cb"
                            name="<?php echo $tool['key']; ?>_enabled"
                            value="1"
                            <?php checked($tool['enabled'], 1); ?>
                            onchange="this.closest('div[style]').style.borderColor=this.checked&&this.previousElementSibling.previousElementSibling.value?'<?php echo $tool['color']; ?>':'#ddd'">
                        <span style="font-size:13px;font-weight:600;color:<?php echo $active ? $tool['color'] : '#666'; ?>">
                            <?php echo $active ? '✅ Đang bật' : '⭕ Tắt'; ?>
                        </span>
                    </label>
                    <?php if (!empty($tool['id_val']) && empty($tool['enabled'])): ?>
                        <span style="font-size:12px;color:#999">(có ID nhưng đang tắt)</span>
                    <?php endif; ?>
                </div>
                <p style="margin:6px 0 0 34px;font-size:12px;color:#888"><?php echo $tool['desc']; ?></p>
            </div>
            <?php endforeach; ?>
            </div>

            <button type="submit" name="adsdefender_save_tracking" class="button button-primary">
                💾 Lưu cấu hình Tracking
            </button>
        </form>

        <script>
        (function(){
            var btn  = document.getElementById('ads-scan-tracking');
            var msg  = document.getElementById('ads-scan-msg');
            if (!btn) return;
            btn.addEventListener('click', function(){
                btn.disabled = true;
                btn.textContent = '⏳ Đang quét...';
                msg.textContent = '';
                var fd = new FormData();
                fd.append('action', 'adsdefender_detect_tracking');
                fd.append('nonce', '<?php echo esc_js(wp_create_nonce('adsdefender_detect_tracking')); ?>');
                fetch(ajaxurl, {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.innerHTML = '🔍 Quét tự động từ trang chủ';
                        if (!res.success) {
                            msg.style.color = '#c00';
                            msg.textContent = res.data || 'Lỗi không xác định';
                            return;
                        }
                        var d = res.data;
                        var filled = [];
                        if (d.gtm_id) {
                            var inp = document.getElementById('gtm_id');
                            if (inp) { inp.value = d.gtm_id; filled.push('GTM'); }
                            var cb = document.getElementById('gtm_enabled_cb');
                            if (cb && !cb.checked) { cb.checked = true; }
                        }
                        if (d.pixel_id) {
                            var inp2 = document.getElementById('pixel_id');
                            if (inp2) { inp2.value = d.pixel_id; filled.push('Pixel'); }
                            var cb2 = document.getElementById('pixel_enabled_cb');
                            if (cb2 && !cb2.checked) { cb2.checked = true; }
                        }
                        if (d.tracksg_id) {
                            var inp3 = document.getElementById('tracksg_id');
                            if (inp3) { inp3.value = d.tracksg_id; filled.push('TrackSG'); }
                            var cb3 = document.getElementById('tracksg_enabled_cb');
                            if (cb3 && !cb3.checked) { cb3.checked = true; }
                        }
                        msg.style.color = filled.length ? '#008a20' : '#996800';
                        msg.textContent = d.message || (filled.length ? '✅ Đã điền: '+filled.join(', ') : '⚠️ Không tìm thấy');
                        if (filled.length) {
                            msg.textContent += ' — nhớ bấm Lưu!';
                        }
                    })
                    .catch(function(){ btn.disabled=false; btn.innerHTML='🔍 Quét tự động từ trang chủ'; msg.style.color='#c00'; msg.textContent='Lỗi kết nối'; });
            });
        })();
        </script>

        <?php if ($gtm_id || $tracksg_id || $pixel_id): ?>
        <div style="margin-top:20px;background:#f6f7f7;border-radius:4px;padding:14px 16px">
            <strong style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.5px">Preview script đang inject</strong>
            <?php if ($gtm_id && $gtm_enabled): ?>
            <details style="margin-top:10px">
                <summary style="cursor:pointer;font-size:13px;color:#2271b1">🏷 GTM — <code>&lt;head&gt;</code></summary>
                <pre style="font-size:11px;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;overflow-x:auto;margin-top:8px;white-space:pre-wrap"><?php echo esc_html(adsdefender_render_gtm_head($gtm_id)); ?></pre>
            </details>
            <details style="margin-top:4px">
                <summary style="cursor:pointer;font-size:13px;color:#2271b1">🏷 GTM — noscript sau <code>&lt;body&gt;</code></summary>
                <pre style="font-size:11px;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;overflow-x:auto;margin-top:8px;white-space:pre-wrap"><?php echo esc_html(adsdefender_render_gtm_body($gtm_id)); ?></pre>
            </details>
            <?php endif; ?>
            <?php if ($tracksg_id && $tracksg_enabled): ?>
            <details style="margin-top:4px">
                <summary style="cursor:pointer;font-size:13px;color:#1a7a4a">📊 TrackSG — <code>&lt;head&gt;</code></summary>
                <pre style="font-size:11px;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;overflow-x:auto;margin-top:8px;white-space:pre-wrap"><?php echo esc_html(adsdefender_render_tracksg_head($tracksg_id)); ?></pre>
            </details>
            <details style="margin-top:4px">
                <summary style="cursor:pointer;font-size:13px;color:#1a7a4a">📊 TrackSG — noscript sau <code>&lt;body&gt;</code></summary>
                <pre style="font-size:11px;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;overflow-x:auto;margin-top:8px;white-space:pre-wrap"><?php echo esc_html(adsdefender_render_tracksg_noscript($tracksg_id)); ?></pre>
            </details>
            <?php endif; ?>
            <?php if ($pixel_id && $pixel_enabled): ?>
            <details style="margin-top:4px">
                <summary style="cursor:pointer;font-size:13px;color:#0866ff">📘 Meta Pixel — <code>&lt;head&gt;</code></summary>
                <pre style="font-size:11px;background:#1e1e1e;color:#d4d4d4;padding:12px;border-radius:4px;overflow-x:auto;margin-top:8px;white-space:pre-wrap"><?php echo esc_html(adsdefender_render_pixel_head($pixel_id)); ?></pre>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <h2 style="margin-bottom:4px">📝 Custom Scripts</h2>
    <p style="color:#666;margin-bottom:20px">
        Dành cho chat widget, schema JSON-LD, hoặc bất kỳ script nào khác.<br>
        Hỗ trợ HTML, <code>&lt;script&gt;</code>, <code>&lt;noscript&gt;</code>, <code>&lt;style&gt;</code>.
    </p>

    <form method="post">
        <?php wp_nonce_field('adsdefender_scripts'); ?>
        <?php
        $fields = [
            'header'      => ['label' => '🔝 Header Scripts', 'desc' => 'Inject vào cuối <code>&lt;head&gt;</code> — Meta Pixel init, Google Analytics, hreflang...', 'rows' => 8],
            'body_top'    => ['label' => '⬆️ Body Top',       'desc' => 'Inject ngay sau <code>&lt;body&gt;</code> mở — Meta Pixel noscript, custom noscript...', 'rows' => 5],
            'body_bottom' => ['label' => '⬇️ Body Bottom',    'desc' => 'Inject cuối body trước footer — contact bar, chat widget, phone tracking...', 'rows' => 8],
            'footer'      => ['label' => '📄 Footer Scripts', 'desc' => 'Inject trước <code>&lt;/body&gt;</code> — deferred scripts, schema JSON-LD...', 'rows' => 6],
        ];
        foreach ($fields as $key => $field): ?>
        <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:16px">
            <h3 style="margin-top:0;margin-bottom:4px"><?php echo $field['label']; ?></h3>
            <p style="color:#666;font-size:13px;margin:0 0 10px"><?php echo $field['desc']; ?></p>
            <textarea
                name="script_<?php echo $key; ?>"
                rows="<?php echo $field['rows']; ?>"
                class="large-text code"
                style="font-family:monospace;font-size:12px;background:#1e1e1e;color:#d4d4d4;border:none;border-radius:4px;padding:12px;resize:vertical"
                placeholder="<!-- Dán script vào đây -->"
                spellcheck="false"
            ><?php echo esc_textarea($scripts[$key] ?? ''); ?></textarea>
            <p style="margin:6px 0 0;font-size:11px;color:#999">
                <?php echo !empty(trim($scripts[$key] ?? '')) ? '✅ Đang có ' . (substr_count(trim($scripts[$key]), "\n") + 1) . ' dòng' : '(trống)'; ?>
            </p>
        </div>
        <?php endforeach; ?>

        <button type="submit" name="adsdefender_save_scripts" class="button button-primary button-large">💾 Lưu Custom Scripts</button>
    </form>

    <hr style="margin-top:30px">
    <h3>📌 Thứ tự inject</h3>
    <table class="widefat" style="max-width:760px">
        <thead><tr><th>Vị trí</th><th>Inject gì</th><th>Hook</th></tr></thead>
        <tbody>
            <tr><td><strong>&lt;head&gt;</strong></td><td>GTM script → TrackSG script → Header Scripts</td><td><code>wp_head</code> p=1</td></tr>
            <tr><td><strong>Sau &lt;body&gt;</strong></td><td>GTM noscript → Body Top Scripts</td><td><code>wp_body_open</code> p=1</td></tr>
            <tr><td><strong>Body Bottom</strong></td><td>Body Bottom Scripts</td><td><code>wp_footer</code> p=1</td></tr>
            <tr><td><strong>Trước &lt;/body&gt;</strong></td><td>Footer Scripts</td><td><code>wp_footer</code> p=99</td></tr>
        </tbody>
    </table>
    <p style="color:#999;font-size:12px;margin-top:8px">
        ⚠️ Body Top yêu cầu theme hỗ trợ <code>wp_body_open()</code> — Flatsome v3.9+ đã hỗ trợ.
    </p>

    <hr style="margin-top:30px">
    <h2>📲 Telegram Alert</h2>
    <p style="color:#555;max-width:620px">Nhận thông báo tức thì qua Telegram khi có lead mới hoặc IP mới bị block.
    <a href="https://core.telegram.org/bots#botfather" target="_blank">Tạo bot tại BotFather →</a></p>

    <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px 24px;max-width:600px">
    <form method="post">
    <?php wp_nonce_field('adstg_save'); ?>
    <table class="form-table" style="margin:0">
    <tr>
        <th style="width:160px">Bật Telegram</th>
        <td><label><input type="checkbox" name="tg_enabled" value="1" <?php checked($tg['enabled'] ?? 0, 1); ?>>
        Kích hoạt thông báo</label></td>
    </tr>
    <tr>
        <th>Bot Token</th>
        <td><input type="text" name="tg_bot_token" value="<?php echo esc_attr($tg['bot_token'] ?? ''); ?>"
            class="large-text" placeholder="123456789:AAFxxxxxx..." style="font-family:monospace;font-size:12px">
            <p class="description">Lấy từ @BotFather → /newbot → copy token</p></td>
    </tr>
    <tr>
        <th>Chat ID</th>
        <td><input type="text" name="tg_chat_id" value="<?php echo esc_attr($tg['chat_id'] ?? ''); ?>"
            class="regular-text" placeholder="-100xxxxxxxxxx hoặc @channel" style="font-family:monospace">
            <p class="description">Chat cá nhân, group, hoặc channel. Dùng @userinfobot để lấy ID.</p></td>
    </tr>
    <tr>
        <th>Thông báo khi</th>
        <td>
            <label style="margin-right:16px"><input type="checkbox" name="tg_on_lead" value="1" <?php checked($tg['on_lead'] ?? 1, 1); ?>> 📥 Lead mới</label>
            <label><input type="checkbox" name="tg_on_block" value="1" <?php checked($tg['on_block'] ?? 1, 1); ?>> 🚫 IP mới bị block (cron)</label>
        </td>
    </tr>
    <tr>
        <th>Gửi test</th>
        <td><label><input type="checkbox" name="tg_test_send" value="1"> Gửi tin nhắn kiểm tra ngay khi lưu</label></td>
    </tr>
    </table>
    <div style="margin-top:16px">
        <button type="submit" name="adstg_save" class="button button-primary">💾 Lưu Telegram</button>
        <?php if (!empty($tg['enabled'])): ?>
        <span style="margin-left:12px;color:#00a32a;font-size:13px">✅ Đang bật</span>
        <?php endif; ?>
    </div>
    </form>

    <div style="margin-top:16px;padding:14px;background:#f6f7f7;border-radius:6px;font-size:12px;color:#555">
        <strong>Hướng dẫn nhanh:</strong>
        <ol style="margin:8px 0 0 16px;line-height:1.9">
            <li>Chat với <code>@BotFather</code> → <code>/newbot</code> → lấy token</li>
            <li>Add bot vào group/channel, hoặc chat trực tiếp với bot</li>
            <li>Lấy Chat ID: chat với <code>@userinfobot</code> hoặc forward 1 tin vào <code>@JsonDumpBot</code></li>
            <li>Paste token + chat ID vào ô trên → tick "Gửi test" → Lưu</li>
        </ol>
    </div>
    </div>
    <?php
}

// ─── Trang Block Thủ Công ────────────────────────────────────────────────────

function adsdefender_page_manual()
{
    $manual = get_option(ADSDEFENDER_OPTION_MANUAL, []);
    $notice = '';

    if (isset($_POST['adsdefender_manual_add']) && check_admin_referer('adsdefender_manual_add')) {
        $new_ip   = trim(sanitize_text_field($_POST['manual_ip']   ?? ''));
        $new_note = trim(sanitize_text_field($_POST['manual_note'] ?? ''));
        if (!filter_var($new_ip, FILTER_VALIDATE_IP) && !preg_match('/^[\da-fA-F:\.]+\/\d{1,3}$/', $new_ip)) {
            $notice = ['type' => 'error', 'msg' => "IP không hợp lệ: <code>" . esc_html($new_ip) . "</code>"];
        } elseif (in_array($new_ip, array_column($manual, 'ip'), true)) {
            $notice = ['type' => 'warning', 'msg' => "<code>" . esc_html($new_ip) . "</code> đã có trong danh sách."];
        } else {
            $manual[] = ['ip' => $new_ip, 'note' => $new_note, 'added_at' => current_time('mysql')];
            update_option(ADSDEFENDER_OPTION_MANUAL, $manual, false);
            $notice = ['type' => 'success', 'msg' => "✅ Đã block <code>" . esc_html($new_ip) . "</code>"];
        }
    }

    if (isset($_GET['adsdefender_manual_remove']) && isset($_GET['_wpnonce'])) {
        if (wp_verify_nonce($_GET['_wpnonce'], 'adsdefender_remove_' . $_GET['adsdefender_manual_remove'])) {
            $remove_ip = sanitize_text_field($_GET['adsdefender_manual_remove']);
            $manual    = array_values(array_filter($manual, fn($m) => $m['ip'] !== $remove_ip));
            update_option(ADSDEFENDER_OPTION_MANUAL, $manual, false);
            $notice = ['type' => 'success', 'msg' => "Đã xóa <code>" . esc_html($remove_ip) . "</code> khỏi danh sách."];
        }
    }

    if (isset($_POST['adsdefender_manual_clear']) && check_admin_referer('adsdefender_manual_clear')) {
        update_option(ADSDEFENDER_OPTION_MANUAL, [], false);
        $manual = [];
        $notice = ['type' => 'success', 'msg' => 'Đã xóa toàn bộ danh sách block thủ công.'];
    }

    $my_ip = adsdefender_get_real_ip() ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    ?>
    <p style="color:#666">Danh sách này do bạn tự quản lý, <strong>không bị ghi đè</strong> khi sync từ Matomo.<br>
        Hỗ trợ IP đơn (<code>1.2.3.4</code>), IPv6 (<code>2405:4803::1</code>), và CIDR (<code>1.2.3.0/24</code>).</p>

    <?php if ($notice): ?>
    <div class="notice notice-<?php echo $notice['type']; ?> is-dismissible"><p><?php echo $notice['msg']; ?></p></div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;max-width:600px;margin-bottom:24px">
        <h3 style="margin-top:0">➕ Thêm IP block</h3>
        <form method="post">
            <?php wp_nonce_field('adsdefender_manual_add'); ?>
            <table class="form-table" style="margin:0">
                <tr>
                    <th style="width:100px;padding:6px 0">IP / CIDR</th>
                    <td style="padding:6px 0">
                        <input type="text" name="manual_ip" id="manual_ip"
                            value="<?php echo esc_attr($_POST['manual_ip'] ?? ''); ?>"
                            class="regular-text" placeholder="1.2.3.4 hoặc 1.2.3.0/24"
                            style="font-family:monospace" required>
                        <button type="button" class="button" style="margin-left:6px"
                            onclick="document.getElementById('manual_ip').value='<?php echo esc_js($my_ip); ?>'">
                            IP của tôi
                        </button>
                    </td>
                </tr>
                <tr>
                    <th style="padding:6px 0">Ghi chú</th>
                    <td style="padding:6px 0">
                        <input type="text" name="manual_note"
                            value="<?php echo esc_attr($_POST['manual_note'] ?? ''); ?>"
                            class="regular-text" placeholder="VD: Click fraud ngày 24/04, competitor...">
                    </td>
                </tr>
            </table>
            <div style="margin-top:12px">
                <button type="submit" name="adsdefender_manual_add" class="button button-primary">🚫 Block IP này</button>
            </div>
        </form>
    </div>

    <h3>Danh sách đang block thủ công (<?php echo count($manual); ?> entries)</h3>

    <?php if (empty($manual)): ?>
    <p style="color:#999">Chưa có IP nào được block thủ công.</p>
    <?php else: ?>
    <table class="widefat striped" style="max-width:800px">
        <thead>
            <tr>
                <th style="width:180px">IP / CIDR</th>
                <th>Ghi chú</th>
                <th style="width:150px">Thêm lúc</th>
                <th style="width:80px">Xóa</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($manual as $m):
            $remove_url = wp_nonce_url(
                add_query_arg('adsdefender_manual_remove', urlencode($m['ip'])),
                'adsdefender_remove_' . $m['ip']
            ); ?>
            <tr>
                <td><code><?php echo esc_html($m['ip']); ?></code></td>
                <td style="color:#555"><?php echo esc_html($m['note'] ?? ''); ?></td>
                <td style="color:#888;font-size:12px"><?php echo esc_html($m['added_at'] ?? ''); ?></td>
                <td>
                    <a href="<?php echo esc_url($remove_url); ?>"
                        style="color:#d63638"
                        onclick="return confirm('Xóa <?php echo esc_js($m['ip']); ?> khỏi danh sách?')">
                        ✕ Xóa
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top:16px">
        <?php wp_nonce_field('adsdefender_manual_clear'); ?>
        <button type="submit" name="adsdefender_manual_clear" class="button button-secondary"
            onclick="return confirm('Xóa toàn bộ danh sách block thủ công?')">
            🗑 Xóa tất cả
        </button>
    </form>
    <?php endif; ?>
    <?php
}

// ─── Trang Cập Nhật ──────────────────────────────────────────────────────────

function adsdefender_page_update()
{
    $update_status = sanitize_key($_GET['update'] ?? '');
    $new_version   = sanitize_text_field($_GET['new_version'] ?? '');

    if (isset($_POST['adsdefender_check_update']) && check_admin_referer('adsdefender_check_update')) {
        delete_transient('adsdefender_update_info');
    }

    $remote     = adsdefender_fetch_update_info();
    $has_update = $remote && version_compare($remote['version'] ?? '0', ADSDEFENDER_VERSION, '>');
    $changelog  = $remote['changelog'] ?? '';
    ?>
    <?php if ($update_status === 'success'): ?>
        <div class="notice notice-success is-dismissible">
            <p>✅ Cập nhật lên <strong>v<?php echo esc_html($new_version); ?></strong> thành công! Plugin đã được kích hoạt lại.</p>
        </div>
    <?php elseif ($update_status === 'already_latest'): ?>
        <div class="notice notice-info is-dismissible"><p>Bạn đang dùng phiên bản mới nhất rồi.</p></div>
    <?php elseif ($update_status === 'failed'): ?>
        <div class="notice notice-error is-dismissible"><p>❌ Cập nhật thất bại. Kiểm tra quyền ghi thư mục plugin hoặc tải thủ công.</p></div>
    <?php elseif ($update_status === 'fetch_failed'): ?>
        <div class="notice notice-error is-dismissible"><p>❌ Không lấy được thông tin update từ server.</p></div>
    <?php endif; ?>

    <table class="widefat" style="max-width:560px;margin-bottom:20px">
        <tr>
            <th style="width:180px">Phiên bản hiện tại</th>
            <td><strong>v<?php echo ADSDEFENDER_VERSION; ?></strong></td>
        </tr>
        <tr>
            <th>Phiên bản mới nhất</th>
            <td>
                <?php if (!$remote): ?>
                    <span style="color:#999">Không lấy được thông tin</span>
                <?php elseif ($has_update): ?>
                    <strong style="color:#d63638">v<?php echo esc_html($remote['version']); ?> — Có bản cập nhật!</strong>
                <?php else: ?>
                    <span style="color:#00a32a">✅ v<?php echo esc_html($remote['version']); ?> — Đang dùng bản mới nhất</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Nguồn cập nhật</th>
            <td><a href="<?php echo esc_url(ADSDEFENDER_UPDATE_URL); ?>" target="_blank"><?php echo esc_html(ADSDEFENDER_UPDATE_URL); ?></a></td>
        </tr>
    </table>

    <?php
    // Lưu toggle auto_update
    if (isset($_POST['adsdefender_save_auto_update']) && check_admin_referer('adsdefender_save_auto_update')) {
        $s = adsdefender_settings();
        $s['auto_update'] = isset($_POST['auto_update']) ? '1' : '0';
        update_option('adsdefender_settings', $s);
        adsdefender_settings(true);
        echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cài đặt.</p></div>';
    }
    $auto_update_on = (adsdefender_settings()['auto_update'] ?? '1') !== '0';
    ?>

    <table class="widefat" style="max-width:560px;margin-bottom:16px">
        <tr>
            <th style="width:180px">Tự động cập nhật nền</th>
            <td>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field('adsdefender_save_auto_update'); ?>
                    <label>
                        <input type="checkbox" name="auto_update" value="1" <?php checked($auto_update_on); ?>>
                        Bật — WordPress tự cập nhật khi có bản mới (không cần vào admin)
                    </label>
                    <input type="submit" name="adsdefender_save_auto_update" class="button" value="Lưu" style="margin-left:8px">
                </form>
                <p class="description" style="margin-top:4px">WP chạy background update qua wp-cron mỗi 12h. Sau khi update, plugin tự kích hoạt lại.</p>
            </td>
        </tr>
    </table>

    <form method="post" style="display:inline-block;margin-right:8px">
        <?php wp_nonce_field('adsdefender_check_update'); ?>
        <button type="submit" name="adsdefender_check_update" class="button">🔍 Kiểm tra ngay</button>
    </form>

    <?php if ($has_update): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
        <?php wp_nonce_field('adsdefender_do_update'); ?>
        <input type="hidden" name="action" value="adsdefender_do_update">
        <button type="submit" class="button button-primary"
            onclick="return confirm('Cập nhật lên v<?php echo esc_js($remote['version']); ?>?\nPlugin sẽ được cài đè và kích hoạt lại tự động.')">
            ⬆️ Cập nhật lên v<?php echo esc_html($remote['version']); ?>
        </button>
    </form>
    <?php endif; ?>

    <p style="margin-top:12px">
        <a href="<?php echo esc_url($remote['download_url'] ?? ADSDEFENDER_UPDATE_URL); ?>" class="button button-secondary" target="_blank">
            ⬇️ Tải zip thủ công
        </a>
    </p>

    <?php if ($changelog): ?>
    <hr>
    <h3>Changelog</h3>
    <div style="background:#f6f7f7;padding:12px 16px;border-radius:4px;max-width:560px;font-family:monospace;font-size:13px;white-space:pre-wrap"><?php echo esc_html($changelog); ?></div>
    <?php endif; ?>
    <?php
}

// ─── Security Event Log ───────────────────────────────────────────────────────

function adsdefender_page_seclog(): void
{
    $type_filter = sanitize_key($_GET['stype'] ?? '');
    $ip_filter   = sanitize_text_field($_GET['sip'] ?? '');
    $page        = max(1, (int)($_GET['spg'] ?? 1));
    $per_page    = 50;
    $offset      = ($page - 1) * $per_page;

    $rows  = adsdefender_sec_log_get($per_page, $offset, $type_filter, $ip_filter);
    $total = adsdefender_sec_log_count($type_filter, $ip_filter);
    $pages = max(1, (int)ceil($total / $per_page));

    $type_labels = [
        ''            => 'Tất cả',
        'brute_force' => '🔐 Brute Force',
        'firewall'    => '🔥 Firewall',
        'rate_limit'  => '⏱ Rate Limit',
        'flood_404'   => '🔍 404 Flood',
        'ip_block'    => '🚫 IP Block',
    ];
    $type_colors = [
        'brute_force' => '#d63638',
        'firewall'    => '#f0860a',
        'rate_limit'  => '#2271b1',
        'flood_404'   => '#888888',
        'ip_block'    => '#6741d9',
    ];
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
      <strong style="font-size:13px">Lọc loại:</strong>
      <?php foreach ($type_labels as $k => $lbl):
        $active = ($type_filter === $k);
        $url = esc_url(add_query_arg(['page'=>'adsdefender-protect','tab'=>'seclog','stype'=>$k,'spg'=>1], admin_url('admin.php')));
      ?>
      <a href="<?php echo $url; ?>" class="button<?php echo $active ? ' button-primary' : ''; ?>" style="font-size:12px;height:28px;line-height:27px;padding:0 10px"><?php echo $lbl; ?></a>
      <?php endforeach; ?>
      <form method="get" style="display:flex;gap:6px;margin-left:auto">
        <input type="hidden" name="page" value="adsdefender-protect">
        <input type="hidden" name="tab" value="seclog">
        <input type="hidden" name="stype" value="<?php echo esc_attr($type_filter); ?>">
        <input type="text" name="sip" value="<?php echo esc_attr($ip_filter); ?>" placeholder="Lọc IP..." style="width:150px;height:28px">
        <button type="submit" class="button" style="height:28px;line-height:27px">Tìm</button>
        <?php if ($ip_filter): ?>
        <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-protect','tab'=>'seclog','stype'=>$type_filter,'spg'=>1], admin_url('admin.php'))); ?>" class="button" style="height:28px;line-height:27px">✕</a>
        <?php endif; ?>
      </form>
    </div>

    <p style="color:#666;font-size:13px;margin-bottom:8px">Tổng <strong><?php echo $total; ?></strong> sự kiện<?php if ($ip_filter) echo ' — IP: <code>' . esc_html($ip_filter) . '</code>'; ?></p>

    <table class="widefat" style="font-size:12px">
      <thead><tr><th style="width:140px">Thời gian</th><th style="width:110px">Loại</th><th style="width:130px">IP</th><th>Chi tiết</th><th style="width:50px">Rep.</th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
      <tr><td colspan="5" style="text-align:center;color:#999;padding:20px">Không có sự kiện nào.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row):
        $col = $type_colors[$row['event_type']] ?? '#888';
      ?>
      <tr>
        <td style="color:#999"><?php echo esc_html(substr($row['created_at'], 0, 16)); ?></td>
        <td><span style="background:<?php echo $col; ?>22;color:<?php echo $col; ?>;border-radius:3px;padding:1px 6px;font-size:11px;font-weight:600"><?php echo esc_html($row['event_type']); ?></span></td>
        <td>
          <code style="font-size:11px"><?php echo esc_html($row['ip']); ?></code>
          <?php if ($row['ip']): ?>
          <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-protect','tab'=>'seclog','sip'=>$row['ip'],'stype'=>'','spg'=>1], admin_url('admin.php'))); ?>" title="Lọc IP này" style="color:#999;font-size:10px;margin-left:4px">▼</a>
          <?php endif; ?>
        </td>
        <td style="color:#555;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr($row['detail']); ?>"><?php echo esc_html($row['detail']); ?></td>
        <td><?php if ($row['ip']): ?><a href="#" class="ads-ip-rep" data-ip="<?php echo esc_attr($row['ip']); ?>" title="IP Reputation">🔍</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($pages > 1): ?>
    <div style="margin-top:12px;display:flex;gap:6px;align-items:center">
      <span style="color:#666;font-size:13px">Trang <?php echo $page; ?>/<?php echo $pages; ?></span>
      <?php for ($p = max(1,$page-3); $p <= min($pages,$page+3); $p++):
        $purl = esc_url(add_query_arg(['page'=>'adsdefender-protect','tab'=>'seclog','stype'=>$type_filter,'sip'=>$ip_filter,'spg'=>$p], admin_url('admin.php')));
      ?><a href="<?php echo $purl; ?>" class="button<?php echo $p===$page?' button-primary':''; ?>" style="font-size:12px;height:28px;line-height:27px;padding:0 10px;min-width:32px;text-align:center"><?php echo $p; ?></a><?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php adsdefender_ip_rep_modal(); ?>
    <?php
}

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
            Vào <a href="<?php echo esc_url(admin_url('admin.php?page=adsdefender-system&tab=settings#abuseipdb')); ?>">Hệ thống → Cài đặt</a> để thêm.
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
      function openModal(ip){
        var $m=$('#ads-ip-rep-modal'); $m.css('display','flex');
        $m.find('h3').text('🔍 IP Reputation: '+ip);
        if(!hasKey) return;
        $('#ads-ip-rep-loading').show(); $('#ads-ip-rep-content').hide().empty();
        $.post(ajax,{action:'adsdefender_ip_reputation',ip:ip,_ajax_nonce:nonce},function(r){
          $('#ads-ip-rep-loading').hide();
          if(!r.success){$('#ads-ip-rep-content').html('<div style="color:#d63638;font-size:13px">'+r.data+'</div>').show();return;}
          var d=r.data, sc=d.score>=75?'#d63638':d.score>=25?'#f0860a':'#00a32a';
          var h='<div style="display:grid;grid-template-columns:140px 1fr;gap:8px 12px;font-size:13px">';
          h+='<b style="color:#555">Abuse Score</b><span style="font-size:22px;font-weight:700;color:'+sc+'">'+d.score+'%</span>';
          h+='<b style="color:#555">Quốc gia</b><span>'+(d.country||'—')+'</span>';
          h+='<b style="color:#555">ISP</b><span>'+(d.isp||'—')+'</span>';
          h+='<b style="color:#555">Usage Type</b><span>'+(d.usage_type||'—')+'</span>';
          h+='<b style="color:#555">Reports</b><span>'+d.total_reports+' lần</span>';
          h+='<b style="color:#555">Báo cáo cuối</b><span>'+(d.last_reported?d.last_reported.substring(0,10):'—')+'</span>';
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
}

// Modal trên Dashboard page
add_action('admin_footer', function() {
    if (($GLOBALS['plugin_page'] ?? '') !== 'adsdefender') return;
    adsdefender_ip_rep_modal();
});

// ─── Admin Activity Log Page ──────────────────────────────────────────────────

function adsdefender_page_adminlog(): void
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_ADMIN_LOG_TABLE;

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        echo '<div class="notice notice-warning"><p>Bảng Admin Log chưa được tạo. <a href="' . esc_url(admin_url('admin.php?page=adsdefender-system&tab=settings')) . '">Bật Admin Log</a> trong Cài đặt để bắt đầu.</p></div>';
        return;
    }

    // Clear log
    if (isset($_POST['ads_clear_adminlog']) && check_admin_referer('ads_clear_adminlog')) {
        $wpdb->query("TRUNCATE TABLE `{$table}`");
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã xóa toàn bộ Admin Log.</p></div>';
    }

    // Filters
    $type_filter = sanitize_key($_GET['altype'] ?? '');
    $user_filter = sanitize_text_field($_GET['aluser'] ?? '');
    $date_filter = sanitize_text_field($_GET['aldate'] ?? '');
    $page        = max(1, (int)($_GET['alpg'] ?? 1));
    $per_page    = 50;
    $offset      = ($page - 1) * $per_page;

    $where = ['1=1'];
    $args  = [];
    if ($type_filter) { $where[] = 'event_type = %s';       $args[] = $type_filter; }
    if ($user_filter) { $where[] = 'user_login LIKE %s';    $args[] = '%' . $wpdb->esc_like($user_filter) . '%'; }
    if ($date_filter) { $where[] = 'DATE(created_at) = %s'; $args[] = $date_filter; }
    $where_sql = implode(' AND ', $where);

    $total = $args
        ? (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $args))
        : (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

    $qargs = array_merge($args, [$per_page, $offset]);
    $rows = $args
        ? $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $qargs), ARRAY_A)
        : $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset), ARRAY_A);

    $pages = max(1, (int)ceil($total / $per_page));

    $event_labels = [
        'login_ok'          => ['🟢', '#00a32a', 'Login OK'],
        'login_fail'        => ['🔴', '#d63638', 'Login Fail'],
        'logout'            => ['⚪', '#888',    'Logout'],
        'settings_change'   => ['⚙️', '#2271b1', 'Settings'],
        'plugin_activate'   => ['🔌', '#2271b1', 'Plugin ON'],
        'plugin_deactivate' => ['🔌', '#888',    'Plugin OFF'],
        'post_create'       => ['📝', '#00a32a', 'Post Create'],
        'post_update'       => ['✏️', '#f0860a', 'Post Update'],
        'post_delete'       => ['🗑', '#d63638', 'Post Delete'],
        'media_upload'      => ['🖼', '#00a32a', 'Media Upload'],
        'media_delete'      => ['🗑', '#d63638', 'Media Delete'],
        'user_create'       => ['👤', '#00a32a', 'User Create'],
        'user_update'       => ['✏️', '#f0860a', 'User Update'],
        'user_role_change'  => ['🔑', '#d63638', 'Role Change'],
        'user_delete'       => ['👤', '#d63638', 'User Delete'],
    ];

    if (!adsdefender_admin_log_enabled()):
    ?>
    <div class="notice notice-warning is-dismissible"><p>⚠️ Admin Log đang <strong>tắt</strong>. <a href="<?php echo esc_url(admin_url('admin.php?page=adsdefender-system&tab=settings')); ?>">Bật trong Cài đặt →</a></p></div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <form method="get" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="page" value="adsdefender-system">
        <input type="hidden" name="tab" value="adminlog">
        <select name="altype" style="height:30px">
          <option value="">Tất cả loại</option>
          <?php foreach ($event_labels as $k => [$icon, $col, $lbl]): ?>
          <option value="<?php echo esc_attr($k); ?>" <?php selected($type_filter, $k); ?>><?php echo $icon . ' ' . $lbl; ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="aluser" value="<?php echo esc_attr($user_filter); ?>" placeholder="Tìm user..." style="width:130px;height:30px">
        <input type="date" name="aldate" value="<?php echo esc_attr($date_filter); ?>" style="height:30px">
        <button type="submit" class="button" style="height:30px;line-height:29px">Lọc</button>
        <?php if ($type_filter || $user_filter || $date_filter): ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=adsdefender-system&tab=adminlog')); ?>" class="button" style="height:30px;line-height:29px">✕</a>
        <?php endif; ?>
      </form>
      <span style="color:#666;font-size:13px;margin-left:auto">Tổng <strong><?php echo $total; ?></strong> sự kiện</span>
      <form method="post" style="margin:0">
        <?php wp_nonce_field('ads_clear_adminlog'); ?>
        <button type="submit" name="ads_clear_adminlog" class="button button-secondary"
            style="height:30px;line-height:29px" onclick="return confirm('Xóa toàn bộ Admin Log?')">🗑 Xóa log</button>
      </form>
    </div>

    <table class="widefat" style="font-size:12px">
      <thead>
        <tr>
          <th style="width:130px">Thời gian</th>
          <th style="width:100px">Loại</th>
          <th style="width:110px">User</th>
          <th style="width:110px">IP</th>
          <th>Chi tiết</th>
          <th style="width:50px">Rep.</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
      <tr><td colspan="6" style="text-align:center;color:#999;padding:20px">Không có dữ liệu.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row):
        [$icon, $col, $lbl] = $event_labels[$row['event_type']] ?? ['•', '#888', $row['event_type']];
      ?>
      <tr>
        <td style="color:#999;font-size:11px"><?php echo esc_html(substr($row['created_at'], 0, 16)); ?></td>
        <td>
          <span style="background:<?php echo $col; ?>18;color:<?php echo $col; ?>;border-radius:3px;padding:1px 6px;font-size:11px;font-weight:600;white-space:nowrap">
            <?php echo $icon . ' ' . esc_html($lbl); ?>
          </span>
        </td>
        <td>
          <?php if ($row['user_login']): ?>
          <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-system','tab'=>'adminlog','aluser'=>$row['user_login'],'alpg'=>1], admin_url('admin.php'))); ?>"
             style="font-size:11px;font-family:monospace"><?php echo esc_html($row['user_login']); ?></a>
          <?php else: ?><span style="color:#999;font-size:11px">—</span><?php endif; ?>
        </td>
        <td><code style="font-size:11px"><?php echo esc_html($row['ip'] ?: '—'); ?></code></td>
        <td style="color:#555;max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
            title="<?php echo esc_attr($row['detail']); ?>"><?php echo esc_html($row['detail']); ?></td>
        <td><?php if ($row['ip']): ?>
          <a href="#" class="ads-ip-rep" data-ip="<?php echo esc_attr($row['ip']); ?>" title="IP Reputation" style="text-decoration:none">🔍</a>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($pages > 1):
      $base = ['page'=>'adsdefender-system','tab'=>'adminlog','altype'=>$type_filter,'aluser'=>$user_filter,'aldate'=>$date_filter];
    ?>
    <div style="margin-top:10px;display:flex;gap:5px;align-items:center;flex-wrap:wrap">
      <span style="color:#666;font-size:12px">Trang <?php echo $page; ?>/<?php echo $pages; ?></span>
      <?php if ($page>1): ?><a href="<?php echo esc_url(add_query_arg(array_merge($base,['alpg'=>1]), admin_url('admin.php'))); ?>" class="button button-small">«</a><?php endif; ?>
      <?php for ($p=max(1,$page-4);$p<=min($pages,$page+4);$p++): ?>
      <a href="<?php echo esc_url(add_query_arg(array_merge($base,['alpg'=>$p]), admin_url('admin.php'))); ?>"
         class="button button-small<?php echo $p===$page?' button-primary':''; ?>"
         style="min-width:30px;text-align:center"><?php echo $p; ?></a>
      <?php endfor; ?>
      <?php if ($page<$pages): ?><a href="<?php echo esc_url(add_query_arg(array_merge($base,['alpg'=>$pages]), admin_url('admin.php'))); ?>" class="button button-small">»</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php adsdefender_ip_rep_modal(); ?>
    <?php
}

// ─── Tab File Monitor ─────────────────────────────────────────────────────────

function adsdefender_page_filemonitor(): void
{
    if (!current_user_can('manage_options')) wp_die('Không có quyền truy cập.');

    $opts         = adsdefender_settings();
    $enabled      = !empty($opts['fm_enabled']);
    $last_scan    = get_option('adsdefender_fm_last_scan', '');
    $last_changes = (int) get_option('adsdefender_fm_last_changes', 0);
    $baseline_at  = get_option('adsdefender_fm_baseline_built', '');
    $last_alert   = get_option('adsdefender_fm_last_alert', '');
    $nonce        = wp_create_nonce('adsdefender_fm_scan');
    ?>
    <style>
    .fm-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px}
    .fm-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;min-width:160px;flex:1}
    .fm-card .fm-val{font-size:22px;font-weight:700;color:#1a202c}
    .fm-card .fm-lbl{font-size:12px;color:#718096;margin-top:2px}
    .fm-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.07)}
    .fm-table th{background:#f7fafc;padding:10px 14px;text-align:left;font-size:12px;text-transform:uppercase;color:#718096;border-bottom:1px solid #e2e8f0}
    .fm-table td{padding:9px 14px;border-bottom:1px solid #f0f4f8;font-size:13px;vertical-align:top}
    .fm-badge-added{background:#c6f6d5;color:#276749;padding:2px 8px;border-radius:10px;font-size:11px}
    .fm-badge-modified{background:#fefcbf;color:#7b5e0e;padding:2px 8px;border-radius:10px;font-size:11px}
    .fm-badge-deleted{background:#fed7d7;color:#9b2c2c;padding:2px 8px;border-radius:10px;font-size:11px}
    #fm-result{margin-top:16px}
    </style>

    <?php if (!$enabled): ?>
    <div class="notice notice-warning is-dismissible"><p>⚠️ File Monitor đang <strong>tắt</strong>. <a href="<?php echo esc_url(admin_url('admin.php?page=adsdefender-system&tab=settings&stab=protect')); ?>">Bật trong Cài đặt → Bảo vệ →</a></p></div>
    <?php endif; ?>

    <div class="fm-meta">
        <div class="fm-card">
            <div class="fm-val"><?php echo $enabled ? '🟢 Bật' : '🔴 Tắt'; ?></div>
            <div class="fm-lbl">Trạng thái</div>
        </div>
        <div class="fm-card">
            <div class="fm-val"><?php echo $last_scan ? esc_html(wp_date('H:i d/m/Y', strtotime($last_scan))) : '—'; ?></div>
            <div class="fm-lbl">Lần quét gần nhất</div>
        </div>
        <div class="fm-card">
            <div class="fm-val" style="color:<?php echo $last_changes > 0 ? '#c05621' : '#276749'; ?>"><?php echo $last_changes; ?></div>
            <div class="fm-lbl">Thay đổi lần quét trước</div>
        </div>
        <div class="fm-card">
            <div class="fm-val"><?php echo $baseline_at ? esc_html(wp_date('d/m/Y', strtotime($baseline_at))) : '—'; ?></div>
            <div class="fm-lbl">Baseline được tạo</div>
        </div>
        <?php if ($last_alert): ?>
        <div class="fm-card" style="border-color:#fc8181">
            <div class="fm-val" style="color:#c53030;font-size:14px"><?php echo esc_html(wp_date('H:i d/m/Y', strtotime($last_alert))); ?></div>
            <div class="fm-lbl">⚠️ Alert gần nhất</div>
        </div>
        <?php endif; ?>
    </div>

    <div style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button id="fm-btn-scan" class="button button-primary" data-nonce="<?php echo esc_attr($nonce); ?>">
            🔍 Quét ngay
        </button>
        <button id="fm-btn-rebuild" class="button" data-nonce="<?php echo esc_attr($nonce); ?>"
            onclick="return confirm('Rebuild baseline từ trạng thái hiện tại? Mọi thay đổi hiện tại sẽ được đánh dấu là baseline mới.')">
            🔄 Rebuild Baseline
        </button>
        <span id="fm-spinner" style="display:none;color:#718096">⏳ Đang quét...</span>
    </div>

    <div id="fm-result"></div>

    <script>
    (function(){
    function fmRequest(rebuild){
        var spinner = document.getElementById('fm-spinner');
        var result  = document.getElementById('fm-result');
        spinner.style.display = '';
        result.innerHTML = '';
        var fd = new FormData();
        fd.append('action', 'adsdefender_fm_scan');
        fd.append('nonce', document.getElementById('fm-btn-scan').dataset.nonce);
        if (rebuild) fd.append('rebuild', '1');
        fetch(ajaxurl, {method:'POST', body: fd, credentials:'same-origin'})
            .then(r => r.json())
            .then(function(res){
                spinner.style.display = 'none';
                if (!res.success) { result.innerHTML = '<div class="notice notice-error"><p>' + (res.data || 'Lỗi không xác định') + '</p></div>'; return; }
                var d = res.data;
                var html = '<div class="notice ' + (d.count > 0 ? 'notice-warning' : 'notice-success') + ' is-dismissible"><p>' + d.message + '</p></div>';
                if (d.changes && d.changes.length > 0) {
                    var fmEsc = function(s){
                        return String(s).replace(/[&<>"']/g, function(ch){
                            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch];
                        });
                    };
                    html += '<table class="fm-table"><thead><tr><th>Loại</th><th>File</th></tr></thead><tbody>';
                    d.changes.forEach(function(c){
                        var badge = '<span class="fm-badge-' + fmEsc(c.type) + '">' + fmEsc(c.type) + '</span>';
                        html += '<tr><td>' + badge + '</td><td><code>' + fmEsc(c.path) + '</code></td></tr>';
                    });
                    html += '</tbody></table>';
                }
                result.innerHTML = html;
            })
            .catch(function(){ spinner.style.display='none'; result.innerHTML='<div class="notice notice-error"><p>Lỗi kết nối.</p></div>'; });
    }
    document.getElementById('fm-btn-scan').addEventListener('click', function(){ fmRequest(false); });
    document.getElementById('fm-btn-rebuild').addEventListener('click', function(){ fmRequest(true); });
    })();
    </script>
    <?php
}

// ─── Tab Site Scanner ─────────────────────────────────────────────────────────

function adsdefender_page_sitescanner(): void
{
    if (!current_user_can('manage_options')) wp_die('Không có quyền truy cập.');

    global $wpdb;
    $table        = $wpdb->prefix . 'adsdefender_scan_results';
    $opts         = adsdefender_settings();
    $enabled      = !empty($opts['scan_enabled']);
    $last_run     = get_option('adsdefender_scan_last_run', '');
    $last_count   = (int) get_option('adsdefender_scan_last_count', 0);
    $last_new     = (int) get_option('adsdefender_scan_last_new', 0);
    $files_scanned= (int) get_option('adsdefender_scan_files_scanned', 0);
    $nonce_run    = wp_create_nonce('adsdefender_scan_run');
    $nonce_res    = wp_create_nonce('adsdefender_scan_resolve');
    $nonce_ign    = wp_create_nonce('adsdefender_scan_ignore');
    $nonce_sucuri = wp_create_nonce('adsdefender_sucuri_scan');
    $nonce_chmod   = wp_create_nonce('adsdefender_chmod_fix');
    $nonce_dbscan  = wp_create_nonce('adsdefender_db_scan');
    $sucuri_cache  = adsdefender_sucuri_get_cached();
    $chmod_backup  = get_option('adsdefender_chmod_backup', []);
    $db_scan_meta  = get_option('adsdefender_db_scan_results', []);
    $db_scan_last  = get_option('adsdefender_db_scan_last', '');

    // Load results từ DB
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    $results = $table_exists
        ? $wpdb->get_results("SELECT * FROM `{$table}` WHERE status != 'ignored' ORDER BY severity='critical' DESC, severity='high' DESC, last_seen DESC LIMIT 200", ARRAY_A)
        : [];

    $sev_color = ['critical' => '#fed7d7', 'high' => '#fefcbf', 'medium' => '#bee3f8', 'low' => '#f0fff4'];
    $sev_text  = ['critical' => '#9b2c2c', 'high'  => '#7b5e0e', 'medium' => '#2c5282', 'low' => '#276749'];
    ?>
    <style>
    .sc-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px}
    .sc-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;min-width:150px;flex:1}
    .sc-card .sc-val{font-size:22px;font-weight:700;color:#1a202c}
    .sc-card .sc-lbl{font-size:12px;color:#718096;margin-top:2px}
    .sc-table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.07);margin-top:16px}
    .sc-table th{background:#f7fafc;padding:10px 14px;text-align:left;font-size:12px;text-transform:uppercase;color:#718096;border-bottom:1px solid #e2e8f0}
    .sc-table td{padding:9px 14px;border-bottom:1px solid #f0f4f8;font-size:13px;vertical-align:top}
    .sc-sev{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
    .sc-snippet{font-family:monospace;font-size:11px;color:#4a5568;background:#f7fafc;padding:3px 6px;border-radius:3px;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
    .sc-action{font-size:12px;cursor:pointer;color:#5a7fc4;text-decoration:underline;background:none;border:none;padding:0;margin-right:8px}
    </style>

    <?php if (!$enabled): ?>
    <div class="notice notice-warning is-dismissible"><p>⚠️ Malware Scan đang <strong>tắt</strong>. <a href="<?php echo esc_url(admin_url('admin.php?page=adsdefender-system&tab=settings&stab=protect')); ?>">Bật trong Cài đặt → Bảo vệ →</a></p></div>
    <?php endif; ?>

    <div class="sc-meta">
        <div class="sc-card">
            <div class="sc-val"><?php echo $enabled ? '🟢 Bật' : '🔴 Tắt'; ?></div>
            <div class="sc-lbl">Trạng thái</div>
        </div>
        <div class="sc-card">
            <div class="sc-val"><?php echo $last_run ? esc_html(wp_date('H:i d/m/Y', strtotime($last_run))) : '—'; ?></div>
            <div class="sc-lbl">Lần quét gần nhất</div>
        </div>
        <div class="sc-card">
            <div class="sc-val"><?php echo $files_scanned; ?></div>
            <div class="sc-lbl">Files đã quét</div>
        </div>
        <div class="sc-card" style="<?php echo $last_count > 0 ? 'border-color:#fc8181' : ''; ?>">
            <div class="sc-val" style="color:<?php echo $last_count > 0 ? '#c53030' : '#276749'; ?>"><?php echo $last_count; ?></div>
            <div class="sc-lbl">Cảnh báo hiện tại</div>
        </div>
        <?php if ($last_new > 0): ?>
        <div class="sc-card" style="border-color:#fc8181">
            <div class="sc-val" style="color:#c53030"><?php echo $last_new; ?></div>
            <div class="sc-lbl">⚠️ Mới (lần quét cuối)</div>
        </div>
        <?php endif; ?>
    </div>

    <div style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button id="sc-btn-scan" class="button button-primary" data-nonce="<?php echo esc_attr($nonce_run); ?>">
            🦠 Quét malware ngay
        </button>
        <span id="sc-spinner" style="display:none;color:#718096">⏳ Đang khởi động...</span>
    </div>

    <div id="sc-scan-result"></div>

    <?php if (!empty($results)): ?>
    <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">
        📋 Kết quả quét (<?php echo count($results); ?> cảnh báo chưa xử lý)
    </h3>
    <table class="sc-table">
        <thead>
            <tr>
                <th style="width:90px">Mức độ</th>
                <th>File</th>
                <th>Loại</th>
                <th style="width:60px">Dòng</th>
                <th>Code</th>
                <th style="width:130px">Phát hiện</th>
                <th style="width:110px">Thao tác</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $row):
            $sev = $row['severity'];
            $bg  = $sev_color[$sev] ?? '#fff';
            $tc  = $sev_text[$sev]  ?? '#333';
            $rel = str_replace(ABSPATH, '', $row['file_path']);
        ?>
        <tr id="sc-row-<?php echo (int)$row['id']; ?>">
            <td><span class="sc-sev" style="background:<?php echo $bg; ?>;color:<?php echo $tc; ?>"><?php echo esc_html($sev); ?></span></td>
            <td style="word-break:break-all;font-size:12px"><?php echo esc_html($rel); ?></td>
            <td style="font-size:12px"><?php echo esc_html($row['label']); ?></td>
            <td><?php echo (int)$row['line_no']; ?></td>
            <td><span class="sc-snippet" title="<?php echo esc_attr($row['snippet']); ?>"><?php echo esc_html($row['snippet']); ?></span></td>
            <td style="font-size:11px;color:#718096"><?php echo esc_html(wp_date('d/m H:i', strtotime($row['first_seen']))); ?></td>
            <td>
                <button class="sc-action sc-resolve" data-id="<?php echo (int)$row['id']; ?>" data-nonce="<?php echo esc_attr($nonce_res); ?>">✅ Đã xử lý</button>
                <button class="sc-action sc-ignore" data-id="<?php echo (int)$row['id']; ?>" data-nonce="<?php echo esc_attr($nonce_ign); ?>">🚫 Bỏ qua</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php elseif ($last_run): ?>
    <div class="notice notice-success"><p>✅ Không có cảnh báo malware nào. Site đang sạch!</p></div>
    <?php else: ?>
    <div class="notice notice-info"><p>ℹ️ Chưa có kết quả quét. Nhấn <strong>Quét malware ngay</strong> để bắt đầu.</p></div>
    <?php endif; ?>

    <?php // ── Database Scanner Section ─────────────────────────────────────── ?>
    <?php
    $db_results  = $db_scan_meta['results'] ?? [];
    $db_found    = $db_scan_meta['found']   ?? 0;
    ?>
    <h3 style="font-size:14px;margin:28px 0 10px;padding-bottom:6px;border-bottom:2px solid #e2e8f0">
        🗄 Database Scan
        <span style="font-size:11px;font-weight:400;color:#718096;margin-left:8px">Tìm malware inject trong posts · options · usermeta</span>
    </h3>

    <div class="sc-meta" style="margin-bottom:14px">
        <div class="sc-card" style="<?php echo $db_found > 0 ? 'border-color:#fc8181' : ''; ?>">
            <div class="sc-val" style="color:<?php echo $db_found > 0 ? '#c53030' : '#276749'; ?>"><?php echo $db_found; ?></div>
            <div class="sc-lbl"><?php echo $db_found > 0 ? '⚠️ Vấn đề phát hiện' : '✅ Vấn đề phát hiện'; ?></div>
        </div>
        <div class="sc-card">
            <div class="sc-val" style="font-size:14px"><?php echo $db_scan_last ? esc_html(wp_date('H:i d/m/Y', strtotime($db_scan_last))) : '—'; ?></div>
            <div class="sc-lbl">Lần quét gần nhất</div>
        </div>
        <div class="sc-card">
            <div class="sc-val"><?php echo (int)($db_scan_meta['posts_scanned'] ?? 0); ?></div>
            <div class="sc-lbl">Posts đã quét</div>
        </div>
    </div>

    <div style="margin-bottom:14px;display:flex;gap:10px;align-items:center">
        <button id="db-btn-scan" class="button button-primary" data-nonce="<?php echo esc_attr($nonce_dbscan); ?>">
            🗄 Quét Database ngay
        </button>
        <span id="db-spinner" style="display:none;color:#718096">⏳ Đang quét database...</span>
    </div>
    <div id="db-scan-result"></div>

    <?php if (!empty($db_results)): ?>
    <table class="sc-table" style="margin-bottom:24px">
        <thead>
            <tr>
                <th style="width:80px">Mức độ</th>
                <th style="width:90px">Bảng</th>
                <th>Row</th>
                <th style="width:120px">Field</th>
                <th>Loại phát hiện</th>
                <th>Snippet</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($db_results as $r):
            $sev = $r['severity'];
            $bg  = $sev_color[$sev] ?? '#fff';
            $tc  = $sev_text[$sev]  ?? '#333';
        ?>
        <tr>
            <td><span class="sc-sev" style="background:<?php echo $bg; ?>;color:<?php echo $tc; ?>"><?php echo esc_html($sev); ?></span></td>
            <td style="font-family:monospace;font-size:12px;color:#4a5568"><?php echo esc_html($r['table']); ?></td>
            <td style="font-size:12px;word-break:break-all"><?php echo esc_html($r['row_id']); ?></td>
            <td style="font-family:monospace;font-size:11px;color:#718096"><?php echo esc_html($r['field']); ?></td>
            <td style="font-size:12px"><?php echo esc_html($r['label']); ?></td>
            <td><span class="sc-snippet" title="<?php echo esc_attr($r['snippet']); ?>"><?php echo esc_html($r['snippet']); ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php elseif ($db_scan_last): ?>
    <div class="notice notice-success" style="margin-bottom:16px"><p>✅ Database sạch — không phát hiện malware.</p></div>
    <?php else: ?>
    <div class="notice notice-info" style="margin-bottom:16px"><p>ℹ️ Chưa quét. Nhấn <strong>Quét Database ngay</strong> để bắt đầu.</p></div>
    <?php endif; ?>

    <?php // ── File Permissions Section ──────────────────────────────────────── ?>
    <?php
    // optimal = quyền tối ưu để fix
    // ok_max  = ngưỡng tối đa vẫn chấp nhận (không cảnh báo)
    // note_bad = mô tả khi vượt ngưỡng
    $fp_paths = [
        ABSPATH                    => ['label' => '/ (ABSPATH)',        'optimal' => 0755, 'ok_max' => 0755, 'note_bad' => 'Quyền quá rộng (>755)'],
        ABSPATH.'wp-includes'      => ['label' => 'wp-includes/',       'optimal' => 0755, 'ok_max' => 0755, 'note_bad' => 'Quyền quá rộng'],
        ABSPATH.'wp-admin'         => ['label' => 'wp-admin/',          'optimal' => 0755, 'ok_max' => 0755, 'note_bad' => 'Quyền quá rộng'],
        WP_CONTENT_DIR             => ['label' => 'wp-content/',        'optimal' => 0755, 'ok_max' => 0755, 'note_bad' => 'Quyền quá rộng'],
        get_theme_root()           => ['label' => 'wp-content/themes/', 'optimal' => 0755, 'ok_max' => 0755, 'note_bad' => 'Quyền quá rộng'],
        WP_PLUGIN_DIR              => ['label' => 'wp-content/plugins/','optimal' => 0755, 'ok_max' => 0755, 'note_bad' => 'Quyền quá rộng'],
        wp_upload_dir()['basedir'] => ['label' => 'wp-content/uploads/','optimal' => 0755, 'ok_max' => 0755, 'note_bad' => 'Quyền quá rộng'],
        // wp-config.php: chứa DB password → group/other không được đọc (0600 hoặc 0400)
        ABSPATH.'wp-config.php'    => ['label' => 'wp-config.php',      'optimal' => 0600, 'ok_max' => 0600, 'note_bad' => 'Group/Other có thể đọc DB password!'],
        // .htaccess: server cần đọc để apply rules → 0644 là đúng, 0664/0666 mới là vấn đề
        ABSPATH.'.htaccess'        => ['label' => '.htaccess',           'optimal' => 0644, 'ok_max' => 0644, 'note_bad' => 'Quyền ghi quá rộng (group/other có thể sửa)'],
    ];
    $fp_issues = 0;
    $fp_rows = [];
    foreach ($fp_paths as $path => $info) {
        if (!file_exists($path)) continue;
        $actual  = fileperms($path) & 0777;
        $ok      = ($actual <= $info['ok_max']);
        if (!$ok) $fp_issues++;
        $note = $ok ? '' : $info['note_bad'];
        $fp_rows[] = [
            'label'   => $info['label'],
            'actual'  => $actual,
            'optimal' => $info['optimal'],
            'ok_max'  => $info['ok_max'],
            'ok'      => $ok,
            'note'    => $note,
        ];
    }
    ?>
    <h3 style="font-size:14px;margin:28px 0 10px;padding-bottom:6px;border-bottom:2px solid #e2e8f0">
        🔐 File Permissions
        <span style="font-size:11px;font-weight:400;color:#718096;margin-left:8px">Kiểm tra quyền truy cập các file/thư mục quan trọng</span>
    </h3>

    <?php if ($fp_issues > 0): ?>
    <div class="notice notice-warning" style="margin-bottom:12px"><p>⚠️ Phát hiện <strong><?php echo $fp_issues; ?></strong> file/thư mục có quyền truy cập không an toàn.</p></div>
    <?php else: ?>
    <div class="notice notice-success" style="margin-bottom:12px"><p>✅ Tất cả quyền truy cập file đều an toàn.</p></div>
    <?php endif; ?>

    <table class="sc-table" style="margin-bottom:16px">
        <thead>
            <tr>
                <th>File / Thư mục</th>
                <th style="width:110px">Quyền hiện tại</th>
                <th style="width:110px">Tối ưu</th>
                <th style="width:90px">Trạng thái</th>
                <th>Ghi chú</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($fp_rows as $r): ?>
        <tr style="<?php echo $r['ok'] ? '' : 'background:#fff5f5'; ?>">
            <td style="font-family:monospace;font-size:13px"><?php echo esc_html($r['label']); ?></td>
            <td>
                <code style="background:<?php echo $r['ok'] ? '#f0fff4' : '#fff5f5'; ?>;color:<?php echo $r['ok'] ? '#276749' : '#c53030'; ?>;padding:2px 7px;border-radius:4px;font-size:13px">
                    <?php echo sprintf('%04o', $r['actual']); ?>
                </code>
            </td>
            <td><code style="background:#f7fafc;padding:2px 7px;border-radius:4px;font-size:13px"><?php echo sprintf('%04o', $r['optimal']); ?></code></td>
            <td>
                <?php if ($r['ok']): ?>
                    <span style="color:#276749;font-weight:600">✅ OK</span>
                <?php else: ?>
                    <span style="color:#c53030;font-weight:600">⚠️ Cảnh báo</span>
                <?php endif; ?>
            </td>
            <td style="font-size:12px;color:<?php echo $r['ok'] ? '#718096' : '#c53030'; ?>">
                <?php echo esc_html($r['note']); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="display:flex;gap:10px;align-items:center;margin-bottom:24px;flex-wrap:wrap">
        <?php if ($fp_issues > 0): ?>
        <button id="fp-btn-fix" class="button button-primary"
            data-nonce="<?php echo esc_attr($nonce_chmod); ?>"
            data-action="fix">
            🔧 Tự động fix (<?php echo $fp_issues; ?> lỗi)
        </button>
        <?php endif; ?>
        <?php if (!empty($chmod_backup)): ?>
        <button id="fp-btn-rollback" class="button"
            data-nonce="<?php echo esc_attr($nonce_chmod); ?>"
            data-action="rollback"
            style="color:#c53030;border-color:#fc8181">
            ↩ Rollback về quyền cũ
        </button>
        <span style="font-size:11px;color:#718096">
            (backup lúc <?php echo esc_html(wp_date('H:i d/m/Y', $chmod_backup['time'] ?? 0)); ?>)
        </span>
        <?php endif; ?>
        <span id="fp-spinner" style="display:none;color:#718096">⏳ Đang xử lý...</span>
    </div>
    <div id="fp-result"></div>

    <?php // ── Sucuri SiteCheck Section ──────────────────────────────────────── ?>
    <h3 style="font-size:14px;margin:28px 0 10px;padding-bottom:6px;border-bottom:2px solid #e2e8f0">
        🌐 External Scan — Sucuri SiteCheck
        <span style="font-size:11px;font-weight:400;color:#718096;margin-left:8px">Powered by Sucuri 700k+ signatures · Blacklist · SSL</span>
    </h3>

    <?php
    $sc = $sucuri_cache;
    $rating_color = ['A' => '#276749', 'B' => '#2c5282', 'C' => '#7b5e0e', 'D' => '#9b2c2c', 'F' => '#9b2c2c'];
    ?>

    <?php if (!empty($sc)): ?>
    <div class="sc-meta" style="margin-bottom:14px">
        <div class="sc-card" style="<?php echo empty($sc['clean']) ? 'border-color:#fc8181' : 'border-color:#9ae6b4'; ?>">
            <div class="sc-val" style="color:<?php echo empty($sc['clean']) ? '#c53030' : '#276749'; ?>">
                <?php echo empty($sc['clean']) ? '⚠️ Phát hiện vấn đề' : '✅ Sạch'; ?>
            </div>
            <div class="sc-lbl">Sucuri SiteCheck</div>
        </div>
        <div class="sc-card">
            <div class="sc-val" style="color:<?php echo $rating_color[$sc['rating_total']] ?? '#333'; ?>"><?php echo esc_html($sc['rating_total']); ?></div>
            <div class="sc-lbl">Rating tổng</div>
        </div>
        <div class="sc-card">
            <div class="sc-val" style="color:<?php echo $rating_color[$sc['rating_security']] ?? '#333'; ?>"><?php echo esc_html($sc['rating_security']); ?></div>
            <div class="sc-lbl">Security</div>
        </div>
        <div class="sc-card">
            <div class="sc-val" style="color:<?php echo $rating_color[$sc['rating_tls']] ?? '#333'; ?>"><?php echo esc_html($sc['rating_tls']); ?></div>
            <div class="sc-lbl">TLS/SSL</div>
        </div>
        <?php if (!empty($sc['cert_expires'])): ?>
        <div class="sc-card">
            <div class="sc-val" style="font-size:14px"><?php echo esc_html($sc['cert_expires']); ?></div>
            <div class="sc-lbl">SSL hết hạn</div>
        </div>
        <?php endif; ?>
        <div class="sc-card">
            <div class="sc-val" style="font-size:13px;color:#718096"><?php echo $sc['cache_age_hours']; ?>h trước</div>
            <div class="sc-lbl"><?php echo $sc['is_stale'] ? '⚠️ Cũ' : 'Quét lần cuối'; ?></div>
        </div>
    </div>

    <?php if (!empty($sc['malware'])): ?>
    <div class="notice notice-error"><p><strong>🚨 Malware detected:</strong></p>
        <ul style="margin:6px 0 0 16px">
        <?php foreach ($sc['malware'] as $m): ?>
            <li><?php echo esc_html($m['type']); ?>
                <?php if ($m['url']): ?> — <a href="<?php echo esc_url($m['url']); ?>" target="_blank"><?php echo esc_html($m['url']); ?></a><?php endif; ?>
                <?php if ($m['desc']): ?><br><small><?php echo esc_html($m['desc']); ?></small><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($sc['blacklists'])): ?>
    <div class="notice notice-error"><p><strong>🚫 Blacklisted:</strong></p>
        <ul style="margin:6px 0 0 16px">
        <?php foreach ($sc['blacklists'] as $bl): ?>
            <li><?php echo esc_html($bl['vendor']); ?><?php if ($bl['info']): ?> — <?php echo esc_html($bl['info']); ?><?php endif; ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($sc['recommendations'])): ?>
    <details style="margin-bottom:10px">
        <summary style="cursor:pointer;color:#5a7fc4;font-size:13px">
            📋 <?php echo count($sc['recommendations']); ?> khuyến nghị bảo mật (click để xem)
        </summary>
        <table class="sc-table" style="margin-top:8px">
            <thead><tr><th>Loại</th><th>Vấn đề</th></tr></thead>
            <tbody>
            <?php foreach ($sc['recommendations'] as $r): ?>
            <tr>
                <td style="font-size:12px;color:#718096"><?php echo esc_html($r['category']); ?></td>
                <td style="font-size:12px"><?php echo esc_html(str_replace('_', ' ', $r['key'])); ?>
                    <?php if (!empty($r['pages'])): ?>
                    <br><small style="color:#a0aab8"><?php echo esc_html(implode(', ', array_slice($r['pages'], 0, 2))); ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <?php endif; // end if $sc not empty ?>

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px">
        <button id="sucuri-btn-scan" class="button" data-nonce="<?php echo esc_attr($nonce_sucuri); ?>">
            🌐 <?php echo empty($sc) ? 'Chạy External Scan' : 'Quét lại (Sucuri)'; ?>
        </button>
        <span id="sucuri-spinner" style="display:none;color:#718096">⏳ Đang gọi Sucuri API... (10-30 giây)</span>
    </div>
    <div id="sucuri-result"></div>

    <script>
    (function(){
    var BATCHES = ['config','wp-includes','wp-admin','themes','plugins','uploads'];
    var LABELS  = {'config':'Config files','wp-includes':'WP Core (depth 1)','wp-admin':'WP Admin','themes':'Themes','plugins':'Plugins','uploads':'Uploads'};

    function runBatch(batchKey, offset, nonce, totalFound, totalScanned, btn, spinner, result) {
        spinner.textContent = '⏳ Đang quét: ' + (LABELS[batchKey] || batchKey) + (offset > 0 ? ' (trang '+ Math.ceil(offset/150+1) +')' : '') + '...';
        var fd = new FormData();
        fd.append('action', 'adsdefender_scan_run');
        fd.append('nonce', nonce);
        fd.append('batch', batchKey);
        fd.append('offset', offset);
        fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
            .then(function(r){
                return r.text().then(function(txt){
                    try { return JSON.parse(txt); }
                    catch(e) {
                        // Server trả HTML (fatal error, WP die...) — lấy 300 ký tự đầu để debug
                        var preview = txt.replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim().substring(0,300);
                        throw new Error(preview || 'Server trả response không phải JSON');
                    }
                });
            })
            .then(function(res){
                if (!res.success) {
                    btn.disabled = false; spinner.style.display = 'none';
                    result.innerHTML = '<div class="notice notice-error"><p>' + (res.data || 'Lỗi không xác định') + '</p></div>';
                    return;
                }
                var d = res.data;
                totalFound   += d.found   || 0;
                totalScanned += d.scanned || 0;

                // Progress: dùng batch index (không dùng offset để tránh nhảy)
                var idx = BATCHES.indexOf(d.next_batch !== null ? batchKey : batchKey);
                var batchIdx = BATCHES.indexOf(batchKey);
                var pct = Math.round((batchIdx + (d.next_batch === batchKey ? 0.5 : 1)) / BATCHES.length * 100);
                result.innerHTML = '<div style="background:#e2e8f0;border-radius:6px;height:8px;margin-bottom:10px">'
                    + '<div style="background:#5a7fc4;height:8px;border-radius:6px;width:' + pct + '%;transition:width .4s"></div></div>'
                    + '<p style="color:#718096;font-size:13px">Đã quét ' + totalScanned + ' files — phát hiện ' + totalFound + ' cảnh báo...</p>';

                if (!d.done) {
                    runBatch(d.next_batch, d.next_offset || 0, nonce, totalFound, totalScanned, btn, spinner, result);
                } else {
                    btn.disabled = false; spinner.style.display = 'none';
                    var msg = totalFound === 0
                        ? '✅ Không phát hiện malware nào. (' + totalScanned + ' files đã quét)'
                        : '⚠️ Phát hiện ' + totalFound + ' cảnh báo! (' + totalScanned + ' files đã quét)';
                    result.innerHTML = '<div class="notice ' + (totalFound > 0 ? 'notice-warning' : 'notice-success') + '"><p>' + msg + '</p></div>'
                        + (totalFound > 0 ? '<p style="color:#718096;font-size:13px">⏳ Đang tải kết quả chi tiết...</p>' : '');
                    // Auto-reload để hiện bảng kết quả từ DB
                    setTimeout(function(){ location.reload(); }, totalFound > 0 ? 1500 : 0);
                }
            })
            .catch(function(err){
                btn.disabled = false; spinner.style.display = 'none';
                result.innerHTML = '<div class="notice notice-error"><p>⚠️ Lỗi kết nối khi quét <strong>' + (LABELS[batchKey]||batchKey) + '</strong>: ' + (err && err.message ? err.message : 'network error') + '</p></div>';
            });
    }

    document.getElementById('sc-btn-scan').addEventListener('click', function(){
        var btn     = this;
        var spinner = document.getElementById('sc-spinner');
        var result  = document.getElementById('sc-scan-result');
        btn.disabled = true;
        spinner.style.display = '';
        result.innerHTML = '';
        runBatch('config', 0, btn.dataset.nonce, 0, 0, btn, spinner, result);
    });

    // Resolve / Ignore
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.sc-resolve,.sc-ignore');
        if (!btn) return;
        var id   = btn.dataset.id;
        var isRes = btn.classList.contains('sc-resolve');
        var action = isRes ? 'adsdefender_scan_resolve' : 'adsdefender_scan_ignore';
        var nonce  = btn.dataset.nonce;
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        fd.append('id', id);
        fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
            .then(r => r.json())
            .then(function(res){
                if (res.success) {
                    var row = document.getElementById('sc-row-' + id);
                    if (row) { row.style.opacity = '0.4'; row.style.transition = 'opacity .3s'; setTimeout(function(){ row.remove(); }, 400); }
                }
            });
    });

    // Database scan
    var dbBtn = document.getElementById('db-btn-scan');
    if (dbBtn) {
        dbBtn.addEventListener('click', function(){
            var spinner = document.getElementById('db-spinner');
            var result  = document.getElementById('db-scan-result');
            dbBtn.disabled = true; spinner.style.display = '';
            result.innerHTML = '';
            var fd = new FormData();
            fd.append('action', 'adsdefender_db_scan');
            fd.append('nonce', dbBtn.dataset.nonce);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.text().then(function(t){ try{return JSON.parse(t);}catch(e){throw new Error(t.replace(/<[^>]+>/g,'').trim().substring(0,300));} }); })
                .then(function(res){
                    dbBtn.disabled = false; spinner.style.display = 'none';
                    if (!res.success) { result.innerHTML = '<div class="notice notice-error"><p>' + (res.data||'Lỗi') + '</p></div>'; return; }
                    var d = res.data;
                    var msg = d.found === 0
                        ? '✅ Database sạch — không phát hiện malware. (' + d.posts_scanned + ' posts đã quét)'
                        : '⚠️ Phát hiện ' + d.found + ' vấn đề trong database!';
                    var cls = d.found > 0 ? 'notice-warning' : 'notice-success';
                    result.innerHTML = '<div class="notice ' + cls + '"><p>' + msg + '</p></div>'
                        + (d.found > 0 ? '<p style="color:#718096;font-size:13px">⏳ Đang tải kết quả...</p>' : '');
                    setTimeout(function(){ location.reload(); }, d.found > 0 ? 1200 : 0);
                })
                .catch(function(err){
                    dbBtn.disabled = false; spinner.style.display = 'none';
                    result.innerHTML = '<div class="notice notice-error"><p>Lỗi: ' + (err&&err.message?err.message:'network error') + '</p></div>';
                });
        });
    }

    // File Permissions Fix / Rollback
    ['fp-btn-fix','fp-btn-rollback'].forEach(function(id){
        var btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', function(){
            var spinner = document.getElementById('fp-spinner');
            var result  = document.getElementById('fp-result');
            var action  = btn.dataset.action; // 'fix' or 'rollback'
            if (action === 'fix' && !confirm('Tự động đặt quyền tối ưu cho các file?\n\nPlugin sẽ lưu backup để rollback lại nếu cần.')) return;
            if (action === 'rollback' && !confirm('Khôi phục lại quyền cũ trước khi fix?')) return;
            btn.disabled = true; spinner.style.display = '';
            result.innerHTML = '';
            var fd = new FormData();
            fd.append('action', 'adsdefender_chmod_fix');
            fd.append('nonce', btn.dataset.nonce);
            fd.append('do', action);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.text().then(function(t){ try{return JSON.parse(t);}catch(e){throw new Error(t.replace(/<[^>]+>/g,'').trim().substring(0,300));} }); })
                .then(function(res){
                    btn.disabled = false; spinner.style.display = 'none';
                    if (!res.success) { result.innerHTML = '<div class="notice notice-error"><p>' + (res.data||'Lỗi') + '</p></div>'; return; }
                    var msg = res.data.message || '';
                    // Render lệnh SSH nếu có |CMD|...|/CMD|
                    var cmdMatch = msg.match(/\|CMD\|([\s\S]*?)\|\/CMD\|/);
                    var htmlMsg  = msg.replace(/\|CMD\|[\s\S]*?\|\/CMD\|/, '');
                    var cmdHtml  = cmdMatch
                        ? '<div style="margin-top:10px;background:#1a202c;color:#e2e8f0;padding:12px 16px;border-radius:6px;font-family:monospace;font-size:12px;line-height:1.8;white-space:pre">'
                          + cmdMatch[1].replace(/</g,'&lt;') + '</div>'
                        : '';
                    var cls = res.data.failed && res.data.failed.length ? 'notice-warning' : 'notice-success';
                    result.innerHTML = '<div class="notice ' + cls + '"><p>' + htmlMsg + '</p></div>' + cmdHtml;
                    if (!cmdMatch) setTimeout(function(){ location.reload(); }, 1200);
                })
                .catch(function(err){
                    btn.disabled = false; spinner.style.display = 'none';
                    result.innerHTML = '<div class="notice notice-error"><p>Lỗi: ' + (err&&err.message?err.message:'network error') + '</p></div>';
                });
        });
    });

    // Sucuri scan
    var sucuriBtn = document.getElementById('sucuri-btn-scan');
    if (sucuriBtn) {
        sucuriBtn.addEventListener('click', function(){
            var btn     = this;
            var spinner = document.getElementById('sucuri-spinner');
            var result  = document.getElementById('sucuri-result');
            btn.disabled = true;
            spinner.style.display = '';
            result.innerHTML = '';
            var fd = new FormData();
            fd.append('action', 'adsdefender_sucuri_scan');
            fd.append('nonce', btn.dataset.nonce);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.text().then(function(t){ try{return JSON.parse(t);}catch(e){throw new Error(t.replace(/<[^>]+>/g,'').trim().substring(0,300));} }); })
                .then(function(res){
                    btn.disabled = false; spinner.style.display = 'none';
                    if (!res.success) { result.innerHTML = '<div class="notice notice-error"><p>' + (res.data||'Lỗi') + '</p></div>'; return; }
                    var d = res.data;
                    var statusHtml = d.clean
                        ? '<div class="notice notice-success"><p>✅ <strong>Sucuri:</strong> Không phát hiện malware hay blacklist. Rating: <strong>' + d.rating_total + '</strong></p></div>'
                        : '<div class="notice notice-error"><p>⚠️ <strong>Sucuri phát hiện vấn đề!</strong> Rating: ' + d.rating_total + '</p></div>';
                    result.innerHTML = statusHtml + '<p style="color:#718096;font-size:12px">Reload trang để xem chi tiết đầy đủ.</p>';
                })
                .catch(function(err){
                    btn.disabled = false; spinner.style.display = 'none';
                    result.innerHTML = '<div class="notice notice-error"><p>Lỗi kết nối Sucuri: ' + (err&&err.message?err.message:'network error') + '</p></div>';
                });
        });
    }
    })();
    </script>
    <?php
}

// ─── Tab Phân quyền ───────────────────────────────────────────────────────────

function adsdefender_page_access()
{
    if (!current_user_can('manage_options')) {
        wp_die('Không có quyền truy cập.');
    }

    $manager     = adsdefender_get_manager_user();
    $is_mgr_self = adsdefender_is_manager();

    // Thông báo sau khi lưu
    if (!empty($_GET['updated'])) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã cập nhật AdsDefender Manager.</p></div>';
    }

    // Lấy danh sách administrators
    $admins = get_users(['role__in' => ['administrator'], 'fields' => ['ID', 'user_login', 'display_name']]);
    ?>
    <div style="max-width:680px;margin-top:20px">
      <h2>🔑 Phân quyền AdsDefender Manager</h2>
      <p style="color:#555">Chỉ user được chọn mới có quyền <strong>lưu thay đổi cài đặt AdsDefender</strong>.
         Các admin khác vẫn xem được nhưng không thể lưu.</p>

      <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;margin-bottom:20px">
        <table class="widefat fixed striped" style="width:100%">
          <thead><tr>
            <th>User</th><th>Login</th><th>Trạng thái</th>
          </tr></thead>
          <tbody>
          <?php foreach ($admins as $u):
            $is_current_mgr = ($manager && (int)$manager->ID === (int)$u->ID);
          ?>
          <tr style="<?php echo $is_current_mgr ? 'background:#eaf7ea' : ''; ?>">
            <td><strong><?php echo esc_html($u->display_name ?: $u->user_login); ?></strong></td>
            <td><code><?php echo esc_html($u->user_login); ?></code></td>
            <td>
              <?php if ($is_current_mgr): ?>
                <span style="color:#2e7d32;font-weight:600">✅ AdsDefender Manager</span>
              <?php else: ?>
                <span style="color:#888">— Chỉ xem</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($is_mgr_self): ?>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('adsdefender_manager_nonce'); ?>
        <input type="hidden" name="action" value="adsdefender_save_manager">
        <input type="hidden" name="adsdefender_save_manager" value="1">
        <table class="form-table">
          <tr>
            <th><label for="adsdefender_manager_uid">Chọn Manager</label></th>
            <td>
              <select name="adsdefender_manager_uid" id="adsdefender_manager_uid" style="min-width:260px">
                <option value="0">— Không giới hạn (tất cả admin) —</option>
                <?php foreach ($admins as $u): ?>
                <option value="<?php echo (int)$u->ID; ?>"
                  <?php selected($manager ? (int)$manager->ID : 0, (int)$u->ID); ?>>
                  <?php echo esc_html($u->display_name ?: $u->user_login); ?> (<?php echo esc_html($u->user_login); ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <p class="description">Chọn "Không giới hạn" nếu muốn tất cả administrator đều lưu được cài đặt.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('💾 Lưu phân quyền'); ?>
      </form>
      <?php else: ?>
      <div class="notice notice-warning" style="margin:0">
        <p>⚠️ Bạn không phải AdsDefender Manager — không thể thay đổi phân quyền.
           Vui lòng đăng nhập bằng tài khoản Manager để chỉnh sửa.</p>
      </div>
      <?php endif; ?>
    </div>
    <?php
}

// Hook xử lý form submit (admin-post.php)
add_action('admin_post_adsdefender_save_manager', function () {
    if (!current_user_can('manage_options')) wp_die('Không có quyền.');
    check_admin_referer('adsdefender_manager_nonce');
    if (!adsdefender_is_manager()) wp_die('Chỉ AdsDefender Manager mới được thay đổi phân quyền.');
    $new_uid = (int)($_POST['adsdefender_manager_uid'] ?? 0);
    adsdefender_set_manager_user($new_uid);
    wp_safe_redirect(add_query_arg(['page' => 'adsdefender-system', 'tab' => 'access', 'updated' => 1], admin_url('admin.php')));
    exit;
});
