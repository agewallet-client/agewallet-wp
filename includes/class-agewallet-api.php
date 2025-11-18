<?php
/**
 * AgeWallet API & Cache Handler
 *
 * Manages the secure REST API endpoint for content retrieval and the
 * file-based caching system used in "Strict Mode".
 *
 * @package AgeWalletOIDCClient
 * @since   1.1.0
 */

// Prevent direct script access.
defined('ABSPATH') || exit;

class AgeWallet_API {

	/**
	 * The single instance of the class.
	 * @var AgeWallet_API|null
	 */
	private static $instance = null;

	/**
	 * The absolute path to the cache directory.
	 * @var string
	 */
	private $cache_dir = '';

	/**
	 * The secret key used to authorize loopback requests for cache building.
	 * @var string
	 */
	private $bypass_secret = '';

	/**
	 * Ensures only one instance of the class is loaded.
	 * @return AgeWallet_API Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Sets up paths, secrets, and hooks.
	 */
	private function __construct() {
		// Define cache directory: /wp-content/uploads/agewallet-cache/
		$upload_dir      = wp_upload_dir();
		$this->cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'agewallet-cache/';

		// Retrieve or generate the loopback bypass secret.
		$this->bypass_secret = get_option( 'agewallet_loopback_secret' );
		if ( empty( $this->bypass_secret ) ) {
			$this->bypass_secret = wp_generate_password( 64, true, true );
			update_option( 'agewallet_loopback_secret', $this->bypass_secret );
		}

		// Register the API Endpoint.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Cache Invalidation Hooks.
		add_action( 'save_post', array( $this, 'clear_post_cache' ), 10, 3 );
		add_action( 'wp_update_nav_menu', array( $this, 'clear_all_cache' ) );
		add_action( 'switch_theme', array( $this, 'clear_all_cache' ) );
		// Hook for Customizer/Settings changes could be added here if needed.

		// Loopback Request Handling (runs early to set context).
		add_action( 'template_redirect', array( $this, 'handle_loopback_request' ), 0 );
	}

	/**
	 * Registers the secure content retrieval endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'agewallet/v1',
			'/content/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_content' ),
				// We handle permission checks manually to inspect cookies.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The API Callback: Returns full page HTML for verified users.
	 *
	 * @param WP_REST_Request $request The API request object.
	 * @return WP_REST_Response The JSON response containing HTML.
	 */
	public function get_content( $request ) {
		$post_id = (int) $request['id'];

		// 1. Security Check: Verify AgeWallet Cookie.
		if ( ! $this->is_user_verified() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'unverified',
					'message' => __( 'Age verification required.', 'agewallet' ),
				),
				403
			);
		}

		// 2. Check File Cache (Hit).
		$cached_html = $this->read_cache( $post_id );
		if ( $cached_html ) {
			// Allow developers to modify cached content before sending (e.g., dynamic nonces).
			$cached_html = apply_filters( 'agewallet_api_content_response', $cached_html, $post_id, 'cache' );

			return new WP_REST_Response(
				array(
					'success' => true,
					'html'    => $cached_html,
					'source'  => 'cache',
				),
				200
			);
		}

		// 3. Cache Miss: Build Cache via Loopback Request.
		$html = $this->build_cache( $post_id );

		if ( is_wp_error( $html ) ) {
			// Log the specific error for debugging.
			if ( class_exists( 'AgeWallet_Helpers' ) ) {
				AgeWallet_Helpers::instance()->log( 'API Build Cache Failed', array( 'error' => $html->get_error_message() ) );
			}

			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'build_failed',
					'message' => $html->get_error_message(),
				),
				500
			);
		}

		// Allow developers to modify fresh content before sending.
		$html = apply_filters( 'agewallet_api_content_response', $html, $post_id, 'fresh' );

		// 4. Return Fresh Content.
		return new WP_REST_Response(
			array(
				'success' => true,
				'html'    => $html,
				'source'  => 'fresh',
			),
			200
		);
	}

	/**
	 * Securely checks if the user has the verification cookie.
	 *
	 * @return bool True if verified, false otherwise.
	 */
	private function is_user_verified() {
		// Use constant if available (best practice), fallback to string.
		$cookie_name = defined( 'AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME' )
			? AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME
			: 'agewallet_verified';

		if ( ! isset( $_COOKIE[ $cookie_name ] ) || '1' !== $_COOKIE[ $cookie_name ] ) {
			return false;
		}
		return true;
	}

	/**
	 * Reads HTML content from the file system.
	 *
	 * @param int $post_id The ID of the post.
	 * @return string|false The HTML content or false if not found.
	 */
	private function read_cache( $post_id ) {
		$file_path = $this->cache_dir . 'page-' . $post_id . '.html';

		if ( file_exists( $file_path ) ) {
			// Optional: Check for TTL (Time To Live) here using filemtime if desired in future.
			return file_get_contents( $file_path );
		}
		return false;
	}

	/**
	 * Writes HTML content to the file system.
	 * Handles directory creation and index.php protection.
	 *
	 * @param int    $post_id The ID of the post.
	 * @param string $html    The full HTML content.
	 * @return bool True on success, false on failure.
	 */
	private function write_cache( $post_id, $html ) {
		// Create directory if it doesn't exist.
		if ( ! is_dir( $this->cache_dir ) ) {
			if ( ! wp_mkdir_p( $this->cache_dir ) ) {
				return false;
			}
			// Add silence is golden file for security.
			file_put_contents( $this->cache_dir . 'index.php', '<?php // Silence is golden.' );
		}

		$file_path = $this->cache_dir . 'page-' . $post_id . '.html';
		return (bool) file_put_contents( $file_path, $html );
	}

	/**
	 * Performs a loopback HTTP request to the site itself to capture the full page HTML.
	 *
	 * @param int $post_id The ID of the post to fetch.
	 * @return string|WP_Error The HTML content or error.
	 */
	private function build_cache( $post_id ) {
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return new WP_Error( 'invalid_id', 'Invalid Post ID or Permalink not found.' );
		}

		// Add the secret bypass key to the URL.
		// This tells handle_loopback_request() to allow full rendering.
		$url = add_query_arg( 'aw_cache_bypass', $this->bypass_secret, $permalink );

		// Prepare request arguments.
		$args = array(
			'timeout'   => 15,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // Often needed for local loopbacks.
			'cookies'   => array(), // We strictly want the "public" version of the page.
		);

		// Hook: Allow developers to modify loopback args (e.g., to pass specific cookies or headers).
		$args = apply_filters( 'agewallet_loopback_request_args', $args, $post_id );

		// Perform the request.
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error( 'http_error', 'Loopback request returned status ' . $response_code );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new WP_Error( 'empty_response', 'Empty response body from loopback request.' );
		}

		// Save the captured HTML to the cache.
		$this->write_cache( $post_id, $html );

		return $html;
	}

	/**
	 * Intercepts the page load early to detect a valid Loopback Request.
	 * If the secret key matches, it sets a constant.
	 *
	 * The Gating Manager will check this constant. If defined, it will NOT
	 * serve the Skeleton, allowing the full theme/Elementor to render so we can capture it.
	 *
	 * Hooked to: template_redirect (priority 0)
	 */
	public function handle_loopback_request() {
		// Verify the nonce/secret in the URL.
		if ( isset( $_GET['aw_cache_bypass'] ) && hash_equals( $this->bypass_secret, $_GET['aw_cache_bypass'] ) ) {
			// Define the flag constant.
			if ( ! defined( 'AGEWALLET_CACHE_BUILDING' ) ) {
				define( 'AGEWALLET_CACHE_BUILDING', true );
			}
			// We do not exit here. We let WordPress continue to load the template.
		}
	}

	/**
	 * Deletes the cache file for a specific post when it is updated.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function clear_post_cache( $post_id, $post, $update ) {
		// Avoid clearing on autosaves or revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'revision' === $post->post_type ) {
			return;
		}

		$file_path = $this->cache_dir . 'page-' . $post_id . '.html';
		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}
	}

	/**
	 * Clears the entire cache directory.
	 * Used for global changes (Menu updates, Theme switches).
	 */
	public function clear_all_cache() {
		if ( ! is_dir( $this->cache_dir ) ) {
			return;
		}

		$files = glob( $this->cache_dir . '*.html' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
			}
		}
	}

	/**
	 * Cloning forbidden.
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'agewallet' ), '1.1.0' );
	}

	/**
	 * Unserializing forbidden.
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing forbidden.', 'agewallet' ), '1.1.0' );
	}

}