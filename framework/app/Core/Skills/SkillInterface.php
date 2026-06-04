<?php

declare(strict_types=1);

namespace App\Core\Skills;

/**
 * Common contract for all SUKI skills.
 *
 * Skills that implement this interface are auto-discoverable by DynamicSkillRegistry
 * when their "handler" field is set in docs/contracts/skills_catalog.json.
 * No changes to SkillExecutor required when adding a new skill.
 */
interface SkillInterface
{
    /**
     * Execute the skill and return a standardized result array.
     *
     * Expected keys in return:
     *   - action  (string) — 'respond_local' | 'continue_to_llm' | etc.
     *   - reply   (string) — Human-readable response text
     *   - command (array)  — Optional command payload
     *   - skill_result_status  (string) — 'ok' | 'error' | 'safe_fallback'
     *   - skill_fallback_reason (string)
     *   - skill_failed (bool)
     *   - telemetry (array)
     *
     * @param array<string, mixed> $input   Merged explicit args + action key
     * @param array<string, mixed> $context Runtime context (tenant_id, session_id, state, etc.)
     * @return array<string, mixed>
     */
    public function handle(array $input, array $context): array;

    /**
     * Declare the skill manifest so the registry can index it without instantiation.
     *
     * @return array{name: string, description: string, intent_patterns: list<string>}
     */
    public static function manifest(): array;
}
