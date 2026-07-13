<?php
/**
 * AdsDefender — System Tweaks
 *
 * Học từ Solid Security Pro / ITSEC_System_Tweaks + config-generators.php.
 *
 * Ghi các rule vào .htaccess (block AdsDefender section) để bảo vệ:
 *  1. protect_files     — Block readme.html/txt, install.php, wp-includes PHP trực tiếp
 *  2. dir_browsing      — Options -Indexes (tắt directory listing)
 *  3. uploads_php       — Block PHP execution trong /wp-content/uploads/
 *  4. plugins_php       — Block PHP execution trong /wp-content/plugins/
 *  5. themes_php        — Block PHP execution trong /wp-content/themes/
 *
 * Không ghi .htaccess mỗi request — chỉ ghi khi settings thay đổi (update_option hook).
 * Rebuild cũng chạy khi activate/deactivate plugin.
 */
if (!defined('ABSPATH')) exit;

// ─── Settings helpers ─────────────────────────────────────────────────────────

function adsdefender_st_enabled(): bool
{
    return !empty(adsdefender_settings()['st_enabled']);
}

function adsdefender_st_opts(): array
{
    $opts = adsdefender_settings();
    return [
        'protect_files' => !empty($opts['st_protect_files']),
        'dir_browsing'  => !empty($opts['st_dir_browsing']),
        'uploads_php'   => !empty($opts['st_uploads_php']),
        'plugins_php'   => !empty($opts['st_plugins_php']),
        'themes_php'    => !empty($opts['st_themes_php']),
    ];
}

// ─── Build .htaccess block content ───────────────────────────────────────────

function adsdefender_st_build_htaccess(): string
{
    if (!adsdefender_st_enabled()) return '';

    $cfg = adsdefender_st_opts();
    $out = '';

    // ── 1. <files> blocks: bảo vệ các file nhạy cảm (iThemes pattern) ──────────
    if ($cfg['protect_files']) {
        $protected_files = ['.htaccess', 'readme.html', 'readme.txt', 'wp-config.php'];
        foreach ($protected_files as $fname) {
            $out .= "<files {$fname}>\n";
            $out .= "  <IfModule mod_authz_core.c>\n";
            $out .= "    Require all denied\n";
            $out .= "  </IfModule>\n";
            $out .= "  <IfModule !mod_authz_core.c>\n";
            $out .= "    Order allow,deny\n";
            $out .= "    Deny from all\n";
            $out .= "  </IfModule>\n";
            $out .= "</files>\n";
        }
    }

    // ── 2. Options -Indexes ───────────────────────────────────────────────────────
    if ($cfg['dir_browsing']) {
        $out .= "\nOptions -Indexes\n";
    }

    // ── 3. Gộp tất cả RewriteRule vào 1 block duy nhất (iThemes pattern) ────────
    $rewrite_rules = '';
    $wp_includes   = WPINC; // 'wp-includes'

    if ($cfg['protect_files']) {
        $rewrite_rules .= "\n\t# Protect System Files\n";
        $rewrite_rules .= "\tRewriteRule ^wp-admin/install\\.php$ - [F,L]\n";
        $rewrite_rules .= "\tRewriteRule ^wp-admin/includes/ - [F,L]\n";
        $rewrite_rules .= "\tRewriteRule !^{$wp_includes}/ - [S=3]\n";
        $rewrite_rules .= "\tRewriteRule ^{$wp_includes}/[^/]+\\.php$ - [F,L]\n";
        $rewrite_rules .= "\tRewriteRule ^{$wp_includes}/js/tinymce/langs/.+\\.php - [F,L]\n";
        $rewrite_rules .= "\tRewriteRule ^{$wp_includes}/theme-compat/ - [F,L]\n";
        $rewrite_rules .= "\tRewriteCond %{REQUEST_FILENAME} -f\n";
        $rewrite_rules .= "\tRewriteRule (^|.*/)\\.(git|svn)/.* - [F,L]\n";
    }

    if ($cfg['uploads_php']) {
        $upload_dir  = wp_upload_dir();
        $rel         = ltrim(str_replace(ABSPATH, '', $upload_dir['basedir']), '/');
        if ($rel) {
            $rel = str_replace('/', '\\/', $rel); // escape / cho RewriteRule
            $rewrite_rules .= "\n\t# Disable PHP in Uploads\n";
            $rewrite_rules .= "\tRewriteRule ^{$rel}/.*\\.(?:php[1-7]?|pht|phtml?|phps)\\.?\$ - [NC,F,L]\n";
        }
    }

    if ($cfg['plugins_php']) {
        // Dùng parse_url để lấy path tương đối — tránh str_replace fail khi http vs https / www vs non-www
        $rel = ltrim(parse_url(plugins_url(), PHP_URL_PATH) ?? '', '/');
        if ($rel) {
            $rel = str_replace('/', '\\/', $rel);
            $rewrite_rules .= "\n\t# Disable PHP in Plugins\n";
            $rewrite_rules .= "\tRewriteRule ^{$rel}/.*\\.(?:php[1-7]?|pht|phtml?|phps)\\.?\$ - [NC,F,L]\n";
        }
    }

    if ($cfg['themes_php']) {
        $rel = ltrim(parse_url(get_theme_root_uri(), PHP_URL_PATH) ?? '', '/');
        if ($rel) {
            $rel = str_replace('/', '\\/', $rel);
            $rewrite_rules .= "\n\t# Disable PHP in Themes\n";
            $rewrite_rules .= "\tRewriteRule ^{$rel}/.*\\.(?:php[1-7]?|pht|phtml?|phps)\\.?\$ - [NC,F,L]\n";
        }
    }

    if ($rewrite_rules !== '') {
        $out .= "\n<IfModule mod_rewrite.c>\n\tRewriteEngine On\n";
        $out .= $rewrite_rules;
        $out .= "</IfModule>\n";
    }

    return $out;
}

// ─── Ghi vào .htaccess — section riêng biệt ──────────────────────────────────

function adsdefender_st_update_htaccess(): void
{
    // Chỉ hỗ trợ Apache/LiteSpeed (Nginx không dùng .htaccess)
    $server = adsdefender_detect_server_type();
    if ($server === 'nginx') return;

    $htaccess = ABSPATH . '.htaccess';
    $marker   = 'AdsDefender-SystemTweaks';
    $begin    = "# BEGIN {$marker}";
    $end      = "# END {$marker}";
    $backup   = ABSPATH . '.htaccess.adsdefender.bak';

    // Đọc htaccess hiện tại
    $current = file_exists($htaccess) ? file_get_contents($htaccess) : '';
    if ($current === false) $current = '';

    // Dọn rác: xóa dòng invalid (vd: "-SystemTweaks" tàn dư bug cũ)
    $current = preg_replace('/^-[A-Za-z].*\n/m', '', $current) ?? $current;
    $current = preg_replace('/\n{3,}/', "\n\n", $current) ?? $current;

    // Backup trước khi ghi
    if ($current !== '') {
        file_put_contents($backup, $current, LOCK_EX);
    }

    $had_wp_block = strpos($current, '# BEGIN WordPress') !== false;

    // Xóa block cũ — chỉ khi có đủ cặp BEGIN/END
    $has_begin = strpos($current, $begin) !== false;
    $has_end   = strpos($current, $end)   !== false;
    if ($has_begin && $has_end) {
        $current = preg_replace(
            '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\n?/s',
            '', $current
        );
        if ($current === null) {
            // preg fail → rollback
            if (file_exists($backup)) file_put_contents($htaccess, file_get_contents($backup), LOCK_EX);
            return;
        }
    } elseif ($has_begin && !$has_end) {
        // File corrupt → rollback
        if (file_exists($backup)) {
            $restored = file_get_contents($backup);
            if ($restored !== false) file_put_contents($htaccess, $restored, LOCK_EX);
        }
        return;
    }

    $new_content = adsdefender_st_build_htaccess();
    if ($new_content) {
        $block = "{$begin}\n" . trim($new_content) . "\n{$end}\n";

        // Thứ tự ưu tiên chèn (quan trọng — đảm bảo thứ tự đúng trong file):
        //   1. Sau "# END AdsDefender" (IP block) — SystemTweaks luôn đứng SAU IP block
        //   2. Trước "# BEGIN WordPress" — nếu chưa có AdsDefender IP block
        //   3. Prepend — fallback cuối cùng
        if (strpos($current, '# END AdsDefender') !== false) {
            // Chèn ngay sau END AdsDefender (IP block) — dùng preg_replace limit=1
            $current = preg_replace(
                '/^# END AdsDefender$/m',
                '# END AdsDefender' . "\n" . rtrim($block),
                $current, 1
            );
            if ($current === null) {
                if (file_exists($backup)) file_put_contents($htaccess, file_get_contents($backup), LOCK_EX);
                return;
            }
        } elseif (strpos($current, '# BEGIN WordPress') !== false) {
            // Không có IP block, chèn trước WordPress — dùng preg_replace limit=1
            $current = preg_replace(
                '/^# BEGIN WordPress$/m',
                rtrim($block) . "\n" . '# BEGIN WordPress',
                $current, 1
            );
            if ($current === null) {
                if (file_exists($backup)) file_put_contents($htaccess, file_get_contents($backup), LOCK_EX);
                return;
            }
        } else {
            // Fallback: prepend
            $current = $block . $current;
        }
    }

    // Validate: WordPress block phải còn nếu trước đó có
    if ($had_wp_block && strpos($current, '# BEGIN WordPress') === false) {
        if (file_exists($backup)) file_put_contents($htaccess, file_get_contents($backup), LOCK_EX);
        return;
    }

    file_put_contents($htaccess, $current, LOCK_EX);

    // Validate sau khi ghi
    $written = file_get_contents($htaccess);
    if ($written === false) $written = '';
    $ok = strlen($written) >= 10;
    if ($had_wp_block && strpos($written, '# BEGIN WordPress') === false) $ok = false;
    if (!$ok && file_exists($backup)) {
        file_put_contents($htaccess, file_get_contents($backup), LOCK_EX);
    }
}

function adsdefender_st_remove_htaccess(): void
{
    $server = adsdefender_detect_server_type();
    if ($server === 'nginx') return;

    $htaccess = ABSPATH . '.htaccess';
    $marker   = 'AdsDefender-SystemTweaks';
    $backup   = ABSPATH . '.htaccess.adsdefender.bak';

    if (!file_exists($htaccess)) return;
    $current = file_get_contents($htaccess);
    if ($current === false || $current === '') return;

    // Backup trước
    file_put_contents($backup, $current, LOCK_EX);

    $had_wp_block = strpos($current, '# BEGIN WordPress') !== false;
    $begin = "# BEGIN {$marker}";
    $end   = "# END {$marker}";

    if (strpos($current, $begin) === false) return; // Không có block → không cần xóa

    $new = preg_replace(
        '/' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\n?/s',
        '', $current
    );
    if ($new === null) return;
    if (trim($new) === '' && strlen($current) > 100) return;

    // Validate WordPress block
    if ($had_wp_block && strpos($new, '# BEGIN WordPress') === false) return;

    file_put_contents($htaccess, $new, LOCK_EX);
}

// ─── Hooks ───────────────────────────────────────────────────────────────────

// Rebuild htaccess khi settings thay đổi
add_action('update_option_adsdefender_settings', function ($old, $new) {
    // Chỉ rebuild nếu st_* settings thay đổi
    $st_keys = ['st_enabled','st_protect_files','st_dir_browsing','st_uploads_php','st_plugins_php','st_themes_php'];
    $changed  = false;
    foreach ($st_keys as $k) {
        if (($old[$k] ?? '') !== ($new[$k] ?? '')) { $changed = true; break; }
    }
    if ($changed) adsdefender_st_update_htaccess();
}, 10, 2);
