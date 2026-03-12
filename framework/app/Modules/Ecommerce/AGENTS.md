# Ecommerce AGENTS

## Responsibility
- Esta carpeta orienta el dominio Ecommerce Hub compartido.
- El runtime canonico vive en `framework/app/Core` y cubre registro de tiendas/canales, credenciales, sync jobs y referencias externas de pedidos.
- Este modulo no ejecuta adaptadores reales todavia; prepara la base para WooCommerce, Tiendanube, PrestaShop y conectores custom.

## Key classes
- `framework/app/Core/EcommerceHubRepository.php`
- `framework/app/Core/EcommerceHubService.php`
- `framework/app/Core/EcommerceHubCommandHandler.php`
- `framework/app/Core/EcommerceHubMessageParser.php`
- `framework/app/Core/EcommerceHubContractValidator.php`
- `framework/app/Core/AlanubeClient.php`
- `framework/app/Core/IntegrationHttpClient.php`
- `framework/app/Core/OpenApiIntegrationImporter.php`

## Contracts involved
- `docs/contracts/action_catalog.json`
- `docs/contracts/skills_catalog.json`
- `framework/contracts/schemas/ecommerce_store.schema.json`
- `framework/contracts/schemas/ecommerce_credential.schema.json`
- `framework/contracts/schemas/ecommerce_sync_job.schema.json`
- `framework/contracts/schemas/ecommerce_order_ref.schema.json`
- `framework/contracts/schemas/ecommerce_store_setup.schema.json`
- `project/contracts/integrations/*`

## Notes
- Mantener tenant isolation en todas las lecturas/escrituras.
- Las credenciales se almacenan cifradas y las respuestas/logs solo exponen payload enmascarado.
- Validar setup con reglas ligeras: tienda existente, credenciales presentes y `connection_status` consistente.
- Los hooks para sync de productos, ordenes, inventario, fiscal y webhooks quedan preparados pero no implementados en este modulo base.
