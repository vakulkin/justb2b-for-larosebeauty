<?php

/**
 * Plugin Name: JustB2B for larosebeauty
 * Description: Simple B2B extension for WooCommerce with ACF integration
 * Version:     1.0.0
 * Text Domain: justb2b-larose
 * Requires Plugins: woocommerce, advanced-custom-fields, userswp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JUSTB2B_VERSION', '1.0.0' );
define( 'JUSTB2B_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JUSTB2B_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4 style autoloader for the JustB2B namespace.
 */
spl_autoload_register( function ( $class ) {
    $prefix   = 'JustB2B\\';
    $base_dir = JUSTB2B_PLUGIN_DIR . 'includes/';

    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }

    $file = $base_dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

/**
 * Main plugin bootstrap.
 */
final class JustB2B_WooCommerce {

    /** @var self|null */
    private static $instance = null;

    /**
     * Required plugins: function/class to check => human-readable name.
     */
    private const DEPENDENCIES = [
        'class:WooCommerce'            => 'WooCommerce',
        'func:acf_add_local_field_group' => 'Advanced Custom Fields (ACF)',
        'func:uwp_get_option'          => 'UsersWP',
    ];

    /**
     * Modules loaded after all dependencies are confirmed.
     */
    private const MODULES = [
        \JustB2B\Price_Display::class,
        \JustB2B\Cart_Handler::class,
        \JustB2B\Billing_Handler::class,
        \JustB2B\Product_Visibility::class,
        \JustB2B\Registration_Handler::class,
        \JustB2B\Menu_Handler::class,
        \JustB2B\WCProductTableLitePro::class,
        \JustB2B\B2B_Button_Shortcode::class,
        \JustB2B\GUS_API_Handler::class,
        \JustB2B\ADP_Disabler::class,
        \JustB2B\Cart_Popup_Cross_Sell::class,
    ];

    /* ------------------------------------------------------------------
     * Singleton
     * ----------------------------------------------------------------*/

    public static function instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );
        add_action( 'plugins_loaded', [ $this, 'register_acf_fields' ], 5 );
        add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    /* ------------------------------------------------------------------
     * WooCommerce HPOS compatibility
     * ----------------------------------------------------------------*/

    public function declare_hpos_compatibility(): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }

    /* ------------------------------------------------------------------
     * ACF fields — registered early, only requires ACF
     * ----------------------------------------------------------------*/

    public function register_acf_fields(): void {
        if ( function_exists( 'acf_add_local_field_group' ) ) {
            \JustB2B\ACF_Fields::instance();
        }
    }

    /* ------------------------------------------------------------------
     * Full initialisation — requires all dependencies
     * ----------------------------------------------------------------*/

    public function init(): void {
        $missing = $this->get_missing_dependencies();

        if ( ! empty( $missing ) ) {
            $this->show_missing_notice( $missing );
            return;
        }

        load_plugin_textdomain( 'justb2b-larose', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
        wp_cache_add_non_persistent_groups( 'justb2b' );

        foreach ( self::MODULES as $module ) {
            $module::instance();
        }
    }

    /* ------------------------------------------------------------------
     * Front-end styles
     * ----------------------------------------------------------------*/

    public function enqueue_styles(): void {
        wp_enqueue_style( 'justb2b-styles', JUSTB2B_PLUGIN_URL . 'assets/style.css', [], JUSTB2B_VERSION );
        wp_enqueue_style( 'justb2b-auth-forms', JUSTB2B_PLUGIN_URL . 'assets/auth-forms.css', [], JUSTB2B_VERSION );
    }

    /* ------------------------------------------------------------------
     * Dependency helpers
     * ----------------------------------------------------------------*/

    /**
     * @return string[] Human-readable names of missing dependencies.
     */
    private function get_missing_dependencies(): array {
        $missing = [];

        foreach ( self::DEPENDENCIES as $check => $label ) {
            [ $type, $symbol ] = explode( ':', $check, 2 );

            $exists = match ( $type ) {
                'class' => class_exists( $symbol ),
                'func'  => function_exists( $symbol ),
                default => false,
            };

            if ( ! $exists ) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * Log missing dependencies and display an admin notice.
     *
     * @param string[] $missing
     */
    private function show_missing_notice( array $missing ): void {
        $list = implode( ', ', $missing );
        error_log( '[JustB2B] Missing dependencies: ' . $list );

        add_action( 'admin_notices', function () use ( $list ) {
            printf(
                '<div class="error"><p>%s</p></div>',
                sprintf(
                    /* translators: %s: comma-separated list of plugin names */
                    esc_html__( 'JustB2B WooCommerce Extension requires the following plugins to be installed and active: %s', 'justb2b-larose' ),
                    '<strong>' . esc_html( $list ) . '</strong>'
                )
            );
        } );
    }
}

JustB2B_WooCommerce::instance();
