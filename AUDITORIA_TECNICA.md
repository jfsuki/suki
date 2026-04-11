# AUDITORÍA TÉCNICA SUKI — 10 FASES
**Fecha**: 2026-04-09  
**Metodología**: Solo cuenta como REAL si hay código funcional verificado (archivo:línea).  
**Auditor**: Claude Sonnet 4.6 (revisión directa de código PHP, sin asumir por documentación)

---

## FASE 1 — TOOLS (Sprint Items)

| Componente | Estado | Evidencia |
|-----------|--------|-----------|
| `inventory_query` / InventorySkill | ✅ REAL | `Skills/InventorySkill.php:25-55` — 6 acciones reales: check_stock, adjust_stock, register_sale, list_products, add_product, low_stock_alerts |
| `formula_calculator` / CalculatorSkill | ✅ REAL | `Skills/CalculatorSkill.php:14-25` — margin_price, round_multiple, tax_projection con lógica matemática real |
| `import_csv_excel` | ✅ REAL | `CsvImportService.php` 116L + `ExcelImportService.php` 272L — PhpSpreadsheet integrado |
| `product_search` | 🟡 PARCIAL | Resuelto por `EntitySearchService` + `InventoryRepository::findProduct` — **no registrada como herramienta independiente** en `action_catalog.json` |
| `record_create` | 🟡 PARCIAL | Manejado por `CrudCommandHandler` — nombre exacto **no aparece en `action_catalog.json`** |
| `report_summary` | ❌ HUMO | `ReportEngine.php:386L` existe pero es para formularios/documentos. **No hay Balance, P&G ni Flujo de efectivo** |

---

## FASE 2 — CONTABILIDAD

| Componente | Estado | Evidencia |
|-----------|--------|-----------|
| Partida doble (debe/haber) | 🟡 PARCIAL | `AccountingService.php:70-79` — estructura debe/haber OK pero cuentas sintéticas (id=1, id=2, id=99) |
| PUC colombiano real | ❌ HUMO | `AccountingRepository.php:13` — tabla `cuentas_contables` existe pero **sin catálogo PUC real** (miles de cuentas vs 3 sintéticas) |
| IVA 19% | ✅ REAL | `CalculatorSkill.php:50-53`, `QuotationService.php:459` — cálculo real con `iva_rate=0.19` |
| ReteFuente | ❌ HUMO | Solo en `SpecialistPersonas.php:76` y prompts — **cero cálculo implementado** |
| ICA | ❌ HUMO | Solo referencia textual en `ConversationGatewayBuilderOnboardingTrait.php:1108` — **no hay cálculo** |
| Balance General | ❌ HUMO | `AccountingService::getBalanceSheet()` existe pero retorna cuentas sintéticas, no estructura NIIF/PUC |
| Estado de Resultados (P&G) | ❌ HUMO | No existe como módulo independiente |
| Flujo de Efectivo | ❌ HUMO | No implementado |
| Alanube HTTP Client | ✅ REAL | `AlanubeClient.php:51-74` — curl real, Bearer token, timeout 20s |
| Alanube XML/UBL/CUFE/Firma DIAN | ❌ HUMO | `AlanubeIntegrationAdapter.php:8` — adapter existe, `emitDocument()` pasa payload sin construir XML UBL 2.1, sin CUFE, sin firma |

---

## FASE 3 — BUGS CONOCIDOS

| Bug | Estado | Evidencia |
|-----|--------|-----------|
| Qdrant tenant_id mismatch (system vs demo) | ✅ CORREGIDO | `QdrantVectorStore.php:62-69` — `resolveCollectionOrFail()` + payload index por `tenant_id` implementado |
| ConversationGateway vs ChatAgent — ¿cuál activo? | ✅ RESUELTO | `api.php:1667,1963` — **ChatAgent es el activo**. ConversationGateway Strangler-extracted a 245L (traits orchestrator) |
| Memoria persistente entre requests | ✅ REAL | `SqlMemoryRepository.php:30-127` — SELECT/INSERT con `tenant_id`, `user_id`, `session_id`. Funciona |
| Circuit breaker + timeout LLM | ✅ REAL | `LLMRouter.php:330,344` — `isCircuitOpen()` + `tripCircuit()`. Curl timeout 20s en todos los clientes |
| P95 latencia — gate automático | 🟡 PARCIAL | `AgentOpsObservabilityService.php:173` — p95 **calculado** desde SQL pero **sin gate automático** que corte si supera umbral |
| Tests con HTTP real | ❌ HUMO | `run.php:7` usa `UnitTestRunner` PHP interno. **Sin pruebas HTTP reales contra endpoints** |

---

## FASE 4 — VEREDICTO GLOBAL (Fases 1-3)

| Componente | Estado | Evidencia |
|-----------|--------|-----------|
| Router determinista | ✅ REAL | `IntentRouter.php`, `RouterPolicyEvaluator.php` — operativo |
| Seguridad chat/message | ✅ REAL | `ChatAgent.php` + `RouterPolicyEvaluator.php` — hard gates auth/tenant/schema |
| Memoria SQL persistente | ✅ REAL | `SqlMemoryRepository.php` — funcional |
| Contabilidad fiscal CO completa | ❌ HUMO | PUC + ReteFuente + ICA no implementados |
| Facturación electrónica DIAN | ❌ HUMO | XML UBL + CUFE + firma ausentes |
| Tests E2E HTTP | ❌ HUMO | Solo PHP interno, sin HTTP real |

---

## FASE 5 (implícita) — ARQUITECTURA REAL

| Componente | Estado | Evidencia |
|-----------|--------|-----------|
| Kernel Database + QueryBuilder | ✅ REAL | `Database.php`, `QueryBuilder.php` — multi-tenant, parameterized, tenant scoping auto |
| EntityMigrator (CREATE IF NOT EXISTS) | ✅ REAL | `EntityMigrator.php:230` — genera DDL desde contratos JSON |
| EntityMigrator ADD COLUMN | ✅ REAL | `EntityMigrator.php:101` — `ensureField()` con ALTER TABLE ADD COLUMN |
| EntityMigrator MODIFY / DROP COLUMN | ❌ HUMO | No existe. Schema diff = solo additive. **Renombrar un campo destruye datos** |
| AgentOps Telemetry | ✅ REAL | `Agents/Telemetry.php` + `TelemetryService.php` — JSONL + SQL metrics |
| Multi-provider LLM | ✅ REAL | `LLMRouter.php` — Mistral (primario), OpenRouter, DeepSeek con failover real |

---

## FASE 6 — AGENTE BUILDER

| Componente | Estado | Evidencia |
|-----------|--------|-----------|
| Crear tablas/entidades por chat | ✅ REAL | `BuilderFastPathParser.php:69-118` — parse LLM → validación → fallback determinista, "never null, never exception up" |
| ConversationGateway refactored | ✅ REAL | `ConversationGateway.php:245L` — solo Traits orchestrator. `ChatAgent.php:4652L` es el engine activo (`api.php:1667,1963`) |
| WorkflowCompiler (NL → DAG) | ✅ REAL | `WorkflowCompiler.php:11-95` — compila texto a contrato de nodos (input/generate/tool/output), infiere prompt templates, linealiza edges |
| WorkflowExecutor (DAG runtime) | ✅ REAL | `WorkflowExecutor.php:21-97` — topological sort, traces por nodo, latency ms, error handling |
| WorkflowValidator | ✅ REAL | `WorkflowValidator.php:150L` — valida contrato antes de ejecutar |
| Contratos forms/grids conectados | 🟡 PARCIAL | JSON forms/grids existen y se parsean. Pero `FORM_STORE/GRID_STORE` persiste solo en **localStorage**, no en DB (confirmado en `FEATURE_MATRIX.md:11`) |
| ALTER diff completo (MODIFY/DROP) | ❌ HUMO | `EntityMigrator.php` solo tiene ADD COLUMN. Sin MODIFY, sin DROP. **No puede renombrar campos sin destruir datos** |
| Memoria/historial por sesión | ✅ REAL | `SqlMemoryRepository.php:60-127` — `getUserMemory` + `appendShortTermMemory` con `tenant_id + user_id + session_id` en SQL |
| Estado builder por tenant+user | ✅ REAL | `ConversationGatewayBuilderOnboardingTrait.php` — state key = `tenant+project+mode+user` persistido en SQL |

---

## FASE 7 — AGENTE DE APPS

| Componente | Estado | Evidencia |
|-----------|--------|-----------|
| Cómo llega el intent del usuario | ✅ REAL | `IntentClassifier.php:70-99` — **3 capas**: Qdrant cosine → Mistral JSON → PHP keyword fallback (en ese orden) |
| Score threshold Qdrant | ⚠️ RIESGO | `IntentClassifier.php:24` — threshold = **0.65** (docs mencionaban 0.72 — valor real nunca actualizado en docs) |
| Skills catálogo vs /Skills/ PHP | 🟡 PARCIAL | PHP Skills reales: 9 clases. `skills_catalog.json` tiene nombres como `create_invoice`, `inventory_check` — **no coinciden 1:1** con clases PHP (`InventorySkill`, `FiscalTaxSkill`, etc.) |
| Dispatcher real intent → tool | ✅ REAL | `SkillExecutor.php:287-1031` — 700+ líneas de dispatch por módulo: POS:545, Purchases:611, Fiscal:667, Ecommerce:719, AccessControl:789, SaaS:836, Usage:883, AgentOps:977 |
| Fallback humano (never null) | ✅ REAL | `BuilderFastPathParser.php:116` — comentario explícito + catch en `ChatAgent.php:198,338` con mensaje friendly |
| Historial de sesión persistido | ✅ REAL | `SqlMemoryRepository.php:106-140` — `getShortTermMemory(tenant, session, limit=20)` SQL real. `appendShortTermMemory` persiste cada turno |

---

## FASE 8 — LLM KEYS Y ROUTING

| Componente | Estado | Evidencia |
|-----------|--------|-----------|
| Multi-provider real | ✅ REAL | `LLMRouter.php:169-177` — Mistral, OpenRouter, DeepSeek. Gemini **ausente como chat provider** (solo embeddings) |
| Provider primario configurado | ✅ REAL | `LLMRouter.php:183` — **Mistral es primario** (hardcoded default). Configurable por `.env` |
| Detección 401/403 + failover | ✅ REAL | `LLMRouter.php:418-419` — strings `'401'`, `'403'` en `isInvalidConfigError()`. `LLMRouter.php:130,136` — `classifyProviderFailureReason()` → `tripCircuit(provider)` → siguiente provider automático |
| Circuit breaker implementado | ✅ REAL | `LLMRouter.php:330-364` — `isCircuitOpen()` + `tripCircuit()` con TTL en memoria de sesión |
| Errores LLM visibles al usuario | ✅ RESUELTO | No se propagan como error técnico. `ChatAgent.php` captura y devuelve mensaje friendly |
| Gemini como chat provider | ❌ AUSENTE | `LLMRouter.php:169-177` — Gemini **no está en la lista de chat providers**. Solo en `GeminiEmbeddingService.php` (vectores Qdrant). Si falla Gemini embedding → Qdrant falla silenciosamente |

---

## FASE 9 — TORRE DE CONTROL

| Componente | Estado | Evidencia |
|-----------|--------|-----------|
| Frontend Tower existe | ✅ REAL | `tower/public/index.php:1-65` — auth con `SUKI_MASTER_KEY`, rutas a dashboard/editor/builder |
| Dashboard (tower_x92.php) | 🟡 PARCIAL | `tower_x92.php:25,62-72` — `SqlMetricsRepository` real, `getHealthByWorld()` + `getApiDetailedMetrics()` + `getAppCatalogStats()`. 937L con UI mezclada con lógica |
| Métricas desde SQL real | ✅ REAL | `tower_x92.php:62-72` — datos reales desde `SqlMetricsRepository`, no mock |
| Vista tokens por tenant | 🟡 PARCIAL | Tabla `ops_token_usage` existe. `AgentOpsObservabilityService.php:78-115` entrega summary. **Sin dashboard visual** (S6.D1-D6 = 100% pendiente) |
| Inbox soporte / tickets | ❌ HUMO | `SPRINT_TRACKER.md:126-132` — S6.C1 a S6.C5 todos `⬜ Sin iniciar` |
| Panel KPIs ejecutivos | ❌ HUMO | S6.A1-A4 = `⬜ Sin iniciar` |
| Chat builder/app views | ✅ REAL | `project/views/chat/builder.php:27909L`, `app.php:29167L` — UIs completas |
| Entrenamiento en tiempo real | ❌ HUMO | `IntentClassifier.php:81,93` — `logTraining()` graba en SQLite. **Sin pipeline automático Qdrant en producción**. S9.4 = pendiente |
| Gestión de tenants en Tower | ❌ HUMO | S6.A3 `⬜ Sin iniciar` — no hay CRUD de tenants en la interfaz |
| AgentOps conectado a frontend | 🟡 PARCIAL | `AgentOpsObservabilityService.php` produce datos. `tower_x92.php:550` tiene UI básica. Sin panel estructurado de traces |

---

## FASE 10 — GAPS CRÍTICOS PARA GO-TO-MARKET (PYME COLOMBIANA)

### Los 5 gaps que bloquean una venta real

| # | Gap | Estado | Esfuerzo | Código base disponible |
|---|-----|--------|----------|------------------------|
| **1** | **Login individual por tenant (OTP/WhatsApp)** | ❌ Solo `SUKI_MASTER_KEY` global. Sin login individual | **5-8 días** | `framework/views/auth/register.php` captura phone. Falta verificación OTP SMS/WA |
| **2** | **Facturación electrónica DIAN completa** | ❌ AlanubeClient HTTP existe; XML UBL 2.1 + CUFE + firma = vacío | **15-20 días** | `AlanubeClient.php`, `AlanubeIntegrationAdapter.php` — base HTTP usable |
| **3** | **PUC + ReteFuente + ICA reales** | ❌ Cuentas 1/2/99 sintéticas, retención solo en prompts | **8-12 días** | `AccountingRepository.php`, `AccountingService.php` — estructura existe, falta lógica |
| **4** | **Control Tower operativo** | ❌ 6 módulos S6 sin iniciar: KPIs, token dashboard, inbox soporte, CI, métricas | **10-15 días** | `tower_x92.php:937L`, `AgentOpsObservabilityService.php` — backend data ready |
| **5** | **Tests E2E HTTP + CI remoto** | ❌ Tests PHP internos, sin CI, sin gate en PR | **5-8 días** | `AcidChatRunner.php` como base. Sin infraestructura CI |

### Esfuerzo total estimado: **43-63 días persona** para salir a producción real

---

## RESUMEN EJECUTIVO — QUÉ ES REAL vs QUÉ ES HUMO

### ✅ SÓLIDO Y FUNCIONAL (el motor técnico)
- Router determinista 3 capas (Qdrant 0.65 → Mistral → keyword fallback)
- WorkflowCompiler + WorkflowExecutor DAG con topological sort real
- SkillExecutor masivo: 11 módulos, ~700L de dispatch, never null
- LLMRouter multi-provider (Mistral primario) con circuit breaker y failover 401/403
- Memoria SQL real (tenant/session/user/global) persistida entre requests
- ChatAgent 4652L — orquestador principal con fallback seguro
- 9 Skills PHP reales: Inventory, Calculator, CRM, FiscalTax, SalesBot, etc.
- Tower frontend con auth y datos SQL reales
- Seguridad: auth chat, tenant isolation, webhook anti-replay
- Multi-tenant DB kernel con QueryBuilder paramétrico

### ❌ HUMO (bloquea operación comercial)
- OTP / login individual → **vender a PYME sin login por usuario = inviable**
- XML UBL 2.1 + CUFE + firma DIAN → **FE electrónica CO no funciona**
- PUC colombiano + ReteFuente + ICA → **contabilidad fiscal incompleta**
- Control Tower dashboards (KPIs, tokens, inbox) → **admin no puede operar ni medir**
- CI remoto + E2E HTTP → **regresiones pueden llegar a producción sin detectarse**
- ALTER diff completo (MODIFY/DROP) → **cambiar schema de entidad existente = peligroso**
- FORM_STORE en DB → **formularios se pierden si el browser se cierra**

---

## ARCHIVO DE REFERENCIA

Este documento se generó revisando código real. Para cada hallazgo existe un `archivo:línea` verificado.  
Documentos fuente leídos: `SPRINT_TRACKER.md`, `FEATURE_MATRIX.md`, `AUDIT_REPORT.md`, `STATUS_FINAL.md`, `STATUS.md`, `ROOT_CAUSE.md`, `AGENTS.md`, `PROJECT_MEMORY.md`, `ARCHITECTURE_INDEX.md`, `MODULE_REGISTRY.md`, `CHANGE_MAP.md`, y todos los cánones en `docs/canon/`.
