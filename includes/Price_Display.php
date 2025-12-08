<?php
namespace JustB2B;

/**
 * Price Display Class
 */
class Price_Display
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
        add_action('woocommerce_single_product_summary', [$this, 'display_b2b_price'], 15);
    }
    
    /**
     * Display B2B price information on product page
     */
    public function display_b2b_price()
    {
        global $product;

        // Only for simple products
        if (!$product || $product->get_type() !== 'simple') {
            return;
        }

        // Check if user is B2B
        if (!Helper::is_b2b_user()) {
            return;
        }

        // Get B2B price
        $b2b_netto = Helper::get_product_b2b_price($product->get_id());

        if (!$b2b_netto) {
            return;
        }

        // Get tax rate from product
        $tax_rate = Helper::get_product_tax_rate($product);

        // Calculate brutto
        $b2b_brutto = Helper::calculate_brutto($b2b_netto, $tax_rate);

        // Display B2B prices
        echo '<div class="justb2b-price-info" style="margin: 15px 0; padding: 15px; background: #f7f7f7; border-left: 4px solid #2c3e50;">';
        echo '<h4 style="margin: 0 0 10px 0; color: #2c3e50;">' . __('B2B Pricing', 'justb2b-woocommerce') . '</h4>';

        echo '<div class="b2b-price-row" style="margin-bottom: 8px;">';
        echo '<strong>' . __('B2B Price (Netto):', 'justb2b-woocommerce') . '</strong> ';
        echo '<span class="woocommerce-Price-amount amount">';
        echo wc_price($b2b_netto);
        echo '</span>';
        echo '</div>';

        echo '<div class="b2b-price-row">';
        echo '<strong>' . __('B2B Price (Brutto):', 'justb2b-woocommerce') . '</strong> ';
        echo '<span class="woocommerce-Price-amount amount">';
        echo wc_price($b2b_brutto);
        echo '</span>';

        if ($tax_rate > 0) {
            echo ' <small style="color: #666;">(' . __('incl.', 'justb2b-woocommerce') . ' ' . number_format($tax_rate * 100, 2) . '% ' . __('VAT', 'justb2b-woocommerce') . ')</small>';
        }

        echo '</div>';
        echo '</div>';
    }
}
