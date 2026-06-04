<?php
/**
 * AgeWallet Admin Class
 *
 * Handles the admin settings page for the AgeWallet OIDC Client plugin.
 * Uses the WordPress Settings API with a multi-page "Wizard" layout.
 * Implements the Singleton pattern.
 *
 * @package AgeWalletOIDCClient
 * @since   0.1.0
 */

// Prevent direct script access.
defined( 'ABSPATH' ) || exit;

class AgeWallet_Admin {

	/**
	 * The single instance of the class.
	 * @var AgeWallet_Admin|null
	 */
	private static $instance = null;

	/**
	 * Option Groups for granular saving.
	 */
	private $group_credentials = 'agewallet_options_credentials';
	private $group_guarding    = 'agewallet_options_guarding';
	private $group_appearance  = 'agewallet_options_appearance';
	private $group_scripts     = 'agewallet_options_scripts';
	private $group_debug       = 'agewallet_options_debug';

	/**
	 * Base Slug.
	 */
	private $base_slug = 'agewallet-welcome';

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_agewallet_purge_cache', array( $this, 'handle_purge_cache' ) );
		add_action( 'wp_ajax_agewallet_term_search', array( $this, 'handle_term_search' ) );
	}

	/**
	 * Register the new multi-page menu structure.
	 */
	public function add_admin_menu() {
		$cap = apply_filters( 'agewallet_admin_menu_capability', 'manage_options' );

		// 1. Main Menu Item -> Welcome Page
		add_menu_page(
			__( 'AgeWallet', 'agewallet' ),
			__( 'AgeWallet', 'agewallet' ),
			$cap,
			$this->base_slug,
			array( $this, 'render_welcome_page' ),
			'dashicons-shield-alt'
		);

		// 2. Welcome Submenu
		add_submenu_page(
			$this->base_slug,
			__( 'Welcome', 'agewallet' ),
			__( 'Welcome', 'agewallet' ),
			$cap,
			'agewallet-welcome',
			array( $this, 'render_welcome_page' )
		);

		// 3. Step 1: API Credentials
		add_submenu_page(
			$this->base_slug,
			__( 'API Credentials', 'agewallet' ),
			__( 'API Credentials', 'agewallet' ),
			$cap,
			'agewallet-credentials',
			array( $this, 'render_credentials_page' )
		);

		// 4. Step 2: Content Guarding
		add_submenu_page(
			$this->base_slug,
			__( 'Content Guarding', 'agewallet' ),
			__( 'Content Guarding', 'agewallet' ),
			$cap,
			'agewallet-guarding',
			array( $this, 'render_guarding_page' )
		);

		// 5. Step 3: Appearance
		add_submenu_page(
			$this->base_slug,
			__( 'Gate Appearance', 'agewallet' ),
			__( 'Gate Appearance', 'agewallet' ),
			$cap,
			'agewallet-appearance',
			array( $this, 'render_appearance_page' )
		);

		// 6. Step 4: Scripts
		add_submenu_page(
			$this->base_slug,
			__( 'Header/Footer Scripts', 'agewallet' ),
			__( 'Scripts', 'agewallet' ),
			$cap,
			'agewallet-scripts',
			array( $this, 'render_scripts_page' )
		);

		// 7. Cache Control
		add_submenu_page(
			$this->base_slug,
			__( 'Cache Control', 'agewallet' ),
			__( 'Cache Control', 'agewallet' ),
			$cap,
			'agewallet-cache-control',
			array( $this, 'render_debug_page' )
		);

		// 8. Documentation Pages
		add_submenu_page( $this->base_slug, __( 'Usage Guide', 'agewallet' ), __( 'Usage Guide', 'agewallet' ), $cap, 'agewallet-usage', array( $this, 'render_usage_page' ) );
		add_submenu_page( $this->base_slug, __( 'CSS Guide', 'agewallet' ), __( 'CSS Guide', 'agewallet' ), $cap, 'agewallet-css-guide', array( $this, 'render_style_guide_page' ) );
		add_submenu_page( $this->base_slug, __( 'Developer Hooks', 'agewallet' ), __( 'Developer Hooks', 'agewallet' ), $cap, 'agewallet-hooks', array( $this, 'render_hooks_page' ) );
	}

	/**
	 * Registers settings sections and fields using the Settings API.
	 */
	public function register_settings() {

		// --- Group 1: Credentials ---
		register_setting( $this->group_credentials, AgeWalletOIDCClientPro::OPT_CLIENT_ID, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( $this->group_credentials, AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
		register_setting( $this->group_credentials, AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT, array( 'sanitize_callback' => array( $this, 'sanitize_metadata' ), 'default' => '' ) );
		register_setting( $this->group_credentials, 'agewallet_metadata_mode', array( 'sanitize_callback' => array( $this, 'sanitize_metadata_mode' ), 'default' => AgeWallet_Metadata_Builder::MODE_STATIC ) );
		register_setting( $this->group_credentials, 'agewallet_auto_metadata_fields', array( 'sanitize_callback' => array( $this, 'sanitize_auto_metadata_fields' ), 'default' => array() ) );

		add_settings_section( 'aw_sec_creds', __( 'API Configuration', 'agewallet' ), '__return_false', 'agewallet-credentials' );
		add_settings_field( AgeWalletOIDCClientPro::OPT_CLIENT_ID, __( 'Client ID', 'agewallet' ), array( $this, 'render_text_input' ), 'agewallet-credentials', 'aw_sec_creds', array( 'label_for' => AgeWalletOIDCClientPro::OPT_CLIENT_ID, 'class' => 'regular-text' ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, __( 'Client Secret', 'agewallet' ), array( $this, 'render_text_input' ), 'agewallet-credentials', 'aw_sec_creds', array( 'label_for' => AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, 'class' => 'regular-text', 'type' => 'password' ) );
		add_settings_field( 'oidc_redirect_uri', __( 'Redirect URI', 'agewallet' ), array( $this, 'render_redirect_uri' ), 'agewallet-credentials', 'aw_sec_creds' );

		add_settings_section( 'aw_sec_metadata', __( 'Verification Metadata', 'agewallet' ), array( $this, 'render_metadata_section_description' ), 'agewallet-credentials' );
		add_settings_field( 'agewallet_metadata_mode', __( 'Metadata source', 'agewallet' ), array( $this, 'render_metadata_source_ui' ), 'agewallet-credentials', 'aw_sec_metadata' );

		// --- Group 2: Guarding ---
		register_setting( $this->group_guarding, 'agewallet_protection_mode', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => 'standard' ) );
		register_setting( $this->group_guarding, AgeWalletOIDCClientPro::OPT_BLOCK_MODE, array( 'sanitize_callback' => array( $this, 'sanitize_block_mode' ), 'default' => 'none' ) );
		register_setting( $this->group_guarding, AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, array( 'sanitize_callback' => array( $this, 'sanitize_paths_textarea' ), 'default' => '' ) );
		register_setting( $this->group_guarding, 'agewallet_excluded_paths', array( 'sanitize_callback' => array( $this, 'sanitize_paths_textarea' ), 'default' => '' ) );
		// NEW: Taxonomy Rules
		register_setting( $this->group_guarding, 'agewallet_taxonomy_rules', array( 'sanitize_callback' => array( $this, 'sanitize_taxonomy_rules' ), 'default' => array() ) );

		add_settings_section( 'aw_sec_guard', __( 'Protection Rules', 'agewallet' ), array( $this, 'render_guarding_section_description' ), 'agewallet-guarding' );
		add_settings_field( 'agewallet_protection_mode', __( 'Security Mode', 'agewallet' ), array( $this, 'render_protection_mode_radio' ), 'agewallet-guarding', 'aw_sec_guard' );
		add_settings_field( AgeWalletOIDCClientPro::OPT_BLOCK_MODE, __( 'Scope of Protection', 'agewallet' ), array( $this, 'render_radio_buttons' ), 'agewallet-guarding', 'aw_sec_guard', array( 'option_name' => AgeWalletOIDCClientPro::OPT_BLOCK_MODE, 'options' => array( 'none' => __( 'No automatic protection.', 'agewallet' ), 'all_but_home' => __( 'Protect entire site, except homepage.', 'agewallet' ), 'all' => __( 'Protect entire site, including homepage.', 'agewallet' ), 'specific' => __( 'Protect only specific URL paths.', 'agewallet' ) ) ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, __( 'Paths to Protect', 'agewallet' ), array( $this, 'render_textarea' ), 'agewallet-guarding', 'aw_sec_guard', array( 'label_for' => AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, 'class' => 'large-text', 'rows' => 5, 'placeholder' => '/shop/, /articles/premium-content/', 'desc' => __( 'Only used if Scope is "specific". Enter comma-separated paths.', 'agewallet' ) ) );
		add_settings_field( 'agewallet_excluded_paths', __( 'Paths to Exclude', 'agewallet' ), array( $this, 'render_simple_textarea' ), 'agewallet-guarding', 'aw_sec_guard', array( 'label_for' => 'agewallet_excluded_paths', 'class' => 'large-text', 'rows' => 3, 'placeholder' => '/privacy-policy/, /contact/', 'desc' => __( 'Exceptions to Global Protection. Any URL containing these paths will be visible.', 'agewallet' ) ) );
		// Taxonomy Rules UI
		add_settings_section( 'aw_sec_tax_rules', __( 'Taxonomy Rules', 'agewallet' ), '__return_false', 'agewallet-guarding' );
		add_settings_field( 'agewallet_taxonomy_rules', __( 'Configure Taxonomies', 'agewallet' ), array( $this, 'render_taxonomy_rules_ui' ), 'agewallet-guarding', 'aw_sec_tax_rules' );

		// WooCommerce Rules — only shown when WC is active.
		if ( class_exists( 'WooCommerce' ) ) {
			register_setting( $this->group_guarding, AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 0 ) );
			register_setting( $this->group_guarding, AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS, array( 'sanitize_callback' => array( $this, 'sanitize_wc_metadata_fields' ), 'default' => array( 'cart_hash', 'cart_total', 'currency' ) ) );

			add_settings_section( 'aw_sec_wc', __( 'WooCommerce', 'agewallet' ), array( $this, 'render_wc_section_description' ), 'agewallet-guarding' );
			add_settings_field( AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT, __( 'Always gate checkout', 'agewallet' ), array( $this, 'render_checkbox' ), 'agewallet-guarding', 'aw_sec_wc', array( 'label_for' => AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT, 'label' => __( 'Force verification on the WooCommerce checkout page, regardless of other gating settings.', 'agewallet' ) ) );
			add_settings_field( AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS, __( 'Checkout metadata fields', 'agewallet' ), array( $this, 'render_wc_metadata_fields_ui' ), 'agewallet-guarding', 'aw_sec_wc' );
		}

		// --- Group 3: Appearance ---
		register_setting( $this->group_appearance, AgeWalletOIDCClientPro::OPT_LOGO_ID, array( 'sanitize_callback' => array( $this, 'sanitize_positive_int' ), 'default' => 0 ) );
		register_setting( $this->group_appearance, AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, array( 'sanitize_callback' => array( $this, 'sanitize_positive_int' ), 'default' => 0 ) );
		register_setting( $this->group_appearance, AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, array( 'sanitize_callback' => array( $this, 'sanitize_wysiwyg' ), 'default' => $this->get_default_copy() ) );
		register_setting( $this->group_appearance, AgeWalletOIDCClientPro::OPT_HIDE_HEADING, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 0 ) );
		register_setting( $this->group_appearance, 'agewallet_custom_css', array( 'sanitize_callback' => array( $this, 'sanitize_custom_css' ), 'default' => '' ) );

		add_settings_section( 'aw_sec_app', __( 'Gate Styling', 'agewallet' ), '__return_false', 'agewallet-appearance' );
		add_settings_field( AgeWalletOIDCClientPro::OPT_LOGO_ID, __( 'Gate Logo', 'agewallet' ), array( $this, 'render_media_uploader' ), 'agewallet-appearance', 'aw_sec_app', array( 'option_name' => AgeWalletOIDCClientPro::OPT_LOGO_ID ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, __( 'Logo Width (px)', 'agewallet' ), array( $this, 'render_number_input' ), 'agewallet-appearance', 'aw_sec_app', array( 'label_for' => AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, 'class' => 'small-text', 'min' => 0, 'step' => 1, 'desc' => __( 'Leave 0 for natural width.', 'agewallet' ) ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, __( 'Gate Copy', 'agewallet' ), array( $this, 'render_wysiwyg_editor' ), 'agewallet-appearance', 'aw_sec_app', array( 'option_name' => AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG ) );
		add_settings_field( AgeWalletOIDCClientPro::OPT_HIDE_HEADING, __( 'Hide Default Heading', 'agewallet' ), array( $this, 'render_checkbox' ), 'agewallet-appearance', 'aw_sec_app', array( 'label_for' => AgeWalletOIDCClientPro::OPT_HIDE_HEADING, 'label' => __( 'Hide the "You Must Verify Your Age" heading.', 'agewallet' ) ) );
		add_settings_field( 'agewallet_custom_css', __( 'Custom CSS', 'agewallet' ), array( $this, 'render_css_editor' ), 'agewallet-appearance', 'aw_sec_app' );

		// --- Group 4: Scripts ---
		register_setting( $this->group_scripts, 'agewallet_head_scripts', array( 'sanitize_callback' => array( $this, 'sanitize_script_field' ), 'default' => '' ) );
		register_setting( $this->group_scripts, 'agewallet_footer_scripts', array( 'sanitize_callback' => array( $this, 'sanitize_script_field' ), 'default' => '' ) );

		add_settings_section( 'aw_sec_scripts', __( 'Loading Screen Scripts (Strict Mode Only)', 'agewallet' ), '__return_false', 'agewallet-scripts' );
		add_settings_field( 'agewallet_head_scripts', __( 'Header Scripts', 'agewallet' ), array( $this, 'render_script_editor' ), 'agewallet-scripts', 'aw_sec_scripts', array( 'label_for' => 'agewallet_head_scripts', 'desc' => __( 'Output in the &lt;head&gt; of the loading screen.', 'agewallet' ) ) );
		add_settings_field( 'agewallet_footer_scripts', __( 'Footer Scripts', 'agewallet' ), array( $this, 'render_script_editor' ), 'agewallet-scripts', 'aw_sec_scripts', array( 'label_for' => 'agewallet_footer_scripts', 'desc' => __( 'Output before &lt;/body&gt; on the loading screen.', 'agewallet' ) ) );

		// --- Group 5: Cache Control ---
		register_setting( $this->group_debug, AgeWalletOIDCClientPro::OPT_DEBUG_MODE, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ), 'default' => 0 ) );
		// NEW: Cache TTL setting
		register_setting( $this->group_debug, 'agewallet_cache_ttl', array( 'sanitize_callback' => array( $this, 'sanitize_positive_int' ), 'default' => 14400 ) ); // Default 4 hours (14400s)

		// Section 1: Cache Management
		add_settings_section( 'aw_sec_cache', __( 'Cache Management', 'agewallet' ), array( $this, 'render_cache_section_description' ), 'agewallet-cache-control' );
		add_settings_field( 'agewallet_cache_ttl', __( 'Cache Auto-Clear Schedule', 'agewallet' ), array( $this, 'render_cache_ttl_dropdown' ), 'agewallet-cache-control', 'aw_sec_cache' );

		// Section 2: Developer Logging
		add_settings_section( 'aw_sec_logging', __( 'Developer Tools', 'agewallet' ), array( $this, 'render_logging_section_description' ), 'agewallet-cache-control' );
		add_settings_field( AgeWalletOIDCClientPro::OPT_DEBUG_MODE, __( 'Enable Logging', 'agewallet' ), array( $this, 'render_checkbox' ), 'agewallet-cache-control', 'aw_sec_logging', array( 'label_for' => AgeWalletOIDCClientPro::OPT_DEBUG_MODE, 'label' => __( 'Enable plugin debug logging', 'agewallet' ), 'desc' => sprintf( wp_kses( __( 'Writes to <code>%s</code>.', 'agewallet' ), array( 'code' => array() ) ), 'wp-content/debug.log' ) ) );
	}

	// --- Page Renderer Wrapper (Unified Layout) ---

	private function render_page_wrapper( $title, $callback, $option_group = null, $next_step = null ) {
		?>
        <style>
            /* Custom Green Style for Next Step Buttons */
            .aw-next-step-btn.button-primary {
                background-color: #008a20;
                border-color: #008a20;
                color: #fff;
            }
            .aw-next-step-btn.button-primary:hover,
            .aw-next-step-btn.button-primary:focus {
                background-color: #007017;
                border-color: #007017;
                color: #fff;
            }
        </style>
		<div class="wrap">
			<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
				<h1 style="margin:0;"><?php echo esc_html( $title ); ?></h1>
				<a href="mailto:support@agewallet.com" class="button"><?php esc_html_e( 'Get Support', 'agewallet' ); ?></a>
			</div>
			<hr style="margin: 0 0 20px 0;">

			<div style="display:flex; gap:20px; flex-wrap:wrap;">
				<div style="flex: 1; min-width: 300px;">
					<?php if ( $option_group ) : ?>
						<form method="post" action="options.php" id="agewallet-settings-form">
							<?php
							settings_fields( $option_group );
							do_settings_sections( $_GET['page'] );
							submit_button( __( 'Save Changes', 'agewallet' ) );
							?>
						</form>

						<?php if ( $next_step ) : ?>
							<div style="margin-top: 20px; text-align: left;">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $next_step['slug'] ) ); ?>" class="button button-primary button-large aw-next-step-btn" style="display: inline-flex; align-items: center; gap: 6px;">
									<?php echo esc_html( $next_step['label'] ); ?>
                                    <span class="dashicons dashicons-arrow-right-alt2" style="line-height: 1;"></span>
                                </a>
                            </div>
						<?php endif; ?>

					<?php else : ?>
						<?php call_user_func( $callback ); ?>
					<?php endif; ?>
				</div>

				<div style="width: 280px; flex-shrink: 0;">
					<div class="postbox">
						<h2 class="hndle ui-sortable-handle" style="padding:10px 15px; margin:0;"><span><?php esc_html_e( 'Documentation', 'agewallet' ); ?></span></h2>
						<div class="inside">
							<ul style="margin:0; padding-left:15px; list-style:square;">
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-usage' ) ); ?>"><?php esc_html_e( 'Usage Guide', 'agewallet' ); ?></a></li>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-css-guide' ) ); ?>"><?php esc_html_e( 'CSS Guide', 'agewallet' ); ?></a></li>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-hooks' ) ); ?>"><?php esc_html_e( 'Developer Hooks', 'agewallet' ); ?></a></li>
							</ul>
							<hr>
							<?php if ( 'strict' === get_option( 'agewallet_protection_mode', 'standard' ) ) : ?>
								<p><strong><?php esc_html_e( 'Quick Action:', 'agewallet' ); ?></strong></p>
								<button type="button" class="button button-small agewallet-purge-btn" style="width:100%; margin-bottom:5px;">
									<?php esc_html_e( 'Purge Cache', 'agewallet' ); ?>
								</button>
								<span class="spinner agewallet-purge-spinner" style="float:none; margin:0;"></span>
								<span class="agewallet-purge-message" style="display:block; font-size:11px; line-height:1.2; margin-top:5px;"></span>
							<?php else : ?>
								<p style="font-size:12px; color:#666;"><?php esc_html_e( 'Cache purging is available when Strict Mode is enabled.', 'agewallet' ); ?></p>
							<?php endif; ?>
							<hr>
							<p style="font-size:12px; margin-top:10px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-cache-control' ) ); ?>"><?php esc_html_e( 'Go to Cache Control →', 'agewallet' ); ?></a></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// --- Specific Page Renderers ---

	public function render_welcome_page() {
		$this->render_page_wrapper( __( 'Welcome to AgeWallet', 'agewallet' ), function() {
            // Welcome - wizard start
			?>
			<div class="card" style="max-width:100%; padding:20px; margin-top:20px;">
				<h2><?php esc_html_e( "Let's get you set up!", 'agewallet' ); ?></h2>
				<ol style="font-size:1.1em; line-height:2; margin-left:20px;">
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-credentials' ) ); ?>"><strong><?php esc_html_e( 'Step 1: Set up your API Credentials', 'agewallet' ); ?></strong></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-guarding' ) ); ?>"><strong><?php esc_html_e( 'Step 2: Set up Content Guarding Rules', 'agewallet' ); ?></strong></a></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-appearance' ) ); ?>"><?php esc_html_e( 'Step 3: Customize Gate Appearance', 'agewallet' ); ?></a> <span class="description">(<?php esc_html_e( 'Optional', 'agewallet' ); ?>)</span></li>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=agewallet-scripts' ) ); ?>"><?php esc_html_e( 'Step 4: Add Header/Footer Scripts', 'agewallet' ); ?></a> <span class="description">(<?php esc_html_e( 'Strict Mode Only', 'agewallet' ); ?>)</span></li>
				</ol>
			</div>
			<?php
		});
	}

	public function render_credentials_page() {
		$next = array(
			'slug'  => 'agewallet-guarding',
			'label' => __( 'Next: Content Guarding', 'agewallet' ),
		);
		$this->render_page_wrapper( __( 'Step 1: API Credentials', 'agewallet' ), null, $this->group_credentials, $next );
	}

	public function render_guarding_page() {
		$next = array(
			'slug'  => 'agewallet-appearance',
			'label' => __( 'Next: Gate Appearance', 'agewallet' ),
		);
		$this->render_page_wrapper( __( 'Step 2: Content Guarding', 'agewallet' ), null, $this->group_guarding, $next );
	}

	public function render_appearance_page() {
		$next = array(
			'slug'  => 'agewallet-scripts',
			'label' => __( 'Next: Scripts', 'agewallet' ),
		);
		$this->render_page_wrapper( __( 'Step 3: Gate Appearance', 'agewallet' ), null, $this->group_appearance, $next );
	}

	public function render_scripts_page() {
		$next = array(
			'slug'  => 'agewallet-welcome',
			'label' => __( 'Go to Dashboard', 'agewallet' ),
		);
		$this->render_page_wrapper( __( 'Step 4: Scripts', 'agewallet' ), null, $this->group_scripts, $next );
	}

	public function render_debug_page() {
		$this->render_page_wrapper( __( 'Cache Control', 'agewallet' ), null, $this->group_debug );
	}

	// --- Documentation Renderers (Reusing Wrapper for Consistency) ---

	public function render_usage_page() {
		$this->render_page_wrapper( __( 'Usage Guide', 'agewallet' ), function() {
			$content = $this->get_readme_section_content( 'Usage Guide' );
			if ( ! empty( $content ) ) {
				echo wp_kses_post( $this->parse_readme_markdown( $content ) );
			} else {
				$this->render_readme_error( 'Usage Guide' );
			}
		});
	}

	public function render_hooks_page() {
		$this->render_page_wrapper( __( 'Developer Hooks Guide', 'agewallet' ), function() {
			$content = $this->get_readme_section_content( 'Developer Hooks' );
			if ( ! empty( $content ) ) {
				echo wp_kses_post( $this->parse_readme_markdown( $content ) );
			} else {
				$this->render_readme_error( 'Developer Hooks' );
			}
		});
	}

	public function render_style_guide_page() {
		$this->render_page_wrapper( __( 'CSS Customization Guide', 'agewallet' ), function() {
			$content = $this->get_readme_section_content( 'CSS Customization Guide' );
			if ( ! empty( $content ) ) {
				echo wp_kses_post( $this->parse_readme_markdown( $content ) );
			} else {
				$this->render_readme_error( 'CSS Customization Guide' );
			}
		});
	}

	private function render_readme_error( $section ) {
		echo '<div class="notice notice-error"><p>';
		printf( esc_html__( 'Error: Could not find the "== %s ==" section in the readme.txt file.', 'agewallet' ), esc_html( $section ) );
		echo '</p></div>';
	}

	// --- Field Rendering Callbacks ---

	public function render_guarding_section_description() {
		echo '<p>' . esc_html__( 'Choose how site content should be protected. Users with "edit_posts" capability (e.g., Administrators, Editors) bypass the gate.', 'agewallet' ) . '</p>';
	}

	public function render_metadata_section_description() {
		echo '<p>' . esc_html__( 'Attach an opaque value to every AgeWallet verification triggered from this site. The value rides along with the OIDC flow and is stored alongside the verification record for later audit / reporting.', 'agewallet' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Developers: the `agewallet_metadata` filter has the final word on the encoded string. The `agewallet_auto_metadata` filter lets you add/remove keys from the auto-JSON bundle before encoding.', 'agewallet' ) . '</p>';
	}

	public function render_metadata_source_ui() {
		$mode = get_option( 'agewallet_metadata_mode', AgeWallet_Metadata_Builder::MODE_STATIC );
		$static_value = get_option( AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT, '' );
		$selected_fields = get_option( 'agewallet_auto_metadata_fields', array() );
		if ( ! is_array( $selected_fields ) ) {
			$selected_fields = array();
		}
		$strict_mode  = AgeWallet_Metadata_Builder::is_strict_mode();
		$cache_unsafe = array_flip( AgeWallet_Metadata_Builder::cache_unsafe_fields() );

		$field_groups = array(
			__( 'Post context (singular pages)', 'agewallet' ) => array(
				'post_id'   => __( 'Post ID', 'agewallet' ),
				'post_slug' => __( 'Post slug', 'agewallet' ),
				'post_type' => __( 'Post type', 'agewallet' ),
			),
			__( 'User context (logged-in visitors)', 'agewallet' ) => array(
				'user_id'   => __( 'WordPress user ID', 'agewallet' ),
				'user_role' => __( 'Primary role', 'agewallet' ),
			),
			__( 'Request context', 'agewallet' ) => array(
				'request_path'  => __( 'Request path', 'agewallet' ),
				'referrer_host' => __( 'Referrer host', 'agewallet' ),
			),
			__( 'Marketing context (UTM query params)', 'agewallet' ) => array(
				'utm_source'   => __( 'utm_source', 'agewallet' ),
				'utm_campaign' => __( 'utm_campaign', 'agewallet' ),
			),
			__( 'Archive / search context', 'agewallet' ) => array(
				'page_type'         => __( 'page_type (singular/category/tag/search/home/...)', 'agewallet' ),
				'term_id'           => __( 'term_id (category/tag/taxonomy archives)', 'agewallet' ),
				'term_slug'         => __( 'term_slug', 'agewallet' ),
				'term_taxonomy'     => __( 'term_taxonomy', 'agewallet' ),
				'search_query'      => __( 'search_query (on-site ?s= search)', 'agewallet' ),
				'archive_post_type' => __( 'archive_post_type (post-type archives)', 'agewallet' ),
			),
		);

		echo '<fieldset class="aw-metadata-source">';

		echo '<div class="notice notice-info inline" style="margin:0 0 12px 0; padding:8px 12px;">';
		echo '<p style="margin:0;">' . esc_html__(
			'Changing any setting below will automatically purge the cache so the new value takes effect on the next visitor — no manual cache flush needed.',
			'agewallet'
		) . '</p>';
		echo '</div>';

		// Mode radios
		$modes = array(
			AgeWallet_Metadata_Builder::MODE_OFF    => __( 'Off — no metadata attached', 'agewallet' ),
			AgeWallet_Metadata_Builder::MODE_STATIC => __( 'Static text', 'agewallet' ),
			AgeWallet_Metadata_Builder::MODE_AUTO   => __( 'Auto JSON of selected fields', 'agewallet' ),
		);
		foreach ( $modes as $value => $label ) {
			printf(
				'<label style="display:block; margin-bottom:6px;"><input type="radio" name="agewallet_metadata_mode" value="%1$s" %2$s class="aw-md-mode" /> %3$s</label>',
				esc_attr( $value ),
				checked( $value, $mode, false ),
				esc_html( $label )
			);
		}

		// Static-text sub-block
		echo '<div class="aw-md-block aw-md-block-static" style="margin-left:24px; margin-top:8px;' . ( AgeWallet_Metadata_Builder::MODE_STATIC === $mode ? '' : ' display:none;' ) . '">';
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT ),
			esc_attr( $static_value )
		);
		echo '<p class="description">' . esc_html__( 'Sent verbatim with every verification. Max 4096 bytes.', 'agewallet' ) . '</p>';
		echo '</div>';

		// Auto-JSON sub-block
		echo '<div class="aw-md-block aw-md-block-auto" style="margin-left:24px; margin-top:8px;' . ( AgeWallet_Metadata_Builder::MODE_AUTO === $mode ? '' : ' display:none;' ) . '">';
		foreach ( $field_groups as $group_label => $fields ) {
			echo '<p style="margin:8px 0 4px; font-weight:600;">' . esc_html( $group_label ) . '</p>';
			foreach ( $fields as $key => $label ) {
				$is_checked  = in_array( $key, $selected_fields, true );
				$is_disabled = $strict_mode && isset( $cache_unsafe[ $key ] );
				$style       = $is_disabled ? 'display:block; margin-left:8px; opacity:0.5;' : 'display:block; margin-left:8px;';
				printf(
					'<label style="%5$s"><input type="checkbox" name="agewallet_auto_metadata_fields[]" value="%1$s" %2$s %4$s/> %3$s</label>',
					esc_attr( $key ),
					$is_disabled ? '' : checked( true, $is_checked, false ),
					esc_html( $label ),
					$is_disabled ? 'disabled="disabled" ' : '',
					esc_attr( $style )
				);
			}
		}
		echo '<p class="description">' . esc_html__( 'Selected fields are JSON-encoded. Keys with no value on a given request (e.g., Post ID on a category page) are omitted automatically.', 'agewallet' ) . '</p>';
		if ( $strict_mode ) {
			echo '<p class="description" style="color:#996800;"><strong>' . esc_html__( 'Strict mode is active:', 'agewallet' ) . '</strong> ' . esc_html__( 'per-visitor fields (user, marketing, referrer) are disabled because the cached skeleton can\'t carry per-request context.', 'agewallet' ) . '</p>';
		}
		echo '</div>';

		echo '</fieldset>';

		// Inline JS to toggle sub-blocks based on selected radio.
		?>
		<script>
		(function(){
			var radios = document.querySelectorAll('input.aw-md-mode');
			function toggle(){
				var v = document.querySelector('input.aw-md-mode:checked');
				v = v ? v.value : '';
				document.querySelectorAll('.aw-md-block').forEach(function(el){ el.style.display = 'none'; });
				var target = document.querySelector('.aw-md-block-' + v);
				if (target) target.style.display = '';
			}
			radios.forEach(function(r){ r.addEventListener('change', toggle); });
			toggle();
		})();
		</script>
		<?php
	}

	public function render_wc_section_description() {
		echo '<p>' . esc_html__( 'Optional rule for the WooCommerce checkout page. When enabled, customers must complete age verification before they can pay. Per-checkout context (cart hash, total, etc.) is attached to the verification as metadata.', 'agewallet' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Use the `agewallet_wc_checkout_metadata` filter to fully customise the metadata payload from code.', 'agewallet' ) . '</p>';
	}

	public function render_wc_metadata_fields_ui() {
		$option_name = AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS;
		$selected    = get_option( $option_name, array( 'cart_hash', 'cart_total', 'currency' ) );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}
		$choices = array(
			'cart_hash'       => __( 'Cart hash (identifies the exact cart state)', 'agewallet' ),
			'cart_total'      => __( 'Cart total', 'agewallet' ),
			'currency'        => __( 'Currency code', 'agewallet' ),
			'customer_id'     => __( 'WordPress user ID', 'agewallet' ),
			'billing_country' => __( 'Billing country (if available)', 'agewallet' ),
			'line_item_count' => __( 'Number of line items', 'agewallet' ),
		);
		echo '<fieldset>';
		foreach ( $choices as $key => $label ) {
			$is_checked = in_array( $key, $selected, true );
			printf(
				'<label style="display:block; margin-bottom:4px;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $option_name ),
				esc_attr( $key ),
				checked( true, $is_checked, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Fields included in the JSON metadata sent with each checkout verification.', 'agewallet' ) . '</p>';
	}

    // Cache Section Description (Contains the Purge Button)
    public function render_cache_section_description() {
        $style = 'border: 1px solid #c3c4c7; background: #f6f7f7; padding: 10px 15px; border-left-width: 4px; border-left-color: #00a32a; margin-bottom: 20px;';
        echo '<div style="' . esc_attr( $style ) . '">';
        echo '<h4 style="margin-top:0;">' . esc_html__( 'Manual Actions', 'agewallet' ) . '</h4>';
        echo '<p>' . esc_html__( 'Manually clear the HTML cache if you see stale content after updates.', 'agewallet' ) . '</p>';
		echo '<button type="button" class="button button-secondary agewallet-purge-btn">';
		echo '<span class="dashicons dashicons-trash" style="vertical-align:text-top; margin-right:4px;"></span>';
		echo esc_html__( 'Purge HTML Cache (Strict Mode)', 'agewallet' );
		echo '</button>';
		echo '<span class="spinner agewallet-purge-spinner" style="float:none; margin-left: 5px;"></span>';
		echo '<span class="agewallet-purge-message" style="margin-left: 10px; font-weight: 600;"></span>';
        echo '</div>';
    }

    // NEW: Logging Section Description (Contains the Warning)
	public function render_logging_section_description() {
		$style = 'border: 1px solid #c3c4c7; background: #fff8e5; padding: 10px 15px; border-left-width: 4px; border-left-color: #d63638; margin-top: 20px;';
		echo '<div style="' . esc_attr( $style ) . '">';
		echo '<h4 style="margin-top:0;"><span class="dashicons dashicons-warning" style="color:#d63638; vertical-align: middle; margin-right: 5px;"></span>' . esc_html__( 'Developer Options', 'agewallet' ) . '</h4>';
		echo '<p style="margin-bottom:0;">' . sprintf( wp_kses( __( '<strong>Warning:</strong> For debugging only. This will write detailed plugin activity to <code>%s</code>.', 'agewallet' ), array( 'code' => array(), 'strong' => array() ) ), 'wp-content/debug.log' ) . '</p>';
		echo '</div>';
	}

	public function render_text_input( $args ) {
		$option_name = $args['label_for'];
		$value       = get_option( $option_name, '' );
		$type        = isset( $args['type'] ) ? $args['type'] : 'text';
		$class       = isset( $args['class'] ) ? $args['class'] : 'regular-text';
		printf( '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="%4$s" />', esc_attr( $type ), esc_attr( $option_name ), esc_attr( $value ), esc_attr( $class ) );
		if ( isset( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function render_redirect_uri() {
		$redirect_uri = AgeWallet_Helpers::instance()->get_oidc_redirect_uri();
		printf( '<code>%s</code>', esc_html( $redirect_uri ) );
		echo '<p class="description">' . wp_kses( __( 'Add this exact Redirect URI to your AgeWallet application configuration. It must match <strong>exactly</strong>.', 'agewallet' ), array( 'strong' => array() ) ) . '</p>';
	}

	public function render_media_uploader( $args ) {
		$option_name = $args['option_name'];
		$logo_id     = get_option( $option_name, 0 );
		$logo_src    = $logo_id ? wp_get_attachment_image_url( (int) $logo_id, 'medium' ) : '';
		?>
		<div style="margin-bottom: 8px;">
			<img id="aw-logo-preview" src="<?php echo esc_url( $logo_src ?: '' ); ?>" alt="<?php esc_attr_e( 'Logo Preview', 'agewallet' ); ?>" style="max-height: 60px; height: auto; <?php echo $logo_src ? '' : 'display:none;'; ?> border: 1px solid #ddd; padding: 2px;">
		</div>
		<input type="hidden" id="aw-logo-id-input" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $logo_id ); ?>">
		<button type="button" class="button" id="aw-logo-select-button"><?php esc_html_e( 'Select Image', 'agewallet' ); ?></button>
		<button type="button" class="button" id="aw-logo-remove-button" <?php echo $logo_id ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove Image', 'agewallet' ); ?></button>
		<p class="description"><?php esc_html_e( 'Optional. Choose a logo from the media library to display above the age verification gate.', 'agewallet' ); ?></p>
		<?php
	}

	public function render_number_input( $args ) {
		$option_name = $args['label_for'];
		$value       = get_option( $option_name, 0 );
		$class       = isset( $args['class'] ) ? $args['class'] : 'small-text';
		$min         = isset( $args['min'] ) ? $args['min'] : 0;
		$step        = isset( $args['step'] ) ? $args['step'] : 1;
		printf( '<input type="number" id="%1$s" name="%1$s" value="%2$s" class="%3$s" min="%4$s" step="%5$s" /> px', esc_attr( $option_name ), esc_attr( $value ), esc_attr( $class ), esc_attr( $min ), esc_attr( $step ) );
		if ( isset( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function render_wysiwyg_editor( $args ) {
		$option_name = $args['option_name'];
		$content     = get_option( $option_name, $this->get_default_copy() );
		$editor_id   = 'agewallet_wysiwyg_copy_editor';
		wp_editor(
			$content,
			$editor_id,
			array(
				'textarea_name' => $option_name,
				'textarea_rows' => 6,
				'media_buttons' => false,
				'tinymce'       => true,
				'quicktags'     => true,
			)
		);
		echo '<p class="description">' . sprintf( wp_kses( __( 'Customize the text shown on the age verification gate. HTML is allowed. Default: "%s"', 'agewallet' ), array() ), esc_html( $this->get_default_copy( false ) ) ) . '</p>';
	}

	public function render_checkbox( $args ) {
		$option_name = $args['label_for'];
		$checked     = get_option( $option_name, 0 );
		$label_text  = isset( $args['label'] ) ? $args['label'] : '';
		echo '<label for="' . esc_attr( $option_name ) . '">';
		printf( '<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s />', esc_attr( $option_name ), checked( 1, $checked, false ) );
		if ( $label_text ) {
			echo ' ' . esc_html( $label_text );
		}
		echo '</label>';
		if ( isset( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses( $args['desc'], array( 'code' => array() ) ) );
		}
	}

	public function render_css_editor( $args ) {
		$option_name = 'agewallet_custom_css';
		$value       = get_option( $option_name, '' );
		?>
		<textarea id="<?php echo esc_attr( $option_name ); ?>" name="<?php echo esc_attr( $option_name ); ?>" rows="10" class="large-text code" placeholder=".aw-gate__btn--yes { background-color: #222; }"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Enter custom CSS to style the Age Gate (and the Skeleton Loading Screen). This CSS remains active even if you switch themes.', 'agewallet' ); ?>
		</p>
		<?php
	}

	public function render_script_editor( $args ) {
		$option_name = $args['label_for'];
		$value       = get_option( $option_name, '' );
		$can_edit    = current_user_can( 'unfiltered_html' );
		?>
		<textarea id="<?php echo esc_attr( $option_name ); ?>" name="<?php echo esc_attr( $option_name ); ?>" rows="8" class="large-text code" <?php echo $can_edit ? '' : 'disabled'; ?>><?php echo esc_textarea( $value ); ?></textarea>
		<?php if ( ! $can_edit ) : ?>
			<p class="description" style="color: #d63638;">
				<strong><?php esc_html_e( 'Permission Denied:', 'agewallet' ); ?></strong>
				<?php esc_html_e( 'You do not have sufficient permissions to edit this field. If you are on a Multisite network, please contact your Super Admin. If you use a security plugin, ensure "Disable File Editing" is turned off.', 'agewallet' ); ?>
			</p>
		<?php else : ?>
			<p class="description"><?php echo wp_kses( $args['desc'], array( 'code' => array() ) ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_radio_buttons( $args ) {
		$option_name = $args['option_name'];
		$options     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		$current_val = get_option( $option_name, 'none' );
		echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Protection Mode Options', 'agewallet' ) . '</span></legend>';
		foreach ( $options as $value => $label ) {
			$input_id = $option_name . '_' . $value;
			echo '<div style="margin-bottom: 5px;">';
			printf( '<input type="radio" id="%1$s" name="%2$s" value="%3$s" %4$s />', esc_attr( $input_id ), esc_attr( $option_name ), esc_attr( $value ), checked( $value, $current_val, false ) );
			echo ' <label for="' . esc_attr( $input_id ) . '">' . wp_kses_post( $label ) . '</label>';
			echo '</div>';
		}
		echo '</fieldset>';
		if ( isset( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function render_protection_mode_radio() {
		$option_name = 'agewallet_protection_mode';
		$current     = get_option( $option_name, 'standard' );
		?>
		<fieldset>
			<div style="margin-bottom: 8px;">
				<label>
					<input type="radio" name="<?php echo esc_attr( $option_name ); ?>" value="standard" <?php checked( $current, 'standard' ); ?>>
					<strong><?php esc_html_e( 'Standard (Overlay)', 'agewallet' ); ?></strong> -
					<span class="description"><?php esc_html_e( 'Uses CSS to hide content. Better for SEO/Bots, but less secure.', 'agewallet' ); ?></span>
				</label>
			</div>
			<div>
				<label>
					<input type="radio" name="<?php echo esc_attr( $option_name ); ?>" value="strict" <?php checked( $current, 'strict' ); ?>>
					<strong><?php esc_html_e( 'High Security (Strict Mode)', 'agewallet' ); ?></strong> -
					<span class="description"><?php esc_html_e( 'Prevents the content from loading until verification is complete. Compatible with "Cache Everything" (Cloudflare/Varnish).', 'agewallet' ); ?></span>
				</label>
				<br><small style="margin-left: 25px; color: #666;">
					<strong><?php esc_html_e( 'Warning:', 'agewallet' ); ?></strong>
					<?php esc_html_e( 'This mode uses JavaScript hydration. It is generally incompatible with Lazy Loading plugins.', 'agewallet' ); ?>
				</small>
			</div>
		</fieldset>
		<?php
	}

	public function render_textarea( $args ) {
		$option_name  = $args['label_for'];
		$value        = get_option( $option_name, '' );
		$class        = isset( $args['class'] ) ? $args['class'] : 'large-text';
		$rows         = isset( $args['rows'] ) ? absint( $args['rows'] ) : 5;
		$placeholder  = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		echo '<div id="agewallet-blocked-paths-wrapper">';
		printf( '<textarea id="%1$s" name="%1$s" class="%2$s" rows="%3$d" placeholder="%4$s">%5$s</textarea>', esc_attr( $option_name ), esc_attr( $class ), $rows, esc_attr( $placeholder ), esc_textarea( $value ) );
		if ( isset( $args['desc'] ) ) {
			echo '<p class="description">' . wp_kses( $args['desc'], array( 'code' => array() ) ) . '</p>';
		}
		echo '</div>';
	}

	public function render_simple_textarea( $args ) {
		$option_name = $args['label_for'];
		$value       = get_option( $option_name, '' );
		$class       = isset( $args['class'] ) ? $args['class'] : 'large-text';
		$rows        = isset( $args['rows'] ) ? absint( $args['rows'] ) : 3;
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';

		printf( '<textarea id="%1$s" name="%1$s" class="%2$s" rows="%3$d" placeholder="%4$s">%5$s</textarea>', esc_attr( $option_name ), esc_attr( $class ), $rows, esc_attr( $placeholder ), esc_textarea( $value ) );
		if ( isset( $args['desc'] ) ) {
			echo '<p class="description">' . wp_kses( $args['desc'], array( 'code' => array() ) ) . '</p>';
		}
	}

	// --- New Renderer: Taxonomy Rules UI (Multi-Input) ---
	public function render_taxonomy_rules_ui() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$saved_rules = get_option( 'agewallet_taxonomy_rules', array() );

		// Inline styles for badges
		?>
		<style>
			.aw-badges-container { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; min-height: 20px; }
			.aw-term-badge { display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; border: 1px solid #ccc; }
			.aw-term-badge.type-gate { background: #ffe6e6; border-color: #ffcccc; color: #8a0000; }
			.aw-term-badge.type-exclude { background: #e6ffec; border-color: #ccffdd; color: #005a1a; }
			.aw-remove-term { margin-left: 6px; cursor: pointer; font-weight: bold; opacity: 0.6; }
			.aw-remove-term:hover { opacity: 1; }
			.aw-tax-specific-wrap { margin-top: 10px; padding-left: 10px; border-left: 2px solid #eee; }
			.aw-input-group { margin-bottom: 10px; }
			.aw-input-group label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 12px; }
		</style>
		<?php

		if ( empty( $taxonomies ) ) {
			echo '<p>' . esc_html__( 'No public taxonomies found.', 'agewallet' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Configure blocking rules for Categories, Tags, and Custom Taxonomies.', 'agewallet' ) . '</p>';
		echo '<table class="widefat striped" style="margin-top:10px; max-width: 100%;">';
		echo '<thead><tr>';
		echo '<th style="width: 25%;">' . esc_html__( 'Taxonomy', 'agewallet' ) . '</th>';
		echo '<th style="width: 20%;">' . esc_html__( 'Mode', 'agewallet' ) . '</th>';
		echo '<th>' . esc_html__( 'Specific Terms Configuration', 'agewallet' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $taxonomies as $tax_slug => $tax_obj ) {
			// Exclude post_format
			if ( 'post_format' === $tax_slug ) {
				continue;
			}

			$mode = isset( $saved_rules[ $tax_slug ]['mode'] ) ? $saved_rules[ $tax_slug ]['mode'] : 'ignore';

			// Saved IDs (Strings)
			$gate_ids_str = isset( $saved_rules[ $tax_slug ]['terms_gate'] ) ? $saved_rules[ $tax_slug ]['terms_gate'] : '';
			$exclude_ids_str = isset( $saved_rules[ $tax_slug ]['terms_exclude'] ) ? $saved_rules[ $tax_slug ]['terms_exclude'] : '';

			echo '<tr class="aw-tax-row" data-taxonomy="' . esc_attr( $tax_slug ) . '">';
			// Label
			echo '<td><strong>' . esc_html( $tax_obj->label ) . '</strong><br><small><code>' . esc_html( $tax_slug ) . '</code></small></td>';

			// Mode Dropdown
			echo '<td style="vertical-align: top; padding-top: 15px;">';
			echo '<select name="agewallet_taxonomy_rules[' . esc_attr( $tax_slug ) . '][mode]" class="aw-tax-mode">';
			echo '<option value="ignore" ' . selected( $mode, 'ignore', false ) . '>' . esc_html__( 'Ignore (Default)', 'agewallet' ) . '</option>';
			echo '<option value="gate_all" ' . selected( $mode, 'gate_all', false ) . '>' . esc_html__( 'Gate All Terms', 'agewallet' ) . '</option>';
			echo '<option value="exclude_all" ' . selected( $mode, 'exclude_all', false ) . '>' . esc_html__( 'Exclude All Terms', 'agewallet' ) . '</option>';
			echo '<option value="specific" ' . selected( $mode, 'specific', false ) . '>' . esc_html__( 'Specific Rules', 'agewallet' ) . '</option>';
			echo '</select>';
			echo '</td>';

			// Specific Inputs
			$display_style = ( 'specific' === $mode ) ? '' : 'display:none;';
			echo '<td>';
			echo '<div class="aw-tax-terms-wrap" style="' . esc_attr( $display_style ) . '">';

			// --- 1. Gate Terms Input ---
			echo '<div class="aw-input-group">';
			echo '<label>' . esc_html__( 'Gate these Terms:', 'agewallet' ) . '</label>';
			// Badges
			echo '<div class="aw-badges-container badges-gate">';
			if ( ! empty( $gate_ids_str ) ) {
				$term_ids = explode( ',', $gate_ids_str );
				foreach ( $term_ids as $term_id ) {
					$term = get_term( (int) $term_id, $tax_slug );
					if ( $term && ! is_wp_error( $term ) ) {
						echo '<span class="aw-term-badge type-gate" data-id="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '<span class="aw-remove-term dashicons dashicons-no-alt"></span></span>';
					}
				}
			}
			echo '</div>';
			// Hidden Storage
			echo '<input type="hidden" name="agewallet_taxonomy_rules[' . esc_attr( $tax_slug ) . '][terms_gate]" value="' . esc_attr( $gate_ids_str ) . '" class="aw-hidden-gate" />';
			// Search Input
			echo '<input type="text" class="large-text aw-term-search aw-search-gate" placeholder="' . esc_attr__( 'Search to gate...', 'agewallet' ) . '" />';
			echo '</div>'; // End Group

			// --- 2. Exclude Terms Input ---
			echo '<div class="aw-input-group">';
			echo '<label style="color: #005a1a;">' . esc_html__( 'Exclude these Terms (Overrides Gating):', 'agewallet' ) . '</label>';
			// Badges
			echo '<div class="aw-badges-container badges-exclude">';
			if ( ! empty( $exclude_ids_str ) ) {
				$term_ids = explode( ',', $exclude_ids_str );
				foreach ( $term_ids as $term_id ) {
					$term = get_term( (int) $term_id, $tax_slug );
					if ( $term && ! is_wp_error( $term ) ) {
						echo '<span class="aw-term-badge type-exclude" data-id="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '<span class="aw-remove-term dashicons dashicons-no-alt"></span></span>';
					}
				}
			}
			echo '</div>';
			// Hidden Storage
			echo '<input type="hidden" name="agewallet_taxonomy_rules[' . esc_attr( $tax_slug ) . '][terms_exclude]" value="' . esc_attr( $exclude_ids_str ) . '" class="aw-hidden-exclude" />';
			// Search Input
			echo '<input type="text" class="large-text aw-term-search aw-search-exclude" placeholder="' . esc_attr__( 'Search to exclude...', 'agewallet' ) . '" />';
			echo '</div>'; // End Group

			echo '</div>'; // End Wrapper
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// --- Renderer: Cache TTL Dropdown ---
	public function render_cache_ttl_dropdown() {
		$current = (int) get_option( 'agewallet_cache_ttl', 14400 );
		$options = array(
			7200   => __( 'Every 2 Hours', 'agewallet' ),
			14400  => __( 'Every 4 Hours', 'agewallet' ),
			21600  => __( 'Every 6 Hours', 'agewallet' ),
			28800  => __( 'Every 8 Hours', 'agewallet' ),
			36000  => __( 'Every 10 Hours', 'agewallet' ),
			43200  => __( 'Every 12 Hours', 'agewallet' ),
			86400  => __( 'Every 24 Hours', 'agewallet' ),
			172800 => __( 'Every 48 Hours', 'agewallet' ),
			259200 => __( 'Every 72 Hours', 'agewallet' ),
		);
		echo '<select name="agewallet_cache_ttl">';
		foreach ( $options as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Automatically clear the strict mode HTML cache on this schedule. Defaults to 4 hours.', 'agewallet' ) . '</p>';
	}

	// --- Sanitization Callbacks ---

	public function sanitize_positive_int( $input ) {
		return absint( $input );
	}

	public function sanitize_metadata( $input ) {
		$value = is_scalar( $input ) ? (string) $input : '';
		$value = wp_strip_all_tags( $value );
		if ( strlen( $value ) > AgeWalletOIDCClientPro::METADATA_MAX_BYTES ) {
			$value = substr( $value, 0, AgeWalletOIDCClientPro::METADATA_MAX_BYTES );
		}
		return $value;
	}

	public function sanitize_wc_metadata_fields( $input ) {
		$allowed = array( 'cart_hash', 'cart_total', 'currency', 'customer_id', 'billing_country', 'line_item_count' );
		if ( ! is_array( $input ) ) {
			return array();
		}
		return array_values( array_intersect( $allowed, array_map( 'sanitize_key', $input ) ) );
	}

	public function sanitize_metadata_mode( $input ) {
		$allowed = AgeWallet_Metadata_Builder::allowed_modes();
		return in_array( $input, $allowed, true ) ? $input : AgeWallet_Metadata_Builder::MODE_STATIC;
	}

	public function sanitize_auto_metadata_fields( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$allowed   = AgeWallet_Metadata_Builder::allowed_auto_fields();
		$sanitized = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $input ) ) );

		// In strict mode, cache-unsafe keys are persistently stripped — the option store stays clean
		// even if a stale submission tried to include them.
		if ( AgeWallet_Metadata_Builder::is_strict_mode() ) {
			$sanitized = array_values( array_diff( $sanitized, AgeWallet_Metadata_Builder::cache_unsafe_fields() ) );
		}

		return $sanitized;
	}

	public function sanitize_wysiwyg( $input ) {
		return wp_kses_post( $input );
	}

	public function sanitize_custom_css( $input ) {
		return wp_strip_all_tags( $input );
	}

	public function sanitize_script_field( $input ) {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return wp_strip_all_tags( $input );
		}
		return $input;
	}

	public function sanitize_checkbox( $input ) {
		return ( isset( $input ) && '1' === $input ) ? 1 : 0;
	}

	public function sanitize_block_mode( $input ) {
		$allowed_modes = array( 'none', 'all_but_home', 'all', 'specific' );
		if ( in_array( $input, $allowed_modes, true ) ) {
			return $input;
		}
		return 'none';
	}

	public function sanitize_paths_textarea( $input ) {
		if ( empty( trim( $input ) ) ) {
			return '';
		}
		$paths       = explode( ',', $input );
		$clean_paths = array();
		foreach ( $paths as $path ) {
			$path = trim( $path );
			if ( empty( $path ) ) {
				continue;
			}
			if ( strpos( $path, '/' ) !== 0 ) {
				$path = '/' . $path;
			}
			$sanitized_path = sanitize_text_field( $path );
			if ( ! empty( $sanitized_path ) && ( strlen( $sanitized_path ) > 1 || '/' === $sanitized_path ) ) {
				$clean_paths[] = $sanitized_path;
			}
		}
		$unique_paths = array_unique( $clean_paths );
		return implode( ',', $unique_paths );
	}

	// Sanitize Taxonomy Rules
	public function sanitize_taxonomy_rules( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$clean = array();
		$allowed_modes = array( 'ignore', 'gate_all', 'exclude_all', 'specific' );

		foreach ( $input as $tax => $data ) {
			$sanitized_tax = sanitize_key( $tax );
			$mode = isset( $data['mode'] ) && in_array( $data['mode'], $allowed_modes, true ) ? $data['mode'] : 'ignore';

			// Helper to clean ID lists
			$clean_ids = function( $str ) {
				if ( empty( $str ) ) return '';
				$ids = explode( ',', $str );
				$out = array();
				foreach ( $ids as $id ) {
					$out[] = absint( trim( $id ) );
				}
				return implode( ',', array_filter( $out ) );
			};

			$terms_gate = isset( $data['terms_gate'] ) ? $clean_ids( $data['terms_gate'] ) : '';
			$terms_exclude = isset( $data['terms_exclude'] ) ? $clean_ids( $data['terms_exclude'] ) : '';

			$clean[ $sanitized_tax ] = array(
				'mode'          => $mode,
				'terms_gate'    => $terms_gate,
				'terms_exclude' => $terms_exclude,
			);
		}
		return $clean;
	}

	// --- Readme Parsing ---

	private function get_readme_section_content( $section_title ) {
		$readme_path = AGEWALLET_PLUGIN_DIR . 'readme.txt';
		if ( ! file_exists( $readme_path ) ) {
			return '';
		}
		$readme_content = file_get_contents( $readme_path );
		$readme_content = str_replace( array( "\r\n", "\r" ), "\n", $readme_content );
		if ( empty( $readme_content ) ) {
			return '';
		}
		$pattern = '/^==\s*' . preg_quote( $section_title, '/' ) . '\s*==\s*(.*?)(?=\n==\s*|\z)/sm';
		if ( preg_match( $pattern, $readme_content, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	private function parse_readme_markdown( $content ) {
		$content = preg_replace( '/^=\s*(.*?)\s*=/m', '<h3>$1</h3>', $content );
		$content = preg_replace( '/^>(.*)/m', '<blockquote>$1</blockquote>', $content );
		$content = str_replace( "</blockquote>\n<blockquote>", "\n", $content );
		$content = preg_replace_callback( '/(^\*\s*.*(?:\n^\*\s*.*)*)/m', function( $matches ) {
			$items = preg_replace( '/^\*\s*(.*)/m', '<li>$1</li>', $matches[0] );
			return "<ul>\n" . $items . "\n</ul>\n";
		}, $content );
		$content = preg_replace_callback( '/(^(\d+\.)\s*.*(?:\n^\d+\.\s*.*)*)/m', function( $matches ) {
			$items = preg_replace( '/^(\d+\.)\s*(.*)/m', '<li>$2</li>', $matches[0] );
			return "<ol>\n" . $items . "\n</ol>\n";
		}, $content );
		$content = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $content );
		$content = preg_replace( '/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $content );
		$content = preg_replace( '/\*([^\*]+)\*/s', '<em>$1</em>', $content );
		$content = preg_replace( '/`(.*?)`/', '<code>$1</code>', $content );
		$content = preg_replace( '/`\n(.*?)\n`/s', '<pre><code>$1</code></pre>', $content );
		$blocks      = explode( "\n\n", $content );
		$html_blocks = array();
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( empty( $block ) ) {
				continue;
			}
			if ( ! preg_match( '/^<(h3|ul|ol|blockquote|pre)/', $block ) ) {
				$html_blocks[] = '<p>' . nl2br( $block ) . '</p>';
			} else {
				$html_blocks[] = $block;
			}
		}
		$content = implode( "\n\n", $html_blocks );
		return $content;
	}

	private function get_default_copy( $include_html = true ) {
		$partner_name = 'AgeWallet™';
		$partner_link = '<a href="https://www.agewallet.com" target="_blank" rel="noopener">' . $partner_name . '</a>';
		$text_format  = __( 'You must be 18+ to view this content (or meet the minimum age required by your local jurisdiction). By selecting “I Agree,” you confirm that you meet the minimum age requirement and consent to verification by our partner, %s. If you do not meet the minimum age requirement or do not agree, please select “I Disagree.”', 'agewallet' );
		return sprintf( $text_format, $include_html ? $partner_link : $partner_name );
	}

	// --- AJAX Handlers ---

	public function handle_purge_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission Denied', 'agewallet' ) );
		}
		if ( class_exists( 'AgeWallet_API' ) ) {
			$count = AgeWallet_API::instance()->clear_all_cache();
			wp_send_json_success( sprintf( __( 'Cache purged successfully! %d files deleted.', 'agewallet' ), $count ) );
		} else {
			wp_send_json_error( __( 'API Class not loaded.', 'agewallet' ) );
		}
	}

	// AJAX Handler for Term Search
	public function handle_term_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_text_field( $_GET['taxonomy'] ) : '';
		$term_search = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : '';

		if ( empty( $taxonomy ) || empty( $term_search ) ) {
			wp_send_json_error( 'Missing parameters.' );
		}

		$terms = get_terms( array(
			'taxonomy' => $taxonomy,
			'name__like' => $term_search,
			'hide_empty' => false,
			'number' => 20
		) );

		if ( is_wp_error( $terms ) ) {
			wp_send_json_error( $terms->get_error_message() );
		}

		$results = array();
		foreach ( $terms as $term ) {
			$results[] = array(
				'id' => $term->term_id,
				'label' => $term->name . ' (ID: ' . $term->term_id . ')',
				'value' => $term->term_id
			);
		}

		wp_send_json_success( $results );
	}

	// --- Enqueue ---

	public function enqueue_admin_scripts( $hook_suffix ) {
		// Match any page that starts with agewallet-
		if ( strpos( $hook_suffix, 'agewallet-' ) === false ) {
			return;
		}

		// We enable the media uploader on the appearance page specifically, but general JS everywhere for the Purge button.
		wp_enqueue_media();
		// Enqueue jQuery UI Autocomplete for Taxonomy Rules
		wp_enqueue_script( 'jquery-ui-autocomplete' );

		wp_enqueue_script(
			'agewallet-admin-settings',
			AGEWALLET_PLUGIN_URL . 'assets/js/admin-settings.js',
			array( 'jquery', 'wp-i18n', 'jquery-ui-autocomplete' ),
			AGEWALLET_VERSION,
			true
		);

		wp_localize_script(
			'agewallet-admin-settings',
			'agewalletAdminData',
			array(
				'mediaFrameTitle'       => __( 'Select or Upload Gate Logo', 'agewallet' ),
				'mediaFrameButton'      => __( 'Use this image', 'agewallet' ),
				'logoInputId'           => 'aw-logo-id-input',
				'logoPreviewId'         => 'aw-logo-preview',
				'selectButtonId'        => 'aw-logo-select-button',
				'removeButtonId'        => 'aw-logo-remove-button',
				'blockModeOptionName'   => AgeWalletOIDCClientPro::OPT_BLOCK_MODE,
				'blockedPathsWrapperId' => 'agewallet-blocked-paths-wrapper',
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/** Cloning forbidden. @since 0.1.0 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'agewallet' ), '0.1.0' );
	}
	/** Unserializing forbidden. @since 0.1.0 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing forbidden.', 'agewallet' ), '0.1.0' );
	}

} // End class AgeWallet_Admin