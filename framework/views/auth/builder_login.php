<?php
// framework/views/auth/builder_login.php
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\AuthService;
use App\Core\ProjectRegistry;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthService();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = $_POST['identifier'] ?? '';
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Para el builder, usamos el projectId 'default' que verifica Master Users
    $result = $auth->login('default', $identifier, $password, $ip);

    if ($result['success']) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $base = (strpos($uri, '/suki/') !== false) ? '/suki' : '';
        header("Location: $base/builder"); 
        exit;
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI | Builder Hub - Arquitectos</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #030712;
            --glass: rgba(17, 24, 39, 0.7);
            --border: rgba(255, 255, 255, 0.1);
            --accent: #a855f7; /* Purple for builders/creators */
            --accent-glow: rgba(168, 85, 247, 0.3);
            --text: #f9fafb;
            --text-dim: #9ca3af;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: 
                radial-gradient(circle at top right, #3b0764, transparent 50%),
                radial-gradient(circle at bottom left, #1e1b4b, transparent 50%);
        }

        .auth-card {
            background: var(--glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 32px;
            padding: 48px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header-area { text-align: center; margin-bottom: 40px; }

        .logo-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #a855f7, #6366f1);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            box-shadow: 0 8px 20px var(--accent-glow);
        }

        h1 { font-size: 28px; font-weight: 800; letter-spacing: -1px; margin-bottom: 8px; }
        .subtitle { color: var(--text-dim); font-size: 15px; }

        .form-group { margin-bottom: 24px; }
        label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.5px; }
        
        input {
            width: 100%;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 18px;
            color: white;
            font-size: 16px;
            transition: all 0.2s;
            outline: none;
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .btn-submit {
            width: 100%;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 14px;
            padding: 16px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
            letter-spacing: 0.5px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px var(--accent-glow);
            filter: brightness(1.1);
        }

        .error-msg {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 14px;
            border-radius: 14px;
            font-size: 14px;
            margin-bottom: 25px;
            text-align: center;
        }

        .badge {
            background: rgba(168, 85, 247, 0.1);
            color: var(--accent);
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 800;
            display: inline-block;
            margin-bottom: 12px;
            border: 1px solid rgba(168, 85, 247, 0.2);
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="header-area">
            <div class="logo-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="16 18 22 12 16 6"></polyline>
                    <polyline points="8 6 2 12 8 18"></polyline>
                </svg>
            </div>
            <br>
            <span class="badge">Builder Hub</span>
            <h1>Arquitectos de Software</h1>
            <p class="subtitle">Acceso directo al orquestador de apps</p>
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="identifier">Usuario Creador</label>
                <input type="text" id="identifier" name="identifier" required placeholder="Ej: jfeliciano" autofocus>
            </div>
            <div class="form-group">
                <label for="password">Contraseña Maestra</label>
                <input type="password" id="password" name="password" required placeholder="••••••••">
            </div>
            
            <button type="submit" class="btn-submit">Entrar al Builder</button>
        </form>

        <div style="text-align: center; margin-top: 32px;">
            <p style="font-size: 13px; color: var(--text-dim);">
                &copy; 2026 SUKI AI-AOS | Neuron Security Active
            </p>
        </div>
    </div>
</body>
</html>
