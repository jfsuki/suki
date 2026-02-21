<?php
// app/Core/Agents/ConversationQualityDashboard.php

namespace App\Core\Agents;

final class ConversationQualityDashboard
{
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot
            ?? (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3) . '/project');
    }

    public function build(string $tenantId = 'default', int $days = 7): array
    {
        $tenantId = $tenantId !== '' ? $tenantId : 'default';
        $days = max(1, min(30, $days));
        $dir = $this->projectRoot . '/storage/tenants/' . $this->safe($tenantId) . '/telemetry';
        $files = $this->collectFiles($dir, $days);
        $rows = $this->loadRows($files);

        $total = 0;
        $unresolved = 0;
        $reprompts = 0;
        $llmHandoffs = 0;
        $executed = 0;
        $byClassification = [];
        $unresolvedMessages = [];
        $sessionAskStreak = [];

        foreach ($rows as $row) {
            $total++;
            $classification = (string) ($row['classification'] ?? 'unknown');
            $action = strtolower((string) ($row['action'] ?? ''));
            $session = (string) ($row['session_id'] ?? $row['user_id'] ?? 'anon');
            $message = trim((string) ($row['message'] ?? ''));
            $resolvedLocally = !empty($row['resolved_locally']);

            $byClassification[$classification] = (int) ($byClassification[$classification] ?? 0) + 1;

            if ($action === 'execute_command') {
                $executed++;
            }

            if (!$resolvedLocally || !empty($row['provider_used'])) {
                $llmHandoffs++;
            }

            $isUnresolved = false;
            if ($action === 'ask_user') {
                $isUnresolved = true;
                $sessionAskStreak[$session] = (int) ($sessionAskStreak[$session] ?? 0) + 1;
                if ($sessionAskStreak[$session] > 1) {
                    $reprompts++;
                }
            } else {
                $sessionAskStreak[$session] = 0;
            }

            if ($classification === 'builder_fallback' || $classification === 'question_local') {
                $isUnresolved = true;
            }
            if ((string) ($row['status'] ?? '') === 'error') {
                $isUnresolved = true;
            }

            if ($isUnresolved) {
                $unresolved++;
                if ($message !== '') {
                    if (!isset($unresolvedMessages[$message])) {
                        $unresolvedMessages[$message] = ['count' => 0, 'last_ts' => 0];
                    }
                    $unresolvedMessages[$message]['count']++;
                    $unresolvedMessages[$message]['last_ts'] = max(
                        (int) $unresolvedMessages[$message]['last_ts'],
                        (int) ($row['ts'] ?? 0)
                    );
                }
            }
        }

        arsort($byClassification);
        uasort(
            $unresolvedMessages,
            static fn(array $a, array $b): int => ((int) $b['count']) <=> ((int) $a['count'])
        );

        $topUnresolved = [];
        foreach (array_slice($unresolvedMessages, 0, 10, true) as $message => $meta) {
            $topUnresolved[] = [
                'message' => $message,
                'count' => (int) ($meta['count'] ?? 0),
                'last_ts' => (int) ($meta['last_ts'] ?? 0),
            ];
        }

        $successRate = $total > 0 ? round((($total - $unresolved) / $total) * 100, 2) : 0.0;

        return [
            'tenant_id' => $tenantId,
            'days' => $days,
            'summary' => [
                'turns' => $total,
                'unresolved_intents' => $unresolved,
                'reprompts' => $reprompts,
                'executed_commands' => $executed,
                'llm_handoffs' => $llmHandoffs,
                'success_rate' => $successRate,
            ],
            'by_classification' => $byClassification,
            'unresolved_top' => $topUnresolved,
            'sources' => $files,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function collectFiles(string $dir, int $days): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime('-' . $i . ' day'));
            $path = $dir . '/' . $date . '.log.jsonl';
            if (is_file($path)) {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * @param array<int, string> $files
     * @return array<int, array<string, mixed>>
     */
    private function loadRows(array $files): array
    {
        $rows = [];
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $decoded = json_decode((string) $line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        }
        return $rows;
    }

    private function safe(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $value) ?? 'default';
        return trim($value, '_');
    }
}

