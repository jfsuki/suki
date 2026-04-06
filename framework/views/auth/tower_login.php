<?php
/**
 * framework/views/auth/tower_login.php
 * Muro de seguridad para la Torre de Control (Master Key login).
 */
$error = $error ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI OS | AUTH — TOWER</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #030712;
            --glass: rgba(17, 24, 39, 0.7);
            --border: rgba(255, 255, 255, 0.1);
            --accent: #22d3ee;
            --accent-glow: rgba(34, 211, 238, 0.3);
            --text: #f8fafc;
            --text-dim: #94a3b8;
        }

        * { margin:0; padding:0; box-sizing:border-box; }

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
                radial-gradient(circle at 10% 10%, #1e1b4b, transparent 40%),
                radial-gradient(circle at 90% 90%, #0f172a, transparent 40%);
        }

        .login-card {
            background: var(--glass);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--border);
            border-radius: 32px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 50px 100px -20px rgba(0,0,0,0.5);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-area {
            margin-bottom: 40px;
        }

        .tower-badge {
            display: inline-block;
            background: rgba(34, 211, 238, 0.1);
            color: var(--accent);
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            border: 1px solid rgba(34, 211, 238, 0.3);
            margin-bottom: 12px;
        }

        h1 {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -1px;
            margin-bottom: 8px;
        }

        .subtitle {
            font-size: 0.9rem;
            color: var(--text-dim);
            margin-bottom: 30px;
        }

        .form-group {
            position: relative;
            margin-bottom: 24px;
        }

        input {
            width: 100%;
            background: rgba(0,0,0,0.5);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            color: #fff;
            font-size: 1rem;
            font-family: inherit;
            outline: none;
            transition: all 0.3s;
            text-align: center;
            letter-spacing: 0.5rem;
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .btn-submit {
            width: 100%;
            background: var(--text);
            color: var(--bg);
            border: none;
            border-radius: 12px;
            padding: 16px;
            font-size: 0.9rem;
            font-weight: 800;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            transform: scale(1.02);
            filter: brightness(1.1);
        }

        .error-msg {
            color: #fca5a5;
            background: rgba(239, 68, 68, 0.1);
            padding: 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            margin-bottom: 20px;
        }

        footer {
            margin-top: 40px;
            font-size: 0.7rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.1rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-area">
            <span class="tower-badge">Layer 4 Gateway</span>
            <h1>ACCESO TOWER</h1>
            <p class="subtitle">Ingrese la Master Key para desbloquear la infraestructura.</p>
        </div>

        <?php if($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <input type="password" name="master_key" required placeholder="••••••••" autofocus>
            </div>
            <button type="submit" class="btn-submit">Sincronizar Acceso</button>
        </form>

        <footer>SUKI AI-AOS SECURE ENVIRONMENT</footer>
    </div>
</body>
</html>
