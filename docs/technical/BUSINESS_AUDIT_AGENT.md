# BUSINESS_AUDIT_AGENT

## Proposito
Business Integrity / Audit Agent define la base contractual para auditar evidencia de negocio sin ejecutar cambios.
En esta etapa es una capa de contratos, catalogos, validacion CLI y tests.

## Alcance
- define configuracion base del auditor
- define formato estructurado de alertas
- define reglas auditables y anomaly patterns extendidos
- valida consistencia con GBO, BEG, action catalog y skills catalog
- deja lista la integracion futura con Control Tower y AgentOps

## Que hace
- modela anomalias auditables versionadas
- modela reglas read-only basadas en evidencia
- valida que una alerta solo proponga acciones estructuradas
- bloquea referencias invalidas a event types, anomaly types, actions y skills
- bloquea referencias cross-tenant en payloads auditables

## Que NO hace
- no ejecuta remediaciones
- no modifica ERP core
- no toca el router productivo
- no reemplaza el ERP transaccional
- no mezcla datos reales entre tenants
- no implementa todavia el motor autonomo de auditoria

## Artefactos
- `framework/contracts/schemas/audit_agent.schema.json`
- `framework/contracts/schemas/audit_alert.schema.json`
- `framework/contracts/schemas/audit_catalog.schema.json`
- `framework/audit/audit_rules.json`
- `framework/audit/anomaly_patterns_extended.json`
- `framework/app/Core/AuditValidator.php`
- `framework/scripts/validate_audit_contracts.php`

## Como validar
```bash
php framework/scripts/validate_audit_contracts.php --strict
php framework/scripts/validate_audit_contracts.php framework/audit/audit_rules.json --strict
php framework/scripts/validate_audit_contracts.php framework/tests/tmp/sample_audit_alert.json --strict
```

## Como extender reglas
1. Agregar o ajustar patterns en `framework/audit/anomaly_patterns_extended.json`
2. Referenciar esos patterns desde `framework/audit/audit_rules.json`
3. Mantener compatibilidad con GBO y BEG
4. Validar por CLI y tests antes de cualquier push
