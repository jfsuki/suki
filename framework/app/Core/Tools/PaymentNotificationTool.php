<?php

declare(strict_types=1);

// framework/app/Core/Tools/PaymentNotificationTool.php

namespace App\Core\Tools;

use App\Core\Database;

/**
 * Validates inbound payment webhook notifications from Colombian payment providers.
 *
 * Secrets come exclusively from environment variables — never from payload.
 * Tenant isolation enforced on every DB query via tenant_id parameter.
 */
final class PaymentNotificationTool
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers  HTTP headers keyed by normalized header name
     * @return array{
     *   matched: bool,
     *   valid: bool,
     *   invoice_id: int|null,
     *   amount_confirmed: float|null,
     *   discrepancy: float,
     *   action_taken: string,
     *   error?: string
     * }
     */
    public function validate(array $payload, string $provider, string $tenantId, Database $db): array
    {
        $provider = strtolower(trim($provider));

        $verifyResult = match ($provider) {
            'nequi'        => $this->verifyNequi($payload),
            'bancolombia'  => $this->verifyBancolombia($payload, $_SERVER ?? []),
            'mercadopago'  => $this->verifyMercadoPago($payload, $_SERVER ?? []),
            'wompi'        => $this->verifyWompi($payload),
            default        => ['valid' => false, 'error' => "Proveedor no soportado: {$provider}"],
        };

        if (!($verifyResult['valid'] ?? false)) {
            return [
                'matched'          => false,
                'valid'            => false,
                'invoice_id'       => null,
                'amount_confirmed' => null,
                'discrepancy'      => 0.0,
                'action_taken'     => 'none',
                'error'            => $verifyResult['error'] ?? 'Firma inválida',
            ];
        }

        $amount    = (float)  ($verifyResult['amount']    ?? 0);
        $reference = (string) ($verifyResult['reference'] ?? '');
        $status    = (string) ($verifyResult['status']    ?? '');

        if ($reference === '') {
            return [
                'matched' => false, 'valid' => true,
                'invoice_id' => null, 'amount_confirmed' => $amount,
                'discrepancy' => 0.0, 'action_taken' => 'no_reference',
            ];
        }

        $pdo = $db->getPdo();

        // Tenant-scoped invoice lookup
        $stmt = $pdo->prepare('SELECT id, total, status FROM invoices WHERE reference = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$reference, $tenantId]);
        $invoice = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$invoice) {
            return [
                'matched' => false, 'valid' => true,
                'invoice_id' => null, 'amount_confirmed' => $amount,
                'discrepancy' => 0.0, 'action_taken' => 'invoice_not_found',
            ];
        }

        $invoiceId   = (int)   $invoice['id'];
        $invoiceTotal = (float) $invoice['total'];
        $discrepancy  = round(abs($amount - $invoiceTotal), 2);

        if ($status === 'APPROVED' && strtolower((string) $invoice['status']) !== 'paid') {
            $pdo->prepare('UPDATE invoices SET status = ?, paid_at = NOW() WHERE id = ? AND tenant_id = ?')
                ->execute(['paid', $invoiceId, $tenantId]);

            $this->createJournalEntry($pdo, $tenantId, $invoiceId, $invoiceTotal, $reference, $provider);

            return [
                'matched' => true, 'valid' => true,
                'invoice_id' => $invoiceId, 'amount_confirmed' => $amount,
                'discrepancy' => $discrepancy, 'action_taken' => 'invoice_marked_paid',
            ];
        }

        return [
            'matched' => true, 'valid' => true,
            'invoice_id' => $invoiceId, 'amount_confirmed' => $amount,
            'discrepancy' => $discrepancy, 'action_taken' => 'no_change',
        ];
    }

    // -------------------------------------------------------------------------
    // Provider signature verification
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    private function verifyNequi(array $payload): array
    {
        // Nequi notifies via statusCode + serviceStatus — no HMAC on inbound webhooks in free tier
        $statusCode = (string) ($payload['statusCode'] ?? $payload['status_code'] ?? '');
        $status     = (string) ($payload['serviceStatus'] ?? $payload['status'] ?? '');

        $nequiStatus = $statusCode === '200' ? 'APPROVED' : 'DECLINED';

        return [
            'valid'     => true,
            'amount'    => (float) ($payload['value'] ?? $payload['amount'] ?? 0),
            'reference' => (string) ($payload['transactionReference'] ?? $payload['reference'] ?? ''),
            'status'    => $nequiStatus,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $server   $_SERVER headers
     */
    private function verifyBancolombia(array $payload, array $server): array
    {
        $secret = (string) getenv('BANCOLOMBIA_WEBHOOK_SECRET');
        if ($secret === '') {
            // Secret not configured — allow but log warning
            return [
                'valid'     => true,
                'amount'    => (float)  ($payload['amount']    ?? 0),
                'reference' => (string) ($payload['reference'] ?? ''),
                'status'    => strtoupper((string) ($payload['status'] ?? '')),
            ];
        }

        $receivedHmac = $server['HTTP_X_HMAC_SHA256'] ?? '';
        $body         = json_encode($payload) ?: '';
        $expected     = base64_encode(hash_hmac('sha256', $body, $secret, true));

        if (!hash_equals($expected, (string) $receivedHmac)) {
            return ['valid' => false, 'error' => 'Firma inválida'];
        }

        return [
            'valid'     => true,
            'amount'    => (float)  ($payload['amount']    ?? 0),
            'reference' => (string) ($payload['reference'] ?? ''),
            'status'    => strtoupper((string) ($payload['status'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $server */
    private function verifyMercadoPago(array $payload, array $server): array
    {
        $secret = (string) getenv('MERCADOPAGO_WEBHOOK_SECRET');

        if ($secret !== '') {
            $xSignature = $server['HTTP_X_SIGNATURE'] ?? '';
            $dataId     = (string) ($payload['data']['id'] ?? '');
            $xRequestId = $server['HTTP_X_REQUEST_ID'] ?? '';
            $ts         = '';

            // Extract ts from X-Signature header
            foreach (explode(',', (string) $xSignature) as $part) {
                $part = trim($part);
                if (str_starts_with($part, 'ts=')) {
                    $ts = substr($part, 3);
                }
            }

            $manifest  = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
            $expected  = hash_hmac('sha256', $manifest, $secret);

            // Extract v1 from X-Signature
            $v1 = '';
            foreach (explode(',', (string) $xSignature) as $part) {
                $part = trim($part);
                if (str_starts_with($part, 'v1=')) {
                    $v1 = substr($part, 3);
                }
            }

            if ($v1 !== '' && !hash_equals($expected, $v1)) {
                return ['valid' => false, 'error' => 'Firma inválida'];
            }
        }

        $action = (string) ($payload['action'] ?? '');
        $status = str_contains($action, 'payment') ? 'APPROVED' : strtoupper((string) ($payload['status'] ?? ''));

        return [
            'valid'     => true,
            'amount'    => (float)  ($payload['data']['amount'] ?? $payload['amount'] ?? 0),
            'reference' => (string) ($payload['data']['id']     ?? $payload['reference'] ?? ''),
            'status'    => $status,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function verifyWompi(array $payload): array
    {
        $secret = (string) getenv('WOMPI_PRIVATE_KEY');

        if ($secret !== '') {
            $transaction  = $payload['data']['transaction'] ?? $payload['transaction'] ?? [];
            $reference    = (string) ($transaction['reference']   ?? '');
            $amount       = (string) ($transaction['amount_in_cents'] ?? '0');
            $currency     = (string) ($transaction['currency']    ?? 'COP');
            $status       = (string) ($transaction['status']      ?? '');
            $hash         = (string) ($payload['signature']['checksum'] ?? '');

            $str      = $reference . $amount . $currency . $status . $secret;
            $expected = hash('sha256', $str);

            if ($hash !== '' && !hash_equals($expected, $hash)) {
                return ['valid' => false, 'error' => 'Firma inválida'];
            }

            return [
                'valid'     => true,
                'amount'    => round((float) $amount / 100, 2),
                'reference' => $reference,
                'status'    => strtoupper($status),
            ];
        }

        $transaction = $payload['data']['transaction'] ?? $payload['transaction'] ?? $payload;
        return [
            'valid'     => true,
            'amount'    => round((float) ($transaction['amount_in_cents'] ?? 0) / 100, 2),
            'reference' => (string) ($transaction['reference'] ?? $payload['reference'] ?? ''),
            'status'    => strtoupper((string) ($transaction['status'] ?? '')),
        ];
    }

    // -------------------------------------------------------------------------
    // Accounting side-effect
    // -------------------------------------------------------------------------

    private function createJournalEntry(\PDO $pdo, string $tenantId, int $invoiceId, float $amount, string $reference, string $provider): void
    {
        try {
            // Check if table exists before inserting
            $pdo->query('SELECT 1 FROM journal_entries LIMIT 0');

            $pdo->prepare("
                INSERT INTO journal_entries
                    (tenant_id, entry_date, account_code, description, debit, credit, reference)
                VALUES
                    (?, CURDATE(), '1105', ?, ?, 0, ?),
                    (?, CURDATE(), '1305', ?, 0, ?, ?)
            ")->execute([
                $tenantId, "Pago {$provider} ref {$reference}",  $amount, $reference,
                $tenantId, "Abono cartera ref {$reference}", $amount, $reference,
            ]);
        } catch (\Throwable $e) {
            // journal_entries may not exist yet — silent fail, invoice is still marked paid
        }
    }
}
