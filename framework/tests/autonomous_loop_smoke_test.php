<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Agents\Processes\AutonomousExecutionProcess;
use App\Core\IntentRouter;
use App\Core\LLM\LLMRouter;
use App\Core\SkillRegistry;
use App\Core\Agents\Memory\MemoryWindow;

/**
 * MOCK LLM Router
 */
class MockLLMRouter extends LLMRouter {
    private int $turn = 0;
    public function __construct() { /* Bypass real constructor */ }
    public function executeWithProviders(array $messages, array $tools = [], $toolChoice = null): array {
        $this->turn++;
        echo "[DEBUG] MockLLM Turn " . $this->turn . " called.\n";
        if ($this->turn === 1) {
            return [
                'text' => 'Voy a crear un borrador POS.',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'pos_create_draft',
                            'arguments' => json_encode(['customer_id' => 'CUST-001'])
                        ]
                    ]
                ]
            ];
        }
        return [
            'text' => 'Listo, borrador creado con ID DRAFT-123.',
            'tool_calls' => []
        ];
    }
}

/**
 * MOCK Intent Router
 */
class MockIntentRouter extends IntentRouter {
    public function __construct() { /* Bypass */ }
    public function executeSkill(string $name, array $args, array $context = []): array {
        return [
            'action' => 'command',
            'command' => ['command' => 'CreatePOSDraft', 'customer_id' => $args['customer_id']],
            'reply' => 'Ejecutando CreatePOSDraft...'
        ];
    }
}

/**
 * MOCK Skill Registry
 */
class MockSkillRegistry extends SkillRegistry {
    public function __construct() { /* Bypass */ }
    public function getTools(): array {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'pos_create_draft',
                    'description' => 'Crea un borrador POS',
                    'parameters' => ['type' => 'object', 'properties' => []]
                ]
            ]
        ];
    }
}

$mockRouter = new MockIntentRouter();
$mockLLM = new MockLLMRouter();
$mockSkills = new MockSkillRegistry();
$mockMemory = new MemoryWindow(5); // maxTurns = 5

$process = new AutonomousExecutionProcess();

echo "--- Iniciando Smoke Test Simplificado ---\n";

$result = $process->execute(
    "crea un borrador",
    $mockMemory,
    $mockRouter,
    $mockLLM,
    $mockSkills
);

echo "Reply: " . $result['reply'] . "\n";
echo "Turns: " . ($result['telemetry']['iterations'] ?? 0) . "\n";

if (str_contains($result['reply'], 'DRAFT-123') && ($result['telemetry']['iterations'] ?? 0) === 2) {
    echo "PASS\n";
    exit(0);
} else {
    echo "FAIL\n";
    exit(1);
}
