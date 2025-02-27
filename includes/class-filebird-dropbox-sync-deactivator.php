<?php
/**
 * Fired during plugin deactivation.
 *
 * @package FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_Sync_Deactivator {

    /**
     * Run during plugin deactivation.
     *
     * @since    1.0.0
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('fbds_scheduled_sync');
        
        // Log deactivation
        self::log_deactivation();
    }

    /**
     * Log plugin deactivation.
     *
     * @since    1.0.0
     */
    private static function log_deactivation() {
        $upload_dir = wp_upload_dir();
        $log_file = trailingslashit($upload_dir['basedir']) . 'fbds-log.txt';
        
        if (file_exists($log_file) && is_writable($log_file)) {
            $log_entry = "[" . date('Y-m-d H:i:s') . "] [info] Plugin deactivated\n";
            file_put_contents($log_file, $log_entry, FILE_APPEND);
        }
    }
}