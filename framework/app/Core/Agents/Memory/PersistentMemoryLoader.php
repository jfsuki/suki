<?php

declare(strict_types=1);

namespace App\Core\Agents\Memory;

/**
 * Loader for filesystem-based persistent autonomous memory.
 */
final class PersistentMemoryLoader
{
    private string $memoryDir;

    public function __construct(?string $memoryDir = null)
    {
        $this->memoryDir = $memoryDir ?? dirname(__DIR__, 5) . '/.suki/memory';
    }

    /**
     * Compiles all memory files into a string for LLM context.
     */
    public function loadAll(): string
    {
        if (!is_dir($this->memoryDir)) {
            return '';
        }

        $files = [
            'LEARNED.md' => '### Autonomous Learnings & Rules',
            'PROJECT_CONTEXT.md' => '### Project Technical Context',
            'USER_PREFERENCES.md' => '### User Interaction Preferences',
        ];

        $output = "## PERSISTENT AGENT MEMORY (Source of Truth)\n";
        $hasContent = false;

        foreach ($files as $file => $header) {
            $path = $this->memoryDir . '/' . $file;
            if (is_file($path)) {
                $content = trim(file_get_contents($path));
                if (!empty($content)) {
                    $output .= "\n" . $header . "\n" . $content . "\n";
                    $hasContent = true;
                }
            }
        }

        return $hasContent ? $output : '';
    }
}
