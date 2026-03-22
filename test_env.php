<?php
require_once __DIR__ . '/framework/vendor/autoload.php';
require_once __DIR__ . '/framework/app/autoload.php';
echo "PROJECT_ROOT: " . PROJECT_ROOT . "\n";
echo "GEMINI_API_KEY: " . getenv('GEMINI_API_KEY') . "\n";
echo "DB_USER: " . getenv('DB_USER') . "\n";
