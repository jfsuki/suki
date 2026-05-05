<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AppInstallService — provisions shared agents for a tenant when an app is installed.
 *
 * Usage: (new AppInstallService($registry))->seedAgents($tenantId, $appId)
 */
final class AppInstallService
{
    private ProjectRegistry $registry;
    private string $catalogPath;

    public function __construct(ProjectRegistry $registry, ?string $catalogPath = null)
    {
        $this->registry    = $registry;
        $this->catalogPath = $catalogPath ?? $this->defaultCatalogPath();
    }

    /**
     * Creates ai_agents rows for each enabled_agent declared in the app catalog.
     * Idempotent: skips agents that already exist for the tenant+area combination.
     *
     * @return array{seeded:int,skipped:int,agents:array<int,string>}
     */
    public function seedAgents(string $tenantId, string $appId): array
    {
        $catalog = $this->loadCatalog();
        $app = null;
        foreach ($catalog['apps'] ?? [] as $entry) {
            if ((string) ($entry['id'] ?? '') === $appId) {
                $app = $entry;
                break;
            }
        }

        if ($app === null) {
            return ['seeded' => 0, 'skipped' => 0, 'agents' => [], 'error' => "App '$appId' no encontrada en catálogo"];
        }

        $enabledAgents = is_array($app['enabled_agents'] ?? null) ? (array) $app['enabled_agents'] : [];
        if (empty($enabledAgents)) {
            return ['seeded' => 0, 'skipped' => 0, 'agents' => []];
        }

        $db      = $this->registry->db();
        $seeded  = 0;
        $skipped = 0;
        $created = [];

        foreach ($enabledAgents as $area) {
            $area = (string) $area;
            // Check if already exists
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM ai_agents WHERE tenant_id = ? AND area = ? AND source = ?'
            );
            $stmt->execute([$tenantId, $area, 'catalog']);
            if ((int) $stmt->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            $agentId = $this->registry->createAgent(
                $tenantId,
                $area,
                $area,
                ['app_id' => $appId, 'source' => 'catalog'],
                $appId
            );

            // Update app_id and source on the newly created agent
            $db->prepare(
                'UPDATE ai_agents SET app_id = ?, source = ? WHERE agent_id = ?'
            )->execute([$appId, 'catalog', $agentId]);

            $created[] = $area;
            $seeded++;
        }

        return ['seeded' => $seeded, 'skipped' => $skipped, 'agents' => $created];
    }

    /** @return array<string,mixed> */
    private function loadCatalog(): array
    {
        if (!file_exists($this->catalogPath)) {
            return ['apps' => []];
        }
        $data = json_decode((string) file_get_contents($this->catalogPath), true);
        return is_array($data) ? $data : ['apps' => []];
    }

    private function defaultCatalogPath(): string
    {
        // Resolve from framework root to project/contracts/app_catalog.json
        $frameworkRoot = dirname(__DIR__, 2);
        $projectRoot = dirname($frameworkRoot) . '/project';
        return $projectRoot . '/contracts/app_catalog.json';
    }
}
