<?php

namespace App\Core\Contracts;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

class ContractRepository
{
    private string $frameworkRoot;
    private string $projectRoot;
    private ContractCache $cache;

    public function __construct(?string $frameworkRoot = null, ?string $projectRoot = null)
    {
        $this->frameworkRoot = $frameworkRoot
            ?? (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3));

        $workspaceRoot = dirname($this->frameworkRoot);
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : $workspaceRoot . '/project');

        $this->cache = new ContractCache($this->projectRoot);
    }

    public function getForm(string $key, ?string $module = null): array
    {
        $path = $this->resolveFormPath($key, $module);

        if ($path === null) {
            $moduleMsg = $module ? " in module {$module}" : '';
            throw new RuntimeException("ContractRepository: form not found for {$key}{$moduleMsg}.");
        }

        return $this->readJson($path);
    }

    public function getFormMeta(string $key, ?string $module = null): array
    {
        $path = $this->resolveFormPath($key, $module);

        if ($path === null) {
            $moduleMsg = $module ? " in module {$module}" : '';
            throw new RuntimeException("ContractRepository: form not found for {$key}{$moduleMsg}.");
        }

        $data = $this->readJson($path);
        return [
            'data' => $data,
            'path' => $path,
            'mtime' => filemtime($path) ?: 0,
            'size' => filesize($path) ?: 0,
        ];
    }

    public function existsForm(string $key, ?string $module = null): bool
    {
        return $this->resolveFormPath($key, $module) !== null;
    }

    public function getSchema(string $schemaKey): array
    {
        $this->assertValid($schemaKey, 'schemaKey');

        $candidates = [
            $this->frameworkRoot . "/contracts/schemas/{$schemaKey}.json",
            $this->frameworkRoot . "/contracts/{$schemaKey}.schema.json",
            $this->frameworkRoot . "/contracts/{$schemaKey}.contract.schema.json",
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $this->readJson($path);
            }
        }

        throw new RuntimeException("ContractRepository: schema not found for {$schemaKey}.");
    }

    private function resolveFormPath(string $key, ?string $module = null): ?string
    {
        $this->assertValid($key, 'key');
        if ($module !== null) {
            $this->assertValid($module, 'module');
        }

        $candidates = [
            $this->projectRoot . "/contracts/forms/{$key}.json",
            $this->frameworkRoot . "/contracts/forms/{$key}.json",
        ];

        if ($module !== null && $module !== '') {
            $candidates[] = $this->projectRoot . "/views/{$module}/{$key}.form.json";
            $candidates[] = $this->frameworkRoot . "/views/{$module}/{$key}.form.json";
        }

        $candidates[] = $this->frameworkRoot . "/contracts/{$key}.json";

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function readJson(string $path): array
    {
        $cached = $this->cache->get($path);
        if (is_array($cached)) {
            return $cached;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("ContractRepository: unable to read {$path}.");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("ContractRepository: invalid JSON in {$path}.", 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("ContractRepository: JSON root must be an object or array in {$path}.");
        }

        $this->cache->put($path, $decoded);
        return $decoded;
    }

    private function assertValid(string $value, string $label): void
    {
        if ($value === '' || strpos($value, '..') !== false || !preg_match('/^[a-zA-Z0-9\/_.-]+$/', $value)) {
            throw new InvalidArgumentException("ContractRepository: {$label} invalido.");
        }
    }
}
