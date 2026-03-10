# Contracts AGENTS

## Responsibility
- `docs/contracts` define contratos canonicos de runtime para tools, skills, router y observabilidad.
- Estos archivos son schema-first y gobiernan integraciones del motor.

## Key contracts
- `action_catalog.json`: acciones/herramientas permitidas por contrato.
- `skills_catalog.json`: skills y tool orchestration.
- `router_policy.json`: politica del router.
- `agentops_metrics_contract.json`: campos minimos de observabilidad.
- `semantic_memory_payload.json`: payload minimo para semantic memory.

## Contracts involved with nearby code
- `framework/app/Core/SkillExecutor.php`
- `framework/app/Core/IntentRouter.php`
- `framework/app/Core/ChatAgent.php`
- `framework/app/Core/TelemetryService.php`

## Local rules
- No renombrar llaves existentes sin migracion/documentacion explicita.
- Toda accion nueva requiere schema + integracion + tests.
- Mantener compatibilidad con `ContractRegistry`.
