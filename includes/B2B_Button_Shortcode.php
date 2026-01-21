<?php

namespace JustB2B;

class B2B_Button_Shortcode
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
        add_shortcode('justb2b_button', [$this, 'render_button']);
    }

    /**
     * Render B2B button shortcode
     *
     * @return string Button HTML
     */
    public function render_button($atts)
    {
        // Parse attributes (no custom attributes needed)
        $atts = shortcode_atts(array(), $atts, 'b2b_button');

        $is_logged_in = is_user_logged_in();
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $is_b2b_products_page = strpos($current_url, '/b2b-products/') !== false;

        // Determine button text and URL
        if ($is_logged_in && $is_b2b_products_page) {
            // Logged in and on B2B products page -> "Konto B2B" -> My Account
            $button_text = 'Konto B2B';
            $button_url = wc_get_page_permalink('myaccount');
        } elseif ($is_logged_in) {
            // Logged in but not on B2B products page -> "Strefa B2B" -> /b2b-products/
            $button_text = 'Strefa B2B';
            $button_url = home_url('/b2b-products/');
        } else {
            // Not logged in -> "Strefa B2B" -> /register
            $button_text = 'Strefa B2B';
            $button_url = home_url('/register/');
        }

        // Build button classes
        $classes = 'btn btn btn-color-primary btn-style-default btn-shape-rectangle btn-size-default';

        // Build button HTML
        $html = sprintf(
            '<a href="%s" title="%s" class="%s">%s</a>',
            esc_url($button_url),
            esc_attr($button_text),
            esc_attr($classes),
            esc_html($button_text)
        );

        return $html;
    }
}
