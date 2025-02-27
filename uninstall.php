<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package FileBirdDropboxSyncPro
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clear scheduled events
wp_clear_scheduled_hook('fbds_scheduled_sync');

// Delete plugin options
$options = array(
    'fbds_dropbox_app_key',
    'fbds_dropbox_app_secret',
    'fbds_dropbox_access_token',
    'fbds_dropbox_refresh_token',
    'fbds_settings',
    'fbds_folder_field_mappings',
    'fbds_last_sync_time',
    'fbds_last_sync_status',
    'fbds_last_sync_error',
    'fbds_last_sync_direction',
    'fbds_sync_start_time',
    'fbds_logs',
    'fbds_wizard_completed'
);

foreach ($options as $option) {
    delete_option($option);
}

// Delete log file
$upload_dir = wp_upload_dir();
$log_file = trailingslashit($upload_dir['basedir']) . 'fbds-log.txt';
if (file_exists($log_file)) {
    unlink($log_file);
}

// Delete log backup files
$pattern = $log_file . '.*.bak';
$backup_files = glob($pattern);
if (is_array($backup_files)) {
    foreach ($backup_files as $file) {
        unlink($file);
    }
}