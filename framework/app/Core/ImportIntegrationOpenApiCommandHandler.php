<?php
// app/Core/ImportIntegrationOpenApiCommandHandler.php

namespace App\Core;

use RuntimeException;

final class ImportIntegrationOpenApiCommandHandler implements CommandHandlerInterface
{
    public function supports(string $commandName): bool
    {
        return $commandName === 'ImportIntegrationOpenApi';
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = (string) ($context['mode'] ?? 'app');
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');

        if ($mode !== 'builder') {
            return $reply('La importacion OpenAPI se hace en el Creador de apps.', $channel, $sessionId, $userId, 'error');
        }

        $importer = $context['openapi_importer'] ?? null;
        if (!$importer instanceof OpenApiIntegrationImporter) {
            throw new RuntimeException('INVALID_CONTEXT');
        }

        $options = [
            'api_name' => (string) ($command['api_name'] ?? $command['integration_id'] ?? 'api_externa'),
            'doc_url' => (string) ($command['doc_url'] ?? ''),
            'openapi_json' => (string) ($command['openapi_json'] ?? ''),
            'provider' => (string) ($command['provider'] ?? ''),
            'country' => (string) ($command['country'] ?? 'CO'),
            'environment' => (string) ($command['environment'] ?? 'sandbox'),
            'type' => (string) ($command['type'] ?? 'custom'),
            'token_env' => (string) ($command['token_env'] ?? 'INTEGRATION_API_TOKEN'),
        ];

        $dryRun = (bool) ($command['dry_run'] ?? false);
        $result = $importer->import($options, !$dryRun);
        $summary = is_array($result['summary'] ?? null) ? (array) $result['summary'] : [];
        $endpointCount = (int) ($summary['endpoint_count'] ?? 0);
        $baseUrl = (string) ($summary['base_url'] ?? '');
        $apiName = (string) ($summary['api_name'] ?? ($options['api_name'] ?? 'api_externa'));
        $path = (string) ($result['path'] ?? '');

        if ($dryRun) {
            return $reply(
                'Validacion OpenAPI lista para ' . $apiName . '. Base URL: ' . $baseUrl . '. Endpoints detectados: ' . $endpointCount . '.',
                $channel,
                $sessionId,
                $userId,
                'success',
                [
                    'summary' => $summary,
                    'contract' => $result['contract'] ?? [],
                ]
            );
        }

        return $reply(
            'Integracion importada: ' . $apiName . '. Endpoints detectados: ' . $endpointCount . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            [
                'summary' => $summary,
                'contract_path' => $path,
                'contract' => $result['contract'] ?? [],
            ]
        );
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }
}

