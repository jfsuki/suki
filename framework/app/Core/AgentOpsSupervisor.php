<?php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class AgentOpsSupervisor
{
    private const DEFAULT_SCHEMA = FRAMEWORK_ROOT . '/contracts/schemas/agentops_supervisor_result.schema.json';

    /** @var array<string, int> */
    private const FLAG_PENALTIES = [
        'insufficient_evidence' => 30,
        'rag_weak_result' => 15,
        'skill_execution_failed' => 25,
        'policy_route_mismatch' => 25,
        'fallback_overuse' => 15,
        'possible_hallucination' => 20,
        'weak_safe_fallback' => 10,
    ];

    /** @var array<int, string> */
    private const CRITICAL_FLAGS = [
        'insufficient_evidence',
        'skill_execution_failed',
        'policy_route_mismatch',
        'possible_hallucination',
    ];

    /** @var array<int, string> */
    private const SAFE_FALLBACK_MARKERS = [
        'falta evidencia',
        'dato verificable',
        'comparte un dato',
        'necesito el',
        'necesito la',
        'indica el',
        'indica la',
        'para continuar',
        'no ejecute ninguna operacion',
        'no execute ninguna operacion',
        'usa comandos simples',
        'cambia el dato clave',
        'concreta el siguiente paso',
        'sin recurrir al llm libre',
        'ia no disponible',
        'revisa permisos o datos',
    ];

    /** @var array<int, string> */
    private const WEAK_FALLBACK_PATTERNS = [
        '/^\s*listo\.?\s*$/iu',
        '/^\s*ok(?:ay)?\.?\s*$/iu',
        '/^\s*error\.?\s*$/iu',
        '/^\s*no entendi\.?\s*$/iu',
        '/^\s*no entendi nada\.?\s*$/iu',
        '/^\s*no puedo\.?\s*$/iu',
        '/^\s*no pude\.?\s*$/iu',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public static function validateResult(array $payload, ?string $schemaPath = null): void
    {
        $schemaPath = $schemaPath ?? self::DEFAULT_SCHEMA;
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema de AgentOps Supervisor no existe: ' . $schemaPath);
        }

        $schema = json_decode((string) file_get_contents($schemaPath));
        if (!$schema) {
            throw new RuntimeException('Schema de AgentOps Supervisor invalido.');
        }

        try {
            $payloadObject = json_decode(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Payload de AgentOps Supervisor no serializable.', 0, $e);
        }

        $validator = new Validator();
        $result = $validator->validate($payloadObject, $schema);
        if (!$result->isValid()) {
            $error = $result->error();
            $message = $error ? $error->message() : 'AgentOps Supervisor invalido por schema.';
            throw new RuntimeException($message);
        }
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $routeTelemetry
     * @param array<string, mixed> $runtimeContext
     * @return array<string, mixed>
     */
    public function evaluate(array $runtime, array $routeTelemetry = [], array $runtimeContext = []): array
    {
        $flags = [];
        $reasons = [];
        $responseText = trim((string) ($runtimeContext['response_text'] ?? ''));
        $responseKind = trim((string) ($runtimeContext['response_kind'] ?? 'unknown')) ?: 'unknown';
        $routePath = trim((string) ($runtime['route_path'] ?? 'unknown')) ?: 'unknown';
        $routeSteps = $this->routeSteps($routePath);
        $sourceIds = $this->normalizeStringList($runtime['source_ids'] ?? []);
        $evidenceIds = $this->normalizeStringList($runtime['evidence_ids'] ?? []);
        $evidenceGateStatus = trim((string) ($runtime['evidence_gate_status'] ?? 'unknown')) ?: 'unknown';
        $fallbackReason = trim((string) ($runtime['fallback_reason'] ?? 'none')) ?: 'none';
        $skillSelected = trim((string) ($runtime['skill_selected'] ?? 'none')) ?: 'none';
        $skillResultStatus = trim((string) ($runtime['skill_result_status'] ?? 'unknown')) ?: 'unknown';
        $skillFallbackReason = trim((string) ($runtime['skill_fallback_reason'] ?? 'none')) ?: 'none';
        $ragAttempted = (bool) ($runtime['rag_attempted'] ?? false);
        $ragUsed = (bool) ($runtime['rag_used'] ?? false);
        $ragResultCount = max(0, (int) ($runtime['rag_result_count'] ?? 0));
        $ragError = trim((string) ($runtime['rag_error'] ?? ''));
        $llmUsed = (bool) ($runtime['llm_used'] ?? false);
        $loopGuardTriggered = (bool) ($runtime['loop_guard_triggered'] ?? false);
        $sameRouteRepeatCount = max(0, (int) ($routeTelemetry['same_route_repeat_count'] ?? 0));
        $gateDecision = trim((string) ($runtime['gate_decision'] ?? 'unknown')) ?: 'unknown';
        $toolCallsCount = max(0, (int) ($runtime['tool_calls_count'] ?? 0));
        $safeFallback = $this->looksLikeSafeFallback($responseText);
        $requiresEvidence = $this->requiresEvidence($runtime, $routeSteps);

        if ($evidenceGateStatus === 'insufficient_evidence') {
            $this->addFlag($flags, $reasons, 'insufficient_evidence', 'La respuesta quedo en ruta con evidencia insuficiente.');
        }

        if ($ragAttempted && ($ragResultCount < 1 || $ragError !== '' || in_array($fallbackReason, ['rag_error', 'awaiting_rag_result'], true))) {
            $this->addFlag($flags, $reasons, 'rag_weak_result', 'RAG fue intentado sin evidencia util o con error de recuperacion.');
        }

        if (
            $skillSelected !== 'none'
            && (
                (bool) ($runtime['skill_failed'] ?? false)
                || in_array($skillResultStatus, ['failed', 'safe_fallback', 'registry_unavailable'], true)
                || ($skillFallbackReason !== 'none' && $skillResultStatus === 'safe_fallback')
            )
        ) {
            $this->addFlag($flags, $reasons, 'skill_execution_failed', 'La skill seleccionada no resolvio la solicitud de forma valida.');
        }

        if (($sameRouteRepeatCount >= 2 && $fallbackReason !== 'none') || ($loopGuardTriggered && str_contains((string) ($runtime['loop_guard_reason'] ?? ''), 'repeated_route'))) {
            $this->addFlag($flags, $reasons, 'fallback_overuse', 'Se detecto repeticion de fallback sin progreso suficiente.');
        }

        if (
            ($llmUsed && !in_array('llm', $routeSteps, true))
            || ($ragUsed && !in_array('rag', $routeSteps, true))
            || ($skillSelected !== 'none' && !in_array('skills', $routeSteps, true))
            || (in_array($gateDecision, ['blocked', 'warn'], true) && ($llmUsed || $toolCallsCount > 0))
        ) {
            $this->addFlag($flags, $reasons, 'policy_route_mismatch', 'La ruta observada no coincide con el tipo de respuesta o ejecucion emitida.');
        }

        if (
            $llmUsed
            && $requiresEvidence
            && $sourceIds === []
            && $evidenceIds === []
            && $responseKind !== 'ask_user'
            && !$safeFallback
        ) {
            $this->addFlag($flags, $reasons, 'possible_hallucination', 'Respuesta potencialmente afirmativa sin evidencia enlazada.');
        }

        if (
            ($fallbackReason !== 'none' || $skillFallbackReason !== 'none' || (bool) ($runtime['error_flag'] ?? false))
            && in_array($responseKind, ['respond_local', 'ask_user', 'error'], true)
            && $responseText !== ''
            && $this->isWeakFallbackResponse($responseText)
        ) {
            $this->addFlag($flags, $reasons, 'weak_safe_fallback', 'El safe fallback emitido es demasiado generico para guiar el siguiente paso.');
        }

        $score = 100;
        foreach ($flags as $flag) {
            $score -= (int) (self::FLAG_PENALTIES[$flag] ?? 5);
        }
        $score = max(0, min(100, $score));

        $critical = array_values(array_intersect($flags, self::CRITICAL_FLAGS));
        $status = 'healthy';
        if ($flags !== []) {
            $status = ($critical !== [] || $score < 70) ? 'flagged' : 'needs_review';
        }

        $needsRegressionCase = $flags !== [];
        $needsMemoryHygiene = array_values(array_intersect($flags, [
            'insufficient_evidence',
            'rag_weak_result',
            'possible_hallucination',
            'fallback_overuse',
        ])) !== [];
        $needsTrainingGapReview = array_values(array_intersect($flags, [
            'insufficient_evidence',
            'rag_weak_result',
            'fallback_overuse',
            'weak_safe_fallback',
        ])) !== [];

        return [
            'status' => $status,
            'score' => $score,
            'flags' => $flags,
            'reasons' => $reasons,
            'route_path' => $routePath,
            'skill_selected' => $skillSelected,
            'rag_used' => $ragUsed,
            'evidence_gate_status' => $evidenceGateStatus,
            'fallback_reason' => $fallbackReason,
            'needs_regression_case' => $needsRegressionCase,
            'needs_memory_hygiene' => $needsMemoryHygiene,
            'needs_training_gap_review' => $needsTrainingGapReview,
        ];
    }

    /**
     * @param array<int, string> $flags
     * @param array<int, string> $reasons
     */
    private function addFlag(array &$flags, array &$reasons, string $flag, string $reason): void
    {
        if (!in_array($flag, $flags, true)) {
            $flags[] = $flag;
        }
        $reason = trim($reason);
        if ($reason !== '' && !in_array($reason, $reasons, true)) {
            $reasons[] = $reason;
        }
    }

    /**
     * @param array<string, mixed> $runtime
     * @param array<int, string> $routeSteps
     */
    private function requiresEvidence(array $runtime, array $routeSteps): bool
    {
        $actionContract = trim((string) ($runtime['action_contract'] ?? 'none')) ?: 'none';
        $evidenceGateStatus = trim((string) ($runtime['evidence_gate_status'] ?? 'unknown')) ?: 'unknown';
        if ($evidenceGateStatus === 'insufficient_evidence') {
            return true;
        }
        if ((bool) ($runtime['rag_attempted'] ?? false)) {
            return true;
        }
        if (in_array('rag', $routeSteps, true)) {
            return true;
        }
        if ($actionContract !== 'none' && !in_array($evidenceGateStatus, ['skipped_by_rule', 'disabled_by_config'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function routeSteps(string $routePath): array
    {
        if ($routePath === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($step): string => strtolower(trim((string) $step)),
            explode('>', $routePath)
        ), static fn(string $step): bool => $step !== ''));
    }

    private function looksLikeSafeFallback(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        foreach (self::SAFE_FALLBACK_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isWeakFallbackResponse(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return true;
        }
        if (mb_strlen($normalized, 'UTF-8') < 24) {
            return true;
        }
        foreach (self::WEAK_FALLBACK_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $candidate = trim((string) $item);
            if ($candidate === '') {
                continue;
            }
            $normalized[] = $candidate;
        }

        if ($normalized === []) {
            return [];
        }

        return array_values(array_unique($normalized));
    }
}
