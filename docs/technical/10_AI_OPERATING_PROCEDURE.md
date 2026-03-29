# AI Operating Procedure — EN
## Required format
Before code:
1) Affected files
2) Current behavior
3) Proposed change
4) Risks
5) Minimal patch plan
6) Validation checklist

## Forbidden
- big refactors
- renaming contracts
- deleting features
- changing routing conventions without migration
- hardcoding form/grid configs in views (must load JSON from /project/contracts; legacy fallback only)
- exposing non-public folders; web root must be project/public (framework/public solo assets/editor)
- mixing framework files into project distribution

## When to stop
If any dependency is unknown -> ask.

---

# Procedimiento IA — ES
## Formato obligatorio
Antes de código:
1) Archivos afectados
2) Comportamiento actual
3) Cambio propuesto
4) Riesgos
5) Plan mínimo
6) Checklist

## Prohibido
- refactors grandes
- renombrar contratos
- borrar features
- cambiar rutas sin migración
- hardcode de formularios/grids en vistas (deben cargar JSON desde /project/contracts; legacy solo como fallback)
- exponer carpetas no públicas; el web root debe ser project/public (framework/public solo assets/editor)
- mezclar archivos del framework dentro del proyecto

## Cuándo parar
Si falta info -> preguntar
