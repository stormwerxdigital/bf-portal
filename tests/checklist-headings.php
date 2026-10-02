<?php
/*
 * A checklist names itself once.
 *
 * Each fixed list carries its name twice: as the block's own heading, and as
 * the label on the column of findings under it. Where those say the same
 * thing the second one is not a column heading, it is the same line printed
 * twice, which is how "Grammar and word usage / Grammar and word usage"
 * reached a report.
 *
 * The rule is in one place because the editor and the family's report both
 * draw the same head row, and a rule written twice drifts.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* Comments explain; they are not evidence. Every match below is against
 * code with the comments taken out, using PHP's own tokeniser rather than a
 * regex, which on a file this size quietly gives up and returns null. */
function code_of($file) {
  $out = '';
  foreach (token_get_all(file_get_contents($file)) as $t) {
    if (is_array($t)) {
      if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue;
      $out .= $t[1];
    } else {
      $out .= $t;
    }
  }
  return $out;
}

/* ---- the rule itself ---- */
check(BFTD_Schema::heading_repeats('Grammar and word usage', 'Grammar and word usage are correct'),
  'a heading that begins with the column words is saying them');
check(BFTD_Schema::heading_repeats('Writing convention', 'Writing conventions'),
  'and the singular of a plural is the same word');
check(BFTD_Schema::heading_repeats('Challenges', 'Challenges'),
  'and the identical case is the plain one');
check(BFTD_Schema::heading_repeats('grammar  and, word usage', 'Grammar and word usage'),
  'case, spacing and punctuation are not a difference');
check(!BFTD_Schema::heading_repeats('Result', 'Challenges'),
  'a column that says something else keeps its heading');
check(!BFTD_Schema::heading_repeats('Spelling pattern', 'Challenges'),
  'and so does one that only sits under a different name');
check(!BFTD_Schema::heading_repeats('', 'Challenges') && !BFTD_Schema::heading_repeats('Challenges', ''),
  'nothing repeats nothing');

/* ---- every checklist in the schema, against its own heading ---- */
$seen = 0;
foreach (BFTD_Schema::sections() as $sid => $section) {
  if (empty($section['fields'])) continue;
  foreach ($section['fields'] as $key => $field) {
    if (empty($field['type']) || 'checklist' !== $field['type']) continue;
    $seen++;
    $item = isset($field['headings']['item']) ? $field['headings']['item'] : $field['label'];

    // The editor draws the block label, then the head row.
    check(BFTD_Schema::heading_repeats($item, $field['label']),
      "$sid/$key: the editor's column heading is the label again, so it is dropped");

    // The report draws the title where there is one, then the head row.
    if (!empty($field['title'])) {
      check(BFTD_Schema::heading_repeats($item, $field['title']),
        "$sid/$key: the report's column heading is the title again, so it is dropped");
    }
  }
}
check(3 === $seen, 'all three fixed lists were looked at');

/* ---- the note column is called a note ---- */
$fields = code_of(BFTD_PATH . 'includes/class-bftd-fields.php');
check(false !== strpos($fields, '<span>Notes</span>'), 'the third column is Notes');
check(false !== strpos($fields, 'placeholder="Optional note"'), 'and so is what it asks for');
check(false === stripos($fields, 'Optional example'), 'nothing still offers an example');

/* ---- and both renderers actually apply the rule ---- */
foreach (array('includes/class-bftd-fields.php', 'includes/class-bftd-dashboard.php') as $file) {
  $src = code_of(BFTD_PATH . $file);
  check(1 === preg_match('/heading_repeats\(/', $src), "$file asks the rule rather than printing the heading blind");
  check(false === strpos($src, "esc_html( \$head['item'] )"), "$file no longer prints the raw column heading");
}

exit($fail ? 1 : 0);
