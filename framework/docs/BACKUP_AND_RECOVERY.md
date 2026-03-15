# BACKUP_AND_RECOVERY

## Objetivo
Resguardar datos de SUKI (tablas + metadata) antes de cambios de riesgo.

## Regla obligatoria
- Antes de tocar DB Kernel, migraciones, contratos de datos o limpieza masiva:
  - `php framework/scripts/db_backup.php`
- Si no hay backup reciente (<=24h), `codex_self_check --strict` falla.

## Que respalda el script
- Dump MySQL completo de `DB_NAME` en `*.sql.gz` por defecto.
- Fallback a `*.sql` solo si la compresion esta deshabilitada o falla y se preserva el dump plano.
- Copia de `project/storage/meta/project_registry.sqlite`.
- Manifest en `project/storage/backups/manifest.json`.

## Ubicacion de backups
- Carpeta por ejecucion:
  - `project/storage/backups/YYYYMMDD_HHMMSS/`
- Manifest:
  - `project/storage/backups/manifest.json`

## Politica operativa
- Cleanup automatico al final de cada backup.
- Retencion por defecto dependiente de `APP_ENV`:
  - `dev/test/local`: 3 backups / 3 dias
  - `staging`: 5 backups / 5 dias
  - `prod/production`: 7 backups / 7 dias
- Overrides opcionales:
  - `BACKUP_COMPRESS=0|1`
  - `BACKUP_RETENTION_RUNS=<n>`
  - `BACKUP_RETENTION_DAYS=<n>`
- El cleanup mantiene siempre al menos el backup mas reciente y elimina historicos sobrantes de forma deterministica.

## Restauracion rapida (MySQL)
1) Confirmar DB destino:
   - `mysql --host=127.0.0.1 --port=3306 --user=root -e "CREATE DATABASE IF NOT EXISTS suki_saas;"`
2) Restaurar dump:
   - si el backup es `*.sql.gz`, descomprimir primero a `*.sql`
   - luego importar:
     - `mysql --host=127.0.0.1 --port=3306 --user=root suki_saas < project/storage/backups/<run_id>/suki_saas.sql`
3) Restaurar registry (si aplica):
   - Reemplazar `project/storage/meta/project_registry.sqlite` con la copia del backup.

## Racional de almacenamiento
- El dump SQL comprimido reduce crecimiento en disco frente a `*.sql` plano.
- La retencion baja por defecto evita acumulacion explosiva en local/dev y hosting con cuota reducida.
- Produccion puede ampliar retencion solo por configuracion explicita.
