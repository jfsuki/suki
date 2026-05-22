<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AppInterviewState
 *
 * Stores multi-turn app-creation interview state as JSON files,
 * one per tenant+session. Survives HTTP request boundaries.
 *
 * File path: project/storage/meta/app_interviews/{tenantId}_{sessionKey}.json
 */
final class AppInterviewState
{
    public const PHASE_INTRO           = 'intro';
    public const PHASE_GATHERING       = 'gathering';
    public const PHASE_SCHEMA_REVIEW   = 'schema_review';
    public const PHASE_SECURITY_REVIEW = 'security_review';
    public const PHASE_FINAL_CONFIRM   = 'final_confirm';
    public const PHASE_CREATING        = 'creating';
    public const PHASE_COMPLETE        = 'complete';

    private string $storageDir;
    private ?\PDO $pdo = null;
    private bool $mysqlEnabled = false;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? $this->resolveStorageDir();
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
        try {
            $this->pdo          = \App\Core\Database::connection();
            $this->mysqlEnabled = $this->tableExists();
        } catch (\Throwable $e) {
            $this->mysqlEnabled = false;
        }
    }

    public function load(string $tenantId, string $sessionId): array
    {
        if ($this->mysqlEnabled) {
            try {
                return $this->mysqlLoad($tenantId, $sessionId);
            } catch (\Throwable $ignored) {}
        }
        // JSON fallback
        $path = $this->path($tenantId, $sessionId);
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function save(string $tenantId, string $sessionId, array $state): void
    {
        $state['updated_at'] = date('Y-m-d H:i:s');
        if ($this->mysqlEnabled) {
            try {
                $this->mysqlSave($tenantId, $sessionId, $state);
                return;
            } catch (\Throwable $e) {
                error_log('[AppInterviewState] MySQL save failed, fallback to file: ' . $e->getMessage());
            }
        }
        file_put_contents($this->path($tenantId, $sessionId), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function exists(string $tenantId, string $sessionId): bool
    {
        if ($this->mysqlEnabled) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT 1 FROM builder_interview_state WHERE tenant_id = ? AND session_id = ? LIMIT 1'
                );
                $stmt->execute([$tenantId, $sessionId]);
                return (bool) $stmt->fetchColumn();
            } catch (\Throwable $ignored) {}
        }
        return file_exists($this->path($tenantId, $sessionId));
    }

    public function clear(string $tenantId, string $sessionId): void
    {
        if ($this->mysqlEnabled) {
            try {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM builder_interview_state WHERE tenant_id = ? AND session_id = ?'
                );
                $stmt->execute([$tenantId, $sessionId]);
            } catch (\Throwable $ignored) {}
        }
        // Also remove JSON file when present (migration cleanup)
        $path = $this->path($tenantId, $sessionId);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /** Create a fresh state for the initial phase */
    public function initialize(string $tenantId, string $sessionId, string $appId): array
    {
        $state = [
            'phase'            => self::PHASE_INTRO,
            'app_id'           => $appId,
            'tenant_id'        => $tenantId,
            'session_id'       => $sessionId,
            'business_name'    => '',
            'gathered_info'    => [],    // key-value of gathered facts
            'gathered_text'    => '',    // raw text accumulation
            'dynamic_steps'    => [],    // extra steps injected at runtime
            'schema_draft'     => null,
            'security_draft'   => null,
            'applied_mixins'   => [],
            'rounds'           => 0,
            'confirmed'        => false,
            'started_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ];
        $this->save($tenantId, $sessionId, $state);
        return $state;
    }

    public function advancePhase(string $tenantId, string $sessionId, string $newPhase): array
    {
        $state = $this->load($tenantId, $sessionId);
        $state['phase'] = $newPhase;
        $this->save($tenantId, $sessionId, $state);
        return $state;
    }

    public function appendGatheredText(string $tenantId, string $sessionId, string $userText): void
    {
        $state = $this->load($tenantId, $sessionId);
        $state['gathered_text'] = trim(($state['gathered_text'] ?? '') . "\n" . $userText);
        $state['rounds'] = ($state['rounds'] ?? 0) + 1;
        $this->save($tenantId, $sessionId, $state);
    }

    /** Detect if the user's message is a confirmation to proceed */
    public static function isConfirmation(string $text): bool
    {
        $text = strtolower(trim($text));
        $confirmPatterns = [
            'confirmo', 'confirmar', 'sí, está todo', 'si, esta todo',
            'adelante', 'proceder', 'crear', 'crear la app', 'todo bien',
            'está correcto', 'esta correcto', 'ok, crear', 'ok crear',
            'eso es todo', 'listo', 'ok, listo', 'perfecto, crear',
            'sí, crear', 'si, crear', 'confirmar creación',
            'todo en orden', 'se puede crear', 'procede',
        ];
        foreach ($confirmPatterns as $p) {
            if (str_contains($text, $p)) {
                return true;
            }
        }
        return false;
    }

    /** Detect if user wants to go back or add more info */
    public static function isAddingMore(string $text): bool
    {
        $text = strtolower($text);
        $morePatterns = [
            'también', 'tambien', 'además', 'ademas', 'y también', 'y tambien',
            'necesito también', 'quiero agregar', 'falta', 'olvidé', 'olvide',
            'una cosa más', 'una cosa mas', 'otro punto',
        ];
        foreach ($morePatterns as $p) {
            if (str_contains($text, $p)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // MySQL backend helpers
    // -------------------------------------------------------------------------

    private function tableExists(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'builder_interview_state'");
        return (bool) ($stmt ? $stmt->fetchColumn() : false);
    }

    private function mysqlLoad(string $tenantId, string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM builder_interview_state WHERE tenant_id = ? AND session_id = ? LIMIT 1'
        );
        $stmt->execute([$tenantId, $sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }
        foreach (['gathered_info', 'dynamic_steps', 'schema_draft', 'security_draft', 'applied_mixins'] as $col) {
            if (isset($row[$col]) && is_string($row[$col])) {
                $decoded    = json_decode($row[$col], true);
                $row[$col]  = is_array($decoded) ? $decoded : ($col === 'gathered_text' ? '' : null);
            }
        }
        $row['confirmed'] = (bool) ($row['confirmed'] ?? false);
        $row['rounds']    = (int) ($row['rounds'] ?? 0);
        return $row;
    }

    private function mysqlSave(string $tenantId, string $sessionId, array $state): void
    {
        $jsonCols = ['gathered_info', 'dynamic_steps', 'schema_draft', 'security_draft', 'applied_mixins'];

        $encode = static function ($v): ?string {
            if ($v === null) return null;
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        };

        $stmt = $this->pdo->prepare(
            'INSERT INTO builder_interview_state
                (tenant_id, session_id, app_id, phase, business_name,
                 gathered_info, gathered_text, dynamic_steps,
                 schema_draft, security_draft, applied_mixins,
                 rounds, confirmed, developer_instructions, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                app_id                  = VALUES(app_id),
                phase                   = VALUES(phase),
                business_name           = VALUES(business_name),
                gathered_info           = VALUES(gathered_info),
                gathered_text           = VALUES(gathered_text),
                dynamic_steps           = VALUES(dynamic_steps),
                schema_draft            = VALUES(schema_draft),
                security_draft          = VALUES(security_draft),
                applied_mixins          = VALUES(applied_mixins),
                rounds                  = VALUES(rounds),
                confirmed               = VALUES(confirmed),
                developer_instructions  = VALUES(developer_instructions),
                updated_at              = NOW()'
        );

        $stmt->execute([
            $tenantId,
            $sessionId,
            (string) ($state['app_id']    ?? ''),
            (string) ($state['phase']     ?? self::PHASE_INTRO),
            (string) ($state['business_name'] ?? ''),
            $encode($state['gathered_info'] ?? []),
            (string) ($state['gathered_text'] ?? ''),
            $encode($state['dynamic_steps'] ?? []),
            $encode($state['schema_draft']  ?? null),
            $encode($state['security_draft'] ?? null),
            $encode($state['applied_mixins'] ?? []),
            (int) ($state['rounds']    ?? 0),
            (int) ($state['confirmed'] ?? 0),
            isset($state['developer_instructions']) ? (string) $state['developer_instructions'] : null,
        ]);
    }

    private function path(string $tenantId, string $sessionId): string
    {
        $safe  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tenantId);
        $safe2 = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId);
        return $this->storageDir . "/{$safe}_{$safe2}.json";
    }

    private function resolveStorageDir(): string
    {
        // __DIR__ = .../framework/app/Core → dirname(,3) = repo root
        return dirname(__DIR__, 3) . '/project/storage/meta/app_interviews';
    }
}
