<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/projects-api.php';

// ── Theme & app name ─────────────────────────────────────────────────────────
$pt_theme    = 'default.css';
$pt_app_name = 'Projects';
$_raw = data_read(SETTINGS_FILE);
if (!empty($_raw['wikiName'])) $pt_app_name = $_raw['wikiName'] . ' — Projects';
if (!empty($_raw['projectsTheme']) &&
    preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['projectsTheme']) &&
    is_file(__DIR__ . '/templates-projects/' . $_raw['projectsTheme'])) {
    $pt_theme = $_raw['projectsTheme'];
}
unset($_raw);
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pt_app_name) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="../icon.svg">
  <link id="pt-theme-link" rel="stylesheet" href="templates-projects/<?= htmlspecialchars($pt_theme, ENT_QUOTES) ?>">
</head>
<body>

  <!-- ── App header ───────────────────────────────────────────────────── -->
  <div id="pt-header" style="display:none">
    <a href="projects.php">
      <img src="../icon.svg" class="pt-header-icon" alt="">
      <?= htmlspecialchars($pt_app_name) ?>
    </a>
    <div id="pt-header-right">
      <span id="pt-user-badge"></span>
      <button class="btn" id="pt-home-btn" title="Go to main panel" aria-label="Go to main panel" onclick="window.location.href='../index.php'"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></button>
      <button class="btn" id="pt-theme-btn" title="Theme" aria-label="Theme" style="display:none" onclick="ptToggleThemePanel()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0 0 20c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
      </button>
      <button class="btn" id="pt-logout-btn" title="Sign out" aria-label="Sign out" onclick="ptLogout()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </button>
    </div>
  </div>

  <!-- ── App screen ───────────────────────────────────────────────────── -->
  <div id="pt-screen">

    <!-- ── Project selector bar (shared) ─────────────────────────────── -->
    <div id="pt-project-bar" style="display:none">
      <label for="pt-project-select">Project:</label>
      <select id="pt-project-select" onchange="ptOnProjectChange()">
        <option value="">— select —</option>
      </select>
      <span id="pt-project-dates"></span>
    </div>

    <!-- ════════════════════════════════════════════════════════════════ -->
    <!-- ADMIN PANEL                                                     -->
    <!-- ════════════════════════════════════════════════════════════════ -->
    <div id="admin-panel" style="display:none">
      <div class="pt-tabs">
        <div class="pt-tab active" data-tab="projects" onclick="ptShowTab('projects')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
          Projects
        </div>
        <div class="pt-tab" data-tab="tasks" onclick="ptShowTab('tasks')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          Tasks
        </div>
        <div class="pt-tab" data-tab="board" onclick="ptShowTab('board')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="18" rx="1"/><rect x="14" y="3" width="7" height="10" rx="1"/><rect x="14" y="17" width="7" height="4" rx="1"/></svg>
          Status Board
        </div>
        <div class="pt-tab" data-tab="burndown" onclick="ptShowTab('burndown')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Burndown
        </div>
        <div class="pt-tab" data-tab="statuses" onclick="ptShowTab('statuses')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M2 12h3M19 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/></svg>
          Statuses
        </div>
        <div class="pt-tab" data-tab="settings" onclick="ptShowTab('settings')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Settings
        </div>
      </div>

      <!-- ── Projects tab ────────────────────────────────────────────── -->
      <div id="tab-projects" class="pt-tab-panel active">
        <div class="pt-tab-toolbar">
          <button class="btn btn-primary" onclick="ptOpenProjectModal()">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New project
          </button>
          <span id="projects-status" class="pt-status" style="display:none"></span>
        </div>
        <div id="projects-table-wrap" class="pt-table-wrap">
          <div class="pt-loading"><div class="pt-spinner"></div> Loading…</div>
        </div>
      </div>

      <!-- ── Tasks tab ───────────────────────────────────────────────── -->
      <div id="tab-tasks" class="pt-tab-panel">
        <div class="pt-tab-toolbar">
          <button class="btn btn-primary" id="btn-add-root-task" onclick="ptOpenTaskModal(null, null)" disabled>
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New task
          </button>
          <div class="pt-filter-bar">
            <select id="tf-status" class="pt-filter-select" onchange="ptApplyTaskFilters()">
              <!-- populated dynamically by ptPopulateStatusSelects() -->
            </select>
            <select id="tf-priority" class="pt-filter-select" onchange="ptApplyTaskFilters()">
              <option value="">All priorities</option>
              <option value="critical">Critical</option>
              <option value="high">High</option>
              <option value="medium">Medium</option>
              <option value="low">Low</option>
            </select>
            <input id="tf-search" type="search" class="pt-filter-search" placeholder="Search tasks…" oninput="ptApplyTaskFilters()">
            <button class="btn btn-sm" onclick="ptResetTaskFilters()">Reset</button>
          </div>
          <span id="tasks-status" class="pt-status" style="display:none"></span>
        </div>
        <div id="tasks-table-wrap" class="pt-table-wrap">
          <p class="pt-empty-msg">Select a project to view its tasks.</p>
        </div>
      </div>

      <!-- ── Status Board tab ────────────────────────────────────────── -->
      <div id="tab-board" class="pt-tab-panel">
        <div id="board-wrap">
          <p class="pt-empty-msg">Select a project to view the status board.</p>
        </div>
      </div>

      <!-- ── Burndown tab ────────────────────────────────────────────── -->
      <div id="tab-burndown" class="pt-tab-panel">
        <div id="burndown-wrap">
          <p class="pt-empty-msg">Select a project to view the burndown chart.</p>
        </div>
      </div>
      <!-- ── Statuses tab ──────────────────────────────────────────────── -->
      <div id="tab-statuses" class="pt-tab-panel">
        <div class="pt-tab-toolbar">
          <button class="btn btn-primary" onclick="ptOpenStatusModal()">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New status
          </button>
          <span id="statuses-status" class="pt-status" style="display:none"></span>
        </div>
        <div id="statuses-table-wrap" class="pt-table-wrap">
          <div class="pt-loading"><div class="pt-spinner"></div> Loading…</div>
        </div>
      </div>
      <!-- ── Settings tab ────────────────────────────────────────────── -->
      <div id="tab-settings" class="pt-tab-panel">
        <h3 class="pt-section-title">Theme</h3>
        <div id="pt-theme-panel">
          <div id="pt-theme-list" class="pt-theme-grid"></div>
          <span id="settings-status" class="pt-status" style="display:none"></span>
        </div>
      </div>

    </div><!-- /admin-panel -->

    <!-- ════════════════════════════════════════════════════════════════ -->
    <!-- USER PANEL (read-only)                                          -->
    <!-- ════════════════════════════════════════════════════════════════ -->
    <div id="user-panel" style="display:none">
      <div class="pt-tabs">
        <div class="pt-tab active" data-tab="u-mytasks" onclick="ptShowUserTab('u-mytasks')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
          My Tasks
        </div>
        <div class="pt-tab" data-tab="u-alltasks" onclick="ptShowUserTab('u-alltasks')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          All Tasks
        </div>
        <div class="pt-tab" data-tab="u-burndown" onclick="ptShowUserTab('u-burndown')">
          <svg class="pt-tab-icon" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Burndown
        </div>
      </div>

      <!-- My Tasks -->
      <div id="tab-u-mytasks" class="pt-tab-panel active">
        <div id="u-mytasks-wrap">
          <p class="pt-empty-msg">Select a project to view your tasks.</p>
        </div>
      </div>

      <!-- All Tasks -->
      <div id="tab-u-alltasks" class="pt-tab-panel">
        <div id="u-alltasks-wrap">
          <p class="pt-empty-msg">Select a project to view all tasks.</p>
        </div>
      </div>

      <!-- Burndown -->
      <div id="tab-u-burndown" class="pt-tab-panel">
        <div id="u-burndown-wrap">
          <p class="pt-empty-msg">Select a project to view the burndown chart.</p>
        </div>
      </div>
    </div><!-- /user-panel -->

  </div><!-- /pt-screen -->

  <!-- ── Toast notification ────────────────────────────────────────────── -->
  <div id="pt-toast" aria-live="polite"></div>

  <!-- ════════════════════════════════════════════════════════════════════ -->
  <!-- MODALS                                                              -->
  <!-- ════════════════════════════════════════════════════════════════════ -->

  <!-- Project modal -->
  <div id="pt-project-overlay" class="pt-overlay" onclick="ptCloseProjectModal()" style="display:none"></div>
  <div id="pt-project-modal" class="pt-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="project-modal-title">
    <div class="pt-modal-header">
      <h3 id="project-modal-title">New Project</h3>
      <button class="pt-modal-close" onclick="ptCloseProjectModal()" aria-label="Close">&times;</button>
    </div>
    <div class="pt-modal-body">
      <label>Name <span class="req">*</span>
        <input type="text" id="pm-name" maxlength="200" autocomplete="off">
      </label>
      <label>Description
        <textarea id="pm-description" rows="3" maxlength="2000"></textarea>
      </label>
      <div class="pt-form-row">
        <label>Start date
          <input type="date" id="pm-start-date">
        </label>
        <label>End date
          <input type="date" id="pm-end-date">
        </label>
      </div>
      <p id="pm-error" class="pt-form-error"></p>
    </div>
    <div class="pt-modal-footer">
      <button class="btn" onclick="ptCloseProjectModal()">Cancel</button>
      <button class="btn btn-primary" onclick="ptSubmitProject()">Save</button>
    </div>
  </div>

  <!-- Task modal -->
  <div id="pt-task-overlay" class="pt-overlay" onclick="ptCloseTaskModal()" style="display:none"></div>
  <div id="pt-task-modal" class="pt-modal pt-modal-lg" style="display:none" role="dialog" aria-modal="true" aria-labelledby="task-modal-title">
    <div class="pt-modal-header">
      <h3 id="task-modal-title">New Task</h3>
      <button class="pt-modal-close" onclick="ptCloseTaskModal()" aria-label="Close">&times;</button>
    </div>
    <div class="pt-modal-body">
      <div class="pt-form-row">
        <label style="flex:2">Name <span class="req">*</span>
          <input type="text" id="tm-name" maxlength="300" autocomplete="off">
        </label>
        <label>Priority
          <select id="tm-priority">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select>
        </label>
        <label>Status
          <select id="tm-status">
            <!-- populated dynamically by ptPopulateStatusSelects() -->
          </select>
        </label>
        <label>Points
          <input type="number" id="tm-points" min="0" max="9999" value="0" style="width:80px">
        </label>
      </div>
      <label>Description
        <textarea id="tm-description" rows="2" maxlength="2000" placeholder="Short summary…"></textarea>
      </label>
      <label>Specification
        <div class="pt-spec-editor">
          <div class="pt-spec-tabs">
            <span class="pt-spec-tab active" onclick="ptSpecTab('edit')">Edit</span>
            <span class="pt-spec-tab" onclick="ptSpecTab('preview')">Preview</span>
          </div>
          <textarea id="tm-specification" rows="8" placeholder="Markdown supported…"></textarea>
          <div id="tm-spec-preview" class="pt-spec-preview" style="display:none"></div>
        </div>
      </label>
      <fieldset class="pt-assignment-fieldset">
        <legend>Assignment (admin only)</legend>
        <label>Assignees
          <div id="tm-assignees-wrap" class="pt-assignees-wrap"></div>
        </label>
        <div class="pt-form-row">
          <label>Assigned date
            <input type="date" id="tm-assigned-date">
          </label>
          <label>Integration date
            <input type="date" id="tm-integration-date">
          </label>
          <label style="flex:2">Integration branch
            <input type="text" id="tm-integration-branch" maxlength="200" placeholder="e.g. feature/login" autocomplete="off">
          </label>
        </div>
        <label class="pt-done-label">
          <input type="checkbox" id="tm-done">
          Done <span class="pt-muted" style="font-weight:normal;font-size:.85em">(auto-checked when integration date is past)</span>
        </label>
      </fieldset>
      <p id="tm-error" class="pt-form-error"></p>
    </div>
    <div class="pt-modal-footer">
      <button class="btn" onclick="ptCloseTaskModal()">Cancel</button>
      <button class="btn btn-primary" onclick="ptSubmitTask()">Save</button>
    </div>
  </div>

  <!-- Task detail modal (read-only) -->
  <div id="pt-detail-overlay" class="pt-overlay" onclick="ptCloseDetail()" style="display:none"></div>
  <div id="pt-detail-modal" class="pt-modal pt-modal-lg" style="display:none" role="dialog" aria-modal="true" aria-labelledby="detail-modal-title">
    <div class="pt-modal-header">
      <h3 id="detail-modal-title"></h3>
      <button class="pt-modal-close" onclick="ptCloseDetail()" aria-label="Close">&times;</button>
    </div>
    <div class="pt-modal-body" id="pt-detail-body"></div>
    <div class="pt-modal-footer" id="pt-detail-footer"></div>
  </div>

  <!-- Status modal -->
  <div id="pt-status-overlay" class="pt-overlay" onclick="ptCloseStatusModal()" style="display:none"></div>
  <div id="pt-status-modal" class="pt-modal pt-modal-sm" style="display:none" role="dialog" aria-modal="true" aria-labelledby="status-modal-title">
    <div class="pt-modal-header">
      <h3 id="status-modal-title">New Status</h3>
      <button class="pt-modal-close" onclick="ptCloseStatusModal()" aria-label="Close">&times;</button>
    </div>
    <div class="pt-modal-body">
      <label>Name <span class="req">*</span>
        <input type="text" id="sm-name" maxlength="100" autocomplete="off">
      </label>
      <p id="sm-error" class="pt-form-error"></p>
    </div>
    <div class="pt-modal-footer">
      <button class="btn" onclick="ptCloseStatusModal()">Cancel</button>
      <button class="btn btn-primary" onclick="ptSubmitStatus()">Save</button>
    </div>
  </div>

  <!-- Delete confirm dialog -->
  <div id="pt-confirm-overlay" class="pt-overlay" style="display:none"></div>
  <div id="pt-confirm-dialog" class="pt-modal pt-modal-sm" style="display:none" role="alertdialog" aria-modal="true">
    <div class="pt-modal-header">
      <h3>Confirm delete</h3>
    </div>
    <div class="pt-modal-body">
      <p id="pt-confirm-msg"></p>
    </div>
    <div class="pt-modal-footer">
      <button class="btn" onclick="ptCancelConfirm()">Cancel</button>
      <button class="btn btn-danger" onclick="ptDoConfirm()">Delete</button>
    </div>
  </div>

  <script src="../lib/auth-client.js?v=<?= filemtime(__DIR__ . '/../lib/auth-client.js') ?>"></script>
  <script src="../vendor/marked.min.js?v=<?= filemtime(__DIR__ . '/../vendor/marked.min.js') ?>"></script>
  <script src="projects.js?v=<?= filemtime(__DIR__ . '/projects.js') ?>"></script>
</body>
</html>
