<?php

namespace JustB2B;

/**
 * Cart Handler Class
 */
class Cart_Handler
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
        add_action('woocommerce_before_calculate_totals', [ $this, 'override_cart_price' ], 10);
        add_filter('woocommerce_package_rates', [ $this, 'apply_free_shipping_for_b2b' ], 10, 2);
        add_filter('woocommerce_available_payment_gateways', [ $this, 'filter_payment_gateways' ]);
        add_filter('woocommerce_cart_needs_shipping', [ $this, 'disable_shipping_calculation' ]);
        add_action('woocommerce_checkout_update_order_review', [ $this, 'reset_shipping_cache' ]);
        add_filter('option_xts-woodmart-options', [ $this, 'override_woodmart_options_for_b2b' ], 10, 1);
        add_filter('woocommerce_coupons_enabled', [ $this, 'disable_coupons_for_b2b' ]);
        add_action('woocommerce_before_calculate_totals', [ $this, 'add_sample_product_for_b2b' ], 20);
        add_filter('woocommerce_cart_item_remove_link', [ $this, 'prevent_sample_removal' ], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [ $this, 'prevent_sample_quantity_change' ], 10, 3);
    }

    /**
     * Override cart item price for B2B users
     */
    public function override_cart_price($cart)
    {
        // Skip if not B2B user
        if (! Helper::is_b2b_accepted_user()) {
            return;
        }

        // Avoid infinite loops
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }

        // Loop through cart items
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];

            // Only process simple products
            if ($product->get_type() !== 'simple') {
                continue;
            }

            $product_id = $product->get_id();
            $b2b_price = Helper::get_product_b2b_price($product_id);

            // If B2B price exists, apply it
            if ($b2b_price) {
                // Get tax rate for the product
                $tax_rate = Helper::get_product_tax_rate($product);

                // Calculate brutto price (with tax)
                $b2b_brutto = Helper::calculate_brutto($b2b_price, $tax_rate);

                // Set the new price (brutto/gross price)
                $product->set_price($b2b_brutto);
            }
        }
    }

    /**
     * Apply free shipping for B2B users when order total is over 1000 (including taxes)
     * Only applies to InPost shipping methods
     */
    public function apply_free_shipping_for_b2b($rates, $package)
    {
        // Calculate cart subtotal including taxes
        $cart_subtotal = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
        // Round to 2 decimal places to avoid floating-point precision issues
        $cart_subtotal = round($cart_subtotal, 2);

        $free_shipping_amount = Helper::is_b2b_accepted_user() ? 1000 : 600;

        if ($cart_subtotal >= $free_shipping_amount) {
            foreach ($rates as $rate_key => $rate) {
                // if ( strpos( $rate_key, 'free_shipping' ) !== false ) {
                // 	unset( $rates[ $rate_key ] );
                // 	continue;
                // }

                // Check if rate label/title contains "inpost" (case-insensitive)
                $rate_label = isset($rate->label) ? $rate->label : '';
                if (stripos($rate_label, 'inpost') !== false) {
                    $rates[ $rate_key ]->cost = 0;
                    // $rates[ $rate_key ]->taxes = array();
                }
            }
        }

        return $rates;
    }

    public function disable_shipping_calculation($needs_shipping)
    {
        if (is_cart()) {
            return false;
        }
        return $needs_shipping;
    }

    public function reset_shipping_cache()
    {
        if (! function_exists('WC') || ! WC()->cart || ! WC()->session) {
            return;
        }

        $packages = WC()->cart->get_shipping_packages();
        foreach ($packages as $key => $value) {
            $sessionKey = "shipping_for_package_$key";
            WC()->session->set($sessionKey, null);
        }
    }

    /**
     * Override WoodMart options for B2B users
     */
    public function override_woodmart_options_for_b2b($options)
    {
        // Only override for B2B accepted users

        if (! Helper::is_b2b_accepted_user()) {
            $options['shipping_progress_bar_calculation'] = 'custom';
            $options['shipping_progress_bar_amount'] = 600;
            return $options;
        }

        if (! is_array($options)) {
            $options = array();
        }

        // Override shipping progress bar amount to 1000 for B2B
        $options['shipping_progress_bar_calculation'] = 'custom';
        $options['shipping_progress_bar_amount'] = 1000;

        return $options;
    }

    /**
     * Filter payment gateways - remove BACS for non-B2B users and modify title for B2B users
     */
    public function filter_payment_gateways($available_gateways)
    {
        if (isset($available_gateways['bacs'])) {
            if (Helper::is_b2b_accepted_user()) {
                // Modify BACS title for B2B users
                $available_gateways['bacs']->title = 'Przelew bankowy z terminem 14 dni';
            } else {
                // Remove BACS for non-B2B users
                unset($available_gateways['bacs']);
            }
        }
        return $available_gateways;
    }

    /**
     * Disable coupons for B2B users
     */
    public function disable_coupons_for_b2b($enabled)
    {
        if (Helper::is_b2b_accepted_user()) {
            return false;
        }
        return $enabled;
    }

    /**
     * Get or create the sample product for specific tier
     */
    private function get_or_create_sample_product($tier, $sample_count)
    {
        $product_name = "Mix próbek, {$sample_count} próbek - przy zamówieniu {$tier}";
        
        // Check if product already exists
        $existing_products = get_posts([
            'post_type' => 'product',
            'title' => $product_name,
            'post_status' => 'any',
            'numberposts' => 1,
        ]);

        if (!empty($existing_products)) {
            return $existing_products[0]->ID;
        }

        // Create new product
        $product = new \WC_Product_Simple();
        $product->set_name($product_name);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_regular_price(0.01);
        $product->set_virtual(true);
        $product->set_sold_individually(false);
        $product_id = $product->save();

        // Add meta to identify this as the sample product
        update_post_meta($product_id, '_is_b2b_sample_product', 'yes');
        update_post_meta($product_id, '_b2b_sample_tier', $tier);

        return $product_id;
    }

    /**
     * Get all sample product IDs
     */
    private function get_all_sample_product_ids()
    {
        $sample_products = get_posts([
            'post_type' => 'product',
            'meta_key' => '_is_b2b_sample_product',
            'meta_value' => 'yes',
            'fields' => 'ids',
            'numberposts' => -1,
        ]);
        
        return $sample_products;
    }

    /**
     * Add sample product to cart for B2B users based on order total (NETTO)
     */
    public function add_sample_product_for_b2b($cart)
    {
        // Skip if not B2B user
        if (!Helper::is_b2b_accepted_user()) {
            return;
        }

        // Avoid infinite loops
        if (did_action('woocommerce_before_calculate_totals') >= 3) {
            return;
        }

        $all_sample_ids = $this->get_all_sample_product_ids();

        // Calculate cart subtotal NETTO (excluding sample products)
        $cart_subtotal_netto = 0;
        foreach ($cart->get_cart() as $cart_item) {
            if (!in_array($cart_item['product_id'], $all_sample_ids)) {
                $cart_subtotal_netto += $cart_item['line_subtotal']; // NETTO only
            }
        }
        $cart_subtotal_netto = round($cart_subtotal_netto, 2);

        // Determine which tier sample product to add based on cart total NETTO
        $sample_info = null;
        $target_product_id = null;
        
        if ($cart_subtotal_netto >= 5000) {
            $sample_info = ['tier' => '5000 zł', 'count' => 20];
        } elseif ($cart_subtotal_netto >= 3000) {
            $sample_info = ['tier' => '3000 zł', 'count' => 15];
        } elseif ($cart_subtotal_netto >= 2000) {
            $sample_info = ['tier' => '2000 zł', 'count' => 10];
        } elseif ($cart_subtotal_netto >= 1000) {
            $sample_info = ['tier' => '1000 zł', 'count' => 5];
        }

        // Get the target product ID if we need one
        if ($sample_info !== null) {
            $target_product_id = $this->get_or_create_sample_product($sample_info['tier'], $sample_info['count']);
        }

        // Check what sample products are currently in cart and remove wrong ones
        $has_correct_sample = false;
        $cart_items_to_remove = [];
        
        foreach ($cart->get_cart() as $cart_key => $cart_item) {
            if (in_array($cart_item['product_id'], $all_sample_ids)) {
                if ($cart_item['product_id'] == $target_product_id) {
                    // Correct sample is already in cart, ensure quantity is 1
                    $has_correct_sample = true;
                    if ($cart_item['quantity'] != 1) {
                        $cart->set_quantity($cart_key, 1, true);
                    }
                } else {
                    // Wrong sample, mark for removal
                    $cart_items_to_remove[] = $cart_key;
                }
            }
        }
        
        // Remove wrong samples
        foreach ($cart_items_to_remove as $cart_key) {
            $cart->remove_cart_item($cart_key);
        }

        // Add the appropriate sample product if needed and not already in cart
        if ($target_product_id !== null && !$has_correct_sample) {
            // Generate a cart item key
            $cart_item_key = $cart->generate_cart_id($target_product_id);
            
            // Check if this exact item already exists in cart
            if (!isset($cart->cart_contents[$cart_item_key])) {
                // Manually add to cart contents to avoid WooCommerce validation
                $product_data = wc_get_product($target_product_id);
                if ($product_data) {
                    $cart->cart_contents[$cart_item_key] = array(
                        'key' => $cart_item_key,
                        'product_id' => $target_product_id,
                        'variation_id' => 0,
                        'variation' => array(),
                        'quantity' => 1,
                        'data' => $product_data,
                        'data_hash' => wc_get_cart_item_data_hash($product_data),
                    );
                }
            }
        }
    }

    /**
     * Prevent users from manually removing sample products
     */
    public function prevent_sample_removal($link, $cart_item_key)
    {
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        if (!$cart_item) {
            return $link;
        }

        $product_id = $cart_item['product_id'];
        $is_sample = get_post_meta($product_id, '_is_b2b_sample_product', true);
        
        if ($is_sample === 'yes' && Helper::is_b2b_accepted_user()) {
            // Return empty string to hide remove link
            return '';
        }
        
        return $link;
    }

    /**
     * Prevent users from changing quantity of sample products
     */
    public function prevent_sample_quantity_change($product_quantity, $cart_item_key, $cart_item)
    {
        $product_id = $cart_item['product_id'];
        $is_sample = get_post_meta($product_id, '_is_b2b_sample_product', true);
        
        if ($is_sample === 'yes' && Helper::is_b2b_accepted_user()) {
            // Return non-editable quantity display
            return '<span class="quantity">1</span>';
        }
        
        return $product_quantity;
    }
}
