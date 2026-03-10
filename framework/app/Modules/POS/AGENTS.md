# POS AGENTS

## Responsibility
- Esta carpeta es una guia local para el dominio POS.
- El runtime POS usa una base compartida y draft-first para preparar venta, precio y contexto operativo antes del checkout.
- La resolucion de producto POS debe ser deterministica y rapida: `barcode -> sku -> exact_name -> partial -> entity_search fallback`.
- La capa actual cubre borradores, product resolution, barcode lookup y pricing de linea; checkout/fiscal/inventario siguen como hooks.

## Key classes
- `framework/app/Core/ChatAgent.php`
- `framework/app/Core/POSRepository.php`
- `framework/app/Core/POSService.php`
- `framework/app/Core/POSCommandHandler.php`
- `framework/app/Core/POSMessageParser.php`
- `framework/app/Core/EntitySearchService.php`
- `framework/app/Core/MediaService.php`

## Contracts involved
- `project/contracts/entities/*`
- `project/contracts/invoices/*`
- `framework/contracts/forms/ticket_pos.contract.json`
- `docs/contracts/action_catalog.json`
- `docs/contracts/skills_catalog.json`
- `framework/contracts/schemas/sale_draft.schema.json`

## Notes
- Si agregas flujo POS, preserva el patron compartido actual y el modelo draft-first.
- Resolver productos POS primero por repositorio deterministico y usar `EntitySearchService` solo como fallback seguro o referencia por `product_id`.
- Mantener sincronizados `base_price`, `override_price`, `effective_unit_price`, `line_subtotal`, `line_tax` y `line_total` en cada cambio de linea.
- Barcode en POS no admite fuzzy guessing: si no hay match exacto, devolver `not_found`.
- Si una referencia de producto es ambigua, devolver candidatos; no adivinar producto.
- Mantener hooks ligeros para checkout, caja, fiscal, inventario y ticket; no adelantarlos aqui.
- No crear bypasses especificos de POS fuera del motor.
