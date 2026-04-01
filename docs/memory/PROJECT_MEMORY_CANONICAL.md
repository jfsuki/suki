# PROJECT_MEMORY_CANONICAL

Version: 2026-02-24
Scope: Canonical memory and governance baseline for SUKI AI-AOS.

## 1) Canonical identity
- SUKI is an AI Application Operating System (AI-AOS).
- Chat is the primary interface.
- Execution Engine is the authority.
- AI decides; AI never executes directly.

## 2) Non-negotiable philosophy
- Simple > Complex
- Declarative > Imperative
- Contracts > ad-hoc code
- Security by design > security by patching
- Human UX > technical UI
- Reliability > creativity

## 3) Market-level goals
1) Non-technical users create and operate real apps by natural language.
2) Internal apps run safely in production.
3) External software is integrated and operated via API contracts.
4) SUKI acts as a trusted operator and support assistant.
5) LLM dependence decreases over time via contracts, registry, and memory.

## 4) Architecture authority
### 4.1 Contracts (JSON)
- JSON is the single source of truth.
- Contracts define forms, grids, rules, validations, permissions, relations, workflows.
- External integrations are API contracts.
- Compatibility is never broken.
- Evolution is extension-first, never rewrite.

### 4.2 Execution Engine
- Interprets internal and external contracts.
- Executes rules without eval.
- Orchestrates external APIs in controlled flows.
- Applies pre/post validations on every action.
- Persists through repositories/query builder (no direct SQL in app logic).
- Centralizes security, RBAC, and auditing.

### 4.3 AI and agents
- AI translates human language to valid intents/actions.
- AI orchestrates specialized agents.
- AI cannot bypass execution engine guards.
- AI cannot execute actions outside contracts.

## 5) Agent roles (canonical)
- Architect Agent: coherence, contracts, architecture consistency.
- App Builder Agent: natural language to valid app contracts.
- Operator Agent: operates internal/external systems under contract.
- Integration Agent: external API docs to integration contracts.
- Support Agent: human guidance, error explanation, contextual help.
- Auditor Agent: permissions, integrity, traceability, forensic logs.

## 6) Decision hierarchy
1) System identity and philosophy
2) Market objectives
3) Contract definitions
4) Execution engine constraints
5) Conversational UX
6) Implementation details

If a decision conflicts with a higher level, it is invalid.

## 7) Security baseline
- No eval.
- No direct SQL from app/business layers.
- No direct execution by AI.
- Declarative validations.
- Contract-based permissions.
- Full audit trail for internal and external actions.

## 8) Build and use boundaries
- BUILD mode creates/changes structure (contracts/forms/entities/views).
- USE mode operates runtime data only.
- APP mode cannot create schema.
- BUILDER mode cannot execute business CRUD outside build scope.

## 9) Integration baseline
- External software is first-class via API contracts.
- Canonical flow: Intent -> Action -> Adapter API -> Result.
- Sandbox/production selection is tenant-scoped.
- Every external action must be auditable.

## 10) Operational governance
- Mandatory pre-check: `php framework/scripts/codex_self_check.php --strict`
- Mandatory backup before DB/data-impacting changes: `php framework/scripts/db_backup.php`
- Mandatory QA gate before push.
- Temporary testing artifacts only in `framework/tests/tmp/`.
- Changes are incremental and auditable.

## 11) Validation of solution quality
- If it cannot be explained to a non-technical user, it is invalid.
- If an action cannot be audited, it is invalid.
- If a capability is not in registry/contracts, it cannot be claimed.

## 12) Engineering execution constitution (2026-02-23)
- Philosophy baseline (C + C++ + Rust):
  - C: contracts pequenos, estables, explicitos; sin comportamiento oculto.
  - C++: rutas de ejecucion optimizadas y predecibles; optimizacion interna al engine.
  - Rust: seguridad por defecto, errores tipados/explicitos, permisos deny-by-default.
- Execution paths:
  - Fast path (default): acciones conocidas sin IA.
  - Slow path (fallback): IA solo propone estructura; kernel valida/decide/ejecuta.
- AI role:
  - IA propone; nunca ejecuta ni inventa datos faltantes.
  - Si falta informacion critica: pedir aclaracion minima.
- Prompt constitution obligatorio:
  - ROLE, CONTEXT, INPUT, CONSTRAINTS, OUTPUT_FORMAT, FAIL_RULES.
- Operational token economy:
  - contexto minimo, vocabulario cerrado, resumen sobre replay historico,
  - dependencia de IA debe bajar con contratos/memoria/cache.

## 13) Workflow Builder canon (2026-02-24)
- Strategic reference: Opal-like workflow UX is valid as benchmark, not as copy.
- Canonical rule: NL is compiled to contract diff first; NL never executes directly.
- Mandatory pipeline:
  1) Plan
  2) Validate
  3) Execute
- Design-time vs runtime split is strict:
  - design-time can propose/edit nodes/edges/assets;
  - runtime can only execute validated workflow revision.
- New canonical contract target: `workflow.contract.json` with:
  - `nodes[]`, `edges[]`, `assets[]`, `theme`, `versioning`.
- Typed references are mandatory:
  - `@` selector inserts only valid typed outputs and auto-wires edges.
- Guardrails before execution:
  - schema/type validation,
  - permission/tool allowlist validation,
  - token/cost budget validation,
  - auditability validation.
- Runtime observability:
  - per-node traces, p50/p95 latency, guardrail errors, token usage.
- Compatibility rule:
  - existing forms/grids/entities contracts remain valid and unchanged.

## 14) Canon and contracts consolidation (2026-03-02)
- Official governance references (docs only):
  - `docs/canon/TEXT_OS_ARCHITECTURE.md`
  - `docs/canon/ACTION_CONTRACTS.md`
  - `docs/canon/AGENTOPS_GOVERNANCE.md`
  - `docs/canon/ROUTER_CANON.md`
  - `docs/canon/VERSIONING_POLICY.md`
  - `docs/canon/RUNTIME_ARTIFACTS_POLICY.md`
  - `docs/canon/TRAINING_DATASET_STANDARD.md`
- Official machine-readable governance contracts:
  - `docs/contracts/router_policy.json`
  - `docs/contracts/action_catalog.json`
  - `docs/contracts/agentops_metrics_contract.json`
  - `docs/contracts/sector_training_dataset_standard.json`

## 15) Canon mandates (explicit)
- Queue + idempotency are mandatory for executable actions.
- Deterministic router order is mandatory: cache -> rules -> rag -> llm.
- LLM is last resort only.
- Intent-class action governance is mandatory:
  - `EXECUTABLE`
  - `INFORMATIVE`
  - `FORBIDDEN`
- Quality gates are mandatory pre-release.
- AgentOps telemetry, regression checks, and rollback are mandatory.
- Versioning is law/addenda style (additive, traceable, rollback-ready).

## 16) CANON VERSION
- source_contract: `docs/contracts/router_policy.json`
- version: `1.0.0`
- effective_date: `2026-03-02`

## 17) CONTRACTS VERSION
- `router_policy`: version `1.0.0`, effective_date `2026-03-02`
- `action_catalog`: version `1.0.0`, effective_date `2026-03-02`
- `agentops_metrics_contract`: version `1.0.0`, effective_date `2026-03-02`

## 18) NON-CODE POLICY
- Runtime artifacts governance source: `docs/canon/RUNTIME_ARTIFACTS_POLICY.md`.
- Mandatory rule: runtime cache/sqlite state is not source code and must not be committed.

## 19) Secrets Policy (2026-03-03)
- Secrets never in git; rotate immediately if exposed.
- `.env` is local runtime state only; commit only `.env.example` with placeholders.

## 20) 3-World Isolation Architecture (2026-04-01)
- **World 1: Framework (Gateway/Marketplace)**
  - Managed by `framework/public/index.php`.
  - Roles: Central auth portal, Marketplace, Creator onboarding.
- **World 2: Tower (Admin NOC)**
  - Managed by `tower/public/index.php`.
  - Roles: Master administration, Creator approval, Enterprise activation.
  - Access: Stealth route, protected by Master Key session.
- **World 3: Project (Builder / App)**
  - Managed by `project/public/index.php`.
  - Roles: AI Builder (Creation Tool), Chat App (End-user interface).
  - Security: Guarded by world-specific session logic; direct file access is strictly blocked.
- **Canonical Law:** Strict isolation between worlds. Sessions are not shared between World 2 and World 3 to prevent privilege escalation. Routers serve as the sole execution gatekeepers.
