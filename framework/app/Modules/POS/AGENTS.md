# POS AGENTS

## Responsibility
- Esta carpeta es una guia local para el dominio POS.
- El runtime POS usa una base compartida y draft-first para preparar venta, precio y contexto operativo antes del checkout.
- La resolucion de producto POS debe ser deterministica y rapida: `barcode -> sku -> exact_name -> partial -> entity_search fallback`.
- La capa actual cubre borradores, product resolution, barcode lookup, pricing de linea, finalizacion `draft -> sale`, ticket/receipt preparado, lifecycle de caja POS con arqueo y ajustes operativos por `cancelation` / `return`; fiscal, inventario, pagos complejos y hardware siguen como hooks.

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
- `framework/contracts/schemas/pos_sale.schema.json`
- `framework/contracts/schemas/pos_receipt_payload.schema.json`
- `framework/contracts/schemas/pos_return.schema.json`
- `framework/contracts/schemas/pos_return_receipt_payload.schema.json`
- `framework/contracts/schemas/pos_session.schema.json`
- `framework/contracts/schemas/pos_cash_summary.schema.json`

## Notes
- Si agregas flujo POS, preserva el patron compartido actual y el modelo draft-first.
- Resolver productos POS primero por repositorio deterministico y usar `EntitySearchService` solo como fallback seguro o referencia por `product_id`.
- Mantener sincronizados `base_price`, `override_price`, `effective_unit_price`, `line_subtotal`, `line_tax` y `line_total` en cada cambio de linea.
- Barcode en POS no admite fuzzy guessing: si no hay match exacto, devolver `not_found`.
- Si una referencia de producto es ambigua, devolver candidatos; no adivinar producto.
- El lifecycle actual es `open draft -> checked_out draft + completed sale`.
- Cancelacion POS y devolucion POS no son lo mismo:
  - cancelacion cambia el `status` de la venta a `canceled` y conserva la venta original visible.
  - devolucion crea `pos_return` + `pos_return_line` ligados a la venta original y valida `returned_qty <= sold_qty pendiente`.
- El receipt/ticket se prepara como payload estructurado y texto imprimible; no hay drivers de impresion aqui.
- Los tickets de ajuste usan payload compartido `return|cancelation`; no ejecutar aqui fiscal note, refund posting ni restock automatico.
- Caja POS sigue la regla `un cash session open por cash_register_id + tenant`.
- El arqueo actual es cash-focused: `opening_amount + sales_total -> expected_cash_amount`, luego `counted_cash_amount -> difference_amount`.
- Mantener hooks ligeros para checkout, fiscal, inventario, pagos, ecommerce origin, accounting, refund cash y ticket; no adelantarlos aqui.
- No crear bypasses especificos de POS fuera del motor.
