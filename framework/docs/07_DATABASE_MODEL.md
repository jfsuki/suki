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
- projects_registry, project_routes, project_configs
- project_changes, project_db_profiles
- integration_providers, integration_credentials
- integration_runs, integration_webhooks
- process_definitions, process_runs, process_steps
- conversation_sessions, conversation_messages
- job_queue

## DB Kernel (must exist)
A “mother” DB layer:
- QueryBuilder (no raw SQL in app layer)
- Parameterized queries only
- Allowlist columns/tables
- Automatic tenant scoping
- Safe filtering, pagination
- SQL generation (pure SQL output)
- Auto-migrations from entity contracts (create-if-missing)
- Optional ORM mapping later

## DB env (runtime)
- DB_DRIVER, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
- DB_PATH (solo sqlite)
- TENANT_ID (opcional para pruebas locales)
- DB_NAMESPACE_BY_PROJECT=1 (opcional, crea tablas fisicas por proyecto: p_<hash>__tabla)

Table namespace mode (shared hosting):
- Use only for isolation in low/medium scale.
- Keep tenant_id + indexes even with namespaced tables.
- Not recommended for millions of apps in one DB (too many tables, metadata locks, open table cache pressure).
- For very large scale: shared canonical tables + app_id/tenant_id columns, then shard by tenant.

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
- projects_registry, project_routes, project_configs
- project_changes, project_db_profiles
- integration_providers, integration_credentials
- integration_runs, integration_webhooks
- process_definitions, process_runs, process_steps
- conversation_sessions, conversation_messages
- job_queue

## Kernel DB (obligatorio)
Capa “madre”:
- QueryBuilder (sin SQL directo en app)
- Queries parametrizadas
- Allowlist de tablas/columnas
- tenant scoping automático
- filtros/paginación seguros
- genera SQL puro internamente
- Migraciones automáticas desde contratos (create-if-missing)
- ORM opcional después

## Variables de entorno DB
- DB_DRIVER, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
- DB_PATH (solo sqlite)
- TENANT_ID (opcional para pruebas locales)
- DB_NAMESPACE_BY_PROJECT=1 (opcional, crea tablas fisicas por proyecto: p_<hash>__tabla)

Modo namespace por proyecto (hosting compartido):
- Sirve para aislar en escala baja/media.
- Mantener tenant_id + indices incluso con tablas namespaced.
- No recomendado para millones de apps en una sola BD (demasiadas tablas, metadata locks, presion en cache de tablas abiertas).
- Para escala alta: tablas canonicas compartidas + columnas app_id/tenant_id, y sharding por tenant.

Seguridad:
- evita SQLi con bindings
- valida identificadores
- bloquea patrones peligrosos
- centraliza validación/escape


