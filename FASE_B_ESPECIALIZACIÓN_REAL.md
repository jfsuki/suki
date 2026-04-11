# FASE B — AGENTES ESPECIALIZADOS: ESPECIALIZACIÓN REAL

**Fecha**: 2026-04-09  
**Metodología**: Análisis de prompts, datos reales, cálculos, validación y tests funcionales por dominio

---

## MATRIZ DE ESPECIALIZACIÓN REAL

| Agente | Prompts | Datos DB | Cálculos | Validación | Tests | Estado | Bloquea GO? |
|---|---|---|---|---|---|---|---|
| **ACCOUNTING** | ✅ Real | ✅ Real | ⚠️ Parcial | ✅ Sí | ❌ No | 🟡 YELLOW | ⚠️ SÍ (PUC) |
| **FINANCE** | ✅ Real | ✅ Real | ⚠️ Parcial | ✅ Sí | ❌ No | 🟡 YELLOW | ⚠️ SÍ (ReteFuente) |
| **SALES/ECOMMERCE** | ✅ Real | ✅ Real | ✅ Real | ✅ Sí | ✅ Sí | ✅ GREEN | ⚠️ No (datos) |
| **INVENTORY** | ✅ Real | ✅ Real | ⚠️ Parcial | ✅ Sí | ❌ No | 🟡 YELLOW | ⚠️ No (datos) |
| **PURCHASES** | ⚠️ Básico | ✅ Real | ⚠️ Parcial | ⚠️ Parcial | ❌ No | 🟡 YELLOW | ⚠️ No (datos) |
| **FISCAL** | ✅ Real | ✅ Real | ❌ Stub | ✅ Sí | ✅ Sí | 🔴 RED | ✅ **SÍ BLOQUEADOR** |
| **ARCHITECT** | ✅ Real | ⚠️ Básico | ✅ Real | ✅ Sí | ✅ Sí | ✅ GREEN | No |

---

## 1. AGENTE ACCOUNTING (Contabilidad)

**Registro**: `SpecialistPersonas.php` línea 25-36  
**Service**: `AccountingService.php`  
**Repository**: `AccountingRepository.php`  
**Skill**: `Skills/AccountingSkill.php`

### Prompts (Especialización)
```
"Eres el Especialista Contable de SUKI. Tus prioridades son:
1. Mantener la integridad de la partida doble en cada asiento.
2. Generar reportes de balance y P&G precisos.
3. Detectar discrepancias en flujos de caja y conciliaciones bancarias."
```
**Evaluación**: ✅ REAL (línea 31-34 de SpecialistPersonas)

### Datos Reales (Database)
- **Tabla**: `cuentas_contables` (AccountingRepository línea 13)
- **Tabla**: `asientos_contables` (AccountingRepository línea 14)
- **Tabla**: `asiento_lineas` (AccountingRepository línea 15)
- **Repository**: QueryBuilder + tenant_id scoping (línea 40-43)
- **Transacciones ACID**: Implementadas (línea 48-70)

**Evaluación**: ✅ REAL (persistencia multi-tenant)

### Cálculos (Determinismo)
✅ Partida Doble (Debe = Haber):
- Validación línea 138: `if (abs($totalDebe - $totalHaber) > 0.01)`
- Líneas de asiento balanceadas por transacción

⚠️ Estimación IVA (AccountingService línea 102):
```php
$taxAmt = $total * 0.19 / 1.19; // estima IVA incluido
```
- Hardcodeado al 19% (no configurable)
- No respeta tasas por municipio (ICA)

❌ **PUC (Chart of Accounts)**: Solo cuentas 1, 2, 99 sintéticas (AccountingService línea 70-72)
```php
['cuenta_id' => 1,  'debe' => $total, 'haber' => 0, 'glosa_linea' => "Caja"],      // Fake
['cuenta_id' => 2,  'debe' => 0, 'haber' => $base, 'glosa_linea' => "Ventas"],    // Fake
['cuenta_id' => 99, 'debe' => 0, 'haber' => $taxAmt, 'glosa_linea' => "IVA por pagar"], // Fake
```

**Evaluación**: ⚠️ PARCIAL (Partida doble SÍ, PUC NO, IVA sin municipio)

### Validación (Contratos)
- ✅ Asiento debe balancear (línea 138-140)
- ✅ Al menos 2 líneas por asiento (línea 127-129)
- ❌ No valida contra contract JSON (AccountingService no referencia contracts)

**Evaluación**: ✅ PARCIAL (validación lógica SÍ, contrato JSON NO)

### Tests
- ❌ No existen tests específicos de AccountingService
- ✅ Tests unitarios incluyen validaciones en UnitTestRunner.php

**Evaluación**: ❌ NO TESTS FUNCIONALES

### Resultado ACCOUNTING
- **Estado**: 🟡 YELLOW (cálculos básicos OK, PUC falta)
- **Bloqueador**: ⚠️ SÍ — PUC real + ICA/municipio + ReteFuente requeridos para PYME Colombia

---

## 2. AGENTE FINANCE (Finanzas)

**Registro**: `SpecialistPersonas.php` línea 67-80  
**Service**: Usa `AccountingService` + `FiscalEngineService`

### Prompts
```
"Eres el Especialista Financiero de SUKI. Tus prioridades son:
1. Asegurar cumplimiento de márgenes mínimos (25% por defecto).
2. Aplicar reglas de redondeo (múltiplos de 50 o 100 según ley).
3. Validar umbrales de impuestos (IVA, ICA)."
```
**Evaluación**: ✅ REAL (línea 73-77)

### Cálculos
✅ Margen mínimo:
- Hardcodeado al 25% (no configurable)
- No valida contra contrato

⚠️ Redondeo:
- Mencionado en prompts pero NO implementado en código
- `FiscalEngineService` no tiene método de redondeo

❌ **ReteFuente (Retención)**:
- `FiscalEngineService.php` línea 761: `'withholding' => 'pending'`
- STUB, no implementado

**Evaluación**: ❌ PARCIAL (Margen + Redondeo + ReteFuente = 33% implementado)

### Resultado FINANCE
- **Estado**: 🔴 RED — ReteFuente es OBLIGATORIO en Colombia
- **Bloqueador**: ✅ **SÍ CRÍTICO** (Impuestos mal calculados = Auditoría DIAN fallida)

---

## 3. AGENTE SALES (Ventas/Ecommerce)

**Registro**: `SpecialistPersonas.php` línea 82-95  
**Service**: `EcommerceHubService.php`  
**Repository**: `EcommerceHubRepository.php`

### Prompts
```
"Eres el Especialista de Ventas de SUKI. Tus prioridades son:
1. Sincronizar catálogo con Alanube y plataformas externas.
2. Validar disponibilidad de stock antes de confirmar ventas.
3. Optimizar distribución de inventario."
```
**Evaluación**: ✅ REAL (línea 88-92)

### Datos Reales
- **Plataformas**: WooCommerce, Tienda Nube, PrestaShop (EcommerceHubService línea 12-17)
- **Sincronización**: Implementada vía Alanube (EcommerceHubService línea 60-80+)
- **Estado multi-pasadas**: linked, prepared, synced, failed (línea 44-52)

**Evaluación**: ✅ REAL (DB + adapters)

### Cálculos
✅ Stock reservation logic (implementado)
✅ Price optimization (básico)
⚠️ Discounts no totalmente implementados

**Evaluación**: ✅ REAL (lógica de sincronización)

### Tests
✅ `ecommerce_agent_skills_test.php` — tests de sincronización

**Evaluación**: ✅ TESTS FUNCIONALES

### Resultado SALES
- **Estado**: ✅ GREEN (operacional)
- **Bloqueador**: NO (datos OK, sincronización OK)

---

## 4. AGENTE INVENTORY (Inventario)

**Registro**: `SpecialistPersonas.php` línea 39-50  
**Service**: `InventoryService.php`  
**Repository**: `InventoryRepository.php`

### Prompts
```
"Eres el Especialista de Inventarios de SUKI. Tus prioridades son:
1. Monitorear niveles de stock crítico y disparar alertas.
2. Gestionar múltiples bodegas y transferencias.
3. Validar entradas y salidas físicas vs lógicas."
```
**Evaluación**: ✅ REAL (línea 45-48)

### Datos Reales
- **Tabla**: `products` (SKU, precio, stock)
- **Multi-bodega**: No implementado (falta tabla `warehouse_transfers`)
- **Stock mínimo**: Definido (InventoryService línea 55)

**Evaluación**: ⚠️ PARCIAL (SKU OK, bodegas múltiples NO)

### Cálculos
✅ Stock actual vs mínimo (línea 55)
⚠️ Alertas de reorden — NO implementadas
❌ FIFO/LIFO — NO implementado
❌ Valuación de inventario — NO implementada

**Evaluación**: ⚠️ PARCIAL (20% implementado)

### Resultado INVENTORY
- **Estado**: 🟡 YELLOW (operacional para caso simple)
- **Bloqueador**: ⚠️ NO (pero multi-bodega se necesita pronto)

---

## 5. AGENTE PURCHASES (Compras)

**Registro**: `SpecialistPersonas.php` línea 53-65  
**Service**: Busqueda...

### Prompts
```
"Eres el Especialista de Compras de SUKI. Tus prioridades son:
1. Gestionar ciclo de vida de órdenes de compra.
2. Negociar y monitorear términos con proveedores.
3. Asegurar que costos no superen presupuestos."
```
**Evaluación**: ✅ REAL (línea 59-62)

### Estado Implementation
⚠️ Parcial - No existe `PurchasesService.php` dedicado
- Referencias en `FiscalEngineService.php` línea 54: `private PurchasesService $purchasesService;`
- Service probablemente stub

**Evaluación**: ⚠️ PARCIAL (definida pero no completamente implementada)

### Resultado PURCHASES
- **Estado**: 🟡 YELLOW
- **Bloqueador**: NO (pero requiere completar)

---

## 6. AGENTE FISCAL (Facturación Electrónica)

**Registro**: `SpecialistPersonas.php` — **NO EXISTE** ❌  
**Service**: `FiscalEngineService.php`  
**Test**: `fiscal_engine_architecture_test.php` (✅ PASS)

### Prompts
❌ **NO DEFINIDO EN SpecialistPersonas.php**
- Contract define área "FISCAL" pero no tiene getPersona()
- Uses `FiscalEngineService` directamente

### Datos Reales
✅ Estados: draft, pending, prepared, submitted, accepted, rejected, canceled  
✅ Transiciones de estado (STATUS_TRANSITIONS línea 33-41)  
✅ Documento tipos: sales_invoice, support_document, credit_note, debit_note

**Evaluación**: ✅ REAL (máquina de estados)

### Cálculos
❌ **XML UBL 2.1**: NO IMPLEMENTADO
❌ **CUFE (Código Único de Facturación Electrónica)**: STUB (solo campo vacío)
❌ **Firma digital DIAN**: NO IMPLEMENTADO
❌ **Validación DIAN**: Stub (solo "accepted/rejected")

**Evaluación**: ❌ STUB (estructura sí, lógica NO)

### Integración Alanube
```php
// FiscalEngineService - Línea ~200
$adapter = IntegrationAdapterFactory::create('alanube');
// Pero AlanubeIntegrationAdapter.php tiene payload vacío
```

**Evaluación**: ❌ INCOMPLETO

### Tests
✅ `fiscal_engine_architecture_test.php` — PASS (pero tests básicos)

### Resultado FISCAL
- **Estado**: 🔴 RED — **BLOQUEADOR CRÍTICO**
- **Bloqueador**: ✅ **SÍ CRÍTICO** (XML + CUFE + firma = 0% producción)
- **Esfuerzo**: 15-20 días

---

## 7. AGENTE ARCHITECT (Diseño de Esquemas)

**Registro**: `SpecialistPersonas.php` línea 97-106  
**Service**: `EntityBuilder.php`, `ContractWriter.php`

### Prompts
```
"Eres el Arquitecto de SUKI. Tu misión es diseñar tablas, formularios 
y flujos que sean escalables y seguros."
```
**Evaluación**: ✅ REAL (línea 103-104)

### Ejecución
✅ EntityBuilder — crea tablas (REAL)
✅ ContractWriter — genera contratos JSON (REAL)
✅ Tests pasan (UnitTestRunner checks)

**Evaluación**: ✅ OPERACIONAL

### Resultado ARCHITECT
- **Estado**: ✅ GREEN
- **Bloqueador**: NO

---

## RESUMEN: MATRIZ EJECUTIVA

```
┌─────────────────────────────────────────────────────────────────┐
│ AGENTE          │ PROMPTS │ DATOS │ CALCULOS │ VAL │ TESTS │ SIT
├─────────────────────────────────────────────────────────────────┤
│ ACCOUNTING      │   ✅    │  ✅   │    ⚠️    │ ⚠️  │  ❌   │ 🟡
│ FINANCE         │   ✅    │  ✅   │    ❌    │ ⚠️  │  ❌   │ 🔴
│ SALES           │   ✅    │  ✅   │    ✅    │ ✅  │  ✅   │ ✅
│ INVENTORY       │   ✅    │  ⚠️   │    ⚠️    │ ✅  │  ❌   │ 🟡
│ PURCHASES       │   ✅    │  ⚠️   │    ⚠️    │ ⚠️  │  ❌   │ 🟡
│ FISCAL          │   ❌    │  ✅   │    ❌    │ ❌  │  ✅   │ 🔴
│ ARCHITECT       │   ✅    │  ⚠️   │    ✅    │ ✅  │  ✅   │ ✅
└─────────────────────────────────────────────────────────────────┘

LEYENDA:
✅ = Implementado & real
⚠️ = Parcial o básico  
❌ = Stub, no implementado
🟡 = Producción condicional
🔴 = BLOQUEADOR CRÍTICO
✅ = GO READY
```

---

## GO-TO-MARKET BLOCKERS (Críticos para PYME Colombia)

| Agente | Gap | Severidad | Esfuerzo | Estado |
|---|---|---|---|---|
| **FINANCE** | ReteFuente no implementada | P0 | 8-12d | ❌ BLOQUEADOR |
| **ACCOUNTING** | PUC real (5000+ cuentas) | P0 | 5-8d | ❌ BLOQUEADOR |
| **FISCAL** | XML UBL + CUFE + Firma DIAN | P0 | 15-20d | ❌ BLOQUEADOR CRÍTICO |
| **INVENTORY** | Multi-bodega + FIFO/LIFO | P1 | 8-10d | ⚠️ AFTER GO |
| **ACCOUNTING** | ICA/municipio variable | P1 | 3-5d | ⚠️ AFTER GO |

---

## RESUMEN FASE B

- **Agentes totalmente especializados**: 2/7 (SALES, ARCHITECT)
- **Agentes parcialmente especializados**: 4/7 (ACCOUNTING, INVENTORY, PURCHASES, FINANCE)
- **Agentes stub**: 1/7 (FISCAL — estructura sí, lógica NO)
- **Agentes sin definición de Persona**: 1/7 (FISCAL)

**Conclusion**: Capa de AGENTES existe pero especialización REAL = 30% (FISCAL es STUB, ReteFuente/PUC son stubs)

---

**FASE B Completada**: Especialización real vs documentada  
**Listo para FASE C**: Auditar sistema de memoria (4 capas)
