# ROADMAP_USER_FIRST

## Goal
Make the builder "no training required" while keeping the engine stable and backward compatible.

## Stage 1 - User-first base (docs + registry)
- Contract registry (apps/forms/grids) ✅
- Command layer MVP (Create/Query/Update/Delete) ✅
- Output escaping and formula sandbox ✅
- Cache contracts (server-side) + ETag ✅
- Chat local-first + slot-filling + help JSON ✅
- Acid test conversacional ✅

Done criteria
- Existing apps still work.
- Contract registry can list forms and versions.
- Security baseline (escape output + no eval).

## Stage 2 - Excel to App (CSV import + form wizard)
- CSV import to EntityContract + migration ✅
- Form wizard: table -> form JSON ✅
- UI simple por pasos (pendiente de refinamiento UX) ⚠️

Done criteria
- User can upload CSV and get a working form and list.

## Stage 3 - Business apps (workflows + accounting)
- Workflow contract and engine ⚠️
- Async jobs + audit log ⚠️
- Templates invoice/inventory/payments ⚠️

## Stage 4 - Chat-first production
- Build vs Use guard estrictos ✅
- Help dinamico basado en registry ✅
- Multiusuario + auth en chat ⚠️

Done criteria
- Create invoice with background accounting entry.
