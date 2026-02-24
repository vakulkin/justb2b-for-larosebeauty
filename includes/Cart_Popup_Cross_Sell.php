<?php

namespace JustB2B;

/**
 * Cart Popup Cross-Sell Class
 *
 * Extends the Woodmart "added to cart" popup with 3 random products
 * from the same category as the product just added.
 * Works without modifying any Woodmart theme files.
 */
class Cart_Popup_Cross_Sell
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
        // Inject cross-sell HTML as a WooCommerce fragment
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'add_cross_sell_fragment'], 50);

        // Enqueue frontend JS & CSS
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add a fragment with cross-sell products HTML to the AJAX response.
     * Woodmart's JS replaces DOM elements matching the fragment selectors,
     * but our JS will also read this fragment to inject into the popup.
     */
    public function add_cross_sell_fragment($fragments)
    {
        $html = $this->get_cross_sell_html();

        if (!empty($html)) {
            $fragments['.justb2b-popup-cross-sell'] = $html;
        }

        return $fragments;
    }

    /**
     * Build cross-sell products HTML based on the last product added to cart.
     */
    private function get_cross_sell_html()
    {
        if (!WC()->cart) {
            return '';
        }

        // Get the last product added to cart
        $cart_contents = WC()->cart->get_cart();
        if (empty($cart_contents)) {
            return '';
        }

        // Get the most recently added item (last in array)
        $last_item = end($cart_contents);
        $product_id = $last_item['product_id'];
        $product = wc_get_product($product_id);

        if (!$product) {
            return '';
        }

        // Get product categories
        $category_ids = $product->get_category_ids();
        if (empty($category_ids)) {
            return '';
        }

        // Query 3 random products from the same categories, excluding the current product
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 3,
            'orderby'        => 'rand',
            'post__not_in'   => [$product_id],
            'tax_query'      => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_ids,
                ],
            ],
            'meta_query'     => [
                [
                    'key'     => '_stock_status',
                    'value'   => 'instock',
                    'compare' => '=',
                ],
            ],
        ];

        // Also exclude all products already in the cart
        $exclude_ids = [$product_id];
        foreach ($cart_contents as $cart_item) {
            $exclude_ids[] = $cart_item['product_id'];
        }
        $args['post__not_in'] = array_unique($exclude_ids);

        $query = new \WP_Query($args);

        if (!$query->have_posts()) {
            wp_reset_postdata();
            return '';
        }

        ob_start();
        ?>
        <div class="justb2b-popup-cross-sell">
            <h4 class="justb2b-cross-sell-title"><?php esc_html_e('You may also like', 'justb2b-larose'); ?></h4>
            <div class="justb2b-cross-sell-products">
                <?php while ($query->have_posts()) : $query->the_post();
                    $cross_product = wc_get_product(get_the_ID());
                    if (!$cross_product) continue;

                    $image = $cross_product->get_image('woocommerce_thumbnail');
                    $title = $cross_product->get_name();
                    $price = $cross_product->get_price_html();
                    $permalink = $cross_product->get_permalink();
                ?>
                <a href="<?php echo esc_url($permalink); ?>" class="justb2b-cross-sell-item">
                    <span class="justb2b-cross-sell-image">
                        <?php echo $image; ?>
                    </span>
                    <span class="justb2b-cross-sell-name"><?php echo esc_html($title); ?></span>
                    <span class="justb2b-cross-sell-price"><?php echo $price; ?></span>
                </a>
                <?php endwhile; ?>
            </div>
        </div>
        <?php
        wp_reset_postdata();

        return ob_get_clean();
    }

    /**
     * Enqueue frontend assets.
     */
    public function enqueue_assets()
    {
        wp_enqueue_script(
            'justb2b-cart-popup-cross-sell',
            JUSTB2B_PLUGIN_URL . 'assets/js/cart-popup-cross-sell.js',
            ['jquery'],
            JUSTB2B_VERSION,
            true
        );
    }
}
