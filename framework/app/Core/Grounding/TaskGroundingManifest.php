<?php

declare(strict_types=1);

namespace App\Core\Grounding;

/**
 * Task Grounding Manifest — SCML Fase 1.
 *
 * Lee el catalogo de skills (docs/contracts/skills_catalog.json), filtra los que
 * tienen campo "handler", los agrupa por "topic_cluster" y genera un bloque de
 * texto para inyectar en el system prompt del LLM.
 *
 * El bloque solo aparece cuando:
 *   - el intent clasificado tiene un handler PHP real en el catalogo
 *   - la confidence supera el umbral configurado
 *
 * Si el JSON no existe o falla, degrada graciosamente (bloque vacio, sin excepcion).
 */
final class TaskGroundingManifest
{
    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $manifest = null;

    private string $catalogPath;
    private float $confidenceThreshold;

    public function __construct(?string $catalogPath = null, float $confidenceThreshold = 0.60)
    {
        $this->catalogPath = $catalogPath
            ?? dirname(__DIR__, 4) . '/docs/contracts/skills_catalog.json';
        $this->confidenceThreshold = $confidenceThreshold;
    }

    /**
     * Lee el catalogo, filtra skills con handler, agrupa por topic_cluster.
     *
     * @return array<string, list<array<string, mixed>>>  ['cluster' => [...skill entries...]]
     */
    public function build(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (!is_file($this->catalogPath)) {
            error_log('[SCML] skills_catalog.json not found at: ' . $this->catalogPath);
            $this->manifest = [];
            return $this->manifest;
        }

        $raw = file_get_contents($this->catalogPath);
        if ($raw === false || trim($raw) === '') {
            error_log('[SCML] skills_catalog.json could not be read.');
            $this->manifest = [];
            return $this->manifest;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['catalog'] ?? null)) {
            error_log('[SCML] skills_catalog.json has unexpected structure.');
            $this->manifest = [];
            return $this->manifest;
        }

        $grouped = [];
        foreach ($data['catalog'] as $skill) {
            if (!is_array($skill)) {
                continue;
            }
            $handler = (string) ($skill['handler'] ?? '');
            if ($handler === '') {
                continue;
            }
            $cluster = (string) ($skill['topic_cluster'] ?? 'general');
            if (!isset($grouped[$cluster])) {
                $grouped[$cluster] = [];
            }
            $grouped[$cluster][] = [
                'name'          => (string) ($skill['name'] ?? ''),
                'handler'       => $handler,
                'description'   => (string) ($skill['description'] ?? ''),
                'topic_cluster' => $cluster,
            ];
        }

        $this->manifest = $grouped;
        return $this->manifest;
    }

    /**
     * Renderiza el bloque de grounding para inyectar en el system prompt.
     *
     * Logica:
     *   1. intent vacio OR confidence < threshold  => needs_clarification=true
     *   2. intent no tiene handler                 => needs_clarification=false, block=''
     *      (intent conversacional — LLM responde normalmente)
     *   3. intent con handler                      => block con contexto del cluster
     *
     * @return array{needs_clarification: bool, block: string, cluster: string}
     */
    public function renderForSystemPrompt(
        string $classifiedIntent = '',
        float $confidence = 0.0,
        string $previousTopicCluster = ''
    ): array {
        $empty = ['needs_clarification' => false, 'block' => '', 'cluster' => ''];

        // Paso 1: intent desconocido o confianza insuficiente
        if ($classifiedIntent === '' || $confidence < $this->confidenceThreshold) {
            return ['needs_clarification' => true, 'block' => '', 'cluster' => ''];
        }

        $manifest = $this->build();
        if (empty($manifest)) {
            return $empty;
        }

        // Paso 2: buscar el intent en el manifest
        $entry = null;
        foreach ($manifest as $clusterSkills) {
            foreach ($clusterSkills as $skill) {
                if ($skill['name'] === $classifiedIntent) {
                    $entry = $skill;
                    break 2;
                }
            }
        }

        if ($entry === null) {
            // Intent no tiene handler — es conversacional, LLM responde normalmente
            return $empty;
        }

        $cluster = $entry['topic_cluster'];
        $handlerClass = $this->shortClassName($entry['handler']);
        $peers = $this->getClusterPeers($cluster, $classifiedIntent, 3);

        // Paso 3: construir bloque
        $block = "=== TAREA EJECUTABLE: {$cluster} ===\n";
        $block .= "Intent: {$classifiedIntent} | Handler: {$handlerClass}\n";
        $block .= "Descripcion: {$entry['description']}\n";
        $block .= "INSTRUCCION: Emite JSON {\"skill\":\"{$classifiedIntent}\",\"data\":{...}} — NO calcules tu.\n";

        if (!empty($peers)) {
            $block .= "Skills relacionadas en este contexto:\n";
            foreach ($peers as $peer) {
                $block .= '• ' . $peer['name'] . ': ' . $peer['description'] . "\n";
            }
        }

        $block .= '===';

        return [
            'needs_clarification' => false,
            'block'               => $block,
            'cluster'             => $cluster,
        ];
    }

    /**
     * Retorna el cluster al que pertenece un intent (o null si no tiene handler).
     */
    public function getTopicCluster(string $intent): ?string
    {
        $manifest = $this->build();
        foreach ($manifest as $cluster => $skills) {
            foreach ($skills as $skill) {
                if ($skill['name'] === $intent) {
                    return $cluster;
                }
            }
        }
        return null;
    }

    /**
     * Indica si un intent tiene handler PHP en el catalogo.
     */
    public function hasHandler(string $intent): bool
    {
        return $this->getTopicCluster($intent) !== null;
    }

    /**
     * Retorna la entrada completa del intent si tiene handler, o null.
     *
     * @return array<string, mixed>|null
     */
    public function getHandler(string $intent): ?array
    {
        $manifest = $this->build();
        foreach ($manifest as $skills) {
            foreach ($skills as $skill) {
                if ($skill['name'] === $intent) {
                    return $skill;
                }
            }
        }
        return null;
    }

    /**
     * Retorna hasta $limit skills del mismo cluster, excluyendo $excludeIntent.
     *
     * @return list<array<string, mixed>>
     */
    private function getClusterPeers(string $cluster, string $excludeIntent, int $limit = 3): array
    {
        $manifest = $this->build();
        $peers = [];
        foreach ($manifest[$cluster] ?? [] as $skill) {
            if ($skill['name'] === $excludeIntent) {
                continue;
            }
            $peers[] = $skill;
            if (count($peers) >= $limit) {
                break;
            }
        }
        return $peers;
    }

    /**
     * Extrae el nombre corto de la clase del handler (sin namespace, sin @metodo).
     * Ejemplo: "App\Core\Skills\AccountingSkill@execute" => "AccountingSkill"
     */
    private function shortClassName(string $handler): string
    {
        // Quitar @metodo si existe
        $classRef = explode('@', $handler)[0];
        // Quitar namespace
        $parts = explode('\\', $classRef);
        return end($parts) ?: $classRef;
    }
}
