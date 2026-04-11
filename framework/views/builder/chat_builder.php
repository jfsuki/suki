<?php 
$current_page = 'chat';
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/navbar.php'; 
?>
<style>
    /* ─── CHAT-SPECIFIC LAYOUT ───────────────────────────────── */
    .layout {
      display: grid;
      grid-template-columns: 260px 1fr 300px;
      gap: 12px;
      padding: 12px;
      flex: 1;
      min-height: 0;
    }
    .panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      display: flex;
      flex-direction: column;
      min-height: 0;
      overflow: hidden;
      transition: var(--transition);
      position: relative;
    }
    .panel-view {
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background: var(--surface);
      z-index: 10;
      display: none;
      flex-direction: column;
      animation: fadeIn 0.3s ease;
    }
    .panel-view.active { display: flex; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    /* ─── KANBAN STYLES ───────────────────────────────────────── */
    .kanban-board {
      display: flex;
      gap: 16px;
      padding: 16px;
      overflow-x: auto;
      flex: 1;
      background: var(--surface2);
    }
    .kanban-col {
      min-width: 280px;
      max-width: 320px;
      background: rgba(100,116,139,0.05);
      border-radius: 12px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      padding: 12px;
      border: 1px solid var(--border);
    }
    .kanban-col-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 4px 8px;
    }
    .kanban-col-title { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--muted); letter-spacing: 0.5px; }
    .kanban-col-count { background: var(--border); color: var(--text); font-size: 10px; padding: 2px 8px; border-radius: 10px; }
    .kanban-items { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; min-height: 50px; }
    .kanban-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px;
      box-shadow: var(--shadow-sm);
      cursor: grab;
      transition: var(--transition);
    }
    .kanban-card:hover { border-color: var(--accent); transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .kanban-card-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
    .kanban-card-sub { font-size: 11px; color: var(--muted); margin-bottom: 8px; }
    .kanban-card-foot { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 8px; font-size: 11px; }
    .kanban-card-price { font-weight: 700; color: var(--teal); }

    /* ─── DASHBOARD STYLES ────────────────────────────────────── */
    .dash-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      padding: 20px;
    }
    .stat-card {
      background: var(--surface);
      border: 1px solid var(--border);
      padding: 20px;
      border-radius: 16px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .stat-val { font-size: 24px; font-weight: 700; color: var(--text); }
    .stat-label { font-size: 12px; color: var(--muted); font-weight: 500; }
    .chart-container { padding: 0 20px 20px; flex: 1; min-height: 300px; display: flex; align-items: center; justify-content: center; }
    .panel-head {
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }
    .panel-icon {
      width: 28px; height: 28px;
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px;
      flex-shrink: 0;
    }
    .panel-icon.purple { background: var(--accent-soft); }
    .panel-icon.teal   { background: var(--teal-soft); }
    .panel-icon.slate  { background: rgba(100,116,139,0.15); }
    .panel-head h3 {
      font-size: 12px;
      font-weight: 600;
      color: var(--text);
      flex: 1;
      letter-spacing: 0.2px;
    }
    .panel-body { flex: 1; overflow-y: auto; padding: 12px 14px; min-height: 0; }
    .panel-body::-webkit-scrollbar { width: 4px; }
    .panel-body::-webkit-scrollbar-track { background: transparent; }
    .panel-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

    /* ─── PIPELINE STATUS ────────────────────────────────────── */
    .pipeline-steps { display: flex; flex-direction: column; gap: 6px; }
    .step {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 9px 11px;
      border-radius: var(--radius-sm);
      font-size: 12px;
      font-weight: 500;
      border: 1px solid transparent;
      transition: var(--transition);
      cursor: default;
    }
    .step .dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      background: var(--muted);
      flex-shrink: 0;
      transition: var(--transition);
    }
    .step.active {
      background: var(--accent-soft);
      border-color: rgba(139,92,246,0.2);
      color: var(--accent);
    }
    .step.active .dot { background: var(--accent); box-shadow: 0 0 8px var(--accent); animation: pulse 1.4s infinite; }
    .step.done   { color: var(--success); }
    .step.done .dot { background: var(--success); }
    .step.skip   { opacity: 0.35; }
    @keyframes pulse {
      0%,100% { opacity:1; transform:scale(1); }
      50%      { opacity:.5; transform:scale(1.35); }
    }
    .step-label { flex: 1; }
    .step-time { font-size: 10px; color: var(--muted); }

    /* ─── ENTITY LIST ─────────────────────────────────────────── */
    .entity-list { display: flex; flex-direction: column; gap: 6px; }
    .entity-item {
      padding: 9px 11px;
      border-radius: var(--radius-sm);
      font-size: 12px;
      background: var(--surface2);
      border: 1px solid var(--border);
      display: flex; align-items: center; gap: 8px;
      transition: var(--transition);
    }
    .entity-item:hover { border-color: var(--accent); }
    .entity-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--teal); flex-shrink: 0; }
    .entity-name { flex: 1; font-weight: 500; }
    .entity-badge {
      font-size: 10px; padding: 1px 6px;
      border-radius: 999px; background: var(--teal-soft);
      color: var(--teal); font-weight: 600;
    }
    .empty-hint {
      text-align: center; color: var(--muted);
      font-size: 12px; padding: 24px 8px; line-height: 1.6;
    }
    .empty-hint .icon { font-size: 28px; margin-bottom: 8px; display: block; }

    /* ─── CHAT ────────────────────────────────────────────────── */
    .chat-panel {
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
    .chat-messages {
      flex: 1; overflow-y: auto;
      padding: 16px;
      display: flex; flex-direction: column; gap: 12px;
      min-height: 0;
      scroll-behavior: smooth;
    }
    .chat-messages::-webkit-scrollbar { width: 4px; }
    .chat-messages::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
    .msg {
      display: flex; gap: 10px; max-width: 85%;
      animation: msgIn 0.28s cubic-bezier(0.34,1.56,0.64,1) both;
    }
    @keyframes msgIn {
      from { opacity:0; transform:translateY(10px) scale(0.97); }
      to   { opacity:1; transform:translateY(0) scale(1); }
    }
    .msg.user { align-self: flex-end; flex-direction: row-reverse; }
    .msg-avatar {
      width: 30px; height: 30px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; flex-shrink: 0; font-weight: 600;
    }
    .msg.user .msg-avatar {
      background: linear-gradient(135deg, var(--accent), #7c3aed);
      color: #fff; box-shadow: 0 0 12px var(--glow);
    }
    .msg.bot .msg-avatar {
      background: var(--teal-soft);
      color: var(--teal);
      border: 1px solid rgba(20,184,166,0.25);
    }
    .msg-bubble {
      padding: 11px 14px;
      border-radius: 14px;
      font-size: 13.5px;
      line-height: 1.65;
    }
    .msg.user .msg-bubble {
      background: linear-gradient(135deg, var(--accent) 0%, #7c3aed 100%);
      color: #fff;
      border-radius: 14px 4px 14px 14px;
      box-shadow: 0 4px 20px var(--glow);
    }
    .msg.bot .msg-bubble {
      background: var(--surface2);
      border: 1px solid var(--border);
      color: var(--text);
      border-radius: 4px 14px 14px 14px;
    }
    /* ─── SESSION SIDEBAR ───────────────────────────────────── */
    .session-sidebar { display: flex; flex-direction: column; gap: 8px; border-right: 0; }
    .session-item {
        padding: 10px 12px; border-radius: 12px; cursor: pointer; transition: 0.2s;
        border: 1px solid transparent; font-size: 11.5px; color: var(--muted);
        display: flex; align-items: center; gap: 8px;
    }
    .session-item:hover { background: var(--surface2); color: var(--text); }
    .session-item.active { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); font-weight: 600; }
    .session-item .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--muted); }
    .session-item.active .dot { background: var(--accent); }
    .new-chat-btn {
        background: var(--surface2); border: 1px dashed var(--border);
        width: 100%; padding: 10px; border-radius: 12px; color: var(--text);
        font-size: 11.5px; font-weight: 600; cursor: pointer; transition: 0.2s;
        margin-bottom: 8px;
    }
    .new-chat-btn:hover { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); }

    /* ─── JOURNAL PANE ────────────────────────────────────────── */
    .journal-entry { margin-bottom: 12px; }
    .journal-label { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; display: block; }
    .journal-text { font-size: 12px; color: var(--text); line-height: 1.5; background: var(--surface2); padding: 10px; border-radius: 10px; border: 1px solid var(--border); }
    .task-item { display: flex; align-items: center; gap: 8px; font-size: 11.5px; margin-bottom: 6px; }
    .task-check { width: 14px; height: 14px; border: 1.5px solid var(--border); border-radius: 4px; flex-shrink: 0; }
    .task-check.done { background: var(--teal); border-color: var(--teal); }
    .task-check.done::after { content: '✓'; color: #fff; font-size: 8px; display: block; text-align: center; }

    .msg-meta { font-size: 10px; color: var(--muted); margin-top: 4px; }
    .msg.user .msg-meta { text-align: right; }

    /* Typing indicator */
    .typing-bubble {
      display: flex; align-items: center; gap: 5px;
      padding: 12px 16px;
      background: var(--surface2); border: 1px solid var(--border);
      border-radius: 4px 14px 14px 14px;
      width: fit-content;
    }
    .typing-bubble span {
      width: 7px; height: 7px; border-radius: 50%;
      background: var(--accent); animation: bounce 1.2s infinite;
    }
    .typing-bubble span:nth-child(2) { animation-delay: 0.2s; }
    .typing-bubble span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes bounce {
      0%,60%,100% { transform:translateY(0); opacity:.5; }
      30%          { transform:translateY(-5px); opacity:1; }
    }

    /* ─── INPUT BAR ──────────────────────────────────────────── */
    .chat-input-wrap {
      padding: 12px 14px;
      border-top: 1px solid var(--border);
      flex-shrink: 0;
      background: var(--surface);
    }
    .chat-input-row {
      display: flex; align-items: flex-end; gap: 8px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 8px 8px 8px 14px;
      transition: var(--transition);
    }
    .chat-input-row:focus-within {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(139,92,246,0.12);
    }
    #chatInput {
      flex: 1;
      background: none; border: none; outline: none;
      color: var(--text); font-family: var(--font);
      font-size: 13.5px; resize: none;
      max-height: 120px; min-height: 20px; overflow-y: auto;
      line-height: 1.5;
    }
    #chatInput::placeholder { color: var(--muted); }
    #sendBtn {
      background: linear-gradient(135deg, var(--accent), #7c3aed);
      border: none; border-radius: 10px;
      width: 36px; height: 36px; flex-shrink: 0;
      cursor: pointer; display: flex; align-items: center; justify-content: center;
      font-size: 15px; color: #fff;
      box-shadow: 0 0 14px var(--glow);
      transition: var(--transition);
    }
    #sendBtn:hover { transform: scale(1.08); }
    #sendBtn:active { transform: scale(0.95); }
    #sendBtn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
    .input-hint { font-size: 10px; color: var(--muted); margin-top: 7px; text-align: center; }

    /* ─── METRICS PANEL ──────────────────────────────────────── */
    .metrics-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
    .metric-card {
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 10px 12px;
    }
    .metric-val { font-size: 20px; font-weight: 700; color: var(--text); line-height: 1; margin-bottom: 3px; }
    .metric-val.accent { color: var(--accent); }
    .metric-val.teal   { color: var(--teal); }
    .metric-val.warn   { color: var(--warn); }
    .metric-val.success { color: var(--success); }
    .metric-lbl { font-size: 10px; color: var(--muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
    .section-lbl {
      font-size: 10px; font-weight: 600; color: var(--muted);
      text-transform: uppercase; letter-spacing: 0.8px;
      margin: 14px 0 8px;
    }

    /* route path trace */
    .route-trace {
      display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 12px;
    }
    .route-step {
      font-size: 10px; font-weight: 600; padding: 3px 8px;
      border-radius: 999px;
      background: var(--surface2);
      border: 1px solid var(--border);
      color: var(--muted);
      text-transform: uppercase; letter-spacing: 0.5px;
    }
    .route-step.active { background: var(--accent-soft); border-color: rgba(139,92,246,0.3); color: var(--accent); }

    /* agent orquestrator badge */
    .orchestrator-badge {
      display: flex; align-items: center; gap: 8px;
      background: var(--accent-soft);
      border: 1px solid rgba(139,92,246,0.2);
      border-radius: var(--radius-sm);
      padding: 9px 11px;
      margin-bottom: 10px;
    }
    .orchestrator-badge .ob-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--accent);
      box-shadow: 0 0 8px var(--accent);
      animation: pulse 2s infinite;
      flex-shrink: 0;
    }
    .orchestrator-badge .ob-text { font-size: 11px; color: var(--accent); font-weight: 600; }
    .orchestrator-badge .ob-sub  { font-size: 10px; color: var(--muted); }

    /* test mode toggle */
    .test-toggle {
      display: flex; align-items: center; justify-content: space-between;
      padding: 9px 11px;
      background: var(--surface2); border: 1px solid var(--border);
      border-radius: var(--radius-sm); margin-bottom: 10px;
      font-size: 11px; font-weight: 500; caption-side
    }
    .toggle-switch {
      position: relative; width: 34px; height: 18px;
    }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; inset: 0;
      background: var(--surface); border-radius: 9px; cursor: pointer;
      transition: var(--transition); border: 1px solid var(--border);
    }
    .toggle-slider::before {
      content: '';
      position: absolute; width: 12px; height: 12px;
      border-radius: 50%; left: 2px; bottom: 2px;
      background: var(--muted); transition: var(--transition);
    }
    .toggle-switch input:checked + .toggle-slider { background: var(--accent-soft); border-color: var(--accent); }
    .toggle-switch input:checked + .toggle-slider::before {
      background: var(--accent); transform: translateX(16px);
      box-shadow: 0 0 6px var(--accent);
    }

    /* ─── ACTION BUTTONS ─────────────────────────────────────── */
    .action-btn {
      display: flex; align-items: center; gap: 10px;
      padding: 12px 14px; margin-bottom: 10px;
      background: var(--surface2); border: 1px solid var(--border);
      border-radius: var(--radius); cursor: pointer;
      text-decoration: none; color: var(--text);
      font-size: 13px; font-weight: 600;
      transition: var(--transition);
    }
    .action-btn:hover { border-color: var(--accent); background: var(--accent-soft); transform: translateY(-1px); }
    .action-btn i { font-style: normal; font-size: 18px; }
    .action-btn.primary {
      background: linear-gradient(135deg, var(--accent), #7c3aed);
      color: #fff; border: none; box-shadow: 0 4px 12px var(--glow);
    }
    .action-btn.primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px var(--glow); }

    /* ─── MODAL ──────────────────────────────────────────────── */
    .modal-overlay {
      position: fixed; inset: 0; z-index: 1000;
      background: rgba(0,0,0,0.8); backdrop-filter: blur(4px);
      display: none; align-items: center; justify-content: center;
      padding: 20px;
    }
    .modal-overlay.open { display: flex; }
    .modal-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--radius); width: 100%; max-width: 500px;
      box-shadow: 0 24px 60px rgba(0,0,0,0.5);
      animation: modalIn 0.3s cubic-bezier(0.34,1.56,0.64,1);
    }
    @keyframes modalIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
    .modal-head {
      padding: 18px 20px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .modal-head h2 { font-size: 15px; font-weight: 700; }
    .modal-body { padding: 20px; }
    .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }
    
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; margin-bottom: 6px; }
    .form-input {
      width: 100%; background: var(--surface2); border: 1px solid var(--border);
      color: var(--text); padding: 10px 12px; border-radius: var(--radius-sm);
      font-size: 13px; outline: none; transition: var(--transition);
    }
    .form-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }

    /* responsive */
    @media (max-width: 900px) {
      .layout { grid-template-columns: 1fr; padding: 0; gap: 0; }
      .layout > *:not(.chat-panel) { display: none; }
      .panel { border: none; border-radius: 0; height: 100vh; }
      .chat-messages { padding: 12px; }
      .view-tabs { 
        position: absolute; 
        bottom: 70px; right: 12px; 
        flex-direction: column; 
        z-index: 100;
        box-shadow: var(--shadow-lg);
      }
    }
  </style>
</head>
<body>

<!-- NAV -->
<nav>
  <div class="nav-brand">
    <div class="nav-logo">⚡</div>
    SUKI
    <span class="nav-badge">Builder</span>
  </div>
  <div class="nav-actions">
    <a href="chat_app.html" class="nav-btn">🚀 Ir al App</a>
    <a href="#" class="nav-btn primary" id="testModeNav">🧪 Test Mode</a>
  </div>
</nav>

<!-- MAIN LAYOUT -->
<div class="layout">

  <!-- PANEL 0: Temas (Multiplexación) -->
  <div class="panel session-sidebar" id="sessionPanel">
    <div class="panel-head">
      <div class="panel-icon purple">🎨</div>
      <h3>Temas</h3>
    </div>
    <div class="panel-body">
      <button class="new-chat-btn" onclick="createNewSession()">+ Nueva sesión</button>
      <div id="sessionList">
        <!-- Sessions will be loaded here -->
        <div class="session-item active">
          <div class="dot"></div>
          <div class="session-title">Cargando...</div>
        </div>
      </div>
    </div>
  </div>

  <!-- PANEL IZQUIERDO: Entidades del proyecto -->
  <div class="panel">
    <div class="panel-head">
      <div class="panel-icon teal">📦</div>
      <h3>Proyecto Activo</h3>
    </div>
    <div class="panel-body">
      
      <!-- FAST ACTIONS -->
      <a href="reports.html" target="_blank" class="action-btn primary">
        <i>📊</i> Ver Dashboard de Reportes
      </a>
      <button class="action-btn" onclick="openSmtpSettings()">
        <i>📧</i> Configurar Correo (SMTP)
      </button>

      <div class="section-lbl">Tablas creadas</div>
      <div class="entity-list" id="entityList">
        <div class="empty-hint">
          <span class="icon">✨</span>
          Aún no hay tablas.<br>Dile a SUKI qué tipo de negocio tienes.
        </div>
      </div>

      <div class="section-lbl" id="formSectionLbl" style="display:none">Formularios</div>
      <div class="entity-list" id="formList"></div>
    </div>
  </div>

  <!-- PANEL CENTRO: Multi-View Panel -->
  <div class="panel chat-panel" style="position: relative;">
    <div class="panel-head">
      <div class="panel-icon purple">💬</div>
      <h3 id="panelTitle">Chat con SUKI Builder</h3>
      <div class="view-tabs" style="display: flex; gap: 8px; background: var(--surface2); padding: 4px; border-radius: 10px; border: 1px solid var(--border);">
        <button onclick="switchView('chat')" id="tab-chat" class="tab-btn active" style="border:0; background:var(--accent); color:#fff; font-size:10px; padding:4px 10px; border-radius:6px; cursor:pointer; font-weight:600;">CHAT</button>
        <button onclick="switchView('kanban')" id="tab-kanban" class="tab-btn" style="border:0; background:transparent; color:var(--muted); font-size:10px; padding:4px 10px; border-radius:6px; cursor:pointer; font-weight:600;">KANBAN</button>
        <button onclick="switchView('dash')" id="tab-dash" class="tab-btn" style="border:0; background:transparent; color:var(--muted); font-size:10px; padding:4px 10px; border-radius:6px; cursor:pointer; font-weight:600;">DASHBOARD</button>
      </div>
    </div>

    <!-- VIEW: CHAT -->
    <div id="view-chat" class="panel-view active" style="display:flex; flex-direction:column; flex:1; min-height:0;">
      <div class="chat-messages" id="chatMessages">
        <!-- Messages will be loaded here -->
        <div class="empty-hint">Conectando con SUKI...</div>
      </div>
      <div class="chat-input-wrap">
        <div class="chat-input-row">
          <textarea id="chatInput" placeholder="Ej: tienda de ropa con inventario y ventas..."
            rows="1" autocomplete="off"></textarea>
          <button id="sendBtn" title="Enviar">➤</button>
        </div>
        <div class="input-hint">Enter para enviar · Shift+Enter nueva línea</div>
      </div>
    </div>

    <!-- VIEW: KANBAN -->
    <div id="view-kanban" class="panel-view" style="display:none; flex-direction:column; flex:1; min-height:0; background:var(--surface2);">
      <div class="kanban-board" id="kanbanContainer">
        <div class="empty-hint" style="margin:auto;">
          <i class="fas fa-spinner fa-spin icon" style="font-size:24px; margin-bottom:10px; display:block;"></i>
          Cargando tablero...
        </div>
      </div>
    </div>

    <!-- VIEW: DASHBOARD -->
    <div id="view-dash" class="panel-view" style="display:none; flex-direction:column; flex:1; min-height:0;">
      <div class="dash-grid" id="dashSummary"></div>
      <div class="chart-container" style="flex:1; padding:20px; display:flex; align-items:center;">
        <canvas id="mainChart" style="max-height: 300px; width:100%;"></canvas>
      </div>
    </div>
  </div>

  <!-- PANEL DERECHO: Agenda y métricas -->
  <div class="panel" id="rightPanel">
    <div class="panel-head">
      <div class="panel-icon teal">📖</div>
      <h3>Agenda Suki</h3>
      <div style="flex:1"></div>
      <button onclick="toggleInspector()" id="inspectBtn" style="border:0; background:transparent; cursor:pointer; font-size:12px;" title="Ver Inspector">🧪</button>
    </div>
    <div class="panel-body" id="journalPane">
      <!-- Journal Content -->
      <div class="journal-entry">
        <span class="journal-label">Resumen de avance</span>
        <div class="journal-text" id="journalSummary">...</div>
      </div>
      <div class="journal-entry">
        <span class="journal-label">Objetivos pendientes</span>
        <div id="taskList">
          <!-- Tasks list here -->
        </div>
      </div>
    </div>

    <!-- Orchestrator status (Inspector Mode - Hidden by default) -->
    <div class="panel-body" id="inspectorPane" style="display:none">
      <div class="orchestrator-badge">
        <div class="ob-dot"></div>
        <div>
          <div class="ob-text">TECHNICAL INSPECTOR</div>
          <div class="ob-sub">AgentOps · Multi-Step Trace</div>
        </div>
      </div>

      <!-- Test mode toggle -->
      <div class="test-toggle">
        <span>🧪 Inspector técnico</span>
        <label class="toggle-switch">
          <input type="checkbox" id="testModeToggle">
          <span class="toggle-slider"></span>
        </label>
      </div>

      <!-- Métricas -->
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-val accent" id="mMsgs">0</div>
          <div class="metric-lbl">Mensajes</div>
        </div>
        <div class="metric-card">
          <div class="metric-val teal" id="mTokens">0</div>
          <div class="metric-lbl">Tokens</div>
        </div>
        <div class="metric-card">
          <div class="metric-val success" id="mCache">0</div>
          <div class="metric-lbl">Cache Hits</div>
        </div>
        <div class="metric-card">
          <div class="metric-val warn" id="mLatency">—</div>
          <div class="metric-lbl">P95 ms</div>
        </div>
      </div>

      <!-- Route path -->
      <div class="section-lbl">Último Route Path</div>
      <div class="route-trace" id="routeTrace">
        <span class="route-step">cache</span>
        <span class="route-step">rules</span>
        <span class="route-step">rag</span>
        <span class="route-step">tools</span>
        <span class="route-step">llm</span>
      </div>

      <!-- Pipeline steps -->
      <div class="section-lbl">Pipeline en tiempo real</div>
      <div class="pipeline-steps">
        <div class="step" id="ps-cache">
          <div class="dot"></div>
          <span class="step-label">1. Semantic Cache</span>
          <span class="step-time" id="ps-cache-t">—</span>
        </div>
        <div class="step" id="ps-budget">
          <div class="dot"></div>
          <span class="step-label">2. Token Budget</span>
          <span class="step-time" id="ps-budget-t">—</span>
        </div>
        <div class="step" id="ps-qdrant">
          <div class="dot"></div>
          <span class="step-label">3. Qdrant Classifier</span>
          <span class="step-time" id="ps-qdrant-t">—</span>
        </div>
        <div class="step" id="ps-process">
          <div class="dot"></div>
          <span class="step-label">4. Agent Process</span>
          <span class="step-time" id="ps-process-t">—</span>
        </div>
        <div class="step" id="ps-kernel">
          <div class="dot"></div>
          <span class="step-label">5. PHP Kernel</span>
          <span class="step-time" id="ps-kernel-t">—</span>
        </div>
      </div>

      <!-- Test info box (visible en test mode) -->
      <div id="testInfoBox" style="display:none; margin-top:12px;">
        <div class="section-lbl">Debug Info</div>
        <pre id="testInfoPre" style="font-size:10px; color:var(--muted); background:var(--surface2);
          border:1px solid var(--border); border-radius:8px; padding:10px; overflow:auto; max-height:200px;
          white-space:pre-wrap; word-break:break-all; line-height:1.5;"></pre>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ════════════════════════════════════════════
// SUKI Builder Chat — v2 Multi-Agent Pipeline
// ════════════════════════════════════════════
(function() {
  const input      = document.getElementById('chatInput');
  const sendBtn    = document.getElementById('sendBtn');
  const messages   = document.getElementById('chatMessages');
  const testToggle = document.getElementById('testModeToggle');
  const testNav    = document.getElementById('testModeNav');
  const testBox    = document.getElementById('testInfoBox');
  const testPre    = document.getElementById('testInfoPre');
  const entityList = document.getElementById('entityList');
  const formList   = document.getElementById('formList');
  
  const kanbanContainer = document.getElementById('kanbanContainer');
  const dashSummary     = document.getElementById('dashSummary');
  let   myChart         = null;

  // ── View Switcher ─────────────────────────
  window.switchView = (view) => {
    document.querySelectorAll('.panel-view').forEach(v => v.style.display = 'none');
    document.getElementById(`view-${view}`).style.display = 'flex';
    
    document.querySelectorAll('.tab-btn').forEach(b => {
      b.classList.remove('active');
      b.style.background = 'transparent';
      b.style.color = 'var(--muted)';
    });
    
    const activeTab = document.getElementById(`tab-${view}`);
    activeTab.classList.add('active');
    activeTab.style.background = 'var(--accent)';
    activeTab.style.color = '#fff';
    
    const titles = { chat: 'Chat con SUKI Builder', kanban: 'Tablero Comercial', dash: 'Métricas de Negocio' };
    document.getElementById('panelTitle').textContent = titles[view];

    if(view === 'kanban') loadKanban();
    if(view === 'dash')   loadDashboard();
  };

  window.loadKanban = async () => {
    try {
      const res = await fetch('api.php?route=kanban/get&type=quotes');
      const json = await res.json();
      if(json.status !== 'success') throw new Error(json.message);
      
      kanbanContainer.innerHTML = '';
      const cols = json.data.columns;
      for(const k in cols) {
        const col = cols[k];
        const colDiv = document.createElement('div');
        colDiv.className = 'kanban-col';
        colDiv.innerHTML = `
          <div class="kanban-col-head">
            <span class="kanban-col-title">${col.title}</span>
            <span class="kanban-col-count">${col.items.length}</span>
          </div>
          <div class="kanban-items" ondragover="event.preventDefault()" ondrop="dropCard(event, '${k}')">
            ${col.items.map(i => `
              <div class="kanban-card" draggable="true" ondragstart="dragCard(event, '${i.id}', 'quote')">
                <div class="kanban-card-title">${i.title}</div>
                <div class="kanban-card-sub">${i.subtitle}</div>
                <div class="kanban-card-foot">
                  <span class="kanban-card-price">$${new Intl.NumberFormat().format(i.amount)}</span>
                </div>
              </div>
            `).join('')}
          </div>
        `;
        kanbanContainer.appendChild(colDiv);
      }
    } catch(e) { kanbanContainer.innerHTML = `<div class="empty-hint">Error: ${e.message}</div>`; }
  };

  window.dragCard = (ev, id, type) => {
    ev.dataTransfer.setData("cardId", id);
    ev.dataTransfer.setData("cardType", type);
  };

  window.dropCard = async (ev, newStatus) => {
    ev.preventDefault();
    const id = ev.dataTransfer.getData("cardId");
    const type = ev.dataTransfer.getData("cardType");
    try {
      await fetch('api.php?route=kanban/move', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, type, status: newStatus })
      });
      loadKanban();
    } catch(e) { console.error(e); }
  };

  window.loadDashboard = async () => {
    try {
      const res = await fetch('api.php?route=dashboard/metrics');
      const json = await res.json();
      if(json.status !== 'success') throw new Error(json.message);
      
      const sum = json.data.summary;
      dashSummary.innerHTML = `
        <div class="stat-card">
          <span class="stat-label">Ventas</span>
          <span class="stat-val" style="color:var(--teal)">$${new Intl.NumberFormat().format(sum.total_sales)}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Gastos</span>
          <span class="stat-val" style="color:var(--danger)">$${new Intl.NumberFormat().format(sum.total_purchases)}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">Activas</span>
          <span class="stat-val">${sum.active_quotes}</span>
        </div>
      `;
      renderChart(json.data.charts);
    } catch(e) { dashSummary.innerHTML = `<div class="empty-hint">Error: ${e.message}</div>`; }
  };

  function renderChart(data) {
    if(myChart) myChart.destroy();
    const ctx = document.getElementById('mainChart').getContext('2d');
    myChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: data.labels,
        datasets: [
          { label: 'Ingresos', data: data.sales, borderColor: '#14b8a6', tension: 0.4, fill: true, backgroundColor: 'rgba(20,184,166,0.1)' },
          { label: 'Egresos', data: data.expenses, borderColor: '#ef4444', tension: 0.4 }
        ]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' } }, x: { grid: { display: false } } }
      }
    });
  }

  // Counters
  let msgCount   = 0;
  let tokenCount = 0;
  let cacheHits  = 0;
  let latencies  = [];

  // Config
  const API_URL   = 'api/chat/message';
  const MODE      = 'builder';
  const TENANT_ID = _cfg('tenant_id', 'demo');
  const USER_ID   = _cfg('user_id',   'admin');
  const PROJECT_ID = _cfg('project_id', 'default');

  function _cfg(k, def) {
    const m = document.cookie.match(new RegExp('(?:^|;)\\s*' + k + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : def;
  }

  // ── Test mode ─────────────────────────────
  function setTestMode(on) {
    testBox.style.display = on ? 'block' : 'none';
    testNav.textContent   = on ? '✅ Test ON' : '🧪 Test Mode';
  }
  testToggle.addEventListener('change', () => setTestMode(testToggle.checked));
  testNav.addEventListener('click', (e) => {
    e.preventDefault();
    testToggle.checked = !testToggle.checked;
    setTestMode(testToggle.checked);
  });

  // ── Pipeline animation ─────────────────────
  function resetPipeline() {
    ['ps-cache','ps-budget','ps-qdrant','ps-process','ps-kernel'].forEach(id => {
      const el = document.getElementById(id);
      el.className = 'step';
      document.getElementById(id+'-t').textContent = '—';
    });
  }
  function activateStep(id) {
    const el = document.getElementById(id);
    if (el) el.className = 'step active';
  }
  function doneStep(id, ms) {
    const el = document.getElementById(id);
    if (el) {
      el.className = 'step done';
      document.getElementById(id+'-t').textContent = ms ? ms+'ms' : '✓';
    }
  }
  function skipStep(id) {
    const el = document.getElementById(id);
    if (el) el.className = 'step skip';
  }

  // ── Route trace ───────────────────────────
  function renderRoutePath(path) {
    const trace = document.getElementById('routeTrace');
    const steps = ['cache','rules','rag','tools','llm'];
    const reached = path ? path.split('>').map(s => s.trim()) : [];
    trace.innerHTML = steps.map(s =>
      `<span class="route-step ${reached.includes(s) ? 'active' : ''}">${s}</span>`
    ).join('');
  }

  // ── Metrics ───────────────────────────────
  function updateMetrics(latency, tokens, fromCache) {
    msgCount++;
    if (tokens)     tokenCount += parseInt(tokens) || 0;
    if (fromCache)  cacheHits++;
    if (latency)    latencies.push(latency);
    document.getElementById('mMsgs').textContent    = msgCount;
    document.getElementById('mTokens').textContent  = tokenCount > 999 ? (tokenCount/1000).toFixed(1)+'k' : tokenCount;
    document.getElementById('mCache').textContent   = cacheHits;
    // p95
    const sorted = [...latencies].sort((a,b)=>a-b);
    const p95    = sorted[Math.floor(sorted.length * 0.95)] ?? (sorted[sorted.length-1] ?? 0);
    document.getElementById('mLatency').textContent = p95 ? p95+'ms' : '—';
  }

  // ── Entity panel ──────────────────────────
  function refreshEntities(entities, forms) {
    if (!entities || !entities.length) return;
    entityList.innerHTML = entities.map(e =>
      `<div class="entity-item">
        <span class="entity-dot"></span>
        <span class="entity-name">${e.name || e}</span>
        <span class="entity-badge">${e.fields ? e.fields+'f' : 'tabla'}</span>
      </div>`
    ).join('');
    if (forms && forms.length) {
      document.getElementById('formSectionLbl').style.display = '';
      formList.innerHTML = forms.map(f =>
        `<div class="entity-item">
          <span class="entity-dot" style="background:var(--accent)"></span>
          <span class="entity-name">${f.name || f}</span>
          <span class="entity-badge" style="background:var(--accent-soft);color:var(--accent)">form</span>
        </div>`
      ).join('');
    }
  }

  // ── Message rendering ──────────────────────
  function ts() {
    return new Date().toLocaleTimeString('es', { hour:'2-digit', minute:'2-digit' });
  }
  function addMsg(role, text, meta) {
    const cls  = role === 'user' ? 'user' : 'bot';
    const av   = role === 'user' ? 'U' : 'S';
    const wrap = document.createElement('div');
    wrap.className = `msg ${cls}`;
    wrap.innerHTML = `
      <div class="msg-avatar">${av}</div>
      <div>
        <div class="msg-bubble">${escHtml(text).replace(/\n/g,'<br>')}</div>
        ${meta ? `<div class="msg-meta">${meta}</div>` : ''}
      </div>`;
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
    return wrap;
  }
  function addTyping() {
    const wrap = document.createElement('div');
    wrap.className = 'msg bot';
    wrap.id = 'typing';
    wrap.innerHTML = `
      <div class="msg-avatar">S</div>
      <div class="typing-bubble">
        <span></span><span></span><span></span>
      </div>`;
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
    return wrap;
  }
  function removeTyping() {
    const t = document.getElementById('typing');
    if (t) t.remove();
  }
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // ── SESSION & JOURNAL MANAGEMENT ──────────────────
  let ACTIVE_SESSION_ID = localStorage.getItem('suki_builder_session') || '';

  window.toggleInspector = () => {
    const journal = document.getElementById('journalPane');
    const inspector = document.getElementById('inspectorPane');
    const btn = document.getElementById('inspectBtn');
    
    if (journal.style.display === 'none') {
        journal.style.display = 'block';
        inspector.style.display = 'none';
        btn.textContent = '🧪';
    } else {
        journal.style.display = 'none';
        inspector.style.display = 'block';
        btn.textContent = '📖';
    }
  };

  window.loadSessions = async () => {
    try {
      const res = await fetch('api/chat/sessions/list');
      const json = await res.json();
      if (json.status === 'success') {
        const list = document.getElementById('sessionList');
        if (!list) return;
        list.innerHTML = '';
        json.data.sessions.forEach(s => {
          const item = document.createElement('div');
          item.className = 'session-item' + (s.session_id === ACTIVE_SESSION_ID ? ' active' : '');
          item.onclick = () => switchSession(s.session_id);
          item.innerHTML = `<div class="dot"></div><div class="session-title text-truncate" style="max-width:140px">${s.title || 'Nueva sesión'}</div>`;
          list.appendChild(item);
        });
        if (ACTIVE_SESSION_ID) {
            switchSession(ACTIVE_SESSION_ID, true);
        } else if (json.data.sessions.length > 0) {
            switchSession(json.data.sessions[0].session_id, true);
        } else {
            document.getElementById('chatMessages').innerHTML = `
                <div class="msg bot">
                    <div class="msg-avatar">S</div>
                    <div>
                        <div class="msg-bubble">👋 Hola, soy <strong>SUKI</strong>, tu asistente de construcción.<br><br>¿Qué tipo de negocio quieres crear? Dímelo en una frase y lo armamos juntos. 🚀</div>
                        <div class="msg-meta">0ms · ready</div>
                    </div>
                </div>`;
        }
      }
    } catch (e) { console.error('Error loading sessions', e); }
  };

  window.switchSession = async (sid, force = false) => {
    if (sid === ACTIVE_SESSION_ID && !force) return;
    ACTIVE_SESSION_ID = sid;
    localStorage.setItem('suki_builder_session', sid);
    loadSessions();
    const chatMsgs = document.getElementById('chatMessages');
    chatMsgs.innerHTML = '<div class="empty-hint">Cargando historial...</div>';
    
    try {
        const res = await fetch(`api/chat/history?session_id=${sid}`);
        const json = await res.json();
        chatMsgs.innerHTML = '';
        if (json.data.history) {
            json.data.history.forEach(m => {
                addMsg(m.dir === 'in' ? 'user' : 'bot', m.msg, m.ts ? new Date(m.ts * 1000).toLocaleTimeString() : '');
            });
        }
    } catch (e) { console.error('Error switching session', e); }
  };

  window.createNewSession = async () => {
    try {
        const res = await fetch('api/chat/sessions/create');
        const json = await res.json();
        if (json.status === 'success') {
            ACTIVE_SESSION_ID = json.data.session_id;
            const chatMsgs = document.getElementById('chatMessages');
            chatMsgs.innerHTML = '';
            addMsg('bot', 'Nueva sesión iniciada. ¿En qué puedo ayudarte?');
            loadSessions();
        }
    } catch (e) { console.error('Error creating session', e); }
  };

  window.loadJournal = async () => {
    try {
        const res = await fetch(`api/chat/journal/get?role=${MODE === 'builder' ? 'architect' : 'admin'}`);
        const json = await res.json();
        if (json.status === 'success') {
            const j = json.data.journal;
            const sum = document.getElementById('journalSummary');
            if (sum) sum.textContent = j.summary || 'Sin resumen.';
            const taskList = document.getElementById('taskList');
            if (taskList) {
                taskList.innerHTML = '';
                const tasks = j.tasks || {};
                Object.keys(tasks).forEach(t => {
                    const item = document.createElement('div');
                    item.className = 'task-item';
                    const done = tasks[t].status === 'done';
                    item.innerHTML = `<div class="task-check ${done ? 'done' : ''}"></div><div class="task-label">${t}</div>`;
                    taskList.appendChild(item);
                });
            }
        }
    } catch (e) { console.error('Error loading journal', e); }
  };

  // Initial load
  setTimeout(() => {
    loadSessions();
    loadJournal();
  }, 1000);

  // ── Send message ───────────────────────────
  async function sendMessage() {
    const text = input.value.trim();
    if (!text) return;
    input.value = ''; input.style.height = 'auto';
    sendBtn.disabled = true;

    addMsg('user', text);
    const typing = addTyping();
    resetPipeline();
    
    // Concurrency Lock: Disable inputs while processing
    input.disabled = true;
    sendBtn.disabled = true;
    input.classList.add('opacity-50');

    const t0 = Date.now();
    // Animate pipeline visually (steps simulados hasta tener telemetría real)
    activateStep('ps-cache');
    await wait(80);
    activateStep('ps-budget');
    await wait(60);
    activateStep('ps-qdrant');

    try {
      const body = {
        message:   text,
        mode:      MODE,
        tenant_id: TENANT_ID,
        user_id:   USER_ID,
        project_id: PROJECT_ID,
        session_id: ACTIVE_SESSION_ID,
        test_mode: testToggle.checked,
      };
      const res  = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body:   JSON.stringify(body),
      });
      const json = await res.json();
      const latency = Date.now() - t0;

      removeTyping();

      const data     = json.data    ?? {};
      const reply    = data.reply   ?? json.message ?? 'Sin respuesta.';
      const testInfo = data.test_info ?? {};
      const routePath   = testInfo.route_path ?? '';
      const fromCache   = routePath && routePath.includes('cache');
      const llmProvider = testInfo.llm_provider ?? '';
      const llmModel    = testInfo.llm_model    ?? '';
      const classification = testInfo.classification ?? testInfo.action ?? '';
      const agentsUsed  = Array.isArray(testInfo.agents_used) ? testInfo.agents_used.join(', ') : '';
      const tokens      = testInfo.evidence_count ?? 0;

      // Pipeline done states
      if (fromCache) {
        doneStep('ps-cache', latency);
        skipStep('ps-budget'); skipStep('ps-qdrant'); skipStep('ps-process'); skipStep('ps-kernel');
      } else {
        doneStep('ps-cache');
        doneStep('ps-budget');
        doneStep('ps-qdrant', latency > 400 ? Math.round(latency*0.35) : null);
        activateStep('ps-process');
        await wait(50);
        doneStep('ps-process');
        doneStep('ps-kernel', latency);
      }

      // Build meta line
      const metaParts = [
        latency+'ms',
        routePath && 'route: '+routePath,
        classification && classification,
        llmProvider && llmProvider + (llmModel ? '/'+llmModel.split('-').slice(-1)[0] : ''),
        agentsUsed && 'agents: '+agentsUsed,
      ].filter(Boolean);

      addMsg('bot', reply, metaParts.join(' · '));
      renderRoutePath(routePath);
      updateMetrics(latency, tokens, fromCache);

      // Test info dump
      if (testToggle.checked && Object.keys(testInfo).length) {
        testPre.textContent = JSON.stringify(testInfo, null, 2);
      }

      // Refresh entity panel
      if (data.entities || data.forms) {
        refreshEntities(data.entities ?? [], data.forms ?? []);
      }

    } catch (err) {
      removeTyping();
      resetPipeline();
      addMsg('bot', '⚠️ Error de conexión: ' + (err.message || 'Fallo de red'));
      console.error(err);
    } finally {
      // Release Concurrency Lock
      input.disabled = false;
      sendBtn.disabled = false;
      input.classList.remove('opacity-50');
      input.focus();
    }
  }

  // ── SMTP Modal ──────────────────────────────
  const smtpModal = document.getElementById('smtpModal');
  const smtpFields = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass'];

  window.openSmtpSettings = async () => {
    smtpModal.classList.add('open');
    try {
      const res = await fetch('api/config/get');
      const json = await res.json();
      if (json.status === 'success') {
        const d = json.data;
        smtpFields.forEach(f => document.getElementById(f).value = d[f] || '');
      }
    } catch (e) { console.error('Error loading config', e); }
  };

  window.closeSmtpSettings = () => {
    smtpModal.classList.remove('open');
  };

  window.saveSmtpSettings = async () => {
    const payload = {};
    smtpFields.forEach(f => payload[f] = document.getElementById(f).value);
    
    const btn = document.querySelector('#smtpModal .primary');
    const oldText = btn.textContent;
    btn.textContent = 'Guardando...';
    btn.disabled = true;

    try {
      const res = await fetch('api/config/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const json = await res.json();
      if (json.status === 'success') {
        alert('✅ Configuración de correo guardada correctamente.');
        closeSmtpSettings();
      } else {
        alert('❌ Error: ' + (json.message || 'Fallo desconocido'));
      }
    } catch (e) {
      alert('❌ Error de conexión al guardar.');
    } finally {
      btn.textContent = oldText;
      btn.disabled = false;
    }
  };

  function wait(ms) { return new Promise(r => setTimeout(r, ms)); }

  // ── Input auto-resize ─────────────────────
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });
  sendBtn.addEventListener('click', sendMessage);
  input.focus();

})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
