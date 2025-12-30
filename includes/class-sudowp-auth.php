<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SudoWP_Auth {

	public function __construct() {
		// Listen for the sudo link parameter
		add_action( 'init', array( $this, 'handle_sudo_login' ) );
	}

	/**
	 * Helper: Find existing user or create a temporary one.
	 */
	public static function get_or_create_user( $username, $email = '', $role = 'administrator', $expiry_seconds = 86400 ) {
		$new_user_created = false;

		// 1. Try to find existing user by Username or Email
		$user = get_user_by( 'login', $username );
		
		if ( ! $user && ! empty( $email ) ) {
			$user = get_user_by( 'email', $email );
		}

		// 2. If not found, CREATE NEW USER
		if ( ! $user ) {
			if ( empty( $username ) || empty( $email ) ) {
				return new WP_Error( 'missing_data', 'To create a new Sudo user, both Username and Email are required.' );
			}

			if ( username_exists( $username ) ) {
				$username = $username . '_' . time();
			}

			$password = wp_generate_password( 32, true );
			$user_id = wp_create_user( $username, $password, $email );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$user = get_user_by( 'id', $user_id );
			$user->set_role( $role );
			
			// Mark as temporary
			update_user_meta( $user_id, '_sudowp_is_temporary', true );

			// Schedule Auto-Deletion
			wp_schedule_single_event( time() + $expiry_seconds, 'sudowp_scheduled_delete_user', array( $user_id ) );
			
			$new_user_created = true;
		}

		return array( 'user' => $user, 'created_new' => $new_user_created );
	}

	/**
	 * Generate a Sudo Token and Store in Meta for retrieval
	 */
	public static function generate_token( $user_id, $expiry_seconds, $restrict_ip = '' ) {
		$token = bin2hex( random_bytes( 32 ) );
		
		$data = array(
			'user_id'     => $user_id,
			'restrict_ip' => $restrict_ip,
		);

		// Store in Transient for expiry handling
		set_transient( 'sudowp_' . $token, $data, $expiry_seconds );

		// NEW: Store in User Meta to retrieve it later (for UI/CLI listing)
		update_user_meta( $user_id, '_sudowp_active_token', $token );

		return $token;
	}

	/**
	 * Retrieve the active link for a user (if valid)
	 */
	public static function get_active_link( $user_id ) {
		$token = get_user_meta( $user_id, '_sudowp_active_token', true );
		
		if ( ! $token ) {
			return false;
		}

		// Verify if the transient still exists (hasn't expired)
		if ( ! get_transient( 'sudowp_' . $token ) ) {
			delete_user_meta( $user_id, '_sudowp_active_token' ); // Cleanup
			return false;
		}

		return add_query_arg( 'sudowp_token', $token, site_url() );
	}

	/**
	 * Send Email Notification
	 */
	public static function send_access_email( $user, $link, $hours ) {
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[%s] Your Sudo Access Link', $site_name );
		
		$message  = "Hello,\n\n";
		$message .= "A temporary administrative access link has been generated for you on {$site_name}.\n\n";
		$message .= "Click the link below to login (no password required):\n";
		$message .= $link . "\n\n";
		$message .= "This link will expire in {$hours} hours.\n";
		$message .= "Security Note: Do not share this link with anyone.";

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		wp_mail( $user->user_email, $subject, $message, $headers );
	}

	/**
	 * Handle the Login Request
	 */
	public function handle_sudo_login() {
		if ( ! isset( $_GET['sudowp_token'] ) ) {
			return;
		}

		$token = sanitize_text_field( $_GET['sudowp_token'] );
		$data  = get_transient( 'sudowp_' . $token );

		if ( ! $data ) {
			wp_die( 'SudoWP: This Sudo Link has expired or is invalid.', 'Access Denied', array( 'response' => 403 ) );
		}

		if ( ! empty( $data['restrict_ip'] ) ) {
			$current_ip = $_SERVER['REMOTE_ADDR'];
			if ( $current_ip !== $data['restrict_ip'] ) {
				SudoWP_Logger::log( $data['user_id'], 'failed_login_ip_mismatch', "Expected: {$data['restrict_ip']}, Got: $current_ip" );
				wp_die( 'SudoWP: IP Address mismatch.', 'Access Denied', array( 'response' => 403 ) );
			}
		}

		$user_id = $data['user_id'];
		wp_set_auth_cookie( $user_id );
		
		SudoWP_Logger::log( $user_id, 'sudo_login_success', 'Logged in via Sudo Link.' );

		wp_safe_redirect( admin_url() );
		exit;
	}
}