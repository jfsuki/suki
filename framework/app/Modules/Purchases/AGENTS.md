# Purchases AGENTS

## Responsibility
- Esta carpeta orienta el dominio Purchases.
- El runtime actual usa una base draft-first para registrar compras de proveedor sin adelantar inventario, fiscal, cuentas por pagar ni contabilidad.
- La capa cubre borradores, lineas, asociacion de proveedor, totalizacion y finalizacion `purchase_draft -> purchase`.
- Los documentos de compra reutilizan `MediaService` por referencia `media_file_id`; compras solo registra linkage, clasificacion y metadata.
- El scope actual incluye `supplier_invoice`, `supplier_xml`, `support_document`, `payment_proof` y `general_attachment`; OCR, parsing XML, soporte fiscal e inventory entry siguen como hooks.

## Key classes
- `framework/app/Core/PurchasesRepository.php`
- `framework/app/Core/PurchasesService.php`
- `framework/app/Core/PurchasesCommandHandler.php`
- `framework/app/Core/PurchasesMessageParser.php`
- `framework/contracts/schemas/purchase_document.schema.json`
- `framework/app/Core/EntitySearchService.php`
- `framework/app/Core/MediaService.php`
- `framework/app/Core/ChatAgent.php`

## Contracts involved
- `docs/contracts/action_catalog.json`
- `docs/contracts/skills_catalog.json`
- `framework/contracts/schemas/purchase_draft.schema.json`
- `framework/contracts/schemas/purchase.schema.json`
- `framework/contracts/schemas/purchase_document.schema.json`
- `project/contracts/entities/*`
- `project/contracts/invoices/*`

## Notes
- Mantener tenant isolation y schema-first en cada cambio.
- Resolver proveedor primero por referencia exacta y usar `EntitySearchService` como fallback seguro; no adivinar proveedor.
- El producto en compras es opcional: si el usuario pasa referencia de producto, debe resolverse o fallar seguro; si no, se permite linea libre con `product_label`.
- Mantener lifecycle consistente: `open draft -> completed draft + registered purchase`.
- Los documentos deben vivir en media/documentos y enlazarse desde compras; no crear otro storage backend.
- Mantener linkage seguro: `purchase_draft_id` o `purchase_id`, nunca ambos inventados ni cross-tenant.
- No ejecutar aqui inventory entry, accounts payable, support document fiscal, OCR/XML parsing, media storage custom ni accounting posting; solo dejar hooks trazables.
