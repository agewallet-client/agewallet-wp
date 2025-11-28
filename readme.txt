=== AgeWallet OIDC Client ===
Contributors: AgeWallet LLC
Tags: age verification, age gate, agewallet, content restriction, oidc, access control, protect content
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0-dev.1
Version: 1.1.0-dev.1
Author: AgeWallet LLC
Author URI: https://agewallet.com

Secure, customizable age verification for WordPress via the AgeWallet OIDC service.
Cache-compatible and designed for legal compliance.

== Description ==

The AgeWallet OIDC Client plugin provides a robust and secure method for adding age verification and content gating to your WordPress website.

It leverages the modern OpenID Connect (OIDC) standard and is built to be both highly flexible and compatible with all types of hosting environments, especially those with aggressive caching.

Key Features:

* Legal Compliance: Leverages the AgeWallet™ service, which is designed to comply with modern age verification laws and regulations in most major countries, helping you meet your legal obligations.
* Secure Verification Flow: Implements the recommended OIDC Authorization Code Flow with PKCE for maximum security, ensuring that user data is handled safely.
* Enhanced Cookie Security: Uses HMAC cryptographic signing to prevent cookie forgery. Verification cookies are strictly tied to the user session and mathematically verified by the server.
* Two Security Modes:
    * Standard (Overlay): A lightweight, SEO-friendly overlay that hides content via CSS and JavaScript.
    * High Security (Strict): Prevents protected content from loading entirely until verification is complete. Uses a secure "Skeleton" loading state and is compatible with "Cache Everything" page rules (Cloudflare/Varnish). However this mode will also prevent SEO crawling of protected content.
* Smart Caching Architecture: Strict mode utilizes a split-cache system (Singular vs. Archives) to ensure fast performance while allowing for immediate invalidation when content changes.
* Automated Cache Management: Includes a configurable garbage collection schedule to keep your storage footprint low, plus extended invalidation triggers for global site changes.
* Fully Customizable Gate: Match the age gate to your brand. Upload your logo, use a WYSIWYG editor for messaging, and override styles with Custom CSS.
* Flexible Content Protection Rules:
    * Full Site Protection: Protect your entire site, including or excluding the homepage.
    * Granular Taxonomy Control: Gate or exclude content based on specific Categories, Tags, or Custom Taxonomies (e.g., WooCommerce Product Categories).
    * Path-Based Protection: Automatically protect specific URL paths (e.g., `/shop/`, `/videos/premium/`).
    * Per-Post Control: Force or exclude verification on individual posts via the editor sidebar.
    * Shortcode Protection: Protect specific page elements using `[agewallet_protected]`.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/agewallet` directory, or install the plugin through the WordPress plugins screen directly by uploading the `.zip` file.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Crucial Step: Go to Settings > Permalinks and simply click "Save Changes". This ensures the custom AgeWallet verification URLs (e.g., `/agewallet/callback/`) are registered.
4. Look for the AgeWallet item in your main WordPress admin menu to launch the setup wizard.
5. After configuring your potection settings be sure to clear any server, plugin or CloudFlare caches.

== Usage Guide ==

This guide explains how to configure and use the plugin using the new setup wizard.

= 1. Initial Configuration (Step 1) =

Before the plugin can work, you must connect it to your AgeWallet account.

1. Click AgeWallet in your WordPress admin menu to see the Welcome screen.
2. Click on Step 1: API Credentials.
3. In the "API Configuration" section, you will see a field labeled Redirect URI. Copy this URL.
4. Log in to your AgeWallet business account dashboard.
5. Create a new application and paste the Redirect URI from the plugin settings into the corresponding field in your AgeWallet dashboard.
6. AgeWallet will provide you with a Client ID and a Client Secret.
7. Copy these keys and paste them into the "Client ID" and "Client Secret" fields in the plugin settings.
8. Click "Save Changes", then click "Next: Content Guarding".

= 2. Setting up Protection Rules (Step 2) =

You have several ways to protect content. You can mix and match these methods on the Step 2: Content Guarding page.

A. Security Mode

* Standard (Overlay): The page loads normally, but a CSS overlay covers the content. Verified users see the content revealed instantly. Best for SEO.
* High Security (Strict): The server sends a generic "Skeleton" page instead of your content. Secure content is fetched via an API call only after the user is verified. This prevents bypassing the gate by disabling JavaScript.

B. Global Scope

* No automatic protection: Only protects content you specify manually (via Paths, Per-Post settings, or Shortcodes).
* Protect entire site, except homepage: Gates every page and post except your front page.
* Protect entire site, including homepage: Gates every single page on your site.

C. Taxonomy Rules

You can define rules based on Categories, Tags, or Custom Taxonomies (like WooCommerce Product Categories).
* Gate All Terms: Gates every post belonging to that taxonomy.
* Exclude All Terms: Ensures posts in that taxonomy are always public.
* Specific Rules: Search for specific terms (e.g., "Premium Content" category) to Gate or Exclude individually. Note: Exclusion rules take priority over Gating rules.

D. Path-Based Rules

* Paths to Protect: (Only active if "Specific URL paths" is selected above). Enter comma-separated paths. Any URL containing these paths will be gated.
    * Example: `/shop/, /videos/` will protect `example.com/shop/`, `example.com/shop/product-1/`, and `example.com/videos/my-video/`.
* Paths to Exclude: Enter paths that should always be public, even if 'Protect entire site' is on.
    * Example: `/privacy-policy/, /terms/`.

= 3. Customizing the Gate Appearance (Step 3) =

You can customize the age gate to match your brand on the Step 3: Gate Appearance page.

* Gate Logo: Upload or select a logo from your Media Library.
* Logo Width (px): Set a specific width for your logo, or leave at 0 for natural size.
* Gate Copy: Use the text editor to change the main message your users see.
* Hide Default Heading: Check this box to remove the "You Must Verify Your Age" title (e.g., if your custom copy already includes a title).
* Custom CSS: Enter custom CSS here to override styles for the Gate or the Strict Mode Skeleton screen (e.g., `.aw-gate__btn--yes { background: red; }`).

= 4. Advanced Scripts (Step 4) =

Note: These settings only apply if you are using High Security (Strict) Mode.

Because Strict Mode prevents your theme from loading during the verification check, your theme's header/footer scripts (like Analytics) won't run on the "Verifying..." screen.
Use these fields to add essential scripts back in:

* Header Scripts: Output in the &lt;head&gt; section (e.g., Google Analytics tag).
* Footer Scripts: Output before the closing &lt;/body&gt; tag.

= 5. Per-Post / Per-Page Control =

On the Edit Post or Edit Page screen, you will see an "Age Restriction" box in the sidebar. These settings override all global rules.

* Require age verification: Check this to force the gate on this single post, even if your global setting is "No automatic protection."
* Exclude from age verification: Check this to make a post public, even if it's in a protected path (like `/shop/`) or a protected Category.

= 6. Shortcode Protection =

To protect just one part of a post (like a single video or paragraph), wrap it in the `[agewallet_protected]` shortcode.

`[agewallet_protected]`
This content, and only this content, will be hidden until the user verifies their age.
`[/agewallet_protected]`

Note: Shortcode protection only works in Standard Mode. In Strict Mode, the shortcode will NOT work.

= 7. Caching & Maintenance =

If you use High Security Mode, the plugin generates static HTML caches of your protected pages to ensure speed.

* Automatic Management: The cache is automatically cleared when you update posts, switch themes, create/edit menus, or modify taxonomy terms.
* Scheduled Cleanup: You can configure an automatic cache purge schedule (default: every 4 hours) in the "Cache Control" settings tab.
* Manual Purge: If you change settings and don't see them update immediately, click the "Purge Cache" button available in the sidebar of any AgeWallet settings page (or under Cache Control).

== CSS Customization Guide ==

Use this guide to customize the appearance of the AgeWallet™ age gate and the Strict Mode loading screen.
You can enter these overrides in Step 3: Gate Appearance > Custom CSS or add them to your theme's stylesheet.
All classes are prefixed with `.aw-gate__` for easy targeting.

= 1. Overlay & Layout =

* `.aw-gate__overlay` - The full-screen overlay background.
Controls background color, opacity, and positioning of the age gate.

* `.aw-gate__card` - The main container (card) holding all gate content.
Controls background color, border radius, padding, and max-width.

= 2. Loading Screen (Strict Mode) =

* `.aw-skeleton-card` - The card container specifically during the "Verifying..." loading state.
Useful for adjusting the minimum height or layout during the initial check.

* `.aw-spinner` - The loading spinner animation.
Controls the size, border color, and speed of the spinner.

* `body.agewallet-strict-loading` - The class applied to the body while the skeleton is active.
Useful for hiding other page elements while verification is in progress.

= 3. Logo Area =

* `.aw-gate__logo-wrap` - Wrapper for the AgeWallet logo area.
Useful for centering or adjusting logo spacing.

* `.aw-gate__logo` - The logo image itself.
Controls image size, margin, and alignment.

= 4. Text Content =

* `.aw-gate__title` - The headline text ("You Must Verify Your Age").
Controls font size, color, weight, and margin.

* `.aw-gate__desc` - The main description paragraph under the title.
Controls text color, font size, and line height.

= 5. Buttons =

* `.aw-gate__buttons` - Container for the "I Agree" and "I Disagree" buttons.
Use this to control button spacing or layout (flex/grid, gap, alignment).

* `.aw-gate__btn--yes` - "I Agree" button.
Controls background color, hover state, border, and text color.

* `.aw-gate__btn--no` - "I Disagree" button.
Controls background color, hover state, border, and text color.

= 6. Error & Disclaimer =

* `.aw-gate__error` - Error message shown when the user doesn't meet requirements.
Controls text color, font size, and margin.

* `.aw-gate__disclaimer` - Disclaimer or fine print text at the bottom.
Controls text color, font size, and spacing.

= 7. Shortcode Wrappers =

* `.agewallet-protected-wrapper` - The main container for protected shortcode content.
Useful for adding margins.

* `.agewallet-protected-placeholder` - The placeholder box shown to unverified users, contains the gate prompt.
Controls borders, background, and padding.

= Customization Tips =

To override styles safely, add your CSS in the plugin settings ("Gate Appearance" tab).
For example:

`
.aw-gate__btn--yes {
    background-color: #28a745;
    color: #fff;
}
`

You can also enqueue a custom CSS file using:

`wp_enqueue_style('agewallet-custom', get_stylesheet_directory_uri() . '/agewallet-custom.css');`

Use your browser's developer tools (Inspect Element) to preview your changes live.

== Developer Hooks ==

This plugin includes a number of action and filter hooks to allow for advanced customization and integration.

= Main Plugin File =
* `agewallet_dependency_files` (filter) - Modify the array of core class files to be loaded.
* `agewallet_initialized` (action) - Fires after all plugin classes have been instantiated.
* `agewallet_activated` (action) - Fires when the plugin is activated.
* `agewallet_deactivated` (action) - Fires when the plugin is deactivated.

= Admin Settings (class-agewallet-admin.php) =
* `agewallet_admin_menu_capability` (filter) - Change the user capability required to access the settings page.
* `agewallet_register_settings` (action) - Add custom settings sections and fields to the admin page.
* `agewallet_before_settings_fields` (action) - Add custom content before the main settings form fields.
* `agewallet_after_settings_fields` (action) - Add custom content after the main settings form fields.

= Helpers (class-agewallet-helpers.php) =
* `agewallet_hmac_secret` (filter) - Override the HMAC secret retrieved from the database.
* `agewallet_redirect_uri` (filter) - Modify the `/agewallet/callback/` URL.
* `agewallet_success_url` (filter) - Modify the `/agewallet/success/` URL.
* `agewallet_launch_url` (filter) - Modify the `/agewallet/launch/` URL.
* `agewallet_cookie_path` (filter) - Modify the path used for the verification cookie.
* `agewallet_current_url` (filter) - Override the auto-detected current URL.
* `agewallet_cookie_payload` (filter) - Modify the data array stored inside the signed cookie before it is signed.
* `agewallet_cookie_validation_error` (action) - Fires when a cookie fails HMAC validation (args: error_type, cookie_value).

= OIDC Handler (class-agewallet-oidc-handler.php) =
* `agewallet_state_transient_expiration` (filter) - Change the expiration time for the OIDC session transient.
* `agewallet_auth_request_params` (filter) - Modify the parameters sent in the authorization request to AgeWallet.
* `agewallet_oidc_error` (action) - Fires when an error is returned from the AgeWallet callback.
* `agewallet_token_request_args` (filter) - Modify the arguments for the server-to-server token exchange request.
* `agewallet_verification_success` (action) - Fires immediately after a user's age is successfully verified.
* `agewallet_verified_cookie_attributes` (filter) - Modify the attributes (path, domain, expires, etc.) of the verification cookie.
* `agewallet_final_redirect_url` (filter) - Modify the final URL the user is redirected to after successful verification.
* `agewallet_before_render_success_page` (action) - Fires before the success page HTML is rendered, allowing for a complete override.

= Gating Manager (class-agewallet-gating-manager.php) =
* `agewallet_should_gate_request` (filter) - Override the final boolean decision on whether to gate the current page.
* `agewallet_gate_script_data` (filter) - Modify the data array passed to the front-end `gate.js` script.
* `agewallet_gate_template_args` (filter) - Modify the array of data used to build the age gate HTML.
* `agewallet_after_gate_buttons` (action) - Add custom HTML content after the Agree/Disagree buttons on the gate.
* `agewallet_meta_box_save` (action) - Fires when the age restriction setting is saved for a post or page.
* `agewallet_meta_box_post_types` (filter) - Control which post types show the Age Restriction meta box.
* `agewallet_template_include_priority` (filter) - Adjust the priority of the strict mode template interception (default: 99).
* `agewallet_taxonomy_rules` (filter) - Modify the loaded array of taxonomy blocking rules before they are evaluated.

= Strict Mode & Caching (class-agewallet-api.php & gatekeeper.php) =
* `agewallet_skeleton_template` (filter) - Replace the `gatekeeper.php` template file entirely.
* `agewallet_skeleton_head` (action) - Output custom tags in the &lt;head&gt; of the skeleton screen.
* `agewallet_skeleton_content_after` (action) - Output content below the spinner on the loading screen.
* `agewallet_skeleton_body_classes` (filter) - Add custom classes to the skeleton &lt;body&gt;.
* `agewallet_skeleton_footer` (action) - Output custom tags before the closing &lt;/body&gt; tag.
* `agewallet_cache_directory` (filter) - Change the physical path where HTML caches are stored.
* `agewallet_loopback_url` (filter) - Modify the URL used by the cache builder (useful for specific proxy setups).
* `agewallet_loopback_request_args` (filter) - Modify HTTP args (timeout, headers) for the cache build request.
* `agewallet_api_content_response` (filter) - Modify the HTML content string before it is returned by the API to the frontend.
* `agewallet_is_user_verified` (filter) - Master boolean override for server-side verification checks.
* `agewallet_cache_invalidation_events` (filter) - Modify the list of WP actions that trigger a global cache purge.
* `agewallet_before_cache_purge` (action) - Fires immediately before cache files are deleted (args: type, count/id).
* `agewallet_after_cache_purge` (action) - Fires immediately after cache files are deleted.

== Changelog ==

= 0.1.7 =
* Tweak: Standardized all front-end CSS class names for better consistency and to prevent theme conflicts.
* Tweak: Converted the "I Agree" link into a &lt;button&gt; element for better styling compatibility across themes.
* Feature: Added numerous developer hooks and filters for improved extensibility.
* Security: Hardened client-side script by adding click handlers dynamically instead of using inline attributes.

= 1.0.0 =
* Feature: Added an in-dashboard "Plugin Guide" page that displays the plugin's readme file.

= 1.0.1 =
* CSS and Documentation tweaks.

= 1.2.0 =
* Patched 2 bugs preventing shortcode from working properly in some environments.

= 1.3.0
* Architecture: Implemented High Security (Strict) Mode with API-based content retrieval.
* Architecture: Added Split-Cache system (Singular/Archive) for intelligent invalidation.
* Compatibility: Switched verification triggers to POST requests to bypass aggressive host caching (WP Engine, etc).
* UX: Refactored Admin Settings into a multi-step Wizard.
* Feature: Added Manual Cache Purge tool.
* Security: Implemented HMAC cryptographic signing for verification cookies to prevent forgery.
* Feature: Added granular Taxonomy Gating (support for Categories, Tags, and Custom Taxonomies).
* Feature: Added "Cache Control" settings with configurable auto-purge schedule (WP-Cron).
* Feature: Extended automatic cache invalidation to include Menu, Theme, and Term updates.
* Dev: Added multiple new hooks for deep customization of cookies, caching, and taxonomy rules.

== Upgrade Notice ==

= 1.3.0 =
This update includes significant security enhancements (signed cookies) and granular gating controls. Please clear your browser cookies after updating to test the new verification flow.