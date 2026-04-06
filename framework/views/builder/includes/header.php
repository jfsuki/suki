<?php // framework/views/builder/includes/header.php ?>
<!doctype html>
<html lang="es" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="SUKI Builder Hub — Software Architectural Control">
  <title>SUKI Architect | Builder Hub</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <style>
    /* ─── BUILDER WORLD TOKENS ────────────────────────────────── */
    :root {
      --bg:            #0a0f1e;
      --surface:       #111827;
      --surface2:      #1a2235;
      --border:        rgba(255,255,255,0.07);
      --glow:          rgba(139, 92, 246, 0.35);
      --text:          #e2e8f0;
      --muted:         #64748b;
      --accent:        #8b5cf6;
      --accent-soft:   rgba(139,92,246,0.12);
      --teal:          #14b8a6;
      --teal-soft:     rgba(20,184,166,0.12);
      --danger:        #ef4444;
      --success:       #22c55e;
      --warn:          #f59e0b;
      --radius:        14px;
      --radius-sm:     8px;
      --shadow:        0 20px 60px rgba(0,0,0,0.5);
      --font:          'Inter', system-ui, sans-serif;
      --transition:    0.22s cubic-bezier(0.4,0,0.2,1);
    }
    
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    
    body {
      font-family: var(--font);
      background: var(--bg);
      color: var(--text);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      background-image:
        radial-gradient(ellipse 80% 60% at 10% -10%, rgba(139,92,246,0.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 110%, rgba(20,184,166,0.12) 0%, transparent 50%);
    }

    /* Standardized Builder layout utilities */
    .view-container {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
      overflow: hidden;
    }
  </style>
</head>
<body>
