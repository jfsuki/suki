# FEATURE_MATRIX

| Feature | Status | Files / Modules | Risks / Notes |
|---|---|---|---|
| FormContract spec | Partial | framework/docs/03_FORM_CONTRACT.md | Spec vs runtime keys not fully aligned. |
| Form rendering (sections/fields) | Done | framework/app/Core/FormGenerator.php, FormBuilder.php | Stable; keep backward compatible. |
| Grid runtime (rows/totals/formulas) | Done (MVP) | framework/public/assets/js/form-grid.js | Safe expression engine (no eval). |
| Summary dependency graph | Done | framework/public/assets/js/form-grid.js | Works; watch for cycles. |
| Select options (manual/API) | Done | form-grid.js, FormBuilder.php | API error handling minimal. |
| FORM_STORE/GRID_STORE | Partial | form-grid.js + docs | Only localStorage; no DB persistence. |
| Validation engine (UI + backend) | Pending | framework/docs/05_VALIDATION_ENGINE.md | Not implemented end-to-end. |
| Routing/view conventions | Done | project/public/index.php, .htaccess | Works with contracts in project. |
| Entity contract schema | Done | framework/contracts/schemas/entity.schema.json | Solid; needs tooling adoption. |
| Entity registry loader | Done | framework/app/Core/EntityRegistry.php | Cache ok; no CLI tool yet. |
| DB Kernel QueryBuilder | Done (MVP) | framework/app/Core/QueryBuilder.php | No joins/advanced filters. |
| BaseRepository CRUD | Done (MVP) | framework/app/Core/BaseRepository.php | Needs controllers + policy checks. |
| Auto migrations (create-if-missing) | Done (MVP) | framework/app/Core/EntityMigrator.php | No ALTER/diff. |
| CLI migration runner | Done | project/bin/migrate.php | CLI only; no web endpoint. |
| Manifest contract + validation | Done | framework/contracts/schemas/app.manifest.schema.json, ManifestValidator.php | Not wired to editor save. |
| Editor JSON (formjson.html) | Partial | framework/public/editor_json/formjson.html | Nuevo layout builder; falta refinamiento. |
| Import wizard (CSV/Excel/JSON) | Partial | framework/public/editor_json/formjson.html | CSV a campos (no Excel nativo). |
| Visual form builder (drag/drop) | Pending | (new) | Must persist to layout JSON (no pixels). |
| Form wizard (table -> form) | Pending | (new) | Sugerir formulario desde tabla/relaciones. |
| Master-detail detection | Pending | (new) | Detectar relaciones y sugerir grids. |
| Report manager/designer | Partial | framework/public/editor_json/formjson.html | UI base; falta engine PDF/print. |
| Dashboards + charts | Pending | (new) | KPI, gráficas, análisis. |
| Cubo/pivot tables | Pending | (new) | Consolidación avanzada. |
| Process engine + async jobs | Pending | (new) | Needed for background tasks. |
| Audit log | Pending | (new) | Required for enterprise readiness. |
| Security hardening (CSRF, sanitization) | Pending | (new) | Needs centralized middleware. |
