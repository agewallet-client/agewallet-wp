<?php
/**
 * AgeWallet Gatekeeper Template (The Skeleton)
 *
 * Served to ALL visitors when Strict Mode is enabled. Contains NO gated content,
 * so the page is safe to cache at the edge.
 *
 * @package AgeWalletOIDCClient
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

// Template-locals; never leave this file's scope. Suppress the WP-prefix rule for the file.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$is_archive = is_archive() || is_search() || is_home();
$post_id    = $is_archive ? 0 : get_the_ID();

$logo_id    = (int) get_option( AgeWalletOIDCClientPro::OPT_LOGO_ID, 0 );
$logo_width = (int) get_option( AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, 0 );
$logo_src   = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

$current_full_url = AgeWallet_Helpers::instance()->get_current_url();

$script_data = array(
	'cookieName'       => defined( 'AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME' ) ? AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME : 'agewallet_verified',
	'isStrictMode'     => true,
	'currentPostId'    => $post_id,
	// gate.js issues a POST; the query params on the URL are fine.
	'apiEndpoint'      => add_query_arg(
		array(
			'url' => rawurlencode( $current_full_url ),
			'id'  => $post_id,
		),
		rest_url( 'agewallet/v1/content' )
	),
	'launchUrl'        => AgeWallet_Helpers::instance()->get_launch_url(),
	'redirectUrl'      => $current_full_url,
	'signedMetadata'   => ( class_exists( 'AgeWallet_Metadata_Builder' ) && ( $aw_md_value = AgeWallet_Metadata_Builder::build() ) )
		? AgeWallet_Helpers::instance()->sign_metadata( $aw_md_value )
		: '',
	'bodyClassPending' => 'agewallet-strict-loading',
);

$script_data  = apply_filters( 'agewallet_gate_script_data', $script_data );
$body_classes = apply_filters( 'agewallet_skeleton_body_classes', 'aw-verify-body' );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php wp_title( '|', true, 'right' ); ?></title>

	<?php // Strict mode bypasses wp_head(); the inline <link> is intentional. ?>
	<link rel="stylesheet" id="agewallet-gate-style-css" href="<?php echo esc_url( AGEWALLET_PLUGIN_URL . 'assets/css/gate.css' ); ?>?ver=<?php echo esc_attr( AGEWALLET_VERSION ); ?>" type="text/css" media="all" /><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Strict-mode template runs before wp_enqueue_scripts. ?>

	<?php
	// Admin-chosen colour / radius values as CSS custom properties.
	AgeWallet_Helpers::instance()->render_css_vars();

	// Strict mode short-circuits wp_head(), so render the Customizer's saved CSS explicitly.
	$customizer_css = wp_get_custom_css();
	if ( ! empty( $customizer_css ) ) :
		?>
		<style id="agewallet-customizer-css">
			<?php echo wp_strip_all_tags( $customizer_css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-sanitised CSS inside <style>. ?>
		</style>
		<?php
	endif;
	?>

	<style>
		body, html { margin: 0; padding: 0; height: 100%; width: 100%; background-color: var(--aw-bg, #000); }
		.aw-spinner {
			width: 40px;
			height: 40px;
			border: 4px solid rgba(255,255,255,0.1);
			border-left-color: var(--aw-purple, #6a1b9a);
			border-radius: 50%;
			animation: aw-spin 1s linear infinite;
			margin: 0 auto 15px auto;
		}
		@keyframes aw-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
		.aw-gate__card.aw-skeleton-card { min-height: 200px; justify-content: center; }
	</style>

	<?php
	do_action( 'agewallet_skeleton_head' );

	// Structured analytics snippets (GA4 / GTM / FB Pixel) when their IDs are set.
	AgeWallet_Helpers::instance()->render_analytics_snippets( 'head' );
	?>
</head>
<body class="<?php echo esc_attr( $body_classes ); ?>">

<div class="aw-gate__overlay">

	<div id="aw-gate-spinner" class="aw-gate aw-gate__card aw-skeleton-card">

		<?php if ( ! empty( $logo_src ) ) : ?>
			<div class="aw-gate__logo-wrap">
				<img class="aw-gate__logo"
					 src="<?php echo esc_url( $logo_src ); ?>"
					 alt="<?php esc_attr_e( 'Logo', 'agewallet-oidc-client' ); ?>"
					 <?php if ( $logo_width > 0 ) : ?>
						 style="width:<?php echo (int) $logo_width; ?>px; max-width:100%; height:auto;"
					 <?php else : ?>
						 style="max-width:100%; height:auto;"
					 <?php endif; ?>
				>
			</div>
		<?php endif; ?>

		<h1 class="aw-gate__title"><?php esc_html_e( 'Verifying your age...', 'agewallet-oidc-client' ); ?></h1>

		<div class="aw-gate__desc">
			<div class="aw-spinner"></div>
			<p style="font-size: 0.9em; opacity: 0.8; margin:0;">
				<?php esc_html_e( 'Please wait while we secure your content.', 'agewallet-oidc-client' ); ?>
			</p>
		</div>

		<?php do_action( 'agewallet_skeleton_content_after' ); ?>

	</div>

	<div id="aw-gate-ui" style="display:none;">
		<?php
		// Pre-escaped plugin-controlled markup; built with esc_html()/esc_attr() in the manager.
		echo AgeWallet_Gating_Manager::instance()->get_gate_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped plugin-controlled markup.
		?>
	</div>

</div>

<script type="text/javascript">
	/* <![CDATA[ */
	var agewallet_gate_data = <?php echo wp_json_encode( $script_data ); ?>;
	/* ]]> */
</script>

<script type="text/javascript" src="<?php echo esc_url( AGEWALLET_PLUGIN_URL . 'assets/js/gate.js' ); ?>?ver=<?php echo esc_attr( AGEWALLET_VERSION ); ?>"></script><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Strict-mode template runs before wp_enqueue_scripts. ?>

<?php
do_action( 'agewallet_skeleton_footer' );

// Analytics that belong in <body>: GTM noscript fallback + FB Pixel noscript image.
AgeWallet_Helpers::instance()->render_analytics_snippets( 'body' );
?>

</body>
</html>
