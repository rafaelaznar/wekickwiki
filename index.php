<?php
// ═══════════════════════════════════════════════════════════════════════════
// index.php — Central authentication hub for WeKickWiki
//
// Handles all auth API endpoints (login, user management) shared by:
//   wiki.php, marks.php, quests.php
//
// Includes:
//   lib/auth.php      — JWT helpers, load_users(), require_auth(), json_out()
//   lib/users-api.php — ?action=login, get-users, save-users, add-guest,
//                       edit-guest, delete-guest, reset-password, change-password
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users-api.php';

// ── Base path detection ───────────────────────────────────────────────────────
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';

// ── Read app name and hub theme from settings ─────────────────────────────────
$_rawSettings = is_file(SETTINGS_FILE)
  ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? [])
  : [];
$hubName = (!empty($_rawSettings['wikiName']) && is_string($_rawSettings['wikiName']))
  ? $_rawSettings['wikiName']
  : 'WeKickWiki';
$hubTheme = 'default.css';
if (
  !empty($_rawSettings['hubTheme']) &&
  preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_rawSettings['hubTheme']) &&
  is_file(__DIR__ . '/templates/' . $_rawSettings['hubTheme'])
) {
  $hubTheme = $_rawSettings['hubTheme'];
}
unset($_rawSettings);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($hubName) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="icon.svg">
  <link id="hub-theme-link" rel="stylesheet" href="templates/<?= htmlspecialchars($hubTheme, ENT_QUOTES) ?>">
</head>

<body>

  <!-- ── Login screen ──────────────────────────────────────────────── -->
  <div id="login-screen">
    <div id="login-box">
      <h2>
        <img src="icon.svg" style="width:2rem;height:2rem;vertical-align:middle;margin-right:.5rem;" alt="">
        <?= htmlspecialchars($hubName) ?> — Sign in
      </h2>
      <form id="login-form" novalidate>
        <label>Username
          <input id="login-user" type="text" autocomplete="username" required autofocus>
        </label>
        <label>Password
          <input id="login-pass" type="password" autocomplete="current-password" required>
        </label>
        <button type="submit" title="Sign in" aria-label="Sign in">
          <svg viewBox="0 0 24 24" aria-hidden="true" style="width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:2;margin-right:.4rem">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
            <polyline points="10 17 15 12 10 7" />
            <line x1="15" y1="12" x2="3" y2="12" />
          </svg>
          Sign in
        </button>
        <p id="login-error"></p>
      </form>
    </div>
  </div>

  <!-- ── Hub screen (after login) ──────────────────────────────────── -->
  <div id="hub-screen">
    <header id="hub-header">
      <div id="hub-title">
        <img src="icon.svg" style="width:1.4rem;height:1.4rem;" alt="">
        <?= htmlspecialchars($hubName) ?>
      </div>
      <div id="hub-header-right">
        <span id="hub-user-badge"></span>
        <button class="btn btn-sm" id="hub-users-btn" title="Manage users" aria-label="Manage users" style="display:none" onclick="hubToggleUsersPanel()">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="8" r="4" />
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
            <circle cx="19" cy="8" r="3" stroke-width="1.5" />
            <line x1="19" y1="11" x2="19" y2="14" />
            <line x1="17.5" y1="12.5" x2="20.5" y2="12.5" />
          </svg>
          Users
        </button>
        <button class="btn btn-sm" id="hub-themes-btn" title="Themes" aria-label="Themes" style="display:none" onclick="hubToggleThemesPanel()">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2a10 10 0 0 0 0 20c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z" />
          </svg>
          Themes
        </button>
        <button class="btn btn-sm" id="hub-security-btn" title="Security" aria-label="Security" style="display:none" onclick="hubToggleSecurityPanel()">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2l8 4v6c0 5-3.4 8.7-8 10-4.6-1.3-8-5-8-10V6l8-4z" />
            <path d="M9 12l2 2 4-4" />
          </svg>
          Security
        </button>
        <button class="btn btn-sm" id="hub-change-pass-btn" title="Change my password" aria-label="Change my password" style="display:none" onclick="hubToggleChangePasswordPanel()">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg>
          Password
        </button>
        <button class="btn btn-sm" onclick="hubLogout()">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <polyline points="16 17 21 12 16 7" />
            <line x1="21" y1="12" x2="9" y2="12" />
          </svg>
          Sign out
        </button>
      </div>
    </header>

    <main id="hub-main">
      <h2>Select a module</h2>
      <div id="hub-cards">
        <a href="wiki/wiki.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" />
            <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" />
          </svg>
          <h3>Wiki</h3>
          <p>Document and share knowledge</p>
        </a>
        <a href="marks/marks.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="16" y1="13" x2="8" y2="13" />
            <line x1="16" y1="17" x2="8" y2="17" />
            <polyline points="10 9 9 9 8 9" />
          </svg>
          <h3>Qualifications</h3>
          <p>Track and review student grades</p>
        </a>
        <a href="quests/quests.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="10" />
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
          </svg>
          <h3>Quests</h3>
          <p>Create and take interactive quizzes</p>
        </a>
        <a href="feedback/feedback.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
          </svg>
          <h3>Feedback</h3>
          <p>Collect and review event feedback</p>
        </a>
        <a href="projects/projects.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="2" y="7" width="20" height="14" rx="2" />
            <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2" />
            <line x1="12" y1="12" x2="12" y2="17" />
            <line x1="9.5" y1="14.5" x2="14.5" y2="14.5" />
          </svg>
          <h3>Projects</h3>
          <p>Manage software project tasks and sprints</p>
        </a>
        <a href="calendar/calendar.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
            <line x1="16" y1="2" x2="16" y2="6" />
            <line x1="8" y1="2" x2="8" y2="6" />
            <line x1="3" y1="10" x2="21" y2="10" />
          </svg>
          <h3>Calendar</h3>
          <p>View upcoming events and schedule</p>
        </a>
      </div>
    </main>

    <!-- Users management panel (admin only) -->
    <div id="users-overlay" class="hub-overlay" onclick="hubToggleUsersPanel()"></div>
    <div id="users-panel" class="hub-panel">
      <h3>Manage users <button class="close-btn" onclick="hubToggleUsersPanel()">&times;</button></h3>
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
            <button type="button" class="btn btn-sm" onclick="hubHideAddGuestForm()">Cancel</button>
            <button type="button" class="btn btn-sm btn-primary" onclick="hubSubmitAddGuest()">Add</button>
          </div>
        </fieldset>
      </div>
      <button type="button" class="btn btn-sm" id="guest-add-btn" onclick="hubShowAddGuestForm()" style="margin-top:.75rem;width:100%">+ Add guest user</button>
    </div>

    <!-- Security panel (admin only) -->
    <div id="security-overlay" class="hub-overlay" onclick="hubToggleSecurityPanel()"></div>
    <div id="security-panel" class="hub-panel hub-panel-sm">
      <h3>Security <button class="close-btn" onclick="hubToggleSecurityPanel()">&times;</button></h3>
      <form id="security-form" novalidate>
        <div id="security-guest-login-row">
          <input type="checkbox" id="security-guest-login-enabled" class="settings-check">
          <label for="security-guest-login-enabled">Allow guest logins</label>
        </div>
        <label>Token TTL <span class="hint">(seconds, 60&#x2013;86400)</span>
          <input type="number" id="security-token-ttl" min="60" max="86400" step="60">
        </label>
        <div id="security-form-actions">
          <span id="security-save-status"></span>
          <button type="button" class="btn btn-sm" onclick="hubToggleSecurityPanel()">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </div>
      </form>
    </div>

    <!-- Themes panel (admin only) -->
    <div id="themes-overlay" class="hub-overlay" onclick="hubToggleThemesPanel()"></div>
    <div id="themes-panel" class="hub-panel hub-panel-sm">
      <h3>Themes <button class="close-btn" onclick="hubToggleThemesPanel()">&times;</button></h3>
      <form id="themes-form" novalidate>
        <div class="themes-grid">
          <div>
            <label for="th-hub">Hub</label>
            <select id="th-hub">
              <option>default.css</option>
              <option>flower-power.css</option>
              <option>impact.css</option>
            </select>
          </div>
          <div>
            <label for="th-wiki">Wiki</label>
            <select id="th-wiki">
              <option>default.css</option>
              <option>flower-power.css</option>
              <option>impact.css</option>
            </select>
          </div>
          <div>
            <label for="th-marks">Qualifications</label>
            <select id="th-marks">
              <option>default.css</option>
              <option>flower-power.css</option>
              <option>impact.css</option>
            </select>
          </div>
          <div>
            <label for="th-quests">Quests</label>
            <select id="th-quests">
              <option>default.css</option>
              <option>flower-power.css</option>
              <option>impact.css</option>
            </select>
          </div>
          <div>
            <label for="th-feedback">Feedback</label>
            <select id="th-feedback">
              <option>default.css</option>
              <option>flower-power.css</option>
              <option>impact.css</option>
            </select>
          </div>
          <div>
            <label for="th-projects">Projects</label>
            <select id="th-projects">
              <option>default.css</option>
              <option>flower-power.css</option>
              <option>impact.css</option>
            </select>
          </div>
          <div>
            <label for="th-calendar">Calendar</label>
            <select id="th-calendar">
              <option>default.css</option>
              <option>flower-power.css</option>
              <option>impact.css</option>
            </select>
          </div>
        </div>
        <div id="themes-form-actions">
          <span id="themes-save-status"></span>
          <button type="button" class="btn btn-sm" onclick="hubToggleThemesPanel()">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </div>
      </form>
    </div>

    <!-- Change password panel (self-service) -->
    <div id="change-password-overlay" class="hub-overlay" onclick="hubToggleChangePasswordPanel()"></div>
    <div id="change-password-panel" class="hub-panel">
      <h3>Change my password <button class="close-btn" onclick="hubToggleChangePasswordPanel()">&times;</button></h3>
      <form id="change-password-form" novalidate>
        <label>New password
          <input type="password" id="change-pass-new" autocomplete="new-password" placeholder="••••••••">
        </label>
        <label>Confirm new password
          <input type="password" id="change-pass-confirm" autocomplete="new-password" placeholder="••••••••">
        </label>
        <div id="change-password-form-actions">
          <span id="change-password-status"></span>
          <button type="button" class="btn btn-sm" onclick="hubToggleChangePasswordPanel()">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary">Change password</button>
        </div>
      </form>
    </div>

    <!-- Reset password dialog (admin → any user) -->
    <div id="reset-password-overlay" class="hub-overlay"></div>
    <div id="reset-password-dialog" class="hub-panel">
      <h4 id="reset-password-title">Reset password</h4>
      <label>New password
        <input type="password" id="reset-pass-new" autocomplete="new-password" placeholder="••••••••">
      </label>
      <label>Confirm password
        <input type="password" id="reset-pass-confirm" autocomplete="new-password" placeholder="••••••••">
      </label>
      <div id="reset-password-actions">
        <span id="reset-password-status"></span>
        <button type="button" class="btn btn-sm" id="reset-password-cancel">Cancel</button>
        <button type="button" class="btn btn-sm btn-primary" id="reset-password-ok">Reset</button>
      </div>
    </div>
  </div><!-- #hub-screen -->

  <div id="footer">
    <a href="https://github.com/rafaelaznar/wekickwiki"><?= htmlspecialchars($hubName) ?> 2026 v2 | MIT Licensed | By Rafael Aznar</a>
  </div>

  <script>
    window.WKW_BASE = <?= json_encode($baseHref) ?>;
  </script>
  <script src="lib/auth-client.js?v=<?= filemtime(__DIR__ . '/lib/auth-client.js') ?>"></script>
  <script src="lib/hub.js?v=<?= filemtime(__DIR__ . '/lib/hub.js') ?>"></script>
</body>

</html>