# PROJECT_MEMORY (framework/docs)

## Objective
Build a low-code/AI-first platform that generates business apps from JSON contracts.
Core principle: chat-first usage, visual UI only when needed (tables, reports, charts).

## Canonical governance
- Canonical memory source: `framework/docs/PROJECT_MEMORY_CANONICAL.md`.
- Mandatory developer pre-check: `php framework/scripts/codex_self_check.php --strict`.
- Temporary testing artifacts policy: only under `framework/tests/tmp/`.

## Checkpoint (2026-03-03, pre-P0 secrets hardening)
- Baseline frozen for pending tracking before structural hardening.
- Open blockers at snapshot time:
  - `domain_training_sync` drift blocking full green in `run.php`.
  - `project/.env` tracked in git (security risk).
  - `ConversationGateway.php` still oversized and pending deeper split.

## Canon consolidation (2026-03-02)
This project now recognizes `docs/canon/*` and `docs/contracts/*` as official governance sources for architecture and agent policy.

### Canon docs (official)
- `docs/canon/TEXT_OS_ARCHITECTURE.md`: formal TextOS architecture, deterministic router, queue + idempotency, and tenant safety.
- `docs/canon/ACTION_CONTRACTS.md`: canonical intent/action taxonomy and required gates by action type.
- `docs/canon/AGENTOPS_GOVERNANCE.md`: observability, memory promotion flow, quality metrics, and rollback policy.
- `docs/canon/ROUTER_CANON.md`: mandatory route order and minimum evidence requirements.
- `docs/canon/VERSIONING_POLICY.md`: versioning as laws/addenda and rollback discipline.
- `docs/canon/RUNTIME_ARTIFACTS_POLICY.md`: non-code hygiene policy for runtime/cache artifacts.
- `docs/canon/TRAINING_DATASET_STANDARD.md`: canonical layered standard for sector training datasets (RAG vs structured boundaries).

### Contracts docs (official)
- `docs/contracts/router_policy.json`: machine-readable router law (cache -> rules -> rag -> llm), resolve criteria, minimum evidence, and missing-evidence actions.
- `docs/contracts/action_catalog.json`: machine-readable intent catalog (`EXECUTABLE|INFORMATIVE|FORBIDDEN`) with roles, tools, risk and gates.
- `docs/contracts/agentops_metrics_contract.json`: machine-readable AgentOps events, required fields, and mandatory KPIs.
- `docs/contracts/sector_training_dataset_standard.json`: machine-readable standard for sector dataset layers, quality and anti-noise rules.

### Canon mandates (effective)
- Queue and idempotency are co-mandatory for executable side effects.
- Deterministic router is mandatory; LLM is last resort.
- Actions are governed by intent class (`EXECUTABLE`, `INFORMATIVE`, `FORBIDDEN`).
- Quality gates are mandatory before release.
- AgentOps observability and rollback policy are mandatory.
- Versioning follows law/addenda style (extension-first, no destructive rewrites).

### CANON VERSION
- Bound canonical policy contract: `router_policy`.
- version: `1.0.0`
- effective_date: `2026-03-02`

### CONTRACTS VERSION
- `router_policy` -> version `1.0.0`, effective_date `2026-03-02`
- `action_catalog` -> version `1.0.0`, effective_date `2026-03-02`
- `agentops_metrics_contract` -> version `1.0.0`, effective_date `2026-03-02`

### NON-CODE POLICY
- Runtime artifacts policy reference: `docs/canon/RUNTIME_ARTIFACTS_POLICY.md`.
- Rule: do not commit runtime caches or sqlite runtime state (`*.cache.json`, runtime `*.sqlite` under `project/storage/*`).

## Vision (working memory, 2026-02-19)
- Meta: generar apps ERP por conversacion (chat-first) con panel visual para lo necesario.
- North star: cero tecnicismos, JSON-first, IA solo fallback, cambios incrementales.
- UX: 1 pregunta minima, chat para operar, panel visual para ver datos.
- Arquitectura: Router local-first + CRUD + report engine + agentes (lider + mini).
- Metricas: JSON hit-rate >= 85%, menos llamadas LLM con el tiempo.

## Operating commitments (non-negotiable)
- Investigar y comparar referentes antes de cambiar logica (Emergent, PowerApps, Velneo, Supabase, v0, etc.).
- Definir flujo logico y diagnostico antes de codificar.
- Cambios siempre incrementales, retro-compatibles y probados.
- Contratos JSON son fuente unica de verdad.
- No SQL en capa app; todo pasa por Kernel/ORM.
- Cada consulta debe ser index-friendly (tenant_id primero).
- Tests acidos y manuales actualizados antes de entregar.

## Core modules (actual)
- FormGenerator + FormBuilder + grid runtime (form-grid.js).
- Entity contracts + registry + migrator (DB kernel MVP).
- Editor JSON (dashboard + builder).
- Chat UI: chat_builder.html (build) + chat_app.html (use).
- ChatAgent (API) con routing local + LLM fallback.
- ConversationGateway local-first con memoria por tenant.
- LLMRouter multi-proveedor con fallback.
- LLMRouter con control de cuota por sesion y failover por error de cuota/rate-limit.
- Telemetry JSONL + AgentNurtureJob para lexicon.
- KPI gate conversacional (accuracy intents, correction success, unknown-business success, fallback rate y tokens/costo por sesion).
- CommandLayer (CRUD + validacion + auditoria).
- Report runtime (preview + PDF MVP) + Dashboard runtime.
- IntegrationContract + InvoiceContract + Alanube client + webhooks.
- ProjectRegistry (usuarios, proyectos, sesiones, deploys).

## What works today
- Forms + grids render desde JSON.
- Summary dependency ordering estable.
- Entity contracts validados (schema).
- DB Kernel MVP (QueryBuilder + Repository + tenant scope).
- CRUD API + Command endpoint.
- Report preview/PDF MVP + dashboards KPIs.
- CSV -> EntityContract + migration.
- ValidationEngine backend + AuditLogger.
- ConversationGateway local-first:
  - valida entidad antes de CRUD
  - slot-filling con requested_slot
  - build/use guard
  - guard reforzado: en modo APP bloquea solicitudes de crear app/estructura y redirige al chat creador
  - ayuda y ejemplos desde registry real
  - flujo guiado corto en builder: paso minimo + confirmacion `si/no` antes de crear tabla sugerida
  - sugerencia de campos en lenguaje no tecnico para usuarios basicos
  - ruta tecnica resumida (logica + base de datos + pasos de construccion) disponible por chat en modo builder
  - comando interno de builder para instalar playbooks sectoriales sin terminal
    (`instalar playbook FERRETERIA` / `... en simulacion`)
- Knowledge base externa reforzada (sin hardcode):
  - `framework/contracts/agents/domain_playbooks.json` con perfiles de negocio, entidades sugeridas y reportes
  - `solver_intents` sectoriales (`SOLVE_*`) + acciones `APPLY_PLAYBOOK_*` para respuesta tipo consultor
  - `project/contracts/knowledge/domain_playbooks.json` como memoria de negocio instalable por proyecto
  - `framework/contracts/agents/accounting_tax_knowledge_co.json` con base contable/tributaria operativa y checklists
  - `framework/contracts/agents/unspsc_co_common.json` con codigos UNSPSC comunes (CO), aliases comerciales y recomendaciones por tipo de negocio
  - merge de entidades contables minimas por tipo de negocio en onboarding
  - cobertura ampliada universal: ERP/CRM/contable, salud, iglesia/fundacion, restaurante, retail, manufactura, taller, ecommerce, constructora, educacion, hoteleria, agro
  - protocolo para dominios desconocidos: si no hay plantilla exacta, se registra cola de investigacion y el chat continua con preguntas minimas
  - memoria compartida de investigacion en `project/storage/chat/research/{tenant}.json` (usable por todos los mini-agentes)
  - worker `AgentNurtureJob` ahora promueve automaticamente aprendizaje compartido
    (`agent_shared_knowledge`) a `training_overrides` para reducir dependencia LLM
- Chat help external JSON: conversation_training_base.json.
- Acid test actualizado (framework/tests/chat_acid.php) y reportes.

## What is missing
- Manual testing end-to-end (pendiente).
- UI avanzada: builder visual drag/drop, inspector completo.
- Multi-modal (audio/img/PDF) + OCR + transcripcion.
- Persistencia historica FORM_STORE/GRID_STORE (snapshot/audit).
- Migraciones diff/alter (solo CREATE IF NOT EXISTS).
- Metadata de contratos en DB (versionado/patch en DB).
- Modo build/use con agentes separados y permisos estrictos (formal).
- Seguridad avanzada: CSRF, rate limiting, IDOR.

## Known risks
- Deriva entre grid engines (JS vs PHP).
- Ayuda y ejemplos pueden quedar desfasados si no se alimentan del registry.
- Estado conversacional debe resetearse cuando cambia proyecto/tenant.

## Checkpoint (2026-02-19, pre-manual-test reset)
- Proyecto limpiado para pruebas desde cero:
  - `project/contracts/entities/*` vacio
  - `project/contracts/forms/*` vacio
  - `project/views/` solo `dashboard.php` + `includes/`
  - `project/config/menu.json` minimal (Dashboard)
- Runtime state limpiado:
  - `project/storage/meta/project_registry.sqlite` reiniciado (se regenera al primer uso)
  - `project/storage/chat/*` y `project/storage/tenants/*/agent_state/*` limpiados
  - cache de contratos/esquemas limpiada
- Base MySQL de pruebas revisada:
  - queda solo `audit_log` (sin tablas de entidades activas)
- Validacion tecnica posterior a limpieza:
  - `UnitTestRunner`: 7 pass, 0 fail, 0 warn
  - `chat_acid.php`: 23 pass, 0 fail

## Checkpoint (2026-02-19, knowledge upgrade build-chat)
- ConversationGateway reforzado para modo creador:
  - deteccion de tipo de negocio con aliases desde playbook externo
  - plan de arranque con tablas, flujo, reportes y controles contables minimos
  - sugerencias de campos por negocio/entidad con explicacion simple de tipos
  - carga en cache de `domain_playbooks` y `accounting_tax_knowledge`
- Base de conocimiento ampliada:
  - nuevos perfiles: servicios y mantenimiento, restaurante/cafeteria, retail, consultoria, iglesia/fundacion, etc.
  - plantillas de entidades contables: facturas, items, pagos, cartera, gastos, impuestos
  - checklists de creador para asegurar estructura contable minima antes de operar
- Validacion posterior:
  - `php framework/tests/run.php`: 7 pass, 0 fail
  - `php framework/tests/chat_acid.php`: 23 pass, 0 fail

## Checkpoint (2026-02-19, universal business memory)
- Chat builder actualizado a flujo mas asistido para usuario no tecnico:
  - propone tabla sugerida por negocio
  - explica datos a guardar
  - confirma con `si/no` antes de crear
- Memoria de dominio expandida para mini-apps complementarias de ERP (ej. SAP-side apps):
  - mantenimiento interno
  - inventario de taller
  - control de lotes/calidad
  - solicitudes/aprobaciones internas
- Base contable ampliada:
  - plantillas de entidades por industria
  - controles operativos por vertical
  - checklists para cierre y control tributario operativo

## Checkpoint (2026-02-19, UNSPSC para facturacion electronica)
- Se agrego base de conocimiento `framework/contracts/agents/unspsc_co_common.json`:
  - codigos comunes + nombre + aliases (nombre comercial)
  - recomendaciones por tipo de negocio (ferreteria, servicios, retail, etc.)
  - fuentes oficiales Colombia Compra (clasificador + resumen UNSPSC)
- ConversationGateway ahora responde preguntas de UNSPSC sin LLM:
  - detecta consultas de `unspsc`, `clasificador`, `codigo producto/servicio`
  - sugiere codigos segun texto y/o tipo de negocio
  - siempre cierra con validacion obligatoria en clasificador oficial
- Builder fortalece diseno para facturacion electronica:
  - en tablas de productos/servicios sugiere `codigo_unspsc:texto` automaticamente.

## Checkpoint (2026-02-20, correcciones de flujo conversacional y runtime)
- Falla principal identificada: no era solo IA, era logica de flujo + runtime:
  - bloqueo en onboarding builder (repetia paso 1 sin aclaracion)
  - preguntas de capacidad (`puedo crear ...?`) se ejecutaban como CRUD
  - `tenant_id` hash podia salir del rango `INT` y romper inserts (`SQLSTATE 22003`)
- Correcciones aplicadas:
  - onboarding builder ahora acepta respuesta corta `servicios/productos/ambos` y avanza de forma deterministica
  - durante confirmacion pendiente, el usuario puede cambiar entidad objetivo (ej. de `marcas` a `clientes`) sin atascarse
  - deteccion de entidad case-insensitive y parser de tabla con stopwords para evitar entidades basura
  - guard de preguntas CRUD en modo app: si es pregunta sin datos (`?`), responde guia en vez de ejecutar
  - normalizacion de rol (`Administrador` -> `admin`, `Vendedora` -> `seller`)
  - `tenant_id` estable dentro de rango `INT` para evitar overflow
  - mensajes SQL humanizados (credenciales vs tabla faltante vs tenant invalido)
- Estado de pruebas tras correccion:
  - `framework/tests/run.php`: 7 pass, 0 fail
  - `AcidChatRunner`: 26 pass, 0 fail (tests actualizados al comportamiento actual)

## Checkpoint (2026-02-20, matriz de brechas y cierre sin vacios)
- Se consolido matriz tecnica con evidencia real en:
  - `framework/docs/IMPLEMENTATION_GAP_MATRIX_2026_02_20.md`
- Hallazgo clave:
  - El cuello de botella principal ya no es CRUD/DB, es coherencia del asistente (router + ayuda + estado por proyecto/modo).
- Regla operativa reforzada:
  - primero diagnostico y cobertura de test conversacional,
  - luego implementacion incremental,
  - despues verificacion acida con casos reales de usuario no tecnico.
- Criterio de decision obligatorio:
  - si la capacidad no existe en registry real -> no inventar,
  - si la entidad no existe -> guiar a crearla en modo builder,
  - si hay ambiguedad -> 1 pregunta minima antes de ejecutar.

## Checkpoint (2026-02-20, ejecucion bloque P0)
- P0 ejecutado en backend conversacional y API:
  - `tenant_id` estable en API (`stableTenantInt`) para evitar overflow.
  - nuevo endpoint canonico `GET /api/registry/capabilities` (entidades, formularios, acciones por modo).
  - sincronizacion `registry <-> contracts` aplicada en:
    - `chat/help`
    - `registry/status`
    - `registry/entities`
  - migracion de estado conversacional a llave fuerte:
    - `tenant + project + mode + user` en `storage/tenants/*/agent_state/*`.
    - fallback automatico a estado legacy por usuario.
  - guardas build/use reforzadas:
    - app no sugiere crear estructura.
    - builder no ejecuta CRUD de negocio.
  - pruebas "golden conversation" agregadas:
    - `framework/tests/chat_golden.php`
- Validacion post-P0:
  - `tests/run.php`: 8 pass, 0 fail
  - `tests/chat_acid.php`: 26 pass, 0 fail
  - `tests/chat_api_single_demo.php`: 7/7 OK
  - `tests/chat_golden.php`: 8/8 OK

## Checkpoint (2026-02-21, portafolio agentes + anti-colapso DB)
- Estrategia de portafolio formalizada:
  - `framework/docs/AGENTS_PORTFOLIO_STRATEGY.md`
  - objetivo: operar apps propias + sistemas de terceros via API oficial.
- Anti-colapso en DB compartida:
  - namespace fisico por proyecto activo (`DB_NAMESPACE_BY_PROJECT=1`).
  - guardrail de tablas por proyecto (`DB_MAX_TABLES_PER_PROJECT`) en `EntityMigrator`.
  - resolucion de tablas aplicada en migrador/CRUD/dashboard/query builder.
- Plan de evolucion de hosting y migracion a modelo canonico:
  - `framework/docs/HOSTING_MIGRATION_PLAN.md`.
  - enfoque: shared -> VPS -> tablas canonicas con `tenant_id + app_id`.

## Checkpoint (2026-02-21, testing post-namespace)
- Pruebas ejecutadas:
  - `php framework/tests/run.php` -> 8 pass, 0 fail.
  - `php framework/tests/chat_golden.php` -> 8 pass, 0 fail.
  - `php framework/tests/chat_api_single_demo.php` -> 7/7 OK.
  - `php framework/tests/db_health.php` -> OK, sin warnings en baseline limpio.
- Hallazgo pendiente:
  - `php framework/tests/chat_acid.php` sigue en 23 pass / 11 fail (regresion previa no resuelta en este bloque).
  - `framework/docs/smoke.ps1` requiere contratos/forms de demo (`fact.form.json`, etc.), por eso falla en baseline limpio.

## Checkpoint (2026-02-21, oportunidad competitiva Alegra vs agentes autonomos)
- Analisis consolidado:
  - Alegra chatbot actual: soporte guiado + FAQ + navegacion.
  - Alegra API: si permite crear clientes/facturas/items, pero el chatbot no orquesta de forma autonoma instrucciones complejas.
  - Oportunidad SUKI: convertir "soporte guiado" en "ejecucion real por chat" con confirmacion, auditoria y conectores.
- Definicion de nivel de agente:
  - SUKI actual: L3 (assistant con ejecucion parcial).
  - objetivo: L4 (agente autonomo multicanal con orquestacion multi-sistema).
- Contrato de referencia agregado:
  - `framework/contracts/agents/CompetitorCapabilityMatrix.contract.json`
  - `framework/contracts/agents/competitor_capability_matrix.json`
- Reglas para capacidades de agente (ya incorporadas en rumbo):
  - no prometer capacidades no activas en registry real,
  - toda accion externa via flujo canonico `Intent -> Action -> Adapter -> Resultado`,
  - sandbox/produccion por tenant y auditoria obligatoria por accion.

## Checkpoint (2026-02-23, cierre P0 tecnico)
- Runtime schema de memoria conectado en `ConversationGateway`:
  - cada guardado valida un snapshot con `WORKING_MEMORY_SCHEMA.json`,
  - snapshot persistido por `tenant + project + mode + user`.
- QA post endurecido:
  - nueva suite `chat_real_20.php` (20 conversaciones reales builder/app),
  - post gate ejecuta reset de artefactos de pruebas antes de `db_health`.
- Baseline de contratos externos restaurado:
  - `project/contracts/integrations/alanube_main.integration.json`
  - `project/contracts/invoices/facturas_co.invoice.json`

## Checkpoint (2026-02-23, memoria SQL multi-tenant)
- Refactor anti "God Class" de persistencia:
  - nuevo contrato `MemoryRepositoryInterface`.
  - implementacion `SqlMemoryRepository` (tablas `mem_global`, `mem_tenant`, `mem_user`, `chat_log`).
- `ConversationGateway` ahora usa inyeccion de repositorio SQL:
  - estado y working memory se guardan en `mem_user` por `tenant + project + mode + user`.
  - perfil/glosario/research se guardan en `mem_user` y `mem_tenant`.
  - chat logs de corto plazo se guardan en `chat_log`.
- Compatibilidad y migracion:
  - fallback de lectura legacy JSON (solo lectura) para no romper sesiones viejas.

## Checkpoint (2026-02-23, P2 kickoff observabilidad + canonical)
- Observabilidad operativa integrada al runtime:
  - `TelemetryService` + `SqlMetricsRepository` registran metricas por `tenant_id + project_id`.
  - eventos guardados:
    - latencia por intent (`ops_intent_metrics`)
    - latencia/bloqueo por comando (`ops_command_metrics`)
    - errores guardrail (`ops_guardrail_events`)
    - tokens/costo (`ops_token_usage`)
- Transicion DB canónica para proyectos nuevos:
  - `ProjectRegistry` agrega `storage_model` por proyecto (`legacy|canonical`).
  - feature flag `DB_CANONICAL_NEW_PROJECTS` define default para nuevos proyectos.
  - `TableNamespace` respeta `storage_model` y activa key canónica en migraciones.
  - runtime tenant-scoped en modo canonical agrega scope `app_id` (repositorio CRUD, migrador, grids, dashboard).
- Pruebas dedicadas agregadas:
  - `framework/tests/observability_metrics_test.php`
  - `framework/tests/canonical_storage_new_project_test.php`
- Explotacion operacional:
  - endpoint `chat/quality` incorpora `ops_summary` (intent/comando/guardrails/tokens).
  - endpoint `chat/ops-quality` expone resumen operativo por `tenant_id + project_id + days`.
  - paneles `chat_builder` y `chat_app` muestran metricas operativas (p95, bloqueos, fallback, tokens).
  - nuevo script `framework/scripts/migrate_memory_json_to_sql.php` para migrar datos historicos.

## Checkpoint (2026-02-23, inicio refactor estructural P0)
- Extraccion incremental sin ruptura:
  - nuevo `framework/app/Core/ModeGuardPolicy.php` para reglas BUILD/USE.
  - nuevo `framework/app/Core/BuilderOnboardingFlow.php` para puerta de entrada del onboarding.
  - `ConversationGateway` delega en estas capas y mantiene la logica core existente.
- QA de regresion:
  - `run`, `chat_acid`, `chat_golden`, `chat_real_20`, `db_health` y `qa_gate post` en verde.
- Pruebas dedicadas nuevas:
  - `framework/tests/mode_guard_policy_test.php`
  - `framework/tests/builder_onboarding_flow_test.php`

## Checkpoint (2026-02-23, inicio P1 router + command bus)
- `ChatAgent` desacoplado en dos costuras nuevas:
  - `IntentRouter` + `IntentRouteResult` para decidir ruta de ejecucion.
  - `CommandBus` + `MapCommandHandler` para despacho de comandos.
- Compatibilidad preservada:
  - la semantica actual de comandos se mantiene; el bus envuelve la ejecucion existente.
- Pruebas nuevas:
  - `framework/tests/intent_router_test.php`
  - `framework/tests/command_bus_test.php`
- Regla consolidada:
  - IA propone, kernel valida/decide/ejecuta; fast path sin IA como default.

## Checkpoint (2026-02-23, P1.2 handlers por comando)
- `ChatAgent` ahora registra handlers dedicados por comando para reducir switch legacy:
  - `CreateEntityCommandHandler`
  - `CreateFormCommandHandler`
  - `InstallPlaybookCommandHandler`
  - `CrudCommandHandler`
- `dispatchCommandPayload` entrega contexto tipado (builder/writer/migrator/entity checks/playbook installer) para ejecucion controlada por handler.
- `executeCommandPayload` queda solo para compatibilidad de autenticacion (`AuthLogin`, `AuthCreateUser`).
- Cobertura reforzada:
  - `UnitTestRunner::checkCommandBus` valida guards y despacho de handlers nuevos.
  - `framework/tests/command_bus_test.php` actualizado al nuevo modelo.

## Checkpoint (2026-02-23, expansion playbooks y entrenamiento)
- Playbooks sectoriales ampliados para asesoria consultiva:
  - nuevos `solver_intents`: `SOLVE_CHURCH_ADMIN`, `SOLVE_ACADEMIC_CONTROL`, `SOLVE_TIMESHEET_BILLING`.
  - nuevos `sector_playbooks`: `IGLESIA`, `EDUCACION`, `SERVICIOS_PRO`.
- Se agregaron bloques de conocimiento para guiar conversaciones:
  - `builder_guidance` (tipos de campo, relaciones, master-detalle, performance, importacion, reportes, FE CO, seguridad).
  - `guided_conversation_flows` (flujo base sectorial + flujos dedicados por vertical).
- `project/contracts/knowledge/domain_playbooks.json` se normalizo y sincronizo con la version canonica para eliminar errores de parseo y mantener consistencia de entrenamiento.
- `conversation_training_base.json` extendido a `v0.3.7` con intents/utterances y smoke tests para los tres sectores nuevos.

## Checkpoint (2026-02-23, builder guidance data-driven)
- `ConversationGateway` ahora consume `builder_guidance` desde contratos JSON (playbook + training) para respuestas guiadas en modo builder.
- Interpolacion dinamica soportada en plantillas (`{tabla_A}`, `{tabla_B}`, `{campo}`) con deteccion desde el texto del usuario.
- Se agrega sugerencia de flujo usando `guided_conversation_flows` para mantener respuestas consistentes con onboarding.
- `conversation_training_base.json` incorpora bloque `builder_guidance` sincronizado y refinado (money/phone/date/relations/performance/import/reportes/FE/seguridad).

## Checkpoint (2026-02-23, guidance transaccional relaciones/performance)
- `builder_guidance` ahora puede crear `builder_pending_command` transaccional para temas `RELATIONS`, `MASTER_DETAIL` y `PERFORMANCE`.
- Confirmar con `si` en esos flujos dispara ejecucion de comandos reales:
  - `CreateRelation` (agrega FK + `relations[]` en contrato objetivo).
  - `CreateIndex` (registra indice en `extensions.indexes` y lo materializa en DB cuando aplica).
- `ChatAgent` registra handlers dedicados:
  - `CreateRelationCommandHandler`
  - `CreateIndexCommandHandler`
- `EntityMigrator` incorpora operaciones incrementales:
  - `ensureField(...)` para `ALTER TABLE ADD COLUMN` seguro.
  - `ensureIndex(...)` para creacion idempotente de indices.

## Checkpoint (2026-02-23, flow control + retoma + telegram bootstrap)
- Control global de navegacion en `ConversationGateway`:
  - `cancelar`, `atras`, `reiniciar`, `retomar`.
  - funciona por encima de onboarding/guidance para evitar flujos atrapados.
- Persistencia de flujo con `flow_runtime` en estado:
  - `flow_key`, `current_step`, `step_history`, `paused`, `last_activity_at`.
  - permite retomar paso exacto tras pausas largas.
- Feedback loop operativo (builder):
  - tras `CreateRelation`, `CreateIndex`, `InstallPlaybook` pide confirmacion de utilidad.
  - captura frases `me sirvio` / `no me sirvio` y agrega estadistica por comando en memoria tenant.
- Conector inicial Telegram:
  - nuevo endpoint `POST /api/channels/telegram/webhook`.
  - valida secret opcional (`TELEGRAM_WEBHOOK_SECRET`), mapea `user_id/session_id` estables y responde por Bot API.

## Checkpoint (2026-02-24, workflow builder north star)
- Se define programa formal `WORKFLOW_BUILDER_PROGRAM.md` como hoja de ruta unificada:
  - benchmark funcional inspirado en Opal (NL->DAG, editor dual, debug por nodo, versionado, remix),
  - diferenciador SUKI (ERP real con entidades/CRUD/persistencia/auditoria).
- Decision arquitectonica consolidada:
  - lenguaje natural compila a contrato canonico `workflow.contract.json` (diffable, portable),
  - pipeline obligatorio `Plan -> Validate -> Execute`,
  - runtime no inventa estructura; solo ejecuta revision validada.
- Guardrails nuevos fijados en memoria:
  - validacion tipada de referencias `@`,
  - presupuesto de tokens/costo por nodo y sesion,
  - allowlist de tools/actions por rol, app y entorno,
  - trazabilidad por nodo para debug y observabilidad operativa.
- Compatibilidad protegida:
  - sin ruptura de contratos actuales (`forms/grids/entities/integrations`),
  - evolucion incremental por fases WB-0..WB-4.

## Checkpoint (2026-02-24, WB-0 tecnico en ejecucion)
- Se crea el primer contrato tecnico de workflow:
  - `framework/contracts/schemas/workflow.schema.json`.
- Se habilita validacion backend contract-first:
  - `framework/app/Core/WorkflowValidator.php`.
- Se integra cobertura automatica:
  - `framework/tests/workflow_contract_test.php`.
  - `UnitTestRunner` incluye `workflow_contract`.
- Resultado esperado de WB-0:
  - contrato base validable, sin cambios en runtime productivo aun,
  - compatibilidad actual de forms/grids sin regresion.

## Checkpoint (2026-02-24, WB-0.1 + onboarding adaptativo de negocio desconocido)
- `WorkflowValidator` agrega validacion semantica DAG:
  - ids de nodos unicos,
  - edges solo entre nodos existentes,
  - sin self-loop,
  - mapping obligatorio por edge,
  - deteccion de ciclos por recorrido topologico.
- Cobertura WB-0.1:
  - `framework/tests/workflow_contract_test.php` valida edge invalido y ciclo.
  - `UnitTestRunner::checkWorkflowContract` valida schema + semantica.
- Flujo conversacional para ideas nuevas fuera de base:
  - `ConversationGateway` activa `unknown_business_discovery` con cuestionario tecnico antes de crear.
  - genera `technical_brief` + `technical_prompt` para clasificacion externa (Gemini) o fallback local.
  - evita sesgo de perfil previo con `unknown_business_force_research` y limpia estado en correcciones.
- Contrato de dominio ampliado:
  - `unknown_business_protocol.technical_requirements_questions`
  - `unknown_business_protocol.research_prompt_template`
  - sincronizado en:
    - `framework/contracts/agents/domain_playbooks.json`
    - `project/contracts/knowledge/domain_playbooks.json`
- QA nuevo:
  - `framework/tests/unknown_business_discovery_test.php`
  - `framework/tests/chat_golden.php` agrega escenario de correccion de negocio + discovery.

## Checkpoint (2026-02-24, WB hardening + E2E canales/API)
- Cierre incremental WB-1/WB-2/WB-3/WB-4:
  - executor/compile activos por API y por comando interno.
  - repositorio workflow agrega `diff` entre revisiones para auditoria visual.
  - `workflow_builder.html` agrega comparacion de revisiones.
- Seguridad API reforzada:
  - `requestData()` ahora cachea payload; `ApiSecurityGuard` recibe payload real para validacion CSRF en body/header.
  - flujo `chat/message` mantiene bloqueo IDOR por `user_id/tenant_id/project_id` de sesion.
- OpenAPI -> contract cerrado:
  - endpoint `integrations/import_openapi` validado en E2E con bloqueo sin auth y exito autenticado (`persist=false` para dry run).
- E2E operativos agregados:
  - `framework/tests/workflow_api_e2e_test.php` (auth guard + compile/validate/execute).
  - `framework/tests/security_channels_e2e_test.php` (OpenAPI auth, IDOR chat, Telegram secret, WhatsApp verify challenge).

## Checkpoint (2026-02-24, security production hardening)
- Se cierra brecha de seguridad operativa para salida a produccion:
  - `ApiSecurityGuard` usa rate-limit central persistente via `SecurityStateRepository`.
  - `API_SECURITY_STRICT=1` activa CSRF obligatorio en mutaciones sensibles.
  - WhatsApp valida firma HMAC (`X-Hub-Signature-256`) cuando `WHATSAPP_APP_SECRET` esta activo.
  - Telegram/WhatsApp aplican anti-replay por nonce con TTL para ignorar webhooks duplicados.
- Pruebas nuevas/reforzadas:
  - `framework/tests/security_state_repository_test.php`
  - `framework/tests/api_security_guard_test.php` (strict CSRF + central rate-limit)
  - `framework/tests/security_channels_e2e_test.php` (secret/firma/replay + CSRF strict en `integrations/import_openapi`).

## Checkpoint (2026-02-24, P2.1 stress + p95/p99)
- Brecha de performance cerrada con suite dedicada:
  - `chat_stress` (carga conversacional con workers concurrentes).
  - `channels_stress` (carga webhook Telegram/WhatsApp en dry-run).
  - `perf_stress_report` (consolidado p95/p99).
- Observabilidad operativa:
  - `SqlMetricsRepository::summary` agrega `p99_latency_ms` en intent/command metrics.
- QA operativa:
  - `qa_gate post` acepta gate adicional de performance via `QA_INCLUDE_STRESS=1`.
  - resultado validado en verde con p95/p99 bajo umbrales configurados.

## Checkpoint (2026-02-24, LLM staging smoke Gemini)
- Se agrega prueba real de LLM para staging:
  - `framework/tests/llm_smoke.php`.
- Validaciones cubiertas:
  - fallback de proveedor en `LLMRouter` (auto -> Gemini),
  - salida JSON estructurada con contrato de prompt:
    - `ROLE`, `CONTEXT`, `INPUT`, `CONSTRAINTS`, `OUTPUT_FORMAT`, `FAIL_RULES`,
  - registro de tokens/costo en `ops_token_usage` y resumen de telemetria.
- Gate opcional:
  - `QA_INCLUDE_LLM_SMOKE=1 php framework/scripts/qa_gate.php post`.
- Evidencia exportada:
  - `framework/tests/tmp/llm_smoke_report.json`.

## Checkpoint (2026-02-24, escalamiento conversacional + limpieza release)
- Politica de higiene pre-release:
  - script reproducible `framework/scripts/cleanup_runtime_artifacts.php` (`--check|--apply` + opciones extendidas).
  - `.gitignore` actualizado para artefactos runtime/test frecuentes.
- Escalamiento de dominio:
  - `solver_intents`: 15 (antes 9).
  - `sector_playbooks`: 15 (antes 9).
  - cada sector con `hard_negatives` y cada solver con `utterances=45`.
  - nuevos sectores: `INMOBILIARIA`, `LOGISTICA`, `EVENTOS`, `TURISMO_HOTELERIA`, `AGRO`, `ECOMMERCE`.
- Clasificacion robusta:
  - `ConversationGateway` descuenta score por `hard_negatives` en training y playbooks.
- QA extendida:
  - nueva suite `framework/tests/chat_real_100.php`.
  - gate opcional: `QA_INCLUDE_CHAT_REAL_100=1`.

## Checkpoint (2026-02-24, LLM strict JSON + release checklist + training plan)
- Router LLM endurecido para contrato estricto:
  - `requires_strict_json=true` ahora fuerza JSON valido por proveedor; si falla, hace failover.
  - se deriva `response_schema` desde `prompt_contract.OUTPUT_FORMAT` para providers compatibles.
  - `LLMRouter` incluye diagnostico agregado de `provider_errors` al fallar todos los proveedores.
- Clientes/proveedores actualizados para salida JSON estricta:
  - Gemini usa `responseMimeType=application/json` (+ schema cuando aplica).
  - Groq/OpenRouter usan `response_format={type:json_object}` en modo estricto.
- Perfil staging recomendado en `.env`:
  - primario `openrouter` con `qwen/qwen3-coder-next`, secundario `gemini`.
  - cuotas por sesion y rate-limit por proveedor activados.
- `llm_smoke` validado en verde con salida estructurada estricta y tokens/costo reales.
- Gobierno operativo agregado:
  - `framework/docs/PREPROD_RELEASE_CHECKLIST.md` (gate y umbrales KPI definitivos).
  - `framework/docs/AGENT_TRAINING_PLAN.md` (plan builder/app agent, unknown text, correcciones, emocion y promotion loop).

## Checkpoint (2026-02-24, plantilla interna para dataset conversacional)
- Nuevo template contract-first para ingesta de entrenamiento:
  - `project/contracts/knowledge/training_dataset_template.json`
- Nuevo schema oficial de lote:
  - `framework/contracts/schemas/training_dataset_ingest.schema.json`
- Nuevo validador reutilizable:
  - `App\Core\TrainingDatasetValidator`
  - `php framework/scripts/validate_training_dataset.php`
- Cobertura automatica agregada:
  - `framework/tests/training_dataset_validator_test.php`
  - `UnitTestRunner` incluye `training_dataset_validator`.
- El template ahora viene prellenado con contexto operativo:
  - `context_pack` fiscal/contable (CO, moneda, flujo documental, cuentas base, posting rules).
  - intents seed para factura, asiento contable, pagos/recaudos, cliente, producto/servicio y creacion de app.
  - dialogos multi-turn + casos de emocion + QA de clasificacion/accion.

## Checkpoint (2026-02-24, auditoria de lote ERP 6 intents y contexto reusable)
- Se agrego libreria de contexto consolidado para evitar repeticion por intent:
  - `project/contracts/knowledge/training_context_library.json`
  - incluye action catalog, dependencia de slots, plan de cuentas minimo CO, posting rules, mapa de ambiguedad y sectores faltantes.
- Se documenta auditoria y normalizacion del lote `esco-erp6intents...` en:
  - `framework/docs/TRAINING_AUDIT_ESCO_ERP6INTENTS_V1.md`
