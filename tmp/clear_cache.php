<?php
define('PROJECT_ROOT', 'c:/laragon/www/suki/project');
$db = new PDO('sqlite:' . PROJECT_ROOT . '/storage/meta/ops_semantic_cache.sqlite');
$db->exec('DELETE FROM ops_semantic_cache');
echo 'Cache cleared. Rows remaining: ' . $db->query('SELECT COUNT(*) FROM ops_semantic_cache')->fetchColumn() . PHP_EOL;
