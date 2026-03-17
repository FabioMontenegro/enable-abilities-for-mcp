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
	(function () {
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
