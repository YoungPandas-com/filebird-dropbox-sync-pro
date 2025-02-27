/**
 * FileBird Dropbox Sync Pro - Admin JavaScript
 */
(function($) {
    'use strict';
    
    // Main admin functions
    const FileBirdDropboxSync = {
        init: function() {
            this.initTabs();
            this.initDropboxConnection();
            this.initManualSync();
            this.initLogsFilter();
            this.initCopyWebhook();
            this.initMapperInterface();
            this.initModal();
        },
        
        /**
         * Initialize tabs
         */
        initTabs: function() {
            $('.fbds-tab').on('click', function(e) {
                const tab = $(this).attr('href').split('tab=')[1];
                $('.fbds-tab').removeClass('active');
                $(this).addClass('active');
                $('.fbds-tab-content').hide();
                $(`.fbds-tab-content[data-tab="${tab}"]`).show();
                
                // Don't follow the link
                e.preventDefault();
            });
        },
        
        /**
         * Initialize Dropbox connection
         */
        initDropboxConnection: function() {
            // Save API settings
            $('#fbds-save-api-settings').on('click', function() {
                const appKey = $('#dropbox_app_key').val();
                const appSecret = $('#dropbox_app_secret').val();
                const nonce = $(this).data('nonce');
                
                if (!appKey || !appSecret) {
                    FileBirdDropboxSync.showNotice('Please enter both App Key and App Secret.', 'error');
                    return;
                }
                
                FileBirdDropboxSync.showLoading($(this));
                
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
                        FileBirdDropboxSync.hideLoading();
                        
                        if (response.success) {
                            FileBirdDropboxSync.showNotice(response.data.message, 'success');
                            $('#fbds-connect-dropbox').prop('disabled', false);
                        } else {
                            FileBirdDropboxSync.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        FileBirdDropboxSync.hideLoading();
                        FileBirdDropboxSync.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
            
            // Connect to Dropbox
            $('#fbds-connect-dropbox').on('click', function() {
                const nonce = $(this).data('nonce');
                
                FileBirdDropboxSync.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_get_dropbox_auth_url',
                        nonce: nonce
                    },
                    success: function(response) {
                        FileBirdDropboxSync.hideLoading();
                        
                        if (response.success && response.data.auth_url) {
                            // Open Dropbox auth in a popup
                            window.open(response.data.auth_url, 'dropbox_auth', 'width=800,height=600');
                            
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
                                            FileBirdDropboxSync.showNotice('Successfully connected to Dropbox!', 'success');
                                            
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
                            FileBirdDropboxSync.showNotice(response.data.message || 'Failed to get authorization URL.', 'error');
                        }
                    },
                    error: function() {
                        FileBirdDropboxSync.hideLoading();
                        FileBirdDropboxSync.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
            
            // Disconnect from Dropbox
            $('.fbds-disconnect-btn').on('click', function() {
                if (!confirm(fbds_data.texts.confirm_disconnect)) {
                    return;
                }
                
                const nonce = $(this).data('nonce');
                
                FileBirdDropboxSync.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_disconnect_dropbox',
                        nonce: nonce
                    },
                    success: function(response) {
                        FileBirdDropboxSync.hideLoading();
                        
                        if (response.success) {
                            FileBirdDropboxSync.showNotice(response.data.message, 'success');
                            
                            // Reload the page after a short delay
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        } else {
                            FileBirdDropboxSync.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        FileBirdDropboxSync.hideLoading();
                        FileBirdDropboxSync.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
        },
        
        /**
         * Initialize manual sync
         */
        initManualSync: function() {
            $('.fbds-manual-sync').on('click', function() {
                const direction = $(this).data('direction');
                const nonce = $(this).data('nonce');
                
                FileBirdDropboxSync.showLoading($(this));
                FileBirdDropboxSync.showNotice(fbds_data.texts.sync_started, 'info');
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_manual_sync',
                        direction: direction,
                        nonce: nonce
                    },
                    success: function(response) {
                        FileBirdDropboxSync.hideLoading();
                        
                        if (response.success) {
                            FileBirdDropboxSync.showNotice(fbds_data.texts.sync_completed, 'success');
                        } else {
                            FileBirdDropboxSync.showNotice(fbds_data.texts.sync_failed, 'error');
                        }
                    },
                    error: function() {
                        FileBirdDropboxSync.hideLoading();
                        FileBirdDropboxSync.showNotice(fbds_data.texts.sync_failed, 'error');
                    }
                });
            });
        },
        
        /**
         * Initialize logs filter
         */
        initLogsFilter: function() {
            // Level filter
            $('#fbds-log-level-filter').on('change', function() {
                const level = $(this).val();
                
                if (level === 'all') {
                    $('.fbds-logs-table tbody tr').show();
                } else {
                    $('.fbds-logs-table tbody tr').hide();
                    $(`.fbds-logs-table tbody tr.fbds-log-level-${level}`).show();
                }
            });
            
            // Search filter
            $('#fbds-log-search').on('keyup', function() {
                const query = $(this).val().toLowerCase();
                
                if (!query) {
                    $('.fbds-logs-table tbody tr').show();
                    $('#fbds-log-level-filter').trigger('change'); // Apply level filter
                    return;
                }
                
                $('.fbds-logs-table tbody tr').each(function() {
                    const text = $(this).text().toLowerCase();
                    if (text.includes(query)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });
            
            // Refresh logs
            $('#fbds-refresh-logs').on('click', function() {
                const nonce = $(this).data('nonce');
                
                FileBirdDropboxSync.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_refresh_logs',
                        nonce: nonce
                    },
                    success: function(response) {
                        FileBirdDropboxSync.hideLoading();
                        
                        if (response.success) {
                            $('#fbds-logs-tbody').html(response.data.logs_html);
                            FileBirdDropboxSync.showNotice('Logs refreshed successfully.', 'success');
                        } else {
                            FileBirdDropboxSync.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        FileBirdDropboxSync.hideLoading();
                        FileBirdDropboxSync.showNotice('An error occurred while refreshing logs.', 'error');
                    }
                });
            });
            
            // Clear logs
            $('#fbds-clear-logs').on('click', function() {
                if (!confirm('Are you sure you want to clear all logs?')) {
                    return;
                }
                
                const nonce = $(this).data('nonce');
                
                FileBirdDropboxSync.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_clear_logs',
                        nonce: nonce
                    },
                    success: function(response) {
                        FileBirdDropboxSync.hideLoading();
                        
                        if (response.success) {
                            $('#fbds-logs-tbody').html('<tr><td colspan="3" class="fbds-no-logs">No logs found.</td></tr>');
                            FileBirdDropboxSync.showNotice('Logs cleared successfully.', 'success');
                        } else {
                            FileBirdDropboxSync.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        FileBirdDropboxSync.hideLoading();
                        FileBirdDropboxSync.showNotice('An error occurred while clearing logs.', 'error');
                    }
                });
            });
        },
        
        /**
         * Initialize copy webhook URL
         */
        initCopyWebhook: function() {
            $('.fbds-copy-webhook-url').on('click', function() {
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
                $(this).html('<span class="dashicons dashicons-yes"></span>');
                setTimeout(() => {
                    $(this).html('<span class="dashicons dashicons-clipboard"></span>');
                }, 2000);
            });
        },
        
        /**
         * Initialize folder mapping interface
         */
        initMapperInterface: function() {
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
            
            // Edit mapping button
            $(document).on('click', '.fbds-edit-mapping-btn', function() {
                const mappingId = $(this).data('mapping-id');
                const mapping = fbdsMappings[mappingId];
                
                if (!mapping) {
                    return;
                }
                
                // Set form values
                $('#fbds-mapping-id').val(mappingId);
                $('#fbds-folder-select').val(mapping.folder_id);
                $('#fbds-post-select').val(mapping.post_id);
                
                // Load fields for this post
                FileBirdDropboxSync.loadFieldsForPost(mapping.post_id, mapping.field_key);
                
                // Set modal title
                $('#fbds-modal-title').text('Edit Mapping');
                
                // Show modal
                $('#fbds-mapping-modal').addClass('fbds-show');
            });
            
            // Delete mapping button
            $(document).on('click', '.fbds-delete-mapping-btn', function() {
                if (!confirm(fbds_data.texts.confirm_delete_mapping)) {
                    return;
                }
                
                const mappingId = $(this).data('mapping-id');
                const nonce = $(this).data('nonce');
                
                FileBirdDropboxSync.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_delete_mapping',
                        mapping_id: mappingId,
                        nonce: nonce
                    },
                    success: function(response) {
                        FileBirdDropboxSync.hideLoading();
                        
                        if (response.success) {
                            // Remove mapping from list
                            $(`.fbds-mapping-item[data-mapping-id="${mappingId}"]`).fadeOut(300, function() {
                                $(this).remove();
                                
                                // Show "no mappings" message if no mappings left
                                if ($('.fbds-mapping-item').length <= 1) { // Only header remains
                                    $('.fbds-no-mappings-message').show();
                                }
                            });
                            
                            FileBirdDropboxSync.showNotice(response.data.message, 'success');
                        } else {
                            FileBirdDropboxSync.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        FileBirdDropboxSync.hideLoading();
                        FileBirdDropboxSync.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
            
            // Post select change
            $('#fbds-post-select').on('change', function() {
                const postId = $(this).val();
                
                if (!postId) {
                    $('#fbds-field-select').html('<option value="">Select a post first...</option>').prop('disabled', true);
                    return;
                }
                
                FileBirdDropboxSync.loadFieldsForPost(postId);
            });
            
            // Folder select change
            $('#fbds-folder-select').on('change', function() {
                FileBirdDropboxSync.updateMappingPreview();
            });
            
            // Field select change
            $(document).on('change', '#fbds-field-select', function() {
                FileBirdDropboxSync.updateMappingPreview();
            });
        },
        
        /**
         * Load ACF fields for a post
         */
        loadFieldsForPost: function(postId, selectedField = '') {
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
                const selected = field.key === selectedField ? 'selected' : '';
                options += `<option value="${field.key}" ${selected}>${field.label || field.name}</option>`;
            });
            
            $('#fbds-field-select').html(options).prop('disabled', false);
            $('#fbds-field-loading').hide();
            
            if (selectedField) {
                FileBirdDropboxSync.updateMappingPreview();
            }
        },
        
        /**
         * Update mapping preview
         */
        updateMappingPreview: function() {
            const folderId = $('#fbds-folder-select').val();
            const fieldKey = $('#fbds-field-select').val();
            const postId = $('#fbds-post-select').val();
            
            if (!folderId || !fieldKey || !postId) {
                $('#fbds-mapping-info').hide();
                return;
            }
            
            // Show loading in the preview area
            $('#fbds-mapping-info').show();
            $('#fbds-folder-count').html('<small>Loading...</small>');
            $('#fbds-gallery-count').html('<small>Loading...</small>');
            
            $.ajax({
                url: fbds_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fbds_get_mapping_preview',
                    folder_id: folderId,
                    field_key: fieldKey,
                    post_id: postId,
                    nonce: $('#fbds-mapping-nonce').val()
                },
                success: function(response) {
                    if (response.success) {
                        $('#fbds-folder-count').text(response.data.folder_count);
                        $('#fbds-gallery-count').text(response.data.gallery_count);
                    } else {
                        $('#fbds-folder-count').text('0');
                        $('#fbds-gallery-count').text('0');
                    }
                },
                error: function() {
                    $('#fbds-folder-count').text('Error');
                    $('#fbds-gallery-count').text('Error');
                }
            });
        },
        
        /**
         * Initialize modal
         */
        initModal: function() {
            // Close modal
            $('.fbds-modal-close, .fbds-modal-cancel').on('click', function() {
                $('.fbds-modal').removeClass('fbds-show');
            });
            
            // Close modal when clicking outside
            $('.fbds-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).removeClass('fbds-show');
                }
            });
            
            // Save mapping
            $('#fbds-save-mapping-btn').on('click', function() {
                const mappingId = $('#fbds-mapping-id').val();
                const folderId = $('#fbds-folder-select').val();
                const fieldKey = $('#fbds-field-select').val();
                const postId = $('#fbds-post-select').val();
                const nonce = $('#fbds-mapping-nonce').val();
                
                if (!folderId || !fieldKey || !postId) {
                    FileBirdDropboxSync.showNotice('Please fill in all fields.', 'error');
                    return;
                }
                
                FileBirdDropboxSync.showLoading($(this));
                
                $.ajax({
                    url: fbds_data.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'fbds_save_mapping',
                        mapping_id: mappingId,
                        folder_id: folderId,
                        field_key: fieldKey,
                        post_id: postId,
                        nonce: nonce
                    },
                    success: function(response) {
                        FileBirdDropboxSync.hideLoading();
                        
                        if (response.success) {
                            // Hide modal
                            $('.fbds-modal').removeClass('fbds-show');
                            
                            // Show success message
                            FileBirdDropboxSync.showNotice(response.data.message, 'success');
                            
                            // Reload the page to show the updated mappings
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        } else {
                            FileBirdDropboxSync.showNotice(response.data.message, 'error');
                        }
                    },
                    error: function() {
                        FileBirdDropboxSync.hideLoading();
                        FileBirdDropboxSync.showNotice('An error occurred. Please try again.', 'error');
                    }
                });
            });
        },
        
        /**
         * Show loading indicator
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
        FileBirdDropboxSync.init();
    });
    
})(jQuery);