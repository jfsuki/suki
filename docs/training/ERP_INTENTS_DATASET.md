# ERP Intents Dataset

## Purpose
`framework/training/intents_erp_base.json` entrena mapeo de lenguaje natural ERP hacia skills reales ya publicadas en `skills_catalog.json`, sin tocar router ni runtime.

## Structure
El dataset usa un formato ligero `intent_dataset` para `agent_training`.

Cada entrada contiene:
- `intent`
- `utterances`
- `skill`
- `confidence`
- `domain`

Metadata requerida:
- `type=intent_dataset`
- `dataset=intents_erp_base`
- `domain=erp`
- `collection=agent_training`
- `embedding_model=gemini-embedding-001`
- `embedding_dimension=768`

## How To Extend
1. Agrega un nuevo objeto en `entries`.
2. Usa solo skills existentes en `docs/contracts/skills_catalog.json`.
3. Mantén 10 a 15 utterances cortas por intent.
4. Evita utterances duplicadas globalmente.
5. Valida antes de publicar.

## Validate
```bash
php framework/scripts/validate_intents_dataset.php framework/training/intents_erp_base.json --strict
```

## Publish
```bash
php framework/scripts/training_dataset_publication_gate.php --in=framework/training/intents_erp_base.json
```

## Vectorize
```bash
php framework/scripts/training_dataset_vectorize.php --in=framework/training/intents_erp_base.json --tenant-id=<tenant_id> --dry-run
```

Para upsert real, omite `--dry-run`.
