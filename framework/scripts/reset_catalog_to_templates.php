<?php
// Pone todas las apps del catálogo como 'template'.
// Estado inicial correcto: ninguna app está terminada para producción.
$path = dirname(__DIR__, 2) . '/project/contracts/app_catalog.json';
$data = json_decode(file_get_contents($path), true);

foreach ($data['apps'] as &$app) {
    $id = $app['id'] ?? '';
    $app['status'] = 'template';
    unset($app['_beta_blockers'], $app['_beta_note']);

    if ($id === 'suki_erp') {
        $app['_template_note'] = 'App con más implementación PHP real. Requiere P0: OTP self-service + DIAN FE para publicarse como available.';
        $app['_p0_blockers']   = [
            'OTP self-service por tenant — auth/register flujo incompleto',
            'Factura electrónica DIAN — AlanubeIntegrationAdapter sin CUFE/firma end-to-end',
        ];
    } else {
        $app['_template_note'] = 'Plantilla base de SUKI. Requiere implementación PHP para publicarse en Marketplace.';
    }
}
unset($app);

file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$counts = array_count_values(array_column($data['apps'], 'status'));
echo "Resultado del catálogo:\n";
foreach ($counts as $status => $count) {
    echo "  $status: $count\n";
}
echo "OK — todas las apps son templates ahora.\n";
