<?php
/**
 * Admin UI for the regulated-status flags that drive the WC checkout-gate
 * conditional-on-cart mode.
 *
 * Adds:
 *  - A "AgeWallet" tab to the WC product data metabox with a 3-state radio
 *    (not regulated / regulated / override).
 *  - A checkbox to the product category + product tag edit screens to mark
 *    whole taxonomy terms as regulated.
 *
 * Reads/writes go through the meta keys defined on AgeWallet_WooCommerce
 * (META_KEY_PRODUCT_REGULATED_STATUS and META_KEY_TERM_REGULATED).
 *
 * No-op on non-WC installs.
 *
 * @package AgeWalletOIDCClient
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AgeWallet_Product_Flags' ) ) {

	final class AgeWallet_Product_Flags {

		private static $instance = null;

		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			// Product data tab + panel + save.
			add_filter( 'woocommerce_product_data_tabs',   array( $this, 'add_product_data_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );

			// Category edit screens.
			add_action( 'product_cat_add_form_fields',  array( $this, 'render_cat_field_add' ) );
			add_action( 'product_cat_edit_form_fields', array( $this, 'render_cat_field_edit' ) );
			add_action( 'edited_product_cat',           array( $this, 'save_term_meta' ) );
			add_action( 'created_product_cat',          array( $this, 'save_term_meta' ) );

			// Tag edit screens.
			add_action( 'product_tag_add_form_fields',  array( $this, 'render_cat_field_add' ) );
			add_action( 'product_tag_edit_form_fields', array( $this, 'render_cat_field_edit' ) );
			add_action( 'edited_product_tag',           array( $this, 'save_term_meta' ) );
			add_action( 'created_product_tag',          array( $this, 'save_term_meta' ) );
		}

		/**
		 * WC product-data-tab filter: append the AgeWallet tab.
		 */
		public function add_product_data_tab( $tabs ) {
			$tabs['agewallet'] = array(
				'label'    => __( 'AgeWallet', 'agewallet' ),
				'target'   => 'agewallet_product_data',
				'class'    => array(),
				'priority' => 80,
			);
			return $tabs;
		}

		/**
		 * WC product-data-panel action: render the regulated-status radio panel.
		 */
		public function render_product_data_panel() {
			global $post;
			$status = $post ? get_post_meta( $post->ID, AgeWallet_WooCommerce::META_KEY_PRODUCT_REGULATED_STATUS, true ) : '';
			if ( '' === $status ) {
				$status = AgeWallet_WooCommerce::PRODUCT_REGULATED_STATUS_DEFAULT;
			}

			$options = array(
				'not_regulated'          => array(
					'label' => __( 'Not regulated', 'agewallet' ),
					'desc'  => __( 'This product does not require age verification at checkout.', 'agewallet' ),
				),
				'regulated'              => array(
					'label' => __( 'Regulated', 'agewallet' ),
					'desc'  => __( 'This product is regulated — when the AgeWallet checkout-gate is set to "Conditional on cart", a cart containing this product will fire the age verification gate.', 'agewallet' ),
				),
				'override_not_regulated' => array(
					'label' => __( 'Override — explicitly not regulated', 'agewallet' ),
					'desc'  => __( 'Forces this product to be treated as unregulated even if one of its categories or tags is flagged as regulated. Only useful for edge cases where an otherwise-regulated category contains a specific product that should not trigger the gate.', 'agewallet' ),
				),
			);
			?>
			<div id="agewallet_product_data" class="panel woocommerce_options_panel hidden">
				<div class="options_group">
					<p class="form-field">
						<label><?php esc_html_e( 'Regulated status', 'agewallet' ); ?></label>
						<span class="description" style="display:block; margin-left:0;">
							<?php esc_html_e( 'Controls whether a cart containing this product triggers the AgeWallet checkout gate when the gate is set to "Conditional on cart" mode.', 'agewallet' ); ?>
						</span>
					</p>
					<?php foreach ( $options as $value => $entry ) : ?>
						<p class="form-field" style="margin-left:160px;">
							<label style="display:block;">
								<input type="radio" name="<?php echo esc_attr( AgeWallet_WooCommerce::META_KEY_PRODUCT_REGULATED_STATUS ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $status ); ?> />
								<strong><?php echo esc_html( $entry['label'] ); ?></strong>
								<br>
								<span class="description" style="margin-left:24px;"><?php echo esc_html( $entry['desc'] ); ?></span>
							</label>
						</p>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * WC product save action: persist the regulated-status meta.
		 * WC has already verified the standard post-edit nonce + capability before this fires.
		 */
		public function save_product_meta( $post_id ) {
			$key = AgeWallet_WooCommerce::META_KEY_PRODUCT_REGULATED_STATUS;
			if ( ! isset( $_POST[ $key ] ) ) {
				return;
			}
			$input   = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			$allowed = array( 'not_regulated', 'regulated', 'override_not_regulated' );
			$value   = in_array( $input, $allowed, true ) ? $input : 'not_regulated';
			update_post_meta( $post_id, $key, $value );
		}

		/**
		 * Category/tag "Add new" form field.
		 */
		public function render_cat_field_add() {
			?>
			<div class="form-field">
				<label for="agewallet_regulated">
					<input type="checkbox" name="<?php echo esc_attr( AgeWallet_WooCommerce::META_KEY_TERM_REGULATED ); ?>" id="agewallet_regulated" value="1" />
					<?php esc_html_e( 'Regulated for AgeWallet checkout gate', 'agewallet' ); ?>
				</label>
				<p><?php esc_html_e( 'When AgeWallet\'s checkout-gate is set to "Conditional on cart", any cart containing a product in this term will trigger age verification.', 'agewallet' ); ?></p>
			</div>
			<?php
		}

		/**
		 * Category/tag edit form field.
		 */
		public function render_cat_field_edit( $term ) {
			$checked = get_term_meta( $term->term_id, AgeWallet_WooCommerce::META_KEY_TERM_REGULATED, true );
			?>
			<tr class="form-field">
				<th scope="row" valign="top">
					<label for="agewallet_regulated">
						<?php esc_html_e( 'Regulated for AgeWallet checkout gate', 'agewallet' ); ?>
					</label>
				</th>
				<td>
					<input type="checkbox" name="<?php echo esc_attr( AgeWallet_WooCommerce::META_KEY_TERM_REGULATED ); ?>" id="agewallet_regulated" value="1" <?php checked( '1', $checked ); ?> />
					<p class="description"><?php esc_html_e( 'When AgeWallet\'s checkout-gate is set to "Conditional on cart", any cart containing a product in this term will trigger age verification.', 'agewallet' ); ?></p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Term save handler. WP has already verified the term-edit nonce before this fires.
		 */
		public function save_term_meta( $term_id ) {
			$key   = AgeWallet_WooCommerce::META_KEY_TERM_REGULATED;
			$value = isset( $_POST[ $key ] ) && '1' === $_POST[ $key ] ? '1' : '';
			if ( '1' === $value ) {
				update_term_meta( $term_id, $key, '1' );
			} else {
				delete_term_meta( $term_id, $key );
			}
		}
	}

	AgeWallet_Product_Flags::instance();
}
