<?php
/**
 * Covers the seven key migrations that used to run on every request.
 *
 * Reported by Peter Brejassou against 2.13.5: `ewpa_maybe_migrate_keys_v251` through
 * `_v281` were hooked on `plugins_loaded` but never read or wrote their
 * `ewpa_keys_migrated_*` flag, so they re-added ten abilities on every page load. An
 * administrator who turned one of them off got it back on the next request, for good.
 *
 * Giving them the guard the other migrations already have is only half the fix: without
 * the flag, each one would still run one final time on the upgrade that introduces the
 * guard, and that single run would undo the very choice this fix exists to protect. So
 * `ewpa_settle_unflagged_key_migrations()` records the flags as done on installs whose
 * recorded version already passed the release that introduced each migration.
 *
 * This file loads the real functions out of the shipped plugin file rather than copying
 * them, and drives them through one simulated page load at a time. It exercises both
 * orders in which those migrations can run: their own `plugins_loaded` hooks, and
 * `ewpa_run_migrations()` on `wp_abilities_api_init`, which can fire first.
 *
 * Run: php tests/key-migrations-test.php
 *
 * A second argument points it at another copy of the plugin file, which is how the
 * pre-fix behaviour was observed:
 *
 *     php tests/key-migrations-test.php /path/to/an/older/enable-abilities-for-mcp.php
 */

$ewpa_source = $argv[1] ?? dirname( __DIR__ ) . '/enable-abilities-for-mcp.php';

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

// ─── WordPress doubles ───────────────────────────────────────────────────────

$GLOBALS['ewpa_options'] = array();

// Counts the calls the upgrade routine makes besides the migrations, so the behaviour
// that was already there can be checked alongside the new behaviour.
$GLOBALS['ewpa_table_calls'] = 0;

/**
 * @param string $option  Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( $option, $default = false ) {
	return array_key_exists( $option, $GLOBALS['ewpa_options'] ) ? $GLOBALS['ewpa_options'][ $option ] : $default;
}

/**
 * @param string $option Option name.
 * @param mixed  $value  New value.
 * @return bool
 */
function update_option( $option, $value ) {
	$GLOBALS['ewpa_options'][ $option ] = $value;
	return true;
}

/**
 * Stands in for the activity-log schema call the upgrade routine makes.
 *
 * @return void
 */
function ewpa_create_activity_log_table() {
	++$GLOBALS['ewpa_table_calls'];
}

// ─── The functions under test, taken from the shipped file ──────────────────

if ( ! is_readable( $ewpa_source ) ) {
	echo "FAIL cannot read $ewpa_source\n";
	exit( 1 );
}

$ewpa_plugin_source = (string) file_get_contents( $ewpa_source );

if ( ! preg_match( "/define\(\s*'EWPA_VERSION',\s*'([^']+)'/", $ewpa_plugin_source, $ewpa_matches ) ) {
	echo "FAIL cannot read EWPA_VERSION out of $ewpa_source\n";
	exit( 1 );
}

define( 'EWPA_VERSION', $ewpa_matches[1] );
define( 'EWPA_OPTION_KEY', 'ewpa_enabled_abilities' );
define( 'EWPA_API_KEY_OPTION', 'ewpa_api_key' );

/**
 * Pulls one top-level function out of the plugin source and defines it here.
 *
 * Copying these bodies into the test would mean checking a copy instead of the code that
 * ships, and this fix is precisely about what the shipped file does on every request.
 *
 * @param string $source Plugin file contents.
 * @param string $name   Function name.
 * @return bool Whether it was found and defined.
 */
function ewpa_define_from_source( string $source, string $name ): bool {
	$pattern = '/^function ' . preg_quote( $name, '/' ) . '\s*\(.*?^\}/ms';

	if ( ! preg_match( $pattern, $source, $matches ) ) {
		return false;
	}

	eval( $matches[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- loads the shipped function instead of a copy of it.

	return function_exists( $name );
}

// The order the seven are hooked in, which is the order plugins_loaded runs them.
$ewpa_flags = array( 'v251', 'v260', 'v270', 'v271', 'v272', 'v280', 'v281' );

// What each one adds. The version that introduced it is read from the plugin instead,
// below: restating it here would only check the plugin against a copy of its own
// assumption, which is how two wrong versions survived the first round of this test.
$ewpa_abilities = array(
	'v251' => array( 'ewpa/duplicate-post' ),
	'v260' => array( 'ewpa/fse-list-templates', 'ewpa/fse-get-template' ),
	'v270' => array( 'ewpa/get-term-meta', 'ewpa/update-term-meta' ),
	'v271' => array( 'ewpa/update-term' ),
	'v272' => array( 'ewpa/je-list-queries', 'ewpa/je-get-query' ),
	'v280' => array( 'ewpa/get-accessibility-snapshot' ),
	'v281' => array( 'ewpa/assign-post-terms' ),
);

$ewpa_all_abilities = array();

foreach ( $ewpa_abilities as $ewpa_set ) {
	$ewpa_all_abilities = array_merge( $ewpa_all_abilities, $ewpa_set );
}

echo "== the functions load out of the shipped file ==\n";

// The seven, the upgrade routine, the settle call, and the second entry point with the
// two already-flagged migrations it also runs.
$ewpa_wanted = array( 'ewpa_maybe_upgrade', 'ewpa_settle_unflagged_key_migrations', 'ewpa_run_migrations', 'ewpa_maybe_migrate_keys_v2100', 'ewpa_maybe_migrate_keys_v2110' );

foreach ( $ewpa_flags as $ewpa_flag ) {
	$ewpa_wanted[] = 'ewpa_maybe_migrate_keys_' . $ewpa_flag;
}

foreach ( $ewpa_wanted as $ewpa_name ) {
	$ewpa_found = ewpa_define_from_source( $ewpa_plugin_source, $ewpa_name );

	ewpa_check( 'loaded_' . $ewpa_name, $ewpa_found, 'not found in ' . basename( $ewpa_source ) );

	// A missing function must not stop the run: on a copy that predates the fix, the
	// re-add checks below are the point of the exercise.
	if ( ! $ewpa_found && ! function_exists( $ewpa_name ) ) {
		eval( 'function ' . $ewpa_name . '() {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- keeps the remaining checks runnable.
	}
}

// ─── Where settling is wired in ─────────────────────────────────────────────
//
// ewpa_load() below calls the settle function itself, the way plugins_loaded would, so
// it cannot notice a plugin that forgot to hook it. These two read the wiring instead.

echo "\n== settling runs before the migrations on both paths ==\n";

if ( preg_match( "/add_action\(\s*'plugins_loaded',\s*'ewpa_settle_unflagged_key_migrations'\s*,\s*(\d+)\s*\)/", $ewpa_plugin_source, $ewpa_matches ) ) {
	$ewpa_priority = (int) $ewpa_matches[1];

	// The seven are hooked at the default priority, and so is the upgrade routine that
	// overwrites the version settling is judged against.
	ewpa_check(
		'settling_is_hooked_before_the_default_priority',
		$ewpa_priority < 10,
		'hooked at priority ' . $ewpa_priority
	);
} else {
	ewpa_check( 'settling_is_hooked_before_the_default_priority', false, 'no plugins_loaded hook for it at all' );
}

// The lazy path: ewpa_run_migrations() has to settle before it calls the first migration.
$ewpa_runner_body = '';

if ( preg_match( '/^function ewpa_run_migrations\s*\(.*?^\}/ms', $ewpa_plugin_source, $ewpa_matches ) ) {
	$ewpa_runner_body = $ewpa_matches[0];
}

$ewpa_settle_at = strpos( $ewpa_runner_body, 'ewpa_settle_unflagged_key_migrations(' );
$ewpa_first_at  = strpos( $ewpa_runner_body, 'ewpa_maybe_migrate_keys_' );

ewpa_check(
	'the_abilities_runner_settles_before_its_first_migration',
	false !== $ewpa_settle_at && false !== $ewpa_first_at && $ewpa_settle_at < $ewpa_first_at,
	false === $ewpa_settle_at ? 'it never settles' : 'it settles after migration ' . ( $ewpa_first_at > 0 ? 'calls start' : 'unknown' )
);

// ─── Which release introduced each migration ────────────────────────────────

echo "\n== the release each migration first shipped in ==\n";

/*
 * Read out of the plugin, then pinned against the published tags. Those tags are the
 * only authority on this: a migration's name is not. Checked against
 * E:\Workspace\svn-enable-abilities-for-mcp\tags, where every release lives:
 *
 *   tag 2.4.0  has none of the seven
 *   tag 2.5.0  already has v251          <- not 2.5.1, although that is its name
 *   tag 2.6.0  adds v260
 *   tag 2.7.0  adds v270
 *   tag 2.7.1  does not exist; it was never released
 *   tag 2.7.2  adds v271 and v272        <- v271 is named after a release that never was
 *   tag 2.8.0  adds v280
 *   tag 2.8.1  adds v281
 *
 * A migration settled one release too late costs an administrator one unwanted
 * re-enable. One settled too early is worse: its ability never gets added at all.
 */
$ewpa_expected_versions = array(
	'v251' => '2.5.0',
	'v260' => '2.6.0',
	'v270' => '2.7.0',
	'v271' => '2.7.2',
	'v272' => '2.7.2',
	'v280' => '2.8.0',
	'v281' => '2.8.1',
);

$ewpa_settle_body = '';

if ( preg_match( '/^function ewpa_settle_unflagged_key_migrations\s*\(.*?^\}/ms', $ewpa_plugin_source, $ewpa_matches ) ) {
	$ewpa_settle_body = $ewpa_matches[0];
}

$ewpa_versions = array();

if ( preg_match_all( "/'(v\d+)'\s*=>\s*'([^']+)'/", $ewpa_settle_body, $ewpa_matches, PREG_SET_ORDER ) ) {
	foreach ( $ewpa_matches as $ewpa_pair ) {
		$ewpa_versions[ $ewpa_pair[1] ] = $ewpa_pair[2];
	}
}

ewpa_check(
	'the_plugin_maps_all_seven_migrations_to_a_release',
	array_keys( $ewpa_versions ) === $ewpa_flags,
	'found ' . ( array() === $ewpa_versions ? 'nothing' : implode( ', ', array_keys( $ewpa_versions ) ) )
);

foreach ( $ewpa_expected_versions as $ewpa_flag => $ewpa_expected ) {
	ewpa_check(
		$ewpa_flag . '_is_dated_to_the_release_that_shipped_it',
		( $ewpa_versions[ $ewpa_flag ] ?? '' ) === $ewpa_expected,
		'plugin says ' . var_export( $ewpa_versions[ $ewpa_flag ] ?? null, true ) . ', tags say ' . $ewpa_expected
	);
}

// Everything below derives from what the plugin says, so a behaviour check cannot agree
// with a wrong constant: the pin above is what fails in that case.
$ewpa_versions = $ewpa_versions + $ewpa_expected_versions;

/**
 * The abilities a site recorded at $version has not received yet.
 *
 * @param string $version Recorded ewpa_db_version.
 * @return array
 */
function ewpa_still_due( string $version ): array {
	global $ewpa_versions, $ewpa_abilities;

	$due = array();

	foreach ( $ewpa_versions as $flag => $introduced_in ) {
		if ( version_compare( $version, $introduced_in, '<' ) ) {
			$due = array_merge( $due, $ewpa_abilities[ $flag ] );
		}
	}

	return $due;
}

// ─── One simulated page load ────────────────────────────────────────────────

/**
 * Runs one simulated page load against a given set of stored options.
 *
 * @param array  $options Stored options at the start of the request.
 * @param string $order   'hooks' for the plugins_loaded order, 'lazy' when
 *                        wp_abilities_api_init fires before plugins_loaded gets there.
 * @return array Stored options at the end of it.
 */
function ewpa_load( array $options, string $order = 'hooks' ): array {
	global $ewpa_flags;

	$GLOBALS['ewpa_options'] = $options;

	// wp_abilities_api_init is fired lazily by the registry, so a plugin that touches it
	// early gets the migrations before plugins_loaded reaches us.
	if ( 'lazy' === $order ) {
		ewpa_run_migrations();
	}

	// plugins_loaded: settle at priority 1, then the upgrade routine and the seven at the
	// default priority, in registration order.
	ewpa_settle_unflagged_key_migrations();
	ewpa_maybe_upgrade();

	foreach ( $ewpa_flags as $flag ) {
		call_user_func( 'ewpa_maybe_migrate_keys_' . $flag );
	}

	return $GLOBALS['ewpa_options'];
}

/**
 * Stored options for a site on $version with $enabled turned on.
 *
 * @param string $version Recorded ewpa_db_version.
 * @param array  $enabled Enabled ability keys.
 * @return array
 */
function ewpa_site( string $version, array $enabled ): array {
	return array(
		'ewpa_db_version'        => $version,
		'ewpa_enabled_abilities' => $enabled,
	);
}

// ─── The reported bug ────────────────────────────────────────────────────────
//
// 2.13.0 is past every one of the seven: a site that recorded it has run all of them
// thousands of times, so anything missing from its list was removed on purpose.

echo "\n== an administrator's choice survives the upgrade ==\n";

$ewpa_without_duplicate = array_values( array_diff( $ewpa_all_abilities, array( 'ewpa/duplicate-post' ) ) );
$ewpa_after             = ewpa_load( ewpa_site( '2.13.0', $ewpa_without_duplicate ) );

ewpa_check(
	'a_disabled_ability_is_not_re_added',
	! in_array( 'ewpa/duplicate-post', $ewpa_after['ewpa_enabled_abilities'], true ),
	'ewpa/duplicate-post came back'
);

ewpa_check(
	'nothing_else_was_added_either',
	count( $ewpa_after['ewpa_enabled_abilities'] ) === count( $ewpa_without_duplicate ),
	'list went from ' . count( $ewpa_without_duplicate ) . ' to ' . count( $ewpa_after['ewpa_enabled_abilities'] )
);

// Each of the seven, so a migration that keeps the old shape cannot hide behind the six
// that were fixed.
foreach ( $ewpa_abilities as $ewpa_flag => $ewpa_set ) {
	$ewpa_reduced = array_values( array_diff( $ewpa_all_abilities, $ewpa_set ) );
	$ewpa_after   = ewpa_load( ewpa_site( '2.13.0', $ewpa_reduced ) );
	$ewpa_back    = array_values( array_intersect( $ewpa_after['ewpa_enabled_abilities'], $ewpa_set ) );

	ewpa_check(
		$ewpa_flag . '_does_not_re_add_its_abilities',
		array() === $ewpa_back,
		're-added ' . implode( ', ', $ewpa_back )
	);
}

echo "\n== the same, whichever hook gets there first ==\n";

// ewpa_run_migrations() on wp_abilities_api_init is a second entry point into all seven,
// and it can run before plugins_loaded. If settling only happened on the plugins_loaded
// side, this is the order that would still re-enable the ability — once, and for good,
// because each migration records its flag on the way out.
$ewpa_after = ewpa_load( ewpa_site( '2.13.0', $ewpa_without_duplicate ), 'lazy' );

ewpa_check(
	'the_lazy_abilities_hook_does_not_re_add_either',
	! in_array( 'ewpa/duplicate-post', $ewpa_after['ewpa_enabled_abilities'], true ),
	'ewpa/duplicate-post came back when wp_abilities_api_init ran first'
);

echo "\n== an install already on the current version ==\n";

// ewpa_maybe_upgrade() returns early when the recorded version is already current, so
// anything that only ran from inside it would never run on a site in this state — which
// is every site already running the release that carries the bug.
$ewpa_after = ewpa_load(
	array(
		'ewpa_db_version'        => EWPA_VERSION,
		'ewpa_enabled_abilities' => $ewpa_without_duplicate,
	)
);

ewpa_check(
	'a_current_install_with_no_flags_does_not_re_add',
	! in_array( 'ewpa/duplicate-post', $ewpa_after['ewpa_enabled_abilities'], true ),
	'ewpa/duplicate-post came back on an install already at ' . EWPA_VERSION
);

echo "\n== two loads in a row change nothing ==\n";

$ewpa_first  = ewpa_load( ewpa_site( '2.13.0', $ewpa_without_duplicate ) );
$ewpa_second = ewpa_load( $ewpa_first );

ewpa_check(
	'the_second_load_does_not_re_add',
	! in_array( 'ewpa/duplicate-post', $ewpa_second['ewpa_enabled_abilities'], true ),
	'ewpa/duplicate-post came back on the second load'
);

ewpa_check(
	'the_second_load_leaves_the_options_identical',
	$ewpa_second === $ewpa_first,
	'options changed between two identical loads'
);

// ─── A genuine upgrade must still add ────────────────────────────────────────

echo "\n== a real upgrade still adds what it should ==\n";

// Three published releases, each on a different side of the seven. What is due at each
// one comes from the plugin's own table, so these check behaviour and the pin above
// checks the table.
foreach ( array( '2.4.0', '2.5.0', '2.7.0' ) as $ewpa_version ) {
	$ewpa_due   = ewpa_still_due( $ewpa_version );
	$ewpa_label = str_replace( '.', '_', $ewpa_version );
	$ewpa_after = ewpa_load( ewpa_site( $ewpa_version, array( 'ewpa/get-posts' ) ) );
	$ewpa_added = array_values( array_diff( $ewpa_after['ewpa_enabled_abilities'], array( 'ewpa/get-posts' ) ) );

	sort( $ewpa_due );
	sort( $ewpa_added );

	ewpa_check(
		'an_install_from_' . $ewpa_label . '_gets_exactly_what_it_missed',
		$ewpa_due === $ewpa_added,
		count( $ewpa_due ) . ' due, got ' . count( $ewpa_added ) . ': ' . implode( ', ', array_diff( $ewpa_due, $ewpa_added ) ) . ' missing, ' . implode( ', ', array_diff( $ewpa_added, $ewpa_due ) ) . ' unexpected'
	);

	ewpa_check(
		'the_pre_existing_ability_is_kept_from_' . $ewpa_label,
		in_array( 'ewpa/get-posts', $ewpa_after['ewpa_enabled_abilities'], true ),
		'ewpa/get-posts was dropped'
	);
}

echo "\n== an install with no recorded version ==\n";

// Nothing to settle: ewpa_db_version has been written since 2.0.1, so no recorded
// version means the install predates it or is brand new. The migrations run normally.
$ewpa_after   = ewpa_load( array( 'ewpa_enabled_abilities' => array() ) );
$ewpa_missing = array_values( array_diff( $ewpa_all_abilities, $ewpa_after['ewpa_enabled_abilities'] ) );

ewpa_check(
	'an_install_with_no_recorded_version_gets_all_ten',
	array() === $ewpa_missing,
	'missing ' . implode( ', ', $ewpa_missing )
);

echo "\n== every migration records its flag ==\n";

// Whichever path a load took, no migration may be left unflagged: an unflagged one is
// exactly the bug that was reported.
foreach ( array( '2.13.0', '2.7.0', '2.4.0' ) as $ewpa_version ) {
	$ewpa_after     = ewpa_load( ewpa_site( $ewpa_version, $ewpa_all_abilities ) );
	$ewpa_unflagged = array();

	foreach ( $ewpa_flags as $ewpa_flag ) {
		if ( empty( $ewpa_after[ 'ewpa_keys_migrated_' . $ewpa_flag ] ) ) {
			$ewpa_unflagged[] = $ewpa_flag;
		}
	}

	ewpa_check(
		'all_seven_are_flagged_coming_from_' . str_replace( '.', '_', $ewpa_version ),
		array() === $ewpa_unflagged,
		'unflagged: ' . implode( ', ', $ewpa_unflagged )
	);
}

echo "\n== a stored list that is not an array ==\n";

// update_option() can leave a scalar behind, and a migration that trusted it would warn
// on in_array(). Each one has to flag itself and leave the option alone.
$ewpa_after = ewpa_load(
	array(
		'ewpa_db_version'        => '2.4.0',
		'ewpa_enabled_abilities' => false,
	)
);

ewpa_check(
	'a_non_array_list_is_left_alone',
	false === $ewpa_after['ewpa_enabled_abilities'],
	'option became ' . var_export( $ewpa_after['ewpa_enabled_abilities'], true )
);

$ewpa_unflagged = array();

foreach ( $ewpa_flags as $ewpa_flag ) {
	if ( empty( $ewpa_after[ 'ewpa_keys_migrated_' . $ewpa_flag ] ) ) {
		$ewpa_unflagged[] = $ewpa_flag;
	}
}

ewpa_check(
	'a_non_array_list_still_flags_all_seven',
	array() === $ewpa_unflagged,
	'unflagged: ' . implode( ', ', $ewpa_unflagged )
);

// ─── What ewpa_maybe_upgrade() already did ───────────────────────────────────
//
// The settle call used to live in that function and no longer does, so its existing
// behaviour is checked here as well: the early return, the schema call, the version
// write, and the bearer flag.

echo "\n== the upgrade routine still does what it did ==\n";

$GLOBALS['ewpa_table_calls'] = 0;

$ewpa_after = ewpa_load( ewpa_site( '2.4.0', $ewpa_all_abilities ) );

ewpa_check(
	'the_version_is_recorded',
	EWPA_VERSION === $ewpa_after['ewpa_db_version'],
	'recorded ' . var_export( $ewpa_after['ewpa_db_version'], true )
);

ewpa_check(
	'the_activity_log_table_is_created_once',
	1 === $GLOBALS['ewpa_table_calls'],
	$GLOBALS['ewpa_table_calls'] . ' calls'
);

// Already current: nothing in the routine may run again.
$GLOBALS['ewpa_table_calls'] = 0;

ewpa_load( ewpa_site( EWPA_VERSION, $ewpa_all_abilities ) );

ewpa_check(
	'an_up_to_date_install_skips_the_routine',
	0 === $GLOBALS['ewpa_table_calls'],
	$GLOBALS['ewpa_table_calls'] . ' calls on a current version'
);

// An install that already has an API key gets the Bearer switch turned on for it.
$ewpa_after = ewpa_load(
	array(
		'ewpa_db_version'        => '2.4.0',
		'ewpa_enabled_abilities' => $ewpa_all_abilities,
		'ewpa_api_key'           => 'a-key',
	)
);

ewpa_check(
	'an_install_with_an_api_key_gets_bearer_enabled',
	! empty( $ewpa_after['ewpa_bearer_enabled'] ),
	'ewpa_bearer_enabled is ' . var_export( $ewpa_after['ewpa_bearer_enabled'] ?? null, true )
);

// Whether an administrator who turned that switch off keeps it off is not checked here:
// the routine tells "never set" from "set to false" through get_option(), and real
// WordPress returns '' for a stored false while this double returns the false itself. A
// check written against the double would pass or fail for the wrong reason, and the
// switch is not what this fix touches.

echo "\n$ewpa_pass passed, $ewpa_fail failed.\n";
exit( $ewpa_fail > 0 ? 1 : 0 );
