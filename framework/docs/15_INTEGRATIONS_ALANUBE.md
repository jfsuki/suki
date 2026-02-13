# Alanube Integration (MVP)

## Objetivo
Integrar facturacion electronica via Alanube sin romper compatibilidad, usando contratos y configuracion por pais/env.

## Configuracion
- integration_id: alanube_main
- country: CO (u otro)
- environment: sandbox | production
- base_url: https://sandbox.alanube.co/{country}/v1 o https://api.alanube.co/{country}/v1
- auth: bearer con token en .env (ALANUBE_TOKEN)
- document_type (CO): INVOICE (ajustable segun pais)
- currency: COP (ajustable)
- resolution_id/prefijo: opcional si el proveedor lo exige

## Contratos
- /project/contracts/integrations/*.integration.json
- /project/contracts/invoices/*.invoice.json

## Flujo
1) Crear integracion (wizard) -> guarda integration contract + app.manifest
2) Crear contrato de factura (wizard) -> mapping base (CO) con campos comunes
3) Emitir -> /api/integrations/alanube/emit (sandbox)
4) Webhook -> /api/integrations/alanube/webhook

## Notas
- Alanube usa ULID para id de documentos (guardar como VARCHAR).
- El mapping del invoice es incremental: field:, item:, fixed:, env:.
- Para Colombia: completar document_type, resolution_id, y ajustar mapping segun el payload oficial del proveedor.
- Algunos paises usan subdominio sandbox-api (ver docs CRI). El base_url es editable en el wizard.
- Las rutas de invoice suelen ser /invoices y el metodo de anulacion puede variar (POST /cancel o DELETE /invoices/{id}).
