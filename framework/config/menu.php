<?php
// config/menu.php (loader)
$frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
$projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname($frameworkRoot) . '/project';

$menuCandidates = [
    $projectRoot . '/config/menu.json',
    $frameworkRoot . '/config/menu.json', // legacy fallback
];

foreach ($menuCandidates as $menuJson) {
    if (!file_exists($menuJson)) {
        continue;
    }

    try {
        $data = json_decode(
            file_get_contents($menuJson),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (is_array($data)) {
            return $data;
        }
    } catch (JsonException $e) {
        // Fallback below
    }
}

return [
    ['label' => 'Dashboard', 'url' => 'dashboard', 'icon' => 'home'],
    [
        'label' => 'Inventarios',
        'url' => '#',
        'submenu' => [
            ['label' => 'Productos', 'url' => 'inventario/productos'],
            ['label' => 'Bodegas',   'url' => 'inventario/bodegas'],
            ['label' => 'Kardex',    'url' => 'inventario/kardex'],
        ]
    ],
    ['label' => 'Facturas',  'url' => 'facturas'],
    ['label' => 'Clientes',  'url' => 'clientes/clientes'],
];
