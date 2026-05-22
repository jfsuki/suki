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

## App Creator Architecture — Shared Tables per app_type (2026-05-11)
When CreateAppSkill installs an app from the catalog, tables are created ONCE globally per app_type.
All tenants using the same app share the same physical tables. Row-level isolation via tenant_id.

Table naming convention:
  app_{app_id}__{entity_name}
  Examples: app_vet_clinic__pacientes, app_restaurant__mesas

Rules:
- StorageModel MUST be CANONICAL for all app catalog table creation
- DB_NAMESPACE_BY_PROJECT must NOT apply to app template tables
- Every app table requires: tenant_id (NOT NULL), app_id (NOT NULL)
- CREATE TABLE IF NOT EXISTS — idempotent, safe for multiple tenant installs
- INDEX (tenant_id, id) and INDEX (tenant_id, created_at) required on every app table

Anti-pattern (forbidden):
- Creating separate physical tables per tenant for the same app_type
- Using project hash namespace (p_<hash>__table) for catalog app tables

Scalability:
  1-10K tenants:      LEGACY mode, shared tables + tenant_id, single MySQL DB
  10K-1M tenants:     CANONICAL mode + app_id column, shard by tenant_range
  >1M tenants:        CANONICAL + MySQL Cluster, shard key = tenant_id hash

See: docs/technical/APP_CREATOR_DB_ARCHITECTURE.md
See: docs/canon/SUKI_ARCHITECTURE_CANON.md section 13

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

## Arquitectura App Creator — Tablas compartidas por app_type (2026-05-11)
Cuando CreateAppSkill instala un app del catálogo, las tablas se crean UNA SOLA VEZ globalmente por tipo de app.
Todos los tenants que usan el mismo app comparten las mismas tablas físicas. Aislamiento por fila con tenant_id.

Convención de nombres:
  app_{app_id}__{nombre_entidad}
  Ejemplos: app_vet_clinic__pacientes, app_restaurant__mesas

Reglas:
- StorageModel DEBE ser CANONICAL para toda creación de tablas del catálogo de apps
- DB_NAMESPACE_BY_PROJECT NO debe aplicarse a tablas de app templates
- Toda tabla de app requiere: tenant_id (NOT NULL), app_id (NOT NULL)
- CREATE TABLE IF NOT EXISTS — idempotente, seguro para instalaciones de múltiples tenants
- INDEX (tenant_id, id) e INDEX (tenant_id, created_at) requeridos en cada tabla de app

Anti-patrón (PROHIBIDO):
- Crear tablas físicas separadas por tenant para el mismo tipo de app
- Usar namespace por hash de proyecto (p_<hash>__tabla) para tablas de apps del catálogo

Escalabilidad:
  1-10K tenants:    LEGACY, tablas shared + tenant_id, una DB MySQL
  10K-1M tenants:   CANONICAL + columna app_id, sharding por rango de tenant
  >1M tenants:      CANONICAL + MySQL Cluster, shard key = hash de tenant_id

Ver: docs/technical/APP_CREATOR_DB_ARCHITECTURE.md
Ver: docs/canon/SUKI_ARCHITECTURE_CANON.md sección 13


