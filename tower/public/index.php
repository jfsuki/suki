<?php
// tower/public/index.php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// Cargar variables del entorno (.env) del proyecto
$envLoader = __DIR__ . '/../../project/config/env_loader.php';
if (file_exists($envLoader)) {
    require_once $envLoader;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Capturar la ruta. 'tower' se mapea a dashboard
$url = isset($_GET['url']) ? trim($_GET['url'], '/') : 'dashboard';
if ($url === '' || $url === 'torre' || $url === 'tower') {
    $url = 'dashboard';
}

// 2. Seguridad: La Torre solo se abre con el Master Key en sesión
// SUKI_MASTER_KEY DEBE estar definida en el entorno (.env o variable de servidor).
// No hay default — si falta, la Torre no arranca para evitar acceso con claves conocidas.
$masterKey = trim((string) getenv('SUKI_MASTER_KEY'));
if ($masterKey === '') {
    http_response_code(503);
    echo '<h1>Configuration Error</h1><p>SUKI_MASTER_KEY is not set. Set it in your environment before accessing the Control Tower.</p>';
    exit;
}
$error = '';

// Procesar Login si se envía el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['master_key'])) {
    if ($_POST['master_key'] === $masterKey) {
        $_SESSION['suki_tower_auth'] = true;
        header('Location: /torre');
        exit;
    } else {
        $error = 'Master Key inválida. Intento registrado en SecurityHub.';
    }
}

$is_authenticated = isset($_SESSION['suki_tower_auth']) && $_SESSION['suki_tower_auth'] === true;

// 3. Mapa de Rutas de la Torre (Vistas ocultas en framework/views/auth/)
$viewsDir = __DIR__ . '/../../framework/views/auth/';
$routes = [
    'dashboard' => $viewsDir . 'tower_x92.php',
    'editor'    => __DIR__ . '/../../framework/views/builder/formjson.php',
    'builder'   => __DIR__ . '/../../project/views/chat/builder.php',
];

// Forzar noindex para todo el mundo Tower
header('X-Robots-Tag: noindex, nofollow', true);

// 4. Lógica de Enrutado
if (!$is_authenticated) {
    // Si no está autenticado, cargamos el formulario Master Key
    require_once $viewsDir . 'tower_login.php';
    exit;
}

if (array_key_exists($url, $routes)) {
    $file = $routes[$url];
    if (file_exists($file)) {
        require_once $file;
    } else {
        http_response_code(500);
        echo "Error interno: El componente de la torre '$url' no existe.";
    }
} else {
    // Si la ruta no existe en la torre, lanzamos un 404 que será capturado por el web.config
    // o mostramos el dashboard por defecto si es una ruta raíz
    http_response_code(404);
    require_once __DIR__ . '/../../framework/views/errors/suki_error.php';
}
