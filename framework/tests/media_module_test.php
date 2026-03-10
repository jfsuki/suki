<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/autoload.php';

use App\Core\ChatAgent;
use App\Core\ContractRegistry;
use App\Core\Database;
use App\Core\MediaEventLogger;
use App\Core\MediaService;
use App\Core\SkillRegistry;
use App\Core\SkillResolver;

$failures = [];
$tmpDir = FRAMEWORK_ROOT . '/tests/tmp/media_module_' . time() . '_' . random_int(1000, 9999);
@mkdir($tmpDir, 0777, true);
@mkdir($tmpDir . '/project_root', 0777, true);

$previous = [
    'APP_ENV' => getenv('APP_ENV'),
    'ALLOW_RUNTIME_SCHEMA' => getenv('ALLOW_RUNTIME_SCHEMA'),
    'PROJECT_REGISTRY_DB_PATH' => getenv('PROJECT_REGISTRY_DB_PATH'),
    'MEDIA_STORAGE_ROOT' => getenv('MEDIA_STORAGE_ROOT'),
    'MEDIA_ACCESS_SECRET' => getenv('MEDIA_ACCESS_SECRET'),
    'DB_DRIVER' => getenv('DB_DRIVER'),
    'DB_PATH' => getenv('DB_PATH'),
];

putenv('APP_ENV=local');
putenv('ALLOW_RUNTIME_SCHEMA=1');
putenv('PROJECT_REGISTRY_DB_PATH=' . $tmpDir . '/project_registry.sqlite');
putenv('MEDIA_STORAGE_ROOT=' . $tmpDir . '/storage_root');
putenv('MEDIA_ACCESS_SECRET=media-test-secret');
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $tmpDir . '/api_media.sqlite');

$pdo = new PDO('sqlite:' . $tmpDir . '/api_media.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo);

$pngPath = $tmpDir . '/sample.png';
$pdfPath = $tmpDir . '/sample.pdf';
createSamplePng($pngPath);
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

try {
    $service = new MediaService(null, null, null, new MediaEventLogger($tmpDir . '/project_root'));
    $image = $service->upload([
        'tenant_id' => 'tenant_alpha',
        'app_id' => 'media_app',
        'entity_type' => 'product',
        'entity_id' => '501',
        'source_path' => $pngPath,
        'original_name' => 'producto.png',
        'uploaded_by_user_id' => 'tester',
    ]);
    $pdf = $service->upload([
        'tenant_id' => 'tenant_alpha',
        'app_id' => 'media_app',
        'entity_type' => 'purchase',
        'entity_id' => 'PO-77',
        'source_path' => $pdfPath,
        'original_name' => 'factura_compra.pdf',
        'uploaded_by_user_id' => 'tester',
    ]);

    $productItems = $service->list('tenant_alpha', 'product', '501', 'media_app');
    $purchaseItems = $service->list('tenant_alpha', 'purchase', 'PO-77', 'media_app');
    $betaItems = $service->list('tenant_beta', 'product', '501', 'media_app');
    if (count($productItems) !== 1 || (string) ($productItems[0]['file_type'] ?? '') !== 'image') {
        $failures[] = 'El servicio debe listar la imagen del producto.';
    }
    if (count($purchaseItems) !== 1 || (string) ($purchaseItems[0]['file_type'] ?? '') !== 'pdf') {
        $failures[] = 'El servicio debe listar el PDF de la compra.';
    }
    if ($betaItems !== []) {
        $failures[] = 'El tenant beta no debe ver archivos del tenant alpha.';
    }

    $thumb = $service->generateThumbnail('tenant_alpha', (string) ($image['id'] ?? ''), 'media_app');
    $thumbVariant = $thumb['variants']['thumbnail'] ?? [];
    if (!is_array($thumbVariant) || (string) ($thumbVariant['status'] ?? '') !== 'ready') {
        $failures[] = 'generateThumbnail debe dejar la miniatura en estado ready.';
    }

    $deleted = $service->delete('tenant_alpha', (string) ($pdf['id'] ?? ''), 'media_app', 'tester');
    if (!(bool) ($deleted['deleted'] ?? false)) {
        $failures[] = 'delete debe devolver deleted=true.';
    }
} catch (Throwable $e) {
    $failures[] = 'El servicio de media debe funcionar de extremo a extremo: ' . $e->getMessage();
}

try {
    $catalog = (new ContractRegistry())->getSkillsCatalog();
    $resolver = new SkillResolver();
    $registry = new SkillRegistry($catalog);
    $resolvedUpload = $resolver->resolve('subir imagen producto entity_id=501', $registry, ['attachments_count' => 1]);
    $resolvedList = $resolver->resolve('listar archivos producto entity_id=501', $registry, []);
    $resolvedGet = $resolver->resolve('abrir archivo media_id=1', $registry, []);
    $resolvedDelete = $resolver->resolve('eliminar archivo media_id=1 confirmar=si', $registry, []);

    if ((string) (($resolvedUpload['selected']['name'] ?? '') ?: '') !== 'media_upload') {
        $failures[] = 'SkillResolver debe detectar media_upload.';
    }
    if ((string) (($resolvedList['selected']['name'] ?? '') ?: '') !== 'media_list') {
        $failures[] = 'SkillResolver debe detectar media_list.';
    }
    if ((string) (($resolvedGet['selected']['name'] ?? '') ?: '') !== 'media_get') {
        $failures[] = 'SkillResolver debe detectar media_get.';
    }
    if ((string) (($resolvedDelete['selected']['name'] ?? '') ?: '') !== 'media_delete') {
        $failures[] = 'SkillResolver debe detectar media_delete.';
    }
} catch (Throwable $e) {
    $failures[] = 'El catalogo de skills de media debe resolver correctamente: ' . $e->getMessage();
}

try {
    $agent = new ChatAgent();
    $tenantId = 'tenant_chat_media';
    $projectId = 'media_app';
    $sessionBase = 'media_chat_' . time();

    $uploadReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_upload',
        'user_id' => 'operator_media',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message' => 'subir imagen producto entity_id=501',
        'attachments' => [[
            'path' => $pngPath,
            'name' => 'producto.png',
            'type' => 'image/png',
            'size' => filesize($pngPath),
        ]],
    ]);
    $uploadData = is_array($uploadReply['data'] ?? null) ? (array) $uploadReply['data'] : [];
    $mediaId = (string) ($uploadData['media']['id'] ?? '');
    if ((string) ($uploadReply['status'] ?? '') !== 'success' || (string) ($uploadData['module_used'] ?? '') !== 'media_storage' || (string) ($uploadData['media_action'] ?? '') !== 'upload') {
        $failures[] = 'ChatAgent debe ejecutar media_upload via skill + CommandBus.';
    }

    $listReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_list',
        'user_id' => 'operator_media',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message' => 'listar archivos producto entity_id=501',
    ]);
    $listData = is_array($listReply['data'] ?? null) ? (array) $listReply['data'] : [];
    if ((string) ($listReply['status'] ?? '') !== 'success' || (string) ($listData['media_action'] ?? '') !== 'list' || count((array) ($listData['items'] ?? [])) < 1) {
        $failures[] = 'ChatAgent debe listar archivos del producto.';
    }

    $getReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_get',
        'user_id' => 'operator_media',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message' => 'abrir archivo media_id=' . $mediaId,
    ]);
    $getData = is_array($getReply['data'] ?? null) ? (array) $getReply['data'] : [];
    if ((string) ($getReply['status'] ?? '') !== 'success' || (string) ($getData['media_action'] ?? '') !== 'get') {
        $failures[] = 'ChatAgent debe recuperar metadata del archivo.';
    }

    $deleteReply = $agent->handle([
        'tenant_id' => $tenantId,
        'project_id' => $projectId,
        'session_id' => $sessionBase . '_delete',
        'user_id' => 'operator_media',
        'role' => 'admin',
        'is_authenticated' => true,
        'mode' => 'app',
        'channel' => 'test',
        'message' => 'eliminar archivo media_id=' . $mediaId . ' confirmar=si',
    ]);
    $deleteData = is_array($deleteReply['data'] ?? null) ? (array) $deleteReply['data'] : [];
    if ((string) ($deleteReply['status'] ?? '') !== 'success' || (string) ($deleteData['media_action'] ?? '') !== 'delete') {
        $failures[] = 'ChatAgent debe eliminar archivos con confirmacion explicita.';
    }
} catch (Throwable $e) {
    $failures[] = 'El flujo chat/skills de media debe pasar: ' . $e->getMessage();
}

try {
    $env = [
        'APP_ENV' => 'local',
        'ALLOW_RUNTIME_SCHEMA' => '1',
        'PROJECT_REGISTRY_DB_PATH' => $tmpDir . '/project_registry.sqlite',
        'MEDIA_STORAGE_ROOT' => $tmpDir . '/storage_root',
        'MEDIA_ACCESS_SECRET' => 'media-test-secret',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => $tmpDir . '/api_media.sqlite',
    ];
    $session = [
        'auth_user' => [
            'id' => 'api_media_user',
            'tenant_id' => 'tenant_api',
            'project_id' => 'media_app',
            'role' => 'admin',
            'label' => 'API Media',
        ],
    ];

    $upload = runApiRoute([
        'route' => 'media/upload',
        'method' => 'POST',
        'payload' => [
            'entity_type' => 'product',
            'entity_id' => '900',
            'source_path' => $pngPath,
            'original_name' => 'api_producto.png',
            'project_id' => 'media_app',
        ],
        'session' => $session,
        'env' => $env,
    ]);
    $uploadJson = $upload['json'];
    $apiMedia = is_array($uploadJson['data']['media'] ?? null) ? (array) $uploadJson['data']['media'] : [];
    $apiMediaId = (string) ($apiMedia['id'] ?? '');
    if (!is_array($uploadJson) || (string) ($uploadJson['status'] ?? '') !== 'success' || $apiMediaId === '') {
        $failures[] = 'API media/upload debe subir el archivo.';
    }

    $get = runApiRoute([
        'route' => 'media/get',
        'method' => 'GET',
        'query' => ['id' => $apiMediaId, 'project_id' => 'media_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $getJson = $get['json'];
    $token = (string) ($getJson['data']['media']['access']['original']['token'] ?? '');
    if (!is_array($getJson) || (string) ($getJson['status'] ?? '') !== 'success' || $token === '') {
        $failures[] = 'API media/get debe devolver el token firmado de acceso.';
    }

    $access = runApiRoute([
        'route' => 'media/access',
        'method' => 'GET',
        'query' => ['id' => $apiMediaId, 'variant' => 'original', 't' => $token],
        'env' => $env,
    ]);
    $accessPrefixHex = bin2hex(substr((string) $access['raw'], 0, 8));
    if (!str_starts_with($accessPrefixHex, '89504e47')) {
        $failures[] = 'API media/access debe entregar el binario protegido con token.';
    }

    $delete = runApiRoute([
        'route' => 'media/delete',
        'method' => 'POST',
        'payload' => ['id' => $apiMediaId, 'project_id' => 'media_app'],
        'session' => $session,
        'env' => $env,
    ]);
    $deleteJson = $delete['json'];
    if (!is_array($deleteJson) || (string) ($deleteJson['status'] ?? '') !== 'success') {
        $failures[] = 'API media/delete debe eliminar el archivo.';
    }
} catch (Throwable $e) {
    $failures[] = 'Las rutas API de media deben pasar: ' . $e->getMessage();
}

foreach ($previous as $key => $value) {
    restoreEnvValue($key, $value);
}

$result = ['ok' => $failures === [], 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

function createSamplePng(string $path): void
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('GD extension requerida para media_module_test.');
    }

    $image = imagecreatetruecolor(48, 48);
    if (!is_resource($image) && !($image instanceof \GdImage)) {
        throw new RuntimeException('No se pudo crear imagen de prueba.');
    }
    $bg = imagecolorallocate($image, 40, 120, 220);
    $fg = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, 48, 48, $bg);
    imagefilledellipse($image, 24, 24, 20, 20, $fg);
    imagepng($image, $path);
    imagedestroy($image);
}

/**
 * @return array{raw:string,json:array<string,mixed>|null}
 */
function runApiRoute(array $request): array
{
    $helper = __DIR__ . '/api_route_turn.php';
    $encoded = base64_encode((string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($helper) . ' ' . escapeshellarg($encoded);
    $raw = (string) shell_exec($cmd);
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
