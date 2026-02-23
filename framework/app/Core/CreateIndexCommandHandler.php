<?php
// app/Core/CreateIndexCommandHandler.php

namespace App\Core;

use RuntimeException;

final class CreateIndexCommandHandler implements CommandHandlerInterface
{
    public function supports(string $commandName): bool
    {
        return $commandName === 'CreateIndex';
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = (string) ($context['mode'] ?? 'app');
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');

        if ($mode === 'app') {
            return $reply('Estas en modo app. Usa el chat creador para optimizar estructura.', $channel, $sessionId, $userId, 'error');
        }

        $entity = $this->normalizeIdentifier((string) ($command['entity'] ?? ''));
        $field = $this->normalizeIdentifier((string) ($command['field'] ?? ''));
        if ($entity === '' || $field === '') {
            return $reply('Necesito tabla y campo para crear el indice.', $channel, $sessionId, $userId, 'error');
        }

        $entityExists = $this->contextCallable($context, 'entity_exists');
        if (!(bool) $entityExists($entity)) {
            return $reply('La tabla ' . $entity . ' no existe en este proyecto.', $channel, $sessionId, $userId, 'error');
        }

        $entities = $context['entities'] ?? null;
        $writer = $context['writer'] ?? null;
        $migrator = $context['migrator'] ?? null;
        if (!$entities instanceof EntityRegistry || !$writer instanceof ContractWriter || !$migrator instanceof EntityMigrator) {
            throw new RuntimeException('INVALID_CONTEXT');
        }

        $entityData = $entities->get($entity);
        $fields = is_array($entityData['fields'] ?? null) ? $entityData['fields'] : [];
        if (!$this->hasField($fields, $field)) {
            return $reply(
                'No encuentro el campo ' . $field . ' en la tabla ' . $entity . '.',
                $channel,
                $sessionId,
                $userId,
                'error'
            );
        }

        $unique = (bool) ($command['unique'] ?? false);
        $indexName = $this->normalizeIdentifier((string) ($command['index_name'] ?? ('idx_' . $entity . '_' . $field)));
        if ($indexName === '') {
            $indexName = 'idx_' . $entity . '_' . $field;
        }

        $extensions = is_array($entityData['extensions'] ?? null) ? $entityData['extensions'] : [];
        $indexes = is_array($extensions['indexes'] ?? null) ? $extensions['indexes'] : [];

        $already = false;
        foreach ($indexes as $index) {
            if (!is_array($index)) {
                continue;
            }
            $name = $this->normalizeIdentifier((string) ($index['name'] ?? ''));
            $indexFields = is_array($index['fields'] ?? null) ? $index['fields'] : [];
            $firstField = $this->normalizeIdentifier((string) ($indexFields[0] ?? ''));
            if ($name === $indexName || $firstField === $field) {
                $already = true;
                break;
            }
        }

        if (!$already) {
            $indexes[] = [
                'name' => $indexName,
                'fields' => [$field],
                'unique' => $unique,
                'method' => 'btree',
                'source' => 'builder_guidance',
            ];
            $extensions['indexes'] = $indexes;
            $entityData['extensions'] = $extensions;
            $writer->writeEntity($entityData);
            $migrator->migrateEntity($entityData, true);
        }

        $dbIndex = $migrator->ensureIndex($entity, $field, $unique, $indexName);
        if (!empty($dbIndex['reason']) && (string) $dbIndex['reason'] === 'field_missing') {
            return $reply(
                'No pude crear el indice en base de datos porque el campo aun no existe fisicamente.',
                $channel,
                $sessionId,
                $userId,
                'error',
                [
                    'entity' => $entity,
                    'field' => $field,
                    'index_name' => $indexName,
                    'db_index' => $dbIndex,
                ]
            );
        }

        if ($already && !empty($dbIndex['already_exists'])) {
            return $reply(
                'El indice ' . $indexName . ' ya estaba creado en ' . $entity . '.',
                $channel,
                $sessionId,
                $userId,
                'success',
                [
                    'already_exists' => true,
                    'entity' => $entity,
                    'field' => $field,
                    'index_name' => $indexName,
                    'db_index' => $dbIndex,
                ]
            );
        }

        return $reply(
            'Indice listo en ' . $entity . '.' . $field . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            [
                'entity' => $entity,
                'field' => $field,
                'index_name' => $indexName,
                'already_exists' => $already,
                'db_index' => $dbIndex,
            ]
        );
    }

    private function hasField(array $fields, string $fieldName): bool
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            if ($this->normalizeIdentifier((string) ($field['name'] ?? '')) === $fieldName) {
                return true;
            }
        }
        return false;
    }

    private function normalizeIdentifier(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]/', '_', $value) ?? '';
        $value = preg_replace('/_+/', '_', $value) ?? $value;
        return trim($value, '_');
    }

    private function replyCallable(array $context): callable
    {
        $callable = $context['reply'] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }

    private function contextCallable(array $context, string $key): callable
    {
        $callable = $context[$key] ?? null;
        if (!is_callable($callable)) {
            throw new RuntimeException('INVALID_CONTEXT');
        }
        return $callable;
    }
}
