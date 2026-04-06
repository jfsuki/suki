<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';
$r = new App\Core\ProjectRegistry();
echo "--- PROYECTOS ACTUALES ---\n";
foreach($r->listProjects() as $p) {
    echo $p['id'] . " - " . $p['name'] . " [" . $p['status'] . "]\n";
}
echo "--- USUARIOS MASTER ---\n";
foreach($r->getMasterUsersByType('creator') as $u) {
    echo $u['id'] . " - " . $u['label'] . " [" . $u['role'] . "]\n";
}
echo "--- GREENFIELD VERIFIED ---\n";
