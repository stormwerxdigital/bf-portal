<?php
/*
 * Accessibility mode: whose it is, how it is switched, and the type sizes it
 * works out from the plugin's own stylesheets.
 *
 * The mode belongs to one person. A staff member who switches it on gets it
 * everywhere they go; nobody else gets anything. A client never gets it,
 * whatever is stored against their account, and the profile row only writes
 * when it was on the form and the person saving may edit that user.
 */
$META = array(); $USER = 0; $STAFF = array( 7 => true, 8 => true ); $CAN = true;
function get_user_meta( $id, $k, $single = false ) { return $GLOBALS['META'][ $id ][ $k ] ?? ''; }
function update_user_meta( $id, $k, $v ) { $GLOBALS['META'][ $id ][ $k ] = $v; return true; }
function get_current_user_id() { return $GLOBALS['USER']; }
function current_user_can( $cap, $id = 0 ) { return $GLOBALS['CAN']; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=n-' . $action; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function is_ssl() { return true; }
function wp_unslash( $v ) { return $v; }
function get_transient( $k ) { return $GLOBALS['TRANSIENT'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['TRANSIENT'][ $k ] = $v; return true; }
if ( ! defined( 'WEEK_IN_SECONDS' ) ) define( 'WEEK_IN_SECONDS', 604800 );
class BFTD_Roles { public static function is_staff( $id = null ) { $id = $id ? $id : $GLOBALS['USER']; return ! empty( $GLOBALS['STAFF'][ $id ] ); } }

require __DIR__ . '/wp-stubs.php';
define( 'BFTD_PATH', dirname( __DIR__ ) . '/' );
define( 'BFTD_URL', 'https://example.test/wp-content/plugins/bf-portal/' );
define( 'BFTD_VERSION', 'test' );
require BFTD_PATH . 'includes/class-bftd-accessibility.php';

$fail = 0;
function check( $ok, $msg ) { global $fail; if ( $ok ) { echo "  ok  $msg\n"; } else { echo "FAIL  $msg\n"; $fail++; } }
$A = 'BFTD_Accessibility';

/* ---- whose it is ---- */
$USER = 7;
check( ! $A::is_on(), 'off until somebody switches it on' );
$A::set( 7, true );
check( $A::is_on() && '1' === $META[7][ $A::META ], 'on once a staff member switches it on' );
check( ! $A::is_on( 8 ), 'and only for them, not the next staff member' );
$META[9][ $A::META ] = '1';
check( ! $A::is_on( 9 ), 'a client is never in the mode, whatever is stored' );
$USER = 0;
check( ! $A::is_on(), 'nor is somebody signed out' );
$USER = 7;

/* ---- what it puts on the page ---- */
check( false !== strpos( $A::admin_body_class( 'x' ), 'bftd-a11y' ), 'on: the admin body gets the class' );
check( in_array( 'bftd-a11y', $A::body_class( array() ), true ), 'and so does a front-end page, the portal' );
check( false !== strpos( $A::body_attr(), 'bftd-a11y' ) && false !== strpos( $A::head_tags(), 'bftd-a11y.css' ), 'and the report preview, which has its own head' );
$A::set( 7, false );
check( 'x' === $A::admin_body_class( 'x' ) && array() === $A::body_class( array() ), 'off: nothing at all' );
check( '' === $A::head_tags() && '' === $A::body_attr(), 'and nothing on the preview either' );
check( array( 'on' => 0 ) === $A::script_config(), 'and nothing for the editors the script builds' );

/* ---- the switch ---- */
$_SERVER['HTTP_HOST'] = 'example.test'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=bftd-students';
$u = $A::toggle_url();
check( false !== strpos( $u, 'to=1' ) && false !== strpos( $u, '_wpnonce=n-bftd_a11y' ), 'off, the switch turns it on and carries a nonce' );
check( false !== strpos( urldecode( urldecode( $u ) ), 'page=bftd-students' ), 'and comes back to the page it was pressed on' );
$A::set( 7, true );
check( false !== strpos( $A::toggle_url(), 'to=0' ), 'on, it turns it off' );

/* ---- the profile row ---- */
$A::set( 8, false );
$_POST = array( 'bftd_a11y' => '1' );
$A::save_profile( 8 );
check( '0' === $META[8][ $A::META ], 'a save from a form without the row changes nothing' );
$_POST = array( 'bftd_a11y_shown' => '1', 'bftd_a11y' => '1' );
$CAN = false; $A::save_profile( 8 );
check( '0' === $META[8][ $A::META ], 'nor does one by somebody who cannot edit that user' );
$CAN = true; $A::save_profile( 8 );
check( '1' === $META[8][ $A::META ], 'ticked, it is on' );
$_POST = array( 'bftd_a11y_shown' => '1' );
$A::save_profile( 8 );
check( '0' === $META[8][ $A::META ], 'unticked, it is off' );
$_POST = array( 'bftd_a11y_shown' => '1', 'bftd_a11y' => '1' );
$A::save_profile( 9 );
check( '1' === ( $META[9][ $A::META ] ?? '' ) && ! $A::is_on( 9 ), 'a client account is not written to by it (and would not be drawn in it anyway)' );
ob_start(); $A::profile_row( (object) array( 'ID' => 9 ) ); $row = ob_get_clean();
check( '' === $row, 'and a client\'s profile does not show it' );
ob_start(); $A::profile_row( (object) array( 'ID' => 7 ) ); $row = ob_get_clean();
check( false !== strpos( $row, 'name="bftd_a11y"' ) && false !== strpos( $row, 'bftd_a11y_shown' ), 'a staff member\'s profile does' );

/* ---- the editor ---- */
check( false === strpos( $A::editor_css(), '"' ), 'the editor style has no double quote, which would stop every editor loading' );
$init = $A::editor( array( 'content_css' => 'a.css' ) );
check( false !== strpos( $init['content_style'], 'Atkinson' ) && 0 === strpos( $init['content_css'], 'a.css,' ), 'on, editors get the style, added to theirs' );
$A::set( 7, false );
check( array( 'content_css' => 'a.css' ) === $A::editor( array( 'content_css' => 'a.css' ) ), 'off, editors are left exactly as they were' );

/* ---- the sizes ---- */
check( 16.5 === $A::scale( 11 ) && 16.5 === $A::scale( 9 ), 'small type comes up to 16.5px' );
$prev = 0; $mono = true;
for ( $px = 8; $px <= 60; $px += 0.5 ) { $s = $A::scale( $px ); if ( $s < $prev || $s < $px ) $mono = false; $prev = $s; }
check( $mono, 'bigger stays bigger, and nothing shrinks' );
check( $A::scale( 40 ) / 40 < $A::scale( 14 ) / 14, 'display type grows less than body type' );

$css = '/* .x{font-size:9px} */ .a{color:red;font-size:12px} .b,.c{font:600 13px/18px var(--f)}'
	. ' :root{font-size:14px} body.post-type-x .d{font-size:11px} html .e{font-size:12px}'
	. ' @media (max-width:600px){ .f{font-size:10px;line-height:14px} }'
	. ' @media print{ .g{font-size:9px} } @keyframes k{from{opacity:0}to{opacity:1}} .h{font-size:2em}';
$out = $A::enlarge( $css );
check( false === strpos( $out, '9px' ) && false === strpos( $out, '.g' ), 'comments and print rules are left alone' );
check( false !== strpos( $out, 'body.bftd-a11y .a{font-size:16.5px}' ), 'a rule comes back behind the body class, enlarged' );
check( false !== strpos( $out, 'body.bftd-a11y .b,body.bftd-a11y .c{font-size:16.5px;line-height:22.8px}' ), 'every selector in a list, from a font shorthand, line height kept in proportion' );
check( false !== strpos( $out, 'body.bftd-a11y{font-size:17px}' ), ':root becomes the body' );
check( '' === $A::enlarge( 'html.wp-toolbar{font-size:13px}' ), 'a state of the root itself is left alone' );
check( 'body.bftd-a11y :is(.p, .q) .r{font-size:16.5px}' === $A::enlarge( ':is(.p, .q) .r{font-size:12px}' ), 'a comma inside :is() does not split the selector' );
check( false !== strpos( $out, 'body.bftd-a11y.post-type-x .d' ), 'a body selector keeps its own classes' );
check( false !== strpos( $out, 'body.bftd-a11y .e' ), 'html in front of a selector' );
check( false !== strpos( $out, '@media (max-width:600px){body.bftd-a11y .f{font-size:16.5px;line-height:23.1px}}' ), 'a size inside a media query stays inside it' );
check( false === strpos( $out, 'keyframes' ) && false === strpos( $out, '.h' ), 'keyframes and relative sizes are skipped' );

/* The three real stylesheets: every px size has its enlarged twin. */
foreach ( $A::sources() as $f ) {
	$src = (string) file_get_contents( BFTD_PATH . 'assets/css/' . $f );
	$bare = preg_replace( '#/\*.*?\*/#s', '', $src );
	// The reader assumes no brace inside a quoted string. Checked, not hoped.
	check( ! preg_match( '/(["\'])[^"\'\n]*[{}][^"\'\n]*\1/', $bare ), "$f has no brace inside a string, so it can be read rule by rule" );
	$big = $A::enlarge( $src );
	check( substr_count( $big, '{' ) === substr_count( $big, '}' ), "$f enlarged is balanced" );
	// Count rule bodies outside print that set a px size.
	$noprint = preg_replace( '/@media\s+print\s*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $bare );
	preg_match_all( '/\{([^{}]*)\}/', $noprint, $m );
	$want = 0;
	foreach ( $m[1] as $body ) if ( preg_match( '/(^|;)\s*(font-size\s*:\s*[\d.]+px|font\s*:[^;]*\b[\d.]+px)/', $body ) ) $want++;
	check( $want > 50 && substr_count( $big, 'font-size:' ) === $want, "$f: all $want px sizes are enlarged, got " . substr_count( $big, 'font-size:' ) );
	preg_match_all( '/font-size:([\d.]+)px/', $big, $s );
	check( min( array_map( 'floatval', $s[1] ) ) >= 16.5, "$f: none of them under 16.5px" );
}
$all = $A::sizes_css();
check( 0 === strpos( $all, '@media screen{' ), 'all of it is for the screen only' );
check( $all === $A::sizes_css() && count( $TRANSIENT ) === 1, 'worked out once and then read back' );

/* ---- the stylesheet ---- */
$sheet = preg_replace( '#/\*.*?\*/#s', '', file_get_contents( BFTD_PATH . 'assets/css/bftd-a11y.css' ) );
check( 0 === strpos( trim( $sheet ), '@media screen' ), 'the stylesheet is all inside @media screen' );
$inner = trim( substr( trim( $sheet ), strpos( trim( $sheet ), '{' ) + 1, -1 ) );
/* A selector list split on its own commas, not the ones inside :is(). */
function split_top( $h ) {
	$out = array(); $cur = ''; $d = 0;
	foreach ( str_split( $h ) as $c ) {
		if ( '(' === $c ) $d++; elseif ( ')' === $c ) $d--;
		if ( ',' === $c && 0 === $d ) { $out[] = $cur; $cur = ''; } else { $cur .= $c; }
	}
	$out[] = $cur;
	return $out;
}
$unscoped = array();
$depth = 0; $head = '';
for ( $i = 0, $n = strlen( $inner ); $i < $n; $i++ ) {
	$c = $inner[ $i ];
	if ( '{' === $c ) {
		if ( 0 === $depth || ( 1 === $depth && '@' !== $outer ) ) { /* noop */ }
		$h = trim( $head ); $head = '';
		if ( '' !== $h && '@' !== $h[0] ) {
			foreach ( split_top( $h ) as $sel ) {
				$sel = trim( $sel );
				if ( ! preg_match( '/^(body\.bftd-a11y|:where\(body\.bftd-a11y\)|html(\.[\w-]+)?:has\(> body\.bftd-a11y)/', $sel ) ) $unscoped[] = $sel;
			}
		}
		$outer = $h ? $h[0] : '';
		$depth++;
	} elseif ( '}' === $c ) { $depth--; $head = ''; }
	elseif ( ';' === $c && 0 === $depth ) { $head = ''; }
	else { $head .= $c; }
}
check( array() === $unscoped, 'every rule is behind the mode\'s body class: ' . implode( ' | ', array_slice( $unscoped, 0, 5 ) ) );
check( false === strpos( $sheet, '--font-abc:' ) && false === strpos( $sheet, '--bftd-abc:' ), 'the alphabet a child is taught with is not replaced' );

/* The palette: text colours at 7:1 or better against white. */
function lum( $hex ) { $c = array_map( function ( $h ) { $v = hexdec( $h ) / 255; return $v <= .03928 ? $v / 12.92 : pow( ( $v + .055 ) / 1.055, 2.4 ); }, str_split( ltrim( $hex, '#' ), 2 ) ); return .2126 * $c[0] + .7152 * $c[1] + .0722 * $c[2]; }
preg_match_all( '/--(bftd-|bf-)?(ink|body|muted|purple|purple-deep|sage|sage-deep|clay|red):(#[0-9A-Fa-f]{6})/', $sheet, $m, PREG_SET_ORDER );
$low = array();
foreach ( $m as $row ) { $r = 1.05 / ( lum( $row[3] ) + .05 ); if ( $r < 7 ) $low[] = $row[0] . ' ' . round( $r, 2 ); }
check( count( $m ) > 15 && array() === $low, 'every text colour in the palette is 7:1 or better on white: ' . implode( ', ', $low ) );

exit( $fail ? 1 : 0 );
