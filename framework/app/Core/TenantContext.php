<?php
// app/Core/TenantContext.php

namespace App\Core;

class TenantContext
{
    public static function getTenantId(): ?int
    {
        if (defined('TENANT_ID') && is_numeric(TENANT_ID)) {
            return (int) TENANT_ID;
        }

        $env = getenv('TENANT_ID');
        if ($env !== false && $env !== '' && is_numeric($env)) {
            return (int) $env;
        }

        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['tenant_id'])) {
            $sessionId = $_SESSION['tenant_id'];
            if (is_numeric($sessionId)) {
                return (int) $sessionId;
            }
        }

        return null;
    }
}
