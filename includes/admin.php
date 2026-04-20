<?php
/**
 * Admin settings page for Enable Abilities for MCP.
 *
 * @package EnableAbilitiesForMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Register admin menu ─────────────────────────────────────────────────────
add_action(
	'admin_menu',
	function () {
		add_options_page(
			__( 'WP Abilities', 'enable-abilities-for-mcp' ),
			__( 'WP Abilities', 'enable-abilities-for-mcp' ),
			'manage_options',
			'ewpa-settings',
			'ewpa_render_settings_page'
		);
	}
);

// ─── MCP Adapter dependency notice ──────────────────────────────────────────
add_action( 'admin_notices', 'ewpa_admin_notice_mcp_adapter' );

/**
 * Shows a dismissible admin notice if MCP Adapter plugin is not active.
 */
function ewpa_admin_notice_mcp_adapter(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) ) {
		return;
	}

	$mcp_url = 'https://github.com/WordPress/mcp-adapter/releases';
	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<?php
			printf(
				/* translators: %1$s: opening <a> tag, %2$s: closing </a> tag */
				esc_html__( 'Enable Abilities for MCP requires the MCP Adapter plugin to work. %1$sDownload MCP Adapter%2$s', 'enable-abilities-for-mcp' ),
				'<a href="' . esc_url( $mcp_url ) . '" target="_blank" rel="noopener noreferrer">',
				'</a>'
			);
			?>
		</p>
	</div>
	<?php
}

// ─── Migration notice ───────────────────────────────────────────────────────
add_action( 'admin_notices', 'ewpa_admin_notice_migration' );

/**
 * Shows a one-time dismissible notice after key migration from v1.7 to v1.8.
 */
function ewpa_admin_notice_migration(): void {
	if ( ! get_transient( 'ewpa_migration_notice' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	delete_transient( 'ewpa_migration_notice' );
	?>
	<div class="notice notice-info is-dismissible">
		<p>
			<?php esc_html_e( 'Ability keys updated to English for internationalization. Your settings have been preserved.', 'enable-abilities-for-mcp' ); ?>
		</p>
	</div>
	<?php
}

// ─── Settings page load: create table + lazy bearer migration ────────────────
add_action(
	'load-settings_page_ewpa-settings',
	function () {
		ewpa_create_activity_log_table();

		// If ewpa_bearer_enabled was never saved but a key exists, persist it now
		// so the toggle renders correctly without requiring a plugin reactivation.
		if ( false === get_option( 'ewpa_bearer_enabled' ) && get_option( EWPA_API_KEY_OPTION ) ) {
			update_option( 'ewpa_bearer_enabled', true );
		}
	}
);

// ─── Enqueue admin assets ───────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', 'ewpa_enqueue_admin_assets' );

/**
 * Enqueue CSS and JS only on the plugin settings page.
 *
 * @param string $hook_suffix The current admin page hook suffix.
 */
function ewpa_enqueue_admin_assets( $hook_suffix ) {
	if ( 'settings_page_ewpa-settings' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'ewpa-admin',
		EWPA_PLUGIN_URL . 'assets/css/admin.css',
		array(),
		EWPA_VERSION
	);

	wp_enqueue_script(
		'ewpa-admin',
		EWPA_PLUGIN_URL . 'assets/js/admin.js',
		array(),
		EWPA_VERSION,
		true
	);

	wp_localize_script(
		'ewpa-admin',
		'ewpaAdmin',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'ewpa_api_key_nonce' ),
			'logsNonce' => wp_create_nonce( 'ewpa_logs_nonce' ),
			'i18n'      => array(
				'keyActive'         => __( 'API Key active', 'enable-abilities-for-mcp' ),
				'regenerate'        => __( 'Regenerate API Key', 'enable-abilities-for-mcp' ),
				'revoke'            => __( 'Revoke API Key', 'enable-abilities-for-mcp' ),
				'confirmRegenerate' => __( 'This will invalidate the previous key. Continue?', 'enable-abilities-for-mcp' ),
				'confirmRevoke'     => __( 'Are you sure you want to revoke the API Key? External connections will stop working.', 'enable-abilities-for-mcp' ),
				'enabled'           => __( 'Enabled', 'enable-abilities-for-mcp' ),
				'disabled'          => __( 'Disabled', 'enable-abilities-for-mcp' ),
				'show'              => __( 'Show', 'enable-abilities-for-mcp' ),
				'hide'              => __( 'Hide', 'enable-abilities-for-mcp' ),
				'credError'         => __( 'Could not encode credentials. Please check your username and password.', 'enable-abilities-for-mcp' ),
				'confirmClearAll'   => __( 'Clear all activity logs? This cannot be undone.', 'enable-abilities-for-mcp' ),
				'confirmClearUser'  => __( 'Clear logs for this user? This cannot be undone.', 'enable-abilities-for-mcp' ),
				'cleared'           => __( 'Logs cleared.', 'enable-abilities-for-mcp' ),
				'copied'            => __( 'Copied!', 'enable-abilities-for-mcp' ),
				'copy'              => __( 'Copy', 'enable-abilities-for-mcp' ),
			),
		)
	);
}

// ─── Handle form submission ──────────────────────────────────────────────────
add_action(
	'admin_init',
	function () {
		if (
		! isset( $_POST['ewpa_save_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ewpa_save_nonce'] ) ), 'ewpa_save_settings' )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$all_keys = ewpa_get_all_ability_keys();
		$enabled  = array();

		if ( isset( $_POST['ewpa_abilities'] ) && is_array( $_POST['ewpa_abilities'] ) ) {
			$raw_abilities = array_map( 'sanitize_text_field', wp_unslash( $_POST['ewpa_abilities'] ) );
			foreach ( $raw_abilities as $key ) {
				if ( in_array( $key, $all_keys, true ) ) {
					$enabled[] = $key;
				}
			}
		}

		update_option( EWPA_OPTION_KEY, $enabled );

		add_settings_error(
			'ewpa_settings',
			'ewpa_saved',
			__( 'Settings saved successfully.', 'enable-abilities-for-mcp' ),
			'success'
		);
	}
);

// ─── AJAX: Toggle Bearer auth ────────────────────────────────────────────────
add_action( 'wp_ajax_ewpa_toggle_bearer', 'ewpa_ajax_toggle_bearer' );

/**
 * AJAX handler to enable or disable the Bearer token authentication method.
 */
function ewpa_ajax_toggle_bearer(): void {
	check_ajax_referer( 'ewpa_api_key_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions.', 'enable-abilities-for-mcp' ) ) );
	}

	$enabled = ! empty( $_POST['enabled'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
	update_option( 'ewpa_bearer_enabled', $enabled );

	wp_send_json_success( array( 'enabled' => $enabled ) );
}

// ─── AJAX: Generate API Key ──────────────────────────────────────────────────
add_action( 'wp_ajax_ewpa_generate_api_key', 'ewpa_ajax_generate_api_key' );

/**
 * AJAX handler to generate a new API key.
 */
function ewpa_ajax_generate_api_key(): void {
	check_ajax_referer( 'ewpa_api_key_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions.', 'enable-abilities-for-mcp' ) ) );
	}

	$plain_key = ewpa_generate_api_key( get_current_user_id() );

	wp_send_json_success(
		array(
			'key'     => $plain_key,
			'message' => __( 'API Key generated successfully.', 'enable-abilities-for-mcp' ),
		)
	);
}

// ─── AJAX: Revoke API Key ────────────────────────────────────────────────────
add_action( 'wp_ajax_ewpa_revoke_api_key', 'ewpa_ajax_revoke_api_key' );

/**
 * AJAX handler to revoke the current API key.
 */
function ewpa_ajax_revoke_api_key(): void {
	check_ajax_referer( 'ewpa_api_key_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions.', 'enable-abilities-for-mcp' ) ) );
	}

	ewpa_revoke_api_key();

	wp_send_json_success( array( 'message' => __( 'API Key revoked successfully.', 'enable-abilities-for-mcp' ) ) );
}

/**
 * Renders the admin settings page.
 */
function ewpa_render_settings_page(): void {
	$registry     = ewpa_get_abilities_registry();
	$current_user = wp_get_current_user();
	$profile_url  = admin_url( 'profile.php#application-passwords-section' );
	$mcp_url      = site_url( '/wp-json/mcp/mcp-adapter-default-server' );

	$ewpa_bearer_on = (bool) get_option( 'ewpa_bearer_enabled', false );
	$ewpa_api_key   = get_option( EWPA_API_KEY_OPTION );
	$ewpa_has_key   = is_array( $ewpa_api_key ) && ! empty( $ewpa_api_key['hash'] );
	?>
	<div class="wrap ewpa-wrap">
		<h1>
			<span class="dashicons dashicons-superhero" style="font-size: 28px; margin-right: 8px; vertical-align: text-bottom;"></span>
			<?php esc_html_e( 'Enable Abilities for MCP', 'enable-abilities-for-mcp' ); ?>
		</h1>
		<p class="ewpa-subtitle">
			<?php esc_html_e( 'Manage which WordPress abilities are available for MCP. Enable or disable each one according to your needs.', 'enable-abilities-for-mcp' ); ?>
		</p>

		<?php settings_errors( 'ewpa_settings' ); ?>

		<?php /* ── Tab navigation ──────────────────────────────────────────── */ ?>
		<div class="ewpa-tabs-nav" role="tablist">
			<button class="ewpa-tab-btn" data-tab="connection" role="tab">
				<span class="dashicons dashicons-admin-network"></span>
				<?php esc_html_e( 'Connection', 'enable-abilities-for-mcp' ); ?>
			</button>
			<button class="ewpa-tab-btn" data-tab="logs" role="tab">
				<span class="dashicons dashicons-chart-bar"></span>
				<?php esc_html_e( 'Activity Log', 'enable-abilities-for-mcp' ); ?>
			</button>
			<button class="ewpa-tab-btn" data-tab="abilities" role="tab">
				<span class="dashicons dashicons-superhero-alt"></span>
				<?php esc_html_e( 'Abilities', 'enable-abilities-for-mcp' ); ?>
			</button>
		</div>

		<?php /* ══ TAB: Connection ══════════════════════════════════════════ */ ?>
		<div class="ewpa-tab-panel" id="ewpa-tab-connection" role="tabpanel">
		<div class="ewpa-section ewpa-connection-section">
			<div class="ewpa-section-header">
				<div class="ewpa-section-title">
					<span class="dashicons dashicons-admin-network"></span>
					<div>
						<h2><?php esc_html_e( 'MCP Connection', 'enable-abilities-for-mcp' ); ?></h2>
						<p class="ewpa-section-desc">
							<?php esc_html_e( 'Choose how your MCP clients authenticate. Both methods log activity per user.', 'enable-abilities-for-mcp' ); ?>
						</p>
					</div>
				</div>
			</div>
			<div class="ewpa-section-body" style="padding: 0;">

				<?php /* ── Panel 1: Bearer token ─────────────────────────── */ ?>
				<div class="ewpa-auth-panel" style="padding: 20px; border-bottom: 1px solid #dcdcde;">
					<div style="display: flex; align-items: flex-start; gap: 16px;">
						<div style="flex: 1;">
							<h3 style="margin: 0 0 4px; font-size: 14px;">
								<?php esc_html_e( 'Single Admin Bearer Token', 'enable-abilities-for-mcp' ); ?>
							</h3>
							<p class="description" style="margin: 0 0 10px;">
								<?php
								esc_html_e(
									'One shared API key authenticates all MCP requests as a single admin user. Ideal for personal sites, automation scripts, or single-operator setups.',
									'enable-abilities-for-mcp'
								);
								?>
							</p>
							<div class="notice notice-warning inline" style="margin: 0; padding: 8px 12px; display: flex; align-items: flex-start; gap: 8px;">
								<span class="dashicons dashicons-warning" style="color: #dba617; margin-top: 2px; flex-shrink: 0;"></span>
								<p style="margin: 0; font-size: 12px;">
									<?php
									esc_html_e(
										'Team sites: anyone who has the token keeps access even after their WordPress account is removed. Use Application Passwords below for per-user control.',
										'enable-abilities-for-mcp'
									);
									?>
								</p>
							</div>
						</div>
						<div style="flex-shrink: 0; display: flex; align-items: center; gap: 10px; padding-top: 2px;">
							<span class="description" style="font-size: 12px;" id="ewpa-bearer-status-label">
								<?php echo $ewpa_bearer_on ? esc_html__( 'Enabled', 'enable-abilities-for-mcp' ) : esc_html__( 'Disabled', 'enable-abilities-for-mcp' ); ?>
							</span>
							<label class="ewpa-switch" style="margin: 0;">
								<input type="checkbox" id="ewpa-bearer-toggle" <?php checked( $ewpa_bearer_on ); ?>>
								<span class="ewpa-slider"></span>
							</label>
						</div>
					</div>

					<?php /* Bearer key management — shown when enabled */ ?>
					<div id="ewpa-bearer-body" style="margin-top: 16px; <?php echo $ewpa_bearer_on ? '' : 'display:none;'; ?>">
						<div id="ewpa-api-key-status">
							<?php if ( $ewpa_has_key ) : ?>
								<p class="ewpa-key-active">
									<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
									<?php
									printf(
										/* translators: %s: formatted date */
										esc_html__( 'API Key active — generated on %s', 'enable-abilities-for-mcp' ),
										esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ewpa_api_key['created_at'] ) )
									);
									?>
								</p>
							<?php else : ?>
								<p class="ewpa-key-inactive">
									<span class="dashicons dashicons-warning" style="color: #dba617;"></span>
									<?php esc_html_e( 'No API Key configured.', 'enable-abilities-for-mcp' ); ?>
								</p>
							<?php endif; ?>
						</div>

						<div id="ewpa-api-key-display" style="display: none; margin: 12px 0;">
							<div class="notice notice-warning inline" style="margin: 0; padding: 12px;">
								<p><strong><?php esc_html_e( 'Copy this key now. It will not be shown again:', 'enable-abilities-for-mcp' ); ?></strong></p>
								<p>
									<code id="ewpa-api-key-value" style="font-size: 14px; padding: 6px 10px; background: #f6f7f7; display: inline-block; word-break: break-all;"></code>
									<button type="button" class="button button-small ewpa-copy-btn" id="ewpa-copy-key" data-target="ewpa-api-key-value" style="margin-left: 8px;">
										<?php esc_html_e( 'Copy', 'enable-abilities-for-mcp' ); ?>
									</button>
								</p>
							</div>
						</div>

						<div class="ewpa-key-actions" style="margin-top: 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
							<?php if ( $ewpa_has_key ) : ?>
								<button type="button" class="button" id="ewpa-regenerate-key">
									<?php esc_html_e( 'Regenerate API Key', 'enable-abilities-for-mcp' ); ?>
								</button>
								<button type="button" class="button button-link-delete" id="ewpa-revoke-key">
									<?php esc_html_e( 'Revoke API Key', 'enable-abilities-for-mcp' ); ?>
								</button>
							<?php else : ?>
								<button type="button" class="button button-primary" id="ewpa-generate-key">
									<?php esc_html_e( 'Generate API Key', 'enable-abilities-for-mcp' ); ?>
								</button>
							<?php endif; ?>
						</div>

						<?php /* Claude Desktop example — Bearer */ ?>
						<div style="margin-top: 16px;">
							<h4 style="margin: 0 0 6px; font-size: 13px;"><?php esc_html_e( 'Claude Desktop configuration', 'enable-abilities-for-mcp' ); ?></h4>
							<?php
							$ewpa_bearer_json  = "{\n";
							$ewpa_bearer_json .= "  \"mcpServers\": {\n";
							$ewpa_bearer_json .= "    \"my-wordpress-site\": {\n";
							$ewpa_bearer_json .= "      \"command\": \"npx\",\n";
							$ewpa_bearer_json .= "      \"args\": [\n";
							$ewpa_bearer_json .= "        \"-y\",\n";
							$ewpa_bearer_json .= "        \"mcp-remote\",\n";
							$ewpa_bearer_json .= '        "' . esc_url( $mcp_url ) . "\",\n";
							$ewpa_bearer_json .= "        \"--header\",\n";
							$ewpa_bearer_json .= "        \"Authorization: Bearer YOUR-API-KEY\"\n";
							$ewpa_bearer_json .= "      ]\n";
							$ewpa_bearer_json .= "    }\n";
							$ewpa_bearer_json .= "  }\n";
							$ewpa_bearer_json .= '}';
							?>
							<div style="position: relative;">
								<pre id="ewpa-bearer-config" style="background: #1e1e1e; color: #d4d4d4; padding: 14px 16px; border-radius: 4px; overflow-x: auto; font-size: 13px; line-height: 1.5; margin: 0;"><code style="color: inherit; background: none;"><?php echo esc_html( $ewpa_bearer_json ); ?></code></pre>
								<button type="button" class="button ewpa-copy-btn" data-target="ewpa-bearer-config" style="position: absolute; top: 8px; right: 8px;">
									<?php esc_html_e( 'Copy', 'enable-abilities-for-mcp' ); ?>
								</button>
							</div>
							<p class="description" style="margin-top: 6px;">
								<?php esc_html_e( 'Replace YOUR-API-KEY with the key generated above.', 'enable-abilities-for-mcp' ); ?>
								<?php esc_html_e( 'The name "my-wordpress-site" is just an identifier — use it to invoke this MCP server from Claude or any other AI client (e.g. "use my-wordpress-site to…").', 'enable-abilities-for-mcp' ); ?>
							</p>
						</div>
					</div>
				</div>

				<?php /* ── Panel 2: Application Passwords ──────────────────── */ ?>
				<div class="ewpa-auth-panel" style="padding: 20px;">
					<div style="display: flex; align-items: flex-start; gap: 16px; margin-bottom: 16px;">
						<div style="flex: 1;">
							<h3 style="margin: 0 0 4px; font-size: 14px;">
								<?php esc_html_e( 'WordPress Application Passwords', 'enable-abilities-for-mcp' ); ?>
								<span style="background: #00a32a; color: #fff; font-size: 10px; font-weight: 600; padding: 2px 7px; border-radius: 3px; vertical-align: middle; margin-left: 6px; letter-spacing: .5px;">
									<?php esc_html_e( 'RECOMMENDED FOR TEAMS', 'enable-abilities-for-mcp' ); ?>
								</span>
							</h3>
							<p class="description" style="margin: 0;">
								<?php
								esc_html_e(
									'Each user creates their own token from their WordPress profile. Removing or deactivating a user immediately revokes their MCP access — no shared secrets, full audit trail.',
									'enable-abilities-for-mcp'
								);
								?>
							</p>
						</div>
					</div>

					<?php /* Step 1: create the password */ ?>
					<div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
						<div style="flex: 1; min-width: 200px;">
							<p class="description" style="margin: 0;">
								<strong><?php esc_html_e( 'Step 1 —', 'enable-abilities-for-mcp' ); ?></strong>
								<?php esc_html_e( 'Create an Application Password in your profile. Copy it — shown only once.', 'enable-abilities-for-mcp' ); ?>
							</p>
						</div>
						<a href="<?php echo esc_url( $profile_url ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer" style="flex-shrink: 0;">
							<span class="dashicons dashicons-external" style="margin-top: 3px; margin-right: 4px; font-size: 16px;"></span>
							<?php
							printf(
								/* translators: %s: current WordPress username */
								esc_html__( 'Open profile for %s', 'enable-abilities-for-mcp' ),
								'<strong>' . esc_html( $current_user->user_login ) . '</strong>'
							);
							?>
						</a>
					</div>

					<?php /* Step 2: in-browser credential generator */ ?>
					<p class="description" style="margin: 0 0 8px;">
						<strong><?php esc_html_e( 'Step 2 —', 'enable-abilities-for-mcp' ); ?></strong>
						<?php esc_html_e( 'Generate your connection credentials right here — your password never leaves this page.', 'enable-abilities-for-mcp' ); ?>
					</p>
					<div class="ewpa-cred-generator">
						<div class="ewpa-cred-field">
							<label for="ewpa-cred-username"><?php esc_html_e( 'WordPress username', 'enable-abilities-for-mcp' ); ?></label>
							<input type="text" id="ewpa-cred-username" value="<?php echo esc_attr( $current_user->user_login ); ?>" readonly style="background:#f6f7f7; max-width: 280px;">
						</div>
						<div class="ewpa-cred-field">
							<label for="ewpa-cred-apppass"><?php esc_html_e( 'Application Password', 'enable-abilities-for-mcp' ); ?></label>
							<div style="display: flex; gap: 8px; align-items: center; max-width: 360px;">
								<input type="password" id="ewpa-cred-apppass" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" style="flex: 1;" autocomplete="off">
								<button type="button" class="button" id="ewpa-toggle-pass" style="white-space: nowrap;">
									<?php esc_html_e( 'Show', 'enable-abilities-for-mcp' ); ?>
								</button>
							</div>
							<p class="description" style="margin-top: 4px; font-size: 12px;">
								<?php esc_html_e( 'Paste the Application Password you just copied from your profile (spaces are OK).', 'enable-abilities-for-mcp' ); ?>
							</p>
						</div>
						<button type="button" class="button button-primary" id="ewpa-gen-creds">
							<span class="dashicons dashicons-lock" style="margin-top: 3px; margin-right: 4px; font-size: 16px;"></span>
							<?php esc_html_e( 'Generate Credentials', 'enable-abilities-for-mcp' ); ?>
						</button>

						<div id="ewpa-creds-output" class="ewpa-cred-output" style="display: none;">
							<p style="margin: 0 0 4px; font-size: 12px; color: #00a32a; font-weight: 600;">
								<span class="dashicons dashicons-yes-alt" style="font-size: 14px; vertical-align: middle;"></span>
								<?php esc_html_e( 'Generated locally — your password was never sent to the server.', 'enable-abilities-for-mcp' ); ?>
							</p>
							<p class="description" style="margin: 0 0 6px; font-size: 12px;">
								<?php esc_html_e( 'Copy and use this as YOUR_BASE64_CREDENTIALS in the config below:', 'enable-abilities-for-mcp' ); ?>
							</p>
							<code id="ewpa-creds-value" style="display: block; word-break: break-all; padding: 8px 10px; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 3px; font-size: 13px; margin-bottom: 8px;"></code>
							<button type="button" class="button ewpa-copy-btn" data-target="ewpa-creds-value">
								<?php esc_html_e( 'Copy credentials', 'enable-abilities-for-mcp' ); ?>
							</button>
						</div>
					</div>

					<?php /* Step 3: Claude Desktop config */ ?>
					<p class="description" style="margin: 12px 0 6px;">
						<strong><?php esc_html_e( 'Step 3 —', 'enable-abilities-for-mcp' ); ?></strong>
						<?php
						printf(
							/* translators: %s: config file name */
							esc_html__( 'Add this block to your %s using the credentials from Step 2:', 'enable-abilities-for-mcp' ),
							'<code>claude_desktop_config.json</code>'
						);
						?>
					</p>
					<?php
					$ewpa_apppass_json  = "{\n";
					$ewpa_apppass_json .= "  \"mcpServers\": {\n";
					$ewpa_apppass_json .= "    \"my-wordpress-site\": {\n";
					$ewpa_apppass_json .= "      \"command\": \"npx\",\n";
					$ewpa_apppass_json .= "      \"args\": [\n";
					$ewpa_apppass_json .= "        \"-y\",\n";
					$ewpa_apppass_json .= "        \"mcp-remote\",\n";
					$ewpa_apppass_json .= '        "' . esc_url( $mcp_url ) . "\",\n";
					$ewpa_apppass_json .= "        \"--header\",\n";
					$ewpa_apppass_json .= "        \"Authorization: Basic YOUR_BASE64_CREDENTIALS\"\n";
					$ewpa_apppass_json .= "      ]\n";
					$ewpa_apppass_json .= "    }\n";
					$ewpa_apppass_json .= "  }\n";
					$ewpa_apppass_json .= '}';
					?>
					<div style="position: relative; margin-bottom: 8px;">
						<pre id="ewpa-apppass-config" style="background: #1e1e1e; color: #d4d4d4; padding: 14px 16px; border-radius: 4px; overflow-x: auto; font-size: 13px; line-height: 1.5; margin: 0;"><code style="color: inherit; background: none;"><?php echo esc_html( $ewpa_apppass_json ); ?></code></pre>
						<button type="button" class="button ewpa-copy-btn" data-target="ewpa-apppass-config" style="position: absolute; top: 8px; right: 8px;">
							<?php esc_html_e( 'Copy', 'enable-abilities-for-mcp' ); ?>
						</button>
					</div>
					<p class="description" style="margin-bottom: 12px;">
						<?php esc_html_e( 'The name "my-wordpress-site" is just an identifier — use it to invoke this MCP server from Claude or any other AI client (e.g. "use my-wordpress-site to…"). You can rename it to anything meaningful.', 'enable-abilities-for-mcp' ); ?>
					</p>

					<?php /* MCP endpoint */ ?>
					<h4 style="margin: 12px 0 6px; font-size: 13px;"><?php esc_html_e( 'MCP Endpoint URL', 'enable-abilities-for-mcp' ); ?></h4>
					<div style="display: flex; align-items: center; gap: 8px;">
						<code id="ewpa-mcp-url" style="display: block; flex: 1; padding: 8px 12px; background: #f6f7f7; border: 1px solid #dcdcde; word-break: break-all;">
							<?php echo esc_url( $mcp_url ); ?>
						</code>
						<button type="button" class="button ewpa-copy-btn" data-target="ewpa-mcp-url">
							<?php esc_html_e( 'Copy', 'enable-abilities-for-mcp' ); ?>
						</button>
					</div>
				</div>

			</div>
		</div>

		</div><?php /* /ewpa-tab-panel connection */ ?>

		<?php /* ══ TAB: Activity Log ═══════════════════════════════════════ */ ?>
		<div class="ewpa-tab-panel" id="ewpa-tab-logs" role="tabpanel">
		<?php ewpa_render_activity_log_section(); ?>
		</div><?php /* /ewpa-tab-panel logs */ ?>

		<?php /* ══ TAB: Abilities ══════════════════════════════════════════ */ ?>
		<div class="ewpa-tab-panel" id="ewpa-tab-abilities" role="tabpanel">
		<form method="post" action="">
			<?php wp_nonce_field( 'ewpa_save_settings', 'ewpa_save_nonce' ); ?>

			<div class="ewpa-toolbar">
				<div class="ewpa-toolbar-left">
					<span class="ewpa-counter">
						<strong id="ewpa-enabled-count">0</strong> / <strong id="ewpa-total-count">0</strong>
						<?php esc_html_e( 'abilities enabled', 'enable-abilities-for-mcp' ); ?>
					</span>
				</div>
				<div class="ewpa-toolbar-right">
					<button type="button" class="button" id="ewpa-enable-all">
						<?php esc_html_e( 'Enable All', 'enable-abilities-for-mcp' ); ?>
					</button>
					<button type="button" class="button" id="ewpa-disable-all">
						<?php esc_html_e( 'Disable All', 'enable-abilities-for-mcp' ); ?>
					</button>
				</div>
			</div>

			<?php foreach ( $registry as $section_key => $section ) : ?>
				<div class="ewpa-section" data-section="<?php echo esc_attr( $section_key ); ?>">
					<div class="ewpa-section-header">
						<div class="ewpa-section-title">
							<span class="dashicons <?php echo esc_attr( $section['section_icon'] ); ?>"></span>
							<div>
								<h2>
									<?php echo esc_html( $section['section_label'] ); ?>
									<?php if ( ! empty( $section['section_badge'] ) ) : ?>
										<span class="ewpa-badge ewpa-badge-<?php echo esc_attr( $section['section_badge'] ); ?>">
											<?php esc_html_e( 'Caution', 'enable-abilities-for-mcp' ); ?>
										</span>
									<?php endif; ?>
								</h2>
								<p class="ewpa-section-desc"><?php echo esc_html( $section['section_desc'] ); ?></p>
							</div>
						</div>
						<label class="ewpa-section-toggle">
							<input type="checkbox" class="ewpa-section-check" data-section="<?php echo esc_attr( $section_key ); ?>">
							<span><?php esc_html_e( 'All', 'enable-abilities-for-mcp' ); ?></span>
						</label>
					</div>
					<div class="ewpa-section-body">
						<?php
						if ( ! empty( $section['section_notice'] ) && is_callable( $section['section_notice'] ) ) {
							$notice_html = call_user_func( $section['section_notice'] );
							if ( $notice_html ) {
								echo wp_kses_post( $notice_html );
							}
						}
						?>
						<?php foreach ( $section['abilities'] as $ability_key => $ability ) : ?>
							<div class="ewpa-ability">
								<label class="ewpa-switch">
									<input
										type="checkbox"
										name="ewpa_abilities[]"
										value="<?php echo esc_attr( $ability_key ); ?>"
										class="ewpa-ability-check"
										data-section="<?php echo esc_attr( $section_key ); ?>"
										<?php checked( ewpa_is_ability_enabled( $ability_key ) ); ?>
									>
									<span class="ewpa-slider"></span>
								</label>
								<div class="ewpa-ability-info">
									<strong><?php echo esc_html( $ability['label'] ); ?></strong>
									<code class="ewpa-ability-key"><?php echo esc_html( $ability_key ); ?></code>
									<p><?php echo esc_html( $ability['desc'] ); ?></p>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save Changes', 'enable-abilities-for-mcp' ), 'primary large', 'submit', true, array( 'id' => 'ewpa-save-btn' ) ); ?>
		</form>
		</div><?php /* /ewpa-tab-panel abilities */ ?>

	</div><?php /* /wrap */ ?>

	<?php
}

/**
 * Renders the Activity Log section inside the settings page.
 */
function ewpa_render_activity_log_section(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination on admin page, no state change.
	$current_page = isset( $_GET['ewpa_log_page'] ) ? max( 1, absint( $_GET['ewpa_log_page'] ) ) : 1;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on admin page, no state change.
	$filter_user = isset( $_GET['ewpa_log_user'] ) ? absint( $_GET['ewpa_log_user'] ) : 0;

	$data        = ewpa_get_activity_logs(
		array(
			'page'    => $current_page,
			'user_id' => $filter_user,
		)
	);
	$logs        = $data['logs'];
	$total       = $data['total'];
	$per_page    = 20;
	$total_pages = (int) ceil( $total / $per_page );

	$log_users = ewpa_get_log_users();
	$base_url  = admin_url( 'options-general.php?page=ewpa-settings&ewpa_tab=logs' );
	?>
	<div class="ewpa-section ewpa-log-section">
		<div class="ewpa-section-header">
			<div class="ewpa-section-title">
				<span class="dashicons dashicons-chart-bar"></span>
				<div>
					<h2><?php esc_html_e( 'Activity Log', 'enable-abilities-for-mcp' ); ?></h2>
					<p class="ewpa-section-desc">
						<?php esc_html_e( 'MCP ability executions per user — last 30 days.', 'enable-abilities-for-mcp' ); ?>
					</p>
				</div>
			</div>
		</div>
		<div class="ewpa-section-body" style="padding: 20px;">

			<?php /* Toolbar: filter + clear */ ?>
			<div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
				<form method="get" action="<?php echo esc_url( $base_url ); ?>" style="display: flex; align-items: center; gap: 8px;">
					<input type="hidden" name="page" value="ewpa-settings">
					<label for="ewpa-log-user-filter" style="font-weight: 600; white-space: nowrap;">
						<?php esc_html_e( 'User:', 'enable-abilities-for-mcp' ); ?>
					</label>
					<select id="ewpa-log-user-filter" name="ewpa_log_user" onchange="this.form.submit()">
						<option value="0" <?php selected( 0, $filter_user ); ?>>
							<?php esc_html_e( 'All users', 'enable-abilities-for-mcp' ); ?>
						</option>
						<?php foreach ( $log_users as $lu ) : ?>
							<option value="<?php echo esc_attr( $lu->user_id ); ?>" <?php selected( (int) $lu->user_id, $filter_user ); ?>>
								<?php echo esc_html( $lu->user_login ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</form>

				<div style="margin-left: auto; display: flex; gap: 8px;">
					<?php if ( $filter_user ) : ?>
						<button type="button" class="button ewpa-clear-logs" data-user="<?php echo esc_attr( $filter_user ); ?>">
							<?php esc_html_e( 'Clear logs for this user', 'enable-abilities-for-mcp' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( $total > 0 ) : ?>
						<button type="button" class="button button-link-delete ewpa-clear-logs" data-user="0">
							<?php esc_html_e( 'Clear all logs', 'enable-abilities-for-mcp' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<?php /* Log table */ ?>
			<?php if ( empty( $logs ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'No activity recorded yet. Logs will appear here once users start calling abilities via MCP.', 'enable-abilities-for-mcp' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" style="border-radius: 4px; overflow: hidden;">
					<thead>
						<tr>
							<th style="width: 160px;"><?php esc_html_e( 'Date / Time', 'enable-abilities-for-mcp' ); ?></th>
							<th style="width: 130px;"><?php esc_html_e( 'User', 'enable-abilities-for-mcp' ); ?></th>
							<th><?php esc_html_e( 'Ability', 'enable-abilities-for-mcp' ); ?></th>
							<th style="width: 90px;"><?php esc_html_e( 'Status', 'enable-abilities-for-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td style="white-space: nowrap;">
									<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->created_at ) ) ); ?>
								</td>
								<td>
									<?php if ( $log->user_id && $log->user_login ) : ?>
										<a href="<?php echo esc_url( get_edit_user_link( $log->user_id ) ); ?>">
											<?php echo esc_html( $log->user_login ); ?>
										</a>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'Unknown', 'enable-abilities-for-mcp' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<code style="font-size: 12px;"><?php echo esc_html( $log->ability ); ?></code>
								</td>
								<td>
									<?php if ( 'success' === $log->status ) : ?>
										<span style="color: #00a32a; font-weight: 600;">&#10003; <?php esc_html_e( 'Success', 'enable-abilities-for-mcp' ); ?></span>
									<?php else : ?>
										<span style="color: #d63638; font-weight: 600;">&#10007; <?php esc_html_e( 'Error', 'enable-abilities-for-mcp' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php /* Pagination */ ?>
				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom" style="margin-top: 8px;">
						<div class="tablenav-pages" style="display: flex; align-items: center; gap: 6px; float: right;">
							<span class="displaying-num">
								<?php
								printf(
									/* translators: %d: total entries */
									esc_html( _n( '%d entry', '%d entries', $total, 'enable-abilities-for-mcp' ) ),
									(int) $total
								);
								?>
							</span>
							<?php if ( $current_page > 1 ) : ?>
								<a class="button" href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'ewpa_log_page' => $current_page - 1,
											'ewpa_log_user' => $filter_user,
										),
										$base_url
									)
								);
								?>
														">
									&lsaquo;
								</a>
							<?php endif; ?>
							<span><?php echo esc_html( $current_page . ' / ' . $total_pages ); ?></span>
							<?php if ( $current_page < $total_pages ) : ?>
								<a class="button" href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'ewpa_log_page' => $current_page + 1,
											'ewpa_log_user' => $filter_user,
										),
										$base_url
									)
								);
								?>
														">
									&rsaquo;
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

			<?php endif; ?>

		</div>
	</div>
	<?php
}
