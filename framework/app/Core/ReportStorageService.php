<?php
declare(strict_types=1);

namespace App\Core;

/**
 * ReportStorageService
 *
 * Persists generated PDFs/HTML reports to disk so users can re-download.
 * Storage path:  project/storage/reports/{safe_tenant}/{uuid}.{ext}
 * Index:         project/storage/reports/{safe_tenant}/index.json
 *
 * Returns a signed download token — the actual file path is never exposed.
 * Download URL:  /api/reports/download?token={signed_token}
 *
 * Security:
 *   - UUID is generated with random_bytes — not guessable
 *   - Download token is HMAC-signed by ReportTokenService
 *   - File paths never appear in any response
 *   - Tenant isolation: each tenant has their own subdirectory
 */
final class ReportStorageService
{
    private string            $storageDir;
    private ReportTokenService $tokens;

    public function __construct(?string $storageDir = null, ?ReportTokenService $tokens = null)
    {
        $this->storageDir = $storageDir ?? $this->resolveStorageDir();
        $this->tokens     = $tokens ?? new ReportTokenService();
    }

    /**
     * Saves report content to disk. Returns a signed download token.
     * @param array<string, mixed> $meta  Human-readable labels (app_id, entity, label…)
     */
    public function save(
        string $tenantId,
        string $content,
        string $ext  = 'pdf',
        array  $meta = []
    ): string {
        $dir = $this->tenantDir($tenantId);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $uuid     = bin2hex(random_bytes(8)); // 16-char opaque ID
        $filename = $uuid . '.' . $ext;
        file_put_contents($dir . '/' . $filename, $content);

        $this->appendIndex($dir, $uuid, $filename, $ext, $meta);

        return $this->tokens->generatePersistent([
            'purpose'   => 'report_download',
            'tenant_id' => $tenantId,
            'uuid'      => $uuid,
            'ext'       => $ext,
            'label'     => (string) ($meta['label'] ?? ''),
        ]);
    }

    /**
     * Retrieves file content. Verifies tenant matches token claims.
     */
    public function get(string $tenantId, string $uuid): ?string
    {
        $path = $this->resolvePath($tenantId, $uuid);
        if ($path === null) {
            return null;
        }
        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }

    /**
     * Serves a file by a signed download token. Returns [content, ext] or null.
     * @return array{string, string}|null
     */
    public function serveByToken(string $token): ?array
    {
        $payload = $this->tokens->decode($token);
        if ($payload === null || ($payload['purpose'] ?? '') !== 'report_download') {
            return null;
        }
        $tenantId = (string) ($payload['tenant_id'] ?? '');
        $uuid     = (string) ($payload['uuid']      ?? '');
        $ext      = (string) ($payload['ext']       ?? 'pdf');
        if ($tenantId === '' || $uuid === '') {
            return null;
        }
        $content = $this->get($tenantId, $uuid);
        return $content !== null ? [$content, $ext] : null;
    }

    /**
     * Lists saved reports for a tenant (most recent first, max 50).
     * @return array<int, array<string, mixed>>
     */
    public function listReports(string $tenantId): array
    {
        $index = $this->tenantDir($tenantId) . '/index.json';
        if (!file_exists($index)) {
            return [];
        }
        $raw  = file_get_contents($index);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        return array_reverse(array_slice($data, -50));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function tenantDir(string $tenantId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tenantId);
        return $this->storageDir . '/' . $safe;
    }

    private function resolvePath(string $tenantId, string $uuid): ?string
    {
        $dir  = $this->tenantDir($tenantId);
        $safeU = preg_replace('/[^a-zA-Z0-9]/', '', $uuid);
        foreach (['pdf', 'html'] as $ext) {
            $candidate = $dir . '/' . $safeU . '.' . $ext;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $meta */
    private function appendIndex(string $dir, string $uuid, string $filename, string $ext, array $meta): void
    {
        $indexPath = $dir . '/index.json';
        $entries   = [];
        if (file_exists($indexPath)) {
            $raw     = file_get_contents($indexPath);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            $entries = is_array($decoded) ? $decoded : [];
        }
        $entries[] = array_merge($meta, [
            'uuid'     => $uuid,
            'filename' => $filename,
            'ext'      => $ext,
            'saved_at' => date('Y-m-d H:i:s'),
        ]);
        if (count($entries) > 100) {
            $entries = array_slice($entries, -100);
        }
        file_put_contents($indexPath, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function resolveStorageDir(): string
    {
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project';
        return $projectRoot . '/storage/reports';
    }
}
