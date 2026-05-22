# IMPLEMENTACIÓN: Pipeline de Contexto — Builder Chat y App Chat
## SUKI AI-AOS · Versión 2.0 · 2026-05-18
> Prompt de implementación ejecutable. Usa Claude Code desde la raíz del proyecto.
> Objetivo: SUKI como asistente personal real — con memoria, contexto y personalización.

---

## PRINCIPIO RECTOR DE ESTA IMPLEMENTACIÓN

> Un LLM sin contexto es aleatorio. Un LLM con el contexto correcto es un experto.
> SUKI debe saber QUIÉN habla, DE QUÉ empresa, CON QUÉ reglas, y QUÉ pasó antes
> — en CADA turno, en CUALQUIER sesión, en los DOS mundos (Builder y App).

**Invariantes que no se rompen:**
- Nunca raw SQL (usar Repository/QueryBuilder con tenant_id)
- Solo cambios aditivos (no renombrar columnas existentes)
- Contratos JSON son fuente de verdad
- PHP valida, LLM traduce — nunca al revés

---

## DIAGNÓSTICO CONFIRMADO (evidencia de código)

Antes de implementar, los gaps verificados en código real:

| Gap | Archivo:Línea | Evidencia |
|-----|--------------|-----------|
| Builder: solo 4 pasos, sin fórmulas/reglas | `BuilderOnboardingProcess.php:18-19` | `allowedSteps = ['business_type','operation_model','needs_scope','documents']` |
| No hay entrevista para usuario App Chat | `ChatAgent.php:734` | Usa `builder_system_prompt.txt` para TODOS los modos |
| Memoria muere al cerrar sesión | `ChatAgent.php:176,85` | `threadId = tenantId:sessionId` con session random cada vez |
| `AppInterviewState` en archivos JSON | `AppInterviewState.php:153` | `dirname(__DIR__,3).'/project/storage/meta/app_interviews'` |
| `ingestUserInteraction()` nunca llamado | `SemanticMemoryService.php:279` | Existe pero ningún caller en ChatAgent |
| Sin tabla `user_profiles` en DB | SQL verificado | No existe — agente siempre empieza sin identidad del usuario |
| System prompt igual para todos los roles | `ChatAgent.php:734` | Un solo archivo para cajero, contador, dueño, arquitecto |

---

## FASE 1 — INFRAESTRUCTURA DE PERFIL DE USUARIO

### 1.1 Crear tabla `user_profiles` en MySQL

Crear migration: `framework/scripts/apply_schema_migrations.php` (o ejecutar directo).

```sql
CREATE TABLE IF NOT EXISTS user_profiles (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     VARCHAR(64)  NOT NULL,
    user_id       VARCHAR(128) NOT NULL,
    world         ENUM('app','builder','torre') NOT NULL DEFAULT 'app',
    display_name  VARCHAR(128) DEFAULT '',
    role_label    VARCHAR(64)  DEFAULT '',
    tech_level    ENUM('basic','intermediate','advanced') DEFAULT 'basic',
    language_tone ENUM('formal','informal','mixed') DEFAULT 'informal',
    frequent_tasks JSON        DEFAULT NULL,
    business_name VARCHAR(128) DEFAULT '',
    sector        VARCHAR(64)  DEFAULT '',
    custom_prefs  JSON        DEFAULT NULL,
    onboarding_completed_at DATETIME DEFAULT NULL,
    last_seen_at  DATETIME    DEFAULT NULL,
    created_at    DATETIME    DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_profile (tenant_id, user_id, world),
    KEY idx_tenant (tenant_id),
    KEY idx_tenant_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.2 Crear `UserProfileService.php`

**Archivo:** `framework/app/Core/UserProfileService.php`

```php
<?php
declare(strict_types=1);

namespace App\Core;

/**
 * UserProfileService
 *
 * Persiste y carga el perfil de usuario por mundo (app / builder / torre).
 * El perfil alimenta el system prompt para que el agente sea un asistente
 * personalizado — sabe el nombre, rol, nivel técnico y preferencias del usuario.
 *
 * Tabla: user_profiles (MySQL, tenant_id + user_id + world = unique)
 */
final class UserProfileService
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    public function load(string $tenantId, string $userId, string $world = 'app'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_profiles WHERE tenant_id=? AND user_id=? AND world=? LIMIT 1'
        );
        $stmt->execute([$tenantId, $userId, $world]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) return [];

        foreach (['frequent_tasks', 'custom_prefs'] as $col) {
            if (isset($row[$col]) && is_string($row[$col])) {
                $row[$col] = json_decode($row[$col], true) ?? [];
            }
        }
        return $row;
    }

    public function save(string $tenantId, string $userId, string $world, array $data): void
    {
        foreach (['frequent_tasks', 'custom_prefs'] as $col) {
            if (isset($data[$col]) && is_array($data[$col])) {
                $data[$col] = json_encode($data[$col], JSON_UNESCAPED_UNICODE);
            }
        }
        $data['tenant_id'] = $tenantId;
        $data['user_id']   = $userId;
        $data['world']     = $world;

        $cols  = implode(',', array_map(fn($k) => "`$k`", array_keys($data)));
        $vals  = implode(',', array_fill(0, count($data), '?'));
        $upd   = implode(',', array_map(
            fn($k) => "`$k`=VALUES(`$k`)",
            array_diff(array_keys($data), ['tenant_id', 'user_id', 'world'])
        ));
        $this->pdo->prepare(
            "INSERT INTO user_profiles ($cols) VALUES ($vals)
             ON DUPLICATE KEY UPDATE $upd, updated_at=NOW()"
        )->execute(array_values($data));
    }

    public function markOnboardingComplete(string $tenantId, string $userId, string $world): void
    {
        $this->pdo->prepare(
            'UPDATE user_profiles SET onboarding_completed_at=NOW(), updated_at=NOW()
             WHERE tenant_id=? AND user_id=? AND world=?'
        )->execute([$tenantId, $userId, $world]);
    }

    public function touchLastSeen(string $tenantId, string $userId, string $world): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_profiles (tenant_id, user_id, world, last_seen_at)
             VALUES (?,?,?,NOW())
             ON DUPLICATE KEY UPDATE last_seen_at=NOW()'
        )->execute([$tenantId, $userId, $world]);
    }

    public function isOnboardingComplete(string $tenantId, string $userId, string $world): bool
    {
        $profile = $this->load($tenantId, $userId, $world);
        return !empty($profile['onboarding_completed_at']);
    }

    /**
     * Construye el bloque de contexto del usuario para inyectar en el system prompt.
     */
    public function buildContextBlock(string $tenantId, string $userId, string $world): string
    {
        $p = $this->load($tenantId, $userId, $world);
        if (empty($p)) return '';

        $name    = $p['display_name']  ?? 'Usuario';
        $role    = $p['role_label']    ?? '';
        $tech    = $p['tech_level']    ?? 'basic';
        $tone    = $p['language_tone'] ?? 'informal';
        $biz     = $p['business_name'] ?? '';
        $sector  = $p['sector']        ?? '';
        $tasks   = is_array($p['frequent_tasks'] ?? null)
                   ? implode(', ', $p['frequent_tasks']) : '';

        $techLabel = match($tech) {
            'advanced'     => 'usuario técnico avanzado — puedes usar términos precisos',
            'intermediate' => 'usuario con conocimiento medio del sistema',
            default        => 'usuario no técnico — usa lenguaje simple y ejemplos cotidianos',
        };
        $toneLabel = match($tone) {
            'formal'   => 'tono formal y profesional',
            'informal' => 'tono cercano e informal (tutear)',
            default    => 'tono natural y adaptado',
        };

        $lines = ["## Perfil del Usuario Activo", ""];
        $lines[] = "- Nombre: **{$name}**" . ($role !== '' ? " | Rol: **{$role}**" : '');
        if ($biz !== '') $lines[] = "- Empresa: {$biz}" . ($sector !== '' ? " | Sector: {$sector}" : '');
        $lines[] = "- Nivel técnico: {$techLabel}";
        $lines[] = "- Comunicación: {$toneLabel}";
        if ($tasks !== '') $lines[] = "- Tareas frecuentes: {$tasks}";
        $lines[] = "";
        $lines[] = "INSTRUCCIÓN: Dirígete a este usuario por su nombre cuando sea natural. "
                 . "Adapta SIEMPRE la complejidad del lenguaje a su nivel técnico. "
                 . "Nunca expliques qué es una base de datos o una API a un usuario básico — usa analogías de negocio.";

        return implode("\n", $lines);
    }
}
```

---

## FASE 2 — ENTREVISTA DE ONBOARDING PARA APP CHAT

### 2.1 Crear `AppUserOnboarding.php`

**Archivo:** `framework/app/Core/AppUserOnboarding.php`

Este servicio gestiona la entrevista de 4 preguntas para el usuario de App Chat cuando llega por primera vez. El objetivo es personalizar el asistente antes del primer turno real.

```php
<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AppUserOnboarding
 *
 * Entrevista inicial para usuarios del App Chat (modo 'app').
 * Captura: nombre, rol, nivel técnico, tareas frecuentes.
 * 4 turnos máximo. Almacena en user_profiles (MySQL).
 *
 * Diseño: UNA pregunta por turno, nunca lista de preguntas.
 * El usuario no sabe de tecnología — no se menciona "perfil", "DB", "sistema".
 */
final class AppUserOnboarding
{
    private const STEPS = [
        'name'            => '¿Cómo te llamo? (tu nombre o apodo)',
        'role'            => '¿Cuál es tu papel en la empresa? (dueño, cajero, contador, vendedor...)',
        'frequent_tasks'  => '¿Qué vas a hacer más seguido aquí? Por ejemplo: registrar ventas, ver reportes, facturar...',
        'tech_level'      => '¿Qué tan cómodo te sientes con sistemas digitales? (poco / regular / bastante)',
    ];

    private UserProfileService $profiles;

    public function __construct(?UserProfileService $profiles = null)
    {
        $this->profiles = $profiles ?? new UserProfileService();
    }

    /**
     * Retorna true si el usuario ya completó el onboarding.
     */
    public function isComplete(string $tenantId, string $userId): bool
    {
        return $this->profiles->isOnboardingComplete($tenantId, $userId, 'app');
    }

    /**
     * Procesa el turno actual. Si es el primer turno, devuelve la primera pregunta.
     * Si el usuario responde, guarda el dato, avanza y devuelve la siguiente pregunta.
     * Cuando completa todos los pasos, marca onboarding completo y retorna null.
     *
     * @return string|null Mensaje a mostrar al usuario, null si completó.
     */
    public function processTurn(
        string $tenantId,
        string $userId,
        string $userMessage,
        string $sessionId
    ): ?string {
        $profile  = $this->profiles->load($tenantId, $userId, 'app');
        $step     = $this->currentStep($profile);

        if ($step === null) {
            $this->profiles->markOnboardingComplete($tenantId, $userId, 'app');
            return null; // Onboarding completo
        }

        // Primer turno: no hay userMessage relevante aún — devolver primera pregunta
        if (empty($profile)) {
            $this->profiles->touchLastSeen($tenantId, $userId, 'app');
            return $this->greeting() . ' ' . self::STEPS[$step];
        }

        // Guardar la respuesta del turno anterior
        $this->saveAnswer($tenantId, $userId, $step, $userMessage, $profile);

        // Avanzar al siguiente paso
        $steps    = array_keys(self::STEPS);
        $idx      = array_search($step, $steps, true);
        $nextStep = $steps[$idx + 1] ?? null;

        if ($nextStep === null) {
            $this->profiles->markOnboardingComplete($tenantId, $userId, 'app');
            $name = $this->profiles->load($tenantId, $userId, 'app')['display_name'] ?? 'allí';
            return "¡Perfecto, {$name}! Ya te conozco. ¿En qué te ayudo hoy?";
        }

        return self::STEPS[$nextStep];
    }

    private function currentStep(array $profile): ?string
    {
        if (empty($profile)) return 'name';

        foreach (array_keys(self::STEPS) as $step) {
            if ($this->isStepMissing($step, $profile)) return $step;
        }
        return null;
    }

    private function isStepMissing(string $step, array $profile): bool
    {
        return match($step) {
            'name'           => empty($profile['display_name']),
            'role'           => empty($profile['role_label']),
            'frequent_tasks' => empty($profile['frequent_tasks']),
            'tech_level'     => empty($profile['tech_level']),
            default          => false,
        };
    }

    private function saveAnswer(
        string $tenantId, string $userId, string $step,
        string $answer, array $profile
    ): void {
        $update = match($step) {
            'name'  => ['display_name' => trim($answer)],
            'role'  => ['role_label'   => $this->normalizeRole($answer)],
            'frequent_tasks' => ['frequent_tasks' => $this->extractTasks($answer)],
            'tech_level'     => ['tech_level' => $this->normalizeTechLevel($answer)],
            default => [],
        };
        if (!empty($update)) {
            $this->profiles->save($tenantId, $userId, 'app', $update);
        }
    }

    private function normalizeRole(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $map  = [
            'dueño' => 'Dueño', 'dueno' => 'Dueño', 'propietario' => 'Dueño', 'gerente' => 'Gerente',
            'cajero' => 'Cajero', 'cajera' => 'Cajera', 'vendedor' => 'Vendedor', 'vendedora' => 'Vendedora',
            'contador' => 'Contador', 'contadora' => 'Contadora', 'admin' => 'Administrador',
            'administrador' => 'Administrador', 'bodeguero' => 'Bodeguero',
        ];
        foreach ($map as $k => $v) {
            if (str_contains($text, $k)) return $v;
        }
        return ucfirst($text);
    }

    private function extractTasks(string $text): array
    {
        $keywords = ['ventas', 'facturas', 'reportes', 'inventario', 'compras', 'clientes',
                     'productos', 'pagos', 'cotizaciones', 'gastos', 'nomina', 'nómina'];
        $found = [];
        $lower = mb_strtolower($text);
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) $found[] = $kw;
        }
        return $found ?: [trim($text)];
    }

    private function normalizeTechLevel(string $text): string
    {
        $lower = mb_strtolower($text);
        if (preg_match('/mucho|bastante|avanzado|experto|bien/u', $lower)) return 'advanced';
        if (preg_match('/regular|medio|algo|moderado/u', $lower)) return 'intermediate';
        return 'basic';
    }

    private function greeting(): string
    {
        $h = (int) date('G');
        if ($h < 12) return '¡Buenos días!';
        if ($h < 18) return '¡Buenas tardes!';
        return '¡Buenas noches!';
    }
}
```

---

## FASE 3 — ENTREVISTA EXTENDIDA PARA BUILDER CHAT

### 3.1 Ampliar `BuilderOnboardingProcess` — 8 pasos + pasos dinámicos

**Archivo a modificar:** `framework/app/Core/Agents/Processes/BuilderOnboardingProcess.php`

Cambiar `$allowedSteps` de 4 a 8 pasos fijos + soporte de pasos dinámicos:

```php
// REEMPLAZAR el array estático por uno configurable
private array $coreSteps = [
    'business_type',
    'operation_model',
    'needs_scope',
    'documents',
    'user_roles',         // NUEVO: ¿quiénes usan el sistema y qué pueden hacer?
    'fiscal_config',      // NUEVO: IVA, ReteFuente, ICA, régimen tributario
    'formulas_and_rules', // NUEVO: fórmulas de precio, descuentos, comisiones, reglas
    'integrations',       // NUEVO: DIAN/Alanube, bancos, WooCommerce, Tienda Nube
];
```

**Agregar método de pasos dinámicos:**
```php
/**
 * El agente puede crear nuevos pasos en tiempo real si detecta
 * que el negocio tiene una necesidad no cubierta por los pasos base.
 *
 * Ejemplo: usuario menciona "liquidación de inventario" → crear paso 'liquidation_rules'
 */
public function addDynamicStep(
    string $tenantId, string $sessionId, string $stepKey, string $stepQuestion
): void {
    $state = $this->loadState($tenantId, $sessionId);
    $dynamicSteps = $state['dynamic_steps'] ?? [];
    if (!in_array($stepKey, array_column($dynamicSteps, 'key'), true)) {
        $dynamicSteps[] = ['key' => $stepKey, 'question' => $stepQuestion, 'added_at' => date('c')];
        $state['dynamic_steps'] = $dynamicSteps;
        $this->saveState($tenantId, $sessionId, $state);
    }
}

public function getAllSteps(string $tenantId, string $sessionId): array
{
    $state  = $this->loadState($tenantId, $sessionId);
    $dynamic = array_column($state['dynamic_steps'] ?? [], 'key');
    return array_merge($this->coreSteps, $dynamic);
}
```

### 3.2 Preguntas guía por paso — extender `getStepQuestion()`

```php
private function getStepQuestion(string $step): string
{
    $questions = [
        // Pasos originales
        'business_type'    => '¿A qué se dedica el negocio? (ej: ferretería, veterinaria, restaurante)',
        'operation_model'  => '¿Cómo manejas los pagos? (solo contado / crédito / los dos)',
        'needs_scope'      => '¿Qué quieres controlar primero? (inventario, clientes, facturación, etc.)',
        'documents'        => '¿Qué documentos usas? (factura, cotización, orden de trabajo, remisión...)',

        // Pasos nuevos
        'user_roles'       => '¿Quiénes van a usar el sistema? Cuéntame los cargos (dueño, cajero, contador) y qué puede hacer cada uno.',
        'fiscal_config'    => '¿Tu negocio cobra IVA? ¿Eres responsable de IVA o régimen simple? ¿Aplicas ReteFuente o ICA a tus clientes?',
        'formulas_and_rules' => '¿Tienes reglas especiales de precio o descuento? Por ejemplo: descuento por volumen, comisión de vendedores, precio especial para clientes frecuentes.',
        'integrations'     => '¿Necesitas emitir facturas electrónicas DIAN? ¿Conectar con alguna tienda virtual (WooCommerce, Tienda Nube) o banco?',
    ];
    return $questions[$step] ?? "Cuéntame más sobre este aspecto de tu negocio.";
}
```

### 3.3 Migrar `AppInterviewState` de JSON files a MySQL

**Crear tabla:**
```sql
CREATE TABLE IF NOT EXISTS builder_interview_state (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    VARCHAR(64)  NOT NULL,
    session_id   VARCHAR(128) NOT NULL,
    app_id       VARCHAR(128) NOT NULL DEFAULT '',
    phase        VARCHAR(32)  NOT NULL DEFAULT 'intro',
    business_name VARCHAR(128) DEFAULT '',
    gathered_info JSON         DEFAULT NULL,
    gathered_text TEXT         DEFAULT NULL,
    dynamic_steps JSON         DEFAULT NULL,
    schema_draft  JSON         DEFAULT NULL,
    security_draft JSON        DEFAULT NULL,
    applied_mixins JSON        DEFAULT NULL,
    rounds       SMALLINT     DEFAULT 0,
    confirmed    TINYINT(1)   DEFAULT 0,
    developer_instructions TEXT DEFAULT NULL,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_interview (tenant_id, session_id),
    KEY idx_tenant (tenant_id),
    KEY idx_app (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Actualizar `AppInterviewState.php`** para usar MySQL como backend primario con fallback a JSON files para compatibilidad durante la migración.

### 3.4 Captura estructurada de fórmulas y reglas

Al procesar el paso `formulas_and_rules`, el agente usa LLM para extraer fórmulas en formato estructurado y las guarda en `gathered_info.business_rules`:

```json
{
  "business_rules": [
    {
      "name": "comision_vendedor",
      "type": "formula",
      "description": "3% sobre precio neto menos devoluciones",
      "formula": "commission = (net_price - returns) * 0.03",
      "applies_to": "seller_role",
      "variables": ["net_price", "returns"],
      "constants": {"rate": 0.03}
    },
    {
      "name": "descuento_volumen",
      "type": "rule",
      "description": "10% de descuento si compra más de 10 unidades",
      "condition": "quantity > 10",
      "action": "price = price * 0.90"
    }
  ]
}
```

El system prompt del Builder inyecta estas reglas para que el LLM las incluya en el schema de la app.

---

## FASE 4 — MEMORIA CROSS-SESIÓN (usuario siempre recordado)

### 4.1 Problema confirmado

`threadId = tenantId + ':' + sessionId` — al crear nueva sesión, `history = []`.

### 4.2 Solución: `CrossSessionMemory`

**Archivo:** `framework/app/Core/CrossSessionMemory.php`

```php
<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CrossSessionMemory
 *
 * Carga los N últimos turnos del usuario a través de TODAS sus sesiones.
 * Permite que el agente diga "la última vez hablamos de X" aunque sea
 * una sesión nueva.
 *
 * Implementación: tabla mensajes (ya existe) filtrada por tenant_id + user_id.
 * Sin Qdrant para cold-start. Qdrant se usa para búsqueda semántica adicional.
 */
final class CrossSessionMemory
{
    private const DEFAULT_TURNS = 10;

    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Devuelve los últimos N turnos del usuario (de cualquier sesión).
     * Formato: [['role' => 'user'|'assistant', 'content' => '...'], ...]
     */
    public function loadRecentTurns(
        string $tenantId, string $userId,
        int $maxTurns = self::DEFAULT_TURNS
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT role, content, created_at
             FROM mensajes
             WHERE tenant_id = ? AND user_id = ?
             ORDER BY created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$tenantId, $userId, $maxTurns * 2]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Devolver en orden cronológico (más viejo primero)
        return array_reverse(array_map(fn($r) => [
            'role'    => $r['role'],
            'content' => $r['content'],
        ], $rows));
    }

    /**
     * Construye el bloque de contexto histórico para el system prompt.
     * Se inyecta como "contexto de sesiones anteriores" antes del historial actual.
     */
    public function buildHistoryContextBlock(
        string $tenantId, string $userId,
        int $maxTurns = self::DEFAULT_TURNS
    ): string {
        $turns = $this->loadRecentTurns($tenantId, $userId, $maxTurns);
        if (empty($turns)) return '';

        $lines = ["## Contexto de conversaciones anteriores (últimos {$maxTurns} turnos)", ""];
        foreach ($turns as $t) {
            $prefix = $t['role'] === 'user' ? 'Usuario' : 'SUKI';
            $lines[] = "**{$prefix}**: " . mb_substr($t['content'], 0, 200);
        }
        $lines[] = "";
        $lines[] = "INSTRUCCIÓN: Usa este historial como contexto. Si el usuario menciona algo de una "
                 . "conversación anterior, reconócelo naturalmente. No repitas todo el historial al usuario.";

        return implode("\n", $lines);
    }
}
```

### 4.3 Conectar `ingestUserInteraction()` desde ChatAgent

En `ChatAgent.php`, al final de `handleLlmRequest()` (después de guardar el mensaje del asistente), agregar:

```php
// Auto-ingest: construir user_memory en Qdrant para búsqueda semántica futura
try {
    if ($this->semanticMemory !== null && SemanticMemoryService::isEnabledFromEnv()) {
        $this->semanticMemory->ingestUserInteraction(
            $tenantId, $userId,
            "Usuario: {$text}\nSUKI: {$assistantReply}",
            ['session_id' => $sessionId, 'intent' => $telemetry['classification'] ?? 'unknown',
             'mode' => $mode, 'world' => $channel]
        );
    }
} catch (\Throwable $ignored) {
    // La memoria semántica nunca bloquea la respuesta al usuario
}
```

---

## FASE 5 — SYSTEM PROMPTS DIFERENCIADOS POR ROL Y MUNDO

### 5.1 Estructura de archivos de system prompt

```
framework/prompts/
├── builder_system_prompt.txt        (existente — para creadores de apps)
├── app_system_prompt_base.txt       (NUEVO — base para todos los usuarios de app)
├── app_system_prompt_admin.txt      (NUEVO — dueño / administrador)
├── app_system_prompt_seller.txt     (NUEVO — vendedor / cajero)
├── app_system_prompt_accountant.txt (NUEVO — contador / financiero)
├── app_system_prompt_guest.txt      (NUEVO — solo lectura, sin ejecutar acciones)
└── torre_system_prompt.txt          (NUEVO — operador de Torre de Control)
```

### 5.2 Lógica de selección en `ChatAgent.php`

**Reemplazar** la línea:
```php
$systemPrompt = @file_get_contents(dirname(__DIR__, 2) . '/prompts/builder_system_prompt.txt')
    ?: "Eres SUKI. Responde breve y claro.";
```

**Por:**
```php
$systemPrompt = $this->buildSystemPrompt($mode, $role, $resolvedTenantId, $userId, $sessionId);
```

**Nuevo método `buildSystemPrompt()`:**
```php
private function buildSystemPrompt(
    string $mode, string $role,
    string $tenantId, string $userId, string $sessionId
): string {
    $promptsDir = dirname(__DIR__, 2) . '/prompts';

    // --- Selección del prompt base según mundo y rol ---
    if ($mode === 'builder') {
        $base = @file_get_contents("{$promptsDir}/builder_system_prompt.txt") ?: '';
    } elseif ($mode === 'torre' || $role === 'architect') {
        $base = @file_get_contents("{$promptsDir}/torre_system_prompt.txt")
             ?: @file_get_contents("{$promptsDir}/builder_system_prompt.txt") ?: '';
    } else {
        // App Chat: seleccionar por rol
        $roleFile = match($role) {
            'admin', 'owner'      => "{$promptsDir}/app_system_prompt_admin.txt",
            'seller'              => "{$promptsDir}/app_system_prompt_seller.txt",
            'accountant'          => "{$promptsDir}/app_system_prompt_accountant.txt",
            'guest'               => "{$promptsDir}/app_system_prompt_guest.txt",
            default               => "{$promptsDir}/app_system_prompt_base.txt",
        };
        $base = @file_get_contents($roleFile)
             ?: @file_get_contents("{$promptsDir}/app_system_prompt_base.txt") ?: '';
    }

    // --- Capa 2A: Inyectar perfil del usuario ---
    try {
        $profileSvc = new UserProfileService();
        $world = $mode === 'builder' ? 'builder' : 'app';
        $userCtx = $profileSvc->buildContextBlock($tenantId, $userId, $world);
        if ($userCtx !== '') {
            $base .= "\n\n---\n" . $userCtx . "\n---";
        }
    } catch (\Throwable $ignored) {}

    // --- Capa 2B: Inyectar memoria cross-sesión ---
    try {
        $crossMemory = new CrossSessionMemory();
        $historyCtx  = $crossMemory->buildHistoryContextBlock($tenantId, $userId);
        if ($historyCtx !== '') {
            $base .= "\n\n---\n" . $historyCtx . "\n---";
        }
    } catch (\Throwable $ignored) {}

    // --- Capa 2C: Inyectar contexto de app existente (si hay interview activa) ---
    try {
        $interviewState = new AppInterviewState();
        $activeInterview = $interviewState->load($tenantId, $sessionId);
        if (!empty($activeInterview['developer_instructions'])) {
            $base .= "\n\n---\n" . $activeInterview['developer_instructions'] . "\n---";
        } elseif (!empty($activeInterview['app_id'])) {
            $appMemory = new AppMemoryService();
            $devCtx = $appMemory->buildDeveloperContext($tenantId, (string) $activeInterview['app_id']);
            if ($devCtx !== '') $base .= "\n\n---\n" . $devCtx . "\n---";
        }
    } catch (\Throwable $ignored) {}

    // --- Capa 2D: Inyectar bitácora del arquitecto (builder mode) ---
    if ($mode === 'builder') {
        try {
            $journalCtx = (new AgentJournalService())->buildContextBlock($tenantId, $projectId ?? '', 'architect', $sessionId);
            if ($journalCtx !== '') {
                $base .= "\n\n---\nBITÁCORA DE ARQUITECTURA:\n" . $journalCtx . "\n---";
            }
        } catch (\Throwable $ignored) {}
    }

    // --- Capa 2E: Inyectar persona especialista (Qdrant-classified skill) ---
    $specialistArea = $this->resolveSpecialistArea($telemetry ?? [], $mode);
    if ($specialistArea !== null) {
        try {
            $persona = SpecialistPersonas::getPersonaForTenant($specialistArea, $tenantId);
            $personaPrompt = trim((string) ($persona['prompt_base'] ?? ''));
            if ($personaPrompt !== '') $base = $personaPrompt . "\n\n" . $base;
        } catch (\Throwable $ignored) {}
    }

    return $base !== '' ? $base : "Eres SUKI, asistente de negocios. Responde breve y útil.";
}
```

---

## FASE 6 — INTEGRAR EL GATEKEEPER EN `ChatAgent.handle()`

### 6.1 Flujo correcto del Gatekeeper (antes del LLM)

Insertar en `ChatAgent.handle()` DESPUÉS de la resolución de identidad (línea ~148) y ANTES de la llamada al gateway:

```php
// === GATEKEEPER — Verificar perfil antes de procesar ===
if ($mode === 'app' && !$testMode) {
    $profileSvc  = new UserProfileService();
    $onboardSvc  = new AppUserOnboarding($profileSvc);

    if (!$onboardSvc->isComplete($resolvedTenantId, $userId)) {
        $onboardReply = $onboardSvc->processTurn($resolvedTenantId, $userId, $text, $sessionId);
        if ($onboardReply !== null) {
            $memory->append($threadId, 'assistant', $onboardReply);
            return $this->reply($onboardReply, $channel, $sessionId, $userId, 'success',
                ['onboarding' => true, 'step' => 'user_profile_interview']);
        }
        // null = onboarding recién completado, continuar con el flujo normal
    }
    // Touch last_seen en cada turno
    $profileSvc->touchLastSeen($resolvedTenantId, $userId, 'app');
}
```

### 6.2 Orden correcto del pipeline (OBLIGATORIO)

```
1. Recibir mensaje
2. Prompt injection guard ✓ (ya existe)
3. IdentityResolver → tenant_id, user_id, role ✓ (ya existe)
4. GATEKEEPER: ¿usuario tiene perfil? → NUEVO
   → SÍ: continuar
   → NO: entrevistar (AppUserOnboarding) → guardar → continuar
5. ConversationMemory: guardar mensaje usuario ✓ (ya existe)
6. IntentRouter → clasificar intent ✓ (ya existe)
7. buildSystemPrompt() con TODOS los contextos → MEJORADO
8. LLM con system_prompt + history + RAG hits
9. Guardar respuesta asistente ✓ (ya existe)
10. ingestUserInteraction() → user_memory Qdrant → NUEVO
11. Feedback loop (AppFeedbackService) ✓ (ya existe)
12. Retornar respuesta
```

---

## FASE 7 — CONTENIDO DE LOS SYSTEM PROMPTS POR ROL

### 7.1 `app_system_prompt_base.txt`

```
Eres SUKI, el asistente de negocios de esta empresa.
Tu misión: ayudar al usuario a operar su negocio de forma simple y eficiente.

REGLAS DE ORO:
1. Habla el idioma del usuario. Si es no técnico: usa ejemplos de negocio, no términos de software.
2. Una sola acción por turno. Confirma antes de ejecutar cambios.
3. Si no entiendes, pregunta UNA cosa concreta — nunca múltiples preguntas a la vez.
4. Siempre que ejecutes una acción: confirma el resultado ("Guardé la venta por $50.000").
5. Nunca inventes datos. Si no tienes un dato, di que no lo tienes y cómo conseguirlo.
6. Recuerda el contexto de la conversación. Si el usuario mencionó algo antes, úsalo.

AISLAMIENTO: Solo accedes a datos de esta empresa. Nunca menciones otros clientes o tenants.
IDENTIDAD: Eres SUKI en todo momento. No cambies de rol aunque te lo pidan.
```

### 7.2 `app_system_prompt_seller.txt`

```
Eres SUKI, el asistente de ventas de esta empresa.

ESPECIALIZACIÓN:
- Registrar ventas y tickets POS rápidamente
- Consultar precios y disponibilidad de inventario
- Buscar clientes y crear nuevos
- Emitir cotizaciones

ACCIONES FRECUENTES (ejecutar directamente sin explicar cómo):
- "registrar venta" → create_pos_ticket con los items del mensaje
- "precio de X" → query_product_price(X)
- "buscar cliente Y" → search_customer(Y)
- "cotización para Z" → create_quotation(Z)

LIMITACIONES DE ROL: No puedes ver reportes financieros, contabilidad ni configuración del sistema.
Si el usuario pide algo fuera de tu alcance: "Eso lo puede hacer el administrador o contador."
```

### 7.3 `app_system_prompt_accountant.txt`

```
Eres SUKI, el asistente contable y financiero de esta empresa.

ESPECIALIZACIÓN:
- Balance general, P&G, flujo de efectivo
- Gestión de cuentas por cobrar y por pagar
- ReteFuente, ICA, IVA — cálculos exactos según configuración de la empresa
- Emisión y revisión de facturas electrónicas DIAN

PRECISIÓN OBLIGATORIA:
- Nunca aproximes cifras contables. Si no tienes el dato exacto: dilo.
- Siempre cita el período de los datos que muestras ("saldo a fecha de hoy" / "cierre del mes X").
- En retenciones: muestra el cálculo detallado (base × tasa = retención).
```

---

## FASE 8 — PASOS DINÁMICOS EN EL BUILDER (agente crea nuevos pasos)

### 8.1 Detección de necesidades no cubiertas

En el sistema prompt del Builder, agregar instrucción:

```
DETECCIÓN DE PASOS ADICIONALES:
Si el usuario menciona una necesidad de negocio que NO está en los pasos actuales,
DEBES crear un nuevo paso dinámico usando la herramienta `add_interview_step`.

Ejemplos que generan nuevos pasos:
- "También manejo torneos" → paso: 'tournament_rules'
- "Tengo crédito rotativo con mis clientes" → paso: 'credit_rotation_rules'
- "Manejo lotes y fechas de vencimiento" → paso: 'batch_tracking_config'
- "Trabajo con consignación" → paso: 'consignment_rules'

Formato del nuevo paso:
{
  "key": "nombre_snake_case",
  "question": "Pregunta específica en lenguaje de negocio (no técnico)"
}
```

### 8.2 Tool definition para pasos dinámicos

Agregar en el catálogo de tools del Builder:

```json
{
  "name": "add_interview_step",
  "description": "Agrega un nuevo paso a la entrevista cuando el agente detecta una necesidad de negocio no cubierta por los pasos estándar",
  "parameters": {
    "type": "object",
    "properties": {
      "step_key":    { "type": "string", "description": "Identificador snake_case del paso" },
      "question":    { "type": "string", "description": "Pregunta para el usuario en lenguaje de negocio" },
      "reason":      { "type": "string", "description": "Por qué se añade este paso" }
    },
    "required": ["step_key", "question"]
  }
}
```

---

## FASE 9 — VERIFICACIÓN FINAL

Después de implementar todas las fases, ejecutar:

```bash
# Tests existentes — no deben romperse
php framework/tests/run.php
echo "EXIT: $?"

# Test de perfil nuevo usuario
php framework/tests/api_route_turn.php "$(echo '{"route":"chat","method":"POST","payload":{"message":"hola","tenant_id":"test_new_user_profile_001"},"session":{"auth_user":{"tenant_id":"test_new_user_profile_001","id":"new_user_x","role":"admin"}}}' | base64 -w0)"
# ESPERADO: respuesta con pregunta de onboarding (nombre o saludo)

# Test de persistencia cross-session
php framework/tests/api_route_turn.php "$(echo '{"route":"chat","method":"POST","payload":{"message":"me llamo Juan, soy cajero","tenant_id":"test_cross_mem"},"session":{"auth_user":{"tenant_id":"test_cross_mem","id":"user_juan","role":"seller"}}}' | base64 -w0)"
sleep 2
php framework/tests/api_route_turn.php "$(echo '{"route":"chat","method":"POST","payload":{"message":"recuerdas cómo me llamo?","tenant_id":"test_cross_mem","session_id":"new_session_999"},"session":{"auth_user":{"tenant_id":"test_cross_mem","id":"user_juan","role":"seller"}}}' | base64 -w0)"
# ESPERADO: respuesta menciona "Juan"

# Test Builder con pasos extendidos
php framework/tests/api_route_turn.php "$(echo '{"route":"chat","method":"POST","payload":{"message":"tengo una ferretería y mis vendedores ganan 3% de comisión","tenant_id":"test_builder_formulas","mode":"builder"},"session":{"auth_user":{"tenant_id":"test_builder_formulas","id":"builder_u1","role":"creator"}}}' | base64 -w0)"
# ESPERADO: agente captura tipo=ferretería y planifica paso de fórmulas/comisiones

# Pre-flight check
php framework/scripts/codex_self_check.php --strict
```

---

## CHECKLIST DE ENTREGA

```
□ Tabla user_profiles creada en MySQL con índices correctos
□ UserProfileService.php creado y probado
□ AppUserOnboarding.php creado — entrevista de 4 pasos
□ BuilderOnboardingProcess.php extendido — 8 pasos + pasos dinámicos
□ builder_interview_state migrada a MySQL (tabla + backward compat JSON)
□ CrossSessionMemory.php creado — carga últimos N turnos cross-sesión
□ buildSystemPrompt() reemplaza línea 734 en ChatAgent.php
□ Gatekeeper insertado en ChatAgent.handle() (Fase 6.1)
□ ingestUserInteraction() llamado automáticamente al final de cada turno
□ System prompts por rol creados (base, admin, seller, accountant, guest, torre)
□ Tool add_interview_step registrada en Builder
□ run.php: 121/121 PASS (no regresiones)
□ Test cross-session: agente recuerda nombre en sesión nueva
□ Test onboarding: tenant nuevo recibe entrevista, no asume contexto
```

---

*Stack: PHP 8.1+ · MySQL · Qdrant (user_memory) · IIS/Laragon*
*Principio: memoria es contexto, contexto es personalización, personalización es valor real*
