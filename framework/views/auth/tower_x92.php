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

// 1. Auth & Context
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['suki_tower_auth'])) {
    header('Location: ./torre'); exit;
}

$registry = new ProjectRegistry();
$metricsRepo = new SqlMetricsRepository();
$knowledgeRepo = new KnowledgeRegistryRepository();

$tab = $_GET['tab'] ?? 'executive';
$range = $_GET['range'] ?? 'today';
$error = '';

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
} catch (\Exception $e) {
    $error = "Fallo crítico en telemetría: " . $e->getMessage();
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

    </style>
</head>
<body>

    <aside>
        <div class="brand"><span>Neural Engine</span><h1>SUKI TOWER</h1></div>
        <nav>
            <a href="?tab=executive" class="<?= $tab==='executive'?'active':'' ?>">Control Principal</a>
            <a href="?tab=library" class="<?= $tab==='library'?'active':'' ?>">Biblioteca Conocimiento</a>
            <a href="?tab=catalog" class="<?= $tab==='catalog'?'active':'' ?>">Catálogo Apps</a>
            <a href="?tab=creators" class="<?= $tab==='creators'?'active':'' ?>">Creators Hub</a>
            <a href="?tab=training" class="<?= $tab==='training'?'active':'' ?>">🧠 Entrenamiento AI</a>
            
            <div style="margin: 20px 0 10px 10px; font-size: 9px; color: var(--accent); font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">⚡ Herramientas</div>
            <a href="./builder" target="_blank" style="background: rgba(56,189,248,0.05); border: 1px solid rgba(56,189,248,0.1);">
                🚀 Ir al Builder
            </a>
            <a href="./editor" target="_blank" style="background: rgba(56,189,248,0.05); border: 1px solid rgba(56,189,248,0.1);">
                🛠️ Ir al Studio
            </a>
        </nav>
        <div style="margin-top:auto; font-family:'JetBrains Mono'; font-size:10px; color:var(--text-dim);">
            <p>v4.1.0-STABLE</p>
            <p>ENCODING: UTF-8 STRIKT</p>
        </div>
    </aside>

    <main>
        <?php if($error): ?><div class="card" style="border-color:var(--danger); color:var(--danger);"><?= $error ?></div><?php endif; ?>

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
             <div class="card" style="border-left: 4px solid var(--accent); background: linear-gradient(135deg, var(--surface), rgba(56, 189, 248, 0.05));">
                 <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:30px;">
                     <div>
                        <h2 style="font-size:20px; font-weight:800; margin-bottom:8px;">Inyección de Conocimiento Autoratativo</h2>
                        <p style="font-size:14px; color:var(--text-dim); line-height:1.6; max-width: 600px;">
                            Sube documentos (Markdown, JSON) para alimentar la memoria semántica de SUKI.
                            Solo fuentes confiables (Libros, Manuales, Leyes) son aceptadas por el <b>Knowledge Training Contract (KTC)</b>.
                        </p>
                     </div>
                     <div style="text-align:right;">
                        <span class="stat-badge badge-success">SISTEMA ACTIVO</span>
                        <div style="font-family:'JetBrains Mono'; font-size:10px; margin-top:10px; color:var(--accent);">VECTOR_ENGINE: QDRANT_0.16.x</div>
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
                        <!-- Aquí se listarían los últimos registros de ingestion_log -->
                        <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                            <td style="padding:15px;"><?= date('Y-m-d') ?></td>
                            <td style="padding:15px; font-weight:600;">fiscal_col</td>
                            <td style="padding:15px;"><span class="stat-badge badge-success">1.0</span></td>
                            <td style="padding:15px; color:var(--accent);">dian.gov.co</td>
                            <td style="padding:15px; color:var(--success);">✅ VECTORIZED</td>
                        </tr>
                     </tbody>
                 </table>
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
</body>
</html>
