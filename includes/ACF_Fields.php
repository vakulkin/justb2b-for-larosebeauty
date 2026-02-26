<?php

namespace JustB2B;

/**
 * ACF Fields Configuration Class
 */
class ACF_Fields
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
        add_action('acf/init', [ $this, 'register_product_fields' ]);
        add_action('acf/init', [ $this, 'register_user_fields' ]);
        add_filter('acf/prepare_field/name=justb2b_price', [ $this, 'hide_field_for_non_simple' ]);
    }

    /**
     * Register ACF field group for Products
     */
    public function register_product_fields()
    {
        acf_add_local_field_group(array(
            'key' => 'group_justb2b_product',
            'title' => __('B2B Price', 'justb2b-larose'),
            'fields' => array(
                array(
                    'key' => 'field_justb2b_price',
                    'label' => __('B2B Price (Net)', 'justb2b-larose'),
                    'name' => 'justb2b_price',
                    'type' => 'number',
                    'instructions' => __('Enter the B2B net price for this product', 'justb2b-larose'),
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'default_value' => '',
                    'placeholder' => '0.00',
                    'prepend' => get_woocommerce_currency_symbol(),
                    'append' => '',
                    'min' => 0.01,
                    'max' => '',
                    'step' => 0.01,
                ),
                array(
                    'key' => 'field_justb2b_only_visible',
                    'label' => __('B2B Only Visible', 'justb2b-larose'),
                    'name' => 'justb2b_only_visible',
                    'type' => 'select',
                    'instructions' => __('Select whether this product should be visible only to B2B customers', 'justb2b-larose'),
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'choices' => array(
                        'no' => __('No (Visible to all customers)', 'justb2b-larose'),
                        'yes' => __('Yes (B2B customers only)', 'justb2b-larose'),
                    ),
                    'default_value' => 'no',
                    'allow_null' => 0,
                    'multiple' => 0,
                    'ui' => 1,
                    'ajax' => 0,
                    'return_format' => 'value',
                    'placeholder' => '',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'product',
                    ),
                ),
            ),
            'menu_order' => 10,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
        ));
    }

    /**
     * Register ACF field group for Users
     */
    public function register_user_fields()
    {
        acf_add_local_field_group(array(
            'key' => 'group_justb2b_user',
            'title' => __('B2B/B2C Role', 'justb2b-larose'),
            'fields' => array(
                array(
                    'key' => 'field_justb2b_role',
                    'label' => __('Customer Type', 'justb2b-larose'),
                    'name' => 'justb2b_role',
                    'type' => 'select',
                    'instructions' => __('Select whether this user is a B2C or B2B customer', 'justb2b-larose'),
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'choices' => array(
                        'b2c' => __('B2C', 'justb2b-larose'),
                        'b2b_pending' => __('B2B Pending', 'justb2b-larose'),
                        'b2b_accepted' => __('B2B Accepted', 'justb2b-larose'),
                    ),
                    'default_value' => 'b2c',
                    'allow_null' => 0,
                    'multiple' => 0,
                    'ui' => 1,
                    'ajax' => 0,
                    'return_format' => 'value',
                    'placeholder' => '',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'user_form',
                        'operator' => '==',
                        'value' => 'all',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
        ));
    }

    /**
     * Hide B2B price field for non-simple products
     */
    public function hide_field_for_non_simple($field)
    {
        global $post;

        if ($post && $post->post_type === 'product') {
            $product = wc_get_product($post->ID);
            if ($product && $product->get_type() !== 'simple') {
                return false; // Hide field
            }
        }

        return $field;
    }
}
