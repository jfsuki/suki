# PROJECT_MEMORY

## Objective
Build a low-code/AI-first platform that generates business apps from JSON contracts.
Core principle: chat-first usage, visual UI only when needed (tables, reports, charts).
Users get a friendly UI; the engine hides complexity and keeps backward compatibility.

## Non-negotiable rules
- **No rewrite from scratch**. Only incremental, backward-compatible changes.
- **JSON is the single source of truth** (FormContract/EntityContract are canonical).
- **Do not break existing keys/behavior**; only additive changes.
- **No SQL in app layer**; persistence must go through Kernel/ORM/Repository.
- **Keep framework/project separation** (framework = kernel, project = app).
- **Tenant Isolation**: Every query must be index-friendly and tenant-scoped.
- **Neuron IA Alignment**: Follow the 6-step message flow and semantic routing.

## Canonical governance
- Canonical memory source: `docs/PROJECT_MEMORY.md`.
- Architecture laws: `docs/canon/`.
- Mandatory developer pre-check: `php framework/scripts/codex_self_check.php --strict`.
- Temporary testing artifacts policy: only under `framework/tests/tmp/`.

## Vision (Neuron IA integrated)
- **Meta**: Generar y operar apps ERP por conversación (chat-first) con panel visual para lo necesario.
- **North Star**: Cero tecnicismos para el usuario final, IA como capa de razonamiento (no caja negra), cambios determinísticos preferidos.
- **UX**: 1 pregunta mínima, chat para operar, panel visual para ver datos.
- **Arquitectura**: Router multi-capa (Reglas -> Semántica -> LLM) + Command Bus + Repositorios tipados.

## Current modules and responsibilities
- **FormGenerator** (framework/app/Core/FormGenerator.php): orchestrates form + grids + summary rendering.
- **ChatAgent** (framework/app/Core/ChatAgent.php): Orchestrator following Neuron IA 6-step flow.
- **ConversationMemory** (framework/app/Core/ConversationMemory.php): Thread-based persistence (Tenant/Session).
- **LLMRouter** (framework/app/Core/LLM/LLMRouter.php): Multi-provider failover and strict JSON enforcement.
- **IntentRouter** (framework/app/Core/IntentRouter.php): Multi-layer intent classification.
- **CommandBus** (framework/app/Core/CommandBus.php): Deterministic execution of module actions.
- **Database Kernel** (Database.php, QueryBuilder.php, EntityMigrator.php): Multi-tenant persistence layer.

## Checkpoint History (Detailed)

### Checkpoint (2026-03-29, Neuron IA Integration)
- Adopted `ConversationMemory` pattern for history persistence.
- Implemented 6-step invariant message flow in `ChatAgent`.
- Standardized `SystemPrompt` in external files.
- Integrated `IntentClassifier` for strict JSON routing.

### Checkpoint (2026-03-03, pre-P0 secrets hardening)
- Baseline frozen for pending tracking before structural hardening.
- Open blockers at snapshot time:
  - `domain_training_sync` drift blocking full green in `run.php`.
  - `project/.env` tracked in git (security risk).
  - `ConversationGateway.php` still oversized and pending deeper split.

### Canon consolidation (2026-03-02)
- Recognized `docs/canon/*` and `docs/contracts/*` as official governance sources.
- Mandates: Queue and idempotency, deterministic router, intent classes (EXECUTABLE|INFORMATIVE).

*(... Historial previo de checkpoints de febrero truncado para brevedad, disponible en `docs/memory/PROJECT_MEMORY.md` si se requiere detalle profundo de la evolución inicial ...)*

## What works today
- Forms + grids render desde JSON.
- Entity contracts validados (schema).
- DB Kernel MVP (QueryBuilder + Repository + tenant scope).
- Onboarding builder determinístico con "Fast Path" para sectores comunes.
- LLM Smoke Test exitoso en Mistral, Gemini, OpenRouter y DeepSeek.
- Limpieza profunda de artefactos temporales y logs.

## Missing / Partial
- Manual testing end-to-end completo.
- UI avanzada (drag & drop editor).
- Migraciones `diff/alter` automáticas (actualmente solo CREATE IF NOT EXISTS).
- Auditoría profunda de persistencia FORM_STORE/GRID_STORE.
