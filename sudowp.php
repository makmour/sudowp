<?php
/*
 Plugin Name: SudoWP
 Plugin URI: wprepublic.com
 Description: Secure temporary login & audit logging for professionals.
 Version: 0.2.0
 Author: WP Republic
 Author URI: https://wprepublic.com/
 License: GPL-2.0+
 License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 Text Domain: sudowp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'SUDOWP_VERSION', '0.2.0' );
define( 'SUDOWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'SUDOWP_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes
require_once SUDOWP_PATH . 'includes/class-sudowp-auth.php';
require_once SUDOWP_PATH . 'includes/class-sudowp-logger.php';

if ( is_admin() ) {
	require_once SUDOWP_PATH . 'includes/class-sudowp-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SUDOWP_PATH . 'cli/class-sudowp-cli.php';
}

/**
 * Main Class
 */
class SudoWP {

	public function __construct() {
		new SudoWP_Auth();

		if ( is_admin() ) {
			new SudoWP_Admin();
		}
		
		// Scheduled Hooks
		add_action( 'sudowp_scheduled_delete_user', array( $this, 'delete_temporary_user' ) );
		add_action( 'sudowp_daily_maintenance', array( $this, 'process_log_retention' ) );
	}

	/**
	 * 1. Auto-delete expired users
	 */
	public function delete_temporary_user( $user_id ) {
		if ( user_can( $user_id, 'manage_options' ) ) {
			$is_temp = get_user_meta( $user_id, '_sudowp_is_temporary', true );
			if ( ! $is_temp ) {
				return;
			}
		}

		require_once( ABSPATH . 'wp-admin/includes/user.php' );
		wp_delete_user( $user_id, 1 );
		SudoWP_Logger::log( 0, 'system_user_cleanup', "Automatically deleted temporary user ID: $user_id" );
	}

	/**
	 * 2. Auto-purge old logs based on settings
	 */
	public function process_log_retention() {
		$retention = get_option( 'sudowp_log_retention', 'never' );

		if ( $retention === 'never' ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'sudowp_logs';
		$days = 0;

		if ( $retention === 'weekly' ) {
			$days = 7;
		} elseif ( $retention === 'monthly' ) {
			$days = 30;
		}

		if ( $days > 0 ) {
			// Delete logs older than X days
			$wpdb->query( 
				$wpdb->prepare( 
					"DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", 
					$days 
				) 
			);
		}
	}

	/**
	 * Activation: Setup DB & Schedule Cron
	 */
	public static function activate() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'sudowp_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			action varchar(100) NOT NULL,
			details text,
			ip_address varchar(45) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		// Schedule Daily Maintenance if not exists
		if ( ! wp_next_scheduled( 'sudowp_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'sudowp_daily_maintenance' );
		}
	}

	/**
	 * Deactivation: Cleanup Hooks & Users
	 */
	public static function deactivate() {
		// Clean up users
		$users = get_users( array(
			'meta_key'   => '_sudowp_is_temporary',
			'meta_value' => true,
		) );

		if ( ! empty( $users ) ) {
			require_once( ABSPATH . 'wp-admin/includes/user.php' );
			foreach ( $users as $user ) {
				wp_delete_user( $user->ID, 1 );
			}
		}
		
		// Clear scheduled hooks
		wp_clear_scheduled_hook( 'sudowp_scheduled_delete_user' );
		wp_clear_scheduled_hook( 'sudowp_daily_maintenance' );
	}
}

new SudoWP();

register_activation_hook( __FILE__, array( 'SudoWP', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SudoWP', 'deactivate' ) );