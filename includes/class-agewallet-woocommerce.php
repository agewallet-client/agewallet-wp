<?php
/**
 * WooCommerce integration for the AgeWallet plugin.
 *
 * Adds an opt-in "always gate the checkout page" rule plus per-checkout
 * metadata that round-trips through the AgeWallet verification flow.
 *
 * Loaded only when WooCommerce is active (gated in agewallet.php bootstrap).
 *
 * @package AgeWalletOIDCClient
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AgeWallet_WooCommerce' ) ) {

	final class AgeWallet_WooCommerce {

		private static $instance = null;

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
		 * True if the merchant has opted in to gating the WC checkout.
		 */
		public static function checkout_gating_enabled() {
			return (bool) get_option( AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT, false );
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
			if ( ! self::is_checkout_request() ) {
				return $existing;
			}

			$cart_array = $this->build_checkout_metadata_array();
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
