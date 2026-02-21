<?php
// app/Core/IntegrationAdapterFactory.php

namespace App\Core;

final class IntegrationAdapterFactory
{
    public function make(array $integration): IntegrationAdapterInterface
    {
        $provider = strtolower((string) ($integration['provider'] ?? ''));
        $type = strtolower((string) ($integration['type'] ?? ''));

        if (str_contains($provider, 'alanube')) {
            return new AlanubeIntegrationAdapter();
        }
        if (str_contains($provider, 'alegra')) {
            return new AlegraIntegrationAdapter();
        }
        if ($type === 'e-invoicing' && str_contains($provider, 'dian')) {
            return new GenericIntegrationAdapter();
        }

        return new GenericIntegrationAdapter();
    }
}

