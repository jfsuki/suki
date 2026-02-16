<?php
// app/Core/ContractWriter.php

namespace App\Core;

use RuntimeException;

final class ContractWriter
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 2) . '/project');
    }

    public function writeEntity(array $entity): string
    {
        $name = (string) ($entity['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('entity.name requerido');
        }
        $path = $this->projectRoot . '/contracts/entities/' . $name . '.entity.json';
        $this->writeJsonFile($path, $entity);
        return $path;
    }

    public function writeForm(array $form): string
    {
        $name = (string) ($form['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('form.name requerido');
        }
        $path = $this->projectRoot . '/contracts/forms/' . $name . '.json';
        $this->writeJsonFile($path, $form);
        return $path;
    }

    private function writeJsonFile(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear directorio: ' . $dir);
            }
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar JSON.');
        }
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException('No se pudo escribir archivo: ' . $path);
        }
    }
}
