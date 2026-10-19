<?php
/*
 * The media window keeps core's layout (1.157). A rule setting
 * .media-frame-content to position:relative collapsed the upload tab to a
 * 40px strip; the drop box styling must work inside core's absolute pane.
 */
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$css = preg_replace('#/\*[\s\S]*?\*/#', '', file_get_contents(dirname(__DIR__) . '/assets/css/bftd-admin.css'));
check(0 === preg_match('/(^|[},\s])\.media-frame-content\s*\{[^}]*position\s*:/', $css), 'nothing repositions the media pane itself');
check(1 === preg_match('/\.media-modal \.uploader-inline-content\{[^}]*position:relative;top:auto;/', $css), 'the drop box sits inside the pane, not 30% down it');
check(false !== strpos($css, '.media-modal .attachments-browser .uploader-inline-content{height:auto}'), 'and fits under the filters in an empty library');
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
