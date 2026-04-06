<?php
// public/report.php
// Endpoint REST para renderizar reportes HTML o PDF con filtros dinámicos.
//
// Métodos: GET (previsualizar HTML) | POST (filtros via JSON body)
//
// Parámetros:
//   form      = clave del contrato (ej: reporte_ventas, reporte_cartera, factura_venta)
//   report    = clave del reporte dentro del contrato (ej: ventas_periodo, cartera_general)
//   format    = html (default) | pdf
//   tenant_id = identificador del tenant
//   + cualquier filtro definido en el contrato (periodo_desde, estado, saldo_minimo, ...)

declare(strict_types = 1)
;

$frameworkRoot = dirname(__DIR__);
require_once $frameworkRoot . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$tenantId = trim((string)($_REQUEST['tenant_id'] ?? 'default'));

// 1. Validar autenticación básica
if (empty($_SESSION['auth_user'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'No autenticado. Inicie sesión para ver reportes.']);
    exit;
}

// 2. Validar acceso al tenant
$sessionTenant = (string)($_SESSION['tenant_id'] ?? '');
$userType = (string)($_SESSION['user_type'] ?? 'user');

if ($userType !== 'admin' && $userType !== 'creator' && $sessionTenant !== $tenantId) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado al reporte de este tenant.']);
    exit;
}

$formKey = trim((string)($_REQUEST['form'] ?? ''));
$reportKey = trim((string)($_REQUEST['report'] ?? ''));
$format = strtolower(trim((string)($_REQUEST['format'] ?? 'html')));

// Leer filtros adicionales
$reserved = ['form', 'report', 'format', 'tenant_id', '_'];
$filters = [];
foreach ($_REQUEST as $k => $v) {
    if (!in_array($k, $reserved, true) && is_string($v)) {
        $filters[$k] = trim($v);
    }
}

// También aceptar JSON body (POST)
$raw = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $body = json_decode($raw, true);
    if (is_array($body)) {
        $formKey = $formKey ?: trim((string)($body['form'] ?? ''));
        $reportKey = $reportKey ?: trim((string)($body['report'] ?? ''));
        $format = $format !== 'pdf' ? strtolower(trim((string)($body['format'] ?? 'html'))) : $format;
        $tenantId = $tenantId !== 'default' ? $tenantId : trim((string)($body['tenant_id'] ?? 'default'));
        foreach ($body as $k => $v) {
            if (!in_array($k, $reserved, true) && is_string($v)) {
                $filters[$k] = trim($v);
            }
        }
    }
}

if ($formKey === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Parámetro "form" requerido.']);
    exit;
}

try {
    $engine = new \App\Core\FilteredReportEngine();

    if ($format === 'pdf') {
        $pdf = $engine->renderPdf($formKey, $reportKey ?: $formKey, $filters, $tenantId);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $formKey . '_' . date('Ymd_His') . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }
    else {
        $html = $engine->renderHtml($formKey, $reportKey ?: $formKey, $filters, $tenantId);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

}
catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
