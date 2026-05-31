<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/calendar-api.php';

// ── Read settings ─────────────────────────────────────────────────────────
$cal_app_name = 'Calendar';
$cal_theme    = 'default.css';
$_raw = data_read(SETTINGS_FILE);
if (!empty($_raw['wikiName'])) $cal_app_name = $_raw['wikiName'] . ' — Calendar';
if (
  !empty($_raw['calendarTheme']) &&
  preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['calendarTheme']) &&
  is_file(__DIR__ . '/templates-calendar/' . $_raw['calendarTheme'])
) {
  $cal_theme = $_raw['calendarTheme'];
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
  <title><?= htmlspecialchars($cal_app_name) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="../icon.svg">
  <link id="cal-theme-link" rel="stylesheet" href="templates-calendar/<?= htmlspecialchars($cal_theme, ENT_QUOTES) ?>">
</head>

<body>

  <!-- ── Header ───────────────────────────────────────────────────── -->
  <div id="cal-header" style="display:none">
    <a href="calendar.php">
      <img src="../icon.svg" class="cal-header-icon" alt="">
      <?= htmlspecialchars($cal_app_name) ?>
    </a>
    <div id="cal-header-right">
      <span id="cal-user-badge"></span>
      <button class="btn" id="cal-home-btn" title="Go to main panel" aria-label="Go to main panel" onclick="window.location.href='../index.php'"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
          <polyline points="9 22 9 12 15 12 15 22" />
        </svg></button>
      <button class="btn" id="cal-theme-btn" title="Change theme" aria-label="Change theme" style="display:none" onclick="calToggleThemePanel()">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 2a10 10 0 0 0 0 20c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z" />
        </svg>
      </button>
      <button class="btn" title="Sign out" aria-label="Sign out" onclick="calLogout()">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
          <polyline points="16 17 21 12 16 7" />
          <line x1="21" y1="12" x2="9" y2="12" />
        </svg>
      </button>
    </div>
  </div>

  <!-- ── App screen ───────────────────────────────────────────────── -->
  <div id="cal-screen" style="display:none">

    <!-- Tab bar -->
    <div class="cal-tab-bar" role="tablist">
      <button class="cal-tab active" id="cal-tab-btn-calendar" role="tab"
        aria-selected="true" aria-controls="cal-tab-calendar"
        onclick="calSwitchTab('calendar')">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <line x1="16" y1="2" x2="16" y2="6" />
          <line x1="8" y1="2" x2="8" y2="6" />
          <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
        Calendar
      </button>
      <button class="cal-tab" id="cal-tab-btn-upcoming" role="tab"
        aria-selected="false" aria-controls="cal-tab-upcoming"
        onclick="calSwitchTab('upcoming')">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <line x1="8" y1="6" x2="21" y2="6" />
          <line x1="8" y1="12" x2="21" y2="12" />
          <line x1="8" y1="18" x2="21" y2="18" />
          <circle cx="3" cy="6" r=".5" fill="currentColor" />
          <circle cx="3" cy="12" r=".5" fill="currentColor" />
          <circle cx="3" cy="18" r=".5" fill="currentColor" />
        </svg>
        Upcoming
      </button>
    </div>

    <!-- Tab: Calendar -->
    <div id="cal-tab-calendar" role="tabpanel" aria-labelledby="cal-tab-btn-calendar">

      <!-- Admin toolbar -->
      <div id="admin-toolbar" style="display:none">
        <div class="cal-toolbar">
          <div class="cal-nav">
            <button class="btn btn-sm" onclick="calPrevMonth()" aria-label="Previous month">
              <svg viewBox="0 0 24 24">
                <polyline points="15 18 9 12 15 6" />
              </svg>
            </button>
            <span id="cal-month-label"></span>
            <button class="btn btn-sm" onclick="calNextMonth()" aria-label="Next month">
              <svg viewBox="0 0 24 24">
                <polyline points="9 18 15 12 9 6" />
              </svg>
            </button>
            <button class="btn btn-sm" onclick="calGoToday()">Today</button>
          </div>
          <button class="btn btn-primary" onclick="calOpenAddModal()">
            <svg viewBox="0 0 24 24">
              <line x1="12" y1="5" x2="12" y2="19" />
              <line x1="5" y1="12" x2="19" y2="12" />
            </svg>
            Add event
          </button>
        </div>
        <div id="cal-grid-admin" class="cal-grid-wrap">
          <div class="cal-loading">
            <div class="cal-spinner"></div> Loading…
          </div>
        </div>
      </div>

      <!-- User toolbar (read-only) -->
      <div id="user-toolbar" style="display:none">
        <div class="cal-toolbar">
          <div class="cal-nav">
            <button class="btn btn-sm" onclick="calPrevMonth()" aria-label="Previous month">
              <svg viewBox="0 0 24 24">
                <polyline points="15 18 9 12 15 6" />
              </svg>
            </button>
            <span id="cal-month-label-user"></span>
            <button class="btn btn-sm" onclick="calNextMonth()" aria-label="Next month">
              <svg viewBox="0 0 24 24">
                <polyline points="9 18 15 12 9 6" />
              </svg>
            </button>
            <button class="btn btn-sm" onclick="calGoToday()">Today</button>
          </div>
        </div>
        <div id="cal-grid-user" class="cal-grid-wrap">
          <div class="cal-loading">
            <div class="cal-spinner"></div> Loading…
          </div>
        </div>
      </div>

    </div><!-- #cal-tab-calendar -->

    <!-- Tab: Upcoming (next 20 events) -->
    <div id="cal-tab-upcoming" role="tabpanel" aria-labelledby="cal-tab-btn-upcoming" style="display:none">
      <div id="cal-upcoming-list">
        <div class="cal-loading">
          <div class="cal-spinner"></div> Loading…
        </div>
      </div>
    </div>

  </div><!-- #cal-screen -->

  <!-- ── Event form modal (admin only) ────────────────────────────── -->
  <div id="cal-form-overlay" class="cal-overlay" onclick="calCloseFormModal()"></div>
  <div id="cal-form-modal" class="cal-modal" role="dialog" aria-modal="true" aria-labelledby="cal-form-title">
    <h3 id="cal-form-title">Add event <button class="close-btn" onclick="calCloseFormModal()" aria-label="Close">&times;</button></h3>
    <form id="cal-event-form" onsubmit="calSaveEvent(event)">
      <label>Title <span class="cal-required">*</span>
        <input type="text" id="cal-f-title" maxlength="128" required autocomplete="off">
      </label>
      <label>Date <span class="cal-required">*</span>
        <input type="date" id="cal-f-date" required>
      </label>
      <label>End date <span class="cal-optional">(optional — for multi-day events)</span>
        <input type="date" id="cal-f-end-date">
      </label>
      <div class="cal-form-row">
        <label>Start time <span class="cal-optional">(optional)</span>
          <input type="time" id="cal-f-time">
        </label>
        <label>End time <span class="cal-optional">(optional)</span>
          <input type="time" id="cal-f-end-time">
        </label>
      </div>
      <label>Color
        <div id="cal-color-picker" class="cal-color-picker"></div>
        <input type="hidden" id="cal-f-color" value="">
      </label>
      <label>Description <span class="cal-optional">(optional)</span>
        <textarea id="cal-f-description" rows="3" maxlength="2000"></textarea>
      </label>
      <div id="cal-form-status" class="cal-status" style="display:none"></div>
      <div class="cal-form-actions">
        <button type="button" id="cal-form-delete-btn" class="btn btn-danger" style="display:none" onclick="calDeleteEventFromModal()">
          <svg viewBox="0 0 24 24">
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
          </svg>
          Delete
        </button>
        <div style="flex:1"></div>
        <button type="button" class="btn" onclick="calCloseFormModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="cal-form-save-btn">Save</button>
      </div>
    </form>
  </div>

  <!-- ── Event detail modal (all users) ───────────────────────────── -->
  <div id="cal-detail-overlay" class="cal-overlay" onclick="calCloseDetailModal()"></div>
  <div id="cal-detail-modal" class="cal-modal" role="dialog" aria-modal="true" aria-labelledby="cal-detail-title">
    <div id="cal-detail-color-bar" class="cal-detail-color-bar"></div>
    <h3 id="cal-detail-title">Event <button class="close-btn" onclick="calCloseDetailModal()" aria-label="Close">&times;</button></h3>
    <div id="cal-detail-body"></div>
    <div class="cal-form-actions">
      <button type="button" id="cal-detail-edit-btn" class="btn btn-primary" style="display:none" onclick="calEditFromDetail()">
        <svg viewBox="0 0 24 24">
          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
          <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
        </svg>
        Edit
      </button>
      <div style="flex:1"></div>
      <button type="button" class="btn" onclick="calCloseDetailModal()">Close</button>
    </div>
  </div>

  <!-- ── Theme panel ──────────────────────────────────────────────── -->
  <div id="cal-theme-overlay" onclick="calToggleThemePanel()"></div>
  <div id="cal-theme-panel">
    <h3>Theme <button class="close-btn" onclick="calToggleThemePanel()" aria-label="Close">&times;</button></h3>
    <label>Calendar theme
      <select id="cal-theme-select"></select>
    </label>
    <div id="cal-theme-panel-actions">
      <button type="button" class="btn" onclick="calToggleThemePanel()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="calSaveTheme()">Save</button>
    </div>
  </div>

  <div id="cal-toast"></div>

  <script src="../lib/auth-client.js?v=<?= filemtime(__DIR__ . '/../lib/auth-client.js') ?>"></script>
  <script src="calendar.js?v=<?= filemtime(__DIR__ . '/calendar.js') ?>"></script>
</body>

</html>