# AGENTOPS_GOVERNANCE
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-02  
Scope: AgentOps governance for multi-tenant operation and reliability.

## 1) Governance Objective
AgentOps SHALL provide enforceable control over:
- Observability
- Quality
- Memory promotion
- Version traceability
- Rollback safety

## 2) Structured Logging (Mandatory)
Every interaction and action SHALL be logged with tenant scope.

Mandatory log fields:
- `timestamp`
- `tenant_id`
- `project_id`
- `mode`
- `user_id`
- `session_id`
- `message_id`
- `intent_name`
- `action_name`
- `action_type` (`EXECUTABLE|INFORMATIVE|FORBIDDEN`)
- `router_stage` (`cache|rules|rag|llm`)
- `akp_refs` (list, optional)
- `risk_level`
- `result_status`
- `latency_ms`
- `cost_estimate`

Missing `tenant_id` in action logs is a policy violation.

## 3) Versioning As Laws (No Rewrites)
Governance artifacts are versioned as legal layers:
1. Base law (`v1.x`)
2. Addenda (`A-YYYYMMDD-N`)
3. Interpretation note (optional, no behavior change)

Rules:
- Existing law text SHALL NOT be overwritten.
- New behavior SHALL be introduced as additive addenda.
- Deprecated rules remain traceable until explicit retirement addendum.

## 4) Memory Promotion Pipeline (Mandatory)
Promotion path:
1. Candidate
2. Hygiene
3. Supervisor
4. Publication

### 4.1 Candidate
Source: chat traces, corrections, unresolved intents.  
Requirements: raw evidence linked to `tenant_id`, `intent_name`, and outcome.

### 4.2 Hygiene
Checks:
- PII removal or masking
- duplicate collapse
- contradiction detection
- format normalization

### 4.3 Supervisor
Human or policy supervisor validates:
- factual consistency
- contract compatibility
- safety and legal constraints

### 4.4 Publication
Approved memory is published to AKP/policy datasets with version tags and rollback pointer.

## 5) Mandatory Metrics
AgentOps dashboard SHALL report:
- `% JSON valido`
- `% uso correcto AKP`
- `tasa fallback LLM`
- `costo por conversacion`
- `tasa hallucinacion detectada`

Canonical formulas:
- `% JSON valido = valid_json_responses / total_structured_responses`
- `% uso correcto AKP = responses_with_valid_akp_refs / responses_requiring_rag`
- `tasa fallback LLM = llm_stage_calls / total_router_calls`
- `costo por conversacion = sum(token_cost + tool_cost) / total_conversations`
- `tasa hallucinacion detectada = flagged_hallucinations / audited_responses`

## 6) Regression Dataset (Mandatory)
A regression dataset SHALL exist and SHALL be executed on each release candidate.

Dataset minimum coverage:
- EXECUTABLE intents (CRUD + integrations + billing)
- INFORMATIVE intents (reports + support)
- FORBIDDEN policy checks
- BUILDER vs APP mode boundaries
- multi-tenant isolation scenarios

Release is blocked if critical regression cases fail.

## 7) Immediate Rollback Policy
Rollback SHALL trigger immediately when:
- hallucination rate crosses critical threshold,
- forbidden actions are executed,
- schema-valid JSON rate drops below agreed SLO,
- tenant isolation breach is detected.

Rollback requirements:
- switch to last stable prompt/policy/AKP set,
- preserve incident logs,
- open postmortem with root cause and corrective addendum.

## 8) Compliance Cadence
- Per interaction: log + metric emit.
- Daily: quality summary by tenant/project.
- Per release: full regression dataset + rollback readiness check.

## 9) Non-Goal
This file does not define DB tables or operational workers. It defines governance only.
