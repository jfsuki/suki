<?php
// framework/public/auth/register.php
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\RegistrationService;
use App\Core\ProjectRegistry;
use App\Core\PdfExtractorService;

session_start();

$registry = new ProjectRegistry();
$pdfExtractor = new PdfExtractorService();
$registration = new RegistrationService($registry->db(), $pdfExtractor);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = [
        'password'      => $_POST['password'] ?? '',
        'phone_number'  => $_POST['phone'] ?? '',
        'area_code'     => $_POST['area_code'] ?? '+57',
        'alt_phone'     => $_POST['alt_phone'] ?? '',
        'alt_email'     => $_POST['alt_email'] ?? '',
        'business_desc' => $_POST['business_desc'] ?? '',
        'nit'           => $_POST['nit'] ?? '', 
    ];

    $uploadedFile = $_FILES['rut'] ?? null;
    $detected = null;

    if ($uploadedFile && $uploadedFile['error'] === UPLOAD_ERR_OK) {
        $result = $registration->register($input, $uploadedFile);
        if ($result['success']) {
            $success = $result['msg'];
            $detected = $result['detected_info'] ?? null;
        } else {
            $error = $result['error'];
        }
    } else {
        $error = 'Es obligatorio cargar el RUT en formato PDF.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI | Registro de Empresa - Onboarding Seguro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #F0F9FF;
            --card: #FFFFFF;
            --border: #BAE6FD;
            --accent: #0891B2;
            --accent-hover: #0E7490;
            --text: #0F172A;
            --text-dim: #64748B;
            --success: #059669;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 50%, #CFFAFE 100%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .auth-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 48px;
            width: 100%;
            max-width: 580px;
            box-shadow: 0 4px 6px rgba(8,145,178,0.06), 0 20px 40px rgba(15,23,42,0.10);
            animation: fadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .logo-area { text-align: center; margin-bottom: 40px; }
        h1 { font-size: 26px; font-weight: 800; letter-spacing: -0.03em; margin-bottom: 12px; color: var(--text); }
        p.subtitle { color: var(--text-dim); font-size: 14px; margin-bottom: 32px; font-weight: 400; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        @media (max-width: 480px) { .form-grid { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 24px; }
        .full-width { grid-column: 1 / -1; }

        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; }
        
        input, textarea, select {
            width: 100%;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text);
            font-size: 15px;
            font-family: inherit;
            outline: none;
            transition: all 0.2s;
        }
        input:focus, textarea:focus { border-color: var(--accent); background: #FFFFFF; box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.12); }

        .file-upload {
            border: 1px dashed #BAE6FD;
            padding: 28px;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #F8FAFC;
            color: var(--text-dim);
        }
        .file-upload:hover { border-color: var(--accent); background: rgba(8,145,178,0.04); color: var(--accent); }

        .btn-primary {
            width: 100%;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 16px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 20px;
            letter-spacing: 0.02em;
        }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(8,145,178,0.25); }

        .error-msg { background: rgba(239,68,68,0.06); border: 1px solid rgba(239,68,68,0.25); color: #B91C1C; padding: 14px; border-radius: 10px; font-size: 14px; margin-bottom: 22px; text-align: center; }
        
        .detection-card {
            background: rgba(5, 150, 105, 0.06);
            border: 1px solid rgba(5, 150, 105, 0.25);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 28px;
            animation: slideIn 0.4s ease-out;
        }
        @keyframes slideIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

        .detection-card h3 { color: var(--success); font-size: 15px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .detection-item { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; }
        .detection-label { color: var(--text-dim); }
        .detection-val { font-weight: 600; color: var(--text); }

        .footer-links { margin-top: 32px; text-align: center; font-size: 14px; color: var(--text-dim); }
        .footer-links a { color: var(--accent); text-decoration: none; font-weight: 600; transition: color 0.2s; }
        .footer-links a:hover { color: var(--accent-hover); text-decoration: underline; }

        .alert-box { font-size: 11px; background: rgba(217,119,6,0.06); color: #92400E; padding: 10px 12px; border-radius: 8px; margin-top: 10px; border: 1px solid rgba(217,119,6,0.20); }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="logo-area">
            <h1>Crear Cuenta SUKI</h1>
            <p class="subtitle">Inteligencia Fiscal • Onboarding Seguro</p>
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <?php if ($detected): ?>
                <div class="detection-card">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Inteligencia SUKI: Datos Extraídos
                    </h3>
                    <div class="detection-item"><span class="detection-label">Entidad:</span> <span class="detection-val"><?php echo htmlspecialchars($detected['full_name']); ?></span></div>
                    <div class="detection-item"><span class="detection-label">NIT / ID:</span> <span class="detection-val"><?php echo htmlspecialchars($detected['nit']); ?></span></div>
                    <div class="detection-item"><span class="detection-label">Ubicación:</span> <span class="detection-val"><?php echo htmlspecialchars(($detected['location']['city'] ?? '') . ', ' . ($detected['location']['country'] ?? '')); ?></span></div>
                    <div class="detection-item"><span class="detection-label">Actividad:</span> <span class="detection-val"><?php echo htmlspecialchars($detected['activities']['primary'] ?? 'No detectada'); ?></span></div>
                </div>
            <?php endif; ?>

            <div style="background:rgba(16, 185, 129, 0.1); border:1px solid var(--success); padding:24px; border-radius:20px; text-align:center;">
                <h2 style="color:var(--success); font-size:20px; margin-bottom:12px;">¡Registro Completado!</h2>
                <p style="font-size:15px; line-height:1.6; color:#fff;">
                    Tu identidad fiscal ha sido validada. Nuestro equipo activará tu cuenta en las próximas 24-48 horas tras una breve llamada de bienvenida al número registrado.
                </p>
            </div>
        <?php else: ?>
            <form method="POST" action="" enctype="multipart/form-data" id="regForm">
                <div class="form-group">
                    <label>Archivo RUT (PDF de la DIAN)</label>
                    <div class="file-upload" onclick="document.getElementById('rut').click()">
                        <span id="fileName">Cargar PDF Original (Sin clave)</span>
                        <input type="file" id="rut" name="rut" accept="application/pdf" style="display:none" onchange="document.getElementById('fileName').innerText = this.files[0].name" required>
                    </div>
                    <div class="alert-box">
                        <strong>Escudo Anti-Bots activo:</strong> Solo se procesan documentos PDF legítimos para prevenir ataques de suplantación.
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="phone">Teléfono WhatsApp</label>
                        <div style="display:flex; gap:8px">
                            <input type="text" name="area_code" value="+57" style="width:65px; padding:14px 10px;">
                            <input type="tel" id="phone" name="phone" required placeholder="300 000 0000">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="alt_phone">Tel. Auxiliar</label>
                        <input type="tel" id="alt_phone" name="alt_phone" placeholder="Fijo o celular">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="alt_email">Correo Alternativo</label>
                        <input type="email" id="alt_email" name="alt_email" placeholder="notificaciones@empresa.com">
                    </div>

                    <div class="form-group full-width">
                        <label for="password">Contraseña de acceso</label>
                        <input type="password" id="password" name="password" required placeholder="Min. 8 caracteres">
                    </div>
                </div>

                <button type="submit" class="btn-primary" onclick="showShield(event)">Validar Identidad y Unirme</button>
            </form>
        <?php endif; ?>

        <div class="footer-links">
            ¿Ya tienes una empresa registrada? <a href="login">Iniciar Sesión</a>
        </div>
    </div>

    <!-- Escudo Anti-Bots Interstitial -->
    <div id="shieldOverlay" style="display:none; position:fixed; inset:0; background:rgba(3, 7, 18, 0.9); z-index:9999; display:none; flex-direction:column; align-items:center; justify-content:center; backdrop-filter:blur(20px);">
        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#22d3ee" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:20px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        <h2 style="color:#22d3ee; margin-bottom:10px;">Verificando Identidad...</h2>
        <p style="color:#9ca3af; font-size:14px;">Análisis anti-bots en curso para su seguridad.</p>
        <div style="width:200px; height:4px; background:rgba(255,255,255,0.1); border-radius:99px; margin-top:20px; overflow:hidden;">
            <div id="shieldBar" style="width:0%; height:100%; background:#22d3ee; transition: width 2s linear;"></div>
        </div>
    </div>

    <script>
        function showShield(e) {
            const form = document.getElementById('regForm');
            if(!form.checkValidity()) return;
            
            e.preventDefault();
            const overlay = document.getElementById('shieldOverlay');
            const bar = document.getElementById('shieldBar');
            overlay.style.display = 'flex';
            
            setTimeout(() => { bar.style.width = '100%'; }, 50);
            
            setTimeout(() => {
                form.submit();
            }, 2500);
        }
    </script>
</body>
</html>
