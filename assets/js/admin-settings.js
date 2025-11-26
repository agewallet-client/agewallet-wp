/**
 * JavaScript for AgeWallet OIDC Admin Settings Page.
 *
 * Handles media uploader interactions, conditional display of fields,
 * manual cache purging, and taxonomy term autocomplete with badges.
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
            // Target the closest table row (tr) to hide both the label (th) and the input (td)
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


        // --- 3. Manual Cache Purge Logic (Class-based) ---
        // Use class selector to handle both sidebar and main settings instances
        $(document).on('click', '.agewallet-purge-btn', function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to delete ALL cached HTML files? This action cannot be undone.')) {
                return;
            }

            var $btn = $(this);
            // Find relative spinner/message elements
            var $spinner = $btn.siblings('.agewallet-purge-spinner');
            var $msg = $btn.siblings('.agewallet-purge-message');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $msg.text('').removeClass('notice-error notice-success').css('color', '');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'agewallet_purge_cache',
                    // Assuming standard admin ajax security if nonce not explicitly passed
                },
                success: function(response) {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);

                    if (response.success) {
                        $msg.text(response.data).css('color', '#00a32a');
                    } else {
                        $msg.text('Error: ' + response.data).css('color', '#d63638');
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    $btn.prop('disabled', false);
                    $msg.text('Network Error. Please try again.').css('color', '#d63638');
                }
            });
        });

        // --- 4. Taxonomy Rules UI Logic (Double Inputs with Badges) ---
        $('.aw-tax-row').each(function() {
            var $row = $(this);
            var $select = $row.find('.aw-tax-mode');
            var $termsWrap = $row.find('.aw-tax-terms-wrap');
            var taxonomy = $row.data('taxonomy');

            // Toggle Visibility
            function toggleVisibility() {
                if ($select.val() === 'specific') {
                    $termsWrap.show();
                } else {
                    $termsWrap.hide();
                }
            }
            $select.on('change', toggleVisibility);
            toggleVisibility();

            // Generic Function to Initialize a Badge Input
            function initBadgeInput($group, typeClass) {
                var $input = $group.find('.aw-term-search');
                var $hidden = $group.find('input[type="hidden"]');
                var $container = $group.find('.aw-badges-container');

                function addBadge(id, label) {
                    var currentIds = $hidden.val().split(',').filter(Boolean);
                    if (currentIds.includes(id.toString())) return;

                    var $badge = $('<span class="aw-term-badge ' + typeClass + '"></span>')
                        .text(label)
                        .attr('data-id', id);
                    $badge.append('<span class="aw-remove-term dashicons dashicons-no-alt"></span>');
                    $container.append($badge);

                    currentIds.push(id);
                    $hidden.val(currentIds.join(','));
                }

                function removeBadge($badge) {
                    var idToRemove = $badge.data('id').toString();
                    var currentIds = $hidden.val().split(',').filter(Boolean);
                    var newIds = currentIds.filter(function(id) { return id !== idToRemove; });
                    $hidden.val(newIds.join(','));
                    $badge.remove();
                }

                $container.on('click', '.aw-remove-term', function() {
                    removeBadge($(this).closest('.aw-term-badge'));
                });

                $input.autocomplete({
                    source: function( request, response ) {
                        $.getJSON( agewalletAdminData.ajaxUrl, {
                            action: 'agewallet_term_search',
                            taxonomy: taxonomy,
                            term: request.term
                        }, function( data ) {
                            response( data.success ? data.data : [] );
                        });
                    },
                    minLength: 2,
                    select: function( event, ui ) {
                        addBadge(ui.item.id, ui.item.label);
                        $(this).val('');
                        return false;
                    },
                    focus: function() { return false; }
                });
            }

            // Initialize Gate Input
            initBadgeInput($row.find('.aw-input-group:has(.aw-hidden-gate)'), 'type-gate');
            // Initialize Exclude Input
            initBadgeInput($row.find('.aw-input-group:has(.aw-hidden-exclude)'), 'type-exclude');

        });

    }); // End $(document).ready()

})(jQuery);