<?php
/**
 * Vista: Clientes
 */

use App\Core\FormGenerator;

require_once __DIR__ . '/../../app/autoload.php';

$formPath = __DIR__ . '/cliente.form.json';

if (!file_exists($formPath)) {
    throw new RuntimeException("Archivo no encontrado: {$formPath}");
}

try {
    $formConfig = json_decode(
        file_get_contents($formPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    throw new RuntimeException("JSON inválido: " . $e->getMessage());
}

$formGenerator = new FormGenerator();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Clientes</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 p-6">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Gestión de Clientes</h1>
        
        <?php
        try {
            echo $formGenerator->render($formConfig);
        } catch (Exception $e) {
            echo "<div class='p-4 bg-red-100 border border-red-400 text-red-700 rounded'>";
            echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
        ?>
    </div>

    
</body>
</html>
