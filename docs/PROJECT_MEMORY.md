# PROJECT_MEMORY

## Objective (short)
Build a low-code "mother app" platform that generates business apps from JSON contracts. Users get a friendly UI; the engine hides complexity and keeps backward compatibility.

## Non-negotiable rules
- No rewrite from scratch. Only incremental, backward-compatible changes.
- JSON is the single source of truth (FormContract is canonical).
- Do not break existing keys/behavior; only additive changes.
- No SQL in app layer; persistence must go through Kernel/ORM.
- Keep framework/project separation (framework = kernel, project = app).
- Legacy fallback is allowed but new contracts live in /project/contracts.

## Current modules and responsibilities
- FormGenerator (framework/app/Core/FormGenerator.php): orchestrates form + grids + summary rendering.
- FormBuilder (framework/app/Core/FormBuilder.php): input/select/textarea UI primitives.
- TableGenerator (framework/app/Core/TableGenerator.php): table shell for API-driven lists.
- Grid runtime JS (framework/public/assets/js/form-grid.js): grid rows, totals, formulas, summary graph, FORM_STORE/GRID_STORE in localStorage.
- Legacy grid engine (framework/public/assets/js/grid-engine.php): older grid calculator.
- Manifest validator (framework/app/Core/ManifestValidator.php): validates app.manifest.json at runtime.
- Entity contract + registry (framework/contracts/schemas/entity.schema.json, framework/app/Core/EntityRegistry.php).
- DB Kernel MVP (framework/app/Core/Database.php, QueryBuilder.php, BaseRepository.php, EntityMigrator.php, MigrationStore.php).
- Editor JSON (framework/public/editor_json/formjson.html): dashboard + forms + DB/process editor.
- Project routing (project/public/index.php, api.php, .htaccess).

## What works today
- Form rendering from JSON (sections, grid, summary).
- Grid formulas + totals + summary dependency ordering.
- Select options (manual or API) in grids.
- FORM_STORE/GRID_STORE snapshots in localStorage.
- Manifest + Entity schema validation.
- DB Kernel MVP with allowlist + tenant scope + create-if-missing migrations.
- CLI migrator runner (project/bin/migrate.php) for real DB table creation.
- Framework/project separation and routing conventions.

## What is missing / partial
- Declarative validation engine (UI + backend) is incomplete.
- FORM_STORE/GRID_STORE persistence to DB (only localStorage now).
- CRUD endpoints/controllers built on BaseRepository.
- Migration diff/alter (only CREATE IF NOT EXISTS).
- Import wizard (CSV/Excel/JSON -> DataContract + seeds).
- Visual form builder (drag/drop, layout grid, tab order).
- Process engine + async jobs + audit log.
- Security hardening (CSRF, centralized sanitization).

## Known risks / gaps
- Two grid engines (form-grid.js + grid-engine.php) can drift.
- Summary formulas use eval/new Function; needs sandboxing.
- LocalStorage store is not durable or multi-user safe.
