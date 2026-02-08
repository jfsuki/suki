# No-Break Rules — EN
## Purpose
Prevent regressions and wasted time. This project must evolve incrementally.

## Forbidden
- Rewrite modules from scratch
- Rename contract keys
- Big refactors “for cleanliness”
- Changing existing store names (FORM_STORE/GRID_STORE)
- Changing routing conventions without migration

## Mandatory change workflow
1) Read current behavior
2) Identify dependencies (who consumes this?)
3) Minimal patch proposal
4) Validation checklist
5) Apply patch
6) Re-test critical flows

## Regression checklist (minimum)
- Form renders
- Grid add/remove row
- Totals recalc
- Summary update
- Submit payload structure unchanged

---

# Reglas No-Romper — ES
## Propósito
Evitar regresiones y pérdida de tiempo. Evolución incremental.

## Prohibido
- Reescribir módulos desde cero
- Renombrar llaves del contrato
- Refactors grandes “por limpieza”
- Cambiar nombres de stores (FORM_STORE/GRID_STORE)
- Cambiar rutas sin migración

## Flujo obligatorio
1) Leer comportamiento actual
2) Identificar dependencias
3) Proponer patch mínimo
4) Checklist de validación
5) Aplicar
6) Re-test de flujos críticos

## Checklist mínimo
- Render form
- Grid add/remove
- Totales recalc
- Summary update
- Submit payload igual
