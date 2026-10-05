<?php
/*
 * Panels on the edit screens can be opened and closed but not moved.
 *
 * Karl asked for this on students, diagnostics, progress reports, sessions,
 * resources, skills and activities: no up and down arrows, no dragging, and an order somebody saved
 * before is ignored. Other screens are left alone.
 */
$FILTERS = array(); $ACTIONS = array();
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['FILTERS'][ $h ][] = $cb; return true; }
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['ACTIONS'][ $h ][] = $cb; return true; }
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/' );
class BFTD_CPT { const STUDENT = 'bftd_student'; const ASSESSMENT = 'bftd_assessment'; const PROGRESS = 'bftd_progress'; const SESSION = 'bftd_session'; const RESOURCE = 'bftd_resource'; }
class BFTD_Skills { const POST_TYPE = 'bftd_skill'; }
class BFTD_Activities { const POST_TYPE = 'bftd_activity'; }
require dirname( __DIR__ ) . '/includes/class-bftd-panels.php';

$fail = 0;
function check( $ok, $msg ) { global $fail; if ( $ok ) { echo "  ok  $msg\n"; } else { echo "FAIL  $msg\n"; $fail++; } }
function scr( $base, $pt ) { return (object) array( 'base' => $base, 'post_type' => $pt, 'id' => $pt ); }

foreach ( array( 'bftd_student', 'bftd_assessment', 'bftd_progress', 'bftd_session', 'bftd_resource', 'bftd_skill', 'bftd_activity' ) as $pt ) {
	$FILTERS = array(); $ACTIONS = array();
	BFTD_Panels::screen( scr( 'post', $pt ) );
	check( isset( $FILTERS[ 'get_user_option_meta-box-order_' . $pt ] ) && '__return_false' === $FILTERS[ 'get_user_option_meta-box-order_' . $pt ][0], "$pt: a saved panel order is ignored" );
	check( ! empty( $ACTIONS['admin_head'] ) && ! empty( $ACTIONS['admin_print_footer_scripts'] ), "$pt: the arrows are hidden and dragging is switched off" );
}
foreach ( array( array( 'edit', 'bftd_student' ), array( 'post', 'post' ), array( 'post', 'page' ) ) as $c ) {
	$FILTERS = array(); $ACTIONS = array();
	BFTD_Panels::screen( scr( $c[0], $c[1] ) );
	check( ! $FILTERS && ! $ACTIONS, "{$c[0]} screen for {$c[1]} is left alone" );
}

ob_start(); BFTD_Panels::css(); $css = ob_get_clean();
check( false !== strpos( $css, '.handle-order-higher' ) && false !== strpos( $css, '.handle-order-lower' ) && false !== strpos( $css, 'display:none' ), 'the arrows are hidden' );
check( false === strpos( $css, 'handlediv' ) && false === strpos( $css, 'toggle-indicator' ), 'the open and close triangle is not touched' );
ob_start(); BFTD_Panels::js(); $js = ob_get_clean();
check( false !== strpos( $js, '"disabled",true' ) && false === strpos( $js, 'destroy' ), 'dragging is switched off, not torn out (WordPress still reads the order when a panel is toggled)' );
exit( $fail ? 1 : 0 );
