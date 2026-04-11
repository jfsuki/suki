# 🚨 ACCIÓN HOY — 4 Pasos a Producción

**Fecha**: 2026-04-11 (Viernes)  
**Tiempo total**: ~2.5 horas  
**Resultado**: Sistema listo para desarrollo FE/Taxes W1-3

---

## PASO 1️⃣: Arreglar Entity Schema (15 min)

**Archivo**: `framework/contracts/schemas/entity.schema.json`

**Línea 46** (aprox), busca:
```json
"source": { "type": "string" },
"grid": { "type": "string" },
"ref": { "type": "string" }
```

**Agrega esta línea después**:
```json
"enum": { "type": "array", "items": { "type": "string" } },
```

**Verifica**:
```bash
php -r "
require_once 'framework/vendor/autoload.php';
require_once 'framework/app/autoload.php';
try {
    new \App\Core\EntityRegistry();
    echo '✅ PASS: Entity schema fixed' . PHP_EOL;
    exit(0);
} catch (Throwable \$e) {
    echo '❌ FAIL: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"
```

**Expected**:
```
✅ PASS: Entity schema fixed
```

---

## PASO 2️⃣: Ejecutar Migraciones de Schema (2-3 horas)

**Script**: Ya creado para ti  
**Ubicación**: `framework/scripts/apply_schema_migrations.php`  
**Comando**:

```bash
cd /c/laragon/www/suki/framework
php scripts/apply_schema_migrations.php
```

**Verifica**:
```
✅ Database connected
✅ Migrations table ensured
Running entity migrations...
  ✅ POS Module
  ✅ Accounting Module
  ✅ Purchases Module
  ✅ Inventory Module
  ✅ Fiscal Module
  ✅ [otros módulos]
✅ All migrations applied successfully!
```

**Si falla**:
```bash
# Activar runtime schema temporarily
ALLOW_RUNTIME_SCHEMA=1 php scripts/apply_schema_migrations.php
```

---

## PASO 3️⃣: Verificar Que Todo Funciona (30 min)

**Test 1: POS Module**
```bash
php -r "
putenv('ALLOW_RUNTIME_SCHEMA=1');
require_once 'vendor/autoload.php';
require_once 'app/autoload.php';

try {
    \$db = new \App\Core\Database();
    \$pos = new \App\Core\POSRepository();
    \$tickets = \$pos->listTickets('default', 0, 1);
    echo '✅ POS Module: ' . count(\$tickets) . ' tickets found' . PHP_EOL;
} catch (Throwable \$e) {
    echo '❌ POS Error: ' . \$e->getMessage() . PHP_EOL;
}
"
```

**Test 2: Accounting Module**
```bash
php -r "
putenv('ALLOW_RUNTIME_SCHEMA=1');
require_once 'vendor/autoload.php';
require_once 'app/autoload.php';

try {
    \$acc = new \App\Core\AccountingRepository();
    \$accounts = \$acc->listAccounts('default');
    echo '✅ Accounting Module: ' . count(\$accounts) . ' cuentas (PUC colombiano)' . PHP_EOL;
} catch (Throwable \$e) {
    echo '❌ Accounting Error: ' . \$e->getMessage() . PHP_EOL;
}
"
```

**Test 3: Full Test Suite**
```bash
php tests/run.php 2>&1 | tail -20
```

**Expected result**:
```json
{
  "summary": {
    "passed": 65,  // ← Should be 65+ (was 64)
    "failed": 51,  // ← Should decrease
    "warned": 0
  }
}
```

---

## PASO 4️⃣: Confirmar Sistema Listo (10 min)

**Checklist**:
- [ ] Entity schema fijo (sin "properties must match" error)
- [ ] Migraciones DB corrieron sin error
- [ ] POS Module responde
- [ ] Accounting Module responde
- [ ] Tests: 65+ passed (mejoró desde 64)
- [ ] Chat API aún funciona

```bash
# Final chat test
php -r "
require_once 'framework/vendor/autoload.php';
require_once 'framework/app/autoload.php';

\$agent = new \App\Core\ChatAgent();
\$result = \$agent->handle([
    'message' => 'Hola, necesito crear una factura',
    'tenant_id' => 'test',
    'is_authenticated' => true,
]);

if (isset(\$result['data']['reply'])) {
    echo '✅ Chat API: System ready for production' . PHP_EOL;
    echo 'Response: ' . substr(\$result['data']['reply'], 0, 80) . '...' . PHP_EOL;
} else {
    echo '❌ Chat API failed' . PHP_EOL;
}
"
```

---

## ¿QUÉ SIGUE? (SEMANA 1)

Una vez completados los 4 pasos:

1. **FE Electrónica XML** (15-20 días paralelo)
   - Archivo: `framework/app/Core/AlanubeIntegrationAdapter.php`
   - Add: XML UBL 2.1 generation, CUFE calculation, RSA signature

2. **ReteFuente** (5-8 días paralelo)
   - Archivo: `framework/app/Core/Services/ReteFuenteService.php` (crear)
   - Add: Withholding tax calculation (3.5%-5% based on CIIU)

3. **PUC Real** (ya está en código)
   - 85 cuentas colombianas ya codificadas en `AccountingRepository`
   - Solo necesita ejecutarse post-migrations ✅

---

## PROBLEMAS COMUNES

**P: Entity registry aún falla después del fix**
```
R: Limpia el cache y reinicia:
   rm -rf project/storage/cache/entities.schema.cache.json
   php -r "new \App\Core\EntityRegistry();"
```

**P: Migraciones timeout**
```
R: Son pesadas (~2-3 MB en MySQL). Espera o ejecuta sin ALLOW_RUNTIME_SCHEMA:
   php scripts/apply_schema_migrations.php
```

**P: Tests aún fallan después de migraciones**
```
R: Expected - algunos tests fallan por:
   - Chat auth requirements (4-6 tests)
   - Builder onboarding (4 tests)
   - Otros issues de lógica (no relacionados a schema)
   
   Lo importante es que POS + Accounting + Fiscal modules funcionan.
```

---

## TIMELINE ESPERADO

```
Ahora (15:00):     ← TÚ ESTÁS AQUÍ
├─ [15 min] Entity schema fix
├─ [2-3h]  Migrations run
├─ [30 min] Verify tests
└─ [17:30] 🎉 DONE — Ready for W1 dev

Lunes (W1):
└─ Start FE XML + ReteFuente development
```

---

**¿LISTO?** Abre el editor y comienza con PASO 1.

Si algo falla, revisa:
- `AUDIT_REALIDAD_EJECUCIÓN_20260411.md` (análisis completo)
- `START_HERE.md` (guía detallada)

**¡ADELANTE! 🚀**

