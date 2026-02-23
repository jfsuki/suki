# BACKUP_AND_RECOVERY

## Objetivo
Resguardar datos de SUKI (tablas + metadata) antes de cambios de riesgo.

## Regla obligatoria
- Antes de tocar DB Kernel, migraciones, contratos de datos o limpieza masiva:
  - `php framework/scripts/db_backup.php`
- Si no hay backup reciente (<=24h), `codex_self_check --strict` falla.

## Que respalda el script
- Dump MySQL completo de `DB_NAME`.
- Copia de `project/storage/meta/project_registry.sqlite`.
- Manifest en `project/storage/backups/manifest.json`.

## Ubicacion de backups
- Carpeta por ejecucion:
  - `project/storage/backups/YYYYMMDD_HHMMSS/`
- Manifest:
  - `project/storage/backups/manifest.json`

## Restauracion rapida (MySQL)
1) Confirmar DB destino:
   - `mysql --host=127.0.0.1 --port=3306 --user=root -e "CREATE DATABASE IF NOT EXISTS suki_saas;"`
2) Restaurar dump:
   - `mysql --host=127.0.0.1 --port=3306 --user=root suki_saas < project/storage/backups/<run_id>/suki_saas.sql`
3) Restaurar registry (si aplica):
   - Reemplazar `project/storage/meta/project_registry.sqlite` con la copia del backup.

## Politica de retencion
- El script mantiene 14 dias de historico por defecto.
- Ajustar retencion en `framework/scripts/db_backup.php` si se requiere.
