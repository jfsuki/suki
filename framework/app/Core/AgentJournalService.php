<?php
// framework/app/Core/AgentJournalService.php

declare(strict_types=1);

namespace App\Core;

/**
 * Service to manage "Agent Journals" (Agenda de Apuntes).
 * Maintains a high-level summary of project status, tasks, and findings 
 * to allow rapid context recovery across chat sessions.
 */
class AgentJournalService
{
    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? dirname(__DIR__, 2) . '/storage/meta/journals';
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0777, true);
        }
    }

    /**
     * Get the journal for a specific user, project and agent role.
     */
    public function getJournal(string $tenantId, string $projectId, string $agentRole, string $sessionId = ''): array
    {
        $path = $this->getJournalPath($tenantId, $projectId, $agentRole, $sessionId);
        if (!is_file($path)) {
            return $this->getDefaultJournal($agentRole);
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : $this->getDefaultJournal($agentRole);
    }

    /**
     * Update or merge data into the journal.
     */
    public function updateJournal(string $tenantId, string $projectId, string $agentRole, array $updates, string $sessionId = ''): void
    {
        $current = $this->getJournal($tenantId, $projectId, $agentRole, $sessionId);
        $merged = array_merge($current, $updates);
        $merged['updated_at'] = date('Y-m-d H:i:s');
        
        $path = $this->getJournalPath($tenantId, $projectId, $agentRole, $sessionId);
        file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Record a specific task status in the journal.
     */
    public function recordTask(string $tenantId, string $projectId, string $agentRole, string $task, string $status = 'pending', string $sessionId = ''): void
    {
        $journal = $this->getJournal($tenantId, $projectId, $agentRole, $sessionId);
        $journal['tasks'][$task] = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $this->updateJournal($tenantId, $projectId, $agentRole, $journal, $sessionId);
    }

    /**
     * Append a categorized architectural note to the journal.
     * Category: 'arch' | 'decision' | 'requirement' | 'finding'
     */
    public function appendNote(string $tenantId, string $projectId, string $agentRole, string $note, string $category = 'arch', string $sessionId = ''): void
    {
        $journal = $this->getJournal($tenantId, $projectId, $agentRole, $sessionId);
        if (!isset($journal['notes']) || !is_array($journal['notes'])) {
            $journal['notes'] = [];
        }
        // Keep last 20 notes to avoid unbounded growth
        $journal['notes'][] = [
            'ts'       => date('Y-m-d H:i:s'),
            'category' => $category,
            'text'     => mb_substr($note, 0, 300),
        ];
        if (count($journal['notes']) > 20) {
            $journal['notes'] = array_slice($journal['notes'], -20);
        }
        // Update summary to last arch/decision note for quick display
        if (in_array($category, ['arch', 'decision'], true)) {
            $journal['summary'] = mb_substr($note, 0, 200);
        }
        $this->updateJournal($tenantId, $projectId, $agentRole, $journal, $sessionId);
    }

    /**
     * Merge LLM-extracted keywords into the journal's keywords list.
     * Keeps a rolling window of the most recent unique keywords (max 30).
     * These are already normalized by the LLM, no stopword filtering needed.
     */
    public function mergeKeywords(string $tenantId, string $projectId, string $agentRole, array $newKeywords, string $sessionId = ''): void
    {
        $journal = $this->getJournal($tenantId, $projectId, $agentRole, $sessionId);
        $existing = $journal['keywords'] ?? [];

        // Normalize: lowercase, trim, deduplicate (new ones first to prioritize recent context)
        $merged = array_values(array_unique(array_merge(
            array_map('mb_strtolower', array_map('trim', $newKeywords)),
            array_map('mb_strtolower', array_map('trim', $existing))
        )));

        // Keep the most recent 30 keywords
        $journal['keywords'] = array_slice($merged, 0, 30);
        $this->updateJournal($tenantId, $projectId, $agentRole, $journal, $sessionId);
    }

    /**
     * Get LLM-extracted keywords for this journal session.
     */
    public function getKeywords(string $tenantId, string $projectId, string $agentRole, string $sessionId = ''): array
    {
        $journal = $this->getJournal($tenantId, $projectId, $agentRole, $sessionId);
        return $journal['keywords'] ?? [];
    }

    /**
     * Build a condensed context string from the journal to inject into system prompts.
     * Uses LLM-extracted keywords (robust to typos) instead of regex stopword filtering.
     */
    public function buildContextBlock(string $tenantId, string $projectId, string $agentRole, string $sessionId = ''): string
    {
        $journal = $this->getJournal($tenantId, $projectId, $agentRole, $sessionId);
        $notes   = $journal['notes'] ?? [];

        if (empty($notes)) {
            return '';
        }

        $lines = [];
        $lines[] = "CONTEXTO DE ESTA SESIÓN (recuperado de la libreta del agente):";

        // Group by category for clarity
        $byCategory = [];
        foreach (array_slice($notes, -10) as $n) {
            $cat = $n['category'] ?? 'arch';
            $byCategory[$cat][] = $n;
        }

        // Requirements first (what the user wants)
        if (!empty($byCategory['requirement'])) {
            $lines[] = "\nREQUERIMIENTOS DEL USUARIO:";
            foreach ($byCategory['requirement'] as $n) {
                $lines[] = "  - " . $n['text'];
            }
        }

        // Decisions
        if (!empty($byCategory['decision'])) {
            $lines[] = "\nDECISIONES TOMADAS:";
            foreach ($byCategory['decision'] as $n) {
                $lines[] = "  - " . $n['text'];
            }
        }

        // Agent findings (from gateway/skills paths)
        if (!empty($byCategory['finding'])) {
            $lines[] = "\nHALLAZGOS DEL AGENTE:";
            foreach ($byCategory['finding'] as $n) {
                $lines[] = "  - " . $n['text'];
            }
        }

        // Architectural notes from LLM responses (last 3 only — avoid token bloat)
        if (!empty($byCategory['arch'])) {
            $lines[] = "\nÚLTIMAS RESPUESTAS DEL AGENTE (resumen):";
            foreach (array_slice($byCategory['arch'], -3) as $n) {
                $lines[] = "  - " . mb_substr($n['text'], 0, 180);
            }
        }

        // Pending tasks
        $tasks = $journal['tasks'] ?? [];
        $pending = [];
        foreach ($tasks as $name => $info) {
            if (($info['status'] ?? '') !== 'completed') {
                $pending[] = $name;
            }
        }
        if (!empty($pending)) {
            $lines[] = "\nPENDIENTE: " . implode(', ', array_slice($pending, 0, 5));
        }

        // LLM-extracted keywords (no stopwords, no regex — the LLM wrote these)
        $keywords = $journal['keywords'] ?? [];
        if (!empty($keywords)) {
            $lines[] = "\nENTIDADES CLAVE DEL PROYECTO: " . implode(', ', array_slice($keywords, 0, 12));
        }

        return implode("\n", $lines);
    }

    private function getJournalPath(string $tenantId, string $projectId, string $agentRole, string $sessionId = ''): string
    {
        $safeTenant = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tenantId);
        $safeProject = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $projectId);
        $safeRole = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $agentRole);
        $safeSession = $sessionId !== '' ? '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId) : '';
        
        return $this->storagePath . "/journal_{$safeTenant}_{$safeProject}_{$safeRole}{$safeSession}.json";
    }

    private function getDefaultJournal(string $agentRole): array
    {
        return [
            'agent_role'  => $agentRole,
            'summary'     => 'Conversación recién iniciada.',
            'notes'       => [],
            'keywords'    => [],  // LLM-extracted business entities (typo-free)
            'tasks'       => [],
            'findings'    => [],
            'rules'       => [],
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
    }
}
