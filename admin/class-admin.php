<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_Sync_Admin {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    string    $plugin_name       The name of this plugin.
     * @param    string    $version           The version of this plugin.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
        wp_enqueue_style($this->plugin_name, FBDS_PLUGIN_URL . 'assets/css/admin.css', [], $this->version, 'all');
        
        // Add wizard styles if on wizard page
        if (isset($_GET['page']) && $_GET['page'] === 'filebird-dropbox-setup') {
            wp_enqueue_style($this->plugin_name . '-wizard', FBDS_PLUGIN_URL . 'assets/css/wizard.css', [], $this->version, 'all');
        }
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        wp_enqueue_script($this->plugin_name, FBDS_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], $this->version, false);
        
        // Add admin-specific scripts
        wp_enqueue_script($this->plugin_name . '-folder-mapper', FBDS_PLUGIN_URL . 'assets/js/folder-mapper.js', ['jquery', 'jquery-ui-sortable'], $this->version, false);
        wp_enqueue_script($this->plugin_name . '-sync-engine', FBDS_PLUGIN_URL . 'assets/js/sync-engine.js', ['jquery'], $this->version, false);
        
        // Add Dropbox connector script only if on settings page
        if (isset($_GET['page']) && ($_GET['page'] === 'filebird-dropbox-sync' || $_GET['page'] === 'filebird-dropbox-setup')) {
            wp_enqueue_script($this->plugin_name . '-dropbox-connector', FBDS_PLUGIN_URL . 'assets/js/dropbox-connector.js', ['jquery'], $this->version, false);
        }
        
        // Add localized data for JavaScript
        wp_localize_script($this->plugin_name, 'fbds_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fbds_ajax_nonce'),
            'manual_sync_nonce' => wp_create_nonce('fbds_manual_sync'),
            'texts' => [
                'sync_started' => __('Synchronization started...', 'filebird-dropbox-sync-pro'),
                'sync_completed' => __('Synchronization completed successfully!', 'filebird-dropbox-sync-pro'),
                'sync_failed' => __('Synchronization failed. Please check the logs.', 'filebird-dropbox-sync-pro'),
                'confirm_disconnect' => __('Are you sure you want to disconnect Dropbox? This will stop all synchronization until reconnected.', 'filebird-dropbox-sync-pro'),
                'confirm_delete_mapping' => __('Are you sure you want to delete this mapping?', 'filebird-dropbox-sync-pro'),
            ],
        ]);
    }

    /**
     * Add plugin admin menu.
     *
     * @since    1.0.0
     */
    public function add_plugin_admin_menu() {
        // Main menu item
        add_menu_page(
            __('FileBird Dropbox Sync', 'filebird-dropbox-sync-pro'),
            __('FB Dropbox Sync', 'filebird-dropbox-sync-pro'),
            'manage_options',
            'filebird-dropbox-sync',
            [$this, 'display_plugin_admin_page'],
            'dashicons-cloud',
            81
        );
        
        // Dashboard submenu
        add_submenu_page(
            'filebird-dropbox-sync',
            __('Dashboard', 'filebird-dropbox-sync-pro'),
            __('Dashboard', 'filebird-dropbox-sync-pro'),
            'manage_options',
            'filebird-dropbox-sync',
            [$this, 'display_plugin_admin_page']
        );
        
        // Folder Mapping submenu
        add_submenu_page(
            'filebird-dropbox-sync',
            __('Folder Mapping', 'filebird-dropbox-sync-pro'),
            __('Folder Mapping', 'filebird-dropbox-sync-pro'),
            'manage_options',
            'filebird-dropbox-mapping',
            [$this, 'display_folder_mapping_page']
        );
        
        // Settings submenu
        add_submenu_page(
            'filebird-dropbox-sync',
            __('Settings', 'filebird-dropbox-sync-pro'),
            __('Settings', 'filebird-dropbox-sync-pro'),
            'manage_options',
            'filebird-dropbox-settings',
            [$this, 'display_settings_page']
        );
        
        // Hidden setup wizard page
        add_submenu_page(
            null,
            __('Setup Wizard', 'filebird-dropbox-sync-pro'),
            __('Setup Wizard', 'filebird-dropbox-sync-pro'),
            'manage_options',
            'filebird-dropbox-setup',
            [$this, 'display_setup_wizard']
        );
    }

    /**
     * Display the plugin admin page.
     *
     * @since    1.0.0
     */
    public function display_plugin_admin_page() {
        include_once FBDS_PLUGIN_DIR . 'admin/partials/dashboard.php';
    }

    /**
     * Display the folder mapping page.
     *
     * @since    1.0.0
     */
    public function display_folder_mapping_page() {
        include_once FBDS_PLUGIN_DIR . 'admin/partials/folder-mapping.php';
    }

    /**
     * Display the settings page.
     *
     * @since    1.0.0
     */
    public function display_settings_page() {
        include_once FBDS_PLUGIN_DIR . 'admin/partials/settings-page.php';
    }

    /**
     * Display the setup wizard.
     *
     * @since    1.0.0
     */
    public function display_setup_wizard() {
        include_once FBDS_PLUGIN_DIR . 'admin/partials/wizard/index.php';
    }
}