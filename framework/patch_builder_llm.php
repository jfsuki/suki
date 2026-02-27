<?php
$f = 'c:\laragon\www\suki\framework\app\Core\Agents\ConversationGateway.php';
$c = file_get_contents($f);

// 1. Hooking into routeConfusion to delegate unknown text to the LLM Assistant
$searchStr1 = <<<'EOD'
        $askNextSet = $this->confusionSetById($confusionBase, 'ASK_NEXT_STEP');
EOD;

$replaceStr1 = <<<'EOD'
        if ($mode === 'builder' && empty($pending)) {
            $llmResolution = $this->resolveBuilderEntityConfusionWithLLM($text, $state, $profile, $tenantId, $userId);
            if (!empty($llmResolution)) {
                return $llmResolution;
            }
        }

        $askNextSet = $this->confusionSetById($confusionBase, 'ASK_NEXT_STEP');
EOD;

$c = str_replace($searchStr1, $replaceStr1, $c);

// 2. Injecting the mega prompt and JSON Deterministic logic
$searchStr2 = <<<'EOD'
    private function confusionSetById(array $confusionBase, string $id): array
EOD;

$replaceStr2 = <<<'EOD'
    private function resolveBuilderEntityConfusionWithLLM(string $text, array $state, array $profile, string $tenantId, string $userId): array
    {
        $hasGemini = trim((string) getenv('GEMINI_API_KEY')) !== '';
        $hasDeepSeek = trim((string) getenv('DEEPSEEK_API_KEY')) !== '';
        if (!$hasGemini && !$hasDeepSeek) {
            return [];
        }

        // 1. Build STATE_JSON
        $businessType = $this->normalizeBusinessType((string) ($profile['business_type'] ?? ''));
        $plan = is_array($state['builder_plan'] ?? null) ? (array) $state['builder_plan'] : [];
        $progress = $this->computeBuilderPlanProgress($plan, $state);

        $stateJson = [
            'sector_key' => $businessType !== '' ? $businessType : 'general',
            'sector_locked' => !empty($businessType),
            'entities' => array_values($progress['done_entities']),
            'missing_entities' => array_values($progress['missing_entities']),
            'constraints' => is_array($state['constraints_log'] ?? null) ? array_values((array) $state['constraints_log']) : [],
            'decisions_log' => is_array($state['decisions_log'] ?? null) ? array_values((array) $state['decisions_log']) : [],
        ];

        // 2. Build ROL DEL AGENTE prompt
        $promptContract = [
            'ROLE' => 'AGENTE GENERADOR DE APLICACIONES POR CONVERSACION',
            'CONTEXT' => [
                'goal' => 'operar apps administrativas/ERP a partir de lenguaje natural, usando JSON como unica fuente de verdad.',
                'principle' => 'La IA NO es el cerebro del sistema. La IA SOLO ASISTE al motor deterministico basado en JSON. Si algo puede resolverse con reglas NO usar IA.',
                'state_json' => $stateJson,
            ],
            'RULES' => [
                '1_SOURCE_OF_TRUTH' => 'Operar exclusivamente sobre STATE_JSON. Nunca asumas nada fuera del STATE_JSON. Nunca recuerdes por conversacion.',
                '2_SECTOR_LOCK' => 'Si sector_locked=true: Prohibido proponer tablas de otro sector. Si el usuario rechaza algo, cancela el plan y reporta "constraints" en PHASE 2.',
                '3_CLASSIFICATION' => 'Clasifica en: A) DETERMINISTICO (crear/editar tablas -> Genera PATCH_JSON), B) AMBIGUO (Incompleto -> Formula 1 sola pregunta clave), C) EXPLICATIVO (Texto libre, sin JSON_PATCH).',
                '4_NEGATIVE_HANDLING' => 'Si el usuario dice "no", "ese no es": Detener ejecucion, registrar restriccion explicita en PATCH_JSON, reencaminar con 1 pregunta.',
                '5_CONSTRAINTS' => 'Valida toda accion nueva contra la lista de constraints.',
            ],
            'INPUT' => [
                'user_text' => $text,
            ],
            'OUTPUT_FORMAT' => [
                'intent_classification' => ['type' => 'string', 'enum' => ['DETERMINISTICO', 'AMBIGUO', 'EXPLICATIVO']],
                'phase_1_plan' => [
                    'type' => 'object',
                    'properties' => [
                        'intent' => ['type' => 'string'],
                        'confidence' => ['type' => 'number'],
                        'proposed_reply' => ['type' => 'string'],
                    ]
                ],
                'phase_2_patch' => [
                    'type' => 'object',
                    'properties' => [
                        'patch_type' => ['type' => 'string', 'enum' => ['add_entity', 'ask_question', 'explain', 'add_constraint']],
                        'entities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'new_constraints' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ]
                ]
            ]
        ];

        $capsule = [
            'intent' => 'BUILDER_JSON_ASSISTANT',
            'user_message' => $text,
            'policy' => [
                'requires_strict_json' => true,
                'max_output_tokens' => 600,
                'latency_budget_ms' => 3500, // allow deepseek to think
            ],
            'prompt_contract' => $promptContract,
        ];

        try {
            $router = new \App\Core\LLM\LLMRouter();
            $llm = $router->chat($capsule, ['mode' => $hasDeepSeek ? 'deepseek' : 'gemini', 'temperature' => 0.1]);
            $json = is_array($llm['json'] ?? null) ? $llm['json'] : [];

            if (empty($json)) {
                return [];
            }

            $classification = (string) ($json['intent_classification'] ?? 'AMBIGUO');
            $reply = (string) ($json['phase_1_plan']['proposed_reply'] ?? 'Dime un poco mas para entenderte.');
            $patchType = (string) ($json['phase_2_patch']['patch_type'] ?? 'ask_question');

            // Save new constraints if any
            if (!empty($json['phase_2_patch']['new_constraints']) && is_array($json['phase_2_patch']['new_constraints'])) {
                $constraints = is_array($state['constraints_log'] ?? null) ? (array) $state['constraints_log'] : [];
                foreach ($json['phase_2_patch']['new_constraints'] as $newConstraint) {
                    $constraints[] = $newConstraint;
                }
                $state['constraints_log'] = array_slice($constraints, -20);
            }

            // Route based on Patch Type
            if ($classification === 'DETERMINISTICO' && $patchType === 'add_entity') {
                $entities = is_array($json['phase_2_patch']['entities'] ?? null) ? $json['phase_2_patch']['entities'] : [];
                $firstEntity = (string) ($entities[0] ?? '');
                if ($firstEntity !== '') {
                    $proposal = $this->buildCreateTableProposal($firstEntity, $profile);
                    return [
                        'action' => 'ask_user',
                        'reply' => $reply . "\n\n" . $proposal['reply'],
                        'intent' => 'llm_json_propose_entity',
                        'entity' => $proposal['entity'],
                        'pending_command' => $proposal['command'],
                        'active_task' => 'create_table',
                        'state_patch' => ['constraints_log' => $state['constraints_log'] ?? []]
                    ];
                }
            }

            // Default fallback for AMBIGUO or EXPLICATIVO
            return [
                'action' => 'ask_user',
                'reply' => $reply,
                'intent' => 'llm_json_clarify',
                'active_task' => 'builder_onboarding',
                'state_patch' => ['constraints_log' => $state['constraints_log'] ?? []]
            ];

        } catch (\Exception $e) {
            return [];
        }
    }

    private function confusionSetById(array $confusionBase, string $id): array
EOD;

$c = str_replace($searchStr2, $replaceStr2, $c);
file_put_contents($f, $c);
echo "Patched routeConfusion successfully!";
