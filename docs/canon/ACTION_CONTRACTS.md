# ACTION_CONTRACTS
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-02  
Scope: Structural contract for intent-to-action governance (no implementation).

## 1) Core Definitions
### 1.1 Intent
An Intent is the normalized user objective after parsing and policy normalization.  
An Intent is not execution by itself.

### 1.2 Executable Action
Action that may change system state or external systems.  
It requires full gate validation and idempotent execution controls.

### 1.3 Informative Action
Action that returns explanation, status, guidance, or reports without side effects.

### 1.4 Forbidden Action
Action denied by mode, policy, role, safety, or missing contract.

## 2) Mandatory Intent Structure
Every intent definition SHALL include:
- `name`
- `type`
- `required_role`
- `allowed_tools`
- `json_schema_required` (bool)
- `rag_required` (bool)
- `risk_level`
- `gates_required`

Canonical type values:
- `EXECUTABLE`
- `INFORMATIVE`
- `FORBIDDEN`

Canonical risk values:
- `low`
- `medium`
- `high`
- `critical`

## 3) Contract Template (Normative)
```json
{
  "name": "INTENT_NAME",
  "type": "EXECUTABLE|INFORMATIVE|FORBIDDEN",
  "required_role": "admin|builder|operator|support|auditor",
  "allowed_tools": ["tool_a", "tool_b"],
  "json_schema_required": true,
  "rag_required": false,
  "risk_level": "low|medium|high|critical",
  "gates_required": [
    "mode_guard",
    "role_guard",
    "schema_guard",
    "evidence_guard",
    "idempotency_guard",
    "audit_guard"
  ]
}
```

## 4) Execution Rules By Type
### 4.1 EXECUTABLE
- MUST pass all listed gates before enqueue/dispatch.
- MUST use idempotency key.
- MUST generate audit event.
- MUST fail closed on schema mismatch.

### 4.2 INFORMATIVE
- MUST NOT create side effects.
- MAY use RAG evidence if `rag_required=true`.
- MUST return traceable source references.

### 4.3 FORBIDDEN
- MUST be denied immediately.
- MUST include denial reason and policy reference.
- MUST NOT call execution tools.

## 5) Canonical Examples (No Implementation)
### 5.1 CRUD Example
```json
{
  "name": "CRUD_CREATE_CLIENTE",
  "type": "EXECUTABLE",
  "required_role": "operator",
  "allowed_tools": ["CommandBus"],
  "json_schema_required": true,
  "rag_required": false,
  "risk_level": "medium",
  "gates_required": [
    "mode_guard",
    "role_guard",
    "schema_guard",
    "entity_exists_guard",
    "idempotency_guard",
    "audit_guard"
  ]
}
```

### 5.2 Facturacion Example
```json
{
  "name": "FACTURACION_EMITIR_FE_CO",
  "type": "EXECUTABLE",
  "required_role": "admin",
  "allowed_tools": ["CommandBus", "IntegrationActionOrchestrator"],
  "json_schema_required": true,
  "rag_required": true,
  "risk_level": "high",
  "gates_required": [
    "mode_guard",
    "role_guard",
    "schema_guard",
    "policy_guard",
    "confirmation_guard",
    "idempotency_guard",
    "audit_guard"
  ]
}
```

### 5.3 FE Configuration Example
```json
{
  "name": "FE_CONFIG_RESOLUCION_DIAN",
  "type": "EXECUTABLE",
  "required_role": "admin",
  "allowed_tools": ["CommandBus", "IntegrationActionOrchestrator"],
  "json_schema_required": true,
  "rag_required": true,
  "risk_level": "critical",
  "gates_required": [
    "mode_guard",
    "role_guard",
    "schema_guard",
    "evidence_guard",
    "confirmation_guard",
    "policy_guard",
    "audit_guard"
  ]
}
```

### 5.4 Reportes Example
```json
{
  "name": "REPORTES_RESUMEN_VENTAS",
  "type": "INFORMATIVE",
  "required_role": "operator",
  "allowed_tools": ["QueryReadOnly", "ReportRenderer"],
  "json_schema_required": true,
  "rag_required": false,
  "risk_level": "low",
  "gates_required": [
    "mode_guard",
    "role_guard",
    "schema_guard",
    "audit_guard"
  ]
}
```

### 5.5 Soporte Example
```json
{
  "name": "SOPORTE_EXPLICAR_ERROR_FACTURA",
  "type": "INFORMATIVE",
  "required_role": "support",
  "allowed_tools": ["KnowledgeLookup", "ErrorCatalog"],
  "json_schema_required": true,
  "rag_required": true,
  "risk_level": "low",
  "gates_required": [
    "mode_guard",
    "role_guard",
    "evidence_guard",
    "audit_guard"
  ]
}
```

### 5.6 Forbidden Example
```json
{
  "name": "BYPASS_EXECUTION_GUARDS",
  "type": "FORBIDDEN",
  "required_role": "admin",
  "allowed_tools": [],
  "json_schema_required": false,
  "rag_required": false,
  "risk_level": "critical",
  "gates_required": [
    "policy_guard",
    "audit_guard"
  ]
}
```

## 6) Compatibility Rule
This contract canon is additive.  
Existing runtime keys are not renamed or removed by this document.

## 7) Non-Goal
This file defines structure and governance only. It does not register or execute intents.
