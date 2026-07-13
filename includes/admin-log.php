<?php
/**
 * AdsDefender — Admin Activity Log
 *
 * Ghi lại mọi hành động quan trọng của admin:
 *   login_ok | login_fail | logout
 *   settings_change | plugin_activate | plugin_deactivate
 *   post_create | post_update | post_delete | media_upload | media_delete
 *   user_create | user_update | user_delete | user_role_change
 */
if (!defined('ABSPATH')) exit;

define('ADSDEFENDER_ADMIN_LOG_TABLE', 'adsdefender_admin_log');

// ─── Create Table ─────────────────────────────────────────────────────────────

function adsdefender_create_admin_log_table(): void
{
    global $wpdb;
    $table   = $wpdb->prefix . ADSDEFENDER_ADMIN_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type  VARCHAR(30)  NOT NULL,
        user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_login  VARCHAR(60)  NOT NULL DEFAULT '',
        ip          VARCHAR(45)  NOT NULL DEFAULT '',
        object_type VARCHAR(30)  NOT NULL DEFAULT '',
        object_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        object_name VARCHAR(300) NOT NULL DEFAULT '',
        detail      VARCHAR(500) NOT NULL DEFAULT '',
        created_at  DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_type_time  (event_type, created_at),
        KEY idx_user_time  (user_id, created_at),
        KEY idx_time       (created_at)
    ) {$charset};");
}

// ─── Write Event ──────────────────────────────────────────────────────────────

function adsdefender_admin_log(
    string $event_type,
    string $detail       = '',
    string $object_type  = '',
    int    $object_id    = 0,
    string $object_name  = ''
): void {
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_ADMIN_LOG_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

    $user       = wp_get_current_user();
    $user_id    = $user ? (int)$user->ID : 0;
    $user_login = $user ? $user->user_login : '';
    $ip         = adsdefender_get_real_ip();

    $wpdb->insert($table, [
        'event_type'  => substr($event_type, 0, 30),
        'user_id'     => $user_id,
        'user_login'  => substr($user_login, 0, 60),
        'ip'          => substr($ip, 0, 45),
        'object_type' => substr($object_type, 0, 30),
        'object_id'   => $object_id,
        'object_name' => substr($object_name, 0, 300),
        'detail'      => substr($detail, 0, 500),
        'created_at'  => current_time('mysql'),
    ]);

    // Cleanup 1%: giữ tối đa 20k dòng
    if (mt_rand(1, 100) === 1) {
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($count > 20000) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` ORDER BY id ASC LIMIT %d",
                $count - 20000
            ));
        }
    }
}

// ─── Helper: Settings enabled ─────────────────────────────────────────────────

function adsdefender_admin_log_enabled(): bool
{
    return !empty(adsdefender_settings()['admin_log_enabled']);
}

// ─── LOGIN / LOGOUT ───────────────────────────────────────────────────────────

add_action('wp_login', function (string $user_login, WP_User $user) {
    if (!adsdefender_admin_log_enabled()) return;
    if (!in_array('administrator', (array)$user->roles) && !user_can($user, 'manage_options')) return;
    adsdefender_admin_log('login_ok', "Login: {$user_login}", 'user', $user->ID, $user_login);
}, 10, 2);

add_action('wp_login_failed', function (string $username) {
    if (!adsdefender_admin_log_enabled()) return;
    global $wpdb;
    $table = $wpdb->prefix . ADSDEFENDER_ADMIN_LOG_TABLE;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;
    $ip = adsdefender_get_real_ip();
    $wpdb->insert($table, [
        'event_type'  => 'login_fail',
        'user_id'     => 0,
        'user_login'  => substr($username, 0, 60),
        'ip'          => substr($ip, 0, 45),
        'object_type' => 'user',
        'object_id'   => 0,
        'object_name' => substr($username, 0, 300),
        'detail'      => "Login failed: {$username}",
        'created_at'  => current_time('mysql'),
    ]);
});

add_action('wp_logout', function (int $user_id) {
    if (!adsdefender_admin_log_enabled()) return;
    $user = get_userdata($user_id);
    if (!$user) return;
    if (!in_array('administrator', (array)$user->roles) && !user_can($user, 'manage_options')) return;
    adsdefender_admin_log('logout', "Logout: {$user->user_login}", 'user', $user_id, $user->user_login);
}, 10, 1);

// ─── SETTINGS CHANGE ─────────────────────────────────────────────────────────

add_action('updated_option', function (string $option, $old, $new) {
    if (!adsdefender_admin_log_enabled()) return;
    // Chỉ ghi các option quan trọng
    $tracked = [
        'adsdefender_settings'        => 'AdsDefender Settings',
        'blogname'                    => 'Site Title',
        'blogdescription'             => 'Site Tagline',
        'siteurl'                     => 'Site URL',
        'home'                        => 'Home URL',
        'admin_email'                 => 'Admin Email',
        'default_role'                => 'Default Role',
        'users_can_register'          => 'Registration',
        'permalink_structure'         => 'Permalinks',
        'active_plugins'              => 'Active Plugins',
    ];
    if (!isset($tracked[$option])) return;
    if (!is_user_logged_in()) return;

    $detail = $tracked[$option];
    // Plugin activate/deactivate
    if ($option === 'active_plugins') {
        $added   = array_diff((array)$new, (array)$old);
        $removed = array_diff((array)$old, (array)$new);
        if ($added)   adsdefender_admin_log('plugin_activate',   'Activated: '   . implode(', ', $added),   'plugin');
        if ($removed) adsdefender_admin_log('plugin_deactivate', 'Deactivated: ' . implode(', ', $removed), 'plugin');
        return;
    }

    $old_str = is_scalar($old) ? (string)$old : json_encode($old);
    $new_str = is_scalar($new) ? (string)$new : json_encode($new);
    if ($old_str === $new_str) return;

    adsdefender_admin_log('settings_change', "{$detail}: [{$old_str}] → [{$new_str}]", 'option', 0, $option);
}, 10, 3);

// ─── POST / PAGE / CUSTOM POST TYPE ──────────────────────────────────────────

add_action('post_updated', function (int $post_id, WP_Post $after, WP_Post $before) {
    if (!adsdefender_admin_log_enabled()) return;
    if (!is_user_logged_in()) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    $type  = $after->post_type;
    $title = $after->post_title ?: "(no title)";

    if ($before->post_status === 'auto-draft' || $before->post_status === 'new') {
        adsdefender_admin_log('post_create', "Created {$type}: {$title}", $type, $post_id, $title);
    } else {
        adsdefender_admin_log('post_update', "Updated {$type}: {$title}", $type, $post_id, $title);
    }
}, 10, 3);

add_action('before_delete_post', function (int $post_id, WP_Post $post) {
    if (!adsdefender_admin_log_enabled()) return;
    if (!is_user_logged_in()) return;
    if (wp_is_post_revision($post_id)) return;
    $title = $post->post_title ?: "(no title)";
    adsdefender_admin_log('post_delete', "Deleted {$post->post_type}: {$title}", $post->post_type, $post_id, $title);
}, 10, 2);

// ─── MEDIA ───────────────────────────────────────────────────────────────────

add_action('add_attachment', function (int $att_id) {
    if (!adsdefender_admin_log_enabled()) return;
    if (!is_user_logged_in()) return;
    $file = get_attached_file($att_id);
    $name = $file ? basename($file) : "attachment #{$att_id}";
    adsdefender_admin_log('media_upload', "Uploaded: {$name}", 'attachment', $att_id, $name);
});

add_action('delete_attachment', function (int $att_id) {
    if (!adsdefender_admin_log_enabled()) return;
    if (!is_user_logged_in()) return;
    $post = get_post($att_id);
    $name = $post ? ($post->post_title ?: basename(get_attached_file($att_id) ?: '')) : "#{$att_id}";
    adsdefender_admin_log('media_delete', "Deleted media: {$name}", 'attachment', $att_id, $name);
});

// ─── USER MANAGEMENT ─────────────────────────────────────────────────────────

add_action('user_register', function (int $user_id) {
    if (!adsdefender_admin_log_enabled()) return;
    if (!is_user_logged_in()) return;
    $user = get_userdata($user_id);
    if (!$user) return;
    adsdefender_admin_log('user_create', "Created user: {$user->user_login} ({$user->user_email})", 'user', $user_id, $user->user_login);
});

add_action('profile_update', function (int $user_id, WP_User $old_data) {
    if (!adsdefender_admin_log_enabled()) return;
    if (!is_user_logged_in()) return;
    // Bỏ qua tự cập nhật session token
    if (get_current_user_id() === $user_id && !isset($_POST['role'])) return;

    $user = get_userdata($user_id);
    if (!$user) return;

    $changes = [];
    if ($old_data->user_email !== $user->user_email) {
        $changes[] = "email: {$old_data->user_email} → {$user->user_email}";
    }
    $old_role = implode(',', (array)$old_data->roles);
    $new_role = implode(',', (array)$user->roles);
    if ($old_role !== $new_role) {
        $changes[] = "role: {$old_role} → {$new_role}";
        adsdefender_admin_log('user_role_change', "Role changed for {$user->user_login}: {$old_role} → {$new_role}", 'user', $user_id, $user->user_login);
        return;
    }
    if (empty($changes)) $changes[] = 'profile updated';
    adsdefender_admin_log('user_update', "Updated user {$user->user_login}: " . implode('; ', $changes), 'user', $user_id, $user->user_login);
}, 10, 2);

add_action('deleted_user', function (int $user_id, ?int $reassign, WP_User $user) {
    if (!adsdefender_admin_log_enabled()) return;
    adsdefender_admin_log('user_delete', "Deleted user: {$user->user_login} ({$user->user_email})", 'user', $user_id, $user->user_login);
}, 10, 3);
