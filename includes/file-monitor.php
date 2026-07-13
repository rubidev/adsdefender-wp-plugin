<?php
/**
 * AdsDefender — File Change Detection
 *
 * Scan định kỳ các file WP quan trọng, so sánh hash SHA-256 với baseline.
 * Alert khi có file mới, bị sửa, hoặc bị xóa.
 *
 * Cron: chạy cùng ADSDEFENDER_CRON_HOOK (30 phút), có thể trigger thủ công.
 * DB:   {prefix}adsdefender_file_monitor — lưu baseline hash
 * Log:  ghi vào security_log với event_type = 'file_change'
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_FM_TABLE', 'adsdefender_file_monitor');

// ─── Create Table ─────────────────────────────────────────────────────────────

function adsdefender_fm_create_table(): void
{
    global $wpdb;
    $table   = $wpdb->prefix . ADSDEFENDER_FM_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_path  VARCHAR(500) NOT NULL,
        file_hash  CHAR(64)     NOT NULL,
        file_size  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        scanned_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_path (file_path(500))
    ) {$charset};");
}

// ─── Settings helpers ─────────────────────────────────────────────────────────

function adsdefender_fm_enabled(): bool
{
    return !empty(adsdefender_settings()['fm_enabled']);
}

function adsdefender_fm_scan_dirs(): array
{
    // Các thư mục/file cần monitor — không scan uploads (quá nhiều file media)
    return [
        ABSPATH . 'wp-config.php',
        ABSPATH . 'index.php',
        ABSPATH . '.htaccess',
        ABSPATH . 'wp-login.php',
        ABSPATH . 'wp-includes/',
        ABSPATH . 'wp-admin/',
        WP_CONTENT_DIR . '/themes/',
        WP_CONTENT_DIR . '/plugins/',
    ];
}

function adsdefender_fm_get_scan_depth(): int
{
    return (int)(adsdefender_settings()['fm_depth'] ?? 3);
}

// ─── Collect files recursively ───────────────────────────────────────────────

function adsdefender_fm_collect_files(string $path, int $max_depth, int $depth = 0): array
{
    $files = [];
    if ($depth > $max_depth) return $files;

    if (is_file($path)) {
        return [$path];
    }

    if (!is_dir($path) || !is_readable($path)) return $files;

    $items = @scandir($path);
    if (!$items) return $files;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = rtrim($path, '/') . '/' . $item;

        // Skip uploads, cache, backup dirs
        if (is_dir($full)) {
            $base = basename($full);
            if (in_array($base, ['uploads', 'cache', 'litespeed', 'backup', 'backups', '.git', 'node_modules'], true)) continue;
            $files = array_merge($files, adsdefender_fm_collect_files($full, $max_depth, $depth + 1));
        } elseif (is_file($full)) {
            // Chỉ scan file PHP, JS, htaccess, config
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if (in_array($ext, ['php', 'js', 'htaccess', 'html', 'htm', 'cfg', 'conf', 'ini'], true)
                || $item === '.htaccess' || $item === 'wp-config.php') {
                $files[] = $full;
            }
        }
    }
    return $files;
}

// ─── Hash single file ─────────────────────────────────────────────────────────

function adsdefender_fm_hash_file(string $path): ?string
{
    if (!is_readable($path) || !is_file($path)) return null;
    // Skip files > 5MB
    if (filesize($path) > 5 * 1024 * 1024) return null;
    return hash_file('sha256', $path) ?: null;
}

// ─── Load baseline from DB ───────────────────────────────────────────────────

function adsdefender_fm_load_baseline(): array
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_FM_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return [];

    $rows = $wpdb->get_results("SELECT file_path, file_hash, file_size FROM `{$table}`", ARRAY_A);
    $baseline = [];
    foreach ($rows as $row) {
        $baseline[$row['file_path']] = ['hash' => $row['file_hash'], 'size' => (int)$row['file_size']];
    }
    return $baseline;
}

// ─── Save baseline to DB ─────────────────────────────────────────────────────

function adsdefender_fm_save_baseline(array $current_files): void
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_FM_TABLE;
    $now   = current_time('mysql');

    foreach ($current_files as $path => $info) {
        $wpdb->replace($table, [
            'file_path'  => $path,
            'file_hash'  => $info['hash'],
            'file_size'  => $info['size'],
            'scanned_at' => $now,
        ]);
    }

    // Xóa records của file đã không còn tồn tại
    if (!empty($current_files)) {
        $placeholders = implode(',', array_fill(0, count($current_files), '%s'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table}` WHERE file_path NOT IN ({$placeholders})",
            ...array_keys($current_files)
        ));
    }
}

// ─── Main scan ────────────────────────────────────────────────────────────────

function adsdefender_fm_scan(bool $rebuild_baseline = false): array
{
    if (!adsdefender_fm_create_table_if_needed()) return [];

    $scan_targets = adsdefender_fm_scan_dirs();
    $depth        = adsdefender_fm_get_scan_depth();
    $all_files    = [];

    foreach ($scan_targets as $target) {
        foreach (adsdefender_fm_collect_files($target, $depth) as $file) {
            $hash = adsdefender_fm_hash_file($file);
            if ($hash === null) continue;
            $all_files[$file] = [
                'hash' => $hash,
                'size' => (int)@filesize($file),
            ];
        }
    }

    if ($rebuild_baseline) {
        adsdefender_fm_save_baseline($all_files);
        update_option('adsdefender_fm_baseline_built', current_time('mysql'), false);
        update_option('adsdefender_fm_last_scan', current_time('mysql'), false);
        return [];
    }

    $baseline = adsdefender_fm_load_baseline();

    // Nếu chưa có baseline → build lần đầu, không alert
    if (empty($baseline)) {
        adsdefender_fm_save_baseline($all_files);
        update_option('adsdefender_fm_baseline_built', current_time('mysql'), false);
        update_option('adsdefender_fm_last_scan', current_time('mysql'), false);
        return [];
    }

    $changes = [];

    // Tìm file mới hoặc bị sửa
    foreach ($all_files as $path => $info) {
        // Normalize path relative to ABSPATH for display
        $rel = str_replace(ABSPATH, '', $path);

        if (!isset($baseline[$path])) {
            $changes[] = ['type' => 'added',   'path' => $rel, 'full' => $path];
        } elseif ($baseline[$path]['hash'] !== $info['hash']) {
            $changes[] = ['type' => 'modified', 'path' => $rel, 'full' => $path,
                          'old_hash' => $baseline[$path]['hash'], 'new_hash' => $info['hash']];
        }
    }

    // Tìm file bị xóa
    foreach ($baseline as $path => $info) {
        if (!isset($all_files[$path])) {
            $rel = str_replace(ABSPATH, '', $path);
            $changes[] = ['type' => 'deleted', 'path' => $rel, 'full' => $path];
        }
    }

    update_option('adsdefender_fm_last_scan', current_time('mysql'), false);
    update_option('adsdefender_fm_last_changes', count($changes), false);

    // Log changes vào security_log
    if (!empty($changes)) {
        foreach ($changes as $c) {
            $label = $c['type'] === 'added' ? '🆕 File mới' : ($c['type'] === 'deleted' ? '🗑 File xóa' : '✏️ File sửa');
            adsdefender_sec_log('file_change', '', "[{$c['type']}] {$c['path']}");
        }
        update_option('adsdefender_fm_last_alert', current_time('mysql'), false);
    }

    // Update baseline với trạng thái hiện tại
    adsdefender_fm_save_baseline($all_files);

    return $changes;
}

function adsdefender_fm_create_table_if_needed(): bool
{
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_FM_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        adsdefender_fm_create_table();
    }
    return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
}

// ─── Cron hook ────────────────────────────────────────────────────────────────

add_action(ADSDEFENDER_CRON_HOOK, function () {
    if (!adsdefender_fm_enabled()) return;
    adsdefender_fm_scan();
}, 20);

// ─── AJAX: manual scan + rebuild baseline ────────────────────────────────────

add_action('wp_ajax_adsdefender_fm_scan', function () {
    check_ajax_referer('adsdefender_fm_scan', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $rebuild = !empty($_POST['rebuild']);
    $changes = adsdefender_fm_scan($rebuild);

    if ($rebuild) {
        wp_send_json_success(['message' => '✅ Đã rebuild baseline từ trạng thái file hiện tại.', 'changes' => []]);
    }

    wp_send_json_success([
        'message' => empty($changes)
            ? '✅ Không có thay đổi nào.'
            : '⚠️ Phát hiện ' . count($changes) . ' thay đổi!',
        'changes' => $changes,
        'count'   => count($changes),
    ]);
});
