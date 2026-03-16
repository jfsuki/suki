# INCIDENT_MANAGEMENT
Status: CANONICAL
Version: 1.0.0
Date: 2026-03-16
Scope: Canon for governed incident detection, classification, escalation, resolution, and audit across SUKI.

## 1) Purpose
Formalize the SUKI Incident Management system as the operational governance framework that detects, classifies, escalates, resolves, and audits incidents without creating a second execution authority.

Incident Management exists to:
- protect tenant safety,
- preserve routing and execution governance,
- support supervised recovery,
- feed Control Tower, Product Support, and Production Learning with traceable evidence.

Incident Management is not:
- a business execution engine,
- an excuse to bypass router, skills, CommandBus, or PHP kernel authority,
- a transport, vendor, or ticketing implementation by itself.

## 2) Scope
This canon covers:
- incident families and severity levels,
- detection and evidence principles,
- workflow and response boundaries,
- Control Tower integration,
- multitenant safety,
- audit and long-term governance.

This canon does not define:
- a specific monitoring vendor,
- a ticketing channel,
- a storage engine,
- a human org chart,
- runtime behavior changes by itself.

## 3) Incident Types
Incident Management SHALL cover, at minimum, the following incident families:

### 3.1 Execution Integrity
Examples:
- governed skill failure,
- command-path failure,
- repeated side-effect anomaly,
- blocked or inconsistent execution result.

### 3.2 Routing and Governance
Examples:
- route-policy mismatch,
- evidence-gate failure,
- forbidden-action attempt,
- canonical drift affecting supervised safety.

### 3.3 Agent Behavior
Examples:
- excessive fallback,
- delegation loop,
- unsupported capability claim,
- unsafe or contradictory agent findings.

### 3.4 Tenant Safety
Examples:
- tenant-scope violation,
- cross-tenant evidence leakage,
- unsafe memory exposure,
- credential-scope breach risk.

### 3.5 Quality and User Impact
Examples:
- unresolved intent cluster,
- repeated user friction,
- degraded support outcomes,
- recurring regression affecting safe operation.

### 3.6 Learning and Hygiene
Examples:
- unsafe promotion candidate,
- hygiene failure,
- contradictory learning signal,
- review-blocked memory publication.

### 3.7 Release and Simulation
Examples:
- simulation-blocking defect,
- regression gate failure,
- rollback-triggering condition,
- unresolved release-readiness blocker.

The incident taxonomy is additive. It may grow without breaking the current `agentops_incident` schema categories already used by Control Tower.

## 4) Severity Levels
Canonical severity baseline SHALL be:
- `low`
- `medium`
- `high`
- `critical`

Interpretation:
- `low`: localized issue, limited impact, no immediate tenant-safety or release risk.
- `medium`: repeated or cross-component degradation, but safe fallback or bounded containment still exists.
- `high`: material operational risk, blocked workflow, or strong governance concern requiring prompt containment.
- `critical`: tenant isolation risk, forbidden execution, unsafe side effect, severe security issue, or release-blocking condition that requires immediate containment and supervised rollback readiness.

Future sub-levels MAY be added additively, but these four levels remain the canonical baseline.

## 5) Detection Model
Incident detection SHALL be evidence-driven and multi-source.

Allowed signal sources include:
- AgentOps telemetry and supervisor flags,
- router and gate decisions,
- guardrail and safety-layer events,
- specialized-agent and collaboration traces,
- simulation failures,
- Control Tower review and sprint-governance artifacts,
- user-reported support and recovery patterns,
- repeated regression failures,
- production-learning hygiene blocks.

Detection MAY use deterministic rules, thresholds, anomaly policies, and supervised review.
LLM may help summarize or group evidence, but it SHALL NOT become the final execution or remediation authority.

Minimum incident evidence SHOULD preserve, when relevant:
- `tenant_id`
- `project_id`
- `app_id` when available
- source component
- symptoms
- route or gate context
- severity
- detected time
- current status

## 6) Incident Workflow
Canonical incident workflow:
1. Detect
2. Classify
3. Acknowledge
4. Contain
5. Remediate
6. Verify
7. Resolve or explicitly ignore with reason

Operationally, current machine-readable artifacts may expose statuses such as:
- `open`
- `acked`
- `resolved`
- `ignored`

Workflow rules:
- high and critical incidents SHALL be visible to Control Tower immediately,
- incidents that threaten release readiness SHALL block closure or deployment until governed resolution,
- critical incidents SHOULD trigger postmortem and rollback-readiness review,
- ignored incidents SHALL remain auditable with explicit rationale.

## 7) Response Automation
Allowed automation is governed containment and supervision support, not autonomous business execution.

Allowed automated responses include:
- opening or updating a governed incident artifact,
- attaching evidence and scope metadata,
- surfacing the incident in Control Tower Dashboard,
- blocking sprint closure or release-gate progression,
- switching to safer fallback or clarification paths,
- generating checkpoint-and-pause state,
- queuing regression, hygiene, or review work,
- proposing rollback or remediation steps for supervised approval.

Forbidden automation includes:
- direct business-side effects outside governed execution paths,
- unsupervised contract mutation,
- cross-tenant data access to investigate an incident,
- raw tenant data promotion into shared memory,
- silent incident suppression when safety risk remains.

## 8) Control Tower Integration
Incident Management SHALL integrate with SUKI Control Tower as a governed incident lane.

Minimum integration expectations:
- AgentOps Monitor may open incidents from telemetry and supervisor evidence.
- Control Tower Dashboard visualizes incident queues, severity, and status.
- Decision Engine considers unresolved incidents before `CLOSED` or release-ready outcomes.
- Checkpoint Engine preserves incident context for paused or long-running work.
- Production Critic may correlate repeated incidents into supervised improvement findings.
- File Custodian and backup references MAY support investigation for risky changes when applicable.

Incident Management does not replace Control Tower. It gives Control Tower a formal operational incident model.

## 9) Multitenant Safety
Incident Management SHALL preserve strict multitenant isolation.

Non-negotiable rules:
- `tenant_id` is mandatory in operational scope.
- Incident evidence SHALL remain scoped to the affected tenant and app/project context.
- Shared supervisory views MAY aggregate only sanitized and policy-approved signals.
- Raw tenant credentials, unrestricted memory, and cross-tenant operational payloads SHALL NOT enter shared incident views.
- Shared learning from incidents SHALL use abstract patterns only after hygiene and review.

When canonical app scope exists, `app_id` SHALL be preserved alongside `project_id`.

## 10) Audit Log Principles
Incident handling SHALL remain fully auditable.

Audit principles:
- no silent overwrite of incident history,
- lifecycle transitions remain traceable,
- detection evidence and remediation steps remain linked,
- severity changes require rationale,
- ignored or closed incidents remain reviewable,
- rollback, postmortem, checkpoint, and regression references stay connected when they exist.

Incident records SHOULD preserve, when relevant:
- `incident_id`
- `tenant_id`
- `project_id`
- `app_id`
- source component
- severity
- category or family
- route path and gate decision
- symptoms
- detected timestamp
- actor or supervisor reference for status change
- result or verification note

## 11) Failure Modes
Relevant incident-management failure modes include:
- missing tenant scope in incident evidence,
- under-detection of harmful patterns,
- alert storms without correlation or deduplication,
- false healthy state caused by incomplete telemetry,
- critical incident downgraded without rationale,
- incident resolved in status but not in reality,
- incident workflows exposing raw tenant data,
- automated containment being mistaken for business execution authority,
- router drift or tenant-safety breaches not escalating quickly enough.

Safe outcomes include:
- explicit `needs_review` style escalation in supervising layers,
- degraded read-only visibility,
- blocked closure or release progression,
- checkpoint-and-pause while evidence is gathered,
- supervised rollback readiness review.

## 12) Governance Notes
- Incident Management is a governance framework, not an execution path.
- The governed runtime path remains `cache -> rules -> skills -> rag -> llm`.
- If older canons or contracts still show a different router sequence, incident handling SHOULD preserve that as drift evidence instead of flattening it.
- The LLM may summarize incidents, but PHP-governed rules, telemetry, and supervised decisions remain authoritative.
- Incident Management integrates with AgentOps, Control Tower, Dashboard, Product Support, Simulation, and Production Learning without replacing any of them.
- All evolution in this area SHALL be additive, backward-compatible, and auditable.

## 13) Long-Term Evolution
Long-term evolution SHOULD move toward:
- better correlation across incidents, user friction, and regression evidence,
- stronger deduplication and clustering of repeated incidents,
- richer role-aware dashboard views for supervised triage,
- tighter alignment between incident classes and simulation/regression coverage,
- stronger rollback-readiness evidence for critical incidents,
- privacy-safe fleet-level pattern analysis after hygiene,
- clearer bridges from resolved incidents to reusable learning and product support improvements.

This evolution remains bounded by the core SUKI laws:
- LLM interprets,
- PHP executes,
- tenants remain isolated,
- agents remain controlled copilots,
- governance does not bypass execution authority.
