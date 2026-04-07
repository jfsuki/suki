<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Repositorio de configuración de empresa por tenant.
 * Almacena logo, datos fiscales DIAN, pie de página legal y color corporativo.
 */
final class BusinessConfigRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
        $this->ensureSchema();
    }

    // ─── Lectura ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    public function findByTenant(string $tenantId): ?array
    {
        $pdo  = $this->db->getPdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM tenant_business_config WHERE tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $this->decode($row) : null;
    }

    // ─── Escritura ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function upsert(string $tenantId, array $data): array
    {
        $pdo   = $this->db->getPdo();
        $now   = date('Y-m-d H:i:s');
        $existing = $this->findByTenant($tenantId);

        $fields = [
            'company_name'      => (string) ($data['company_name']      ?? $existing['company_name']      ?? ''),
            'trade_name'        => (string) ($data['trade_name']        ?? $existing['trade_name']        ?? ''),
            'nit'               => (string) ($data['nit']               ?? $existing['nit']               ?? ''),
            'address'           => (string) ($data['address']           ?? $existing['address']           ?? ''),
            'city'              => (string) ($data['city']              ?? $existing['city']              ?? ''),
            'department'        => (string) ($data['department']        ?? $existing['department']        ?? ''),
            'country'           => (string) ($data['country']           ?? $existing['country']           ?? 'CO'),
            'phone'             => (string) ($data['phone']             ?? $existing['phone']             ?? ''),
            'email'             => (string) ($data['email']             ?? $existing['email']             ?? ''),
            'website'           => (string) ($data['website']          ?? $existing['website']           ?? ''),
            'tax_regime'        => (string) ($data['tax_regime']        ?? $existing['tax_regime']        ?? ''),
            'dian_resolution'   => (string) ($data['dian_resolution']   ?? $existing['dian_resolution']   ?? ''),
            'dian_prefix'       => (string) ($data['dian_prefix']       ?? $existing['dian_prefix']       ?? ''),
            'dian_from_number'  => (int)    ($data['dian_from_number']  ?? $existing['dian_from_number']  ?? 0),
            'dian_to_number'    => (int)    ($data['dian_to_number']    ?? $existing['dian_to_number']    ?? 0),
            'dian_valid_from'   => (string) ($data['dian_valid_from']   ?? $existing['dian_valid_from']   ?? ''),
            'dian_valid_until'  => (string) ($data['dian_valid_until']  ?? $existing['dian_valid_until']  ?? ''),
            'document_footer'   => (string) ($data['document_footer']   ?? $existing['document_footer']   ?? ''),
            'primary_color'     => (string) ($data['primary_color']     ?? $existing['primary_color']     ?? '#1a56db'),
            'logo_path'         => (string) ($data['logo_path']         ?? $existing['logo_path']         ?? ''),
            'logo_base64'       => (string) ($data['logo_base64']       ?? $existing['logo_base64']       ?? ''),
            'currency'          => (string) ($data['currency']          ?? $existing['currency']          ?? 'COP'),
            'smtp_host'         => (string) ($data['smtp_host']         ?? $existing['smtp_host']         ?? ''),
            'smtp_port'         => (int)    ($data['smtp_port']         ?? $existing['smtp_port']         ?? 587),
            'smtp_user'         => (string) ($data['smtp_user']         ?? $existing['smtp_user']         ?? ''),
            'smtp_pass'         => (string) ($data['smtp_pass']         ?? $existing['smtp_pass']         ?? ''),
            'updated_at'        => $now,
        ];

        if ($existing === null) {
            $fields['tenant_id']  = $tenantId;
            $fields['created_at'] = $now;
            $cols  = implode(', ', array_keys($fields));
            $marks = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO tenant_business_config ({$cols}) VALUES ({$marks})")
                ->execute(array_values($fields));
        } else {
            $sets = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($fields)));
            $vals = array_values($fields);
            $vals[] = $tenantId;
            $pdo->prepare("UPDATE tenant_business_config SET {$sets} WHERE tenant_id = ?")
                ->execute($vals);
        }

        return $this->findByTenant($tenantId) ?? $fields;
    }

    /** Save logo path after upload */
    public function saveLogo(string $tenantId, string $logoPath, string $logoBase64 = ''): void
    {
        $pdo = $this->db->getPdo();
        $now = date('Y-m-d H:i:s');
        $existing = $this->findByTenant($tenantId);

        if ($existing === null) {
            $pdo->prepare(
                'INSERT INTO tenant_business_config (tenant_id, logo_path, logo_base64, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$tenantId, $logoPath, $logoBase64, $now, $now]);
        } else {
            $pdo->prepare(
                'UPDATE tenant_business_config SET logo_path = ?, logo_base64 = ?, updated_at = ?
                 WHERE tenant_id = ?'
            )->execute([$logoPath, $logoBase64, $now, $tenantId]);
        }
    }

    // ─── Schema ───────────────────────────────────────────────────────────────

    private function ensureSchema(): void
    {
        $this->db->getPdo()->exec('
            CREATE TABLE IF NOT EXISTS tenant_business_config (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id       TEXT NOT NULL UNIQUE,
                company_name    TEXT NOT NULL DEFAULT \'\',
                trade_name      TEXT NOT NULL DEFAULT \'\',
                nit             TEXT NOT NULL DEFAULT \'\',
                address         TEXT NOT NULL DEFAULT \'\',
                city            TEXT NOT NULL DEFAULT \'\',
                department      TEXT NOT NULL DEFAULT \'\',
                country         TEXT NOT NULL DEFAULT \'CO\',
                phone           TEXT NOT NULL DEFAULT \'\',
                email           TEXT NOT NULL DEFAULT \'\',
                website         TEXT NOT NULL DEFAULT \'\',
                tax_regime      TEXT NOT NULL DEFAULT \'\',
                dian_resolution TEXT NOT NULL DEFAULT \'\',
                dian_prefix     TEXT NOT NULL DEFAULT \'\',
                dian_from_number INTEGER NOT NULL DEFAULT 0,
                dian_to_number   INTEGER NOT NULL DEFAULT 0,
                dian_valid_from  TEXT NOT NULL DEFAULT \'\',
                dian_valid_until TEXT NOT NULL DEFAULT \'\',
                document_footer  TEXT NOT NULL DEFAULT \'\',
                primary_color    TEXT NOT NULL DEFAULT \'#1a56db\',
                logo_path        TEXT NOT NULL DEFAULT \'\',
                logo_base64      TEXT NOT NULL DEFAULT \'\',
                currency         TEXT NOT NULL DEFAULT \'COP\',
                smtp_host        TEXT NOT NULL DEFAULT \'\',
                smtp_port        INTEGER NOT NULL DEFAULT 587,
                smtp_user        TEXT NOT NULL DEFAULT \'\',
                smtp_pass        TEXT NOT NULL DEFAULT \'\',
                created_at       TEXT NOT NULL,
                updated_at       TEXT NOT NULL
            )
        ');
        $this->db->getPdo()->exec(
            'CREATE INDEX IF NOT EXISTS idx_tbc_tenant ON tenant_business_config(tenant_id)'
        );
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function decode(array $row): array
    {
        foreach (['dian_from_number', 'dian_to_number'] as $int) {
            $row[$int] = (int) ($row[$int] ?? 0);
        }
        return $row;
    }
}
