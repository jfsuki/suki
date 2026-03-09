<?php
// app/Core/SkillRegistry.php

declare(strict_types=1);

namespace App\Core;

final class SkillRegistry
{
    /**
     * @var array<int,array<string,mixed>>
     */
    private array $skills = [];

    /**
     * @param array<string,mixed> $contract
     */
    public function __construct(array $contract)
    {
        $catalog = is_array($contract['catalog'] ?? null) ? (array) $contract['catalog'] : [];
        foreach ($catalog as $skill) {
            if (!is_array($skill)) {
                continue;
            }
            $this->skills[] = $this->normalizeSkill($skill);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->skills;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $name): ?array
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return null;
        }

        foreach ($this->skills as $skill) {
            if ((string) ($skill['name'] ?? '') === $name) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $skill
     * @return array<string,mixed>
     */
    private function normalizeSkill(array $skill): array
    {
        $memoryType = trim((string) ($skill['memory_type'] ?? ''));
        if ($memoryType !== '') {
            $memoryType = QdrantVectorStore::assertMemoryType($memoryType);
        }

        return [
            'name' => strtolower(trim((string) ($skill['name'] ?? ''))),
            'description' => trim((string) ($skill['description'] ?? '')),
            'intent_patterns' => $this->normalizeStringList($skill['intent_patterns'] ?? []),
            'keywords' => $this->normalizeStringList($skill['keywords'] ?? []),
            'execution_mode' => strtolower(trim((string) ($skill['execution_mode'] ?? 'deterministic'))),
            'allowed_tools' => $this->normalizeStringList($skill['allowed_tools'] ?? []),
            'priority' => (int) ($skill['priority'] ?? 0),
            'memory_type' => $memoryType !== '' ? $memoryType : null,
            'input_schema' => is_array($skill['input_schema'] ?? null) ? (array) $skill['input_schema'] : [],
            'channel_capabilities' => $this->normalizeStringList($skill['channel_capabilities'] ?? []),
            'context_hints' => is_array($skill['context_hints'] ?? null) ? (array) $skill['context_hints'] : [],
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item === '' || in_array($item, $result, true)) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}
