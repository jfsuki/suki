# BUSINESS_EVENT_GRAPH

## Propósito
BEG modela eventos empresariales universales y sus relaciones causales sobre la base semántica del GBO.
En esta etapa es contrato + validación + estructura base; no es motor transaccional ni grafo runtime productivo.

## Artefactos
- `framework/contracts/schemas/beg.schema.json`
- `framework/contracts/schemas/beg_event_payload.schema.json`
- `framework/events/beg_event_types.json`
- `framework/events/beg_relationship_types.json`
- `framework/events/beg_anomaly_patterns.json`
- `framework/events/beg_projection_rules.json`
- `framework/app/Core/BegValidator.php`
- `framework/scripts/validate_beg_contracts.php`

## Qué valida
- event types alineados con `gbo_business_events.json`
- relationship types permitidos
- anomalías y projection rules contra catálogos reales
- payload de evento con `tenant_id` y `app_id`
- bloqueo de cross-tenant references
- integridad causal mínima

## Cómo validar
```bash
php framework/scripts/validate_beg_contracts.php --strict
php framework/scripts/validate_beg_contracts.php framework/events/beg_event_types.json --strict
php framework/scripts/validate_beg_contracts.php framework/tests/tmp/sample_beg_event.json --strict
```

## Qué NO hace
- no reemplaza ERP core
- no ejecuta compensaciones ni postings
- no usa Neo4j/Qdrant como grafo causal principal
- no mezcla datos reales entre tenants
- no crea agentes autónomos

## Extensión futura
BEG queda preparado para Country Packs, Sector Packs, auditoría de integridad y agentes autónomos, siempre sobre contratos compatibles.
