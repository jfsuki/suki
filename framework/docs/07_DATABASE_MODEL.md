# Database Model — EN
## Multi-tenant
All tables include tenant_id. Enforce tenant isolation.

## Core tables (proposal)
- tenants, users, roles, permissions
- forms, form_versions
- form_instances
- form_instance_data (json)
- grid_instance_data (json)
- audit_log

## DB Kernel (must exist)
A “mother” DB layer:
- QueryBuilder (no raw SQL in app layer)
- Parameterized queries only
- Allowlist columns/tables
- Automatic tenant scoping
- Safe filtering, pagination
- SQL generation (pure SQL output)
- Optional ORM mapping later

Security:
- Prevent SQL injection via bindings
- Validate identifiers against allowlist
- Block dangerous patterns
- Centralize escaping and validation

---

# Modelo de Base de Datos — ES
## Multi-tenant
Todas las tablas con tenant_id. Aislamiento obligatorio.

## Tablas base
- tenants, users, roles, permissions
- forms, form_versions
- form_instances
- form_instance_data (json)
- grid_instance_data (json)
- audit_log

## Kernel DB (obligatorio)
Capa “madre”:
- QueryBuilder (sin SQL directo en app)
- Queries parametrizadas
- Allowlist de tablas/columnas
- tenant scoping automático
- filtros/paginación seguros
- genera SQL puro internamente
- ORM opcional después

Seguridad:
- evita SQLi con bindings
- valida identificadores
- bloquea patrones peligrosos
- centraliza validación/escape
