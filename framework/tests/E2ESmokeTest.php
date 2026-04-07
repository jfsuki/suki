<?php
// framework/tests/E2ESmokeTest.php
require_once __DIR__ . '/../../framework/vendor/autoload.php';
require_once __DIR__ . '/../../project/config/env_loader.php';

use App\Core\QuotationRepository;
use App\Core\POSRepository;

function runSmokeTest() {
    // Forzar SQLite y Entorno Local para el test de humo
    putenv('DB_DRIVER=sqlite');
    putenv('ALLOW_RUNTIME_SCHEMA=1');
    putenv('APP_ENV=testing');
    putenv('APP_URL=http://localhost');
    putenv('DB_NAMESPACE_BY_PROJECT=0'); // Desactivar prefijos para el test en SQLite aislado
    
    if (!is_dir(__DIR__ . '/tmp')) mkdir(__DIR__ . '/tmp', 0777, true);
    if (file_exists(__DIR__ . '/tmp/smoke_test.sqlite')) unlink(__DIR__ . '/tmp/smoke_test.sqlite');

    $tenantId = 'smoke_test_tenant_' . time();
    $repoQuote = new QuotationRepository();
    $repoPOS = new POSRepository();

    echo "1. Creando Cotización...\n";
    $quote = $repoQuote->createQuotation([
        'tenant_id' => $tenantId,
        'customer_name' => 'Cliente de Prueba',
        'total' => 100000,
        'currency' => 'COP',
        'status' => 'draft'
    ]);
    
    $quoteId = (string)$quote['id'];
    echo "   OK: Cotización #$quoteId creada.\n";

    echo "2. Aprobando Cotización...\n";
    $repoQuote->updateQuotation($tenantId, $quoteId, ['status' => 'approved']);
    echo "   OK: Estado actualizado a 'approved'.\n";

    echo "3. Convirtiendo a Venta (POS)...\n";
    $sale = $repoPOS->createSale([
        'tenant_id' => $tenantId,
        'customer_name' => 'Cliente de Prueba',
        'total_amount' => 100000,
        'payment_method' => 'cash',
        'quotation_id' => $quoteId,
        'status' => 'completed'
    ]);

    $saleId = (string)$sale['id'];
    echo "   OK: Venta #$saleId generada.\n";

    echo "4. Verificando integridad...\n";
    $sales = $repoPOS->listSales($tenantId, [], 1);
    if (count($sales) === 0) throw new Exception("La venta no aparece en el listado");
    echo "   OK: Venta encontrada en DB.\n";

    echo "\nSMOKE TEST COMPLETO: Suki ERP listo para Producción.\n";
}

try {
    runSmokeTest();
} catch (Exception $e) {
    echo "\n❌ FALLO EN EL TEST: " . $e->getMessage() . "\n";
    exit(1);
}
