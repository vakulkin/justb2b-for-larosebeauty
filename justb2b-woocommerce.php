<?php

/**
 * Plugin Name: JustB2B for larosebeauty
 * Description: Simple B2B extension for WooCommerce with ACF integration
 * Text Domain: justb2b-woocommerce
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('JUSTB2B_VERSION', '1.0.0');
define('JUSTB2B_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JUSTB2B_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'JustB2B\\';
    $base_dir = JUSTB2B_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Main Plugin Class
 */
final class JustB2B_WooCommerce
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
        $this->init_hooks();
    }
    
    private function init_hooks()
    {
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        add_action('plugins_loaded', [$this, 'init']);
    }
    
    public function declare_hpos_compatibility()
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    public function init()
    {
        if (!$this->check_dependencies()) {
            return;
        }
        
        // Mark justb2b cache group as non-persistent (only for current page load)
        wp_cache_add_non_persistent_groups('justb2b');
        
        // Initialize modules
        \JustB2B\ACF_Fields::instance();
        \JustB2B\Price_Display::instance();
        \JustB2B\Cart_Handler::instance();
    }
    
    private function check_dependencies()
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>' . __('JustB2B WooCommerce Extension requires WooCommerce to be installed and active.', 'justb2b-woocommerce') . '</p></div>';
            });
            return false;
        }
        
        if (!function_exists('acf_add_local_field_group')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>' . __('JustB2B WooCommerce Extension requires Advanced Custom Fields (ACF) to be installed and active.', 'justb2b-woocommerce') . '</p></div>';
            });
            return false;
        }
        
        return true;
    }
}

// Initialize the plugin
JustB2B_WooCommerce::instance();
