This plugin includes a number of action and filter hooks to allow for advanced customization and integration.

= Main Plugin File =
* `agewallet_dependency_files` (filter) - Modify the array of core class files to be loaded.
* `agewallet_initialized` (action) - Fires after all plugin classes have been instantiated.
* `agewallet_activated` (action) - Fires when the plugin is activated.
* `agewallet_deactivated` (action) - Fires when the plugin is deactivated.

= Admin Settings (class-agewallet-admin.php) =
* `agewallet_admin_menu_capability` (filter) - Change the user capability required to access the settings page.

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
