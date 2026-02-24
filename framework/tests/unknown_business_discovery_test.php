<?php
// framework/tests/unknown_business_discovery_test.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Agents\ConversationGateway;

$gateway = new ConversationGateway();
$tenantId = 'default';
$userId = 'unknown_test_' . time();
$projectId = 'unknown_test_proj';
$failures = [];

$gateway->handle(
    $tenantId,
    $userId,
    'quiero crear una app',
    'builder',
    $projectId
);
$start = $gateway->handle(
    $tenantId,
    $userId,
    'laboratorio de velas aromaticas',
    'builder',
    $projectId
);
$startReply = mb_strtolower((string) ($start['reply'] ?? ''), 'UTF-8');
if ((string) ($start['action'] ?? '') !== 'ask_user') {
    $failures[] = 'Expected ask_user when unknown business discovery starts.';
}
if (!str_contains($startReply, 'pregunta 1/')) {
    $failures[] = 'Expected questionnaire step at discovery start.';
}

$state = is_array($start['state'] ?? null) ? (array) $start['state'] : [];
$flow = is_array($state['unknown_business_discovery'] ?? null)
    ? (array) $state['unknown_business_discovery']
    : [];
$questions = is_array($flow['questions'] ?? null) ? array_values((array) $flow['questions']) : [];
if (count($questions) < 4) {
    $failures[] = 'Expected 4+ discovery questions.';
}

$result = $start;
for ($i = 0; $i < count($questions); $i++) {
    $result = $gateway->handle(
        $tenantId,
        $userId,
        'respuesta ' . ($i + 1) . ' control de produccion, inventario, factura, pagos y reportes',
        'builder',
        $projectId
    );
}

$finalReply = mb_strtolower((string) ($result['reply'] ?? ''), 'UTF-8');
if (!str_contains($finalReply, 'documento tecnico inicial')) {
    $failures[] = 'Expected technical brief after discovery completion.';
}
$finalState = is_array($result['state'] ?? null) ? (array) $result['state'] : [];
$finalFlow = is_array($finalState['unknown_business_discovery'] ?? null)
    ? (array) $finalState['unknown_business_discovery']
    : [];
if (trim((string) ($finalFlow['technical_prompt'] ?? '')) === '') {
    $failures[] = 'Expected technical prompt stored in state.';
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
