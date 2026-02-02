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
     * Get or create the sample product
     */
    private function get_or_create_sample_product()
    {
        // Check if product already exists
        $existing_products = get_posts([
            'post_type' => 'product',
            'title' => 'Mix próbek',
            'post_status' => 'any',
            'numberposts' => 1,
        ]);

        if (!empty($existing_products)) {
            return $existing_products[0]->ID;
        }

        // Create new product
        $product = new \WC_Product_Simple();
        $product->set_name('Mix próbek');
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_regular_price(0.01);
        $product->set_virtual(true);
        $product->set_sold_individually(true);
        $product_id = $product->save();

        // Add meta to identify this as the sample product
        update_post_meta($product_id, '_is_b2b_sample_product', 'yes');

        return $product_id;
    }

    /**
     * Add sample product to cart for B2B users based on order total
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

        $product_id = $this->get_or_create_sample_product();

        // Calculate cart subtotal including taxes (excluding the sample product)
        $cart_subtotal = 0;
        foreach ($cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] != $product_id) {
                $cart_subtotal += $cart_item['line_subtotal'] + $cart_item['line_subtotal_tax'];
            }
        }
        $cart_subtotal = round($cart_subtotal, 2);

        // Determine sample quantity and title based on cart total
        $sample_info = null;
        if ($cart_subtotal >= 5000) {
            $sample_info = ['quantity' => 20, 'title' => 'Przy zamówieniu na 5000 zł: 20 próbek'];
        } elseif ($cart_subtotal >= 3000) {
            $sample_info = ['quantity' => 15, 'title' => 'Przy zamówieniu na 3000 zł: 15 próbek'];
        } elseif ($cart_subtotal >= 2000) {
            $sample_info = ['quantity' => 10, 'title' => 'Przy zamówieniu na 2000 zł: 10 próbek'];
        } elseif ($cart_subtotal >= 1000) {
            $sample_info = ['quantity' => 5, 'title' => 'Przy zamówieniu na 1000 zł: 5 próbek'];
        }

        // Check if sample product is already in cart
        $sample_cart_key = null;
        foreach ($cart->get_cart() as $cart_key => $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                $sample_cart_key = $cart_key;
                break;
            }
        }

        // Remove sample product if cart total is below 1000
        if ($sample_info === null) {
            if ($sample_cart_key) {
                $cart->remove_cart_item($sample_cart_key);
            }
            return;
        }

        // Add or update sample product in cart
        if ($sample_cart_key) {
            // Update existing item
            $cart->cart_contents[$sample_cart_key]['quantity'] = $sample_info['quantity'];
            $cart->cart_contents[$sample_cart_key]['b2b_sample_title'] = $sample_info['title'];
        } else {
            // Add new item
            $cart->add_to_cart($product_id, $sample_info['quantity'], 0, [], [
                'b2b_sample_title' => $sample_info['title']
            ]);
        }
    }
}
