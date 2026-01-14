<?php
// public/index.php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// 1. Cargar configuración (Descomenta cuando tengas el db.php listo)
// require_once __DIR__ . '/../config/db.php';

// 2. Iniciar sesión
session_start();

// 3. Capturar la URL. Si no hay nada, por defecto es 'dashboard'
$url = isset($_GET['url']) ? rtrim($_GET['url'], '/') : 'dashboard';

// 4. Definir la ruta física del archivo que queremos mostrar
$archivoVista = __DIR__ . '/../views/' . $url . '.php';

// 5. Lógica de Enrutado
if (file_exists($archivoVista)) {
    // CARGAMOS EL ROMPECABEZAS
    require_once __DIR__ . '/../views/includes/header.php'; // Parte de arriba
    require_once $archivoVista;                            // El centro (dashboard.php, etc)
    require_once __DIR__ . '/../views/includes/footer.php'; // Parte de abajo
} else {
    // Si el archivo NO existe en la carpeta views
    http_response_code(404);
    include __DIR__ . '/../views/includes/header.php';
    echo "<div class='p-10 bg-red-100 text-red-700'>
            <h1 class='text-2xl font-bold'>Error 404</h1>
            <p>El archivo <b>views/{$url}.php</b> no existe.</p>
          </div>";
    include __DIR__ . '/../views/includes/footer.php';
}
// AQUÍ TERMINA EL ARCHIVO. No pongas más HTML aquí abajo.