# TEXT_OS_ARCHITECTURE
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-02  
Scope: System canon for SUKI TextOS (documentation only, no runtime implementation).

## 1) System Definition
SUKI is a Text Operating System (TextOS): a multi-tenant AI Application Operating System where chat is the primary interface and the Execution Engine is the only execution authority.

The system SHALL translate user text into governed intents, validate them against contracts, and execute only through deterministic engine paths.  
The system SHALL NOT execute business actions directly from LLM output.

## 2) Immutable Principles
1. Contracts are the source of truth.
2. Deterministic routing is default; probabilistic routing is fallback.
3. Execution authority is centralized in the Execution Engine.
4. AI may propose; AI SHALL NOT bypass guards or adapters.
5. Multi-tenant isolation is mandatory (`tenant_id` everywhere).
6. Auditability is mandatory for internal and external actions.
7. Additive evolution only; backward compatibility is required.
8. Deny-by-default for unknown, unsafe, or uncontracted actions.
9. Idempotency is mandatory for executable side effects.
10. Quality gates are mandatory before release.

## 3) System Layers
### 3.1 Deterministic Core
Components:
- Intent normalization and policy guards.
- Deterministic router.
- Command orchestration and adapter invocation.
- Queue dispatcher with retry policy.
- Idempotency validator.
- Contract and schema validators.

Responsibility:
- Decide whether an action is executable, informative, or forbidden.
- Enforce mode boundaries (BUILDER vs APP).
- Enforce role and permission checks.

### 3.2 Knowledge Layer (Qdrant + AKP)
Definitions:
- AKP = Agent Knowledge Pack (versioned knowledge package used by retrieval).
- Qdrant = vector retrieval engine for AKP evidence.

Canonical retrieval profile:
- `embedding_model = gemini-embedding-001`
- `output_dimensionality = 768`
- `distance = Cosine`
- `retrieval_mode = tenant-scoped first, shared fallback second`

Knowledge Layer SHALL:
- Retrieve evidence chunks with provenance (`akp_id`, `akp_version`, `source_id`, `chunk_id`, `score`).
- Return only evidence that satisfies tenant/project policy scope.
- Never authorize execution by itself; it only provides evidence for router decisions.

### 3.3 LLM Fallback Layer
LLM is last resort only when deterministic + retrieval paths cannot resolve with required evidence.

LLM Fallback SHALL:
- Receive minimal context capsule.
- Return strict JSON contract output.
- Be blocked from direct side effects.
- Produce confidence and evidence references (or explicit `NEEDS_CLARIFICATION`).

### 3.4 AgentOps Layer
AgentOps governs observability, quality, rollback, and promotion of knowledge/memory.

AgentOps SHALL provide:
- Structured logs per `tenant_id`.
- Regression datasets and quality dashboards.
- Prompt/policy/AKP version governance.
- Immediate rollback controls.

## 4) Mandatory Router Order
Formal deterministic order:
1. Cache
2. Rules/DSL
3. RAG (Qdrant + AKP)
4. LLM fallback (last resort)

A lower stage SHALL NOT be skipped unless policy explicitly disables an upper stage.

## 5) Queue and Idempotency Coexistence
For every EXECUTABLE action:
- Queue is responsible for delivery, retry, and backoff.
- Idempotency is responsible for exactly-once side effects.

Mandatory command envelope fields:
- `tenant_id`
- `project_id`
- `intent_name`
- `action_name`
- `idempotency_key`
- `payload_hash`
- `requested_at`
- `risk_level`

Retry policy SHALL keep the same `idempotency_key`.  
Any repeated message with same key + same hash MUST resolve to the same final state.

## 6) Multi-Tenant Enforcement
`tenant_id` is mandatory in:
- Runtime requests
- Queue payloads
- Memory writes
- Audit logs
- Metrics
- Retrieval filters

Cross-tenant reads/writes are forbidden unless explicitly marked as system-global and read-only.

## 7) Action Classification
- `EXECUTABLE`: may produce side effects if all gates pass.
- `INFORMATIVE`: no side effects; returns guidance/report/context only.
- `FORBIDDEN`: denied by policy, mode, role, or safety constraints.

Classification output SHALL be explicit and machine-readable before any downstream execution.

## 8) Mandatory Quality Gates
### 8.1 Pre-change Gate
- `php framework/scripts/codex_self_check.php --strict`

### 8.2 Runtime Gates
- Contract/schema validation
- Mode guard
- Role/permission guard
- Evidence minimum guard
- Idempotency guard (for EXECUTABLE)
- Audit log write guard

### 8.3 Release Gate
- `php framework/tests/run.php`
- `php framework/tests/chat_acid.php`
- `php framework/tests/chat_golden.php`
- `php framework/tests/db_health.php`

If any mandatory gate fails, release SHALL be blocked.

## 9) Non-Goal of This Document
This canon defines architecture only. It does not create tables, workers, adapters, or operational services.
