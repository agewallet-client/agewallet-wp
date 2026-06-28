<?php
/**
 * WooCommerce integration for the AgeWallet plugin.
 *
 * Adds an opt-in "always gate the checkout page" rule plus per-checkout
 * metadata that round-trips through the AgeWallet verification flow.
 *
 * Loaded only when WooCommerce is active (gated in agewallet-oidc-client.php bootstrap).
 *
 * @package AgeWalletOIDCClient
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AgeWallet_WooCommerce' ) ) {

	final class AgeWallet_WooCommerce {

		private static $instance = null;

		/**
		 * Post meta key for per-product regulated status.
		 * Values: 'not_regulated' | 'regulated' | 'override_not_regulated' | (absent — treated as 'not_regulated')
		 */
		const META_KEY_PRODUCT_REGULATED_STATUS = '_agewallet_regulated_status';

		/**
		 * Term meta key for category/tag-level regulated flag.
		 * Value: '1' if regulated, absent/'' otherwise.
		 */
		const META_KEY_TERM_REGULATED = 'agewallet_regulated';

		/**
		 * Default per-product regulated status when no post meta is set.
		 */
		const PRODUCT_REGULATED_STATUS_DEFAULT = 'not_regulated';

		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_filter( 'agewallet_metadata', array( $this, 'inject_checkout_metadata' ) );
		}

		/**
		 * Returns the configured WC checkout-gate mode: one of
		 * AgeWalletOIDCClientPro::WC_GATE_MODE_OFF, _FORCE_ALWAYS, or _CONDITIONAL_CART.
		 *
		 * Normalizes legacy values: integer/string 1 → 'force-always', 0/'' → 'off'.
		 * Unknown values default to 'off'.
		 */
		public static function checkout_gating_mode() {
			$raw = get_option( AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT, AgeWalletOIDCClientPro::WC_GATE_MODE_OFF );

			// Legacy normalization.
			if ( 1 === $raw || '1' === $raw || true === $raw ) {
				return AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS;
			}
			if ( 0 === $raw || '0' === $raw || '' === $raw || false === $raw || null === $raw ) {
				return AgeWalletOIDCClientPro::WC_GATE_MODE_OFF;
			}

			$allowed = array(
				AgeWalletOIDCClientPro::WC_GATE_MODE_OFF,
				AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS,
				AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART,
			);
			return in_array( $raw, $allowed, true ) ? $raw : AgeWalletOIDCClientPro::WC_GATE_MODE_OFF;
		}

		/**
		 * True if WC checkout gating is enabled in any mode (force-always or conditional-on-cart).
		 * Kept for callers that just want to know "is this opt-in turned on at all."
		 */
		public static function checkout_gating_enabled() {
			return AgeWalletOIDCClientPro::WC_GATE_MODE_OFF !== self::checkout_gating_mode();
		}

		/**
		 * True if the current request is the WC checkout page.
		 *
		 * Compares the current queried page against whatever WC has configured
		 * as its checkout page in Settings → Advanced → Page setup. Reading
		 * from WC's own source-of-truth (the `woocommerce_checkout_page_id`
		 * option) avoids the edge cases where `is_checkout()` silently returns
		 * false (Blocks-based checkout, plugin shims hooking
		 * `woocommerce_is_checkout`, endpoint-detection drift, etc.).
		 */
		public static function is_checkout_request() {
			if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'is_page' ) ) {
				return false;
			}
			$checkout_id = wc_get_page_id( 'checkout' );
			return $checkout_id > 0 && is_page( $checkout_id );
		}

		/**
		 * True for dynamic WC pages that must never be served from the strict-mode cache.
		 * Checkout, cart, and my-account all contain per-user/per-cart state and nonces.
		 *
		 * @since 1.4.0
		 * @return bool
		 */
		public static function is_dynamic_wc_page() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return false;
			}
			if ( function_exists( 'is_checkout' ) && is_checkout() ) {
				return true;
			}
			if ( function_exists( 'is_cart' ) && is_cart() ) {
				return true;
			}
			if ( function_exists( 'is_account_page' ) && is_account_page() ) {
				return true;
			}
			return false;
		}

		/**
		 * Default set of cart-context fields to include in metadata.
		 */
		public static function default_metadata_fields() {
			return array( 'cart_hash', 'cart_total', 'currency' );
		}

		/**
		 * Allowed metadata field keys.
		 */
		public static function allowed_metadata_fields() {
			return array( 'cart_hash', 'cart_total', 'currency', 'customer_id', 'billing_country', 'line_item_count' );
		}

		/**
		 * True if the current cart contains at least one item flagged as regulated.
		 *
		 * Used by `should_restrict_content()` in the conditional-on-cart branch so the
		 * checkout gate fires only when a regulated item is present. Short-circuits if
		 * WC is missing or the cart is empty.
		 *
		 * Final result is passed through the `agewallet_cart_has_regulated_items` filter
		 * so site developers can override the decision (e.g. "regulated if cart total > X",
		 * "regulated if shipping to a specific country", etc.).
		 *
		 * Filter signature: apply_filters( 'agewallet_cart_has_regulated_items', bool $is_regulated, array $triggers, ?WC_Cart $cart )
		 */
		public static function cart_contains_regulated_items() {
			if ( ! function_exists( 'WC' ) ) {
				return apply_filters( 'agewallet_cart_has_regulated_items', false, array(), null );
			}
			$cart = WC()->cart ?? null;
			if ( ! $cart || $cart->is_empty() ) {
				return apply_filters( 'agewallet_cart_has_regulated_items', false, array(), $cart );
			}

			$triggers     = self::get_regulated_triggers();
			$is_regulated = ! empty( $triggers['products'] )
				|| ! empty( $triggers['product_cats'] )
				|| ! empty( $triggers['product_tags'] );

			return apply_filters( 'agewallet_cart_has_regulated_items', $is_regulated, $triggers, $cart );
		}

		/**
		 * Collect the IDs of products, categories, and tags in the current cart that
		 * caused the regulated-status check to fire. Used both by the gating decision
		 * and by the metadata audit trail (`cart_triggers` field) so merchants can prove
		 * which item(s) led to a given verification.
		 *
		 * Final array is passed through the `agewallet_regulated_cart_triggers` filter.
		 *
		 * @return array Shaped as: array( 'products' => int[], 'product_cats' => int[], 'product_tags' => int[] ).
		 */
		public static function get_regulated_triggers() {
			$triggers = array(
				'products'     => array(),
				'product_cats' => array(),
				'product_tags' => array(),
			);

			if ( ! function_exists( 'WC' ) ) {
				return apply_filters( 'agewallet_regulated_cart_triggers', $triggers, null );
			}
			$cart = WC()->cart ?? null;
			if ( ! $cart || $cart->is_empty() ) {
				return apply_filters( 'agewallet_regulated_cart_triggers', $triggers, $cart );
			}

			foreach ( $cart->get_cart() as $item ) {
				if ( empty( $item['data'] ) || ! ( $item['data'] instanceof WC_Product ) ) {
					continue;
				}
				$product   = $item['data'];
				$lookup_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

				if ( ! self::product_is_regulated( $product ) ) {
					continue;
				}

				if ( ! in_array( $lookup_id, $triggers['products'], true ) ) {
					$triggers['products'][] = $lookup_id;
				}

				$cat_terms = wp_get_post_terms( $lookup_id, 'product_cat', array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $cat_terms ) ) {
					foreach ( $cat_terms as $term_id ) {
						if ( '1' === get_term_meta( $term_id, self::META_KEY_TERM_REGULATED, true )
							 && ! in_array( $term_id, $triggers['product_cats'], true ) ) {
							$triggers['product_cats'][] = (int) $term_id;
						}
					}
				}

				$tag_terms = wp_get_post_terms( $lookup_id, 'product_tag', array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $tag_terms ) ) {
					foreach ( $tag_terms as $term_id ) {
						if ( '1' === get_term_meta( $term_id, self::META_KEY_TERM_REGULATED, true )
							 && ! in_array( $term_id, $triggers['product_tags'], true ) ) {
							$triggers['product_tags'][] = (int) $term_id;
						}
					}
				}
			}

			return apply_filters( 'agewallet_regulated_cart_triggers', $triggers, $cart );
		}

		/**
		 * True if the given product is regulated under the current rules.
		 *
		 * Per-product flag wins over category/tag flags:
		 *   - 'regulated'              → returns true
		 *   - 'override_not_regulated' → returns false (forces unregulated even if cat/tag says yes)
		 *   - anything else            → falls through to category + tag check; any category OR tag
		 *                                 carrying the `agewallet_regulated` term meta = '1' returns true.
		 *
		 * Variations inherit their parent's flag (`get_parent_id() > 0 ? parent : self`).
		 *
		 * @param WC_Product $product
		 * @return bool
		 */
		public static function product_is_regulated( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}
			$lookup_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

			$status = get_post_meta( $lookup_id, self::META_KEY_PRODUCT_REGULATED_STATUS, true );
			if ( 'regulated' === $status ) {
				return true;
			}
			if ( 'override_not_regulated' === $status ) {
				return false;
			}

			// Fall through to category + tag inheritance.
			$cat_terms = wp_get_post_terms( $lookup_id, 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $cat_terms ) ) {
				foreach ( $cat_terms as $term_id ) {
					if ( '1' === get_term_meta( $term_id, self::META_KEY_TERM_REGULATED, true ) ) {
						return true;
					}
				}
			}

			$tag_terms = wp_get_post_terms( $lookup_id, 'product_tag', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $tag_terms ) ) {
				foreach ( $tag_terms as $term_id ) {
					if ( '1' === get_term_meta( $term_id, self::META_KEY_TERM_REGULATED, true ) ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Build the per-checkout cart context as an associative array.
		 *
		 * @return array Associative array of resolved cart fields; empty if no WC cart.
		 */
		public function build_checkout_metadata_array() {
			if ( ! function_exists( 'WC' ) ) {
				return array();
			}
			$cart = WC()->cart ?? null;
			if ( ! $cart ) {
				return array();
			}

			$selected = get_option( AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS, self::default_metadata_fields() );
			if ( ! is_array( $selected ) ) {
				$selected = self::default_metadata_fields();
			}

			$data = array();
			foreach ( $selected as $field ) {
				switch ( $field ) {
					case 'cart_hash':
						$data['cart_hash'] = $cart->get_cart_hash();
						break;
					case 'cart_total':
						$data['cart_total'] = $cart->get_total( 'edit' );
						break;
					case 'currency':
						$data['currency'] = get_woocommerce_currency();
						break;
					case 'customer_id':
						$data['customer_id'] = get_current_user_id();
						break;
					case 'billing_country':
						$customer = WC()->customer ?? null;
						if ( $customer ) {
							$data['billing_country'] = $customer->get_billing_country();
						}
						break;
					case 'line_item_count':
						$data['line_item_count'] = $cart->get_cart_contents_count();
						break;
				}
			}

			return $data;
		}

		/**
		 * Filter callback for `agewallet_metadata`. On the checkout page, merges
		 * the WC cart context into whatever the site-level builder produced — never overrides.
		 *
		 * @param string|null $existing The site-level builder output (null/static text/auto JSON).
		 * @return string|null
		 */
		public function inject_checkout_metadata( $existing ) {
			if ( ! self::checkout_gating_enabled() ) {
				return $existing;
			}
			// Only fire when the verification originated from the WC checkout page.
			// AgeWallet_OIDC_Handler sets the origin marker from the signed `agewallet_origin`
			// query param on /agewallet/launch — that marker is added by the gating
			// manager when rendering the gate on a page where is_checkout_request()
			// is true. Cannot use is_checkout_request() directly here because we're
			// inside /agewallet/launch at this point, not on the checkout page.
			if ( ! class_exists( 'AgeWallet_OIDC_Handler' )
				|| 'checkout' !== AgeWallet_OIDC_Handler::get_request_origin() ) {
				return $existing;
			}

			$cart_array = $this->build_checkout_metadata_array();

			// When the gate fired because of regulated cart contents, attach the trigger IDs
			// to the metadata as an audit trail so merchants can prove which item(s) led to
			// each verification. Only meaningful in conditional-on-cart mode — in force-always
			// mode every checkout gates, so this field would just be noise.
			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART === self::checkout_gating_mode() ) {
				$triggers = self::get_regulated_triggers();
				if ( ! empty( $triggers['products'] )
					 || ! empty( $triggers['product_cats'] )
					 || ! empty( $triggers['product_tags'] ) ) {
					$cart_array['cart_triggers'] = $triggers;
				}
			}

			if ( empty( $cart_array ) ) {
				return $existing;
			}

			// Compose: site-level metadata wins on its own keys, cart fields are added (and win on collision).
			if ( null === $existing || '' === $existing ) {
				$final = $cart_array;
			} else {
				$decoded = json_decode( (string) $existing, true );
				if ( is_array( $decoded ) ) {
					// Site is auto-JSON: merge keys (cart fields override on collision since they're per-order).
					$final = array_merge( $decoded, $cart_array );
				} else {
					// Site is static text: wrap as a sibling key.
					$final = array_merge( array( 'site_metadata' => (string) $existing ), $cart_array );
				}
			}

			$json = wp_json_encode( $final );

			/**
			 * Filter the per-checkout metadata blob before it's attached to the verification.
			 *
			 * @param string   $json  JSON-encoded composed metadata (site + cart).
			 * @param WC_Cart  $cart  The current WC cart.
			 */
			$json = apply_filters( 'agewallet_wc_checkout_metadata', $json, WC()->cart );

			return is_string( $json ) && '' !== $json ? $json : $existing;
		}
	}
}
