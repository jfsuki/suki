---
name: suki-builder-discipline
description: Builder discipline for SUKI app generation. Use when creating or modifying app structure, contracts, entities, forms, workflows, sector assets, or simulation-bound delivery pipelines.
---

# SUKI Builder Discipline

## Canonical builder chain

Follow this architecture sequence:
1. Business Discovery
2. Sector Packs
3. App Builder Engine
4. Business Simulation Engine
5. Specialized Agents

## Build-time rules

- Treat JSON contracts as the source of truth.
- Extend contracts additively.
- Preserve backward compatibility.
- Keep builder outputs deterministic and reviewable.
- Never mix builder actions with runtime business CRUD.

## Schema discipline

Apply this order:
1. shared multitenant core
2. `custom_fields`
3. JSON `custom_data`
4. new table only with explicit justification

Do not create uncontrolled schema growth just because a prompt asks for it.

## App generation boundaries

- BUILD mode creates or changes structure.
- USE mode operates approved runtime data.
- Reusable sector assets must stay free of tenant operational data.
- Integration contracts must stay auditable and contract-first.

## Simulation gate

- Every generated or materially changed app should pass business simulation before deployment.
- Simulation uses the real PHP execution kernel.
- Failed simulation blocks release.

## Builder output checklist

- contract integrity
- mode separation
- tenant safety
- observability hooks
- rollback-friendly evolution
