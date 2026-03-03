# HOSTING_MIGRATION_PLAN (2026-02-21)

## Objetivo
Evitar colapso en hosting compartido (cPanel) y migrar por fases a una arquitectura estable para miles de apps/usuarios.

## Estado actual
- `DB_NAMESPACE_BY_PROJECT=1` activo.
- Tablas fisicas namespaced por proyecto: `p_<hash>__<tabla>`.
- Guardrail activo: `DB_MAX_TABLES_PER_PROJECT` en `EntityMigrator`.
- Script de salud DB: `php framework/tests/db_health.php`.

## Riesgo tecnico del modelo actual
- Bueno para etapa inicial (MVP, pocos proyectos por cuenta).
- Riesgo alto si crece demasiado en una sola base:
  - demasiadas tablas,
  - metadata locks,
  - presion en cache de tablas abiertas,
  - backups lentos.

## Estrategia recomendada (sin romper compatibilidad)

### Fase A — Shared hosting (ahora)
Objetivo: estabilidad inmediata.

1. Mantener namespace por proyecto (`DB_NAMESPACE_BY_PROJECT=1`).
2. Limitar tablas por proyecto (`DB_MAX_TABLES_PER_PROJECT`).
3. Ejecutar salud DB diaria:
   - `php framework/tests/db_health.php > project/storage/logs/db_health.json`.
4. Politicas:
   - no crear tablas duplicadas,
   - usar indices `tenant_id` y `created_at`,
   - purgar tablas demo/acid fuera de horario.
5. Worker de cola por cron (webhook fast-ack + procesamiento asincrono):
   - comando recomendado:
     `* * * * * php /path/bin/worker.php --once`
   - objetivo: cada webhook responde rapido (HTTP 200) y el procesamiento ocurre fuera de la peticion web.

### Fase B — VPS administrado (proximo paso)
Objetivo: pasar de “hosting web” a “servicio de aplicaciones”.

1. Migrar a VPS/Cloud administrado con MySQL dedicado.
2. Separar:
   - servidor app PHP,
   - servidor DB.
3. Mantener namespace por proyecto temporalmente.
4. Activar observabilidad:
   - slow query log,
   - tiempos p95 por endpoint,
   - conteo de tablas por proyecto.

### Fase C — Modelo canonico (escala alta)
Objetivo: soportar miles/millones sin explosion de tablas.

1. Introducir tablas compartidas por dominio (clientes, facturas, items, etc.).
2. Agregar columnas obligatorias:
   - `tenant_id`,
   - `app_id` (project_id),
   - `created_at`,
   - `updated_at`.
3. Migracion gradual:
   - dual-write temporal (namespace + canonico),
   - validacion de conteos/hash por lote,
   - switch de lectura por bandera,
   - retiro progresivo de tablas namespaced.

## Plan de migracion tecnica (namespace -> canonico)
1. Catalogo de tablas namespaced por proyecto.
2. Crear tablas canonicas con indices compuestos:
   - `(tenant_id, app_id, id)`,
   - `(tenant_id, app_id, created_at)`,
   - indices de filtros de negocio.
3. Backfill por lotes (id/cursor) con checksum.
4. Activar dual-write en `CommandLayer`.
5. Activar read-shadow (comparar resultados).
6. Cutover por proyecto.
7. Congelar namespace para proyecto migrado.
8. Limpieza final de tablas legacy.

## Reglas de decision de hosting (resumen)
- Si `namespaced_total` > 300 tablas por cuenta: salir de shared.
- Si p95 CRUD > 250ms sostenido: mover DB a servidor dedicado.
- Si crecimiento > 50 proyectos/mes: planificar Fase C.

## Checklist operativo semanal
- [ ] Ejecutar `db_health.php`.
- [ ] Revisar tablas sin indice `tenant_id`.
- [ ] Revisar crecimiento de `schema_migrations`.
- [ ] Limpiar residuos de pruebas.
- [ ] Revisar p95/p99 de endpoints CRUD y chat.

## Referencias operativas
- Dongee hosting compartido (planes + cPanel): https://www.dongee.com/hosting-
- Dongee limites de inodos (shared): https://soporte.dongee.com/es/articles/2662837-que-es-un-inodo-y-cuales-son-los-limites-de-inodos-en-dongee
- Dongee Cloud AMD (VPS/Cloud): https://www.dongee.com/hosting/amd/usd
- cPanel SQL/open files tuning: https://docs.cpanel.net/whm/server-configuration/tweak-settings/sql/116/
- Practica recomendada MySQL open tables: https://cloud.google.com/sql/docs/mysql/recommender-high-number-of-open-tables
