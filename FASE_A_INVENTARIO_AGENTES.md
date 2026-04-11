# FASE A — INVENTARIO DE AGENTES SUKI

**Fecha de Auditoría**: 2026-04-09  
**Alcance**: Todos los agentes definidos y en ejecución en el proyecto SUKI  
**Metodología**: Búsqueda de clases con herencia Agent/Specialist/Persona + registros en catálogos + evidencia en tests

---

## 1. AGENTES CORE (Orquestación Central)

| Nombre Agente | Archivo PHP | Clase | Tipo | Prompt Externo | Registro | Estado |
|---|---|---|---|---|---|---|
| **ChatAgent** | `framework/app/Core/ChatAgent.php` | `ChatAgent` (final) | Orquestador Principal | No (inline) | Instancia global | ✅ ACTIVO |
| **ConversationGateway** | `framework/app/Core/Agents/ConversationGateway.php` | `ConversationGateway` | Puerta de Entrada | No (traits) | Delegado de ChatAgent | ✅ ACTIVO |
| **MultiAgentSupervisor** | `framework/app/Core/Agents/Orchestrator/MultiAgentSupervisor.php` | `MultiAgentSupervisor` | Validador Determinista | No (inline) | Instanciado en Orchestrator | ✅ ACTIVO |
| **ChatOrchestrator** | `framework/app/Core/Agents/Orchestrator/ChatOrchestrator.php` | `ChatOrchestrator` | Coordinador de Workflows | No (inline) | Usado en Gateway | ✅ ACTIVO |
| **IntentClassifier** | `framework/app/Core/Agents/IntentClassifier.php` | `IntentClassifier` | Clasificador de Intenciones | Sí (DSL + RAG) | Router Policy | ✅ ACTIVO |
| **IntentRouter** | `framework/app/Core/IntentRouter.php` | `IntentRouter` | Router Determinista | Sí (router_policy.json) | docs/contracts | ✅ ACTIVO |

**Evidencia**: 
- ChatAgent líneas 19-60: Constructor e inicialización
- ConversationGateway línea 27: usa traits de orquestación
- MultiAgentSupervisor líneas 14-56: validateAction() método core
- ChatOrchestrator línea 33: MultiAgentSupervisor como propiedad

---

## 2. AGENTES ESPECIALIZADOS (Domain Specialists)

Definidos en: `framework/app/Core/Agents/Registry/SpecialistPersonas.php` (clase estática)

| Nombre Especialista | Área (enum) | Rol | Prompt Base | Capacidades | Línea | Estado |
|---|---|---|---|---|---|---|
| **Certified Accounting Agent** | ACCOUNTING | ERP Accountant & Auditor | Líneas 31-34 | ledger_management, financial_reporting, audit_logs | 25-36 | ✅ DEFINIDO |
| **Fiscal Strategy Specialist** | FINANCES | Expert Accountant & Tax Advisor | Líneas 73-77 | profit_analysis, tax_validation, fiscal_rounding | 67-80 | ✅ DEFINIDO |
| **Commerce Hub Agent** | SALES | Sales & Inventory Manager | Líneas 88-92 | catalog_sync, stock_reservation, price_optimization | 82-95 | ✅ DEFINIDO |
| **Stock & Supply Maestro** | INVENTORY | Warehouse & Inventory Optimizer | Líneas 45-48 | sku_management, stock_alerts, warehouse_logistics | 39-50 | ✅ DEFINIDO |
| **Strategic Procurement Agent** | PURCHASES | Purchasing & Supplier Manager | Líneas 59-62 | supplier_management, purchase_orders, cost_analysis | 53-65 | ✅ DEFINIDO |
| **Lead Neural Architect** | ARCHITECT | System Designer & Schema Expert | Líneas 103-104 | schema_design, workflow_automation | 97-106 | ✅ DEFINIDO |
| **Default Specialist** | (dynamic) | General Assistant | Líneas 114 | general_support | 108-117 | ✅ FALLBACK |

**Evidencia de Instanciación Runtime**:
- Script: `framework/scripts/initialize_specialists.php` líneas 1-28
  - Usa `SpecialistPersonas::getPersona($area)` para crear instancias
  - `$reg->createAgent($tenant, $persona['name'], $area, [...])` persiste en DB
  - Test evidencia: Inicialización en líneas 15-20

**Method Signature**:
```php
public static function getPersona(string $area): array
// Retorna: [name, role, description, prompt_base, capabilities]
```

---

## 3. AGENTES DE SOPORTE (Orquestación & Coordinación)

| Agente Soporte | Archivo | Clase | Propósito | Integración | Estado |
|---|---|---|---|---|---|
| **InternalEventBus** | `Orchestrator/InternalEventBus.php` | `InternalEventBus` | Enrutamiento de eventos inter-agentes | MultiAgentSupervisor | ✅ ACTIVO |
| **ResponseSynthesizer** | `Orchestrator/ResponseSynthesizer.php` | `ResponseSynthesizer` | Síntesis de respuestas multiagente | ChatOrchestrator | ✅ ACTIVO |
| **ToolExecutionLoop** | `Orchestrator/ToolExecutionLoop.php` | `ToolExecutionLoop` | Ejecución determinista de herramientas | Orchestrator | ✅ ACTIVO |
| **DialogStateEngine** | `Agents/DialogStateEngine.php` | `DialogStateEngine` | Máquina de estados de diálogo | ConversationGateway | ✅ ACTIVO |
| **KnowledgeProvider** | `Agents/KnowledgeProvider.php` | `KnowledgeProvider` | Proveedor de conocimiento de dominio | Gateway (línea 62) | ✅ ACTIVO |

**Evidencia de Instanciación**:
- ConversationGateway línea 78-102: Constructor inicializa todos los servicios auxiliares
- KnowledgeProvider línea 62: `$this->knowledge` en Gateway
- DialogStateEngine: Usado para mantener contexto conversacional

---

## 4. AGENTES DE PROCESOS (Ejecución de Workflows)

| Proceso/Agente | Archivo | Clase | Activación | Responsabilidad | Estado |
|---|---|---|---|---|---|
| **BuilderOnboardingProcess** | `Processes/BuilderOnboardingProcess.php` | `BuilderOnboardingProcess` | modo='builder' | Guiar creación de aplicaciones | ✅ ACTIVO |
| **AppExecutionProcess** | `Processes/AppExecutionProcess.php` | `AppExecutionProcess` | modo='app' | Ejecutar lógica de aplicación | ✅ ACTIVO |
| **AutonomousExecutionProcess** | `Processes/AutonomousExecutionProcess.php` | `AutonomousExecutionProcess` | trigger automatizado | Ejecutar workflows programados | ✅ IMPLEMENTADO |

**Uso**: ConversationGateway selecciona proceso según contexto (modo, intent, triggers)

---

## 5. MEMORIA Y CONOCIMIENTO DE AGENTES

| Componente | Archivo | Clase | Scope | Persistencia | Estado |
|---|---|---|---|---|---|
| **SemanticCache** | `Memory/SemanticCache.php` | `SemanticCache` | Session (20 turnos) | In-memory | ✅ ACTIVO |
| **TokenBudgeter** | `Memory/TokenBudgeter.php` | `TokenBudgeter` | Request | Cálculo en-vivo | ✅ ACTIVO |
| **MemoryWindow** | `Memory/MemoryWindow.php` | `MemoryWindow` | Conversación | Ventana deslizante | ✅ ACTIVO |
| **PersistentMemoryLoader** | `Memory/PersistentMemoryLoader.php` | `PersistentMemoryLoader` | User/Tenant | SQLite | ✅ ACTIVO |
| **SemanticMemoryService** | `Core/SemanticMemoryService.php` | `SemanticMemoryService` | Tenant/Sector | Qdrant (0.65 threshold) | ✅ ACTIVO |
| **ImprovementMemoryService** | `Core/ImprovementMemoryService.php` | `ImprovementMemoryService` | Tenant | SQLite + Qdrant ingest | ✅ ACTIVO |

**Ciclo Completo**:
1. SemanticCache mantiene últimos 20 turnos
2. MemoryWindow filtra para contexto actual
3. PersistentMemoryLoader carga historial usuario
4. SemanticMemoryService consulta Qdrant para similaridad
5. ImprovementMemoryService ingesta telemetría AgentOps para reentrenamiento

---

## 6. AGENTES DE TELEMETRÍA & OBSERVABILIDAD

| Agente Telemetría | Archivo | Clase | Propósito | Registra | Estado |
|---|---|---|---|---|---|
| **Telemetry** | `Agents/Telemetry.php` | `Telemetry` | Registro de eventos | Tenant-scoped JSONL | ✅ ACTIVO |
| **AgentOpsSupervisor** | `Core/AgentOpsSupervisor.php` | `AgentOpsSupervisor` | Supervisión de decisiones | AgentOps trace | ✅ ACTIVO |
| **AgentOpsObservabilityService** | `Core/AgentOpsObservabilityService.php` | `AgentOpsObservabilityService` | Runtime observability | KPIs, latencias | ✅ ACTIVO |
| **ConversationQualityDashboard** | `Agents/ConversationQualityDashboard.php` | `ConversationQualityDashboard` | Análisis de calidad | Métricas conversacionales | ✅ IMPLEMENTADO |

**Contrato**: `docs/contracts/agentops_metrics_contract.json` (v1.5.0)
- Eventos mínimos: router.decision, gate.evaluation, intent.classified, action.executed, response.emitted
- Campos requeridos: 80+ (tenant_id, project_id, session_id, supervisor_status, etc.)

---

## 7. INTEGRACIÓN CON SKILLS Y HERRAMIENTAS

| Sistema | Archivo | Clase | Conexión con Agentes | Estado |
|---|---|---|---|---|
| **SkillExecutor** | `Core/SkillExecutor.php` | `SkillExecutor` | Despacha skills a agentes | ✅ ACTIVO (línea 8-1000+) |
| **CommandBus** | `Core/CommandBus.php` | `CommandBus` | Gateway de ejecución determinista | ✅ ACTIVO |
| **AgentToolsIntegration** | `Core/AgentToolsIntegration*.php` (4 archivos) | Múltiples | Integra herramientas externas | ✅ ACTIVO |

**Flujo**: Agent → SkillExecutor → CommandBus → Handlers (determinístico)

---

## 8. REGISTRO EN CATÁLOGOS

### 8.1 Agent Definition Contract
- Archivo: `docs/contracts/agent_definition.contract.json`
- Schema obligatorio: agent_id, role, area, capabilities
- Áreas soportadas (enum): FINANCES, SALES, INVENTORY, PURCHASES, HR, LOGISTICS, SUPERVISOR, ARCHITECT
- Memory scope: isolate_by_tenant, isolate_by_project, use_shared_sector_knowledge

### 8.2 SkillsCatalog & ActionCatalog
- `docs/contracts/skills_catalog.json`: Registro de skills disponibles
- `docs/contracts/action_catalog.json`: Whitelist de acciones ejecutables
- Ambos referencian acciones que agentes pueden invocar

### 8.3 Persona Registry
- `SpecialistPersonas.php` es la única fuente de verdad para prompts de especialistas
- Sin archivo JSON externo (generado en runtime vía `getPersona()`)

---

## 9. TESTS Y EVIDENCIA DE EJECUCIÓN

Tests que validan ejecución real de agentes:

| Test | Archivo | Validaciones | Resultado |
|---|---|---|---|
| **Agent Tools Integration Foundation** | `framework/tests/agent_tools_integration_foundation_test.php` | SkillExecutor dispatch, CommandBus routing | ✅ PASS |
| **Agent Tools Integration Skills** | `framework/tests/agent_tools_integration_skills_test.php` | Skill execution + agent context | ✅ PASS |
| **AgentOps Observability Foundation** | `framework/tests/agentops_observability_foundation_test.php` | Telemetry ingestion, metrics collection | ✅ PASS |
| **AgentOps Observability Skills** | `framework/tests/agentops_observability_skills_test.php` | Agent KPI tracking | ✅ PASS |
| **AgentOps Supervisor Test** | `framework/tests/agentops_supervisor_test.php` | MultiAgentSupervisor validation logic | ✅ PASS |
| **AgentOps Runtime Observability** | `framework/tests/agentops_runtime_observability_test.php` | Runtime telemetry + latency tracking | ✅ PASS |
| **ERP Coordination Test** | `framework/tests/test_erp_coordination.php` | Multi-agent workflow coordination | ✅ PASS |

**Resultado Final**: ✅ 71/71 unit tests PASS (incluyendo todos los tests de agentes)

---

## 10. ESTADO DE ACTIVACIÓN

**Agentes Completamente Activos en Producción**:
- ✅ ChatAgent (orquestador principal)
- ✅ ConversationGateway (puerta de entrada)
- ✅ IntentRouter + IntentClassifier (determinismo)
- ✅ MultiAgentSupervisor (validación)
- ✅ Specialized Personas (ACCOUNTING, FINANCES, SALES, INVENTORY, PURCHASES, ARCHITECT)
- ✅ Memory System (4 capas: session, user, business, semantic)
- ✅ AgentOps Telemetry (supervisión completa)
- ✅ SkillExecutor + CommandBus (determinístico)

**Agentes Parcialmente Implementados**:
- ⚠️ ConversationQualityDashboard (definido, métricas limitadas)
- ⚠️ DialogStateEngine (básico, sin persistencia de estado compleja)

**Agentes No Implementados/Documentados Pero No Reales**:
- ❌ HR Specialist (definido en contract pero no en SpecialistPersonas.php)
- ❌ LOGISTICS Specialist (definido en contract pero no en SpecialistPersonas.php)

---

## 11. RESUMEN EJECUTIVO

### Total de Agentes Inventariados
- **Core Agents**: 6 (ChatAgent, ConversationGateway, MultiAgentSupervisor, ChatOrchestrator, IntentClassifier, IntentRouter)
- **Specialized Agents**: 6 (Accounting, Finance, Sales, Inventory, Purchases, Architect) + 1 fallback
- **Support Agents**: 5 (EventBus, ResponseSynthesizer, ToolLoop, DialogState, KnowledgeProvider)
- **Process Agents**: 3 (BuilderOnboarding, AppExecution, AutonomousExecution)
- **Memory/Knowledge Agents**: 6 (SemanticCache, TokenBudgeter, MemoryWindow, PersistentMemoryLoader, SemanticMemoryService, ImprovementMemoryService)
- **Telemetry Agents**: 4 (Telemetry, AgentOpsSupervisor, AgentOpsObservabilityService, QualityDashboard)

**Total Funcional**: ~30 agentes y servicios distribuidos

### Matriz de Especialización

```
           ACCOUNTING  FINANCE  SALES  INVENTORY  PURCHASES  ARCHITECT  SUPPORT
Prompt       ✅         ✅       ✅       ✅         ✅         ✅         -
Real Data    ✅         ✅       ✅       ✅         ✅         ⚠️        N/A
Calculation  ⚠️         ✅       ⚠️       ⚠️         ⚠️         -         -
Tools        ✅         ✅       ✅       ✅         ✅         ✅         ✅
Memory       ✅         ✅       ✅       ✅         ✅         ✅         ✅
Tests        ✅         ✅       ✅       ✅         ✅         ✅         ✅
```

### Gaps Identificados (Fuera de FASE A)
- Agentes LOANS, HR, LOGISTICS documentados pero no implementados
- Architect agent con capacidades limitadas a schema_design (sin real ejecución)
- DialogStateEngine muy básico (sin persistencia de estado multi-turno compleja)
- QualityDashboard sin integración visual (solo logs)

---

**FASE A Completada**: Inventario de todos los agentes activos, especializados y de soporte.  
**Listo para FASE B**: Análisis profundo de especialización real vs documentada.
