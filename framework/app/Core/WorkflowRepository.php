<?php
// app/Core/WorkflowRepository.php

namespace App\Core;

use RuntimeException;

final class WorkflowRepository
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
    }

    public function exists(string $workflowId): bool
    {
        return is_file($this->contractPath($workflowId));
    }

    public function load(string $workflowId): array
    {
        $path = $this->contractPath($workflowId);
        if (!is_file($path)) {
            throw new RuntimeException('Workflow no encontrado: ' . $workflowId);
        }
        return $this->readJson($path);
    }

    public function list(): array
    {
        $dir = $this->contractsDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.workflow.contract.json') ?: [];
        $rows = [];
        foreach ($files as $path) {
            $contract = $this->readJson($path);
            $meta = is_array($contract['meta'] ?? null) ? (array) $contract['meta'] : [];
            $rows[] = [
                'id' => (string) ($meta['id'] ?? basename((string) $path, '.workflow.contract.json')),
                'name' => (string) ($meta['name'] ?? ''),
                'status' => (string) ($meta['status'] ?? 'draft'),
                'revision' => (int) ($meta['revision'] ?? 1),
                'updated_at' => (string) ($meta['updated_at'] ?? ''),
                'path' => $path,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        return $rows;
    }

    public function save(array $contract, string $note = 'manual_update'): array
    {
        WorkflowValidator::validateOrFail($contract);

        $meta = is_array($contract['meta'] ?? null) ? (array) $contract['meta'] : [];
        $workflowId = $this->sanitizeId((string) ($meta['id'] ?? ''));
        if ($workflowId === '') {
            throw new RuntimeException('Workflow sin meta.id');
        }

        $now = date('c');
        $contract['meta'] = $meta;
        $contract['meta']['id'] = $workflowId;
        if (trim((string) ($contract['meta']['name'] ?? '')) === '') {
            $contract['meta']['name'] = ucfirst(str_replace('_', ' ', $workflowId));
        }
        $contract['meta']['updated_at'] = $now;
        if (trim((string) ($contract['meta']['created_at'] ?? '')) === '') {
            $contract['meta']['created_at'] = $now;
        }

        $current = $this->exists($workflowId) ? $this->load($workflowId) : null;
        $revision = (int) ($contract['versioning']['revision'] ?? $contract['meta']['revision'] ?? 0);
        if ($current !== null) {
            $currentRevision = (int) ($current['versioning']['revision'] ?? $current['meta']['revision'] ?? 1);
            if ($revision <= $currentRevision) {
                $revision = $currentRevision + 1;
            }
            $this->appendHistory($workflowId, $current, $currentRevision, 'snapshot_before_save');
        } elseif ($revision < 1) {
            $revision = 1;
        }

        $contract['versioning'] = is_array($contract['versioning'] ?? null) ? (array) $contract['versioning'] : [];
        $contract['versioning']['revision'] = $revision;
        $historyPointers = is_array($contract['versioning']['historyPointers'] ?? null)
            ? (array) $contract['versioning']['historyPointers']
            : [];
        $historyPointers[] = 'rev_' . $revision;
        $contract['versioning']['historyPointers'] = array_values(array_unique($historyPointers));
        $contract['meta']['revision'] = $revision;

        $this->writeJson($this->contractPath($workflowId), $contract);
        $this->appendHistory($workflowId, $contract, $revision, $note);

        return [
            'id' => $workflowId,
            'revision' => $revision,
            'path' => $this->contractPath($workflowId),
        ];
    }

    public function history(string $workflowId): array
    {
        $dir = $this->historyDir($workflowId);
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/rev_*.json') ?: [];
        $rows = [];
        foreach ($files as $path) {
            $entry = $this->readJson($path);
            $rows[] = [
                'revision' => (int) ($entry['revision'] ?? 0),
                'saved_at' => (string) ($entry['saved_at'] ?? ''),
                'note' => (string) ($entry['note'] ?? ''),
                'path' => $path,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => ((int) $b['revision']) <=> ((int) $a['revision']));
        return $rows;
    }

    public function restore(string $workflowId, int $revision): array
    {
        if ($revision < 1) {
            throw new RuntimeException('Revision invalida');
        }
        $entryPath = $this->historyDir($workflowId) . '/rev_' . $revision . '.json';
        if (!is_file($entryPath)) {
            throw new RuntimeException('No existe revision ' . $revision . ' para workflow ' . $workflowId);
        }
        $entry = $this->readJson($entryPath);
        $contract = is_array($entry['contract'] ?? null) ? (array) $entry['contract'] : [];
        if (empty($contract)) {
            throw new RuntimeException('Revision sin contrato valido');
        }

        $contract['meta'] = is_array($contract['meta'] ?? null) ? (array) $contract['meta'] : [];
        $contract['meta']['updated_at'] = date('c');

        $save = $this->save($contract, 'restore_from_rev_' . $revision);
        $save['restored_from_revision'] = $revision;
        return $save;
    }

    public function diff(string $workflowId, int $fromRevision, int $toRevision): array
    {
        if ($fromRevision < 1 || $toRevision < 1) {
            throw new RuntimeException('from_revision y to_revision deben ser >= 1');
        }

        $workflowId = $this->sanitizeId($workflowId);
        if ($workflowId === '') {
            throw new RuntimeException('workflow_id invalido');
        }

        $fromContract = $this->loadRevisionContract($workflowId, $fromRevision);
        $toContract = $this->loadRevisionContract($workflowId, $toRevision);

        $fromNodes = $this->indexNodes($fromContract);
        $toNodes = $this->indexNodes($toContract);
        $fromEdges = $this->indexEdges($fromContract);
        $toEdges = $this->indexEdges($toContract);

        $nodesAdded = [];
        $nodesRemoved = [];
        $nodesChanged = [];
        foreach ($toNodes as $nodeId => $nodeData) {
            if (!isset($fromNodes[$nodeId])) {
                $nodesAdded[] = $nodeId;
                continue;
            }
            if ($fromNodes[$nodeId]['hash'] !== $nodeData['hash']) {
                $nodesChanged[] = $nodeId;
            }
        }
        foreach ($fromNodes as $nodeId => $_nodeData) {
            if (!isset($toNodes[$nodeId])) {
                $nodesRemoved[] = $nodeId;
            }
        }

        $edgesAdded = [];
        $edgesRemoved = [];
        foreach ($toEdges as $edgeId => $edgeData) {
            if (!isset($fromEdges[$edgeId])) {
                $edgesAdded[] = $edgeData['label'];
            }
        }
        foreach ($fromEdges as $edgeId => $edgeData) {
            if (!isset($toEdges[$edgeId])) {
                $edgesRemoved[] = $edgeData['label'];
            }
        }

        sort($nodesAdded);
        sort($nodesRemoved);
        sort($nodesChanged);
        sort($edgesAdded);
        sort($edgesRemoved);

        return [
            'workflow_id' => $workflowId,
            'from_revision' => $fromRevision,
            'to_revision' => $toRevision,
            'summary' => [
                'nodes_added' => count($nodesAdded),
                'nodes_removed' => count($nodesRemoved),
                'nodes_changed' => count($nodesChanged),
                'edges_added' => count($edgesAdded),
                'edges_removed' => count($edgesRemoved),
            ],
            'details' => [
                'nodes_added' => $nodesAdded,
                'nodes_removed' => $nodesRemoved,
                'nodes_changed' => $nodesChanged,
                'edges_added' => $edgesAdded,
                'edges_removed' => $edgesRemoved,
            ],
        ];
    }

    public function templates(): array
    {
        $dirs = [
            FRAMEWORK_ROOT . '/contracts/workflows/templates',
            $this->projectRoot . '/contracts/workflows/templates',
        ];
        $rows = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . '/*.workflow.template.json') ?: [];
            foreach ($files as $path) {
                $tpl = $this->readJson($path);
                $rows[] = [
                    'id' => (string) ($tpl['id'] ?? basename((string) $path, '.workflow.template.json')),
                    'label' => (string) ($tpl['label'] ?? ''),
                    'description' => (string) ($tpl['description'] ?? ''),
                    'contract' => is_array($tpl['contract'] ?? null) ? (array) $tpl['contract'] : [],
                    'path' => $path,
                ];
            }
        }
        return $rows;
    }

    public function remix(string $templateId, string $workflowId): array
    {
        $templateId = $this->sanitizeId($templateId);
        $workflowId = $this->sanitizeId($workflowId);
        if ($templateId === '' || $workflowId === '') {
            throw new RuntimeException('template_id o workflow_id invalido');
        }

        $template = null;
        foreach ($this->templates() as $row) {
            if ((string) ($row['id'] ?? '') === $templateId) {
                $template = $row;
                break;
            }
        }
        if ($template === null) {
            throw new RuntimeException('Template no encontrado: ' . $templateId);
        }

        $contract = is_array($template['contract'] ?? null) ? (array) $template['contract'] : [];
        if (empty($contract)) {
            throw new RuntimeException('Template sin contrato');
        }
        $contract['meta'] = is_array($contract['meta'] ?? null) ? (array) $contract['meta'] : [];
        $contract['meta']['id'] = $workflowId;
        $contract['meta']['name'] = trim((string) ($contract['meta']['name'] ?? '')) !== ''
            ? (string) $contract['meta']['name'] . ' (Remix)'
            : ucfirst(str_replace('_', ' ', $workflowId));
        $contract['meta']['status'] = 'draft';
        $contract['versioning'] = is_array($contract['versioning'] ?? null) ? (array) $contract['versioning'] : [];
        $contract['versioning']['revision'] = 1;
        $contract['versioning']['historyPointers'] = ['rev_1'];

        $save = $this->save($contract, 'remix_from_' . $templateId);
        $save['template_id'] = $templateId;
        return $save;
    }

    private function contractsDir(): string
    {
        return $this->projectRoot . '/contracts/workflows';
    }

    private function historyDir(string $workflowId): string
    {
        return $this->projectRoot . '/storage/workflows/history/' . $this->sanitizeId($workflowId);
    }

    private function contractPath(string $workflowId): string
    {
        return $this->contractsDir() . '/' . $this->sanitizeId($workflowId) . '.workflow.contract.json';
    }

    private function appendHistory(string $workflowId, array $contract, int $revision, string $note): void
    {
        $dir = $this->historyDir($workflowId);
        $entry = [
            'workflow_id' => $workflowId,
            'revision' => $revision,
            'saved_at' => date('c'),
            'note' => $note,
            'contract' => $contract,
        ];
        $this->writeJson($dir . '/rev_' . $revision . '.json', $entry);
    }

    private function loadRevisionContract(string $workflowId, int $revision): array
    {
        $entryPath = $this->historyDir($workflowId) . '/rev_' . $revision . '.json';
        if (!is_file($entryPath)) {
            throw new RuntimeException('No existe revision ' . $revision . ' para workflow ' . $workflowId);
        }
        $entry = $this->readJson($entryPath);
        $contract = is_array($entry['contract'] ?? null) ? (array) $entry['contract'] : [];
        if (empty($contract)) {
            throw new RuntimeException('Revision ' . $revision . ' sin contrato valido');
        }
        return $contract;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, array<string, string>>
     */
    private function indexNodes(array $contract): array
    {
        $nodes = is_array($contract['nodes'] ?? null) ? (array) $contract['nodes'] : [];
        $indexed = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }
            $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $indexed[$nodeId] = [
                'hash' => sha1((string) ($json === false ? '' : $json)),
            ];
        }
        return $indexed;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, array<string, string>>
     */
    private function indexEdges(array $contract): array
    {
        $edges = is_array($contract['edges'] ?? null) ? (array) $contract['edges'] : [];
        $indexed = [];
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $from = trim((string) ($edge['from'] ?? ''));
            $to = trim((string) ($edge['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            $mapping = is_array($edge['mapping'] ?? null) ? (array) $edge['mapping'] : [];
            ksort($mapping);
            $mappingJson = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $mappingKey = (string) ($mappingJson === false ? '{}' : $mappingJson);
            $edgeId = sha1($from . '->' . $to . '|' . $mappingKey);
            $indexed[$edgeId] = [
                'label' => $from . ' -> ' . $to,
            ];
        }
        return $indexed;
    }

    private function sanitizeId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]/', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? $value;
        return trim((string) $value, '_');
    }

    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('No se pudo leer JSON: ' . $path);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON invalido: ' . $path);
        }
        return $decoded;
    }

    private function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio: ' . $dir);
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar JSON');
        }
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('No se pudo guardar archivo: ' . $path);
        }
    }
}
