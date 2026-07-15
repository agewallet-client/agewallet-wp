This guide explains how to configure and use the plugin using the new setup wizard.

= 1. Initial Configuration (Step 1) =

Before the plugin can work, you must connect it to your AgeWallet account. If you don't have an account yet, register one at https://app.agewallet.io/register — new accounts receive $5 of free verification credit.

1. Click AgeWallet in your WordPress admin menu to see the Welcome screen.
2. Click on Step 1: API Credentials.
3. In the "API Configuration" section, you will see a field labeled Redirect URI. Copy this URL.
4. Log in to your AgeWallet business account dashboard at https://app.agewallet.io.
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

E. WooCommerce (visible when WooCommerce is active)

The Content Guarding page shows a "WooCommerce" section below the Taxonomy Rules. The plugin also adds an "AgeWallet" tab to the Product Data metabox and a checkbox to the term-edit screens for Categories and Tags.

* Checkout Gating: chooses how the checkout page itself is gated.
    * Off: no checkout-specific rule; the Security Mode / Block / Path / Taxonomy rules apply unchanged.
    * Force Always: every visit to the checkout page requires age verification, regardless of other rules.
    * Conditional on Cart: the checkout requires verification only when the cart contains at least one product flagged as regulated AND the visitor isn't already verified. Useful for mixed-catalog stores where only some products require age gating.

* Checkout Metadata Fields: pick which cart-context values get attached to each verification as metadata that round-trips through the OIDC flow back to your backend. Available fields: cart hash, cart total, currency code, WordPress user ID, billing country, number of line items. Defaults: cart hash, cart total, currency. Use the `agewallet_wc_checkout_metadata` filter (see Developer Hooks) for fully custom payloads.

* Per-Product Regulated Status: open any product in WooCommerce > Products > Edit Product > AgeWallet tab.
    * Not regulated (default): does NOT trigger the Conditional-on-Cart gate.
    * Regulated: cart containing this product fires the Conditional-on-Cart gate.
    * Override — explicitly not regulated: forces this product to bypass the gate even when one of its categories or tags is flagged regulated. Useful for an edge product inside an otherwise-regulated category.

* Per-Category / Per-Tag Regulated Flag: on the term-edit screens (Products > Categories or Products > Tags), check "Regulated for AgeWallet checkout gate". Products inherit the regulated flag from any of their categories or tags (unless overridden per-product).

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
