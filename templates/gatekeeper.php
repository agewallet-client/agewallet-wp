<?php
/**
 * AgeWallet Gatekeeper Template (The Skeleton)
 *
 * This file is served to ALL users (verified and unverified) when "Strict Mode" is enabled.
 * It contains NO sensitive content, ensuring that only the loader is cached by Cloudflare/Edge.
 *
 * Features:
 * - Visual parity with the main Age Gate (uses same CSS/DOM structure).
 * - Injects "Custom CSS" from plugin settings to allow user overrides.
 * - Provides developer hooks for advanced asset loading and content injection.
 * - Lightweight execution (bypasses theme loading).
 * - "Unified Skeleton" design: Contains both Loading State and Gate State.
 *
 * @package AgeWalletOIDCClient
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

// --- 1. Setup Data ---
// Intelligent ID Detection: If this is an archive/search/home, set ID to 0.
// This ensures the API looks in the /archives/ folder immediately.
$is_archive = is_archive() || is_search() || is_home();
$post_id    = $is_archive ? 0 : get_the_ID();

// Retrieve Logo (Used for the Skeleton state)
$logo_id    = (int) get_option( AgeWalletOIDCClientPro::OPT_LOGO_ID, 0 );
$logo_width = (int) get_option( AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, 0 );
$logo_src   = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

// Retrieve Custom CSS & Scripts
$custom_css     = get_option( 'agewallet_custom_css', '' );
$head_scripts   = get_option( 'agewallet_head_scripts', '' );
$footer_scripts = get_option( 'agewallet_footer_scripts', '' );

// Get Current Full URL for Strict Mode API Request
$current_full_url = AgeWallet_Helpers::instance()->get_current_url();

// Script Data
$script_data = array(
	'cookieName'       => defined( 'AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME' ) ? AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME : 'agewallet_verified',
	'isStrictMode'     => true,
	'currentPostId'    => $post_id,

    // Pass URL and ID as query params.
    // Note: gate.js sends a POST, so these params are technically query params on a POST request, which works fine.
	'apiEndpoint'      => add_query_arg(
        array(
            'url' => urlencode( $current_full_url ),
            'id'  => $post_id
        ),
        rest_url( 'agewallet/v1/content' )
    ),
	'launchUrl'        => AgeWallet_Helpers::instance()->get_launch_url(),
	'redirectUrl'      => $current_full_url,
	'bodyClassPending' => 'agewallet-strict-loading',
);

$script_data = apply_filters( 'agewallet_gate_script_data', $script_data );

// Allow filtering of body classes
$body_classes = apply_filters( 'agewallet_skeleton_body_classes', 'aw-verify-body' );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php wp_title( '|', true, 'right' ); ?></title>

	<?php
	/**
	 * 1. Load the Plugin's Main Stylesheet
	 * This ensures the Skeleton looks exactly like the Gate (colors, cards, shadows).
	 */
	?>
	<link rel="stylesheet" id="agewallet-gate-style-css" href="<?php echo esc_url( AGEWALLET_PLUGIN_URL . 'assets/css/gate.css' ); ?>?ver=<?php echo esc_attr( AGEWALLET_VERSION ); ?>" type="text/css" media="all" />

	<?php
	/**
	 * 2. Inject User's "Custom CSS" (from Plugin Settings)
	 * This allows users to override .aw-gate__* classes securely and independently of the theme.
	 */
	if ( ! empty( $custom_css ) ) :
		?>
		<style type="text/css" id="agewallet-custom-css">
			<?php echo strip_tags( $custom_css ); // Safe injection of CSS ?>
		</style>
	<?php endif; ?>

	<style>
		/* Skeleton-Specific Overrides to ensure centering */
		body, html { margin: 0; padding: 0; height: 100%; width: 100%; background-color: var(--aw-bg, #000); }
		/* Spinner Styles (Matches Brand Color) */
		.aw-spinner {
			width: 40px;
			height: 40px;
			border: 4px solid rgba(255,255,255,0.1); /* Subtle track */
			border-left-color: var(--aw-purple, #6a1b9a); /* Brand color */
			border-radius: 50%;
			animation: aw-spin 1s linear infinite;
			margin: 0 auto 15px auto;
		}
		@keyframes aw-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

		/* Ensure the card looks good even with just a spinner */
		.aw-gate__card.aw-skeleton-card {
			min-height: 200px;
			justify-content: center;
		}
	</style>

	<?php
	// Developer Hook for Head Assets
	do_action( 'agewallet_skeleton_head' );

	// Admin Setting: Header Scripts (Analytics, etc.)
	if ( ! empty( $head_scripts ) ) {
		echo $head_scripts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin trusted input
	}
	?>
</head>
<body class="<?php echo esc_attr( $body_classes ); ?>">

<div class="aw-gate__overlay">

	<div id="aw-gate-spinner" class="aw-gate aw-gate__card aw-skeleton-card">

		<?php if ( ! empty( $logo_src ) ) : ?>
			<div class="aw-gate__logo-wrap">
				<img class="aw-gate__logo"
					 src="<?php echo esc_url( $logo_src ); ?>"
					 alt="<?php esc_attr_e( 'Logo', 'agewallet' ); ?>"
					 <?php if ( $logo_width > 0 ) : ?>
						 style="width:<?php echo intval( $logo_width ); ?>px; max-width:100%; height:auto;"
					 <?php else : ?>
						 style="max-width:100%; height:auto;"
					 <?php endif; ?>
				>
			</div>
		<?php endif; ?>

		<h1 class="aw-gate__title"><?php esc_html_e( 'Verifying your age...', 'agewallet' ); ?></h1>

		<div class="aw-gate__desc">
			<div class="aw-spinner"></div>
			<p style="font-size: 0.9em; opacity: 0.8; margin:0;">
				<?php esc_html_e( 'Please wait while we secure your content.', 'agewallet' ); ?>
			</p>
		</div>

		<?php
		// Developer Hook for content after spinner (e.g. legal text)
		do_action( 'agewallet_skeleton_content_after' );
		?>

	</div>

	<div id="aw-gate-ui" style="display:none;">
		<?php
		// Retrieve the standard gate HTML from the manager
		echo AgeWallet_Gating_Manager::instance()->get_gate_html();
		?>
	</div>

</div>

<?php
/**
 * Output Script Data and Loader
 */
?>
<script type="text/javascript">
	/* <![CDATA[ */
	var agewallet_gate_data = <?php echo wp_json_encode( $script_data ); ?>;
	/* ]]> */
</script>

<script type="text/javascript" src="<?php echo esc_url( AGEWALLET_PLUGIN_URL . 'assets/js/gate.js' ); ?>?ver=<?php echo esc_attr( AGEWALLET_VERSION ); ?>"></script>

<?php
// Developer Hook for Footer Scripts (Analytics, etc)
do_action( 'agewallet_skeleton_footer' );

// Admin Setting: Footer Scripts
if ( ! empty( $footer_scripts ) ) {
	echo $footer_scripts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin trusted input
}
?>

</body>
</html>