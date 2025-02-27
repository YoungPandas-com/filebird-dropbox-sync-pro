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
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        $this->logger = new FileBird_Dropbox_Sync_Logger();
    }

    /**
     * Get all FileBird folders.
     *
     * @since    1.0.0
     * @return   array    The list of folders.
     */
    public function get_all_folders() {
        global $wpdb;
        
        // Check if FileBird tables exist
        $table_name = $wpdb->prefix . 'fbv';
        if (!$this->check_filebird_tables()) {
            $this->logger->log('FileBird tables not found', 'error');
            return [];
        }
        
        $folders = $wpdb->get_results(
            "SELECT t.term_id, t.name, tt.parent 
            FROM {$wpdb->prefix}terms AS t 
            INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id 
            WHERE tt.taxonomy = 'filebird_folder' 
            ORDER BY t.name ASC"
        );
        
        if (empty($folders)) {
            return [];
        }
        
        return $folders;
    }

    /**
     * Get folders by parent ID.
     *
     * @since    1.0.0
     * @param    int       $parent_id    The parent folder ID.
     * @return   array                   The list of child folders.
     */
    public function get_folders_by_parent($parent_id) {
        global $wpdb;
        
        if (!$this->check_filebird_tables()) {
            return [];
        }
        
        $folders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, t.name, tt.parent 
                FROM {$wpdb->prefix}terms AS t 
                INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id 
                WHERE tt.taxonomy = 'filebird_folder' AND tt.parent = %d 
                ORDER BY t.name ASC",
                $parent_id
            )
        );
        
        if (empty($folders)) {
            return [];
        }
        
        return $folders;
    }

    /**
     * Get a specific folder by ID.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID.
     * @return   object|false            The folder object or false if not found.
     */
    public function get_folder($folder_id) {
        global $wpdb;
        
        if (!$this->check_filebird_tables()) {
            return false;
        }
        
        $folder = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT t.term_id, t.name, tt.parent 
                FROM {$wpdb->prefix}terms AS t 
                INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id 
                WHERE tt.taxonomy = 'filebird_folder' AND t.term_id = %d",
                $folder_id
            )
        );
        
        return $folder;
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
        global $wpdb;
        
        if (!$this->check_filebird_tables()) {
            return false;
        }
        
        $folder_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT t.term_id 
                FROM {$wpdb->prefix}terms AS t 
                INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON t.term_id = tt.term_id 
                WHERE tt.taxonomy = 'filebird_folder' AND t.name = %s AND tt.parent = %d",
                $name,
                $parent_id
            )
        );
        
        return $folder_id ? intval($folder_id) : false;
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
        if (!$this->check_filebird_tables()) {
            return false;
        }
        
        // Check if FileBird class exists and use its function
        if (class_exists('FileBird\\Model\\Folder')) {
            try {
                $result = \FileBird\Model\Folder::newOrGet($name, $parent_id);
                
                if (isset($result['id'])) {
                    $this->logger->log('Created FileBird folder: ' . $name . ' (ID: ' . $result['id'] . ')', 'info');
                    return $result['id'];
                }
            } catch (\Exception $e) {
                $this->logger->log('Error creating FileBird folder: ' . $e->getMessage(), 'error');
                return false;
            }
        }
        
        // Fallback method if FileBird class is not available
        $term = wp_insert_term($name, 'filebird_folder', [
            'parent' => $parent_id
        ]);
        
        if (is_wp_error($term)) {
            $this->logger->log('Error creating FileBird folder: ' . $term->get_error_message(), 'error');
            return false;
        }
        
        $this->logger->log('Created FileBird folder: ' . $name . ' (ID: ' . $term['term_id'] . ')', 'info');
        return $term['term_id'];
    }

    /**
     * Get attachments in a specific folder.
     *
     * @since    1.0.0
     * @param    int       $folder_id    The folder ID.
     * @return   array                   The list of attachments.
     */
    public function get_attachments_in_folder($folder_id) {
        global $wpdb;
        
        if (!$this->check_filebird_tables()) {
            return [];
        }
        
        // Check if folder exists
        $folder = $this->get_folder($folder_id);
        if (!$folder) {
            return [];
        }
        
        // Get attachments using term relationships
        $attachments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.* 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id 
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                WHERE tt.term_id = %d 
                AND tt.taxonomy = 'filebird_folder' 
                AND p.post_type = 'attachment'",
                $folder_id
            )
        );
        
        return $attachments;
    }

    /**
     * Get the folder ID for an attachment.
     *
     * @since    1.0.0
     * @param    int       $attachment_id    The attachment ID.
     * @return   int|false                   The folder ID or false if not found.
     */
    public function get_folder_for_attachment($attachment_id) {
        global $wpdb;
        
        if (!$this->check_filebird_tables()) {
            return false;
        }
        
        $folder_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT tt.term_id 
                FROM {$wpdb->term_relationships} tr 
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                WHERE tt.taxonomy = 'filebird_folder' 
                AND tr.object_id = %d",
                $attachment_id
            )
        );
        
        return $folder_id ? intval($folder_id) : false;
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
        if (!$this->check_filebird_tables()) {
            return false;
        }
        
        // Check if FileBird class exists and use its function
        if (class_exists('FileBird\\Model\\Folder')) {
            try {
                \FileBird\Model\Folder::setFolder($attachment_id, $folder_id);
                $this->logger->log('Moved attachment ' . $attachment_id . ' to folder ' . $folder_id, 'info');
                return true;
            } catch (\Exception $e) {
                $this->logger->log('Error moving attachment: ' . $e->getMessage(), 'error');
                return false;
            }
        }
        
        // Fallback method if FileBird class is not available
        $current_folder_id = $this->get_folder_for_attachment($attachment_id);
        
        // Remove from current folder if it exists
        if ($current_folder_id) {
            wp_remove_object_terms($attachment_id, $current_folder_id, 'filebird_folder');
        }
        
        // Add to new folder
        $result = wp_set_object_terms($attachment_id, $folder_id, 'filebird_folder');
        
        if (is_wp_error($result)) {
            $this->logger->log('Error moving attachment: ' . $result->get_error_message(), 'error');
            return false;
        }
        
        $this->logger->log('Moved attachment ' . $attachment_id . ' to folder ' . $folder_id, 'info');
        return true;
    }

    /**
     * Get an attachment by filename.
     *
     * @since    1.0.0
     * @param    string    $filename    The filename to search for.
     * @return   int|false              The attachment ID or false if not found.
     */
    public function get_attachment_by_filename($filename) {
        global $wpdb;
        
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'attachment' 
                AND guid LIKE %s",
                '%/' . $wpdb->esc_like($filename)
            )
        );
        
        return $attachment_id ? intval($attachment_id) : false;
    }

    /**
     * Check if FileBird tables exist and verify compatible version.
     *
     * @since    1.0.0
     * @return   bool    Whether the FileBird tables exist and version is compatible.
     */
    private function check_filebird_tables() {
        global $wpdb;
        
        // Check if FileBird is active
        if (!class_exists('FileBird\\FileBird')) {
            $this->logger->log('FileBird plugin is not active', 'error');
            return false;
        }
        
        // Check FileBird version (require 4.0+)
        if (defined('NJFB_VERSION')) {
            $version = NJFB_VERSION;
            if (version_compare($version, '4.0', '<')) {
                $this->logger->log('FileBird version is too old. Version 4.0 or higher is required.', 'error');
                return false;
            }
        }
        
        // Check if the taxonomy exists
        $taxonomy_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'filebird_folder' LIMIT 1"
        );
        
        if ($taxonomy_exists <= 0) {
            $this->logger->log('FileBird folder taxonomy does not exist', 'error');
            return false;
        }
        
        return true;
    }
}