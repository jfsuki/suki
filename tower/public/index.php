<?php
// tower/public/index.php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

session_start();

// 1. Redirección forzada: Este archivo asume el rol de tower_x92.php
// Pero en una estructura MVC limpia dentro del mundo /tower/

$url = isset($_GET['url']) ? rtrim($_GET['url'], '/') : 'dashboard';

// 2. Seguridad: La Torre solo se abre con el Master Key en sesión
$is_authenticated = isset($_SESSION['suki_tower_auth']) && $_SESSION['suki_tower_auth'] === true;

// Si no está autenticado, solo permitimos ver la pantalla de "Entrada Maestra"
if (!$is_authenticated) {
    // Aquí implementaremos un formulario minimalista de Master Key
    // Por ahora, simulamos la lógica que antes estaba en tower_x92.php
    require_once __DIR__ . '/../../framework/public/auth/tower_x92.php'; 
    exit;
}

// 3. Mapa de Rutas de la Torre (Solo accesibles si $is_authenticated)
$routes = [
    'dashboard' => __DIR__ . '/../../framework/public/auth/tower_x92.php', // Por ahora reusamos el archivo existente
    'creators' => null, // Próximamente
    'marketplace' => null, // Próximamente
];

if (array_key_exists($url, $routes) && $routes[$url]) {
    require_once $routes[$url];
} else {
    http_response_code(404);
    echo "<h1>404 - Mundo Tower</h1>";
    echo "Área restringida o ruta no definida.";
}
