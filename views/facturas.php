<?php
use App\Core\FormGenerator;
require_once __DIR__ . '/../app/autoload.php';

$form = new FormGenerator();

// Maestro
$config_maestro = [

    'type' => 'form',
    'name' => 'factura',
    'fields' => [
        ['label' => 'Fecha', 'name' => 'fecha', 'type' => 'date'],
        ['label' => 'Cliente', 'name' => 'cliente', 'type' => 'text']
    ]
];

// Detalle

$config_detalle = [
    'type' => 'grid',
    'name' => 'factura_det',

    'columns' => [
        ['label' => 'Producto', 'name' => 'producto', 'type' => 'text'],
        ['label' => 'Cantidad', 'name' => 'cantidad', 'type' => 'number'],
        ['label' => 'Precio', 'name' => 'precio', 'type' => 'number'],
        ['label' => 'IVA', 'name' => 'iva', 'type' => 'number'],


        [
            'label' => 'Subtotal',
            'name' => 'subtotal',
            'type' => 'calculated',
            'formula' => 'cantidad * precio'
        ],

        [
            'label' => 'Descuento %',
            'name' => 'desc_pct',
            'type' => 'number'
        ],

        [
            'label' => 'Descuento $',
            'name' => 'desc_val',
            'type' => 'calculated',
            'formula' => 'subtotal * desc_pct / 100'
        ],
        [
            'label' => 'IVA $',
            'name' => 'iva_sub',
            'type' => 'calculated',
            'formula' => '(subtotal - desc_val) * (iva/100)'
        ]
    ],

    'totals' => [
        ['label' => 'Subtotal', 'name' => 't_subtotal', 'formula' => 'sum(subtotal)'],
        ['label' => 'Descuento', 'name' => 'desc', 'formula' => 'sum(desc_val)'],
        ['label' => 'TOTAL IVA', 'name' => 'total_iva', 'formula' => 'sum(iva_sub) '],
        ['label' => 'TOTAL', 'name' => 'total_fact', 'formula' => 'sum(subtotal) - sum(desc_val) + sum(iva_sub)']
 
        ]
];
?>

<h1 class="text-2xl font-bold mb-4">Nueva Factura</h1>

<?php echo  $form->render($config_maestro) ?>
<h1 class="text-2xl font-bold mb-4">Detalle Factura</h1>
<?php echo  $form->render($config_detalle) ?>

<script src="/js/grid-form.js"></script>
