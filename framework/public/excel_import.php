<?php
// public/excel_import.php
// Endpoint REST para importar archivos Excel (.xlsx)
// Método: POST multipart/form-data
// Campos: file (xlsx), tenant_id (string), overwrite (0|1, opcional)

declare(strict_types = 1)
;

$frameworkRoot = dirname(__DIR__);
require_once $frameworkRoot . '/vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

// session_start() debe ir antes de cualquier salida
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Validar autenticación básica
if (empty($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado. Inicie sesión primero.']);
    exit;
}

// 2. Validar que el usuario tenga acceso al tenant solicitado
// Nota: En SUKI, el creador puede saltar entre tenants si tiene permisos globales.
$sessionTenant = (string)($_SESSION['tenant_id'] ?? '');
$requestedTenant = trim((string)($_POST['tenant_id'] ?? ''));

// Si no es admin/creator global, el tenant debe coincidir exactamente
$userType = (string)($_SESSION['user_type'] ?? 'user');
if ($userType !== 'admin' && $userType !== 'creator' && $sessionTenant !== $requestedTenant) {
     http_response_code(403);
     echo json_encode(['ok' => false, 'error' => 'Acceso denegado al tenant solicitado.']);
     exit;
}

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido. Use POST.']);
    exit;
}

// Leer parámetros
$tenantId = trim((string)($_POST['tenant_id'] ?? ''));
$overwrite = filter_var($_POST['overwrite'] ?? '0', FILTER_VALIDATE_BOOLEAN);

if ($tenantId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'tenant_id requerido.']);
    exit;
}

// Validar archivo subido
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se recibió un archivo válido.', 'upload_error' => $uploadError]);
    exit;
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$maxBytes = 10 * 1024 * 1024; // 10 MB

if ($ext !== 'xlsx') {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Solo se aceptan archivos .xlsx']);
    exit;
}

if ($file['size'] > $maxBytes) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Archivo demasiado grande. Máximo 10 MB.']);
    exit;
}

// Verificar tipo MIME
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowedMimes = [
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/zip', // xlsx es un zip
    'application/octet-stream',
];
if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => "Tipo MIME no permitido: {$mimeType}"]);
    exit;
}

// Mover a directorio temporal
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suki_excel_' . $tenantId;
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0700, true);
}
$tmpPath = $tmpDir . DIRECTORY_SEPARATOR . uniqid('import_', true) . '.xlsx';

if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al guardar el archivo temporalmente.']);
    exit;
}

// Procesar el Excel
try {
    $importer = new \App\Core\ExcelImportService();
    $report = $importer->importFile($tmpPath, $tenantId, $overwrite);

    // Limpiar archivo temporal
    @unlink($tmpPath);

    echo json_encode([
        'ok' => true,
        'report' => $report,
        'message' => sprintf(
        'Se procesaron %d hojas. %d tablas creadas, %d filas guardadas.',
        $report['sheets_processed'],
        count($report['entities_created']),
        $report['rows_inserted']
    ),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

}
catch (\Throwable $e) {
    @unlink($tmpPath);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
