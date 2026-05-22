<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verificar codigo — SUKI</title>
<style>
  :root { --cyan: #06b6d4; --cyan-dark: #0891b2; --bg: #f8fafc; }
  * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', system-ui, sans-serif; }
  body { background: var(--bg); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 2.5rem; width: 100%; max-width: 400px; }
  .logo { text-align: center; margin-bottom: 2rem; }
  .logo span { font-size: 1.75rem; font-weight: 700; color: var(--cyan-dark); }
  h1 { font-size: 1.25rem; font-weight: 600; color: #1e293b; margin-bottom: .5rem; }
  p.sub { color: #64748b; font-size: .875rem; margin-bottom: 1.5rem; }
  label { display: block; font-size: .875rem; font-weight: 500; color: #374151; margin-bottom: .25rem; }
  input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: .625rem .875rem; font-size: 1.5rem; letter-spacing: .5rem; text-align: center; outline: none; transition: border .2s; }
  input:focus { border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(6,182,212,.15); }
  .field { margin-bottom: 1rem; }
  .btn { width: 100%; background: var(--cyan); color: #fff; border: none; border-radius: 8px; padding: .75rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background .2s; margin-top: .5rem; }
  .btn:hover { background: var(--cyan-dark); }
  .msg { font-size: .875rem; padding: .75rem; border-radius: 8px; margin-top: 1rem; display: none; }
  .msg.ok { background: #ecfdf5; color: #065f46; }
  .msg.err { background: #fef2f2; color: #991b1b; }
  .back { text-align: center; margin-top: 1.5rem; font-size: .875rem; color: #64748b; }
  .back a { color: var(--cyan-dark); font-weight: 500; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
  <div class="logo"><span>SUKI</span></div>
  <h1>Verificar codigo</h1>
  <p class="sub" id="subText">Ingresa el codigo de 6 digitos enviado a tu email.</p>
  <form id="verifyForm">
    <div class="field"><label>Codigo de verificacion</label>
      <input type="text" name="code" required maxlength="6" pattern="[0-9]{6}" placeholder="000000" inputmode="numeric">
    </div>
    <button type="submit" class="btn">Verificar y activar cuenta</button>
  </form>
  <div id="msg" class="msg"></div>
  <div class="back"><a href="/auth/register">Volver al registro</a></div>
</div>
<script>
const tenantId = sessionStorage.getItem('reg_tenant_id') || '';
const email    = sessionStorage.getItem('reg_email') || '';
if (email) document.getElementById('subText').textContent = 'Ingresa el codigo enviado a ' + email;

document.getElementById('verifyForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const code = e.target.code.value.trim();
  const msg  = document.getElementById('msg');
  try {
    const r = await fetch('/api/auth/tenant-verify-otp', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ tenant_id: tenantId, email, code })
    });
    const data = await r.json();
    msg.style.display = 'block';
    if (data.ok) {
      msg.className = 'msg ok';
      msg.textContent = 'Cuenta activada! Redirigiendo al login...';
      sessionStorage.removeItem('reg_tenant_id');
      sessionStorage.removeItem('reg_email');
      setTimeout(() => window.location.href = '/login', 2000);
    } else {
      msg.className = 'msg err';
      msg.textContent = data.message || 'Codigo incorrecto';
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
