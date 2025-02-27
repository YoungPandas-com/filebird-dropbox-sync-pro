<?php
/**
 * The setup wizard class.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_Sync_Setup_Wizard {

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
     * Logger instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      FileBird_Dropbox_Sync_Logger    $logger    Logger instance.
     */
    private $logger;

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
        $this->logger = new FileBird_Dropbox_Sync_Logger();
    }

    /**
     * Check if we should start the wizard.
     *
     * @since    1.0.0
     */
    public function maybe_start_wizard() {
        // If we're already on the wizard page, don't redirect
        if (isset($_GET['page']) && 'filebird-dropbox-setup' === $_GET['page']) {
            return;
        }

        // If user explicitly wants to ignore the wizard, set it as completed
        if (isset($_GET['fbds_ignore_wizard']) && $_GET['fbds_ignore_wizard'] == 1) {
            update_option('fbds_wizard_completed', true);
            return;
        }

        // Check if wizard has been completed
        $wizard_completed = get_option('fbds_wizard_completed', false);
        
        if (!$wizard_completed && current_user_can('manage_options')) {
            // Don't redirect in specific contexts or URLs
            $current_screen = get_current_screen();
            
            // Skip redirect for certain admin pages
            $skip_pages = array(
                'update.php',
                'plugins.php', 
                'options-general.php',
                'options.php',
                'admin-ajax.php',
                'admin.php?page=filebird-dropbox-sync',
                'customize.php'
            );
            
            $current_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            foreach ($skip_pages as $page) {
                if (strpos($current_url, $page) !== false) {
                    return;
                }
            }
            
            // Redirect to wizard
            if (!isset($_GET['fbds_ignore_wizard'])) {
                // Check if it's an admin page but not the wizard
                if (is_admin() && (!isset($_GET['action']) || $_GET['action'] !== 'heartbeat')) {
                    // Prevent redirect loops
                    if (!wp_doing_ajax() && !wp_doing_cron()) {
                        // Add a parameter to track redirects
                        static $redirected = false;
                        if (!$redirected) {
                            $redirected = true;
                            wp_redirect(admin_url('admin.php?page=filebird-dropbox-setup'));
                            exit;
                        }
                    }
                }
            }
        }
    }

    /**
     * Handle wizard steps.
     *
     * @since    1.0.0
     */
    public function handle_wizard_step() {
        // Check nonce
        check_ajax_referer('fbds_wizard_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : '';
        
        switch ($step) {
            case 'skip_wizard':
                $this->skip_wizard();
                break;
                
            case 'complete_wizard':
                $this->complete_wizard();
                break;
                
            case 'save_api_settings':
                $this->save_api_settings();
                break;
                
            case 'get_dropbox_auth_url':
                $this->get_dropbox_auth_url();
                break;
                
            case 'save_mapping':
                $this->save_mapping();
                break;
                
            default:
                wp_send_json_error(array('message' => __('Invalid step.', 'filebird-dropbox-sync-pro')));
                break;
        }
    }

    /**
     * Skip the wizard.
     *
     * @since    1.0.0
     */
    private function skip_wizard() {
        update_option('fbds_wizard_completed', true);
        $this->logger->log('Setup wizard skipped by user', 'info');
        
        wp_send_json_success(array(
            'message' => __('Setup wizard skipped.', 'filebird-dropbox-sync-pro'),
            'redirect_url' => admin_url('admin.php?page=filebird-dropbox-sync')
        ));
    }

    /**
     * Complete the wizard.
     *
     * @since    1.0.0
     */
    private function complete_wizard() {
        update_option('fbds_wizard_completed', true);
        $this->logger->log('Setup wizard completed', 'info');
        
        wp_send_json_success(array(
            'message' => __('Setup completed successfully!', 'filebird-dropbox-sync-pro'),
            'redirect_url' => admin_url('admin.php?page=filebird-dropbox-sync')
        ));
    }

    /**
     * Save API settings.
     *
     * @since    1.0.0
     */
    private function save_api_settings() {
        $app_key = isset($_POST['app_key']) ? sanitize_text_field($_POST['app_key']) : '';
        $app_secret = isset($_POST['app_secret']) ? sanitize_text_field($_POST['app_secret']) : '';
        
        if (empty($app_key) || empty($app_secret)) {
            wp_send_json_error(array('message' => __('App Key and App Secret are required.', 'filebird-dropbox-sync-pro')));
        }
        
        // Save API settings
        update_option('fbds_dropbox_app_key', $app_key);
        update_option('fbds_dropbox_app_secret', $app_secret);
        
        $this->logger->log('Dropbox API settings saved', 'info');
        
        wp_send_json_success(array('message' => __('API settings saved successfully.', 'filebird-dropbox-sync-pro')));
    }

    /**
     * Get Dropbox authorization URL.
     *
     * @since    1.0.0
     */
    private function get_dropbox_auth_url() {
        $dropbox_api = new FileBird_Dropbox_API();
        $auth_url = $dropbox_api->get_auth_url();
        
        if (!$auth_url) {
            wp_send_json_error(array('message' => __('Could not get authorization URL. Please check your API settings.', 'filebird-dropbox-sync-pro')));
        }
        
        wp_send_json_success(array('auth_url' => $auth_url));
    }

    /**
     * Save folder-to-field mapping.
     *
     * @since    1.0.0
     */
    private function save_mapping() {
        $mapping_id = isset($_POST['mapping_id']) ? sanitize_text_field($_POST['mapping_id']) : '';
        $folder_id = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;
        $field_key = isset($_POST['field_key']) ? sanitize_text_field($_POST['field_key']) : '';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (empty($folder_id) || empty($field_key) || empty($post_id)) {
            wp_send_json_error(array('message' => __('Folder, field, and post are required.', 'filebird-dropbox-sync-pro')));
        }
        
        // Get existing mappings
        $mappings = get_option('fbds_folder_field_mappings', array());
        
        // Check if mapping already exists
        foreach ($mappings as $index => $existing_mapping) {
            if ($existing_mapping['folder_id'] == $folder_id && 
                $existing_mapping['field_key'] == $field_key && 
                $existing_mapping['post_id'] == $post_id) {
                
                // Skip if this is an update to the same mapping
                if ($mapping_id !== '' && $mapping_id == $index) {
                    continue;
                }
                
                wp_send_json_error(array('message' => __('This mapping already exists.', 'filebird-dropbox-sync-pro')));
                return;
            }
        }
        
        // Create new mapping data
        $mapping_data = array(
            'folder_id' => $folder_id,
            'field_key' => $field_key,
            'post_id' => $post_id,
            'created_at' => time()
        );
        
        // Update or add mapping
        if ($mapping_id !== '' && isset($mappings[$mapping_id])) {
            $mappings[$mapping_id] = $mapping_data;
            $message = __('Mapping updated successfully.', 'filebird-dropbox-sync-pro');
        } else {
            $mappings[] = $mapping_data;
            $message = __('Mapping added successfully.', 'filebird-dropbox-sync-pro');
        }
        
        // Save mappings
        update_option('fbds_folder_field_mappings', $mappings);
        
        // Log the action
        $this->logger->log("Folder-to-field mapping saved: Folder ID $folder_id to field $field_key on post $post_id", 'info');
        
        // Trigger a sync to update the ACF field with the folder contents
        $filebird_connector = new FileBird_Connector();
        $acf_connector = new ACF_Connector();
        
        $attachments = $filebird_connector->get_attachments_in_folder($folder_id);
        
        if ($attachments) {
            $attachment_ids = array();
            foreach ($attachments as $attachment) {
                $attachment_ids[] = $attachment->ID;
            }
            
            $acf_connector->update_gallery_field($post_id, $field_key, $attachment_ids);
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'mapping' => $mapping_data
        ));
    }
}