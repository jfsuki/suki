// framework/tests/test_persistence_sync.php
/**
 * TEST DE PERSISTENCIA Y SINCRONIZACIÓN (CERO HUMO v4.4 SQL)
 * Valida que la alineación arquitectónica permite recuperar el contexto usando SQL + Hashed Identity.
 */

require_once __DIR__ . '/../app/autoload.php';

use App\Core\SqlMemoryRepository;
use App\Core\Agents\Memory\MemoryWindow;
use App\Core\Agents\Memory\TokenBudgeter;

echo "--- INICIANDO PRUEBA DE PERSISTENCIA FINAL (v4.4 SQL) ---\n";

$rawTenantId = 'default';
$sessionId = 'test_sync_' . uniqid();
$userId = 'test_user';

// --- HASHED IDENTITY (Carlos Rule) ---
function resolveTenantId($tenantId) {
    if (is_numeric($tenantId)) return (int) $tenantId;
    $hash = crc32((string) $tenantId);
    $unsigned = (int) sprintf('%u', $hash);
    $max = 2147483647;
    $value = $unsigned % $max;
    return $value > 0 ? $value : 1;
}
$resolvedTenantId = resolveTenantId($rawTenantId);

// Usamos el repositorio SQL unificado
$memory = new SqlMemoryRepository();

echo "1. Simulando guardado en motor SQL (ID Resuelto: $resolvedTenantId)...\n";
$memory->appendShortTermMemory($resolvedTenantId, $userId, $sessionId, 'web', 'in', 'Hola, soy Carlos y mi negocio es un SPA.');
$memory->appendShortTermMemory($resolvedTenantId, $userId, $sessionId, 'web', 'out', 'Hola Carlos, anotado: un SPA.');

echo "2. Recuperando historial desde SQL...\n";
$history = $memory->getShortTermMemory($resolvedTenantId, $sessionId);

echo "3. Hidratando MemoryWindow (Normalización automática activada)...\n";
$window = new MemoryWindow(5);
$window->hydrateFromState([
    'tenant_id' => $resolvedTenantId,
    'user_id' => $userId,
    'history_log' => $history
], [], ['summary' => 'Carlos tiene un SPA', 'tasks' => []]);

echo "4. Compilando para el LLM...\n";
$budgeter = new TokenBudgeter();
$compiled = $window->compileLlmContext($budgeter, 500);

$historyText = $compiled['recent_history'] ?? '';
$foundName = (stripos($historyText, 'Carlos') !== false);
$foundBusiness = (stripos($historyText, 'SPA') !== false);

echo "--- RESULTADOS DE RECUPERACIÓN ---\n";
echo "Contexto Compilado:\n" . $historyText . "\n\n";

if ($foundName && $foundBusiness && count($history) >= 2) {
    echo "✅ ÉXITO: El sistema recuperó el contexto SQL sincrónico correctamente.\n";
} else {
    echo "❌ FALLA: Persistencia rota o incompleta en SQL.\n";
    exit(1);
}

echo "--- FIN DE PRUEBA ---\n";
