<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\PdfExtractorService;

$pdfPath = 'C:\Users\PortatilHP2\Desktop\felits\14926779837.pdf';

echo "--- PRUEBA DE EXTRACCIÓN DE RUT ---\n";
echo "Archivo: $pdfPath\n";

if (!is_file($pdfPath)) {
    echo "ERROR: El archivo no existe en la ruta especificada.\n";
    exit(1);
}

$extractor = new PdfExtractorService();
$result = $extractor->extractFromRut($pdfPath);

echo "\nRESULTADOS:\n";
echo "---------------------------------\n";
echo "País Detectado:   " . ($result['country'] ?? 'N/A') . "\n";
echo "NIT:              " . ($result['nit'] ?? 'No detectado') . "\n";
echo "Nombre/Razón:     " . ($result['full_name'] ?? 'No detectado') . "\n";
echo "Es P. Jurídica:   " . (($result['is_pj'] ?? false) ? 'SÍ' : 'NO') . "\n";
echo "Email (RUT):      " . ($result['email'] ?? 'No detectado') . "\n";
echo "Teléfono (RUT):    " . ($result['phone'] ?? 'No detectado') . "\n";

if (isset($result['location'])) {
    echo "Depto:            " . ($result['location']['department'] ?? 'N/A') . "\n";
    echo "Ciudad:           " . ($result['location']['city'] ?? 'N/A') . "\n";
    echo "Dirección:        " . ($result['location']['address'] ?? 'N/A') . "\n";
}

if (isset($result['activities'])) {
    echo "Act. Principal:   " . ($result['activities']['primary'] ?? 'N/A') . "\n";
}

echo "Responsabilidades:\n";
if (isset($result['responsibilities_desc'])) {
    foreach ($result['responsibilities_desc'] as $code => $desc) {
        echo "  - [$code] $desc\n";
    }
}

if (isset($result['error'])) {
    echo "ERROR INTERNO: " . $result['error'] . "\n";
}

echo "---------------------------------\n";
echo "Prueba finalizada.\n";
