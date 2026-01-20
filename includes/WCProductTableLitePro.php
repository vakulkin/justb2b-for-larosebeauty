<?php

namespace JustB2B;

/**
 * WC Product Table Lite Pro Integration
 * Handles cart total price display for B2B users in WC Product Table
 */
class WCProductTableLitePro
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
        add_filter('wcpt_cart_total_price', [$this, 'overrideCartTotalPrice'], 20);
    }

    /**
     * Override cart total price to show net price for B2B users
     */
    public function overrideCartTotalPrice(): string
    {
        $cart = WC()->cart;

        if (!$cart || $cart->is_empty()) {
            return wc_price(0);
        }

        $total = 0;

        foreach ($cart->get_cart() as $cart_item) {
            if (Helper::is_b2b_accepted_user()) {
                // B2B users: show net price (without tax)
                $total += $cart_item['line_subtotal'];
            } else {
                // Regular users: show gross price (with tax)
                $total += $cart_item['line_subtotal'] + $cart_item['line_subtotal_tax'];
            }
        }

        // Add " netto" suffix for B2B users
        return wc_price($total) . (Helper::is_b2b_accepted_user() ? ' netto' : '');
    }
}
