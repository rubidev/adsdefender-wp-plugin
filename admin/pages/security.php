<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════════════════
// Trang 🛡 Bảo mật: cấu hình từng lớp bảo vệ đặt cạnh nhật ký / kết quả của nó
// ════════════════════════════════════════════════════════════════════════════

// ─── Tab Firewall: Request Firewall, Rate Limit, 404 Flood, IP Reputation ────

function adsdefender_security_tab_firewall(): void
{
    $opts = adsdefender_settings();
    adsdefender_settings_form_open('sec_firewall');
    ?>
    <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🛡️ Request Firewall</h3>
    <table class="form-table">
        <tr>
            <th>Bật Firewall</th>
            <td>
                <label><input type="checkbox" name="adsdefender_settings[fw_enabled]" value="1"
                    <?php checked(!empty($opts['fw_enabled'])); ?>>
                Phát hiện và block SQLi, XSS, Path Traversal, RCE</label>
                <p class="description">Quét URL, query, POST (kể cả mảng lồng nhau), cookie và header —
                block ngay với 403 khi phát hiện tấn công. Payload được chuẩn hoá trước khi so khớp
                (giải mã nhiều lớp URL-encode, HTML entity, comment SQL) nên bắt được cả kỹ thuật né filter.
                <br>Bỏ qua: bot hợp lệ đã xác minh, người dùng có quyền sửa bài, và các field nội dung dài
                (bài viết, bình luận) — nên không chặn nhầm biên tập viên.</p>
            </td>
        </tr>
        <tr>
            <th>Chặn Bad User-Agent</th>
            <td>
                <label><input type="checkbox" name="adsdefender_settings[ua_enabled]" value="1"
                    <?php checked(!empty($opts['ua_enabled'])); ?>>
                Block scanner, bot độc hại theo User-Agent</label>
                <p class="description">Block Nikto, SQLmap, WPScan, DirBuster và các tool quét lỗ hổng phổ biến.
                <strong>Không</strong> chặn Google, Bing, Yandex, Baidu hay bot SEO.</p>
            </td>
        </tr>
        <tr>
            <th>Chặn bot SEO</th>
            <td>
                <label><input type="checkbox" name="adsdefender_settings[seo_bot_block]" value="1"
                    <?php checked(!empty($opts['seo_bot_block'])); ?>>
                Block AhrefsBot, SemrushBot, MJ12bot, DotBot, BLEXBot, PetalBot…</label>
                <p class="description">Các bot này <em>không</em> tấn công — chỉ tốn băng thông. Bật nếu bạn muốn
                tiết kiệm tài nguyên. Chúng bị trả 403 nhưng <strong>không</strong> bị khoá IP.
                Lưu ý: bật xong thì Ahrefs/Semrush sẽ không audit được chính site bạn.</p>
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

    <?php
    adsdefender_settings_form_close();
}

// ─── Tab Đăng nhập & Form: Brute Force, Honeypot, CAPTCHA, Hide Login, Comment ─

function adsdefender_security_tab_login(): void
{
    $opts = adsdefender_settings();
    adsdefender_settings_form_open('sec_login');
    ?>
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
    <?php
    adsdefender_settings_form_close();
}

// ─── Tab Server & WP: System Tweaks, WordPress Tweaks, Security Headers ──────

function adsdefender_security_tab_hardening(): void
{
    $opts = adsdefender_settings();
    adsdefender_settings_form_open('sec_hardening');
    ?>
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
    <?php
    adsdefender_settings_form_close();
}

// ─── Tab có công tắc bật/tắt đặt ngay trên kết quả ───────────────────────────

function adsdefender_security_tab_scanner(): void
{
    $opts = adsdefender_settings();
    echo '<div id="ads-module-toggle" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:4px 20px 0;margin-bottom:20px;max-width:900px">';
    adsdefender_settings_form_open('sec_scanner');
    ?>
    <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🦠 Site Scanner / Malware</h3>
    <table class="form-table">
        <tr>
            <th>Bật Malware Scan</th>
            <td>
                <label><input type="checkbox" name="adsdefender_settings[scan_enabled]" value="1"
                    <?php checked(!empty($opts['scan_enabled'])); ?>>
                Tự động quét malware 1 lần/ngày</label>
                <p class="description">Hơn 30 loại dấu hiệu mã độc phổ biến: webshell, backdoor, redirect độc hại, crypto miner, spam inject… Kết quả hiển thị ngay bên dưới.</p>
            </td>
        </tr>
    </table>
    <?php
    adsdefender_settings_form_close();
    echo '</div>';
    adsdefender_page_sitescanner();
}

function adsdefender_security_tab_filemonitor(): void
{
    $opts = adsdefender_settings();
    echo '<div id="ads-module-toggle" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:4px 20px 0;margin-bottom:20px;max-width:900px">';
    adsdefender_settings_form_open('sec_filemonitor');
    ?>
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
    <?php
    adsdefender_settings_form_close();
    echo '</div>';
    adsdefender_page_filemonitor();
}

function adsdefender_security_tab_adminlog(): void
{
    $opts = adsdefender_settings();
    echo '<div id="ads-module-toggle" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:4px 20px 0;margin-bottom:20px;max-width:900px">';
    adsdefender_settings_form_open('sec_adminlog');
    ?>
    <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">📋 Admin Activity Log</h3>
    <table class="form-table">
        <tr>
            <th>Bật Admin Log</th>
            <td>
                <label><input type="checkbox" name="adsdefender_settings[admin_log_enabled]" value="1"
                    <?php checked(!empty($opts['admin_log_enabled'])); ?>>
                Ghi lại hành động của admin (login, settings, post, user)</label>
                <p class="description">Lưu tối đa 20.000 dòng. Nhật ký hiển thị ngay bên dưới.</p>
            </td>
        </tr>
    </table>
    <?php
    adsdefender_settings_form_close();
    echo '</div>';
    adsdefender_page_adminlog();
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
        'threat_intel'=> '🌐 Threat Intel',
    ];
    $type_colors = [
        'brute_force' => '#d63638',
        'firewall'    => '#f0860a',
        'rate_limit'  => '#2271b1',
        'flood_404'   => '#888888',
        'ip_block'    => '#6741d9',
        'threat_intel'=> '#b45309',
    ];
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
      <strong style="font-size:13px">Lọc loại:</strong>
      <?php foreach ($type_labels as $k => $lbl):
        $active = ($type_filter === $k);
        $url = esc_url(add_query_arg(['page'=>'adsdefender-security','tab'=>'events','stype'=>$k,'spg'=>1], admin_url('admin.php')));
      ?>
      <a href="<?php echo $url; ?>" class="button<?php echo $active ? ' button-primary' : ''; ?>" style="font-size:12px;height:28px;line-height:27px;padding:0 10px"><?php echo $lbl; ?></a>
      <?php endforeach; ?>
      <form method="get" style="display:flex;gap:6px;margin-left:auto">
        <input type="hidden" name="page" value="adsdefender-security">
        <input type="hidden" name="tab" value="events">
        <input type="hidden" name="stype" value="<?php echo esc_attr($type_filter); ?>">
        <input type="text" name="sip" value="<?php echo esc_attr($ip_filter); ?>" placeholder="Lọc IP..." style="width:150px;height:28px">
        <button type="submit" class="button" style="height:28px;line-height:27px">Tìm</button>
        <?php if ($ip_filter): ?>
        <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-security','tab'=>'events','stype'=>$type_filter,'spg'=>1], admin_url('admin.php'))); ?>" class="button" style="height:28px;line-height:27px">✕</a>
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
          <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-security','tab'=>'events','sip'=>$row['ip'],'stype'=>'','spg'=>1], admin_url('admin.php'))); ?>" title="Lọc IP này" style="color:#999;font-size:10px;margin-left:4px">▼</a>
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
        $purl = esc_url(add_query_arg(['page'=>'adsdefender-security','tab'=>'events','stype'=>$type_filter,'sip'=>$ip_filter,'spg'=>$p], admin_url('admin.php')));
      ?><a href="<?php echo $purl; ?>" class="button<?php echo $p===$page?' button-primary':''; ?>" style="font-size:12px;height:28px;line-height:27px;padding:0 10px;min-width:32px;text-align:center"><?php echo $p; ?></a><?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php adsdefender_ip_rep_modal(); ?>
    <?php
}

// ─── Admin Activity Log Page ──────────────────────────────────────────────────

function adsdefender_page_adminlog(): void
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_ADMIN_LOG_TABLE;

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        echo '<div class="notice notice-warning"><p>Bảng Admin Log chưa được tạo. Bật Admin Log ở ô phía trên rồi tải lại trang.</p></div>';
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
    <div class="notice notice-warning is-dismissible"><p>⚠️ Admin Log đang <strong>tắt</strong> — bật ở ô phía trên.</p></div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <form method="get" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="page" value="adsdefender-security">
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
        <a href="<?php echo esc_url(adsdefender_admin_url('adsdefender-security', 'adminlog')); ?>" class="button" style="height:30px;line-height:29px">✕</a>
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
          <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-security','tab'=>'adminlog','aluser'=>$row['user_login'],'alpg'=>1], admin_url('admin.php'))); ?>"
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
      $base = ['page'=>'adsdefender-security','tab'=>'adminlog','altype'=>$type_filter,'aluser'=>$user_filter,'aldate'=>$date_filter];
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
    <div class="notice notice-warning is-dismissible"><p>⚠️ File Monitor đang <strong>tắt</strong> — bật ở ô phía trên.</p></div>
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
    $nonce_rechk  = wp_create_nonce('adsdefender_scan_recheck');
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
    <div class="notice notice-warning is-dismissible"><p>⚠️ Malware Scan đang <strong>tắt</strong> — bật ở ô phía trên.</p></div>
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
        <button id="sc-btn-optimize" class="button" data-nonce="<?php echo esc_attr(wp_create_nonce('adsdefender_cfg_optimize')); ?>"
                title="Đặt các hằng số bảo mật nên có trong wp-config.php — có sao lưu và kiểm tra cú pháp trước khi ghi">
            ⚙️ Tối ưu wp-config.php
        </button>
        <span id="sc-spinner" style="display:none;color:#718096">⏳ Đang khởi động...</span>
    </div>

    <div id="sc-scan-result"></div>
    <div id="sc-optimize-result"></div>

    <?php if (!empty($results)): ?>
    <h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:12px">
        <span>📋 Kết quả quét (<?php echo count($results); ?> cảnh báo chưa xử lý)</span>
        <button type="button" id="sc-recheck" class="button" style="font-weight:400;font-size:12px"
                title="Chạy lại signature trên các file đã báo — xóa mục không còn đúng (vd: sau khi sửa luật bị báo nhầm)">
            🔄 Kiểm tra lại
        </button>
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
            <?php $db_st = $db_scan_meta['stats'] ?? []; ?>
            <div class="sc-val"><?php echo (int)array_sum(array_map('intval', $db_st ?: [0])); ?></div>
            <div class="sc-lbl">Bản ghi đã quét</div>
            <?php if ($db_st): ?>
            <div style="font-size:10px;color:#a0aec0;margin-top:2px">
                <?php echo esc_html(sprintf('opt %d · post %d · meta %d · user %d',
                    $db_st['options'] ?? 0, $db_st['posts'] ?? 0,
                    ($db_st['postmeta'] ?? 0) + ($db_st['usermeta'] ?? 0), $db_st['users'] ?? 0)); ?>
            </div>
            <?php endif; ?>
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
    var BATCHES = ['config','config-audit','wp-includes','wp-admin','themes','plugins','uploads'];
    var LABELS  = {'config':'Config files','config-audit':'Cấu hình (.htaccess & wp-config)','wp-includes':'WP Core (depth 1)','wp-admin':'WP Admin','themes':'Themes','plugins':'Plugins','uploads':'Uploads'};

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

    // ── Tối ưu wp-config.php ────────────────────────────────────────────────
    // Luôn xem trước rồi mới hỏi xác nhận — không tự ghi vào wp-config.php.
    (function(){
        var oBtn = document.getElementById('sc-btn-optimize');
        if (!oBtn) return;
        var oOut = document.getElementById('sc-optimize-result');

        function call(preview, nonce) {
            var fd = new FormData();
            fd.append('action', 'adsdefender_cfg_optimize');
            fd.append('nonce', nonce);
            if (preview) fd.append('preview', '1');
            return fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.text(); })
                .then(function(txt){
                    try { return JSON.parse(txt); }
                    catch(e) {
                        var p = txt.replace(/<[^>]+>/g,'').replace(/\s+/g,' ').trim().substring(0,300);
                        throw new Error(p || 'Server trả response không phải JSON');
                    }
                });
        }

        function rows(changes) {
            return changes.map(function(c){
                return '<tr><td style="padding:4px 10px 4px 0"><code>' + c.const + '</code></td>'
                     + '<td style="padding:4px 10px 4px 0;color:#a0aec0">' + c.from + '</td>'
                     + '<td style="padding:4px 10px 4px 0">→ <strong>' + c.to + '</strong></td>'
                     + '<td style="padding:4px 0;color:#718096;font-size:12px">' + c.reason + '</td></tr>';
            }).join('');
        }

        oBtn.addEventListener('click', function(){
            var nonce = oBtn.getAttribute('data-nonce');
            oBtn.disabled = true;
            oOut.innerHTML = '<p style="color:#718096">⏳ Đang kiểm tra wp-config.php...</p>';

            call(true, nonce).then(function(res){
                if (!res.success) {
                    oBtn.disabled = false;
                    oOut.innerHTML = '<div class="notice notice-error"><p>' + (res.data || 'Lỗi không xác định') + '</p></div>';
                    return;
                }
                var ch = res.data.changes || [];
                if (!ch.length) {
                    oBtn.disabled = false;
                    oOut.innerHTML = '<div class="notice notice-success"><p>✅ ' + res.data.message + '</p></div>';
                    return;
                }
                oOut.innerHTML = '<div class="notice notice-warning" style="padding:12px">'
                    + '<p style="margin-top:0"><strong>Sẽ thay đổi ' + ch.length + ' mục trong wp-config.php:</strong></p>'
                    + '<table style="font-size:13px;margin-bottom:10px">' + rows(ch) + '</table>'
                    + '<p style="color:#718096;font-size:12px;margin-bottom:10px">'
                    + 'Bản sao lưu được tạo trước khi ghi. Cú pháp bản mới được kiểm tra trước; '
                    + 'nếu có lỗi thì file gốc giữ nguyên.</p>'
                    + '<button type="button" id="sc-opt-go" class="button button-primary">Áp dụng</button> '
                    + '<button type="button" id="sc-opt-no" class="button">Hủy</button>'
                    + '</div>';

                document.getElementById('sc-opt-no').addEventListener('click', function(){
                    oBtn.disabled = false;
                    oOut.innerHTML = '';
                });
                document.getElementById('sc-opt-go').addEventListener('click', function(){
                    this.disabled = true;
                    oOut.innerHTML = '<p style="color:#718096">⏳ Đang ghi và kiểm tra...</p>';
                    call(false, nonce).then(function(r2){
                        oBtn.disabled = false;
                        if (!r2.success) {
                            oOut.innerHTML = '<div class="notice notice-error"><p>' + (r2.data || 'Lỗi không xác định') + '</p></div>';
                            return;
                        }
                        oOut.innerHTML = '<div class="notice notice-success"><p>✅ ' + r2.data.message + '</p></div>';
                        setTimeout(function(){ location.reload(); }, 2000);
                    }).catch(function(err){
                        oBtn.disabled = false;
                        oOut.innerHTML = '<div class="notice notice-error"><p>⚠️ ' + err.message + '</p></div>';
                    });
                });
            }).catch(function(err){
                oBtn.disabled = false;
                oOut.innerHTML = '<div class="notice notice-error"><p>⚠️ ' + err.message + '</p></div>';
            });
        });
    })();

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

    // Kiểm tra lại: chạy signature trên các file đã báo, xóa mục không còn đúng
    var reBtn = document.getElementById('sc-recheck');
    if (reBtn) {
        reBtn.addEventListener('click', function(){
            reBtn.disabled = true;
            var old = reBtn.textContent;
            reBtn.textContent = '⏳ Đang kiểm tra...';
            var fd = new FormData();
            fd.append('action', 'adsdefender_scan_recheck');
            fd.append('nonce', '<?php echo $nonce_rechk; ?>');
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res.success) {
                        alert(res.data.message);
                        if (res.data.removed > 0) location.reload();
                        else { reBtn.disabled = false; reBtn.textContent = old; }
                    } else {
                        alert('Lỗi: ' + (res.data || 'không rõ'));
                        reBtn.disabled = false; reBtn.textContent = old;
                    }
                })
                .catch(function(err){
                    alert('Lỗi kết nối: ' + err);
                    reBtn.disabled = false; reBtn.textContent = old;
                });
        });
    }

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

// ─── Tab Threat Intel ─────────────────────────────────────────────────────────

function adsdefender_security_tab_threat(): void
{
    $opts    = adsdefender_settings();
    $sources = adsdefender_ti_sources();
    $nonce   = wp_create_nonce('adsdefender_ti_sync');
    $modes   = ['off' => 'Tắt', 'label' => 'Chỉ gắn nhãn', 'block' => 'Chặn'];

    // Thống kê 7 ngày từ Security Events
    global $wpdb;
    $sec_table  = $wpdb->prefix . 'adsdefender_security_log';
    $blocked_7d = $wpdb->get_var("SHOW TABLES LIKE '{$sec_table}'") === $sec_table
        ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$sec_table}` WHERE event_type = %s AND created_at >= %s",
            'threat_intel', date('Y-m-d H:i:s', current_time('timestamp') - 7 * DAY_IN_SECONDS)))
        : 0;
    ?>
    <p style="max-width:820px;color:#555;margin-top:0">
        Tự động đồng bộ <strong>mỗi ngày</strong> các danh sách IP độc hại công khai (miễn phí, không cần API key) và chặn ngay ở tầng PHP —
        trước khi kẻ xấu chạm tới form đăng nhập hay bấm vào quảng cáo. Dải IP nội bộ / private có trong danh sách gốc được tự loại bỏ.
        <br>Luôn bỏ qua: IP trong Whitelist, bot tìm kiếm đã xác minh (Googlebot, Bingbot…), tài khoản biên tập đã đăng nhập.
    </p>
    <div style="display:flex;gap:12px;margin:0 0 18px;flex-wrap:wrap">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 18px;min-width:170px">
            <div style="font-size:22px;font-weight:700;color:#b45309"><?php echo number_format($blocked_7d); ?></div>
            <div style="font-size:12px;color:#718096">lượt bị chặn 7 ngày qua
                <a href="<?php echo esc_url(adsdefender_admin_url('adsdefender-security', 'events', ['stype' => 'threat_intel'])); ?>">xem →</a></div>
        </div>
    </div>

    <?php adsdefender_settings_form_open('sec_threat'); ?>
    <table class="widefat striped" style="max-width:1000px">
        <thead><tr><th style="width:190px">Nguồn</th><th>Mô tả</th><th style="width:150px">Chế độ</th><th style="width:230px">Dữ liệu</th></tr></thead>
        <tbody>
        <?php foreach ($sources as $key => $src):
            $mode = $opts['ti_' . $key] ?? 'off';
            $data = get_option(adsdefender_ti_option($key));
            $data = is_array($data) ? $data : [];
        ?>
        <tr>
            <td><strong><?php echo esc_html($src['label']); ?></strong><br>
                <a href="<?php echo esc_url($src['link']); ?>" target="_blank" rel="noopener" style="font-size:12px">Nguồn ↗</a></td>
            <td style="font-size:13px;color:#555"><?php echo esc_html($src['desc']); ?></td>
            <td>
                <select name="adsdefender_settings[ti_<?php echo esc_attr($key); ?>]">
                    <?php foreach ($modes as $mv => $ml): ?>
                    <option value="<?php echo esc_attr($mv); ?>" <?php selected($mode, $mv); ?>><?php echo esc_html($ml); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td style="font-size:12px" id="ti-status-<?php echo esc_attr($key); ?>">
                <?php if (!empty($data['count'])): ?>
                    <strong><?php echo number_format((int) $data['count']); ?></strong> mục
                    (<?php echo number_format((int) ($data['ranges'] ?? 0)); ?> dải IPv4<?php echo !empty($data['v6']) ? ', ' . count($data['v6']) . ' IPv6' : ''; ?>)<br>
                    <span style="color:#718096">Cập nhật <?php echo esc_html(wp_date('H:i d/m/Y', (int) $data['updated'])); ?></span>
                <?php else: ?>
                    <span style="color:#718096">Chưa tải</span>
                <?php endif; ?>
                <?php if (!empty($data['error'])): ?>
                    <br><span style="color:#b32d2e">⚠️ <?php echo esc_html($data['error']); ?></span>
                <?php endif; ?>
                <br><button type="button" class="button button-small ads-ti-sync" data-source="<?php echo esc_attr($key); ?>" style="margin-top:4px">🔄 Tải ngay</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="description" style="max-width:1000px;margin-top:10px">
        <strong>Chỉ gắn nhãn:</strong> không chặn, chỉ hiện nhãn cạnh IP trong Live Traffic và Block Log — nên dùng thử vài ngày trước khi chuyển sang Chặn.
        Bật một nguồn lần đầu, plugin tải danh sách ngay khi lưu.
    </p>
    <?php adsdefender_settings_form_close('💾 Lưu Threat Intel'); ?>

    <h3 style="font-size:14px;margin:24px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔎 Kiểm tra một IP</h3>
    <?php
    $chk    = trim(sanitize_text_field(wp_unslash($_POST['ti_check_ip'] ?? '')));
    $chk_ok = $chk !== '' && isset($_POST['ti_do_check']) && check_admin_referer('adsdefender_ti_check');
    ?>
    <form method="post" style="display:flex;gap:6px;max-width:460px">
        <?php wp_nonce_field('adsdefender_ti_check'); ?>
        <input type="text" name="ti_check_ip" value="<?php echo esc_attr($chk); ?>" placeholder="IPv4 hoặc IPv6" style="flex:1;font-family:monospace">
        <button type="submit" name="ti_do_check" class="button">Kiểm tra</button>
    </form>
    <?php
    if ($chk_ok) {
        if (!filter_var($chk, FILTER_VALIDATE_IP)) {
            echo '<p style="color:#b32d2e">IP không hợp lệ.</p>';
        } else {
            $hits = [];
            foreach ($sources as $key => $src) {
                $d = get_option(adsdefender_ti_option($key));
                if (!is_array($d) || empty($d['count'])) continue;
                $m = strpos($chk, ':') === false
                    ? adsdefender_ti_bsearch(adsdefender_ti_v4_bin($d), (int) ip2long($chk))
                    : (!empty($d['v6']) && adsdefender_ip_matches($chk, (array) $d['v6']));
                if ($m) $hits[] = $src['label'] . ' (' . ($modes[$opts['ti_' . $key] ?? 'off'] ?? '') . ')';
            }
            echo '<p id="ti-check-result" style="margin-top:10px">' . ($hits
                ? '🚩 <strong>' . esc_html($chk) . '</strong> có trong: ' . esc_html(implode(', ', $hits))
                : '✅ <strong>' . esc_html($chk) . '</strong> không có trong danh sách nào đã tải.') . '</p>';
        }
    }
    ?>
    <script>
    document.querySelectorAll('.ads-ti-sync').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var src = btn.dataset.source;
            btn.disabled = true; btn.textContent = '⏳ Đang tải…';
            var fd = new FormData();
            fd.append('action', 'adsdefender_ti_sync'); fd.append('nonce', '<?php echo esc_js($nonce); ?>'); fd.append('source', src);
            fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (res) {
                var r = res.success ? res.data[src] : {ok: false, error: res.data};
                if (r.ok) { location.reload(); return; }
                btn.disabled = false; btn.textContent = '🔄 Tải ngay';
                alert('Không tải được: ' + (r.error || 'lỗi không rõ'));
            }).catch(function () { btn.disabled = false; btn.textContent = '🔄 Tải ngay'; alert('Lỗi kết nối'); });
        });
    });
    </script>
    <?php
}
