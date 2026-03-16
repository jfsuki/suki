# SUKI_CONTROL_TOWER
Status: CANONICAL
Version: 1.0.0
Date: 2026-03-16
Scope: Canon for SUKI Control Tower as the supervised orchestration, audit, and development-governance layer.

## 1) Purpose
Define the canonical Control Tower that supervises:
- development runs,
- sprint execution,
- agent collaboration during development,
- file hygiene and temporary artifacts,
- AgentOps incidents,
- production criticism,
- checkpointed execution state.

The Control Tower is a governance and supervision layer.
It is not a second execution engine.

## 2) Scope
This canon covers:
- Tower Supervisor,
- Sprint Manager,
- Multi Coder Orchestrator,
- Reviewer Agent,
- File Custodian,
- AgentOps Monitor,
- Production Critic,
- Checkpoint Engine,
- Decision Engine,
- sprint status summaries,
- integration with AgentOps, router governance, and semantic memory hygiene.

This canon does not replace:
- `docs/canon/SUKI_ARCHITECTURE_CANON.md`
- `docs/canon/AGENTOPS_GOVERNANCE.md`
- `docs/canon/AGENT_COLLABORATION_ENGINE.md`
- `docs/canon/PRODUCTION_LEARNING_PIPELINE.md`

## 3) Architecture Role
SUKI Control Tower SHALL supervise development and operational quality without bypassing:
- deterministic routing,
- skill governance,
- CommandBus,
- PHP execution authority,
- multitenant isolation.

Control Tower outputs are governance artifacts, checkpoints, and decisions.
Business execution remains in the PHP kernel.

## 4) Non-Negotiable Laws
### 4.1 LLM vs PHP Boundary
- LLM interprets, plans, proposes, and reviews.
- PHP validates, normalizes, calculates, persists, and executes.
- Control Tower SHALL NOT grant executable authority directly to any LLM output.

### 4.2 Multitenant Scope Law
Every Control Tower artifact SHALL include, at minimum:
- `tenant_id`
- `project_id`
- `app_id`
- `run_id`
- `sprint_id`

Additional request scope MAY include:
- `ticket_id`
- `session_id`
- `message_id`
- `user_id`

### 4.3 Router Monitoring Law
Control Tower SHALL monitor route compliance through:
- `route_path`
- `route_path_steps`
- `gate_decision`
- `supervisor_status`
- `fallback_reason`

It SHALL detect drift between canonical router law and active runtime policy.

### 4.4 Anti-Schema-Explosion Law
Control Tower SHALL flag app-generation violations against:
1. shared multitenant core tables
2. `custom_fields`
3. JSON fields / `custom_data`
4. new table only with explicit justification

### 4.5 Additive Evolution Law
- Control Tower artifacts SHALL be additive and traceable.
- Existing contracts SHALL NOT be broken.
- Snapshots, checkpoints, tickets, and review decisions SHALL remain auditable.

## 5) Canonical Components
### 5.1 Tower Supervisor
Responsibilities:
- receive development or architecture tasks,
- create `run_id`,
- create `sprint_id`,
- classify priority,
- perform initial architecture impact assessment,
- record detected drift and blocked conditions.

Primary artifact:
- `control_tower_run`

### 5.2 Sprint Manager
Responsibilities:
- break a run into executable tickets,
- define scope, acceptance criteria, risks, and file targets,
- keep tickets incremental and bounded.

Primary artifact:
- `sprint_ticket`

### 5.3 Multi Coder Orchestrator
Responsibilities:
- coordinate proposal collection from `Coder_OpenAI` and `Coder_Gemini`,
- compare proposal coverage,
- ensure neither proposal gains direct execution authority,
- hand proposals to Reviewer Agent.

Primary artifact:
- `coder_proposal`

### 5.4 Reviewer Agent
Responsibilities:
- validate incremental compatibility,
- validate JSON contract preservation,
- validate SUKI canon alignment,
- validate multitenant and router policy compliance,
- reject destructive rewrites.

Primary artifact:
- `review_decision`

Allowed decisions:
- `approve`
- `reject`
- `fix_required`

### 5.5 File Custodian
Responsibilities:
- register created, modified, snapshot, backup, and temporary files,
- assign TTL to temporary artifacts,
- report hygiene debt,
- preserve auditability for generated artifacts.

Primary artifact:
- `file_registry`

### 5.6 AgentOps Monitor
Responsibilities:
- inspect runtime metrics and traces,
- detect latency spikes, loop patterns, token-cost anomalies, and repeated failures,
- open incidents for supervision and sprint review.

Primary artifact:
- `agentops_incident`

### 5.7 Production Critic
Responsibilities:
- analyze production feedback and recurring bugs,
- consolidate repeated bad responses,
- detect regressions and improvement candidates,
- propose supervised next actions.

Primary artifact:
- `production_critic_finding`

### 5.8 Checkpoint Engine
Responsibilities:
- persist resumable execution state,
- support pause/resume across days,
- preserve decision trace continuity,
- anchor auditability of long-running sprints.

Primary artifact:
- `checkpoint_state`

### 5.9 Decision Engine
Responsibilities:
- evaluate sprint readiness after review, incidents, checkpoints, and file hygiene,
- decide whether sprint continues, closes, or blocks.

Primary artifact:
- `sprint_decision`

Allowed states:
- `CLOSED`
- `CONTINUE`
- `BLOCKED`
- `NEEDS_REVIEW`

### 5.10 Sprint Status Summary
Responsibilities:
- return a concise and auditable summary of sprint state,
- include files changed, risks, incidents, backups, temporaries, and next steps.

Primary artifact:
- `sprint_status_summary`

## 6) Canonical Folder Structure
Governance and contract sources:
- `docs/canon/SUKI_CONTROL_TOWER.md`
- `docs/contracts/control_tower_contract.json`
- `framework/contracts/schemas/control_tower_run.schema.json`
- `framework/contracts/schemas/sprint_ticket.schema.json`
- `framework/contracts/schemas/coder_proposal.schema.json`
- `framework/contracts/schemas/review_decision.schema.json`
- `framework/contracts/schemas/file_registry.schema.json`
- `framework/contracts/schemas/agentops_incident.schema.json`
- `framework/contracts/schemas/production_critic_finding.schema.json`
- `framework/contracts/schemas/checkpoint_state.schema.json`
- `framework/contracts/schemas/sprint_decision.schema.json`
- `framework/contracts/schemas/sprint_status_summary.schema.json`

Deterministic validation layer:
- `framework/app/Core/ControlTowerArtifactValidator.php`

Suggested runtime artifact root for future implementations:
- `project/storage/control_tower/{tenant_id}/{app_id}/{sprint_id}/{run_id}/`

This canon defines the layout but does not require immediate runtime storage creation.

## 7) Execution Flow
Canonical flow:
1. Tower Supervisor receives a task and opens `run_id` + `sprint_id`.
2. Sprint Manager emits bounded `sprint_ticket` artifacts.
3. Multi Coder Orchestrator gathers coder proposals.
4. Reviewer Agent validates proposals and issues `review_decision`.
5. File Custodian registers touched files, snapshots, backups, and temporaries.
6. AgentOps Monitor opens incidents from telemetry when needed.
7. Production Critic emits recurring bug or regression findings.
8. Checkpoint Engine persists resumable execution state.
9. Decision Engine emits sprint decision.
10. Sprint Status Summary returns the auditable sprint picture.

## 8) Integration with AgentOps
Control Tower SHALL integrate with `docs/contracts/agentops_metrics_contract.json`.

Minimum observed fields:
- `route_path`
- `gate_decision`
- `latency_ms`
- `tool_calls_count`
- `fallback_reason`
- `supervisor_status`
- `supervisor_flags`
- `supervisor_reasons`
- `needs_regression_case`
- `needs_memory_hygiene`

Control Tower MAY aggregate these fields into:
- reviewer evidence,
- sprint incident logs,
- production criticism,
- learning candidates after hygiene.

## 9) Integration with Router
Control Tower SHALL not alter routing order by itself.

It SHALL:
- monitor route coherence against the active policy contract version,
- flag route-policy mismatches,
- record `route_path` and `gate_decision`,
- block sprint closure when router drift threatens governance.

## 10) Integration with Vector Memory
Control Tower MAY propose memory writes only after hygiene.

Allowed memory targets:
- `agent_training`
- `sector_knowledge`
- `user_memory`

Rules:
- no raw tenant operational data to shared memory,
- no unsanitized production traces to `agent_training`,
- only abstract patterns or approved findings may be promoted,
- `user_memory` remains tenant/user/app scoped.

## 11) Failure Modes
Relevant Control Tower failure modes include:
- missing scope identifiers,
- inconsistent `app_id` / `project_id` mapping,
- missing backup for risky work,
- stale temporary files with no TTL,
- reviewer approval without contract checks,
- router drift not recorded,
- cross-tenant artifact contamination,
- checkpoint state without resumable context,
- sprint closure while incidents remain unresolved.

Safe outcomes are:
- `BLOCKED`
- `NEEDS_REVIEW`
- checkpoint-and-pause
- remediation ticket generation

## 12) Detected Canon Drift
### 12.1 Router Stage Drift
Reviewed sources currently differ:
- `docs/canon/ROUTER_CANON.md` states `cache -> rules -> rag -> llm`
- `docs/contracts/router_policy.json` states `cache -> rules -> skills -> rag -> llm`
- `framework/app/Core/IntentRouter.php` operates with `cache -> rules -> skills -> rag -> llm`

Control Tower SHALL record this drift instead of silently resolving it.
Monitoring MUST therefore preserve:
- canonical router reference,
- active policy version,
- active runtime route path.

### 12.2 `project_id` vs `app_id` Drift
Current operational runtime is primarily keyed by `tenant_id + project_id + mode + user_id`.
Control Tower architecture requires explicit `app_id` in addition to `project_id`.

Safe additive interpretation:
- `project_id` remains mandatory for current runtime compatibility,
- `app_id` is mandatory in new Control Tower artifacts,
- when canonical app storage is not yet split, `app_id` MAY mirror `project_id`.

## 13) Non-Goal
This canon does not introduce:
- a new business execution engine,
- autonomous coder execution,
- direct Control Tower writes into shared memory without hygiene,
- a bypass around router, skills, CommandBus, or PHP kernel execution.
