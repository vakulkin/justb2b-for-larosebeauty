<?php

namespace JustB2B;

/**
 * Cart Handler Class
 */
class Cart_Handler {
	private static $instance = null;

	const COD_FEE = 21;



	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'override_cart_price' ], 200 );
		add_filter( 'woocommerce_package_rates', [ $this, 'apply_free_shipping_for_b2b' ], 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_payment_gateways' ] );
		add_filter( 'woocommerce_cart_needs_shipping', [ $this, 'disable_shipping_calculation' ] );
		add_action( 'woocommerce_checkout_update_order_review', [ $this, 'reset_shipping_cache' ] );
		add_filter( 'option_xts-woodmart-options', [ $this, 'override_woodmart_options_for_b2b' ], 10, 1 );
		add_filter( 'woocommerce_coupons_enabled', [ $this, 'disable_coupons_for_b2b' ] );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'add_sample_product_for_b2b' ], 300 );
		add_filter( 'woocommerce_cart_item_remove_link', [ $this, 'prevent_sample_removal' ], 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', [ $this, 'prevent_sample_quantity_change' ], 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'block_manual_sample_addition' ], 10, 2 );
		add_filter( 'woocommerce_cart_item_name', [ $this, 'modify_sample_cart_item_name' ], 10, 3 );
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_cod_fee' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
	}

	/**
	 * Add 21 PLN fee when Cash on Delivery payment method is selected
	 */
	public function add_cod_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// $_POST['payment_method'] is the most reliable source during checkout AJAX updates
		if ( isset( $_POST['payment_method'] ) ) {
			$chosen_payment = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
		} else {
			$chosen_payment = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
		}

		if ( $chosen_payment === 'cod' ) {
			$cart->add_fee( __( 'Opłata za płatność przy odbiorze', 'justb2b-larose' ), self::COD_FEE, true );
		}
	}

	/**
	 * Enqueue JS to trigger checkout update when payment method changes
	 */
	public function enqueue_checkout_assets() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_add_inline_script(
			'wc-checkout',
			'jQuery( function( $ ) {
				$( document.body ).on( "change", "input[name=payment_method]", function() {
					$( document.body ).trigger( "update_checkout" );
				} );
			} );'
		);
	}

	/**
	 * Override cart item price for B2B users
	 */
	public function override_cart_price( $cart ) {
		// Skip if not B2B user
		if ( ! Helper::is_b2b_accepted_user() ) {
			return;
		}

		// Avoid infinite loops
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		// Loop through cart items
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];

			// Only process simple products
			if ( $product->get_type() !== 'simple' ) {
				continue;
			}

			$product_id = $product->get_id();
			$b2b_price = Helper::get_product_b2b_price( $product_id );

			// If B2B price exists, apply it
			if ( $b2b_price ) {
				// Get tax rate for the product
				$tax_rate = Helper::get_product_tax_rate( $product );

				// Calculate brutto price (with tax)
				$b2b_brutto = Helper::calculate_brutto( $b2b_price, $tax_rate );

				// Set the new price (brutto/gross price)
				$product->set_price( $b2b_brutto );
			}
		}
	}

	/**
	 * Apply free shipping for B2B users when order total is over 1000 (including taxes)
	 * Only applies to InPost shipping methods
	 */
	public function apply_free_shipping_for_b2b( $rates, $package ) {
		// Calculate cart subtotal including taxes
		$cart_subtotal = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
		// Round to 2 decimal places to avoid floating-point precision issues
		$cart_subtotal = round( $cart_subtotal, 2 );

		$free_shipping_amount = Helper::is_b2b_accepted_user() ? 1000 : 600;

		if ( $cart_subtotal >= $free_shipping_amount ) {
			foreach ( $rates as $rate_key => $rate ) {
				// if ( strpos( $rate_key, 'free_shipping' ) !== false ) {
				// 	unset( $rates[ $rate_key ] );
				// 	continue;
				// }

				// Check if rate label/title contains "inpost" (case-insensitive)
				$rate_label = isset( $rate->label ) ? $rate->label : '';
				if ( stripos( $rate_label, 'inpost' ) !== false ) {
					$rates[ $rate_key ]->cost = 0;
					// $rates[ $rate_key ]->taxes = array();
				}
			}
		}

		return $rates;
	}

	public function disable_shipping_calculation( $needs_shipping ) {
		if ( is_cart() ) {
			return false;
		}
		return $needs_shipping;
	}

	public function reset_shipping_cache() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$packages = WC()->cart->get_shipping_packages();
		foreach ( $packages as $key => $value ) {
			$sessionKey = "shipping_for_package_$key";
			WC()->session->set( $sessionKey, null );
		}
	}

	/**
	 * Override WoodMart options for B2B users
	 */
	public function override_woodmart_options_for_b2b( $options ) {
		// Only override for B2B accepted users

		if ( ! Helper::is_b2b_accepted_user() ) {
			$options['shipping_progress_bar_calculation'] = 'custom';
			$options['shipping_progress_bar_amount'] = 600;
			return $options;
		}

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		// Override shipping progress bar amount to 1000 for B2B
		$options['shipping_progress_bar_calculation'] = 'custom';
		$options['shipping_progress_bar_amount'] = 1000;

		return $options;
	}

	/**
	 * Filter payment gateways - remove BACS for non-B2B users and modify title for B2B users
	 */
	public function filter_payment_gateways( $available_gateways ) {
		if ( isset( $available_gateways['bacs'] ) ) {
			if ( Helper::is_b2b_accepted_user() ) {
				// Modify BACS title for B2B users
				$available_gateways['bacs']->title = 'Przelew bankowy z terminem 14 dni';
			} else {
				// Remove BACS for non-B2B users
				unset( $available_gateways['bacs'] );
			}
		}

		if ( isset( $available_gateways['cod'] ) ) {
			$available_gateways['cod']->title = sprintf(
				/* translators: %s: COD fee amount */
				__( 'Płatność przy odbiorze (+%s zł)', 'justb2b-larose' ),
				number_format( self::COD_FEE, 0, ',', '' )
			);
		}

		return $available_gateways;
	}

	/**
	 * Disable coupons for B2B users
	 */
	public function disable_coupons_for_b2b( $enabled ) {
		if ( Helper::is_b2b_accepted_user() ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Get or create the single sample product "Mix próbek"
	 */
	private function get_or_create_sample_product() {
		$product_name = 'Mix próbek';

		// Check if product already exists by meta flag
		$existing_products = get_posts( [
			'post_type' => 'product',
			'meta_key' => '_is_b2b_sample_product',
			'meta_value' => 'yes',
			'post_status' => 'any',
			'numberposts' => 1,
			'fields' => 'ids',
		] );

		if ( ! empty( $existing_products ) ) {
			return $existing_products[0];
		}

		// Create new product
		$product = new \WC_Product_Simple();
		$product->set_name( $product_name );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( 0.01 );
		$product->set_virtual( true );
		$product->set_sold_individually( false );
		$product_id = $product->save();

		// Add meta to identify this as the sample product
		update_post_meta( $product_id, '_is_b2b_sample_product', 'yes' );

		return $product_id;
	}

	/**
	 * Add sample product to cart for B2B users based on order total (NETTO)
	 */
	public function add_sample_product_for_b2b( $cart ) {
		// Skip if not B2B user
		if ( ! Helper::is_b2b_accepted_user() ) {
			return;
		}

		// Avoid infinite loops
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 3 ) {
			return;
		}

		$sample_product_id = $this->get_or_create_sample_product();

		// Calculate cart subtotal NETTO (excluding sample product)
		$cart_subtotal_netto = 0;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( $cart_item['product_id'] != $sample_product_id ) {
				$cart_subtotal_netto += $cart_item['line_subtotal']; // NETTO only
			}
		}
		$cart_subtotal_netto = round( $cart_subtotal_netto, 2 );

		// Determine tier based on cart total NETTO
		$sample_info = null;

		if ( $cart_subtotal_netto >= 5000 ) {
			$sample_info = [ 'tier' => '5000 zł netto', 'count' => 20 ];
		} elseif ( $cart_subtotal_netto >= 3000 ) {
			$sample_info = [ 'tier' => '3000 zł netto', 'count' => 15 ];
		} elseif ( $cart_subtotal_netto >= 2000 ) {
			$sample_info = [ 'tier' => '2000 zł netto', 'count' => 10 ];
		} elseif ( $cart_subtotal_netto >= 1000 ) {
			$sample_info = [ 'tier' => '1000 zł netto', 'count' => 5 ];
		}

		// Find existing sample in cart
		$sample_cart_key = null;
		foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
			if ( $cart_item['product_id'] == $sample_product_id ) {
				$sample_cart_key = $cart_key;
				break;
			}
		}

		if ( $sample_info === null ) {
			// No tier reached — remove sample if present
			if ( $sample_cart_key !== null ) {
				$cart->remove_cart_item( $sample_cart_key );
			}
			return;
		}

		if ( $sample_cart_key !== null ) {
			// Sample already in cart — update tier data and ensure quantity is 1
			$cart->cart_contents[ $sample_cart_key ]['_b2b_sample_tier'] = $sample_info['tier'];
			$cart->cart_contents[ $sample_cart_key ]['_b2b_sample_count'] = $sample_info['count'];
			if ( $cart->cart_contents[ $sample_cart_key ]['quantity'] != 1 ) {
				$cart->set_quantity( $sample_cart_key, 1, true );
			}
		} else {
			// Add sample product to cart with tier data
			$cart_item_data = [
				'_b2b_sample_tier' => $sample_info['tier'],
				'_b2b_sample_count' => $sample_info['count'],
			];
			$cart_item_key = $cart->generate_cart_id( $sample_product_id, 0, [], $cart_item_data );

			if ( ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
				$product_data = wc_get_product( $sample_product_id );
				if ( $product_data ) {
					$cart->cart_contents[ $cart_item_key ] = array(
						'key' => $cart_item_key,
						'product_id' => $sample_product_id,
						'variation_id' => 0,
						'variation' => array(),
						'quantity' => 1,
						'data' => $product_data,
						'data_hash' => wc_get_cart_item_data_hash( $product_data ),
						'_b2b_sample_tier' => $sample_info['tier'],
						'_b2b_sample_count' => $sample_info['count'],
					);
				}
			}
		}
	}

	/**
	 * Prevent users from manually removing sample products
	 */
	public function prevent_sample_removal( $link, $cart_item_key ) {
		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		if ( ! $cart_item ) {
			return $link;
		}

		$product_id = $cart_item['product_id'];
		$is_sample = get_post_meta( $product_id, '_is_b2b_sample_product', true );

		if ( $is_sample === 'yes' && Helper::is_b2b_accepted_user() ) {
			// Return empty string to hide remove link
			return '';
		}

		return $link;
	}

	/**
	 * Prevent users from changing quantity of sample products
	 */
	public function prevent_sample_quantity_change( $product_quantity, $cart_item_key, $cart_item ) {
		$product_id = $cart_item['product_id'];
		$is_sample = get_post_meta( $product_id, '_is_b2b_sample_product', true );

		if ( $is_sample === 'yes' && Helper::is_b2b_accepted_user() ) {
			// Return non-editable quantity display
			return '<span class="quantity">1</span>';
		}

		return $product_quantity;
	}

	/**
	 * Block manual addition of the sample product to cart
	 */
	public function block_manual_sample_addition( $passed, $product_id ) {
		$is_sample = get_post_meta( $product_id, '_is_b2b_sample_product', true );

		if ( $is_sample === 'yes' ) {
			wc_add_notice( 'Ten produkt nie może być dodany ręcznie do koszyka.', 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Modify sample product name in cart to show tier info
	 */
	public function modify_sample_cart_item_name( $name, $cart_item, $cart_item_key ) {
		$product_id = $cart_item['product_id'];
		$is_sample = get_post_meta( $product_id, '_is_b2b_sample_product', true );

		if ( $is_sample === 'yes' && ! empty( $cart_item['_b2b_sample_count'] ) && ! empty( $cart_item['_b2b_sample_tier'] ) ) {
			$count = $cart_item['_b2b_sample_count'];
			$tier = $cart_item['_b2b_sample_tier'];
			return "Mix próbek, {$count} próbek - przy zamówieniu {$tier}";
		}

		return $name;
	}
}
