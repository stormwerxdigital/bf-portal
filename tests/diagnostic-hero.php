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
class BFTD_MetaBoxes { const SUBTITLE_KEY = '_bftd_subtitle'; public static function grade_options() { return array( '8' => 'Grade 8', '12' => 'Grade 12', 'adult' => 'Adult' ); } }
class BFTD_CPT { public static function staff_ids( $id ) { return array(); } }
require dirname( __DIR__ ) . '/includes/class-bftd-report-view.php';

$fail = 0;
function check( $ok, $msg ) { global $fail; if ( $ok ) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$line = new ReflectionMethod( 'BFTD_Report_View', 'hero_line' );
$line->setAccessible( true );
$v = array( 'assessed_on' => '2026-08-11', 'grade' => 'Grade 5 typed on the diagnostic', 'assessed_by' => 3 );

BFTD_Fields::$S = array( 'birth_date' => '2013-07-06', 'school' => 'Chewelah School District', 'grade' => '8' );
$got = $line->invoke( null, 1, 90, $v );
check( 1 === preg_match( '/^[^·]*2026 · born [^·]*2013 · age 13 · Grade 8 · Chewelah School District · assessed by Laurel Sanders$/u', $got ),
  'the birth date sits after the assessment date, with the age it made them: ' . $got );

/* Grade, birth date, age and school all come from the student record
   (Karl, 1.151). A grade typed on an older diagnostic is not shown. */
check( false === strpos( $got, 'Grade 5' ), 'an old grade typed on the diagnostic is ignored' );
BFTD_Fields::$S = array( 'grade' => '12', 'school' => 'homeschool + Running Start Program' );
$got = $line->invoke( null, 1, 90, $v );
check( false !== strpos( $got, ' · Grade 12 · homeschool + Running Start Program · ' ), 'the stored grade is said in words, then the school: ' . $got );
BFTD_Fields::$S = array( 'grade' => 'Grade 11 French immersion' );
$got = $line->invoke( null, 1, 90, $v );
check( false !== strpos( $got, 'Grade 11 French immersion' ), 'a grade not in the list is shown as typed' );

BFTD_Fields::$S = array( 'school' => 'Chewelah School District' );
$got = $line->invoke( null, 1, 90, $v );
check( false === strpos( $got, 'born' ) && false === strpos( $got, 'age' ), 'no birth date on the record, no "born" and no age: ' . $got );

BFTD_Fields::$S = array( 'birth_date' => 'not a date' );
$got = $line->invoke( null, 1, 90, $v );
check( false === strpos( $got, 'born' ), 'and nonsense in the field is left out rather than printed' );

/* The title above the name ("Reading diagnostic assessment · Initial") is a
   heading, at Karl's direction: the heading font, heading size and weight,
   not the small eyebrow label it was. */
$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/bftd-report.css' );
preg_match( '/\.bf-report \.hero-report \.hero-title\{([^}]*)\}/', $css, $m );
$rule = $m[1] ?? '';
check( preg_match( '/font-size:(\d+(\.\d+)?)px/', $rule, $fs ) && (float) $fs[1] >= 22, 'the diagnostic title is heading sized, at least 22px' );
check( false !== strpos( $rule, 'font-weight:800' ), 'and heavy' );
check( false !== strpos( $rule, 'font-family:var(--font-d)' ), 'in the heading font' );
$rv0 = file_get_contents( dirname( __DIR__ ) . '/includes/class-bftd-report-view.php' );
check( false !== strpos( $rv0, '<p class="hero-title">Reading diagnostic assessment' ), 'and no longer carries the small eyebrow class' );

/* Karl removed the opening paragraph and the "Read this part first" banner
   in 1.151: the overview carries notes and instructions. Neither the words,
   the field nor the banner remain. */
$rv  = file_get_contents( dirname( __DIR__ ) . '/includes/class-bftd-report-view.php' );
$sch = file_get_contents( dirname( __DIR__ ) . '/includes/class-bftd-schema.php' );
check( false === strpos( $rv, 'banner-first' ) && false === strpos( $css, 'banner-first' ), 'no read-this-first banner on the report' );
check( false === strpos( $rv, 'is starting from, not what they are capable of' ), 'nor its sentence' );
check( false === strpos( $rv, 'class="lede"' ) && false === strpos( $rv, 'DIAGNOSTIC_INTRO' ), 'no opening paragraph under the name' );
check( false === strpos( $sch, 'DIAGNOSTIC_INTRO' ) && false === strpos( $sch, 'Six areas of reading and writing' ), 'nor its wording anywhere' );
check( 0 === preg_match( "/'intro'\s*=>\s*array\( 'type' => 'textarea', 'label' => 'Introduction at the top of the report'/", $sch ), 'and the overview no longer asks for one' );

/* Headings inside report sections are large and prominent (Karl, 1.151). */
check( 1 === preg_match( '/\.bf-report \.doc-body h3, \.bf-report \.doc-body h4\{font-family:var\(--font-d\); font-size:(\d+)px; font-weight:800[^}]*border-left:4px solid var\(--bf-purple\)/', $css, $hm ) && (int) $hm[1] >= 18,
  'section headings are heading font, heavy, at least 18px, edged in purple' );
check( 1 === preg_match( '/\.bf-report \.doc-body h3\{font-size:(\d+)px/', $css, $hm2 ) && (int) $hm2[1] >= 20, 'and larger still at the comfortable reading size' );

exit( $fail ? 1 : 0 );
