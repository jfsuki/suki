# Training Dataset Template

## Objetivo
Estandarizar lotes de datos de entrenamiento conversacional para ingestar intents, preguntas minimas, hard negatives y casos de QA sin vacios.

## Archivos base
- Template editable: `project/contracts/knowledge/training_dataset_template.json`
- Schema oficial: `framework/contracts/schemas/training_dataset_ingest.schema.json`
- Validador CLI: `framework/scripts/validate_training_dataset.php`

## Estructura obligatoria
- `batch_id`
- `language`
- `intents_expansion[]`
- `multi_turn_dialogues[]`
- `emotion_cases[]`
- `qa_cases[]`

## Validacion
Validacion estandar:
```bash
php framework/scripts/validate_training_dataset.php
```

Validar un archivo especifico:
```bash
php framework/scripts/validate_training_dataset.php project/contracts/knowledge/mi_lote.json
```

Validacion estricta (warnings rompen):
```bash
php framework/scripts/validate_training_dataset.php project/contracts/knowledge/mi_lote.json --strict
```

Umbrales personalizados:
```bash
php framework/scripts/validate_training_dataset.php project/contracts/knowledge/mi_lote.json --strict --min-explicit=40 --min-implicit=40 --min-hard-negatives=40 --min-dialogues=10 --min-qa=10
```

## Reglas semanticas que valida
- Duplicados en utterances (explicitas, implicitas, hard negatives).
- Overlap invalido entre utterances y hard negatives.
- `one_question_policy` debe cubrir todos los slots.
- `missing_slot` en policy debe existir en `slots`.
- Dialogos multi-turn con al menos 2 turnos.

## Recomendacion operativa
Antes de sync a training real:
1) Validar lote con `--strict`.
2) Ejecutar `php framework/scripts/sync_domain_training.php`.
3) Ejecutar QA conversacional (`chat_golden`, `chat_real_100`, `conversation_kpi_gate`).
