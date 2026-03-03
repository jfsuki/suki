<?php
// app/Core/ExcelImportService.php

namespace App\Core;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class ExcelImportService
{
    private EntityMigrator $migrator;

    public function __construct(?EntityMigrator $migrator = null)
    {
        $this->migrator = $migrator ?? new EntityMigrator();
    }

    public function importFile(string $filePath, string $tenantId, bool $overwrite = false): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Archivo Excel no encontrado o sin permisos de lectura.');
        }

        $tenant = $this->parseTenantId($tenantId);
        if ($tenant === null) {
            throw new RuntimeException('tenant_id debe ser numerico para importar Excel.');
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            throw new RuntimeException('No fue posible leer el archivo Excel.', 0, $e);
        }

        $report = [
            'sheets_processed' => 0,
            'entities_created' => [],
            'rows_inserted' => 0,
            'imported_at' => date('c'),
            'errors' => [],
        ];

        $usedEntityNames = [];
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $headerRow = is_array($rows[0] ?? null) ? $rows[0] : [];
            if (empty($headerRow)) {
                continue;
            }

            $columnMap = [];
            $usedColumns = [];
            foreach ($headerRow as $index => $label) {
                $base = $this->sanitizeIdentifier((string) $label);
                if ($base === '') {
                    $base = 'col_' . ((int) $index + 1);
                }
                $column = $base;
                $n = 2;
                while (isset($usedColumns[$column])) {
                    $column = $base . '_' . $n;
                    $n++;
                }
                $usedColumns[$column] = true;
                $columnMap[(int) $index] = $column;
            }

            $dataRows = [];
            for ($i = 1; $i < count($rows); $i++) {
                $row = is_array($rows[$i]) ? $rows[$i] : [];
                $mapped = [];
                $hasData = false;
                foreach ($columnMap as $index => $column) {
                    $value = $row[$index] ?? null;
                    if (is_string($value)) {
                        $value = trim($value);
                    }
                    if ($value !== null && $value !== '') {
                        $hasData = true;
                    }
                    $mapped[$column] = $value;
                }
                if ($hasData) {
                    $dataRows[] = $mapped;
                }
            }

            $entityName = $this->buildEntityName((string) $sheet->getTitle(), $usedEntityNames);
            $usedEntityNames[$entityName] = true;

            $fields = [
                ['name' => 'id', 'type' => 'int', 'primary' => true, 'source' => 'system'],
                ['name' => 'tenant_id', 'type' => 'int', 'source' => 'system'],
            ];

            foreach (array_values($columnMap) as $column) {
                $samples = [];
                foreach ($dataRows as $row) {
                    $value = $row[$column] ?? null;
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $samples[] = $value;
                    if (count($samples) >= 30) {
                        break;
                    }
                }
                $fields[] = [
                    'name' => $column,
                    'type' => $this->inferFieldType($samples),
                    'label' => ucwords(str_replace('_', ' ', $column)),
                    'required' => false,
                    'source' => 'import',
                ];
            }

            $entity = [
                'type' => 'entity',
                'name' => $entityName,
                'label' => ucwords(str_replace('_', ' ', $entityName)),
                'version' => '1.0',
                'table' => [
                    'name' => $entityName,
                    'primaryKey' => 'id',
                    'timestamps' => false,
                    'softDelete' => false,
                    'tenantScoped' => true,
                ],
                'fields' => $fields,
                'permissions' => [
                    'read' => ['admin'],
                    'create' => ['admin'],
                    'update' => ['admin'],
                    'delete' => ['admin'],
                ],
            ];

            $this->migrator->migrateEntity($entity, true);
            $repo = new BaseRepository($entity, null, $tenant);

            foreach ($dataRows as $dataRow) {
                $payload = [];
                foreach ($fields as $field) {
                    $name = (string) ($field['name'] ?? '');
                    if ($name === '' || $name === 'id' || $name === 'tenant_id') {
                        continue;
                    }
                    $payload[$name] = $this->normalizeCellValue($dataRow[$name] ?? null, (string) ($field['type'] ?? 'string'));
                }
                $repo->create($payload);
                $report['rows_inserted']++;
            }

            $report['sheets_processed']++;
            $report['entities_created'][] = $entityName;
        }

        return $report;
    }

    private function parseTenantId(string $tenantId): ?int
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '' || !is_numeric($tenantId)) {
            return null;
        }
        return (int) $tenantId;
    }

    private function buildEntityName(string $sheetTitle, array $used): string
    {
        $base = $this->sanitizeIdentifier($sheetTitle);
        if ($base === '') {
            $base = 'import_sheet';
        }

        $candidate = $base;
        $n = 2;
        while (isset($used[$candidate])) {
            $candidate = $base . '_' . $n;
            $n++;
        }
        return $candidate;
    }

    private function sanitizeIdentifier(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    private function inferFieldType(array $samples): string
    {
        if (empty($samples)) {
            return 'string';
        }

        $boolCount = 0;
        $intCount = 0;
        $decimalCount = 0;
        $dateCount = 0;
        $total = 0;

        foreach ($samples as $sample) {
            $value = is_string($sample) ? trim($sample) : $sample;
            if ($value === '' || $value === null) {
                continue;
            }
            $total++;
            $asString = strtolower((string) $value);

            if (in_array($asString, ['1', '0', 'true', 'false', 'si', 'no', 'yes', 'on', 'off'], true)) {
                $boolCount++;
                continue;
            }

            $numeric = str_replace(',', '.', (string) $value);
            if (is_numeric($numeric)) {
                if (preg_match('/^-?\d+$/', (string) $numeric) === 1) {
                    $intCount++;
                } else {
                    $decimalCount++;
                }
                continue;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) === 1) {
                $dateCount++;
            }
        }

        if ($total === 0) {
            return 'string';
        }
        if ($boolCount === $total) {
            return 'bool';
        }
        if ($dateCount === $total) {
            return 'date';
        }
        if ($intCount === $total) {
            return 'int';
        }
        if (($intCount + $decimalCount) === $total) {
            return 'decimal';
        }

        return 'string';
    }

    private function normalizeCellValue($value, string $type)
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || $value === null) {
            return null;
        }

        return match ($type) {
            'int' => is_numeric($value) ? (int) $value : null,
            'decimal' => is_numeric(str_replace(',', '.', (string) $value)) ? (float) str_replace(',', '.', (string) $value) : null,
            'bool' => in_array(strtolower((string) $value), ['1', 'true', 'si', 'yes', 'on'], true),
            default => (string) $value,
        };
    }
}
