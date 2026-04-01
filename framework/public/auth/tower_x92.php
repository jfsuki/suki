<?php
/**
 * TOWER_X92 - SUKI Control Tower (Stealth Admin Panel)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\ProjectRegistry;
use App\Core\ControlTowerService;

// El router centraliza el inicio de sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$registry = new ProjectRegistry();
$tower = new ControlTowerService($registry);

$error = '';
$success = '';

// Manejo de Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['suki_tower_auth']);
    header('Location: tower'); // Redirigir vía router
    exit;
}

// Manejo de Login (Master Key)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['master_key'])) {
    if ($tower->verifyAccess($_POST['master_key'])) {
        $_SESSION['suki_tower_auth'] = true;
        $success = 'Acceso concedido. Comand Center activo.';
    } else {
        $error = 'Llave maestra inválida. Acceso denegado.';
        sleep(1);
    }
}

// Verificación de Autenticación
$isAuthenticated = $_SESSION['suki_tower_auth'] ?? false;

// Acciones Administrativas
if ($isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'activate' || $action === 'deactivate') {
        $targetId = $_POST['user_id'] ?? '';
        if ($action === 'activate') {
            if ($tower->activateCompany($targetId)) $success = "Entidad [$targetId] activada.";
        } else {
            if ($tower->deactivateCompany($targetId)) $success = "Entidad [$targetId] desactivada.";
        }
    } elseif ($action === 'create_creator') {
        $data = [
            'nit' => $_POST['nit'] ?? '',
            'full_name' => $_POST['full_name'] ?? '',
            'password' => $_POST['password'] ?? '',
        ];
        $res = $tower->createCreator($data);
        if ($res['success']) {
            $success = "Creador creado exitosamente: " . $res['user_id'];
        } else {
            $error = "Error al crear creador: " . $res['error'];
        }
    }
}

// Datos para el dashboard
$pendingEnterprises = $isAuthenticated ? $tower->getPendingRegistrations() : [];
$activeCreators = $isAuthenticated ? $tower->getCreators() : [];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚡ SUKI | CommandCenter</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #020617;
            --surface: #0f172a;
            --accent: #06b6d4;
            --accent-purple: #8b5cf6;
            --text: #f8fafc;
            --text-dim: #64748b;
            --border: rgba(255,255,255,0.08);
            --glass: rgba(15, 23, 42, 0.6);
            --success: #10b981;
            --danger: #ef4444;
        }

        * { margin:0; padding:0; box-sizing: border-box; }
        body {
            background-color: var(--bg);
            color: var(--text);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            background-image: 
                radial-gradient(at 0% 0%, rgba(6, 182, 212, 0.1) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(139, 92, 246, 0.1) 0px, transparent 50%);
        }

        header {
            padding: 20px 40px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(12px);
            position: sticky; top:0; z-index: 100;
        }

        .logo { font-weight: 800; font-size: 1.5rem; letter-spacing: -1px; display: flex; align-items: center; gap: 10px; }
        .logo span { color: var(--accent); }

        .container { max-width: 1400px; margin: 40px auto; padding: 0 20px; }

        .grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
        @media (max-width: 1024px) { .grid { grid-template-columns: 1fr; } }

        .glass-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        h2 { font-weight: 800; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        h2 small { font-size: 0.5em; opacity: 0.5; text-transform: uppercase; letter-spacing: 2px; }

        .alert { padding: 15px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; border: 1px solid transparent; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); color: #fca5a5; }
        .alert-success { background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.2); color: #6ee7b7; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; padding: 12px; font-size: 12px; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 16px 12px; border-bottom: 1px solid var(--border); font-size: 14px; }

        .mono { font-family: 'JetBrains Mono', monospace; font-size: 12px; }
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .badge-active { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .badge-pending { background: rgba(234, 179, 8, 0.1); color: #eab308; }

        .form-row { margin-bottom: 15px; }
        label { display: block; font-size: 12px; color: var(--text-dim); margin-bottom: 6px; font-weight: 600; }
        input { 
            width: 100%; background: rgba(0,0,0,0.3); border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px;
            color: white; font-family: inherit; outline: none; transition: 0.2s;
        }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1); }

        .btn { 
            padding: 10px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; border: none; font-size: 13px;
            transition: 0.2s; text-transform: uppercase; letter-spacing: 1px;
        }
        .btn-primary { background: var(--accent); color: #000; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(6, 182, 212, 0.3); }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--text-dim); }
        .btn-ghost:hover { border-color: var(--text); color: var(--text); }

        .logout { color: var(--text-dim); text-decoration: none; font-size: 13px; font-weight: 600; }
        .logout:hover { color: var(--danger); }
    </style>
</head>
<body>
    <header>
        <div class="logo">⚡ SUKI <span>CommandCenter</span></div>
        <?php if ($isAuthenticated): ?>
            <a href="?logout=1" class="logout">TERMINAR SESIÓN</a>
        <?php endif; ?>
    </header>

    <div class="container">
        <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

        <?php if (!$isAuthenticated): ?>
            <div style="max-width: 400px; margin: 100px auto;">
                <div class="glass-card">
                    <h2 style="justify-content: center;">Acceso Maestro</h2>
                    <form method="POST">
                        <div class="form-row">
                            <label>COMMAND MASTER KEY</label>
                            <input type="password" name="master_key" required placeholder="••••••••••••••••" autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">VALIDAR ACCESO</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="grid">
                <main>
                    <!-- Sección Empresas (Mundo 3) -->
                    <div class="glass-card" style="margin-bottom: 30px;">
                        <h2>Empresas en Espera <small>Mundo App (M3)</small></h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>Identidad</th>
                                    <th>Contacto</th>
                                    <th>Fecha</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendingEnterprises)): ?>
                                    <tr><td colspan="4" style="text-align: center; color: var(--text-dim);">No hay registros pendientes.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pendingEnterprises as $ent): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 700;"><?= $ent['full_name'] ?></div>
                                                <div class="mono"><?= $ent['nit'] ?></div>
                                                <div style="font-size: 10px; color: var(--accent);"><?= $ent['project_id'] ?></div>
                                            </td>
                                            <td><?= $ent['phone_number'] ?></td>
                                            <td class="mono"><?= substr($ent['created_at'], 0, 10) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="user_id" value="<?= $ent['id'] ?>">
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size:10px;">ACTIVAR</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Sección Creadores (Mundo 2) -->
                    <div class="glass-card">
                        <h2>Creadores de Aplicaciones <small>Mundo Builder (M2)</small></h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID / Nombre</th>
                                    <th>NIT</th>
                                    <th>Acceso</th>
                                    <th>Apps</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($activeCreators)): ?>
                                    <tr><td colspan="4" style="text-align: center; color: var(--text-dim);">No hay creadores registrados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($activeCreators as $cr): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 700;"><?= $cr['full_name'] ?></div>
                                                <div class="mono" style="opacity: 0.5;"><?= $cr['id'] ?></div>
                                            </td>
                                            <td class="mono"><?= $cr['nit'] ?></td>
                                            <td><span class="badge badge-active">AUTORIZADO</span></td>
                                            <td><button class="btn btn-ghost" style="padding: 4px 8px; font-size:9px;">VER PROYECTOS</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </main>

                <aside>
                    <!-- Formulario Nuevo Creador -->
                    <div class="glass-card">
                        <h2>Nuevo Creador <small>Añadir a Mundo 2</small></h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="create_creator">
                            <div class="form-row">
                                <label>NIT / IDENTIFICACIÓN</label>
                                <input type="text" name="nit" required placeholder="Ej: 900123456">
                            </div>
                            <div class="form-row">
                                <label>NOMBRE COMPLETO / AGENCIA</label>
                                <input type="text" name="full_name" required placeholder="Nombre Comercial">
                            </div>
                            <div class="form-row">
                                <label>CONTRASEÑA TEMPORAL</label>
                                <input type="password" name="password" required placeholder="••••••••">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">CREAR USUARIO CREADOR</button>
                        </form>
                    </div>

                    <div class="glass-card" style="margin-top: 30px; font-size: 12px; color: var(--text-dim);">
                        <h2 style="font-size: 14px;">Métricas del Sistema</h2>
                        <p>Inquilinos Activos: <?= count($activeCreators) ?></p>
                        <p>Pendientes: <?= count($pendingEnterprises) ?></p>
                        <hr style="border: none; border-top: 1px solid var(--border); margin: 15px 0;">
                        <p>Versión Core: 2.1.0-stealth</p>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
