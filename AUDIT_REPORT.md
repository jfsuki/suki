# FULL AUDIT REPORT — Ejecución y Seguridad Pre-Entrenamiento
Fecha: 2026-03-03  
Alcance auditado: seguridad, runtime, migraciones/disciplina, AgentOps, preparación para entrenamiento.

## 1) Resumen ejecutivo
Estado global: **AMARILLO (NO-GO para entrenamiento)**.

Top 5 riesgos actuales:
1. **P0 seguridad**: endpoints `records/*` permiten lectura `GET` sin autenticación obligatoria.
2. **P0 correctitud**: `channels/whatsapp/webhook` procesa síncrono (no fast-ack + cola), riesgo de retries/duplicados por timeout.
3. **P0 correctitud/canon**: enforcement runtime no ejecuta completamente `minimum_evidence` y `resolve_criteria` del contrato.
4. **P0 operativo**: `llm_smoke` en rojo por credenciales (`GEMINI` expirado / `OPENROUTER` usuario inválido).
5. **P1 disciplina DB**: hay componentes fuera de cola operativa que aún hacen `ensureSchema()` en runtime.

### Resultado de comandos (evidencia)
- `php framework/scripts/codex_self_check.php --strict` -> `ok: true`.
- `php framework/tests/run.php` -> `40 passed / 0 failed`.
- `php framework/scripts/qa_gate.php post` -> `ok: true`.
- `php framework/tests/domain_training_sync_test.php` -> `PASS`.
- `php framework/tests/secrets_guard_test.php` -> `ok: true`.
- `php framework/tests/db_health.php` -> `ok: true`.
- `php framework/tests/llm_smoke.php` -> `FAIL` (provider errors: API key expirada / user not found).

## 2) Hallazgos P0 (bloqueantes)

### P0-SEC-001 — Webhook Alanube sin autenticación/anti-replay (CERRADO en este ciclo)
- Evidencia previa: `integrations/alanube/webhook` era público sin validación de firma/secret.
- Parche aplicado:
  - `project/public/api.php:244` (`verifyAlanubeWebhookRequest`).
  - `project/public/api.php:2235` (solo `POST`), `2240` (401 firma inválida), `2259` (TTL replay), `2263` (ignore duplicado).
  - `project/.env.example:53-54` (`ALANUBE_WEBHOOK_SECRET`, `ALANUBE_REPLAY_TTL_SEC`).
  - `framework/tests/security_channels_e2e_test.php:272-308` (test de secret + replay).
- Validación: `php framework/tests/security_channels_e2e_test.php` -> `ok: true`.

### P0-SEC-002 — Lectura de datos por `GET records/*` sin auth obligatoria (ABIERTO)
- Evidencia:
  - `ApiSecurityGuard` sólo exige auth en mutaciones: `framework/app/Core/ApiSecurityGuard.php:118-123`.
  - `records/*` permite `GET` lectura/listado: `project/public/api.php:2304-2319`.
- Riesgo: exposición de datos (BOLA/IDOR por objeto/filtros).
- Fix mínimo propuesto (decisión ambigua):
  - Opción A: exigir sesión para `records/*` también en `GET`.
    - Pros: cierre directo del riesgo.
    - Contras: puede romper flujos que hoy asumen lectura pública.
  - Opción B: mantener público pero sólo con token firmado por app/tenant y TTL corto.
    - Pros: compatibilidad de lectura compartida controlada.
    - Contras: mayor complejidad de implementación/operación.
  - **Recomendación**: Opción A en `API_SECURITY_STRICT=1` como default de producción.

### P0-COR-003 — WhatsApp webhook no cumple fast-ack + cola (ABIERTO)
- Evidencia:
  - Ejecuta `ChatAgent` en request webhook: `project/public/api.php:957`.
  - Envía respuesta externa antes de contestar webhook: `project/public/api.php:984`.
  - Canon/plan exige fast-ack + async: `framework/docs/HOSTING_MIGRATION_PLAN.md:33-36`.
- Riesgo: timeout del proveedor, retries, duplicados y sobrecarga.
- Fix mínimo: replicar patrón Telegram (`enqueueIfNotExists` + `200`) para WhatsApp y procesar en `bin/worker.php`.

### P0-COR-004 — Enforcement runtime parcial vs contratos canon (ABIERTO)
- Evidencia de contrato:
  - `docs/contracts/router_policy.json:19` (`resolve_criteria`), `33` (`minimum_evidence`).
  - `docs/contracts/action_catalog.json:14-22` (campos obligatorios), `41-47` (`gates_required`).
- Evidencia runtime:
  - Carga contratos y order: `framework/app/Core/IntentRouter.php:38-45`.
  - No valida explícitamente `resolve_criteria`/`minimum_evidence` por contrato; sólo usa `missing_evidence_actions` al bloquear: `framework/app/Core/IntentRouter.php:265-268`.
  - `gates_required` se adjunta a telemetry pero no se ejecuta como gate real: `framework/app/Core/IntentRouter.php:90-91`.
- Riesgo: desviación de canon y decisiones ejecutables sin evidencia/gates completos.
- Fix mínimo: `MinimumEvidenceEvaluator` + ejecución real de `gates_required` antes de `execute_command`.

### P0-OPS-005 — LLM smoke en rojo por credenciales (ABIERTO)
- Evidencia: `php framework/tests/llm_smoke.php` falla por `API key expired` y `User not found`.
- Riesgo: no hay validación real de fallback/proveedores previo a entrenamiento operativo.
- Fix mínimo: rotar/actualizar llaves y rerun `llm_smoke` hasta verde.

## 3) Hallazgos P1 (importantes)

### P1-DB-001 — Runtime schema fuera de disciplina en módulos no-queue
- Evidencia:
  - `IntegrationStore` fuerza `ensureTables()`: `framework/app/Core/IntegrationStore.php:17`.
  - `IntegrationMigrator` crea tablas en runtime: `framework/app/Core/IntegrationMigrator.php:17-80`.
  - `SqlMetricsRepository` ejecuta `ensureSchema()` y `ensureColumn()` en runtime: `framework/app/Core/SqlMetricsRepository.php:21`, `207-280`.
- Riesgo: cambios de esquema silenciosos en producción.
- Recomendación: replicar política `ALLOW_RUNTIME_SCHEMA` + migraciones formales para estos módulos.

### P1-SEC-002 — Riesgo SSRF en integración saliente configurable
- Evidencia:
  - `AlanubeClient` acepta cualquier `baseUrl` sin allowlist: `framework/app/Core/AlanubeClient.php:13-20`.
  - Schema sólo exige string mínima, no URL/host policy: `framework/contracts/schemas/integration.schema.json:19`.
- Riesgo: llamadas salientes a destinos no autorizados.
- Recomendación: validar esquema `https` + deny private ranges + allowlist por `INTEGRATION_ALLOWED_HOSTS`.

### P1-OBS-003 — Logging potencialmente sensible
- Evidencia:
  - Telemetría persistida en JSONL por tenant: `framework/app/Core/Agents/Telemetry.php:16-28`.
  - `ChatAgent` incluye `message` completo en eventos: `framework/app/Core/ChatAgent.php:186`, `287`, `351`, `407`.
- Riesgo: PII o datos sensibles en logs operativos.
- Recomendación: `TelemetryRedactor` para campos sensibles (`password`, `token`, `authorization`, etc.) y truncado de texto.

### P1-CONTRACT-004 — Catálogo de acciones incompleto vs comandos runtime
- Evidencia:
  - Runtime mapea `crud.query/read/update/delete`: `framework/app/Core/IntentRouter.php:178-187`.
  - Contrato sólo define `crud.create`: `docs/contracts/action_catalog.json:32`.
- Riesgo: en `strict`, comandos reales pueden quedar bloqueados o inconsistentes.
- Recomendación: ampliar `action_catalog.json` por addenda (sin reescritura) o ajustar mapeo runtime canónico.

### P1-CONFIG-005 — Drift de configuración no utilizada
- Evidencia:
  - `TELEGRAM_REPLAY_TTL_SEC` está en `.env.example`: `project/.env.example:116`.
  - No hay uso en runtime (`rg` sin matches en código de Telegram).
- Riesgo: falsa sensación de cobertura anti-replay configurable.
- Recomendación: usar la variable o retirarla del template/documentación.

## 4) No issues found (verificado)
- Secrets hygiene:
  - `.gitignore` robusto (`.env`, claves, secrets): `.gitignore:2-14`.
  - `project/.env` no trackeado (`git ls-files -- project/.env` sin salida).
  - `secrets_guard` verde.
- Domain training sync:
  - Gate verde y source-of-truth explícito en `framework/scripts/sync_domain_training.php:71`.
- Telegram webhook pipeline:
  - idempotency key + enqueue + 200 inmediato: `project/public/api.php:797-801`, `836-854`.
- Guard de esquema en cola operativa:
  - `OperationalQueueStore` bloquea runtime schema en prod: `framework/app/Core/OperationalQueueStore.php:292-333`.
  - `operational_queue_schema_guard_test` verde.
- Canon embeddings consistente:
  - `docs/canon/TEXT_OS_ARCHITECTURE.md:46-48` (`gemini-embedding-001`, `768`, `Cosine`).

## 5) Plan de remediación (orden recomendado, máximo 12)
1. Forzar `ENFORCEMENT_MODE=warn` en staging y `strict` en producción controlada.
2. Implementar `MinimumEvidenceEvaluator` consumiendo `router_policy.json`.
3. Ejecutar `gates_required` como gates reales en acciones EXECUTABLE.
4. Cerrar `records/* GET` con auth (o token firmado de lectura con TTL).
5. Migrar `channels/whatsapp/webhook` a enqueue + fast-ack.
6. Conectar `bin/worker.php` para procesar también `whatsapp.inbound`.
7. Externalizar schema-changes de `IntegrationMigrator`/`SqlMetricsRepository` a migraciones formales.
8. Añadir validación de host/SSRF para `base_url` de integraciones.
9. Incorporar redacción de campos sensibles en telemetría y webhook payload logs.
10. Alinear `action_catalog.json` con comandos runtime reales (addenda versionada).
11. Rotar llaves de LLM y dejar `llm_smoke` en verde.
12. Re-ejecutar gate completo (`run`, `qa_gate post`, `llm_smoke`) y bloquear merge si falla.

## 6) GO / NO-GO entrenamiento
Decisión actual: **NO-GO**.

Criterios NO-GO activos:
- `P0-SEC-002` abierto (lectura sin auth en `records/*`).
- `P0-COR-003` abierto (WhatsApp sync sin queue fast-ack).
- `P0-COR-004` abierto (enforcement de evidencia/gates parcial).
- `llm_smoke` en rojo por credenciales.

## Referencias externas usadas para contraste (fuentes primarias)
- Telegram Bot API (`secret_token` y header `X-Telegram-Bot-Api-Secret-Token`):  
  https://core.telegram.org/bots/api
- OWASP API Security (BOLA):  
  https://owasp.org/API-Security/editions/2019/en/0xa1-broken-object-level-authorization/
- OWASP SSRF Prevention Cheat Sheet:  
  https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
- WhatsApp Cloud API SDK (Meta, webhook verification y `X-Hub-Signature-256`):  
  https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/webhooks/start/

## Security note (2026-03-03): records read protection
- `records/*` GET ahora requiere una de estas dos opciones:
  - sesion autenticada valida, con `tenant_id` consistente; o
  - token firmado de lectura (`records:read`) con HMAC SHA-256 y `exp` (TTL).
- Variables nuevas:
  - `RECORDS_READ_SECRET` (rotar inmediatamente si hubo exposicion)
  - `RECORDS_READ_TTL_SEC` (default `900`)
- Formato de token:
  - `base64url(payload_json) + "." + base64url(hmac_sha256(payload_json, RECORDS_READ_SECRET))`
- Auditoria de acceso:
  - `project/storage/security/records_read_access.log.jsonl`
  - sin token ni payload completo, solo `request_id`, endpoint, decision, modo de auth y tenant.
- Politica de rotacion:
  1. generar nuevo `RECORDS_READ_SECRET`,
  2. desplegar config en todos los nodos,
  3. invalidar links/tokens previos,
  4. revisar logs de acceso denegado/permitido post-rotacion.

## Records Integrity (2026-03-03): write protection hardening P0-S1
- Mutaciones `records/*` (`POST`, `PUT`, `PATCH`, `DELETE`) ahora exigen autenticacion real de sesion.
- CSRF por si solo no habilita escritura: sin `auth_user` la mutacion se bloquea.
- `tenant_id` para mutaciones se liga a la sesion autenticada:
  - no se confia `tenant_id` de payload/header/query;
  - si el request intenta override y no coincide con sesion, se bloquea con respuesta generica.
- Se agrego auditoria minima de mutaciones:
  - `project/storage/security/records_mutation_access.log.jsonl`
  - campos: `request_id`, endpoint, metodo, decision, auth_mode, tenant_id (sanitizado), reason.
