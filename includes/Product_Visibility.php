<?php

namespace JustB2B;

/**
 * Product Visibility — B2B / B2C separation.
 *
 * Products with `justb2b_only_visible = "yes"` are hidden from every public
 * surface for non-B2B users:
 *
 *  – WooCommerce shop / archive / category / tag queries
 *  – WordPress search
 *  – WooCommerce [products] shortcode
 *  – WooCommerce product widgets
 *  – wc_get_products() / WC_Product_Query
 *  – WooCommerce REST API (V1 / V2 / V3)
 *  – Related products, up-sells, cross-sells
 *  – WC Product Table Lite plugin
 *  – Direct URL access (→ 404)
 *  – Any other WP_Query for the `product` post type
 */
class Product_Visibility {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// ── WP-level catch-all (search, custom queries, etc.) ────────
		add_action( 'pre_get_posts', [ $this, 'exclude_from_queries' ], 99 );

		// ── WooCommerce shop / archive queries ───────────────────────
		add_filter( 'woocommerce_product_query_meta_query', [ $this, 'add_visibility_meta_query' ] );

		// ── WooCommerce product visibility flag ──────────────────────
		add_filter( 'woocommerce_product_is_visible', [ $this, 'filter_product_visibility' ], 10, 2 );

		// ── [products] shortcode ─────────────────────────────────────
		add_filter( 'woocommerce_shortcode_products_query', [ $this, 'filter_shortcode_query' ] );

		// ── Products widget ──────────────────────────────────────────
		add_filter( 'woocommerce_products_widget_query_args', [ $this, 'filter_widget_query' ] );

		// ── wc_get_products() / WC_Product_Query ─────────────────────
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', [ $this, 'filter_data_store_query' ], 10, 2 );

		// ── REST API (V2 + V3 use the _object suffix) ────────────────
		add_filter( 'woocommerce_rest_product_object_query', [ $this, 'filter_rest_query' ], 10, 2 );

		// ── Related products ─────────────────────────────────────────
		add_filter( 'woocommerce_related_products', [ $this, 'filter_related_products' ], 10, 3 );

		// ── Cross-sells ──────────────────────────────────────────────
		add_filter( 'woocommerce_cart_crosssell_ids', [ $this, 'filter_product_id_list' ] );

		// ── WC Product Table Lite plugin ─────────────────────────────
		add_filter( 'wcpt_query_args', [ $this, 'filter_wcpt_query' ] );

		// ── Single product 404 ───────────────────────────────────────
		add_action( 'template_redirect', [ $this, 'block_single_product_access' ] );
	}

	/* ==================================================================
	 * Permission helpers
	 * ================================================================*/

	/**
	 * Whether the current user may see B2B-only products.
	 */
	private function user_can_see_b2b(): bool {
		return current_user_can( 'manage_options' ) || Helper::is_b2b_accepted_user();
	}

	/**
	 * Whether a given product ID is restricted to B2B users.
	 */
	private function is_b2b_only( int $product_id ): bool {
		return get_field( 'justb2b_only_visible', $product_id ) === 'yes';
	}

	/* ==================================================================
	 * Reusable meta-query snippet
	 * ================================================================*/

	/**
	 * Meta query that excludes products where justb2b_only_visible = 'yes'.
	 */
	private function get_exclude_meta_query(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => 'justb2b_only_visible',
				'value'   => 'yes',
				'compare' => '!=',
			],
			[
				'key'     => 'justb2b_only_visible',
				'compare' => 'NOT EXISTS',
			],
		];
	}

	/**
	 * Append the exclusion meta query to an existing meta_query array.
	 */
	private function append_meta_query( array $meta_query ): array {
		$meta_query[] = $this->get_exclude_meta_query();
		return $meta_query;
	}

	/* ==================================================================
	 * Filter a list of product IDs (used for related / cross-sells etc.)
	 * ================================================================*/

	/**
	 * Remove B2B-only IDs from an array of product IDs.
	 */
	public function filter_product_id_list( array $ids ): array {
		if ( $this->user_can_see_b2b() ) {
			return $ids;
		}

		return array_values( array_filter( $ids, function ( $id ) {
			return ! $this->is_b2b_only( (int) $id );
		} ) );
	}

	/* ==================================================================
	 * Hook callbacks
	 * ================================================================*/

	/**
	 * Catch-all: inject meta query into every front-end WP_Query for products.
	 */
	public function exclude_from_queries( \WP_Query $query ): void {
		if ( $this->user_can_see_b2b() ) {
			return;
		}

		// Only target product queries on the front end.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		// Normalise to array for comparison.
		$types = is_array( $post_type ) ? $post_type : [ $post_type ];

		if ( ! in_array( 'product', $types, true ) && ! in_array( 'product_variation', $types, true ) ) {
			// Also catch generic search that includes products.
			if ( ! ( $query->is_search() && empty( $post_type ) ) ) {
				return;
			}
		}

		$existing = (array) $query->get( 'meta_query', [] );
		$query->set( 'meta_query', $this->append_meta_query( $existing ) );
	}

	/**
	 * WooCommerce shop / archive meta query.
	 */
	public function add_visibility_meta_query( array $meta_query ): array {
		if ( $this->user_can_see_b2b() ) {
			return $meta_query;
		}

		return $this->append_meta_query( $meta_query );
	}

	/**
	 * Per-product visibility flag used by WooCommerce loops.
	 */
	public function filter_product_visibility( bool $visible, int $product_id ): bool {
		if ( ! $visible ) {
			return $visible;
		}

		if ( $this->user_can_see_b2b() ) {
			return $visible;
		}

		return ! $this->is_b2b_only( $product_id );
	}

	/**
	 * [products] shortcode query args.
	 */
	public function filter_shortcode_query( array $args ): array {
		if ( $this->user_can_see_b2b() ) {
			return $args;
		}

		$args['meta_query'] = $this->append_meta_query( $args['meta_query'] ?? [] );
		return $args;
	}

	/**
	 * Products widget query args.
	 */
	public function filter_widget_query( array $args ): array {
		if ( $this->user_can_see_b2b() ) {
			return $args;
		}

		$args['meta_query'] = $this->append_meta_query( $args['meta_query'] ?? [] );
		return $args;
	}

	/**
	 * wc_get_products() / WC_Product_Query data store args.
	 */
	public function filter_data_store_query( array $query, array $query_vars ): array {
		if ( $this->user_can_see_b2b() ) {
			return $query;
		}

		$query['meta_query'] = $this->append_meta_query( $query['meta_query'] ?? [] );
		return $query;
	}

	/**
	 * WooCommerce REST API product query args.
	 */
	public function filter_rest_query( array $args, \WP_REST_Request $request ): array {
		if ( $this->user_can_see_b2b() ) {
			return $args;
		}

		$args['meta_query'] = $this->append_meta_query( $args['meta_query'] ?? [] );
		return $args;
	}

	/**
	 * Related products (receives array of product IDs).
	 */
	public function filter_related_products( array $related_ids, int $product_id, array $args ): array {
		return $this->filter_product_id_list( $related_ids );
	}

	/**
	 * WC Product Table Lite plugin query args.
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

		// B2B visibility filter.
		if ( ! $this->user_can_see_b2b() ) {
			$query_args['meta_query'] = $this->append_meta_query( $query_args['meta_query'] ?? [] );
		}

		return $query_args;
	}

	/**
	 * Block direct URL access to B2B-only products for non-B2B users → 404.
	 */
	public function block_single_product_access(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		global $post;
		if ( ! $post || $post->post_type !== 'product' ) {
			return;
		}

		if ( $this->user_can_see_b2b() ) {
			return;
		}

		if ( ! $this->is_b2b_only( (int) $post->ID ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}