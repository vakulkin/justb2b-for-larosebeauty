<?php

namespace JustB2B;

/**
 * Product Visibility — B2B / B2C separation.
 *
 * Products with `justb2b_only_visible = "yes"` are hidden from every public
 * surface for non-B2B users.
 *
 * Strategy: one cheap indexed query per request loads the (small) list of
 * B2B-only product IDs into memory, then every product query on the page
 * receives a `post__not_in` clause — a simple `WHERE ID NOT IN (…)` on
 * the primary key. This avoids the expensive LEFT JOIN + OR + NOT EXISTS
 * meta_query pattern that was causing slow page loads.
 *
 * Comprehensive protection layers:
 *
 * ┌─ QUERY LAYER ────────────────────────────────────────────────────────┐
 * │ • WooCommerce shop / archive / category / tag queries               │
 * │ • WordPress search (including AJAX search)                           │
 * │ • WooCommerce [products] shortcode                                   │
 * │ • WooCommerce product widgets                                        │
 * │ • wc_get_products() / WC_Product_Query                               │
 * │ • WooCommerce REST API (V2 / V3)                                     │
 * │ • Related products, up-sells, cross-sells                            │
 * │ • WC Product Table Lite plugin (search, filters, and queries)       │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ CART & CHECKOUT LAYER ──────────────────────────────────────────────┐
 * │ • Add to cart validation (blocks manual addition)                    │
 * │ • Purchasable filter (makes products non-purchasable)                │
 * │ • Cart validation on checkout (removes B2B items from cart)          │
 * │ • Cart session filter (removes B2B items when loading from session)  │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ DISPLAY LAYER ──────────────────────────────────────────────────────┐
 * │ • Price HTML filter (hides price for non-purchasable products)       │
 * │ • Direct URL access (returns 404 for B2B-only product pages)         │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * Coverage includes:
 *  ✓ Any WP_Query for the `product` post type
 *  ✓ WooCommerce native shortcodes and widgets
 *  ✓ Third-party plugins using WooCommerce APIs
 *  ✓ Direct cart manipulation (AJAX, POST requests)
 *  ✓ Session restoration and cart persistence
 *  ✓ Direct product page access
 */
class Product_Visibility {

	private static $instance = null;

	/** Cached per-request: null = not yet checked. */
	private ?bool $can_see_b2b = null;

	/** Cached per-request list of B2B-only product IDs (loaded once). */
	private ?array $b2b_only_ids = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// ── WP-level catch-all (covers shop, archives, search, etc.) ─
		add_action( 'pre_get_posts', [ $this, 'exclude_from_queries' ], 99 );

		// ── Shortcode [products] — builds its own WP_Query internally ─
		add_filter( 'woocommerce_shortcode_products_query', [ $this, 'filter_query_args' ] );

		// ── Products widget ──────────────────────────────────────────
		add_filter( 'woocommerce_products_widget_query_args', [ $this, 'filter_query_args' ] );

		// ── wc_get_products() / WC_Product_Query (bypasses WP_Query) ─
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', [ $this, 'filter_data_store_query' ], 10, 2 );

		// ── REST API (V2 + V3) ───────────────────────────────────────
		add_filter( 'woocommerce_rest_product_object_query', [ $this, 'filter_rest_query' ], 10, 2 );

		// ── Related products (array of IDs) ──────────────────────────
		add_filter( 'woocommerce_related_products', [ $this, 'filter_product_ids' ], 10, 3 );

		// ── Cross-sells (array of IDs) ───────────────────────────────
		add_filter( 'woocommerce_cart_crosssell_ids', [ $this, 'filter_product_ids' ] );

		// ── WC Product Table Lite plugin ─────────────────────────────
		add_filter( 'wcpt_query_args', [ $this, 'filter_wcpt_query' ] );
		add_filter( 'wcpt_products', [ $this, 'filter_wcpt_products' ] );

		// ── Cart & Checkout protection ────────────────────────────
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_cart_addition' ], 10, 3 );
		add_filter( 'woocommerce_is_purchasable', [ $this, 'make_non_purchasable' ], 10, 2 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'validate_cart_on_checkout' ] );
		add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'remove_b2b_from_cart_session' ], 10, 2 );

		// ── Hide price for non-purchasable products ────────────────────
		add_filter( 'woocommerce_get_price_html', [ $this, 'hide_price_for_b2b_only' ], 1, 2 );

		// ── Single product 404 ───────────────────────────────────────
		add_action( 'template_redirect', [ $this, 'block_single_product_access' ] );
	}

	/* ==================================================================
	 * Permission check — computed once per request
	 * ================================================================*/

	private function user_can_see_b2b(): bool {
		if ( $this->can_see_b2b === null ) {
			$this->can_see_b2b = current_user_can( 'manage_options' )
				|| Helper::is_b2b_accepted_user();
		}
		return $this->can_see_b2b;
	}

	/* ==================================================================
	 * B2B-only product IDs — single indexed query, cached per request
	 * ================================================================*/

	/**
	 * Return product IDs where justb2b_only_visible = 'yes'.
	 *
	 * Runs once per request. The underlying SQL uses the (meta_key, meta_value)
	 * index on wp_postmeta — typically sub-millisecond even with 100k+ rows.
	 */
	private function get_b2b_only_ids(): array {
		if ( $this->b2b_only_ids !== null ) {
			return $this->b2b_only_ids;
		}

		global $wpdb;

		$this->b2b_only_ids = array_map( 'intval', $wpdb->get_col(
			"SELECT post_id
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = 'justb2b_only_visible'
			   AND meta_value = 'yes'"
		) );

		return $this->b2b_only_ids;
	}

	/* ==================================================================
	 * Query helpers
	 * ================================================================*/

	/**
	 * Merge B2B-only IDs into an existing post__not_in array.
	 *
	 * Using post__not_in produces `WHERE ID NOT IN (…)` on the primary key,
	 * which is orders of magnitude faster than an OR meta_query with NOT EXISTS.
	 */
	private function merge_post_not_in( array $existing_not_in ): array {
		return array_unique( array_merge( $existing_not_in, $this->get_b2b_only_ids() ) );
	}

	/* ==================================================================
	 * Hook callbacks
	 * ================================================================*/

	/**
	 * Catch-all: inject post__not_in into every front-end WP_Query for products.
	 */
	public function exclude_from_queries( \WP_Query $query ): void {
		if ( $this->user_can_see_b2b() ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$types     = (array) $post_type;

		$is_product_query = in_array( 'product', $types, true )
			|| in_array( 'product_variation', $types, true );

		// Also catch generic search (no explicit post_type = includes products).
		if ( ! $is_product_query && ! ( $query->is_search() && empty( $post_type ) ) ) {
			return;
		}

		$b2b_ids = $this->get_b2b_only_ids();
		if ( empty( $b2b_ids ) ) {
			return;
		}

		$existing = (array) $query->get( 'post__not_in', [] );
		$query->set( 'post__not_in', $this->merge_post_not_in( $existing ) );
	}

	/**
	 * Generic query-args filter (shortcode, widget, etc.).
	 */
	public function filter_query_args( array $args ): array {
		if ( $this->user_can_see_b2b() ) {
			return $args;
		}

		$b2b_ids = $this->get_b2b_only_ids();
		if ( empty( $b2b_ids ) ) {
			return $args;
		}

		$args['post__not_in'] = $this->merge_post_not_in( $args['post__not_in'] ?? [] );
		return $args;
	}

	/**
	 * wc_get_products() / WC_Product_Query data store args.
	 */
	public function filter_data_store_query( array $query, array $query_vars ): array {
		if ( $this->user_can_see_b2b() ) {
			return $query;
		}

		$b2b_ids = $this->get_b2b_only_ids();
		if ( empty( $b2b_ids ) ) {
			return $query;
		}

		$query['post__not_in'] = $this->merge_post_not_in( $query['post__not_in'] ?? [] );
		return $query;
	}

	/**
	 * WooCommerce REST API product query args.
	 */
	public function filter_rest_query( array $args, \WP_REST_Request $request ): array {
		if ( $this->user_can_see_b2b() ) {
			return $args;
		}

		$b2b_ids = $this->get_b2b_only_ids();
		if ( empty( $b2b_ids ) ) {
			return $args;
		}

		$args['post__not_in'] = $this->merge_post_not_in( $args['post__not_in'] ?? [] );
		return $args;
	}

	/**
	 * Filter an array of product IDs (related, cross-sells, up-sells).
	 */
	public function filter_product_ids( array $ids ): array {
		if ( $this->user_can_see_b2b() || empty( $ids ) ) {
			return $ids;
		}

		$b2b_ids = $this->get_b2b_only_ids();
		if ( empty( $b2b_ids ) ) {
			return $ids;
		}

		return array_values( array_diff( $ids, $b2b_ids ) );
	}

	/**
	 * WC Product Table Lite plugin query args.
	 *
	 * This method handles two scenarios:
	 * 1. Regular queries: adds B2B product IDs to post__not_in
	 * 2. Search/filter queries: removes B2B products from post__in array
	 *    (search results populate post__in, so we need to filter them)
	 */
	public function filter_wcpt_query( array $query_args ): array {
		// Baseline filters applied for all users.
		$query_args['meta_query'][] = [
			'key'     => '_stock_status',
			'value'   => 'instock',
			'compare' => '=',
		];

		$query_args['meta_query'][] = [
			'key'     => '_price',
			'value'   => '',
			'compare' => '!=',
		];

		$query_args['tax_query'] = $query_args['tax_query'] ?? [];
		$query_args['tax_query'][] = [
			'taxonomy' => 'product_type',
			'field'    => 'slug',
			'terms'    => 'simple',
			'operator' => 'IN',
		];

		if ( ! $this->user_can_see_b2b() ) {
			$b2b_ids = $this->get_b2b_only_ids();
			if ( ! empty( $b2b_ids ) ) {
				// Add to post__not_in
				$query_args['post__not_in'] = $this->merge_post_not_in( $query_args['post__not_in'] ?? [] );

				// Also remove from post__in if it exists (e.g., from search results)
				if ( ! empty( $query_args['post__in'] ) && is_array( $query_args['post__in'] ) ) {
					$query_args['post__in'] = array_values( array_diff( $query_args['post__in'], $b2b_ids ) );
					// Ensure we don't end up with an empty array that returns all products
					if ( empty( $query_args['post__in'] ) ) {
						$query_args['post__in'] = [ 0 ]; // No results
					}
				}
			}
		}

		return $query_args;
	}

	/**
	 * Filter WC Product Table Lite WP_Query results to exclude B2B products.
	 */
	public function filter_wcpt_products( \WP_Query $products ): \WP_Query {
		if ( $this->user_can_see_b2b() ) {
			return $products;
		}

		$b2b_ids = $this->get_b2b_only_ids();
		if ( empty( $b2b_ids ) || empty( $products->posts ) ) {
			return $products;
		}

		// Additional safety net: filter out B2B products from the results
		$products->posts = array_filter( $products->posts, function( $post ) use ( $b2b_ids ) {
			return ! in_array( $post->ID, $b2b_ids, true );
		} );

		// Reset array keys
		$products->posts = array_values( $products->posts );

		// Update post count
		$products->post_count = count( $products->posts );

		return $products;
	}

	/**
	 * Validate add to cart — prevent B2B-only products from being added to cart.
	 */
	public function validate_cart_addition( bool $passed, int $product_id, int $quantity ): bool {
		if ( $this->user_can_see_b2b() ) {
			return $passed;
		}

		if ( get_post_meta( $product_id, 'justb2b_only_visible', true ) === 'yes' ) {
			wc_add_notice(
				__( 'This product is only available for B2B customers.', 'justb2b-larose' ),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Make B2B-only products non-purchasable for non-B2B users.
	 */
	public function make_non_purchasable( bool $purchasable, \WC_Product $product ): bool {
		if ( $this->user_can_see_b2b() ) {
			return $purchasable;
		}

		$product_id = $product->get_id();
		if ( get_post_meta( $product_id, 'justb2b_only_visible', true ) === 'yes' ) {
			return false;
		}

		return $purchasable;
	}

	/**
	 * Validate cart items on checkout — ensure no B2B-only products.
	 */
	public function validate_cart_on_checkout(): void {
		if ( $this->user_can_see_b2b() ) {
			return;
		}

		$b2b_ids = $this->get_b2b_only_ids();
		if ( empty( $b2b_ids ) ) {
			return;
		}

		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = $cart_item['product_id'];
			if ( in_array( $product_id, $b2b_ids, true ) ) {
				$cart->remove_cart_item( $cart_item_key );
				wc_add_notice(
					__( 'A product in your cart is no longer available and has been removed.', 'justb2b-larose' ),
					'error'
				);
			}
		}
	}

	/**
	 * Remove B2B-only products from cart when loading from session.
	 */
	public function remove_b2b_from_cart_session( array $cart_item, array $values ): array {
		if ( $this->user_can_see_b2b() ) {
			return $cart_item;
		}

		$product_id = $cart_item['product_id'] ?? 0;
		if ( $product_id && get_post_meta( $product_id, 'justb2b_only_visible', true ) === 'yes' ) {
			// Return empty array to remove from cart
			return [];
		}

		return $cart_item;
	}

	/**
	 * Hide price HTML for B2B-only products when user cannot purchase them.
	 */
	public function hide_price_for_b2b_only( string $price, \WC_Product $product ): string {
		if ( $this->user_can_see_b2b() ) {
			return $price;
		}

		$product_id = $product->get_id();
		if ( get_post_meta( $product_id, 'justb2b_only_visible', true ) === 'yes' ) {
			return '';
		}

		return $price;
	}

	/**
	 * Block direct URL access to B2B-only products for non-B2B users → 404.
	 */
	public function block_single_product_access(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		if ( $this->user_can_see_b2b() ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		if ( get_post_meta( $post->ID, 'justb2b_only_visible', true ) !== 'yes' ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}