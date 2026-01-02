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
		echo '<div class="justb2b-price-info" style="margin: 15px 0; padding: 15px; background: #f7f7f7; border-left: 4px solid #2c3e50;">';
		echo '<h4 style="margin: 0 0 10px 0; color: #2c3e50;">' . __( 'B2B Pricing', 'justb2b-larose' ) . '</h4>';

		echo '<div class="b2b-price-row" style="margin-bottom: 8px;">';
		echo '<strong>' . __( 'B2B Price (Net):', 'justb2b-larose' ) . '</strong> ';
		echo wc_price( $b2b_netto );
		echo '</div>';

		echo '<div class="b2b-price-row">';
		echo '<strong>' . __( 'Regular Recommended Price (Gross):', 'justb2b-larose' ) . '</strong> ';
		echo wc_price( $regular_price );

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
}
