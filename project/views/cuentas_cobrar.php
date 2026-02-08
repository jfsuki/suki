<?php
/**
 * Vista: Cuentas por Cobrar
 */

use App\Core\FormGenerator;

$frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2) . '/framework';
require_once $frameworkRoot . '/app/autoload.php';

$projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 1);

$formPathCandidates = [
    $projectRoot . '/contracts/forms/cuentasxcobrar.form.json',
    __DIR__ . '/cuentasxcobrar.form.json',
];

$formPath = null;
foreach ($formPathCandidates as $candidate) {
    if (file_exists($candidate)) {
        $formPath = $candidate;
        break;
    }
}

if ($formPath === null) {
    throw new RuntimeException("Archivo no encontrado: cuentasxcobrar.form.json");
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
<div class="max-w-6xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Cuentas por Cobrar</h1>
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
