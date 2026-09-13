<?php
/**
 * Tests for the Code Snippets helpers and the human-confirmed activation flow.
 *
 * Run with: php tests/code-snippets-test.php
 *
 * @package EnableAbilitiesForMCP
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/doubles/code-snippets.php';

/*
 * ==========================================================================
 * WORDPRESS FUNCTION DOUBLES
 * ==========================================================================
 */

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function __( $text, $domain = '' ) {
	return $text;
}

function add_action( ...$args ) {}

function do_action( $hook, ...$args ) {
	$GLOBALS['ewpa_actions'][] = $hook;
}

function get_option( $name, $default = false ) {
	return $GLOBALS['ewpa_options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['ewpa_options'][ $name ] = $value;
	return true;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return str_repeat( 'a', $length - 4 ) . sprintf( '%04d', ++$GLOBALS['ewpa_token_seq'] );
}

require dirname( __DIR__ ) . '/includes/code-snippets.php';

/*
 * ==========================================================================
 * HARNESS
 * ==========================================================================
 */

$ewpa_failures = 0;
$ewpa_checks   = 0;

function ewpa_check( string $label, bool $condition ): void {
	global $ewpa_failures, $ewpa_checks;
	++$ewpa_checks;
	if ( $condition ) {
		echo 'PASS ' . $label . PHP_EOL;
	} else {
		++$ewpa_failures;
		echo 'FAIL ' . $label . PHP_EOL;
	}
}

/**
 * Resets the doubles and stores one inactive PHP snippet (ID 7).
 *
 * @return Code_Snippets\Model\Snippet
 */
function ewpa_reset(): Code_Snippets\Model\Snippet {
	$GLOBALS['ewpa_options']              = array();
	$GLOBALS['ewpa_actions']              = array();
	$GLOBALS['ewpa_cs_activated']         = array();
	$GLOBALS['ewpa_cs_refuse_activation'] = false;
	$GLOBALS['ewpa_cs_next_id']           = 7;
	$GLOBALS['ewpa_token_seq']            = 0;

	$snippet        = new Code_Snippets\Model\Snippet();
	$snippet->id    = 7;
	$snippet->name  = 'Marker';
	$snippet->code  = "add_filter( 'ewpa_marker', '__return_true' );";
	$snippet->scope = 'front-end';

	$GLOBALS['ewpa_cs_snippets'] = array( 7 => $snippet );

	return $snippet;
}

/*
 * ==========================================================================
 * CASES
 * ==========================================================================
 */

// Scope normalization: Code Snippets stores "front-end"; "frontend" silently became "global".
ewpa_check( 'frontend alias maps to front-end', 'front-end' === ewpa_snippets_normalize_scope( 'frontend' ) );
ewpa_check( 'front-end is kept', 'front-end' === ewpa_snippets_normalize_scope( 'front-end' ) );
ewpa_check( 'scope is case-insensitive', 'admin' === ewpa_snippets_normalize_scope( ' ADMIN ' ) );
ewpa_check( 'single-use is not allowed', '' === ewpa_snippets_normalize_scope( 'single-use' ) );
ewpa_check( 'unknown scope is rejected', '' === ewpa_snippets_normalize_scope( 'everywhere' ) );

// PHP validation.
ewpa_check( 'valid code passes', true === ewpa_snippets_validate_php( "add_filter( 'x', '__return_true' );" ) );
$ewpa_syntax = ewpa_snippets_validate_php( 'function (' );
ewpa_check( 'syntax error is rejected', is_wp_error( $ewpa_syntax ) && 'syntax_error' === $ewpa_syntax->get_error_code() );
$ewpa_blocked = ewpa_snippets_validate_php( "shell_exec( 'id' );" );
ewpa_check( 'blocked function is rejected', is_wp_error( $ewpa_blocked ) && 'blocked_function' === $ewpa_blocked->get_error_code() );
ewpa_check( 'blocked function match is case-insensitive', is_wp_error( ewpa_snippets_validate_php( "EVAL( '1' );" ) ) );

// API detection uses the namespaced 3.x functions.
ewpa_check( 'Code Snippets 3.x API is detected', ewpa_snippets_available() );

// Summary never leaks code unless asked.
$ewpa_snippet = ewpa_reset();
ewpa_check( 'summary without code omits it', ! array_key_exists( 'code', ewpa_snippets_summarize( $ewpa_snippet, false ) ) );
ewpa_check( 'summary with code includes it', $ewpa_snippet->code === ewpa_snippets_summarize( $ewpa_snippet, true )['code'] );

// Approve: activates and consumes the request.
$ewpa_snippet = ewpa_reset();
$ewpa_request = ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
ewpa_check( 'request has a token', '' !== $ewpa_request['token'] );
ewpa_check( 'confirmation URL points to the settings page', false !== strpos( ewpa_snippets_confirmation_url( $ewpa_request['token'] ), 'page=ewpa-settings&ewpa_snippet_request=' ) );
ewpa_check( 'requesting does not activate', false === $ewpa_snippet->active && array() === $GLOBALS['ewpa_cs_activated'] );
$ewpa_outcome = ewpa_snippets_decide_activation( $ewpa_request['token'], 'approve', 1 );
ewpa_check( 'approval activates the snippet', is_array( $ewpa_outcome ) && 'activated' === $ewpa_outcome['status'] && array( 7 ) === $GLOBALS['ewpa_cs_activated'] );
ewpa_check( 'approved request is consumed', array() === ewpa_snippets_requests() );
$ewpa_replay = ewpa_snippets_decide_activation( $ewpa_request['token'], 'approve', 1 );
ewpa_check( 'a consumed token cannot be replayed', is_wp_error( $ewpa_replay ) && 'request_not_found' === $ewpa_replay->get_error_code() );

// Reusing an open request for the same code.
$ewpa_snippet = ewpa_reset();
$ewpa_first   = ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
$ewpa_second  = ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
ewpa_check( 'same snippet and code reuse the open request', $ewpa_first['token'] === $ewpa_second['token'] && 1 === count( ewpa_snippets_requests() ) );

// Code changed after the request: refused and cancelled.
$ewpa_snippet       = ewpa_reset();
$ewpa_request       = ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
$ewpa_snippet->code = "add_filter( 'ewpa_marker', '__return_false' );";
$ewpa_outcome       = ewpa_snippets_decide_activation( $ewpa_request['token'], 'approve', 1 );
ewpa_check( 'changed code blocks approval', is_wp_error( $ewpa_outcome ) && 'code_changed' === $ewpa_outcome->get_error_code() );
ewpa_check( 'changed code does not activate', array() === $GLOBALS['ewpa_cs_activated'] );
ewpa_check( 'changed code cancels the request', array() === ewpa_snippets_requests() );

// Code that now contains a blocked function (same hash is impossible, so simulate a stale request).
$ewpa_snippet = ewpa_reset();
$ewpa_snippet->code = "passthru( 'id' );";
$GLOBALS['ewpa_options'][ EWPA_SNIPPET_REQUESTS_OPTION ] = array(
	'tok-blocked' => array(
		'snippet_id'   => 7,
		'code_hash'    => hash( 'sha256', $ewpa_snippet->code ),
		'requested_by' => 1,
		'requested_at' => time(),
		'expires_at'   => time() + 60,
	),
);
$ewpa_outcome = ewpa_snippets_decide_activation( 'tok-blocked', 'approve', 1 );
ewpa_check( 'blocked function is re-checked at approval', is_wp_error( $ewpa_outcome ) && 'blocked_function' === $ewpa_outcome->get_error_code() && array() === $GLOBALS['ewpa_cs_activated'] );

// Expired requests are ignored and pruned.
$ewpa_snippet = ewpa_reset();
$GLOBALS['ewpa_options'][ EWPA_SNIPPET_REQUESTS_OPTION ] = array(
	'tok-old' => array(
		'snippet_id'   => 7,
		'code_hash'    => hash( 'sha256', $ewpa_snippet->code ),
		'requested_by' => 1,
		'requested_at' => time() - 90000,
		'expires_at'   => time() - 10,
	),
);
$ewpa_outcome = ewpa_snippets_decide_activation( 'tok-old', 'approve', 1 );
ewpa_check( 'expired request cannot be approved', is_wp_error( $ewpa_outcome ) && 'request_not_found' === $ewpa_outcome->get_error_code() && array() === $GLOBALS['ewpa_cs_activated'] );
ewpa_check( 'expired request is pruned from storage', array() === get_option( EWPA_SNIPPET_REQUESTS_OPTION ) );

// Reject: nothing is activated.
$ewpa_snippet = ewpa_reset();
$ewpa_request = ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
$ewpa_outcome = ewpa_snippets_decide_activation( $ewpa_request['token'], 'reject', 1 );
ewpa_check( 'rejection does not activate', is_array( $ewpa_outcome ) && 'rejected' === $ewpa_outcome['status'] && array() === $GLOBALS['ewpa_cs_activated'] );
ewpa_check( 'rejected request is consumed', array() === ewpa_snippets_requests() );

// Unknown or empty tokens.
ewpa_reset();
ewpa_check( 'empty token is refused', is_wp_error( ewpa_snippets_decide_activation( '', 'approve', 1 ) ) );
ewpa_check( 'unknown token is refused', is_wp_error( ewpa_snippets_decide_activation( 'nope', 'approve', 1 ) ) );

// Non-PHP and trashed snippets cannot be activated this way.
$ewpa_snippet       = ewpa_reset();
$ewpa_request       = ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
$ewpa_snippet->type = 'css';
$ewpa_outcome       = ewpa_snippets_decide_activation( $ewpa_request['token'], 'approve', 1 );
ewpa_check( 'non-PHP snippet is refused', is_wp_error( $ewpa_outcome ) && 'not_activatable' === $ewpa_outcome->get_error_code() );
$ewpa_snippet          = ewpa_reset();
$ewpa_request          = ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
$ewpa_snippet->trashed = true;
$ewpa_outcome          = ewpa_snippets_decide_activation( $ewpa_request['token'], 'approve', 1 );
ewpa_check( 'trashed snippet is refused', is_wp_error( $ewpa_outcome ) && 'not_activatable' === $ewpa_outcome->get_error_code() );

// Code Snippets refusing activation surfaces its message and keeps the request.
$ewpa_snippet                         = ewpa_reset();
$ewpa_request                         = ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
$GLOBALS['ewpa_cs_refuse_activation'] = true;
$ewpa_outcome                         = ewpa_snippets_decide_activation( $ewpa_request['token'], 'approve', 1 );
ewpa_check( 'Code Snippets refusal becomes an error', is_wp_error( $ewpa_outcome ) && 'activation_failed' === $ewpa_outcome->get_error_code() );

// The request list stays bounded.
$ewpa_snippet = ewpa_reset();
for ( $ewpa_i = 0; $ewpa_i < EWPA_SNIPPET_MAX_REQUESTS + 5; $ewpa_i++ ) {
	$ewpa_snippet->code = "add_filter( 'ewpa_marker_{$ewpa_i}', '__return_true' );";
	ewpa_snippets_create_activation_request( $ewpa_snippet, 1 );
}
ewpa_check( 'request list is capped', EWPA_SNIPPET_MAX_REQUESTS === count( ewpa_snippets_requests() ) );

echo PHP_EOL;
if ( $ewpa_failures ) {
	echo $ewpa_failures . ' of ' . $ewpa_checks . ' check(s) failed.' . PHP_EOL;
	exit( 1 );
}
echo $ewpa_checks . ' check(s) passed.' . PHP_EOL;
