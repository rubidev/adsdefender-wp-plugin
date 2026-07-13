<?php
if (!defined('ABSPATH')) exit;

// Ngan CSV/formula injection khi mo file bang Excel/Sheets (fields bat dau bang =,+,-,@,tab,CR)
function adsdefender_csv_safe($value): string
{
    $value = (string) $value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        $value = "'" . $value;
    }
    return $value;
}

function adsdefender_create_lead_table_if_needed()
{
    global $wpdb;
    $t = $wpdb->prefix . ADSDEFENDER_LEAD_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) {
        adsdefender_create_lead_table();
    } else {
        // Migration: thêm cột note nếu chưa có
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$t}`");
        if (!in_array('note', $cols, true)) {
            $wpdb->query("ALTER TABLE `{$t}` ADD COLUMN note TEXT AFTER status");
        }
    }
}

// ─── Frontend: popup HTML + JS ────────────────────────────────────────────────

add_action('wp_footer', function () {
    if (is_admin()) return;
    $popups = get_option(ADSDEFENDER_OPTION_POPUP, []);
    if (empty($popups)) return;
    $active = array_filter($popups, fn($p) => !empty($p['enabled']));
    if (empty($active)) return;
    $utm      = adsdefender_get_current_utm();
    $rest_lead = esc_url(rest_url('adsdefender/v1/lead'));
    ?>
<style>
.adpop-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:999998;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;transition:opacity .3s}
.adpop-overlay.adpop-show{opacity:1}
.adpop-box{background:#fff;border-radius:12px;width:100%;max-width:460px;overflow:hidden;transform:translateY(20px);transition:transform .3s;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.adpop-overlay.adpop-show .adpop-box{transform:translateY(0)}
.adpop-header{padding:24px 24px 16px;position:relative}
.adpop-close{position:absolute;top:12px;right:14px;background:none;border:none;font-size:22px;cursor:pointer;color:#999;line-height:1}
.adpop-close:hover{color:#333}
.adpop-title{font-size:20px;font-weight:700;margin:0 0 6px;color:#1a1a2e}
.adpop-sub{font-size:14px;color:#666;margin:0}
.adpop-body{padding:0 24px 24px}
.adpop-field{margin-bottom:12px}
.adpop-field label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:4px}
.adpop-field input,.adpop-field textarea{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;font-family:inherit;transition:border-color .2s}
.adpop-field input:focus,.adpop-field textarea:focus{outline:none;border-color:#2271b1}
.adpop-submit{width:100%;padding:13px;border:none;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;transition:filter .2s;margin-top:4px}
.adpop-submit:hover{filter:brightness(1.08)}
.adpop-success{text-align:center;padding:32px 24px;display:none}
.adpop-success .adpop-ok-icon{font-size:48px;margin-bottom:12px}
.adpop-success p{color:#555;font-size:14px}
.adpop-badge{font-size:11px;color:#999;text-align:center;margin-top:12px}
</style>
<?php
function adsdefender_render_popup_html(string $pid, array $popup, string $variant = 'a'): void
{
    $vid     = $variant === 'b' ? $pid . '_b' : $pid . '_a';
    $title   = $variant === 'b' && !empty($popup['ab_title'])   ? esc_html($popup['ab_title'])   : esc_html($popup['title']   ?? 'Để lại thông tin');
    $sub     = $variant === 'b' && !empty($popup['ab_sub'])     ? esc_html($popup['ab_sub'])     : esc_html($popup['sub']     ?? 'Chúng tôi sẽ liên hệ ngay!');
    $btn_txt = $variant === 'b' && !empty($popup['ab_btn_txt']) ? esc_html($popup['ab_btn_txt']) : esc_html($popup['btn_txt'] ?? 'Gửi ngay');
    $btn_col = $variant === 'b' && !empty($popup['ab_btn_col']) ? esc_attr($popup['ab_btn_col']) : esc_attr($popup['btn_col'] ?? '#d63638');
    $img     = $variant === 'b' && !empty($popup['ab_image'])   ? esc_url($popup['ab_image'])    : esc_url($popup['image'] ?? '');
    $bg_col  = esc_attr($popup['bg_col'] ?? '#ffffff');
    $fields  = $popup['fields'] ?? ['name'=>1,'phone'=>1,'email'=>0,'message'=>0];
    ?>
<div id="adpop-<?php echo $vid; ?>" class="adpop-overlay" style="display:none">
  <div class="adpop-box" style="background:<?php echo $bg_col; ?>">
    <?php if ($img): ?><img src="<?php echo $img; ?>" alt="" style="width:100%;height:160px;object-fit:cover"><?php endif; ?>
    <div class="adpop-header">
      <button class="adpop-close" onclick="adPopClose('<?php echo $vid; ?>')" aria-label="Đóng">×</button>
      <h2 class="adpop-title"><?php echo $title; ?></h2>
      <p class="adpop-sub"><?php echo $sub; ?></p>
    </div>
    <div class="adpop-body">
      <div class="adpop-form">
        <?php if (!empty($fields['name'])): ?>
        <div class="adpop-field"><label>Họ tên</label>
          <input type="text" name="adpop_name" placeholder="Nguyễn Văn A" autocomplete="name"></div>
        <?php endif; ?>
        <?php if (!empty($fields['phone'])): ?>
        <div class="adpop-field"><label>Số điện thoại <span style="color:#d63638">*</span></label>
          <input type="tel" name="adpop_phone" placeholder="0901 234 567" autocomplete="tel" required></div>
        <?php endif; ?>
        <?php if (!empty($fields['email'])): ?>
        <div class="adpop-field"><label>Email</label>
          <input type="email" name="adpop_email" placeholder="email@example.com" autocomplete="email"></div>
        <?php endif; ?>
        <?php if (!empty($fields['message'])): ?>
        <div class="adpop-field"><label>Yêu cầu</label>
          <textarea name="adpop_message" rows="3" placeholder="Nhu cầu của bạn..."></textarea></div>
        <?php endif; ?>
        <div style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden" aria-hidden="true">
          <input type="text" name="adpop_website" tabindex="-1" autocomplete="off" value="">
        </div>
        <button class="adpop-submit" style="background:<?php echo $btn_col; ?>;color:#fff"
          onclick="adPopSubmit('<?php echo $vid; ?>')"><?php echo $btn_txt; ?></button>
        <p class="adpop-badge">🔒 Thông tin được bảo mật tuyệt đối</p>
      </div>
      <div class="adpop-success">
        <div class="adpop-ok-icon">🎉</div>
        <h3 style="color:#00a32a">Gửi thành công!</h3>
        <p><?php echo esc_html($popup['success_msg'] ?? 'Cảm ơn! Chúng tôi sẽ liên hệ với bạn sớm nhất.'); ?></p>
      </div>
    </div>
  </div>
</div>
    <?php
}

foreach ($active as $pid => $popup):
    $pid     = sanitize_key($pid);
    $trigger = $popup['trigger']  ?? 'delay';
    $delay   = max(0, (int)($popup['delay'] ?? 5));
    $scroll  = max(0, min(100, (int)($popup['scroll_pct'] ?? 50)));
    $once    = !empty($popup['once_per_session']) ? 'true' : 'false';
    $ab      = !empty($popup['ab_enabled']);
?>
<?php
adsdefender_render_popup_html($pid, $popup, 'a');
if ($ab) adsdefender_render_popup_html($pid, $popup, 'b');
?>
<script>
(function(){
  var pid     = '<?php echo esc_js($pid); ?>';
  var ab      = <?php echo $ab ? 'true' : 'false'; ?>;
  var trigger = '<?php echo esc_js($trigger); ?>';
  var delay   = <?php echo $delay * 1000; ?>;
  var scroll  = <?php echo $scroll; ?>;
  var once    = <?php echo $once; ?>;
  var shown   = false;
  var key     = 'adpop_shown_' + pid;

  // A/B: chọn variant ngẫu nhiên hoặc dùng lại từ session
  var variant = 'a';
  if (ab) {
    var abKey = 'adpop_ab_' + pid;
    variant = sessionStorage.getItem(abKey) || (Math.random() < 0.5 ? 'a' : 'b');
    sessionStorage.setItem(abKey, variant);
  }
  var vid = pid + '_' + variant;

  function show(){
    if (shown) return;
    if (once && sessionStorage.getItem(key)) return;
    shown = true; sessionStorage.setItem(key,'1');
    var el = document.getElementById('adpop-' + vid);
    if (!el) return;
    el.style.display='flex';
    setTimeout(function(){ el.classList.add('adpop-show'); }, 10);
  }

  if (trigger==='delay') {
    setTimeout(show, delay || 5000);
  } else if (trigger==='scroll') {
    window.addEventListener('scroll', function onS(){
      var pct=(window.scrollY/(document.body.scrollHeight-window.innerHeight))*100;
      if(pct>=scroll){window.removeEventListener('scroll',onS);show();}
    },{passive:true});
  } else if (trigger==='exit') {
    document.addEventListener('mouseleave',function(e){if(e.clientY<=0)show();});
  } else if (trigger==='idle') {
    var idleT;
    function reset(){clearTimeout(idleT);idleT=setTimeout(show,delay||30000);}
    ['mousemove','keydown','scroll','click'].forEach(function(ev){
      document.addEventListener(ev,reset,{passive:true});
    });
    reset();
  }
})();
</script>
<?php endforeach; ?>
<script>
function adPopClose(pid){
  var el=document.getElementById('adpop-'+pid);
  if(!el) return;
  el.classList.remove('adpop-show');
  setTimeout(function(){el.style.display='none';},300);
}
function adPopSubmit(vid){
  var el=document.getElementById('adpop-'+vid);
  if(!el) return;
  var phone=(el.querySelector('[name=adpop_phone]')||{}).value||'';
  if(!phone.trim()){el.querySelector('[name=adpop_phone]').focus();return;}
  var data={
    popup_id:   vid,
    session_id: (window.adUTM||{}).sid||'',
    name:       (el.querySelector('[name=adpop_name]')   ||{}).value||'',
    phone:      phone,
    email:      (el.querySelector('[name=adpop_email]')  ||{}).value||'',
    message:    (el.querySelector('[name=adpop_message]')||{}).value||'',
    website:    (el.querySelector('[name=adpop_website]')||{}).value||'',
    _ads_token: (window.adUTM||{}).token||'',
  };
  fetch('<?php echo $rest_lead; ?>',{
    method:'POST',
    headers:{'Content-Type':'application/json','X-ADS-Token':(window.adUTM||{}).token||''},
    body:JSON.stringify(data),
  }).then(function(r){return r.json();}).then(function(d){
    if(d.ok){
      el.querySelector('.adpop-form').style.display='none';
      el.querySelector('.adpop-success').style.display='block';
      setTimeout(function(){adPopClose(vid);},3000);
    }
  });
}
</script>
<?php
}, 60);

// ─── REST: nhận lead ──────────────────────────────────────────────────────────

function adsdefender_lead_spam_check(WP_REST_Request $req): ?WP_REST_Response
{
    global $wpdb;
    $ip    = adsdefender_get_real_ip() ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    $phone = sanitize_text_field($req->get_param('phone') ?? '');
    $t     = $wpdb->prefix . ADSDEFENDER_LEAD_TABLE;

    // 1. Honeypot — bot thường điền trường ẩn này
    if (!empty($req->get_param('website'))) {
        return new WP_REST_Response(['ok' => false, 'error' => 'spam'], 400);
    }

    // 2. Phone bắt buộc
    if (empty($phone)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'phone_required'], 400);
    }

    // 3. Rate limit: cùng IP gửi quá 3 lead trong 10 phút
    $ip_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t} WHERE ip=%s AND created_at >= %s",
        $ip, date('Y-m-d H:i:s', time() - 600)
    ));
    if ($ip_count >= 3) {
        return new WP_REST_Response(['ok' => false, 'error' => 'rate_limit'], 429);
    }

    // 4. Duplicate: cùng số điện thoại trong 30 phút
    $phone_clean = preg_replace('/[^0-9+]/', '', $phone);
    $dup = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t} WHERE phone LIKE %s AND created_at >= %s LIMIT 1",
        '%' . $wpdb->esc_like($phone_clean) . '%',
        date('Y-m-d H:i:s', time() - 1800)
    ));
    if ($dup) {
        return new WP_REST_Response(['ok' => true, 'duplicate' => true, 'id' => (int)$dup]);
    }

    return null;
}

// ─── Email ────────────────────────────────────────────────────────────────────

function adsdefender_lead_send_email(array $lead): void
{
    $cfg         = get_option('adsdefender_lead_notify', []);
    $notify_email = trim($cfg['email'] ?? '');
    if (empty($cfg['enabled']) || empty($notify_email)) return;

    $to      = $notify_email;
    $subject = '[Lead mới] ' . ($lead['name'] ?: $lead['phone']) . ' — ' . get_bloginfo('name');
    $rows    = array_filter([
        'Tên'       => $lead['name'],
        'SĐT'       => $lead['phone'],
        'Email'     => $lead['email'],
        'Tin nhắn'  => $lead['message'],
        'Source'    => $lead['utm_source'],
        'Campaign'  => $lead['utm_campaign'],
        'Landing'   => $lead['landing_url'],
        'IP'        => $lead['ip'],
        'Thời gian' => $lead['created_at'],
    ]);

    $body = "<html><body style='font-family:sans-serif;font-size:14px;color:#333'>";
    $body .= "<h2 style='color:#d63638;margin-bottom:16px'>📥 Lead mới từ " . esc_html(get_bloginfo('name')) . "</h2>";
    $body .= "<table style='border-collapse:collapse;width:100%;max-width:520px'>";
    foreach ($rows as $label => $value) {
        $body .= "<tr><td style='padding:8px 12px;background:#f6f7f7;font-weight:600;width:110px;border-bottom:1px solid #eee'>{$label}</td>"
               . "<td style='padding:8px 12px;border-bottom:1px solid #eee'>" . esc_html($value) . "</td></tr>";
    }
    $body .= "</table>";
    $body .= "<p style='margin-top:16px'><a href='" . esc_url(admin_url('admin.php?page=adsdefender-marketing&tab=leads')) . "' style='background:#2271b1;color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none'>Xem tất cả leads</a></p>";
    $body .= "</body></html>";

    wp_mail($to, $subject, $body, [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
    ]);
}

add_action('rest_api_init', function () {
    register_rest_route('adsdefender/v1', '/lead', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            adsdefender_create_lead_table_if_needed();
            $t = $wpdb->prefix . ADSDEFENDER_LEAD_TABLE;

            $spam = adsdefender_lead_spam_check($req);
            if ($spam !== null) return $spam;

            $sid     = sanitize_text_field($req->get_param('session_id') ?? '');
            $utm_row = $sid ? $wpdb->get_row($wpdb->prepare(
                "SELECT utm_source,utm_medium,utm_campaign,gclid,landing_url FROM {$wpdb->prefix}" . ADSDEFENDER_UTM_TABLE . " WHERE session_id=%s LIMIT 1", $sid
            )) : null;

            $lead = [
                'session_id'   => $sid,
                'name'         => substr(sanitize_text_field($req->get_param('name')         ?? ''), 0, 200),
                'phone'        => substr(sanitize_text_field($req->get_param('phone')        ?? ''), 0, 20),
                'email'        => substr(sanitize_email($req->get_param('email')             ?? ''), 0, 200),
                'message'      => substr(sanitize_textarea_field($req->get_param('message')  ?? ''), 0, 2000),
                'utm_source'   => $utm_row->utm_source   ?? '',
                'utm_medium'   => $utm_row->utm_medium   ?? '',
                'utm_campaign' => $utm_row->utm_campaign ?? '',
                'gclid'        => $utm_row->gclid        ?? '',
                'landing_url'  => $utm_row->landing_url  ?? '',
                'popup_id'     => substr(sanitize_key($req->get_param('popup_id') ?? ''), 0, 50),
                'ip'           => adsdefender_get_real_ip() ?: ($_SERVER['REMOTE_ADDR'] ?? ''),
                'status'       => 'new',
                'created_at'   => current_time('mysql'),
            ];

            $wpdb->insert($t, $lead);
            $lead_id = $wpdb->insert_id;

            adsdefender_lead_send_email($lead);

            // Telegram
            $tg_cfg = adsdefender_telegram_cfg();
            if (!empty($tg_cfg['enabled']) && !empty($tg_cfg['on_lead'])) {
                $site = get_bloginfo('name');
                $src  = $lead['utm_source'] ? " | <b>{$lead['utm_source']}</b>" : '';
                $msg  = "📥 <b>Lead mới</b> — {$site}{$src}\n"
                      . "👤 " . ($lead['name'] ?: '—') . "\n"
                      . "📞 " . $lead['phone'] . "\n"
                      . ($lead['email'] ? "✉️ {$lead['email']}\n" : '')
                      . ($lead['utm_campaign'] ? "🎯 {$lead['utm_campaign']}\n" : '')
                      . ($lead['message'] ? "💬 " . mb_substr($lead['message'], 0, 100) . "\n" : '');
                adsdefender_telegram_send($msg);
            }

            return new WP_REST_Response(['ok' => true, 'id' => $lead_id]);
        },
        'permission_callback' => 'adsdefender_verify_rest_token',
    ]);
});

// ─── Admin: trang Popup & Lead ────────────────────────────────────────────────

function adsdefender_page_popup_form()
{
    $_GET['adtab'] = 'popups';
    adsdefender_page_popup_inner();
}

function adsdefender_page_leads()
{
    $_GET['adtab'] = 'leads';
    adsdefender_page_popup_inner();
}

function adsdefender_page_popup() { adsdefender_page_popup_inner(); }

function adsdefender_page_popup_inner()
{
    global $wpdb;
    adsdefender_create_lead_table_if_needed();
    $lt     = $wpdb->prefix . ADSDEFENDER_LEAD_TABLE;
    $popups = get_option(ADSDEFENDER_OPTION_POPUP, []);

    if (isset($_POST['adpop_save']) && check_admin_referer('adpop_save')) {
        $pid          = sanitize_key($_POST['adpop_pid'] ?? ('popup_' . time()));
        $popups[$pid] = [
            'enabled'          => !empty($_POST['adpop_enabled'])    ? 1 : 0,
            'title'            => sanitize_text_field($_POST['adpop_title']   ?? ''),
            'sub'              => sanitize_text_field($_POST['adpop_sub']     ?? ''),
            'btn_txt'          => sanitize_text_field($_POST['adpop_btn_txt'] ?? 'Gửi ngay'),
            'btn_col'          => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['adpop_btn_col'] ?? '') ? $_POST['adpop_btn_col'] : '#d63638',
            'bg_col'           => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['adpop_bg_col']  ?? '') ? $_POST['adpop_bg_col']  : '#ffffff',
            'trigger'          => in_array($_POST['adpop_trigger'] ?? '', ['delay','scroll','exit','idle']) ? $_POST['adpop_trigger'] : 'delay',
            'delay'            => max(0, (int)($_POST['adpop_delay'] ?? 5)),
            'scroll_pct'       => max(0, min(100, (int)($_POST['adpop_scroll_pct'] ?? 50))),
            'once_per_session' => !empty($_POST['adpop_once']) ? 1 : 0,
            'success_msg'      => sanitize_text_field($_POST['adpop_success_msg'] ?? ''),
            'image'            => esc_url_raw($_POST['adpop_image'] ?? ''),
            'fields'           => [
                'name'    => !empty($_POST['adpop_field_name'])    ? 1 : 0,
                'phone'   => 1,
                'email'   => !empty($_POST['adpop_field_email'])   ? 1 : 0,
                'message' => !empty($_POST['adpop_field_message']) ? 1 : 0,
            ],
            'ab_enabled'  => !empty($_POST['adpop_ab_enabled']) ? 1 : 0,
            'ab_title'    => sanitize_text_field($_POST['adpop_ab_title']   ?? ''),
            'ab_sub'      => sanitize_text_field($_POST['adpop_ab_sub']     ?? ''),
            'ab_btn_txt'  => sanitize_text_field($_POST['adpop_ab_btn_txt'] ?? ''),
            'ab_btn_col'  => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['adpop_ab_btn_col'] ?? '') ? $_POST['adpop_ab_btn_col'] : '#d63638',
            'ab_image'    => esc_url_raw($_POST['adpop_ab_image'] ?? ''),
        ];
        update_option(ADSDEFENDER_OPTION_POPUP, $popups, false);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu popup.</p></div>';
    }

    if (isset($_GET['adpop_delete']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'adpop_delete_' . $_GET['adpop_delete'])) {
        $pid = sanitize_key($_GET['adpop_delete']);
        unset($popups[$pid]);
        update_option(ADSDEFENDER_OPTION_POPUP, $popups, false);
        echo '<div class="notice notice-success is-dismissible"><p>Đã xóa popup.</p></div>';
    }

    if (isset($_GET['lead_status']) && isset($_GET['lead_id']) && check_admin_referer('adlead_status')) {
        $wpdb->update($lt, ['status' => sanitize_key($_GET['lead_status'])], ['id' => (int)$_GET['lead_id']], ['%s'], ['%d']);
    }

    // Lưu ghi chú
    if (isset($_POST['adlead_note_save']) && check_admin_referer('adlead_note')) {
        $note_id = (int)($_POST['lead_note_id'] ?? 0);
        $note    = substr(sanitize_textarea_field($_POST['lead_note'] ?? ''), 0, 500);
        if ($note_id) {
            $wpdb->query($wpdb->prepare("UPDATE `{$lt}` SET note=%s WHERE id=%d", $note, $note_id));
        }
    }

    // Export CSV
    if (isset($_GET['adlead_export']) && check_admin_referer('adlead_export')) {
        $filter_s = sanitize_key($_GET['filter_status'] ?? '');
        $where    = $filter_s ? $wpdb->prepare("WHERE status=%s", $filter_s) : '';
        $rows     = $wpdb->get_results("SELECT * FROM `{$lt}` {$where} ORDER BY created_at DESC", ARRAY_A);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8 cho Excel
        fputcsv($out, ['ID','Thời gian','Tên','SĐT','Email','Tin nhắn','Source','Campaign','Landing','Popup','Status','Ghi chú','IP']);
        foreach ($rows as $r) {
            fputcsv($out, array_map('adsdefender_csv_safe', [$r['id'],$r['created_at'],$r['name'],$r['phone'],$r['email'],$r['message'],$r['utm_source'],$r['utm_campaign'],$r['landing_url'],$r['popup_id'],$r['status'],$r['note']??'',$r['ip']]));
        }
        fclose($out);
        exit;
    }

    if (isset($_POST['adlead_clear']) && check_admin_referer('adlead_clear')) {
        $wpdb->query("TRUNCATE TABLE {$lt}");
        echo '<div class="notice notice-success is-dismissible"><p>Đã xóa toàn bộ leads.</p></div>';
    }

    if (isset($_POST['adlead_notify_save']) && check_admin_referer('adlead_notify')) {
        update_option('adsdefender_lead_notify', [
            'enabled' => !empty($_POST['notify_enabled']) ? 1 : 0,
            'email'   => sanitize_email(trim($_POST['notify_email'] ?? '')),
        ], false);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu cấu hình thông báo.</p></div>';
    }

    $editing      = sanitize_key($_GET['edit'] ?? '');
    $edit_data    = $editing && isset($popups[$editing]) ? $popups[$editing] : null;
    $filter_s     = sanitize_key($_GET['filter_status'] ?? '');
    $total_leads  = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$lt}`");
    $new_leads    = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$lt} WHERE status=%s", 'new'));
    $where_filter = $filter_s ? $wpdb->prepare("WHERE status=%s", $filter_s) : '';
    $leads        = $wpdb->get_results("SELECT * FROM `{$lt}` {$where_filter} ORDER BY created_at DESC LIMIT 200");
    $tab          = sanitize_key($_GET['adtab'] ?? 'popups');
    ?>
<?php if ($tab === 'popups'): ?>

<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px 24px;margin-bottom:24px;max-width:700px">
<h2 style="margin-top:0"><?php echo $edit_data ? '✏️ Sửa popup: ' . esc_html($editing) : '➕ Tạo popup mới'; ?></h2>
<form method="post">
<?php wp_nonce_field('adpop_save'); ?>
<input type="hidden" name="adpop_pid" value="<?php echo esc_attr($editing ?: 'popup_' . time()); ?>">
<table class="form-table" style="margin:0">
<tr><th style="width:160px">Bật popup</th>
  <td><label><input type="checkbox" name="adpop_enabled" value="1" <?php checked($edit_data['enabled'] ?? 1, 1); ?>> Hiển thị trên site</label></td></tr>
<tr><th>Tiêu đề</th>
  <td><input type="text" name="adpop_title" value="<?php echo esc_attr($edit_data['title'] ?? 'Nhận tư vấn miễn phí!'); ?>" class="large-text" placeholder="Nhận tư vấn miễn phí!"></td></tr>
<tr><th>Mô tả phụ</th>
  <td><input type="text" name="adpop_sub" value="<?php echo esc_attr($edit_data['sub'] ?? 'Điền thông tin, chúng tôi liên hệ ngay!'); ?>" class="large-text"></td></tr>
<tr><th>Text nút</th>
  <td><input type="text" name="adpop_btn_txt" value="<?php echo esc_attr($edit_data['btn_txt'] ?? 'Gửi ngay'); ?>" class="regular-text"></td></tr>
<tr><th>Màu nút / nền</th>
  <td style="display:flex;align-items:center;gap:16px;padding-top:8px">
    <label>Nút: <input type="color" name="adpop_btn_col" value="<?php echo esc_attr($edit_data['btn_col'] ?? '#d63638'); ?>" style="width:60px;height:32px;border:1px solid #ddd;border-radius:4px;padding:2px;cursor:pointer"></label>
    <label>Nền: <input type="color" name="adpop_bg_col"  value="<?php echo esc_attr($edit_data['bg_col']  ?? '#ffffff'); ?>" style="width:60px;height:32px;border:1px solid #ddd;border-radius:4px;padding:2px;cursor:pointer"></label>
  </td></tr>
<tr><th>Ảnh banner</th>
  <td><input type="url" name="adpop_image" value="<?php echo esc_attr($edit_data['image'] ?? ''); ?>" class="large-text" placeholder="https://... (để trống nếu không có)"></td></tr>
<tr><th>Trigger hiển thị</th>
  <td>
    <select name="adpop_trigger" id="adpop_trigger" onchange="adpopTrigger(this.value)">
      <option value="delay"  <?php selected($edit_data['trigger'] ?? 'delay', 'delay'); ?>>⏱ Sau N giây</option>
      <option value="scroll" <?php selected($edit_data['trigger'] ?? '', 'scroll'); ?>>📜 Scroll đến X%</option>
      <option value="exit"   <?php selected($edit_data['trigger'] ?? '', 'exit'); ?>>🚪 Exit Intent</option>
      <option value="idle"   <?php selected($edit_data['trigger'] ?? '', 'idle'); ?>>😴 Idle N giây</option>
    </select>
    <span id="adpop_delay_wrap" style="margin-left:10px">
      <input type="number" name="adpop_delay" value="<?php echo (int)($edit_data['delay'] ?? 5); ?>" min="0" max="300" style="width:70px"> giây
    </span>
    <span id="adpop_scroll_wrap" style="margin-left:10px;display:none">
      Khi scroll đến <input type="number" name="adpop_scroll_pct" value="<?php echo (int)($edit_data['scroll_pct'] ?? 50); ?>" min="1" max="100" style="width:60px">%
    </span>
  </td></tr>
<tr><th>Trường thu thập</th>
  <td>
    <label style="margin-right:12px"><input type="checkbox" name="adpop_field_name" value="1" <?php checked($edit_data['fields']['name'] ?? 1, 1); ?>> Họ tên</label>
    <label style="margin-right:12px"><input type="checkbox" disabled checked> Số điện thoại <small>(bắt buộc)</small></label>
    <label style="margin-right:12px"><input type="checkbox" name="adpop_field_email" value="1" <?php checked($edit_data['fields']['email'] ?? 0, 1); ?>> Email</label>
    <label><input type="checkbox" name="adpop_field_message" value="1" <?php checked($edit_data['fields']['message'] ?? 0, 1); ?>> Yêu cầu</label>
  </td></tr>
<tr><th>Thông báo thành công</th>
  <td><input type="text" name="adpop_success_msg" value="<?php echo esc_attr($edit_data['success_msg'] ?? 'Cảm ơn! Chúng tôi sẽ liên hệ với bạn sớm nhất.'); ?>" class="large-text"></td></tr>
<tr><th>Hiện 1 lần/session</th>
  <td><label><input type="checkbox" name="adpop_once" value="1" <?php checked($edit_data['once_per_session'] ?? 1, 1); ?>> Không hiện lại nếu đã đóng trong session này</label></td></tr>
<tr><td colspan="2"><hr style="margin:8px 0"><strong>🧪 A/B Test</strong> <span style="font-size:12px;color:#666">— chia traffic 50/50, so sánh conversion rate</span></td></tr>
<tr><th>Bật A/B Test</th>
  <td><label><input type="checkbox" name="adpop_ab_enabled" value="1" id="adpop_ab_toggle"
    <?php checked($edit_data['ab_enabled'] ?? 0, 1); ?> onchange="document.getElementById('adpop_ab_rows').style.display=this.checked?'':'none'">
    Kích hoạt variant B</label></td></tr>
</table>
<table class="form-table" id="adpop_ab_rows" style="margin:0;<?php echo empty($edit_data['ab_enabled']) ? 'display:none' : ''; ?>">
<tr><th style="width:160px">B — Tiêu đề</th>
  <td><input type="text" name="adpop_ab_title" value="<?php echo esc_attr($edit_data['ab_title'] ?? ''); ?>" class="large-text" placeholder="Để trống = dùng giống variant A"></td></tr>
<tr><th>B — Mô tả phụ</th>
  <td><input type="text" name="adpop_ab_sub" value="<?php echo esc_attr($edit_data['ab_sub'] ?? ''); ?>" class="large-text" placeholder="Để trống = giống A"></td></tr>
<tr><th>B — Text nút</th>
  <td><input type="text" name="adpop_ab_btn_txt" value="<?php echo esc_attr($edit_data['ab_btn_txt'] ?? ''); ?>" class="regular-text" placeholder="Để trống = giống A"></td></tr>
<tr><th>B — Màu nút</th>
  <td><input type="color" name="adpop_ab_btn_col" value="<?php echo esc_attr($edit_data['ab_btn_col'] ?? '#d63638'); ?>" style="width:60px;height:32px;border:1px solid #ddd;border-radius:4px;padding:2px;cursor:pointer"></td></tr>
<tr><th>B — Ảnh banner</th>
  <td><input type="url" name="adpop_ab_image" value="<?php echo esc_attr($edit_data['ab_image'] ?? ''); ?>" class="large-text" placeholder="https://... (để trống = giống A)"></td></tr>
</table>
<table class="form-table" style="margin:0">
<div style="margin-top:16px;display:flex;gap:8px">
  <button type="submit" name="adpop_save" class="button button-primary">💾 Lưu popup</button>
  <?php if ($editing): ?><a href="<?php echo esc_url(remove_query_arg('edit')); ?>" class="button">+ Tạo mới</a><?php endif; ?>
</div>
</form>
</div>

<?php if (!empty($popups)): ?>
<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;max-width:700px">
<h3 style="margin-top:0">Popups hiện có</h3>
<table class="widefat striped" style="font-size:13px">
  <thead><tr><th>ID</th><th>Tiêu đề</th><th>Trigger</th><th>Trạng thái</th><th>Hành động</th></tr></thead>
  <tbody>
  <?php $trig_lbl=['delay'=>'⏱ Delay','scroll'=>'📜 Scroll','exit'=>'🚪 Exit','idle'=>'😴 Idle'];
  foreach ($popups as $pid => $p): ?>
  <tr>
    <td><code><?php echo esc_html($pid); ?></code></td>
    <td><?php echo esc_html($p['title'] ?? ''); ?></td>
    <td><?php echo $trig_lbl[$p['trigger']??'delay'] ?? ''; ?></td>
    <td><?php echo !empty($p['enabled']) ? '<span style="color:#00a32a">✅ Bật</span>' : '<span style="color:#999">⏸ Tắt</span>'; ?></td>
    <td>
      <a href="<?php echo esc_url(add_query_arg('edit', $pid)); ?>" class="button button-small">Sửa</a>
      <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('adpop_delete', $pid), 'adpop_delete_'.$pid)); ?>"
         class="button button-small" style="color:#d63638"
         onclick="return confirm('Xóa popup này?')">Xóa</a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<script>
function adpopTrigger(v){
  document.getElementById('adpop_delay_wrap').style.display  = (v==='delay'||v==='idle') ?'inline':'none';
  document.getElementById('adpop_scroll_wrap').style.display = v==='scroll'?'inline':'none';
}
adpopTrigger(document.getElementById('adpop_trigger').value);
</script>

<?php elseif ($tab === 'leads'): ?>

<?php
$notify_cfg   = get_option('adsdefender_lead_notify', []);
$notify_on    = !empty($notify_cfg['enabled']);
$notify_email = $notify_cfg['email'] ?? get_option('admin_email', '');
?>
<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;margin-bottom:20px;max-width:560px">
  <h3 style="margin-top:0">📧 Thông báo email khi có lead mới</h3>
  <form method="post">
    <?php wp_nonce_field('adlead_notify'); ?>
    <table class="form-table" style="margin:0">
      <tr>
        <th style="width:120px;padding:6px 0">Bật thông báo</th>
        <td style="padding:6px 0">
          <label><input type="checkbox" name="notify_enabled" value="1" <?php checked($notify_on); ?>>
          Gửi email mỗi khi có lead mới</label>
        </td>
      </tr>
      <tr>
        <th style="padding:6px 0">Email nhận</th>
        <td style="padding:6px 0">
          <input type="email" name="notify_email" value="<?php echo esc_attr($notify_email); ?>"
            class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
          <p class="description">Mặc định dùng email admin WordPress nếu để trống</p>
        </td>
      </tr>
    </table>
    <div style="margin-top:10px;display:flex;align-items:center;gap:12px">
      <button type="submit" name="adlead_notify_save" class="button button-primary">💾 Lưu</button>
      <?php if ($notify_on): ?>
        <span style="color:#00a32a;font-size:13px">✅ Đang bật — gửi về <strong><?php echo esc_html($notify_email); ?></strong></span>
      <?php else: ?>
        <span style="color:#999;font-size:13px">⭕ Đang tắt</span>
      <?php endif; ?>
    </div>
  </form>
  <details style="margin-top:12px">
    <summary style="font-size:12px;color:#666;cursor:pointer">🛡 Chống spam đang bật</summary>
    <ul style="font-size:12px;color:#555;margin:8px 0 0 16px;line-height:1.8">
      <li><strong>Honeypot</strong> — field ẩn, bot điền vào → reject</li>
      <li><strong>Rate limit</strong> — cùng IP gửi quá 3 lần / 10 phút → chặn</li>
      <li><strong>Duplicate</strong> — cùng SĐT trong 30 phút → bỏ qua, không gửi email lại</li>
      <li><strong>Phone bắt buộc</strong> — không có số → reject</li>
    </ul>
  </details>
</div>

<?php
$sl = ['new'=>'🔴 Mới','contacted'=>'🟡 Đã liên hệ','converted'=>'🟢 Chuyển đổi','spam'=>'⚫ Spam'];
$count_by_status = [];
foreach ($sl as $sv => $_) {
    $count_by_status[$sv] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$lt}` WHERE status=%s", $sv));
}
?>
<!-- Stats -->
<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
  <?php
  $stat_cards = [['Tổng leads',$total_leads,'#2271b1',''],['Leads mới',$new_leads,'#d63638','new']];
  foreach ($sl as $sv => $sl_txt) {
      $stat_cards[] = [$sl_txt, $count_by_status[$sv], $sv==='new'?'#d63638':($sv==='converted'?'#00a32a':'#888'), $sv];
  }
  foreach ([['Tổng leads',$total_leads,'#2271b1',''],['Leads mới',$new_leads,'#d63638','new']] as [$l,$v,$c,$fs]): ?>
  <a href="<?php echo esc_url(add_query_arg(['adtab'=>'leads','filter_status'=>$fs])); ?>" style="text-decoration:none">
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 18px;border-top:3px solid <?php echo $c; ?>;min-width:110px">
      <div style="font-size:20px;font-weight:700;color:<?php echo $c; ?>"><?php echo $v; ?></div>
      <div style="font-size:11px;color:#666;margin-top:2px"><?php echo $l; ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filter + Export bar -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
  <strong style="font-size:13px">Lọc:</strong>
  <?php
  $all_filters = ['' => '🔵 Tất cả'] + $sl;
  foreach ($all_filters as $fv => $fl): ?>
  <a href="<?php echo esc_url(add_query_arg(['adtab'=>'leads','filter_status'=>$fv])); ?>"
     class="button button-small <?php echo $filter_s===$fv?'button-primary':''; ?>"><?php echo $fl; ?></a>
  <?php endforeach; ?>
  <span style="flex:1"></span>
  <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['adtab'=>'leads','adlead_export'=>1,'filter_status'=>$filter_s]), 'adlead_export')); ?>"
     class="button" style="background:#2271b1;color:#fff;border-color:#2271b1">⬇ Export CSV</a>
</div>

<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-bottom:16px">
<div style="overflow-x:auto">
<table class="widefat striped" style="font-size:13px;min-width:1000px">
  <thead><tr><th>Thời gian</th><th>Tên</th><th>SĐT</th><th>Email</th><th>Source</th><th>Campaign</th><th>Popup</th><th>Trạng thái</th><th>Ghi chú</th><th>IP</th></tr></thead>
  <tbody>
  <?php if (empty($leads)): ?>
  <tr><td colspan="10" style="text-align:center;color:#999;padding:24px">
    <?php echo $filter_s ? 'Không có lead nào với trạng thái này.' : 'Chưa có lead nào. Tạo popup và bật lên!'; ?>
  </td></tr>
  <?php else: foreach ($leads as $lead): ?>
  <tr style="<?php echo $lead->status==='new'?'background:#fff9f0':''; ?>">
    <td style="white-space:nowrap"><?php echo esc_html(substr($lead->created_at,0,16)); ?></td>
    <td><strong><?php echo esc_html($lead->name ?: '—'); ?></strong></td>
    <td><a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/','',$lead->phone)); ?>"><strong><?php echo esc_html($lead->phone); ?></strong></a></td>
    <td><?php echo $lead->email ? '<a href="mailto:'.esc_attr($lead->email).'">'.esc_html($lead->email).'</a>' : '—'; ?></td>
    <td><span style="background:#eef;padding:2px 6px;border-radius:3px;font-size:11px"><?php echo esc_html($lead->utm_source ?: 'direct'); ?></span></td>
    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#666" title="<?php echo esc_attr($lead->utm_campaign); ?>"><?php echo esc_html($lead->utm_campaign ?: '—'); ?></td>
    <td style="font-size:11px;color:#888"><?php echo esc_html($lead->popup_id ?: '—'); ?></td>
    <td><?php
      echo '<select onchange="adLeadStatus(this,'.(int)$lead->id.')" style="font-size:12px">';
      foreach ($sl as $sv => $sl_txt) echo '<option value="'.$sv.'" '.selected($lead->status,$sv,false).'>'.esc_html($sl_txt).'</option>';
      echo '</select>';
    ?></td>
    <td style="min-width:140px">
      <div class="adlead-note-wrap" data-id="<?php echo (int)$lead->id; ?>">
        <span class="adlead-note-txt" style="font-size:11px;color:#555;cursor:pointer" title="Click để sửa"
          onclick="adNoteEdit(<?php echo (int)$lead->id; ?>)"><?php echo esc_html($lead->note ?? '') ?: '<span style="color:#bbb">+ Ghi chú</span>'; ?></span>
        <div class="adlead-note-form" style="display:none">
          <form method="post" style="display:flex;flex-direction:column;gap:4px">
            <?php wp_nonce_field('adlead_note'); ?>
            <input type="hidden" name="lead_note_id" value="<?php echo (int)$lead->id; ?>">
            <textarea name="lead_note" rows="2" style="font-size:11px;width:140px;resize:vertical"><?php echo esc_textarea($lead->note ?? ''); ?></textarea>
            <div style="display:flex;gap:4px">
              <button type="submit" name="adlead_note_save" class="button button-small button-primary" style="font-size:11px">Lưu</button>
              <button type="button" class="button button-small" style="font-size:11px" onclick="adNoteCancel(<?php echo (int)$lead->id; ?>)">✕</button>
            </div>
          </form>
        </div>
      </div>
    </td>
    <td style="font-size:11px;color:#888"><?php echo esc_html($lead->ip); ?></td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>
</div>

<div style="display:flex;align-items:center;gap:12px">
<form method="post" style="display:inline-block">
  <?php wp_nonce_field('adlead_clear'); ?>
  <button type="submit" name="adlead_clear" class="button button-secondary"
    onclick="return confirm('Xóa toàn bộ leads?')">🗑 Xóa tất cả leads</button>
</form>
<span style="color:#999;font-size:12px">Hiển thị tối đa 200 bản ghi mới nhất<?php echo $filter_s ? " (đang lọc: {$sl[$filter_s]})" : ''; ?></span>
</div>

<script>
function adLeadStatus(sel, id){
  var nonce='<?php echo wp_create_nonce('adlead_status'); ?>';
  var url='<?php echo esc_url(admin_url('admin.php?page=adsdefender-marketing&tab=leads')); ?>'
    +'&adtab=leads&lead_id='+id+'&lead_status='+sel.value+'&_wpnonce='+nonce;
  window.location=url;
}
function adNoteEdit(id){
  var wrap=document.querySelector('.adlead-note-wrap[data-id="'+id+'"]');
  wrap.querySelector('.adlead-note-txt').style.display='none';
  wrap.querySelector('.adlead-note-form').style.display='block';
}
function adNoteCancel(id){
  var wrap=document.querySelector('.adlead-note-wrap[data-id="'+id+'"]');
  wrap.querySelector('.adlead-note-txt').style.display='';
  wrap.querySelector('.adlead-note-form').style.display='none';
}
</script>

<?php
// A/B Test Report
$ab_popups = array_filter($popups, fn($p) => !empty($p['ab_enabled']));
if (!empty($ab_popups)):
?>
<hr style="margin:24px 0">
<h3>🧪 A/B Test Report</h3>
<div style="overflow-x:auto">
<table class="widefat" style="font-size:13px;max-width:700px">
  <thead><tr><th>Popup</th><th>Variant</th><th>Leads</th><th>Conversion %</th><th>Winner</th></tr></thead>
  <tbody>
  <?php foreach ($ab_popups as $apid => $ap):
    $cnt_a = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$lt}` WHERE popup_id=%s", $apid.'_a'));
    $cnt_b = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$lt}` WHERE popup_id=%s", $apid.'_b'));
    $total_ab = $cnt_a + $cnt_b;
    $pct_a = $total_ab > 0 ? round($cnt_a / $total_ab * 100, 1) : 0;
    $pct_b = $total_ab > 0 ? round($cnt_b / $total_ab * 100, 1) : 0;
    $winner = $cnt_a > $cnt_b ? 'A' : ($cnt_b > $cnt_a ? 'B' : '—');
    ?>
    <tr>
      <td rowspan="2" style="vertical-align:middle"><strong><?php echo esc_html($ap['title'] ?? $apid); ?></strong><br><code style="font-size:10px"><?php echo esc_html($apid); ?></code></td>
      <td>Variant A <small style="color:#666">(gốc)</small></td>
      <td><?php echo $cnt_a; ?></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div style="width:80px;background:#eee;border-radius:4px;height:8px">
            <div style="width:<?php echo $pct_a; ?>%;background:#2271b1;height:8px;border-radius:4px"></div>
          </div>
          <?php echo $pct_a; ?>%
        </div>
      </td>
      <td rowspan="2" style="vertical-align:middle;text-align:center;font-size:20px">
        <?php echo $winner === 'A' ? '🏆 A' : ($winner === 'B' ? '🏆 B' : '🤝 Hòa'); ?>
      </td>
    </tr>
    <tr style="background:#fafafa">
      <td>Variant B <small style="color:#666">(test)</small></td>
      <td><?php echo $cnt_b; ?></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div style="width:80px;background:#eee;border-radius:4px;height:8px">
            <div style="width:<?php echo $pct_b; ?>%;background:#d63638;height:8px;border-radius:4px"></div>
          </div>
          <?php echo $pct_b; ?>%
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php endif; ?>
<?php
}
