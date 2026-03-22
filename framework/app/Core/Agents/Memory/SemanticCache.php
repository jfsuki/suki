<?php
declare(strict_types=1);
// app/Core/Agents/Memory/SemanticCache.php

namespace App\Core\Agents\Memory;

use App\Core\RuntimeSchemaPolicy;
use PDO;

/**
 * SemanticCache
 * Inspirado en AutoGen: Cortafuegos de LLM. Si el usuario envía exactamente el 
 * mismo prompt / estado / contexto en un marco de tiempo corto, se devuelve la 
 * respuesta anterior en 0ms y costando $0.00.
 */
class SemanticCache
{
    private PDO $db;
    private int $ttlSeconds;

    public function __construct(?PDO $db = null, int $ttlSeconds = 7200) // Default: 2 horas
    {
        if ($db === null) {
            $db = $this->initializeDefaultDb();
        }
        $this->db = $db;
        $this->ttlSeconds = max(60, $ttlSeconds);
        $this->ensureSchema();
    }

    private function initializeDefaultDb(): PDO
    {
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 6);
        $dbPath = $projectRoot . '/storage/meta/ops_semantic_cache.sqlite';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $pdo = new PDO("sqlite:$dbPath");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    /**
     * Genera una firma única del request para saber si ya se procesó.
     * Incluye user_id para evitar cross-session contamination.
     *
     * @param array<string,mixed> $contextFields
     */
    public function generateSignature(
        string $tenantId,
        string $mode,
        string $userText,
        array $contextFields = [],
        string $userId = ''
    ): string {
        // Normalización básica para ignorar espacios y mayúsculas
        $normalizedText = trim(preg_replace('/\s+/', ' ', strtolower($userText)) ?? '');

        // contextFields diferencia el estado del turno (active_task, pending_entity, etc.)
        ksort($contextFields);
        $contextString = json_encode($contextFields, JSON_UNESCAPED_UNICODE);

        // FIX: incluir user_id para aislar por usuario/sesión y evitar colisiones cross-session
        $payload = sprintf('%s|%s|%s|%s|%s', $tenantId, $userId, $mode, $normalizedText, $contextString);
        return hash('sha256', $payload);
    }

    /**
     * Busca si existe una respuesta válida cacheada.
     */
    public function get(string $signature): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT response_json, created_at FROM ops_semantic_cache WHERE signature = :signature ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':signature' => $signature]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $createdAt = strtotime((string) $row['created_at']);
        if ((time() - $createdAt) > $this->ttlSeconds) {
            // Expiró
            return null;
        }

        $decoded = json_decode((string) $row['response_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Guarda una nueva respuesta en el caché local.
     */
    public function set(string $signature, string $tenantId, string $mode, array $responseJson): void
    {
        $json = json_encode($responseJson, JSON_UNESCAPED_UNICODE);
        
        $stmt = $this->db->prepare(
            'INSERT INTO ops_semantic_cache (signature, tenant_id, mode, response_json, created_at) 
             VALUES (:signature, :tenant_id, :mode, :response_json, :created_at)'
        );
        
        $stmt->execute([
            ':signature' => $signature,
            ':tenant_id' => $tenantId,
            ':mode' => $mode,
            ':response_json' => $json,
            ':created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Purgar basura vieja asíncronamente (10% de probabilidad para no golpear performance)
        if (random_int(1, 100) <= 10) {
            $this->prune();
        }
    }

    /**
     * Limpia registros expirados.
     */
    public function prune(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - $this->ttlSeconds);
        $this->db->exec("DELETE FROM ops_semantic_cache WHERE created_at < '$cutoff'");
    }

    private function ensureSchema(): void
    {
        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'SemanticCache',
            function () {
                $this->db->exec(
                    'CREATE TABLE IF NOT EXISTS ops_semantic_cache (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        signature TEXT NOT NULL,
                        tenant_id TEXT NOT NULL,
                        mode TEXT NOT NULL,
                        response_json TEXT NOT NULL,
                        created_at TEXT NOT NULL
                    )'
                );
                $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_semcache_sig ON ops_semantic_cache (signature, created_at)');
                $this->db->exec('CREATE INDEX IF NOT EXISTS idx_ops_semcache_tenant ON ops_semantic_cache (tenant_id, created_at)');
            },
            ['ops_semantic_cache'],
            ['ops_semantic_cache' => ['idx_ops_semcache_sig', 'idx_ops_semcache_tenant']],
            [],
            'db/migrations/sqlite/20260322_001_semantic_cache.sql'
        );
    }
}
