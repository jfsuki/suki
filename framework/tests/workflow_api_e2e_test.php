<?php
// framework/tests/workflow_api_e2e_test.php

declare(strict_types=1);

$helper = __DIR__ . '/api_route_turn.php';

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runApiRoute(string $helper, array $request): array
{
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
    $json = json_decode($raw, true);
    return [
        'raw' => trim($raw),
        'json' => is_array($json) ? $json : null,
    ];
}

$failures = [];
$authSession = [
    'auth_user' => [
        'id' => 'wf_e2e_user',
        'role' => 'admin',
        'tenant_id' => 'default',
        'project_id' => 'default',
    ],
];

$unauthCompile = runApiRoute($helper, [
    'route' => 'workflow/compile',
    'method' => 'POST',
    'payload' => [
        'workflow_id' => 'wf_e2e_security',
        'text' => 'crear workflow de prueba',
    ],
]);
$unauth = $unauthCompile['json'];
if (!is_array($unauth) || ($unauth['status'] ?? '') !== 'error' || !str_contains((string) ($unauth['message'] ?? ''), 'iniciar sesion')) {
    $failures[] = 'workflow/compile debe bloquear sin sesion autenticada (401).';
}

$compile = runApiRoute($helper, [
    'route' => 'workflow/compile',
    'method' => 'POST',
    'session' => $authSession,
    'payload' => [
        'workflow_id' => 'wf_e2e_' . time(),
        'text' => 'crear workflow para cotizacion y salida final',
    ],
]);
$compileJson = $compile['json'];
if (!is_array($compileJson) || ($compileJson['status'] ?? '') !== 'success') {
    $failures[] = 'workflow/compile autenticado debe responder success.';
}
$proposal = is_array($compileJson['data'] ?? null) ? (array) $compileJson['data'] : [];
$contract = is_array($proposal['proposed_contract'] ?? null) ? (array) $proposal['proposed_contract'] : [];
if (empty($contract)) {
    $failures[] = 'workflow/compile debe retornar proposed_contract.';
}

if (!empty($contract)) {
    $validate = runApiRoute($helper, [
        'route' => 'workflow/validate',
        'method' => 'POST',
        'session' => $authSession,
        'payload' => [
            'contract' => $contract,
        ],
    ]);
    $validateJson = $validate['json'];
    if (!is_array($validateJson) || ($validateJson['status'] ?? '') !== 'success') {
        $failures[] = 'workflow/validate debe validar proposed_contract.';
    }

    $execute = runApiRoute($helper, [
        'route' => 'workflow/execute',
        'method' => 'POST',
        'session' => $authSession,
        'payload' => [
            'contract' => $contract,
            'input' => [
                'cliente' => 'Ana',
                'total' => 120000,
                'descripcion' => 'cotizacion e2e',
            ],
        ],
    ]);
    $executeJson = $execute['json'];
    if (!is_array($executeJson) || ($executeJson['status'] ?? '') !== 'success') {
        $failures[] = 'workflow/execute debe responder success con contrato valido.';
    } else {
        $ok = (bool) (($executeJson['data']['ok'] ?? false));
        $traces = is_array($executeJson['data']['traces'] ?? null) ? (array) $executeJson['data']['traces'] : [];
        if (!$ok) {
            $failures[] = 'workflow/execute debe reportar ok=true.';
        }
        if (count($traces) < 1) {
            $failures[] = 'workflow/execute debe incluir trazas de nodos.';
        }
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

