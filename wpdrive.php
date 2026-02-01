<?php
/**
 * Plugin Name: WPDrive Sync
 * Description: Private, Dropbox-like file browser + multi-device sync (chunked uploads) with share links. Includes a local Python client.
 * Version: 1.0.0
 * Author: WPDrive
 * License: Proprietary
 */

if (!defined('ABSPATH')) { exit; }

define('WPDRIVE_VERSION', '1.0.0');
define('WPDRIVE_PLUGIN_FILE', __FILE__);
define('WPDRIVE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPDRIVE_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPDRIVE_PLUGIN_DIR . 'includes/helpers.php';
require_once WPDRIVE_PLUGIN_DIR . 'includes/class-wpdrive-db.php';
require_once WPDRIVE_PLUGIN_DIR . 'includes/class-wpdrive-rest.php';
require_once WPDRIVE_PLUGIN_DIR . 'includes/class-wpdrive-download.php';
require_once WPDRIVE_PLUGIN_DIR . 'includes/class-wpdrive-admin.php';
require_once WPDRIVE_PLUGIN_DIR . 'includes/class-wpdrive-share.php';
require_once WPDRIVE_PLUGIN_DIR . 'includes/class-wpdrive-shortcode.php';

register_activation_hook(__FILE__, function () {
    WPDrive_DB::install();
    WPDrive_Admin::ensure_caps_and_roles();
    WPDrive_Share::add_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

add_action('init', function () {
    WPDrive_Share::add_rewrite_rules();
    WPDrive_Share::register_query_vars();
});

add_action('plugins_loaded', function () {
    WPDrive_Admin::init();
    WPDrive_REST::init();
    WPDrive_Download::init();
    WPDrive_Share::init();
    WPDrive_Shortcode::init();
});
