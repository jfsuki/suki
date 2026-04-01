<?php
require_once __DIR__ . '/../vendor/autoload.php';
$db = new PDO('sqlite:project/storage/meta/project_registry.sqlite');
$stmt = $db->query("SELECT id, nit, full_name, is_active FROM auth_users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total usuarios: " . count($users) . "\n";
print_r($users);
