<?php
// project/public/index.php
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
$frameworkRoot = dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);

require_once APP_ROOT . '/vendor/autoload.php';

// El router centraliza el inicio de sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Capturar la ruta y limpiar
$url = isset($_GET['url']) ? rtrim($_GET['url'], '/') : 'dashboard';

// 2. SEGURIDAD ESTRICTA:
// - Builder requiere sesión de CREADOR.
// - App requiere sesión de EMPRESA (por ahora compartimos user_id, pero se separará).
// - Rutas públicas: register-enterprise.

$is_builder_route = in_array($url, ['builder', 'workflow_builder']);
$is_app_route = ($url === 'app');
$is_public_project_route = in_array($url, ['register-enterprise']);

if (($is_builder_route || $is_app_route) && !isset($_SESSION['user_id'])) {
    // Redirigir al login del framework si no hay sesión para rutas privadas
    header('Location: ../../framework/public/login');
    exit;
}

// 3. Mapa de Vistas Protegidas
$viewsRoot = PROJECT_ROOT . '/views';

$routes = [
    'dashboard'           => $viewsRoot . '/dashboard.php',
    'builder'             => $viewsRoot . '/chat/builder.php',
    'app'                 => $viewsRoot . '/chat/app.php',
    'register-enterprise' => $viewsRoot . '/register_enterprise.php',
];

if (array_key_exists($url, $routes)) {
    $file = $routes[$url];
    if (file_exists($file)) {
        // MVC Simple: Solo incluimos, el archivo ya es .php ahora
        if ($file === $viewsRoot . '/register_enterprise.php') {
            // No incluimos header/footer para el registro si tiene su propio layout premium
            require_once $file;
        } else {
            if (file_exists($viewsRoot . '/includes/header.php')) include $viewsRoot . '/includes/header.php';
            require_once $file;
            if (file_exists($viewsRoot . '/includes/footer.php')) include $viewsRoot . '/includes/footer.php';
        }
    } else {
        http_response_code(500);
        echo "Error: El archivo para '$url' [".basename($file)."] no existe.";
    }
} else {
    http_response_code(404);
    echo "<h1>404 - Mundo Proyecto</h1>";
    echo "Área restringida o ruta no válida.";
}
