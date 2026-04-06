<?php
// framework/public/auth/login.php
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\AuthService;
use App\Core\ProjectRegistry;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthService();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = $_POST['identifier'] ?? '';
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $result = $auth->login('default', $identifier, $password, $ip);

    if ($result['success']) {
        // Redirigir al mundo proyecto detectando el subdirectorio /suki/
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $base = (strpos($uri, '/suki/') !== false) ? '/suki' : '';
        
        // Redirección inteligente por rol
        $role = $_SESSION['role'] ?? 'user';
        if ($role === 'creator' || $role === 'architect') {
            header("Location: $base/builder");
        } else {
            header("Location: $base/apps/dashboard");
        }
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
    <title>SUKI | Iniciar Sesión - Seguridad Avanzada</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #030712;
            --glass: rgba(17, 24, 39, 0.7);
            --border: rgba(255, 255, 255, 0.1);
            --accent: #22d3ee;
            --accent-hover: #0891b2;
            --text: #f9fafb;
            --text-dim: #9ca3af;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: radial-gradient(circle at top right, #1e1b4b, transparent),
                        radial-gradient(circle at bottom left, #0f172a, transparent);
        }

        .auth-card {
            background: var(--glass);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-area {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo-area svg {
            width: 48px;
            height: 48px;
            fill: var(--accent);
            margin-bottom: 12px;
        }

        h1 {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.025em;
            margin-bottom: 8px;
        }

        p.subtitle {
            color: var(--text-dim);
            font-size: 14px;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dim);
        }

        input {
            width: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            font-size: 15px;
            transition: all 0.2s;
            outline: none;
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(34, 211, 238, 0.1);
        }

        .btn-primary {
            width: 100%;
            background: var(--accent);
            color: #000;
            border: none;
            border-radius: 12px;
            padding: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, background 0.2s;
            margin-top: 10px;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .error-msg {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            padding: 12px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 20px;
            text-align: center;
        }

        .footer-links {
            margin-top: 32px;
            text-align: center;
            font-size: 14px;
            color: var(--text-dim);
        }

        .footer-links a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #10b981;
            background: rgba(16, 185, 129, 0.1);
            padding: 4px 10px;
            border-radius: 99px;
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="logo-area">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 2.18l7 3.12v5.7c0 4.66-3.14 8.98-7 10.12-3.86-1.14-7-5.46-7-10.12V6.3l7-3.12z"/>
                <path d="M12 7c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.67 0-5 .83-5 2.5V17h10v-1.5c0-1.67-3.33-2.5-5-2.5z"/>
            </svg>
            <h1>Acceso Clientes</h1>
            <p class="subtitle">Gestiona tu empresa con inteligencia neural</p>
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="identifier">NIT o Usuario de Empresa</label>
                <input type="text" id="identifier" name="identifier" required placeholder="Ej: 900.123.456-1" autofocus>
            </div>
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required placeholder="••••••••">
            </div>
            
            <button type="submit" class="btn-primary">Entrar al Sistema</button>
        </form>

        <div class="footer-links">
            ¿Aún no tienes cuenta? <a href="register.php">Registrarse ahora</a>
        </div>

        <div style="text-align: center;">
            <div class="security-badge">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Protección Anti-Bots Activa (IP: <?php echo $_SERVER['REMOTE_ADDR']; ?>)
            </div>
        </div>
    </div>
</body>
</html>
