<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI Marketplace | Universo de Aplicaciones</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #F8FAFC;
            --card-bg: #FFFFFF;
            --accent: #0891B2;
            --accent-glow: rgba(8, 145, 178, 0.15);
            --text: #0F172A;
            --text-dim: #64748B;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
        }
        .hero {
            height: 56vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 60%, #CFFAFE 100%);
            border-bottom: 1px solid #E2E8F0;
        }
        h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            color: var(--text);
            letter-spacing: -0.03em;
        }
        h1 span { color: var(--accent); }
        .subtitle {
            font-size: 1.1rem;
            color: var(--text-dim);
            max-width: 560px;
            line-height: 1.6;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 4rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 2rem;
            transition: all 0.2s ease;
            position: relative;
            box-shadow: 0 1px 3px rgba(15,23,42,0.06);
        }
        .card:hover {
            transform: translateY(-4px);
            border-color: var(--accent);
            box-shadow: 0 8px 24px var(--accent-glow);
        }
        .card h3 { font-size: 1.3rem; margin-bottom: 0.75rem; color: var(--text); }
        .card p { color: var(--text-dim); line-height: 1.6; margin-bottom: 1.75rem; font-size: 0.95rem; }
        .btn {
            display: inline-block;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            padding: 0.75rem 1.4rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: background 0.2s, box-shadow 0.2s;
        }
        .btn:hover { background: #0E7490; box-shadow: 0 4px 12px rgba(8,145,178,0.25); }
        .nav {
            padding: 1.25rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }
        .logo { font-weight: 800; font-size: 1.4rem; letter-spacing: -0.5px; color: var(--text); }
        .logo span { color: var(--accent); }
        .auth-links a {
            color: var(--text-dim);
            text-decoration: none;
            margin-left: 1.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            transition: color 0.2s;
        }
        .auth-links a:hover { color: var(--accent); }
        .badge {
            background: rgba(8, 145, 178, 0.08);
            color: var(--accent);
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 1rem;
            border: 1px solid rgba(8, 145, 178, 0.20);
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body>
    <nav class="nav">
        <div class="logo">SUKI <span>OS</span></div>
        <div class="auth-links">
            <a href="login">Acceso Clientes</a>
            <?php $__base = (str_contains($_SERVER['REQUEST_URI'] ?? '', '/suki/')) ? '/suki' : ''; ?>
            <a href="<?= $__base ?>/apps/register-enterprise" style="background: white; color: black; padding: 10px 20px; border-radius: 10px;">Registrar Empresa</a>
        </div>
    </nav>

    <section class="hero">
        <h1>SUKI <span>Marketplace</span></h1>
        <p class="subtitle">Explora, construye y despliega aplicaciones empresariales inteligentes en segundos. El futuro del software operativo está aquí.</p>
    </section>

    <div class="grid">
        <div class="card">
            <div class="badge">Administrativo</div>
            <h3>SUKI ERP Core</h3>
            <p>Control total de inventarios, compras y ventas con inteligencia fiscal integrada.</p>
            <a href="<?= $__base ?>/apps/register-enterprise?app_id=suki_erp" class="btn">Instalar App</a>
        </div>
        <div class="card">
            <div class="badge">Ecommerce</div>
            <h3>Store Hub</h3>
            <p>Conecta tu tienda física con el mundo digital y gestiona todo desde un solo chat.</p>
            <a href="<?= $__base ?>/apps/register-enterprise?app_id=store_hub" class="btn">Instalar App</a>
        </div>
        <div class="card">
            <div class="badge">Soporte</div>
            <h3>Agent Support</h3>
            <p>Atención al cliente automatizada con memoria semántica y resolución de dudas.</p>
            <a href="<?= $__base ?>/apps/register-enterprise?app_id=agent_support" class="btn">Instalar App</a>
        </div>
    </div>
</body>
</html>
