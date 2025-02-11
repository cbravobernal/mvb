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
    });
})(jQuery); 