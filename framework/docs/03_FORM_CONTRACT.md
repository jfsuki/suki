# Form Contract — EN
## Contract metadata
- contractVersion (string)
- engineVersion (string)
- formId (string)
- formName (string)
- namespace (string)
- createdAt, updatedAt

## Optional integration (MVP)
- entity (string) -> nombre de EntityContract para CRUD
- action (string) -> endpoint API override (si no se define, usa /api/records/{entity})

## Fields
Each field:
- name (stable key, no rename)
- type (text, number, select, date, money, etc.)
- label
- required (bool)
- default
- rules (validation rules)
- ui (placeholder, help, mask, etc.)
- data (source bindings)

## Rules
- Never break existing keys
- Additive changes only
- Deprecate via `deprecated: true` but keep supported

---

# Contrato de Formulario — ES
## Metadatos
- contractVersion
- engineVersion
- formId
- formName
- namespace
- createdAt, updatedAt

## Integración opcional (MVP)
- entity (string) -> nombre de EntityContract para CRUD
- action (string) -> endpoint API override (si no se define, usa /api/records/{entity})

## Campos
Cada campo:
- name (llave estable)
- type
- label
- required
- default
- rules (validaciones)
- ui
- data (bindings)

## Reglas
- No romper llaves existentes
- Cambios aditivos
- Deprecar sin eliminar
