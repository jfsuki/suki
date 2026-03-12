<?php

declare(strict_types=1);

namespace App\Core;

final class EcommerceHubMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        $telemetry = ['module_used' => 'ecommerce', 'ecommerce_action' => 'none'];
        $baseCommand = [
            'tenant_id' => trim((string) ($context['tenant_id'] ?? '')) ?: 'default',
            'app_id' => trim((string) ($context['project_id'] ?? '')) ?: null,
            'requested_by_user_id' => trim((string) ($context['user_id'] ?? '')) ?: 'system',
        ];

        return match ($skillName) {
            'ecommerce_create_store' => $this->parseCreateStore($pairs, $baseCommand, $telemetry),
            'ecommerce_update_store' => $this->parseUpdateStore($pairs, $baseCommand, $telemetry),
            'ecommerce_register_credentials' => $this->parseRegisterCredentials($pairs, $baseCommand, $telemetry),
            'ecommerce_validate_store_setup' => $this->parseValidateStoreSetup($pairs, $baseCommand, $telemetry),
            'ecommerce_validate_connection' => $this->parseValidateConnection($pairs, $baseCommand, $telemetry),
            'ecommerce_get_store_metadata' => $this->parseGetStoreMetadata($pairs, $baseCommand, $telemetry),
            'ecommerce_get_platform_capabilities' => $this->parseGetPlatformCapabilities($pairs, $baseCommand, $telemetry),
            'ecommerce_ping_store' => $this->parsePingStore($pairs, $baseCommand, $telemetry),
            'ecommerce_list_stores' => $this->parseListStores($pairs, $baseCommand, $telemetry),
            'ecommerce_get_store' => $this->parseGetStore($pairs, $baseCommand, $telemetry),
            'ecommerce_create_sync_job' => $this->parseCreateSyncJob($pairs, $baseCommand, $telemetry),
            'ecommerce_list_sync_jobs' => $this->parseListSyncJobs($pairs, $baseCommand, $telemetry),
            'ecommerce_list_order_refs' => $this->parseListOrderRefs($pairs, $baseCommand, $telemetry),
            'ecommerce_link_order' => $this->parseLinkOrder($pairs, $baseCommand, $telemetry),
            'ecommerce_get_order_link' => $this->parseGetOrderLink($pairs, $baseCommand, $telemetry),
            'ecommerce_list_order_links' => $this->parseListOrderLinks($pairs, $baseCommand, $telemetry),
            'ecommerce_register_order_pull_snapshot' => $this->parseRegisterOrderPullSnapshot($pairs, $baseCommand, $telemetry),
            'ecommerce_normalize_external_order' => $this->parseNormalizeExternalOrder($pairs, $baseCommand, $telemetry),
            'ecommerce_mark_order_sync_status' => $this->parseMarkOrderSyncStatus($pairs, $baseCommand, $telemetry),
            'ecommerce_get_order_snapshot' => $this->parseGetOrderSnapshot($pairs, $baseCommand, $telemetry),
            'ecommerce_link_product' => $this->parseLinkProduct($pairs, $baseCommand, $telemetry),
            'ecommerce_unlink_product' => $this->parseUnlinkProduct($pairs, $baseCommand, $telemetry),
            'ecommerce_list_product_links' => $this->parseListProductLinks($pairs, $baseCommand, $telemetry),
            'ecommerce_get_product_link' => $this->parseGetProductLink($pairs, $baseCommand, $telemetry),
            'ecommerce_prepare_product_push_payload' => $this->parsePrepareProductPushPayload($pairs, $baseCommand, $telemetry),
            'ecommerce_register_product_pull_snapshot' => $this->parseRegisterProductPullSnapshot($pairs, $baseCommand, $telemetry),
            'ecommerce_mark_product_sync_status' => $this->parseMarkProductSyncStatus($pairs, $baseCommand, $telemetry),
            default => ['kind' => 'ask_user', 'reply' => 'No pude interpretar la operacion ecommerce.', 'telemetry' => $telemetry],
        };
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateStore(array $pairs, array $baseCommand, array $telemetry): array
    {
        $platform = $this->firstValue($pairs, ['platform']);
        $storeName = $this->firstValue($pairs, ['store_name', 'name', 'tienda']);
        if ($platform === '' || $storeName === '') {
            return $this->askUser('Indica `platform` y `store_name` para crear la tienda ecommerce.', $this->telemetry($telemetry, 'create_store'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'CreateEcommerceStore',
            'platform' => $platform,
            'store_name' => $storeName,
            'store_url' => ($storeUrl = $this->firstValue($pairs, ['store_url', 'url'])) !== '' ? $storeUrl : null,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'connection_status' => ($connection = $this->firstValue($pairs, ['connection_status'])) !== '' ? $connection : null,
            'currency' => ($currency = $this->firstValue($pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'timezone' => ($timezone = $this->firstValue($pairs, ['timezone', 'zona_horaria'])) !== '' ? $timezone : null,
        ], $this->telemetry($telemetry, 'create_store'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseUpdateStore(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id', 'id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para actualizar la tienda ecommerce.', $this->telemetry($telemetry, 'update_store'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'UpdateEcommerceStore',
            'store_id' => $storeId,
            'platform' => ($platform = $this->firstValue($pairs, ['platform'])) !== '' ? $platform : null,
            'store_name' => ($storeName = $this->firstValue($pairs, ['store_name', 'name'])) !== '' ? $storeName : null,
            'store_url' => ($storeUrl = $this->firstValue($pairs, ['store_url', 'url'])) !== '' ? $storeUrl : null,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'connection_status' => ($connection = $this->firstValue($pairs, ['connection_status'])) !== '' ? $connection : null,
            'currency' => ($currency = $this->firstValue($pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'timezone' => ($timezone = $this->firstValue($pairs, ['timezone', 'zona_horaria'])) !== '' ? $timezone : null,
        ], $this->telemetry($telemetry, 'update_store'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRegisterCredentials(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $credentialType = $this->firstValue($pairs, ['credential_type', 'type']);
        $secretLike = $this->firstValue($pairs, ['token', 'api_key', 'secret', 'password', 'client_secret', 'key']);
        if ($storeId === '' || $credentialType === '' || $secretLike === '') {
            return $this->askUser('Indica `store_id`, `credential_type` y al menos un secreto (`token`, `api_key`, `secret` o similar) para registrar credenciales ecommerce.', $this->telemetry($telemetry, 'register_credentials'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'RegisterEcommerceStoreCredentials',
            'store_id' => $storeId,
            'credential_type' => $credentialType,
            'token' => ($token = $this->firstValue($pairs, ['token'])) !== '' ? $token : null,
            'api_key' => ($apiKey = $this->firstValue($pairs, ['api_key'])) !== '' ? $apiKey : null,
            'secret' => ($secret = $this->firstValue($pairs, ['secret'])) !== '' ? $secret : null,
            'client_id' => ($clientId = $this->firstValue($pairs, ['client_id'])) !== '' ? $clientId : null,
            'client_secret' => ($clientSecret = $this->firstValue($pairs, ['client_secret'])) !== '' ? $clientSecret : null,
            'username' => ($username = $this->firstValue($pairs, ['username', 'user'])) !== '' ? $username : null,
            'password' => ($password = $this->firstValue($pairs, ['password'])) !== '' ? $password : null,
            'key' => ($key = $this->firstValue($pairs, ['key'])) !== '' ? $key : null,
        ], $this->telemetry($telemetry, 'register_credentials'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseValidateStoreSetup(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id', 'id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para validar la configuracion ecommerce.', $this->telemetry($telemetry, 'validate_store_setup'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ValidateEcommerceStoreSetup',
            'store_id' => $storeId,
        ], $this->telemetry($telemetry, 'validate_store_setup'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseValidateConnection(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id', 'id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para validar la conexion ecommerce.', $this->telemetry($telemetry, 'validate_connection'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ValidateEcommerceConnection',
            'store_id' => $storeId,
        ], $this->telemetry($telemetry, 'validate_connection'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetStoreMetadata(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id', 'id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para cargar la metadata ecommerce.', $this->telemetry($telemetry, 'get_store_metadata'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceStoreMetadata',
            'store_id' => $storeId,
        ], $this->telemetry($telemetry, 'get_store_metadata'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetPlatformCapabilities(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id', 'id']);
        $platform = $this->firstValue($pairs, ['platform']);
        if ($storeId === '' && $platform === '') {
            return $this->askUser('Indica `store_id` o `platform` para consultar capacidades ecommerce.', $this->telemetry($telemetry, 'get_platform_capabilities'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommercePlatformCapabilities',
            'store_id' => $storeId !== '' ? $storeId : null,
            'platform' => $platform !== '' ? $platform : null,
        ], $this->telemetry($telemetry, 'get_platform_capabilities'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parsePingStore(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id', 'id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para revisar el ping ecommerce.', $this->telemetry($telemetry, 'ping_store'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'PingEcommerceStore',
            'store_id' => $storeId,
        ], $this->telemetry($telemetry, 'ping_store'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListStores(array $pairs, array $baseCommand, array $telemetry): array
    {
        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceStores',
            'platform' => ($platform = $this->firstValue($pairs, ['platform'])) !== '' ? $platform : null,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'connection_status' => ($connection = $this->firstValue($pairs, ['connection_status'])) !== '' ? $connection : null,
            'limit' => $this->firstValue($pairs, ['limit']) ?: '10',
        ], $this->telemetry($telemetry, 'list_stores'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetStore(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id', 'id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para cargar la tienda ecommerce.', $this->telemetry($telemetry, 'get_store'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceStore',
            'store_id' => $storeId,
        ], $this->telemetry($telemetry, 'get_store'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseCreateSyncJob(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $syncType = $this->firstValue($pairs, ['sync_type', 'type']);
        if ($storeId === '' || $syncType === '') {
            return $this->askUser('Indica `store_id` y `sync_type` para crear el trabajo de sincronizacion ecommerce.', $this->telemetry($telemetry, 'create_sync_job'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'CreateEcommerceSyncJob',
            'store_id' => $storeId,
            'sync_type' => $syncType,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'result_summary' => ($summary = $this->firstValue($pairs, ['result_summary'])) !== '' ? $summary : null,
        ], $this->telemetry($telemetry, 'create_sync_job'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListSyncJobs(array $pairs, array $baseCommand, array $telemetry): array
    {
        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceSyncJobs',
            'store_id' => ($storeId = $this->firstValue($pairs, ['store_id'])) !== '' ? $storeId : null,
            'sync_type' => ($syncType = $this->firstValue($pairs, ['sync_type', 'type'])) !== '' ? $syncType : null,
            'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            'limit' => $this->firstValue($pairs, ['limit']) ?: '10',
        ], $this->telemetry($telemetry, 'list_sync_jobs'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListOrderRefs(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para listar referencias de pedidos ecommerce.', $this->telemetry($telemetry, 'list_order_refs'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceOrderRefs',
            'store_id' => $storeId,
            'external_order_id' => ($externalOrderId = $this->firstValue($pairs, ['external_order_id'])) !== '' ? $externalOrderId : null,
            'local_order_status' => ($localStatus = $this->firstValue($pairs, ['local_order_status'])) !== '' ? $localStatus : null,
            'external_status' => ($externalStatus = $this->firstValue($pairs, ['external_status'])) !== '' ? $externalStatus : null,
            'limit' => $this->firstValue($pairs, ['limit']) ?: '10',
        ], $this->telemetry($telemetry, 'list_order_refs'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseLinkOrder(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $externalOrderId = $this->firstValue($pairs, ['external_order_id', 'order_id', 'id']);
        if ($storeId === '' || $externalOrderId === '') {
            return $this->askUser('Indica `store_id` y `external_order_id` para vincular el pedido ecommerce.', $this->telemetry($telemetry, 'link_order'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'LinkEcommerceOrder',
            'store_id' => $storeId,
            'external_order_id' => $externalOrderId,
            'local_reference_type' => ($localReferenceType = $this->firstValue($pairs, ['local_reference_type'])) !== '' ? $localReferenceType : null,
            'local_reference_id' => ($localReferenceId = $this->firstValue($pairs, ['local_reference_id'])) !== '' ? $localReferenceId : null,
            'external_status' => ($externalStatus = $this->firstValue($pairs, ['external_status', 'status'])) !== '' ? $externalStatus : null,
            'local_status' => ($localStatus = $this->firstValue($pairs, ['local_status'])) !== '' ? $localStatus : null,
            'currency' => ($currency = $this->firstValue($pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'total' => ($total = $this->firstValue($pairs, ['total'])) !== '' ? $total : null,
        ], $this->telemetry($telemetry, 'link_order'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetOrderLink(array $pairs, array $baseCommand, array $telemetry): array
    {
        $linkId = $this->firstValue($pairs, ['link_id', 'id']);
        if ($linkId === '') {
            return $this->askUser('Indica `link_id` para cargar el vinculo de pedido ecommerce.', $this->telemetry($telemetry, 'get_order_link'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceOrderLink',
            'link_id' => $linkId,
        ], $this->telemetry($telemetry, 'get_order_link'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListOrderLinks(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para listar vinculos de pedidos ecommerce.', $this->telemetry($telemetry, 'list_order_links'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceOrderLinks',
            'store_id' => $storeId,
            'external_order_id' => ($externalOrderId = $this->firstValue($pairs, ['external_order_id', 'order_id'])) !== '' ? $externalOrderId : null,
            'local_reference_type' => ($localReferenceType = $this->firstValue($pairs, ['local_reference_type'])) !== '' ? $localReferenceType : null,
            'local_reference_id' => ($localReferenceId = $this->firstValue($pairs, ['local_reference_id'])) !== '' ? $localReferenceId : null,
            'external_status' => ($externalStatus = $this->firstValue($pairs, ['external_status'])) !== '' ? $externalStatus : null,
            'local_status' => ($localStatus = $this->firstValue($pairs, ['local_status'])) !== '' ? $localStatus : null,
            'currency' => ($currency = $this->firstValue($pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'sync_status' => ($syncStatus = $this->firstValue($pairs, ['sync_status', 'status'])) !== '' ? $syncStatus : null,
            'limit' => $this->firstValue($pairs, ['limit']) ?: '10',
        ], $this->telemetry($telemetry, 'list_order_links'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRegisterOrderPullSnapshot(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $externalOrderId = $this->firstValue($pairs, ['external_order_id', 'order_id', 'id']);
        if ($storeId === '' || $externalOrderId === '') {
            return $this->askUser('Indica `store_id` y `external_order_id` para registrar el snapshot pull del pedido ecommerce.', $this->telemetry($telemetry, 'register_order_pull_snapshot'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'RegisterEcommerceOrderPullSnapshot',
            'store_id' => $storeId,
            'external_order_payload' => $this->buildExternalOrderPayload($pairs, $externalOrderId),
        ], $this->telemetry($telemetry, 'register_order_pull_snapshot'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseNormalizeExternalOrder(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $externalOrderId = $this->firstValue($pairs, ['external_order_id', 'order_id', 'id']);
        if ($storeId === '' || $externalOrderId === '') {
            return $this->askUser('Indica `store_id` y `external_order_id` para normalizar el pedido ecommerce.', $this->telemetry($telemetry, 'normalize_external_order'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'NormalizeEcommerceExternalOrder',
            'store_id' => $storeId,
            'external_order_payload' => $this->buildExternalOrderPayload($pairs, $externalOrderId),
        ], $this->telemetry($telemetry, 'normalize_external_order'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseMarkOrderSyncStatus(array $pairs, array $baseCommand, array $telemetry): array
    {
        $linkId = $this->firstValue($pairs, ['link_id', 'id']);
        $syncStatus = $this->firstValue($pairs, ['sync_status', 'status']);
        if ($linkId === '' || $syncStatus === '') {
            return $this->askUser('Indica `link_id` y `sync_status` para registrar el estado de sync del pedido ecommerce.', $this->telemetry($telemetry, 'mark_order_sync_status'));
        }

        $metadata = array_filter([
            'external_status' => ($externalStatus = $this->firstValue($pairs, ['external_status'])) !== '' ? $externalStatus : null,
            'local_status' => ($localStatus = $this->firstValue($pairs, ['local_status'])) !== '' ? $localStatus : null,
            'note' => ($note = $this->firstValue($pairs, ['note'])) !== '' ? $note : null,
        ], static fn($value): bool => $value !== null && $value !== '');

        return $this->commandResult($baseCommand + [
            'command' => 'MarkEcommerceOrderSyncStatus',
            'link_id' => $linkId,
            'sync_status' => $syncStatus,
            'metadata' => $metadata,
        ], $this->telemetry($telemetry, 'mark_order_sync_status'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetOrderSnapshot(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $externalOrderId = $this->firstValue($pairs, ['external_order_id', 'order_id', 'id']);
        if ($storeId === '' || $externalOrderId === '') {
            return $this->askUser('Indica `store_id` y `external_order_id` para cargar el snapshot del pedido ecommerce.', $this->telemetry($telemetry, 'get_order_snapshot'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceOrderSnapshot',
            'store_id' => $storeId,
            'external_order_id' => $externalOrderId,
        ], $this->telemetry($telemetry, 'get_order_snapshot'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseLinkProduct(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $localProductId = $this->firstValue($pairs, ['local_product_id', 'product_id']);
        $externalProductId = $this->firstValue($pairs, ['external_product_id']);
        if ($storeId === '' || $localProductId === '' || $externalProductId === '') {
            return $this->askUser('Indica `store_id`, `local_product_id` y `external_product_id` para vincular el producto ecommerce.', $this->telemetry($telemetry, 'link_product'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'LinkEcommerceProduct',
            'store_id' => $storeId,
            'local_product_id' => $localProductId,
            'external_product_id' => $externalProductId,
            'external_sku' => ($externalSku = $this->firstValue($pairs, ['external_sku', 'sku'])) !== '' ? $externalSku : null,
        ], $this->telemetry($telemetry, 'link_product'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseUnlinkProduct(array $pairs, array $baseCommand, array $telemetry): array
    {
        $linkId = $this->firstValue($pairs, ['link_id', 'id']);
        if ($linkId === '') {
            return $this->askUser('Indica `link_id` para eliminar el vinculo ecommerce.', $this->telemetry($telemetry, 'unlink_product'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'UnlinkEcommerceProduct',
            'link_id' => $linkId,
        ], $this->telemetry($telemetry, 'unlink_product'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseListProductLinks(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        if ($storeId === '') {
            return $this->askUser('Indica `store_id` para listar vinculos de productos ecommerce.', $this->telemetry($telemetry, 'list_product_links'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'ListEcommerceProductLinks',
            'store_id' => $storeId,
            'local_product_id' => ($localProductId = $this->firstValue($pairs, ['local_product_id', 'product_id'])) !== '' ? $localProductId : null,
            'external_product_id' => ($externalProductId = $this->firstValue($pairs, ['external_product_id'])) !== '' ? $externalProductId : null,
            'external_sku' => ($externalSku = $this->firstValue($pairs, ['external_sku', 'sku'])) !== '' ? $externalSku : null,
            'sync_status' => ($syncStatus = $this->firstValue($pairs, ['sync_status', 'status'])) !== '' ? $syncStatus : null,
            'sync_direction' => ($syncDirection = $this->firstValue($pairs, ['sync_direction'])) !== '' ? $syncDirection : null,
            'limit' => $this->firstValue($pairs, ['limit']) ?: '10',
        ], $this->telemetry($telemetry, 'list_product_links'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseGetProductLink(array $pairs, array $baseCommand, array $telemetry): array
    {
        $linkId = $this->firstValue($pairs, ['link_id', 'id']);
        if ($linkId === '') {
            return $this->askUser('Indica `link_id` para cargar el vinculo ecommerce.', $this->telemetry($telemetry, 'get_product_link'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'GetEcommerceProductLink',
            'link_id' => $linkId,
        ], $this->telemetry($telemetry, 'get_product_link'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parsePrepareProductPushPayload(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $localProductId = $this->firstValue($pairs, ['local_product_id', 'product_id']);
        if ($storeId === '' || $localProductId === '') {
            return $this->askUser('Indica `store_id` y `local_product_id` para preparar el push ecommerce.', $this->telemetry($telemetry, 'prepare_product_push_payload'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'PrepareEcommerceProductPushPayload',
            'store_id' => $storeId,
            'local_product_id' => $localProductId,
        ], $this->telemetry($telemetry, 'prepare_product_push_payload'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseRegisterProductPullSnapshot(array $pairs, array $baseCommand, array $telemetry): array
    {
        $storeId = $this->firstValue($pairs, ['store_id']);
        $externalProductId = $this->firstValue($pairs, ['external_product_id', 'id']);
        if ($storeId === '' || $externalProductId === '') {
            return $this->askUser('Indica `store_id` y `external_product_id` para registrar el snapshot pull ecommerce.', $this->telemetry($telemetry, 'register_product_pull_snapshot'));
        }

        return $this->commandResult($baseCommand + [
            'command' => 'RegisterEcommerceProductPullSnapshot',
            'store_id' => $storeId,
            'external_product_payload' => array_filter([
                'external_product_id' => $externalProductId,
                'external_sku' => ($externalSku = $this->firstValue($pairs, ['external_sku', 'sku'])) !== '' ? $externalSku : null,
                'name' => ($name = $this->firstValue($pairs, ['name', 'title'])) !== '' ? $name : null,
                'status' => ($status = $this->firstValue($pairs, ['status'])) !== '' ? $status : null,
            ], static fn($value): bool => $value !== null && $value !== ''),
        ], $this->telemetry($telemetry, 'register_product_pull_snapshot'));
    }

    /**
     * @param array<string, string> $pairs
     * @param array<string, mixed> $baseCommand
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function parseMarkProductSyncStatus(array $pairs, array $baseCommand, array $telemetry): array
    {
        $linkId = $this->firstValue($pairs, ['link_id', 'id']);
        $syncStatus = $this->firstValue($pairs, ['sync_status', 'status']);
        if ($linkId === '' || $syncStatus === '') {
            return $this->askUser('Indica `link_id` y `sync_status` para registrar el estado de sync ecommerce.', $this->telemetry($telemetry, 'mark_product_sync_status'));
        }

        $metadata = array_filter([
            'sync_direction' => ($syncDirection = $this->firstValue($pairs, ['sync_direction'])) !== '' ? $syncDirection : null,
            'note' => ($note = $this->firstValue($pairs, ['note'])) !== '' ? $note : null,
        ], static fn($value): bool => $value !== null && $value !== '');

        return $this->commandResult($baseCommand + [
            'command' => 'MarkEcommerceProductSyncStatus',
            'link_id' => $linkId,
            'sync_status' => $syncStatus,
            'metadata' => $metadata,
        ], $this->telemetry($telemetry, 'mark_product_sync_status'));
    }

    /**
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function telemetry(array $telemetry, string $action): array
    {
        $telemetry['ecommerce_action'] = $action;

        return $telemetry;
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function commandResult(array $command, array $telemetry): array
    {
        return [
            'kind' => 'command',
            'command' => $command,
            'telemetry' => $telemetry,
        ];
    }

    /**
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private function askUser(string $reply, array $telemetry): array
    {
        return [
            'kind' => 'ask_user',
            'reply' => $reply,
            'telemetry' => $telemetry,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        if ($message === '') {
            return $pairs;
        }

        if (preg_match_all('/([a-zA-Z0-9_]+)\s*=\s*"([^"]*)"/', $message, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $pairs[strtolower((string) ($match[1] ?? ''))] = (string) ($match[2] ?? '');
            }
        }
        if (preg_match_all('/([a-zA-Z0-9_]+)\s*=\s*([^\s"]+)/', $message, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower((string) ($match[1] ?? ''));
                if ($key === '' || array_key_exists($key, $pairs)) {
                    continue;
                }
                $pairs[$key] = trim((string) ($match[2] ?? ''));
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs
     * @param array<int, string> $keys
     */
    private function firstValue(array $pairs, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($pairs[strtolower($key)] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $pairs
     * @return array<string, mixed>
     */
    private function buildExternalOrderPayload(array $pairs, string $externalOrderId): array
    {
        return array_filter([
            'external_order_id' => $externalOrderId,
            'status' => ($status = $this->firstValue($pairs, ['status', 'external_status'])) !== '' ? $status : null,
            'currency' => ($currency = $this->firstValue($pairs, ['currency', 'moneda'])) !== '' ? $currency : null,
            'total' => ($total = $this->firstValue($pairs, ['total'])) !== '' ? $total : null,
        ], static fn($value): bool => $value !== null && $value !== '');
    }
}
