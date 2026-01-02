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
		add_action( 'updated_user_meta', [ $this, 'notify_user_on_approval' ], 10, 4 );
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
		update_user_meta( $user_id, 'justb2b_role', 'b2b_pending' );
	}

	/**
	 * Send email notification to administrator about new B2B request
	 */
	public function send_admin_notification( $user_id ) {
		$user = get_userdata( $user_id );
		$admin_email = get_option( 'admin_email' );
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
	public function notify_user_on_approval( $meta_id, $user_id, $meta_key, $meta_value ) {
		if ( $meta_key === 'justb2b_role' && $meta_value === 'b2b_accepted' ) {
			// Check if the value actually changed
			$old_value = get_user_meta( $user_id, 'justb2b_role', true );
			if ( $old_value !== 'b2b_accepted' ) {
				$this->send_user_approval_notification( $user_id );
			}
		}
	}

	/**
	 * Send approval notification email to user
	 */
	public function send_user_approval_notification( $user_id ) {
		$user = get_userdata( $user_id );

		// Get forgot password page URL from UsersWP
		$password_reset_link = wp_lostpassword_url(); // fallback
		$forgot_page_data = uwp_get_page_url_data( 'forgot_page', 'array' );
		if ( ! empty( $forgot_page_data ) && isset( $forgot_page_data['url'] ) ) {
			$password_reset_link = $forgot_page_data['url'];
		}

		$subject = __( 'Your B2B Account Has Been Approved', 'justb2b-larose' );
		$message = sprintf(
			__( 'Congratulations %s!' . "\n\n" . 'Your B2B account request has been approved. You now have access to B2B pricing and features.' . "\n\n" . 'Your login details:' . "\n" . 'Username: %s' . "\n" . 'If you need to reset your password, please visit: %s' . "\n\n" . 'Thank you for your business!', 'justb2b-larose' ),
			$user->display_name,
			$user->user_login,
			$password_reset_link
		);

		wp_mail( $user->user_email, $subject, $message );
	}
}