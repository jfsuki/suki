<?php

declare(strict_types=1);

/**
 * Tests para ExecutionRegistry (SCML Fase 2).
 *
 * Valida que el registro puede inspeccionar handlers reales via PHP Reflection,
 * leer SKILL_ACTION_MAP, extraer dependencias y generar el codegraph.
 */

require_once dirname(__DIR__) . '/app/autoload.php';

use App\Core\Grounding\ExecutionRegistry;
use App\Core\Grounding\TaskGroundingManifest;

// ---------------------------------------------------------------------------
// Test runner helpers
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}\n";
        $failed++;
    }
}

function assertContains(string $label, mixed $needle, array $haystack): void
{
    ok($label, in_array($needle, $haystack, true));
}

function assertNotEmpty(string $label, mixed $value): void
{
    ok($label, !empty($value));
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function catalogPath(): string
{
    return dirname(__DIR__, 2) . '/docs/contracts/skills_catalog.json';
}

/** @return list<string> */
function intentNamesWithHandler(): array
{
    $raw  = file_get_contents(catalogPath());
    $data = json_decode($raw ?: '', true);
    $names = [];
    foreach ($data['catalog'] ?? [] as $s) {
        if (!empty($s['handler'])) {
            $names[] = (string) $s['name'];
        }
    }
    return $names;
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

echo "\n=== ExecutionRegistry (SCML Fase 2) ===\n\n";

$registry = new ExecutionRegistry();

// ---------------------------------------------------------------------------
// T01 — build() retorna array no vacío
// ---------------------------------------------------------------------------
$built = $registry->build();
ok('T01 build() retorna array no vacío', !empty($built));

// ---------------------------------------------------------------------------
// T02 — número de entradas = número de skills con handler en el catálogo
// ---------------------------------------------------------------------------
$expected = count(intentNamesWithHandler());
ok("T02 entradas en registry = {$expected} (skills con handler)", count($built) === $expected);

// ---------------------------------------------------------------------------
// T03 — intent conocido existe en el registro
// ---------------------------------------------------------------------------
$intents = intentNamesWithHandler();
$firstIntent = $intents[0] ?? '';
ok("T03 primer intent '{$firstIntent}' existe en registry", isset($built[$firstIntent]));

// ---------------------------------------------------------------------------
// T04 — class_exists = true para handlers reales
// ---------------------------------------------------------------------------
$allClassesExist = true;
foreach ($built as $entry) {
    if (!$entry['class_exists']) {
        $allClassesExist = false;
        echo "     [DEBUG] class_not_found: {$entry['class']}\n";
    }
}
ok('T04 class_exists=true para todos los handlers reales', $allClassesExist);

// ---------------------------------------------------------------------------
// T05 — method_exists = true para todos (execute O handle como fallback)
// ---------------------------------------------------------------------------
$allMethodsExist = true;
$aliasCount = 0;
foreach ($built as $entry) {
    if (!$entry['method_exists']) {
        $allMethodsExist = false;
        echo "     [DEBUG] method_not_found: {$entry['class']}::{$entry['method']}\n";
    }
    if ($entry['method_is_alias'] ?? false) {
        $aliasCount++;
    }
}
ok('T05 method_exists=true (execute O handle fallback)', $allMethodsExist);
echo "     [INFO] {$aliasCount} skills usan handle() pero catálogo dice @execute (METHOD_ALIAS warnings)\n";

// ---------------------------------------------------------------------------
// T06 — AccountingSkill tiene SKILL_ACTION_MAP leído por reflection
// ---------------------------------------------------------------------------
$accountingIntents = array_filter($built, fn($e) => str_contains($e['class'], 'AccountingSkill'));
$hasActionMap = false;
foreach ($accountingIntents as $e) {
    if ($e['action'] !== null) {
        $hasActionMap = true;
        break;
    }
}
ok('T06 AccountingSkill: action extraído de SKILL_ACTION_MAP', $hasActionMap);

// ---------------------------------------------------------------------------
// T07 — Acción concreta: accounting_post → record_entry
// ---------------------------------------------------------------------------
$apEntry = $registry->getEntry('accounting_post');
ok(
    'T07 accounting_post → action=record_entry',
    $apEntry !== null && ($apEntry['action'] ?? '') === 'record_entry'
);

// ---------------------------------------------------------------------------
// T08 — getEntry() para intent inexistente retorna null
// ---------------------------------------------------------------------------
ok('T08 getEntry("intent_inexistente") = null', $registry->getEntry('intent_inexistente') === null);

// ---------------------------------------------------------------------------
// T09 — input_keys extraídas por análisis estático (AccountingSkill tiene claves)
// ---------------------------------------------------------------------------
$acctEntry = $apEntry;
ok(
    'T09 AccountingSkill tiene input_keys extraídas',
    $acctEntry !== null && !empty($acctEntry['input_keys'])
);

// ---------------------------------------------------------------------------
// T10 — UpdateInternalMemorySkill tiene input_keys: content, memory_type
// ---------------------------------------------------------------------------
$memEntry = null;
foreach ($built as $e) {
    if (str_contains($e['class'], 'UpdateInternalMemorySkill')) {
        $memEntry = $e;
        break;
    }
}
ok(
    'T10 UpdateInternalMemorySkill: input_keys incluye content',
    $memEntry !== null && in_array('content', $memEntry['input_keys'], true)
);
ok(
    'T11 UpdateInternalMemorySkill: input_keys incluye memory_type',
    $memEntry !== null && in_array('memory_type', $memEntry['input_keys'], true)
);

// ---------------------------------------------------------------------------
// T12 — deps del constructor extraídas (AccountingSkill → AccountingService)
// ---------------------------------------------------------------------------
$acctDeps = null;
foreach ($built as $e) {
    if (str_contains($e['class'], 'AccountingSkill')) {
        $acctDeps = $e['deps'];
        break;
    }
}
ok(
    'T12 AccountingSkill deps incluye AccountingService',
    $acctDeps !== null && in_array('AccountingService', $acctDeps, true)
);

// ---------------------------------------------------------------------------
// T13 — getCodeGraph() retorna estructura correcta
// ---------------------------------------------------------------------------
$graph = $registry->getCodeGraph();
ok('T13 getCodeGraph() retorna array no vacío', !empty($graph));

$hasIntents = false;
foreach ($graph as $classData) {
    if (!empty($classData['intents'])) {
        $hasIntents = true;
        break;
    }
}
ok('T14 codegraph: cada clase tiene intents[]', $hasIntents);

// ---------------------------------------------------------------------------
// T15 — codegraph: AccountingSkill agrupa múltiples intents
// ---------------------------------------------------------------------------
$acctGraph = null;
foreach ($graph as $className => $data) {
    if (str_contains($className, 'AccountingSkill')) {
        $acctGraph = $data;
        break;
    }
}
ok(
    'T15 codegraph AccountingSkill tiene > 1 intent',
    $acctGraph !== null && count($acctGraph['intents']) > 1
);

// ---------------------------------------------------------------------------
// T16 — validate() retorna valid=true para catálogo real (aliases son warnings, no errors)
// ---------------------------------------------------------------------------
$validation = $registry->validate();
ok('T16 validate() valid=true (METHOD_ALIAS es warning, no error)', $validation['valid'] === true);
ok('T17 validate() entries > 0', $validation['entries'] > 0);

if (!empty($validation['errors'])) {
    echo "     [DEBUG] errores de validación:\n";
    foreach ($validation['errors'] as $err) {
        echo "       - {$err}\n";
    }
}
if (!empty($validation['warnings'])) {
    echo "     [INFO] warnings ({$validation['entries']} entries):\n";
    foreach (array_slice($validation['warnings'], 0, 4) as $w) {
        echo "       - {$w}\n";
    }
}

// ---------------------------------------------------------------------------
// T18 — validate() detecta clase inexistente con catálogo falso
// ---------------------------------------------------------------------------
$fakeCatalog = sys_get_temp_dir() . '/fake_catalog_test.json';
file_put_contents($fakeCatalog, json_encode([
    'catalog' => [[
        'name'          => 'fake_skill',
        'handler'       => 'App\\Core\\Skills\\NonExistentSkillXyz@execute',
        'topic_cluster' => 'test',
        'description'   => 'fake',
    ]],
]));
$fakeRegistry = new ExecutionRegistry($fakeCatalog);
$fakeValidation = $fakeRegistry->validate();
ok('T18 validate() detecta CLASS_NOT_FOUND en catálogo con clase inexistente', !$fakeValidation['valid']);
ok('T19 validate() error contiene CLASS_NOT_FOUND', !empty(array_filter(
    $fakeValidation['errors'],
    fn($e) => str_contains($e, 'CLASS_NOT_FOUND')
)));
@unlink($fakeCatalog);

// ---------------------------------------------------------------------------
// T20 — renderSignatureHint retorna string no vacío para intent conocido con action_map
// ---------------------------------------------------------------------------
$hint = $registry->renderSignatureHint('accounting_post');
ok('T20 renderSignatureHint("accounting_post") no vacío', $hint !== '');
ok('T21 renderSignatureHint contiene "Acción: record_entry"', str_contains($hint, 'record_entry'));

// ---------------------------------------------------------------------------
// T22 — renderSignatureHint retorna '' para intent inexistente
// ---------------------------------------------------------------------------
ok('T22 renderSignatureHint("nonexistent") = ""', $registry->renderSignatureHint('nonexistent') === '');

// ---------------------------------------------------------------------------
// T23 — degradación graciosa con catálogo inexistente
// ---------------------------------------------------------------------------
$emptyRegistry = new ExecutionRegistry('/tmp/no_such_file_xyz.json');
$emptyBuild = $emptyRegistry->build();
ok('T23 build() degradación graciosa con catálogo inexistente', is_array($emptyBuild) && empty($emptyBuild));

// ---------------------------------------------------------------------------
// T24 — TaskGroundingManifest integrado con ExecutionRegistry enriquece el bloque
// ---------------------------------------------------------------------------
$manifest = new TaskGroundingManifest(null, 0.60, $registry);
// Tomar el primer intent con action_map (accounting_post)
$result = $manifest->renderForSystemPrompt('accounting_post', 0.90);
ok('T24 TaskGroundingManifest con registry: block no vacío', $result['block'] !== '');
ok('T25 bloque enriquecido contiene "Acción: record_entry"', str_contains($result['block'], 'record_entry'));

// ---------------------------------------------------------------------------
// T26 — TaskGroundingManifest SIN registry: tests Fase 1 siguen pasando
// ---------------------------------------------------------------------------
$manifestNoReg = new TaskGroundingManifest();
$resultNoReg = $manifestNoReg->renderForSystemPrompt('accounting_post', 0.90);
ok('T26 TaskGroundingManifest SIN registry: block no vacío (backward compat)', $resultNoReg['block'] !== '');

// ---------------------------------------------------------------------------
// Resultado final
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo "\n--- Resultado: {$passed}/{$total} PASS";
if ($failed > 0) {
    echo " ({$failed} FAIL)";
}
echo " ---\n";

exit($failed > 0 ? 1 : 0);
