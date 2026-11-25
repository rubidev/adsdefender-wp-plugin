<?php
/*
 * AdsDefender — Copyright (c) 2026 saigon.pro — https://saigon.pro
 * Ủng hộ tác giả (Donate): https://www.paypal.com/paypalme/dangngocsoncom
 */
/**
 * AdsDefender — Kiểm tra cấu hình .htaccess & wp-config.php
 *
 * Khác với malware-scanner (quét NỘI DUNG file tìm mã độc), phần này kiểm tra
 * CẤU HÌNH: thiếu hằng số bảo mật, quyền file quá rộng, rule .htaccess đáng ngờ.
 * Nhiều lỗ hổng nằm ở chỗ "không có gì" — signature dựa trên regex không thấy được.
 *
 * Kết quả ghi vào cùng bảng của scanner để hiện chung một bảng kết quả, dùng
 * sig_key có tiền tố "cfg_" nên phân biệt được với phát hiện malware.
 */
if (!defined('ABSPATH')) exit;

/**
 * Đọc wp-config.php. WordPress cho phép đặt file ở thư mục cha của ABSPATH,
 * nên phải tìm cả hai chỗ như wp-load.php vẫn làm.
 */
function adsdefender_cfg_wp_config_path(): string
{
    $in_root = ABSPATH . 'wp-config.php';
    if (file_exists($in_root)) return $in_root;

    // Thư mục cha — chỉ hợp lệ khi không có wp-settings.php đi kèm
    $in_parent = dirname(ABSPATH) . '/wp-config.php';
    if (file_exists($in_parent) && !file_exists(dirname(ABSPATH) . '/wp-settings.php')) {
        return $in_parent;
    }
    return '';
}

/**
 * Kiểm tra wp-config.php.
 *
 * Trả về mảng phát hiện: [key => [label, severity, detail]]
 */
function adsdefender_cfg_check_wp_config(): array
{
    $found = [];
    $path  = adsdefender_cfg_wp_config_path();
    if ($path === '' || !is_readable($path)) return $found;

    $src = @file_get_contents($path);
    if ($src === false || $src === '') return $found;

    // ── 1. Salt keys còn để mặc định ──────────────────────────────────────────
    // Bản wp-config-sample.php ghi 'put your unique phrase here'. Ai copy nguyên
    // mẫu mà quên sinh salt thì mọi cookie phiên đều đoán được.
    if (preg_match('/put\s+your\s+unique\s+phrase\s+here/i', $src)) {
        $found['cfg_default_salts'] = [
            'label'    => 'Salt keys còn để mặc định (chưa sinh khóa riêng)',
            'severity' => 'critical',
            'detail'   => 'Sinh khóa mới tại https://api.wordpress.org/secret-key/1.1/salt/',
        ];
    }

    // ── 2. DEBUG bật trên production ──────────────────────────────────────────
    // Chính là nguyên nhân làm lộ đường dẫn máy chủ qua các warning PHP.
    if (preg_match('/define\s*\(\s*[\'"]WP_DEBUG[\'"]\s*,\s*true\s*\)/i', $src)) {
        $has_display_off = preg_match('/define\s*\(\s*[\'"]WP_DEBUG_DISPLAY[\'"]\s*,\s*false\s*\)/i', $src);
        $found['cfg_debug_on'] = [
            'label'    => $has_display_off
                ? 'WP_DEBUG đang bật (đã tắt hiển thị — chấp nhận được)'
                : 'WP_DEBUG bật và đang HIỂN THỊ lỗi ra trang web',
            'severity' => $has_display_off ? 'low' : 'high',
            'detail'   => $has_display_off
                ? 'Lỗi chỉ ghi vào log, không lộ ra ngoài.'
                : 'Lỗi PHP in ra trang làm lộ đường dẫn máy chủ và chặn header. '
                  . 'Đặt WP_DEBUG_DISPLAY = false và WP_DEBUG_LOG = true.',
        ];
    }

    // ── 3. Chưa chặn sửa file qua giao diện admin ─────────────────────────────
    // Chiếm được 1 tài khoản admin là chèn được webshell qua Theme Editor.
    if (!preg_match('/define\s*\(\s*[\'"]DISALLOW_FILE_EDIT[\'"]\s*,\s*true\s*\)/i', $src)) {
        $found['cfg_no_disallow_edit'] = [
            'label'    => 'Chưa tắt trình sửa file trong admin (DISALLOW_FILE_EDIT)',
            'severity' => 'medium',
            'detail'   => "Thêm: define('DISALLOW_FILE_EDIT', true);",
        ];
    }

    // ── 4. Tiền tố bảng mặc định ──────────────────────────────────────────────
    if (preg_match('/\$table_prefix\s*=\s*[\'"]wp_[\'"]/', $src)) {
        $found['cfg_default_prefix'] = [
            'label'    => 'Tiền tố bảng để mặc định wp_',
            'severity' => 'low',
            'detail'   => 'Nhiều mã khai thác SQL injection viết sẵn theo tiền tố wp_.',
        ];
    }

    // ── 5. Có ký tự sau thẻ đóng PHP ──────────────────────────────────────────
    // Đúng lỗi làm "headers already sent" — chặn cookie, redirect và header bảo mật.
    if (preg_match('/\?>\s*\S/s', $src)) {
        $found['cfg_output_after_close'] = [
            'label'    => 'Có nội dung sau thẻ đóng ?> trong wp-config.php',
            'severity' => 'high',
            'detail'   => 'Gây "headers already sent" — hỏng cookie đăng nhập và header bảo mật. '
                        . 'Bỏ hẳn thẻ ?> ở cuối file.',
        ];
    }

    // ── 6. Quyền file quá rộng ────────────────────────────────────────────────
    // Trên shared hosting, world-readable nghĩa là user khác đọc được mật khẩu DB.
    $perms = @fileperms($path);
    if ($perms !== false) {
        $mode = $perms & 0777;
        if ($mode & 0044) {  // nhóm hoặc người khác đọc được
            $found['cfg_perms_wide'] = [
                'label'    => sprintf('wp-config.php quyền quá rộng (%04o)', $mode),
                'severity' => 'high',
                'detail'   => 'File chứa mật khẩu database. Đặt về 0600 hoặc 0640.',
            ];
        }
    }

    return $found;
}

/**
 * Kiểm tra .htaccess.
 *
 * Chỉ chạy khi máy chủ thực sự dùng Apache/LiteSpeed — trên Nginx thì file này
 * không được đọc, báo lỗi ở đó chỉ gây hoang mang.
 */
function adsdefender_cfg_check_htaccess(): array
{
    $found = [];

    $sw = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');
    $is_apache_like = $sw === '' || strpos($sw, 'apache') !== false || strpos($sw, 'litespeed') !== false;
    if (!$is_apache_like) return $found;

    $path = ABSPATH . '.htaccess';
    if (!file_exists($path)) {
        $found['cfg_htaccess_missing'] = [
            'label'    => 'Không có .htaccess ở thư mục gốc',
            'severity' => 'low',
            'detail'   => 'Vào Cài đặt → Đường dẫn tĩnh rồi Lưu để WordPress tạo lại.',
        ];
        return $found;
    }
    if (!is_readable($path)) return $found;

    $src = @file_get_contents($path);
    if ($src === false) return $found;

    // Bỏ dòng comment trước khi dò — comment nhắc tới auto_prepend_file không phải lỗi
    $active = preg_replace('/^\s*#.*$/m', '', $src);

    // ── 1. Nạp file PHP tự động — thủ đoạn cắm backdoor rất phổ biến ──────────
    if (preg_match('/php_value\s+auto_(prepend|append)_file/i', $active, $m)) {
        $found['cfg_ht_auto_prepend'] = [
            'label'    => 'Có auto_' . strtolower($m[1]) . '_file — tự nạp PHP mỗi request',
            'severity' => 'critical',
            'detail'   => 'Thủ đoạn cắm backdoor thường gặp. Kiểm tra kỹ file được nạp; '
                        . 'nếu không phải bạn thêm vào, xóa ngay dòng này.',
        ];
    }

    // ── 2. Chuyển hướng ra ngoài — dấu hiệu chiếm lưu lượng ───────────────────
    if (preg_match('#RewriteRule\s+.*?https?://(?!(?:www\.)?' . preg_quote(parse_url(home_url(), PHP_URL_HOST) ?: '', '#') . ')([^\s/]+)#i', $active, $m)) {
        $found['cfg_ht_external_redirect'] = [
            'label'    => 'RewriteRule chuyển hướng sang tên miền ngoài: ' . substr($m[1], 0, 60),
            'severity' => 'critical',
            'detail'   => 'Kiểu chèn này dùng để cướp lưu lượng từ Google sang trang khác.',
        ];
    }

    // ── 3. Cho phép chạy PHP trong uploads/ ───────────────────────────────────
    if (preg_match('/(AddType|AddHandler)\s+[^\n]*php/i', $active)) {
        $found['cfg_ht_php_handler'] = [
            'label'    => 'Có AddType/AddHandler cho PHP trong .htaccess',
            'severity' => 'high',
            'detail'   => 'Có thể khiến file .jpg, .txt bị chạy như PHP. Kiểm tra lại dòng này.',
        ];
    }

    // ── 4. Bật liệt kê thư mục ────────────────────────────────────────────────
    if (preg_match('/Options\s+[^\n]*(?<![-\w])\+?Indexes/i', $active)
        && !preg_match('/Options\s+[^\n]*-Indexes/i', $active)) {
        $found['cfg_ht_indexes'] = [
            'label'    => 'Bật liệt kê thư mục (Options +Indexes)',
            'severity' => 'medium',
            'detail'   => 'Khách xem được toàn bộ danh sách file. Đổi thành Options -Indexes.',
        ];
    }

    // ── 5. Chưa chặn truy cập trực tiếp wp-config.php ─────────────────────────
    if (!preg_match('/<Files[^>]*wp-config\.php/i', $active)) {
        $found['cfg_ht_no_wpconfig_guard'] = [
            'label'    => 'Chưa chặn truy cập trực tiếp wp-config.php',
            'severity' => 'low',
            'detail'   => 'Thêm khối <Files wp-config.php> Require all denied </Files>. '
                        . 'PHP lỗi cấu hình có thể làm lộ nội dung file.',
        ];
    }

    return $found;
}

/**
 * Chạy toàn bộ kiểm tra cấu hình và ghi vào bảng của scanner.
 *
 * Dùng chung bảng nên kết quả hiện ngay trong danh sách sẵn có. file_path lưu
 * đường dẫn thật để nút "Bỏ qua" và việc dọn kết quả cũ hoạt động như thường.
 */
function adsdefender_cfg_run(): array
{
    if (!function_exists('adsdefender_scan_table_exists')) {
        return ['scanned' => 0, 'found' => 0, 'new' => 0, 'stale' => 0, 'files_infected' => []];
    }
    if (!adsdefender_scan_table_exists()) adsdefender_scan_create_table();

    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_SCAN_TABLE;
    $now   = current_time('mysql');

    $wp_config = adsdefender_cfg_wp_config_path();
    $htaccess  = ABSPATH . '.htaccess';

    $checks = [
        $wp_config !== '' ? $wp_config : ABSPATH . 'wp-config.php' => adsdefender_cfg_check_wp_config(),
        $htaccess                                                  => adsdefender_cfg_check_htaccess(),
    ];

    $summary   = ['scanned' => 0, 'found' => 0, 'new' => 0, 'stale' => 0, 'files_infected' => []];
    $still_hit = [];

    foreach ($checks as $path => $hits) {
        $summary['scanned']++;
        foreach ($hits as $key => $hit) {
            $still_hit[] = $path . '|' . $key;
            $summary['found']++;

            $rel = str_replace(ABSPATH, '', $path);
            $summary['files_infected'][$path] = $rel;

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE file_path = %s AND sig_key = %s", $path, $key
            ));
            if ($existing) {
                $wpdb->update($table, ['last_seen' => $now], ['id' => $existing->id]);
            } else {
                $summary['new']++;
                $wpdb->insert($table, [
                    'file_path'  => $path,
                    'sig_key'    => $key,
                    'severity'   => $hit['severity'],
                    'label'      => substr($hit['label'], 0, 200),
                    'line_no'    => 0,
                    'snippet'    => substr($hit['detail'], 0, 300),
                    'status'     => 'new',
                    'first_seen' => $now,
                    'last_seen'  => $now,
                ]);
                if (function_exists('adsdefender_sec_log')) {
                    adsdefender_sec_log('config_issue', '', "[{$hit['severity']}] {$hit['label']} in {$rel}");
                }
            }
        }
    }

    // Dọn các cảnh báo cấu hình không còn đúng (đã sửa xong thì phải biến mất).
    // Chỉ đụng tới record có sig_key bắt đầu bằng cfg_ để không xóa nhầm malware.
    $rows = $wpdb->get_results(
        "SELECT id, file_path, sig_key FROM `{$table}`
         WHERE status != 'ignored' AND sig_key LIKE 'cfg\\_%'"
    );
    $keep = array_flip($still_hit);
    $drop = [];
    foreach ($rows as $r) {
        if (!isset($keep[$r->file_path . '|' . $r->sig_key])) $drop[] = (int) $r->id;
    }
    if ($drop) {
        $wpdb->query("DELETE FROM `{$table}` WHERE id IN (" . implode(',', $drop) . ")");
        $summary['stale'] = count($drop);
    }

    $summary['files_infected'] = array_values($summary['files_infected']);
    $summary['next_offset']    = null;
    return $summary;
}

// ─── Tự động tối ưu wp-config.php ────────────────────────────────────────────
//
// Ghi vào file quan trọng nhất của site: sai một dấu chấm phẩy là trắng trang.
// Nguyên tắc an toàn áp dụng ở đây:
//   1. Sao lưu trước, luôn luôn
//   2. Chỉ sửa những hằng số ĐỌC ĐƯỢC và HIỂU RÕ, không đụng phần còn lại
//   3. Kiểm tra cú pháp bản mới TRƯỚC khi thay thế file thật
//   4. Nếu bản mới hỏng → khôi phục ngay từ bản sao lưu
//   5. Không bao giờ tự động đổi salt keys (đăng xuất toàn bộ người dùng)

/**
 * Các hằng số sẽ được đặt, kèm lý do hiển thị cho người dùng.
 */
function adsdefender_cfg_optimize_targets(): array
{
    return [
        'WP_DEBUG_DISPLAY' => ['value' => 'false', 'reason' => 'Không in lỗi PHP ra trang web (lộ đường dẫn máy chủ, chặn header)'],
        'WP_DEBUG_LOG'     => ['value' => 'true',  'reason' => 'Vẫn ghi lỗi vào file log để chẩn đoán'],
        'DISALLOW_FILE_EDIT' => ['value' => 'true', 'reason' => 'Chặn sửa theme/plugin qua admin (đường cắm webshell phổ biến)'],
        'WP_AUTO_UPDATE_CORE' => ['value' => "'minor'", 'reason' => 'Tự cập nhật bản vá bảo mật của WordPress'],
    ];
}

/**
 * Sinh nội dung wp-config.php mới.
 *
 * Với hằng số đã có: sửa giá trị tại chỗ, giữ nguyên định dạng xung quanh.
 * Với hằng số chưa có: chèn ngay trước dòng "That's all, stop editing" —
 * đúng chỗ WordPress dành cho tùy chỉnh, luôn nằm trước khi wp-settings.php nạp.
 *
 * @return array{content:string, changes:array}  content rỗng nghĩa là không cần đổi gì
 */
function adsdefender_cfg_optimize_build(string $src): array
{
    $changes = [];
    $out     = $src;

    foreach (adsdefender_cfg_optimize_targets() as $const => $spec) {
        $want = $spec['value'];

        // Đã định nghĩa chưa? Bắt cả dạng define('X', y) lẫn define( "X" , y )
        $re = '/define\s*\(\s*([\'"])' . preg_quote($const, '/') . '\1\s*,\s*([^)]*?)\s*\)\s*;/i';

        if (preg_match($re, $out, $m)) {
            $current = trim($m[2]);
            if (strcasecmp($current, $want) === 0) continue;  // đã đúng, bỏ qua

            $out = preg_replace($re, "define('{$const}', {$want});", $out, 1);
            $changes[] = [
                'const'  => $const,
                'from'   => $current,
                'to'     => $want,
                'reason' => $spec['reason'],
            ];
        } else {
            $line = "define('{$const}', {$want});";
            $anchor = '/^([ \t]*\/\*.*?stop editing.*?\*\/)/mis';
            if (preg_match($anchor, $out)) {
                $out = preg_replace($anchor, $line . "\n\n$1", $out, 1);
            } else {
                // Không có mốc quen thuộc — chèn trước require wp-settings.php
                $req = '/^([ \t]*(?:require|include)(?:_once)?\s*[\s(].*wp-settings\.php.*$)/mi';
                if (preg_match($req, $out)) {
                    $out = preg_replace($req, $line . "\n\n$1", $out, 1);
                } else {
                    continue;  // không xác định được chỗ an toàn → bỏ qua hằng số này
                }
            }
            $changes[] = [
                'const'  => $const,
                'from'   => '(chưa có)',
                'to'     => $want,
                'reason' => $spec['reason'],
            ];
        }
    }

    // Bỏ ký tự thừa sau thẻ đóng PHP — nguyên nhân "headers already sent"
    if (preg_match('/\?>\s*\S/s', $out)) {
        // Cắt từ thẻ đóng CUỐI CÙNG trở đi. Không dùng neo $ vì khi có rác phía
        // sau thì không khớp, thẻ đóng sẽ nằm lại nguyên.
        // Ghép chuỗi từ 2 phần: viết thẳng thẻ đóng vào đây sẽ thoát khỏi chế độ
        // PHP ngay giữa file, kể cả khi nó nằm trong dấu nháy.
        $close = '?' . '>';
        $pos   = strrpos($out, $close);
        if ($pos !== false) $out = rtrim(substr($out, 0, $pos)) . "\n";
        $changes[] = [
            'const'  => 'thẻ đóng PHP',
            'from'   => 'có ký tự thừa sau thẻ đóng',
            'to'     => 'đã bỏ thẻ đóng',
            'reason' => 'Ký tự sau thẻ đóng gây "headers already sent", hỏng cookie và header bảo mật',
        ];
    }

    return ['content' => $changes ? $out : '', 'changes' => $changes];
}

/**
 * Kiểm tra cú pháp PHP của một chuỗi mã, không thực thi nó.
 *
 * Ưu tiên php -l qua CLI. Nhiều hosting tắt proc_open/exec, khi đó lùi về
 * token_get_all() — bắt được lỗi cú pháp cơ bản, đủ để chặn trường hợp tệ nhất.
 */
function adsdefender_cfg_syntax_ok(string $code, ?string &$error = null): bool
{
    $error = null;

    // Cách 1: php -l (chính xác nhất)
    if (function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        $tmp = wp_tempnam('adsdef-cfg');
        if ($tmp && @file_put_contents($tmp, $code) !== false) {
            $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
            $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $p = @proc_open(escapeshellarg($php) . ' -l ' . escapeshellarg($tmp), $desc, $pipes);
            if (is_resource($p)) {
                $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                $code_ret = proc_close($p);
                @unlink($tmp);
                if ($code_ret !== 0) {
                    $error = trim($out);
                    return false;
                }
                return true;
            }
            @unlink($tmp);
        }
    }

    // Cách 2: token_get_all — ném ParseError khi cú pháp sai
    try {
        $prev = error_reporting(0);
        token_get_all($code, TOKEN_PARSE);
        error_reporting($prev);
        return true;
    } catch (ParseError $e) {
        $error = $e->getMessage();
        return false;
    } catch (Throwable $e) {
        // Không kết luận được → coi như không an toàn
        $error = 'Không kiểm tra được cú pháp: ' . $e->getMessage();
        return false;
    }
}

/**
 * Thực hiện tối ưu: sao lưu → ghi bản mới → kiểm tra → khôi phục nếu hỏng.
 *
 * @param bool $dry_run true = chỉ xem trước thay đổi, không ghi gì
 * @return array{ok:bool, message:string, changes:array, backup:string}
 */
function adsdefender_cfg_optimize(bool $dry_run = false): array
{
    $fail = function (string $msg) {
        return ['ok' => false, 'message' => $msg, 'changes' => [], 'backup' => ''];
    };

    $path = adsdefender_cfg_wp_config_path();
    if ($path === '')            return $fail('Không tìm thấy wp-config.php');
    if (!is_readable($path))     return $fail('Không đọc được wp-config.php');

    $src = @file_get_contents($path);
    if ($src === false || $src === '') return $fail('wp-config.php rỗng hoặc không đọc được');

    $built = adsdefender_cfg_optimize_build($src);
    if (empty($built['changes'])) {
        return ['ok' => true, 'message' => 'wp-config.php đã tối ưu sẵn, không cần thay đổi.', 'changes' => [], 'backup' => ''];
    }

    if ($dry_run) {
        return ['ok' => true, 'message' => 'Xem trước ' . count($built['changes']) . ' thay đổi.', 'changes' => $built['changes'], 'backup' => ''];
    }

    // Cú pháp bản mới phải sạch TRƯỚC khi đụng vào file thật
    $syntax_error = null;
    if (!adsdefender_cfg_syntax_ok($built['content'], $syntax_error)) {
        return $fail('Bản mới có lỗi cú pháp nên đã hủy, file gốc không bị đụng tới. Chi tiết: ' . $syntax_error);
    }

    if (!is_writable($path)) return $fail('wp-config.php không có quyền ghi. Sửa thủ công hoặc đổi quyền file.');

    // Sao lưu — đặt cạnh file gốc, tên có mốc thời gian
    $backup = dirname($path) . '/wp-config.adsdefender-backup-' . gmdate('Ymd-His') . '.php';
    if (@copy($path, $backup) === false) {
        return $fail('Không tạo được bản sao lưu nên đã dừng lại (không sửa gì).');
    }

    if (@file_put_contents($path, $built['content']) === false) {
        @unlink($backup);
        return $fail('Không ghi được wp-config.php.');
    }

    // Đọc lại từ đĩa và kiểm tra lần nữa — phòng trường hợp ghi thiếu
    $written = @file_get_contents($path);
    if ($written === false || $written !== $built['content'] || !adsdefender_cfg_syntax_ok($written, $syntax_error)) {
        @copy($backup, $path);   // khôi phục
        return $fail('File ghi ra không hợp lệ nên đã khôi phục bản cũ. Chi tiết: ' . (string)$syntax_error);
    }

    if (function_exists('adsdefender_sec_log')) {
        adsdefender_sec_log('config_optimize', '',
            'Đã tối ưu wp-config.php: ' . implode(', ', array_column($built['changes'], 'const')));
    }
    update_option('adsdefender_cfg_last_optimize', [
        'time'    => current_time('mysql'),
        'backup'  => basename($backup),
        'changes' => $built['changes'],
    ], false);

    return [
        'ok'      => true,
        'message' => 'Đã áp dụng ' . count($built['changes']) . ' thay đổi. Bản sao lưu: ' . basename($backup),
        'changes' => $built['changes'],
        'backup'  => basename($backup),
    ];
}

// ─── AJAX ────────────────────────────────────────────────────────────────────

add_action('wp_ajax_adsdefender_cfg_optimize', function () {
    check_ajax_referer('adsdefender_cfg_optimize', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Không đủ quyền');

    $dry = !empty($_POST['preview']);
    try {
        $res = adsdefender_cfg_optimize($dry);
    } catch (Throwable $e) {
        wp_send_json_error('Lỗi: ' . $e->getMessage());
        return;
    }

    if (empty($res['ok'])) wp_send_json_error($res['message']);
    wp_send_json_success($res);
});
