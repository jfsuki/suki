<?php
// app/Core/CreateRelationCommandHandler.php

namespace App\Core;

use RuntimeException;

final class CreateRelationCommandHandler implements CommandHandlerInterface
{
    public function supports(string $commandName): bool
    {
        return $commandName === 'CreateRelation';
    }

    public function handle(array $command, array $context): array
    {
        $reply = $this->replyCallable($context);
        $mode = (string) ($context['mode'] ?? 'app');
        $channel = (string) ($context['channel'] ?? 'local');
        $sessionId = (string) ($context['session_id'] ?? 'sess');
        $userId = (string) ($context['user_id'] ?? 'anon');

        if ($mode === 'app') {
            return $reply('Estas en modo app. Usa el chat creador para definir relaciones.', $channel, $sessionId, $userId, 'error');
        }

        $source = $this->normalizeIdentifier((string) ($command['source_entity'] ?? ''));
        $target = $this->normalizeIdentifier((string) ($command['target_entity'] ?? ''));
        $relationType = strtolower(trim((string) ($command['relation_type'] ?? 'belongsTo')));
        if (!in_array($relationType, ['belongsto', 'hasmany', 'hasone'], true)) {
            $relationType = 'belongsto';
        }
        $relationType = match ($relationType) {
            'hasmany' => 'hasMany',
            'hasone' => 'hasOne',
            default => 'belongsTo',
        };

        if ($source === '' || $target === '') {
            return $reply('Necesito dos tablas para crear la relacion.', $channel, $sessionId, $userId, 'error');
        }
        if ($source === $target) {
            return $reply('La relacion necesita dos tablas diferentes.', $channel, $sessionId, $userId, 'error');
        }

        $entityExists = $this->contextCallable($context, 'entity_exists');
        if (!(bool) $entityExists($source) || !(bool) $entityExists($target)) {
            return $reply(
                'Para conectar tablas, ambas deben existir primero. Revisa si ya creaste ' . $source . ' y ' . $target . '.',
                $channel,
                $sessionId,
                $userId,
                'error'
            );
        }

        $entities = $context['entities'] ?? null;
        $writer = $context['writer'] ?? null;
        $migrator = $context['migrator'] ?? null;
        if (!$entities instanceof EntityRegistry || !$writer instanceof ContractWriter || !$migrator instanceof EntityMigrator) {
            throw new RuntimeException('INVALID_CONTEXT');
        }

        $targetEntity = $entities->get($target);
        $fkField = $this->normalizeIdentifier((string) ($command['fk_field'] ?? ($source . '_id')));
        if ($fkField === '') {
            $fkField = $source . '_id';
        }

        $fieldAdded = false;
        $relationAdded = false;

        $fields = is_array($targetEntity['fields'] ?? null) ? $targetEntity['fields'] : [];
        if (!$this->hasField($fields, $fkField)) {
            $fkDef = [
                'name' => $fkField,
                'type' => 'int',
                'label' => ucfirst($source) . ' ID',
                'required' => false,
                'nullable' => true,
                'source' => 'form',
                'ref' => $source,
            ];
            $fields[] = $fkDef;
            $targetEntity['fields'] = $fields;
            $fieldAdded = true;
        } else {
            $fkDef = $this->fieldByName($fields, $fkField) ?? [
                'name' => $fkField,
                'type' => 'int',
                'nullable' => true,
            ];
        }

        $relations = is_array($targetEntity['relations'] ?? null) ? $targetEntity['relations'] : [];
        if (!$this->hasRelation($relations, $source, $fkField, $relationType)) {
            $relations[] = [
                'name' => $source,
                'type' => $relationType,
                'entity' => $source,
                'fk' => $fkField,
            ];
            $targetEntity['relations'] = $relations;
            $relationAdded = true;
        }

        $migrated = null;
        $dbField = null;
        if ($fieldAdded || $relationAdded) {
            $writer->writeEntity($targetEntity);
            $migrated = $migrator->migrateEntity($targetEntity, true);
            if ($fieldAdded) {
                $dbField = $migrator->ensureField($target, $fkDef);
            }
        }

        if (!$fieldAdded && !$relationAdded) {
            return $reply(
                'La relacion entre ' . $target . ' y ' . $source . ' ya estaba configurada.',
                $channel,
                $sessionId,
                $userId,
                'success',
                [
                    'already_exists' => true,
                    'source_entity' => $source,
                    'target_entity' => $target,
                    'fk_field' => $fkField,
                ]
            );
        }

        return $reply(
            'Relacion lista: ' . $target . '.' . $fkField . ' ahora apunta a ' . $source . '.',
            $channel,
            $sessionId,
            $userId,
            'success',
            [
                'source_entity' => $source,
                'target_entity' => $target,
                'fk_field' => $fkField,
                'field_added' => $fieldAdded,
                'relation_added' => $relationAdded,
                'migration' => $migrated,
                'db_field' => $dbField,
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

    private function fieldByName(array $fields, string $fieldName): ?array
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            if ($this->normalizeIdentifier((string) ($field['name'] ?? '')) === $fieldName) {
                return $field;
            }
        }
        return null;
    }

    private function hasRelation(array $relations, string $sourceEntity, string $fkField, string $relationType): bool
    {
        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }
            $entity = $this->normalizeIdentifier((string) ($relation['entity'] ?? ''));
            $fk = $this->normalizeIdentifier((string) ($relation['fk'] ?? ''));
            $type = strtolower(trim((string) ($relation['type'] ?? '')));
            if ($entity === $sourceEntity && $fk === $fkField && $type === strtolower($relationType)) {
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
