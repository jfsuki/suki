<?php
$raw = file_get_contents(__DIR__ . '/../../docs/contracts/skills_catalog.json');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo 'JSON INVALID: ' . json_last_error_msg() . "\n";
    exit(1);
}
$count = count($data['catalog'] ?? []);
$withHandler = count(array_filter($data['catalog'] ?? [], fn($e) => isset($e['handler'])));
echo "JSON valid. Total entries: $count. With handler: $withHandler\n";

// Test DynamicSkillRegistry can load
require_once __DIR__ . '/../app/Core/DynamicSkillRegistry.php';
$registry = new \App\Core\DynamicSkillRegistry(null);
$registered = $registry->listRegistered();
echo "DynamicSkillRegistry loaded: " . count($registered) . " skills\n";
echo "Sample registered: " . implode(', ', array_slice($registered, 0, 8)) . "\n";
echo "Has 'accounting_profit_loss': " . ($registry->has('accounting_profit_loss') ? 'YES' : 'NO') . "\n";
echo "Has 'web_search': " . ($registry->has('web_search') ? 'YES' : 'NO') . "\n";
echo "Has 'create_agent': " . ($registry->has('create_agent') ? 'YES' : 'NO') . "\n";
