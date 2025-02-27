<?php
/**
 * The FileBird integration class.
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
            $this->logger->log('[DEBUG] ' . $message, 'info');
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
            $folders = array();
            
            $this->debug('Trying to get FileBird folders');
            
            // Check if FileBird is properly installed and active
            if (!$this->is_filebird_active()) {
                $this->logger->log('FileBird plugin not detected', 'error');
                return array();
            }

            // Try getting folders via direct database query first (most reliable method for all versions)
            $taxonomy_name = $this->get_filebird_taxonomy_name();
            
            if (!empty($taxonomy_name)) {
                $this->debug('Using taxonomy_name: ' . $taxonomy_name);
                
                $folders = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT t.term_id, t.name, tt.parent 
                        FROM {$wpdb->terms} AS t 
                        INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id 
                        WHERE tt.taxonomy = %s 
                        ORDER BY t.name ASC",
                        $taxonomy_name
                    )
                );
            }
            
            // If no folders found via taxonomy, try fbv table
            if (empty($folders) && $this->check_fbv_table_exists()) {
                $this->debug('Using fbv table');
                
                $fbv_table = $wpdb->prefix . 'fbv';
                
                $folders = $wpdb->get_results(
                    "SELECT id as term_id, name, parent 
                    FROM {$fbv_table} 
                    ORDER BY name ASC"
                );
            }
            
            // If still no folders, try using FileBird's native API as a last resort
            if (empty($folders) && class_exists('\\FileBird\\Model\\Folder')) {
                $this->debug('Using FileBird API');
                
                try {
                    $api_folders = \FileBird\Model\Folder::allFolders();
                    
                    if (!empty($api_folders)) {
                        // Convert to standard format
                        foreach ($api_folders as $folder) {
                            $obj = new stdClass();
                            $obj->term_id = $folder['id'];
                            $obj->name = $folder['name'];
                            $obj->parent = $folder['parent'];
                            $folders[] = $obj;
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error using FileBird API: ' . $e->getMessage(), 'error');
                }
            }
            
            // For debugging, log the number of folders found
            if (!empty($folders)) {
                $this->debug('Found ' . count($folders) . ' folders');
            } else {
                $this->debug('No folders found');
            }
            
            return is_array($folders) ? $folders : array();
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_all_folders: ' . $e->getMessage(), 'error');
            return array();
        }
    }

    /**
     * Get folders by parent ID.
     *
     * @since    1.0.0
     * @param    int       $parent_id    The parent folder ID.
     * @return   array                   The list of child folders.
     */
    public function get_folders_by_parent($parent_id) {
        try {
            global $wpdb;
            $folders = array();
            
            // Check FileBird is active
            if (!$this->is_filebird_active()) {
                return array();
            }
            
            // Direct database query (most reliable)
            $taxonomy_name = $this->get_filebird_taxonomy_name();
            
            if (!empty($taxonomy_name)) {
                $folders = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT t.term_id, t.name, tt.parent 
                        FROM {$wpdb->terms} AS t 
                        INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id 
                        WHERE tt.taxonomy = %s AND tt.parent = %d 
                        ORDER BY t.name ASC",
                        $taxonomy_name,
                        $parent_id
                    )
                );
            }
            
            // If no folders found, try fbv table
            if (empty($folders) && $this->check_fbv_table_exists()) {
                $fbv_table = $wpdb->prefix . 'fbv';
                
                $folders = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id as term_id, name, parent 
                        FROM {$fbv_table} 
                        WHERE parent = %d 
                        ORDER BY name ASC",
                        $parent_id
                    )
                );
            }
            
            return is_array($folders) ? $folders : array();
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_folders_by_parent: ' . $e->getMessage(), 'error');
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
            
            // Check FileBird is active
            if (!$this->is_filebird_active()) {
                return false;
            }
            
            // Try direct database query first
            $taxonomy_name = $this->get_filebird_taxonomy_name();
            
            if (!empty($taxonomy_name)) {
                $folder = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT t.term_id, t.name, tt.parent 
                        FROM {$wpdb->terms} AS t 
                        INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id 
                        WHERE tt.taxonomy = %s AND t.term_id = %d",
                        $taxonomy_name,
                        $folder_id
                    )
                );
                
                if ($folder) {
                    return $folder;
                }
            }
            
            // If not found, try fbv table
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
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_folder: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get a folder by name and parent ID.
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
            
            // Check FileBird is active
            if (!$this->is_filebird_active()) {
                return false;
            }
            
            // Direct database query
            $taxonomy_name = $this->get_filebird_taxonomy_name();
            
            if (!empty($taxonomy_name)) {
                $folder_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT t.term_id 
                        FROM {$wpdb->terms} AS t 
                        INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id 
                        WHERE tt.taxonomy = %s AND t.name = %s AND tt.parent = %d",
                        $taxonomy_name,
                        $name,
                        $parent_id
                    )
                );
                
                if ($folder_id) {
                    return (int)$folder_id;
                }
            }
            
            // Try fbv table
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
                    return (int)$folder_id;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_folder_by_name: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Create a new folder.
     *
     * @since    1.0.0
     * @param    string    $name         The folder name.
     * @param    int       $parent_id    The parent folder ID.
     * @return   int|false               The new folder ID or false on failure.
     */
    public function create_folder($name, $parent_id = 0) {
        try {
            if (empty($name)) {
                return false;
            }
            
            // Check if FileBird is active
            if (!$this->is_filebird_active()) {
                return false;
            }
            
            // Check if FileBird class exists and use its function
            if (class_exists('\\FileBird\\Model\\Folder')) {
                try {
                    $result = \FileBird\Model\Folder::newOrGet($name, $parent_id);
                    
                    if (isset($result['id'])) {
                        $this->logger->log('Created FileBird folder: ' . $name . ' (ID: ' . $result['id'] . ')', 'info');
                        return $result['id'];
                    } elseif (isset($result[0]['id'])) {
                        // Some versions might return different format
                        $this->logger->log('Created/Retrieved FileBird folder: ' . $name . ' (ID: ' . $result[0]['id'] . ')', 'info');
                        return $result[0]['id'];
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error creating FileBird folder via API: ' . $e->getMessage(), 'error');
                }
            }
            
            // Fallback to traditional WP terms method
            $taxonomy_name = $this->get_filebird_taxonomy_name();
            
            if (!empty($taxonomy_name)) {
                $term = wp_insert_term($name, $taxonomy_name, [
                    'parent' => $parent_id
                ]);
                
                if (!is_wp_error($term)) {
                    $this->logger->log('Created FileBird folder via WP terms: ' . $name . ' (ID: ' . $term['term_id'] . ')', 'info');
                    return $term['term_id'];
                } else {
                    $this->logger->log('Error creating FileBird folder via WP terms: ' . $term->get_error_message(), 'error');
                }
            }
            
            // Try direct DB insertion as last resort
            if ($this->check_fbv_table_exists()) {
                global $wpdb;
                $fbv_table = $wpdb->prefix . 'fbv';
                
                // Check if the folder already exists
                $existing = $this->get_folder_by_name($name, $parent_id);
                if ($existing) {
                    return $existing;
                }
                
                // Insert new folder
                $wpdb->insert(
                    $fbv_table,
                    [
                        'name' => $name,
                        'parent' => $parent_id,
                        'created_by' => get_current_user_id()
                    ],
                    ['%s', '%d', '%d']
                );
                
                $folder_id = $wpdb->insert_id;
                
                if ($folder_id) {
                    $this->logger->log('Created FileBird folder via direct DB: ' . $name . ' (ID: ' . $folder_id . ')', 'info');
                    return $folder_id;
                }
            }
            
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
            
            if (empty($folder_id)) {
                return array();
            }
            
            // Check FileBird is active
            if (!$this->is_filebird_active()) {
                return array();
            }
            
            // Check if folder exists
            $folder = $this->get_folder($folder_id);
            if (!$folder) {
                return array();
            }
            
            // Try different methods to get attachments
            
            // Method 1: Using term relationships (most compatible)
            $taxonomy_name = $this->get_filebird_taxonomy_name();
            
            if (!empty($taxonomy_name)) {
                $attachments = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT p.* 
                        FROM {$wpdb->posts} p 
                        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id 
                        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                        WHERE tt.term_id = %d 
                        AND tt.taxonomy = %s 
                        AND p.post_type = 'attachment'",
                        $folder_id,
                        $taxonomy_name
                    )
                );
                
                if (!empty($attachments)) {
                    return $attachments;
                }
            }
            
            // Method 2: Using fbv_attachment table
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
                    return $attachments;
                }
            }
            
            // Method 3: Check for _fbv meta
            $attachments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.* 
                    FROM {$wpdb->posts} p 
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                    WHERE pm.meta_key = '_fbv' 
                    AND pm.meta_value = %d 
                    AND p.post_type = 'attachment'",
                    $folder_id
                )
            );
            
            if (!empty($attachments)) {
                return $attachments;
            }
            
            // Method 4: Check for _fb_folder_id meta
            $attachments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.* 
                    FROM {$wpdb->posts} p 
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                    WHERE pm.meta_key = '_fb_folder_id' 
                    AND pm.meta_value = %d 
                    AND p.post_type = 'attachment'",
                    $folder_id
                )
            );
            
            return !empty($attachments) ? $attachments : array();
            
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
            
            // Check FileBird is active
            if (!$this->is_filebird_active()) {
                return false;
            }
            
            // Method 1: Check attachment meta
            $folder_id = get_post_meta($attachment_id, '_fbv', true);
            if (!empty($folder_id)) {
                return (int)$folder_id;
            }
            
            // Method 2: Check alternate meta key
            $folder_id = get_post_meta($attachment_id, '_fb_folder_id', true);
            if (!empty($folder_id)) {
                return (int)$folder_id;
            }
            
            // Method 3: Check fbv_attachment table
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
                
                if (!empty($folder_id)) {
                    return (int)$folder_id;
                }
            }
            
            // Method 4: Check term relationships
            $taxonomy_name = $this->get_filebird_taxonomy_name();
            
            if (!empty($taxonomy_name)) {
                $folder_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT tt.term_id 
                        FROM {$wpdb->term_relationships} tr 
                        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                        WHERE tt.taxonomy = %s 
                        AND tr.object_id = %d",
                        $taxonomy_name,
                        $attachment_id
                    )
                );
                
                if (!empty($folder_id)) {
                    return (int)$folder_id;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_folder_for_attachment: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Move an attachment to a folder.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @param    int       $folder_id        The destination folder ID.
     * @return   bool                        Whether the move was successful.
     */
    public function move_attachment_to_folder($attachment_id, $folder_id) {
        try {
            global $wpdb;
            
            if (empty($attachment_id)) {
                return false;
            }
            
            // Check FileBird is active
            if (!$this->is_filebird_active()) {
                return false;
            }
            
            // Try FileBird API first
            if (class_exists('\\FileBird\\Model\\Folder') && method_exists('\\FileBird\\Model\\Folder', 'setFolder')) {
                try {
                    $result = \FileBird\Model\Folder::setFolder($attachment_id, $folder_id);
                    
                    if ($result !== false) {
                        $this->logger->log('Moved attachment ' . $attachment_id . ' to folder ' . $folder_id . ' via API', 'info');
                        return true;
                    }
                } catch (\Exception $e) {
                    $this->logger->log('Error moving attachment via FileBird API: ' . $e->getMessage(), 'error');
                }
            }
            
            // Try updating meta first
            update_post_meta($attachment_id, '_fbv', $folder_id);
            update_post_meta($attachment_id, '_fb_folder_id', $folder_id);
            
            // Try updating fbv_attachment table
            if ($this->check_fbv_attachment_table_exists()) {
                $fbv_attachment_table = $wpdb->prefix . 'fbv_attachment_folder';
                
                // Check if record exists
                $exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) 
                        FROM {$fbv_attachment_table} 
                        WHERE attachment_id = %d",
                        $attachment_id
                    )
                );
                
                if ($exists) {
                    // Update existing record
                    $wpdb->update(
                        $fbv_attachment_table,
                        ['folder_id' => $folder_id],
                        ['attachment_id' => $attachment_id],
                        ['%d'],
                        ['%d']
                    );
                } else {
                    // Insert new record
                    $wpdb->insert(
                        $fbv_attachment_table,
                        [
                            'attachment_id' => $attachment_id,
                            'folder_id' => $folder_id
                        ],
                        ['%d', '%d']
                    );
                }
            }
            
            // Try term relationships
            $taxonomy_name = $this->get_filebird_taxonomy_name();
            
            if (!empty($taxonomy_name)) {
                // Get current folder first
                $current_folder_id = $this->get_folder_for_attachment($attachment_id);
                
                // Remove from current folder if it exists
                if ($current_folder_id) {
                    wp_remove_object_terms($attachment_id, $current_folder_id, $taxonomy_name);
                }
                
                // Add to new folder
                $result = wp_set_object_terms($attachment_id, $folder_id, $taxonomy_name);
                
                if (!is_wp_error($result)) {
                    $this->logger->log('Moved attachment ' . $attachment_id . ' to folder ' . $folder_id . ' via terms', 'info');
                    return true;
                }
            }
            
            // If we got this far, check if the attachment has the new folder ID in meta
            $updated_folder_id = get_post_meta($attachment_id, '_fbv', true);
            if ($updated_folder_id == $folder_id) {
                return true;
            }
            
            $updated_folder_id = get_post_meta($attachment_id, '_fb_folder_id', true);
            if ($updated_folder_id == $folder_id) {
                return true;
            }
            
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
            
            // Try by guid
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} 
                    WHERE post_type = 'attachment' 
                    AND guid LIKE %s",
                    '%/' . $wpdb->esc_like($filename)
                )
            );
            
            if ($attachment_id) {
                return (int)$attachment_id;
            }
            
            // Try by post title (filename without extension)
            $file_name_no_ext = pathinfo($filename, PATHINFO_FILENAME);
            
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} 
                    WHERE post_type = 'attachment' 
                    AND post_title = %s",
                    $file_name_no_ext
                )
            );
            
            return $attachment_id ? (int)$attachment_id : false;
            
        } catch (\Exception $e) {
            $this->logger->log('Error in get_attachment_by_filename: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Check if FileBird is active and working.
     *
     * @since    1.0.0
     * @return   bool    Whether FileBird is active.
     */
    private function is_filebird_active() {
        // Check for FileBird class
        $filebird_class_exists = class_exists('\\FileBird\\FileBird') || 
                                 class_exists('FileBird\\FileBird') || 
                                 class_exists('FileBird');
        
        // Check for FileBird constants
        $filebird_constants = defined('NJFB_VERSION') || defined('FILEBIRD_VERSION');
        
        // Check for FileBird tables
        $filebird_tables = $this->check_fbv_table_exists() || $this->check_fbv_attachment_table_exists();
        
        // Check for FileBird taxonomy
        $filebird_taxonomy = !empty($this->get_filebird_taxonomy_name());
        
        $is_active = $filebird_class_exists || $filebird_constants || $filebird_tables || $filebird_taxonomy;
        
        if ($this->debug_mode && !$is_active) {
            $this->debug('FileBird not detected: ' . 
                        'Class exists: ' . ($filebird_class_exists ? 'Yes' : 'No') . ', ' .
                        'Constants: ' . ($filebird_constants ? 'Yes' : 'No') . ', ' .
                        'Tables: ' . ($filebird_tables ? 'Yes' : 'No') . ', ' .
                        'Taxonomy: ' . ($filebird_taxonomy ? 'Yes' : 'No'));
        }
        
        return $is_active;
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
        
        // Simple check to see if the table name exists in the database tables
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
        
        // Simple check to see if the table name exists in the database tables
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        
        return ($result === $table_name);
    }

    /**
     * Detect the FileBird taxonomy name
     *
     * @since    1.0.0
     * @return   string|false    The taxonomy name or false if not found
     */
    private function get_filebird_taxonomy_name() {
        global $wpdb;
        
        // Check for common FileBird taxonomy names
        $possible_taxonomies = [
            'filebird_folder',
            'nt_wmc_folder',
            'media_folder',
            'folder',
            'fbv'
        ];
        
        foreach ($possible_taxonomies as $taxonomy) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1",
                    $taxonomy
                )
            );
            
            if ((int)$exists > 0) {
                return $taxonomy;
            }
        }
        
        return false;
    }
}