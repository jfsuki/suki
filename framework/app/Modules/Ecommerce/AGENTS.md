# Ecommerce AGENTS

## Responsibility
- Esta carpeta orienta el dominio Ecommerce Hub compartido.
- El runtime canonico vive en `framework/app/Core` y cubre registro de tiendas/canales, credenciales, sync jobs y referencias externas de pedidos.
- Este modulo ya expone una base de adapters para WooCommerce, Tiendanube y PrestaShop con resolver seguro y fallback `unknown`.
- El alcance actual es validacion, metadata, capacidades y ping seguro; sync real sigue fuera de alcance.

## Key classes
- `framework/app/Core/EcommerceHubRepository.php`
- `framework/app/Core/EcommerceHubService.php`
- `framework/app/Core/EcommerceHubCommandHandler.php`
- `framework/app/Core/EcommerceHubMessageParser.php`
- `framework/app/Core/EcommerceHubContractValidator.php`
- `framework/app/Core/EcommerceAdapterInterface.php`
- `framework/app/Core/EcommerceAdapterResolver.php`
- `framework/app/Core/WooCommerceAdapter.php`
- `framework/app/Core/TiendanubeAdapter.php`
- `framework/app/Core/PrestaShopAdapter.php`
- `framework/app/Core/UnknownEcommerceAdapter.php`
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
- Validar setup con reglas ligeras: tienda existente, adapter resuelto, credenciales presentes y `connection_status` consistente.
- `validate_connection` valida forma/configuracion de credenciales; no inventa conexion exitosa sin evidencia real.
- `ping_store` es seguro por defecto: si no hay precondiciones o remote ping no aplica, responde explicitamente sin exponer secretos.
- Los hooks para sync de productos, ordenes, inventario, fiscal y webhooks quedan preparados pero no implementados en este modulo base.
