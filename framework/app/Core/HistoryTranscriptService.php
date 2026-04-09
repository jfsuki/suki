<?php
// framework/app/Core/HistoryTranscriptService.php

namespace App\Core;

class HistoryTranscriptService
{
    private string $logPath;

    public function __construct(?string $logPath = null)
    {
        $this->logPath = $logPath ?? dirname(__DIR__, 2) . '/storage/logs/transcripts';
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0775, true);
        }
    }

    /**
     * Actualiza el archivo .txt con el historial actual.
     * 
     * @param string $tenantId
     * @param string $sessionId
     * @param array $history Historia de mensajes en formato JSON
     */
    public function updateTranscript(string $tenantId, string $sessionId, array $history): void
    {
        $fileName = 'history_' . $this->sanitize($tenantId) . '_' . $this->sanitize($sessionId) . '.txt';
        $filePath = $this->logPath . '/' . $fileName;

        $content = "=== TRANSCRIPCION DE CONVERSACION SUKI ===\n";
        $content .= "Tenant: " . $tenantId . "\n";
        $content .= "Sesión: " . $sessionId . "\n";
        $content .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
        $content .= "------------------------------------------\n\n";

        foreach ($history as $entry) {
            $ts = isset($entry['ts']) ? date('Y-m-d H:i:s', (int)$entry['ts']) : date('Y-m-d H:i:s');
            $dir = (strtolower($entry['dir'] ?? 'in') === 'in') ? 'USUARIO' : 'SUKI';
            $msg = $entry['msg'] ?? '';
            
            $content .= "[{$ts}] [{$dir}]: {$msg}\n";
        }

        $content .= "\n=== FIN DEL ARCHIVO ===\n";

        file_put_contents($filePath, $content);
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[^a-z0-9_]/i', '_', $value);
    }
}
