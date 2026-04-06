<?php
// framework/public/index.php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Capturar la ruta. Por defecto a 'marketplace'
$url = isset($_GET['url']) ? trim($_GET['url'], '/') : 'marketplace';

// 2. Definición de Rutas y Permisos
// 'view' => path relativo a framework/views/
$routes = [
    'login'         => ['view' => 'auth/login.php',         'public' => true],
    'builder-login' => ['view' => 'auth/builder_login.php', 'public' => true],
    'register'      => ['view' => 'auth/register.php',      'public' => true],
    'marketplace' => ['view' => 'marketplace.php',      'public' => true],
    'builder'     => ['view' => 'builder/chat_builder.php', 'role' => 'creator'],
    'editor'      => ['view' => 'builder/formjson.php',     'role' => 'creator'],
];

// 3. Lógica de Enrutado y Seguridad
if (array_key_exists($url, $routes)) {
    $route = $routes[$url];
    
    // Verificar Seguridad
    if (!($route['public'] ?? false)) {
        if (!isset($_SESSION['user_id'])) {
            header('Location: login'); // Redirigir a login (enmascarado)
            exit;
        }
        if (isset($route['role']) && ($_SESSION['role'] ?? '') !== $route['role']) {
            http_response_code(403);
            echo "Acceso Denegado: Se requiere rol " . $route['role'];
            exit;
        }
    }

    $viewFile = __DIR__ . '/../views/' . $route['view'];
    
    if (file_exists($viewFile)) {
        // Cargar la vista desde la capa oculta
        require_once $viewFile;
    } else {
        http_response_code(500);
        echo "Error interno: El componente '$url' no está disponible.";
    }
} else {
    http_response_code(404);
    echo "<h1>404 - SUKI OS</h1>";
    echo "La ruta <b>/$url</b> no existe en este mundo.";
}
