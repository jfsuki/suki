<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\Contracts\ContractRepository;
use App\Core\GboValidator;

$failures = [];

$catalogReport = GboValidator::validateCatalog();
if (($catalogReport['ok'] ?? false) !== true) {
    $failures[] = 'El catalogo GBO base debe validar.';
}

$stats = is_array($catalogReport['stats'] ?? null) ? $catalogReport['stats'] : [];
if ((int) ($stats['concepts'] ?? 0) < 17) {
    $failures[] = 'GBO debe incluir al menos 17 conceptos universales.';
}
if ((int) ($stats['events'] ?? 0) < 15) {
    $failures[] = 'GBO debe incluir al menos 15 eventos universales.';
}

$concepts = loadJson(FRAMEWORK_ROOT . '/ontology/gbo_universal_concepts.json');
$relationships = loadJson(FRAMEWORK_ROOT . '/ontology/gbo_semantic_relationships.json');
$aliases = loadJson(FRAMEWORK_ROOT . '/ontology/gbo_base_aliases.json');

$duplicateConcepts = $concepts;
$duplicateConcepts['concepts'][] = $duplicateConcepts['concepts'][0];
$duplicateReport = GboValidator::validateCatalog([
    'gbo_universal_concepts' => $duplicateConcepts,
]);
if (($duplicateReport['ok'] ?? false) === true) {
    $failures[] = 'GBO debe bloquear conceptos duplicados.';
}

$invalidRelationships = $relationships;
$invalidRelationships['relationships'][0]['target_type'] = 'ghost_concept';
$relationshipReport = GboValidator::validateCatalog([
    'gbo_semantic_relationships' => $invalidRelationships,
]);
if (($relationshipReport['ok'] ?? false) === true) {
    $failures[] = 'GBO debe bloquear relaciones contra tipos inexistentes.';
}

$conflictingAliases = $aliases;
$conflictingAliases['aliases'][] = [
    'alias_id' => 'alias_factura_payment_conflict',
    'alias' => 'factura',
    'canonical_target' => 'payment',
    'target_type' => 'concept',
    'language' => 'es',
    'source_scope' => 'global',
    'version' => '1.0.0',
    'status' => 'active',
];
$aliasReport = GboValidator::validateCatalog([
    'gbo_base_aliases' => $conflictingAliases,
]);
if (($aliasReport['ok'] ?? false) === true) {
    $failures[] = 'GBO debe bloquear aliases conflictivos.';
}

try {
    $repo = new ContractRepository();
    $schema = $repo->getSchema('gbo.schema');
    if (($schema['contract_id'] ?? '') !== 'global_business_ontology') {
        $failures[] = 'ContractRepository debe resolver gbo.schema.json.';
    }
} catch (Throwable $e) {
    $failures[] = 'Schema repository GBO fallo: ' . $e->getMessage();
}

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @return array<string, mixed>
 */
function loadJson(string $path): array
{
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}
