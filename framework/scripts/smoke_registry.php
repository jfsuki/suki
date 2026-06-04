<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$r = new \App\Core\DynamicSkillRegistry(null);
$all = $r->listRegistered();
echo "Skills registradas: " . count($all) . "\n";
echo "accounting_profit_loss: " . ($r->has('accounting_profit_loss') ? 'OK' : 'FAIL') . "\n";
echo "web_search:             " . ($r->has('web_search')             ? 'OK' : 'FAIL') . "\n";
echo "create_agent:           " . ($r->has('create_agent')           ? 'OK' : 'FAIL') . "\n";
echo "inventory_check:        " . ($r->has('inventory_check')        ? 'OK' : 'FAIL') . "\n";
echo "custom_tool_inexistente:" . ($r->has('mi_tool_nueva')          ? 'FOUND (error)' : 'NOT_FOUND — correcto') . "\n";
echo "\nTodas las registradas: " . implode(', ', $all) . "\n";
