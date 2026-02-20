<?php
// app/Core/CapabilityGraph.php

namespace App\Core;

final class CapabilityGraph
{
    private ContractsCatalog $catalog;

    public function __construct(?string $projectRoot = null)
    {
        $this->catalog = new ContractsCatalog($projectRoot);
    }

    public function build(string $projectId, string $mode = 'app'): array
    {
        $entities = $this->loadEntities();
        $forms = $this->loadForms();

        $entityNames = array_map(static fn(array $e): string => (string) ($e['name'] ?? ''), $entities);
        $actions = $this->buildActions($mode, $entityNames);

        return [
            'project_id' => $projectId,
            'mode' => $mode,
            'entities' => $entities,
            'forms' => $forms,
            'actions' => $actions,
        ];
    }

    private function loadEntities(): array
    {
        $list = [];
        foreach ($this->catalog->entities() as $path) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                continue;
            }
            $name = (string) ($data['name'] ?? basename($path, '.entity.json'));
            if ($name === '') {
                continue;
            }
            $label = (string) ($data['label'] ?? $name);
            $fields = [];
            $required = [];
            foreach (($data['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fname = (string) ($field['name'] ?? '');
                if ($fname === '' || $fname === 'id') {
                    continue;
                }
                $fields[] = $fname;
                if (!empty($field['required'])) {
                    $required[] = $fname;
                }
            }
            $list[] = [
                'name' => $name,
                'label' => $label,
                'fields' => $fields,
                'required' => $required,
            ];
        }
        return $list;
    }

    private function loadForms(): array
    {
        $list = [];
        foreach ($this->catalog->forms() as $path) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                continue;
            }
            $name = (string) ($data['name'] ?? basename($path, '.json'));
            if ($name === '') {
                continue;
            }
            $list[] = [
                'name' => $name,
                'title' => (string) ($data['title'] ?? $name),
                'entity' => (string) ($data['entity'] ?? ''),
            ];
        }
        return $list;
    }

    private function buildActions(string $mode, array $entityNames): array
    {
        if ($mode === 'builder') {
            return [
                'create_entity' => true,
                'create_form' => true,
                'run_tests' => true,
            ];
        }
        $actions = [
            'create_record' => [],
            'query_records' => [],
            'update_record' => [],
            'delete_record' => [],
        ];
        foreach ($entityNames as $entity) {
            if ($entity === '') {
                continue;
            }
            $actions['create_record'][] = $entity;
            $actions['query_records'][] = $entity;
            $actions['update_record'][] = $entity;
            $actions['delete_record'][] = $entity;
        }
        return $actions;
    }
}

