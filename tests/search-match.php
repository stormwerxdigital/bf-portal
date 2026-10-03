<?php
/*
 * The library search rule, on the server and in the browser, case by case.
 *
 * The pickers and the list suggestions search in the browser; the list
 * itself searches on the server. If the two rules ever differ, a suggestion
 * offers a record that Enter then fails to find. So both run the same cases
 * from search-cases.json, and both have to give the same answer.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
class BFTD_Roles { public static function can_manage_team(){ return true; } }
require BFTD_PATH . 'includes/class-bftd-library.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

$set  = json_decode(file_get_contents(__DIR__ . '/search-cases.json'), true);
$rows = array();
foreach ($set['rows'] as $id => $r) $rows[(int) $id] = $r;

/* ---- the server ---- */
foreach ($set['cases'] as $c) {
  $got = BFTD_Library::search($rows, $c['q']);
  check($got === $c['want'], 'server: "' . $c['q'] . '" ' . $c['why'] . ', got [' . implode(',', $got) . ']');
}

/* ---- the browser's copy, run in node ---- */
$node = trim((string) shell_exec('command -v node'));
if ('' === $node) {
  echo "SKIP  browser rule (no node here)\n";
} else {
  $js = 'require(' . json_encode(BFTD_PATH . 'assets/js/bftd-match.js') . ');'
      . 'const set=require(' . json_encode(__DIR__ . '/search-cases.json') . ');'
      . 'const rows=Object.keys(set.rows).map(id=>Object.assign({id:+id},set.rows[id]));'
      . 'console.log(JSON.stringify(set.cases.map(c=>globalThis.BFTDMatch.search(rows,c.q).map(r=>r.id))));';
  $out = json_decode((string) shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($js)), true);
  check(is_array($out), 'the browser rule ran');
  foreach ($set['cases'] as $i => $c) {
    $got = $out[$i] ?? null;
    check($got === $c['want'], 'browser: "' . $c['q'] . '" ' . $c['why'] . ', got [' . implode(',', (array) $got) . ']');
  }
}

exit($fail ? 1 : 0);
