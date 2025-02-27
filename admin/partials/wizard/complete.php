<?php
/**
 * Completion step template for the setup wizard
 *
 * @package FileBirdDropboxSyncPro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Mark wizard as completed when this page is shown
update_option('fbds_wizard_completed', true);
?>

<div class="fbds-wizard-step-content fbds-step-complete">
    <div class="fbds-complete-icon">
        <span class="dashicons dashicons-yes-alt"></span>
    </div>
    
    <h2><?php _e('Setup Complete!', 'filebird-dropbox-sync-pro'); ?></h2>
    
    <p class="fbds-wizard-description">
        <?php _e('Congratulations! You have successfully set up FileBird Dropbox Sync Pro. Your FileBird folders, Dropbox, and ACF fields are now ready to sync.', 'filebird-dropbox-sync-pro'); ?>
    </p>
    
    <div class="fbds-next-steps">
        <h3><?php _e('Next Steps', 'filebird-dropbox-sync-pro'); ?></h3>
        
        <ul class="fbds-next-steps-list">
            <li>
                <span class="fbds-step-icon dashicons dashicons-update"></span>
                <span class="fbds-step-text"><?php _e('Run your first sync from the dashboard', 'filebird-dropbox-sync-pro'); ?></span>
            </li>
            <li>
                <span class="fbds-step-icon dashicons dashicons-admin-generic"></span>
                <span class="fbds-step-text"><?php _e('Configure additional settings like sync frequency', 'filebird-dropbox-sync-pro'); ?></span>
            </li>
            <li>
                <span class="fbds-step-icon dashicons dashicons-welcome-learn-more"></span>
                <span class="fbds-step-text"><?php _e('Review the documentation for advanced features', 'filebird-dropbox-sync-pro'); ?></span>
            </li>
        </ul>
    </div>
    
    <div class="fbds-wizard-summary">
        <h3><?php _e('Setup Summary', 'filebird-dropbox-sync-pro'); ?></h3>
        
        <?php 
        // Check Dropbox connection
        $dropbox_api = new FileBird_Dropbox_API();
        $is_connected = $dropbox_api->is_connected();
        
        // Get mappings count
        $mappings = get_option('fbds_folder_field_mappings', []);
        $mappings_count = count($mappings);
        ?>
        
        <div class="fbds-summary-item">
            <span class="fbds-summary-label"><?php _e('Dropbox Connection:', 'filebird-dropbox-sync-pro'); ?></span>
            <span class="fbds-summary-value <?php echo $is_connected ? 'fbds-success' : 'fbds-error'; ?>">
                <?php echo $is_connected ? __('Connected', 'filebird-dropbox-sync-pro') : __('Not Connected', 'filebird-dropbox-sync-pro'); ?>
            </span>
        </div>
        
        <div class="fbds-summary-item">
            <span class="fbds-summary-label"><?php _e('Folder Mappings:', 'filebird-dropbox-sync-pro'); ?></span>
            <span class="fbds-summary-value">
                <?php echo $mappings_count; ?> <?php echo _n('mapping', 'mappings', $mappings_count, 'filebird-dropbox-sync-pro'); ?>
            </span>
        </div>
        
        <div class="fbds-summary-item">
            <span class="fbds-summary-label"><?php _e('Auto Sync:', 'filebird-dropbox-sync-pro'); ?></span>
            <span class="fbds-summary-value">
                <?php 
                $settings = get_option('fbds_settings', []);
                $auto_sync = isset($settings['auto_sync']) ? $settings['auto_sync'] : true;
                echo $auto_sync ? __('Enabled', 'filebird-dropbox-sync-pro') : __('Disabled', 'filebird-dropbox-sync-pro'); 
                ?>
            </span>
        </div>
    </div>
    
    <div class="fbds-support-info">
        <h3><?php _e('Need Help?', 'filebird-dropbox-sync-pro'); ?></h3>
        <p>
            <?php _e('If you encounter any issues or have questions, please visit our support center or contact our support team.', 'filebird-dropbox-sync-pro'); ?>
        </p>
    </div>
    
    <div class="fbds-wizard-actions">
        <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-sync'); ?>" class="button button-primary button-hero">
            <?php _e('Go to Dashboard', 'filebird-dropbox-sync-pro'); ?>
        </a>
    </div>
</div>