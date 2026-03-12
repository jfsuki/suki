# Ecommerce AGENTS

## Responsibility
- Esta carpeta orienta el dominio Ecommerce Hub compartido.
- El runtime canonico vive en `framework/app/Core` y cubre registro de tiendas/canales, credenciales, sync jobs y referencias externas de pedidos.
- Este modulo ya expone una base de adapters para WooCommerce, Tiendanube y PrestaShop con resolver seguro y fallback `unknown`.
- El alcance actual incluye validacion, metadata, capacidades, ping seguro, una base de product sync canonica y una base de order sync canonica.
- Product sync actual: links local/external, payload push preparado, snapshots pull y estado de sync.
- Order sync actual: order links canonicos, snapshots externos normalizados y estado de sync sin crear ventas/fiscal/locales.
- Sync remoto real sigue fuera de alcance.

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
- `framework/contracts/schemas/ecommerce_order_link.schema.json`
- `framework/contracts/schemas/ecommerce_order_snapshot.schema.json`
- `framework/contracts/schemas/ecommerce_product_link.schema.json`
- `framework/contracts/schemas/ecommerce_store_setup.schema.json`
- `project/contracts/integrations/*`

## Notes
- Mantener tenant isolation en todas las lecturas/escrituras.
- Las credenciales se almacenan cifradas y las respuestas/logs solo exponen payload enmascarado.
- Validar setup con reglas ligeras: tienda existente, adapter resuelto, credenciales presentes y `connection_status` consistente.
- `validate_connection` valida forma/configuracion de credenciales; no inventa conexion exitosa sin evidencia real.
- `ping_store` es seguro por defecto: si no hay precondiciones o remote ping no aplica, responde explicitamente sin exponer secretos.
- Product sync foundation:
  - `link_product` y `unlink_product` solo gestionan el vinculo canonico interno.
  - `prepare_product_push_payload` construye payload normalizado por adapter sin llamar APIs remotas.
  - `register_product_pull_snapshot` normaliza payload externo y registra snapshot sin crear productos locales.
  - `mark_product_sync_status` solo registra estado/direccion de sync.
- Order sync foundation:
  - `link_order` solo gestiona el vinculo canonico entre pedido externo y referencia local opcional.
  - `normalize_external_order` produce payload normalizado por adapter sin crear ventas locales.
  - `register_order_pull_snapshot` guarda snapshot externo + payload normalizado sin ejecutar POS, fiscal, inventario ni clientes.
  - `mark_order_sync_status` solo registra estado de sync.
- Resolver productos locales via `EntitySearchService`; si no existe referencia real, fallar de forma explicita.
- Los hooks para venta local, fiscal, inventario, cliente, pagos, sync remoto de productos/ordenes e ingestion webhook quedan preparados pero no implementados en este modulo base.
