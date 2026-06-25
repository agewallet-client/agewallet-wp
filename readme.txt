=== AgeWallet OIDC Client ===
Contributors: agewallet, cookedbiscuits
Tags: age verification, age gate, agewallet, content restriction, oidc
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure, customizable age verification for WordPress via the AgeWallet OIDC service.
Cache-compatible and designed for legal compliance.

== Description ==

The AgeWallet OIDC Client plugin adds age verification and content gating to your WordPress website, backed by the AgeWallet™ verification service over OpenID Connect (OIDC).

It is designed to be straightforward to set up, fully customizable, and compatible with all WordPress hosting environments — including sites behind aggressive page caches (Cloudflare, Varnish, WP Rocket, LiteSpeed, etc.).

Two protection modes are available. **Standard (Overlay)** mode hides gated content via a client-side overlay; it is SEO-friendly and works on any cached page. **High Security (Strict)** mode short-circuits the page entirely until the visitor verifies, using a cookieless "skeleton" template that pairs with "Cache Everything" CDN rules.

Customize the gate's appearance from the Gate Appearance tab (logo, copy, colours, button radii) and override anything else from Appearance → Customize → Additional CSS. Strict-mode analytics — Google Analytics 4, Google Tag Manager, and Facebook Pixel — are configurable from the Strict Mode Analytics tab.

Protection rules cover the whole site, individual URL paths, taxonomies (categories, tags, custom taxonomies), per-post overrides via the editor sidebar, and the `[agewallet_protected]` shortcode for inline content. WooCommerce is supported out of the box with three checkout-gate modes and per-product / per-category regulated flagging.

Every verification can carry an opaque metadata payload (≤4KB) that round-trips through the OIDC flow and surfaces on the `/userinfo` response, letting integrators correlate verifications with their own backend records.

For the full feature enumeration, integration steps, developer hook list, and CSS class reference, see the Other Notes section below.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/agewallet` directory, or install the plugin through the WordPress plugins screen directly by uploading the `.zip` file.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Crucial Step: Go to Settings > Permalinks and simply click "Save Changes". This ensures the custom AgeWallet verification URLs (e.g., `/agewallet/callback/`) are registered.
4. Look for the AgeWallet item in your main WordPress admin menu to launch the setup wizard.
5. After configuring your potection settings be sure to clear any server, plugin or CloudFlare caches.

== Frequently Asked Questions ==

= Do I need an AgeWallet account to use this plugin? =

Yes. The plugin is a client for the AgeWallet OIDC age-verification service. Apply for a business account at https://agewallet.com to obtain a Client ID and Client Secret, then enter them on the plugin's Credentials screen.

= Will the plugin work with my caching plugin or CDN (Cloudflare, Varnish, WP Rocket, etc.)? =

Yes. Standard (Overlay) mode is fully cache-compatible — the page HTML is the same for every visitor and the gate is enforced client-side. High Security (Strict) mode uses a split-cache system that ships a cookieless "Skeleton" page from cache and fetches the real content via an authenticated API call once the visitor is verified, so it works with "Cache Everything" rules.

= Does it work with WooCommerce? =

Yes. The plugin integrates with WooCommerce checkout. You can gate every checkout, gate only when the cart contains regulated items, or leave checkout ungated. Regulated status can be set per-product, per-category, or per-tag.

= How do I customize the look of the gate? =

Upload your logo, customize the headline and body copy via the WYSIWYG editor, and override CSS through the Custom CSS field on the Gate Appearance settings tab. See the in-dashboard Plugin Guide page for the full list of CSS classes.

= Does the plugin store any personal data about my visitors? =

The plugin stores a signed HMAC verification cookie on the visitor's browser indicating that they passed age verification. No personal identifying information is stored on your WordPress site — verification is handled by the AgeWallet service, and only the pass/fail outcome reaches your site. See https://agewallet.com/privacy for full details.

= What happens to my data if I uninstall the plugin? =

Deleting the plugin from the Plugins screen runs `uninstall.php`, which removes all `agewallet_*` options, transients, scheduled cron events, and the strict-mode HTML cache directory. Verification cookies on visitor browsers expire on their own.

== Other Notes ==

= Features =

* Legal compliance: leverages the AgeWallet™ service, designed to comply with modern age-verification laws in major jurisdictions.
* Secure verification flow: OIDC Authorization Code Flow with PKCE.
* Cryptographic cookie integrity: HMAC-signed verification cookies, mathematically verified server-side.
* Two protection modes:
    * Standard (Overlay): a lightweight, SEO-friendly overlay that hides content via CSS and JavaScript.
    * High Security (Strict): prevents protected content from loading until verification is complete. Uses a cookieless skeleton page compatible with "Cache Everything" CDN rules.
* Smart caching architecture: Strict mode uses a split-cache system (singular vs. archives) for fast performance with immediate invalidation when content changes.
* Automated cache management: configurable garbage-collection schedule plus invalidation triggers for menu / theme / taxonomy changes.
* Fully customizable gate appearance: dedicated logo, WYSIWYG copy editor, colour pickers (overlay / card / text / buttons), card and button border-radius, plus Customizer Additional CSS for advanced overrides.
* Strict-mode analytics: structured Google Analytics 4, Google Tag Manager, and Facebook Pixel ID fields render the official snippets directly into the skeleton page (where the theme's own analytics tags can't fire).
* Flexible content protection rules:
    * Full-site protection (with or without the homepage).
    * Granular taxonomy control across Categories, Tags, and Custom Taxonomies (e.g. WooCommerce Product Categories).
    * Path-based protection on specific URL paths (e.g. `/shop/`, `/videos/premium/`).
    * Per-post overrides via the editor sidebar.
    * Inline `[agewallet_protected]` shortcode for specific page elements.
* WooCommerce integration:
    * Three checkout-gate modes: Off, Force Always, Conditional on Cart.
    * Per-product regulated controls: Not Regulated / Regulated / Override.
    * Per-category and per-tag regulated flagging on the term-edit screens (products inherit from any of their categories or tags).
    * Cart-context metadata (cart hash, total, currency, line-item count, billing country) automatically attached to verifications. In Conditional-on-Cart mode, a cart_triggers audit trail captures which products / categories / tags fired the gate.
* Metadata pass-through: attach an opaque per-verification string (up to 4 KB) — static text or auto-composed JSON of selected request-context fields — that round-trips through the OIDC flow and surfaces on the `/userinfo` response.

= Usage Guide =

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
* Colours: Pick the overlay backdrop, card background and border, body and disclaimer text colours, and the Agree / Disagree button colours from the dedicated colour pickers.
* Card / button border radius: Set how rounded the card and buttons appear.
* For anything beyond these structured controls (custom fonts, site-wide rules, advanced selectors, hover variants not in the form), use **Appearance → Customize → Additional CSS**. AgeWallet applies that CSS to the gate on both Standard and Strict modes — see the CSS Customization Guide below for the available class names.

= 4. Strict Mode Analytics (Step 4) =

Note: These settings only apply if you are using High Security (Strict) Mode.

Because Strict Mode short-circuits the theme during verification, the analytics tags your theme normally renders do NOT fire on the "Verifying..." loading screen. Enter the IDs of the services you use and AgeWallet renders their official snippets directly. Leave any field blank to skip it.

* Google Analytics 4 — Measurement ID (`G-XXXXXXXXXX`)
* Google Tag Manager — Container ID (`GTM-XXXXXXX`)
* Facebook Pixel — numeric Pixel ID

For analytics providers we don't ship a field for (Plausible, Fathom, Microsoft Clarity, Hotjar, etc.), add them via the `agewallet_skeleton_head` / `agewallet_skeleton_footer` action hooks in a small mu-plugin or your theme's `functions.php` — see the Developer Hooks section.

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

= CSS Customization Guide =

Use this guide to customize the appearance of the AgeWallet™ age gate and the Strict Mode loading screen.

For most customizations, the colour and radius controls in Step 3: Gate Appearance are sufficient — they map directly to the CSS custom properties listed in the next subsection.

For anything beyond that (custom fonts, site-wide rules, advanced selectors, hover states not exposed in the form), add your rules in **Appearance → Customize → Additional CSS**. AgeWallet applies that CSS to the gate on both Standard and Strict modes. The gate exposes a stable DOM with the `.aw-gate__*` and `.agewallet-*` class names below — write rules against them as you would for any theme component.

= CSS custom properties (set by the Gate Appearance form) =

The plugin's gate stylesheet uses these CSS variables; the Gate Appearance pickers write to them. You can also override them yourself in Customizer Additional CSS:

* `--aw-bg` — Overlay backdrop (Standard mode) and skeleton background (Strict mode).
* `--aw-card` — Card background.
* `--aw-card-border` — Card border colour.
* `--aw-text` — Card body text colour.
* `--aw-muted` — Disclaimer text colour.
* `--aw-purple` — "I Agree" button background.
* `--aw-purple-700` — "I Agree" hover state.
* `--aw-no-btn-dark-bg` — "I Disagree" button background.
* `--aw-no-btn-dark-text` — "I Disagree" button text.
* `--aw-radius` — Card border radius (px).
* `--aw-btn-radius` — Button border radius (px).

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

For colour and radius tweaks, the Gate Appearance form is the easiest path. For anything else, paste your CSS into **Appearance → Customize → Additional CSS**:

`
.aw-gate__btn--yes {
    background-color: #28a745 !important;
    color: #fff !important;
}
`

Customizer CSS targets the gate's `.aw-gate__*` and `.agewallet-*` classes in both Standard and Strict modes — no plugin setting required.

Use your browser's developer tools (Inspect Element) to preview your changes live.

= Developer Hooks =

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

= Metadata Builder (class-agewallet-metadata-builder.php) =
* `agewallet_auto_metadata` (filter) - Modify the resolved auto-metadata fields array (post_id, post_slug, request_path, user_id, etc.) before it is JSON-encoded into the metadata string.

= OIDC Handler (class-agewallet-oidc-handler.php) =
* `agewallet_state_transient_expiration` (filter) - Change the expiration time for the OIDC session transient.
* `agewallet_auth_request_params` (filter) - Modify the parameters sent in the authorization request to AgeWallet.
* `agewallet_metadata` (filter) - Modify the metadata string just before it is signed and attached to the verification request. Receives the value produced by the configured metadata source (Off, Static text, or Auto JSON of selected fields).
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

= WooCommerce (class-agewallet-woocommerce.php & class-agewallet-product-flags.php) =
* `agewallet_wc_checkout_metadata` (filter) - Modify the per-checkout JSON metadata blob (cart hash, total, currency, line item count, billing country, etc.) before it is attached to the verification.
* `agewallet_cart_has_regulated_items` (filter) - Override the boolean decision on whether the current WC cart contains items that should trigger the checkout gate in "Conditional on cart" mode. Useful for custom rules such as "regulated if cart total exceeds X" or "regulated if shipping to a specific country".
* `agewallet_regulated_cart_triggers` (filter) - Modify the array of product IDs, category term IDs, and tag term IDs that triggered the regulated-cart check. Used both for the gating decision and for the cart_triggers audit field stored in metadata.

= Public Helper Functions =
* `agewallet_get_metadata()` - Returns the metadata value attached to the currently verified user's session as a string, or empty string if not available. Reads from the signed verification cookie payload.

= Public PHP Methods =
* `AgeWallet_WooCommerce::checkout_gating_mode()` - Returns the configured WC checkout-gate mode as a string: `'off'`, `'force-always'`, or `'conditional-on-cart'`.
* `AgeWallet_WooCommerce::checkout_gating_enabled()` - Returns true when the checkout gate is enabled in any mode (force-always or conditional-on-cart).
* `AgeWallet_WooCommerce::cart_contains_regulated_items()` - Returns true when the current WC cart contains at least one item flagged as regulated.
* `AgeWallet_WooCommerce::get_regulated_triggers()` - Returns an associative array of product IDs, category term IDs, and tag term IDs that triggered the regulated-cart check.
* `AgeWallet_WooCommerce::product_is_regulated( $product )` - Returns true when the given WC_Product (or its parent for variations) is flagged as regulated, either directly via the per-product status or inherited from its categories or tags.
* `AgeWallet_WooCommerce::is_checkout_request()` - Returns true when the current request is the WooCommerce-configured checkout page.
* `AgeWallet_WooCommerce::is_dynamic_wc_page()` - Returns true when the current request is a WooCommerce page that must never be served from the strict-mode cache (checkout, cart, my-account).

= Post & Term Meta Keys =
* `_agewallet_regulated_status` (post meta on `product`) - Per-product regulated status. Accepts `'not_regulated'` (default), `'regulated'`, or `'override_not_regulated'` (forces unregulated even when the product's category or tag is flagged).
* `agewallet_regulated` (term meta on `product_cat` and `product_tag`) - When set to `'1'`, every product in this category or tag is treated as regulated for the WC checkout gate in "Conditional on cart" mode.
* `_agewallet_force_restrict` (post meta) - When set to `'1'`, forces the age gate to fire on this single post regardless of global rules.
* `_agewallet_force_exclude` (post meta) - When set to `'1'`, forces the age gate to be skipped on this single post regardless of global rules. Takes priority over `_agewallet_force_restrict`.

== Screenshots ==

1. The age gate overlay shown to unverified visitors in Standard (Overlay) mode.
2. The Strict Mode "Verifying..." skeleton screen used when content must not load until verification completes.
3. The plugin's setup wizard — Step 1 (API Credentials).
4. The Content Guarding screen — taxonomy, path, and global protection rules.
5. The WooCommerce checkout-gating settings and per-product Regulated flag.

== License ==

This plugin is licensed under the GNU General Public License v2.0 or later. A copy of the license is included in `LICENSE.txt` and is available at https://www.gnu.org/licenses/gpl-2.0.html.

== Changelog ==

= 1.5.4 =
* Structured Gate Appearance form: dedicated colour pickers (overlay backdrop, card background and border, body and disclaimer text, Agree / Disagree buttons) and card / button border-radius inputs replace the previous free-text "Custom CSS" field. Defaults match the previous look; saving without changes produces no visual diff.
* Strict Mode Analytics: dedicated Google Analytics 4 Measurement ID, Google Tag Manager Container ID, and Facebook Pixel ID fields replace the previous free-text Header / Footer Scripts inputs. AgeWallet now renders the canonical official snippets for each provider when the corresponding ID is set.
* Customizer-driven custom CSS: arbitrary CSS overrides should now live in **Appearance → Customize → Additional CSS**. AgeWallet automatically renders the Customizer's saved CSS into the Strict-mode skeleton (it already applies on Standard mode via the normal WordPress lifecycle). For exotic per-site needs (custom fonts, non-standard analytics providers), use the `agewallet_skeleton_head` / `agewallet_skeleton_footer` action hooks listed in the Developer Hooks guide.
* Text domain renamed from `agewallet` to `agewallet-oidc-client` to match the WordPress.org-assigned plugin slug. Plugin folder, main file, and Text Domain header all aligned.
* Quality: admin-page styles moved into a dedicated stylesheet enqueued via `admin_enqueue_scripts` (no more inline `<style>` blocks); raw `$_GET` no longer logged from the OIDC handler's debug paths; shortcode return annotated for the static-analyzer escape check.
* No external installs existed before this version, so the previous `agewallet_custom_css` / `agewallet_head_scripts` / `agewallet_footer_scripts` options are removed outright; their stored values (if any) are cleared by `uninstall.php` on plugin deletion.

= 1.5.3 =
* Security & Quality: Comprehensive cleanup pass driven by the WordPress.org Plugin Check tool. All `$_GET`, `$_SERVER`, and `$_COOKIE` reads now go through `wp_unslash()` + appropriate `sanitize_*()` wrappers. All translated output is escaped (`esc_html__()` / `esc_url()` / etc.). Translator comments added to every `__()`/`esc_html__()` call that uses placeholders, and ordered-placeholder syntax adopted where multiple placeholders appear. Direct `unlink()` calls replaced with `wp_delete_file()`; `strip_tags()` replaced with `wp_strip_all_tags()`. Template variables prefixed (`$aw_*`). Plugin's own debug logging routed through a centralised helper that respects `OPT_DEBUG_MODE`, replacing scattered `error_log()` calls. Removed manual `load_plugin_textdomain()` call (no longer needed under WP 4.6+).
* Compatibility: Minimum WordPress version raised from 5.8 to 6.0. The plugin's OIDC handler uses `str_ends_with()`, which only exists in WP 5.9 / PHP 8.0 and later; 6.0 is also the floor WP.org recommends for active plugins.

= 1.5.2 =
* Maintenance: WooCommerce Marketplace prep. Added the standard `WC requires at least` and `WC tested up to` plugin header lines, and declared High-Performance Order Storage (HPOS) compatibility. The plugin's WooCommerce integration only reads cart and product data, so HPOS compatibility is safe.

= 1.5.1 =
* Maintenance: Prepared the plugin for the WordPress.org plugin directory. Removed the bundled third-party update checker (WordPress.org now handles all updates). Added GPL-2.0-or-later license declaration. Reconciled the version string across the plugin header, the internal `AGEWALLET_VERSION` constant, and `readme.txt`. Added `uninstall.php` for clean removal of options, scheduled cron events, and the strict-mode cache directory.

= 1.4.0 =
* Feature: Metadata pass-through. An opaque per-verification string (up to 4096 bytes) can now be attached to every AgeWallet verification. The Credentials page exposes a "Metadata source" picker with three modes: Off, Static text (literal string), and Auto JSON (compose from selected fields across post, user, request, and archive context groups). Metadata is computed at gate-render time and HMAC-signed for transport via the launch URL. Two filter hooks let developers extend: `agewallet_auto_metadata` (array, before encoding) and `agewallet_metadata` (final string, after encoding). Read back with `agewallet_get_metadata()`.
* Feature: WooCommerce checkout gating. New three-state Checkout Gating control on the Content Guarding page (visible only when WooCommerce is active): Off (no checkout-specific rule), Force Always (every checkout gates), or Conditional on Cart (gate fires only when the cart contains items flagged as regulated). Regulated status is set per-product via a new "AgeWallet" tab in the product data metabox (Not Regulated / Regulated / Override) or per-category and per-tag via a Regulated checkbox on the term edit screens. Per-checkout cart context (cart hash, total, currency, line item count, billing country) is composed into the verification metadata and merged with any site-level metadata. When Conditional on Cart mode fires the gate, a cart_triggers audit field captures the product, category, and tag IDs that triggered. Filters: `agewallet_wc_checkout_metadata`, `agewallet_cart_has_regulated_items`, `agewallet_regulated_cart_triggers`.
* Safety: Strict-mode cache automatically skips WooCommerce checkout, cart, and my-account pages. Caching cart-bearing pages would either render an empty cart in the cookieless loopback or leak one customer's HTML to another, so these pages always render live.
* Dev: New helper `agewallet_get_metadata()` and `AgeWallet_Helpers::get_verified_cookie_payload()`. Cart-inspection methods exposed on `AgeWallet_WooCommerce`: `checkout_gating_mode`, `cart_contains_regulated_items`, `get_regulated_triggers`, `product_is_regulated`. Programmatic flagging via post meta (`_agewallet_regulated_status` on products) and term meta (`agewallet_regulated` on `product_cat` and `product_tag`).

= 1.3.1 =
* Fix: On verification failure, redirect user back to originating page so the age gate re-triggers, rather than showing a misleading "cancelled or denied" message.

= 1.3.0 =
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

= 1.2.0 =
* Patched 2 bugs preventing shortcode from working properly in some environments.

= 1.0.1 =
* CSS and Documentation tweaks.

= 1.0.0 =
* Feature: Added an in-dashboard "Plugin Guide" page that displays the plugin's readme file.

= 0.1.7 =
* Tweak: Standardized all front-end CSS class names for better consistency and to prevent theme conflicts.
* Tweak: Converted the "I Agree" link into a &lt;button&gt; element for better styling compatibility across themes.
* Feature: Added numerous developer hooks and filters for improved extensibility.
* Security: Hardened client-side script by adding click handlers dynamically instead of using inline attributes.

== Upgrade Notice ==

= 1.4.0 =
Adds metadata pass-through and a full WooCommerce integration with three-mode checkout gating (Off / Force Always / Conditional on Cart), per-product and per-category/tag regulated flagging, and per-checkout cart context attached as metadata.

= 1.3.1 =
On verification failure, users are now redirected back to the originating page instead of seeing a generic error.