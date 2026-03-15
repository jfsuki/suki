# AGENT_COLLABORATION_ENGINE
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-15  
Scope: Canon for safe, deterministic multi-agent collaboration inside SUKI tenant applications.

## 1) Purpose
Formalize how multiple specialized agents collaborate during a request without creating a second execution authority or bypassing the governed router and PHP kernel.

## 2) Scope
This canon covers:
- App Agent coordination authority,
- specialist findings model,
- CollaborationEnvelope contract,
- delegation policy,
- conflict prevention,
- observability,
- relationship with router, skills, and CommandBus.

This canon does not implement a new runtime engine by itself.

## 3) Canonical Responsibilities
The Agent Collaboration Engine SHALL:
- keep the App Agent as sole coordinator,
- allow specialists to contribute findings only,
- merge request-scoped context safely,
- prevent duplicate or conflicting actions,
- produce at most one final executable proposal per request,
- emit Control Tower and AgentOps traces.

## 4) Canonical Role Model
### 4.1 App Agent
The App Agent is the sole coordinator and the only agent allowed to compose the final command proposal for execution.

The App Agent MAY:
- classify intent,
- decide whether delegation is needed,
- request specialist findings,
- merge findings,
- ask one critical clarification,
- compose the final governed command proposal.

### 4.2 Specialist Agents
Specialists MAY:
- normalize domain parameters,
- validate context,
- resolve domain references,
- return evidence, warnings, or missing data.

Specialists SHALL NOT:
- execute side effects,
- dispatch CommandBus directly,
- delegate to other specialists autonomously,
- perform business math,
- access cross-tenant data,
- emit raw SQL.

## 5) CollaborationEnvelope
Collaboration is request-scoped and SHALL use a minimal envelope.

Minimum fields:
- `tenant_id`
- `project_id`
- `mode`
- `user_id`
- `session_id`
- `message_id`
- `intent_name`
- `action_type`
- `workflow_stage`
- `entity_refs`
- `transaction_refs`
- `extracted_params`
- `evidence_refs`
- `delegation_depth`
- `pending_command_id`
- `idempotency_key`

The envelope SHALL NOT contain:
- raw tenant memory beyond active request need,
- credentials,
- cross-tenant data,
- unrestricted historical conversation replay,
- direct execution handles.

## 6) Delegation Protocol
Canonical sequence:
1. User interacts with App Agent.
2. App Agent frames the intent under router and policy governance.
3. App Agent decides whether specialist findings are needed.
4. Specialists receive only the scoped CollaborationEnvelope.
5. Specialists return findings, not execution.
6. App Agent merges findings and resolves conflicts.
7. If one critical datum is missing, App Agent asks one critical question.
8. If evidence and guards are satisfied, App Agent composes the final command proposal.
9. Execution proceeds through skills, CommandBus, and the PHP kernel.

Example:
- User: `Sell 5 batteries to ACME Corp and bill net-30.`
- App Agent requests:
  - Sales Agent: customer and sales context
  - Inventory Agent: stock verification
  - Accounting Agent: payment terms normalization
- App Agent composes `CreateInvoiceCommand`
- PHP kernel executes through governed runtime

## 7) Specialist Finding Contract
Specialist output SHOULD be structured and limited to findings such as:
- `agent_key`
- `finding_type`
- `normalized_params`
- `entity_refs`
- `evidence_refs`
- `warnings`
- `missing_critical_data`
- `confidence`
- `result_status`

Finding output is advisory to the App Agent. It is not executable authority.

## 8) Delegation Policy
- Only the App Agent may delegate.
- Specialists operate in a star topology under the App Agent.
- Specialist-to-specialist delegation is forbidden.
- If a specialist detects the need for another domain, it returns that need as a finding; the App Agent decides.
- Delegation SHALL remain bounded by configured depth and count limits.

Suggested guardrails:
- `max_delegation_depth <= 2`
- `max_specialists_per_request` bounded
- `same_route_repeat_count` bounded

## 9) Conflict Prevention
### 9.1 Single-Writer Principle
There SHALL be one executable proposal owner per request: the App Agent.

### 9.2 Duplicate Prevention
Executable proposals SHALL use:
- request fingerprinting,
- idempotency keys,
- duplicate suppression before dispatch.

### 9.3 Circular Delegation Prevention
Circular delegation is forbidden by policy.

### 9.4 Deterministic Merge Order
When findings disagree, merge precedence SHALL be:
1. explicit user-confirmed data
2. contract or registry authority
3. validated domain evidence
4. specialist findings
5. probabilistic inference last

### 9.5 Execution Locks
High-risk executable proposals SHOULD use scoped execution locks keyed by tenant, project, action family, and business reference.

## 10) Relationship with Router, Skills, and CommandBus
- Collaboration SHALL NOT bypass router governance.
- Collaboration SHALL NOT bypass skills.
- Collaboration SHALL NOT bypass CommandBus.
- Collaboration SHALL NOT create a free-form multi-agent execution loop.
- All real execution SHALL go through governed skills, then CommandBus, then the PHP kernel.

Operational interpretation:
- collaboration is a governed interpretation layer,
- specialists may help resolve or enrich the request,
- execution authority remains outside agents.

## 11) Observability and Control Tower Signals
Suggested minimum collaboration events:
- `agent.collaboration.started`
- `agent.delegation.requested`
- `agent.delegation.completed`
- `agent.delegation.denied`
- `agent.conflict.detected`
- `agent.command.composed`
- `agent.command.blocked`
- `agent.command.dispatched`
- `agent.response.emitted`

Suggested minimum fields:
- `tenant_id`
- `project_id`
- `mode`
- `user_id`
- `session_id`
- `message_id`
- `coordinator_agent`
- `delegate_agent`
- `delegation_chain_id`
- `delegation_depth`
- `route_path`
- `intent_name`
- `action_name`
- `gate_decision`
- `evidence_refs`
- `latency_ms`
- `result_status`

## 12) Failure Modes
Typical failure modes include:
- duplicate execution proposal,
- conflicting specialist findings,
- circular delegation attempt,
- insufficient evidence,
- excessive fallback,
- lock acquisition failure,
- mode or permission denial,
- unsupported capability.

Safe outcomes are:
- clarification,
- denial,
- safe local response,
- blocked execution with traceable reason.

## 13) Detected Canon Drift
### 13.1 Router Stage Drift
The reviewed sources do not currently align:
- `docs/canon/ROUTER_CANON.md` documents `cache -> rules -> rag -> llm`
- `docs/contracts/router_policy.json` documents `cache -> rules -> skills -> rag -> llm`
- `framework/app/Core/IntentRouter.php` currently uses `cache -> rules -> skills -> rag -> llm`

This document does not silently resolve that difference.

Safe canonical reading for collaboration:
- collaboration SHALL remain inside governed deterministic routing,
- skills SHALL remain controlled execution mediators,
- LLM SHALL remain last resort,
- no collaboration flow may bypass either the documented canon or the implemented guardrails.

### 13.2 Skills Stage Interpretation
Current runtime treats skills as an explicit governed stage with allowlisting and telemetry.

Older canonical text describes tools and execution control but does not always isolate `skills` as a named router stage.

This document records that drift and preserves safety by requiring:
- skills governance,
- evidence and guard evaluation,
- CommandBus and PHP execution boundaries.

## 14) Non-Goal
This canon defines the collaboration model only. It does not introduce a new execution engine, direct specialist autonomy, or runtime behavior changes by itself.
