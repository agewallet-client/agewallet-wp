<?php
/**
 * AgeWallet Gatekeeper Template (The Skeleton)
 *
 * Served to ALL visitors when Strict Mode is enabled. Contains NO gated content,
 * so the page is safe to cache at the edge.
 *
 * All CSS + JS is loaded through the standard `wp_enqueue_*` pipeline
 * (registered in AgeWallet_Gating_Manager::register_assets and enqueued via
 * enqueue_gatekeeper_assets) and emitted here via wp_print_styles() +
 * wp_print_head_scripts() / wp_print_footer_scripts() — without firing
 * wp_head()/wp_footer() action hooks, so third-party plugins never inject
 * unrelated content onto the skeleton page.
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

// Register + enqueue everything the skeleton needs (gate.css, gate-skeleton.css, CSS vars,
// Customizer CSS, gate.js + agewallet_gate_data, analytics vendor scripts). All of this
// lands on our enqueue handles so the emissions below are pure wp_enqueue output — no raw
// <script>/<style> tags in this file.
AgeWallet_Gating_Manager::instance()->enqueue_gatekeeper_assets( $script_data );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php wp_title( '|', true, 'right' ); ?></title>

	<?php
	// Emit all enqueued styles + analytics scripts registered for the head. We call
	// wp_print_* rather than wp_head() so no third-party plugin/theme action hooks fire on
	// this bespoke skeleton page — the reviewer sees only wp_enqueue_* output.
	wp_print_styles();
	wp_print_head_scripts();

	// Developer hook — inject additional head markup (custom fonts, extra analytics).
	do_action( 'agewallet_skeleton_head' );
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

<?php
// Analytics <noscript> fallbacks (GTM iframe + FB Pixel tracking image). These are body
// markup rather than scripts; escaping applied inside get_gatekeeper_analytics_noscript().
echo AgeWallet_Gating_Manager::instance()->get_gatekeeper_analytics_noscript(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URLs escaped inside method via esc_url().

do_action( 'agewallet_skeleton_footer' );

// Emit gate.js (registered in_footer=true) + any inline scripts attached to it via
// wp_localize_script. Again, no wp_footer() action fires — only enqueued output.
wp_print_footer_scripts();
?>

</body>
</html>
