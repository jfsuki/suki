<?php
// app/Core/TrainingDatasetValidator.php

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class TrainingDatasetValidator
{
    private const DEFAULT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/training_dataset_ingest.schema.json';

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     * @return array{ok: bool, errors: array<int, array<string, string>>, warnings: array<int, array<string, string>>, stats: array<string, mixed>}
     */
    public static function validate(array $payload, array $options = [], ?string $schemaPath = null): array
    {
        $schemaPath = $schemaPath ?? self::DEFAULT_SCHEMA;
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema de training dataset no existe: ' . $schemaPath);
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de training dataset invalido.');
        }

        $errors = [];
        $warnings = [];

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload dataset no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'Dataset invalido por schema.';
            $errors[] = ['path' => '$', 'message' => $message];
            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'stats' => self::buildStats($payload),
            ];
        }

        self::validateSemanticRules($payload, $errors, $warnings, $options);

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => self::buildStats($payload),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public static function validateOrFail(array $payload, array $options = [], ?string $schemaPath = null): void
    {
        $report = self::validate($payload, $options, $schemaPath);
        if (!$report['ok']) {
            $first = $report['errors'][0]['message'] ?? 'Dataset invalido.';
            throw new RuntimeException($first);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     * @param array<string, mixed> $options
     */
    private static function validateSemanticRules(array $payload, array &$errors, array &$warnings, array $options): void
    {
        $minExplicit = max(1, (int) ($options['min_explicit'] ?? 40));
        $minImplicit = max(1, (int) ($options['min_implicit'] ?? 40));
        $minHardNeg = max(1, (int) ($options['min_hard_negatives'] ?? 40));
        $minDialogues = max(1, (int) ($options['min_dialogues'] ?? 10));
        $minQaCases = max(1, (int) ($options['min_qa_cases'] ?? 10));
        $context = is_array($payload['context_pack'] ?? null) ? $payload['context_pack'] : [];
        if ($context === []) {
            self::addWarning($warnings, '$.context_pack', 'Sin context_pack: faltan reglas fiscales/operativas para prompts de alta precision.');
        } else {
            $requiredContext = ['business_type', 'country', 'currency', 'tax_regime'];
            foreach ($requiredContext as $field) {
                if (trim((string) ($context[$field] ?? '')) === '') {
                    self::addWarning($warnings, '$.context_pack.' . $field, 'Contexto recomendado faltante.');
                }
            }
        }

        $intents = is_array($payload['intents_expansion'] ?? null) ? $payload['intents_expansion'] : [];
        foreach ($intents as $i => $intent) {
            if (!is_array($intent)) {
                self::addError($errors, '$.intents_expansion[' . $i . ']', 'Intent expansion no es objeto.');
                continue;
            }
            $path = '$.intents_expansion[' . $i . ']';
            $explicit = self::stringList($intent['utterances_explicit'] ?? []);
            $implicit = self::stringList($intent['utterances_implicit'] ?? []);
            $negatives = self::stringList($intent['hard_negatives'] ?? []);

            if (count($explicit) < $minExplicit) {
                self::addWarning($warnings, $path . '.utterances_explicit', 'Cobertura baja: ' . count($explicit) . ' < ' . $minExplicit);
            }
            if (count($implicit) < $minImplicit) {
                self::addWarning($warnings, $path . '.utterances_implicit', 'Cobertura baja: ' . count($implicit) . ' < ' . $minImplicit);
            }
            if (count($negatives) < $minHardNeg) {
                self::addWarning($warnings, $path . '.hard_negatives', 'Cobertura baja: ' . count($negatives) . ' < ' . $minHardNeg);
            }

            self::detectDuplicates($explicit, $errors, $path . '.utterances_explicit');
            self::detectDuplicates($implicit, $errors, $path . '.utterances_implicit');
            self::detectDuplicates($negatives, $errors, $path . '.hard_negatives');

            self::validateOverlap($explicit, $negatives, $errors, $path, 'explicit vs hard_negatives');
            self::validateOverlap($implicit, $negatives, $errors, $path, 'implicit vs hard_negatives');

            $slots = is_array($intent['slots'] ?? null) ? $intent['slots'] : [];
            $slotNames = [];
            foreach ($slots as $s => $slot) {
                if (!is_array($slot)) {
                    self::addError($errors, $path . '.slots[' . $s . ']', 'Slot no es objeto.');
                    continue;
                }
                $slotName = trim((string) ($slot['name'] ?? ''));
                if ($slotName === '') {
                    self::addError($errors, $path . '.slots[' . $s . ']', 'Slot sin nombre.');
                    continue;
                }
                $slotKey = self::toKey($slotName);
                if (isset($slotNames[$slotKey])) {
                    self::addError($errors, $path . '.slots[' . $s . ']', 'Slot duplicado: ' . $slotName);
                }
                $slotNames[$slotKey] = true;
            }

            $policies = is_array($intent['one_question_policy'] ?? null) ? $intent['one_question_policy'] : [];
            $policyBySlot = [];
            foreach ($policies as $p => $policy) {
                if (!is_array($policy)) {
                    self::addError($errors, $path . '.one_question_policy[' . $p . ']', 'Policy no es objeto.');
                    continue;
                }
                $missing = trim((string) ($policy['missing_slot'] ?? ''));
                $question = trim((string) ($policy['question'] ?? ''));
                if ($missing === '' || $question === '') {
                    self::addError($errors, $path . '.one_question_policy[' . $p . ']', 'Policy incompleta.');
                    continue;
                }
                $slotKey = self::toKey($missing);
                $policyBySlot[$slotKey] = true;
                if (!isset($slotNames[$slotKey])) {
                    self::addError(
                        $errors,
                        $path . '.one_question_policy[' . $p . ']',
                        'Policy referencia slot inexistente: ' . $missing
                    );
                }
            }

            foreach (array_keys($slotNames) as $slotKey) {
                if (!isset($policyBySlot[$slotKey])) {
                    self::addError(
                        $errors,
                        $path . '.one_question_policy',
                        'Falta pregunta minima para slot: ' . $slotKey
                    );
                }
            }
        }

        $dialogues = is_array($payload['multi_turn_dialogues'] ?? null) ? $payload['multi_turn_dialogues'] : [];
        if (count($dialogues) < $minDialogues) {
            self::addWarning($warnings, '$.multi_turn_dialogues', 'Cobertura baja: ' . count($dialogues) . ' < ' . $minDialogues);
        }
        foreach ($dialogues as $d => $dialogue) {
            if (!is_array($dialogue)) {
                self::addError($errors, '$.multi_turn_dialogues[' . $d . ']', 'Dialogo no es objeto.');
                continue;
            }
            $turns = is_array($dialogue['turns'] ?? null) ? $dialogue['turns'] : [];
            if (count($turns) < 2) {
                self::addError($errors, '$.multi_turn_dialogues[' . $d . '].turns', 'Dialogo con menos de 2 turnos.');
            }
        }

        $qaCases = is_array($payload['qa_cases'] ?? null) ? $payload['qa_cases'] : [];
        if (count($qaCases) < $minQaCases) {
            self::addWarning($warnings, '$.qa_cases', 'Cobertura baja: ' . count($qaCases) . ' < ' . $minQaCases);
        }
        $qaTexts = [];
        foreach ($qaCases as $q => $qa) {
            if (!is_array($qa)) {
                self::addError($errors, '$.qa_cases[' . $q . ']', 'QA case no es objeto.');
                continue;
            }
            $text = trim((string) ($qa['text'] ?? ''));
            $key = self::toKey($text);
            if ($key === '') {
                self::addError($errors, '$.qa_cases[' . $q . ']', 'QA case sin text.');
                continue;
            }
            if (isset($qaTexts[$key])) {
                self::addWarning($warnings, '$.qa_cases[' . $q . ']', 'QA text duplicado: ' . $text);
            }
            $qaTexts[$key] = true;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function buildStats(array $payload): array
    {
        $intents = is_array($payload['intents_expansion'] ?? null) ? $payload['intents_expansion'] : [];
        $explicit = 0;
        $implicit = 0;
        $negatives = 0;
        foreach ($intents as $intent) {
            if (!is_array($intent)) {
                continue;
            }
            $explicit += count(self::stringList($intent['utterances_explicit'] ?? []));
            $implicit += count(self::stringList($intent['utterances_implicit'] ?? []));
            $negatives += count(self::stringList($intent['hard_negatives'] ?? []));
        }

        $dialogues = is_array($payload['multi_turn_dialogues'] ?? null) ? $payload['multi_turn_dialogues'] : [];
        $emotion = is_array($payload['emotion_cases'] ?? null) ? $payload['emotion_cases'] : [];
        $qa = is_array($payload['qa_cases'] ?? null) ? $payload['qa_cases'] : [];

        return [
            'intents_expansion' => count($intents),
            'utterances_explicit_total' => $explicit,
            'utterances_implicit_total' => $implicit,
            'hard_negatives_total' => $negatives,
            'multi_turn_dialogues' => count($dialogues),
            'emotion_cases' => count($emotion),
            'qa_cases' => count($qa),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return $result;
    }

    /**
     * @param array<int, string> $values
     * @param array<int, array<string, string>> $errors
     */
    private static function detectDuplicates(array $values, array &$errors, string $path): void
    {
        $seen = [];
        foreach ($values as $value) {
            $key = self::toKey($value);
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                self::addError($errors, $path, 'Duplicado detectado: ' . $value);
                return;
            }
            $seen[$key] = true;
        }
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     * @param array<int, array<string, string>> $errors
     */
    private static function validateOverlap(array $left, array $right, array &$errors, string $path, string $label): void
    {
        $leftKeys = [];
        foreach ($left as $value) {
            $key = self::toKey($value);
            if ($key !== '') {
                $leftKeys[$key] = $value;
            }
        }

        foreach ($right as $value) {
            $key = self::toKey($value);
            if ($key !== '' && isset($leftKeys[$key])) {
                self::addError($errors, $path, 'Conflicto ' . $label . ': "' . $value . '" aparece en ambos grupos.');
                return;
            }
        }
    }

    private static function toKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    /**
     * @param array<int, array<string, string>> $errors
     */
    private static function addError(array &$errors, string $path, string $message): void
    {
        $errors[] = ['path' => $path, 'message' => $message];
    }

    /**
     * @param array<int, array<string, string>> $warnings
     */
    private static function addWarning(array &$warnings, string $path, string $message): void
    {
        $warnings[] = ['path' => $path, 'message' => $message];
    }
}
