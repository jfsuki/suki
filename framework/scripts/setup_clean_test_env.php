<?php

declare(strict_types=1);

/**
 * setup_clean_test_env.php
 *
 * Prepara un entorno LIMPIO para pruebas manuales QA.
 * Ejecutar ANTES de empezar las pruebas manuales.
 *
 * QUÉ HACE:
 *   1. Limpia tablas de sesión y conversación (datos de pruebas previas)
 *   2. Limpia usuarios de prueba anteriores (conserva la estructura DB)
 *   3. Limpia apps instaladas de pruebas previas
 *   4. Limpia el catálogo de apps propuestas (deja solo templates base)
 *   5. Verifica que el .env tiene lo necesario
 *   6. Muestra el estado final listo para iniciar pruebas
 *
 * QUÉ NO HACE:
 *   - No toca el esquema de la DB (no DROP TABLE)
 *   - No borra el catálogo base (templates de SUKI)
 *   - No elimina configuración del .env
 *
 * USO:
 *   php framework/scripts/setup_clean_test_env.php
 *   php framework/scripts/setup_clean_test_env.php --dry-run  (solo muestra, no ejecuta)
 */

require_once dirname(__DIR__) . '/app/autoload.php';

$isDryRun = in_array('--dry-run', $argv ?? [], true);
$errors   = [];
$done     = [];

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║  SUKI — Setup Entorno Limpio para Pruebas QA             ║\n";
echo "║  " . date('Y-m-d H:i:s') . ($isDryRun ? ' [DRY-RUN]' : '          ') . "              ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ─── Verificar .env ──────────────────────────────────────────────────────────

echo "── Verificación de entorno ──────────────────────────────────\n";

$required = [
    'SUKI_MASTER_KEY'        => 'Requerida para acceder a Torre',
    'DB_DRIVER'              => 'mysql o sqlite',
    'APP_ENV'                => 'debe ser dev para pruebas',
];
$warnings = [
    'LLM_PROVIDER'           => 'Al menos un LLM activo (mistral, openrouter, groq)',
    'SEMANTIC_MEMORY_ENABLED'=> '1 para activar Qdrant',
];

$allOk = true;
foreach ($required as $key => $desc) {
    $val = trim((string) getenv($key));
    if ($val === '') {
        echo "  ❌ $key — FALTA ($desc)\n";
        $errors[] = $key;
        $allOk = false;
    } else {
        $display = $key === 'SUKI_MASTER_KEY' ? str_repeat('*', min(8, strlen($val))) : $val;
        echo "  ✅ $key = $display\n";
    }
}
foreach ($warnings as $key => $desc) {
    $val = trim((string) getenv($key));
    $icon = $val !== '' ? '✅' : '⚠️ ';
    $display = $val ?: 'no configurado';
    echo "  $icon $key = $display ($desc)\n";
}

if (!$allOk) {
    echo "\n❌ Variables requeridas faltantes. Configura el .env antes de continuar.\n";
    exit(1);
}

// ─── Conexión DB ─────────────────────────────────────────────────────────────

echo "\n── Conexión a base de datos ─────────────────────────────────\n";
try {
    $registry = new \App\Core\ProjectRegistry();
    $db       = $registry->db();
    $driver   = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
    echo "  ✅ Conectado ($driver)\n";
} catch (\Throwable $e) {
    echo "  ❌ Error de conexión: " . $e->getMessage() . "\n";
    exit(1);
}

// ─── Tablas a limpiar ────────────────────────────────────────────────────────

$tablesToClear = [
    'conversation_memory'       => 'Historial de conversaciones',
    'conversation_topic_cluster'=> 'Clusters de tema por sesión (SCML)',
    'ai_agents'                 => 'Agentes instalados por tenant',
    'app_tenant_config'         => 'Configuración de apps por tenant',
    'agent_memory'              => 'Memoria interna de agentes',
    'agent_journal'             => 'Bitácora de arquitectura',
    'telemetry_events'          => 'Eventos de telemetría',
    'intent_metrics'            => 'Métricas de intents',
    'feedback_queue'            => 'Cola de retroalimentación',
    'operational_queue'         => 'Cola de trabajos async',
];

$tablesToClearAuth = [
    'auth_users'  => 'Usuarios autenticados de tenants',
    'auth_sessions' => 'Sesiones activas',
];

$tablesToClearMaster = [
    'master_users'  => 'Usuarios del sistema (builders, creadores)',
    'sessions'      => 'Sesiones PHP de usuarios master',
];

echo "\n── Limpieza de tablas de datos de sesión ────────────────────\n";
foreach ($tablesToClear as $table => $desc) {
    try {
        $count = (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        if (!$isDryRun) {
            $db->exec("DELETE FROM $table");
            echo "  ✅ $table — $count filas eliminadas ($desc)\n";
        } else {
            echo "  [DRY] $table — $count filas (no eliminado)\n";
        }
        $done[] = $table;
    } catch (\Throwable) {
        echo "  ⚪ $table — no existe (ok)\n";
    }
}

echo "\n── Limpieza de usuarios de tenant ───────────────────────────\n";
foreach ($tablesToClearAuth as $table => $desc) {
    try {
        $count = (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        if (!$isDryRun) {
            $db->exec("DELETE FROM $table");
            echo "  ✅ $table — $count filas eliminadas ($desc)\n";
        } else {
            echo "  [DRY] $table — $count filas (no eliminado)\n";
        }
    } catch (\Throwable) {
        echo "  ⚪ $table — no existe (ok)\n";
    }
}

echo "\n── Limpieza de usuarios master/builder ──────────────────────\n";
foreach ($tablesToClearMaster as $table => $desc) {
    try {
        $count = (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        if (!$isDryRun) {
            $db->exec("DELETE FROM $table");
            echo "  ✅ $table — $count filas eliminadas ($desc)\n";
        } else {
            echo "  [DRY] $table — $count filas (no eliminado)\n";
        }
    } catch (\Throwable) {
        echo "  ⚪ $table — no existe (ok)\n";
    }
}

// ─── Limpiar apps propuestas del catálogo (no templates base) ────────────────

echo "\n── Limpiar apps propuestas del catálogo ─────────────────────\n";
$catalogPath = dirname(__DIR__, 2) . '/project/contracts/app_catalog.json';
if (is_file($catalogPath)) {
    $catalog = json_decode(file_get_contents($catalogPath), true);
    $before  = count($catalog['apps'] ?? []);
    // Conservar solo apps sin _proposed_by_tenant (plantillas base de SUKI)
    $catalog['apps'] = array_values(array_filter(
        $catalog['apps'] ?? [],
        static fn($a) => empty($a['_proposed_by_tenant'])
    ));
    $after   = count($catalog['apps']);
    $removed = $before - $after;
    if (!$isDryRun) {
        file_put_contents($catalogPath, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "  ✅ app_catalog.json — $removed apps propuestas eliminadas, $after templates base conservadas\n";
    } else {
        echo "  [DRY] app_catalog.json — se eliminarían $removed apps propuestas\n";
    }
} else {
    echo "  ⚠️  app_catalog.json no encontrado\n";
}

// ─── Estado final ─────────────────────────────────────────────────────────────

if (!$isDryRun) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════╗\n";
    echo "║  ✅ ENTORNO LIMPIO — LISTO PARA PRUEBAS QA               ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";

    echo "FLUJO CORRECTO PARA INICIAR PRUEBAS:\n\n";
    echo "  PASO 1 → Torre\n";
    echo "    URL: http://suki.test/torre/\n";
    echo "    Clave: SUKI_MASTER_KEY del .env\n";
    echo "    Acción: Ir a pestaña 'Creadores' → Crear usuario builder\n\n";

    echo "  PASO 2 → Builder (después de crear usuario en Torre)\n";
    echo "    URL: http://suki.test/builder-login\n";
    echo "    Acción: Login con credenciales creadas en Torre\n";
    echo "    Acción: Crear apps por chat → publicar en marketplace\n\n";

    echo "  PASO 3 → Marketplace (después de publicar apps)\n";
    echo "    URL: http://suki.test/marketplace\n";
    echo "    Estado: vacío hasta que Builder publique apps\n\n";

    echo "  PASO 4 → Registrar empresas (después de que haya apps)\n";
    echo "    URL: http://suki.test/register-enterprise\n";
    echo "    Acción: 6 empresas, cada una elige una app\n\n";

    echo "  PASO 5 → Uso del ERP\n";
    echo "    URL: http://suki.test/app\n";
    echo "    Acción: Pruebas de funcionalidad por empresa\n\n";

    echo "Ver manual completo: docs/MANUAL_PRUEBAS_QA_SUKI.md\n\n";
} else {
    echo "\n[DRY-RUN] Ningún dato fue modificado. Ejecuta sin --dry-run para limpiar.\n\n";
}
