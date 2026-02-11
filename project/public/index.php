<?php
// project/public/index.php

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
require_once PROJECT_ROOT . '/config/env_loader.php';

$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);
define('FRAMEWORK_PUBLIC_URL', rtrim(getenv('SUKI_FRAMEWORK_PUBLIC_URL') ?: '', '/'));

require_once APP_ROOT . '/vendor/autoload.php';
$viewsRoot = PROJECT_ROOT . '/views';

try {
    \App\Core\ManifestValidator::validateOrFail();
} catch (\Throwable $e) {
    http_response_code(500);
    if (file_exists($viewsRoot . '/includes/header.php')) {
        include $viewsRoot . '/includes/header.php';
    }
    $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo "<div class='p-10 bg-red-100 text-red-700'>
            <h1 class='text-2xl font-bold'>Error 500</h1>
            <p>Contrato app.manifest.json invalido.</p>
            <p>{$message}</p>
          </div>";
    if (file_exists($viewsRoot . '/includes/footer.php')) {
        include $viewsRoot . '/includes/footer.php';
    }
    exit;
}

// 1. Cargar configuración (Descomenta cuando tengas el db.php listo)
// require_once PROJECT_ROOT . '/config/db.php';

// 2. Iniciar sesión
session_start();

// 3. Capturar la URL. Si no hay nada, por defecto es 'dashboard'
$url = isset($_GET['url']) ? rtrim($_GET['url'], '/') : 'dashboard';

// 3.1 Validacion basica de ruta (bloquea .. y caracteres no permitidos)
if (strpos($url, '..') !== false || !preg_match('/^[a-zA-Z0-9\/_.-]*$/', $url)) {
    http_response_code(400);
    include $viewsRoot . '/includes/header.php';
    echo "<div class='p-10 bg-red-100 text-red-700'>
            <h1 class='text-2xl font-bold'>Error 400</h1>
            <p>Ruta no valida.</p>
          </div>";
    include $viewsRoot . '/includes/footer.php';
    exit;
}

// 4. Definir la ruta física del archivo que queremos mostrar
$archivoVista = $viewsRoot . '/' . $url . '.php';

// 5. Lógica de Enrutado
if (file_exists($archivoVista)) {
    // CARGAMOS EL ROMPECABEZAS
    require_once $viewsRoot . '/includes/header.php'; // Parte de arriba
    require_once $archivoVista;                            // El centro (dashboard.php, etc)
    require_once $viewsRoot . '/includes/footer.php'; // Parte de abajo
} else {
    // Si el archivo NO existe en la carpeta views
    http_response_code(404);
    include $viewsRoot . '/includes/header.php';
    echo "<div class='p-10 bg-red-100 text-red-700'>
            <h1 class='text-2xl font-bold'>Error 404</h1>
            <p>El archivo <b>project/views/{$url}.php</b> no existe.</p>
          </div>";
    include $viewsRoot . '/includes/footer.php';
}
// AQUÍ TERMINA EL ARCHIVO. No pongas más HTML aquí abajo.


