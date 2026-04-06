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
            return "Eres un Arquitecto de Software Experto. Tu meta es diseñar una app robusta y escalable.";
        }

        $sectorLabel = $template['sector_label'] ?? 'General';
        
        $prompt = "ACTÚA COMO UN ARQUITECTO DE SOFTWARE EXPERTO EN EL SECTOR: '$sectorLabel'.\n";
        $prompt .= "REGLAS DE DISEÑO TÉCNICO (RESUMEN):\n";
        $prompt .= $this->summarizeSectorTemplate($template);
        
        // --- ENFOQUE EN LÓGICA DE NEGOCIO (Senior Upgrade) ---
        $logic = $this->loadBusinessLogicPlaybook();
        if ($logic) {
            $prompt .= "\nREGLAS DE LÓGICA DE NEGOCIO (SUMMARIZED):\n";
            $prompt .= $this->summarizeBusinessPlaybook($logic);
        }

        $prompt .= "\nTU MISIÓN EN ESTE TURNO:\n";
        $prompt .= "Guía al usuario para completar el alcance técnico y la lógica de negocio. ";
        $prompt .= "Sugiere proactivamente fórmulas de margen (ej: 25%) e integraciones sectoriales.";

        return $prompt;
    }

    /**
     * Summarizes the sector template into a dense string to optimize token usage.
     */
    private function summarizeSectorTemplate(array $template): string
    {
        $summary = "";
        
        if (!empty($template['business_processes'])) {
            $processes = [];
            foreach ($template['business_processes'] as $key => $p) {
                $processes[] = ($p['label'] ?? $key);
            }
            $summary .= "- Procesos Core: " . implode(', ', $processes) . ".\n";
        }

        if (!empty($template['business_documents'])) {
            $docs = [];
            foreach ($template['business_documents'] as $key => $d) {
                $fields = isset($d['key_fields']) ? ("(" . implode(',', (array)$d['key_fields']) . ")") : "";
                $docs[] = $key . $fields;
            }
            $summary .= "- Documentos: " . implode(' | ', $docs) . ".\n";
        }

        if (!empty($template['accounting_rules']['posting_rules'])) {
            $rules = [];
            foreach (array_slice($template['accounting_rules']['posting_rules'], 0, 4) as $r) {
                $rules[] = ($r['trigger'] ?? 'upd') . ":" . ($r['debit_account'] ?? '?') . "/" . ($r['credit_account'] ?? '?');
            }
            $summary .= "- Contabilización: " . implode(', ', $rules) . ".\n";
        }

        return $summary;
    }

    /**
     * Summarizes the business playbook rules.
     */
    private function summarizeBusinessPlaybook(array $logic): string
    {
        $rules = $logic['rules'] ?? [];
        $summary = "";
        
        if (isset($rules['pricing'])) {
            $summary .= "- Pricing: Formula=" . ($rules['pricing']['formula_base'] ?? 'N/A');
            if (isset($rules['pricing']['margin_standards'])) {
                $summary .= " | Margins=" . json_encode($rules['pricing']['margin_standards']);
            }
            $summary .= "\n";
        }

        if (isset($rules['tax_logic'])) {
            $summary .= "- Tax: IVA=" . ($rules['tax_logic']['iva'] ?? '0.19');
            $summary .= " | ICA_Threshold=" . ($rules['tax_logic']['ica_alert_threshold'] ?? '1M') . "\n";
        }

        $summary .= "- Extraction: Always capture 'margin' (float) & 'rounding' (int).\n";

        return $summary;
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
