# Ecommerce AGENTS

## Responsibility
- Esta carpeta orienta el dominio Ecommerce Hub.
- El runtime actual vive en adaptadores e integraciones compartidas bajo `framework/app/Core`.

## Key classes
- `framework/app/Core/AlanubeClient.php`
- `framework/app/Core/IntegrationHttpClient.php`
- `framework/app/Core/OpenApiIntegrationImporter.php`
- `framework/app/Core/ChatAgent.php`

## Contracts involved
- `project/contracts/integrations/*`
- `docs/contracts/action_catalog.json`
- `docs/contracts/skills_catalog.json`

## Notes
- Mantener integraciones oficiales y schema-first.
- Preservar tenant isolation y trazabilidad de AgentOps.
