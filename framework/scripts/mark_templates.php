<?php
// Marca como 'template' todas las apps del catálogo que NO son suki_erp
// y no fueron propuestas por un builder (sin _proposed_by_tenant).
// solo suki_erp tiene implementación PHP real completa.

$path = dirname(__DIR__, 2) . '/project/contracts/app_catalog.json';
$data = json_decode(file_get_contents($path), true);
$changed = 0;

foreach ($data['apps'] as &$app) {
    if (
        $app['id'] !== 'suki_erp' &&
        ($app['status'] ?? '') === 'available' &&
        empty($app['_proposed_by_tenant'])
    ) {
        $app['status'] = 'template';
        $app['_template_note'] = 'Plantilla base de SUKI. Requiere implementación PHP para activarse en el Marketplace.';
        $changed++;
    }
}

file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Cambiadas a template: {$changed}" . PHP_EOL;

// Mostrar resultado final
$data2 = json_decode(file_get_contents($path), true);
foreach ($data2['apps'] as $a) {
    echo str_pad($a['id'], 28) . ' -> ' . $a['status'] . PHP_EOL;
}
