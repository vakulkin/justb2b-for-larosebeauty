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
 *  – WooCommerce REST API (V2 / V3)
 *  – Related products, up-sells, cross-sells
 *  – WC Product Table Lite plugin
 *  – Direct URL access (→ 404)
 *  – Any other WP_Query for the `product` post type
 */
class Product_Visibility {

	private static $instance = null;

	private const TRANSIENT_KEY = 'justb2b_b2b_only_ids';
	private const TRANSIENT_TTL = DAY_IN_SECONDS;

	/** Cached per-request: null = not yet checked. */
	private ?bool $can_see_b2b = null;

	/** Cached list of B2B-only product IDs (loaded once per request). */
	private ?array $b2b_only_ids = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		/*
		 * Strategy: use a single `pre_get_posts` catch-all to inject the
		 * meta-query into every front-end product query.  WooCommerce-specific
		 * filters are only added where `pre_get_posts` cannot reach (data-store,
		 * REST API, shortcode, widget, ID lists).  This avoids duplicate
		 * meta-query clauses that cause extra SQL JOINs.
		 */

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

		// ── Invalidate transient when B2B visibility meta changes ────
		add_action( 'acf/save_post', [ $this, 'maybe_flush_cache' ] );
		add_action( 'updated_post_meta', [ $this, 'flush_on_meta_change' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'flush_on_meta_change' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'flush_on_meta_change' ], 10, 4 );
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
	 * B2B-only product IDs — transient + per-request property cache
	 * ================================================================*/

	/**
	 * Return an array of product IDs marked as B2B-only.
	 *
	 * First check: in-memory property (zero cost on repeat calls).
	 * Second check: transient (persists across requests, avoids DB hit).
	 * Fallback: single DB query, then store in transient + property.
	 */
	private function get_b2b_only_ids(): array {
		if ( $this->b2b_only_ids !== null ) {
			return $this->b2b_only_ids;
		}

		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) ) {
			$this->b2b_only_ids = $cached;
			return $this->b2b_only_ids;
		}

		global $wpdb;

		$this->b2b_only_ids = array_map( 'intval', $wpdb->get_col(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = 'justb2b_only_visible'
			   AND meta_value = 'yes'"
		) );

		set_transient( self::TRANSIENT_KEY, $this->b2b_only_ids, self::TRANSIENT_TTL );

		return $this->b2b_only_ids;
	}

	/**
	 * Whether a given product ID is restricted to B2B users.
	 */
	private function is_b2b_only( int $product_id ): bool {
		return in_array( $product_id, $this->get_b2b_only_ids(), true );
	}

	/* ==================================================================
	 * Cache invalidation
	 * ================================================================*/

	/**
	 * Flush the transient + in-memory cache.
	 */
	private function flush_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
		$this->b2b_only_ids = null;
	}

	/**
	 * Called on `acf/save_post` — flush when a product is saved.
	 */
	public function maybe_flush_cache( $post_id ): void {
		if ( get_post_type( $post_id ) === 'product' ) {
			$this->flush_cache();
		}
	}

	/**
	 * Called on updated/added/deleted_post_meta — flush only when
	 * the justb2b_only_visible key changes.
	 */
	public function flush_on_meta_change( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( $meta_key === 'justb2b_only_visible' ) {
			$this->flush_cache();
		}
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
	 * Uses the cached ID list — zero extra DB queries.
	 */
	public function filter_product_ids( array $ids ): array {
		if ( $this->user_can_see_b2b() ) {
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
	 */
	public function block_single_product_access(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		if ( $this->user_can_see_b2b() ) {
			return;
		}

		global $post;
		if ( ! $post || ! $this->is_b2b_only( (int) $post->ID ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}