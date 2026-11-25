<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
if (!defined('ABSPATH')) exit;

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
    ['🛡 IP Đang Block',      $count,     '#2271b1', adsdefender_admin_url('adsdefender-fraud', 'log')],
    ['🍪 Visitor Blacklist',  $visitors,  $visitors > 0 ? '#d63638' : '#888', adsdefender_admin_url('adsdefender-fraud', 'log')],
    ['✋ Block Thủ Công',     $manual,    '#f0860a', adsdefender_admin_url('adsdefender-fraud', 'manual')],
    ['🚫 Block 7 ngày',       $blocks_7d, '#d63638', adsdefender_admin_url('adsdefender-fraud', 'log')],
];
if (adsdefender_module_enabled('marketing')) {
    $cards[] = ['👥 Sessions 7 ngày', $utm_7d,   '#2271b1', adsdefender_admin_url('adsdefender-marketing', 'utm')];
    $cards[] = ['✅ Conv. 7 ngày',    $conv_7d,  '#00a32a', adsdefender_admin_url('adsdefender-marketing', 'utm')];
    $cards[] = ['📥 Leads mới',       $lead_new, $lead_new > 0 ? '#d63638' : '#888', adsdefender_admin_url('adsdefender-marketing', 'leads')];
}
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
      ['🎯 Chống Click Fraud — Kết nối Matomo, Block Log, Whitelist', adsdefender_admin_url('adsdefender-fraud')],
    ];
    if (adsdefender_module_enabled('security')) {
      $links[] = ['🛡 Bảo mật — Firewall, Đăng nhập, Quét mã độc, Nhật ký', adsdefender_admin_url('adsdefender-security')];
    }
    if (adsdefender_module_enabled('marketing')) {
      $links[] = ['🚀 Marketing — Contact Bar, Popup, UTM, Scripts & Tracking', adsdefender_admin_url('adsdefender-marketing')];
    }
    $links[] = ['⚙️ Cài đặt chung — Module, Telegram, Phân quyền, Cập nhật', adsdefender_admin_url('adsdefender-settings')];
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
    'threat_intel'=> ['🌐 Threat Intel', '#b45309'],
];
if ($sec['total'] > 0 && adsdefender_module_enabled('security')):
?>
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-top:20px;max-width:900px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
    <h3 style="margin:0">🔒 Security Events — 7 ngày qua (<?php echo $sec['total']; ?> sự kiện)</h3>
    <a href="<?php echo esc_url(adsdefender_admin_url('adsdefender-security', 'events')); ?>" class="button button-small">Xem log đầy đủ →</a>
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
<?php
}
