# Work Plan (Updated)

## Current maturity (assessment)
- Core runtime (forms + grids) : DONE
- Summary dependency ordering : DONE
- Framework/project separation : DONE
- Validation engine backend : DONE
- Persistence (DB kernel + CRUD) : DONE
- Routing/view conventions : DONE
- Chat local-first + slot filling : DONE
- Acid tests for chat : DONE
- Report runtime + dashboard runtime : DONE
- Integrations base + Alanube client : MVP DONE

## Roadmap (next phases)
### Phase 1 — Stabilize logic
1) Build/Use guard strict (done)
2) Entity exists validation before CRUD (done)
3) Help/Buttons from registry real (done)
4) Manual testing end-to-end (pending)

### Phase 2 — UX builder
1) Visual builder drag/drop (pending)
2) Inspector properties full (pending)
3) Import wizard UI (pending)

### Phase 3 — Production hardening
1) Migration diff/alter (pending)
2) Security hardening (CSRF/IDOR/rate limit) (pending)
3) Metadata contracts in DB + versioning (pending)

## Definition of Done (DoD)
- Backward compatible
- Minimal patch
- Tests passed (acid + manual)
- No rewrite

## Execution checklist
- [x] Summary dependency ordering stable
- [x] Framework/project separation (paths + webroots)
- [x] Validation engine MVP
- [x] Routing/view standard + migration notes
- [x] Persist submit payload (CommandLayer)
- [x] Load/hydrate (Repository)
- [x] Entity contract schema + registry loader
- [x] DB config loader (env -> PDO)
- [x] DB Kernel QueryBuilder MVP
- [x] CRUD base (Repository + allowlist)
- [x] Tenant scoping everywhere
- [x] Migration store (schema_migrations)
- [x] Audit log MVP
- [x] Chat local-first + help JSON
- [x] Acid test conversational
- [ ] Manual testing end-to-end
- [ ] Visual builder drag/drop
- [ ] Migration diff/alter
- [ ] Security hardening
- [ ] Metadata contracts in DB
