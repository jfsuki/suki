# POS AGENTS

## Responsibility
- Esta carpeta es una guia local para el dominio POS.
- El runtime POS usa una base compartida y draft-first para preparar venta, precio y contexto operativo antes del checkout.

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
- Resolver productos y clientes via `EntitySearchService` antes de mutar borradores.
- Mantener hooks ligeros para checkout, caja, fiscal, inventario y ticket; no adelantarlos aqui.
- No crear bypasses especificos de POS fuera del motor.
