# PROJECT_MEMORY (framework/docs)

## Objective
Build a low-code "mother app" platform that generates business apps from JSON contracts. Users get a friendly UI; the engine hides complexity and keeps backward compatibility.

## Non-negotiable rules
- No rewrite. Only incremental, backward-compatible changes.
- JSON contracts are the single source of truth.
- Do not change existing keys without versioning + fallback.
- No SQL in app layer; persistence goes through Kernel/ORM.
- Keep framework/project separation.
- Every query must be index-friendly (tenant_id first).
- Optimize schema from day 1 for millions of records.

## Core modules
- FormGenerator: orchestrates form rendering.
- FormBuilder: UI primitives.
- Grid runtime (form-grid.js): rows, totals, formulas, summary graph.
- Entity contracts + registry + migrator (DB kernel MVP).
- Editor JSON: dashboard + forms + DB/process editor.
- Editor JSON (modo amigable): arbol/inspector/canvas, modo guiado, reportes y dashboards basicos (UI).
- Chat Gateway (local HTML) para simular WhatsApp/Telegram sin instalar nada.
- ChatAgent (API) con routing local + LLM (Groq/Gemini) y comandos para crear tablas/forms.
- UnitTestRunner basico (framework/tests/run.php) + comando chat "probar sistema".

## What works today
- Forms + grids render from JSON.
- Summary dependency ordering (graph).
- FORM_STORE/GRID_STORE in localStorage.
- Entity/manifest schema validation.
- DB Kernel MVP (QueryBuilder + Repository + create-if-missing migrations).
- CLI migration runner (project/bin/migrate.php).
- Output escaping (Html::e) for server-rendered labels/values.
- Safe formula engine (no eval/new Function).
- CRUD API endpoints /api/records/* + command endpoint /api/command.
- Contract cache + ETag (contracts) and assets cache headers.
- DB persistence for main entity + grid rows via CommandLayer.
- Report runtime (preview + PDF MVP) via /api/reports.
- Dashboard runtime (KPI + chart series) via /api/dashboards.
- Wizard tabla->form (FormWizard) con soporte maestro-detalle + reportes fiscales.
- CSV->EntityContract + migracion.
- ValidationEngine backend (min/max/pattern/enum) + AuditLogger.
- IntegrationContract + InvoiceContract schemas.
- Integracion Alanube (wizard + endpoints + webhook) con base_url parametrico por pais.
- Report designer fiscal basico (emisor/cliente/documento/totales) en editor.
- Tablas base de integracion (connections/documents/outbox/webhooks).
- Smoke tests checklist in framework/docs/SMOKE_TESTS.md (run before deploy).

## What is missing
- Manual testing end-to-end (still pending).
- Multimodal pipeline (audio/img/doc) + OCR + transcripcion.
- Validation engine (UI) con mensajes claros.
- Snapshot persistence of FORM_STORE/GRID_STORE for audit/history.
- Migration diff/alter (only CREATE IF NOT EXISTS).
- Import wizard (CSV/Excel/JSON -> DataContract + seeds).
- Visual form builder (drag/drop, layout grid).
- Report manager/designer avanzado (facturas, cotizaciones, PDF).
- Dashboards + charts avanzados (KPI, analitica).
- Process engine + async jobs + audit log.
- Security hardening (CSRF, RBAC/IDOR, rate limiting).

## Known risks
- Two grid engines (form-grid.js and grid-engine.php) can drift.
- Grid engines still duplicate logic (needs consolidation later).
