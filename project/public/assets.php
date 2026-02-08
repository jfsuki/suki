<?php
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
require_once PROJECT_ROOT . '/config/env_loader.php';

$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);

$relativePath = ltrim((string)($_GET['path'] ?? ''), '/');
$relativePath = str_replace(['..', '\\'], '', $relativePath);

$assetPath = FRAMEWORK_ROOT . '/public/assets/' . $relativePath;

if ($relativePath === '' || !is_file($assetPath)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'js' => 'application/javascript',
    'css' => 'text/css',
    'json' => 'application/json',
    'map' => 'application/json',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
];

$mime = $mimeTypes[$ext] ?? (function_exists('mime_content_type') ? mime_content_type($assetPath) : 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($assetPath));

readfile($assetPath);
