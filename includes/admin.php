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
			__( 'Configuración guardada correctamente.', 'enable-abilities-for-mcp' ),
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
		wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'enable-abilities-for-mcp' ) ) );
	}

	$plain_key = ewpa_generate_api_key( get_current_user_id() );

	wp_send_json_success(
		array(
			'key'     => $plain_key,
			'message' => __( 'API Key generada exitosamente.', 'enable-abilities-for-mcp' ),
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
		wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'enable-abilities-for-mcp' ) ) );
	}

	ewpa_revoke_api_key();

	wp_send_json_success(
		array(
			'message' => __( 'API Key revocada exitosamente.', 'enable-abilities-for-mcp' ),
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
			<?php esc_html_e( 'Administra qué abilities de WordPress están disponibles para el MCP. Activa o desactiva cada una según tus necesidades.', 'enable-abilities-for-mcp' ); ?>
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
						<h2><?php esc_html_e( 'API Key para MCP', 'enable-abilities-for-mcp' ); ?></h2>
						<p class="ewpa-section-desc">
							<?php esc_html_e( 'Genera una API Key para autenticar conexiones externas al servidor MCP via Bearer token.', 'enable-abilities-for-mcp' ); ?>
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
								esc_html__( 'API Key activa — generada el %s', 'enable-abilities-for-mcp' ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $api_key_data['created_at'] ) )
							);
							?>
						</p>
					<?php else : ?>
						<p class="ewpa-key-inactive">
							<span class="dashicons dashicons-warning" style="color: #dba617;"></span>
							<?php esc_html_e( 'No hay API Key configurada.', 'enable-abilities-for-mcp' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<div id="ewpa-api-key-display" style="display: none; margin: 12px 0;">
					<div class="notice notice-warning inline" style="margin: 0; padding: 12px;">
						<p><strong><?php esc_html_e( 'Copia esta clave ahora. No se mostrara de nuevo:', 'enable-abilities-for-mcp' ); ?></strong></p>
						<p>
							<code id="ewpa-api-key-value" style="font-size: 14px; padding: 6px 10px; background: #f6f7f7; display: inline-block; word-break: break-all;"></code>
							<button type="button" class="button button-small" id="ewpa-copy-key" style="margin-left: 8px;">
								<?php esc_html_e( 'Copiar', 'enable-abilities-for-mcp' ); ?>
							</button>
						</p>
					</div>
				</div>

				<div class="ewpa-key-actions" style="margin-top: 12px;">
					<?php if ( $has_key ) : ?>
						<button type="button" class="button" id="ewpa-regenerate-key">
							<?php esc_html_e( 'Regenerar API Key', 'enable-abilities-for-mcp' ); ?>
						</button>
						<button type="button" class="button button-link-delete" id="ewpa-revoke-key" style="margin-left: 8px;">
							<?php esc_html_e( 'Revocar API Key', 'enable-abilities-for-mcp' ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="button button-primary" id="ewpa-generate-key">
							<?php esc_html_e( 'Generar API Key', 'enable-abilities-for-mcp' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<p class="description" style="margin-top: 12px;">
					<?php
					printf(
						/* translators: %s: example authorization header */
						esc_html__( 'Usa esta clave en el header de autenticacion: %s', 'enable-abilities-for-mcp' ),
						'<code>Authorization: Bearer &lt;tu-api-key&gt;</code>'
					);
					?>
				</p>
			</div>
		</div>

		<form method="post" action="">
			<?php wp_nonce_field( 'ewpa_save_settings', 'ewpa_save_nonce' ); ?>

			<div class="ewpa-toolbar">
				<div class="ewpa-toolbar-left">
					<span class="ewpa-counter">
						<strong id="ewpa-enabled-count">0</strong> / <strong id="ewpa-total-count">0</strong>
						<?php esc_html_e( 'abilities activas', 'enable-abilities-for-mcp' ); ?>
					</span>
				</div>
				<div class="ewpa-toolbar-right">
					<button type="button" class="button" id="ewpa-enable-all">
						<?php esc_html_e( 'Activar todas', 'enable-abilities-for-mcp' ); ?>
					</button>
					<button type="button" class="button" id="ewpa-disable-all">
						<?php esc_html_e( 'Desactivar todas', 'enable-abilities-for-mcp' ); ?>
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
											<?php esc_html_e( 'Precaución', 'enable-abilities-for-mcp' ); ?>
										</span>
									<?php endif; ?>
								</h2>
								<p class="ewpa-section-desc"><?php echo esc_html( $section['section_desc'] ); ?></p>
							</div>
						</div>
						<label class="ewpa-section-toggle">
							<input type="checkbox" class="ewpa-section-check" data-section="<?php echo esc_attr( $section_key ); ?>">
							<span><?php esc_html_e( 'Todas', 'enable-abilities-for-mcp' ); ?></span>
						</label>
					</div>
					<div class="ewpa-section-body">
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

			<?php submit_button( __( 'Guardar Cambios', 'enable-abilities-for-mcp' ), 'primary large', 'submit', true, array( 'id' => 'ewpa-save-btn' ) ); ?>
		</form>
	</div>

	<style>
		.ewpa-wrap {
			max-width: 860px;
		}
		.ewpa-subtitle {
			color: #50575e;
			font-size: 14px;
			margin: 4px 0 20px;
		}

		/* Toolbar */
		.ewpa-toolbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 16px;
			padding: 10px 16px;
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
		}
		.ewpa-toolbar-right {
			display: flex;
			gap: 8px;
		}
		.ewpa-counter {
			font-size: 14px;
			color: #50575e;
		}

		/* Section card */
		.ewpa-section {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			margin-bottom: 16px;
			box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
		}
		.ewpa-section-header {
			padding: 16px 20px;
			border-bottom: 1px solid #e0e0e0;
			display: flex;
			justify-content: space-between;
			align-items: center;
			background: #f9f9f9;
			border-radius: 4px 4px 0 0;
		}
		.ewpa-section-title {
			display: flex;
			align-items: flex-start;
			gap: 12px;
		}
		.ewpa-section-title > .dashicons {
			font-size: 24px;
			width: 24px;
			height: 24px;
			color: #2271b1;
			margin-top: 2px;
		}
		.ewpa-section-title h2 {
			margin: 0;
			font-size: 15px;
			line-height: 1.4;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.ewpa-section-desc {
			margin: 2px 0 0;
			color: #646970;
			font-size: 13px;
		}
		.ewpa-section-toggle {
			display: flex;
			align-items: center;
			gap: 6px;
			cursor: pointer;
			white-space: nowrap;
			font-size: 13px;
			color: #50575e;
			user-select: none;
		}
		.ewpa-section-toggle input {
			margin: 0;
		}

		/* Badge */
		.ewpa-badge {
			display: inline-block;
			padding: 1px 8px;
			border-radius: 10px;
			font-size: 11px;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: .3px;
		}
		.ewpa-badge-warning {
			background: #fcf0e3;
			color: #9a6700;
			border: 1px solid #f0c78b;
		}

		/* Ability row */
		.ewpa-section-body {
			padding: 0;
		}
		.ewpa-ability {
			padding: 14px 20px;
			border-bottom: 1px solid #f0f0f1;
			display: flex;
			align-items: flex-start;
			gap: 16px;
		}
		.ewpa-ability:last-child {
			border-bottom: none;
		}
		.ewpa-ability-info {
			flex: 1;
		}
		.ewpa-ability-info strong {
			display: inline;
			font-size: 13px;
		}
		.ewpa-ability-info p {
			margin: 4px 0 0;
			color: #646970;
			font-size: 12px;
			line-height: 1.5;
		}
		.ewpa-ability-key {
			font-size: 11px;
			color: #8c8f94;
			background: #f0f0f1;
			padding: 1px 6px;
			border-radius: 3px;
			margin-left: 6px;
		}

		/* Toggle switch */
		.ewpa-switch {
			position: relative;
			display: inline-block;
			width: 40px;
			min-width: 40px;
			height: 22px;
			margin-top: 1px;
		}
		.ewpa-switch input {
			opacity: 0;
			width: 0;
			height: 0;
			position: absolute;
		}
		.ewpa-slider {
			position: absolute;
			cursor: pointer;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background-color: #c3c4c7;
			transition: background-color .2s ease;
			border-radius: 22px;
		}
		.ewpa-slider::before {
			position: absolute;
			content: "";
			height: 16px;
			width: 16px;
			left: 3px;
			bottom: 3px;
			background-color: #fff;
			transition: transform .2s ease;
			border-radius: 50%;
			box-shadow: 0 1px 2px rgba(0, 0, 0, .2);
		}
		.ewpa-switch input:checked + .ewpa-slider {
			background-color: #2271b1;
		}
		.ewpa-switch input:checked + .ewpa-slider::before {
			transform: translateX(18px);
		}
		.ewpa-switch input:focus + .ewpa-slider {
			box-shadow: 0 0 0 2px #2271b1;
		}

		/* Save button area */
		#ewpa-save-btn {
			font-size: 14px;
			padding: 6px 24px;
			height: auto;
		}
	</style>

	<script>
	var ewpaApiKey = {
		ajaxUrl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
		nonce: '<?php echo esc_js( wp_create_nonce( 'ewpa_api_key_nonce' ) ); ?>'
	};
	</script>

	<script>
	(function () {
		/* ── API Key Management ─────────────────────────────────────────── */
		function ewpaDoAjax(action, confirmMsg) {
			if (confirmMsg && !confirm(confirmMsg)) {
				return;
			}
			var xhr = new XMLHttpRequest();
			xhr.open('POST', ewpaApiKey.ajaxUrl, true);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.onload = function () {
				if (xhr.status === 200) {
					var response = JSON.parse(xhr.responseText);
					if (response.success) {
						if (response.data.key) {
							document.getElementById('ewpa-api-key-value').textContent = response.data.key;
							document.getElementById('ewpa-api-key-display').style.display = 'block';
							var statusEl = document.getElementById('ewpa-api-key-status');
							statusEl.innerHTML = '<p class="ewpa-key-active">' +
								'<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ' +
								'API Key activa</p>';
							var actionsEl = document.querySelector('.ewpa-key-actions');
							actionsEl.innerHTML =
								'<button type="button" class="button" id="ewpa-regenerate-key">Regenerar API Key</button>' +
								'<button type="button" class="button button-link-delete" id="ewpa-revoke-key" style="margin-left: 8px;">Revocar API Key</button>';
							ewpaBindKeyButtons();
						} else {
							location.reload();
						}
					} else {
						alert(response.data.message || 'Error');
					}
				}
			};
			xhr.send('action=' + action + '&nonce=' + ewpaApiKey.nonce);
		}

		function ewpaBindKeyButtons() {
			var genBtn = document.getElementById('ewpa-generate-key');
			var regenBtn = document.getElementById('ewpa-regenerate-key');
			var revokeBtn = document.getElementById('ewpa-revoke-key');
			var copyBtn = document.getElementById('ewpa-copy-key');

			if (genBtn) {
				genBtn.addEventListener('click', function () {
					ewpaDoAjax('ewpa_generate_api_key', null);
				});
			}
			if (regenBtn) {
				regenBtn.addEventListener('click', function () {
					ewpaDoAjax('ewpa_generate_api_key', '<?php echo esc_js( __( 'Esto invalidara la clave anterior. ¿Continuar?', 'enable-abilities-for-mcp' ) ); ?>');
				});
			}
			if (revokeBtn) {
				revokeBtn.addEventListener('click', function () {
					ewpaDoAjax('ewpa_revoke_api_key', '<?php echo esc_js( __( '¿Seguro que deseas revocar la API Key? Las conexiones externas dejaran de funcionar.', 'enable-abilities-for-mcp' ) ); ?>');
				});
			}
			if (copyBtn) {
				copyBtn.addEventListener('click', function () {
					var keyText = document.getElementById('ewpa-api-key-value').textContent;
					navigator.clipboard.writeText(keyText).then(function () {
						copyBtn.textContent = '<?php echo esc_js( __( '¡Copiada!', 'enable-abilities-for-mcp' ) ); ?>';
						setTimeout(function () {
							copyBtn.textContent = '<?php echo esc_js( __( 'Copiar', 'enable-abilities-for-mcp' ) ); ?>';
						}, 2000);
					});
				});
			}
		}
		ewpaBindKeyButtons();

		/* ── Abilities Toggles ──────────────────────────────────────────── */
		var checkboxes = document.querySelectorAll('.ewpa-ability-check');
		var sectionChecks = document.querySelectorAll('.ewpa-section-check');
		var enableAll = document.getElementById('ewpa-enable-all');
		var disableAll = document.getElementById('ewpa-disable-all');
		var countEl = document.getElementById('ewpa-enabled-count');
		var totalEl = document.getElementById('ewpa-total-count');

		totalEl.textContent = checkboxes.length;

		function updateCount() {
			var count = 0;
			checkboxes.forEach(function (cb) {
				if (cb.checked) count++;
			});
			countEl.textContent = count;
		}

		function updateSectionCheck(section) {
			var items = document.querySelectorAll('.ewpa-ability-check[data-section="' + section + '"]');
			var sectionCb = document.querySelector('.ewpa-section-check[data-section="' + section + '"]');
			if (!sectionCb) return;
			var allChecked = true;
			items.forEach(function (cb) {
				if (!cb.checked) allChecked = false;
			});
			sectionCb.checked = allChecked;
		}

		function updateAllSections() {
			sectionChecks.forEach(function (sc) {
				updateSectionCheck(sc.getAttribute('data-section'));
			});
		}

		// Individual toggle
		checkboxes.forEach(function (cb) {
			cb.addEventListener('change', function () {
				updateCount();
				updateSectionCheck(this.getAttribute('data-section'));
			});
		});

		// Section toggle
		sectionChecks.forEach(function (sc) {
			sc.addEventListener('change', function () {
				var section = this.getAttribute('data-section');
				var checked = this.checked;
				document.querySelectorAll('.ewpa-ability-check[data-section="' + section + '"]').forEach(function (cb) {
					cb.checked = checked;
				});
				updateCount();
			});
		});

		// Enable all
		enableAll.addEventListener('click', function () {
			checkboxes.forEach(function (cb) { cb.checked = true; });
			sectionChecks.forEach(function (sc) { sc.checked = true; });
			updateCount();
		});

		// Disable all
		disableAll.addEventListener('click', function () {
			checkboxes.forEach(function (cb) { cb.checked = false; });
			sectionChecks.forEach(function (sc) { sc.checked = false; });
			updateCount();
		});

		// Init
		updateCount();
		updateAllSections();
	})();
	</script>
	<?php
}
