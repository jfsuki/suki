<?php
// app/controller/ClienteController.php

namespace App\Controller;

use App\Core\Controller;
use App\Core\Response;

class ClienteController extends Controller
{
    private Response $response;

    public function __construct()
    {
        $this->response = new Response();
    }

    public function listar(): void
    {
        $data = [
            ['id' => 1, 'nombre' => 'Suki Devops SAS'],
            ['id' => 2, 'nombre' => 'Empresa México'],
            ['id' => 3, 'nombre' => 'Consultoría España'],
        ];

        echo $this->response->json('success', 'Datos cargados', $data);
    }

    public function guardar(): void
    {
        echo $this->response->json('success', 'Cliente guardado');
    }
}
