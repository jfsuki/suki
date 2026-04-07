<?php
/**
 * Plantilla: Reporte Dinámico con Gráfica Chart.js
 * Soporte: bar, line, doughnut, kpi
 * Variables: $company, $data, $type, $report_type, $desde, $hasta, $user_role, $rendered_at
 */
$e      = fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmtCur = fn(mixed $v): string => '$ ' . number_format((float)($v ?? 0), 2, ',', '.');
$fmtNum = fn(mixed $v): string => number_format((float)($v ?? 0), 0, ',', '.');

$title     = $e($data['chart_title'] ?? 'Reporte');
$chartType = $data['chart_type'] ?? 'bar';
$labels    = array_map(fn($l) => htmlspecialchars((string)$l), (array)($data['labels'] ?? []));
$datasets  = is_array($data['datasets'] ?? null) ? (array)$data['datasets'] : [];
$tableRows = is_array($data['table_rows'] ?? null) ? (array)$data['table_rows'] : [];
$tableCols = is_array($data['table_columns'] ?? null) ? (array)$data['table_columns'] : [];
$summary   = is_array($data['summary'] ?? null) ? (array)$data['summary'] : [];
$kpis      = is_array($data['kpis'] ?? null) ? (array)$data['kpis'] : [];
$periodo   = is_array($data['periodo'] ?? null) ? (array)$data['periodo'] : ['desde' => $desde, 'hasta' => $hasta];
$color     = $company['primary_color'] ?? '#1a56db';

// Colores para datasets
$chartColors = [
    ['bg' => "{$color}cc", 'border' => $color],
    ['bg' => '#f59e0bcc', 'border' => '#f59e0b'],
    ['bg' => '#10b981cc', 'border' => '#10b981'],
    ['bg' => '#ef4444cc', 'border' => '#ef4444'],
];

$data['_doc_title'] = $title;
include __DIR__ . '/layout_base.php';
$logo = $company['logo_base64'] ?? '';
?>
<!-- Extra CSS para reportes -->
<style>
  .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:20px; }
  .kpi-card  { background:#fff; border:1px solid var(--gray-200); border-radius:10px; padding:16px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
  .kpi-card .kpi-icon { font-size:28px; margin-bottom:4px; }
  .kpi-card .kpi-value { font-size:22px; font-weight:800; color:var(--brand); }
  .kpi-card .kpi-label { font-size:11px; color:var(--gray-500); margin-top:2px; }
  .chart-container { position:relative; height:320px; margin-bottom:24px; }
  .rpt-header { display:flex; align-items:center; gap:12px; margin-bottom:16px; padding-bottom:12px; border-bottom:3px solid var(--brand); }
  .rpt-logo   { max-height:50px; max-width:140px; }
  .rpt-meta   { flex:1; }
  .rpt-meta h1 { font-size:16px; font-weight:800; color:var(--gray-900); }
  .rpt-meta p  { font-size:11px; color:var(--gray-500); }
  .rpt-badge  { background:var(--brand); color:#fff; padding:4px 12px; border-radius:6px; font-size:12px; font-weight:600; white-space:nowrap; }
  .report-table { width:100%; border-collapse:collapse; margin-top:8px; font-size:11px; }
  .report-table th { background:var(--brand); color:#fff; padding:7px 10px; text-align:left; }
  .report-table td { padding:6px 10px; border-bottom:1px solid var(--gray-200); }
  .report-table tr:nth-child(even) { background:var(--gray-50); }
  .report-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
</style>

<!-- Header del reporte -->
<div class="rpt-header">
  <?php if ($logo !== ''): ?><img src="<?= $logo ?>" class="rpt-logo" alt="Logo"><?php endif; ?>
  <div class="rpt-meta">
    <h1><?= $title ?></h1>
    <p>
      Empresa: <strong><?= $e($company['company_name'] ?? '') ?></strong>
      &nbsp;|&nbsp; Período: <strong><?= $e((string)($periodo['desde'] ?? $desde)) ?></strong> al <strong><?= $e((string)($periodo['hasta'] ?? $hasta)) ?></strong>
      &nbsp;|&nbsp; Generado: <?= $e($rendered_at ?? date('Y-m-d H:i')) ?>
    </p>
  </div>
  <div class="rpt-badge">REPORTE</div>
</div>

<!-- KPIs summary cards -->
<?php if (!empty($summary)): ?>
<div class="kpi-grid">
  <?php foreach ($summary as $sk => $sv): ?>
  <div class="kpi-card">
    <div class="kpi-value"><?= is_numeric($sv) ? $fmtNum($sv) : $e((string)$sv) ?></div>
    <div class="kpi-label"><?= $e(ucwords(str_replace('_', ' ', $sk))) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- KPI Cards especiales (kpi_dashboard) -->
<?php if (!empty($kpis) && $chartType === 'kpi'): ?>
<div class="kpi-grid">
  <?php foreach ($kpis as $kpi): ?>
  <div class="kpi-card">
    <div class="kpi-icon"><?= $e($kpi['icon'] ?? '📊') ?></div>
    <div class="kpi-value">
      <?php
      $kv = $kpi['value'] ?? 0;
      $fmt = $kpi['format'] ?? 'number';
      echo $fmt === 'currency' ? $fmtCur($kv) : $fmtNum($kv);
      ?>
    </div>
    <div class="kpi-label"><?= $e($kpi['label'] ?? '') ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Chart.js Gráfica -->
<?php if (!empty($labels) && !empty($datasets) && $chartType !== 'kpi'): ?>
<div class="chart-container">
  <canvas id="reportChart"></canvas>
</div>
<?php endif; ?>

<!-- Tabla de detalle -->
<?php if (!empty($tableRows) && !empty($tableCols)): ?>
<h3 style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--gray-900);">📋 Detalle del Reporte</h3>
<table class="report-table">
  <thead>
    <tr>
      <?php foreach ($tableCols as $col): ?>
      <th><?= $e(ucwords(str_replace('_', ' ', $col))) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($tableRows as $row): ?>
    <tr>
      <?php foreach ($tableCols as $col): ?>
      <?php $v = $row[$col] ?? ''; ?>
      <td class="<?= is_numeric($v) && $col !== 'mes' && $col !== 'semana' && $col !== 'fecha' ? 'num' : '' ?>">
        <?= is_numeric($v) && $col !== 'mes' && $col !== 'semana' && $col !== 'fecha'
            ? (str_contains($col, 'monto') || str_contains($col, 'total') || str_contains($col, 'compras') || str_contains($col, 'ventas') ? $fmtCur($v) : $fmtNum($v))
            : $e((string)$v) ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<!-- Footer -->
<div class="doc-footer" style="margin-top:24px;">
  <?= $e($company['company_name'] ?? '') ?> — NIT: <?= $e($company['nit'] ?? '---') ?>&nbsp;|&nbsp;
  Reporte generado por SUKI ERP — <?= $e($rendered_at ?? date('Y-m-d H:i')) ?>
  &nbsp;|&nbsp; Uso interno — requiere sesión autorizada
</div>

</body>

<!-- Chart.js CDN (solo para display — se omite en impresión simple) -->
<?php if (!empty($labels) && !empty($datasets) && $chartType !== 'kpi'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function(){
  const ctx = document.getElementById('reportChart');
  if (!ctx) return;

  const labels  = <?= json_encode(array_values($labels), JSON_UNESCAPED_UNICODE) ?>;
  const rawDS   = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE) ?>;
  const colors  = <?= json_encode(array_values($chartColors)) ?>;
  const type    = <?= json_encode($chartType) ?>;

  const datasets = rawDS.map((ds, i) => {
    const c = colors[i % colors.length];
    return {
      label: ds.label ?? '',
      data:  ds.data ?? [],
      backgroundColor: type === 'line' ? (c?.bg ?? '#1a56dbcc') : (ds.data ?? []).map(() => c?.bg ?? '#1a56dbcc'),
      borderColor: c?.border ?? '#1a56db',
      borderWidth: 2,
      tension: 0.4,
      fill: type === 'line',
      pointRadius: type === 'line' ? 4 : 0,
    };
  });

  new Chart(ctx, {
    type: type === 'doughnut' ? 'doughnut' : (type === 'line' ? 'line' : 'bar'),
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top', labels: { font: { size: 12 } } },
        tooltip: { callbacks: {
          label: (ctx) => {
            const v = ctx.parsed.y ?? ctx.parsed;
            return ' ' + ctx.dataset.label + ': ' + (typeof v === 'number' ? v.toLocaleString('es-CO', {minimumFractionDigits:0}) : v);
          }
        }}
      },
      scales: type !== 'doughnut' ? {
        x: { grid: { color: '#f3f4f6' } },
        y: { grid: { color: '#f3f4f6' }, ticks: { callback: (v) => v.toLocaleString('es-CO') } }
      } : {}
    }
  });
})();
</script>
<?php endif; ?>
</html>
