<?php
// app/autoload.php
spl_autoload_register(function ($class) {
    // Definimos las carpetas donde buscar
    $paths = [
        __DIR__ . '/Core/',
        __DIR__ . '/controller/'
    ];

    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});