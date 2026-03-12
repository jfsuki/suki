# Fiscal Module

## Responsibility
- Representar documentos fiscales internos compartidos para POS, Purchases y futuras ventas Ecommerce.
- Mantener linkage `source_module + source_entity_type + source_entity_id`.
- Preparar status/eventos fiscales sin integrar todavia proveedor externo.
- Construir builders internos para `sales_invoice`, `credit_note` y `support_document`.
- Exponer payloads estructurados para futuro mapeo DIAN/proveedor sin generar XML/UBL todavia.

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
- `framework/contracts/schemas/fiscal_document_payload.schema.json`
- `docs/contracts/action_catalog.json`
- `docs/contracts/skills_catalog.json`

## Working Rules
- Cambios incrementales solamente.
- Reusar source hooks de POS y Purchases; no duplicar flujos de checkout o soporte documental.
- Mantener tenant isolation y status lifecycle controlado.
- Builders internos actuales:
  - `POS sale -> sales_invoice | pos_ticket_fiscal_hook`
  - `POS return | sale canceled -> credit_note`
  - `Purchase -> support_document | purchase_fiscal_hook`
- Antes de crear builders FE, bloquear o reutilizar duplicados activos por `tenant + source + document_type`.
- `buildDocumentPayload()` solo prepara `header + summary + lines + references + metadata`; no envia nada afuera.
- Provider integration, XML/UBL, firma, CUFE/CUDE y webhooks quedan fuera de este modulo base.
