<?php
/**
 * framework/views/errors/suki_error.php
 * Vista de error personalizada con estética premium de SUKI OS.
 */
$code = $_GET['code'] ?? '404';
$titles = [
    '404' => 'Mundo No Encontrado',
    '403' => 'Acceso Restringido',
    '500' => 'Colapso del Kernel',
];
$descriptions = [
    '404' => 'La ruta especificada no existe en la arquitectura actual de los 4 Mundos.',
    '403' => 'No tienes los privilegios necesarios para acceder a esta capa del Hidden Layer.',
    '500' => 'Se ha detectado una anomalía crítica en la ejecución del Kernel. Los logs de AgentOps han sido notificados.',
];

$title = $titles[$code] ?? 'Error de Infraestructura';
$desc = $descriptions[$code] ?? 'Se ha producido una excepción no controlada en el entorno.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUKI OS | ERROR <?php echo $code; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #020617;
            --accent: #ef4444; /* Rojo para errores */
            <?php if($code === '403'): ?> --accent: #f59e0b; <?php endif; ?>
            <?php if($code === '404'): ?> --accent: #3b82f6; <?php endif; ?>
            --accent-glow: rgba(239, 68, 68, 0.2);
            --text: #f8fafc;
            --text-dim: #94a3b8;
        }

        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background-image: 
                radial-gradient(circle at 50% 50%, rgba(15, 23, 42, 1), var(--bg));
        }

        .error-container {
            text-align: center;
            max-width: 600px;
            padding: 40px;
            position: relative;
            z-index: 10;
        }

        .glitch-code {
            font-size: 15rem;
            font-weight: 800;
            line-height: 1;
            background: linear-gradient(135deg, var(--text) 0%, var(--text-dim) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0.1;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: -1;
            letter-spacing: -1rem;
        }

        h1 {
            font-size: 3rem;
            font-weight: 800;
            letter-spacing: -2px;
            margin-bottom: 20px;
            color: var(--text);
        }

        .description {
            font-size: 1.25rem;
            color: var(--text-dim);
            margin-bottom: 40px;
            font-weight: 300;
            line-height: 1.6;
        }

        .btn-home {
            display: inline-block;
            padding: 16px 32px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 100px;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 0.8rem;
        }

        .btn-home:hover {
            background: var(--text);
            color: var(--bg);
            transform: scale(1.05);
            box-shadow: 0 0 30px rgba(255, 255, 255, 0.1);
        }

        .footer-logo {
            margin-top: 80px;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5rem;
            color: var(--accent);
            font-weight: 800;
            opacity: 0.5;
        }

        /* Pulsing background effect */
        .ambient-glow {
            position: absolute;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 50%, var(--accent-glow), transparent 70%);
            animation: pulse-glow 4s infinite ease-in-out;
            z-index: 1;
        }

        @keyframes pulse-glow {
            0%, 100% { opacity: 0.3; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.2); }
        }
    </style>
</head>
<body>
    <div class="ambient-glow"></div>
    
    <div class="error-container">
        <div class="glitch-code"><?php echo $code; ?></div>
        <h1><?php echo $title; ?></h1>
        <p class="description"><?php echo $desc; ?></p>
        
        <a href="/suki/marketplace" class="btn-home">Retornar al Marketplace</a>
        
        <div class="footer-logo">SUKI OS CORE SECURE</div>
    </div>
</body>
</html>
