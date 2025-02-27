/**
 * FileBird Dropbox Sync Pro - Folder Mapper JavaScript
 */
(function($) {
    'use strict';
    
    // Folder Mapper functionality
    const FolderMapper = {
        init: function() {
            this.initMappingForm();
            this.loadStoredMappings();
            this.bindEvents();
        },
        
        /**
         * Initialize mapping form
         */
        initMappingForm: function() {
            // Set up select2 for better select box UX if available
            if ($.fn.select2) {
                $('#fbds-folder-select, #fbds-post-select, #fbds-field-select').select2({
                    width: '100%',
                    dropdownParent: $('#fbds-mapping-modal')
                });
            }
        },
        
        /**
         * Load stored mappings
         */
        loadStoredMappings: function() {
            if (typeof fbdsMappings === 'undefined' || !fbdsMappings.length) {
                $('.fbds-no-mappings-message').show();
                return;
            }
            
            $('.fbds-no-mappings-message').hide();
        },
        
        /**
         * Bind events
         */
        bindEvents: function() {
            // Handle post selection change - load available ACF fields
            $('#fbds-post-select').on('change', function() {
                const postId = $(this).val();
                
                if (!postId) {
                    $('#fbds-field-select').html('<option value="">Select a post first...</option>').prop('disabled', true);
                    $('#fbds-mapping-info').hide();
                    return;
                }
                
                FolderMapper.loadACFFields(postId);
            });
            
            // Handle folder and field selection change - update preview
            $('#fbds-folder-select, #fbds-field-select').on('change', function() {
                FolderMapper.updateMappingPreview();
            });
            
            // Save mapping
            $('#fbds-save-mapping-btn').on('click', function() {
                FolderMapper.saveMapping();
            });
            
            // Add mapping button
            $('.fbds-add-mapping-btn').on('click', function() {
                FolderMapper.resetForm();
                $('#fbds-mapping-modal').addClass('fbds-show');
            });
            
            // Edit mapping button
            $(document).on('click', '.fbds-edit-mapping-btn', function() {
                const mappingId = $(this).data('mapping-id');
                FolderMapper.editMapping(mappingId);
            });
            
            // Delete mapping button
            $(document).on('click', '.fbds-delete-mapping-btn', function() {
                const mappingId = $(this).data('mapping-id');
                const nonce = $(this).data('nonce');
                FolderMapper.deleteMapping(mappingId, nonce);
            });
            
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
        },
        
        /**
         * Load ACF fields for a post
         * 
         * @param {number} postId The post ID
         * @param {string} selectedField Optional selected field key
         */
        loadACFFields: function(postId, selectedField = '') {
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
                FolderMapper.updateMappingPreview();
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
         * Save mapping
         */
        saveMapping: function() {
            const mappingId = $('#fbds-mapping-id').val();
            const folderId = $('#fbds-folder-select').val();
            const fieldKey = $('#fbds-field-select').val();
            const postId = $('#fbds-post-select').val();
            const nonce = $('#fbds-mapping-nonce').val();
            
            if (!folderId || !fieldKey || !postId) {
                this.showNotice('Please fill in all fields.', 'error');
                return;
            }
            
            this.showLoading($('#fbds-save-mapping-btn'));
            
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
                    FolderMapper.hideLoading();
                    
                    if (response.success) {
                        // Hide modal
                        $('.fbds-modal').removeClass('fbds-show');
                        
                        // Show success message
                        FolderMapper.showNotice(response.data.message, 'success');
                        
                        // Reload the page to show the updated mappings
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        FolderMapper.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    FolderMapper.hideLoading();
                    FolderMapper.showNotice('An error occurred. Please try again.', 'error');
                }
            });
        },
        
        /**
         * Edit mapping
         * 
         * @param {number} mappingId The mapping ID
         */
        editMapping: function(mappingId) {
            const mapping = fbdsMappings[mappingId];
            
            if (!mapping) {
                return;
            }
            
            // Set form values
            $('#fbds-mapping-id').val(mappingId);
            $('#fbds-folder-select').val(mapping.folder_id);
            $('#fbds-post-select').val(mapping.post_id);
            
            // Trigger select2 update if available
            if ($.fn.select2) {
                $('#fbds-folder-select, #fbds-post-select').trigger('change.select2');
            } else {
                $('#fbds-post-select').trigger('change');
            }
            
            // Load fields for this post
            this.loadACFFields(mapping.post_id, mapping.field_key);
            
            // Set modal title
            $('#fbds-modal-title').text('Edit Mapping');
            
            // Show modal
            $('#fbds-mapping-modal').addClass('fbds-show');
        },
        
        /**
         * Delete mapping
         * 
         * @param {number} mappingId The mapping ID
         * @param {string} nonce The security nonce
         */
        deleteMapping: function(mappingId, nonce) {
            if (!confirm(fbds_data.texts.confirm_delete_mapping)) {
                return;
            }
            
            this.showLoading($(`.fbds-delete-mapping-btn[data-mapping-id="${mappingId}"]`));
            
            $.ajax({
                url: fbds_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fbds_delete_mapping',
                    mapping_id: mappingId,
                    nonce: nonce
                },
                success: function(response) {
                    FolderMapper.hideLoading();
                    
                    if (response.success) {
                        // Remove mapping from list
                        $(`.fbds-mapping-item[data-mapping-id="${mappingId}"]`).fadeOut(300, function() {
                            $(this).remove();
                            
                            // Show "no mappings" message if no mappings left
                            if ($('.fbds-mapping-item').length <= 1) { // Only header remains
                                $('.fbds-no-mappings-message').show();
                            }
                        });
                        
                        FolderMapper.showNotice(response.data.message, 'success');
                    } else {
                        FolderMapper.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    FolderMapper.hideLoading();
                    FolderMapper.showNotice('An error occurred. Please try again.', 'error');
                }
            });
        },
        
        /**
         * Reset form
         */
        resetForm: function() {
            $('#fbds-mapping-form')[0].reset();
            $('#fbds-mapping-id').val('');
            $('#fbds-field-select').html('<option value="">Select a post first...</option>').prop('disabled', true);
            $('#fbds-mapping-info').hide();
            
            // Reset select2 if available
            if ($.fn.select2) {
                $('#fbds-folder-select, #fbds-post-select, #fbds-field-select').val(null).trigger('change.select2');
            }
            
            // Set modal title
            $('#fbds-modal-title').text('Add New Mapping');
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
        FolderMapper.init();
    });
    
})(jQuery);