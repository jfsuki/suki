<?php
declare(strict_types=1);

// app/Core/AppConfigOnboarding.php

namespace App\Core;

/**
 * AppConfigOnboarding
 *
 * Gestiona la configuración inicial de la app instalada en el tenant.
 * Diseño LLM-driven: PHP determina qué campo falta → inyecta descripción en system prompt
 * → el LLM genera la pregunta natural → PHP extrae y persiste el valor.
 *
 * Dos capas de campos:
 *   1. Globales (global_onboarding_fields.json): NIT, razón social, municipio — toda app
 *   2. Específicos de app (app_catalog.json → config_fields): según la app instalada
 *   3. Específicos de mixins activos (app_catalog.json → mixins → additional_config_fields)
 *
 * Sin strings de preguntas hardcodeadas en PHP ni en JSON.
 * El LLM recibe el nombre del campo + su descripción y genera la pregunta.
 *
 * Tabla: app_tenant_config (MySQL, tenant_id + app_id + field_key = unique)
 */
final class AppConfigOnboarding
{
    private string $globalFieldsPath;
    private string $appCatalogPath;
    private ?array $globalFields  = null;
    private ?array $appCatalog    = null;

    public function __construct(
        private readonly AppTenantConfigService $configSvc
    ) {
        $this->globalFieldsPath = dirname(__DIR__, 3) . '/contracts/agents/global_onboarding_fields.json';
        $this->appCatalogPath   = dirname(__DIR__, 3) . '/project/contracts/app_catalog.json';
    }

    /**
     * Resolves the catalog app id actually installed for this tenant.
     *
     * Priority:
     * 1. _installed_app_id saved in app_tenant_config under 'tenant_meta' slot
     * 2. _installed_app_id saved under $fallbackAppId slot
     * 3. $fallbackAppId as-is (e.g. 'suki_erp' from manifest)
     */
    public function resolveInstalledAppId(string $tenantId, string $fallbackAppId): string
    {
        // Check tenant_meta first (written by InstallPlaybookCommandHandler regardless of catalog_app_id)
        $meta = $this->configSvc->loadAll($tenantId, 'tenant_meta');
        $resolved = trim((string) ($meta['_installed_app_id'] ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }

        // Check under the fallback app slot (written when catalog_app_id === fallbackAppId)
        $slot = $this->configSvc->loadAll($tenantId, $fallbackAppId);
        $resolved = trim((string) ($slot['_installed_app_id'] ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }

        return $fallbackAppId;
    }

    /**
     * Returns true when the app configuration is complete for this tenant.
     */
    public function isComplete(string $tenantId, string $appId): bool
    {
        $pending = $this->getPendingFields($tenantId, $appId);
        return $pending === [];
    }

    /**
     * Core method — call once per turn from the Gatekeeper after user profile is done.
     *
     * - Extracts and persists the answer from $userMessage for the pending field.
     * - Determines the next unconfigured field.
     * - Returns a context string for system prompt injection (Capa 2H), or null when complete.
     */
    public function processAnswerAndGetContext(
        string $tenantId,
        string $appId,
        string $userMessage
    ): ?string {
        $pendingFields = $this->getPendingFields($tenantId, $appId);
        if ($pendingFields === []) {
            return null;
        }

        // The first pending field is the one we just asked about in the previous turn
        $activeField = $pendingFields[0];

        if ($userMessage !== '') {
            $extracted = $this->extractFieldValue($activeField, $userMessage);
            if ($extracted !== null && $extracted !== '') {
                $this->configSvc->saveField($tenantId, $appId, $activeField['key'], $extracted);
                // Reload pending after save
                $pendingFields = $this->getPendingFields($tenantId, $appId);
            }
        }

        if ($pendingFields === []) {
            $this->configSvc->markComplete($tenantId, $appId);
            return null;
        }

        return $this->buildFieldContextForPrompt($pendingFields[0], $tenantId, $appId);
    }

    /**
     * Build the system prompt context block for the given pending field.
     * Injected into the LLM system prompt (Capa 2H) — LLM generates the question naturally.
     */
    public function buildFieldContextForPrompt(array $field, string $tenantId, string $appId): string
    {
        $appDef  = $this->findAppDefinition($appId);
        $appName = $appDef['name'] ?? $appId;

        $key         = $field['key']         ?? '';
        $description = $field['description'] ?? "Configuración requerida: {$key}";
        $llmHint     = $field['llm_hint']    ?? "Pregunta de forma natural y breve por: {$key}.";

        // Include already-configured fields so LLM can reference them
        $configured = $this->configSvc->loadAll($tenantId, $appId);
        $knownParts = [];
        foreach ($configured as $k => $v) {
            if ($v !== '' && $v !== null) {
                $knownParts[] = "{$k}: {$v}";
            }
        }

        $lines = [
            '## Configuración de App — Dato Pendiente',
            '',
            "**App activa**: {$appName}",
            "**Dato requerido**: {$key}",
            "**Para qué sirve**: {$description}",
            "**Instrucción para el LLM**: {$llmHint}",
        ];

        if ($knownParts !== []) {
            $lines[] = '**Ya tenemos**: ' . implode(' · ', $knownParts) . '.';
        }

        $lines[] = '';
        $lines[] = 'REGLA: Haz UNA sola pregunta concreta. No menciones "campo", "sistema" ni "configuración". '
                 . 'Sé natural, empático y breve. No hagas varias preguntas a la vez.';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the ordered list of fields not yet configured for this tenant+app.
     */
    public function getPendingFields(string $tenantId, string $appId): array
    {
        $allFields  = $this->resolveAllFieldsForApp($appId, $tenantId);
        $configured = $this->configSvc->loadAll($tenantId, $appId);
        $pending    = [];

        foreach ($allFields as $field) {
            $key   = $field['key'] ?? '';
            $value = $configured[$key] ?? '';
            if ($key !== '' && ($value === '' || $value === null)) {
                $pending[] = $field;
            }
        }

        return $pending;
    }

    /**
     * Merges: global fields + app-specific config_fields + active mixin fields.
     * Fields are resolved from JSON definitions so the LLM gets rich descriptions.
     *
     * Mixin fields are only included for mixins the tenant explicitly activated
     * (stored as _active_mixins in app_tenant_config). Falls back to all compatible
     * mixins if no _active_mixins record exists (first-install scenario).
     */
    private function resolveAllFieldsForApp(string $appId, string $tenantId = ''): array
    {
        $globalFields = $this->loadGlobalFields();
        $appDef       = $this->findAppDefinition($appId);
        $catalog      = $this->loadAppCatalog();

        if ($appDef === null) {
            return $globalFields;
        }

        // App-specific config_fields (keys) → enrich with descriptions from catalog field_definitions if present
        $appFieldKeys = is_array($appDef['config_fields'] ?? null) ? $appDef['config_fields'] : [];
        $appFields    = $this->resolveFieldsFromKeys($appFieldKeys, $catalog['field_definitions'] ?? []);

        // Determine which mixins are active for this tenant
        $compatibleMixins = is_array($appDef['compatible_mixins'] ?? null) ? $appDef['compatible_mixins'] : [];
        $activeMixins     = $compatibleMixins; // default: all compatible
        if ($tenantId !== '') {
            $stored = $this->configSvc->loadAll($tenantId, $appId);
            $rawMixins = trim((string) ($stored['_active_mixins'] ?? ''));
            if ($rawMixins !== '') {
                $activeMixins = array_filter(
                    array_map('trim', explode(',', $rawMixins)),
                    static fn(string $m) => $m !== ''
                );
                $activeMixins = array_values($activeMixins);
            }
        }

        // Mixin fields — only for active mixins
        $mixinFields = [];
        $mixins      = $catalog['mixins'] ?? [];
        foreach ($activeMixins as $mixinKey) {
            if (!isset($mixins[$mixinKey])) {
                continue;
            }
            $mixinFieldKeys = $mixins[$mixinKey]['additional_config_fields'] ?? [];
            if (is_array($mixinFieldKeys)) {
                $resolved    = $this->resolveFieldsFromKeys($mixinFieldKeys, $catalog['field_definitions'] ?? []);
                $mixinFields = array_merge($mixinFields, $resolved);
            }
        }

        // Deduplicate by key: global → app-specific → mixin
        $seen   = [];
        $merged = [];
        foreach (array_merge($globalFields, $appFields, $mixinFields) as $field) {
            $key = $field['key'] ?? '';
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key]  = true;
                $merged[]    = $field;
            }
        }

        return $merged;
    }

    /**
     * Converts a flat array of field keys into rich field definitions.
     * Falls back to a minimal definition if no catalog definition exists.
     */
    private function resolveFieldsFromKeys(array $keys, array $fieldDefs): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (isset($fieldDefs[$key])) {
                $result[] = array_merge(['key' => $key], (array) $fieldDefs[$key]);
            } else {
                // Minimal fallback — LLM will still know the field name
                $result[] = [
                    'key'         => $key,
                    'type'        => 'text',
                    'required'    => true,
                    'description' => "Dato de configuración requerido por la app: {$key}.",
                    'llm_hint'    => "Pregunta de forma natural y breve por '{$key}'.",
                ];
            }
        }
        return $result;
    }

    /**
     * Simple extraction — PHP is the authority, not the LLM.
     * Trims and limits the value. For now all fields are treated as free text.
     * Future: type-specific validators (numeric, enum, etc.) per field type.
     */
    private function extractFieldValue(array $field, string $answer): ?string
    {
        $value = mb_substr(trim($answer), 0, 500);

        // Skip clearly meaningless answers
        $lower = mb_strtolower($value);
        if (in_array($lower, ['no sé', 'no se', 'ns', 'n/a', '?'], true)) {
            return null;
        }

        // NIT: strip non-numeric characters (keep only digits and dash)
        if (($field['key'] ?? '') === 'nit') {
            $value = preg_replace('/[^0-9\-]/', '', $value) ?: $value;
        }

        return $value !== '' ? $value : null;
    }

    private function findAppDefinition(string $appId): ?array
    {
        $catalog = $this->loadAppCatalog();
        foreach ($catalog['apps'] ?? [] as $app) {
            if (is_array($app) && ($app['id'] ?? '') === $appId) {
                return $app;
            }
        }
        return null;
    }

    private function loadGlobalFields(): array
    {
        if ($this->globalFields !== null) {
            return $this->globalFields;
        }
        if (file_exists($this->globalFieldsPath)) {
            $raw = file_get_contents($this->globalFieldsPath);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($decoded['fields'] ?? null)) {
                $this->globalFields = $decoded['fields'];
                return $this->globalFields;
            }
        }
        $this->globalFields = [
            ['key' => 'razon_social', 'type' => 'text', 'required' => true,  'description' => 'Nombre del negocio o empresa.', 'llm_hint' => 'Pregunta el nombre del negocio.'],
            ['key' => 'nit',          'type' => 'text', 'required' => true,  'description' => 'NIT o cédula del negocio (Colombia).', 'llm_hint' => 'Pregunta el NIT o cédula.'],
            ['key' => 'municipio',    'type' => 'text', 'required' => true,  'description' => 'Ciudad o municipio donde opera.', 'llm_hint' => 'Pregunta en qué ciudad está el negocio.'],
        ];
        return $this->globalFields;
    }

    private function loadAppCatalog(): array
    {
        if ($this->appCatalog !== null) {
            return $this->appCatalog;
        }
        if (file_exists($this->appCatalogPath)) {
            $raw = file_get_contents($this->appCatalogPath);
            $decoded = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $this->appCatalog = $decoded;
                return $this->appCatalog;
            }
        }
        $this->appCatalog = [];
        return $this->appCatalog;
    }
}
