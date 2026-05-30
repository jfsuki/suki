<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/autoload.php';

use App\Core\Grounding\TaskGroundingManifest;

$PHP_BIN = PHP_BINARY;
$catalogPath = dirname(__DIR__, 2) . '/docs/contracts/skills_catalog.json';

echo '=== SUKI Grounding Manifest Verification ===' . PHP_EOL;
echo 'Catalogo: ' . $catalogPath . PHP_EOL;

$manifest = new TaskGroundingManifest($catalogPath);
$built = $manifest->build();

if (empty($built)) {
    echo 'ERROR: No se pudo cargar el catalogo o no hay skills con handler.' . PHP_EOL;
    exit(1);
}

// Contar total skills con handler
$totalSkills = 0;
foreach ($built as $skills) {
    $totalSkills += count($skills);
}

echo 'Skills con handler: ' . $totalSkills . PHP_EOL;
echo 'Topic clusters: ' . count($built) . PHP_EOL;
echo PHP_EOL;

$hasErrors = false;

foreach ($built as $cluster => $skills) {
    echo "[{$cluster}] — " . count($skills) . " skills" . PHP_EOL;
    foreach ($skills as $skill) {
        $name    = str_pad((string) $skill['name'], 40);
        $handler = (string) $skill['handler'];

        // Verificar que la clase PHP existe
        $classRef = explode('@', $handler)[0];
        if (class_exists($classRef)) {
            $status = '[OK]';
        } else {
            $status = '[MISSING]';
            $hasErrors = true;
        }

        // Nombre corto de la clase para legibilidad
        $classParts = explode('\\', $classRef);
        $shortClass = end($classParts);

        echo "  {$name} => {$shortClass}  {$status}" . PHP_EOL;
    }
    echo PHP_EOL;
}

// Verificar skills sin topic_cluster que tienen handler (debe ser 0)
$raw = file_get_contents($catalogPath);
$data = json_decode($raw, true);
$missingCluster = 0;
foreach ($data['catalog'] ?? [] as $skill) {
    if (!empty($skill['handler']) && empty($skill['topic_cluster'])) {
        $missingCluster++;
        echo 'WARN: skill "' . $skill['name'] . '" tiene handler pero sin topic_cluster' . PHP_EOL;
    }
}
echo 'Skills sin topic_cluster (con handler): ' . $missingCluster . '  <- debe ser 0' . PHP_EOL;

// Verificar que no hay clases faltantes
$missingClasses = 0;
foreach ($built as $skills) {
    foreach ($skills as $skill) {
        $classRef = explode('@', (string) $skill['handler'])[0];
        if (!class_exists($classRef)) {
            $missingClasses++;
        }
    }
}
echo 'Handler classes faltantes: ' . $missingClasses . PHP_EOL;

if ($hasErrors || $missingCluster > 0 || $missingClasses > 0) {
    echo PHP_EOL . 'Exit: 1 (hay errores)' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Exit: 0' . PHP_EOL;
exit(0);
