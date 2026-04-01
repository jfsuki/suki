<?php
declare(strict_types=1);

namespace App\Core\Agents;

/**
 * SoftwareArchitectPromptBuilder
 * Generates structured "Software Architect" guidance based on discovery templates.
 */
class SoftwareArchitectPromptBuilder
{
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    /**
     * Builds a prompt segment that injects "Architect" knowledge into the LLM.
     */
    public function buildArchitectGuidance(string $sectorKey = 'GENERAL'): string
    {
        $template = $this->loadTemplateForSector($sectorKey);
        if ($template === null) {
            return "Eres un Arquitecto de Software Experto. Tu meta es diseÃ±ar una app robusta y escalable.";
        }

        $sectorLabel = $template['sector_label'] ?? 'General';
        $processes = array_keys($template['business_processes'] ?? []);
        $docs = array_keys($template['business_documents'] ?? []);

        $prompt = "ACTÚA COMO UN ARQUITECTO DE SOFTWARE EXPERTO EN EL SECTOR: '$sectorLabel'.\n";
        $prompt .= "REGLAS DE DISEÑO TÉCNICO:\n";
        $prompt .= "1. Toda app debe tener soporte para: " . implode(', ', $processes) . ".\n";
        $prompt .= "2. Los documentos transaccionales obligatorios son: " . implode(', ', $docs) . ".\n";
        $prompt .= "3. Prioriza el aislamiento multitenant y la integridad de datos.\n";
        $prompt .= "4. Si el usuario no menciona uno de estos procesos, PREGUNTA si lo necesita.\n";
        
        if (!empty($template['accounting_rules']['chart_of_accounts_min'])) {
            $prompt .= "5. Estructura el plan de cuentas base usando estándares locales (ej. " . 
                       ($template['country_or_regulation'] ?? 'PUC') . ").\n";
        }

        // --- ENFOQUE EN LÓGICA DE NEGOCIO (Senior Upgrade) ---
        $logic = $this->loadBusinessLogicPlaybook();
        if ($logic) {
            $prompt .= "\nREGLAS DE LÓGICA DE NEGOCIO (PLAYBOOK 2026):\n";
            $prompt .= "- FÓRMULA DE PRECIO: Usar " . ($logic['rules']['pricing']['formula_base'] ?? '') . ".\n";
            $prompt .= "- REDONDEO: Aplica redondeo comercial (ej: múltiplos de 5000).\n";
            $prompt .= "- FISCAL: Si el total supera " . ($logic['rules']['tax_logic']['ica_alert_threshold'] ?? '') . ", sugiere una alerta de ICA.\n";
            $prompt .= "- EXTRACCIÓN: Extrae siempre 'margin' (float, ej: 0.25) y 'rounding' (int, ej: 5000) si el usuario los menciona.\n";
        }

        $prompt .= "\nTU MISIÓN EN ESTE TURNO:\n";
        $prompt .= "Guía al usuario para completar el alcance técnico y la lógica de negocio. ";
        $prompt .= "Sugiere proactivamente fórmulas de margen (ej: 25%) y redondeo si el usuario pide 'precios' o 'descuentos'.";

        return $prompt;
    }

    private function loadBusinessLogicPlaybook(): ?array
    {
        $path = $this->projectRoot . '/contracts/logic/business_logic_playbook.contract.json';
        if (!file_exists($path)) return null;
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function loadTemplateForSector(string $sectorKey): ?array
    {
        // For now, we only have one main template as a reference.
        // In the future, this should crawl project/contracts/knowledge/ for sector-specific files.
        $path = $this->projectRoot . '/contracts/knowledge/business_discovery_template.json';
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
