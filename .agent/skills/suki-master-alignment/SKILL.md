---
name: suki-master-alignment
description: Default SUKI architecture alignment for any repository task. Use when starting analysis, planning, coding, review, canon writing, or QA inside SUKI so the agent applies the core laws, layer model, router path, and execution boundaries before acting.
---

# SUKI Master Alignment

## Apply this baseline first

- Treat SUKI as an AI Application Operating System, not as a chatbot or demo.
- Keep chat as the main interface and the PHP execution kernel as the only business execution authority.
- Work incrementally. Extend. Do not redesign stable runtime paths.

## Core laws

- LLM interprets and guides.
- PHP kernel calculates, validates, persists, and executes.
- Agents are controlled copilots, not autonomous executors.
- Real execution must flow through `router -> skills -> CommandBus -> PHP kernel`.
- Deterministic router order for active runtime work is `cache -> rules -> skills -> rag -> llm`.
- Reusable apps and sector packs contain zero tenant operational data.

## Mandatory invariants

- Multitenant isolation is mandatory.
- `tenant_id` is mandatory in operational scope.
- Preserve backward compatibility unless an explicit canon or contract says otherwise.
- Respect anti-schema-explosion order:
  1. shared multitenant core
  2. `custom_fields`
  3. JSON `custom_data`
  4. new table only as last resort with justification

## Brain map

SUKI architecture layers:
1. Business Discovery
2. Sector Packs
3. App Builder Engine
4. Business Simulation Engine
5. Specialized Agents
6. SUKI Control Tower
7. Production Learning Pipeline
8. Agent Collaboration Engine

## Default working discipline

- Reconcile with `framework/docs/INDEX.md` and the relevant canons before changing anything important.
- Prefer repo contracts, registry, and canonical docs over assumptions.
- Report canon drift explicitly. Do not silently "fix" it in prose.
- Keep outputs short, architectural, and implementation-safe.
