<?php
namespace JustB2B;

/**
 * Helper Class
 * Contains utility methods for B2B functionality
 */
class Helper
{
    /**
     * Check if user is B2B
     */
    public static function is_b2b_user($user_id = null)
    {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return false;
        }

        // Try to get from cache first
        $cache_key = 'justb2b_role_' . $user_id;
        $cached_role = wp_cache_get($cache_key, 'justb2b');
        
        if ($cached_role !== false) {
            return $cached_role === 'b2b';
        }

        $role = get_field('justb2b_role', 'user_' . $user_id);
        $is_b2b = $role === 'b2b';
        
        wp_cache_set($cache_key, $role, 'justb2b');
        
        return $is_b2b;
    }

    /**
     * Get B2B price for a product
     */
    public static function get_product_b2b_price($product_id)
    {
        // Try to get from cache first
        $cache_key = 'justb2b_price_' . $product_id;
        $cached_price = wp_cache_get($cache_key, 'justb2b');
        
        if ($cached_price !== false) {
            return $cached_price === 'null' ? null : floatval($cached_price);
        }
        
        $product = wc_get_product($product_id);

        if (!$product || $product->get_type() !== 'simple') {
            wp_cache_set($cache_key, 'null', 'justb2b');
            return null;
        }

        $b2b_price = get_field('justb2b_price', $product_id);

        if ($b2b_price && is_numeric($b2b_price) && $b2b_price > 0) {
            wp_cache_set($cache_key, floatval($b2b_price), 'justb2b');
            return floatval($b2b_price);
        }

        wp_cache_set($cache_key, 'null', 'justb2b');
        return null;
    }

    /**
     * Get product tax rate
     */
    public static function get_product_tax_rate($product)
    {
        // Try to get from cache first
        $cache_key = 'justb2b_tax_rate_' . $product->get_id();
        $cached_rate = wp_cache_get($cache_key, 'justb2b');
        
        if ($cached_rate !== false) {
            return floatval($cached_rate);
        }
        
        $tax_rates = \WC_Tax::get_rates($product->get_tax_class());
        $tax_rate = 0;
        
        if (!empty($tax_rates)) {
            $tax_rate_data = reset($tax_rates);
            $tax_rate = floatval($tax_rate_data['rate']) / 100;
        }
        
        wp_cache_set($cache_key, $tax_rate, 'justb2b');
        
        return $tax_rate;
    }

    /**
     * Calculate brutto price from netto
     */
    public static function calculate_brutto($netto_price, $tax_rate = null)
    {
        if ($tax_rate === null || $tax_rate === 0) {
            return $netto_price;
        }

        return $netto_price * (1 + $tax_rate);
    }
}
