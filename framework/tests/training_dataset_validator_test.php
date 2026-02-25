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
}

$result = [
    'ok' => empty($failures),
    'failures' => $failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
