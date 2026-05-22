<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AppCatalogManager
 *
 * Runtime service for reading, querying, and extending app_catalog.json.
 * The agent can propose new app types in production via proposeApp().
 */
final class AppCatalogManager
{
    private string $catalogPath;
    private ?array $catalog = null;

    public function __construct(?string $catalogPath = null)
    {
        $this->catalogPath = $catalogPath ?? $this->resolveCatalogPath();
    }

    public function loadCatalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        if (!file_exists($this->catalogPath)) {
            return ['apps' => [], 'mixins' => [], 'schema_version' => '2.0'];
        }
        $raw = file_get_contents($this->catalogPath);
        if ($raw === false) {
            return ['apps' => [], 'mixins' => [], 'schema_version' => '2.0'];
        }
        $data = json_decode($raw, true);
        $this->catalog = is_array($data) ? $data : ['apps' => [], 'mixins' => []];
        return $this->catalog;
    }

    public function listApps(): array
    {
        $catalog = $this->loadCatalog();
        return is_array($catalog['apps'] ?? null) ? (array) $catalog['apps'] : [];
    }

    public function findApp(string $appId): ?array
    {
        foreach ($this->listApps() as $app) {
            if (is_array($app) && ($app['id'] ?? '') === $appId) {
                return $app;
            }
        }
        return null;
    }

    public function getMixins(): array
    {
        $catalog = $this->loadCatalog();
        return is_array($catalog['mixins'] ?? null) ? (array) $catalog['mixins'] : [];
    }

    public function getMixin(string $mixinId): ?array
    {
        $mixins = $this->getMixins();
        return is_array($mixins[$mixinId] ?? null) ? (array) $mixins[$mixinId] : null;
    }

    public function getInterviewQuestions(string $appId): array
    {
        $app = $this->findApp($appId);
        if ($app === null) {
            return [];
        }
        return is_array($app['interview_questions'] ?? null) ? (array) $app['interview_questions'] : [];
    }

    public function getCompatibleMixins(string $appId): array
    {
        $app = $this->findApp($appId);
        if ($app === null) {
            return [];
        }
        $compatibleIds = is_array($app['compatible_mixins'] ?? null) ? (array) $app['compatible_mixins'] : [];
        $result = [];
        foreach ($compatibleIds as $id) {
            $mixin = $this->getMixin((string) $id);
            if ($mixin !== null) {
                $result[(string) $id] = $mixin;
            }
        }
        return $result;
    }

    /**
     * Merges base app with selected mixins to produce a combined app definition.
     */
    public function composeApp(string $appId, array $selectedMixinIds): array
    {
        $app = $this->findApp($appId);
        if ($app === null) {
            return [];
        }

        foreach ($selectedMixinIds as $mixinId) {
            $mixin = $this->getMixin((string) $mixinId);
            if ($mixin === null) {
                continue;
            }
            $additionalAgents  = is_array($mixin['additional_agents'] ?? null)  ? $mixin['additional_agents']  : [];
            $additionalModules = is_array($mixin['additional_modules'] ?? null) ? $mixin['additional_modules'] : [];
            $additionalConfig  = is_array($mixin['additional_config_fields'] ?? null) ? $mixin['additional_config_fields'] : [];

            $app['enabled_agents']  = array_unique(array_merge($app['enabled_agents'] ?? [], $additionalAgents));
            $app['modules']         = array_unique(array_merge($app['modules'] ?? [], $additionalModules));
            $app['config_fields']   = array_unique(array_merge($app['config_fields'] ?? [], $additionalConfig));
            $app['applied_mixins']  = array_merge($app['applied_mixins'] ?? [], [$mixinId]);
        }

        return $app;
    }

    /**
     * Builds a customized system prompt for a tenant based on gathered requirements.
     */
    public function buildSystemPrompt(string $appId, string $businessName, string $requirements, array $appliedMixins = []): string
    {
        $app = $this->findApp($appId);
        if ($app === null) {
            return '';
        }

        $appName    = $app['name'] ?? $appId;
        $sector     = $app['sector'] ?? 'general';
        $modules    = implode(', ', $app['modules'] ?? []);
        $mixinNames = [];
        foreach ($appliedMixins as $mid) {
            $m = $this->getMixin($mid);
            if ($m !== null) {
                $mixinNames[] = $m['name'] ?? $mid;
            }
        }
        $mixinLine = $mixinNames !== [] ? 'Módulos adicionales activos: ' . implode(', ', $mixinNames) . '.' : '';

        $prompt  = "Eres el asistente de IA especializado para **{$businessName}**, un negocio del sector {$sector}.\n";
        $prompt .= "Sistema: {$appName}. Módulos activos: {$modules}. {$mixinLine}\n\n";

        if ($requirements !== '') {
            $prompt .= "Contexto del negocio según el propietario:\n{$requirements}\n\n";
        }

        $prompt .= "Siempre responde en español colombiano. Conoces a fondo las reglas fiscales de Colombia (DIAN, ReteFuente, ICA, IVA). ";
        $prompt .= "Ayuda al usuario a gestionar su negocio usando los módulos disponibles. ";
        $prompt .= "Si el usuario pide algo que está fuera de los módulos activos, sugiere activarlo.";

        return $prompt;
    }

    /**
     * Propose and persist a new app type to the catalog.
     * The agent can call this to expand the catalog at runtime.
     *
     * @param array $definition Must include: id, name, category, sector, description, enabled_agents, modules, config_fields
     */
    public function proposeApp(array $definition): array
    {
        $required = ['id', 'name', 'category', 'sector', 'description', 'enabled_agents', 'modules', 'config_fields'];
        foreach ($required as $field) {
            if (empty($definition[$field])) {
                return ['ok' => false, 'error' => "Campo requerido faltante: {$field}"];
            }
        }

        $appId = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $definition['id']));
        if ($this->findApp($appId) !== null) {
            return ['ok' => false, 'error' => "Ya existe una app con id '{$appId}'"];
        }

        $catalog = $this->loadCatalog();

        $newApp = [
            'id'                  => $appId,
            'name'                => (string) $definition['name'],
            'category'            => (string) $definition['category'],
            'sector'              => (string) $definition['sector'],
            'description'         => (string) $definition['description'],
            'status'              => 'available',
            'enabled_agents'      => (array) $definition['enabled_agents'],
            'default_workflows'   => (array) ($definition['default_workflows'] ?? []),
            'modules'             => (array) $definition['modules'],
            'config_fields'       => (array) $definition['config_fields'],
            'compatible_mixins'   => (array) ($definition['compatible_mixins'] ?? []),
            'interview_questions' => (array) ($definition['interview_questions'] ?? []),
            '_proposed_by_agent'  => true,
            '_proposed_at'        => date('Y-m-d H:i:s'),
        ];

        $catalog['apps'][] = $newApp;
        $this->catalog = $catalog;

        $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($this->catalogPath, $json) === false) {
            return ['ok' => false, 'error' => 'No se pudo guardar el catálogo'];
        }

        return ['ok' => true, 'app_id' => $appId, 'app' => $newApp];
    }

    /**
     * Returns all sectors with a summary for LLM context.
     */
    public function getSectorSummary(): string
    {
        $lines = [];
        foreach ($this->listApps() as $app) {
            if (!is_array($app)) {
                continue;
            }
            $mixins = is_array($app['compatible_mixins'] ?? null) ? implode(', ', $app['compatible_mixins']) : '';
            $lines[] = "- **{$app['id']}** ({$app['sector']}): {$app['description']}" . ($mixins ? " [+ {$mixins}]" : '');
        }
        return implode("\n", $lines);
    }

    private function resolveCatalogPath(): string
    {
        // __DIR__ = .../framework/app/Core → dirname(,3) = repo root (suki/)
        $candidates = [
            dirname(__DIR__, 3) . '/project/contracts/app_catalog.json',
            dirname(__DIR__, 3) . '/contracts/app_catalog.json',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return $candidates[0];
    }
}
