<?php
// app/controller/ProductoController.php

namespace App\Controller;

use App\Core\Controller;
use App\Core\Response;

class ProductoController extends Controller
{
    private Response $response;

    public function __construct()
    {
        $this->response = new Response();
    }

    public function buscar(): void
    {
        $q = $_GET['q'] ?? '';

        $productos = [
            ['id' => 1, 'codigo' => 'LAP', 'nombre' => 'Laptop Dell'],
            ['id' => 2, 'codigo' => 'MOU', 'nombre' => 'Mouse'],
        ];

        $filtrados = array_values(array_filter(
            $productos,
            fn ($p) =>
                $q === '' ||
                stripos($p['nombre'], $q) !== false ||
                stripos($p['codigo'], $q) !== false
        ));

        echo $this->response->json('success', 'ok', $filtrados);
    }
}
