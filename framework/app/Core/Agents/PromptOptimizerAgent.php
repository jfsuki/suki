<?php
declare(strict_types=1);

namespace App\Core\Agents;

/**
 * Optimiza el array de mensajes ANTES de enviarlo al LLM.
 * Configuración en framework/data/prompt_optimizer_config.json — cero hardcode.
 */
class PromptOptimizerAgent
{
    private static ?array $config = null;

    /**
     * Retorna el array $messages optimizado para el LLM.
     * Opera sobre el contrato estándar: [['role'=>'system','content'=>'...'], ...]
     */
    public function optimize(array $messages, string $mode, string $intent): array
    {
        $cfg = $this->loadConfig($mode);

        if (!($cfg['enabled'] ?? true)) {
            return $messages;
        }

        $messages = $this->limitHistory($messages, (int) ($cfg['max_history_messages'] ?? 6));
        $messages = $this->trimSystemPrompt($messages, (int) ($cfg['max_system_chars'] ?? 3000));
        $messages = $this->injectLanguageHint($messages, (string) ($cfg['language_hint'] ?? ''));

        return $messages;
    }

    private function limitHistory(array $messages, int $max): array
    {
        $system  = array_values(array_filter($messages, static fn($m) => ($m['role'] ?? '') === 'system'));
        $history = array_values(array_filter($messages, static fn($m) => ($m['role'] ?? '') !== 'system'));
        $history = array_slice($history, -$max);
        return array_merge($system, $history);
    }

    private function trimSystemPrompt(array $messages, int $maxChars): array
    {
        return array_map(static function (array $m) use ($maxChars): array {
            if (($m['role'] ?? '') === 'system' && strlen((string) ($m['content'] ?? '')) > $maxChars) {
                $m['content'] = substr((string) $m['content'], 0, $maxChars)
                    . "\n[contexto adicional omitido — optimización de tokens]";
            }
            return $m;
        }, $messages);
    }

    private function injectLanguageHint(array $messages, string $hint): array
    {
        if ($hint === '') {
            return $messages;
        }
        return array_map(static function (array $m) use ($hint): array {
            if (($m['role'] ?? '') === 'system') {
                $content = (string) ($m['content'] ?? '');
                if (!str_contains($content, 'es-419') && !str_contains($content, 'español')) {
                    $m['content'] = $content . "\n\n" . $hint;
                }
            }
            return $m;
        }, $messages);
    }

    /**
     * Returns true when the user message is too vague to route accurately.
     * Callers should reply with a clarification request instead of invoking the LLM.
     */
    public function needsClarification(string $userText, string $intent): bool
    {
        $cfg = $this->loadConfig('app');
        $minChars = (int) ($cfg['clarification_min_chars'] ?? 4);
        $vagueIntents = is_array($cfg['clarification_intents'] ?? null)
            ? (array) $cfg['clarification_intents']
            : ['unknown', 'out_of_scope', ''];

        $textTooShort = mb_strlen(trim($userText)) <= $minChars;
        $intentIsVague = in_array($intent, $vagueIntents, true);

        return $textTooShort && $intentIsVague;
    }

    private function loadConfig(string $mode): array
    {
        if (self::$config === null) {
            $path    = dirname(__DIR__, 3) . '/data/prompt_optimizer_config.json';
            $raw     = file_exists($path) ? (string) file_get_contents($path) : '{}';
            $decoded = json_decode($raw, true);
            self::$config = is_array($decoded) ? $decoded : [];
        }
        $overrides = is_array(self::$config['mode_overrides'][$mode] ?? null)
            ? (array) self::$config['mode_overrides'][$mode]
            : [];
        return array_merge(self::$config, $overrides);
    }
}
