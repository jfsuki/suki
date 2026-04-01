<?php
// project/views/register_enterprise.php
declare(strict_types=1);

use App\Core\RegistrationService;
use App\Core\PdfExtractorService;
use App\Core\ProjectRegistry;

$error = '';
$success = '';
$appId = $_GET['app_id'] ?? 'suki_erp'; // Default to suki_erp if not provided

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registry = new ProjectRegistry();
    $pdfService = new PdfExtractorService();
    $regService = new RegistrationService($registry->db(), $pdfService);

    $input = [
        'project_id' => $appId,
        'password'   => $_POST['password'] ?? '',
        'phone_number' => $_POST['phone'] ?? '',
        'business_desc' => $_POST['desc'] ?? '',
    ];

    $result = $regService->register($input, $_FILES['rut_file'] ?? []);

    if ($result['success']) {
        $success = "¡Registro exitoso! Tu empresa ha sido vinculada a <strong>" . strtoupper(str_replace('_', ' ', $appId)) . "</strong>. Un supervisor activará tu cuenta tras validar el RUT.";
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI | Registro de Empresa - <?php echo strtoupper($appId); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #030712;
            --glass: rgba(17, 24, 39, 0.7);
            --border: rgba(255, 255, 255, 0.1);
            --accent: #22d3ee;
            --accent-glow: rgba(34, 211, 238, 0.3);
            --text: #f9fafb;
            --text-dim: #9ca3af;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: 
                radial-gradient(circle at top right, #1e1b4b, transparent 50%),
                radial-gradient(circle at bottom left, #0f172a, transparent 50%);
        }

        .reg-card {
            background: var(--glass);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 40px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header-area {
            text-align: center;
            margin-bottom: 30px;
        }

        .badge-app {
            display: inline-block;
            background: rgba(34, 211, 238, 0.1);
            color: var(--accent);
            padding: 6px 12px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
            border: 1px solid rgba(34, 211, 238, 0.2);
        }

        h1 { font-size: 26px; font-weight: 800; letter-spacing: -1px; margin-bottom: 8px; }
        .subtitle { color: var(--text-dim); font-size: 14px; }

        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text-dim); }
        
        input, textarea {
            width: 100%;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
            outline: none;
        }

        input:focus, textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .file-custom {
            display: block;
            background: rgba(255, 255, 255, 0.03);
            border: 1px dashed var(--border);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .file-custom:hover { background: rgba(34, 211, 238, 0.05); border-color: var(--accent); }

        .btn-submit {
            width: 100%;
            background: var(--accent);
            color: #000;
            border: none;
            border-radius: 12px;
            padding: 16px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px var(--accent-glow);
            filter: brightness(1.1);
        }

        .alert {
            padding: 15px;
            border-radius: 14px;
            margin-bottom: 25px;
            font-size: 14px;
            text-align: center;
            backdrop-filter: blur(5px);
        }
        .alert-error { background: rgba(239, 68, 68, 0.1); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
        .alert-success { background: rgba(16, 185, 129, 0.1); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }

        footer {
            position: absolute;
            bottom: 20px;
            font-size: 12px;
            color: var(--text-dim);
            text-align: center;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="reg-card">
        <div class="header-area">
            <span class="badge-app">Aplicación: <?php echo str_replace('_', ' ', $appId); ?></span>
            <h1>Configura tu Empresa</h1>
            <p class="subtitle">Sube tu RUT para activar tu instancia inteligente</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
            <div style="text-align: center; margin-top: 20px;">
                <a href="../../framework/public/login" style="color: var(--accent); text-decoration: none; font-weight: 700;">Volver al Portal</a>
            </div>
        <?php else: ?>
            <form action="" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Registro Único Tributario (RUT PDF)</label>
                    <input type="file" name="rut_file" id="rut_file" accept="application/pdf" required style="display:none;" onchange="document.getElementById('file-label').innerText = this.files[0].name">
                    <label for="rut_file" id="file-label" class="file-custom">
                        Haga clic o arrastre el PDF original aquí
                    </label>
                </div>

                <div class="form-group">
                    <label>Teléfono de Contacto</label>
                    <input type="text" name="phone" placeholder="Ej: 300 123 4567" required>
                </div>

                <div class="form-group">
                    <label>Crea una Contraseña de Acceso</label>
                    <input type="password" name="password" required placeholder="••••••••">
                </div>

                <div class="form-group">
                    <label>¿Qué hace tu negocio? (Opcional)</label>
                    <textarea name="desc" placeholder="Breve descripción..." rows="2"></textarea>
                </div>

                <button type="submit" class="btn-submit">Finalizar Activación</button>
            </form>
        <?php endif; ?>
    </div>

    <footer>
        SUKI OS &copy; 2026 | IA Infrastructure Protected
    </footer>
</body>
</html>
