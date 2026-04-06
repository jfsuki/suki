<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Parser para comandos de memoria interna (DSL/Legacy bridge).
 */
final class InternalMemoryMessageParser
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function parse(string $skillName, array $context): array
    {
        $message = trim((string) ($context['message_text'] ?? ''));
        $pairs = $this->extractKeyValuePairs($message);
        
        $content = $pairs['content'] ?? $pairs['contenido'] ?? '';
        $memoryType = $pairs['memory_type'] ?? $pairs['tipo'] ?? 'learned';
        $updateMode = $pairs['update_mode'] ?? $pairs['modo'] ?? 'append';

        if (empty($content)) {
            return [
                'kind' => 'ask_user',
                'reply' => 'Necesito el contenido (content="...") que deseas guardar en la memoria.',
                'telemetry' => ['module_used' => 'internal_memory']
            ];
        }

        return [
            'kind' => 'command',
            'command' => [
                'command' => 'UpdateInternalMemory',
                'memory_type' => $memoryType,
                'content' => $content,
                'update_mode' => $updateMode,
                'tenant_id' => $context['tenant_id'] ?? 'default',
            ],
            'telemetry' => [
                'module_used' => 'internal_memory',
                'memory_type' => $memoryType
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractKeyValuePairs(string $message): array
    {
        $pairs = [];
        preg_match_all('/([a-zA-Z_]+)=("([^"]*)"|\'([^\']*)\'|([^\\s]+))/u', $message, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = strtolower(trim((string) ($match[1] ?? '')));
            $value = '';
            foreach ([3, 4, 5] as $index) {
                if (isset($match[$index]) && $match[$index] !== '') {
                    $value = trim((string) $match[$index]);
                    break;
                }
            }
            if ($key !== '' && $value !== '') {
                $pairs[$key] = $value;
            }
        }

        return $pairs;
    }
}
