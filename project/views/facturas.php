<?php
use App\Core\FormGenerator;

$frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2) . '/framework';
require_once $frameworkRoot . '/app/autoload.php';

$formGenerator = new FormGenerator();

$formPathCandidates = [
    __DIR__ . '/../contracts/forms/fact.form.json',
    __DIR__ . '/clientes/fact.form.json',
];

$formPath = null;
foreach ($formPathCandidates as $candidate) {
    if (file_exists($candidate)) {
        $formPath = $candidate;
        break;
    }
}

if ($formPath === null) {
    throw new RuntimeException("Archivo no encontrado: fact.form.json");
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
?>

<h1 class="text-2xl font-bold mb-4">Nueva Factura</h1>

<?php
try {
    echo $formGenerator->render($formConfig);
} catch (Exception $e) {
    echo "<div class='p-4 bg-red-100 border border-red-400 text-red-700 rounded'>";
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>
