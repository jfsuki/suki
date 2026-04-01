# Contracts Standard

## Folder layout
- /project/contracts/forms: Form JSON contracts (UI + behavior).
- /project/contracts/grids: Grid JSON contracts.
- /project/contracts/views: View/page-level JSON contracts.
- /project/contracts/entities: Entity JSON contracts (DB + CRUD).
- /framework/contracts/schemas: JSON Schema files for validation (kernel).
- /project/contracts/app.manifest.json: App manifest contract (db strategy, registry, integrations, processes).

## Tooling
- formjson.html lives in /framework/public/editor_json and must save into /project/contracts/forms, /project/contracts/entities and /project/views.
- formjson.html incluye dashboard del proyecto, editor DB y procesos (preview de app.manifest.json).
- app.manifest.json is maintained by the framework registry and project creator.
- app.manifest.json must validate against /framework/contracts/schemas/app.manifest.schema.json.
- entities must validate against /framework/contracts/schemas/entity.schema.json.

## Migration rules (adapter priority)
- Adapter lookup order: new folders (`/project/contracts/*`) first, then legacy `/contracts/*.contract.json` and `/contracts/*.contract.schema.json`.
- If both exist, the new location wins; legacy is fallback only.
- Do not delete or rename legacy files until all consumers read from new folders.
- Keep legacy keys and payloads unchanged during transition.
