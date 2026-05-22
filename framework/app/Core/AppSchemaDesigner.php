<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AppSchemaDesigner
 *
 * NOT a template library. This class:
 *   1. Provides the JSON schema format spec so the LLM knows what to output
 *   2. Validates and sanitizes LLM-generated schemas (security enforcement only)
 *   3. Renders schema for human review
 *
 * The LLM designs the schema from requirements. PHP enforces the rules.
 *
 * Security contracts enforced on every entity (non-negotiable):
 *   - tenant_id: multi-tenant isolation
 *   - created_at / updated_at: audit trail
 *   - deleted_at: soft delete, never hard-delete
 */
final class AppSchemaDesigner
{
    private const REQUIRED_COLUMNS = [
        ['name' => 'id',                  'type' => 'INTEGER',      'pk' => true,  'nullable' => false],
        ['name' => 'tenant_id',           'type' => 'VARCHAR(100)', 'nullable' => false, 'index' => true,
         'note' => 'Multi-tenant isolation — MANDATORY, never query without this'],
        ['name' => 'created_at',          'type' => 'DATETIME',     'nullable' => false, 'default' => 'CURRENT_TIMESTAMP'],
        ['name' => 'updated_at',          'type' => 'DATETIME',     'nullable' => false, 'default' => 'CURRENT_TIMESTAMP'],
        ['name' => 'deleted_at',          'type' => 'DATETIME',     'nullable' => true,
         'note' => 'Soft delete — set this instead of DELETE'],
        ['name' => 'created_by_user_id',  'type' => 'VARCHAR(100)', 'nullable' => true,
         'note' => 'User who created this record — auto-injected by BaseRepository'],
        ['name' => 'updated_by_user_id',  'type' => 'VARCHAR(100)', 'nullable' => true,
         'note' => 'User who last modified this record — auto-injected by BaseRepository'],
        ['name' => 'deleted_by_user_id',  'type' => 'VARCHAR(100)', 'nullable' => true,
         'note' => 'User who soft-deleted this record — auto-injected by BaseRepository'],
    ];

    private const BASE_ROLES = [
        ['role' => 'admin',    'label' => 'Administrador', 'description' => 'Acceso total — solo el dueño del negocio'],
        ['role' => 'operator', 'label' => 'Operador',      'description' => 'Opera el sistema, no configura ni elimina'],
        ['role' => 'viewer',   'label' => 'Solo consulta', 'description' => 'Lee información, no puede modificar'],
    ];

    /**
     * Returns the schema format specification string so the LLM knows
     * exactly what JSON structure to output when designing the schema.
     */
    public function getSchemaFormatSpec(): string
    {
        return <<<'JSON'
Diseña el schema de base de datos como un objeto JSON con esta estructura exacta:

{
  "entities": [
    {
      "table": "nombre_tabla_snake_case",
      "label": "Nombre legible para el usuario",
      "columns": [
        {
          "name": "nombre_columna",
          "type": "VARCHAR(200)|TEXT|INTEGER|DECIMAL(12,2)|DATE|DATETIME|TINYINT(1)|JSON",
          "nullable": true|false,
          "default": "valor_default_opcional",
          "fk": "tabla_referenciada.id",
          "note": "descripcion breve opcional",
          "sensitive": true
        }
      ],
      "formulas": [
        {
          "name": "nombre_columna_calculada",
          "label": "Etiqueta visible al usuario",
          "expression": "{campo_a} * {campo_b} * (1 - {descuento} / 100)",
          "type": "decimal"
        }
      ]
    }
  ],
  "custom_roles": [
    {"role": "slug_rol", "label": "Nombre del Rol", "description": "Qué puede hacer"}
  ]
}

REGLAS DE COLUMNAS:
- NO incluyas id, tenant_id, created_at, updated_at, deleted_at — se agregan automáticamente
- Cada tabla debe representar una entidad real del negocio
- Marca sensitive:true en campos con datos personales, médicos, financieros o privados
- Incluye FK (foreign key) cuando una tabla referencia otra
- Nombra tablas en snake_case plural (ej: historias_clinicas, no HistoriaClinica)
- Sé específico: no "datos" sino "historial_medico", "recetas_medicamentos", etc.

REGLAS DE FÓRMULAS (formulas[]):
- Las fórmulas son columnas calculadas al leer datos — NO se almacenan en DB
- Usa {nombre_campo} para referenciar valores del mismo registro
- Operadores: + - * / %  y comparaciones: > < >= <= == !=
- Funciones: ROUND(x,n) ABS(x) IF(cond,then,else) MAX(a,b) MIN(a,b) SUM(a,b)
             CONCAT(a,b) UPPER(s) LOWER(s) COALESCE(a,b) FLOOR(x) CEIL(x)
- Ejemplo: ROUND({cantidad} * {precio_unitario} * (1 - {descuento}/100), 2)
- Solo define fórmulas cuando el negocio necesite calcular valores derivados
JSON;
    }

    /**
     * Validates and sanitizes an LLM-generated schema.
     * Enforces security rules: injects required base columns, validates structure.
     *
     * @return array ['valid' => bool, 'schema' => array, 'errors' => string[]]
     */
    public function validateAndSanitize(array $rawSchema): array
    {
        $errors   = [];
        $entities = is_array($rawSchema['entities'] ?? null) ? (array) $rawSchema['entities'] : [];

        if (empty($entities)) {
            return ['valid' => false, 'schema' => [], 'errors' => ['El schema no tiene entidades']];
        }

        $sanitized = [];
        foreach ($entities as $entity) {
            if (!is_array($entity) || empty($entity['table'])) {
                $errors[] = 'Entidad sin nombre de tabla';
                continue;
            }

            $tableName = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $entity['table']));
            $cols = is_array($entity['columns'] ?? null) ? (array) $entity['columns'] : [];

            // Remove any manually included base columns (LLM sometimes includes them)
            $baseNames = array_column(self::REQUIRED_COLUMNS, 'name');
            $cols = array_values(array_filter($cols, fn($c) => !in_array($c['name'] ?? '', $baseNames, true)));

            // Validate business columns
            foreach ($cols as $col) {
                if (empty($col['name'])) {
                    $errors[] = "Columna sin nombre en tabla '{$tableName}'";
                }
            }

            // Prepend required base columns
            $finalCols = array_merge(self::REQUIRED_COLUMNS, $cols);

            $sanitized[] = [
                'table'   => $tableName,
                'label'   => (string) ($entity['label'] ?? $tableName),
                'columns' => $finalCols,
            ];
        }

        // Always add audit log
        $sanitized[] = $this->auditLogEntity();

        // Merge custom roles with base roles (custom come first)
        $customRoles = is_array($rawSchema['custom_roles'] ?? null) ? (array) $rawSchema['custom_roles'] : [];
        $roles = array_merge($customRoles, self::BASE_ROLES);

        $schema = [
            'entities'        => $sanitized,
            'roles'           => $roles,
            'tenant_isolated' => true,
            'soft_delete'     => true,
            'designed_at'     => date('Y-m-d H:i:s'),
        ];

        return ['valid' => empty($errors), 'schema' => $schema, 'errors' => $errors];
    }

    /**
     * Renders a human-readable schema review for the user to approve.
     */
    public function renderSchemaReview(array $schema): string
    {
        $lines = ["## Diseño de Base de Datos\n"];
        $lines[] = "> Todas las tablas tienen `tenant_id` (aislamiento multi-empresa), auditoría de cambios y borrado seguro sin perder datos.\n";

        foreach ($schema['entities'] as $entity) {
            if ($entity['table'] === 'audit_log') {
                continue; // Skip technical table from user-facing review
            }
            $lines[] = "### 📋 {$entity['label']} (`{$entity['table']}`)";
            $skipCols = ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at'];
            foreach ($entity['columns'] as $col) {
                if (in_array($col['name'], $skipCols, true)) {
                    continue;
                }
                $req  = ($col['nullable'] ?? true) ? '' : ' **obligatorio**';
                $note = isset($col['note']) ? " — _{$col['note']}_" : '';
                $sens = ($col['sensitive'] ?? false) ? ' 🔒' : '';
                $lines[] = "  - `{$col['name']}` ({$col['type']}){$req}{$sens}{$note}";
            }
            $lines[] = '';
        }

        $lines[] = "## Roles de Acceso";
        foreach ($schema['roles'] as $role) {
            $lines[] = "- **{$role['label']}**: {$role['description']}";
        }

        $lines[] = "\n🔒 Los campos con 🔒 contienen datos sensibles — solo Administrador y Operador tienen acceso.\n";
        $lines[] = "¿Esto representa bien tu negocio? Si necesitas ajustar alguna tabla o campo, dímelo. Cuando estés de acuerdo escribe **'confirmo el diseño'**.";

        return implode("\n", $lines);
    }

    /**
     * Security review render — always generated, not hardcoded per sector.
     */
    public function renderSecurityReview(array $schema): string
    {
        $sensitive = [];
        foreach ($schema['entities'] as $entity) {
            foreach ($entity['columns'] as $col) {
                if ($col['sensitive'] ?? false) {
                    $sensitive[] = "`{$entity['table']}.{$col['name']}`";
                }
            }
        }

        $lines = ["## Protecciones de Seguridad Aplicadas\n"];
        $lines[] = "- ✅ **Multi-empresa**: cada registro tiene `tenant_id` — una empresa nunca ve datos de otra";
        $lines[] = "- ✅ **Borrado seguro**: los datos se marcan como eliminados, nunca se borran definitivamente";
        $lines[] = "- ✅ **Auditoría completa**: quién creó, modificó o eliminó cada registro, cuándo y desde qué IP";
        $lines[] = "- ✅ **Contraseñas**: bcrypt hash — nunca almacenadas en texto plano";
        $lines[] = "- ✅ **Sesiones**: token único por usuario, expira automáticamente";

        if ($sensitive) {
            $lines[] = "\n**Campos sensibles detectados** (solo Admin y Operador):";
            foreach ($sensitive as $f) {
                $lines[] = "  - {$f}";
            }
        }

        $lines[] = "\n¿Necesitas alguna restricción adicional? Por ejemplo:";
        $lines[] = "- ¿Hay empleados que no deben ver información financiera?";
        $lines[] = "- ¿Datos que solo el profesional responsable puede ver?";
        $lines[] = "- ¿Necesitas notificación cuando alguien elimina un registro importante?\n";
        $lines[] = "Cuando estés conforme, escribe **'seguridad confirmada'**.";

        return implode("\n", $lines);
    }

    /**
     * Converts a validated schema (from validateAndSanitize) to EntityMigrator-compatible
     * entity definitions so DB tables are actually created.
     *
     * @return array<int,array> One entry per entity (audit_log excluded — managed separately)
     */
    /**
     * @param string $appId  When provided, physical table names are prefixed "app_{appId}__"
     *                       so all tenants installing the same app share the same MySQL table.
     */
    public function toEntityMigratorFormat(array $schema, string $appId = ''): array
    {
        // Columns managed by BaseRepository or table flags — never added manually
        $skip = ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at',
                 'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id'];

        // Canonical prefix: app_vet_clinic__pacientes (one physical table for all tenants)
        $prefix = $appId !== ''
            ? 'app_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($appId)) . '__'
            : '';

        $entities = [];
        foreach ($schema['entities'] ?? [] as $entity) {
            $table = (string) ($entity['table'] ?? '');
            if ($table === '' || $table === 'audit_log') {
                continue;
            }
            $fields = [];
            foreach ((array) ($entity['columns'] ?? []) as $col) {
                $colName = (string) ($col['name'] ?? '');
                if ($colName === '' || in_array($colName, $skip, true)) {
                    continue;
                }
                $fields[] = $this->convertColumnToField($col);
            }

            // User-tracking fields: declared in entity so BaseRepository knows to auto-inject
            $fields[] = ['name' => 'created_by_user_id', 'type' => 'string', 'length' => 100, 'nullable' => true, 'required' => false];
            $fields[] = ['name' => 'updated_by_user_id',  'type' => 'string', 'length' => 100, 'nullable' => true, 'required' => false];
            $fields[] = ['name' => 'deleted_by_user_id',  'type' => 'string', 'length' => 100, 'nullable' => true, 'required' => false];

            $physicalName = $prefix . $table;
            $entities[] = [
                'name'   => $physicalName,
                'label'  => (string) ($entity['label'] ?? $table),
                'table'  => [
                    'name'         => $physicalName,
                    'primaryKey'   => 'id',
                    'timestamps'   => true,
                    'softDelete'   => true,
                    'tenantScoped' => true,
                ],
                'fields' => $fields,
            ];
        }
        return $entities;
    }

    private function convertColumnToField(array $col): array
    {
        $sqlType = strtoupper(trim((string) ($col['type'] ?? 'VARCHAR(255)')));
        $nullable = (bool) ($col['nullable'] ?? true);
        $field = [
            'name'     => $col['name'],
            'nullable' => $nullable,
            'required' => !$nullable,
        ];
        if (!empty($col['note'])) {
            $field['label'] = $col['note'];
        }

        if (str_starts_with($sqlType, 'VARCHAR')) {
            preg_match('/VARCHAR\((\d+)\)/i', $sqlType, $m);
            $field['type'] = 'string';
            $field['length'] = (int) ($m[1] ?? 255);
        } elseif ($sqlType === 'TEXT') {
            $field['type'] = 'text';
        } elseif (in_array($sqlType, ['INT', 'INTEGER'], true)) {
            $field['type'] = 'integer';
        } elseif ($sqlType === 'BIGINT') {
            $field['type'] = 'bigint';
        } elseif (str_starts_with($sqlType, 'DECIMAL')) {
            preg_match('/DECIMAL\((\d+),\s*(\d+)\)/i', $sqlType, $m);
            $field['type']      = 'decimal';
            $field['precision'] = (int) ($m[1] ?? 12);
            $field['scale']     = (int) ($m[2] ?? 2);
        } elseif ($sqlType === 'DATE') {
            $field['type'] = 'date';
        } elseif ($sqlType === 'DATETIME') {
            $field['type'] = 'datetime';
        } elseif (in_array($sqlType, ['TINYINT(1)', 'BOOLEAN', 'BOOL'], true)) {
            $field['type'] = 'boolean';
        } elseif ($sqlType === 'JSON') {
            $field['type'] = 'text';
        } else {
            $field['type']   = 'string';
            $field['length'] = 255;
        }

        if (!empty($col['fk'])) {
            $field['fk'] = $col['fk'];
        }
        if ($col['unique'] ?? false) {
            $field['unique'] = true;
        }

        return $field;
    }

    private function auditLogEntity(): array
    {
        return [
            'table' => 'audit_log',
            'label' => 'Registro de Auditoría (Automático)',
            'columns' => array_merge(self::REQUIRED_COLUMNS, [
                ['name' => 'user_id',        'type' => 'INTEGER',      'nullable' => true],
                ['name' => 'user_name',      'type' => 'VARCHAR(200)', 'nullable' => true],
                ['name' => 'accion',         'type' => 'VARCHAR(50)',  'nullable' => false,
                 'note' => 'create|update|delete|login|view'],
                ['name' => 'tabla_afectada', 'type' => 'VARCHAR(100)', 'nullable' => true],
                ['name' => 'registro_id',    'type' => 'INTEGER',      'nullable' => true],
                ['name' => 'datos_antes',    'type' => 'JSON',         'nullable' => true],
                ['name' => 'datos_despues',  'type' => 'JSON',         'nullable' => true],
                ['name' => 'ip_address',     'type' => 'VARCHAR(45)',  'nullable' => true],
            ]),
        ];
    }
}
