<?php
// app/Core/WebhookSecurityPolicy.php

namespace App\Core;

final class WebhookSecurityPolicy
{
    private string $appEnv;
    private bool $allowInsecureWebhooks;

    public function __construct(?string $appEnv = null, ?string $allowInsecureWebhooks = null)
    {
        $rawEnv = trim((string) ($appEnv ?? getenv('APP_ENV') ?: 'dev'));
        $this->appEnv = $this->normalizeAppEnv($rawEnv);

        $rawAllow = trim((string) ($allowInsecureWebhooks ?? getenv('ALLOW_INSECURE_WEBHOOKS') ?: '0'));
        $this->allowInsecureWebhooks = $this->isTruthy($rawAllow);
    }

    public function appEnv(): string
    {
        return $this->appEnv;
    }

    public function requireSecretInNonDev(): bool
    {
        return true;
    }

    public function shouldRequireSecret(): bool
    {
        if ($this->isDevEnv()) {
            return !$this->allowInsecureWebhooks;
        }

        return $this->requireSecretInNonDev();
    }

    public function allowsInsecureInDev(): bool
    {
        return $this->isDevEnv() && $this->allowInsecureWebhooks;
    }

    private function isDevEnv(): bool
    {
        return $this->appEnv === 'dev';
    }

    private function normalizeAppEnv(string $env): string
    {
        $env = strtolower(trim($env));
        if ($env === '' || in_array($env, ['dev', 'local', 'development', 'test'], true)) {
            return 'dev';
        }
        if (in_array($env, ['prod', 'production'], true)) {
            return 'prod';
        }
        if ($env === 'staging') {
            return 'staging';
        }
        return $env;
    }

    private function isTruthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
