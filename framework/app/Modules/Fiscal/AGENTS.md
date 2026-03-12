# Fiscal Module

## Responsibility
- Representar documentos fiscales internos compartidos para POS, Purchases y futuras ventas Ecommerce.
- Mantener linkage `source_module + source_entity_type + source_entity_id`.
- Preparar status/eventos fiscales sin integrar todavia proveedor externo.

## Key Classes
- `framework/app/Core/FiscalEngineRepository.php`
- `framework/app/Core/FiscalEngineService.php`
- `framework/app/Core/FiscalEngineCommandHandler.php`
- `framework/app/Core/FiscalEngineMessageParser.php`
- `framework/app/Core/FiscalEngineContractValidator.php`
- `framework/app/Core/FiscalEngineEventLogger.php`

## Contracts
- `framework/contracts/schemas/fiscal_document.schema.json`
- `framework/contracts/schemas/fiscal_document_line.schema.json`
- `framework/contracts/schemas/fiscal_event.schema.json`
- `docs/contracts/action_catalog.json`
- `docs/contracts/skills_catalog.json`

## Working Rules
- Cambios incrementales solamente.
- Reusar source hooks de POS y Purchases; no duplicar flujos de checkout o soporte documental.
- Mantener tenant isolation y status lifecycle controlado.
- Provider integration, XML/UBL, firma, CUFE/CUDE y webhooks quedan fuera de este modulo base.
