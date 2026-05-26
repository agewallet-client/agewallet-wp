<?php
/**
 * Builds the metadata string attached to each AgeWallet verification.
 *
 * Reads the configured mode (off / static / auto) and either returns the
 * user's literal string, an auto-composed JSON bundle of selected request
 * context fields, or null (no metadata).
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
		 * Field keys that cannot survive the strict-mode page cache, because their
		 * value is per-visitor or per-request rather than per-URL. Stripped at runtime
		 * (and on save) when strict mode is active.
		 */
		public static function cache_unsafe_fields() {
			return array( 'user_id', 'user_role', 'utm_source', 'utm_campaign', 'referrer_host' );
		}

		/**
		 * True when the configured protection mode is strict (i.e., pages are cached).
		 */
		public static function is_strict_mode() {
			return 'strict' === get_option( 'agewallet_protection_mode', 'standard' );
		}

		/**
		 * Allowed field keys for the auto-JSON bundle, grouped by UI section.
		 * The order here drives the admin UI layout.
		 */
		public static function allowed_auto_fields() {
			return array(
				// Post context
				'post_id',
				'post_slug',
				'post_type',
				// User context
				'user_id',
				'user_role',
				// Request context
				'request_path',
				'referrer_host',
				// Marketing context
				'utm_source',
				'utm_campaign',
				// Archive context
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

				// Strict mode: drop cache-unsafe keys at runtime, even if a stale option contains them.
				if ( self::is_strict_mode() ) {
					$selected = array_values( array_diff( $selected, self::cache_unsafe_fields() ) );
					if ( empty( $selected ) ) {
						return null;
					}
				}

				$fields = self::collect_fields( $selected );

				/**
				 * Filter the auto-composed field array before JSON-encoding.
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
		 * Resolve a single field name to a value, or null if unavailable.
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

				case 'user_id':
					$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
					return $uid > 0 ? $uid : null;

				case 'user_role':
					if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
						$user = wp_get_current_user();
						return ! empty( $user->roles ) ? (string) reset( $user->roles ) : null;
					}
					return null;

				case 'request_path':
					if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
						return null;
					}
					return (string) strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' );

				case 'referrer_host':
					if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
						return null;
					}
					$host = wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST );
					return $host ? (string) $host : null;

				case 'utm_source':
					return isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : null;

				case 'utm_campaign':
					return isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : null;

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
