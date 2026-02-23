# PROJECT_MEMORY_CANONICAL

Version: 2026-02-23
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
