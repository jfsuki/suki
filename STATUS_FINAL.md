# STATUS FINAL - hardening audit closeout

Cut date: 2026-03-04

## EXEC_STATUS
- status: **Amarillo**
- top_blocker: `llm_smoke` fail por credenciales invalidas/expiradas en proveedores LLM.
- readiness_for_training: **NO-GO**

### reason_go_nogo
1. `llm_smoke` en FAIL con `API key expired` (Gemini) y `User not found` (OpenRouter).
2. Seguridad P0 de `chat/message` ya cerrada y validada por tests; el unico bloqueo operativo restante es credenciales LLM.

## Mission status

| Mision | Estado | Evidencia |
|---|---|---|
| P0 Secrets Hardening | PASS | `secrets_guard` PASS en `framework/tests/run.php` |
| P0.1 Drift domain_training_sync | PASS | `domain_training_sync` PASS (runner y test directo) |
| P1 Runtime contract enforcement | PASS | `router_contract_enforcement` + `action_allowlist_enforcement` PASS |
| P1.1 Migration discipline | PASS | `operational_queue_schema_guard` + `schema_runtime_guard` PASS |
| P1.2 Strangler ConversationGateway | PARTIAL | No hay metrica comparativa actual de reduccion en este corte |
| P0-S1 Records mutations auth+tenant | PASS | `records_mutation_*` suite PASS |
| P0-S2 Webhooks fail-closed+methods | PASS | `telegram_rejects_get`, `whatsapp_rejects_put`, `webhook_*` PASS |
| P1-E1 Enforcement default by env | PASS | `enforcement_default_by_env` PASS |
| P1-E2 strict stability chat suites | PASS | `ENFORCEMENT_MODE=strict` + `chat_golden` 24/24 + `chat_real_100` 100/100 |
| P1-R1 Worker pipeline real | PASS | `worker_processes_queued_whatsapp_message`, `worker_respects_idempotency`, `worker_logs_route_path` PASS |
| P0-CHAT-CLOSE bypass auth chat/message | PASS | `chat_exec_*` + `redteam_poc_chat_exec` PASS |
| P1-DB-CLEAN test artifacts schema debt | PASS | `db_health` sin warnings luego de cleanup namespaced + teardown |

## SECURITY
- chat/message ejecutables protegidos: **PASS**
  - hard-stop en `framework/app/Core/ChatAgent.php`
  - hard gates incondicionales en `framework/app/Core/RouterPolicyEvaluator.php`
- records GET/mutations protegidos: **PASS**
  - suites `records_get_*` y `records_mutation_*` en verde
- webhooks fail-closed + method hardening: **PASS**
  - validado por tests dedicados de Telegram/WhatsApp + fail-closed
- logs sensibles sanitizados: **PASS**
  - `sensitive_log_redaction` PASS
- tenant isolation ejecutables: **PASS**
  - chat tenant binding + records cross-tenant block PASS

## ENFORCEMENT
- `gates_required` P0 no-deferred: **PASS**
- hard gates no degradan en `warn`: **PASS**
- strict estable en suites de chat: **PASS**

## QA_GATES (validated this cut)
- `php framework/tests/run.php`: **PASS** (71 pass / 0 fail / 0 warned)
- `php framework/scripts/qa_gate.php post`: **PASS**
- `ENFORCEMENT_MODE=strict php framework/tests/run.php`: **PASS** (71/0/0)
- `ENFORCEMENT_MODE=strict php framework/tests/chat_golden.php`: **PASS** (24/24)
- `ENFORCEMENT_MODE=strict php framework/tests/chat_real_100.php`: **PASS** (100/100)
- `php framework/tests/domain_training_sync_test.php`: **PASS**
- `php framework/tests/secrets_guard_test.php`: **PASS**
- `php framework/tests/training_dataset_validator_test.php`: **PASS**
- `php framework/tests/db_health.php`: **PASS** (sin warnings)
- `php framework/tests/llm_smoke.php`: **FAIL** (keys/provider)

## P1-DB-CLEAN decision
- Enfoque aplicado: **A (aislar artefactos de prueba + teardown real)**.
- Cambio clave: cleanup de tests ahora elimina tablas fisicas namespaced (`p_<hash>__...`) y contratos legado (`status_redteam_p0`, `redteam_p0_01`) antes de la suite.
- Razon de seguridad/produccion: no se crearon indices permanentes ni migraciones productivas para tablas basura de test.
- Validacion: `db_health` limpio en entorno normal y en simulacion `APP_ENV=prod`.

## Diff + tests added (this hardening wave)

### Core runtime/security files
1. `project/public/api.php`
2. `framework/app/Core/ChatAgent.php`
3. `framework/app/Core/RouterPolicyEvaluator.php`
4. `framework/app/Core/IntentRouter.php`
5. `framework/app/Core/UnitTestRunner.php`
6. `framework/app/Core/ApiSecurityGuard.php`
7. `framework/app/Core/WebhookSecurityPolicy.php`
8. `framework/app/Core/EnforcementModePolicy.php`
9. `bin/worker.php`
10. `docs/contracts/action_catalog.json`
11. `project/.env.example`

### New tests (selected)
1. `framework/tests/chat_exec_requires_auth_test.php`
2. `framework/tests/chat_exec_no_default_admin_test.php`
3. `framework/tests/chat_exec_tenant_binding_test.php`
4. `framework/tests/chat_informational_without_auth_ok_test.php`
5. `framework/tests/chat_warn_mode_does_not_allow_exec_when_auth_fails_test.php`
6. `framework/tests/redteam_poc_chat_exec_test.php`
7. `framework/tests/records_mutation_requires_auth_test.php`
8. `framework/tests/records_mutation_rejects_payload_tenant_override_test.php`
9. `framework/tests/records_mutation_accepts_authenticated_session_test.php`
10. `framework/tests/records_mutation_cross_tenant_block_test.php`
11. `framework/tests/telegram_rejects_get_test.php`
12. `framework/tests/whatsapp_rejects_put_test.php`
13. `framework/tests/webhook_fails_closed_when_secret_empty_in_staging_test.php`
14. `framework/tests/webhook_allows_insecure_only_in_dev_when_flag_test.php`
15. `framework/tests/worker_processes_queued_whatsapp_message_test.php`
16. `framework/tests/worker_respects_idempotency_test.php`
17. `framework/tests/worker_logs_route_path_test.php`

## NO-GO causes (strict order)
1. `llm_smoke` FAIL by invalid/expired credentials.
