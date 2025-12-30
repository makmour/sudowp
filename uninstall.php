<?php
/**
 * Fired when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Always Revoke/Delete all temporary users first (Safety First)
$users = get_users( array(
	'meta_key'   => '_sudowp_is_temporary',
	'meta_value' => true,
) );

if ( ! empty( $users ) ) {
	require_once( ABSPATH . 'wp-admin/includes/user.php' );
	foreach ( $users as $user ) {
		wp_delete_user( $user->ID, 1 ); // Reassign content to Admin (ID 1)
	}
}

// 2. Check if user opted to clean up data
$delete_data = get_option( 'sudowp_delete_data_on_uninstall', false );

if ( $delete_data ) {
	global $wpdb;

	// Drop the Logs Table
	$table_name = $wpdb->prefix . 'sudowp_logs';
	$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

	// Delete Options and Transients
	delete_option( 'sudowp_delete_data_on_uninstall' );
	
	// Clean up any remaining transients (wildcard deletion is tricky in WP, but we delete specific ones if known)
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_sudowp_%'" );
	$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_sudowp_%'" );
}