<?php
// app/Core/ContractRegistry.php

declare(strict_types=1);

namespace App\Core;

use JsonException;
use RuntimeException;

final class ContractRegistry
{
    private string $contractsDir;

    /** @var array<string, array> */
    private array $cache = [];

    public function __construct(?string $contractsDir = null)
    {
        if (is_string($contractsDir) && trim($contractsDir) !== '') {
            $this->contractsDir = rtrim($contractsDir, '/\\');
            return;
        }

        $fromEnv = trim((string) (getenv('SUKI_CONTRACTS_DIR') ?: ''));
        if ($fromEnv !== '') {
            $this->contractsDir = rtrim($fromEnv, '/\\');
            return;
        }

        $workspaceRoot = dirname(__DIR__, 3);
        $runtimeContractsDir = (defined('PROJECT_ROOT') ? PROJECT_ROOT : ($workspaceRoot . '/project')) . '/contracts';
        $docsContractsDir = $workspaceRoot . '/docs/contracts';

        if ($this->isContractsDirComplete($runtimeContractsDir)) {
            $this->contractsDir = $runtimeContractsDir;
            return;
        }

        $this->contractsDir = $docsContractsDir;
    }

    public function getContractsDir(): string
    {
        return $this->contractsDir;
    }

    public function getRouterPolicy(): array
    {
        return $this->loadContract('router_policy.json', 'router_policy');
    }

    public function getActionCatalog(): array
    {
        return $this->loadContract('action_catalog.json', 'action_catalog');
    }

    public function getAgentOpsMetricsContract(): array
    {
        return $this->loadContract('agentops_metrics_contract.json', 'agentops_metrics_contract');
    }

    public function getSkillsCatalog(): array
    {
        return $this->loadContract('skills_catalog.json', 'skills_catalog');
    }

    public function versions(): array
    {
        $router = $this->getRouterPolicy();
        $actions = $this->getActionCatalog();
        $agentOps = $this->getAgentOpsMetricsContract();
        $skills = $this->getSkillsCatalog();

        return [
            'router_policy' => (string) ($router['version'] ?? 'unknown'),
            'action_catalog' => (string) ($actions['version'] ?? 'unknown'),
            'agentops_metrics_contract' => (string) ($agentOps['version'] ?? 'unknown'),
            'skills_catalog' => (string) ($skills['version'] ?? 'unknown'),
        ];
    }

    private function loadContract(string $fileName, string $contractId): array
    {
        $cacheKey = $contractId . '::' . $fileName;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $path = $this->contractsDir . '/' . $fileName;
        if (!is_file($path)) {
            throw new RuntimeException('ContractRegistry: contract not found: ' . $path);
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('ContractRegistry: contract is empty: ' . $path);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('ContractRegistry: invalid JSON at ' . $path, 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('ContractRegistry: root JSON must be object: ' . $path);
        }

        $this->validateBaseContract($decoded, $contractId, $path);
        $this->validateByType($decoded, $contractId, $path);
        $this->cache[$cacheKey] = $decoded;
        return $decoded;
    }

    private function validateBaseContract(array $contract, string $expectedId, string $path): void
    {
        $required = ['contract_id', 'version', 'effective_date'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $contract)) {
                throw new RuntimeException("ContractRegistry: missing key '{$key}' in {$path}");
            }
            if (!is_string($contract[$key]) || trim((string) $contract[$key]) === '') {
                throw new RuntimeException("ContractRegistry: key '{$key}' must be non-empty string in {$path}");
            }
        }

        if ((string) $contract['contract_id'] !== $expectedId) {
            throw new RuntimeException("ContractRegistry: contract_id mismatch in {$path}; expected {$expectedId}");
        }

        if (!$this->isValidDate((string) $contract['effective_date'])) {
            throw new RuntimeException("ContractRegistry: invalid effective_date in {$path}; expected YYYY-MM-DD");
        }
    }

    private function validateByType(array $contract, string $contractId, string $path): void
    {
        if ($contractId === 'router_policy') {
            $this->validateRouterPolicy($contract, $path);
            return;
        }
        if ($contractId === 'action_catalog') {
            $this->validateActionCatalog($contract, $path);
            return;
        }
        if ($contractId === 'agentops_metrics_contract') {
            $this->validateAgentOpsContract($contract, $path);
            return;
        }
        if ($contractId === 'skills_catalog') {
            $this->validateSkillsCatalog($contract, $path);
            return;
        }

        throw new RuntimeException('ContractRegistry: unsupported contract type: ' . $contractId);
    }

    private function validateRouterPolicy(array $contract, string $path): void
    {
        if (!is_array($contract['route_order'] ?? null) || empty($contract['route_order'])) {
            throw new RuntimeException("ContractRegistry: route_order must be non-empty array in {$path}");
        }
        foreach ($contract['route_order'] as $stage) {
            if (!is_string($stage) || trim($stage) === '') {
                throw new RuntimeException("ContractRegistry: route_order stages must be non-empty strings in {$path}");
            }
        }

        $rules = $contract['rules'] ?? null;
        if (!is_array($rules)) {
            throw new RuntimeException("ContractRegistry: rules must be object in {$path}");
        }
        if (!array_key_exists('llm_is_last_resort', $rules) || !is_bool($rules['llm_is_last_resort'])) {
            throw new RuntimeException("ContractRegistry: rules.llm_is_last_resort must be boolean in {$path}");
        }

        if (!is_array($contract['minimum_evidence'] ?? null)) {
            throw new RuntimeException("ContractRegistry: minimum_evidence must be object in {$path}");
        }
        if (!is_array($contract['missing_evidence_actions'] ?? null)) {
            throw new RuntimeException("ContractRegistry: missing_evidence_actions must be object in {$path}");
        }
    }

    private function validateActionCatalog(array $contract, string $path): void
    {
        if (!is_array($contract['required_fields_per_intent'] ?? null) || empty($contract['required_fields_per_intent'])) {
            throw new RuntimeException("ContractRegistry: required_fields_per_intent must be non-empty array in {$path}");
        }
        if (!is_array($contract['catalog'] ?? null) || empty($contract['catalog'])) {
            throw new RuntimeException("ContractRegistry: catalog must be non-empty array in {$path}");
        }

        /** @var array<int, string> $requiredFields */
        $requiredFields = array_values(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $contract['required_fields_per_intent']
        ), static fn(string $value): bool => $value !== ''));

        if (empty($requiredFields)) {
            throw new RuntimeException("ContractRegistry: required_fields_per_intent has no valid fields in {$path}");
        }

        foreach ($contract['catalog'] as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}] must be object in {$path}");
            }
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $entry)) {
                    throw new RuntimeException("ContractRegistry: catalog[{$index}] missing '{$field}' in {$path}");
                }
            }
            if (!is_string($entry['name']) || trim((string) $entry['name']) === '') {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].name must be non-empty string in {$path}");
            }
            if (!is_array($entry['allowed_tools'] ?? null)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].allowed_tools must be array in {$path}");
            }
            if (!is_array($entry['gates_required'] ?? null)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].gates_required must be array in {$path}");
            }
        }
    }

    private function validateAgentOpsContract(array $contract, string $path): void
    {
        if (!is_array($contract['required_event_fields'] ?? null) || empty($contract['required_event_fields'])) {
            throw new RuntimeException("ContractRegistry: required_event_fields must be non-empty array in {$path}");
        }
        if (!is_array($contract['traceable_events_minimum'] ?? null) || empty($contract['traceable_events_minimum'])) {
            throw new RuntimeException("ContractRegistry: traceable_events_minimum must be non-empty array in {$path}");
        }
        if (!is_array($contract['versions_contract'] ?? null)) {
            throw new RuntimeException("ContractRegistry: versions_contract must be object in {$path}");
        }

        $versions = $contract['versions_contract'];
        if (!array_key_exists('required', $versions) || !is_bool($versions['required'])) {
            throw new RuntimeException("ContractRegistry: versions_contract.required must be boolean in {$path}");
        }
        if (!is_array($versions['fields'] ?? null) || empty($versions['fields'])) {
            throw new RuntimeException("ContractRegistry: versions_contract.fields must be non-empty array in {$path}");
        }

        if (!is_array($contract['metrics'] ?? null) || empty($contract['metrics'])) {
            throw new RuntimeException("ContractRegistry: metrics must be non-empty object in {$path}");
        }
    }

    private function validateSkillsCatalog(array $contract, string $path): void
    {
        if (!is_array($contract['required_fields'] ?? null) || empty($contract['required_fields'])) {
            throw new RuntimeException("ContractRegistry: required_fields must be non-empty array in {$path}");
        }
        if (!is_array($contract['supported_execution_modes'] ?? null) || empty($contract['supported_execution_modes'])) {
            throw new RuntimeException("ContractRegistry: supported_execution_modes must be non-empty array in {$path}");
        }
        if (!is_array($contract['catalog'] ?? null) || empty($contract['catalog'])) {
            throw new RuntimeException("ContractRegistry: catalog must be non-empty array in {$path}");
        }

        $requiredFields = array_values(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $contract['required_fields']
        ), static fn(string $value): bool => $value !== ''));
        if ($requiredFields === []) {
            throw new RuntimeException("ContractRegistry: required_fields has no valid values in {$path}");
        }

        $supportedModes = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string) $value)),
            $contract['supported_execution_modes']
        ), static fn(string $value): bool => $value !== ''));
        if ($supportedModes === []) {
            throw new RuntimeException("ContractRegistry: supported_execution_modes has no valid values in {$path}");
        }

        foreach ($contract['catalog'] as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}] must be object in {$path}");
            }
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $entry)) {
                    throw new RuntimeException("ContractRegistry: catalog[{$index}] missing '{$field}' in {$path}");
                }
            }
            if (!is_string($entry['name']) || trim((string) $entry['name']) === '') {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].name must be non-empty string in {$path}");
            }
            if (!is_string($entry['description']) || trim((string) $entry['description']) === '') {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].description must be non-empty string in {$path}");
            }
            if (!is_array($entry['intent_patterns'] ?? null) || empty($entry['intent_patterns'])) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].intent_patterns must be non-empty array in {$path}");
            }
            if (!is_array($entry['allowed_tools'] ?? null)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].allowed_tools must be array in {$path}");
            }
            $executionMode = strtolower(trim((string) ($entry['execution_mode'] ?? '')));
            if (!in_array($executionMode, $supportedModes, true)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].execution_mode must be supported in {$path}");
            }
            if (!is_numeric($entry['priority'] ?? null)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].priority must be numeric in {$path}");
            }
            if (!is_array($entry['input_schema'] ?? null)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].input_schema must be object in {$path}");
            }
            if (!is_array($entry['channel_capabilities'] ?? null)) {
                throw new RuntimeException("ContractRegistry: catalog[{$index}].channel_capabilities must be array in {$path}");
            }
        }
    }

    private function isValidDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $dt instanceof \DateTimeImmutable && $dt->format('Y-m-d') === $value;
    }

    private function isContractsDirComplete(string $dir): bool
    {
        $dir = rtrim($dir, '/\\');
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }

        $required = [
            'router_policy.json',
            'action_catalog.json',
            'agentops_metrics_contract.json',
            'skills_catalog.json',
        ];
        foreach ($required as $file) {
            if (!is_file($dir . '/' . $file)) {
                return false;
            }
        }
        return true;
    }
}
