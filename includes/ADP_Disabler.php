<?php

namespace JustB2B;

/**
 * ADP Disabler Class
 *
 * Completely disables Advanced Dynamic Pricing for WooCommerce plugin
 * for B2B accepted users. Covers:
 * - Rule calculation engine (main kill switch)
 * - Cart processing (initial + reprocessing)
 * - AJAX & REST API strategies
 * - ExternalHookSuppressor (prevents ADP from stripping our B2B pricing hooks)
 * - Per-rule application
 */
class ADP_Disabler {
	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// ── Core engine kill switch ──
		// Suppresses ALL ADP pricing rule calculations
		add_filter( 'adp_rules_suppression', [ $this, 'suppress_for_b2b' ] );

		// ── Cart processing ──
		// Disable cart reprocessing after calculate_totals
		add_filter( 'adp_disable_process_after_calculate_totals', [ $this, 'suppress_for_b2b' ] );

		// Skip initial cart processing on page load
		add_filter( 'adp_dont_process_cart_on_page_load', [ $this, 'suppress_for_b2b' ] );

		// Disable checkout order review reprocessing
		add_filter( 'wdp_checkout_update_order_review_process_enabled', [ $this, 'disable_for_b2b' ] );

		// ── Strategy-level disabling (AJAX & REST API) ──
		// Prevent ADP AJAX strategy from loading entirely
		add_filter( 'adp_wp_admin_ajax_strategy_load', [ $this, 'disable_for_b2b' ] );

		// Prevent ADP REST API strategy from loading entirely
		add_filter( 'adp_wp_rest_api_strategy_load', [ $this, 'disable_for_b2b' ] );

		// Prevent ADP WP Cron strategy from loading
		add_filter( 'adp_wp_cron_strategy_load', [ $this, 'disable_for_b2b' ] );

		// ── Per-rule safety net ──
		// Block every individual rule from being applied
		add_filter( 'adp_is_apply_rule', [ $this, 'block_rule_for_b2b' ], 10, 4 );

		// ── Price display performance optimization ──
		// Prevents ADP from running calculateProduct() on every product display
		// (woocommerce_get_price_html, _is_on_sale, _get_sale_price, _get_regular_price)
		add_filter( 'adp_get_price_html_is_mod_needed', [ $this, 'disable_for_b2b' ], 10, 3 );

		// ── Restore WC Product Factory ──
		// ADP replaces WC()->product_factory with PricingProductFactory.
		// Restore the original factory for B2B users.
		add_action( 'woocommerce_init', [ $this, 'restore_wc_product_factory' ], PHP_INT_MAX );

		// ── Prevent persistent rules from polluting sale product queries ──
		// ADP modifies [sale_products] shortcode queries to include ADP-rule products.
		add_filter( 'woocommerce_shortcode_products_query', [ $this, 'clean_sale_query_for_b2b' ], 5, 3 );

		// ── ExternalHookSuppressor protection ──
		// ADP can strip hooks from other pricing plugins (including ours).
		// Remove ADP's suppressor before it fires (it runs at wp_loaded priority 10).
		add_action( 'wp_loaded', [ $this, 'remove_adp_external_hook_suppressor' ], 1 );
	}

	/**
	 * Return true to suppress/enable a flag for B2B users
	 */
	public function suppress_for_b2b( $value ) {
		if ( Helper::is_b2b_accepted_user() ) {
			return true;
		}
		return $value;
	}

	/**
	 * Return false to disable a feature for B2B users
	 */
	public function disable_for_b2b( $value ) {
		if ( Helper::is_b2b_accepted_user() ) {
			return false;
		}
		return $value;
	}

	/**
	 * Block every individual ADP rule for B2B users (safety net)
	 */
	public function block_rule_for_b2b( $is_apply, $rule, $processor, $cart ) {
		if ( Helper::is_b2b_accepted_user() ) {
			return false;
		}
		return $is_apply;
	}

	/**
	 * Remove ADP's ExternalHooksSuppressor so it doesn't strip
	 * our B2B pricing hooks from WooCommerce filters.
	 *
	 * Must run before ADP's wp_loaded (priority 10).
	 */
	public function remove_adp_external_hook_suppressor() {
		if ( ! Helper::is_b2b_accepted_user() ) {
			return;
		}

		global $wp_filter;

		if ( empty( $wp_filter['wp_loaded'] ) ) {
			return;
		}

		foreach ( $wp_filter['wp_loaded']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $key => $callback ) {
				if ( ! is_array( $callback['function'] ) ) {
					continue;
				}

				$object = $callback['function'][0] ?? null;

				if ( is_object( $object ) && (
					$object instanceof \ADP\BaseVersion\Includes\ExternalHookSuppression\ExternalHooksSuppressor
					|| get_class( $object ) === 'ADP\BaseVersion\Includes\ExternalHookSuppression\ExternalHooksSuppressor'
				) ) {
					remove_action( 'wp_loaded', $callback['function'], $priority );
				}
			}
		}
	}

	/**
	 * Restore the original WC_Product_Factory for B2B users.
	 * ADP replaces it with PricingProductFactory which fires
	 * adp_product_get_price on every wc_get_product() call.
	 */
	public function restore_wc_product_factory() {
		if ( ! Helper::is_b2b_accepted_user() ) {
			return;
		}

		if ( function_exists( 'WC' ) && WC()->product_factory instanceof \ADP\BaseVersion\Includes\ProductExtensions\PricingProductFactory ) {
			WC()->product_factory = new \WC_Product_Factory();
		}
	}

	/**
	 * Remove ADP's persistent-rule products from [sale_products] shortcode
	 * queries for B2B users so they don't see ADP-only sale badges.
	 */
	public function clean_sale_query_for_b2b( $query_args, $atts, $type ) {
		if ( ! Helper::is_b2b_accepted_user() ) {
			return $query_args;
		}

		// Let WooCommerce handle sale products natively without ADP additions
		if ( $type === 'sale_products' ) {
			$query_args['post__in'] = wc_get_product_ids_on_sale();
		}

		return $query_args;
	}
}
