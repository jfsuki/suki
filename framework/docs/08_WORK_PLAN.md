# Work Plan (Updated)

## Current maturity (assessment)
- Core runtime (forms + grids) : DONE
- Summary dependency ordering : DONE
- Framework/project separation : DONE
- Validation engine backend : DONE
- Persistence (DB kernel + CRUD) : DONE
- Routing/view conventions : DONE
- Chat local-first + slot filling : DONE
- Acid tests for chat : DONE
- Report runtime + dashboard runtime : DONE
- Integrations base + Alanube client : MVP DONE

## Roadmap (next phases)
### Phase 1 — Stabilize logic
1) Build/Use guard strict (done)
2) Entity exists validation before CRUD (done)
3) Help/Buttons from registry real (done)
4) Manual testing end-to-end (pending)

### Phase 2 — UX builder
1) Visual builder drag/drop (pending)
2) Inspector properties full (pending)
3) Import wizard UI (pending)

### Phase 3 — Production hardening
1) Migration diff/alter (pending)
2) Security hardening (CSRF/IDOR/rate limit) (pending)
3) Metadata contracts in DB + versioning (pending)
4) DB anti-colapso guardrails (in progress)
5) DB health monitoring diario (in progress)
6) Namespace -> canonical migration plan (done doc / pending execution)

## Definition of Done (DoD)
- Backward compatible
- Minimal patch
- Tests passed (acid + manual)
- No rewrite

## P0 closure update (2026-02-23)
- Runtime validation connected:
  - ConversationGateway now validates/persists working memory snapshot using `framework/contracts/agents/WORKING_MEMORY_SCHEMA.json`.
- Conversational QA hardening:
  - Added `framework/tests/chat_real_20.php` (20 real conversation suites builder/app).
  - Post QA gate now runs `chat_real_20` before `db_health`.
- Baseline integration contracts restored:
  - `project/contracts/integrations/alanube_main.integration.json`
  - `project/contracts/invoices/facturas_co.invoice.json`
- Memory persistence hardening:
  - `MemoryRepositoryInterface` + `SqlMemoryRepository` added.
  - ConversationGateway stores state/profile/working-memory in SQL multi-tenant tables.
  - ConversationGateway moved `latam_lexicon_overrides` to `mem_tenant` (legacy file only as one-time fallback).
  - Shared tenant learning key `agent_shared_knowledge` added for cross-agent knowledge reuse.
  - Legacy JSON migration script added: `framework/scripts/migrate_memory_json_to_sql.php`.
- Domain consulting layer:
  - `domain_playbooks.json` expanded with `solver_intents` + `sector_playbooks` (6 verticals mata-excel).
  - New project knowledge mirror: `project/contracts/knowledge/domain_playbooks.json`.
  - Builder command interno: instalacion de playbooks sin terminal (`InstallPlaybook`).
  - Worker de autoaprendizaje promueve `agent_shared_knowledge` -> `training_overrides`.
- P0 structural extraction (2026-02-23):
  - `ModeGuardPolicy` extraido para centralizar bloqueo BUILD/USE.
  - `BuilderOnboardingFlow` extraido como puerta de entrada del onboarding builder.
  - pruebas dedicadas agregadas: `framework/tests/mode_guard_policy_test.php` y `framework/tests/builder_onboarding_flow_test.php`.

## P1 kickoff update (2026-02-23)
- Routing and command execution seams added:
  - `IntentRouter` + `IntentRouteResult` integrados en `ChatAgent`.
  - `CommandBus` + `MapCommandHandler` integrados en `ChatAgent` (dispatch centralizado).
- Dedicated tests added:
  - `framework/tests/intent_router_test.php`
  - `framework/tests/command_bus_test.php`
- Unit runner expanded to validate P1 components before full QA gate.

## P1.2 update (2026-02-23)
- Command handlers separados por responsabilidad:
  - `CreateEntityCommandHandler`
  - `CreateFormCommandHandler`
  - `InstallPlaybookCommandHandler`
  - `CrudCommandHandler`
- `ChatAgent` reduce el switch de `executeCommandPayload` a autenticacion (legacy compatible).
- `dispatchCommandPayload` ahora pasa contexto explicito para handlers (guards de modo, existencia entidad/form, builder services, playbook installer).
- Pruebas del bus actualizadas para validar despacho real por handler y fallback de compatibilidad auth.

## Knowledge update (2026-02-23)
- Base de conocimiento de dominio ampliada:
  - `framework/contracts/agents/domain_playbooks.json` ahora incluye 3 verticales nuevas (`IGLESIA`, `EDUCACION`, `SERVICIOS_PRO`) con `solver_intents`, triggers, blueprints y KPIs.
  - bloques nuevos `builder_guidance` y `guided_conversation_flows` para estandarizar respuestas de onboarding/no-tecnico.
- Espejo por proyecto normalizado:
  - `project/contracts/knowledge/domain_playbooks.json` sincronizado con la base canonica y JSON valido.
- Entrenamiento conversacional reforzado:
  - `framework/contracts/agents/conversation_training_base.json` actualizado a `v0.3.7` con intents sectoriales y smoke tests nuevos.
- Capa de carga de playbook ajustada:
  - `ConversationGateway::loadDomainPlaybook` ahora acepta override de `builder_guidance`, `guided_conversation_flows` y `discovery` desde proyecto.

## Runtime update (2026-02-23)
- `builder_guidance` deja de ser solo contenido estatico: ahora el gateway lo enruta en tiempo real en modo builder.
- Plantillas de guidance refinadas para:
  - tipo de campo (precio/telefono/fecha),
  - relaciones entre tablas,
  - performance por campo,
  - importaciones, reportes/documentos, FE CO y seguridad.
- `UnitTestRunner` agrega prueba `builder_guidance` para validar recomendacion de tipo `decimal` y relaciones interpoladas.

## Runtime update (2026-02-23, guidance transaccional)
- `ConversationGateway::routeBuilderGuidance` ahora emite `pending_command` para:
  - `RELATIONS` / `MASTER_DETAIL` -> `CreateRelation`
  - `PERFORMANCE` -> `CreateIndex`
- Confirmacion `si` ejecuta comando por flujo transaccional existente (`builder_pending_command`).
- Command bus extendido con handlers:
  - `CreateRelationCommandHandler`
  - `CreateIndexCommandHandler`
- `EntityMigrator` agrega primitivas incrementales para materializar cambios de guidance:
  - `ensureField` (agrega columna FK si falta)
  - `ensureIndex` (crea indice idempotente).

## Runtime update (2026-02-23, flow control profesional)
- Se agrega control de navegacion global:
  - `cancelar`, `atras`, `reiniciar`, `retomar`.
- Se formaliza persistencia de flujo (`flow_runtime`) para retoma por paso exacto.
- Se agrega `feedback_pending` + captura `me sirvio` / `no me sirvio` para cerrar loop de calidad.
- Pruebas:
  - `UnitTestRunner::checkFlowControl`.
  - `framework/tests/flow_control_test.php`.
- Canal externo bootstrap:
  - endpoint `channels/telegram/webhook` conectado a `ChatAgent` con sesion estable por `chat_id`.

## P2 kickoff update (2026-02-23)
- Observabilidad operativa SQL (multi-tenant/proyecto):
  - nuevos componentes: `TelemetryService`, `MetricsRepositoryInterface`, `SqlMetricsRepository`.
  - nuevas tablas en registry DB: `ops_intent_metrics`, `ops_command_metrics`, `ops_guardrail_events`, `ops_token_usage`.
  - `ChatAgent` ahora registra:
    - latencia/status por intent,
    - latencia/status/bloqueos por comando,
    - eventos de guardrail,
    - tokens + costo estimado por proveedor.
- Transicion canónica DB (proyectos nuevos):
  - `ProjectRegistry` soporta `storage_model` (`legacy|canonical`) por proyecto.
  - feature flag `DB_CANONICAL_NEW_PROJECTS=1` activa `canonical` para proyectos nuevos.
  - `TableNamespace` respeta `storage_model`:
    - `legacy` mantiene namespace fisico por proyecto,
    - `canonical` usa tabla logica + migration key canonica.
  - scope `app_id` agregado para entidades/grids tenant-scoped en modo `canonical` (migrador + repositorio + runtime CRUD/dashboard).
- Pruebas dedicadas P2:
  - `framework/tests/observability_metrics_test.php`
  - `framework/tests/canonical_storage_new_project_test.php`
  - integradas al `UnitTestRunner` (`observability_metrics`, `canonical_storage_new_project`).
- Explotacion UI/API cerrada:
  - `GET/POST /api/chat/quality` ahora incluye `ops_summary` desde `SqlMetricsRepository::summary(...)`.
  - nuevo endpoint `GET/POST /api/chat/ops-quality` para consumo operativo puro.
  - `chat_builder.html` y `chat_app.html` muestran p95 intent/comando, bloqueos guardrail, fallback LLM y tokens.

## Workflow Builder program kickoff (2026-02-24)
- Referencia objetivo documentada: `framework/docs/WORKFLOW_BUILDER_PROGRAM.md`.
- Alcance del programa:
  - compilar NL a contrato canonico ejecutable (`workflow.contract.json`),
  - soportar editor dual (chat + visual graph),
  - mantener runtime deterministico y auditable.
- Plan incremental (sin reescritura):
  1) WB-0: contrato + reglas de validacion + pruebas de compatibilidad.
  2) WB-1: executor DAG con trazas por nodo y paralelismo seguro.
  3) WB-2: compilador NL->diff de contrato con confirmacion transaccional.
  4) WB-3: visual builder (grafo + inspector + referencias tipadas `@`).
  5) WB-4: templates/remix + historial de revisiones/restauracion.
- Criterios de gate por release:
  - no romper forms/grids/entities actuales,
  - validar presupuesto de tokens/costo por nodo/sesion,
  - observar p95 por nodo + errores de guardrail en SQL metrics.

## WB-0 technical execution (2026-02-24)
- Contrato canonico base implementado:
  - schema nuevo `framework/contracts/schemas/workflow.schema.json`.
  - valida `meta`, `nodes`, `edges`, `assets`, `theme`, `versioning`.
- Validacion backend implementada:
  - `framework/app/Core/WorkflowValidator.php`.
  - validacion contract-first via JSON Schema (sin ejecutar runtime).
- QA dedicada WB-0:
  - `framework/tests/workflow_contract_test.php` (caso valido + invalido + compatibilidad schema repository).
  - `UnitTestRunner` agrega `workflow_contract` al suite principal.
- Estado:
  - WB-0 base en verde.

## WB-0.1 technical execution (2026-02-24)
- Validacion semantica DAG activada en `WorkflowValidator`:
  - bloquea `node.id` duplicados,
  - bloquea `edges` hacia nodos inexistentes,
  - bloquea self-loops,
  - exige `mapping` no vacio y consistente,
  - detecta ciclos por topological traversal (Kahn).
- QA WB-0.1:
  - `framework/tests/workflow_contract_test.php` agrega casos semanticos (edge invalido + ciclo).
  - `UnitTestRunner::checkWorkflowContract` valida schema + semantica.

## Conversational hardening for unknown businesses (2026-02-24)
- Nuevo flujo guiado antes de crear app cuando no hay perfil exacto:
  - tarea de estado `unknown_business_discovery`,
  - cuestionario de requisitos (discovery + technical requirements),
  - construccion de `technical_brief` y `technical_prompt` en estado.
- `ConversationGateway`:
  - corrige bucle de confirmacion por perfil errado con prioridad a correccion de usuario,
  - evita sesgo de negocio previo durante investigacion (`unknown_business_force_research`),
  - si Gemini no esta disponible, crea borrador local y pide confirmacion.
- `BuilderOnboardingFlow`:
  - respeta `unknown_business_discovery` como flujo activo y evita short-circuit por playbook.
- Contratos de dominio:
  - `unknown_business_protocol` ampliado con `technical_requirements_questions` y `research_prompt_template`.
- Pruebas:
  - `framework/tests/unknown_business_discovery_test.php`.
  - `framework/tests/chat_golden.php` agrega escenario de correccion de negocio + discovery.

## WB-1 runtime foundation (2026-02-24)
- Executor DAG implementado:
  - `framework/app/Core/WorkflowExecutor.php`.
  - orden topologico por niveles, soporte de nodos (`input`, `generate`, `output`, `tool`, `transform`, `decision`).
  - trazas estructuradas por nodo (`status`, `duration_ms`, `toolCalls`, `tokenUse`, `outputs`).
- Explotacion API:
  - `POST /api/workflow/execute`
  - `POST /api/workflow/validate`
- Pruebas:
  - `framework/tests/workflow_executor_test.php`
  - `UnitTestRunner::checkWorkflowExecutor`.

## WB-2 compiler NL->contract diff (2026-02-24)
- Compilador incremental implementado:
  - `framework/app/Core/WorkflowCompiler.php`.
  - salida en modo propuesta (`PROPOSAL_READY`, `changes[]`, `proposed_contract`, `needs_confirmation=true`).
- Integracion runtime:
  - `POST /api/workflow/compile`
  - `POST /api/workflow/apply`
  - comando interno `CompileWorkflow` + handler dedicado.
  - en builder, `ConversationGateway` detecta solicitud de workflow y abre confirmacion transaccional.
- Pruebas:
  - `framework/tests/workflow_compiler_test.php`
  - `framework/tests/chat_golden.php` agrega flujo de compilacion y guardado.

## OpenAPI -> contract automation (2026-02-24)
- Importador automatico implementado:
  - `framework/app/Core/OpenApiIntegrationImporter.php`.
  - detecta `base_url`, auth (`bearer/api_key/basic/oauth2/custom`) y endpoints.
  - valida contrato final con `IntegrationValidator`.
- Integracion por chat y API:
  - comando `ImportIntegrationOpenApi` + handler.
  - `INTEGRATION_SETUP` en `ConversationGateway` ahora crea `pending_command` transaccional.
  - endpoint `POST /api/integrations/import_openapi`.
- Hardening SSRF basico:
  - bloqueo localhost/IP privada en `doc_url`,
  - allowlist opcional por `OPENAPI_IMPORT_ALLOWED_HOSTS`.

## WB-3/WB-4 baseline operativa (2026-02-24)
- Persistencia/versionado:
  - `framework/app/Core/WorkflowRepository.php` (save/list/load/history/restore).
- Diff de revisiones:
  - `workflow/diff` + `WorkflowRepository::diff(...)` para comparar nodos/aristas entre revisiones.
- Remix/templates:
  - `workflow/templates` + `workflow/remix`.
  - templates base:
    - `framework/contracts/workflows/templates/sales_quote.workflow.template.json`
    - `framework/contracts/workflows/templates/daily_kpi.workflow.template.json`
- UI baseline:
  - nuevo `project/public/workflow_builder.html` (editor dual baseline: compile/apply/validate/execute/list/history/remix).

## Seguridad y canales externos (2026-02-24)
- Guard de API:
  - `framework/app/Core/ApiSecurityGuard.php`.
  - rate-limit por ruta (chat/channels/default),
  - CSRF opcional por feature flag (`API_CSRF_ENFORCE=1`),
  - auth enforcement para mutaciones sensibles.
- IDOR baseline:
  - `chat/message` bloquea `user_id/tenant_id/project_id` distintos a sesion autenticada.
- Canal externo adicional:
  - `channels/whatsapp/webhook` (verificacion + inbound -> `ChatAgent` + respuesta WhatsApp Cloud API).
- E2E y hardening adicional:
  - `requestData()` cacheado para permitir CSRF body/header consistente en guard de API.
  - pruebas E2E nuevas:
    - `framework/tests/workflow_api_e2e_test.php`
    - `framework/tests/security_channels_e2e_test.php`

## Security hardening production (2026-02-24)
- Rate-limit central persistente:
  - nuevo `SecurityStateRepository` sobre SQLite (`project/storage/security/security_state.sqlite`).
  - `ApiSecurityGuard` consume buckets centralizados en DB y mantiene fallback file-based solo si falla repositorio.
- CSRF obligatorio en modo estricto:
  - `API_SECURITY_STRICT=1` activa CSRF en mutaciones sensibles aunque `API_CSRF_ENFORCE` no este explicitado.
  - `API_CSRF_ENFORCE=0` permite override controlado para compatibilidad temporal.
- Firma de webhooks + anti-replay:
  - WhatsApp POST valida `X-Hub-Signature-256` cuando existe `WHATSAPP_APP_SECRET`.
  - Telegram/WhatsApp guardan nonce anti-replay con TTL para ignorar duplicados.
  - nuevas variables opcionales: `TELEGRAM_REPLAY_TTL_SEC`, `WHATSAPP_REPLAY_TTL_SEC`.
- QA dedicado:
  - `framework/tests/security_state_repository_test.php`
  - `framework/tests/api_security_guard_test.php` (strict CSRF + central rate-limit)
  - `framework/tests/security_channels_e2e_test.php` (secret/firma/replay + CSRF strict en import OpenAPI)

## P2.1 performance hardening (2026-02-24)
- Observabilidad extendida:
  - `SqlMetricsRepository::summary(...)` ahora expone `p99_latency_ms` para intents y comandos.
- Suite de estres conversacional/canales:
  - `framework/tests/chat_stress.php` + `framework/tests/chat_stress_worker.php`.
  - `framework/tests/channels_stress.php` + `framework/tests/channels_stress_worker.php`.
  - reporte consolidado `framework/tests/perf_stress_report.php`.
- Gate opcional de performance:
  - `qa_gate.php` ejecuta `perf_stress_report` cuando `QA_INCLUDE_STRESS=1`.
- Variables de tunning:
  - chat: `CHAT_STRESS_CONCURRENCY`, `CHAT_STRESS_ITERATIONS`, `CHAT_STRESS_P95_MAX_MS`, `CHAT_STRESS_P99_MAX_MS`, `CHAT_STRESS_MAX_ERROR_RATE`.
  - canales: `CHANNEL_STRESS_CONCURRENCY`, `CHANNEL_STRESS_ITERATIONS`, `CHANNEL_STRESS_P95_MAX_MS`, `CHANNEL_STRESS_P99_MAX_MS`, `CHANNEL_STRESS_MAX_ERROR_RATE`.

## LLM staging smoke block (2026-02-24)
- Nueva prueba dedicada:
  - `framework/tests/llm_smoke.php`.
- Cobertura:
  - fallback de router en modo `auto` (proveedor primario falla -> Gemini),
  - salida estructurada contract-first (`ROLE/CONTEXT/INPUT/CONSTRAINTS/OUTPUT_FORMAT/FAIL_RULES`),
  - tokens/costo en telemetria SQL (`ops_token_usage` + summary).
- Integracion opcional en gate:
  - `QA_INCLUDE_LLM_SMOKE=1 php framework/scripts/qa_gate.php post`.
- Reporte:
  - `framework/tests/tmp/llm_smoke_report.json`.

## Release hygiene runtime/test (2026-02-24)
- Nuevo script reproducible:
  - `framework/scripts/cleanup_runtime_artifacts.php`.
- Objetivo:
  - limpiar artefactos de pruebas y cache runtime antes de empaquetar release.
- Modos:
  - `--check` (dry run),
  - `--apply` (limpieza base),
  - `--include-runtime-state` y `--include-generated-contracts` para limpieza extendida.

## Conversational scale-up (2026-02-24)
- Base sectorial ampliada:
  - `domain_playbooks` pasa de 9 a 15 sectores.
  - `solver_intents` pasa de 9 a 15.
  - cada solver intent con `utterances=45` y `hard_negatives` por sector.
- Sincronizacion training:
  - `sync_domain_training.php` ahora copia `hard_negatives` a `conversation_training_base.json`.
- QA extendida:
  - nueva suite `framework/tests/chat_real_100.php`.
  - gate opcional: `QA_INCLUDE_CHAT_REAL_100=1 php framework/scripts/qa_gate.php post`.

## Siguiente bloque recomendado
- WB-3 full visual graph: drag/drop real, conexiones con mouse y referencias tipadas `@` en inspector.
- WB-4 advanced: diff visual entre revisiones y restauracion selectiva por nodo.

## Execution checklist
- [x] Summary dependency ordering stable
- [x] Framework/project separation (paths + webroots)
- [x] Validation engine MVP
- [x] Routing/view standard + migration notes
- [x] Persist submit payload (CommandLayer)
- [x] Load/hydrate (Repository)
- [x] Entity contract schema + registry loader
- [x] DB config loader (env -> PDO)
- [x] DB Kernel QueryBuilder MVP
- [x] CRUD base (Repository + allowlist)
- [x] Tenant scoping everywhere
- [x] Migration store (schema_migrations)
- [x] Audit log MVP
- [x] Chat local-first + help JSON
- [x] Acid test conversational
- [ ] Manual testing end-to-end
- [ ] Visual builder drag/drop
- [ ] Migration diff/alter
- [ ] Security hardening
- [ ] Metadata contracts in DB
- [x] Namespace por proyecto (DB_NAMESPACE_BY_PROJECT)
- [x] Guardrail de tablas por proyecto (EntityMigrator)
- [x] Script de salud DB (framework/tests/db_health.php)
- [ ] Automatizar db_health (cron diario)
- [ ] Ejecutar migracion gradual a modelo canonico (tenant_id + app_id)
