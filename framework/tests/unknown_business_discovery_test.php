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

$result = $gateway->handle(
    $tenantId,
    $userId,
    'ventas, inventario y facturacion',
    'builder',
    $projectId
);
$stepOneState = is_array($result['state'] ?? null) ? (array) $result['state'] : [];
$stepOneFlow = is_array($stepOneState['unknown_business_discovery'] ?? null)
    ? (array) $stepOneState['unknown_business_discovery']
    : [];
if ((int) ($stepOneFlow['current_index'] ?? -1) !== 1) {
    $failures[] = 'Expected discovery index = 1 after first valid answer.';
}

$frustration = $gateway->handle(
    $tenantId,
    $userId,
    'no estas entendiendo',
    'builder',
    $projectId
);
$frustrationReply = mb_strtolower((string) ($frustration['reply'] ?? ''), 'UTF-8');
if (!str_contains($frustrationReply, 'pregunta 2/')) {
    $failures[] = 'Discovery should keep same question for non-answer frustration input.';
}
$frustrationState = is_array($frustration['state'] ?? null) ? (array) $frustration['state'] : [];
$frustrationFlow = is_array($frustrationState['unknown_business_discovery'] ?? null)
    ? (array) $frustrationState['unknown_business_discovery']
    : [];
if ((int) ($frustrationFlow['current_index'] ?? -1) !== 1) {
    $failures[] = 'Discovery index should not advance on frustration non-answer.';
}

$result = $frustration;
for ($i = 1; $i < count($questions); $i++) {
    $answer = 'respuesta ' . ($i + 1) . ' control de produccion, inventario, factura, pagos y reportes';
    if ($i === 2) {
        $answer = 'ventas, contabilidad, pagos y lo que me pide la dian';
    }
    $result = $gateway->handle(
        $tenantId,
        $userId,
        $answer,
        'builder',
        $projectId
    );
    $loopReply = mb_strtolower((string) ($result['reply'] ?? ''), 'UTF-8');
    if (str_contains($loopReply, 'flujo sugerido:') || str_contains($loopReply, 'facturacion electronica en colombia')) {
        $failures[] = 'Builder guidance should not interrupt unknown discovery flow.';
        break;
    }
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
