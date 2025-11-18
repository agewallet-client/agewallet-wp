/**
 * AgeWallet Client-Side Gating Script
 *
 * Checks for the verification cookie. Handles:
 * 1. Displaying a full-screen overlay if automatic gating is active and user is unverified.
 * 2. Showing/hiding content wrapped in the [agewallet_protected] shortcode based on verification status.
 * 3. (New) Strict Mode: Redirects unverified users or fetches/injects secure content for verified users.
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
    // - isStrictMode: (Bool) If true, use Redirect/API logic.
    // - apiEndpoint: (String) URL to fetch content from.
    // - gateUrl: (String) URL to redirect unverified users to.
    // - redirectUrl: (String) Current URL to return to.

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

        // Add active class if provided (used by CSS potentially)
        if (bodyClassActive && bodyClassActive.trim() !== '') {
            document.body.classList.add(bodyClassActive);
        }
    }

    /**
     * Handles showing/hiding protected content blocks based on verification status.
     */
    function handleProtectedBlocks(userIsVerified) {
        var wrappers = document.querySelectorAll('.agewallet-protected-wrapper');
        if (wrappers.length === 0) return;

        wrappers.forEach(function(wrapper) {
            var content = wrapper.querySelector('.agewallet-protected-content');
            var placeholder = wrapper.querySelector('.agewallet-protected-placeholder');

            if (!content || !placeholder) return;

            if (userIsVerified) {
                content.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                content.style.display = 'none';
                placeholder.style.display = 'block';
            }
        });
    }

    /**
     * STRICT MODE: Fetches secure content via API and injects it.
     */
    function fetchAndInjectContent(apiEndpoint) {
        if (!apiEndpoint) {
            console.error('[AgeWallet Strict] API Endpoint missing.');
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', apiEndpoint, true);
        xhr.withCredentials = true; // Send cookies with request

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success && response.html) {
                        // The "Hard" Load: Replace the entire document with the fetched HTML.
                        // This triggers a full parse/execute of the new page content.
                        document.open();
                        document.write(response.html);
                        document.close();
                    } else {
                        console.error('[AgeWallet Strict] API returned error or empty HTML.', response);
                        document.body.innerHTML = '<p>Error loading content: ' + (response.message || 'Unknown error') + '</p>';
                    }
                } catch (e) {
                    console.error('[AgeWallet Strict] JSON Parse Error', e);
                }
            } else {
                console.error('[AgeWallet Strict] HTTP Error', xhr.statusText);
                if (xhr.status === 403) {
                     // Cookie might have expired mid-session? Redirect to gate.
                     var gateUrl = (typeof agewallet_gate_data !== 'undefined') ? agewallet_gate_data.gateUrl : '/';
                     if(gateUrl) window.location.href = gateUrl;
                }
            }
        };

        xhr.onerror = function() {
            console.error('[AgeWallet Strict] Network Error');
        };

        xhr.send();
    }


    /**
     * Main execution function. Runs after the DOM is ready.
     */
    function initGate() {

        // Check if the localized data object exists
        if (typeof agewallet_gate_data === 'undefined' || !agewallet_gate_data) {
            console.log('[AgeWallet Gate] agewallet_gate_data not found (this may be normal if using shortcode fallback).');
            var pendingClass = 'agewallet-gated-pending';
            document.body.classList.remove(pendingClass);
            document.body.style.visibility = 'visible';
            // Continue to enable click handlers if possible
        }

        // Extract data passed from PHP
        var data = (typeof agewallet_gate_data !== 'undefined') ? agewallet_gate_data : {};
        var cookieName = data.cookieName || 'agewallet_verified';
        var gateHtml = data.gateHtml || '';
        var isOverlayActive = data.isOverlayActive || false;
        var bodyClassPending = data.bodyClassPending || 'agewallet-gated-pending';

        // Strict Mode Data
        var isStrictMode = data.isStrictMode || false;
        var apiEndpoint  = data.apiEndpoint || '';
        var gateUrl      = data.gateUrl || '';
        var redirectUrl  = data.redirectUrl || window.location.href;

        // Check verification status
        var userIsVerified = isVerified(cookieName);

        // --- STRICT MODE LOGIC ---
        if (isStrictMode) {
            if (userIsVerified) {
                // Verified: Fetch content via API and Inject
                fetchAndInjectContent(apiEndpoint);
                // Note: We do NOT remove bodyClassPending here because the document.write
                // will wipe the entire page (including the body class) and replace it.
            } else {
                // Not Verified: Redirect to Gate Page
                if (gateUrl) {
                    // Construct redirect URL with return path
                    var target = gateUrl;
                    // Check if gateUrl already has query params
                    var separator = target.indexOf('?') !== -1 ? '&' : '?';
                    target += separator + 'redirect_to=' + encodeURIComponent(redirectUrl);

                    // Perform Redirect
                    window.location.replace(target);
                } else {
                    console.error('[AgeWallet Strict] Configuration Error: Age Gate Page URL not set.');
                    document.body.innerHTML = '<p style="padding:20px;text-align:center;">Configuration Error: Age Gate Page not selected in settings.</p>';
                }
            }
            return; // Stop execution, Strict Mode takes over completely.
        }
        // --- END STRICT MODE ---

        // --- STANDARD MODE LOGIC (Overlay) ---
        if (isOverlayActive) {
            if (userIsVerified) {
                document.body.classList.remove(bodyClassPending);
                document.body.style.visibility = 'visible';
            } else {
                showOverlay(gateHtml);
                document.body.classList.remove(bodyClassPending);
                document.body.style.visibility = 'visible';
            }
        } else {
             document.body.classList.remove(bodyClassPending);
             document.body.style.visibility = 'visible';
        }

        handleProtectedBlocks(userIsVerified);

        if (userIsVerified) {
            var buttonWrappers = document.querySelectorAll('.agewallet-shortcode-wrapper');
            if (buttonWrappers.length > 0) {
                 buttonWrappers.forEach(function(wrapper){
                     wrapper.style.display = 'none';
                 });
            }
        }

        // Delegated Click Handler
        document.body.addEventListener('click', function(event) {
            var agreeButton = event.target.closest('.aw-gate__btn--yes');
            if (agreeButton) {
                event.preventDefault();
                var rUrl = agreeButton.getAttribute('data-redirect-url');
                if (rUrl) {
                    window.location.href = rUrl;
                } else {
                    console.error('[AgeWallet Gate] Agree button is missing redirect URL.');
                }
            }
        });
    }

    // --- Execution ---
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGate);
    } else {
        initGate();
    }

})(); // End IIFE