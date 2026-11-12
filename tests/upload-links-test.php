<?php
/**
 * Covers upload links (includes/upload-links.php).
 *
 * The link token is the only credential on the upload route, so most of this file is
 * about what a token can and cannot do: it is stored only as a hash, works once, for
 * a few minutes, for a user who can still upload files, and only for images within
 * the size cap. A failed upload must leave the link usable; a concurrent second upload
 * must not store a second file.
 *
 * This loads the real includes/upload-links.php against WordPress doubles.
 *
 * Run: php tests/upload-links-test.php
 */

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
 * json_encode for failure messages.
 *
 * @param mixed $value Value.
 * @return string
 */
function ewpa_show( $value ): string {
	if ( $value instanceof WP_Error ) {
		return 'WP_Error(' . $value->get_error_code() . ', ' . json_encode( $value->get_error_data() ) . ')'; // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test output only.
	}
	return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test output only.
}

// ─── WordPress doubles ───────────────────────────────────────────────────────

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['ewpa_filters']    = array();
$GLOBALS['ewpa_caps']       = array(); // user_id => can upload_files.
$GLOBALS['ewpa_users']      = array(); // user_id => true when the account exists.
$GLOBALS['ewpa_current']    = 0;
$GLOBALS['ewpa_sideloads']  = array(); // Calls to media_handle_sideload().
$GLOBALS['ewpa_sideload_fn'] = null;   // Optional override for its result.
$GLOBALS['ewpa_type_hook']  = null;    // Runs inside wp_check_filetype_and_ext().
$GLOBALS['ewpa_meta']       = array();
$GLOBALS['ewpa_posts']      = array();
$GLOBALS['ewpa_logs']       = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

/**
 * Options table double. Values are stored serialized, as WordPress does.
 */
class EWPA_Fake_WPDB {
	public $options = 'wp_options';
	public $rows    = array();

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . str_replace( "'", "\\'", (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}
	public function get_var( $query ) {
		if ( preg_match( "/option_name = '([^']*)'/", $query, $m ) ) {
			return $this->rows[ $m[1] ] ?? null;
		}
		return null;
	}
	public function get_results( $query ) {
		$out = array();
		if ( preg_match( "/option_name LIKE '([^']*)'/", $query, $m ) ) {
			$prefix = stripslashes( rtrim( $m[1], '%' ) );
			foreach ( $this->rows as $name => $value ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$out[] = (object) array(
						'option_name'  => $name,
						'option_value' => $value,
					);
				}
			}
		}
		return $out;
	}
	public function delete( $table, $where ) {
		$name = $where['option_name'];
		if ( ! array_key_exists( $name, $this->rows ) ) {
			return 0;
		}
		unset( $this->rows[ $name ] );
		return 1;
	}
}
$GLOBALS['wpdb'] = new EWPA_Fake_WPDB();

function add_action( ...$args ) {}
function __( $text ) {
	return $text;
}
function apply_filters( $hook, $value ) {
	return isset( $GLOBALS['ewpa_filters'][ $hook ] ) ? $GLOBALS['ewpa_filters'][ $hook ]( $value ) : $value;
}
function wp_max_upload_size() {
	return $GLOBALS['ewpa_site_max'] ?? 1000;
}
function maybe_unserialize( $value ) {
	return is_string( $value ) ? unserialize( $value ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test double.
}
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['wpdb']->rows ) ) {
		return false;
	}
	$GLOBALS['wpdb']->rows[ $name ] = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test double.
	return true;
}
function wp_cache_delete( ...$args ) {
	return true;
}
function user_can( $user_id, $cap ) {
	return 'upload_files' === $cap && ! empty( $GLOBALS['ewpa_caps'][ $user_id ] );
}
function current_user_can( $cap ) {
	return user_can( $GLOBALS['ewpa_current'], $cap );
}
function get_userdata( $user_id ) {
	return empty( $GLOBALS['ewpa_users'][ $user_id ] ) ? false : (object) array( 'ID' => $user_id );
}
function get_current_user_id() {
	return $GLOBALS['ewpa_current'];
}
function wp_set_current_user( $user_id ) {
	$GLOBALS['ewpa_current'] = (int) $user_id;
}
function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test double.
}
function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $name );
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_delete_file( $file ) {
	if ( file_exists( $file ) ) {
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test double.
	}
}
function rest_url( $path ) {
	return 'https://example.test/wp-json/' . $path;
}
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}
function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
}
/**
 * Accepts by extension and by content: a file must start with the PNG or JPEG signature.
 */
function wp_check_filetype_and_ext( $file, $filename, $mimes ) {
	if ( $GLOBALS['ewpa_type_hook'] ) {
		( $GLOBALS['ewpa_type_hook'] )();
	}
	$ext   = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$head  = (string) file_get_contents( $file, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test double.
	$kinds = array(
		'png' => array( "\x89PNG", 'image/png' ),
		'jpg' => array( "\xFF\xD8\xFF", 'image/jpeg' ),
	);
	foreach ( $kinds as $kind_ext => $kind ) {
		$allowed = false;
		foreach ( $mimes as $pattern => $mime ) {
			if ( in_array( $ext, explode( '|', $pattern ), true ) && $mime === $kind[1] ) {
				$allowed = true;
			}
		}
		if ( $allowed && 0 === strpos( $head, $kind[0] ) ) {
			return array(
				'ext'             => $ext,
				'type'            => $kind[1],
				'proper_filename' => false,
			);
		}
	}
	return array(
		'ext'             => false,
		'type'            => false,
		'proper_filename' => false,
	);
}
function media_handle_sideload( $file, $post_id, $desc = null, $post_data = array() ) {
	$GLOBALS['ewpa_sideloads'][] = array(
		'file'      => $file,
		'as_user'   => $GLOBALS['ewpa_current'],
		'post_data' => $post_data,
	);
	if ( $GLOBALS['ewpa_sideload_fn'] ) {
		return ( $GLOBALS['ewpa_sideload_fn'] )();
	}
	$id                          = 100 + count( $GLOBALS['ewpa_sideloads'] );
	$GLOBALS['ewpa_posts'][ $id ] = array(
		'title' => $post_data['post_title'] ?? $file['name'],
		'mime'  => 'image/png',
	);
	unlink( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- WordPress moves the file into uploads.
	return $id;
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['ewpa_meta'][ $id ][ $key ] = $value;
}
function get_post( $id ) {
	return isset( $GLOBALS['ewpa_posts'][ $id ] ) ? (object) array( 'ID' => $id ) : null;
}
function wp_get_attachment_url( $id ) {
	return "https://example.test/wp-content/uploads/$id.png";
}
function get_post_mime_type( $id ) {
	return $GLOBALS['ewpa_posts'][ $id ]['mime'] ?? '';
}
function get_the_title( $id ) {
	return $GLOBALS['ewpa_posts'][ $id ]['title'] ?? '';
}
function ewpa_log_activity( $user_id, $ability, $status = 'success' ) {
	$GLOBALS['ewpa_logs'][] = array( $user_id, $ability );
}

require dirname( __DIR__ ) . '/includes/upload-links.php';

// ─── Helpers ─────────────────────────────────────────────────────────────────

$ewpa_tmp_dir = sys_get_temp_dir() . '/ewpa-upload-links-' . bin2hex( random_bytes( 4 ) );
mkdir( $ewpa_tmp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test scratch dir, outside the worktree.

/**
 * Writes a temp file holding a file "received" by the endpoint.
 *
 * @param string $bytes Content.
 * @return string Path.
 */
function ewpa_tmp( string $bytes ): string {
	global $ewpa_tmp_dir;
	$path = tempnam( $ewpa_tmp_dir, 'up' );
	file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test scratch file.
	return $path;
}

/**
 * Extracts the token from a created link's upload_url.
 *
 * @param array $link Result of ewpa_upload_link_create().
 * @return string
 */
function ewpa_token_of( array $link ): string {
	preg_match( '#/upload/([a-f0-9]{64})#', $link['upload_url'], $m );
	return $m[1] ?? '';
}

/**
 * Resets all doubles.
 *
 * @return void
 */
function ewpa_reset(): void {
	$GLOBALS['wpdb']->rows        = array();
	$GLOBALS['ewpa_filters']      = array();
	$GLOBALS['ewpa_caps']         = array(
		1 => true,
		2 => true,
		3 => false,
	);
	$GLOBALS['ewpa_users']        = array(
		1 => true,
		2 => true,
		3 => true,
	);
	$GLOBALS['ewpa_current']      = 0;
	$GLOBALS['ewpa_sideloads']    = array();
	$GLOBALS['ewpa_sideload_fn']  = null;
	$GLOBALS['ewpa_type_hook']    = null;
	$GLOBALS['ewpa_meta']         = array();
	$GLOBALS['ewpa_posts']        = array();
	$GLOBALS['ewpa_logs']         = array();
}

$png = "\x89PNG\r\n\x1a\n" . str_repeat( 'x', 40 );

// ─── Creating a link ─────────────────────────────────────────────────────────

ewpa_reset();
$r = ewpa_upload_link_create( 3 );
ewpa_check( 'a user without upload_files cannot create a link', is_wp_error( $r ) && 'forbidden' === $r->get_error_code(), ewpa_show( $r ) );
ewpa_check( 'a refused link stores nothing', array() === $GLOBALS['wpdb']->rows );

ewpa_reset();
$r     = ewpa_upload_link_create( 1, array( 'title' => '<b>Product</b>', 'alt_text' => 'Red shoe' ) );
$token = is_array( $r ) ? ewpa_token_of( $r ) : '';
ewpa_check( 'a link is created for a user who can upload', is_array( $r ) && 64 === strlen( $token ), ewpa_show( $r ) );
ewpa_check( 'upload_id is the hash of the token, not the token', is_array( $r ) && hash( 'sha256', $token ) === $r['upload_id'] && $r['upload_id'] !== $token );
ewpa_check( 'the link is stored under the hash', array( 'ewpa_upload_link_' . hash( 'sha256', $token ) ) === array_keys( $GLOBALS['wpdb']->rows ) );
ewpa_check( 'the token itself is never stored', false === strpos( implode( '', array_keys( $GLOBALS['wpdb']->rows ) ) . implode( '', $GLOBALS['wpdb']->rows ), $token ) );
ewpa_check( 'page_url carries the token for the browser page', is_array( $r ) && 'https://example.test/?ewpa_upload=' . $token === $r['page_url'], is_array( $r ) ? $r['page_url'] : '' );
ewpa_check( 'the link expires after 10 minutes by default', is_array( $r ) && abs( strtotime( $r['expires_at'] ) - ( time() + 600 ) ) <= 2, is_array( $r ) ? $r['expires_at'] : '' );
ewpa_check( 'only image types are offered', is_array( $r ) && array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif' ) === $r['allowed_types'], is_array( $r ) ? ewpa_show( $r['allowed_types'] ) : '' );
ewpa_check( 'title and alt text are sanitized and stored', array( 'Product', 'Red shoe' ) === array( ewpa_upload_link_pending( $token )['title'], ewpa_upload_link_pending( $token )['alt_text'] ) );

ewpa_reset();
$GLOBALS['ewpa_filters']['ewpa_upload_link_ttl'] = function () {
	return 999999;
};
ewpa_check( 'the TTL filter is capped at one hour', 3600 === ewpa_upload_link_ttl() );
$GLOBALS['ewpa_filters']['ewpa_upload_link_ttl'] = function () {
	return 1;
};
ewpa_check( 'the TTL filter cannot go below one minute', 60 === ewpa_upload_link_ttl() );

$GLOBALS['ewpa_site_max'] = 300 * 1048576;
ewpa_check( 'the size cap defaults to 20 MB even when the site allows more', 20 * 1048576 === ewpa_upload_link_max_bytes(), (string) ewpa_upload_link_max_bytes() );
$GLOBALS['ewpa_site_max'] = 2 * 1048576;
ewpa_check( 'a smaller site upload limit wins', 2 * 1048576 === ewpa_upload_link_max_bytes() );
unset( $GLOBALS['ewpa_site_max'] );

// ─── Per-user limit ──────────────────────────────────────────────────────────

ewpa_reset();
for ( $i = 0; $i < 5; $i++ ) {
	ewpa_upload_link_create( 1 );
}
$r = ewpa_upload_link_create( 1 );
ewpa_check( 'a sixth pending link for the same user is refused', is_wp_error( $r ) && 'too_many_links' === $r->get_error_code() && 429 === $r->get_error_data()['status'], ewpa_show( $r ) );
ewpa_check( 'the limit is per user', is_array( ewpa_upload_link_create( 2 ) ) );

foreach ( $GLOBALS['wpdb']->rows as $name => $value ) {
	$record = unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test.
	if ( 1 === $record['user_id'] ) {
		$record['expires']                = time() - 1;
		$GLOBALS['wpdb']->rows[ $name ] = serialize( $record ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test.
	}
}
ewpa_check( 'expired links do not count against the limit', is_array( ewpa_upload_link_create( 1 ) ) );
ewpa_check( 'expired links are deleted when a new one is created', 2 === count( $GLOBALS['wpdb']->rows ), (string) count( $GLOBALS['wpdb']->rows ) );

// ─── Using a link ────────────────────────────────────────────────────────────

ewpa_reset();
$tmp = ewpa_tmp( $png );
$r   = ewpa_upload_link_receive( str_repeat( 'a', 64 ), $tmp, 'x.png' );
ewpa_check( 'an unknown token is refused with 404', is_wp_error( $r ) && 404 === $r->get_error_data()['status'], ewpa_show( $r ) );
ewpa_check( 'a refused upload deletes the temp file', ! file_exists( $tmp ) );
$r = ewpa_upload_link_receive( 'not-a-token', ewpa_tmp( $png ), 'x.png' );
ewpa_check( 'a malformed token is refused with 404', is_wp_error( $r ) && 404 === $r->get_error_data()['status'], ewpa_show( $r ) );

ewpa_reset();
$link  = ewpa_upload_link_create( 1 );
$token = ewpa_token_of( $link );
$name  = 'ewpa_upload_link_' . $link['upload_id'];
$rec   = unserialize( $GLOBALS['wpdb']->rows[ $name ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test.
$rec['expires']                 = time() - 1;
$GLOBALS['wpdb']->rows[ $name ] = serialize( $rec ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Test.
$r = ewpa_upload_link_receive( $token, ewpa_tmp( $png ), 'x.png' );
ewpa_check( 'an expired token is refused with 404', is_wp_error( $r ) && 404 === $r->get_error_data()['status'], ewpa_show( $r ) );
ewpa_check( 'an expired token stores nothing', array() === $GLOBALS['ewpa_sideloads'] );

ewpa_reset();
$link  = ewpa_upload_link_create( 1 );
$token = ewpa_token_of( $link );
$tmp   = ewpa_tmp( str_repeat( 'x', 1001 ) );
$r     = ewpa_upload_link_receive( $token, $tmp, 'big.png' );
ewpa_check( 'a file over the size cap is refused with 413', is_wp_error( $r ) && 413 === $r->get_error_data()['status'], ewpa_show( $r ) );
ewpa_check( 'an oversized file is deleted', ! file_exists( $tmp ) );
ewpa_check( 'after a size failure the link is still usable', null !== ewpa_upload_link_pending( $token ) );

$r = ewpa_upload_link_receive( $token, ewpa_tmp( '' ), 'empty.png' );
ewpa_check( 'an empty body is refused with 400', is_wp_error( $r ) && 400 === $r->get_error_data()['status'], ewpa_show( $r ) );

$r = ewpa_upload_link_receive( $token, ewpa_tmp( '<?php echo 1; ?>' ), 'shell.php' );
ewpa_check( 'a PHP file is refused with 415', is_wp_error( $r ) && 415 === $r->get_error_data()['status'], ewpa_show( $r ) );
$r = ewpa_upload_link_receive( $token, ewpa_tmp( '<?php echo 1; ?>' ), 'fake.png' );
ewpa_check( 'a non-image renamed to .png is refused with 415', is_wp_error( $r ) && 415 === $r->get_error_data()['status'], ewpa_show( $r ) );
$r = ewpa_upload_link_receive( $token, ewpa_tmp( '<svg xmlns="http://www.w3.org/2000/svg"/>' ), 'logo.svg' );
ewpa_check( 'an SVG is refused with 415', is_wp_error( $r ) && 415 === $r->get_error_data()['status'], ewpa_show( $r ) );
ewpa_check( 'refused types store nothing', array() === $GLOBALS['ewpa_sideloads'] );
ewpa_check( 'after a type failure the link is still usable', null !== ewpa_upload_link_pending( $token ) );

// ─── A successful upload ─────────────────────────────────────────────────────

ewpa_reset();
$GLOBALS['ewpa_current'] = 0;
$link                    = ewpa_upload_link_create( 1, array( 'title' => 'Product', 'alt_text' => 'Red shoe' ) );
$token                   = ewpa_token_of( $link );
$r                       = ewpa_upload_link_receive( $token, ewpa_tmp( $png ), 'shoe.png' );
ewpa_check( 'a valid image is stored as an attachment', is_array( $r ) && 101 === $r['attachment_id'] && 'image/png' === $r['mime_type'], ewpa_show( $r ) );
ewpa_check( 'the file is stored as the link owner', 1 === ( $GLOBALS['ewpa_sideloads'][0]['as_user'] ?? null ) );
ewpa_check( 'the request user is restored afterwards', 0 === $GLOBALS['ewpa_current'] );
ewpa_check( 'the link title becomes the attachment title', 'Product' === ( $r['title'] ?? '' ), ewpa_show( $r ) );
ewpa_check( 'the alt text is saved', 'Red shoe' === ( $GLOBALS['ewpa_meta'][101]['_wp_attachment_image_alt'] ?? '' ) );
ewpa_check( 'the link is consumed', null === ewpa_upload_link_pending( $token ) );
ewpa_check( 'the upload is logged against the owner', array( array( 1, 'ewpa/upload-link-received' ) ) === $GLOBALS['ewpa_logs'], ewpa_show( $GLOBALS['ewpa_logs'] ) );
$r = ewpa_upload_link_receive( $token, ewpa_tmp( $png ), 'again.png' );
ewpa_check( 'a used link cannot upload a second file', is_wp_error( $r ) && 404 === $r->get_error_data()['status'] && 1 === count( $GLOBALS['ewpa_sideloads'] ), ewpa_show( $r ) );

// ─── Owner changes and races ─────────────────────────────────────────────────

ewpa_reset();
$link                       = ewpa_upload_link_create( 2 );
$token                      = ewpa_token_of( $link );
$GLOBALS['ewpa_caps'][2]    = false;
$r                          = ewpa_upload_link_receive( $token, ewpa_tmp( $png ), 'x.png' );
ewpa_check( 'a link stops working when its owner loses upload_files', is_wp_error( $r ) && 403 === $r->get_error_data()['status'], ewpa_show( $r ) );
ewpa_check( 'that link is deleted', null === ewpa_upload_link_pending( $token ) && array() === $GLOBALS['ewpa_sideloads'] );

ewpa_reset();
$link                    = ewpa_upload_link_create( 2 );
$token                   = ewpa_token_of( $link );
unset( $GLOBALS['ewpa_users'][2] );
$r = ewpa_upload_link_receive( $token, ewpa_tmp( $png ), 'x.png' );
ewpa_check( 'a link stops working when its owner is deleted', is_wp_error( $r ) && 403 === $r->get_error_data()['status'], ewpa_show( $r ) );

ewpa_reset();
$link                       = ewpa_upload_link_create( 1 );
$token                      = ewpa_token_of( $link );
$GLOBALS['ewpa_type_hook'] = function () use ( $link ) {
	// Another request consumes the link while this one is still validating the file.
	$GLOBALS['wpdb']->delete( 'wp_options', array( 'option_name' => 'ewpa_upload_link_' . $link['upload_id'] ) );
};
$r = ewpa_upload_link_receive( $token, ewpa_tmp( $png ), 'x.png' );
ewpa_check( 'the losing request of a race gets 409', is_wp_error( $r ) && 409 === $r->get_error_data()['status'], ewpa_show( $r ) );
ewpa_check( 'the losing request stores nothing', array() === $GLOBALS['ewpa_sideloads'] );

ewpa_reset();
$link                        = ewpa_upload_link_create( 1 );
$token                       = ewpa_token_of( $link );
$GLOBALS['ewpa_sideload_fn'] = function () {
	return new WP_Error( 'upload_error', 'Disk full' );
};
$r = ewpa_upload_link_receive( $token, ewpa_tmp( $png ), 'x.png' );
ewpa_check( 'a storage failure is reported with 500', is_wp_error( $r ) && 500 === $r->get_error_data()['status'] && false !== strpos( $r->get_error_message(), 'Disk full' ), ewpa_show( $r ) );
ewpa_check( 'after a storage failure the link is usable again', null !== ewpa_upload_link_pending( $token ) );
ewpa_check( 'the user is restored after a storage failure', 0 === $GLOBALS['ewpa_current'] );

// ─── Status ──────────────────────────────────────────────────────────────────

ewpa_reset();
$link  = ewpa_upload_link_create( 1 );
$token = ewpa_token_of( $link );
$s     = ewpa_upload_link_status( $link['upload_id'], 1 );
ewpa_check( 'status is pending before the file arrives', 'pending' === ( $s['status'] ?? '' ), ewpa_show( $s ) );
$s = ewpa_upload_link_status( $link['upload_id'], 2 );
ewpa_check( 'another user sees expired, not pending', 'expired' === ( $s['status'] ?? '' ), ewpa_show( $s ) );
$s = ewpa_upload_link_status( $token, 1 );
ewpa_check( 'the token is not accepted as an upload_id', 'expired' === ( $s['status'] ?? '' ), ewpa_show( $s ) );

ewpa_upload_link_receive( $token, ewpa_tmp( $png ), 'x.png' );
$s = ewpa_upload_link_status( $link['upload_id'], 1 );
ewpa_check( 'status is completed with the attachment after upload', 'completed' === ( $s['status'] ?? '' ) && 101 === ( $s['attachment_id'] ?? 0 ) && '' !== ( $s['url'] ?? '' ), ewpa_show( $s ) );
$s = ewpa_upload_link_status( $link['upload_id'], 2 );
ewpa_check( 'another user cannot read a completed upload', 'expired' === ( $s['status'] ?? '' ), ewpa_show( $s ) );

$s = ewpa_upload_link_status( 'nope', 1 );
ewpa_check( 'a malformed upload_id is rejected with 400', is_wp_error( $s ) && 400 === $s->get_error_data()['status'], ewpa_show( $s ) );

// ─── Done ────────────────────────────────────────────────────────────────────

foreach ( glob( $ewpa_tmp_dir . '/*' ) as $leftover ) {
	unlink( $leftover ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test scratch cleanup.
}
rmdir( $ewpa_tmp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test scratch cleanup.

echo "\n$ewpa_pass passed, $ewpa_fail failed\n";
exit( $ewpa_fail ? 1 : 0 );
