<?php
declare(strict_types=1);
// app/Core/Agents/BuilderFastPathParser.php
//
// FAST PATH / SOFT PARSER for the Builder mode.
// This class acts as the LLM translator that converts raw human language into
// a strict JSON action payload for the Builder's deterministic onboarding flow.
//
// Design invariants (see .windsurfrules / SUKI_ARCHITECTURE_CANON):
//   - LLM translates; PHP validates and executes.
//   - No history sent. No RAG. No heavy context.
//   - Strict JSON output only. Fallback to hardcoded text on any error.
//   - Max 220 tokens out, 400 tokens in, 2500ms timeout.
//   - Provider order: openrouter → deepseek → deterministic fallback.

namespace App\Core\Agents;

use App\Core\LLM\LLMRouter;
use RuntimeException;

final class BuilderFastPathParser
{
    // Builder-scoped allowed intents (Tool Contextualization).
    // The LLM CANNOT produce any ERP operational action.
    private const ALLOWED_INTENTS = [
        'ask_user',
        'set_business_type',
        'set_operation_model',
        'set_scope',
        'set_documents',
        'confirm',
        'adjust',
        'frustration_help',
        'create_table',
        'create_form',
        'unknown',
    ];

    // Deterministic fallbacks per step — guaranateed no-null for any failure.
    private const STEP_FALLBACK = [
        'business_type'   => 'Cuéntame en una frase: ¿qué vendes o a qué se dedica tu negocio?',
        'operation_model' => 'Solo dime cómo cobras: ¿al contado, a crédito o los dos?',
        'needs_scope'     => '¿Qué quieres controlar primero? Por ejemplo: clientes, pedidos, inventario.',
        'documents_scope' => '¿Qué documentos usas? Por ejemplo: factura, cotización, orden de trabajo.',
        'confirm_scope'   => 'Revisa el resumen y dime si está bien o qué quieres ajustar.',
        'plan_ready'      => '¿Creamos la primera tabla o ajustas algo del alcance?',
    ];

    private const DEFAULT_FALLBACK = 'Ayúdame con un dato más para continuar.';

    /**
     * Parse a raw user message for the builder fast path.
     *
     * @param string $text      Raw user message (normalized).
     * @param string $step      Current onboarding step key.
     * @param array  $known     Already confirmed profile fields (compact summary).
     * @param array  $missing   Fields still needed.
     * @param array  $allowed   Catalog of allowed values for this step.
     *
     * @return array{
     *   intent: string,
     *   mapped_fields: array,
     *   reply: string,
     *   confidence: float,
     *   needs_clarification: bool,
     *   via: string
     * }
     */
    public function parse(
        string $text,
        string $step,
        array $known,
        array $missing,
        array $allowed,
        string $tenantId = 'default',
        string $userId = 'anon'
    ): array {
        // ── Guard: skip fast-path for zero-value inputs.
        $text = trim($text);
        if ($text === '' || $step === '') {
            return $this->fallback($step, 'empty_input');
        }

        // ── Guard: check for stub override (for tests).
        $stub = $this->tryStub($step);
        if ($stub !== null) {
            return $stub;
        }

        // ── Guard: check if fast-path LLM is enabled.
        $enabled = getenv('BUILDER_FAST_PATH_ENABLED');
        if ($enabled !== false && !in_array(strtolower(trim((string) $enabled)), ['1', 'true', 'yes', 'on'], true)) {
            return $this->fallback($step, 'fast_path_disabled');
        }

        // ── Build compact payload — NO history, NO full profile, NO RAG.
        $capsule = $this->buildCapsule($text, $step, $known, $missing, $allowed);

        try {
            $router = new LLMRouter();
            $result = $router->chat($capsule, [
                'provider_mode' => 'openrouter', // try openrouter first
                'temperature' => 0.1,
            ]);

            $json = is_array($result['json'] ?? null) ? (array) $result['json'] : [];
            if ($json === []) {
                return $this->fallback($step, 'empty_json_from_' . ($result['provider'] ?? 'llm'));
            }

            $parsed = $this->validate($json, $step);

            return $parsed;
        } catch (RuntimeException $e) {
            // Any LLM failure returns deterministic fallback — never null, never exception up.
            return $this->fallback($step, 'llm_error');
        } catch (\Throwable $e) {
            return $this->fallback($step, 'unexpected_error');
        }
    }

    /**
     * Build the minimal LLM capsule for the fast path.
     * Total payload target: < 400 tokens.
     */
    private function buildCapsule(
        string $text,
        string $step,
        array $known,
        array $missing,
        array $allowed
    ): array {
        // Only send what the LLM needs: step context + known summary + missing list + allowed values.
        $allowedList = array_slice($allowed, 0, 20); // hard cap to avoid long enums

        return [
            'intent'               => 'BUILDER_FAST_PATH',
            'entity'               => '',
            'entity_contract_min'  => ['required' => [], 'types' => []],
            'state'                => [
                'collected' => $known,
                'missing'   => $missing,
            ],
            'user_message' => $text,
            'policy'       => [
                'requires_strict_json' => true,
                'max_output_tokens'    => 220,
                'latency_budget_ms'    => 1800, // triggers prefer-fast provider selection
            ],
            'prompt_contract' => [
                'ROLE'    => 'Builder Fast Path Parser',
                'CONTEXT' => 'You help a non-technical user create a business app step by step. '
                    . 'Current step: "' . $step . '". '
                    . 'Known info: ' . ($known !== [] ? json_encode($known, JSON_UNESCAPED_UNICODE) : 'none') . '. '
                    . 'Still missing: ' . ($missing !== [] ? implode(', ', $missing) : 'nothing') . '.',
                'INPUT'   => [
                    'user_text'     => $text,
                    'current_step'  => $step,
                    'allowed_values' => $allowedList,
                ],
                'CONSTRAINTS' => [
                    'response_language'                    => 'es-CO',
                    'strict_catalog_only'                  => true,
                    'help_reply_max_words'                 => 20,
                    'do_not_invent_fields'                 => true,
                    'if_not_sure_use_intent_unknown'       => true,
                    'if_user_frustrated_use_frustration_help' => true,
                    'allowed_intents'                      => self::ALLOWED_INTENTS,
                    'no_chat_history_available'            => true,
                    'no_rag_available'                     => true,
                ],
                'OUTPUT_FORMAT' => [
                    'intent'             => ['type' => 'string', 'enum' => self::ALLOWED_INTENTS],
                    'mapped_fields'      => ['type' => 'object', 'additionalProperties' => false],
                    'reply'              => ['type' => 'string'],
                    'confidence'         => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'needs_clarification' => ['type' => 'boolean'],
                ],
                'FAIL_RULES' => [
                    'if intent is not in allowed_intents → use "unknown"',
                    'if confidence < 0.5 → set needs_clarification to true',
                    'if user text is a complaint or frustration → use "frustration_help"',
                    'reply must always be non-empty, short, and in Spanish',
                ],
            ],
        ];
    }

    /**
     * Validate the LLM JSON output and sanitize before returning.
     * PHP is the only validation authority — LLM output is untrusted input.
     */
    private function validate(array $json, string $step): array
    {
        $intent = trim((string) ($json['intent'] ?? 'unknown'));
        if (!in_array($intent, self::ALLOWED_INTENTS, true)) {
            $intent = 'unknown';
        }

        $mappedFields = is_array($json['mapped_fields'] ?? null) ? (array) $json['mapped_fields'] : [];
        // Security: strip any key that looks like an injection attempt or system field.
        $safeFields = [];
        foreach ($mappedFields as $k => $v) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
            if ($key !== '' && strlen($key) <= 64) {
                $safeFields[$key] = is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }

        $reply = trim((string) ($json['reply'] ?? ''));
        if ($reply === '') {
            $reply = self::STEP_FALLBACK[$step] ?? self::DEFAULT_FALLBACK;
        }
        // Cap reply length.
        if (mb_strlen($reply) > 300) {
            $reply = mb_substr($reply, 0, 297) . '...';
        }

        $confidence = is_numeric($json['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $json['confidence']))
            : 0.5;

        $needsClarification = (bool) ($json['needs_clarification'] ?? ($confidence < 0.5));

        return [
            'intent'              => $intent,
            'mapped_fields'       => $safeFields,
            'reply'               => $reply,
            'confidence'          => $confidence,
            'needs_clarification' => $needsClarification,
            'via'                 => 'fast_path_llm',
        ];
    }

    /**
     * Always-safe deterministic fallback — never returns null.
     */
    private function fallback(string $step, string $reason): array
    {
        return [
            'intent'              => 'ask_user',
            'mapped_fields'       => [],
            'reply'               => self::STEP_FALLBACK[$step] ?? self::DEFAULT_FALLBACK,
            'confidence'          => 0.0,
            'needs_clarification' => true,
            'via'                 => 'fast_path_fallback:' . $reason,
        ];
    }

    private function tryStub(string $step): ?array
    {
        $raw = trim((string) (getenv('SUKI_BUILDER_FAST_PATH_STUB_JSON') ?: ''));
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        // Allow step-keyed stubs: {"business_type": {...}, "operation_model": {...}}
        if (isset($decoded[$step]) && is_array($decoded[$step])) {
            $decoded = (array) $decoded[$step];
        }
        return $this->validate($decoded, $step);
    }
}
