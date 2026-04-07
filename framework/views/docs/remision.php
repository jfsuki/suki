<?php
/**
 * Plantilla: Remisión de Despacho
 * NO es documento electrónico — es control interno de despacho.
 * No va a la DIAN. Lleva control de entregas y firmas.
 * Variables: $company, $data, $user_role, $rendered_at
 */
$e      = fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmtNum = fn(mixed $v): string => number_format((float)($v ?? 0), 0, ',', '.');
$fmtCur = fn(mixed $v): string => '$ ' . number_format((float)($v ?? 0), 2, ',', '.');

$docNum     = $e($data['sale_number'] ?? $data['id'] ?? 'REM-001');
$created    = $e($data['created_at'] ?? date('Y-m-d'));
$saleDate   = substr($created, 0, 10);
$items      = is_array($data['_grids']['items'] ?? null) ? (array)$data['_grids']['items'] : (is_array($data['items'] ?? null) ? (array)$data['items'] : []);
$meta       = is_array($data['metadata'] ?? null) ? (array)$data['metadata'] : [];
$custMeta   = is_array($meta['customer'] ?? null) ? (array)$meta['customer'] : [];
$custName   = $e($custMeta['name'] ?? $data['customer_id'] ?? 'Público en General');
$custAddr   = $e($custMeta['address'] ?? $custMeta['delivery_address'] ?? '');
$notes      = $e($data['notes'] ?? $meta['notes'] ?? '');
$total      = (float)($data['total'] ?? 0);

$data['_doc_title'] = 'REMISIÓN ' . $docNum;
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
    <div class="doc-badge" style="background:#0f766e;">REMISIÓN DE DESPACHO</div>
    <div class="doc-number">REM-<?= $docNum ?></div>
    <div class="doc-date">Fecha: <strong><?= $e($saleDate) ?></strong></div>
    <div style="margin-top:6px;padding:4px 10px;background:#dcfce7;border-radius:6px;font-size:10px;display:inline-block;font-weight:700;color:#15803d;">
      USO INTERNO — NO REEMPLAZA FACTURA
    </div>
  </div>
</div>

<!-- Aviso legal -->
<div class="notes-box" style="background:#f0fdf4;border-color:#86efac;margin-bottom:14px;">
  <h4 style="color:#15803d;">📌 Documento de Despacho Interno</h4>
  Este documento es una <strong>remisión de mercancía</strong> para control interno y entrega al cliente.
  <strong>No tiene validez fiscal ante la DIAN.</strong> La factura electrónica debe generarse por separado.
</div>

<div class="parties">
  <div class="party-box">
    <h3>Despachado por</h3>
    <p><strong><?= $e($company['company_name'] ?? '') ?></strong><br>
       NIT: <?= $e($company['nit'] ?? '---') ?><br>
       <?= $e($company['address'] ?? '') ?>, <?= $e($company['city'] ?? '') ?></p>
  </div>
  <div class="party-box">
    <h3>Entregar a</h3>
    <p><strong><?= $custName ?></strong><br>
       <?= $custAddr !== '' ? $custAddr. '<br>' : '' ?>
       <?= $e($custMeta['phone'] ?? '') ?>
    </p>
  </div>
</div>

<div class="meta-cols">
  <div class="meta-item"><label>Nro. Remisión</label><span>REM-<?= $docNum ?></span></div>
  <div class="meta-item"><label>Fecha</label><span><?= $e($saleDate) ?></span></div>
  <div class="meta-item"><label>Referencia Venta</label><span><?= $docNum ?></span></div>
</div>

<!-- Tabla de artículos -->
<table class="items-table">
  <thead><tr>
    <th>#</th><th>Código</th><th>Descripción</th>
    <th class="right">Cant. Facturada</th><th class="right">Cant. Despachada</th>
    <th class="right">Und.</th><th class="right">Precio</th>
  </tr></thead>
  <tbody>
    <?php if (empty($items)): ?>
    <tr><td colspan="7" style="text-align:center;color:#6b7280;padding:20px;">Sin ítems cargados</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $i => $item): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= $e($item['sku'] ?? $item['item_code'] ?? '') ?></td>
      <td><?= $e($item['name'] ?? $item['description'] ?? '') ?></td>
      <td class="right"><?= $fmtNum($item['quantity'] ?? 1) ?></td>
      <td class="right" style="background:#f0fdf4;font-weight:700;">_______</td>
      <td class="right"><?= $e($item['unit'] ?? 'Und') ?></td>
      <td class="right"><?= $fmtCur($item['unit_price'] ?? $item['price'] ?? 0) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><td colspan="4"></td><td class="right" style="font-size:11px;font-weight:700;">Total valor aprox.:</td><td colspan="2" class="right"><?= $fmtCur($total) ?></td></tr>
  </tfoot>
</table>

<?php if ($notes !== ''): ?>
<div class="notes-box"><h4>Observaciones de despacho</h4><?= $notes ?></div>
<?php endif; ?>

<!-- Firmas -->
<div class="signatures">
  <div class="sig-box"><span>Despacha</span></div>
  <div class="sig-box"><span>Transportador / Mensajero</span></div>
  <div class="sig-box"><span>Recibe (Nombre y Cédula)</span></div>
</div>

<div class="notes-box" style="margin-top:0;">
  Fecha y hora de entrega: __________________ &nbsp;&nbsp; Observaciones de entrega: _______________________________
</div>

<div class="doc-footer"><?= $e($company['document_footer'] ?? 'Si tiene dudas, contacte a ' . ($company['phone'] ?? '')) ?><br>
REMISIÓN N° REM-<?= $docNum ?> — <?= $e($company['company_name'] ?? '') ?> — <?= $e(date('Y-m-d H:i')) ?>
</div>
</body></html>
