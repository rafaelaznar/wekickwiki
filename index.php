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

// ── Read app name from settings ───────────────────────────────────────────────
$_rawSettings = is_file(SETTINGS_FILE)
    ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? [])
    : [];
$hubName = (!empty($_rawSettings['wikiName']) && is_string($_rawSettings['wikiName']))
    ? $_rawSettings['wikiName']
    : 'WeKickWiki';
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
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      line-height: 1.6;
      color: #222;
      font: normal 87.5%/1.4 Arial, system-ui, sans-serif;
      background: #fbfaf9;
    }

    /* ── Login screen ─────────────────────────────────────────────────── */
    #login-screen {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: #f4f4f5;
      padding: 1rem;
    }
    #login-box {
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 360px;
      box-shadow: 0 4px 24px rgba(0,0,0,.07);
    }
    #login-box h2 {
      font-size: 1.3rem;
      margin-bottom: 1.5rem;
      text-align: center;
    }
    #login-box label {
      display: block;
      font-size: .85rem;
      font-weight: 600;
      margin-bottom: .9rem;
    }
    #login-box input {
      display: block;
      width: 100%;
      margin-top: .25rem;
      padding: .55rem .75rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: 1rem;
      outline: none;
    }
    #login-box input:focus { border-color: #05c; }
    #login-box button {
      width: 100%;
      margin-top: 1.2rem;
      padding: .65rem;
      background: #05c;
      color: #fff;
      border: none;
      border-radius: 4px;
      font-size: 1rem;
      cursor: pointer;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    #login-box button:hover { background: #004ab3; }
    #login-error {
      margin-top: .75rem;
      font-size: .85rem;
      color: #c00;
      text-align: center;
      min-height: 1.2rem;
    }

    /* ── Hub screen ───────────────────────────────────────────────────── */
    #hub-screen { display: none; }

    #hub-header {
      border-bottom: 2px solid #222;
      padding: .5rem 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: fixed;
      top: 0; left: 0; right: 0;
      background: #fff;
      z-index: 1000;
      box-shadow: 0 2px 5px rgba(0,0,0,.1);
      min-height: 50px;
    }
    #hub-title {
      font-size: 1.3rem;
      font-weight: 700;
      color: #111;
      display: flex;
      align-items: center;
      gap: .4rem;
      text-decoration: none;
    }
    #hub-header-right {
      display: flex;
      gap: .5rem;
      align-items: center;
    }
    #hub-user-badge {
      font-size: .82rem;
      color: #555;
      font-weight: 600;
    }

    /* ── Hub main ─────────────────────────────────────────────────────── */
    #hub-main {
      max-width: 860px;
      margin: 80px auto 50px;
      padding: 2rem 1.5rem;
    }
    #hub-main h2 {
      font-size: 1.1rem;
      font-weight: 700;
      color: #444;
      margin-bottom: 1.25rem;
    }
    #hub-cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 1.25rem;
    }
    .hub-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: .75rem;
      padding: 2rem 1.5rem;
      background: #fff;
      border: 1px solid #e0e0e0;
      border-radius: 10px;
      text-decoration: none;
      color: #222;
      box-shadow: 0 2px 10px rgba(0,0,0,.06);
      transition: box-shadow .15s, border-color .15s, transform .12s;
      cursor: pointer;
    }
    .hub-card:hover {
      box-shadow: 0 6px 20px rgba(0,0,0,.12);
      border-color: #05c;
      transform: translateY(-2px);
    }
    .hub-card svg {
      width: 2.5rem;
      height: 2.5rem;
      fill: none;
      stroke: #05c;
      stroke-width: 1.6;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .hub-card h3 {
      font-size: 1rem;
      font-weight: 700;
      text-align: center;
    }
    .hub-card p {
      font-size: .82rem;
      color: #777;
      text-align: center;
    }

    /* ── Buttons ──────────────────────────────────────────────────────── */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .45rem .9rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      background: #fafafa;
      cursor: pointer;
      font-size: .85rem;
      font-weight: 600;
      color: #333;
      text-decoration: none;
      transition: background .15s, border-color .15s;
    }
    .btn:hover { background: #eee; border-color: #bbb; }
    .btn-primary { background: #05c; color: #fff; border-color: #05c; }
    .btn-primary:hover { background: #004ab3; border-color: #004ab3; }
    .btn-sm { padding: .25rem .55rem; font-size: .78rem; }
    .btn-danger { background: #c0392b; color: #fff; border-color: #c0392b; }
    .btn-danger:hover { background: #a93226; border-color: #a93226; }
    .btn svg { width: 1em; height: 1em; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    /* ── Users management panel ───────────────────────────────────────── */
    #users-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.45);
      z-index: 100;
    }
    #users-panel {
      display: none;
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: min(820px, 96vw);
      max-height: 90vh;
      overflow-y: auto;
      background: #fff;
      box-shadow: 0 8px 40px rgba(0,0,0,.22);
      border-radius: 8px;
      padding: 1.5rem 1.75rem 1.75rem;
      z-index: 101;
    }
    #users-panel h3 {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 1.25rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    #users-panel button.close-btn {
      background: none;
      border: none;
      font-size: 1.2rem;
      cursor: pointer;
      color: #666;
      line-height: 1;
    }
    #users-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .75rem;
      align-items: start;
      margin-bottom: .5rem;
    }
    @media (max-width: 560px) { #users-grid { grid-template-columns: 1fr; } }
    .user-card {
      border: 1px solid #e8e8e8;
      border-radius: 8px;
      padding: .6rem .85rem;
      background: #fafafa;
    }
    .admin-card { border-color: #b8d4f4; background: #f0f6ff; }
    .user-card-view { display: flex; align-items: center; gap: .5rem; }
    .user-card-info { flex: 1; min-width: 0; }
    .user-card-name { font-size: .88rem; font-weight: 700; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .user-card-username { font-size: .75rem; color: #888; display: block; }
    .user-card-badge {
      display: inline-block;
      font-size: .63rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .05em;
      background: #2a7ae2;
      color: #fff;
      border-radius: 3px;
      padding: .05rem .35rem;
      margin-top: .2rem;
    }
    .user-card-controls { display: flex; align-items: center; gap: .3rem; flex-shrink: 0; }
    .user-card-edit { margin-top: .5rem; border-top: 1px solid #e0e0e0; padding-top: .5rem; }
    .user-card-edit label { display: block; font-size: .8rem; font-weight: 600; color: #333; margin-top: .4rem; }
    .user-card-edit label:first-of-type { margin-top: 0; }
    .user-card-edit input {
      display: block;
      width: 100%;
      margin-top: .15rem;
      padding: .35rem .55rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: .85rem;
      outline: none;
      font-family: inherit;
    }
    .user-card-edit input:focus { border-color: #05c; }
    .guest-edit-status { font-size: .78rem; color: #c0392b; display: block; min-height: 1rem; margin-top: .25rem; }
    #guest-add-form fieldset {
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      padding: .75rem 1rem .85rem;
      margin-top: .75rem;
    }
    #guest-add-form legend {
      font-size: .78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #555;
      padding: 0 .35rem;
    }
    #guest-add-form label { display: block; font-size: .82rem; font-weight: 600; color: #333; margin-top: .6rem; }
    #guest-add-form label:first-of-type { margin-top: 0; }
    #guest-add-form input {
      display: block;
      width: 100%;
      margin-top: .2rem;
      padding: .45rem .65rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: .92rem;
      outline: none;
      font-family: inherit;
    }
    #guest-add-form input:focus { border-color: #05c; }
    .toggle-switch { position: relative; display: inline-block; width: 38px; height: 22px; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute;
      inset: 0;
      background: #ccc;
      border-radius: 22px;
      cursor: pointer;
      transition: .25s;
    }
    .toggle-slider::before {
      content: '';
      position: absolute;
      width: 16px;
      height: 16px;
      left: 3px;
      bottom: 3px;
      background: #fff;
      border-radius: 50%;
      transition: .25s;
    }
    .toggle-switch input:checked + .toggle-slider { background: #2a7ae2; }
    .toggle-switch input:checked + .toggle-slider::before { transform: translateX(16px); }

    /* ── Change password panel ────────────────────────────────────────── */
    #change-password-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.45);
      z-index: 102;
    }
    #change-password-panel {
      display: none;
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: min(340px, 92vw);
      background: #fff;
      box-shadow: 0 8px 40px rgba(0,0,0,.22);
      border-radius: 8px;
      padding: 1.5rem 1.75rem 1.75rem;
      z-index: 103;
    }
    #change-password-panel h3 {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 1.25rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    #change-password-panel .close-btn {
      background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #666; line-height: 1;
    }
    #change-password-form label { display: block; font-size: .82rem; font-weight: 600; color: #333; margin-top: .75rem; }
    #change-password-form label:first-of-type { margin-top: 0; }
    #change-password-form input {
      display: block;
      width: 100%;
      margin-top: .2rem;
      padding: .45rem .65rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: .92rem;
      outline: none;
      font-family: inherit;
    }
    #change-password-form input:focus { border-color: #05c; }
    #change-password-form-actions {
      display: flex;
      gap: .6rem;
      justify-content: flex-end;
      margin-top: 1rem;
      align-items: center;
    }
    #change-password-status { font-size: .8rem; color: #c0392b; flex: 1; line-height: 1.4; }

    /* ── Reset password dialog ────────────────────────────────────────── */
    #reset-password-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.45);
      z-index: 200;
    }
    #reset-password-dialog {
      display: none;
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: min(320px, 92vw);
      background: #fff;
      box-shadow: 0 8px 40px rgba(0,0,0,.22);
      border-radius: 8px;
      padding: 1.5rem 1.75rem 1.75rem;
      z-index: 201;
    }
    #reset-password-dialog h4 { font-size: .95rem; font-weight: 700; margin-bottom: 1rem; }
    #reset-password-dialog label { display: block; font-size: .82rem; font-weight: 600; color: #333; margin-top: .75rem; }
    #reset-password-dialog label:first-of-type { margin-top: 0; }
    #reset-password-dialog input {
      display: block;
      width: 100%;
      margin-top: .2rem;
      padding: .45rem .65rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: .92rem;
      outline: none;
      font-family: inherit;
    }
    #reset-password-dialog input:focus { border-color: #05c; }
    #reset-password-actions {
      display: flex;
      gap: .6rem;
      justify-content: flex-end;
      margin-top: 1rem;
      align-items: center;
    }
    #reset-password-status { font-size: .8rem; color: #c0392b; flex: 1; line-height: 1.4; }

    /* ── Footer ───────────────────────────────────────────────────────── */
    #footer {
      text-align: center;
      margin: 1rem auto 2rem;
    }
    #footer a { font-size: .75rem; color: #8A0808; }
  </style>
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
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/><circle cx="19" cy="8" r="3" stroke-width="1.5"/><line x1="19" y1="11" x2="19" y2="14"/><line x1="17.5" y1="12.5" x2="20.5" y2="12.5"/></svg>
          Users
        </button>
        <button class="btn btn-sm" id="hub-change-pass-btn" title="Change my password" aria-label="Change my password" style="display:none" onclick="hubToggleChangePasswordPanel()">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Password
        </button>
        <button class="btn btn-sm" onclick="hubLogout()">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sign out
        </button>
      </div>
    </header>

    <main id="hub-main">
      <h2>Select a module</h2>
      <div id="hub-cards">
        <a href="wiki/wiki.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
            <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
          </svg>
          <h3>Wiki</h3>
          <p>Document and share knowledge</p>
        </a>
        <a href="marks/marks.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/>
            <line x1="16" y1="17" x2="8" y2="17"/>
            <polyline points="10 9 9 9 8 9"/>
          </svg>
          <h3>Qualifications</h3>
          <p>Track and review student grades</p>
        </a>
        <a href="quests/quests.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
          <h3>Quests</h3>
          <p>Create and take interactive quizzes</p>
        </a>
        <a href="feedback/feedback.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          <h3>Feedback</h3>
          <p>Collect and review event feedback</p>
        </a>
        <a href="projects/projects.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="2" y="7" width="20" height="14" rx="2"/>
            <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
            <line x1="12" y1="12" x2="12" y2="17"/>
            <line x1="9.5" y1="14.5" x2="14.5" y2="14.5"/>
          </svg>
          <h3>Projects</h3>
          <p>Manage software project tasks and sprints</p>
        </a>
        <a href="calendar/calendar.php" class="hub-card">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/>
            <line x1="8" y1="2" x2="8" y2="6"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
          </svg>
          <h3>Calendar</h3>
          <p>View upcoming events and schedule</p>
        </a>
      </div>
    </main>

    <!-- Users management panel (admin only) -->
    <div id="users-overlay" onclick="hubToggleUsersPanel()"></div>
    <div id="users-panel">
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

    <!-- Change password panel (self-service) -->
    <div id="change-password-overlay" onclick="hubToggleChangePasswordPanel()"></div>
    <div id="change-password-panel">
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
