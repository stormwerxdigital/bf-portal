<?php
$sample = BFTD_Sample::get('progress');
$fields = $sample['fields'];
$posts  = $sample['sessions'];
$posts += (array) $sample['posts'];
$fields['__posts'] = $posts;

BFTD_Fields::use_fixture($fields);
BFTD_Items::use_fixture($sample['items']);
BFTD_Derived::use_fixture($sample['derived']);
$ids = array_keys($sample['sessions']);
add_filter('bftd_pre_sessions_for', function() use ($ids){ return $ids; });

ob_start();
BFTD_Report_View::render(0, 0, $sample['screen'], $sample['student'], $sample);
$body = ob_get_clean();

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-report.css');
file_put_contents(__DIR__.'/full.html',
  '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{margin:0;background:#FBF9F7;font-family:system-ui,sans-serif}'.$css.'</style>'
  . '<div style="max-width:1060px;margin:0 auto;padding:22px">'.$body.'</div>');
echo "written ".strlen($body)." bytes\n";
