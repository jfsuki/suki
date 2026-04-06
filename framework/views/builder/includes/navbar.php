<!-- framework/views/builder/includes/navbar.php -->
<nav>
  <div class="nav-brand">
    <div class="nav-logo">⚡</div>
    SUKI
    <span class="nav-badge">Builder Hub</span>
  </div>
  <div class="nav-actions">
    <!-- Links strictly for the Builder world -->
    <a href="/suki/builder" class="nav-btn <?= (isset($current_page) && $current_page==='chat') ? 'active' : '' ?>">
       💬 Orchestrator
    </a>
    <a href="/suki/editor" class="nav-btn <?= (isset($current_page) && $current_page==='editor') ? 'active' : '' ?>">
       🛠️ Studio / Editor
    </a>
    <a href="#" class="nav-btn primary" id="testModeNav">🧪 Test Mode</a>
    
    <div style="margin-left: 14px; border-left: 1px solid var(--border); padding-left: 14px; display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 10px; color: var(--muted); font-weight: 600; text-transform: uppercase;">Architect Mode</span>
        <a href="logout" class="nav-btn" style="border-color: var(--danger); color: var(--danger);">Salir</a>
    </div>
  </div>
</nav>

<style>
    /* Shared Builder Navbar Styles (extracted from chat_builder.php and enhanced) */
    nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 22px;
      height: 58px;
      flex-shrink: 0;
      border-bottom: 1px solid var(--border);
      background: rgba(10,15,30,0.85);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      z-index: 100;
    }
    .nav-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 700;
      font-size: 15px;
      letter-spacing: -0.3px;
      color: var(--text);
    }
    .nav-logo {
      width: 30px; height: 30px;
      background: linear-gradient(135deg, var(--accent) 0%, var(--teal) 100%);
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px;
      box-shadow: 0 0 14px var(--glow);
    }
    .nav-badge {
      background: var(--accent-soft);
      color: var(--accent);
      border: 1px solid rgba(139,92,246,0.25);
      font-size: 10px;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 999px;
      letter-spacing: 0.5px;
      text-transform: uppercase;
    }
    .nav-actions { display: flex; align-items: center; gap: 8px; }
    .nav-btn {
      background: var(--surface2);
      border: 1px solid var(--border);
      color: var(--text);
      font-family: var(--font);
      font-size: 12px;
      font-weight: 500;
      padding: 6px 14px;
      border-radius: 999px;
      cursor: pointer;
      text-decoration: none;
      transition: var(--transition);
      display: flex; align-items: center; gap: 5px;
    }
    .nav-btn:hover { background: var(--surface); border-color: var(--accent); color: var(--accent); }
    .nav-btn.active { border-color: var(--accent); color: var(--accent); background: var(--accent-soft); }
    .nav-btn.primary {
      background: linear-gradient(135deg, var(--accent) 0%, #7c3aed 100%);
      border-color: transparent; color: #fff;
      box-shadow: 0 0 18px var(--glow);
    }
    .nav-btn.primary:hover { opacity: 0.88; }
</style>
