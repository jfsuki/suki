<?php
declare(strict_types=1);
// app/Core/Agents/AppFastPathParser.php
//
// FAST PATH / SOFT PARSER for the App (Operational) mode.
// This class acts as the LLM translator that converts raw business language 
// into strict operational actions (POOS, CRUD, Reports, Tasks) 
// with a natural, agentic conversational reply.
//
// Following Neuron IA Architecture:
//   Layer 1: IntentClassifier (Semantic)
//   Layer 2: AppFastPathParser (LLM Extraction + Agentic Reply)
//   Layer 3: Kernel Executor (CommandBus / Skills)

namespace App\Core\Agents;

use App\Core\LLM\LLMRouter;
use RuntimeException;

final class AppFastPathParser
{
    private const ALLOWED_INTENTS = [
        'pos.create_sale',
        'pos.add_item',
        'pos.finalize',
        'entity.search',
        'entity.list',
        'crud.create',
        'report.generate',
        'task.create',
        'inventory.check',
        'customer.lookup',
        'clarify',
        'unknown',
    ];

    private const SYSTEM_PROMPT_AGENT = "Act as Suki, an elite AI Agent for business operations. 
Your goal is to parse user intents for business actions and provide a natural, human-like response in Spanish.
Avoid sounding like a robot or a template. If a user wants to sell, respond with enthusiasm and helpfulness.";

    /**
     * Parse operational message for Chat App mode.
     */
    public function parse(
        string $text,
        array $state,
        array $profile,
        array $lexicon,
        string $tenantId = 'default',
        string $userId = 'anon'
    ): array {
        $text = trim($text);
        if ($text === '') {
            return $this->fallback('empty_input');
        }

        // Feature flag for App Fast Path
        $enabled = getenv('APP_FAST_PATH_ENABLED') ?: 'true';
        if (!in_array(strtolower(trim((string)$enabled)), ['1', 'true', 'yes', 'on'], true)) {
            return $this->fallback('disabled');
        }

        $capsule = $this->buildCapsule($text, $state, $profile, $lexicon);

        try {
            $router = new LLMRouter();
            $result = $router->chat($capsule, [
                'provider_mode' => 'openrouter',
                'temperature' => 0.1,
            ]);

            $json = is_array($result['json'] ?? null) ? (array) $result['json'] : [];
            if ($json === []) {
                return $this->fallback('llm_empty_response');
            }

            return $this->validate($json);
        } catch (\Throwable $e) {
            return $this->fallback('error:' . $e->getMessage());
        }
    }

    private function buildCapsule(string $text, array $state, array $profile, array $lexicon): array
    {
        // Compact business context
        $businessType = $profile['business_type'] ?? 'General';
        $entities = array_keys($lexicon['entities'] ?? []);
        
        return [
            'intent' => 'APP_FAST_PATH',
            'user_message' => $text,
            'policy' => [
                'requires_strict_json' => true,
                'max_output_tokens' => 300,
            ],
            'prompt_contract' => [
                'ROLE' => 'Suki Operational Agent (Layer 2 Parser)',
                'CONTEXT' => "User is operating a business of type: {$businessType}. 
Available tables: " . implode(', ', $entities),
                'INPUT' => [
                    'message' => $text,
                    'current_state' => $state,
                ],
                'CONSTRAINTS' => [
                    'output_language' => 'es-CO',
                    'agentic_tone' => true,
                    'short_replies' => true,
                    'allowed_intents' => self::ALLOWED_INTENTS,
                ],
                'OUTPUT_FORMAT' => [
                    'intent' => ['type' => 'string', 'enum' => self::ALLOWED_INTENTS],
                    'mapped_fields' => ['type' => 'object'],
                    'reply' => ['type' => 'string', 'description' => 'A natural, warm, and professional Spanish response.'],
                    'confidence' => ['type' => 'number'],
                    'requires_confirmation' => ['type' => 'boolean']
                ],
                'EXAMPLES' => [
                    [
                        'input' => 'vende una pulidora de 200 mil',
                        'output' => [
                            'intent' => 'pos.create_sale',
                            'mapped_fields' => ['item' => 'pulidora', 'price' => 200000],
                            'reply' => '¡Excelente! He registrado la venta de la pulidora por $200.000. ¿Deseas agregar algo más o cerramos la venta?',
                            'confidence' => 0.95
                        ]
                    ]
                ]
            ]
        ];
    }

    private function validate(array $json): array
    {
        $intent = (string)($json['intent'] ?? 'unknown');
        if (!in_array($intent, self::ALLOWED_INTENTS, true)) {
            $intent = 'unknown';
        }

        return [
            'intent' => $intent,
            'mapped_fields' => (array)($json['mapped_fields'] ?? []),
            'reply' => (string)($json['reply'] ?? 'Entendido. ¿Cómo procedemos?'),
            'confidence' => (float)($json['confidence'] ?? 0.0),
            'requires_confirmation' => (bool)($json['requires_confirmation'] ?? false),
            'via' => 'app_fast_path_llm'
        ];
    }

    private function fallback(string $reason): array
    {
        return [
            'intent' => 'unknown',
            'mapped_fields' => [],
            'reply' => 'Entendido. ¿En qué puedo ayudarte en tu aplicación?',
            'confidence' => 0.0,
            'via' => 'app_fast_path_fallback:' . $reason
        ];
    }
}
