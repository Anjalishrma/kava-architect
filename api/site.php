<?php
// Public website text, edited in Admin → Site content.
require __DIR__ . '/../lib/bootstrap.php';
$out = [];
foreach (CONTENT_FIELDS as $k => $f) $out[$k] = setting($k);
header('Cache-Control: public, max-age=60');
json_out(['ok' => true, 'content' => $out]);
