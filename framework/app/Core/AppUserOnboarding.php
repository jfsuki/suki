<?php
declare(strict_types=1);

// app/Core/AppUserOnboarding.php

namespace App\Core;

/**
 * AppUserOnboarding
 *
 * Gestiona la entrevista inicial de 4 pasos para usuarios del App Chat.
 * Diseño LLM-driven: PHP detecta el paso actual y extrae la respuesta del
 * usuario; el LLM genera las preguntas de forma natural usando el contexto
 * del paso inyectado en el system prompt.
 *
 * Sin strings de preguntas hardcodeadas en PHP.
 * Toda la configuración (goals, hints, extraction rules) viene de:
 *   framework/contracts/agents/user_onboarding_steps.json
 *
 * Flujo por turno:
 *   1. Gatekeeper llama processAnswerAndGetContext(tenantId, userId, userMessage)
 *   2. PHP guarda la respuesta del turno anterior (si aplica)
 *   3. Retorna el bloque de contexto para inyectar en system prompt, o null si terminó
 *   4. buildSystemPrompt() inyecta ese contexto (Capa 2G)
 *   5. LLM genera la pregunta naturalmente sin strings PHP fijos
 */
final class AppUserOnboarding
{
    private UserProfileService $profileSvc;
    private ?array $stepsConfig = null;
    private string $contractPath;

    public function __construct(UserProfileService $profileSvc)
    {
        $this->profileSvc   = $profileSvc;
        $this->contractPath = dirname(__DIR__, 3) . '/contracts/agents/user_onboarding_steps.json';
    }

    /**
     * Returns true when onboarding is complete for this user.
     */
    public function isComplete(string $tenantId, string $userId, string $world = 'app'): bool
    {
        return $this->profileSvc->hasCompletedOnboarding($tenantId, $userId, $world);
    }

    /**
     * Core method — call once per turn from the Gatekeeper.
     *
     * - Extracts and persists the answer from $userMessage for the previous step.
     * - Determines the next unanswered step.
     * - Returns a context string for system prompt injection (Capa 2G), or null when complete.
     *
     * Returning null means onboarding just finished → continue to normal LLM flow.
     * Returning a string means still in progress → inject into system prompt, continue to LLM.
     */
    public function processAnswerAndGetContext(
        string $tenantId,
        string $userId,
        string $userMessage
    ): ?string {
        $profile = $this->profileSvc->load($tenantId, $userId, 'app');

        // Save the answer from the current user message (if there's a step waiting)
        $activeStep = $this->resolveCurrentStep($profile);
        if ($activeStep !== null && $userMessage !== '') {
            $this->saveStepAnswer($tenantId, $userId, $activeStep, $userMessage, $profile);
            $profile    = $this->profileSvc->load($tenantId, $userId, 'app');
            $activeStep = $this->resolveCurrentStep($profile);
        }

        // All steps done
        if ($activeStep === null) {
            $this->profileSvc->markOnboardingComplete($tenantId, $userId, 'app');
            return null;
        }

        return $this->buildStepContextForPrompt($activeStep, $profile);
    }

    /**
     * Build the system prompt context block for the given step.
     * This is what gets injected into the LLM system prompt (Capa 2G).
     */
    public function buildStepContextForPrompt(string $stepKey, array $profile = []): string
    {
        $steps  = $this->loadStepsConfig();
        $step   = null;
        foreach ($steps['steps'] ?? [] as $s) {
            if (($s['key'] ?? '') === $stepKey) {
                $step = $s;
                break;
            }
        }
        if ($step === null) {
            return '';
        }

        $goal = $step['goal'] ?? '';
        $hint = $step['llm_hint'] ?? '';

        // Include already-known facts so the LLM can reference them
        $knownFacts = [];
        if (!empty($profile['display_name'])) {
            $knownFacts[] = "nombre del usuario: {$profile['display_name']}";
        }
        if (!empty($profile['role_label'])) {
            $knownFacts[] = "cargo: {$profile['role_label']}";
        }

        $lines = [
            '## Entrevista de Bienvenida — Paso Activo',
            '',
            "**Objetivo de este turno**: {$goal}",
            "**Instrucción para el LLM**: {$hint}",
        ];

        if ($knownFacts !== []) {
            $lines[] = '**Ya sabemos**: ' . implode(', ', $knownFacts) . '.';
        }

        $lines[] = '';
        $lines[] = 'REGLA: Haz UNA sola pregunta. No menciones "perfil", "sistema" ni "datos". '
                 . 'No hagas múltiples preguntas a la vez. Sé natural y breve.';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** Returns the first unanswered step key, or null when all are done. */
    private function resolveCurrentStep(array $profile): ?string
    {
        $steps = $this->loadStepsConfig();
        foreach ($steps['steps'] ?? [] as $step) {
            $key = $step['key'] ?? '';
            if ($key === '') {
                continue;
            }
            if (!$this->hasAnswerForStep($profile, $key)) {
                return $key;
            }
        }
        return null;
    }

    private function hasAnswerForStep(array $profile, string $step): bool
    {
        return match ($step) {
            'name'           => !empty($profile['display_name']),
            'role'           => !empty($profile['role_label']),
            'frequent_tasks' => !empty($profile['frequent_tasks']),
            'tech_level'     => !empty($profile['tech_level']) && $profile['tech_level'] !== 'basic',
            default          => false,
        };
    }

    private function saveStepAnswer(
        string $tenantId,
        string $userId,
        string $step,
        string $answer,
        array  $currentProfile
    ): void {
        $steps      = $this->loadStepsConfig();
        $stepConfig = null;
        foreach ($steps['steps'] ?? [] as $s) {
            if (($s['key'] ?? '') === $step) {
                $stepConfig = $s;
                break;
            }
        }

        $extraction = $stepConfig['extraction'] ?? 'text_trim';
        $fields     = match ($step) {
            'name'  => ['display_name' => $this->extractTextTrim($answer, (int) ($stepConfig['max_length'] ?? 128))],
            'role'  => ['role_label'   => $this->extractRole($answer, $stepConfig['role_normalize_map'] ?? [])],
            'frequent_tasks' => ['frequent_tasks' => $this->extractFrequentTasks($answer, $stepConfig['task_keywords'] ?? [])],
            'tech_level'     => ['tech_level'     => $this->extractTechLevel($answer, $stepConfig['level_patterns'] ?? [])],
            default          => [],
        };

        if (!empty($fields)) {
            $this->profileSvc->upsert($tenantId, $userId, 'app', $fields);
        }
    }

    // ---- Extraction methods (PHP validates, LLM does not) -------------------

    private function extractTextTrim(string $answer, int $maxLen = 128): string
    {
        return mb_substr(trim($answer), 0, $maxLen);
    }

    private function extractRole(string $answer, array $normalizeMap): string
    {
        $lower = mb_strtolower(trim($answer));
        foreach ($normalizeMap as $pattern => $canonical) {
            if (str_contains($lower, $pattern)) {
                return $canonical;
            }
        }
        // Fallback: capitalize first word
        return ucfirst(mb_substr(trim($answer), 0, 64));
    }

    private function extractFrequentTasks(string $answer, array $taskKeywords): array
    {
        $lower = mb_strtolower($answer);
        $found = [];
        foreach ($taskKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                // Normalize to canonical form (first word, trim)
                $canonical = strtolower(trim($kw));
                if (!in_array($canonical, $found, true)) {
                    $found[] = $canonical;
                }
            }
        }
        if ($found !== []) {
            return array_slice($found, 0, 8);
        }
        // No keyword match — split by comma/newline and keep raw answer
        $parts = preg_split('/[\n,;]+/u', $answer) ?: [];
        $tasks = [];
        foreach ($parts as $p) {
            $clean = mb_substr(trim($p), 0, 80);
            if ($clean !== '') {
                $tasks[] = $clean;
            }
        }
        return array_slice($tasks ?: [trim($answer)], 0, 8);
    }

    private function extractTechLevel(string $answer, array $levelPatterns): string
    {
        $lower = mb_strtolower(trim($answer));
        foreach (['advanced', 'intermediate', 'basic'] as $level) {
            $patterns = $levelPatterns[$level] ?? [];
            foreach ($patterns as $p) {
                if (str_contains($lower, $p)) {
                    return $level;
                }
            }
        }
        return 'basic'; // Safe default
    }

    // ---- Config loader -------------------------------------------------------

    private function loadStepsConfig(): array
    {
        if ($this->stepsConfig !== null) {
            return $this->stepsConfig;
        }
        if (file_exists($this->contractPath)) {
            $raw = file_get_contents($this->contractPath);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $this->stepsConfig = $decoded;
                return $this->stepsConfig;
            }
        }
        // Emergency fallback — minimal config without any hardcoded question strings
        $this->stepsConfig = [
            'steps' => [
                ['key' => 'name',           'field' => 'display_name', 'goal' => 'Conocer el nombre del usuario.',          'llm_hint' => 'Pregunta el nombre de forma natural.', 'extraction' => 'text_trim', 'max_length' => 128],
                ['key' => 'role',           'field' => 'role_label',   'goal' => 'Conocer el cargo en la empresa.',         'llm_hint' => 'Pregunta el cargo de forma natural.', 'extraction' => 'text_trim', 'max_length' => 64],
                ['key' => 'frequent_tasks', 'field' => 'frequent_tasks','goal' => 'Conocer tareas frecuentes.',             'llm_hint' => 'Pregunta qué hará más seguido.', 'extraction' => 'keyword_array', 'task_keywords' => []],
                ['key' => 'tech_level',     'field' => 'tech_level',   'goal' => 'Conocer nivel de comodidad tecnológica.', 'llm_hint' => 'Pregunta sobre comodidad con sistemas digitales.', 'extraction' => 'tech_level_enum', 'level_patterns' => []],
            ],
        ];
        return $this->stepsConfig;
    }
}
