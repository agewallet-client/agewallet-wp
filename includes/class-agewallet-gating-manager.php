<?php
// Prevent direct script access.
defined( 'ABSPATH' ) || exit;

/**
 * Manages the display and logic for age gating content.
 *
 * Handles shortcode registration ([agewallet_button], [agewallet_protected]),
 * CSS/JS enqueueing, gate HTML generation, automatic gating based on global settings,
 * and per-post meta box settings. Implements the Singleton pattern.
 *
 * @package AgeWalletOIDCClient
 * @since   0.1.0
 */
class AgeWallet_Gating_Manager {

	/**
	 * The single instance of the class.
	 * @since 0.1.0
	 * @var   AgeWallet_Gating_Manager|null
	 */
	private static $instance = null;

	/**
	 * Name of the client-side cookie used to track verification status.
	 * @since 0.1.0
	 */
	const VERIFIED_COOKIE_NAME = 'agewallet_verified';

	/**
	 * Handle for the gate stylesheet.
	 * @since 0.1.0
	 */
	const STYLE_HANDLE = 'agewallet-gate-style';

	/**
	 * Handle for the gate JavaScript.
	 * @since 0.1.0
	 */
	const SCRIPT_HANDLE = 'agewallet-gate-script';

	/**
	 * Meta key to force restriction on a specific post/page.
	 * @since 0.1.0
	 */
	const META_KEY_FORCE_RESTRICT = '_agewallet_force_restrict';

	/**
	 * Meta key to force exclusion from restriction on a specific post/page.
	 * @since 0.1.0
	 */
	const META_KEY_FORCE_EXCLUDE = '_agewallet_force_exclude';

	/**
	 * Flag to track if assets have been enqueued for the current request. Prevents double enqueueing.
	 * @since 0.1.0
	 * @var bool
	 */
	private $assets_enqueued = false;

	/**
	 * Ensures only one instance of the class is loaded.
	 * @since  0.1.0
	 * @static
	 * @return AgeWallet_Gating_Manager - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Hooks into WordPress actions and filters.
	 * Private to prevent direct object creation.
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->log_debug( '[Gating Manager] __construct started.' );

		// Front-end Hooks
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) ); // Register always
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_gate_assets' ), 20 ); // Conditionally enqueue later
		add_filter( 'body_class', array( $this, 'add_body_classes' ) ); // Add initial hiding class conditionally

		// Strict Mode Interception (New in 1.1.0)
		// Runs just before the template is included. Priority 99 ensures we override theme logic.
		// Filter added to allow priority adjustment (Dev Hook)
		$priority = apply_filters( 'agewallet_template_include_priority', 99 );
		add_filter( 'template_include', array( $this, 'intercept_template_loading' ), $priority );

		// Shortcode Hooks
		add_shortcode( 'agewallet_button', array( $this, 'button_shortcode_handler' ) );
		add_shortcode( 'agewallet_protected', array( $this, 'protected_content_shortcode_handler' ) );

		// Meta Box Hooks (Admin-side)
		add_action( 'add_meta_boxes', array( $this, 'add_restriction_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_restriction_meta_box' ) );

		$this->log_debug( '[Gating Manager] __construct finished, hooks added.' );
	}

	/**
	 * Registers front-end CSS and JS assets. Hooked to 'wp_enqueue_scripts'.
	 * @since 0.1.0
	 */
	public function register_assets() {
		// Register Stylesheet
		$style_path = 'assets/css/gate.css';
		$style_ver  = AGEWALLET_VERSION;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$file_path = AGEWALLET_PLUGIN_DIR . $style_path;
			if ( file_exists( $file_path ) ) {
				$style_ver = filemtime( $file_path ) ?: $style_ver;
			}
		}
		wp_register_style( self::STYLE_HANDLE, AGEWALLET_PLUGIN_URL . $style_path, array(), $style_ver, 'all' );

		// Register JavaScript
		$script_path = 'assets/js/gate.js';
		$script_ver  = AGEWALLET_VERSION;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$file_path = AGEWALLET_PLUGIN_DIR . $script_path;
			if ( file_exists( $file_path ) ) {
				$script_ver = filemtime( $file_path ) ?: $script_ver;
			}
		}
		wp_register_script( self::SCRIPT_HANDLE, AGEWALLET_PLUGIN_URL . $script_path, array( 'jquery' ), $script_ver, true );

		$this->log_debug( '[Gating Manager] Assets registered.' );
	}

/**
	 * Intercepts the template loading process to serve the Gatekeeper Skeleton
	 * when Strict Mode is enabled.
	 *
	 * @since 1.1.0
	 * @param string $template The path to the template WordPress intends to load.
	 * @return string The template path (modified if gated).
	 */
	public function intercept_template_loading( $template ) {
		// 1. Bypass if this is a Loopback Request (API building cache).
		// We check BOTH the constant (set by API class) AND the query string directly as a fail-safe.
		$bypass_secret = get_option( 'agewallet_loopback_secret' );

		// HMAC bypass-secret read; auth is hash_equals below, not a nonce. Server-to-server signed
		// request from our own build_cache() loopback, not a form submit.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$param_secret  = isset( $_GET['aw_cache_bypass'] ) ? sanitize_text_field( wp_unslash( $_GET['aw_cache_bypass'] ) ) : '';

		// --- DEBUGGING INSTRUMENTATION ---
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Only log if we are potentially dealing with a bypass attempt to reduce noise
			if ( ! empty( $param_secret ) ) {
				$this->log_debug( '--- BYPASS DEBUG ---' );
				$this->log_debug( 'Stored Secret: ' . $bypass_secret );
				$this->log_debug( 'Incoming Raw: ' . ( isset( $_GET['aw_cache_bypass'] ) ? sanitize_text_field( wp_unslash( $_GET['aw_cache_bypass'] ) ) : 'NULL' ) );
				$this->log_debug( 'Incoming Stripped: ' . $param_secret );
				$this->log_debug( 'Constant Defined: ' . ( defined( 'AGEWALLET_CACHE_BUILDING' ) ? 'YES' : 'NO' ) );
			}
		}
		// --- END DEBUGGING ---
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Check if secret exists and matches.
		$is_valid_bypass = ( ! empty( $bypass_secret ) && hash_equals( $bypass_secret, $param_secret ) );

		if ( ( defined( 'AGEWALLET_CACHE_BUILDING' ) && AGEWALLET_CACHE_BUILDING ) || $is_valid_bypass ) {
			$this->log_debug( 'Template Intercept: Bypassing for Cache Build (Loopback via Query/Const).' );

			// Also ensure W3TC Lazy Load is disabled for this request if it wasn't caught earlier.
			add_filter( 'w3tc_lazyload_can_process', '__return_false' );

			return $template;
		}

		// 2. Check if Strict Mode is enabled.
		$mode = get_option( 'agewallet_protection_mode', 'standard' );
		if ( 'strict' !== $mode ) {
			return $template;
		}

		// 3. Initial Checks (Admin, Feed, API, etc).
		if ( $this->is_excluded_context() ) {
			$this->log_debug( 'Template Intercept: Context excluded (Admin/Feed/Editor).' );
			return $template;
		}

		// 4. Check Content Rules (Is this specific page protected?).
		if ( ! $this->should_restrict_content() ) {
			$this->log_debug( 'Template Intercept: Content does not require gating.' );
			return $template;
		}

		// 4a. Strict-mode safety rail: never substitute a skeleton for dynamic WC pages.
		// The strict-mode cache stores the cookieless-loopback HTML keyed only by URL,
		// so caching /checkout/, /cart/, or /my-account/ would either render an empty
		// cart or leak one customer's HTML to another. Fall back to the normal template
		// so WC renders the live page; the gate JS still draws the overlay on top.
		if ( class_exists( 'AgeWallet_WooCommerce' ) && AgeWallet_WooCommerce::is_dynamic_wc_page() ) {
			$this->log_debug( 'Template Intercept: Bypassing skeleton for dynamic WC page (checkout/cart/account).' );
			return $template;
		}

		// 5. Serve the Skeleton (UNCONDITIONALLY).
		// We specifically removed the cookie check here to ensure Cloudflare always caches the Skeleton.
		// The hydration logic in gate.js will handle verified users.
		$this->log_debug( 'Template Intercept: Serving Gatekeeper Skeleton (Strict Mode Active).' );
		$skeleton_path = AGEWALLET_PLUGIN_DIR . 'templates/gatekeeper.php';
		if ( file_exists( $skeleton_path ) ) {
			// Allow developers to swap the skeleton template.
			return apply_filters( 'agewallet_skeleton_template', $skeleton_path );
		}

		$this->log_debug( 'Template Intercept ERROR: Skeleton file not found at ' . $skeleton_path );
		return $template;
	}

	/**
	 * Determines if the current page view requires gating or uses protected content,
	 * and enqueues assets if needed. Hooked to 'wp_enqueue_scripts' priority 20.
	 *
	 * @since 0.1.0
	 */
	public function maybe_enqueue_gate_assets() {
		$this->log_debug( '[Gating Manager] Checking if gate assets should be enqueued.' );

		// Use centralized check for context/rules.
		$should_restrict = $this->should_restrict_content();

		// Check for verified cookie (server-side).
		$is_verified = false;
		// Use Helper to verify HMAC signature
		if ( isset( $_COOKIE[ self::VERIFIED_COOKIE_NAME ] ) && class_exists('AgeWallet_Helpers') ) {
			 $is_verified = AgeWallet_Helpers::instance()->verify_signed_cookie(
				sanitize_text_field( wp_unslash( $_COOKIE[ self::VERIFIED_COOKIE_NAME ] ) )
			);
		}

		if ( ! $should_restrict || $is_verified ) {
			$this->log_debug( 'Final Decision: No assets needed (Not restricted or already verified).' );
			return;
		}

		// Enqueue Assets and Localize.
		if ( ! $this->assets_enqueued ) {
			$this->log_debug( 'Final Decision: Enqueuing assets.' );

			wp_enqueue_style( self::STYLE_HANDLE );
			wp_enqueue_script( self::SCRIPT_HANDLE );

			$script_data = array(
				'cookieName'       => self::VERIFIED_COOKIE_NAME,
				'bodyClassPending' => 'agewallet-gated-pending',
				'gateHtml'         => '',
				'isOverlayActive'  => false,
			);

			// Since should_restrict_content() is true, we need the gate.
			// Note: In Strict Mode, this enqueuing might be redundant if intercept_template_loading
			// swapped the template, but it's harmless.
			$script_data['gateHtml']        = $this->get_gate_html();
			$script_data['isOverlayActive'] = true;
			$this->log_debug( 'Localizing script data including overlay HTML.' );

			// Signal to add body class. Double-underscore prefix marks this as an internal,
			// not-publicly-documented hook; plugin-check's regex misses the prefix, so annotate.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			add_filter( '__agewallet_is_gating_this_request', '__return_true' );

			// HOOK: Allow developers to add or modify data passed to the front-end script.
			$script_data = apply_filters( 'agewallet_gate_script_data', $script_data );

			wp_localize_script( self::SCRIPT_HANDLE, 'agewallet_gate_data', $script_data );
			$this->assets_enqueued = true;

		} elseif ( $this->assets_enqueued ) {
			$this->log_debug( 'Assets already enqueued for this request.' );
		}
	}

	/**
	 * Centralized logic to determine if the current request should be gated.
	 * Checks Context, Meta, Global Settings, and Shortcodes.
	 *
	 * @since 1.1.0 Updated to accept optional Post ID for API checks.
	 * @param int|null $context_post_id Optional. The post ID to check (for API requests).
	 * @return bool True if content should be restricted.
	 */
	public function should_restrict_content( $context_post_id = null ) {
		// 1. Context Checks.
		// If we are running an API check ($context_post_id is set), we skip the context check (admin/cli)
		// because the API itself is a REST request, which is normally excluded.
		if ( is_null( $context_post_id ) && $this->is_excluded_context() ) {
			return false;
		}

		// Determine the Post ID to check (passed or current).
		$post_id = $context_post_id;
		if ( ! $post_id && is_singular() ) {
			$post_id = get_the_ID();
		}

		// 2. Check Post Meta Settings (Highest Priority).
		if ( $post_id ) {
			$force_exclude  = get_post_meta( $post_id, self::META_KEY_FORCE_EXCLUDE, true );
			$force_restrict = get_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT, true );

			if ( '1' === $force_exclude ) {
				$this->log_debug( 'Rule Check: Post excluded via Meta.', array( 'post_id' => $post_id ) );
				return false;
			} elseif ( '1' === $force_restrict ) {
				$this->log_debug( 'Rule Check: Post restricted via Meta.', array( 'post_id' => $post_id ) );
				return true;
			}
		}

		// 3. Global Exclusion Paths (Prioritized before Global Rules).
		$excluded_paths_str = get_option( 'agewallet_excluded_paths', '' );
		if ( ! empty( $excluded_paths_str ) ) {
			// Determine path to check.
			if ( $post_id ) {
				// API Context: Get path from permalink.
				$url  = get_permalink( $post_id );
				$path = wp_parse_url( $url, PHP_URL_PATH );
			} else {
				// Current Request Context.
				$path = strtok( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', '?' );
			}
			$current_path_norm = trailingslashit( $path );
			$excluded_paths    = array_map( 'trim', explode( ',', $excluded_paths_str ) );

			foreach ( $excluded_paths as $ex_path ) {
				if ( empty( $ex_path ) || '/' !== $ex_path[0] ) {
					continue;
				}
				// Normalize paths for matching.
				$ex_path_norm = trailingslashit( $ex_path );
				if ( strpos( $current_path_norm, $ex_path_norm ) !== false || ( '/' === $ex_path && '/' === $path ) ) {
					$this->log_debug( 'Rule Check: Path excluded by Global Exception.', array( 'path' => $ex_path ) );
					return false; // Explicitly excluded.
				}
			}
		}

		// Check Taxonomy Rules
		// Priority: Term Exclusion > Term Gate
		if ( $post_id ) {
			$tax_rules = get_option( 'agewallet_taxonomy_rules', array() );
			// Dev Hook to modify rules dynamically
			$tax_rules = apply_filters( 'agewallet_taxonomy_rules', $tax_rules );

			if ( ! empty( $tax_rules ) && is_array( $tax_rules ) ) {
				$gate_signal = false; // Store gate signal, but allow loop to continue looking for exclusions

				foreach ( $tax_rules as $tax => $data ) {
					$mode = isset( $data['mode'] ) ? $data['mode'] : 'ignore';
					if ( 'ignore' === $mode ) continue;

					// Check if post has terms in this taxonomy
					$post_terms = get_the_terms( $post_id, $tax );
					if ( ! $post_terms || is_wp_error( $post_terms ) ) continue;

					// Extract IDs
					$post_term_ids = wp_list_pluck( $post_terms, 'term_id' );

					// A. Check Exclusions FIRST (Trump card inside taxonomy logic)
					if ( 'exclude_all' === $mode ) {
						$this->log_debug( 'Rule Check: Allowed by Taxonomy (Exclude All).', array( 'taxonomy' => $tax ) );
						return apply_filters( 'agewallet_should_gate_request', false, $post_id );
					}
					elseif ( 'specific' === $mode && ! empty( $data['terms_exclude'] ) ) {
						$excluded_term_ids = array_map( 'absint', explode( ',', $data['terms_exclude'] ) );
						if ( array_intersect( $post_term_ids, $excluded_term_ids ) ) {
							$this->log_debug( 'Rule Check: Allowed by Taxonomy (Specific Exclusion).', array( 'taxonomy' => $tax ) );
							return apply_filters( 'agewallet_should_gate_request', false, $post_id );
						}
					}

					// B. Check Gating (If no exclusion found yet)
					if ( 'gate_all' === $mode ) {
						$gate_signal = true;
						$this->log_debug( 'Rule Check: Matched Gate All.', array( 'taxonomy' => $tax ) );
					}
					elseif ( 'specific' === $mode && ! empty( $data['terms_gate'] ) ) {
						$gated_term_ids = array_map( 'absint', explode( ',', $data['terms_gate'] ) );
						if ( array_intersect( $post_term_ids, $gated_term_ids ) ) {
							$gate_signal = true;
							$this->log_debug( 'Rule Check: Matched Specific Gate.', array( 'taxonomy' => $tax ) );
						}
					}
				}

				// If we found a gate signal and NO exclusion signal (we would have returned false already), return true.
				if ( $gate_signal ) {
					return apply_filters( 'agewallet_should_gate_request', true, $post_id );
				}
			}
		}

		// 4a. WooCommerce checkout — additive override. Gates the checkout
		// regardless of block_mode/paths/taxonomy, in one of two modes:
		//   - force-always:        every checkout gates (current binary behavior)
		//   - conditional-on-cart: gates only when an unverified visitor's cart
		//                          contains at least one item flagged as regulated
		if (
			class_exists( 'WooCommerce' )
			&& class_exists( 'AgeWallet_WooCommerce' )
			&& is_null( $context_post_id ) // API/post-context callers skip this; this is a request-level rule.
			&& AgeWallet_WooCommerce::is_checkout_request()
		) {
			$mode = AgeWallet_WooCommerce::checkout_gating_mode();

			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS === $mode ) {
				$this->log_debug( 'Rule Check: WC checkout force-always enabled and request is checkout.' );
				return apply_filters( 'agewallet_should_gate_request', true, $post_id );
			}

			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART === $mode ) {
				// Short-circuit: already-verified visitors don't need cart inspection
				// (and we don't want to pay for the per-item meta reads on every page hit).
				$is_verified = false;
				if ( isset( $_COOKIE[ self::VERIFIED_COOKIE_NAME ] ) && class_exists( 'AgeWallet_Helpers' ) ) {
					$is_verified = AgeWallet_Helpers::instance()->verify_signed_cookie(
						sanitize_text_field( wp_unslash( $_COOKIE[ self::VERIFIED_COOKIE_NAME ] ) )
					);
				}
				if ( $is_verified ) {
					$this->log_debug( 'Rule Check: WC checkout conditional-on-cart, visitor already verified — skipping cart inspection.' );
					return false;
				}
				if ( AgeWallet_WooCommerce::cart_contains_regulated_items() ) {
					$this->log_debug( 'Rule Check: WC checkout conditional-on-cart, cart contains regulated items — gating.' );
					return apply_filters( 'agewallet_should_gate_request', true, $post_id );
				}
				$this->log_debug( 'Rule Check: WC checkout conditional-on-cart, no regulated items in cart — not gating.' );
				return false;
			}

			// WC_GATE_MODE_OFF — fall through to general block_mode logic below.
		}

		// 4. Check Global Settings.
		$global_block_mode      = get_option( AgeWalletOIDCClientPro::OPT_BLOCK_MODE, 'none' );
		$global_requires_gating = false;

		switch ( $global_block_mode ) {
			case 'all':
				$global_requires_gating = true;
				break;
			case 'all_but_home':
				// If checking a specific ID (API), check if it's front page.
				if ( $post_id ) {
					$front_id = (int) get_option( 'page_on_front' );
					if ( $post_id !== $front_id ) {
						$global_requires_gating = true;
					}
				} elseif ( ! is_front_page() ) { // Fallback to conditional tag.
					$global_requires_gating = true;
				}
				break;
			case 'specific':
				$blocked_paths_str = get_option( AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, '' );
				if ( ! empty( $blocked_paths_str ) ) {
					$blocked_paths = array_map( 'trim', explode( ',', $blocked_paths_str ) );
					// Determine path to check.
					if ( $post_id ) {
						// API Context: Get path from permalink.
						$url  = get_permalink( $post_id );
						$path = wp_parse_url( $url, PHP_URL_PATH );
					} else {
						// Current Request Context.
						$path = strtok( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', '?' );
					}
					$current_path_norm = trailingslashit( $path );

					foreach ( $blocked_paths as $blocked_path ) {
						if ( empty( $blocked_path ) || '/' !== $blocked_path[0] ) {
							continue;
						}
						$blocked_path_norm = trailingslashit( $blocked_path );
						if ( strpos( $current_path_norm, $blocked_path_norm ) !== false || ( '/' === $blocked_path && '/' === $path ) ) {
							$global_requires_gating = true;
							$this->log_debug( 'Rule Check: Path match.', array( 'path' => $blocked_path ) );
							break;
						}
					}
				}
				break;
			default: // 'none'.
				break;
		}

		if ( $global_requires_gating ) {
			return apply_filters( 'agewallet_should_gate_request', true, $post_id );
		}

		// 5. Check Shortcode Presence.
		// We skip this check in Strict Mode because shortcodes do not trigger gating in that mode.
		if ( 'strict' === get_option( 'agewallet_protection_mode', 'standard' ) ) {
			return apply_filters( 'agewallet_should_gate_request', false, $post_id );
		}

		// Normal mode check for shortcode.
		if ( $post_id ) {
			$post_obj = get_post( $post_id );
			if ( isset( $post_obj ) && has_shortcode( $post_obj->post_content, 'agewallet_protected' ) ) {
				$this->log_debug( 'Rule Check: Shortcode found.' );
				return true;
			}
		}

		return apply_filters( 'agewallet_should_gate_request', false, $post_id );
	}

	/**
	 * Helper to check if the current context is excluded from gating (Admin, Feed, etc).
	 * @return bool
	 */
	private function is_excluded_context() {
		if ( is_admin() || defined( 'WP_CLI' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}
		if ( is_feed() || is_preview() || is_customize_preview() ) {
			return true;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_others_posts' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Adds body class for pending state.
	 * @since 0.1.0
	 */
	public function add_body_classes( $classes ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- See add_filter() site; internal hook, double-underscore prefix.
		if ( apply_filters( '__agewallet_is_gating_this_request', false ) ) {
			$classes[] = 'agewallet-gated-pending';
		}
		return $classes;
	}

	// --- Shortcode Handlers ---

	/**
	 * Handles the [agewallet_button] shortcode.
	 * @since 0.1.0
	 */
	public function button_shortcode_handler( $atts = [] ) {
		$this->log_debug( '[Gating Manager] Button shortcode handler called.' );

		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
			$this->log_debug( '[Gating Manager] Gate style enqueued via button shortcode (fallback).' );
		}

		$output_html = $this->get_gate_html();
		if ( ! empty( $output_html ) ) {
			return '<div class="agewallet-shortcode-wrapper">' . $output_html . '</div>';
		}
		return '';
	}

	/**
	 * Handles the [agewallet_protected]...[/agewallet_protected] shortcode.
	 * @since 0.1.0
	 */
	public function protected_content_shortcode_handler( $atts = [], $content = null ) {
		$this->log_debug( '[Gating Manager] Protected content shortcode handler called.' );

		// 1. Loopback Bypass (Fix for Strict Mode Cache Build).
		if ( defined( 'AGEWALLET_CACHE_BUILDING' ) && AGEWALLET_CACHE_BUILDING ) {
			$this->log_debug( '[Gating Manager] Shortcode bypassed for Cache Build.' );
			return do_shortcode( $content );
		}

		// 2. Strict Mode Bypass (Disable shortcode protection logic).
		if ( 'strict' === get_option( 'agewallet_protection_mode', 'standard' ) ) {
			return do_shortcode( $content );
		}

		// 3. Standard Checks.
		if ( is_user_logged_in() && current_user_can( 'edit_others_posts' ) ) {
			$this->log_debug( '[Gating Manager] Protected shortcode skipped: User is Admin or Editor.' );
			return do_shortcode( $content );
		}

		// Check for Signed Cookie using AgeWallet_Helpers
		if ( isset( $_COOKIE[ self::VERIFIED_COOKIE_NAME ] ) && class_exists('AgeWallet_Helpers') ) {
			 if ( AgeWallet_Helpers::instance()->verify_signed_cookie(
				sanitize_text_field( wp_unslash( $_COOKIE[ self::VERIFIED_COOKIE_NAME ] ) )
			) ) {
				 $this->log_debug( '[Gating Manager] Protected shortcode: User is verified (HMAC check), showing content.' );
				 return do_shortcode( $content );
			 }
		}

		// Ensure assets are loaded if maybe_enqueue_gate_assets didn't run or detect it early enough.
		if ( ! $this->assets_enqueued ) {
			$this->log_debug( '[Gating Manager] Enqueuing assets from protected_content_shortcode_handler (fallback needed).' );

			// --- Manually register scripts/styles first, in case register_assets() hasn't run ---
			// This logic is duplicated from register_assets() to make this fallback robust.

			// Register Stylesheet.
			if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
				$style_path = 'assets/css/gate.css';
				$style_ver  = AGEWALLET_VERSION;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$file_path = AGEWALLET_PLUGIN_DIR . $style_path;
					if ( file_exists( $file_path ) ) {
						$style_ver = filemtime( $file_path ) ?: $style_ver;
					}
				}
				wp_register_style( self::STYLE_HANDLE, AGEWALLET_PLUGIN_URL . $style_path, array(), $style_ver, 'all' );
			}

			// Register JavaScript.
			if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
				$script_path = 'assets/js/gate.js';
				$script_ver  = AGEWALLET_VERSION;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$file_path = AGEWALLET_PLUGIN_DIR . $script_path;
					if ( file_exists( $file_path ) ) {
						$script_ver = filemtime( $file_path ) ?: $script_ver;
					}
				}
				wp_register_script( self::SCRIPT_HANDLE, AGEWALLET_PLUGIN_URL . $script_path, array( 'jquery' ), $script_ver, true );
			}
			// --- END REGISTRATION ---

			// Now enqueue them.
			wp_enqueue_style( self::STYLE_HANDLE );
			wp_enqueue_script( self::SCRIPT_HANDLE );

			// Check if data has been added (by this function or another).
			if ( ! wp_script_is( self::SCRIPT_HANDLE, 'data' ) ) {
				$script_data = array(
					'cookieName'       => self::VERIFIED_COOKIE_NAME,
					'bodyClassPending' => 'agewallet-gated-pending',
					'gateHtml'         => '',
					'isOverlayActive'  => false,
				);

				// --- Use wp_add_inline_script instead of wp_localize_script ---
				// This correctly adds the data object before the script tag, even when called late.
				$script_data_string = 'var agewallet_gate_data = ' . wp_json_encode( $script_data ) . ';';
				wp_add_inline_script( self::SCRIPT_HANDLE, $script_data_string, 'before' );
				// --- END INLINE SCRIPT ---

				$this->log_debug( '[GGating Manager] Injected minimal script data via shortcode fallback (wp_add_inline_script).' );
			} else {
				$this->log_debug( '[Gating Manager] Script data already localized, skipping in shortcode fallback.' );
			}
			$this->assets_enqueued = true;
		}

		$processed_content = do_shortcode( $content );
		$placeholder_html  = $this->get_gate_html();
		if ( empty( $placeholder_html ) ) {
			$placeholder_html = '<p style="color:red;">Error: Could not generate verification prompt.</p>';
		}

		// Output the wrapper structure. Content starts hidden, placeholder starts visible.
		$output  = '<div class="agewallet-protected-wrapper">';
		$output .= '<div class="agewallet-protected-content" style="display: none;">';
		$output .= $processed_content;
		$output .= '</div>';
		$output .= '<div class="agewallet-protected-placeholder" style="display: block;">';
		$output .= $placeholder_html;
		$output .= '</div>';
		$output .= '</div>';

		// Internally-escaped: $placeholder_html via get_gate_html(), $processed_content via WP shortcode pipeline.
		return $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Gate HTML internally escaped; $content goes through WP shortcode pipeline.
	}

	/**
	 * Generates the HTML markup for the age gate prompt.
	 * @since 0.1.0
	 * @access public
	 */
	public function get_gate_html() {
		$this->log_debug( '[Gating Manager] Generating gate HTML.' );

		$logo_id    = (int) get_option( AgeWalletOIDCClientPro::OPT_LOGO_ID, 0 );
		$logo_width = (int) get_option( AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, 0 );
		$copy_html  = get_option( AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, '' );
		$hide_h1    = (bool) get_option( AgeWalletOIDCClientPro::OPT_HIDE_HEADING, 0 );
		$logo_src   = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		if ( ! empty( $copy_html ) ) {
			$desc_html = wpautop( trim( $copy_html ) );
		} else {
			$default_copy = __( 'You must be 18+ to view this content (or meet the minimum age required by your local jurisdiction). By selecting “I Agree,” you confirm that you meet the minimum age requirement and consent to verification by our partner, AgeWallet™. If you do not meet the minimum age requirement or do not agree, please select “I Disagree.”', 'agewallet-oidc-client' );
			$desc_html    = '<p>' . esc_html( $default_copy ) . '</p>';
		}

		$current_url = AgeWallet_Helpers::instance()->get_current_url();
		$launch_url  = AgeWallet_Helpers::instance()->get_launch_url();
		$agree_href  = $launch_url ? add_query_arg( 'redirect_to', urlencode( $current_url ), $launch_url ) : '';

		// Compute metadata here — we have the correct WP context for the gated page.
		// Sign it so handle_launch() can trust the value despite it riding in a URL.
		if ( $agree_href && class_exists( 'AgeWallet_Metadata_Builder' ) ) {
			$md_value = AgeWallet_Metadata_Builder::build();
			if ( is_string( $md_value ) && '' !== $md_value ) {
				$signed_md  = AgeWallet_Helpers::instance()->sign_metadata( $md_value );
				$agree_href = add_query_arg( 'md', urlencode( $signed_md ), $agree_href );
			}
		}

		// Mark checkout-origin so handle_launch() knows to attach WC cart-context
		// metadata at click-time. The marker rides as a separate signed query param
		// so it stays out of the final metadata payload stored against the verification.
		if ( $agree_href && class_exists( 'AgeWallet_WooCommerce' ) && AgeWallet_WooCommerce::is_checkout_request() ) {
			$signed_origin = AgeWallet_Helpers::instance()->sign_metadata( 'checkout' );
			$agree_href    = add_query_arg( 'aw_o', urlencode( $signed_origin ), $agree_href );
		}

		if ( ! $agree_href ) {
			 $this->log_debug( '[Gating Manager] ERROR: Could not get launch URL for gate HTML.' );
			 return '<p style="color:red;">Error: Could not determine launch URL.</p>';
		}

		$args = array(
			'logo_src'         => $logo_src,
			'logo_alt'         => __( 'Logo', 'agewallet-oidc-client' ),
			'logo_width'       => $logo_width,
			'show_title'       => ! $hide_h1,
			'title'            => __( 'You Must Verify Your Age', 'agewallet-oidc-client' ),
			'description_html' => $desc_html,
			'agree_url'        => $agree_href,
			'agree_text'       => __( 'I Agree', 'agewallet-oidc-client' ),
			'disagree_text'    => __( 'I Disagree', 'agewallet-oidc-client' ),
			'error_text'       => __( 'Sorry, you do not meet the minimum requirements to view this content.', 'agewallet-oidc-client' ),
			'disclaimer_html'  => sprintf(
				/* translators: %s: URL to AgeWallet website */
				__( 'By proceeding you agree to allow %s to verify your age.', 'agewallet-oidc-client' ),
				'<a href="https://www.agewallet.com" target="_blank" rel="noopener noreferrer">AgeWallet™</a>'
			),
		);

		$args = apply_filters( 'agewallet_gate_template_args', $args );

		ob_start();
		?>
		<div class="aw-gate aw-gate__card">
			<?php if ( ! empty( $args['logo_src'] ) ) : ?>
				<div class="aw-gate__logo-wrap">
					<img class="aw-gate__logo"
						 src="<?php echo esc_url( $args['logo_src'] ); ?>"
						 alt="<?php echo esc_attr( $args['logo_alt'] ); ?>"
						<?php if ( ! empty( $args['logo_width'] ) && $args['logo_width'] > 0 ) : ?>
							 style="width:<?php echo intval( $args['logo_width'] ); ?>px; max-width:100%; height:auto;"
						<?php else : ?>
							 style="max-width:100%; height:auto;"
						<?php endif; ?>
					>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $args['show_title'] ) ) : ?>
				<h1 class="aw-gate__title"><?php echo esc_html( $args['title'] ); ?></h1>
			<?php endif; ?>

			<div class="aw-gate__desc">
				<?php echo wp_kses_post( $args['description_html'] ); ?>
			</div>

			<div class="aw-gate__buttons">
				<button class="aw-gate__btn aw-gate__btn--no" type="button" onclick="var err = this.closest('.aw-gate').querySelector('.aw-gate__error'); if(err) err.style.display='block'; return false;">
					<?php echo esc_html( $args['disagree_text'] ); ?>
				</button>
				<button class="aw-gate__btn aw-gate__btn--yes" type="button" data-redirect-url="<?php echo esc_url( $args['agree_url'] ); ?>">
					<?php echo esc_html( $args['agree_text'] ); ?>
				</button>
			</div>

			<?php do_action( 'agewallet_after_gate_buttons' ); ?>

			<div class="aw-gate__error" style="display:none;"><?php echo esc_html( $args['error_text'] ); ?></div>

			<p class="aw-gate__disclaimer">
				<?php
				echo wp_kses(
					$args['disclaimer_html'],
					array(
						'a' => array(
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
					)
				);
				?>
			</p>
		</div>
		<?php
		$html = ob_get_clean();
		$this->log_debug( '[Gating Manager] Finished generating gate HTML.', array( 'html_length' => strlen( $html ) ) );
		return $html;
	}

	// --- Meta Box Handling ---

	/**
	 * Registers the meta box on relevant post types. Hooked to 'add_meta_boxes'.
	 * @since 0.1.0
	 */
	public function add_restriction_meta_box() {
		$this->log_debug( '[Gating Manager] Adding meta boxes.' );
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		$post_types = apply_filters( 'agewallet_meta_box_post_types', $post_types );

		if ( empty( $post_types ) ) {
			$this->log_debug( '[Gating Manager] No public post types found to add meta box to.' );
			return;
		}

		$this->log_debug( '[Gating Manager] Adding meta box to post types.', array( 'post_types' => $post_types ) );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'agewallet_restriction_meta',
				__( 'Age Restriction', 'agewallet-oidc-client' ),
				array( $this, 'render_restriction_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the content of the Age Restriction meta box.
	 * @since 0.1.0
	 * @param WP_Post $post The current post object.
	 */
	public function render_restriction_meta_box( $post ) {
		wp_nonce_field( 'agewallet_save_restriction_meta_action', 'agewallet_restriction_nonce' );
		$force_restrict = get_post_meta( $post->ID, self::META_KEY_FORCE_RESTRICT, true );
		$force_exclude  = get_post_meta( $post->ID, self::META_KEY_FORCE_EXCLUDE, true );
		?>
		<p>
			<label for="agewallet_force_restrict">
				<input type="checkbox" id="agewallet_force_restrict" name="agewallet_force_restrict" value="1"
					<?php checked( $force_restrict, '1' ); ?>
					<?php echo ( '1' === $force_exclude ) ? 'disabled="disabled"' : ''; ?> />
				<?php esc_html_e( 'Require age verification', 'agewallet-oidc-client' ); ?>
			</label><br>
			<small><?php esc_html_e( "(Overrides Global 'None' setting)", 'agewallet-oidc-client' ); ?></small>
		</p>
		<hr style="margin: 10px 0;">
		<p>
			<label for="agewallet_force_exclude">
				<input type="checkbox" id="agewallet_force_exclude" name="agewallet_force_exclude" value="1"
					<?php checked( $force_exclude, '1' ); ?> />
				<?php esc_html_e( 'Exclude from age verification', 'agewallet-oidc-client' ); ?>
			</label><br>
			<small><?php esc_html_e( "(Overrides ALL Global settings)", 'agewallet-oidc-client' ); ?></small>
		</p>
		<p><small><?php esc_html_e( 'Note: If "Exclude" is checked, "Require" will be ignored.', 'agewallet-oidc-client' ); ?></small></p>
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				var restrictCheckbox = document.getElementById('agewallet_force_restrict');
				var excludeCheckbox = document.getElementById('agewallet_force_exclude');
				if (!restrictCheckbox || !excludeCheckbox) return;
				function toggleRestrict() { restrictCheckbox.disabled = excludeCheckbox.checked; if (excludeCheckbox.checked) restrictCheckbox.checked = false; }
				excludeCheckbox.addEventListener('change', toggleRestrict);
				toggleRestrict();
			});
		</script>
		<?php
	}

	/**
	 * Saves the custom meta box data when a post is saved. Hooked to 'save_post'.
	 * @since 0.1.0
	 * @param int $post_id The ID of the post being saved.
	 */
	public function save_restriction_meta_box( $post_id ) {
		// Check if nonce is set and valid.
		if ( ! isset( $_POST['agewallet_restriction_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['agewallet_restriction_nonce'] ), 'agewallet_save_restriction_meta_action' ) ) {
			$this->log_debug( 'Meta save aborted: Invalid nonce.', array( 'post_id' => $post_id ) );
			return;
		}
		// Check if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			$this->log_debug( 'Meta save skipped: Is autosave.', array( 'post_id' => $post_id ) );
			return;
		}
		// Check if it's a revision - prevent saving meta for revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			 $this->log_debug( 'Meta save skipped: Is revision.', array( 'post_id' => $post_id ) );
			 return;
		}
		// Check user permissions based on post type.
		$post_type         = get_post_type( $post_id );
		$public_post_types = apply_filters( 'agewallet_meta_box_post_types', get_post_types( array( 'public' => true ), 'names' ) );
		if ( ! in_array( $post_type, $public_post_types, true ) ) {
			$this->log_debug( 'Meta save skipped: Post type not applicable.', array(
				'post_id'   => $post_id,
				'post_type' => $post_type,
			) );
			return;
		}
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
			$this->log_debug( 'Meta save aborted: User lacks permission.', array(
				'post_id' => $post_id,
				'user_id' => get_current_user_id(),
			) );
			return;
		}

		$this->log_debug( '[Gating Manager] Saving restriction meta box data.', array( 'post_id' => $post_id ) );

		$force_exclude_value = filter_input( INPUT_POST, 'agewallet_force_exclude', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( true === $force_exclude_value ) {
			update_post_meta( $post_id, self::META_KEY_FORCE_EXCLUDE, '1' );
			delete_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT );
			$this->log_debug( 'Saved meta: force_exclude=1, force_restrict=deleted', array( 'post_id' => $post_id ) );
		} else {
			delete_post_meta( $post_id, self::META_KEY_FORCE_EXCLUDE );
			$force_restrict_value = filter_input( INPUT_POST, 'agewallet_force_restrict', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( true === $force_restrict_value ) {
				update_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT, '1' );
				$this->log_debug( 'Saved meta: force_exclude=deleted, force_restrict=1', array( 'post_id' => $post_id ) );
			} else {
				delete_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT );
				$this->log_debug( 'Saved meta: force_exclude=deleted, force_restrict=deleted', array( 'post_id' => $post_id ) );
			}
		}
		do_action( 'agewallet_meta_box_save', $post_id, $force_exclude_value, $force_restrict_value );
	}

	// --- Utility & Logging ---

	/** Helper method to log debug messages. @since 0.1.0 */
	private function log_debug( $message, $context = null ) {
		if ( class_exists( 'AgeWallet_Helpers' ) && method_exists( AgeWallet_Helpers::instance(), 'log' ) ) {
			 AgeWallet_Helpers::instance()->log( '[Gating Mgr] ' . $message, $context );
		} else {
			$log_entry = '[AgeWallet Plugin] [Gating Mgr] ' . $message;
			if ( ! is_null( $context ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Fallback debug formatter when central logger is unavailable; only runs under WP_DEBUG_LOG.
				$log_entry .= ' Context: ' . print_r( $context, true );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback debug output when central logger is unavailable; only runs under WP_DEBUG_LOG.
			error_log( preg_replace( '/\s+/', ' ', trim( $log_entry ) ) );
		}
	}

	// --- Singleton Pattern Boilerplate ---
	/** Cloning forbidden. @since 0.1.0 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'agewallet-oidc-client' ), '0.1.0' );
	}
	/** Unserializing forbidden. @since 0.1.0 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing forbidden.', 'agewallet-oidc-client' ), '0.1.0' );
	}

} // End class AgeWallet_Gating_Manager