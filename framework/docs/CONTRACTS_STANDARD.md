# Contracts Standard

## Folder layout
- /project/contracts/forms: Form JSON contracts (UI + behavior).
- /project/contracts/grids: Grid JSON contracts.
- /project/contracts/views: View/page-level JSON contracts.
- /framework/contracts/schemas: JSON Schema files for validation (kernel).

## Tooling
- formjson.html lives in /framework/public/editor_json and must save into /project/contracts/forms and /project/views.

## Migration rules (adapter priority)
- Adapter lookup order: new folders (`/project/contracts/*`) first, then legacy `/contracts/*.contract.json` and `/contracts/*.contract.schema.json`.
- If both exist, the new location wins; legacy is fallback only.
- Do not delete or rename legacy files until all consumers read from new folders.
- Keep legacy keys and payloads unchanged during transition.
