<?php
/**
 * Setup wizard template
 *
 * @package FileBirdDropboxSyncPro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get current step
$current_step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$total_steps = 4;

// Get required data
$dropbox_api = new FileBird_Dropbox_API();
$is_connected = $dropbox_api->is_connected();
$filebird_connector = new FileBird_Connector();
$folders = $filebird_connector->get_all_folders();
$has_folders = !empty($folders);
?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php _e('FileBird Dropbox Sync Setup', 'filebird-dropbox-sync-pro'); ?> - <?php bloginfo('name'); ?></title>
    <?php do_action('admin_print_styles'); ?>
    <?php do_action('admin_print_scripts'); ?>
    <?php do_action('admin_head'); ?>
    <style>
        .emergency-exit {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #dc3232;
            color: white;
            padding: 10px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: bold;
            z-index: 9999;
        }
        .emergency-exit:hover {
            background: #a00;
            color: white;
        }
    </style>
</head>
<body class="fbds-wizard-page wp-core-ui">
    <!-- Emergency exit button -->
    <a href="<?php echo admin_url('index.php?fbds_ignore_wizard=1'); ?>" class="emergency-exit">
        <?php _e('Exit Wizard', 'filebird-dropbox-sync-pro'); ?>
    </a>
    
    <div class="fbds-wizard-container">
        <div class="fbds-wizard-header">
            <h1><?php _e('FileBird Dropbox Sync Pro', 'filebird-dropbox-sync-pro'); ?></h1>
            <div class="fbds-wizard-steps">
                <?php for ($i = 1; $i <= $total_steps; $i++): ?>
                <div class="fbds-wizard-step <?php echo $i === $current_step ? 'active' : ($i < $current_step ? 'completed' : ''); ?>">
                    <div class="fbds-step-number"><?php echo $i; ?></div>
                    <div class="fbds-step-label">
                        <?php 
                        switch ($i) {
                            case 1:
                                _e('Welcome', 'filebird-dropbox-sync-pro');
                                break;
                            case 2:
                                _e('Connect Dropbox', 'filebird-dropbox-sync-pro');
                                break;
                            case 3:
                                _e('Folder Mapping', 'filebird-dropbox-sync-pro');
                                break;
                            case 4:
                                _e('Complete', 'filebird-dropbox-sync-pro');
                                break;
                        }
                        ?>
                    </div>
                </div>
                <?php if ($i < $total_steps): ?>
                <div class="fbds-wizard-step-divider"></div>
                <?php endif; ?>
                <?php endfor; ?>
            </div>
        </div>
        
        <div class="fbds-wizard-content">
            <?php if ($current_step === 1): ?>
            <!-- Step 1: Welcome -->
            <div class="fbds-wizard-step-content fbds-step-welcome">
                <h2><?php _e('Welcome to FileBird Dropbox Sync Pro!', 'filebird-dropbox-sync-pro'); ?></h2>
                
                <div class="fbds-wizard-features">
                    <div class="fbds-feature">
                        <div class="fbds-feature-icon">
                            <span class="dashicons dashicons-cloud"></span>
                        </div>
                        <div class="fbds-feature-content">
                            <h3><?php _e('Two-way Synchronization', 'filebird-dropbox-sync-pro'); ?></h3>
                            <p><?php _e('Keep your FileBird folders and Dropbox perfectly in sync. Changes made in either location will be reflected in the other.', 'filebird-dropbox-sync-pro'); ?></p>
                        </div>
                    </div>
                    
                    <div class="fbds-feature">
                        <div class="fbds-feature-icon">
                            <span class="dashicons dashicons-admin-appearance"></span>
                        </div>
                        <div class="fbds-feature-content">
                            <h3><?php _e('ACF Gallery Integration', 'filebird-dropbox-sync-pro'); ?></h3>
                            <p><?php _e('Automatically update ACF gallery fields with images from FileBird folders. Perfect for keeping your website content fresh.', 'filebird-dropbox-sync-pro'); ?></p>
                        </div>
                    </div>
                    
                    <div class="fbds-feature">
                        <div class="fbds-feature-icon">
                            <span class="dashicons dashicons-admin-users"></span>
                        </div>
                        <div class="fbds-feature-content">
                            <h3><?php _e('User-Friendly Interface', 'filebird-dropbox-sync-pro'); ?></h3>
                            <p><?php _e('Our intuitive dashboard and mapping tools make configuration a breeze, even for non-technical users.', 'filebird-dropbox-sync-pro'); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="fbds-wizard-prerequisites">
                    <h3><?php _e('Prerequisites Check', 'filebird-dropbox-sync-pro'); ?></h3>
                    
                    <ul class="fbds-checklist">
                        <li class="<?php echo class_exists('FileBird\\Model\\Folder') ? 'fbds-success' : 'fbds-error'; ?>">
                            <span class="dashicons <?php echo class_exists('FileBird\\Model\\Folder') ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                            <span class="fbds-check-label"><?php _e('FileBird Plugin Active', 'filebird-dropbox-sync-pro'); ?></span>
                        </li>
                        
                        <li class="<?php echo class_exists('ACF') ? 'fbds-success' : 'fbds-error'; ?>">
                            <span class="dashicons <?php echo class_exists('ACF') ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                            <span class="fbds-check-label"><?php _e('Advanced Custom Fields Active', 'filebird-dropbox-sync-pro'); ?></span>
                        </li>
                        
                        <li class="<?php echo $has_folders ? 'fbds-success' : 'fbds-warning'; ?>">
                            <span class="dashicons <?php echo $has_folders ? 'dashicons-yes' : 'dashicons-warning'; ?>"></span>
                            <span class="fbds-check-label">
                                <?php _e('FileBird Folders', 'filebird-dropbox-sync-pro'); ?>
                                <?php if (!$has_folders): ?>
                                <small><?php _e('(No folders found. You can create them later.)', 'filebird-dropbox-sync-pro'); ?></small>
                                <?php endif; ?>
                            </span>
                        </li>
                        
                        <li class="<?php echo function_exists('curl_version') ? 'fbds-success' : 'fbds-error'; ?>">
                            <span class="dashicons <?php echo function_exists('curl_version') ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                            <span class="fbds-check-label"><?php _e('PHP cURL Extension', 'filebird-dropbox-sync-pro'); ?></span>
                        </li>
                    </ul>
                </div>
                
                <div class="fbds-wizard-actions">
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup&step=2'); ?>" class="button button-primary button-hero">
                        <?php _e('Let\'s Get Started', 'filebird-dropbox-sync-pro'); ?>
                    </a>
                    
                    <p class="fbds-skip-link">
                        <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-sync&fbds_ignore_wizard=1'); ?>">
                            <?php _e('Skip wizard and configure manually', 'filebird-dropbox-sync-pro'); ?>
                        </a>
                    </p>
                </div>
            </div>
            
            <?php elseif ($current_step === 2): ?>
            <!-- Step 2: Connect Dropbox -->
            <div class="fbds-wizard-step-content fbds-step-connect">
                <h2><?php _e('Connect Your Dropbox Account', 'filebird-dropbox-sync-pro'); ?></h2>
                
                <?php if ($is_connected): ?>
                <div class="fbds-notice fbds-notice-success">
                    <p>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php _e('Dropbox account successfully connected!', 'filebird-dropbox-sync-pro'); ?>
                    </p>
                </div>
                
                <div class="fbds-connection-details">
                    <h3><?php _e('Connection Details', 'filebird-dropbox-sync-pro'); ?></h3>
                    <div class="fbds-connected-account">
                        <div class="fbds-connection-icon">
                            <span class="dashicons dashicons-cloud"></span>
                        </div>
                        <div class="fbds-connection-info">
                            <span class="fbds-connection-status"><?php _e('Connected', 'filebird-dropbox-sync-pro'); ?></span>
                            <button type="button" class="fbds-disconnect-btn" data-nonce="<?php echo wp_create_nonce('fbds_disconnect_dropbox'); ?>">
                                <?php _e('Disconnect', 'filebird-dropbox-sync-pro'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <p class="fbds-wizard-description">
                    <?php _e('Connect your Dropbox account to enable syncing with FileBird folders. Your Dropbox credentials are never stored on your WordPress site.', 'filebird-dropbox-sync-pro'); ?>
                </p>
                
                <div class="fbds-dropbox-setup">
                    <div class="fbds-setup-section">
                        <h3><?php _e('Dropbox API Settings', 'filebird-dropbox-sync-pro'); ?></h3>
                        <p class="fbds-api-instructions">
                            <?php _e('You\'ll need to create a Dropbox app to get your API credentials. Follow these steps:', 'filebird-dropbox-sync-pro'); ?>
                        </p>
                        
                        <ol class="fbds-instructions-list">
                            <li><?php _e('Go to the <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox App Console</a>', 'filebird-dropbox-sync-pro'); ?></li>
                            <li><?php _e('Click "Create app"', 'filebird-dropbox-sync-pro'); ?></li>
                            <li><?php _e('Choose "Scoped access" API', 'filebird-dropbox-sync-pro'); ?></li>
                            <li><?php _e('Select "Full Dropbox" access type', 'filebird-dropbox-sync-pro'); ?></li>
                            <li><?php _e('Name your app (e.g., "WordPress FileBird Sync")', 'filebird-dropbox-sync-pro'); ?></li>
                            <li><?php _e('In the Configuration tab, add the redirect URI:', 'filebird-dropbox-sync-pro'); ?> <code><?php echo admin_url('admin-ajax.php?action=fbds_dropbox_oauth_callback'); ?></code></li>
                            <li><?php _e('Copy your App Key and App Secret below', 'filebird-dropbox-sync-pro'); ?></li>
                        </ol>
                        
                        <div class="fbds-api-form">
                            <div class="fbds-form-field">
                                <label for="dropbox_app_key"><?php _e('App Key', 'filebird-dropbox-sync-pro'); ?></label>
                                <input type="text" id="dropbox_app_key" name="dropbox_app_key" class="regular-text" placeholder="<?php _e('Your Dropbox App Key', 'filebird-dropbox-sync-pro'); ?>">
                            </div>
                            
                            <div class="fbds-form-field">
                                <label for="dropbox_app_secret"><?php _e('App Secret', 'filebird-dropbox-sync-pro'); ?></label>
                                <input type="password" id="dropbox_app_secret" name="dropbox_app_secret" class="regular-text" placeholder="<?php _e('Your Dropbox App Secret', 'filebird-dropbox-sync-pro'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="fbds-wizard-actions fbds-connect-actions">
                    <button type="button" class="button button-secondary" id="fbds-save-api-settings" data-nonce="<?php echo wp_create_nonce('fbds_save_api_settings'); ?>">
                        <?php _e('Save API Settings', 'filebird-dropbox-sync-pro'); ?>
                    </button>
                    
                    <button type="button" class="button button-primary" id="fbds-connect-dropbox" data-nonce="<?php echo wp_create_nonce('fbds_connect_dropbox'); ?>" disabled>
                        <?php _e('Connect to Dropbox', 'filebird-dropbox-sync-pro'); ?>
                    </button>
                </div>
                <?php endif; ?>
                
                <div class="fbds-wizard-navigation">
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup&step=1'); ?>" class="button">
                        <?php _e('Previous Step', 'filebird-dropbox-sync-pro'); ?>
                    </a>
                    
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup&step=3'); ?>" class="button button-primary">
                        <?php _e('Next Step', 'filebird-dropbox-sync-pro'); ?>
                    </a>
                </div>
            </div>
            
            <?php elseif ($current_step === 3): ?>
            <!-- Step 3: Folder Mapping -->
            <div class="fbds-wizard-step-content fbds-step-mapping">
                <h2><?php _e('Set Up Folder Mappings', 'filebird-dropbox-sync-pro'); ?></h2>
                
                <p class="fbds-wizard-description">
                    <?php _e('Map your FileBird folders to ACF gallery fields. This will automatically update your galleries when files are added to these folders.', 'filebird-dropbox-sync-pro'); ?>
                </p>
                
                <?php if (!$has_folders): ?>
                <div class="fbds-notice fbds-notice-warning">
                    <p>
                        <span class="dashicons dashicons-warning"></span>
                        <?php _e('No FileBird folders found. Please create some folders in the FileBird Media Library before setting up mappings.', 'filebird-dropbox-sync-pro'); ?>
                    </p>
                </div>
                <?php endif; ?>
                
                <div class="fbds-mapping-interface" <?php echo !$has_folders ? 'style="opacity:0.5; pointer-events:none;"' : ''; ?>>
                    <div class="fbds-mapping-controls">
                        <button type="button" class="button fbds-add-mapping-btn" data-nonce="<?php echo wp_create_nonce('fbds_add_mapping'); ?>">
                            <span class="dashicons dashicons-plus"></span> <?php _e('Add Mapping', 'filebird-dropbox-sync-pro'); ?>
                        </button>
                    </div>
                    
                    <div class="fbds-mappings-list">
                        <div class="fbds-mapping-item fbds-mapping-header">
                            <div class="fbds-mapping-folder"><?php _e('FileBird Folder', 'filebird-dropbox-sync-pro'); ?></div>
                            <div class="fbds-mapping-field"><?php _e('ACF Gallery Field', 'filebird-dropbox-sync-pro'); ?></div>
                            <div class="fbds-mapping-post"><?php _e('Post/Page', 'filebird-dropbox-sync-pro'); ?></div>
                            <div class="fbds-mapping-actions"><?php _e('Actions', 'filebird-dropbox-sync-pro'); ?></div>
                        </div>
                        
                        <div class="fbds-no-mappings-message">
                            <p><?php _e('No mappings configured yet. Click "Add Mapping" above to create your first folder-to-field mapping.', 'filebird-dropbox-sync-pro'); ?></p>
                        </div>
                        
                        <!-- Mapping items will be inserted here dynamically -->
                    </div>
                </div>
                
                <div class="fbds-wizard-navigation">
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup&step=2'); ?>" class="button">
                        <?php _e('Previous Step', 'filebird-dropbox-sync-pro'); ?>
                    </a>
                    
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup&step=4'); ?>" class="button button-primary">
                        <?php _e('Next Step', 'filebird-dropbox-sync-pro'); ?>
                    </a>
                </div>
            </div>
            
            <?php elseif ($current_step === 4): ?>
            <!-- Step 4: Complete - This content will be replaced by the separate complete.php template -->
            <?php include_once FBDS_PLUGIN_DIR . 'admin/partials/wizard/complete.php'; ?>
            <?php endif; ?>
        </div>
        
        <div class="fbds-wizard-footer">
            <p>
                <?php _e('FileBird Dropbox Sync Pro', 'filebird-dropbox-sync-pro'); ?> | 
                <?php _e('Version', 'filebird-dropbox-sync-pro'); ?> <?php echo FBDS_VERSION; ?>
            </p>
        </div>
    </div>

    <?php do_action('admin_footer'); ?>
    <?php do_action('admin_print_footer_scripts'); ?>
</body>
</html>