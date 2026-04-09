<?php
namespace App\Core;

class LocalJsonMemoryRepository implements MemoryRepositoryInterface
{
    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? dirname(__DIR__, 2) . '/storage/meta/memory';
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0777, true);
        }
    }

    private function getFilePath(string $category, string $key): string
    {
        return $this->storagePath . '/' . md5($category . $key) . '.json';
    }

    public function getGlobalMemory(string $category, string $key, array $default = []): array
    {
        $path = $this->getFilePath('global_' . $category, $key);
        return is_file($path) ? json_decode(file_get_contents($path), true) : $default;
    }

    public function saveGlobalMemory(string $category, string $key, array $value): void
    {
        $path = $this->getFilePath('global_' . $category, $key);
        file_put_contents($path, json_encode($value));
    }

    public function getTenantMemory(string $tenantId, string $key, array $default = []): array
    {
        $path = $this->getFilePath('tenant_' . $tenantId, $key);
        return is_file($path) ? json_decode(file_get_contents($path), true) : $default;
    }

    public function saveTenantMemory(string $tenantId, string $key, array $value): void
    {
        $path = $this->getFilePath('tenant_' . $tenantId, $key);
        file_put_contents($path, json_encode($value));
    }

    public function getUserMemory(string $tenantId, string $userId, string $key, array $default = []): array
    {
        $path = $this->getFilePath('user_' . $tenantId . '_' . $userId, $key);
        return is_file($path) ? json_decode(file_get_contents($path), true) : $default;
    }

    public function saveUserMemory(string $tenantId, string $userId, string $key, array $value): void
    {
        $path = $this->getFilePath('user_' . $tenantId . '_' . $userId, $key);
        file_put_contents($path, json_encode($value));
    }

    public function appendShortTermMemory(string $tenantId, string $userId, string $sessionId, string $channel, string $direction, string $message, array $meta = []): void
    {
        $path = $this->getFilePath('history_' . $tenantId, $sessionId);
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $data[] = ['msg' => $message, 'dir' => $direction, 'ts' => time()];
        file_put_contents($path, json_encode(array_slice($data, -50)));

        // 2.3.1 GENERACIÓN DE TRANSCRIPCION TXT (Bloc de notas para el usuario)
        try {
            $transcriptService = new \App\Core\HistoryTranscriptService();
            $transcriptService->updateTranscript($tenantId, $sessionId, $data);
        } catch (\Throwable $e) {
            // No bloquear el chat si falla el log TXT
            error_log("FALLO_GEN_TRANSCRIPT: " . $e->getMessage());
        }
    }

    public function getShortTermMemory(string $tenantId, string $sessionId, int $limit = 20): array
    {
        $path = $this->getFilePath('history_' . $tenantId, $sessionId);
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        return array_slice($data, -$limit);
    }

    public function get(string $key, array $default = []): array
    {
        $path = $this->storagePath . '/' . md5($key) . '.json';
        return is_file($path) ? json_decode(file_get_contents($path), true) : $default;
    }

    public function save(string $key, array $value): void
    {
        $path = $this->storagePath . '/' . md5($key) . '.json';
        file_put_contents($path, json_encode($value));
    }

    public function getSession(string $sessionId): array
    {
        return $this->get('session_' . $sessionId);
    }

    public function saveSession(string $sessionId, array $data): void
    {
        $this->save('session_' . $sessionId, $data);
    }
}
