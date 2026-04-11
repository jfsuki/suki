<?php
declare(strict_types=1);
// app/Core/Agents/Memory/MemoryWindow.php

namespace App\Core\Agents\Memory;

use App\Core\Agents\Memory\TokenBudgeter;

/**
 * MemoryWindow
 * Inspirado en CrewAI / AutoGen:
 * Rompe la mala práctica de enviarle el historial completo al LLM.
 * Divide la memoria en:
 * 1. Long-Term Profile (hechos confirmados, ej: 'business_type: restaurant').
 * 2. Short-Term History (los últimos N turnos de conversación literal).
 */
class MemoryWindow
{
    private array $shortTermHistory = [];
    private array $longTermProfile = [];
    private array $journal = [];
    private int $maxTurns;

    /**
     * @param int $maxTurns Cantidad de intercambios recientes a recordar (1 turno = user + assistant).
     */
    public function __construct(int $maxTurns = 3)
    {
        $this->maxTurns = max(1, $maxTurns);
    }

    /**
     * Carga el estado histórico y el perfil desde la persistencia de SUKI.
     * Extrae inteligentemente qué es perfil a largo plazo y qué es charla reciente.
     */
    public function hydrateFromState(array $state, array $profile = [], array $journal = []): void
    {
        $this->journal = $journal;
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

        // Extraer los últimos N turnos de la bitácora "history_log" (si existe)
        $historyLog = is_array($state['history_log'] ?? null) ? $state['history_log'] : [];
        if (!empty($historyLog)) {
            $sliceLength = $this->maxTurns * 2;
            $rawSlice = array_slice($historyLog, -$sliceLength);
            
            // NORMALIZACIÓN: Convertir formatos heterogéneos (SQL, JSON, Neuron) 
            // a un formato interno consistente {role, content}
            $this->shortTermHistory = array_map(function($m) {
                $role = $m['role'] ?? $m['dir'] ?? $m['direction'] ?? 'user';
                $content = $m['content'] ?? $m['text'] ?? $m['msg'] ?? $m['message'] ?? '';
                
                return [
                    'role' => (strtolower($role) === 'out' || strtolower($role) === 'assistant') ? 'assistant' : 'user',
                    'content' => $content
                ];
            }, $rawSlice);
        } else {
            $this->shortTermHistory = [];
        }
    }

    /**
     * Agrega un nuevo mensaje a la ventana a corto plazo de forma manual
     */
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
     * Compila el payload comprimido que se inyectará en el prompt final del LLM.
     * Utiliza el TokenBudgeter para garantizar que, incluso la memoria a corto plazo,
     * no reviente el presupuesto (ej. si el usuario pegó 3 biblias en los últimos 3 mensajes).
     *
     * @param TokenBudgeter $budgeter
     * @param int $maxHistoryTokens Presupuesto estricto SOLO para el historial.
     * @return array
     */
    public function compileLlmContext(TokenBudgeter $budgeter, int $maxHistoryTokens = 500): array
    {
        // 1. Facts & Journal
        $facts = $this->getLongTermFacts();
        $agenda = $this->journal['summary'] ?? '';
        $pendingTasks = array_keys(array_filter($this->journal['tasks'] ?? [], fn($t) => ($t['status'] ?? '') === 'pending'));
        
        if ($agenda !== '') {
            $facts['agenda_context'] = $agenda;
        }
        if (!empty($pendingTasks)) {
            $facts['pending_objectives'] = implode(', ', $pendingTasks);
        }

        // Limpiamos nulos o vacíos para ahorrar tokens
        $facts = array_filter($facts, fn($val) => $val !== null && $val !== '' && $val !== []);

        // 2. Historial (se procesa y se cuenta)
        $compiledHistory = [];
        $currentTokens = 0;

        // Iteramos de más reciente a más antiguo para priorizar lo último
        $reversedHistory = array_reverse($this->shortTermHistory);
        foreach ($reversedHistory as $msg) {
            $role = strtoupper($msg['role'] ?? 'USER');
            $text = $msg['content'] ?? '';
            
            $line = "$role: $text";
            $lineTokens = $budgeter->estimate($line);
            
            if ($currentTokens + $lineTokens > $maxHistoryTokens) {
                $remainder = $maxHistoryTokens - $currentTokens;
                if ($remainder > 10) {
                    $cropped = $budgeter->cropText($text, $remainder - 5, 'end');
                    $compiledHistory[] = "$role: $cropped";
                }
                break;
            }

            $compiledHistory[] = $line;
            $currentTokens += $lineTokens;
        }

        // Volvemos al orden cronológico correcto
        $compiledHistory = array_reverse($compiledHistory);

        return [
            'long_term_facts' => $facts,
            'recent_history' => implode("\n", $compiledHistory),
            'active_task' => $this->longTermProfile['active_task'] ?? 'none',
            'agent_agenda' => $agenda
        ];
    }
}
