<?php
/**
 * Vista: Cuentas por Cobrar
 */

use App\Core\FormGenerator;
use App\Core\Contracts\ContractRepository;

$frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2) . '/framework';
require_once $frameworkRoot . '/app/autoload.php';

$projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 1);
$contractRepo = new ContractRepository($frameworkRoot, $projectRoot);
$formConfig = $contractRepo->getForm('cuentasxcobrar.form');

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
