# AGENTS.md (SUKI)

Este archivo define como deben trabajar los agentes de desarrollo en este repo.
No reemplaza reglas del negocio: las refuerza con retrieval-led + QA obligatorio.

## 1) Objetivo operativo
- Entregar un generador de apps chat-first para usuarios no tecnicos.
- Mantener compatibilidad: cambios incrementales, sin reescrituras.
- Priorizar estabilidad en hosting compartido (cPanel) y costo bajo.

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
- `framework/docs/08_WORK_PLAN.md`
- `framework/docs/07_DATABASE_MODEL.md`
- `framework/docs/AGENTS_CONVERSATION_GATEWAY.md`
- `framework/docs/HOSTING_MIGRATION_PLAN.md`

## 4) Reglas no negociables
- No renombrar ni romper llaves existentes de contratos.
- No SQL crudo en capa app (usar QueryBuilder/CommandLayer/Repository).
- Validar existencia de entidad antes de CRUD.
- En modo APP no crear estructura; en modo BUILDER no ejecutar CRUD de negocio.
- Pregunta minima: 1 dato critico faltante por turno.
- Respuestas para usuario no tecnico: cortas, claras y con siguiente paso concreto.

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
