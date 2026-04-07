<?php
/**
 * Layout base compartido para todos los documentos SUKI.
 * Variables disponibles: $company, $data, $type, $user_role, $rendered_at
 *
 * $company: array de BusinessConfigService (logo, NIT, nombre, color, etc.)
 * $data:    array con los datos del documento
 */

// Helpers de escape y formato
$e = fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmt = fn(mixed $v, int $dec = 0): string => number_format((float)($v ?? 0), $dec, ',', '.');
$fmtCur = fn(mixed $v): string => '$&nbsp;' . number_format((float)($v ?? 0), 2, ',', '.');

$color   = $e($company['primary_color'] ?? '#1a56db');
$logo    = $company['logo_base64'] ?? '';     // data:image/... base64
$logoPath = $company['logo_path'] ?? '';
$compName = $e($company['display_name'] ?? $company['company_name'] ?? 'Empresa');
$nit     = $e($company['nit'] ?? '');
$addr    = $e($company['address'] ?? '');
$city    = $e($company['city'] ?? '');
$phone   = $e($company['phone'] ?? '');
$emailC  = $e($company['email'] ?? '');
$regTax  = $e($company['tax_regime'] ?? '');
$footer  = $e($company['document_footer'] ?? 'Documento generado por SUKI ERP');
$currency = $e($company['currency'] ?? 'COP');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e($data['_doc_title'] ?? 'Documento') ?> — <?= $compName ?></title>
<style>
  :root {
    --brand: <?= $color ?>;
    --brand-light: <?= $color ?>22;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-500: #6b7280;
    --gray-700: #374151;
    --gray-900: #111827;
    --red-600: #dc2626;
    --green-600: #16a34a;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 12px;
    color: var(--gray-900);
    background: #fff;
    padding: 20px;
    max-width: 900px;
    margin: 0 auto;
  }

  /* ── No-print actions ────────────────────────────────── */
  .no-print {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    align-items: center;
    flex-wrap: wrap;
  }
  .no-print button {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
  }
  .btn-print  { background: var(--brand); color: #fff; }
  .btn-back   { background: var(--gray-100); color: var(--gray-700); }
  .no-print button:hover { opacity: 0.85; }

  /* ── Document header ─────────────────────────────────── */
  .doc-header {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 16px;
    align-items: flex-start;
    border-bottom: 3px solid var(--brand);
    padding-bottom: 12px;
    margin-bottom: 16px;
  }
  .company-logo { max-height: 60px; max-width: 180px; object-fit: contain; }
  .company-name { font-size: 15px; font-weight: 700; color: var(--gray-900); }
  .company-meta { font-size: 11px; color: var(--gray-500); line-height: 1.5; margin-top: 4px; }
  .doc-title-block { text-align: right; }
  .doc-badge {
    display: inline-block;
    background: var(--brand);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 6px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-bottom: 6px;
  }
  .doc-number { font-size: 20px; font-weight: 800; color: var(--gray-900); }
  .doc-date   { font-size: 11px; color: var(--gray-500); margin-top: 2px; }

  /* ── Party boxes ─────────────────────────────────────── */
  .parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 16px;
  }
  .party-box {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 10px 12px;
  }
  .party-box h3 {
    font-size: 10px;
    text-transform: uppercase;
    color: var(--brand);
    font-weight: 700;
    letter-spacing: 0.8px;
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 1px solid var(--gray-200);
  }
  .party-box p { font-size: 11px; line-height: 1.7; color: var(--gray-700); }
  .party-box strong { color: var(--gray-900); font-size: 13px; }

  /* ── Meta cols ───────────────────────────────────────── */
  .meta-cols {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 8px;
    margin-bottom: 16px;
  }
  .meta-item {
    background: var(--brand-light);
    border-left: 3px solid var(--brand);
    border-radius: 0 6px 6px 0;
    padding: 6px 10px;
  }
  .meta-item label { font-size: 9px; text-transform: uppercase; color: var(--brand); font-weight: 700; display: block; }
  .meta-item span  { font-size: 12px; font-weight: 600; color: var(--gray-900); }

  /* ── Items table ─────────────────────────────────────── */
  .items-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  .items-table thead tr { background: var(--brand); color: #fff; }
  .items-table thead th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 600; }
  .items-table thead th.right { text-align: right; }
  .items-table tbody tr { border-bottom: 1px solid var(--gray-200); }
  .items-table tbody tr:nth-child(even) { background: var(--gray-50); }
  .items-table tbody td { padding: 7px 10px; font-size: 11px; vertical-align: top; }
  .items-table tbody td.right { text-align: right; font-variant-numeric: tabular-nums; }
  .items-table tfoot tr { background: var(--gray-100); }
  .items-table tfoot td { padding: 5px 10px; font-size: 11px; font-weight: 600; }

  /* ── Totals block ────────────────────────────────────── */
  .totals-wrap { display: flex; justify-content: flex-end; margin-bottom: 20px; }
  .totals-table { border-collapse: collapse; min-width: 280px; }
  .totals-table tr { border-bottom: 1px solid var(--gray-200); }
  .totals-table td { padding: 5px 10px; font-size: 12px; }
  .totals-table td:last-child { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
  .totals-table .total-row { background: var(--brand); color: #fff; }
  .totals-table .total-row td { font-size: 14px; font-weight: 800; padding: 8px 12px; }
  .totals-table .total-row td:last-child { font-size: 16px; }

  /* ── CUFE / QR ───────────────────────────────────────── */
  .fiscal-bar {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 14px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
  }
  .fiscal-bar .qr-block img { width: 80px; height: 80px; }
  .fiscal-bar .cufe-block { flex: 1; }
  .fiscal-bar .cufe-block label { font-size: 9px; text-transform: uppercase; color: var(--gray-500); font-weight: 700; }
  .fiscal-bar .cufe-block span  { font-size: 9px; word-break: break-all; color: var(--gray-700); font-family: monospace; }
  .status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .status-accepted { background: #dcfce7; color: #15803d; }
  .status-pending  { background: #fef9c3; color: #854d0e; }
  .status-draft    { background: var(--gray-100); color: var(--gray-500); }
  .status-canceled { background: #fee2e2; color: var(--red-600); }

  /* ── Observations / Notes ───────────────────────────── */
  .notes-box {
    border: 1px dashed var(--gray-200);
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 14px;
    font-size: 11px;
    color: var(--gray-700);
    line-height: 1.6;
  }
  .notes-box h4 { font-size: 10px; color: var(--brand); text-transform: uppercase; margin-bottom: 4px; }

  /* ── Signature line ─────────────────────────────────── */
  .signatures {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 24px;
    margin-top: 40px;
    margin-bottom: 14px;
  }
  .sig-box { border-top: 1px solid var(--gray-700); padding-top: 4px; }
  .sig-box span { font-size: 10px; color: var(--gray-500); }

  /* ── Footer ─────────────────────────────────────────── */
  .doc-footer {
    text-align: center;
    font-size: 9px;
    color: var(--gray-500);
    border-top: 1px solid var(--gray-200);
    padding-top: 8px;
    margin-top: 16px;
    line-height: 1.6;
  }

  /* ── Print ───────────────────────────────────────────── */
  @media print {
    .no-print { display: none !important; }
    body { padding: 0; margin: 0; }
    .doc-header { border-color: #000; }
    @page { margin: 15mm 12mm; size: A4 portrait; }
  }
</style>
</head>
<body>

<!-- Botones de acción (no se imprimen) -->
<div class="no-print">
  <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
  <button class="btn-back"  onclick="history.back()">← Volver</button>
  <span style="margin-left:auto;color:#6b7280;font-size:11px;">
    Documento generado el <?= $e($rendered_at ?? date('Y-m-d H:i:s')) ?>
    &nbsp;|&nbsp; Solo para uso interno — requiere sesión autorizada
  </span>
</div>
