<?php
// project/public/api.php

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
require_once PROJECT_ROOT . '/config/env_loader.php';

$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);

require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

use App\Core\Response;
$manifestError = null;
try {
    \App\Core\ManifestValidator::validateOrFail();
} catch (\Throwable $e) {
    $manifestError = $e;
}

// --------------------------------
// Inicializar respuesta
// --------------------------------
$response = new Response();
if ($manifestError) {
    http_response_code(500);
    echo $response->json('error', 'App manifest invalido: ' . $manifestError->getMessage());
    return;
}

// --------------------------------
// 1. Obtener la ruta
// --------------------------------
$route = trim($_GET['route'] ?? '');

if ($route === '') {
    echo $response->json('error', 'Ruta no definida');
    return;
}

// --------------------------------
// 2. Resolver controlador y método
// --------------------------------
$parts = explode('/', $route);

$controllerClass = 'App\\Controller\\' . ucfirst(strtolower($parts[0])) . 'Controller';
$method          = strtolower($parts[1] ?? 'index');

// --------------------------------
// 3. Validar controlador
// --------------------------------
if (!class_exists($controllerClass)) {
    echo $response->json(
        'error',
        "El controlador {$controllerClass} no existe"
    );
    return;
}

// --------------------------------
// 4. Instanciar controlador
// --------------------------------
$controller = new $controllerClass();

// --------------------------------
// 5. Validar método
// --------------------------------
if (!method_exists($controller, $method)) {
    echo $response->json(
        'error',
        "El método {$method} no existe en {$controllerClass}"
    );
    return;
}

// --------------------------------
// 6. Ejecutar acción
// --------------------------------
$result = $controller->$method($_POST);

// Si el controlador retorna algo
if (is_string($result)) {
    echo $result;
}







