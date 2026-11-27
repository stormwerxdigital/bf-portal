<?php
/*
 * Schools that pay (Karl, 1.218): "most Schools pay monthly, after services
 * have been delivered. 1 school currently pays in advance. Whereas regular
 * students pay 1-3 months in advance". Made-up schools and students only.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

$META = array(); $TR = array(); $STUDENTS = array(); $ADMIN = true; $SENT = array(); $SESS = array(); $TITLE = array();
function get_post_meta($id, $k = '', $s = false) { global $META; return $META[$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { global $META; $META[$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { global $META; unset($META[$id][$k]); return true; }
function get_transient($k) { global $TR; return $TR[$k] ?? false; }
function set_transient($k, $v, $t = 0) { global $TR; $TR[$k] = $v; return true; }
function delete_transient($k) { global $TR; unset($TR[$k]); return true; }
function get_the_title($id) { return $GLOBALS['TITLE'][$id] ?? ''; }
if (!function_exists('remove_accents')) { function remove_accents($s) { return $s; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return $s; } }
function get_posts($a) {
  global $STUDENTS, $META;
  if (isset($a['meta_key'])) return array_values(array_filter($STUDENTS, function ($s) use ($a, $META) { $v = $META[$s][$a['meta_key']] ?? ''; return is_numeric($a['meta_value']) ? (int) $v === (int) $a['meta_value'] : (string) $v === (string) $a['meta_value']; }));
  return $STUDENTS;
}
class BFTD_CPT {
  const STUDENT = 'bftd_student';
  public static function sessions_of($ids, $d = false) { $o = array(); foreach ($ids as $i) $o[$i] = $GLOBALS['SESS'][$i] ?? array(); return $o; }
}
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $s, $k) { return get_post_meta($id, BFTD_Schema::meta_key($s, $k), true); }
  public static function set($id, $s, $k, $v) { return update_post_meta($id, BFTD_Schema::meta_key($s, $k), $v); }
}
class BFTD_Roles { public static function can_administer() { return $GLOBALS['ADMIN']; } public static function is_staff() { return true; } }
class BFTD_GHL {
  public static function configured() { return false; }
  public static function school_words($s) {
    $out = array();
    foreach (preg_split('/[^a-z0-9]+/', strtolower((string) $s)) as $w) if (strlen($w) >= 3 && !in_array($w, array('school', 'elementary', 'middle', 'prep'), true)) $out[] = $w;
    return array_values(array_unique($out));
  }
}
require BFTD_PATH . 'includes/class-bftd-schools.php';
require BFTD_PATH . 'includes/class-bftd-school-billing.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$set = function ($sid, $k, $v) { BFTD_Fields::set($sid, 'student', $k, $v); };
$sess = function ($sid, $date, $status) { static $n = 1000; $n++; BFTD_Fields::set($n, 'session', 'session_date', $date); BFTD_Fields::set($n, 'session', 'status', $status); $GLOBALS['SESS'][$sid][] = $n; };

echo "Billing periods:\n";
check('2027-02-28' === BFTD_School_Billing::bill_date(2027, 2, 'last') && '2027-02-28' === BFTD_School_Billing::bill_date(2027, 2, '28'), 'the last day of February, and the 28th');
check(array('2027-01-16', '2027-02-15') === BFTD_School_Billing::period_ending(2027, 2, '15'), 'billed on the 15th: the 16th of the month before to the 15th');
check(array('2027-01-01', '2027-01-31') === BFTD_School_Billing::period_ending(2027, 1, 'last'), 'billed on the last day: the calendar month');
check(array('2026-12-16', '2027-01-15') === BFTD_School_Billing::period_ending(2027, 1, '15'), 'across the new year');
$p = BFTD_School_Billing::periods('15', '2027-02-20', 3);
check(array('2027-02-16', '2027-03-15', true) === $p[0] && array('2027-01-16', '2027-02-15', false) === $p[1] && 3 === count($p), 'the open period first, after this month\'s billing day, then the closed ones');
$p = BFTD_School_Billing::periods('15', '2027-02-15', 2);
check(array('2027-01-16', '2027-02-15', true) === $p[0], 'on the billing day itself the period is still open');

echo "\nA school's billing:\n";
$m = BFTD_Schools::add('Maple Grove Elementary');
$v = BFTD_Schools::add('Valley Prep');
$o = BFTD_Schools::add('Oak Park Middle');
check(array('mode' => '', 'day' => 'last', 'rate' => '') === BFTD_School_Billing::of_school($o), 'a school on the list is not billed until somebody says so');
check(array('billing' => 'monthly', 'bill_day' => 'last', 'rate' => '45.50') === BFTD_School_Billing::clean('monthly', '31', '$45.5'), 'a day past the 28th is the last day; a rate is kept in dollars and cents');
check(array('billing' => '', 'bill_day' => '5', 'rate' => '') === BFTD_School_Billing::clean('weekly', '5', '-3'), 'nothing that is not a billing choice or a rate gets in');
list($r, $say) = BFTD_Schools::save_table(array('do' => 'save',
  'billing' => array($m => 'monthly', $v => 'advance', $o => ''), 'bill_day' => array($m => '15', $v => 'last', $o => 'last'), 'rate' => array($m => '40', $v => '50', $o => '')));
check(true === $r && 'monthly' === BFTD_School_Billing::of_school($m)['mode'] && '15' === BFTD_School_Billing::of_school($m)['day'] && '40.00' === BFTD_School_Billing::of_school($m)['rate'], 'saved with the Schools table, got ' . $say);
check('advance' === BFTD_School_Billing::of_school($v)['mode'] && false !== strpos($say, '2 billing changed'), 'both schools set at once, got ' . $say);
check(array($m => 'Maple Grove Elementary') === BFTD_School_Billing::monthly_schools(), 'only the monthly one has statements');

echo "\nWho pays, per student:\n";
$STUDENTS = array(1, 2, 3, 4, 5);
foreach (array(1 => 'Ada Test', 2 => 'Ben Test', 3 => 'Cal Test', 4 => 'Dee Test', 5 => 'Eve Test') as $i => $t) $TITLE[$i] = $t;
BFTD_Schools::set_student(1, $m, false); BFTD_Schools::set_student(2, $m, false); BFTD_Schools::set_student(3, $m, false);
BFTD_Schools::set_student(4, $v, false); BFTD_Schools::set_student(5, $o, false);
$set(1, 'paid_by', 'school'); $set(2, 'paid_by', 'school'); $set(4, 'paid_by', 'school'); $set(5, 'paid_by', 'school');
check('monthly' === BFTD_School_Billing::mode_for(1) && 'Sessions approved' === BFTD_School_Billing::bank_label(1), 'a student a monthly school pays for has Sessions approved');
check('' === BFTD_School_Billing::mode_for(3) && 'Sessions purchased' === BFTD_School_Billing::bank_label(3), 'a classmate whose family pays still has Sessions purchased: Paid by decides, not the school');
check('advance' === BFTD_School_Billing::mode_for(4) && 'Sessions purchased' === BFTD_School_Billing::bank_label(4), 'one paid for in advance draws down Sessions purchased');
check('' === BFTD_School_Billing::mode_for(5) && false !== strpos(BFTD_School_Billing::said(5), 'not set up to be billed'), 'Their school, at a school not billed: the family pays, and it says why');
check(false !== strpos(BFTD_School_Billing::said(1), 'monthly after sessions, on the 15th'), 'and says when a monthly school is billed');
$set(6, 'paid_by', 'school');
check(null === BFTD_School_Billing::payer(6) && false !== strpos(BFTD_School_Billing::said(6), 'Link their School'), 'Their school with no school linked: the family pays');
$set(1, 'paid_by', 'family');
check('' === BFTD_School_Billing::mode_for(1), 'and back the other way: Family again, and the school no longer pays');
$set(1, 'paid_by', 'school');

echo "\nA monthly statement:\n";
$set(2, 'rate', '35');
$sess(1, '2027-01-15', 'held');      // before the period
$sess(1, '2027-01-16', 'held');
$sess(1, '2027-01-20', 'late');
$sess(1, '2027-02-01', 'noshow');
$sess(1, '2027-02-03', 'missed');
$sess(1, '2027-02-05', 'cancelled'); // not given
$sess(1, '2027-02-15', 'held');
$sess(1, '2027-02-16', 'held');      // after
$sess(2, '2027-02-10', 'held');
$sess(3, '2027-02-10', 'held');      // the family pays
$st = BFTD_School_Billing::statement($m, '2027-01-16', '2027-02-15');
$rows = array(); foreach ($st['rows'] as $r) $rows[$r['name']] = $r;
check(array('Ada Test', 'Ben Test') === array_keys($rows), 'only the students the school pays for');
check(3 === $rows['Ada Test']['attended'] && 1 === $rows['Ada Test']['noshow'] && 1 === $rows['Ada Test']['missed'] && 5 === $rows['Ada Test']['sessions'], 'sessions dated in the period, both ends included; no shows and missed billed, cancelled not, got ' . json_encode($rows['Ada Test']));
check(40.0 === $rows['Ada Test']['rate'] && 200.0 === $rows['Ada Test']['total'], 'at the school\'s rate when the student has none');
check(35.0 === $rows['Ben Test']['rate'] && 35.0 === $rows['Ben Test']['total'], 'the student\'s own rate first');
check(6 === $st['sessions'] && 235.0 === $st['total'] && 0 === $st['missing_rate'], 'and the totals');
$csv = BFTD_School_Billing::csv_rows($m, array('2027-01-16', '2027-02-15', false), $st);
$last = end($csv);
check('Sessions billed' === $csv[0][7] && '200.00' === $csv[1][9] && 'Total' === $last[3] && '235.00' === $last[9], 'the CSV has a row for each student and a total');
$list = get_option(BFTD_Schools::OPTION); $list[$m]['rate'] = ''; update_option(BFTD_Schools::OPTION, $list);
$st = BFTD_School_Billing::statement($m, '2027-01-16', '2027-02-15');
check(1 === $st['missing_rate'], 'with no rate anywhere, the statement says so rather than billing nothing quietly');

echo "\nThe statements section draws:\n";
foreach (array('esc_html', 'esc_attr', 'esc_url') as $fn) if (!function_exists($fn)) eval("function $fn(\$s) { return htmlspecialchars((string) \$s, ENT_QUOTES); }");
if (!function_exists('selected')) { function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected'; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return '/wp-admin/' . $p; } }
if (!function_exists('add_query_arg')) { function add_query_arg($a, $u) { return $u . '?' . http_build_query($a); } }
if (!function_exists('absint')) { function absint($n) { return abs((int) $n); } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return $s; } }
if (!class_exists('BFTD_Time')) require BFTD_PATH . 'includes/class-bftd-time.php';
$list = get_option(BFTD_Schools::OPTION); $list[$m]['rate'] = '40.00'; update_option(BFTD_Schools::OPTION, $list);
$_GET = array('statement' => $m, 'period' => '');
ob_start(); BFTD_School_Billing::render_section(); $html = ob_get_clean();
check(false !== strpos($html, 'Monthly statements') && false !== strpos($html, 'Maple Grove Elementary') && false !== strpos($html, 'bftd_school_statement_csv') && false !== strpos($html, 'bftd_school_statement_print'), 'the school, its periods, Print and Export CSV');
check(false === strpos($html, '<b>Warning') && false === strpos($html, 'Notice:'), 'without warnings');
$_GET = array();

echo "\nOn the screens:\n";
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, "'paid_by'      => array( 'type' => 'select', 'label' => 'Paid by', 'default' => 'family'"), 'Student details has Paid by, Family to start');
check(false !== strpos($mb, 'BFTD_School_Billing::bank_label( $post->ID )') && false !== strpos($mb, "'Approved' : 'Purchased'"), 'Sessions purchased and its tile say Approved for a monthly school');
$sb = file_get_contents(BFTD_PATH . 'includes/class-bftd-school-billing.php');
check(1 === substr_count($sb, "check_admin_referer( 'bftd_school_statement' )") && false !== strpos($sb, 'BFTD_Schools::can_edit()'), 'print and CSV are for senior managers and administrators, with a nonce');

echo "\nGovernment funding (1.221.0):\n";
check(false === isset(BFTD_School_Billing::monthly_schools()[BFTD_School_Billing::GOV]), 'no government statement while nobody is paid for by it');
$STUDENTS[] = 7; $TITLE[7] = 'Gil Test';
$set(7, 'paid_by', 'government');
check('government' === BFTD_School_Billing::paid_by(7) && 'monthly' === BFTD_School_Billing::mode_for(7) && 'Sessions approved' === BFTD_School_Billing::bank_label(7), 'a student paid by government funding is billed monthly, with Sessions approved');
check(false !== strpos(BFTD_School_Billing::said(7), 'Government funding is billed monthly after sessions, on the last day of the month'), 'and Paid by says so');
check(array(7) === BFTD_School_Billing::paid_students(BFTD_School_Billing::GOV), 'only the students paid by government funding are on its statement');
check('Government funding' === (BFTD_School_Billing::monthly_schools()[BFTD_School_Billing::GOV] ?? ''), 'and its statement is listed with the schools\'');
update_option(BFTD_School_Billing::OPTION_GOV, array('bill_day' => '10', 'rate' => '50.00'));
$sess(7, '2027-02-01', 'held'); $sess(7, '2027-02-09', 'noshow'); $sess(7, '2027-02-11', 'held');
$g = BFTD_School_Billing::statement(BFTD_School_Billing::GOV, '2027-01-11', '2027-02-10');
check(1 === count($g['rows']) && 2 === $g['sessions'] && 100.0 === $g['total'], 'its statement bills the period at its own rate, got ' . json_encode($g));
$set(7, 'rate', '45');
check(90.0 === BFTD_School_Billing::statement(BFTD_School_Billing::GOV, '2027-01-11', '2027-02-10')['total'], 'the student\'s own rate first, as for a school');
check(false !== strpos(BFTD_School_Billing::said(7), 'on the 10th'), 'on its own billing day');
check('Government funding' === BFTD_School_Billing::csv_rows(BFTD_School_Billing::GOV, array('2027-01-11', '2027-02-10', false), $g)[1][0], 'the CSV names it');
check(null === BFTD_School_Billing::record(999) && 'Government funding' === BFTD_School_Billing::record(BFTD_School_Billing::GOV)['name'], 'a payer that is neither is nobody');
$_GET = array('statement' => BFTD_School_Billing::GOV, 'period' => '');
ob_start(); BFTD_School_Billing::render_section(); $html = ob_get_clean();
check(false !== strpos($html, 'name="action" value="bftd_gov_billing"') && 1 === preg_match('/value="10"\s+selected/', $html) && false !== strpos($html, 'value="50.00"'), 'the Schools page sets its billing day and rate');
check(false !== strpos($html, '<caption>Government funding,') && false !== strpos($html, 'Gil Test'), 'and shows its statement');
$sb = file_get_contents(BFTD_PATH . 'includes/class-bftd-school-billing.php');
check(false !== strpos($sb, "check_admin_referer( 'bftd_gov_billing' )") && 2 <= substr_count($sb, 'BFTD_Schools::can_edit()'), 'saving it takes a nonce and a senior manager or administrator');
check(false !== strpos($mb, "'government' => 'Government funding'"), 'Paid by offers Government funding');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
