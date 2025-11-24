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
defined( 'ABSPATH' ) || exit;

class AgeWallet_API {

	/**
	 * The single instance of the class.
	 * @var AgeWallet_API|null
	 */
	private static $instance = null;

	/**
	 * The absolute path to the cache base directory.
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
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'agewallet-cache/';

		/**
		 * Filter: agewallet_cache_directory
		 * Allows developers to move the HTML cache to a custom location.
		 */
		$this->cache_dir = trailingslashit( apply_filters( 'agewallet_cache_directory', $base_dir ) );

		// Retrieve the loopback bypass secret.
		$this->bypass_secret = get_option( 'agewallet_loopback_secret' );

		// Self-Healing: Check if secret is missing or invalid.
		if ( empty( $this->bypass_secret ) || preg_match( '/[^a-zA-Z0-9]/', $this->bypass_secret ) ) {
			$this->bypass_secret = wp_generate_password( 64, false );
			update_option( 'agewallet_loopback_secret', $this->bypass_secret );
		}

		// Register the API Endpoint.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Cache Invalidation Hooks.
		add_action( 'save_post', array( $this, 'clear_post_cache' ), 10, 3 );
		add_action( 'wp_update_nav_menu', array( $this, 'clear_all_cache' ) );
		add_action( 'switch_theme', array( $this, 'clear_all_cache' ) );

		// Loopback Request Handling.
		add_action( 'template_redirect', array( $this, 'handle_loopback_request' ), 0 );
		// Inject Cache Type Header during loopback.
		add_action( 'wp_headers', array( $this, 'add_cache_type_headers' ) );
	}

	/**
	 * Registers the secure content retrieval endpoint.
	 */
	public function register_routes() {
		register_rest_route(
			'agewallet/v1',
			'/content',
			array(
				// Accept POST methods to bypass aggressive GET caching (Varnish/WP Engine).
				'methods'             => array( 'GET', 'POST' ),
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

		// 2. Determine Target.
		// Support params in both Body (POST) and Query (GET).
		$target_url = $request->get_param( 'url' );
		$post_id    = (int) $request->get_param( 'id' );

		if ( empty( $target_url ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'missing_url' ), 400 );
		}

		// Generate MD5 Hash of the URL for the filename.
		$url_hash = md5( $target_url );

		// 3. Check File Cache (Hit).
		$cached_html = $this->read_cache( $post_id, $url_hash );

		if ( $cached_html ) {
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

		// 4. Cache Miss: Build Cache via Loopback.
		$html = $this->build_cache( $target_url, $post_id, $url_hash );

		if ( is_wp_error( $html ) ) {
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

		$html = apply_filters( 'agewallet_api_content_response', $html, $post_id, 'fresh' );

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
	 */
	private function is_user_verified() {
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
	 * Looks in /singular/ or /archives/ based on the ID provided by Gatekeeper.
	 */
	private function read_cache( $post_id, $url_hash ) {
		$path = $this->get_cache_path( $post_id, $url_hash );
		if ( file_exists( $path ) ) {
			return file_get_contents( $path );
		}
		return false;
	}

	/**
	 * Helper to determine file path.
	 * If ID > 0, it's singular. If 0, it's an archive.
	 */
	private function get_cache_path( $post_id, $url_hash ) {
		if ( $post_id > 0 ) {
			return $this->cache_dir . 'singular/post-' . $post_id . '-' . $url_hash . '.html';
		} else {
			return $this->cache_dir . 'archives/' . $url_hash . '.html';
		}
	}

	/**
	 * Performs a loopback HTTP request to capture HTML.
	 */
	private function build_cache( $target_url, $post_id, $url_hash ) {
		// Add the secret bypass key to the URL.
		$url = add_query_arg( 'aw_cache_bypass', $this->bypass_secret, $target_url );

		$url = apply_filters( 'agewallet_loopback_url', $url, $post_id );

		$args = array(
			'timeout'   => 15,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'   => array(), // Request as a public, unauthenticated user
		);
		$args = apply_filters( 'agewallet_loopback_request_args', $args, $post_id );

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error( 'http_error', 'Loopback returned status ' . $response_code );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return new WP_Error( 'empty_response', 'Empty response body.' );
		}

		// Determine cache type from Header response (set by add_cache_type_headers)
		// Default to provided ID logic if header missing.
		$type_header = wp_remote_retrieve_header( $response, 'X-AW-Cache-Type' );

		// Logic: If Loopback says "archive", force ID to 0 to save in /archives/ folder.
		if ( 'archive' === $type_header ) {
			$post_id = 0;
		}

		$this->write_cache( $post_id, $url_hash, $html );

		return $html;
	}

	/**
	 * Writes HTML content to the file system.
	 */
	private function write_cache( $post_id, $url_hash, $html ) {
		$sub_dir  = ( $post_id > 0 ) ? 'singular/' : 'archives/';
		$full_dir = $this->cache_dir . $sub_dir;

		if ( ! is_dir( $full_dir ) ) {
			if ( ! wp_mkdir_p( $full_dir ) ) {
				return false;
			}
			// Security silence file
			file_put_contents( $full_dir . 'index.php', '<?php // Silence.' );
		}

		$file_path = $this->get_cache_path( $post_id, $url_hash );
		return (bool) file_put_contents( $file_path, $html );
	}

	/**
	 * Intercepts Loopback to set constants.
	 */
	public function handle_loopback_request() {
		$param_secret = isset( $_GET['aw_cache_bypass'] ) ? stripslashes( $_GET['aw_cache_bypass'] ) : '';

		if ( isset( $_GET['aw_cache_bypass'] ) && hash_equals( $this->bypass_secret, $param_secret ) ) {
			if ( ! defined( 'AGEWALLET_CACHE_BUILDING' ) ) {
				define( 'AGEWALLET_CACHE_BUILDING', true );
			}
			// Disable optimization plugins...
			if ( ! defined( 'DONOTROCKETOPTIMIZE' ) ) define( 'DONOTROCKETOPTIMIZE', true );
			if ( ! defined( 'DONOTMINIFY' ) ) define( 'DONOTMINIFY', true );
		}
	}

	/**
	 * Adds a header to the loopback response indicating page type.
	 * Crucial for distinguishing Archives vs Singular during cache build.
	 */
	public function add_cache_type_headers( $headers ) {
		if ( defined( 'AGEWALLET_CACHE_BUILDING' ) && AGEWALLET_CACHE_BUILDING ) {
			if ( is_archive() || is_home() || is_search() ) {
				$headers['X-AW-Cache-Type'] = 'archive';
			} elseif ( is_singular() ) {
				$headers['X-AW-Cache-Type'] = 'singular';
			}
		}
		return $headers;
	}

	/**
	 * Invalidation Logic.
	 */
	public function clear_post_cache( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( 'revision' === $post->post_type ) return;

		// 1. Delete Singular Cache for this Post (all variations)
		$singular_dir = $this->cache_dir . 'singular/';
		// Pattern: post-{id}-*.html
		$files = glob( $singular_dir . 'post-' . $post_id . '-*.html' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				@unlink( $file );
			}
		}

		// 2. Delete ALL Archive Caches
		// Because we don't know which archives this post appears on.
		$archive_dir = $this->cache_dir . 'archives/';
		$archives    = glob( $archive_dir . '*.html' );
		if ( is_array( $archives ) ) {
			foreach ( $archives as $file ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Nuke everything (Themes/Menus changed or Manual Purge).
	 */
	public function clear_all_cache() {
		$count = 0;
		// Clear Singular
		$files = glob( $this->cache_dir . 'singular/*.html' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( @unlink( $file ) ) $count++;
			}
		}
		// Clear Archives
		$files = glob( $this->cache_dir . 'archives/*.html' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( @unlink( $file ) ) $count++;
			}
		}
		return $count;
	}

	public function __clone() { _doing_it_wrong( __FUNCTION__, esc_html__( 'Forbidden', 'agewallet' ), '1.1.0' ); }
	public function __wakeup() { _doing_it_wrong( __FUNCTION__, esc_html__( 'Forbidden', 'agewallet' ), '1.1.0' ); }
}