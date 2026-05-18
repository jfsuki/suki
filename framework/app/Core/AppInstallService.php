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
     * @param array $metadata Optional: system_prompt, requirements, mixins, business_name
     * @return array{seeded:int,skipped:int,agents_created:array<int,array{area:string,agent_id:string}>}
     */
    public function seedAgents(string $tenantId, string $appId, array $metadata = []): array
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
            return ['seeded' => 0, 'skipped' => 0, 'agents_created' => [], 'error' => "App '$appId' no encontrada en catálogo"];
        }

        // Merge extra agents from composed mixins
        $enabledAgents = is_array($app['enabled_agents'] ?? null) ? (array) $app['enabled_agents'] : [];
        $composedAgents = is_array($metadata['composed_agents'] ?? null) ? (array) $metadata['composed_agents'] : [];
        $enabledAgents  = array_unique(array_merge($enabledAgents, $composedAgents));

        if (empty($enabledAgents)) {
            return ['seeded' => 0, 'skipped' => 0, 'agents_created' => []];
        }

        $db           = $this->registry->db();
        $seeded       = 0;
        $skipped      = 0;
        $created      = [];
        $systemPrompt = (string) ($metadata['system_prompt'] ?? '');
        $requirements = (string) ($metadata['requirements']  ?? '');

        // Ensure prompt_override column exists (additive, safe on older installs)
        try {
            $db->exec("ALTER TABLE ai_agents ADD COLUMN prompt_override TEXT");
        } catch (\Throwable $ignored) {}
        try {
            $db->exec("ALTER TABLE ai_agents ADD COLUMN requirements TEXT");
        } catch (\Throwable $ignored) {}
        try {
            $db->exec("ALTER TABLE ai_agents ADD COLUMN business_name VARCHAR(200)");
        } catch (\Throwable $ignored) {}

        $businessName = (string) ($metadata['business_name'] ?? '');

        foreach ($enabledAgents as $area) {
            $area = (string) $area;

            $stmt = $db->prepare('SELECT agent_id FROM ai_agents WHERE tenant_id = ? AND area = ? AND source = ?');
            $stmt->execute([$tenantId, $area, 'catalog']);
            $existingId = $stmt->fetchColumn();

            if ($existingId !== false) {
                // Update prompt on existing agent (safe: no data destruction)
                if ($systemPrompt !== '') {
                    $db->prepare('UPDATE ai_agents SET prompt_override = ?, requirements = ?, business_name = ?, app_id = ? WHERE agent_id = ?')
                       ->execute([$systemPrompt, $requirements, $businessName, $appId, $existingId]);
                }
                $skipped++;
                $created[] = ['area' => $area, 'agent_id' => (string) $existingId, 'status' => 'updated'];
                continue;
            }

            $agentId = $this->registry->createAgent(
                $tenantId,
                $area,
                $area,
                ['app_id' => $appId, 'source' => 'catalog'],
                $appId
            );

            $db->prepare(
                'UPDATE ai_agents SET app_id = ?, source = ?, prompt_override = ?, requirements = ?, business_name = ? WHERE agent_id = ?'
            )->execute([$appId, 'catalog', $systemPrompt, $requirements, $businessName, $agentId]);

            $created[] = ['area' => $area, 'agent_id' => (string) $agentId, 'status' => 'created'];
            $seeded++;
        }

        // Seed default accounting roles (idempotent — safe to call on existing tenants)
        try {
            $accountingRepo = new AccountingRepository($this->registry->db());
            $accountingRepo->seedDefaultRolesForTenant($tenantId);
        } catch (\Throwable $ignored) {}

        return ['seeded' => $seeded, 'skipped' => $skipped, 'agents_created' => $created];
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
