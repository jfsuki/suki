<?php
$__base = (str_contains($_SERVER['REQUEST_URI'] ?? '', '/suki/')) ? '/suki' : '';
$catalogPath = dirname(__DIR__, 2) . '/project/contracts/app_catalog.json';
$__apps      = [];   // apps disponibles para instalar (status:available)
$__templates = [];   // plantillas para builders (status:template) — sección separada
if (file_exists($catalogPath)) {
    $raw = json_decode(file_get_contents($catalogPath), true);
    foreach ($raw['apps'] ?? [] as $a) {
        $st = $a['status'] ?? '';
        if ($st === 'available')  { $__apps[]      = $a; }
        if ($st === 'template')   { $__templates[] = $a; }
    }
}
?>
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
            <a href="<?= $__base ?>/marketplace/login">Acceso Clientes</a>
            <a href="<?= $__base ?>/apps/register-enterprise" style="background: white; color: black; padding: 10px 20px; border-radius: 10px;">Registrar Empresa</a>
        </div>
    </nav>

    <section class="hero">
        <h1>SUKI <span>Marketplace</span></h1>
        <p class="subtitle">Explora, construye y despliega aplicaciones empresariales inteligentes en segundos. El futuro del software operativo está aquí.</p>
    </section>

    <div class="grid">
        <?php if (empty($__apps)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:5rem 2rem;">
            <div style="font-size:3rem;margin-bottom:1rem;">🔧</div>
            <h2 style="color:var(--text);margin-bottom:0.75rem;font-size:1.5rem;">Aún no hay apps publicadas</h2>
            <p style="color:var(--text-dim);max-width:420px;margin:0 auto 2rem;line-height:1.7;">
                El Marketplace está listo. Los creadores de apps pueden publicar aquí para que cualquier empresa las use.
            </p>
            <a href="<?= $__base ?>/builder-login" style="display:inline-block;background:var(--accent);color:#fff;padding:0.85rem 1.75rem;border-radius:10px;font-weight:700;text-decoration:none;margin-right:1rem;">
                Crear y publicar una App
            </a>
            <a href="<?= $__base ?>/marketplace/login" style="display:inline-block;background:transparent;color:var(--accent);border:2px solid var(--accent);padding:0.8rem 1.75rem;border-radius:10px;font-weight:700;text-decoration:none;">
                Soy una empresa →
            </a>
        </div>
        <?php else: foreach ($__apps as $__app):
            $__id   = htmlspecialchars($__app['id'],          ENT_QUOTES, 'UTF-8');
            $__name = htmlspecialchars($__app['name'],        ENT_QUOTES, 'UTF-8');
            $__cat  = htmlspecialchars($__app['category'],    ENT_QUOTES, 'UTF-8');
            $__desc = htmlspecialchars($__app['description'], ENT_QUOTES, 'UTF-8');
        ?>
        <div class="card">
            <div class="badge"><?= $__cat ?></div>
            <h3><?= $__name ?></h3>
            <p><?= $__desc ?></p>
            <a href="<?= $__base ?>/apps/register-enterprise?app_id=<?= $__id ?>" class="btn">Instalar App</a>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if (!empty($__templates)): ?>
    <!-- Sección plantillas — visibles pero no instalables directamente -->
    <div style="max-width:1200px;margin:0 auto;padding:0 4rem 2rem;">
        <h2 style="font-size:1.4rem;font-weight:700;color:var(--text);margin-bottom:0.5rem;">
            Próximamente en el Marketplace
        </h2>
        <p style="color:var(--text-dim);font-size:0.92rem;margin-bottom:2rem;">
            Estas plantillas están en desarrollo. Un Builder puede tomarlas como punto de partida
            y publicarlas una vez implementadas.
        </p>
    </div>
    <div class="grid" style="padding-top:0;">
        <?php foreach ($__templates as $__tpl):
            $__tid   = htmlspecialchars($__tpl['id'],          ENT_QUOTES, 'UTF-8');
            $__tname = htmlspecialchars($__tpl['name'],        ENT_QUOTES, 'UTF-8');
            $__tcat  = htmlspecialchars($__tpl['category'],    ENT_QUOTES, 'UTF-8');
            $__tdesc = htmlspecialchars($__tpl['description'], ENT_QUOTES, 'UTF-8');
        ?>
        <div class="card" style="opacity:0.72;border-style:dashed;">
            <div class="badge" style="background:rgba(100,116,139,0.08);color:var(--text-dim);border-color:rgba(100,116,139,0.2);"><?= $__tcat ?></div>
            <h3 style="color:var(--text-dim);"><?= $__tname ?></h3>
            <p><?= $__tdesc ?></p>
            <a href="<?= $__base ?>/builder-login" style="display:inline-block;background:transparent;color:var(--text-dim);border:1.5px solid #CBD5E1;padding:0.65rem 1.2rem;border-radius:8px;font-weight:600;font-size:0.88rem;text-decoration:none;">
                Construir esta app →
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</body>
</html>
