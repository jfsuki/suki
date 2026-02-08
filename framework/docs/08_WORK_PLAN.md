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
(Fases iguales)

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
- [ ] DB Kernel QueryBuilder MVP
- [ ] Tenant scoping everywhere
- [ ] Audit log MVP
- [x] Update formjson.html assistant when contracts/features change
