<?php
    // Prevent direct script access.
    defined('ABSPATH') || exit;

    /**
     * Provides utility/helper methods for the AgeWallet OIDC Client plugin.
    *
    * Includes functions for logging, generating secrets/tokens/PKCE, getting URLs etc.
    * Implements the Singleton pattern.
    *
    * @package AgeWalletOIDCClient
    * @since   0.1.0
    */
    class AgeWallet_Helpers {

        /**
         * The single instance of the class.
        * @since 0.1.0
        * @var   AgeWallet_Helpers|null
        */
        private static $instance = null;

        /**
         * Ensures only one instance of the helper class is loaded.
        * @since  0.1.0
        * @static
        * @return AgeWallet_Helpers - Main instance.
        */
        public static function instance() {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor. Private to prevent direct object creation.
        * @since 0.1.0
        */
        private function __construct() {
            // Initialization logic can go here if needed in the future.
        }

        /**
         * Logs a debug message if the plugin's "Enable Debug Logging" setting is enabled.
        * Prepends '[AgeWallet Plugin]' and an optional context prefix.
        * Forces logging to wp-content/debug.log regardless of WP_DEBUG_LOG.
        *
        * @since 0.1.0
        * @param string $message The message to log.
        * @param mixed  $context Optional context data (array, object, string). Will be formatted with print_r.
        */
        public function log( $message, $context = null ) {
            // --- DEBUG LOGIC ---
            // Check if our plugin's debug setting is on. If not, stop immediately.
            // We must check that the main class constant is available first.
            if ( ! class_exists('AgeWalletOIDCClientPro') ) {
                return; // Main class not loaded, cannot check setting.
            }
            $plugin_debug_on = (bool) get_option( AgeWalletOIDCClientPro::OPT_DEBUG_MODE, false );
            if ( ! $plugin_debug_on ) {
                return; // Plugin-specific debug is disabled.
            }

            $log_entry = '[AgeWallet Plugin] ' . $message;
            if ( ! is_null($context) ) {
                if ( is_array($context) || is_object($context) ) {
                    // Use print_r for arrays/objects, remove excessive whitespace
                    $context_str = preg_replace('/\s+/', ' ', print_r($context, true));
                    $log_entry .= ' | Context: ' . $context_str;
                } else {
                    $log_entry .= ' | Context: ' . $context;
                }
            }
            // Replace multiple spaces/newlines from print_r output for cleaner single-line logs
            $log_entry = preg_replace('/\s+/', ' ', trim($log_entry));

            // --- DESTINATION LOGIC ---
            // Always log to wp-content/debug.log if plugin debug is on
            $log_file = WP_CONTENT_DIR . '/debug.log';
            // Add timestamp and newline
            $formatted_log_entry = '[' . gmdate('d-M-Y H:i:s') . ' UTC] ' . $log_entry . PHP_EOL;

            @error_log($formatted_log_entry, 3, $log_file); // Type 3 = append to file
            // --- END DESTINATION LOGIC ---
        }

        /**
         * Static version of the log method for use when an instance might not be available (e.g., activation).
        * This version checks WP_DEBUG_LOG, as plugin settings may not be loaded.
        *
        * @since 0.1.0
        * @param string $message The message to log.
        * @param mixed  $context Optional context data (array, object, string).
        */
        public static function static_log_debug( $message, $context = null ) {
            // This static logger is only for activation/deactivation hooks.
            // It will only log if WP_DEBUG_LOG is on, as plugin settings are not reliably available.
            if ( ! ( defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) ) {
                return; // Do nothing if main WP logging is disabled
            }

            $log_entry = '[AgeWallet Plugin] ' . $message; // No class context prefix here
            if ( ! is_null($context) ) {
                if ( is_array($context) || is_object($context) ) {
                    $context_str = preg_replace('/\s+/', ' ', print_r($context, true));
                    $log_entry .= ' | Context: ' . $context_str;
                } else {
                    $log_entry .= ' | Context: ' . $context;
                }
            }
            // Use default error_log, which will respect WP_DEBUG_LOG's destination
            @error_log(preg_replace('/\s+/', ' ', trim($log_entry)));
        }

        /**
         * Ensures the HMAC secret option exists, generating it if needed.
        * Uses cryptographically secure random bytes.
        *
        * @since 0.1.0
        * @return string The HMAC secret. Returns empty string on generation failure.
        */
        public function ensure_hmac_secret() {
            $secret = get_option(AgeWalletOIDCClientPro::OPT_HMAC_SECRET, '');
            $regenerate = false;

            if ( empty($secret) || ! is_string($secret) || strlen($secret) < 32 ) {
                $regenerate = true;
                self::static_log_debug('[Helpers] HMAC secret is missing or invalid, attempting regeneration.'); // Use static log for activation
            }

            if ( $regenerate ) {
                try {
                    $secret = bin2hex(random_bytes(32)); // 64 hex characters
                    update_option(AgeWalletOIDCClientPro::OPT_HMAC_SECRET, $secret, false); // Do not autoload
                    self::static_log_debug('[Helpers] Generated new HMAC secret.');
                } catch ( Exception $e ) {
                    // Fallback if random_bytes fails (highly unlikely)
                    self::static_log_debug('[Helpers] ERROR: random_bytes failed during HMAC secret generation.', ['exception' => $e->getMessage()]);
                    $secret = wp_generate_password(64, true, true);
                    update_option(AgeWalletOIDCClientPro::OPT_HMAC_SECRET, $secret, false);
                    self::static_log_debug('[Helpers] Generated fallback HMAC secret using wp_generate_password.');
                }
            }

            // Final check in case generation failed completely
            if ( empty($secret) || ! is_string($secret) || strlen($secret) < 32 ) {
                self::static_log_debug('[Helpers] CRITICAL ERROR: Failed to generate or retrieve a valid HMAC secret.');
                return ''; // Return empty string on failure
            }

            return apply_filters('agewallet_hmac_secret', $secret);
        }

        /**
         * Generates a cryptographically secure random hex string.
        *
        * @since 0.1.0
        * @param int $byte_length Number of random bytes to generate (results in hex string twice as long). Default 16 (32 chars).
        * @return string Random hex string. Uses less secure fallback on error.
        */
        public function generate_random_hex( $byte_length = 16 ) {
            try {
                return bin2hex(random_bytes( (int) $byte_length ));
            } catch ( Exception $e ) {
                $this->log('ERROR: Failed to generate secure random hex, using fallback.', ['exception' => $e->getMessage()]);
                // Fallback (less secure if random_bytes truly fails)
                return wp_generate_password( $byte_length * 2, false, false);
            }
        }

        /**
         * Generates a PKCE code verifier string (approx 86 chars).
        * @link https://tools.ietf.org/html/rfc7636#section-4.1
        * @since 0.1.0
        * @param int $byte_length Number of random bytes (default 64).
        * @return string The code verifier. Uses less secure fallback on error.
        */
        public function generate_pkce_verifier( $byte_length = 64 ) {
            try {
                $random_bytes = random_bytes( (int) $byte_length );
                return rtrim(strtr(base64_encode($random_bytes), '+/', '-_'), '='); // Base64url encode
            } catch ( Exception $e ) {
                $this->log('ERROR: Failed to generate secure PKCE verifier, using fallback.', ['exception' => $e->getMessage()]);
                // Fallback
                return wp_generate_password( $byte_length * 1.3, true, true); // Approximate length, include special chars
            }
        }

        /**
         * Generates a PKCE code challenge (S256 method).
        * @link https://tools.ietf.org/html/rfc7636#section-4.2
        * @since 0.1.0
        * @param string $code_verifier The code verifier.
        * @return string The S256 code challenge. Returns empty string on error.
        */
        public function generate_pkce_challenge( $code_verifier ) {
            if ( empty($code_verifier) || ! is_string($code_verifier) ) {
                $this->log('ERROR: Invalid code_verifier provided for PKCE challenge generation.');
                return '';
            }
            try {
                $hashed_verifier = hash('sha256', $code_verifier, true); // true = raw binary output
                if ($hashed_verifier === false) {
                    $this->log('ERROR: hash("sha256") failed during PKCE challenge generation.');
                    return '';
                }
                return rtrim(strtr(base64_encode($hashed_verifier), '+/', '-_'), '='); // Base64url encode
            } catch ( Exception $e ) {
                $this->log('ERROR: Exception during PKCE challenge generation.', ['exception' => $e->getMessage()]);
                return '';
            }
        }

        // --- Cookie Security (HMAC) ---

        /**
         * Generates a signed cookie value.
         * Format: base64(json_payload) . signature
         * @param string $salt Optional unique salt (e.g., nonce).
         * @return string Signed cookie string.
         */
        public function generate_signed_cookie( $salt = '' ) {
            $secret = $this->ensure_hmac_secret();
            if ( empty( $salt ) ) {
                $salt = $this->generate_random_hex(8);
            }

            // Payload
            $payload = array(
                'status' => 'verified',
                'salt'   => $salt,
                // 24 Hour Safety Net: Even though browser session deletes cookie on close,
                // this ensures a stolen cookie string expires in 24h.
                'exp'    => time() + ( DAY_IN_SECONDS ),
            );

            // Hook to add extra data
            $payload = apply_filters( 'agewallet_cookie_payload', $payload );

            $encoded_payload = base64_encode( json_encode( $payload ) );
            $signature = hash_hmac( 'sha256', $encoded_payload, $secret );

            return $encoded_payload . '.' . $signature;
        }

        /**
         * Verifies a signed cookie value.
         * @param string $cookie_value The raw cookie string.
         * @return bool True if valid and not expired.
         */
        public function verify_signed_cookie( $cookie_value ) {
            // Basic format check
            if ( empty( $cookie_value ) || strpos( $cookie_value, '.' ) === false ) {
                return false;
            }

            list( $encoded_payload, $signature ) = explode( '.', $cookie_value, 2 );

            // 1. Verify Signature
            $secret = $this->ensure_hmac_secret();
            $check_signature = hash_hmac( 'sha256', $encoded_payload, $secret );

            if ( ! hash_equals( $signature, $check_signature ) ) {
                do_action( 'agewallet_cookie_validation_error', 'signature_mismatch', $cookie_value );
                return false;
            }

            // 2. Verify Expiration
            $payload = json_decode( base64_decode( $encoded_payload ), true );
            if ( ! is_array( $payload ) || ! isset( $payload['exp'] ) ) {
                return false;
            }

            if ( time() > $payload['exp'] ) {
                do_action( 'agewallet_cookie_validation_error', 'expired', $cookie_value );
                return false;
            }

            return true;
        }

        // --- URL Helpers ---

        /** Gets the callback URL (/agewallet/callback/). @since 0.1.0 @return string */
        public function get_oidc_redirect_uri() {
            $url = home_url(user_trailingslashit('/agewallet/callback/'));
            return apply_filters('agewallet_redirect_uri', $url);
        }

        /** Gets the success URL (/agewallet/success/). @since 0.1.0 @return string */
        public function get_success_url() {
            $url = home_url(user_trailingslashit('/agewallet/success/'));
            return apply_filters('agewallet_success_url', $url);
        }

        /** Gets the launch URL (/agewallet/launch/). @since 0.1.0 @return string */
        public function get_launch_url() {
            $url = home_url(user_trailingslashit('/agewallet/launch/'));
            return apply_filters('agewallet_launch_url', $url);
        }

        /**
         * Gets the site's base path ('/' or '/subdirectory/'). For cookie scoping.
        * @since 0.1.0
        * @return string The site path with a trailing slash.
        */
        public function get_site_path() {
            $site_url = home_url('/'); // Get Site Address URL with trailing slash
            $path = wp_parse_url($site_url, PHP_URL_PATH);
            // Ensure path is not empty and ends with a slash, default to '/'
            $path = ! empty($path) ? trailingslashit($path) : '/';
            return apply_filters('agewallet_cookie_path', $path);
        }

        /**
         * Gets the current page's full URL by reconstructing from server variables.
        * Attempts to handle HTTPS behind proxies and non-standard ports correctly.
        * Used for the 'redirect_to' parameter.
        *
        * @since 0.1.0
        * @return string The current full URL. Returns home URL on failure.
        */
        public function get_current_url() {
            // Determine Scheme (HTTPS check, considering proxies)
            $scheme = 'http';
            if ( (! empty($_SERVER['HTTPS']) && 'off' !== strtolower($_SERVER['HTTPS']))
                || (isset($_SERVER['SERVER_PORT']) && 443 === (int) $_SERVER['SERVER_PORT'])
                || (! empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']))
            ) {
                $scheme = 'https';
            }

            // Determine Host (Prefer HTTP_HOST, fallback to SERVER_NAME)
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
            if ( ! $host ) {
                $this->log('Warning: Could not determine host, falling back to host from home_url().');
                $host = wp_parse_url(home_url(), PHP_URL_HOST);
            }
            if ( ! $host ) {
                $this->log('ERROR: Could not determine host even from home_url(). Cannot construct URL.');
                return home_url('/'); // Final fallback
            }

            // Get Request URI (includes path and query string)
            $uri = $_SERVER['REQUEST_URI'] ?? '/';

            // Reconstruct the URL
            $current_url = $scheme . '://' . $host . $uri;

            // Basic validation
            if ( ! filter_var($current_url, FILTER_VALIDATE_URL) ) {
                $this->log('Warning: Reconstructed URL failed validation, falling back to home_url() + REQUEST_URI.', ['reconstructed_url' => $current_url]);
                // Fallback to the potentially problematic method ONLY if reconstruction fails validation
                $current_url = home_url( $uri );
                if ( empty($current_url) || ! filter_var($current_url, FILTER_VALIDATE_URL) ) {
                    $this->log('Warning: Fallback URL construction also failed, using basic home URL.');
                    return home_url('/'); // Final fallback
                }
            }

            $this->log('[Helpers] Reconstructed current URL.', ['url' => $current_url]);
            return apply_filters('agewallet_current_url', $current_url);
        }


        // --- Singleton Pattern Boilerplate ---
        /** Cloning forbidden. @since 0.1.0 */
        public function __clone() { _doing_it_wrong(__FUNCTION__, esc_html__('Cloning forbidden.', 'agewallet'), '0.1.0'); }
        /** Unserializing forbidden. @since 0.1.0 */
        public function __wakeup() { _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing forbidden.', 'agewallet'), '0.1.0'); }

    } // End class AgeWallet_Helpers