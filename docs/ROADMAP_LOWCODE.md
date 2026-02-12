# ROADMAP_LOWCODE

## Principles
- Backward compatible, no rewrite.
- JSON contracts are the source of truth.
- No direct SQL in app layer.
- DataContract/AppContract are optional; existing FormContract remains valid.

## Stage MVP (ETAPA 1) - Contracts + parser + examples
**Goal:** introduce DataContract/AppContract without breaking existing apps.

Deliverables
- DataContract v1 schema + AppContract v1 schema.
- Server-side validation for new contracts.
- Fallback behavior if DataContract/AppContract do not exist.
- Minimal examples in /examples.

Dependencies
- JSON schema validation already in framework (opis/json-schema).

Done criteria
- Projects without DataContract still render forms as today.
- DataContract validates and can be parsed into Entity contracts.
- AppContract can reference existing FormContract by path.

## Stage v1 (ETAPA 2) - Import Wizard (CSV -> DataContract)
**Goal:** generate tables + seeds from files, no SQL shown to user.

Flow
1) Select file (CSV/Excel/JSON).
2) Detect headers and types.
3) Preview rows.
4) Map columns -> field types.
5) Define keys (primary, unique, relations).
6) Generate DataContract + seeds + import report.

Validations
- UTF-8 encoding.
- RFC4180 CSV rules (delimiter, quotes).
- Duplicate keys and missing required.

Done criteria
- CSV import creates DataContract + seed JSON.
- No DB write yet (safe generation only).

## Stage v2 (ETAPA 3) - Form Wizard (table -> form JSON)
**Goal:** build a form JSON from a table without touching FormBuilder core.

Flow
1) Select table.
2) Select fields.
3) Auto layout (sections/columns).
4) Map types -> controls.
5) Generate FormContract JSON.

Done criteria
- FormContract renders with current FormGenerator.
- Layout stored as stable JSON (no pixel positions).

## Stage v3 (ETAPA 4) - Smart Wizards + Reports
**Goal:** acelerar creacion de apps: sugerencias automaticas y reportes.

Deliverables
- Sugerencia de formulario CRUD desde tabla (campos + layout).
- Deteccion maestro-detalle por relaciones (hasMany/belongsTo).
- Sugerir grids y formularios relacionados.
- Report Manager (lista de informes por formulario).
- Report Designer minimo (Factura/Cotizacion/Reporte) con preview.
- Dashboard basico (KPI + grafico simple por entidad).

Done criteria
- Crear formulario y grids sugeridos en 1 clic.
- Informes basicos con preview/print/PDF (MVP).
- Dashboard genera al menos 1 grafico por entidad.

## Proposed DataContract v1 (example)
```json
{
  "type": "data_contract",
  "version": "1.0",
  "tables": [
    {
      "id": "clientes",
      "name": "clientes",
      "label": "Clientes",
      "fields": [
        { "id": "id", "name": "id", "type": "int", "primary": true },
        { "id": "nombre", "name": "nombre", "type": "string", "required": true },
        { "id": "email", "name": "email", "type": "string", "unique": true }
      ],
      "indices": [
        { "name": "idx_clientes_email", "columns": ["email"], "unique": true }
      ],
      "relations": [
        { "name": "facturas", "type": "hasMany", "table": "facturas", "fk": "cliente_id" }
      ],
      "seeds": [
        { "id": 1, "nombre": "Demo", "email": "demo@example.com" }
      ]
    }
  ],
  "migrations": {
    "strategy": "auto",
    "history": []
  }
}
```

## Proposed AppContract v1 (example)
```json
{
  "type": "app_contract",
  "version": "1.0",
  "data_ref": "data/data.contract.json",
  "forms": [
    {
      "id": "cliente_form",
      "contract": "forms/clientes.form.json",
      "table": "clientes",
      "mode": "create"
    }
  ],
  "views": [
    {
      "id": "clientes_view",
      "route": "/clientes",
      "form": "cliente_form",
      "layout": "default"
    }
  ],
  "hooks": [
    { "event": "beforeSave", "handler": "App\\Hooks\\Clientes::beforeSave" },
    { "event": "afterSave", "handler": "App\\Hooks\\Clientes::afterSave" }
  ]
}
```

## Compatibility strategy
- If DataContract is missing, use current Entity contract + FormContract.
- AppContract is optional. If missing, routing works as today.
- DataContract can be generated from Entity contracts (adapter layer).

## Risks + mitigations
- Risk: two parallel contracts (Entity vs DataContract). Mitigation: adapter that maps DataContract -> EntityContract.
- Risk: import wizard creates invalid types. Mitigation: strict schema validation + preview step.
- Risk: visual builder stores pixel layout. Mitigation: store rows/cols grid JSON only.
