<?php
if (!defined('ABSPATH')) exit;

function adsdefender_get_contact_bar(): array
{
    return get_option(ADSDEFENDER_OPTION_CONTACT, [
        'enabled'        => 0,
        'position'       => 'bottom',
        'buttons'        => [],
        'mobile_only'    => 0,
        'hide_on_pages'  => '',
    ]);
}

/**
 * Kiểm tra trang hiện tại có nằm trong danh sách ẩn không.
 * Nhận mỗi dòng một mục: ID bài/trang, slug, hoặc đường dẫn (/lien-he, /shop/*).
 */
function adsdefender_contact_is_hidden(string $rules): bool
{
    $rules = trim($rules);
    if ($rules === '') return false;

    $post_id = is_singular() ? (int) get_queried_object_id() : 0;
    $slug    = $post_id ? (string) get_post_field('post_name', $post_id) : '';
    $path    = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');

    foreach (preg_split('/[\r\n,]+/', $rules) as $rule) {
        $rule = trim($rule);
        if ($rule === '') continue;

        if (ctype_digit($rule)) {
            if ($post_id && $post_id === (int) $rule) return true;
            continue;
        }

        if ($rule[0] === '/') {
            $pat = '#^' . str_replace('\*', '.*', preg_quote(rtrim($rule, '/') ?: '/', '#')) . '/?$#i';
            if (preg_match($pat, $path)) return true;
            continue;
        }

        if ($slug !== '' && strcasecmp($slug, ltrim($rule, '/')) === 0) return true;
    }
    return false;
}

add_action('wp_footer', function () {
    if (is_admin()) return;
    $cfg     = adsdefender_get_contact_bar();
    if (empty($cfg['enabled']) || empty($cfg['buttons'])) return;
    if (adsdefender_contact_is_hidden((string) ($cfg['hide_on_pages'] ?? ''))) return;
    $buttons = array_filter($cfg['buttons'], fn($b) => !empty($b['active']) && !empty($b['value']));
    if (empty($buttons)) return;
    echo adsdefender_render_contact_bar($cfg, array_values($buttons));
}, 50);

function adsdefender_contact_url(string $type, string $value): string
{
    $value = trim($value);
    switch ($type) {
        case 'phone':     return 'tel:' . preg_replace('/[^0-9+]/', '', $value);
        case 'zalo':      return 'https://zalo.me/' . preg_replace('/[^0-9]/', '', $value);
        case 'messenger': return strpos($value, 'http') === 0 ? esc_url_raw($value) : 'https://m.me/' . ltrim($value, '/');
        case 'whatsapp':  return 'https://wa.me/' . preg_replace('/[^0-9]/', '', $value);
        case 'viber':     return 'viber://chat?number=' . urlencode(preg_replace('/[^0-9+]/', '', $value));
        case 'telegram':  return 'https://t.me/' . ltrim($value, '@/');
        case 'tiktok':    return 'https://tiktok.com/@' . ltrim($value, '@');
        case 'email':     return 'mailto:' . $value;
        case 'maps':      return strpos($value, 'http') === 0 ? esc_url_raw($value) : 'https://maps.google.com/?q=' . urlencode($value);
        case 'custom':    return esc_url_raw($value);
        default:          return '#';
    }
}

function adsdefender_contact_defaults(): array
{
    return [
        'phone'     => ['label' => 'Gọi ngay',  'color' => '#e8192c', 'icon' => 'phone'],
        'zalo'      => ['label' => 'Chat Zalo',  'color' => '#0068ff', 'icon' => 'zalo'],
        'messenger' => ['label' => 'Messenger',  'color' => '#0084ff', 'icon' => 'messenger'],
        'whatsapp'  => ['label' => 'WhatsApp',   'color' => '#25d366', 'icon' => 'whatsapp'],
        'viber'     => ['label' => 'Viber',      'color' => '#7360f2', 'icon' => 'viber'],
        'telegram'  => ['label' => 'Telegram',   'color' => '#2ca5e0', 'icon' => 'telegram'],
        'tiktok'    => ['label' => 'TikTok',     'color' => '#010101', 'icon' => 'tiktok'],
        'email'     => ['label' => 'Email',      'color' => '#ea4335', 'icon' => 'email'],
        'maps'      => ['label' => 'Bản đồ',     'color' => '#4285f4', 'icon' => 'maps'],
        'custom'    => ['label' => 'Custom',     'color' => '#ff6b35', 'icon' => 'custom'],
    ];
}

function adsdefender_contact_svg(string $icon): string
{
    $icons = [
        'phone'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1L6.6 10.8z"/></svg>',
        'zalo'      => '<svg viewBox="0 0 48 48" fill="currentColor"><path d="M24 5C12.4 5 3 13.2 3 23.3c0 5.7 3 10.8 7.8 14.2-.3 1.6-1.2 4.2-2.6 6-.4.5 0 1.2.7 1.1 3.9-.6 7-2.2 8.9-3.5 2 .5 4.1.8 6.2.8 11.6 0 21-8.2 21-18.3S35.6 5 24 5z"/><path fill="#fff" d="M14.6 17.4h9.7v2.2l-6.6 7.9h6.8v2.3H14v-2.2l6.6-7.9h-6zm12.3 0h2.4v12.4h-2.4zm8.6 3.3c2.6 0 4.7 2.1 4.7 4.7s-2.1 4.7-4.7 4.7-4.7-2.1-4.7-4.7 2.1-4.7 4.7-4.7zm0 2.2a2.5 2.5 0 100 5 2.5 2.5 0 000-5z"/></svg>',
        'messenger' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.259c0 2.88 1.397 5.451 3.584 7.151V22l3.39-1.86C10.079 20.372 11.02 20.519 12 20.519c5.523 0 10-4.145 10-9.26C22 6.145 17.523 2 12 2zm1.009 12.467l-2.552-2.72-4.98 2.72 5.477-5.812 2.614 2.72 4.918-2.72-5.477 5.812z"/></svg>',
        'whatsapp'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>',
        'viber'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.4 1.9C8.8 2 6.2 2.9 4.2 4.6 2.3 6.3 1.1 8.7 1 11.2c-.1 2 .4 4 1.5 5.7v3.4c0 .4.4.7.7.6l3.2-.9c1.5.7 3.1 1.1 4.7 1.1h.5c2.4-.1 4.7-1 6.4-2.8 1.8-1.7 2.8-4.1 2.9-6.5.1-2.5-.8-5-2.6-6.8-1.7-1.8-4.1-2.9-6.9-3.1zM8.1 7.1c.2 0 .4.1.5.2l1.4 1.7c.2.3.2.7 0 1l-.7.8c.4.8 1.5 2 2.4 2.5l.9-.7c.3-.2.7-.2 1 0l1.7 1.3c.3.2.3.6.1.9l-.5.7c-.4.5-.8.8-1.4.9-.8.1-2.4-.3-4.5-2.5-1.8-1.8-2.3-3.3-2.2-4.1.1-.5.4-.9.8-1.3l.6-.5c.1 0 .2-.1.3-.1h-.4z"/></svg>',
        'telegram'  => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.96 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
        'tiktok'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.69a8.18 8.18 0 004.79 1.54V6.79a4.85 4.85 0 01-1.02-.1z"/></svg>',
        'email'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
        'maps'      => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
        'custom'    => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>',
    ];
    return $icons[$icon] ?? $icons['custom'];
}

function adsdefender_render_contact_bar(array $cfg, array $buttons): string
{
    $pos     = $cfg['position'] ?? 'bottom';
    $mob_only = !empty($cfg['mobile_only']);
    $uid     = 'adcb';
    $tr      = adsdefender_get_tracking_settings();
    $has_gtm = !empty($tr['gtm_id']);

    $js_events = [];
    foreach ($buttons as $idx => $btn) {
        $conv = $btn['conversion'] ?? [];
        if (empty($conv['enabled'])) continue;
        $js_events[$idx] = [
            'event'     => $conv['event_name'] ?? 'contact_click',
            'category'  => $conv['category']   ?? ($btn['type'] ?? 'contact'),
            'label'     => $conv['label']        ?? ($btn['label'] ?? $btn['type'] ?? ''),
            'ads_id'    => $conv['ads_id']       ?? '',
            'ads_label' => $conv['ads_label']    ?? '',
            'sg_event'  => $conv['sg_event']     ?? '',
        ];
    }

    ob_start(); ?>
<style id="<?php echo $uid; ?>-css">
<?php $tip_side = $pos === 'left' ? 'left:66px' : 'right:66px'; ?>
@keyframes <?php echo $uid; ?>-pulse{0%,100%{box-shadow:0 0 0 0 rgba(255,255,255,.5)}60%{box-shadow:0 0 0 10px rgba(255,255,255,0)}}
.<?php echo $uid; ?>-wrap{position:fixed;z-index:99999;<?php
    if ($pos === 'bottom')   echo 'bottom:0;left:0;right:0;';
    elseif ($pos === 'left') echo 'left:0;top:50%;transform:translateY(-50%);';
    else                     echo 'right:0;top:50%;transform:translateY(-50%);';
?>}
<?php if ($mob_only): ?>.<?php echo $uid; ?>-wrap{display:none}@media(max-width:768px){.<?php echo $uid; ?>-wrap{display:flex}}<?php else: ?>.<?php echo $uid; ?>-wrap{display:flex}<?php endif; ?>
<?php if ($pos === 'bottom'): ?>
.<?php echo $uid; ?>-wrap{flex-direction:row;box-shadow:0 -2px 16px rgba(0,0,0,.18);border-top:1px solid rgba(255,255,255,.08)}
.<?php echo $uid; ?>-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:0;height:54px;color:#fff!important;text-decoration:none!important;font-size:12.5px;font-weight:700;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;transition:filter .15s,transform .15s;line-height:1;letter-spacing:.2px;position:relative;overflow:hidden}
.<?php echo $uid; ?>-btn::after{content:'';position:absolute;inset:0;background:rgba(255,255,255,0);transition:background .15s;pointer-events:none}
.<?php echo $uid; ?>-btn:hover::after{background:rgba(255,255,255,.12)}
.<?php echo $uid; ?>-btn:active::after{background:rgba(0,0,0,.1)}
.<?php echo $uid; ?>-btn:first-child{border-radius:0}
.<?php echo $uid; ?>-btn svg{width:20px;height:20px;flex-shrink:0;filter:drop-shadow(0 1px 2px rgba(0,0,0,.25));pointer-events:none}
.<?php echo $uid; ?>-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-shadow:0 1px 2px rgba(0,0,0,.3);pointer-events:none}
/* Chừa chỗ để bar không che nội dung cuối trang; env() cộng thêm safe-area iPhone. */
.<?php echo $uid; ?>-wrap{padding-bottom:env(safe-area-inset-bottom,0)}
<?php if ($mob_only): ?>
@media(max-width:768px){body{padding-bottom:calc(54px + env(safe-area-inset-bottom,0px))!important}}
@media(max-width:500px){body{padding-bottom:calc(52px + env(safe-area-inset-bottom,0px))!important}}
<?php else: ?>
body{padding-bottom:calc(54px + env(safe-area-inset-bottom,0px))!important}
@media(max-width:500px){body{padding-bottom:calc(52px + env(safe-area-inset-bottom,0px))!important}}
<?php endif; ?>
@media(max-width:500px){.<?php echo $uid; ?>-label{display:none}.<?php echo $uid; ?>-btn{height:52px}}
<?php else: ?>
.<?php echo $uid; ?>-wrap{flex-direction:column;gap:10px;padding:10px}
.<?php echo $uid; ?>-btn{display:flex;align-items:center;justify-content:center;width:54px;height:54px;border-radius:50%;color:#fff!important;text-decoration:none!important;box-shadow:0 4px 14px rgba(0,0,0,.28),0 1px 4px rgba(0,0,0,.18);transition:transform .18s cubic-bezier(.34,1.56,.64,1),box-shadow .18s;position:relative;overflow:visible}
.<?php echo $uid; ?>-btn:first-child{animation:<?php echo $uid; ?>-pulse 2.2s ease-in-out infinite}
.<?php echo $uid; ?>-btn:hover{transform:scale(1.12);box-shadow:0 8px 24px rgba(0,0,0,.32),0 2px 6px rgba(0,0,0,.18)}
.<?php echo $uid; ?>-btn:active{transform:scale(.96)}
.<?php echo $uid; ?>-btn svg{width:26px;height:26px;filter:drop-shadow(0 1px 2px rgba(0,0,0,.25));pointer-events:none}
.<?php echo $uid; ?>-tip{position:absolute;<?php echo $tip_side; ?>;top:50%;transform:translateY(-50%);background:rgba(30,30,30,.92);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);color:#fff;font-size:12px;font-weight:700;padding:5px 11px;border-radius:6px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .18s,transform .18s;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;letter-spacing:.2px;box-shadow:0 2px 8px rgba(0,0,0,.25)}
.<?php echo $uid; ?>-tip::before{content:'';position:absolute;top:50%;<?php echo $pos === 'left' ? 'right:100%;border-right:none;border-left:5px solid rgba(30,30,30,.92)' : 'left:100%;border-left:none;border-right:5px solid rgba(30,30,30,.92)'; ?>;transform:translateY(-50%);border-top:5px solid transparent;border-bottom:5px solid transparent}
.<?php echo $uid; ?>-btn:hover .<?php echo $uid; ?>-tip{opacity:1;transform:translateY(-50%) translateX(<?php echo $pos === 'left' ? '2px' : '-2px'; ?>)}
<?php endif; ?>
</style>
<div class="<?php echo $uid; ?>-wrap" role="navigation" aria-label="Liên hệ">
<?php foreach ($buttons as $idx => $btn):
    $type   = $btn['type']  ?? 'custom';
    $value  = $btn['value'] ?? '';
    $label  = !empty($btn['label']) ? $btn['label'] : (adsdefender_contact_defaults()[$type]['label'] ?? 'Liên hệ');
    $color  = !empty($btn['color']) ? $btn['color'] : (adsdefender_contact_defaults()[$type]['color'] ?? '#333');
    $url    = adsdefender_contact_url($type, $value);
    $icon   = adsdefender_contact_svg($type);
    $target = in_array($type, ['phone', 'viber', 'email']) ? '_self' : '_blank';
    $rel    = $target === '_blank' ? 'noopener noreferrer' : '';
    $has_ev = isset($js_events[$idx]);
?>
<a href="<?php echo esc_url($url); ?>"
   target="<?php echo $target; ?>"
   <?php if ($rel): ?>rel="<?php echo $rel; ?>"<?php endif; ?>
   class="<?php echo $uid; ?>-btn"
   style="background-color:<?php echo esc_attr($color); ?>"
   title="<?php echo esc_attr($label); ?>"
   aria-label="<?php echo esc_attr($label); ?>"
   <?php if ($has_ev): ?>data-adcb-ev="<?php echo $idx; ?>"<?php endif; ?>>
  <?php echo $icon; ?>
  <?php if ($pos === 'bottom'): ?>
  <span class="<?php echo $uid; ?>-label"><?php echo esc_html($label); ?></span>
  <?php else: ?>
  <span class="<?php echo $uid; ?>-tip"><?php echo esc_html($label); ?></span>
  <?php endif; ?>
</a>
<?php endforeach; ?>
</div>
<?php if (!empty($js_events)): ?>
<script>
(function(){
/* JSON_FORCE_OBJECT: $js_events là mảng thưa (nút không bật conversion bị bỏ qua),
   ép về object để key luôn khớp với data-adcb-ev dù index có nhảy cóc. */
var evMap = <?php echo json_encode($js_events, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT); ?>;
var hasGTM = <?php echo $has_gtm ? 'true' : 'false'; ?>;
document.querySelectorAll('[data-adcb-ev]').forEach(function(el){
  el.addEventListener('click', function(){
   try {
    var idx = el.getAttribute('data-adcb-ev');
    var ev  = evMap[idx];
    if (!ev) return;

    // 1. GTM dataLayer (GA4 sẽ nhận qua GTM tag nếu đã cấu hình)
    if (hasGTM) {
      window.dataLayer = window.dataLayer || [];
      dataLayer.push({
        event:          ev.event,
        event_category: ev.category,
        event_label:    ev.label,
        contact_type:   ev.category,
      });
    }

    // 2. GA4 direct (gtag) — luôn gửi nếu có gtag, kể cả khi dùng GTM
    if (typeof gtag !== 'undefined') {
      gtag('event', ev.event, {
        event_category: ev.category,
        event_label:    ev.label,
      });
    }

    // 3. Google Ads conversion — luôn gửi nếu có ads_id (qua gtag trực tiếp)
    if (ev.ads_id && ev.ads_label && typeof gtag !== 'undefined') {
      gtag('event', 'conversion', {send_to: ev.ads_id + '/' + ev.ads_label});
    }

    // 4. Matomo / TrackSG (_paq) — dùng sg_event nếu admin đã đặt riêng
    if (typeof _paq !== 'undefined') {
      _paq.push(['trackEvent', 'Contact', ev.sg_event || ev.event, ev.label || ev.category]);
    }

    // 5. Meta Pixel
    if (typeof fbq !== 'undefined') {
      fbq('track', 'Contact', {content_category: ev.category, content_name: ev.label || ev.category});
    }
   } catch(err) {/* không để lỗi tracking chặn tel:/zalo: trên mobile */}
  });
});
})();
</script>
<?php endif; ?>
<script>
(function(){
var hasGTM=<?php echo $has_gtm?'true':'false';?>;
function adcbInlineFire(type,label){
  /* Matomo/_paq — luôn track nếu có */
  if(typeof _paq!=='undefined'){
    _paq.push(['trackEvent','Contact','Click',type+':'+label]);
  }
  if(typeof fbq!=='undefined'){
    fbq('track','Contact',{content_category:type,content_name:label,contact_source:'inline'});
  }
  /* GTM dataLayer — nếu anh dùng GTM */
  if(hasGTM){
    window.dataLayer=window.dataLayer||[];
    dataLayer.push({event:'contact_click',event_category:type,event_label:label,contact_type:type,contact_source:'inline'});
  }
}
/* Tự leo cây thay vì e.target.closest(): trên iOS Safari / Android WebView cũ,
   SVGElement không có .closest() — bấm trúng icon sẽ ném TypeError, và vì
   listener chạy ở capture phase, exception sẽ chặn luôn hành vi mặc định
   (tel: không mở được trên mobile). */
function adcbClosestLink(node){
  for(var n=node; n && n!==document; n=n.parentNode||n.parentElement){
    if(n.nodeType===1 && n.tagName && n.tagName.toLowerCase()==='a' && n.hasAttribute('href')) return n;
  }
  return null;
}
function adcbInWrap(node){
  for(var n=node; n && n!==document; n=n.parentNode||n.parentElement){
    if(n.nodeType===1 && n.classList && n.classList.contains('<?php echo $uid; ?>-wrap')) return true;
  }
  return false;
}
document.addEventListener('click',function(e){
  try{
    var a=adcbClosestLink(e.target);
    if(!a||adcbInWrap(a))return;
    var href=a.getAttribute('href')||'';
    if(/^tel:/i.test(href)){
      adcbInlineFire('phone',href.replace(/^tel:/i,'').trim());
    }else if(/zalo\.me\//i.test(href)){
      adcbInlineFire('zalo',(a.textContent||'').trim()||href);
    }
  }catch(err){/* tracking không bao giờ được chặn điều hướng */}
},true);
})();
</script>
<?php
    return ob_get_clean();
}

// ─── Admin: trang Contact Bar ─────────────────────────────────────────────────

function adsdefender_page_contact()
{
    $cfg      = adsdefender_get_contact_bar();
    $defaults = adsdefender_contact_defaults();
    $types    = array_keys($defaults);

    if (isset($_POST['adsdefender_save_contact'])
        && check_admin_referer('adsdefender_contact')
        && current_user_can('manage_options')) {

        $buttons  = [];
        $raw_btns = $_POST['cb_btn'] ?? [];
        if (!is_array($raw_btns)) $raw_btns = [];

        foreach ($raw_btns as $b) {
            if (!is_array($b)) continue;

            $type = sanitize_key($b['type'] ?? 'custom');
            if (!in_array($type, $types, true)) $type = 'custom';

            $value = sanitize_text_field($b['value'] ?? '');
            $active = !empty($b['active']) ? 1 : 0;

            $conv_raw = $b['conversion'] ?? [];
            if (!is_array($conv_raw)) $conv_raw = [];

            // Không lưu nút rỗng và chưa bật — tránh option phình thêm 10 dòng mỗi lần lưu.
            if ($value === '' && !$active) continue;

            $color = (string) ($b['color'] ?? '');
            if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
                $color = $defaults[$type]['color'] ?? '#333';
            }

            $buttons[] = [
                'type'   => $type,
                'value'  => $value,
                'label'  => sanitize_text_field($b['label'] ?? ''),
                'color'  => $color,
                'active' => $active,
                'conversion' => [
                    'enabled'    => !empty($conv_raw['enabled']) ? 1 : 0,
                    'event_name' => sanitize_key($conv_raw['event_name'] ?? 'contact_click'),
                    'category'   => sanitize_text_field($conv_raw['category'] ?? $type),
                    'label'      => sanitize_text_field($conv_raw['label'] ?? ''),
                    'ads_id'     => sanitize_text_field($conv_raw['ads_id'] ?? ''),
                    'ads_label'  => sanitize_text_field($conv_raw['ads_label'] ?? ''),
                    'sg_event'   => sanitize_key($conv_raw['sg_event'] ?? ''),
                ],
            ];
        }

        $cfg = [
            'enabled'       => !empty($_POST['cb_enabled'])     ? 1 : 0,
            'position'      => in_array($_POST['cb_position'] ?? '', ['bottom','left','right'], true) ? $_POST['cb_position'] : 'bottom',
            'mobile_only'   => !empty($_POST['cb_mobile_only']) ? 1 : 0,
            'hide_on_pages' => sanitize_textarea_field(is_string($_POST['cb_hide_on_pages'] ?? '') ? $_POST['cb_hide_on_pages'] : ''),
            'buttons'       => $buttons,
        ];
        update_option(ADSDEFENDER_OPTION_CONTACT, $cfg, false);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu Contact Bar.</p></div>';
    }

    $buttons  = $cfg['buttons'] ?? [];
    $position = $cfg['position'] ?? 'bottom';
    $default_events = [
        'phone'=>'click_phone','zalo'=>'click_zalo','messenger'=>'click_messenger',
        'whatsapp'=>'click_whatsapp','viber'=>'click_viber','telegram'=>'click_telegram',
        'tiktok'=>'click_tiktok','email'=>'click_email','maps'=>'click_maps','custom'=>'contact_click',
    ];
    ?>
<p style="color:#555;margin-bottom:20px">Thanh liên hệ nổi — hiển thị nút gọi điện, Zalo, Messenger... trên toàn site.</p>

<form method="post">
<?php wp_nonce_field('adsdefender_contact'); ?>

<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px 24px;margin-bottom:20px">
<h2 style="margin-top:0">⚙️ Cài đặt chung</h2>
<table class="form-table" style="margin:0">
<tr>
  <th style="width:180px">Hiển thị</th>
  <td><label><input type="checkbox" name="cb_enabled" value="1" <?php checked($cfg['enabled'] ?? 0, 1); ?>>
  Bật Contact Bar</label></td>
</tr>
<tr>
  <th>Vị trí</th>
  <td>
    <label style="margin-right:16px"><input type="radio" name="cb_position" value="bottom" <?php checked($position,'bottom'); ?>> 📌 Thanh ngang dưới cùng</label>
    <label style="margin-right:16px"><input type="radio" name="cb_position" value="left"   <?php checked($position,'left'); ?>>   ◀ Cột bên trái</label>
    <label><input type="radio" name="cb_position" value="right" <?php checked($position,'right'); ?>> ▶ Cột bên phải</label>
  </td>
</tr>
<tr>
  <th>Thiết bị</th>
  <td><label><input type="checkbox" name="cb_mobile_only" value="1" <?php checked($cfg['mobile_only'] ?? 0, 1); ?>>
  Chỉ hiện trên mobile (≤768px)</label></td>
</tr>
<tr>
  <th>Ẩn trên trang</th>
  <td>
    <textarea name="cb_hide_on_pages" rows="4" class="large-text code"
      placeholder="lien-he&#10;/gio-hang&#10;/shop/*&#10;123"><?php echo esc_textarea($cfg['hide_on_pages'] ?? ''); ?></textarea>
    <p class="description" style="margin-top:6px">
      Mỗi dòng một mục — để trống nghĩa là hiện ở mọi trang. Chấp nhận:<br>
      • <code>lien-he</code> — slug bài/trang<br>
      • <code>/gio-hang</code> — đường dẫn (bắt đầu bằng <code>/</code>)<br>
      • <code>/shop/*</code> — đường dẫn có wildcard<br>
      • <code>123</code> — ID bài/trang
    </p>
  </td>
</tr>
</table>
</div>

<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px 24px;margin-bottom:20px">
<h2 style="margin-top:0">📞 Các nút liên hệ</h2>
<p style="color:#666;font-size:13px;margin-bottom:16px">Kéo thả để sắp xếp thứ tự. Bỏ tick để ẩn nút mà không xóa.</p>

<div id="adcb-list" style="display:flex;flex-direction:column;gap:12px;max-width:760px">
<?php
/* Chỉ bổ sung dòng trống cho loại CHƯA có nút nào dùng. Kết hợp với việc
   không lưu nút rỗng-và-tắt ở khối save, danh sách không còn phình mỗi lần lưu. */
$saved_types = array_unique(array_column($buttons, 'type'));
$all_buttons = $buttons;
foreach ($defaults as $type => $def) {
    if (!in_array($type, $saved_types, true)) {
        $all_buttons[] = ['type' => $type, 'value' => '', 'label' => '', 'color' => $def['color'], 'active' => 0];
    }
}
$phs = [
    'phone'=>'VD: 0901234567','zalo'=>'VD: 0901234567',
    'messenger'=>'VD: tenpage hoặc https://m.me/...','whatsapp'=>'VD: 84901234567',
    'viber'=>'VD: +84901234567','telegram'=>'VD: @username','tiktok'=>'VD: @tenaccount',
    'email'=>'VD: info@example.com','maps'=>'VD: địa chỉ hoặc link maps','custom'=>'https://...',
];
$em = ['phone'=>'📞','zalo'=>'🇿','messenger'=>'💬','whatsapp'=>'💚','viber'=>'🟣','telegram'=>'✈️','tiktok'=>'🎵','email'=>'📧','maps'=>'📍','custom'=>'🔗'];

foreach ($all_buttons as $i => $btn):
    $type    = $btn['type']   ?? 'custom';
    $def     = $defaults[$type] ?? ['label' => 'Custom', 'color' => '#333'];
    $label   = $btn['label']  ?? '';
    $color   = $btn['color']  ?? $def['color'];
    $active  = $btn['active'] ?? 0;
    $icon    = adsdefender_contact_svg($type);
    $conv    = $btn['conversion'] ?? [];
    $conv_on = !empty($conv['enabled']);
    $ph      = $phs[$type] ?? '';
    $ev_default = $default_events[$type] ?? 'contact_click';
?>
<div class="adcb-row" style="background:#f8f9fa;border:1px solid #ddd;border-radius:6px;margin-bottom:2px;<?php echo $active ? '' : 'opacity:.55'; ?>">
  <div style="display:grid;grid-template-columns:28px 40px 1fr 1fr 76px 110px 36px 90px;gap:8px;align-items:center;padding:10px 14px">
    <span style="cursor:move;color:#aaa;font-size:18px;user-select:none" title="Kéo để sắp xếp">⠿</span>
    <span style="width:36px;height:36px;border-radius:50%;background:<?php echo esc_attr($color); ?>;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0"><?php echo $icon; ?></span>
    <select name="cb_btn[<?php echo $i; ?>][type]" style="font-size:13px" onchange="adcbTypeChange(this,<?php echo $i; ?>)">
      <?php foreach ($defaults as $t => $d): ?>
      <option value="<?php echo $t; ?>" <?php selected($type, $t); ?>><?php echo ($em[$t] ?? '•') . ' ' . $d['label']; ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="cb_btn[<?php echo $i; ?>][value]"
      value="<?php echo esc_attr($btn['value'] ?? ''); ?>"
      placeholder="<?php echo esc_attr($ph); ?>"
      id="adcb-val-<?php echo $i; ?>"
      style="font-family:monospace;font-size:13px" class="regular-text">
    <input type="color" name="cb_btn[<?php echo $i; ?>][color]"
      value="<?php echo esc_attr($color); ?>"
      style="width:68px;height:34px;border:1px solid #ddd;border-radius:4px;cursor:pointer;padding:2px">
    <input type="text" name="cb_btn[<?php echo $i; ?>][label]"
      value="<?php echo esc_attr($label); ?>"
      placeholder="<?php echo esc_attr($def['label']); ?>"
      style="font-size:12px">
    <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;white-space:nowrap">
      <input type="checkbox" name="cb_btn[<?php echo $i; ?>][active]" value="1"
        <?php checked($active, 1); ?>
        onchange="this.closest('.adcb-row').style.opacity=this.checked?'1':'.55'"> Hiện
    </label>
    <button type="button"
      onclick="var p=this.closest('.adcb-row').querySelector('.adcb-conv');p.style.display=p.style.display==='none'?'block':'none';this.textContent=p.style.display==='none'?'📊 Chuyển đổi':'▲ Đóng'"
      style="font-size:11px;padding:4px 8px;background:<?php echo $conv_on ? '#2271b1' : '#f0f0f0'; ?>;color:<?php echo $conv_on ? '#fff' : '#555'; ?>;border:1px solid #ddd;border-radius:4px;cursor:pointer;white-space:nowrap">
      <?php echo $conv_on ? '📊 Đã bật' : '📊 Chuyển đổi'; ?>
    </button>
  </div>
  <div class="adcb-conv" style="display:<?php echo $conv_on ? 'block' : 'none'; ?>;border-top:1px solid #e0e0e0;padding:14px;background:#fff;border-radius:0 0 6px 6px">
    <div style="display:flex;flex-wrap:wrap;gap:12px 20px;align-items:flex-start">
      <label style="flex-basis:100%;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;color:#2271b1">
        <input type="checkbox" name="cb_btn[<?php echo $i; ?>][conversion][enabled]" value="1" <?php checked($conv_on, true); ?>>
        Bật tracking chuyển đổi cho nút này
      </label>
      <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">GA4 Event Name</label>
        <input type="text" name="cb_btn[<?php echo $i; ?>][conversion][event_name]"
          value="<?php echo esc_attr($conv['event_name'] ?? $ev_default); ?>"
          placeholder="<?php echo esc_attr($ev_default); ?>"
          style="font-family:monospace;font-size:12px;width:160px">
        <div style="font-size:11px;color:#999;margin-top:2px">gtag + dataLayer</div>
      </div>
      <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Event Category</label>
        <input type="text" name="cb_btn[<?php echo $i; ?>][conversion][category]"
          value="<?php echo esc_attr($conv['category'] ?? $type); ?>"
          placeholder="contact" style="font-size:12px;width:120px">
      </div>
      <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Event Label</label>
        <input type="text" name="cb_btn[<?php echo $i; ?>][conversion][label]"
          value="<?php echo esc_attr($conv['label'] ?? ($label ?: $def['label'])); ?>"
          placeholder="<?php echo esc_attr($def['label']); ?>" style="font-size:12px;width:120px">
      </div>
      <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Google Ads ID</label>
        <input type="text" name="cb_btn[<?php echo $i; ?>][conversion][ads_id]"
          value="<?php echo esc_attr($conv['ads_id'] ?? ''); ?>"
          placeholder="AW-XXXXXXXXX" style="font-family:monospace;font-size:12px;width:140px">
        <div style="font-size:11px;color:#999;margin-top:2px">Conversion ID</div>
      </div>
      <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">Conversion Label</label>
        <input type="text" name="cb_btn[<?php echo $i; ?>][conversion][ads_label]"
          value="<?php echo esc_attr($conv['ads_label'] ?? ''); ?>"
          placeholder="AbCdEfGhIjK" style="font-family:monospace;font-size:12px;width:140px">
        <div style="font-size:11px;color:#999;margin-top:2px">Lấy từ Google Ads</div>
      </div>
      <div>
        <label style="font-size:12px;color:#666;display:block;margin-bottom:3px">TrackSG Event</label>
        <input type="text" name="cb_btn[<?php echo $i; ?>][conversion][sg_event]"
          value="<?php echo esc_attr($conv['sg_event'] ?? $ev_default); ?>"
          placeholder="<?php echo esc_attr($ev_default); ?>" style="font-family:monospace;font-size:12px;width:160px">
        <div style="font-size:11px;color:#999;margin-top:2px">_paq.push trackEvent</div>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
</div>

<button type="submit" name="adsdefender_save_contact" class="button button-primary button-large">💾 Lưu Contact Bar</button>
</form>

<script>
(function(){
  var list = document.getElementById('adcb-list');
  var dragging = null;
  list.querySelectorAll('.adcb-row').forEach(function(row){
    row.setAttribute('draggable','true');
    row.addEventListener('dragstart',function(e){
      if(e.target.tagName==='INPUT'||e.target.tagName==='SELECT'||e.target.tagName==='BUTTON'){e.preventDefault();return;}
      dragging=row;row.style.opacity='.4';
    });
    row.addEventListener('dragend',function(){dragging.style.opacity='';dragging=null;adcbRenumber();});
    row.addEventListener('dragover',function(e){
      if(!dragging)return;e.preventDefault();
      var r=row.getBoundingClientRect();
      if(e.clientY<r.top+r.height/2){list.insertBefore(dragging,row);}
      else{list.insertBefore(dragging,row.nextSibling);}
    });
  });
  function adcbRenumber(){
    list.querySelectorAll('.adcb-row').forEach(function(row,i){
      row.querySelectorAll('[name^="cb_btn["]').forEach(function(el){
        el.name=el.name.replace(/cb_btn\[\d+\]/,'cb_btn['+i+']');
      });
    });
  }
})();
var placeholders = <?php echo json_encode($phs); ?>;
var defaultEvents = <?php echo json_encode($default_events); ?>;
function adcbTypeChange(sel, i){
  var ph=placeholders[sel.value]||'';
  var inp=document.getElementById('adcb-val-'+i);
  if(inp) inp.placeholder=ph;
  var ev=defaultEvents[sel.value]||'contact_click';
  var row=sel.closest('.adcb-row');
  if(!row) return;
  row.querySelectorAll('[name*="[event_name]"],[name*="[sg_event]"]').forEach(function(el){
    el.placeholder=ev;
    if(!el.value||el.value===el.getAttribute('data-prev-default')) el.value=ev;
    el.setAttribute('data-prev-default',ev);
  });
  row.querySelectorAll('[name*="[category]"]').forEach(function(el){
    if(!el.value||el.value===el.getAttribute('data-prev-type')) el.value=sel.value;
    el.setAttribute('data-prev-type',sel.value);
  });
}
</script>

<hr style="margin-top:30px">
<h3>📌 Hướng dẫn</h3>
<table class="widefat" style="max-width:680px">
<thead><tr><th>Loại</th><th>Nhập gì vào ô Value</th></tr></thead>
<tbody>
<tr><td>📞 Phone</td><td>Số điện thoại: <code>0901234567</code></td></tr>
<tr><td>🇿 Zalo</td><td>Số điện thoại Zalo: <code>0901234567</code></td></tr>
<tr><td>💬 Messenger</td><td>Username page: <code>tenpage</code> hoặc <code>https://m.me/tenpage</code></td></tr>
<tr><td>💚 WhatsApp</td><td>Số có mã quốc gia: <code>84901234567</code></td></tr>
<tr><td>🟣 Viber</td><td>Số có mã quốc gia: <code>+84901234567</code></td></tr>
<tr><td>✈️ Telegram</td><td>Username: <code>@tenaccount</code> hoặc <code>tenaccount</code></td></tr>
<tr><td>🎵 TikTok</td><td>Username: <code>@tenaccount</code></td></tr>
<tr><td>📧 Email</td><td>Địa chỉ email: <code>info@example.com</code></td></tr>
<tr><td>📍 Maps</td><td>Địa chỉ hoặc link Google Maps</td></tr>
<tr><td>🔗 Custom</td><td>URL đầy đủ: <code>https://...</code></td></tr>
</tbody>
</table>
<?php
}
