<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\AuditLogger;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\EntityRegistry;
use App\Core\EntitySearchEventLogger;
use App\Core\EntitySearchRepository;
use App\Core\EntitySearchService;
use App\Core\MediaEventLogger;
use App\Core\MediaRepository;
use App\Core\MediaService;
use App\Core\PurchasesEventLogger;
use App\Core\PurchasesRepository;
use App\Core\PurchasesService;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/purchases_documents_module_' . time() . '_' . random_int(1000, 9999);
$tmpProjectRoot = $tmpDir . '/project_root';
@mkdir($tmpProjectRoot . '/contracts/entities', 0777, true);
@mkdir($tmpProjectRoot . '/storage/cache', 0777, true);
@mkdir($tmpProjectRoot . '/storage/meta', 0777, true);
@mkdir($tmpDir . '/storage_root', 0777, true);

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'PROJECT_REGISTRY_DB_PATH' => getenv('PROJECT_REGISTRY_DB_PATH'),
    'MEDIA_STORAGE_ROOT' => getenv('MEDIA_STORAGE_ROOT'),
    'MEDIA_ACCESS_SECRET' => getenv('MEDIA_ACCESS_SECRET'),
    'DB_DRIVER' => getenv('DB_DRIVER'),
    'DB_PATH' => getenv('DB_PATH'),
    'DB_NAMESPACE_BY_PROJECT' => getenv('DB_NAMESPACE_BY_PROJECT'),
    'PROJECT_STORAGE_MODEL' => getenv('PROJECT_STORAGE_MODEL'),
    'DB_STORAGE_MODEL' => getenv('DB_STORAGE_MODEL'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('PROJECT_REGISTRY_DB_PATH=' . $tmpDir . '/project_registry.sqlite');
putenv('MEDIA_STORAGE_ROOT=' . $tmpDir . '/storage_root');
putenv('MEDIA_ACCESS_SECRET=purchases-documents-secret');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $tmpDir . '/purchases_documents.sqlite');
putenv('DB_NAMESPACE_BY_PROJECT=0');
putenv('PROJECT_STORAGE_MODEL=legacy');
putenv('DB_STORAGE_MODEL=legacy');

$pdo = new PDO('sqlite:' . $tmpDir . '/purchases_documents.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

writeEntityContract($tmpProjectRoot, 'suppliers', 'proveedores', 'suppliers', [
    field('id', 'int', ['primary' => true, 'source' => 'system']),
    field('tenant_id', 'int'),
    field('nit', 'string'),
    field('nombre', 'string'),
    field('activo', 'bool'),
]);
seedSearchTables($pdo);

$tenantAlpha = 'tenant_alpha';
$tenantBeta = 'tenant_beta';
$tenantAlphaInt = stableTenantInt($tenantAlpha);
$tenantBetaInt = stableTenantInt($tenantBeta);
$now = new DateTimeImmutable('2026-03-11 11:00:00');

$supplierStmt = $pdo->prepare(
    'INSERT INTO suppliers (id, tenant_id, nit, nombre, activo, created_at, updated_at)
     VALUES (:id, :tenant_id, :nit, :nombre, :activo, :created_at, :updated_at)'
);
foreach ([
    ['id' => 10, 'tenant_id' => $tenantAlphaInt, 'nit' => '900111222', 'nombre' => 'Proveedor Acme', 'activo' => 1],
    ['id' => 11, 'tenant_id' => $tenantAlphaInt, 'nit' => '900333444', 'nombre' => 'Proveedor Norte', 'activo' => 1],
    ['id' => 20, 'tenant_id' => $tenantBetaInt, 'nit' => '800999000', 'nombre' => 'Proveedor Beta', 'activo' => 1],
 ] as $row) {
    $supplierStmt->execute($row + ['created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
}

$registry = new EntityRegistry(null, $tmpProjectRoot);
$entitySearch = new EntitySearchService(
    new EntitySearchRepository($pdo, $registry),
    new AuditLogger($pdo),
    new EntitySearchEventLogger($tmpProjectRoot)
);
$mediaRepository = new MediaRepository($pdo);
$mediaService = new MediaService(
    $mediaRepository,
    null,
    new AuditLogger($pdo),
    new MediaEventLogger($tmpProjectRoot)
);
$service = new PurchasesService(
    new PurchasesRepository($pdo),
    $entitySearch,
    $mediaService,
    new AuditLogger($pdo),
    new PurchasesEventLogger($tmpProjectRoot)
);

$seededMedia = [];
foreach ([
    ['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'entity_type' => 'purchase', 'entity_id' => 'draft-service', 'file_type' => 'pdf', 'storage_path' => '/storage/tenant_alpha/purchase/draft-service/factura.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048, 'uploaded_by_user_id' => 'seed_user', 'metadata' => ['original_name' => 'factura-servicio.pdf']],
    ['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'entity_type' => 'purchase', 'entity_id' => 'purchase-service', 'file_type' => 'xml', 'storage_path' => '/storage/tenant_alpha/purchase/purchase-service/factura.xml', 'mime_type' => 'application/xml', 'file_size' => 1024, 'uploaded_by_user_id' => 'seed_user', 'metadata' => ['original_name' => 'factura-servicio.xml']],
    ['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'entity_type' => 'purchase', 'entity_id' => 'draft-chat', 'file_type' => 'pdf', 'storage_path' => '/storage/tenant_alpha/purchase/draft-chat/factura-chat.pdf', 'mime_type' => 'application/pdf', 'file_size' => 4096, 'uploaded_by_user_id' => 'seed_user', 'metadata' => ['original_name' => 'factura-chat.pdf']],
    ['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'entity_type' => 'purchase', 'entity_id' => 'purchase-api', 'file_type' => 'pdf', 'storage_path' => '/storage/tenant_alpha/purchase/purchase-api/factura-api.pdf', 'mime_type' => 'application/pdf', 'file_size' => 3072, 'uploaded_by_user_id' => 'seed_user', 'metadata' => ['original_name' => 'factura-api.pdf']],
    ['tenant_id' => $tenantBeta, 'app_id' => 'purchase_app', 'entity_type' => 'purchase', 'entity_id' => 'purchase-beta', 'file_type' => 'pdf', 'storage_path' => '/storage/tenant_beta/purchase/purchase-beta/factura-beta.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048, 'uploaded_by_user_id' => 'seed_user', 'metadata' => ['original_name' => 'factura-beta.pdf']],
] as $record) {
    $seededMedia[] = $mediaRepository->insertFile($record + [
        'created_at' => $now->format('Y-m-d H:i:s'),
        'updated_at' => $now->format('Y-m-d H:i:s'),
    ]);
}

$serviceDraft = $service->createDraft(['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'currency' => 'COP']);
$serviceDraft = $service->attachSupplierToDraft([
    'tenant_id' => $tenantAlpha,
    'app_id' => 'purchase_app',
    'draft_id' => (string) ($serviceDraft['id'] ?? ''),
    'query' => 'proveedor acme',
]);
$serviceDraft = $service->addLineToDraft([
    'tenant_id' => $tenantAlpha,
    'app_id' => 'purchase_app',
    'draft_id' => (string) ($serviceDraft['id'] ?? ''),
    'product_label' => 'Arroz Premium 1Kg',
    'qty' => 2,
    'unit_cost' => 3000,
    'tax_rate' => 5,
]);
$servicePurchaseResult = $service->finalizeDraft([
    'tenant_id' => $tenantAlpha,
    'app_id' => 'purchase_app',
    'draft_id' => (string) ($serviceDraft['id'] ?? ''),
    'created_by_user_id' => 'buyer_service',
]);
$servicePurchase = is_array($servicePurchaseResult['purchase'] ?? null) ? (array) $servicePurchaseResult['purchase'] : [];
$purchaseId = (string) ($servicePurchase['id'] ?? '');
$purchaseNumber = (string) ($servicePurchase['purchase_number'] ?? '');

$draftForDocuments = $service->createDraft(['tenant_id' => $tenantAlpha, 'app_id' => 'purchase_app', 'currency' => 'COP']);
$draftForDocuments = $service->attachSupplierToDraft([
    'tenant_id' => $tenantAlpha,
    'app_id' => 'purchase_app',
    'draft_id' => (string) ($draftForDocuments['id'] ?? ''),
    'query' => 'proveedor acme',
]);
$draftForDocumentsId = (string) ($draftForDocuments['id'] ?? '');

try {
    $draftDocument = $service->attachDocumentToPurchaseDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'draft_id' => $draftForDocumentsId,
        'media_file_id' => (string) ($seededMedia[0]['id'] ?? ''),
        'document_type' => 'supplier_invoice',
        'notes' => 'Factura proveedor adjunta al borrador',
    ]);
    $draftDocumentId = (string) ($draftDocument['id'] ?? '');
    if ($draftDocumentId === '' || (string) ($draftDocument['purchase_draft_id'] ?? '') !== $draftForDocumentsId || (string) ($draftDocument['supplier_id'] ?? '') !== '10') {
        $failures[] = 'Debe asociar documento al borrador de compra preservando linkage y proveedor.';
    }

    $draftDocuments = $service->listPurchaseDocuments($tenantAlpha, ['purchase_draft_id' => $draftForDocumentsId], 'purchase_app');
    if (count($draftDocuments) !== 1 || (string) (($draftDocuments[0]['id'] ?? '') ?: '') !== $draftDocumentId) {
        $failures[] = 'Debe listar documentos vinculados al borrador.';
    }

    $loadedDraftDocument = $service->getPurchaseDocument($tenantAlpha, $draftDocumentId, 'purchase_app');
    if ((string) ($loadedDraftDocument['media_file_id'] ?? '') !== (string) ($seededMedia[0]['id'] ?? '')) {
        $failures[] = 'Debe cargar documento de compra por id.';
    }

    $purchaseDocument = $service->attachDocumentToPurchase([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'purchase_number' => $purchaseNumber,
        'media_file_id' => (string) ($seededMedia[1]['id'] ?? ''),
        'document_type' => 'supplier_xml',
    ]);
    $purchaseDocumentId = (string) ($purchaseDocument['id'] ?? '');
    if ($purchaseDocumentId === '' || (string) ($purchaseDocument['purchase_id'] ?? '') !== $purchaseId) {
        $failures[] = 'Debe asociar documento a compra finalizada por numero.';
    }

    $updatedDocument = $service->registerDocumentMetadata([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'purchase_document_id' => $purchaseDocumentId,
        'document_number' => 'FAC-9001',
        'supplier_query' => 'proveedor acme',
        'issue_date' => '2026-03-11 11:30:00',
        'total_amount' => '6300',
        'currency' => 'COP',
        'notes' => 'XML asociado',
        'metadata' => ['source' => 'manual'],
    ]);
    if ((string) ($updatedDocument['document_number'] ?? '') !== 'FAC-9001' || (string) ($updatedDocument['supplier_id'] ?? '') !== '10' || (float) ($updatedDocument['total_amount'] ?? 0) !== 6300.0) {
        $failures[] = 'Debe registrar metadata consultable para el documento de compra.';
    }

    $purchaseDocuments = $service->listPurchaseDocuments($tenantAlpha, ['purchase_number' => $purchaseNumber], 'purchase_app');
    if (count($purchaseDocuments) !== 1 || (string) (($purchaseDocuments[0]['id'] ?? '') ?: '') !== $purchaseDocumentId) {
        $failures[] = 'Debe listar documentos vinculados a la compra finalizada.';
    }

    $detached = $service->detachPurchaseDocument($tenantAlpha, $draftDocumentId, 'purchase_app');
    if (!(bool) ($detached['deleted'] ?? false)) {
        $failures[] = 'Debe desvincular documentos de compra sin borrar el asset de media.';
    }

    if ($service->listPurchaseDocuments($tenantAlpha, ['purchase_draft_id' => $draftForDocumentsId], 'purchase_app') !== []) {
        $failures[] = 'El listado del borrador debe quedar vacio despues de desvincular.';
    }
} catch (Throwable $e) {
    $failures[] = 'El servicio de documentos de compras debe funcionar de extremo a extremo: ' . $e->getMessage();
}

try {
    $service->getPurchaseDocument($tenantBeta, (string) ($purchaseDocumentId ?? ''), 'purchase_app');
    $failures[] = 'No debe permitir leer documentos de compra de otro tenant.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'PURCHASE_DOCUMENT_NOT_FOUND') {
        $failures[] = 'Tenant isolation de documentos devolvio error inesperado: ' . $e->getMessage();
    }
}

try {
    $service->attachDocumentToPurchase([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'purchase_number' => 'PUR-404',
        'media_file_id' => (string) ($seededMedia[3]['id'] ?? ''),
    ]);
    $failures[] = 'Debe bloquear referencia invalida de compra al asociar documentos.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'PURCHASE_NOT_FOUND') {
        $failures[] = 'Referencia invalida de compra devolvio error inesperado: ' . $e->getMessage();
    }
}

try {
    $service->attachDocumentToPurchaseDraft([
        'tenant_id' => $tenantAlpha,
        'app_id' => 'purchase_app',
        'draft_id' => $draftForDocumentsId,
        'media_file_id' => '999999',
    ]);
    $failures[] = 'Debe bloquear media_file_id invalido al asociar documentos.';
} catch (Throwable $e) {
    if ((string) $e->getMessage() !== 'PURCHASE_DOCUMENT_MEDIA_NOT_FOUND') {
        $failures[] = 'Media invalida devolvio error inesperado: ' . $e->getMessage();
    }
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $skillRegistry = new SkillRegistry($catalog);
    $attachDraftSkill = $resolver->resolve('adjuntar documento al borrador compra draft_id=55 media_file_id=9', $skillRegistry, []);
    $attachPurchaseSkill = $resolver->resolve('adjuntar documento compra purchase_id=77 media_file_id=9', $skillRegistry, []);
    $metadataSkill = $resolver->resolve('registrar metadata documento compra purchase_document_id=91', $skillRegistry, []);
    if ((string) (($attachDraftSkill['selected']['name'] ?? '') ?: '') !== 'purchases_attach_document_to_draft') {
        $failures[] = 'SkillResolver debe detectar purchases_attach_document_to_draft.';
    }
    if ((string) (($attachPurchaseSkill['selected']['name'] ?? '') ?: '') !== 'purchases_attach_document') {
        $failures[] = 'SkillResolver debe detectar purchases_attach_document.';
    }
    if ((string) (($metadataSkill['selected']['name'] ?? '') ?: '') !== 'purchases_register_document_metadata') {
        $failures[] = 'SkillResolver debe detectar purchases_register_document_metadata.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo de skills de documentos de compras debe resolver correctamente: ' . $e->getMessage();
}

try {
    $sharedEnv = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'MEDIA_STORAGE_ROOT' => $tmpDir . '/storage_root',
        'MEDIA_ACCESS_SECRET' => 'purchases-documents-secret',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/purchases_documents.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $sharedEnv['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($sharedEnv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $chatAttach = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'session_id' => 'purchase_docs_chat_' . time() . '_attach',
            'user_id' => 'buyer_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'adjuntar documento al borrador compra draft_id=' . $draftForDocumentsId . ' media_file_id=' . (string) ($seededMedia[2]['id'] ?? '') . ' document_type=supplier_invoice',
        ],
    ]);
    $chatDocument = is_array(($chatAttach['data']['document'] ?? null)) ? (array) $chatAttach['data']['document'] : [];
    $chatDocumentId = (string) ($chatDocument['id'] ?? '');
    if ((string) ($chatAttach['status'] ?? '') !== 'success' || (string) (($chatAttach['data']['purchases_action'] ?? '') ?: '') !== 'attach_document_to_draft' || $chatDocumentId === '') {
        $failures[] = 'ChatAgent debe asociar documentos de compra al borrador.';
    }

    $chatMetadata = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'session_id' => 'purchase_docs_chat_' . time() . '_metadata',
            'user_id' => 'buyer_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'registrar metadata documento compra purchase_document_id=' . $chatDocumentId . ' document_number=CHAT-001 supplier_query="proveedor acme"',
        ],
    ]);
    if ((string) ($chatMetadata['status'] ?? '') !== 'success' || (string) (($chatMetadata['data']['document']['document_number'] ?? '') ?: '') !== 'CHAT-001') {
        $failures[] = 'ChatAgent debe registrar metadata de documentos de compra.';
    }

    $chatList = runChatTurn([
        'project_root' => $tmpProjectRoot,
        'env' => $sharedEnv,
        'payload' => [
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'session_id' => 'purchase_docs_chat_' . time() . '_list',
            'user_id' => 'buyer_chat',
            'role' => 'admin',
            'is_authenticated' => true,
            'mode' => 'app',
            'channel' => 'test',
            'message' => 'listar documentos compra draft_id=' . $draftForDocumentsId,
        ],
    ]);
    if ((string) ($chatList['status'] ?? '') !== 'success' || (string) (($chatList['data']['purchases_action'] ?? '') ?: '') !== 'list_documents' || count((array) ($chatList['data']['items'] ?? [])) < 1) {
        $failures[] = 'ChatAgent debe listar documentos de compra.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills de documentos de compras debe pasar: ' . $e->getMessage();
}

try {
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'MEDIA_STORAGE_ROOT' => $tmpDir . '/storage_root',
        'MEDIA_ACCESS_SECRET' => 'purchases-documents-secret',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/purchases_documents.sqlite',
        'DB_NAMESPACE_BY_PROJECT' => '0',
        'PROJECT_STORAGE_MODEL' => 'legacy',
        'DB_STORAGE_MODEL' => 'legacy',
    ];
    $env['SUKI_RUNTIME_ENV_OVERRIDES_JSON'] = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $session = [
        'auth_user' => [
            'id' => 'api_buyer',
            'tenant_id' => $tenantAlpha,
            'project_id' => 'purchase_app',
            'role' => 'admin',
            'label' => 'API Buyer',
        ],
    ];

    $attach = runApiRoute([
        'route' => 'purchases/attach-document',
        'method' => 'POST',
        'payload' => [
            'project_id' => 'purchase_app',
            'purchase_number' => $purchaseNumber,
            'media_file_id' => (string) ($seededMedia[3]['id'] ?? ''),
            'document_type' => 'payment_proof',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $attachJson = $attach['json'];
    $apiDocumentId = (string) (($attachJson['data']['document']['id'] ?? '') ?: '');
    if (!is_array($attachJson) || (string) ($attachJson['status'] ?? '') !== 'success' || $apiDocumentId === '') {
        $failures[] = 'API purchases/attach-document debe vincular documentos a compras.';
    }

    $get = runApiRoute([
        'route' => 'purchases/get-document',
        'method' => 'GET',
        'query' => ['purchase_document_id' => $apiDocumentId, 'project_id' => 'purchase_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $getJson = $get['json'];
    if (!is_array($getJson) || (string) ($getJson['status'] ?? '') !== 'success' || (string) (($getJson['data']['document']['id'] ?? '') ?: '') !== $apiDocumentId) {
        $failures[] = 'API purchases/get-document debe devolver el documento exacto.';
    }

    $detach = runApiRoute([
        'route' => 'purchases/detach-document',
        'method' => 'POST',
        'payload' => ['purchase_document_id' => $apiDocumentId, 'project_id' => 'purchase_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $detachJson = $detach['json'];
    if (!is_array($detachJson) || (string) ($detachJson['status'] ?? '') !== 'success') {
        $failures[] = 'API purchases/detach-document debe desvincular documentos.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API de documentos de compras deben pasar: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

function field(string $name, string $type, array $extra = []): array
{
    return array_merge([
        'name' => $name,
        'type' => $type,
        'required' => false,
        'source' => 'form',
    ], $extra);
}

function writeEntityContract(string $projectRoot, string $name, string $label, string $tableName, array $fields): void
{
    $payload = [
        'type' => 'entity',
        'name' => $name,
        'label' => ucfirst($label),
        'version' => '1.0',
        'table' => ['name' => $tableName, 'primaryKey' => 'id', 'timestamps' => true, 'softDelete' => false, 'tenantScoped' => true],
        'fields' => $fields,
        'grids' => [],
        'relations' => [],
        'rules' => [],
        'permissions' => ['read' => ['admin', 'seller'], 'create' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']],
    ];

    file_put_contents($projectRoot . '/contracts/entities/' . $name . '.entity.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function seedSearchTables(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE suppliers (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, nit TEXT, nombre TEXT, activo INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE INDEX idx_suppliers_tenant_nit ON suppliers (tenant_id, nit)');
    $pdo->exec('CREATE INDEX idx_suppliers_tenant_nombre ON suppliers (tenant_id, nombre)');
}

function stableTenantInt(string $tenantId): int
{
    $hash = crc32((string) $tenantId);
    $unsigned = (int) sprintf('%u', $hash);
    $max = 2147483647;
    $value = $unsigned % $max;
    return $value > 0 ? $value : 1;
}

function runChatTurn(array $request): array
{
    $helper = __DIR__ . '/entity_search_chat_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded));
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Respuesta invalida del helper de chat: ' . $raw);
    }

    return $json;
}

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runApiRoute(array $request): array
{
    $helper = __DIR__ . '/api_route_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded));
    $json = json_decode($raw, true);

    return ['raw' => $raw, 'json' => is_array($json) ? $json : null];
}

/**
 * @param string|false $value
 */
function restoreEnvValue(string $key, $value): void
{
    if ($value === false) {
        putenv($key);
        return;
    }
    putenv($key . '=' . $value);
}
