<?php
// project/views/register_enterprise.php
declare(strict_types=1);

use App\Core\RegistrationService;
use App\Core\PdfExtractorService;
use App\Core\ProjectRegistry;

$error = '';
$success = '';
$appId = $_GET['app_id'] ?? 'suki_erp'; // Default to suki_erp if not provided
$isCreator = ($appId === 'suki_builder');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registry = new ProjectRegistry();
    $pdfService = new PdfExtractorService();
    $regService = new RegistrationService($registry->db(), $pdfService);

    if ($isCreator) {
        $input = [
            'full_name'    => $_POST['full_name'] ?? '',
            'password'     => $_POST['password'] ?? '',
            'phone_number' => $_POST['phone'] ?? '',
            'email'        => $_POST['email'] ?? '',
        ];
        $result = $regService->registerCreator($input);
    } else {
        $input = [
            'project_id' => $appId,
            'password'   => $_POST['password'] ?? '',
            'phone_number' => $_POST['phone'] ?? '',
            'business_desc' => $_POST['desc'] ?? '',
        ];
        $result = $regService->register($input, $_FILES['rut_file'] ?? []);
    }

    if ($result['success']) {
        if ($isCreator) {
            $success = "¡Felicidades! Tu cuenta de <strong>Creador</strong> ha sido activada.";
        } else {
            $success = "¡Registro exitoso! Tu empresa ha sido vinculada a <strong>" . strtoupper(str_replace('_', ' ', $appId)) . "</strong>. Un supervisor activará tu cuenta tras validar el RUT.";
        }
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
            --bg: #F0F9FF;
            --card: #FFFFFF;
            --border: #BAE6FD;
            --accent: #0891B2;
            --accent-glow: rgba(8, 145, 178, 0.18);
            --text: #0F172A;
            --text-dim: #64748B;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 50%, #CFFAFE 100%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .reg-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 4px 6px rgba(8,145,178,0.06), 0 20px 40px rgba(15,23,42,0.10);
            animation: fadeIn 0.5s ease-out;
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
            background: rgba(8, 145, 178, 0.08);
            color: var(--accent);
            padding: 6px 12px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
            border: 1px solid rgba(8, 145, 178, 0.20);
        }

        h1 { font-size: 26px; font-weight: 800; letter-spacing: -1px; margin-bottom: 8px; }
        .subtitle { color: var(--text-dim); font-size: 14px; }

        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text-dim); }
        
        input, textarea {
            width: 100%;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text);
            font-size: 15px;
            font-family: inherit;
            transition: all 0.2s;
            outline: none;
        }

        input:focus, textarea:focus {
            border-color: var(--accent);
            background: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.12);
        }

        .file-custom {
            display: block;
            background: #F8FAFC;
            border: 1px dashed #BAE6FD;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-dim);
        }
        .file-custom:hover { background: rgba(8, 145, 178, 0.04); border-color: var(--accent); color: var(--accent); }

        .btn-submit {
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
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .btn-submit:hover {
            background: #0E7490;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(8,145,178,0.25);
        }

        .alert {
            padding: 14px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 14px;
            text-align: center;
        }
        .alert-error { background: rgba(239, 68, 68, 0.06); color: #B91C1C; border: 1px solid rgba(239, 68, 68, 0.25); }
        .alert-success { background: rgba(5, 150, 105, 0.08); color: #065F46; border: 1px solid rgba(5, 150, 105, 0.25); }

        label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; }

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
            <h1><?php echo $isCreator ? 'Habilitar Perfil Creador' : 'Configura tu Empresa'; ?></h1>
            <p class="subtitle"><?php echo $isCreator ? 'Sincroniza tu identidad con el Neural Hub de SUKI' : 'Sube tu RUT para activar tu instancia inteligente'; ?></p>
        </div>


        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
            <div style="text-align: center; margin-top: 20px;">
                <?php $__base = (str_contains($_SERVER['REQUEST_URI'] ?? '', '/suki/')) ? '/suki' : ''; ?>
                <a href="<?= $__base ?>/marketplace/login" style="color: var(--accent); text-decoration: none; font-weight: 700;">Volver al Portal</a>
            </div>
        <?php else: ?>
            <form action="" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Nombre Completo</label>
                    <input type="text" name="full_name" placeholder="Ej: Juan Pérez" required>
                </div>

                <?php if (!$isCreator): ?>
                <div class="form-group">
                    <label>Registro Único Tributario (RUT PDF)</label>
                    <input type="file" name="rut_file" id="rut_file" accept="application/pdf" required style="display:none;" onchange="document.getElementById('file-label').innerText = this.files[0].name">
                    <label for="rut_file" id="file-label" class="file-custom">
                        Haga clic o arrastre el PDF original aquí
                    </label>
                </div>
                <?php else: ?>
                <div class="form-group">
                    <label>Correo Electrónico</label>
                    <input type="email" name="email" placeholder="creador@suki.os" required>
                </div>
                <?php endif; ?>


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

                <button type="submit" class="btn-submit"><?php echo $isCreator ? 'Desplegar Perfil' : 'Finalizar Activación'; ?></button>

            </form>
        <?php endif; ?>
    </div>

    <footer>
        SUKI OS &copy; 2026 | IA Infrastructure Protected
    </footer>
</body>
</html>
