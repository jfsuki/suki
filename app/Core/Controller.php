<?php
// Core\Controller.php

namespace App\Core;

use RuntimeException;

class Controller
{
    public function view(string $viewName, array $data = []): void
    {
        extract($data, EXTR_SKIP);

        $file = __DIR__ . "/../../views/{$viewName}.php";

        if (!file_exists($file)) {
            throw new RuntimeException("La vista {$viewName} no existe.");
        }

        require $file;
    }
}
