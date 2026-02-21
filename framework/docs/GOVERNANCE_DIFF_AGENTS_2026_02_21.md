# GOVERNANCE DIFF — AGENTS.md (2026-02-21)

## Alcance
Mejora incremental de `AGENTS.md` sin romper reglas de negocio.
No cambia contratos ni flujo funcional del runtime.

## 1) Que se preservo
- Objetivo operativo del proyecto (chat-first, no tecnico, estabilidad/costo).
- Flujo obligatorio de trabajo (analizar -> disenar -> implementar -> validar -> reportar).
- Reglas no negociables:
  - no romper contratos,
  - no SQL crudo en capa app,
  - separacion APP vs BUILDER,
  - pregunta minima por turno.
- Reglas DB anti-colapso:
  - `DB_NAMESPACE_BY_PROJECT`,
  - `DB_MAX_TABLES_PER_PROJECT`,
  - salud DB y ruta a modelo canonico.
- Quality gate con tests obligatorios.
- Politica de investigacion basada en fuentes primarias.

## 2) Que se mejoro
- Se agrego protocolo retrieval-led obligatorio:
  - leer `framework/docs/INDEX.md`,
  - cargar solo docs relevantes por tarea,
  - usar registry/contratos como verdad operativa.
- Se reforzo calidad conversacional:
  - capacidades desde registry real,
  - no fake-success,
  - estado por `tenant + project + mode + user`.
- Se formalizo uso de skills como soporte puntual (no sustituto de analisis).
- Se mejoro claridad de gobierno para evitar cambios “por impulso”.

## 3) Vacios que se cerraron
- Vacio previo: reemplazos agresivos de documentos de gobierno.
  - Cierre: regla explicita de mejora incremental en AGENTS.
- Vacio previo: falta de criterio de carga de contexto.
  - Cierre: retrieval-led con lectura minima requerida.
- Vacio previo: falta de estandar unico para respuesta final al usuario.
  - Cierre: bloque obligatorio (que se hizo, evidencia, pendientes).

## 4) Riesgos residuales
- Riesgo: usar tests como “checklist mecanico” sin evaluar causa raiz.
  - Mitigacion: mantener requirement de analisis previo + reporte de causa.
- Riesgo: drift entre docs y comportamiento real.
  - Mitigacion: revisar `PROJECT_MEMORY.md` y `08_WORK_PLAN.md` en cada cambio mayor.

## 5) Estado final
- Cambio aplicado como mejora de gobernanza (no reemplazo funcional).
- Reglas de negocio preservadas.
- Mayor control de calidad y trazabilidad para siguientes iteraciones.
