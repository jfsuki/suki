<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * OtpService — genera, almacena y envía códigos OTP de 6 dígitos vía SMTP.
 *
 * Delivery: SMTP (SMTP_HOST en .env). Fallback: log en storage/logs/otp_dev.log.
 * Seguridad: hash_equals, expiración 15 min, max 3 intentos por ventana.
 * NUNCA expone el código en HTTP responses.
 */
final class OtpService
{
    private PDO $db;
    private string $projectRoot;

    public function __construct(PDO $db, string $projectRoot = '')
    {
        $this->db = $db;
        $this->projectRoot = $projectRoot ?: (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
        $this->ensureSchema();
    }

    /**
     * Genera OTP, lo almacena y lo envía al email.
     * Retorna ['ok'=>true] o ['ok'=>false, 'error'=>string].
     * NUNCA retorna el código — no expuesto en HTTP.
     */
    public function send(string $projectId, string $identifier, string $email): array
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email inválido.'];
        }
        if ($this->isRateLimited($identifier)) {
            return ['ok' => false, 'error' => 'Demasiadas solicitudes. Espera 15 minutos.'];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->store($projectId, $identifier, $code);

        $sent = $this->deliverEmail($email, $code);
        if (!$sent) {
            $this->logFallback($identifier, $code);
        }

        return ['ok' => true];
    }

    /**
     * Verifica el código OTP. Consume el código si es válido (un solo uso).
     */
    public function verify(string $projectId, string $identifier, string $code): array
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return ['ok' => false, 'error' => 'Código inválido.'];
        }

        $stmt = $this->db->prepare(
            'SELECT code, created_at, attempts FROM otp_codes
             WHERE project_id = :project AND identifier = :id
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([':project' => $projectId, ':id' => $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['ok' => false, 'error' => 'Código no encontrado. Solicita uno nuevo.'];
        }

        $created = strtotime((string) ($row['created_at'] ?? '1970-01-01'));
        if (time() - $created > 900) {
            $this->deleteCode($projectId, $identifier);
            return ['ok' => false, 'error' => 'Código expirado. Solicita uno nuevo.'];
        }

        $attempts = (int) ($row['attempts'] ?? 0);
        if ($attempts >= 3) {
            $this->deleteCode($projectId, $identifier);
            return ['ok' => false, 'error' => 'Demasiados intentos. Solicita un nuevo código.'];
        }

        $this->incrementAttempts($projectId, $identifier);

        if (!hash_equals((string) $row['code'], $code)) {
            return ['ok' => false, 'error' => 'Código incorrecto.'];
        }

        $this->deleteCode($projectId, $identifier);
        return ['ok' => true];
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function store(string $projectId, string $identifier, string $code): void
    {
        $this->db->prepare(
            'DELETE FROM otp_codes WHERE project_id = :project AND identifier = :id'
        )->execute([':project' => $projectId, ':id' => $identifier]);

        $this->db->prepare(
            'INSERT INTO otp_codes (project_id, identifier, code, created_at, attempts)
             VALUES (:project, :id, :code, :created, 0)'
        )->execute([
            ':project' => $projectId,
            ':id'      => $identifier,
            ':code'    => $code,
            ':created' => date('Y-m-d H:i:s'),
        ]);
    }

    private function isRateLimited(string $identifier): bool
    {
        $driver   = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $timeExpr = $driver === 'mysql'
            ? 'DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
            : 'DATETIME("now", "-15 minutes")';
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM otp_codes WHERE identifier = :id AND created_at > ' . $timeExpr
        );
        $stmt->execute([':id' => $identifier]);
        return (int) $stmt->fetchColumn() >= 3;
    }

    private function incrementAttempts(string $projectId, string $identifier): void
    {
        $this->db->prepare(
            'UPDATE otp_codes SET attempts = attempts + 1
             WHERE project_id = :project AND identifier = :id'
        )->execute([':project' => $projectId, ':id' => $identifier]);
    }

    private function deleteCode(string $projectId, string $identifier): void
    {
        $this->db->prepare(
            'DELETE FROM otp_codes WHERE project_id = :project AND identifier = :id'
        )->execute([':project' => $projectId, ':id' => $identifier]);
    }

    private function deliverEmail(string $to, string $code): bool
    {
        $host     = (string) (getenv('SMTP_HOST')      ?: '');
        $port     = (int)    (getenv('SMTP_PORT')      ?: 587);
        $user     = (string) (getenv('SMTP_USER')      ?: '');
        $pass     = (string) (getenv('SMTP_PASS')      ?: '');
        $from     = (string) (getenv('SMTP_FROM')      ?: $user);
        $fromName = (string) (getenv('SMTP_FROM_NAME') ?: 'SUKI ERP');

        if ($host === '' || $user === '' || $pass === '') {
            return false;
        }

        $subject = 'Tu código de verificación SUKI: ' . $code;
        $body    = $this->buildEmailBody($code);
        $headers = implode("\r\n", [
            'From: ' . $fromName . ' <' . $from . '>',
            'To: ' . $to,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ]);

        try {
            $isLocal = strtolower((string) (getenv('APP_ENV') ?: 'production')) === 'local';
            $sslOpts = ['verify_peer' => !$isLocal, 'verify_peer_name' => !$isLocal];
            if ($isLocal) {
                $sslOpts['allow_self_signed'] = true;
            } elseif ($cafile = (string) (getenv('SSL_CAFILE') ?: '')) {
                $sslOpts['cafile'] = $cafile;
            }
            $context = stream_context_create(['ssl' => $sslOpts]);
            $proto  = $port === 465 ? 'ssl://' : '';
            $socket = stream_socket_client($proto . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) { return false; }
            stream_set_timeout($socket, 10);

            $read = fgets($socket, 512);
            if (!str_starts_with((string) $read, '220')) { fclose($socket); return false; }

            $domain = gethostname() ?: 'localhost';
            fwrite($socket, 'EHLO ' . $domain . "\r\n");
            while ($line = fgets($socket, 512)) { if (substr($line, 3, 1) === ' ') { break; } }

            if ($port !== 465) {
                fwrite($socket, "STARTTLS\r\n");
                $tls = fgets($socket, 512);
                if (!str_starts_with((string) $tls, '220')) { fclose($socket); return false; }
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                fwrite($socket, 'EHLO ' . $domain . "\r\n");
                while ($line = fgets($socket, 512)) { if (substr($line, 3, 1) === ' ') { break; } }
            }

            fwrite($socket, "AUTH LOGIN\r\n");          fgets($socket, 512);
            fwrite($socket, base64_encode($user) . "\r\n"); fgets($socket, 512);
            fwrite($socket, base64_encode($pass) . "\r\n");
            $auth = fgets($socket, 512);
            if (!str_starts_with((string) $auth, '235')) { fclose($socket); return false; }

            fwrite($socket, 'MAIL FROM:<' . $from . ">\r\n"); fgets($socket, 512);
            fwrite($socket, 'RCPT TO:<' . $to . ">\r\n");    fgets($socket, 512);
            fwrite($socket, "DATA\r\n");                       fgets($socket, 512);
            fwrite($socket, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
            $sent = fgets($socket, 512);
            fwrite($socket, "QUIT\r\n");
            fclose($socket);

            return str_starts_with((string) $sent, '250');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function buildEmailBody(string $code): string
    {
        return <<<HTML
<!DOCTYPE html><html lang="es"><body style="font-family:Arial,sans-serif;background:#F0F9FF;padding:40px 20px">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:40px;border:1px solid #BAE6FD">
  <h2 style="color:#0891B2;margin-bottom:8px">SUKI ERP</h2>
  <p style="color:#64748B;font-size:14px;margin-bottom:24px">Verificación de identidad</p>
  <p style="color:#0F172A;font-size:15px">Tu código de verificación es:</p>
  <div style="font-size:36px;font-weight:800;letter-spacing:8px;color:#0891B2;padding:20px 0;text-align:center">{$code}</div>
  <p style="color:#64748B;font-size:13px">Válido por 15 minutos. No lo compartas con nadie.</p>
  <p style="color:#94A3B8;font-size:12px;margin-top:24px">Si no solicitaste este código, ignora este mensaje.</p>
</div>
</body></html>
HTML;
    }

    private function logFallback(string $identifier, string $code): void
    {
        $logDir = $this->projectRoot . '/storage/logs';
        if (!is_dir($logDir)) { return; }
        $line = date('Y-m-d H:i:s') . ' | OTP_DEV | id=' . $identifier . ' | code=' . $code . PHP_EOL;
        file_put_contents($logDir . '/otp_dev.log', $line, FILE_APPEND | LOCK_EX);
    }

    private function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS otp_codes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id  TEXT NOT NULL,
            identifier  TEXT NOT NULL,
            code        TEXT NOT NULL,
            attempts    INTEGER NOT NULL DEFAULT 0,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec(
            "CREATE INDEX IF NOT EXISTS idx_otp_identifier ON otp_codes (identifier, project_id)"
        );
    }
}
