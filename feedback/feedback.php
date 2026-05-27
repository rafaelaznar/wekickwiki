<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/feedback-api.php';

// ── Theme & app name ─────────────────────────────────────────────────────────
$fb_theme    = 'default.css';
$fb_app_name = 'Feedback';
$_raw = data_read(SETTINGS_FILE);
if (!empty($_raw['wikiName'])) $fb_app_name = $_raw['wikiName'] . ' — Feedback';
if (!empty($_raw['feedbackTheme']) &&
    preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['feedbackTheme']) &&
    is_file(__DIR__ . '/templates-feedback/' . $_raw['feedbackTheme'])) {
    $fb_theme = $_raw['feedbackTheme'];
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
  <title><?= htmlspecialchars($fb_app_name) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="../icon.svg">
  <link id="fb-theme-link" rel="stylesheet" href="templates-feedback/<?= htmlspecialchars($fb_theme, ENT_QUOTES) ?>">
</head>
<body>

  <!-- ── App header ───────────────────────────────────────────────────── -->
  <div id="fb-header" style="display:none">
    <a href="feedback.php">
      <img src="../icon.svg" class="fb-header-icon" alt="">
      <?= htmlspecialchars($fb_app_name) ?>
    </a>
    <div id="fb-header-right">
      <span id="fb-user-badge"></span>
      <button class="btn" id="fb-home-btn" title="Go to main panel" aria-label="Go to main panel" onclick="window.location.href='../index.php'"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></button>
      <button class="btn" id="fb-theme-btn" title="Theme" aria-label="Theme" style="display:none" onclick="fbToggleThemePanel()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0 0 20c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
      </button>
      <button class="btn" id="fb-logout-btn" title="Sign out" aria-label="Sign out" onclick="fbLogout()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </button>
    </div>
  </div>

  <!-- ── App screen ───────────────────────────────────────────────────── -->
  <div id="fb-screen">

    <!-- ADMIN PANEL -->
    <div id="admin-panel" style="display:none">
      <div class="fb-tabs">
        <div class="fb-tab active" data-tab="events" onclick="fbShowTab('events')">
          <svg class="fb-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          Events
        </div>
        <div class="fb-tab" data-tab="settings" onclick="fbShowTab('settings')">
          <svg class="fb-tab-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Settings
        </div>
      </div>

      <!-- Events tab -->
      <div id="tab-events" class="fb-tab-panel active">
        <div class="fb-tab-toolbar">
          <button class="btn btn-primary" onclick="fbOpenEventModal()">
            <svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add event
          </button>
          <span id="events-status" class="fb-status" style="display:none"></span>
        </div>
        <div id="events-table-wrap" class="fb-table-wrap">
          <div class="fb-loading"><div class="fb-spinner"></div> Loading…</div>
        </div>
      </div>

      <!-- Settings tab -->
      <div id="tab-settings" class="fb-tab-panel">
        <div class="fb-settings-section">
          <h3>Theme</h3>
          <div id="fb-theme-list" class="fb-theme-grid">
            <div class="fb-loading"><div class="fb-spinner"></div> Loading…</div>
          </div>
          <span id="settings-status" class="fb-status" style="display:none"></span>
        </div>
      </div>
    </div><!-- /admin-panel -->

    <!-- USER PANEL -->
    <div id="user-panel" style="display:none">
      <div id="user-events-wrap">
        <div class="fb-loading"><div class="fb-spinner"></div> Loading…</div>
      </div>
    </div><!-- /user-panel -->

  </div><!-- /fb-screen -->

  <!-- ── Theme panel (header quick-switcher, admin only) ──────────────── -->
  <div id="fb-theme-panel" class="fb-theme-panel" style="display:none">
    <div id="fb-theme-panel-list"></div>
  </div>

  <!-- ── Event modal (admin create / edit) ────────────────────────────── -->
  <div id="fb-event-overlay" class="fb-overlay" onclick="fbCloseEventModal()"></div>
  <div id="fb-event-modal" class="fb-modal" style="display:none">
    <button class="fb-modal-close" aria-label="Close" onclick="fbCloseEventModal()">&times;</button>
    <h3 id="fb-event-modal-title">Add event</h3>
    <div class="fb-field">
      <label for="fb-ev-title">Title <span class="fb-required">*</span></label>
      <input type="text" id="fb-ev-title" maxlength="120" placeholder="Event title">
    </div>
    <div class="fb-field">
      <label for="fb-ev-desc">Description</label>
      <textarea id="fb-ev-desc" rows="3" placeholder="Optional description…"></textarea>
    </div>
    <div class="fb-field-row">
      <div class="fb-field">
        <label for="fb-ev-type">Type <span class="fb-required">*</span></label>
        <select id="fb-ev-type">
          <option value="open">Open — text opinion</option>
          <option value="closed">Closed — score 0–10</option>
          <option value="mixed">Mixed — text + score</option>
        </select>
      </div>
      <div class="fb-field">
        <label for="fb-ev-status">Status</label>
        <select id="fb-ev-status">
          <option value="open">Open</option>
          <option value="closed">Closed</option>
        </select>
      </div>
    </div>
    <div class="fb-field fb-field-checkbox">
      <label>
        <input type="checkbox" id="fb-ev-anonymous">
        Collect responses anonymously
      </label>
      <p class="fb-field-hint">When enabled, usernames are hidden from responses view. Responses are still stored to prevent duplicates.</p>
    </div>
    <div class="fb-modal-actions">
      <button class="btn" onclick="fbCloseEventModal()">Cancel</button>
      <button class="btn btn-primary" onclick="fbSaveEvent()">Save</button>
    </div>
    <div id="fb-event-modal-status" class="fb-status" style="display:none"></div>
  </div>

  <!-- ── Responses modal (admin view) ─────────────────────────────────── -->
  <div id="fb-resp-overlay" class="fb-overlay" onclick="fbCloseResponsesModal()"></div>
  <div id="fb-resp-modal" class="fb-modal fb-modal-wide" style="display:none">
    <button class="fb-modal-close" aria-label="Close" onclick="fbCloseResponsesModal()">&times;</button>
    <h3 id="fb-resp-modal-title">Responses</h3>
    <div id="fb-resp-modal-stats" class="fb-resp-stats"></div>
    <div id="fb-resp-table-wrap" class="fb-table-wrap"></div>
  </div>

  <!-- ── Respond modal (user) ─────────────────────────────────────────── -->
  <div id="fb-respond-overlay" class="fb-overlay" onclick="fbCloseRespondModal()"></div>
  <div id="fb-respond-modal" class="fb-modal" style="display:none">
    <button class="fb-modal-close" aria-label="Close" onclick="fbCloseRespondModal()">&times;</button>
    <h3 id="fb-respond-modal-title">Submit feedback</h3>
    <p id="fb-respond-modal-desc" class="fb-modal-desc" style="display:none"></p>
    <div id="fb-respond-text-field" class="fb-field" style="display:none">
      <label for="fb-respond-text">Your opinion</label>
      <textarea id="fb-respond-text" rows="4" placeholder="Share your thoughts…"></textarea>
    </div>
    <div id="fb-respond-score-field" class="fb-field" style="display:none">
      <label for="fb-respond-score">Score (0 – 10)</label>
      <div class="fb-score-wrap">
        <input type="range"  id="fb-respond-score-range" min="0" max="10" step="1" value="5"
               oninput="fbSyncScore(this.value)">
        <input type="number" id="fb-respond-score"       min="0" max="10" step="1" value="5"
               oninput="fbSyncScore(this.value)">
      </div>
    </div>
    <div class="fb-modal-actions">
      <button class="btn" onclick="fbCloseRespondModal()">Cancel</button>
      <button class="btn btn-primary" onclick="fbSubmitResponse()">Send</button>
    </div>
    <div id="fb-respond-status" class="fb-status" style="display:none"></div>
  </div>

  <!-- ── Delete confirm modal ──────────────────────────────────────────── -->
  <div id="fb-del-overlay" class="fb-overlay" onclick="fbCloseDeleteModal()"></div>
  <div id="fb-del-modal" class="fb-modal fb-modal-sm" style="display:none">
    <h3>Delete event?</h3>
    <p id="fb-del-modal-msg" class="fb-modal-desc"></p>
    <div class="fb-modal-actions">
      <button class="btn" onclick="fbCloseDeleteModal()">Cancel</button>
      <button class="btn btn-danger" onclick="fbConfirmDelete()">Delete</button>
    </div>
  </div>

  <!-- ── Toast ─────────────────────────────────────────────────────────── -->
  <div id="fb-toast" class="fb-toast"></div>

  <script src="../lib/auth-client.js"></script>
  <script src="feedback.js"></script>
</body>
</html>
