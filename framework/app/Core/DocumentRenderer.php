<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Motor de renderizado de documentos dinámicos.
 *
 * Carga plantillas PHP desde framework/views/docs/ e inyecta:
 *   - configuración del tenant (logo, NIT, resolución DIAN, color)
 *   - datos del registro (factura, OC, remisión, etc.)
 *   - acceso validado por rol
 *
 * El CUFE y QR Code en facturas electrónicas provienen de Alanube.
 * SUKI los almacena en fiscal_document.metadata['alanube_response'].
 * Endpoint Alanube: GET /documents/{id} retorna cufe, qr_url, pdf_url.
 */
final class DocumentRenderer
{
    /** Tipos de documento soportados → plantilla */
    private const TEMPLATES = [
        'invoice'        => 'invoice.php',
        'credit_note'    => 'credit_note.php',
        'debit_note'     => 'credit_note.php',  // reutiliza plantilla similar
        'purchase_order' => 'purchase_order.php',
        'remision'       => 'remision.php',
        'report'         => 'report_chart.php',
        'report_chart'   => 'report_chart.php',
    ];

    /** Roles que pueden ver documentos fiscales */
    private const FISCAL_ROLES   = ['admin', 'owner', 'accountant', 'supervisor'];

    /** Roles que pueden ver reportes */
    private const REPORT_ROLES   = ['admin', 'owner', 'accountant', 'supervisor', 'analyst'];

    /** Todos los roles pueden ver sus propios documentos operativos */
    private const OPERATOR_ROLES = ['admin', 'owner', 'operator', 'cashier', 'supervisor'];

    private const VIEWS_PATH = __DIR__ . '/../../views/docs/';

    private BusinessConfigService $configService;
    private ChartDataService $chartService;
    private AuthService $authService;

    public function __construct(
        ?BusinessConfigService $configService = null,
        ?ChartDataService $chartService = null,
        ?AuthService $authService = null
    ) {
        $this->configService = $configService ?? new BusinessConfigService();
        $this->chartService  = $chartService  ?? new ChartDataService();
        $this->authService   = $authService   ?? new AuthService();
    }

    // ─── Entrada Principal ───────────────────────────────────────────────────

    /**
     * Renderiza un documento como HTML completo.
     * Requiere sesión activa y valida el rol del usuario.
     *
     * @param array<string, mixed> $params
     */
    public function render(array $params): string
    {
        $tenantId   = $this->req($params, 'tenant_id');
        $type       = strtolower(trim((string) ($params['type'] ?? '')));
        $id         = (string) ($params['id'] ?? '');
        $userRole   = strtolower(trim((string) ($params['user_role'] ?? 'operator')));
        $reportType = (string) ($params['report_type'] ?? '');
        $desde      = (string) ($params['desde'] ?? date('Y-m-01'));
        $hasta      = (string) ($params['hasta'] ?? date('Y-m-d'));

        $templateFile = self::TEMPLATES[$type] ?? null;
        if ($templateFile === null) {
            throw new RuntimeException('DOC_TYPE_INVALID');
        }

        $templatePath = self::VIEWS_PATH . $templateFile;
        if (!is_file($templatePath)) {
            throw new RuntimeException('DOC_TEMPLATE_NOT_FOUND: ' . $templateFile);
        }

        // ── Validación de rol ────────────────────────────────────────────────
        $this->assertRoleAccess($type, $userRole);

        // ── Configuración de empresa ─────────────────────────────────────────
        $company = $this->configService->getConfig($tenantId);

        // ── Datos del documento ──────────────────────────────────────────────
        $data = $this->loadDocumentData($type, $tenantId, $id, $reportType, $desde, $hasta, $params);

        // ── Renderizar plantilla ─────────────────────────────────────────────
        return $this->renderTemplate($templatePath, [
            'company'     => $company,
            'data'        => $data,
            'type'        => $type,
            'user_role'   => $userRole,
            'report_type' => $reportType,
            'desde'       => $desde,
            'hasta'       => $hasta,
            'rendered_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ─── Carga de Datos ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function loadDocumentData(
        string $type,
        string $tenantId,
        string $id,
        string $reportType,
        string $desde,
        string $hasta,
        array $params
    ): array {
        switch ($type) {
            case 'invoice':
            case 'credit_note':
            case 'debit_note':
                return $this->loadFiscalDocument($tenantId, $id);

            case 'purchase_order':
                return $this->loadPurchaseOrder($tenantId, $id);

            case 'remision':
                return $this->loadRemision($tenantId, $id);

            case 'report':
            case 'report_chart':
                return $this->loadReportData($tenantId, $reportType, $desde, $hasta, $params);

            default:
                throw new RuntimeException('DOC_TYPE_INVALID');
        }
    }

    /** @return array<string, mixed> */
    private function loadFiscalDocument(string $tenantId, string $id): array
    {
        if ($id === '') {
            throw new RuntimeException('DOC_ID_REQUIRED');
        }

        $repo = new FiscalEngineRepository();
        $doc  = $repo->loadDocumentAggregate($tenantId, $id);
        if (!is_array($doc)) {
            throw new RuntimeException('DOC_NOT_FOUND');
        }

        // Extraer CUFE y QR desde la respuesta de Alanube almacenada en metadata
        $meta          = is_array($doc['metadata'] ?? null) ? (array) $doc['metadata'] : [];
        $alanubeResp   = is_array($meta['alanube_response'] ?? null) ? (array) $meta['alanube_response'] : [];
        $doc['cufe']   = (string) ($alanubeResp['cufe'] ?? $meta['cufe'] ?? '');
        $doc['qr_url'] = (string) ($alanubeResp['qr_url'] ?? $meta['qr_url'] ?? '');
        $doc['pdf_url_alanube'] = (string) ($alanubeResp['pdf_url'] ?? '');

        return $doc;
    }

    /** @return array<string, mixed> */
    private function loadPurchaseOrder(string $tenantId, string $id): array
    {
        if ($id === '') {
            throw new RuntimeException('DOC_ID_REQUIRED');
        }
        $service = new PurchasesService();
        return $service->getPurchase($tenantId, $id);
    }

    /** @return array<string, mixed> */
    private function loadRemision(string $tenantId, string $id): array
    {
        // Remisiones son registros de ventas POS con flag de "remision"
        // o pueden ser entidades propias. Por ahora cargamos desde POS sale.
        if ($id === '') {
            throw new RuntimeException('DOC_ID_REQUIRED');
        }
        $service = new POSService();
        $sale = $service->getSale($tenantId, $id);
        $sale['_doc_type'] = 'remision';
        return $sale;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function loadReportData(
        string $tenantId,
        string $reportType,
        string $desde,
        string $hasta,
        array $params
    ): array {
        $reportType = $reportType !== '' ? $reportType : 'ventas_resumen';
        return $this->chartService->getReportData($tenantId, $reportType, $desde, $hasta, $params);
    }

    // ─── Renderizado ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $vars
     */
    private function renderTemplate(string $path, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }

    // ─── Control de Acceso por Rol ────────────────────────────────────────────

    private function assertRoleAccess(string $type, string $userRole): void
    {
        $allowedRoles = match (true) {
            in_array($type, ['invoice', 'credit_note', 'debit_note'], true) => self::FISCAL_ROLES,
            in_array($type, ['report', 'report_chart'], true)               => self::REPORT_ROLES,
            default                                                         => self::OPERATOR_ROLES,
        };

        if (!in_array($userRole, $allowedRoles, true)) {
            throw new RuntimeException('DOC_ACCESS_DENIED_ROLE:' . $userRole);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function req(array $params, string $key): string
    {
        $val = trim((string) ($params[$key] ?? ''));
        if ($val === '') {
            throw new RuntimeException(strtoupper($key) . '_REQUIRED');
        }
        return $val;
    }
}
