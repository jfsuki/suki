<?php
declare(strict_types=1);

namespace App\Core\Agents;

use App\Core\TokenBudgeter;

/**
 * MemoryWindow
 * Manages the balance between short-term conversation history and long-term profile facts.
 */
class MemoryWindow
{
    private array $shortTermHistory = [];
    private array $longTermProfile = [];
    private int $maxTurns;

    /**
     * @param int $maxTurns Number of turns to remember (1 turn = user + assistant). 
     * Defaults to 10 for better context retention as requested.
     */
    public function __construct(int $maxTurns = 10)
    {
        $this->maxTurns = max(1, $maxTurns);
    }

    public function hydrateFromState(array $state, array $profile = []): void
    {
        $this->longTermProfile = [
            'tenant' => $state['tenant_id'] ?? 'default',
            'project' => $state['project_id'] ?? 'default',
            'mode' => $state['mode'] ?? 'app',
            'known_facts' => array_merge(
                $state['collected'] ?? [],
                $profile['business_profile'] ?? [],
                ['sector' => $profile['sector'] ?? 'unknown']
            ),
            'active_task' => $state['active_task'] ?? 'none'
        ];

        // Hydrate history from memory log
        $historyLog = is_array($state['history_log'] ?? null) ? $state['history_log'] : [];
        if (empty($historyLog) && is_array($state['last_messages'] ?? null)) {
            $historyLog = $state['last_messages'];
        }

        if (!empty($historyLog)) {
            $sliceLength = $this->maxTurns * 2;
            $this->shortTermHistory = array_slice($historyLog, -$sliceLength);
        } else {
            $this->shortTermHistory = [];
        }
    }

    public function appendShortTerm(string $role, string $text): void
    {
        $this->shortTermHistory[] = [
            'role' => $role,
            'text' => trim($text)
        ];

        $sliceLength = $this->maxTurns * 2;
        if (count($this->shortTermHistory) > $sliceLength) {
            $this->shortTermHistory = array_slice($this->shortTermHistory, -$sliceLength);
        }
    }

    public function getShortTermHistory(): array
    {
        return $this->shortTermHistory;
    }

    public function getLongTermFacts(): array
    {
        return $this->longTermProfile['known_facts'] ?? [];
    }

    /**
     * Compiles context for LLM injection.
     */
    public function compileLlmContext(TokenBudgeter $budgeter, int $maxHistoryTokens = 1500): array
    {
        $facts = $this->getLongTermFacts();
        $facts = array_filter($facts, fn($val) => $val !== null && $val !== '' && $val !== []);

        $compiledHistory = [];
        $currentTokens = 0;

        $reversedHistory = array_reverse($this->shortTermHistory);
        foreach ($reversedHistory as $msg) {
            $role = strtoupper($msg['role'] ?? ($msg['u'] ? 'USER' : 'ASSISTANT'));
            $text = $msg['text'] ?? $msg['u'] ?? $msg['a'] ?? '';
            
            if (trim((string)$text) === '') continue;

            $line = "$role: $text";
            $lineTokens = $budgeter->calculate($line);

            if ($currentTokens + $lineTokens > $maxHistoryTokens) {
                $remainder = $maxHistoryTokens - $currentTokens;
                if ($remainder > 20) {
                    $cropped = $budgeter->cropText((string)$text, $remainder - 10, 'end');
                    $compiledHistory[] = "$role: $cropped";
                }
                break;
            }

            $compiledHistory[] = $line;
            $currentTokens += $lineTokens;
        }

        return [
            'long_term_facts' => $facts,
            'recent_history' => implode("\n", array_reverse($compiledHistory)),
            'active_task' => $this->longTermProfile['active_task'] ?? 'none'
        ];
    }
}
