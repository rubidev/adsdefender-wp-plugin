<?php
if (!defined('ABSPATH')) exit;

// ─── DB Tables ────────────────────────────────────────────────────────────────

function adsdefender_create_utm_table()
{
    global $wpdb;
    $t  = $wpdb->prefix . ADSDEFENDER_UTM_TABLE;
    $ch = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$t} (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id       VARCHAR(64)  NOT NULL DEFAULT '',
        utm_source       VARCHAR(100) NOT NULL DEFAULT '',
        utm_medium       VARCHAR(100) NOT NULL DEFAULT '',
        utm_campaign     VARCHAR(200) NOT NULL DEFAULT '',
        utm_term         VARCHAR(200) NOT NULL DEFAULT '',
        utm_content      VARCHAR(200) NOT NULL DEFAULT '',
        gclid            VARCHAR(200) NOT NULL DEFAULT '',
        fbclid           VARCHAR(200) NOT NULL DEFAULT '',
        landing_url      VARCHAR(500) NOT NULL DEFAULT '',
        referrer         VARCHAR(500) NOT NULL DEFAULT '',
        ip               VARCHAR(45)  NOT NULL DEFAULT '',
        user_agent       VARCHAR(300) NOT NULL DEFAULT '',
        conversion_type  VARCHAR(50)  NOT NULL DEFAULT '',
        conversion_label VARCHAR(200) NOT NULL DEFAULT '',
        created_at       DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_session  (session_id),
        KEY idx_source   (utm_source),
        KEY idx_campaign (utm_campaign),
        KEY idx_created  (created_at)
    ) {$ch};");
}

function adsdefender_create_lead_table()
{
    global $wpdb;
    $t  = $wpdb->prefix . ADSDEFENDER_LEAD_TABLE;
    $ch = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$t} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id   VARCHAR(64)  NOT NULL DEFAULT '',
        name         VARCHAR(200) NOT NULL DEFAULT '',
        phone        VARCHAR(20)  NOT NULL DEFAULT '',
        email        VARCHAR(200) NOT NULL DEFAULT '',
        message      TEXT,
        utm_source   VARCHAR(100) NOT NULL DEFAULT '',
        utm_medium   VARCHAR(100) NOT NULL DEFAULT '',
        utm_campaign VARCHAR(200) NOT NULL DEFAULT '',
        gclid        VARCHAR(200) NOT NULL DEFAULT '',
        landing_url  VARCHAR(500) NOT NULL DEFAULT '',
        popup_id     VARCHAR(50)  NOT NULL DEFAULT '',
        ip           VARCHAR(45)  NOT NULL DEFAULT '',
        status       VARCHAR(20)  NOT NULL DEFAULT 'new',
        note         TEXT,
        created_at   DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_phone    (phone),
        KEY idx_source   (utm_source),
        KEY idx_campaign (utm_campaign),
        KEY idx_status   (status),
        KEY idx_created  (created_at)
    ) {$ch};");
}

// ─── UTM Capture ──────────────────────────────────────────────────────────────

function adsdefender_capture_utm(): void
{
    if (is_admin()) return;
    $utms = ['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','fbclid'];
    $has  = false;
    foreach ($utms as $k) {
        if (!empty($_GET[$k])) { $has = true; break; }
    }
    if (!$has) return;

    $data = [];
    foreach ($utms as $k) {
        $val = sanitize_text_field($_GET[$k] ?? '');
        // Lọc Google Ads ValueTrack template tags chưa được replace: {lpurl}, {keyword}, {placement}...
        if (preg_match('/^\{[^}]+\}/', $val) || $val === '{lpurl}') $val = '';
        $data[$k] = $val;
    }
    // Nếu toàn bộ utm_source là template tag → bỏ qua
    if (empty($data['utm_source']) && empty($data['gclid']) && empty($data['fbclid'])) return;

    $data['landing_url'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    // Làm sạch landing_url: xóa các param {*} còn sót
    $data['landing_url'] = preg_replace('/[?&][^=]+=\{[^}]+\}/', '', $data['landing_url']);
    $data['referrer'] = sanitize_text_field($_SERVER['HTTP_REFERER'] ?? '');

    $existing     = isset($_COOKIE['adutm']) ? json_decode(stripslashes($_COOKIE['adutm']), true) : [];
    $priority     = ['cpc','cpm','paid','google','facebook','zalo'];
    $existing_med = strtolower($existing['utm_medium'] ?? '');
    $new_med      = strtolower($data['utm_medium']);
    $overwrite    = empty($existing) || !empty($data['gclid']) || !empty($data['fbclid'])
                    || in_array($new_med, $priority) || !in_array($existing_med, $priority);

    if ($overwrite) {
        setcookie('adutm', json_encode($data), time() + 86400 * 30, '/', '', true, false);
        $_COOKIE['adutm'] = json_encode($data);
    }

    $sid = sanitize_text_field($_COOKIE['adutm_sid'] ?? '');
    if (!$sid) {
        $sid = bin2hex(random_bytes(16));
        setcookie('adutm_sid', $sid, time() + 86400 * 30, '/', '', true, false);
        $_COOKIE['adutm_sid'] = $sid;
    }
    global $wpdb;
    $t = $wpdb->prefix . ADSDEFENDER_UTM_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) return;
    $existing_row = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t} WHERE session_id=%s AND utm_source=%s LIMIT 1", $sid, $data['utm_source']
    ));
    if (!$existing_row) {
        $wpdb->insert($t, array_merge($data, [
            'session_id' => $sid,
            'ip'         => adsdefender_get_real_ip() ?: ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
            'created_at' => current_time('mysql'),
        ]));
    }
}

function adsdefender_get_current_utm(): array
{
    if (empty($_COOKIE['adutm'])) return [];
    $d = json_decode(stripslashes($_COOKIE['adutm']), true);
    return is_array($d) ? $d : [];
}

function adsdefender_get_session_id(): string
{
    return sanitize_text_field($_COOKIE['adutm_sid'] ?? '');
}

// ─── REST: conversion tracking ────────────────────────────────────────────────

add_action('rest_api_init', function () {
    register_rest_route('adsdefender/v1', '/conversion', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            $t   = $wpdb->prefix . ADSDEFENDER_UTM_TABLE;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) return ['ok' => false];
            $sid  = sanitize_text_field($req->get_param('session_id') ?? '');
            $type = sanitize_key($req->get_param('type') ?? '');
            $lbl  = substr(sanitize_text_field($req->get_param('label') ?? ''), 0, 100);
            if (!$sid || !$type) return new WP_Error('missing', 'Missing params', ['status' => 400]);
            $wpdb->update($t,
                ['conversion_type' => $type, 'conversion_label' => $lbl],
                ['session_id' => $sid],
                ['%s', '%s'], ['%s']
            );
            return ['ok' => true];
        },
        'permission_callback' => 'adsdefender_verify_rest_token',
    ]);
});

// ─── Đếm click link liên hệ trong nội dung ────────────────────────────────────

/**
 * Chèn onclick vào các link tel:/zalo.me/m.me/wa.me/t.me trong nội dung.
 *
 * Xử lý ở PHP nên trình duyệt nhận về HTML đã hoàn chỉnh — không có listener
 * nào đứng giữa cú bấm và hành vi mặc định. onclick chạy sau khi trình duyệt
 * đã quyết định điều hướng, nên dù adContactHit() lỗi thì tel: vẫn quay số.
 *
 * Link đã có onclick sẵn (nút Contact Bar) được bỏ qua để khỏi đếm trùng.
 */
function adsdefender_inject_contact_onclick($html)
{
    if (!is_string($html) || $html === '' || is_admin()) return $html;
    if (stripos($html, '<a ') === false) return $html;

    return preg_replace_callback(
        '#<a\s([^>]*?)href=(["\'])\s*(tel:[^"\']*|https?://(?:[^"\'/]*\.)?(?:zalo\.me|m\.me|wa\.me|t\.me)(?:[/?][^"\']*)?)\2([^>]*)>#i',
        function ($m) {
            $before = $m[1];
            $after  = $m[4];

            // Đã có onclick (nút Contact Bar) → không đụng vào, tránh đếm trùng.
            if (stripos($before . $after, 'onclick') !== false) return $m[0];

            $href = trim($m[3]);
            $low  = strtolower($href);
            if (strpos($low, 'tel:') === 0)          $type = 'phone';
            elseif (strpos($low, 'zalo.me') !== false) $type = 'zalo';
            elseif (strpos($low, 'm.me')    !== false) $type = 'messenger';
            elseif (strpos($low, 'wa.me')   !== false) $type = 'whatsapp';
            else                                       $type = 'telegram';

            // esc_js cho ngữ cảnh JS, esc_attr cho ngữ cảnh thuộc tính HTML.
            $val = esc_attr(esc_js(substr($href, 0, 60)));

            return '<a ' . $before . 'href=' . $m[2] . $m[3] . $m[2]
                 . ' onclick="adContactHit(&#39;' . $type . '&#39;,&#39;' . $val . '&#39;)"'
                 . $after . '>';
        },
        $html
    );
}

add_filter('the_content',  'adsdefender_inject_contact_onclick', 20);
add_filter('widget_text',  'adsdefender_inject_contact_onclick', 20);
add_filter('render_block', 'adsdefender_inject_contact_onclick', 20);

// ─── Frontend: inject UTM JS + conversion tracking ────────────────────────────

add_action('wp_footer', function () {
    if (is_admin()) return;
    $sid      = adsdefender_get_session_id();
    $utm      = adsdefender_get_current_utm();
    $rest_url = esc_url(rest_url('adsdefender/v1/conversion'));
    $token    = adsdefender_rest_token();
    // Luôn inject window.adUTM (popup cần token dù không có UTM)
    ?>
<script>
window.adUTM = <?php echo json_encode([
    'sid'      => $sid,
    'source'   => $utm['utm_source']   ?? '',
    'medium'   => $utm['utm_medium']   ?? '',
    'campaign' => $utm['utm_campaign'] ?? '',
    'gclid'    => $utm['gclid']        ?? '',
    'token'    => $token,
], JSON_UNESCAPED_UNICODE); ?>;
window.adUTM.restUrl = '<?php echo $rest_url; ?>';
/* Gọi từ onclick="" mà PHP đã chèn sẵn vào link tel:/zalo trong nội dung
   (adsdefender_inject_contact_onclick). Không addEventListener, không bắt
   click toàn site: trình duyệt đã quyết định điều hướng trước khi hàm này
   chạy, nên dù nó lỗi thì tel:/zalo: vẫn mở bình thường. */
function adContactHit(type, label){
 try {
  if (!window.adUTM || !window.adUTM.sid) return;

  /* Matomo / TrackSG */
  if (typeof _paq !== 'undefined') {
    _paq.push(['trackEvent', 'Contact', 'Click', type + ':' + label]);
  }
  /* Meta Pixel */
  if (typeof fbq !== 'undefined') {
    fbq('track', 'Contact', {content_category: type, content_name: label});
  }
  /* GA4 */
  if (typeof gtag !== 'undefined') {
    gtag('event', 'contact_click', {event_category: type, event_label: label});
  }
  /* GTM dataLayer */
  <?php if (!empty(adsdefender_get_tracking_settings()['gtm_id'])): ?>
  window.dataLayer = window.dataLayer || [];
  dataLayer.push({event:'contact_click', event_category:type, event_label:label, contact_type:type});
  <?php endif; ?>

  /* Ghi vào bảng UTM để gán chuyển đổi cho phiên (attribution) */
  if (navigator.sendBeacon) {
    navigator.sendBeacon(window.adUTM.restUrl, new Blob([JSON.stringify({
      session_id: window.adUTM.sid,
      type:       type,
      label:      label,
      _ads_token: window.adUTM.token
    })], {type:'application/json'}));
  }
 } catch(err) {}
}
</script>
<?php
}, 5);

// ─── Admin: trang UTM & Attribution ──────────────────────────────────────────

function adsdefender_page_utm()
{
    global $wpdb;
    $t = $wpdb->prefix . ADSDEFENDER_UTM_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) {
        adsdefender_create_utm_table();
    }

    if (isset($_POST['adutm_clear']) && check_admin_referer('adutm_clear')) {
        $wpdb->query("TRUNCATE TABLE {$t}");
        echo '<div class="notice notice-success is-dismissible"><p>Đã xóa toàn bộ UTM log.</p></div>';
    }

    // range: today | yesterday | N (số ngày). Dùng current_time() để theo múi giờ
    // của site, không phải giờ UTC của server — nếu không, "hôm nay" sẽ lệch.
    $range = sanitize_key($_GET['range'] ?? '');
    if ($range === '' && isset($_GET['days'])) $range = (string) (int) $_GET['days'];
    if ($range === '') $range = '30';

    $today = current_time('Y-m-d');
    if ($range === 'today') {
        $since  = $today . ' 00:00:00';
        $until  = $today . ' 23:59:59';
        $days   = 1;
        $period = 'Hôm nay ' . date_i18n('d/m/Y', strtotime($today));
    } elseif ($range === 'yesterday') {
        $y      = date('Y-m-d', strtotime($today . ' -1 day'));
        $since  = $y . ' 00:00:00';
        $until  = $y . ' 23:59:59';
        $days   = 1;
        $period = 'Hôm qua ' . date_i18n('d/m/Y', strtotime($y));
    } else {
        $days   = max(1, min(90, (int) $range));
        $range  = (string) $days;
        $since  = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days')) . ' 00:00:00';
        $until  = $today . ' 23:59:59';
        $period = date_i18n('d/m/Y', strtotime($since)) . ' — ' . date_i18n('d/m/Y', strtotime($today));
    }

    // Mọi truy vấn dùng chung khoảng [$since, $until] để các con số khớp nhau.
    $win = $wpdb->prepare('created_at BETWEEN %s AND %s', $since, $until);

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$win}");
    $conv  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$win} AND conversion_type != ''");
    $rate  = $total > 0 ? round($conv / $total * 100, 1) : 0;

    // ── Thống kê trong ngày ──────────────────────────────────────────────────
    // Hôm nay so với hôm qua, và phân bố theo giờ để biết khung giờ nào đông.
    $t_start = $today . ' 00:00:00';
    $t_end   = $today . ' 23:59:59';
    $y_date  = date('Y-m-d', strtotime($today . ' -1 day'));

    $today_sessions = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE created_at BETWEEN %s AND %s", $t_start, $t_end));
    $today_conv = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE created_at BETWEEN %s AND %s AND conversion_type != ''", $t_start, $t_end));
    $yest_sessions = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE created_at BETWEEN %s AND %s", $y_date . ' 00:00:00', $y_date . ' 23:59:59'));
    $yest_conv = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE created_at BETWEEN %s AND %s AND conversion_type != ''", $y_date . ' 00:00:00', $y_date . ' 23:59:59'));

    // Phân bố theo giờ của hôm nay (0-23)
    $hourly = array_fill(0, 24, ['sessions' => 0, 'conv' => 0]);
    $rows_h = $wpdb->get_results($wpdb->prepare(
        "SELECT HOUR(created_at) AS h, COUNT(*) AS sessions,
                SUM(conversion_type != '') AS conv
         FROM {$t} WHERE created_at BETWEEN %s AND %s
         GROUP BY HOUR(created_at)", $t_start, $t_end));
    foreach ($rows_h as $r) {
        $h = (int) $r->h;
        if ($h >= 0 && $h <= 23) $hourly[$h] = ['sessions' => (int) $r->sessions, 'conv' => (int) $r->conv];
    }

    // Xu hướng từng ngày trong khoảng đang xem (tối đa 30 cột cho dễ nhìn)
    $daily = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(created_at) AS d, COUNT(*) AS sessions,
                SUM(conversion_type != '') AS conv
         FROM {$t} WHERE created_at BETWEEN %s AND %s
         GROUP BY DATE(created_at) ORDER BY d ASC LIMIT 90", $since, $until));

    $by_source = $wpdb->get_results(
        "SELECT utm_source, utm_medium, COUNT(*) as sessions,
         SUM(conversion_type != '') as conversions
         FROM {$t} WHERE {$win} AND utm_source != ''
         GROUP BY utm_source, utm_medium ORDER BY sessions DESC LIMIT 20"
    );
    $by_campaign = $wpdb->get_results(
        "SELECT utm_campaign, utm_source, COUNT(*) as sessions,
         SUM(conversion_type != '') as conversions
         FROM {$t} WHERE {$win} AND utm_campaign != ''
         GROUP BY utm_campaign, utm_source ORDER BY conversions DESC, sessions DESC LIMIT 20"
    );
    $by_conv = $wpdb->get_results(
        "SELECT conversion_type, conversion_label, COUNT(*) as cnt
         FROM {$t} WHERE {$win} AND conversion_type != ''
         GROUP BY conversion_type, conversion_label ORDER BY cnt DESC"
    );
    $recent = $wpdb->get_results(
        "SELECT * FROM {$t} WHERE {$win} ORDER BY created_at DESC LIMIT 50"
    );
    ?>
<p style="color:#555">Theo dõi nguồn traffic → chuyển đổi (click Zalo, Phone, Messenger...).</p>

<!-- Lọc ngày -->
<div style="margin-bottom:20px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
  <?php
  $ranges = ['today' => 'Hôm nay', 'yesterday' => 'Hôm qua',
             '7' => '7 ngày', '14' => '14 ngày', '30' => '30 ngày', '60' => '60 ngày', '90' => '90 ngày'];
  foreach ($ranges as $k => $lbl):
      $url = esc_url(add_query_arg(['range' => $k, 'days' => false]));
  ?>
  <a href="<?php echo $url; ?>" class="button <?php echo $range === $k ? 'button-primary' : ''; ?>"><?php echo $lbl; ?></a>
  <?php endforeach; ?>
  <span style="color:#666;font-size:13px;margin-left:4px"><?php echo esc_html($period); ?></span>
</div>

<!-- Thống kê trong ngày -->
<?php
$d_sess = $today_sessions - $yest_sessions;
$d_conv = $today_conv - $yest_conv;
$t_rate = $today_sessions > 0 ? round($today_conv / $today_sessions * 100, 1) : 0;
$y_rate = $yest_sessions  > 0 ? round($yest_conv  / $yest_sessions  * 100, 1) : 0;
$peak_h = 0; $peak_v = 0;
foreach ($hourly as $h => $v) { if ($v['sessions'] > $peak_v) { $peak_v = $v['sessions']; $peak_h = $h; } }
$max_h  = max(1, $peak_v);
$now_h  = (int) current_time('G');

function adsdefender_utm_delta(int $d): string
{
    if ($d === 0) return '<span style="color:#888;font-size:12px">— không đổi</span>';
    $up  = $d > 0;
    $col = $up ? '#00a32a' : '#d63638';
    return '<span style="color:' . $col . ';font-size:12px;font-weight:600">'
         . ($up ? '▲ +' : '▼ ') . $d . ' so với hôm qua</span>';
}
?>
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 20px;margin-bottom:24px">
  <h3 style="margin:0 0 14px;display:flex;align-items:center;gap:8px">
    📅 Hôm nay — <?php echo date_i18n('d/m/Y'); ?>
    <span style="font-weight:400;font-size:12px;color:#888">cập nhật lúc <?php echo current_time('H:i'); ?></span>
  </h3>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:18px">
    <?php foreach ([
      ['Sessions hôm nay',  $today_sessions, '#2271b1', adsdefender_utm_delta($d_sess)],
      ['Chuyển đổi hôm nay',$today_conv,     '#00a32a', adsdefender_utm_delta($d_conv)],
      ['Tỷ lệ chuyển đổi',  $t_rate . '%',   '#f0860a', '<span style="color:#888;font-size:12px">hôm qua ' . $y_rate . '%</span>'],
      ['Giờ cao điểm',      $peak_v > 0 ? sprintf('%02d:00', $peak_h) : '—', '#7048e8',
       '<span style="color:#888;font-size:12px">' . ($peak_v > 0 ? $peak_v . ' sessions' : 'chưa có dữ liệu') . '</span>'],
    ] as [$lbl, $val, $col, $sub]): ?>
    <div style="background:#f8f9fa;border-left:3px solid <?php echo $col; ?>;border-radius:4px;padding:12px 14px">
      <div style="font-size:24px;font-weight:700;color:<?php echo $col; ?>;line-height:1.1"><?php echo $val; ?></div>
      <div style="font-size:12px;color:#666;margin:4px 0 3px"><?php echo $lbl; ?></div>
      <?php echo $sub; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Biểu đồ theo giờ -->
  <div style="font-size:12px;color:#666;margin-bottom:6px;font-weight:600">Phân bố theo giờ</div>
  <?php if ($today_sessions === 0): ?>
  <p style="color:#999;font-size:13px;margin:8px 0 0">Chưa có session nào hôm nay.</p>
  <?php else: ?>
  <div style="display:flex;align-items:flex-end;gap:2px;height:90px;padding:0 0 4px;overflow-x:auto">
    <?php for ($h = 0; $h <= 23; $h++):
      $s   = $hourly[$h]['sessions'];
      $c   = $hourly[$h]['conv'];
      $pct = round($s / $max_h * 100);
      $fut = $h > $now_h;
    ?>
    <div style="flex:1;min-width:18px;display:flex;flex-direction:column;align-items:center;gap:3px"
         title="<?php echo sprintf('%02d:00', $h); ?> — <?php echo $s; ?> sessions, <?php echo $c; ?> chuyển đổi">
      <div style="width:100%;height:70px;display:flex;align-items:flex-end">
        <div style="width:100%;height:<?php echo max($s > 0 ? 3 : 0, $pct); ?>%;
                    background:<?php echo $fut ? '#f0f0f0' : ($c > 0 ? '#00a32a' : '#2271b1'); ?>;
                    border-radius:2px 2px 0 0;transition:height .2s"></div>
      </div>
      <div style="font-size:9px;color:<?php echo $h === $now_h ? '#2271b1' : '#aaa'; ?>;
                  font-weight:<?php echo $h === $now_h ? '700' : '400'; ?>"><?php echo $h; ?></div>
    </div>
    <?php endfor; ?>
  </div>
  <div style="font-size:11px;color:#999;margin-top:4px">
    <span style="display:inline-block;width:9px;height:9px;background:#2271b1;border-radius:2px;vertical-align:middle"></span> sessions
    &nbsp;<span style="display:inline-block;width:9px;height:9px;background:#00a32a;border-radius:2px;vertical-align:middle"></span> giờ có chuyển đổi
  </div>
  <?php endif; ?>
</div>

<!-- Xu hướng theo ngày -->
<?php if (count($daily) > 1): ?>
<?php $max_d = 1; foreach ($daily as $r) $max_d = max($max_d, (int) $r->sessions); ?>
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 20px;margin-bottom:24px">
  <h3 style="margin:0 0 14px">📈 Xu hướng theo ngày</h3>
  <div style="display:flex;align-items:flex-end;gap:3px;height:110px;overflow-x:auto;padding-bottom:4px">
    <?php foreach ($daily as $r):
      $s = (int) $r->sessions; $c = (int) $r->conv;
      $pct = round($s / $max_d * 100);
      $is_today = $r->d === $today;
    ?>
    <div style="flex:1;min-width:22px;display:flex;flex-direction:column;align-items:center;gap:3px"
         title="<?php echo date_i18n('d/m/Y', strtotime($r->d)); ?> — <?php echo $s; ?> sessions, <?php echo $c; ?> chuyển đổi">
      <div style="font-size:10px;color:#666;font-weight:600"><?php echo $s; ?></div>
      <div style="width:100%;height:70px;display:flex;align-items:flex-end">
        <div style="width:100%;height:<?php echo max(3, $pct); ?>%;
                    background:<?php echo $is_today ? '#00a32a' : '#2271b1'; ?>;
                    border-radius:2px 2px 0 0"></div>
      </div>
      <div style="font-size:9px;color:#aaa;white-space:nowrap"><?php echo date_i18n('d/m', strtotime($r->d)); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Summary cards -->
<div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap">
  <?php foreach ([
    ['Sessions — ' . $period, $total, '#2271b1'],
    ['Chuyển đổi', $conv, '#00a32a'],
    ['Tỷ lệ conv.', $rate . '%', '#f0860a'],
  ] as [$l,$v,$c]): ?>
  <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 20px;border-top:3px solid <?php echo $c; ?>">
    <div style="font-size:22px;font-weight:700;color:<?php echo $c; ?>"><?php echo $v; ?></div>
    <div style="font-size:12px;color:#666;margin-top:2px"><?php echo $l; ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

<!-- Theo nguồn -->
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px">
<h3 style="margin-top:0">📊 Theo nguồn traffic</h3>
<?php if (empty($by_source)): ?>
<p style="color:#999">Chưa có dữ liệu.</p>
<?php else: ?>
<table class="widefat" style="font-size:13px">
  <thead><tr><th>Source</th><th>Medium</th><th>Sessions</th><th>Conv.</th><th>Rate</th></tr></thead>
  <tbody>
  <?php foreach ($by_source as $r): ?>
  <tr>
    <td><strong><?php echo esc_html($r->utm_source); ?></strong></td>
    <td><span style="background:#f0f6fc;padding:1px 5px;border-radius:3px;font-size:11px"><?php echo esc_html($r->utm_medium ?: '—'); ?></span></td>
    <td><?php echo (int)$r->sessions; ?></td>
    <td style="color:#00a32a"><strong><?php echo (int)$r->conversions; ?></strong></td>
    <td style="color:#f0860a"><?php echo $r->sessions > 0 ? round($r->conversions/$r->sessions*100,1) : 0; ?>%</td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

<!-- Theo campaign -->
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px">
<h3 style="margin-top:0">🎯 Theo campaign</h3>
<?php if (empty($by_campaign)): ?>
<p style="color:#999">Chưa có dữ liệu.</p>
<?php else: ?>
<table class="widefat" style="font-size:13px">
  <thead><tr><th>Campaign</th><th>Source</th><th>Sessions</th><th>Conv.</th></tr></thead>
  <tbody>
  <?php foreach ($by_campaign as $r): ?>
  <tr>
    <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr($r->utm_campaign); ?>">
      <?php echo esc_html($r->utm_campaign); ?></td>
    <td><span style="font-size:11px;color:#666"><?php echo esc_html($r->utm_source); ?></span></td>
    <td><?php echo (int)$r->sessions; ?></td>
    <td style="color:#00a32a"><strong><?php echo (int)$r->conversions; ?></strong></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>
</div>

<!-- Theo loại conversion -->
<?php if (!empty($by_conv)): ?>
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-bottom:24px">
<h3 style="margin-top:0">✅ Loại chuyển đổi</h3>
<table class="widefat" style="font-size:13px">
  <thead><tr><th>Loại</th><th>Label</th><th>Số lượt</th></tr></thead>
  <tbody>
  <?php foreach ($by_conv as $r): ?>
  <tr>
    <td><code><?php echo esc_html($r->conversion_type); ?></code></td>
    <td><?php echo esc_html($r->conversion_label ?: '—'); ?></td>
    <td><strong><?php echo (int)$r->cnt; ?></strong></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Recent sessions -->
<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-bottom:20px">
<h3 style="margin-top:0">🕐 Sessions gần đây (50 bản ghi mới nhất)</h3>
<div style="overflow-x:auto">
<table class="widefat" style="font-size:12px;min-width:1100px">
  <thead><tr>
    <th>Thời gian</th><th>IP</th><th>Source</th><th>Medium</th><th>Campaign</th>
    <th>Keyword</th><th>GCLID</th><th>Landing</th><th>UA</th><th>Conv.</th>
  </tr></thead>
  <tbody>
  <?php if (empty($recent)): ?>
  <tr><td colspan="10" style="text-align:center;color:#999;padding:20px">Chưa có session nào trong khoảng thời gian này.</td></tr>
  <?php else: foreach ($recent as $r): ?>
  <?php
    $ua = $r->user_agent ?? '';
    $ua_short = '';
    if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) $ua_short = '📱 Mobile';
    elseif (preg_match('/Chrome/i', $ua))  $ua_short = '🖥 Chrome';
    elseif (preg_match('/Firefox/i', $ua)) $ua_short = '🖥 Firefox';
    elseif (preg_match('/Safari/i', $ua))  $ua_short = '🖥 Safari';
    elseif ($ua) $ua_short = '🌐 Other';
    else $ua_short = '—';
  ?>
  <tr style="<?php echo $r->conversion_type ? 'background:#f0faf0' : ''; ?>">
    <td style="white-space:nowrap;color:#888;font-size:11px"><?php echo esc_html(substr($r->created_at,0,16)); ?></td>
    <td style="font-family:monospace;font-size:11px;white-space:nowrap">
      <a href="https://ipinfo.io/<?php echo esc_attr($r->ip); ?>" target="_blank" style="text-decoration:none;color:#2271b1"><?php echo esc_html($r->ip ?: '—'); ?></a>
    </td>
    <td><span style="background:#eef;padding:1px 5px;border-radius:3px"><?php echo esc_html($r->utm_source ?: 'direct'); ?></span></td>
    <td><?php echo esc_html($r->utm_medium ?: '—'); ?></td>
    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr($r->utm_campaign); ?>">
      <?php echo esc_html($r->utm_campaign ?: '—'); ?></td>
    <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#555" title="<?php echo esc_attr($r->utm_term ?? ''); ?>">
      <?php echo esc_html($r->utm_term ? mb_substr($r->utm_term, 0, 30) : '—'); ?></td>
    <td style="font-size:10px;color:#888;max-width:70px;overflow:hidden"><?php echo $r->gclid ? '<span title="'.esc_attr($r->gclid).'">'.substr(esc_html($r->gclid),0,12).'…</span>' : '—'; ?></td>
    <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px" title="<?php echo esc_attr($r->landing_url); ?>">
      <?php echo esc_html(parse_url($r->landing_url, PHP_URL_PATH) ?: '/'); ?></td>
    <td style="font-size:11px;white-space:nowrap" title="<?php echo esc_attr($ua); ?>"><?php echo $ua_short; ?></td>
    <td><?php echo $r->conversion_type
          ? '<span style="color:#00a32a;white-space:nowrap">✅ '.esc_html($r->conversion_type).'</span>'
          : '<span style="color:#ccc">—</span>'; ?></td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>
</div>

<form method="post" style="margin-bottom:4px">
  <?php wp_nonce_field('adutm_clear'); ?>
  <button type="submit" name="adutm_clear" class="button button-secondary"
    onclick="return confirm('Xóa toàn bộ UTM log?')">🗑 Xóa toàn bộ log</button>
</form>

<hr style="margin-top:24px">
<h2>📌 Hướng dẫn cài UTM</h2>
<?php $site_url = get_site_url(); ?>

<div style="display:grid;gap:20px;max-width:820px">

<!-- Google Ads -->
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px">
  <h3 style="margin-top:0;color:#1a73e8">🔵 Google Ads</h3>
  <p style="color:#555;font-size:13px;margin-bottom:10px">Vào <strong>Campaign → Settings → Campaign URL options → Tracking template</strong>, dán vào:</p>
  <div style="position:relative">
    <code id="utm-google" style="display:block;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 12px;font-size:12px;word-break:break-all;line-height:1.6">
      {lpurl}?utm_source=google&amp;utm_medium=cpc&amp;utm_campaign={_campaignname}&amp;utm_term={keyword}&amp;utm_content={creative}&amp;gclid={gclid}
    </code>
    <button onclick="adsCopy('utm-google',this)" class="button" style="position:absolute;top:6px;right:6px;font-size:11px">Copy</button>
  </div>
  <p style="color:#888;font-size:12px;margin-top:8px">
    💡 <code>{lpurl}</code> = Final URL của ad. <code>{_campaignname}</code> = tên campaign. <code>{keyword}</code> = từ khóa trigger.<br>
    Nếu dùng <strong>Final URL suffix</strong> thay vì Tracking template, dán vào đó (không cần <code>{lpurl}?</code>):
  </p>
  <div style="position:relative;margin-top:6px">
    <code id="utm-google-suffix" style="display:block;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 12px;font-size:12px;word-break:break-all;line-height:1.6">
      utm_source=google&amp;utm_medium=cpc&amp;utm_campaign={_campaignname}&amp;utm_term={keyword}&amp;utm_content={creative}&amp;gclid={gclid}
    </code>
    <button onclick="adsCopy('utm-google-suffix',this)" class="button" style="position:absolute;top:6px;right:6px;font-size:11px">Copy</button>
  </div>
</div>

<!-- Meta / Facebook Ads -->
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px">
  <h3 style="margin-top:0;color:#1877f2">🔷 Meta / Facebook Ads</h3>
  <p style="color:#555;font-size:13px;margin-bottom:10px">Vào <strong>Ad → Destination → URL Parameters</strong> (hoặc dán thẳng vào Website URL):</p>
  <div style="position:relative">
    <code id="utm-meta" style="display:block;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 12px;font-size:12px;word-break:break-all;line-height:1.6">
      utm_source=facebook&amp;utm_medium=cpc&amp;utm_campaign={{campaign.name}}&amp;utm_content={{ad.name}}&amp;fbclid={{fbclid}}
    </code>
    <button onclick="adsCopy('utm-meta',this)" class="button" style="position:absolute;top:6px;right:6px;font-size:11px">Copy</button>
  </div>
  <p style="color:#888;font-size:12px;margin-top:8px">💡 <code>{{campaign.name}}</code> là dynamic parameter của Meta, tự động điền tên campaign.</p>
</div>

<!-- Zalo Ads -->
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px">
  <h3 style="margin-top:0;color:#0068ff">🔹 Zalo Ads</h3>
  <p style="color:#555;font-size:13px;margin-bottom:10px">Vào <strong>Cài đặt quảng cáo → URL đích</strong>, thêm tham số vào cuối URL:</p>
  <div style="position:relative">
    <code id="utm-zalo" style="display:block;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 12px;font-size:12px;word-break:break-all;line-height:1.6">
      <?php echo esc_html($site_url); ?>/?utm_source=zalo&amp;utm_medium=cpc&amp;utm_campaign=ten-campaign
    </code>
    <button onclick="adsCopy('utm-zalo',this)" class="button" style="position:absolute;top:6px;right:6px;font-size:11px">Copy</button>
  </div>
</div>

<!-- TikTok Ads -->
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px">
  <h3 style="margin-top:0;color:#010101">⬛ TikTok Ads</h3>
  <p style="color:#555;font-size:13px;margin-bottom:10px">Vào <strong>Ad → Destination URL</strong>, thêm vào cuối URL:</p>
  <div style="position:relative">
    <code id="utm-tiktok" style="display:block;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 12px;font-size:12px;word-break:break-all;line-height:1.6">
      utm_source=tiktok&amp;utm_medium=cpc&amp;utm_campaign=__CAMPAIGN_NAME__&amp;utm_content=__CID__
    </code>
    <button onclick="adsCopy('utm-tiktok',this)" class="button" style="position:absolute;top:6px;right:6px;font-size:11px">Copy</button>
  </div>
  <p style="color:#888;font-size:12px;margin-top:8px">💡 <code>__CAMPAIGN_NAME__</code> và <code>__CID__</code> là macro TikTok tự điền.</p>
</div>

<!-- Email / Zalo OA thủ công -->
<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px">
  <h3 style="margin-top:0;color:#555">✉️ Email / Zalo OA / Link thủ công</h3>
  <p style="color:#555;font-size:13px;margin-bottom:10px">Dùng link đầy đủ, thay giá trị theo từng chiến dịch:</p>
  <div style="position:relative">
    <code id="utm-manual" style="display:block;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 12px;font-size:12px;word-break:break-all;line-height:1.6">
      <?php echo esc_html($site_url); ?>/?utm_source=email&amp;utm_medium=email&amp;utm_campaign=khuyen-mai-thang-5
    </code>
    <button onclick="adsCopy('utm-manual',this)" class="button" style="position:absolute;top:6px;right:6px;font-size:11px">Copy</button>
  </div>
</div>

<!-- Giải thích tham số -->
<div style="background:#f0f6fc;border:1px solid #c5d9ed;border-radius:8px;padding:16px 20px">
  <h3 style="margin-top:0">📖 Ý nghĩa các tham số</h3>
  <table class="widefat" style="font-size:13px;background:transparent;border:none">
    <thead><tr style="background:transparent"><th>Tham số</th><th>Ý nghĩa</th><th>Ví dụ</th></tr></thead>
    <tbody>
      <tr><td><code>utm_source</code></td><td>Nguồn traffic</td><td>google, facebook, zalo, email</td></tr>
      <tr><td><code>utm_medium</code></td><td>Kênh quảng cáo</td><td>cpc, email, social, organic</td></tr>
      <tr><td><code>utm_campaign</code></td><td>Tên chiến dịch</td><td>dien-lanh-he-2026, khuyen-mai-tet</td></tr>
      <tr><td><code>utm_term</code></td><td>Từ khóa (Google Ads)</td><td>sua may lanh, dien lanh gia re</td></tr>
      <tr><td><code>utm_content</code></td><td>Phân biệt các ad/banner</td><td>banner-top, cta-button</td></tr>
      <tr><td><code>gclid</code></td><td>Google Click ID (tự động)</td><td><em>do Google điền</em></td></tr>
      <tr><td><code>fbclid</code></td><td>Facebook Click ID (tự động)</td><td><em>do Meta điền</em></td></tr>
    </tbody>
  </table>
</div>

</div>

<script>
function adsCopy(id, btn) {
  var el = document.getElementById(id);
  var text = el ? el.innerText.trim() : '';
  navigator.clipboard.writeText(text).then(function() {
    var orig = btn.textContent;
    btn.textContent = '✅ Copied';
    btn.disabled = true;
    setTimeout(function(){ btn.textContent = orig; btn.disabled = false; }, 2000);
  });
}
</script>
<?php
}
