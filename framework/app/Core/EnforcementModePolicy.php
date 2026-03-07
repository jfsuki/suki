<?php
// app/Core/EnforcementModePolicy.php

namespace App\Core;

final class EnforcementModePolicy
{
    /**
     * @return array{mode:string,source:string,app_env:string}
     */
    public static function resolve(?string $appEnv = null, ?string $enforcementMode = null): array
    {
        $normalizedEnv = self::normalizeEnv($appEnv);
        $defaultByEnv = self::defaultModeByEnv($normalizedEnv);

        $rawMode = trim((string) ($enforcementMode ?? ''));
        if ($rawMode !== '') {
            $normalizedMode = self::normalizeMode($rawMode);
            if ($normalizedMode !== null) {
                return [
                    'mode' => $normalizedMode,
                    'source' => 'env_override',
                    'app_env' => $normalizedEnv,
                ];
            }

            return [
                'mode' => $defaultByEnv,
                'source' => 'invalid_override_fallback',
                'app_env' => $normalizedEnv,
            ];
        }

        return [
            'mode' => $defaultByEnv,
            'source' => 'app_env_default',
            'app_env' => $normalizedEnv,
        ];
    }

    public static function getEffectiveEnforcementMode(?string $appEnv = null, ?string $enforcementMode = null): string
    {
        $resolved = self::resolve($appEnv, $enforcementMode);
        return (string) ($resolved['mode'] ?? 'warn');
    }

    private static function normalizeEnv(?string $appEnv): string
    {
        $env = strtolower(trim((string) ($appEnv ?? '')));
        if ($env === '' || in_array($env, ['dev', 'local', 'development', 'test', 'testing'], true)) {
            return 'dev';
        }
        if ($env === 'staging') {
            return 'staging';
        }
        if (in_array($env, ['prod', 'production'], true)) {
            return 'prod';
        }
        return 'dev';
    }

    private static function defaultModeByEnv(string $normalizedEnv): string
    {
        if ($normalizedEnv === 'prod') {
            return 'strict';
        }
        if ($normalizedEnv === 'staging') {
            return 'warn';
        }
        return 'warn';
    }

    private static function normalizeMode(string $mode): ?string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['off', 'warn', 'strict'], true) ? $mode : null;
    }
}
