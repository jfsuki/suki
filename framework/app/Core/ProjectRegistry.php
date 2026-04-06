<?php
// app/Core/ProjectRegistry.php

namespace App\Core;

use PDO;
use RuntimeException;

final class ProjectRegistry
{
    private PDO $db;
    private string $dbPath;

    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath ?: $this->defaultPath();
        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Forzar entorno local y permitir cambios de esquema en tiempo de ejecución para aplicar la columna user_type
        if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '.test') !== false) {
             putenv('ALLOW_RUNTIME_SCHEMA=1');
             putenv('APP_ENV=local');
        }

        RuntimeSchemaPolicy::bootstrap(
            $this->db,
            'ProjectRegistry',
            function() {
                $this->ensureSchema();
                $this->initializeAuthSchema();
            },
            $this->requiredTables(),
            [],
            $this->requiredColumns(),
            'db/migrations/sqlite/20260303_004_runtime_infra_schema.sql'
        );
    }

    public function db(): PDO
    {
        return $this->db;
    }


    public function ensureProject(
        string $projectId,
        string $name,
        string $status = 'draft',
        string $tenantMode = 'shared',
        string $ownerUserId = '',
        string $storageModel = ''
    ): void
    {
        $existing = $this->getProject($projectId);
        $resolvedStorageModel = $this->resolveStorageModel($storageModel, $existing);
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO projects (id, name, status, tenant_mode, owner_user_id, storage_model, created_at, updated_at) VALUES (:id, :name, :status, :tenant_mode, :owner, :storage_model, :created, :updated)');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':id' => $projectId,
            ':name' => $name,
            ':status' => $status,
            ':tenant_mode' => $tenantMode,
            ':owner' => $ownerUserId,
            ':storage_model' => $resolvedStorageModel,
            ':created' => $now,
            ':updated' => $now,
        ]);

        $stmt = $this->db->prepare('UPDATE projects SET name = :name, status = :status, tenant_mode = :tenant_mode, storage_model = :storage_model, updated_at = :updated WHERE id = :id');
        $stmt->execute([
            ':id' => $projectId,
            ':name' => $name,
            ':status' => $status,
            ':tenant_mode' => $tenantMode,
            ':storage_model' => $resolvedStorageModel,
            ':updated' => $now,
        ]);
    }

    public function updateProjectStatus(string $projectId, string $status): bool
    {
        $stmt = $this->db->prepare('UPDATE projects SET status = :status, updated_at = :now WHERE id = :id');
        return $stmt->execute([
            ':status' => $status,
            ':id' => $projectId,
            ':now' => date('Y-m-d H:i:s')
        ]);
    }

    public function touchUser(string $userId, string $role, string $type, string $tenantId, ?string $label = null, ?string $password = null): void
    {
        if ($userId === '') return;
        $now = date('Y-m-d H:i:s');
        $hash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
        
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO users (id, label, type, role, tenant_id, password_hash, created_at, last_seen) VALUES (:id, :label, :type, :role, :tenant, :hash, :created, :last_seen)');
        $stmt->execute([
            ':id' => $userId,
            ':label' => $label ?: $userId,
            ':type' => $type,
            ':role' => $role,
            ':tenant' => $tenantId,
            ':hash' => $hash,
            ':created' => $now,
            ':last_seen' => $now,
        ]);

        $sql = 'UPDATE users SET role = :role, type = :type, tenant_id = :tenant, last_seen = :last_seen WHERE id = :id';
        $params = [
            ':id' => $userId,
            ':role' => $role,
            ':type' => $type,
            ':tenant' => $tenantId,
            ':last_seen' => $now,
        ];
        
        if ($hash) {
            $sql = 'UPDATE users SET role = :role, type = :type, tenant_id = :tenant, password_hash = :hash, last_seen = :last_seen WHERE id = :id';
            $params[':hash'] = $hash;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function updateMasterUser(string $userId, array $data): bool
    {
        $id = trim($userId);
        if ($id === '') return false;
        
        $fields = [];
        $params = [':id' => $id];
        
        foreach ($data as $key => $val) {
            if ($key === 'password') {
                $fields[] = "password_hash = :hash";
                $params[':hash'] = password_hash($val, PASSWORD_DEFAULT);
                continue;
            }
            if (in_array($key, ['label', 'role', 'type', 'tenant_id'])) {
                $fields[] = "{$key} = :{$key}";
                $params[":{$key}"] = $val;
            }
        }
        
        if (empty($fields)) return false;
        
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function assignUserToProject(string $projectId, string $userId, string $role): void
    {
        if ($projectId === '' || $userId === '') return;
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO project_users (project_id, user_id, role, created_at) VALUES (:project, :user, :role, :created)');
        $stmt->execute([
            ':project' => $projectId,
            ':user' => $userId,
            ':role' => $role,
            ':created' => $now,
        ]);

        $stmt = $this->db->prepare('UPDATE project_users SET role = :role WHERE project_id = :project AND user_id = :user');
        $stmt->execute([
            ':project' => $projectId,
            ':user' => $userId,
            ':role' => $role,
        ]);
    }

    public function touchSession(string $sessionId, string $userId, string $projectId, string $tenantId, string $channel): void
    {
        if ($sessionId === '') return;
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO chat_sessions (session_id, user_id, project_id, tenant_id, channel, last_message_at) VALUES (:id, :user, :project, :tenant, :channel, :last)');
        $stmt->execute([
            ':id' => $sessionId,
            ':user' => $userId,
            ':project' => $projectId,
            ':tenant' => $tenantId,
            ':channel' => $channel,
            ':last' => $now,
        ]);

        $stmt = $this->db->prepare('UPDATE chat_sessions SET last_message_at = :last WHERE session_id = :id');
        $stmt->execute([
            ':id' => $sessionId,
            ':last' => $now,
        ]);
    }

    public function getSession(string $sessionId): ?array
    {
        if ($sessionId === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT session_id, user_id, project_id, tenant_id, channel, last_message_at FROM chat_sessions WHERE session_id = :id');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function registerEntity(string $projectId, string $entityName, string $source = 'chat'): void
    {
        if ($projectId === '' || $entityName === '') return;
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO entities (project_id, entity_name, source, created_at) VALUES (:project, :name, :source, :created)');
        $stmt->execute([
            ':project' => $projectId,
            ':name' => $entityName,
            ':source' => $source,
            ':created' => $now,
        ]);
    }

    public function syncEntitiesFromContracts(string $projectId, array $entityNames, string $source = 'contracts'): array
    {
        if ($projectId === '') {
            return ['inserted' => 0, 'removed' => 0, 'total' => 0];
        }

        $clean = [];
        foreach ($entityNames as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $clean[$name] = true;
            }
        }
        $names = array_keys($clean);
        sort($names);

        $stmt = $this->db->prepare('SELECT entity_name FROM entities WHERE project_id = :project');
        $stmt->execute([':project' => $projectId]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existingMap = [];
        foreach ($existing as $name) {
            $n = trim((string) $name);
            if ($n !== '') {
                $existingMap[$n] = true;
            }
        }

        $inserted = 0;
        foreach ($names as $name) {
            if (!isset($existingMap[$name])) {
                $this->registerEntity($projectId, $name, $source);
                $inserted++;
            }
        }

        $removed = 0;
        if (empty($names)) {
            $delete = $this->db->prepare('DELETE FROM entities WHERE project_id = :project');
            $delete->execute([':project' => $projectId]);
            $removed = (int) $delete->rowCount();
        } else {
            $placeholders = [];
            $params = [':project' => $projectId];
            foreach ($names as $idx => $name) {
                $key = ':n' . $idx;
                $placeholders[] = $key;
                $params[$key] = $name;
            }
            $sql = 'DELETE FROM entities WHERE project_id = :project AND entity_name NOT IN (' . implode(', ', $placeholders) . ')';
            $delete = $this->db->prepare($sql);
            $delete->execute($params);
            $removed = (int) $delete->rowCount();
        }

        return [
            'inserted' => $inserted,
            'removed' => $removed,
            'total' => count($names),
        ];
    }

    public function summary(string $projectId): array
    {
        $summary = [
            'project_id' => $projectId,
            'users' => 0,
            'entities' => 0,
            'sessions' => 0,
        ];
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM project_users WHERE project_id = :project');
        $stmt->execute([':project' => $projectId]);
        $summary['users'] = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM entities WHERE project_id = :project');
        $stmt->execute([':project' => $projectId]);
        $summary['entities'] = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM chat_sessions WHERE project_id = :project');
        $stmt->execute([':project' => $projectId]);
        $summary['sessions'] = (int) $stmt->fetchColumn();

        return $summary;
    }

    public function listEntityNames(string $projectId): array
    {
        if ($projectId === '') {
            return [];
        }
        $stmt = $this->db->prepare('SELECT entity_name FROM entities WHERE project_id = :project ORDER BY entity_name ASC');
        $stmt->execute([':project' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $names = [];
        foreach ($rows as $name) {
            $value = trim((string) $name);
            if ($value !== '') {
                $names[$value] = true;
            }
        }
        return array_keys($names);
    }

    public function hasAnyEntities(): bool
    {
        $stmt = $this->db->query('SELECT 1 FROM entities LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row !== false;
    }

    public function listUsers(string $projectId): array
    {
        $stmt = $this->db->prepare('SELECT u.id, u.label, u.type, pu.role, u.tenant_id, u.last_seen
            FROM project_users pu
            JOIN users u ON u.id = pu.user_id
            WHERE pu.project_id = :project
            ORDER BY u.last_seen DESC');
        $stmt->execute([':project' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUser(string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id, label, type, role, tenant_id, created_at, last_seen
            FROM users
            WHERE id = :id
            LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listProjects(): array
    {
        $stmt = $this->db->query('SELECT id, name, status, tenant_mode, storage_model, owner_user_id, updated_at FROM projects ORDER BY updated_at DESC');
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function getProject(string $projectId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, status, tenant_mode, storage_model, owner_user_id, updated_at FROM projects WHERE id = :id');
        $stmt->execute([':id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function storeAuthCode(string $projectId, string $phone, string $code): void
    {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO auth_codes (project_id, phone, code, created_at) VALUES (:project, :phone, :code, :created)');
        $stmt->execute([
            ':project' => $projectId,
            ':phone' => $phone,
            ':code' => $code,
            ':created' => date('Y-m-d H:i:s'),
        ]);
    }

    public function verifyAuthCode(string $projectId, string $phone, string $code): bool
    {
        $stmt = $this->db->prepare('SELECT code, created_at FROM auth_codes WHERE project_id = :project AND phone = :phone');
        $stmt->execute([':project' => $projectId, ':phone' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return hash_equals((string) $row['code'], (string) $code);
    }

    public function createAuthUser(string $projectId, string $userId, string $password, string $role = 'admin', string $tenantId = 'default', ?string $label = null): void
    {
        if ($userId === '' || $password === '') {
            throw new RuntimeException('Usuario y password requeridos.');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO auth_users (id, project_id, label, role, tenant_id, password_hash, created_at, last_login) VALUES (:id, :project, :label, :role, :tenant, :hash, :created, :last)');
        $stmt->execute([
            ':id' => $userId,
            ':project' => $projectId,
            ':label' => $label ?: $userId,
            ':role' => $role,
            ':tenant' => $tenantId,
            ':hash' => $hash,
            ':created' => $now,
            ':last' => null,
        ]);
    }

    public function initializeAuthSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS auth_users (
            id            TEXT,
            project_id    TEXT,
            nit           TEXT,
            full_name     TEXT,
            area_code     TEXT NOT NULL DEFAULT '+57',
            phone_number  TEXT,
            alt_phone     TEXT,
            alt_email     TEXT,
            country       TEXT DEFAULT 'COLOMBIA',
            department    TEXT,
            city          TEXT,
            primary_activity   TEXT,
            secondary_activity TEXT,
            other_activities   TEXT,
            tax_responsibilities TEXT,
            business_desc TEXT,
            rut_path      TEXT,
            password_hash TEXT NOT NULL,
            tenant_id     TEXT NOT NULL,
            created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
            is_active     INTEGER DEFAULT 0,
            user_type     TEXT DEFAULT 'enterprise',
            PRIMARY KEY(id, project_id)
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_identifier
            ON login_attempts(identifier, attempted_at)");
        
        $this->ensureAuthColumns();
    }

    private function ensureAuthColumns(): void
    {
        $stmt = $this->db->query("PRAGMA table_info(auth_users)");
        $cols = $stmt->fetchAll(\PDO::FETCH_COLUMN, 1);
        $missing = array_diff(
            ['nit', 'full_name', 'area_code', 'phone_number', 'business_desc', 'rut_path', 'is_active', 'user_type'],
            $cols
        );

        foreach ($missing as $col) {
            $def = match($col) {
                'is_active' => "INTEGER DEFAULT 1",
                'area_code' => "TEXT DEFAULT '+57'",
                'user_type' => "TEXT DEFAULT 'enterprise'",
                default => "TEXT"
            };
            $this->db->exec("ALTER TABLE auth_users ADD COLUMN $col $def");
        }
    }

    public function verifyAuthUser(string $projectId, string $userId, string $password): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM auth_users WHERE id = :id AND project_id = :project');
        $stmt->execute([':id' => $userId, ':project' => $projectId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;
        if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $update = $this->db->prepare('UPDATE auth_users SET last_login = :last WHERE id = :id AND project_id = :project');
        $update->execute([':last' => $now, ':id' => $userId, ':project' => $projectId]);
        return $user;
    }

    public function getAuthUser(string $projectId, string $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, label, role, tenant_id, created_at, last_login FROM auth_users WHERE id = :id AND project_id = :project');
        $stmt->execute([':id' => $userId, ':project' => $projectId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function syncAuthUserContext(string $projectId, string $userId, string $role, string $tenantId): void
    {
        if ($projectId === '' || $userId === '') {
            return;
        }

        $stmt = $this->db->prepare('UPDATE auth_users
            SET role = :role, tenant_id = :tenant
            WHERE id = :id AND project_id = :project');
        $stmt->execute([
            ':role' => $role,
            ':tenant' => $tenantId,
            ':id' => $userId,
            ':project' => $projectId,
        ]);
    }

    public function listAuthUsers(string $projectId): array
    {
        $stmt = $this->db->prepare('SELECT id, label, role, tenant_id, created_at, last_login FROM auth_users WHERE project_id = :project ORDER BY created_at DESC');
        $stmt->execute([':project' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addDeploy(string $projectId, string $name, string $env, string $url = '', string $status = 'pending'): array
    {
        if ($projectId === '' || $name === '') {
            throw new RuntimeException('project_id y name requeridos.');
        }
        $id = 'dep_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('INSERT INTO deploys (id, project_id, name, env, url, status, created_at) VALUES (:id, :project, :name, :env, :url, :status, :created)');
        $stmt->execute([
            ':id' => $id,
            ':project' => $projectId,
            ':name' => $name,
            ':env' => $env,
            ':url' => $url,
            ':status' => $status,
            ':created' => $now,
        ]);
        return [
            'id' => $id,
            'project_id' => $projectId,
            'name' => $name,
            'env' => $env,
            'url' => $url,
            'status' => $status,
            'created_at' => $now,
        ];
    }

    public function listDeploys(string $projectId): array
    {
        $stmt = $this->db->prepare('SELECT id, name, env, url, status, created_at FROM deploys WHERE project_id = :project ORDER BY created_at DESC');
        $stmt->execute([':project' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function resolveProjectFromManifest(): array
    {
        $manifestPath = $this->projectRoot() . '/contracts/app.manifest.json';
        if (!is_file($manifestPath)) {
            return [
                'id' => 'default',
                'name' => 'Proyecto',
                'status' => 'draft',
                'tenant_mode' => 'shared',
                'storage_model' => StorageModel::LEGACY,
            ];
        }
        $raw = file_get_contents($manifestPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $app = is_array($data) ? ($data['app'] ?? []) : [];
        $storageModel = StorageModel::normalize((string) ($app['storage_model'] ?? $data['storage_model'] ?? ''));
        if ($storageModel === StorageModel::LEGACY && empty($app['storage_model']) && empty($data['storage_model'])) {
            $storageModel = $this->isCanonicalNewProjectsEnabled() ? StorageModel::CANONICAL : StorageModel::LEGACY;
        }
        return [
            'id' => (string) ($app['id'] ?? 'default'),
            'name' => (string) ($app['name'] ?? 'Proyecto'),
            'status' => (string) ($app['status'] ?? 'draft'),
            'tenant_mode' => (string) ($app['tenant_mode'] ?? 'shared'),
            'storage_model' => $storageModel,
        ];
    }

    private function ensureSchema(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS projects (
            id TEXT PRIMARY KEY,
            name TEXT,
            status TEXT,
            tenant_mode TEXT,
            storage_model TEXT DEFAULT \'legacy\',
            owner_user_id TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $this->ensureProjectsStorageModelColumn();
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (
            id TEXT PRIMARY KEY,
            label TEXT,
            type TEXT,
            role TEXT,
            tenant_id TEXT,
            password_hash TEXT,
            created_at TEXT,
            last_seen TEXT
        )');
        $this->ensureUsersPasswordColumn();
        $this->db->exec('CREATE TABLE IF NOT EXISTS project_users (
            project_id TEXT,
            user_id TEXT,
            role TEXT,
            created_at TEXT,
            PRIMARY KEY(project_id, user_id)
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS entities (
            project_id TEXT,
            entity_name TEXT,
            source TEXT,
            created_at TEXT,
            PRIMARY KEY(project_id, entity_name)
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS chat_sessions (
            session_id TEXT PRIMARY KEY,
            user_id TEXT,
            project_id TEXT,
            tenant_id TEXT,
            channel TEXT,
            last_message_at TEXT
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS auth_users (
            id TEXT,
            project_id TEXT,
            label TEXT,
            role TEXT,
            tenant_id TEXT,
            password_hash TEXT,
            created_at TEXT,
            last_login TEXT,
            PRIMARY KEY(id, project_id)
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS auth_codes (
            project_id TEXT,
            phone TEXT,
            code TEXT,
            created_at TEXT,
            PRIMARY KEY(project_id, phone)
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS deploys (
            id TEXT PRIMARY KEY,
            project_id TEXT,
            name TEXT,
            env TEXT,
            url TEXT,
            status TEXT,
            created_at TEXT
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ai_agents (
            agent_id TEXT PRIMARY KEY,
            tenant_id TEXT,
            project_id TEXT,
            role TEXT,
            area TEXT,
            status TEXT,
            config_json TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ai_agent_events (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            agent_id     TEXT,
            tenant_id    TEXT,
            event_type   TEXT,
            details      TEXT,
            status       TEXT,
            created_at   TEXT
        )');
    }

    public function logAgentEvent(string $agentId, string $tenantId, string $type, string $details, string $status = 'INFO'): void
    {
        $stmt = $this->db->prepare('INSERT INTO ai_agent_events (agent_id, tenant_id, event_type, details, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$agentId, $tenantId, $type, $details, $status, date('Y-m-d H:i:s')]);
    }

    public function getAgentEvents(string $tenantId, int $limit = 20): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_agent_events WHERE tenant_id = ? ORDER BY created_at DESC LIMIT ?');
        $stmt->execute([$tenantId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function createAgent(string $tenantId, string $role, string $area, array $config = [], string $projectId = 'default'): string
    {
        $agentId = 'agnt_' . bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('INSERT INTO ai_agents (agent_id, tenant_id, project_id, role, area, status, config_json, created_at, updated_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $agentId,
            $tenantId,
            $projectId,
            $role,
            $area,
            'IDLE',
            json_encode($config),
            $now,
            $now
        ]);
        return $agentId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAgentsByTenant(string $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ai_agents WHERE tenant_id = ? ORDER BY created_at DESC');
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updateAgentStatus(string $agentId, string $status): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('UPDATE ai_agents SET status = ?, updated_at = ? WHERE agent_id = ?');
        return $stmt->execute([$status, $now, $agentId]);
    }

    /**
     * @return array<int, string>
     */
    private function requiredTables(): array
    {
        return [
            'projects',
            'users',
            'project_users',
            'entities',
            'chat_sessions',
            'auth_users',
            'auth_codes',
            'deploys',
            'ai_agents',
            'ai_agent_events',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requiredColumns(): array
    {
        return [
            'projects' => ['storage_model'],
            'auth_users' => ['user_type', 'nit', 'full_name', 'is_active'],
        ];
    }

    private function defaultPath(): string
    {
        $override = trim((string) (getenv('PROJECT_REGISTRY_DB_PATH') ?: ''));
        if ($override !== '') {
            $dir = dirname($override);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            return $override;
        }
        $root = $this->projectRoot();
        $dir = $root . '/storage/meta';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir . '/project_registry.sqlite';
    }

    private function ensureProjectsStorageModelColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->query('PRAGMA table_info(projects)');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if ((string) ($row['name'] ?? '') === 'storage_model') {
                    return;
                }
            }
            $this->db->exec("ALTER TABLE projects ADD COLUMN storage_model TEXT DEFAULT 'legacy'");
            return;
        }

        if ($driver === 'mysql') {
            $stmt = $this->db->query(
                "SELECT COUNT(*) AS total
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'projects'
                   AND column_name = 'storage_model'"
            );
            $exists = (int) ($stmt ? $stmt->fetchColumn() : 0);
            if ($exists === 0) {
                $this->db->exec("ALTER TABLE projects ADD COLUMN storage_model VARCHAR(40) NOT NULL DEFAULT 'legacy'");
            }
        }
    }

    private function resolveStorageModel(string $requestedModel, ?array $existingProject = null): string
    {
        $rawRequested = strtolower(trim($requestedModel));
        if ($rawRequested !== '') {
            return StorageModel::normalize($rawRequested);
        }

        if (is_array($existingProject)) {
            $current = StorageModel::normalize((string) ($existingProject['storage_model'] ?? ''));
            return $current !== '' ? $current : StorageModel::LEGACY;
        }

        return $this->isCanonicalNewProjectsEnabled() ? StorageModel::CANONICAL : StorageModel::LEGACY;
    }

    private function isCanonicalNewProjectsEnabled(): bool
    {
        $raw = strtolower(trim((string) (getenv('DB_CANONICAL_NEW_PROJECTS') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function projectRoot(): string
    {
        if (defined('PROJECT_ROOT')) {
            return PROJECT_ROOT;
        }
        return dirname(__DIR__, 3) . '/project';
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUsersByStatus(int $isActive): array
    {
        $stmt = $this->db->prepare('SELECT * FROM auth_users WHERE is_active = :status ORDER BY created_at DESC');
        $stmt->execute([':status' => $isActive]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateAuthUserStatus(string $userId, int $status): bool
    {
        $stmt = $this->db->prepare('UPDATE auth_users SET is_active = :status WHERE id = :id OR nit = :id');
        return $stmt->execute([':status' => $status, ':id' => $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAuthUserById(string $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM auth_users WHERE id = :id OR nit = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getUsersByType(string $type): array
    {
        $stmt = $this->db->prepare('SELECT * FROM auth_users WHERE user_type = :type ORDER BY created_at DESC');
        $stmt->execute([':type' => $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMasterUsersByType(string $type): array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE type = :type ORDER BY created_at DESC');
        $stmt->execute([':type' => $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function verifyMasterUser(string $userId, string $password): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id OR label = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;
        if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            return null;
        }
        return $user;
    }

    private function ensureUsersPasswordColumn(): void
    {
        $stmt = $this->db->query("PRAGMA table_info(users)");
        $cols = $stmt->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (!in_array('password_hash', $cols)) {
            $this->db->exec("ALTER TABLE users ADD COLUMN password_hash TEXT");
        }
    }
}
