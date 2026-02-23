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
