<?php
// ═══════════════════════════════════════════════════════════════════════════
// index.php — WeKickWiki landing page & admin hub
//
// Serves the login screen, module navigation cards and the admin panel.
// All API endpoints from lib/users-api.php, lib/wiki-api.php and
// lib/backup-api.php are reachable via index.php?action=…
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users-api.php';
require_once __DIR__ . '/lib/wiki-api.php';
require_once __DIR__ . '/lib/backup-api.php';

$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';
$settings  = load_settings();
$theme     = $settings['theme'] ?? 'default.css';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($settings['wikiName']) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="icon.svg">
  <link id="app-theme-link" rel="stylesheet" href="templates/<?= htmlspecialchars($theme, ENT_QUOTES) ?>">
  <style>
    /* ── Index-specific layout ── */
    #login-screen { display:flex; align-items:center; justify-content:center; min-height:100vh; }
    #app-screen   { display:none; }

    /* Module cards */
    #module-cards {
      display:flex; flex-wrap:wrap; gap:1.5rem;
      padding:2rem; max-width:960px; margin:0 auto;
    }
    .module-card {
      flex:1 1 220px; border:1px solid #e0e0e0; border-radius:10px;
      padding:2rem 1.5rem; text-align:center; text-decoration:none;
      color:inherit; transition:box-shadow .15s, transform .15s;
      background:#fff;
    }
    .module-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.12); transform:translateY(-2px); }
    .module-card svg  { width:2.5rem; height:2.5rem; stroke:var(--accent,#4a6fa5); fill:none; stroke-width:1.5; }
    .module-card h2   { margin:.75rem 0 .4rem; font-size:1.2rem; }
    .module-card p    { margin:0; font-size:.88rem; color:#666; }

    /* Admin panel */
    #admin-panel { max-width:960px; margin:0 auto 2rem; padding:0 2rem; }
    #admin-panel > h2 { font-size:1.1rem; color:#555; margin-bottom:1rem; }
    .admin-tabs { display:flex; gap:.25rem; border-bottom:2px solid #e0e0e0; margin-bottom:1.25rem; flex-wrap:wrap; }
    .admin-tab {
      padding:.5rem 1.1rem; background:none; border:none; border-bottom:2px solid transparent;
      cursor:pointer; font-size:.9rem; font-weight:600; color:#666; margin-bottom:-2px;
      border-radius:4px 4px 0 0; transition:color .15s;
    }
    .admin-tab:hover  { color:#333; }
    .admin-tab.active { color:var(--accent,#4a6fa5); border-bottom-color:var(--accent,#4a6fa5); }
    .admin-tab-panel  { display:none; }
    .admin-tab-panel.active { display:block; }

    /* Users */
    .user-card {
      display:flex; align-items:center; gap:.75rem; padding:.65rem .9rem;
      border:1px solid #e8e8e8; border-radius:6px; margin-bottom:.5rem; background:#fafafa;
    }
    .user-card .user-info { flex:1; }
    .user-card .user-name { font-weight:600; font-size:.9rem; }
    .user-card .user-meta { font-size:.78rem; color:#888; }
    .user-card .user-actions { display:flex; gap:.4rem; flex-shrink:0; }
    .badge { display:inline-block; font-size:.7rem; padding:.1em .45em; border-radius:3px; font-weight:700; text-transform:uppercase; }
    .badge-admin  { background:#4a6fa5; color:#fff; }
    .badge-guest  { background:#e0e0e0; color:#555; }
    .badge-off    { background:#ffcdd2; color:#b71c1c; }

    /* Backup */
    .backup-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:.75rem; }
    .backup-item { border:1px solid #e0e0e0; border-radius:6px; padding:.85rem 1rem; background:#fafafa; }
    .backup-item h4 { margin:0 0 .5rem; font-size:.88rem; color:#333; }
    .backup-item .backup-actions { display:flex; gap:.4rem; flex-wrap:wrap; }

    /* Plugins */
    .plugin-card {
      display:flex; align-items:center; gap:.75rem; padding:.65rem .9rem;
      border:1px solid #e8e8e8; border-radius:6px; margin-bottom:.5rem; background:#fafafa;
    }
    .plugin-card .plugin-info { flex:1; font-size:.88rem; }
    .toggle-switch { position:relative; display:inline-block; width:36px; height:20px; }
    .toggle-switch input { opacity:0; width:0; height:0; }
    .toggle-slider {
      position:absolute; inset:0; background:#ccc; border-radius:20px; cursor:pointer;
      transition:background .2s;
    }
    .toggle-slider:before {
      content:''; position:absolute; height:14px; width:14px; left:3px; bottom:3px;
      background:#fff; border-radius:50%; transition:transform .2s;
    }
    input:checked + .toggle-slider { background:var(--accent,#4a6fa5); }
    input:checked + .toggle-slider:before { transform:translateX(16px); }
    input:disabled + .toggle-slider { opacity:.5; cursor:not-allowed; }

    /* Forms */
    .form-row { margin-bottom:.85rem; }
    .form-row label { display:block; font-weight:600; font-size:.82rem; margin-bottom:.3rem; color:#333; }
    .form-row input, .form-row select {
      width:100%; padding:.45rem .65rem; border:1px solid #ccc; border-radius:5px;
      font-size:.9rem; box-sizing:border-box;
    }
    .form-check { display:flex; align-items:center; gap:.5rem; margin-bottom:.65rem; }
    .form-check input[type=checkbox] { width:auto; cursor:pointer; }
    .form-check label { font-weight:600; font-size:.82rem; color:#333; cursor:pointer; margin:0; }

    /* Modals */
    .ix-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:900; align-items:center; justify-content:center; }
    .ix-overlay.open { display:flex; }
    .ix-modal { background:#fff; border-radius:10px; padding:1.5rem; width:min(420px,90vw); box-shadow:0 8px 32px rgba(0,0,0,.18); position:relative; }
    .ix-modal h3 { margin:0 0 1rem; font-size:1rem; }
    .ix-modal-actions { display:flex; justify-content:flex-end; gap:.5rem; margin-top:1.2rem; align-items:center; }
    .ix-modal-close { position:absolute; top:.75rem; right:.9rem; background:none; border:none; font-size:1.3rem; cursor:pointer; color:#999; }
    .ix-modal-close:hover { color:#333; }
    .hint { font-weight:400; font-size:.78rem; color:#888; }
    .ix-status { font-size:.82rem; display:inline-block; min-height:1.1em; }
    .ix-status.ok  { color:#2e7d32; }
    .ix-status.err { color:#c62828; }

    fieldset.ix-fs { border:1px solid #e0e0e0; border-radius:6px; padding:.75rem 1rem .85rem; margin-top:1rem; }
    fieldset.ix-fs legend { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#555; padding:0 .35rem; }

    input.restore-input { display:none; }

    @media(max-width:480px) {
      #module-cards { padding:1rem; gap:1rem; }
      .admin-tabs { gap:0; }
      .admin-tab  { padding:.45rem .7rem; font-size:.83rem; }
    }
  </style>
</head>
<body>

  <!-- ── Login screen ─────────────────────────────────────── -->
  <div id="login-screen">
    <div id="login-box">
      <h2>
        <img src="icon.svg" style="width:2rem;height:2rem;vertical-align:middle;margin-right:.5rem" alt="">
        <?= htmlspecialchars($settings['wikiName']) ?> — Sign in
      </h2>
      <form id="login-form" novalidate>
        <label>Username
          <input id="login-user" type="text" autocomplete="username" required autofocus>
        </label>
        <label>Password
          <input id="login-pass" type="password" autocomplete="current-password" required>
        </label>
        <button type="submit">
          <svg viewBox="0 0 24 24" style="width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:2;margin-right:.4rem" aria-hidden="true">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
            <polyline points="10 17 15 12 10 7"/>
            <line x1="15" y1="12" x2="3" y2="12"/>
          </svg>
          Sign in
        </button>
        <p id="login-error"></p>
      </form>
    </div>
  </div>

  <!-- ── App screen ───────────────────────────────────────── -->
  <div id="app-screen">

    <header id="app-header">
      <a href="index.php">
        <img src="icon.svg" style="display:inline;width:1.4rem;height:1.4rem;vertical-align:middle;margin-right:.4rem" alt="">
        <?= htmlspecialchars($settings['wikiName']) ?>
      </a>
      <div id="app-header-right">
        <span id="user-badge"></span>
        <button class="btn btn-sm" onclick="doLogout()">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign out
        </button>
      </div>
    </header>

    <!-- Module cards -->
    <div id="module-cards">
      <a href="wiki.php" class="module-card">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
        <h2>Wiki</h2>
        <p>Browse, write and manage wiki pages.</p>
      </a>
      <a href="marks.php" class="module-card" id="marks-card">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <h2>Marks</h2>
        <p>Manage qualification structures and student marks.</p>
      </a>
      <a href="quests.php" class="module-card" id="quests-card">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <h2>Quests</h2>
        <p>Create questions, build quests and track results.</p>
      </a>
    </div>

    <!-- Admin panel (admins only) -->
    <div id="admin-panel" style="display:none">
      <h2>Administration</h2>
      <div class="admin-tabs">
        <button class="admin-tab active" onclick="showAdminTab('theme')">Theme</button>
        <button class="admin-tab" onclick="showAdminTab('users')">Users</button>
        <button class="admin-tab" onclick="showAdminTab('backup')">Backup</button>
        <button class="admin-tab" onclick="showAdminTab('settings')">Settings</button>
        <button class="admin-tab" onclick="showAdminTab('plugins')">Plugins</button>
      </div>

      <!-- Theme -->
      <div id="tab-theme" class="admin-tab-panel active">
        <p style="margin-bottom:.75rem;font-size:.88rem;color:#555">Select the theme applied to all three modules.</p>
        <div class="form-row" style="max-width:300px">
          <label>Active theme</label>
          <select id="theme-select"></select>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem">
          <button class="btn btn-primary" onclick="saveTheme()">Save theme</button>
          <span id="theme-status" class="ix-status"></span>
        </div>
      </div>

      <!-- Users -->
      <div id="tab-users" class="admin-tab-panel">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem">
          <button class="btn btn-primary" onclick="openAddGuestModal()">
            <svg viewBox="0 0 24 24" style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2;vertical-align:middle;margin-right:.3em"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add guest
          </button>
          <span id="users-status" class="ix-status"></span>
        </div>
        <div id="users-list"></div>
      </div>

      <!-- Backup -->
      <div id="tab-backup" class="admin-tab-panel">
        <p style="margin-bottom:.75rem;font-size:.88rem;color:#555">Download or restore individual data files.</p>
        <div class="backup-grid" id="backup-grid"></div>
      </div>

      <!-- Settings -->
      <div id="tab-settings" class="admin-tab-panel">
        <form id="settings-form" novalidate style="max-width:520px">
          <div class="form-row">
            <label>Wiki / app name</label>
            <input type="text" id="settings-wiki-name" autocomplete="off" maxlength="64">
          </div>
          <div class="form-row">
            <label>Code highlight theme</label>
            <select id="settings-hljs-theme"></select>
          </div>
          <div class="form-check">
            <input type="checkbox" id="settings-code-line-numbers">
            <label for="settings-code-line-numbers">Show line numbers in code blocks</label>
          </div>
          <div class="form-check">
            <input type="checkbox" id="settings-guest-odt-download">
            <label for="settings-guest-odt-download">Allow guests to download ODT</label>
          </div>
          <div class="form-check">
            <input type="checkbox" id="settings-guest-toc">
            <label for="settings-guest-toc">Allow guests to view Table of contents</label>
          </div>
          <div class="form-check">
            <input type="checkbox" id="settings-guest-index">
            <label for="settings-guest-index">Allow guests to view page Index</label>
          </div>
          <fieldset class="ix-fs">
            <legend>Security</legend>
            <div class="form-check" style="margin-top:.3rem">
              <input type="checkbox" id="settings-guest-login-enabled">
              <label for="settings-guest-login-enabled">Allow guest logins</label>
            </div>
            <div class="form-row" style="margin-top:.75rem">
              <label>JWT secret <span class="hint">(leave blank to keep current)</span></label>
              <input type="text" id="settings-jwt-secret" autocomplete="off" minlength="16" maxlength="128" placeholder="leave blank to keep" style="font-family:monospace;font-size:.85rem">
            </div>
            <div id="settings-admin-pass-row" style="display:none">
              <div class="form-row">
                <label>Admin password <span class="hint">(required when changing JWT secret)</span></label>
                <input type="password" id="settings-admin-pass" autocomplete="current-password" placeholder="••••••••">
              </div>
            </div>
            <div class="form-row">
              <label>Token TTL <span class="hint">(seconds, 60–86400)</span></label>
              <input type="number" id="settings-token-ttl" min="60" max="86400" step="60">
            </div>
          </fieldset>
          <fieldset class="ix-fs" style="margin-top:.75rem">
            <legend>Modules</legend>
            <div class="form-check" style="margin-top:.3rem">
              <input type="checkbox" id="settings-wiki-enabled" disabled checked>
              <label for="settings-wiki-enabled">Wiki <span class="hint">(always enabled)</span></label>
            </div>
            <div class="form-check">
              <input type="checkbox" id="settings-marks-enabled">
              <label for="settings-marks-enabled">Enable Marks module</label>
            </div>
            <div class="form-check">
              <input type="checkbox" id="settings-quests-enabled">
              <label for="settings-quests-enabled">Enable Quests module</label>
            </div>
          </fieldset>
          <div style="display:flex;align-items:center;gap:.75rem;margin-top:1rem">
            <button type="submit" class="btn btn-primary">Save settings</button>
            <span id="settings-status" class="ix-status"></span>
          </div>
        </form>
      </div>

      <!-- Plugins -->
      <div id="tab-plugins" class="admin-tab-panel">
        <p style="margin-bottom:.75rem;font-size:.88rem;color:#555">Enable or disable front-end plugins loaded by the wiki module.</p>
        <div id="plugins-list"><p style="color:#888;font-size:.88rem">Loading…</p></div>
      </div>

    </div><!-- #admin-panel -->

  </div><!-- #app-screen -->

  <!-- Add guest modal -->
  <div class="ix-overlay" id="add-guest-overlay">
    <div class="ix-modal">
      <button class="ix-modal-close" onclick="closeAddGuestModal()">&times;</button>
      <h3>Add guest user</h3>
      <div class="form-row">
        <label>Username <span class="hint">(lowercase, digits, underscore)</span></label>
        <input type="text" id="ag-username" autocomplete="off" maxlength="32" pattern="[a-z0-9_]+" placeholder="username">
      </div>
      <div class="form-row">
        <label>Display name</label>
        <input type="text" id="ag-name" autocomplete="off" maxlength="64" placeholder="Full name">
      </div>
      <div class="form-row">
        <label>Password</label>
        <input type="password" id="ag-pass" autocomplete="new-password" placeholder="••••••••">
      </div>
      <div class="form-row">
        <label>Confirm password</label>
        <input type="password" id="ag-pass2" autocomplete="new-password" placeholder="••••••••">
      </div>
      <div class="ix-modal-actions">
        <span id="ag-status" class="ix-status" style="flex:1"></span>
        <button class="btn" onclick="closeAddGuestModal()">Cancel</button>
        <button class="btn btn-primary" onclick="submitAddGuest()">Add</button>
      </div>
    </div>
  </div>

  <!-- Edit guest modal -->
  <div class="ix-overlay" id="edit-guest-overlay">
    <div class="ix-modal">
      <button class="ix-modal-close" onclick="closeEditGuestModal()">&times;</button>
      <h3>Edit guest</h3>
      <input type="hidden" id="eg-username-orig">
      <div class="form-row">
        <label>Display name</label>
        <input type="text" id="eg-name" autocomplete="off" maxlength="64">
      </div>
      <div class="ix-modal-actions">
        <span id="eg-status" class="ix-status" style="flex:1"></span>
        <button class="btn" onclick="closeEditGuestModal()">Cancel</button>
        <button class="btn btn-primary" onclick="submitEditGuest()">Save</button>
      </div>
    </div>
  </div>

  <!-- Reset password modal -->
  <div class="ix-overlay" id="reset-pass-overlay">
    <div class="ix-modal">
      <button class="ix-modal-close" onclick="closeResetPassModal()">&times;</button>
      <h3 id="reset-pass-title">Reset password</h3>
      <input type="hidden" id="rp-username">
      <div class="form-row">
        <label>New password</label>
        <input type="password" id="rp-new" autocomplete="new-password" placeholder="••••••••">
      </div>
      <div class="form-row">
        <label>Confirm password</label>
        <input type="password" id="rp-confirm" autocomplete="new-password" placeholder="••••••••">
      </div>
      <div class="ix-modal-actions">
        <span id="rp-status" class="ix-status" style="flex:1"></span>
        <button class="btn" onclick="closeResetPassModal()">Cancel</button>
        <button class="btn btn-primary" onclick="submitResetPass()">Reset</button>
      </div>
    </div>
  </div>

  <!-- Confirm delete modal -->
  <div class="ix-overlay" id="confirm-del-overlay">
    <div class="ix-modal" style="max-width:360px">
      <h3 id="confirm-del-title">Confirm delete</h3>
      <p id="confirm-del-msg" style="font-size:.88rem;color:#555;margin-bottom:1rem"></p>
      <div class="ix-modal-actions">
        <button class="btn" onclick="closeConfirmDel()">Cancel</button>
        <button class="btn btn-danger" id="confirm-del-ok">Delete</button>
      </div>
    </div>
  </div>

  <div id="app-toast"></div>

  <!-- Restore file inputs -->
  <input type="file" class="restore-input" id="ri-users"    accept=".json" onchange="doRestore('restore-users',   this)">
  <input type="file" class="restore-input" id="ri-settings" accept=".json" onchange="doRestore('restore-settings',this)">
  <input type="file" class="restore-input" id="ri-items"    accept=".json" onchange="doRestore('restore-items',   this)">
  <input type="file" class="restore-input" id="ri-marks"    accept=".json" onchange="doRestore('restore-marks',   this)">
  <input type="file" class="restore-input" id="ri-queries"  accept=".json" onchange="doRestore('restore-queries', this)">
  <input type="file" class="restore-input" id="ri-quests"   accept=".json" onchange="doRestore('restore-quests',  this)">
  <input type="file" class="restore-input" id="ri-attempts" accept=".json" onchange="doRestore('restore-attempts',this)">
  <input type="file" class="restore-input" id="ri-pages"    accept=".txt"  onchange="doRestore('restore',         this, 'wiki.php')">

  <script src="lib/auth-client.js?v=<?= filemtime(__DIR__ . '/lib/auth-client.js') ?>"></script>
  <script src="lib/app-client.js?v=<?= filemtime(__DIR__ . '/lib/app-client.js') ?>"></script>
  <script>
  window.WKW_MARKS_ENABLED  = <?= json_encode((bool)($settings['marksEnabled']  ?? true)) ?>;
  window.WKW_QUESTS_ENABLED = <?= json_encode((bool)($settings['questsEnabled'] ?? true)) ?>;
  </script>
  <script>
  // ── Init ──────────────────────────────────────────────────────────────────
  (function init() {
    if (getToken()) {
      showApp();
    } else {
      document.getElementById('login-screen').style.display = 'flex';
    }
  })();

  // ── Login ─────────────────────────────────────────────────────────────────
  document.getElementById('login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const errEl = document.getElementById('login-error');
    errEl.textContent = '';
    const user = document.getElementById('login-user').value.trim();
    const pass = document.getElementById('login-pass').value;
    if (!user || !pass) { errEl.textContent = 'Enter username and password.'; return; }
    const hash = await sha256(pass);
    try {
      const res  = await fetch('index.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: user, hash })
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.token) {
        setToken(data.token);
        showApp();
      } else {
        errEl.textContent = data.error || 'Login failed.';
      }
    } catch { errEl.textContent = 'Connection error.'; }
  });

  setOnUnauthorized(() => {
    clearToken();
    document.getElementById('app-screen').style.display = 'none';
    document.getElementById('login-screen').style.display = 'flex';
  });

  function doLogout() { clearToken(); window.location.reload(); }

  // ── Show app ──────────────────────────────────────────────────────────────
  function showApp() {
    document.getElementById('login-screen').style.display = 'none';
    document.getElementById('app-screen').style.display   = 'block';
    const badge = document.getElementById('user-badge');
    if (badge) badge.textContent = getUser() + ' (' + getRole() + ')';
    updateModuleCards();
    if (getRole() === 'admin') {
      document.getElementById('admin-panel').style.display = '';
      loadThemeTab();
      loadUsersTab();
      buildBackupGrid();
      loadSettingsTab();
      loadPluginsTab();
    }
  }

  function updateModuleCards() {
    const marksCard  = document.getElementById('marks-card');
    const questsCard = document.getElementById('quests-card');
    if (marksCard)  marksCard.style.display  = window.WKW_MARKS_ENABLED  ? '' : 'none';
    if (questsCard) questsCard.style.display = window.WKW_QUESTS_ENABLED ? '' : 'none';
  }

  // ── Admin tab switching ───────────────────────────────────────────────────
  const _TABS = ['theme','users','backup','settings','plugins'];
  function showAdminTab(name) {
    document.querySelectorAll('.admin-tab').forEach((t, i) => t.classList.toggle('active', _TABS[i] === name));
    document.querySelectorAll('.admin-tab-panel').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
  }

  // ─────────────────────────────────────────────────────────────────────────
  // THEME TAB
  // ─────────────────────────────────────────────────────────────────────────
  async function loadThemeTab() {
    try {
      const [tr, sr] = await Promise.all([
        apiFetch('index.php?action=get-templates'),
        apiFetch('index.php?action=get-settings'),
      ]);
      const td = await tr.json();
      const sd = await sr.json();
      const sel = document.getElementById('theme-select');
      sel.innerHTML = (td.templates ?? []).map(t => `<option value="${escHtml(t)}">${escHtml(t)}</option>`).join('');
      sel.value = sd.settings?.theme ?? 'default.css';
    } catch { /* silent */ }
  }

  async function saveTheme() {
    const statusEl = document.getElementById('theme-status');
    statusEl.textContent = 'Saving…'; statusEl.className = 'ix-status';
    const theme = document.getElementById('theme-select').value;
    try {
      const res  = await apiFetch('index.php?action=save-settings', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ theme })
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok) {
        document.getElementById('app-theme-link').href = 'templates/' + theme;
        statusEl.textContent = 'Saved!'; statusEl.className = 'ix-status ok';
        setTimeout(() => { statusEl.textContent = ''; }, 2000);
        showToast('Theme saved.');
      } else {
        statusEl.textContent = data.error || 'Error'; statusEl.className = 'ix-status err';
      }
    } catch { statusEl.textContent = 'Connection error'; statusEl.className = 'ix-status err'; }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // USERS TAB
  // ─────────────────────────────────────────────────────────────────────────
  let _users = [];

  async function loadUsersTab() {
    try {
      const res  = await apiFetch('index.php?action=get-users');
      const data = await res.json();
      _users = data.users ?? [];
      renderUsers();
    } catch { /* silent */ }
  }

  function renderUsers() {
    const list = document.getElementById('users-list');
    if (!_users.length) { list.innerHTML = '<p style="color:#888;font-size:.88rem">No users.</p>'; return; }
    list.innerHTML = _users.map(u => {
      const isAdmin = u.role === 'admin';
      const enabled = u.enabled !== false;
      const editBtn = `<button class="btn btn-sm" onclick="openEditGuestModal('${escHtml(u.username)}')" title="Edit">
        <svg viewBox="0 0 24 24" style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      </button>`;
      const toggleBtn = `<button class="btn btn-sm" onclick="toggleGuestEnabled('${escHtml(u.username)}',${!enabled})" title="${enabled?'Disable':'Enable'}">
        <svg viewBox="0 0 24 24" style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2"><circle cx="12" cy="12" r="10"/>${enabled?'<line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>':'<polyline points="20 6 9 17 4 12"/>'}</svg>
      </button>`;
      const delBtn = `<button class="btn btn-sm btn-danger" onclick="openConfirmDel('${escHtml(u.username)}')" title="Delete">
        <svg viewBox="0 0 24 24" style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
      </button>`;
      const resetBtn = `<button class="btn btn-sm" onclick="openResetPassModal('${escHtml(u.username)}')" title="Reset password">
        <svg viewBox="0 0 24 24" style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </button>`;
      return `<div class="user-card">
  <div class="user-info">
    <span class="user-name">${escHtml(u.name||u.username)}</span>
    <span class="badge ${isAdmin?'badge-admin':(enabled?'badge-guest':'badge-off')}" style="margin-left:.4rem">${isAdmin?'admin':(enabled?'guest':'disabled')}</span><br>
    <span class="user-meta">${escHtml(u.username)}</span>
  </div>
  <div class="user-actions">
    ${!isAdmin ? editBtn + toggleBtn : ''}
    ${resetBtn}
    ${!isAdmin ? delBtn : ''}
  </div>
</div>`;
    }).join('');
  }

  function openAddGuestModal() {
    ['ag-username','ag-name','ag-pass','ag-pass2'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('ag-status').textContent = '';
    document.getElementById('add-guest-overlay').classList.add('open');
    document.getElementById('ag-username').focus();
  }
  function closeAddGuestModal() { document.getElementById('add-guest-overlay').classList.remove('open'); }

  async function submitAddGuest() {
    const statusEl = document.getElementById('ag-status');
    statusEl.textContent = ''; statusEl.className = 'ix-status';
    const username = document.getElementById('ag-username').value.trim();
    const name     = document.getElementById('ag-name').value.trim();
    const pass     = document.getElementById('ag-pass').value;
    const pass2    = document.getElementById('ag-pass2').value;
    if (!username) { statusEl.textContent = 'Username required.'; statusEl.className='ix-status err'; return; }
    if (!pass)     { statusEl.textContent = 'Password required.'; statusEl.className='ix-status err'; return; }
    if (pass !== pass2) { statusEl.textContent = 'Passwords do not match.'; statusEl.className='ix-status err'; return; }
    const hash = await sha256(pass);
    try {
      const res  = await apiFetch('index.php?action=add-guest', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({username,name,hash}) });
      const data = await res.json().catch(() => ({}));
      if (res.ok) { closeAddGuestModal(); showToast('Guest added.'); loadUsersTab(); }
      else { statusEl.textContent = data.error||'Error'; statusEl.className='ix-status err'; }
    } catch { statusEl.textContent='Connection error'; statusEl.className='ix-status err'; }
  }

  function openEditGuestModal(username) {
    const u = _users.find(x => x.username === username);
    if (!u) return;
    document.getElementById('eg-username-orig').value = username;
    document.getElementById('eg-name').value = u.name || '';
    document.getElementById('eg-status').textContent = '';
    document.getElementById('edit-guest-overlay').classList.add('open');
    document.getElementById('eg-name').focus();
  }
  function closeEditGuestModal() { document.getElementById('edit-guest-overlay').classList.remove('open'); }

  async function submitEditGuest() {
    const statusEl = document.getElementById('eg-status');
    statusEl.textContent = ''; statusEl.className='ix-status';
    const username = document.getElementById('eg-username-orig').value;
    const name     = document.getElementById('eg-name').value.trim();
    if (!name) { statusEl.textContent='Display name required.'; statusEl.className='ix-status err'; return; }
    try {
      const res  = await apiFetch('index.php?action=edit-guest', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({username,name}) });
      const data = await res.json().catch(() => ({}));
      if (res.ok) { closeEditGuestModal(); showToast('User updated.'); loadUsersTab(); }
      else { statusEl.textContent=data.error||'Error'; statusEl.className='ix-status err'; }
    } catch { statusEl.textContent='Connection error'; statusEl.className='ix-status err'; }
  }

  async function toggleGuestEnabled(username, enabled) {
    try {
      const res = await apiFetch('index.php?action=edit-guest', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({username,enabled}) });
      if (res.ok) { showToast(enabled?'User enabled.':'User disabled.'); loadUsersTab(); }
    } catch { showToast('Connection error','error'); }
  }

  function openResetPassModal(username) {
    document.getElementById('rp-username').value = username;
    document.getElementById('rp-new').value = '';
    document.getElementById('rp-confirm').value = '';
    document.getElementById('rp-status').textContent = '';
    document.getElementById('reset-pass-title').textContent = 'Reset password — ' + username;
    document.getElementById('reset-pass-overlay').classList.add('open');
    document.getElementById('rp-new').focus();
  }
  function closeResetPassModal() { document.getElementById('reset-pass-overlay').classList.remove('open'); }

  async function submitResetPass() {
    const statusEl = document.getElementById('rp-status');
    statusEl.textContent=''; statusEl.className='ix-status';
    const username = document.getElementById('rp-username').value;
    const pass     = document.getElementById('rp-new').value;
    const pass2    = document.getElementById('rp-confirm').value;
    if (!pass)        { statusEl.textContent='Password required.'; statusEl.className='ix-status err'; return; }
    if (pass !== pass2){ statusEl.textContent='Passwords do not match.'; statusEl.className='ix-status err'; return; }
    const hash = await sha256(pass);
    try {
      const res  = await apiFetch('index.php?action=reset-password', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({username,hash}) });
      const data = await res.json().catch(() => ({}));
      if (res.ok) { closeResetPassModal(); showToast('Password reset.'); }
      else { statusEl.textContent=data.error||'Error'; statusEl.className='ix-status err'; }
    } catch { statusEl.textContent='Connection error'; statusEl.className='ix-status err'; }
  }

  let _confirmDelCb = null;
  function openConfirmDel(username) {
    document.getElementById('confirm-del-msg').textContent = 'Delete guest "' + username + '"? This cannot be undone.';
    _confirmDelCb = () => deleteGuest(username);
    document.getElementById('confirm-del-ok').onclick = () => { _confirmDelCb && _confirmDelCb(); closeConfirmDel(); };
    document.getElementById('confirm-del-overlay').classList.add('open');
  }
  function closeConfirmDel() { document.getElementById('confirm-del-overlay').classList.remove('open'); _confirmDelCb=null; }

  async function deleteGuest(username) {
    try {
      const res = await apiFetch('index.php?action=delete-guest', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({username}) });
      if (res.ok) { showToast('User deleted.'); loadUsersTab(); }
      else { showToast('Error deleting user.','error'); }
    } catch { showToast('Connection error','error'); }
  }

  // ─────────────────────────────────────────────────────────────────────────
  // BACKUP TAB
  // ─────────────────────────────────────────────────────────────────────────
  const BACKUP_FILES = [
    { label:'Users',     action:'backup-users',    inputId:'ri-users',    apiBase:'index.php' },
    { label:'Settings',  action:'backup-settings', inputId:'ri-settings', apiBase:'index.php' },
    { label:'Items',     action:'backup-items',    inputId:'ri-items',    apiBase:'index.php' },
    { label:'Marks',     action:'backup-marks',    inputId:'ri-marks',    apiBase:'index.php' },
    { label:'Queries',   action:'backup-queries',  inputId:'ri-queries',  apiBase:'index.php' },
    { label:'Quests',    action:'backup-quests',   inputId:'ri-quests',   apiBase:'index.php' },
    { label:'Attempts',  action:'backup-attempts', inputId:'ri-attempts', apiBase:'index.php' },
    { label:'Wiki pages',action:'backup',          inputId:'ri-pages',    apiBase:'wiki.php', restoreAction:'restore', restoreApiBase:'wiki.php' },
  ];

  function buildBackupGrid() {
    const grid = document.getElementById('backup-grid');
    grid.innerHTML = BACKUP_FILES.map(f => `
<div class="backup-item">
  <h4>${escHtml(f.label)}</h4>
  <div class="backup-actions">
    <button class="btn btn-sm" onclick="doDownload('${f.action}','${f.apiBase}')" title="Download">
      <svg viewBox="0 0 24 24" style="width:.85em;height:.85em;fill:none;stroke:currentColor;stroke-width:2;vertical-align:middle;margin-right:.25em"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
      Download
    </button>
    <button class="btn btn-sm btn-danger" onclick="document.getElementById('${f.inputId}').click()" title="Restore">
      <svg viewBox="0 0 24 24" style="width:.85em;height:.85em;fill:none;stroke:currentColor;stroke-width:2;vertical-align:middle;margin-right:.25em"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
      Restore
    </button>
  </div>
</div>`).join('');
  }

  function doDownload(action, apiBase) {
    const tok = getToken();
    const a   = document.createElement('a');
    a.href    = (apiBase||'index.php') + '?action=' + action + '&_token=' + encodeURIComponent(tok||'');
    a.target  = '_blank';
    a.rel     = 'noopener noreferrer';
    a.click();
  }

  async function doRestore(action, input, apiBase) {
    const file = input.files[0];
    if (!file) return;
    if (!confirm('Restore "' + action + '" from "' + file.name + '"? Existing data will be overwritten.')) {
      input.value = ''; return;
    }
    const body = new FormData();
    body.append('file', file);
    try {
      const res  = await apiFetch((apiBase||'index.php') + '?action=' + action, { method:'POST', body });
      const data = await res.json().catch(() => ({}));
      if (res.ok) { showToast('Restore successful.'); }
      else { showToast(data.error||'Restore failed.','error'); }
    } catch { showToast('Connection error','error'); }
    input.value = '';
  }

  // ─────────────────────────────────────────────────────────────────────────
  // SETTINGS TAB
  // ─────────────────────────────────────────────────────────────────────────
  async function loadSettingsTab() {
    try {
      const [sr, hr] = await Promise.all([
        apiFetch('index.php?action=get-settings'),
        apiFetch('index.php?action=get-hljs-themes'),
      ]);
      const sd = await sr.json();
      const hd = await hr.json();
      const s  = sd.settings ?? {};
      const hlSel = document.getElementById('settings-hljs-theme');
      hlSel.innerHTML = (hd.themes??[]).map(t=>`<option value="${escHtml(t)}">${escHtml(t)}</option>`).join('');
      hlSel.value = s.hljsTheme ?? '';
      document.getElementById('settings-wiki-name').value             = s.wikiName ?? '';
      document.getElementById('settings-code-line-numbers').checked   = !!s.codeLineNumbers;
      document.getElementById('settings-guest-odt-download').checked  = !!s.guestOdtDownload;
      document.getElementById('settings-guest-toc').checked           = !!s.guestToc;
      document.getElementById('settings-guest-index').checked         = !!s.guestIndex;
      document.getElementById('settings-guest-login-enabled').checked = !!s.guestLoginEnabled;
      document.getElementById('settings-token-ttl').value             = s.tokenTtl ?? 3600;
      document.getElementById('settings-marks-enabled').checked  = window.WKW_MARKS_ENABLED;
      document.getElementById('settings-quests-enabled').checked = window.WKW_QUESTS_ENABLED;
    } catch { /* silent */ }
    document.getElementById('settings-jwt-secret').addEventListener('input', () => {
      document.getElementById('settings-admin-pass-row').style.display =
        document.getElementById('settings-jwt-secret').value ? 'block' : 'none';
    });
  }

  document.getElementById('settings-form').addEventListener('submit', async e => {
    e.preventDefault();
    const statusEl = document.getElementById('settings-status');
    statusEl.textContent='Saving…'; statusEl.className='ix-status';
    const body = {
      wikiName:          document.getElementById('settings-wiki-name').value.trim(),
      hljsTheme:         document.getElementById('settings-hljs-theme').value,
      codeLineNumbers:   document.getElementById('settings-code-line-numbers').checked,
      guestOdtDownload:  document.getElementById('settings-guest-odt-download').checked,
      guestToc:          document.getElementById('settings-guest-toc').checked,
      guestIndex:        document.getElementById('settings-guest-index').checked,
      guestLoginEnabled: document.getElementById('settings-guest-login-enabled').checked,
      tokenTtl:          parseInt(document.getElementById('settings-token-ttl').value,10),
      marksEnabled:      document.getElementById('settings-marks-enabled').checked,
      questsEnabled:     document.getElementById('settings-quests-enabled').checked,
    };
    const secret = document.getElementById('settings-jwt-secret').value;
    if (secret) { body.jwtSecret = secret; body.adminPass = document.getElementById('settings-admin-pass').value; }
    try {
      const res  = await apiFetch('index.php?action=save-settings', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body) });
      const data = await res.json().catch(() => ({}));
      if (res.ok) {
        window.WKW_MARKS_ENABLED  = body.marksEnabled;
        window.WKW_QUESTS_ENABLED = body.questsEnabled;
        updateModuleCards();
        statusEl.textContent='Saved!'; statusEl.className='ix-status ok';
        setTimeout(() => { statusEl.textContent=''; }, 2000);
        showToast('Settings saved.');
      } else { statusEl.textContent=data.error||'Error'; statusEl.className='ix-status err'; }
    } catch { statusEl.textContent='Connection error'; statusEl.className='ix-status err'; }
  });

  // ─────────────────────────────────────────────────────────────────────────
  // PLUGINS TAB
  // ─────────────────────────────────────────────────────────────────────────
  let _pluginDisabled = [];
  let _pluginFiles    = [];

  async function loadPluginsTab() {
    try {
      const res  = await apiFetch('index.php?action=get-front-plugins');
      const data = await res.json();
      _pluginFiles    = data.plugins  ?? [];
      _pluginDisabled = data.disabled ?? [];
      renderPlugins();
    } catch { /* silent */ }
  }

  function renderPlugins() {
    const list = document.getElementById('plugins-list');
    if (!_pluginFiles.length) {
      list.innerHTML = '<p style="color:#888;font-size:.88rem">No plugins installed.</p>';
      return;
    }
    list.innerHTML = _pluginFiles.map(f => {
      const id      = f.replace(/\.js$/, '');
      const enabled = !_pluginDisabled.includes(id);
      return `<div class="plugin-card">
  <div class="plugin-info"><strong>${escHtml(id)}</strong><br><small style="color:#888">${escHtml(f)}</small></div>
  <label class="toggle-switch" title="${enabled?'Disable':'Enable'} plugin">
    <input type="checkbox" ${enabled?'checked':''} onchange="togglePlugin('${escHtml(id)}',this)">
    <span class="toggle-slider"></span>
  </label>
</div>`;
    }).join('');
  }

  async function togglePlugin(id, checkbox) {
    const newEnabled = checkbox.checked;
    checkbox.disabled = true;
    if (newEnabled) { _pluginDisabled = _pluginDisabled.filter(x => x !== id); }
    else { if (!_pluginDisabled.includes(id)) _pluginDisabled.push(id); }
    try {
      const res = await apiFetch('index.php?action=save-plugin-state', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({disabled:_pluginDisabled}) });
      if (!res.ok) {
        if (newEnabled) { _pluginDisabled.push(id); } else { _pluginDisabled=_pluginDisabled.filter(x=>x!==id); }
        showToast('Error saving plugin state.','error');
      } else { showToast(newEnabled?'Plugin enabled.':'Plugin disabled.'); }
    } catch { showToast('Connection error','error'); }
    checkbox.disabled = false;
    renderPlugins();
  }
  </script>
</body>
</html>
