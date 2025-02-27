<?php
/**
 * FileBird Dropbox Sync Pro
 *
 * @package           FileBirdDropboxSyncPro
 * @author            Young Pandas
 * @copyright         2025 Young Pandas Limited
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       FileBird Dropbox Sync Pro
 * Plugin URI:        https://yp.studio
 * Description:       Two-way synchronization between FileBird folders and Dropbox, with ACF gallery integration
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Young Pandas
 * Author URI:        https://yp.studio
 * Text Domain:       filebird-dropbox-sync-pro
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:        https://example.com/plugins/filebird-dropbox-sync-pro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('FBDS_VERSION', '1.0.0');
define('FBDS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FBDS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FBDS_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once plugin_dir_path(__FILE__) . 'filebird-diagnostic.php';

/**
 * Check for required plugins (FileBird and ACF)
 */
function fbds_check_required_plugins() {
    if (is_admin() && current_user_can('activate_plugins')) {
        $filebird_active = is_plugin_active('filebird/filebird.php');
        $acf_active = is_plugin_active('advanced-custom-fields/acf.php') || is_plugin_active('advanced-custom-fields-pro/acf.php');
        
        if (!$filebird_active || !$acf_active) {
            add_action('admin_notices', 'fbds_admin_notice_missing_plugins');
        }
    }
}
add_action('admin_init', 'fbds_check_required_plugins');

/**
 * Admin notice for missing required plugins
 */
function fbds_admin_notice_missing_plugins() {
    // Check for missing plugins
    $missing_plugins = get_option('fbds_missing_plugins', array());
    
    if (!empty($missing_plugins)) {
        $message = 'The following plugins are required for FileBird Dropbox Sync Pro: ';
        $message .= implode(', ', $missing_plugins) . '. ';
        $message .= 'Please install and activate the required plugins.';
        
        echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
    }
    
    // Check for incompatible plugin versions
    $incompatible_plugins = get_option('fbds_incompatible_plugins', array());
    
    if (!empty($incompatible_plugins)) {
        $message = 'FileBird Dropbox Sync Pro requires newer versions of the following plugins: ';
        $message .= implode(', ', $incompatible_plugins) . '. ';
        $message .= 'Please update these plugins to their required versions.';
        
        echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
    }
}

/**
 * The code that runs during plugin activation.
 */
function activate_filebird_dropbox_sync() {
    require_once FBDS_PLUGIN_DIR . 'includes/class-filebird-dropbox-sync-activator.php';
    FileBird_Dropbox_Sync_Activator::activate();
}
register_activation_hook(__FILE__, 'activate_filebird_dropbox_sync');

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_filebird_dropbox_sync() {
    require_once FBDS_PLUGIN_DIR . 'includes/class-filebird-dropbox-sync-deactivator.php';
    FileBird_Dropbox_Sync_Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'deactivate_filebird_dropbox_sync');

/**
 * The core plugin class
 */
require FBDS_PLUGIN_DIR . 'includes/class-filebird-dropbox-sync.php';

/**
 * Begins execution of the plugin.
 */
function run_filebird_dropbox_sync() {
    $plugin = new FileBird_Dropbox_Sync();
    $plugin->run();
}
run_filebird_dropbox_sync();