<?php
// app/Core/DashboardEngine.php

namespace App\Core;

use App\Core\Contracts\ContractRepository;
use RuntimeException;

final class DashboardEngine
{
    private ContractRepository $contracts;
    private EntityRegistry $registry;
    private SummaryCalculator $summary;
    private ?int $tenantId;

    public function __construct(
        ?ContractRepository $contracts = null,
        ?EntityRegistry $registry = null,
        ?SummaryCalculator $summary = null,
        ?int $tenantId = null
    ) {
        $this->contracts = $contracts ?? new ContractRepository();
        $this->registry = $registry ?? new EntityRegistry();
        $this->summary = $summary ?? new SummaryCalculator();
        $this->tenantId = $tenantId ?? TenantContext::getTenantId();
    }

    public function build(string $formKey, string $dashboardKey, ?string $entityName = null): array
    {
        $form = $this->contracts->getForm($formKey);
        $dashboards = $form['dashboards'] ?? [];
        if (!is_array($dashboards) || empty($dashboards)) {
            throw new RuntimeException('Formulario sin dashboards.');
        }

        $dashboard = $this->findDashboard($dashboards, $dashboardKey);
        if (!$dashboard) {
            throw new RuntimeException('Dashboard no encontrado.');
        }

        $entity = $entityName ?: ($form['entity'] ?? '');
        if ($entity === '') {
            throw new RuntimeException('Entidad requerida para dashboard.');
        }

        $entityContract = $this->registry->get($entity);
        $summaryValues = $this->calculateSummaryGlobal($form, $entityContract);

        $widgets = [];
        foreach (($dashboard['widgets'] ?? []) as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $widgets[] = $this->buildWidget($widget, $form, $entityContract, $summaryValues);
        }

        return [
            'dashboard' => [
                'id' => $dashboard['id'] ?? null,
                'name' => $dashboard['name'] ?? null,
            ],
            'widgets' => $widgets,
        ];
    }

    private function findDashboard(array $dashboards, string $key): ?array
    {
        foreach ($dashboards as $dash) {
            if (!is_array($dash)) {
                continue;
            }
            if (($dash['id'] ?? '') === $key || ($dash['name'] ?? '') === $key) {
                return $dash;
            }
        }
        return $dashboards[0] ?? null;
    }

    private function buildWidget(array $widget, array $form, array $entity, array $summaryValues): array
    {
        $type = strtolower((string) ($widget['type'] ?? 'kpi'));
        $label = (string) ($widget['label'] ?? $type);

        if ($type === 'kpi') {
            $summaryName = $widget['source']['summary'] ?? '';
            $value = $summaryValues[$summaryName] ?? 0;
            return [
                'type' => 'kpi',
                'label' => $label,
                'value' => $value,
            ];
        }

        if ($type === 'chart') {
            $gridName = $widget['source']['grid'] ?? '';
            $field = $widget['source']['field'] ?? '';
            $series = $this->buildChartSeries($entity, $gridName, $field);
            return [
                'type' => 'chart',
                'label' => $label,
                'series' => $series,
            ];
        }

        return [
            'type' => $type,
            'label' => $label,
        ];
    }

    private function calculateSummaryGlobal(array $form, array $entity): array
    {
        $summaryConfig = $form['summary'] ?? [];
        if (!is_array($summaryConfig) || empty($summaryConfig)) {
            return [];
        }

        $values = [];
        foreach ($summaryConfig as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = strtolower((string) ($item['type'] ?? 'sum'));
            if ($type === 'sum') {
                $source = $item['source'] ?? [];
                $grid = $source['grid'] ?? null;
                $field = $source['field'] ?? null;
                if ($grid && $field) {
                    $values[$name] = $this->sumGridColumn($entity, (string) $grid, (string) $field);
                } elseif ($field) {
                    $values[$name] = $this->sumMainColumn($entity, (string) $field);
                }
                continue;
            }

            if ($type === 'formula') {
                $expression = (string) ($item['expression'] ?? '');
                $engine = new ExpressionEngine();
                $values[$name] = $engine->evaluate($expression, $values);
            }
        }

        return $values;
    }

    private function sumMainColumn(array $entity, string $column): float
    {
        $logicalTable = (string) ($entity['table']['name'] ?? '');
        if ($logicalTable === '' || $column === '') {
            return 0.0;
        }
        $table = $this->sanitizeIdentifier(TableNamespace::resolve($logicalTable));
        $column = $this->sanitizeIdentifier($column);
        $sql = "SELECT SUM({$column}) as total FROM {$table}";
        $params = [];
        if (!empty($entity['table']['tenantScoped']) && $this->tenantId !== null) {
            $sql .= " WHERE tenant_id = :tenant_id";
            $params['tenant_id'] = $this->tenantId;
        }
        return $this->fetchSum($sql, $params);
    }

    private function sumGridColumn(array $entity, string $gridName, string $column): float
    {
        $grid = $this->findGrid($entity, $gridName);
        if (!$grid) {
            return 0.0;
        }
        $gridLogicalTable = (string) ($grid['table'] ?? (($entity['table']['name'] ?? '') . '__' . $gridName));
        $table = $this->sanitizeIdentifier(TableNamespace::resolve($gridLogicalTable));
        $column = $this->sanitizeIdentifier($column);
        $sql = "SELECT SUM({$column}) as total FROM {$table}";
        $params = [];
        if (!empty($entity['table']['tenantScoped']) && $this->tenantId !== null) {
            $sql .= " WHERE tenant_id = :tenant_id";
            $params['tenant_id'] = $this->tenantId;
        }
        return $this->fetchSum($sql, $params);
    }

    private function buildChartSeries(array $entity, string $gridName, string $field): array
    {
        $grid = $this->findGrid($entity, $gridName);
        if (!$grid || $field === '') {
            return [];
        }
        $gridLogicalTable = (string) ($grid['table'] ?? (($entity['table']['name'] ?? '') . '__' . $gridName));
        $table = $this->sanitizeIdentifier(TableNamespace::resolve($gridLogicalTable));
        $field = $this->sanitizeIdentifier($field);

        $sql = "SELECT {$field} as val FROM {$table}";
        $params = [];
        if (!empty($entity['table']['tenantScoped']) && $this->tenantId !== null) {
            $sql .= " WHERE tenant_id = :tenant_id";
            $params['tenant_id'] = $this->tenantId;
        }
        $sql .= " LIMIT 200";

        $db = Database::connection();
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $series = [];
        foreach ($rows as $row) {
            $val = $row['val'] ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            $key = is_numeric($val) ? (string) $val : (string) $val;
            $series[$key] = ($series[$key] ?? 0) + 1;
        }

        $result = [];
        foreach ($series as $label => $count) {
            $result[] = ['label' => $label, 'value' => $count];
        }

        return $result;
    }

    private function fetchSum(string $sql, array $params): float
    {
        $db = Database::connection();
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        return isset($row['total']) ? (float) $row['total'] : 0.0;
    }

    private function findGrid(array $entity, string $gridName): ?array
    {
        foreach (($entity['grids'] ?? []) as $grid) {
            if (($grid['name'] ?? '') === $gridName) {
                return $grid;
            }
        }
        return null;
    }

    private function sanitizeIdentifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException('Identificador invalido.' );
        }
        return $name;
    }
}
