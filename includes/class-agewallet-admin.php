<?php
/**
 * AgeWallet Admin Class
 *
 * Handles the admin settings page for the AgeWallet OIDC Client plugin.
 * Uses the WordPress Settings API.
 * Implements the Singleton pattern.
 *
 * @package AgeWalletOIDCClient
 * @since   0.1.0
 */

// Prevent direct script access.
defined('ABSPATH') || exit;

class AgeWallet_Admin {

    /**
     * The single instance of the class.
     * @since 0.1.0
     * @var   AgeWallet_Admin|null
     */
    private static $instance = null;

    /**
     * The option group name used for the Settings API.
     * @since 0.1.0
     * @var string
     */
    private $option_group = 'agewallet_oidc';

    /**
     * The slug for the main settings page.
     * @since 0.1.0
     * @var string
     */
    private $page_slug = 'agewallet-settings'; // Changed for clarity

    /**
     * Ensures only one instance of the admin class is loaded.
     * @since  0.1.0
     * @static
     * @return AgeWallet_Admin - Main instance.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor. Hooks into WordPress admin actions.
     * @since 0.1.0
     */
    private function __construct() {
        // Hook to add the menu item.
        add_action('admin_menu', array( $this, 'add_admin_menu' ));
        // Hook to register settings.
        add_action('admin_init', array( $this, 'register_settings' ));
        // Hook to enqueue admin scripts/styles.
        add_action('admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ));
    }

    /**
    * Adds the plugin's menu and sub-menu pages to the WordPress admin.
    * @since 0.1.0
    */
    public function add_admin_menu() {
        $capability = apply_filters('agewallet_admin_menu_capability', 'manage_options');

        // Add a top-level menu.
        add_menu_page(
            __('AgeWallet', 'agewallet'),
            __('AgeWallet', 'agewallet'),
            $capability,
            $this->page_slug,
            array( $this, 'render_settings_page' ), // Main menu item points to Settings
            'dashicons-shield-alt' // Icon
        );

        // Add the "Settings" sub-menu page (this is the main page)
        add_submenu_page(
            $this->page_slug,
            __('AgeWallet Settings', 'agewallet'),
            __('Settings', 'agewallet'),
            $capability,
            $this->page_slug, // This makes it the default page for the parent menu
            array( $this, 'render_settings_page' )
        );

        // --- Add Usage Guide Sub-menu ---
        add_submenu_page(
            $this->page_slug,
            __('Usage Guide', 'agewallet'),
            __('Usage Guide', 'agewallet'),
            $capability,
            'agewallet-usage',
            array( $this, 'render_usage_page' )
        );

        // --- Add CSS Guide Sub-menu ---
        add_submenu_page(
            $this->page_slug,
            __('CSS Guide', 'agewallet'),
            __('CSS Guide', 'agewallet'),
            $capability,
            'agewallet-css-guide',
            array( $this, 'render_style_guide_page' )
        );

        // --- Add Hooks Guide Sub-menu ---
        add_submenu_page(
            $this->page_slug,
            __('Developer Hooks', 'agewallet'),
            __('Developer Hooks', 'agewallet'),
            $capability,
            'agewallet-hooks',
            array( $this, 'render_hooks_page' )
        );
    }

    /**
     * Registers settings sections and fields using the Settings API.
     * @since 0.1.0
     */
    public function register_settings() {
        // --- Register Settings ---
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_CLIENT_ID, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ));
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ));
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_LOGO_ID, array( 'sanitize_callback' => array($this, 'sanitize_positive_int'), 'default' => 0 ));
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, array( 'sanitize_callback' => array($this, 'sanitize_positive_int'), 'default' => 0 ));
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, array( 'sanitize_callback' => array($this, 'sanitize_wysiwyg'), 'default' => $this->get_default_copy() ));
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_HIDE_HEADING, array( 'sanitize_callback' => array($this, 'sanitize_checkbox'), 'default' => 0 ));
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_BLOCK_MODE, array( 'sanitize_callback' => array($this, 'sanitize_block_mode'), 'default' => 'none' ));
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, array( 'sanitize_callback' => array($this, 'sanitize_paths_textarea'), 'default' => '' ));
        register_setting($this->option_group, AgeWalletOIDCClientPro::OPT_DEBUG_MODE, array( 'sanitize_callback' => array($this, 'sanitize_checkbox'), 'default' => 0 ));

        // --- Add Settings Sections ---
        do_action('agewallet_register_settings', $this->page_slug, $this->option_group);

        add_settings_section( 'agewallet_oidc_section_credentials', __('API Credentials', 'agewallet'), '__return_false', $this->page_slug );
        add_settings_section( 'agewallet_oidc_section_appearance', __('Gate Appearance', 'agewallet'), '__return_false', $this->page_slug );
        add_settings_section( 'agewallet_oidc_section_guarding', __('Content Guarding Rules', 'agewallet'), array($this, 'render_guarding_section_description'), $this->page_slug );
        add_settings_section( 'agewallet_oidc_section_debugging', __('Developer Debugging', 'agewallet'), array($this, 'render_debugging_section_description'), $this->page_slug );

        // --- Add Settings Fields ---
        add_settings_field( AgeWalletOIDCClientPro::OPT_CLIENT_ID, __('Client ID', 'agewallet'), array($this, 'render_text_input'), $this->page_slug, 'agewallet_oidc_section_credentials', array( 'label_for' => AgeWalletOIDCClientPro::OPT_CLIENT_ID, 'class' => 'regular-text' ) );
        add_settings_field( AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, __('Client Secret', 'agewallet'), array($this, 'render_text_input'), $this->page_slug, 'agewallet_oidc_section_credentials', array( 'label_for' => AgeWalletOIDCClientPro::OPT_CLIENT_SECRET, 'class' => 'regular-text', 'type' => 'password' ) );
        add_settings_field( 'oidc_redirect_uri', __('Redirect URI', 'agewallet'), array($this, 'render_redirect_uri'), $this->page_slug, 'agewallet_oidc_section_credentials' );
        add_settings_field( AgeWalletOIDCClientPro::OPT_LOGO_ID, __('Gate Logo', 'agewallet'), array($this, 'render_media_uploader'), $this->page_slug, 'agewallet_oidc_section_appearance', array( 'option_name' => AgeWalletOIDCClientPro::OPT_LOGO_ID ) );
        add_settings_field( AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, __('Logo Width (px)', 'agewallet'), array($this, 'render_number_input'), $this->page_slug, 'agewallet_oidc_section_appearance', array( 'label_for' => AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX, 'class' => 'small-text', 'min' => 0, 'step' => 1, 'desc' => __('Leave 0 for natural width.', 'agewallet') ) );
        add_settings_field( AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG, __('Gate Copy', 'agewallet'), array($this, 'render_wysiwyg_editor'), $this->page_slug, 'agewallet_oidc_section_appearance', array( 'option_name' => AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG ) );
        add_settings_field( AgeWalletOIDCClientPro::OPT_HIDE_HEADING, __('Hide Default Heading', 'agewallet'), array($this, 'render_checkbox'), $this->page_slug, 'agewallet_oidc_section_appearance', array( 'label_for' => AgeWalletOIDCClientPro::OPT_HIDE_HEADING, 'label' => __('Hide the "You Must Verify Your Age" heading.', 'agewallet') ) );
        add_settings_field( AgeWalletOIDCClientPro::OPT_BLOCK_MODE, __('Protection Mode', 'agewallet'), array($this, 'render_radio_buttons'), $this->page_slug, 'agewallet_oidc_section_guarding', array( 'option_name' => AgeWalletOIDCClientPro::OPT_BLOCK_MODE, 'options' => array( 'none' => __('No automatic protection.', 'agewallet'), 'all_but_home' => __('Protect entire site, except homepage.', 'agewallet'), 'all' => __('Protect entire site, including homepage.', 'agewallet'), 'specific' => __('Protect only specific URL paths.', 'agewallet'), ) ) );
        add_settings_field( AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, __('Paths to Protect', 'agewallet'), array($this, 'render_textarea'), $this->page_slug, 'agewallet_oidc_section_guarding', array( 'label_for' => AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS, 'class' => 'large-text', 'rows' => 5, 'placeholder' => '/shop/, /articles/premium-content/', 'desc' => __('Only used if Mode is "specific". Enter comma-separated paths.', 'agewallet') ) );
        add_settings_field( AgeWalletOIDCClientPro::OPT_DEBUG_MODE, __('Enable Logging', 'agewallet'), array($this, 'render_checkbox'), $this->page_slug, 'agewallet_oidc_section_debugging', array( 'label_for' => AgeWalletOIDCClientPro::OPT_DEBUG_MODE, 'label' => __('Enable plugin debug logging', 'agewallet'), 'desc' => sprintf( wp_kses( __('This will write activity to <code>%s</code>. Do not leave this enabled on a live site.', 'agewallet'), array('code' => array()) ), 'wp-content/debug.log' ) ) );
    }

    /**
     * Renders the description paragraph for the Guarding section.
     * @since 0.1.0
     */
    public function render_guarding_section_description() {
        echo '<p>' . esc_html__('Choose how site content should be protected. Users with "edit_posts" capability (e.g., Administrators, Editors) bypass the gate.', 'agewallet') . '</p>';
    }

    /**
     * Renders the description for the Debugging section.
     * @since 0.1.0
     */
    public function render_debugging_section_description() {
        $style = 'border: 1px solid #c3c4c7; background: #f6f7f7; padding: 10px 15px; border-left-width: 4px; border-left-color: #d63638;';
        echo '<div style="' . esc_attr($style) . '">';
        echo '<h4 style="margin-top:0;"><span class="dashicons dashicons-warning" style="color:#d63638; vertical-align: middle; margin-right: 5px;"></span>' . esc_html__('Developer Options', 'agewallet') . '</h4>';
        echo '<p style="margin-bottom:0;">' . sprintf( wp_kses( __('<strong>Warning:</strong> For debugging only. This will write detailed plugin activity to <code>%s</code>.', 'agewallet'), array('code' => array(), 'strong' => array()) ), 'wp-content/debug.log' ) . '</p>';
        echo '</div>';
    }

    /**
     * Renders the main settings page wrapper HTML and form.
     * @since 0.1.0
     */
    public function render_settings_page() {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'agewallet'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php" id="agewallet-settings-form">
                <?php
                settings_fields($this->option_group);
                do_action('agewallet_before_settings_fields');
                do_settings_sections($this->page_slug);
                do_action('agewallet_after_settings_fields');
                submit_button(__('Save Changes', 'agewallet'));
                ?>
            </form>
        </div>
        <?php
    }


    // --- README PARSER CALLBACKS ---

    /**
     * Renders the "Usage Guide" page.
     * @since 0.1.8
     */
    public function render_usage_page() {
        $this->render_readme_section_page('Usage Guide', __('Usage Guide', 'agewallet'));
    }

    /**
     * Renders the "Developer Hooks" page.
     * @since 0.1.8
     */
    public function render_hooks_page() {
        $this->render_readme_section_page('Developer Hooks', __('Developer Hooks Guide', 'agewallet'));
    }

    /**
     * Renders the "CSS Guide" page.
     * @since 0.1.8
     */
    public function render_style_guide_page() {
        $this->render_readme_section_page('CSS Customization Guide', __('CSS Customization Guide', 'agewallet'));
    }

    /**
     * Generic renderer for displaying a section of the readme.txt file.
     * @since 0.1.8
     * @param string $section_title The exact title of the section (e.g., "Developer Hooks").
     * @param string $page_title    The title to display at the top of the admin page.
     */
    private function render_readme_section_page($section_title, $page_title = '') {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'agewallet'));
        }

        if ( empty($page_title) ) {
            $page_title = $section_title;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($page_title); ?></h1>
            <div class="postbox">
                <div class="inside" style="padding: 12px 20px; font-size: 1.1em; max-width: 900px;">
                    <?php
                    $content = $this->get_readme_section_content($section_title);
                    if ( ! empty($content) ) {
                        $html_content = $this->parse_readme_markdown($content);
                        echo wp_kses_post($html_content);
                    } else {
                        echo '<div class="notice notice-error"><p>';
                        printf( esc_html__( 'Error: Could not find the "== %s ==" section in the readme.txt file.', 'agewallet' ), esc_html($section_title) );
                        echo '</p></div>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Extracts the content of a specific section from the readme.txt file.
     * @since 0.1.8
     * @param string $section_title The exact title of the section (e.g., "Developer Hooks").
     * @return string The raw content of that section.
     */
    private function get_readme_section_content($section_title) {
        $readme_path = AGEWALLET_PLUGIN_DIR . 'readme.txt';
        if ( ! file_exists($readme_path) ) {
            return '';
        }

        $readme_content = file_get_contents($readme_path);
        // *** FIX 1: NORMALIZE LINE ENDINGS ***
        $readme_content = str_replace(["\r\n", "\r"], "\n", $readme_content);

        if ( empty($readme_content) ) {
            return '';
        }

        // Regex to find the section and capture everything until the next section
        $pattern = '/^==\s*' . preg_quote($section_title, '/') . '\s*==\s*(.*?)(?=\n==\s*|\z)/sm';
        if ( preg_match($pattern, $readme_content, $matches) ) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * A simple parser to convert WordPress readme.txt format to HTML.
     * @since 0.1.8
     * @param string $content The raw content of a readme.txt section.
     * @return string The content converted to HTML.
     */
    private function parse_readme_markdown($content) {
        // Convert sub-headers (e.g., = Section =)
        $content = preg_replace('/^=\s*(.*?)\s*=/m', '<h3>$1</h3>', $content);

        // Convert blockquotes
        $content = preg_replace('/^>(.*)/m', '<blockquote>$1</blockquote>', $content);
        $content = str_replace("</blockquote>\n<blockquote>", "\n", $content); // Merge consecutive

        // Convert unordered lists (lines starting with *)
        $content = preg_replace_callback('/(^\*\s*.*(?:\n^\*\s*.*)*)/m', function($matches) {
            $items = preg_replace('/^\*\s*(.*)/m', '<li>$1</li>', $matches[0]);
            return "<ul>\n" . $items . "\n</ul>\n";
        }, $content);

        // Convert ordered lists (e.g., 1. Item)
        $content = preg_replace_callback('/(^(\d+\.)\s*.*(?:\n^\d+\.\s*.*)*)/m', function($matches) {
            $items = preg_replace('/^(\d+\.)\s*(.*)/m', '<li>$2</li>', $matches[0]);
            return "<ol>\n" . $items . "\n</ol>\n";
        }, $content);

        // Convert inline markdown
        $content = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $content); // Links
        $content = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $content); // Bold
        $content = preg_replace('/\*([^\*]+)\*/s', '<em>$1</em>', $content); // Italic
        $content = preg_replace('/`(.*?)`/', '<code>$1</code>', $content); // Inline Code

        // Convert code blocks (readme.txt uses backticks or spaces)
        $content = preg_replace('/`\n(.*?)\n`/s', '<pre><code>$1</code></pre>', $content);

        // Convert paragraphs (wrap blocks of text in <p> tags)
        $blocks = explode("\n\n", $content);
        $html_blocks = array();
        foreach ( $blocks as $block ) {
            $block = trim($block);
            if ( empty($block) ) continue;

            // Don't wrap tags that are already block-level
            if ( ! preg_match('/^<(h3|ul|ol|blockquote|pre)/', $block) ) {
                $html_blocks[] = '<p>' . nl2br($block) . '</p>';
            } else {
                $html_blocks[] = $block;
            }
        }
        $content = implode("\n\n", $html_blocks);

        return $content;
    }


    // --- Field Rendering Callbacks ---

    /**
     * Renders a standard text or password input field.
     * @since 0.1.0
     */
    public function render_text_input($args) {
        $option_name = $args['label_for'];
        $value       = get_option($option_name, '');
        $type        = isset($args['type']) ? $args['type'] : 'text';
        $class       = isset($args['class']) ? $args['class'] : 'regular-text';
        printf( '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="%4$s" />', esc_attr($type), esc_attr($option_name), esc_attr($value), esc_attr($class) );
        if (isset($args['desc'])) { printf('<p class="description">%s</p>', esc_html($args['desc'])); }
    }

    /**
     * Renders the static display of the OIDC Redirect URI.
     * @since 0.1.0
     */
    public function render_redirect_uri() {
        $redirect_uri = AgeWallet_Helpers::instance()->get_oidc_redirect_uri();
        printf('<code>%s</code>', esc_html($redirect_uri));
        echo '<p class="description">' . wp_kses( __('Add this exact Redirect URI to your AgeWallet application configuration. It must match <strong>exactly</strong>.', 'agewallet'), array('strong' => array()) ) . '</p>';
    }

    /**
     * Renders the media uploader button, preview, and hidden input for the logo ID.
     * @since 0.1.0
     */
    public function render_media_uploader($args) {
        $option_name = $args['option_name'];
        $logo_id     = get_option($option_name, 0);
        $logo_src    = $logo_id ? wp_get_attachment_image_url( (int) $logo_id, 'medium' ) : '';
        ?>
        <div style="margin-bottom: 8px;">
            <img id="aw-logo-preview" src="<?php echo esc_url($logo_src ?: ''); ?>" alt="<?php esc_attr_e('Logo Preview', 'agewallet'); ?>" style="max-height: 60px; height: auto; <?php echo $logo_src ? '' : 'display:none;'; ?> border: 1px solid #ddd; padding: 2px;">
        </div>
        <input type="hidden" id="aw-logo-id-input" name="<?php echo esc_attr($option_name); ?>" value="<?php echo esc_attr($logo_id); ?>">
        <button type="button" class="button" id="aw-logo-select-button"><?php esc_html_e('Select Image', 'agewallet'); ?></button>
        <button type="button" class="button" id="aw-logo-remove-button" <?php echo $logo_id ? '' : 'style="display:none"'; ?>><?php esc_html_e('Remove Image', 'agewallet'); ?></button>
        <p class="description"><?php esc_html_e('Optional. Choose a logo from the media library to display above the age verification gate.', 'agewallet'); ?></p>
        <?php
    }

    /**
     * Renders a number input field.
     * @since 0.1.0
     */
    public function render_number_input($args) {
        $option_name = $args['label_for'];
        $value       = get_option($option_name, 0);
        $class       = isset($args['class']) ? $args['class'] : 'small-text';
        $min         = isset($args['min']) ? $args['min'] : 0;
        $step        = isset($args['step']) ? $args['step'] : 1;
        printf( '<input type="number" id="%1$s" name="%1$s" value="%2$s" class="%3$s" min="%4$s" step="%5$s" /> px', esc_attr($option_name), esc_attr($value), esc_attr($class), esc_attr($min), esc_attr($step) );
        if (isset($args['desc'])) { printf('<p class="description">%s</p>', esc_html($args['desc'])); }
    }

    /**
     * Renders the WP WYSIWYG editor for the gate copy.
     * @since 0.1.0
     */
    public function render_wysiwyg_editor($args) {
        $option_name = $args['option_name'];
        $content     = get_option($option_name, $this->get_default_copy());
        $editor_id   = 'agewallet_wysiwyg_copy_editor';
        wp_editor( $content, $editor_id, array( 'textarea_name' => $option_name, 'textarea_rows' => 6, 'media_buttons' => false, 'tinymce' => true, 'quicktags' => true, ) );
        echo '<p class="description">' . sprintf( wp_kses( __('Customize the text shown on the age verification gate. HTML is allowed. Default: "%s"', 'agewallet'), array() ), esc_html($this->get_default_copy(false)) ) . '</p>';
    }

    /**
     * Renders a checkbox input field with a label.
     * @since 0.1.0
     */
    public function render_checkbox($args) {
        $option_name = $args['label_for'];
        $checked     = get_option($option_name, 0);
        $label_text  = isset($args['label']) ? $args['label'] : '';
        echo '<label for="' . esc_attr($option_name) . '">';
        printf( '<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s />', esc_attr($option_name), checked(1, $checked, false) );
        if ($label_text) { echo ' ' . esc_html($label_text); }
        echo '</label>';
        if (isset($args['desc'])) { printf( '<p class="description">%s</p>', wp_kses( $args['desc'], array( 'code' => array() ) ) ); }
    }

    /**
     * Renders radio button options for a setting.
     * @since 0.1.0
     */
    public function render_radio_buttons($args) {
        $option_name = $args['option_name'];
        $options     = isset($args['options']) && is_array($args['options']) ? $args['options'] : array();
        $current_val = get_option($option_name, 'none');
        echo '<fieldset><legend class="screen-reader-text"><span>' . esc_html__('Protection Mode Options', 'agewallet') . '</span></legend>';
        foreach ($options as $value => $label) {
            $input_id = $option_name . '_' . $value;
            echo '<div style="margin-bottom: 5px;">';
            printf( '<input type="radio" id="%1$s" name="%2$s" value="%3$s" %4$s />', esc_attr($input_id), esc_attr($option_name), esc_attr($value), checked($value, $current_val, false) );
            echo ' <label for="' . esc_attr($input_id) . '">' . wp_kses_post($label) . '</label>';
            echo '</div>';
        }
        echo '</fieldset>';
        if (isset($args['desc'])) { printf('<p class="description">%s</p>', esc_html($args['desc'])); }
    }

    /**
     * Renders a textarea field. Includes logic to hide/show based on protection mode.
     * @since 0.1.0
     */
    public function render_textarea($args) {
        $option_name = $args['label_for'];
        $value       = get_option($option_name, '');
        $class       = isset($args['class']) ? $args['class'] : 'large-text';
        $rows        = isset($args['rows']) ? absint($args['rows']) : 5;
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        $current_mode = get_option(AgeWalletOIDCClientPro::OPT_BLOCK_MODE, 'none');
        $style_attr   = ($current_mode !== 'specific') ? 'style="display:none;"' : '';
        echo '<div id="agewallet-blocked-paths-wrapper" ' . $style_attr . '>';
        printf( '<textarea id="%1$s" name="%1$s" class="%2$s" rows="%3$d" placeholder="%4$s">%5$s</textarea>', esc_attr($option_name), esc_attr($class), $rows, esc_attr($placeholder), esc_textarea($value) );
        if (isset($args['desc'])) { echo '<p class="description">' . wp_kses($args['desc'], array('code' => array())) . '</p>'; }
        echo '</div>';
    }

    // --- Sanitization Callbacks ---

    /**
     * Sanitizes input intended to be a positive integer (or zero).
     * @since 0.1.0
     */
    public function sanitize_positive_int($input) {
        return absint($input);
    }

    /**
     * Sanitizes input from the WYSIWYG editor using wp_kses_post.
     * @since 0.1.0
     */
    public function sanitize_wysiwyg($input) {
        return wp_kses_post($input);
    }

    /**
     * Sanitizes checkbox input. Returns 1 if checked ('1'), 0 otherwise.
     * @since 0.1.0
     */
    public function sanitize_checkbox($input) {
        return ( isset($input) && $input === '1' ) ? 1 : 0;
    }

    /**
     * Sanitizes the block mode radio button selection.
     * @since 0.1.0
     */
    public function sanitize_block_mode($input) {
        $allowed_modes = array('none', 'all_but_home', 'all', 'specific');
        if ( in_array($input, $allowed_modes, true) ) {
            return $input;
        }
        return 'none';
    }

    /**
     * Sanitizes the comma-separated list of paths from the textarea.
     * @since 0.1.0
     */
    public function sanitize_paths_textarea($input) {
        if ( empty(trim($input)) ) { return ''; }
        $paths = explode(',', $input);
        $clean_paths = array();
        foreach ($paths as $path) {
            $path = trim($path);
            if ( empty($path) ) { continue; }
            if (strpos($path, '/') !== 0) { $path = '/' . $path; }
            $sanitized_path = sanitize_text_field($path);
            if ( ! empty($sanitized_path) && (strlen($sanitized_path) > 1 || $sanitized_path === '/') ) {
                $clean_paths[] = $sanitized_path;
            }
        }
        $unique_paths = array_unique($clean_paths);
        return implode(',', $unique_paths);
    }

    /**
     * Gets the default copy text for the gate. Includes HTML link.
     * @since 0.1.0
     */
    private function get_default_copy($include_html = true) {
        $partner_name = 'AgeWallet™';
        $partner_link = '<a href="https://www.agewallet.com" target="_blank" rel="noopener">' . $partner_name . '</a>';
        /* translators: %s: Partner name (AgeWallet™) or HTML link to partner site */
        $text_format = __( 'You must be 18+ to view this content (or meet the minimum age required by your local jurisdiction). By selecting “I Agree,” you confirm that you meet the minimum age requirement and consent to verification by our partner, %s. If you do not meet the minimum age requirement or do not agree, please select “I Disagree.”', 'agewallet' );
        return sprintf($text_format, $include_html ? $partner_link : $partner_name);
    }

    /**
     * Enqueues scripts and styles needed ONLY for the admin settings page.
     * @since 0.1.0
     */
    public function enqueue_admin_scripts($hook_suffix) {
        // --- Load assets on ALL our plugin pages ---
        $allowed_hooks = array(
            'toplevel_page_' . $this->page_slug, // Main settings page
            $this->page_slug . '_page_agewallet-usage',    // Corrected Usage hook
            $this->page_slug . '_page_agewallet-css-guide',// Corrected CSS Guide hook
            $this->page_slug . '_page_agewallet-hooks',    // Corrected Hooks hook
        );

        if ( ! in_array($hook_suffix, $allowed_hooks) ) {
            return;
        }

        // Only enqueue media uploader scripts on the main settings page
        if ($hook_suffix === 'toplevel_page_' . $this->page_slug) {
            wp_enqueue_media();
            wp_enqueue_script(
                'agewallet-admin-settings',
                AGEWALLET_PLUGIN_URL . 'assets/js/admin-settings.js',
                array('jquery', 'wp-i18n'),
                AGEWALLET_VERSION,
                true
            );

            wp_localize_script(
                'agewallet-admin-settings',
                'agewalletAdminData',
                array(
                    'mediaFrameTitle'       => __('Select or Upload Gate Logo', 'agewallet'),
                    'mediaFrameButton'      => __('Use this image', 'agewallet'),
                    'logoInputId'           => 'aw-logo-id-input',
                    'logoPreviewId'         => 'aw-logo-preview',
                    'selectButtonId'        => 'aw-logo-select-button',
                    'removeButtonId'        => 'aw-logo-remove-button',
                    'blockModeOptionName'   => AgeWalletOIDCClientPro::OPT_BLOCK_MODE,
                    'blockedPathsWrapperId' => 'agewallet-blocked-paths-wrapper',
                )
            );
        }
    }

    /** Cloning forbidden. @since 0.1.0 */
    public function __clone() { _doing_it_wrong(__FUNCTION__, esc_html__('Cloning is forbidden.', 'agewallet'), '0.1.0'); }
    /** Unserializing forbidden. @since 0.1.0 */
    public function __wakeup() { _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing forbidden.', 'agewallet'), '0.1.0'); }

} // End class AgeWallet_Admin