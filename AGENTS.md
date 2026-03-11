# AGENTS.md (SUKI / AI-AOS)

Este archivo define como deben trabajar los agentes de desarrollo en este repo.
No reemplaza reglas del negocio: las refuerza con retrieval-led + QA obligatorio.

## SYSTEM IDENTITY (NON-NEGOTIABLE)
- Este proyecto no es un chatbot ni una demo no-code.
- Es un **AI Application Operating System (AI-AOS)**.
- Chat = interfaz principal.
- Execution Engine = autoridad unica de ejecucion.
- La IA decide; nunca ejecuta directo fuera del motor y contratos.

## MARKET OBJECTIVES (PRIORIDAD 1)
1) Permitir a usuarios no tecnicos crear y operar apps reales por chat.
2) Operar apps internas con seguridad en produccion.
3) Integrar y operar software externo via APIs oficiales.
4) Actuar como operador digital confiable (guiar + ejecutar + soportar).
5) Reducir llamadas LLM con contratos, registry y memoria persistente.

## DECISION HIERARCHY (MANDATORY)
1) System identity y filosofia.
2) Market objectives.
3) Contract definitions.
4) Execution engine constraints.
5) Conversational UX.
6) Implementation details.

Si una decision viola una capa superior, es invalida.

## 1) Objetivo operativo
- Entregar un generador de apps chat-first para usuarios no tecnicos.
- Mantener compatibilidad: cambios incrementales, sin reescrituras.
- Priorizar estabilidad en hosting compartido (cPanel) y costo bajo.
- Mantener enfoque enterprise: auditable, seguro, escalable.

## 2) Flujo obligatorio (siempre)
1) Analizar logica, riesgos y restricciones antes de codificar.
2) Disenar cambio minimo, retro-compatible y trazable.
3) Implementar por capas (sin romper contratos JSON).
4) Validar con pruebas automaticas + smoke.
5) Reportar resultados reales (pass/fail + pendientes).

No se permite patch ciego. Si no hay evidencia de test, el trabajo no esta completo.

## 3) Protocolo de contexto (retrieval-led, obligatorio)
Antes de tocar chat, DB kernel, contratos o integraciones:
1) Leer `framework/docs/INDEX.md`.
2) Leer solo docs relevantes (targeted retrieval), no todo el repositorio.
3) Tomar como fuente de verdad:
   - contratos activos (`project/contracts/*`)
   - registry real (`project/storage/meta/project_registry.sqlite`)

Lectura minima recomendada:
- `framework/docs/PROJECT_MEMORY.md`
- `framework/docs/PROJECT_MEMORY_CANONICAL.md`
- `framework/docs/08_WORK_PLAN.md`
- `framework/docs/07_DATABASE_MODEL.md`
- `framework/docs/AGENTS_CONVERSATION_GATEWAY.md`
- `framework/docs/AGENT_SKILLS_MATRIX.md`
- `framework/docs/HOSTING_MIGRATION_PLAN.md`
- `framework/docs/CODEX_SELF_CHECKLIST.md`

## 3.1) Pre-check obligatorio antes de cada cambio
Ejecutar siempre:
- `php framework/scripts/codex_self_check.php --strict`

Si falla, no se implementa nada hasta corregir causa raiz.

## 3.2) Backup obligatorio (datos y tablas)
- Antes de cambios de DB/kernel/contratos de datos o limpieza de pruebas:
  - `php framework/scripts/db_backup.php`
- `codex_self_check --strict` falla si no existe backup reciente (<= 24h).
- Nunca ejecutar limpiezas masivas sin backup previo.

## 4) Reglas no negociables
- No renombrar ni romper llaves existentes de contratos.
- No SQL crudo en capa app (usar QueryBuilder/CommandLayer/Repository).
- Validar existencia de entidad antes de CRUD.
- En modo APP no crear estructura; en modo BUILDER no ejecutar CRUD de negocio.
- Pregunta minima: 1 dato critico faltante por turno.
- Respuestas para usuario no tecnico: cortas, claras y con siguiente paso concreto.
- Nunca inventar capacidades no presentes en registry.
- Nunca bypass de execution guards.

## 5) Calidad conversacional minima
- Capacidades del agente deben salir del registry real, no texto fijo.
- Si entidad no existe en APP: informar que debe crearla el creador (sin fake success).
- Si el mensaje esta fuera de contexto: reencauzar al objetivo de crear/usar app.
- Confirmar acciones destructivas antes de ejecutar.
- Mantener estado por `tenant + project + mode + user`.
- Nunca afirmar datos no existentes en la base real.

## 6) Reglas DB anti-colapso
- Namespace por proyecto activo: `DB_NAMESPACE_BY_PROJECT=1`.
- Limite por proyecto: `DB_MAX_TABLES_PER_PROJECT`.
- Toda tabla de negocio con `tenant_id` + indices.
- Revisar salud DB con `php framework/tests/db_health.php`.
- Mantener ruta de migracion a modelo canonico (`tenant_id + app_id`) para escala alta.

## 7) Quality Gate obligatorio
Antes de cerrar tareas backend/chat:
- `php framework/tests/run.php`
- `php framework/tests/chat_acid.php`
- `php framework/tests/chat_golden.php`
- `php framework/tests/db_health.php`

Si las pruebas generan artefactos:
- `php framework/tests/reset_test_project.php`

## 8) Skills: como usarlas sin romper logica
Orden de uso:
1) Retrieval-led docs (`INDEX.md` + docs objetivo).
2) Memoria y contratos del repo.
3) Skills puntuales (no reemplazan analisis).

Skills/patrones utiles:
- agent/tool orchestration y tool-calling
- workflow/retry orchestration por pasos
- browser-driven UI/chat verification
- before/after diff checks para cambios visuales

Skill local recomendada en este repo:
- `skills/software-engineering-senior/SKILL.md`
  - aplicar para tareas de arquitectura, regresiones o cambios en chat/DB.
  - ejecutar QA gate pre/post (`framework/scripts/qa_gate.php`).

## 9) Politica de investigacion y mejora continua
- Para arquitectura, seguridad y rendimiento:
  - consultar documentacion oficial y fuentes primarias,
  - evitar copiar patrones sin evidencia.
- Referentes para comparar UX/logica:
  - Vercel Agent Resources
  - Microsoft Power Apps
  - Velneo
  - Supabase
  - v0 / emergent-style builders
  - Kore.ai

## 10) Estandar de respuesta al usuario final
- Evitar tecnicismos innecesarios.
- Entregar siempre:
  1) que se hizo,
  2) evidencia (tests/salidas),
  3) que falta.
- Si hay falla: causa raiz + correccion concreta (sin maquillaje).

## 11) Definicion de mejora (para evitar reemplazos innecesarios)
Cuando se modifique un archivo de gobierno (AGENTS/docs):
- preservar reglas de negocio existentes,
- agregar solo capacidades faltantes (no borrar valor ya probado),
- documentar vacios detectados y como se cubren,
- validar que el nuevo texto no contradiga contratos ni pipeline actual.

## 12) Disciplina obligatoria de versionado (git)
- Identificar archivos clave antes de cambiar:
  - contratos (`framework/contracts/*`, `project/contracts/*`)
  - kernel/engine (`framework/app/Core/*`)
  - docs de gobierno (`framework/docs/*`, `AGENTS.md`)
  - scripts QA (`framework/scripts/*`, `framework/tests/*`)
- Todo cambio exitoso debe cerrar con:
  1) `git add` de archivos modificados,
  2) commit con mensaje trazable,
  3) push al remoto.
- Si QA gate falla, no se permite push.

## 13) Politica de artefactos temporales de testing
- Todos los artefactos temporales viven solo en `framework/tests/tmp/`.
- No crear ni mantener artefactos temporales fuera de esa carpeta.
- La limpieza automatica solo puede tocar `framework/tests/tmp/` y artefactos de prueba declarados por prefijo.

## 14) Agent orientation
### Project purpose
- SUKI es un AI-AOS chat-first para crear y operar apps reales con contratos, router, memoria y ejecucion controlada.
- El objetivo operativo del repo es reducir dependencia del LLM libre usando cache, reglas, RAG, tools y memoria persistente.

### Core architecture
- Runtime compartido: `framework/app/Core/*`.
- Contratos canonicos de runtime: `docs/contracts/*`.
- Contratos del proyecto activo: `project/contracts/*`.
- Estado, registry y metricas livianas: `project/storage/meta/project_registry.sqlite`.
- Pipeline mental minimo para agentes:
  - `cache -> rules -> rag -> tools -> llm fallback`
- En trazas actuales el stage `tools` puede aparecer como `skills` + `CommandBus`; ambos pertenecen a la misma capa de ejecucion controlada.

### Working rules for contributors
- Cambios incrementales solamente. No reescribir modulos estables.
- Todas las acciones nuevas deben ser schema-first.
- Toda lectura/escritura de negocio debe respetar tenant isolation.

### Core modules and repository map
- POS:
  - contratos y formularios en `project/contracts/entities/*`, `project/contracts/invoices/*`, `framework/contracts/forms/ticket_pos.contract.json`
  - guia local en `framework/app/Modules/POS/AGENTS.md`
- Purchases:
  - runtime en `framework/app/Core/PurchasesRepository.php`, `PurchasesService.php`, `PurchasesCommandHandler.php`, `PurchasesMessageParser.php`
  - contratos e invoices en `project/contracts/entities/*` y `project/contracts/invoices/*`
  - guia local en `framework/app/Modules/Purchases/AGENTS.md`
- Fiscal Engine:
  - contratos de invoice en `project/contracts/invoices/*`
  - runtime en `framework/app/Core/*Invoice*`, `ChatAgent.php`, `IntentRouter.php`
- Ecommerce Hub:
  - integraciones en `project/contracts/integrations/*`
  - clientes/adaptadores en `framework/app/Core/AlanubeClient.php`, `IntegrationHttpClient.php`, `OpenApiIntegrationImporter.php`
  - guia local en `framework/app/Modules/Ecommerce/AGENTS.md`
- Media/Documents:
  - runtime en `framework/app/Core/MediaRepository.php`, `MediaService.php`, `MediaCommandHandler.php`, `MediaMessageParser.php`
- Entity Search:
  - runtime en `framework/app/Core/EntitySearchRepository.php`, `EntitySearchService.php`, `EntitySearchCommandHandler.php`, `EntitySearchMessageParser.php`
- AgentOps:
  - runtime en `framework/app/Core/ChatAgent.php`, `framework/app/Core/AgentOpsSupervisor.php`, `framework/app/Core/Agents/Telemetry.php`
  - contrato en `docs/contracts/agentops_metrics_contract.json`
- Semantic Memory:
  - runtime en `framework/app/Core/SemanticMemoryService.php`, `QdrantVectorStore.php`
  - payload/contrato en `docs/contracts/semantic_memory_payload.json`

### Router order reference
- Orden esperado para exploracion y reasoning operativo:
  1) `cache`
  2) `rules`
  3) `rag`
  4) `tools`
  5) `llm fallback`

### Learning lifecycle
- `metrics -> improvement_memory -> learning_candidate(pending) -> review -> approved candidate -> improvement_proposal(open)`
- La promocion solo puede ocurrir desde `review_status=approved`.
- Ninguna propuesta implementa cambios de produccion por si sola; solo crea backlog estructurado y trazable.
