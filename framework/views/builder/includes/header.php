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
    /* ─── BUILDER WORLD TOKENS — SUKI White Cyan Corporate ─────── */
    :root {
      --bg:            #F0F9FF;
      --surface:       #FFFFFF;
      --surface2:      #F1F5F9;
      --border:        #E2E8F0;
      --glow:          rgba(8,145,178,0.15);
      --text:          #0F172A;
      --muted:         #64748B;
      --accent:        #0891B2;
      --accent-soft:   rgba(8,145,178,0.08);
      --teal:          #06B6D4;
      --teal-soft:     rgba(6,182,212,0.10);
      --danger:        #EF4444;
      --success:       #059669;
      --warn:          #D97706;
      --radius:        12px;
      --radius-sm:     8px;
      --shadow:        0 1px 3px rgba(15,23,42,0.06), 0 4px 16px rgba(15,23,42,0.04);
      --font:          'Inter', system-ui, sans-serif;
      --transition:    0.20s cubic-bezier(0.4,0,0.2,1);
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
        radial-gradient(ellipse 80% 60% at 10% -10%, rgba(8,145,178,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 110%, rgba(6,182,212,0.04) 0%, transparent 50%);
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
