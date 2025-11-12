=== AgeWallet OIDC Client ===
Contributors: AgeWallet LLC
Tags: age verification, age gate, agewallet, content restriction, oidc, access control, protect content
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
Version: 1.0.0
Author: AgeWallet LLC
Author URI: https://agewallet.com
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure, customizable age verification for WordPress via the AgeWallet OIDC service.
Cache-compatible and designed for legal compliance.

== Description ==

The AgeWallet OIDC Client plugin provides a robust and secure method for adding age verification and content gating to your WordPress website.
It leverages the modern OpenID Connect (OIDC) standard and is built to be both highly flexible and compatible with all types of hosting environments, especially those with aggressive caching.
**Key Features:**

* **Legal Compliance:** Leverages the AgeWallet™ service, which is designed to comply with modern age verification laws and regulations in most major countries, helping you meet your legal obligations.
* **Secure Verification Flow:** Implements the recommended OIDC Authorization Code Flow with PKCE for maximum security, ensuring that user data is handled safely.
* **Cache Compatibility:** The age check is performed client-side, which means it works perfectly with caching plugins (like WP Rocket, W3 Total Cache), server-side caching, and CDNs.
Your site stays fast while remaining protected.
* **Fully Customizable Gate:** Match the age gate to your brand.
You can easily upload your own logo and use a full WYSIWYG editor to customize all of the gate's messaging.
* **Flexible Content Protection Rules:** You have complete control over what content is protected.
* **Full Site Protection:** Protect your entire site, including or excluding the homepage, with a single setting.
* **Path-Based Protection:** Automatically protect specific sections of your site by entering URL paths (e.g., `/shop/`, `/videos/premium/`).
* **Manual Protection (Shortcode):** Protect specific paragraphs, images, or videos within any post or page by wrapping them in the `[agewallet_protected]…[/agewallet_protected]` shortcode.
* **Per-Post / Per-Page Control:** For ultimate control, use the "Age Restriction" box in the editor for any post, page, or custom post type.
You can **force verification** for a single item or **exclude** an item from verification, overriding any global rules.
== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/agewallet-oidc-client` directory, or install the plugin through the WordPress plugins screen directly by uploading the `.zip` file.
2.  Activate the plugin through the 'Plugins' screen in WordPress.
3.  Go to **Settings > Permalinks** and simply click "Save Changes".
This is a crucial step to ensure the custom AgeWallet verification URLs (e.g., `/agewallet/callback/`) are working correctly.
4.  Go to **Settings > AgeWallet OIDC** in your WordPress admin menu to begin configuration.
5.  If you are using any caching plugins (WP Rocket, etc.) or server-side caching, **clear all caches** after saving your settings.
== Usage Guide ==

This guide explains how to configure and use the plugin after installation.
= 1. Initial Configuration =

Before the plugin can work, you must connect it to your AgeWallet account.
1.  Go to **AgeWallet > Settings** in your WordPress admin menu.
2.  In the "API Credentials" section, you will see a field labeled **Redirect URI**. Copy this URL.
3.  Log in to your AgeWallet business account dashboard.
4.  Create a new application and paste the **Redirect URI** from step 2 into the corresponding field in your AgeWallet settings.
5.  AgeWallet will provide you with a **Client ID** and a **Client Secret**.
6.  Copy these keys and paste them into the "Client ID" and "Client Secret" fields in the plugin's settings page in WordPress.
7.  Click "Save Changes".

= 2. Customizing the Gate Appearance =

You can customize the age gate to match your brand.
* **Gate Logo:** Upload or select a logo from your Media Library.
* **Logo Width (px):** Set a specific width for your logo, or leave at 0 for natural size.
* **Gate Copy:** Use the text editor to change the main message your users see.
* **Hide Default Heading:** Check this box to remove the "You Must Verify Your Age" title (e.g., if your custom copy already includes a title).
* **Custom CSS:** For more advanced styling, use the **AgeWallet > CSS Guide** page for a full list of classes you can target.
= 3. Choosing Your Protection Method =

You have four ways to protect content. You can mix and match these methods.
= A. Global Protection (Site-Wide) =
This is the most common method.
Go to **AgeWallet > Settings** and find the "Content Guarding Rules" section.
* **No automatic protection:** Only protects content you specify with methods B, C, or D.
* **Protect entire site, except homepage:** Ggtes every page and post except your front page.
* **Protect entire site, including homepage:** Ggtes every single page on your site.
= B. Path-Based Protection =
If you set "Protection Mode" to **Protect only specific URL paths**, this option becomes active.
* **Paths to Protect:** Enter a comma-separated list of URL paths. Any URL that *contains* this path will be gated.
* **Example:** ` /shop/, /videos/ ` will protect `example.com/shop/`, `example.com/shop/product-1/`, and `example.com/videos/my-video/`.
= C. Per-Post / Per-Page Control =
On the **Edit Post** or **Edit Page** screen, you will see an "Age Restriction" box in the sidebar.
These settings **override** all global rules.

* **Require age verification:** Check this to force the gate on this single post, even if your global setting is "No automatic protection."
* **Exclude from age verification:** Check this to make a post public, even if it's in a protected path (like `/shop/`).
= D. Shortcode Protection =
To protect just one part of a post (like a single video or paragraph), wrap it in the `[agewallet_protected]` shortcode.
`[agewallet_protected]`
This content, and only this content, will be hidden until the user verifies their age.
`[/agewallet_protected]`

= 4. Caching =
**Important:** Any time you change protection rules (in Settings or on a post), you must **clear all caches** (your caching plugin, your server's cache, and any CDN like Cloudflare) for the changes to take effect immediately.
== Frequently Asked Questions ==

= Do I need an AgeWallet account for this to work?
=

Yes, this plugin connects your WordPress site to the AgeWallet service.
You will need to sign up for an AgeWallet merchant account to get your Client ID and Client Secret.
= Is this plugin compliant with age verification regulations? =

This plugin provides the secure technical connection to the AgeWallet™ service.
The AgeWallet service itself is designed to provide verification methods that meet the robust requirements of modern digital safety and age assurance regulations in the UK, EU, US, and other major jurisdictions.
By using AgeWallet, you are using a tool built for legal compliance.

= Will this plugin slow down my site?
=

The plugin is designed to be lightweight. The age check is performed client-side (in the user's browser), so it does not interfere with server-side caching and has a minimal impact on performance.
= How do I customize the style of the age gate?
=

You can customize the logo and message from the plugin's settings page.
For more advanced styling (colors, fonts, layout), you can target the gate's CSS classes.
A full guide with the primary CSS classes is available on the "Plugin Guide" page within the plugin's settings area.
= Can I use this to protect just one part of a page? =

Yes.
Simply wrap the content you wish to protect with the `[agewallet_protected]…[/agewallet_protected]` shortcode in the post editor.
= How do I control protection for a single post or page?
=

In the editor for any post, page, or custom post type, you will find an "Age Restriction" settings box in the sidebar.
Use its checkboxes to either "Require age verification" or "Exclude from age verification" for that specific item.
These options will always override your global settings.

== CSS Customization Guide ==

Use this guide to customize the appearance of the AgeWallet™ age gate overlay.
You can override any of these classes in your theme's CSS or a custom stylesheet.
All classes are prefixed with `.aw-gate__` for easy targeting.

= 1. Overlay & Layout =

* `.aw-gate__overlay` – The full-screen overlay background.
Controls background color, opacity, and positioning of the age gate.
* `.aw-gate_card` – The main container (card) holding all gate content. Controls background color, border radius, padding, and max-width.
= 2. Logo Area =

* `.aw-gate__logo-wrap` – Wrapper for the AgeWallet logo area. Useful for centering or adjusting logo spacing.
* `.aw-gate__logo` – The logo image itself. Controls image size, margin, and alignment.
= 3. Text Content =

* `.aw-gate__title` – The headline text ("You Must Verify Your Age").
Controls font size, color, weight, and margin.
* `.aw-gate__desc` – The main description paragraph under the title.
Controls text color, font size, and line height.

= 4. Buttons =

* `.aw-gate__buttons` – Container for the "I Agree" and "I Disagree" buttons.
Use this to control button spacing or layout (flex/grid, gap, alignment).
* `.aw-gate__btn--yes` – "I Agree" button.
Controls background color, hover state, border, and text color.
* `.aw-gate__btn--no` – "I Disagree" button.
Controls background color, hover state, border, and text color.

= 5. Error & Disclaimer =

* `.aw-gate__error` – Error message shown when the user doesn't meet requirements.
Controls text color, font size, and margin.
* `.aw-gate__disclaimer` – Disclaimer or fine print text at the bottom.
Controls text color, font size, and spacing.

= 6. Shortcode Wrappers =

* `.agewallet-protected-wrapper` – The main container for protected shortcode content. Useful for adding margins.
* `.agewallet-protected-placeholder` – The placeholder box shown to unverified users, contains the gate prompt. Controls borders, background, and padding.

= Customization Tips =

To override styles safely, add your CSS in your theme or site stylesheet.
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
* `agewallet_dependency_files` (filter) – Modify the array of core class files to be loaded.
* `agewallet_initialized` (action) – Fires after all plugin classes have been instantiated.
* `agewallet_activated` (action) – Fires when the plugin is activated.
* `agewallet_deactivated` (action) – Fires when the plugin is deactivated.
= Admin Settings (class-agewallet-admin.php) =
* `agewallet_admin_menu_capability` (filter) – Change the user capability required to access the settings page.
* `agewallet_register_settings` (action) – Add custom settings sections and fields to the admin page.
* `agewallet_before_settings_fields` (action) – Add custom content before the main settings form fields.
* `agewallet_after_settings_fields` (action) – Add custom content after the main settings form fields.
= Helpers (class-agewallet-helpers.php) =
* `agewallet_hmac_secret` (filter) – Override the HMAC secret retrieved from the database.
* `agewallet_redirect_uri` (filter) – Modify the `/agewallet/callback/` URL.
* `agewallet_success_url` (filter) – Modify the `/agewallet/success/` URL.
* `agewallet_launch_url` (filter) – Modify the `/agewallet/launch/` URL.
* `agewallet_cookie_path` (filter) – Modify the path used for the verification cookie.
* `agewallet_current_url` (filter) – Override the auto-detected current URL.

= OIDC Handler (class-agewallet-oidc-handler.php) =
* `agewallet_state_transient_expiration` (filter) – Change the expiration time for the OIDC session transient.
* `agewallet_auth_request_params` (filter) – Modify the parameters sent in the authorization request to AgeWallet.
* `agewallet_oidc_error` (action) – Fires when an error is returned from the AgeWallet callback.
* `agewallet_token_request_args` (filter) – Modify the arguments for the server-to-server token exchange request.
* `agewallet_verification_success` (action) – Fires immediately after a user's age is successfully verified.
* `agewallet_verified_cookie_attributes` (filter) – Modify the attributes (path, domain, expires, etc.) of the verification cookie.
* `agewallet_final_redirect_url` (filter) – Modify the final URL the user is redirected to after successful verification.
* `agewallet_before_render_success_page` (action) – Fires before the success page HTML is rendered, allowing for a complete override.
= Gating Manager (class-agewallet-gating-manager.php) =
* `agewallet_should_gate_request` (filter) – Override the final boolean decision on whether to gate the current page.
* `agewallet_gate_script_data` (filter) – Modify the data array passed to the front-end `gate.js` script.
* `agewallet_gate_template_args` (filter) – Modify the array of data used to build the age gate HTML.
* `agewallet_after_gate_buttons` (action) – Add custom HTML content after the Agree/Disagree buttons on the gate.
* `agewallet_meta_box_save` (action) – Fires when the age restriction setting is saved for a post or page.
== Changelog ==

= 0.1.7 =
* Tweak: Standardized all front-end CSS class names for better consistency and to prevent theme conflicts.
* Tweak: Converted the "I Agree" link into a `<button>` element for better styling compatibility across themes.
* Feature: Added numerous developer hooks and filters for improved extensibility.
* Security: Hardened client-side script by adding click handlers dynamically instead of using inline attributes.
= 1.0.0 =
* Feature: Added an in-dashboard "Plugin Guide" page that displays the plugin's readme file.
= 1.0.1 =
* CSs and rDocumentation tweaks
== Upgrade Notice ==

= 1.0.1 =
This is the updated public beta release. Enjoy!