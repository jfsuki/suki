# AI Onboarding Prompt (MUST READ) — EN
You are working in an existing codebase. This is a **metadata-driven platform** (mother app) generating many customized apps (children) using JSON contracts.

## Non-negotiable rules
1) DO NOT rewrite from scratch.
2) DO NOT break backward compatibility.
3) DO NOT change existing JSON keys/contracts unless a migration plan exists.
4) DO NOT rename variables/functions/stores unless explicitly requested.
5) Always propose minimal incremental patches.
6) Always validate against the current code behavior.
7) If uncertain, STOP and ask.

## System pillars
- JSON is the single source of truth for UI + behavior.
- Core engine is stable (runtime/interpreters).
- Apps are configured via contracts, not hard-coded.
- Tenants customize via params, not forks.

## Mandatory workflow before coding
- List affected files
- Explain current behavior
- Explain the change
- Risks + edge cases
- Minimal patch plan
- Validation checklist
Then code.

## Docs to read
- /framework/docs/00_SYSTEM_OVERVIEW.md
- /framework/docs/01_RULES_NO_BREAK.md
- /framework/docs/02_ARCHITECTURE.md
- /framework/docs/03_FORM_CONTRACT.md
- /framework/docs/04_GRID_CONTRACT.md
- /framework/docs/05_VALIDATION_ENGINE.md
- /framework/docs/06_PERSISTENCE_PLAN.md
- /framework/docs/07_DATABASE_MODEL.md
- /framework/docs/08_WORK_PLAN.md
- /framework/docs/10_AI_OPERATING_PROCEDURE.md

---

# Prompt de Onboarding IA (OBLIGATORIO) — ES
Estás trabajando en un código existente. Esto es una **plataforma dirigida por metadatos** (app madre) que genera muchas apps personalizadas (hijas) usando contratos JSON.

## Reglas no negociables
1) NO reescribir desde cero.
2) NO romper compatibilidad.
3) NO cambiar llaves/contratos JSON sin plan de migración.
4) NO renombrar variables/funciones/stores sin instrucción explícita.
5) Solo cambios incrementales mínimos.
6) Validar siempre contra el comportamiento actual.
7) Si hay duda, DETENERSE y preguntar.

## Pilares del sistema
- JSON es la fuente única de verdad para UI + comportamiento.
- El motor core es estable.
- Las apps se configuran por contrato, no por hardcode.
- Los tenants personalizan por parámetros, no forks.

## Flujo obligatorio antes de codificar
- Archivos afectados
- Comportamiento actual
- Cambio propuesto
- Riesgos y casos borde
- Plan mínimo
- Checklist de validación
Luego código.

## Documentos a leer
(igual lista)

