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
            'ecommerce_list_stores' => $this->parseListStores($pairs, $baseCommand, $telemetry),
            'ecommerce_get_store' => $this->parseGetStore($pairs, $baseCommand, $telemetry),
            'ecommerce_create_sync_job' => $this->parseCreateSyncJob($pairs, $baseCommand, $telemetry),
            'ecommerce_list_sync_jobs' => $this->parseListSyncJobs($pairs, $baseCommand, $telemetry),
            'ecommerce_list_order_refs' => $this->parseListOrderRefs($pairs, $baseCommand, $telemetry),
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
}
