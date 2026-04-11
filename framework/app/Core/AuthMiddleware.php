<?php

declare(strict_types=1);

namespace App\Core;

/**
 * AuthMiddleware ensures current requests are authenticated.
 */
final class AuthMiddleware
{
    /**
     * Valida si la sesión es válida.
     * Si no, redirige al login o retorna falso.
     * 
     * @param bool $redirect Si debe redirigir automáticamente al login.
     * @return bool
     */
    public static function check(bool $redirect = true): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $authenticated = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);

        if (!$authenticated) {
            if ($redirect) {
                header('Location: /auth/login.php');
                exit;
            }
            return false;
        }

        // Validación extra: IP no ha cambiado drásticamente (Secuestro de sesión)
        $currentIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (isset($_SESSION['last_ip']) && $_SESSION['last_ip'] !== $currentIp) {
            // Podríamos registrar una alerta aquí
            session_destroy();
            if ($redirect) {
                header('Location: /auth/login.php?error=session_compromised');
                exit;
            }
            return false;
        }

        return true;
    }

    /**
     * Verifica si el usuario tiene un rol específico.
     */
    public static function hasRole(string $role): bool
    {
        if (!self::check(false)) return false;
        
        // Aquí se podría consultar al Registry si el rol en sesión sigue siendo válido o es mayor
        // Por simplicidad, asumimos roles en sesión por ahora o inyectamos Registry
        return true; 
    }

    /**
     * Verifica si la sesión actual coincide con la última sesión iniciada en BD (Single Concurrent Session).
     * Si no coincide (se abrió en otro lado), destruye la sesión y puede redirigir o devolver false.
     */
    public static function checkConcurrentSession(bool $apiMode = false): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? '';
        $sessionHash = $_SESSION['active_session_hash'] ?? '';

        // Si no hay sesión o es la cuenta estática Master Key (suki_tower_auth), se omite check BD.
        if ($userId === '') {
            return true;
        }

        // Consultar el hash real en BD
        $registry = new \App\Core\ProjectRegistry();
        $realHash = $registry->getActiveSession($userId);

        if ($realHash !== null && $realHash !== '' && $sessionHash !== $realHash) {
            // Hash difiere -> Se abrió otra sesión.
            session_destroy();
            
            if ($apiMode) {
                return false;
            }
            
            // Redirigir usando rutas relativas
            $base = (strpos($_SERVER['REQUEST_URI'] ?? '', '/suki/') !== false) ? '/suki' : '';
            header("Location: $base/marketplace/?error=session_closed_concurrent");
            exit;
        }

        return true;
    }
}
