<?php

declare(strict_types=1);

// framework/app/Core/Skills/BusinessAutomationSkill.php

namespace App\Core\Skills;

use App\Core\Database;
use App\Core\Tools\WebSearchTool;
use App\Core\Tools\FileDownloadTool;
use App\Core\Tools\PaymentNotificationTool;

/**
 * Orchestrates business automation tools exposed to the agent layer.
 * tenant_id always comes from $state — never from user payload.
 */
final class BusinessAutomationSkill
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handle(array $input, array $context = []): array
    {
        $action = (string) ($input['action'] ?? '');
        return $this->execute($action, $input, $context);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $state  Must contain 'tenant_id'
     * @return array<string, mixed>
     */
    public function execute(string $action, array $params, array $state): array
    {
        // Tenant isolation: always from server-side state
        $tenantId = (string) ($state['tenant_id'] ?? '');

        return match ($action) {
            'web_search'         => $this->webSearch($params, $tenantId),
            'download_file'      => $this->downloadFile($params, $tenantId),
            'validate_payment'   => $this->validatePayment($params, $tenantId),
            'send_collection_notice' => $this->sendCollectionNotice($params, $tenantId),
            'sync_ecommerce'     => $this->syncEcommerce($params, $tenantId, $state),
            'check_dispatch'     => $this->checkDispatch($params, $tenantId),
            'process_excel'      => $this->processExcel($params, $tenantId),
            default              => [
                'error'     => "Acción no disponible: {$action}",
                'available' => ['web_search', 'download_file', 'validate_payment',
                                'send_collection_notice', 'sync_ecommerce',
                                'check_dispatch', 'process_excel'],
            ],
        };
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $params */
    private function webSearch(array $params, string $tenantId): array
    {
        $query = (string) ($params['query'] ?? '');
        if ($query === '') {
            return ['error' => 'Parámetro requerido: query'];
        }

        $maxResults = max(1, min(10, (int) ($params['max_results'] ?? 5)));
        $tool       = new WebSearchTool();
        return $tool->search($query, $tenantId, $maxResults);
    }

    /** @param array<string, mixed> $params */
    private function downloadFile(array $params, string $tenantId): array
    {
        $url = (string) ($params['url'] ?? '');
        if ($url === '') {
            return ['error' => 'Parámetro requerido: url'];
        }

        $tool = new FileDownloadTool();
        return $tool->download($url, $tenantId);
    }

    /** @param array<string, mixed> $params */
    private function validatePayment(array $params, string $tenantId): array
    {
        $provider = (string) ($params['provider'] ?? '');
        $payload  = (array)  ($params['payload']  ?? []);

        if ($provider === '') {
            return ['error' => 'Parámetro requerido: provider (nequi|bancolombia|mercadopago|wompi)'];
        }

        $tool = new PaymentNotificationTool();
        $db   = new Database();
        return $tool->validate($payload, $provider, $tenantId, $db);
    }

    /** @param array<string, mixed> $params */
    private function sendCollectionNotice(array $params, string $tenantId): array
    {
        $invoiceId   = (string) ($params['invoice_id']  ?? '');
        $customerRef = (string) ($params['customer_ref'] ?? '');

        if ($invoiceId === '') {
            return ['error' => 'Parámetro requerido: invoice_id'];
        }

        // Check for EmailService
        if (class_exists(\App\Core\EmailService::class)) {
            try {
                $emailService = new \App\Core\EmailService();
                if (method_exists($emailService, 'sendCollectionNotice')) {
                    return $emailService->sendCollectionNotice($tenantId, $invoiceId, $customerRef);
                }
            } catch (\Throwable $e) {
                // Fall through to template
            }
        }

        // Return template when no email service is wired
        return [
            'action'      => 'collection_notice_template',
            'invoice_id'  => $invoiceId,
            'template'    => "Estimado cliente {$customerRef},\n\nLe recordamos que la factura #{$invoiceId} se encuentra pendiente de pago.\n\nPor favor, comuníquese con nosotros para regularizar su situación.\n\nAtentamente,\nEquipo de Cartera",
            'note'        => 'EmailService no disponible — usar template para envío manual',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $state
     */
    private function syncEcommerce(array $params, string $tenantId, array $state): array
    {
        if (!class_exists(\App\Core\EcommerceAdapterResolver::class)) {
            return ['error' => 'EcommerceAdapterResolver no disponible'];
        }

        try {
            $platform = (string) ($params['platform'] ?? $state['ecommerce_platform'] ?? '');
            $resolver = new \App\Core\EcommerceAdapterResolver();
            $adapter  = $resolver->resolve($platform ?: null);

            $since = (string) ($params['since'] ?? date('Y-m-d', strtotime('-1 day')));

            if (method_exists($adapter, 'syncOrders')) {
                return $adapter->syncOrders($tenantId, $since);
            }

            return ['error' => 'El adaptador de ecommerce no soporta syncOrders'];
        } catch (\Throwable $e) {
            return ['error' => 'Error de sincronización ecommerce: ' . $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $params */
    private function checkDispatch(array $params, string $tenantId): array
    {
        try {
            $db  = Database::connection();
            // Try dispatches table first, then shipments
            foreach (['dispatches', 'shipments'] as $table) {
                try {
                    $stmt = $db->prepare("SELECT id, reference, status, created_at FROM `{$table}` WHERE tenant_id = ? AND status != 'delivered' ORDER BY created_at DESC LIMIT 50");
                    $stmt->execute([$tenantId]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    return [
                        'table'     => $table,
                        'pending'   => $rows,
                        'count'     => count($rows),
                    ];
                } catch (\Throwable $tableError) {
                    continue;
                }
            }

            return ['error' => 'Tabla de despachos no disponible', 'pending' => [], 'count' => 0];
        } catch (\Throwable $e) {
            return ['error' => 'Error al consultar despachos: ' . $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $params */
    private function processExcel(array $params, string $tenantId): array
    {
        $filePath = (string) ($params['file_path'] ?? '');
        $mimeType = (string) ($params['mime_type'] ?? '');

        if ($filePath === '') {
            return ['error' => 'Parámetro requerido: file_path'];
        }

        if (!class_exists(\App\Core\DocumentProcessorService::class)) {
            return ['error' => 'DocumentProcessorService no disponible'];
        }

        try {
            $processor = new \App\Core\DocumentProcessorService();
            return $processor->process($filePath, $mimeType, 'analysis');
        } catch (\Throwable $e) {
            return ['error' => 'Error al procesar archivo: ' . $e->getMessage()];
        }
    }
}
