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
        add_action('acf/init', [$this, 'register_product_fields']);
        add_action('acf/init', [$this, 'register_user_fields']);
        add_filter('acf/prepare_field/name=justb2b_price', [$this, 'hide_field_for_non_simple']);
    }
    
    /**
     * Register ACF field group for Products
     */
    public function register_product_fields()
    {
        if (function_exists('acf_add_local_field_group')) {
            acf_add_local_field_group(array(
                'key' => 'group_justb2b_product',
                'title' => 'B2B Price',
                'fields' => array(
                    array(
                        'key' => 'field_justb2b_price',
                        'label' => 'B2B Price (Netto)',
                        'name' => 'justb2b_price',
                        'type' => 'number',
                        'instructions' => 'Enter the B2B net price for this product',
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
                        'min' => 0,
                        'max' => '',
                        'step' => 0.01,
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
    }
    
    /**
     * Register ACF field group for Users
     */
    public function register_user_fields()
    {
        if (function_exists('acf_add_local_field_group')) {
            acf_add_local_field_group(array(
                'key' => 'group_justb2b_user',
                'title' => 'B2B/B2C Role',
                'fields' => array(
                    array(
                        'key' => 'field_justb2b_role',
                        'label' => 'Customer Type',
                        'name' => 'justb2b_role',
                        'type' => 'select',
                        'instructions' => 'Select whether this user is a B2C or B2B customer',
                        'required' => 0,
                        'conditional_logic' => 0,
                        'wrapper' => array(
                            'width' => '',
                            'class' => '',
                            'id' => '',
                        ),
                        'choices' => array(
                            'b2c' => 'B2C (Business to Consumer)',
                            'b2b' => 'B2B (Business to Business)',
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
