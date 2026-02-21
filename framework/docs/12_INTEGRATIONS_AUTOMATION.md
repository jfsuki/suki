# Integrations and Automation - EN
## Goal
Enable apps to integrate external providers (e-invoicing, payments, ERP) and execute the same business processes from UI forms or natural-language chat.

## Core principles
- One process pipeline (UI and chat share the same validators, permissions, and persistence).
- Contracts define processes, inputs, and actions (no hidden logic).
- External calls are async, audited, and retry-safe.
- Observability and audit are mandatory.

## Core components (framework)
- Integration Gateway: provider registry, auth/credentials vault, request signing, retries, webhooks, rate limits, mapping.
- Process Engine: command bus, process definitions, input schemas, policy/permission checks, state tracking.
- Conversation Layer: intent parser, slot filling, confirmations, safe actions.
- Job Queue: async tasks, retries, dead-letter.
- Audit + Logs: every process run and integration call is traceable.

## Canonical runtime flow (implemented)
- `Intent -> Action -> Adapter API -> Result`
- Single endpoint:
  - `POST /api/integrations/action`
- Orchestrator:
  - resolves tenant + integration + environment,
  - normalizes action (`emit_document`, `get_status`, `cancel_document`, `test_connection`),
  - dispatches to adapter (`Alanube`, `Alegra`, `Generic`),
  - writes audit record (`audit_log`) for every action.

## App Manifest Contract (planned)
New contract file:
- project/contracts/app.manifest.json

Example structure:
```json
{
  "app": {
    "id": "miapp",
    "name": "Mi App",
    "tenant_mode": "shared|isolated"
  },
  "db": {
    "strategy": "shared_schema|dedicated_db",
    "schema_source": "master|custom",
    "master_schema_id": "suki_base_v1",
    "connection_alias": "default"
  },
  "registry": {
    "track_changes": true,
    "track_routes": true,
    "track_config": true,
    "track_db_profile": true
  },
  "integrations": [
    {
      "id": "dian_provider",
      "type": "e-invoicing",
      "provider": "ProveedorX",
      "base_url": "https://api.proveedor.com",
      "auth": { "type": "api_key", "ref": "vault:dian_key" },
      "webhooks": ["invoice.created", "invoice.accepted"]
    }
  ],
  "processes": [
    {
      "id": "crear_factura",
      "ui_form": "fact.form.json",
      "intents": ["crear factura", "facturar", "nueva factura"],
      "requires": ["cliente", "items"],
      "actions": ["validate", "persist", "emit_invoice"]
    }
  ]
}
```

## Non-goals (for now)
- Full NLP generation of schemas.
- Unbounded function execution without permissions.

---

# Integraciones y Automatizacion - ES
## Objetivo
Permitir que las apps integren proveedores externos (facturacion electronica, pagos, ERP) y ejecuten los mismos procesos tanto desde formularios como desde chat en lenguaje natural.

## Principios
- Un solo pipeline de proceso (UI y chat comparten validaciones, permisos y persistencia).
- Los contratos definen procesos, entradas y acciones.
- Llamados externos son async, auditables y con reintentos.
- Observabilidad y auditoria obligatorias.

## Componentes core (framework)
- Integration Gateway: registro de proveedores, credenciales, firma, reintentos, webhooks, rate limit, mapping.
- Process Engine: command bus, definiciones de proceso, esquemas de entrada, permisos, tracking.
- Capa conversacional: intents, recoleccion de datos, confirmaciones, acciones seguras.
- Job Queue: tareas async, reintentos, dead-letter.
- Auditoria + Logs: trazabilidad completa.

## Flujo canonico de ejecucion (implementado)
- `Intent -> Action -> Adapter API -> Resultado`
- Endpoint unico:
  - `POST /api/integrations/action`
- Orquestador:
  - resuelve tenant + integracion + ambiente,
  - normaliza accion (`emit_document`, `get_status`, `cancel_document`, `test_connection`),
  - despacha al adapter (`Alanube`, `Alegra`, `Generic`),
  - guarda auditoria obligatoria (`audit_log`) por accion.

## Contrato App Manifest (planeado)
Archivo nuevo:
- project/contracts/app.manifest.json

Estructura ejemplo:
(igual al ejemplo EN)

## No objetivos (por ahora)
- NLP generando esquemas completos.
- Ejecucion de acciones sin permisos.
