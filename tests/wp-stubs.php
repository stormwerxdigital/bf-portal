<?php
/**
 * Just enough WordPress for the tests to run on plain PHP.
 *
 * Every definition is guarded, so an individual test can supply its own
 * version of anything it needs to control.
 */

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );

$__stubs = array(
	'apply_filters'        => function ( $tag, $value ) { return $value; },
	'add_filter'           => function () {},
	'add_action'           => function () {},
	'do_action'            => function () {},
	'add_shortcode'        => function () {},
	'esc_html'             => function ( $s ) { return htmlspecialchars( (string) $s ); },
	'esc_attr'             => function ( $s ) { return htmlspecialchars( (string) $s ); },
	'esc_url'              => function ( $s ) { return (string) $s; },
	'esc_textarea'         => function ( $s ) { return htmlspecialchars( (string) $s ); },
	'esc_html__'           => function ( $s ) { return $s; },
	'__'                   => function ( $s ) { return $s; },
	'_n'                   => function ( $a, $b, $n ) { return 1 === $n ? $a : $b; },
	'wp_parse_args'        => function ( $a, $b ) { return array_merge( $b, (array) $a ); },
	'get_option'           => function ( $k, $d = false ) { return $GLOBALS['OPT'][ $k ] ?? $d; },
	'wp_salt'              => function () { return 'salt'; },
	'home_url'             => function ( $p = '' ) { return 'https://example.test' . $p; },
	'admin_url'            => function ( $p = '' ) { return 'https://example.test/wp-admin/' . $p; },
	'sanitize_key'         => function ( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); },
	'sanitize_text_field'  => function ( $s ) { return trim( strip_tags( (string) $s ) ); },
	'sanitize_textarea_field' => function ( $s ) { return trim( strip_tags( (string) $s ) ); },
	'wp_kses_post'         => function ( $s ) { return $s; },
	'wp_strip_all_tags'    => function ( $s ) { return strip_tags( (string) $s ); },
	'maybe_serialize'      => function ( $v ) { return is_scalar( $v ) ? (string) $v : serialize( $v ); },
	// WordPress ECHOES these too. A stub that only returned made every
	// rendered <option> come back without its selected attribute, so a test
	// looking at real markup could not tell a chosen value from an unchosen
	// one, and would have passed either way.
	'selected'             => function ( $a, $b, $e = true ) { $r = $a == $b ? ' selected' : ''; if ( $e ) echo $r; return $r; },
	'checked'              => function ( $a, $b = true, $e = true ) { $r = $a == $b ? ' checked' : ''; if ( $e ) echo $r; return $r; },
	// WordPress ECHOES these unless told not to, and plugin code calls them
	// bare inside markup. A stub that only returns makes rendered output look
	// as though the attribute was never written.
	'disabled'             => function ( $a, $b = true, $e = true ) { $r = $a == $b ? ' disabled' : ''; if ( $e ) echo $r; return $r; },
	'wp_generate_password' => function ( $n = 12 ) { return substr( md5( (string) mt_rand() ), 0, $n ); },
	'wp_json_encode'       => function ( $v ) { return json_encode( $v ); },
	'absint'               => function ( $v ) { return abs( (int) $v ); },
	'current_time'         => function ( $f ) { return 'mysql' === $f ? date( 'Y-m-d H:i:s' ) : date( $f ); },
	'date_i18n'            => function ( $f, $ts = null ) { return date( $f, null === $ts ? time() : $ts ); },
	'size_format'          => function ( $n ) { return $n . 'B'; },
	'human_time_diff'      => function ( $a, $b = null ) { return '1 day'; },
	'register_activation_hook' => function () {},
	'plugin_dir_path'      => function ( $f ) { return dirname( $f ) . '/'; },
	'plugin_dir_url'       => function () { return 'https://example.test/plugin/'; },
	'wp_list_pluck'        => function ( $l, $f ) { $o = array(); foreach ( (array) $l as $r ) { $o[] = is_array( $r ) ? $r[ $f ] : $r->$f; } return $o; },
	'update_option'        => function ( $k, $v, $a = null ) { $GLOBALS['OPT'][ $k ] = $v; return true; },
	// A record's status, out of $GLOBALS['STATUS'] keyed by id, so a test can
	// say what is published and what is still a draft. Anything not named is
	// a draft, because an unsaved screen is the state code most often gets
	// wrong and the stub should not be kinder than reality.
	'get_post_status'      => function ( $id ) { return $GLOBALS['STATUS'][ (int) $id ] ?? 'draft'; },
);

// BFTD_Time is the plugin's own clock, not a WordPress function, so the real
// one is loaded rather than faked: a stubbed clock would let a timezone bug
// pass every test.
if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

/*
 * The post type feature registry, which is a real registry in WordPress and
 * not a no-op: removing a feature is how a field leaves an edit screen, so a
 * stub that accepted the call and forgot it would let "the title box is gone"
 * pass without anything being gone. A test's own register_post_type is
 * expected to seed this from its args.
 */
if ( ! isset( $GLOBALS['PT_SUPPORT'] ) ) $GLOBALS['PT_SUPPORT'] = array();
if ( ! function_exists( 'add_post_type_support' ) ) {
	function add_post_type_support( $type, $feature ) {
		foreach ( (array) $feature as $f ) $GLOBALS['PT_SUPPORT'][ $type ][ $f ] = true;
	}
}
if ( ! function_exists( 'remove_post_type_support' ) ) {
	function remove_post_type_support( $type, $feature ) {
		unset( $GLOBALS['PT_SUPPORT'][ $type ][ $feature ] );
	}
}
if ( ! function_exists( 'post_type_supports' ) ) {
	function post_type_supports( $type, $feature ) {
		return ! empty( $GLOBALS['PT_SUPPORT'][ $type ][ $feature ] );
	}
}

foreach ( $__stubs as $name => $fn ) {
	if ( function_exists( $name ) ) continue;
	// Bind each stub to a real function name, so plugin code can call it.
	eval( 'function ' . $name . '(...$a){ return call_user_func_array($GLOBALS["__stubfns"]["' . $name . '"], $a); }' );
	$GLOBALS['__stubfns'][ $name ] = $fn;
}

require_once dirname( __DIR__ ) . '/includes/class-bftd-time.php';

/*
 * Nonces, shaped the way WordPress shapes them.
 *
 * Faked rather than left out: a screen that renders an action link without a
 * nonce looks identical in a fixture to one that renders it with a nonce, so a
 * missing check is invisible unless the stub can tell the two apart.
 */
if ( ! function_exists( 'wp_create_nonce' ) ) {
  function wp_create_nonce($a=-1){ return substr(md5('nonce'.$a),0,10); }
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
  function wp_nonce_url($url,$a=-1,$name='_wpnonce'){
    return $url . (strpos($url,'?')===false?'?':'&') . $name . '=' . wp_create_nonce($a);
  }
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
  function wp_verify_nonce($n,$a=-1){ return $n === wp_create_nonce($a); }
}
if ( ! function_exists( 'update_option' ) ) {
  function update_option($k,$v,$a=null){ $GLOBALS['OPT'][$k]=$v; return true; }
}
