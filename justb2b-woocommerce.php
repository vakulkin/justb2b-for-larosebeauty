<?php

/**
 * Plugin Name: JustB2B for larosebeauty
 * Description: Simple B2B extension for WooCommerce with ACF integration
 * Text Domain: justb2b-larose
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
        add_action('plugins_loaded', [$this, 'init_acf_fields'], 5);
        add_action('plugins_loaded', [$this, 'init'], 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    public function declare_hpos_compatibility()
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Register ACF fields early — only needs ACF, not WooCommerce or UsersWP.
     * This ensures field groups appear on product/user edit pages regardless
     * of whether other dependencies are active.
     */
    public function init_acf_fields()
    {
        if (function_exists('acf_add_local_field_group')) {
            \JustB2B\ACF_Fields::instance();
        }
    }

    public function init()
    {
        if (!$this->check_dependencies()) {
            return;
        }

        // Load plugin text domain
        load_plugin_textdomain('justb2b-larose', false, dirname(plugin_basename(__FILE__)) . '/languages/');

        // Mark justb2b cache group as non-persistent (only for current page load)
        wp_cache_add_non_persistent_groups('justb2b');

        // Initialize modules (ACF_Fields already initialized in init_acf_fields)
        \JustB2B\Price_Display::instance();
        \JustB2B\Cart_Handler::instance();
        \JustB2B\Billing_Handler::instance();
        \JustB2B\Product_Visibility::instance();
        \JustB2B\Registration_Handler::instance();
        \JustB2B\Menu_Handler::instance();
        \JustB2B\WCProductTableLitePro::instance();
        \JustB2B\B2B_Button_Shortcode::instance();
        \JustB2B\GUS_API_Handler::instance();
        \JustB2B\ADP_Disabler::instance();
        \JustB2B\Cart_Popup_Cross_Sell::instance();
    }

    public function enqueue_styles()
    {
        wp_enqueue_style(
            'justb2b-styles',
            JUSTB2B_PLUGIN_URL . 'assets/style.css',
            array(),
            JUSTB2B_VERSION
        );

        wp_enqueue_style(
            'justb2b-auth-forms',
            JUSTB2B_PLUGIN_URL . 'assets/auth-forms.css',
            array(),
            JUSTB2B_VERSION
        );
    }

    private function check_dependencies()
    {
        $missing = [];

        if (!class_exists('WooCommerce')) {
            $missing[] = 'WooCommerce';
        }

        if (!function_exists('acf_add_local_field_group')) {
            $missing[] = 'Advanced Custom Fields (ACF)';
        }

        if (!function_exists('uwp_get_option')) {
            $missing[] = 'UsersWP';
        }

        if (!empty($missing)) {
            $missing_list = implode(', ', $missing);
            error_log('[JustB2B] Missing dependencies: ' . $missing_list);
            add_action('admin_notices', function () use ($missing_list) {
                echo '<div class="error"><p>' .
                    sprintf(
                        __('JustB2B WooCommerce Extension requires the following plugins to be installed and active: %s', 'justb2b-larose'),
                        '<strong>' . esc_html($missing_list) . '</strong>'
                    ) .
                    '</p></div>';
            });
            return false;
        }

        return true;
    }
}

// Initialize the plugin
JustB2B_WooCommerce::instance();
