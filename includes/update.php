<?php
if (!defined('ABSPATH')) exit;

function adsdefender_fetch_update_info(): ?array
{
    $cached = get_transient('adsdefender_update_info');
    if ($cached !== false) return $cached;

    $response = wp_remote_get(ADSDEFENDER_UPDATE_URL, [
        'timeout'   => 10,
        'sslverify' => adsdefender_sslverify(),
    ]);
    if (is_wp_error($response)) return null;
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data['version'])) return null;

    set_transient('adsdefender_update_info', $data, HOUR_IN_SECONDS);
    return $data;
}

// ─── Đổi tên thư mục khi giải nén ────────────────────────────────────────────
// ZIP của GitHub giải nén ra "adsdefender-wp-plugin-2.5.109/" (tên repo + tag),
// nhưng WordPress cần đúng thư mục "adsdefender/". Không đổi tên thì mỗi lần
// cập nhật sẽ tạo ra một plugin mới thay vì ghi đè bản cũ.

add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $args = []) {
    if (empty($args['plugin']) || $args['plugin'] !== ADSDEFENDER_PLUGIN_SLUG) return $source;

    $desired = trailingslashit($remote_source) . 'adsdefender';
    if (untrailingslashit($source) === $desired) return $source;

    global $wp_filesystem;
    if (!$wp_filesystem) return $source;

    if ($wp_filesystem->is_dir($desired)) $wp_filesystem->delete($desired, true);
    if (!$wp_filesystem->move($source, $desired)) {
        return new WP_Error('adsdefender_rename_failed',
            'Không đổi được tên thư mục plugin khi giải nén.');
    }
    return trailingslashit($desired);
}, 10, 4);

// ─── Inject vào WP update transient ──────────────────────────────────────────

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) return $transient;

    $data = adsdefender_fetch_update_info();
    if (!$data || empty($data['download_url'])) return $transient;

    if (version_compare($data['version'], ADSDEFENDER_VERSION, '>')) {
        $transient->response[ADSDEFENDER_PLUGIN_SLUG] = (object) [
            'id'            => 'adsdefender/adsdefender',
            'slug'          => 'adsdefender',
            'plugin'        => ADSDEFENDER_PLUGIN_SLUG,
            'new_version'   => $data['version'],
            'url'           => $data['details_url'] ?? ADSDEFENDER_UPDATE_URL,
            'package'       => $data['download_url'],
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'requires'      => '5.6',
            'tested'        => '6.8',
            'requires_php'  => '7.4',
        ];
    } else {
        // Đang dùng bản mới nhất — xóa khỏi response để không hiện badge lỗi
        unset($transient->response[ADSDEFENDER_PLUGIN_SLUG]);
        $transient->no_update[ADSDEFENDER_PLUGIN_SLUG] = (object) [
            'id'          => 'adsdefender/adsdefender',
            'slug'        => 'adsdefender',
            'plugin'      => ADSDEFENDER_PLUGIN_SLUG,
            'new_version' => $data['version'],
            'url'         => ADSDEFENDER_UPDATE_URL,
            'package'     => '',
            'icons'       => [],
            'banners'     => [],
        ];
    }

    return $transient;
});

// ─── Plugin details popup ─────────────────────────────────────────────────────

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;
    if (($args->slug ?? '') !== 'adsdefender') return $result;

    $data = adsdefender_fetch_update_info();
    if (!$data) return $result;

    return (object) [
        'name'          => 'AdsDefender',
        'slug'          => 'adsdefender',
        'version'       => $data['version'],
        'author'        => 'AdsDefender',
        'requires'      => '5.6',
        'tested'        => '6.8',
        'requires_php'  => '7.4',
        'download_link' => $data['download_url'] ?? '',
        'trunk'         => $data['download_url'] ?? '',
        'sections'      => [
            'changelog' => '<pre>' . esc_html($data['changelog'] ?? '') . '</pre>',
        ],
        'banners'       => [],
        'icons'         => [],
    ];
}, 10, 3);

// ─── 1-click update từ tab Hệ thống → Cập nhật ───────────────────────────────

add_action('admin_post_adsdefender_do_update', function () {
    if (!current_user_can('update_plugins')) wp_die('Forbidden');
    check_admin_referer('adsdefender_do_update');

    delete_transient('adsdefender_update_info');
    $data = adsdefender_fetch_update_info();

    if (!$data || empty($data['download_url'])) {
        wp_redirect(adsdefender_update_redirect('fetch_failed'));
        exit;
    }
    if (!version_compare($data['version'], ADSDEFENDER_VERSION, '>')) {
        wp_redirect(adsdefender_update_redirect('already_latest'));
        exit;
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';

    // Inject vào transient để upgrader nhận package
    $update_obj = (object) [
        'slug'        => 'adsdefender',
        'plugin'      => ADSDEFENDER_PLUGIN_SLUG,
        'new_version' => $data['version'],
        'package'     => $data['download_url'],
        'url'         => ADSDEFENDER_UPDATE_URL,
        'requires_php'=> '7.4',
    ];
    $current = get_site_transient('update_plugins') ?: (object) ['response' => []];
    $current->response[ADSDEFENDER_PLUGIN_SLUG] = $update_obj;
    set_site_transient('update_plugins', $current);

    $skin     = new \Automatic_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);
    $result   = $upgrader->upgrade(ADSDEFENDER_PLUGIN_SLUG, ['clear_update_cache' => true]);

    // Fallback: nếu upgrade fail (quyền ghi, conflict...) thì install fresh
    if (is_wp_error($result) || $result === false || $result === null) {
        $plugin_dir = WP_PLUGIN_DIR . '/adsdefender';
        if (is_dir($plugin_dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($plugin_dir);
        }
        $result2 = $upgrader->install($data['download_url']);
        if (is_wp_error($result2) || $result2 === false) {
            activate_plugin(ADSDEFENDER_PLUGIN_SLUG);
            wp_redirect(adsdefender_update_redirect('failed'));
            exit;
        }
    }

    activate_plugin(ADSDEFENDER_PLUGIN_SLUG);

    wp_redirect(adsdefender_update_redirect('success', $data['version']));
    exit;
});

function adsdefender_update_redirect(string $status, string $version = ''): string
{
    $args = ['page' => 'adsdefender-system', 'tab' => 'update', 'update' => $status];
    if ($version) $args['new_version'] = $version;
    return add_query_arg($args, admin_url('admin.php'));
}

// ─── WordPress background auto-update ────────────────────────────────────────
// WP chạy tự động qua wp-cron mỗi 12h — không cần admin vào bấm gì.
// Chỉ bật khi setting auto_update = true (default: true).

add_filter('auto_update_plugin', function ($update, $item) {
    if (isset($item->plugin) && $item->plugin === ADSDEFENDER_PLUGIN_SLUG) {
        $opts = adsdefender_settings();
        // Mặc định bật nếu chưa set
        return ($opts['auto_update'] ?? '1') !== '0';
    }
    return $update;
}, 10, 2);

// ─── Badge update count trong admin menu ─────────────────────────────────────

add_action('admin_menu', function () {
    $data = adsdefender_fetch_update_info();
    if (!$data) return;
    if (!version_compare($data['version'], ADSDEFENDER_VERSION, '>')) return;

    global $menu;
    foreach ($menu as &$item) {
        if (isset($item[2]) && $item[2] === 'adsdefender') {
            $item[0] .= ' <span class="update-plugins count-1"><span class="update-count">1</span></span>';
            break;
        }
    }
}, 999);
