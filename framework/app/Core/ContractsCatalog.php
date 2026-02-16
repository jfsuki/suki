<?php
// app/Core/ContractsCatalog.php

namespace App\Core;

final class ContractsCatalog
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 2) . '/project');
    }

    public function forms(): array
    {
        $dir = $this->projectRoot . '/contracts/forms';
        return $this->listJson($dir);
    }

    public function entities(): array
    {
        $dir = $this->projectRoot . '/contracts/entities';
        return $this->listJson($dir);
    }

    private function listJson(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        return $files;
    }
}
