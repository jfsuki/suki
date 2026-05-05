<?php
/**
 * framework/views/auth/tower_tenant_select.php
 * Paso 2 del login Tower: selección de tenant scope.
 */
$__baseTower = $__baseTower ?? '';
$tenants = [];

try {
    $pdo = \App\Core\Database::getConnection();
    $stmt = $pdo->query("SELECT DISTINCT tenant_id FROM tenant_business_config ORDER BY tenant_id ASC");
    $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
    $tenants = array_filter($rows, fn($t) => $t !== '');
} catch (\Throwable $e) {
    // DB not ready — allow manual entry
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI OS | TOWER — Seleccionar Tenant</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#F0F9FF; --card:#FFFFFF; --border:#BAE6FD; --accent:#0891B2; --text:#0F172A; --text-dim:#64748B; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Outfit',sans-serif; background:linear-gradient(135deg,#F0F9FF,#E0F2FE,#CFFAFE); min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:40px; width:100%; max-width:400px; box-shadow:0 4px 24px rgba(8,145,178,.10); }
        h1 { font-size:1.4rem; font-weight:800; color:var(--text); margin-bottom:6px; }
        p.sub { color:var(--text-dim); font-size:.85rem; margin-bottom:28px; }
        label { display:block; font-size:.8rem; font-weight:600; color:var(--text-dim); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px; }
        select, input[type=text] { width:100%; padding:12px 14px; border:1px solid var(--border); border-radius:8px; font-family:inherit; font-size:.95rem; color:var(--text); background:#fff; outline:none; }
        select:focus, input[type=text]:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(8,145,178,.12); }
        button { width:100%; margin-top:20px; padding:13px; background:var(--accent); color:#fff; border:none; border-radius:8px; font-family:inherit; font-size:1rem; font-weight:700; cursor:pointer; }
        button:hover { opacity:.9; }
        .hint { margin-top:16px; font-size:.78rem; color:var(--text-dim); text-align:center; }
        a { color:var(--accent); text-decoration:none; }
    </style>
</head>
<body>
<div class="card">
    <h1>🏢 Seleccionar empresa</h1>
    <p class="sub">Elige el tenant que vas a operar en esta sesión Tower.</p>
    <form method="POST" action="<?= htmlspecialchars($__baseTower) ?>/torre/select-tenant">
        <label for="tower_tenant">Tenant ID</label>
        <?php if (!empty($tenants)): ?>
            <select name="tower_tenant" id="tower_tenant" required>
                <option value="">— Selecciona —</option>
                <?php foreach ($tenants as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input type="text" name="tower_tenant" id="tower_tenant" placeholder="demo_tenant" required pattern="[a-zA-Z0-9_\-]+">
        <?php endif; ?>
        <button type="submit">Ingresar →</button>
    </form>
    <p class="hint"><a href="<?= htmlspecialchars($__baseTower) ?>/torre/logout">Cerrar sesión</a></p>
</div>
</body>
</html>
