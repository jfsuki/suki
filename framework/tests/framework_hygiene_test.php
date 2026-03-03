<?php
// framework/tests/framework_hygiene_test.php

declare(strict_types=1);

$frameworkRoot = dirname(__DIR__);
$appRoot = $frameworkRoot . '/app';
$failures = [];

$zeroBytePhpFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $entry) {
    if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
        continue;
    }
    if (strtolower((string) $entry->getExtension()) !== 'php') {
        continue;
    }
    if ((int) $entry->getSize() === 0) {
        $zeroBytePhpFiles[] = str_replace('\\', '/', (string) $entry->getPathname());
    }
}
if (!empty($zeroBytePhpFiles)) {
    $failures[] = 'No se permiten archivos PHP vacios en framework/app: ' . implode(', ', $zeroBytePhpFiles);
}

$gatewayPath = $appRoot . '/Core/Agents/ConversationGateway.php';
$gatewayLineCount = 0;
if (!is_file($gatewayPath)) {
    $failures[] = 'ConversationGateway.php no existe en ruta esperada.';
} else {
    $gatewayLines = file($gatewayPath, FILE_IGNORE_NEW_LINES);
    $gatewayLineCount = is_array($gatewayLines) ? count($gatewayLines) : 0;
    if ($gatewayLineCount > 10000) {
        $failures[] = 'ConversationGateway supera limite de higiene (10000 lineas). Actual=' . $gatewayLineCount;
    }

    $gatewayContent = file_get_contents($gatewayPath);
    $requiredTraits = [
        'ConversationGatewayHandlePipelineTrait',
        'ConversationGatewayBuilderOnboardingTrait',
        'ConversationGatewayRoutingPolicyTrait',
    ];
    foreach ($requiredTraits as $traitName) {
        $traitPath = $appRoot . '/Core/Agents/' . $traitName . '.php';
        if (!is_file($traitPath)) {
            $failures[] = 'Trait requerido ausente: ' . $traitName;
            continue;
        }
        if (!is_string($gatewayContent) || !str_contains($gatewayContent, 'use ' . $traitName . ';')) {
            $failures[] = 'ConversationGateway no declara use para trait requerido: ' . $traitName;
        }
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'gateway_lines' => $gatewayLineCount,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
