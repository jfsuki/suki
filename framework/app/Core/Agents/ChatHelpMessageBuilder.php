<?php
declare(strict_types=1);

namespace App\Core\Agents;

use App\Core\ProjectRegistry;

final class ChatHelpMessageBuilder
{
    public static function build(string $mode = 'app', string $projectId = ''): string
    {
        if ($projectId === '') {
            $projectId = (string) ($_SESSION['current_project_id'] ?? '');
        }
        if ($projectId === '') {
            $registry = new ProjectRegistry();
            $manifest = $registry->resolveProjectFromManifest();
            $projectId = (string) ($manifest['id'] ?? 'default');
        }
        if ($mode === 'builder') {
            return self::buildBuilder($projectId);
        }
        return self::buildApp($projectId);
    }

    private static function buildApp(string $projectId): string
    {
        $help = self::loadTrainingHelp();
        $graph = (new \App\Core\CapabilityGraph())->build($projectId, 'app');
        $formNames = array_values(array_filter(array_map(
            static fn(array $f): string => (string) ($f['title'] ?? $f['name'] ?? ''),
            $graph['forms'] ?? []
        )));
        $entityNames = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['name'] ?? ''),
            $graph['entities'] ?? []
        )));
        $entityLabels = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['label'] ?? $e['name'] ?? ''),
            $graph['entities'] ?? []
        )));

        $stateKey = count($entityNames) === 0 ? 'empty' : 'ready';
        $lines = [];
        $lines[] = 'Hola, soy Cami. Estoy lista para ayudarte.';
        $lines = array_merge($lines, $help['app']['intro'] ?? []);
        $lines = array_merge($lines, $help['app']['steps'][$stateKey] ?? []);
        $lines[] = 'Ejemplos rapidos:';
        $examples = self::buildCrudExamples($entityNames, $help['app']['examples'] ?? []);
        foreach ($examples as $ex) {
            $lines[] = '- ' . $ex;
        }
        $lines[] = 'Formularios activos: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 5)) : 'sin formularios');
        $lines[] = 'Entidades activas: ' . (count($entityLabels) ? implode(', ', array_slice($entityLabels, 0, 5)) : 'sin entidades');
        $question = $help['app']['next_questions'][$stateKey] ?? '';
        if ($question !== '') {
            $lines[] = $question;
        }
        $lines[] = 'Puedes enviar archivos (audio/imagen/PDF). Se procesaran cuando el OCR/voz este habilitado.';
        return implode("\n", $lines);
    }

    private static function buildBuilder(string $projectId): string
    {
        $help = self::loadTrainingHelp();
        $graph = (new \App\Core\CapabilityGraph())->build($projectId, 'builder');
        $formNames = array_values(array_filter(array_map(
            static fn(array $f): string => (string) ($f['title'] ?? $f['name'] ?? ''),
            $graph['forms'] ?? []
        )));
        $entityNames = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['name'] ?? ''),
            $graph['entities'] ?? []
        )));
        $entityLabels = array_values(array_filter(array_map(
            static fn(array $e): string => (string) ($e['label'] ?? $e['name'] ?? ''),
            $graph['entities'] ?? []
        )));

        $stateKey = count($entityNames) === 0 ? 'empty' : (count($formNames) === 0 ? 'no_forms' : 'ready');
        $lines = [];
        $lines[] = 'Estas en el modo CREADOR.';
        $lines = array_merge($lines, $help['builder']['intro'] ?? []);
        $lines = array_merge($lines, $help['builder']['steps'][$stateKey] ?? []);
        $lines[] = 'Ejemplos rapidos:';
        $examples = self::buildBuilderExamples($entityNames, $help['builder']['examples'] ?? []);
        foreach ($examples as $ex) {
            $lines[] = '- ' . $ex;
        }
        $lines[] = 'Formularios activos: ' . (count($formNames) ? implode(', ', array_slice($formNames, 0, 5)) : 'sin formularios');
        $lines[] = 'Entidades activas: ' . (count($entityLabels) ? implode(', ', array_slice($entityLabels, 0, 5)) : 'sin entidades');
        $question = $help['builder']['next_questions'][$stateKey] ?? '';
        if ($question !== '') {
            $lines[] = $question;
        }
        return implode("\n", $lines);
    }

    private static function buildCrudExamples(array $entityNames, array $fallback): array
    {
        if (empty($entityNames)) {
            return $fallback;
        }
        $entity = self::slugEntity($entityNames[0]);
        return [
            'crear ' . $entity . ' nombre=Ana',
            'listar ' . $entity,
            'actualizar ' . $entity . ' id=1 campo=valor',
            'eliminar ' . $entity . ' id=1',
        ];
    }

    private static function buildBuilderExamples(array $entityNames, array $fallback): array
    {
        if (empty($entityNames)) {
            return $fallback;
        }
        $entity = self::slugEntity($entityNames[0]);
        return [
            'crear tabla ' . $entity . ' nombre:texto',
            'crear formulario ' . $entity,
            'probar sistema',
        ];
    }

    private static function slugEntity(string $label): string
    {
        $label = mb_strtolower($label, 'UTF-8');
        $label = preg_replace('/[^a-z0-9áéíóúñü\\s_-]/u', '', $label) ?? $label;
        $label = preg_replace('/\\s+/', '_', trim($label)) ?? $label;
        return $label;
    }

    private static function loadTrainingHelp(): array
    {
        $path = APP_ROOT . '/contracts/agents/conversation_training_base.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $raw = ltrim($raw, "\xEF\xBB\xBF");
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [];
        }
        return (array) ($json['help'] ?? []);
    }
}
