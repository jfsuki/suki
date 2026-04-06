<?php
/**
 * framework/scripts/synthesize_multisector_training.php
 * 
 * Synthesizes a production-ready multisector training dataset.
 * Outputs two files to avoid JSON Schema 'additionalProperties' conflicts:
 * 1. multisector_training_flat.json (for prepare_erp_training_dataset.php)
 * 2. multisector_training_compliant.json (for training_dataset_publication_gate.php)
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/autoload.php';

$domainPath = __DIR__ . '/../contracts/agents/domain_playbooks.json';
$flatPath = __DIR__ . '/../training/multisector_training_flat.json';
$compliantPath = __DIR__ . '/../training/multisector_training_compliant.json';

if (!is_file($domainPath)) {
    die("Error: domain_playbooks.json not found at $domainPath\n");
}

$domain = json_decode(file_get_contents($domainPath), true);
if (!$domain) {
    die("Error: Invalid JSON in $domainPath\n");
}

$flatDataset = [
    "metadata" => [
        "dataset_id" => "multisector_training_production",
        "dataset_version" => "1.0.0",
        "domain" => "erp",
        "subdomain" => "all",
        "locale" => "es-CO",
        "recommended_memory_type" => "agent_training"
    ],
    "intents_catalog" => [],
    "training_samples" => [],
    "hard_cases" => []
];

$compliantDataset = [
    "batch_id" => "multisector_prod_" . date('Ymd_His'),
    "language" => "es",
    "dataset_version" => "1.0.0",
    "memory_type" => "agent_training",
    "intents_expansion" => [], 
    "multi_turn_dialogues" => [], 
    "emotion_cases" => [], 
    "qa_cases" => []
];

$solverIntents = $domain['solver_intents'] ?? [];

foreach ($solverIntents as $si) {
    $intentName = $si['name'] ?? 'unknown';
    $action = "builder.install_playbook";
    $utterances = $si['utterances'] ?? [];
    $hardNegatives = $si['hard_negatives'] ?? [];

    // 1. FLAT Dataset
    $flatDataset['intents_catalog'][] = [
        "intent_key" => $intentName,
        "intent_name" => str_replace('_', ' ', $intentName),
        "description" => $si['description'] ?? "Playbook for $intentName",
        "target_skill" => "playbook_executor",
        "skill_type" => "hybrid",
        "required_action" => $action,
        "risk_level" => "medium"
    ];

    foreach ($utterances as $u) {
        $flatDataset['training_samples'][] = [
            "intent_key" => $intentName,
            "utterance" => $u,
            "target_skill" => "playbook_executor",
            "skill_type" => "hybrid",
            "required_action" => $action
        ];
    }

    foreach ($hardNegatives as $hn) {
        $flatDataset['hard_cases'][] = [
            "intent_key" => $intentName,
            "utterance" => $hn,
            "target_skill" => "playbook_executor",
            "skill_type" => "hybrid",
            "required_action" => $action,
            "expected_resolution" => "resolve",
            "expected_route_stage" => "skills"
        ];
    }

    // 2. COMPLIANT Dataset
    $compliantDataset['intents_expansion'][] = [
        "intent" => $intentName,
        "action" => $action,
        "utterances_explicit" => array_slice($utterances, 0, (int)(count($utterances) / 2)),
        "utterances_implicit" => array_slice($utterances, (int)(count($utterances) / 2)),
        "hard_negatives" => $hardNegatives,
        "slots" => [
            ["name" => "sector_id", "required" => true]
        ],
        "one_question_policy" => [
            ["missing_slot" => "sector_id", "question" => "¿Para qué sector necesitas activar el playbook?"]
        ],
        "confirm_required" => false,
        "success_reply_template" => "He activado el playbook de $intentName con éxito.",
        "error_reply_template" => "Lo siento, no pude activar el playbook de $intentName."
    ];
}

// Dialogues, Emotions, QA for Compliant
for ($i=0; $i<10; $i++) {
    $compliantDataset['multi_turn_dialogues'][] = [
        "id" => "dlg_" . $i,
        "scenario" => "Instalación de playbook multisector $i",
        "turns" => [
            ["user" => "Hola, necesito el playbook de " . ($flatDataset['intents_catalog'][$i]['intent_name'] ?? 'general')],
            ["assistant_expected_action" => "builder.install_playbook"]
        ]
    ];

    $compliantDataset['qa_cases'][] = [
        "text" => "¿Cómo instalo el playbook de " . ($flatDataset['intents_catalog'][$i]['intent_name'] ?? 'sector') . "?",
        "expect_intent" => $flatDataset['intents_catalog'][$i]['intent_key'] ?? 'UNKNOWN',
        "expect_action" => "builder.install_playbook"
    ];
}

$compliantDataset['emotion_cases'][] = [
    "emotion" => "confusion",
    "user_samples" => ["No entiendo cómo usar esto", "Qué es un playbook?"],
    "assistant_style" => "helpful",
    "expected_next_step" => "explain_concept"
];

file_put_contents($flatPath, json_encode($flatDataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($compliantPath, json_encode($compliantDataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "Phase 10.1: Multi-file Synthesis COMPLETE.\n";
echo "  - Flat: $flatPath\n";
echo "  - Compliant: $compliantPath\n";
