<?php
// Core\Controller.php

namespace App\Core;

use RuntimeException;

class Controller
{
    public function view(string $viewName, array $data = []): void
    {
        extract($data, EXTR_SKIP);

        $frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname($frameworkRoot) . '/project';
        $file = $projectRoot . "/views/{$viewName}.php";

        if (!file_exists($file)) {
            throw new RuntimeException("La vista {$viewName} no existe.");
        }

        require $file;
    }
}
