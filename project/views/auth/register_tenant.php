<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registro — SUKI</title>
<style>
  :root { --cyan: #06b6d4; --cyan-dark: #0891b2; --bg: #f8fafc; }
  * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif; }
  body { background: var(--bg); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 2.5rem; width: 100%; max-width: 400px; }
  .logo { text-align: center; margin-bottom: 2rem; }
  .logo span { font-size: 1.75rem; font-weight: 700; color: var(--cyan-dark); }
  h1 { font-size: 1.25rem; font-weight: 600; color: #1e293b; margin-bottom: 0.5rem; }
  p.sub { color: #64748b; font-size: .875rem; margin-bottom: 1.5rem; }
  label { display: block; font-size: .875rem; font-weight: 500; color: #374151; margin-bottom: .25rem; }
  input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: .625rem .875rem; font-size: .9rem; outline: none; transition: border .2s; }
  input:focus { border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(6,182,212,.15); }
  .field { margin-bottom: 1rem; }
  .btn { width: 100%; background: var(--cyan); color: #fff; border: none; border-radius: 8px; padding: .75rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background .2s; margin-top: .5rem; }
  .btn:hover { background: var(--cyan-dark); }
  .msg { font-size: .875rem; padding: .75rem; border-radius: 8px; margin-top: 1rem; display: none; }
  .msg.ok { background: #ecfdf5; color: #065f46; }
  .msg.err { background: #fef2f2; color: #991b1b; }
  .login-link { text-align: center; margin-top: 1.5rem; font-size: .875rem; color: #64748b; }
  .login-link a { color: var(--cyan-dark); font-weight: 500; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
  <div class="logo"><span>SUKI</span></div>
  <h1>Crear cuenta</h1>
  <p class="sub">Registrate para empezar tu empresa en SUKI.</p>
  <form id="regForm">
    <div class="field"><label>Nombre de la empresa</label><input type="text" name="business_name" required placeholder="Mi Empresa SAS"></div>
    <div class="field"><label>NIT</label><input type="text" name="nit" required placeholder="900123456-1"></div>
    <div class="field"><label>Email</label><input type="email" name="email" required placeholder="admin@miempresa.co"></div>
    <div class="field"><label>Contrasena</label><input type="password" name="password" required minlength="8" placeholder="Minimo 8 caracteres"></div>
    <button type="submit" class="btn">Enviar codigo de verificacion</button>
  </form>
  <div id="msg" class="msg"></div>
  <div class="login-link">Ya tienes cuenta? <a href="/login">Iniciar sesion</a></div>
</div>
<script>
document.getElementById('regForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const body = Object.fromEntries(fd.entries());
  const msg = document.getElementById('msg');
  try {
    const r = await fetch('/api/auth/tenant-register', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
    });
    const data = await r.json();
    msg.style.display = 'block';
    if (data.ok) {
      msg.className = 'msg ok';
      msg.textContent = data.message;
      sessionStorage.setItem('reg_tenant_id', data.tenant_id);
      sessionStorage.setItem('reg_email', body.email);
      setTimeout(() => window.location.href = '/auth/verify-otp', 1500);
    } else {
      msg.className = 'msg err';
      msg.textContent = data.message || 'Error al registrar';
    }
  } catch (err) {
    msg.style.display = 'block';
    msg.className = 'msg err';
    msg.textContent = 'Error de conexion';
  }
});
</script>
</body>
</html>
