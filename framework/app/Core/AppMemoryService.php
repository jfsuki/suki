<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AppMemoryService
 *
 * Persists complete app context after creation and loads it in any future
 * session — so the agent never starts blind when working on an existing app.
 *
 * Stored at: project/storage/meta/app_memory/{tenantId}_{appId}.json
 *
 * Saved after creation:
 *   - schema (entities, roles)
 *   - system_prompt (custom for this business)
 *   - requirements (full gathered text)
 *   - config (mixins, business_name, version)
 *   - changelog (what changed in each update)
 *
 * Used by:
 *   - CreateAppSkill: load context when tenant asks to update/view their app
 *   - ChatAgent: inject developer context into system prompt for active sessions
 *   - AppFeedbackService: attach improvement suggestions to existing app memory
 */
final class AppMemoryService
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? $this->resolveStorageDir();
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
    }

    /**
     * Saves the full app context after creation or update.
     */
    public function save(
        string $tenantId,
        string $appId,
        array  $schema,
        string $systemPrompt,
        string $requirements,
        array  $config = [],
        string $version = '1.0.0'
    ): void {
        $existing = $this->load($tenantId, $appId);

        $changelog = $existing['changelog'] ?? [];
        $changelog[] = [
            'version'    => $version,
            'summary'    => empty($existing) ? 'Creación inicial' : 'Actualización',
            'changed_at' => date('Y-m-d H:i:s'),
        ];

        $record = [
            'app_id'        => $appId,
            'tenant_id'     => $tenantId,
            'version'       => $version,
            'schema'        => $schema,
            'system_prompt' => $systemPrompt,
            'requirements'  => $requirements,
            'config'        => $config,
            'changelog'     => $changelog,
            'updated_at'    => date('Y-m-d H:i:s'),
            'created_at'    => $existing['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        file_put_contents(
            $this->path($tenantId, $appId),
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Loads the full app context. Returns [] if not found.
     */
    public function load(string $tenantId, string $appId): array
    {
        $path = $this->path($tenantId, $appId);
        if (!file_exists($path)) {
            return [];
        }
        $raw  = file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    public function exists(string $tenantId, string $appId): bool
    {
        return file_exists($this->path($tenantId, $appId));
    }

    /**
     * Returns all apps installed for a tenant (for listing in chat).
     */
    public function listApps(string $tenantId): array
    {
        $safe  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tenantId);
        $files = glob($this->storageDir . "/{$safe}_*.json") ?: [];
        $apps  = [];
        foreach ($files as $file) {
            $raw  = file_get_contents($file);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data) && ($data['tenant_id'] ?? '') === $tenantId) {
                $apps[] = [
                    'app_id'        => $data['app_id']    ?? '',
                    'version'       => $data['version']   ?? '?',
                    'requirements'  => mb_substr((string) ($data['requirements'] ?? ''), 0, 120),
                    'updated_at'    => $data['updated_at'] ?? '',
                ];
            }
        }
        return $apps;
    }

    /**
     * Builds the developer context string to inject into the LLM system prompt
     * when the user is working on an already-created app.
     */
    public function buildDeveloperContext(string $tenantId, string $appId): string
    {
        $mem = $this->load($tenantId, $appId);
        if (empty($mem)) {
            return '';
        }

        $skip = ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at',
                 'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id'];

        $entityLines = [];
        foreach (array_filter($mem['schema']['entities'] ?? [], fn($e) => ($e['table'] ?? '') !== 'audit_log') as $e) {
            $entityLines[] = "  - `{$e['table']}` ({$e['label']})";
            $fields = array_filter($e['fields'] ?? [], fn($f) => !in_array($f['name'] ?? '', $skip, true));
            foreach ($fields as $f) {
                $req  = ($f['required'] ?? false) ? ' [requerido]' : '';
                $type = $f['type'] ?? 'string';
                $entityLines[] = "    · `{$f['name']}` ({$type}){$req}";
            }
        }
        $entityList = implode("\n", $entityLines);

        $version   = $mem['version']   ?? '?';
        $appId2    = $mem['app_id']    ?? $appId;
        $updatedAt = $mem['updated_at'] ?? '?';
        $reqs      = mb_substr((string) ($mem['requirements'] ?? ''), 0, 400);
        $mixins    = implode(', ', (array) ($mem['config']['mixins'] ?? []));

        $lines = [
            "## Contexto de App Existente",
            "",
            "App ID: `{$appId2}` — Versión: `{$version}` — Última actualización: `{$updatedAt}`",
            "Módulos adicionales activos: " . ($mixins !== '' ? $mixins : 'ninguno'),
            "",
            "### Tablas de base de datos actuales:",
            $entityList,
            "",
            "### Requisitos originales recopilados:",
            "> {$reqs}",
            "",
            "REGLAS para modificar esta app:",
            "- Solo cambios ADITIVOS (agregar tablas o columnas). NUNCA eliminar ni renombrar.",
            "- Si el usuario pide algo que requiere cambio destructivo, propón una solución alternativa aditiva.",
            "- Cuando hagas cambios, llama create_app con confirmed=true e incluye el schema COMPLETO actualizado.",
            "- Registra en changelog qué cambió y por qué.",
            "",
            "OPERACIONES DE DATOS (usar cuando el usuario pide registrar, ver, editar o borrar datos):",
            "- Registrar → insert_app_data con app_id, entity (nombre de tabla arriba), data {campo: valor}",
            "- Ver datos  → query_app_data con app_id, entity, limit opcional",
            "- Actualizar → update_app_data con app_id, entity, id (número), data {campo: nuevo_valor}",
            "- Eliminar   → delete_app_data con app_id, entity, id (número)",
            "- Buscar uno → find_app_data con app_id, entity, id (número)",
            "- Extrae los valores del mensaje del usuario. Si faltan campos requeridos, preguntar antes de llamar el tool.",
        ];

        return implode("\n", $lines);
    }

    /**
     * Appends a feedback/improvement note to the app memory.
     */
    public function appendFeedback(string $tenantId, string $appId, array $feedback): void
    {
        $mem = $this->load($tenantId, $appId);
        if (empty($mem)) {
            return;
        }
        $mem['feedback']   = array_merge($mem['feedback'] ?? [], [$feedback]);
        $mem['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents(
            $this->path($tenantId, $appId),
            json_encode($mem, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Returns pending improvement suggestions from feedback.
     */
    public function getPendingImprovements(string $tenantId, string $appId): array
    {
        $mem  = $this->load($tenantId, $appId);
        $feed = $mem['feedback'] ?? [];
        return array_values(array_filter($feed, fn($f) => ($f['status'] ?? 'pending') === 'pending'));
    }

    private function path(string $tenantId, string $appId): string
    {
        $t = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tenantId);
        $a = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $appId);
        return "{$this->storageDir}/{$t}_{$a}.json";
    }

    private function resolveStorageDir(): string
    {
        return dirname(__DIR__, 3) . '/project/storage/meta/app_memory';
    }
}
