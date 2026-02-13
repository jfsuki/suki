<?php
// project/public/api.php

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
require_once PROJECT_ROOT . '/config/env_loader.php';

$frameworkRoot = getenv('SUKI_FRAMEWORK_ROOT') ?: dirname(PROJECT_ROOT) . '/framework';
define('APP_ROOT', $frameworkRoot);
define('FRAMEWORK_ROOT', $frameworkRoot);

require_once APP_ROOT . '/vendor/autoload.php';
require_once APP_ROOT . '/app/autoload.php';

use App\Core\Response;
use App\Core\CommandLayer;
use App\Core\Contracts\ContractRepository;
use App\Core\ReportEngine;
use App\Core\DashboardEngine;
use App\Core\FormWizard;
use App\Core\CsvImportService;
use App\Core\EntityRegistry;
use App\Core\EntityMigrator;
use App\Core\IntegrationRegistry;
use App\Core\IntegrationValidator;
use App\Core\IntegrationStore;
use App\Core\IntegrationMigrator;
use App\Core\AlanubeClient;
use App\Core\InvoiceRegistry;
use App\Core\InvoiceValidator;
use App\Core\InvoiceMapper;
use App\Core\Database;
use App\Core\QueryBuilder;

$route = trim($_GET['route'] ?? '');
$manifestError = null;
try {
    \App\Core\ManifestValidator::validateOrFail();
} catch (\Throwable $e) {
    $manifestError = $e;
}

// --------------------------------
// CORS (solo si se configura allowlist)
// --------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = getenv('SUKI_ALLOWED_ORIGINS') ?: '';
if ($origin && $allowedOrigins !== '') {
    $list = array_map('trim', explode(',', $allowedOrigins));
    if (in_array($origin, $list, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

// --------------------------------
// Inicializar respuesta
// --------------------------------
$response = new Response();
if ($manifestError) {
    $allowedWithoutManifest = [
        'integrations/alanube/test',
        'integrations/alanube/save',
        'integrations/alanube/webhook',
    ];
    if (in_array($route, $allowedWithoutManifest, true)) {
        // allow integration setup even if manifest missing
    } else {
        http_response_code(500);
        echo $response->json('error', 'App manifest invalido: ' . $manifestError->getMessage());
        return;
    }
}

function requestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return [];
}

function respondJson(Response $response, string $status, string $message, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo $response->json($status, $message, $data);
}

function writeJsonFile(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio: ' . $dir);
        }
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo serializar JSON.');
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('No se pudo escribir archivo: ' . $path);
    }
}

function tokenizeChatMessage(string $message): array
{
    $tokens = [];
    $len = strlen($message);
    $buf = '';
    $inQuote = false;
    $quoteChar = '';

    for ($i = 0; $i < $len; $i++) {
        $ch = $message[$i];
        if ($inQuote) {
            if ($ch === $quoteChar) {
                $inQuote = false;
                continue;
            }
            if ($ch === '\\' && $i + 1 < $len) {
                $buf .= $message[$i + 1];
                $i++;
                continue;
            }
            $buf .= $ch;
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $inQuote = true;
            $quoteChar = $ch;
            continue;
        }
        if (ctype_space($ch)) {
            if ($buf !== '') {
                $tokens[] = $buf;
                $buf = '';
            }
            continue;
        }
        $buf .= $ch;
    }

    if ($buf !== '') {
        $tokens[] = $buf;
    }

    return $tokens;
}

function parseChatMessage(array $payload): array
{
    $message = trim((string) ($payload['message'] ?? $payload['text'] ?? ''));
    if ($message === '') {
        return ['error' => 'Mensaje vacio'];
    }

    $tokens = tokenizeChatMessage($message);
    if (count($tokens) === 0) {
        return ['error' => 'Mensaje vacio'];
    }

    $verb = strtolower(array_shift($tokens));
    $verbMap = [
        'crear' => 'CreateRecord',
        'nuevo' => 'CreateRecord',
        'agregar' => 'CreateRecord',
        'add' => 'CreateRecord',
        'listar' => 'QueryRecords',
        'lista' => 'QueryRecords',
        'ver' => 'QueryRecords',
        'buscar' => 'QueryRecords',
        'consulta' => 'QueryRecords',
        'actualizar' => 'UpdateRecord',
        'editar' => 'UpdateRecord',
        'update' => 'UpdateRecord',
        'eliminar' => 'DeleteRecord',
        'borrar' => 'DeleteRecord',
        'delete' => 'DeleteRecord',
        'leer' => 'ReadRecord',
    ];

    if (!isset($verbMap[$verb])) {
        return ['error' => 'Verbo no soportado'];
    }

    $entity = '';
    $data = [];
    $filters = [];
    $id = null;

    foreach ($tokens as $token) {
        if (strpos($token, '=') !== false || strpos($token, ':') !== false) {
            $sep = strpos($token, '=') !== false ? '=' : ':';
            [$rawKey, $rawVal] = array_pad(explode($sep, $token, 2), 2, '');
            $key = trim($rawKey);
            $val = trim($rawVal);
            if ($key === '') {
                continue;
            }
            if (strtolower($key) === 'id') {
                $id = $val;
                continue;
            }
            $data[$key] = $val;
            $filters[$key] = $val;
            continue;
        }

        if ($entity === '') {
            $entity = $token;
        }
    }

    if ($entity === '') {
        $entity = (string) ($payload['entity'] ?? '');
    }

    if ($entity === '') {
        return ['error' => 'Entidad requerida'];
    }

    if ($verbMap[$verb] === 'QueryRecords' && $id !== null && $id !== '') {
        $verbMap[$verb] = 'ReadRecord';
    }

    return [
        'command' => $verbMap[$verb],
        'entity' => $entity,
        'data' => $data,
        'filters' => $filters,
        'id' => $id,
    ];
}

function fetchGridItems(array $entity, string $gridName, $recordId): array
{
    if ($gridName === '' || $recordId === null || $recordId === '') {
        return [];
    }
    $gridTable = '';
    $fk = '';
    foreach (($entity['grids'] ?? []) as $grid) {
        if (!is_array($grid)) {
            continue;
        }
        if (($grid['name'] ?? '') === $gridName) {
            $gridTable = (string) ($grid['table'] ?? '');
            $fk = (string) ($grid['relation']['fk'] ?? '');
            break;
        }
    }
    if ($gridTable === '') {
        $gridTable = ($entity['table']['name'] ?? '') . '__' . $gridName;
    }
    if ($fk === '') {
        $fk = ($entity['table']['name'] ?? 'parent') . '_id';
    }

    $db = Database::connection();
    $qb = new QueryBuilder($db, $gridTable);
    $qb->setAllowedColumns([]);
    return $qb->where($fk, '=', $recordId)->get();
}

// --------------------------------
// 1. Obtener la ruta
// --------------------------------
if ($route === '') {
    echo $response->json('error', 'Ruta no definida');
    return;
}

// --------------------------------
// 1.1 Endpoints especiales (contracts, records, command)
// --------------------------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (str_starts_with($route, 'contracts/')) {
    $parts = explode('/', $route);
    $type = $parts[1] ?? '';
    if ($type !== 'form' && $type !== 'forms') {
        respondJson($response, 'error', 'Tipo de contrato no soportado', [], 400);
        return;
    }
    $key = $parts[2] ?? '';
    $module = $parts[3] ?? null;
    if ($key === '') {
        respondJson($response, 'error', 'Nombre de contrato requerido', [], 400);
        return;
    }

    try {
        $repo = new ContractRepository();
        $meta = $repo->getFormMeta($key, $module);
        $etag = '"' . sha1($meta['path'] . '|' . $meta['mtime'] . '|' . $meta['size']) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=60');

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch === $etag) {
            http_response_code(304);
            return;
        }

        respondJson($response, 'success', 'Contrato cargado', $meta['data']);
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
    }
    return;
}

if (str_starts_with($route, 'reports/')) {
    $parts = explode('/', $route);
    $action = $parts[1] ?? 'preview';
    $formKey = (string) ($_GET['form'] ?? '');
    $reportKey = (string) ($_GET['report'] ?? '');
    $entity = (string) ($_GET['entity'] ?? '');
    $recordId = $_GET['id'] ?? null;

    if ($formKey === '') {
        respondJson($response, 'error', 'form requerido', [], 400);
        return;
    }

    try {
        $engine = new ReportEngine();
        if ($action === 'pdf') {
            $pdf = $engine->renderPdf($formKey, $reportKey, $recordId, $entity ?: null);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename=\"reporte.pdf\"');
            echo $pdf;
            return;
        }

        $html = $engine->renderPreview($formKey, $reportKey, $recordId, $entity ?: null);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if (str_starts_with($route, 'dashboards')) {
    $formKey = (string) ($_GET['form'] ?? '');
    $dashKey = (string) ($_GET['dashboard'] ?? '');
    $entity = (string) ($_GET['entity'] ?? '');
    if ($formKey === '') {
        respondJson($response, 'error', 'form requerido', [], 400);
        return;
    }

    try {
        $engine = new DashboardEngine();
        $data = $engine->build($formKey, $dashKey, $entity ?: null);
        respondJson($response, 'success', 'Dashboard listo', $data);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'wizard/form-from-entity') {
    $payload = requestData();
    $entityName = (string) ($payload['entity'] ?? $_GET['entity'] ?? '');
    if ($entityName === '') {
        respondJson($response, 'error', 'entity requerido', [], 400);
        return;
    }

    try {
        $registry = new EntityRegistry();
        $entity = $registry->get($entityName);
        $wizard = new FormWizard();
        $options = [
            'master_detail' => !empty($payload['master_detail']) || !empty($payload['masterDetail']),
            'report_type' => (string) ($payload['report_type'] ?? $payload['reportType'] ?? ''),
            'template' => (string) ($payload['template'] ?? ''),
        ];
        $form = $wizard->buildFromEntity($entity, $options);

        $saved = false;
        if (!empty($payload['save'])) {
            $fileName = $form['name'] . '.json';
            $path = PROJECT_ROOT . '/contracts/forms/' . $fileName;
            writeJsonFile($path, $form);
            $saved = true;
        }

        respondJson($response, 'success', 'Formulario generado', [
            'form' => $form,
            'saved' => $saved,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'entity/save') {
    $payload = requestData();
    $entity = $payload['entity'] ?? null;
    if (!is_array($entity)) {
        respondJson($response, 'error', 'entity requerido', [], 400);
        return;
    }
    try {
        $name = $entity['name'] ?? '';
        if ($name === '') {
            respondJson($response, 'error', 'entity.name requerido', [], 400);
            return;
        }
        $fileName = $name . '.entity.json';
        $path = PROJECT_ROOT . '/contracts/entities/' . $fileName;
        writeJsonFile($path, $entity);
        $migrator = new EntityMigrator(new EntityRegistry());
        $migration = $migrator->migrateEntity($entity, true);
        respondJson($response, 'success', 'Entidad guardada', [
            'file' => $fileName,
            'migration' => $migration,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'import/csv') {
    $payload = requestData();
    try {
        $service = new CsvImportService();
        $result = $service->import($payload);
        $entity = $result['entity'];
        $fileName = ($entity['name'] ?? 'entity') . '.entity.json';
        $path = PROJECT_ROOT . '/contracts/entities/' . $fileName;
        writeJsonFile($path, $entity);

        $form = null;
        if (!empty($payload['createForm'])) {
            $wizard = new FormWizard();
            $form = $wizard->buildFromEntity($entity);
            $formFile = $form['name'] . '.json';
            writeJsonFile(PROJECT_ROOT . '/contracts/forms/' . $formFile, $form);
        }

        respondJson($response, 'success', 'CSV importado', [
            'entity' => $entity,
            'migration' => $result['migration'] ?? [],
            'form' => $form,
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/test') {
    $payload = requestData();
    $baseUrl = (string) ($payload['base_url'] ?? '');
    $tokenEnv = (string) ($payload['token_env'] ?? 'ALANUBE_TOKEN');
    $token = (string) ($payload['token'] ?? getenv($tokenEnv) ?: '');
    if ($baseUrl === '') {
        respondJson($response, 'error', 'base_url requerido', [], 400);
        return;
    }
    if ($token === '') {
        respondJson($response, 'error', 'Token no encontrado en .env', [], 400);
        return;
    }

    try {
        $client = new AlanubeClient($baseUrl, $token);
        $result = $client->testConnection();
        $status = (int) ($result['status'] ?? 0);
        $ok = ($status >= 200 && $status < 300) || in_array($status, [401, 403, 404], true);
        $message = $ok ? 'Conexion OK (sandbox o prod)' : 'No se pudo conectar';
        respondJson($response, $ok ? 'success' : 'error', $message, [
            'status' => $status,
            'data' => $result['data'] ?? []
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/save') {
    $payload = requestData();
    $integration = $payload['integration'] ?? null;
    if (!is_array($integration)) {
        respondJson($response, 'error', 'integration requerida', [], 400);
        return;
    }
    try {
        IntegrationValidator::validateOrFail($integration);
        $id = (string) ($integration['id'] ?? '');
        if ($id === '') {
            respondJson($response, 'error', 'integration.id requerido', [], 400);
            return;
        }
        $path = PROJECT_ROOT . '/contracts/integrations/' . $id . '.integration.json';
        writeJsonFile($path, $integration);

        $store = new IntegrationStore();
        $store->saveConnection($integration);

        respondJson($response, 'success', 'Integracion guardada', [
            'file' => basename($path)
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/emit') {
    $payload = requestData();
    $invoiceKey = (string) ($payload['invoice'] ?? '');
    $recordId = $payload['record_id'] ?? null;
    $integrationId = (string) ($payload['integration_id'] ?? 'alanube_main');
    $manualPayload = $payload['payload'] ?? null;

    try {
        $integrations = new IntegrationRegistry();
        $integration = $integrations->get($integrationId);
        $tokenEnv = $integration['auth']['token_env'] ?? 'ALANUBE_TOKEN';
        $token = getenv($tokenEnv) ?: '';
        if ($token === '') {
            respondJson($response, 'error', 'Token no encontrado en .env', [], 400);
            return;
        }

        $client = new AlanubeClient($integration['base_url'], $token);
        $endpoint = (string) ($payload['endpoint'] ?? '/documents');
        $payloadData = [];
        $entityName = null;

        if (is_array($manualPayload)) {
            $payloadData = $manualPayload;
        } elseif ($invoiceKey !== '') {
            $registry = new InvoiceRegistry();
            $invoice = $registry->get($invoiceKey);
            InvoiceValidator::validateOrFail($invoice);
            $entityName = (string) ($invoice['entity'] ?? '');
            if ($entityName === '') {
                respondJson($response, 'error', 'entity requerido en invoice', [], 400);
                return;
            }
            if ($recordId === null || $recordId === '') {
                respondJson($response, 'error', 'record_id requerido', [], 400);
                return;
            }
            $entities = new EntityRegistry();
            $entity = $entities->get($entityName);
            $repo = new \App\Core\BaseRepository($entity);
            $record = $repo->find($recordId);
            if (!$record) {
                respondJson($response, 'error', 'Registro no encontrado', [], 404);
                return;
            }
            $gridName = (string) (($invoice['mapping']['items']['source_grid'] ?? '') ?: '');
            $gridItems = fetchGridItems($entity, $gridName, $recordId);
            $mapper = new InvoiceMapper();
            $payloadData = $mapper->buildPayload($invoice, $record, $gridItems);
            if (!empty($invoice['emit_endpoint'])) {
                $endpoint = $invoice['emit_endpoint'];
            }
        } else {
            respondJson($response, 'error', 'payload o invoice requerido', [], 400);
            return;
        }

        $result = $client->emitDocument($endpoint, $payloadData);
        $externalId = $result['data']['id'] ?? ($result['data']['documentId'] ?? null);

        $store = new IntegrationStore();
        $store->saveDocument($integrationId, $entityName, $recordId ? (string) $recordId : null, $externalId ? (string) $externalId : null, 'sent', $payloadData, $result['data']);

        respondJson($response, 'success', 'Documento emitido', [
            'status' => $result['status'],
            'data' => $result['data'],
            'external_id' => $externalId
        ]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/status') {
    $payload = requestData();
    $integrationId = (string) ($payload['integration_id'] ?? $_GET['integration_id'] ?? 'alanube_main');
    $externalId = (string) ($payload['external_id'] ?? $_GET['external_id'] ?? '');
    if ($externalId === '') {
        respondJson($response, 'error', 'external_id requerido', [], 400);
        return;
    }
    try {
        $integrations = new IntegrationRegistry();
        $integration = $integrations->get($integrationId);
        $tokenEnv = $integration['auth']['token_env'] ?? 'ALANUBE_TOKEN';
        $token = getenv($tokenEnv) ?: '';
        if ($token === '') {
            respondJson($response, 'error', 'Token no encontrado en .env', [], 400);
            return;
        }
        $client = new AlanubeClient($integration['base_url'], $token);
        $endpoint = (string) ($payload['endpoint'] ?? '/documents');
        $result = $client->getDocument($endpoint, $externalId);
        $store = new IntegrationStore();
        $store->updateDocumentStatus($integrationId, $externalId, 'status', $result['data']);
        respondJson($response, 'success', 'Estado consultado', $result);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/cancel') {
    $payload = requestData();
    $integrationId = (string) ($payload['integration_id'] ?? 'alanube_main');
    $externalId = (string) ($payload['external_id'] ?? '');
    if ($externalId === '') {
        respondJson($response, 'error', 'external_id requerido', [], 400);
        return;
    }
    try {
        $integrations = new IntegrationRegistry();
        $integration = $integrations->get($integrationId);
        $tokenEnv = $integration['auth']['token_env'] ?? 'ALANUBE_TOKEN';
        $token = getenv($tokenEnv) ?: '';
        if ($token === '') {
            respondJson($response, 'error', 'Token no encontrado en .env', [], 400);
            return;
        }
        $client = new AlanubeClient($integration['base_url'], $token);
        $endpoint = (string) ($payload['endpoint'] ?? '/documents');
        $result = $client->cancelDocument($endpoint, $externalId, $payload['payload'] ?? []);
        $store = new IntegrationStore();
        $store->updateDocumentStatus($integrationId, $externalId, 'cancelled', $result['data']);
        respondJson($response, 'success', 'Documento anulado', $result);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'integrations/alanube/webhook') {
    $payload = requestData();
    $integrationId = (string) ($payload['integration_id'] ?? $_GET['integration_id'] ?? 'alanube_main');
    $event = (string) ($payload['event'] ?? $payload['type'] ?? $payload['action'] ?? '');
    $externalId = null;
    if (isset($payload['id'])) {
        $externalId = (string) $payload['id'];
    } elseif (isset($payload['documentId'])) {
        $externalId = (string) $payload['documentId'];
    } elseif (isset($payload['document']['id'])) {
        $externalId = (string) $payload['document']['id'];
    }
    try {
        $store = new IntegrationStore();
        $store->logWebhook($integrationId, $event ?: null, $externalId, $payload);
        if ($externalId) {
            $store->updateDocumentStatus($integrationId, $externalId, $event ?: 'webhook', $payload);
        }
        respondJson($response, 'success', 'Webhook recibido', ['external_id' => $externalId]);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if (str_starts_with($route, 'records/')) {
    $parts = explode('/', $route);
    $entity = $parts[1] ?? '';
    $id = $parts[2] ?? null;

    if ($entity === '') {
        respondJson($response, 'error', 'Entidad requerida', [], 400);
        return;
    }

    $command = new CommandLayer();

    try {
        if ($method === 'GET') {
            if ($id !== null && $id !== '') {
                $data = $command->readRecord($entity, $id, true);
                respondJson($response, 'success', 'Registro cargado', $data);
            } else {
                $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
                $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
                $filters = $_GET['filter'] ?? [];
                if (!is_array($filters)) {
                    $filters = [];
                }
                $data = $command->queryRecords($entity, $filters, $limit, $offset);
                respondJson($response, 'success', 'Registros cargados', $data);
            }
            return;
        }

        if ($method === 'POST') {
            $payload = requestData();
            $data = $command->createRecord($entity, $payload);
            respondJson($response, 'success', 'Registro creado', $data);
            return;
        }

        if ($method === 'PUT') {
            if ($id === null || $id === '') {
                respondJson($response, 'error', 'ID requerido', [], 400);
                return;
            }
            $payload = requestData();
            $data = $command->updateRecord($entity, $id, $payload);
            respondJson($response, 'success', 'Registro actualizado', $data);
            return;
        }

        if ($method === 'DELETE') {
            if ($id === null || $id === '') {
                respondJson($response, 'error', 'ID requerido', [], 400);
                return;
            }
            $data = $command->deleteRecord($entity, $id);
            respondJson($response, 'success', 'Registro eliminado', $data);
            return;
        }

        respondJson($response, 'error', 'Metodo no soportado', [], 405);
        return;
    } catch (\InvalidArgumentException $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

if ($route === 'command') {
    $payload = requestData();
    $commandName = $payload['command'] ?? '';
    $entity = $payload['entity'] ?? '';
    $command = new CommandLayer();

    if ($commandName === '' && (isset($payload['message']) || isset($payload['text']))) {
        $parsed = parseChatMessage($payload);
        if (isset($parsed['error'])) {
            respondJson($response, 'error', $parsed['error'], [], 422);
            return;
        }
        $commandName = $parsed['command'];
        $entity = $parsed['entity'];
        if (!empty($parsed['data'])) {
            $payload['data'] = $parsed['data'];
        }
        if (!empty($parsed['filters'])) {
            $payload['filters'] = $parsed['filters'];
        }
        if (!empty($parsed['id'])) {
            $payload['id'] = $parsed['id'];
        }
    }

    try {
        switch ($commandName) {
            case 'CreateRecord':
                $data = $command->createRecord($entity, $payload['data'] ?? $payload);
                respondJson($response, 'success', 'Registro creado', [
                    'data' => $data,
                    'view_compact' => "Registro creado en {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            case 'QueryRecords':
                $filters = $payload['filters'] ?? [];
                $limit = (int) ($payload['limit'] ?? 100);
                $offset = (int) ($payload['offset'] ?? 0);
                $data = $command->queryRecords($entity, $filters, $limit, $offset);
                respondJson($response, 'success', 'Registros cargados', [
                    'data' => $data,
                    'view_compact' => "Registros de {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            case 'ReadRecord':
                $id = $payload['id'] ?? null;
                $data = $command->readRecord($entity, $id, true);
                respondJson($response, 'success', 'Registro cargado', [
                    'data' => $data,
                    'view_compact' => "Registro de {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            case 'UpdateRecord':
                $id = $payload['id'] ?? null;
                $data = $command->updateRecord($entity, $id, $payload['data'] ?? $payload);
                respondJson($response, 'success', 'Registro actualizado', [
                    'data' => $data,
                    'view_compact' => "Registro actualizado en {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            case 'DeleteRecord':
                $id = $payload['id'] ?? null;
                $data = $command->deleteRecord($entity, $id);
                respondJson($response, 'success', 'Registro eliminado', [
                    'data' => $data,
                    'view_compact' => "Registro eliminado en {$entity}",
                    'view_link' => "/{$entity}",
                ]);
                return;
            default:
                respondJson($response, 'error', 'Comando no soportado', [], 400);
                return;
        }
    } catch (\InvalidArgumentException $e) {
        respondJson($response, 'error', $e->getMessage(), [], 422);
        return;
    } catch (\Throwable $e) {
        respondJson($response, 'error', $e->getMessage(), [], 500);
        return;
    }
}

// --------------------------------
// 2. Resolver controlador y método
// --------------------------------
$parts = explode('/', $route);

$controllerClass = 'App\\Controller\\' . ucfirst(strtolower($parts[0])) . 'Controller';
$method          = strtolower($parts[1] ?? 'index');

// --------------------------------
// 3. Validar controlador
// --------------------------------
if (!class_exists($controllerClass)) {
    echo $response->json(
        'error',
        "El controlador {$controllerClass} no existe"
    );
    return;
}

// --------------------------------
// 4. Instanciar controlador
// --------------------------------
$controller = new $controllerClass();

// --------------------------------
// 5. Validar método
// --------------------------------
if (!method_exists($controller, $method)) {
    echo $response->json(
        'error',
        "El método {$method} no existe en {$controllerClass}"
    );
    return;
}

// --------------------------------
// 6. Ejecutar acción
// --------------------------------
$result = $controller->$method($_POST);

// Si el controlador retorna algo
if (is_string($result)) {
    echo $result;
}







