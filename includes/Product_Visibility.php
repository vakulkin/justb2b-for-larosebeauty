<?php

namespace JustB2B;

/**
 * Product Visibility — B2B / B2C separation.
 *
 * Products with `justb2b_only_visible = "yes"` are hidden from every public
 * surface for non-B2B users via meta_query conditions injected directly
 * into every relevant query. No transients, no pre-loaded ID lists —
 * filtering happens entirely at the SQL level.
 *
 * Covered surfaces:
 *  – WooCommerce shop / archive / category / tag queries
 *  – WordPress search
 *  – WooCommerce [products] shortcode
 *  – WooCommerce product widgets
 *  – wc_get_products() / WC_Product_Query
 *  – WooCommerce REST API (V2 / V3)
 *  – Related products, up-sells, cross-sells
 *  – WC Product Table Lite plugin
 *  – Direct URL access (→ 404)
 *  – Any other WP_Query for the `product` post type
 */
class Product_Visibility {

	private static $instance = null;

	/** Cached per-request: null = not yet checked. */
	private ?bool $can_see_b2b = null;

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
	 * Reusable meta-query snippet
	 * ================================================================*/

	/**
	 * Meta query that excludes products where justb2b_only_visible = 'yes'.
	 *
	 * Matches products where the meta key either does not exist or has
	 * any value other than 'yes'. WordPress translates this to a single
	 * LEFT JOIN + WHERE condition — no PHP-level filtering needed.
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
	 * Hook callbacks
	 * ================================================================*/

	/**
	 * Catch-all: inject meta query into every front-end WP_Query for products.
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

		$existing = (array) $query->get( 'meta_query', [] );
		$query->set( 'meta_query', $this->append_meta_query( $existing ) );
	}

	/**
	 * Generic query-args filter (shortcode, widget, etc.).
	 */
	public function filter_query_args( array $args ): array {
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
	 * Filter an array of product IDs (related, cross-sells, up-sells).
	 *
	 * These hooks receive pre-built ID arrays, so we run a single DB
	 * query to remove any B2B-only IDs from the list.
	 */
	public function filter_product_ids( array $ids ): array {
		if ( $this->user_can_see_b2b() || empty( $ids ) ) {
			return $ids;
		}

		global $wpdb;

		$ids_int      = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids_int ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$b2b_only_in_set = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = 'justb2b_only_visible'
			   AND meta_value = 'yes'
			   AND post_id IN ($placeholders)",
			...$ids_int
		) );

		if ( empty( $b2b_only_in_set ) ) {
			return $ids;
		}

		$b2b_only_in_set = array_map( 'intval', $b2b_only_in_set );

		return array_values( array_diff( $ids, $b2b_only_in_set ) );
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

		if ( ! $this->user_can_see_b2b() ) {
			$query_args['meta_query'] = $this->append_meta_query( $query_args['meta_query'] ?? [] );
		}

		return $query_args;
	}

	/**
	 * Block direct URL access to B2B-only products for non-B2B users → 404.
	 *
	 * Uses a single get_post_meta() call on the current product — no
	 * pre-loaded ID list needed.
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