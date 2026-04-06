<?php
// framework/tests/suki_token_benchmark.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Agents\Memory\TokenBudgeter;
use App\Core\Agents\Processes\AutonomousExecutionProcess;
use App\Core\Agents\Memory\MemoryWindow;
use App\Core\IntentRouter;
use App\Core\LLM\LLMRouter;
use App\Core\SkillRegistry;

class SukiTokenBenchmark {
    private TokenBudgeter $budgeter;

    public function __construct() {
        $this->budgeter = new TokenBudgeter();
    }

    public function run() {
        echo "=== SUKI TOKEN & EFFICIENCY BENCHMARK ===\n";
        echo "Scenario: 'Crear un borrador POS para el cliente CUST-001'\n\n";

        $results = [
            'legacy' => $this->benchmarkLegacy(),
            'tool_first' => $this->benchmarkToolFirst()
        ];

        $this->printReport($results);
    }

    private function benchmarkLegacy(): array {
        // Simulación de flujo Legacy:
        // 1. Router Call (Clasificación de intención)
        // 2. Extraer parámetros (NLU Parser)
        // 3. Ejecución y respuesta final.

        $input1 = "User: Crear un borrador POS para el cliente CUST-001. System: Clasifica esta intención entre POS, Inventario, Reportes.";
        $output1 = "Intención: POS";
        
        $input2 = "User: Crear un borrador POS para el cliente CUST-001. System: Extrae los parámetros customer_id y items del texto.";
        $output2 = "{\"customer_id\": \"CUST-001\", \"items\": []}";

        $input3 = "System: El comando POS se ejecutó con éxito. Responde al usuario.";
        $output3 = "Listo, he creado el borrador para CUST-001.";

        $tokensIn = $this->budgeter->estimate($input1) + $this->budgeter->estimate($input2) + $this->budgeter->estimate($input3);
        $tokensOut = $this->budgeter->estimate($output1) + $this->budgeter->estimate($output2) + $this->budgeter->estimate($output3);

        return [
            'name' => 'Legacy (DSL/NLU)',
            'calls' => 3,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'total_tokens' => $tokensIn + $tokensOut
        ];
    }

    private function benchmarkToolFirst(): array {
        // Simulación de flujo Tool-First:
        // 1. Prompt con definiciones de herramientas -> Retorna llamada a herramienta.
        // 2. Resultado de herramienta -> Retorna respuesta final.

        // Simular el System Prompt con herramientas (aproximado)
        $systemTools = "Habilidades disponibles: pos_create_draft(customer_id, items), pos_confirm_payment(...). Responde con tool_calls.";
        $input1 = "User: Crear un borrador POS para el cliente CUST-001. System: " . $systemTools;
        $output1 = "tool_calls: pos_create_draft(customer_id: 'CUST-001')";

        $input2 = "Tool Result: {status: success, id: DRAFT-123}";
        $output2 = "Listo, borrador DRAFT-123 creado.";

        $tokensIn = ($this->budgeter->estimate($input1) * 1.2) + $this->budgeter->estimate($input2); // Factor 1.2 por overhead de formato JSON de tools
        $tokensOut = $this->budgeter->estimate($output1) + $this->budgeter->estimate($output2);

        return [
            'name' => 'Tool-First (Autonomous)',
            'calls' => 2,
            'tokens_in' => (int)$tokensIn,
            'tokens_out' => $tokensOut,
            'total_tokens' => (int)$tokensIn + $tokensOut
        ];
    }

    private function printReport(array $results) {
        printf("%-20s | %-10s | %-12s | %-12s | %-12s\n", "Metodología", "Llamadas", "Tokens In", "Tokens Out", "Total");
        echo str_repeat("-", 75) . "\n";
        foreach ($results as $res) {
            printf("%-20s | %-10d | %-12d | %-12d | %-12d\n", 
                $res['name'], $res['calls'], $res['tokens_in'], $res['tokens_out'], $res['total_tokens']
            );
        }
        
        $saving = $results['legacy']['total_tokens'] - $results['tool_first']['total_tokens'];
        $percent = ($saving / $results['legacy']['total_tokens']) * 100;
        
        echo "\nRESULTADO: Tool-First ahorra un estimando de " . number_format($percent, 2) . "% de tokens en este escenario.\n";
        echo "MOTIVO: Reducción de RTT (pasos de 3 a 2) y eliminación del paso de extracción NLU explícito.\n";
    }
}

$benchmark = new SukiTokenBenchmark();
$benchmark->run();
