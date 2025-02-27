<?php
/**
 * Settings page template
 *
 * @package FileBirdDropboxSyncPro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

// Get Dropbox API instance
$dropbox_api = new FileBird_Dropbox_API();
$is_connected = $dropbox_api->is_connected();

// Get saved settings
$settings = get_option('fbds_settings', [
    'sync_frequency' => 'hourly',
    'conflict_resolution' => 'newer',
    'file_types' => ['jpg', 'jpeg', 'png', 'gif'],
    'auto_sync' => true,
    'notification_email' => get_option('admin_email'),
    'enable_email_notifications' => false,
]);

// Get logger instance
$logger = new FileBird_Dropbox_Sync_Logger();
$recent_logs = $logger->get_recent_logs(50);
?>

<div class="wrap fbds-admin">
    <h1 class="fbds-page-title"><?php _e('FileBird Dropbox Sync Settings', 'filebird-dropbox-sync-pro'); ?></h1>
    
    <div class="fbds-settings-tabs">
        <a href="?page=filebird-dropbox-settings&tab=general" class="fbds-tab <?php echo $current_tab === 'general' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-admin-generic"></span>
            <?php _e('General', 'filebird-dropbox-sync-pro'); ?>
        </a>
        <a href="?page=filebird-dropbox-settings&tab=dropbox" class="fbds-tab <?php echo $current_tab === 'dropbox' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-cloud"></span>
            <?php _e('Dropbox', 'filebird-dropbox-sync-pro'); ?>
        </a>
        <a href="?page=filebird-dropbox-settings&tab=sync" class="fbds-tab <?php echo $current_tab === 'sync' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-update"></span>
            <?php _e('Synchronization', 'filebird-dropbox-sync-pro'); ?>
        </a>
        <a href="?page=filebird-dropbox-settings&tab=logs" class="fbds-tab <?php echo $current_tab === 'logs' ? 'active' : ''; ?>">
            <span class="dashicons dashicons-list-view"></span>
            <?php _e('Logs', 'filebird-dropbox-sync-pro'); ?>
        </a>
    </div>
    
    <div class="fbds-settings-content">
        <?php if ($current_tab === 'general'): ?>
        <!-- General Settings -->
        <form method="post" action="options.php" class="fbds-settings-form">
            <?php settings_fields('fbds_general_settings'); ?>
            
            <div class="fbds-card">
                <div class="fbds-card-header">
                    <h2><?php _e('General Settings', 'filebird-dropbox-sync-pro'); ?></h2>
                </div>
                <div class="fbds-card-body">
                    <div class="fbds-settings-section">
                        <div class="fbds-form-field">
                            <label for="fbds-auto-sync">
                                <input type="checkbox" id="fbds-auto-sync" name="fbds_settings[auto_sync]" value="1" <?php checked($settings['auto_sync']); ?>>
                                <?php _e('Enable automatic synchronization', 'filebird-dropbox-sync-pro'); ?>
                            </label>
                            <p class="fbds-field-description">
                                <?php _e('Automatically sync files between FileBird, Dropbox, and ACF based on your schedule settings.', 'filebird-dropbox-sync-pro'); ?>
                            </p>
                        </div>
                        
                        <div class="fbds-form-field">
                            <label for="fbds-notification-email"><?php _e('Notification Email', 'filebird-dropbox-sync-pro'); ?></label>
                            <input type="email" id="fbds-notification-email" name="fbds_settings[notification_email]" value="<?php echo esc_attr($settings['notification_email']); ?>" class="regular-text">
                        </div>
                        
                        <div class="fbds-form-field">
                            <label for="fbds-enable-email-notifications">
                                <input type="checkbox" id="fbds-enable-email-notifications" name="fbds_settings[enable_email_notifications]" value="1" <?php checked($settings['enable_email_notifications']); ?>>
                                <?php _e('Enable email notifications', 'filebird-dropbox-sync-pro'); ?>
                            </label>
                            <p class="fbds-field-description">
                                <?php _e('Receive email notifications about sync status and errors.', 'filebird-dropbox-sync-pro'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="fbds-card-footer">
                    <button type="submit" class="button button-primary">
                        <?php _e('Save Settings', 'filebird-dropbox-sync-pro'); ?>
                    </button>
                </div>
            </div>
        </form>
        
        <?php elseif ($current_tab === 'dropbox'): ?>
        <!-- Dropbox Settings -->
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><?php _e('Dropbox Connection', 'filebird-dropbox-sync-pro'); ?></h2>
            </div>
            <div class="fbds-card-body">
                <?php if ($is_connected): ?>
                <div class="fbds-connection-status fbds-connected">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <span class="fbds-status-text"><?php _e('Connected to Dropbox', 'filebird-dropbox-sync-pro'); ?></span>
                    
                    <div class="fbds-connection-actions">
                        <button type="button" class="button fbds-disconnect-btn" data-nonce="<?php echo wp_create_nonce('fbds_disconnect_dropbox'); ?>">
                            <?php _e('Disconnect', 'filebird-dropbox-sync-pro'); ?>
                        </button>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="fbds-connection-status fbds-disconnected">
                    <span class="dashicons dashicons-no"></span>
                    <span class="fbds-status-text"><?php _e('Not connected to Dropbox', 'filebird-dropbox-sync-pro'); ?></span>
                </div>
                
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
                            
                            <div class="fbds-form-actions">
                                <button type="button" class="button button-secondary" id="fbds-save-api-settings" data-nonce="<?php echo wp_create_nonce('fbds_save_api_settings'); ?>">
                                    <?php _e('Save API Settings', 'filebird-dropbox-sync-pro'); ?>
                                </button>
                                
                                <button type="button" class="button button-primary" id="fbds-connect-dropbox" data-nonce="<?php echo wp_create_nonce('fbds_connect_dropbox'); ?>" <?php echo get_option('fbds_dropbox_app_key', '') && get_option('fbds_dropbox_app_secret', '') ? '' : 'disabled'; ?>>
                                    <?php _e('Connect to Dropbox', 'filebird-dropbox-sync-pro'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><?php _e('Dropbox Webhook', 'filebird-dropbox-sync-pro'); ?></h2>
            </div>
            <div class="fbds-card-body">
                <div class="fbds-webhook-info">
                    <p>
                        <?php _e('To enable real-time synchronization when files change in Dropbox, add the following webhook URL to your Dropbox app settings:', 'filebird-dropbox-sync-pro'); ?>
                    </p>
                    
                    <div class="fbds-webhook-url-container">
                        <code class="fbds-webhook-url"><?php echo site_url('wp-json/filebird-dropbox-sync/v1/webhook'); ?></code>
                        <button type="button" class="button fbds-copy-webhook-url">
                            <span class="dashicons dashicons-clipboard"></span>
                        </button>
                    </div>
                    
                    <ol class="fbds-webhook-instructions">
                        <li><?php _e('Go to the <a href="https://www.dropbox.com/developers/apps" target="_blank">Dropbox App Console</a> and select your app', 'filebird-dropbox-sync-pro'); ?></li>
                        <li><?php _e('Go to the "Webhooks" tab', 'filebird-dropbox-sync-pro'); ?></li>
                        <li><?php _e('Add the webhook URL above', 'filebird-dropbox-sync-pro'); ?></li>
                        <li><?php _e('Click "Add"', 'filebird-dropbox-sync-pro'); ?></li>
                    </ol>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_tab === 'sync'): ?>
        <!-- Sync Settings -->
        <form method="post" action="options.php" class="fbds-settings-form">
            <?php settings_fields('fbds_sync_settings'); ?>
            
            <div class="fbds-card">
                <div class="fbds-card-header">
                    <h2><?php _e('Synchronization Settings', 'filebird-dropbox-sync-pro'); ?></h2>
                </div>
                <div class="fbds-card-body">
                    <div class="fbds-settings-section">
                        <div class="fbds-form-field">
                            <label for="fbds-sync-frequency"><?php _e('Sync Frequency', 'filebird-dropbox-sync-pro'); ?></label>
                            <select id="fbds-sync-frequency" name="fbds_settings[sync_frequency]">
                                <option value="hourly" <?php selected($settings['sync_frequency'], 'hourly'); ?>><?php _e('Hourly', 'filebird-dropbox-sync-pro'); ?></option>
                                <option value="twicedaily" <?php selected($settings['sync_frequency'], 'twicedaily'); ?>><?php _e('Twice Daily', 'filebird-dropbox-sync-pro'); ?></option>
                                <option value="daily" <?php selected($settings['sync_frequency'], 'daily'); ?>><?php _e('Daily', 'filebird-dropbox-sync-pro'); ?></option>
                                <option value="weekly" <?php selected($settings['sync_frequency'], 'weekly'); ?>><?php _e('Weekly', 'filebird-dropbox-sync-pro'); ?></option>
                            </select>
                        </div>
                        
                        <div class="fbds-form-field">
                            <label for="fbds-conflict-resolution"><?php _e('File Conflict Resolution', 'filebird-dropbox-sync-pro'); ?></label>
                            <select id="fbds-conflict-resolution" name="fbds_settings[conflict_resolution]">
                                <option value="newer" <?php selected($settings['conflict_resolution'], 'newer'); ?>><?php _e('Keep Newer File', 'filebird-dropbox-sync-pro'); ?></option>
                                <option value="filebird" <?php selected($settings['conflict_resolution'], 'filebird'); ?>><?php _e('FileBird Version Wins', 'filebird-dropbox-sync-pro'); ?></option>
                                <option value="dropbox" <?php selected($settings['conflict_resolution'], 'dropbox'); ?>><?php _e('Dropbox Version Wins', 'filebird-dropbox-sync-pro'); ?></option>
                                <option value="both" <?php selected($settings['conflict_resolution'], 'both'); ?>><?php _e('Keep Both (Rename)', 'filebird-dropbox-sync-pro'); ?></option>
                            </select>
                            <p class="fbds-field-description">
                                <?php _e('How to handle it when the same file exists in both FileBird and Dropbox with different content.', 'filebird-dropbox-sync-pro'); ?>
                            </p>
                        </div>
                        
                        <div class="fbds-form-field">
                            <label><?php _e('Allowed File Types', 'filebird-dropbox-sync-pro'); ?></label>
                            <div class="fbds-checkbox-group">
                                <?php 
                                $file_types = [
                                    'jpg' => __('JPEG Images (.jpg, .jpeg)', 'filebird-dropbox-sync-pro'),
                                    'png' => __('PNG Images (.png)', 'filebird-dropbox-sync-pro'),
                                    'gif' => __('GIF Images (.gif)', 'filebird-dropbox-sync-pro'),
                                    'pdf' => __('PDF Documents (.pdf)', 'filebird-dropbox-sync-pro'),
                                    'doc' => __('Word Documents (.doc, .docx)', 'filebird-dropbox-sync-pro'),
                                    'xls' => __('Excel Spreadsheets (.xls, .xlsx)', 'filebird-dropbox-sync-pro'),
                                    'zip' => __('Zip Archives (.zip)', 'filebird-dropbox-sync-pro'),
                                    'mp4' => __('Video Files (.mp4, .mov, .avi)', 'filebird-dropbox-sync-pro'),
                                    'mp3' => __('Audio Files (.mp3, .wav)', 'filebird-dropbox-sync-pro'),
                                ];
                                
                                foreach ($file_types as $type => $label): 
                                    $checked = in_array($type, $settings['file_types']);
                                ?>
                                <label class="fbds-checkbox-label">
                                    <input type="checkbox" name="fbds_settings[file_types][]" value="<?php echo $type; ?>" <?php checked($checked); ?>>
                                    <?php echo $label; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="fbds-field-description">
                                <?php _e('Only files with these extensions will be synchronized.', 'filebird-dropbox-sync-pro'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="fbds-card-footer">
                    <button type="submit" class="button button-primary">
                        <?php _e('Save Settings', 'filebird-dropbox-sync-pro'); ?>
                    </button>
                </div>
            </div>
        </form>
        
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><?php _e('Manual Synchronization', 'filebird-dropbox-sync-pro'); ?></h2>
            </div>
            <div class="fbds-card-body">
                <div class="fbds-manual-sync-section">
                    <p>
                        <?php _e('Run a manual synchronization to update files between FileBird, Dropbox, and ACF gallery fields.', 'filebird-dropbox-sync-pro'); ?>
                    </p>
                    
                    <div class="fbds-sync-options">
                        <div class="fbds-sync-option">
                            <h4><?php _e('Two-way Sync', 'filebird-dropbox-sync-pro'); ?></h4>
                            <p><?php _e('Synchronize files in both directions.', 'filebird-dropbox-sync-pro'); ?></p>
                            <button type="button" class="button fbds-manual-sync" data-direction="both" data-nonce="<?php echo wp_create_nonce('fbds_manual_sync'); ?>">
                                <span class="dashicons dashicons-update"></span> <?php _e('Run Two-way Sync', 'filebird-dropbox-sync-pro'); ?>
                            </button>
                        </div>
                        
                        <div class="fbds-sync-option">
                            <h4><?php _e('FileBird to Dropbox', 'filebird-dropbox-sync-pro'); ?></h4>
                            <p><?php _e('Push FileBird folders and files to Dropbox.', 'filebird-dropbox-sync-pro'); ?></p>
                            <button type="button" class="button fbds-manual-sync" data-direction="to_dropbox" data-nonce="<?php echo wp_create_nonce('fbds_manual_sync'); ?>">
                                <span class="dashicons dashicons-upload"></span> <?php _e('Push to Dropbox', 'filebird-dropbox-sync-pro'); ?>
                            </button>
                        </div>
                        
                        <div class="fbds-sync-option">
                            <h4><?php _e('Dropbox to FileBird', 'filebird-dropbox-sync-pro'); ?></h4>
                            <p><?php _e('Pull Dropbox folders and files to FileBird.', 'filebird-dropbox-sync-pro'); ?></p>
                            <button type="button" class="button fbds-manual-sync" data-direction="from_dropbox" data-nonce="<?php echo wp_create_nonce('fbds_manual_sync'); ?>">
                                <span class="dashicons dashicons-download"></span> <?php _e('Pull from Dropbox', 'filebird-dropbox-sync-pro'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_tab === 'logs'): ?>
        <!-- Logs Tab -->
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><?php _e('Activity Logs', 'filebird-dropbox-sync-pro'); ?></h2>
                <div class="fbds-card-actions">
                    <button type="button" class="button" id="fbds-refresh-logs" data-nonce="<?php echo wp_create_nonce('fbds_refresh_logs'); ?>">
                        <span class="dashicons dashicons-update"></span> <?php _e('Refresh', 'filebird-dropbox-sync-pro'); ?>
                    </button>
                    <button type="button" class="button" id="fbds-clear-logs" data-nonce="<?php echo wp_create_nonce('fbds_clear_logs'); ?>">
                        <span class="dashicons dashicons-trash"></span> <?php _e('Clear Logs', 'filebird-dropbox-sync-pro'); ?>
                    </button>
                </div>
            </div>
            <div class="fbds-card-body">
                <div class="fbds-logs-filters">
                    <div class="fbds-filter-field">
                        <label for="fbds-log-level-filter"><?php _e('Filter by Level:', 'filebird-dropbox-sync-pro'); ?></label>
                        <select id="fbds-log-level-filter">
                            <option value="all"><?php _e('All Levels', 'filebird-dropbox-sync-pro'); ?></option>
                            <option value="info"><?php _e('Info', 'filebird-dropbox-sync-pro'); ?></option>
                            <option value="warning"><?php _e('Warning', 'filebird-dropbox-sync-pro'); ?></option>
                            <option value="error"><?php _e('Error', 'filebird-dropbox-sync-pro'); ?></option>
                        </select>
                    </div>
                    
                    <div class="fbds-filter-field">
                        <label for="fbds-log-search"><?php _e('Search:', 'filebird-dropbox-sync-pro'); ?></label>
                        <input type="text" id="fbds-log-search" placeholder="<?php _e('Search logs...', 'filebird-dropbox-sync-pro'); ?>">
                    </div>
                </div>
                
                <div class="fbds-logs-table-container">
                    <table class="fbds-logs-table widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Time', 'filebird-dropbox-sync-pro'); ?></th>
                                <th><?php _e('Level', 'filebird-dropbox-sync-pro'); ?></th>
                                <th><?php _e('Message', 'filebird-dropbox-sync-pro'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="fbds-logs-tbody">
                            <?php if (empty($recent_logs)): ?>
                            <tr>
                                <td colspan="3" class="fbds-no-logs"><?php _e('No logs found.', 'filebird-dropbox-sync-pro'); ?></td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($recent_logs as $log): ?>
                                <tr class="fbds-log-level-<?php echo esc_attr($log['level']); ?>">
                                    <td class="fbds-log-time">
                                        <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $log['time']); ?>
                                    </td>
                                    <td class="fbds-log-level">
                                        <span class="fbds-log-badge fbds-log-badge-<?php echo esc_attr($log['level']); ?>">
                                            <?php echo esc_html(ucfirst($log['level'])); ?>
                                        </span>
                                    </td>
                                    <td class="fbds-log-message"><?php echo esc_html($log['message']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="fbds-log-file-info">
                    <p>
                        <?php _e('Complete logs are stored in:', 'filebird-dropbox-sync-pro'); ?> 
                        <code><?php echo esc_html($logger->get_log_file_path()); ?></code>
                    </p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=fbds_download_logs'), 'fbds_download_logs', 'nonce'); ?>" class="button">
                        <span class="dashicons dashicons-download"></span> <?php _e('Download Full Log File', 'filebird-dropbox-sync-pro'); ?>
                    </a>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
</div>