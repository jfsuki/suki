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
            'TENANT_ID_REQUIRED' => 'Falta tenant_id para ejecutar la operacion ecommerce.',
            'STORE_ID_REQUIRED' => 'Falta store_id para ejecutar la operacion ecommerce.',
            'STORE_NAME_REQUIRED' => 'Falta store_name para crear la tienda ecommerce.',
            'PLATFORM_REQUIRED' => 'Falta platform para crear la tienda ecommerce.',
            'CREDENTIAL_TYPE_REQUIRED' => 'Falta credential_type para registrar credenciales ecommerce.',
            'SYNC_TYPE_REQUIRED' => 'Falta sync_type para crear el sync job ecommerce.',
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
}
