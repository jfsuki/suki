<?php
// framework/app/Core/LearningCenterCommandHandler.php

namespace App\Core;

use RuntimeException;

final class LearningCenterCommandHandler implements CommandHandlerInterface
{
    private ?ImprovementMemoryRepository $repository = null;

    public function supports(string $commandName): bool
    {
        return str_starts_with($commandName, 'LearningCenter:');
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $tenantId = (string) ($context['tenant_id'] ?? getenv('TENANT_ID') ?? 'default');
        $commandName = (string) ($command['command'] ?? '');

        $repo = $this->repository();
        
        switch ($commandName) {
            case 'LearningCenter:List':
                return $this->handleList($repo, $tenantId, $reply, $context);
            case 'LearningCenter:Approve':
                return $this->handleReview($repo, $tenantId, $command, 'approved', $reply, $context);
            case 'LearningCenter:Reject':
                return $this->handleReview($repo, $tenantId, $command, 'rejected', $reply, $context);
            case 'LearningCenter:Status':
                return $this->handleStatus($repo, $tenantId, $reply, $context);
        }

        return $reply('Comando de Learning Center no reconocido.', (string)($context['channel'] ?? 'local'), (string)($context['session_id'] ?? 'sess'), (string)($context['user_id'] ?? 'anon'), 'error');
    }

    private function handleList(ImprovementMemoryRepository $repo, string $tenantId, callable $reply, array $context): array
    {
        $candidates = $repo->listLearningCandidates($tenantId, 'pending', 5);
        if (empty($candidates)) {
            return $reply("No hay candidatos de aprendizaje pendientes de revisión en este momento. ¡SUKI está al día!", (string)($context['channel'] ?? 'local'), (string)($context['session_id'] ?? 'sess'), (string)($context['user_id'] ?? 'anon'), 'success');
        }

        $text = "### Centro de Aprendizaje: Candidatos Pendientes\n";
        $text .= "He detectado situaciones donde puedo mejorar. ¿Me ayudas a confirmar?\n\n";

        foreach ($candidates as $c) {
            $text .= "- **ID: {$c['candidate_id']}**\n";
            $text .= "  - **Módulo:** {$c['module']}\n";
            $text .= "  - **Hallazgo:** {$c['description']}\n";
            $text .= "  - **Confianza:** " . round(($c['confidence'] ?? 0) * 100) . "%\n";
            $text .= "  - *Acción:* Responde 'Aprobar aprendizaje {$c['candidate_id']}'\n\n";
        }

        return $reply($text, (string)($context['channel'] ?? 'local'), (string)($context['session_id'] ?? 'sess'), (string)($context['user_id'] ?? 'anon'), 'success', ['candidates' => $candidates]);
    }

    private function handleReview(ImprovementMemoryRepository $repo, string $tenantId, array $command, string $status, callable $reply, array $context): array
    {
        $id = (string)($command['candidate_id'] ?? $command['id'] ?? '');
        if ($id === '') {
            return $reply("Necesito el ID del candidato para procesar la revisión.", (string)($context['channel'] ?? 'local'), (string)($context['session_id'] ?? 'sess'), (string)($context['user_id'] ?? 'anon'), 'error');
        }

        $candidate = $repo->findLearningCandidate($tenantId, $id);
        if (!$candidate) {
            return $reply("No encontré ningún candidato con el ID: $id", (string)($context['channel'] ?? 'local'), (string)($context['session_id'] ?? 'sess'), (string)($context['user_id'] ?? 'anon'), 'error');
        }

        $repo->upsertLearningCandidate([
            'tenant_id' => $tenantId,
            'candidate_id' => $id,
            'review_status' => $status,
            'source_metric' => $candidate['source_metric'],
            'module' => $candidate['module'],
            'description' => $candidate['description']
        ]);

        $msg = $status === 'approved' 
            ? "¡Excelente! He aprobado el aprendizaje **$id**. Lo integraré en mi base de conocimiento pronto."
            : "Entendido. He descartado el candidato **$id**.";

        return $reply($msg, (string)($context['channel'] ?? 'local'), (string)($context['session_id'] ?? 'sess'), (string)($context['user_id'] ?? 'anon'), 'success');
    }

    private function handleStatus(ImprovementMemoryRepository $repo, string $tenantId, callable $reply, array $context): array
    {
        $agg = $repo->aggregate($tenantId);
        $totals = $agg['totals'] ?? [];
        
        $text = "### Estado del Motor de Aprendizaje (Learning Center)\n";
        $text .= "- **Candidatos Pendientes:** " . ($totals['pending_candidates'] ?? 0) . "\n";
        $text .= "- **Mejoras Aprobadas (en cola):** " . ($totals['approved_candidates'] ?? 0) . "\n";
        $text .= "- **Problemas Detectados (30 días):** " . ($totals['improvements'] ?? 0) . "\n";
        
        return $reply($text, (string)($context['channel'] ?? 'local'), (string)($context['session_id'] ?? 'sess'), (string)($context['user_id'] ?? 'anon'), 'success', ['aggregate' => $agg]);
    }

    private function repository(): ImprovementMemoryRepository
    {
        if (!$this->repository) {
            $this->repository = new ImprovementMemoryRepository();
        }
        return $this->repository;
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }
}
