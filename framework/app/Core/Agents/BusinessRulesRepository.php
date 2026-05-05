<?php
declare(strict_types=1);

namespace App\Core\Agents;

use PDO;
use App\Core\Database;
use App\Core\TableNamespace;
use App\Core\RuntimeSchemaPolicy;

/**
 * BusinessRulesRepository
 *
 * Fuente de verdad para reglas de negocio multi-scope.
 *
 * Scopes:
 *   'universal' → aplica a todos los tenants/apps  (Torre)
 *   'app'       → aplica a todas las empresas de una app  (Builder)
 *   'tenant'    → aplica solo a esa empresa  (chat/training del tenant)
 *
 * Prioridad en evaluación: tenant > app > universal
 * Cuando el mismo id existe en varios scopes, el más específico prevalece.
 *
 * tenant_id y app_id usan '' en lugar de NULL para respetar el UNIQUE KEY de MySQL.
 */
final class BusinessRulesRepository
{
    private const TABLE = 'business_rules';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            self::class,
            fn() => $this->ensureSchema(),
            [self::TABLE],
            [self::TABLE => ['uk_br_identity', 'idx_br_lookup', 'idx_br_event']]
        );
    }

    // ─── Lectura ────────────────────────────────────────────────────────────────

    /**
     * Carga todas las reglas activas para un contexto dado.
     * Orden: universal → app → tenant (el más específico sobreescribe por id).
     *
     * @return list<array{id:string, scope:string, event_type:string, rule_type:string, params:array, description:string}>
     */
    public function loadForContext(string $tenantId = '', string $appId = ''): array
    {
        try {
            $table = TableNamespace::resolve(self::TABLE);
            $stmt  = $this->db->prepare(
                "SELECT id, scope, event_type, rule_type, params_json, description
                 FROM {$table}
                 WHERE enabled = 1
                   AND (
                         scope = 'universal'
                     OR (scope = 'app'    AND app_id    = ?)
                     OR (scope = 'tenant' AND tenant_id = ?)
                   )
                 ORDER BY FIELD(scope, 'universal', 'app', 'tenant')"
            );
            $stmt->execute([$appId, $tenantId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return $this->loadFromSeed();
        }

        if (empty($rows)) {
            // Tabla vacía → sembrar universales y volver a intentar
            $this->seedFromJsonIfEmpty();
            return $this->loadForContext($tenantId, $appId);
        }

        // Más específico sobreescribe al mismo id de scope más general
        $byId = [];
        foreach ($rows as $row) {
            $params = is_string($row['params_json'] ?? null)
                ? (json_decode($row['params_json'], true) ?? [])
                : [];
            $byId[(string) $row['id']] = [
                'id'          => (string) $row['id'],
                'scope'       => (string) $row['scope'],
                'event_type'  => (string) $row['event_type'],
                'rule_type'   => (string) $row['rule_type'],
                'params'      => is_array($params) ? $params : [],
                'description' => (string) ($row['description'] ?? ''),
            ];
        }
        return array_values($byId);
    }

    // ─── Escritura por origen ───────────────────────────────────────────────────

    /**
     * Torre → regla universal (aplica a todos).
     */
    public function saveUniversal(array $rule): bool
    {
        return $this->upsert(array_merge($rule, [
            'scope'     => 'universal',
            'tenant_id' => '',
            'app_id'    => '',
        ]));
    }

    /**
     * Builder → regla de app (aplica a todas las empresas que usen esa app).
     */
    public function saveForApp(string $appId, array $rule): bool
    {
        return $this->upsert(array_merge($rule, [
            'scope'     => 'app',
            'tenant_id' => '',
            'app_id'    => $appId,
        ]));
    }

    /**
     * Chat/training del tenant → regla de empresa (solo esa empresa).
     */
    public function saveForTenant(string $tenantId, array $rule): bool
    {
        return $this->upsert(array_merge($rule, [
            'scope'     => 'tenant',
            'tenant_id' => $tenantId,
            'app_id'    => '',
        ]));
    }

    /**
     * Desactiva una regla sin borrarla (preserva auditoría).
     */
    public function disable(string $ruleId, string $scope, string $tenantId = '', string $appId = ''): bool
    {
        try {
            $table = TableNamespace::resolve(self::TABLE);
            $stmt  = $this->db->prepare(
                "UPDATE {$table} SET enabled = 0
                 WHERE id = ? AND scope = ? AND tenant_id = ? AND app_id = ?"
            );
            return $stmt->execute([$ruleId, $scope, $tenantId, $appId]);
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Seed ───────────────────────────────────────────────────────────────────

    /**
     * Siembra reglas universales desde JSON si la tabla está vacía.
     * Se llama automáticamente en loadForContext() cuando la tabla no tiene datos.
     */
    public function seedFromJsonIfEmpty(): void
    {
        try {
            $table = TableNamespace::resolve(self::TABLE);
            $count = (int) $this->db
                ->query("SELECT COUNT(*) FROM {$table} WHERE scope = 'universal'")
                ->fetchColumn();
            if ($count > 0) {
                return;
            }
            foreach ($this->loadFromSeed() as $rule) {
                $this->saveUniversal($rule);
            }
        } catch (\Throwable) {
            // Silencioso — si no hay DB el fallback a JSON ya está activo
        }
    }

    // ─── Privado ────────────────────────────────────────────────────────────────

    private function upsert(array $rule): bool
    {
        try {
            $table      = TableNamespace::resolve(self::TABLE);
            $paramsJson = json_encode($rule['params'] ?? [], JSON_UNESCAPED_UNICODE);
            $stmt       = $this->db->prepare(
                "INSERT INTO {$table}
                   (id, scope, tenant_id, app_id, event_type, rule_type, params_json, enabled, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                   event_type  = VALUES(event_type),
                   rule_type   = VALUES(rule_type),
                   params_json = VALUES(params_json),
                   enabled     = 1,
                   description = VALUES(description)"
            );
            return $stmt->execute([
                $rule['id']          ?? '',
                $rule['scope']       ?? 'universal',
                $rule['tenant_id']   ?? '',
                $rule['app_id']      ?? '',
                $rule['event_type']  ?? '',
                $rule['rule_type']   ?? 'legacy',
                $paramsJson,
                $rule['description'] ?? '',
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fallback de emergencia: lee el JSON seed cuando la DB no está disponible.
     */
    private function loadFromSeed(): array
    {
        $path = dirname(__DIR__, 3) . '/data/business_rules_seed.json';
        if (!file_exists($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $rules   = is_array($decoded['rules'] ?? null) ? (array) $decoded['rules'] : [];
        // Normalizar estructura para que sea idéntica a lo que devuelve loadForContext()
        return array_map(static function (array $r): array {
            return [
                'id'          => (string) ($r['id'] ?? ''),
                'scope'       => 'universal',
                'event_type'  => (string) ($r['event_type'] ?? ''),
                'rule_type'   => (string) ($r['rule_type'] ?? $r['id'] ?? 'legacy'),
                'params'      => is_array($r['params'] ?? null) ? (array) $r['params'] : [],
                'description' => (string) ($r['description'] ?? ''),
            ];
        }, $rules);
    }

    private function ensureSchema(): void
    {
        $table = TableNamespace::resolve(self::TABLE);
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS {$table} (
                rule_pk     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id          VARCHAR(64)     NOT NULL DEFAULT '',
                scope       ENUM('universal','app','tenant') NOT NULL DEFAULT 'universal',
                tenant_id   VARCHAR(64)     NOT NULL DEFAULT '',
                app_id      VARCHAR(64)     NOT NULL DEFAULT '',
                event_type  VARCHAR(64)     NOT NULL,
                rule_type   VARCHAR(64)     NOT NULL DEFAULT 'legacy',
                params_json JSON            DEFAULT NULL,
                enabled     TINYINT(1)      NOT NULL DEFAULT 1,
                description VARCHAR(255)    DEFAULT NULL,
                created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (rule_pk),
                UNIQUE  KEY uk_br_identity (id, scope, tenant_id, app_id),
                INDEX idx_br_lookup (scope, tenant_id, app_id, enabled),
                INDEX idx_br_event  (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
