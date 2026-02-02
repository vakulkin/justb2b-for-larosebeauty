<?php

namespace JustB2B;

/**
 * GUS API Handler Class
 * Handles GUS API integration for NIP lookup
 */
class GUS_API_Handler {
	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Enqueue JavaScript for GUS lookup
	 */
	public function enqueue_scripts() {
		// Only enqueue on registration/account pages
		if ( is_page() || is_account_page() ) {
			wp_enqueue_script(
				'justb2b-gus-lookup',
				JUSTB2B_PLUGIN_URL . 'assets/js/gus-lookup.js',
				[ 'jquery' ],
				JUSTB2B_VERSION,
				true
			);

			wp_localize_script(
				'justb2b-gus-lookup',
				'justb2bGUS',
				[
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'justb2b_gus_nonce' ),
				]
			);
		}
	}
}
