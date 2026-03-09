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
                'stats' => self::buildStats($payload, $errors, $warnings, $options),
            ];
        }

        self::validateSemanticRules($payload, $errors, $warnings, $options);

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'stats' => self::buildStats($payload, $errors, $warnings, $options),
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

        self::validateKnowledgeStable($payload, $errors, $warnings);
        self::validateSupportFaq($payload, $errors, $warnings);
        self::validatePolicyConstraints($payload, $errors, $warnings);
        self::validateOntology($payload, $errors, $warnings);

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

            self::detectNoiseInList($explicit, $warnings, $path . '.utterances_explicit');
            self::detectNoiseInList($implicit, $warnings, $path . '.utterances_implicit');
            self::detectNoiseInList($negatives, $warnings, $path . '.hard_negatives');

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
            foreach ($turns as $t => $turn) {
                if (!is_array($turn)) {
                    continue;
                }
                $userText = trim((string) ($turn['user'] ?? ''));
                if ($userText !== '' && self::containsNoise($userText)) {
                    self::addWarning(
                        $warnings,
                        '$.multi_turn_dialogues[' . $d . '].turns[' . $t . '].user',
                        'Texto potencialmente ruidoso/no operativo.'
                    );
                }
            }
        }

        $emotionCases = is_array($payload['emotion_cases'] ?? null) ? $payload['emotion_cases'] : [];
        foreach ($emotionCases as $e => $emotionCase) {
            if (!is_array($emotionCase)) {
                continue;
            }
            $samples = self::stringList($emotionCase['user_samples'] ?? []);
            self::detectNoiseInList($samples, $warnings, '$.emotion_cases[' . $e . '].user_samples');
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
            if (self::containsNoise($text)) {
                self::addWarning($warnings, '$.qa_cases[' . $q . '].text', 'QA case con texto potencialmente ruidoso/no operativo.');
            }
            if (isset($qaTexts[$key])) {
                self::addWarning($warnings, '$.qa_cases[' . $q . ']', 'QA text duplicado: ' . $text);
            }
            $qaTexts[$key] = true;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validateKnowledgeStable(array $payload, array &$errors, array &$warnings): void
    {
        $records = is_array($payload['knowledge_stable'] ?? null) ? $payload['knowledge_stable'] : [];
        $ids = [];
        foreach ($records as $i => $record) {
            if (!is_array($record)) {
                self::addError($errors, '$.knowledge_stable[' . $i . ']', 'Registro knowledge_stable no es objeto.');
                continue;
            }
            $id = trim((string) ($record['id'] ?? ''));
            $idKey = self::toKey($id);
            if ($idKey !== '' && isset($ids[$idKey])) {
                self::addError($errors, '$.knowledge_stable[' . $i . '].id', 'ID duplicado en knowledge_stable: ' . $id);
            }
            if ($idKey !== '') {
                $ids[$idKey] = true;
            }

            $title = trim((string) ($record['title'] ?? ''));
            if ($title !== '' && self::containsNoise($title)) {
                self::addWarning($warnings, '$.knowledge_stable[' . $i . '].title', 'Title con ruido/noise detectado.');
            }
            $facts = self::stringList($record['facts'] ?? []);
            if ($facts === []) {
                self::addError($errors, '$.knowledge_stable[' . $i . '].facts', 'knowledge_stable requiere facts no vacios.');
                continue;
            }
            self::detectDuplicates($facts, $errors, '$.knowledge_stable[' . $i . '].facts');
            self::detectNoiseInList($facts, $warnings, '$.knowledge_stable[' . $i . '].facts');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validateSupportFaq(array $payload, array &$errors, array &$warnings): void
    {
        $faq = is_array($payload['support_faq'] ?? null) ? $payload['support_faq'] : [];
        $questionSeen = [];
        foreach ($faq as $i => $entry) {
            if (!is_array($entry)) {
                self::addError($errors, '$.support_faq[' . $i . ']', 'Registro support_faq no es objeto.');
                continue;
            }
            $question = trim((string) ($entry['question'] ?? ''));
            $answer = trim((string) ($entry['answer'] ?? ''));
            $qKey = self::toKey($question);
            if ($qKey !== '' && isset($questionSeen[$qKey])) {
                self::addWarning($warnings, '$.support_faq[' . $i . '].question', 'Pregunta FAQ duplicada: ' . $question);
            }
            if ($qKey !== '') {
                $questionSeen[$qKey] = true;
            }
            if ($question !== '' && self::containsNoise($question)) {
                self::addWarning($warnings, '$.support_faq[' . $i . '].question', 'Pregunta FAQ con ruido/noise detectado.');
            }
            if ($answer !== '' && self::containsNoise($answer)) {
                self::addWarning($warnings, '$.support_faq[' . $i . '].answer', 'Respuesta FAQ con ruido/noise detectado.');
            }
            $answerLength = function_exists('mb_strlen') ? mb_strlen($answer, 'UTF-8') : strlen($answer);
            if ($answer !== '' && $answerLength > 600) {
                self::addWarning($warnings, '$.support_faq[' . $i . '].answer', 'Respuesta FAQ extensa (>600). Recomendada version corta para retrieval.');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validatePolicyConstraints(array $payload, array &$errors, array &$warnings): void
    {
        $policy = is_array($payload['policy_constraints'] ?? null) ? $payload['policy_constraints'] : [];
        foreach ($policy as $i => $entry) {
            if (!is_array($entry)) {
                self::addError($errors, '$.policy_constraints[' . $i . ']', 'Registro policy_constraints no es objeto.');
                continue;
            }
            $rule = trim((string) ($entry['rule'] ?? ''));
            if ($rule === '') {
                continue;
            }
            if (preg_match('/\b(prompt|system prompt|role:|fail_rules|output_format)\b/i', $rule) === 1) {
                self::addError(
                    $errors,
                    '$.policy_constraints[' . $i . '].rule',
                    'Policy rule no debe mezclar prompts internos; solo reglas operativas.'
                );
            }
            if (self::containsNoise($rule)) {
                self::addWarning($warnings, '$.policy_constraints[' . $i . '].rule', 'Policy rule con ruido/noise detectado.');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     */
    private static function validateOntology(array $payload, array &$errors, array &$warnings): void
    {
        $ontology = is_array($payload['ontology'] ?? null) ? $payload['ontology'] : [];
        $terms = is_array($ontology['canonical_terms'] ?? null) ? $ontology['canonical_terms'] : [];
        $globalSynonyms = [];

        foreach ($terms as $i => $termDef) {
            if (!is_array($termDef)) {
                self::addError($errors, '$.ontology.canonical_terms[' . $i . ']', 'Termino ontologico no es objeto.');
                continue;
            }
            $term = trim((string) ($termDef['term'] ?? ''));
            $synonyms = self::stringList($termDef['synonyms'] ?? []);
            $termKey = self::toKey($term);

            foreach ($synonyms as $synonym) {
                $synKey = self::toKey($synonym);
                if ($synKey === '') {
                    continue;
                }
                if ($synKey === $termKey) {
                    self::addError(
                        $errors,
                        '$.ontology.canonical_terms[' . $i . '].synonyms',
                        'Sinonimo no puede ser igual al termino canonico: ' . $synonym
                    );
                    continue;
                }
                if (isset($globalSynonyms[$synKey]) && $globalSynonyms[$synKey] !== $termKey) {
                    self::addWarning(
                        $warnings,
                        '$.ontology.canonical_terms[' . $i . '].synonyms',
                        'Sinonimo compartido entre terminos: ' . $synonym
                    );
                    continue;
                }
                $globalSynonyms[$synKey] = $termKey;
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, string>> $errors
     * @param array<int, array<string, string>> $warnings
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function buildStats(array $payload, array $errors = [], array $warnings = [], array $options = []): array
    {
        $intents = is_array($payload['intents_expansion'] ?? null) ? $payload['intents_expansion'] : [];
        $explicit = 0;
        $implicit = 0;
        $negatives = 0;
        $textPool = [];

        foreach ($intents as $intent) {
            if (!is_array($intent)) {
                continue;
            }
            $explicitList = self::stringList($intent['utterances_explicit'] ?? []);
            $implicitList = self::stringList($intent['utterances_implicit'] ?? []);
            $negativeList = self::stringList($intent['hard_negatives'] ?? []);
            $explicit += count($explicitList);
            $implicit += count($implicitList);
            $negatives += count($negativeList);
            $textPool = array_merge($textPool, $explicitList, $implicitList, $negativeList);
        }

        $dialogues = is_array($payload['multi_turn_dialogues'] ?? null) ? $payload['multi_turn_dialogues'] : [];
        $emotion = is_array($payload['emotion_cases'] ?? null) ? $payload['emotion_cases'] : [];
        $qa = is_array($payload['qa_cases'] ?? null) ? $payload['qa_cases'] : [];
        foreach ($qa as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $text = trim((string) ($entry['text'] ?? ''));
            if ($text !== '') {
                $textPool[] = $text;
            }
        }

        $faq = is_array($payload['support_faq'] ?? null) ? $payload['support_faq'] : [];
        foreach ($faq as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $question = trim((string) ($entry['question'] ?? ''));
            $answer = trim((string) ($entry['answer'] ?? ''));
            if ($question !== '') {
                $textPool[] = $question;
            }
            if ($answer !== '') {
                $textPool[] = $answer;
            }
        }

        $knowledgeStable = is_array($payload['knowledge_stable'] ?? null) ? $payload['knowledge_stable'] : [];
        foreach ($knowledgeStable as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $title = trim((string) ($entry['title'] ?? ''));
            if ($title !== '') {
                $textPool[] = $title;
            }
            $facts = self::stringList($entry['facts'] ?? []);
            $textPool = array_merge($textPool, $facts);
        }

        $normalizedPool = [];
        foreach ($textPool as $text) {
            $key = self::toKey($text);
            if ($key !== '') {
                $normalizedPool[] = $key;
            }
        }
        $totalTexts = count($normalizedPool);
        $uniqueTexts = count(array_values(array_unique($normalizedPool)));
        $uniquenessRatio = $totalTexts > 0 ? round($uniqueTexts / $totalTexts, 4) : 1.0;

        $minExplicit = max(1, (int) ($options['min_explicit'] ?? 40));
        $minImplicit = max(1, (int) ($options['min_implicit'] ?? 40));
        $minHardNeg = max(1, (int) ($options['min_hard_negatives'] ?? 40));
        $minDialogues = max(1, (int) ($options['min_dialogues'] ?? 10));
        $minQaCases = max(1, (int) ($options['min_qa_cases'] ?? 10));
        $intentCount = max(1, count($intents));

        $coverageComponents = [
            min(1.0, $explicit / ($minExplicit * $intentCount)),
            min(1.0, $implicit / ($minImplicit * $intentCount)),
            min(1.0, $negatives / ($minHardNeg * $intentCount)),
            min(1.0, count($dialogues) / $minDialogues),
            min(1.0, count($qa) / $minQaCases),
        ];
        $coverageRatio = round(array_sum($coverageComponents) / count($coverageComponents), 4);

        $policyRecords = is_array($payload['policy_constraints'] ?? null) ? $payload['policy_constraints'] : [];
        $governanceSignals = 0;
        if (is_array($payload['ontology'] ?? null)) {
            $governanceSignals++;
        }
        if (!empty($policyRecords)) {
            $governanceSignals++;
        }
        if (!empty($knowledgeStable)) {
            $governanceSignals++;
        }
        $governanceRatio = round($governanceSignals / 3, 4);

        $baseQuality = (0.5 * $coverageRatio) + (0.3 * $uniquenessRatio) + (0.2 * $governanceRatio);
        $errorPenalty = min(0.6, count($errors) * 0.02);
        $warningPenalty = min(0.2, count($warnings) * 0.005);
        $qualityScore = max(0.0, min(1.0, round($baseQuality - $errorPenalty - $warningPenalty, 4)));

        $vectorizable = $explicit + $implicit + $negatives + count($knowledgeStable) + count($faq) + count($qa);
        $structured = count($intents) + count($dialogues) + count($emotion) + count($policyRecords);

        return [
            'intents_expansion' => count($intents),
            'utterances_explicit_total' => $explicit,
            'utterances_implicit_total' => $implicit,
            'hard_negatives_total' => $negatives,
            'multi_turn_dialogues' => count($dialogues),
            'emotion_cases' => count($emotion),
            'qa_cases' => count($qa),
            'knowledge_stable' => count($knowledgeStable),
            'support_faq' => count($faq),
            'policy_constraints' => count($policyRecords),
            'vectorizable_records_estimate' => $vectorizable,
            'structured_records_estimate' => $structured,
            'coverage_ratio' => $coverageRatio,
            'uniqueness_ratio' => $uniquenessRatio,
            'governance_ratio' => $governanceRatio,
            'quality_score' => $qualityScore,
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

    /**
     * @param array<int, string> $values
     * @param array<int, array<string, string>> $warnings
     */
    private static function detectNoiseInList(array $values, array &$warnings, string $path): void
    {
        foreach ($values as $value) {
            if (self::containsNoise($value)) {
                self::addWarning($warnings, $path, 'Texto potencialmente ruidoso/no operativo: ' . $value);
                return;
            }
        }
    }

    private static function containsNoise(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        $patterns = [
            '/\b(lorem ipsum|dummy|xxx|asdf|texto de relleno|placeholder)\b/iu',
            '/\b(compra ahora|oferta limitada|suscribete|haz clic|promo|promocion)\b/iu',
            '/^\s*(web|telegram|whatsapp)\s*:/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function toKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = self::normalizeForDedup($value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private static function normalizeForDedup(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $replace = [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
            'Á' => 'a',
            'É' => 'e',
            'Í' => 'i',
            'Ó' => 'o',
            'Ú' => 'u',
            'Ü' => 'u',
            'Ñ' => 'n',
        ];
        $value = strtr($value, $replace);
        $value = preg_replace('/\s+/u', ' ', $value);
        return is_string($value) ? trim($value) : trim($value ?? '');
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
