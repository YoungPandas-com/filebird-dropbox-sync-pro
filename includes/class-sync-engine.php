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
     * Root path for Dropbox.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $root_path    The root path in Dropbox.
     */
    private $root_path = '/Website';

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
        
        // Set default root path to /Website as requested
        $this->root_path = '/Website';
        
        // Apply filter to allow customization (but we default to /Website)
        $this->root_path = apply_filters('fbds_dropbox_root_path', $this->root_path);
        
        // Log the configured root path for debugging
        $this->logger->log('Configured Dropbox root path: ' . $this->root_path, 'info');
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
    
    // Clear any existing scheduled syncs for this direction to prevent duplicates
    wp_clear_scheduled_hook('fbds_scheduled_sync', [$direction]);
    
    // Schedule the sync
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
    // Check if another sync is in progress
    if ($this->is_syncing) {
        $this->logger->log('Sync already in progress. Adding to queue.', 'warning');
        
        // Schedule this sync for later
        $queued_time = time() + 120; // Try again in 2 minutes
        wp_schedule_single_event($queued_time, 'fbds_scheduled_sync', [$direction]);
        
        return;
    }
    
    // Set the sync flag
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
    
    // Release the sync flag
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
        $this->logger->log('Using root path: ' . $this->root_path, 'info');
        
        // Get all FileBird folders
        $folders = $this->filebird_connector->get_all_folders();
        
        // Make sure root folder exists first
        $root_metadata = $this->dropbox_api->get_metadata($this->root_path);
        if (is_wp_error($root_metadata) || isset($root_metadata['error_summary'])) {
            // Root doesn't exist, create it
            $this->logger->log('Creating root Dropbox folder: ' . $this->root_path, 'info');
            $result = $this->dropbox_api->create_folder($this->root_path);
            
            if (is_wp_error($result) || isset($result['error_summary'])) {
                $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
                throw new Exception('Error creating root Dropbox folder: ' . $error_message);
                }
            }
        
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
                    
                    // Get conflict resolution setting
                    $settings = get_option('fbds_settings', []);
                    $conflict_resolution = isset($settings['conflict_resolution']) ? $settings['conflict_resolution'] : 'newer';
                    
                    if ($conflict_resolution === 'filebird' || ($conflict_resolution === 'newer' && $attachment_modified <= $dropbox_modified)) {
                        // Skip upload based on conflict resolution setting
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
        $this->logger->log('Using Dropbox root path: ' . $this->root_path, 'info');
        
        try {
            // Check if Dropbox root folder exists, create if not
            $metadata = $this->dropbox_api->get_metadata($this->root_path);
            if (is_wp_error($metadata) || isset($metadata['error_summary'])) {
                // Try to create the root folder
                $this->logger->log('Creating root Dropbox folder: ' . $this->root_path, 'info');
                $result = $this->dropbox_api->create_folder($this->root_path);
                
                if (is_wp_error($result) || isset($result['error_summary'])) {
                    $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
                    throw new Exception('Error creating Dropbox root folder: ' . $error_message);
                }
            }
            
            // Now list the contents of the root folder
            $contents = $this->dropbox_api->list_folder($this->root_path);
            
            if (is_wp_error($contents) || isset($contents['error_summary'])) {
                $error_message = is_wp_error($contents) ? $contents->get_error_message() : $contents['error_summary'];
                $this->logger->log('Error response from Dropbox API: ' . print_r($contents, true), 'error');
                throw new Exception('Error listing Dropbox folder ' . $this->root_path . ': ' . $error_message);
            }
            
            $this->logger->log('Successfully listed contents of Dropbox root folder: ' . $this->root_path, 'info');
            
            // Process folders in the root - these will map to top-level FileBird folders
            $folders = array_filter($contents['entries'], function($entry) {
                return $entry['.tag'] === 'folder';
            });
            
            $this->logger->log('Found ' . count($folders) . ' folders in Dropbox root', 'info');
            foreach ($folders as $folder) {
                $this->logger->log('Found folder: ' . $folder['path_display'], 'info');
            }
            
            // Find existing top-level FileBird folders for mapping
            $existing_folders = $this->filebird_connector->get_folders_by_parent(0);
            $this->logger->log('Found ' . count($existing_folders) . ' top-level FileBird folders', 'info');
            
            // Create a mapping between Dropbox folder names and FileBird folder IDs
            $folder_map = [];
            foreach ($existing_folders as $fb_folder) {
                $folder_map[$fb_folder->name] = $fb_folder->term_id;
            }
            
            // Process each folder in the Dropbox root
            foreach ($folders as $entry) {
                $folder_name = basename($entry['path_display']);
                $this->logger->log('Processing root Dropbox folder: ' . $folder_name, 'info');
                
                // Check if a FileBird folder with this name already exists
                $filebird_folder_id = isset($folder_map[$folder_name]) ? $folder_map[$folder_name] : null;
                
                if (!$filebird_folder_id) {
                    // Create a new FileBird folder
                    $filebird_folder_id = $this->filebird_connector->create_folder($folder_name, 0);
                    $this->logger->log('Created top-level FileBird folder: ' . $folder_name . ' (ID: ' . $filebird_folder_id . ')', 'info');
                } else {
                    $this->logger->log('Using existing top-level FileBird folder: ' . $folder_name . ' (ID: ' . $filebird_folder_id . ')', 'info');
                }
                
                if (!$filebird_folder_id) {
                    $this->logger->log('Failed to create/find FileBird folder for: ' . $folder_name, 'error');
                    continue;
                }
                
                // Process this folder and its subfolders
                $this->process_dropbox_folder($entry['path_display'], $filebird_folder_id);
            }
            
            // Process files in the root folder (if any)
            $files = array_filter($contents['entries'], function($entry) {
                return $entry['.tag'] === 'file';
            });
            
            if (!empty($files)) {
                // For files directly in the root, we'll need to create or use a special "Website Root" folder
                $root_folder_name = 'Website Root';
                $root_folder_id = $this->filebird_connector->get_folder_by_name($root_folder_name, 0);
                
                if (!$root_folder_id) {
                    $root_folder_id = $this->filebird_connector->create_folder($root_folder_name, 0);
                    $this->logger->log('Created special FileBird folder for Dropbox root files: ' . $root_folder_name, 'info');
                }
                
                if ($root_folder_id) {
                    foreach ($files as $file_entry) {
                        $this->process_dropbox_file($file_entry, $root_folder_id);
                    }
                }
            }
            
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
        // List folder contents with retries
        $contents = false;
        $retries = 3;
        
        $this->logger->log("Processing Dropbox folder: {$dropbox_path}", 'info');
        
        while (!$contents && $retries > 0) {
            $contents = $this->dropbox_api->list_folder($dropbox_path);
            if (is_wp_error($contents) || isset($contents['error_summary'])) {
                $retries--;
                if ($retries > 0) {
                    // Wait before retry
                    sleep(1);
                }
            }
        }
        
        if (is_wp_error($contents) || isset($contents['error_summary'])) {
            $error_message = is_wp_error($contents) ? $contents->get_error_message() : $contents['error_summary'];
            $this->logger->log('Error listing Dropbox folder: ' . $error_message, 'error');
            return;
        }
        
        // Get or create FileBird folder
        $folder_name = basename($dropbox_path);
        $filebird_folder_id = $parent_id; // Default to parent_id
        
        // IMPORTANT: Remove this check as it references /FileBird directly
        // if ($dropbox_path !== '/FileBird') {
        // Always process the folder regardless of its path
        $this->logger->log('Processing Dropbox folder: ' . $dropbox_path . ' with parent: ' . $parent_id, 'info');
        
        // Try multiple times to get or create the folder
        $retries = 3;
        while ($retries > 0) {
            $filebird_folder_id = $this->filebird_connector->get_folder_by_name($folder_name, $parent_id);
            
            if (!$filebird_folder_id) {
                $filebird_folder_id = $this->filebird_connector->create_folder($folder_name, $parent_id);
                if ($filebird_folder_id) {
                    $this->logger->log('Created FileBird folder: ' . $folder_name . ' (ID: ' . $filebird_folder_id . ') with parent: ' . $parent_id, 'info');
                    break;
                }
                $retries--;
                if ($retries > 0) {
                    // Wait before retry
                    usleep(500000); // 0.5 seconds
                }
            } else {
                $this->logger->log('Found existing FileBird folder: ' . $folder_name . ' (ID: ' . $filebird_folder_id . ')', 'info');
                break;
            }
        }
        
        // Verify folder was created/found
        if (!$filebird_folder_id) {
            $this->logger->log('Failed to create or find FileBird folder for: ' . $dropbox_path, 'error');
            return;
        }
        
        // Process files in this folder with batch processing
        $files = array_filter($contents['entries'], function($entry) {
            return $entry['.tag'] === 'file';
        });
        
        // Process in batches of 5 files (smaller batch size to reduce timeout issues)
        $batch_size = 5;
        $file_batches = array_chunk($files, $batch_size);
        
        foreach ($file_batches as $batch) {
            foreach ($batch as $entry) {
                $this->logger->log('Processing file: ' . $entry['path_display'] . ' for folder ID: ' . $filebird_folder_id, 'info');
                $this->process_dropbox_file($entry, $filebird_folder_id);
            }
            
            // Give the server a longer breather between batches
            if (count($file_batches) > 1) {
                sleep(1); // 1 second pause between batches to prevent timeouts
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
        
        // Check if folder_id is valid
        if (empty($folder_id) || !is_numeric($folder_id)) {
            $this->logger->log("Invalid folder_id for file {$filename}: " . print_r($folder_id, true), 'error');
            return;
        }
        
        // Verify the FileBird folder exists
        $folder = $this->filebird_connector->get_folder($folder_id);
        if (!$folder) {
            $this->logger->log("FileBird folder ID {$folder_id} not found for file {$filename}", 'error');
            return;
        }
        
        $this->logger->log("Processing file {$filename} for FileBird folder: {$folder->name} (ID: {$folder_id})", 'info');
        
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
        $this->logger->log("Checking if file exists in WordPress: " . ($attachment_id ? "Yes (ID: {$attachment_id})" : "No"), 'info');
        
        if ($attachment_id) {
            // File exists, check conflict resolution setting
            $conflict_resolution = isset($settings['conflict_resolution']) ? $settings['conflict_resolution'] : 'newer';
            $attachment_modified = get_post_modified_time('U', true, $attachment_id);
            
            if ($conflict_resolution === 'filebird') {
                // WordPress version always wins, skip download
                $this->logger->log("Conflict resolution is 'filebird', keeping WordPress version", 'info');
                
                // Make sure the attachment is in the correct folder
                $current_folder_id = $this->filebird_connector->get_folder_for_attachment($attachment_id);
                
                if ($current_folder_id != $folder_id) {
                    $result = $this->filebird_connector->move_attachment_to_folder($attachment_id, $folder_id);
                    if ($result) {
                        $this->logger->log("Moved existing attachment {$attachment_id} to folder {$folder_id}", 'info');
                    } else {
                        $this->logger->log("Failed to move attachment {$attachment_id} to folder {$folder_id}", 'error');
                    }
                }
                
                return;
            } else if ($conflict_resolution === 'newer' && $attachment_modified >= $dropbox_modified) {
                // WordPress version is newer or same age, skip download
                $this->logger->log("WordPress version is newer or same age, skipping download", 'info');
                
                // Make sure the attachment is in the correct folder
                $current_folder_id = $this->filebird_connector->get_folder_for_attachment($attachment_id);
                
                if ($current_folder_id != $folder_id) {
                    $result = $this->filebird_connector->move_attachment_to_folder($attachment_id, $folder_id);
                    if ($result) {
                        $this->logger->log("Moved existing attachment {$attachment_id} to folder {$folder_id}", 'info');
                    } else {
                        $this->logger->log("Failed to move attachment {$attachment_id} to folder {$folder_id}", 'error');
                    }
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
                $this->logger->log("Conflict resolution is 'both', creating new file with name: {$filename}", 'info');
            } else {
                $this->logger->log("Conflict resolution is '{$conflict_resolution}', proceeding with download", 'info');
            }
            // For 'dropbox' resolution or 'newer' with dropbox being newer, continue with download
        }
        
        // Download file from Dropbox
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/fbds-temp-' . md5($filename . time());
        
        $this->logger->log("Downloading file from Dropbox: {$dropbox_path}", 'info');
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
            
            $this->logger->log("Updated existing attachment ID: {$attachment_id}", 'info');
            
        } else {
            // Create new attachment
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            
            $attachment_id = media_handle_sideload($file_array, 0);
            
            $this->logger->log("Created new attachment via sideload: " . (is_wp_error($attachment_id) ? $attachment_id->get_error_message() : "Success, ID: {$attachment_id}"), 'info');
        }
        
        if (is_wp_error($attachment_id)) {
            unlink($temp_file);
            $this->logger->log('Error adding file to media library: ' . $attachment_id->get_error_message(), 'error');
            return;
        }
        
        $this->logger->log("Successfully added/updated attachment ID: {$attachment_id}", 'info');
        
        // Move the attachment to the FileBird folder - try up to 3 times
        $success = false;
        $attempts = 0;
        
        while (!$success && $attempts < 3) {
            $attempts++;
            $success = $this->filebird_connector->move_attachment_to_folder($attachment_id, $folder_id);
            
            if (!$success) {
                $this->logger->log("Attempt {$attempts}: Failed to move attachment {$attachment_id} to folder {$folder_id}", 'warning');
                usleep(500000); // Wait half a second before retrying
            }
        }
        
        if ($success) {
            $this->logger->log("Successfully moved attachment {$attachment_id} to folder {$folder_id}", 'info');
        } else {
            $this->logger->log("All attempts failed: Could not move attachment {$attachment_id} to folder {$folder_id}", 'error');
        }
        
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
        return $this->get_dropbox_path_for_filebird_folder($folder->term_id);
    }

    /**
     * Get FileID folder ID for a Dropbox path
     * This helps ensure consistent mapping between Dropbox and FileBird
     *
     * @since    1.0.0
     * @param    string    $dropbox_path    The Dropbox folder path.
     * @param    bool      $create          Whether to create folders if they don't exist.
     * @return   int|false                  The FileBird folder ID or false if not found.
     */
    private function get_filebird_folder_for_dropbox_path($dropbox_path, $create = true) {
        // Use the same root path as defined in the constructor - this is /Website
        $root_path = $this->root_path;
        
        // If this is the root path, return 0 (FileBird root)
        if ($dropbox_path === $root_path) {
            return 0;
        }
        
        // Ensure path starts with the root path
        if (strpos($dropbox_path, $root_path) !== 0) {
            $this->logger->log("Dropbox path not within root path: {$dropbox_path}, root: {$root_path}", 'error');
            return false;
        }
        
        // Remove root path and split into parts
        $relative_path = trim(substr($dropbox_path, strlen($root_path)), '/');
        if (empty($relative_path)) {
            return 0; // Root folder
        }
        
        $path_parts = explode('/', $relative_path);
        
        // Start from FileBird root
        $parent_id = 0;
        $current_path = $root_path;
        
        // Navigate through each part of the path, creating folders as needed
        foreach ($path_parts as $part) {
            if (empty($part)) continue; // Skip empty parts
            
            $current_path .= '/' . $part;
            $this->logger->log("Processing path part: {$part}, current path: {$current_path}", 'info');
            
            // Look for existing folder with maximum retries
            $folder_id = false;
            $retries = 3;
            
            while (!$folder_id && $retries > 0) {
                $folder_id = $this->filebird_connector->get_folder_by_name($part, $parent_id);
                if (!$folder_id) {
                    $retries--;
                    if ($retries > 0) {
                        // Short pause before retry
                        usleep(100000); // 0.1 seconds
                    }
                }
            }
            
            if (!$folder_id && $create) {
                // Create folder if it doesn't exist, with retries
                $retries = 3;
                while (!$folder_id && $retries > 0) {
                    $this->logger->log("Creating folder: {$part} with parent: {$parent_id}", 'info');
                    $folder_id = $this->filebird_connector->create_folder($part, $parent_id);
                    if (!$folder_id) {
                        $retries--;
                        if ($retries > 0) {
                            // Short pause before retry
                            usleep(200000); // 0.2 seconds
                        }
                    }
                }
                
                if ($folder_id) {
                    $this->logger->log("Created FileBird folder: {$part} (ID: {$folder_id}) with parent: {$parent_id}", 'info');
                }
            }
            
            if (!$folder_id) {
                $this->logger->log("Could not find/create FileBird folder for path: {$current_path}", 'error');
                return false;
            }
            
            $parent_id = $folder_id;
        }
        
        return $parent_id;
    }


/**
 * Get the Dropbox path for a FileBird folder with more reliable path construction
 *
 * @since    1.0.0
 * @param    int       $folder_id    The FileBird folder ID.
 * @return   string|false           The Dropbox path or false if not found.
 */
private function get_dropbox_path_for_filebird_folder($folder_id) {
    // Use the consistent root path
    $root_path = $this->root_path;  // This will be /Website
    
    // Root folder case
    if ($folder_id === 0) {
        return $root_path;
    }
    
    // Get the folder - with additional error handling
    $folder = $this->filebird_connector->get_folder($folder_id);
    if (!$folder) {
        // Log the error but don't stop sync - attempt to continue with root path
        $this->logger->log("Could not find FileBird folder with ID: {$folder_id}, using root path", 'warning');
        return $root_path;
    }
    
    // Get the complete path by traversing up the folder hierarchy
    $path_parts = array();
    $path_parts[] = $folder->name;
    
    $current_parent = $folder->parent;
    $max_depth = 10; // Prevent infinite loops
    $depth = 0;
    
    while ($current_parent !== 0 && $depth < $max_depth) {
        $parent_folder = $this->filebird_connector->get_folder($current_parent);
        
        if (!$parent_folder) {
            $this->logger->log("Could not find parent folder with ID: {$current_parent}, using path parts collected so far", 'warning');
            break;
        }
        
        array_unshift($path_parts, $parent_folder->name);
        $current_parent = $parent_folder->parent;
        $depth++;
    }
    
    // Build the full path
    $full_path = $root_path;
    foreach ($path_parts as $part) {
        $full_path .= '/' . $part;
    }
    
    return $full_path;
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