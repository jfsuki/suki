# Declarative Forms & Grids Platform (PHP + JS) — “Mother App” Framework
**EN + ES in each doc. Source of truth is /framework/docs.**

## What this is (EN)
A metadata-driven platform: one core engine (“mother”) that generates many customized apps (“children”) using JSON contracts. No-code/low-code behavior is achieved through configuration, not manual coding.

## Qué es (ES)
Una plataforma dirigida por metadatos: un motor central (“madre”) que genera muchas aplicaciones (“hijas”) personalizadas usando contratos JSON. El comportamiento se define por configuración, no por código manual.

---

## Source of Truth / Fuente de verdad
All core rules, architecture, contracts, and plans live in `/framework/docs`.

## Workspace layout
- /framework: kernel distribuible (core, assets, editor, docs).
- /project: implementación del proyecto/app (solo archivos del usuario).

### Documents index
- `/framework/docs/AI_ONBOARDING_PROMPT.md` — **Mandatory** prompt for Codex/any AI.  
- `/framework/docs/00_SYSTEM_OVERVIEW.md` — product + platform overview.
- `/framework/docs/01_RULES_NO_BREAK.md` — non-negotiable “no regressions / no rewrite”.
- `/framework/docs/02_ARCHITECTURE.md` — modules + layers (Core/App/Tenant).
- `/framework/docs/03_FORM_CONTRACT.md` — form JSON contract spec.
- `/framework/docs/04_GRID_CONTRACT.md` — grid JSON contract spec.
- `/framework/docs/05_VALIDATION_ENGINE.md` — declarative validation engine spec.
- `/framework/docs/06_PERSISTENCE_PLAN.md` — persistence snapshot strategy.
- `/framework/docs/07_DATABASE_MODEL.md` — multi-tenant DB + DIAN-ready architecture.
- `/framework/docs/08_WORK_PLAN.md` — step-by-step roadmap + checklists.
- `/framework/docs/09_MONETIZATION_PRODUCT_VISION.md` — product vision & monetization.
- `/framework/docs/10_AI_OPERATING_PROCEDURE.md` — how AI must operate (safe workflow).
- `/framework/docs/11_FRAMEWORK_BOUNDARY.md` � framework vs project boundary.
- `/framework/docs/12_INTEGRATIONS_AUTOMATION.md` � integrations, automation, chat processes.
- /framework/docs/13_APP_MANIFEST_CONTRACT.md -- app manifest contract (db strategy, registry, integrations, processes).

---

## “Mother Prompt” for Codex / Prompt madre para Codex
Copy/paste before any AI work:

> Read `/framework/docs/AI_ONBOARDING_PROMPT.md` first.  
> Then follow `/framework/docs/01_RULES_NO_BREAK.md`.  
> Only propose minimal incremental changes.  
> Never rewrite from scratch. Never break JSON contracts.  
> Provide affected files, risks, patch plan, and validation steps before coding.

---

## Quick dev principles
- Backward compatibility always.
- Version contracts.
- Separate Core/App/Tenant configuration.
- Audit everything (DIAN-ready).
- Security by design.




- /framework/docs/14_ENTITY_CONTRACT.md -- entity contract (tables, fields, relations, permissions).

