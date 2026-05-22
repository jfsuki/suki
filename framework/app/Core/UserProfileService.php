<?php
declare(strict_types=1);

// app/Core/UserProfileService.php

namespace App\Core;

/**
 * UserProfileService
 *
 * Manages persistent user profiles in the `user_profiles` MySQL table.
 * Each profile is scoped by (tenant_id, user_id, world) so the same user
 * can have different preferences in the app, builder, and torre worlds.
 */
final class UserProfileService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * Load a profile row. Returns [] when none exists yet.
     *
     * @param string $world  'app' | 'builder' | 'torre'
     */
    public function load(string $tenantId, string $userId, string $world = 'app'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_profiles WHERE tenant_id = ? AND user_id = ? AND world = ? LIMIT 1'
        );
        $stmt->execute([$tenantId, $userId, $world]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }
        foreach (['frequent_tasks', 'custom_prefs'] as $jsonCol) {
            if (isset($row[$jsonCol]) && is_string($row[$jsonCol])) {
                $decoded = json_decode($row[$jsonCol], true);
                $row[$jsonCol] = is_array($decoded) ? $decoded : [];
            }
        }
        return $row;
    }

    /**
     * Upsert profile fields. Only provided keys are written — never clears existing data.
     */
    public function upsert(string $tenantId, string $userId, string $world, array $fields): void
    {
        $allowed = [
            'display_name', 'role_label', 'tech_level', 'language_tone',
            'frequent_tasks', 'business_name', 'sector', 'custom_prefs',
            'onboarding_completed_at',
        ];

        $sets = [];
        $values = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $fields)) {
                continue;
            }
            $val = $fields[$col];
            if (in_array($col, ['frequent_tasks', 'custom_prefs'], true)) {
                $val = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
            }
            $sets[]   = "{$col} = ?";
            $values[] = $val;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'INSERT INTO user_profiles (tenant_id, user_id, world, ' . implode(', ', array_keys(array_intersect_key($fields, array_flip($allowed)))) . ')
                VALUES (?, ?, ?, ' . implode(', ', array_fill(0, count($sets), '?')) . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);

        // Rebuild positional params: insert values + duplicate-key values
        $insertValues = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $fields)) {
                continue;
            }
            $val = $fields[$col];
            if (in_array($col, ['frequent_tasks', 'custom_prefs'], true)) {
                $val = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
            }
            $insertValues[] = $val;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_profiles (tenant_id, user_id, world, updated_at'
            . ($sets !== [] ? ', ' . implode(', ', array_map(fn($s) => explode(' =', $s)[0], $sets)) : '')
            . ') VALUES (?, ?, ?, NOW()'
            . str_repeat(', ?', count($insertValues))
            . ') ON DUPLICATE KEY UPDATE updated_at = NOW()'
            . ($sets !== [] ? ', ' . implode(', ', $sets) : '')
        );

        $stmt->execute(array_merge([$tenantId, $userId, $world], $insertValues, $insertValues));
    }

    /**
     * Mark the profile's last_seen_at to now without touching other fields.
     */
    public function touchLastSeen(string $tenantId, string $userId, string $world = 'app'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_profiles (tenant_id, user_id, world, last_seen_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW(), updated_at = NOW()'
        );
        $stmt->execute([$tenantId, $userId, $world]);
    }

    /**
     * Mark the onboarding as completed.
     */
    public function markOnboardingComplete(string $tenantId, string $userId, string $world = 'app'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_profiles (tenant_id, user_id, world, onboarding_completed_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE onboarding_completed_at = NOW(), updated_at = NOW()'
        );
        $stmt->execute([$tenantId, $userId, $world]);
    }

    /**
     * Returns true when the user has completed onboarding for the given world.
     */
    public function hasCompletedOnboarding(string $tenantId, string $userId, string $world = 'app'): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT onboarding_completed_at FROM user_profiles
             WHERE tenant_id = ? AND user_id = ? AND world = ? LIMIT 1'
        );
        $stmt->execute([$tenantId, $userId, $world]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) && !empty($row['onboarding_completed_at']);
    }

    /**
     * Build a concise natural-language context block for injection into the system prompt.
     * Returns '' when no meaningful profile data is available.
     *
     * @param string $world  'app' | 'builder' | 'torre'
     */
    public function buildContextBlock(string $tenantId, string $userId, string $world = 'app'): string
    {
        $profile = $this->load($tenantId, $userId, $world);
        if (empty($profile)) {
            return '';
        }

        $lines = ['PERFIL DEL USUARIO:'];

        if (!empty($profile['display_name'])) {
            $lines[] = "- Nombre: {$profile['display_name']}";
        }
        if (!empty($profile['role_label'])) {
            $lines[] = "- Cargo: {$profile['role_label']}";
        }
        if (!empty($profile['business_name'])) {
            $lines[] = "- Empresa: {$profile['business_name']}";
        }
        if (!empty($profile['sector'])) {
            $lines[] = "- Sector: {$profile['sector']}";
        }

        $techLabels = ['basic' => 'básico', 'intermediate' => 'intermedio', 'advanced' => 'avanzado'];
        $techLevel  = $techLabels[$profile['tech_level'] ?? ''] ?? '';
        if ($techLevel !== '') {
            $lines[] = "- Nivel técnico: {$techLevel}";
        }

        $toneLabels = ['formal' => 'formal', 'informal' => 'informal', 'mixed' => 'mixto'];
        $tone       = $toneLabels[$profile['language_tone'] ?? ''] ?? '';
        if ($tone !== '') {
            $lines[] = "- Tono preferido: {$tone}";
        }

        $tasks = $profile['frequent_tasks'] ?? [];
        if (!empty($tasks) && is_array($tasks)) {
            $lines[] = '- Tareas frecuentes: ' . implode(', ', array_slice($tasks, 0, 5));
        }

        // Only emit the block when we have more than just the header
        if (count($lines) <= 1) {
            return '';
        }

        return implode("\n", $lines);
    }
}
