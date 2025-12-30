<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SudoWP_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'process_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'print_copy_script' ) );
	}

	/**
	 * Enqueue CSS Styles
	 */
	public function enqueue_assets( $hook ) {
		// Load only on SudoWP page
		if ( $hook !== 'toplevel_page_sudowp' ) {
			return;
		}

		wp_enqueue_style( 
			'sudowp-admin-css', 
			SUDOWP_URL . 'assets/css/sudowp-admin.css', 
			array(), 
			SUDOWP_VERSION 
		);
	}

	public function add_plugin_page() {
		add_menu_page(
			'SudoWP Logs',
			'SudoWP',
			'manage_options',
			'sudowp',
			array( $this, 'render_admin_page' ),
			'dashicons-shield',
			99
		);
	}

	/**
	 * Process Form Actions (POST/GET)
	 * Validates Nonces and Sanitizes Input.
	 */
	public function process_actions() {
		// 1. Check Permissions first
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 2. SAVE SETTINGS
		if ( isset( $_POST['sudowp_save_settings'] ) ) {
			check_admin_referer( 'sudowp_settings_action', 'sudowp_settings_nonce' );
			
			// Sanitization
			$delete_data = isset( $_POST['sudowp_delete_data'] ) ? 1 : 0;
			update_option( 'sudowp_delete_data_on_uninstall', $delete_data );
			
			$retention = isset( $_POST['sudowp_log_retention'] ) ? sanitize_text_field( $_POST['sudowp_log_retention'] ) : 'never';
			
			// Additional validation
			if ( ! in_array( $retention, array( 'never', 'weekly', 'monthly' ), true ) ) {
				$retention = 'never';
			}
			update_option( 'sudowp_log_retention', $retention );

			add_settings_error( 'sudowp_messages', 'sudowp_msg', __( 'Settings saved successfully.', 'sudowp' ), 'updated' );
		}

		// 3. MANUAL PURGE
		if ( isset( $_POST['sudowp_manual_purge'] ) ) {
			check_admin_referer( 'sudowp_purge_action', 'sudowp_purge_nonce' );

			global $wpdb;
			$table_name = $wpdb->prefix . 'sudowp_logs';
			$wpdb->query( "TRUNCATE TABLE $table_name" );
			
			SudoWP_Logger::log( get_current_user_id(), 'system_log_purge', 'Manual security log purge initiated.' );
			add_settings_error( 'sudowp_messages', 'sudowp_msg', __( 'All Security Logs have been purged.', 'sudowp' ), 'updated' );
		}

		// 4. CREATE LINK
		if ( isset( $_POST['sudowp_create_link'] ) ) {
			check_admin_referer( 'sudowp_create_action', 'sudowp_nonce' );

			// Sanitization
			$username = isset( $_POST['sudowp_username'] ) ? sanitize_user( $_POST['sudowp_username'] ) : '';
			$email    = isset( $_POST['sudowp_email'] ) ? sanitize_email( $_POST['sudowp_email'] ) : '';
			$role     = isset( $_POST['sudowp_role'] ) ? sanitize_text_field( $_POST['sudowp_role'] ) : 'administrator';
			$hours    = isset( $_POST['sudowp_hours'] ) ? (int) $_POST['sudowp_hours'] : 24;
			
			$seconds  = $hours * HOUR_IN_SECONDS;

			// Logic
			$result = SudoWP_Auth::get_or_create_user( $username, $email, $role, $seconds );

			if ( is_wp_error( $result ) ) {
				add_settings_error( 'sudowp_messages', 'sudowp_error', $result->get_error_message(), 'error' );
				return;
			}

			$user = $result['user'];
			$token = SudoWP_Auth::generate_token( $user->ID, $seconds );
			$link  = add_query_arg( 'sudowp_token', $token, site_url() );

			SudoWP_Auth::send_access_email( $user, $link, $hours );

			set_transient( 'sudowp_last_link', $link, 60 );
			
			// Safe Redirect
			wp_safe_redirect( add_query_arg( array( 'page' => 'sudowp', 'link_generated' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// 5. REVOKE USER
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'revoke_sudo' && isset( $_GET['user_id'] ) ) {
			// Validate ID
			$target_user_id = (int) $_GET['user_id'];
			
			// Validate Nonce
			check_admin_referer( 'revoke_sudo_' . $target_user_id );
			
			$is_temp = get_user_meta( $target_user_id, '_sudowp_is_temporary', true );
			
			if ( $is_temp ) {
				require_once( ABSPATH . 'wp-admin/includes/user.php' );
				wp_delete_user( $target_user_id, get_current_user_id() );
				SudoWP_Logger::log( get_current_user_id(), 'sudo_user_revoked', "Manually deleted temporary user ID: $target_user_id" );
				add_settings_error( 'sudowp_messages', 'sudowp_msg', __( 'Sudo User deleted successfully.', 'sudowp' ), 'updated' );
			}
		}
	}

	/**
	 * Main Render Function
	 */
	public function render_admin_page() {
		// Security: Sanitize the tab parameter to prevent XSS/Injection
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		
		// Whitelist valid tabs
		if ( ! in_array( $active_tab, array( 'dashboard', 'settings' ), true ) ) {
			$active_tab = 'dashboard';
		}
		?>
		<div class="wrap sudowp-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SudoWP Dashboard', 'sudowp' ); ?></h1>
			
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sudowp&tab=dashboard' ) ); ?>" class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Dashboard', 'sudowp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sudowp&tab=settings' ) ); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'sudowp' ); ?>
				</a>
			</nav>
			
			<div style="margin-top: 20px;">
				<?php settings_errors( 'sudowp_messages' ); ?>
				
				<?php 
				if ( $active_tab === 'settings' ) {
					$this->render_settings_tab();
				} else {
					$this->render_dashboard_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	private function render_dashboard_tab() {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'sudowp_logs';
		// Direct SQL is okay here, but ensure table name is trusted (it is, from wpdb->prefix)
		$logs = $wpdb->get_results( "SELECT * FROM $logs_table ORDER BY id DESC LIMIT 50" );
		
		$temp_users = get_users( array( 'meta_key' => '_sudowp_is_temporary', 'meta_value' => true ) );
		$last_link = get_transient( 'sudowp_last_link' );
		delete_transient( 'sudowp_last_link' );
		?>

		<?php if ( $last_link ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><strong><?php esc_html_e( 'Success!', 'sudowp' ); ?></strong> <?php esc_html_e( 'User created and email sent. Active Link:', 'sudowp' ); ?></p>
				<div class="sudowp-copy-wrapper">
					<input type="text" value="<?php echo esc_url( $last_link ); ?>" class="large-text" readonly id="new_created_link">
					<button type="button" class="button" onclick="copyToClipboard('new_created_link', this)"><?php esc_html_e( 'Copy Link', 'sudowp' ); ?></button>
				</div>
			</div>
		<?php endif; ?>

		<div class="sudowp-flex-container">
			<div class="sudowp-card">
				<h2><?php esc_html_e( 'Create New Sudo Link', 'sudowp' ); ?></h2>
				<form method="post" action="">
					<?php wp_nonce_field( 'sudowp_create_action', 'sudowp_nonce' ); ?>
					<p>
						<label for="sudowp_username"><strong><?php esc_html_e( 'Username', 'sudowp' ); ?></strong> (<?php esc_html_e( 'Required', 'sudowp' ); ?>)</label><br>
						<input name="sudowp_username" type="text" id="sudowp_username" class="regular-text sudowp-full-width" required>
					</p>
					<p>
						<label for="sudowp_email"><strong><?php esc_html_e( 'Email', 'sudowp' ); ?></strong> (<?php esc_html_e( 'Required for new users', 'sudowp' ); ?>)</label><br>
						<input name="sudowp_email" type="email" id="sudowp_email" class="regular-text sudowp-full-width" placeholder="e.g. dev@agency.com">
					</p>
					<div style="display:flex; gap: 15px;">
						<div style="flex: 1;">
							<label for="sudowp_role"><strong><?php esc_html_e( 'Role', 'sudowp' ); ?></strong></label><br>
							<select name="sudowp_role" id="sudowp_role" class="sudowp-full-width">
								<option value="administrator">Administrator</option>
								<option value="editor">Editor</option>
							</select>
						</div>
						<div style="flex: 1;">
							<label for="sudowp_hours"><strong><?php esc_html_e( 'Expires In', 'sudowp' ); ?></strong></label><br>
							<select name="sudowp_hours" id="sudowp_hours" class="sudowp-full-width">
								<option value="1">1 Hour</option>
								<option value="4">4 Hours</option>
								<option value="24" selected>24 Hours</option>
								<option value="168">7 Days</option>
							</select>
						</div>
					</div>
					<p class="submit">
						<input type="submit" name="sudowp_create_link" class="button button-primary" value="<?php esc_attr_e( 'Generate Sudo Link', 'sudowp' ); ?>">
					</p>
				</form>
			</div>

			<div class="sudowp-card danger">
				<h2><?php esc_html_e( 'Active Temporary Users', 'sudowp' ); ?></h2>
				<?php if ( ! empty( $temp_users ) ) : ?>
					<table class="widefat striped sudowp-full-width">
						<thead>
							<tr>
								<th><?php esc_html_e( 'User', 'sudowp' ); ?></th>
								<th><?php esc_html_e( 'Link', 'sudowp' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'sudowp' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $temp_users as $user ) : 
								$active_link = SudoWP_Auth::get_active_link( $user->ID );
								$input_id = 'sudo_link_' . $user->ID;
							?>
								<tr>
									<td>
										<strong><?php echo esc_html( $user->user_login ); ?></strong><br>
										<span style="font-size: 11px;"><?php echo esc_html( $user->user_email ); ?></span>
									</td>
									<td>
										<?php if ( $active_link ) : ?>
											<button type="button" class="button button-small" onclick="copyToClipboard('<?php echo esc_attr( $input_id ); ?>', this)"><?php esc_html_e( 'Copy', 'sudowp' ); ?></button>
											<input type="text" value="<?php echo esc_url( $active_link ); ?>" id="<?php echo esc_attr( $input_id ); ?>" class="sudowp-hidden-input">
										<?php else : ?>
											<span style="color: #999;"><?php esc_html_e( 'Expired', 'sudowp' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php 
											$revoke_url = wp_nonce_url( admin_url( 'admin.php?page=sudowp&action=revoke_sudo&user_id=' . $user->ID ), 'revoke_sudo_' . $user->ID );
										?>
										<a href="<?php echo esc_url( $revoke_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this user?', 'sudowp' ); ?>');"><?php esc_html_e( 'Revoke', 'sudowp' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><?php esc_html_e( 'No active temporary users.', 'sudowp' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<hr style="margin: 30px 0;">

		<h2><?php esc_html_e( 'Security Logs', 'sudowp' ); ?></h2>
		<div class="sudowp-logs-container">
			<table class="wp-list-table widefat striped sudowp-table">
				<thead>
					<tr>
						<th class="sudowp-col-nowrap" style="width: 160px;"><?php esc_html_e( 'Date', 'sudowp' ); ?></th>
						<th class="sudowp-col-nowrap" style="width: 150px;"><?php esc_html_e( 'User', 'sudowp' ); ?></th>
						<th class="sudowp-col-nowrap" style="width: 180px;"><?php esc_html_e( 'Action', 'sudowp' ); ?></th>
						<th><?php esc_html_e( 'Details', 'sudowp' ); ?></th>
						<th class="sudowp-col-nowrap" style="width: 140px;"><?php esc_html_e( 'IP', 'sudowp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $logs ) ) : ?>
						<?php foreach ( $logs as $row ) : 
							$user_info = get_userdata( $row->user_id );
							$username = $user_info ? $user_info->user_login : 'Unknown';
						?>
						<tr>
							<td class="sudowp-col-nowrap"><?php echo esc_html( $row->created_at ); ?></td>
							<td><strong><?php echo esc_html( $username ); ?></strong></td>
							<td>
								<span class="sudowp-badge">
									<?php echo esc_html( $row->action ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $row->details ); ?></td>
							<td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
						</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No logs yet.', 'sudowp' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_settings_tab() {
		$delete_data = get_option( 'sudowp_delete_data_on_uninstall', false );
		$retention   = get_option( 'sudowp_log_retention', 'never' );
		?>
		
		<div class="sudowp-card settings" style="max-width: 800px; margin-top: 20px;">
			<form method="post" action="">
				<?php wp_nonce_field( 'sudowp_settings_action', 'sudowp_settings_nonce' ); ?>
				
				<h3><?php esc_html_e( 'General Configuration', 'sudowp' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall Cleanup', 'sudowp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sudowp_delete_data" value="1" <?php checked( $delete_data, 1 ); ?>>
								<?php esc_html_e( 'Delete all data on uninstall', 'sudowp' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'If checked, SudoWP Logs and Database tables will be deleted when you uninstall the plugin.', 'sudowp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-Purge Logs', 'sudowp' ); ?></th>
						<td>
							<select name="sudowp_log_retention">
								<option value="never" <?php selected( $retention, 'never' ); ?>><?php esc_html_e( 'Never', 'sudowp' ); ?></option>
								<option value="weekly" <?php selected( $retention, 'weekly' ); ?>><?php esc_html_e( 'Every Week (Keep last 7 days)', 'sudowp' ); ?></option>
								<option value="monthly" <?php selected( $retention, 'monthly' ); ?>><?php esc_html_e( 'Every Month (Keep last 30 days)', 'sudowp' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Automatically delete old security logs to keep the database small.', 'sudowp' ); ?></p>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" name="sudowp_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'sudowp' ); ?>">
				</p>
			</form>
		</div>

		<div class="sudowp-card danger" style="max-width: 800px; margin-top: 20px;">
			<h3><?php esc_html_e( 'Danger Zone', 'sudowp' ); ?></h3>
			<p><?php esc_html_e( 'If your database is getting too large, you can clear all logs immediately.', 'sudowp' ); ?></p>
			
			<form method="post" action="" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure? This will delete ALL security logs history.', 'sudowp' ); ?>');">
				<?php wp_nonce_field( 'sudowp_purge_action', 'sudowp_purge_nonce' ); ?>
				<input type="submit" name="sudowp_manual_purge" class="button button-link-delete" value="<?php esc_attr_e( 'Purge All Logs Now', 'sudowp' ); ?>">
			</form>
		</div>
		<?php
	}

	public function print_copy_script() {
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'sudowp' ) {
			?>
			<script type="text/javascript">
			function copyToClipboard(elementId, btn) {
				var copyText = document.getElementById(elementId);
				copyText.select();
				copyText.setSelectionRange(0, 99999);
				try {
					document.execCommand('copy');
					var originalText = btn.innerText;
					btn.innerText = '<?php esc_js( __( 'Copied!', 'sudowp' ) ); ?>';
					setTimeout(function() { btn.innerText = originalText; }, 2000);
				} catch (err) { alert('Unable to copy'); }
			}
			</script>
			<?php
		}
	}
}