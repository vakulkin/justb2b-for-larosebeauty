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
        add_action('woocommerce_before_calculate_totals', [$this, 'override_cart_price'], 10, 1);
    }
    
    /**
     * Override cart item price for B2B users
     */
    public function override_cart_price($cart)
    {
        // Skip if not B2B user
        if (!Helper::is_b2b_user()) {
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
}
