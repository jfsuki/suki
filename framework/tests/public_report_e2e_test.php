<?php
// framework/tests/public_report_e2e_test.php

declare(strict_types=1);

$frameworkRoot = dirname(__DIR__);
$endpointPath = $frameworkRoot . '/public/report.php';
$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0775, true);
}

$failures = [];
if (!is_file($endpointPath)) {
    $failures[] = 'Endpoint no encontrado: framework/public/report.php';
} else {
    $probePath = $tmpDir . '/public_report_probe_' . uniqid('', true) . '.php';
    $probeCode = "<?php\n"
        . "declare(strict_types=1);\n"
        . "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "\$_REQUEST = [];\n"
        . "\$_GET = [];\n"
        . "\$_POST = [];\n"
        . "require " . var_export($endpointPath, true) . ";\n";
    file_put_contents($probePath, $probeCode);

    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probePath), $output, $exitCode);
    if (is_file($probePath)) {
        unlink($probePath);
    }

    $body = implode("\n", $output);
    if ($exitCode !== 0) {
        $failures[] = 'La ejecucion del endpoint devolvio exit code no esperado: ' . $exitCode;
    }
    if (!str_contains($body, '"ok":false')) {
        $failures[] = 'El endpoint debe responder JSON con ok=false cuando falta parametro form.';
    }
    if (!str_contains(strtolower($body), 'form')) {
        $failures[] = 'El endpoint debe explicar que falta el parametro form.';
    }
}

$ok = empty($failures);
echo json_encode([
    'ok' => $ok,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($ok ? 0 : 1);
