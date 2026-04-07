<?php
/**
 * Plantilla: Nota Crédito / Nota Débito
 * Variables: $company, $data, $user_role, $rendered_at
 */
$e      = fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmtCur = fn(mixed $v): string => '$ ' . number_format((float)($v ?? 0), 2, ',', '.');

$isDebit   = (($data['document_type'] ?? '') === 'debit_note');
$docLabel  = $isDebit ? 'NOTA DÉBITO' : 'NOTA CRÉDITO';
$docNum    = $e($data['document_number'] ?? $data['id'] ?? 'NC-BORRADOR');
$issueDate = $e($data['issue_date'] ?? date('Y-m-d'));
$meta      = is_array($data['metadata'] ?? null) ? (array)$data['metadata'] : [];
$refs      = is_array($meta['references'] ?? null) ? (array)$meta['references'] : [];
$origFact  = $e($refs['sale_number'] ?? $refs['invoice_number'] ?? '—');
$reason    = $e($refs['reason'] ?? $meta['reason'] ?? '');
$cufe      = $e($data['cufe'] ?? '');
$qrUrl     = $e($data['qr_url'] ?? '');
$status    = strtolower((string)($data['status'] ?? 'draft'));
$lines     = is_array($data['lines'] ?? null) ? (array)$data['lines'] : [];
$subtotal  = (float)($data['subtotal'] ?? 0);
$taxTotal  = (float)($data['tax_total']  ?? 0);
$total     = (float)($data['total'] ?? 0);

$statusLabel = ['draft'=>'Borrador','pending'=>'Pendiente','accepted'=>'Aceptada DIAN ✓','canceled'=>'Anulada'][$status] ?? $status;
$statusClass = in_array($status, ['accepted']) ? 'status-accepted' : (in_array($status, ['canceled']) ? 'status-canceled' : 'status-pending');

$data['_doc_title'] = $docLabel . ' ' . $docNum;
include __DIR__ . '/layout_base.php';
$logo = $company['logo_base64'] ?? '';
?>

<div class="doc-header">
  <div class="company-block">
    <?php if ($logo !== ''): ?><img src="<?= $logo ?>" class="company-logo" style="margin-bottom:6px;display:block;" alt="Logo"><?php endif; ?>
    <div class="company-name"><?= $e($company['company_name'] ?? '') ?></div>
    <div class="company-meta">NIT: <strong><?= $e($company['nit'] ?? '---') ?></strong><br><?= $e($company['address'] ?? '') ?></div>
  </div>
  <div class="doc-title-block">
    <div class="doc-badge" style="<?= $isDebit ? 'background:#f59e0b' : '' ?>"><?= $e($docLabel) ?></div>
    <div class="doc-number"><?= $docNum ?></div>
    <div class="doc-date">Fecha: <strong><?= $issueDate ?></strong></div>
    <div style="margin-top:6px;"><span class="status-badge <?= $statusClass ?>"><?= $e($statusLabel) ?></span></div>
  </div>
</div>

<!-- Referencia a factura original -->
<div class="notes-box" style="background:#fef3c7;border-color:#f59e0b;">
  <h4 style="color:#b45309;">📎 Referencia al Documento Original</h4>
  <strong>Factura/Documento Original:</strong> <?= $origFact ?>
  <?php if ($reason !== ''): ?><br><strong>Motivo:</strong> <?= $reason ?><?php endif; ?>
</div>

<div class="parties">
  <div class="party-box">
    <h3>Emisor</h3>
    <p><strong><?= $e($company['company_name'] ?? '') ?></strong><br>NIT: <?= $e($company['nit'] ?? '---') ?><br><?= $e($company['address'] ?? '') ?></p>
  </div>
  <div class="party-box">
    <h3>Cliente</h3>
    <?php $custMeta = is_array($meta['customer'] ?? null) ? (array)$meta['customer'] : []; ?>
    <p><strong><?= $e($custMeta['name'] ?? $data['receiver_party_id'] ?? '—') ?></strong><br>
       <?= ($custMeta['nit'] ?? '') !== '' ? 'NIT: ' . $e($custMeta['nit']) : '' ?></p>
  </div>
</div>

<table class="items-table">
  <thead><tr>
    <th>#</th><th>Descripción</th>
    <th class="right">Cant.</th><th class="right">Precio Unit.</th>
    <th class="right">IVA %</th><th class="right">Total</th>
  </tr></thead>
  <tbody>
    <?php if (empty($lines)): ?>
    <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:16px;">Sin ítems</td></tr>
    <?php endif; ?>
    <?php foreach ($lines as $i => $line): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= $e($line['description'] ?? $line['name'] ?? $line['item_name'] ?? '') ?></td>
      <td class="right"><?= number_format((float)($line['quantity'] ?? 1), 0) ?></td>
      <td class="right"><?= $fmtCur($line['unit_price'] ?? $line['price'] ?? 0) ?></td>
      <td class="right"><?= number_format((float)($line['tax_rate'] ?? $line['iva_rate'] ?? 0), 0) ?>%</td>
      <td class="right"><?= $fmtCur($line['line_total'] ?? $line['total'] ?? 0) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="totals-wrap">
  <table class="totals-table">
    <tr><td>Subtotal</td><td><?= $fmtCur($subtotal) ?></td></tr>
    <?php if ($taxTotal > 0): ?><tr><td>IVA</td><td><?= $fmtCur($taxTotal) ?></td></tr><?php endif; ?>
    <tr class="total-row"><td><?= $isDebit ? 'VALOR A DÉBITAR' : 'VALOR A ACREDITAR' ?></td><td><?= $fmtCur($total) ?></td></tr>
  </table>
</div>

<?php if ($cufe !== '' || $qrUrl !== ''): ?>
<div class="fiscal-bar">
  <?php if ($qrUrl !== ''): ?><div class="qr-block"><img src="<?= $qrUrl ?>" alt="QR DIAN" width="80"></div><?php endif; ?>
  <div class="cufe-block">
    <label>CUFE</label><span><?= $cufe ?></span>
    <div style="margin-top:4px;font-size:9px;color:#6b7280;">Documento electrónico validado por Alanube — DIAN Colombia</div>
  </div>
</div>
<?php endif; ?>

<div class="doc-footer"><?= $e($company['document_footer'] ?? '') ?><br><?= $e($company['company_name'] ?? '') ?> — NIT: <?= $e($company['nit'] ?? '---') ?></div>
</body></html>
