# Business Discovery Template

## Objetivo
Estandarizar el levantamiento sectorial antes de generar datasets de entrenamiento ERP.

Flujo canonico:
1. `BusinessDiscovery` estructurado
2. dataset JSON compatible con `training_dataset_ingest.schema.json`
3. `training_dataset_publication_gate.php`
4. `training_dataset_vectorize.php`

## Archivos base
- Template editable: `project/contracts/knowledge/business_discovery_template.json`
- Schema discovery: `framework/contracts/schemas/business_discovery_template.schema.json`
- Compilador: `framework/scripts/business_discovery_to_training_dataset.php`
- Schema ingest: `framework/contracts/schemas/training_dataset_ingest.schema.json`

## Secciones obligatorias del discovery
- Identidad del sector: `sector_key`, `sector_label`, `country_or_regulation`, `business_type`
- Procesos ERP: `sales`, `purchases`, `inventory`, `accounting`, `billing`, `reporting`
- Documentos: `invoice`, `purchase_order`, `delivery_note`, `credit_note`, `debit_note`, `accounting_entry`, `bank_statement`
- Flujos operativos: secuencias explicitas de `event` y `skill`
- Reglas contables: impuesto, IVA, retenciones, ventas a credito, costo inventario, costo de ventas
- Terminologia sectorial
- Preguntas frecuentes reales
- Skill mapping explicito
- Guardrails de arquitectura
- Reglas de calidad del dataset

## Reglas duras
- El LLM nunca ejecuta acciones ERP.
- Toda accion ERP pasa por skills registradas.
- RAG solo recupera conocimiento.
- El aislamiento tenant/proyecto/modo/usuario se preserva.
- AgentOps/telemetria permanecen activos.
- No duplicar utterances ni repetirlas por canal.

## Compilacion
```bash
php framework/scripts/business_discovery_to_training_dataset.php \
  --in=project/contracts/knowledge/business_discovery_template.json \
  --out=project/contracts/knowledge/training_dataset_ferreteria_minorista_from_discovery.json
```

## Validacion recomendada
```bash
php framework/tests/business_discovery_template_test.php
php framework/scripts/training_dataset_publication_gate.php --in=project/contracts/knowledge/training_dataset_ferreteria_minorista_from_discovery.json
php framework/scripts/training_dataset_vectorize.php --in=project/contracts/knowledge/training_dataset_ferreteria_minorista_from_discovery.json --tenant-id=<tenant> --dry-run
```
