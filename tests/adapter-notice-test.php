<?php
/**
 * Covers the MCP Adapter notice: which of its four messages a given site state gets.
 *
 * Two of those states cannot be produced on a real installation without breaking it —
 * an adapter that is active but never loaded its class, and a loaded copy whose file
 * cannot be resolved — so they are driven here instead. The other two are also checked
 * live against altovoltage.local.
 *
 * Run: php tests/adapter-notice-test.php
 */

// ─── WordPress doubles ───────────────────────────────────────────────────────

// admin.php bails out immediately without this.
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ewpa_caps']    = array( 'activate_plugins' );
$GLOBALS['ewpa_options'] = array();

$ewpa_sandbox = rtrim( sys_get_temp_dir(), '/\\' ) . '/ewpa-notice-test-' . getmypid();

define( 'WP_CONTENT_DIR', $ewpa_sandbox );
define( 'WP_PLUGIN_DIR', $ewpa_sandbox . '/plugins' );

register_shutdown_function(
	function () use ( $ewpa_sandbox ) {
		if ( ! is_dir( $ewpa_sandbox ) ) {
			return;
		}

		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ewpa_sandbox, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $entry ) {
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		@rmdir( $ewpa_sandbox );
	}
);

/**
 * @param string $capability Capability to check.
 * @return bool
 */
function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['ewpa_caps'], true );
}

/**
 * @param string $option  Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( $option, $default = false ) {
	return $GLOBALS['ewpa_options'][ $option ] ?? $default;
}

/**
 * @param string $option  Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_site_option( $option, $default = false ) {
	return $GLOBALS['ewpa_options'][ $option ] ?? $default;
}

/**
 * @return bool
 */
function is_multisite() {
	return false;
}

/**
 * @param string $path Path to normalize.
 * @return string
 */
function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

/**
 * @param string $value Value to slash.
 * @return string
 */
function trailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' ) . '/';
}

/**
 * @param string $text Text to escape.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * @param string $url URL to escape.
 * @return string
 */
function esc_url( $url ) {
	return (string) $url;
}

/**
 * @param string $text   Text to translate.
 * @param string $domain Text domain.
 * @return string
 */
function esc_html__( $text, $domain = 'default' ) {
	return (string) $text;
}

/**
 * @param string $text   Text to translate.
 * @param string $domain Text domain.
 * @return void
 */
function esc_html_e( $text, $domain = 'default' ) {
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Doubles in a test harness.
}

/**
 * @param string $text   Text to translate.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
	return (string) $text;
}

/**
 * @param string $text   Text to translate.
 * @param string $domain Text domain.
 * @return void
 */
function _e( $text, $domain = 'default' ) {
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Doubles in a test harness.
}

// Everything admin.php registers at load time is irrelevant here.
$GLOBALS['ewpa_noop_functions'] = array(
	'add_action',
	'add_filter',
	'add_options_page',
	'register_setting',
	'add_settings_error',
	'settings_errors',
	'wp_enqueue_script',
	'wp_enqueue_style',
	'wp_localize_script',
	'do_action',
	'submit_button',
	'wp_nonce_field',
	'checked',
	'selected',
	'disabled',
	'wp_create_nonce',
	'admin_url',
	'wp_unslash',
	'sanitize_text_field',
	'wp_json_encode',
	'wp_kses_post',
	'esc_attr',
	'esc_attr__',
	'esc_attr_e',
	'esc_textarea',
	'number_format_i18n',
	'wp_date',
	'get_bloginfo',
	'plugins_url',
	'update_option',
	'delete_option',
	'get_transient',
	'set_transient',
	'delete_transient',
	'wp_verify_nonce',
	'is_plugin_active',
	'get_plugins',
	'wp_remote_get',
	'wp_remote_retrieve_body',
	'is_wp_error',
	'absint',
	'wp_safe_redirect',
	'add_query_arg',
	'remove_query_arg',
	'wp_strip_all_tags',
	'sanitize_key',
	'current_time',
	'size_format',
	'human_time_diff',
	'wp_rand',
	'get_current_screen',
	'wp_get_current_user',
	'get_userdata',
	'get_current_user_id',
	'wp_schedule_single_event',
	'do_settings_sections',
	'settings_fields',
	'_n',
	'_x',
	'esc_html_x',
	'apply_filters',
);

foreach ( $GLOBALS['ewpa_noop_functions'] as $ewpa_fn ) {
	if ( ! function_exists( $ewpa_fn ) ) {
		eval( 'function ' . $ewpa_fn . '() { return null; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Bulk doubles.
	}
}

// ─── Subject ─────────────────────────────────────────────────────────────────

require dirname( __DIR__ ) . '/includes/admin.php';

// ─── Harness ─────────────────────────────────────────────────────────────────

$ewpa_pass = 0;
$ewpa_fail = 0;

/**
 * Records one expectation.
 *
 * @param string $name Check name.
 * @param bool   $ok   Whether it held.
 * @param string $got  Observed value, for a failure message.
 * @return void
 */
function ewpa_check( string $name, bool $ok, string $got = '' ): void {
	global $ewpa_pass, $ewpa_fail;

	if ( $ok ) {
		++$ewpa_pass;
		echo "PASS $name\n";
		return;
	}

	++$ewpa_fail;
	echo "FAIL $name", '' !== $got ? "  <- $got" : '', "\n";
}

/**
 * Renders the notice and returns its text.
 *
 * @return string
 */
function ewpa_render_notice(): string {
	ob_start();
	ewpa_admin_notice_mcp_adapter();
	$html = (string) ob_get_clean();

	return trim( preg_replace( '/\s+/', ' ', wp_strip_tags_for_test( $html ) ) );
}

/**
 * Strips tags without depending on WordPress.
 *
 * @param string $html Markup.
 * @return string
 */
function wp_strip_tags_for_test( string $html ): string {
	return strip_tags( $html );
}

/**
 * Declares the adapter class so class_exists() finds it, pointing at a given file.
 *
 * The notice resolves the location with Reflection, so the class has to really be
 * declared in a file. Each scenario that needs one evals it into its own stub file.
 *
 * @param string $file Absolute file the class should appear to come from.
 * @return void
 */
function ewpa_declare_adapter_class( string $file ): void {
	$dir = dirname( $file );

	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}

	file_put_contents( $file, "<?php\nnamespace WP\\MCP\\Core;\nclass McpAdapter { public const VERSION = '0.5.0'; }\n" );
	require_once $file;
}

// ─── Scenarios ───────────────────────────────────────────────────────────────

echo "== the adapter is not active ==\n";
$GLOBALS['ewpa_options']['active_plugins'] = array( 'woocommerce/woocommerce.php' );
$notice                                    = ewpa_render_notice();
ewpa_check( 'an_absent_adapter_gets_the_download_message', false !== strpos( $notice, 'requires the MCP Adapter plugin to work' ), $notice );
ewpa_check( 'an_absent_adapter_is_not_accused_of_a_conflict', false === strpos( $notice, 'already loaded' ), $notice );

echo "\n== the adapter is listed but its file is gone ==\n";
$GLOBALS['ewpa_options']['active_plugins'] = array( 'mcp-adapter/mcp-adapter.php' );
ewpa_check( 'a_listed_adapter_with_no_file_is_not_active', ! ewpa_mcp_adapter_plugin_is_active() );
$notice = ewpa_render_notice();
ewpa_check( 'a_listed_adapter_with_no_file_gets_the_download_message', false !== strpos( $notice, 'requires the MCP Adapter plugin to work' ), $notice );

echo "\n== the adapter is active but never loaded its class ==\n";
$ewpa_fake_plugin = WP_PLUGIN_DIR . '/mcp-adapter/mcp-adapter.php';

if ( ! is_dir( dirname( $ewpa_fake_plugin ) ) ) {
	mkdir( dirname( $ewpa_fake_plugin ), 0777, true );
}

file_put_contents( $ewpa_fake_plugin, "<?php\n" );
ewpa_check( 'the_adapter_counts_as_active', ewpa_mcp_adapter_plugin_is_active() );
ewpa_check( 'no_loaded_copy_is_reported_as_null', null === ewpa_mcp_adapter_conflict_source() );
$notice = ewpa_render_notice();
ewpa_check( 'an_adapter_that_did_not_start_is_not_blamed_on_another_copy', false === strpos( $notice, 'already loaded' ), $notice );
ewpa_check( 'an_adapter_that_did_not_start_says_so', false !== strpos( $notice, 'installed and active but did not start' ), $notice );
ewpa_check( 'an_adapter_that_did_not_start_is_not_asked_to_be_downloaded', false === strpos( $notice, 'requires the MCP Adapter plugin to work' ), $notice );

echo "\n== a conflicting copy is loaded from a plugin ==\n";
ewpa_declare_adapter_class( WP_PLUGIN_DIR . '/zz-bundler/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php' );
ewpa_check( 'the_conflicting_plugin_folder_is_named', 'zz-bundler' === ewpa_mcp_adapter_conflict_source(), var_export( ewpa_mcp_adapter_conflict_source(), true ) );
$notice = ewpa_render_notice();
ewpa_check( 'the_conflict_message_names_the_plugin', false !== strpos( $notice, 'already loaded from zz-bundler' ), $notice );

echo "\n$ewpa_pass passed, $ewpa_fail failed.\n";
exit( $ewpa_fail > 0 ? 1 : 0 );
