# ERP Training Dataset Pipeline

## Objetivo
Validar, normalizar y preparar datasets ERP fuente para entrenamiento de agentes sin tocar el runtime productivo.

## Boundary
- `LLM interpreta; PHP valida, normaliza, persiste y exporta`.
- No ejecuta embeddings ni escribe en Qdrant.
- No mezcla datos operativos de tenant.
- El scope del dataset preparado es `shared_non_operational_training`.

## Input esperado
JSON fuente con:
- `metadata`
- `BLOQUE_A_intents_catalog`
- `BLOQUE_B_training_samples`
- `BLOQUE_C_hard_cases`

## Validacion
Script:
- `php framework/scripts/validate_erp_training_dataset.php <dataset.json>`

Chequeos principales:
- estructura de bloques
- `intent_key`, `target_skill`, `skill_type`, `required_action`
- skills reales desde `docs/contracts/skills_catalog.json`
- acciones reales desde `docs/contracts/action_catalog.json`
- tipos numericos y `ambiguity_flags`
- consistencia entre catalogo, samples y hard cases

Modo estricto:
- `php framework/scripts/validate_erp_training_dataset.php <dataset.json> --strict`
- falla si hay warnings de higiene o consistencia blanda

## Preparacion
Script:
- `php framework/scripts/prepare_erp_training_dataset.php <dataset.json> --out-dir=<dir>`

Artefactos generados:
- `erp_intents_catalog.json`
- `erp_training_samples.json`
- `erp_hard_cases.json`
- `erp_vectorization_prep.json`
- `erp_pipeline_report.json`

## Hygiene minima
- elimina duplicados exactos por `intent_key + utterance`
- marca near duplicates simples
- marca repeticion sospechosa por prefijo
- rechaza utterances vacias o basura extrema
- preserva `utterance_original`

## Qdrant prep
- prepara metadata para futura vectorizacion
- coleccion objetivo actual: `agent_training`
- separacion canonica preservada:
  - `agent_training`
  - `sector_knowledge`
  - `user_memory`
- este pipeline no upsertea vectores ni cambia colecciones

## Regression-friendly
`erp_hard_cases.json` preserva:
- `expected_resolution`
- `expected_route_stage`
- `expected_supervisor_flags`
- `regression_tags`

Esto permite reutilizar hard cases en tests futuros del router, supervisor y capas de seguridad.
