<?php
/**
 * Define the internationalization functionality.
 *
 * @package FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_Sync_i18n {

    /**
     * Load the plugin text domain for translation.
     *
     * @since    1.0.0
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'filebird-dropbox-sync-pro',
            false,
            dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
        );
    }
}