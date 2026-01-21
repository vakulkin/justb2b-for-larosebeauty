<?php
namespace JustB2B;

/**
 * Menu Handler Class - Changes menu for B2B users
 */
class Menu_Handler
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
        add_filter('wp_nav_menu_args', [$this, 'switch_menu_for_b2b_users'], 10, 1);
    }

    /**
     * Switch menu location for B2B accepted users
     */
    public function switch_menu_for_b2b_users($args)
    {
        // Only modify if user is B2B accepted and menu location is 'main-menu'
        if (Helper::is_b2b_accepted_user() && isset($args['theme_location']) && $args['theme_location'] === 'main-menu') {
            var_dump($args);
            $args['menu'] = 'b2b-main-menu';
        }

        return $args;
    }
}