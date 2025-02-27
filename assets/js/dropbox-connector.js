/**
 * FileBird Dropbox Sync Pro - Dropbox Connector JavaScript
 */
(function($) {
    'use strict';
    
    // Dropbox Connector functionality
    const DropboxConnector = {
        authWindow: null,
        checkAuthInterval: null,
        
        init: function() {
            this.bindEvents();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Save API settings
            $('#fbds-save-api-settings').on('click', function() {
                DropboxConnector.saveAPISettings($(this));
            });
            
            // Connect to Dropbox
            $('#fbds-connect-dropbox').on('click', function() {
                DropboxConnector.connectToDropbox($(this));
            });
            
            // Disconnect from Dropbox
            $('.fbds-disconnect-btn').on('click', function() {
                DropboxConnector.disconnectFromDropbox($(this));
            });
            
            // Copy webhook URL
            $('.fbds-copy-webhook-url').on('click', function() {
                DropboxConnector.copyWebhookURL($(this));
            });
        },
        
        /**
         * Save API settings
         * 
         * @param {jQuery} button The button element
         */
        saveAPISettings: function(button) {
            const appKey = $('#dropbox_app_key').val();
            const appSecret = $('#dropbox_app_secret').val();
            const nonce = button.data('nonce');
            
            if (!appKey || !appSecret) {
                this.showNotice('Please enter both App Key and App Secret.', 'error');
                return;
            }
            
            this.showLoading(button);
            
            $.ajax({
                url: fbds_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fbds_save_api_settings',
                    app_key: appKey,
                    app_secret: appSecret,
                    nonce: nonce
                },
                success: function(response) {
                    DropboxConnector.hideLoading();
                    
                    if (response.success) {
                        DropboxConnector.showNotice(response.data.message, 'success');
                        $('#fbds-connect-dropbox').prop('disabled', false);
                    } else {
                        DropboxConnector.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    DropboxConnector.hideLoading();
                    DropboxConnector.showNotice('An error occurred. Please try again.', 'error');
                }
            });
        },
        
        /**
         * Connect to Dropbox
         * 
         * @param {jQuery} button The button element
         */
        connectToDropbox: function(button) {
            const nonce = button.data('nonce');
            
            this.showLoading(button);
            
            $.ajax({
                url: fbds_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fbds_get_dropbox_auth_url',
                    nonce: nonce
                },
                success: function(response) {
                    DropboxConnector.hideLoading();
                    
                    if (response.success && response.data.auth_url) {
                        // Open Dropbox auth in a popup
                        DropboxConnector.authWindow = window.open(response.data.auth_url, 'dropbox_auth', 'width=800,height=600');
                        
                        // Set up a check to see if auth was successful
                        DropboxConnector.checkAuthInterval = setInterval(function() {
                            DropboxConnector.checkAuthStatus(nonce);
                        }, 3000); // Check every 3 seconds
                        
                        // Stop checking after 2 minutes
                        setTimeout(function() {
                            clearInterval(DropboxConnector.checkAuthInterval);
                        }, 120000);
                    } else {
                        DropboxConnector.showNotice(response.data.message || 'Failed to get authorization URL.', 'error');
                    }
                },
                error: function() {
                    DropboxConnector.hideLoading();
                    DropboxConnector.showNotice('An error occurred. Please try again.', 'error');
                }
            });
        },
        
        /**
         * Check authentication status
         * 
         * @param {string} nonce The security nonce
         */
        checkAuthStatus: function(nonce) {
            $.ajax({
                url: fbds_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fbds_check_dropbox_connection',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success && response.data.connected) {
                        clearInterval(DropboxConnector.checkAuthInterval);
                        
                        // Close the auth window if it's still open
                        if (DropboxConnector.authWindow && !DropboxConnector.authWindow.closed) {
                            DropboxConnector.authWindow.close();
                        }
                        
                        DropboxConnector.showNotice('Successfully connected to Dropbox!', 'success');
                        
                        // Reload the page after a short delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    }
                }
            });
        },
        
        /**
         * Disconnect from Dropbox
         * 
         * @param {jQuery} button The button element
         */
        disconnectFromDropbox: function(button) {
            if (!confirm(fbds_data.texts.confirm_disconnect)) {
                return;
            }
            
            const nonce = button.data('nonce');
            
            this.showLoading(button);
            
            $.ajax({
                url: fbds_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fbds_disconnect_dropbox',
                    nonce: nonce
                },
                success: function(response) {
                    DropboxConnector.hideLoading();
                    
                    if (response.success) {
                        DropboxConnector.showNotice(response.data.message, 'success');
                        
                        // Reload the page after a short delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        DropboxConnector.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    DropboxConnector.hideLoading();
                    DropboxConnector.showNotice('An error occurred. Please try again.', 'error');
                }
            });
        },
        
        /**
         * Copy webhook URL to clipboard
         * 
         * @param {jQuery} button The button element
         */
        copyWebhookURL: function(button) {
            const webhookUrl = $('.fbds-webhook-url').text();
            
            // Create a temporary input element
            const tempInput = document.createElement('input');
            tempInput.value = webhookUrl;
            document.body.appendChild(tempInput);
            
            // Select and copy the text
            tempInput.select();
            document.execCommand('copy');
            
            // Remove the temporary element
            document.body.removeChild(tempInput);
            
            // Show success message
            button.html('<span class="dashicons dashicons-yes"></span>');
            
            setTimeout(() => {
                button.html('<span class="dashicons dashicons-clipboard"></span>');
            }, 2000);
            
            this.showNotice('Webhook URL copied to clipboard!', 'success');
        },
        
        /**
         * Show loading indicator
         * 
         * @param {jQuery} button The button to show loading on
         */
        showLoading: function(button) {
            if (button) {
                button.prop('disabled', true).addClass('fbds-loading');
                button.data('original-text', button.html());
                button.html('<span class="fbds-spinner"></span>');
            }
        },
        
        /**
         * Hide loading indicator
         */
        hideLoading: function() {
            $('.fbds-loading').each(function() {
                $(this).prop('disabled', false).removeClass('fbds-loading');
                $(this).html($(this).data('original-text'));
            });
        },
        
        /**
         * Show notification
         * 
         * @param {string} message The message to show
         * @param {string} type The notice type (success, error, info)
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
        DropboxConnector.init();
    });
    
})(jQuery);