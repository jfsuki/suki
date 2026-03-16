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

Ejemplo real versionado en este repo:
- `framework/training/erp_training_dataset_example.json`

No usar como input de este pipeline:
- `framework/training/intents_erp_base.json`
- Ese archivo es un `intent_dataset` legacy para otro flujo de entrenamiento/publicacion.

## Validacion
Script:
- `php framework/scripts/validate_erp_training_dataset.php framework/training/erp_training_dataset_example.json`

Chequeos principales:
- estructura de bloques
- `intent_key`, `target_skill`, `skill_type`, `required_action`
- skills reales desde `docs/contracts/skills_catalog.json`
- acciones reales desde `docs/contracts/action_catalog.json`
- tipos numericos y `ambiguity_flags`
- consistencia entre catalogo, samples y hard cases

Modo estricto:
- `php framework/scripts/validate_erp_training_dataset.php framework/training/erp_training_dataset_example.json --strict`
- falla si hay warnings de higiene o consistencia blanda

## Preparacion
Script:
- `php framework/scripts/prepare_erp_training_dataset.php framework/training/erp_training_dataset_example.json --out-dir=framework/training/output/erp_training_dataset_example`

Artefactos generados:
- `erp_intents_catalog.json`
- `erp_training_samples.json`
- `erp_hard_cases.json`
- `erp_vectorization_prep.json`
- `erp_pipeline_report.json`

Ruta de salida recomendada en este repo:
- `framework/training/output/<dataset_name>/`

Los artefactos bajo `framework/training/output/` son generados y no deben versionarse.

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

## UX CLI y guardrails
- `--help` imprime uso exacto y ejemplo real del repo.
- Si el input no existe o no es `.json`, el CLI falla con mensaje guiado y candidatos detectados.
- Si el archivo parece un `intent_dataset` legacy o un artefacto ya preparado, el validador lo reporta de forma explicita.
- Si `--out-dir` apunta a una ruta sospechosa o a un archivo `.json`, el CLI bloquea la ejecucion.
- Si no se pasa `--out-dir`, el default es `framework/training/output/<dataset_name>` cuando el input vive en `framework/training/`.

## Regression-friendly
`erp_hard_cases.json` preserva:
- `expected_resolution`
- `expected_route_stage`
- `expected_supervisor_flags`
- `regression_tags`

Esto permite reutilizar hard cases en tests futuros del router, supervisor y capas de seguridad.
