<?php
// framework/scripts/sync_domain_training.php

declare(strict_types=1);

$frameworkRoot = dirname(__DIR__);
$domainPath = $frameworkRoot . '/contracts/agents/domain_playbooks.json';
$trainingPath = $frameworkRoot . '/contracts/agents/conversation_training_base.json';

$checkOnly = in_array('--check', $argv, true);

$domain = read_json($domainPath);
$training = read_json($trainingPath);

$updated = sync_training_from_domain($training, $domain);

$changed = json_encode($training, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    !== json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($checkOnly) {
    if ($changed) {
        fwrite(STDERR, "DRIFT_DETECTED: conversation_training_base.json no esta sincronizado con domain_playbooks.json\n");
        exit(1);
    }
    echo "OK: domain/training sync sin drift\n";
    exit(0);
}

if (!$changed) {
    echo "No changes. conversation_training_base.json ya estaba sincronizado.\n";
    exit(0);
}

write_json($trainingPath, $updated);
echo "Synced: " . $trainingPath . "\n";

function sync_training_from_domain(array $training, array $domain): array
{
    $domainSolverIntents = is_array($domain['solver_intents'] ?? null) ? $domain['solver_intents'] : [];
    $builderGuidance = is_array($domain['builder_guidance'] ?? null) ? $domain['builder_guidance'] : [];

    if (!isset($training['intents']) || !is_array($training['intents'])) {
        $training['intents'] = [];
    }

    $existingByName = [];
    foreach ($training['intents'] as $index => $intent) {
        $name = trim((string) ($intent['name'] ?? ''));
        if ($name !== '') {
            $existingByName[$name] = $index;
        }
    }

    $domainSolverByName = [];
    foreach ($domainSolverIntents as $solverIntent) {
        if (!is_array($solverIntent)) {
            continue;
        }
        $name = trim((string) ($solverIntent['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $domainSolverByName[$name] = $solverIntent;
    }

    // Remove stale solver intents from training (single source: domain playbooks).
    $filtered = [];
    foreach ($training['intents'] as $intent) {
        $name = trim((string) ($intent['name'] ?? ''));
        if (str_starts_with($name, 'SOLVE_') && !isset($domainSolverByName[$name])) {
            continue;
        }
        $filtered[] = $intent;
    }
    $training['intents'] = $filtered;

    // Rebuild lookup after filtering.
    $existingByName = [];
    foreach ($training['intents'] as $index => $intent) {
        $name = trim((string) ($intent['name'] ?? ''));
        if ($name !== '') {
            $existingByName[$name] = $index;
        }
    }

    foreach ($domainSolverByName as $name => $solverIntent) {
        $existing = [];
        if (isset($existingByName[$name])) {
            $existing = (array) ($training['intents'][$existingByName[$name]] ?? []);
        }
        $merged = merge_solver_intent($solverIntent, $existing);
        if (isset($existingByName[$name])) {
            $training['intents'][$existingByName[$name]] = $merged;
        } else {
            $training['intents'][] = $merged;
        }
    }

    ensure_flow_control_back($training['intents']);
    $training['builder_guidance'] = $builderGuidance;
    ensure_sync_note($training);
    return $training;
}

function merge_solver_intent(array $solverIntent, array $existing): array
{
    $name = trim((string) ($solverIntent['name'] ?? ''));
    $action = trim((string) ($solverIntent['action'] ?? ''));
    $utterances = normalize_utterances(is_array($solverIntent['utterances'] ?? null) ? $solverIntent['utterances'] : []);

    $intent = $existing;
    $intent['name'] = $name;
    $intent['description'] = trim((string) ($existing['description'] ?? ''));
    if ($intent['description'] === '') {
        $intent['description'] = 'Resolver dolor de negocio usando playbook sectorial validado.';
    }
    $intent['action'] = $action;
    $intent['required_entities'] = normalize_list($existing['required_entities'] ?? []);
    $intent['optional_entities'] = normalize_list($existing['optional_entities'] ?? []);
    $intent['utterances'] = $utterances;
    $intent['slot_filling'] = normalize_array($existing['slot_filling'] ?? []);
    $intent['examples'] = normalize_array($existing['examples'] ?? []);
    $intent['notes'] = normalize_list($existing['notes'] ?? []);
    if (empty($intent['notes'])) {
        $intent['notes'] = [
            'Intent sectorial auto-sincronizado desde domain_playbooks.',
            'Sugerir playbook y confirmar siguiente paso minimo.',
        ];
    }

    $responseStyle = is_array($existing['response_style'] ?? null) ? $existing['response_style'] : [];
    if (empty($responseStyle)) {
        $responseStyle = [
            'tone' => 'muy simple, cero tecnicismos',
            'length' => 'breve',
            'format' => 'diagnostico corto + siguiente paso',
            'guardrails' => [
                'No usar jerga',
                'No usar comandos visibles al usuario final',
            ],
        ];
    }
    $intent['response_style'] = $responseStyle;
    $disambiguation = trim((string) ($existing['disambiguation_prompt'] ?? ''));
    $intent['disambiguation_prompt'] = $disambiguation !== ''
        ? $disambiguation
        : 'Quieres que te instale esta plantilla en el Creador de apps?';

    return $intent;
}

function ensure_sync_note(array &$training): void
{
    if (!isset($training['meta']) || !is_array($training['meta'])) {
        $training['meta'] = [];
    }
    if (!isset($training['meta']['notes']) || !is_array($training['meta']['notes'])) {
        $training['meta']['notes'] = [];
    }

    $note = 'v0.3.9: solver_intents y builder_guidance sincronizados desde domain_playbooks (single source).';
    if (!in_array($note, $training['meta']['notes'], true)) {
        $training['meta']['notes'][] = $note;
    }
    $training['meta']['updated'] = date('Y-m-d');
}

function ensure_flow_control_back(array &$intents): void
{
    foreach ($intents as $intent) {
        if ((string) ($intent['name'] ?? '') === 'FLOW_CONTROL_BACK') {
            return;
        }
    }

    $intents[] = [
        'name' => 'FLOW_CONTROL_BACK',
        'description' => 'Volver al paso anterior del flujo activo sin perder contexto.',
        'action' => 'FLOW_BACK',
        'required_entities' => [],
        'optional_entities' => [],
        'utterances' => [
            'atras',
            'volver',
            'volver atras',
            'paso anterior',
            'regresar',
        ],
        'slot_filling' => [],
        'examples' => [],
        'notes' => [
            'Debe regresar al paso anterior respetando la maquina de estado.',
        ],
        'response_style' => [
            'tone' => 'neutro',
            'length' => 'muy breve',
            'format' => 'estado + siguiente paso',
        ],
        'disambiguation_prompt' => 'Volvemos al paso anterior del flujo?',
    ];
}

function normalize_utterances(array $values): array
{
    $seen = [];
    $result = [];
    foreach ($values as $value) {
        $utterance = trim((string) $value);
        if ($utterance === '') {
            continue;
        }
        $key = mb_strtolower($utterance, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $utterance;
    }
    return $result;
}

function normalize_list(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }
    return array_values(array_filter(array_map(
        static fn($value): string => trim((string) $value),
        $values
    ), static fn(string $value): bool => $value !== ''));
}

function normalize_array(mixed $values): array
{
    return is_array($values) ? array_values($values) : [];
}

function read_json(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('JSON file not found: ' . $path);
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('JSON file empty: ' . $path);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON: ' . $path);
    }
    return $decoded;
}

function write_json(string $path, array $data): void
{
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('Cannot encode JSON for: ' . $path);
    }
    $ok = file_put_contents($path, $payload . PHP_EOL, LOCK_EX);
    if ($ok === false) {
        throw new RuntimeException('Cannot write file: ' . $path);
    }
}
