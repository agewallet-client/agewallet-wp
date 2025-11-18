<?php
/**
 * AgeWallet Gatekeeper Template (The Skeleton)
 *
 * This file is served to ALL users (verified and unverified) when "Strict Mode" is enabled.
 * It contains NO content, ensuring that sensitive data is never cached by Cloudflare/Edge.
 *
 * Responsibilities:
 * 1. Display a loading state (Spinner).
 * 2. Initialize the client-side logic (gate.js).
 * 3. Define the 'agewallet_gate_data' object with the Strict Mode flag.
 *
 * @package AgeWalletOIDCClient
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

// Get the current Post ID to build the API endpoint.
$post_id = get_the_ID();

// Define the script data manually since we are bypassing wp_head().
$script_data = array(
	'cookieName'       => defined( 'AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME' ) ? AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME : 'agewallet_verified',
	'isStrictMode'     => true, // SIGNAL TO JS: Do not just show overlay. Redirect or Fetch.
	'currentPostId'    => $post_id,
	'apiEndpoint'      => rest_url( 'agewallet/v1/content/' . $post_id ),
	'gateUrl' => get_permalink( get_option( 'agewallet_gate_page_id' ) ),
	'redirectUrl'      => AgeWallet_Helpers::instance()->get_current_url(), // Where to come back to.
	'bodyClassPending' => 'agewallet-strict-loading', // Specific class for strict mode.
);

// Allow developers to modify the skeleton template data (e.g., branded spinners).
$script_data = apply_filters( 'agewallet_gate_script_data', $script_data );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php wp_title( '|', true, 'right' ); ?></title>
	<style>
		/* Critical CSS for the Skeleton State - Inlined for speed */
		body, html { margin: 0; padding: 0; height: 100%; width: 100%; background-color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
		.aw-skeleton-wrap { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100vh; width: 100vw; }
		.aw-spinner { width: 40px; height: 40px; border: 4px solid rgba(0, 0, 0, 0.1); border-left-color: #6a1b9a; border-radius: 50%; animation: aw-spin 1s linear infinite; }
		.aw-loading-text { margin-top: 20px; color: #666; font-size: 14px; letter-spacing: 0.5px; }
		@keyframes aw-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
	</style>
</head>
<body>

<div class="aw-skeleton-wrap">
	<div class="aw-spinner"></div>
	<div class="aw-loading-text"><?php esc_html_e( 'Verifying access...', 'agewallet' ); ?></div>
</div>

<?php
/**
 * We must manually output the data object and script tag because
 * we are NOT calling wp_footer() to avoid loading heavy theme assets.
 */
?>
<script type="text/javascript">
	/* <![CDATA[ */
	var agewallet_gate_data = <?php echo wp_json_encode( $script_data ); ?>;
	/* ]]> */
</script>

<script type="text/javascript" src="<?php echo esc_url( AGEWALLET_PLUGIN_URL . 'assets/js/gate.js' ); ?>"></script>

</body>
</html>