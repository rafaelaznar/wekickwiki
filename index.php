<?php
// ═══════════════════════════════════════════════════════════════════════════
// JWT, configuration and user helpers — shared with other apps via lib/auth.php
// ═══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/lib/auth.php';


// ═══════════════════════════════════════════════════════════════════════════
// Base path detection
// ═══════════════════════════════════════════════════════════════════════════
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';

// ═══════════════════════════════════════════════════════════════════════════
// User-management API endpoints — shared via lib/users-api.php
// Handles: login, get-users, save-users, add-guest, edit-guest, delete-guest,
//          reset-password, change-password
// ═══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/lib/users-api.php';
require_once __DIR__ . '/lib/wiki-api.php';

// Load validated settings before rendering the HTML shell; values are PHP-escaped on output.
$settings = load_settings();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($settings['wikiName']) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="icon.svg">
  <script src="vendor/marked.min.js"></script>
  <script src="vendor/highlight.min.js"></script>
  <link id="hljs-theme-link" rel="stylesheet" href="vendor/highlight-themes/<?= htmlspecialchars($settings['hljsTheme'], ENT_QUOTES) ?>">
  <link rel="stylesheet" href="templates/<?= htmlspecialchars($settings['theme'], ENT_QUOTES) ?>">
</head>

<body>

  <!-- ── Login screen ─────────────────────────────────────── -->
  <div id="login-screen">
    <div id="login-box">
      <h2><img src="icon.svg" style="width:2rem;height:2rem;vertical-align:middle;margin-right:.5rem;" alt=""><?= htmlspecialchars($settings['wikiName']) ?> — Sign in</h2>
      <form id="login-form" novalidate>
        <label>Username
          <input id="login-user" type="text" autocomplete="username" required autofocus>
        </label>
        <label>Password
          <input id="login-pass" type="password" autocomplete="current-password" required>
        </label>
        <button type="submit" title="Sign in" aria-label="Sign in"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
            <polyline points="10 17 15 12 10 7" />
            <line x1="15" y1="12" x2="3" y2="12" />
          </svg></button>
        <p id="login-error"></p>
      </form>
    </div>
  </div>

  <!-- ── Wiki screen ──────────────────────────────────────── -->
  <div id="wiki-screen" style="display:none">
    <header>
      <a id="header-title" href="" onclick="navigate('index');return false;"><img src="icon.svg" style="display:inline; width:1.5rem; height:1.5rem; margin-right:0.5rem; vertical-align:middle;" alt=""><?= htmlspecialchars($settings['wikiName']) ?></a>
      <div id="header-right">
        <span id="user-badge"></span>
        <button class="btn" id="mobile-menu-btn" aria-label="Menu" aria-expanded="false" onclick="toggleMobileMenu()"><svg viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div id="header-buttons">
        <button class="btn" id="toc-btn" title="Table of contents" aria-label="Table of contents" style="display:none" onclick="toggleToc()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="3" y1="5" x2="21" y2="5" />
            <line x1="6" y1="10" x2="21" y2="10" />
            <line x1="10" y1="15" x2="21" y2="15" />
            <line x1="6" y1="20" x2="21" y2="20" />
          </svg></button>
        <button class="btn" id="index-btn" title="Index" aria-label="Index" style="display:none" onclick="toggleIndex()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="21" y1="5" x2="3" y2="5" />
            <line x1="18" y1="10" x2="3" y2="10" />
            <line x1="14" y1="15" x2="3" y2="15" />
            <line x1="18" y1="20" x2="3" y2="20" />
          </svg></button>
        <button class="btn" id="users-btn" title="Manage users" aria-label="Manage users" style="display:none" onclick="toggleUsersPanel()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="8" r="4" />
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
            <circle cx="19" cy="8" r="3" stroke-width="1.5" />
            <line x1="19" y1="11" x2="19" y2="14" />
            <line x1="17.5" y1="12.5" x2="20.5" y2="12.5" />
          </svg></button>
        <button class="btn" id="backup-btn" title="Download backup" aria-label="Download backup" style="display:none" onclick="downloadBackup()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <polyline points="8 17 12 21 16 17" />
            <line x1="12" y1="12" x2="12" y2="21" />
            <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29" />
          </svg></button>
        <button class="btn btn-danger" id="restore-btn" title="Restore backup" aria-label="Restore backup" style="display:none" onclick="restoreBackup()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <polyline points="16 16 12 12 8 16" />
            <line x1="12" y1="12" x2="12" y2="21" />
            <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29" />
          </svg></button>
        <input type="file" id="restore-input" accept=".txt" style="display:none">
        <button class="btn" id="settings-btn" title="Settings" aria-label="Settings" style="display:none" onclick="toggleSettingsPanel()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
          </svg></button>
        <button class="btn" id="plugins-btn" title="Plugins" aria-label="Plugins" style="display:none" onclick="togglePluginsPanel()"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z" />
            <line x1="16" y1="8" x2="2" y2="22" />
            <line x1="17.5" y1="15" x2="9" y2="15" />
          </svg></button>
        <button class="btn" id="edit-btn" title="Edit" aria-label="Edit" style="display:none" onclick="toggleEdit()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
          </svg></button>
        <button class="btn btn-danger" id="delete-btn" title="Delete" aria-label="Delete" style="display:none" onclick="deletePage()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
            <path d="M10 11v6" />
            <path d="M14 11v6" />
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
          </svg></button>
        <button class="btn" id="home-btn" title="Home" aria-label="Home" style="display:none" onclick="navigate('index')"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
          </svg></button>
        <button class="btn" id="top-btn" title="Go to top" aria-label="Go to top" style="display:none" onclick="window.scrollTo({top:0,behavior:'smooth'})"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="12" y1="19" x2="12" y2="5" />
            <polyline points="5 12 12 5 19 12" />
          </svg></button>
        <button class="btn" id="odt-btn" title="Download as ODT" aria-label="Download as ODT" style="display:none" onclick="downloadOdt()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="12" y1="18" x2="12" y2="12" />
            <polyline points="9 15 12 18 15 15" />
          </svg></button>
        <button class="btn btn-danger" id="logout-btn" title="Sign out" aria-label="Sign out" onclick="logout()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <polyline points="16 17 21 12 16 7" />
            <line x1="21" y1="12" x2="9" y2="12" />
          </svg></button>
        <button class="btn" id="change-pass-btn" title="Change my password" aria-label="Change my password" style="display:none" onclick="toggleChangePasswordPanel()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg></button>
        </div>
      </div>
    </header>
    <nav id="nav"></nav>
    <main id="content"></main>

    <!-- Plugins panel -->
    <div id="plugins-overlay" onclick="togglePluginsPanel()"></div>
    <div id="plugins-panel">
      <h3>Plugins <button class="close-btn" onclick="togglePluginsPanel()">&times;</button></h3>
      <div id="plugins-list"></div>
    </div>

    <!-- TOC panel -->
    <div id="toc-overlay" onclick="toggleToc()"></div>
    <div id="toc-panel">
      <h3>Contents <button class="close-btn" onclick="toggleToc()">&times;</button></h3>
      <div id="toc-list"></div>
    </div>
    <!-- Index panel -->
    <div id="index-overlay" onclick="toggleIndex()"></div>
    <div id="index-panel">
      <h3>Pages <button class="close-btn" onclick="toggleIndex()">&times;</button></h3>
      <div id="index-tree"></div>
    </div>
    <!-- Users management panel -->
    <div id="users-overlay" onclick="toggleUsersPanel()"></div>
    <div id="users-panel">
      <h3>Manage users <button class="close-btn" onclick="toggleUsersPanel()">&times;</button></h3>
      <div id="users-grid"></div>
      <div id="guest-add-form" style="display:none">
        <fieldset>
          <legend>Add guest</legend>
          <label>Username
            <input type="text" id="guest-add-username" autocomplete="off" maxlength="32" pattern="[a-z0-9_]+" placeholder="username">
          </label>
          <label>Name
            <input type="text" id="guest-add-name" autocomplete="off" maxlength="64" placeholder="Display name">
          </label>
          <label>Password
            <input type="password" id="guest-add-pass" autocomplete="new-password" placeholder="••••••••">
          </label>
          <label>Confirm password
            <input type="password" id="guest-add-pass2" autocomplete="new-password" placeholder="••••••••">
          </label>
          <div style="display:flex;gap:.5rem;margin-top:.6rem;align-items:center">
            <span id="guest-add-status" style="flex:1;font-size:.8rem;color:#c0392b"></span>
            <button type="button" class="btn" onclick="hideAddGuestForm()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitAddGuest()">Add</button>
          </div>
        </fieldset>
      </div>
      <button type="button" class="btn" id="guest-add-btn" onclick="showAddGuestForm()" style="margin-top:.75rem;width:100%">+ Add guest user</button>
    </div>

    <!-- Change password panel (guest self-service) -->
    <div id="change-password-overlay" onclick="toggleChangePasswordPanel()"></div>
    <div id="change-password-panel">
      <h3>Change my password <button class="close-btn" onclick="toggleChangePasswordPanel()">&times;</button></h3>
      <form id="change-password-form" novalidate>
        <label>New password
          <input type="password" id="change-pass-new" autocomplete="new-password" placeholder="••••••••">
        </label>
        <label>Confirm new password
          <input type="password" id="change-pass-confirm" autocomplete="new-password" placeholder="••••••••">
        </label>
        <div id="change-password-form-actions">
          <span id="change-password-status"></span>
          <button type="button" class="btn" onclick="toggleChangePasswordPanel()">Cancel</button>
          <button type="submit" class="btn btn-primary">Change password</button>
        </div>
      </form>
    </div>

    <!-- Reset password dialog (admin → any user) -->
    <div id="reset-password-overlay"></div>
    <div id="reset-password-dialog">
      <h4 id="reset-password-title">Reset password</h4>
      <label>New password
        <input type="password" id="reset-pass-new" autocomplete="new-password" placeholder="••••••••">
      </label>
      <label>Confirm password
        <input type="password" id="reset-pass-confirm" autocomplete="new-password" placeholder="••••••••">
      </label>
      <div id="reset-password-actions">
        <span id="reset-password-status"></span>
        <button type="button" class="btn" id="reset-password-cancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="reset-password-ok">Reset</button>
      </div>
    </div>
    <!-- Settings panel -->
    <div id="settings-overlay" onclick="toggleSettingsPanel()"></div>
    <!-- Confirm delete dialog -->
    <div id="confirm-delete-overlay"></div>
    <div id="confirm-delete-dialog" role="dialog" aria-modal="true">
      <p id="confirm-delete-msg"></p>
      <p class="confirm-delete-subtitle">This action cannot be undone.</p>
      <div class="confirm-delete-actions">
        <button class="btn" id="confirm-delete-cancel">Cancel</button>
        <button class="btn btn-danger" id="confirm-delete-ok">Delete</button>
      </div>
    </div>
    <div id="settings-panel">
      <h3>Settings <button class="close-btn" onclick="toggleSettingsPanel()">&times;</button></h3>
      <form id="settings-form" novalidate>
        <label>Wiki name
          <input type="text" id="settings-wiki-name" autocomplete="off" maxlength="64">
        </label>
        <label>Theme
          <select id="settings-theme"></select>
        </label>
        <label>Code highlight theme
          <select id="settings-hljs-theme"></select>
        </label>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.85rem">
          <input type="checkbox" id="settings-code-line-numbers" style="width:auto;cursor:pointer">
          <label for="settings-code-line-numbers" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Show line numbers in code blocks</label>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.85rem">
          <input type="checkbox" id="settings-guest-odt-download" style="width:auto;cursor:pointer">
          <label for="settings-guest-odt-download" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest to download ODT</label>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.85rem">
          <input type="checkbox" id="settings-guest-toc" style="width:auto;cursor:pointer">
          <label for="settings-guest-toc" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest to view Table of contents</label>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.85rem">
          <input type="checkbox" id="settings-guest-index" style="width:auto;cursor:pointer">
          <label for="settings-guest-index" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest to view Index</label>
        </div>
        <fieldset style="border:1px solid #e0e0e0;border-radius:6px;padding:.75rem 1rem .85rem;margin-top:1rem">
          <legend style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#555;padding:0 .35rem">Security</legend>
          <div style="display:flex;align-items:center;gap:.6rem;margin-top:.25rem">
            <input type="checkbox" id="settings-guest-login-enabled" style="width:auto;cursor:pointer">
            <label for="settings-guest-login-enabled" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest logins</label>
          </div>
          <div id="settings-jwt-secret-row">
            <label style="margin-top:.85rem">JWT Secret <span class="hint">(leave blank to keep)</span>
              <input type="text" id="settings-jwt-secret" autocomplete="off" minlength="16" maxlength="128" placeholder="leave blank to keep" style="font-family:monospace;font-size:.85rem">
            </label>
            <label id="settings-admin-pass-label" style="display:none;margin-top:.6rem">Admin password <span class="hint">(required when changing JWT secret)</span>
              <input type="password" id="settings-admin-pass" autocomplete="new-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
            </label>
          </div>
          <label style="margin-top:.6rem">Token TTL <span class="hint">(seconds, 60&#x2013;86400)</span>
            <input type="number" id="settings-token-ttl" min="60" max="86400" step="60">
          </label>
        </fieldset>
        <div id="settings-form-actions">
          <span id="settings-save-status"></span>
          <button type="button" class="btn" onclick="toggleSettingsPanel()">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
    <div id="editor">
      <div id="editor-bar">
        <button class="btn btn-primary" title="Save" aria-label="Save" onclick="save()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
            <polyline points="17 21 17 13 7 13 7 21" />
            <polyline points="7 3 7 8 15 8" />
          </svg></button>
        <button class="btn" title="Cancel" aria-label="Cancel" onclick="cancelEdit()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg></button>
        <span id="save-status"></span>
      </div>
      <textarea id="editor-area" spellcheck="false"></textarea>
    </div>
  </div>

  <div id="footer">
    <a href="https://github.com/rafaelaznar/wekickwiki"><?= htmlspecialchars($settings['wikiName']) ?> 2026 v2 | MIT Licensed | By Rafael Aznar</a>
  </div>

  <div id="toast"></div>

  <?php // Expose the base path and code-line-numbers flag to JavaScript before loading the main app 
  ?>
  <script>
    window.WKW_BASE = <?= json_encode($baseHref) ?>;
    window.WKW_CODE_LINE_NUMBERS = <?= $settings['codeLineNumbers'] ? 'true' : 'false' ?>;
    window.WKW_GUEST_ODT_DOWNLOAD = <?= $settings['guestOdtDownload'] ? 'true' : 'false' ?>;
    window.WKW_GUEST_TOC   = <?= $settings['guestToc']   ? 'true' : 'false' ?>;
    window.WKW_GUEST_INDEX = <?= $settings['guestIndex'] ? 'true' : 'false' ?>;
  </script>
  <script src="lib/auth-client.js?v=<?= filemtime(__DIR__ . '/lib/auth-client.js') ?>"></script>
  <script src="wiki.js?v=<?= filemtime(__DIR__ . '/wiki.js') ?>"></script>
  <?php // Dynamically inject each enabled front-end plugin as a <script> tag 
  ?>
  <?php foreach (front_plugins() as $pf): ?>
    <script src="front-plugins/<?= htmlspecialchars($pf, ENT_QUOTES) ?>"></script>
  <?php endforeach; ?>
</body>

</html>