<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * AdsDefender — Trang chặn dùng chung
 *
 * MỌI nguồn chặn (click fraud, lockout, firewall, rate limit, honeypot, ẩn trang
 * đăng nhập) trả lời qua adsdefender_block_response():
 *
 *  • Đúng mã HTTP: 403 chặn, 429 quá nhiều request (kèm Retry-After), 404 cho
 *    trang đăng nhập bị ẩn — để trông như trang không tồn tại.
 *  • Render tại chỗ, KHÔNG chuyển hướng → không thể có vòng lặp redirect, không
 *    cần file tĩnh trong thư mục gốc.
 *  • noindex + no-store: không bị Google index, không bị plugin cache lưu lại.
 *  • Giọng trung tính, không hiện IP/User-Agent, không nhắc tên plugin. Thay vào
 *    đó là MÃ THAM CHIẾU: khách gửi mã cho chủ site, admin tra ra IP ở
 *    Chống Click Fraud → Sync & Kiểm tra để gỡ chặn nếu nhầm.
 *  • Request không phải trang HTML (AJAX, REST, API) nhận văn bản ngắn.
 */
if (!defined('ABSPATH')) exit;

/**
 * Mã tham chiếu của một IP: HMAC với salt của site nên ổn định theo IP nhưng
 * không suy ngược ra IP được, và mỗi site cho ra mã khác nhau.
 */
function adsdefender_block_ref(string $ip): string
{
    if ($ip === '') return '—';
    $h = strtoupper(substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 8));
    return substr($h, 0, 4) . '-' . substr($h, 4, 4);
}

/** Chuẩn hoá mã người dùng nhập ("7f3a 9c2e", "7F3A-9C2E"…) về dạng XXXX-XXXX */
function adsdefender_normalize_ref(string $ref): string
{
    $r = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $ref));
    return strlen($r) === 8 ? substr($r, 0, 4) . '-' . substr($r, 4, 4) : '';
}

/**
 * Tìm IP ứng với mã tham chiếu trong Block Log, bảng lockout và Security Log.
 * Chỉ so được với IP đã từng bị ghi nhận — đúng với mục đích tra cứu người bị chặn.
 */
function adsdefender_find_ip_by_ref(string $ref): array
{
    $ref = adsdefender_normalize_ref($ref);
    if ($ref === '') return [];
    global $wpdb;
    $ips = [];
    foreach ([ADSDEFENDER_LOG_TABLE, ADSDEFENDER_LOCKOUT_TABLE, 'adsdefender_security_log'] as $t) {
        $table = $wpdb->prefix . $t;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) continue;
        // GROUP BY + MAX(id): DISTINCT kèm ORDER BY cột ngoài SELECT bị MySQL 8 từ chối
        foreach ((array) $wpdb->get_col("SELECT ip FROM `{$table}` WHERE ip <> '' GROUP BY ip ORDER BY MAX(id) DESC LIMIT 20000") as $ip) {
            $ips[$ip] = true;
        }
    }
    $found = [];
    foreach (array_keys($ips) as $ip) {
        if (adsdefender_block_ref($ip) === $ref) $found[] = $ip;
    }
    return $found;
}

/** Tiêu đề + lời nhắn mặc định cho chặn click fraud / lockout */
function adsdefender_block_default_texts(): array
{
    return [
        'Truy cập tạm thời bị hạn chế',
        'Hệ thống ghi nhận lưu lượng bất thường từ kết nối của bạn nên tạm thời hạn chế truy cập. '
        . 'Nếu bạn cho rằng đây là nhầm lẫn, vui lòng liên hệ với chúng tôi kèm mã tham chiếu bên dưới.',
    ];
}

/** Nội dung theo lý do chặn. Click fraud dùng tiêu đề / lời nhắn admin tự đặt. */
function adsdefender_block_texts(string $reason): array
{
    $opts = adsdefender_settings();
    [$default_title, $default_msg] = adsdefender_block_default_texts();
    switch ($reason) {
        case 'rate_limit':
            return ['Bạn thao tác hơi nhanh', 'Có quá nhiều yêu cầu được gửi trong thời gian ngắn. Vui lòng đợi vài phút rồi tải lại trang.'];
        case 'firewall':
        case 'honeypot':
        case 'login_bot':
        case 'login_geo':
            return ['Yêu cầu không được chấp nhận', 'Yêu cầu vừa gửi chứa nội dung không hợp lệ nên đã bị từ chối. Nếu bạn cho rằng đây là nhầm lẫn, vui lòng liên hệ với chúng tôi kèm mã tham chiếu bên dưới.'];
        case 'geo':
            return ['Website chỉ phục vụ khách tại Việt Nam', 'Xin lỗi, website hiện chỉ mở cho truy cập từ Việt Nam. Nếu bạn đang ở Việt Nam và dùng VPN / proxy nước ngoài, vui lòng tắt rồi tải lại trang.'];
        case 'not_found':
            return ['Không tìm thấy trang', 'Trang bạn tìm không tồn tại hoặc đã được chuyển đi.'];
        default:
            return [
                trim((string) ($opts['bp_title'] ?? '')) ?: $default_title,
                trim((string) ($opts['bp_message'] ?? '')) ?: $default_msg,
            ];
    }
}

/**
 * Trả trang chặn rồi dừng request.
 *
 * @param string $reason  fraud | lockout | firewall | rate_limit | honeypot | not_found
 * @param int    $status  403 | 404 | 429
 * @param int    $retry   Giây cho header Retry-After (chỉ với 429)
 */
function adsdefender_block_response(string $reason = 'fraud', int $status = 403, int $retry = 0): void
{
    $GLOBALS['adsdefender_block_reason'] = $reason;     // Live Traffic đọc ở shutdown
    if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
    if (!defined('DONOTCACHEDB'))     define('DONOTCACHEDB', true);
    do_action('litespeed_control_set_nocache', 'adsdefender_blocked');

    if (!headers_sent()) {
        nocache_headers();
        status_header($status);
        header('X-Robots-Tag: noindex, nofollow', true);
        if ($status === 429 && $retry > 0) header('Retry-After: ' . $retry, true);
    }

    [$title] = adsdefender_block_texts($reason);
    $accept  = $_SERVER['HTTP_ACCEPT'] ?? '';
    $is_page = !wp_doing_ajax()
            && !(defined('REST_REQUEST') && REST_REQUEST)
            && !(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            && ($accept === '' || stripos($accept, 'text/html') !== false || stripos($accept, '*/*') !== false);

    if (!$is_page) {
        if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8', true);
        echo $status . ' — ' . $title;
        exit;
    }

    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8', true);
    echo adsdefender_render_block_page($reason, $status);
    exit;
}

function adsdefender_render_block_page(string $reason, int $status, ?string $ip = null): string
{
    $opts    = adsdefender_settings();
    [$title, $message] = adsdefender_block_texts($reason);
    $ip      = $ip ?? (adsdefender_get_real_ip() ?: ($_SERVER['REMOTE_ADDR'] ?? ''));
    $show_ref = $reason !== 'not_found';
    $ref     = adsdefender_block_ref($ip);
    $contact = trim((string) ($opts['bp_contact'] ?? ''));
    $promo   = !array_key_exists('bp_promo', $opts) || !empty($opts['bp_promo']);
    $site    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $icon    = function_exists('get_site_icon_url') ? get_site_icon_url(96) : '';
    $home    = home_url('/');
    $time    = wp_date('H:i d/m/Y');

    // Liên hệ: số điện thoại / email / link tự thành liên kết bấm được
    $contact_html = '';
    if ($contact !== '') {
        $parts = array_filter(array_map('trim', preg_split('/\s*[\n|]\s*/', $contact)));
        $items = [];
        foreach ($parts as $p) {
            if (is_email($p)) {
                $items[] = '<a href="mailto:' . esc_attr($p) . '">' . esc_html($p) . '</a>';
            } elseif (preg_match('#^https?://#i', $p)) {
                $items[] = '<a href="' . esc_url($p) . '" rel="noopener" target="_blank">' . esc_html(preg_replace('#^https?://(www\.)?#i', '', rtrim($p, '/'))) . '</a>';
            } elseif (preg_match('/^\+?[\d\s.\-()]{8,}$/', $p)) {
                $items[] = '<a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $p)) . '">' . esc_html($p) . '</a>';
            } else {
                $items[] = esc_html($p);
            }
        }
        $contact_html = implode('<span class="sep">·</span>', $items);
    }

    ob_start(); ?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($title . ' — ' . $site); ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f6f7f9;color:#1f2933;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px;line-height:1.6;-webkit-font-smoothing:antialiased}
.card{background:#fff;border:1px solid #e4e7eb;border-radius:14px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px rgba(16,24,40,.06);max-width:520px;width:100%;padding:40px 36px 32px;text-align:center}
.brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:28px;text-decoration:none;color:#52606d;font-size:14px;font-weight:600}
.brand img{width:28px;height:28px;border-radius:6px}
.mark{width:52px;height:52px;margin:0 auto 20px;border-radius:50%;background:#fff4e5;display:flex;align-items:center;justify-content:center}
.mark.nf{background:#eef2f7}
.code{font-size:12px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#9aa5b1;margin-bottom:8px}
h1{font-size:22px;line-height:1.3;font-weight:700;color:#1f2933;margin-bottom:12px}
p.msg{font-size:15px;color:#52606d;margin:0 auto;max-width:420px}
.ref{margin:26px auto 0;display:inline-flex;align-items:center;gap:10px;background:#f6f7f9;border:1px dashed #cbd2d9;border-radius:10px;padding:10px 16px}
.ref span{font-size:12px;color:#7b8794}
.ref strong{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:16px;letter-spacing:.08em;color:#1f2933;user-select:all}
.meta{margin-top:10px;font-size:12px;color:#9aa5b1}
.contact{margin-top:24px;padding-top:20px;border-top:1px solid #eef0f3;font-size:14px;color:#52606d}
.contact a{color:#2563eb;text-decoration:none;font-weight:600}
.contact a:hover{text-decoration:underline}
.contact .sep{margin:0 8px;color:#cbd2d9}
.home{display:inline-block;margin-top:24px;font-size:14px;color:#2563eb;text-decoration:none;font-weight:600}
.promo{margin-top:28px;padding-top:18px;border-top:1px solid #eef0f3;font-size:12px;color:#9aa5b1;line-height:1.6}
.promo a{color:#5a7fc4;text-decoration:none;font-weight:600}
.promo a:hover{text-decoration:underline}
@media (max-width:480px){.card{padding:32px 22px 26px}h1{font-size:20px}}
</style>
</head>
<body>
<main class="card" role="main">
  <a class="brand" href="<?php echo esc_url($home); ?>">
    <?php if ($icon): ?><img src="<?php echo esc_url($icon); ?>" alt=""><?php endif; ?>
    <span><?php echo esc_html($site); ?></span>
  </a>
  <div class="mark<?php echo $reason === 'not_found' ? ' nf' : ''; ?>" aria-hidden="true">
    <?php if ($reason === 'not_found'): ?>
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7b8794" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
    <?php else: ?>
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
    <?php endif; ?>
  </div>
  <div class="code">Mã lỗi <?php echo (int) $status; ?></div>
  <h1><?php echo esc_html($title); ?></h1>
  <p class="msg"><?php echo nl2br(esc_html($message)); ?></p>

  <?php if ($show_ref): ?>
  <div class="ref"><span>Mã tham chiếu</span><strong><?php echo esc_html($ref); ?></strong></div>
  <div class="meta"><?php echo esc_html($time); ?></div>
  <?php if ($contact_html): ?>
  <div class="contact">Liên hệ hỗ trợ: <?php echo $contact_html; ?></div>
  <?php endif; ?>
  <?php else: ?>
  <a class="home" href="<?php echo esc_url($home); ?>">← Về trang chủ</a>
  <?php endif; ?>

  <?php if ($promo && $reason !== 'not_found'): ?>
  <div class="promo">Bạn đang chạy Google Ads? <a href="https://saigon.pro" target="_blank" rel="noopener">saigon.pro</a> cung cấp dịch vụ chặn click ảo, bad click tự động — bảo vệ ngân sách quảng cáo hiệu quả.</div>
  <?php endif; ?>
</main>
</body>
</html>
<?php
    return ob_get_clean();
}

// ─── Xem trước trong admin ────────────────────────────────────────────────────

add_action('admin_post_adsdefender_preview_block_page', function () {
    if (!current_user_can('manage_options')) wp_die('Không có quyền.');
    check_admin_referer('adsdefender_preview_block_page');
    $reason = sanitize_key($_GET['reason'] ?? 'fraud');
    $status = ['rate_limit' => 429, 'not_found' => 404][$reason] ?? 403;
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo adsdefender_render_block_page($reason, $status);
    exit;
});
