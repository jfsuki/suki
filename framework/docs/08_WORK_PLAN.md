# Work Plan — EN
## Current maturity (assessment)
- Core runtime exists (forms + grids) ✅
- Summary dependency ordering hardened ✅
- Framework/project separation enforced ✅
- Declarative validations incomplete ⚠️
- Persistence not fully integrated ⚠️
- Routing/view conventions standardized ✅
- DB Kernel not implemented ⚠️
- Audit/security layers pending ⚠️

## Roadmap (phases)
### Phase 1 — Stabilize core (No regressions)
1) Summary dependency graph ordering
2) Validation engine (required/min/formula safe)
3) Standardize routing/view conventions (see below)
4) Regression checklist automation (minimal)

### Phase 2 — Persistence MVP
1) Save FORM_STORE + GRID_STORE
2) Load/hydrate
3) Edit flow
4) Audit logs for submit/update

### Phase 3 — DB Kernel
1) QueryBuilder + safe filters + tenant scope
2) CRUD builder for dev-friendly usage
3) SQL generator + allowlist
4) Security testing baseline

### Phase 4 — DIAN-ready foundations
1) Tax rules module (IVA, retenciones)
2) Document lifecycle + approvals
3) Audit + traceability for accountant approval

### Phase 5 � Integrations + Automation
1) Integration Gateway MVP (providers + credentials + webhooks)
2) Process Engine (command bus + process definitions)
3) Conversational execution (chat intents -> actions)
4) External provider POC (e-invoicing)

## Definition of Done (DoD)
- Backward compatible
- Minimal patch
- Validation checklist passed
- No “rewrite” changes

---

# Plan de Trabajo — ES
## Madurez actual
- Runtime core (forms + grids) ✅
- Summary por dependencias reforzado ✅
- Separación framework/proyecto aplicada ✅
- Validaciones declarativas incompletas ⚠️
- Persistencia falta integrar ⚠️
- Routing/view estandarizado ✅
- Kernel DB pendiente ⚠️
- Auditoría/seguridad pendiente ⚠️

## Roadmap
Fases iguales + Fase 5 (Integraciones + Automatizacion)

## DoD
- Retrocompatible
- Patch mínimo
- Checklist validado
- Sin reescritura




## Execution checklist
- [x] Summary dependency ordering stable
- [x] Framework/project separation (paths + webroots)
- [ ] Validation engine MVP
- [x] Routing/view standard + migration notes
- [ ] Persist submit payload (FORM_STORE + GRID_STORE)
- [ ] Load/hydrate
- [x] Entity contract schema + registry loader
- [x] DB config loader (env -> PDO)
- [x] DB Kernel QueryBuilder MVP
- [x] CRUD base (Repository + allowlist)
- [x] Tenant scoping everywhere
- [x] Migration store (schema_migrations)
- [ ] Audit log MVP
- [ ] Integration Gateway MVP
- [ ] Process Engine + chat execution
- [ ] E-invoicing provider POC
- [x] Update formjson.html assistant when contracts/features change

