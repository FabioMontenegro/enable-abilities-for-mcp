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
 *
 * @return void
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
 *
 * @return void
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
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ewpa_api_key_nonce' ),
			'i18n'    => array(
				'keyActive'         => __( 'API Key active', 'enable-abilities-for-mcp' ),
				'regenerate'        => __( 'Regenerate API Key', 'enable-abilities-for-mcp' ),
				'revoke'            => __( 'Revoke API Key', 'enable-abilities-for-mcp' ),
				'confirmRegenerate' => __( 'This will invalidate the previous key. Continue?', 'enable-abilities-for-mcp' ),
				'confirmRevoke'     => __( 'Are you sure you want to revoke the API Key? External connections will stop working.', 'enable-abilities-for-mcp' ),
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

// ─── AJAX: Generate API Key ─────────────────────────────────────────────────
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

// ─── AJAX: Revoke API Key ───────────────────────────────────────────────────
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

	wp_send_json_success(
		array(
			'message' => __( 'API Key revoked successfully.', 'enable-abilities-for-mcp' ),
		)
	);
}

/**
 * Renders the admin settings page.
 */
function ewpa_render_settings_page(): void {
	$registry = ewpa_get_abilities_registry();
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

		<?php
		$api_key_data = get_option( EWPA_API_KEY_OPTION );
		$has_key      = is_array( $api_key_data ) && ! empty( $api_key_data['hash'] );
		?>
		<div class="ewpa-section ewpa-api-key-section">
			<div class="ewpa-section-header">
				<div class="ewpa-section-title">
					<span class="dashicons dashicons-admin-network"></span>
					<div>
						<h2><?php esc_html_e( 'API Key for MCP', 'enable-abilities-for-mcp' ); ?></h2>
						<p class="ewpa-section-desc">
							<?php esc_html_e( 'Generate an API Key to authenticate external connections to the MCP server via Bearer token.', 'enable-abilities-for-mcp' ); ?>
						</p>
					</div>
				</div>
			</div>
			<div class="ewpa-section-body" style="padding: 20px;">
				<div id="ewpa-api-key-status">
					<?php if ( $has_key ) : ?>
						<p class="ewpa-key-active">
							<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
							<?php
							printf(
								/* translators: %s: formatted date */
								esc_html__( 'API Key active — generated on %s', 'enable-abilities-for-mcp' ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $api_key_data['created_at'] ) )
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
							<button type="button" class="button button-small" id="ewpa-copy-key" style="margin-left: 8px;">
								<?php esc_html_e( 'Copy', 'enable-abilities-for-mcp' ); ?>
							</button>
						</p>
					</div>
				</div>

				<div class="ewpa-key-actions" style="margin-top: 12px;">
					<?php if ( $has_key ) : ?>
						<button type="button" class="button" id="ewpa-regenerate-key">
							<?php esc_html_e( 'Regenerate API Key', 'enable-abilities-for-mcp' ); ?>
						</button>
						<button type="button" class="button button-link-delete" id="ewpa-revoke-key" style="margin-left: 8px;">
							<?php esc_html_e( 'Revoke API Key', 'enable-abilities-for-mcp' ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="button button-primary" id="ewpa-generate-key">
							<?php esc_html_e( 'Generate API Key', 'enable-abilities-for-mcp' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<p class="description" style="margin-top: 12px;">
					<?php
					printf(
						/* translators: %s: example authorization header */
						esc_html__( 'Use this key in the authentication header: %s', 'enable-abilities-for-mcp' ),
						'<code>Authorization: Bearer &lt;your-api-key&gt;</code>'
					);
					?>
				</p>

				<div style="margin-top: 16px; padding: 16px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px;">
					<h4 style="margin: 0 0 8px;">
						<?php esc_html_e( 'Your site MCP endpoint', 'enable-abilities-for-mcp' ); ?>
					</h4>
					<p class="description" style="margin: 0 0 8px;">
						<?php esc_html_e( 'Use this URL to connect your MCP client:', 'enable-abilities-for-mcp' ); ?>
					</p>
					<code style="display: block; padding: 8px 12px; background: #fff; border: 1px solid #dcdcde; word-break: break-all;">
						<?php echo esc_url( site_url( '/wp-json/mcp/mcp-adapter-default-server' ) ); ?>
					</code>

					<h4 style="margin: 16px 0 8px;">
						<?php esc_html_e( 'Claude Desktop configuration example', 'enable-abilities-for-mcp' ); ?>
					</h4>
					<p class="description" style="margin: 0 0 8px;">
						<?php
						printf(
							/* translators: %s: config file name */
							esc_html__( 'Add this to your configuration file %s:', 'enable-abilities-for-mcp' ),
							'<code>claude_desktop_config.json</code>'
						);
						?>
					</p>
					<?php
					$ewpa_mcp_url = esc_url( site_url( '/wp-json/mcp/mcp-adapter-default-server' ) );
					$ewpa_json    = "{\n";
					$ewpa_json   .= "  \"mcpServers\": {\n";
					$ewpa_json   .= "    \"my-wordpress-site\": {\n";
					$ewpa_json   .= "      \"command\": \"npx\",\n";
					$ewpa_json   .= "      \"args\": [\n";
					$ewpa_json   .= "        \"-y\",\n";
					$ewpa_json   .= "        \"mcp-remote\",\n";
					$ewpa_json   .= '        "' . $ewpa_mcp_url . "\",\n";
					$ewpa_json   .= "        \"--header\",\n";
					$ewpa_json   .= "        \"Authorization: Bearer YOUR-API-KEY\"\n";
					$ewpa_json   .= "      ]\n";
					$ewpa_json   .= "    }\n";
					$ewpa_json   .= "  }\n";
					$ewpa_json   .= '}';
					?>
					<pre style="background: #1e1e1e; color: #d4d4d4; padding: 14px 16px; border-radius: 4px; overflow-x: auto; font-size: 13px; line-height: 1.5; margin: 0;"><code style="color: inherit; background: none;"><?php echo esc_html( $ewpa_json ); ?></code></pre>
					<p class="description" style="margin: 8px 0 0;">
						<?php esc_html_e( 'Replace YOUR-API-KEY with the API Key generated above.', 'enable-abilities-for-mcp' ); ?>
					</p>
				</div>
			</div>
		</div>

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
						// Render section notice if present.
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
	</div>

	<?php
}
