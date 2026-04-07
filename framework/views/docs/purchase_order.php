<?php
/**
 * Plantilla: Orden de Compra
 * Variables: $company, $data, $user_role, $rendered_at
 */
$e      = fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmtCur = fn(mixed $v): string => '$ ' . number_format((float)($v ?? 0), 2, ',', '.');
$fmtNum = fn(mixed $v): string => number_format((float)($v ?? 0), 0, ',', '.');

$docNum   = $e($data['purchase_number'] ?? $data['id'] ?? 'OC-001');
$created  = $e(substr((string)($data['created_at'] ?? date('Y-m-d')), 0, 10));
$status   = strtolower((string)($data['status'] ?? 'draft'));
$supplier = is_array($data['supplier'] ?? null) ? (array)$data['supplier'] : [];
$suppName = $e($supplier['name'] ?? $data['supplier_id'] ?? 'Proveedor no especificado');
$suppNit  = $e($supplier['nit'] ?? $supplier['id_number'] ?? '');
$suppAddr = $e($supplier['address'] ?? '');
$meta     = is_array($data['metadata'] ?? null) ? (array)$data['metadata'] : [];
$items    = is_array($data['_grids']['items'] ?? null) ? (array)$data['_grids']['items'] : (is_array($data['items'] ?? null) ? (array)$data['items'] : []);
$payTerms = $e($data['payment_terms'] ?? $meta['payment_terms'] ?? 'Contra entrega');
$delivDate = $e($data['delivery_date'] ?? $meta['delivery_date'] ?? '');
$notes    = $e($data['notes'] ?? $meta['notes'] ?? '');
$currency = $e($company['currency'] ?? 'COP');
$subtotal = (float)($data['subtotal'] ?? 0);
$taxTotal = (float)($data['tax_total'] ?? 0);
$total    = (float)($data['total'] ?? 0);

$statusClass = match($status) {
    'approved' => 'status-accepted',
    'pending'  => 'status-pending',
    'canceled' => 'status-canceled',
    default    => 'status-draft',
};
$statusLabel = ['draft'=>'Borrador','pending'=>'Pendiente','approved'=>'Aprobada ✓','received'=>'Recibida ✓','canceled'=>'Anulada'][$status] ?? $status;

$data['_doc_title'] = 'ORDEN DE COMPRA ' . $docNum;
include __DIR__ . '/layout_base.php';
$logo = $company['logo_base64'] ?? '';
?>

<div class="doc-header">
  <div class="company-block">
    <?php if ($logo !== ''): ?><img src="<?= $logo ?>" class="company-logo" style="margin-bottom:6px;display:block;" alt="Logo"><?php endif; ?>
    <div class="company-name"><?= $e($company['company_name'] ?? '') ?></div>
    <div class="company-meta">NIT: <strong><?= $e($company['nit'] ?? '---') ?></strong><br><?= $e($company['address'] ?? '') ?>, <?= $e($company['city'] ?? '') ?><br><?= $e($company['phone'] ?? '') ?></div>
  </div>
  <div class="doc-title-block">
    <div class="doc-badge" style="background:#7c3aed;">ORDEN DE COMPRA</div>
    <div class="doc-number">OC-<?= $docNum ?></div>
    <div class="doc-date">Fecha: <strong><?= $created ?></strong></div>
    <div style="margin-top:6px;"><span class="status-badge <?= $statusClass ?>"><?= $e($statusLabel) ?></span></div>
  </div>
</div>

<div class="parties">
  <div class="party-box">
    <h3>Comprador</h3>
    <p><strong><?= $e($company['company_name'] ?? '') ?></strong><br>NIT: <?= $e($company['nit'] ?? '---') ?><br><?= $e($company['address'] ?? '') ?></p>
  </div>
  <div class="party-box">
    <h3>Proveedor</h3>
    <p><strong><?= $suppName ?></strong><br>
       <?= $suppNit !== '' ? 'NIT: ' . $suppNit . '<br>' : '' ?>
       <?= $suppAddr !== '' ? $suppAddr . '<br>' : '' ?></p>
  </div>
</div>

<div class="meta-cols">
  <div class="meta-item"><label>N° Orden</label><span>OC-<?= $docNum ?></span></div>
  <div class="meta-item"><label>Fecha Emisión</label><span><?= $created ?></span></div>
  <div class="meta-item"><label>Moneda</label><span><?= $currency ?></span></div>
  <div class="meta-item"><label>Cond. de Pago</label><span><?= $payTerms ?></span></div>
  <?php if ($delivDate !== ''): ?>
  <div class="meta-item"><label>Fecha Entrega</label><span><?= $delivDate ?></span></div>
  <?php endif; ?>
</div>

<table class="items-table">
  <thead><tr>
    <th>#</th><th>Código</th><th>Descripción</th>
    <th class="right">Cant.</th><th class="right">Precio Unit.</th>
    <th class="right">IVA %</th><th class="right">Total</th>
  </tr></thead>
  <tbody>
    <?php if (empty($items)): ?>
    <tr><td colspan="7" style="text-align:center;color:#6b7280;padding:20px;">Sin ítems registrados</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $i => $item): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= $e($item['sku'] ?? $item['item_code'] ?? '') ?></td>
      <td><?= $e($item['name'] ?? $item['description'] ?? '') ?></td>
      <td class="right"><?= $fmtNum($item['quantity'] ?? 0) ?></td>
      <td class="right"><?= $fmtCur($item['unit_price'] ?? $item['cost'] ?? 0) ?></td>
      <td class="right"><?= number_format((float)($item['tax_rate'] ?? 0), 0) ?>%</td>
      <td class="right"><?= $fmtCur($item['line_total'] ?? ((float)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? $item['cost'] ?? 0))) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="totals-wrap">
  <table class="totals-table">
    <?php if ($subtotal > 0): ?><tr><td>Subtotal</td><td><?= $fmtCur($subtotal) ?></td></tr><?php endif; ?>
    <?php if ($taxTotal > 0): ?><tr><td>IVA</td><td><?= $fmtCur($taxTotal) ?></td></tr><?php endif; ?>
    <tr class="total-row"><td>TOTAL ORDEN (<?= $currency ?>)</td><td><?= $fmtCur($total > 0 ? $total : $subtotal + $taxTotal) ?></td></tr>
  </table>
</div>

<?php if ($notes !== ''): ?>
<div class="notes-box"><h4>Condiciones y Observaciones</h4><?= $notes ?></div>
<?php endif; ?>

<div class="notes-box" style="background:#f5f3ff;border-color:#a78bfa;">
  <h4 style="color:#7c3aed;">Condiciones de la Orden</h4>
  <strong>Condiciones de Pago:</strong> <?= $payTerms ?><br>
  <strong>Lugar de Entrega:</strong> <?= $e($company['address'] ?? '') ?>, <?= $e($company['city'] ?? '') ?><br>
  Los bienes deben entregarse en las condiciones y fechas pactadas. La recepción parcial debe ser notificada al comprador.
</div>

<div class="signatures" style="grid-template-columns:1fr 1fr;">
  <div class="sig-box"><span>Autorizado por (Comprador)</span></div>
  <div class="sig-box"><span>Aceptado por (Proveedor)</span></div>
</div>

<div class="doc-footer"><?= $e($company['document_footer'] ?? '') ?><br>
ORDEN DE COMPRA OC-<?= $docNum ?> — <?= $e($company['company_name'] ?? '') ?> — <?= $e(date('Y-m-d H:i')) ?>
</div>
</body></html>
