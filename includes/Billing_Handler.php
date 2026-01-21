<?php

namespace JustB2B;

/**
 * Billing Handler Class
 * Handles billing details for B2B users
 */
class Billing_Handler {
	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_before_checkout_billing_form', [ $this, 'render_b2b_pending_notice' ] );
		add_action( 'woocommerce_before_checkout_billing_form', [ $this, 'render_values_for_b2b_users' ] );
		add_action( 'woocommerce_account_content', [ $this, 'render_b2b_pending_notice' ], 5 );
		add_action( 'wcpt_before_loop', [ $this, 'render_b2b_pending_notice' ] );
		add_action( 'woocommerce_before_edit_account_address_form', [ $this, 'start_buffering_form' ] );
		add_action( 'woocommerce_after_edit_account_address_form', [ $this, 'end_buffering_form' ] );
		add_filter( 'woocommerce_billing_fields', [ $this, 'hide_billing_fields_for_b2b_users' ], 10, 2 );
		add_filter( 'woocommerce_checkout_get_value', [ $this, 'load_b2b_values_for_pending_user' ], 10, 2 );
		add_filter( 'woocommerce_checkout_posted_data', [ $this, 'set_values_for_b2b_users' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_b2b_menu_item' ], 40 );
		add_filter( 'woocommerce_get_endpoint_url', [ $this, 'b2b_products_menu_link' ], 10, 4 );

	}

	public function get_field_map() {
		return [
			'billing_first_name' => 'justb2b_firstname',
			'billing_last_name' => 'justb2b_lastname',
			'billing_company' => 'justb2b_company',
			'billing_address_1' => 'justb2b_address_1',
			'billing_address_2' => 'justb2b_address_2',
			'billing_country' => 'justb2b_country',
			'billing_state' => 'justb2b_state',
			'billing_city' => 'justb2b_city',
			'billing_postcode' => 'justb2b_postcode',
			'billing_phone' => 'justb2b_phone',
			'billing_email' => 'email',
			'billing_faktura' => 'justb2b_invoice',
			'billing_nip' => 'justb2b_nip',
		];
	}

	public function render_b2b_pending_notice() {
		if ( Helper::is_b2b_pending_user() ) {
			echo '<div class="justb2b-notice pending">';
			echo '<strong>' . esc_html__( 'Notice:', 'justb2b-larose' ) . '</strong> ';
			echo esc_html__( 'Your business application is under review but meanwhile you can order as regular user.', 'justb2b-larose' );
			echo '</div>';
		}
	}

	public function render_values_for_b2b_users() {
		if ( Helper::is_b2b_accepted_user() || ( Helper::is_b2b_pending_user() && ! is_checkout() ) ) {

			$user_id = get_current_user_id();
			$field_map = $this->get_field_map();

			if ( is_account_page() ) {
				echo '<h2>' . esc_html__( 'Your business details', 'justb2b-larose' ) . '</h2>';
			}

			echo '<div class="justb2b-billing-grid">';
			foreach ( $field_map as $woo_key => $uwp_key ) {
				$uwp_value = uwp_get_usermeta( $user_id, $uwp_key );
				if ( $uwp_value ) {
					$field = uwp_get_custom_field_info( $uwp_key );
					$label = $field ? uwp_get_form_label( $field ) : $woo_key;
					echo '<div class="justb2b-field-item">';
					echo '<div class="justb2b-field-label">' . esc_html( $label ) . '</div>';
					echo '<div class="justb2b-field-value">' . esc_html( $uwp_value ) . '</div>';
					echo '</div>';
				}
			}
			echo '</div>';

			if (Helper::is_b2b_accepted_user() && is_account_page()) {            	
				echo do_action('uwp_account_form_display', 'account');
			}
		}
	}

	/**
	 * Modify address fields for editing
	 */
	public function hide_billing_fields_for_b2b_users( $address, $load_address ) {
		if ( Helper::is_b2b_accepted_user() ) {
			$country_field = $address['billing_country'];
			return [
				'billing_country' => $country_field,
			];
		}
		return $address;
	}

	public function load_b2b_values_for_pending_user( $value, $input ) {
		if ( Helper::is_b2b_pending_user() ) {
			$user_id = get_current_user_id();
			$field_map = $this->get_field_map();

			if ( isset( $field_map[ $input ] ) ) {
				$uwp_key = $field_map[ $input ];
				$uwp_value = uwp_get_usermeta( $user_id, $uwp_key );
				if ( $uwp_value ) {
					return $uwp_value;
				}
			}
		}
		return $value;
	}



	/**
	 * Start output buffering for the edit address form if it's billing
	 */
	public function start_buffering_form() {
		$this->render_values_for_b2b_users();
		if ( Helper::is_b2b_accepted_user() ) {
			ob_start();
		}
	}

	/**
	 * End output buffering, discard the form, and render B2B values instead
	 */
	public function end_buffering_form() {
		if ( Helper::is_b2b_accepted_user() ) {
			ob_end_clean();
		}
	}

	/**
	 * Modify posted data for B2B users to include UsersWP data
	 */
	public function set_values_for_b2b_users( $data ) {
		if ( ! Helper::is_b2b_accepted_user() ) {
			return $data;
		}

		$user_id = get_current_user_id();
		$field_map = $this->get_field_map();

		foreach ( $field_map as $woo_key => $uwp_key ) {
			$value = uwp_get_usermeta( $user_id, $uwp_key );
			if ( $value ) {
				$data[ $woo_key ] = $value;
			}
		}

		$data['billing_faktura'] = '1';
		return $data;
	}

	/**
	 * Add B2B Account tab to WooCommerce My Account menu
	 */
	public function add_b2b_menu_item( $items ) {
		// Only show for B2B users
		if ( ! Helper::is_b2b_accepted_user() ) {
			return $items;
		}

		// Insert B2B Products link after orders
		$new_items = [];
		foreach ( $items as $key => $value ) {
			$new_items[ $key ] = $value;
			// Add B2B Products after orders
			if ( $key === 'orders' ) {
				$new_items['b2b-products'] = __( 'B2B Product list', 'justb2b-larose' );
			}
		}

		// Rename "Addresses" to "Business Account" for B2B users
		if ( isset( $new_items['edit-address'] ) ) {
			$new_items['edit-address'] = __( 'Business account', 'justb2b-larose' );
		}

		return $new_items;
	}

	/**
	 * Set custom URL for B2B Products menu item
	 */
	public function b2b_products_menu_link( $url, $endpoint, $value, $permalink ) {
		if ( $endpoint === 'b2b-products' ) {
			return home_url( '/b2b-products/' );
		}
		return $url;
	}
}
