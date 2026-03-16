---
name: suki-agent-governance
description: Governance skill for SUKI agents and multi-agent collaboration. Use when defining agent behavior, delegation, supervision, findings, control boundaries, or observability for App Agent and specialist agents.
---

# SUKI Agent Governance

## Agent law

- Agents are controlled copilots.
- Agents interpret, classify, normalize, explain, and delegate within policy.
- Agents do not execute business logic directly.

## Collaboration model

- App Agent is the sole coordinator.
- Specialists return findings only.
- Specialists do not dispatch `CommandBus`.
- Specialist-to-specialist delegation is forbidden.
- One request should end with at most one governed executable proposal.

## What agents may do

- extract parameters
- resolve references through governed skills
- return evidence, warnings, and missing data
- ask one critical clarification when needed

## What agents may not do

- perform business math
- execute side effects
- use raw SQL
- access cross-tenant data
- claim unsupported capabilities
- create uncontrolled loops

## Supervisor law

- Control Tower supervises drift, safety, and operational quality.
- AgentOps supervises latency, fallback, quality, and incidents.
- Every significant delegation or denial should emit traceable signals.

## Safety rules

- enforce mode boundaries,
- require confirmation for destructive actions,
- use evidence before fallback,
- block execution on weak authority,
- preserve `router -> skills -> CommandBus -> PHP kernel`.
