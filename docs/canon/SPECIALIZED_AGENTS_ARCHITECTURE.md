# SPECIALIZED_AGENTS_ARCHITECTURE
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-15  
Scope: Canon for specialized operational agents inside tenant applications.

## 1) Purpose
Formalize how specialized agents operate inside SUKI as controlled copilots under deterministic execution governance.

This canon defines:
- operational agent types,
- context and memory boundaries,
- skill governance,
- router integration,
- supervision and safety rules.

## 2) Scope
This document covers request-time operational agents for tenant applications.

It does not replace higher-level governance roles such as Architect, App Builder, Operator, Integration, Support, and Auditor. Those roles remain valid accountability layers.

## 3) Canonical Agent Types
### 3.1 App Agent
Primary responsibilities:
- primary user-facing entry point,
- intent framing,
- context consolidation,
- delegation control,
- final response composition.

The App Agent is the operational coordinator for user requests.

### 3.2 Inventory Agent
Primary responsibilities:
- stock interpretation,
- product and warehouse reference normalization,
- availability or reservation findings,
- inventory-related evidence gathering.

### 3.3 Sales Agent
Primary responsibilities:
- customer and sales-document interpretation,
- commercial intent normalization,
- sales flow guidance,
- sales-related evidence gathering.

### 3.4 Purchasing Agent
Primary responsibilities:
- supplier and purchase reference interpretation,
- purchasing flow guidance,
- purchase-side evidence gathering.

### 3.5 Manufacturing Agent
Primary responsibilities:
- production-order interpretation,
- material or routing context normalization,
- manufacturing findings and constraints.

### 3.6 Service Agent
Primary responsibilities:
- service-ticket or work-order interpretation,
- service process guidance,
- service-related findings and exceptions.

### 3.7 Accounting Agent
Primary responsibilities:
- payment term interpretation,
- accounting context normalization,
- posting or fiscal-context findings,
- evidence for governed financial actions.

### 3.8 Support Agent
Primary responsibilities:
- explain current state,
- guide recovery,
- translate errors into safe next steps,
- avoid unsafe or fake success.

## 4) Canonical Responsibilities
All specialized agents MAY:
- interpret user language,
- extract and normalize parameters,
- resolve references through governed tools,
- produce findings, warnings, and safe guidance,
- request one critical clarification when required.

All specialized agents SHALL NOT:
- execute business logic directly,
- perform business math,
- persist arbitrary state outside governed runtime paths,
- execute raw SQL,
- access cross-tenant data,
- claim unsupported capabilities outside registry/contracts.

## 5) Inputs and Outputs
### 5.1 Inputs
Allowed inputs:
- request-scoped context capsule,
- registry and contract references,
- tenant-scoped capability metadata,
- evidence references,
- sanitized memory references when permitted.

### 5.2 Outputs
Allowed outputs:
- normalized parameters,
- evidence references,
- missing critical data,
- safe explanations,
- proposed next step,
- specialist findings.

Specialists SHALL NOT emit final side-effect execution authority on their own.

## 6) Context Model
Specialized agents SHALL operate with minimal context that includes only what is needed for the active request, such as:
- `tenant_id`
- `project_id`
- `mode`
- `user_id`
- `session_id`
- `message_id`
- `intent_name`
- `entity_refs`
- `transaction_refs`
- `workflow_stage`
- `extracted_params`
- `evidence_refs`

Historical tenant memory may inform the request through governed retrieval or memory brokers, but raw tenant memory SHALL NOT be exposed to agents without scope control.

## 7) Memory Boundaries
- Tenant memory remains isolated.
- User memory remains isolated by tenant, project, mode, and user.
- Shared framework or sector knowledge is read-only to specialized agents at request time.
- Specialized agents may consume memory references; they do not own global memory promotion.

## 8) Skill Governance
- Skills are the only allowed bridge from agent interpretation to executable runtime paths.
- Skill selection SHALL be allowlisted by contract, role, and mode.
- Skills SHALL call governed PHP services, CommandBus, repositories, or adapters.
- Missing capability in registry/contracts SHALL result in denial, clarification, or redirect, never fake success.

## 9) Router Integration
- Specialized agents SHALL operate under router governance.
- They SHALL NOT create a parallel routing system.
- They SHALL NOT bypass cache, rules, evidence gates, skills, or CommandBus.
- LLM remains last resort for interpretation only.

Operationally, agent reasoning may occur inside deterministic stages and governed fallback stages, but execution authority remains outside the agent itself.

## 10) Safety Rules
- Agents are controlled copilots, not autonomous executors.
- Destructive actions require confirmation through governed contracts.
- APP mode SHALL NOT create app structure.
- BUILDER mode SHALL NOT execute business CRUD.
- If one critical datum is missing, ask exactly one critical question.
- If entity or capability does not exist, explain and redirect safely.

## 11) Supervision and Observability
Specialized agents SHALL operate under Control Tower and AgentOps supervision.

Minimum operational traces SHOULD include:
- `agent.selected`
- `agent.finding.generated`
- `agent.clarification.requested`
- `agent.denied`
- `agent.fallback.used`

Minimum trace fields SHOULD include:
- `tenant_id`
- `project_id`
- `agent_key`
- `intent_name`
- `skill_selected`
- `result_status`
- `latency_ms`
- `evidence_refs`

## 12) Failure Modes
Typical failure modes include:
- unsupported capability,
- ambiguous entity resolution,
- stale or insufficient context,
- weak evidence,
- mode mismatch,
- permission denial,
- repeated fallback without progress.

The safe outcome for unresolved cases is clarification or denial, not unsafe execution.

## 13) Detected Canon Drift
### 13.1 Agent Taxonomy Drift
Reviewed governance sources already define broader governance roles such as:
- Architect
- App Builder
- Operator
- Integration
- Support
- Auditor

Operational design work also refers to domain specialists such as:
- App Agent
- Inventory Agent
- Sales Agent
- Purchasing Agent
- Manufacturing Agent
- Service Agent
- Accounting Agent
- Support Agent

This document does not replace one set with the other.

Canonical interpretation:
- governance roles remain policy and accountability layers,
- specialized agents are request-time operational specializations inside that governance.

### 13.2 Router Stage Drift
Reviewed sources differ on whether `skills` is an explicit router stage before `rag`.

This document does not resolve that drift. It records the safe rule that agents SHALL NOT bypass deterministic router governance, regardless of whether `skills` is documented as a separate stage or as part of controlled tools.

## 14) Non-Goal
This canon defines specialized agent architecture only. It does not create new runtime agent classes or modify execution behavior by itself.
