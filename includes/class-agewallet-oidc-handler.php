<?php
 // Prevent direct script access.
 defined('ABSPATH') || exit;

 /**
  * Handles the OpenID Connect (OIDC) Authorization Code Flow with AgeWallet.
  *
  * Manages redirects, state/nonce/PKCE generation and validation using transients,
  * token exchange, and the final success handoff for client-side cookie setting.
  * Implements the Singleton pattern.
  *
  * @package AgeWalletOIDCClient
  * @since   0.1.0
  */
 class AgeWallet_OIDC_Handler {

     /**
      * The single instance of the class.
      * @since 0.1.0
      * @var   AgeWallet_OIDC_Handler|null
      */
     private static $instance = null;

     /**
      * Origin marker for the current /agewallet/launch request, set inside
      * handle_launch() from the signed `agewallet_origin` query param. Filter callbacks on
      * `agewallet_metadata` can read this via get_request_origin() to decide
      * whether the verification originated from a specific context (e.g., 'checkout').
      *
      * Null when no signed origin was present on the launch URL.
      *
      * @var string|null
      */
     private static $request_origin = null;

     /**
      * Returns the origin marker for the current verify-click request.
      * Set by handle_launch() from the signed `agewallet_origin` query param.
      *
      * @return string|null
      */
     public static function get_request_origin() {
         return self::$request_origin;
     }

     /**
      * Transient prefix for OIDC state data. Used to store PKCE verifier and redirect URL.
      * @since 0.1.0
      * @var string
      */
     const STATE_TRANSIENT_PREFIX = 'agewallet_oidc_state_';

     /**
      * Transient prefix for the success page handoff token. Used to pass the final redirect URL securely.
      * @since 0.1.0
      * @var string
      */
     const SUCCESS_TOKEN_PREFIX = 'agewallet_oidc_success_';

     /**
      * Ensures only one instance of the handler class is loaded.
      * Follows the Singleton pattern.
      *
      * @since  0.1.0
      * @static
      * @return AgeWallet_OIDC_Handler - The single instance.
      */
     public static function instance() {
         if ( is_null( self::$instance ) ) {
             self::$instance = new self();
         }
         return self::$instance;
     }

     /**
      * Constructor. Hooks into WordPress actions.
      * Private to prevent direct object creation (Singleton pattern).
      *
      * @since 0.1.0
      */
     private function __construct() {
         $this->log_debug('[OIDC Handler] __construct started.');

         // OIDC Endpoint Hooks
         add_action('init', array($this, 'register_rewrite_rules'), 11);
         add_filter('query_vars', array($this, 'register_query_vars'));
         add_action('template_redirect', array( $this, 'handle_redirects' ), 1); // Early priority

         // Hook to allow adding AgeWallet domain for safe redirects
         add_filter( 'wp_redirect_allowed_hosts', array( $this, 'add_allowed_redirect_hosts' ) );

         // Resolve per-visitor metadata fields (user_id, user_role) at click-time.
         // This filter fires inside handle_launch() — POST + nocache_headers() — so
         // each visitor's true identity is captured even when the gated page itself
         // is served from an external page cache (WP Engine, Cloudflare, W3TC, etc.).
         add_filter( 'agewallet_metadata', array( $this, 'merge_per_visitor_fields' ), 10, 1 );

         $this->log_debug('[OIDC Handler] __construct finished, hooks added.');
     }

     /**
      * Resolve per-visitor metadata fields at verify-click time and merge them
      * into the existing metadata payload.
      *
      * Page-context fields (post_id, request_path, term_*, etc.) are baked into
      * the signed md= URL at gate-render time, where they're safe to cache because
      * they're identical for every visitor to the same URL. Per-visitor fields
      * (user_id, user_role) bypass that path and resolve here instead so they
      * reflect the actual visitor — not whoever first cached the page.
      *
      * Composes with developer hooks on `agewallet_metadata`: we run at default
      * priority 10. Developer hooks at higher priority see our merged output and
      * can override or extend further.
      *
      * @param string|null $existing The metadata string built so far (from the
      *                              signed md= URL — JSON for auto mode, plain
      *                              text for static mode, or null).
      * @return string|null
      */
     public function merge_per_visitor_fields( $existing ) {
         // Only act in auto-JSON mode. Static-text metadata is opaque to us; we
         // don't presume to munge a user's literal string.
         if ( 'auto' !== get_option( 'agewallet_metadata_mode', 'static' ) ) {
             return $existing;
         }

         $selected = get_option( 'agewallet_auto_metadata_fields', array() );
         if ( ! is_array( $selected ) ) {
             return $existing;
         }

         $additions = array();

         if ( in_array( 'user_id', $selected, true ) ) {
             $uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
             if ( $uid > 0 ) {
                 $additions['user_id'] = $uid;
             }
         }

         if ( in_array( 'user_role', $selected, true ) ) {
             if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
                 $user = wp_get_current_user();
                 if ( ! empty( $user->roles ) ) {
                     $additions['user_role'] = (string) reset( $user->roles );
                 }
             }
         }

         if ( empty( $additions ) ) {
             return $existing;
         }

         // Merge into existing JSON (or seed a new one if there was none).
         $base = array();
         if ( is_string( $existing ) && '' !== $existing ) {
             $decoded = json_decode( $existing, true );
             if ( is_array( $decoded ) ) {
                 $base = $decoded;
             } else {
                 // Non-JSON existing value (shouldn't happen in auto mode, but be defensive).
                 return $existing;
             }
         }

         $merged = array_merge( $base, $additions );
         $json   = wp_json_encode( $merged );

         return is_string( $json ) ? $json : $existing;
     }

     // --- OIDC Endpoint Handling ---

     /**
      * Register custom rewrite rules.
      * Runs on init hook to ensure rules are available. Flushed on activation/deactivation.
      * @since 0.1.0
      */
     public function register_rewrite_rules() {
         add_rewrite_rule('^agewallet/launch/?$',   'index.php?' . AgeWalletOIDCClientPro::QV_LAUNCH . '=1',   'top');
         add_rewrite_rule('^agewallet/callback/?$', 'index.php?' . AgeWalletOIDCClientPro::QV_CALLBACK . '=1', 'top');
         add_rewrite_rule('^agewallet/success/?$',  'index.php?' . AgeWalletOIDCClientPro::QV_SUCCESS . '=1',  'top');
     }

     /**
      * Add custom query vars needed for rewrite rules.
      * @since 0.1.0
      * @param array $vars Existing query vars.
      * @return array Modified query vars.
      */
     public function register_query_vars($vars) {
         if (!is_array($vars)) $vars = []; // Ensure it's an array
         $vars[] = AgeWalletOIDCClientPro::QV_LAUNCH;
         $vars[] = AgeWalletOIDCClientPro::QV_CALLBACK;
         $vars[] = AgeWalletOIDCClientPro::QV_SUCCESS;
         return $vars;
     }

     /**
      * Routes requests for OIDC endpoints based on URI or query vars.
      * Hooked to 'template_redirect' at priority 1.
      * @since 0.1.0
      */
     public function handle_redirects() {
         // Get the path part of the request URI, removing query string and trailing slash if present.
         $raw_uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
         $current_path = strtok( $raw_uri, '?' );
         if ( '/' !== $current_path && str_ends_with( $current_path, '/' ) ) {
             $current_path = substr( $current_path, 0, -1 );
         }

         $this->log_debug( '[OIDC Handler] handle_redirects called.', array( 'raw_uri' => $raw_uri ?: 'N/A', 'parsed_path' => $current_path ) );

         $matched_handler = null;
         // --- Direct URI Check First (More reliable early) ---
         if ( '/agewallet/launch' === $current_path ) {
             $this->log_debug('[OIDC Handler] Matched URI directly: /agewallet/launch. Routing...');
             $matched_handler = 'launch'; $this->handle_launch(); exit; // Exit after handling
         } elseif ( '/agewallet/callback' === $current_path ) {
             $this->log_debug('[OIDC Handler] Matched URI directly: /agewallet/callback. Routing...');
             $matched_handler = 'callback'; $this->handle_callback(); exit; // Exit after handling
         } elseif ( '/agewallet/success' === $current_path ) {
             $this->log_debug('[OIDC Handler] Matched URI directly: /agewallet/success. Routing...');
             $matched_handler = 'success'; $this->handle_success(); exit; // Exit after handling
         }

         // --- Fallback Query Var Check (In case direct URI check fails or rules change) ---
         if ( ! $matched_handler ) {
             $is_launch   = ( 1 === (int) get_query_var( AgeWalletOIDCClientPro::QV_LAUNCH ) );
             $is_callback = ( 1 === (int) get_query_var( AgeWalletOIDCClientPro::QV_CALLBACK ) );
             $is_success  = ( 1 === (int) get_query_var( AgeWalletOIDCClientPro::QV_SUCCESS ) );

             $this->log_debug('[OIDC Handler] Query Var Status.', ['launch' => $is_launch, 'callback' => $is_callback, 'success' => $is_success]);

             if ( $is_launch ) {
                 $this->log_debug('[OIDC Handler] Matched Query Var: launch. Routing...');
                 $this->handle_launch(); exit; // Exit after handling
             } elseif ( $is_callback ) {
                 $this->log_debug('[OIDC Handler] Matched Query Var: callback. Routing...');
                 $this->handle_callback(); exit; // Exit after handling
             } elseif ( $is_success ) {
                 $this->log_debug('[OIDC Handler] Matched Query Var: success. Routing...');
                 $this->handle_success(); exit; // Exit after handling
             }
         }

         // If none of the above matched, do nothing and let WordPress continue.
         // $this->log_debug('[OIDC Handler] No OIDC endpoint matched, continuing standard WP load.'); // Can be verbose
     }

     /**
      * Handles the '/agewallet/launch/' request.
      * Generates state, PKCE codes, stores them in a transient,
      * and redirects the user to the AgeWallet authorization endpoint via PHP header.
      *
      * @since 0.1.0
      */
     private function handle_launch() {
         $this->log_debug('[OIDC Handler] Inside handle_launch.');
         nocache_headers(); // Prevent caching of this endpoint

         $client_id = get_option(AgeWalletOIDCClientPro::OPT_CLIENT_ID);
         if ( empty($client_id) ) {
             $this->log_debug('[OIDC Handler] Launch aborted: Client ID not configured.');
             wp_die(
                 esc_html__('AgeWallet OIDC Client ID is not configured. Please contact the site administrator.', 'agewallet-oidc-client'),
                 esc_html__('Configuration Error', 'agewallet-oidc-client'),
                 array('response' => 400) // Bad Request
             );
         }

         // Generate cryptographically secure values using helpers.
         $state = AgeWallet_Helpers::instance()->generate_random_hex(32); // CSRF protection
         $code_verifier = AgeWallet_Helpers::instance()->generate_pkce_verifier(); // PKCE Verifier
         $code_challenge = AgeWallet_Helpers::instance()->generate_pkce_challenge($code_verifier); // PKCE Challenge
         $nonce = AgeWallet_Helpers::instance()->generate_random_hex(32); // Nonce for ID token replay protection

         // Determine the URL to redirect back to after successful verification.
         // The $_GET reads in this method are OIDC-flow routing parameters (not
         // user-submitted form data); each is validated downstream — `redirect_to`
         // via wp_validate_redirect(), `agewallet_md` / `agewallet_origin` via HMAC signature
         // verification. Nonce checks don't apply to the OIDC redirect flow.
         // phpcs:disable WordPress.Security.NonceVerification.Recommended
         $redirect_to = home_url('/'); // Default to home
         if ( ! empty($_GET['redirect_to']) ) {
             // Get the raw value and decode it first
             $potential_redirect = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) );
             // Validate that it's a local URL before trusting and sanitizing
             if ( wp_validate_redirect( $potential_redirect, home_url('/') )) {
                  $redirect_to = wp_sanitize_redirect( $potential_redirect );
                  $this->log_debug('[OIDC Handler] Using redirect_to parameter for final destination.', ['url' => $redirect_to]);
             } else {
                  $this->log_debug('[OIDC Handler] Invalid redirect_to parameter provided during launch.', ['redirect_to' => $potential_redirect]);
             }
         } else {
             // Use current URL if no redirect_to param - safer than referer
              $current_url = AgeWallet_Helpers::instance()->get_current_url();
              // Check if the current URL is not one of our OIDC endpoints before using it
              if (strpos($current_url, '/agewallet/') === false) {
                    $redirect_to = $current_url;
                    $this->log_debug('[OIDC Handler] No redirect_to parameter found, using current URL for final destination.', ['url' => $redirect_to]);
              } else {
                    $this->log_debug('[OIDC Handler] No redirect_to parameter found, and current URL is an endpoint, using home URL.');
                    $redirect_to = home_url('/'); // Fallback to home if current URL is one of ours
              }
         }

         // Store state, verifier, final redirect URL, and nonce in a transient (expires in 10 minutes).
         $transient_key  = self::STATE_TRANSIENT_PREFIX . $state;
         $transient_data = array(
             'pkce_verifier' => $code_verifier,
             'redirect_to'   => $redirect_to,
             'nonce'         => $nonce
         );

         $expiration = apply_filters('agewallet_state_transient_expiration', 10 * MINUTE_IN_SECONDS);

         $set_transient_success = set_transient($transient_key, wp_json_encode($transient_data), $expiration);

         $this->log_debug('[OIDC Handler] State transient set.', ['key' => $transient_key, 'success' => $set_transient_success ? 'Yes' : 'No', 'data_keys' => implode(',', array_keys($transient_data)) ]);
         if ( ! $set_transient_success ) {
             // Handle failure to save transient (database issue?)
             wp_die(
                 esc_html__('Could not save verification session state. Please try again.', 'agewallet-oidc-client'),
                 esc_html__('Session Error', 'agewallet-oidc-client'),
                 array('response' => 500) // Internal Server Error
             );
         }

         // Build the authorization URL parameters.
         $params = array(
             'response_type' => 'code',
             'client_id' => $client_id,
             'redirect_uri' => AgeWallet_Helpers::instance()->get_oidc_redirect_uri(),
             'scope' => 'openid age', // Request 'openid' and 'age' scopes
             'state' => $state,
             'code_challenge' => $code_challenge,
             'code_challenge_method' => 'S256', // Required for PKCE
             'nonce' => $nonce, // Include nonce in request
         );

         // Read metadata from the signed `md` query param (computed at gate-render
         // time where WordPress has the correct page context). On bad signature → null.
         //
         // HOOK: `agewallet_metadata`
         //
         // This filter fires AT VERIFY-CLICK TIME, not at gate-render time, so the
         // response is reliably cache-safe (POST to /agewallet/launch +
         // nocache_headers() earlier in this method). It's the right place to add
         // PER-VISITOR data (user identity, session-scoped values, anything that
         // must reflect the actual clicker rather than whoever first cached the page).
         //
         // For PER-URL/page-context data instead, use `agewallet_auto_metadata`
         // (in Metadata_Builder::build) — that one fires at gate-render time and
         // is correct for values that are identical for every visitor to the URL.
         //
         // Internal subscriber: AgeWallet_OIDC_Handler::merge_per_visitor_fields()
         // hooks here at priority 10 to resolve user_id / user_role if they're
         // ticked in the auto-JSON options. Developer hooks at any priority compose
         // cleanly with that.
         //
         // Return a string (max 4096 bytes) or null/empty to skip.
         $signed_md   = isset( $_GET['agewallet_md'] ) ? sanitize_text_field( wp_unslash( $_GET['agewallet_md'] ) ) : '';
         $base_value  = $signed_md ? AgeWallet_Helpers::instance()->verify_signed_metadata( $signed_md ) : null;

         // Decode the signed origin marker (if present) so filter callbacks can read
         // it via self::get_request_origin() to decide whether to attach context-specific
         // metadata (e.g., AgeWallet_WooCommerce::inject_checkout_metadata fires only
         // when this is 'checkout').
         $signed_origin = isset( $_GET['agewallet_origin'] ) ? sanitize_text_field( wp_unslash( $_GET['agewallet_origin'] ) ) : '';
         $origin        = $signed_origin ? AgeWallet_Helpers::instance()->verify_signed_metadata( $signed_origin ) : '';
         self::$request_origin = ( is_string( $origin ) && '' !== $origin ) ? $origin : null;

         $metadata    = apply_filters( 'agewallet_metadata', $base_value );
         if ( is_string( $metadata ) && '' !== $metadata ) {
             if ( strlen( $metadata ) > AgeWalletOIDCClientPro::METADATA_MAX_BYTES ) {
                 $this->log_debug( '[OIDC Handler] Metadata exceeds limit; truncating.', [ 'len' => strlen( $metadata ) ] );
                 $metadata = substr( $metadata, 0, AgeWalletOIDCClientPro::METADATA_MAX_BYTES );
             }
             $params['metadata'] = $metadata;
         }

         // Construct the full URL.
         // HOOK: Allow developers to modify the authorization request parameters.
         $params = apply_filters('agewallet_auth_request_params', $params);

         $authorize_url = add_query_arg($params, AgeWalletOIDCClientPro::AUTH_ENDPOINT);

         $this->log_debug('[OIDC Handler] About to redirect browser to AgeWallet authorization endpoint.', ['target_url_length' => strlen($authorize_url), 'target_url' => $authorize_url, 'final_redirect_back_target' => $redirect_to]);

         // Clear any output buffers that might have started.
         while (ob_get_level() > 0) {
             ob_end_clean();
         }

         // Use PHP's header function directly for external redirects.
         header('Location: ' . $authorize_url, true, 302);

         // Log after attempting to set the header
         $this->log_debug('[OIDC Handler] Sent direct Location header. Attempting exit.');

         // Ensure no further code executes.
         exit;
     }


     /**
      * Handles the '/agewallet/callback/' request from AgeWallet.
      * Validates state, handles regional exemption, exchanges code for token using PKCE,
      * verifies userinfo, and redirects to the success endpoint.
      *
      * @since 0.1.0
      */
     private function handle_callback() {
         // OIDC callback from the identity provider; query params (state, code,
         // error, etc.) are validated via state-transient lookup below — not via
         // form-nonce. Suppress nonce-recommendation warning for this method.
         // phpcs:disable WordPress.Security.NonceVerification.Recommended
         // *** Manually parse the query string from REQUEST_URI ***
         // This avoids issues with WordPress unsetting the 'error' query var.
         $raw_uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
         $query_string = wp_parse_url( $raw_uri, PHP_URL_QUERY );
         $query_params = array();
         if ( $query_string ) {
             parse_str( $query_string, $query_params );
         }

         $this->log_debug( '[OIDC Handler] Inside handle_callback.', array(
             'manual_query_params' => $query_params,
             'original_get'        => array_map( 'sanitize_text_field', wp_unslash( $_GET ) ),
         ) );
         nocache_headers(); // Prevent caching of this sensitive endpoint

         // --- 1. Basic Security Checks & Parameter Retrieval ---
         // *** Use $query_params instead of $_GET ***
         $state = isset($query_params['state']) ? sanitize_text_field( wp_unslash( $query_params['state'] ) ) : null;
         $code  = isset($query_params['code']) ? sanitize_text_field( wp_unslash( $query_params['code'] ) ) : null;
         $error = isset($query_params['error']) ? sanitize_text_field( wp_unslash( $query_params['error'] ) ) : null; // This now correctly gets 'access_denied'
         $error_description = isset($query_params['error_description']) ? sanitize_text_field( wp_unslash( $query_params['error_description'] ) ) : null;

         // --- 2. State Validation & Transient Retrieval (Needed for both success and error paths to get redirect_to) ---
         if ( ! $state ) {
             $this->log_debug('[OIDC Handler] Callback missing state parameter.');
             wp_die(
                 esc_html__('Invalid callback request received (missing state parameter).', 'agewallet-oidc-client'),
                 esc_html__('Verification Error', 'agewallet-oidc-client'),
                 array('response' => 400) // Bad Request
             );
         }

         $transient_key = self::STATE_TRANSIENT_PREFIX . $state;
         $this->log_debug('[OIDC Handler] Attempting to retrieve state transient.', ['key' => $transient_key]);
         $transient_json = get_transient($transient_key);

         if ( false === $transient_json ) {
             $this->log_debug('[OIDC Handler] Invalid or expired state transient.', ['state' => $state]);
             wp_die(
                 esc_html__('Your verification session has expired or is invalid (state mismatch). Please try initiating the verification again.', 'agewallet-oidc-client'),
                 esc_html__('Verification Error', 'agewallet-oidc-client'),
                 array('response' => 400, 'link_text' => esc_html__( 'Return to site', 'agewallet-oidc-client' ), 'link_url' => esc_url( home_url( '/' ) )) // Bad Request
             );
         }

         // Immediately delete the transient after retrieving it (one-time use).
         delete_transient($transient_key);
         $transient_data = json_decode($transient_json, true);

         // Validate the structure BEFORE checking for errors, as we need redirect_to even on error.
         if ( ! is_array($transient_data) || empty($transient_data['pkce_verifier']) || empty($transient_data['nonce']) ) {
              $this->log_debug('[OIDC Handler] Invalid transient data structure retrieved.', ['state' => $state]);
              wp_die(
                  esc_html__('Could not retrieve necessary verification data (invalid session state). Please try again.', 'agewallet-oidc-client'),
                  esc_html__('Verification Error', 'agewallet-oidc-client'),
                  array('response' => 500) // Internal Server Error - transient structure was wrong
              );
         }

         $code_verifier = $transient_data['pkce_verifier'];
         $redirect_to   = $transient_data['redirect_to'] ?? home_url('/'); // Use stored redirect or fallback home
         $nonce         = $transient_data['nonce'];
         $this->log_debug('[OIDC Handler] Extracted data from state transient.', ['redirect_to' => $redirect_to]);

         // --- 3. Check for OIDC Errors OR Regional Exemption ---
         if ( $error ) {
             $this->log_debug('[OIDC Handler] OIDC Error parameters received on callback.', ['error' => $error, 'desc' => $error_description]);

             // HOOK: Allow custom actions when an OIDC error occurs.
             do_action('agewallet_oidc_error', $error, $error_description);

             // Check for specific regional exemption
             $region_exemption_desc = 'Region does not require verification'; // Use exact string from AgeWallet

             // Note: $query_params already decoded '+' to space if parse_str did its job.
             if ('access_denied' === $error && $error_description === $region_exemption_desc) {
                 $this->log_debug('[OIDC Handler] Regional exemption detected. Treating as success.');
                 // Proceed directly to Step 6 (Success Redirect Setup)
                 $this->proceed_to_success($redirect_to, $nonce); // Pass nonce
                 exit;
             }

             // Handle other errors (User cancellation or other OIDC errors)
             if ('access_denied' === $error) {
                  if ('Verification failed' === $error_description) {
                      // Verification process failed — redirect back so the age gate re-triggers
                      wp_safe_redirect($redirect_to);
                      exit;
                  }
                  // User clicked "Deny" on consent screen
                  wp_die(
                     esc_html__('Age verification was cancelled or denied by the user.', 'agewallet-oidc-client'),
                     esc_html__('Verification Cancelled', 'agewallet-oidc-client'),
                     array('response' => 403, 'link_text' => esc_html__('Return to previous page', 'agewallet-oidc-client'), 'back_link' => true)
                  );
             } else {
                  // Other OIDC errors (invalid_request, server_error etc.).
                  wp_die(
                     sprintf(
                        /* translators: 1: Human-readable error description from the identity provider. 2: Machine-readable error code. */
                        esc_html__( 'Age verification failed: %1$s [%2$s]', 'agewallet-oidc-client' ),
                        esc_html( $error_description ?: 'Unknown error' ),
                        esc_html( $error )
                     ),
                     esc_html__( 'Verification Error', 'agewallet-oidc-client' ),
                     array( 'response' => 400 )
                  );
             }
         }

         // --- 4. No Error Parameter Found - Proceed with Code Exchange ---
         if ( ! $code ) {
             $this->log_debug('[OIDC Handler] Callback missing code parameter (and no error parameter).');
             wp_die(
                 esc_html__('Invalid callback request received (missing code).', 'agewallet-oidc-client'),
                 esc_html__('Verification Error', 'agewallet-oidc-client'),
                 array('response' => 400)
             );
         }

         // --- 5. Token Exchange ---
         $client_id = get_option(AgeWalletOIDCClientPro::OPT_CLIENT_ID);
         $client_secret = get_option(AgeWalletOIDCClientPro::OPT_CLIENT_SECRET);

         if ( empty($client_id) || empty($client_secret) ) {
             $this->log_debug('[OIDC Handler] Missing Client ID or Secret during token exchange attempt.');
             wp_die(
                 esc_html__('Plugin configuration error: Client credentials are not set.', 'agewallet-oidc-client'),
                 esc_html__('Configuration Error', 'agewallet-oidc-client'),
                 array('response' => 500)
             );
         }

         $token_endpoint = AgeWalletOIDCClientPro::TOKEN_ENDPOINT;
         $request_body = array(
             'grant_type' => 'authorization_code',
             'code' => $code,
             'redirect_uri' => AgeWallet_Helpers::instance()->get_oidc_redirect_uri(),
             'client_id' => $client_id,
             'client_secret' => $client_secret,
             'code_verifier' => $code_verifier,
         );

         $request_args = array(
             'method' => 'POST',
             'timeout' => 25,
             'redirection' => 5,
             'httpversion' => '1.1',
             'blocking' => true,
             'headers' => array(
                 'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
                 'Accept' => 'application/json',
                 'Cache-Control' => 'no-cache',
             ),
             'body' => http_build_query($request_body, '', '&'),
             'cookies' => array(),
             'sslverify' => true,
         );

         $this->log_debug('[OIDC Handler] Attempting token exchange.', ['endpoint' => $token_endpoint]);
         // HOOK: Allow developers to modify the token exchange request arguments.
         $request_args = apply_filters('agewallet_token_request_args', $request_args);
         $response = wp_remote_post($token_endpoint, $request_args);

         // --- 6. Handle Token Response ---
         if ( is_wp_error($response) ) {
             $this->log_debug('[OIDC Handler] Token exchange failed (wp_error).', ['error_code' => $response->get_error_code(), 'error_message' => $response->get_error_message()]);
             wp_die(
                 sprintf(
                     /* translators: %s: WP_Error code from the failed token-endpoint request. */
                     esc_html__( 'Could not communicate with the verification server (%s).', 'agewallet-oidc-client' ),
                     esc_html( $response->get_error_code() )
                 ),
                 esc_html__( 'Verification Error', 'agewallet-oidc-client' ),
                 array( 'response' => 502 )
             );
         }

         $response_code = wp_remote_retrieve_response_code($response);
         $response_body = wp_remote_retrieve_body($response);
         $token_data    = json_decode($response_body, true);

         $this->log_debug('[OIDC Handler] Received token response.', ['http_code' => $response_code, 'body_preview' => substr($response_body, 0, 500)]);

         if ( $response_code < 200 || $response_code >= 300 || ! is_array($token_data) || isset($token_data['error']) || empty($token_data['access_token']) ) {
             $error_details = 'Invalid or error response received from token endpoint.';
             if (is_array($token_data)) {
                 $error_details = $token_data['error_description'] ?? ($token_data['error'] ?? $error_details);
             }
             $this->log_debug('[OIDC Handler] Token exchange failed (API error).', ['details' => $error_details]);
             wp_die(
                 sprintf(
                     /* translators: %s: Human-readable error description returned by the token endpoint. */
                     esc_html__( 'Verification failed: %s', 'agewallet-oidc-client' ),
                     esc_html( $error_details )
                 ),
                 esc_html__( 'Verification Error', 'agewallet-oidc-client' ),
                 array( 'response' => $response_code >= 500 ? 502 : 400 )
             );
         }

         $access_token = sanitize_text_field($token_data['access_token']);
         $this->log_debug('[OIDC Handler] Access token obtained.');

         // --- 7. UserInfo Check ---
         $userinfo_endpoint = AgeWalletOIDCClientPro::USERINFO_ENDPOINT;
         $userinfo_args = array(
             'method'      => 'GET',
             'timeout'     => 15,
             'headers'     => array(
                 'Authorization' => 'Bearer ' . $access_token,
                 'Accept'        => 'application/json',
                 'Cache-Control' => 'no-cache',
             ),
             'sslverify'   => true,
         );

         $this->log_debug('[OIDC Handler] Fetching userinfo.', ['endpoint' => $userinfo_endpoint]);
         $userinfo_response = wp_remote_get($userinfo_endpoint, $userinfo_args);

         if ( is_wp_error($userinfo_response) ) {
              $this->log_debug('[OIDC Handler] Userinfo request failed (wp_error).', ['error_code' => $userinfo_response->get_error_code(), 'error_message' => $userinfo_response->get_error_message()]);
              wp_die(
                  sprintf(
                      /* translators: %s: WP_Error code from the failed userinfo-endpoint request. */
                      esc_html__( 'Could not confirm verification details (%s).', 'agewallet-oidc-client' ),
                      esc_html( $userinfo_response->get_error_code() )
                  ),
                  esc_html__( 'Verification Error', 'agewallet-oidc-client' ),
                  array( 'response' => 502 )
              );
         }

         $userinfo_code = wp_remote_retrieve_response_code($userinfo_response);
         $userinfo_body = wp_remote_retrieve_body($userinfo_response);
         $userinfo_data = json_decode($userinfo_body, true);
         $this->log_debug('[OIDC Handler] Received userinfo response.', ['http_code' => $userinfo_code, 'body_preview' => substr($userinfo_body, 0, 500)]);

         $age_verified_claim = isset($userinfo_data['age_verified']) ? $userinfo_data['age_verified'] : null;

         if ( $userinfo_code < 200 || $userinfo_code >= 300 || ! is_array($userinfo_data) || empty($userinfo_data['sub']) || $age_verified_claim !== true ) {
              $this->log_debug('[OIDC Handler] Userinfo check failed.', ['age_verified_claim_value' => $age_verified_claim]);
              if ($age_verified_claim === false) {
                   wp_die(
                         esc_html__('Age verification completed, but the minimum age requirement was not met.', 'agewallet-oidc-client'),
                         esc_html__('Verification Failed', 'agewallet-oidc-client'),
                         array('response' => 403, 'link_text' => esc_html__( 'Return to site', 'agewallet-oidc-client' ), 'link_url' => esc_url( home_url( '/' ) ))
                   );
              } else {
                   wp_die(
                         esc_html__('Could not confirm verification details (Invalid userinfo response).', 'agewallet-oidc-client'),
                         esc_html__('Verification Error', 'agewallet-oidc-client'),
                         array('response' => 500)
                   );
              }
         }

         $this->log_debug('[OIDC Handler] Userinfo check successful.', ['sub' => $userinfo_data['sub']]);
         // HOOK: Fires right after a user is successfully verified.
         do_action('agewallet_verification_success', $userinfo_data, $token_data);
         // --- End UserInfo Check ---

         // Extract metadata returned by the AgeWallet server (round-tripped from /authorize).
         $returned_metadata = null;
         if ( isset( $userinfo_data['metadata'] ) && is_string( $userinfo_data['metadata'] ) && '' !== $userinfo_data['metadata'] ) {
             $returned_metadata = $userinfo_data['metadata'];
         }

         // --- 8. Proceed to Success Page (after successful verification) ---
         $this->proceed_to_success($redirect_to, $nonce, $returned_metadata);
     }


     /**
      * Handles the '/agewallet/success/' request.
      * @since 0.1.0
      */
     private function handle_success() {
         // Success endpoint reached via redirect from /agewallet/callback; the
         // `awt` query param is a one-time transient lookup key, validated by
         // get_transient() below — not form data, no nonce applies.
         // phpcs:disable WordPress.Security.NonceVerification.Recommended
         $this->log_debug( '[OIDC Handler] Inside handle_success.', array(
             'query_params' => array_map( 'sanitize_text_field', wp_unslash( $_GET ) ),
         ) );
         nocache_headers();

         $success_token = isset( $_GET['awt'] ) ? preg_replace( '/[^a-f0-9]/i', '', sanitize_text_field( wp_unslash( $_GET['awt'] ) ) ) : null;
         $transient_json = false;
         $was_valid_token = false;

         if ( $success_token ) {
             $transient_key  = self::SUCCESS_TOKEN_PREFIX . $success_token;
             $this->log_debug('[OIDC Handler] Attempting to retrieve success transient.', ['key' => $transient_key]);
             $transient_json = get_transient($transient_key);

             if ( false !== $transient_json ) {
                 $was_valid_token = true;
                 delete_transient($transient_key);
                 $this->log_debug('[OIDC Handler] Success transient retrieved and deleted.');
             } else {
                  $this->log_debug('[OIDC Handler] Success handler: Invalid or expired success token.', ['awt' => $success_token]);
             }
         } else {
             $this->log_debug('[OIDC Handler] Success handler: Missing success token (awt).');
         }

         if ( ! $was_valid_token ) {
             $this->log_debug('[OIDC Handler] Success handler: Invalid token. Redirecting home without setting cookie.');
             while (ob_get_level() > 0) ob_end_clean();
             wp_safe_redirect( home_url('/') );
             exit;
         }

         $redirect_to   = home_url('/');
         $transient_data = json_decode($transient_json, true);

         if ( ! empty($transient_data['redirect_to']) && is_string($transient_data['redirect_to'])) {
             $potential_url = esc_url_raw( wp_unslash( $transient_data['redirect_to'] ) );
             if ( wp_validate_redirect( $potential_url, home_url('/') ) ) {
                 $redirect_to = wp_sanitize_redirect($potential_url);
             } else {
                 $this->log_debug('[OIDC Handler] Success handler: Invalid redirect URL found in transient, using home URL instead.', ['original' => $transient_data['redirect_to']]);
             }
         } else {
             $this->log_debug('[OIDC Handler] Success handler: Missing or invalid redirect_to URL in transient, using home URL.');
         }

        // HOOK: Allow developers to change the final redirect URL.
        $redirect_to = apply_filters('agewallet_final_redirect_url', $redirect_to, $transient_data);

        $this->log_debug('[OIDC Handler] Rendering success page.', ['final_redirect_to' => $redirect_to]);

        if ( class_exists('AgeWallet_Gating_Manager') && defined('AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME') ) {
            $cookie_name = AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME;
            $this->log_debug('[OIDC Handler] Using cookie name from Gating Manager constant.', ['name' => $cookie_name]);
        } else {
            $cookie_name = 'agewallet_verified';
            $this->log_debug('[OIDC Handler] Warning: AgeWallet_Gating_Manager class or constant not found, using fallback cookie name.', ['name' => $cookie_name]);
        }

         // Generate Signed Cookie using unique nonce as salt, embedding any round-tripped metadata.
         $nonce_salt    = isset($transient_data['nonce']) ? $transient_data['nonce'] : '';
         $cookie_meta   = isset($transient_data['metadata']) && is_string($transient_data['metadata']) ? $transient_data['metadata'] : null;
         $cookie_value  = AgeWallet_Helpers::instance()->generate_signed_cookie( $nonce_salt, $cookie_meta );

         $this->log_debug('[OIDC Handler] Setting signed session cookie (expires on browser close).');

         // --- Cookie Attribute Handling ---
         $site_path = AgeWallet_Helpers::instance()->get_site_path();
         $this->log_debug('[OIDC Handler] Using default cookie path.', ['path' => $site_path]);

         // Build attributes as an array for filtering.
         $attributes = array(
             'path'     => $site_path,
             'SameSite' => 'Lax',
         );

         if ( is_ssl() ) {
             $attributes['Secure'] = true;
         }

         // HOOK: Allow developers to modify cookie attributes (e.g., domain, expires, max-age).
         $attributes = apply_filters('agewallet_verified_cookie_attributes', $attributes);

         // Build the final attribute string from the array.
         $cookie_attributes_string = '';
         foreach ( $attributes as $key => $value ) {
             if ( $value === true ) {
                 $cookie_attributes_string .= '; ' . esc_js( $key );
             } else {
                 $cookie_attributes_string .= '; ' . esc_js( $key ) . '=' . esc_js( $value );
             }
         }
         $this->log_debug('[OIDC Handler] Final cookie attributes for JS.', ['attributes' => $cookie_attributes_string]);

         // HOOK: Allow developers to completely override the default success page.
         do_action('agewallet_before_render_success_page', $redirect_to);

         ?>
         <!doctype html>
         <html <?php language_attributes(); ?>>
         <head>
             <meta charset="<?php bloginfo( 'charset' ); ?>">
             <title><?php esc_html_e('Verification Successful - Redirecting...', 'agewallet-oidc-client'); ?></title>
             <meta name="viewport" content="width=device-width, initial-scale=1">
             <meta name="robots" content="noindex, nofollow">
             <meta http-equiv="refresh" content="2;url=<?php echo esc_url($redirect_to); ?>">
             <style>
                 body{font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; margin:0; padding: 2em; text-align: center; color: #444; background-color:#f0f0f1; display: flex; justify-content: center; align-items: center; min-height: 100vh;}
                 .container{max-width: 400px; padding: 2em; background: #fff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);}
                 p{margin: 1em 0 1.5em; font-size: 1.1em; line-height: 1.5;}
                 a{color: #0073aa; text-decoration: none;} a:hover{text-decoration: underline;}
                 .spinner{display: inline-block; box-sizing: border-box; width: 24px; height: 24px; border: 4px solid rgba(0,115,170,.2); border-radius: 50%; border-top-color: #0073aa; animation: spin 1s ease-in-out infinite; -webkit-animation: spin 1s ease-in-out infinite; margin-left: 10px; vertical-align: middle;}
                 @keyframes spin{to{transform:rotate(360deg)}} @-webkit-keyframes spin{to{-webkit-transform:rotate(360deg)}}
                 noscript strong { color: #d63638; }
             </style>
         </head>
         <body>
             <div class="container">
                 <p><?php esc_html_e('Age verification successful. Redirecting you back...', 'agewallet-oidc-client'); ?><span class="spinner"></span></p>
                 <p><a href="<?php echo esc_url($redirect_to); ?>"><?php esc_html_e('Click here if you are not redirected automatically.', 'agewallet-oidc-client'); ?></a></p>
                 <noscript><p><strong><?php esc_html_e('JavaScript is required for the final redirection step.', 'agewallet-oidc-client'); ?></strong></p></noscript>
             </div>

             <script type="text/javascript">
                 (function(){
                     try {
                         var cookieName = <?php echo wp_json_encode($cookie_name); ?>;
                         var cookieValue = <?php echo wp_json_encode($cookie_value); ?>;
                         var cookieAttributes = <?php echo wp_json_encode($cookie_attributes_string); ?>;;
                         var cookieString = cookieName + '=' + encodeURIComponent(cookieValue) + cookieAttributes;

                         document.cookie = cookieString;
                         console.log('[AgeWallet] Set session cookie: ' + cookieString);

                         var redirectTo = <?php echo wp_json_encode($redirect_to); ?>;
                         console.log('[AgeWallet] Redirecting (replace) to: ' + redirectTo);
                         window.location.replace(redirectTo);

                     } catch (e) {
                         console.error("[AgeWallet] Error during success page script execution.", e);
                         var fallbackRedirect = <?php echo wp_json_encode($redirect_to); ?>;
                         console.log('[AgeWallet] Fallback redirect (href) to: ' + fallbackRedirect);
                         window.location.href = fallbackRedirect;
                     }
                 })();
             </script>
         </body>
         </html>
         <?php
         exit;
     }

     /**
      * Helper function to perform the steps needed to redirect to the success page.
      * passes Nonce for HMAC salt. Metadata (if any) round-trips via the success transient.
      * @since 0.1.0
      */
     private function proceed_to_success( $redirect_to, $nonce, $metadata = null ) {
         $success_token = AgeWallet_Helpers::instance()->generate_random_hex(16);
         $success_transient_key = self::SUCCESS_TOKEN_PREFIX . $success_token;

         // Store nonce so it can be used as salt on the final page
         $transient_data = [
             'redirect_to' => $redirect_to,
             'nonce'       => $nonce
         ];
         if ( is_string( $metadata ) && '' !== $metadata ) {
             $transient_data['metadata'] = $metadata;
         }

         $set_success_transient = set_transient($success_transient_key, wp_json_encode($transient_data), 2 * MINUTE_IN_SECONDS);

         $this->log_debug('[OIDC Handler] Success transient set (for success/exemption).', ['key' => $success_transient_key, 'success' => $set_success_transient ? 'Yes' : 'No']);
         if ( ! $set_success_transient ) {
             wp_die(
                 esc_html__('Could not save final redirect state.', 'agewallet-oidc-client'),
                 esc_html__('Session Error', 'agewallet-oidc-client'),
                 array('response' => 500)
             );
         }
         $success_url = add_query_arg('awt', $success_token, AgeWallet_Helpers::instance()->get_success_url());
         $this->log_debug('[OIDC Handler] Verification successful (or exempt). Redirecting to success handler.', ['target_success_url' => $success_url]);

         while (ob_get_level() > 0) {
             ob_end_clean();
         }
         wp_safe_redirect($success_url);
         exit;
     }

     /**
      * Add the AgeWallet authorize endpoint host to the list of allowed redirect hosts.
      * @since 0.1.0
      */
     public function add_allowed_redirect_hosts( $hosts ) {
         $oidc_host = wp_parse_url( AgeWalletOIDCClientPro::AUTH_ENDPOINT, PHP_URL_HOST );
         if ( $oidc_host && ! in_array( $oidc_host, $hosts, true ) ) {
             $this->log_debug('[OIDC Handler] Adding OIDC provider host to allowed redirect hosts.', ['host' => $oidc_host]);
             $hosts[] = $oidc_host;
         }
         return $hosts;
     }


     // --- Utility & Logging ---

     /**
      * Helper method to log debug messages.
      * @since 0.1.0
      */
     private function log_debug($message, $context = null) {
         if ( class_exists('AgeWallet_Helpers') && method_exists(AgeWallet_Helpers::instance(), 'log') ) {
              AgeWallet_Helpers::instance()->log('[OIDC Handler] ' . $message, $context);
         } else {
             // Fallback only fires when the Helpers class failed to load — a
             // dependency-bootstrap failure mode. Direct error_log is acceptable
             // because the centralised facility isn't available at this point.
             $log_entry = '[AgeWallet Plugin] [OIDC Handler] ' . $message;
             // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Fallback logger; print_r flattens structured context.
             if ( ! is_null( $context ) ) { $log_entry .= ' Context: ' . print_r( $context, true ); }
             // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Defensive fallback when AgeWallet_Helpers can't be loaded; central debug facility is unavailable here.
             @error_log( preg_replace( '/\s+/', ' ', trim( $log_entry ) ) );
         }
     }

     // --- Singleton Pattern Boilerplate ---
     /** Cloning forbidden. @since 0.1.0 */
     public function __clone() { _doing_it_wrong(__FUNCTION__, esc_html__('Cloning forbidden.', 'agewallet-oidc-client'), '0.1.0'); }
     /** Unserializing forbidden. @since 0.1.0 */
     public function __wakeup() { _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing forbidden.', 'agewallet-oidc-client'), '0.1.0'); }

 } // End class AgeWallet_OIDC_Handler