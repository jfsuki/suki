<?php
declare(strict_types=1);

namespace App\Core\Agents;

use App\Core\Agents\AcidChatRunner;
use App\Core\CommandLayer;
use App\Core\ConversationManagerService;
use App\Core\ContractWriter;
use App\Core\EntityBuilder;
use App\Core\EntityMigrator;
use App\Core\EntityRegistry;
use App\Core\FormWizard;
use App\Core\ProjectRegistry;

final class LocalCommandExecutor
{
    /**
     * Execute a parsed local command.
     *
     * @param callable $reply           fn(string $msg, string $ch, string $sid, string $uid, string $status='ok', array $data=[]): array
     * @param callable $llmUsageSummary fn(string $tenantId): array{reply:string, data:array}
     */
    public function execute(
        array $parsed,
        string $channel,
        string $sessionId,
        string $userId,
        string $mode,
        string $tenantId,
        callable $reply,
        callable $llmUsageSummary
    ): array {
        $cmd = $parsed['command'] ?? '';

        if ($cmd === 'RunTests') {
            $runner = new UnitTestRunner();
            $result = $runner->run();
            $summary = $result['summary'];
            $warns = array_filter($result['tests'], fn($t) => $t['status'] === 'warn');
            $fails = array_filter($result['tests'], fn($t) => $t['status'] === 'fail');
            $warnList = $warns ? implode(', ', array_map(fn($t) => $t['name'], $warns)) : '';
            $failList = $fails ? implode(', ', array_map(fn($t) => $t['name'], $fails)) : '';
            $msg = "Pruebas: {$summary['passed']} ok, {$summary['warned']} warn, {$summary['failed']} fail.";
            if ($warnList !== '') { $msg .= " Warn: {$warnList}."; }
            if ($failList !== '') { $msg .= " Fail: {$failList}."; }
            $acid = null;
            try {
                $acidRunner = new AcidChatRunner();
                $acid = $acidRunner->run($tenantId ?: 'default', ['save' => true]);
                $acidSummary = $acid['summary'] ?? [];
                $msg .= " Chat ácido: " . ($acidSummary['passed'] ?? 0) . " ok, " . ($acidSummary['failed'] ?? 0) . " fail.";
            } catch (\Throwable $e) {
                $msg .= " Chat ácido: error al ejecutar.";
            }
            return $reply($msg, $channel, $sessionId, $userId, 'success', ['unit' => $result, 'acid' => $acid]);
        }

        if ($cmd === 'ListSessions') {
            $conv = new ConversationManagerService();
            $history = $conv->getMyHistory($userId);
            if (empty($history)) {
                return $reply('Aún no tienes conversaciones guardadas.', $channel, $sessionId, $userId);
            }
            $list = "Tus conversaciones recientes:\n";
            foreach ($history as $h) {
                $list .= "- [" . $h['session_id'] . "] " . ($h['title'] ?: 'Sin título') . " (" . $h['last_message_at'] . ")\n";
            }
            return $reply($list, $channel, $sessionId, $userId, 'success', ['sessions' => $history]);
        }

        if ($cmd === 'NewSession') {
            $conv = new ConversationManagerService();
            $newId = $conv->startNewSubject($userId, 'default', $tenantId, $channel, $parsed['title'] ?: 'Nueva Conversación');
            return $reply('Iniciando nueva sesión: ' . ($parsed['title'] ?: 'Nueva'), $channel, $newId, $userId, 'success', ['new_session_id' => $newId]);
        }

        if ($cmd === 'OpenSession') {
            $targetId = $parsed['id'] ?? '';
            $registry = new ProjectRegistry();
            $session = $registry->getSession($targetId);
            if (!$session || $session['user_id'] !== $userId) {
                return $reply('No se encontró la conversación o no tienes acceso.', $channel, $sessionId, $userId, 'error');
            }
            return $reply('Abriendo conversación: ' . ($session['title'] ?: $targetId), $channel, $targetId, $userId, 'success', ['switch_to_session_id' => $targetId]);
        }

        if ($cmd === 'LLMUsage') {
            $summary = $llmUsageSummary($tenantId);
            return $reply($summary['reply'], $channel, $sessionId, $userId, 'success', $summary['data']);
        }

        if ($cmd === 'CreateEntity') {
            if ($mode === 'app') {
                return $reply('Estas en modo app. Usa el chat creador para crear tablas.', $channel, $sessionId, $userId, 'error');
            }
            $entityName = (string) ($parsed['entity'] ?? '');
            if ($entityName === '') {
                return $reply('Necesito el nombre de la tabla.', $channel, $sessionId, $userId, 'error');
            }
            if ($this->entityExists($entityName)) {
                return $reply('La tabla ' . $entityName . ' ya existe. No la voy a duplicar.', $channel, $sessionId, $userId, 'success', [
                    'entity' => ['name' => $entityName],
                    'already_exists' => true,
                ]);
            }
            $builder = new EntityBuilder();
            $writer  = new ContractWriter();
            $entity  = $builder->build($entityName, $parsed['fields'] ?? []);
            $writer->writeEntity($entity);
            try {
                $registry = new EntityRegistry();
                $migrator = new EntityMigrator($registry);
                $migrator->migrateEntity($entity, true);
            } catch (\Throwable $e) {
                $rawError = (string) $e->getMessage();
                $human = str_contains($rawError, 'SQLSTATE')
                    ? 'Tabla de contrato creada. Falta conectar correctamente la base de datos para crear la tabla fisica.'
                    : 'Tabla de contrato creada, pero no pude migrar a DB.';
                return $reply($human, $channel, $sessionId, $userId, 'warn', ['entity' => $entity, 'error' => $rawError]);
            }
            return $reply('Tabla creada: ' . $entity['name'], $channel, $sessionId, $userId, 'success', ['entity' => $entity]);
        }

        if ($cmd === 'CreateForm') {
            if ($mode === 'app') {
                return $reply('Estas en modo app. Usa el chat creador para crear formularios.', $channel, $sessionId, $userId, 'error');
            }
            $entityName = (string) ($parsed['entity'] ?? '');
            if ($entityName === '') {
                return $reply('Necesito la entidad para el formulario.', $channel, $sessionId, $userId, 'error');
            }
            if ($this->formExistsForEntity($entityName)) {
                return $reply('El formulario de ' . $entityName . ' ya existe. No lo voy a duplicar.', $channel, $sessionId, $userId, 'success', [
                    'form' => ['name' => $entityName . '.form'],
                    'already_exists' => true,
                ]);
            }
            $registry = new EntityRegistry();
            $entity   = $registry->get($entityName);
            $wizard   = new FormWizard();
            $writer   = new ContractWriter();
            $form     = $wizard->buildFromEntity($entity);
            $writer->writeForm($form);
            return $reply('Formulario creado para ' . $entityName, $channel, $sessionId, $userId, 'success', ['form' => $form]);
        }

        if (in_array($cmd, ['CreateRecord', 'QueryRecords', 'ReadRecord', 'UpdateRecord', 'DeleteRecord'], true)) {
            if ($mode === 'builder') {
                return $reply('Estas en modo creador. Usa el chat app para registrar datos.', $channel, $sessionId, $userId, 'error');
            }
            return $this->executeCrud($parsed, $channel, $sessionId, $userId, $reply);
        }

        return $reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
    }

    private function executeCrud(array $parsed, string $channel, string $sessionId, string $userId, callable $reply): array
    {
        $cmd    = $parsed['command'];
        $entity = (string) ($parsed['entity'] ?? '');
        if ($entity === '') {
            return $reply('Falta entidad.', $channel, $sessionId, $userId, 'error');
        }
        $command = new CommandLayer();
        $data    = [];
        switch ($cmd) {
            case 'CreateRecord':
                $data  = $command->createRecord($entity, $parsed['data'] ?? []);
                $msg   = 'Registro creado en ' . $entity;
                break;
            case 'QueryRecords':
                $data  = $command->queryRecords($entity, $parsed['filters'] ?? [], 20, 0);
                $msg   = 'Resultados para ' . $entity . ': ' . count($data);
                break;
            case 'ReadRecord':
                $data  = $command->readRecord($entity, $parsed['id'] ?? null, true);
                $msg   = 'Registro: ' . $entity;
                break;
            case 'UpdateRecord':
                $data  = $command->updateRecord($entity, $parsed['id'] ?? null, $parsed['data'] ?? []);
                $msg   = 'Registro actualizado en ' . $entity;
                break;
            case 'DeleteRecord':
                $data  = $command->deleteRecord($entity, $parsed['id'] ?? null);
                $msg   = 'Registro eliminado en ' . $entity;
                break;
            default:
                return $reply('Comando no soportado.', $channel, $sessionId, $userId, 'error');
        }
        return $reply($msg, $channel, $sessionId, $userId, 'success', $data);
    }

    private function entityExists(string $entity): bool
    {
        if ($entity === '') {
            return false;
        }
        try {
            (new EntityRegistry())->get($entity);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function formExistsForEntity(string $entity): bool
    {
        $entity = strtolower(trim($entity));
        if ($entity === '') {
            return false;
        }
        return is_file(PROJECT_ROOT . '/contracts/forms/' . $entity . '.form.json');
    }
}
