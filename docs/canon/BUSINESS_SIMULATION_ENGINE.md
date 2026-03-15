# BUSINESS_SIMULATION_ENGINE
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-15  
Scope: Canon for deterministic business simulation as a mandatory safety gate before deployment.

## 1) Purpose
Formalize the Business Simulation Engine as the canonical gate that validates generated or changed apps before deployment.

Simulation exists to prove that a candidate app can execute business flows safely through the real PHP execution kernel without exposing production tenants or creating uncontrolled side effects.

## 2) Scope
This canon covers:
- simulation purpose and authority,
- required inputs and outputs,
- scenario classes,
- execution and isolation boundaries,
- feedback paths to builder and Control Tower.

This canon does not define runtime implementation details or test fixtures storage.

## 3) Canonical Responsibilities
The Business Simulation Engine SHALL:
- execute candidate flows through the same PHP kernel used by production runtime,
- validate contracts, guards, and command paths before deployment,
- verify that routing, skills, and command execution remain deterministic,
- detect blocking defects before tenant exposure,
- emit auditable simulation results and feedback.

## 4) Inputs
Mandatory inputs may include:
- candidate app contracts,
- workflow revisions,
- form, grid, entity, and integration contracts,
- relevant sector pack references,
- action and router governance contracts,
- sanitized or synthetic scenario data,
- sandbox credentials or test doubles when integrations are involved.

Forbidden inputs:
- uncontrolled live tenant production data,
- production-only secrets without sandbox boundary,
- free-form LLM output as execution authority.

## 5) Scenario Classes
### 5.1 Structural Validation
Validates:
- schema and contract integrity,
- entity and workflow references,
- required permissions and mode boundaries,
- builder/app separation.

### 5.2 Happy-Path Business Scenarios
Validates:
- expected operational flows,
- valid command dispatch,
- correct persistence path,
- auditable end state.

### 5.3 Negative and Guardrail Scenarios
Validates:
- missing required data,
- policy denial,
- destructive confirmations,
- invalid mode or role,
- forbidden actions.

### 5.4 Tenant Isolation Scenarios
Validates:
- `tenant_id` propagation,
- app/project scoping,
- absence of cross-tenant reads or writes,
- isolation of tenant overlays and credentials.

### 5.5 Idempotency and Retry Scenarios
Validates:
- repeated executable requests do not duplicate side effects,
- retry behavior preserves `idempotency_key`,
- queue or retry semantics remain safe.

### 5.6 Integration Sandbox Scenarios
Validates:
- adapter contract compatibility,
- sandbox endpoint behavior,
- outbound safety,
- auditable integration traces without live production execution.

### 5.7 Workflow and Process Scenarios
Validates:
- contract-driven process execution,
- workflow guardrails,
- deterministic node or step execution,
- rollback or block behavior on invalid state.

## 6) Execution Boundary
- LLM may assist design-time generation of scenarios, but SHALL NOT determine simulation pass/fail.
- PHP kernel SHALL perform all business calculations, validations, persistence, and command execution during simulation.
- Skills, CommandBus, adapters, and repositories SHALL be exercised through governed execution paths.
- Simulation SHALL use the same business rules as production, not a parallel rules engine.

## 7) Safety Boundaries and Isolation
### 7.1 Tenant Safety
- Simulation SHALL run with explicit isolated scope.
- `tenant_id` remains mandatory even in simulation.
- Production tenant operational data SHALL NOT be modified.

### 7.2 Storage Safety
- Simulation artifacts SHALL be isolated from production state.
- Temporary artifacts SHALL live in approved temporary locations only.
- Simulation results are evidence, not source contracts.

### 7.3 Integration Safety
- External calls SHALL use sandbox credentials, mocks, or dry-run adapters where possible.
- Live production side effects are forbidden during simulation unless an explicit approved environment contract exists.

## 8) Canonical Outputs
Simulation SHALL return a structured result containing:
- simulation status,
- scenario coverage summary,
- blocking failures,
- warnings,
- evidence references,
- affected contracts or actions,
- recommended remediation.

Canonical statuses:
- `SIMULATION_PASS`
- `SIMULATION_WARN`
- `SIMULATION_BLOCKED`
- `SIMULATION_INCOMPLETE`

Deployment SHALL be blocked on `SIMULATION_BLOCKED`.

## 9) Feedback Integration
### 9.1 To App Builder Engine
Simulation feeds:
- structural corrections,
- missing validation rules,
- workflow gaps,
- contract compatibility issues.

### 9.2 To SUKI Control Tower
Simulation feeds:
- pass/fail rates,
- recurring blocker classes,
- latency and reliability signals,
- release-gate evidence.

### 9.3 To Production Learning Pipeline
Only abstracted patterns may be promoted:
- missing rule classes,
- recurring scenario failures,
- reusable builder guidance,
- regression dataset additions.

Raw tenant data SHALL NOT be promoted.

## 10) Failure Modes
Common failure modes include:
- contract mismatch,
- missing scenario coverage,
- non-deterministic execution result,
- invalid tenant scope,
- missing sandbox for required integration,
- unsupported command or guard,
- unsafe live side effect during simulation.

Any failure mode that compromises safety SHALL block deployment.

## 11) Governance and Observability
Minimum simulation events SHOULD include:
- `simulation.run.started`
- `simulation.scenario.started`
- `simulation.scenario.passed`
- `simulation.scenario.failed`
- `simulation.run.completed`
- `simulation.deployment.blocked`

Minimum event fields SHOULD include:
- `tenant_id`
- `project_id`
- `scenario_class`
- `action_name`
- `result_status`
- `latency_ms`
- `evidence_refs`

## 12) Detected Canon Drift
No direct simulation canon/runtime contradiction was found in the reviewed source set.

The drift at this time is documentation coverage:
- simulation is treated as a required architectural law,
- but it was not yet formalized as a dedicated canonical document.

This file closes that documentation gap without changing runtime behavior.

## 13) Non-Goal
This canon defines the safety gate and its boundaries only. It does not create a simulation runner, fixtures database, or deployment workflow by itself.
