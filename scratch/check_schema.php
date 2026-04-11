<?php
$db = new \PDO('sqlite:' . __DIR__ . '/../project/storage/meta/project_registry.sqlite');
$stmt = $db->query("PRAGMA table_info(chat_sessions)");
print_r($stmt->fetchAll(\PDO::FETCH_ASSOC));
