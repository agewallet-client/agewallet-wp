/**
 * JavaScript for AgeWallet OIDC Admin Settings Page.
 *
 * Handles media uploader interactions, conditional display of fields,
 * and manual cache purging.
 * Relies on localized data object 'agewalletAdminData'.
 *
 * @since 0.1.0
 */
(function($) {
    'use strict';

    $(function() { // Equivalent to $(document).ready()

        // --- 1. Media Uploader Logic ---
        var mediaFrame;
        var $logoPreview = $('#' + agewalletAdminData.logoPreviewId);
        var $logoIdInput = $('#' + agewalletAdminData.logoInputId);
        var $selectButton = $('#' + agewalletAdminData.selectButtonId);
        var $removeButton = $('#' + agewalletAdminData.removeButtonId);

        // Open the media frame when the 'Select Image' button is clicked
        $selectButton.on('click', function(e) {
            e.preventDefault();

            // If the frame already exists, reopen it
            if (mediaFrame) {
                mediaFrame.open();
                return;
            }

            // Create a new media frame (the modal window)
            mediaFrame = wp.media({
                title: agewalletAdminData.mediaFrameTitle, // Use localized title
                button: {
                    text: agewalletAdminData.mediaFrameButton // Use localized button text
                },
                multiple: false // Disallow selecting multiple images
            });

            // When an image is selected in the frame, run this callback
            mediaFrame.on('select', function() {
                // Get the selected attachment object
                var attachment = mediaFrame.state().get('selection').first().toJSON();

                // Ensure we have the necessary attachment details (ID and URL)
                if (attachment && attachment.id && attachment.url) {
                    // Update the hidden input field with the attachment ID
                    $logoIdInput.val(attachment.id);
                    // Update the preview image source and make it visible
                    $logoPreview.attr('src', attachment.url).show();
                    // Show the 'Remove Image' button
                    $removeButton.show();
                }
            });

            // Open the media frame
            mediaFrame.open();
        });

        // Clear the selection when the 'Remove Image' button is clicked
        $removeButton.on('click', function(e) {
            e.preventDefault();
            // Clear the hidden input field value (set to 0 or empty)
            $logoIdInput.val('0');
            // Clear the preview image source and hide it
            $logoPreview.attr('src', '').hide();
            // Hide the 'Remove Image' button itself
            $(this).hide();
        });


        // --- 2. Conditional Display for Blocked Paths Textarea ---
        var $blockModeRadios = $('input[type="radio"][name="' + agewalletAdminData.blockModeOptionName + '"]');
        var $pathsWrapper = $('#' + agewalletAdminData.blockedPathsWrapperId);

        function toggleBlockedPathsVisibility() {
            var selectedMode = $blockModeRadios.filter(':checked').val();
            // FIX: Target the closest table row (tr) to hide both the label (th) and the input (td)
            var $row = $pathsWrapper.closest('tr');

            if (selectedMode === 'specific') {
                // Use show() instead of slideDown() for table rows to avoid layout glitches
                $row.show();
            } else {
                $row.hide();
            }
        }

        // Run immediately and on change
        toggleBlockedPathsVisibility();
        $blockModeRadios.on('change', toggleBlockedPathsVisibility);


        // --- 3. Manual Cache Purge Logic ---
        var $purgeBtn = $('#agewallet-purge-cache');
        var $purgeSpinner = $('#agewallet-purge-spinner');
        var $purgeMsg = $('#agewallet-purge-message');

        if ($purgeBtn.length) {
            $purgeBtn.on('click', function(e) {
                e.preventDefault();

                if (!confirm('Are you sure you want to delete ALL cached HTML files? This action cannot be undone.')) {
                    return;
                }

                $purgeBtn.prop('disabled', true);
                $purgeSpinner.addClass('is-active');
                $purgeMsg.text('').removeClass('notice-error notice-success').css('color', '');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'agewallet_purge_cache',
                        // Assuming standard admin ajax security if nonce not explicitly passed
                    },
                    success: function(response) {
                        $purgeSpinner.removeClass('is-active');
                        $purgeBtn.prop('disabled', false);

                        if (response.success) {
                            $purgeMsg.text(response.data).css('color', '#00a32a');
                        } else {
                            $purgeMsg.text('Error: ' + response.data).css('color', '#d63638');
                        }
                    },
                    error: function() {
                        $purgeSpinner.removeClass('is-active');
                        $purgeBtn.prop('disabled', false);
                        $purgeMsg.text('Network Error. Please try again.').css('color', '#d63638');
                    }
                });
            });
        }

    }); // End $(document).ready()

})(jQuery);