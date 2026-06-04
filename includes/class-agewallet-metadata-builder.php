<?php
/**
 * Builds the metadata string attached to each AgeWallet verification.
 *
 * Reads the configured mode (off / static / auto) and either returns the
 * user's literal string, an auto-composed JSON bundle of selected page-context
 * fields, or null. Per-visitor fields (user_id, user_role) are resolved later
 * by AgeWallet_OIDC_Handler at verify-click time.
 *
 * @package AgeWalletOIDCClient
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AgeWallet_Metadata_Builder' ) ) {

	final class AgeWallet_Metadata_Builder {

		const MODE_OFF    = 'off';
		const MODE_STATIC = 'static';
		const MODE_AUTO   = 'auto';

		public static function allowed_modes() {
			return array( self::MODE_OFF, self::MODE_STATIC, self::MODE_AUTO );
		}

		/**
		 * True when the configured protection mode is strict (i.e., AgeWallet's own
		 * HTML cache is in play). Kept as a public helper for callers that still
		 * need to know whether strict mode is active.
		 */
		public static function is_strict_mode() {
			return 'strict' === get_option( 'agewallet_protection_mode', 'standard' );
		}

		/**
		 * Allowed field keys for the auto-JSON bundle, grouped by UI section.
		 */
		public static function allowed_auto_fields() {
			return array(
				// Post context
				'post_id',
				'post_slug',
				'post_type',
				// User context (resolved at click-time in OIDC_Handler)
				'user_id',
				'user_role',
				// Request context
				'request_path',
				// Archive / search context
				'page_type',
				'term_id',
				'term_slug',
				'term_taxonomy',
				'search_query',
				'archive_post_type',
			);
		}

		/**
		 * Resolve the metadata string for the current request, or null.
		 *
		 * @return string|null
		 */
		public static function build() {
			$mode = get_option( 'agewallet_metadata_mode', self::MODE_STATIC );

			if ( self::MODE_OFF === $mode ) {
				return null;
			}

			if ( self::MODE_STATIC === $mode ) {
				$value = (string) get_option( AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT, '' );
				return ( '' === $value ) ? null : $value;
			}

			if ( self::MODE_AUTO === $mode ) {
				$selected = get_option( 'agewallet_auto_metadata_fields', array() );
				if ( ! is_array( $selected ) || empty( $selected ) ) {
					return null;
				}

				$fields = self::collect_fields( $selected );

				/**
				 * Filter the auto-composed field array before JSON-encoding.
				 *
				 * Fires at gate-render time. Whatever you add here is baked into
				 * the signed md= URL and will be cached if a page-cache layer is
				 * in front of WordPress. ONLY add per-URL/page-context values.
				 * For per-visitor data, use the `agewallet_metadata` filter
				 * instead — it fires at verify-click time and is cache-safe.
				 *
				 * @param array $fields Associative array of selected fields with resolved values.
				 *                       Keys whose values were unavailable for the current request
				 *                       are already omitted.
				 */
				$fields = apply_filters( 'agewallet_auto_metadata', $fields );

				if ( ! is_array( $fields ) || empty( $fields ) ) {
					return null;
				}

				$json = wp_json_encode( $fields );
				return is_string( $json ) ? $json : null;
			}

			return null;
		}

		/**
		 * Collect the values for the selected field keys, omitting any whose
		 * source isn't available for the current request.
		 *
		 * @param array $selected Field keys the site owner enabled.
		 * @return array Associative array of resolved values.
		 */
		private static function collect_fields( array $selected ) {
			$allowed = array_flip( self::allowed_auto_fields() );
			$out     = array();

			foreach ( $selected as $field ) {
				if ( ! is_string( $field ) || ! isset( $allowed[ $field ] ) ) {
					continue;
				}

				$value = self::resolve_field( $field );
				if ( null !== $value && '' !== $value ) {
					$out[ $field ] = $value;
				}
			}

			return $out;
		}

		/**
		 * Resolve a single page-context field to a value, or null if unavailable.
		 * user_id and user_role are resolved at click-time in AgeWallet_OIDC_Handler.
		 *
		 * @param string $field
		 * @return mixed
		 */
		private static function resolve_field( $field ) {
			switch ( $field ) {
				case 'post_id':
					return ( function_exists( 'is_singular' ) && is_singular() ) ? (int) get_the_ID() : null;

				case 'post_slug':
					if ( function_exists( 'is_singular' ) && is_singular() ) {
						$id = get_the_ID();
						return $id ? (string) get_post_field( 'post_name', $id ) : null;
					}
					return null;

				case 'post_type':
					return ( function_exists( 'is_singular' ) && is_singular() ) ? (string) get_post_type() : null;

				case 'request_path':
					if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
						return null;
					}
					return (string) strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' );

				case 'page_type':
					return self::classify_page_type();

				case 'term_id':
					$term = self::current_term();
					return $term ? (int) $term->term_id : null;

				case 'term_slug':
					$term = self::current_term();
					return $term ? (string) $term->slug : null;

				case 'term_taxonomy':
					$term = self::current_term();
					return $term ? (string) $term->taxonomy : null;

				case 'search_query':
					if ( function_exists( 'is_search' ) && is_search() ) {
						$q = function_exists( 'get_search_query' ) ? get_search_query() : '';
						return '' !== $q ? sanitize_text_field( $q ) : null;
					}
					return null;

				case 'archive_post_type':
					if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive() ) {
						return (string) get_post_type();
					}
					return null;
			}

			return null;
		}

		private static function classify_page_type() {
			if ( function_exists( 'is_singular' ) && is_singular() ) {
				return 'singular';
			}
			if ( function_exists( 'is_category' ) && is_category() ) {
				return 'category';
			}
			if ( function_exists( 'is_tag' ) && is_tag() ) {
				return 'tag';
			}
			if ( function_exists( 'is_tax' ) && is_tax() ) {
				return 'tax';
			}
			if ( function_exists( 'is_author' ) && is_author() ) {
				return 'author';
			}
			if ( function_exists( 'is_date' ) && is_date() ) {
				return 'date';
			}
			if ( function_exists( 'is_search' ) && is_search() ) {
				return 'search';
			}
			if ( function_exists( 'is_404' ) && is_404() ) {
				return '404';
			}
			if ( function_exists( 'is_front_page' ) && is_front_page() ) {
				return 'home';
			}
			if ( function_exists( 'is_home' ) && is_home() ) {
				return 'home';
			}
			if ( function_exists( 'is_archive' ) && is_archive() ) {
				return 'archive';
			}
			return null;
		}

		private static function current_term() {
			if ( ! ( ( function_exists( 'is_category' ) && is_category() )
				|| ( function_exists( 'is_tag' ) && is_tag() )
				|| ( function_exists( 'is_tax' ) && is_tax() ) ) ) {
				return null;
			}
			$obj = get_queried_object();
			if ( ! $obj || empty( $obj->term_id ) ) {
				return null;
			}
			return $obj;
		}
	}
}
