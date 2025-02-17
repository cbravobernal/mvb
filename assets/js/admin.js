(function($) {
    'use strict';

    $(document).ready(function() {
        $('#mvb-test-connection').on('click', function() {
            const $button = $(this);
            const $result = $('#mvb-connection-result');

            $button.prop('disabled', true);
            $result.html('<span class="spinner is-active"></span> Testing connection...');

            $.ajax({
                url: MVBAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mvb_test_igdb_connection',
                    nonce: MVBAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    } else {
                        $result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    $result.html('<div class="notice notice-error"><p>Connection failed. Please try again.</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Handle company sync
        $('#mvb-sync-companies').on('click', function() {
            const button = $(this);
            const resultDiv = $('#mvb-sync-result');

            button.prop('disabled', true);
            button.text(MVBAdmin.i18n.syncing || 'Syncing...');
            resultDiv.html('<div class="notice notice-info"><p>' + (MVBAdmin.i18n.syncStarted || 'Starting sync...') + '</p></div>');

            $.ajax({
                url: MVBAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mvb_sync_companies',
                    nonce: MVBAdmin.nonce
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', MVBAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        
                        // Show errors if any
                        if (response.data.details.errors.length > 0) {
                            let errorHtml = '<div class="notice notice-warning"><p>' + 
                                (MVBAdmin.i18n.syncErrors || 'Sync completed with errors:') + '</p><ul>';
                            response.data.details.errors.forEach(function(error) {
                                errorHtml += '<li>' + error + '</li>';
                            });
                            errorHtml += '</ul></div>';
                            resultDiv.append(errorHtml);
                        }
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    resultDiv.html('<div class="notice notice-error"><p>' + 
                        (MVBAdmin.i18n.syncError || 'Error during sync. Please try again.') + 
                        ' (' + status + ')</p></div>');
                },
                complete: function() {
                    button.prop('disabled', false);
                    button.text(MVBAdmin.i18n.syncCompanies || 'Sync Companies');
                }
            });
        });

        // Add platform sync handler
        $('#mvb-sync-platforms').on('click', function() {
            const button = $(this);
            const resultDiv = $('#mvb-platforms-result');

            button.prop('disabled', true);
            button.text(MVBAdmin.i18n.syncing || 'Syncing...');
            resultDiv.html('<div class="notice notice-info"><p>' + (MVBAdmin.i18n.syncStarted || 'Starting sync...') + '</p></div>');

            $.ajax({
                url: MVBAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mvb_sync_platforms',
                    nonce: MVBAdmin.nonce
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', MVBAdmin.nonce);
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        
                        // Show errors if any
                        if (response.data.details.errors.length > 0) {
                            let errorHtml = '<div class="notice notice-warning"><p>' + 
                                (MVBAdmin.i18n.syncErrors || 'Sync completed with errors:') + '</p><ul>';
                            response.data.details.errors.forEach(function(error) {
                                errorHtml += '<li>' + error + '</li>';
                            });
                            errorHtml += '</ul></div>';
                            resultDiv.append(errorHtml);
                        }
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    resultDiv.html('<div class="notice notice-error"><p>' + 
                        (MVBAdmin.i18n.syncError || 'Error during sync. Please try again.') + 
                        ' (' + status + ')</p></div>');
                },
                complete: function() {
                    button.prop('disabled', false);
                    button.text(MVBAdmin.i18n.syncPlatforms || 'Sync Platforms');
                }
            });
        });
    });
})(jQuery); 