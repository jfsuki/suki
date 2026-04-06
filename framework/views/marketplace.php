<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI Marketplace | Universo de Aplicaciones</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #050505;
            --card-bg: #111;
            --accent: #7c3aed;
            --accent-glow: rgba(124, 58, 237, 0.3);
            --text: #fff;
            --text-dim: #888;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
        }
        .hero {
            height: 60vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: radial-gradient(circle at center, #1e1b4b 0%, var(--bg) 70%);
        }
        h1 {
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 1rem;
            background: linear-gradient(to right, #fff, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .subtitle {
            font-size: 1.2rem;
            color: var(--text-dim);
            max-width: 600px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            padding: 4rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 24px;
            padding: 2rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .card:hover {
            transform: translateY(-10px);
            border-color: var(--accent);
            box-shadow: 0 0 30px var(--accent-glow);
        }
        .card h3 { font-size: 1.5rem; margin-bottom: 1rem; }
        .card p { color: var(--text-dim); line-height: 1.6; margin-bottom: 2rem; }
        .btn {
            display: inline-block;
            background: var(--accent);
            color: white;
            text-decoration: none;
            padding: 0.8rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.9; }
        .nav {
            padding: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }
        .logo { font-weight: 800; font-size: 1.5rem; letter-spacing: -1px; }
        .auth-links a {
            color: var(--text);
            text-decoration: none;
            margin-left: 2rem;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .badge {
            background: rgba(124, 58, 237, 0.1);
            color: var(--accent);
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <nav class="nav">
        <div class="logo">SUKI <span style="color: var(--accent)">OS</span></div>
        <div class="auth-links">
            <a href="login">Acceso Clientes</a>
            <a href="../../project/public/register-enterprise" style="background: white; color: black; padding: 10px 20px; border-radius: 10px;">Registrar Empresa</a>
        </div>
    </nav>

    <section class="hero">
        <h1>Marketplace</h1>
        <p class="subtitle">Explora, construye y despliega aplicaciones empresariales inteligentes en segundos. El futuro del software operativo está aquí.</p>
    </section>

    <div class="grid">
        <div class="card">
            <div class="badge">Administrativo</div>
            <h3>SUKI ERP Core</h3>
            <p>Control total de inventarios, compras y ventas con inteligencia fiscal integrada.</p>
            <a href="../../project/public/register-enterprise?app_id=suki_erp" class="btn">Instalar App</a>
        </div>
        <div class="card">
            <div class="badge">Ecommerce</div>
            <h3>Store Hub</h3>
            <p>Conecta tu tienda física con el mundo digital y gestiona todo desde un solo chat.</p>
            <a href="../../project/public/register-enterprise?app_id=store_hub" class="btn">Instalar App</a>
        </div>
        <div class="card">
            <div class="badge">Soporte</div>
            <h3>Agent Support</h3>
            <p>Atención al cliente automatizada con memoria semántica y resolución de dudas.</p>
            <a href="../../project/public/register-enterprise?app_id=agent_support" class="btn">Instalar App</a>
        </div>
    </div>
</body>
</html>
