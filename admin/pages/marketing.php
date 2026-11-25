<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
if (!defined('ABSPATH')) exit;

// ════════════════════════════════════════════════════════════════════════════
// Trang 🚀 Marketing — tab Scripts & Tracking. Contact Bar, Popup, Leads, UTM
// render bởi includes/contact-bar.php, popup.php, utm.php.
// ════════════════════════════════════════════════════════════════════════════

// ─── Trang Scripts & Tracking ────────────────────────────────────────────────

function adsdefender_page_scripts()
{
    $scripts  = adsdefender_get_scripts();
    $tracking = adsdefender_get_tracking_settings();

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

    // Script thô được chèn vào MỌI trang → đòi quyền unfiltered_html như WordPress (multisite:
    // admin site con không có quyền này; DISALLOW_UNFILTERED_HTML tắt quyền này cho mọi người).
    if (isset($_POST['adsdefender_save_scripts']) && !current_user_can('unfiltered_html')) {
        echo '<div class="notice notice-error"><p>Tài khoản của bạn không có quyền <code>unfiltered_html</code> nên không thể lưu mã script.</p></div>';
    } elseif (isset($_POST['adsdefender_save_scripts']) && check_admin_referer('adsdefender_scripts')) {
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

    <?php
}
