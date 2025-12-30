<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class SudoWP_CLI_Command extends WP_CLI_Command {

	/**
	 * Create a temporary sudo link.
	 *
	 * ## OPTIONS
	 *
	 * <username>
	 * : The username.
	 *
	 * [--email=<email>]
	 * : The email (required if creating a new user).
	 *
	 * [--role=<role>]
	 * : The role for the new user. Default: administrator.
	 *
	 * [--expiry=<hours>]
	 * : Hours until link expires. Default: 24.
	 *
	 * [--ip=<ip_address>]
	 * : Restrict login to a specific IP.
	 *
	 * ## EXAMPLES
	 *
	 * wp sudo create support_user --email=support@agency.com
	 *
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ) {
		$username = $args[0];
		$email    = isset( $assoc_args['email'] ) ? $assoc_args['email'] : '';
		$role     = isset( $assoc_args['role'] ) ? $assoc_args['role'] : 'administrator';
		$hours    = isset( $assoc_args['expiry'] ) ? (int) $assoc_args['expiry'] : 24;
		$ip       = isset( $assoc_args['ip'] ) ? $assoc_args['ip'] : '';
		
		$seconds    = $hours * HOUR_IN_SECONDS;

		// Use the centralized Auth logic
		$result = SudoWP_Auth::get_or_create_user( $username, $email, $role, $seconds );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( "Error: " . $result->get_error_message() );
		}

		$user = $result['user'];
		$new_user_created = $result['created_new'];

		// Generate Token
		$token = SudoWP_Auth::generate_token( $user->ID, $seconds, $ip );
		$link  = add_query_arg( 'sudowp_token', $token, site_url() );

		// Send Email
		SudoWP_Auth::send_access_email( $user, $link, $hours );

		// Output
		WP_CLI::success( "Sudo Link Created & Emailed!" );
		WP_CLI::log( "----------------------------------------" );
		WP_CLI::log( "User: " . $user->user_login );
		WP_CLI::log( "URL: " . $link );
		WP_CLI::log( "Expires: In $hours hours" );
		if ( $new_user_created ) {
			WP_CLI::log( "Action: User will be DELETED automatically after expiry." );
		}
		WP_CLI::log( "----------------------------------------" );
	}

	/**
	 * List all active Sudo temporary users.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 * wp sudo list
	 * wp sudo list --format=json
	 *
	 * @subcommand list
	 */
	public function list_users( $args, $assoc_args ) {
		$users = get_users( array(
			'meta_key'   => '_sudowp_is_temporary',
			'meta_value' => true,
		) );

		if ( empty( $users ) ) {
			WP_CLI::warning( "No active temporary Sudo users found." );
			return;
		}

		$data = array();

		foreach ( $users as $user ) {
			$link = SudoWP_Auth::get_active_link( $user->ID );
			
			$data[] = array(
				'ID'     => $user->ID,
				'Login'  => $user->user_login,
				'Email'  => $user->user_email,
				'Role'   => implode( ', ', $user->roles ),
				'Link'   => $link ? $link : 'Expired',
			);
		}

		WP_CLI\Utils\format_items( $assoc_args['format'], $data, array( 'ID', 'Login', 'Email', 'Role', 'Link' ) );
	}

	/**
	 * Get info and active link for a specific Sudo user.
	 * * ## OPTIONS
	 * <user>
	 * : The username or email.
	 */
	public function info( $args, $assoc_args ) {
		$user_fetch = $args[0];
		
		$user = get_user_by( 'login', $user_fetch );
		if ( ! $user ) {
			$user = get_user_by( 'email', $user_fetch );
		}

		if ( ! $user ) {
			WP_CLI::error( "User not found." );
		}

		$is_temp = get_user_meta( $user->ID, '_sudowp_is_temporary', true );
		$link = SudoWP_Auth::get_active_link( $user->ID );

		WP_CLI::log( "----------------------------------------" );
		WP_CLI::log( "User ID: " . $user->ID );
		WP_CLI::log( "Username: " . $user->user_login );
		WP_CLI::log( "Email: " . $user->user_email );
		WP_CLI::log( "Type: " . ( $is_temp ? "Temporary Sudo User" : "Standard User" ) );
		
		if ( $link ) {
			WP_CLI::log( "Active Link: " . $link );
		} else {
			WP_CLI::log( "Active Link: None / Expired" );
		}
		WP_CLI::log( "----------------------------------------" );
	}

	/**
	 * Revoke and delete a temporary Sudo user.
	 * * ## OPTIONS
	 * <user>
	 * : The username or email.
	 */
	public function revoke( $args, $assoc_args ) {
		$user_fetch = $args[0];
		
		$user = get_user_by( 'login', $user_fetch );
		if ( ! $user ) {
			$user = get_user_by( 'email', $user_fetch );
		}

		if ( ! $user ) {
			WP_CLI::error( "User not found." );
		}

		$is_temp = get_user_meta( $user->ID, '_sudowp_is_temporary', true );

		if ( ! $is_temp ) {
			WP_CLI::error( "User is NOT a temporary Sudo user. Cannot delete." );
		}

		require_once( ABSPATH . 'wp-admin/includes/user.php' );
		
		if ( wp_delete_user( $user->ID, 1 ) ) {
			SudoWP_Logger::log( 0, 'sudo_user_revoked', "CLI: Deleted temporary user ID: {$user->ID}" );
			WP_CLI::success( "Temporary user deleted." );
		} else {
			WP_CLI::error( "Failed to delete user." );
		}
	}

	/**
	 * Configure SudoWP settings.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The setting key (currently only 'delete_data').
	 *
	 * <value>
	 * : The value to set (true/false).
	 *
	 * ## EXAMPLES
	 *
	 * wp sudo config delete_data true
	 *
	 * @when after_wp_load
	 */
	public function config( $args, $assoc_args ) {
		$key   = $args[0];
		$value = $args[1];

		if ( $key === 'delete_data' ) {
			$bool_val = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			update_option( 'sudowp_delete_data_on_uninstall', $bool_val );
			WP_CLI::success( "Setting 'delete_data_on_uninstall' set to " . ( $bool_val ? 'TRUE' : 'FALSE' ) );
		} else {
			WP_CLI::error( "Unknown config key. Available: delete_data" );
		}
	}

	/**
	 * Manually purge all SudoWP data (Logs & Tables).
	 * WARNING: This cannot be undone. Users will be revoked.
	 *
	 * ## EXAMPLES
	 *
	 * wp sudo purge
	 *
	 * @when after_wp_load
	 */
	public function purge( $args, $assoc_args ) {
		WP_CLI::confirm( "Are you sure you want to delete ALL SudoWP logs and database tables? Users will be revoked." );

		global $wpdb;

		// 1. Revoke Users
		$users = get_users( array( 'meta_key' => '_sudowp_is_temporary', 'meta_value' => true ) );
		$count = 0;
		if ( ! empty( $users ) ) {
			require_once( ABSPATH . 'wp-admin/includes/user.php' );
			foreach ( $users as $user ) {
				wp_delete_user( $user->ID, 1 );
				$count++;
			}
		}
		WP_CLI::log( "Revoked " . $count . " temporary users." );

		// 2. Drop Tables
		$table_name = $wpdb->prefix . 'sudowp_logs';
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
		WP_CLI::log( "Dropped logs table." );

		WP_CLI::success( "System purged successfully." );
	}
}

WP_CLI::add_command( 'sudo', 'SudoWP_CLI_Command' );