# Entity Contract - EN
Defines an entity for data layer and CRUD generation. It is the bridge between forms/grids and the database kernel.

## Location
- project/contracts/entities/*.entity.json

## Schema (validation)
- framework/contracts/schemas/entity.schema.json

## Required top-level fields
- type = "entity"
- name (slug, lowercase a-z0-9_)
- table
- fields
- permissions

## Field summary
### table
- table.name (physical table name)
- table.primaryKey (default: id)
- table.timestamps (true/false)
- table.softDelete (true/false)
- table.tenantScoped (true/false)

### fields
- fields[].name
- fields[].type (string, number, decimal, date, time, bool, text, select, etc)
- fields[].label (optional)
- fields[].required (optional)
- fields[].source (optional: form|grid:<gridName>|system)
- fields[].grid (optional: grid name)
- fields[].ref (optional: entity reference)

### system columns (runtime)
- primaryKey is always allowed for lookups.
- tenantScoped adds tenant_id to allowlist + tenant filter.
- timestamps adds created_at/updated_at allowlist.
- softDelete adds deleted_at allowlist.

### grids (optional)
- grids[].name
- grids[].table
- grids[].relation (hasMany|hasOne|belongsTo)

### relations (optional)
- relations[].name
- relations[].type
- relations[].entity
- relations[].fk (optional)

### rules (optional)
- rules[].id
- rules[].assert (expression)
- rules[].message
- rules[].when (optional)
- rules[].fields (optional)

### permissions
- permissions.read/create/update/delete: array of roles

## Example
```json
{
  "type": "entity",
  "name": "factura",
  "label": "Factura",
  "version": "1.0",
  "table": {
    "name": "facturas",
    "primaryKey": "id",
    "timestamps": true,
    "softDelete": false,
    "tenantScoped": true
  },
  "fields": [
    { "name": "id", "type": "int", "primary": true, "source": "system" },
    { "name": "cliente", "type": "string", "required": true, "source": "form" },
    { "name": "subtotal", "type": "decimal", "source": "grid:detalle_factura" }
  ],
  "grids": [
    {
      "name": "detalle_factura",
      "table": "facturas__detalle_factura",
      "relation": { "type": "hasMany", "fk": "factura_id" }
    }
  ],
  "relations": [
    { "name": "cliente", "type": "belongsTo", "entity": "cliente", "fk": "cliente_id" }
  ],
  "rules": [
    { "id": "subtotal_gt_0", "assert": "subtotal > 0", "message": "Subtotal debe ser mayor a 0" }
  ],
  "permissions": {
    "read": ["admin", "editor"],
    "create": ["admin", "editor"],
    "update": ["admin", "editor"],
    "delete": ["admin"]
  }
}
```

---

# Contrato Entity - ES
Define una entidad para capa de datos y generacion de CRUD. Es el puente entre forms/grids y el kernel de base de datos.

## Ubicacion
- project/contracts/entities/*.entity.json

## Esquema (validacion)
- framework/contracts/schemas/entity.schema.json

## Requeridos
- type = "entity"
- name
- table
- fields
- permissions

## Notas
- El editor SQL visual debe generar este contrato.
- No escribir SQL manual: el contrato alimenta migraciones seguras.
- Si table.tenantScoped = true, el runtime aplica tenant_id aunque no este en fields.
