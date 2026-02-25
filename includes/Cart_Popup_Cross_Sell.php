<?php

namespace JustB2B;

/**
 * Cart Popup Cross-Sell Class
 *
 * Extends the Woodmart "added to cart" popup with 3 random products
 * from the same category as the product just added.
 * Works without modifying any Woodmart theme files.
 */
class Cart_Popup_Cross_Sell {
	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// AJAX handler for getting cross-sell HTML
		add_action( 'wp_ajax_justb2b_get_cross_sell_html', [ $this, 'ajax_get_cross_sell_html' ] );
		add_action( 'wp_ajax_nopriv_justb2b_get_cross_sell_html', [ $this, 'ajax_get_cross_sell_html' ] );

		// Enqueue frontend JS & CSS
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Build cross-sell products HTML based on the last product added to cart.
	 */
	private function get_cross_sell_html() {
		if ( ! WC()->cart ) {
			return '';
		}

		// Get the last product added to cart
		$cart_contents = WC()->cart->get_cart();
		if ( empty( $cart_contents ) ) {
			return '';
		}

		// Get the most recently added item (last in array)
		$last_item = end( $cart_contents );
		$product_id = $last_item['product_id'];
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return '';
		}

		// Query 3 random products from the same categories, excluding the current product
		$args = [
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => 3,
			'orderby' => 'rand',
			'post__not_in' => [ $product_id ],
			'meta_query' => [
				[
					'key' => '_stock_status',
					'value' => 'instock',
					'compare' => '=',
				],
			],
		];

		// Also exclude all products already in the cart
		$exclude_ids = [ $product_id ];
		foreach ( $cart_contents as $cart_item ) {
			$exclude_ids[] = $cart_item['product_id'];
		}
		$args['post__not_in'] = array_unique( $exclude_ids );

		$query = new \WP_Query( $args );

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			return '';
		}

		ob_start();
		?>
		<div class="justb2b-popup-cross-sell">
			<h4 class="justb2b-cross-sell-title">
				<?php esc_html_e( 'You may also like', 'justb2b-larose' ); ?>
			</h4>
			<div class="justb2b-cross-sell-products">
				<?php while ( $query->have_posts() ) :
					$query->the_post();
					$cross_product = wc_get_product( get_the_ID() );
					if ( ! $cross_product ) {
						continue;
					}
					$image = $cross_product->get_image( 'woocommerce_thumbnail' );
					$title = $cross_product->get_name();
					$permalink = $cross_product->get_permalink();

					// Handle price display for B2B vs regular users
					$price = ''; // Initialize price variable
					if ( \JustB2B\Helper::is_b2b_accepted_user() ) {
						// Temporarily set global $product for display_b2b_price method
						global $product;
						$original_product = $product;
						$product = $cross_product;

						// Capture the output of display_b2b_price method
						ob_start();
						\JustB2B\Price_Display::instance()->display_b2b_price();
						$price = ob_get_clean();

						// Restore original product
						$product = $original_product;

						// If no price was captured, fall back to regular price
						if ( empty( trim( $price ) ) ) {
							$price = $cross_product->get_price_html();
						}
					} else {
						$price = $cross_product->get_price_html();
					}
					?>
					<a href="<?php echo esc_url( $permalink ); ?>" class="justb2b-cross-sell-item">
						<div class="justb2b-cross-sell-image">
							<?php echo $image; ?>
						</div>
						<div class="justb2b-cross-sell-name">
							<?php echo esc_html( $title ); ?>
						</div>
						<div class="justb2b-cross-sell-price">
							<?php echo $price; ?>
						</div>
					</a>
				<?php endwhile; ?>
			</div>
		</div>
		<?php
		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * AJAX handler to get cross-sell HTML
	 */
	public function ajax_get_cross_sell_html() {
		// Verify nonce if provided
		if ( isset( $_POST['nonce'] ) && ! wp_verify_nonce( $_POST['nonce'], 'justb2b_cross_sell' ) ) {
			wp_die( 'Security check failed' );
		}

		$html = $this->get_cross_sell_html();

		wp_send_json_success( $html );
	}

	/**
	 * Enqueue frontend assets.
	 */
	public function enqueue_assets() {
		wp_enqueue_script(
			'justb2b-cart-popup-cross-sell',
			JUSTB2B_PLUGIN_URL . 'assets/js/cart-popup-cross-sell.js',
			[ 'jquery' ],
			JUSTB2B_VERSION,
			true
		);

		// Localize script with nonce
		wp_localize_script( 'justb2b-cart-popup-cross-sell', 'justb2b_cross_sell', [
			'nonce' => wp_create_nonce( 'justb2b_cross_sell' )
		] );
	}
}
?>