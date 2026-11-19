<?php
// Published projects for the website gallery, in display order.
require __DIR__ . '/../lib/bootstrap.php';
$rows = db()->query('SELECT id,title,type,year,location,area,status,summary,image FROM projects WHERE published = 1 ORDER BY sort, id')->fetchAll();
header('Cache-Control: public, max-age=60');
json_out(['ok' => true, 'projects' => $rows]);
