<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class EcommerceHubMessageParser
{
    private string $message = '';

    /** @var array<string, string> */
    private array $pairs = [];

    /** @var array<string, mixed> */
    private array $context = [];

    private ?EcommerceHubRepository $repository = null;

    private ?EntitySearchService $entitySearchService = null;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $this->context = $context;
        $this->message = trim((string) ($context['message_text'] ?? ''));
        $this->pairs = $this->extractKeyValuePairs($this->message);

        $telemetry = $this->baseTelemetry($skillName);
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($context['project_id'] ?? '')) ?: null,
            'requested_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: 'system',
        ];

        return match ($skillName) {
            'ecommerce_create_store' => $this->parseCreateStore($baseCommand, $telemetry),
            'ecommerce_update_store' => $this->parseUpdateStore($baseCommand, $telemetry),
            'ecommerce_register_credentials' => $this->parseRegisterCredentials($baseCommand, $telemetry),
            'ecommerce_validate_store_setup' => $this->parseValidateStoreSetup($baseCommand, $telemetry),
            'ecommerce_validate_connection' => $this->parseValidateConnection($baseCommand, $telemetry),
            'ecommerce_get_store_metadata' => $this->parseGetStoreMetadata($baseCommand, $telemetry),
            'ecommerce_get_platform_capabilities' => $this->parseGetPlatformCapabilities($baseCommand, $telemetry),
            'ecommerce_ping_store' => $this->parsePingStore($baseCommand, $telemetry),
            'ecommerce_list_stores' => $this->parseListStores($baseCommand, $telemetry),
            'ecommerce_get_store' => $this->parseGetStore($baseCommand, $telemetry),
            'ecommerce_create_sync_job' => $this->parseCreateSyncJob($baseCommand, $telemetry),
            'ecommerce_list_sync_jobs' => $this->parseListSyncJobs($baseCommand, $telemetry),
            'ecommerce_list_order_refs' => $this->parseListOrderRefs($baseCommand, $telemetry),
            'ecommerce_link_order' => $this->parseLinkOrder($baseCommand, $telemetry),
            'ecommerce_get_order_link' => $this->parseGetOrderLink($baseCommand, $telemetry),
            'ecommerce_list_order_links' => $this->parseListOrderLinks($baseCommand, $telemetry),
            'ecommerce_register_order_pull_snapshot' => $this->parseRegisterOrderPullSnapshot($baseCommand, $telemetry),
            'ecommerce_normalize_external_order' => $this->parseNormalizeExternalOrder($baseCommand, $telemetry),
            'ecommerce_mark_order_sync_status' => $this->parseMarkOrderSyncStatus($baseCommand, $telemetry),
            'ecommerce_get_order_snapshot' => $this->parseGetOrderSnapshot($baseCommand, $telemetry),
            'ecommerce_link_product' => $this->parseLinkProduct($baseCommand, $telemetry),
            'ecommerce_unlink_product' => $this->parseUnlinkProduct($baseCommand, $telemetry),
            'ecommerce_list_product_links' => $this->parseListProductLinks($baseCommand, $telemetry),
            'ecommerce_get_product_link' => $this->parseGetProductLink($baseCommand, $telemetry),
            'ecommerce_prepare_product_push_payload' => $this->parsePrepareProductPushPayload($baseCommand, $telemetry),
            'ecommerce_register_product_pull_snapshot' => $this->parseRegisterProductPullSnapshot($baseCommand, $telemetry),
            'ecommerce_mark_product_sync_status' => $this->parseMarkProductSyncStatus($baseCommand, $telemetry),
            default => $this->askUser('No pude interpretar la operacion ecommerce.', $telemetry),
        };
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateStore(array $baseCommand, array $telemetry): array
    {
        $platform = $this->platformValue();
        $storeName = $this->storeNameValue();
        if ($platform === '' || $storeName === '') {
            return $this->askUser(
                'Indica `platform` y `store_name` para conectar la tienda ecommerce. Plataformas soportadas: woocommerce, tiendanube o prestashop.',
                $this->telemetry($telemetry, 'create_store', ['platform' => $platform])
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'CreateEcommerceStore',
            'platform' => $platform,
            'store_name' => $storeName,
            'store_url' => ($storeUrl = $this->storeUrlValue()) !== '' ? $storeUrl : null,
            'status' => ($status = $this->firstValue($this->pairs, ['status'])) !== '' ? $status : null,
            'connection_status' => ($connection = $this->firstValue($this->pairs, ['connection_status'])) !== '' ? $connection : null,
            'currency' => ($currency = $this->firstValue($this->pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'timezone' => ($timezone = $this->firstValue($this->pairs, ['timezone', 'zona_horaria'])) !== '' ? $timezone : null,
        ], $this->telemetry($telemetry, 'create_store', ['platform' => $platform]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseUpdateStore(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'update_store', 'actualizar la tienda ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'UpdateEcommerceStore',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'platform' => ($platform = $this->platformValue()) !== '' ? $platform : null,
            'store_name' => ($storeName = $this->storeNameValue()) !== '' ? $storeName : null,
            'store_url' => ($storeUrl = $this->storeUrlValue()) !== '' ? $storeUrl : null,
            'status' => ($status = $this->firstValue($this->pairs, ['status'])) !== '' ? $status : null,
            'connection_status' => ($connection = $this->firstValue($this->pairs, ['connection_status'])) !== '' ? $connection : null,
            'currency' => ($currency = $this->firstValue($this->pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'timezone' => ($timezone = $this->firstValue($this->pairs, ['timezone', 'zona_horaria'])) !== '' ? $timezone : null,
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'update_store')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRegisterCredentials(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'register_credentials', 'registrar credenciales ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $credentialType = $this->credentialTypeValue();
        $credentialPayload = $this->credentialPayload();
        if ($credentialType === '' || $credentialPayload === []) {
            return $this->askUser(
                'Indica `credential_type` y al menos un secreto (`token`, `api_key`, `secret`, `consumer_key` o `access_token`) para registrar credenciales ecommerce.',
                (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'register_credentials'))
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'RegisterEcommerceStoreCredentials',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'credential_type' => $credentialType,
        ] + $credentialPayload, (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'register_credentials')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseValidateStoreSetup(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'validate_store_setup', 'validar la configuracion ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ValidateEcommerceStoreSetup',
            'store_id' => (string) ($selection['store_id'] ?? ''),
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'validate_store_setup')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseValidateConnection(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'validate_connection', 'validar la conexion ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ValidateEcommerceConnection',
            'store_id' => (string) ($selection['store_id'] ?? ''),
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'validate_connection')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetStoreMetadata(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'get_store_metadata', 'cargar metadata ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceStoreMetadata',
            'store_id' => (string) ($selection['store_id'] ?? ''),
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'get_store_metadata')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetPlatformCapabilities(array $baseCommand, array $telemetry): array
    {
        $platform = $this->platformValue();
        $actionTelemetry = $this->telemetry($telemetry, 'get_platform_capabilities', ['platform' => $platform]);
        if ($platform !== '') {
            return $this->commandResult($baseCommand + [
                'command' => 'GetEcommercePlatformCapabilities',
                'platform' => $platform,
                'store_id' => null,
            ], $actionTelemetry);
        }

        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'get_platform_capabilities', 'consultar capacidades ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommercePlatformCapabilities',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'platform' => null,
        ], (array) ($selection['telemetry'] ?? $actionTelemetry));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parsePingStore(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'ping_store', 'hacer ping de la tienda ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'PingEcommerceStore',
            'store_id' => (string) ($selection['store_id'] ?? ''),
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'ping_store')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListStores(array $baseCommand, array $telemetry): array
    {
        $limit = $this->firstValue($this->pairs, ['limit']);
        if ($limit === '' && $this->containsAny($this->message, ['ultima', 'última', 'ultimo', 'último', 'latest', 'last'])) {
            $limit = '1';
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceStores',
            'platform' => ($platform = $this->platformValue()) !== '' ? $platform : null,
            'status' => ($status = $this->firstValue($this->pairs, ['status'])) !== '' ? $status : null,
            'connection_status' => ($connection = $this->firstValue($this->pairs, ['connection_status'])) !== '' ? $connection : null,
            'limit' => $limit !== '' ? $limit : '10',
        ], $this->telemetry($telemetry, 'list_stores', ['platform' => $this->platformValue()]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetStore(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'get_store', 'cargar la tienda ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceStore',
            'store_id' => (string) ($selection['store_id'] ?? ''),
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'get_store')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateSyncJob(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'create_sync_job', 'crear el sync ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $syncType = $this->syncTypeValue();
        if ($syncType === '') {
            return $this->askUser(
                'Indica `sync_type` para crear el trabajo de sincronizacion ecommerce. Valores comunes: products, orders o inventory.',
                (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'create_sync_job'))
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'CreateEcommerceSyncJob',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'sync_type' => $syncType,
            'status' => ($status = $this->firstValue($this->pairs, ['status'])) !== '' ? $status : null,
            'result_summary' => ($summary = $this->firstValue($this->pairs, ['result_summary'])) !== '' ? $summary : null,
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'create_sync_job', ['sync_type' => $syncType])));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListSyncJobs(array $baseCommand, array $telemetry): array
    {
        $limit = $this->firstValue($this->pairs, ['limit']);
        if ($limit === '' && $this->containsAny($this->message, ['ultima', 'última', 'ultimo', 'último', 'latest', 'last'])) {
            $limit = '1';
        }

        $selection = $this->resolveOptionalStoreSelection($baseCommand, $telemetry, 'list_sync_jobs');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $resolvedTelemetry = (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'list_sync_jobs'));
        $resolvedStoreId = isset($selection['store_id']) ? (string) $selection['store_id'] : $this->storeIdValue();
        $syncType = $this->syncTypeValue();

        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceSyncJobs',
            'store_id' => $resolvedStoreId !== '' ? $resolvedStoreId : null,
            'sync_type' => $syncType !== '' ? $syncType : null,
            'status' => ($status = $this->firstValue($this->pairs, ['status'])) !== '' ? $status : null,
            'limit' => $limit !== '' ? $limit : '10',
        ], $this->telemetry($resolvedTelemetry, 'list_sync_jobs', [
            'store_id' => $resolvedStoreId,
            'sync_type' => $syncType,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListOrderRefs(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'list_order_refs', 'listar referencias de pedidos ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceOrderRefs',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'external_order_id' => ($externalOrderId = $this->externalOrderIdValue()) !== '' ? $externalOrderId : null,
            'local_order_status' => ($localStatus = $this->firstValue($this->pairs, ['local_order_status'])) !== '' ? $localStatus : null,
            'external_status' => ($externalStatus = $this->firstValue($this->pairs, ['external_status', 'status'])) !== '' ? $externalStatus : null,
            'limit' => $this->firstValue($this->pairs, ['limit']) ?: '10',
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'list_order_refs')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseLinkOrder(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'link_order', 'vincular el pedido ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $externalOrderId = $this->externalOrderIdValue();
        if ($externalOrderId === '') {
            return $this->askUser(
                'Indica `external_order_id` para vincular el pedido ecommerce.',
                (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'link_order'))
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'LinkEcommerceOrder',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'external_order_id' => $externalOrderId,
            'local_reference_type' => ($localReferenceType = $this->firstValue($this->pairs, ['local_reference_type'])) !== '' ? $localReferenceType : null,
            'local_reference_id' => ($localReferenceId = $this->firstValue($this->pairs, ['local_reference_id'])) !== '' ? $localReferenceId : null,
            'external_status' => ($externalStatus = $this->firstValue($this->pairs, ['external_status', 'status'])) !== '' ? $externalStatus : null,
            'local_status' => ($localStatus = $this->firstValue($this->pairs, ['local_status'])) !== '' ? $localStatus : null,
            'currency' => ($currency = $this->firstValue($this->pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'total' => ($total = $this->firstValue($this->pairs, ['total'])) !== '' ? $total : null,
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'link_order', ['external_order_id' => $externalOrderId])));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetOrderLink(array $baseCommand, array $telemetry): array
    {
        $linkId = $this->linkIdValue();
        if ($linkId === '') {
            return $this->askUser(
                'Indica `link_id` para cargar el vinculo de pedido ecommerce.',
                $this->telemetry($telemetry, 'get_order_link')
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceOrderLink',
            'link_id' => $linkId,
        ], $this->telemetry($telemetry, 'get_order_link', ['link_id' => $linkId]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListOrderLinks(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'list_order_links', 'listar vinculos de pedidos ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceOrderLinks',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'external_order_id' => ($externalOrderId = $this->externalOrderIdValue()) !== '' ? $externalOrderId : null,
            'local_reference_type' => ($localReferenceType = $this->firstValue($this->pairs, ['local_reference_type'])) !== '' ? $localReferenceType : null,
            'local_reference_id' => ($localReferenceId = $this->firstValue($this->pairs, ['local_reference_id'])) !== '' ? $localReferenceId : null,
            'external_status' => ($externalStatus = $this->firstValue($this->pairs, ['external_status'])) !== '' ? $externalStatus : null,
            'local_status' => ($localStatus = $this->firstValue($this->pairs, ['local_status'])) !== '' ? $localStatus : null,
            'currency' => ($currency = $this->firstValue($this->pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'sync_status' => ($syncStatus = $this->firstValue($this->pairs, ['sync_status', 'status'])) !== '' ? $syncStatus : null,
            'limit' => $this->firstValue($this->pairs, ['limit']) ?: '10',
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'list_order_links')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRegisterOrderPullSnapshot(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'register_order_pull_snapshot', 'registrar el snapshot pull del pedido ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $externalOrderId = $this->externalOrderIdValue();
        if ($externalOrderId === '') {
            return $this->askUser(
                'Indica `external_order_id` para registrar el snapshot pull del pedido ecommerce.',
                (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'register_order_pull_snapshot'))
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'RegisterEcommerceOrderPullSnapshot',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'external_order_payload' => $this->buildExternalOrderPayload($externalOrderId),
        ], $this->telemetry((array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'register_order_pull_snapshot')), 'register_order_pull_snapshot', [
            'external_order_id' => $externalOrderId,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseNormalizeExternalOrder(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'normalize_external_order', 'normalizar el pedido ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $externalOrderId = $this->externalOrderIdValue();
        if ($externalOrderId === '') {
            return $this->askUser(
                'Indica `external_order_id` para normalizar el pedido ecommerce.',
                (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'normalize_external_order'))
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'NormalizeEcommerceExternalOrder',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'external_order_payload' => $this->buildExternalOrderPayload($externalOrderId),
        ], $this->telemetry((array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'normalize_external_order')), 'normalize_external_order', [
            'external_order_id' => $externalOrderId,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseMarkOrderSyncStatus(array $baseCommand, array $telemetry): array
    {
        $linkId = $this->linkIdValue();
        $syncStatus = $this->firstValue($this->pairs, ['sync_status', 'status']);
        if ($linkId === '' || $syncStatus === '') {
            return $this->askUser(
                'Indica `link_id` y `sync_status` para registrar el estado de sync del pedido ecommerce.',
                $this->telemetry($telemetry, 'mark_order_sync_status', ['link_id' => $linkId])
            );
        }

        $metadata = array_filter([
            'external_status' => ($externalStatus = $this->firstValue($this->pairs, ['external_status'])) !== '' ? $externalStatus : null,
            'local_status' => ($localStatus = $this->firstValue($this->pairs, ['local_status'])) !== '' ? $localStatus : null,
            'note' => ($note = $this->firstValue($this->pairs, ['note'])) !== '' ? $note : null,
        ], static fn($value): bool => $value !== null && $value !== '');

        return $this->commandResult($baseCommand + [
            'command' => 'MarkEcommerceOrderSyncStatus',
            'link_id' => $linkId,
            'sync_status' => $syncStatus,
            'metadata' => $metadata,
        ], $this->telemetry($telemetry, 'mark_order_sync_status', [
            'link_id' => $linkId,
            'sync_status' => $syncStatus,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetOrderSnapshot(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'get_order_snapshot', 'cargar el snapshot del pedido ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $externalOrderId = $this->externalOrderIdValue();
        if ($externalOrderId === '') {
            return $this->askUser(
                'Indica `external_order_id` para cargar el snapshot del pedido ecommerce.',
                (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'get_order_snapshot'))
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceOrderSnapshot',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'external_order_id' => $externalOrderId,
        ], $this->telemetry((array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'get_order_snapshot')), 'get_order_snapshot', [
            'external_order_id' => $externalOrderId,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseLinkProduct(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'link_product', 'vincular el producto ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $productSelection = $this->resolveProductSelection(
            $baseCommand,
            (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'link_product')),
            'link_product',
            'vincular el producto ecommerce'
        );
        if (isset($productSelection['ask'])) {
            return (array) $productSelection['ask'];
        }

        $externalProductId = $this->externalProductIdValue();
        if ($externalProductId === '') {
            return $this->askUser(
                'Indica `external_product_id` para vincular el producto ecommerce.',
                (array) ($productSelection['telemetry'] ?? $this->telemetry($telemetry, 'link_product'))
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'LinkEcommerceProduct',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'local_product_id' => (string) ($productSelection['local_product_id'] ?? ''),
            'external_product_id' => $externalProductId,
            'external_sku' => ($externalSku = $this->firstValue($this->pairs, ['external_sku', 'sku'])) !== '' ? $externalSku : null,
        ], $this->telemetry((array) ($productSelection['telemetry'] ?? $this->telemetry($telemetry, 'link_product')), 'link_product', [
            'external_product_id' => $externalProductId,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseUnlinkProduct(array $baseCommand, array $telemetry): array
    {
        $linkId = $this->linkIdValue();
        if ($linkId === '') {
            return $this->askUser(
                'Indica `link_id` para eliminar el vinculo ecommerce.',
                $this->telemetry($telemetry, 'unlink_product')
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'UnlinkEcommerceProduct',
            'link_id' => $linkId,
        ], $this->telemetry($telemetry, 'unlink_product', ['link_id' => $linkId]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListProductLinks(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'list_product_links', 'listar vinculos de productos ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceProductLinks',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'local_product_id' => ($localProductId = $this->firstValue($this->pairs, ['local_product_id', 'product_id'])) !== '' ? $localProductId : null,
            'external_product_id' => ($externalProductId = $this->externalProductIdValue()) !== '' ? $externalProductId : null,
            'external_sku' => ($externalSku = $this->firstValue($this->pairs, ['external_sku', 'sku'])) !== '' ? $externalSku : null,
            'sync_status' => ($syncStatus = $this->firstValue($this->pairs, ['sync_status', 'status'])) !== '' ? $syncStatus : null,
            'last_sync_direction' => ($syncDirection = $this->firstValue($this->pairs, ['sync_direction', 'last_sync_direction'])) !== '' ? $syncDirection : null,
            'limit' => $this->firstValue($this->pairs, ['limit']) ?: '10',
        ], (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'list_product_links')));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetProductLink(array $baseCommand, array $telemetry): array
    {
        $linkId = $this->linkIdValue();
        if ($linkId === '') {
            return $this->askUser(
                'Indica `link_id` para cargar el vinculo ecommerce.',
                $this->telemetry($telemetry, 'get_product_link')
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceProductLink',
            'link_id' => $linkId,
        ], $this->telemetry($telemetry, 'get_product_link', ['link_id' => $linkId]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parsePrepareProductPushPayload(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'prepare_product_push_payload', 'preparar el payload push ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $productSelection = $this->resolveProductSelection(
            $baseCommand,
            (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'prepare_product_push_payload')),
            'prepare_product_push_payload',
            'preparar el payload push ecommerce'
        );
        if (isset($productSelection['ask'])) {
            return (array) $productSelection['ask'];
        }

        return $this->commandResult($baseCommand + [
            'command' => 'PrepareEcommerceProductPushPayload',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'local_product_id' => (string) ($productSelection['local_product_id'] ?? ''),
        ], $this->telemetry((array) ($productSelection['telemetry'] ?? $this->telemetry($telemetry, 'prepare_product_push_payload')), 'prepare_product_push_payload', [
            'sync_direction' => 'push_local_to_store',
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRegisterProductPullSnapshot(array $baseCommand, array $telemetry): array
    {
        $selection = $this->resolveStoreSelection($baseCommand, $telemetry, 'register_product_pull_snapshot', 'registrar el snapshot pull ecommerce');
        if (isset($selection['ask'])) {
            return (array) $selection['ask'];
        }

        $externalProductId = $this->externalProductIdValue();
        if ($externalProductId === '') {
            return $this->askUser(
                'Indica `external_product_id` para registrar el snapshot pull ecommerce.',
                (array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'register_product_pull_snapshot'))
            );
        }

        return $this->commandResult($baseCommand + [
            'command' => 'RegisterEcommerceProductPullSnapshot',
            'store_id' => (string) ($selection['store_id'] ?? ''),
            'external_product_payload' => array_filter([
                'external_product_id' => $externalProductId,
                'external_sku' => ($externalSku = $this->firstValue($this->pairs, ['external_sku', 'sku'])) !== '' ? $externalSku : null,
                'name' => ($name = $this->firstValue($this->pairs, ['name', 'title'])) !== '' ? $name : $this->quotedValue(),
                'status' => ($status = $this->firstValue($this->pairs, ['status'])) !== '' ? $status : null,
            ], static fn($value): bool => $value !== null && $value !== ''),
        ], $this->telemetry((array) ($selection['telemetry'] ?? $this->telemetry($telemetry, 'register_product_pull_snapshot')), 'register_product_pull_snapshot', [
            'external_product_id' => $externalProductId,
            'sync_direction' => 'pull_store_to_local',
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseMarkProductSyncStatus(array $baseCommand, array $telemetry): array
    {
        $linkId = $this->linkIdValue();
        $syncStatus = $this->firstValue($this->pairs, ['sync_status', 'status']);
        if ($linkId === '' || $syncStatus === '') {
            return $this->askUser(
                'Indica `link_id` y `sync_status` para registrar el estado de sync ecommerce.',
                $this->telemetry($telemetry, 'mark_product_sync_status', ['link_id' => $linkId])
            );
        }

        $syncDirection = $this->firstValue($this->pairs, ['sync_direction']);
        $metadata = array_filter([
            'sync_direction' => $syncDirection !== '' ? $syncDirection : null,
            'note' => ($note = $this->firstValue($this->pairs, ['note'])) !== '' ? $note : null,
        ], static fn($value): bool => $value !== null && $value !== '');

        return $this->commandResult($baseCommand + [
            'command' => 'MarkEcommerceProductSyncStatus',
            'link_id' => $linkId,
            'sync_status' => $syncStatus,
            'metadata' => $metadata,
        ], $this->telemetry($telemetry, 'mark_product_sync_status', [
            'link_id' => $linkId,
            'sync_status' => $syncStatus,
            'sync_direction' => $syncDirection,
        ]));
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function resolveStoreSelection(array $baseCommand, array $telemetry, string $action, string $purpose): array
    {
        $tenantId = trim((string) ($baseCommand['tenant_id'] ?? '')) ?: 'default';
        $appId = $this->nullableString($baseCommand['app_id'] ?? null);
        $storeId = $this->storeIdValue();
        $platform = $this->platformValue();
        $storeReference = $this->storeReferenceValue();
        $actionTelemetry = $this->telemetry($telemetry, $action, ['platform' => $platform]);

        try {
            if ($storeId !== '') {
                $store = $this->ecommerceRepository()->findStore($tenantId, $storeId, $appId);
                if (is_array($store)) {
                    return [
                        'store_id' => (string) ($store['id'] ?? ''),
                        'store' => $store,
                        'telemetry' => $this->telemetry($actionTelemetry, $action, $this->storeTelemetry($store)),
                    ];
                }

                $candidates = $this->storesForTenant($tenantId, $appId, $platform !== '' ? ['platform' => $platform] : []);
                return [
                    'ask' => $this->askUser(
                        'No encontre esa tienda ecommerce en este tenant.' . $this->storeCandidateSuffix($candidates),
                        $this->telemetry($actionTelemetry, $action, [
                            'ambiguity_detected' => $candidates !== [],
                            'ambiguity_count' => count($candidates),
                            'needs_clarification' => true,
                            'result_status' => 'needs_input',
                        ])
                    ),
                ];
            }

            $stores = $this->storesForTenant($tenantId, $appId, $platform !== '' ? ['platform' => $platform] : []);
            if ($storeReference !== '') {
                $matched = $this->matchStoreCandidates($stores, $storeReference);
                if (count($matched) === 1) {
                    $store = $matched[0];
                    return [
                        'store_id' => (string) ($store['id'] ?? ''),
                        'store' => $store,
                        'telemetry' => $this->telemetry($actionTelemetry, $action, $this->storeTelemetry($store)),
                    ];
                }
                if ($matched !== []) {
                    return [
                        'ask' => $this->askUser(
                            'Encontre varias tiendas ecommerce para ' . $purpose . ':' . PHP_EOL . $this->formatStoreCandidates($matched),
                            $this->telemetry($actionTelemetry, $action, [
                                'ambiguity_detected' => true,
                                'ambiguity_count' => count($matched),
                                'needs_clarification' => true,
                                'result_status' => 'needs_input',
                            ])
                        ),
                    ];
                }
            }

            if ($stores === []) {
                return [
                    'ask' => $this->askUser(
                        'No encontre tiendas ecommerce registradas en este tenant. Puedes crear una primero.',
                        $this->telemetry($actionTelemetry, $action, [
                            'needs_clarification' => true,
                            'result_status' => 'needs_input',
                        ])
                    ),
                ];
            }

            if (count($stores) === 1) {
                $store = $stores[0];
                return [
                    'store_id' => (string) ($store['id'] ?? ''),
                    'store' => $store,
                    'telemetry' => $this->telemetry($actionTelemetry, $action, $this->storeTelemetry($store)),
                ];
            }

            return [
                'ask' => $this->askUser(
                    'Necesito `store_id` para ' . $purpose . '. Encontré varias tiendas en este tenant:' . PHP_EOL . $this->formatStoreCandidates($stores),
                    $this->telemetry($actionTelemetry, $action, [
                        'ambiguity_detected' => true,
                        'ambiguity_count' => count($stores),
                        'needs_clarification' => true,
                        'result_status' => 'needs_input',
                    ])
                ),
            ];
        } catch (Throwable $e) {
            return [
                'ask' => $this->askUser(
                    'Necesito `store_id` para ' . $purpose . '.',
                    $this->telemetry($actionTelemetry, $action, [
                        'needs_clarification' => true,
                        'result_status' => 'needs_input',
                    ])
                ),
            ];
        }
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function resolveOptionalStoreSelection(array $baseCommand, array $telemetry, string $action): array
    {
        if ($this->storeIdValue() !== '' || $this->storeReferenceValue() !== '' || $this->platformValue() !== '') {
            return $this->resolveStoreSelection($baseCommand, $telemetry, $action, 'cargar la tienda ecommerce');
        }

        return ['telemetry' => $this->telemetry($telemetry, $action)];
    }

    /**
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function resolveProductSelection(array $baseCommand, array $telemetry, string $action, string $purpose): array
    {
        $tenantId = trim((string) ($baseCommand['tenant_id'] ?? '')) ?: 'default';
        $appId = $this->nullableString($baseCommand['app_id'] ?? null);
        $productReference = $this->localProductReferenceValue();
        $actionTelemetry = $this->telemetry($telemetry, $action);

        if ($productReference === '') {
            return [
                'ask' => $this->askUser(
                    'Indica `local_product_id` o el nombre exacto del producto para ' . $purpose . '.',
                    $this->telemetry($actionTelemetry, $action, [
                        'needs_clarification' => true,
                        'result_status' => 'needs_input',
                    ])
                ),
            ];
        }

        try {
            $direct = $this->entitySearchService()->getByReference(
                $tenantId,
                'product',
                $productReference,
                ['app_id' => $appId],
                $appId
            );
            if (is_array($direct)) {
                $productId = trim((string) ($direct['entity_id'] ?? ''));
                return [
                    'local_product_id' => $productId,
                    'telemetry' => $this->telemetry($actionTelemetry, $action, [
                        'product_id' => $productId,
                        'local_product_id' => $productId,
                        'matched_product_id' => $productId,
                        'matched_by' => trim((string) ($direct['matched_by'] ?? 'reference')),
                        'product_query' => $productReference,
                    ]),
                ];
            }

            $resolved = $this->entitySearchService()->resolveBestMatch(
                $tenantId,
                $productReference,
                ['entity_type' => 'product', 'app_id' => $appId, 'limit' => 5],
                $appId
            );
            if (($resolved['resolved'] ?? false) === true && is_array($resolved['result'] ?? null)) {
                $result = (array) $resolved['result'];
                $productId = trim((string) ($result['entity_id'] ?? ''));
                return [
                    'local_product_id' => $productId,
                    'telemetry' => $this->telemetry($actionTelemetry, $action, [
                        'product_id' => $productId,
                        'local_product_id' => $productId,
                        'matched_product_id' => $productId,
                        'matched_by' => trim((string) ($result['matched_by'] ?? 'entity_search')),
                        'product_query' => $productReference,
                    ]),
                ];
            }

            $candidates = is_array($resolved['candidates'] ?? null) ? (array) $resolved['candidates'] : [];
            if ($candidates !== []) {
                return [
                    'ask' => $this->askUser(
                        'Encontre varios productos locales para ' . $purpose . '. Elige uno con `local_product_id`:' . PHP_EOL . $this->formatProductCandidates($candidates),
                        $this->telemetry($actionTelemetry, $action, [
                            'product_query' => $productReference,
                            'ambiguity_detected' => true,
                            'ambiguity_count' => count($candidates),
                            'needs_clarification' => true,
                            'result_status' => 'needs_input',
                        ])
                    ),
                ];
            }
        } catch (Throwable $e) {
            // Safe fall-through to explicit ask_user below.
        }

        return [
            'ask' => $this->askUser(
                'No encontre ese producto local en este tenant. Indica `local_product_id` o un nombre mas exacto.',
                $this->telemetry($actionTelemetry, $action, [
                    'product_query' => $productReference,
                    'needs_clarification' => true,
                    'result_status' => 'needs_input',
                ])
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExternalOrderPayload(string $externalOrderId): array
    {
        return array_filter([
            'external_order_id' => $externalOrderId,
            'id' => $externalOrderId,
            'status' => ($status = $this->firstValue($this->pairs, ['external_status', 'status'])) !== '' ? $status : null,
            'currency' => ($currency = $this->firstValue($this->pairs, ['currency', 'moneda'])) !== '' ? strtoupper($currency) : null,
            'total' => ($total = $this->firstValue($this->pairs, ['total'])) !== '' ? $total : null,
            'line_items' => [],
        ], static fn($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function baseTelemetry(string $skillName): array
    {
        $action = $this->actionFromSkillName($skillName);
        $platform = $this->platformValue();

        return [
            'module_used' => 'ecommerce',
            'ecommerce_action' => $action,
            'skill_group' => $this->skillGroup($skillName),
            'store_id' => '',
            'platform' => $platform,
            'adapter_key' => $this->adapterKey($platform),
            'connection_status' => '',
            'validation_result' => 'none',
            'sync_job_id' => '',
            'sync_type' => '',
            'link_id' => '',
            'external_order_id' => '',
            'local_reference_type' => '',
            'local_reference_id' => '',
            'local_product_id' => '',
            'external_product_id' => '',
            'sync_status' => '',
            'sync_direction' => '',
            'product_id' => '',
            'matched_product_id' => '',
            'matched_by' => '',
            'product_query' => '',
            'ambiguity_detected' => false,
            'ambiguity_count' => 0,
            'needs_clarification' => false,
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $telemetry
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function telemetry(array $telemetry, string $action, array $extra = []): array
    {
        $merged = array_merge($telemetry, [
            'module_used' => 'ecommerce',
            'ecommerce_action' => $action,
            'skill_group' => $this->skillGroupFromAction($action),
        ], $extra);

        $platform = trim((string) ($merged['platform'] ?? ''));
        if (trim((string) ($merged['adapter_key'] ?? '')) === '') {
            $merged['adapter_key'] = $this->adapterKey($platform);
        }
        $merged['ambiguity_detected'] = (($merged['ambiguity_detected'] ?? false) === true);
        $merged['ambiguity_count'] = max(0, (int) ($merged['ambiguity_count'] ?? 0));
        $merged['needs_clarification'] = (($merged['needs_clarification'] ?? false) === true);
        $merged['result_status'] = trim((string) ($merged['result_status'] ?? '')) ?: 'success';

        return $merged;
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function commandResult(array $command, array $telemetry): array
    {
        $telemetry['needs_clarification'] = false;
        if (!isset($telemetry['result_status']) || trim((string) $telemetry['result_status']) === '') {
            $telemetry['result_status'] = 'success';
        }

        return ['kind' => 'command', 'command' => $command, 'telemetry' => $telemetry];
    }

    /**
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function askUser(string $reply, array $telemetry): array
    {
        $telemetry['needs_clarification'] = true;
        if (!isset($telemetry['result_status']) || trim((string) $telemetry['result_status']) === '') {
            $telemetry['result_status'] = 'needs_input';
        }

        return ['kind' => 'ask_user', 'reply' => $reply, 'telemetry' => $telemetry];
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\\s]+))/u', $message, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = strtolower(trim((string) ($match[1] ?? '')));
            $value = '';
            foreach ([3, 4, 5] as $index) {
                if (isset($match[$index]) && $match[$index] !== '') {
                    $value = trim((string) $match[$index]);
                    break;
                }
            }
            if ($key !== '' && $value !== '') {
                $pairs[$key] = $value;
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs
     * @param array<int, string> $aliases
     */
    private function firstValue(array $pairs, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $alias = strtolower(trim($alias));
            if ($alias !== '' && array_key_exists($alias, $pairs)) {
                return trim((string) $pairs[$alias]);
            }
        }

        return '';
    }

    private function quotedValue(): string
    {
        if (preg_match('/"([^"]+)"/u', $this->message, $match) === 1) {
            return trim((string) ($match[1] ?? ''));
        }
        if (preg_match("/'([^']+)'/u", $this->message, $match) === 1) {
            return trim((string) ($match[1] ?? ''));
        }

        return '';
    }

    private function platformValue(): string
    {
        $explicit = strtolower(trim($this->firstValue($this->pairs, ['platform', 'plataforma'])));
        if ($explicit !== '') {
            return $this->normalizePlatform($explicit);
        }

        $message = $this->normalizedText($this->message);
        if (preg_match('/(?:^|\b)(woocommerce|woo)(?:$|\b)/u', $message) === 1) {
            return 'woocommerce';
        }
        if (preg_match('/(?:^|\b)(tiendanube|nuvemshop|tienda nube)(?:$|\b)/u', $message) === 1) {
            return 'tiendanube';
        }
        if (preg_match('/(?:^|\b)(prestashop|presta shop)(?:$|\b)/u', $message) === 1) {
            return 'prestashop';
        }
        if (preg_match('/(?:^|\b)(custom_store|custom store)(?:$|\b)/u', $message) === 1) {
            return 'custom_store';
        }

        return '';
    }

    private function storeNameValue(): string
    {
        $explicit = $this->firstValue($this->pairs, ['store_name', 'nombre_tienda']);
        if ($explicit !== '') {
            return $explicit;
        }

        return $this->storeReferenceValue();
    }

    private function storeReferenceValue(): string
    {
        $explicit = $this->firstValue($this->pairs, ['store_name', 'nombre_tienda', 'tienda', 'store', 'canal']);
        if ($explicit !== '' && !$this->isGenericStoreReference($explicit)) {
            return $explicit;
        }

        $patterns = [
            '/(?:de|del|para|en|con)\s+(?:la\s+)?(?:tienda|store|canal)\s+"([^"]+)"/iu',
            '/(?:tienda|store|canal)\s+"([^"]+)"/iu',
            '/(?:de|del|para|en|con)\s+(?:la\s+)?(?:tienda|store|canal)\s+([a-z0-9][^,]+?)(?=\s+(?:platform|store_id|token|api_key|secret|consumer_key|consumer_secret|access_token|external_|sync|pedido|producto|sku|status|total|currency|moneda)\b|$)/iu',
            '/(?:tienda|store|canal)\s+([a-z0-9][^,]+?)(?=\s+(?:platform|store_id|token|api_key|secret|consumer_key|consumer_secret|access_token|external_|sync|pedido|producto|sku|status|total|currency|moneda)\b|$)/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $this->message, $match) === 1) {
                $value = trim((string) ($match[1] ?? ''));
                if ($value !== '' && !$this->isGenericStoreReference($value)) {
                    return $value;
                }
            }
        }

        return '';
    }

    private function storeUrlValue(): string
    {
        $explicit = $this->firstValue($this->pairs, ['store_url', 'url', 'store_link']);
        if ($explicit !== '') {
            return $explicit;
        }
        if (preg_match('/https?:\/\/[^\s]+/iu', $this->message, $match) === 1) {
            return trim((string) ($match[0] ?? ''));
        }

        return '';
    }

    private function credentialTypeValue(): string
    {
        $explicit = strtolower(trim($this->firstValue($this->pairs, ['credential_type', 'tipo_credencial'])));
        if ($explicit !== '') {
            return $explicit;
        }
        if ($this->firstValue($this->pairs, ['consumer_key', 'consumer_secret']) !== '') {
            return 'woocommerce_rest_api';
        }
        if ($this->firstValue($this->pairs, ['access_token']) !== '') {
            return 'access_token';
        }
        if (
            $this->firstValue($this->pairs, ['api_key']) !== ''
            || $this->firstValue($this->pairs, ['secret']) !== ''
            || $this->firstValue($this->pairs, ['client_secret']) !== ''
        ) {
            return 'api_key_secret';
        }
        if ($this->firstValue($this->pairs, ['token']) !== '') {
            return 'api_token';
        }

        return '';
    }

    private function syncTypeValue(): string
    {
        $explicit = strtolower(trim($this->firstValue($this->pairs, ['sync_type', 'tipo_sync', 'tipo_sincro'])));
        if ($explicit !== '') {
            return $explicit;
        }

        $message = $this->normalizedText($this->message);
        if (preg_match('/(?:^|\b)(pedido|pedidos|orden|ordenes|orders)(?:$|\b)/u', $message) === 1) {
            return 'orders';
        }
        if (preg_match('/(?:^|\b)(producto|productos|product|products)(?:$|\b)/u', $message) === 1) {
            return 'products';
        }
        if (preg_match('/(?:^|\b)(inventario|inventory|stock)(?:$|\b)/u', $message) === 1) {
            return 'inventory';
        }

        return '';
    }

    private function storeIdValue(): string
    {
        return $this->firstNonEmpty([
            $this->firstValue($this->pairs, ['store_id']),
            trim((string) ($this->context['store_id'] ?? '')),
        ]);
    }

    private function linkIdValue(): string
    {
        return $this->firstNonEmpty([
            $this->firstValue($this->pairs, ['link_id']),
            trim((string) ($this->context['link_id'] ?? '')),
        ]);
    }

    private function externalOrderIdValue(): string
    {
        $explicit = $this->firstValue($this->pairs, ['external_order_id', 'order_id', 'pedido_externo', 'orden_externa']);
        if ($explicit !== '') {
            return $explicit;
        }

        foreach ([
            '/(?:pedido|orden|order)\s+extern[oa]\s+([a-z0-9._:-]+)/iu',
            '/(?:pedido|orden|order)\s+([a-z0-9._:-]+)(?=\s+(?:de|del|store|tienda|status|currency|moneda|total)\b|$)/iu',
        ] as $pattern) {
            if (preg_match($pattern, $this->message, $match) === 1) {
                return trim((string) ($match[1] ?? ''));
            }
        }

        return '';
    }

    private function externalProductIdValue(): string
    {
        $explicit = $this->firstValue($this->pairs, ['external_product_id', 'product_external_id', 'producto_externo']);
        if ($explicit !== '') {
            return $explicit;
        }
        if (preg_match('/(?:producto|product)\s+extern[oa]\s+([a-z0-9._:-]+)/iu', $this->message, $match) === 1) {
            return trim((string) ($match[1] ?? ''));
        }

        return '';
    }

    private function localProductReferenceValue(): string
    {
        $explicit = $this->firstValue($this->pairs, ['local_product_id', 'product_id', 'product_ref', 'product_reference']);
        if ($explicit !== '') {
            return $explicit;
        }

        foreach ([
            '/(?:producto|product)\s+"([^"]+)"/iu',
            '/(?:producto|product)\s+\'([^\']+)\'/iu',
            '/(?:producto|product)\s+(.+?)(?=\s+(?:con|para|de|del|store|tienda|external_product_id|sku|status|sync|link|vincul|payload|push)\b|$)/iu',
        ] as $pattern) {
            if (preg_match($pattern, $this->message, $match) === 1) {
                $value = trim((string) ($match[1] ?? ''));
                if ($value !== '' && !$this->isGenericProductReference($value)) {
                    return $value;
                }
            }
        }

        $quoted = $this->quotedValue();
        if ($quoted !== '' && str_contains($this->normalizedText($this->message), 'producto')) {
            return $quoted;
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function credentialPayload(): array
    {
        $payload = [];
        foreach ([
            'token',
            'access_token',
            'api_key',
            'secret',
            'consumer_key',
            'consumer_secret',
            'client_id',
            'client_secret',
            'username',
            'password',
            'key',
            'webservice_key',
        ] as $key) {
            $value = $this->firstValue($this->pairs, [$key]);
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $stores
     * @return array<int, array<string, mixed>>
     */
    private function matchStoreCandidates(array $stores, string $reference): array
    {
        $reference = $this->normalizedText($reference);
        if ($reference === '') {
            return [];
        }

        $exact = [];
        $contains = [];
        foreach ($stores as $store) {
            $storeId = trim((string) ($store['id'] ?? ''));
            $storeName = $this->normalizedText((string) ($store['store_name'] ?? ''));
            $platform = $this->normalizedText((string) ($store['platform'] ?? ''));

            if ($storeId !== '' && $storeId === $reference) {
                return [$store];
            }
            if ($storeName !== '' && $storeName === $reference) {
                $exact[] = $store;
                continue;
            }
            if ($platform !== '' && $platform === $reference) {
                $exact[] = $store;
                continue;
            }
            if (($storeName !== '' && str_contains($storeName, $reference)) || ($platform !== '' && str_contains($platform, $reference))) {
                $contains[] = $store;
            }
        }

        return $exact !== [] ? $exact : $contains;
    }

    /**
     * @param array<int, array<string, mixed>> $stores
     */
    private function formatStoreCandidates(array $stores): string
    {
        $lines = [];
        foreach (array_slice($stores, 0, 5) as $store) {
            $lines[] = '- store_id=' . (string) ($store['id'] ?? '')
                . ' | ' . (string) ($store['store_name'] ?? '')
                . ' | ' . (string) ($store['platform'] ?? '')
                . ' | ' . (string) ($store['connection_status'] ?? '');
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $stores
     */
    private function storeCandidateSuffix(array $stores): string
    {
        if ($stores === []) {
            return '';
        }

        return PHP_EOL . 'Tiendas disponibles:' . PHP_EOL . $this->formatStoreCandidates($stores);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private function formatProductCandidates(array $candidates): string
    {
        $lines = [];
        foreach (array_slice($candidates, 0, 5) as $candidate) {
            $metadata = is_array($candidate['metadata_json'] ?? null) ? (array) $candidate['metadata_json'] : [];
            $lines[] = '- local_product_id=' . (string) ($candidate['entity_id'] ?? '')
                . ' | ' . trim((string) ($candidate['label'] ?? ''))
                . ' | ' . trim((string) ($metadata['raw_identifier'] ?? $candidate['subtitle'] ?? ''));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function storeTelemetry(array $store): array
    {
        $platform = trim((string) ($store['platform'] ?? ''));

        return [
            'store_id' => trim((string) ($store['id'] ?? '')),
            'platform' => $platform,
            'adapter_key' => $this->adapterKey($platform),
            'connection_status' => trim((string) ($store['connection_status'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function storesForTenant(string $tenantId, ?string $appId, array $filters = []): array
    {
        return $this->ecommerceRepository()->listStores($tenantId, $filters + ['app_id' => $appId], 25);
    }

    private function actionFromSkillName(string $skillName): string
    {
        return str_starts_with($skillName, 'ecommerce_')
            ? substr($skillName, strlen('ecommerce_'))
            : $skillName;
    }

    private function skillGroup(string $skillName): string
    {
        return $this->skillGroupFromAction($this->actionFromSkillName($skillName));
    }

    private function skillGroupFromAction(string $action): string
    {
        return match ($action) {
            'create_store', 'update_store', 'register_credentials', 'validate_store_setup', 'validate_connection', 'get_store_metadata', 'get_platform_capabilities', 'ping_store', 'list_stores', 'get_store' => 'store_setup',
            'create_sync_job', 'list_sync_jobs', 'list_order_refs' => 'sync_tracking',
            'link_product', 'unlink_product', 'list_product_links', 'get_product_link', 'prepare_product_push_payload', 'register_product_pull_snapshot', 'mark_product_sync_status' => 'product_sync_ops',
            'link_order', 'get_order_link', 'list_order_links', 'register_order_pull_snapshot', 'normalize_external_order', 'mark_order_sync_status', 'get_order_snapshot' => 'order_sync_ops',
            default => 'unknown',
        };
    }

    private function adapterKey(string $platform): string
    {
        $platform = $this->normalizePlatform($platform);
        return in_array($platform, ['woocommerce', 'tiendanube', 'prestashop'], true) ? $platform : 'unknown';
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = $this->normalizedText($platform);

        return match ($platform) {
            'woo', 'woocommerce' => 'woocommerce',
            'tiendanube', 'nuvemshop', 'tienda nube' => 'tiendanube',
            'prestashop', 'presta shop' => 'prestashop',
            'custom', 'custom store', 'custom_store' => 'custom_store',
            default => trim($platform),
        };
    }

    private function isGenericStoreReference(string $value): bool
    {
        return in_array($this->normalizedText($value), ['', 'mi', 'mi tienda', 'la', 'la tienda', 'esta', 'esta tienda', 'tienda', 'store', 'canal', 'ecommerce'], true);
    }

    private function isGenericProductReference(string $value): bool
    {
        return in_array($this->normalizedText($value), ['', 'este', 'este producto', 'el producto', 'producto', 'product', 'externo', 'externa'], true);
    }

    private function normalizedText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $message, array $needles): bool
    {
        $message = $this->normalizedText($message);
        foreach ($needles as $needle) {
            if (str_contains($message, $this->normalizedText($needle))) {
                return true;
            }
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function ecommerceRepository(): EcommerceHubRepository
    {
        if (!$this->repository instanceof EcommerceHubRepository) {
            $this->repository = new EcommerceHubRepository();
        }

        return $this->repository;
    }

    private function entitySearchService(): EntitySearchService
    {
        if (!$this->entitySearchService instanceof EntitySearchService) {
            $this->entitySearchService = new EntitySearchService();
        }

        return $this->entitySearchService;
    }
}
