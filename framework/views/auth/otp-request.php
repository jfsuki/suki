<?php
// framework/views/auth/otp-request.php
// Solicitar OTP — envía código al email registrado del usuario
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\AuthService;
use App\Core\ProjectRegistry;

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim(strip_tags((string) ($_POST['identifier'] ?? '')));
    $email      = trim(strip_tags((string) ($_POST['email']      ?? '')));
    $projectId  = 'default';

    if ($identifier === '' || $email === '') {
        $error = 'Completa todos los campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email inválido.';
    } else {
        $auth   = new AuthService();
        $result = $auth->requestOtp($projectId, $identifier, $email);
        if ($result['ok']) {
            $success = 'Código enviado a ' . htmlspecialchars($email) . '. Revisa tu bandeja de entrada.';
            $_SESSION['otp_identifier'] = $identifier;
            $_SESSION['otp_project']    = $projectId;
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
    <title>SUKI | Verificar Identidad</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#F0F9FF; --card:#FFFFFF; --border:#BAE6FD; --accent:#0891B2; --accent-hover:#0E7490; --text:#0F172A; --text-dim:#64748B; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:linear-gradient(135deg,#F0F9FF,#E0F2FE,#CFFAFE); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:40px 20px; }
        .card { background:#fff; border:1px solid var(--border); border-radius:20px; padding:48px; width:100%; max-width:460px; box-shadow:0 4px 6px rgba(8,145,178,.06),0 20px 40px rgba(15,23,42,.10); }
        h1 { font-size:24px; font-weight:800; color:var(--text); margin-bottom:8px; }
        p.sub { color:var(--text-dim); font-size:14px; margin-bottom:32px; }
        label { display:block; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; color:var(--text-dim); margin-bottom:8px; }
        input { width:100%; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:12px 16px; font-size:15px; font-family:inherit; outline:none; transition:all .2s; margin-bottom:20px; }
        input:focus { border-color:var(--accent); background:#fff; box-shadow:0 0 0 3px rgba(8,145,178,.12); }
        .btn { width:100%; background:var(--accent); color:#fff; border:none; border-radius:10px; padding:16px; font-size:15px; font-weight:700; cursor:pointer; transition:all .2s; }
        .btn:hover { background:var(--accent-hover); transform:translateY(-1px); }
        .error { background:rgba(239,68,68,.06); border:1px solid rgba(239,68,68,.25); color:#B91C1C; padding:14px; border-radius:10px; font-size:14px; margin-bottom:22px; }
        .success { background:rgba(5,150,105,.06); border:1px solid rgba(5,150,105,.25); color:#065F46; padding:14px; border-radius:10px; font-size:14px; margin-bottom:22px; }
        .links { margin-top:24px; text-align:center; font-size:14px; color:var(--text-dim); }
        .links a { color:var(--accent); text-decoration:none; font-weight:600; }
    </style>
</head>
<body>
<div class="card">
    <h1>Verificar Identidad</h1>
    <p class="sub">Recibirás un código de 6 dígitos en tu correo.</p>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <a href="otp-verify" class="btn" style="display:block;text-align:center;text-decoration:none;padding:16px">Ingresar código</a>
    <?php else: ?>
        <form method="POST">
            <label for="identifier">Teléfono o usuario</label>
            <input type="text" id="identifier" name="identifier" required placeholder="300 000 0000" autocomplete="username">
            <label for="email">Correo electrónico</label>
            <input type="email" id="email" name="email" required placeholder="tu@empresa.com" autocomplete="email">
            <button type="submit" class="btn">Enviar código</button>
        </form>
    <?php endif; ?>

    <div class="links"><a href="login">Volver al inicio de sesión</a></div>
</div>
</body>
</html>
