<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\SectorSeedValidator;

$seedPath = PROJECT_ROOT . '/contracts/knowledge/sector_seed_FERRETERIA_MINORISTA.sample.json';
$payload = readJson($seedPath);
$failures = [];

if (!is_array($payload)) {
    $failures[] = 'Sector seed sample JSON invalido.';
} else {
    $report = SectorSeedValidator::validate($payload, [
        'expected_sector_key' => 'FERRETERIA_MINORISTA',
        'expected_sector_label' => 'Ferreteria minorista',
        'expected_country_or_regulation' => 'CO - IVA, retenciones y PUC comercial',
    ]);
    if (($report['ok'] ?? false) !== true) {
        $failures[] = 'Sector seed sample debe validar.';
    }

    $mismatch = $payload;
    $mismatch['sector_key'] = 'FARMACIA';
    $mismatchReport = SectorSeedValidator::validate($mismatch, [
        'expected_sector_key' => 'FERRETERIA_MINORISTA',
    ]);
    if (($mismatchReport['ok'] ?? false) === true) {
        $failures[] = 'SectorSeedValidator debe bloquear sector_key inconsistente.';
    }

    $invalidSkill = $payload;
    $invalidSkill['skill_overrides'][0]['skill'] = 'ghost_skill';
    $invalidSkillReport = SectorSeedValidator::validate($invalidSkill, [
        'expected_sector_key' => 'FERRETERIA_MINORISTA',
    ]);
    if (($invalidSkillReport['ok'] ?? false) === true) {
        $failures[] = 'SectorSeedValidator debe bloquear skill inexistente.';
    }

    $invalidGbo = $payload;
    $invalidGbo['knowledge_stable_seed'][0]['gbo_concepts'] = ['ghost_concept'];
    $invalidGboReport = SectorSeedValidator::validate($invalidGbo, [
        'expected_sector_key' => 'FERRETERIA_MINORISTA',
    ]);
    if (($invalidGboReport['ok'] ?? false) === true) {
        $failures[] = 'SectorSeedValidator debe bloquear concepto GBO inexistente.';
    }
}

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

/**
 * @return array<string, mixed>|null
 */
function readJson(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}
