# InvoiceContract (MVP)

## Objetivo
Definir el mapeo entre tu factura local (entidad + grids) y el payload del proveedor de facturacion (Alanube).

## Ubicacion
- /project/contracts/invoices/*.invoice.json

## Schema
- /framework/contracts/schemas/invoice.schema.json

## Mapping rules (MVP)
- field:campo -> toma del registro principal
- item:campo -> toma del item de la lista detalle
- fixed:valor -> literal
- env:NOMBRE -> lee variable de entorno

## Ejemplo minimo
```json
{
  "type": "invoice",
  "version": "1.0",
  "provider": "alanube",
  "country": "CO",
  "entity": "facturas",
  "integration_id": "alanube_main",
  "emit_endpoint": "/invoices",
  "mapping": {
    "document": {
      "issue_date": "field:fecha",
      "document_type": "fixed:INVOICE",
      "currency": "fixed:COP"
    },
    "buyer": { "name": "field:cliente", "id": "field:nit" },
    "items": {
      "source_grid": "detalle_items",
      "map": {
        "description": "item:descripcion",
        "quantity": "item:cantidad",
        "unit_price": "item:valor_unitario"
      }
    }
  }
}
```
