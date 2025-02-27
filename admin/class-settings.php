<?php
/**
 * The settings class.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class FileBird_Dropbox_Sync_Settings {

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
     * Register settings.
     *
     * @since    1.0.0
     */
    public function register_settings() {
        // Register settings
        register_setting('fbds_general_settings', 'fbds_settings', array($this, 'validate_settings'));
        register_setting('fbds_sync_settings', 'fbds_settings', array($this, 'validate_settings'));
        
        // Set up default settings if not exists
        $this->set_default_settings();
        
        // Add settings sections and fields
        add_settings_section(
            'fbds_general_section',
            __('General Settings', 'filebird-dropbox-sync-pro'),
            array($this, 'render_general_section'),
            'fbds_general_settings'
        );
        
        add_settings_section(
            'fbds_sync_section',
            __('Synchronization Settings', 'filebird-dropbox-sync-pro'),
            array($this, 'render_sync_section'),
            'fbds_sync_settings'
        );
        
        // General settings fields
        add_settings_field(
            'fbds_auto_sync',
            __('Automatic Synchronization', 'filebird-dropbox-sync-pro'),
            array($this, 'render_auto_sync_field'),
            'fbds_general_settings',
            'fbds_general_section'
        );
        
        add_settings_field(
            'fbds_notification_email',
            __('Notification Email', 'filebird-dropbox-sync-pro'),
            array($this, 'render_notification_email_field'),
            'fbds_general_settings',
            'fbds_general_section'
        );
        
        add_settings_field(
            'fbds_enable_email_notifications',
            __('Email Notifications', 'filebird-dropbox-sync-pro'),
            array($this, 'render_enable_email_notifications_field'),
            'fbds_general_settings',
            'fbds_general_section'
        );
        
        // Sync settings fields
        add_settings_field(
            'fbds_sync_frequency',
            __('Sync Frequency', 'filebird-dropbox-sync-pro'),
            array($this, 'render_sync_frequency_field'),
            'fbds_sync_settings',
            'fbds_sync_section'
        );
        
        add_settings_field(
            'fbds_conflict_resolution',
            __('File Conflict Resolution', 'filebird-dropbox-sync-pro'),
            array($this, 'render_conflict_resolution_field'),
            'fbds_sync_settings',
            'fbds_sync_section'
        );
        
        add_settings_field(
            'fbds_file_types',
            __('Allowed File Types', 'filebird-dropbox-sync-pro'),
            array($this, 'render_file_types_field'),
            'fbds_sync_settings',
            'fbds_sync_section'
        );
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
    }

    /**
     * Set default settings.
     *
     * @since    1.0.0
     */
    private function set_default_settings() {
        $settings = get_option('fbds_settings');
        
        if (false === $settings) {
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
    }

    /**
     * Validate settings.
     *
     * @since    1.0.0
     * @param    array    $input    The settings input.
     * @return   array             The validated settings.
     */
    public function validate_settings($input) {
        $output = get_option('fbds_settings', array());
        
        // Auto sync
        if (isset($input['auto_sync'])) {
            $output['auto_sync'] = (bool) $input['auto_sync'];
        }
        
        // Notification email
        if (isset($input['notification_email'])) {
            $output['notification_email'] = sanitize_email($input['notification_email']);
        }
        
        // Enable email notifications
        if (isset($input['enable_email_notifications'])) {
            $output['enable_email_notifications'] = (bool) $input['enable_email_notifications'];
        }
        
        // Sync frequency
        if (isset($input['sync_frequency'])) {
            $valid_frequencies = array('hourly', 'twicedaily', 'daily', 'weekly');
            $output['sync_frequency'] = in_array($input['sync_frequency'], $valid_frequencies) ? $input['sync_frequency'] : 'hourly';
        }
        
        // Conflict resolution
        if (isset($input['conflict_resolution'])) {
            $valid_resolutions = array('newer', 'filebird', 'dropbox', 'both');
            $output['conflict_resolution'] = in_array($input['conflict_resolution'], $valid_resolutions) ? $input['conflict_resolution'] : 'newer';
        }
        
        // File types
        if (isset($input['file_types']) && is_array($input['file_types'])) {
            $output['file_types'] = array_map('sanitize_text_field', $input['file_types']);
        }
        
        // Update cron schedule if frequency changed
        if (isset($input['sync_frequency']) && $input['sync_frequency'] !== $output['sync_frequency']) {
            $this->update_cron_schedule($input['sync_frequency']);
        }
        
        // Update auto sync setting
        if (isset($input['auto_sync']) && $input['auto_sync'] !== $output['auto_sync']) {
            if ($input['auto_sync']) {
                $this->enable_scheduled_sync();
            } else {
                $this->disable_scheduled_sync();
            }
        }
        
        return $output;
    }

    /**
     * Update cron schedule.
     *
     * @since    1.0.0
     * @param    string    $frequency    The new frequency.
     */
    private function update_cron_schedule($frequency) {
        // Clear existing scheduled event
        wp_clear_scheduled_hook('fbds_scheduled_sync');
        
        // Schedule new event if auto sync is enabled
        $settings = get_option('fbds_settings');
        if ($settings && isset($settings['auto_sync']) && $settings['auto_sync']) {
            wp_schedule_event(time(), $frequency, 'fbds_scheduled_sync');
            $this->logger->log("Scheduled sync updated to frequency: $frequency", 'info');
        }
    }

    /**
     * Enable scheduled sync.
     *
     * @since    1.0.0
     */
    private function enable_scheduled_sync() {
        $settings = get_option('fbds_settings');
        $frequency = isset($settings['sync_frequency']) ? $settings['sync_frequency'] : 'hourly';
        
        // Clear any existing scheduled event
        wp_clear_scheduled_hook('fbds_scheduled_sync');
        
        // Schedule new event
        wp_schedule_event(time(), $frequency, 'fbds_scheduled_sync');
        
        $this->logger->log('Automatic synchronization enabled', 'info');
    }

    /**
     * Disable scheduled sync.
     *
     * @since    1.0.0
     */
    private function disable_scheduled_sync() {
        wp_clear_scheduled_hook('fbds_scheduled_sync');
        $this->logger->log('Automatic synchronization disabled', 'info');
    }

    /**
     * Render general section.
     *
     * @since    1.0.0
     */
    public function render_general_section() {
        echo '<p>' . __('Configure general plugin settings.', 'filebird-dropbox-sync-pro') . '</p>';
    }

    /**
     * Render sync section.
     *
     * @since    1.0.0
     */
    public function render_sync_section() {
        echo '<p>' . __('Configure synchronization settings.', 'filebird-dropbox-sync-pro') . '</p>';
    }

    /**
     * Render auto sync field.
     *
     * @since    1.0.0
     */
    public function render_auto_sync_field() {
        $settings = get_option('fbds_settings');
        $auto_sync = isset($settings['auto_sync']) ? $settings['auto_sync'] : true;
        
        echo '<label>';
        echo '<input type="checkbox" name="fbds_settings[auto_sync]" value="1" ' . checked($auto_sync, true, false) . '>';
        echo ' ' . __('Enable automatic synchronization', 'filebird-dropbox-sync-pro');
        echo '</label>';
        echo '<p class="description">' . __('Automatically sync files between FileBird, Dropbox, and ACF based on your schedule settings.', 'filebird-dropbox-sync-pro') . '</p>';
    }

    /**
     * Render notification email field.
     *
     * @since    1.0.0
     */
    public function render_notification_email_field() {
        $settings = get_option('fbds_settings');
        $notification_email = isset($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        
        echo '<input type="email" name="fbds_settings[notification_email]" value="' . esc_attr($notification_email) . '" class="regular-text">';
    }

    /**
     * Render enable email notifications field.
     *
     * @since    1.0.0
     */
    public function render_enable_email_notifications_field() {
        $settings = get_option('fbds_settings');
        $enable_email_notifications = isset($settings['enable_email_notifications']) ? $settings['enable_email_notifications'] : false;
        
        echo '<label>';
        echo '<input type="checkbox" name="fbds_settings[enable_email_notifications]" value="1" ' . checked($enable_email_notifications, true, false) . '>';
        echo ' ' . __('Enable email notifications', 'filebird-dropbox-sync-pro');
        echo '</label>';
        echo '<p class="description">' . __('Receive email notifications about sync status and errors.', 'filebird-dropbox-sync-pro') . '</p>';
    }

    /**
     * Render sync frequency field.
     *
     * @since    1.0.0
     */
    public function render_sync_frequency_field() {
        $settings = get_option('fbds_settings');
        $sync_frequency = isset($settings['sync_frequency']) ? $settings['sync_frequency'] : 'hourly';
        
        echo '<select name="fbds_settings[sync_frequency]">';
        echo '<option value="hourly" ' . selected($sync_frequency, 'hourly', false) . '>' . __('Hourly', 'filebird-dropbox-sync-pro') . '</option>';
        echo '<option value="twicedaily" ' . selected($sync_frequency, 'twicedaily', false) . '>' . __('Twice Daily', 'filebird-dropbox-sync-pro') . '</option>';
        echo '<option value="daily" ' . selected($sync_frequency, 'daily', false) . '>' . __('Daily', 'filebird-dropbox-sync-pro') . '</option>';
        echo '<option value="weekly" ' . selected($sync_frequency, 'weekly', false) . '>' . __('Weekly', 'filebird-dropbox-sync-pro') . '</option>';
        echo '</select>';
    }

    /**
     * Render conflict resolution field.
     *
     * @since    1.0.0
     */
    public function render_conflict_resolution_field() {
        $settings = get_option('fbds_settings');
        $conflict_resolution = isset($settings['conflict_resolution']) ? $settings['conflict_resolution'] : 'newer';
        
        echo '<select name="fbds_settings[conflict_resolution]">';
        echo '<option value="newer" ' . selected($conflict_resolution, 'newer', false) . '>' . __('Keep Newer File', 'filebird-dropbox-sync-pro') . '</option>';
        echo '<option value="filebird" ' . selected($conflict_resolution, 'filebird', false) . '>' . __('FileBird Version Wins', 'filebird-dropbox-sync-pro') . '</option>';
        echo '<option value="dropbox" ' . selected($conflict_resolution, 'dropbox', false) . '>' . __('Dropbox Version Wins', 'filebird-dropbox-sync-pro') . '</option>';
        echo '<option value="both" ' . selected($conflict_resolution, 'both', false) . '>' . __('Keep Both (Rename)', 'filebird-dropbox-sync-pro') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('How to handle it when the same file exists in both FileBird and Dropbox with different content.', 'filebird-dropbox-sync-pro') . '</p>';
    }

    /**
     * Render file types field.
     *
     * @since    1.0.0
     */
    public function render_file_types_field() {
        $settings = get_option('fbds_settings');
        $file_types = isset($settings['file_types']) ? $settings['file_types'] : array('jpg', 'jpeg', 'png', 'gif');
        
        $all_file_types = array(
            'jpg' => __('JPEG Images (.jpg, .jpeg)', 'filebird-dropbox-sync-pro'),
            'png' => __('PNG Images (.png)', 'filebird-dropbox-sync-pro'),
            'gif' => __('GIF Images (.gif)', 'filebird-dropbox-sync-pro'),
            'pdf' => __('PDF Documents (.pdf)', 'filebird-dropbox-sync-pro'),
            'doc' => __('Word Documents (.doc, .docx)', 'filebird-dropbox-sync-pro'),
            'xls' => __('Excel Spreadsheets (.xls, .xlsx)', 'filebird-dropbox-sync-pro'),
            'zip' => __('Zip Archives (.zip)', 'filebird-dropbox-sync-pro'),
            'mp4' => __('Video Files (.mp4, .mov, .avi)', 'filebird-dropbox-sync-pro'),
            'mp3' => __('Audio Files (.mp3, .wav)', 'filebird-dropbox-sync-pro'),
        );
        
        echo '<div class="fbds-checkbox-group">';
        foreach ($all_file_types as $type => $label) {
            $checked = in_array($type, $file_types) ? 'checked' : '';
            echo '<label class="fbds-checkbox-label">';
            echo '<input type="checkbox" name="fbds_settings[file_types][]" value="' . esc_attr($type) . '" ' . $checked . '>';
            echo ' ' . $label;
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="description">' . __('Only files with these extensions will be synchronized.', 'filebird-dropbox-sync-pro') . '</p>';
    }

    /**
     * Register AJAX handlers.
     *
     * @since    1.0.0
     */
    private function register_ajax_handlers() {
        // Dropbox API settings
        add_action('wp_ajax_fbds_save_api_settings', array($this, 'ajax_save_api_settings'));
        add_action('wp_ajax_fbds_get_dropbox_auth_url', array($this, 'ajax_get_dropbox_auth_url'));
        add_action('wp_ajax_fbds_check_dropbox_connection', array($this, 'ajax_check_dropbox_connection'));
        add_action('wp_ajax_fbds_disconnect_dropbox', array($this, 'ajax_disconnect_dropbox'));
        
        // Folder mapping
        add_action('wp_ajax_fbds_save_mapping', array($this, 'ajax_save_mapping'));
        add_action('wp_ajax_fbds_delete_mapping', array($this, 'ajax_delete_mapping'));
        add_action('wp_ajax_fbds_get_mapping_preview', array($this, 'ajax_get_mapping_preview'));
        
        // Sync operations
        add_action('wp_ajax_fbds_manual_sync', array($this, 'ajax_manual_sync'));
        add_action('wp_ajax_fbds_check_sync_status', array($this, 'ajax_check_sync_status'));
        add_action('wp_ajax_fbds_get_stats', array($this, 'ajax_get_stats'));
        
        // Logs
        add_action('wp_ajax_fbds_refresh_logs', array($this, 'ajax_refresh_logs'));
        add_action('wp_ajax_fbds_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_fbds_download_logs', array($this, 'ajax_download_logs'));
        
        // Dropbox OAuth callback
        add_action('wp_ajax_fbds_dropbox_oauth_callback', array($this, 'handle_dropbox_oauth_callback'));
        
        // Wizard steps
        add_action('wp_ajax_fbds_wizard_step', array($this, 'ajax_wizard_step'));
    }

    /**
     * AJAX handler for saving API settings.
     *
     * @since    1.0.0
     */
    public function ajax_save_api_settings() {
        // Check nonce
        check_ajax_referer('fbds_save_api_settings', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
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
     * AJAX handler for getting Dropbox authorization URL.
     *
     * @since    1.0.0
     */
    public function ajax_get_dropbox_auth_url() {
        // Check nonce
        check_ajax_referer('fbds_connect_dropbox', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $dropbox_api = new FileBird_Dropbox_API();
        $auth_url = $dropbox_api->get_auth_url();
        
        if (!$auth_url) {
            wp_send_json_error(array('message' => __('Could not get authorization URL. Please check your API settings.', 'filebird-dropbox-sync-pro')));
        }
        
        wp_send_json_success(array('auth_url' => $auth_url));
    }

    /**
     * AJAX handler for checking Dropbox connection.
     *
     * @since    1.0.0
     */
    public function ajax_check_dropbox_connection() {
        // Check nonce
        check_ajax_referer('fbds_connect_dropbox', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $dropbox_api = new FileBird_Dropbox_API();
        $is_connected = $dropbox_api->is_connected();
        
        wp_send_json_success(array('connected' => $is_connected));
    }

    /**
     * AJAX handler for disconnecting from Dropbox.
     *
     * @since    1.0.0
     */
    public function ajax_disconnect_dropbox() {
        // Check nonce
        check_ajax_referer('fbds_disconnect_dropbox', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        // Clear Dropbox tokens
        delete_option('fbds_dropbox_access_token');
        delete_option('fbds_dropbox_refresh_token');
        
        $this->logger->log('Disconnected from Dropbox', 'info');
        
        wp_send_json_success(array('message' => __('Successfully disconnected from Dropbox.', 'filebird-dropbox-sync-pro')));
    }

    /**
     * AJAX handler for saving folder-to-field mapping.
     *
     * @since    1.0.0
     */
    public function ajax_save_mapping() {
        // Check nonce
        check_ajax_referer('fbds_save_mapping', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
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

    /**
     * AJAX handler for deleting folder-to-field mapping.
     *
     * @since    1.0.0
     */
    public function ajax_delete_mapping() {
        // Check nonce
        check_ajax_referer('fbds_delete_mapping', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $mapping_id = isset($_POST['mapping_id']) ? sanitize_text_field($_POST['mapping_id']) : '';
        
        if (empty($mapping_id)) {
            wp_send_json_error(array('message' => __('Mapping ID is required.', 'filebird-dropbox-sync-pro')));
        }
        
        // Get existing mappings
        $mappings = get_option('fbds_folder_field_mappings', array());
        
        // Check if mapping exists
        if (!isset($mappings[$mapping_id])) {
            wp_send_json_error(array('message' => __('Mapping not found.', 'filebird-dropbox-sync-pro')));
        }
        
        // Log the action before removing
        $folder_id = $mappings[$mapping_id]['folder_id'];
        $field_key = $mappings[$mapping_id]['field_key'];
        $post_id = $mappings[$mapping_id]['post_id'];
        
        $this->logger->log("Folder-to-field mapping deleted: Folder ID $folder_id to field $field_key on post $post_id", 'info');
        
        // Remove mapping
        unset($mappings[$mapping_id]);
        
        // Reindex array
        $mappings = array_values($mappings);
        
        // Save mappings
        update_option('fbds_folder_field_mappings', $mappings);
        
        wp_send_json_success(array('message' => __('Mapping deleted successfully.', 'filebird-dropbox-sync-pro')));
    }

    /**
     * AJAX handler for getting mapping preview.
     *
     * @since    1.0.0
     */
    public function ajax_get_mapping_preview() {
        // Check nonce
        check_ajax_referer('fbds_save_mapping', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $folder_id = isset($_POST['folder_id']) ? intval($_POST['folder_id']) : 0;
        $field_key = isset($_POST['field_key']) ? sanitize_text_field($_POST['field_key']) : '';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (empty($folder_id) || empty($field_key) || empty($post_id)) {
            wp_send_json_error(array('message' => __('Folder, field, and post are required.', 'filebird-dropbox-sync-pro')));
        }
        
        // Get folder attachments count
        $filebird_connector = new FileBird_Connector();
        $attachments = $filebird_connector->get_attachments_in_folder($folder_id);
        $folder_count = count($attachments);
        
        // Get gallery field count
        $acf_connector = new ACF_Connector();
        $gallery_value = $acf_connector->get_gallery_field_value($post_id, $field_key);
        $gallery_count = count($gallery_value);
        
        wp_send_json_success(array(
            'folder_count' => $folder_count,
            'gallery_count' => $gallery_count
        ));
    }

    /**
     * AJAX handler for manual sync.
     *
     * @since    1.0.0
     */
    public function ajax_manual_sync() {
        // Check nonce
        check_ajax_referer('fbds_manual_sync', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $direction = isset($_POST['direction']) ? sanitize_text_field($_POST['direction']) : 'both';
        
        // Schedule sync
        $dropbox_api = new FileBird_Dropbox_API();
        $filebird_connector = new FileBird_Connector();
        $acf_connector = new ACF_Connector();
        $sync_engine = new Sync_Engine($dropbox_api, $filebird_connector, $acf_connector);
        
        $sync_engine->schedule_sync($direction);
        
        wp_send_json_success(array('message' => __('Synchronization has been scheduled.', 'filebird-dropbox-sync-pro')));
    }

    /**
     * AJAX handler for checking sync status.
     *
     * @since    1.0.0
     */
    public function ajax_check_sync_status() {
        // Check nonce
        check_ajax_referer('fbds_ajax_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $status = get_option('fbds_last_sync_status', '');
        $error = get_option('fbds_last_sync_error', '');
        
        wp_send_json_success(array(
            'status' => $status,
            'error' => $error
        ));
    }

    /**
     * AJAX handler for getting stats.
     *
     * @since    1.0.0
     */
    public function ajax_get_stats() {
        // Check nonce
        check_ajax_referer('fbds_ajax_nonce', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        // Get FileBird folder count
        $filebird_connector = new FileBird_Connector();
        $folders = $filebird_connector->get_all_folders();
        $folder_count = count($folders);
        
        // Get mapping count
        $mappings = get_option('fbds_folder_field_mappings', array());
        $mapping_count = count($mappings);
        
        wp_send_json_success(array(
            'folder_count' => $folder_count,
            'mapping_count' => $mapping_count
        ));
    }

    /**
     * AJAX handler for refreshing logs.
     *
     * @since    1.0.0
     */
    public function ajax_refresh_logs() {
        // Check nonce
        check_ajax_referer('fbds_refresh_logs', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        
        $logger = new FileBird_Dropbox_Sync_Logger();
        $logs = $logger->get_recent_logs($limit);
        
        $logs_html = '';
        
        if (empty($logs)) {
            $logs_html = '<tr><td colspan="3" class="fbds-no-logs">' . __('No logs found.', 'filebird-dropbox-sync-pro') . '</td></tr>';
        } else {
            foreach ($logs as $log) {
                $logs_html .= '
                <tr class="fbds-log-level-' . esc_attr($log['level']) . '">
                    <td class="fbds-log-time">
                        ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $log['time']) . '
                    </td>
                    <td class="fbds-log-level">
                        <span class="fbds-log-badge fbds-log-badge-' . esc_attr($log['level']) . '">
                            ' . esc_html(ucfirst($log['level'])) . '
                        </span>
                    </td>
                    <td class="fbds-log-message">' . esc_html($log['message']) . '</td>
                </tr>';
            }
        }
        
        wp_send_json_success(array('logs_html' => $logs_html));
    }

    /**
     * AJAX handler for clearing logs.
     *
     * @since    1.0.0
     */
    public function ajax_clear_logs() {
        // Check nonce
        check_ajax_referer('fbds_clear_logs', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')));
        }
        
        $logger = new FileBird_Dropbox_Sync_Logger();
        $logger->clear_logs();
        
        wp_send_json_success(array('message' => __('Logs cleared successfully.', 'filebird-dropbox-sync-pro')));
    }

    /**
     * AJAX handler for downloading logs.
     *
     * @since    1.0.0
     */
    public function ajax_download_logs() {
        // Check nonce
        check_ajax_referer('fbds_download_logs', 'nonce');
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro'));
        }
        
        $logger = new FileBird_Dropbox_Sync_Logger();
        $log_content = $logger->get_log_file_contents();
        
        // Set headers for download
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="fbds-log-' . date('Y-m-d') . '.txt"');
        header('Content-Length: ' . strlen($log_content));
        
        // Output log content
        echo $log_content;
        exit;
    }

    /**
     * Handle Dropbox OAuth callback.
     *
     * @since    1.0.0
     */
    public function handle_dropbox_oauth_callback() {
        // Check if this is a Dropbox callback
        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            wp_die(__('Invalid callback request.', 'filebird-dropbox-sync-pro'));
        }
        
        // Verify state parameter (CSRF protection)
        if (!wp_verify_nonce($_GET['state'], 'fbds_dropbox_auth')) {
            wp_die(__('Security check failed.', 'filebird-dropbox-sync-pro'));
        }
        
        $code = sanitize_text_field($_GET['code']);
        
        // Exchange code for token
        $dropbox_api = new FileBird_Dropbox_API();
        $success = $dropbox_api->exchange_code_for_token($code);
        
        if ($success) {
            $this->logger->log('Successfully connected to Dropbox', 'info');
            
            // This is a popup window, so we just need to show a success message
            echo '<html><head><title>' . __('Dropbox Connected', 'filebird-dropbox-sync-pro') . '</title>';
            echo '<style>body { font-family: Arial, sans-serif; padding: 20px; text-align: center; }</style>';
            echo '</head><body>';
            echo '<h2>' . __('Successfully connected to Dropbox!', 'filebird-dropbox-sync-pro') . '</h2>';
            echo '<p>' . __('You can now close this window and return to the setup wizard.', 'filebird-dropbox-sync-pro') . '</p>';
            echo '<script>window.setTimeout(function() { window.close(); }, 3000);</script>';
            echo '</body></html>';
        } else {
            $this->logger->log('Failed to connect to Dropbox', 'error');
            
            echo '<html><head><title>' . __('Dropbox Connection Failed', 'filebird-dropbox-sync-pro') . '</title>';
            echo '<style>body { font-family: Arial, sans-serif; padding: 20px; text-align: center; }</style>';
            echo '</head><body>';
            echo '<h2>' . __('Failed to connect to Dropbox!', 'filebird-dropbox-sync-pro') . '</h2>';
            echo '<p>' . __('Please try again or contact support if the issue persists.', 'filebird-dropbox-sync-pro') . '</p>';
            echo '<button onclick="window.close();">' . __('Close', 'filebird-dropbox-sync-pro') . '</button>';
            echo '</body></html>';
        }
        
        exit;
    }

    /**
     * AJAX handler for wizard steps.
     *
     * @since    1.0.0
     */
    public function ajax_wizard_step() {
        // This is just a wrapper for the wizard class method
        $wizard = new FileBird_Dropbox_Sync_Setup_Wizard($this->plugin_name, $this->version);
        $wizard->handle_wizard_step();
    }
}