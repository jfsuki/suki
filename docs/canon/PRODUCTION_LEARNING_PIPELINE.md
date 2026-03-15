# PRODUCTION_LEARNING_PIPELINE
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-15  
Scope: Canon for converting operational signals into reviewed, privacy-safe system improvements.

## 1) Purpose
Formalize how SUKI learns from production safely without turning tenant data into shared knowledge and without allowing uncontrolled self-modification.

## 2) Scope
This canon covers:
- signal sources,
- hygiene and sanitization,
- candidate promotion logic,
- privacy guarantees,
- publication targets,
- supervision and rollback boundaries.

This canon does not authorize direct production changes without review.

## 3) Canonical Objective
The Production Learning Pipeline SHALL:
- reduce future LLM dependence,
- improve sector packs, builder guidance, rules, and regression coverage,
- preserve tenant isolation,
- publish only abstract and reviewed improvements.

## 4) Signal Sources
Allowed signal sources include:
- router decisions,
- AgentOps telemetry,
- guardrail events,
- unresolved intents,
- user corrections,
- simulation failures,
- skill execution failures,
- workflow validation failures,
- business discovery outputs,
- support and recovery patterns,
- approved regression findings.

Signals are evidence, not automatic permission to change production behavior.

## 5) Canonical Pipeline Stages
### 5.1 Signal Ingestion
Operational signals enter improvement memory with tenant and outcome references.

Representative lifecycle alignment:
- `metrics`
- `improvement_memory`

### 5.2 Candidate Creation
Signals are converted into structured learning candidates.

Representative lifecycle alignment:
- `learning_candidate(pending)`

### 5.3 Hygiene and Sanitization
Mandatory hygiene checks include:
- PII masking or removal,
- duplicate collapse,
- contradiction detection,
- abstraction of tenant-specific details into reusable patterns,
- normalization of format and evidence references.

### 5.4 Review and Supervision
Candidates SHALL be reviewed for:
- factual consistency,
- contract compatibility,
- safety and legal compliance,
- privacy safety,
- cross-tenant applicability.

Representative lifecycle alignment:
- `review`
- `approved candidate`

### 5.5 Publication Proposal
Approved candidates become structured improvement proposals.

Representative lifecycle alignment:
- `improvement_proposal(open)`

Publication targets may include:
- sector pack updates,
- builder guidance updates,
- rule additions,
- skills catalog proposals,
- regression datasets,
- policy addenda,
- AKP or knowledge-pack updates.

### 5.6 Regression and Rollout Gate
Published improvements SHALL pass the relevant validation, regression, and release gates before production use.

## 6) Privacy Guarantees
- Raw tenant operational data SHALL NOT be promoted to shared knowledge.
- Tenant credentials SHALL NEVER enter learning artifacts.
- Tenant memory remains tenant-scoped.
- Published learning SHALL contain abstract patterns, normalized failures, or reviewed reusable rules only.
- Cross-tenant aggregation is allowed only after masking and abstraction.

## 7) Allowed Improvement Targets
### 7.1 Sector Packs
Examples:
- new domain terminology,
- reusable document flows,
- updated business controls.

### 7.2 Builder Improvements
Examples:
- better field-type guidance,
- missing relation recommendations,
- stronger onboarding prompts.

### 7.3 Agent and Router Improvements
Examples:
- clarification improvements,
- hard-negative additions,
- new deterministic rules,
- better skill routing evidence.

### 7.4 Quality Assets
Examples:
- new regression cases,
- better anomaly thresholds,
- refined evaluation datasets.

## 8) Safety Boundaries
- No learning artifact may directly execute production changes.
- No tenant signal may bypass hygiene and review.
- No automatic contract mutation is allowed solely from production telemetry.
- No policy, skill, or knowledge publication is valid without traceable versioning and rollback pointer.

## 9) Integration with Other SUKI Layers
### 9.1 Business Discovery
Production failures may reveal missing discovery questions or sector distinctions.

### 9.2 Sector Packs
Reviewed patterns may enrich reusable sector assets.

### 9.3 App Builder Engine
Builder guidance may be improved from repeated design-time or post-deployment gaps.

### 9.4 Business Simulation Engine
Simulation blockers are high-value learning signals for future prevention.

### 9.5 Specialized Agents and Collaboration
Repeated clarifications, conflicts, or fallback patterns may produce safer guidance or better routing rules.

### 9.6 SUKI Control Tower
Control Tower supervises promotion quality, rollback readiness, and anomaly detection.

## 10) Failure Modes
Common failure modes include:
- privacy leakage,
- overfitting to one tenant,
- contradictory learned patterns,
- noisy or weak evidence,
- promotion without regression coverage,
- publishing unsupported capability claims.

Any privacy or tenant isolation breach SHALL block publication immediately.

## 11) Governance and Observability
Suggested minimum events:
- `learning.signal.ingested`
- `learning.candidate.created`
- `learning.hygiene.blocked`
- `learning.review.approved`
- `learning.review.rejected`
- `learning.proposal.published`
- `learning.rollback.triggered`

Suggested minimum fields:
- `tenant_id` when source-scoped,
- `project_id`,
- `signal_type`,
- `candidate_id`,
- `review_status`,
- `publication_target`,
- `version_ref`,
- `result_status`

## 12) Detected Canon Drift
No direct contradiction was found in the reviewed sources for production learning.

The current source set already describes:
- AgentOps promotion and hygiene governance,
- an improvement-memory lifecycle,
- rollback and quality expectations.

This document consolidates those pieces into one dedicated canonical pipeline document without altering behavior.

## 13) Non-Goal
This canon defines the learning governance pipeline only. It does not implement workers, queues, database tables, or automatic promotions by itself.
