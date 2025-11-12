/**
 * AgeWallet Client-Side Gating Script
 *
 * Checks for the verification cookie. Handles:
 * 1. Displaying a full-screen overlay if automatic gating is active and user is unverified.
 * 2. Showing/hiding content wrapped in the [agewallet_protected] shortcode based on verification status.
 *
 * @since 0.1.0
 */
(function() {
    'use strict';

    // Data passed from PHP via wp_localize_script('agewallet_gate_data')
    // Expected properties:
    // - cookieName: Name of the verification cookie (string)
    // - gateHtml: HTML for the overlay gate (string, may be empty)
    // - isOverlayActive: Flag indicating if automatic overlay gating is triggered (boolean)
    // - bodyClassPending: CSS class added to body initially (string)

    /**
     * Function to check if the verification cookie exists.
     * @param {string} cookieName The name of the cookie to check.
     * @returns {boolean} True if the cookie exists with value '1', false otherwise.
     */
    function isVerified(cookieName) {
        if (!cookieName) {
            console.error('[AgeWallet Gate] Cookie name is missing.');
            return false; // Cannot check without a name
        }
        // Simple check for 'cookieName=1' somewhere in the cookies
        var cookieValue = cookieName + '=1';
        // Check using indexOf which is widely compatible
        var exists = document.cookie.indexOf(cookieValue) > -1;

        // More robust check to avoid partial matches (e.g., cookie 'other_verified=1')
        // Check for '; cookieName=1' or 'cookieName=1' at the start
        var parts = document.cookie.split('; ');
        for (var i = 0; i < parts.length; i++) {
            if (parts[i] === cookieValue) {
                exists = true;
                break;
            }
        }

        return exists;
    }

    /**
     * Creates and injects the overlay into the DOM.
     * @param {string} gateHtml The HTML markup for the gate prompt.
     * @param {string} bodyClassActive CSS class to add to body when overlay is active.
     */
    function showOverlay(gateHtml, bodyClassActive) {
        if (!gateHtml) {
            console.error('[AgeWallet Gate] Gate HTML is missing. Cannot display overlay.');
            // Fallback: Display a simple message in the body
             try { // Wrap in try/catch in case body modification fails
                 document.body.innerHTML = '<p style="padding:2em; text-align:center; font-family: sans-serif; color: red;">Age verification is required, but the gate prompt could not be loaded.</p>';
             } catch(e) { console.error("Failed to show fallback message.", e); }
            return;
        }

        // Create the overlay container div
        var overlay = document.createElement('div');
        overlay.id = 'agewallet-gate-overlay';
        overlay.className = 'aw-gate__overlay'; // Basic class for positioning/styling from CSS
        // Inline styles serve as fallbacks if CSS doesn't load, but prefer CSS rules.
        // Styles moved mostly to CSS file for better maintenance. Z-index is critical here.
        overlay.style.zIndex = '999999';

        // Set the inner HTML to the gate markup provided by PHP
        try {
             overlay.innerHTML = gateHtml;
        } catch(e) {
             console.error("Error setting overlay innerHTML", e);
             overlay.innerHTML = '<p style="color:red;">Error loading gate content.</p>';
        }


        // Append the overlay to the body
        document.body.appendChild(overlay);

        // Add click handler for the new 'Agree' button
        var agreeButton = overlay.querySelector('.aw-gate__btn--yes');
        if (agreeButton) {
            agreeButton.addEventListener('click', function(event) {
                event.preventDefault();
                var redirectUrl = this.getAttribute('data-redirect-url');
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                } else {
                    console.error('[AgeWallet Gate] Agree button is missing redirect URL.');
                }
            });
        }

        // Add active class if provided (used by CSS potentially)
        if (bodyClassActive && bodyClassActive.trim() !== '') {
            document.body.classList.add(bodyClassActive);
        }
    }

    /**
     * --- Handles showing/hiding protected content blocks based on verification status ---
     * Finds elements matching '.agewallet-protected-wrapper' and adjusts display
     * of '.agewallet-protected-content' and '.agewallet-protected-placeholder' children.
     * @param {boolean} userIsVerified True if the verification cookie is present.
     */
    function handleProtectedBlocks(userIsVerified) {

        var wrappers = document.querySelectorAll('.agewallet-protected-wrapper');

        if (wrappers.length === 0) {
            return; // No blocks to process
        }

        wrappers.forEach(function(wrapper) {
            var content = wrapper.querySelector('.agewallet-protected-content');
            var placeholder = wrapper.querySelector('.agewallet-protected-placeholder');

            if (!content || !placeholder) {
                console.warn('[AgeWallet Gate] Protected block wrapper is missing content or placeholder element.', wrapper);
                return; // Skip this block if structure is wrong
            }

            if (userIsVerified) {
                // User IS verified: Show content, hide placeholder
                content.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                // User is NOT verified: Hide content, show placeholder (should be default state)
                content.style.display = 'none';
                placeholder.style.display = 'block';
            }
        });
    }


    /**
     * Main execution function. Runs after the DOM is ready.
     */
    function initGate() {

        // Check if the localized data object exists
        if (typeof agewallet_gate_data === 'undefined' || !agewallet_gate_data) {
            console.error('[AgeWallet Gate] Localization data (agewallet_gate_data) not found or invalid. Gating cannot proceed reliably.');
            // Try to reveal the body anyway to prevent a permanently hidden page if PHP failed.
            var pendingClass = 'agewallet-gated-pending'; // Use default as fallback
            document.body.classList.remove(pendingClass);
            document.body.style.visibility = 'visible';
            return;
        }

        // Extract data passed from PHP
        var cookieName = agewallet_gate_data.cookieName;
        var gateHtml = agewallet_gate_data.gateHtml; // HTML for overlay, if active
        var isOverlayActive = agewallet_gate_data.isOverlayActive || false; // Should the overlay be shown?
        var bodyClassPending = agewallet_gate_data.bodyClassPending || 'agewallet-gated-pending';
        // var bodyClassActive = agewallet_gate_data.bodyClassActive || 'agewallet-overlay-active'; // Not currently used by JS

        // Check verification status
        var userIsVerified = isVerified(cookieName);

        // --- Handle Automatic Overlay ---
        if (isOverlayActive) {
            if (userIsVerified) {
                // Verified: Remove the pending/hiding class and ensure body is visible
                document.body.classList.remove(bodyClassPending);
                document.body.style.visibility = 'visible'; // Ensure visibility
            } else {
                // Not verified: Show the overlay
                showOverlay(gateHtml); // bodyClassActive is handled by CSS via bodyClassPending removal
                // Remove the pending class now that the overlay is active
                document.body.classList.remove(bodyClassPending);
                document.body.style.visibility = 'visible'; // Ensure visibility (overlay will cover it)
            }
        } else {
             // No automatic overlay required, ensure content is visible
             document.body.classList.remove(bodyClassPending);
             document.body.style.visibility = 'visible';
        }

        // --- Handle Protected Content Blocks ---
        handleProtectedBlocks(userIsVerified);

        // --- Handle Hiding [agewallet_button] if verified ---
        // TODO: Implement logic to find '.agewallet-shortcode-wrapper' and hide if userIsVerified is true
        if (userIsVerified) {
            var buttonWrappers = document.querySelectorAll('.agewallet-shortcode-wrapper');
            if (buttonWrappers.length > 0) {
                 buttonWrappers.forEach(function(wrapper){
                     wrapper.style.display = 'none';
                 });
            }
        }
    }

    // --- Execution ---
    // Wait for the DOM to be fully loaded before executing the script
    if (document.readyState === 'loading') {
        // Loading hasn't finished yet
        document.addEventListener('DOMContentLoaded', initGate);
    } else {
        // `DOMContentLoaded` has already fired
        initGate();
    }

})(); // End IIFE