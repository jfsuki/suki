# App Manifest Contract - EN
This contract defines global app metadata, database strategy, registry tracking, integrations, and process definitions (UI + chat).

## Location
- project/contracts/app.manifest.json

## Schema (validation)
- framework/contracts/schemas/app.manifest.schema.json

## Required top-level fields
- schema_version (string, e.g. "1.0")
- app
- db
- registry

## Field summary
### app
- app.id (slug, lowercase, a-z0-9_-)
- app.name
- app.description (optional)
- app.status: draft | active | archived
- app.tenant_mode: shared | isolated
- app.default_locale (optional)
- app.timezone (optional)
- app.version (optional)

### db
- db.strategy: shared_schema | dedicated_db
- db.schema_source: master | custom
- db.master_schema_id (required if schema_source = master)
- db.connection_alias (optional)
- db.database_name (optional)
- db.provision.create_on_first_run (optional)
- db.provision.db_name_prefix (optional)
- db.provision.migrations: auto | manual | off

### registry
- registry.track_changes
- registry.track_routes
- registry.track_config
- registry.track_db_profile
- registry.track_integrations
- registry.track_processes

### auth (optional)
- auth.login_field: username | email
- auth.email_optional
- auth.mfa: off | optional | required
- auth.roles[], auth.default_role
- auth.password_policy

### integrations (optional)
- integrations[].id, type, provider
- integrations[].country, integrations[].environment
- integrations[].base_url
- integrations[].auth (bearer/api_key/oauth2/basic/jwt/custom) + token_env
- integrations[].webhooks[]

### processes (optional)
- processes[].id
- processes[].ui_form (optional)
- processes[].intents[] (chat triggers)
- processes[].requires[] (required inputs)
- processes[].actions[] (validate/persist/emit/etc)
- processes[].permissions[]
- processes[].async / processes[].confirm

### extensions (optional)
- Free-form custom fields (kept for future expansion).

## Example
```json
{
  "schema_version": "1.0",
  "app": {
    "id": "miapp",
    "name": "Mi App",
    "status": "draft",
    "tenant_mode": "shared"
  },
  "db": {
    "strategy": "shared_schema",
    "schema_source": "master",
    "master_schema_id": "suki_base_v1",
    "connection_alias": "default",
    "provision": {
      "create_on_first_run": true,
      "db_name_prefix": "tenant_",
      "migrations": "auto"
    }
  },
  "registry": {
    "track_changes": true,
    "track_routes": true,
    "track_config": true,
    "track_db_profile": true,
    "track_integrations": true,
    "track_processes": true
  },
  "auth": {
    "login_field": "username",
    "email_optional": true,
    "mfa": "optional",
    "roles": ["admin", "editor", "viewer"],
    "default_role": "admin",
    "password_policy": { "min_length": 8, "require_numbers": true }
  },
  "integrations": [
    {
      "id": "dian_provider",
      "type": "e-invoicing",
      "provider": "ProveedorX",
      "base_url": "https://api.proveedor.com",
      "auth": { "type": "api_key", "ref": "vault:dian_key" },
      "webhooks": ["invoice.created", "invoice.accepted"]
    }
  ],
  "processes": [
    {
      "id": "crear_factura",
      "ui_form": "fact.form.json",
      "intents": ["crear factura", "facturar"],
      "requires": ["cliente", "items"],
      "actions": ["validate", "persist", "emit_invoice"],
      "permissions": ["facturas.create"],
      "async": true,
      "confirm": true
    }
  ]
}
```

## Notes
- UI and chat must execute the same process pipeline.
- External integrations must be async, auditable, and retry-safe.
- Use registry flags to track all configuration changes.
- Runtime validation is enforced at bootstrap; cache stored in /project/storage/cache/manifest.schema.cache.json.
- The JSON editor includes a friendly DB/process panel and generates this manifest.

---

# Contrato App Manifest - ES
Este contrato define metadatos globales del app, estrategia de base de datos, registro de cambios, integraciones y procesos (UI + chat).

## Ubicacion
- project/contracts/app.manifest.json

## Esquema (validacion)
- framework/contracts/schemas/app.manifest.schema.json
- Validar con JSON Schema (draft-07).

## Requeridos
- schema_version
- app
- db
- registry

## Resumen de campos
### app
- app.id (slug, lowercase, a-z0-9_-)
- app.name
- app.description (opcional)
- app.status: draft | active | archived
- app.tenant_mode: shared | isolated
- app.default_locale (opcional)
- app.timezone (opcional)
- app.version (opcional)

### db
- db.strategy: shared_schema | dedicated_db
- db.schema_source: master | custom
- db.master_schema_id (requerido si schema_source = master)
- db.connection_alias (opcional)
- db.database_name (opcional)
- db.provision.create_on_first_run (opcional)
- db.provision.db_name_prefix (opcional)
- db.provision.migrations: auto | manual | off

### registry
- registry.track_changes
- registry.track_routes
- registry.track_config
- registry.track_db_profile
- registry.track_integrations
- registry.track_processes

### auth (opcional)
- auth.login_field: username | email
- auth.email_optional
- auth.mfa: off | optional | required
- auth.roles[], auth.default_role
- auth.password_policy

### integrations (opcional)
- integrations[].id, type, provider
- integrations[].country, integrations[].environment
- integrations[].base_url
- integrations[].auth (bearer/api_key/oauth2/basic/jwt/custom) + token_env
- integrations[].webhooks[]

### processes (opcional)
- processes[].id
- processes[].ui_form (opcional)
- processes[].intents[] (triggers chat)
- processes[].requires[] (inputs requeridos)
- processes[].actions[] (validate/persist/emit/etc)
- processes[].permissions[]
- processes[].async / processes[].confirm

### extensions (opcional)
- Campos libres para expansion futura.

## Ejemplo minimo
```json
{
  "schema_version": "1.0",
  "app": {
    "id": "miapp",
    "name": "Mi App",
    "status": "draft",
    "tenant_mode": "shared"
  },
  "db": {
    "strategy": "shared_schema",
    "schema_source": "master",
    "master_schema_id": "suki_base_v1"
  },
  "registry": {
    "track_changes": true,
    "track_routes": true,
    "track_config": true,
    "track_db_profile": true,
    "track_integrations": true,
    "track_processes": true
  }
}
```

## Notas
- UI y chat deben ejecutar el mismo pipeline.
- Integraciones externas deben ser async y auditables.
- Se valida en runtime al iniciar; cache en /project/storage/cache/manifest.schema.cache.json.
- El editor JSON incluye panel amigable de DB/procesos y genera este manifest.
