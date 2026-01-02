<?php
namespace JustB2B;

/**
 * Product Visibility Class
 * Handles filtering products based on B2B visibility settings
 */
class Product_Visibility
{
    private static $instance = null;

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_filter('woocommerce_product_query_meta_query', [$this, 'filter_b2b_only_products'], 10, 2);
        add_filter('wcpt_query_args', [$this, 'filter_wcpt_b2b_only_products'], 10, 1);
        add_action('template_redirect', [$this, 'check_single_product_access']);
    }

    /**
     * Check if the current user should see all products (B2B users and admins)
     */
    private function should_show_all_products()
    {
        return current_user_can('manage_options') || Helper::is_b2b_accepted_user();
    }

    /**
     * Get the meta query array to exclude B2B-only products
     */
    private function get_b2b_visibility_meta_query()
    {
        return array(
            'relation' => 'OR',
            array(
                'key'     => 'justb2b_only_visible',
                'value'   => 'yes',
                'compare' => '!=',
            ),
            array(
                'key'     => 'justb2b_only_visible',
                'compare' => 'NOT EXISTS',
            ),
        );
    }

    /**
     * Filter out B2B-only products for WooCommerce standard queries
     */
    public function filter_b2b_only_products($meta_query, $query)
    {
        // If user should see all products, return unchanged
        if ($this->should_show_all_products()) {
            return $meta_query;
        }

        // For non-B2B users, exclude products where justb2b_only_visible is 'yes'
        $meta_query[] = $this->get_b2b_visibility_meta_query();

        return $meta_query;
    }

    /**
     * Filter out B2B-only products for wc-product-table-lite plugin
     */
    public function filter_wcpt_b2b_only_products($query_args)
    {
        // Additional filters for WC Product Table: only purchasable, in stock, simple products
        $query_args['meta_query'][] = array(
            'key'     => '_stock_status',
            'value'   => 'instock',
            'compare' => '=',
        );

        $query_args['meta_query'][] = array(
            'key'     => '_price',
            'value'   => '',
            'compare' => '!=',
        );

        if (!isset($query_args['tax_query'])) {
            $query_args['tax_query'] = array();
        }

        $query_args['tax_query'][] = array(
            'taxonomy' => 'product_type',
            'field'    => 'slug',
            'terms'    => 'simple',
            'operator' => 'IN',
        );

        // If user should see all products, return unchanged
        if ($this->should_show_all_products()) {
            return $query_args;
        }

        // For non-B2B users, exclude products where justb2b_only_visible is 'yes'
        if (!isset($query_args['meta_query'])) {
            $query_args['meta_query'] = array();
        }

        $query_args['meta_query'][] = $this->get_b2b_visibility_meta_query();

        return $query_args;
    }

    /**
     * Check access to single product pages and show 404 for B2B-only products
     */
    public function check_single_product_access()
    {
        // Only check on single product pages
        if (!is_singular('product')) {
            return;
        }

        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        // Check if product is B2B-only
        $is_b2b_only = get_field('justb2b_only_visible', $post->ID);
        if ($is_b2b_only !== 'yes') {
            return; // Not B2B-only, allow access
        }

        // If user should see all products, allow access
        if ($this->should_show_all_products()) {
            return;
        }

        // User cannot see this B2B-only product, show 404
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
}