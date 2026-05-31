// ═══════════════════════════════════════════════════════════════════════════
// calendar.js — Calendar module client
// ═══════════════════════════════════════════════════════════════════════════

// ── State ──────────────────────────────────────────────────────────────────
let calEvents = [];
let calYear = new Date().getFullYear();
let calMonth = new Date().getMonth(); // 0-based
let calEditingId = null; // null = adding new, integer = editing existing
let _calSelectedColor = null;

// ── Toast ──────────────────────────────────────────────────────────────────
let _calToastTimer;
/**
 * Show a temporary toast notification.
 * @param {string} msg  - Message text
 * @param {string} [type='success'] - CSS modifier ('success' | 'error')
 * @param {number} [ms=3200]  - Duration in ms before auto-dismiss
 */ function calToast(msg, type = "success", ms = 3200) {
  const el = document.getElementById("cal-toast");
  el.textContent = msg;
  el.className = "show " + type;
  clearTimeout(_calToastTimer);
  _calToastTimer = setTimeout(() => el.classList.remove("show"), ms);
}

/**
 * Set or clear an inline status message element.
 * @param {string} id   - Element ID
 * @param {string} msg  - Message text (empty to hide)
 * @param {string} type - CSS class suffix ('ok' | 'error' | 'info')
 */
function calSetStatus(id, msg, type) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.className = "cal-status " + type;
  el.style.display = msg ? "" : "none";
}

// ── Auth / routing ─────────────────────────────────────────────────────────
setOnUnauthorized(calLogout);

/** Clear session storage and redirect to the hub. */
function calLogout() {
  sessionStorage.clear();
  window.location.href = "../index.php";
}

/** Show the app chrome (header, screen) after successful auth. */
function calShowApp() {
  document.getElementById("cal-header").style.display = "flex";
  document.getElementById("cal-screen").style.display = "block";
  document.getElementById("cal-user-badge").textContent =
    getUser() + " (" + getRole() + ")";
}

/**
 * Set up role-specific toolbars and load events.
 * Admin sees the add-event toolbar; guests see a read-only toolbar.
 */
function calRoute() {
  const role = getRole();
  const themeBtn = document.getElementById("cal-theme-btn");
  if (themeBtn) themeBtn.style.display = role === "admin" ? "" : "none";
  if (role === "admin") {
    document.getElementById("admin-toolbar").style.display = "";
    document.getElementById("user-toolbar").style.display = "none";
  } else {
    document.getElementById("admin-toolbar").style.display = "none";
    document.getElementById("user-toolbar").style.display = "";
  }
  calLoadEvents();
}

// ── Tab switching ──────────────────────────────────────────────────────────
/**
 * Switch between the calendar grid tab and the upcoming events list tab.
 * @param {'calendar'|'upcoming'} tab
 */
function calSwitchTab(tab) {
  const isCalendar = tab === "calendar";
  document.getElementById("cal-tab-calendar").style.display = isCalendar
    ? ""
    : "none";
  document.getElementById("cal-tab-upcoming").style.display = isCalendar
    ? "none"
    : "";
  const btnCal = document.getElementById("cal-tab-btn-calendar");
  const btnUp = document.getElementById("cal-tab-btn-upcoming");
  btnCal.classList.toggle("active", isCalendar);
  btnCal.setAttribute("aria-selected", isCalendar);
  btnUp.classList.toggle("active", !isCalendar);
  btnUp.setAttribute("aria-selected", !isCalendar);
}

// Auto-restore session
if (getToken()) {
  calShowApp();
  calRoute();
} else {
  window.location.href = "../index.php";
}

// ── Load events ────────────────────────────────────────────────────────────
/**
 * Fetch events from the server and refresh both the grid and the upcoming list.
 */
async function calLoadEvents() {
  try {
    const res = await apiFetch("calendar.php?action=get-events");
    if (!res.ok) throw new Error("Failed to load events");
    calEvents = await res.json();
    calRender();
    calRenderUpcoming();
  } catch (err) {
    const gridId = getRole() === "admin" ? "cal-grid-admin" : "cal-grid-user";
    document.getElementById(gridId).innerHTML =
      '<p class="cal-load-error">' + err.message + "</p>";
  }
}

// ── Upcoming events list ──────────────────────────────────────────────────
/**
 * Render the upcoming events list (next 20 events from today, sorted by date/time).
 * Clicking an item opens its detail modal.
 */
function calRenderUpcoming() {
  const wrap = document.getElementById("cal-upcoming-list");
  if (!wrap) return;
  const today = new Date();
  const todayStr = _calDateStr(
    today.getFullYear(),
    today.getMonth(),
    today.getDate(),
  );

  const upcoming = calEvents
    .filter((ev) => (ev.end_date || ev.date) >= todayStr)
    .sort((a, b) => {
      const d = a.date.localeCompare(b.date);
      return d !== 0 ? d : (a.time || "").localeCompare(b.time || "");
    })
    .slice(0, 20);

  if (upcoming.length === 0) {
    wrap.innerHTML = '<p class="cal-upcoming-empty">No upcoming events.</p>';
    return;
  }

  const ul = document.createElement("ul");
  ul.className = "cal-upcoming-list";

  upcoming.forEach((ev) => {
    const li = document.createElement("li");
    li.className = "cal-upcoming-item";
    li.addEventListener("click", () => calOpenDetailModal(ev.id));

    const dot = document.createElement("span");
    dot.className = "cal-upcoming-dot";
    if (ev.color) dot.style.backgroundColor = ev.color;

    const main = document.createElement("div");
    main.className = "cal-upcoming-main";

    const title = document.createElement("span");
    title.className = "cal-upcoming-title";
    title.textContent = ev.title;

    const meta = document.createElement("span");
    meta.className = "cal-upcoming-meta";
    let metaText = _calFormatDateRange(ev);
    if (ev.time)
      metaText += " · " + ev.time + (ev.end_time ? "–" + ev.end_time : "");
    meta.textContent = metaText;

    main.appendChild(title);
    main.appendChild(meta);
    li.appendChild(dot);
    li.appendChild(main);
    ul.appendChild(li);
  });

  wrap.innerHTML = "";
  wrap.appendChild(ul);
}

// ── Navigation ─────────────────────────────────────────────────────────────
/** Navigate the calendar grid to the previous month. */
function calPrevMonth() {
  calMonth--;
  if (calMonth < 0) {
    calMonth = 11;
    calYear--;
  }
  calRender();
}

/** Navigate the calendar grid to the next month. */
function calNextMonth() {
  calMonth++;
  if (calMonth > 11) {
    calMonth = 0;
    calYear++;
  }
  calRender();
}

/** Jump the calendar grid to the current month. */
function calGoToday() {
  const now = new Date();
  calYear = now.getFullYear();
  calMonth = now.getMonth();
  calRender();
}

// ── Monthly grid renderer ──────────────────────────────────────────────────
const CAL_MONTH_NAMES = [
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December",
];
const CAL_DAY_NAMES = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

/**
 * Build and insert the monthly calendar grid for calYear/calMonth.
 * Builds an eventMap that maps each ISO date string to the events that
 * appear on that day (multi-day events are expanded over every day they span).
 * Leading/trailing blank cells are added so the grid always starts on Monday.
 */
function calRender() {
  const role = getRole();
  const gridId = role === "admin" ? "cal-grid-admin" : "cal-grid-user";
  const labelId = role === "admin" ? "cal-month-label" : "cal-month-label-user";

  document.getElementById(labelId).textContent =
    CAL_MONTH_NAMES[calMonth] + " " + calYear;

  const wrap = document.getElementById(gridId);
  wrap.innerHTML = "";

  const grid = document.createElement("div");
  grid.className = "cal-grid";

  // ── Column headers ──────────────────────────────────────────────────
  CAL_DAY_NAMES.forEach((d) => {
    const h = document.createElement("div");
    h.className = "cal-day-header";
    h.textContent = d;
    grid.appendChild(h);
  });

  // ── Compute layout offset (week starts Monday) ──────────────────────
  const firstDay = new Date(calYear, calMonth, 1);
  const lastDay = new Date(calYear, calMonth + 1, 0);
  let startOffset = firstDay.getDay() - 1; // 0=Mon … 6=Sun
  if (startOffset < 0) startOffset = 6; // Sunday → position 6

  const today = new Date();
  const todayStr = _calDateStr(
    today.getFullYear(),
    today.getMonth(),
    today.getDate(),
  );

  // ── Build date → events[] map (multi-day events span every day) ─────
  const eventMap = {};
  for (const ev of calEvents) {
    const start = ev.date;
    const end = ev.end_date || ev.date;
    let cur = new Date(start + "T00:00:00");
    const endD = new Date(end + "T00:00:00");
    while (cur <= endD) {
      const ds = _calDateStr(cur.getFullYear(), cur.getMonth(), cur.getDate());
      (eventMap[ds] = eventMap[ds] || []).push(ev);
      cur.setDate(cur.getDate() + 1);
    }
  }

  // ── Leading blank cells ─────────────────────────────────────────────
  for (let i = 0; i < startOffset; i++) {
    const blank = document.createElement("div");
    blank.className = "cal-day other-month";
    grid.appendChild(blank);
  }

  // ── Day cells ───────────────────────────────────────────────────────
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const ds = _calDateStr(calYear, calMonth, d);
    const cell = document.createElement("div");
    cell.className = "cal-day" + (ds === todayStr ? " today" : "");

    const numEl = document.createElement("span");
    numEl.className = "cal-day-number";
    numEl.textContent = d;
    cell.appendChild(numEl);

    const eventsInDay = eventMap[ds] || [];
    eventsInDay.forEach((ev) => {
      const chip = document.createElement("div");
      chip.className = "cal-event-chip";
      if (ev.color) {
        chip.style.backgroundColor = ev.color;
        chip.style.color = _calContrastColor(ev.color);
      }
      chip.textContent = ev.title;
      chip.title = ev.title;
      chip.addEventListener("click", (e) => {
        e.stopPropagation();
        calOpenDetailModal(ev.id);
      });
      cell.appendChild(chip);
    });

    grid.appendChild(cell);
  }

  // ── Trailing blank cells to complete last row ───────────────────────
  const totalCells = startOffset + lastDay.getDate();
  const remainder = totalCells % 7;
  if (remainder !== 0) {
    for (let i = 0; i < 7 - remainder; i++) {
      const blank = document.createElement("div");
      blank.className = "cal-day other-month";
      grid.appendChild(blank);
    }
  }

  wrap.appendChild(grid);
}

/**
 * Return a zero-padded ISO date string for the given year/month/day.
 * @param {number} y - Full year
 * @param {number} m - 0-based month
 * @param {number} d - Day of month
 * @returns {string} YYYY-MM-DD
 */
function _calDateStr(y, m, d) {
  return (
    y + "-" + String(m + 1).padStart(2, "0") + "-" + String(d).padStart(2, "0")
  );
}

/** Returns '#111' or '#fff' depending on background luminance */
function _calContrastColor(hex) {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.55 ? "#111" : "#fff";
}

// ── Color palette ──────────────────────────────────────────────────────────
const CAL_COLORS = [
  { hex: "#1a73e8", label: "Blue" },
  { hex: "#0f9d58", label: "Green" },
  { hex: "#e53935", label: "Red" },
  { hex: "#fb8c00", label: "Orange" },
  { hex: "#8e24aa", label: "Purple" },
  { hex: "#00acc1", label: "Teal" },
];

/**
 * Populate the color picker <select> inside the event form.
 * Updates _calSelectedColor and the hidden color text input on change.
 */
function _calBuildColorPicker() {
  const wrap = document.getElementById("cal-color-picker");
  wrap.innerHTML = "";

  const sel = document.createElement("select");
  sel.id = "cal-color-select";

  const noneOpt = document.createElement("option");
  noneOpt.value = "";
  noneOpt.textContent = "— No color —";
  if (_calSelectedColor === null) noneOpt.selected = true;
  sel.appendChild(noneOpt);

  CAL_COLORS.forEach((c) => {
    const opt = document.createElement("option");
    opt.value = c.hex;
    opt.textContent = c.label + "  (" + c.hex + ")";
    if (_calSelectedColor === c.hex) opt.selected = true;
    sel.appendChild(opt);
  });

  sel.addEventListener("change", () => {
    _calSelectedColor = sel.value || null;
    document.getElementById("cal-f-color").value = sel.value;
  });

  wrap.appendChild(sel);
}

// ── Admin: add / edit event modal ──────────────────────────────────────────
/**
 * Open the event form modal in "add" mode with all fields reset.
 */
function calOpenAddModal() {
  calEditingId = null;
  _calSelectedColor = null;
  document.getElementById("cal-form-title").childNodes[0].textContent =
    "Add event ";
  document.getElementById("cal-event-form").reset();
  document.getElementById("cal-f-color").value = "";
  document.getElementById("cal-form-delete-btn").style.display = "none";
  calSetStatus("cal-form-status", "", "");
  _calBuildColorPicker();
  _calShowModal("cal-form-overlay", "cal-form-modal");
  document.getElementById("cal-f-title").focus();
}

/**
 * Open the event form modal in "edit" mode, pre-filling fields from the event.
 * @param {number} id - Event ID to edit
 */
function calOpenEditModal(id) {
  const ev = calEvents.find((e) => e.id === id);
  if (!ev) return;
  calEditingId = id;
  _calSelectedColor = ev.color || null;

  document.getElementById("cal-form-title").childNodes[0].textContent =
    "Edit event ";
  document.getElementById("cal-f-title").value = ev.title || "";
  document.getElementById("cal-f-date").value = ev.date || "";
  document.getElementById("cal-f-end-date").value = ev.end_date || "";
  document.getElementById("cal-f-time").value = ev.time || "";
  document.getElementById("cal-f-end-time").value = ev.end_time || "";
  document.getElementById("cal-f-description").value = ev.description || "";
  document.getElementById("cal-f-color").value = ev.color || "";
  document.getElementById("cal-form-delete-btn").style.display = "";
  calSetStatus("cal-form-status", "", "");
  _calBuildColorPicker();
  _calShowModal("cal-form-overlay", "cal-form-modal");
  document.getElementById("cal-f-title").focus();
}

/** Close the add/edit event form modal. */
function calCloseFormModal() {
  _calHideModal("cal-form-overlay", "cal-form-modal");
}

/**
 * Read the event form and POST to add-event or edit-event.
 * @param {Event} e - The form submit event (preventDefault is called)
 */
async function calSaveEvent(e) {
  e.preventDefault();
  const btn = document.getElementById("cal-form-save-btn");
  btn.disabled = true;
  calSetStatus("cal-form-status", "", "");

  const payload = {
    title: document.getElementById("cal-f-title").value.trim(),
    date: document.getElementById("cal-f-date").value,
    end_date: document.getElementById("cal-f-end-date").value || null,
    time: document.getElementById("cal-f-time").value || null,
    end_time: document.getElementById("cal-f-end-time").value || null,
    description: document.getElementById("cal-f-description").value.trim(),
    color: document.getElementById("cal-f-color").value || null,
  };
  if (calEditingId !== null) payload.id = calEditingId;

  const action = calEditingId !== null ? "edit-event" : "add-event";

  try {
    const res = await apiFetch("calendar.php?action=" + action, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || "Save failed");
    }
    calCloseFormModal();
    await calLoadEvents();
    calToast(calEditingId !== null ? "Event updated" : "Event created");
  } catch (err) {
    calSetStatus("cal-form-status", err.message, "error");
  } finally {
    btn.disabled = false;
  }
}

/** Prompt for delete confirmation and delete the event currently in the form modal. */
async function calDeleteEventFromModal() {
  if (calEditingId === null) return;
  const ev = calEvents.find((e) => e.id === calEditingId);
  if (!ev) return;
  if (!confirm('Delete "' + ev.title + '"?')) return;
  await _calDoDelete(calEditingId);
  calCloseFormModal();
}

/**
 * Send the delete-event request for the given event ID and refresh.
 * @param {number} id - Event ID
 */
async function _calDoDelete(id) {
  try {
    const res = await apiFetch("calendar.php?action=delete-event", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id }),
    });
    if (!res.ok) throw new Error("Delete failed");
    await calLoadEvents();
    calToast("Event deleted");
  } catch (err) {
    calToast(err.message, "error");
  }
}

// ── Detail modal (all users — read-only for guests, editable for admin) ────
/**
 * Open the read-only event detail modal.
 * Admin users also see an Edit button.
 * @param {number} id - Event ID
 */
function calOpenDetailModal(id) {
  const ev = calEvents.find((e) => e.id === id);
  if (!ev) return;

  // Title node (before the close button)
  document.getElementById("cal-detail-title").childNodes[0].textContent =
    (ev.title || "Event") + " ";

  const colorBar = document.getElementById("cal-detail-color-bar");
  if (ev.color) {
    colorBar.style.backgroundColor = ev.color;
    colorBar.style.display = "block";
  } else {
    colorBar.style.display = "none";
  }

  let html = '<dl class="cal-detail-dl">';
  html += "<dt>Date</dt><dd>" + _calFormatDateRange(ev) + "</dd>";
  if (ev.time) {
    html +=
      "<dt>Time</dt><dd>" +
      _calEscape(ev.time) +
      (ev.end_time ? " – " + _calEscape(ev.end_time) : "") +
      "</dd>";
  }
  if (ev.description) {
    html +=
      '<dt>Description</dt><dd class="cal-detail-desc">' +
      _calEscape(ev.description).replace(/\n/g, "<br>") +
      "</dd>";
  }
  html += "</dl>";
  document.getElementById("cal-detail-body").innerHTML = html;

  const editBtn = document.getElementById("cal-detail-edit-btn");
  editBtn.style.display = getRole() === "admin" ? "" : "none";
  editBtn.dataset.id = id;

  _calShowModal("cal-detail-overlay", "cal-detail-modal");
}

/** Close the detail modal and open the edit form for the displayed event. */
function calEditFromDetail() {
  const id = parseInt(
    document.getElementById("cal-detail-edit-btn").dataset.id,
  );
  calCloseDetailModal();
  calOpenEditModal(id);
}

/** Close the event detail modal. */
function calCloseDetailModal() {
  _calHideModal("cal-detail-overlay", "cal-detail-modal");
}

/**
 * Format a date range for display, combining start and optional end date.
 * @param {Object} ev - Event with date and optional end_date
 * @returns {string}
 */
function _calFormatDateRange(ev) {
  const start = _calPrettyDate(ev.date);
  if (!ev.end_date || ev.end_date === ev.date) return start;
  return start + " – " + _calPrettyDate(ev.end_date);
}

/**
 * Convert a YYYY-MM-DD string to a human-readable date like 'March 5, 2025'.
 * @param {string} dateStr
 * @returns {string}
 */
function _calPrettyDate(dateStr) {
  const [y, m, d] = dateStr.split("-").map(Number);
  return CAL_MONTH_NAMES[m - 1] + " " + d + ", " + y;
}

/**
 * Escape a string for safe insertion into HTML.
 * @param {*} str
 * @returns {string}
 */
function _calEscape(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

// ── Modal helpers ──────────────────────────────────────────────────────────
/**
 * Show a modal overlay + modal panel (locks scroll).
 * @param {string} overlayId - ID of the backdrop element
 * @param {string} modalId   - ID of the modal panel element
 */
function _calShowModal(overlayId, modalId) {
  document.getElementById(overlayId).style.display = "block";
  document.getElementById(modalId).style.display = "block";
  document.body.style.overflow = "hidden";
}

/**
 * Hide a modal overlay + modal panel (restores scroll).
 * @param {string} overlayId - ID of the backdrop element
 * @param {string} modalId   - ID of the modal panel element
 */
function _calHideModal(overlayId, modalId) {
  document.getElementById(overlayId).style.display = "none";
  document.getElementById(modalId).style.display = "none";
  document.body.style.overflow = "";
}

// ── Theme panel ────────────────────────────────────────────────────────────
/**
 * Toggle the theme selection panel open/closed.
 * Fetches available templates when opening.
 */
async function calToggleThemePanel() {
  const panel = document.getElementById("cal-theme-panel");
  const overlay = document.getElementById("cal-theme-overlay");
  const isOpen = panel.classList.contains("open");
  if (isOpen) {
    panel.classList.remove("open");
    overlay.style.display = "none";
  } else {
    await _calLoadThemes();
    panel.classList.add("open");
    overlay.style.display = "block";
  }
}

/**
 * Fetch theme template filenames and populate the theme <select>.
 * Pre-selects the currently active theme.
 */
async function _calLoadThemes() {
  try {
    const res = await apiFetch("calendar.php?action=get-calendar-templates");
    if (!res.ok) return;
    const data = await res.json();
    const sel = document.getElementById("cal-theme-select");
    const cur = document
      .getElementById("cal-theme-link")
      .href.split("/")
      .pop()
      .split("?")[0];
    sel.innerHTML = data.templates
      .map(
        (t) =>
          `<option value="${_calEscape(t)}"${t === cur ? " selected" : ""}>${_calEscape(t.replace(".css", ""))}</option>`,
      )
      .join("");
  } catch (_) {}
}

/**
 * Persist the selected theme to the server and update the page <link>.
 */
async function calSaveTheme() {
  const theme = document.getElementById("cal-theme-select").value;
  try {
    const res = await apiFetch("calendar.php?action=save-calendar-theme", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ theme }),
    });
    if (!res.ok) throw new Error("Failed to save theme");
    document.getElementById("cal-theme-link").href =
      "templates-calendar/" + encodeURIComponent(theme);
    calToggleThemePanel();
    calToast("Theme saved");
  } catch (err) {
    calToast(err.message, "error");
  }
}

// ── Keyboard: Escape closes open modals / panels ───────────────────────────
document.addEventListener("keydown", (e) => {
  if (e.key !== "Escape") return;
  if (document.getElementById("cal-form-modal").style.display === "block")
    calCloseFormModal();
  if (document.getElementById("cal-detail-modal").style.display === "block")
    calCloseDetailModal();
  if (document.getElementById("cal-theme-panel").classList.contains("open"))
    calToggleThemePanel();
});
