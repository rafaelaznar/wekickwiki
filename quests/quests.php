<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/quests-api.php';

// ═══════════════════════════════════════════════════════════════════════════
// ── HTML RENDERING ──────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
$qs_theme    = 'default.css';
$qs_app_name = 'Quests';
$_raw = data_read(SETTINGS_FILE);
if (!empty($_raw['wikiName'])) $qs_app_name = $_raw['wikiName'] . ' — Quests';
if (!empty($_raw['questsTheme']) &&
    preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['questsTheme']) &&
    is_file(__DIR__ . '/templates-quests/' . $_raw['questsTheme'])) {
    $qs_theme = $_raw['questsTheme'];
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
  <title><?= htmlspecialchars($qs_app_name) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="../icon.svg">
  <link id="qs-theme-link" rel="stylesheet" href="templates-quests/<?= htmlspecialchars($qs_theme, ENT_QUOTES) ?>">
</head>
<body>

  <!-- ── App header ───────────────────────────────────────────────────── -->
  <div id="qs-header" style="display:none">
    <a href="quests.php">
      <img src="../icon.svg" class="qs-header-icon" alt="">
      <?= htmlspecialchars($qs_app_name) ?>
    </a>
    <div id="qs-header-right">
      <span id="qs-user-badge"></span>
      <button class="btn" id="qs-home-btn" title="Go to main panel" aria-label="Go to main panel" onclick="window.location.href='../index.php'"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></button>
      <button class="btn" id="qs-theme-btn" title="Theme" aria-label="Theme" style="display:none" onclick="qsToggleThemePanel()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0 0 20c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
      </button>
      <button class="btn" id="qs-logout-btn" title="Sign out" aria-label="Sign out" onclick="qsLogout()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </button>
    </div>
  </div>

  <!-- ── App screen ───────────────────────────────────────────────────── -->
  <div id="qs-screen">

    <!-- ADMIN PANEL -->
    <div id="admin-panel" style="display:none">
      <div class="qs-tabs">
        <div class="qs-tab active" data-tab="questions" onclick="qsShowTab('questions')">
          <svg class="qs-tab-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          Questions
        </div>
        <div class="qs-tab" data-tab="quests" onclick="qsShowTab('quests')">
          <svg class="qs-tab-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          Quests
        </div>
        <div class="qs-tab" data-tab="results" onclick="qsShowTab('results')">
          <svg class="qs-tab-icon" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          Results
        </div>
      </div>

      <!-- Questions tab -->
      <div id="tab-questions" class="qs-tab-panel active">
        <div class="qs-tab-toolbar qs-tab-toolbar-sm">
          <button class="btn btn-primary" onclick="qsOpenQueryModal()">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add question
          </button>
          <button class="btn" type="button" onclick="qsPickMoodleXmlFile()">
            <svg viewBox="0 0 24 24"><path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M5 21h14"/></svg>
            Import Moodle XML
          </button>
          <input id="qm-xml-file" type="file" accept=".xml,text/xml,application/xml" onchange="qsHandleMoodleXmlFile(this)" style="display:none">
          <span id="queries-status" class="qs-status" style="display:none"></span>
        </div>
        <!-- Filter bar -->
        <div class="qs-filter-bar">
          <input id="qf-search" type="search" placeholder="Search question text…"
            class="qs-filter-search"
            oninput="qsApplyQueryFilters()">
          <select id="qf-type" class="qs-filter-select"
            onchange="qsApplyQueryFilters()">
            <option value="">All types</option>
            <option value="multiple_choice">Multiple choice</option>
            <option value="binary">Binary</option>
            <option value="gap_filling">Gap filling</option>
            <option value="matching">Matching</option>
          </select>
          <input id="qf-label" type="search" list="qf-label-list" placeholder="Filter by label…"
            class="qs-filter-label-input"
            oninput="qsApplyQueryFilters()">
          <datalist id="qf-label-list"></datalist>
          <button class="btn btn-sm" type="button" onclick="qsResetQueryFilters()">Reset</button>
          <span id="qf-count" class="qs-filter-count"></span>
        </div>
        <div id="queries-table-wrap" class="qs-table-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
      </div>

      <!-- Quests tab -->
      <div id="tab-quests" class="qs-tab-panel">
        <div class="qs-tab-toolbar">
          <button class="btn btn-primary" onclick="qsOpenQuestModal()">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add quest
          </button>
          <span id="quests-admin-status" class="qs-status" style="display:none"></span>
        </div>
        <div class="qs-filter-bar">
          <select id="qsf-status" class="qs-filter-select" onchange="qsApplyQuestFilters()">
            <option value="">All status</option>
            <option value="open">Open</option>
            <option value="closed">Closed</option>
          </select>
          <select id="qsf-attempts" class="qs-filter-select" onchange="qsApplyQuestFilters()">
            <option value="">All attempts</option>
            <option value="0">Attempts = 0</option>
            <option value="gt0">Attempts &gt; 0</option>
          </select>
          <select id="qsf-avgscore" class="qs-filter-select" onchange="qsApplyQuestFilters()">
            <option value="">All avg scores</option>
            <option value="lt5">Avg score &lt; 5</option>
            <option value="ge5">Avg score ≥ 5</option>
          </select>
          <select id="qsf-month" class="qs-filter-select" onchange="qsApplyQuestFilters()">
            <option value="">All months</option>
          </select>
          <select id="qsf-year" class="qs-filter-select" onchange="qsApplyQuestFilters()">
            <option value="">All years</option>
          </select>
          <button class="btn btn-sm" type="button" onclick="qsResetQuestFilters()">Reset</button>
          <span id="qsf-count" class="qs-filter-count"></span>
        </div>
        <div id="quests-admin-table-wrap" class="qs-table-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
      </div>

      <!-- Results tab -->
      <div id="tab-results" class="qs-tab-panel">
        <div class="qs-tab-toolbar">
          <button class="btn" onclick="qsLoadResults()">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.36"/></svg>
            Reload
          </button>
        </div>
        <div class="qs-filter-bar">
          <select id="rf-quest" class="qs-filter-select" onchange="qsApplyResultsFilters()">
            <option value="">All quests</option>
          </select>
          <select id="rf-user" class="qs-filter-select" onchange="qsApplyResultsFilters()">
            <option value="">All users</option>
          </select>
          <select id="rf-month" class="qs-filter-select" onchange="qsApplyResultsFilters()">
            <option value="">All months</option>
          </select>
          <select id="rf-year" class="qs-filter-select" onchange="qsApplyResultsFilters()">
            <option value="">All years</option>
          </select>
          <select id="rf-score" class="qs-filter-select" onchange="qsApplyResultsFilters()">
            <option value="">All scores</option>
            <option value="lt5">Score &lt; 5</option>
            <option value="ge5">Score ≥ 5</option>
          </select>
          <button class="btn btn-sm" type="button" onclick="qsResetResultsFilters()">Reset</button>
          <span id="rf-count" class="qs-filter-count"></span>
        </div>
        <div id="results-table-wrap" class="qs-table-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
      </div>
    </div><!-- #admin-panel -->

    <!-- USER PANEL -->
    <div id="user-panel" style="display:none">
      <!-- Available quests -->
      <div id="user-quest-list">
        <h2>Available quests</h2>
        <div id="open-quests-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
        <h2>My completed quests</h2>
        <div id="my-attempts-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
      </div>

      <!-- Wizard (hidden until quest started) -->
      <div id="user-wizard" style="display:none"></div>

      <!-- Review view -->
      <div id="user-review" style="display:none"></div>

      <!-- Score result screen -->
      <div id="user-result" style="display:none"></div>

    </div><!-- #user-panel -->

  </div><!-- #qs-screen -->

  <!-- ── Modals ────────────────────────────────────────────────────────── -->

  <!-- Query modal -->
  <div class="qs-overlay" id="query-modal-overlay" onclick="e => { if(e.target===this) qsCloseQueryModal(); }">
    <div class="qs-modal" onclick="event.stopPropagation()">
      <button class="qs-modal-close" onclick="qsCloseQueryModal()" aria-label="Close">&times;</button>
      <h3 id="query-modal-title">Add question</h3>
      <div class="qs-field">
        <label>Type</label>
        <select id="qm-type" onchange="qsRenderQueryTypeFields()">
          <option value="multiple_choice">Multiple choice</option>
          <option value="binary">Binary (Yes/No)</option>
          <option value="gap_filling">Gap filling (open answer)</option>
          <option value="matching">Matching</option>
        </select>
      </div>
      <div class="qs-field">
        <label>Question text</label>
        <textarea id="qm-query" placeholder="Enter the question…"></textarea>
      </div>
      <div class="qs-field">
        <label>Labels <span class="qs-label-hint">(comma-separated)</span></label>
        <input type="text" id="qm-labels" placeholder="e.g. mathematics, algebra">
      </div>
      <!-- Type-specific fields rendered here -->
      <div id="qm-type-fields"></div>
      <div class="qs-modal-actions">
        <button class="btn" onclick="qsCloseQueryModal()">Cancel</button>
        <button class="btn btn-primary" onclick="qsSaveQuery()">Save</button>
      </div>
    </div>
  </div>

  <!-- Quest modal -->
  <div class="qs-overlay" id="quest-modal-overlay" onclick="e => { if(e.target===this) qsCloseQuestModal(); }">
    <div class="qs-modal" onclick="event.stopPropagation()">
      <button class="qs-modal-close" onclick="qsCloseQuestModal()" aria-label="Close">&times;</button>
      <h3 id="quest-modal-title">Add quest</h3>
      <div class="qs-field">
        <label>Name</label>
        <input type="text" id="qst-name" placeholder="e.g. First semester final quest" maxlength="256">
      </div>
      <div class="qs-field-row">
        <div class="qs-field">
          <label>Date</label>
          <input type="date" id="qst-date">
        </div>
        <div class="qs-field">
          <label>Status</label>
          <select id="qst-status">
            <option value="open">Open</option>
            <option value="closed">Closed</option>
          </select>
        </div>
      </div>
      <div class="qs-field-row">
        <div class="qs-field">
          <label>Wrong answer penalty <span class="qs-label-hint">(0 = none, -0.25 = ¼ deduction)</span></label>
          <input type="number" id="qst-wrong" step="0.01" min="-1" max="0" value="0" placeholder="-0.25">
        </div>
        <div class="qs-field qs-field-shrink">
          <label>Revisable</label>
          <select id="qst-revisable">
            <option value="1">Yes</option>
            <option value="0">No</option>
          </select>
        </div>
      </div>
      <div class="qs-field">
        <label>Question groups <span class="qs-label-hint">(labels AND-matched, random pick)</span></label>
        <div id="qst-label-groups" class="qs-label-groups"></div>
        <button class="btn btn-sm qs-add-group-btn" onclick="qsAddLabelGroup()">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add group
        </button>
      </div>
      <div class="qs-field">
        <label>Allowed users</label>
        <div id="qst-allowed-wrap"><div class="qs-loading"><div class="qs-spinner"></div> Loading users…</div></div>
      </div>
      <div class="qs-modal-actions">
        <button class="btn" onclick="qsCloseQuestModal()">Cancel</button>
        <button class="btn btn-primary" onclick="qsSaveQuest()">Save</button>
      </div>
    </div>
  </div>

  <!-- Access modal —— manage quest allowed field independently -->
  <div class="qs-overlay" id="access-modal-overlay">
    <div class="qs-modal qs-modal-sm" onclick="event.stopPropagation()">
      <button class="qs-modal-close" onclick="qsCloseAccessModal()" aria-label="Close">&times;</button>
      <h3 id="access-modal-title">Manage access</h3>
      <div id="access-modal-content">
        <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
      </div>
      <div class="qs-modal-actions">
        <button class="btn" onclick="qsCloseAccessModal()">Cancel</button>
        <button class="btn btn-primary" onclick="qsSaveAccess()">Save</button>
      </div>
    </div>
  </div>

  <!-- Delete confirm modal -->
  <div class="qs-overlay" id="delete-modal-overlay">
    <div class="qs-modal qs-modal-sm" onclick="event.stopPropagation()">
      <h3 id="delete-modal-title">Confirm delete</h3>
      <p id="delete-modal-msg" class="qs-delete-msg"></p>
      <div class="qs-modal-actions">
        <button class="btn" onclick="qsCloseDeleteModal()">Cancel</button>
        <button class="btn btn-danger" id="delete-modal-confirm">Delete</button>
      </div>
    </div>
  </div>

  <!-- Theme panel -->
  <div id="qs-theme-overlay" onclick="qsToggleThemePanel()"></div>
  <div id="qs-theme-panel">
    <h3>Theme <button class="close-btn" onclick="qsToggleThemePanel()" aria-label="Close">&times;</button></h3>
    <label>Quests theme
      <select id="qs-theme-select"></select>
    </label>
    <div id="qs-theme-panel-actions">
      <button class="btn" onclick="qsToggleThemePanel()">Cancel</button>
      <button class="btn btn-primary" onclick="qsSaveTheme()">Save</button>
    </div>
  </div>

  <div id="qs-toast"></div>


  <script src="../lib/auth-client.js?v=<?= filemtime(__DIR__ . '/../lib/auth-client.js') ?>"></script>
  <script src="quests.js?v=<?= filemtime(__DIR__ . '/quests.js') ?>"></script>
</body>
</html>