<?php
if (!defined('ABSPATH')) { exit; }

final class WPDrive_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
    }

    public static function ensure_caps_and_roles() {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('wpdrive_manage', true);
            $role->add_cap('wpdrive_browse', true);
            $role->add_cap('wpdrive_sync', true);
        }

        if (!get_role('wpdrive_sync')) {
            add_role('wpdrive_sync', 'WPDrive Sync', array(
                'read' => true,
                'wpdrive_sync' => true,
            ));
        }
    }

    public static function register_settings() {
        register_setting('wpdrive_settings', 'wpdrive_storage_base', array(
            'type' => 'string',
            'sanitize_callback' => array(__CLASS__, 'sanitize_storage_base'),
            'default' => '',
        ));
    }

    public static function sanitize_storage_base($v) {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') { return ''; }
        $v = wp_normalize_path($v);
        if (strlen($v) > 4096) { return ''; }
        return $v;
    }

    public static function admin_menu() {
        add_menu_page('WPDrive', 'WPDrive', 'wpdrive_manage', 'wpdrive', array(__CLASS__, 'render'), 'dashicons-cloud', 80);
    }

    public static function render() {
        if (!current_user_can('wpdrive_manage')) { return; }

        $dirs = WPDrive_Helpers::ensure_storage_dirs();
        $status = is_wp_error($dirs) ? $dirs->get_error_message() : 'OK';
        $base = is_wp_error($dirs) ? '' : $dirs['base'];

        $created = null;
        if (isset($_POST['wpdrive_create_share']) && check_admin_referer('wpdrive_create_share_nonce')) {
            $scope = WPDrive_Helpers::normalize_rel_path($_POST['scope_path'] ?? '');
            $is_dir = isset($_POST['is_dir']) ? (int)$_POST['is_dir'] : 0;
            $pw = (string)($_POST['share_password'] ?? '');
            $expires = (string)($_POST['expires_at'] ?? '');
            $expires_at = null;
            if ($expires !== '') {
                $ts = strtotime($expires);
                if ($ts !== false) { $expires_at = gmdate('Y-m-d H:i:s', $ts); }
            }

            if ($scope === '' || $pw === '') {
                $created = array('error' => 'Scope path and password are required.');
            } else {
                $token = wp_generate_password(40, false, false);
                WPDrive_DB::create_share(array(
                    'token' => $token,
                    'scope_path' => $scope,
                    'is_dir' => $is_dir ? 1 : 0,
                    'password_hash' => wp_hash_password($pw),
                    'expires_at' => $expires_at,
                    'created_at' => current_time('mysql'),
                ));
                $created = array('token' => $token);
            }
        }
        ?>
        <div class="wrap">
            <h1>WPDrive</h1>

            <?php if ($created && isset($created['error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html($created['error']); ?></p></div>
            <?php endif; ?>

            <?php if ($created && isset($created['token'])): ?>
                <div class="notice notice-success">
                    <p>Share created. Link: <code><?php echo esc_html(home_url('/wpdrive-share/' . $created['token'] . '/')); ?></code></p>
                </div>
            <?php endif; ?>

            <h2>Storage</h2>
            <p>Status: <strong><?php echo esc_html($status); ?></strong></p>
            <?php if ($base): ?><p>Storage base: <code><?php echo esc_html($base); ?></code></p><?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('wpdrive_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wpdrive_storage_base">Storage base (absolute path)</label></th>
                        <td>
                            <input name="wpdrive_storage_base" id="wpdrive_storage_base" type="text" class="regular-text"
                                value="<?php echo esc_attr(get_option('wpdrive_storage_base','')); ?>" />
                            <p class="description">
                                Leave blank to try <code><?php echo esc_html(dirname(ABSPATH) . '/wpdrive-data'); ?></code> and fall back to <code><?php echo esc_html(WP_CONTENT_DIR . '/wpdrive-private'); ?></code>.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Sync user setup</h2>
            <ol>
                <li>Create a WP user (e.g. <code>wpdrive-sync</code>) and assign role <strong>WPDrive Sync</strong>.</li>
                <li>Generate an <strong>Application Password</strong> for that user.</li>
                <li>Use the Python client with site URL + username + app password.</li>
            </ol>

            <h2>Create share link (one password)</h2>
            <form method="post">
                <?php wp_nonce_field('wpdrive_create_share_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="scope_path">Scope path</label></th>
                        <td><input name="scope_path" id="scope_path" type="text" class="regular-text" placeholder="e.g. Clients/A or Clients/A/report.pdf" /></td>
                    </tr>
                    <tr>
                        <th scope="row">Scope type</th>
                        <td><label><input type="checkbox" name="is_dir" value="1" checked /> Folder scope</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="share_password">Password</label></th>
                        <td><input name="share_password" id="share_password" type="text" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="expires_at">Expires at (optional)</label></th>
                        <td><input name="expires_at" id="expires_at" type="text" class="regular-text" placeholder="YYYY-MM-DD or any strtotime() format" /></td>
                    </tr>
                </table>
                <p><button class="button button-primary" name="wpdrive_create_share" value="1">Create share</button></p>
            </form>

            <h2>Browser shortcode</h2>
            <p>Use <code>[wpdrive_browser]</code> on a page. Requires logged-in users with capability <code>wpdrive_browse</code>.</p>
        </div>
        <?php
    }
}
