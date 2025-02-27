/**
 * FileBird Dropbox Sync Pro - Setup Wizard JavaScript
 */
(function($) {
    'use strict';
    
    // Setup Wizard functionality
    const SetupWizard = {
        currentStep: 1,
        totalSteps: 4,
        
        init: function() {
            // Get current step from URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            this.currentStep = parseInt(urlParams.get('step')) || 1;
            
            this.bindEvents();
            this.initDropboxConnection();
            this.initFolderMapping();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Next step button
            $('.fbds-wizard-navigation .button-primary').on('click', function(e) {
                e.preventDefault();
                
                const nextStep = SetupWizard.currentStep + 1;
                if (nextStep <= SetupWizard.totalSteps) {
                    window.location.href = $(this).attr('href');
                }
            });
            
            // Previous step button
            $('.fbds-wizard-navigation .button:not(.button-primary)').on('click', function(e) {
                e.preventDefault();
                
                const prevStep = SetupWizard.currentStep - 1;
                if (prevStep >= 1) {
                    window.location.href = $(this).attr('href');
                }
            });
            
            // Let's get started button (Step 1)
            $('.fbds-wizard-actions .button-hero').on('click', function(e) {
                e.preventDefault();
                window.location.href = $(this).attr('href');
            });
        },
        
        /**
         * Initialize Dropbox connection (Step 2)
         */
        initDropboxConnection: function() {
            if (this.currentStep !== 2) {
                return;
            }
            
            // Save API settings
            $('#fbds-save-api-settings').on('click', function() {
                const appKey = $('#dropbox_app_key').val();
                const appSecret = $('#dropbox_app_secret').val();
                const nonce = $(this).data('nonce');
                
                if (!appKey || !appSecret) {
                    SetupWizard.showNotice('Please enter both App Key and App Secret.', 'error');
                    return;
                }
                
                SetupWizard.showLoading($(this));
                
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
                        SetupWizard.hideLoading();
                        
                        if (response.success) {
                            SetupWizard.showNotice(response.data.message, 'success');
                            $('#fbds-connect-dropbox').prop('disabled', false);
                        } else {
                            SetupWizard.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        SetupWizard.hideLoading();
                        SetupWizard.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
            
            // Connect to Dropbox
            $('#fbds-connect-dropbox').on('click', function() {
                const nonce = $(this).data('nonce');
                
                SetupWizard.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_get_dropbox_auth_url',
                        nonce: nonce
                    },
                    success: function(response) {
                        SetupWizard.hideLoading();
                        
                        if (response.success && response.data.auth_url) {
                            // Open Dropbox auth in a popup
                            const authWindow = window.open(response.data.auth_url, 'dropbox_auth', 'width=800,height=600');
                            
                            // Set up a check to see if auth was successful
                            const checkAuthInterval = setInterval(function() {
                                $.ajax({
                                    url: fbds_data.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'fbds_check_dropbox_connection',
                                        nonce: nonce
                                    },
                                    success: function(checkResponse) {
                                        if (checkResponse.success && checkResponse.data.connected) {
                                            clearInterval(checkAuthInterval);
                                            
                                            // Close the auth window if it's still open
                                            if (authWindow && !authWindow.closed) {
                                                authWindow.close();
                                            }
                                            
                                            SetupWizard.showNotice('Successfully connected to Dropbox!', 'success');
                                            
                                            // Reload the page after a short delay
                                            setTimeout(function() {
                                                window.location.reload();
                                            }, 1500);
                                        }
                                    }
                                });
                            }, 3000); // Check every 3 seconds
                            
                            // Stop checking after 2 minutes
                            setTimeout(function() {
                                clearInterval(checkAuthInterval);
                            }, 120000);
                        } else {
                            SetupWizard.showNotice(response.data.message || 'Failed to get authorization URL.', 'error');
                        }
                    },
                    error: function() {
                        SetupWizard.hideLoading();
                        SetupWizard.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
            
            // Disconnect from Dropbox
            $('.fbds-disconnect-btn').on('click', function() {
                if (!confirm('Are you sure you want to disconnect from Dropbox?')) {
                    return;
                }
                
                const nonce = $(this).data('nonce');
                
                SetupWizard.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_disconnect_dropbox',
                        nonce: nonce
                    },
                    success: function(response) {
                        SetupWizard.hideLoading();
                        
                        if (response.success) {
                            SetupWizard.showNotice(response.data.message, 'success');
                            
                            // Reload the page after a short delay
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        } else {
                            SetupWizard.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        SetupWizard.hideLoading();
                        SetupWizard.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
        },
        
        /**
         * Initialize folder mapping (Step 3)
         */
        initFolderMapping: function() {
            if (this.currentStep !== 3) {
                return;
            }
            
            // Add mapping button
            $('.fbds-add-mapping-btn').on('click', function() {
                // Reset form
                $('#fbds-mapping-form')[0].reset();
                $('#fbds-mapping-id').val('');
                $('#fbds-field-select').html('<option value="">Select a post first...</option>').prop('disabled', true);
                $('#fbds-mapping-info').hide();
                
                // Set modal title
                $('#fbds-modal-title').text('Add New Mapping');
                
                // Show modal
                $('#fbds-mapping-modal').addClass('fbds-show');
            });
            
            // Post select change
            $('#fbds-post-select').on('change', function() {
                const postId = $(this).val();
                
                if (!postId) {
                    $('#fbds-field-select').html('<option value="">Select a post first...</option>').prop('disabled', true);
                    return;
                }
                
                // Show loading indicator
                $('#fbds-field-loading').show();
                $('#fbds-field-select').prop('disabled', true);
                
                if (!fbdsAcfData[postId] || !fbdsAcfData[postId].gallery_fields) {
                    $('#fbds-field-select').html('<option value="">No gallery fields found</option>');
                    $('#fbds-field-loading').hide();
                    return;
                }
                
                const fields = fbdsAcfData[postId].gallery_fields;
                let options = '<option value="">Select a gallery field...</option>';
                
                fields.forEach(function(field) {
                    options += `<option value="${field.key}">${field.label || field.name}</option>`;
                });
                
                $('#fbds-field-select').html(options).prop('disabled', false);
                $('#fbds-field-loading').hide();
            });
            
            // Save mapping button
            $('#fbds-save-mapping-btn').on('click', function() {
                const folderId = $('#fbds-folder-select').val();
                const fieldKey = $('#fbds-field-select').val();
                const postId = $('#fbds-post-select').val();
                const nonce = $('#fbds-mapping-nonce').val();
                
                if (!folderId || !fieldKey || !postId) {
                    SetupWizard.showNotice('Please fill in all fields.', 'error');
                    return;
                }
                
                SetupWizard.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_save_mapping',
                        mapping_id: '',
                        folder_id: folderId,
                        field_key: fieldKey,
                        post_id: postId,
                        nonce: nonce
                    },
                    success: function(response) {
                        SetupWizard.hideLoading();
                        
                        if (response.success) {
                            // Hide modal
                            $('#fbds-mapping-modal').removeClass('fbds-show');
                            
                            // Show success message
                            SetupWizard.showNotice(response.data.message, 'success');
                            
                            // Reload the page to show the updated mappings
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        } else {
                            SetupWizard.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        SetupWizard.hideLoading();
                        SetupWizard.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
            
            // Close modal
            $('.fbds-modal-close, .fbds-modal-cancel').on('click', function() {
                $('#fbds-mapping-modal').removeClass('fbds-show');
            });
            
            // Close modal when clicking outside
            $('#fbds-mapping-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).removeClass('fbds-show');
                }
            });
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
        SetupWizard.init();
    });
    
})(jQuery);