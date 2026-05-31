<?php
declare(strict_types=1);

namespace App\Core\Skills;

use App\Core\AppCatalogManager;
use App\Core\AppInstallService;
use App\Core\AppInterviewState;
use App\Core\AppMemoryService;
use App\Core\AppSchemaDesigner;
use App\Core\AppVersionManager;
use App\Core\ProjectRegistry;

/**
 * CreateAppSkill — LLM-driven app creation.
 *
 * PHP does: state persistence, schema security validation, execution.
 * The LLM does: requirements interview, schema design, all conversation logic.
 *
 * Flow:
 *   Call 1  (no confirmed flag)  → return developer context + schema format spec
 *                                   → LLM conducts professional interview freely
 *   Call 2  (confirmed=true)     → LLM has gathered info + designed schema
 *                                   → PHP validates schema security, runs creation
 *
 * Special: action=propose_new_type → add new app type to catalog at runtime
 */
final class CreateAppSkill
{
    private AppCatalogManager $catalog;
    private AppInterviewState $interviewState;
    private AppMemoryService  $appMemory;
    private AppSchemaDesigner $schemaDesigner;
    private AppVersionManager $versionManager;

    public function __construct(
        ?AppCatalogManager $catalog       = null,
        ?AppInterviewState $interviewState = null,
        ?AppMemoryService  $appMemory      = null,
        ?AppSchemaDesigner $schemaDesigner = null,
        ?AppVersionManager $versionManager = null
    ) {
        $this->catalog        = $catalog        ?? new AppCatalogManager();
        $this->interviewState = $interviewState ?? new AppInterviewState();
        $this->appMemory      = $appMemory      ?? new AppMemoryService();
        $this->schemaDesigner = $schemaDesigner ?? new AppSchemaDesigner();
        $this->versionManager = $versionManager ?? new AppVersionManager();
    }

    public function handle(array $args, array $context): array
    {
        $tenantId  = (string) ($context['tenant_id']  ?? 'default');
        $sessionId = (string) ($context['session_id'] ?? $context['thread_id'] ?? 'main');
        $action    = trim((string) ($args['action']   ?? ''));

        // Special: agent proposes a new app type for the catalog
        if ($action === 'propose_new_type') {
            return $this->handlePropose($args, $tenantId);
        }

        // Special: builder publishes a draft app to the Marketplace
        if ($action === 'publish_to_marketplace') {
            return $this->handlePublish($args, $tenantId);
        }

        // Phase 2: LLM has completed the interview and confirmed with schema
        if (($args['confirmed'] ?? false) === true || ($args['confirmed'] ?? '') === 'true') {
            return $this->executeCreation($tenantId, $sessionId, $args);
        }

        // Phase 1: First call — give LLM the context it needs to conduct the interview
        return $this->startDeveloperMode($tenantId, $sessionId, $args);
    }

    // ─── Phase 1: Start developer mode ───────────────────────────────────────

    private function startDeveloperMode(string $tenantId, string $sessionId, array $args): array
    {
        $sector = strtolower(trim((string) ($args['sector'] ?? '')));
        $appId  = trim((string) ($args['app_id'] ?? ''));

        if ($appId === '') {
            $appId = $this->inferAppIdFromCatalog($sector . ' ' . ($args['requirements'] ?? ''));
        }

        $appDef = $this->catalog->findApp($appId);
        if ($appDef === null) {
            return [
                'ok'     => false,
                'action' => 'ask_user',
                'reply'  => "No encontré una app para el sector '{$sector}'.\n\nApps disponibles:\n\n" . $this->catalog->getSectorSummary() . "\n\n¿Cuál describe mejor tu negocio?",
            ];
        }

        // Check if this app already exists for the tenant (update mode)
        $existingContext = '';
        $isUpdate = $this->appMemory->exists($tenantId, $appId);
        if ($isUpdate) {
            $existingContext = $this->appMemory->buildDeveloperContext($tenantId, $appId);
        }

        // Save initial state
        $state = $this->interviewState->initialize($tenantId, $sessionId, $appId);
        $this->interviewState->save($tenantId, $sessionId, $state);

        $schemaFormatSpec = $this->schemaDesigner->getSchemaFormatSpec();
        $compatibleMixins = $this->catalog->getCompatibleMixins($appId);
        $mixinList = '';
        foreach ($compatibleMixins as $mid => $mixin) {
            $mixinList .= "\n- **{$mixin['name']}** (`{$mid}`): {$mixin['description']}";
        }

        $existingContextBlock = $existingContext !== ''
            ? "\n\nCONTEXTO DE APP EXISTENTE (NO PERDER — solo cambios aditivos):\n{$existingContext}\n"
            : '';

        $processStep = $isUpdate
            ? "PROCESO (modo ACTUALIZACIÓN — la app YA EXISTE):\n"
              . "1. DIAGNÓSTICO: Entiende qué quiere cambiar/agregar el usuario. Nunca eliminar datos existentes.\n"
              . "2. DISEÑO ADITIVO: Propón SOLO tablas o columnas nuevas. El schema existente se preserva íntegro.\n"
              . "3. CONFIRMACIÓN: Resume cambios propuestos. Pide 'CONFIRMO'.\n"
              . "4. ACTUALIZACIÓN: Llama create_app con confirmed=true e incluye el schema COMPLETO actualizado."
            : "PROCESO OBLIGATORIO (modo CREACIÓN — no saltar pasos):\n"
              . "1. ENTREVISTA: Haz preguntas específicas sobre el negocio. No asumas nada. Pregunta sobre:\n"
              . "   - Procesos operativos del día a día\n"
              . "   - Quiénes usan el sistema (roles, cuántos usuarios)\n"
              . "   - Qué datos necesitan guardar y consultar\n"
              . "   - Reportes que necesitan (PDF, HTML, gráficos en pantalla)\n"
              . "   - Cálculos personalizados (fórmulas, tarifas, descuentos, impuestos)\n"
              . "   - Integraciones y automatizaciones\n"
              . "   - Módulos adicionales disponibles:{$mixinList}\n"
              . "   Continúa preguntando hasta que el usuario confirme que dio toda la información.\n"
              . "2. DISEÑO DE SCHEMA: Cuando tengas suficiente info, propón el diseño de base de datos.\n"
              . "   Usa el formato JSON exacto a continuación. Muéstraselo al usuario en lenguaje simple.\n"
              . "   Ajusta hasta que el usuario confirme el diseño.\n"
              . "3. SEGURIDAD: Explica quién accede a qué. Confirma con el usuario.\n"
              . "4. CONFIRMACIÓN: Resume todo. Pide confirmación explícita ('CONFIRMO').\n"
              . "5. CREACIÓN: Cuando el usuario confirme, llama create_app de nuevo con:\n"
              . "   - confirmed: true\n"
              . "   - app_id: '{$appId}'\n"
              . "   - business_name: (nombre del negocio)\n"
              . "   - requirements: (resumen de todo lo que recopilaste)\n"
              . "   - schema: (el JSON del diseño final aprobado por el usuario)\n"
              . "   - mixins: (array de módulos adicionales que pidió, ej: [\"accounting\", \"billing_dian\"])";

        $devInstructions = implode("\n", [
            "MODO: Eres un ingeniero de software senior profesional haciendo levantamiento de requisitos.",
            $existingContextBlock,
            $processStep,
            "",
            "FORMATO DEL SCHEMA:",
            $schemaFormatSpec,
        ]);

        $replyIntro = $isUpdate
            ? "Voy a revisar tu app de **{$appDef['name']}** y aplicar los cambios que necesitas.\n\n"
              . "Tengo el contexto completo de lo que ya existe — no se perderá ningún dato.\n\n"
              . "¿Qué quieres agregar o mejorar?"
            : "Voy a actuar como tu **desarrollador de software personal** para crear tu app de **{$appDef['name']}**.\n\n"
              . "No ejecutaré nada hasta que confirmes que todo está correcto. El proceso es:\n\n"
              . "1️⃣ **Levantamiento de requisitos** — te hago preguntas para entender tu negocio completamente\n"
              . "2️⃣ **Diseño de la base de datos** — propongo las tablas y campos necesarios\n"
              . "3️⃣ **Revisión de seguridad** — definimos quién accede a qué\n"
              . "4️⃣ **Confirmación final** — resumen completo antes de crear\n\n"
              . "---\n\n"
              . "Empecemos. Para crear una app de **{$appDef['name']}** que realmente funcione para tu negocio, necesito conocerte bien.\n\n"
              . "¿Cuál es el nombre de tu negocio y puedes contarme cómo funciona un día típico de trabajo?";

        return [
            'ok'                     => false,
            'action'                 => 'interview_mode',
            'app_id'                 => $appId,
            'app_name'               => $appDef['name'],
            'sector'                 => $appDef['sector'],
            'is_update'              => $isUpdate,
            'developer_instructions' => $devInstructions,
            'reply'                  => $replyIntro,
        ];
    }

    // ─── Phase 2: Execute creation ────────────────────────────────────────────

    private function executeCreation(string $tenantId, string $sessionId, array $args): array
    {
        $appId        = trim((string) ($args['app_id']        ?? ''));
        $businessName = trim((string) ($args['business_name'] ?? ''));
        $requirements = trim((string) ($args['requirements']  ?? ''));
        $mixins       = is_array($args['mixins'] ?? null) ? (array) $args['mixins'] : [];
        $rawSchema    = is_array($args['schema'] ?? null) ? (array) $args['schema'] : [];

        if ($appId === '') {
            return ['ok' => false, 'action' => 'respond_local', 'reply' => 'Falta app_id para crear la app.'];
        }

        $appDef = $this->catalog->findApp($appId);
        if ($appDef === null) {
            return ['ok' => false, 'action' => 'respond_local', 'reply' => "App '{$appId}' no encontrada en catálogo."];
        }

        // Validate and sanitize LLM-generated schema
        $validation = ['valid' => true, 'schema' => [], 'errors' => []];
        if (!empty($rawSchema)) {
            $validation = $this->schemaDesigner->validateAndSanitize($rawSchema);
        }

        if (!$validation['valid']) {
            $errList = implode(', ', $validation['errors']);
            return [
                'ok'     => false,
                'action' => 'respond_local',
                'reply'  => "⚠️ El schema tiene problemas de seguridad: {$errList}. Por favor revisa el diseño.",
            ];
        }

        $schema       = $validation['schema'];
        $systemPrompt = $this->catalog->buildSystemPrompt($appId, $businessName ?: 'tu negocio', $requirements, $mixins);

        try {
            // Compose app with selected mixins
            $composedApp = $mixins !== [] ? $this->catalog->composeApp($appId, $mixins) : $appDef;

            // Seed agents with full metadata
            $registry = new ProjectRegistry();
            $service  = new AppInstallService($registry);
            $result   = $service->seedAgents($tenantId, $appId, [
                'system_prompt' => $systemPrompt,
                'requirements'  => $requirements,
                'business_name' => $businessName,
                'composed_agents' => array_column($composedApp['enabled_agents'] ?? [], null),
            ]);

            // Persist full app memory for future sessions (no blind restarts)
            $this->appMemory->save(
                $tenantId, $appId, $schema, $systemPrompt, $requirements,
                ['business_name' => $businessName, 'mixins' => $mixins]
            );

            // Create actual DB tables in MySQL with canonical naming:
            // app_{appId}__{entity} — one physical table shared by all tenants (isolated by tenant_id)
            $migrationErrors = [];
            $migrationCount  = 0;
            $prevStorageModel = getenv('PROJECT_STORAGE_MODEL') ?: '';
            try {
                // Force CANONICAL so TableNamespace skips project-hash prefix
                putenv('PROJECT_STORAGE_MODEL=canonical');
                \App\Core\TableNamespace::clearCache();

                $migrator   = new \App\Core\EntityMigrator();
                $entityDefs = $this->schemaDesigner->toEntityMigratorFormat($schema, $appId);
                foreach ($entityDefs as $entityDef) {
                    try {
                        $migrator->migrateEntity($entityDef, true);
                        $migrationCount++;
                    } catch (\Throwable $me) {
                        $migrationErrors[] = ($entityDef['name'] ?? '?') . ': ' . $me->getMessage();
                    }
                }
            } catch (\Throwable $me) {
                $migrationErrors[] = $me->getMessage();
            } finally {
                // Restore original storage model for the rest of the request
                putenv("PROJECT_STORAGE_MODEL=$prevStorageModel");
                \App\Core\TableNamespace::clearCache();
            }

            // Register version
            $version = $this->versionManager->registerApp($tenantId, $appId, $schema, [
                'business_name' => $businessName,
                'mixins'        => $mixins,
                'system_prompt' => $systemPrompt,
                'requirements'  => $requirements,
            ]);
            $this->versionManager->recordMigration($tenantId, $appId, 'initial_install_v' . $version, 'applied');

            // Clear interview state
            $this->interviewState->clear($tenantId, $sessionId);

            $agentCount  = count($result['agents_created'] ?? []);
            $agentAreas  = implode(', ', array_column($result['agents_created'] ?? [], 'area'));
            $entityCount = count(array_filter($schema['entities'] ?? [], fn($e) => ($e['table'] ?? '') !== 'audit_log'));

            $mixinNames = [];
            foreach ($mixins as $mid) {
                $m = $this->catalog->getMixin((string) $mid);
                if ($m) {
                    $mixinNames[] = $m['name'];
                }
            }
            $mixinLine = $mixinNames !== [] ? ' + ' . implode(' + ', $mixinNames) : '';
            $label     = $businessName !== '' ? " para **{$businessName}**" : '';

            $dbLine = $migrationCount > 0
                ? "- ✅ {$migrationCount} tabla(s) creadas en base de datos (listas para guardar información)\n"
                : "- ⚠️ Tablas pendientes de creación (migración diferida)\n";
            $dbErrorLine = $migrationErrors
                ? "- ⚠️ Errores en migración: " . implode('; ', $migrationErrors) . "\n"
                : '';

            return [
                'ok'               => true,
                'app_id'           => $appId,
                'version'          => $version,
                'agents'           => $result['agents_created'] ?? [],
                'migration_count'  => $migrationCount,
                'migration_errors' => $migrationErrors,
                'action'           => 'respond_local',
                'reply'            => "🎉 **¡App creada exitosamente!**\n\n"
                                    . "**{$appDef['name']}{$mixinLine}**{$label} — versión `{$version}`\n\n"
                                    . "**Configurado:**\n"
                                    . "- ✅ {$agentCount} agente(s) especializado(s): {$agentAreas}\n"
                                    . $dbLine
                                    . $dbErrorLine
                                    . "- ✅ Roles de acceso: Admin, Operador, Solo consulta\n"
                                    . "- ✅ Sistema personalizado para tu negocio\n"
                                    . "- ✅ Memoria completa guardada — puedo retomar en cualquier sesión sin empezar desde cero\n"
                                    . "- ✅ Actualizable en el futuro sin perder datos\n\n"
                                    . "¿Por dónde quieres empezar? Puedo ayudarte a crear tus primeros registros, configurar usuarios o explicarte cómo funciona cada módulo.\n\n"
                                    . "---\n"
                                    . "**¿Quieres que otras empresas puedan usar esta app?**\n"
                                    . "Puedo publicarla en el **Marketplace de SUKI** para que cualquier empresa la vea, se suscriba y la use.\n"
                                    . "Escribe **\"publicar en marketplace\"** cuando estés listo, o **\"mantener privada\"** para uso exclusivo de tu empresa.",
            ];

        } catch (\Throwable $e) {
            $this->interviewState->clear($tenantId, $sessionId);
            return [
                'ok'    => false,
                'reply' => '❌ Error técnico al crear la app: ' . $e->getMessage(),
                'action' => 'respond_local',
            ];
        }
    }

    // ─── Propose new app type ─────────────────────────────────────────────────

    private function handlePropose(array $args, string $tenantId = ''): array
    {
        $definition = is_array($args['definition'] ?? null) ? $args['definition'] : $args;
        $result     = $this->catalog->proposeApp((array) $definition, $tenantId);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'action' => 'respond_local', 'reply' => 'No pude guardar el nuevo tipo de app: ' . ($result['error'] ?? 'error desconocido')];
        }
        $app   = $result['app'] ?? [];
        $appId = $result['app_id'] ?? '';

        return [
            'ok'     => true,
            'app_id' => $appId,
            'status' => 'draft',
            'action' => 'respond_local',
            'reply'  => "✅ App **{$app['name']}** creada y guardada en borrador (sector: {$app['sector']}).\n\n"
                      . "Está en modo **privado** — solo tú puedes verla y usarla por ahora.\n\n"
                      . "**¿Quieres publicarla en el Marketplace?**\n"
                      . "Cuando esté lista, dime **\"publicar en marketplace\"** y quedará visible para que cualquier empresa se suscriba.\n"
                      . "O dime **\"mantener privada\"** si es solo para tu empresa.",
        ];
    }

    // ─── Publish draft app to Marketplace ────────────────────────────────────

    private function handlePublish(array $args, string $tenantId): array
    {
        $appId = trim((string) ($args['app_id'] ?? ''));

        // Si no viene app_id, buscar el último draft de este tenant
        if ($appId === '') {
            $drafts = $this->catalog->getDraftsByTenant($tenantId);
            if (empty($drafts)) {
                return [
                    'ok'     => false,
                    'action' => 'respond_local',
                    'reply'  => "No tienes apps en borrador para publicar. Crea una app primero.",
                ];
            }
            if (count($drafts) === 1) {
                $appId = $drafts[0]['id'] ?? '';
            } else {
                $names = implode(', ', array_column($drafts, 'name'));
                return [
                    'ok'     => false,
                    'action' => 'respond_local',
                    'reply'  => "Tienes varias apps en borrador: {$names}. ¿Cuál quieres publicar? Indícame el nombre.",
                ];
            }
        }

        $result = $this->catalog->publishApp($appId, $tenantId);

        if (!($result['ok'] ?? false)) {
            return [
                'ok'     => false,
                'action' => 'respond_local',
                'reply'  => "No pude publicar la app: " . ($result['error'] ?? 'error desconocido'),
            ];
        }

        $app = $result['app'] ?? [];
        return [
            'ok'        => true,
            'app_id'    => $appId,
            'status'    => 'available',
            'action'    => 'respond_local',
            'reply'     => "🚀 **¡App publicada en el Marketplace!**\n\n"
                         . "**{$app['name']}** ya aparece en el Marketplace de SUKI.\n"
                         . "Cualquier empresa puede verla, suscribirse y empezar a usarla.\n\n"
                         . "La pueden encontrar en: `/marketplace`",
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Infers app_id from text using the catalog's sector field.
     * No hardcoded map — reads from the live catalog.
     */
    private function inferAppIdFromCatalog(string $text): string
    {
        $text = strtolower($text);
        $apps = $this->catalog->listApps();

        // Match by sector slug or keywords in description
        foreach ($apps as $app) {
            if (!is_array($app)) {
                continue;
            }
            $sector = strtolower((string) ($app['sector'] ?? ''));
            $name   = strtolower((string) ($app['name'] ?? ''));
            $desc   = strtolower((string) ($app['description'] ?? ''));

            // Direct sector match
            if ($sector !== '' && str_contains($text, explode('_', $sector)[0])) {
                return (string) $app['id'];
            }
            // Match by distinctive words in name
            foreach (explode(' ', $name) as $word) {
                if (strlen($word) > 4 && str_contains($text, strtolower($word))) {
                    return (string) $app['id'];
                }
            }
        }

        return 'suki_erp'; // generic fallback
    }
}
