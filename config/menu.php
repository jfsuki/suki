<?php
// config/menu.php
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