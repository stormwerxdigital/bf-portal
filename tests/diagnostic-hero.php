<?php
/*
 * The line under the student's name on a reading diagnostic.
 *
 * Karl asked for the student's birth date there: the assessment date, then
 * "born <date>", then the age it made them on the day, grade, school and who
 * assessed. A record with no birth date simply leaves both out.
 */
require __DIR__ . '/wp-stubs.php';
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return 'F j, Y'; } }
if ( ! function_exists( 'get_userdata' ) ) { function get_userdata( $id ) { $u = new stdClass; $u->display_name = 'Laurel Sanders'; return $u; } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $id, $k = '', $s = false ) { return ''; } }
class BFTD_Fields { public static $S = array(); public static function get( $id, $sec, $k ) { return self::$S[ $k ] ?? ''; } }
class BFTD_MetaBoxes { const SUBTITLE_KEY = '_bftd_subtitle'; }
class BFTD_CPT { public static function staff_ids( $id ) { return array(); } }
require dirname( __DIR__ ) . '/includes/class-bftd-report-view.php';

$fail = 0;
function check( $ok, $msg ) { global $fail; if ( $ok ) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$line = new ReflectionMethod( 'BFTD_Report_View', 'hero_line' );
$line->setAccessible( true );
$v = array( 'assessed_on' => '2026-08-11', 'grade' => 'Grade 8', 'assessed_by' => 3 );

BFTD_Fields::$S = array( 'birth_date' => '2013-07-06', 'school' => 'Chewelah School District' );
$got = $line->invoke( null, 1, 90, $v );
check( 1 === preg_match( '/^[^·]*2026 · born [^·]*2013 · age 13 · Grade 8 · Chewelah School District · assessed by Laurel Sanders$/u', $got ),
  'the birth date sits after the assessment date, with the age it made them: ' . $got );

BFTD_Fields::$S = array( 'school' => 'Chewelah School District' );
$got = $line->invoke( null, 1, 90, $v );
check( false === strpos( $got, 'born' ) && false === strpos( $got, 'age' ), 'no birth date on the record, no "born" and no age: ' . $got );

BFTD_Fields::$S = array( 'birth_date' => 'not a date' );
$got = $line->invoke( null, 1, 90, $v );
check( false === strpos( $got, 'born' ), 'and nonsense in the field is left out rather than printed' );

/* The eyebrow above the name ("Reading diagnostic assessment · Initial") is
   prominent, at Karl's direction, rather than the small label it was. */
$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/bftd-report.css' );
preg_match( '/\.bf-report \.hero-report \.eyebrow\{([^}]*)\}/', $css, $m );
$rule = $m[1] ?? '';
check( preg_match( '/font-size:(\d+(\.\d+)?)px/', $rule, $fs ) && (float) $fs[1] >= 15, 'the diagnostic eyebrow is at least 15px' );
check( false !== strpos( $rule, 'font-weight:800' ), 'and heavy' );

/* "Read this part first" is prominent too: its own class, larger type, a
   heavier border. */
$rv = file_get_contents( dirname( __DIR__ ) . '/includes/class-bftd-report-view.php' );
check( false !== strpos( $rv, 'class="banner banner-first"' ), 'the read-this-first banner carries its own class' );
preg_match( '/\.bf-report \.banner\.banner-first\{([^}]*)\}/', $css, $b );
check( isset( $b[1] ) && false !== strpos( $b[1], 'font-size:16px' ) && false !== strpos( $b[1], 'border-left-width:6px' ),
  'and is drawn larger, with a heavy edge' );

/* The paragraph under the name is the tutor's to edit: a field on the
   overview, starting from the wording it always had, with {tutor} filled in. */
$sch = file_get_contents( dirname( __DIR__ ) . '/includes/class-bftd-schema.php' );
check( 1 === preg_match( "/'intro'\s*=>\s*array\( 'type' => 'textarea'.{0,200}'default' => self::DIAGNOSTIC_INTRO/s", $sch ), 'the introduction is an editable field that starts from the old wording' );
check( false !== strpos( $rv, "str_replace( '{tutor}'" ) && false === strpos( $rv, 'Six areas of reading and writing, so we can see' ),
  'and the report draws the field, not hard-coded words' );

exit( $fail ? 1 : 0 );
