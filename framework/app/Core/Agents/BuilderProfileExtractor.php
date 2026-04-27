<?php
declare(strict_types=1);

namespace App\Core\Agents;

use App\Core\LLM\LLMRouter;

/**
 * LLM-first profile extractor for builder onboarding.
 * Single call extracts all business profile fields + generates contextual next question.
 * Replaces sequential str_contains keyword bridges.
 */
final class BuilderProfileExtractor
{
    private LLMRouter $llm;
    private array $cache = [];

    private const REQUIRED_FIELDS = ['business_type', 'operation_model', 'needs_scope', 'documents_scope'];

    public function __construct(?LLMRouter $llm = null)
    {
        $this->llm = $llm ?? new LLMRouter();
    }

    /**
     * Extract business profile from user message AND generate next contextual question.
     *
     * @param string $userMessage Current user message
     * @param array $currentProfile Profile collected so far
     * @param array $history Recent conversation turns [{u:..., a:...}]
     * @return array{ok:bool, profile_updates:array, next_question:string|null, missing:array, confidence:float}
     */
    public function extractAndGuide(string $userMessage, array $currentProfile, array $history = []): array
    {
        $cacheKey = md5($userMessage . json_encode(array_intersect_key($currentProfile, array_flip(self::REQUIRED_FIELDS))));
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $missingBefore = $this->computeMissing($currentProfile);

        try {
            $capsule = [
                'policy' => [
                    'requires_strict_json' => true,
                    'max_output_tokens'    => 500,
                ],
                'prompt_contract' => [
                    'TASK'    => 'Analiza el mensaje del usuario y el perfil actual. Extrae datos del negocio. Genera la siguiente pregunta natural si aún faltan campos.',
                    'CONTEXT' => 'Plataforma SUKI ERP para PYMEs colombianas. Configura módulos automáticamente según el perfil del negocio.',
                    'CURRENT_PROFILE' => array_filter([
                        'business_type'  => $currentProfile['business_type'] ?? null,
                        'operation_model'=> $currentProfile['operation_model'] ?? null,
                        'needs_scope'    => $currentProfile['needs_scope'] ?? null,
                        'documents_scope'=> $currentProfile['documents_scope'] ?? null,
                    ]),
                    'MISSING_FIELDS' => $missingBefore,
                    'USER_MESSAGE'   => $userMessage,
                    'OUTPUT_SCHEMA'  => [
                        'business_type'        => 'string|null — SOLO uno de: ferreteria|veterinaria|restaurante|retail|servicios_mantenimiento. null si es desconocido/otro.',
                        'operation_model'      => 'string|null — contado|credito|mixto. null si no se menciona.',
                        'needs_scope_items'    => 'array — módulos: inventario, ventas, compras, clientes, contabilidad, nomina. [] si ninguno.',
                        'documents_scope_items'=> 'array — documentos: facturas, cotizaciones, recibos, tickets, ordenes. [] si ninguno.',
                        'next_question'        => 'string|null — pregunta conversacional en español colombiano para el primer campo faltante. null si ya están todos.',
                        'missing'              => 'array — campos que siguen faltando DESPUÉS de este mensaje.',
                        'confidence'           => 'float 0.0–1.0',
                    ],
                    'RULES' => [
                        'JSON puro. Sin markdown, sin bloques de código.',
                        'No inventes datos ausentes del mensaje.',
                        'next_question: sin "Paso N:", sin formato de lista, tono natural amigable, máximo 30 palabras.',
                        'Si el mensaje implica un tipo de negocio (ej: "tienda de ropa" → retail), inclúyelo.',
                        'Si todos los campos están completos, next_question = null y missing = [].',
                    ],
                ],
            ];

            if (!empty($history)) {
                $capsule['last_messages'] = array_slice($history, -4);
            }

            $result  = $this->llm->chat($capsule, ['temperature' => 0.0]);
            $json    = is_array($result['json'] ?? null) ? $result['json'] : [];

            // Flatten common wrapper keys some models add
            foreach (['findings', 'extracted', 'result', 'data', 'output'] as $wrapper) {
                if (isset($json[$wrapper]) && is_array($json[$wrapper])) {
                    $json = array_merge($json, $json[$wrapper]);
                    break;
                }
            }

            $updates = [];
            if (!empty($json['business_type']) && is_string($json['business_type'])) {
                $updates['business_type'] = $json['business_type'];
            }
            if (!empty($json['operation_model']) && is_string($json['operation_model'])) {
                $updates['operation_model'] = $json['operation_model'];
            }
            if (!empty($json['needs_scope_items']) && is_array($json['needs_scope_items'])) {
                $items = array_values(array_filter(array_map('strval', $json['needs_scope_items'])));
                if ($items !== []) {
                    $updates['needs_scope_items'] = $items;
                }
            }
            if (!empty($json['documents_scope_items']) && is_array($json['documents_scope_items'])) {
                $items = array_values(array_filter(array_map('strval', $json['documents_scope_items'])));
                if ($items !== []) {
                    $updates['documents_scope_items'] = $items;
                }
            }

            $nextQ   = is_string($json['next_question'] ?? null) ? trim($json['next_question']) : null;
            $missing = is_array($json['missing'] ?? null) ? $json['missing'] : $missingBefore;

            $response = [
                'ok'             => true,
                'profile_updates'=> $updates,
                'next_question'  => ($nextQ !== '' && $nextQ !== null) ? $nextQ : null,
                'missing'        => $missing,
                'confidence'     => is_numeric($json['confidence'] ?? null) ? (float)$json['confidence'] : 0.5,
            ];

            $this->cache[$cacheKey] = $response;
            return $response;

        } catch (\Throwable $e) {
            error_log('BuilderProfileExtractor::extractAndGuide error: ' . $e->getMessage());
            return ['ok' => false, 'profile_updates' => [], 'next_question' => null, 'missing' => $missingBefore, 'confidence' => 0.0];
        }
    }

    /**
     * Generate only the next question for a known missing field.
     * Used by buildBuilderOnboardingRecoveryReply as primary (LLM-first).
     */
    public function generateQuestion(string $step, array $currentProfile): string
    {
        $cacheKey = 'q_' . md5($step . json_encode(array_intersect_key($currentProfile, array_flip(self::REQUIRED_FIELDS))));
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        try {
            $capsule = [
                'policy' => ['requires_strict_json' => false, 'max_output_tokens' => 80],
                'prompt_contract' => [
                    'TASK'    => 'Genera una sola pregunta conversacional en español colombiano para obtener el dato faltante.',
                    'STEP'    => $step,
                    'PROFILE' => array_filter([
                        'business_type'  => $currentProfile['business_type'] ?? null,
                        'operation_model'=> $currentProfile['operation_model'] ?? null,
                    ]),
                    'RULES'   => [
                        'Sin "Paso N:", sin listas, sin JSON, sin emojis.',
                        'Máximo 25 palabras. Tono amigable y directo.',
                        'business_type → pregunta por el giro del negocio.',
                        'operation_model → pregunta si vende a contado, crédito o ambos.',
                        'needs_scope → pregunta qué módulos necesita controlar.',
                        'documents_scope → pregunta qué documentos genera.',
                        'confirm_scope → pregunta si confirma la propuesta.',
                        'Solo el texto de la pregunta. Sin comillas.',
                    ],
                ],
            ];

            $result = $this->llm->chat($capsule, ['temperature' => 0.3]);
            $text   = trim((string)($result['text'] ?? ''));

            if (strlen($text) >= 5 && strlen($text) <= 300) {
                $this->cache[$cacheKey] = $text;
                return $text;
            }
        } catch (\Throwable $e) {
            error_log('BuilderProfileExtractor::generateQuestion error: ' . $e->getMessage());
        }

        // Short fallback — not "Paso N:" chatbot strings
        $fallback = match ($step) {
            'business_type'  => '¿A qué se dedica tu negocio?',
            'operation_model'=> '¿Vendes a contado, a crédito o manejo mixto?',
            'needs_scope'    => '¿Qué necesitas controlar: inventario, ventas, clientes?',
            'documents_scope'=> '¿Qué documentos genera tu negocio?',
            'confirm_scope'  => '¿Confirmamos esta propuesta o ajustamos algo?',
            'plan_ready'     => '¿Creo la primera tabla o ajustamos algo primero?',
            default          => '¿Qué más necesitas configurar?',
        };

        $this->cache[$cacheKey] = $fallback;
        return $fallback;
    }

    private function computeMissing(array $profile): array
    {
        $missing = [];
        if (empty($profile['business_type'])) {
            $missing[] = 'business_type';
        }
        if (empty($profile['operation_model'])) {
            $missing[] = 'operation_model';
        }
        if (empty($profile['needs_scope']) && empty($profile['needs_scope_items'])) {
            $missing[] = 'needs_scope';
        }
        if (empty($profile['documents_scope']) && empty($profile['documents_scope_items'])) {
            $missing[] = 'documents_scope';
        }
        return $missing;
    }
}
