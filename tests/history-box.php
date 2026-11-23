<?php
/*
 * The student's History box (Karl, 1.207.1): was "Contact and history", and
 * no longer shows Phone or Email. Both are still kept in step with the CRM,
 * and a save from a form without them leaves them as they were.
 */
$src = file_get_contents(dirname(__DIR__) . '/includes/class-bftd-metaboxes.php');
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function body_of($src, $fn) {
  $p = strpos($src, 'function ' . $fn . '(');
  if (false === $p) return '';
  $q = strpos($src, "\n\t}\n", $p);
  return substr($src, $p, $q - $p);
}

check(false !== strpos($src, "add_meta_box( 'bftd_student_crm', 'History',"), 'the box is called History');
check(false === strpos($src, 'Contact and history'), 'and nothing still calls it Contact and history');
check(false !== strpos($src, "add_meta_box( 'bftd_log', 'Activity log',"), 'the change log box is called Activity log (1.208.1)');
check(1 === substr_count($src, "'History', array("), 'so only one box on the student page is called History');

$ns = body_of($src, 'not_shown');
check(false !== strpos($ns, "array( 'phone', 'email' )"), 'Phone and Email are the fields not shown');

$box = body_of($src, 'box_crm');
check(1 === preg_match("#foreach \( self::crm_fields\(\) as \\\$key => \\\$field \) : \?>\s*<\?php.*?if \( in_array\( \\\$key, self::not_shown\(\), true \) \) continue;\s*(?:(?://[^\n]*\s*)?if \( '(?:school|attend_from)' === [^\n]*\s*)*BFTD_Fields::render_field#s", $box),
  'the History box skips them before drawing each field (School is the school search since 1.212, Attending from the start appointment since 1.217)');

$crm = body_of($src, 'crm_fields');
check(false !== strpos($crm, "'phone'") && false !== strpos($crm, "'email'"), 'they are still CRM fields, so the sync keeps them');
check(false !== strpos(body_of($src, 'save_flat'), "if ( ! array_key_exists( \$key, \$posted ) ) {"), 'and a save without them leaves them as they were');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
