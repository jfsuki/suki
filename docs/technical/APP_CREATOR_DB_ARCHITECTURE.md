# APP CREATOR — Arquitectura de Base de Datos
Status: CANONICAL
Version: 1.0.0
Date: 2026-05-11
Scope: Cómo CreateAppSkill crea y maneja tablas de apps dinámicas a escala de millones de tenants.

---

## 1. Principio fundamental

**Las tablas de apps del catálogo se crean UNA SOLA VEZ por tipo de app, no una vez por tenant.**

Todos los tenants que instalan el mismo app comparten las mismas tablas físicas.
El aislamiento de datos entre empresas es SIEMPRE por fila, nunca por tabla separada.

```
✅ CORRECTO — Opción A (canónica):
Primera empresa instala vet_clinic → se crean tablas UNA VEZ:
  app_vet_clinic__pacientes  (tenant_id, app_id, ...)
  app_vet_clinic__citas      (tenant_id, app_id, ...)
  app_vet_clinic__historias  (tenant_id, app_id, ...)

Empresa A (tenant_1) instala vet_clinic → filas con tenant_id='tenant_1'
Empresa B (tenant_2) instala vet_clinic → filas con tenant_id='tenant_2'
Empresa C (tenant_3) instala vet_clinic → filas con tenant_id='tenant_3'
= las mismas 3 tablas físicas, 1M de empresas posibles

❌ PROHIBIDO — una tabla por tenant (anti-patrón):
Empresa A instala vet_clinic → p_abc123__pacientes, p_abc123__citas
Empresa B instala vet_clinic → p_def456__pacientes, p_def456__citas
...
Empresa 1M instala vet_clinic → 3M tablas físicas en MySQL → FALLA CATASTRÓFICA
```

---

## 2. Motor de base de datos

| Propósito | Motor | Ubicación |
|-----------|-------|-----------|
| Datos operacionales de todos los tenants | **MySQL** (suki_saas) | DB compartida |
| Metadatos del sistema (proyectos, sesiones, ai_agents) | **SQLite** | project/storage/meta/project_registry.sqlite |
| Desarrollo local / testing | SQLite o MySQL | configurable via DB_DRIVER |

**No confundir:** SQLite es solo para metadatos internos del sistema. Los datos de negocio de las apps creadas van SIEMPRE a MySQL.

---

## 3. Naming convention de tablas de apps

```
Formato:  app_{app_id}__{entity_name_snake_case}

Ejemplos:
  app_vet_clinic__pacientes
  app_vet_clinic__citas_medicas
  app_vet_clinic__historias_clinicas
  app_restaurant__mesas
  app_restaurant__pedidos
  app_dental__pacientes
  app_dental__tratamientos
  app_pos_retail__productos
  app_pos_retail__ventas
```

**Reglas de naming:**
- Solo `a-z`, `0-9`, `_`
- Prefijo `app_` siempre presente
- Entidad en snake_case plural
- Máximo 64 caracteres (límite MySQL para nombres de tabla)

---

## 4. Columnas obligatorias en toda tabla de app

El `AppSchemaDesigner` las inyecta automáticamente. El LLM NO las incluye (se las indicamos en el spec):

```sql
id         INTEGER      NOT NULL AUTO_INCREMENT PRIMARY KEY
tenant_id  VARCHAR(100) NOT NULL  -- aísla datos por empresa, ÍNDICE obligatorio
app_id     VARCHAR(120) NOT NULL  -- identifica el app del catálogo
created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
deleted_at DATETIME     NULL      -- soft delete, NUNCA borrar filas
```

**Índices obligatorios:**
```sql
INDEX idx_{table}_tenant      (tenant_id)
INDEX idx_{table}_tenant_id   (tenant_id, id)
INDEX idx_{table}_tenant_date (tenant_id, created_at)
```

---

## 5. StorageModel y TableNamespace

### StorageModel::CANONICAL (obligatorio para App Creator)
```php
// framework/app/Core/StorageModel.php
StorageModel::isCanonical()  // → true cuando PROJECT_STORAGE_MODEL=canonical
```
Cuando es `true`:
- `TableNamespace::resolve()` NO aplica prefijo de proyecto
- `BaseRepository` agrega `app_id` en INSERT y WHERE automáticamente
- Las tablas no tienen prefijo `p_<hash>__`

### Por qué NO usar DB_NAMESPACE_BY_PROJECT para apps del catálogo
El namespace por proyecto (`p_<hash>__tabla`) existe para aislar proyectos custom en hosting compartido de escala baja/media. Es INCOMPATIBLE con la estrategia de tablas compartidas para apps del catálogo.

Para el App Creator, el aislamiento NO es a nivel de tabla, es a nivel de fila con `tenant_id`.

---

## 6. Flujo de creación de tablas en CreateAppSkill

```
Usuario confirma diseño del app
         ↓
CreateAppSkill.executeCreation()
         ↓
AppSchemaDesigner.validateAndSanitize(rawSchema)
  → inyecta columnas base (id, tenant_id, app_id, timestamps, deleted_at)
  → valida naming y seguridad
         ↓
AppSchemaDesigner.toEntityMigratorFormat(schema)
  → convierte tipos SQL a tipos EntityMigrator
  → nombra entidades como "app_{app_id}__{entity}" ← CLAVE
  → sets table.tenantScoped=true, table.timestamps=true, table.softDelete=true
         ↓
EntityMigrator.migrateEntity(entityDef, apply=true)
  → CREATE TABLE IF NOT EXISTS app_{app_id}__{entity} (...)
  → Si la tabla ya existe → solo ADD COLUMN para columnas nuevas (idempotente)
  → Si ya existe y no hay cambios → no hace nada
         ↓
AppInstallService.seedAgents(tenantId, appId, metadata)
  → Crea/actualiza agentes en ai_agents para este tenant
  → El tenant puede usar las tablas compartidas desde este momento
```

---

## 7. Consultas correctas en tiempo de operación

Todo acceso a datos de una app del catálogo DEBE incluir `tenant_id` (y opcionalmente `app_id` si la tabla es compartida entre múltiples apps):

```php
// ✅ CORRECTO — BaseRepository aplica tenant scoping automáticamente
$pacientes = $repo->list(['tenant_id' => $tenantId]);

// ✅ CORRECTO — query directa con tenant scope
SELECT * FROM app_vet_clinic__pacientes
WHERE tenant_id = 'empresa_42'
  AND deleted_at IS NULL
ORDER BY created_at DESC
LIMIT 50;

// ❌ PROHIBIDO — sin tenant scope (datos de todos los tenants)
SELECT * FROM app_vet_clinic__pacientes;
```

---

## 8. Estrategia de escalabilidad

| Fase | Tenants | Estrategia | Cambios necesarios |
|------|---------|------------|-------------------|
| MVP | 1 — 500 | LEGACY: tablas shared + tenant_id | Sin cambios |
| Crecimiento | 500 — 10K | LEGACY: con índices optimizados | Añadir índices compuestos |
| Escala | 10K — 1M | CANONICAL: tablas shared + tenant_id + app_id | `PROJECT_STORAGE_MODEL=canonical` |
| Masivo | >1M | CANONICAL + sharding por tenant_range | MySQL Cluster, shard key=tenant_id |

**Decisión de sharding:** el shard key es siempre `tenant_id`. Una empresa (tenant) siempre va al mismo shard. Las consultas incluyen `WHERE tenant_id = ?` garantizando que nunca hacen cross-shard queries.

---

## 9. Tablas del sistema vs tablas de apps del catálogo

### Tablas del sistema (siempre presentes, no creadas por App Creator)
```
tenants, users, roles, permissions
ai_agents, app_catalog, app_versions
conversation_sessions, conversation_messages
audit_log, job_queue
```
Estas son gestionadas por `EntityMigrator` con sus entity contracts en `project/contracts/entities/`.

### Tablas de apps del catálogo (creadas dinámicamente por App Creator)
```
app_vet_clinic__pacientes
app_vet_clinic__citas
app_restaurant__mesas
...
```
Creadas por `CreateAppSkill` → `AppSchemaDesigner.toEntityMigratorFormat()` → `EntityMigrator`.
NO tienen entity contracts en `project/contracts/entities/` — viven en `AppMemoryService`.

---

## 10. Referencia de archivos

| Archivo | Rol |
|---------|-----|
| `framework/app/Core/Skills/CreateAppSkill.php` | Skill que orquesta la creación. `executeCreation()` llama al migrador. |
| `framework/app/Core/AppSchemaDesigner.php` | Valida schema del LLM, convierte a formato EntityMigrator, inyecta columnas base. |
| `framework/app/Core/EntityMigrator.php` | Ejecuta `CREATE TABLE IF NOT EXISTS`. Migración incremental (ADD COLUMN). |
| `framework/app/Core/StorageModel.php` | Determina LEGACY vs CANONICAL. Debe ser CANONICAL para App Creator. |
| `framework/app/Core/TableNamespace.php` | Con CANONICAL activo, NO aplica prefijo de proyecto. |
| `framework/app/Core/AppMemoryService.php` | Persiste schema + requirements + system_prompt post-creación. |
| `framework/app/Core/AppInstallService.php` | Crea/actualiza ai_agents para el tenant instalador. |
| `project/contracts/app_catalog.json` | Catálogo de tipos de apps. Actualizable en runtime por el agente. |
| `docs/canon/SUKI_ARCHITECTURE_CANON.md` | Sección 13: App Creator DB Law (ley inmutable). |

---

## 11. Pending — cambio de código requerido

El código actual en `CreateAppSkill.executeCreation()` llama `EntityMigrator.migrateEntity()` sin garantizar que el contexto sea `StorageModel::CANONICAL`. Si `DB_NAMESPACE_BY_PROJECT=1` está activo en `.env`, se crearán tablas con namespace de proyecto (anti-patrón).

**Fix pendiente:** En `CreateAppSkill.executeCreation()`, antes de llamar `EntityMigrator`:
1. Forzar `StorageModel::CANONICAL` temporalmente, O
2. Usar prefijo `app_{app_id}__` explícitamente en el nombre de entidad
3. Pasar el nombre ya prefijado a `toEntityMigratorFormat()` con `$appId`

Este fix está en la lista de gaps pendientes (POST sesión 2026-05-11).
