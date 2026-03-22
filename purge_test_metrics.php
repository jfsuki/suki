<?php
$db = new PDO('sqlite:' . __DIR__ . '/project/storage/meta/project_registry.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Count total rows
$total = $db->query('SELECT COUNT(*) FROM ops_intent_metrics')->fetchColumn();
echo "Total intent metrics: $total\n";

// Count by status
$stmt = $db->query('SELECT status, COUNT(*) as c FROM ops_intent_metrics GROUP BY status ORDER BY c DESC');
echo "\nBy status:\n";
foreach ($stmt as $row) { echo "  {$row['status']}: {$row['c']}\n"; }

// Show top 10 intents
$stmt = $db->query('SELECT intent, COUNT(*) as c FROM ops_intent_metrics GROUP BY intent ORDER BY c DESC LIMIT 10');
echo "\nTop intents:\n";
foreach ($stmt as $row) { echo "  {$row['intent']}: {$row['c']}\n"; }

// Show distinct tenant_ids
$stmt = $db->query('SELECT DISTINCT tenant_id FROM ops_intent_metrics LIMIT 10');
echo "\nTenant IDs:\n";
foreach ($stmt as $row) { echo "  {$row['tenant_id']}\n"; }

// Show total token usage
$tokens = $db->query('SELECT SUM(total_tokens) as t, SUM(estimated_cost_usd) as c FROM ops_token_usage')->fetch();
echo "\nTotal tokens: {$tokens['t']}, Cost: \${$tokens['c']}\n";

// PURGE OLD TEST DATA — delete everything older than today
echo "\n--- PURGING test data from metrics ---\n";
$today = date('Y-m-d');
$tables = ['ops_intent_metrics', 'ops_command_metrics', 'ops_guardrail_events', 'ops_token_usage'];
foreach ($tables as $table) {
    $count = $db->query("SELECT COUNT(*) FROM $table WHERE created_at < '$today'")->fetchColumn();
    $db->exec("DELETE FROM $table WHERE created_at < '$today'");
    echo "Purged $count old rows from $table\n";
}

// Recount
$total2 = $db->query('SELECT COUNT(*) FROM ops_intent_metrics')->fetchColumn();
echo "\nRemaining intent metrics: $total2\n";
echo "Done.\n";
