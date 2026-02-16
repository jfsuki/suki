# DB Index Checklist + QueryBuilder Rules

Objetivo: consultas ultra-rapidas con millones de registros y miles de usuarios.

## Reglas base (no negociables)
- TODA tabla multi-tenant debe tener `tenant_id`.
- Toda consulta debe filtrar por `tenant_id` primero.
- Usar indices compuestos segun uso real.
- Paginacion por indice (LIMIT + cursor cuando sea posible).
- Prohibido full scan en tablas grandes.

## Checklist por tipo de tabla

### 1) Tablas core
**core_users**
- PK: (tenant_id, id)
- IDX: (tenant_id, email)
- IDX: (tenant_id, status, created_at)

**core_roles**
- PK: (tenant_id, id)
- IDX: (tenant_id, name)

**core_permissions**
- PK: (tenant_id, id)
- IDX: (tenant_id, role_id)

### 2) Entidades (records)
**records_{entity}**
- PK: (tenant_id, id)
- IDX: (tenant_id, created_at)
- IDX: (tenant_id, status, created_at) si hay estado
- IDX: (tenant_id, name) si hay busqueda por nombre

### 3) Grids / detalle
**{entity}__{grid}**
- PK: (tenant_id, id)
- IDX: (tenant_id, parent_id)
- IDX: (tenant_id, parent_id, created_at)

### 4) Integraciones
**integration_connections**
- PK: (tenant_id, id)
- IDX: (tenant_id, provider)

**integration_documents**
- PK: (tenant_id, id)
- IDX: (tenant_id, external_id)
- IDX: (tenant_id, entity, record_id)

### 5) Memorias + chat
**mem_global**
- IDX: (category, updated_at)

**mem_tenant**
- PK: (tenant_id, key)

**mem_user**
- PK: (tenant_id, user_id, key)

**chat_log**
- IDX: (tenant_id, session_id, created_at)

**mem_action_cache**
- IDX: (tenant_id, intent_hash, updated_at)

### 6) Auditoria + outbox
**audit_log**
- IDX: (tenant_id, created_at)
- IDX: (tenant_id, entity, record_id)

**outbox_jobs**
- IDX: (tenant_id, status, run_at)

## Reglas QueryBuilder (forzar indices)
1) Rechazar query sin `tenant_id`.
2) No permitir `SELECT *` en tablas grandes.
3) Solo campos permitidos (allowlist).
4) Ordenar por columna indexada (default: created_at).
5) En tablas grandes usar paginacion por cursor.
6) Si falta indice requerido -> log warning + bloquear en prod.

## Reglas de rendimiento
- Todos los endpoints deben usar LIMIT y OFFSET/cursor.
- Si una query tarda > 500ms => registrar en slow_log interno.
- Si proceso > X segundos => enviar a outbox + reintento.

