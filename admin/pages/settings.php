<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════════════════
// Trang ⚙️ Cài đặt chung: module, thông báo Telegram, phân quyền, cập nhật
// ════════════════════════════════════════════════════════════════════════════

// ─── Tab Module ───────────────────────────────────────────────────────────────

function adsdefender_settings_tab_modules(): void
{
    if (!empty($_GET['modules_saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu cấu hình module.</p></div>';
    }
    $registry = adsdefender_modules_registry();
    $can_edit = adsdefender_is_manager();
    ?>
    <p style="max-width:720px;color:#555">
        <strong>🎯 Chống Click Fraud</strong> là lõi của plugin và luôn chạy. Các module dưới đây bật/tắt được:
        module tắt thì code của nó <strong>không được nạp</strong> và menu tương ứng bị ẩn.
        Cài đặt của module được giữ nguyên — bật lại là chạy như cũ.
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px">
        <input type="hidden" name="action" value="adsdefender_save_modules">
        <?php wp_nonce_field('adsdefender_save_modules'); ?>
        <?php foreach ($registry as $key => $mod): $on = adsdefender_module_enabled($key); ?>
        <div style="background:#fff;border:1px solid #ddd;border-left:4px solid <?php echo $on ? '#00a32a' : '#c3c4c7'; ?>;border-radius:6px;padding:14px 18px;margin-bottom:12px">
            <label style="font-size:15px;font-weight:600">
                <input type="checkbox" name="modules[<?php echo esc_attr($key); ?>]" value="1" <?php checked($on); ?> <?php disabled(!$can_edit); ?>>
                <?php echo esc_html($mod['label']); ?>
            </label>
            <p style="margin:6px 0 0 24px;color:#666;font-size:13px"><?php echo esc_html($mod['desc']); ?></p>
            <?php if ($key === 'security'): ?>
            <p style="margin:6px 0 0 24px;color:#996800;font-size:12px">Tắt module sẽ gỡ các rule hardening AdsDefender đã ghi vào <code>.htaccess</code>
                và trả lại URL đăng nhập mặc định nếu đang dùng Hide Login.</p>
            <?php elseif ($key === 'marketing'): ?>
            <p style="margin:6px 0 0 24px;color:#996800;font-size:12px">Mã tracking (GTM, TrackSG, Meta Pixel, Custom Scripts) vẫn tiếp tục chèn vào site —
                TrackSG là nguồn dữ liệu để phát hiện click fraud.</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($can_edit): ?>
            <?php submit_button('💾 Lưu module'); ?>
        <?php else: ?>
            <p style="color:#d63638">⛔ Chỉ AdsDefender Manager mới được thay đổi module.</p>
        <?php endif; ?>
    </form>
    <?php
}

add_action('admin_post_adsdefender_save_modules', function () {
    if (!current_user_can('manage_options')) wp_die('Không có quyền.');
    check_admin_referer('adsdefender_save_modules');
    if (!adsdefender_is_manager()) wp_die('Chỉ AdsDefender Manager mới được thay đổi module.');

    $posted = (array) ($_POST['modules'] ?? []);
    $mods   = [];
    foreach (array_keys(adsdefender_modules_registry()) as $key) {
        $mods[$key] = !empty($posted[$key]) ? 1 : 0;
    }

    // Tắt Bảo mật: gỡ rule hardening khỏi .htaccess NGAY BÂY GIỜ, khi code của
    // module còn được nạp. Request sau file không nạp nữa thì không còn ai gỡ.
    if (adsdefender_module_enabled('security') && empty($mods['security'])
        && function_exists('adsdefender_st_remove_htaccess')) {
        adsdefender_st_remove_htaccess();
    }

    update_option(ADSDEFENDER_OPTION_MODULES, $mods, true);
    update_option('adsdefender_modules_pending', 1, false);   // xem hook init trong adsdefender.php

    wp_safe_redirect(adsdefender_admin_url('adsdefender-settings', 'modules', ['modules_saved' => 1]));
    exit;
});

// ─── Tab Trang chặn ───────────────────────────────────────────────────────────

function adsdefender_settings_tab_blockpage(): void
{
    $opts  = adsdefender_settings();
    $promo = !array_key_exists('bp_promo', $opts) || !empty($opts['bp_promo']);
    [$def_title, $def_msg] = adsdefender_block_default_texts();
    $preview = function (string $reason): string {
        return wp_nonce_url(admin_url('admin-post.php?action=adsdefender_preview_block_page&reason=' . $reason), 'adsdefender_preview_block_page');
    };
    ?>
    <div style="display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:24px;max-width:1100px;align-items:start">
    <div>
    <p style="color:#555;margin-top:0">Trang khách nhìn thấy khi bị chặn — dùng chung cho click fraud, lockout, Firewall, Rate Limit, Honeypot.
        Trang trả đúng mã HTTP (403 / 429), không bị Google index, không bị plugin cache lưu, và <strong>không</strong> hiển thị IP hay tên plugin.</p>
    <?php adsdefender_settings_form_open('block_page'); ?>
    <table class="form-table">
        <tr>
            <th>Tiêu đề</th>
            <td><input type="text" name="adsdefender_settings[bp_title]" class="large-text"
                    value="<?php echo esc_attr($opts['bp_title'] ?? ''); ?>" placeholder="<?php echo esc_attr($def_title); ?>">
                <p class="description">Áp dụng cho chặn click fraud / lockout. Để trống = dùng mặc định.</p></td>
        </tr>
        <tr>
            <th>Lời nhắn</th>
            <td><textarea name="adsdefender_settings[bp_message]" rows="3" class="large-text"
                    placeholder="<?php echo esc_attr($def_msg); ?>"><?php echo esc_textarea($opts['bp_message'] ?? ''); ?></textarea></td>
        </tr>
        <tr>
            <th>Thông tin liên hệ</th>
            <td><textarea name="adsdefender_settings[bp_contact]" rows="2" class="large-text"
                    placeholder="0909 123 456&#10;hotro@example.com&#10;https://zalo.me/0909123456"><?php echo esc_textarea($opts['bp_contact'] ?? ''); ?></textarea>
                <p class="description">Mỗi dòng một mục. Số điện thoại, email, link (Zalo, Messenger…) tự thành liên kết bấm được. Để trống = ẩn.</p></td>
        </tr>
        <tr>
            <th>Dòng giới thiệu</th>
            <td><input type="hidden" name="adsdefender_settings[bp_promo]" value="0">
                <label><input type="checkbox" name="adsdefender_settings[bp_promo]" value="1" <?php checked($promo); ?>>
                Hiện dòng giới thiệu dịch vụ chặn click ảo <strong>saigon.pro</strong> ở cuối trang</label></td>
        </tr>
    </table>
    <?php adsdefender_settings_form_close('💾 Lưu trang chặn'); ?>
    </div>

    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 18px">
        <h3 style="margin:0 0 10px;font-size:14px">👁 Xem trước</h3>
        <p style="font-size:12px;color:#666;margin:0 0 12px">Mở trong tab mới, theo nội dung đã lưu.</p>
        <div style="display:flex;flex-direction:column;gap:6px">
            <a class="button" target="_blank" href="<?php echo esc_url($preview('fraud')); ?>">Chặn click fraud / lockout (403)</a>
            <a class="button" target="_blank" href="<?php echo esc_url($preview('firewall')); ?>">Firewall / Honeypot (403)</a>
            <a class="button" target="_blank" href="<?php echo esc_url($preview('rate_limit')); ?>">Quá nhiều request (429)</a>
            <a class="button" target="_blank" href="<?php echo esc_url($preview('not_found')); ?>">Trang đăng nhập bị ẩn (404)</a>
        </div>
        <p style="font-size:12px;color:#666;margin:14px 0 0">Khách gửi lại <strong>mã tham chiếu</strong> → tra IP tại
            <a href="<?php echo esc_url(adsdefender_admin_url('adsdefender-fraud', 'tools') . '#ref-lookup'); ?>">Chống Click Fraud → Sync &amp; Kiểm tra</a>.</p>
    </div>
    </div>
    <?php
}

// ─── Tab Thông báo Telegram ───────────────────────────────────────────────────
// (trước đây nằm cuối trang Scripts & Tracking — dùng chung cho lead và IP block)

function adsdefender_settings_tab_notify(): void
{
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
    ?>
    <h2 style="margin-top:0">📲 Telegram Alert</h2>
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
    wp_safe_redirect(adsdefender_admin_url('adsdefender-settings', 'access', ['updated' => 1]));
    exit;
});
