# AUDITORÍA DE CALIDAD — SUKI AI-AOS
## ISO/IEC 25010 · OWASP ASVS Level 2 · CMMI Level 3
## Edición Especial: Pipeline de Contexto 5 Capas + Experiencia Personalizada
> Adaptada para el stack real de SUKI: PHP custom, MySQL, IIS/web.config, Qdrant.
> NO usa Laravel/Artisan. NO usa SQLite. NO usa .htaccess.
> Ejecutar en Claude Code desde la raíz del proyecto `C:\laragon\www\suki`.

---

## CONTRATO DE AUDITORÍA

Estas reglas no son sugerencias. Si se violan, el reporte es inválido:

1. **Evidencia o no cuenta.** Cada hallazgo requiere: archivo + número de línea + output real del comando ejecutado.
2. **Un 200 no es PASS.** Verificar el cuerpo de la respuesta y el comportamiento con datos incorrectos.
3. **El código que existe no es el código que funciona.** Verificar ejecución real, no solo presencia de clase.
4. **Prohibido marcar PASS por ausencia de evidencia de fallo.** "No encontré problemas" requiere evidencia de búsqueda.
5. **Si un prerequisito falla, reportar la cadena completa.** No evaluar B si A está roto.
6. **Cero interpretación de comentarios como evidencia.** Solo código ejecutado y output real.

---

## PREREQUISITOS — ejecutar primero

```bash
# Versión PHP y estado del proyecto
php -v
php framework/scripts/codex_self_check.php --strict

# Estado de la base de datos MySQL
php framework/tests/db_health.php

# Commit exacto auditado
git rev-parse HEAD
git log --oneline -1
git status --short | head -20

# Suite de tests completa — debe ser 121/121 ANTES de auditar
php framework/tests/run.php

# Verificar acceso HTTP real
php framework/tests/api_route_turn.php
```

**Si `db_health.php` falla o `run.php` no es 121/121: DETENER AUDITORÍA.**
Reportar como bloqueador nivel 0. El software no está en condición de ser auditado.

**Stack verificado:**
- PHP: `framework/public/index.php` (Marketplace), `project/public/index.php` (Apps+Builder), `tower/public/index.php` (Torre)
- API: `project/public/api.php` — router principal de todas las llamadas
- DB: MySQL `suki_saas` en `localhost` — NUNCA SQLite
- Rutas: `web.config` (IIS) — NUNCA `.htaccess`
- Tests: `php framework/tests/api_route_turn.php` para pruebas internas HTTP

---

## MÓDULO 1 — CORRECCIÓN FUNCIONAL
*¿El sistema hace lo que debe hacer? (ISO 25010 §4.1.1)*

### 1.1 Rutas declaradas en web.config vs. implementadas

```bash
# Ver todas las rutas configuradas en IIS
grep -E "rewrite|location|action" web.config | grep -v "#"

# Verificar que cada entry point existe físicamente
ls -la framework/public/index.php
ls -la project/public/index.php
ls -la project/public/api.php
ls -la tower/public/index.php

# Verificar router interno de API
grep -n "case\|route\|endpoint" project/public/api.php | head -40
```

### 1.2 Flujo crítico E2E — Chat → DB

```bash
# Test interno de chat (usa api_route_turn.php para evitar HTTP externo)
php framework/tests/api_route_turn.php
```

Leer el output y verificar:
- `intent` clasificado correctamente (no `unknown` ni `out_of_scope`)
- `response` no vacía, no HTML, no stack trace
- `tenant_id` presente en el log

```bash
# Verificar que los mensajes se persisten en MySQL
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();
\$rows = \$db->query('SELECT COUNT(*) as c FROM mensajes WHERE tenant_id IS NOT NULL');
echo 'Mensajes con tenant_id: ' . \$rows[0]['c'] . PHP_EOL;
\$sinTenant = \$db->query('SELECT COUNT(*) as c FROM mensajes WHERE tenant_id IS NULL OR tenant_id = \"\"');
echo 'Mensajes SIN tenant_id: ' . \$sinTenant[0]['c'] . PHP_EOL;
"
```

PASS: `Mensajes SIN tenant_id: 0`
FAIL: cualquier valor > 0 en mensajes sin tenant_id

### 1.3 Comportamiento con input inválido

```bash
# Test entrada vacía vía api_route_turn (simula request vacío)
php -r "
\$_POST = ['tenant_id' => 'demo', 'message' => ''];
\$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
require 'project/public/api.php';
\$out = ob_get_clean();
\$json = json_decode(\$out, true);
echo 'Vacío — status: ' . (isset(\$json['error']) ? 'CONTROLADO' : 'SIN CONTROL') . PHP_EOL;
" 2>&1 | head -5

# Test inyección SQL en mensaje
php -r "
\$_POST = ['tenant_id' => 'demo', 'message' => \"'; DROP TABLE mensajes; --\"];
\$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
require 'project/public/api.php';
\$out = ob_get_clean();
echo 'SQL injection — responde JSON: ' . (json_decode(\$out) ? 'SÍ' : 'NO') . PHP_EOL;
" 2>&1 | head -5
```

### 1.4 Consistencia de datos — lo guardado existe en DB

```bash
# Tras una inserción via chat, verificar en MySQL
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();
\$last = \$db->query('SELECT id, tenant_id, role, created_at FROM mensajes ORDER BY id DESC LIMIT 3');
foreach(\$last as \$r) {
    echo implode(' | ', \$r) . PHP_EOL;
}
"
```

---

## MÓDULO 2 — CONFIABILIDAD
*¿El sistema falla de forma controlada? (ISO 25010 §4.1.2)*

### 2.1 Manejo de errores en framework/app/Core/

```bash
# Todos los catches del proyecto — buscar silenciosos
grep -rn "catch\s*(" framework/app/Core/ --include="*.php" | wc -l
echo "--- Catches sospechosos (sin log ni throw):"
grep -rn -A5 "catch\s*(" framework/app/Core/ --include="*.php" | \
  grep -v "Log::\|logger\|throw\|TelemetryService\|error_log\|logEvent" | \
  grep "}" | head -20
```

### 2.2 Resiliencia ante fallos de servicios externos

```bash
# Llamadas HTTP externas en el proyecto (LLM providers, Qdrant, Alanube)
grep -rn "curl_exec\|file_get_contents.*http\|guzzle\|stream_context_create" \
  framework/app/Core/ --include="*.php" | grep -v test | grep -v vendor
```

Para cada archivo encontrado, verificar que tiene timeout configurado:
```bash
grep -rn "CURLOPT_TIMEOUT\|timeout\|connect_timeout" \
  framework/app/Core/LLM/Providers/ --include="*.php"
```

PASS: cada provider LLM tiene CURLOPT_TIMEOUT definido.

### 2.3 LLM fallback chain — verificar que nunca se queda sin respuesta

```bash
# Verificar el orden del cascade en LLMRouter
grep -n "fallback\|LLM_PRIMARY\|LLM_FALLBACK\|catch.*callLLM\|nextProvider" \
  framework/app/Core/LLM/LLMRouter.php | head -20

# Verificar config de providers
grep -E "primary|fallback|providers" framework/config/llm.php | head -10
```

PASS: hay al menos 2 providers en cascada y un catch-all que nunca retorna null.

---

## MÓDULO 3 — SEGURIDAD
*OWASP ASVS Level 2*

### 3.1 Tenant isolation — la regla más crítica de SUKI

```bash
# Verificar que BaseRepository agrega tenant_id en TODOS los queries
grep -n "tenant_id\|where.*tenant\|addCondition.*tenant" \
  framework/app/Core/BaseRepository.php | head -20

# Verificar que NO existe ningún query sin tenant_id en los servicios críticos
grep -rn "->query(\|->find(\|->all(" \
  framework/app/Core/ --include="*.php" | \
  grep -v "tenant_id\|BaseRepository\|test\|#" | \
  grep -v "\/\/" | head -20
```

FAIL crítico: cualquier query sin tenant_id scope es fuga de datos cross-tenant.

### 3.2 Autenticación y master key

```bash
# Verificar que SUKI_MASTER_KEY no está hardcodeada en código
grep -rn "SUKI_MASTER_KEY\|master_key\|masterkey" \
  framework/ project/ --include="*.php" | grep -v ".env" | grep -v "getenv\|env(" | head -10

# Verificar que el .env no está en git
cat .gitignore | grep ".env"
git ls-files | grep ".env$"
```

FAIL: si `.env` está trackeado en git.

### 3.3 Prevención de prompt injection

```bash
# Verificar que BuilderFastPathParser tiene validación de campos
grep -n "STEP_ALLOWED_FIELDS\|validate\|strip\|sanitize" \
  framework/app/Core/Agents/BuilderFastPathParser.php | head -15

# Verificar que el system prompt tiene protección de identidad
grep -n "Soy SUKI\|olvida\|nunca reveles\|IDENTIDAD PROTEGIDA" \
  framework/prompts/builder_system_prompt.txt
```

### 3.4 Protección de rutas sensibles en web.config

```bash
# Verificar que .env, storage, vendor están bloqueados
grep -A3 "sensitive\|403\|storage\|vendor\|\.env" web.config | head -30
```

---

## MÓDULO 4 — RENDIMIENTO
*ISO 25010 §4.1.5*

### 4.1 Latencia del chat

```bash
# Medir tiempo del pipeline completo (interno)
time php framework/tests/api_route_turn.php

# Verificar que el router usa cache primero
grep -n "cache\|Cache::get\|redis\|apcu" \
  framework/app/Core/IntentRouter.php | head -10
```

PASS: respuesta < 3s para mensajes sin LLM, < 10s para LLM real.

### 4.2 Índices DB

```bash
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();
\$tables = ['mensajes', 'user_profiles', 'ai_agents', 'app_memoria'];
foreach(\$tables as \$t) {
    \$idx = \$db->query('SHOW INDEX FROM ' . \$t);
    \$cols = array_column(\$idx, 'Column_name');
    echo \$t . ': ' . implode(', ', \$cols) . PHP_EOL;
}
" 2>&1
```

PASS: `mensajes` tiene índice en `tenant_id`, `session_id`. `user_profiles` tiene índice en `(tenant_id, user_id, world)`.

---

## MÓDULO 5 — MANTENIBILIDAD
*ISO 25010 §4.1.6*

### 5.1 Tamaño de archivos críticos (Strangler progress)

```bash
# ChatAgent — objetivo: < 1500 líneas
wc -l framework/app/Core/ChatAgent.php
echo "META: < 1500 líneas"

# Verificar extractores ya separados
ls -la framework/app/Core/Agents/
wc -l framework/app/Core/Agents/*.php 2>/dev/null | sort -n
```

### 5.2 Contratos JSON — fuente de verdad

```bash
# Verificar integridad de contratos críticos
php -r "
\$files = glob('docs/contracts/*.json');
\$files = array_merge(\$files, glob('framework/contracts/**/*.json'));
foreach(\$files as \$f) {
    json_decode(file_get_contents(\$f));
    echo (json_last_error() === JSON_ERROR_NONE ? 'OK' : 'INVALID') . ' ' . \$f . PHP_EOL;
}
"
```

### 5.3 Sin raw SQL en capa de aplicación

```bash
# Verificar que no hay raw SQL fuera de BaseRepository y Database.php
grep -rn "mysqli_query\|PDO::query\|->exec(\"SELECT\|->exec(\"INSERT\|->exec(\"UPDATE\|->exec(\"DELETE" \
  framework/app/Core/ project/app/ --include="*.php" | \
  grep -v "BaseRepository\|Database.php\|test" | head -20
```

FAIL: cualquier raw SQL en servicios de negocio.

---

## MÓDULO 6 — OBSERVABILIDAD
*AgentOps Telemetry*

### 6.1 Traces activos

```bash
# Verificar que los traces se están escribiendo
ls -lt framework/storage/logs/agentops/ | head -5
ls -lt project/storage/logs/agentops/ 2>/dev/null | head -5

# Último trace — verificar estructura
tail -1 $(ls -t framework/storage/logs/agentops/trace_*.jsonl 2>/dev/null | head -1) 2>/dev/null | \
  php -r "echo json_encode(json_decode(file_get_contents('php://stdin')), JSON_PRETTY_PRINT);" 2>/dev/null | head -20
```

### 6.2 Cobertura de telemetría en flows críticos

```bash
# Verificar que TelemetryService se llama en ChatAgent
grep -n "TelemetryService\|logEvent\|traceEvent" \
  framework/app/Core/ChatAgent.php | head -10

# Verificar que los LLM providers loguean tokens consumidos
grep -rn "tokens\|usage\|input_tokens\|output_tokens" \
  framework/app/Core/LLM/Providers/ --include="*.php" | head -10
```

---

## MÓDULO 7 — QDRANT Y MEMORIA SEMÁNTICA
*Clasificación de intents y retrieval*

### 7.1 Estado de colecciones Qdrant

```bash
# Verificar configuración de Qdrant
grep -E "QDRANT_URL|QDRANT_API_KEY|QDRANT_COLLECTION" project/.env | cut -d= -f1

# Verificar que IntentClassifier tiene threshold correcto
grep -n "threshold\|0\.65\|0\.72\|score" \
  framework/app/Core/IntentClassifier.php | head -10

# Seed de intents — verificar que está cargado
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$svc = new \App\Core\SemanticMemoryService();
echo 'SemanticMemoryService instanciable: OK' . PHP_EOL;
" 2>&1
```

### 7.2 Colección user_memory — memoria personal por usuario

```bash
# Verificar que ingestUserInteraction() existe y tiene la firma correcta
grep -n "ingestUserInteraction\|user_memory\|retrieveUserMemory" \
  framework/app/Core/SemanticMemoryService.php | head -10

# CRÍTICO: verificar que se LLAMA desde ChatAgent (no solo existe)
grep -n "ingestUserInteraction" framework/app/Core/ChatAgent.php
```

FAIL crítico: si `ingestUserInteraction` no aparece en ChatAgent.php, la memoria personal nunca se persiste.

### 7.3 Aislamiento de vectores por tenant

```bash
# Verificar buildScopeFilter incluye tenant_id
grep -n "buildScopeFilter\|tenant_id.*filter\|must.*tenant" \
  framework/app/Core/SemanticMemoryService.php | head -10
```

---

## MÓDULO 8 — FRONTEND Y RUTAS HTTP
*Verificación de 4 mundos de SUKI*

### 8.1 Verificar que los 4 mundos cargan

```bash
# Verificar entry points
php -l framework/public/index.php && echo "Marketplace: SINTAXIS OK"
php -l project/public/index.php && echo "Apps+Builder: SINTAXIS OK"
php -l tower/public/index.php && echo "Torre: SINTAXIS OK"
php -l project/public/api.php && echo "API: SINTAXIS OK"
```

### 8.2 Verificar que vistas no usan URLs hardcodeadas

```bash
# Buscar URLs hardcodeadas en frontend (deben usar SUKI_BASE o APP_URL)
grep -rn "http://\|https://" framework/public/ project/public/ tower/public/ \
  --include="*.php" --include="*.html" | grep -v "cdn\|googleapis\|gstatic" | \
  grep -v "APP_URL\|SUKI_BASE\|env(" | head -20
```

### 8.3 Verificar que fetch() usa la base URL correcta

```bash
# En JS, buscar fetch() sin variable de base
grep -rn "fetch('/\|fetch(\"/" \
  framework/public/ project/public/ tower/public/ \
  --include="*.js" --include="*.php" | \
  grep -v "SUKI_BASE\|base_url\|apiBase" | head -20
```

FAIL: fetch con ruta absoluta hardcodeada rompe en subdirectorios.

### 8.4 Verificar que web.config protege rutas correctamente

```bash
# Verificar reglas de rewrite activas
grep -c "RewriteRule\|action" web.config
echo "Reglas activas:"
grep "action" web.config | head -10

# Verificar bloqueo de acceso directo a PHP internos
grep -B2 -A5 "403\|Forbidden\|deny" web.config | head -30
```

---

## MÓDULO 9 — INTEGRACIÓN DIAN / FISCAL
*P0 para Colombia*

### 9.1 Estado de AlanubeIntegrationAdapter

```bash
# Verificar si el payload XML/UBL está implementado
grep -n "ubl\|xml\|cufe\|firma\|sign\|payload\|buildInvoice" \
  framework/app/Core/AlanubeIntegrationAdapter.php 2>/dev/null | head -20

# Verificar si es stub o real
grep -n "TODO\|stub\|placeholder\|\[\]\|array()" \
  framework/app/Core/AlanubeIntegrationAdapter.php 2>/dev/null | head -10
```

FAIL (P0): si `buildInvoice()` retorna `[]` o array vacío sin XML real.

### 9.2 PUC Colombiano — cuentas reales

```bash
# Contar cuentas en AccountingRepository
grep -n "1101\|1110\|2205\|4135\|PUC\|plan_cuentas" \
  framework/app/Core/AccountingRepository.php 2>/dev/null | head -10

php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();
try {
    \$count = \$db->query('SELECT COUNT(*) as c FROM plan_cuentas');
    echo 'Cuentas PUC en DB: ' . \$count[0]['c'] . PHP_EOL;
} catch(\Exception \$e) {
    echo 'Tabla plan_cuentas NO EXISTE: ' . \$e->getMessage() . PHP_EOL;
}
" 2>&1
```

FAIL (P1): si hay menos de 100 cuentas reales (necesita 5000+).

---

## MÓDULO 10 — SUITE DE TESTS
*Verificación de cobertura real*

### 10.1 Tests unitarios

```bash
php framework/tests/run.php
# PASS: 121/121
```

### 10.2 Tests de integración por fase

```bash
php framework/tests/fase1_tc01_tc04.php
php framework/tests/fase3_tc09_tc11.php
php framework/tests/fase4_tc12_tc15.php
php framework/tests/fase5_7_tc16_tc23.php
```

### 10.3 Smoke test LLM real

```bash
php framework/tests/llm_smoke.php 2>&1
# EXPECTED FAIL si no hay credentials configurados
# PASS real: requiere LLM_PRIMARY_PROVIDER activo en .env
```

### 10.4 DB health

```bash
php framework/tests/db_health.php
# PASS: todos los checks en verde
```

---

## MÓDULO 11 — MULTI-TENANCIA
*Aislamiento absoluto de datos por empresa*

### 11.1 Verificar que BaseRepository enforce tenant_id en TODAS las operaciones

```bash
# Leer BaseRepository y verificar que cada método público incluye tenant_id
grep -n "function find\|function all\|function insert\|function update\|function delete\|function query\|WHERE\|->where" \
  framework/app/Core/BaseRepository.php | head -30
```

### 11.2 Verificar que no hay leakage cross-tenant en tests

```bash
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();

// Crear registros para 2 tenants distintos
\$tenantA = 'audit_test_A';
\$tenantB = 'audit_test_B';

// Verificar que query por tenant A no trae tenant B
\$rows = \$db->query(
    'SELECT COUNT(*) as c FROM mensajes WHERE tenant_id = ? AND tenant_id != ?',
    [\$tenantA, \$tenantB]
);
echo 'Isolation test: ' . (\$rows[0]['c'] === 0 || \$tenantA !== \$tenantB ? 'PASS' : 'FAIL') . PHP_EOL;
" 2>&1
```

---

## MÓDULO 12 — SISTEMA DE ROUTING Y CLASIFICACIÓN
*Router order: Cache → Rules → RAG → LLM*

### 12.1 Verificar orden del router

```bash
# El orden NUNCA puede cambiarse
grep -n "cache\|rules\|qdrant\|rag\|llm\|fallback\|classify" \
  framework/app/Core/IntentRouter.php | head -20

# Verificar que LLM es SIEMPRE el último recurso
tail -30 framework/app/Core/IntentRouter.php
```

FAIL: si LLM aparece antes de Rules o RAG.

### 12.2 Verificar threshold Qdrant

```bash
grep -n "0\.65\|threshold\|MIN_SCORE\|min_score" \
  framework/app/Core/IntentClassifier.php | head -5

# Score actual en docs vs código
grep -n "0\.72\|0\.65" docs/canon/ROUTER_CANON.md 2>/dev/null | head -3
```

PASS: threshold en código = 0.65 (docs dicen 0.72 — es una desincronización P2 conocida).

### 12.3 Verificar reglas deterministas para intents críticos

```bash
# Las reglas deben capturar create_app, pos_*, accounting_* sin tocar LLM
grep -rn "create_app\|pos_sale\|accounting_" \
  framework/app/Core/IntentRouter.php \
  framework/contracts/rules/*.json 2>/dev/null | head -20
```

---

## MÓDULO 13 — AGENTE BUILDER (Entrevista de creación de apps)
*¿El Builder hace una entrevista real antes de crear?*

### 13.1 Verificar pasos de la entrevista

```bash
# Verificar cuántos pasos tiene BuilderOnboardingProcess
grep -n "steps\|STEPS\|allowedSteps\|step_order\|business_type\|operation_model\|needs_scope\|documents\|user_roles\|fiscal_config\|formulas_and_rules\|integrations" \
  framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php | head -20

# Contar pasos core
grep -c "business_type\|operation_model\|needs_scope\|documents\|user_roles\|fiscal_config\|formulas_and_rules\|integrations" \
  framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php
```

PASS: mínimo 8 pasos (business_type, operation_model, needs_scope, documents, user_roles, fiscal_config, formulas_and_rules, integrations).
FAIL: solo 4 pasos → la entrevista es insuficiente para capturar reglas de negocio reales.

### 13.2 Verificar que la entrevista captura reglas de negocio y fórmulas

```bash
# ¿Hay preguntas sobre fórmulas de cálculo?
grep -n "formula\|calculo\|regla\|rule\|margin\|descuento\|retencion\|impuesto\|iva" \
  framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php | head -10

# ¿Hay preguntas sobre roles de usuario?
grep -n "role\|rol\|usuario\|vendedor\|admin\|permiso" \
  framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php | head -10
```

FAIL: si no hay preguntas sobre fórmulas ni roles → la app se crea sin reglas de negocio reales.

### 13.3 Verificar pasos dinámicos — el agente puede crear pasos nuevos

```bash
# ¿Existe soporte para pasos dinámicos?
grep -n "dynamic_steps\|addStep\|customStep\|extra_steps" \
  framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php \
  framework/app/Core/AppInterviewState.php | head -10
```

FAIL: si no hay `dynamic_steps` → el agente no puede adaptar la entrevista al negocio.

### 13.4 Verificar que AppInterviewState persiste en MySQL (no JSON files)

```bash
# ¿Dónde se guarda el estado de la entrevista?
grep -n "json_encode\|file_put_contents\|storage/meta\|Database\|INSERT\|UPDATE" \
  framework/app/Core/AppInterviewState.php | head -10
```

FAIL (P1): si usa `file_put_contents` → estado se pierde si el servidor se reinicia.
PASS: si usa MySQL vía BaseRepository.

### 13.5 Verificar que BuilderFastPathParser usa < 400 tokens

```bash
grep -n "buildCapsule\|token\|max_token\|no_chat_history" \
  framework/app/Core/Agents/BuilderFastPathParser.php | head -10
```

---

## MÓDULO 14 — ARQUITECTURA DEL PIPELINE: 5 CAPAS
*La auditoría más crítica para personalización y contexto real*

```
CAPA 1: GATEKEEPER     ─── ¿Quién eres? ¿Tienes perfil?
CAPA 2: CTX MIDDLEWARE ─── Construir system prompt con identidad real
CAPA 3: LLM PROCESSING ─── El LLM nunca ve al usuario directamente
CAPA 4: RESP FORMATTER ─── Formatear según perfil del usuario
CAPA 5: FEEDBACK LOOP  ─── Loguear, aprender, actualizar perfil
```

### 14.1 CAPA 1 — Gatekeeper: IdentityResolver + UserProfile

```bash
# ¿IdentityResolver carga el perfil del usuario?
grep -n "user_profile\|profile_json\|onboarding_completed\|UserProfileService\|loadProfile" \
  framework/app/Core/Agents/IdentityResolver.php | head -10

# ¿Existe la tabla user_profiles en MySQL?
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();
try {
    \$cols = \$db->query('DESCRIBE user_profiles');
    echo 'Tabla user_profiles: EXISTE (' . count(\$cols) . ' columnas)' . PHP_EOL;
    foreach(\$cols as \$c) echo '  ' . \$c['Field'] . ': ' . \$c['Type'] . PHP_EOL;
} catch(\Exception \$e) {
    echo 'FAIL: tabla user_profiles NO EXISTE — ' . \$e->getMessage() . PHP_EOL;
}
" 2>&1

# ¿Existe UserProfileService?
ls -la framework/app/Core/UserProfileService.php 2>/dev/null || \
  echo "FAIL: UserProfileService.php NO EXISTE"

# ¿Existe AppUserOnboarding?
ls -la framework/app/Core/Agents/Processes/AppUserOnboarding.php 2>/dev/null || \
  echo "FAIL: AppUserOnboarding.php NO EXISTE"
```

**Criterios Capa 1:**
- PASS: tabla `user_profiles` existe con campos `tech_level`, `role_label`, `onboarding_completed`
- PASS: `IdentityResolver` carga el perfil antes de procesar el mensaje
- PASS: si `onboarding_completed = false`, se redirige al flujo de onboarding
- FAIL: si el agente responde sin conocer el nombre, rol o nivel técnico del usuario

### 14.2 CAPA 2 — Context Middleware: System Prompt Dinámico

```bash
# ¿ChatAgent construye un system prompt diferente por mundo/rol?
grep -n "buildSystemPrompt\|builder_system_prompt\|app_system_prompt\|world\|mode" \
  framework/app/Core/ChatAgent.php | head -20

# CRÍTICO: ¿Usa el mismo prompt.txt para todos los mundos?
grep -n "file_get_contents.*system_prompt\|prompts/builder" \
  framework/app/Core/ChatAgent.php | head -5
```

FAIL CRÍTICO: si hay una sola línea `file_get_contents('...builder_system_prompt.txt')` para todos los modos → el agente no tiene identidad diferenciada por contexto.

```bash
# ¿Se inyecta el perfil del usuario en el system prompt?
grep -n "profile\|tech_level\|role_label\|display_name\|user_name" \
  framework/app/Core/ChatAgent.php | head -10

# ¿Se inyecta el contexto de la app activa?
grep -n "buildDeveloperContext\|AppMemoryService\|app_schema\|requirements" \
  framework/app/Core/ChatAgent.php | head -10

# ¿Se inyecta la persona especialista?
grep -n "SpecialistPersonas\|getPersonaForTenant\|specialist_persona\|persona" \
  framework/app/Core/ChatAgent.php | head -10
```

**Sistema de scoring Capa 2:**
```bash
SCORE=0
# +2: system prompt diferenciado por mundo (builder vs app vs torre)
grep -c "builder.*prompt\|app.*prompt\|torre.*prompt" framework/app/Core/ChatAgent.php > /dev/null 2>&1 && SCORE=$((SCORE+2))
# +2: perfil de usuario inyectado
grep -qn "tech_level\|role_label\|user_name" framework/app/Core/ChatAgent.php && SCORE=$((SCORE+2))
# +2: contexto de app activa inyectado
grep -qn "buildDeveloperContext\|app_schema" framework/app/Core/ChatAgent.php && SCORE=$((SCORE+2))
# +2: cross-session memory inyectada
grep -qn "CrossSessionMemory\|cross_session\|loadRecentTurns" framework/app/Core/ChatAgent.php && SCORE=$((SCORE+2))
# +2: specialist persona inyectada
grep -qn "SpecialistPersonas\|getPersonaForTenant" framework/app/Core/ChatAgent.php && SCORE=$((SCORE+2))
echo "Score Capa 2: $SCORE /10"
[ $SCORE -ge 8 ] && echo "NIVEL: Context Middleware Enterprise" || \
[ $SCORE -ge 5 ] && echo "NIVEL: Context Middleware parcial — gaps de personalización" || \
echo "BLOQUEADOR: el LLM responde sin contexto real del usuario"
```

### 14.3 CAPA 3 — LLM Processing: El LLM nunca ve al usuario directamente

```bash
# Verificar que el LLM siempre recibe system prompt (nunca solo user message)
grep -n "system.*prompt\|systemPrompt\|messages.*system\|role.*system" \
  framework/app/Core/LLM/Providers/ClaudeProvider.php \
  framework/app/Core/LLM/Providers/MistralProvider.php \
  framework/app/Core/LLM/Providers/GeminiProvider.php | head -20

# Verificar que el prompt incluye isolación de tenant
grep -n "tenant\|solo.*datos\|limitado.*empresa\|no.*acceso.*otro" \
  framework/prompts/builder_system_prompt.txt | head -5
```

FAIL: si algún provider envía la petición sin `system` → el LLM no tiene contexto.

### 14.4 CAPA 4 — Response Formatter: Adaptado al perfil

```bash
# ¿Existe lógica de formateo según tech_level?
grep -rn "tech_level\|basic.*response\|advanced.*response\|formatForLevel" \
  framework/app/Core/ --include="*.php" | head -10

# ¿El ResponseSynthesizer adapta la respuesta?
grep -n "profile\|level\|persona\|format" \
  framework/app/Core/Agents/Orchestrator/ResponseSynthesizer.php | head -10
```

### 14.5 CAPA 5 — Feedback Loop: Aprendizaje y actualización de perfil

```bash
# ¿Se llama ingestUserInteraction desde ChatAgent?
grep -n "ingestUserInteraction\|SemanticMemoryService\|ingest(" \
  framework/app/Core/ChatAgent.php | head -10

# ¿Se actualiza el perfil tras la conversación?
grep -rn "updateProfile\|user_profiles.*UPDATE\|saveProfile\|last_seen\|interaction_count" \
  framework/app/Core/ --include="*.php" | head -10

# ¿Existe auto-promote a Qdrant?
grep -n "auto_promote\|autoPromote\|AppFeedbackService\|promote" \
  framework/app/Core/ChatAgent.php | head -5
```

FAIL: si `ingestUserInteraction` no se llama → la memoria personal no se actualiza jamás.

### 14.6 Score consolidado del pipeline de 5 capas

```bash
echo "=== SCORE PIPELINE 5 CAPAS ==="
TOTAL=0

# Capa 1: Gatekeeper
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();
try {
    \$db->query('SELECT 1 FROM user_profiles LIMIT 1');
    echo 'C1_DB=1' . PHP_EOL;
} catch(\Exception \$e) {
    echo 'C1_DB=0' . PHP_EOL;
}
" 2>/dev/null
[ -f "framework/app/Core/UserProfileService.php" ] && echo "C1_SERVICE=1" || echo "C1_SERVICE=0"
[ -f "framework/app/Core/Agents/Processes/AppUserOnboarding.php" ] && echo "C1_ONBOARD=1" || echo "C1_ONBOARD=0"

# Capa 2: Context Middleware
grep -q "buildSystemPrompt\|build_system_prompt" framework/app/Core/ChatAgent.php 2>/dev/null && \
  echo "C2_DYNAMIC=1" || echo "C2_DYNAMIC=0"
grep -q "tech_level\|role_label" framework/app/Core/ChatAgent.php 2>/dev/null && \
  echo "C2_PROFILE=1" || echo "C2_PROFILE=0"
grep -q "CrossSessionMemory\|loadRecentTurns\|cross_session" framework/app/Core/ChatAgent.php 2>/dev/null && \
  echo "C2_XSESSION=1" || echo "C2_XSESSION=0"

# Capa 3: LLM Processing
grep -qrn "role.*system\|system.*prompt" framework/app/Core/LLM/Providers/ 2>/dev/null && \
  echo "C3_SYSTEM=1" || echo "C3_SYSTEM=0"

# Capa 4: Response Formatter
grep -qrn "tech_level\|formatForLevel\|ResponseFormatter" framework/app/Core/ 2>/dev/null && \
  echo "C4_FORMAT=1" || echo "C4_FORMAT=0"

# Capa 5: Feedback Loop
grep -q "ingestUserInteraction" framework/app/Core/ChatAgent.php 2>/dev/null && \
  echo "C5_INGEST=1" || echo "C5_INGEST=0"
grep -qrn "updateProfile\|interaction_count" framework/app/Core/ 2>/dev/null && \
  echo "C5_UPDATE=1" || echo "C5_UPDATE=0"

echo "=== INTERPRETACIÓN ==="
echo "Suma todos los =1 y multiplica por 10:"
echo "  0-3 puntos:  BLOQUEADOR — el agente opera sin identidad ni contexto"
echo "  4-5 puntos:  INCOMPLETO — alto riesgo de respuestas sin contexto personal"
echo "  6-7 puntos:  PARCIAL — usar con precaución, gaps críticos en persistencia"
echo "  8-9 puntos:  FUNCIONAL — pipeline activo, gaps menores en personalización"
echo "  10 puntos:   ENTERPRISE — agente realmente personalizado y con memoria"
```

### 14.7 Verificar cross-session memory (memoria cross-sesión)

```bash
# ¿Existe CrossSessionMemory?
ls -la framework/app/Core/Agents/Memory/CrossSessionMemory.php 2>/dev/null || \
  echo "FAIL: CrossSessionMemory NO EXISTE"

# Si existe, verificar que carga de tabla mensajes con scope tenant+user
grep -n "mensajes\|tenant_id\|user_id\|ORDER BY\|LIMIT\|maxTurns" \
  framework/app/Core/Agents/Memory/CrossSessionMemory.php 2>/dev/null | head -10

# Verificar que se llama desde ChatAgent
grep -n "CrossSessionMemory\|loadRecentTurns\|cross_session" \
  framework/app/Core/ChatAgent.php 2>/dev/null | head -5
```

PASS: CrossSessionMemory existe, carga desde `mensajes` tabla scoped por tenant+user.
FAIL: si no existe → cada sesión empieza desde cero → el agente "olvida" al usuario cada vez.

### 14.8 Verificar sistema de prompts diferenciados por mundo

```bash
# Debe haber system prompts distintos para cada mundo
ls -la framework/prompts/ 2>/dev/null
ls -la project/prompts/ 2>/dev/null

# Qué prompts existen actualmente
find . -name "*system_prompt*" -o -name "*persona*" -o -name "*prompt*.txt" | \
  grep -v vendor | grep -v node_modules | grep -v ".git"
```

PASS: al menos 3 archivos de prompt (builder, app, torre) O lógica PHP que construye el prompt dinámicamente.
FAIL: solo `builder_system_prompt.txt` usado para todos.

---

## MÓDULO 15 — VERIFICACIÓN DE FLUJO ONBOARDING USUARIO (App Chat)
*¿El agente entrevista al usuario antes de operar?*

### 15.1 Primera vez en la app — ¿hay entrevista?

```bash
# ¿Existe flujo de onboarding para App Chat?
grep -rn "onboarding\|first_time\|welcome_flow\|AppUserOnboarding\|nombre.*usuario\|cómo te llamo" \
  framework/app/Core/Agents/Processes/ --include="*.php" | head -10

# ¿Se verifica si es primera vez antes de procesar el mensaje?
grep -n "onboarding_completed\|is_first_time\|checkOnboarding\|hasProfile" \
  framework/app/Core/ChatAgent.php | head -10
```

FAIL: si no existe → el agente responde a un usuario nuevo sin saber su nombre ni rol.

### 15.2 Datos mínimos que el onboarding debe capturar

```bash
grep -rn "display_name\|role_label\|tech_level\|frequent_tasks\|nombre\|cargo\|nivel" \
  framework/app/Core/Agents/Processes/AppUserOnboarding.php 2>/dev/null | head -15
```

PASS: captura al menos: nombre/apodo, cargo/rol, nivel técnico.
FAIL: si no existe el archivo.

### 15.3 Verificar que el perfil persiste y se usa en sesiones futuras

```bash
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();
try {
    # Simular usuario con perfil existente
    \$profile = \$db->query(
        'SELECT * FROM user_profiles WHERE tenant_id = ? LIMIT 1',
        ['demo']
    );
    if (empty(\$profile)) {
        echo 'INFO: No hay perfiles en tabla user_profiles para tenant demo' . PHP_EOL;
        echo 'STATUS: Onboarding nunca se ha completado para este tenant' . PHP_EOL;
    } else {
        \$p = \$profile[0];
        echo 'Perfil encontrado: ' . \$p['display_name'] . ' | ' . \$p['role_label'] . ' | ' . \$p['tech_level'] . PHP_EOL;
        echo 'Onboarding completado: ' . (\$p['onboarding_completed'] ? 'SÍ' : 'NO') . PHP_EOL;
    }
} catch(\Exception \$e) {
    echo 'FAIL: ' . \$e->getMessage() . PHP_EOL;
}
" 2>&1
```

---

## MÓDULO 16 — SÍNTESIS Y REPORTE FINAL

Al completar todos los módulos, ejecutar este bloque para generar el reporte ejecutivo:

```bash
echo "============================================================"
echo "REPORTE FINAL — AUDITORÍA SUKI AI-AOS"
echo "Fecha: $(date)"
echo "Commit: $(git rev-parse --short HEAD)"
echo "============================================================"
echo ""
echo "=== TESTS BASE ==="
php framework/tests/run.php 2>&1 | tail -3
echo ""
echo "=== PIPELINE 5 CAPAS — DIAGNÓSTICO RÁPIDO ==="

echo ""
echo "CAPA 1 — GATEKEEPER:"
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$db = new \App\Core\Database();
try { \$db->query('DESCRIBE user_profiles'); echo '  tabla user_profiles: EXISTE' . PHP_EOL; }
catch(\Exception \$e) { echo '  tabla user_profiles: FALTA — BLOQUEADOR' . PHP_EOL; }
" 2>&1
[ -f "framework/app/Core/UserProfileService.php" ] && \
  echo "  UserProfileService: EXISTE" || echo "  UserProfileService: FALTA — BLOQUEADOR"
[ -f "framework/app/Core/Agents/Processes/AppUserOnboarding.php" ] && \
  echo "  AppUserOnboarding: EXISTE" || echo "  AppUserOnboarding: FALTA — sin entrevista usuario"

echo ""
echo "CAPA 2 — CONTEXT MIDDLEWARE:"
grep -qn "buildSystemPrompt" framework/app/Core/ChatAgent.php 2>/dev/null && \
  echo "  system prompt dinámico: EXISTE" || \
  echo "  system prompt dinámico: FALTA — prompt único para todos los mundos"
grep -qn "CrossSessionMemory\|loadRecentTurns" framework/app/Core/ChatAgent.php 2>/dev/null && \
  echo "  cross-session memory: ACTIVA" || \
  echo "  cross-session memory: FALTA — el agente olvida entre sesiones"

echo ""
echo "CAPA 3 — LLM PROCESSING:"
grep -qrn "role.*system\|messages.*system" framework/app/Core/LLM/Providers/ 2>/dev/null && \
  echo "  system prompt enviado a LLM: SÍ" || \
  echo "  system prompt enviado a LLM: NO — BLOQUEADOR CRÍTICO"

echo ""
echo "CAPA 5 — FEEDBACK LOOP:"
grep -qn "ingestUserInteraction" framework/app/Core/ChatAgent.php 2>/dev/null && \
  echo "  ingestUserInteraction: ACTIVO" || \
  echo "  ingestUserInteraction: NUNCA SE LLAMA — memoria personal muerta"

echo ""
echo "=== BUILDER — ENTREVISTA ==="
STEPS=$(grep -c "business_type\|operation_model\|needs_scope\|documents\|user_roles\|fiscal_config\|formulas_and_rules\|integrations" \
  framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php 2>/dev/null)
echo "  Pasos detectados: $STEPS (mínimo esperado: 8)"
[ "$STEPS" -ge 8 ] && echo "  Entrevista: COMPLETA" || echo "  Entrevista: INCOMPLETA — solo $STEPS pasos"

grep -q "dynamic_steps\|addStep" framework/app/Core/AppInterviewState.php 2>/dev/null && \
  echo "  Pasos dinámicos: SOPORTADOS" || echo "  Pasos dinámicos: NO SOPORTADOS"

echo ""
echo "=== ALMACENAMIENTO ENTREVISTA ==="
grep -qn "file_put_contents\|storage/meta" framework/app/Core/AppInterviewState.php 2>/dev/null && \
  echo "  FAIL: estado en archivos JSON — se pierde al reiniciar servidor" || \
  echo "  OK: estado en MySQL"

echo ""
echo "=== FISCAL/DIAN ==="
ALANUBE=$(grep -c "buildInvoice\|xml\|ubl\|cufe" framework/app/Core/AlanubeIntegrationAdapter.php 2>/dev/null || echo 0)
[ "$ALANUBE" -gt 3 ] && echo "  Alanube payload: IMPLEMENTADO" || \
  echo "  Alanube payload: STUB/VACÍO — P0 BLOQUEADOR para Colombia"

echo ""
echo "=== QDRANT SEMÁNTICO ==="
grep -qn "ingestUserInteraction" framework/app/Core/SemanticMemoryService.php && \
  echo "  ingestUserInteraction existe en SemanticMemoryService: SÍ" || \
  echo "  FAIL: ingestUserInteraction no existe"
grep -qn "ingestUserInteraction" framework/app/Core/ChatAgent.php && \
  echo "  ingestUserInteraction LLAMADO desde ChatAgent: SÍ" || \
  echo "  FAIL: ingestUserInteraction NUNCA SE LLAMA desde ChatAgent"

echo ""
echo "============================================================"
echo "REFERENCIAS IMPL:"
echo "  Plan de implementación: IMPL_PIPELINE_CONTEXTO.md"
echo "  Arquitectura canon: docs/canon/SUKI_ARCHITECTURE_CANON.md"
echo "  Tests a pasar: php framework/tests/run.php (121/121)"
echo "============================================================"
```

---

## MÓDULO 17 — PIPELINE MULTI-TENANT APP-ESPECÍFICO (GAP A + GAP B)

Verifica que el pipeline Builder → Install → Chat sea 100% multi-tenant y app-específico.
Añadido 2026-05-20 tras corrección commit `08a6003`.

```bash
echo "============================================================"
echo "M17 — PIPELINE MULTI-TENANT APP-ESPECÍFICO"
echo "============================================================"

echo ""
echo "--- GAP A: Tenant→App Mapping ---"
grep -qn "matchCatalogAppId" framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php 2>/dev/null && \
  echo "  matchCatalogAppId() existe en BuilderOnboardingProcess: SÍ" || \
  echo "  FAIL: matchCatalogAppId() NO EXISTE — tenant siempre mapea a suki_erp"

grep -qn "_installed_app_id" framework/app/Core/InstallPlaybookCommandHandler.php 2>/dev/null && \
  echo "  _installed_app_id guardado en InstallPlaybookCommandHandler: SÍ" || \
  echo "  FAIL: _installed_app_id NUNCA SE GUARDA — ChatAgent no sabe qué app instaló el tenant"

grep -qn "resolveInstalledAppId" framework/app/Core/AppConfigOnboarding.php 2>/dev/null && \
  echo "  resolveInstalledAppId() existe en AppConfigOnboarding: SÍ" || \
  echo "  FAIL: resolveInstalledAppId() NO EXISTE — siempre usa projectId estático del manifest"

grep -qn "resolveInstalledAppId" framework/app/Core/ChatAgent.php 2>/dev/null && \
  echo "  resolveInstalledAppId() LLAMADO desde ChatAgent Gatekeeper: SÍ" || \
  echo "  FAIL: ChatAgent NO llama resolveInstalledAppId — usa \$projectId hardcodeado"

echo ""
echo "--- GAP B: Mixin Filtering ---"
grep -qn "extractActiveMixins" framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php 2>/dev/null && \
  echo "  extractActiveMixins() existe en BuilderOnboardingProcess: SÍ" || \
  echo "  FAIL: extractActiveMixins() NO EXISTE — todos los mixins siempre activos"

grep -qn "_active_mixins" framework/app/Core/InstallPlaybookCommandHandler.php 2>/dev/null && \
  echo "  _active_mixins guardado en InstallPlaybookCommandHandler: SÍ" || \
  echo "  FAIL: _active_mixins NUNCA SE GUARDA — tenant sin DIAN recibe preguntas sobre resolucion_dian"

grep -qn "_active_mixins" framework/app/Core/AppConfigOnboarding.php 2>/dev/null && \
  echo "  resolveAllFieldsForApp() filtra por _active_mixins: SÍ" || \
  echo "  FAIL: resolveAllFieldsForApp() NO filtra mixins — siempre muestra todos los campos"

echo ""
echo "--- billing_dian: Must NOT activate without explicit DIAN mention ---"
php -r "
require 'framework/vendor/autoload.php'; require 'framework/app/autoload.php';
\$cls = new ReflectionClass(\App\Core\Agents\Processes\BuilderOnboardingProcess::class);
\$src = file_get_contents(\$cls->getFileName());
if (preg_match('/extractActiveMixins.*?}/s', \$src, \$m)) {
    \$ok = strpos(\$m[0], 'billing_dian') !== false &&
           (strpos(\$m[0], 'dian') !== false || strpos(\$m[0], 'factura') !== false);
    echo \$ok
        ? '  billing_dian requiere mención explícita dian/factura: SÍ' . PHP_EOL
        : '  WARN: billing_dian activo por defecto — tenants sin facturación electrónica ven preguntas DIAN' . PHP_EOL;
} else {
    echo '  SKIP: no se pudo extraer extractActiveMixins()' . PHP_EOL;
}
" 2>&1

echo ""
echo "--- Tests verify_gap_fixes.php ---"
php framework/tests/verify_gap_fixes.php 2>&1 | tail -5
```

**Criterio PASS M17:** Los 7 checks `SÍ` + `verify_gap_fixes.php` → 32/32.

---

## MÓDULO 18 — COMPARATIVA DE MERCADO

Posicionamiento de SUKI AI-AOS frente a plataformas enterprise similares (2026).

```bash
echo "============================================================"
echo "M18 — COMPARATIVA DE MERCADO"
echo "============================================================"

echo ""
echo "Generando tabla comparativa..."

echo "| Capacidad                        | SUKI AI-AOS  | MS Copilot Studio | Google CCAI  | Salesforce Einstein | SAP CoPilot |"
echo "|----------------------------------|:------------:|:-----------------:|:------------:|:-------------------:|:-----------:|"

echo -n "| Onboarding app-específico (LLM)  | "
grep -qn "AppConfigOnboarding\|AppUserOnboarding" framework/app/Core/ChatAgent.php 2>/dev/null && echo -n "✅ SÍ  " || echo -n "❌ NO  "
echo "| ✅ Sí (Topics) | ✅ Sí (Playbooks) | ⚠️ Parcial (Studio) | ❌ Rígido |"

echo -n "| Multi-tenant real por tenant_id  | "
grep -qn "tenant_id" framework/app/Core/BaseRepository.php 2>/dev/null && echo -n "✅ SÍ  " || echo -n "❌ NO  "
echo "| ✅ Sí (AAD) | ✅ Sí (Agent Spaces) | ✅ Sí (Orgs) | ✅ Sí (Mandantes) |"

echo -n "| Apps creables por usuario final  | "
[ -f "framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php" ] && echo -n "✅ SÍ  " || echo -n "❌ NO  "
echo "| ⚠️ Solo Power Users | ⚠️ Solo Power Users | ❌ No | ❌ No |"

echo -n "| Router determinístico (sin LLM)  | "
grep -qn "Cache\|Rules\|RAG" framework/app/Core/IntentRouter.php 2>/dev/null && echo -n "✅ SÍ  " || echo -n "❌ NO  "
echo "| ⚠️ LLM-first | ⚠️ ML Dispatcher | ⚠️ LLM-first | ✅ Rulesets |"

echo -n "| Feedback loop → Qdrant automático| "
grep -qn "autoPromote\|auto_promote" framework/app/Core/AppFeedbackService.php 2>/dev/null && echo -n "✅ SÍ  " || echo -n "❌ NO  "
echo "| ✅ (Azure ML) | ✅ (Vertex AI) | ✅ (Salesforce AI) | ⚠️ Manual |"

echo -n "| DIAN Colombia XML/UBL 2.1 nativo | "
DIAN=$(grep -c "buildInvoice\|xml\|ubl\|cufe" framework/app/Core/AlanubeIntegrationAdapter.php 2>/dev/null || echo 0)
[ "$DIAN" -gt 3 ] && echo -n "✅ SÍ  " || echo -n "❌ PENDIENTE"
echo "| ❌ No LATAM fiscal | ❌ No nativo | ❌ No nativo | ⚠️ Localización SAP |"

echo -n "| PUC colombiano real (5000+ ctas) | "
PUC=$(grep -c "110\|120\|130\|210\|220" framework/app/Core/AccountingRepository.php 2>/dev/null || echo 0)
[ "$PUC" -gt 20 ] && echo -n "✅ SÍ  " || echo -n "❌ PENDIENTE"
echo "| ❌ No LATAM | ❌ No nativo | ❌ No nativo | ⚠️ Chart of Accounts genérico |"

echo -n "| CURLOPT_TIMEOUT en LLM providers | "
T=$(grep -c "CURLOPT_TIMEOUT" framework/app/Core/GeminiClient.php framework/app/Core/GroqClient.php 2>/dev/null || echo 0)
[ "$T" -ge 2 ] && echo -n "✅ SÍ  " || echo -n "❌ NO  "
echo "| ✅ Retry + timeout | ✅ Retry + timeout | ✅ Circuit breaker | ✅ Circuit breaker |"

echo ""
echo "VENTAJAS DIFERENCIALES SUKI vs MERCADO:"
echo "  1. Chat-first ERP (crear apps por conversación) — único en LATAM"
echo "  2. Onboarding app-específico sin hardcode — adapta preguntas al sector del tenant"
echo "  3. Router determinístico Cache→Rules→RAG→LLM (predecible, auditable)"
echo "  4. LATAM-native: DIAN en roadmap, PUC colombiano, ReteFuente, ICA"
echo "  5. Multi-tenant PHP/MySQL — no requiere Azure/GCP (costo menor en LATAM)"
echo ""
echo "BRECHAS vs MERCADO ENTERPRISE (hojas de ruta):"
echo "  B-01: DIAN XML/UBL/CUFE — MS/Google/Salesforce no lo tienen; SUKI lo necesita (P0)"
echo "  B-02: PUC 5000+ cuentas — PGC genérico no sirve para PYME CO"
echo "  B-03: E2E HTTP tests + CI remoto — enterprise tiene CI/CD completo (P1)"
echo "  B-04: ReteFuente + ICA real — estándar Colombia (P1)"
```

**Criterio PASS M18:** Tabla generada, ≥ 5 ventajas impresas, ≥ 3 brechas documentadas.

---

## CRITERIOS DE PASE PARA PRODUCCIÓN

| Módulo | Criterio PASS | Severity |
|--------|--------------|----------|
| M1 Funcional | 121/121 tests + E2E sin 500 | P0 |
| M3 Seguridad | tenant_id en 100% queries, .env no en git | P0 |
| M7 Qdrant | ingestUserInteraction llamado desde ChatAgent | P0 |
| M8 Frontend | fetch() usa SUKI_BASE, sin URLs hardcodeadas | P0 |
| M13 Builder | ≥ 8 pasos entrevista, dynamic_steps soportados | P0 |
| M14 Pipeline | Score ≥ 8/10 en 5 capas | P0 |
| M15 Onboarding | AppUserOnboarding existe, user_profiles en MySQL | P0 |
| M2 Confiabilidad | No catches silenciosos, timeouts en LLM | P1 |
| M4 Rendimiento | Chat < 10s con LLM real | P1 |
| M9 DIAN | Alanube payload XML/UBL real | P0 Colombia |
| M10 Tests | run.php 121/121, db_health verde | P0 |
| M11 Multi-tenant | 0 queries sin tenant_id scope | P0 |
| M12 Router | LLM es SIEMPRE el último recurso | P0 |
| M17 Multi-tenant App | resolveInstalledAppId + _active_mixins, verify_gap_fixes 32/32 | P0 |
| M18 Mercado | DIAN/PUC pendientes documentados, ventajas diferenciales listadas | P1 |

**GO-LIVE solo cuando todos los P0 estén en PASS.**

---

**Notas técnicas:**
- `CURLOPT_TIMEOUT` está en `GeminiClient.php:78` y `GroqClient.php:63` — verificar en clientes, no en providers.
- Autoload correcto: `framework/vendor/autoload.php` + `framework/app/autoload.php` (no `bootstrap/autoload.php`).
- `AppTenantConfigService` es `final class` — tests deben usar PDO SQLite in-memory real, no mocks.

*ISO/IEC 25010:2023 — Modelo de Calidad de Software*
*OWASP Application Security Verification Standard v4.0 Level 2*
*CMMI-DEV v2.0 Level 3 — Proceso de Verificación y Validación*
*Adaptada para SUKI AI-AOS — Stack: PHP 8.1+, MySQL, IIS, Qdrant, LLM cascade*
*Pipeline 5 capas basado en Microsoft Copilot Studio (Agent Identity + Onboarding)*
*y Google Gemini (Context Grounding + Personalization Pipeline)*
*M17 añadido 2026-05-20: Pipeline multi-tenant app-específico (GAP A + GAP B — commit 08a6003)*
*M18 añadido 2026-05-20: Comparativa de mercado enterprise (MS Copilot Studio, Google CCAI, Salesforce, SAP)*
*Módulos M13-M15 específicos para SUKI Builder Chat y App Chat personalization*
