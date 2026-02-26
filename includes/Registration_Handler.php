<?php

namespace JustB2B;

/**
 * Handles B2B registration and notification functionality
 */
class Registration_Handler {
	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	public function init_hooks() {
		add_filter( 'uwp_before_extra_fields_save', [ $this, 'handle_b2b_registration' ], 10, 3 );
		add_action( 'update_user_meta', [ $this, 'notify_user_on_approval' ], 10, 4 );
		add_filter( 'uwp_users_search_where', [ $this, 'exclude_all_users_from_uwp_list' ], 10, 2 );
		add_filter( 'uwp_account_available_tabs', [ $this, 'modify_account_tabs_for_b2b' ], 10, 1 );

		add_action( 'template_redirect', [ $this, 'redirect_wc_login_to_userswp' ] );

		// add_action( 'uwp_template_form_title_before', [ $this, 'start_buffering_account_sidebar' ], 10, 1 );
		// add_action( 'uwp_template_form_title_after', [ $this, 'clear_buffering_account_sidebar' ], 10, 1 );
		// add_filter( 'do_shortcode_tag', [ $this, 'filter_uwp_avatar_shortcode' ], 10, 4 );
	}

	/**
	 * Handle B2B registration process
	 */
	public function handle_b2b_registration( $result, $type, $user_id ) {
		if ( $type === 'register' ) {
			$form_id = isset( $_POST['uwp_register_form_id'] ) ? intval( $_POST['uwp_register_form_id'] ) : 0;
			if ( $form_id === 1 ) {
				$this->set_pending_role( $user_id );
				$this->send_admin_notification( $user_id );
			}
		}
		return $result;
	}

	/**
	 * Set B2B role to pending for new registrations
	 */
	public function set_pending_role( $user_id ) {
		update_field( 'field_justb2b_role', 'b2b_pending', 'user_' . $user_id );
	}

	/**
	 * Send email notification to administrator about new B2B request
	 */
	public function send_admin_notification( $user_id ) {
		$user = get_userdata( $user_id );
		// TODO: Uncomment it
		$admin_email = get_option( 'admin_email' );
		// $admin_email = 'studio@thenewlook.pl';
		$user_edit_link = admin_url( 'user-edit.php?user_id=' . $user_id );

		$subject = __( 'New B2B Account Request', 'justb2b-larose' );
		$message = sprintf(
			__( 'A new B2B account request has been submitted by %s (%s).' . "\n\n" . 'You can review and approve this request by visiting: %s', 'justb2b-larose' ),
			$user->display_name,
			$user->user_email,
			$user_edit_link
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Notify user when B2B role changes to accepted
	 */
	public function notify_user_on_approval( $meta_id, $user_id, $meta_key, $_meta_value ) {
		if ( $meta_key === 'justb2b_role' ) {
			// Get the old value before update
			$old_value = get_user_meta( $user_id, 'justb2b_role', true );
			// Get the new value from POST or other source
			$new_value = isset( $_POST['justb2b_role'] ) ? $_POST['justb2b_role'] : $_meta_value;

			if ( $old_value !== 'b2b_accepted' && $new_value === 'b2b_accepted' ) {
				$this->send_user_approval_notification( $user_id );
			}
		}
	}

	/**
	 * Send approval notification email to user
	 */
	public function send_user_approval_notification( $user_id ) {
		$user = get_userdata( $user_id );

		// Get login page URL from UsersWP
		$login_link = uwp_get_login_page_url();

		// Get forgot password page URL from UsersWP
		$password_reset_link = wp_lostpassword_url(); // fallback
		$forgot_page_data = uwp_get_page_url_data( 'forgot_page', 'array' );
		if ( ! empty( $forgot_page_data ) && isset( $forgot_page_data['url'] ) ) {
			$password_reset_link = $forgot_page_data['url'];
		}

		$subject = __( 'Your B2B Account Has Been Approved', 'justb2b-larose' );
		$message = sprintf(
			"Dziękujemy za zalogowanie się do panelu B2B La Rose Beauty.\n\nCieszymy się, że jesteś z nami 🤍\n\nW panelu B2B masz dostęp do pełnej oferty produktów, cen hurtowych, nowości oraz funkcji dedykowanych naszym partnerom biznesowym.\n\nTwoje dane logowania:\nNazwa użytkownika: %s\nZaloguj się tutaj: %s\nJeśli potrzebujesz zresetować hasło, odwiedź: %s\n\nW razie pytań lub potrzeby wsparcia – nasz zespół pozostaje do Twojej dyspozycji.\n\nŻyczymy udanych zakupów i owocnej współpracy.\n\nZespół La Rose Beauty",
			$user->user_login,
			$login_link,
			$password_reset_link
		);

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Exclude all users from UsersWP user listings for B2B users
	 */
	public function exclude_all_users_from_uwp_list( $where, $keyword ) {
		// Only apply exclusion for B2B users
		if ( Helper::is_b2b_accepted_user() ) {
			// Add a WHERE condition that always evaluates to false
			$where .= " AND 1=0";
		}
		return $where;
	}

	/**
	 * Remove Notifications and Privacy tabs for B2B users
	 */
	public function modify_account_tabs_for_b2b( $tabs ) {
		// Only modify tabs for B2B users
		if ( Helper::is_b2b_accepted_user() ) {
			// Remove notifications and privacy tabs
			unset( $tabs['notifications'] );
			unset( $tabs['privacy'] );
		}
		return $tabs;
	}

	/**
	 * Start output buffering for account sidebar content (B2B users)
	 */
	public function start_buffering_account_sidebar( $type ) {
		// Only buffer for account page and B2B users
		if ( $type === 'account' && Helper::is_b2b_accepted_user() ) {
			ob_start();
		}
	}

	/**
	 * Redirect WooCommerce my-account page to UsersWP login if not logged in
	 */
	public function redirect_wc_login_to_userswp() {
		if ( is_account_page() && ! is_user_logged_in() ) {
			wp_safe_redirect( uwp_get_register_page_url() );
			exit;
		}
	}
}
