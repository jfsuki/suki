<?php
/**
 * Plantilla: Factura de Venta / Factura Electrónica
 * Cumple con representación gráfica DIAN Colombia.
 *
 * CUFE y QR vienen de Alanube:
 *   - Alanube POST /documents → retorna cufe, qr_url, pdf_url en la respuesta
 *   - SUKI guarda en fiscal_document.metadata['alanube_response']
 *   - DocumentRenderer extrae: $data['cufe'], $data['qr_url']
 *
 * Variables: $company, $data, $user_role, $rendered_at
 */

$e      = fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmtCur = fn(mixed $v): string => '$ ' . number_format((float)($v ?? 0), 2, ',', '.');
$fmtNum = fn(mixed $v): string => number_format((float)($v ?? 0), 0, ',', '.');

// Datos del documento
$docNum    = $e(($data['document_number'] ?? '') !== '' ? $data['document_number'] : ($data['id'] ?? 'BORRADOR'));
$issueDate = $e($data['issue_date'] ?? date('Y-m-d'));
$status    = strtolower((string)($data['status'] ?? 'draft'));
$docType   = ($data['document_type'] ?? 'sales_invoice') === 'pos_ticket_fiscal_hook' ? 'TIQUETE POS' : 'FACTURA DE VENTA';
$cufe      = $e($data['cufe'] ?? '');
$qrUrl     = $e($data['qr_url'] ?? '');
$currency  = $e($company['currency'] ?? 'COP');

// Resolución DIAN
$dianRes   = $e($company['dian_resolution'] ?? '');
$dianFrom  = $e((string)($company['dian_from_number'] ?? ''));
$dianTo    = $e((string)($company['dian_to_number'] ?? ''));
$dianFrom2 = $e($company['dian_valid_from'] ?? '');
$dianTo2   = $e($company['dian_valid_until'] ?? '');
$prefix    = $e($company['dian_prefix'] ?? '');

// Partes
$meta = is_array($data['metadata'] ?? null) ? (array)$data['metadata'] : [];
$sourceSnap = is_array($meta['source_snapshot'] ?? null) ? (array)$meta['source_snapshot'] : [];
$buyer  = $data['receiver_party_id'] ?? ($sourceSnap['customer_name'] ?? '');
$lines  = is_array($data['lines'] ?? null) ? (array)$data['lines'] : [];
$subtotal = (float)($data['subtotal'] ?? 0);
$taxTotal = (float)($data['tax_total'] ?? 0);
$total    = (float)($data['total'] ?? 0);

$data['_doc_title'] = $docType . ' ' . $docNum;

// Incluir layout base
include __DIR__ . '/layout_base.php';

$statusClass = match($status) {
    'accepted'  => 'status-accepted',
    'pending', 'prepared', 'submitted' => 'status-pending',
    'canceled'  => 'status-canceled',
    default     => 'status-draft',
};
$statusLabel = [
    'draft'     => 'Borrador',
    'pending'   => 'Pendiente',
    'prepared'  => 'Preparada',
    'submitted' => 'Enviada DIAN',
    'accepted'  => 'Aceptada DIAN ✓',
    'rejected'  => 'Rechazada',
    'canceled'  => 'Anulada',
][$status] ?? strtoupper($status);

$logo    = $company['logo_base64'] ?? '';
$logoPath = $company['logo_path'] ?? '';
?>

<!-- ── Header del documento ─────────────────────────────────── -->
<div class="doc-header">
  <div class="company-block">
    <?php if ($logo !== ''): ?>
      <img src="<?= $logo ?>" alt="Logo" class="company-logo" style="margin-bottom:6px;display:block;">
    <?php elseif ($logoPath !== '' && is_file($logoPath)): ?>
      <img src="data:image/png;base64,<?= base64_encode(file_get_contents($logoPath)) ?>" alt="Logo" class="company-logo" style="margin-bottom:6px;display:block;">
    <?php endif; ?>
    <div class="company-name"><?= $e($company['company_name'] ?? '') ?></div>
    <?php if (($company['trade_name'] ?? '') !== ''): ?>
      <div class="company-meta"><?= $e($company['trade_name']) ?></div>
    <?php endif; ?>
    <div class="company-meta">
      NIT: <strong><?= $e($company['nit'] ?? '---') ?></strong><br>
      <?= $e($company['address'] ?? '') ?><?= ($company['city'] ?? '') !== '' ? ', ' . $e($company['city']) : '' ?><br>
      <?= ($company['phone'] ?? '') !== '' ? '📞 ' . $e($company['phone']) : '' ?>
      <?= ($company['email'] ?? '') !== '' ? '&nbsp; ✉ ' . $e($company['email']) : '' ?><br>
      <?= ($company['tax_regime'] ?? '') !== '' ? 'Régimen: ' . $e($company['tax_regime']) : '' ?>
    </div>
  </div>
  <div class="doc-title-block">
    <div class="doc-badge"><?= $e($docType) ?></div>
    <div class="doc-number"><?= $prefix !== '' ? $prefix . '-' : '' ?><?= $docNum ?></div>
    <div class="doc-date">Fecha: <strong><?= $issueDate ?></strong></div>
    <div style="margin-top:6px;">
      <span class="status-badge <?= $statusClass ?>"><?= $e($statusLabel) ?></span>
    </div>
    <?php if ($dianRes !== ''): ?>
    <div style="font-size:9px;color:#6b7280;margin-top:4px;">
      Res. DIAN N° <?= $dianRes ?><br>
      Rango: <?= $prefix ?><?= $dianFrom ?>–<?= $prefix ?><?= $dianTo ?><br>
      Vigencia: <?= $dianFrom2 ?> al <?= $dianTo2 ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Partes: Emisor / Cliente ──────────────────────────────── -->
<div class="parties">
  <div class="party-box">
    <h3>Emisor</h3>
    <p>
      <strong><?= $e($company['company_name'] ?? '') ?></strong><br>
      NIT: <?= $e($company['nit'] ?? '---') ?><br>
      <?= $e($company['address'] ?? '') ?><br>
      <?= $e($company['city'] ?? '') ?><?= ($company['department'] ?? '') !== '' ? ', ' . $e($company['department']) : '' ?> — <?= $e($company['country'] ?? 'Colombia') ?><br>
      <?= $e($company['tax_regime'] ?? '') ?>
    </p>
  </div>
  <div class="party-box">
    <h3>Cliente / Receptor</h3>
    <?php
    $custMeta = is_array($meta['customer'] ?? null) ? (array)$meta['customer'] : [];
    $custName = $e($custMeta['name'] ?? ($data['receiver_party_id'] ?? 'Sin identificar'));
    $custNit  = $e($custMeta['nit'] ?? $custMeta['id_number'] ?? '');
    $custAddr = $e($custMeta['address'] ?? '');
    $custCity = $e($custMeta['city'] ?? '');
    ?>
    <p>
      <strong><?= $custName ?></strong><br>
      <?= $custNit !== '' ? 'NIT/CC: ' . $custNit . '<br>' : '' ?>
      <?= $custAddr !== '' ? $custAddr . '<br>' : '' ?>
      <?= $custCity ?>
    </p>
  </div>
</div>

<!-- ── Meta campos (moneda, vencimiento…) ───────────────────── -->
<div class="meta-cols">
  <div class="meta-item"><label>Moneda</label><span><?= $currency ?></span></div>
  <div class="meta-item"><label>Tipo Documento</label><span><?= $e($docType) ?></span></div>
  <?php if (($data['issue_date'] ?? '') !== ''): ?>
  <div class="meta-item"><label>Fecha Emisión</label><span><?= $issueDate ?></span></div>
  <?php endif; ?>
  <div class="meta-item"><label>Estado FE</label><span><?= $e($statusLabel) ?></span></div>
</div>

<!-- ── Tabla de ítems ────────────────────────────────────────── -->
<table class="items-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Código</th>
      <th>Descripción</th>
      <th class="right">Cant.</th>
      <th class="right">Precio Unit.</th>
      <th class="right">Descto.</th>
      <th class="right">IVA %</th>
      <th class="right">Total</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($lines)): ?>
    <tr><td colspan="8" style="text-align:center;color:#6b7280;padding:16px;">Sin ítems registrados</td></tr>
    <?php endif; ?>
    <?php foreach ($lines as $i => $line):
      $lineTotal = (float)($line['line_total'] ?? ($line['total'] ?? 0));
      $disc = (float)($line['discount'] ?? $line['discount_amount'] ?? 0);
      $taxPct = (float)($line['tax_rate'] ?? $line['iva_rate'] ?? $line['tax_percent'] ?? 0);
    ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= $e($line['sku'] ?? $line['item_code'] ?? $line['item_id'] ?? '') ?></td>
      <td><?= $e($line['description'] ?? $line['name'] ?? $line['item_name'] ?? '') ?></td>
      <td class="right"><?= $fmtNum($line['quantity'] ?? 1) ?></td>
      <td class="right"><?= $fmtCur($line['unit_price'] ?? $line['price'] ?? 0) ?></td>
      <td class="right"><?= $disc > 0 ? $fmtCur($disc) : '-' ?></td>
      <td class="right"><?= $taxPct > 0 ? number_format($taxPct, 0) . '%' : '-' ?></td>
      <td class="right"><?= $fmtCur($lineTotal) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- ── Totales ───────────────────────────────────────────────── -->
<div class="totals-wrap">
  <table class="totals-table">
    <tr><td>Subtotal</td><td><?= $fmtCur($subtotal) ?></td></tr>
    <?php if ($taxTotal > 0): ?>
    <tr><td>IVA</td><td><?= $fmtCur($taxTotal) ?></td></tr>
    <?php
    $discTotal = (float)($data['discount_total'] ?? 0);
    if ($discTotal > 0):
    ?>
    <tr><td>Descuento</td><td>- <?= $fmtCur($discTotal) ?></td></tr>
    <?php endif; ?>
    <?php endif; ?>
    <tr class="total-row">
      <td>TOTAL A PAGAR (<?= $currency ?>)</td>
      <td><?= $fmtCur($total) ?></td>
    </tr>
  </table>
</div>

<!-- ── CUFE y QR (solo facturas electrónicas alanube) ────────── -->
<?php if ($cufe !== '' || $qrUrl !== ''): ?>
<div class="fiscal-bar">
  <?php if ($qrUrl !== ''): ?>
  <div class="qr-block">
    <img src="<?= $qrUrl ?>" alt="QR DIAN" title="Código QR generado por Alanube">
  </div>
  <?php endif; ?>
  <div class="cufe-block">
    <label>CUFE (Código Único de Factura Electrónica)</label>
    <span><?= $cufe !== '' ? $cufe : 'Pendiente de validación DIAN' ?></span>
    <div style="margin-top:6px;font-size:9px;color:#6b7280;">
      ✓ Documento electrónico validado por la DIAN a través de Alanube.
      Para verificar autenticidad consulte: <em>muisca.dian.gov.co</em>
    </div>
  </div>
</div>
<?php else: ?>
<div class="notes-box" style="border-color:#fbbf24;background:#fefce8;">
  <h4 style="color:#b45309;">⚠ Documento no electrónico</h4>
  Este documento no ha sido enviado a la DIAN. No tiene validez fiscal electrónica.
  Para generar la factura electrónica, solicítalo desde el módulo de Facturación.
</div>
<?php endif; ?>

<!-- ── Notas / Observaciones ─────────────────────────────────── -->
<?php
$notes = (string)($data['notes'] ?? $data['observations'] ?? $meta['notes'] ?? '');
if ($notes !== ''):
?>
<div class="notes-box">
  <h4>Observaciones</h4>
  <?= $e($notes) ?>
</div>
<?php endif; ?>

<!-- ── Footer del documento ──────────────────────────────────── -->
<div class="doc-footer">
  <?= $e($company['document_footer'] ?? 'Documento generado por SUKI ERP') ?><br>
  <?= $e($company['company_name'] ?? '') ?> — NIT: <?= $e($company['nit'] ?? '---') ?><br>
  <strong>Generado el <?= $e($rendered_at ?? date('Y-m-d H:i:s')) ?></strong>
  &nbsp;|&nbsp; Solo personal autorizado
</div>

</body>
</html>
