<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class EcommerceHubCommandHandler implements CommandHandlerInterface
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        'CreateEcommerceStore',
        'UpdateEcommerceStore',
        'RegisterEcommerceStoreCredentials',
        'ValidateEcommerceStoreSetup',
        'ValidateEcommerceConnection',
        'GetEcommerceStoreMetadata',
        'GetEcommercePlatformCapabilities',
        'PingEcommerceStore',
        'ListEcommerceStores',
        'GetEcommerceStore',
        'CreateEcommerceSyncJob',
        'ListEcommerceSyncJobs',
        'ListEcommerceOrderRefs',
        'LinkEcommerceOrder',
        'GetEcommerceOrderLink',
        'ListEcommerceOrderLinks',
        'RegisterEcommerceOrderPullSnapshot',
        'NormalizeEcommerceExternalOrder',
        'MarkEcommerceOrderSyncStatus',
        'GetEcommerceOrderSnapshot',
        'LinkEcommerceProduct',
        'UnlinkEcommerceProduct',
        'ListEcommerceProductLinks',
        'GetEcommerceProductLink',
        'PrepareEcommerceProductPushPayload',
        'RegisterEcommerceProductPullSnapshot',
        'MarkEcommerceProductSyncStatus',
    ];

    public function supports(string $commandName): bool
    {
        return in_array($commandName, self::SUPPORTED, true);
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = strtolower(trim((string) ($context['mode'] ?? 'app')));
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');
        $tenantId = trim((string) ($command['tenant_id'] ?? $context['tenant_id'] ?? ''));
        $appId = trim((string) ($command['app_id'] ?? $context['project_id'] ?? ''));

        if ($mode === 'builder') {
            return $this->withReplyText($reply(
                'Estas en modo creador. Usa el chat de la app para operar Ecommerce Hub.',
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData()
            ));
        }

        $service = $context['ecommerce_service'] ?? null;
        if (!$service instanceof EcommerceHubService) {
            $service = new EcommerceHubService();
        }

        try {
            return match ((string) ($command['command'] ?? '')) {
                'CreateEcommerceStore' => $this->respondStore($reply, $channel, $sessionId, $userId, 'Tienda ecommerce creada.', 'create_store', $service->createStore($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null])),
                'UpdateEcommerceStore' => $this->respondStore($reply, $channel, $sessionId, $userId, 'Tienda ecommerce actualizada.', 'update_store', $service->updateStore($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null])),
                'RegisterEcommerceStoreCredentials' => $this->respondCredential($reply, $channel, $sessionId, $userId, $service->registerCredentials($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null])),
                'ValidateEcommerceStoreSetup' => $this->respondSetup($reply, $channel, $sessionId, $userId, $service->validateStoreSetup($tenantId, trim((string) ($command['store_id'] ?? '')), $appId !== '' ? $appId : null)),
                'ValidateEcommerceConnection' => $this->respondConnectionValidation($reply, $channel, $sessionId, $userId, $service->validateConnection($tenantId, trim((string) ($command['store_id'] ?? '')), $appId !== '' ? $appId : null)),
                'GetEcommerceStoreMetadata' => $this->respondStoreMetadata($reply, $channel, $sessionId, $userId, $service->getNormalizedStoreMetadata($tenantId, trim((string) ($command['store_id'] ?? '')), $appId !== '' ? $appId : null)),
                'GetEcommercePlatformCapabilities' => $this->respondPlatformCapabilities($reply, $channel, $sessionId, $userId, $service->getPlatformCapabilities(
                    $tenantId,
                    ($storeId = trim((string) ($command['store_id'] ?? ''))) !== '' ? $storeId : null,
                    ($platform = trim((string) ($command['platform'] ?? ''))) !== '' ? $platform : null,
                    $appId !== '' ? $appId : null
                )),
                'PingEcommerceStore' => $this->respondPing($reply, $channel, $sessionId, $userId, $service->pingStore($tenantId, trim((string) ($command['store_id'] ?? '')), $appId !== '' ? $appId : null)),
                'ListEcommerceStores' => $this->respondStoreList($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetEcommerceStore' => $this->respondStore($reply, $channel, $sessionId, $userId, 'Tienda ecommerce cargada.', 'get_store', $service->getStore($tenantId, trim((string) ($command['store_id'] ?? '')), $appId !== '' ? $appId : null)),
                'CreateEcommerceSyncJob' => $this->respondSyncJob($reply, $channel, $sessionId, $userId, $service->createSyncJob($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null])),
                'ListEcommerceSyncJobs' => $this->respondSyncJobList($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'ListEcommerceOrderRefs' => $this->respondOrderRefList($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'LinkEcommerceOrder' => $this->respondOrderLink($reply, $channel, $sessionId, $userId, 'Pedido ecommerce vinculado.', 'link_order', $service->linkOrder($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null])),
                'GetEcommerceOrderLink' => $this->respondOrderLink($reply, $channel, $sessionId, $userId, 'Vinculo de pedido ecommerce cargado.', 'get_order_link', $service->getOrderLink($tenantId, trim((string) ($command['link_id'] ?? '')), $appId !== '' ? $appId : null)),
                'ListEcommerceOrderLinks' => $this->respondOrderLinkList($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'RegisterEcommerceOrderPullSnapshot' => $this->respondOrderSnapshot($reply, $channel, $sessionId, $userId, 'Snapshot pull de pedido ecommerce registrado.', 'register_order_pull_snapshot', $service->registerOrderPullSnapshot(
                    $tenantId,
                    trim((string) ($command['store_id'] ?? '')),
                    is_array($command['external_order_payload'] ?? null) ? (array) $command['external_order_payload'] : [],
                    $appId !== '' ? $appId : null
                )),
                'NormalizeEcommerceExternalOrder' => $this->respondNormalizedOrderPayload($reply, $channel, $sessionId, $userId, $service->normalizeExternalOrderPayload(
                    $tenantId,
                    trim((string) ($command['store_id'] ?? '')),
                    is_array($command['external_order_payload'] ?? null) ? (array) $command['external_order_payload'] : [],
                    $appId !== '' ? $appId : null
                )),
                'MarkEcommerceOrderSyncStatus' => $this->respondOrderLink($reply, $channel, $sessionId, $userId, 'Estado de sync de pedido ecommerce actualizado.', 'mark_order_sync_status', $service->markOrderSyncStatus(
                    $tenantId,
                    trim((string) ($command['link_id'] ?? '')),
                    trim((string) ($command['sync_status'] ?? '')),
                    is_array($command['metadata'] ?? null) ? (array) $command['metadata'] : [],
                    $appId !== '' ? $appId : null
                )),
                'GetEcommerceOrderSnapshot' => $this->respondOrderSnapshot($reply, $channel, $sessionId, $userId, 'Snapshot de pedido ecommerce cargado.', 'get_order_snapshot', $service->getOrderSnapshot(
                    $tenantId,
                    trim((string) ($command['store_id'] ?? '')),
                    trim((string) ($command['external_order_id'] ?? '')),
                    $appId !== '' ? $appId : null
                )),
                'LinkEcommerceProduct' => $this->respondProductLink($reply, $channel, $sessionId, $userId, 'Producto ecommerce vinculado.', 'link_product', $service->linkProduct($command + ['tenant_id' => $tenantId, 'app_id' => $appId !== '' ? $appId : null])),
                'UnlinkEcommerceProduct' => $this->respondProductLink($reply, $channel, $sessionId, $userId, 'Vinculo de producto ecommerce eliminado.', 'unlink_product', $service->unlinkProduct($tenantId, trim((string) ($command['link_id'] ?? '')), $appId !== '' ? $appId : null)),
                'ListEcommerceProductLinks' => $this->respondProductLinkList($service, $tenantId, $appId, $command, $reply, $channel, $sessionId, $userId),
                'GetEcommerceProductLink' => $this->respondProductLink($reply, $channel, $sessionId, $userId, 'Vinculo de producto ecommerce cargado.', 'get_product_link', $service->getProductLink($tenantId, trim((string) ($command['link_id'] ?? '')), $appId !== '' ? $appId : null)),
                'PrepareEcommerceProductPushPayload' => $this->respondPreparedProductPayload($reply, $channel, $sessionId, $userId, $service->prepareProductPushPayload(
                    $tenantId,
                    trim((string) ($command['store_id'] ?? '')),
                    trim((string) ($command['local_product_id'] ?? '')),
                    $appId !== '' ? $appId : null
                )),
                'RegisterEcommerceProductPullSnapshot' => $this->respondPullSnapshotRegistration($reply, $channel, $sessionId, $userId, $service->registerProductPullSnapshot(
                    $tenantId,
                    trim((string) ($command['store_id'] ?? '')),
                    is_array($command['external_product_payload'] ?? null) ? (array) $command['external_product_payload'] : [],
                    $appId !== '' ? $appId : null
                )),
                'MarkEcommerceProductSyncStatus' => $this->respondProductLink($reply, $channel, $sessionId, $userId, 'Estado de sync de producto ecommerce actualizado.', 'mark_product_sync_status', $service->markProductSyncStatus(
                    $tenantId,
                    trim((string) ($command['link_id'] ?? '')),
                    trim((string) ($command['sync_status'] ?? '')),
                    is_array($command['metadata'] ?? null) ? (array) $command['metadata'] : [],
                    $appId !== '' ? $appId : null
                )),
                default => throw new RuntimeException('COMMAND_NOT_SUPPORTED'),
            };
        } catch (Throwable $e) {
            return $this->withReplyText($reply(
                $this->humanizeError((string) $e->getMessage()),
                $channel,
                $sessionId,
                $userId,
                'error',
                $this->moduleData(['result_status' => 'error'])
            ));
        }
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function respondStore(callable $reply, string $channel, string $sessionId, string $userId, string $text, string $actionName, array $store): array
    {
        return $this->withReplyText($reply($text, $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => $actionName,
            'store' => $store,
            'item' => $store,
            'store_id' => (string) ($store['id'] ?? ''),
            'platform' => (string) ($store['platform'] ?? ''),
            'adapter_key' => $this->adapterKeyFromStore($store),
            'connection_status' => (string) ($store['connection_status'] ?? ''),
            'validation_result' => 'not_applicable',
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    private function respondCredential(callable $reply, string $channel, string $sessionId, string $userId, array $credential): array
    {
        return $this->withReplyText($reply('Credenciales ecommerce registradas.', $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'register_credentials',
            'credential' => $credential,
            'item' => $credential,
            'store_id' => (string) ($credential['store_id'] ?? ''),
            'validation_result' => 'credentials_registered',
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $setup
     * @return array<string, mixed>
     */
    private function respondSetup(callable $reply, string $channel, string $sessionId, string $userId, array $setup): array
    {
        return $this->withReplyText($reply('Validacion ecommerce completada.', $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'validate_store_setup',
            'setup' => $setup,
            'item' => $setup,
            'store_id' => (string) ($setup['store_id'] ?? ''),
            'platform' => (string) ($setup['platform'] ?? ''),
            'adapter_key' => (string) ($setup['adapter_key'] ?? ''),
            'connection_status' => (string) ($setup['connection_status'] ?? ''),
            'validation_result' => (string) ($setup['validation_result'] ?? 'unknown'),
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondConnectionValidation(callable $reply, string $channel, string $sessionId, string $userId, array $result): array
    {
        $text = ($result['valid'] ?? false) === true
            ? 'Conexion ecommerce revisada. La configuracion base es valida.'
            : 'Conexion ecommerce revisada. La configuracion actual no quedo validada.';

        return $this->withReplyText($reply($text, $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'validate_connection',
            'connection_validation' => $result,
            'item' => $result,
            'store_id' => (string) ($result['store_id'] ?? ''),
            'platform' => (string) ($result['platform'] ?? ''),
            'adapter_key' => (string) ($result['adapter_key'] ?? ''),
            'connection_status' => (string) ($result['connection_status'] ?? ''),
            'validation_result' => (string) ($result['validation_result'] ?? 'unknown'),
            'result_status' => (string) ($result['result_status'] ?? 'safe_failure'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondStoreMetadata(callable $reply, string $channel, string $sessionId, string $userId, array $result): array
    {
        return $this->withReplyText($reply('Metadata ecommerce cargada.', $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'get_store_metadata',
            'store_metadata' => $result,
            'item' => $result,
            'store_id' => (string) ($result['store_id'] ?? ''),
            'platform' => (string) ($result['platform'] ?? ''),
            'adapter_key' => (string) ($result['adapter_key'] ?? ''),
            'connection_status' => (string) ($result['connection_status'] ?? ''),
            'validation_result' => 'metadata_ready',
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondPlatformCapabilities(callable $reply, string $channel, string $sessionId, string $userId, array $result): array
    {
        return $this->withReplyText($reply('Capacidades ecommerce cargadas.', $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'get_platform_capabilities',
            'platform_capabilities' => $result,
            'item' => $result,
            'store_id' => (string) ($result['store_id'] ?? ''),
            'platform' => (string) ($result['platform'] ?? ''),
            'adapter_key' => (string) ($result['adapter_key'] ?? ''),
            'validation_result' => (string) ($result['validation_result'] ?? 'unknown'),
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondPing(callable $reply, string $channel, string $sessionId, string $userId, array $result): array
    {
        $text = 'Ping ecommerce revisado.';
        if (trim((string) ($result['message'] ?? '')) !== '') {
            $text .= ' ' . trim((string) ($result['message'] ?? ''));
        }

        return $this->withReplyText($reply($text, $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'ping_store',
            'ping_result' => $result,
            'item' => $result,
            'store_id' => (string) ($result['store_id'] ?? ''),
            'platform' => (string) ($result['platform'] ?? ''),
            'adapter_key' => (string) ($result['adapter_key'] ?? ''),
            'connection_status' => (string) ($result['connection_status'] ?? ''),
            'validation_result' => (string) ($result['validation_result'] ?? 'unknown'),
            'result_status' => (string) ($result['result_status'] ?? 'safe_failure'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function respondSyncJob(callable $reply, string $channel, string $sessionId, string $userId, array $job): array
    {
        return $this->withReplyText($reply('Sync job ecommerce creado.', $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'create_sync_job',
            'sync_job' => $job,
            'item' => $job,
            'store_id' => (string) ($job['store_id'] ?? ''),
            'adapter_key' => $this->adapterKeyFromSyncJob($job),
            'sync_job_id' => (string) ($job['id'] ?? ''),
            'sync_type' => (string) ($job['sync_type'] ?? ''),
            'validation_result' => 'sync_job_registered',
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    private function respondOrderLink(callable $reply, string $channel, string $sessionId, string $userId, string $text, string $actionName, array $link): array
    {
        $validationResult = match ($actionName) {
            'link_order' => 'order_linked',
            'get_order_link' => 'order_link_loaded',
            'mark_order_sync_status' => 'sync_status_recorded',
            default => 'not_applicable',
        };

        return $this->withReplyText($reply($text, $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => $actionName,
            'order_link' => $link,
            'item' => $link,
            'store_id' => (string) ($link['store_id'] ?? ''),
            'link_id' => (string) ($link['id'] ?? ''),
            'external_order_id' => (string) ($link['external_order_id'] ?? ''),
            'local_reference_type' => (string) ($link['local_reference_type'] ?? ''),
            'local_reference_id' => (string) ($link['local_reference_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'validation_result' => $validationResult,
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondNormalizedOrderPayload(callable $reply, string $channel, string $sessionId, string $userId, array $result): array
    {
        return $this->withReplyText($reply('Payload externo de pedido ecommerce normalizado.', $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'normalize_external_order',
            'normalized_order_payload' => $result,
            'item' => $result,
            'store_id' => (string) ($result['store_id'] ?? ''),
            'platform' => (string) ($result['platform'] ?? ''),
            'adapter_key' => (string) ($result['adapter_key'] ?? ''),
            'external_order_id' => (string) ($result['external_order_id'] ?? ''),
            'link_id' => is_array($result['existing_link'] ?? null) ? (string) (($result['existing_link']['id'] ?? '') ?: '') : '',
            'local_reference_type' => is_array($result['existing_link'] ?? null) ? (string) (($result['existing_link']['local_reference_type'] ?? '') ?: '') : '',
            'local_reference_id' => is_array($result['existing_link'] ?? null) ? (string) (($result['existing_link']['local_reference_id'] ?? '') ?: '') : '',
            'sync_status' => is_array($result['existing_link'] ?? null) ? (string) (($result['existing_link']['sync_status'] ?? '') ?: '') : '',
            'validation_result' => (string) ($result['validation_result'] ?? 'unknown'),
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondOrderSnapshot(callable $reply, string $channel, string $sessionId, string $userId, string $text, string $actionName, array $result): array
    {
        $snapshot = is_array($result['snapshot'] ?? null) ? (array) $result['snapshot'] : $result;
        $link = is_array($result['link'] ?? null) ? (array) $result['link'] : [];

        return $this->withReplyText($reply($text, $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => $actionName,
            'order_snapshot_result' => $result,
            'item' => $result,
            'store_id' => (string) ($result['store_id'] ?? $snapshot['store_id'] ?? ''),
            'platform' => (string) ($result['platform'] ?? ''),
            'adapter_key' => (string) ($result['adapter_key'] ?? ''),
            'link_id' => (string) ($link['id'] ?? ''),
            'external_order_id' => (string) ($result['external_order_id'] ?? $snapshot['external_order_id'] ?? ''),
            'local_reference_type' => (string) ($link['local_reference_type'] ?? ''),
            'local_reference_id' => (string) ($link['local_reference_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'validation_result' => (string) ($result['validation_result'] ?? ($actionName === 'get_order_snapshot' ? 'snapshot_loaded' : 'unknown')),
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    private function respondProductLink(callable $reply, string $channel, string $sessionId, string $userId, string $text, string $actionName, array $link): array
    {
        $validationResult = match ($actionName) {
            'link_product' => 'product_linked',
            'unlink_product' => 'product_unlinked',
            'get_product_link' => 'product_link_loaded',
            'mark_product_sync_status' => 'sync_status_recorded',
            default => 'not_applicable',
        };

        return $this->withReplyText($reply($text, $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => $actionName,
            'product_link' => $link,
            'item' => $link,
            'store_id' => (string) ($link['store_id'] ?? ''),
            'link_id' => (string) ($link['id'] ?? ''),
            'product_id' => (string) ($link['local_product_id'] ?? ''),
            'local_product_id' => (string) ($link['local_product_id'] ?? ''),
            'external_product_id' => (string) ($link['external_product_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'sync_direction' => (string) ($link['last_sync_direction'] ?? ''),
            'validation_result' => $validationResult,
            'result_status' => 'success',
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondPreparedProductPayload(callable $reply, string $channel, string $sessionId, string $userId, array $result): array
    {
        return $this->withReplyText($reply('Payload de push ecommerce preparado.', $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'prepare_product_push_payload',
            'prepared_product_payload' => $result,
            'item' => $result,
            'store_id' => (string) ($result['store_id'] ?? ''),
            'platform' => (string) ($result['platform'] ?? ''),
            'adapter_key' => (string) ($result['adapter_key'] ?? ''),
            'product_id' => (string) ($result['local_product_id'] ?? ''),
            'local_product_id' => (string) ($result['local_product_id'] ?? ''),
            'sync_direction' => (string) ($result['sync_direction'] ?? 'push_local_to_store'),
            'validation_result' => (string) ($result['validation_result'] ?? 'unknown'),
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function respondPullSnapshotRegistration(callable $reply, string $channel, string $sessionId, string $userId, array $result): array
    {
        $link = is_array($result['link'] ?? null) ? (array) $result['link'] : [];

        return $this->withReplyText($reply('Snapshot pull ecommerce registrado.', $channel, $sessionId, $userId, 'success', $this->moduleData([
            'ecommerce_action' => 'register_product_pull_snapshot',
            'pull_snapshot_result' => $result,
            'item' => $result,
            'store_id' => (string) ($result['store_id'] ?? ''),
            'platform' => (string) ($result['platform'] ?? ''),
            'adapter_key' => (string) ($result['adapter_key'] ?? ''),
            'link_id' => (string) ($link['id'] ?? ''),
            'product_id' => (string) ($link['local_product_id'] ?? ''),
            'local_product_id' => (string) ($link['local_product_id'] ?? ''),
            'external_product_id' => (string) ($link['external_product_id'] ?? ''),
            'sync_status' => (string) ($link['sync_status'] ?? ''),
            'sync_direction' => (string) ($result['sync_direction'] ?? 'pull_store_to_local'),
            'validation_result' => (string) ($result['validation_result'] ?? 'unknown'),
            'result_status' => (string) ($result['result_status'] ?? 'success'),
        ])));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondStoreList(EcommerceHubService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $items = $service->listStores($tenantId, array_filter([
            'platform' => $command['platform'] ?? null,
            'status' => $command['status'] ?? null,
            'connection_status' => $command['connection_status'] ?? null,
            'limit' => $command['limit'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== ''), $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            $items === [] ? 'No hay tiendas ecommerce con esos filtros.' : "Tiendas ecommerce:\n" . implode("\n", array_map([$this, 'formatStoreLine'], $items)),
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'ecommerce_action' => 'list_stores',
                'items' => $items,
                'result_count' => count($items),
                'store_id' => (string) (($items[0]['id'] ?? '') ?: ''),
                'platform' => (string) (($items[0]['platform'] ?? '') ?: ''),
                'connection_status' => (string) (($items[0]['connection_status'] ?? '') ?: ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondSyncJobList(EcommerceHubService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $items = $service->listSyncJobs($tenantId, array_filter([
            'store_id' => $command['store_id'] ?? null,
            'sync_type' => $command['sync_type'] ?? null,
            'status' => $command['status'] ?? null,
            'limit' => $command['limit'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== ''), $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            $items === [] ? 'No hay sync jobs ecommerce con esos filtros.' : "Sync jobs ecommerce:\n" . implode("\n", array_map([$this, 'formatSyncJobLine'], $items)),
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'ecommerce_action' => 'list_sync_jobs',
                'items' => $items,
                'result_count' => count($items),
                'store_id' => (string) (($items[0]['store_id'] ?? ($command['store_id'] ?? '')) ?: ''),
                'sync_job_id' => (string) (($items[0]['id'] ?? '') ?: ''),
                'sync_type' => (string) (($items[0]['sync_type'] ?? ($command['sync_type'] ?? '')) ?: ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondOrderRefList(EcommerceHubService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $storeId = trim((string) ($command['store_id'] ?? ''));
        $items = $service->getOrderRefsByStore($tenantId, $storeId, array_filter([
            'external_order_id' => $command['external_order_id'] ?? null,
            'local_order_status' => $command['local_order_status'] ?? null,
            'external_status' => $command['external_status'] ?? null,
            'limit' => $command['limit'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== ''), $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            $items === [] ? 'No hay referencias de pedidos ecommerce con esos filtros.' : "Pedidos ecommerce:\n" . implode("\n", array_map([$this, 'formatOrderRefLine'], $items)),
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'ecommerce_action' => 'list_order_refs',
                'items' => $items,
                'result_count' => count($items),
                'store_id' => $storeId,
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondOrderLinkList(EcommerceHubService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $storeId = trim((string) ($command['store_id'] ?? ''));
        $items = $service->listOrderLinks($tenantId, $storeId, array_filter([
            'external_order_id' => $command['external_order_id'] ?? null,
            'local_reference_type' => $command['local_reference_type'] ?? null,
            'local_reference_id' => $command['local_reference_id'] ?? null,
            'external_status' => $command['external_status'] ?? null,
            'local_status' => $command['local_status'] ?? null,
            'currency' => $command['currency'] ?? null,
            'sync_status' => $command['sync_status'] ?? null,
            'limit' => $command['limit'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== ''), $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            $items === [] ? 'No hay vinculos de pedidos ecommerce con esos filtros.' : "Vinculos de pedidos ecommerce:\n" . implode("\n", array_map([$this, 'formatOrderLinkLine'], $items)),
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'ecommerce_action' => 'list_order_links',
                'items' => $items,
                'result_count' => count($items),
                'store_id' => $storeId,
                'link_id' => (string) (($items[0]['id'] ?? '') ?: ''),
                'external_order_id' => (string) (($items[0]['external_order_id'] ?? '') ?: ''),
                'local_reference_type' => (string) (($items[0]['local_reference_type'] ?? '') ?: ''),
                'local_reference_id' => (string) (($items[0]['local_reference_id'] ?? '') ?: ''),
                'sync_status' => (string) (($items[0]['sync_status'] ?? '') ?: ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param callable $reply
     * @return array<string, mixed>
     */
    private function respondProductLinkList(EcommerceHubService $service, string $tenantId, string $appId, array $command, callable $reply, string $channel, string $sessionId, string $userId): array
    {
        $storeId = trim((string) ($command['store_id'] ?? ''));
        $items = $service->listProductLinks($tenantId, $storeId, array_filter([
            'local_product_id' => $command['local_product_id'] ?? null,
            'external_product_id' => $command['external_product_id'] ?? null,
            'external_sku' => $command['external_sku'] ?? null,
            'sync_status' => $command['sync_status'] ?? null,
            'last_sync_direction' => $command['sync_direction'] ?? null,
            'limit' => $command['limit'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== ''), $appId !== '' ? $appId : null);

        return $this->withReplyText($reply(
            $items === [] ? 'No hay vinculos de productos ecommerce con esos filtros.' : "Vinculos de productos ecommerce:\n" . implode("\n", array_map([$this, 'formatProductLinkLine'], $items)),
            $channel,
            $sessionId,
            $userId,
            'success',
            $this->moduleData([
                'ecommerce_action' => 'list_product_links',
                'items' => $items,
                'result_count' => count($items),
                'store_id' => $storeId,
                'link_id' => (string) (($items[0]['id'] ?? '') ?: ''),
                'product_id' => (string) (($items[0]['local_product_id'] ?? '') ?: ''),
                'local_product_id' => (string) (($items[0]['local_product_id'] ?? '') ?: ''),
                'external_product_id' => (string) (($items[0]['external_product_id'] ?? '') ?: ''),
                'sync_status' => (string) (($items[0]['sync_status'] ?? '') ?: ''),
                'sync_direction' => (string) (($items[0]['last_sync_direction'] ?? '') ?: ''),
                'result_status' => 'success',
            ])
        ));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function moduleData(array $extra = []): array
    {
        return $extra + [
            'module_used' => 'ecommerce',
            'ecommerce_action' => 'none',
            'store_id' => '',
            'platform' => '',
            'adapter_key' => '',
            'connection_status' => '',
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
            'validation_result' => 'none',
            'result_status' => 'success',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withReplyText(array $payload): array
    {
        if (!is_array($payload['data'] ?? null)) {
            $payload['data'] = [];
        }
        if (!array_key_exists('reply', $payload['data'])) {
            $payload['data']['reply'] = (string) ($payload['message'] ?? '');
        }

        return $payload;
    }

    private function humanizeError(string $error): string
    {
        return match ($error) {
            'ECOMMERCE_STORE_NOT_FOUND' => 'No encontre esa tienda ecommerce en este tenant.',
            'ECOMMERCE_SYNC_JOB_NOT_FOUND' => 'No encontre ese sync job ecommerce en este tenant.',
            'ECOMMERCE_PLATFORM_INVALID' => 'La plataforma ecommerce no es valida. Usa woocommerce, tiendanube, prestashop, custom_store o unknown.',
            'ECOMMERCE_STORE_STATUS_INVALID' => 'El estado de la tienda ecommerce no es valido.',
            'ECOMMERCE_CONNECTION_STATUS_INVALID' => 'El estado de conexion ecommerce no es valido.',
            'ECOMMERCE_SYNC_JOB_STATUS_INVALID' => 'El estado del sync job ecommerce no es valido.',
            'ECOMMERCE_CREDENTIAL_PAYLOAD_REQUIRED' => 'Necesito al menos un secreto o token para registrar credenciales ecommerce.',
            'ECOMMERCE_PRODUCT_LINK_NOT_FOUND' => 'No encontre ese vinculo de producto ecommerce en este tenant.',
            'ECOMMERCE_PRODUCT_LINK_CONFLICT' => 'Ya existe un conflicto entre el producto local y el externo para esa tienda ecommerce.',
            'ECOMMERCE_ORDER_LINK_NOT_FOUND' => 'No encontre ese vinculo de pedido ecommerce en este tenant.',
            'ECOMMERCE_ORDER_SNAPSHOT_NOT_FOUND' => 'No encontre snapshot de ese pedido ecommerce en este tenant.',
            'ECOMMERCE_LOCAL_PRODUCT_NOT_FOUND' => 'No encontre ese producto local dentro del tenant actual.',
            'ECOMMERCE_EXTERNAL_PRODUCT_ID_REQUIRED' => 'Necesito `external_product_id` para registrar el snapshot pull ecommerce.',
            'ECOMMERCE_EXTERNAL_ORDER_ID_REQUIRED' => 'Necesito `external_order_id` para registrar el pedido ecommerce.',
            'ECOMMERCE_PRODUCT_SYNC_STATUS_INVALID' => 'El estado de sync de producto ecommerce no es valido.',
            'ECOMMERCE_ORDER_SYNC_STATUS_INVALID' => 'El estado de sync de pedido ecommerce no es valido.',
            'ECOMMERCE_ORDER_STATUS_INVALID' => 'El estado de pedido ecommerce no es valido.',
            'ECOMMERCE_SYNC_DIRECTION_INVALID' => 'La direccion de sync ecommerce no es valida.',
            'TENANT_ID_REQUIRED' => 'Falta tenant_id para ejecutar la operacion ecommerce.',
            'STORE_ID_REQUIRED' => 'Falta store_id para ejecutar la operacion ecommerce.',
            'LINK_ID_REQUIRED' => 'Falta link_id para ejecutar la operacion ecommerce.',
            'STORE_NAME_REQUIRED' => 'Falta store_name para crear la tienda ecommerce.',
            'PLATFORM_REQUIRED' => 'Falta platform para crear la tienda ecommerce.',
            'EXTERNAL_ORDER_ID_REQUIRED' => 'Falta external_order_id para ejecutar la operacion ecommerce.',
            'LOCAL_PRODUCT_ID_REQUIRED' => 'Falta local_product_id para ejecutar la operacion ecommerce.',
            'EXTERNAL_PRODUCT_ID_REQUIRED' => 'Falta external_product_id para ejecutar la operacion ecommerce.',
            'CREDENTIAL_TYPE_REQUIRED' => 'Falta credential_type para registrar credenciales ecommerce.',
            'SYNC_TYPE_REQUIRED' => 'Falta sync_type para crear el sync job ecommerce.',
            'SYNC_STATUS_REQUIRED' => 'Falta sync_status para ejecutar la operacion ecommerce.',
            'ECOMMERCE_PLATFORM_OR_STORE_REQUIRED' => 'Necesito `store_id` o `platform` para obtener capacidades ecommerce.',
            default => $error !== '' ? $error : 'No pude ejecutar la operacion ecommerce.',
        };
    }

    /**
     * @param array<string, mixed> $store
     */
    private function adapterKeyFromStore(array $store): string
    {
        $metadata = is_array($store['metadata'] ?? null) ? (array) $store['metadata'] : [];
        $adapterKey = trim((string) ($metadata['adapter_foundation']['adapter_key'] ?? ''));
        if ($adapterKey !== '') {
            return $adapterKey;
        }

        $platform = trim((string) ($store['platform'] ?? ''));
        if (in_array($platform, ['woocommerce', 'tiendanube', 'prestashop'], true)) {
            return $platform;
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $job
     */
    private function adapterKeyFromSyncJob(array $job): string
    {
        $metadata = is_array($job['metadata'] ?? null) ? (array) $job['metadata'] : [];
        $platform = trim((string) ($metadata['store_platform'] ?? ''));
        if (in_array($platform, ['woocommerce', 'tiendanube', 'prestashop'], true)) {
            return $platform;
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function replyCallable(array $context): callable
    {
        $reply = $context['reply'] ?? null;
        if (is_callable($reply)) {
            return $reply;
        }

        return static function (string $text, string $channel, string $sessionId, string $userId, string $status = 'success', array $data = []): array {
            return [
                'status' => $status,
                'message' => $status === 'success' ? 'OK' : $text,
                'data' => array_merge([
                    'reply' => $text,
                    'channel' => $channel,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                ], $data),
            ];
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatStoreLine(array $item): string
    {
        return '- ' . (string) ($item['id'] ?? '') . ' | ' . (string) ($item['store_name'] ?? '') . ' | ' . (string) ($item['platform'] ?? '') . ' | ' . (string) ($item['connection_status'] ?? '');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatSyncJobLine(array $item): string
    {
        return '- ' . (string) ($item['id'] ?? '') . ' | store=' . (string) ($item['store_id'] ?? '') . ' | ' . (string) ($item['sync_type'] ?? '') . ' | ' . (string) ($item['status'] ?? '');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatOrderRefLine(array $item): string
    {
        return '- ' . (string) ($item['id'] ?? '') . ' | ext=' . (string) ($item['external_order_id'] ?? '') . ' | local=' . (string) ($item['local_order_status'] ?? '') . ' | ext_status=' . (string) ($item['external_status'] ?? '');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatOrderLinkLine(array $item): string
    {
        return '- ' . (string) ($item['id'] ?? '')
            . ' | ext=' . (string) ($item['external_order_id'] ?? '')
            . ' | local_ref=' . (string) ($item['local_reference_type'] ?? '') . ':' . (string) ($item['local_reference_id'] ?? '')
            . ' | status=' . (string) ($item['sync_status'] ?? '');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function formatProductLinkLine(array $item): string
    {
        return '- ' . (string) ($item['id'] ?? '')
            . ' | local=' . (string) ($item['local_product_id'] ?? '')
            . ' | ext=' . (string) ($item['external_product_id'] ?? '')
            . ' | status=' . (string) ($item['sync_status'] ?? '');
    }
}
