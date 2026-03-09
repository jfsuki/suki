# Training Dataset Template

## Objetivo
Estandarizar lotes de datos de entrenamiento conversacional para ingestar intents, preguntas minimas, hard negatives y casos de QA sin vacios.

## Archivos base
- Template editable: `project/contracts/knowledge/training_dataset_template.json`
- Schema oficial: `framework/contracts/schemas/training_dataset_ingest.schema.json`
- Validador CLI: `framework/scripts/validate_training_dataset.php`
- Gate de publicacion: `framework/scripts/training_dataset_publication_gate.php`
- Vectorizacion canonica: `framework/scripts/training_dataset_vectorize.php`

## Estructura obligatoria
- `batch_id`
- `language`
- `intents_expansion[]`
- `multi_turn_dialogues[]`
- `emotion_cases[]`
- `qa_cases[]`

## Estructura canonica por capas (aditiva, compatible)
- `knowledge_stable[]`: hechos sectoriales estables para retrieval.
- `intents_expansion[]`: comportamiento operativo/intents.
- `support_faq[]`: soporte FAQ para retrieval.
- `policy_constraints[]`: restricciones de gobierno (no vector-first).
- `ontology.canonical_terms[]`: sinonimos normalizados.
- `utterances` viven dentro de `intents_expansion` (explicit/implicit/hard_negatives).

## Que va a embeddings y que no
- Vectorizable por defecto (RAG canonico): `knowledge_stable`, `support_faq`.
- Vectorizable opcional (solo con flag explicito): `intents_expansion` (`--include-intents-expansion`).
- Estructurado (no vector-first): `policy_constraints`, `slots`, `one_question_policy`, mapeo intent->action.

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

## Publicacion controlada (pre-vectorizacion)
Publicar dataset solo si pasa quality + coverage + anti-noise:
```bash
php framework/scripts/training_dataset_publication_gate.php --in=project/contracts/knowledge/mi_lote.clean.json
```

Verificar requisito duro para vectorizacion/RAG:
```bash
php framework/scripts/training_dataset_publication_gate.php --in=project/contracts/knowledge/mi_lote.clean.json --require-published
```

Vectorizar dataset publicado (flujo canonico):
```bash
php framework/scripts/training_dataset_vectorize.php --in=project/contracts/knowledge/mi_lote.clean.json --tenant-id=<tenant_id> --app-id=<app_id>
```

Habilitar `intents_expansion` solo de forma explicita:
```bash
php framework/scripts/training_dataset_vectorize.php --in=project/contracts/knowledge/mi_lote.clean.json --tenant-id=<tenant_id> --include-intents-expansion
```

Prueba sin upsert real (solo preparar chunks y trazabilidad):
```bash
php framework/scripts/training_dataset_vectorize.php --in=project/contracts/knowledge/mi_lote.clean.json --tenant-id=<tenant_id> --dry-run
```

## Reglas semanticas que valida
- Duplicados en utterances (explicitas, implicitas, hard negatives).
- Overlap invalido entre utterances y hard negatives.
- `one_question_policy` debe cubrir todos los slots.
- `missing_slot` en policy debe existir en `slots`.
- Dialogos multi-turn con al menos 2 turnos.
- Deteccion basica de ruido/noise (placeholders, marketing, prefijos de canal).
- Consistencia de capas opcionales (`knowledge_stable`, `support_faq`, `policy_constraints`, `ontology`).
- `quality_score` agregado en salida del validador.

## Recomendacion operativa
Antes de sync a training real:
1) Higiene: `php framework/scripts/sanitize_training_dataset_channels.php --in=<lote.json> --out=<lote.clean.json>`.
2) Validar lote limpio con `--strict`.
3) Revisar `quality_score` y `uniqueness_ratio` en el reporte.
4) Pasar gate de publicacion (`publication.status=published`).
5) Ejecutar vectorizacion canonica (`training_dataset_vectorize.php`).
6) Ejecutar `php framework/scripts/sync_domain_training.php`.
7) Ejecutar QA conversacional (`chat_golden`, `chat_real_100`, `conversation_kpi_gate`).
