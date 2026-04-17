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
            --bg: #F0F9FF;
            --card: #FFFFFF;
            --border: #BAE6FD;
            --accent: #0891B2;
            --accent-hover: #0E7490;
            --accent-glow: rgba(8, 145, 178, 0.18);
            --text: #0F172A;
            --text-dim: #64748B;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 50%, #CFFAFE 100%);
            color: var(--text);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .auth-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 48px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 4px 6px rgba(8,145,178,0.06), 0 20px 40px rgba(15,23,42,0.10);
            animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header-area { text-align: center; margin-bottom: 40px; }

        .logo-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #0891B2, #06B6D4);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            box-shadow: 0 8px 20px rgba(8,145,178,0.20);
        }

        h1 { font-size: 26px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 8px; color: var(--text); }
        .subtitle { color: var(--text-dim); font-size: 14px; }

        .form-group { margin-bottom: 22px; }
        label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; }

        input {
            width: 100%;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 13px 16px;
            color: var(--text);
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
            outline: none;
        }

        input:focus {
            border-color: var(--accent);
            background: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.12);
        }

        .btn-submit {
            width: 100%;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 15px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
            letter-spacing: 0.05em;
        }

        .btn-submit:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(8,145,178,0.25);
        }

        .error-msg {
            background: rgba(239, 68, 68, 0.06);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #B91C1C;
            padding: 12px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 22px;
            text-align: center;
        }

        .badge {
            background: rgba(8, 145, 178, 0.08);
            color: var(--accent);
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 12px;
            border: 1px solid rgba(8, 145, 178, 0.20);
            text-transform: uppercase;
            letter-spacing: 0.05em;
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
