<?php
// app/Core/RoleContext.php

namespace App\Core;

final class RoleContext
{
    private static ?string $role = null;
    private static ?string $userId = null;
    private static ?string $userLabel = null;

    public static function setRole(?string $role): void
    {
        self::$role = $role !== null && $role !== '' ? $role : null;
    }

    public static function getRole(): string
    {
        if (self::$role) {
            return self::$role;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!empty($_SESSION['role'])) {
                return (string) $_SESSION['role'];
            }
            if (!empty($_SESSION['user_role'])) {
                return (string) $_SESSION['user_role'];
            }
        }
        $default = getenv('DEFAULT_ROLE') ?: 'admin';
        return $default;
    }

    public static function setUserId(?string $userId): void
    {
        self::$userId = $userId !== null && $userId !== '' ? $userId : null;
    }

    public static function getUserId(): ?string
    {
        if (self::$userId) {
            return self::$userId;
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
            return (string) $_SESSION['user_id'];
        }
        return null;
    }

    public static function setUserLabel(?string $label): void
    {
        self::$userLabel = $label !== null && $label !== '' ? $label : null;
    }

    public static function getUserLabel(): ?string
    {
        if (self::$userLabel) {
            return self::$userLabel;
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_name'])) {
            return (string) $_SESSION['user_name'];
        }
        return null;
    }
}
