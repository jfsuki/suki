# ROADMAP_USER_FIRST

## Goal
Make the builder "no training required" while keeping the engine stable and backward compatible.

## Stage 1 - User-first base (docs + registry)
- Contract registry (catalog of apps/forms/grids).
- Command layer MVP (CreateRecord/QueryRecords).
- Output escaping and formula sandbox.
- Cache contracts (server-side) + ETag.

Done criteria
- Existing apps still work.
- Contract registry can list forms and versions.
- Security baseline (escape output + no eval).

## Stage 2 - Excel to App (CSV import + form wizard)
- CSV import to DataContract + seeds.
- Form wizard: table -> form JSON.
- UI simple by steps (Datos -> Pantallas -> Reglas -> Automatizaciones).

Done criteria
- User can upload CSV and get a working form and list.

## Stage 3 - Business apps (workflows + accounting)
- Workflow contract and engine.
- Async jobs + audit log.
- Templates for invoice, inventory, payments.

Done criteria
- Create invoice with background accounting entry.
