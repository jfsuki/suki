<?php
// app/Core/WorkflowCompiler.php

namespace App\Core;

final class WorkflowCompiler
{
    /**
     * @return array<string, mixed>
     */
    public function compile(string $text, array $currentContract = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return [
                'status' => 'NEEDS_CLARIFICATION',
                'question' => 'Dime en una frase el flujo que quieres construir.',
            ];
        }

        $workflowId = $this->resolveWorkflowId($text, $currentContract);
        $contract = !empty($currentContract) ? $currentContract : $this->seedContract($workflowId);
        $changes = [];

        $hasInput = $this->hasNodeType($contract, 'input');
        $hasGenerate = $this->hasNodeType($contract, 'generate');
        $hasOutput = $this->hasNodeType($contract, 'output');

        if (!$hasInput) {
            $contract['nodes'][] = $this->buildNode('n_input', 'input', 'Captura inicial');
            $changes[] = ['op' => 'add_node', 'id' => 'n_input', 'type' => 'input'];
        }

        if (!$hasGenerate) {
            $prompt = $this->inferPromptTemplate($text);
            $node = $this->buildNode('n_generate', 'generate', 'Generar respuesta');
            $node['promptTemplate'] = $prompt;
            $contract['nodes'][] = $node;
            $changes[] = ['op' => 'add_node', 'id' => 'n_generate', 'type' => 'generate', 'promptTemplate' => $prompt];
        } else {
            $prompt = $this->inferPromptTemplate($text);
            $index = $this->findNodeIndexByType($contract, 'generate');
            if ($index !== null) {
                $current = trim((string) ($contract['nodes'][$index]['promptTemplate'] ?? ''));
                if ($prompt !== '' && $prompt !== $current) {
                    $contract['nodes'][$index]['promptTemplate'] = $prompt;
                    $changes[] = ['op' => 'update_node', 'id' => (string) ($contract['nodes'][$index]['id'] ?? ''), 'field' => 'promptTemplate'];
                }
            }
        }

        if (!$hasOutput) {
            $contract['nodes'][] = $this->buildNode('n_output', 'output', 'Salida final');
            $changes[] = ['op' => 'add_node', 'id' => 'n_output', 'type' => 'output'];
        }

        $changes = array_merge($changes, $this->ensureLinearEdges($contract));
        $contract['meta']['updated_at'] = date('c');
        $contract['meta']['status'] = 'draft';

        $summary = [];
        foreach ($changes as $change) {
            $op = (string) ($change['op'] ?? '');
            $id = (string) ($change['id'] ?? '');
            if ($op === 'add_node') {
                $summary[] = 'nodo ' . $id;
            } elseif ($op === 'add_edge') {
                $summary[] = 'conexion ' . (string) ($change['from'] ?? '') . ' -> ' . (string) ($change['to'] ?? '');
            } elseif ($op === 'update_node') {
                $summary[] = 'ajuste de prompt en ' . $id;
            }
        }

        return [
            'status' => 'PROPOSAL_READY',
            'needs_confirmation' => true,
            'workflow_id' => $workflowId,
            'changes' => $changes,
            'summary' => !empty($summary) ? implode(', ', array_slice($summary, 0, 5)) : 'Sin cambios estructurales',
            'proposed_contract' => $contract,
            'confirmation_reply' => 'Tengo una propuesta de workflow para "' . $workflowId . '". ¿La aplico?',
        ];
    }

    private function resolveWorkflowId(string $text, array $contract): string
    {
        $existing = trim((string) ($contract['meta']['id'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }
        $base = $this->slug('workflow_' . substr($text, 0, 48));
        if ($base === '' || $base === 'workflow_') {
            $base = 'workflow_' . date('Ymd_His');
        }
        return $base;
    }

    private function seedContract(string $workflowId): array
    {
        return [
            'meta' => [
                'id' => $workflowId,
                'name' => ucfirst(str_replace('_', ' ', $workflowId)),
                'status' => 'draft',
                'revision' => 1,
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ],
            'nodes' => [],
            'edges' => [],
            'assets' => [],
            'theme' => ['presetName' => 'clean_business'],
            'versioning' => [
                'revision' => 1,
                'historyPointers' => ['rev_1'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNode(string $id, string $type, string $title): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'inputsSchema' => new \stdClass(),
            'promptTemplate' => '',
            'modelConfig' => new \stdClass(),
            'toolsAllowed' => [],
            'outputsSchema' => new \stdClass(),
            'uiHints' => new \stdClass(),
            'runPolicy' => [
                'timeout_ms' => 15000,
                'retry_max' => 0,
                'token_budget' => 0,
            ],
        ];
    }

    private function hasNodeType(array $contract, string $type): bool
    {
        $nodes = is_array($contract['nodes'] ?? null) ? (array) $contract['nodes'] : [];
        foreach ($nodes as $node) {
            if (strtolower(trim((string) ($node['type'] ?? ''))) === $type) {
                return true;
            }
        }
        return false;
    }

    private function findNodeIndexByType(array $contract, string $type): ?int
    {
        $nodes = is_array($contract['nodes'] ?? null) ? (array) $contract['nodes'] : [];
        foreach ($nodes as $index => $node) {
            if (strtolower(trim((string) ($node['type'] ?? ''))) === $type) {
                return $index;
            }
        }
        return null;
    }

    private function inferPromptTemplate(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        if (preg_match('/\b(cotizacion|cotización|quote)\b/u', $text) === 1) {
            return 'Genera una cotizacion breve para {{input.cliente}} con total {{input.total}}.';
        }
        if (preg_match('/\b(resumen|dashboard|indicador|kpi)\b/u', $text) === 1) {
            return 'Resume el estado actual con foco en {{input.metrica}} y {{input.periodo}}.';
        }
        if (preg_match('/\b(factura|facturacion|facturación)\b/u', $text) === 1) {
            return 'Genera el texto final de factura para {{input.cliente}} y total {{input.total}}.';
        }
        return 'Genera una salida clara usando los datos de entrada: {{input.descripcion}}.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ensureLinearEdges(array &$contract): array
    {
        $nodes = is_array($contract['nodes'] ?? null) ? (array) $contract['nodes'] : [];
        $typeToId = [];
        foreach ($nodes as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            $type = strtolower(trim((string) ($node['type'] ?? '')));
            if ($id !== '' && $type !== '' && !isset($typeToId[$type])) {
                $typeToId[$type] = $id;
            }
        }

        $changes = [];
        $edges = is_array($contract['edges'] ?? null) ? (array) $contract['edges'] : [];
        $required = [];
        if (!empty($typeToId['input']) && !empty($typeToId['generate'])) {
            $required[] = [$typeToId['input'], $typeToId['generate'], ['descripcion' => 'output.descripcion', 'cliente' => 'output.cliente', 'total' => 'output.total', 'periodo' => 'output.periodo', 'metrica' => 'output.metrica']];
        }
        if (!empty($typeToId['generate']) && !empty($typeToId['output'])) {
            $required[] = [$typeToId['generate'], $typeToId['output'], ['text' => 'output.text']];
        }

        foreach ($required as $item) {
            [$from, $to, $mapping] = $item;
            if (!$this->edgeExists($edges, (string) $from, (string) $to)) {
                $edges[] = [
                    'from' => (string) $from,
                    'to' => (string) $to,
                    'mapping' => $mapping,
                ];
                $changes[] = ['op' => 'add_edge', 'from' => (string) $from, 'to' => (string) $to];
            }
        }
        $contract['edges'] = $edges;
        return $changes;
    }

    private function edgeExists(array $edges, string $from, string $to): bool
    {
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            if (trim((string) ($edge['from'] ?? '')) === $from && trim((string) ($edge['to'] ?? '')) === $to) {
                return true;
            }
        }
        return false;
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? $value;
        return trim($value, '_');
    }
}

