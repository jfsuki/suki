<?php
// framework/views/auth/otp-verify.php
// Verificar código OTP — confirma identidad, marca email_verified=1
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\AuthService;

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code       = trim(strip_tags((string) ($_POST['code'] ?? '')));
    $identifier = (string) ($_SESSION['otp_identifier'] ?? '');
    $projectId  = (string) ($_SESSION['otp_project']    ?? 'default');

    if ($code === '') {
        $error = 'Ingresa el código de verificación.';
    } elseif ($identifier === '') {
        $error = 'Sesión expirada. Solicita un nuevo código.';
    } else {
        $auth   = new AuthService();
        $result = $auth->verifyOtp($projectId, $identifier, $code);
        if ($result['ok']) {
            unset($_SESSION['otp_identifier'], $_SESSION['otp_project']);
            $success = $result['message'];
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI | Ingresar Código</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#F0F9FF; --card:#FFFFFF; --border:#BAE6FD; --accent:#0891B2; --accent-hover:#0E7490; --text:#0F172A; --text-dim:#64748B; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:linear-gradient(135deg,#F0F9FF,#E0F2FE,#CFFAFE); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:40px 20px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:20px; padding:48px; width:100%; max-width:420px; box-shadow:0 4px 6px rgba(8,145,178,.06),0 20px 40px rgba(15,23,42,.10); }
        h1 { font-size:24px; font-weight:800; color:var(--text); margin-bottom:8px; }
        p.sub { color:var(--text-dim); font-size:14px; margin-bottom:32px; }
        label { display:block; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:var(--text-dim); margin-bottom:8px; }
        .otp-input { width:100%; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:16px; font-size:28px; font-weight:800; letter-spacing:12px; text-align:center; font-family:inherit; outline:none; transition:all .2s; margin-bottom:24px; }
        .otp-input:focus { border-color:var(--accent); background:#fff; box-shadow:0 0 0 3px rgba(8,145,178,.12); }
        .btn { width:100%; background:var(--accent); color:#fff; border:none; border-radius:10px; padding:16px; font-size:15px; font-weight:700; cursor:pointer; transition:all .2s; }
        .btn:hover { background:var(--accent-hover); transform:translateY(-1px); }
        .error { background:rgba(239,68,68,.06); border:1px solid rgba(239,68,68,.25); color:#B91C1C; padding:14px; border-radius:10px; font-size:14px; margin-bottom:22px; }
        .success { background:rgba(5,150,105,.06); border:1px solid rgba(5,150,105,.25); color:#065F46; padding:18px; border-radius:12px; font-size:14px; margin-bottom:22px; }
        .links { margin-top:24px; text-align:center; font-size:14px; color:var(--text-dim); }
        .links a { color:var(--accent); text-decoration:none; font-weight:600; }
    </style>
</head>
<body>
<div class="card">
    <h1>Código de verificación</h1>
    <p class="sub">Ingresa los 6 dígitos enviados a tu correo.</p>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <strong>Identidad verificada.</strong><br>
            <?php echo htmlspecialchars($success); ?>
        </div>
        <a href="login" class="btn" style="display:block;text-align:center;text-decoration:none;padding:16px">Ir al inicio de sesión</a>
    <?php else: ?>
        <form method="POST">
            <label for="code">Código OTP</label>
            <input type="text" id="code" name="code" class="otp-input"
                   maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                   required placeholder="000000" autocomplete="one-time-code">
            <button type="submit" class="btn">Verificar identidad</button>
        </form>
    <?php endif; ?>

    <div class="links">
        <a href="otp-request">Solicitar nuevo código</a> &nbsp;·&nbsp;
        <a href="login">Inicio de sesión</a>
    </div>
</div>

<script>
    document.getElementById('code')?.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    });
</script>
</body>
</html>
