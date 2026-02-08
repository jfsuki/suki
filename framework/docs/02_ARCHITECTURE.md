# Architecture — EN
## Layers
### Core (Engine)
- FormGenerator orchestration
- FormBuilder UI primitives
- Grid runtime (rows, totals, formulas)
- Summary runtime (dependency graph)
- Validation runtime
- Persistence adapters (save/load)
- DB Kernel (query builder + security)
- Dev Assistant (formjson.html) to generate contracts, views, and DB artifacts

### App (Templates)
- JSON contracts: forms/grids/modules
- Business workflows (configured)
- Feature flags per module

### Tenant (Customer)
- Branding, permissions, tax rules, numbering
- DIAN settings, company settings
- User roles and field-level rules

## Rule
Core must be stable. Apps and Tenants configure behavior via contracts.

---

# Arquitectura — ES
## Capas
### Core (Motor)
- Orquestación FormGenerator
- UI base FormBuilder
- Runtime de grids (filas, totales, fórmulas)
- Runtime de summary (grafo de dependencias)
- Runtime de validaciones
- Adaptadores de persistencia
- Kernel DB (builder + seguridad)
- Asistente Dev (formjson.html) para generar contratos, vistas y artefactos de BD

### App (Plantillas)
- Contratos JSON de formularios/grids/módulos
- Flujos configurables
- Feature flags por módulo

### Tenant (Cliente)
- Branding, permisos, impuestos, numeración
- Config DIAN, empresa
- Roles y reglas por campo

## Regla
El core se mantiene estable. Apps y Tenants parametrizan.
