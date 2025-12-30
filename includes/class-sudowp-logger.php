<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SudoWP_Logger {

	public static function log( $user_id, $action, $details = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'sudowp_logs';

		$wpdb->insert(
			$table_name,
			array(
				'user_id'    => $user_id,
				'action'     => sanitize_text_field( $action ),
				'details'    => sanitize_textarea_field( $details ),
				'ip_address' => $_SERVER['REMOTE_ADDR'] // Capture Real IP
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}
}