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
 * Schedule a synchronization with more optimized timing.
 *
 * @since    1.0.0
 * @param    string    $direction    The sync direction (both, to_dropbox, from_dropbox).
 * @param    int       $delay        Optional delay in seconds (default 5).
 */
public function schedule_sync($direction = 'both', $delay = 5) {
    $sync_time = time() + $delay; // Start after specified delay
    
    // Clear any existing scheduled syncs for this direction to prevent duplicates
    wp_clear_scheduled_hook('fbds_scheduled_sync', [$direction]);
    
    // Set status as scheduled
    update_option('fbds_last_sync_status', 'scheduled');
    update_option('fbds_last_sync_direction', $direction);
    update_option('fbds_last_sync_scheduled_time', $sync_time);
    
    // Schedule the sync
    wp_schedule_single_event($sync_time, 'fbds_scheduled_sync', [$direction]);
    
    $this->logger->log("Sync scheduled with direction: {$direction} to run in {$delay} seconds", 'info');
}

/**
 * Run a scheduled synchronization with improved error recovery.
 *
 * @since    1.0.0
 * @param    string    $direction    The sync direction (both, to_dropbox, from_dropbox).
 */
public function run_scheduled_sync($direction = 'both') {
    // If another sync is in progress, queue this one for later
    if ($this->is_syncing) {
        $this->logger->log('Sync already in progress, rescheduling for later', 'warning');
        
        // Try again in 60 seconds
        $this->schedule_sync($direction, 60);
        return;
    }
    
    // Set sync flag
    $this->is_syncing = true;
    update_option('fbds_last_sync_status', 'in_progress');
    update_option('fbds_sync_start_time', time());
    
    $this->logger->log("Starting sync with direction: {$direction}", 'info');
    
    // Check Dropbox connection first
    if (!$this->dropbox_api->is_connected()) {
        update_option('fbds_last_sync_status', 'failed');
        update_option('fbds_last_sync_error', 'Dropbox is not connected');
        $this->logger->log('Sync failed: Dropbox is not connected', 'error');
        $this->is_syncing = false;
        return;
    }
    
    // Refresh Dropbox token if needed
    if (!$this->dropbox_api->check_and_refresh_token()) {
        update_option('fbds_last_sync_status', 'failed');
        update_option('fbds_last_sync_error', 'Unable to refresh Dropbox access token');
        $this->logger->log('Sync failed: Unable to refresh Dropbox token', 'error');
        $this->is_syncing = false;
        return;
    }
    
    // Track sync status
    $to_dropbox_success = true;
    $from_dropbox_success = true;
    $to_acf_success = true;
    $errors = [];
    
    // Run sync in appropriate direction
    if ($direction === 'both' || $direction === 'to_dropbox') {
        try {
            $this->sync_filebird_to_dropbox();
        } catch (Exception $e) {
            $to_dropbox_success = false;
            $errors[] = 'FileBird to Dropbox: ' . $e->getMessage();
            $this->logger->log('FileBird to Dropbox sync failed: ' . $e->getMessage(), 'error');
        }
    }
    
    if ($direction === 'both' || $direction === 'from_dropbox') {
        try {
            $this->sync_dropbox_to_filebird();
        } catch (Exception $e) {
            $from_dropbox_success = false;
            $errors[] = 'Dropbox to FileBird: ' . $e->getMessage();
            $this->logger->log('Dropbox to FileBird sync failed: ' . $e->getMessage(), 'error');
        }
    }
    
    // Always sync FileBird to ACF
    try {
        $this->sync_filebird_to_acf();
    } catch (Exception $e) {
        $to_acf_success = false;
        $errors[] = 'FileBird to ACF: ' . $e->getMessage();
        $this->logger->log('FileBird to ACF sync failed: ' . $e->getMessage(), 'error');
    }
    
    // Update sync status
    if ($to_dropbox_success && $from_dropbox_success && $to_acf_success) {
        update_option('fbds_last_sync_status', 'completed');
        update_option('fbds_last_sync_time', time());
        $this->logger->log('Sync completed successfully', 'info');
    } else if (!$to_dropbox_success && !$from_dropbox_success && !$to_acf_success) {
        update_option('fbds_last_sync_status', 'failed');
        update_option('fbds_last_sync_error', implode(' | ', $errors));
        $this->logger->log('Sync failed completely', 'error');
    } else {
        update_option('fbds_last_sync_status', 'partial');
        update_option('fbds_last_sync_error', implode(' | ', $errors));
        update_option('fbds_last_sync_time', time());
        $this->logger->log('Sync partially completed with errors', 'warning');
        
        // Retry failed directions after a short delay
        if (!$to_dropbox_success && ($direction === 'both' || $direction === 'to_dropbox')) {
            $this->logger->log('Scheduling retry for FileBird to Dropbox sync', 'info');
            $this->schedule_sync('to_dropbox', 60); // 1 minute
        }
        
        if (!$from_dropbox_success && ($direction === 'both' || $direction === 'from_dropbox')) {
            $this->logger->log('Scheduling retry for Dropbox to FileBird sync', 'info');
            $this->schedule_sync('from_dropbox', 90); // 1.5 minutes
        }
    }
    
    // Reset sync flag
    $this->is_syncing = false;
}

/**
 * Synchronize FileBird folders and media to Dropbox with improved performance and reliability.
 *
 * @since    1.0.0
 * @throws   Exception    If an error occurs during sync.
 */
private function sync_filebird_to_dropbox() {
    $this->logger->log('Starting FileBird to Dropbox sync', 'info');
    $this->logger->log('Using root path: ' . $this->root_path, 'info');
    
    try {
        // Ensure Dropbox root exists
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
        
        // Get all FileBird folders
        $folders = $this->filebird_connector->get_all_folders();
        
        if (empty($folders)) {
            $this->logger->log('No FileBird folders found', 'warning');
            return;
        }
        
        $this->logger->log('Found ' . count($folders) . ' FileBird folders to process', 'info');
        
        // Process each FileBird folder
        foreach ($folders as $folder) {
            // Determine Dropbox path for this folder
            $dropbox_path = $this->get_dropbox_path_for_folder($folder);
            
            // Check if folder already exists in Dropbox
            $metadata = $this->dropbox_api->get_metadata($dropbox_path);
            
            if (is_wp_error($metadata) || isset($metadata['error_summary'])) {
                // Create the folder in Dropbox
                $this->logger->log('Creating Dropbox folder: ' . $dropbox_path, 'info');
                $result = $this->dropbox_api->create_folder($dropbox_path);
                
                if (is_wp_error($result) || isset($result['error_summary'])) {
                    $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
                    $this->logger->log('Error creating folder in Dropbox: ' . $error_message, 'error');
                    // Continue with next folder instead of stopping entirely
                    continue;
                }
                
                $this->logger->log('Successfully created folder in Dropbox: ' . $dropbox_path, 'info');
            } else {
                $this->logger->log('Dropbox folder already exists: ' . $dropbox_path, 'info');
            }
            
            // Process attachments in this folder
            $this->sync_folder_attachments_to_dropbox($folder->term_id, $dropbox_path);
        }
        
        $this->logger->log('Completed FileBird to Dropbox sync', 'info');
        
    } catch (Exception $e) {
        $this->logger->log('Error during FileBird to Dropbox sync: ' . $e->getMessage(), 'error');
        throw $e;
    }
}

/**
 * Sync attachments from a FileBird folder to Dropbox.
 *
 * @since    1.0.0
 * @param    int       $folder_id      The FileBird folder ID.
 * @param    string    $dropbox_path   The Dropbox folder path.
 */
private function sync_folder_attachments_to_dropbox($folder_id, $dropbox_path) {
    // Get attachments in this folder
    $attachments = $this->filebird_connector->get_attachments_in_folder($folder_id);
    
    if (empty($attachments)) {
        $this->logger->log('No attachments in folder ID: ' . $folder_id, 'info');
        return;
    }
    
    $this->logger->log('Processing ' . count($attachments) . ' attachments in folder', 'info');
    
    // Get conflict resolution setting
    $settings = get_option('fbds_settings', []);
    $conflict_resolution = isset($settings['conflict_resolution']) ? $settings['conflict_resolution'] : 'newer';
    
    // Process each attachment
    foreach ($attachments as $attachment) {
        $file_path = get_attached_file($attachment->ID);
        
        if (!$file_path || !file_exists($file_path)) {
            $this->logger->log('File not found for attachment ID ' . $attachment->ID, 'warning');
            continue;
        }
        
        $filename = basename($file_path);
        $dropbox_file_path = trailingslashit($dropbox_path) . $filename;
        
        // Check if file exists in Dropbox
        $metadata = $this->dropbox_api->get_metadata($dropbox_file_path);
        $attachment_modified = get_post_modified_time('U', true, $attachment->ID);
        
        if (!is_wp_error($metadata) && !isset($metadata['error_summary'])) {
            // File exists in Dropbox, check modification times
            $dropbox_modified = strtotime($metadata['server_modified']);
            
            // Skip based on conflict resolution
            if ($conflict_resolution === 'dropbox' || 
                ($conflict_resolution === 'newer' && $attachment_modified <= $dropbox_modified)) {
                $this->logger->log('Skipping upload based on conflict resolution: ' . $filename, 'info');
                continue;
            }
        }
        
        // Upload file to Dropbox
        $this->logger->log('Uploading ' . $filename . ' to Dropbox', 'info');
        $result = $this->dropbox_api->upload_file($file_path, $dropbox_file_path);
        
        if (is_wp_error($result) || isset($result['error_summary'])) {
            $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
            $this->logger->log('Error uploading file to Dropbox: ' . $error_message, 'error');
        } else {
            $this->logger->log('Successfully uploaded ' . $filename . ' to Dropbox', 'info');
        }
    }
}

    /**
     * Synchronize Dropbox folders and files to FileBird.
     *
     * @since    1.0.0
     * @throws   Exception    If an error occurs during sync.
     */
    private function sync_dropbox_to_filebird() {
        $this->logger->log('Starting Dropbox to FileBird sync with DETAILED logging', 'info');
        $this->logger->log('Using Dropbox root path: ' . $this->root_path, 'info');
        
        try {
            // Check if Dropbox root folder exists, create if not
            $metadata = $this->dropbox_api->get_metadata($this->root_path);
            if (is_wp_error($metadata) || isset($metadata['error_summary'])) {
                // Try to create the root folder
                $this->logger->log('Root path not found in Dropbox. Creating: ' . $this->root_path, 'info');
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
            
            $this->logger->log('Successfully listed contents of Dropbox root folder, found ' . 
                count($contents['entries']) . ' entries', 'info');
            
            // Process folders and files in the root
            $folders = array_filter($contents['entries'], function($entry) {
                return $entry['.tag'] === 'folder';
            });
            
            $files = array_filter($contents['entries'], function($entry) {
                return $entry['.tag'] === 'file';
            });
            
            $this->logger->log('Found ' . count($folders) . ' folders and ' . 
                count($files) . ' files in Dropbox root', 'info');
                
            // Process each folder in the Dropbox root
            foreach ($folders as $entry) {
                $folder_name = basename($entry['path_display']);
                $this->logger->log('Processing Dropbox folder: ' . $folder_name, 'info');
                
                // Create or get FileBird folder for this Dropbox folder
                $filebird_folder_id = $this->filebird_connector->get_folder_by_name($folder_name, 0);
                
                if (!$filebird_folder_id) {
                    $this->logger->log('Creating new FileBird folder: ' . $folder_name, 'info');
                    $filebird_folder_id = $this->filebird_connector->create_folder($folder_name, 0);
                    
                    if (!$filebird_folder_id) {
                        $this->logger->log('Failed to create FileBird folder: ' . $folder_name, 'error');
                        continue;
                    }
                    
                    $this->logger->log('Created FileBird folder with ID: ' . $filebird_folder_id, 'info');
                } else {
                    $this->logger->log('Found existing FileBird folder with ID: ' . $filebird_folder_id, 'info');
                }
                
                // Process this folder and its subfolders recursively
                $this->process_dropbox_folder($entry['path_display'], $filebird_folder_id);
            }
            
            // Process files in the root folder (if any)
            if (!empty($files)) {
                // For files directly in the root, create a special "Website Root" folder
                $root_folder_name = 'Website Root';
                $root_folder_id = $this->filebird_connector->get_folder_by_name($root_folder_name, 0);
                
                if (!$root_folder_id) {
                    $this->logger->log('Creating special FileBird folder for root files: ' . $root_folder_name, 'info');
                    $root_folder_id = $this->filebird_connector->create_folder($root_folder_name, 0);
                }
                
                if ($root_folder_id) {
                    $this->logger->log('Processing ' . count($files) . ' files in Dropbox root', 'info');
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
        
        $folder_name = basename($dropbox_path);
        $this->logger->log("Processing Dropbox folder: {$dropbox_path} with parent ID: {$parent_id}", 'info');
        
        while (!$contents && $retries > 0) {
            $contents = $this->dropbox_api->list_folder($dropbox_path);
            if (is_wp_error($contents) || isset($contents['error_summary'])) {
                $retries--;
                if ($retries > 0) {
                    $this->logger->log("Retrying folder listing ({$retries} attempts left)", 'warning');
                    sleep(1);
                }
            }
        }
        
        if (is_wp_error($contents) || isset($contents['error_summary'])) {
            $error_message = is_wp_error($contents) ? $contents->get_error_message() : $contents['error_summary'];
            $this->logger->log('Error listing Dropbox folder: ' . $error_message, 'error');
            return;
        }
        
        $this->logger->log('Successfully listed folder contents, found ' . 
            count($contents['entries']) . ' entries', 'info');
        
        // Process files in this folder
        $files = array_filter($contents['entries'], function($entry) {
            return $entry['.tag'] === 'file';
        });
        
        if (!empty($files)) {
            $this->logger->log('Processing ' . count($files) . ' files in folder: ' . $folder_name, 'info');
            
            // Process files in batches to prevent timeouts
            $batch_size = 5;
            $file_batches = array_chunk($files, $batch_size);
            
            foreach ($file_batches as $batch) {
                foreach ($batch as $file_entry) {
                    $this->process_dropbox_file($file_entry, $parent_id);
                }
                
                // Short pause between batches
                if (count($file_batches) > 1) {
                    usleep(500000); // 0.5 second pause
                }
            }
        }
        
        // Process subfolders
        $folders = array_filter($contents['entries'], function($entry) {
            return $entry['.tag'] === 'folder';
        });
        
        if (!empty($folders)) {
            $this->logger->log('Processing ' . count($folders) . ' subfolders in folder: ' . $folder_name, 'info');
            
            foreach ($folders as $folder_entry) {
                $subfolder_name = basename($folder_entry['path_display']);
                $this->logger->log('Processing subfolder: ' . $subfolder_name, 'info');
                
                // Create or get FileBird folder for this subfolder
                $filebird_subfolder_id = $this->filebird_connector->get_folder_by_name($subfolder_name, $parent_id);
                
                if (!$filebird_subfolder_id) {
                    $this->logger->log('Creating new FileBird subfolder: ' . $subfolder_name, 'info');
                    $filebird_subfolder_id = $this->filebird_connector->create_folder($subfolder_name, $parent_id);
                    
                    if (!$filebird_subfolder_id) {
                        $this->logger->log('Failed to create FileBird subfolder: ' . $subfolder_name, 'error');
                        continue;
                    }
                    
                    $this->logger->log('Created FileBird subfolder with ID: ' . $filebird_subfolder_id, 'info');
                } else {
                    $this->logger->log('Found existing FileBird subfolder with ID: ' . $filebird_subfolder_id, 'info');
                }
                
                // Process this subfolder recursively
                $this->process_dropbox_folder($folder_entry['path_display'], $filebird_subfolder_id);
            }
        }
    }

/**
 * Process a Dropbox file and sync it to FileBird with properly configured WordPress media integration.
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
    
    $this->logger->log("Processing Dropbox file: {$filename} for folder ID: {$folder_id}", 'info');
    
    // Check allowed file types
    $settings = get_option('fbds_settings', []);
    $allowed_file_types = isset($settings['file_types']) ? $settings['file_types'] : ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    
    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $check_extension = ($file_extension === 'jpeg') ? 'jpg' : $file_extension;
    
    if (!in_array($check_extension, $allowed_file_types)) {
        $this->logger->log("Skipping file {$filename} - file type not allowed", 'info');
        return;
    }
    
    // Check if file already exists
    $attachment_id = $this->filebird_connector->get_attachment_by_filename($filename);
    
    // Determine conflict resolution
    $should_download = true;
    if ($attachment_id) {
        $this->logger->log("File already exists as attachment ID: {$attachment_id}", 'info');
        $conflict_resolution = isset($settings['conflict_resolution']) ? $settings['conflict_resolution'] : 'newer';
        $attachment_modified = get_post_modified_time('U', true, $attachment_id);
        
        if ($conflict_resolution === 'filebird') {
            $should_download = false;
        } else if ($conflict_resolution === 'newer' && $attachment_modified >= $dropbox_modified) {
            $should_download = false;
        } else if ($conflict_resolution === 'both') {
            // Rename new file to keep both
            $name_parts = pathinfo($filename);
            $new_filename = $name_parts['filename'] . '-dropbox-' . date('Ymd-His', $dropbox_modified);
            if (isset($name_parts['extension'])) {
                $new_filename .= '.' . $name_parts['extension'];
            }
            $filename = $new_filename;
            $attachment_id = null; // Force new file creation
        }
        
        // If not downloading but need to update folder assignment
        if (!$should_download) {
            $current_folder_id = $this->filebird_connector->get_folder_for_attachment($attachment_id);
            if ($current_folder_id != $folder_id) {
                $result = $this->filebird_connector->move_attachment_to_folder($attachment_id, $folder_id);
                $this->logger->log("Moved existing attachment to folder {$folder_id}: " . ($result ? "Success" : "Failed"), 'info');
            }
            return;
        }
    }
    
    if ($should_download) {
        // Download file from Dropbox
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/fbds-temp-' . md5($filename . time());
        
        $downloaded = $this->dropbox_api->download_file($dropbox_path, $temp_file);
        
        if (!$downloaded || !file_exists($temp_file)) {
            $this->logger->log("Download failed for {$dropbox_path}", 'error');
            return;
        }
        
        $this->logger->log("Download successful, size: " . size_format(filesize($temp_file)), 'info');
        
        // Prepare WordPress upload structure
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        // Get proper file type
        $filetype = wp_check_filetype($filename, null);
        $mime_type = $filetype['type'];
        
        if (empty($mime_type)) {
            unlink($temp_file);
            $this->logger->log("Unsupported file type for {$filename}", 'error');
            return;
        }
        
        // Set up a proper filename in WordPress uploads directory
        $uploads = wp_upload_dir();
        $year_month = date('Y/m');
        $wp_filetype = wp_check_filetype($filename, null);
        
        // Create year/month directories if they don't exist
        $uploads_dir = $uploads['basedir'] . '/' . $year_month;
        if (!file_exists($uploads_dir)) {
            wp_mkdir_p($uploads_dir);
        }
        
        // Prepare unique filename
        $unique_filename = wp_unique_filename($uploads_dir, $filename);
        $upload_file = $uploads_dir . '/' . $unique_filename;
        
        // Copy file to uploads directory
        $moved = copy($temp_file, $upload_file);
        
        if (!$moved) {
            unlink($temp_file);
            $this->logger->log("Failed to move file to {$upload_file}", 'error');
            return;
        }
        
        // Check if we're updating existing attachment
        if ($attachment_id) {
            // Update existing attachment
            $this->logger->log("Updating existing attachment ID: {$attachment_id}", 'info');
            
            // Get old attachment info
            $old_file = get_attached_file($attachment_id);
            
            // Delete old file if it exists
            if ($old_file && file_exists($old_file) && $old_file !== $upload_file) {
                unlink($old_file);
            }
            
            // Update attachment data
            $attachment = array(
                'ID' => $attachment_id,
                'post_mime_type' => $mime_type,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', true),
            );
            
            wp_update_post($attachment);
            update_attached_file($attachment_id, $upload_file);
            
            // Generate metadata for new file
            $attach_data = wp_generate_attachment_metadata($attachment_id, $upload_file);
            wp_update_attachment_metadata($attachment_id, $attach_data);
            
        } else {
            // Create new attachment
            $this->logger->log("Creating new attachment for {$filename}", 'info');
            
            // Get file title (remove extension)
            $title = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filename);
            
            // Create post array
            $attachment = array(
                'guid' => $uploads['url'] . '/' . $year_month . '/' . $unique_filename,
                'post_mime_type' => $mime_type,
                'post_title' => $title,
                'post_content' => '',
                'post_status' => 'inherit'
            );
            
            // Insert attachment
            $attachment_id = wp_insert_attachment($attachment, $upload_file);
            
            if (is_wp_error($attachment_id)) {
                unlink($temp_file);
                unlink($upload_file);
                $this->logger->log("Error creating attachment: " . $attachment_id->get_error_message(), 'error');
                return;
            }
            
            // Generate metadata
            $attach_data = wp_generate_attachment_metadata($attachment_id, $upload_file);
            wp_update_attachment_metadata($attachment_id, $attach_data);
        }
        
        // Clean up temp file
        unlink($temp_file);
        
        $this->logger->log("Successfully added/updated attachment ID: {$attachment_id}", 'info');
        
        // Add to FileBird folder
        $success = false;
        $retries = 3;
        for ($i = 0; $i < $retries; $i++) {
            $success = $this->filebird_connector->move_attachment_to_folder($attachment_id, $folder_id);
            if ($success) {
                $this->logger->log("Successfully added file to FileBird folder ID: {$folder_id}", 'info');
                break;
            }
            if ($i < $retries - 1) {
                usleep(200000); // 0.2 seconds pause
            }
        }
        
        if (!$success) {
            $this->logger->log("Failed to add file to FileBird folder after {$retries} attempts", 'error');
        }
    }
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
 * Handle FileBird folder created event with immediate sync.
 *
 * @since    1.0.0
 * @param    int       $folder_id    The folder ID.
 * @param    array     $folder_data  The folder data.
 */
public function on_filebird_folder_created($folder_id, $folder_data) {
    if ($this->is_syncing) {
        return;
    }
    
    $this->logger->log('FileBird folder created: ' . $folder_id . ' - ' . $folder_data['title'], 'info');
    
    try {
        // Get the complete folder object
        $folder = $this->filebird_connector->get_folder($folder_id);
        
        if (!$folder) {
            $this->logger->log('Unable to get folder details for ID: ' . $folder_id, 'error');
            return;
        }
        
        // Determine Dropbox path for this folder
        $dropbox_path = $this->get_dropbox_path_for_folder($folder);
        
        // Create folder in Dropbox immediately
        $this->logger->log('Creating Dropbox folder for new FileBird folder: ' . $dropbox_path, 'info');
        $result = $this->dropbox_api->create_folder($dropbox_path);
        
        if (is_wp_error($result) || isset($result['error_summary'])) {
            $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
            $this->logger->log('Error creating folder in Dropbox: ' . $error_message, 'error');
            
            // Fall back to scheduled sync
            $this->schedule_sync('to_dropbox');
        } else {
            $this->logger->log('Successfully created folder in Dropbox: ' . $dropbox_path, 'info');
        }
    } catch (Exception $e) {
        $this->logger->log('Error in direct folder creation: ' . $e->getMessage(), 'error');
        
        // Fall back to scheduled sync
        $this->schedule_sync('to_dropbox');
    }
}

    /**
     * Handle FileBird folder deleted event with immediate sync.
     *
     * @since    1.0.0
     * @param    int    $folder_id    The folder ID.
     */
    public function on_filebird_folder_deleted($folder_id) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('FileBird folder deleted: ' . $folder_id, 'info');
        
        // We need to schedule this since we can't get the Dropbox path for a deleted folder
        $this->schedule_sync('to_dropbox', 5); // 5 seconds
    }


    /**
     * Handle FileBird folder renamed event with immediate sync.
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
        
        // Schedule a quick sync since renaming requires complex path calculations
        $this->schedule_sync('to_dropbox', 5); // 5 seconds
    }

    /**
     * Handle attachment added event with immediate sync.
     *
     * @since    1.0.0
     * @param    int    $attachment_id    The attachment ID.
     */
    public function on_attachment_added($attachment_id) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('Attachment added: ' . $attachment_id, 'info');
        
        try {
            // Wait a moment for any folder assignment to complete
            sleep(2);
            
            // Get the folder for this attachment
            $folder_id = $this->filebird_connector->get_folder_for_attachment($attachment_id);
            
            if (!$folder_id) {
                $this->logger->log('No folder assigned to attachment ' . $attachment_id . ' yet', 'info');
                // Schedule a delayed sync to catch any pending folder assignments
                $this->schedule_sync('to_dropbox', 10);
                return;
            }
            
            $folder = $this->filebird_connector->get_folder($folder_id);
            if (!$folder) {
                $this->logger->log('Unable to get folder details for ID: ' . $folder_id, 'warning');
                $this->schedule_sync('to_dropbox', 10);
                return;
            }
            
            // Get the Dropbox path for this folder
            $dropbox_path = $this->get_dropbox_path_for_folder($folder);
            
            // Get attachment file
            $file_path = get_attached_file($attachment_id);
            
            if (!$file_path || !file_exists($file_path)) {
                $this->logger->log('File not found for attachment ID ' . $attachment_id, 'warning');
                return;
            }
        
            $filename = basename($file_path);
            $dropbox_file_path = trailingslashit($dropbox_path) . $filename;
            
            // Upload file to Dropbox
            $this->logger->log('Uploading new attachment to Dropbox: ' . $dropbox_file_path, 'info');
            $result = $this->dropbox_api->upload_file($file_path, $dropbox_file_path);
            
            if (is_wp_error($result) || isset($result['error_summary'])) {
                $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
                $this->logger->log('Error uploading new attachment to Dropbox: ' . $error_message, 'error');
                // Fall back to scheduled sync
                $this->schedule_sync('to_dropbox', 10);
            } else {
                $this->logger->log('Successfully uploaded new attachment to Dropbox: ' . $dropbox_file_path, 'info');
            }
        } catch (Exception $e) {
            $this->logger->log('Error in direct attachment upload: ' . $e->getMessage(), 'error');
            // Fall back to scheduled sync
            $this->schedule_sync('to_dropbox', 10);
        }
    }

    /**
     * Handle attachment deleted event with immediate sync.
     *
     * @since    1.0.0
     * @param    int    $attachment_id    The attachment ID.
     */
    public function on_attachment_deleted($attachment_id) {
        if ($this->is_syncing) {
            return;
        }
        
        $this->logger->log('Attachment deleted: ' . $attachment_id, 'info');
        
        // We need to schedule this since we can't get the Dropbox path for a deleted attachment
        $this->schedule_sync('to_dropbox', 5); // 5 seconds
    }

    /**
     * Handle attachment moved event with immediate sync.
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
        
        try {
            // Get file info
            $file_path = get_attached_file($attachment_id);
            
            if (!$file_path || !file_exists($file_path)) {
                $this->logger->log('File not found for moved attachment ID ' . $attachment_id, 'warning');
                return;
            }
            
            $filename = basename($file_path);
            
            // Get source and destination folders in Dropbox
            $source_folder = $this->filebird_connector->get_folder($from_folder);
            $dest_folder = $this->filebird_connector->get_folder($to_folder);
            
            if (!$source_folder || !$dest_folder) {
                $this->logger->log('Unable to get folder details for source or destination', 'warning');
                $this->schedule_sync('to_dropbox', 5);
                return;
            }
            
            $source_dropbox_path = $this->get_dropbox_path_for_folder($source_folder);
            $dest_dropbox_path = $this->get_dropbox_path_for_folder($dest_folder);
            
            $source_dropbox_file = trailingslashit($source_dropbox_path) . $filename;
            $dest_dropbox_file = trailingslashit($dest_dropbox_path) . $filename;
            
            // Move file in Dropbox
            $this->logger->log('Moving file in Dropbox from ' . $source_dropbox_file . ' to ' . $dest_dropbox_file, 'info');
            $result = $this->dropbox_api->move($source_dropbox_file, $dest_dropbox_file);
            
            if (is_wp_error($result) || isset($result['error_summary'])) {
                $error_message = is_wp_error($result) ? $result->get_error_message() : $result['error_summary'];
                $this->logger->log('Error moving file in Dropbox: ' . $error_message, 'error');
                
                // If move failed, try uploading to new location
                $this->logger->log('Attempting to upload file to new location instead', 'info');
                $upload_result = $this->dropbox_api->upload_file($file_path, $dest_dropbox_file);
                
                if (is_wp_error($upload_result) || isset($upload_result['error_summary'])) {
                    $upload_error = is_wp_error($upload_result) ? $upload_result->get_error_message() : $upload_result['error_summary'];
                    $this->logger->log('Error uploading file to new location: ' . $upload_error, 'error');
                    $this->schedule_sync('to_dropbox', 5);
                } else {
                    $this->logger->log('Successfully uploaded file to new location in Dropbox', 'info');
                }
            } else {
                $this->logger->log('Successfully moved file in Dropbox', 'info');
            }
        } catch (Exception $e) {
            $this->logger->log('Error in direct attachment move: ' . $e->getMessage(), 'error');
            $this->schedule_sync('to_dropbox', 5);
        }
    }
}    