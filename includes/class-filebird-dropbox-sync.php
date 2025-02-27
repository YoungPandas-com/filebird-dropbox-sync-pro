<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_Sync {

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      FileBird_Dropbox_Sync_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $plugin_name    The string used to uniquely identify this plugin.
     */
    protected $plugin_name;

    /**
     * The current version of the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string    $version    The current version of the plugin.
     */
    protected $version;

    /**
     * Define the core functionality of the plugin.
     *
     * Load the dependencies, define the locale, and set the hooks for the admin area.
     *
     * @since    1.0.0
     */
    public function __construct() {
        if (defined('FBDS_VERSION')) {
            $this->version = FBDS_VERSION;
        } else {
            $this->version = '1.0.0';
        }
        $this->plugin_name = 'filebird-dropbox-sync-pro';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_sync_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - FileBird_Dropbox_Sync_Loader. Orchestrates the hooks of the plugin.
     * - FileBird_Dropbox_Sync_i18n. Defines internationalization functionality.
     * - FileBird_Dropbox_Sync_Admin. Defines all hooks for the admin area.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies() {
        /**
         * The class responsible for orchestrating the actions and filters of the
         * core plugin.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-filebird-dropbox-sync-loader.php';

        /**
         * The class responsible for defining internationalization functionality
         * of the plugin.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-filebird-dropbox-sync-i18n.php';

        /**
         * The class responsible for handling Dropbox API interactions.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-dropbox-api.php';

        /**
         * The class responsible for FileBird integration.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-filebird-connector.php';

        /**
         * The class responsible for ACF integration.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-acf-connector.php';

        /**
         * The class responsible for synchronization logic.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sync-engine.php';

        /**
         * The class responsible for logging activities.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-logger.php';

        /**
         * The class responsible for defining all actions that occur in the admin area.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-admin.php';

        /**
         * The class responsible for the settings page.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-settings.php';

        /**
         * The class responsible for the setup wizard.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-setup-wizard.php';

        $this->loader = new FileBird_Dropbox_Sync_Loader();
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * @since    1.0.0
     * @access   private
     */
    private function set_locale() {
        $plugin_i18n = new FileBird_Dropbox_Sync_i18n();

        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks() {
        $plugin_admin = new FileBird_Dropbox_Sync_Admin($this->get_plugin_name(), $this->get_version());
        $plugin_settings = new FileBird_Dropbox_Sync_Settings($this->get_plugin_name(), $this->get_version());
        $setup_wizard = new FileBird_Dropbox_Sync_Setup_Wizard($this->get_plugin_name(), $this->get_version());

        // Admin assets
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');

        // Admin menu
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');

        // Settings
        $this->loader->add_action('admin_init', $plugin_settings, 'register_settings');

        // Setup wizard
        $this->loader->add_action('admin_init', $setup_wizard, 'maybe_start_wizard');
        $this->loader->add_action('wp_ajax_fbds_wizard_step', $setup_wizard, 'handle_wizard_step');
    }

    /**
     * Register all of the hooks related to synchronization functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_sync_hooks() {
        $dropbox_api = new FileBird_Dropbox_API();
        $filebird_connector = new FileBird_Connector();
        $acf_connector = new ACF_Connector();
        $sync_engine = new Sync_Engine($dropbox_api, $filebird_connector, $acf_connector);
        $logger = new FileBird_Dropbox_Sync_Logger();

        // FileBird hooks for detecting changes
        $this->loader->add_action('filebird_folder_created', $sync_engine, 'on_filebird_folder_created', 10, 2);
        $this->loader->add_action('filebird_folder_deleted', $sync_engine, 'on_filebird_folder_deleted', 10, 1);
        $this->loader->add_action('filebird_folder_renamed', $sync_engine, 'on_filebird_folder_renamed', 10, 2);
        $this->loader->add_action('add_attachment', $sync_engine, 'on_attachment_added', 10, 1);
        $this->loader->add_action('delete_attachment', $sync_engine, 'on_attachment_deleted', 10, 1);
        $this->loader->add_action('filebird_attachment_moved', $sync_engine, 'on_attachment_moved', 10, 3);

        // Dropbox webhook handler
        $this->loader->add_action('rest_api_init', $dropbox_api, 'register_webhook_endpoint');

        // Manual sync
        $this->loader->add_action('wp_ajax_fbds_manual_sync', $sync_engine, 'handle_manual_sync');

        // Scheduled sync
        $this->loader->add_action('fbds_scheduled_sync', $sync_engine, 'run_scheduled_sync');
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run() {
        $this->loader->run();
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @since     1.0.0
     * @return    string    The name of the plugin.
     */
    public function get_plugin_name() {
        return $this->plugin_name;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     * @since     1.0.0
     * @return    FileBird_Dropbox_Sync_Loader    Orchestrates the hooks of the plugin.
     */
    public function get_loader() {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @since     1.0.0
     * @return    string    The version number of the plugin.
     */
    public function get_version() {
        return $this->version;
    }
}