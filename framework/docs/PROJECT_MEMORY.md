# PROJECT_MEMORY (framework/docs)

## Objective
Build a low-code/AI-first platform that generates business apps from JSON contracts.
Core principle: chat-first usage, visual UI only when needed (tables, reports, charts).

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
- Knowledge base externa reforzada (sin hardcode):
  - `framework/contracts/agents/domain_playbooks.json` con perfiles de negocio, entidades sugeridas y reportes
  - `framework/contracts/agents/accounting_tax_knowledge_co.json` con base contable/tributaria operativa y checklists
  - merge de entidades contables minimas por tipo de negocio en onboarding
  - cobertura ampliada universal: ERP/CRM/contable, salud, iglesia/fundacion, restaurante, retail, manufactura, taller, ecommerce, constructora, educacion, hoteleria, agro
  - protocolo para dominios desconocidos: si no hay plantilla exacta, se registra cola de investigacion y el chat continua con preguntas minimas
  - memoria compartida de investigacion en `project/storage/chat/research/{tenant}.json` (usable por todos los mini-agentes)
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
