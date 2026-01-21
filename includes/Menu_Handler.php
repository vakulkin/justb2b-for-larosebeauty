<?php
namespace JustB2B;

/**
 * Menu Handler Class - Changes menu for B2B users
 */
class Menu_Handler {
	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wp_nav_menu_args', [ $this, 'switch_menu_for_b2b_users' ], 10, 1 );
	}

	/**
	 * Switch menu location for B2B accepted users
	 */
	public function switch_menu_for_b2b_users( $args ) {
		// Only modify if user is B2B accepted
		if ( ! Helper::is_b2b_accepted_user() ) {
			return $args;
		}

		// Check if this is the main menu (by menu slug, ID, or name)
		if ( isset( $args['menu'] ) ) {
			$menu = $args['menu'];
			
			// Check if it's the main menu by slug or ID
			if ( $menu === 'menu-glowne' || $menu === 'menu-2' || $menu === 'Menu główne' ) {
				$args['menu'] = 'b2b-main-menu';
			}
			
			// // Check if menu is an object with the name or slug
			// if ( is_object( $menu ) && ( $menu->slug === 'menu-glowne' || $menu->name === 'Menu główne' ) ) {
			// 	$args['menu'] = 'b2b-main-menu';
			// }
		}

		return $args;
	}
}