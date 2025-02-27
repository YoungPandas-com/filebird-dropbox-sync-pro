<?php
/**
 * Admin dashboard template
 *
 * @package FileBirdDropboxSyncPro
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get sync status
$is_connected = (new FileBird_Dropbox_API())->is_connected();
$last_sync_time = get_option('fbds_last_sync_time', 0);
$last_sync_status = get_option('fbds_last_sync_status', '');
$last_sync_error = get_option('fbds_last_sync_error', '');
$last_sync_direction = get_option('fbds_last_sync_direction', 'both');

// Get folder and file stats
$filebird_connector = new FileBird_Connector();
$total_folders = count($filebird_connector->get_all_folders());
$mapped_acf_fields = count(get_option('fbds_folder_field_mappings', []));

// Get activity log
$logger = new FileBird_Dropbox_Sync_Logger();
$recent_logs = $logger->get_recent_logs(10);
?>

<div class="wrap fbds-admin">
    <h1 class="fbds-page-title"><?php _e('FileBird Dropbox Sync', 'filebird-dropbox-sync-pro'); ?></h1>
    
    <?php if (!$is_connected): ?>
    <div class="fbds-notice fbds-notice-warning">
        <p>
            <span class="dashicons dashicons-warning"></span>
            <?php _e('Dropbox is not connected. Please connect your Dropbox account to enable synchronization.', 'filebird-dropbox-sync-pro'); ?>
            <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-settings'); ?>" class="button button-primary">
                <?php _e('Connect Dropbox', 'filebird-dropbox-sync-pro'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>
    
    <div class="fbds-dashboard-grid">
        <!-- Status Card -->
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><span class="dashicons dashicons-clipboard"></span> <?php _e('Sync Status', 'filebird-dropbox-sync-pro'); ?></h2>
            </div>
            <div class="fbds-card-body">
                <div class="fbds-status-indicator">
                    <?php if ($last_sync_status === 'completed'): ?>
                        <span class="fbds-status-dot fbds-status-success"></span>
                        <span class="fbds-status-text"><?php _e('Last sync completed successfully', 'filebird-dropbox-sync-pro'); ?></span>
                    <?php elseif ($last_sync_status === 'in_progress'): ?>
                        <span class="fbds-status-dot fbds-status-working"></span>
                        <span class="fbds-status-text"><?php _e('Sync in progress...', 'filebird-dropbox-sync-pro'); ?></span>
                    <?php elseif ($last_sync_status === 'failed'): ?>
                        <span class="fbds-status-dot fbds-status-error"></span>
                        <span class="fbds-status-text"><?php _e('Last sync failed', 'filebird-dropbox-sync-pro'); ?></span>
                    <?php elseif ($last_sync_status === 'partial'): ?>
                        <span class="fbds-status-dot fbds-status-warning"></span>
                        <span class="fbds-status-text"><?php _e('Last sync partially completed with errors', 'filebird-dropbox-sync-pro'); ?></span>
                    <?php elseif ($last_sync_status === 'scheduled'): ?>
                        <span class="fbds-status-dot fbds-status-waiting"></span>
                        <span class="fbds-status-text"><?php _e('Sync scheduled', 'filebird-dropbox-sync-pro'); ?></span>
                    <?php else: ?>
                        <span class="fbds-status-dot fbds-status-unknown"></span>
                        <span class="fbds-status-text"><?php _e('No sync performed yet', 'filebird-dropbox-sync-pro'); ?></span>
                    <?php endif; ?>
                </div>
                
                <?php if ($last_sync_time): ?>
                <div class="fbds-last-sync">
                    <p>
                        <strong><?php _e('Last Sync:', 'filebird-dropbox-sync-pro'); ?></strong> 
                        <?php echo human_time_diff($last_sync_time, time()) . ' ' . __('ago', 'filebird-dropbox-sync-pro'); ?>
                        (<?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync_time); ?>)
                    </p>
                    
                    <p>
                        <strong><?php _e('Direction:', 'filebird-dropbox-sync-pro'); ?></strong> 
                        <?php 
                        switch ($last_sync_direction) {
                            case 'both':
                                _e('Two-way sync', 'filebird-dropbox-sync-pro');
                                break;
                            case 'to_dropbox':
                                _e('FileBird to Dropbox', 'filebird-dropbox-sync-pro');
                                break;
                            case 'from_dropbox':
                                _e('Dropbox to FileBird', 'filebird-dropbox-sync-pro');
                                break;
                        }
                        ?>
                    </p>
                    
                    <?php if ($last_sync_status === 'failed' && $last_sync_error): ?>
                    <p class="fbds-error-message">
                        <strong><?php _e('Error:', 'filebird-dropbox-sync-pro'); ?></strong> 
                        <?php echo esc_html($last_sync_error); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($is_connected): ?>
                <div class="fbds-sync-actions">
                    <button type="button" class="button button-primary fbds-manual-sync" data-direction="both" data-nonce="<?php echo wp_create_nonce('fbds_manual_sync'); ?>">
                        <span class="dashicons dashicons-update"></span> <?php _e('Sync Now', 'filebird-dropbox-sync-pro'); ?>
                    </button>
                    
                    <div class="fbds-dropdown">
                        <button type="button" class="button fbds-dropdown-toggle">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        <div class="fbds-dropdown-content">
                            <a href="#" class="fbds-manual-sync" data-direction="to_dropbox" data-nonce="<?php echo wp_create_nonce('fbds_manual_sync'); ?>">
                                <?php _e('FileBird to Dropbox', 'filebird-dropbox-sync-pro'); ?>
                            </a>
                            <a href="#" class="fbds-manual-sync" data-direction="from_dropbox" data-nonce="<?php echo wp_create_nonce('fbds_manual_sync'); ?>">
                                <?php _e('Dropbox to FileBird', 'filebird-dropbox-sync-pro'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Stats Card -->
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><span class="dashicons dashicons-chart-pie"></span> <?php _e('Statistics', 'filebird-dropbox-sync-pro'); ?></h2>
            </div>
            <div class="fbds-card-body">
                <div class="fbds-stats-grid">
                    <div class="fbds-stat-item">
                        <div class="fbds-stat-icon">
                            <span class="dashicons dashicons-category"></span>
                        </div>
                        <div class="fbds-stat-content">
                            <span class="fbds-stat-value"><?php echo $total_folders; ?></span>
                            <span class="fbds-stat-label"><?php _e('FileBird Folders', 'filebird-dropbox-sync-pro'); ?></span>
                        </div>
                    </div>
                    
                    <div class="fbds-stat-item">
                        <div class="fbds-stat-icon">
                            <span class="dashicons dashicons-admin-appearance"></span>
                        </div>
                        <div class="fbds-stat-content">
                            <span class="fbds-stat-value"><?php echo $mapped_acf_fields; ?></span>
                            <span class="fbds-stat-label"><?php _e('ACF Field Mappings', 'filebird-dropbox-sync-pro'); ?></span>
                        </div>
                    </div>
                    
                    <div class="fbds-stat-item">
                        <div class="fbds-stat-icon">
                            <span class="dashicons dashicons-cloud"></span>
                        </div>
                        <div class="fbds-stat-content">
                            <span class="fbds-stat-value"><?php echo $is_connected ? __('Connected', 'filebird-dropbox-sync-pro') : __('Disconnected', 'filebird-dropbox-sync-pro'); ?></span>
                            <span class="fbds-stat-label"><?php _e('Dropbox Status', 'filebird-dropbox-sync-pro'); ?></span>
                        </div>
                    </div>
                    
                    <div class="fbds-stat-item">
                        <div class="fbds-stat-icon">
                            <span class="dashicons dashicons-calendar-alt"></span>
                        </div>
                        <div class="fbds-stat-content">
                            <span class="fbds-stat-value"><?php echo $last_sync_time ? human_time_diff($last_sync_time, time()) : __('Never', 'filebird-dropbox-sync-pro'); ?></span>
                            <span class="fbds-stat-label"><?php _e('Since Last Sync', 'filebird-dropbox-sync-pro'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions Card -->
        <div class="fbds-card">
            <div class="fbds-card-header">
                <h2><span class="dashicons dashicons-admin-generic"></span> <?php _e('Quick Actions', 'filebird-dropbox-sync-pro'); ?></h2>
            </div>
            <div class="fbds-card-body">
                <div class="fbds-quick-actions">
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-mapping'); ?>" class="fbds-quick-action-btn">
                        <span class="dashicons dashicons-networking"></span>
                        <span class="fbds-btn-text"><?php _e('Manage Folder Mappings', 'filebird-dropbox-sync-pro'); ?></span>
                    </a>
                    
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-settings'); ?>" class="fbds-quick-action-btn">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <span class="fbds-btn-text"><?php _e('Configure Settings', 'filebird-dropbox-sync-pro'); ?></span>
                    </a>
                    
                    <?php if (!$is_connected): ?>
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-setup'); ?>" class="fbds-quick-action-btn fbds-highlighted">
                        <span class="dashicons dashicons-admin-network"></span>
                        <span class="fbds-btn-text"><?php _e('Setup Wizard', 'filebird-dropbox-sync-pro'); ?></span>
                    </a>
                    <?php else: ?>
                    <a href="<?php echo admin_url('upload.php?page=filebird-settings'); ?>" class="fbds-quick-action-btn">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <span class="fbds-btn-text"><?php _e('FileBird Settings', 'filebird-dropbox-sync-pro'); ?></span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Activity Log Card -->
        <div class="fbds-card fbds-card-full-width">
            <div class="fbds-card-header">
                <h2><span class="dashicons dashicons-list-view"></span> <?php _e('Recent Activity', 'filebird-dropbox-sync-pro'); ?></h2>
            </div>
            <div class="fbds-card-body">
                <?php if (empty($recent_logs)): ?>
                <p class="fbds-no-logs"><?php _e('No recent activity found.', 'filebird-dropbox-sync-pro'); ?></p>
                <?php else: ?>
                <div class="fbds-activity-log">
                    <table class="fbds-log-table">
                        <thead>
                            <tr>
                                <th><?php _e('Time', 'filebird-dropbox-sync-pro'); ?></th>
                                <th><?php _e('Level', 'filebird-dropbox-sync-pro'); ?></th>
                                <th><?php _e('Message', 'filebird-dropbox-sync-pro'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
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
                        </tbody>
                    </table>
                </div>
                <div class="fbds-view-all-logs">
                    <a href="<?php echo admin_url('admin.php?page=filebird-dropbox-settings&tab=logs'); ?>" class="button">
                        <?php _e('View All Logs', 'filebird-dropbox-sync-pro'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>