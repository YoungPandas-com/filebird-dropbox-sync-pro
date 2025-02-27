<?php
/**
 * Welcome step template for the setup wizard
 *
 * @package FileBirdDropboxSyncPro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>

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
        
        <?php
        // Check if FileBird is active
        $filebird_active = class_exists('FileBird\\FileBird');
        
        // Check if ACF is active
        $acf_active = class_exists('ACF');
        
        // Check FileBird folders
        $filebird_connector = new FileBird_Connector();
        $folders = $filebird_connector->get_all_folders();
        $has_folders = !empty($folders);
        
        // Check PHP curl
        $has_curl = function_exists('curl_version');
        ?>
        
        <ul class="fbds-checklist">
            <li class="<?php echo $filebird_active ? 'fbds-success' : 'fbds-error'; ?>">
                <span class="dashicons <?php echo $filebird_active ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                <span class="fbds-check-label"><?php _e('FileBird Plugin Active', 'filebird-dropbox-sync-pro'); ?></span>
            </li>
            
            <li class="<?php echo $acf_active ? 'fbds-success' : 'fbds-error'; ?>">
                <span class="dashicons <?php echo $acf_active ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
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
            
            <li class="<?php echo $has_curl ? 'fbds-success' : 'fbds-error'; ?>">
                <span class="dashicons <?php echo $has_curl ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                <span class="fbds-check-label"><?php _e('PHP cURL Extension', 'filebird-dropbox-sync-pro'); ?></span>
            </li>
        </ul>
    </div>
    
    <div class="fbds-wizard-actions">
        <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup&step=2'); ?>" class="button button-primary button-hero">
            <?php _e('Let\'s Get Started', 'filebird-dropbox-sync-pro'); ?>
        </a>
        
        <p class="fbds-skip-link">
            <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-sync&fbds_skip_wizard=1'); ?>">
                <?php _e('Skip wizard and configure manually', 'filebird-dropbox-sync-pro'); ?>
            </a>
        </p>
    </div>
</div>