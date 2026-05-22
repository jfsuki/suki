# Audit Integral SUKI — Mayo 2026

**Ejecutado**: 2026-05-15
**Auditor**: suki-auditor (Claude Sonnet 4.6)
**Scope**: Calidad de testing TC01-TC23, deuda técnica, documentación, agentes de mejora, escalabilidad, deploy, LLM, Control Tower.

---

## Semáforo por módulo

| Módulo | Estado | Razón |
|--------|--------|-------|
| A — Calidad testing | AMARILLO | Tests pasan pero hay smoke real; TC20 cases=0 confirmado |
| B — Deuda técnica | AMARILLO | PUC 109 entradas (docs dicen 5000+); ReteFuente calculada pero no integrada en flujo chat |
| C — Documentación | AMARILLO | Docs de arquitectura existen; troubleshooting ausente; elapsed_ms en bootstrap sin doc |
| D — Agentes de mejora | ROJO | AcidChatRunner lee confusion_base.json que puede no existir (cases=0); sin cron de promoción |
| E — Escalabilidad | AMARILLO | Sessions en archivos PHP; sin SCALING_PLAN.md; MemoryWindow sin límite de tokens |
| F — Deploy | VERDE | .env.example completo; EntityMigrator additive; sin script de seed automático |
| G — LLM | VERDE | Cascade real con circuit breaker; Gemini existe como chat provider; 6 providers |
| H — Torre | ROJO | ControlTowerService es solo activación de usuarios; KPIs/reentrenamiento/LLM-status ausentes |

---

## Resumen ejecutivo

SUKI tiene una base sólida: tests TC01-TC23 reportan PASS, el routing determinístico funciona, multi-tenant está enforced en SQL, FiscalRulesEngine calcula ReteFuente/ICA desde JSON real. Sin embargo, tres gaps bloquean la declaración de "production-ready":

1. PUC nacional tiene 109 entradas reales en el archivo JSON (no 5000+ como dicen los docs). La contabilidad arranca con seed manual en cada test — sin seed automático en deploy fresco.
2. Control Tower es una pantalla de activación de usuarios, no un centro de mando operativo: faltan KPIs de uso, estado de LLM, reentrenamiento Qdrant, métricas de calidad en vivo.
3. El acid test (TC20) reporta `cases=0` cuando `conversation_confusion_base.json` no existe — el runner cae silenciosamente, lo que hace que TC20 sea una prueba de "no crash" más que de regresión real.

---

## Módulo A — Calidad del testing

### Estado: AMARILLO

### A1 — Smoke y hardcodes en aserciones

**Hallazgo A1.1 — Funciones `chatTurn` duplicadas en cada archivo fase**

Cada archivo fase define su propia función con nombre distinto (`chatTurn`, `chatTurn2`, `chatTurn3`, `chatTurn4`, `chatTurn57`). No hay helper compartido. Si cambia la API de respuesta (p.ej. el campo `data.reply` se renombra), hay que editar 5 archivos por separado.

- `framework/tests/fase1_tc01_tc04.php:23` — `chatTurn()`
- `framework/tests/fase2_tc05_tc08.php:21` — `chatTurn2()`
- `framework/tests/fase3_tc09_tc11.php:40` — `chatTurn3()`
- `framework/tests/fase4_tc12_tc15.php:30` — `chatTurn4()`
- `framework/tests/fase5_7_tc16_tc23.php:31` — `chatTurn57()`

**Hallazgo A1.2 — TC12/TC13/TC14 aceptan respuesta sin recall como PASS**

En `fase4_tc12_tc15.php:98-99`, TC12 (persistencia entre mensajes) hace PASS con solo que la respuesta no esté vacía y no tenga stack trace — el recall del nombre "Clínica del Norte" es informativo (`echo "Recall detected: YES/partial"`) pero no es aserciones de fallo. Mismo patrón en TC13 (línea 136) y TC14 (líneas 178-179): un chat que no recuerda nada igual pasa.

```
checkResult4('TC12', 'M2 — recuerda nombre o pide aclaración', $hasReply12b, ...)
// $hasReply12b = solo que la respuesta tenga > 5 caracteres
```

Esto convierte las pruebas de memoria en tests de "no crash", no de funcionalidad real.

**Hallazgo A1.3 — TC15 multi-agente no verifica routing_hint=specialist**

`fase4_tc12_tc15.php:208-213`: Solo chequea "respuesta presente y sin crash". No verifica que el routing_hint incluya "specialist" ni que el supervisor haya sido invocado. La condición del prompt original de que TC15 verifique `specialist` en routing_hint no está implementada.

**Hallazgo A1.4 — TC02 embeddings optativos**

`fase1_tc01_tc04.php:118-121`: Si embeddings no están activos (Qdrant/Gemini deshabilitados), el check de score se saltea completamente con un mensaje informativo. El test pasa igualmente. En ambiente sin LLM credentials, TC02 siempre pasa sin probar nada de Qdrant.

**Hallazgo A1.5 — TC11 requiere INSERT manual de cuentas contables**

`fase3_tc09_tc11.php:222-225`: El test inserta directamente en `cuentas_contables` y `parametros_contables_tenant` antes de llamar a `recordNonElectronicSale`. Esto significa que la prueba no verifica que el sistema arranque limpio — simula condiciones que no existen en un tenant real nuevo.

### A2 — Mantenibilidad

- Los helpers `chatTurn*` están duplicados (5 versiones). Un cambio en el protocolo de respuesta requiere editar todos los archivos.
- Los `checkResult` numerados (`checkResult`, `checkResult2`, etc.) son globals con estado global (`$pass`, `$fail`) — si se ejecutan en el mismo proceso habría conflicto, pero al usar subprocesos por fase esto es aceptable.
- No existe un archivo de helpers compartido (`framework/tests/helpers.php` o similar).

### A3 — Gaps del plan vs ejecutado

| TC | ¿Ejecutado? | Profundidad real |
|----|-------------|-----------------|
| TC07 URLs /r/{token} | Ejecutado condicionalmente | Solo si el reply LIST contiene un token. Si el LLM no genera URL con token, TC07 cae al path alternativo (direct service test). No prueba el endpoint HTTP GET /r/{token}. |
| TC08 FormulaEngine | Ejecutado con autoloader | Prueba 3 fórmulas y validación. Correcto. |
| TC20 acid test | FALLO SILENCIOSO | El endpoint `/chat/acid-test` llama a `AcidChatRunner::run()` que a su vez llama `runConfusionScenarios()`. Si `conversation_confusion_base.json` no existe (`framework/app/Core/Agents/AcidChatRunner.php:207-210`), devuelve `['total' => 0, 'passed' => 0, ...]`. El TC20 en `fase5_7_tc16_tc23.php:251` acepta esto como PASS porque `is_array($acidData)` es true. |
| TC15 multi-agente | Ejecutado superficialmente | Solo "sin crash". No verifica specialist routing. |
| TC19 quality | Ejecutado | La calidad lee archivos de telemetría en `project/storage/tenants/default/telemetry/`. Si no hay archivos del período actual, `total_messages=0`. |

**Verificación de TC20:** `framework/app/Core/Agents/AcidChatRunner.php:207-223` — si `conversation_confusion_base.json` no existe, `$cases = []`, `runConfusionScenarios` retorna `['total' => 0, 'passed' => 0, 'failed' => 0, 'error' => 'no_cases', 'cases' => []]`. El TC20 acepta `is_array($acidData)` como PASS. El acid test tiene 0 casos de confusión probados.

---

## Módulo B — Deuda técnica

### Estado: AMARILLO

### B1 — Verificación en código real

**PUC Nacional:**

Archivo: `framework/data/puc_colombia_base.json` — 111 líneas totales, 109 entradas de cuenta (grep de `"codigo"` devuelve 109). El PUC colombiano real tiene 5,000+ subcuentas. El archivo actual cubre solo clases, grupos y algunas cuentas principales sin subcuentas de dígito 4 y 6.

El código en `AccountingRepository.php:583-612` carga este archivo en `puc_nacional` si la tabla está vacía. La función `activateAccount()` (línea 108) solo puede activar cuentas que existen en `puc_nacional`. Con 109 entradas, el 95% de las cuentas colombianas reales no pueden ser activadas por el tenant.

**ReteFuente:**

Estado actual: IMPLEMENTADO pero no integrado en flujo de chat por defecto.

- `FiscalRulesEngine.php` calcula ReteFuente, ICA y ReteIVA desde `fiscal_rules_co.json` (verificado líneas 58-143). El cálculo es real y configurable por JSON.
- `FiscalEngineService.php:524-579` llama a `FiscalRulesEngine::calculate()` dentro de `calculateFiscalTotalsSummary()`.
- Sin embargo, esto solo se invoca cuando hay un documento fiscal activo en el flujo de `FiscalEngineService`. La contabilidad de ventas POS no-electrónicas (`AccountingService::recordNonElectronicSale()`) no calcula retenciones — no llama a `FiscalRulesEngine`.
- El chat no puede calcular ReteFuente de forma conversacional; depende de que el tenant tenga configurados roles contables y docuemtos fiscales.

**ALTER MODIFY/DROP:**

`EntityMigrator.php` tiene `dropColumn()` (línea 242) y renameColumn() (línea 201). El CLAUDE.md dice que falta MODIFY/DROP, pero en código ambos existen. Sin embargo, `migrateEntity()` (líneas 56-80) solo hace ADD COLUMN automáticamente. Para MODIFY (cambiar tipo) o DROP, hay que llamar a los métodos explícitamente — no es parte del flujo automático de migración. Esto es deuda de integración, no de implementación.

**ChatAgent:**

`wc -l framework/app/Core/ChatAgent.php` = **2958 líneas**. El CLAUDE.md decía 4652 — la reducción es real, pero 2958 sigue siendo un monolito. El objetivo era -15% (3954) — se superó eso — pero la arquitectura Strangler sigue incompleta.

**ConversationMemory:**

`ConversationMemory.php:44-65`: La query de `load()` usa `WHERE thread_id = :thread_id AND (tenant_id = :tenant_id OR tenant_id = '')`. Para tenant_id='default', cae al branch sin filtro de tenant (línea 57-65), retornando todo el historial solo filtrado por thread_id. Si dos tenants distintos tuvieran el mismo session_id, habría cruce de datos. En la práctica el thread_id incluye el tenant_id (formato `tenantId:sessionId`), lo que mitiga el riesgo, pero la lógica del branch `default` es un punto de atención.

### B2 — Deuda nueva encontrada en sesión de testing

**AccountingService requiere seed manual:**

TC11 (`fase3_tc09_tc11.php:222-225`) hace INSERT directo en `cuentas_contables` y `parametros_contables_tenant` antes de cada test. Esto documenta que un tenant nuevo sin seed no puede ejecutar `recordNonElectronicSale()`. No hay seed automático en `AppInstallService` ni en la instalación de tenant nuevo.

- Archivo donde debería estar: `framework/app/Core/AppInstallService.php` — NO VERIFICADO si llama a `seedDefaultRolesForTenant()`.

**PurchasesService requiere flags de entorno:**

TC10 (`fase3_tc09_tc11.php:154-155`) hace `putenv('ALLOW_RUNTIME_SCHEMA=1')` y `putenv('APP_ENV=local')` antes de crear la instancia. Esto confirma que PurchasesService en staging/producción fallará si estas variables no están configuradas. El `.env.example` tiene `ALLOW_RUNTIME_SCHEMA=0` por defecto.

### B3 — Tabla de deuda

| Item | Archivo:línea | Prioridad | Días estimados |
|------|--------------|-----------|----------------|
| PUC 109 entradas (necesita 5000+) | `framework/data/puc_colombia_base.json:1-111` | P1 | 5-8 |
| Seed automático de roles contables en nuevo tenant | `framework/app/Core/AppInstallService.php` (verificar llamada a seedDefaultRolesForTenant) | P1 | 2-3 |
| ALTER MODIFY no en flujo automático | `framework/app/Core/EntityMigrator.php:59-80` | P1 | 3-5 |
| ALLOW_RUNTIME_SCHEMA=0 bloquea PurchasesService en prod | `framework/app/Core/PurchasesService.php` + `.env.example:27` | P1 | 1 |
| ConversationMemory branch default sin tenant filter | `framework/app/Core/ConversationMemory.php:56-65` | P1 | 1 |
| ChatAgent 2958 líneas (Strangler incompleto) | `framework/app/Core/ChatAgent.php:1-2958` | P2 | 15-20 |
| ReteFuente no calculada en flujo POS no-electrónico | `framework/app/Core/AccountingService.php` (sin llamada a FiscalRulesEngine) | P2 | 3-5 |
| FORM_STORE solo localStorage | Confirmado en FEATURE_MATRIX.md | P2 | 3-5 |

---

## Módulo C — Documentación

### Estado: AMARILLO

### C1 — Mapa de fallos

`docs/troubleshooting/` no existe. Los 5 fallos más comunes y su localización:

1. **"No hay proveedores LLM disponibles"** — `framework/app/Core/LLM/LLMRouter.php:157`. Causa: API keys en blanco o todos los providers con circuit abierto. Fix: verificar .env y hacer reset de circuits.
2. **AccountingService falla con "cuenta no encontrada"** — `framework/app/Core/AccountingRepository.php:163-177` en `findAccountByRole()`. Causa: tenant sin seed de roles. Fix: llamar `seedDefaultRolesForTenant($tenantId)`.
3. **PurchasesService tabla no existe** — `framework/app/Core/RuntimeSchemaPolicy.php`. Causa: `ALLOW_RUNTIME_SCHEMA=0`. Fix: setear `ALLOW_RUNTIME_SCHEMA=1` en `.env`.
4. **Qdrant RuntimeException "QDRANT_URL requerido"** — `framework/app/Core/QdrantVectorStore.php:51`. Causa: `QDRANT_URL` vacío con `SEMANTIC_MEMORY_ENABLED=1`. Fix: deshabilitar semántica o proveer URL.
5. **TC20 acid test cases=0** — `framework/app/Core/Agents/AcidChatRunner.php:207-210`. Causa: `conversation_confusion_base.json` ausente. Fix: crear el archivo en `framework/contracts/agents/`.

### C2 — Documentos existentes y actualización

| Documento | Existe | Actualizado (cambios sesión) |
|-----------|--------|------------------------------|
| `CLAUDE.md` | SI | PARCIAL — dice ChatAgent 4652 líneas, real es 2958 |
| `docs/INDEX.md` | SI | NO VERIFICADO si incluye cambios de sesión |
| `docs/canon/SUKI_ARCHITECTURE_CANON.md` | SI | NO VERIFICADO |
| `AGENTS.md` | SI | NO VERIFICADO |
| `docs/troubleshooting/FAILURE_MAP.md` | NO | Ausente — debe crearse |
| Docs sobre seed manual de AccountingService | NO | No documentado en ningún archivo |
| Docs sobre ALLOW_RUNTIME_SCHEMA requerido en Purchases | NO | Solo en .env.example como `0` (valor que bloquea) |

### C3 — Logs accionables

El log `project/storage/tenants/default/telemetry/2026-04-08.log.jsonl` tiene campos:
- `skill_failure_detail`: string con error específico (ej: `"ContractRegistry: catalog[131].channel_capabilities must be array"`)
- `retrieval.*`: estado del RAG, tenant_id, app_id, motivo de skip
- `success`, `data.action`, `data.intent`, `data.confidence`

Los logs son razonablemente accionables. Sin embargo:
- No tienen `elapsed_ms` como campo de primer nivel (está en `_elapsed_ms` agregado por el helper de test, no por el sistema en producción).
- No hay alerta automática cuando `skill_failure_detail` no está vacío.

---

## Módulo D — Agentes que estudian el comportamiento

### Estado: ROJO

### D1 — Pipeline de aprendizaje

`framework/app/Core/TelemetryService.php`: Es un thin wrapper sobre `SqlMetricsRepository`. Guarda métricas en DB (intent, command, guardrail, token, support ticket). No promueve aprendizaje automático.

`framework/app/Core/AgentJournalService.php`: Guarda notas de planificación del agente en archivos JSON por tenant/proyecto/rol. No es un pipeline de aprendizaje — es un cuaderno de notas del agente.

`framework/app/Core/LearningPromotionService.php:7,19`: La clase existe y tiene `promoteApprovedCandidates()`. Sin embargo:
- No hay cron job ni scheduler que la invoque automáticamente.
- Los candidatos deben ser aprobados manualmente (status=approved) antes de ser promovidos.
- No hay evidencia de que el sistema detecte automáticamente candidatos de score < 0.65.

**Conclusión D1**: El pipeline de aprendizaje existe como infraestructura pero no es autónomo. Requiere aprobación manual y no tiene scheduler. Es "semi-automático" en el mejor caso.

### D2 — Acid test y cases=0

**Causa confirmada en código:**

`framework/app/Core/Agents/AcidChatRunner.php:206-223`:

```php
private function loadConfusionBase(): array
{
    if (!is_file($this->confusionPath)) {
        return [];  // retorna array vacío silenciosamente
    }
    ...
}

private function runConfusionScenarios(..., array $confusion): array
{
    $cases = is_array($confusion['acid_conversation_cases'] ?? null) ? ... : [];
    if (empty($cases)) {
        return ['total' => 0, 'passed' => 0, 'failed' => 0, 'error' => 'no_cases', 'cases' => []];
    }
    ...
}
```

El path esperado es `framework/contracts/agents/conversation_confusion_base.json`. Si el archivo no existe, `runConfusionScenarios` retorna `total=0` sin error. El `defaultTests()` (línea 157) tiene 39 casos hardcoded que SÍ se ejecutan. Los `confusion_cases` son adicionales y su ausencia pasa desapercibida.

**El TC20 en la suite de tests acepta `cases=0` como PASS** (`fase5_7_tc16_tc23.php:251`): `checkResult57('TC20', 'acid test retorna JSON válido', $acidHasData || is_array($acidData))` — es true aunque cases sea 0.

### D3 — Quality agent

El endpoint `/chat/quality` (`project/public/api.php:2318-2343`) llama a `ConversationQualityDashboard::build()`. Este lee archivos JSONL de `project/storage/tenants/{tenant}/telemetry/`. Los datos son reales (existen logs desde 2026-03-29). Las métricas calculadas son: total mensajes, no resueltos, reprompts, handoffs LLM, ejecutados, y breakdown por clasificación. Son datos reales de DB/archivos, no hardcoded.

Sin embargo, si se llama con un tenant nuevo o un período sin tráfico, `total_messages=0` y todas las métricas son 0. No hay aviso explícito de "sin datos suficientes".

---

## Módulo E — Escalabilidad

### Estado: AMARILLO

### E1 — Verificaciones en código

**BaseRepository tenant_id:** `framework/app/Core/BaseRepository.php:56,158-159` — tenant_id se añade en INSERT y en WHERE de GET. Los índices están declarados en el schema de cada tabla (`idx_accounts_tenant`, `idx_journal_tenant`, etc.). CORRECTO.

**ConversationMemory race conditions:** `framework/app/Core/ConversationMemory.php:96-112` — `append()` no tiene lock de archivo ni mutex. En concurrencia alta, dos procesos podrían escribir simultáneamente al SQLite de `project_registry.sqlite`. SQLite maneja locks a nivel de archivo pero puede haber timeouts en escrituras concurrentes. No hay `PRAGMA journal_mode=WAL` visible en el código. RIESGO MEDIO para concurrencia > 10 usuarios simultáneos.

**Qdrant colección:** La arquitectura usa una colección por `memory_type` (`agent_training`, `sector_knowledge`, `user_memory`) con filtro por `tenant_id` dentro de la colección. `QdrantVectorStore.php:338-359` — `queryForTenant()` agrega `must[]` con tenant_id. Esto escala bien para pocos tenants pero puede ser ineficiente con miles de tenants en la misma colección.

**Sessions:** PHP sessions por defecto en archivos del sistema (`session_save_path`). No hay configuración de session driver en DB en el código inspeccionado. Con múltiples workers PHP-FPM en servidores distintos, las sesiones no se comparten — esto bloquea horizontal scaling.

**LLM rate limiting:** `LLMRouter.php:93-99` tiene `consumeProviderRateLimit()` con configuración por proveedor (ej: `LLM_MAX_REQUESTS_PER_MINUTE_OPENROUTER=90`). El circuit breaker en `$circuit` es una variable estática de clase — se reinicia entre procesos PHP. No es persistente ni distribuido.

### E2 — Documentación de escala

`docs/ESCALABILIDAD_COSTOS.md` existe (`docs/ESTRATEGIA_ESCALABILIDAD_COSTOS.md`) — NO VERIFICADO su contenido.

No existe `docs/technical/SCALING_PLAN.md`.

Los índices MySQL están declarados en los schemas (`CREATE TABLE` statements en `AccountingRepository.php:493-573`) pero no hay un script centralizado de índices ni un plan de EXPLAIN ANALYZE documentado.

### E3 — Límites conocidos

- **ConversationMemory**: `limit = 20` mensajes por thread (constructor, línea 25 de `ConversationMemory.php`). No hay límite de tokens — un mensaje largo de 10K tokens vale igual que uno de 10 tokens.
- **MemoryWindow**: `maxTurns = 10` (línea 22). Sin límite de tokens. No hay compresión de contexto.
- **Qdrant**: NO VERIFICADO si es instancia gratuita o cloud. La URL en `.env.example` es `http://localhost:6333` — instancia local.

---

## Módulo F — Deploy y operaciones

### Estado: VERDE

### F1 — Instalación

`project/.env.example` existe y está completo (verificado — cubre DB, LLM, Qdrant, channels, security, caching, backups, QA toggles). Es el documento más completo para deploy.

No existe un `README.md` en la raíz del repo con pasos de instalación paso a paso. `AGENTS.md` existe pero es protocolo de desarrollo, no instalación para ops.

No hay script de seed automático. Los seeders existen (evidenciado por `AccountingRepository::seedDefaultRolesForTenant()` y `loadPucNacionalFromJson()`) pero no se invocan automáticamente en deploy fresco — requieren llamada manual o trigger desde el test.

### F2 — Zero-downtime

Las migraciones son additive por defecto en `EntityMigrator.migrateEntity()` — solo ADD COLUMN automáticamente. MODIFY y DROP son explícitos y destructivos. Correcto para zero-downtime.

No hay un script de deploy documentado (`deploy.sh`, `Makefile`, etc.).

### F3 — Hosting

Extensiones PHP requeridas detectadas en código:
- `pdo` y `pdo_mysql` (Database.php, BaseRepository.php)
- `pdo_sqlite` (ProjectRegistry, ConversationMemory)
- `curl` (AlanubeClient, GeminiClient, LLM providers)
- `mbstring` (ChatAgent, múltiples archivos)
- `json` (omnipresente)
- `openssl` (AuthService, hashes HMAC)
- `fileinfo` (MediaService)

Nginx/Apache config: no existe en el repo.

Qdrant es local por defecto. Puede reemplazarse por Qdrant Cloud cambiando `QDRANT_URL` y `QDRANT_API_KEY` en `.env`.

---

## Módulo G — Selección y gestión de LLM

### Estado: VERDE

### G1 — Configuración

`framework/config/llm.php` lee `framework/data/llm_providers.json`. El cascade es `["openrouter", "gemini", "deepseek", "mistral", "groq"]`. Primary = openrouter, secondary = gemini. Toda la configuración está en JSON, no hardcoded en PHP.

### G2 — Failover real

`LLMRouter.php:87-154` — el loop `foreach ($providers as $providerName)` implementa failover real con:
- Circuit breaker por provider (`isCircuitOpen()` / `tripCircuit()`)
- Rate limit guard por provider
- Try/catch con continue al siguiente provider

El failover es real, no simulado. Si OpenRouter falla, se intenta Gemini, luego DeepSeek, etc.

**Gemini como chat provider:** CONFIRMADO. `framework/data/llm_providers.json:13-18` incluye Gemini con `class: App\Core\LLM\Providers\GeminiProvider`. La nota de CLAUDE.md que dice "Gemini ausente en chat failover" es INCORRECTA — fue corregida en una sesión anterior. Gemini es el provider secundario en cascade.

**Protección contra infinite loop:** Si todos los providers fallan, `LLMRouter.php:157-165` lanza `RuntimeException` con mensaje agregado de errores — no hay infinite loop.

**Tool use:**
- ClaudeProvider: soporta `tools` y `tool_use` (líneas 56, 76)
- OpenRouterProvider: soporta `tools` (línea 31, 45) en formato OpenAI
- GeminiProvider: NO soporta tools (grep sin resultados)

### G3 — Documentación de proveedores

No existe un documento sobre cuándo usar cada proveedor ni criterios para agregar uno nuevo. El JSON `llm_providers.json` tiene comentarios pero no criterios de selección. NO VERIFICADO si existe en docs/technical/.

---

## Módulo H — Control Tower (Torre)

### Estado: ROJO

### H1 — Estado real

`framework/app/Core/ControlTowerService.php` (todo el archivo):

Lo que hace la Torre actualmente:
1. Verificar SUKI_MASTER_KEY
2. Listar registraciones pendientes (usuarios sin activar)
3. Activar/desactivar empresas
4. Crear usuarios "Creator"

Lo que NO hace: métricas de uso, estado de LLM, reentrenamiento Qdrant, quality metrics, logs de errores, KPIs de tenant.

El endpoint `/dashboard/` en `api.php:1382-1393` llama a `DashboardService::getMetrics()` por tenant — esto sí es real (NO VERIFICADO el contenido de DashboardService). El endpoint `/torre` en index.php usa autenticación con `suki_tower_auth` en sesión pero no hay un controlador dedicado de Torre con las funcionalidades prometidas.

### H2 — Capacidades reales vs prometidas

| Capacidad | Estado | Evidencia |
|-----------|--------|-----------|
| Ver todos los tenants activos | PARCIAL | `ControlTowerService::getPendingRegistrations()` solo muestra pendientes, no activos |
| Ver métricas de uso por tenant | NO | `ControlTowerService.php` no tiene método de métricas |
| Revisar y aprobar mejoras de intents | NO | `LearningPromotionService` existe pero no hay UI en Torre |
| Ver estado de LLM providers | NO | No hay endpoint que exponga circuit breaker status |
| Forzar reentrenamiento Qdrant | NO | No hay endpoint en api.php para reentrenamiento |
| Ver logs de errores recientes | NO | No hay agregación de errores en Torre |
| Crear usuarios Creator | SI | `ControlTowerService::createCreator()` implementado |
| Activar/desactivar empresa | SI | `activateCompany()` / `deactivateCompany()` implementados |

### H3 — Brecha y esfuerzo

Faltan para hacer Torre funcional como centro de mando:

1. Endpoint de metrics por tenant (leer de `SqlMetricsRepository`) — 3-5 días
2. Endpoint de estado de LLM providers (exponer circuit breaker status) — 1-2 días
3. UI de aprobación de intents (wiring a LearningPromotionService) — 3-5 días
4. Endpoint de reentrenamiento Qdrant (wiring a ErpTrainingDatasetVectorizer) — 2-3 días
5. Agregación de errores recientes (leer telemetry logs) — 2-3 días

**Esfuerzo total estimado Torre funcional: 11-18 días**

---

## Plan de acción post-audit

### Tareas P0 — Bloquean confiabilidad inmediata

| Tarea | Archivo:método | Esfuerzo |
|-------|---------------|---------|
| Crear `conversation_confusion_base.json` con al menos 5 casos | `framework/contracts/agents/conversation_confusion_base.json` (crear) | 1 día |
| Verificar y documentar llamada a `seedDefaultRolesForTenant()` en AppInstallService | `framework/app/Core/AppInstallService.php` | 1 día |
| Documentar `ALLOW_RUNTIME_SCHEMA=1` como requerido para Purchases en staging/prod | `project/.env.example:27` + docs | 0.5 días |
| Agregar docs/troubleshooting/FAILURE_MAP.md con 5 fallos más comunes | (crear) | 1 día |

### Tareas P1 — Degradan calidad de producto

| Tarea | Archivo:método | Esfuerzo |
|-------|---------------|---------|
| Completar PUC con 5000+ subcuentas colombianas reales | `framework/data/puc_colombia_base.json` | 5-8 días |
| TC12/TC13 — convertir check de recall en aserción de fallo real | `framework/tests/fase4_tc12_tc15.php:98-99,136` | 1 día |
| TC15 — verificar routing_hint contiene specialist | `framework/tests/fase4_tc12_tc15.php:208-213` | 0.5 días |
| Refactorizar chatTurn en helper compartido | `framework/tests/` (crear helpers.php) | 1 día |
| Torre: endpoint de métricas por tenant | `project/public/api.php` + `ControlTowerService.php` | 3-5 días |
| Sessions en DB para horizontal scaling | `project/public/index.php` + config | 3-5 días |

### Tareas P2 — Deuda técnica

| Tarea | Archivo:método | Esfuerzo |
|-------|---------------|---------|
| FORM_STORE persistencia en DB | `framework/app/Core/FormBuilder.php` (o equivalente) | 3-5 días |
| Continuar Strangler ChatAgent (2958 a < 2000 líneas) | `framework/app/Core/ChatAgent.php` | 10-15 días |
| Agregar LLM circuit breaker persistente (Redis/DB) | `framework/app/Core/LLM/LLMRouter.php:14` ($circuit array estático) | 3-5 días |
| SCALING_PLAN.md con índices, límites, arquitectura de escala | `docs/technical/SCALING_PLAN.md` (crear) | 2 días |
| GeminiProvider: agregar soporte tool_use | `framework/app/Core/LLM/Providers/GeminiProvider.php` | 2-3 días |

---

## Veredicto final

**Listo para cliente real**: NO

**Bloqueantes para ir a producción:**
1. PUC con 109 entradas — no puede atender contabilidad colombiana real
2. Seed manual de AccountingService — nuevo tenant arranca sin cuentas configuradas
3. Torre sin métricas de uso — operación ciega en producción
4. TC20 acid test con cases=0 — regresión de calidad sin cobertura real de conversaciones confusas
5. PHP sessions en archivos — impide scaling horizontal

**Lo que sí está listo para uso interno o piloto con 1-2 empresas controladas:**
- Routing determinístico (Cache → Rules → RAG → LLM) funcionando
- Multi-tenant SQL enforced en BaseRepository
- FiscalRulesEngine calcula retenciones reales desde JSON
- LLM cascade con 6 providers y circuit breaker real
- AlanubePayloadBuilderCO con mapa real de documentos DIAN
- POS cycle y Purchases cycle operativos (con flags de entorno correctos)
- 39 casos de acid test ejecutados en defaultTests()
