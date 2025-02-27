/**
 * FileBird Dropbox Sync Pro - Sync Engine JavaScript
 */
(function($) {
    'use strict';
    
    // Sync Engine functionality
    const SyncEngine = {
        syncInProgress: false,
        syncStatus: null,
        syncTimer: null,
        
        init: function() {
            this.bindEvents();
            this.initStatusChecker();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Manual sync buttons
            $('.fbds-manual-sync').on('click', function(e) {
                e.preventDefault();
                
                if (SyncEngine.syncInProgress) {
                    SyncEngine.showNotice('A synchronization is already in progress. Please wait until it completes.', 'warning');
                    return;
                }
                
                const direction = $(this).data('direction');
                const nonce = $(this).data('nonce');
                
                SyncEngine.startSync(direction, nonce);
            });
        },
        
        /**
         * Initialize sync status checker
         */
        initStatusChecker: function() {
            // Check status every 5 seconds if a sync is in progress
            this.syncTimer = setInterval(function() {
                if (SyncEngine.syncInProgress) {
                    SyncEngine.checkSyncStatus();
                }
            }, 5000);
            
            // Initial check
            this.checkSyncStatus();
        },
        
        /**
         * Start a synchronization
         * 
         * @param {string} direction The sync direction (both, to_dropbox, from_dropbox)
         * @param {string} nonce The security nonce
         */
        startSync: function(direction, nonce) {
            this.syncInProgress = true;
            this.updateSyncStatusUI('in_progress');
            this.showNotice(fbds_data.texts.sync_started, 'info');
            
            // Disable sync buttons
            $('.fbds-manual-sync').prop('disabled', true);
            
            $.ajax({
                url: fbds_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fbds_manual_sync',
                    direction: direction,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        // The sync has been scheduled, we need to check status
                        SyncEngine.showNotice('Synchronization has been scheduled.', 'success');
                    } else {
                        SyncEngine.syncInProgress = false;
                        SyncEngine.updateSyncStatusUI('failed');
                        SyncEngine.showNotice(response.data.message || fbds_data.texts.sync_failed, 'error');
                        
                        // Re-enable sync buttons
                        $('.fbds-manual-sync').prop('disabled', false);
                    }
                },
                error: function() {
                    SyncEngine.syncInProgress = false;
                    SyncEngine.updateSyncStatusUI('failed');
                    SyncEngine.showNotice(fbds_data.texts.sync_failed, 'error');
                    
                    // Re-enable sync buttons
                    $('.fbds-manual-sync').prop('disabled', false);
                }
            });
        },
        
        /**
         * Check sync status
         */
        checkSyncStatus: function() {
            $.ajax({
                url: fbds_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fbds_check_sync_status',
                    nonce: fbds_data.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const status = response.data.status;
                        
                        if (status !== SyncEngine.syncStatus) {
                            SyncEngine.syncStatus = status;
                            SyncEngine.updateSyncStatusUI(status);
                            
                            if (status === 'completed') {
                                SyncEngine.syncCompleted();
                            } else if (status === 'failed') {
                                SyncEngine.syncFailed(response.data.error || '');
                            }
                        }
                        
                        // If status is in_progress or scheduled, keep checking
                        if (status === 'in_progress' || status === 'scheduled') {
                            SyncEngine.syncInProgress = true;
                        } else {
                            SyncEngine.syncInProgress = false;
                            
                            // Re-enable sync buttons
                            $('.fbds-manual-sync').prop('disabled', false);
                        }
                    }
                }
            });
        },
        
        /**
         * Sync completed handler
         */
        syncCompleted: function() {
            this.syncInProgress = false;
            this.showNotice(fbds_data.texts.sync_completed, 'success');
            
            // Update last sync time display
            const now = new Date();
            $('.fbds-last-sync time').text('Just now');
            
            // Update stats
            this.updateStats();
        },
        
        /**
         * Sync failed handler
         * 
         * @param {string} error The error message
         */
        syncFailed: function(error) {
            this.syncInProgress = false;
            this.showNotice(error || fbds_data.texts.sync_failed, 'error');
            
            // Update error message display if it exists
            if ($('.fbds-error-message').length) {
                $('.fbds-error-message strong').text('Error: ');
                $('.fbds-error-message').show().find('span').text(error);
            }
        },
        
        /**
         * Update sync status UI
         * 
         * @param {string} status The sync status
         */
        updateSyncStatusUI: function(status) {
            // Remove all status classes
            $('.fbds-status-indicator .fbds-status-dot')
                .removeClass('fbds-status-success fbds-status-error fbds-status-working fbds-status-waiting fbds-status-warning fbds-status-unknown');
            
            // Add appropriate class based on status
            switch (status) {
                case 'completed':
                    $('.fbds-status-indicator .fbds-status-dot').addClass('fbds-status-success');
                    $('.fbds-status-indicator .fbds-status-text').text('Last sync completed successfully');
                    break;
                case 'in_progress':
                    $('.fbds-status-indicator .fbds-status-dot').addClass('fbds-status-working');
                    $('.fbds-status-indicator .fbds-status-text').text('Sync in progress...');
                    break;
                case 'failed':
                    $('.fbds-status-indicator .fbds-status-dot').addClass('fbds-status-error');
                    $('.fbds-status-indicator .fbds-status-text').text('Last sync failed');
                    break;
                case 'partial':
                    $('.fbds-status-indicator .fbds-status-dot').addClass('fbds-status-warning');
                    $('.fbds-status-indicator .fbds-status-text').text('Last sync partially completed with errors');
                    break;
                case 'scheduled':
                    $('.fbds-status-indicator .fbds-status-dot').addClass('fbds-status-waiting');
                    $('.fbds-status-indicator .fbds-status-text').text('Sync scheduled');
                    break;
                default:
                    $('.fbds-status-indicator .fbds-status-dot').addClass('fbds-status-unknown');
                    $('.fbds-status-indicator .fbds-status-text').text('No sync performed yet');
                    break;
            }
        },
        
        /**
         * Update stats display
         */
        updateStats: function() {
            // Refresh the activity logs
            this.refreshLogs();
            
            // If we have the stats section, update it
            if ($('.fbds-stats-grid').length) {
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_get_stats',
                        nonce: fbds_data.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update folder count
                            $('.fbds-stat-item:nth-child(1) .fbds-stat-value').text(response.data.folder_count);
                            
                            // Update mapping count
                            $('.fbds-stat-item:nth-child(2) .fbds-stat-value').text(response.data.mapping_count);
                            
                            // Update last sync time
                            $('.fbds-stat-item:nth-child(4) .fbds-stat-value').text('Just now');
                        }
                    }
                });
            }
        },
        
        /**
         * Refresh logs
         */
        refreshLogs: function() {
            if ($('.fbds-activity-log').length) {
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_refresh_logs',
                        nonce: fbds_data.nonce,
                        limit: 10 // Only get the 10 most recent logs for the dashboard
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.fbds-activity-log table tbody').html(response.data.logs_html);
                        }
                    }
                });
            }
        },
        
        /**
         * Show notification
         * 
         * @param {string} message The message to show
         * @param {string} type The notice type (success, error, info, warning)
         */
        showNotice: function(message, type = 'info') {
            // Remove existing notices
            $('.fbds-notice-popup').remove();
            
            // Create notice element
            const notice = $(`<div class="fbds-notice-popup fbds-notice-${type}"><p>${message}</p></div>`);
            
            // Add close button
            const closeBtn = $('<button type="button" class="fbds-notice-close">&times;</button>');
            closeBtn.on('click', function() {
                notice.fadeOut(200, function() {
                    $(this).remove();
                });
            });
            
            notice.prepend(closeBtn);
            
            // Add to body and show
            $('body').append(notice);
            notice.fadeIn(200);
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                notice.fadeOut(200, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        SyncEngine.init();
    });
    
})(jQuery);