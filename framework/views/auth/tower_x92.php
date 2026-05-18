<?php
// tower_x92.php - SUKI Neural Control Tower v4.1 (The Expert Edition)
declare(strict_types=1);

// Global Encoding Fix (UTF-8 Hardening)
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../app/autoload.php';

use App\Core\SqlMetricsRepository;
use App\Core\ProjectRegistry;
use App\Core\KnowledgeRegistryRepository;
use App\Core\QdrantVectorStore;
use App\Core\TrainingPortalController;

// 1. Auth & Context
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['suki_tower_auth'])) {
    $__base = (str_contains($_SERVER['REQUEST_URI'] ?? '', '/suki/')) ? '/suki' : '';
    header("Location: {$__base}/torre/");
    exit;
}

$registry = new ProjectRegistry();
$metricsRepo = new SqlMetricsRepository();
$knowledgeRepo = new KnowledgeRegistryRepository();

$tab   = $_GET['tab']   ?? 'executive';
$range = $_GET['range'] ?? 'today';
$error = '';

// Tenant scope — '' = all companies (Torre universal view)
$tower_tenant = $_SESSION['tower_tenant_scope'] ?? '';

// Handle scope changes from sidebar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scope_tenant'])) {
    $scopeVal = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $_POST['scope_tenant']);
    $_SESSION['tower_tenant_scope'] = $scopeVal;
    $tower_tenant = $scopeVal;
    header('Location: ?tab=' . urlencode($tab));
    exit;
}
if (isset($_GET['clear_scope'])) {
    unset($_SESSION['tower_tenant_scope']);
    $tower_tenant = '';
    header('Location: ?tab=' . urlencode($tab));
    exit;
}

// 2. Actions & Data Aggregation
$action = $_GET['action'] ?? '';
if ($action === 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = trim((string) ($_POST['user_id'] ?? ''));
    $label = trim((string) ($_POST['label'] ?? ''));
    $role = trim((string) ($_POST['role'] ?? 'creator'));
    $pass = trim((string) ($_POST['password'] ?? ''));
    if ($uid !== '') {
        $registry->touchUser($uid, $role, 'creator', 'default', $label, $pass);
        header('Location: ?tab=creators&success=1'); exit;
    }
}

if ($action === 'edit_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = trim((string) ($_POST['user_id'] ?? ''));
    $data = [
        'label' => trim((string) ($_POST['label'] ?? '')),
        'role' => trim((string) ($_POST['role'] ?? '')),
    ];
    $pass = trim((string) ($_POST['password'] ?? ''));
    if ($pass !== '') {
        $data['password'] = $pass;
    }
    if ($uid !== '') {
        $registry->updateMasterUser($uid, $data);
        header('Location: ?tab=creators&updated=1'); exit;
    }
}

try {
    $health = $metricsRepo->getHealthByWorld(1);
    $apiMetrics = $metricsRepo->getApiDetailedMetrics($range);
    
    // Grouped Knowledge (Phase 11.2)
    $groupedLibrary = $knowledgeRepo->getNodesGroupedBySector(); 
    $knowledgeSummary = $knowledgeRepo->getSummaryByMaturity();
    
    // User Memory
    $userMemory = $knowledgeRepo->getUserMemoryNodes(1); 
    
    $catalog = $metricsRepo->getAppCatalogStats();
    $creators = $registry->getMasterUsersByType('creator');

    // 2.1 Training Portal Action
    if ($action === 'ingest_knowledge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $files = $_FILES;
        $post = $_POST;
        $trainingController = new \App\Core\TrainingPortalController();
        $res = $trainingController->handleIngestion($files, $post);
        if ($res['success']) {
            header('Location: ?tab=training&success=1&msg=' . urlencode($res['message'])); exit;
        } else {
            $error = $res['message'];
        }
    }

    // 2.2 Agent Lifecycle Actions
    if ($action === 'create_agent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $area = trim((string) ($_POST['area'] ?? 'GENERAL'));
        $role = trim((string) ($_POST['role'] ?? 'Specialist'));
        $tenantId = trim((string) ($_POST['tenant_id'] ?? 'default'));
        
        $persona = \App\Core\Agents\Registry\SpecialistPersonas::getPersona($area);
        $config = [
            'persona_name' => $persona['name'],
            'prompt_base' => $persona['prompt_base'],
            'capabilities' => $persona['capabilities']
        ];

        $agentId = $registry->createAgent($tenantId, $role, $area, $config);
        header('Location: ?tab=mission&agent_created=1&agent_id=' . $agentId); exit;
    }

    if ($action === 'research_topic' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $agentId = trim((string) ($_POST['agent_id'] ?? ''));
        $topic = trim((string) ($_POST['topic'] ?? ''));
        $tenantId = trim((string) ($_POST['tenant_id'] ?? 'default'));

        $trainingController = new \App\Core\TrainingPortalController();
        $res = $trainingController->handleAutonomousResearch($agentId, $topic, $tenantId);
        
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }

        if ($res['success']) {
            header('Location: ?tab=mission&research_started=1&topic=' . urlencode($topic)); exit;
        } else {
            $error = $res['message'];
        }
    }

    if ($action === 'get_live_events') {
        header('Content-Type: application/json');
        $tenantId = $_GET['tenant_id'] ?? 'default';
        $limit = (int)($_GET['limit'] ?? 10);
        echo json_encode($registry->getAgentEvents($tenantId, $limit));
        exit;
    }

    if ($action === 'trace_knowledge') {
        header('Content-Type: application/json');
        $query = $_GET['query'] ?? '';
        $trainingController = new \App\Core\TrainingPortalController();
        echo json_encode($trainingController->traceKnowledge($query));
        exit;
    }

    // Fetch Agents for Mission Control (respect tenant scope)
    $activeAgents = $registry->getAgentsByTenant($tower_tenant !== '' ? $tower_tenant : 'default');
} catch (\Exception $e) {
    $error = "Fallo crítico en telemetría: " . $e->getMessage();
}

// Cross-tenant overview for Empresas tab
$tenantStats = [];
$allTenants  = [];
try {
    $pdo = $registry->db();
    $stmt = $pdo->query("
        SELECT t.tenant_id,
               (SELECT COUNT(*) FROM ai_agents    WHERE tenant_id = t.tenant_id) AS agent_count,
               (SELECT COUNT(*) FROM chat_sessions WHERE tenant_id = t.tenant_id) AS session_count,
               (SELECT COUNT(*) FROM auth_users   WHERE tenant_id = t.tenant_id) AS user_count
        FROM (
            SELECT DISTINCT tenant_id FROM ai_agents    WHERE tenant_id != ''
            UNION
            SELECT DISTINCT tenant_id FROM chat_sessions WHERE tenant_id != ''
            UNION
            SELECT DISTINCT tenant_id FROM auth_users   WHERE tenant_id != ''
        ) t
        ORDER BY t.tenant_id
    ");
    $tenantStats = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    $allTenants  = array_column($tenantStats, 'tenant_id');
} catch (\Exception $e) {
    // Non-critical — Empresas tab shows empty state
}

// Stats
$memUsage = memory_get_usage(true) / 1024 / 1024;

function safeStr($s): string { 
    $val = (string)$s;
    if (!mb_check_encoding($val, 'UTF-8')) {
        $val = mb_convert_encoding($val, 'UTF-8', 'ISO-8859-1');
    }
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SUKI OS | Neural Tower v4.1 EXPERT</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #010309; --surface: #0a0e1a; --surface-light: #161d30;
            --border: rgba(255, 255, 255, 0.1); --accent: #38bdf8;
            --text: #f1f5f9; --text-dim: #94a3b8;
            --success: #10b981; --warning: #f59e0b; --danger: #ef4444;
            --sidebar-w: 260px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Outfit', sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; overflow-x: hidden; }

        aside { width: var(--sidebar-w); background: var(--surface); border-right: 1px solid var(--border); padding: 40px 20px; position: fixed; height: 100vh; z-index: 100; }
        .brand span { color: var(--accent); font-weight: 800; font-size: 10px; letter-spacing: 4px; text-transform: uppercase; }
        .brand h1 { font-size: 22px; font-weight: 800; margin-bottom: 50px; color:#fff; }
        nav a { display: flex; align-items: center; padding: 14px 18px; color: var(--text-dim); text-decoration: none; border-radius: 14px; margin-bottom: 8px; font-size: 14px; font-weight: 600; transition: 0.3s; }
        nav a:hover { background: var(--surface-light); color: #fff; transform: translateX(5px); }
        nav a.active { background: linear-gradient(90deg, var(--surface-light), transparent); border: 1px solid var(--border); color: var(--accent); }

        main { flex: 1; margin-left: var(--sidebar-w); padding: 50px; max-width: 1500px; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: slideUp 0.4s ease-out; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 24px; padding: 30px; margin-bottom: 30px; position: relative; overflow: hidden; }
        
        .section-title { font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--accent); letter-spacing: 2px; margin-bottom: 25px; margin-top: 20px; border-bottom: 1px solid var(--border); padding-bottom: 10px; }

        .health-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
        .health-node { text-align: center; border-right: 1px solid var(--border); padding: 10px; }
        .health-node:last-child { border: none; }
        .health-status { font-size: 28px; font-weight: 800; margin: 15px 0; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .dot { width: 12px; height: 12px; border-radius: 50%; box-shadow: 0 0 15px currentColor; }

        /* LIBRARY GRID */
        .k-library-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px; margin-bottom: 50px; }
        .k-node-card { background: var(--surface-light); border: 1px solid var(--border); border-radius: 20px; padding: 25px; transition: 0.3s; border-left: 4px solid var(--accent); }
        .k-node-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .k-node-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .k-domain-tag { font-size: 9px; font-weight: 800; padding: 4px 10px; border-radius: 20px; background: rgba(56, 189, 248, 0.15); color: var(--accent); }
        
        .k-maturity-bar { width: 100%; height: 6px; background: rgba(0,0,0,0.3); border-radius: 3px; margin: 15px 0; overflow: hidden; }
        .k-maturity-fill { height: 100%; border-radius: 3px; transition: 1.5s; background: var(--accent); }

        /* DETAILS (ACORDEON) */
        .details-trigger { cursor: pointer; color: var(--accent); font-size: 11px; font-weight: 800; text-transform: uppercase; display: flex; align-items: center; gap: 5px; margin-top: 15px; }
        .details-trigger:hover { text-decoration: underline; }
        .details-content { display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.05); font-size: 13px; color: var(--text-dim); line-height: 1.6; }
        .details-content.active { display: block; animation: slideDown 0.3s ease-out; }
        @keyframes slideDown { from { opacity:0; transform: translateY(-10px); } to { opacity:1; transform: translateY(0); } }

        .stat-badge { padding: 4px 10px; border-radius: 8px; font-size: 10px; font-weight: 800; }
        .badge-success { background: rgba(16,185,129,0.1); color: var(--success); }
        .badge-gap { background: rgba(239,68,68,0.1); color: var(--danger); }

        .sector-header { margin-top: 40px; margin-bottom: 20px; font-size: 20px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 15px; }
        .sector-header::after { content:''; flex:1; height:1px; background: linear-gradient(90deg, var(--border), transparent); }

        /* NEURAL CONSOLE & TRACER */
        .neural-console { 
            background: #000; 
            border: 1px solid var(--border); 
            border-radius: 12px; 
            padding: 20px; 
            font-family: 'JetBrains Mono', monospace; 
            font-size: 12px; 
            height: 300px; 
            overflow-y: auto; 
            margin-top: 20px;
            box-shadow: inset 0 0 20px rgba(56, 189, 248, 0.1);
        }
        .console-entry { margin-bottom: 8px; display: flex; gap: 10px; animation: fadeIn 0.3s ease-out; }
        .console-time { color: var(--accent); opacity: 0.7; }
        .console-type { font-weight: 800; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.05); }
        .console-msg { color: var(--text-dim); }
        @keyframes fadeIn { from { opacity: 0; transform: translateX(-5px); } to { opacity: 1; transform: translateX(0); } }

        .tracer-io {
            background: var(--surface-light);
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
            border-left: 4px solid var(--success);
        }
    </style>
</head>
<body>

    <aside>
        <div class="brand"><span>Neural Engine</span><h1>SUKI TOWER</h1></div>
        <nav>
            <a href="?tab=empresas" class="<?= $tab==='empresas'?'active':'' ?>">🏢 Empresas</a>
            <a href="?tab=executive" class="<?= $tab==='executive'?'active':'' ?>">Control Principal</a>
            <a href="?tab=mission" class="<?= $tab==='mission'?'active':'' ?>">🛰️ Misión Control</a>
            <a href="?tab=library" class="<?= $tab==='library'?'active':'' ?>">Biblioteca Conocimiento</a>
            <a href="?tab=catalog" class="<?= $tab==='catalog'?'active':'' ?>">Catálogo Apps</a>
            <a href="?tab=creators" class="<?= $tab==='creators'?'active':'' ?>">Creators Hub</a>
            <a href="?tab=training" class="<?= $tab==='training'?'active':'' ?>">🧠 Entrenamiento AI</a>
            <a href="?tab=feedback" class="<?= $tab==='feedback'?'active':'' ?>">Feedback</a>
        </nav>

        <div style="margin: 20px 0 8px 0; font-size: 9px; color: var(--accent); font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">🔭 Empresa activa</div>
        <form method="POST" style="margin-bottom: 4px;">
            <select name="scope_tenant" onchange="this.form.submit()" style="width:100%; background:var(--surface-light); border:1px solid var(--border); color:#fff; padding:8px 10px; border-radius:10px; font-size:11px; cursor:pointer;">
                <option value="">— Todas las empresas —</option>
                <?php foreach ($allTenants as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= ($tower_tenant === $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($tower_tenant !== ''): ?>
            <a href="?clear_scope=1&tab=<?= $tab ?>" style="display:block; text-align:center; font-size:10px; color:var(--text-dim); margin-bottom:10px;">✕ Ver todas</a>
        <?php endif; ?>

        <div style="margin: 15px 0 8px 0; font-size: 9px; color: var(--accent); font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">⚡ Herramientas</div>
        <?php $__base = (str_contains($_SERVER['REQUEST_URI'] ?? '', '/suki/')) ? '/suki' : ''; ?>
        <a href="<?= $__base ?>/builder" target="_blank" style="display:flex; align-items:center; padding:10px 14px; border-radius:12px; color:var(--text-dim); text-decoration:none; font-size:13px; font-weight:600; background: rgba(56,189,248,0.05); border: 1px solid rgba(56,189,248,0.1); margin-bottom:8px;">
            🚀 Builder
        </a>
        <a href="<?= $__base ?>/editor" target="_blank" style="display:flex; align-items:center; padding:10px 14px; border-radius:12px; color:var(--text-dim); text-decoration:none; font-size:13px; font-weight:600; background: rgba(56,189,248,0.05); border: 1px solid rgba(56,189,248,0.1);">
            🛠️ Studio
        </a>
        <div style="margin-top:auto; font-family:'JetBrains Mono'; font-size:10px; color:var(--text-dim);">
            <p>v4.1.0-STABLE</p>
            <p>ENCODING: UTF-8 STRIKT</p>
        </div>
    </aside>

    <main>
        <?php if($error): ?><div class="card" style="border-color:var(--danger); color:var(--danger);"><?= $error ?></div><?php endif; ?>

        <!-- TAB: EMPRESAS — Universal Control Panel -->
        <div id="empresas" class="tab-pane <?= $tab==='empresas'?'active':'' ?>">
            <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:40px;">
                <div>
                    <h2 style="font-size:32px; font-weight:800; margin-bottom:8px;">Control de Empresas</h2>
                    <p style="color:var(--text-dim); font-size:14px;">Vista universal de todos los tenants activos en SUKI OS.</p>
                </div>
                <div class="stat-badge badge-success"><?= count($tenantStats) ?> EMPRESAS ACTIVAS</div>
            </div>

            <?php if (empty($tenantStats)): ?>
                <div class="card" style="text-align:center; padding:60px; color:var(--text-dim);">
                    <div style="font-size:48px; margin-bottom:20px;">🏢</div>
                    <p style="font-size:16px; font-weight:600; margin-bottom:8px;">Sin empresas registradas</p>
                    <p style="font-size:13px;">Aún no hay tenants en el sistema. Las empresas aparecen al registrarse en el Marketplace.</p>
                </div>
            <?php else: ?>
                <!-- Resumen rápido -->
                <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:20px; margin-bottom:30px;">
                    <?php
                        $totAgents   = array_sum(array_column($tenantStats, 'agent_count'));
                        $totSessions = array_sum(array_column($tenantStats, 'session_count'));
                        $totUsers    = array_sum(array_column($tenantStats, 'user_count'));
                    ?>
                    <div class="card" style="text-align:center; padding:20px; margin-bottom:0;">
                        <div style="font-size:11px; color:var(--text-dim); font-weight:800; text-transform:uppercase; margin-bottom:8px;">Empresas</div>
                        <div style="font-size:32px; font-weight:800; color:var(--accent);"><?= count($tenantStats) ?></div>
                    </div>
                    <div class="card" style="text-align:center; padding:20px; margin-bottom:0;">
                        <div style="font-size:11px; color:var(--text-dim); font-weight:800; text-transform:uppercase; margin-bottom:8px;">Agentes IA</div>
                        <div style="font-size:32px; font-weight:800; color:var(--accent);"><?= $totAgents ?></div>
                    </div>
                    <div class="card" style="text-align:center; padding:20px; margin-bottom:0;">
                        <div style="font-size:11px; color:var(--text-dim); font-weight:800; text-transform:uppercase; margin-bottom:8px;">Sesiones Chat</div>
                        <div style="font-size:32px; font-weight:800; color:var(--accent);"><?= $totSessions ?></div>
                    </div>
                    <div class="card" style="text-align:center; padding:20px; margin-bottom:0;">
                        <div style="font-size:11px; color:var(--text-dim); font-weight:800; text-transform:uppercase; margin-bottom:8px;">Usuarios</div>
                        <div style="font-size:32px; font-weight:800; color:var(--accent);"><?= $totUsers ?></div>
                    </div>
                </div>

                <!-- Tabla de empresas -->
                <div class="card" style="padding:0; overflow:hidden;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead style="background:rgba(0,0,0,0.2);">
                            <tr style="text-align:left; color:var(--text-dim);">
                                <th style="padding:18px 20px; border-bottom:1px solid var(--border);">Tenant ID / Empresa</th>
                                <th style="padding:18px 20px; border-bottom:1px solid var(--border); text-align:center;">Agentes IA</th>
                                <th style="padding:18px 20px; border-bottom:1px solid var(--border); text-align:center;">Sesiones</th>
                                <th style="padding:18px 20px; border-bottom:1px solid var(--border); text-align:center;">Usuarios</th>
                                <th style="padding:18px 20px; border-bottom:1px solid var(--border);">Estado</th>
                                <th style="padding:18px 20px; border-bottom:1px solid var(--border);">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tenantStats as $ts):
                                $isActive = ((int)$ts['session_count'] > 0 || (int)$ts['user_count'] > 0);
                            ?>
                            <tr style="border-bottom:1px solid rgba(255,255,255,0.04); transition:0.2s;" onmouseover="this.style.background='rgba(56,189,248,0.04)'" onmouseout="this.style.background=''">
                                <td style="padding:16px 20px;">
                                    <div style="font-weight:800; font-size:14px; color:#fff; margin-bottom:3px;"><?= safeStr($ts['tenant_id']) ?></div>
                                    <div style="font-family:'JetBrains Mono'; font-size:10px; color:var(--text-dim);">tenant_id</div>
                                </td>
                                <td style="padding:16px 20px; text-align:center;">
                                    <span style="font-size:18px; font-weight:800; color:var(--accent);"><?= (int)$ts['agent_count'] ?></span>
                                </td>
                                <td style="padding:16px 20px; text-align:center;">
                                    <span style="font-size:18px; font-weight:800; color:var(--text-dim);"><?= (int)$ts['session_count'] ?></span>
                                </td>
                                <td style="padding:16px 20px; text-align:center;">
                                    <span style="font-size:18px; font-weight:800; color:var(--text-dim);"><?= (int)$ts['user_count'] ?></span>
                                </td>
                                <td style="padding:16px 20px;">
                                    <span class="stat-badge <?= $isActive ? 'badge-success' : 'badge-gap' ?>">
                                        <?= $isActive ? '● ACTIVO' : '○ SIN ACTIVIDAD' ?>
                                    </span>
                                </td>
                                <td style="padding:16px 20px;">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="scope_tenant" value="<?= htmlspecialchars($ts['tenant_id']) ?>">
                                        <button type="submit" style="background:rgba(56,189,248,0.1); border:1px solid rgba(56,189,248,0.3); color:var(--accent); padding:6px 14px; border-radius:8px; cursor:pointer; font-size:11px; font-weight:800; font-family:inherit;">
                                            Ver detalle →
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 1: EXECUTIVE DASHBOARD -->
        <div id="executive" class="tab-pane <?= $tab==='executive'?'active':'' ?>">
            <div class="section-title">🔴 Salud del Sistema</div>
            <div class="card">
                <div class="health-grid">
                    <?php foreach(['marketplace','apps','builder','torre'] as $w): 
                        $node = $health[$w] ?? ['status'=>'OK','p50'=>0]; 
                    ?>
                    <div class="health-node">
                        <span style="font-size:11px; color:var(--text-dim); font-weight:800; text-transform:uppercase;"><?= $w ?></span>
                        <div class="health-status" style="color: <?= $node['status']==='OK' ? 'var(--success)' : 'var(--warning)' ?>">
                            <div class="dot" style="background: currentColor;"></div><?= safeStr($node['status']) ?>
                        </div>
                        <div style="font-family:'JetBrains Mono'; font-size:12px; color:var(--accent);">P50: <?= $node['p50'] ?>ms</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="section-title">🟢 Maduración por Sector (Global)</div>
            <div class="card">
                <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:40px;">
                    <?php foreach($knowledgeSummary as $s): ?>
                    <div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                            <span style="font-weight:800; font-size:14px;"><?= safeStr($s['domain']) ?></span>
                            <span style="color:var(--accent);"><?= round((float)$s['avg_maturity'], 1) ?>%</span>
                        </div>
                        <div class="k-maturity-bar"><div class="k-maturity-fill" style="width:<?= $s['avg_maturity'] ?>%; background:var(--accent);"></div></div>
                        <div style="font-size:11px; color:var(--text-dim);"><?= $s['node_count'] ?> Áreas | <?= $s['gap_count'] ?> Vacíos</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- TAB: MISSION CONTROL (PHASE 11.4 + 12.4) -->
        <div id="mission" class="tab-pane <?= $tab === 'mission' ? 'active' : '' ?>">
            <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:40px;">
                <div>
                    <h2 style="font-size:32px; font-weight:800; margin-bottom:8px;">Satellite Mission Control</h2>
                    <p style="color:var(--text-dim); font-size:14px;">Gestión de agentes especialistas y entrenamiento autónomo.</p>
                </div>
                <div style="display:flex; gap:10px;">
                    <button onclick="document.getElementById('modalCreateAgent').style.display='flex'" class="btn btn-primary" style="background:var(--accent); color:#000;">+ Crear Especialista</button>
                    <?php
                    $missionStatus = !empty($activeAgents) ? 'ACTIVOS: '.count($activeAgents).' AGENTES' : 'SIN AGENTES';
                    $missionBadge  = !empty($activeAgents) ? 'badge-success' : 'badge-gap';
                    ?>
                    <div class="stat-badge <?= $missionBadge ?>"><?= $missionStatus ?></div>
                </div>
            </div>

            <!-- SPECIALIST AGENTS LIST (PHASE 12.4) -->
            <div class="section-title">🤖 Red de Agentes Activos (Especialistas)</div>
            <div class="k-library-grid" style="margin-bottom:40px;">
                <?php if (empty($activeAgents)): ?>
                    <p style="color:var(--text-dim); padding:20px;">No hay agentes especializados creados para este tenant móvil aún.</p>
                <?php else: foreach($activeAgents as $agt): 
                    $conf = json_decode($agt['config_json'] ?? '{}', true);
                ?>
                    <div class="k-node-card" style="border-left: 4px solid var(--accent);">
                        <div class="k-node-header">
                             <div class="k-domain-tag"><?= safeStr($agt['area']) ?></div>
                             <div class="dot" style="background:var(--success); box-shadow: 0 0 10px var(--success);"></div>
                        </div>
                        <div style="font-weight:800; margin-bottom:5px; font-size:16px;"><?= safeStr($conf['persona_name'] ?? 'Neural Agent') ?></div>
                        <div style="font-size:11px; color:var(--text-dim); margin-bottom:15px; font-family:'JetBrains Mono';">ID: <?= $agt['agent_id'] ?></div>
                        
                        <p style="font-size:12px; color:var(--text-dim); line-height:1.4; margin-bottom:20px;">
                            <?= safeStr(substr($conf['prompt_base'] ?? '', 0, 100)) ?>...
                        </p>

                        <div style="display:flex; gap:10px;">
                            <button onclick="openResearchModal('<?= $agt['agent_id'] ?>', '<?= $agt['area'] ?>')" class="btn" style="flex:1; font-size:10px; padding:8px; background:rgba(255,255,255,0.05); border:1px solid var(--border);">🧠 Entrenar</button>
                            <button class="btn" style="font-size:10px; padding:8px; border:1px solid var(--border);">⚙️ Config</button>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- LIVE ACTIVITY FEED -->
            <div class="section-title">🛰️ Neural Activity Feed (Last 20 Events)</div>
            <div class="card" style="padding:0; overflow:hidden; border:1px solid rgba(56,189,248,0.2);">
                <table style="width:100%; border-collapse:collapse; font-size:12px; font-family:'JetBrains Mono', monospace;">
                    <thead style="background:rgba(0,0,0,0.2);">
                        <tr style="text-align:left; color:var(--text-dim);">
                            <th style="padding:15px; border-bottom:1px solid var(--border);">TIMESTAMP</th>
                            <th style="padding:15px; border-bottom:1px solid var(--border);">AGENT</th>
                            <th style="padding:15px; border-bottom:1px solid var(--border);">EVENT TYPE</th>
                            <th style="padding:15px; border-bottom:1px solid var(--border);">DETAILS</th>
                            <th style="padding:15px; border-bottom:1px solid var(--border);">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $entries = $registry->getAgentEvents($tower_tenant !== '' ? $tower_tenant : 'default', 20);
                            if (empty($entries)):
                        ?>
                            <tr>
                                <td colspan="5" style="padding:40px; text-align:center; color:var(--text-dim);">No hay actividad registrada en la red multi-agente todavía.</td>
                            </tr>
                        <?php else: 
                            foreach($entries as $data): 
                        ?>
                            <tr style="border-bottom:1px solid rgba(255,255,255,0.03);">
                                <td style="padding:12px 15px; color:var(--accent);"><?= substr($data['created_at'] ?? '', 11, 8) ?></td>
                                <td style="padding:12px 15px; font-weight:800;"><?= safeStr($data['agent_id'] ?? '???') ?></td>
                                <td style="padding:12px 15px;"><span style="color:#fff; background:rgba(255,255,255,0.05); padding:2px 6px; border-radius:4px;"><?= safeStr($data['event_type'] ?? 'EVENT') ?></span></td>
                                <td style="padding:12px 15px; color:var(--text-dim);"><?= safeStr($data['details'] ?? '') ?></td>
                                <td style="padding:12px 15px;">
                                    <span style="color:<?= ($data['status'] ?? '') === 'SUCCESS' || ($data['status'] ?? '') === 'INFO' ? 'var(--success)' : 'var(--danger)' ?>;">
                                        <?= ($data['status'] ?? '') === 'SUCCESS' || ($data['status'] ?? '') === 'INFO' ? '● OK' : '○ BLOCK' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: BIBLIOTECA DE CONOCIMIENTO v4.1 -->
        <div id="library" class="tab-pane <?= $tab==='library'?'active':'' ?>">
            <div class="section-title">📚 Biblioteca de Conocimiento Sectorial (Colombia)</div>
            
            <?php foreach($groupedLibrary as $sector => $nodes): ?>
            <div class="sector-header"><?= safeStr($sector) ?></div>
            <div class="k-library-grid">
                <?php foreach($nodes as $node): 
                    $isGap = ($node['status'] === 'GAP');
                    $nodeId = "node_". $node['id'];
                ?>
                <div class="k-node-card">
                    <div class="k-node-header">
                        <span class="k-domain-tag"><?= safeStr($node['node_type']) ?></span>
                        <span class="stat-badge <?= $isGap ? 'badge-gap' : 'badge-success' ?>"><?= safeStr($node['status']) ?></span>
                    </div>
                    <h3 style="font-size:17px; margin-bottom:8px;"><?= safeStr($node['node_name']) ?></h3>
                    <p style="font-size:13px; color:var(--text-dim);"><?= safeStr($node['description']) ?></p>
                    
                    <div class="k-maturity-bar">
                        <div class="k-maturity-fill" style="width:<?= $node['maturity'] ?>%; background: <?= $isGap ? 'var(--danger)' : 'var(--success)' ?>;"></div>
                    </div>
                    
                    <div class="details-trigger" onclick="toggleDetails('<?= $nodeId ?>')">
                        <span>Ver Conocimiento Detallado</span>
                        <span id="icon_<?= $nodeId ?>">+</span>
                    </div>
                    <div id="<?= $nodeId ?>" class="details-content">
                        <?= safeStr($node['long_content']) ?>
                        <?php if($node['skill_class']): ?>
                        <div style="margin-top:15px; padding:12px; background:rgba(0,0,0,0.3); border-radius:10px; font-family:'JetBrains Mono'; font-size:11px; color:var(--accent);">
                             ⚡ PHP_CALCULATOR: <?= safeStr($node['skill_class']) ?>.php
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <!-- MEMORIA INDIVIDUAL POR USUARIO -->
            <div class="section-title">🧠 Memoria Individual por Usuario</div>
            <div class="card" style="border-left: 4px solid var(--success);">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <p style="font-size:14px; color:var(--text-dim);">Ajustes de contexto específicos aprendidos de tus interacciones.</p>
                    <span class="stat-badge badge-success">Sincronizado</span>
                </div>
                <div style="margin-top:30px; display:grid; grid-template-columns: repeat(3, 1fr); gap:20px;">
                    <?php if(empty($userMemory)): ?>
                    <div style="grid-column: span 3; color:var(--text-dim); font-size:13px; font-style:italic; text-align:center;">No hay aprendizajes individuales registrados aún. SUKI está aprendiendo de tus comandos...</div>
                    <?php else: foreach($userMemory as $m): ?>
                    <div style="padding:20px; background:var(--surface-light); border-radius:16px;">
                        <h4 style="font-size:14px; margin-bottom:10px;"><?= safeStr($m['node_key']) ?></h4>
                        <p style="font-size:12px; color:var(--text-dim);"><?= safeStr($m['content']) ?></p>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB: CREATORS HUB (User Management) -->
        <div id="creators" class="tab-pane <?= $tab==='creators'?'active':'' ?>">
             <div class="section-title">👥 Gestión de Creadores y Arquitectos</div>
             <div class="card">
                 <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                     <p style="font-size:14px; color:var(--text-dim);">Usuarios autorizados para operar el Agente Builder y diseñar aplicaciones.</p>
                     <button onclick="toggleModal('modal-create-user')" style="background:var(--accent); color:#000; border:none; padding:10px 20px; border-radius:12px; font-weight:800; cursor:pointer;">+ Nuevo Creador</button>
                 </div>
                 <table style="width:100%; border-collapse:collapse; font-size:13px;">
                     <thead>
                         <tr style="text-align:left; color:var(--text-dim); border-bottom:1px solid var(--border);">
                             <th style="padding:15px;">ID</th>
                             <th style="padding:15px;">Etiqueta</th>
                             <th style="padding:15px;">Rol</th>
                             <th style="padding:15px;">Visto última vez</th>
                             <th style="padding:15px;">Acciones</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php if(empty($creators)): ?>
                         <tr><td colspan="5" style="padding:30px; text-align:center; color:var(--text-dim);">No hay creadores registrados.</td></tr>
                         <?php else: foreach($creators as $u): ?>
                         <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                             <td style="padding:15px; font-family:'JetBrains Mono'; color:var(--accent);"><?= safeStr($u['id']) ?></td>
                             <td style="padding:15px; font-weight:600;"><?= safeStr($u['label']) ?></td>
                             <td style="padding:15px;"><span class="stat-badge badge-success"><?= safeStr($u['role']) ?></span></td>
                             <td style="padding:15px; color:var(--text-dim);"><?= safeStr($u['last_seen']) ?></td>
                             <td style="padding:15px;">
                                 <button onclick='openEditModal(<?= json_encode($u) ?>)' style="background:var(--surface-light); color:var(--accent); border:1px solid var(--border); padding:6px 12px; border-radius:8px; cursor:pointer; font-size:11px; font-weight:800;">EDITAR</button>
                             </td>
                         </tr>
                         <?php endforeach; endif; ?>
                     </tbody>
                 </table>
             </div>
        </div>

        <!-- TAB: CATALOG -->
        <div id="catalog" class="tab-pane <?= $tab==='catalog'?'active':'' ?>">
             <div class="section-title">🗂️ Catálogo de Aplicaciones Multitenant</div>
             <div class="card">
                 <table style="width:100%; border-collapse:collapse; font-size:13px;">
                     <thead>
                         <tr style="text-align:left; color:var(--text-dim); border-bottom:1px solid var(--border);">
                             <th style="padding:15px;">App ID</th>
                             <th style="padding:15px;">Nombre</th>
                             <th style="padding:15px;">Estado</th>
                             <th style="padding:15px;">Modelo</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php 
                         $allProjects = $registry->listProjects();
                         foreach($allProjects as $p): ?>
                         <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                             <td style="padding:15px; font-family:'JetBrains Mono'; color:var(--accent);"><?= safeStr($p['id']) ?></td>
                             <td style="padding:15px; font-weight:600;"><?= safeStr($p['name']) ?></td>
                             <td style="padding:15px;"><span class="stat-badge badge-success"><?= safeStr($p['status']) ?></span></td>
                             <td style="padding:15px; color:var(--text-dim);"><?= safeStr($p['storage_model']) ?></td>
                         </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
             </div>
        </div>

        <!-- TAB: AI TRAINING CENTER v1.0 [KTC ENGINE] -->
        <div id="training" class="tab-pane <?= $tab==='training'?'active':'' ?>">
             <div class="section-title">🧠 Centro de Entrenamiento AI (KTC Engine)</div>
             
             <div style="display:grid; grid-template-columns: 1fr 1fr; gap:30px; margin-bottom:30px;">
                 <!-- LADO IZQUIERDO: CONSOLA DE INVESTIGACIÓN -->
                 <div class="card" style="margin-bottom:0;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="font-size:16px; font-weight:800;">Neural Research Console</h3>
                        <span id="neural-pulse" class="dot" style="background:var(--accent); display:none;"></span>
                    </div>
                    <p style="font-size:12px; color:var(--text-dim); margin-bottom:15px;">Seguimiento en tiempo real del proceso de búsqueda, síntesis y vectorización.</p>
                    <div id="neural-console" class="neural-console">
                        <div class="console-entry">
                            <span class="console-time">[SISTEMA]</span>
                            <span class="console-msg">Esperando comando de entrenamiento...</span>
                        </div>
                    </div>
                    <button onclick="clearConsole()" style="margin-top:15px; background:none; border:1px solid var(--border); color:var(--text-dim); padding:5px 10px; border-radius:8px; cursor:pointer; font-size:10px;">Limpiar Consola</button>
                 </div>

                 <!-- LADO DERECHO: PRUEBA DE RAZONAMIENTO (RAG TRACER) -->
                 <div class="card" style="margin-bottom:0;">
                    <h3 style="font-size:16px; font-weight:800; margin-bottom:15px;">Knowledge Tracer (Prueba RAG)</h3>
                    <p style="font-size:12px; color:var(--text-dim); margin-bottom:15px;">Verifica en tiempo real si el nuevo conocimiento está disponible en producción.</p>
                    
                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <input type="text" id="trace-query" class="input" style="flex:1; font-size:12px;" placeholder="Haz una pregunta al cerebro neural...">
                        <button onclick="traceKnowledge()" class="btn btn-primary" style="background:var(--accent); color:#000; padding:0 15px; font-size:11px; font-weight:800;">TEST</button>
                    </div>

                    <div id="trace-result" class="tracer-io" style="display:none; font-size:12px; line-height:1.6;">
                        <div style="color:var(--accent); font-weight:800; margin-bottom:10px;">RESPUESTA NEURAL:</div>
                        <div id="trace-response-text" style="margin-bottom:15px; color:#fff;"></div>
                        <div style="color:var(--success); font-weight:800; margin-bottom:5px;">FUENTES RECUPERADAS (RAG):</div>
                        <div id="trace-sources" style="font-size:10px; font-family:'JetBrains Mono'; color:var(--text-dim);"></div>
                    </div>
                    <div id="trace-empty" style="padding:40px; text-align:center; color:var(--text-dim); font-size:12px; border:1px dashed var(--border); border-radius:12px;">
                        Ingresa una pregunta para trazar el razonamiento.
                    </div>
                 </div>
             </div>

             <div class="card" style="border-left: 4px solid var(--accent); background: linear-gradient(135deg, var(--surface), rgba(56, 189, 248, 0.05));">
                 <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:30px;">
                     <div>
                        <h2 style="font-size:20px; font-weight:800; margin-bottom:8px;">Inyección de Conocimiento Manual</h2>
                        <p style="font-size:14px; color:var(--text-dim); line-height:1.6; max-width: 600px;">
                            Sube documentos seleccionados para alimentar la memoria sectorial sin búsqueda autónoma.
                        </p>
                     </div>
                     <div style="text-align:right;">
                        <?php $ktcOk = !isset($error) || $error === ''; ?>
                        <span class="stat-badge <?= $ktcOk ? 'badge-success' : 'badge-gap' ?>"><?= $ktcOk ? 'KTC OPERATIVO' : 'KTC ERROR' ?></span>
                        <div style="font-family:'JetBrains Mono'; font-size:10px; margin-top:10px; color:var(--accent);">KTC_PROTOCOL: v1.0_SECURE</div>
                     </div>
                 </div>

                 <!-- FORMULARIO DE ENTRENAMIENTO -->
                 <form method="POST" action="?action=ingest_knowledge" enctype="multipart/form-data" class="training-form">
                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:30px;">
                        <div style="background:var(--bg); border:1px dashed var(--border); border-radius:24px; padding:40px; text-align:center; transition:0.3s; cursor:pointer;" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
                            <input type="file" name="knowledge_file" id="knowledge_file" required style="display:none;" onchange="updateFileName(this)">
                            <label for="knowledge_file" style="cursor:pointer;">
                                <div style="font-size:40px; margin-bottom:15px;">📄</div>
                                <div id="file-label" style="font-size:14px; font-weight:600; margin-bottom:8px;">Seleccionar archivo (MD, JSON)</div>
                                <p style="font-size:11px; color:var(--text-dim);">Formatos soportados por el KTC</p>
                            </label>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:15px;">
                            <div>
                                <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px; font-weight:800; text-transform:uppercase;">Sector / Dominio</label>
                                <input type="text" name="sector" placeholder="ej: ferreteria, fiscal_col" required style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px; font-weight:800; text-transform:uppercase;">Nivel de Confianza (0.1 - 1.0)</label>
                                <input type="number" step="0.1" min="0.1" max="1.0" name="trust_score" value="1.0" style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;">
                            </div>
                            <div>
                                <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px; font-weight:800; text-transform:uppercase;">URI de la Fuente (Opcional)</label>
                                <input type="text" name="source_uri" placeholder="https://dian.gov.co/..." style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;">
                            </div>
                        </div>
                    </div>
                    <div style="margin-top:25px; display:flex; gap:15px; align-items:center;">
                        <button type="submit" style="background:var(--accent); color:#000; border:none; padding:15px 30px; border-radius:12px; font-weight:800; cursor:pointer;">⚡ INICIAR ENTRENAMIENTO</button>
                        <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
                            <div style="color:var(--success); font-size:13px; font-weight:600;">✅ <?= safeStr($_GET['msg']) ?></div>
                        <?php endif; ?>
                    </div>
                 </form>
             </div>

             <!-- LOG DE ACTIVIDAD RECIENTE -->
             <div class="section-title">📊 Últimos Aprendizajes Autoratativos</div>
             <div class="card">
                 <table style="width:100%; border-collapse:collapse; font-size:13px;">
                     <thead>
                         <tr style="text-align:left; color:var(--text-dim); border-bottom:1px solid var(--border);">
                             <th style="padding:15px;">Fecha</th>
                             <th style="padding:15px;">Sector</th>
                             <th style="padding:15px;">Confianza</th>
                             <th style="padding:15px;">Fuente</th>
                             <th style="padding:15px;">Estado</th>
                         </tr>
                     </thead>
                     <tbody>
                        <?php
                        $ingestLog = [];
                        try {
                            $stmtLog = $registry->db()->query(
                                "SELECT * FROM ktc_ingestion_log ORDER BY created_at DESC LIMIT 10"
                            );
                            $ingestLog = $stmtLog ? $stmtLog->fetchAll(\PDO::FETCH_ASSOC) : [];
                        } catch (\Exception $e) { /* tabla aún no creada */ }
                        if (empty($ingestLog)): ?>
                        <tr>
                            <td colspan="5" style="padding:30px; text-align:center; color:var(--text-dim); font-style:italic; font-size:13px;">
                                Sin aprendizajes registrados. Usa "Inyección de Conocimiento" para entrenar al sistema.
                            </td>
                        </tr>
                        <?php else: foreach ($ingestLog as $entry): ?>
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                            <td style="padding:15px;"><?= safeStr($entry['created_at'] ?? '') ?></td>
                            <td style="padding:15px; font-weight:600;"><?= safeStr($entry['sector'] ?? '') ?></td>
                            <td style="padding:15px;"><span class="stat-badge badge-success"><?= safeStr($entry['trust_score'] ?? '') ?></span></td>
                            <td style="padding:15px; color:var(--accent);"><?= safeStr($entry['source_uri'] ?? '—') ?></td>
                            <td style="padding:15px; color:var(--success);">✅ <?= safeStr(strtoupper($entry['status'] ?? 'INGESTED')) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                     </tbody>
                 </table>
             </div>
        </div>

        <!-- TAB: FEEDBACK v1.0 -->
        <div id="feedback" class="tab-pane <?= $tab==='feedback'?'active':'' ?>">
            <div class="section-title">Feedback de Agentes</div>
            <div class="card" style="margin-bottom:20px;">
                <p style="font-size:13px; color:var(--text-dim); margin-bottom:16px;">
                    Gaps e intents no resueltos capturados en tiempo real por los agentes. Se registran cuando el intent queda <code>unknown</code> con score &lt; 0.65 o cuando el usuario expresa frustración.
                </p>
                <button onclick="loadFeedback()" style="background:var(--accent); color:#000; border:none; padding:8px 20px; border-radius:8px; font-weight:700; cursor:pointer; font-size:12px;">Cargar feedback</button>
                <span id="feedback-count" style="font-size:11px; color:var(--text-dim); margin-left:12px;"></span>
            </div>
            <div class="card" style="padding:0; overflow:hidden;">
                <table id="feedback-table" style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:rgba(56,189,248,0.08); border-bottom:1px solid var(--border);">
                            <th style="padding:10px 14px; text-align:left; font-weight:700; color:var(--accent);">Fecha</th>
                            <th style="padding:10px 14px; text-align:left; font-weight:700; color:var(--accent);">Tenant</th>
                            <th style="padding:10px 14px; text-align:left; font-weight:700; color:var(--accent);">Tipo</th>
                            <th style="padding:10px 14px; text-align:left; font-weight:700; color:var(--accent);">Resumen</th>
                            <th style="padding:10px 14px; text-align:left; font-weight:700; color:var(--accent);">Estado</th>
                            <th style="padding:10px 14px; text-align:left; font-weight:700; color:var(--accent);">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="feedback-tbody">
                        <tr><td colspan="6" style="padding:30px; text-align:center; color:var(--text-dim);">Pulsa "Cargar feedback" para ver los items.</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Modal para promover missing_skill manualmente -->
            <div id="promote-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:2000; align-items:center; justify-content:center;">
                <div class="card" style="width:480px; border:1px solid var(--accent);">
                    <div class="section-title">Asignar Intent y Promover a Qdrant</div>
                    <p style="font-size:12px; color:var(--text-dim); margin-bottom:16px;">El agente no pudo clasificar este mensaje. Asigna el intent correcto para que aprenda.</p>
                    <input type="hidden" id="promote-fb-id">
                    <div style="margin-bottom:14px;">
                        <label style="font-size:11px; color:var(--text-dim);">UTTERANCE DEL USUARIO</label>
                        <input id="promote-utterance" type="text" style="width:100%; background:var(--bg); border:1px solid var(--border); padding:10px; border-radius:8px; color:#fff; margin-top:6px; font-size:12px;">
                    </div>
                    <div style="margin-bottom:20px;">
                        <label style="font-size:11px; color:var(--text-dim);">INTENT CORRECTO (ej: pos_create, create_app, list_records)</label>
                        <input id="promote-intent" type="text" style="width:100%; background:var(--bg); border:1px solid var(--border); padding:10px; border-radius:8px; color:#fff; margin-top:6px; font-size:12px;" placeholder="pos_create">
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button onclick="submitPromote()" style="flex:1; background:var(--accent); color:#000; border:none; padding:12px; border-radius:8px; font-weight:800; cursor:pointer; font-size:12px;">Promover a Qdrant</button>
                        <button onclick="document.getElementById('promote-modal').style.display='none'" style="flex:1; background:var(--surface-light); color:#fff; border:none; padding:12px; border-radius:8px; font-weight:800; cursor:pointer; font-size:12px;">Cancelar</button>
                    </div>
                    <div id="promote-result" style="margin-top:12px; font-size:12px;"></div>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL: CREATE USER -->
    <div id="modal-create-user" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(10px);">
        <div class="card" style="width:500px; border:1px solid var(--accent); background:var(--surface);">
            <div class="section-title">Registrar Nuevo Creador</div>
            <form method="POST" action="?action=create_user">
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px;">ID USUARIO (Sin espacios)</label>
                    <input type="text" name="user_id" required style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;">
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px;">NOMBRE / ETIQUETA</label>
                    <input type="text" name="label" required style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;">
                </div>
                <div style="margin-bottom:30px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px;">CONTRASEÑA</label>
                    <input type="password" name="password" required style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;" placeholder="Mínimo 6 caracteres">
                </div>
                <div style="margin-bottom:30px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px;">ROL DEL SISTEMA</label>
                    <select name="role" style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;">
                        <option value="creator">Creador (Agent Builder)</option>
                        <option value="admin">Administrador Total</option>
                        <option value="architect">Arquitecto Senior</option>
                    </select>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="submit" style="flex:1; background:var(--accent); color:#000; border:none; padding:15px; border-radius:12px; font-weight:800; cursor:pointer;">Crear Usuario</button>
                    <button type="button" onclick="toggleModal('modal-create-user')" style="flex:1; background:var(--surface-light); color:#fff; border:none; padding:15px; border-radius:12px; font-weight:800; cursor:pointer;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: EDIT USER -->
    <div id="modal-edit-user" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(10px);">
        <div class="card" style="width:500px; border:1px solid var(--accent); background:var(--surface);">
            <div class="section-title">Editar Creador: <span id="edit-user-id-title" style="color:#fff;"></span></div>
            <form method="POST" action="?action=edit_user">
                <input type="hidden" name="user_id" id="edit-user-id">
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px;">NOMBRE / ETIQUETA</label>
                    <input type="text" name="label" id="edit-label" required style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;">
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px;">CAMBIAR ROL</label>
                    <select name="role" id="edit-role" style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;">
                        <option value="creator">Creador (Agent Builder)</option>
                        <option value="admin">Administrador Total</option>
                        <option value="architect">Arquitecto Senior</option>
                    </select>
                </div>
                <div style="margin-bottom:30px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px;">NUEVA CONTRASEÑA (Opcional)</label>
                    <input type="password" name="password" style="width:100%; background:var(--bg); border:1px solid var(--border); padding:12px; border-radius:12px; color:#fff;" placeholder="Dejar vacío para no cambiar">
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="submit" style="flex:1; background:var(--accent); color:#000; border:none; padding:15px; border-radius:12px; font-weight:800; cursor:pointer;">Guardar Cambios</button>
                    <button type="button" onclick="toggleModal('modal-edit-user')" style="flex:1; background:var(--surface-light); color:#fff; border:none; padding:15px; border-radius:12px; font-weight:800; cursor:pointer;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleDetails(id) {
            const content = document.getElementById(id);
            const icon = document.getElementById('icon_' + id);
            if (content.classList.toggle('active')) {
                icon.innerText = '-';
            } else {
                icon.innerText = '+';
            }
        }

        function toggleModal(id) {
            const modal = document.getElementById(id);
            if (modal.style.display === 'flex') {
                modal.style.display = 'none';
            } else {
                modal.style.display = 'flex';
            }
        }

        function openEditModal(user) {
            document.getElementById('edit-user-id').value = user.id;
            document.getElementById('edit-user-id-title').innerText = user.id;
            document.getElementById('edit-label').value = user.label;
            document.getElementById('edit-role').value = user.role;
            toggleModal('modal-edit-user');
        }

        function updateFileName(input) {
            const label = document.getElementById('file-label');
            if (input.files && input.files.length > 0) {
                label.innerText = 'Archivo: ' + input.files[0].name;
                label.style.color = 'var(--accent)';
            } else {
                label.innerText = 'Seleccionar archivo (MD, JSON)';
                label.style.color = 'inherit';
            }
        }
    </script>
    <!-- MODAL: CREATE AGENT (PHASE 12.4) -->
    <div id="modalCreateAgent" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:1000; justify-content:center; align-items:center;">
        <div class="card" style="width:500px; padding:30px; position:relative;">
            <button onclick="this.parentElement.parentElement.style.display='none'" style="position:absolute; top:15px; right:15px; background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:20px;">&times;</button>
            <h2 style="margin-bottom:20px;">Instanciar Agente Especialista</h2>
            <form action="?action=create_agent" method="POST">
                <input type="hidden" name="tenant_id" value="<?= htmlspecialchars($tower_tenant ?: 'default') ?>">
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px; text-transform:uppercase;">Área de Especialidad</label>
                    <select name="area" class="input" style="width:100%;">
                        <option value="FINANCES">Finanzas & Fiscalidad</option>
                        <option value="SALES">Ventas & E-commerce</option>
                        <option value="ARCHITECT">Arquitectura de Sistema</option>
                        <option value="ACCOUNTING">Contabilidad & Auditoría</option>
                        <option value="INVENTORY">Inventario & Logística</option>
                        <option value="PURCHASES">Compras & Proveedores</option>
                        <option value="SUPPORT">Soporte General</option>
                    </select>
                </div>
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px; text-transform:uppercase;">Rol / Nombre del Agente</label>
                    <input type="text" name="role" class="input" style="width:100%;" placeholder="Ej: Auditor Fiscal Senior" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; height:45px; background:var(--accent); color:#000; font-weight:800;">ACTIVAR AGENTE</button>
            </form>
        </div>
    </div>

    <!-- MODAL: AUTONOMOUS RESEARCH (PHASE 12.4) -->
    <div id="modalResearch" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:1000; justify-content:center; align-items:center;">
        <div class="card" style="width:500px; padding:30px; position:relative;">
            <button onclick="this.parentElement.parentElement.style.display='none'" style="position:absolute; top:15px; right:15px; background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:20px;">&times;</button>
            <h2 style="margin-bottom:10px;">Entrenamiento Autónomo</h2>
            <p style="color:var(--text-dim); font-size:12px; margin-bottom:20px;">El agente buscará fuentes académicas y leyes oficiales para auto-instruirse.</p>
            <form id="researchForm" onsubmit="event.preventDefault(); startNeuralResearch();">
                <input type="hidden" name="tenant_id" value="<?= htmlspecialchars($tower_tenant ?: 'default') ?>">
                <input type="hidden" id="research_agent_id" name="agent_id" value="">
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:11px; color:var(--text-dim); margin-bottom:8px; text-transform:uppercase;">Tema de Investigación</label>
                    <input type="text" name="topic" id="research_topic_input" class="input" style="width:100%;" placeholder="Ej: Ley de Facturación Electrónica 2024 Colombia" required>
                </div>
                <button type="submit" id="btn-start-research" class="btn btn-primary" style="width:100%; height:45px; background:var(--success); color:#000; font-weight:800;">INICIAR INVESTIGACIÓN NEURAL</button>
            </form>
        </div>
    </div>

    <script>
        function openResearchModal(agentId, area) {
            document.getElementById('research_agent_id').value = agentId;
            let topic = "Mejores prácticas en " + area;
            if (area === 'FINANCES') topic = "Leyes de Facturación y Fiscalidad Colombia 2025";
            if (area === 'SALES') topic = "Estrategias de Growth y conversión E-commerce";
            if (area === 'ACCOUNTING') topic = "Normas NIA y gestión de balances en ERP";
            if (area === 'INVENTORY') topic = "Optimización de stock y métodos FIFO/LIFO";
            if (area === 'PURCHASES') topic = "Gestión estratégica de proveedores y reducción de costos";
            document.getElementById('research_topic_input').value = topic;
            document.getElementById('modalResearch').style.display = 'flex';
        }

        let isPolling = false;
        window.processedEvents = new Set();

        async function startNeuralResearch() {
            const agentId = document.getElementById('research_agent_id').value;
            const topic = document.getElementById('research_topic_input').value;
            const btn = document.getElementById('btn-start-research');

            btn.disabled = true;
            btn.innerText = 'CONECTANDO CON CEREBRO...';

            try {
                // Ir a la pestaña de entrenamiento automáticamente
                showTab('training');
                document.getElementById('modalResearch').style.display = 'none';
                
                // Limpiar consola anterior
                clearConsole();
                addLog('INFO', 'SUKI_LINK', 'Iniciando túnel de investigación neural para especialista: ' + agentId);
                
                // Iniciar Polling
                if(!isPolling) startPolling();

                const response = await fetch('?action=research_topic', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `agent_id=${agentId}&topic=${encodeURIComponent(topic)}&tenant_id=<?= htmlspecialchars($tower_tenant ?: 'default') ?>&ajax=1`
                });

                const result = await response.json();
                if(result.success) {
                    addLog('SUCCESS', 'TASK_DONE', result.message);
                } else {
                    addLog('ERROR', 'FAIL', result.message || 'Error desconocido');
                }
            } catch (e) {
                addLog('ERROR', 'CRITICAL', 'Túnel neural interrumpido o tiempo de espera excedido.');
            } finally {
                btn.disabled = false;
                btn.innerText = 'INICIAR INVESTIGACIÓN NEURAL';
            }
        }

        function showTab(tabId) {
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('nav a').forEach(a => {
                a.classList.remove('active');
                if(a.getAttribute('href').includes(tabId)) a.classList.add('active');
            });
            document.getElementById(tabId).classList.add('active');
        }

        function addLog(type, code, msg) {
            const consoleBox = document.getElementById('neural-console');
            const entry = document.createElement('div');
            entry.className = 'console-entry';
            const time = new Date().toLocaleTimeString();
            
            let color = 'var(--accent)';
            if(type === 'SUCCESS') color = 'var(--success)';
            if(type === 'ERROR') color = 'var(--danger)';

            entry.innerHTML = `
                <span class="console-time">[${time}]</span>
                <span class="console-type" style="color:${color}">${code}</span>
                <span class="console-msg">${msg}</span>
            `;
            consoleBox.appendChild(entry);
            consoleBox.scrollTop = consoleBox.scrollHeight;
        }

        function clearConsole() {
            document.getElementById('neural-console').innerHTML = '';
            window.processedEvents = new Set();
        }

        function startPolling() {
            isPolling = true;
            document.getElementById('neural-pulse').style.display = 'inline-block';
            const pollId = setInterval(async () => {
                try {
                    const res = await fetch('?action=get_live_events&tenant_id=<?= htmlspecialchars($tower_tenant ?: 'default') ?>&limit=10');
                    const events = await res.json();
                    
                    events.forEach(ev => {
                        const key = ev.created_at + ev.event_type;
                        if(!window.processedEvents.has(key)) {
                            addLog(ev.status, ev.event_type, ev.details);
                            window.processedEvents.add(key);
                        }
                    });
                } catch(e) {}
            }, 3000);
            
            // Detener sesión después de 3 minutos
            setTimeout(() => {
                clearInterval(pollId);
                isPolling = false;
                document.getElementById('neural-pulse').style.display = 'none';
            }, 180000);
        }

        async function traceKnowledge() {
            const query = document.getElementById('trace-query').value;
            const resBox = document.getElementById('trace-result');
            const emptyBox = document.getElementById('trace-empty');
            
            if(!query) return;

            emptyBox.style.display = 'none';
            resBox.style.display = 'block';
            document.getElementById('trace-response-text').innerText = 'Recuperando vectores de QDRANT...';
            document.getElementById('trace-sources').innerHTML = '';

            try {
                const response = await fetch('?action=trace_knowledge&query=' + encodeURIComponent(query));
                const result = await response.json();
                
                document.getElementById('trace-response-text').innerText = result.answer || 'Consultando a la IA con los fragmentos recuperados...';
                
                if(result.sources && result.sources.length > 0) {
                    document.getElementById('trace-sources').innerHTML = result.sources.map(s => 
                        `• [VECTOR_${s.id.substring(0,8)}] Score: ${s.score.toFixed(4)} <br> <span style="opacity:0.6; font-size:9px;">${s.content.substring(0,60)}...</span><br>`
                    ).join('<br>');
                } else {
                    document.getElementById('trace-sources').innerText = 'Sin coincidencias en la memoria vectorial.';
                }
            } catch(e) {
                document.getElementById('trace-response-text').innerText = 'Error en la traza de conocimiento.';
            }
        }
        async function loadFeedback() {
            const tbody = document.getElementById('feedback-tbody');
            const countEl = document.getElementById('feedback-count');
            tbody.innerHTML = '<tr><td colspan="6" style="padding:20px; text-align:center; color:var(--text-dim);">Cargando...</td></tr>';
            try {
                const res = await fetch('api/chat/feedback');
                const json = await res.json();
                const items = json.data?.items || [];
                const pending = items.filter(i => i.status === 'pending').length;
                countEl.textContent = items.length + ' total · ' + pending + ' pendientes';
                if (items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="padding:30px; text-align:center; color:var(--text-dim);">Sin feedback registrado aun.</td></tr>';
                    return;
                }
                const typeLabels = { missing_skill: '❓ Skill faltante', bad_response: '😤 Mala respuesta', missing_field: '📋 Campo faltante', error: '❌ Error' };
                const statusColors = { pending: 'var(--warning)', auto_promoted: 'var(--success)', promoted: 'var(--success)', applied: 'var(--success)' };
                tbody.innerHTML = items.slice(0, 30).map(item => {
                    const ts = (item.created_at || '').substring(0, 16);
                    const tenant = (item.tenant_id || '-').substring(0, 12);
                    const type = typeLabels[item.type] || item.type || '-';
                    const summary = (item.summary || '').substring(0, 100);
                    const status = item.status || 'pending';
                    const statusColor = statusColors[status] || 'var(--text-dim)';
                    // Solo mostrar botón Promover para missing_skill pendientes
                    const canPromote = item.type === 'missing_skill' && status === 'pending';
                    const utteranceRaw = (item.summary || '').replace(/^[^"]*"([^"]*)".*$/, '$1');
                    const actionCell = canPromote
                        ? `<button onclick="openPromoteModal('${item.id}','${utteranceRaw.replace(/'/g,"\\'")}\")" style="background:var(--accent); color:#000; border:none; padding:5px 12px; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer;">Promover</button>`
                        : `<span style="color:var(--text-dim); font-size:11px;">${status === 'auto_promoted' ? '✅ auto' : status === 'promoted' ? '✅ manual' : '—'}</span>`;
                    return `<tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:8px 14px; color:var(--text-dim); white-space:nowrap; font-size:11px;">${ts}</td>
                        <td style="padding:8px 14px; font-size:11px;">${tenant}</td>
                        <td style="padding:8px 14px; font-weight:700; font-size:11px; white-space:nowrap;">${type}</td>
                        <td style="padding:8px 14px; color:var(--text-dim); font-size:11px;">${summary}</td>
                        <td style="padding:8px 14px; color:${statusColor}; font-weight:700; font-size:11px;">${status}</td>
                        <td style="padding:8px 14px;">${actionCell}</td>
                    </tr>`;
                }).join('');
            } catch(e) {
                tbody.innerHTML = '<tr><td colspan="6" style="padding:20px; text-align:center; color:var(--danger);">Error: ' + e.message + '</td></tr>';
            }
        }

        function openPromoteModal(fbId, utterance) {
            document.getElementById('promote-fb-id').value = fbId;
            document.getElementById('promote-utterance').value = utterance;
            document.getElementById('promote-intent').value = '';
            document.getElementById('promote-result').textContent = '';
            document.getElementById('promote-modal').style.display = 'flex';
        }

        async function submitPromote() {
            const fbId     = document.getElementById('promote-fb-id').value;
            const utterance = document.getElementById('promote-utterance').value.trim();
            const intent   = document.getElementById('promote-intent').value.trim();
            const resultEl = document.getElementById('promote-result');
            if (!intent) { resultEl.textContent = 'Escribe el intent correcto.'; return; }
            resultEl.textContent = 'Vectorizando...';
            try {
                const res = await fetch('api/chat/feedback/promote', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ feedback_id: fbId, utterance, intent })
                });
                const json = await res.json();
                if (json.status === 'success') {
                    resultEl.style.color = 'var(--success)';
                    resultEl.textContent = '✅ Promovido. El agente aprenderá este utterance en la próxima clasificación.';
                    setTimeout(() => { document.getElementById('promote-modal').style.display = 'none'; loadFeedback(); }, 2000);
                } else {
                    resultEl.style.color = 'var(--danger)';
                    resultEl.textContent = '❌ ' + (json.message || 'Error');
                }
            } catch(e) {
                resultEl.style.color = 'var(--danger)';
                resultEl.textContent = '❌ ' + e.message;
            }
        }
    </script>
</body>
</html>
