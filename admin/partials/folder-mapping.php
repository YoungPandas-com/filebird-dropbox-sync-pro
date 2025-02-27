<?php
/**
 * Folder Mapping interface
 *
 * @package FileBirdDropboxSyncPro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get FileBird folders
$filebird_connector = new FileBird_Connector();
$folders = $filebird_connector->get_all_folders();

// Get ACF connector
$acf_connector = new ACF_Connector();
$posts_with_gallery = $acf_connector->get_posts_with_gallery_fields();

// Get existing mappings
$mappings = get_option('fbds_folder_field_mappings', []);
?>

<div class="wrap fbds-admin">
    <h1 class="fbds-page-title"><?php _e('Folder Mapping', 'filebird-dropbox-sync-pro'); ?></h1>
    
    <div class="fbds-description">
        <p>
            <?php _e('Map FileBird folders to ACF gallery fields. When files are added to or removed from a FileBird folder, the corresponding ACF gallery field will be automatically updated.', 'filebird-dropbox-sync-pro'); ?>
        </p>
    </div>
    
    <?php if (empty($folders)): ?>
    <div class="fbds-notice fbds-notice-warning">
        <p>
            <span class="dashicons dashicons-warning"></span>
            <?php _e('No FileBird folders found. Please create folders in FileBird before setting up mappings.', 'filebird-dropbox-sync-pro'); ?>
            <a href="<?php echo admin_url('upload.php?page=filebird-settings'); ?>" class="button">
                <?php _e('Go to FileBird', 'filebird-dropbox-sync-pro'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>
    
    <?php if (empty($posts_with_gallery)): ?>
    <div class="fbds-notice fbds-notice-warning">
        <p>
            <span class="dashicons dashicons-warning"></span>
            <?php _e('No ACF gallery fields found. Please create ACF gallery fields before setting up mappings.', 'filebird-dropbox-sync-pro'); ?>
            <a href="<?php echo admin_url('edit.php?post_type=acf-field-group'); ?>" class="button">
                <?php _e('Go to ACF', 'filebird-dropbox-sync-pro'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($folders) && !empty($posts_with_gallery)): ?>
    <div class="fbds-mapping-container">
        <!-- Mapping Interface -->
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><span class="dashicons dashicons-networking"></span> <?php _e('Manage Mappings', 'filebird-dropbox-sync-pro'); ?></h2>
                <button type="button" class="button fbds-add-mapping-btn" data-nonce="<?php echo wp_create_nonce('fbds_add_mapping'); ?>">
                    <span class="dashicons dashicons-plus"></span> <?php _e('Add Mapping', 'filebird-dropbox-sync-pro'); ?>
                </button>
            </div>
            <div class="fbds-card-body">
                <div class="fbds-mappings-list">
                    <div class="fbds-mapping-item fbds-mapping-header">
                        <div class="fbds-mapping-folder"><?php _e('FileBird Folder', 'filebird-dropbox-sync-pro'); ?></div>
                        <div class="fbds-mapping-field"><?php _e('ACF Gallery Field', 'filebird-dropbox-sync-pro'); ?></div>
                        <div class="fbds-mapping-post"><?php _e('Post/Page', 'filebird-dropbox-sync-pro'); ?></div>
                        <div class="fbds-mapping-preview"><?php _e('Preview', 'filebird-dropbox-sync-pro'); ?></div>
                        <div class="fbds-mapping-actions"><?php _e('Actions', 'filebird-dropbox-sync-pro'); ?></div>
                    </div>
                    
                    <?php if (empty($mappings)): ?>
                    <div class="fbds-no-mappings-message">
                        <p><?php _e('No mappings configured yet. Click "Add Mapping" to create your first folder-to-field mapping.', 'filebird-dropbox-sync-pro'); ?></p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($mappings as $index => $mapping): ?>
                            <?php 
                            $folder_id = $mapping['folder_id'];
                            $field_key = $mapping['field_key'];
                            $post_id = $mapping['post_id'];
                            
                            // Get folder info
                            $folder = $filebird_connector->get_folder($folder_id);
                            $folder_name = $folder ? $folder->name : __('Unknown Folder', 'filebird-dropbox-sync-pro');
                            
                            // Get post info
                            $post = get_post($post_id);
                            $post_title = $post ? $post->post_title : __('Unknown Post', 'filebird-dropbox-sync-pro');
                            
                            // Get field info
                            $field_info = '';
                            if (isset($posts_with_gallery[$post_id])) {
                                foreach ($posts_with_gallery[$post_id]['gallery_fields'] as $gallery_field) {
                                    if ($gallery_field['key'] === $field_key) {
                                        $field_info = $gallery_field['label'] ?: $gallery_field['name'];
                                        break;
                                    }
                                }
                            }
                            if (!$field_info) {
                                $field_info = __('Unknown Field', 'filebird-dropbox-sync-pro');
                            }
                            
                            // Get count of attachments
                            $attachments = $filebird_connector->get_attachments_in_folder($folder_id);
                            $attachment_count = count($attachments);
                            ?>
                            <div class="fbds-mapping-item" data-mapping-id="<?php echo $index; ?>">
                                <div class="fbds-mapping-folder">
                                    <span class="dashicons dashicons-category"></span>
                                    <?php echo esc_html($folder_name); ?>
                                </div>
                                <div class="fbds-mapping-field">
                                    <span class="dashicons dashicons-images-alt2"></span>
                                    <?php echo esc_html($field_info); ?>
                                </div>
                                <div class="fbds-mapping-post">
                                    <span class="dashicons dashicons-admin-page"></span>
                                    <?php echo esc_html($post_title); ?>
                                </div>
                                <div class="fbds-mapping-preview">
                                    <span class="fbds-attachment-count">
                                        <span class="dashicons dashicons-admin-media"></span>
                                        <?php echo sprintf(_n('%s image', '%s images', $attachment_count, 'filebird-dropbox-sync-pro'), $attachment_count); ?>
                                    </span>
                                </div>
                                <div class="fbds-mapping-actions">
                                    <button type="button" class="fbds-edit-mapping-btn" data-mapping-id="<?php echo $index; ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                    </button>
                                    <button type="button" class="fbds-delete-mapping-btn" data-mapping-id="<?php echo $index; ?>" data-nonce="<?php echo wp_create_nonce('fbds_delete_mapping'); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Mapping Form Modal -->
        <div class="fbds-modal" id="fbds-mapping-modal">
            <div class="fbds-modal-content">
                <div class="fbds-modal-header">
                    <h3 id="fbds-modal-title"><?php _e('Add New Mapping', 'filebird-dropbox-sync-pro'); ?></h3>
                    <button type="button" class="fbds-modal-close">&times;</button>
                </div>
                <div class="fbds-modal-body">
                    <form id="fbds-mapping-form">
                        <input type="hidden" id="fbds-mapping-id" value="">
                        <input type="hidden" id="fbds-mapping-nonce" value="<?php echo wp_create_nonce('fbds_save_mapping'); ?>">
                        
                        <!-- FileBird Folder Selection -->
                        <div class="fbds-form-field">
                            <label for="fbds-folder-select"><?php _e('FileBird Folder', 'filebird-dropbox-sync-pro'); ?></label>
                            <select id="fbds-folder-select" name="folder_id" required>
                                <option value=""><?php _e('Select a folder...', 'filebird-dropbox-sync-pro'); ?></option>
                                <?php foreach ($folders as $folder): ?>
                                <option value="<?php echo $folder->term_id; ?>">
                                    <?php 
                                    // Calculate folder depth for indentation
                                    $parent_id = $folder->parent;
                                    $depth = 0;
                                    while ($parent_id > 0) {
                                        $depth++;
                                        $parent = $filebird_connector->get_folder($parent_id);
                                        if (!$parent) {
                                            break;
                                        }
                                        $parent_id = $parent->parent;
                                    }
                                    
                                    // Output folder name with indentation
                                    echo str_repeat('&mdash; ', $depth) . esc_html($folder->name);
                                    ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Post Selection -->
                        <div class="fbds-form-field">
                            <label for="fbds-post-select"><?php _e('Post/Page', 'filebird-dropbox-sync-pro'); ?></label>
                            <select id="fbds-post-select" name="post_id" required>
                                <option value=""><?php _e('Select a post...', 'filebird-dropbox-sync-pro'); ?></option>
                                <?php 
                                // Group posts by post type
                                $post_groups = [];
                                foreach ($posts_with_gallery as $post_id => $post_data) {
                                    $post_type = $post_data['post_type'];
                                    if (!isset($post_groups[$post_type])) {
                                        $post_groups[$post_type] = [];
                                    }
                                    $post_groups[$post_type][$post_id] = $post_data;
                                }
                                
                                // Output post options grouped by post type
                                foreach ($post_groups as $post_type => $posts): 
                                    $post_type_obj = get_post_type_object($post_type);
                                    $post_type_label = $post_type_obj ? $post_type_obj->labels->name : $post_type;
                                ?>
                                <optgroup label="<?php echo esc_attr($post_type_label); ?>">
                                    <?php foreach ($posts as $post_id => $post_data): ?>
                                    <option value="<?php echo $post_id; ?>"><?php echo esc_html($post_data['post_title']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Field Selection (populated via JavaScript) -->
                        <div class="fbds-form-field">
                            <label for="fbds-field-select"><?php _e('ACF Gallery Field', 'filebird-dropbox-sync-pro'); ?></label>
                            <select id="fbds-field-select" name="field_key" required disabled>
                                <option value=""><?php _e('Select a post first...', 'filebird-dropbox-sync-pro'); ?></option>
                            </select>
                            <div id="fbds-field-loading" class="fbds-loading-spinner" style="display: none;"></div>
                        </div>
                        
                        <div class="fbds-form-info" id="fbds-mapping-info" style="display: none;">
                            <div class="fbds-info-heading"><?php _e('Mapping Preview', 'filebird-dropbox-sync-pro'); ?></div>
                            <div class="fbds-info-content">
                                <div class="fbds-info-item">
                                    <span class="fbds-info-label"><?php _e('Images in folder:', 'filebird-dropbox-sync-pro'); ?></span>
                                    <span class="fbds-info-value" id="fbds-folder-count">0</span>
                                </div>
                                <div class="fbds-info-item">
                                    <span class="fbds-info-label"><?php _e('Images in gallery:', 'filebird-dropbox-sync-pro'); ?></span>
                                    <span class="fbds-info-value" id="fbds-gallery-count">0</span>
                                </div>
                            </div>
                            <div class="fbds-info-note">
                                <?php _e('Note: Saving this mapping will update the ACF gallery field with the images from the selected folder.', 'filebird-dropbox-sync-pro'); ?>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="fbds-modal-footer">
                    <button type="button" class="button fbds-modal-cancel"><?php _e('Cancel', 'filebird-dropbox-sync-pro'); ?></button>
                    <button type="button" class="button button-primary" id="fbds-save-mapping-btn"><?php _e('Save Mapping', 'filebird-dropbox-sync-pro'); ?></button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Information Section -->
    <div class="fbds-info-section">
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><span class="dashicons dashicons-info"></span> <?php _e('How Folder Mapping Works', 'filebird-dropbox-sync-pro'); ?></h2>
            </div>
            <div class="fbds-card-body">
                <div class="fbds-how-it-works">
                    <div class="fbds-step">
                        <div class="fbds-step-number">1</div>
                        <div class="fbds-step-content">
                            <h3><?php _e('Map Folders to Fields', 'filebird-dropbox-sync-pro'); ?></h3>
                            <p><?php _e('Select a FileBird folder, an ACF gallery field, and the post/page containing that field to create a mapping.', 'filebird-dropbox-sync-pro'); ?></p>
                        </div>
                    </div>
                    
                    <div class="fbds-step">
                        <div class="fbds-step-number">2</div>
                        <div class="fbds-step-content">
                            <h3><?php _e('Automatic Sync', 'filebird-dropbox-sync-pro'); ?></h3>
                            <p><?php _e('When files are added to or removed from a mapped FileBird folder, the corresponding ACF gallery field will be automatically updated.', 'filebird-dropbox-sync-pro'); ?></p>
                        </div>
                    </div>
                    
                    <div class="fbds-step">
                        <div class="fbds-step-number">3</div>
                        <div class="fbds-step-content">
                            <h3><?php _e('Dropbox Integration', 'filebird-dropbox-sync-pro'); ?></h3>
                            <p><?php _e('Upload files to Dropbox folders, and they will appear in your FileBird folders and ACF gallery fields automatically.', 'filebird-dropbox-sync-pro'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- JavaScript for ACF field data -->
<script type="text/javascript">
    // Store ACF field data for JavaScript access
    var fbdsAcfData = <?php echo json_encode($posts_with_gallery); ?>;
    var fbdsFolderData = <?php echo json_encode($filebird_connector->get_all_folders()); ?>;
    var fbdsMappings = <?php echo json_encode($mappings); ?>;
</script>