<?php
// framework/public/index.php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

session_start();

// 1. Capturar la ruta. Por defecto a 'marketplace' si no hay nada
$url = isset($_GET['url']) ? rtrim($_GET['url'], '/') : 'marketplace';

// 2. Definir mapa de rutas del mundo Framework (Creators/Public)
$routes = [
    'login' => __DIR__ . '/auth/login.php',
    'register' => __DIR__ . '/auth/register.php',
    'marketplace' => __DIR__ . '/views/marketplace.php', // Lo crearemos pronto
];

// 3. Lógica de enrutado simple
if (array_key_exists($url, $routes)) {
    $file = $routes[$url];
    if (file_exists($file)) {
        require_once $file;
    } else {
        http_response_code(500);
        echo "Error interno: El archivo de la ruta '$url' no existe.";
    }
} else {
    // 404 para rutas no definidas en este mundo
    http_response_code(404);
    echo "<h1>404 - Mundo Framework</h1>";
    echo "La ruta <b>/$url</b> no pertenece a este entorno.";
}
