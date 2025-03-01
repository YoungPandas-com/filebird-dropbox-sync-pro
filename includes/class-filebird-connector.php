<?php
/**
 * The FileBird integration class with direct DB integration.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class FileBird_Connector {

    /**
     * Logger instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      FileBird_Dropbox_Sync_Logger    $logger    Logger instance.
     */
    private $logger;

    /**
     * Debug mode
     * 
     * @since    1.0.0
     * @access   private
     * @var      bool    $debug_mode    Whether debug mode is enabled
     */
    private $debug_mode = false;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->logger = new FileBird_Dropbox_Sync_Logger();
        
        // Option to enable debug mode
        $this->debug_mode = defined('FBDS_DEBUG') && FBDS_DEBUG;
    }

    /**
     * Log debug information if debug mode is enabled
     *
     * @since    1.0.0
     * @param    string    $message    Debug message
     */
    private function debug($message) {
        if ($this->debug_mode) {
            $this->logger->log('[DEBUG-FB] ' . $message, 'info');
        }
    }

    /**
     * Get all FileBird folders.
     *
     * @since    1.0.0
     * @return   array    The list of folders.
     */
    public function get_all_folders() {
        try {
            global $wpdb;
            
            // Direct query to get fbv folders - most reliable method
            if ($this->check_fbv_table_exists()) {
                $this->debug('Querying fbv table directly');
                
                $fbv_table = $wpdb->prefix . 'fbv';
                
                $folders = $wpdb->get_results(
                    "SELECT id as term_id, name, parent 
                    FROM {$fbv_table} 
                    ORDER BY name ASC"
                );
                
                if (!empty($folders)) {
                    $this->debug('Found ' . count($folders) . ' folders');
                    return $folders;
                }
            }
            
            // Try using FileBird's native API as fallback
            if (class_exists('\\FileBird\\Model\\Folder')) {
                $this->debug('Using FileBird native API');
                
                try {
                    $api_folders = \FileBird\Model\Folder::allFolders();
                    
                    if (!empty($api_folders)) {
                        // Convert to standard format
                        $folders = array();
                        foreach ($api_folders as $folder) {
                            $obj = new stdClass();
                            $obj->term_id = intval($folder->id);
                            $obj->name = $folder->name;
                            $obj->parent = intval($folder->parent);
                            $folders[] = $obj;
                        }
                        $this->debug('Found ' . count($folders) . ' folders via API');
                        return $folders;
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error using FileBird API: ' . $e->getMessage(), 'error');
                }
            }
            
            return array();
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_all_folders: ' . $e->getMessage(), 'error');
            return array();
        }
    }

    /**
     * Get a specific folder by ID.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID.
     * @return   object|false            The folder object or false if not found.
     */
    public function get_folder($folder_id) {
        try {
            global $wpdb;
            
            if (empty($folder_id)) {
                return false;
            }
            
            // Direct query to fbv table - most reliable method
            if ($this->check_fbv_table_exists()) {
                $fbv_table = $wpdb->prefix . 'fbv';
                
                $folder = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id as term_id, name, parent 
                        FROM {$fbv_table} 
                        WHERE id = %d",
                        $folder_id
                    )
                );
                
                if ($folder) {
                    return $folder;
                }
            }
            
            // Try using FileBird's native API as fallback
            if (class_exists('\\FileBird\\Model\\Folder') && method_exists('\\FileBird\\Model\\Folder', 'findById')) {
                try {
                    $folder = \FileBird\Model\Folder::findById($folder_id);
                    
                    if ($folder) {
                        $obj = new stdClass();
                        $obj->term_id = intval($folder_id);
                        $obj->name = $folder->name;
                        $obj->parent = intval($folder->parent);
                        return $obj;
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error using FileBird API to get folder: ' . $e->getMessage(), 'error');
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_folder: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get a folder by name and parent ID using the same logic as FileBird.
     *
     * @since    1.0.0
     * @param    string    $name         The folder name.
     * @param    int       $parent_id    The parent folder ID.
     * @return   int|false               The folder ID or false if not found.
     */
    public function get_folder_by_name($name, $parent_id) {
        try {
            global $wpdb;
            
            if (empty($name)) {
                return false;
            }
            
            // Sanitize the name exactly as FileBird does
            $name = sanitize_text_field(wp_kses_post($name));
            $parent_id = intval($parent_id);
            
            $this->debug("Looking for folder: '$name' with parent: $parent_id");
            
            // Direct query to fbv table - most reliable method
            if ($this->check_fbv_table_exists()) {
                $fbv_table = $wpdb->prefix . 'fbv';
                
                $folder_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id 
                        FROM {$fbv_table} 
                        WHERE name = %s AND parent = %d",
                        $name,
                        $parent_id
                    )
                );
                
                if ($folder_id) {
                    $this->debug("Found folder ID: $folder_id");
                    return (int)$folder_id;
                } else {
                    $this->debug("No folder found with name: '$name' in parent: $parent_id");
                }
            }
            
            // Try using FileBird's API as fallback
            if (class_exists('\\FileBird\\Model\\Folder')) {
                try {
                    $this->debug("Trying FileBird API to find folder");
                    $folder = \FileBird\Model\Folder::detail($name, $parent_id);
                    
                    if ($folder && isset($folder->id)) {
                        $this->debug("Found folder via API: " . $folder->id);
                        return (int)$folder->id;
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error using FileBird API to find folder: ' . $e->getMessage(), 'error');
                }
            }
            
            $this->debug("Folder not found by any method");
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_folder_by_name: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Create a new folder using FileBird's native method when possible.
     *
     * @since    1.0.0
     * @param    string    $name         The folder name.
     * @param    int       $parent_id    The parent folder ID.
     * @return   int|false               The new folder ID or false on failure.
     */
    public function create_folder($name, $parent_id = 0) {
        try {
            if (empty($name)) {
                $this->debug("Cannot create folder with empty name");
                return false;
            }
            
            $parent_id = intval($parent_id);
            
            $this->debug("Creating folder: '$name' with parent: $parent_id");
            
            // Method 1: Try native FileBird API (most reliable)
            if (class_exists('\\FileBird\\Model\\Folder') && method_exists('\\FileBird\\Model\\Folder', 'newFolder')) {
                try {
                    $this->debug("Using FileBird native newFolder API");
                    $result = \FileBird\Model\Folder::newFolder($name, $parent_id);
                    
                    if ($result && isset($result['id'])) {
                        $this->debug("Created folder via API with ID: " . $result['id']);
                        $this->logger->log("Created FileBird folder: $name (ID: " . $result['id'] . ")", 'info');
                        return (int)$result['id'];
                    } else {
                        $this->debug("API returned no folder ID");
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error using FileBird API to create folder: ' . $e->getMessage(), 'error');
                }
            }
            
            // Method 2: Simulate FileBird's folder creation with direct DB access
            if ($this->check_fbv_table_exists()) {
                global $wpdb;
                $fbv_table = $wpdb->prefix . 'fbv';
                
                // Sanitize name exactly as FileBird does
                $name = sanitize_text_field(wp_kses_post($name));
                
                // Get the max ord exactly as FileBird does
                $ord = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT MAX(ord) FROM {$fbv_table} WHERE parent = %d AND created_by = %d", 
                        $parent_id, 
                        apply_filters('fbv_folder_created_by', 0)
                    )
                );
                
                // Insert folder record
                $inserted = $wpdb->insert(
                    $fbv_table,
                    array(
                        'name' => $name,
                        'parent' => $parent_id,
                        'type' => 0, // 0 for folder
                        'created_by' => apply_filters('fbv_folder_created_by', 0),
                        'ord' => is_null($ord) ? 0 : (intval($ord) + 1)
                    ),
                    array('%s', '%d', '%d', '%d', '%d')
                );
                
                if ($inserted) {
                    $folder_id = $wpdb->insert_id;
                    
                    // Trigger the same action FileBird does
                    $folder_data = array(
                        'title' => $name,
                        'id' => $folder_id,
                        'key' => $folder_id,
                        'type' => 0,
                        'parent' => $parent_id,
                        'children' => array(),
                        'data-count' => 0,
                        'data-id' => $folder_id
                    );
                    
                    // Trigger action to maintain compatibility
                    if (function_exists('do_action')) {
                        do_action('fbv_after_folder_created', $folder_id, $folder_data);
                    }
                    
                    $this->debug("Created folder via direct DB with ID: $folder_id");
                    $this->logger->log("Created FileBird folder: $name (ID: $folder_id)", 'info');
                    return $folder_id;
                } else {
                    $this->debug("DB insert failed: " . $wpdb->last_error);
                }
            }
            
            $this->logger->log("Failed to create FileBird folder: $name", 'error');
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in create_folder: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get attachments in a specific folder.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID.
     * @return   array                   The list of attachments.
     */
    public function get_attachments_in_folder($folder_id) {
        try {
            global $wpdb;
            
            if (empty($folder_id) || !is_numeric($folder_id)) {
                return array();
            }
            
            $folder_id = intval($folder_id);
            $this->debug("Getting attachments in folder ID: $folder_id");
            
            // Method 1: Direct query to fbv_attachment_folder table (most reliable)
            if ($this->check_fbv_attachment_table_exists()) {
                $fbv_attachment_table = $wpdb->prefix . 'fbv_attachment_folder';
                
                $attachments = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT p.* 
                        FROM {$wpdb->posts} p 
                        INNER JOIN {$fbv_attachment_table} af ON p.ID = af.attachment_id 
                        WHERE af.folder_id = %d 
                        AND p.post_type = 'attachment'",
                        $folder_id
                    )
                );
                
                if (!empty($attachments)) {
                    $this->debug("Found " . count($attachments) . " attachments");
                    return $attachments;
                }
            }
            
            // Method 2: Using FileBird's native API as fallback
            if (class_exists('\\FileBird\\Classes\\Helpers') && 
                method_exists('\\FileBird\\Classes\\Helpers', 'getAttachmentIdsByFolderId')) {
                try {
                    $this->debug("Using FileBird API to get attachments");
                    $attachment_ids = \FileBird\Classes\Helpers::getAttachmentIdsByFolderId($folder_id);
                    
                    if (!empty($attachment_ids)) {
                        $attachments = array();
                        foreach ($attachment_ids as $id) {
                            $attachment = get_post($id);
                            if ($attachment) {
                                $attachments[] = $attachment;
                            }
                        }
                        $this->debug("Found " . count($attachments) . " attachments via API");
                        return $attachments;
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error using FileBird API for attachments: ' . $e->getMessage(), 'error');
                }
            }
            
            $this->debug("No attachments found in folder");
            return array();
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_attachments_in_folder: ' . $e->getMessage(), 'error');
            return array();
        }
    }

    /**
     * Get the folder ID for an attachment.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @return   int|false                   The folder ID or false if not found.
     */
    public function get_folder_for_attachment($attachment_id) {
        try {
            global $wpdb;
            
            if (empty($attachment_id)) {
                return false;
            }
            
            $attachment_id = intval($attachment_id);
            $this->debug("Getting folder for attachment ID: $attachment_id");
            
            // Method 1: Direct query to fbv_attachment_folder table (most reliable)
            if ($this->check_fbv_attachment_table_exists()) {
                $fbv_attachment_table = $wpdb->prefix . 'fbv_attachment_folder';
                
                $folder_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT folder_id 
                        FROM {$fbv_attachment_table} 
                        WHERE attachment_id = %d",
                        $attachment_id
                    )
                );
                
                if ($folder_id !== null) {
                    $this->debug("Found folder ID: $folder_id");
                    return (int)$folder_id;
                }
            }
            
            // Method 2: Using FileBird's native API as fallback
            if (class_exists('\\FileBird\\Model\\Folder') && 
                method_exists('\\FileBird\\Model\\Folder', 'getFolderFromPostId')) {
                try {
                    $this->debug("Using FileBird API to get folder");
                    $folder = \FileBird\Model\Folder::getFolderFromPostId($attachment_id);
                    if (!empty($folder) && isset($folder[0]->folder_id)) {
                        $this->debug("Found folder via API: " . $folder[0]->folder_id);
                        return (int)$folder[0]->folder_id;
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error using FileBird API to get folder: ' . $e->getMessage(), 'error');
                }
            }
            
            $this->debug("No folder found for attachment");
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_folder_for_attachment: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Move an attachment to a folder using FileBird's native method when possible.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @param    int       $folder_id        The destination folder ID.
     * @return   bool                        Whether the move was successful.
     */
    public function move_attachment_to_folder($attachment_id, $folder_id) {
        try {
            if (empty($attachment_id) || !is_numeric($folder_id)) {
                $this->debug("Invalid parameters: attachment_id=$attachment_id, folder_id=$folder_id");
                return false;
            }
            
            $attachment_id = intval($attachment_id);
            $folder_id = intval($folder_id);
            
            // Verify attachment exists
            $attachment = get_post($attachment_id);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                $this->debug("Attachment not found: $attachment_id");
                return false;
            }
            
            // Skip verification for root folder (0)
            if ($folder_id > 0) {
                // Verify folder exists
                $folder_exists = $this->get_folder($folder_id);
                if (!$folder_exists) {
                    $this->debug("Folder not found: $folder_id");
                    return false;
                }
            }
            
            $this->debug("Moving attachment $attachment_id to folder $folder_id");
            
            // Get current folder for action hook
            $current_folder_id = $this->get_folder_for_attachment($attachment_id);
            
            // Method 1: Try FileBird's native API (most reliable)
            if (class_exists('\\FileBird\\Model\\Folder') && 
                method_exists('\\FileBird\\Model\\Folder', 'setFoldersForPosts')) {
                try {
                    $this->debug("Using FileBird's native setFoldersForPosts method");
                    \FileBird\Model\Folder::setFoldersForPosts($attachment_id, $folder_id);
                    
                    // Double-check if it worked
                    $new_folder_id = $this->get_folder_for_attachment($attachment_id);
                    if ((int)$new_folder_id === (int)$folder_id) {
                        $this->debug("Successfully moved attachment to folder $folder_id using API");
                        if ($current_folder_id !== false && $current_folder_id != $folder_id) {
                            do_action('filebird_attachment_moved', $attachment_id, $current_folder_id, $folder_id);
                        }
                        return true;
                    } else {
                        $this->debug("API call didn't update folder relationship");
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error using FileBird API: ' . $e->getMessage(), 'error');
                }
            }
            
            // Method 2: Directly update the database just like FileBird does
            if ($this->check_fbv_attachment_table_exists()) {
                global $wpdb;
                $fbv_attachment_table = $wpdb->prefix . 'fbv_attachment_folder';
                
                // Get user mode setting to handle folder ownership correctly
                $user_has_own_folder = false;
                if (class_exists('\\FileBird\\Model\\SettingModel')) {
                    try {
                        $settingModel = \FileBird\Model\SettingModel::getInstance();
                        $user_has_own_folder = $settingModel->get('user_mode') === '1';
                    } catch (\Exception $e) {
                        $this->debug("Error getting user_mode setting: " . $e->getMessage());
                    }
                }
                
                $current_user_id = get_current_user_id();
                
                // Trigger before action
                if (function_exists('do_action')) {
                    do_action('fbv_before_setting_folder', $attachment_id, $folder_id);
                }
                
                // Delete existing records - use the exact same query FileBird uses
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->prefix}fbv_attachment_folder 
                        WHERE `attachment_id` = %d 
                        AND `folder_id` IN (
                            SELECT `id` FROM {$wpdb->prefix}fbv 
                            WHERE `created_by` = %d
                        )",
                        $attachment_id, 
                        $user_has_own_folder ? $current_user_id : 0
                    )
                );
                
                // Insert new record if folder_id > 0
                if ($folder_id > 0) {
                    $result = $wpdb->insert(
                        $fbv_attachment_table,
                        array(
                            'attachment_id' => $attachment_id,
                            'folder_id' => $folder_id
                        ),
                        array('%d', '%d')
                    );
                    
                    if ($result === false) {
                        $this->debug("DB insert failed: " . $wpdb->last_error);
                        return false;
                    }
                }
                
                // Clean post cache like FileBird does
                clean_post_cache($attachment_id);
                
                // Trigger after action hook
                if (function_exists('do_action')) {
                    do_action('fbv_after_set_folder', $attachment_id, $folder_id);
                    
                    // Also trigger filebird_attachment_moved action for our plugin to detect
                    if ($current_folder_id !== false && $current_folder_id != $folder_id) {
                        do_action('filebird_attachment_moved', $attachment_id, $current_folder_id, $folder_id);
                    }
                }
                
                $this->debug("Successfully moved attachment to folder using direct DB method");
                return true;
            }
            
            $this->debug("Failed to move attachment to folder");
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in move_attachment_to_folder: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get an attachment by filename.
     *
     * @since    1.0.0
     * @param    string    $filename    The filename to search for.
     * @return   int|false              The attachment ID or false if not found.
     */
    public function get_attachment_by_filename($filename) {
        try {
            global $wpdb;
            
            if (empty($filename)) {
                return false;
            }
            
            $this->debug("Looking for attachment with filename: $filename");
            
            // 1. Try by searching the _wp_attached_file meta for the exact filename
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_wp_attached_file' 
                    AND meta_value LIKE %s",
                    '%/' . $wpdb->esc_like($filename)
                )
            );
            
            if ($attachment_id) {
                $this->debug("Found by _wp_attached_file meta: $attachment_id");
                return (int)$attachment_id;
            }
            
            // 2. Try by post title (filename without extension)
            $file_name_no_ext = pathinfo($filename, PATHINFO_FILENAME);
            
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} 
                    WHERE post_type = 'attachment' 
                    AND post_title = %s",
                    $file_name_no_ext
                )
            );
            
            if ($attachment_id) {
                $this->debug("Found by post_title: $attachment_id");
                return (int)$attachment_id;
            }
            
            // 3. Try by guid (permalink)
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} 
                    WHERE post_type = 'attachment' 
                    AND guid LIKE %s",
                    '%/' . $wpdb->esc_like($filename)
                )
            );
            
            if ($attachment_id) {
                $this->debug("Found by guid: $attachment_id");
                return (int)$attachment_id;
            }
            
            $this->debug("No attachment found with filename: $filename");
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_attachment_by_filename: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Check if the FileBird fbv table exists
     *
     * @since    1.0.0
     * @return   bool    Whether the table exists
     */
    private function check_fbv_table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fbv';
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        
        return ($result === $table_name);
    }

    /**
     * Check if the FileBird attachment relation table exists
     *
     * @since    1.0.0
     * @return   bool    Whether the table exists
     */
    private function check_fbv_attachment_table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'fbv_attachment_folder';
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        
        return ($result === $table_name);
    }

    /**
     * Dump the FileBird database tables to help diagnose issues.
     * This is a debugging method, use with caution.
     *
     * @since    1.0.0
     * @return   array    The database tables content.
     */
    public function dump_filebird_tables() {
        if (!$this->debug_mode) {
            return array('error' => 'Debug mode not enabled');
        }
        
        try {
            global $wpdb;
            $result = array();
            
            // Check and dump the fbv table
            if ($this->check_fbv_table_exists()) {
                $fbv_table = $wpdb->prefix . 'fbv';
                $result['fbv'] = $wpdb->get_results("SELECT * FROM {$fbv_table} ORDER BY id", ARRAY_A);
                $result['fbv_count'] = count($result['fbv']);
            } else {
                $result['fbv'] = 'Table does not exist';
            }
            
            // Check and dump the fbv_attachment_folder table
            if ($this->check_fbv_attachment_table_exists()) {
                $fbv_attachment_table = $wpdb->prefix . 'fbv_attachment_folder';
                $result['fbv_attachment_folder'] = $wpdb->get_results(
                    "SELECT * FROM {$fbv_attachment_table} LIMIT 100", 
                    ARRAY_A
                );
                $result['fbv_attachment_folder_count'] = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$fbv_attachment_table}"
                );
            } else {
                $result['fbv_attachment_folder'] = 'Table does not exist';
            }
            
            return $result;
            
        } catch (\Exception $e) {
            return array('error' => $e->getMessage());
        }
    }
}