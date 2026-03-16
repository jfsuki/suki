# CONTROL_TOWER_DASHBOARD
Status: CANONICAL
Version: 1.0.0
Date: 2026-03-16
Scope: Canon for the visual and operational observability layer of SUKI Control Tower.

## 1) Purpose
Formalize the SUKI Control Tower Dashboard as the governed visual layer that exposes operational quality, supervision signals, and improvement queues across SUKI without creating a second execution authority.

The dashboard exists to make supervised state visible, auditable, and actionable for operators, reviewers, and governance roles.

The dashboard is not:
- a business execution surface,
- a replacement for router, skills, CommandBus, or PHP kernel execution,
- a source of truth independent from contracts, traces, and governed artifacts.

## 2) Scope
This canon covers:
- dashboard domains and panel boundaries,
- operational views for agents, health, AI usage, friction, learning, and incidents,
- multitenant visualization boundaries,
- observability integration requirements,
- failure modes and long-term evolution direction.

This canon does not define:
- a UI framework,
- a telemetry vendor,
- a storage engine,
- a production implementation by itself.

## 3) Dashboard Domains
The Control Tower Dashboard SHALL organize observability into the following governed domains:
- Agent Operations
- System Health
- AI Usage
- User Friction
- Knowledge Improvement
- Incident Monitoring

These domains are the dashboard expression of already recognized Control Tower concerns, including:
- Supervisor
- Error Recovery
- Safety Layer
- Product Support
- Knowledge Improvement
- Operational Telemetry

Domains are visualization lenses, not execution authorities.

## 4) Agent Operations
The Agent Operations domain SHALL provide a supervised view of how agents behave inside governed runtime paths.

Representative coverage includes:
- agent selection and role used,
- delegation chains and collaboration depth,
- findings versus final executable proposal ownership,
- skill selection and governed execution path,
- clarification loops, denials, and blocked actions,
- supervisor status and route-quality signals.

This domain SHALL preserve the law that agents are controlled copilots and that final business execution remains outside the agent itself.

## 5) System Health
The System Health domain SHALL expose whether the platform is operating within expected safety and quality limits.

Representative coverage includes:
- routing health and route-policy coherence,
- guardrail and safety-layer status,
- execution latency and failure trends,
- simulation gate outcomes and release-readiness signals,
- checkpoint freshness and resumability health,
- hygiene state for governed artifacts relevant to Control Tower.

System Health is broader than infrastructure uptime. It includes runtime governance health.

## 6) AI Usage
The AI Usage domain SHALL show how much the system depends on AI and whether that use remains within policy.

Representative coverage includes:
- LLM fallback rate,
- structured output quality,
- evidence-backed retrieval usage,
- token and cost trends,
- skill usage versus free-form fallback,
- supervisor flags related to weak evidence, loops, or possible hallucination.

This domain SHALL reinforce that LLM is an interpretation layer and last resort, not the execution authority.

## 7) User Friction
The User Friction domain SHALL expose where users struggle to complete tasks safely and successfully.

Representative coverage includes:
- repeated clarifications,
- unresolved intents,
- failed or abandoned flows,
- repeated denials or guard blocks,
- mode confusion between builder and app operation,
- support and recovery patterns that indicate design or guidance gaps.

This domain exists to improve product guidance, contracts, routing, and support quality. It does not authorize silent runtime mutation.

## 8) Knowledge Improvement
The Knowledge Improvement domain SHALL visualize the governed path from signal to reviewed improvement.

Representative coverage includes:
- improvement candidates and hygiene status,
- reviewed versus blocked learning items,
- recurring reusable patterns,
- sector-pack gaps,
- regression case backlog,
- promotion readiness for abstract knowledge only.

This domain SHALL respect the Production Learning Pipeline law:
- raw tenant operational data is not shared,
- publication requires hygiene, review, and rollback-ready governance.

## 9) Incident Monitoring
The Incident Monitoring domain SHALL expose incidents that materially affect safety, quality, or release readiness.

Representative incident classes include:
- tenant-scope violations,
- router drift or route-policy mismatch,
- loop or fallback overuse,
- repeated skill failures,
- unresolved critical regressions,
- memory hygiene blockers,
- blocked checkpoints or sprint-governance blockers.

This domain SHALL support supervised triage, not autonomous remediation.

## 10) Multitenant Safety
The dashboard SHALL respect strict multitenant isolation.

Non-negotiable rules:
- `tenant_id` is mandatory in operational scope.
- Tenant-facing views SHALL remain scoped to the active tenant and app/project context.
- Shared supervisory views MAY aggregate only sanitized, policy-approved signals.
- Raw tenant credentials, unrestricted memory, and cross-tenant operational records SHALL NOT be exposed through dashboard views.
- Reusable sector knowledge and governance views SHALL remain free of tenant operational payloads.

When canonical app scope exists, `app_id` SHALL be preserved alongside `project_id`.

## 11) Observability Integration
The Control Tower Dashboard SHALL integrate with governed observability sources rather than inventing a parallel telemetry model.

Primary signal families include:
- Control Tower governance artifacts,
- AgentOps metrics and trace fields,
- router and gate decisions,
- specialized-agent and collaboration traces,
- simulation and release-gate outputs,
- production-learning hygiene and review signals,
- operational quality summaries already emitted by governed runtime paths.

Minimum integration dimensions SHOULD include:
- `tenant_id`
- `project_id`
- `app_id` when available
- `run_id`
- `sprint_id`
- `mode`
- `user_id` when relevant
- `route_path`
- `gate_decision`
- `supervisor_status`
- `latency_ms`
- `fallback_reason`
- `result_status`

The dashboard may consume existing summaries or derived metrics, but the canonical source of truth remains the underlying governed artifacts and telemetry contracts.

## 12) Failure Modes
Relevant dashboard failure modes include:
- cross-tenant data mixing,
- missing or stale observability signals,
- false healthy status due to incomplete ingestion,
- hidden router drift,
- incident views without severity or scope,
- learning views that expose raw tenant detail,
- dashboard actions being mistaken for runtime authority,
- excessive implementation coupling to a specific vendor or storage engine.

Safe outcomes include:
- degraded read-only visibility,
- explicit `unknown` or `incomplete` health status,
- blocked cross-tenant views,
- governance review before any promoted action.

## 13) Governance Notes
- The dashboard is a read-model and supervision surface, not an execution engine.
- The dashboard SHALL reflect the active governed runtime path `cache -> rules -> skills -> rag -> llm`.
- If other canons or older contracts still show a different router sequence, the dashboard SHOULD expose that as detected drift rather than flattening it.
- Dashboard changes SHALL be additive, backward-compatible, and traceable.
- Control Tower decisions, incidents, and learning candidates remain governed by their own canons and contracts.

## 14) Long-Term Evolution
Long-term evolution SHOULD move toward:
- stronger role-aware and tenant-safe drill-downs,
- unified release-readiness and simulation visibility,
- better correlation between user friction, incidents, and learning candidates,
- clearer separation between development supervision and tenant runtime supervision,
- trend analysis for route quality, fallback reduction, and support burden,
- richer dashboard support for audited rollback readiness and improvement governance.

This evolution remains bounded by the same SUKI laws:
- LLM interprets,
- PHP executes,
- tenants remain isolated,
- learning stays supervised,
- observability does not become autonomous execution.
