<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\BusinessDiscoveryDatasetCompiler;
use App\Core\TrainingDatasetValidator;

$templatePath = PROJECT_ROOT . '/contracts/knowledge/business_discovery_template.json';
$seedPath = PROJECT_ROOT . '/contracts/knowledge/sector_seed_FERRETERIA_MINORISTA.sample.json';
$failures = [];

$payload = readJson($templatePath);
$seed = readJson($seedPath);

if (!is_array($payload)) {
    $failures[] = 'Business discovery template invalido.';
}
if (!is_array($seed)) {
    $failures[] = 'Sector seed sample invalido.';
}

if ($failures === []) {
    $payload['sector_seed'] = $seed;

    try {
        $compiled = BusinessDiscoveryDatasetCompiler::compile($payload);
    } catch (Throwable $e) {
        $compiled = null;
        $failures[] = 'No se pudo compilar business discovery con sector seed: ' . $e->getMessage();
    }

    if (is_array($compiled)) {
        $dataset = is_array($compiled['dataset'] ?? null) ? $compiled['dataset'] : [];
        $knowledge = is_array($dataset['knowledge_stable'] ?? null) ? $dataset['knowledge_stable'] : [];
        $faq = is_array($dataset['support_faq'] ?? null) ? $dataset['support_faq'] : [];
        $intents = is_array($dataset['intents_expansion'] ?? null) ? $dataset['intents_expansion'] : [];

        $foundSeedKnowledge = false;
        foreach ($knowledge as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (str_contains((string) ($entry['title'] ?? ''), 'Operacion de mostrador')) {
                $foundSeedKnowledge = true;
                break;
            }
        }
        if (!$foundSeedKnowledge) {
            $failures[] = 'El compilador debe anexar knowledge_stable desde sector seed.';
        }

        $foundSeedFaq = false;
        foreach ($faq as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((string) ($entry['question'] ?? '') === 'Como atiendo una venta rapida de mostrador?') {
                $foundSeedFaq = true;
                break;
            }
        }
        if (!$foundSeedFaq) {
            $failures[] = 'El compilador debe anexar support_faq desde sector seed.';
        }

        $foundInventoryOverride = false;
        foreach ($intents as $intent) {
            if (!is_array($intent)) {
                continue;
            }
            if ((string) ($intent['action'] ?? '') !== 'inventory_check') {
                continue;
            }
            $explicit = is_array($intent['utterances_explicit'] ?? null) ? $intent['utterances_explicit'] : [];
            $implicit = is_array($intent['utterances_implicit'] ?? null) ? $intent['utterances_implicit'] : [];
            $negatives = is_array($intent['hard_negatives'] ?? null) ? $intent['hard_negatives'] : [];
            if (
                in_array('revisa stock de mostrador', $explicit, true)
                && in_array('mira si aun queda en vitrina', $implicit, true)
                && in_array('cerrar inventario anual', $negatives, true)
            ) {
                $foundInventoryOverride = true;
                break;
            }
        }
        if (!$foundInventoryOverride) {
            $failures[] = 'El compilador debe anexar skill_overrides del sector seed.';
        }

        $report = TrainingDatasetValidator::validate($dataset, [
            'min_explicit' => 1,
            'min_implicit' => 1,
            'min_hard_negatives' => 1,
            'min_dialogues' => 1,
            'min_qa_cases' => 1,
        ]);
        if (($report['ok'] ?? false) !== true) {
            $failures[] = 'El dataset compilado con sector seed debe seguir validando.';
        }
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
