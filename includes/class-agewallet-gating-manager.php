<?php
 // Prevent direct script access.
 defined('ABSPATH') || exit;

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
         $this->log_debug('[Gating Manager] __construct started.');

         // Front-end Hooks
         add_action('wp_enqueue_scripts', array( $this, 'register_assets' )); // Register always
         add_action('wp_enqueue_scripts', array( $this, 'maybe_enqueue_gate_assets' ), 20); // Conditionally enqueue later
         add_filter('body_class', array( $this, 'add_body_classes' )); // Add initial hiding class conditionally

         // Shortcode Hooks
         add_shortcode('agewallet_button', array( $this, 'button_shortcode_handler' ));
         add_shortcode('agewallet_protected', array( $this, 'protected_content_shortcode_handler' ));

         // Meta Box Hooks (Admin-side)
         add_action('add_meta_boxes', array( $this, 'add_restriction_meta_box' ));
         add_action('save_post', array( $this, 'save_restriction_meta_box' ));

         $this->log_debug('[GGating Manager] __construct finished, hooks added.');
     }

     /**
      * Registers front-end CSS and JS assets. Hooked to 'wp_enqueue_scripts'.
      * @since 0.1.0
      */
     public function register_assets() {
         // Register Stylesheet
         $style_path = 'assets/css/gate.css';
         $style_ver = AGEWALLET_VERSION;
         if ( defined('WP_DEBUG') && WP_DEBUG ) {
             $file_path = AGEWALLET_PLUGIN_DIR . $style_path;
             if ( file_exists($file_path) ) { $style_ver = filemtime($file_path) ?: $style_ver; }
         }
         wp_register_style( self::STYLE_HANDLE, AGEWALLET_PLUGIN_URL . $style_path, array(), $style_ver, 'all');

         // Register JavaScript
         $script_path = 'assets/js/gate.js';
         $script_ver = AGEWALLET_VERSION;
         if ( defined('WP_DEBUG') && WP_DEBUG ) {
             $file_path = AGEWALLET_PLUGIN_DIR . $script_path;
             if ( file_exists($file_path) ) { $script_ver = filemtime($file_path) ?: $script_ver; }
         }
         wp_register_script( self::SCRIPT_HANDLE, AGEWALLET_PLUGIN_URL . $script_path, array('jquery'), $script_ver, true);

         $this->log_debug('[Gating Manager] Assets registered.');
     }

     /**
      * Determines if the current page view requires gating or uses protected content,
      * and enqueues assets if needed. Hooked to 'wp_enqueue_scripts' priority 20.
      * Checks global settings, per-post meta, and shortcode presence.
      *
      * @since 0.1.0
      */
     public function maybe_enqueue_gate_assets() {
         $this->log_debug('[Gating Manager] Checking if gate assets should be enqueued.');

         // --- Initial Checks (Conditions where gating NEVER applies) ---
         if ( is_admin() ) {
             $this->log_debug('Skipping gate: Is admin area.');
             return;
         }
         if ( defined( 'WP_CLI' ) ) {
              $this->log_debug('Skipping gate: Is WP-CLI request.');
              return;
         }
         if ( defined('REST_REQUEST') && REST_REQUEST ) {
              $this->log_debug('Skipping gate: Is REST API request.');
              return;
         }
         if ( is_feed() ) {
              $this->log_debug('Skipping gate: Is feed request.');
              return;
         }
         if ( is_preview() || is_customize_preview() ) {
              $this->log_debug('Skipping gate: Is preview or customizer.');
              return;
         }
         if ( is_user_logged_in() && current_user_can('edit_others_posts') ) {
             $this->log_debug('Skipping gate: User is Admin or Editor.');
             return;
         }
         if ( isset($_COOKIE[self::VERIFIED_COOKIE_NAME]) && '1' === $_COOKIE[self::VERIFIED_COOKIE_NAME] ) {
              $this->log_debug('Skipping gate: Verified cookie already present (server-side check).');
              return;
         }
         // --- End Initial Checks ---

         // --- Determine Gating Requirement based on Settings ---
         $needs_gating_js_css = false;
         $should_gate_automatically = false;
         $post_id = null;
         $post_requires_gating = false;
         $post_is_excluded = false;

         // Check Post Meta Settings (only if viewing a single post/page)
         if ( is_singular() ) {
             $post_id = get_the_ID();
             if ($post_id) {
                 $force_exclude = get_post_meta($post_id, self::META_KEY_FORCE_EXCLUDE, true);
                 $force_restrict = get_post_meta($post_id, self::META_KEY_FORCE_RESTRICT, true);

                 if ( '1' === $force_exclude ) {
                     $post_is_excluded = true;
                     $this->log_debug('Gating check: Post is explicitly excluded via meta.', ['post_id' => $post_id]);
                 } elseif ( '1' === $force_restrict ) {
                     $post_requires_gating = true;
                     $this->log_debug('Gating check: Post is explicitly restricted via meta.', ['post_id' => $post_id]);
                 }
             }
         }

         // If post explicitly excluded, stop here (highest precedence).
         if ( $post_is_excluded ) {
             $this->log_debug('Asset check: Post excluded via meta.');
             return;
         }

         // Check Global Settings for automatic gating
         $global_block_mode = get_option(AgeWalletOIDCClientPro::OPT_BLOCK_MODE, 'none');
         $global_requires_gating = false;
         switch ($global_block_mode) {
             case 'all':
                 $global_requires_gating = true;
                 $this->log_debug('Gating check: Global mode "all" applies.');
                 break;
             case 'all_but_home':
                 if ( ! is_front_page() ) {
                     $global_requires_gating = true;
                     $this->log_debug('Gating check: Global mode "all_but_home" applies (not front page).');
                 } else {
                     $this->log_debug('Gating check: Global mode "all_but_home" skipped (is front page).');
                 }
                 break;
             case 'specific':
                 $blocked_paths_str = get_option(AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, '');
                 $this->log_debug('Gating check: Global mode "specific" checking paths.', ['paths_to_check' => $blocked_paths_str]);
                 if ( ! empty($blocked_paths_str) ) {
                     $blocked_paths = array_map('trim', explode(',', $blocked_paths_str));
                     $current_path_for_match = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
                     $this->log_debug('Gating check: "specific" details.', ['current_path' => $current_path_for_match]);
                     foreach ( $blocked_paths as $blocked_path ) {
                         if ( empty($blocked_path) || '/' !== $blocked_path[0] ) continue;
                         // Normalize paths for matching (ensure trailing slash consistency)
                         $blocked_path_norm = trailingslashit($blocked_path);
                         $current_path_norm = trailingslashit($current_path_for_match);
                         // *** Use strpos (contains) to match within the url, not just start ***
                         if (strpos($current_path_norm, $blocked_path_norm) !== false || ($blocked_path === '/' && $current_path_for_match === '/')) {
                             $global_requires_gating = true;
                             $this->log_debug('Gating check: Global mode "specific" applies.', ['matched_path' => $blocked_path]);
                             break;
                         }
                     }
                 }
                 if (! $global_requires_gating) {
                     $this->log_debug('Gating check: Global mode "specific" did not match any paths.');
                 }
                 break;
             default: // 'none'
                 $this->log_debug('Gating check: Global mode is "none".');
                 break;
         }

         // Determine if automatic overlay should trigger based on global/post settings
         $should_gate_automatically = ( $global_requires_gating || $post_requires_gating );

         // HOOK: Allow developers to override the automatic gating decision.
         $should_gate_automatically = apply_filters('agewallet_should_gate_request', $should_gate_automatically, $post_id);

         // Check for wrapping shortcode presence
         $has_protected_shortcode = false;
         $post_obj = get_post();
         if ( is_singular() && isset($post_obj) && is_object($post_obj) && property_exists($post_obj, 'post_content') && has_shortcode( $post_obj->post_content, 'agewallet_protected' ) ) {
             $has_protected_shortcode = true;
             $this->log_debug('Asset check: Found [agewallet_protected] shortcode in content.');
         }

         // Final Decision Logic
         $needs_gating_js_css = ( $should_gate_automatically || $has_protected_shortcode );

         // Enqueue Assets and Localize
         if ( $needs_gating_js_css && ! $this->assets_enqueued ) {
             $this->log_debug('Final Decision: Enqueuing assets.');

             wp_enqueue_style(self::STYLE_HANDLE);
             wp_enqueue_script(self::SCRIPT_HANDLE);

             $script_data = array(
                 'cookieName' => self::VERIFIED_COOKIE_NAME,
                 'bodyClassPending' => 'agewallet-gated-pending',
                 'gateHtml'   => '',
                 'isOverlayActive' => false
             );

             if ($should_gate_automatically) {
                  $script_data['gateHtml'] = $this->get_gate_html();
                  $script_data['isOverlayActive'] = true;
                  $this->log_debug('Localizing script data including overlay HTML.');
                  add_filter('__agewallet_is_gating_this_request', '__return_true');
             } else {
                  $this->log_debug('Localizing script data for protected shortcode only (no overlay HTML needed).');
             }

             // HOOK: Allow developers to add or modify data passed to the front-end script.
             $script_data = apply_filters('agewallet_gate_script_data', $script_data);

             wp_localize_script(self::SCRIPT_HANDLE, 'agewallet_gate_data', $script_data);
             $this->log_debug('Gate assets enqueued and data localized.', ['isOverlay' => $should_gate_automatically, 'hasShortcode' => $has_protected_shortcode]);

             $this->assets_enqueued = true;

         } elseif ($this->assets_enqueued) {
             $this->log_debug('Assets already enqueued for this request.');
         } else {
             $this->log_debug('Final Decision: No assets needed.');
         }
     }

     /**
      * Adds CSS classes to the body tag when automatic overlay gating might be applied.
      * @since 0.1.0
      */
     public function add_body_classes( $classes ) {
         if ( apply_filters('__agewallet_is_gating_this_request', false) ) {
             $classes[] = 'agewallet-gated-pending';
             $this->log_debug('[Gating Manager] Added body class: agewallet-gated-pending.');
         }
         return $classes;
     }

     // --- Shortcode Handling ---

     /**
      * Handles the [agewallet_button] shortcode. Note: This shortcode is mostly deprecated in favor of automatic gating and the [agewallet_protected] paired shortcode wrapper. This is for flow testihng mainly.
      * @since 0.1.0
      */
     public function button_shortcode_handler($atts = []) {
         $this->log_debug('[Gating Manager] Button shortcode handler called.');

         if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
             wp_enqueue_style(self::STYLE_HANDLE);
             $this->log_debug('[Gating Manager] Gate style enqueued via button shortcode (fallback).');
         }

         // --- Check if user is verified (client-side) ---
         // We still output the HTML, but gate.js will hide this wrapper if user is verified.
         $output_html = $this->get_gate_html();
         if (!empty($output_html)) {
             return '<div class="agewallet-shortcode-wrapper">' . $output_html . '</div>';
         }
         return '';
     }

     /**
      * Handles the [agewallet_protected]...[/agewallet_protected] shortcode.
      * @since 0.1.0
      */
     public function protected_content_shortcode_handler($atts = [], $content = null) {
         $this->log_debug('[Gating Manager] Protected content shortcode handler called.');

         // --- Check if user is admin/editor and return raw content if so ---
         if ( is_user_logged_in() && current_user_can('edit_others_posts') ) {
             $this->log_debug('[Gating Manager] Protected shortcode skipped: User is Admin or Editor.');
             return do_shortcode( $content ); // Return the processed inner content directly
         }
         // --- END Check ---

         // --- Check if user is already verified (server-side cookie check) ---
         // If verified, just return the content directly.
         // This avoids showing a flash of the placeholder before JS runs.
         if ( isset($_COOKIE[self::VERIFIED_COOKIE_NAME]) && '1' === $_COOKIE[self::VERIFIED_COOKIE_NAME] ) {
              $this->log_debug('[Gating Manager] Protected shortcode: User is verified (server-side cookie), showing content directly.');
              return do_shortcode( $content ); // Return the processed inner content
         }
         // --- END Check ---

         // Ensure assets are loaded if maybe_enqueue_gate_assets didn't run or detect it early enough.
         if ( ! $this->assets_enqueued ) {
              $this->log_debug('[Gating Manager] Enqueuing assets from protected_content_shortcode_handler (fallback needed).');
              wp_enqueue_style(self::STYLE_HANDLE);
              wp_enqueue_script(self::SCRIPT_HANDLE);

              if (!wp_script_is(self::SCRIPT_HANDLE, 'data')) {
                 $script_data = array(
                     'cookieName' => self::VERIFIED_COOKIE_NAME,
                     'bodyClassPending' => 'agewallet-gated-pending',
                     'gateHtml'   => '',
                     'isOverlayActive' => false
                 );
                 wp_localize_script(self::SCRIPT_HANDLE, 'agewallet_gate_data', $script_data);
                 $this->log_debug('[GGating Manager] Localized minimal script data via shortcode fallback.');
              } else {
                  $this->log_debug('[Gating Manager] Script data already localized, skipping in shortcode fallback.');
              }
              $this->assets_enqueued = true;
         }

         $processed_content = do_shortcode( $content );
         $placeholder_html = $this->get_gate_html();
         if (empty($placeholder_html)) {
             $placeholder_html = '<p style="color:red;">Error: Could not generate verification prompt.</p>';
         }

         // Output the wrapper structure. Content starts hidden, placeholder starts visible.
         $output = '<div class="agewallet-protected-wrapper">';
         $output .= '<div class="agewallet-protected-content" style="display: none;">';
         $output .= $processed_content;
         $output .= '</div>';
         $output .= '<div class="agewallet-protected-placeholder" style="display: block;">';
         $output .= $placeholder_html;
         $output .= '</div>';
         $output .= '</div>';

         return $output;
     }

     /**
     * Generates the HTML markup for the age gate prompt.
     * @since 0.1.0
     * @access private
     */
    private function get_gate_html() {
        $this->log_debug('[Gating Manager] Generating gate HTML.');

        // --- Step 1: Gather all data points into variables ---
        $logo_id    = (int) get_option(AgeWalletOIDCClientPro::OPT_LOGO_ID, 0);
        $logo_width = (int) get_option(AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, 0);
        $copy_html  = get_option(AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, '');
        $hide_h1    = (bool) get_option(AgeWalletOIDCClientPro::OPT_HIDE_HEADING, 0);
        $logo_src   = $logo_id > 0 ? wp_get_attachment_image_url($logo_id, 'full') : '';

        if ( ! empty($copy_html) ) {
            $desc_html = wpautop(trim($copy_html));
        } else {
            $default_copy = __('You must be 18+ to view this content (or meet the minimum age required by your local jurisdiction). By selecting “I Agree,” you confirm that you meet the minimum age requirement and consent to verification by our partner, AgeWallet™. If you do not meet the minimum age requirement or do not agree, please select “I Disagree.”', 'agewallet');
            $desc_html = '<p>' . esc_html($default_copy) . '</p>';
        }

        $current_url = AgeWallet_Helpers::instance()->get_current_url();
        $launch_url  = AgeWallet_Helpers::instance()->get_launch_url();
        $agree_href  = $launch_url ? add_query_arg('redirect_to', urlencode($current_url), $launch_url) : '';

        if ( ! $agree_href ) {
             $this->log_debug('[Gating Manager] ERROR: Could not get launch URL for gate HTML.');
             return '<p style="color:red;">Error: Could not determine launch URL.</p>';
        }

        // --- Step 2: Consolidate data into a filterable arguments array ---
        $args = array(
            'logo_src'          => $logo_src,
            'logo_alt'          => __('Logo', 'agewallet'),
            'logo_width'        => $logo_width,
            'show_title'        => ! $hide_h1,
            'title'             => __('You Must Verify Your Age', 'agewallet'),
            'description_html'  => $desc_html,
            'agree_url'         => $agree_href,
            'agree_text'        => __('I Agree', 'agewallet'),
            'disagree_text'     => __('I Disagree', 'agewallet'),
            'error_text'        => __('Sorry, you do not meet the minimum requirements to view this content.', 'agewallet'),
            'disclaimer_html'   => sprintf(
                /* translators: %s: URL to AgeWallet website */
                __('By proceeding you agree to allow %s to verify your age.', 'agewallet'),
                '<a href="https://www.agewallet.com" target="_blank" rel="noopener noreferrer">AgeWallet™</a>'
            ),
        );

        // HOOK: Allow developers to modify all gate template arguments.
        $args = apply_filters('agewallet_gate_template_args', $args);

        // --- Step 3: Build the HTML using the (potentially modified) $args array ---
        ob_start();
        ?>
        <div class="aw-gate aw-gate__card">
            <?php if ( ! empty($args['logo_src']) ): ?>
                <div class="aw-gate__logo-wrap">
                    <img class="aw-gate__logo"
                         src="<?php echo esc_url($args['logo_src']); ?>"
                         alt="<?php echo esc_attr($args['logo_alt']); ?>"
                         <?php if ( ! empty($args['logo_width']) && $args['logo_width'] > 0): ?>
                             style="width:<?php echo intval($args['logo_width']); ?>px; max-width:100%; height:auto;"
                         <?php else: ?>
                             style="max-width:100%; height:auto;"
                         <?php endif; ?>
                    >
                </div>
            <?php endif; ?>

            <?php if ( ! empty($args['show_title']) ): ?>
                <h1 class="aw-gate__title"><?php echo esc_html($args['title']); ?></h1>
            <?php endif; ?>

            <div class="aw-gate__desc">
                <?php echo wp_kses_post( $args['description_html'] ); ?>
            </div>

            <div class="aw-gate__buttons">
                <button class="aw-gate__btn aw-gate__btn--no" type="button" onclick="var err = this.closest('.aw-gate').querySelector('.aw-gate__error'); if(err) err.style.display='block'; return false;">
                    <?php echo esc_html($args['disagree_text']); ?>
                </button>
                <button class="aw-gate__btn aw-gate__btn--yes" type="button" data-redirect-url="<?php echo esc_url($args['agree_url']); ?>">
                    <?php echo esc_html($args['agree_text']); ?>
                </button>
            </div>

            <?php
            // HOOK: Add content after the gate buttons.
            do_action('agewallet_after_gate_buttons');
            ?>

            <div class="aw-gate__error" style="display:none;"><?php echo esc_html($args['error_text']); ?></div>

            <p class="aw-gate__disclaimer">
                <?php
                echo wp_kses(
                    $args['disclaimer_html'],
                    [ 'a' => [ 'href' => true, 'target' => true, 'rel' => true ] ]
                );
                ?>
            </p>
        </div>
        <?php
        $html = ob_get_clean();
        $this->log_debug('[Gating Manager] Finished generating gate HTML.', ['html_length' => strlen($html)]);
        return $html;
    }
     // --- Meta Box Handling ---

     /**
      * Registers the meta box on relevant post types. Hooked to 'add_meta_boxes'.
      * @since 0.1.0
      */
     public function add_restriction_meta_box() {
         $this->log_debug('[Gating Manager] Adding meta boxes.');
         $post_types = get_post_types( array( 'public' => true ), 'names' );
         $post_types = apply_filters('agewallet_meta_box_post_types', $post_types);

         if ( empty($post_types) ) {
             $this->log_debug('[Gating Manager] No public post types found to add meta box to.');
             return;
         }

         $this->log_debug('[Gating Manager] Adding meta box to post types.', ['post_types' => $post_types]);

         foreach ( $post_types as $post_type ) {
             add_meta_box(
                 'agewallet_restriction_meta',
                 __( 'Age Restriction', 'agewallet' ),
                 array( $this, 'render_restriction_meta_box' ),
                 $post_type, 'side', 'default'
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
         $force_exclude = get_post_meta( $post->ID, self::META_KEY_FORCE_EXCLUDE, true );
         ?>
         <p>
             <label for="agewallet_force_restrict">
                 <input type="checkbox" id="agewallet_force_restrict" name="agewallet_force_restrict" value="1"
                        <?php checked( $force_restrict, '1' ); ?>
                        <?php echo ($force_exclude === '1') ? 'disabled="disabled"' : ''; ?> />
                 <?php esc_html_e( 'Require age verification', 'agewallet' ); ?>
             </label><br>
             <small><?php esc_html_e( "(Overrides Global 'None' setting)", 'agewallet' ); ?></small>
         </p>
         <hr style="margin: 10px 0;">
         <p>
             <label for="agewallet_force_exclude">
                 <input type="checkbox" id="agewallet_force_exclude" name="agewallet_force_exclude" value="1"
                        <?php checked( $force_exclude, '1' ); ?> />
                 <?php esc_html_e( 'Exclude from age verification', 'agewallet' ); ?>
             </label><br>
             <small><?php esc_html_e( "(Overrides ALL Global settings)", 'agewallet' ); ?></small>
         </p>
         <p><small><?php esc_html_e( 'Note: If "Exclude" is checked, "Require" will be ignored.', 'agewallet' ); ?></small></p>
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
         // Check if nonce is set and valid
         if ( ! isset( $_POST['agewallet_restriction_nonce'] ) || ! wp_verify_nonce( sanitize_key($_POST['agewallet_restriction_nonce']), 'agewallet_save_restriction_meta_action' ) ) {
             $this->log_debug('Meta save aborted: Invalid nonce.', ['post_id' => $post_id]);
             return;
         }
         // Check if this is an autosave
         if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
             $this->log_debug('Meta save skipped: Is autosave.', ['post_id' => $post_id]);
             return;
         }
         // Check if it's a revision - prevent saving meta for revisions
         if ( wp_is_post_revision( $post_id ) ) {
              $this->log_debug('Meta save skipped: Is revision.', ['post_id' => $post_id]);
              return;
         }
         // Check user permissions based on post type
         $post_type = get_post_type($post_id);
         // Prevent saving for non-public post types if meta box wasn't added there (safety check)
         $public_post_types = apply_filters('agewallet_meta_box_post_types', get_post_types( array( 'public' => true ), 'names' ));
         if ( ! in_array($post_type, $public_post_types, true) ) {
             $this->log_debug('Meta save skipped: Post type not applicable.', ['post_id' => $post_id, 'post_type' => $post_type]);
             return;
         }
         $post_type_object = get_post_type_object($post_type);
         if ( !$post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
             $this->log_debug('Meta save aborted: User lacks permission.', ['post_id' => $post_id, 'user_id' => get_current_user_id()]);
             return;
         }

         $this->log_debug('[Gating Manager] Saving restriction meta box data.', ['post_id' => $post_id]);

         $force_exclude_value = filter_input(INPUT_POST, 'agewallet_force_exclude', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
         if ( $force_exclude_value === true ) {
             update_post_meta( $post_id, self::META_KEY_FORCE_EXCLUDE, '1' );
             delete_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT );
             $this->log_debug('Saved meta: force_exclude=1, force_restrict=deleted', ['post_id' => $post_id]);
         } else {
             delete_post_meta( $post_id, self::META_KEY_FORCE_EXCLUDE );
             $force_restrict_value = filter_input(INPUT_POST, 'agewallet_force_restrict', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
             if ( $force_restrict_value === true ) {
                 update_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT, '1' );
                 $this->log_debug('Saved meta: force_exclude=deleted, force_restrict=1', ['post_id' => $post_id]);
             } else {
                 delete_post_meta( $post_id, self::META_KEY_FORCE_RESTRICT );
                 $this->log_debug('Saved meta: force_exclude=deleted, force_restrict=deleted', ['post_id' => $post_id]);
             }
         }
         // HOOK: Allow other plugins to react to the meta box save action.
         // We pass the raw input values as they were submitted.
         do_action('agewallet_meta_box_save', $post_id, $force_exclude_value, $force_restrict_value);
     }

     // --- Utility & Logging ---

     /** Helper method to log debug messages. @since 0.1.0 */
     private function log_debug($message, $context = null) {
         if ( class_exists('AgeWallet_Helpers') && method_exists(AgeWallet_Helpers::instance(), 'log') ) {
              AgeWallet_Helpers::instance()->log('[Gating Mgr] '. $message, $context);
         } else {
             $log_entry = '[AgeWallet Plugin] [Gating Mgr] ' . $message;
             if (!is_null($context)) { $log_entry .= ' Context: ' . print_r($context, true); }
             @error_log(preg_replace('/\s+/', ' ', trim($log_entry)));
         }
     }

     // --- Singleton Pattern Boilerplate ---
     /** Cloning forbidden. @since 0.1.0 */
     public function __clone() { _doing_it_wrong(__FUNCTION__, esc_html__('Cloning forbidden.', 'agewallet'), '0.1.0'); }
     /** Unserializing forbidden. @since 0.1.0 */
     public function __wakeup() { _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing forbidden.', 'agewallet'), '0.1.0'); }

 } // End class AgeWallet_Gating_Manager