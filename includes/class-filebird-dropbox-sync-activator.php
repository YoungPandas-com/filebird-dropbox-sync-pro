<?php
/**
 * Fired during plugin activation.
 *
 * @package FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_Sync_Activator {

    /**
     * Run during plugin activation.
     *
     * @since    1.0.0
     */
    public static function activate() {
        // Check for required plugins
        self::check_dependencies();
        
        // Create initial settings
        self::create_settings();
        
        // Schedule sync if auto-sync is enabled
        self::setup_cron();
        
        // Create log file
        self::create_log_file();
    }

    /**
     * Check for required plugins and compatible versions.
     *
     * @since    1.0.0
     */
    private static function check_dependencies() {
        $required_plugins = array(
            'filebird/filebird.php' => array('name' => 'FileBird', 'min_version' => '4.0'),
            'advanced-custom-fields/acf.php' => array('name' => 'Advanced Custom Fields', 'min_version' => '5.7.0'),
            'advanced-custom-fields-pro/acf.php' => array('name' => 'Advanced Custom Fields PRO', 'min_version' => '5.7.0')
        );
        
        $missing_plugins = array();
        $incompatible_plugins = array();
        
        // Check ACF
        $acf_active = false;
        $acf_version = '';
        
        if (is_plugin_active('advanced-custom-fields/acf.php')) {
            $acf_active = true;
            $acf_data = get_plugin_data(WP_PLUGIN_DIR . '/advanced-custom-fields/acf.php');
            $acf_version = $acf_data['Version'];
        } elseif (is_plugin_active('advanced-custom-fields-pro/acf.php')) {
            $acf_active = true;
            $acf_data = get_plugin_data(WP_PLUGIN_DIR . '/advanced-custom-fields-pro/acf.php');
            $acf_version = $acf_data['Version'];
        } else {
            $missing_plugins[] = 'Advanced Custom Fields';
        }
        
        // Check ACF version
        if ($acf_active && version_compare($acf_version, '5.7.0', '<')) {
            $incompatible_plugins[] = 'Advanced Custom Fields (requires 5.7.0+, current: ' . $acf_version . ')';
        }
        
        // Check FileBird
        if (is_plugin_active('filebird/filebird.php')) {
            $filebird_data = get_plugin_data(WP_PLUGIN_DIR . '/filebird/filebird.php');
            $filebird_version = $filebird_data['Version'];
            
            if (version_compare($filebird_version, '4.0', '<')) {
                $incompatible_plugins[] = 'FileBird (requires 4.0+, current: ' . $filebird_version . ')';
            }
        } else {
            $missing_plugins[] = 'FileBird';
        }
        
        // Store results for admin notices
        if (!empty($missing_plugins)) {
            update_option('fbds_missing_plugins', $missing_plugins);
        } else {
            delete_option('fbds_missing_plugins');
        }
        
        if (!empty($incompatible_plugins)) {
            update_option('fbds_incompatible_plugins', $incompatible_plugins);
        } else {
            delete_option('fbds_incompatible_plugins');
        }
    }

    /**
     * Create initial settings.
     *
     * @since    1.0.0
     */
    private static function create_settings() {
        // Create default settings if not exists
        if (false === get_option('fbds_settings')) {
            $default_settings = array(
                'sync_frequency' => 'hourly',
                'conflict_resolution' => 'newer',
                'file_types' => array('jpg', 'jpeg', 'png', 'gif'),
                'auto_sync' => true,
                'notification_email' => get_option('admin_email'),
                'enable_email_notifications' => false,
            );
            
            update_option('fbds_settings', $default_settings);
        }
        
        // Create empty mappings array if not exists
        if (false === get_option('fbds_folder_field_mappings')) {
            update_option('fbds_folder_field_mappings', array());
        }
        
        // Reset wizard completed flag
        if (!get_option('fbds_wizard_completed')) {
            update_option('fbds_wizard_completed', false);
        }
    }

    /**
     * Setup cron job.
     *
     * @since    1.0.0
     */
    private static function setup_cron() {
        // Clear any existing scheduled events
        wp_clear_scheduled_hook('fbds_scheduled_sync');
        
        // Get settings
        $settings = get_option('fbds_settings');
        
        // Schedule sync if auto sync is enabled
        if ($settings && isset($settings['auto_sync']) && $settings['auto_sync']) {
            $frequency = isset($settings['sync_frequency']) ? $settings['sync_frequency'] : 'hourly';
            
            // Schedule general bidirectional sync
            wp_schedule_event(time(), $frequency, 'fbds_scheduled_sync');
            
            // Schedule additional dedicated syncs for the Dropbox to FileBird direction
            // This ensures changes in Dropbox are regularly pulled even without webhook triggers
            wp_schedule_event(time() + 300, $frequency, 'fbds_scheduled_sync', array('from_dropbox'));
            
            // Also schedule a daily full bidirectional sync
            wp_schedule_event(time() + 3600, 'daily', 'fbds_scheduled_sync', array('both'));
        }
    }

    /**
     * Create log file.
     *
     * @since    1.0.0
     */
    private static function create_log_file() {
        $upload_dir = wp_upload_dir();
        $log_file = trailingslashit($upload_dir['basedir']) . 'fbds-log.txt';
        
        if (!file_exists($log_file)) {
            $header = "FileBird Dropbox Sync Pro - Log File\n";
            $header .= "Created: " . date('Y-m-d H:i:s') . "\n";
            $header .= "----------------------------------------\n\n";
            
            file_put_contents($log_file, $header);
        }
    }
}