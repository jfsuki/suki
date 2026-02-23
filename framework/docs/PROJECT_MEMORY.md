# PROJECT_MEMORY (framework/docs)

## Objective
Build a low-code/AI-first platform that generates business apps from JSON contracts.
Core principle: chat-first usage, visual UI only when needed (tables, reports, charts).

## Canonical governance
- Canonical memory source: `framework/docs/PROJECT_MEMORY_CANONICAL.md`.
- Mandatory developer pre-check: `php framework/scripts/codex_self_check.php --strict`.
- Temporary testing artifacts policy: only under `framework/tests/tmp/`.

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
- Telemetry JSONL + AgentNurtureJob para lexicon.
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
