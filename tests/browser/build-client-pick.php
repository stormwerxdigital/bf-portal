<?php
/*
 * The client picker and the caregiver picker, side by side, so a browser can
 * be driven at both. One holds a person, the other holds a list, and they are
 * the same widget with one attribute between them.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function wp_rand($a=0,$b=0){ return 4242; }
function wp_kses_post($v){ return $v; }
function absint($n){ return abs((int)$n); }
function selected($a,$b,$e=true){ $r = $a==$b ? ' selected' : ''; if ($e) echo $r; return $r; }
function disabled($a,$b=true,$e=true){ $r = $a==$b ? ' disabled' : ''; if ($e) echo $r; return $r; }

class U {
  public $ID, $display_name, $user_email, $user_login;
  function __construct($i,$n,$e){ $this->ID=$i; $this->display_name=$n; $this->user_email=$e; $this->user_login=$n; }
}
$users = array(
  new U(20,'Laurel Sanders','laurel@example.test'),
  new U(21,'Dana Cole','dana@example.test'),
  new U(22,'Ravi Patel','ravi@example.test'),
);

$src = file_get_contents(BFTD_PATH.'includes/class-bftd-metaboxes.php');
$a = strpos($src, "\tpublic static function assign_field(");
$b = strpos($src, "\t/* ------------------------------------------------------------------ */\n\t/* Student", $a);
eval('class AF { ' . substr($src, $a, $b - $a) . ' }');

$rel = function ($u) { return 'Parent or guardian'; };

ob_start();
AF::assign_field('bftd_client_primary', $users, array(20), 'Nobody yet.', '', true, $rel, 1);
$one = ob_get_clean();

ob_start();
AF::assign_field('bftd_clients', $users, array(20), 'Nobody linked yet.', '', true, $rel);
$many = ob_get_clean();

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
$js  = file_get_contents(BFTD_PATH.'assets/js/bftd-admin.js');
file_put_contents(__DIR__.'/client-pick.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 system-ui;background:#fff;margin:0;padding:20px}'
  . $css . '</style>'
  . '<h3>Client</h3><div id="one">' . $one . '</div>'
  . '<h3 style="margin-top:30px">Caregivers</h3><div id="many">' . $many . '</div>'
  . '<script src="' . BFTD_PATH . 'tests/browser/jquery.js"></script>'
  . '<script>window.BFTD={ajax_url:"",nonce:"",post_id:1,autosave:0,tracks:[]};</script>'
  . '<script>'.$js.'</script>');
echo __DIR__ . '/client-pick.html' . "\n";
