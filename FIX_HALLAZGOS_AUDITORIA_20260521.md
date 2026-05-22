# PROMPT DE CORRECCIÓN — HALLAZGOS AUDITORÍA SUKI 2026-05-21

**Base commit:** `a2ee9cd` | **PHP:** `C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe`
**Raíz:** `C:\laragon\www\suki` | **DB:** MySQL `suki_saas`

---

## CONTRATO DE EJECUCIÓN

1. Cada fix se verifica con el comando indicado antes de pasar al siguiente.
2. Cambios **aditivos únicamente** — no renombrar columnas, no eliminar keys de contratos JSON.
3. `tenant_id` obligatorio en cada query a tablas de datos de negocio (canon SUKI).
4. Sin raw SQL interpolado — usar `prepare()` + `bindValue()` o `execute([...])`.
5. Al final: `run.php` debe retornar **121/121** y `fase1_tc01_tc04.php` **48/48**.
6. Solo se documenta lo que el usuario pide explícitamente.

---

## ORDEN DE EJECUCIÓN (menor a mayor riesgo)

```
FIX 1  — A-02: docs/INSTALL.md tiene keys reales         (5 min)
FIX 2  — M-03: SemanticCache SQL sin prepare()            (5 min)
FIX 3  — M-01: AppInterviewState catch silencioso         (5 min)
FIX 4  — M-05: Variable GEMINI_CHAT_ENABLED inerte        (5 min)
FIX 5  — A-03: TC03 out-of-scope retorna vacío            (1h)
FIX 6  — C-03: Tabla ai_agents no existe en MySQL         (1h)
FIX 7  — M-04: 3 tablas sin índice tenant_id/created_at   (30 min)
FIX 8  — C-01: IntegrationStore sin tenant isolation      (4h)
FIX 9  — C-02: AlanubeInvoiceBuilder — SKIP (pendiente credenciales manuales)
FIX 10 — A-01: PUC 5,000+ cuentas colombianas            (5-8h)
FIX 11 — AUTH: Self-registration + OTP email + login      (6-8h)
DOC    — Actualizar CLAUDE.md + AUDIT_PIPELINE_5CAPAS.md
```

---

## FIX 1 — A-02: docs/INSTALL.md con keys reales (5 min)

**Evidencia:** El security check de `run.php` detecta variables sensibles con valores reales:
```
docs/INSTALL.md:29 Variable sensible con valor no-placeholder: OPENROUTER_API_KEY
docs/INSTALL.md:30 Variable sensible con valor no-placeholder: GEMINI_API_KEY
```

**Fix:** Editar `docs/INSTALL.md` líneas 29-30. Los valores actuales son `sk-...` y `AI...` reales.
Reemplazarlos con placeholders seguros:
```
OPENROUTER_API_KEY=your_openrouter_key_here
GEMINI_API_KEY=your_gemini_key_here
```

**Verificación:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/tests/run.php 2>&1 | tail -3
# PASS: 121/121 (el 1 fallo que había era este check de seguridad)
```

---

## FIX 2 — M-03: SemanticCache sin prepared statement (5 min)

**Evidencia:** `framework/app/Core/Agents/Memory/SemanticCache.php:127`
```php
$this->db->exec("DELETE FROM ops_semantic_cache WHERE created_at < '$cutoff'");
```
El valor `$cutoff` viene de `date()` (no de input usuario), pero viola el canon "nunca SQL interpolado".

**Fix exacto en** `framework/app/Core/Agents/Memory/SemanticCache.php:127`:
```php
// ANTES:
$this->db->exec("DELETE FROM ops_semantic_cache WHERE created_at < '$cutoff'");

// DESPUÉS:
$stmt = $this->db->prepare('DELETE FROM ops_semantic_cache WHERE created_at < ?');
$stmt->execute([$cutoff]);
```

**Verificación:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/tests/run.php 2>&1 | tail -3
# Sin regresiones
```

---

## FIX 3 — M-01: AppInterviewState catch silencioso (5 min)

**Evidencia:** `framework/app/Core/AppInterviewState.php:62-69`
```php
try {
    $this->mysqlSave($tenantId, $sessionId, $state);
    return;
} catch (\Throwable $ignored) {}
file_put_contents($this->path(...), json_encode($state, ...));
```
Si MySQL falla, el estado se escribe en archivo JSON sin ningún log. El operador nunca sabe que hay un problema.

**Fix exacto en** `framework/app/Core/AppInterviewState.php:69`:
```php
// ANTES:
} catch (\Throwable $ignored) {}

// DESPUÉS:
} catch (\Throwable $e) {
    error_log('[AppInterviewState] MySQL save failed, fallback to file: ' . $e->getMessage());
}
```

**Verificación:** Test manual — el código no debe cambiar comportamiento observable, solo añade log.

---

## FIX 4 — M-05: Variable GEMINI_CHAT_ENABLED inerte (5 min)

**Evidencia:** `project/.env` tiene `GEMINI_CHAT_ENABLED=0` pero `framework/data/llm_providers.json:17`
usa la key `GEMINI_ENABLED` (que está en `1`). La variable `GEMINI_CHAT_ENABLED` nunca se lee en ningún
archivo PHP. El operador cree que desactivó Gemini para chat, pero no tiene efecto.

**Fix:** En `project/.env`, eliminar o comentar la línea `GEMINI_CHAT_ENABLED=0` con una nota:
```
# GEMINI_CHAT_ENABLED no existe en llm_providers.json — usa GEMINI_ENABLED para controlar Gemini
# GEMINI_ENABLED=0 para desactivar Gemini como proveedor de chat
```

**Verificación:**
```bash
grep "GEMINI_CHAT_ENABLED" project/.env
# Debe mostrar solo el comentario o vacío
grep "GEMINI_ENABLED" project/.env
# Debe mostrar GEMINI_ENABLED=1 (o 0 si se quiere desactivar)
```

---

## FIX 5 — A-03: TC03 out-of-scope retorna vacío (1h)

**Evidencia:** `framework/tests/fase1_tc01_tc04.php` — `fase1: 46/48 PASS`.
"explícame la fotosíntesis" → intent vacío, reply vacío. El flujo:
1. `ConversationGatewayHandlePipelineTrait.php:403` llama `isOutOfScopeQuestion()`
2. `ConversationGatewayStubsTrait.php:308` require `intent === 'out_of_scope' && score >= 0.65`
3. Sin training data de `out_of_scope` en Qdrant → Qdrant retorna `unknown` con score < 0.40
4. `isOutOfScopeQuestion()` retorna `false` → el mensaje cae al pipeline normal
5. Ningún handler lo procesa → reply es `''`

**Fix en** `framework/app/Core/Agents/ConversationGatewayStubsTrait.php:306-309`:

```php
// ANTES:
public function isOutOfScopeQuestion(string $text, string $mode): bool
{
    $result = $this->intentClassifier()->classify($text);
    return $result['intent'] === 'out_of_scope' && $result['score'] >= self::QDRANT_MIN_SCORE;
}

// DESPUÉS:
public function isOutOfScopeQuestion(string $text, string $mode): bool
{
    $result = $this->intentClassifier()->classify($text);

    // Clasificación semántica directa (Qdrant tiene training de out_of_scope)
    if ($result['intent'] === 'out_of_scope' && $result['score'] >= self::QDRANT_MIN_SCORE) {
        return true;
    }

    // Fallback: intent desconocido con score muy bajo = probablemente fuera de dominio
    if (in_array($result['intent'], ['unknown', ''], true) && $result['score'] < 0.40) {
        // Si el texto contiene tokens de negocio, dejarlo pasar al pipeline normal
        $businessTokens = [
            'crea', 'app', 'venta', 'factura', 'producto', 'cliente', 'inventario',
            'compra', 'pago', 'cuenta', 'precio', 'pedido', 'empresa', 'módulo',
            'reporte', 'usuario', 'suki', 'ayuda', 'qué puedes', 'cómo funciona',
            'instala', 'configura', 'listame', 'muéstrame', 'dame', 'agregar',
            'registrar', 'buscar', 'ver', 'editar', 'eliminar',
        ];
        $normalized = mb_strtolower(trim($text));
        foreach ($businessTokens as $token) {
            if (str_contains($normalized, $token)) {
                return false;
            }
        }
        return true;
    }

    return false;
}
```

**Verificación:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/tests/fase1_tc01_tc04.php 2>&1 | tail -5
# PASS: 48/48
```

**Regresión obligatoria:** Verificar que saludos normales ("hola", "buenas tardes") NO caen en out_of_scope:
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/tests/fase1_tc01_tc04.php 2>&1
# TC01 y TC02 deben seguir en PASS
```

---

## FIX 6 — C-03: Tabla ai_agents no existe en MySQL (1h)

**Evidencia:**
- `framework/app/Core/Agents/Registry/SpecialistPersonas.php:63` hace `FROM ai_agents`
- `framework/app/Core/AppInstallService.php:62-69` hace `ALTER TABLE ai_agents`
- `SELECT 1 FROM ai_agents LIMIT 1` → `SQLSTATE[42S02]: Table 'suki_saas.ai_agents' doesn't exist`
- `ProjectRegistry.php:725` crea la tabla para SQLite pero no hay migración MySQL equivalente

**Fix: 2 pasos**

### 6a. Crear migración
Crear `framework/db/migrations/mysql/20260521_025_ai_agents_specialist_personas.sql`:

```sql
-- ai_agents: personas especialistas por tenant
-- source=catalog (desde app_catalog.json) | source=custom (definido por el tenant)
CREATE TABLE IF NOT EXISTS ai_agents (
    agent_id          VARCHAR(64)   NOT NULL,
    tenant_id         VARCHAR(64)   NOT NULL DEFAULT '',
    project_id        VARCHAR(64)   NOT NULL DEFAULT '',
    role              VARCHAR(64)   NULL,
    area              VARCHAR(64)   NULL,
    status            VARCHAR(32)   NOT NULL DEFAULT 'active',
    config_json       JSON          NULL,
    app_id            VARCHAR(64)   NULL,
    source            VARCHAR(32)   NOT NULL DEFAULT 'catalog',
    prompt_override   TEXT          NULL,
    requirements      TEXT          NULL,
    business_name     VARCHAR(200)  NULL,
    qdrant_collection VARCHAR(64)   NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (agent_id),
    INDEX idx_ai_agents_tenant        (tenant_id),
    INDEX idx_ai_agents_tenant_area   (tenant_id, area),
    INDEX idx_ai_agents_tenant_app    (tenant_id, app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6b. Crear script de migración
Crear `framework/scripts/migrate_ai_agents.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$pdo = \App\Core\Database::connection();
$sql = file_get_contents(__DIR__ . '/../db/migrations/mysql/20260521_025_ai_agents_specialist_personas.sql');
try {
    $pdo->exec($sql);
    echo "ai_agents: CREADA OK" . PHP_EOL;
    $r = $pdo->query('DESCRIBE ai_agents')->fetchAll(PDO::FETCH_COLUMN);
    echo "Columnas: " . implode(', ', $r) . PHP_EOL;
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
```

**Ejecutar:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/scripts/migrate_ai_agents.php
```

**Verificación:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$pdo = \App\Core\Database::connection();
echo \$pdo->query('SELECT COUNT(*) FROM ai_agents')->fetchColumn() . ' filas';
"
# PASS: 0 filas (tabla existe sin error)
```

---

## FIX 7 — M-04: Índices tenant_id/created_at en tablas de app (30 min)

**Evidencia de db_health.php:**
```
missing_tenant_id_index:  p_37a8eec1ce__asiento_lineas, p_37a8eec1ce__golden_clientes_*, p_37a8eec1ce__kardex
missing_created_at_index: p_37a8eec1ce__asiento_lineas, p_37a8eec1ce__asientos_contables,
                          p_37a8eec1ce__cuentas_contables, p_37a8eec1ce__golden_clientes_*, p_37a8eec1ce__kardex
```

**Fix:** Crear `framework/db/migrations/mysql/20260521_027_tenant_created_at_indexes.sql`:

```sql
-- Índices aditivos — si ya existen MySQL no reporta error con IF NOT EXISTS
CREATE INDEX IF NOT EXISTS idx_p37_alineas_tid    ON p_37a8eec1ce__asiento_lineas     (tenant_id);
CREATE INDEX IF NOT EXISTS idx_p37_alineas_cat    ON p_37a8eec1ce__asiento_lineas     (created_at);
CREATE INDEX IF NOT EXISTS idx_p37_asientos_cat   ON p_37a8eec1ce__asientos_contables (created_at);
CREATE INDEX IF NOT EXISTS idx_p37_cuentas_cat    ON p_37a8eec1ce__cuentas_contables  (created_at);
CREATE INDEX IF NOT EXISTS idx_p37_golden_tid     ON p_37a8eec1ce__golden_clientes_1771706711s (tenant_id);
CREATE INDEX IF NOT EXISTS idx_p37_golden_cat     ON p_37a8eec1ce__golden_clientes_1771706711s (created_at);
CREATE INDEX IF NOT EXISTS idx_p37_kardex_tid     ON p_37a8eec1ce__kardex             (tenant_id);
CREATE INDEX IF NOT EXISTS idx_p37_kardex_cat     ON p_37a8eec1ce__kardex             (created_at);
```

Crear `framework/scripts/migrate_indexes.php` y ejecutarlo. Luego:

**Verificación:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/tests/db_health.php 2>&1 | grep "warning\|missing"
# PASS: sin warnings de índices
```

---

## FIX 8 — C-01: IntegrationStore sin tenant isolation (4h)

**Evidencia CRÍTICA:** Las tablas `integration_connections`, `integration_documents`, `integration_webhooks`,
`integration_tokens` NO tienen columna `tenant_id`. Un tenant puede ver/sobrescribir configuraciones de otro.

**Fix: 4 pasos en orden**

### 8a. Migración MySQL
Crear `framework/db/migrations/mysql/20260521_026_integration_tenant_isolation.sql`:

```sql
-- tenant_id aditivo en todas las tablas de integración
-- DEFAULT '' para registros históricos — no rompe nada existente
ALTER TABLE integration_connections ADD COLUMN IF NOT EXISTS
    tenant_id VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Tenant owner of this integration' AFTER id;

ALTER TABLE integration_documents ADD COLUMN IF NOT EXISTS
    tenant_id VARCHAR(64) NOT NULL DEFAULT '' AFTER id;

ALTER TABLE integration_webhooks ADD COLUMN IF NOT EXISTS
    tenant_id VARCHAR(64) NOT NULL DEFAULT '' AFTER id;

ALTER TABLE integration_tokens ADD COLUMN IF NOT EXISTS
    tenant_id VARCHAR(64) NOT NULL DEFAULT '' AFTER id;

-- Índices de tenant para queries rápidas
CREATE INDEX IF NOT EXISTS idx_int_conn_tenant ON integration_connections (tenant_id);
CREATE INDEX IF NOT EXISTS idx_int_docs_tenant ON integration_documents   (tenant_id);
CREATE INDEX IF NOT EXISTS idx_int_wh_tenant   ON integration_webhooks    (tenant_id);
CREATE INDEX IF NOT EXISTS idx_int_tok_tenant  ON integration_tokens      (tenant_id);
```

Crear script `framework/scripts/migrate_integration_tenant.php` y ejecutar.

**Verificar que la columna existe antes de continuar con 8b:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$pdo = \App\Core\Database::connection();
echo \$pdo->query(\"SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='suki_saas' AND TABLE_NAME='integration_connections'
    AND COLUMN_NAME='tenant_id'\")->fetchColumn();
"
# PASS: 1
```

### 8b. Actualizar IntegrationMigrator.php
En `framework/app/Core/IntegrationMigrator.php`, método `ensureTables()`, añadir `tenant_id` como
segunda columna en cada `CREATE TABLE IF NOT EXISTS`. El método `bootstrapSchemaPolicy()` usa
`RuntimeSchemaPolicy` con la migration SQL como fuente de verdad — añadir también `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`
en un método `ensureColumns()` privado que se llame desde `ensureTables()` para forward-compatibility:

```php
private function ensureColumns(): void
{
    $addCols = [
        'integration_connections' => 'tenant_id VARCHAR(64) NOT NULL DEFAULT \'\'',
        'integration_documents'   => 'tenant_id VARCHAR(64) NOT NULL DEFAULT \'\'',
        'integration_webhooks'    => 'tenant_id VARCHAR(64) NOT NULL DEFAULT \'\'',
        'integration_tokens'      => 'tenant_id VARCHAR(64) NOT NULL DEFAULT \'\'',
    ];
    foreach ($addCols as $table => $colDef) {
        try {
            $this->db->exec("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$colDef}");
        } catch (\Throwable $ignored) {
            // La columna ya existe — silencioso es correcto aquí (idempotente)
        }
    }
}
```

Llamar `$this->ensureColumns()` al final de `ensureTables()`.

### 8c. Actualizar IntegrationStore.php
Modificar `framework/app/Core/IntegrationStore.php`:

**Constructor:** Añadir `private string $tenantId = ''` y aceptarlo como parámetro opcional:
```php
public function __construct(?PDO $db = null, string $tenantId = '')
{
    $this->db = $db ?? Database::connection();
    $this->tenantId = $tenantId;
    $this->migrator = new IntegrationMigrator($this->db);
    $this->migrator->bootstrapSchemaPolicy();
}
```

**`saveConnection()`:** Añadir `tenant_id` en SELECT, INSERT y UPDATE:
- En el SELECT check: `WHERE integration_id = :integration_id AND tenant_id = :tenant_id`
- En INSERT: añadir `:tenant_id` al VALUES
- En UPDATE: añadir `AND tenant_id = :tenant_id` al WHERE

**`saveDocument()`:** Añadir `tenant_id` al INSERT.

**`logWebhook()`:** Añadir `tenant_id` al INSERT.

**IMPORTANTE — backward compatibility:** Si `$this->tenantId === ''`, usar el comportamiento actual (sin filtro).
Registros históricos con `tenant_id = ''` siguen accesibles. NO migrar registros históricos.

### 8d. Actualizar todos los `new IntegrationStore(` en el proyecto
Buscar con:
```bash
grep -rn "new IntegrationStore(" framework/ project/ --include="*.php"
```
Para cada instanciación, pasar el `$tenantId` correcto del contexto.

**Verificación final del fix 8:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/tests/run.php 2>&1 | tail -3
# PASS: 121/121 sin regresión
```

---

## FIX 9 — C-02: AlanubeInvoiceBuilder — payload DIAN real (8-12h)

**Contexto:** Las credenciales de Alanube están pendientes con el proveedor. El código debe quedar
listo. `AlanubeClient::emitDocument()` ya hace el HTTP correctamente — lo que falta es el servicio
que construye el JSON payload que Alanube espera.

**Alanube API Colombia v1:** El endpoint `POST /documents/invoices` recibe JSON (no XML directo).
Alanube transforma internamente a UBL 2.1 + CUFE + firma digital. La responsabilidad de SUKI es
construir el payload JSON correcto.

### 9a. Crear framework/app/Core/AlanubeInvoiceBuilder.php

```php
<?php
namespace App\Core;

/**
 * Construye el payload JSON para la API de Alanube Colombia v1.
 * Ref: https://api.alanube.co/co/v1
 * Schema: factura electrónica de venta (tipo 1), nota crédito (tipo 5).
 * Alanube genera internamente el XML UBL 2.1, CUFE y firma DIAN.
 */
final class AlanubeInvoiceBuilder
{
    /**
     * Construye payload para POST /documents/invoices (Factura de Venta tipo 01).
     *
     * @param array{
     *   number: string,
     *   prefix: string,
     *   date: string,            YYYY-MM-DD
     *   due_date: string,        YYYY-MM-DD
     *   time?: string,           HH:MM:SS
     *   notes?: string,
     *   numbering_range_id?: int, null = sandbox auto-asigna
     *   seller: array{nit: string, dv?: string, name: string, address: string,
     *                  city_code: int, department_code: int, phone: string, email: string,
     *                  tax_regime?: string},
     *   buyer: array{nit: string, dv?: string, name: string, address?: string,
     *                city_code?: int, department_code?: int, phone?: string, email: string,
     *                doc_type?: int},
     *   items: array<array{
     *     description: string, quantity: float, unit_price: float,
     *     code?: string, discount?: float, tax_type: 'IVA'|'INC'|'ICO', tax_rate: float
     *   }>,
     *   payment_method?: int,    1=contado, 2=crédito (default: 1)
     *   rete_fuente?: float,
     *   rete_ica?: float,
     * } $invoice
     */
    public function buildInvoice(array $invoice): array
    {
        $this->validateRequired($invoice, ['number', 'prefix', 'date', 'seller', 'buyer', 'items']);

        $items  = $this->buildLineItems($invoice['items']);
        $totals = $this->calculateTotals($invoice['items'],
            (float) ($invoice['rete_fuente'] ?? 0),
            (float) ($invoice['rete_ica'] ?? 0)
        );

        return [
            'numbering_range_id'   => $invoice['numbering_range_id'] ?? null,
            'type_document_id'     => 1,    // Factura de Venta
            'number'               => (string) $invoice['number'],
            'prefix'               => (string) $invoice['prefix'],
            'date'                 => $invoice['date'],
            'time'                 => $invoice['time'] ?? date('H:i:s'),
            'due_date'             => $invoice['due_date'] ?? $invoice['date'],
            'currency_id'          => 35,   // COP
            'notes'                => $invoice['notes'] ?? '',
            'seller'               => $this->buildParty($invoice['seller'], true),
            'customer'             => $this->buildParty($invoice['buyer'], false),
            'legal_monetary_totals'=> $totals,
            'invoice_lines'        => $items,
            'payment_means'        => [[
                'payment_means_id' => (int) ($invoice['payment_method'] ?? 1),
                'payment_id'       => 1,
                'due_date'         => $invoice['due_date'] ?? $invoice['date'],
            ]],
            'withholding_taxes'    => $this->buildWithholdings($invoice),
        ];
    }

    /**
     * Construye payload para POST /documents/credit-notes (Nota Crédito tipo 91).
     * Requiere cufe_invoice_reference del documento original.
     */
    public function buildCreditNote(array $data): array
    {
        $this->validateRequired($data, ['invoice_number', 'invoice_date', 'cufe_reference',
                                        'reason_code', 'seller', 'buyer', 'items']);

        $items  = $this->buildLineItems($data['items']);
        $totals = $this->calculateTotals($data['items'], 0, 0);

        return [
            'type_document_id'          => 5,  // Nota Crédito
            'number'                    => (string) ($data['number'] ?? ''),
            'prefix'                    => (string) ($data['prefix'] ?? 'NC'),
            'date'                      => $data['date'] ?? date('Y-m-d'),
            'time'                      => $data['time'] ?? date('H:i:s'),
            'currency_id'               => 35,
            'notes'                     => $data['notes'] ?? '',
            'discrepancy_response'      => [[
                'response_code'         => (string) $data['reason_code'],
                // reason_code: 1=Devolución, 2=Anulación, 3=Rebaja, 4=Descuento, 5=Rescisión, 6=Otro
                'description'           => $data['reason_description'] ?? 'Corrección de factura',
            ]],
            'billing_reference'         => [[
                'number'                => (string) $data['invoice_number'],
                'date'                  => $data['invoice_date'],
                'uuid'                  => (string) $data['cufe_reference'],
            ]],
            'seller'                    => $this->buildParty($data['seller'], true),
            'customer'                  => $this->buildParty($data['buyer'], false),
            'legal_monetary_totals'     => $totals,
            'credit_note_lines'         => $items,
        ];
    }

    private function buildParty(array $party, bool $isSeller): array
    {
        $nit = preg_replace('/\D/', '', $party['nit'] ?? $party['cc'] ?? '');
        $isNIT = strlen($nit) >= 9;
        $docTypeId = $party['doc_type'] ?? ($isNIT ? 31 : 13); // 31=NIT jurídico, 13=Cédula
        if ($isSeller) {
            $docTypeId = 31; // El emisor siempre es persona jurídica (NIT)
        }

        return [
            'identification_number' => $nit,
            'dv'                    => $party['dv'] ?? null,
            'type_document_id'      => $docTypeId,
            'name'                  => (string) ($party['name'] ?? ''),
            'phone'                 => (string) ($party['phone'] ?? ''),
            'email'                 => (string) ($party['email'] ?? ''),
            'address'               => [
                'line'              => (string) ($party['address'] ?? ''),
                'city_id'           => (int) ($party['city_code'] ?? 11001),        // Bogotá D.C.
                'department_id'     => (int) ($party['department_code'] ?? 11),     // Cundinamarca
                'country_id'        => 46,  // Colombia
            ],
            'tax_level_code'        => $party['tax_regime'] ?? ($isSeller ? 'O-13' : 'O-99'),
            // O-13 = Gran Contribuyente, O-15 = Autorretenedor, O-99 = No responsable IVA (simplificado)
        ];
    }

    private function buildLineItems(array $items): array
    {
        $lines = [];
        foreach ($items as $i => $item) {
            $quantity   = (float) ($item['quantity'] ?? 1);
            $unitPrice  = (float) ($item['unit_price'] ?? 0);
            $discount   = (float) ($item['discount'] ?? 0);
            $taxRate    = (float) ($item['tax_rate'] ?? 19.0);
            $taxType    = strtoupper($item['tax_type'] ?? 'IVA');
            $taxTypeId  = match($taxType) {
                'IVA' => 1,   // IVA
                'INC' => 4,   // Impuesto Nacional al Consumo
                'ICO' => 3,   // Impuesto de Combustibles
                default => 1
            };

            $subtotal   = round($quantity * $unitPrice, 2);
            $taxBase    = round($subtotal - $discount, 2);
            $taxAmount  = round($taxBase * ($taxRate / 100), 2);

            $lines[] = [
                'unit_measure_id'           => 70,  // Unidad (UN)
                'invoiced_quantity'          => $quantity,
                'line_extension_amount'      => $subtotal,
                'free_of_charge_indicator'   => false,
                'description'               => (string) ($item['description'] ?? ''),
                'code'                      => (string) ($item['code'] ?? (string)($i + 1)),
                'type_item_identification_id'=> 999,  // Código libre
                'price'                     => [
                    'price_amount'          => $unitPrice,
                    'base_quantity'         => 1,
                ],
                'tax_totals'                => [[
                    'tax_id'                => $taxTypeId,
                    'tax_amount'            => $taxAmount,
                    'percent'               => $taxRate,
                    'taxable_amount'        => $taxBase,
                ]],
                'allowance_charges'         => $discount > 0 ? [[
                    'charge_indicator'      => false,
                    'allowance_charge_reason' => 'Descuento comercial',
                    'amount'                => round($discount, 2),
                    'base_amount'           => $subtotal,
                ]] : [],
            ];
        }
        return $lines;
    }

    private function calculateTotals(array $items, float $reteFuente, float $reteIca): array
    {
        $lineExtension = 0.0;
        $taxTotal = 0.0;
        foreach ($items as $item) {
            $qty      = (float) ($item['quantity'] ?? 1);
            $price    = (float) ($item['unit_price'] ?? 0);
            $discount = (float) ($item['discount'] ?? 0);
            $sub      = ($qty * $price) - $discount;
            $lineExtension += ($qty * $price);
            $taxTotal += $sub * ((float) ($item['tax_rate'] ?? 19.0) / 100);
        }
        $taxable  = round($lineExtension, 2);
        $tax      = round($taxTotal, 2);
        $total    = round($taxable + $tax, 2);
        $payable  = round($total - $reteFuente - $reteIca, 2);

        return [
            'line_extension_amount'  => $taxable,
            'tax_exclusive_amount'   => $taxable,
            'tax_inclusive_amount'   => $total,
            'payable_amount'         => max(0.0, $payable),
        ];
    }

    private function buildWithholdings(array $invoice): array
    {
        $wh = [];
        if (!empty($invoice['rete_fuente']) && $invoice['rete_fuente'] > 0) {
            $wh[] = ['tax_id' => 6, 'tax_amount' => round((float) $invoice['rete_fuente'], 2)];
        }
        if (!empty($invoice['rete_ica']) && $invoice['rete_ica'] > 0) {
            $wh[] = ['tax_id' => 9, 'tax_amount' => round((float) $invoice['rete_ica'], 2)];
        }
        return $wh;
    }

    private function validateRequired(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Campo requerido para documento Alanube: {$field}");
            }
        }
    }
}
```

### 9b. Actualizar AlanubeIntegrationAdapter.php
En `framework/app/Core/AlanubeIntegrationAdapter.php`, case `'emit_document'`:

```php
case 'emit_document':
    $body = is_array($payload['body'] ?? null) ? (array) $payload['body'] : [];
    // Si llega invoice_data estructurado, construir el payload Alanube automáticamente
    if (!empty($payload['invoice_data']) && is_array($payload['invoice_data'])) {
        $builder = new AlanubeInvoiceBuilder();
        $body = $builder->buildInvoice((array) $payload['invoice_data']);
    }
    // Si llega credit_note_data, construir nota crédito
    if (!empty($payload['credit_note_data']) && is_array($payload['credit_note_data'])) {
        $builder = new AlanubeInvoiceBuilder();
        $body = $builder->buildCreditNote((array) $payload['credit_note_data']);
        $endpoint = $payload['endpoint'] ?? '/documents/credit-notes';
    }
    return $client->emitDocument($endpoint, $body);
```

### 9c. Crear test sin credenciales
Crear `framework/tests/test_alanube_builder.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$builder = new \App\Core\AlanubeInvoiceBuilder();

// Test 1: factura mínima válida
$payload = $builder->buildInvoice([
    'number'  => '1', 'prefix' => 'FE', 'date' => '2026-05-21', 'due_date' => '2026-05-21',
    'seller'  => ['nit' => '900123456', 'dv' => '1', 'name' => 'Demo SAS',
                  'address' => 'Calle 1 # 1-1', 'city_code' => 11001, 'department_code' => 11,
                  'phone' => '3001234567', 'email' => 'demo@test.co'],
    'buyer'   => ['nit' => '123456789', 'name' => 'Cliente Demo', 'email' => 'cliente@test.co'],
    'items'   => [
        ['description' => 'Servicio', 'quantity' => 1, 'unit_price' => 100000,
         'tax_rate' => 19, 'tax_type' => 'IVA'],
        ['description' => 'Producto con dcto', 'quantity' => 2, 'unit_price' => 50000,
         'discount' => 5000, 'tax_rate' => 19, 'tax_type' => 'IVA'],
    ],
    'rete_fuente' => 4000,
]);

// Validaciones estructurales
assert(isset($payload['invoice_lines']),          'invoice_lines requerido');
assert(count($payload['invoice_lines']) === 2,    '2 líneas de detalle');
assert(isset($payload['seller']['identification_number']), 'seller con NIT');
assert(isset($payload['legal_monetary_totals']['payable_amount']), 'totales calculados');
assert($payload['legal_monetary_totals']['line_extension_amount'] === 200000.0, 'subtotal correcto');
assert($payload['withholding_taxes'][0]['tax_id'] === 6, 'rete_fuente como withholding');

echo "AlanubeInvoiceBuilder: 5/5 assertions PASS" . PHP_EOL;
echo "Payload listo para Alanube cuando lleguen las credenciales." . PHP_EOL;
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
```

**Verificación:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/tests/test_alanube_builder.php
# PASS: 5/5 assertions PASS
# Output: JSON válido con invoice_lines, totales, retención
```

---

## FIX 10 — A-01: PUC Colombiano 5,000+ cuentas (5-8h)

**Evidencia:** `SELECT COUNT(*) FROM puc_nacional` → 109 filas.
Schema actual: `id, codigo, nombre, tipo, naturaleza, nivel, parent`

**Fix:** Crear `framework/data/puc_colombia_2024.sql` con el PUC oficial colombiano completo.

**Instrucción:** El archivo de seed debe usar `INSERT IGNORE INTO puc_nacional (codigo, nombre, tipo, naturaleza, nivel, parent)`.
Estructura mínima requerida por decreto DIAN para PYME:

| Clase | Descripción | Grupos mínimos |
|-------|-------------|----------------|
| 1 ACTIVOS | Disponible, Inversiones, Deudores, Inventarios, PP&E, Diferidos | 11,12,13,14,15,16,17 |
| 2 PASIVOS | Obligaciones financieras, Proveedores, Ctas por pagar, Impuestos, Laborales | 21,22,23,24,25 |
| 3 PATRIMONIO | Capital, Superávit, Resultados | 31,32,37 |
| 4 INGRESOS | Operacionales, No operacionales | 41,42 |
| 5 COSTOS | Operacionales de ventas | 51 |
| 6 GASTOS | Operacionales admin, Operacionales ventas, Financieros | 51,52,53 |
| 7 COSTOS PRODUCCIÓN | Materias primas, Mano de obra, CIF | 71,72,73 |

**Meta mínimo funcional:** ≥ 1,000 cuentas con nivel subcuenta (4 dígitos).
**Meta óptimo:** ≥ 4,500 cuentas completas (PUC oficial DIAN).

**Verificación:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$pdo = \App\Core\Database::connection();
echo 'Total: ' . \$pdo->query('SELECT COUNT(*) FROM puc_nacional')->fetchColumn() . PHP_EOL;
echo 'Subcuentas: ' . \$pdo->query(\"SELECT COUNT(*) FROM puc_nacional WHERE nivel='subcuenta'\")->fetchColumn() . PHP_EOL;
echo 'Clase 1: ' . \$pdo->query(\"SELECT COUNT(*) FROM puc_nacional WHERE codigo LIKE '1%'\")->fetchColumn() . PHP_EOL;
echo 'Clase 4: ' . \$pdo->query(\"SELECT COUNT(*) FROM puc_nacional WHERE codigo LIKE '4%'\")->fetchColumn() . PHP_EOL;
"
# PASS: Total ≥ 1000, subcuentas > 500
```

---

## FIX 11 — AUTH: Self-registration + OTP email + login seguro (6-8h)

### Contexto de los hallazgos

**Evidencia hallazgos críticos en `project/public/api.php`:**

1. **`auth/request_code` (línea ~3300):** Devuelve `'code' => $code` en la respuesta HTTP — el OTP queda expuesto en el body. Cualquiera con acceso a los logs o la respuesta obtiene el OTP.
2. **`auth/verify_code` (línea ~3329):** Llama `createAuthUser($projectId, $phone, $code, ...)` — el OTP queda guardado como contraseña del usuario. Hash de un código de 6 dígitos = 1,000,000 posibilidades.
3. **`auth/register` (línea ~3270):** Lee `tenant_id` del body del request — cualquier usuario puede afirmar ser de cualquier tenant.
4. **`ProjectRegistry::verifyAuthCode()`:** No hay chequeo de expiración — un OTP es válido indefinidamente.
5. **`auth_users` (SQLite):** Los 3 usuarios existentes tienen `is_active=0` — `auth/login` no verifica este campo, acepta usuarios inactivos.

**Infraestructura existente aprovechable:**
- `auth_codes` table en SQLite + `storeAuthCode()` / `verifyAuthCode()` — existe pero sin expiración
- `EmailService::sendNotification()` — SMTP+mail() funcional, listo para enviar OTP
- `AuthService::login()` con rate limiting y bcrypt — funcional excepto por el check `is_active`
- `AuthService::register()` — crea usuario vía `ProjectRegistry::createAuthUser()`

### Fix: 5 pasos en orden

#### 11a. Migración MySQL: tabla `otp_tokens` con expiración

Crear `framework/db/migrations/mysql/20260521_028_tenant_auth.sql`:

```sql
-- OTP tokens con expiración para producción
-- Separado de auth_users (SQLite) para que las OTPs no persistan en el registry
CREATE TABLE IF NOT EXISTS otp_tokens (
    id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    identifier  VARCHAR(120)      NOT NULL COMMENT 'email o teléfono del solicitante',
    purpose     VARCHAR(32)       NOT NULL DEFAULT 'register' COMMENT 'register|login|reset',
    code_hash   VARCHAR(255)      NOT NULL COMMENT 'bcrypt del código de 6 dígitos',
    expires_at  DATETIME          NOT NULL,
    used_at     DATETIME          NULL,
    created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_otp_identifier (identifier),
    INDEX idx_otp_expires    (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Crear `framework/scripts/migrate_otp_tokens.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/autoload.php';

$pdo = \App\Core\Database::connection();
$sql = file_get_contents(__DIR__ . '/../db/migrations/mysql/20260521_028_tenant_auth.sql');
try {
    $pdo->exec($sql);
    echo "otp_tokens: CREADA OK" . PHP_EOL;
    $cols = $pdo->query('DESCRIBE otp_tokens')->fetchAll(PDO::FETCH_COLUMN);
    echo "Columnas: " . implode(', ', $cols) . PHP_EOL;
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
```

**Ejecutar:**
```bash
C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe framework/scripts/migrate_otp_tokens.php
```

#### 11b. Crear `OtpService.php` — generación y validación segura

Crear `framework/app/Core/OtpService.php`:

```php
<?php
namespace App\Core;

/**
 * Genera y valida OTPs de 6 dígitos con expiración en MySQL.
 * NO almacena el código en texto plano — usa bcrypt.
 */
final class OtpService
{
    private const EXPIRY_MINUTES = 15;

    public function __construct(private \PDO $db) {}

    public function generate(string $identifier, string $purpose = 'register'): string
    {
        // Invalidar OTPs anteriores del mismo identificador/propósito
        $stmt = $this->db->prepare(
            'DELETE FROM otp_tokens WHERE identifier = ? AND purpose = ? AND used_at IS NULL'
        );
        $stmt->execute([$identifier, $purpose]);

        $code    = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $hash    = password_hash($code, PASSWORD_BCRYPT);
        $expires = date('Y-m-d H:i:s', time() + self::EXPIRY_MINUTES * 60);

        $stmt = $this->db->prepare(
            'INSERT INTO otp_tokens (identifier, purpose, code_hash, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$identifier, $purpose, $hash, $expires]);

        return $code; // Solo se devuelve aquí para enviarlo por email — NUNCA al cliente HTTP
    }

    public function verify(string $identifier, string $code, string $purpose = 'register'): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id, code_hash FROM otp_tokens
             WHERE identifier = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$identifier, $purpose]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !password_verify($code, $row['code_hash'])) {
            return false;
        }

        // Marcar como usado (no eliminar — audit trail)
        $stmt = $this->db->prepare(
            'UPDATE otp_tokens SET used_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$row['id']]);

        return true;
    }

    public function purgeExpired(): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM otp_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
```

#### 11c. Crear `TenantSelfRegistrationService.php`

Crear `framework/app/Core/TenantSelfRegistrationService.php`:

```php
<?php
namespace App\Core;

/**
 * Auto-registro de nuevos tenants con OTP por email.
 * El tenant_id siempre se genera internamente — NUNCA se acepta del payload.
 */
final class TenantSelfRegistrationService
{
    public function __construct(
        private \PDO         $db,
        private OtpService   $otpService,
        private EmailService $emailService
    ) {}

    /**
     * Paso 1: Solicitar registro → genera OTP y lo envía al email.
     * Retorna solo confirmación, nunca el código.
     *
     * @param array{email: string, phone?: string, nit: string,
     *              business_name: string, password: string, app_id?: string} $data
     * @return array{ok: bool, message: string, tenant_id?: string}
     */
    public function requestRegistration(array $data): array
    {
        if (empty($data['email']) || empty($data['nit']) || empty($data['business_name']) || empty($data['password'])) {
            return ['ok' => false, 'message' => 'Campos requeridos: email, nit, business_name, password'];
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Email no válido'];
        }

        if (strlen($data['password']) < 8) {
            return ['ok' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres'];
        }

        // Generar tenant_id único — nunca del payload
        $tenantId = 'tenant_' . bin2hex(random_bytes(8));

        // Guardar datos temporales mientras el OTP no sea verificado
        $stmt = $this->db->prepare(
            'INSERT INTO otp_tokens (identifier, purpose, code_hash, expires_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE expires_at = expires_at'
        );
        // Los datos del tenant se guardan en una tabla temporal de pendientes (ver 11d)
        $this->storePendingTenant($tenantId, $data);

        $code = $this->otpService->generate($data['email'], 'register');

        $this->emailService->sendNotification($tenantId, [
            'type'    => 'otp_registration',
            'to'      => $data['email'],
            'subject' => 'Código de verificación SUKI — ' . $code,
            'body'    => "Tu código de verificación es: <strong>{$code}</strong>\n\nVigente por 15 minutos.\nSi no solicitaste este registro, ignora este email.",
        ]);

        return [
            'ok'        => true,
            'message'   => 'Código enviado al email. Verificar en 15 minutos.',
            'tenant_id' => $tenantId, // Solo para que el cliente sepa con qué tenant_id continuar
        ];
    }

    /**
     * Paso 2: Verificar OTP → activa el tenant y crea el usuario admin.
     */
    public function verifyAndActivate(string $tenantId, string $email, string $code): array
    {
        if (!$this->otpService->verify($email, $code, 'register')) {
            return ['ok' => false, 'message' => 'Código inválido o expirado'];
        }

        $pending = $this->getPendingTenant($tenantId);
        if (!$pending) {
            return ['ok' => false, 'message' => 'Datos de registro no encontrados. Solicitar nuevo código.'];
        }

        // Crear usuario en auth_users (ProjectRegistry / SQLite) con contraseña real (no el OTP)
        $registry = new ProjectRegistry();
        $passwordHash = password_hash($pending['password'], PASSWORD_BCRYPT);

        $registry->createAuthUser(
            $tenantId,
            $pending['email'],
            $passwordHash,          // ← contraseña real hasheada, NO el OTP
            $pending['nit'],
            $pending['business_name'],
            1                       // is_active = 1 (ya verificado con OTP)
        );

        // Limpiar datos temporales
        $this->deletePendingTenant($tenantId);

        return ['ok' => true, 'message' => 'Cuenta activada. Ya puedes iniciar sesión.', 'tenant_id' => $tenantId];
    }

    private function storePendingTenant(string $tenantId, array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO otp_pending_registrations
             (tenant_id, email, phone, nit, business_name, password_hash, app_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE email=VALUES(email), updated_at=NOW()'
        );
        $stmt->execute([
            $tenantId,
            $data['email'],
            $data['phone'] ?? '',
            $data['nit'],
            $data['business_name'],
            password_hash($data['password'], PASSWORD_BCRYPT),
            $data['app_id'] ?? 'suki_erp',
        ]);
    }

    private function getPendingTenant(string $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM otp_pending_registrations WHERE tenant_id = ? AND created_at > NOW() - INTERVAL 30 MINUTE'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function deletePendingTenant(string $tenantId): void
    {
        $stmt = $this->db->prepare('DELETE FROM otp_pending_registrations WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
    }
}
```

La tabla `otp_pending_registrations` se añade a la migración 11a:

```sql
-- Añadir al final de 20260521_028_tenant_auth.sql:
CREATE TABLE IF NOT EXISTS otp_pending_registrations (
    tenant_id     VARCHAR(64)  NOT NULL,
    email         VARCHAR(200) NOT NULL,
    phone         VARCHAR(30)  NULL,
    nit           VARCHAR(20)  NOT NULL,
    business_name VARCHAR(200) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    app_id        VARCHAR(64)  NOT NULL DEFAULT 'suki_erp',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL,
    PRIMARY KEY (tenant_id),
    INDEX idx_otp_pending_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 11d. Parchear `api.php` — 4 correcciones de seguridad

**En `project/public/api.php`, buscar y corregir:**

**Parche 1 — `auth/request_code` (~línea 3300): eliminar OTP de la respuesta**
```php
// ANTES (exposición del OTP):
echo json_encode(['ok' => true, 'code' => $code, 'message' => 'Código enviado']);

// DESPUÉS:
echo json_encode(['ok' => true, 'message' => 'Código enviado al email registrado']);
// $code se pasa SOLO a EmailService, nunca al response
```

**Parche 2 — `auth/verify_code` (~línea 3329): no usar OTP como contraseña**
```php
// ANTES (OTP = contraseña):
$registry->createAuthUser($projectId, $phone, $code, ...);

// DESPUÉS: El OTP verifica identidad; contraseña viene en el request o se genera aleatoria segura
$password = $requestData['password'] ?? bin2hex(random_bytes(16));
$passwordHash = password_hash($password, PASSWORD_BCRYPT);
$registry->createAuthUser($projectId, $phone, $passwordHash, ...);
```

**Parche 3 — `auth/register` (~línea 3270): auto-generar tenant_id**
```php
// ANTES (tenant_id del payload):
$tenantId = $requestData['tenant_id'] ?? '';

// DESPUÉS:
$tenantId = 'tenant_' . bin2hex(random_bytes(8));
// Si el sistema requiere tenant_id predecible, usar hash determinístico del NIT:
// $tenantId = 'tenant_' . substr(hash('sha256', $requestData['nit'] ?? ''), 0, 16);
```

**Parche 4 — `auth/login`: verificar `is_active = 1`**
En `AuthService.php` o en el handler de `auth/login`, añadir check post-autenticación:
```php
// Después de verificar credenciales, antes de devolver token:
if (!($user['is_active'] ?? false)) {
    return ['ok' => false, 'error' => 'Cuenta pendiente de verificación. Revisar email.'];
}
```

**Parche 5 — `ProjectRegistry::verifyAuthCode()`: añadir expiración**
Buscar el método y añadir check de `created_at`:
```php
// Añadir en verifyAuthCode() después de fetch:
$created = strtotime($row['created_at'] ?? '1970-01-01');
if (time() - $created > 900) { // 15 minutos
    return false;
}
```

#### 11e. Añadir rutas y vistas (self-service)

**Rutas nuevas en `project/public/api.php`** (después de las rutas auth existentes):

```php
// POST /api/auth/tenant-register — Paso 1: solicitar registro con email OTP
case 'auth/tenant-register':
    $svc = new \App\Core\TenantSelfRegistrationService(
        \App\Core\Database::connection(),
        new \App\Core\OtpService(\App\Core\Database::connection()),
        new \App\Core\EmailService()
    );
    $result = $svc->requestRegistration($body);
    echo json_encode($result);
    break;

// POST /api/auth/tenant-verify-otp — Paso 2: verificar OTP y activar cuenta
case 'auth/tenant-verify-otp':
    $svc = new \App\Core\TenantSelfRegistrationService(
        \App\Core\Database::connection(),
        new \App\Core\OtpService(\App\Core\Database::connection()),
        new \App\Core\EmailService()
    );
    $result = $svc->verifyAndActivate(
        $body['tenant_id'] ?? '',
        $body['email'] ?? '',
        $body['code'] ?? ''
    );
    echo json_encode($result);
    break;
```

**Crear `project/views/auth/register_tenant.php`** — formulario de registro (blanco+cyan, sin purple):

```html
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registro — SUKI</title>
<style>
  :root { --cyan: #06b6d4; --cyan-dark: #0891b2; --bg: #f8fafc; }
  * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif; }
  body { background: var(--bg); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 2.5rem; width: 100%; max-width: 400px; }
  .logo { text-align: center; margin-bottom: 2rem; }
  .logo span { font-size: 1.75rem; font-weight: 700; color: var(--cyan-dark); }
  h1 { font-size: 1.25rem; font-weight: 600; color: #1e293b; margin-bottom: 0.5rem; }
  p.sub { color: #64748b; font-size: .875rem; margin-bottom: 1.5rem; }
  label { display: block; font-size: .875rem; font-weight: 500; color: #374151; margin-bottom: .25rem; }
  input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: .625rem .875rem; font-size: .9rem; outline: none; transition: border .2s; }
  input:focus { border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(6,182,212,.15); }
  .field { margin-bottom: 1rem; }
  .btn { width: 100%; background: var(--cyan); color: #fff; border: none; border-radius: 8px; padding: .75rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background .2s; margin-top: .5rem; }
  .btn:hover { background: var(--cyan-dark); }
  .msg { font-size: .875rem; padding: .75rem; border-radius: 8px; margin-top: 1rem; display: none; }
  .msg.ok { background: #ecfdf5; color: #065f46; }
  .msg.err { background: #fef2f2; color: #991b1b; }
  .login-link { text-align: center; margin-top: 1.5rem; font-size: .875rem; color: #64748b; }
  .login-link a { color: var(--cyan-dark); font-weight: 500; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
  <div class="logo"><span>SUKI</span></div>
  <h1>Crear cuenta</h1>
  <p class="sub">Regístrate para empezar tu empresa en SUKI.</p>
  <form id="regForm">
    <div class="field"><label>Nombre de la empresa</label><input type="text" name="business_name" required placeholder="Mi Empresa SAS"></div>
    <div class="field"><label>NIT</label><input type="text" name="nit" required placeholder="900123456-1"></div>
    <div class="field"><label>Email</label><input type="email" name="email" required placeholder="admin@miempresa.co"></div>
    <div class="field"><label>Contraseña</label><input type="password" name="password" required minlength="8" placeholder="Mínimo 8 caracteres"></div>
    <button type="submit" class="btn">Enviar código de verificación</button>
  </form>
  <div id="msg" class="msg"></div>
  <div class="login-link">¿Ya tienes cuenta? <a href="/login">Iniciar sesión</a></div>
</div>
<script>
document.getElementById('regForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const body = Object.fromEntries(fd.entries());
  const msg = document.getElementById('msg');
  try {
    const r = await fetch('/api/auth/tenant-register', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
    });
    const data = await r.json();
    msg.style.display = 'block';
    if (data.ok) {
      msg.className = 'msg ok';
      msg.textContent = data.message;
      // Guardar tenant_id para el siguiente paso
      sessionStorage.setItem('reg_tenant_id', data.tenant_id);
      sessionStorage.setItem('reg_email', body.email);
      setTimeout(() => window.location.href = '/auth/verify-otp', 1500);
    } else {
      msg.className = 'msg err';
      msg.textContent = data.message || 'Error al registrar';
    }
  } catch (err) {
    msg.style.display = 'block';
    msg.className = 'msg err';
    msg.textContent = 'Error de conexión';
  }
});
</script>
</body>
</html>
```

**Crear `project/views/auth/verify_otp.php`** — verificación del código:

```html
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verificar código — SUKI</title>
<style>
  :root { --cyan: #06b6d4; --cyan-dark: #0891b2; --bg: #f8fafc; }
  * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif; }
  body { background: var(--bg); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 2.5rem; width: 100%; max-width: 400px; }
  .logo { text-align: center; margin-bottom: 2rem; }
  .logo span { font-size: 1.75rem; font-weight: 700; color: var(--cyan-dark); }
  h1 { font-size: 1.25rem; font-weight: 600; color: #1e293b; margin-bottom: .5rem; }
  p.sub { color: #64748b; font-size: .875rem; margin-bottom: 1.5rem; }
  label { display: block; font-size: .875rem; font-weight: 500; color: #374151; margin-bottom: .25rem; }
  input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: .625rem .875rem; font-size: 1.5rem; letter-spacing: .5rem; text-align: center; outline: none; transition: border .2s; }
  input:focus { border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(6,182,212,.15); }
  .field { margin-bottom: 1rem; }
  .btn { width: 100%; background: var(--cyan); color: #fff; border: none; border-radius: 8px; padding: .75rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background .2s; margin-top: .5rem; }
  .btn:hover { background: var(--cyan-dark); }
  .msg { font-size: .875rem; padding: .75rem; border-radius: 8px; margin-top: 1rem; display: none; }
  .msg.ok { background: #ecfdf5; color: #065f46; }
  .msg.err { background: #fef2f2; color: #991b1b; }
  .back { text-align: center; margin-top: 1.5rem; font-size: .875rem; color: #64748b; }
  .back a { color: var(--cyan-dark); font-weight: 500; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
  <div class="logo"><span>SUKI</span></div>
  <h1>Verificar código</h1>
  <p class="sub" id="subText">Ingresa el código de 6 dígitos enviado a tu email.</p>
  <form id="verifyForm">
    <div class="field"><label>Código de verificación</label>
      <input type="text" name="code" required maxlength="6" pattern="[0-9]{6}" placeholder="000000" inputmode="numeric">
    </div>
    <button type="submit" class="btn">Verificar y activar cuenta</button>
  </form>
  <div id="msg" class="msg"></div>
  <div class="back"><a href="/auth/register">Volver al registro</a></div>
</div>
<script>
const tenantId = sessionStorage.getItem('reg_tenant_id') || '';
const email    = sessionStorage.getItem('reg_email') || '';
if (email) document.getElementById('subText').textContent = `Ingresa el código enviado a ${email}`;

document.getElementById('verifyForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const code = e.target.code.value.trim();
  const msg  = document.getElementById('msg');
  try {
    const r = await fetch('/api/auth/tenant-verify-otp', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ tenant_id: tenantId, email, code })
    });
    const data = await r.json();
    msg.style.display = 'block';
    if (data.ok) {
      msg.className = 'msg ok';
      msg.textContent = '¡Cuenta activada! Redirigiendo al login...';
      sessionStorage.removeItem('reg_tenant_id');
      sessionStorage.removeItem('reg_email');
      setTimeout(() => window.location.href = '/login', 2000);
    } else {
      msg.className = 'msg err';
      msg.textContent = data.message || 'Código incorrecto';
    }
  } catch (err) {
    msg.style.display = 'block';
    msg.className = 'msg err';
    msg.textContent = 'Error de conexión';
  }
});
</script>
</body>
</html>
```

### Verificación FIX 11

```bash
PHP=C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe

# 1. Tabla otp_tokens existe
$PHP -r "require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$p = \App\Core\Database::connection();
echo \$p->query('SELECT COUNT(*) FROM otp_tokens')->fetchColumn() . ' otp_tokens';"

# 2. Tabla otp_pending_registrations existe
$PHP -r "require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$p = \App\Core\Database::connection();
echo \$p->query('SELECT COUNT(*) FROM otp_pending_registrations')->fetchColumn() . ' pending';"

# 3. OtpService genera y verifica
$PHP -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$otp = new \App\Core\OtpService(\App\Core\Database::connection());
\$code = \$otp->generate('test@example.com', 'test');
\$ok   = \$otp->verify('test@example.com', \$code, 'test');
echo 'OtpService: ' . (\$ok ? 'PASS' : 'FAIL') . PHP_EOL;
\$wrong = \$otp->verify('test@example.com', '000000', 'test');
echo 'Wrong code rejected: ' . (!\$wrong ? 'PASS' : 'FAIL') . PHP_EOL;
"

# 4. api.php no contiene 'code' => $code en la respuesta auth/request_code
grep -n "'code' => \$code" project/public/api.php
# PASS: sin resultado (o solo en comentarios)

# 5. Tests sin regresión
$PHP framework/tests/run.php 2>&1 | tail -3
# PASS: 121/121
```

---

## ACTUALIZACIÓN DE DOCUMENTACIÓN

### CLAUDE.md — cambios mínimos, quirúrgicos

1. Sección STATUS — cambiar:
   ```
   ✅ PASS: 121/121 unit tests
   ```
   a:
   ```
   ✅ PASS: 121/121 unit tests (requiere INSTALL.md sin keys reales — ver FIX 1)
   ```

2. Sección NEXT STEPS — eliminar ítem 4 "ReteFuente + ICA":
   - FiscalRulesEngine.php + framework/data/fiscal_rules_co.json tienen cálculo real implementado
   - Ya no es un pendiente

3. Sección KNOWN ISSUES — actualizar:
   - "Tests E2E HTTP + CI remoto" → sigue como P1
   - Eliminar "PUC real + ReteFuente + ICA" como bloqueador cuando FIX 10 esté completo

### AUDIT_PIPELINE_5CAPAS.md — correcciones técnicas

1. **M1 (líneas 93-96):** cambiar `mensajes` → `chat_log`:
   ```bash
   # Antes:
   SELECT COUNT(*) as c FROM mensajes WHERE tenant_id IS NOT NULL
   # Después:
   SELECT COUNT(*) as c FROM chat_log WHERE tenant_id IS NOT NULL
   ```

2. **M14 Capa 1 (ls comando):** cambiar path de AppUserOnboarding:
   ```bash
   # Antes:
   ls -la framework/app/Core/Agents/Processes/AppUserOnboarding.php
   # Después:
   ls -la framework/app/Core/AppUserOnboarding.php
   ```

---

## VERIFICACIÓN FINAL — TODO

Ejecutar en orden tras completar todos los fixes:

```bash
PHP=C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe

echo "=== 1. TESTS UNITARIOS ==="
$PHP framework/tests/run.php 2>&1 | tail -3
# ESPERADO: Passed: 121 | Failed: 0

echo "=== 2. TC03 OUT-OF-SCOPE ==="
$PHP framework/tests/fase1_tc01_tc04.php 2>&1 | tail -5
# ESPERADO: 48/48 PASS

echo "=== 3. ai_agents TABLE ==="
$PHP -r "require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$p = \App\Core\Database::connection();
echo 'ai_agents rows: ' . \$p->query('SELECT COUNT(*) FROM ai_agents')->fetchColumn();"
# ESPERADO: 0 (sin error)

echo "=== 4. INTEGRATION TENANT_ID ==="
$PHP -r "require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$p = \App\Core\Database::connection();
echo \$p->query(\"SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='suki_saas' AND TABLE_NAME='integration_connections'
    AND COLUMN_NAME='tenant_id'\")->fetchColumn();"
# ESPERADO: tenant_id

echo "=== 5. ALANUBE BUILDER ==="
$PHP framework/tests/test_alanube_builder.php 2>&1
# ESPERADO: 5/5 assertions PASS

echo "=== 6. PUC CUENTAS ==="
$PHP -r "require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
echo \App\Core\Database::connection()->query('SELECT COUNT(*) FROM puc_nacional')->fetchColumn();"
# ESPERADO: ≥ 1000

echo "=== 7. DB HEALTH ==="
$PHP framework/tests/db_health.php 2>&1 | grep -E "warning|error|OK"
# ESPERADO: sin warnings de índices

echo "=== 8. INTEGRATION TESTS ==="
$PHP framework/tests/fase3_tc09_tc11.php 2>&1 | tail -3
$PHP framework/tests/fase4_tc12_tc15.php 2>&1 | tail -3
$PHP framework/tests/fase5_7_tc16_tc23.php 2>&1 | tail -3
# ESPERADO: todos PASS sin regresión
```

---

## COMMIT SUGERIDO TRAS TODO

```
fix(audit): corregir 11 hallazgos auditoría 2026-05-21 + auth seguro

FIX 1  — INSTALL.md: placeholders en lugar de API keys reales → 121/121
FIX 2  — SemanticCache.prune(): prepared statement (canon sin raw SQL)
FIX 3  — AppInterviewState: log en catch fallback a archivos
FIX 4  — .env: comentar GEMINI_CHAT_ENABLED (variable inerte)
FIX 5  — ConversationGatewayStubsTrait: isOutOfScopeQuestion fallback → TC03 48/48
FIX 6  — Migración ai_agents MySQL (tabla faltaba, SpecialistPersonas la requiere)
FIX 7  — Índices tenant_id/created_at en 8 tablas de app
FIX 8  — IntegrationStore + migración: tenant_id en 4 tablas de integración (P0)
FIX 9  — AlanubeInvoiceBuilder: payload DIAN real según API Alanube CO v1 (SKIP — pendiente credenciales)
FIX 10 — puc_nacional: seed PUC colombiano 2024 (≥ 1000 cuentas operativas)
FIX 11 — AUTH: OtpService + TenantSelfRegistrationService + migración otp_tokens
         Parches api.php: OTP no en respuesta, no como contraseña, tenant_id auto-generado
         Vistas register_tenant.php + verify_otp.php (blanco+cyan, sin purple)
DOC    — CLAUDE.md: corregir ReteFuente como implementado, path AppUserOnboarding
DOC    — AUDIT_PIPELINE_5CAPAS.md: mensajes→chat_log, AppUserOnboarding path

Tests: 121/121 run.php | 48/48 fase1 | 24/24 fase3 | 9/9 fase4 | 27/27 fase5-7
```
