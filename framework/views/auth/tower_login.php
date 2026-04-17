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
            --bg: #F0F9FF;
            --card: #FFFFFF;
            --border: #BAE6FD;
            --accent: #0891B2;
            --accent-glow: rgba(8, 145, 178, 0.18);
            --text: #0F172A;
            --text-dim: #64748B;
        }

        * { margin:0; padding:0; box-sizing:border-box; }

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

        .login-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(8,145,178,0.06), 0 20px 40px rgba(15,23,42,0.10);
            animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1);
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
            background: rgba(8, 145, 178, 0.08);
            color: var(--accent);
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border: 1px solid rgba(8, 145, 178, 0.20);
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
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 14px 18px;
            color: var(--text);
            font-size: 1.1rem;
            font-family: inherit;
            outline: none;
            transition: all 0.2s;
            text-align: center;
            letter-spacing: 0.4rem;
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
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            transition: all 0.2s;
        }

        .btn-submit:hover {
            background: #0E7490;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(8,145,178,0.25);
        }

        .error-msg {
            color: #B91C1C;
            background: rgba(239, 68, 68, 0.06);
            border: 1px solid rgba(239, 68, 68, 0.25);
            padding: 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 18px;
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
