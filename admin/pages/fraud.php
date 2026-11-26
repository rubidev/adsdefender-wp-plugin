<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════════════════
// Trang 🎯 Chống Click Fraud: kết nối Matomo, IP bị chặn, whitelist, cross-site
// ════════════════════════════════════════════════════════════════════════════

// ─── Tab Kết nối & Hành động ──────────────────────────────────────────────────

function adsdefender_fraud_tab_connect(): void
{
    $opts = adsdefender_settings();
    adsdefender_settings_form_open('fraud_connect');
    ?>
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
                        value="<?php $sid = (int) ($opts['site_id'] ?? 0); echo $sid > 0 ? $sid : ''; ?>" class="small-text" min="1"
                        placeholder="vd: 5">
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
            <th>IP thật của khách</th>
            <td>
                <label style="display:block;margin-bottom:4px"><input type="checkbox" name="adsdefender_settings[behind_cloudflare]" value="1" <?php checked(!empty($opts['behind_cloudflare'])); ?>>
                    Site chạy sau <strong>Cloudflare</strong> (proxy cam) — lấy IP từ <code>CF-Connecting-IP</code></label>
                <label style="display:block"><input type="checkbox" name="adsdefender_settings[behind_proxy]" value="1" <?php checked(!empty($opts['behind_proxy'])); ?>>
                    Site chạy sau <strong>reverse proxy / load balancer</strong> khác — lấy IP từ <code>X-Real-IP</code></label>
                <div style="margin:4px 0 0 24px">
                    <label style="font-size:12px;color:#50575e">Proxy tin cậy (IP / CIDR, mỗi dòng 1) — chỉ cần khi proxy dùng IP công khai, vd. CDN khác Cloudflare:</label><br>
                    <textarea name="adsdefender_settings[trusted_proxies]" rows="2" style="width:320px;font-family:monospace;font-size:12px" placeholder="203.0.113.10&#10;198.51.100.0/24"><?php echo esc_textarea($opts['trusted_proxies'] ?? ''); ?></textarea>
                    <p class="description" style="margin:2px 0 0"><code>X-Real-IP</code> chỉ được tin khi kết nối đến từ proxy nội bộ (127.0.0.1, 10.x, 172.16–31.x, 192.168.x) hoặc IP trong ô này —
                        kẻ gian gọi thẳng vào server không giả được IP.</p>
                </div>
                <?php $remote_now = $_SERVER['REMOTE_ADDR'] ?? ''; $real_now = adsdefender_get_real_ip(); ?>
                <p class="description">Chọn sai thì mọi khách hiện chung một IP (của proxy) → chặn 1 người là chặn tất cả.
                    Header Cloudflare chỉ được tin khi request thật sự đi qua dải IP của Cloudflare (chống giả mạo).
                    <br>Hiện tại: kết nối từ <code><?php echo esc_html($remote_now); ?></code>, plugin nhận IP của bạn là
                    <code><?php echo esc_html($real_now); ?></code>.</p>
            </td>
        </tr>
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
                <?php
                $ba       = $opts['block_action'] ?? '403';
                $rurl     = trim($opts['redirect_url'] ?? '');
                $rhost    = strtolower((string) wp_parse_url($rurl, PHP_URL_HOST));
                $rself    = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
                $rinvalid = $ba === 'redirect' && ($rhost === '' || $rhost === $rself);
                ?>
                <select name="adsdefender_settings[block_action]" id="ads-block-action">
                    <option value="403"      <?php selected($ba, '403'); ?>>Hiện trang chặn (khuyến nghị)</option>
                    <option value="redirect" <?php selected($ba, 'redirect'); ?>>Chuyển sang website khác</option>
                    <option value="blank"    <?php selected($ba, 'blank'); ?>>Trang trắng</option>
                </select>
                <p class="description">Trang chặn dùng chung cho mọi lớp bảo vệ, trả đúng mã 403 và có mã tham chiếu để khách báo lại.
                    Tuỳ chỉnh nội dung tại <a href="<?php echo esc_url(adsdefender_admin_url('adsdefender-settings', 'blockpage')); ?>">Cài đặt chung → Trang chặn</a>.</p>
            </td>
        </tr>
        <tr id="ads-redirect-row" <?php echo $ba !== 'redirect' ? 'style="display:none"' : ''; ?>>
            <th>Website chuyển đến</th>
            <td><input type="url" name="adsdefender_settings[redirect_url]"
                value="<?php echo esc_attr($rurl); ?>"
                class="regular-text" placeholder="https://google.com/">
                <p class="description">Phải là <strong>website khác</strong>. URL trống hoặc trỏ về chính site này sẽ không được dùng
                    (khách sẽ bị chặn lại ngay ở trang đích → vòng lặp) — khi đó plugin tự hiện trang chặn.</p>
                <?php if ($rinvalid): ?>
                <p style="color:#b32d2e;margin-top:6px">⚠️ URL hiện tại không hợp lệ cho chế độ này — khách bị chặn đang thấy <strong>trang chặn</strong>.</p>
                <?php endif; ?>
                <script>
                document.getElementById('ads-block-action').addEventListener('change', function () {
                    document.getElementById('ads-redirect-row').style.display = this.value === 'redirect' ? '' : 'none';
                });
                </script>
            </td>
        </tr>
    </table>

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
<h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🔍 IP Reputation (AbuseIPDB)</h3>
    <table class="form-table">
        <tr id="abuseipdb">
            <th>AbuseIPDB API Key</th>
            <td>
                <input type="password" name="adsdefender_settings[abuseipdb_key]"
                    value="<?php echo esc_attr($opts['abuseipdb_key'] ?? ''); ?>"
                    class="regular-text" placeholder="Dán API Key từ abuseipdb.com" autocomplete="new-password">
                <p class="description">Miễn phí 1000 check/ngày tại <a href="https://www.abuseipdb.com/account/api" target="_blank">abuseipdb.com/account/api</a>. Dùng để tra cứu độ nguy hiểm của IP trong Block Log và Security Events.</p>
            </td>
        </tr>
    </table>
<h3 style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">📍 Vị trí khách</h3>
    <table class="form-table">
        <tr id="geo">
            <th>Hiện vị trí IP</th>
            <td>
                <input type="hidden" name="adsdefender_settings[geo_enabled]" value="0">
                <label><input type="checkbox" name="adsdefender_settings[geo_enabled]" value="1" <?php checked(adsdefender_geo_enabled()); ?>>
                    Hiện quốc gia / tỉnh / nhà mạng dưới mỗi IP trong Live Traffic, Block Log, Security Events</label>
                <p class="description">Chỉ tra các IP đang hiển thị trong trang quản trị (không tra lúc khách truy cập, không làm chậm web), cache 30 ngày mỗi IP.
                    IP được gửi tới dịch vụ tra cứu bên dưới. Nhãn <b>datacenter</b> = IP máy chủ / cloud (thường là bot, VPN), <b>di động</b> = mạng 3G/4G/5G.<br>
                    Lưu ý: vị trí theo IP chỉ là ước lượng — IP 4G ở Việt Nam thường hiện Hà Nội / TP.HCM (nơi đặt gateway nhà mạng) dù khách ở tỉnh khác.</p>
            </td>
        </tr>
        <tr>
            <th>Nguồn tra</th>
            <td>
                <?php $gp = adsdefender_geo_provider(); ?>
                <label style="display:block;margin-bottom:6px"><input type="radio" name="adsdefender_settings[geo_provider]" value="ip2location" <?php checked($gp, 'ip2location'); ?>>
                    <b>IP2Location.io</b> (khuyên dùng) — tỉnh / thành ở Việt Nam sát hơn</label>
                <label style="display:block"><input type="radio" name="adsdefender_settings[geo_provider]" value="ipapi" <?php checked($gp, 'ipapi'); ?>>
                    <b>ip-api.com</b> — có sẵn nhãn di động / datacenter, tra nhanh 100 IP / lần</label>
                <p class="description">Đổi nguồn sẽ xoá cache vị trí cũ để tra lại bằng nguồn mới.</p>
            </td>
        </tr>
        <tr>
            <th>IP2Location.io key</th>
            <td>
                <input type="password" name="adsdefender_settings[geo_key_i2l]"
                    value="<?php echo esc_attr($opts['geo_key_i2l'] ?? ''); ?>"
                    class="regular-text" placeholder="Để trống = 1.000 lượt / ngày" autocomplete="new-password">
                <p class="description">Đăng ký miễn phí tại <a href="https://www.ip2location.io/sign-up" target="_blank">ip2location.io</a> để có 50.000 lượt / tháng (dùng thương mại được).
                    Gói Free không báo mạng di động — nhãn <b>datacenter</b> được nhận ra theo ASN của các nhà cung cấp cloud lớn (Google, AWS, Azure, Alibaba…).
                    Gói trả phí (có <i>usage_type</i>) hiện đủ nhãn di động / datacenter.</p>
            </td>
        </tr>
        <tr>
            <th>ip-api Pro key</th>
            <td>
                <input type="password" name="adsdefender_settings[geo_key]"
                    value="<?php echo esc_attr($opts['geo_key'] ?? ''); ?>"
                    class="regular-text" placeholder="Để trống = dùng bản miễn phí" autocomplete="new-password">
                <p class="description">Bản miễn phí: giới hạn 15 lần tra / phút (mỗi lần tới 100 IP), qua HTTP, chỉ cho mục đích phi thương mại.
                    Dùng cho website kinh doanh nên mua key Pro tại <a href="https://members.ip-api.com/" target="_blank">members.ip-api.com</a> (HTTPS, không giới hạn).</p>
            </td>
        </tr>
    </table>
    <?php
    adsdefender_settings_form_close();
    ?>
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
});
</script>
    <?php
}

// ─── Tab Cross-site ───────────────────────────────────────────────────────────

function adsdefender_fraud_tab_groups(): void
{
    $opts = adsdefender_settings();
    ?>
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

            const h = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
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
                    tagsHtml += `<span class="ads-tag-chip" data-tag="${h(tag)}" style="display:inline-flex;align-items:center;gap:4px;background:#e8f0fe;border:1px solid #4a90d9;border-radius:12px;padding:2px 10px;margin:2px;font-size:13px">
                        ${h(tag)} <button type="button" class="ads-remove-tag" data-tag="${h(tag)}" style="background:none;border:none;cursor:pointer;color:#d63638;font-size:15px;line-height:1;padding:0 0 0 2px">&times;</button></span>`;
                }
                wrap.innerHTML = tagsHtml;
                bindRemove();
                // Preview table
                let rows = '';
                for (const [tag, sites] of Object.entries(groups)) {
                    const sitesHtml = sites.length === 0
                        ? '<em style="color:#888">Chỉ có site này</em>'
                        : sites.map(s => `<span style="display:inline-block;background:#f0f0f1;border-radius:3px;padding:1px 7px;margin:2px;font-size:12px">Site ${h(s.id)}${s.name ? ' — ' + h(s.name) : ''}</span>`).join('');
                    rows += `<tr data-tag="${h(tag)}"><td><code>${h(tag)}</code></td><td>${sitesHtml}</td></tr>`;
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
    <?php
}

// ─── Tab Sync & Kiểm tra ──────────────────────────────────────────────────────

function adsdefender_fraud_tab_tools(): void
{
    $opts    = adsdefender_settings();
    $updated = get_option(ADSDEFENDER_OPTION_UPDATED, '');
    $count   = count(get_option(ADSDEFENDER_OPTION_IPS, []));
    $error   = get_option('adsdefender_last_error', '');

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
        <tr><th>Token đồng bộ</th><td>
            <?php
            $rest_tok = adsdefender_sync_token();
            $tok_masked = substr($rest_tok, 0, 6) . str_repeat('•', max(0, strlen($rest_tok) - 10)) . substr($rest_tok, -4);
            ?>
            <span id="ads-tok-masked"><code style="font-size:11px;background:#f6f7f7;padding:3px 6px;border-radius:3px"><?php echo esc_html($tok_masked); ?></code></span>
            <span id="ads-tok-full" style="display:none"><code style="font-size:11px;background:#f6f7f7;padding:3px 6px;border-radius:3px"><?php echo esc_html($rest_tok); ?></code></span>
            <button type="button" id="ads-tok-toggle" class="button-link" style="margin-left:8px;font-size:12px">👁 Hiện</button>
            <script>document.getElementById('ads-tok-toggle').addEventListener('click',function(){var show=document.getElementById('ads-tok-full').style.display==='none';document.getElementById('ads-tok-full').style.display=show?'inline':'none';document.getElementById('ads-tok-masked').style.display=show?'none':'inline';this.textContent=show?'🙈 Ẩn':'👁 Hiện';});</script>
            <p class="description">Token bí mật cho lệnh đồng bộ từ máy chủ (<code>POST <?php echo esc_html(rest_url('adsdefender/v1/sync')); ?></code>,
                header <code>X-ADS-Token</code>). Không chia sẻ. Token này không bao giờ xuất hiện trên trang công khai.</p>
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

    <h3 id="ref-lookup" style="font-size:14px;margin:20px 0 10px;padding-bottom:6px;border-bottom:1px solid #eee">🧾 Tra mã tham chiếu</h3>
    <?php
    $ref_in    = sanitize_text_field(wp_unslash($_POST['adsdefender_ref'] ?? ''));
    $ref_found = null;
    if ($ref_in !== '' && isset($_POST['adsdefender_do_ref']) && check_admin_referer('adsdefender_ref')) {
        $ref_found = adsdefender_normalize_ref($ref_in) === '' ? false : adsdefender_find_ip_by_ref($ref_in);
    }
    ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;max-width:900px;margin-bottom:8px">
        <p style="color:#666;font-size:13px;margin:0 0 10px">Khách bị chặn thấy một <strong>mã tham chiếu</strong> (dạng <code>7F3A-9C2E</code>) trên trang chặn.
            Nhập mã họ gửi để biết IP nào và gỡ chặn nếu nhầm.</p>
        <form method="post" style="display:flex;gap:6px;max-width:420px">
            <?php wp_nonce_field('adsdefender_ref'); ?>
            <input type="text" name="adsdefender_ref" value="<?php echo esc_attr($ref_in); ?>" placeholder="XXXX-XXXX"
                style="flex:1;font-family:monospace;font-size:14px;text-transform:uppercase">
            <button type="submit" name="adsdefender_do_ref" class="button button-primary">Tra cứu</button>
        </form>
        <?php if ($ref_found === false): ?>
        <p style="color:#b32d2e;margin:10px 0 0">Mã không hợp lệ — mã gồm 8 ký tự 0-9, A-F.</p>
        <?php elseif (is_array($ref_found) && !$ref_found): ?>
        <p style="color:#996800;margin:10px 0 0">Không tìm thấy IP nào khớp trong Block Log, lockout và Security Events gần đây.</p>
        <?php elseif (is_array($ref_found)): ?>
        <table class="widefat striped" style="margin-top:12px;max-width:640px">
            <thead><tr><th>IP</th><th>Thao tác</th></tr></thead>
            <tbody>
            <?php foreach ($ref_found as $rip): ?>
            <tr>
                <td><code style="font-size:13px"><?php echo esc_html($rip); ?></code></td>
                <td>
                    <a class="button button-small" href="<?php echo esc_url(adsdefender_admin_url('adsdefender-fraud', 'log', ['lip' => $rip, 'paged' => 1])); ?>">Xem Block Log</a>
                    <a class="button button-small" href="<?php echo esc_url(adsdefender_admin_url('adsdefender-fraud', 'whitelist')); ?>">Thêm vào Whitelist</a>
                    <button type="button" class="button button-small" onclick="document.querySelector('[name=adsdefender_test_ip]').value='<?php echo esc_js($rip); ?>';document.querySelector('[name=adsdefender_test_ip]').scrollIntoView({behavior:'smooth',block:'center'});">Kiểm tra IP</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

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
        if (filter_var($block_ip, FILTER_VALIDATE_IP) && adsdefender_is_temp_whitelisted($block_ip)) {
            echo '<div class="notice notice-error is-dismissible"><p>⛔ <code>' . esc_html($block_ip) . '</code> là IP của quản trị viên — không chặn.</p></div>';
        } elseif (filter_var($block_ip, FILTER_VALIDATE_IP)) {
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
        <input type="hidden" name="page" value="adsdefender-fraud">
        <input type="hidden" name="tab" value="log">
        <input type="text" name="lip" value="<?php echo esc_attr($ip_filter); ?>"
               placeholder="Lọc IP..." style="width:150px;height:30px">
        <input type="date" name="ldate" value="<?php echo esc_attr($date_filter); ?>"
               style="height:30px">
        <button type="submit" class="button" style="height:30px;line-height:29px">Lọc</button>
        <?php if ($ip_filter || $date_filter): ?>
        <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-fraud','tab'=>'log'], admin_url('admin.php'))); ?>"
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
        $filter_url = esc_url(add_query_arg(['page'=>'adsdefender-fraud','tab'=>'log','lip'=>$r->ip,'paged'=>1], admin_url('admin.php')));
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
          <th style="width:170px">IP / Vị trí</th>
          <th style="width:190px">Lý do</th>
          <th>URL</th>
          <th style="width:160px">User Agent</th>
          <th style="width:80px">Thao tác</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" style="text-align:center;color:#999;padding:20px">Chưa có log nào.</td></tr>
      <?php else: foreach ($rows as $row):
        $is_manual = in_array($row->ip, $already_blocked);
        $is_matomo = in_array($row->ip, $matomo_ips);
      ?>
        <tr>
          <td style="color:#999;font-size:11px"><?php echo esc_html(substr($row->blocked_at, 0, 16)); ?></td>
          <td>
            <code style="font-size:11px"><?php echo esc_html($row->ip); ?></code><?php echo function_exists('adsdefender_ti_badge') ? adsdefender_ti_badge($row->ip) : ''; ?>
            <?php if ($is_manual): ?><span title="Block thủ công" style="color:#f0860a;font-size:11px"> ✋</span>
            <?php elseif ($is_matomo): ?><span title="Block từ Matomo" style="color:#2271b1;font-size:11px"> 📊</span>
            <?php endif; ?>
            <span class="ads-geo" data-ip="<?php echo esc_attr($row->ip); ?>"></span>
          </td>
          <td style="font-size:11px">
            <?php $rl = adsdefender_block_reason_label((string) ($row->reason ?? ''));
            if ($rl !== ''): ?><span title="<?php echo esc_attr($row->reason); ?>" style="color:#b32d2e"><?php echo esc_html($rl); ?></span>
            <?php else: ?><span style="color:#999" title="Log ghi trước bản 2.5.126 — khi đó chưa lưu lý do">—</span><?php endif; ?>
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
            <a href="<?php echo esc_url(add_query_arg(['page'=>'adsdefender-fraud','tab'=>'log','lip'=>$row->ip,'paged'=>1], admin_url('admin.php'))); ?>" class="button button-small" title="Lọc IP này" style="padding:2px 6px;height:24px;line-height:22px;font-size:11px">▼</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php // ── Pagination ── ?>
    <?php if ($pages > 1):
      $base_args = ['page'=>'adsdefender-fraud','tab'=>'log','lip'=>$ip_filter,'ldate'=>$date_filter];
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

    <?php $admin_ips = adsdefender_admin_ips(); arsort($admin_ips); ?>
    <div style="margin-bottom:16px;padding:10px 14px;background:#edfaef;border-left:4px solid #00a32a;border-radius:2px">
        <strong>👤 IP quản trị — tự động, không bao giờ bị chặn (<?php echo count($admin_ips); ?>):</strong>
        <?php foreach ($admin_ips as $aip => $exp): ?>
            <code style="margin:2px 6px 2px 0;display:inline-block" title="Hết hạn <?php echo esc_attr(wp_date('d/m/Y H:i', $exp)); ?>"><?php echo esc_html($aip); ?><?php echo $aip === $my_ip ? ' (bạn)' : ''; ?></code>
        <?php endforeach; ?>
        <br><span class="description">Ghi khi quản trị viên đăng nhập / dùng trang quản trị, nhớ <?php echo (int) ADSDEFENDER_ADMIN_IP_DAYS; ?> ngày kể từ lần dùng cuối.
            IP mới được ghi sẽ tự gỡ lockout / chặn thủ công đang có.</span>
    </div>

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
        } elseif ($hit = array_values(array_filter(array_merge(array_keys(adsdefender_admin_ips()), [adsdefender_get_real_ip()]),
                      fn($a) => $a !== '' && ($a === $new_ip || adsdefender_ip_matches($a, [$new_ip]))))) {
            // IP / dải CIDR chứa IP quản trị → không chặn
            $notice = ['type' => 'error', 'msg' => "⛔ <code>" . esc_html($new_ip) . "</code> chứa IP của quản trị viên (<code>" . esc_html($hit[0]) . "</code>) — không chặn."];
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

// ─── Tab Live Traffic ─────────────────────────────────────────────────────────

function adsdefender_fraud_tab_live(): void
{
    $o     = adsdefender_lt_opts();
    $cf    = adsdefender_cf_opts();
    $nonce = wp_create_nonce('adsdefender_lt');
    $mine  = adsdefender_get_real_ip();
    ?>
    <style>
    .lt-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;margin-bottom:16px;max-width:1280px}
    .lt-cards{display:flex;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;margin-bottom:12px;max-width:1280px}
    .lt-card{flex:1 1 100px;padding:8px 12px;border-right:1px solid #f0f0f1;white-space:nowrap}
    .lt-card:last-child{border-right:0}
    .lt-card b{display:block;font-size:18px;line-height:1.25;color:#1d2327}
    .lt-card span{font-size:11px;color:#646970}
    details.lt-box>summary{cursor:pointer;font-weight:600;font-size:13.5px;list-style-position:inside}
    details.lt-box>summary .description{font-weight:400;margin-left:6px}
    details.lt-box[open]>summary{margin-bottom:10px}
    .lt-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;max-width:1280px}
    .lt-bar select,.lt-bar input{height:30px}
    .lt-live{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#00a32a;font-weight:600}
    .lt-live i{width:8px;height:8px;border-radius:50%;background:#00a32a;animation:ltp 1.4s infinite}
    .lt-live.paused{color:#996800}.lt-live.paused i{background:#996800;animation:none}
    @keyframes ltp{50%{opacity:.3}}
    #lt-table{max-width:1280px}
    #lt-table td{vertical-align:top;font-size:12.5px}
    #lt-table tr.lt-row{cursor:pointer}
    #lt-table tr.lt-new td{animation:ltn 2.5s ease-out}
    @keyframes ltn{from{background:#fff8c5}to{background:transparent}}
    #lt-table tr.lt-detail td{background:#f6f7f7;font-size:12px;color:#50575e;word-break:break-all}
    #lt-table tr.lt-conv td{background:#f0faf3}
    .lt-k.conv{background:#00a32a;color:#fff}
    .lt-cv{color:#00712d;font-weight:600}
    .lt-k{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap}
    .lt-k.human{background:#e7f5ec;color:#00712d}.lt-k.good_bot{background:#e8f0fe;color:#1d4ed8}
    .lt-k.bot{background:#fdf2e2;color:#9a5b00}.lt-k.blocked{background:#fde8e8;color:#b32d2e}
    .lt-s{font-size:11px;padding:1px 7px;border-radius:10px;background:#f0f0f1;color:#50575e;white-space:nowrap}
    .lt-s.ads{background:#2271b1;color:#fff}.lt-s.gorg{background:#e7f5ec;color:#00712d}
    .lt-path{font-family:ui-monospace,Consolas,monospace;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block}
    .lt-code{font-family:monospace;font-weight:600}.lt-code.err{color:#b32d2e}
    .lt-act{white-space:nowrap}
    .lt-tabs{display:flex;gap:0;border-bottom:1px solid #c3c4c7;margin-bottom:10px;max-width:1280px}
    .lt-tabs button{background:none;border:1px solid transparent;border-bottom:0;margin-bottom:-1px;padding:7px 14px;font-size:13px;font-weight:600;color:#50575e;cursor:pointer;border-radius:4px 4px 0 0}
    .lt-tabs button:hover{color:#2271b1}
    .lt-tabs button.active{background:#fff;border-color:#c3c4c7;color:#1d2327}
    .lt-tabn{display:inline-block;min-width:18px;padding:0 6px;margin-left:4px;border-radius:9px;background:#fdf2e2;color:#9a5b00;font-size:11px;line-height:17px}
    .lt-tabn:empty{display:none}
    .lt-act .button{padding:0 6px;min-height:24px;line-height:22px;font-size:12px;margin:0 2px 0 0}
    .lt-ip code{font-size:12px}
    .lt-n{display:inline-block;font:600 11px/16px -apple-system,sans-serif;background:#fdf2e2;color:#9a5b00;border-radius:8px;padding:0 6px;margin-right:6px;vertical-align:1px}
    .lt-items{margin-top:6px;max-height:260px;overflow:auto;font-family:ui-monospace,Consolas,monospace;font-size:11.5px;line-height:1.6}
    .lt-items>b{font-family:-apple-system,sans-serif}
    .lt-items div span:first-child{color:#8c8f94;margin-right:10px}
    .lt-trk{font-size:12px;line-height:1.35;max-width:230px}
    .lt-trk .kw{font-weight:600;color:#1d2327;cursor:pointer;word-break:break-word}
    .lt-trk .cp{display:block;color:#646970;font-size:11px;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .lt-trk .kw:hover,.lt-trk .cp:hover{color:#2271b1;text-decoration:underline}
    .lt-trk.via{opacity:.6}
    .lt-mt{display:inline-block;font-size:10px;padding:0 5px;border-radius:8px;background:#f0f6fc;color:#2271b1;margin-left:4px;font-weight:600;vertical-align:1px}
    .lt-chip{display:inline-flex;align-items:center;gap:4px;background:#2271b1;color:#fff;border-radius:12px;padding:2px 4px 2px 10px;font-size:12px}
    .lt-chip button{background:rgba(255,255,255,.25);border:0;color:#fff;border-radius:50%;width:18px;height:18px;line-height:16px;cursor:pointer;padding:0}
    #lt-top-table td,#lt-top-table th{font-size:12.5px}
    #lt-top-table tr{cursor:pointer}
    .lt-risk{display:inline-block;min-width:38px;text-align:center;border-radius:10px;padding:1px 6px;font-weight:600;font-size:11px}
    .lt-risk.lo{background:#e7f5ec;color:#00712d}.lt-risk.mid{background:#fdf2e2;color:#9a5b00}.lt-risk.hi{background:#fde8e8;color:#b32d2e}
    </style>

    <details class="lt-box" id="ads-module-toggle" style="padding:10px 16px" <?php echo $o['enabled'] ? '' : 'open'; ?>>
        <summary>⚙ Cài đặt
            <span class="description">Ghi log: <b style="color:<?php echo $o['enabled'] ? '#00a32a' : '#d63638'; ?>"><?php echo $o['enabled'] ? 'bật' : 'tắt'; ?></b>
                (giữ <?php echo (int) $o['hours']; ?> giờ) · Tự phát hiện click ảo:
                <b><?php echo !$cf['enabled'] ? 'tắt' : ($cf['mode'] === 'lock' ? 'khoá IP' : 'chỉ đánh dấu'); ?></b></span></summary>
        <?php adsdefender_settings_form_open('live_traffic'); ?>
        <label style="font-weight:600;font-size:14px"><input type="checkbox" name="adsdefender_settings[lt_enabled]" value="1" <?php checked($o['enabled']); ?>>
            Ghi lại mọi lượt truy cập (Live Traffic)</label>
        <span style="margin-left:18px;font-size:13px">
            <input type="hidden" name="adsdefender_settings[lt_skip_admins]" value="0">
            <label><input type="checkbox" name="adsdefender_settings[lt_skip_admins]" value="1" <?php checked($o['skip_admins']); ?>> Bỏ qua tài khoản quản trị / biên tập</label>
        </span>
        <span style="margin-left:18px;font-size:13px">Giữ
            <input type="number" name="adsdefender_settings[lt_hours]" value="<?php echo (int) $o['hours']; ?>" min="1" max="720" style="width:64px"> giờ, tối đa
            <input type="number" name="adsdefender_settings[lt_max_rows]" value="<?php echo (int) $o['max_rows']; ?>" min="1000" max="200000" step="1000" style="width:90px"> dòng</span>
        <p class="description" style="margin:6px 0 0">Mỗi lượt xem trang thêm 1 dòng vào database (không ghi file tĩnh, wp-admin, AJAX, cron). Site nhiều traffic nên để thời gian giữ ngắn.</p>

        <div style="border-top:1px solid #f0f0f1;margin-top:12px;padding-top:12px">
            <input type="hidden" name="adsdefender_settings[cf_enabled]" value="0">
            <label style="font-weight:600;font-size:14px"><input type="checkbox" name="adsdefender_settings[cf_enabled]" value="1" <?php checked($cf['enabled']); ?>>
                🛑 Tự phát hiện click ảo ngay tại site</label>
            <span style="margin-left:12px;font-size:13px">Khi phát hiện:
                <select name="adsdefender_settings[cf_mode]">
                    <option value="lock" <?php selected($cf['mode'], 'lock'); ?>>Khoá IP (dùng thời gian khoá &amp; tự ban vĩnh viễn khi tái phạm)</option>
                    <option value="flag" <?php selected($cf['mode'], 'flag'); ?>>Chỉ đánh dấu, không khoá</option>
                </select></span>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:6px 18px;margin-top:8px;font-size:13px">
                <label>• Cùng IP click quảng cáo ≥
                    <input type="number" name="adsdefender_settings[cf_clicks]" value="<?php echo (int) $cf['clicks']; ?>" min="2" max="20" style="width:56px"> lần trong
                    <input type="number" name="adsdefender_settings[cf_hours]" value="<?php echo (int) $cf['hours']; ?>" min="1" max="168" style="width:56px"> giờ mà không chuyển đổi</label>
                <label><input type="hidden" name="adsdefender_settings[cf_bot]" value="0"><input type="checkbox" name="adsdefender_settings[cf_bot]" value="1" <?php checked($cf['bot']); ?>>
                    Bot / công cụ tự động click quảng cáo (bot thật của Google được bỏ qua)</label>
                <label><input type="hidden" name="adsdefender_settings[cf_ti]" value="0"><input type="checkbox" name="adsdefender_settings[cf_ti]" value="1" <?php checked($cf['ti']); ?>>
                    IP trong danh sách đen Threat Intel click quảng cáo</label>
                <label><input type="checkbox" name="adsdefender_settings[cf_gclid]" value="1" <?php checked($cf['gclid']); ?>>
                    Dùng lại gclid của lượt click khác <span class="description">(có thể là người dùng chia sẻ link)</span></label>
                <label><input type="checkbox" name="adsdefender_settings[cf_subnet]" value="1" <?php checked($cf['subnet']); ?>>
                    ≥ <input type="number" name="adsdefender_settings[cf_subnet_n]" value="<?php echo (int) $cf['subnet_n']; ?>" min="3" max="50" style="width:52px"> IP cùng dải /24 click trong 1 giờ
                    <span class="description">(cẩn thận với mạng di động dùng chung dải)</span></label>
            </div>
            <p class="description" style="margin:6px 0 0">Cần bật Live Traffic. IP whitelist, IP admin và IP đã có chuyển đổi (gọi điện, gửi form…) không bị tính là click lặp.
                Chặn trên website <strong>không</strong> làm Google ngừng tính tiền — hãy đưa IP vào <em>loại trừ IP</em> của Google Ads (bảng bên dưới).</p>
        </div>
        <?php adsdefender_settings_form_close('Lưu'); ?>
    </details>

    <?php if (!$o['enabled']): ?>
    <div class="notice notice-info inline" style="max-width:1280px"><p>Live Traffic đang <strong>tắt</strong>. Bật ở ô phía trên để bắt đầu ghi — dữ liệu xuất hiện ngay ở lượt truy cập tiếp theo.</p></div>
    <?php endif; ?>

    <div class="lt-cards">
        <div class="lt-card" title="Người thật xem trang trong 5 phút qua"><b id="lt-online">–</b><span>online</span></div>
        <div class="lt-card" title="Lượt truy cập trong 1 giờ qua"><b id="lt-hits">–</b><span>lượt / giờ</span></div>
        <div class="lt-card" title="Số IP khác nhau trong 1 giờ qua"><b id="lt-ips">–</b><span>IP / giờ</span></div>
        <div class="lt-card" title="Lượt vào từ quảng cáo (mọi nền tảng) trong 1 giờ qua"><b id="lt-ads" style="color:#2271b1">–</b><span>từ quảng cáo</span></div>
        <div class="lt-card" title="Google Ads / Google tìm kiếm tự nhiên trong 1 giờ qua"><b><span id="lt-g_ads" style="color:#2271b1">–</span><small style="font-size:12px;color:#8c8f94"> / </small><span id="lt-g_org" style="color:#00712d">–</span></b><span>G.Ads / G.tự nhiên</span><span id="lt-gfake-w" style="display:none;color:#b32d2e;font-weight:600"> · ⚠ <span id="lt-g_fake">0</span> gclid lạ</span></div>
        <div class="lt-card" title="Bot trong 1 giờ qua"><b id="lt-bots" style="color:#9a5b00">–</b><span>bot</span></div>
        <div class="lt-card" title="Request bị chặn trong 1 giờ qua"><b id="lt-blocked" style="color:#b32d2e">–</b><span>bị chặn</span></div>
        <div class="lt-card" title="Chuyển đổi trong 1 giờ qua (gọi, Zalo, form, đơn hàng)"><b id="lt-conv" style="color:#00a32a">–</b><span>chuyển đổi · <span id="lt-conv_g">0</span> G.Ads</span></div>
        <div class="lt-card" title="Click ảo phát hiện trong 1 giờ qua"><b id="lt-cf" style="color:#b32d2e">–</b><span>click ảo · <span id="lt-cf_ips">0</span> IP</span></div>
    </div>

    <?php $ex = adsdefender_cf_export_ips(); ?>
    <details class="lt-box" style="padding:10px 16px">
        <summary>🚫 Loại trừ IP trong Google Ads — <?php echo (int) count($ex['ips']); ?> IP
            <span class="description">chặn trên web không làm Google ngừng tính tiền click
            <?php if ($ex['total'] > count($ex['ips'])): ?> · lấy 500 / <?php echo (int) $ex['total']; ?> mới nhất<?php endif; ?></span></summary>
        <p class="description" style="margin:0 0 8px">Dán vào <b>Google Ads → Chiến dịch → Cài đặt → Loại trừ IP</b> (tối đa 500 / chiến dịch).
            Chỉ gồm IP có dấu hiệu click quảng cáo (click ảo tại site, Matomo, khoá vì click ảo 30 ngày, chặn tay từng vào từ quảng cáo) — không gồm bot quét web.
            <?php if (!empty($ex['skipped'])): ?>Đã bỏ <?php echo (int) $ex['skipped']; ?> IP chặn tay không có click quảng cáo.<?php endif; ?></p>
        <?php if ($ex['ips']): ?>
        <textarea id="lt-ex" readonly rows="5" style="width:100%;max-width:640px;font-family:monospace;font-size:12px"><?php echo esc_textarea(implode("\n", $ex['ips'])); ?></textarea><br>
        <button type="button" class="button button-primary" id="lt-ex-copy">📋 Sao chép</button>
        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=adsdefender_cf_export'), 'adsdefender_cf_export')); ?>">⬇ Tải .txt</a>
        <?php else: ?>
        <p class="description">Chưa có IP nào cần loại trừ.</p>
        <?php endif; ?>
    </details>

    <div class="lt-box" style="padding:12px 16px">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
            <strong style="font-size:14px">🎯 Từ khoá &amp; chiến dịch</strong>
            <span class="button-group">
                <button type="button" class="button button-primary lt-top-by" data-by="keyword">Theo từ khoá</button>
                <button type="button" class="button lt-top-by" data-by="campaign">Theo chiến dịch</button>
            </span>
            <select id="lt-top-hours">
                <option value="1">1 giờ qua</option><option value="6">6 giờ qua</option><option value="24" selected>24 giờ qua</option>
                <option value="168">7 ngày qua</option><option value="720">30 ngày qua</option>
            </select>
            <button type="button" class="button" id="lt-top-reload">↻</button>
            <span class="description" style="margin-left:auto">Bấm một dòng để lọc log bên dưới. Dữ liệu giới hạn trong thời gian giữ log (<?php echo (int) $o['hours']; ?> giờ).</span>
        </div>
        <table class="widefat striped" id="lt-top-table">
            <thead><tr><th id="lt-top-h1">Từ khoá</th><th>Chiến dịch</th><th style="width:70px" title="Số lượt vào trang từ từ khoá / chiến dịch này (không tính trang xem tiếp)">Click</th>
                <th style="width:60px" title="Số IP khác nhau">IP</th><th style="width:80px" title="Số trang khách xem tiếp sau khi vào">Xem tiếp</th>
                <th style="width:90px" title="Gọi điện / Zalo / form / đơn hàng… từ khách vào bằng từ khoá / chiến dịch này (không tính bot)">Chuyển đổi</th>
                <th style="width:70px" title="Chuyển đổi / click">Tỷ lệ</th>
                <th style="width:90px" title="Bot + bị chặn + gclid đáng ngờ + click ảo">Bot / chặn / ảo</th><th style="width:90px" title="(bot + bị chặn + gclid đáng ngờ + click lặp cùng IP) / tổng click">Đáng ngờ</th><th style="width:90px">Gần nhất</th></tr></thead>
            <tbody id="lt-top-body"><tr><td colspan="10" style="text-align:center;color:#888">⏳</td></tr></tbody>
        </table>
    </div>

    <div class="lt-tabs" id="lt-tabs">
        <button type="button" data-scope="people" class="active" title="Người thật xem trang (kể cả chuyển đổi)">👤 Khách truy cập</button>
        <button type="button" data-scope="bots" title="Bot, công cụ, dịch vụ xem trước link và request bị chặn">🤖 Bot &amp; bị chặn<span class="lt-tabn" id="lt-tab-bots" title="trong 1 giờ qua"></span></button>
        <button type="button" data-scope="all">Tất cả</button>
    </div>

    <div class="lt-bar">
        <select id="lt-kind">
            <option value="">Mọi loại khách</option>
            <option value="human" data-scope="all">Người thật</option>
            <option value="bots" data-scope="bots all">Bot</option><option value="blocked" data-scope="bots all">Bị chặn</option>
            <option value="conv" data-scope="people all">✅ Chuyển đổi</option>
            <option value="fraud">🛑 Click ảo</option>
        </select>
        <select id="lt-source">
            <option value="">Mọi nguồn</option>
            <optgroup label="Google">
                <option value="google_all">Google (Ads + tự nhiên)</option>
                <option value="google_ads">Google Ads</option>
                <option value="google_organic">Google tìm kiếm tự nhiên</option>
                <option value="google_ads_fake">⚠ Google Ads — gclid đáng ngờ</option>
            </optgroup>
            <optgroup label="Quảng cáo">
                <option value="ads_all">Quảng cáo (tất cả)</option>
                <option value="meta_ads">Facebook / Meta Ads</option><option value="tiktok_ads">TikTok Ads</option>
                <option value="ads">Quảng cáo khác (Bing, UTM trả phí…)</option>
            </optgroup>
            <optgroup label="Khác">
            <option value="search_all">Tìm kiếm tự nhiên (mọi công cụ)</option>
            <option value="organic">Bing / Cốc Cốc / Yahoo…</option><option value="social">Mạng xã hội</option>
            <option value="referral">Website khác</option><option value="direct">Truy cập trực tiếp</option>
            </optgroup>
        </select>
        <select id="lt-status"><option value="">Mọi mã</option><option value="error">Lỗi (4xx/5xx)</option><option value="ok">Thành công</option></select>
        <input type="text" id="lt-ip" placeholder="Lọc IP…" style="width:150px;font-family:monospace">
        <input type="search" id="lt-q" placeholder="Từ khoá / chiến dịch / URL…" style="width:200px">
        <label style="font-size:12px"><input type="checkbox" id="lt-tracked"> Chỉ lượt có chiến dịch / từ khoá</label>
        <span id="lt-chips"></span>
        <button type="button" class="button" id="lt-clear">Xoá lọc</button>
        <button type="button" class="button" id="lt-pause">⏸ Tạm dừng</button>
        <span class="lt-live" id="lt-live"><i></i><span>Đang cập nhật</span></span>
        <span id="lt-msg" style="font-size:12px;margin-left:auto"></span>
    </div>

    <table class="widefat striped" id="lt-table">
        <thead><tr><th style="width:74px">Giờ</th><th style="width:210px">IP</th><th style="width:96px">Khách</th><th style="width:110px">Nguồn</th>
            <th style="width:220px">Chiến dịch / Từ khoá</th><th>Trang</th><th style="width:46px">Mã</th><th style="width:190px">Thao tác</th></tr></thead>
        <tbody id="lt-body"><tr><td colspan="8" style="text-align:center;color:#888;padding:20px">⏳ Đang tải…</td></tr></tbody>
    </table>
    <p class="description" style="max-width:1280px">Bấm vào một dòng để xem User-Agent, nguồn giới thiệu, Matomo visitor ID, URL đầy đủ và mọi tham số quảng cáo (nhóm QC, kiểu khớp, mạng, thiết bị, placement, click ID).
        Bấm vào từ khoá / chiến dịch để lọc. Dòng mờ có dấu ↪ là trang khách xem tiếp sau khi vào từ quảng cáo (cùng IP, trong 30 phút).
        Mã tham chiếu (cột IP) là mã khách nhìn thấy trên trang chặn.</p>

    <script>
    (function () {
        var nonce = '<?php echo esc_js($nonce); ?>', mine = '<?php echo esc_js($mine); ?>';
        var lastId = 0, paused = false, timer = null, busy = false, MAX = 300;
        var pin = {campaign: '', keyword: ''}, topBy = 'keyword';
        // Tab đang chọn — nhớ trong trình duyệt (per-viewer, không cần lưu server)
        var scope = 'people';
        try { scope = localStorage.getItem('adsdefender_lt_scope') || 'people'; } catch (e) {}
        if (['people', 'bots', 'all'].indexOf(scope) < 0) scope = 'people';
        function setScope(s, reload) {
            scope = s;
            try { localStorage.setItem('adsdefender_lt_scope', s); } catch (e) {}
            document.querySelectorAll('#lt-tabs button').forEach(function (b) { b.classList.toggle('active', b.dataset.scope === s); });
            // Ẩn lựa chọn "loại khách" không hợp với tab (vd. "Bot" trong tab Khách truy cập)
            var sel = document.getElementById('lt-kind');
            Array.prototype.forEach.call(sel.options, function (o) { o.hidden = !!o.dataset.scope && o.dataset.scope.split(' ').indexOf(s) < 0; });
            if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) sel.value = '';
            if (reload) load(true);
        }
        document.getElementById('lt-tabs').addEventListener('click', function (e) {
            var b = e.target.closest('button[data-scope]'); if (b && b.dataset.scope !== scope) setScope(b.dataset.scope, true);
        });
        var MT = {e: 'chính xác', p: 'cụm từ', b: 'rộng'};
        var KIND = {human: 'Người thật', good_bot: 'Bot thật', bot: 'Bot', blocked: 'Bị chặn'};
        var SRC = {google_ads: 'Google Ads', google_organic: 'Google', meta_ads: 'Meta Ads', tiktok_ads: 'TikTok Ads', ads: 'Quảng cáo',
                   organic: 'Tìm kiếm', social: 'Mạng xã hội', referral: 'Website khác', direct: 'Trực tiếp', internal: 'Nội bộ'};
        var body = document.getElementById('lt-body');
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
        function filters() {
            return {scope: scope === 'all' ? '' : scope, kind: val('lt-kind'), source: val('lt-source'), status: val('lt-status'), ip: val('lt-ip').trim(),
                    q: val('lt-q').trim(), tracked: document.getElementById('lt-tracked').checked ? 1 : '',
                    campaign: pin.campaign, keyword: pin.keyword};
        }
        function val(id) { return document.getElementById(id).value; }
        function row(r, fresh) {
            var ti = r.ti ? ' <span class="lt-k blocked" title="Threat Intel">' + esc(r.ti) + '</span>' : '';
            var kindTitle = r.note ? ' title="' + esc(r.note) + '"' : '';
            var you = r.ip === mine ? ' <span class="lt-s" title="IP của bạn">bạn</span>' : '';
            var tr = document.createElement('tr');
            var n = r.n || 1, lp = r.last_path != null ? r.last_path : r.path, lm = r.last_method || r.method, ls = r.last_status || r.status;
            tr.className = 'lt-row' + (fresh ? ' lt-new' : '') + (r.conv ? ' lt-conv' : '');
            tr.dataset.id = r.id;
            tr.innerHTML =
                '<td><span title="' + esc(r.date) + (n > 1 ? ' · ' + n + ' request từ ' + esc(r.time) + ' đến ' + esc(r.t_last) : '') + '">' + esc(r.t_last || r.time) + '</span></td>' +
                '<td class="lt-ip"><code title="Mã tham chiếu: ' + esc(r.ref) + '">' + esc(r.ip) + '</code>' + you + ti +
                    '<span class="ads-geo sm" data-ip="' + esc(r.ip) + '"></span></td>' +
                '<td>' + (r.conv ? '<span class="lt-k conv">' + esc(r.conv_name) + '</span>' + (r.kind !== 'human' ? '<br><span class="lt-k ' + esc(r.kind) + '"' + kindTitle + '>' + esc(KIND[r.kind] || r.kind) + '</span>' : '')
                                 : '<span class="lt-k ' + esc(r.kind) + '"' + kindTitle + '>' + esc(KIND[r.kind] || r.kind) + '</span>') +
                    (/^prefetch/.test(r.note || '') ? ' <span class="lt-s" title="' + (r.note === 'prefetch-proxy'
                        ? 'Chrome tải trước trang qua proxy của Google (ẩn IP khách) — khách có thể chưa mở trang. IP hiện là của Google, không bị tính click ảo.'
                        : 'Trình duyệt tải trước trang khi khách rê chuột vào link — có thể chưa mở trang') + '">tải trước</span>' : '') + '</td>' +
                '<td>' + srcBadge(r) + '</td>' +
                '<td>' + trk(r) + '</td>' +
                (r.conv
                    ? '<td><span class="lt-cv">' + esc(r.conv_label || r.conv_name) + '</span>' + (r.conv_value ? ' · <b>' + money(r.conv_value) + '</b>' : '') +
                      (r.path ? '<span class="lt-path" style="color:#8c8f94" title="' + esc(r.url) + '">tại ' + esc(r.path) + '</span>' : '') + '</td><td>–</td>'
                    : '<td><span class="lt-path" title="' + esc(n > 1 ? 'Trang cuối: ' + lp + ' — bấm để xem cả ' + n + ' request' : r.url) + '">' +
                          (n > 1 ? '<span class="lt-n">×' + n + '</span>' : '') + (lm !== 'GET' ? '<b>' + esc(lm) + '</b> ' : '') + esc(lp) + '</span></td>' +
                      '<td><span class="lt-code' + (ls >= 400 ? ' err' : '') + '">' + ls + '</span></td>') +
                '<td class="lt-act">' +
                    '<button type="button" class="button lt-do" data-do="block" data-ip="' + esc(r.ip) + '" title="Chặn IP (thêm vào danh sách chặn thủ công)">⊘</button>' +
                    '<button type="button" class="button lt-do" data-do="whitelist" data-ip="' + esc(r.ip) + '" title="Whitelist IP">✓</button>' +
                    '<a href="#" class="button ads-ip-rep" data-ip="' + esc(r.ip) + '" title="Tra uy tín IP">🔍</a>' +
                    '<button type="button" class="button lt-filter" data-ip="' + esc(r.ip) + '" title="Chỉ xem IP này">⚲</button>' +
                '</td>';
            tr._detail = r;
            return tr;
        }
        var CFLAG = {format: 'gclid sai định dạng — không phải do Google tạo (thường là gõ / chèn tay)',
                     reuse: 'gclid đã dùng ở IP khác hoặc cách đây > 30 phút — URL quảng cáo bị sao chép / chạy lại',
                     referer: 'Có gclid nhưng khách đến từ trang không phải Google'};
        var CF = <?php echo wp_json_encode(adsdefender_cf_reasons(), JSON_UNESCAPED_UNICODE); ?>;
        function cfBadge(r) {
            if (!r.fraud) return '';
            var k = r.fraud.split(':')[0], d = r.fraud.slice(k.length + 1);
            return ' <span class="lt-k blocked" style="background:#b32d2e;color:#fff" title="' + esc((CF[k] || k) + (d ? ' (' + d + ')' : '')) + '">🛑 click ảo</span>';
        }
        function money(v) { return Number(v).toLocaleString('vi-VN') + ' ₫'; }
        function srcBadge(r) {
            var s = r.via && r.from ? r.from : r.source, ads = /ads$/.test(s), g = s === 'google_organic';
            var b = '<span class="lt-s' + (ads ? ' ads' : '') + (g ? ' gorg' : '') + '"' + (r.via && r.from ? ' style="opacity:.6" title="Trang xem tiếp — khách vào từ ' + esc(SRC[s] || s) + '"' : '') + '>' +
                (r.via && r.from ? '↪ ' : '') + esc(SRC[s] || s) + '</span>';
            if (r.cflag) b += ' <span class="lt-k blocked" title="' + esc(CFLAG[r.cflag] || r.cflag) + '">⚠ gclid</span>';
            b += cfBadge(r);
            return b;
        }
        function trk(r) {
            if (!r.campaign && !r.keyword) return '<span style="color:#c3c4c7">—</span>';
            var h = '<div class="lt-trk' + (r.via ? ' via' : '') + '"' + (r.via ? ' title="Trang xem tiếp sau khi vào từ ' + esc(SRC[r.from] || r.from || 'quảng cáo') + '"' : '') + '>';
            if (r.keyword) h += (r.via ? '↪ ' : '') + '<span class="kw" data-kw="' + esc(r.keyword) + '" data-cp="' + esc(r.campaign) + '">' + esc(r.keyword) + '</span>' +
                (r.mt ? '<span class="lt-mt">' + esc(MT[r.mt] || r.mt) + '</span>' : '');
            if (r.campaign) h += '<span class="cp" data-cp="' + esc(r.campaign) + '" title="' + esc(r.campaign) + '">' + (r.via && !r.keyword ? '↪ ' : '') + '📣 ' + esc(r.campaign) + '</span>';
            return h + '</div>';
        }
        function chips() {
            var c = document.getElementById('lt-chips'), h = '';
            if (pin.campaign) h += '<span class="lt-chip">📣 ' + esc(pin.campaign) + ' <button type="button" data-unpin="campaign">×</button></span> ';
            if (pin.keyword) h += '<span class="lt-chip">🔑 ' + esc(pin.keyword) + ' <button type="button" data-unpin="keyword">×</button></span>';
            c.innerHTML = h;
        }
        function setPin(cp, kw) { pin.campaign = cp || ''; pin.keyword = kw || ''; chips(); load(true); }
        document.getElementById('lt-chips').addEventListener('click', function (e) {
            var b = e.target.closest('[data-unpin]'); if (!b) return; pin[b.dataset.unpin] = ''; chips(); load(true);
        });
        function loadTop() {
            var fd = new FormData();
            fd.append('action', 'adsdefender_lt_top'); fd.append('nonce', nonce); fd.append('by', topBy); fd.append('hours', val('lt-top-hours'));
            fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (res) {
                var tb = document.getElementById('lt-top-body');
                document.getElementById('lt-top-h1').textContent = topBy === 'campaign' ? 'Chiến dịch' : 'Từ khoá';
                if (!res.success) { tb.innerHTML = '<tr><td colspan="10">❌ ' + esc(res.data) + '</td></tr>'; return; }
                if (!res.data.rows.length) {
                    tb.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#888;padding:14px">Chưa có lượt nào mang ' + (topBy === 'campaign' ? 'chiến dịch' : 'từ khoá') +
                        '. Hãy gắn UTM / ValueTrack vào URL quảng cáo, ví dụ mẫu theo dõi Google Ads:<br><code>{lpurl}?utm_source=google&amp;utm_medium=cpc&amp;utm_campaign={campaignid}&amp;utm_term={keyword}&amp;matchtype={matchtype}&amp;adgroupid={adgroupid}&amp;network={network}&amp;device={device}</code></td></tr>';
                    return;
                }
                tb.innerHTML = res.data.rows.map(function (t) {
                    var rk = t.risk >= 50 ? 'hi' : (t.risk >= 20 ? 'mid' : 'lo');
                    var first = topBy === 'campaign' ? t.campaign : t.keyword;
                    return '<tr data-cp="' + esc(t.campaign) + '" data-kw="' + esc(topBy === 'campaign' ? '' : t.keyword) + '">' +
                        '<td><strong>' + esc(first) + '</strong></td><td style="color:#646970">' + (topBy === 'campaign' ? '' : esc(t.campaign || '—')) + '</td>' +
                        '<td>' + t.clicks + '</td><td>' + t.ips + '</td><td>' + t.pv + '</td>' +
                        '<td' + (t.conv ? ' class="lt-cv"' : ' style="color:#c3c4c7"') + '>' + t.conv + (t.conv_val ? '<br><small>' + money(t.conv_val) + '</small>' : '') + '</td>' +
                        '<td>' + (t.clicks ? (Math.round(1000 * t.conv / t.clicks) / 10) + '%' : '–') + '</td>' +
                        '<td' + (t.bad ? ' style="color:#b32d2e;font-weight:600"' : '') + '>' + t.bad + '</td>' +
                        '<td><span class="lt-risk ' + rk + '">' + t.risk + '%</span></td><td style="color:#646970">' + esc(t.last) + '</td></tr>';
                }).join('');
            });
        }
        document.getElementById('lt-top-body').addEventListener('click', function (e) {
            var tr = e.target.closest('tr[data-cp]'); if (!tr) return;
            setPin(tr.dataset.cp, tr.dataset.kw);
            document.getElementById('lt-table').scrollIntoView({behavior: 'smooth', block: 'start'});
        });
        document.querySelectorAll('.lt-top-by').forEach(function (b) {
            b.addEventListener('click', function () {
                topBy = b.dataset.by;
                document.querySelectorAll('.lt-top-by').forEach(function (x) { x.classList.toggle('button-primary', x === b); });
                loadTop();
            });
        });
        document.getElementById('lt-top-hours').addEventListener('change', loadTop);
        document.getElementById('lt-top-reload').addEventListener('click', loadTop);
        function load(reset) {
            if (busy) return; busy = true;
            if (reset) { lastId = 0; }
            var fd = new FormData(), f = filters();
            fd.append('action', 'adsdefender_lt_feed'); fd.append('nonce', nonce); fd.append('after', lastId);
            for (var k in f) fd.append(k, f[k]);
            fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (res) {
                busy = false;
                if (!res.success) { msg('❌ ' + res.data, true); return; }
                var d = res.data, s = d.stats;
                ['online', 'hits', 'ips', 'ads', 'g_ads', 'g_org', 'g_fake', 'bots', 'blocked', 'conv', 'conv_g', 'cf', 'cf_ips'].forEach(function (k) { document.getElementById('lt-' + k).textContent = (s[k] || 0).toLocaleString('vi-VN'); });
                document.getElementById('lt-gfake-w').style.display = s.g_fake ? '' : 'none';
                document.getElementById('lt-tab-bots').textContent = ((s.bots || 0) + (s.blocked || 0)) ? ((s.bots || 0) + (s.blocked || 0)).toLocaleString('vi-VN') : '';
                if (reset) body.innerHTML = '';
                if (reset && !d.rows.length) { body.innerHTML = '<tr class="lt-empty"><td colspan="8" style="text-align:center;color:#888;padding:20px">Chưa có lượt truy cập nào khớp bộ lọc.</td></tr>'; return; }
                if (d.rows.length) {
                    var empty = body.querySelector('.lt-empty'); if (empty) empty.remove();
                    if (reset) {
                        var frag = document.createDocumentFragment();
                        d.rows.forEach(function (r) { frag.appendChild(row(r, false)); });
                        body.appendChild(frag);
                    } else {
                        // Dòng mới (mới nhất trước) → duyệt từ cũ tới mới, gộp vào dòng trên cùng nếu cùng khách
                        d.rows.slice().reverse().forEach(function (r) {
                            var top = body.querySelector('tr.lt-row');
                            if (top && top._detail && canMerge(top._detail, r)) {
                                var g = merge(top._detail, r), nx = top.nextSibling;
                                if (nx && nx.classList && nx.classList.contains('lt-detail')) nx.remove();
                                top.replaceWith(row(g, true));
                            } else {
                                body.insertBefore(row(r, true), body.firstChild);
                            }
                        });
                    }
                    d.rows.forEach(function (r) { lastId = Math.max(lastId, r.id); });
                    var rows = body.querySelectorAll('tr.lt-row');
                    for (var i = MAX; i < rows.length; i++) { var nx = rows[i].nextSibling; if (nx && nx.classList && nx.classList.contains('lt-detail')) nx.remove(); rows[i].remove(); }
                }
            }).catch(function () { busy = false; msg('⚠️ Mất kết nối, thử lại…', true); });
        }
        // Cùng quy tắc với adsdefender_lt_group() phía server: cùng IP + loại khách, cách ≤ 30 phút,
        // không gộp chuyển đổi; lượt vào từ quảng cáo luôn mở dòng mới.
        function isEntry(r) { return !r.via && /ads$/.test(r.source || ''); }
        function canMerge(g, r) {
            return g.ip === r.ip && g.kind === r.kind && !g.conv && !r.conv && !isEntry(r)
                && (r.ts || 0) - (g.ts_last || g.ts || 0) <= 1800;
        }
        function merge(g, r) {
            var items = g.items ? g.items.slice() : [[g.time, g.method, g.path, g.status]];
            items.unshift([r.time, r.method, r.path, r.status]);
            if (items.length > 50) items.length = 50;
            return Object.assign({}, g, {id: r.id, n: (g.n || 1) + 1, items: items, ts_last: r.ts, t_last: r.time,
                last_path: r.path, last_method: r.method, last_status: r.status});
        }
        function msg(t, err) { var m = document.getElementById('lt-msg'); m.textContent = t; m.style.color = err ? '#b32d2e' : '#00a32a'; if (!err) setTimeout(function () { m.textContent = ''; }, 3500); }
        function tick() { if (!paused && !document.hidden) load(false); }
        ['lt-kind', 'lt-source', 'lt-status', 'lt-tracked'].forEach(function (id) { document.getElementById(id).addEventListener('change', function () { load(true); }); });
        var ipT; ['lt-ip', 'lt-q'].forEach(function (id) { document.getElementById(id).addEventListener('input', function () { clearTimeout(ipT); ipT = setTimeout(function () { load(true); }, 400); }); });
        document.getElementById('lt-clear').addEventListener('click', function () {
            ['lt-kind', 'lt-source', 'lt-status', 'lt-ip', 'lt-q'].forEach(function (id) { document.getElementById(id).value = ''; });
            document.getElementById('lt-tracked').checked = false; pin = {campaign: '', keyword: ''}; chips(); load(true);
        });
        document.getElementById('lt-pause').addEventListener('click', function () {
            paused = !paused; this.textContent = paused ? '▶ Tiếp tục' : '⏸ Tạm dừng';
            var l = document.getElementById('lt-live'); l.classList.toggle('paused', paused); l.querySelector('span').textContent = paused ? 'Đã tạm dừng' : 'Đang cập nhật';
            if (!paused) load(false);
        });
        body.addEventListener('click', function (e) {
            var b = e.target.closest('.lt-do');
            if (b) {
                e.stopPropagation();
                var ip = b.dataset.ip, act = b.dataset.do;
                if (act === 'block' && !confirm('Chặn IP ' + ip + '? IP sẽ vào danh sách Block thủ công.')) return;
                var fd = new FormData(); fd.append('action', 'adsdefender_lt_action'); fd.append('nonce', nonce); fd.append('ip', ip); fd.append('do', act);
                b.disabled = true;
                fetch(ajaxurl, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (res) {
                    b.disabled = false; msg(res.success ? '✅ ' + res.data.msg : '❌ ' + res.data, !res.success);
                });
                return;
            }
            var k = e.target.closest('.lt-trk .kw, .lt-trk .cp');
            if (k) { e.stopPropagation(); setPin(k.dataset.cp, k.classList.contains('kw') ? k.dataset.kw : ''); return; }
            var f = e.target.closest('.lt-filter');
            if (f) { e.stopPropagation(); document.getElementById('lt-ip').value = f.dataset.ip; load(true); return; }
            if (e.target.closest('.ads-ip-rep, a, button')) return;
            var tr = e.target.closest('tr.lt-row'); if (!tr || !tr._detail) return;
            var nx = tr.nextSibling;
            if (nx && nx.classList && nx.classList.contains('lt-detail')) { nx.remove(); return; }
            var r = tr._detail, d = document.createElement('tr'); d.className = 'lt-detail';
            var tk = (r.campaign ? '<b>Chiến dịch:</b> ' + esc(r.campaign) + '<br>' : '') + (r.keyword ? '<b>Từ khoá:</b> ' + esc(r.keyword) + '<br>' : '') +
                (r.track || []).map(function (p) { return '<b>' + esc(p[0]) + ':</b> ' + esc(p[1]); }).join(' &nbsp;·&nbsp; ') + ((r.track || []).length ? '<br>' : '') +
                (r.via ? '<i>↪ Trang xem tiếp — thông tin chiến dịch kế thừa từ lượt vào trước của IP này</i><br>' : '');
            d.innerHTML = '<td></td><td colspan="7">' + tk +
                (r.conv ? '<b style="color:#00712d">✅ Chuyển đổi:</b> ' + esc(r.conv_name) + (r.conv_label ? ' — ' + esc(r.conv_label) : '') + (r.conv_value ? ' · ' + money(r.conv_value) : '') +
                    ' <i>(gán cho nguồn của phiên: ' + esc(SRC[r.source] || r.source) + ')</i><br>' : '') +
                (r.fraud ? '<b style="color:#b32d2e">🛑 Click ảo:</b> ' + esc(CF[r.fraud.split(':')[0]] || r.fraud) + (r.fraud.indexOf(':') > 0 ? ' (' + esc(r.fraud.slice(r.fraud.indexOf(':') + 1)) + ')' : '') + '<br>' : '') +
                (r.cflag ? '<b style="color:#b32d2e">⚠ gclid đáng ngờ:</b> ' + esc(CFLAG[r.cflag] || r.cflag) + '<br>' : '') +
                '<b>URL:</b> ' + esc(r.url) + '<br>' +
                '<b>Nguồn giới thiệu:</b> ' + (r.referer ? esc(r.referer) : '<i>không có</i>') + '<br>' +
                '<b>User-Agent:</b> ' + (r.ua ? esc(r.ua) : '<i>trống</i>') + '<br>' +
                (r.vid ? '<b>Matomo visitor ID:</b> <code>' + esc(r.vid) + '</code><br>' : '') +
                '<b>Mã tham chiếu:</b> ' + esc(r.ref) + (r.note ? ' &nbsp;·&nbsp; <b>Ghi chú:</b> ' + esc(r.note) : '') +
                (r.n > 1 ? '<div class="lt-items"><b>' + r.n + ' request</b> (URL / UA ở trên là của request đầu' +
                    (r.n > r.items.length ? '; hiện ' + r.items.length + ' request gần nhất' : '') + '):' +
                    r.items.map(function (x) {
                        return '<div><span>' + esc(x[0]) + '</span>' + (x[1] !== 'GET' ? '<b>' + esc(x[1]) + '</b> ' : '') + esc(x[2]) +
                            ' <span class="lt-code' + (x[3] >= 400 ? ' err' : '') + '">' + x[3] + '</span></div>';
                    }).join('') + '</div>' : '') + '</td>';
            tr.parentNode.insertBefore(d, nx);
        });
        var exc = document.getElementById('lt-ex-copy');
        if (exc) exc.addEventListener('click', function () {
            var t = document.getElementById('lt-ex'); t.select();
            (navigator.clipboard ? navigator.clipboard.writeText(t.value) : Promise.reject()).then(function () { msg('✅ Đã sao chép ' + t.value.split('\n').length + ' IP'); },
                function () { document.execCommand('copy'); msg('✅ Đã sao chép'); });
        });
        setScope(scope, false);
        load(true); loadTop();
        timer = setInterval(tick, 5000);
        setInterval(function () { if (!paused && !document.hidden) loadTop(); }, 60000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden && !paused) load(false); });
    })();
    </script>
    <?php
    adsdefender_ip_rep_modal();
}
