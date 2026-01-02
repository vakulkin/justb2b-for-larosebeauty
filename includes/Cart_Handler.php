<?php
namespace JustB2B;

/**
 * Cart Handler Class
 */
class Cart_Handler {
	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'override_cart_price' ], 10 );
		add_filter( 'woocommerce_package_rates', [ $this, 'apply_free_shipping_for_b2b' ], 10, 2 );
		add_filter( 'woocommerce_gateway_title', [ $this, 'modify_bacs_title_for_b2b' ], 10, 2 );
		add_filter( 'woocommerce_cart_needs_shipping', [ $this, 'disable_shipping_calculation' ] );
		add_action( 'woocommerce_checkout_update_order_review', [ $this, 'reset_shipping_cache' ] );
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
	 */
	public function apply_free_shipping_for_b2b( $rates, $package ) {
		// Check if user is B2B accepted
		if ( ! Helper::is_b2b_accepted_user() ) {
			return $rates;
		}

		// Calculate cart subtotal including taxes
		$cart_subtotal = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();

		// If subtotal is 1000 or more, make all shipping free
		if ( $cart_subtotal >= 1000 ) {
			foreach ( $rates as $rate_key => $rate ) {
				if ( strpos( $rate_key, 'free_shipping' ) !== false ) {
					unset( $rates[ $rate_key ] );
                    continue;
				}

				$rates[ $rate_key ]->cost = 0;
				$rates[ $rate_key ]->taxes = array();
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
	 * Modify BACS payment method title for B2B users
	 */
	public function modify_bacs_title_for_b2b( $title, $gateway_id ) {
		// Only modify for BACS gateway and B2B accepted users
		if ( $gateway_id === 'bacs' && Helper::is_b2b_accepted_user() ) {
			$title = 'Przelew bankowy z terminem 14 dni';
		}
		return $title;
	}
}
