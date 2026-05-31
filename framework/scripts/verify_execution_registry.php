<?php

declare(strict_types=1);

/**
 * Verifica el PHP Execution Registry (SCML Fase 2).
 *
 * Uso: php framework/scripts/verify_execution_registry.php [--codegraph]
 *
 * Muestra:
 *   - Validación catalog ↔ código PHP real
 *   - Firma de cada intent (acción + parámetros detectados)
 *   - Codegraph (clase → intents + deps) si se pasa --codegraph
 *
 * Exit 0 si valid=true, exit 1 si hay errores.
 */

require_once dirname(__DIR__) . '/app/autoload.php';

use App\Core\Grounding\ExecutionRegistry;

$showGraph = in_array('--codegraph', $argv ?? [], true);

$registry   = new ExecutionRegistry();
$validation = $registry->validate();
$built      = $registry->build();

echo "\n=== PHP Execution Registry (SCML Fase 2) ===\n";
echo "Catálogo: " . dirname(__DIR__, 2) . "/docs/contracts/skills_catalog.json\n";
echo "Entradas: {$validation['entries']}\n\n";

// ---- Errores ----------------------------------------------------------------
if (!empty($validation['errors'])) {
    echo "[ERRORES]\n";
    foreach ($validation['errors'] as $err) {
        echo "  ❌ {$err}\n";
    }
    echo "\n";
}

// ---- Warnings ---------------------------------------------------------------
if (!empty($validation['warnings'])) {
    echo "[WARNINGS]\n";
    foreach ($validation['warnings'] as $w) {
        echo "  ⚠️  {$w}\n";
    }
    echo "\n";
}

// ---- Por cluster ------------------------------------------------------------
$byCluster = [];
foreach ($built as $entry) {
    $byCluster[$entry['topic_cluster']][] = $entry;
}

foreach ($byCluster as $cluster => $entries) {
    echo "[{$cluster}] — " . count($entries) . " skills\n";
    foreach ($entries as $e) {
        $status = $e['class_exists'] ? ($e['method_exists'] ? 'OK' : 'MÉTODO?') : 'CLASE?';
        $alias  = ($e['method_is_alias'] ?? false) ? ' ~alias' : '';
        $hint   = $registry->renderSignatureHint($e['intent']);
        $hintStr = $hint !== '' ? "  [{$hint}]" : '';

        printf(
            "  %-42s => %-30s [%s%s]%s\n",
            $e['intent'],
            basename(str_replace('\\', '/', $e['class'])),
            $status,
            $alias,
            $hintStr
        );
    }
    echo "\n";
}

// ---- Codegraph (opcional) ---------------------------------------------------
if ($showGraph) {
    echo "=== CODEGRAPH (clase → deps + intents) ===\n\n";
    foreach ($registry->getCodeGraph() as $class => $data) {
        $shortClass = basename(str_replace('\\', '/', $class));
        echo "[{$shortClass}]\n";
        echo "  intents : " . implode(', ', $data['intents']) . "\n";
        echo "  deps    : " . (empty($data['deps']) ? '—' : implode(', ', $data['deps'])) . "\n";
        echo "  inputs  : " . (empty($data['input_keys']) ? '—' : implode(', ', array_slice($data['input_keys'], 0, 8))) . "\n";
        if (!empty($data['action_map'])) {
            echo "  actions : " . implode(', ', array_values($data['action_map'])) . "\n";
        }
        echo "\n";
    }
}

// ---- Resumen ----------------------------------------------------------------
if ($validation['valid']) {
    echo "✅  Registry válido — {$validation['entries']} handlers verificados\n";
    if (!empty($validation['warnings'])) {
        echo "   (" . count($validation['warnings']) . " warnings — ver arriba)\n";
    }
    exit(0);
} else {
    echo "❌  Registry inválido — " . count($validation['errors']) . " error(es)\n";
    exit(1);
}
