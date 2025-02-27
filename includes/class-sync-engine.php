<?php
/**
 * The synchronization engine class.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class Sync_Engine {

    /**
     * Dropbox API instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      FileBird_Dropbox_API    $dropbox_api    Dropbox API instance.
     */
    private $dropbox_api;

    /**
     * FileBird connector instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      FileBird_Connector    $filebird_connector    FileBird connector instance.
     */
    private $filebird_connector;

    /**
     * ACF connector instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      ACF_Connector    $acf_connector    ACF connector instance.
     */
    private $acf_connector;

    /**
     * Logger instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      FileBird_Dropbox_Sync_Logger    $logger    Logger instance.
     */
    private $logger;

    /**
     * Flag to indicate if a sync is in progress.
     *
     * @since    1.0.0
     * @access   private
     * @var      bool    $is_syncing    Whether a sync is in progress.
     */
    private $is_syncing = false;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    FileBird_Dropbox_API    $dropbox_api         Dropbox API instance.
     * @param    FileBird_Connector      $filebird_connector  FileBird connector instance.
     * @param    ACF_Connector           $acf_connector       ACF connector instance.
     */
    public function __construct($dropbox_api, $filebird_connector, $acf_connector) {
        $this->dropbox_api = $dropbox_api;
        $this->filebird_connector = $filebird_connector;
        $this->acf_connector = $acf_connector;
        $this->logger = new FileBird_Dropbox_Sync_Logger();
    }

    /**
     * Run a manual synchronization.
     *
     * @since    1.0.0
     */
    public function handle_manual_sync() {
        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'filebird-dropbox-sync-pro')]);
        }
        
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'fbds_manual_sync')) {
            wp_send_json_error(['message' => __('Security check failed.', 'filebird-dropbox-sync-pro')]);
        }
        
        // Get sync direction
        $direction = isset($_POST['direction']) ? sanitize_text_field($_POST['direction']) : 'both';
        
        // Start sync in background
        $this->schedule_sync($direction);
        
        wp_send_json_success(['message' => __('Synchronization has been scheduled.', 'filebird-dropbox-sync-pro')]);
    }

    /**
     * Schedule a synchronization.
     *
     * @since    1.0.0
     * @param    string    $direction    The sync direction (both, to_dropbox, from_dropbox).
     */
    public function schedule_sync($direction = 'both') {
        $sync_time = time() + 5; // Start in 5 seconds
        
        update_option('fbds_last_sync_status', 'scheduled');
        update_option('fbds_last_sync_direction', $direction);
        
        wp_schedule_single_event($sync_time, 'fbds_scheduled_sync', [$direction]);
        
        $this->logger->log('Sync scheduled with direction: ' . $direction, 'info');
    }

    /**
     * Run a scheduled synchronization.
     *
     * @since    1.0.0
     * @param    string    $direction    The sync direction (both, to_dropbox, from_dropbox).
     */
    public function run_scheduled_sync($direction = 'both') {
        if ($this->is_syncing) {
            $this->logger->log('Sync already in progress. Aborting.', 'warning');
            return;
        }
        
        $this->is_syncing = true;
        update_option('fbds_last_sync_status', 'in_progress');
        update_option('fbds_sync_start_time', time());
        
        $this->logger->log('Starting sync with direction: ' . $direction, 'info');
        
        // Track individual sync successes
        $filebird_to_dropbox_success = true;
        $dropbox_to_filebird_success = true;
        $filebird_to_acf_success = true;
        $error_messages = array();
        
        // Check Dropbox connection
        if (!$this->dropbox_api->is_connected()) {
            update_option('fbds_last_sync_status', 'failed');
            update_option('fbds_last_sync_error', 'Dropbox is not connected.');
            $this->logger->log('Sync failed: Dropbox is not connected.', 'error');
            $this->is_syncing = false;
            return;
        }
        
        // Run each sync direction independently to avoid total failure
        if ($direction === 'both' || $direction === 'to_dropbox') {
            try {
                $this->sync_filebird_to_dropbox();
            } catch (Exception $e) {
                $filebird_to_dropbox_success = false;
                $error_messages[] = 'FileBird to Dropbox: ' . $e->getMessage();
                $this->logger->log('FileBird to Dropbox sync failed: ' . $e->getMessage(), 'error');
            }
        }
        
        if ($direction === 'both' || $direction === 'from_dropbox') {
            try {
                $this->sync_dropbox_to_filebird();
            } catch (Exception $e) {
                $dropbox_to_filebird_success = false;
                $error_messages[] = 'Dropbox to FileBird: ' . $e->getMessage();
                $this->logger->log('Dropbox to FileBird sync failed: ' . $e->getMessage(), 'error');
            }
        }
        
        // Always try to sync FileBird to ACF
        try {
            $this->sync_filebird_to_acf();
        } catch (Exception $e) {
            $filebird_to_acf_success = false;
            $error_messages[] = 'FileBird to ACF: ' . $e->getMessage();
            $this->logger->log('FileBird to ACF sync failed: ' . $e->getMessage(), 'error');
        }
        
        // Determine overall sync status
        if ($filebird_to_dropbox_success && $dropbox_to_filebird_success && $filebird_to_acf_success) {
            update_option('fbds_last_sync_status', 'completed');
            update_option('fbds_last_sync_time', time());
            $this->logger->log('Sync completed successfully', 'info');
        } else if (!$filebird_to_dropbox_success && !$dropbox_to_filebird_success && !$filebird_to_acf_success) {
            // Complete failure
            update_option('fbds_last_sync_status', 'failed');
            update_option('fbds_last_sync_error', implode(' | ', $error_messages));
            $this->logger->log('Sync failed completely', 'error');
        } else {
            // Partial success
            update_option('fbds_last_sync_status', 'partial');
            update_option('fbds_last_sync_error', implode(' | ', $error_messages));
            update_option('fbds_last_sync_time', time());
            $this->logger->log('Sync partially completed with errors', 'warning');
        }
        
        $this->is_syncing = false;
    }

    /**
     * Synchronize FileBird folders and media to Dropbox.
     *
     * @since    1.0.0
     * @throws   Exception    If an error occurs during sync.
     */
    private function sync_filebird_to_dropbox() {
        $this->logger->log('Starting FileBird to Dropbox sync', 'info');
        
        // Get all FileBird folders
        $folders = $this->filebird_connector->get_all_folders();
        
        // Create folder structure in Dropbox
        foreach ($folders as $folder) {
            $dropbox_path = $this->get_dropbox_path_for_folder($folder);
            
            // Check if folder exists in Dropbox
            $metadata = $this->dropbox_api->get_metadata($dropbox_path);
            
            if (is_wp_error($metadata) || isset($metadata['error_summary'])) {
                // Folder doesn't exist, create it
                $result = $this->dropbox_api->create_folder($dropbox_path);
                
                if (is_wp_error($result) || isset($result['error_summary'])) {
                    $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
                    $this->logger->log('Error creating folder in Dropbox: ' . $error_message, 'error');
                    continue;
                }
                
                $this->logger->log('Created folder in Dropbox: ' . $dropbox_path, 'info');
            }
        }
        
        // Upload files from FileBird to Dropbox
        foreach ($folders as $folder) {
            $attachments = $this->filebird_connector->get_attachments_in_folder($folder->term_id);
            $dropbox_path = $this->get_dropbox_path_for_folder($folder);
            
            foreach ($attachments as $attachment) {
                $file_path = get_attached_file($attachment->ID);
                
                if (!$file_path || !file_exists($file_path)) {
                    $this->logger->log('File not found for attachment ID ' . $attachment->ID, 'warning');
                    continue;
                }
                
                $filename = basename($file_path);
                $dropbox_file_path = trailingslashit($dropbox_path) . $filename;
                
                // Check if file exists in Dropbox and compare modified times
                $metadata = $this->dropbox_api->get_metadata($dropbox_file_path);
                $attachment_modified = get_post_modified_time('U', true, $attachment->ID);
                
                if (!is_wp_error($metadata) && !isset($metadata['error_summary'])) {
                    // File exists in Dropbox, check if WordPress version is newer
                    $dropbox_modified = strtotime($metadata['server_modified']);
                    
                    if ($attachment_modified <= $dropbox_modified) {
                        // Dropbox version is newer or same age, skip upload
                        continue;
                    }
                }
                
                // Upload file to Dropbox
                $result = $this->dropbox_api->upload_file($file_path, $dropbox_file_path);
                
                if (is_wp_error($result) || isset($result['error_summary'])) {
                    $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
                    $this->logger->log('Error uploading file to Dropbox: ' . $error_message, 'error');
                    continue;
                }
                
                $this->logger->log('Uploaded file to Dropbox: ' . $dropbox_file_path, 'info');
            }
        }
        
        $this->logger->log('Completed FileBird to Dropbox sync', 'info');
    }

    /**
     * Synchronize Dropbox folders and files to FileBird.
     *
     * @since    1.0.0
     * @throws   Exception    If an error occurs during sync.
     */
    private function sync_dropbox_to_filebird() {
        $this->logger->log('Starting Dropbox to FileBird sync', 'info');
        
        // Get the root Dropbox folder path
        $root_path = '/FileBird'; // Default root path
        $root_path = apply_filters('fbds_dropbox_root_path', $root_path);
        
        try {
            // Check if Dropbox root folder exists, create if not
            $metadata = $this->dropbox_api->get_metadata($root_path);
            if (is_wp_error($metadata) || isset($metadata['error_summary'])) {
                // Try to create the root folder
                $this->logger->log('Creating root Dropbox folder: ' . $root_path, 'info');
                $result = $this->dropbox_api->create_folder($root_path);
                
                if (is_wp_error($result) || isset($result['error_summary'])) {
                    $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
                    throw new Exception('Error creating Dropbox root folder: ' . $error_message);
                }
            }
            
            // Recursively process Dropbox folders with batch handling
            $this->process_dropbox_folder($root_path, 0);
            
        } catch (Exception $e) {
            $this->logger->log('Error during Dropbox to FileBird sync: ' . $e->getMessage(), 'error');
            throw $e;
        }
        
        $this->logger->log('Completed Dropbox to FileBird sync', 'info');
    }

    /**
     * Process a Dropbox folder and sync it to FileBird.
     *
     * @since    1.0.0
     * @param    string    $dropbox_path   The Dropbox folder path.
     * @param    int       $parent_id      The parent folder ID in FileBird.
     * @throws   Exception                 If an error occurs during sync.
     */
    private function process_dropbox_folder($dropbox_path, $parent_id) {
        // List folder contents
        $contents = $this->dropbox_api->list_folder($dropbox_path);
        
        if (is_wp_error($contents) || isset($contents['error_summary'])) {
            $error_message = is_wp_error($contents) ? $contents->get_error_message() : $contents['error_summary'];
            $this->logger->log('Error listing Dropbox folder: ' . $error_message, 'error');
            return;
        }
        
        // Get or create FileBird folder
        $folder_name = basename($dropbox_path);
        if ($dropbox_path === '/FileBird') {
            // Root folder, use existing FileBird folders
            $filebird_folders = $this->filebird_connector->get_folders_by_parent($parent_id);
        } else {
            // Create or get FileBird folder
            $filebird_folder_id = $this->filebird_connector->get_folder_by_name($folder_name, $parent_id);
            
            if (!$filebird_folder_id) {
                $filebird_folder_id = $this->filebird_connector->create_folder($folder_name, $parent_id);
            }
            
            // Process files in this folder with batch processing
            $files = array_filter($contents['entries'], function($entry) {
                return $entry['.tag'] === 'file';
            });
            
            // Process in batches of 10 files
            $batch_size = 10;
            $file_batches = array_chunk($files, $batch_size);
            
            foreach ($file_batches as $batch) {
                foreach ($batch as $entry) {
                    $this->process_dropbox_file($entry, $filebird_folder_id);
                }
                
                // Give the server a small breather between batches
                if (count($file_batches) > 1) {
                    usleep(100000); // 0.1 second pause between batches
                }
            }
            
            // Process subfolders
            $folders = array_filter($contents['entries'], function($entry) {
                return $entry['.tag'] === 'folder';
            });
            
            foreach ($folders as $entry) {
                $this->process_dropbox_folder($entry['path_display'], $filebird_folder_id);
            }
        }
    }

    /**
     * Process a Dropbox file and sync it to FileBird.
     *
     * @since    1.0.0
     * @param    array    $file_entry     The Dropbox file entry.
     * @param    int      $folder_id      The folder ID in FileBird.
     * @throws   Exception                If an error occurs during sync.
     */
    private function process_dropbox_file($file_entry, $folder_id) {
        $dropbox_path = $file_entry['path_display'];
        $filename = basename($dropbox_path);
        $dropbox_modified = strtotime($file_entry['server_modified']);
        
        // Check file type against allowed types
        $settings = get_option('fbds_settings', []);
        $allowed_file_types = isset($settings['file_types']) ? $settings['file_types'] : ['jpg', 'jpeg', 'png', 'gif'];
        
        // Extract file extension
        $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Normalize 'jpeg' to 'jpg' for checking
        $check_extension = ($file_extension === 'jpeg') ? 'jpg' : $file_extension;
        
        // Skip file if it's not an allowed type
        if (!in_array($check_extension, $allowed_file_types)) {
            $this->logger->log("Skipping file {$filename} - file type {$file_extension} not in allowed types", 'info');
            return;
        }
        
        // Check if file already exists in WordPress
        $attachment_id = $this->filebird_connector->get_attachment_by_filename($filename);
        
        if ($attachment_id) {
            // File exists, check conflict resolution setting
            $conflict_resolution = isset($settings['conflict_resolution']) ? $settings['conflict_resolution'] : 'newer';
            $attachment_modified = get_post_modified_time('U', true, $attachment_id);
            
            if ($conflict_resolution === 'filebird') {
                // WordPress version always wins, skip download
                
                // Make sure the attachment is in the correct folder
                $current_folder_id = $this->filebird_connector->get_folder_for_attachment($attachment_id);
                
                if ($current_folder_id !== $folder_id) {
                    $this->filebird_connector->move_attachment_to_folder($attachment_id, $folder_id);
                }
                
                return;
            } else if ($conflict_resolution === 'newer' && $attachment_modified >= $dropbox_modified) {
                // WordPress version is newer or same age, skip download
                
                // Make sure the attachment is in the correct folder
                $current_folder_id = $this->filebird_connector->get_folder_for_attachment($attachment_id);
                
                if ($current_folder_id !== $folder_id) {
                    $this->filebird_connector->move_attachment_to_folder($attachment_id, $folder_id);
                }
                
                return;
            } else if ($conflict_resolution === 'both') {
                // Keep both versions - rename Dropbox version
                $name_parts = pathinfo($filename);
                $new_filename = $name_parts['filename'] . '-dropbox-' . date('Ymd-His', $dropbox_modified);
                if (isset($name_parts['extension'])) {
                    $new_filename .= '.' . $name_parts['extension'];
                }
                $filename = $new_filename;
                $attachment_id = null; // Force creating as new file
            }
            // For 'dropbox' resolution or 'newer' with dropbox being newer, continue with download
        }
        
        // Download file from Dropbox
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/fbds-temp-' . md5($filename . time());
        
        $downloaded = $this->dropbox_api->download_file($dropbox_path, $temp_file);
        
        if (!$downloaded) {
            $this->logger->log('Error downloading file from Dropbox: ' . $dropbox_path, 'error');
            return;
        }
        
        // Get file mime type
        $file_type = wp_check_filetype($filename);
        
        if (empty($file_type['type'])) {
            // Unknown file type, skip
            unlink($temp_file);
            $this->logger->log('Skipped unsupported file type: ' . $filename, 'warning');
            return;
        }
        
        // Upload file to WordPress media library
        $file_array = [
            'name' => $filename,
            'tmp_name' => $temp_file,
            'error' => 0,
            'size' => filesize($temp_file),
        ];
        
        // Add or update the attachment
        if ($attachment_id) {
            // Update existing attachment
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            // Get old attachment metadata
            $old_attachment = get_post($attachment_id);
            $old_file = get_attached_file($attachment_id);
            
            // Replace the file
            $new_attachment_data = [
                'ID' => $attachment_id,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', true),
            ];
            
            wp_update_post($new_attachment_data);
            
            if (file_exists($old_file)) {
                unlink($old_file);
            }
            
            $new_file = $upload_dir['basedir'] . '/' . wp_basename($filename);
            copy($temp_file, $new_file);
            update_attached_file($attachment_id, $new_file);
            
            // Generate metadata for the new file
            $attach_data = wp_generate_attachment_metadata($attachment_id, $new_file);
            wp_update_attachment_metadata($attachment_id, $attach_data);
            
        } else {
            // Create new attachment
            $attachment_id = media_handle_sideload($file_array, 0);
        }
        
        if (is_wp_error($attachment_id)) {
            unlink($temp_file);
            $this->logger->log('Error adding file to media library: ' . $attachment_id->get_error_message(), 'error');
            return;
        }
        
        // Move the attachment to the FileBird folder
        $this->filebird_connector->move_attachment_to_folder($attachment_id, $folder_id);
        
        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        
        $this->logger->log('Added/updated file in media library: ' . $filename, 'info');
    }

    /**
     * Synchronize FileBird folders with ACF gallery fields.
     *
     * @since    1.0.0
     * @throws   Exception    If an error occurs during sync.
     */
    private function sync_filebird_to_acf() {
        $this->logger->log('Starting FileBird to ACF sync', 'info');
        
        // Get mappings between FileBird folders and ACF fields
        $mappings = get_option('fbds_folder_field_mappings', []);
        
        if (empty($mappings)) {
            $this->logger->log('No folder-to-field mappings found', 'info');
            return;
        }
        
        foreach ($mappings as $mapping) {
            if (empty($mapping['folder_id']) || empty($mapping['field_key']) || empty($mapping['post_id'])) {
                continue;
            }
            
            $folder_id = $mapping['folder_id'];
            $field_key = $mapping['field_key'];
            $post_id = $mapping['post_id'];
            
            // Get attachments in folder
            $attachments = $this->filebird_connector->get_attachments_in_folder($folder_id);
            
            if (empty($attachments)) {
                // Clear the ACF field
                $this->acf_connector->update_gallery_field($post_id, $field_key, []);
                continue;
            }
            
            // Build array of attachment IDs
            $attachment_ids = [];
            foreach ($attachments as $attachment) {
                $attachment_ids[] = $attachment->ID;
            }
            
            // Update the ACF gallery field
            $this->acf_connector->update_gallery_field($post_id, $field_key, $attachment_ids);
            
            $this->logger->log('Updated ACF gallery field: ' . $field_key . ' on post ' . $post_id . ' with ' . count($attachment_ids) . ' images', 'info');
        }
        
        $this->logger->log('Completed FileBird to ACF sync', 'info');
    }

    /**
     * Get the Dropbox path for a FileBird folder.
     *
     * @since    1.0.0
     * @param    object    $folder    The FileBird folder object.
     * @return   string               The Dropbox path.
     */
    private function get_dropbox_path_for_folder($folder) {
        // Get base path from settings
        $root_path = '/FileBird'; // Default root path
        $root_path = apply_filters('fbds_dropbox_root_path', $root_path);
        
        // Build path based on folder hierarchy
        if ($folder->parent === 0) {
            return trailingslashit($root_path) . $folder->name;
        }
        
        // Get parent folder paths
        $path_parts = [];
        $path_parts[] = $folder->name;
        
        $current_parent = $folder->parent;
        while ($current_parent !== 0) {
            $parent_folder = $this->filebird_connector->get_folder($current_parent);
            
            if (!$parent_folder) {
                break;
            }
            
            $path_parts[] = $parent_folder->name;
            $current_parent = $parent_folder->parent;
        }
        
        // Reverse array to get correct path order
        $path_parts = array_reverse($path_parts);
        
        return trailingslashit($root_path) . implode('/', $path_parts);
    }

    /**
     * Handle FileBird folder created event.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID.
     * @param    array     $folder_data  The folder data.
     */
    public function on_filebird_folder_created($folder_id, $folder_data) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('FileBird folder created: ' . $folder_id, 'info');
        $this->schedule_sync('to_dropbox');
    }

    /**
     * Handle FileBird folder deleted event.
     *
     * @since    1.0.0
     * @param    int    $folder_id    The folder ID.
     */
    public function on_filebird_folder_deleted($folder_id) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('FileBird folder deleted: ' . $folder_id, 'info');
        $this->schedule_sync('to_dropbox');
    }

    /**
     * Handle FileBird folder renamed event.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID.
     * @param    string    $new_name     The new folder name.
     */
    public function on_filebird_folder_renamed($folder_id, $new_name) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('FileBird folder renamed: ' . $folder_id . ' to ' . $new_name, 'info');
        $this->schedule_sync('to_dropbox');
    }

    /**
     * Handle attachment added event.
     *
     * @since    1.0.0
     * @param    int    $attachment_id    The attachment ID.
     */
    public function on_attachment_added($attachment_id) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('Attachment added: ' . $attachment_id, 'info');
        
        // Schedule sync after a delay to allow for FileBird folder assignment
        $sync_time = time() + 10;
        wp_schedule_single_event($sync_time, 'fbds_scheduled_sync', ['to_dropbox']);
    }

    /**
     * Handle attachment deleted event.
     *
     * @since    1.0.0
     * @param    int    $attachment_id    The attachment ID.
     */
    public function on_attachment_deleted($attachment_id) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('Attachment deleted: ' . $attachment_id, 'info');
        $this->schedule_sync('to_dropbox');
    }

    /**
     * Handle attachment moved event.
     *
     * @since    1.0.0
     * @param    int    $attachment_id    The attachment ID.
     * @param    int    $from_folder      The source folder ID.
     * @param    int    $to_folder        The destination folder ID.
     */
    public function on_attachment_moved($attachment_id, $from_folder, $to_folder) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('Attachment moved: ' . $attachment_id . ' from folder ' . $from_folder . ' to folder ' . $to_folder, 'info');
        $this->schedule_sync('to_dropbox');
    }
}