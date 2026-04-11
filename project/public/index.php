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

// 0. Single Concurrent Session Check
if (class_exists(\App\Core\AuthMiddleware::class)) {
    \App\Core\AuthMiddleware::checkConcurrentSession(false);
}

// 1. Capturar la ruta y limpiar
$url = isset($_GET['url']) ? rtrim($_GET['url'], '/') : 'dashboard';
if ($url === '') {
    $url = 'dashboard';
}

// 2. SEGURIDAD ESTRICTA:
// - Dashboard requiere sesión activa.
// - Builder requiere sesión de CREADOR.
// - App requiere sesión de EMPRESA.
// - Rutas públicas: register-enterprise.

$is_public_project_route = in_array($url, ['register-enterprise']);
$is_tower_authenticated = isset($_SESSION['suki_tower_auth']) && $_SESSION['suki_tower_auth'] === true;

if (!$is_public_project_route && !isset($_SESSION['user_id']) && !$is_tower_authenticated) {
    // Usar rutas absolutas — siempre se sirve desde la raíz del VirtualHost
    if ($url === 'builder') {
        header('Location: /builder-login');
    } else {
        header('Location: /marketplace/login');
    }
    exit;
}

// 3. Mapa de Vistas Protegidas
$viewsRoot = PROJECT_ROOT . '/views';

$routes = [
    'dashboard'           => $viewsRoot . '/dashboard.php',
    'builder'             => $viewsRoot . '/chat/builder.php',
    'editor'              => $frameworkRoot . '/views/builder/formjson.php',
    'app'                 => $viewsRoot . '/chat/app.php',
    'register-enterprise' => $viewsRoot . '/register_enterprise.php',
];

if (array_key_exists($url, $routes)) {
    $file = $routes[$url];
    if (file_exists($file)) {
        // MVC Simple: Solo incluimos, el archivo ya es .php ahora
        if ($url === 'register-enterprise' || $url === 'builder' || $url === 'editor') {
            // No incluimos header/footer para el registro o el builder (tienen sus propios layouts)
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
