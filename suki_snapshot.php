<?php

/**
 * SUKI SYSTEM SNAPSHOT AUDIT (ROOT LEVEL)
 * -----------------------------------------
 * Script externo de auditoría del estado real del sistema.
 * NO forma parte del runtime del framework.
 * SOLO LECTURA.
 */

class SukiSystemSnapshot
{
    private string $basePath;
    private array $report = [];

    public function __construct()
    {
        // raíz del proyecto
        $this->basePath = __DIR__;
    }

    public function run(): array
    {
        $this->report['timestamp'] = date('c');

        $this->auditCoreContracts();
        $this->auditTraining();
        $this->auditPromptsStatus();
        $this->auditControlTower();
        $this->auditAuditAgent();
        $this->auditDatasetGenerator();

        return $this->report;
    }

    /**
     * -----------------------------------------
     * 1. CONTRATOS (GBO / BEG / AUDIT)
     * -----------------------------------------
     */
    private function auditCoreContracts()
    {
        $paths = [
            'gbo' => [
                'framework/contracts/schemas/gbo.schema.json',
                'framework/ontology/gbo_universal_concepts.json',
                'framework/ontology/gbo_business_events.json'
            ],
            'beg' => [
                'framework/contracts/schemas/beg.schema.json',
                'framework/events/beg_event_types.json'
            ],
            'audit' => [
                'framework/contracts/schemas/audit_alert.schema.json',
                'framework/audit/audit_rules.json'
            ]
        ];

        foreach ($paths as $key => $files) {
            foreach ($files as $file) {
                $full = $this->basePath . '/' . $file;

                $this->report['contracts'][$key][$file] = [
                    'exists' => file_exists($full),
                    'size' => file_exists($full) ? filesize($full) : 0
                ];
            }
        }
    }

    /**
     * -----------------------------------------
     * 2. ENTRENAMIENTO
     * -----------------------------------------
     */
    private function auditTraining()
    {
        $files = [
            'framework/training/intents_erp_base.json'
        ];

        foreach ($files as $file) {
            $full = $this->basePath . '/' . $file;

            $this->report['training'][$file] = [
                'exists' => file_exists($full),
                'size' => file_exists($full) ? filesize($full) : 0
            ];
        }
    }

    /**
     * -----------------------------------------
     * 3. PROMPTS STATUS (MAPA BASE)
     * -----------------------------------------
     */
    private function auditPromptsStatus()
    {
        $this->report['prompts'] = [
            "ERP INTENTS" => "CHECK",
            "ENTRENAMIENTO ERP" => "CHECK",
            "CEREBRO ERP" => "CHECK",
            "GLOBAL BUSINESS ONTOLOGY" => "CHECK",
            "SECTOR SEED CONTRACT" => "CHECK",
            "ERP DATASET GENERATOR" => "CHECK",
            "BUSINESS DISCOVERY" => "PENDING/AUDIT",
            "SECTOR PACK GENERATOR" => "PENDING",
            "IMPLEMENT GBO + BEG" => "CHECK",
            "AUDIT AGENT" => "IN PROGRESS",
            "AUTONOMOUS AGENTS" => "PENDING",
            "COUNTRY PACK" => "PENDING"
        ];
    }

    /**
     * -----------------------------------------
     * 4. CONTROL TOWER
     * -----------------------------------------
     */
    private function auditControlTower()
    {
        $paths = [
            'framework/app/Core/Agents',
            'framework/app/Core/ConversationGateway.php'
        ];

        foreach ($paths as $file) {
            $full = $this->basePath . '/' . $file;

            $this->report['control_tower'][$file] = [
                'exists' => file_exists($full)
            ];
        }
    }

    /**
     * -----------------------------------------
     * 5. AUDIT AGENT
     * -----------------------------------------
     */
    private function auditAuditAgent()
    {
        $paths = [
            'framework/contracts/schemas/audit_agent.schema.json',
            'framework/app/Core/AuditValidator.php'
        ];

        foreach ($paths as $file) {
            $full = $this->basePath . '/' . $file;

            $this->report['audit_agent'][$file] = [
                'exists' => file_exists($full)
            ];
        }
    }

    /**
     * -----------------------------------------
     * 6. DATASET GENERATOR
     * -----------------------------------------
     */
    private function auditDatasetGenerator()
    {
        $paths = [
            'framework/scripts/generate_erp_training_dataset.php'
        ];

        foreach ($paths as $file) {
            $full = $this->basePath . '/' . $file;

            $this->report['dataset_generator'][$file] = [
                'exists' => file_exists($full)
            ];
        }
    }
}

/**
 * -----------------------------------------
 * EJECUCIÓN
 * -----------------------------------------
 */

$snapshot = new SukiSystemSnapshot();
$result = $snapshot->run();

echo json_encode($result, JSON_PRETTY_PRINT);