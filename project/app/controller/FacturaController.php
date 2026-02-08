<?php
// app/controllers/FacturaController.php

namespace App\Controller;

use App\Core\Controller;
use App\Core\Response;

class FacturaController extends Controller {
    public function nueva() {
        // Estos datos deben venir de la base de datos idealmente
        $data = [
            'clientes' => [
                ['id' => 1, 'nombre' => 'Alcaldía de Barranquilla'],
                ['id' => 2, 'nombre' => 'Suki Devops SAS']
            ],
            'titulo' => 'Nueva Factura'
        ];

        // Usamos require para cargar la vista y pasarle los datos
        extract($data);
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 2);
        require_once $projectRoot . '/views/factura.php';
    }
}
