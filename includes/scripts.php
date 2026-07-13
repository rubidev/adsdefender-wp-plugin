<?php
if (!defined('ABSPATH')) exit;

function adsdefender_get_scripts(): array
{
    return get_option(ADSDEFENDER_OPTION_SCRIPTS, [
        'header'      => '',
        'footer'      => '',
        'body_top'    => '',
        'body_bottom' => '',
    ]);
}

function adsdefender_get_tracking_settings(): array
{
    return get_option('adsdefender_tracking', [
        'gtm_id'      => '',
        'gtm_enabled' => 1,
        'tracksg_id'  => '',
        'tracksg_enabled' => 1,
        'pixel_id'    => '',
        'pixel_enabled' => 1,
    ]);
}

// ─── GTM ──────────────────────────────────────────────────────────────────────

function adsdefender_render_gtm_head(string $gtm_id): string
{
    return "<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$gtm_id}');</script>
<!-- End Google Tag Manager -->";
}

function adsdefender_render_gtm_body(string $gtm_id): string
{
    return "<!-- Google Tag Manager (noscript) -->
<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$gtm_id}\"
height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->";
}

// ─── TrackSG (Matomo) ─────────────────────────────────────────────────────────

function adsdefender_render_tracksg_head(string $tracksg_id): string
{
    return "<!-- TrackSG -->
<script>
  var _paq = window._paq = window._paq || [];
  _paq.push(['enableHeartBeatTimer', 15]);
  fetch('https://api64.ipify.org?format=json')
    .then(function(r){return r.json();})
    .then(function(d){
      _paq.push(['setCustomDimension', 2, d.ip]);
      _paq.push(['trackPageView']);
      _paq.push(['enableLinkTracking']);
    });
  (function() {
    var u=\"https://track.saigon.pro/\";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '{$tracksg_id}']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<!-- End TrackSG -->";
}

function adsdefender_render_tracksg_noscript(string $tracksg_id): string
{
    return "<noscript><p><img referrerpolicy=\"no-referrer-when-downgrade\" src=\"https://track.saigon.pro/matomo.php?idsite={$tracksg_id}&amp;rec=1&amp;dimension1=Jsdisable\" style=\"border:0;\" alt=\"\" /></p></noscript>";
}

// ─── Meta Pixel ───────────────────────────────────────────────────────────────

function adsdefender_render_pixel_head(string $pixel_id): string
{
    return "<!-- Meta Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window,document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{$pixel_id}');
fbq('track', 'PageView');
</script>
<noscript><img height=\"1\" width=\"1\" style=\"display:none\"
src=\"https://www.facebook.com/tr?id={$pixel_id}&ev=PageView&noscript=1\"/></noscript>
<!-- End Meta Pixel Code -->";
}

// ─── Inject hooks ─────────────────────────────────────────────────────────────

// wp_head p=1: GTM + TrackSG + Pixel + Header Scripts
add_action('wp_head', function () {
    if (is_admin()) return;
    $tr = adsdefender_get_tracking_settings();
    if (!empty($tr['gtm_id'])     && !empty($tr['gtm_enabled']))     echo "\n" . adsdefender_render_gtm_head($tr['gtm_id']) . "\n";
    if (!empty($tr['tracksg_id']) && !empty($tr['tracksg_enabled'])) echo "\n" . adsdefender_render_tracksg_head($tr['tracksg_id']) . "\n";
    if (!empty($tr['pixel_id'])   && !empty($tr['pixel_enabled']))   echo "\n" . adsdefender_render_pixel_head($tr['pixel_id']) . "\n";
    $s = adsdefender_get_scripts();
    if (!empty($s['header'])) echo "\n" . $s['header'] . "\n";
}, 1);

// wp_body_open p=1: GTM noscript + TrackSG noscript + Body Top
// Nhiều theme không gọi wp_body_open → dùng flag để fallback sang wp_footer
$_ads_body_open_fired = false;
add_action('wp_body_open', function () {
    global $_ads_body_open_fired;
    $_ads_body_open_fired = true;
    $tr = adsdefender_get_tracking_settings();
    if (!empty($tr['gtm_id'])     && !empty($tr['gtm_enabled']))     echo "\n" . adsdefender_render_gtm_body($tr['gtm_id']) . "\n";
    if (!empty($tr['tracksg_id']) && !empty($tr['tracksg_enabled'])) echo "\n" . adsdefender_render_tracksg_noscript($tr['tracksg_id']) . "\n";
    $s = adsdefender_get_scripts();
    if (!empty($s['body_top'])) echo "\n" . $s['body_top'] . "\n";
}, 1);

// wp_footer p=1: Body Bottom Scripts + fallback noscript nếu wp_body_open không fire
add_action('wp_footer', function () {
    global $_ads_body_open_fired;
    // Fallback: theme không hỗ trợ wp_body_open → inject noscript vào footer
    if (!$_ads_body_open_fired) {
        $tr = adsdefender_get_tracking_settings();
        if (!empty($tr['gtm_id'])     && !empty($tr['gtm_enabled']))     echo "\n" . adsdefender_render_gtm_body($tr['gtm_id']) . "\n";
        if (!empty($tr['tracksg_id']) && !empty($tr['tracksg_enabled'])) echo "\n" . adsdefender_render_tracksg_noscript($tr['tracksg_id']) . "\n";
    }
    $s = adsdefender_get_scripts();
    if (!empty($s['body_bottom'])) echo "\n" . $s['body_bottom'] . "\n";
}, 1);

// wp_footer p=99: Footer Scripts
add_action('wp_footer', function () {
    $s = adsdefender_get_scripts();
    if (!empty($s['footer'])) echo "\n" . $s['footer'] . "\n";
}, 99);
