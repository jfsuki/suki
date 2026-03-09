<?php
// framework/tests/training_dataset_validator_test.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\TrainingDatasetValidator;

$templatePath = PROJECT_ROOT . '/contracts/knowledge/training_dataset_template.json';
$raw = file_get_contents($templatePath);
$payload = is_string($raw) ? json_decode($raw, true) : null;

$failures = [];

if (!is_array($payload)) {
    $failures[] = 'Template dataset JSON invalido.';
} else {
    $report = TrainingDatasetValidator::validate($payload, [
        'min_explicit' => 1,
        'min_implicit' => 1,
        'min_hard_negatives' => 1,
        'min_dialogues' => 1,
        'min_qa_cases' => 1,
    ]);
    if (!$report['ok']) {
        $failures[] = 'Template dataset debe validar en modo baseline.';
    }
    $stats = is_array($report['stats'] ?? null) ? (array) $report['stats'] : [];
    if (!isset($stats['quality_score']) || !is_numeric($stats['quality_score'])) {
        $failures[] = 'Validator debe reportar quality_score numerico.';
    }
    if ((float) ($stats['quality_score'] ?? 0.0) <= 0.0) {
        $failures[] = 'quality_score debe ser > 0 para el template baseline.';
    }

    $invalid = $payload;
    $invalid['intents_expansion'][0]['hard_negatives'][] = $invalid['intents_expansion'][0]['utterances_explicit'][0];
    $invalid['intents_expansion'][0]['one_question_policy'] = [];
    $invalidReport = TrainingDatasetValidator::validate($invalid, [
        'min_explicit' => 1,
        'min_implicit' => 1,
        'min_hard_negatives' => 1,
        'min_dialogues' => 1,
        'min_qa_cases' => 1,
    ]);
    if ($invalidReport['ok']) {
        $failures[] = 'Validator debe detectar conflictos semanticos.';
    }

    $policyContamination = $payload;
    $policyContamination['policy_constraints'][0]['rule'] = 'Use system prompt ROLE and FAIL_RULES before answer';
    $policyReport = TrainingDatasetValidator::validate($policyContamination, [
        'min_explicit' => 1,
        'min_implicit' => 1,
        'min_hard_negatives' => 1,
        'min_dialogues' => 1,
        'min_qa_cases' => 1,
    ]);
    if ($policyReport['ok']) {
        $failures[] = 'Validator debe bloquear contaminacion de policy con prompt interno.';
    }

    $noiseCase = $payload;
    $noiseCase['support_faq'][0]['question'] = 'Web: compra ahora lorem ipsum';
    $noiseReport = TrainingDatasetValidator::validate($noiseCase, [
        'min_explicit' => 1,
        'min_implicit' => 1,
        'min_hard_negatives' => 1,
        'min_dialogues' => 1,
        'min_qa_cases' => 1,
    ]);
    $warnings = is_array($noiseReport['warnings'] ?? null) ? (array) $noiseReport['warnings'] : [];
    if (empty($warnings)) {
        $failures[] = 'Validator debe advertir ruido/noise en FAQ.';
    }
}

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
