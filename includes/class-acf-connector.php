<?php
/**
 * The ACF integration class.
 *
 * @since      1.0.0
 * @package    FileBirdDropboxSyncPro
 */

class ACF_Connector {

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
     * Get all ACF gallery fields.
     *
     * @since    1.0.0
     * @return   array    The list of gallery fields.
     */
    public function get_all_gallery_fields() {
        if (!function_exists('acf_get_field_groups')) {
            $this->logger->log('ACF functions not available', 'error');
            return [];
        }
        
        $gallery_fields = [];
        
        // Get all field groups
        $field_groups = acf_get_field_groups();
        
        foreach ($field_groups as $field_group) {
            // Get fields for this group
            $fields = acf_get_fields($field_group);
            
            if (!$fields) {
                continue;
            }
            
            // Loop through fields to find gallery fields
            foreach ($fields as $field) {
                if ($field['type'] === 'gallery') {
                    $gallery_fields[] = $field;
                }
                
                // Check for sub-fields in repeaters, flexible content, etc.
                if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                    $this->get_sub_gallery_fields($field, $gallery_fields);
                }
                
                // Check for layouts in flexible content
                if (isset($field['layouts']) && is_array($field['layouts'])) {
                    foreach ($field['layouts'] as $layout) {
                        if (isset($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                            $this->get_sub_gallery_fields($layout, $gallery_fields);
                        }
                    }
                }
            }
        }
        
        return $gallery_fields;
    }
    
    /**
     * Helper function to get gallery fields from sub-fields.
     *
     * @since    1.0.0
     * @param    array    $parent_field     The parent field.
     * @param    array    &$gallery_fields  The array to populate with gallery fields.
     */
    private function get_sub_gallery_fields($parent_field, &$gallery_fields) {
        foreach ($parent_field['sub_fields'] as $sub_field) {
            if ($sub_field['type'] === 'gallery') {
                $sub_field['parent'] = $parent_field['key'];
                $gallery_fields[] = $sub_field;
            }
            
            // Recursive check for nested sub-fields
            if (isset($sub_field['sub_fields']) && is_array($sub_field['sub_fields'])) {
                $this->get_sub_gallery_fields($sub_field, $gallery_fields);
            }
        }
    }

    /**
     * Get gallery field by key.
     *
     * @since    1.0.0
     * @param    string    $field_key    The field key.
     * @return   array|false             The field data or false if not found.
     */
    public function get_gallery_field($field_key) {
        if (!function_exists('acf_get_field')) {
            return false;
        }
        
        $field = acf_get_field($field_key);
        
        if (!$field || $field['type'] !== 'gallery') {
            return false;
        }
        
        return $field;
    }

    /**
     * Get all posts using a specific gallery field.
     *
     * @since    1.0.0
     * @param    string    $field_key    The field key.
     * @return   array                   The list of posts.
     */
    public function get_posts_using_field($field_key) {
        global $wpdb;
        
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_type 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE pm.meta_key = %s",
                $field_key
            )
        );
        
        if (empty($posts)) {
            return [];
        }
        
        return $posts;
    }

    /**
     * Get gallery field value for a post.
     *
     * @since    1.0.0
     * @param    int       $post_id     The post ID.
     * @param    string    $field_key   The field key.
     * @return   array                  The gallery value (array of attachment IDs).
     */
    public function get_gallery_field_value($post_id, $field_key) {
        if (!function_exists('get_field')) {
            return [];
        }
        
        $value = get_field($field_key, $post_id);
        
        if (!$value || !is_array($value)) {
            return [];
        }
        
        return $value;
    }

    /**
     * Update gallery field value for a post.
     *
     * @since    1.0.0
     * @param    int       $post_id         The post ID.
     * @param    string    $field_key       The field key.
     * @param    array     $attachment_ids  The array of attachment IDs.
     * @return   bool                       Whether the update was successful.
     */
    public function update_gallery_field($post_id, $field_key, $attachment_ids) {
        if (!function_exists('update_field')) {
            $this->logger->log('ACF update_field function not available', 'error');
            return false;
        }
        
        // Check if post exists
        $post = get_post($post_id);
        if (!$post) {
            $this->logger->log("Post ID {$post_id} does not exist", 'error');
            return false;
        }
        
        // Verify field exists for this post
        if (!$this->field_exists($post_id, $field_key)) {
            $this->logger->log("Field {$field_key} does not exist for post {$post_id}", 'error');
            return false;
        }
        
        if (!is_array($attachment_ids)) {
            $attachment_ids = [];
        }
        
        // Handle array of attachment objects
        if (isset($attachment_ids[0]) && is_object($attachment_ids[0])) {
            $ids = [];
            foreach ($attachment_ids as $attachment) {
                if (isset($attachment->ID)) {
                    $ids[] = $attachment->ID;
                }
            }
            $attachment_ids = $ids;
        }
        
        // Verify all attachment IDs are valid
        $valid_ids = [];
        foreach ($attachment_ids as $id) {
            if (wp_attachment_is_image($id)) {
                $valid_ids[] = $id;
            } else {
                $this->logger->log("Attachment ID {$id} is not a valid image", 'warning');
            }
        }
        
        $result = update_field($field_key, $valid_ids, $post_id);
        
        if ($result === false) {
            $this->logger->log("Failed to update ACF gallery field {$field_key} for post {$post_id}", 'error');
            return false;
        }
        
        $this->logger->log("Updated ACF gallery field {$field_key} for post {$post_id} with " . count($valid_ids) . " images", 'info');
        return true;
    }
    
    /**
     * Check if a field exists for a post.
     *
     * @since    1.0.0
     * @param    int       $post_id     The post ID.
     * @param    string    $field_key   The field key.
     * @return   bool                   Whether the field exists.
     */
    private function field_exists($post_id, $field_key) {
        // First check if the field exists in ACF
        if (function_exists('acf_get_field')) {
            $field = acf_get_field($field_key);
            if (!$field) {
                return false;
            }
        }
        
        // Then check if the field is assigned to this post
        if (function_exists('get_field_object')) {
            $field_object = get_field_object($field_key, $post_id);
            return !empty($field_object);
        }
        
        // If ACF functions aren't available, check the post meta
        $meta_key = "_{$field_key}";
        $post_meta = get_post_meta($post_id, $meta_key, true);
        
        return !empty($post_meta);
    }

    /**
     * Get all ACF fields for post selection.
     *
     * @since    1.0.0
     * @return   array    The list of posts with ACF fields.
     */
    public function get_posts_with_acf_fields() {
        global $wpdb;
        
        // Get all post types with ACF fields
        $post_types_with_acf = $wpdb->get_col(
            "SELECT DISTINCT p.post_type 
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE pm.meta_key LIKE 'field_%' OR pm.meta_key LIKE '_field_%'"
        );
        
        if (empty($post_types_with_acf)) {
            return [];
        }
        
        $posts_with_fields = [];
        
        foreach ($post_types_with_acf as $post_type) {
            // Skip certain post types
            if (in_array($post_type, ['revision', 'nav_menu_item', 'acf-field', 'acf-field-group'])) {
                continue;
            }
            
            // Get post type object
            $post_type_obj = get_post_type_object($post_type);
            if (!$post_type_obj) {
                continue;
            }
            
            // Get posts of this type
            $posts = get_posts([
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'publish',
            ]);
            
            if (empty($posts)) {
                continue;
            }
            
            // Add post type group
            $posts_with_fields[$post_type] = [
                'label' => $post_type_obj->labels->name,
                'posts' => [],
            ];
            
            // Add posts
            foreach ($posts as $post) {
                $posts_with_fields[$post_type]['posts'][] = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                ];
            }
        }
        
        return $posts_with_fields;
    }

    /**
     * Get ACF gallery fields for a specific post.
     *
     * @since    1.0.0
     * @param    int       $post_id    The post ID.
     * @return   array                 The list of gallery fields.
     */
    public function get_gallery_fields_for_post($post_id) {
        if (!function_exists('get_field_objects')) {
            return [];
        }
        
        $fields = get_field_objects($post_id);
        
        if (!$fields) {
            return [];
        }
        
        $gallery_fields = [];
        
        foreach ($fields as $field_key => $field) {
            if ($field['type'] === 'gallery') {
                $gallery_fields[$field_key] = $field;
            }
        }
        
        return $gallery_fields;
    }

    /**
     * Get posts with gallery fields.
     *
     * @since    1.0.0
     * @return   array    Associative array of post IDs to gallery field data.
     */
    public function get_posts_with_gallery_fields() {
        global $wpdb;
        
        $result = [];
        
        // Check if ACF functions are available
        if (!function_exists('acf_get_field_groups')) {
            return $result;
        }
        
        // Get all field groups
        $field_groups = acf_get_field_groups();
        
        foreach ($field_groups as $field_group) {
            // Get fields for this group
            $fields = acf_get_fields($field_group);
            
            if (!$fields) {
                continue;
            }
            
            // Find all gallery fields
            $gallery_fields = [];
            foreach ($fields as $field) {
                if ($field['type'] === 'gallery') {
                    $gallery_fields[] = $field;
                }
            }
            
            if (empty($gallery_fields)) {
                continue;
            }
            
            // Get location rules to determine which posts use these fields
            if (empty($field_group['location'])) {
                continue;
            }
            
            // Process each gallery field
            foreach ($gallery_fields as $gallery_field) {
                // Handle different location rule types
                foreach ($field_group['location'] as $location_group) {
                    foreach ($location_group as $location_rule) {
                        // Handle post type rule
                        if ($location_rule['param'] === 'post_type' && $location_rule['operator'] === '==') {
                            $posts = get_posts([
                                'post_type' => $location_rule['value'],
                                'posts_per_page' => -1,
                                'post_status' => 'publish',
                            ]);
                            
                            foreach ($posts as $post) {
                                if (!isset($result[$post->ID])) {
                                    $result[$post->ID] = [
                                        'post_title' => $post->post_title,
                                        'post_type' => $post->post_type,
                                        'gallery_fields' => [],
                                    ];
                                }
                                
                                $result[$post->ID]['gallery_fields'][] = [
                                    'key' => $gallery_field['key'],
                                    'name' => $gallery_field['name'],
                                    'label' => $gallery_field['label'],
                                ];
                            }
                        }
                        
                        // Handle specific post rule
                        if ($location_rule['param'] === 'post' && $location_rule['operator'] === '==') {
                            $post_id = intval($location_rule['value']);
                            $post = get_post($post_id);
                            
                            if ($post) {
                                if (!isset($result[$post->ID])) {
                                    $result[$post->ID] = [
                                        'post_title' => $post->post_title,
                                        'post_type' => $post->post_type,
                                        'gallery_fields' => [],
                                    ];
                                }
                                
                                $result[$post->ID]['gallery_fields'][] = [
                                    'key' => $gallery_field['key'],
                                    'name' => $gallery_field['name'],
                                    'label' => $gallery_field['label'],
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        return $result;
    }
}