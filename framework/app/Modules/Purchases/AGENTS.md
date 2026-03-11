# Purchases AGENTS

## Responsibility
- Esta carpeta orienta el dominio Purchases.
- El runtime actual usa una base draft-first para registrar compras de proveedor sin adelantar inventario, fiscal, cuentas por pagar ni contabilidad.
- La capa cubre borradores, lineas, asociacion de proveedor, totalizacion y finalizacion `purchase_draft -> purchase`; documentos, soporte fiscal e inventory entry siguen como hooks.

## Key classes
- `framework/app/Core/PurchasesRepository.php`
- `framework/app/Core/PurchasesService.php`
- `framework/app/Core/PurchasesCommandHandler.php`
- `framework/app/Core/PurchasesMessageParser.php`
- `framework/app/Core/EntitySearchService.php`
- `framework/app/Core/MediaService.php`
- `framework/app/Core/ChatAgent.php`

## Contracts involved
- `docs/contracts/action_catalog.json`
- `docs/contracts/skills_catalog.json`
- `framework/contracts/schemas/purchase_draft.schema.json`
- `framework/contracts/schemas/purchase.schema.json`
- `project/contracts/entities/*`
- `project/contracts/invoices/*`

## Notes
- Mantener tenant isolation y schema-first en cada cambio.
- Resolver proveedor primero por referencia exacta y usar `EntitySearchService` como fallback seguro; no adivinar proveedor.
- El producto en compras es opcional: si el usuario pasa referencia de producto, debe resolverse o fallar seguro; si no, se permite linea libre con `product_label`.
- Mantener lifecycle consistente: `open draft -> completed draft + registered purchase`.
- No ejecutar aqui inventory entry, accounts payable, support document fiscal, media attachments ni accounting posting; solo dejar hooks trazables.
