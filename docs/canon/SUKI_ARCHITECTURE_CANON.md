# SUKI_ARCHITECTURE_CANON
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-15  
Scope: Master architecture canon for SUKI AI-AOS layers, invariants, and execution boundaries.

## 1) Purpose
Provide the master architecture map for SUKI without replacing the existing detailed canons.

This document formalizes:
- the system layers and their relationships,
- the execution boundary between LLM and PHP,
- multitenant and schema-governance laws,
- router and agent laws,
- the rules for additive evolution.

This canon complements:
- `docs/canon/TEXT_OS_ARCHITECTURE.md`
- `docs/canon/ROUTER_CANON.md`
- `docs/canon/ACTION_CONTRACTS.md`
- `docs/canon/AGENTOPS_GOVERNANCE.md`

## 2) System Definition
SUKI is a multi-tenant AI Application Operating System where chat is the main interface and the PHP execution kernel is the only authority for business execution.

The system SHALL interpret business requests through governed router stages, validate them against contracts and policies, and execute only through skills, CommandBus, adapters, and the PHP kernel.

## 3) Central Laws and Invariants
### 3.1 Execution Boundary Law
- LLM interprets and guides.
- PHP kernel calculates, validates, persists, and executes.
- LLM SHALL NOT perform business math, direct persistence, or side effects.
- Any executable outcome SHALL be represented as a governed action or command before execution.
- **Conversational Layering**: Intent classification MUST stream through Semantic Classifier (Qdrant) -> LLM JSON Fast Parser -> PHP Kernel. Rigid regex for business intents is explicitly forbidden.

### 3.2 Multitenant Law
- Multitenant isolation is mandatory.
- `tenant_id` is mandatory in operational scope, logs, metrics, memory writes, and execution envelopes.
- Tenant overlays for configuration, memory, permissions, and credentials SHALL remain isolated.
- Reusable sector apps and sector packs SHALL contain zero tenant operational data.

### 3.3 Anti-Schema-Explosion Law
Schema evolution SHALL follow this order:
1. shared multitenant core tables
2. `custom_fields`
3. JSON `custom_data`
4. new table only as last resort with explicit justification and governance

Uncontrolled table proliferation is forbidden.

### 3.4 Router Law
- Deterministic routing is mandatory.
- LLM is last resort only.
- The router SHALL produce a machine-governed result before any side effect is allowed.

### 3.5 Agent Law
- Agents are controlled copilots, not autonomous executors.
- Agents interpret, normalize, classify, delegate, and explain.
- Real execution SHALL go through skills, CommandBus, adapters, and the PHP kernel.

### 3.6 Simulation Law
- Business simulation is a mandatory safety gate before deployment.
- Simulation SHALL use the real PHP execution kernel in isolated mode.
- Failed simulation SHALL block deployment.

### 3.7 Learning Law
- Production learning MAY promote abstract patterns only.
- Raw tenant operational data SHALL NOT be promoted to shared knowledge.
- Publication of improvements SHALL be supervised, versioned, and reversible.

## 4) Canonical Architecture Layers
### 4.1 Business Discovery
Purpose:
- capture sector workflows, documents, vocabulary, controls, and exceptions before generation.

Outputs:
- structured discovery artifacts,
- sector requirements,
- candidate operational rules,
- dataset inputs for sector knowledge.

Relationship:
- feeds Sector Packs and App Builder Engine.

### 4.2 Sector Packs
Purpose:
- provide reusable business blueprints, playbooks, datasets, and templates by domain.

Outputs:
- reusable contracts,
- business playbooks,
- guidance for agents and builder,
- sector knowledge packages.

Invariants:
- zero tenant operational data,
- reusable across tenants,
- versioned and auditable.

Relationship:
- feeds App Builder Engine, Specialized Agents, Business Simulation Engine, and Production Learning Pipeline.

### 4.3 App Builder Engine
Purpose:
- convert governed natural-language intent and reusable business assets into app structure.

Outputs:
- entity contracts,
- forms, grids, workflows, reports, integrations,
- draft revisions for validation.

Invariants:
- BUILD mode only,
- additive contract evolution,
- no direct runtime CRUD of tenant business data.

Relationship:
- consumes Business Discovery and Sector Packs,
- produces candidate app revisions for simulation.

### 4.4 Business Simulation Engine
Purpose:
- validate candidate business behavior before deployment using the same PHP kernel used in production execution.

Outputs:
- simulation report,
- blocking defects,
- approved or blocked deployment decision,
- abstract feedback for builder and Control Tower.

Relationship:
- gates deployment from the App Builder Engine,
- feeds Control Tower and Production Learning Pipeline.

### 4.5 Specialized Agents
Purpose:
- interpret and guide operation inside a tenant application through role-aware and domain-aware copilots.

Outputs:
- findings,
- normalized parameters,
- guidance,
- governed action proposals.

Invariants:
- no direct business execution,
- no raw SQL,
- no cross-tenant access,
- no free-form claims outside registry/contracts.

Relationship:
- operate through router, skills, and the PHP kernel.

### 4.6 Agent Collaboration Engine
Purpose:
- coordinate multiple specialized agents safely during a request.

Outputs:
- collaboration envelope,
- delegation chain,
- merged findings,
- one final command proposal under App Agent authority.

Invariants:
- request-scoped only,
- App Agent as sole coordinator,
- specialists return findings only,
- no circular delegation,
- no independent execution authority.

Relationship:
- sits inside the Specialized Agents layer under router and Control Tower governance.

### 4.7 SUKI Control Tower
Purpose:
- supervise routing quality, agent health, safety signals, execution quality, and operational drift.
- detailed canon reference: `docs/canon/SUKI_CONTROL_TOWER.md`

Outputs:
- metrics,
- anomaly flags,
- rollback decisions,
- release gates,
- operational reports.

Relationship:
- consumes signals from router, agents, simulation, skills, and production learning.

### 4.8 Production Learning Pipeline
Purpose:
- convert safe operational signals into reusable system improvements.

Outputs:
- reviewed improvement candidates,
- sector pack updates,
- policy addenda,
- regression cases,
- builder or agent guidance improvements.

Invariants:
- no direct self-modifying production behavior,
- no raw tenant data promotion,
- publication only after hygiene and review.

## 5) Layer Relationship Model
Canonical flow:
1. Business Discovery captures domain structure.
2. Sector Packs store reusable business knowledge and templates.
3. App Builder Engine compiles governed app structure.
4. Business Simulation Engine validates the candidate app.
5. Specialized Agents operate the approved app.
6. Agent Collaboration Engine coordinates multi-agent interpretation when needed.
7. SUKI Control Tower supervises runtime and release quality.
8. Production Learning Pipeline turns safe evidence into additive improvements.

No layer in this sequence creates a second execution authority outside the PHP kernel.

## 6) Multitenant Model
### 6.1 Shared Layers
Shared, reusable, and tenant-free:
- framework runtime,
- canonical docs and governance contracts,
- sector packs,
- shared policies and schemas.

### 6.2 Tenant Overlay Layers
Tenant-scoped and isolated:
- configuration,
- credentials,
- memory,
- permissions,
- operational data,
- project selection and app scope.

### 6.3 Operational Scope Rule
Every operational envelope SHALL carry, at minimum:
- `tenant_id`
- `project_id`
- `mode`
- `user_id`

When canonical storage is active, `app_id` or project scope SHALL also be preserved.

## 7) Execution Authority and Runtime Boundary
Canonical execution path:
1. user request
2. router classification and policy checks
3. skill selection or governed fallback
4. command composition
5. CommandBus dispatch
6. PHP kernel validation, persistence, and adapter execution
7. audit and observability emission

Neither agents nor LLM responses may bypass this path.

## 8) Governance and Evolution Principles
- Additive evolution only.
- Backward compatibility is required.
- Existing contracts SHALL NOT be silently broken or renamed.
- Governance documents SHALL be extended through addenda, not destructive rewrites.
- Any architectural conflict SHALL be documented explicitly under `Detected Canon Drift`.

## 9) Forbidden Architectural Outcomes
- direct LLM execution of business logic,
- direct SQL from app or agent layers,
- cross-tenant data access,
- uncontrolled schema growth,
- bypass of router, skills, CommandBus, or execution guards,
- publication of tenant data into shared knowledge,
- deployment without simulation gate.

## 10) Detected Canon Drift
### 10.1 Router Stage Drift
Reviewed sources currently differ:
- `docs/canon/ROUTER_CANON.md` states `cache -> rules -> rag -> llm`
- `docs/contracts/router_policy.json` states `cache -> rules -> skills -> rag -> llm`
- `framework/app/Core/IntentRouter.php` currently operates with `cache -> rules -> skills -> rag -> llm`

This document does not silently resolve that difference.

Canonical interpretation for safe use today:
- deterministic governed stages remain mandatory,
- LLM remains last resort,
- skills SHALL NOT bypass router or execution guards,
- any future harmonization SHALL be additive and explicit.

### 10.2 Agent Taxonomy Overlap
Existing governance sources define higher-level agent roles such as Architect, App Builder, Operator, Integration, Support, and Auditor.

Operational design work increasingly refers to App Agent plus domain specialists such as Inventory, Sales, Purchasing, Manufacturing, Service, and Accounting.

This document treats those sets as complementary:
- governance roles describe accountability and policy coverage,
- specialized agents describe request-time operational collaboration.

## 11) Non-Goal
This canon defines architecture and authority boundaries only. It does not implement runtime classes, database migrations, or behavior changes by itself.

## 12) Ley Contable Colombia — PUC y Parametrización por Tenant
Fuente normativa: Decreto 2650 de 1993 (Plan Único de Cuentas) + puc.com.co

### 12.1 Estructura PUC (inmutable — norma nacional)
El PUC tiene 4 niveles fijos definidos por el Decreto 2650/1993:
- Clase (1 dígito): 1=Activo, 2=Pasivo, 3=Patrimonio, 4=Ingresos, 5=Gastos, 6=Costos ventas, 7=Costos producción
- Grupo (2 dígitos): e.g. 11=Disponible, 13=Deudores
- Cuenta (4 dígitos): e.g. 1105=Caja, 1110=Bancos
- Subcuenta (6 dígitos): e.g. 111005=Moneda nacional, 111010=Moneda extranjera
- Auxiliar (7+ dígitos): LIBRE, definido por cada empresa (e.g. 1110050001=Bancolombia CC)

El catálogo nacional vive en `puc_nacional` (tabla read-only, compartida entre todos los tenants).
El JSON fuente es `framework/data/puc_colombia_base.json` — actualizable sin tocar PHP.

### 12.2 Catálogo Activo del Tenant (cuentas_contables)
Cada tenant activa únicamente las cuentas PUC que usa según su actividad económica.
- Una empresa de servicios NO activa cuentas 14xx (inventario).
- Una empresa con múltiples bancos crea auxiliares propios (7+ dígitos).
- La tabla `cuentas_contables` almacena el catálogo activado + auxiliares custom del tenant.
- Campos requeridos: tenant_id, codigo (variable hasta 12+ dígitos), nombre, tipo, naturaleza, parent_codigo, nivel, es_auxiliar.

### 12.3 Parametrización Contable por Tenant (parametros_contables_tenant)
Cada tenant configura qué cuenta usar para cada tipo de evento de negocio mediante roles semánticos:
- rol: 'recaudo_efectivo'     → codigo elegido por el tenant (e.g. '110501', '1105001')
- rol: 'recaudo_bancolombia'  → codigo auxiliar del tenant (e.g. '1110050001')
- rol: 'ingresos_ventas'      → '4135' (comercio) ó '4145' (servicios)
- rol: 'iva_ventas'           → '2408'

Esta configuración la hace el contador/empresario desde la UI. No la infiere el sistema.

### 12.4 Medios de Pago (formas_pago_config)
Cada tenant mapea sus medios de pago a roles contables:
- 'efectivo'           → rol: 'recaudo_efectivo'
- 'nequi'              → rol: 'recaudo_nequi'
- 'transferencia_bcol' → rol: 'recaudo_bancolombia'

### 12.5 LEY ABSOLUTA — Nunca hardcodear códigos PUC en PHP
**PROHIBIDO en cualquier archivo PHP:**
```php
// ❌ NUNCA — el código asume la cuenta del tenant
$cuentaId = 1; // hardcoded ID
$cuentaId = $repo->findByCodigo('1105'); // hardcoded código PUC
```

**OBLIGATORIO — siempre resolver por rol semántico:**
```php
// ✅ SIEMPRE — el tenant configuró qué cuenta quiere usar
$cuentaId = $repo->findAccountByRole($tenantId, 'recaudo_efectivo');
```

Si el rol no está configurado para el tenant → el agente pregunta, NO asume.
Si no hay parametros_contables_tenant para el tenant → ejecutar `seedDefaultRolesForTenant()` y notificar que debe revisar la configuración.

Esta ley aplica a: AccountingService, Skills, CommandHandlers, y cualquier código que genere asientos contables.

## 13) App Creator DB Law — Tablas Compartidas por app_type (2026-05-11)

### 13.1 Principio fundamental
Las tablas creadas por el App Creator (CreateAppSkill, EntityMigrator invocado desde flujo de creación de apps) **se crean UNA SOLA VEZ por tipo de app**, no una vez por tenant instalador.

El aislamiento de datos entre empresas es **SIEMPRE por fila** (`tenant_id`), nunca por tabla separada.

### 13.2 Naming convention canónico
```
app_{app_id}__{entity_name}
Ejemplos correctos:
  app_vet_clinic__pacientes
  app_vet_clinic__citas
  app_restaurant__mesas
  app_dental__historias_clinicas

❌ PROHIBIDO — namespace por tenant/proyecto:
  p_a1b2c3__pacientes      (hash del proyecto del tenant)
  tenant_42__pacientes     (prefijo por tenant)
```

### 13.3 Columnas de aislamiento obligatorias
Toda tabla de app del catálogo debe tener, sin excepción:
- `tenant_id VARCHAR(100) NOT NULL` — aísla datos por empresa
- `app_id VARCHAR(120) NOT NULL` — identifica el app que generó la tabla
- Índice compuesto `(tenant_id, id)` — obligatorio
- Índice compuesto `(tenant_id, created_at)` — obligatorio para queries temporales

### 13.4 Modo StorageModel requerido
- Todo código que cree tablas de apps del catálogo DEBE operar con `StorageModel::CANONICAL`
- `TableNamespace::resolve()` con `isCanonical()=true` NO aplica prefijo por proyecto
- `DB_NAMESPACE_BY_PROJECT=1` en `.env` **no aplica** a tablas de apps del catálogo

### 13.5 Idempotencia de instalación
- `CREATE TABLE IF NOT EXISTS` — si otro tenant instaló el mismo app antes, las tablas ya existen
- EntityMigrator detecta la tabla existente y aplica solo columnas nuevas (ADD COLUMN)
- Al instalar el mismo app en el tenant N, no se crean N copias de tablas

### 13.6 Anti-patrón explícito — prohibido
```
❌ empresa_1 instala vet_clinic → crea p_abc123__pacientes
❌ empresa_2 instala vet_clinic → crea p_def456__pacientes
❌ empresa_N instala vet_clinic → crea p_hash_N__pacientes
```
Para 1 millón de empresas: 1 millón × N_tablas = explosión de tablas MySQL.
Consecuencias: metadata locks, open table cache pressure, degradación irrecuperable.

### 13.7 Estrategia de escalabilidad por rango
| Rango tenants | Estrategia |
|---|---|
| 1 — 10,000 | LEGACY: tablas shared + tenant_id, una DB MySQL |
| 10,000 — 1,000,000 | CANONICAL: tablas shared + tenant_id + app_id, sharding por tenant_range |
| >1,000,000 | CANONICAL + MySQL Cluster horizontal, shard key = tenant_id hash |

### 13.8 Referencia técnica
- Implementación: `docs/technical/APP_CREATOR_DB_ARCHITECTURE.md`
- Código clave: `StorageModel.php`, `TableNamespace.php`, `EntityMigrator.php`, `BaseRepository.php`
- Skill: `framework/app/Core/Skills/CreateAppSkill.php` → `executeCreation()`
