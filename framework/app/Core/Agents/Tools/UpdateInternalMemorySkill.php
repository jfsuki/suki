<?php

declare(strict_types=1);

namespace App\Core\Agents\Tools;

use RuntimeException;

/**
 * Skill para actualizar la memoria interna (Aprendizajes, Contexto, Preferencias).
 */
final class UpdateInternalMemorySkill
{
    private string $memoryDir;

    public function __construct(?string $memoryDir = null)
    {
        $this->memoryDir = $memoryDir ?? dirname(__DIR__, 5) . '/.suki/memory';
        if (!is_dir($this->memoryDir)) {
            mkdir($this->memoryDir, 0755, true);
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function execute(array $args): array
    {
        $memoryType = $args['memory_type'] ?? 'learned';
        $content = $args['content'] ?? '';
        $updateMode = $args['update_mode'] ?? 'append'; // append o overwrite

        if (empty($content)) {
            return [
                'status' => 'error',
                'message' => 'Content cannot be empty.'
            ];
        }

        $filename = match ($memoryType) {
            'learned' => 'LEARNED.md',
            'project_context' => 'PROJECT_CONTEXT.md',
            'user_preferences' => 'USER_PREFERENCES.md',
            default => 'LEARNED.md',
        };

        $path = $this->memoryDir . '/' . $filename;

        try {
            if ($updateMode === 'append' && is_file($path)) {
                $existing = file_get_contents($path);
                $newContent = $existing . "\n\n---\n### Update: " . date('Y-m-d H:i:s') . "\n" . $content;
                file_put_contents($path, $newContent);
            } else {
                // Prepend header if it's a new file or overwrite
                $header = match ($memoryType) {
                    'learned' => "# Learned Lessons and Rules\n",
                    'project_context' => "# Project Context\n",
                    'user_preferences' => "# User Preferences\n",
                    default => "",
                };
                file_put_contents($path, $header . $content);
            }

            return [
                'status' => 'success',
                'message' => "Memory [{$memoryType}] updated successfully.",
                'path' => $path
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => "Failed to update memory: " . $e->getMessage()
            ];
        }
    }
}
