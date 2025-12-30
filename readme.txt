=== SudoWP – Secure Temporary Login & Audit Log ===
Contributors: wprepublic, thewebcitizen
Tags: temporary login, audit log, security, wp-cli, support access, admin access
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

The professional way to grant temporary admin access to developers and support agents. Includes Audit Logs and full WP-CLI integration.

== Description ==

**Give Access. Keep Control. Stay Secure.**

SudoWP is the ultimate tool for agencies, developers, and security-conscious site owners. It allows you to create secure, temporary login links ("Sudo Links") for support agents or external developers without sharing passwords.

Unlike other plugins, SudoWP is built with a **Security First** mindset. We don't just let people in; we track what they do.

### 🔥 KEY FEATURES

* **One-Click Sudo Links:** Generate a secure magic link that logs users in automatically.
* **Auto-Expiration:** Set links to expire in 1 hour, 4 hours, 24 hours, or 7 days.
* **Auto-Delete Users:** Automatically delete the temporary user account when the link expires (keeps your user table clean).
* **Security Audit Log:** Track critical actions taken by the temporary user (e.g., "sudo_login_success", plugin changes, etc.).
* **Log Retention Policy:** Automatically purge old logs (Weekly/Monthly) to keep your database optimized.
* **Clean Uninstall:** Option to wipe all SudoWP data and logs upon deletion.
* **Dedicated Dashboard:** Manage active links and view logs from a clean, modern UI.

### 💻 WP-CLI INTEGRATION (FOR PROS)

SudoWP treats WP-CLI as a first-class citizen. You can manage the entire lifecycle of temporary users directly from your terminal. See the **WP-CLI Commands** section below for details.

== WP-CLI Commands ==

SudoWP comes with a comprehensive suite of commands for system administrators.

**1. Create a Sudo Link**
Create a new temporary user or generate a link for an existing one.
`wp sudo create <username> [--email=<email>] [--role=<role>] [--expiry=<hours>]`

* `username`: The user login.
* `--email`: Required if creating a new user.
* `--role`: (Optional) Default is 'administrator'.
* `--expiry`: (Optional) Hours until expiration. Default is 24.

**2. List Active Users**
View all active temporary users and their current Sudo Links.
`wp sudo list`
`wp sudo list --format=json`

**3. Get User Info**
Get details and the active link for a specific user.
`wp sudo info <username>`

**4. Revoke Access**
Immediately delete a temporary user and revoke access.
`wp sudo revoke <username>`

**5. Configuration**
Update plugin settings via CLI.
`wp sudo config delete_data true` (Enable data wipe on uninstall)

**6. Manual Purge**
Clear all security logs and revoke all temporary users immediately (Danger Zone).
`wp sudo purge`

== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/sudowp` directory, or install the plugin through the WordPress plugins screen directly.
2.  Activate the plugin through the 'Plugins' screen in WordPress.
3.  Go to the **SudoWP** menu in your dashboard to create your first link.

== Frequently Asked Questions ==

= Does this plugin store passwords? =
No. SudoWP generates secure, time-limited authentication tokens. It does not store or share passwords.

= What happens when a temporary user expires? =
If the user was created via SudoWP as a temporary user, their account is automatically deleted from WordPress to ensure security.

= Can I use SudoWP with existing administrators? =
Yes. You can generate a Sudo Link for an existing admin. In this case, the user will NOT be deleted when the link expires; only the link becomes invalid.

== Screenshots ==

1. **Dashboard:** Create new links and view active temporary users easily.
2. **Security Logs:** Detailed audit trail of user actions.
3. **Settings:** Configure log retention and uninstall preferences.

== Changelog ==

= 0.2.0 =
* ADDED: Settings Tab for plugin configuration.
* ADDED: Log Retention Policy (Weekly/Monthly auto-purge).
* ADDED: "Delete Data on Uninstall" option.
* ADDED: Manual Log Purge via UI and CLI.
* IMPROVED: Full-width responsive layout for Logs table.
* SECURITY: Enhanced sanitization and nonce checks.

= 0.1.0 =
* Initial Release.
* Basic Sudo Link generation.
* Audit Logging.
* WP-CLI support.