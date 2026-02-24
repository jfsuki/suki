<?php
// app/Core/WorkflowExecutor.php

namespace App\Core;

use RuntimeException;

final class WorkflowExecutor
{
    /** @var array<string, callable> */
    private array $tools;

    /**
     * @param array<string, callable> $tools
     */
    public function __construct(array $tools = [])
    {
        $this->tools = $tools;
    }

    public function execute(array $contract, array $input = [], array $context = []): array
    {
        WorkflowValidator::validateOrFail($contract);

        $nodes = is_array($contract['nodes'] ?? null) ? (array) $contract['nodes'] : [];
        $edges = is_array($contract['edges'] ?? null) ? (array) $contract['edges'] : [];
        $adjacency = $this->buildAdjacency($nodes, $edges);
        $levels = $this->topologicalLevels($nodes, $adjacency);

        $outputs = [];
        $traces = [];
        $error = null;

        foreach ($levels as $group) {
            foreach ($group as $nodeId) {
                $node = $this->findNode($nodes, $nodeId);
                if ($node === null) {
                    $error = 'Nodo no encontrado en ejecucion: ' . $nodeId;
                    break 2;
                }

                $nodeInput = $this->resolveNodeInput($nodeId, $edges, $outputs, $input);
                $started = microtime(true);
                $status = 'success';
                $nodeError = '';
                $toolCalls = [];
                $tokenUse = ['input' => 0, 'output' => 0, 'total' => 0, 'cost_usd_est' => 0.0];
                $nodeOutput = [];

                try {
                    $result = $this->runNode($node, $nodeInput, $outputs, $context);
                    $nodeOutput = is_array($result['output'] ?? null) ? (array) $result['output'] : [];
                    $toolCalls = is_array($result['toolCalls'] ?? null) ? (array) $result['toolCalls'] : [];
                    if (is_array($result['tokenUse'] ?? null)) {
                        $tokenUse = array_merge($tokenUse, (array) $result['tokenUse']);
                    }
                } catch (\Throwable $e) {
                    $status = 'error';
                    $nodeError = $e->getMessage();
                }

                $ended = microtime(true);
                $durationMs = (int) max(0, round(($ended - $started) * 1000));
                if ($status === 'success') {
                    $outputs[$nodeId] = $nodeOutput;
                } else {
                    $error = 'Fallo en nodo ' . $nodeId . ': ' . $nodeError;
                }

                $traces[] = [
                    'nodeId' => $nodeId,
                    'start' => date('c', (int) floor($started)),
                    'end' => date('c', (int) floor($ended)),
                    'duration_ms' => $durationMs,
                    'status' => $status,
                    'error' => $nodeError !== '' ? $nodeError : null,
                    'toolCalls' => $toolCalls,
                    'tokenUse' => $tokenUse,
                    'outputs' => $nodeOutput,
                ];

                if ($status !== 'success') {
                    break 2;
                }
            }
        }

        $finalOutput = $this->collectFinalOutput($nodes, $edges, $outputs);
        return [
            'ok' => $error === null,
            'error' => $error,
            'levels' => $levels,
            'outputs_by_node' => $outputs,
            'final_output' => $finalOutput,
            'traces' => $traces,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $edges
     * @return array<string, array<int, string>>
     */
    private function buildAdjacency(array $nodes, array $edges): array
    {
        $adjacency = [];
        foreach ($nodes as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            if ($id !== '') {
                $adjacency[$id] = [];
            }
        }
        foreach ($edges as $edge) {
            $from = trim((string) ($edge['from'] ?? ''));
            $to = trim((string) ($edge['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            if (!isset($adjacency[$from])) {
                $adjacency[$from] = [];
            }
            $adjacency[$from][] = $to;
        }
        return $adjacency;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, array<int, string>> $adjacency
     * @return array<int, array<int, string>>
     */
    private function topologicalLevels(array $nodes, array $adjacency): array
    {
        $inDegree = [];
        foreach ($nodes as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            if ($id !== '') {
                $inDegree[$id] = 0;
            }
        }
        foreach ($adjacency as $from => $list) {
            foreach ($list as $to) {
                if (!array_key_exists($to, $inDegree)) {
                    $inDegree[$to] = 0;
                }
                $inDegree[$to]++;
            }
        }

        $queue = [];
        foreach ($inDegree as $nodeId => $degree) {
            if ($degree === 0) {
                $queue[] = $nodeId;
            }
        }

        $levels = [];
        $visited = 0;
        while (!empty($queue)) {
            $current = $queue;
            $queue = [];
            $levels[] = $current;
            foreach ($current as $nodeId) {
                $visited++;
                $targets = $adjacency[$nodeId] ?? [];
                foreach ($targets as $to) {
                    $inDegree[$to]--;
                    if ($inDegree[$to] === 0) {
                        $queue[] = $to;
                    }
                }
            }
        }

        if ($visited !== count($inDegree)) {
            throw new RuntimeException('Workflow con ciclo detectado durante ejecucion.');
        }
        return $levels;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, mixed>|null
     */
    private function findNode(array $nodes, string $nodeId): ?array
    {
        foreach ($nodes as $node) {
            if (trim((string) ($node['id'] ?? '')) === $nodeId) {
                return $node;
            }
        }
        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     * @param array<string, array<string, mixed>> $outputs
     */
    private function resolveNodeInput(string $nodeId, array $edges, array $outputs, array $input): array
    {
        $resolved = [];
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            if (trim((string) ($edge['to'] ?? '')) !== $nodeId) {
                continue;
            }
            $from = trim((string) ($edge['from'] ?? ''));
            if ($from === '' || !isset($outputs[$from])) {
                continue;
            }
            $mapping = is_array($edge['mapping'] ?? null) ? (array) $edge['mapping'] : [];
            foreach ($mapping as $targetKey => $sourcePath) {
                $target = trim((string) $targetKey);
                $path = trim((string) $sourcePath);
                if ($target === '' || $path === '') {
                    continue;
                }
                if (str_starts_with($path, 'output.')) {
                    $path = substr($path, 7);
                }
                $value = $this->getByPath((array) $outputs[$from], $path);
                $resolved[$target] = $value;
            }
        }

        if (empty($resolved)) {
            $resolved = $input;
        } elseif (!empty($input)) {
            $resolved['_input'] = $input;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $nodeInput
     * @param array<string, array<string, mixed>> $outputs
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function runNode(array $node, array $nodeInput, array $outputs, array $context): array
    {
        $type = strtolower(trim((string) ($node['type'] ?? '')));
        $title = trim((string) ($node['title'] ?? ''));
        $promptTemplate = (string) ($node['promptTemplate'] ?? '');
        $modelConfig = is_array($node['modelConfig'] ?? null) ? (array) $node['modelConfig'] : [];

        if ($type === 'input') {
            return ['output' => $nodeInput];
        }

        if ($type === 'output') {
            return ['output' => $nodeInput];
        }

        if ($type === 'transform') {
            $map = is_array($modelConfig['map'] ?? null) ? (array) $modelConfig['map'] : [];
            if (empty($map)) {
                return ['output' => $nodeInput];
            }
            $out = [];
            foreach ($map as $target => $sourcePath) {
                $targetKey = trim((string) $target);
                $path = trim((string) $sourcePath);
                if ($targetKey === '' || $path === '') {
                    continue;
                }
                $out[$targetKey] = $this->getByPath($nodeInput, $path);
            }
            return ['output' => $out];
        }

        if ($type === 'decision') {
            $path = trim((string) ($modelConfig['path'] ?? ''));
            $operator = trim((string) ($modelConfig['operator'] ?? 'exists'));
            $value = $modelConfig['value'] ?? null;
            $actual = $path !== '' ? $this->getByPath($nodeInput, $path) : null;
            $result = false;
            switch ($operator) {
                case 'equals':
                    $result = $actual === $value;
                    break;
                case 'not_equals':
                    $result = $actual !== $value;
                    break;
                case 'exists':
                default:
                    $result = $actual !== null && $actual !== '';
                    break;
            }
            return ['output' => ['result' => $result, 'input' => $nodeInput]];
        }

        if ($type === 'tool') {
            $toolName = trim((string) ($modelConfig['tool'] ?? ($node['toolsAllowed'][0] ?? '')));
            if ($toolName === '') {
                throw new RuntimeException('Nodo tool sin herramienta definida');
            }
            $handler = $this->tools[$toolName] ?? null;
            if (!is_callable($handler)) {
                throw new RuntimeException('Tool no permitida: ' . $toolName);
            }
            $toolOutput = $handler($nodeInput, $context);
            $normalized = is_array($toolOutput) ? $toolOutput : ['value' => $toolOutput];
            return [
                'output' => $normalized,
                'toolCalls' => [['name' => $toolName, 'status' => 'success']],
            ];
        }

        if ($type === 'generate') {
            $text = $this->renderTemplate($promptTemplate, $nodeInput, $outputs);
            if ($text === '') {
                $text = $title !== '' ? $title : 'generated';
            }
            return [
                'output' => [
                    'text' => $text,
                    'input' => $nodeInput,
                ],
                'tokenUse' => [
                    'input' => 0,
                    'output' => 0,
                    'total' => 0,
                    'cost_usd_est' => 0.0,
                ],
            ];
        }

        throw new RuntimeException('Tipo de nodo no soportado: ' . $type);
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $edges
     * @param array<string, array<string, mixed>> $outputs
     * @return array<string, mixed>
     */
    private function collectFinalOutput(array $nodes, array $edges, array $outputs): array
    {
        $hasOutgoing = [];
        foreach ($edges as $edge) {
            $from = trim((string) ($edge['from'] ?? ''));
            if ($from !== '') {
                $hasOutgoing[$from] = true;
            }
        }

        $sinks = [];
        foreach ($nodes as $node) {
            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '' || isset($hasOutgoing[$id])) {
                continue;
            }
            if (isset($outputs[$id])) {
                $sinks[$id] = $outputs[$id];
            }
        }

        if (count($sinks) === 1) {
            return (array) array_values($sinks)[0];
        }
        return $sinks;
    }

    private function renderTemplate(string $template, array $nodeInput, array $outputs): string
    {
        $template = trim($template);
        if ($template === '') {
            return '';
        }
        $result = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', function (array $m) use ($nodeInput, $outputs): string {
            $path = trim((string) ($m[1] ?? ''));
            if ($path === '') {
                return '';
            }
            if (str_starts_with($path, 'input.')) {
                $value = $this->getByPath($nodeInput, substr($path, 6));
                return is_scalar($value) ? (string) $value : '';
            }
            if (str_starts_with($path, 'node.')) {
                $raw = substr($path, 5);
                [$nodeId, $subPath] = array_pad(explode('.', $raw, 2), 2, '');
                $nodeId = trim($nodeId);
                if ($nodeId === '' || !isset($outputs[$nodeId])) {
                    return '';
                }
                $value = $subPath !== '' ? $this->getByPath((array) $outputs[$nodeId], $subPath) : $outputs[$nodeId];
                return is_scalar($value) ? (string) $value : '';
            }
            $value = $this->getByPath($nodeInput, $path);
            return is_scalar($value) ? (string) $value : '';
        }, $template);
        return is_string($result) ? $result : $template;
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed
     */
    private function getByPath(array $payload, string $path)
    {
        $path = trim($path);
        if ($path === '') {
            return $payload;
        }
        $cursor = $payload;
        $parts = explode('.', $path);
        foreach ($parts as $part) {
            $key = trim($part);
            if ($key === '') {
                return null;
            }
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }
        return $cursor;
    }
}

