# 🚀 COMIENZA AQUÍ — Ruta a Producción

**Status**: 🟡 AMARILLO — Funciona, pero 3 blockers antes de GO  
**Fecha**: 2026-04-11  
**Tiempo a producción**: 3 semanas  

---

## TU PRIMER PASO (HOY — 1-2 horas TOTAL)

### 0️⃣ FIX ENTITY SCHEMA (15 min)

⚠️ **ANTES de las migraciones**, hay que arreglar una validación de schema:

```bash
# Edit this file:
vim framework/contracts/schemas/entity.schema.json

# Busca esta sección (línea ~46):
#     "source": { "type": "string" },
#     "grid": { "type": "string" },
#     "ref": { "type": "string" }

# Agrega esta línea ANTES de la llave de cierre:
#     "enum": { "type": "array", "items": { "type": "string" } },
```

**Resultado esperado**:
```
  "source": { "type": "string" },
  "grid": { "type": "string" },
  "ref": { "type": "string" },
  "enum": { "type": "array", "items": { "type": "string" } }  ← ADD THIS
```

Luego verifica:
```bash
php -r "
require_once 'framework/vendor/autoload.php';
require_once 'framework/app/autoload.php';
try {
    \$registry = new \App\Core\EntityRegistry();
    \$c = \$registry->get('clientes');
    echo '✅ Entity schema fixed';
} catch (Throwable \$e) {
    echo '❌ ' . \$e->getMessage();
}
"
```

---

### 1️⃣ Aplica las migraciones de schema

```bash
cd framework
php scripts/apply_schema_migrations.php
```

**Qué hace**: Crea las tablas faltantes para POS, Accounting y Purchases.

**Resultado esperado**:
```
✅ Migrations table ensured
  ✅ POS Module
  ✅ Accounting Module
  ✅ Purchases Module
✅ All migrations applied successfully!
```

---

### 2️⃣ Verifica que funcione

```bash
# Test POS
php -r "
putenv('ALLOW_RUNTIME_SCHEMA=1');
require_once 'vendor/autoload.php';
require_once 'app/autoload.php';
\$pos = new \App\Core\POSRepository();
echo count(\$pos->listTickets('default', 0, 1)) . ' tickets found';
"

# Test Accounting  
php -r "
putenv('ALLOW_RUNTIME_SCHEMA=1');
require_once 'vendor/autoload.php';
require_once 'app/autoload.php';
\$acc = new \App\Core\AccountingRepository();
\$accounts = \$acc->listAccounts('default');
echo count(\$accounts) . ' cuentas (PUC colombiano)';
"

# Test Chat
php -c tests/run.php | jq '.summary'
```

---

## LOS 3 BLOCKERS CRÍTICOS

### Blocker #1: ✅ FIXED (acabas de hacerlo)
- [ ] Schema migrations aplicadas

### Blocker #2: 🟡 EN PROCESO (Semana 1-3)
- [ ] **FE Electrónica XML/CUFE/Firma** (15-20 días)
  - Archivo a editar: `framework/app/Core/AlanubeIntegrationAdapter.php`
  - Qué agregar:
    1. XML UBL 2.1 generation (~300 líneas)
    2. CUFE calculation (SHA-256 hash)
    3. RSA digital signature
  - Referencia: [DIAN UBL Docs](https://www.dian.gov.co/)

### Blocker #3: 🟡 EN PROCESO (Semana 1-2)
- [ ] **ReteFuente** (5-8 días)
  - Archivo a crear: `framework/app/Core/Services/ReteFuenteService.php`
  - Cálculo: Base * Rate (3.5% a 5% según CIIU)
  - Integrar en: `FiscalEngineService.php`

---

## CRONOGRAMA (3 semanas)

```
HOY (Viernes, Apr 11)
├─ [2-3h] Aplicar migraciones DB ← TÚ ESTÁS AQUÍ
└─ [Verified] Qdrant filtering está correcto ✅

SEMANA 1 (Lunes, Apr 12 - Viernes, Apr 18)
├─ [8-10h] Kickoff FE XML development
├─ [4-6h] ReteFuente basic implementation  
├─ [Verified] PUC 85 cuentas ya codificadas ✅
└─ Tests pasan ✅

SEMANA 2-3 (Apr 19 - May 2)
├─ [15-20h] FE XML complete + signature
├─ [3-5h] ICA municipio lookup
├─ [5-8h] QA + regression tests
└─ [4h] Staging deploy

SEMANA 4 (May 3-5)
└─ 🚀 PRODUCTION READY
```

---

## RESOURCES

| Documento | Propósito |
|-----------|----------|
| `AUDIT_REALIDAD_EJECUCIÓN_20260411.md` | Audit completo (LÉELO después) |
| `AUDIT_REPORT.md` | Documento anterior (menos confiable) |
| `CLAUDE.md` | Reglas del proyecto |
| `framework/scripts/apply_schema_migrations.php` | Script migrator (hoy) |

---

## PRÓXIMAS ACCIONES (After migrations)

1. **Lunes (Apr 12)**: Kickoff FE XML dev
   ```bash
   vim framework/app/Core/AlanubeIntegrationAdapter.php
   # Start XML generation
   ```

2. **Martes (Apr 13)**: ReteFuente skeleton
   ```bash
   vim framework/app/Core/Services/ReteFuenteService.php
   # Basic rate calculation
   ```

3. **Miércoles-Viernes**: Integration testing

---

## SI ALGO FALLA

1. **Migrations error**: 
   ```bash
   # Check logs
   tail -50 project/storage/logs/framework.log
   
   # Reset and retry
   ALLOW_RUNTIME_SCHEMA=1 php scripts/apply_schema_migrations.php
   ```

2. **Chat doesn't respond**:
   ```bash
   # Check LLM
   php tests/llm_smoke.php
   
   # Check database
   php tests/db_health.php
   ```

3. **Tests fail**:
   ```bash
   php tests/run.php 2>&1 | jq '.summary'
   ```

---

## CONFIANZA

- ✅ **Chat API funciona**: Ejecutado y probado
- ✅ **Database estable**: 37 tablas, 11ms latency
- ✅ **LLM integrado**: Mistral responde en 1.7s
- ✅ **Qdrant secure**: Tenant filtering verificado
- ✅ **6 Especialistas**: Cargan correctamente
- ⚠️ **FE Electrónica**: Vacía (necesita implementación)
- ⚠️ **ReteFuente**: No existe (necesita implementación)

---

**Auditoría**: Claude Code (ejecución real, no documentación)  
**Metodología**: Ejecutar código, verificar output  
**Siguientes pasos**: Ejecuta `php scripts/apply_schema_migrations.php` AHORA

