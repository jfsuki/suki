<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Genera tickets/recibos NO electrónicos para tenants que:
 *  a) No han configurado Alanube, o
 *  b) Han elegido explícitamente fiscal_mode=ticket (no requieren FE DIAN).
 *
 * El ticket se registra en fiscal_documents con status='ticket'.
 * No tiene validez fiscal DIAN — incluye leyenda obligatoria.
 */
final class NonElectronicReceiptService
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Genera un ticket a partir de un documento ya preparado en fiscal_documents.
     *
     * @return array{ok: bool, mode: string, document_id: string, ticket_number: string, html: string, message: string}
     */
    public function generateFromDocument(
        string $tenantId,
        string $documentId,
        ?string $appId = null
    ): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM fiscal_documents WHERE tenant_id = :tid AND id = :did' .
            ($appId !== null ? ' AND app_id = :aid' : '') . ' LIMIT 1'
        );
        $params = [':tid' => $tenantId, ':did' => $documentId];
        if ($appId !== null) {
            $params[':aid'] = $appId;
        }
        $stmt->execute($params);
        $document = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$document) {
            return ['ok' => false, 'mode' => 'ticket', 'document_id' => $documentId,
                    'ticket_number' => '', 'html' => '', 'message' => 'Documento no encontrado'];
        }

        $payload = is_string($document['payload'] ?? null)
            ? (array) json_decode((string) $document['payload'], true)
            : (array) ($document['payload'] ?? []);

        $ticketNumber = $this->nextTicketNumber($tenantId, $appId ?? 'default');
        $html         = $this->buildHtml($ticketNumber, $tenantId, $payload);

        $this->db->prepare(
            'UPDATE fiscal_documents SET status = :s, ticket_number = :tn, ticket_html = :th,
             updated_at = :ua WHERE tenant_id = :tid AND id = :did'
        )->execute([
            ':s'   => 'ticket',
            ':tn'  => $ticketNumber,
            ':th'  => $html,
            ':ua'  => date('Y-m-d H:i:s'),
            ':tid' => $tenantId,
            ':did' => $documentId,
        ]);

        return [
            'ok'            => true,
            'mode'          => 'ticket',
            'document_id'   => $documentId,
            'ticket_number' => $ticketNumber,
            'html'          => $html,
            'message'       => 'Ticket #' . $ticketNumber . ' generado. No equivale a factura electrónica DIAN.',
        ];
    }

    /**
     * Genera un ticket directo desde payload (sin documento previo en DB).
     * Útil para POS rápido sin flujo fiscal completo.
     *
     * @param array<string,mixed> $payload { customer?, lines[], subtotal, tax, total, ... }
     * @return array{ok: bool, mode: string, ticket_number: string, html: string, message: string}
     */
    public function generateDirect(string $tenantId, array $payload, ?string $appId = null): array
    {
        $ticketNumber = $this->nextTicketNumber($tenantId, $appId ?? 'default');
        $html         = $this->buildHtml($ticketNumber, $tenantId, $payload);

        return [
            'ok'            => true,
            'mode'          => 'ticket',
            'ticket_number' => $ticketNumber,
            'html'          => $html,
            'message'       => 'Ticket #' . $ticketNumber . ' generado. No equivale a factura electrónica DIAN.',
        ];
    }

    // -------------------------------------------------------------------------

    private function nextTicketNumber(string $tenantId, string $appId): string
    {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $this->db->exec("CREATE TABLE IF NOT EXISTS ticket_sequences (
                tenant_id VARCHAR(64) NOT NULL,
                app_id    VARCHAR(64) NOT NULL DEFAULT 'default',
                seq       INT         NOT NULL DEFAULT 0,
                PRIMARY KEY (tenant_id, app_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->db->prepare(
                'INSERT INTO ticket_sequences (tenant_id, app_id, seq) VALUES (:t, :a, 1)
                 ON DUPLICATE KEY UPDATE seq = seq + 1'
            )->execute([':t' => $tenantId, ':a' => $appId]);
        } else {
            $this->db->exec("CREATE TABLE IF NOT EXISTS ticket_sequences (
                tenant_id TEXT NOT NULL,
                app_id    TEXT NOT NULL DEFAULT 'default',
                seq       INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (tenant_id, app_id)
            )");
            $exists = $this->db->prepare(
                'SELECT seq FROM ticket_sequences WHERE tenant_id=:t AND app_id=:a'
            );
            $exists->execute([':t' => $tenantId, ':a' => $appId]);
            if ($exists->fetchColumn() !== false) {
                $this->db->prepare(
                    'UPDATE ticket_sequences SET seq = seq + 1 WHERE tenant_id=:t AND app_id=:a'
                )->execute([':t' => $tenantId, ':a' => $appId]);
            } else {
                $this->db->prepare(
                    'INSERT INTO ticket_sequences (tenant_id, app_id, seq) VALUES (:t, :a, 1)'
                )->execute([':t' => $tenantId, ':a' => $appId]);
            }
        }

        $stmt = $this->db->prepare(
            'SELECT seq FROM ticket_sequences WHERE tenant_id=:t AND app_id=:a'
        );
        $stmt->execute([':t' => $tenantId, ':a' => $appId]);
        $seq = (int) ($stmt->fetchColumn() ?: 1);

        return 'TKT-' . date('Ymd') . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string,mixed> $payload */
    private function buildHtml(string $ticketNumber, string $tenantId, array $payload): string
    {
        $date     = date('d/m/Y H:i');
        $customer = htmlspecialchars((string) ($payload['customer_name'] ?? $payload['customer'] ?? 'Consumidor final'));
        $lines    = is_array($payload['lines'] ?? null) ? (array) $payload['lines'] : [];
        $subtotal = number_format((float) ($payload['subtotal'] ?? 0), 2, ',', '.');
        $tax      = number_format((float) ($payload['tax'] ?? $payload['iva'] ?? 0), 2, ',', '.');
        $total    = number_format((float) ($payload['total'] ?? 0), 2, ',', '.');
        $currency = htmlspecialchars((string) ($payload['currency'] ?? 'COP'));
        $tid      = htmlspecialchars($tenantId);

        $lineRows = '';
        foreach ($lines as $line) {
            $desc  = htmlspecialchars((string) ($line['description'] ?? $line['name'] ?? ''));
            $qty   = htmlspecialchars((string) ($line['quantity'] ?? $line['qty'] ?? '1'));
            $price = number_format((float) ($line['unit_price'] ?? $line['price'] ?? 0), 2, ',', '.');
            $amt   = number_format((float) ($line['amount'] ?? $line['total'] ?? 0), 2, ',', '.');
            $lineRows .= "<tr><td>{$desc}</td><td style='text-align:center'>{$qty}</td>"
                       . "<td style='text-align:right'>{$price}</td><td style='text-align:right'>{$amt}</td></tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ticket {$ticketNumber}</title>
<style>
  body{font-family:'Courier New',monospace;max-width:360px;margin:0 auto;padding:20px;font-size:12px;color:#1e293b}
  .header{text-align:center;border-bottom:2px dashed #94a3b8;padding-bottom:12px;margin-bottom:12px}
  .header h2{font-size:16px;margin:0 0 4px}
  .meta{display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:12px}
  table{width:100%;border-collapse:collapse;font-size:11px}
  th{border-bottom:1px solid #94a3b8;padding:4px 2px;text-align:left;font-size:10px;text-transform:uppercase}
  td{padding:4px 2px}
  .totals{border-top:2px dashed #94a3b8;margin-top:8px;padding-top:8px}
  .totals tr td:first-child{font-weight:bold}
  .totals tr td:last-child{text-align:right}
  .total-row td{font-size:14px;font-weight:800;color:#0891b2}
  .footer{margin-top:16px;text-align:center;font-size:9px;color:#94a3b8;border-top:1px dashed #94a3b8;padding-top:10px}
  .disclaimer{background:#fef9c3;border:1px solid #fde047;padding:8px;margin-top:10px;font-size:9px;text-align:center;color:#713f12;border-radius:4px}
  @media print{body{max-width:100%}.disclaimer{background:#fef9c3!important;-webkit-print-color-adjust:exact}}
</style>
</head>
<body>
<div class="header">
  <h2>SUKI ERP</h2>
  <div style="font-size:11px;color:#64748b">{$tid}</div>
  <div style="font-size:13px;font-weight:bold;margin-top:6px">{$ticketNumber}</div>
</div>
<div class="meta">
  <span>Cliente: {$customer}</span>
  <span>{$date}</span>
</div>
<table>
  <thead><tr><th>Descripción</th><th style="text-align:center">Cant</th><th style="text-align:right">Precio</th><th style="text-align:right">Total</th></tr></thead>
  <tbody>{$lineRows}</tbody>
</table>
<table class="totals">
  <tr><td>Subtotal</td><td>{$subtotal} {$currency}</td></tr>
  <tr><td>IVA</td><td>{$tax} {$currency}</td></tr>
  <tr class="total-row"><td>TOTAL</td><td>{$total} {$currency}</td></tr>
</table>
<div class="disclaimer">
  ⚠️ ESTE DOCUMENTO NO EQUIVALE A FACTURA ELECTRÓNICA DIAN.<br>
  Para factura electrónica, solicítela al establecimiento.
</div>
<div class="footer">
  Generado por SUKI ERP · {$date}<br>
  Conserve este documento como soporte de su compra.
</div>
</body>
</html>
HTML;
    }
}
