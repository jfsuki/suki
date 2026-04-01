# Training Data Request Context

## Objetivo
Pedir data ampliada con contexto suficiente para que el lote sea util en produccion, no solo una lista de frases.

## Contexto minimo que debes incluir en cada solicitud de data
- Dominio y pais: tipo de negocio, pais, moneda, regimen fiscal.
- Flujo operativo real: que entra, que se transforma, que sale.
- Documentos oficiales: factura, recibo, nota credito, asiento, orden.
- Reglas contables: cuentas afectadas por evento (debito/credito).
- Politica de pregunta minima: 1 slot critico por turno.
- Fronteras: que SI es la intencion y que NO (hard negatives).
- Canal y estilo: whatsapp/telegram/web, tono no tecnico.
- Calidad esperada: cobertura minima por intent y casos multi-turn.

## Matriz de contexto por tipo de accion
- `factura_venta`
  - datos clave: cliente, items, total, forma de pago, impuestos
  - contabilidad esperada: ingreso + iva + cartera/caja
- `asiento_contable`
  - datos clave: fecha, concepto, lineas debito/credito, soporte
  - control: suma debitos == suma creditos
- `pago_recaudo`
  - datos clave: tercero, monto, medio, referencia documento
  - control: ingreso/salida definido
- `crear_cliente`
  - datos clave: nombre, documento, contacto, condicion comercial
- `crear_producto_servicio`
  - datos clave: nombre, tipo, precio, impuestos, inventariable
- `crear_app`
  - datos clave: sector, procesos, documentos, modulos iniciales

## Prompt extendido recomendado (mismo contrato + mas contexto)
```txt
ROLE
Eres un curador de dataset conversacional ERP para es-CO.

CONTEXT
Negocio: {business_type}
Pais: {country}
Moneda: {currency}
Regimen fiscal: {tax_regime}
Flujos: {document_flows}
Cuentas contables base: {chart_of_accounts_min}
Reglas de contabilizacion: {posting_rules}
Canal de uso: {channels}

INPUT
Genera dataset para intents:
- ERP_FACTURA_VENTA
- ERP_ASIENTO_CONTABLE
- ERP_CAJAYBANCOS
- APP_RUNTIME_CREATE_CUSTOMER
- APP_RUNTIME_CREATE_ITEM
- APP_CREATE

CONSTRAINTS
- Solo JSON valido.
- No inventar campos fuera del esquema.
- Incluir utterances explicitas, implicitas y hard negatives por intent.
- Incluir slots y pregunta minima por slot.
- Incluir dialogos multi-turn de correccion y frustracion.
- Incluir casos QA de clasificacion y accion esperada.

OUTPUT_FORMAT
Usa exactamente el schema `training_dataset_ingest.schema.json`.

FAIL_RULES
Si falta contexto critico: {"status":"NEEDS_CLARIFICATION","missing":"<field>"}.
```

## Criterio de aceptacion del lote
- Valida schema sin errores.
- Sin duplicados ni overlap entre positivos y hard negatives.
- 1 pregunta por slot faltante.
- QA cases alineados con intent/action.
- Validacion estricta:
  - `php framework/scripts/validate_training_dataset.php <archivo> --strict --min-explicit=40 --min-implicit=40 --min-hard-negatives=40 --min-dialogues=10 --min-qa=10`
