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
  - ayuda y ejemplos desde registry real
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

