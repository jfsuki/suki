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
    http_response_code(500);
    echo $response->json('error', 'App manifest invalido: ' . $manifestError->getMessage());
    return;
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

// --------------------------------
// 1. Obtener la ruta
// --------------------------------
$route = trim($_GET['route'] ?? '');

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







