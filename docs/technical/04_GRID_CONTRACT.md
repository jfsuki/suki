# Grid Contract — EN
## Grid config
- name (stable grid id)
- columns: [{ name, type, label, required, formula?, total? }]
- rowDefaults
- totals: per column or computed
- events/hooks (optional, versioned)

### Column input (select)
- input.type = "select"
- input.optionsSource = "manual" | "api"
- input.options (manual) = [{ value, label }]
- input.options (api) = { endpoint, map: { value, label } }

## Storage
GRID_STORE[gridName] = { rows: [], totals: {}, meta: {} }

---

# Contrato de Grid — ES
## Config
- name
- columns: [{ name, type, label, required, formula?, total? }]
- rowDefaults
- totals
- hooks opcionales

### Input select
- input.type = "select"
- input.optionsSource = "manual" | "api"
- input.options (manual) = [{ value, label }]
- input.options (api) = { endpoint, map: { value, label } }

## Storage
GRID_STORE[gridName] = { rows: [], totals: {}, meta: {} }
