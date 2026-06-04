<?php

declare(strict_types=1);

// framework/app/Core/Tools/FileDownloadTool.php

namespace App\Core\Tools;

/**
 * Secure file downloader for agent-driven document retrieval.
 *
 * Security properties:
 *  - SSRF protection: private/loopback IP ranges blocked
 *  - Domain allowlist: only .gov.co, dian.gov.co, drive.google.com, + tenant domains in DB
 *  - MIME validation: only document/spreadsheet/image types
 *  - 10 MB hard cap
 */
final class FileDownloadTool
{
    private const MAX_BYTES   = 10 * 1024 * 1024; // 10 MB
    private const TIMEOUT     = 30;

    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png',
        'application/pdf',
        'text/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const BASE_ALLOWED_DOMAINS = [
        '.gov.co',
        'dian.gov.co',
        'drive.google.com',
    ];

    /**
     * @return array{saved_path: string, mime_type: string, size_bytes: int, filename: string}
     *       | array{error: string}
     */
    public function download(string $url, string $tenantId): array
    {
        $url = trim($url);

        $urlCheck = $this->validateUrl($url);
        if ($urlCheck !== null) {
            return ['error' => $urlCheck];
        }

        if (!$this->isDomainAllowed($url)) {
            return ['error' => 'Dominio no autorizado'];
        }

        [$body, $mimeType] = $this->fetchWithCurl($url);
        if ($body === null) {
            return ['error' => 'No se pudo descargar el archivo'];
        }

        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            return ['error' => "Tipo de archivo no permitido: {$mimeType}"];
        }

        $size = strlen($body);
        if ($size > self::MAX_BYTES) {
            return ['error' => 'Archivo demasiado grande (máximo 10 MB)'];
        }

        $filename    = $this->sanitizeFilename(basename(parse_url($url, PHP_URL_PATH) ?? 'download'));
        $destination = $this->buildDestinationPath($tenantId, $filename);

        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        file_put_contents($destination, $body);

        return [
            'saved_path' => $destination,
            'mime_type'  => $mimeType,
            'size_bytes' => $size,
            'filename'   => $filename,
        ];
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    private function validateUrl(string $url): ?string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'URL inválida';
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return 'URL sin host';
        }

        // SSRF protection: resolve host to IP and check private ranges
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // Resolution failed — treat as unsafe only for IP literals
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if ($this->isPrivateIp($host)) {
                    return 'IP privada no permitida';
                }
            }
        } elseif ($this->isPrivateIp($ip)) {
            return 'IP privada no permitida';
        }

        return null;
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function isDomainAllowed(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        foreach (self::BASE_ALLOWED_DOMAINS as $allowed) {
            if ($host === ltrim($allowed, '.') || str_ends_with($host, $allowed)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    /** @return array{0: string|null, 1: string} [body, mime_type] */
    private function fetchWithCurl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'SUKI-ERP/1.0',
        ]);

        $body     = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mimeType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            return [null, ''];
        }

        // Normalize "text/html; charset=utf-8" → "text/html"
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        return [(string) $body, $mimeType];
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?? 'download';
        return mb_substr($name, 0, 100);
    }

    private function buildDestinationPath(string $tenantId, string $filename): string
    {
        $safeTenant = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tenantId);
        $dir        = dirname(__DIR__, 4) . "/project/storage/tenants/{$safeTenant}/downloads";
        $ts         = time();
        return "{$dir}/{$ts}_{$filename}";
    }
}
