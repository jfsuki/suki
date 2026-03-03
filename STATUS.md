# STATUS REVIEW — Misiones P0, P0.1, P1, P1.1, P1.2
Fecha de auditoria: 2026-03-03
Scope: auditoria verificable sin reescritura de arquitectura.

## 1) Estado por mision
| Mision | Estado | Evidencia (archivo/linea/test) | Riesgo si queda asi | Accion minima para cerrar | Complejidad |
|---|---|---|---|---|---|
| P0 Secrets Hardening | PASS | `.gitignore` lineas 2-4, 8-12, 23, 27; `project/.env.example` lineas 62-70, 110-132, 141-143; `project/config/env_loader.php` lineas 36-42, 45-87, 89-137; `framework/tests/secrets_guard_test.php` lineas 23-54, 56-111; comando `git ls-files -- project/.env` sin salida; test `php framework/tests/secrets_guard_test.php` => ok=true. | Si no se confirma commit/push del untrack de `.env`, puede reintroducirse en ramas futuras. | Commit y push del estado actual de secrets hygiene. | S |
| P0.1 Fix Drift domain_training_sync | PASS | `framework/scripts/sync_domain_training.php` linea 71 (source of truth), lineas 262/268/292 (errores explicativos); `framework/tests/domain_training_sync_test.php` lineas 19-23 (mensaje fail legible); test `php framework/tests/domain_training_sync_test.php` => PASS. | Drift puede reaparecer si se edita training sin correr `--check`. | Mantener gate en CI y bloquear merge si falla `domain_training_sync`. | S |
| P1 Runtime must enforce contracts | PASS | `framework/app/Core/RouterPolicyEvaluator.php` (nuevo evaluador), `framework/app/Core/IntentRouter.php` (enforcement integrado), `framework/app/Core/ChatAgent.php` lineas 1283-1309 (telemetria AgentOps); tests en verde: `router_contract_enforcement`, `action_allowlist_enforcement`, `enforcement_minimum_evidence_strict_blocks_when_missing`, `enforcement_warn_allows_but_logs_when_missing`, `gates_required_block_action_when_schema_invalid`, `gates_required_block_action_when_not_allowlisted`. | Si `ENFORCEMENT_MODE` queda en `off` en ambientes no controlados, la cobertura contractual se reduce. | Mantener `warn` en staging y `strict` en produccion controlada. | M |
| P1.1 Migrations discipline | PASS | `framework/app/Core/OperationalQueueStore.php` lineas 292-333 (bloqueo runtime schema), 335-344 (ALLOW_RUNTIME_SCHEMA), 346-367 (prod/local), 373-420 (missing tables/indexes); `framework/tests/operational_queue_schema_guard_test.php` lineas 24-34 y 61-66; migraciones `db/migrations/mysql/20260303_003_operational_queue_indexes.sql` y `db/migrations/sqlite/20260303_003_operational_queue_indexes.sql`; `php framework/tests/db_health.php` sin missing indexes. | Riesgo si se configura mal `ALLOW_RUNTIME_SCHEMA` fuera de local. | Mantener `ALLOW_RUNTIME_SCHEMA=0` en no-local y usar solo migraciones formales. | S |
| P1.2 Strangler ConversationGateway | PASS | Traits extraidos: `framework/app/Core/Agents/ConversationGateway.php` lineas 20-22; `ConversationGatewayHandlePipelineTrait.php` linea 9 (`handle`), `ConversationGatewayBuilderOnboardingTrait.php` linea 9 (`handleBuilderOnboardingCore`), `ConversationGatewayRoutingPolicyTrait.php` lineas 9 y 303 (`routeTraining`, `routeWorkflowBuilder`); medicion: `HEAD_LINES=10925`, `CURRENT_LINES=8855`, reduccion 18.95%; `qa_gate post` en verde incluyendo `chat_golden` y `chat_real_100`. | Archivo sigue grande (8855 lineas), deuda de mantenibilidad aun alta. | Continuar extraccion por bloques con metas de tamano por release. | M |
| 4.1 QA automatico hygiene + e2e publicos | PASS | `framework/app/Core/UnitTestRunner.php` lineas 40-42, 46-47, 816-826, 850-855; nuevos tests `framework/tests/framework_hygiene_test.php`, `framework/tests/public_excel_import_e2e_test.php`, `framework/tests/public_report_e2e_test.php`; `php framework/tests/run.php` => 51 pass/0 fail. | E2E actuales son smoke negativos (validan contrato de error), no cubren camino feliz end-to-end. | Agregar e2e positivos con fixtures reales para upload/report. | M |

## 2) Resultados de comandos (resumen verificable)
- `php framework/scripts/codex_self_check.php --strict` => `ok: true` (incluye `qa_pre` verde).
- `php framework/tests/run.php` => `passed: 51, failed: 0`.
- `php framework/scripts/qa_gate.php post` => `ok: true` (run/chat_acid/chat_golden/chat_real_20/db_health/chat_real_100/conversation_kpi_gate).
- `php framework/tests/domain_training_sync_test.php` => `PASS`.
- `php framework/tests/secrets_guard_test.php` => `{ "ok": true }`.
- `php framework/tests/db_health.php` => `ok: true`, `missing_tenant_id_index=[]`, `missing_created_at_index=[]`.
- `php framework/tests/llm_smoke.php` => `FAIL` por credenciales/proveedor (`gemini: API key expired`, `openrouter: User not found`).

## 3) Estado ejecutivo revalidado
- Estado general: AMARILLO.
- Bloqueante QA actual: `llm_smoke` rojo por credenciales (no por regresion de codigo).
- Riesgo critico de seguridad actual: BAJO-MEDIO, condicionado a cerrar commit/push de hygiene (el untrack de `.env` ya esta en index local, falta consolidar historial operativo del branch).
- Brecha exacta runtime vs canon:
  - `minimum_evidence` y `gates_required` ya se evalúan en runtime por `RouterPolicyEvaluator`.
  - Brecha residual no bloqueante: gates declarativos no criticos se reportan como `deferred_to_execution_engine` en `gate_results`.

## 4) Top 10 riesgos restantes (priorizados)
1. `ENFORCEMENT_MODE=off` por defecto en `.env.example` (requiere hardening por entorno).
2. `llm_smoke` fallando por claves expiradas/inexistentes (bloquea validacion de providers).
3. `ConversationGateway.php` aun muy grande (8855 lineas), riesgo de regresion por acoplamiento.
4. E2E publicos actuales no validan flujo feliz (solo contrato de error/control).
5. Working tree con cambios amplios no consolidados en commits trazables por mision.
6. Riesgo operacional si `ALLOW_RUNTIME_SCHEMA=1` se activa fuera de local.
7. Secrets guard basado en patrones; posibles falsos negativos de llaves nuevas/proveedor nuevo.
8. Contratos JSON no estan acoplados a un validador JSON Schema formal externo (solo validacion estructural interna).
9. Falta evidencia de pipeline CI remoto ejecutando todos los gates en cada PR (depende de ejecucion local).

## 5) Plan de cierre (max 10 pasos)
1. Forzar `ENFORCEMENT_MODE=warn` en staging y `strict` en produccion controlada.
2. Mantener y versionar `RouterPolicyEvaluator` con cobertura incremental de gates declarativos.
3. Reducir gates `deferred_to_execution_engine` en acciones de riesgo alto.
4. Corregir credenciales de LLM (Gemini/OpenRouter) y rerun `llm_smoke`.
5. Agregar e2e positivos para `excel_import.php` con fixture `.xlsx`.
6. Agregar e2e positivos para `report.php` validando payload/form real.
7. Ejecutar `qa_gate post` y publicar reporte versionado.
8. Continuar Strangler en ConversationGateway con otro recorte >=15% sobre base actual.
9. Consolidar commits por mision (P0, P0.1, P1, P1.1, P1.2) para trazabilidad.
10. Habilitar chequeo CI obligatorio con `run.php`, `qa_gate post`, `domain_training_sync`, `secrets_guard`, `db_health`, `llm_smoke`.

## 6) GO / NO-GO para fase de entrenamiento
- Decision: NO-GO.
- Motivos:
  - `llm_smoke` en rojo por credenciales/proveedores.
  - Se requiere enforcement activo (`warn/strict`) con trazas operativas verificadas en entorno objetivo.
