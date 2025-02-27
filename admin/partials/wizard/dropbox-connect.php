<?php
/**
 * Dropbox connection step template for the setup wizard
 *
 * @package FileBirdDropboxSyncPro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Check if connected to Dropbox
$dropbox_api = new FileBird_Dropbox_API();
$is_connected = $dropbox_api->is_connected();
?>

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
                    <input type="text" id="dropbox_app_key" name="dropbox_app_key" class="regular-text" placeholder="<?php _e('Your Dropbox App Key', 'filebird-dropbox-sync-pro'); ?>" value="<?php echo esc_attr(get_option('fbds_dropbox_app_key', '')); ?>">
                </div>
                
                <div class="fbds-form-field">
                    <label for="dropbox_app_secret"><?php _e('App Secret', 'filebird-dropbox-sync-pro'); ?></label>
                    <input type="password" id="dropbox_app_secret" name="dropbox_app_secret" class="regular-text" placeholder="<?php _e('Your Dropbox App Secret', 'filebird-dropbox-sync-pro'); ?>" value="<?php echo esc_attr(get_option('fbds_dropbox_app_secret', '')); ?>">
                </div>
            </div>
        </div>
    </div>
    
    <div class="fbds-wizard-actions fbds-connect-actions">
        <button type="button" class="button button-secondary" id="fbds-save-api-settings" data-nonce="<?php echo wp_create_nonce('fbds_save_api_settings'); ?>">
            <?php _e('Save API Settings', 'filebird-dropbox-sync-pro'); ?>
        </button>
        
        <button type="button" class="button button-primary" id="fbds-connect-dropbox" data-nonce="<?php echo wp_create_nonce('fbds_connect_dropbox'); ?>" <?php echo get_option('fbds_dropbox_app_key', '') && get_option('fbds_dropbox_app_secret', '') ? '' : 'disabled'; ?>>
            <?php _e('Connect to Dropbox', 'filebird-dropbox-sync-pro'); ?>
        </button>
    </div>
    
    <?php endif; ?>
    
    <div class="fbds-wizard-navigation">
        <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup&step=1'); ?>" class="button">
            <?php _e('Previous', 'filebird-dropbox-sync-pro'); ?>
        </a>
        
        <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup&step=3'); ?>" class="button button-primary">
            <?php _e('Next: Folder Mapping', 'filebird-dropbox-sync-pro'); ?>
        </a>
    </div>
</div>