# Audit - esco-erp6intents-multiSector-contextpack-v1

## Estado general
- Estructura global: consistente con el contrato de ingest (intents + dialogs + emotion + qa).
- Cobertura: alta en intents operativos ERP (factura, asiento, caja/bancos, cliente, item, app create).
- Calidad semantica: buena, con varios casos implicitos y multi-sector.

## Hallazgos de coherencia

### 1) Factura de venta
- `action` esta bien (`ERP_INVOICE_SALES`).
- `slots` estan completos, pero dos reglas deben ser condicionales:
  - `vencimiento`: solo obligatorio cuando `forma_pago=credito`.
  - `prefijo_numeracion`: puede ser autogenerado si el tenant maneja consecutivo automatico.

### 2) Asiento contable
- `action` propuesta (`ERP_ACCOUNTING_JOURNAL`) es coherente para entrenamiento.
- Recomendado en runtime: validar balance (`sum(debito)==sum(credito)`) antes de ejecutar.

### 3) Caja y bancos
- Cobertura fuerte de recaudo/pago explicito e implicito.
- Buena separacion con hard negatives de consulta.
- Recomendado: priorizar `tipo_movimiento` solo si no viene implicito en frase.

### 4) Crear cliente / item
- Cobertura bien distribuida por sectores.
- `APP_ENTITY_CREATE_CUSTOMER` y `APP_ENTITY_CREATE_ITEM` son buenas acciones target para entrenamiento.
- Si en runtime no existen handlers equivalentes, mapear a comandos CRUD canonicos.

### 5) Crear app
- `FRAMEWORK_APP_CREATE` correcto.
- Slots muy completos; recomendable fasear para no frenar onboarding:
  - fase 1: `sector`, `procesos_criticos`, `documentos_criticos`
  - fase 2: resto de slots como enriquecimiento.

### 6) QA cases
- Muy utiles para borde y desambiguacion.
- Casos con `NEEDS_DISAMBIGUATION` correctos; requieren intent/accion de salida uniforme en gateway.

## Riesgos de duplicidad/redundancia
- Repeticion por sector dentro de utterances de varios intents (patron "para sector X ...").
- Esto no es error, pero eleva costo de mantenimiento.
- Mitigacion aplicada: usar contexto comun en `project/contracts/knowledge/training_context_library.json`.

## Incompletos detectados
- Faltaba `context_pack` formal y reusable para pedir datasets con base fiscal/contable consistente.
- Faltaban reglas explicitas de dependencia de slots (ej. vencimiento/prefijo en factura).
- Faltaba lista de sectores de alta probabilidad no cubiertos.

## Completado y guardado
- Se guardo contexto reusable en:
  - `project/contracts/knowledge/training_context_library.json`
- Incluye:
  - catalogo de acciones
  - reglas de dependencia de slots
  - plan de cuentas minimo CO y reglas de contabilizacion
  - mapa de ambiguedad y ruteo recomendado
  - sectores cubiertos y faltantes de alta probabilidad
  - objetivos de calidad para solicitud de nuevos lotes.
- Se guardo lote auditado listo para ingesta:
  - `project/contracts/knowledge/training_dataset_esco_erp6intents_v1.json`
  - validado en estricto con: `--min-explicit=40 --min-implicit=40 --min-hard-negatives=40 --min-dialogues=10 --min-qa=10`

## Siguiente uso recomendado
1) Construir o pegar el lote en JSON.
2) Validar con:
   - `php framework/scripts/validate_training_dataset.php <archivo> --strict --min-explicit=40 --min-implicit=40 --min-hard-negatives=40 --min-dialogues=10 --min-qa=10`
3) Si pasa, integrar por fases en training.
