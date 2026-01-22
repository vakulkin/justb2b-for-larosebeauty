<?php
namespace JustB2B;

/**
 * Price Display Class
 */
class Price_Display {
	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_get_price_html', [ $this, 'hide_price_for_b2b_users' ], 10, 2 );
		add_shortcode( 'justb2b_display_price', [ $this, 'shortcode_b2b_price' ] );
		
		// Display net prices in cart/checkout for B2B users
		add_filter( 'woocommerce_cart_item_price', [ $this, 'display_net_price_in_cart' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'display_net_subtotal_in_cart' ], 10, 3 );
		
		// Display net prices in mini cart for B2B users
		add_filter( 'woocommerce_widget_cart_item_quantity', [ $this, 'display_net_price_in_minicart' ], 10, 3 );	
	}

	/**
	 * Hide price for B2B users
	 */
	public function hide_price_for_b2b_users( $price_html, $product ) {
		if ( is_admin() ) {
			return $price_html;
		}

		// Check if user is B2B
		if ( Helper::is_b2b_accepted_user() ) {
			return $this->display_b2b_price();
		}

		return $price_html;
	}

	/**
	 * Display B2B price information on product page
	 */
	public function display_b2b_price() {
		global $product;

		// Only for simple products
		if ( ! $product || $product->get_type() !== 'simple' ) {
			return;
		}

		// Get B2B price
		$b2b_netto = Helper::get_product_b2b_price( $product->get_id() );

		if ( ! $b2b_netto ) {
			return;
		}

		global $post, $woocommerce_loop;

		$isMainProduct = is_product()
			&& is_singular( 'product' )
			&& isset( $post )
			&& $product->get_id() === $post->ID;

		$isInNamedLoop = isset( $woocommerce_loop['name'] ) && ! empty( $woocommerce_loop['name'] );
		$isShortcode = isset( $woocommerce_loop['is_shortcode'] ) && $woocommerce_loop['is_shortcode'];
		$isInLoop = $isInNamedLoop || $isShortcode || ! $isMainProduct;

		if ( $isInLoop ) {
			echo '<div class="justb2b-price-info-compact">';
			echo '<div class="justb2b-price-line">';
			echo __( 'b2b:', 'justb2b-larose' ) . ' ';
			echo wc_price( $b2b_netto );
			echo '<span>' . __( 'net', 'justb2b-larose' ) . '</span>';
			echo '</div>';
			echo '<div class="justb2b-price-line">';
			echo '<span class="justb2b-rrp">';
			echo __( 'rrp:', 'justb2b-larose' ) . ' ';
			echo wc_price( $product->get_regular_price() ) . ' ';
			echo '<span>' . __( 'gross', 'justb2b-larose' ) . '</span>';
			echo '</span>';
			echo '</div>';
			echo '</div>';
			return;
		}


		$regular_price = $product->get_regular_price();

		// Display B2B prices
		echo '<div class="justb2b-price-info">';
		echo '<div class="b2b-price-row">';
		echo '<strong>' . __( 'B2B Price:', 'justb2b-larose' ) . '</strong> ';
		echo wc_price( $b2b_netto );
		echo ' <small>' . __( 'net', 'justb2b-larose' ) . '</small>';
		echo '</div>';

		echo '<div class="b2b-price-row">';
		echo '<strong>' . __( 'RRP:', 'justb2b-larose' ) . '</strong> ';
		echo wc_price( $regular_price );
		echo ' <small>' . __( 'gross', 'justb2b-larose' ) . '</small>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Shortcode for B2B price display
	 * Usage: [justb2b_price]
	 */
	public function shortcode_b2b_price( $atts ) {
		// Get product from context
		global $product;

		if ( ! $product ) {
			// Try to get from global post
			global $post;
			if ( $post ) {
				$product = wc_get_product( $post->ID );
			}
		}

		if ( ! $product ) {
			return '';
		}

		// Start output buffering
		ob_start();

		$this->display_b2b_price();

		return ob_get_clean();
	}

	/**
	 * Get B2B prices (net and gross) for a product
	 *
	 * @param object $product WooCommerce product
	 * @param int $quantity Product quantity
	 * @return array|false Array with 'net' and 'gross' keys, or false if no B2B price
	 */
	private function get_b2b_prices( $product, $quantity = 1 ) {
		$product_id = $product->get_id();
		$b2b_price = Helper::get_product_b2b_price( $product_id );

		if ( ! $b2b_price ) {
			return false;
		}

		$tax_rate = Helper::get_product_tax_rate( $product );
		$b2b_brutto = Helper::calculate_brutto( $b2b_price, $tax_rate );

		return [
			'net' => $b2b_price * $quantity,
			'gross' => $b2b_brutto * $quantity,
		];
	}

	/**
	 * Format price HTML with net and gross display
	 *
	 * @param float $net_price Net price
	 * @param float $gross_price Gross price
	 * @return string Formatted HTML
	 */
	private function format_dual_price( $net_price, $gross_price ) {
		return wp_strip_all_tags( wc_price( $net_price ) ) . ' <small>' . __( 'net', 'justb2b-larose' ) . '</small><br>' .
		       '<span class="justb2b-gross-price">' . wp_strip_all_tags( wc_price( $gross_price ) ) . ' <small>' . __( 'gross', 'justb2b-larose' ) . '</small></span>';
	}

	/**
	 * Display net and gross price for cart items (B2B users)
	 */
	public function display_net_price_in_cart( $price, $cart_item, $cart_item_key ) {
		if ( ! Helper::is_b2b_accepted_user() ) {
			return $price;
		}

		$prices = $this->get_b2b_prices( $cart_item['data'] );
		
		return $prices ? $this->format_dual_price( $prices['net'], $prices['gross'] ) : $price;
	}

	/**
	 * Display net and gross subtotal for cart items (B2B users)
	 */
	public function display_net_subtotal_in_cart( $subtotal, $cart_item, $cart_item_key ) {
		if ( ! Helper::is_b2b_accepted_user() ) {
			return $subtotal;
		}

		$prices = $this->get_b2b_prices( $cart_item['data'], $cart_item['quantity'] );
		
		return $prices ? $this->format_dual_price( $prices['net'], $prices['gross'] ) : $subtotal;
	}

	/**
	 * Display net and gross price in mini cart for B2B users
	 */
	public function display_net_price_in_minicart( $quantity_html, $cart_item, $cart_item_key ) {
		if ( ! Helper::is_b2b_accepted_user() ) {
			return $quantity_html;
		}

		$prices = $this->get_b2b_prices( $cart_item['data'] );
		
		if ( ! $prices ) {
			return $quantity_html;
		}

		$quantity = $cart_item['quantity'];

		return '<span class="quantity">' . $quantity . ' &times; ' .
		       wp_strip_all_tags( wc_price( $prices['net'] ) ) . ' <small>' . __( 'net', 'justb2b-larose' ) . '</small><br>' .
		       '<span class="justb2b-minicart-gross">' . wp_strip_all_tags( wc_price( $prices['gross'] ) ) . ' <small>' . __( 'gross', 'justb2b-larose' ) . '</small></span>' .
		       '</span>';
	}
}
