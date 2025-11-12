/**
 * JavaScript for AgeWallet OIDC Admin Settings Page.
 *
 * Handles media uploader interactions and conditional display of fields.
 * Relies on localized data object 'agewalletAdminData'.
 *
 * @since 0.1.0
 */
(function($) {
    'use strict';

    $(function() { // Equivalent to $(document).ready()

        // --- Media Uploader Logic ---
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


        // --- Conditional Display for Blocked Paths Textarea ---
        // Find the radio buttons for block mode using the name attribute passed from PHP
        var $blockModeRadios = $('input[type="radio"][name="' + agewalletAdminData.blockModeOptionName + '"]');
        // Find the wrapper div for the textarea using the ID passed from PHP
        var $pathsWrapper = $('#' + agewalletAdminData.blockedPathsWrapperId);

        // Function to toggle the visibility of the textarea wrapper
        function toggleBlockedPathsVisibility() {
            // Get the value of the currently checked radio button
            var selectedMode = $blockModeRadios.filter(':checked').val();

            // Check if the selected mode is 'specific'
            if (selectedMode === 'specific') {
                $pathsWrapper.slideDown(200); // Show with a smooth animation
            } else {
                $pathsWrapper.slideUp(200); // Hide with a smooth animation
            }
        }

        // Run the toggle function immediately on page load to set the initial state
        toggleBlockedPathsVisibility();

        // Add an event listener to run the toggle function whenever a radio button's state changes
        $blockModeRadios.on('change', toggleBlockedPathsVisibility);

    }); // End $(document).ready()

})(jQuery); // Pass jQuery to the closure
