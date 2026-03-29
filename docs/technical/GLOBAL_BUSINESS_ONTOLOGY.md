# GLOBAL_BUSINESS_ONTOLOGY

## Propósito
GBO define una capa semántica universal y versionada para conceptos, eventos, relaciones y aliases de negocio.
No ejecuta acciones, no reemplaza contratos transaccionales y no contiene datos por tenant.

## Artefactos
- `framework/contracts/schemas/gbo.schema.json`
- `framework/ontology/gbo_universal_concepts.json`
- `framework/ontology/gbo_business_events.json`
- `framework/ontology/gbo_semantic_relationships.json`
- `framework/ontology/gbo_base_aliases.json`
- `framework/app/Core/GboValidator.php`
- `framework/scripts/validate_gbo.php`

## Qué valida
- shape de schema
- conceptos/eventos duplicados
- relaciones contra tipos inexistentes
- aliases conflictivos
- versionado base
- conceptos potencialmente huérfanos

## Cómo validar
```bash
php framework/scripts/validate_gbo.php --strict
php framework/scripts/validate_gbo.php framework/ontology/gbo_universal_concepts.json --strict
```

## Qué NO hace
- no ejecuta CRUD
- no altera router
- no reemplaza entity/form/invoice contracts
- no crea memoria por tenant
- no decide acciones runtime

## Extensión futura
Country Packs, Sector Packs y Language Packs deben extender GBO de forma aditiva y compatible; no reescribir la base.
