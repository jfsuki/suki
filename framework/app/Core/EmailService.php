<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Servicio de envío de correo electrónico para SUKI ERP.
 *
 * Usa PHP mail() nativo (compatible con cPanel/hosting compartido).
 * Configura SMTP a través de variables de entorno para instalaciones avanzadas.
 *
 * Variables .env requeridas (opcionales para mail() nativo):
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME
 *   SMTP_SECURE: tls|ssl|'' (vacío = sin cifrado)
 *   USE_SMTP: 1|0 (default 0 = usa PHP mail())
 *
 * Límites: No envía documentos en adjunto (evita problemas de base64 en hosting).
 * Siempre envía un LINK al documento (login-protected URL).
 */
final class EmailService
{
    /** Tiempo máximo de espera para conexión SMTP (socket nativo) */
    private const SMTP_TIMEOUT = 15;

    /** Tamaño máximo del cuerpo HTML en bytes antes de truncar (1 MB) */
    private const MAX_BODY_BYTES = 1_048_576;

    private BusinessConfigService $configService;

    public function __construct(?BusinessConfigService $configService = null)
    {
        $this->configService = $configService ?? new BusinessConfigService();
    }

    // ─── Envío de Link de Documento ───────────────────────────────────────────

    /**
     * Envía un email con el link al documento (factura, OC, remisión, etc.).
     * El link requiere login — el documento nunca viaja como adjunto.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function sendDocumentLink(string $tenantId, array $params): array
    {
        $to       = $this->requireEmail($params['to'] ?? '');
        $docType  = (string) ($params['doc_type'] ?? 'document');
        $docNum   = (string) ($params['doc_number'] ?? '');
        $docUrl   = (string) ($params['doc_url'] ?? '');
        $toName   = (string) ($params['to_name'] ?? '');
        $message  = (string) ($params['message'] ?? '');

        if ($docUrl === '') {
            throw new RuntimeException('EMAIL_DOC_URL_REQUIRED');
        }

        $company = $this->configService->getConfig($tenantId);
        $companyName = (string) ($company['display_name'] ?? $company['company_name'] ?? 'SUKI ERP');
        $primaryColor = (string) ($company['primary_color'] ?? '#1a56db');

        $docLabel = $this->docTypeLabel($docType);
        $subject  = "{$companyName} — {$docLabel}" . ($docNum !== '' ? " N° {$docNum}" : '');

        $htmlBody = $this->buildDocumentLinkEmail([
            'company_name'  => $companyName,
            'primary_color' => $primaryColor,
            'doc_label'     => $docLabel,
            'doc_number'    => $docNum,
            'doc_url'       => $docUrl,
            'to_name'       => $toName,
            'custom_message'=> $message,
            'company_email' => (string) ($company['email'] ?? ''),
            'company_phone' => (string) ($company['phone'] ?? ''),
        ]);

        return $this->send($to, $subject, $htmlBody, $tenantId);
    }

    /**
     * Envío de notificación simple (alertas, confirmaciones, etc.).
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function sendNotification(string $tenantId, array $params): array
    {
        $to      = $this->requireEmail($params['to'] ?? '');
        $subject = (string) ($params['subject'] ?? 'Notificación de SUKI');
        $body    = (string) ($params['body'] ?? '');
        $toName  = (string) ($params['to_name'] ?? '');

        if ($body === '') {
            throw new RuntimeException('EMAIL_BODY_REQUIRED');
        }

        $company = $this->configService->getConfig($tenantId);
        $companyName = (string) ($company['display_name'] ?? 'SUKI ERP');
        $primaryColor = (string) ($company['primary_color'] ?? '#1a56db');

        $htmlBody = $this->buildNotificationEmail([
            'company_name'  => $companyName,
            'primary_color' => $primaryColor,
            'to_name'       => $toName,
            'body'          => $body,
            'company_email' => (string) ($company['email'] ?? ''),
        ]);

        return $this->send($to, $subject, $htmlBody, $tenantId);
    }

    // ─── Núcleo de envío ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function send(string $to, string $subject, string $htmlBody, string $tenantId): array
    {
        $company = $this->configService->getConfig($tenantId);
        
        // Use SMTP if explicitly set in .env OR if tenant has a host configured
        $useSmtp = (bool) ((int) (getenv('USE_SMTP') ?: '0')) 
                || (($company['smtp_host'] ?? '') !== '');

        if ($useSmtp) {
            return $this->sendSmtp($to, $subject, $htmlBody, $company);
        }

        return $this->sendNativeMail($to, $subject, $htmlBody, $company);
    }

    /**
     * Envío usando PHP mail() nativo — funciona en cPanel sin dependencias.
     * @return array<string, mixed>
     */
    private function sendNativeMail(string $to, string $subject, string $htmlBody, array $company = []): array
    {
        $fromEmail = $this->fromEmail($company);
        $fromName  = $this->fromName($company);
        $boundary  = md5(uniqid((string) rand(), true));

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "X-Mailer: SUKI-ERP/1.0\r\n";

        if (strlen($htmlBody) > self::MAX_BODY_BYTES) {
            $htmlBody = substr($htmlBody, 0, self::MAX_BODY_BYTES);
        }

        $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);

        return [
            'ok'       => $sent,
            'to'       => $to,
            'subject'  => $subject,
            'method'   => 'php_mail',
            'message'  => $sent ? 'Email enviado correctamente.' : 'Error al enviar el email — revisar configuración del servidor.',
        ];
    }

    /**
     * Envío SMTP nativo (sockets PHP) — sin PHPMailer, compatible con cPanel.
     * @return array<string, mixed>
     */
    private function sendSmtp(string $to, string $subject, string $htmlBody, array $company = []): array
    {
        $host     = (string) (($company['smtp_host'] ?? '') !== '' ? $company['smtp_host'] : (getenv('SMTP_HOST') ?: 'localhost'));
        $port     = (int)    (($company['smtp_port'] ?? 0)  > 0   ? $company['smtp_port'] : (getenv('SMTP_PORT') ?: 587));
        $user     = (string) (($company['smtp_user'] ?? '') !== '' ? $company['smtp_user'] : (getenv('SMTP_USER') ?: ''));
        $pass     = (string) (($company['smtp_pass'] ?? '') !== '' ? $company['smtp_pass'] : (getenv('SMTP_PASS') ?: ''));
        $secure   = strtolower(trim((string) (getenv('SMTP_SECURE') ?: 'tls')));
        $from     = $this->fromEmail($company);
        $fromName = $this->fromName($company);

        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $prefix  = $secure === 'ssl' ? 'ssl://' : '';

        $socket = @stream_socket_client(
            "{$prefix}{$host}:{$port}", $errno, $errstr, self::SMTP_TIMEOUT,
            STREAM_CLIENT_CONNECT, $context
        );

        if ($socket === false) {
            return ['ok' => false, 'method' => 'smtp', 'message' => "SMTP_CONNECT_FAILED: {$errstr} ({$errno})"];
        }

        $read = fn() => (string) fgets($socket, 4096);
        $write = fn(string $cmd) => fwrite($socket, $cmd . "\r\n");

        $read(); // banner

        if ($secure === 'tls') {
            $write('EHLO suki-erp');
            $read();
            $write('STARTTLS');
            $read();
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['ok' => false, 'method' => 'smtp', 'message' => 'SMTP_STARTTLS_FAILED'];
            }
        }

        $write('EHLO suki-erp'); $read();

        if ($user !== '' && $pass !== '') {
            $write('AUTH LOGIN'); $read();
            $write(base64_encode($user)); $read();
            $write(base64_encode($pass));
            $authResp = $read();
            if (!str_starts_with($authResp, '235')) {
                fclose($socket);
                return ['ok' => false, 'method' => 'smtp', 'message' => 'SMTP_AUTH_FAILED'];
            }
        }

        $write("MAIL FROM:<{$from}>"); $read();
        $write("RCPT TO:<{$to}>"); $read();
        $write('DATA'); $read();

        $msgId  = '<' . time() . '.' . rand(1000, 9999) . '@suki-erp>';
        $body   = "From: {$fromName} <{$from}>\r\n"
            . "To: {$to}\r\n"
            . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
            . "Message-ID: {$msgId}\r\n"
            . "Date: " . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody));

        $write($body);
        $write('.');
        $dataResp = $read();

        $write('QUIT');
        fclose($socket);

        $ok = str_starts_with($dataResp, '250');
        return [
            'ok'      => $ok,
            'to'      => $to,
            'subject' => $subject,
            'method'  => 'smtp',
            'message' => $ok ? 'Email enviado por SMTP.' : "SMTP_DATA_ERROR: {$dataResp}",
        ];
    }

    // ─── Templates HTML ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $vars
     */
    private function buildDocumentLinkEmail(array $vars): string
    {
        $_date   = date('d/m/Y H:i');
        $color   = htmlspecialchars((string)($vars['primary_color'] ?? '#1a56db'), ENT_QUOTES);
        $company = htmlspecialchars((string)($vars['company_name'] ?? ''), ENT_QUOTES);
        $label   = htmlspecialchars((string)($vars['doc_label'] ?? 'Documento'), ENT_QUOTES);
        $num     = htmlspecialchars((string)($vars['doc_number'] ?? ''), ENT_QUOTES);
        $url     = htmlspecialchars((string)($vars['doc_url'] ?? '#'), ENT_QUOTES);
        $toName  = htmlspecialchars((string)($vars['to_name'] ?? ''), ENT_QUOTES);
        $msg     = htmlspecialchars((string)($vars['custom_message'] ?? ''), ENT_QUOTES);
        $cEmail  = htmlspecialchars((string)($vars['company_email'] ?? ''), ENT_QUOTES);
        $cPhone  = htmlspecialchars((string)($vars['company_phone'] ?? ''), ENT_QUOTES);
        $cEmail_sep = ($cEmail !== '' && $cPhone !== '') ? ' · ' : '';

        $greeting = $toName !== '' ? "Hola, <strong>{$toName}</strong>:" : 'Estimado cliente:';
        $docTitle = $num !== '' ? "{$label} N° {$num}" : $label;
        $msgBlock = $msg !== '' ? "<p style='color:#374151;margin:0 0 16px;'>{$msg}</p>" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$label} — {$company}</title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
      <!-- Header -->
      <tr><td style="background:{$color};padding:28px 32px;text-align:center;">
        <h1 style="color:#fff;margin:0;font-size:20px;font-weight:700;">{$company}</h1>
        <p style="color:rgba(255,255,255,0.85);margin:4px 0 0;font-size:13px;">{$docTitle}</p>
      </td></tr>
      <!-- Body -->
      <tr><td style="padding:32px;">
        <p style="color:#374151;margin:0 0 8px;font-size:15px;">{$greeting}</p>
        <p style="color:#374151;margin:0 0 20px;">Le informamos que tiene disponible el siguiente documento:</p>
        {$msgBlock}
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;margin-bottom:24px;">
          <p style="margin:0;font-size:14px;color:#111827;"><strong>{$docTitle}</strong></p>
          <p style="margin:6px 0 0;font-size:12px;color:#6b7280;">Generado el: <strong>{$_date}</strong></p>
        </div>
        <div style="text-align:center;margin-bottom:24px;">
          <a href="{$url}" style="background:{$color};color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:700;font-size:14px;display:inline-block;">
            📄 Ver Documento
          </a>
        </div>
        <p style="color:#6b7280;font-size:12px;margin:0 0 8px;">También puede copiar este enlace en su navegador:</p>
        <p style="color:{$color};font-size:11px;word-break:break-all;margin:0;">{$url}</p>
        <p style="color:#9ca3af;font-size:11px;margin:20px 0 0;">⚠ Este enlace requiere inicio de sesión y es de uso exclusivamente interno.</p>
      </td></tr>
      <!-- Footer -->
      <tr><td style="background:#f9fafb;padding:20px 32px;border-top:1px solid #e5e7eb;text-align:center;">
        <p style="color:#9ca3af;font-size:11px;margin:0;">{$company}</p>
        <p style="color:#9ca3af;font-size:11px;margin:4px 0 0;">
          {$cPhone}{$cEmail_sep}{$cEmail}
        </p>
        <p style="color:#9ca3af;font-size:10px;margin:8px 0 0;">Sistema operado por <strong>SUKI ERP</strong></p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function buildNotificationEmail(array $vars): string
    {
        $color   = htmlspecialchars((string)($vars['primary_color'] ?? '#1a56db'), ENT_QUOTES);
        $company = htmlspecialchars((string)($vars['company_name'] ?? 'SUKI'), ENT_QUOTES);
        $toName  = htmlspecialchars((string)($vars['to_name'] ?? ''), ENT_QUOTES);
        $body    = (string)($vars['body'] ?? '');
        $cEmail  = htmlspecialchars((string)($vars['company_email'] ?? ''), ENT_QUOTES);
        $greeting = $toName !== '' ? "Hola, <strong>{$toName}</strong>:" : 'Estimado usuario:';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Notificación — {$company}</title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
      <tr><td style="background:{$color};padding:24px 32px;text-align:center;">
        <h1 style="color:#fff;margin:0;font-size:18px;font-weight:700;">{$company}</h1>
      </td></tr>
      <tr><td style="padding:32px;">
        <p style="color:#374151;margin:0 0 16px;">{$greeting}</p>
        <div style="color:#374151;font-size:14px;line-height:1.7;">{$body}</div>
      </td></tr>
      <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center;">
        <p style="color:#9ca3af;font-size:10px;margin:0;">Sistema SUKI ERP — {$company}</p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function fromEmail(array $company = []): string
    {
        if (($company['email'] ?? '') !== '') return $company['email'];
        $env = trim((string) (getenv('SMTP_FROM') ?: ''));
        return $env !== '' ? $env : ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'suki-erp.app'));
    }

    private function fromName(array $company = []): string
    {
        if (($company['display_name'] ?? '') !== '') return $company['display_name'];
        if (($company['company_name'] ?? '') !== '') return $company['company_name'];
        $env = trim((string) (getenv('SMTP_FROM_NAME') ?: ''));
        return $env !== '' ? $env : 'SUKI ERP';
    }

    private function requireEmail(mixed $value): string
    {
        $email = strtolower(trim((string) $value));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('EMAIL_TO_INVALID');
        }
        return $email;
    }

    private function docTypeLabel(string $type): string
    {
        return match ($type) {
            'invoice'        => 'Factura de Venta',
            'credit_note'    => 'Nota Crédito',
            'debit_note'     => 'Nota Débito',
            'purchase_order' => 'Orden de Compra',
            'remision'       => 'Remisión de Despacho',
            'quotation'      => 'Cotización',
            'report'         => 'Informe / Reporte',
            default          => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
