<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Servicio de configuración de empresa por tenant.
 * Gestiona datos de empresa, logo y configuración visual para documentos.
 */
final class BusinessConfigService
{
    /** Logo máximo: 3 MB */
    private const MAX_LOGO_BYTES = 3 * 1024 * 1024;

    /** Extensiones permitidas para logo */
    private const LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'svg'];

    private BusinessConfigRepository $repository;
    private AuditLogger $auditLogger;

    public function __construct(
        ?BusinessConfigRepository $repository = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->repository = $repository ?? new BusinessConfigRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    // ─── Lectura ──────────────────────────────────────────────────────────────

    /**
     * Retorna configuración completa de la empresa.
     * Si no existe, retorna defaults vacíos (NO lanza excepción).
     * @return array<string, mixed>
     */
    public function getConfig(string $tenantId): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $config = $this->repository->findByTenant($tenantId);
        return $this->decorate($config ?? $this->emptyConfig($tenantId));
    }

    // ─── Escritura ─────────────────────────────────────────────────────────────

    /**
     * Guarda o actualiza la configuración de la empresa.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveConfig(string $tenantId, array $payload, ?string $userId = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');
        $saved = $this->repository->upsert($tenantId, $payload);
        $result = $this->decorate($saved);

        $this->auditLogger->log('business_config.save', 'tenant_business_config', $tenantId, [
            'tenant_id' => $tenantId,
            'user_id'   => $userId ?? 'system',
            'fields_updated' => array_keys(array_filter($payload, static fn($v) => $v !== null && $v !== '')),
        ]);

        return $result;
    }

    /**
     * Sube el logo de la empresa desde un archivo temporal.
     * @param array<string, mixed> $file  — array con keys: tmp_path, original_name, size
     * @return array<string, mixed>
     */
    public function uploadLogo(string $tenantId, array $file, ?string $userId = null): array
    {
        $tenantId = $this->requireString($tenantId, 'tenant_id');

        $tmpPath      = (string) ($file['tmp_path'] ?? $file['path'] ?? '');
        $originalName = (string) ($file['original_name'] ?? $file['name'] ?? 'logo');
        $fileSize     = (int) ($file['size'] ?? ($tmpPath !== '' ? @filesize($tmpPath) : 0));

        if ($tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
            throw new RuntimeException('LOGO_SOURCE_NOT_READABLE');
        }

        if ($fileSize > self::MAX_LOGO_BYTES) {
            throw new RuntimeException('LOGO_MAX_SIZE_EXCEEDED');
        }

        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::LOGO_EXTENSIONS, true)) {
            throw new RuntimeException('LOGO_EXTENSION_NOT_ALLOWED');
        }

        // Directorio de logos de tenant
        $logoDir = PROJECT_ROOT . '/storage/tenant/logos/';
        if (!is_dir($logoDir)) {
            mkdir($logoDir, 0755, true);
        }

        $filename = 'logo_' . preg_replace('/[^a-z0-9_-]/', '_', strtolower($tenantId)) . '.' . $ext;
        $destPath = $logoDir . $filename;

        if (!copy($tmpPath, $destPath)) {
            throw new RuntimeException('LOGO_SAVE_FAILED');
        }

        // Generar base64 para embeber en documentos sin depender de rutas públicas
        $logoBase64 = '';
        if ($ext !== 'svg') {
            $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $mime = $mimeMap[$ext] ?? 'image/png';
            $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($destPath));
        }

        $this->repository->saveLogo($tenantId, $destPath, $logoBase64);

        $this->auditLogger->log('business_config.logo_upload', 'tenant_business_config', $tenantId, [
            'tenant_id' => $tenantId,
            'user_id'   => $userId ?? 'system',
            'logo_path' => $destPath,
            'file_size' => $fileSize,
        ]);

        return [
            'ok'        => true,
            'logo_path' => $destPath,
            'has_base64' => $logoBase64 !== '',
            'message'   => 'Logo actualizado correctamente.',
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $config */
    private function decorate(array $config): array
    {
        $config['has_logo']       = ($config['logo_path'] ?? '') !== '' || ($config['logo_base64'] ?? '') !== '';
        $config['has_dian_config'] = ($config['dian_resolution'] ?? '') !== '' && ($config['nit'] ?? '') !== '';
        $config['display_name']   = ($config['trade_name'] ?? '') !== '' ? $config['trade_name'] : ($config['company_name'] ?? '');
        return $config;
    }

    /** @return array<string, mixed> */
    private function emptyConfig(string $tenantId): array
    {
        return [
            'tenant_id'       => $tenantId,
            'company_name'    => '',
            'trade_name'      => '',
            'nit'             => '',
            'address'         => '',
            'city'            => '',
            'department'      => '',
            'country'         => 'CO',
            'phone'           => '',
            'email'           => '',
            'website'         => '',
            'tax_regime'      => '',
            'dian_resolution' => '',
            'dian_prefix'     => '',
            'dian_from_number' => 0,
            'dian_to_number'   => 0,
            'dian_valid_from'  => '',
            'dian_valid_until' => '',
            'document_footer'  => '',
            'primary_color'    => '#1a56db',
            'logo_path'        => '',
            'logo_base64'      => '',
            'currency'         => 'COP',
            'created_at'       => '',
            'updated_at'       => '',
        ];
    }

    private function requireString(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new RuntimeException(strtoupper($field) . '_REQUIRED');
        }
        return $value;
    }
}
