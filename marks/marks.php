<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/marks-api.php';

// ═══════════════════════════════════════════════════════════════════════════
// Render HTML — load settings for app name display
// ═══════════════════════════════════════════════════════════════════════════
$pq_settings = load_auth_settings();
$pq_app_name = 'Qualifications';
// Try to get the wikiName from settings if available (re-read raw settings.json)
$pq_theme = 'default.css';
$_raw = data_read(SETTINGS_FILE);
if (!empty($_raw['wikiName'])) $pq_app_name = $_raw['wikiName'] . ' — Qualifications';
if (!empty($_raw['pqTheme']) &&
    preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['pqTheme']) &&
    is_file(__DIR__ . '/templates-marks/' . $_raw['pqTheme'])) {
    $pq_theme = $_raw['pqTheme'];
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
  <title><?= htmlspecialchars($pq_app_name) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="../icon.svg">
  <link id="pq-theme-link" rel="stylesheet" href="templates-marks/<?= htmlspecialchars($pq_theme, ENT_QUOTES) ?>">
</head>
<body>

  <!-- ── Login screen (exact replica of index.php) ────────────────── -->
  <!-- ── App screen ────────────────────────────────────────────────── -->
  <div id="pq-header" style="display:none">
    <a href="marks.php">
      <img src="../icon.svg" class="pq-header-icon" alt="">
      <?= htmlspecialchars($pq_app_name) ?>
    </a>
    <div id="pq-header-right">
      <span id="pq-user-badge"></span>
      <button class="btn" id="pq-home-btn" title="Go to main panel" aria-label="Go to main panel" onclick="window.location.href='../index.php'"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></button>
      <button class="btn" id="pq-theme-btn" title="Change theme" aria-label="Change theme" style="display:none" onclick="pqToggleThemePanel()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0 0 20c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
      </button>
      <button class="btn" id="pq-logout-btn" title="Sign out" aria-label="Sign out" onclick="pqLogout()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </button>
    </div>
  </div>

  <div id="pq-screen">

    <!-- Admin panel -->
    <div id="admin-panel" style="display:none">
      <div class="pq-tabs">
        <div class="pq-tab active" onclick="pqShowTab('structure')">
          <svg class="pq-tab-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="17" x2="21" y2="17"/><line x1="17" y1="14" x2="17" y2="21"/></svg>
          Structure
        </div>
        <div class="pq-tab" onclick="pqShowTab('marks')">
          <svg class="pq-tab-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Marks
        </div>
      </div>

      <!-- ── Structure tab ───────────────────────────────────────── -->
      <div id="tab-structure" class="pq-tab-panel active">
        <p class="pq-section-intro">
          Build the qualification hierarchy. Each group of sub-items must have weights that sum exactly to <strong>100</strong>.
        </p>
        <label class="pq-structure-label">Course / root name</label>
        <input id="structure-root-name" type="text" maxlength="128" placeholder="e.g. Cooking Course">
        <div id="structure-tree-wrap">
          <!-- rendered by JS -->
        </div>
        <div id="structure-errors">
          <strong>Weight errors — please correct before saving:</strong>
          <ul id="structure-errors-list"></ul>
        </div>
        <div id="structure-action-bar">
          <button class="btn btn-primary" onclick="pqSaveStructure()">
            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save structure
          </button>
          <button class="btn" onclick="pqLoadStructure()">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.36"/></svg>
            Reload
          </button>
          <span id="structure-status" class="pq-status" style="display:none"></span>
        </div>
      </div>

      <!-- ── Marks tab ───────────────────────────────────────────── -->
      <div id="tab-marks" class="pq-tab-panel">
        <p class="pq-section-intro">
          Enter the grade (0–10) for each student in each leaf item. Leave blank if not yet graded.
        </p>
        <div id="marks-table-wrap">
          <div class="pq-loading"><div class="pq-spinner"></div> Loading…</div>
        </div>
        <div id="marks-action-bar">
          <button class="btn btn-primary" onclick="pqSaveAllMarks()">
            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save all marks
          </button>
          <button class="btn" onclick="pqLoadMarksTab()">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.36"/></svg>
            Reload
          </button>
          <span id="marks-status" class="pq-status" style="display:none"></span>
        </div>
      </div>
    </div>

    <!-- Student grade view -->
    <div id="pq-grade-view" style="display:none">
      <div class="pq-loading"><div class="pq-spinner"></div> Loading your grades…</div>
    </div>

  </div><!-- #pq-screen -->

  <div id="pq-theme-overlay" onclick="pqToggleThemePanel()"></div>
  <div id="pq-theme-panel">
    <h3>Theme <button class="close-btn" onclick="pqToggleThemePanel()" aria-label="Close">&times;</button></h3>
    <label>Marks theme
      <select id="pq-theme-select"></select>
    </label>
    <div id="pq-theme-panel-actions">
      <button type="button" class="btn" onclick="pqToggleThemePanel()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="pqSaveTheme()">Save</button>
    </div>
  </div>

  <div id="pq-toast"></div>


  <script src="../lib/auth-client.js?v=<?= filemtime(__DIR__ . '/../lib/auth-client.js') ?>"></script>
  <script src="marks.js?v=<?= filemtime(__DIR__ . '/marks.js') ?>"></script>
</body>
</html>