// ═══════════════════════════════════════════════════════════════════════════
// calendar.js — Calendar module client
// ═══════════════════════════════════════════════════════════════════════════

// ── State ──────────────────────────────────────────────────────────────────
let calEvents        = [];
let calYear          = new Date().getFullYear();
let calMonth         = new Date().getMonth(); // 0-based
let calEditingId     = null; // null = adding new, integer = editing existing
let _calSelectedColor = null;

// ── Toast ──────────────────────────────────────────────────────────────────
let _calToastTimer;
function calToast(msg, type = 'success', ms = 3200) {
  const el = document.getElementById('cal-toast');
  el.textContent = msg;
  el.className = 'show ' + type;
  clearTimeout(_calToastTimer);
  _calToastTimer = setTimeout(() => el.classList.remove('show'), ms);
}

function calSetStatus(id, msg, type) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.className = 'cal-status ' + type;
  el.style.display = msg ? '' : 'none';
}

// ── Auth / routing ─────────────────────────────────────────────────────────
setOnUnauthorized(calLogout);

function calLogout() {
  sessionStorage.clear();
  window.location.href = '../index.php';
}

function calShowApp() {
  document.getElementById('cal-header').style.display = 'flex';
  document.getElementById('cal-screen').style.display = 'block';
  document.getElementById('cal-user-badge').textContent =
    getUser() + ' (' + getRole() + ')';
}

function calRoute() {
  const role    = getRole();
  const themeBtn = document.getElementById('cal-theme-btn');
  if (themeBtn) themeBtn.style.display = role === 'admin' ? '' : 'none';
  if (role === 'admin') {
    document.getElementById('admin-panel').style.display = '';
    document.getElementById('user-panel').style.display = 'none';
  } else {
    document.getElementById('admin-panel').style.display = 'none';
    document.getElementById('user-panel').style.display = '';
  }
  calLoadEvents();
}

// Auto-restore session
if (getToken()) {
  calShowApp();
  calRoute();
} else {
  window.location.href = '../index.php';
}

// ── Load events ────────────────────────────────────────────────────────────
async function calLoadEvents() {
  try {
    const res = await apiFetch('calendar.php?action=get-events');
    if (!res.ok) throw new Error('Failed to load events');
    calEvents = await res.json();
    calRender();
  } catch (err) {
    const gridId = getRole() === 'admin' ? 'cal-grid-admin' : 'cal-grid-user';
    document.getElementById(gridId).innerHTML =
      '<p class="cal-load-error">' + err.message + '</p>';
  }
}

// ── Navigation ─────────────────────────────────────────────────────────────
function calPrevMonth() {
  calMonth--;
  if (calMonth < 0) { calMonth = 11; calYear--; }
  calRender();
}

function calNextMonth() {
  calMonth++;
  if (calMonth > 11) { calMonth = 0; calYear++; }
  calRender();
}

function calGoToday() {
  const now = new Date();
  calYear   = now.getFullYear();
  calMonth  = now.getMonth();
  calRender();
}

// ── Monthly grid renderer ──────────────────────────────────────────────────
const CAL_MONTH_NAMES = [
  'January','February','March','April','May','June',
  'July','August','September','October','November','December'
];
const CAL_DAY_NAMES = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

function calRender() {
  const role    = getRole();
  const gridId  = role === 'admin' ? 'cal-grid-admin' : 'cal-grid-user';
  const labelId = role === 'admin' ? 'cal-month-label' : 'cal-month-label-user';

  document.getElementById(labelId).textContent =
    CAL_MONTH_NAMES[calMonth] + ' ' + calYear;

  const wrap = document.getElementById(gridId);
  wrap.innerHTML = '';

  const grid = document.createElement('div');
  grid.className = 'cal-grid';

  // ── Column headers ──────────────────────────────────────────────────
  CAL_DAY_NAMES.forEach(d => {
    const h = document.createElement('div');
    h.className = 'cal-day-header';
    h.textContent = d;
    grid.appendChild(h);
  });

  // ── Compute layout offset (week starts Monday) ──────────────────────
  const firstDay = new Date(calYear, calMonth, 1);
  const lastDay  = new Date(calYear, calMonth + 1, 0);
  let startOffset = firstDay.getDay() - 1; // 0=Mon … 6=Sun
  if (startOffset < 0) startOffset = 6;    // Sunday → position 6

  const today    = new Date();
  const todayStr = _calDateStr(today.getFullYear(), today.getMonth(), today.getDate());

  // ── Build date → events[] map (multi-day events span every day) ─────
  const eventMap = {};
  for (const ev of calEvents) {
    const start = ev.date;
    const end   = ev.end_date || ev.date;
    let cur     = new Date(start + 'T00:00:00');
    const endD  = new Date(end   + 'T00:00:00');
    while (cur <= endD) {
      const ds = _calDateStr(cur.getFullYear(), cur.getMonth(), cur.getDate());
      (eventMap[ds] = eventMap[ds] || []).push(ev);
      cur.setDate(cur.getDate() + 1);
    }
  }

  // ── Leading blank cells ─────────────────────────────────────────────
  for (let i = 0; i < startOffset; i++) {
    const blank = document.createElement('div');
    blank.className = 'cal-day other-month';
    grid.appendChild(blank);
  }

  // ── Day cells ───────────────────────────────────────────────────────
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const ds   = _calDateStr(calYear, calMonth, d);
    const cell = document.createElement('div');
    cell.className = 'cal-day' + (ds === todayStr ? ' today' : '');

    const numEl = document.createElement('span');
    numEl.className = 'cal-day-number';
    numEl.textContent = d;
    cell.appendChild(numEl);

    const eventsInDay = eventMap[ds] || [];
    eventsInDay.forEach(ev => {
      const chip = document.createElement('div');
      chip.className = 'cal-event-chip';
      if (ev.color) {
        chip.style.backgroundColor = ev.color;
        chip.style.color = _calContrastColor(ev.color);
      }
      chip.textContent = ev.title;
      chip.title = ev.title;
      chip.addEventListener('click', e => {
        e.stopPropagation();
        calOpenDetailModal(ev.id);
      });
      cell.appendChild(chip);
    });

    grid.appendChild(cell);
  }

  // ── Trailing blank cells to complete last row ───────────────────────
  const totalCells = startOffset + lastDay.getDate();
  const remainder  = totalCells % 7;
  if (remainder !== 0) {
    for (let i = 0; i < 7 - remainder; i++) {
      const blank = document.createElement('div');
      blank.className = 'cal-day other-month';
      grid.appendChild(blank);
    }
  }

  wrap.appendChild(grid);
}

function _calDateStr(y, m, d) {
  return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
}

/** Returns '#111' or '#fff' depending on background luminance */
function _calContrastColor(hex) {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.55 ? '#111' : '#fff';
}

// ── Color palette ──────────────────────────────────────────────────────────
const CAL_COLORS = [
  { hex: '#1a73e8', label: 'Blue'   },
  { hex: '#0f9d58', label: 'Green'  },
  { hex: '#e53935', label: 'Red'    },
  { hex: '#fb8c00', label: 'Orange' },
  { hex: '#8e24aa', label: 'Purple' },
  { hex: '#00acc1', label: 'Teal'   },
];

function _calBuildColorPicker() {
  const wrap = document.getElementById('cal-color-picker');
  wrap.innerHTML = '';

  // "No color" swatch
  const none = document.createElement('button');
  none.type = 'button';
  none.className = 'cal-color-swatch cal-color-none' +
    (_calSelectedColor === null ? ' active' : '');
  none.title = 'No color';
  none.textContent = '✕';
  none.addEventListener('click', () => {
    _calSelectedColor = null;
    document.getElementById('cal-f-color').value = '';
    _calBuildColorPicker();
  });
  wrap.appendChild(none);

  CAL_COLORS.forEach(c => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cal-color-swatch' + (_calSelectedColor === c.hex ? ' active' : '');
    btn.style.backgroundColor = c.hex;
    btn.title = c.label;
    btn.addEventListener('click', () => {
      _calSelectedColor = c.hex;
      document.getElementById('cal-f-color').value = c.hex;
      _calBuildColorPicker();
    });
    wrap.appendChild(btn);
  });
}

// ── Admin: add / edit event modal ──────────────────────────────────────────
function calOpenAddModal() {
  calEditingId      = null;
  _calSelectedColor = null;
  document.getElementById('cal-form-title').childNodes[0].textContent = 'Add event ';
  document.getElementById('cal-event-form').reset();
  document.getElementById('cal-f-color').value = '';
  document.getElementById('cal-form-delete-btn').style.display = 'none';
  calSetStatus('cal-form-status', '', '');
  _calBuildColorPicker();
  _calShowModal('cal-form-overlay', 'cal-form-modal');
  document.getElementById('cal-f-title').focus();
}

function calOpenEditModal(id) {
  const ev = calEvents.find(e => e.id === id);
  if (!ev) return;
  calEditingId      = id;
  _calSelectedColor = ev.color || null;

  document.getElementById('cal-form-title').childNodes[0].textContent = 'Edit event ';
  document.getElementById('cal-f-title').value       = ev.title       || '';
  document.getElementById('cal-f-date').value        = ev.date        || '';
  document.getElementById('cal-f-end-date').value    = ev.end_date    || '';
  document.getElementById('cal-f-time').value        = ev.time        || '';
  document.getElementById('cal-f-end-time').value    = ev.end_time    || '';
  document.getElementById('cal-f-description').value = ev.description || '';
  document.getElementById('cal-f-color').value       = ev.color       || '';
  document.getElementById('cal-form-delete-btn').style.display = '';
  calSetStatus('cal-form-status', '', '');
  _calBuildColorPicker();
  _calShowModal('cal-form-overlay', 'cal-form-modal');
  document.getElementById('cal-f-title').focus();
}

function calCloseFormModal() {
  _calHideModal('cal-form-overlay', 'cal-form-modal');
}

async function calSaveEvent(e) {
  e.preventDefault();
  const btn = document.getElementById('cal-form-save-btn');
  btn.disabled = true;
  calSetStatus('cal-form-status', '', '');

  const payload = {
    title:       document.getElementById('cal-f-title').value.trim(),
    date:        document.getElementById('cal-f-date').value,
    end_date:    document.getElementById('cal-f-end-date').value   || null,
    time:        document.getElementById('cal-f-time').value       || null,
    end_time:    document.getElementById('cal-f-end-time').value   || null,
    description: document.getElementById('cal-f-description').value.trim(),
    color:       document.getElementById('cal-f-color').value      || null,
  };
  if (calEditingId !== null) payload.id = calEditingId;

  const action = calEditingId !== null ? 'edit-event' : 'add-event';

  try {
    const res = await apiFetch('calendar.php?action=' + action, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || 'Save failed');
    }
    calCloseFormModal();
    await calLoadEvents();
    calToast(calEditingId !== null ? 'Event updated' : 'Event created');
  } catch (err) {
    calSetStatus('cal-form-status', err.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

async function calDeleteEventFromModal() {
  if (calEditingId === null) return;
  const ev = calEvents.find(e => e.id === calEditingId);
  if (!ev) return;
  if (!confirm('Delete "' + ev.title + '"?')) return;
  await _calDoDelete(calEditingId);
  calCloseFormModal();
}

async function _calDoDelete(id) {
  try {
    const res = await apiFetch('calendar.php?action=delete-event', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id }),
    });
    if (!res.ok) throw new Error('Delete failed');
    await calLoadEvents();
    calToast('Event deleted');
  } catch (err) {
    calToast(err.message, 'error');
  }
}

// ── Detail modal (all users — read-only for guests, editable for admin) ────
function calOpenDetailModal(id) {
  const ev = calEvents.find(e => e.id === id);
  if (!ev) return;

  // Title node (before the close button)
  document.getElementById('cal-detail-title').childNodes[0].textContent =
    (ev.title || 'Event') + ' ';

  const colorBar = document.getElementById('cal-detail-color-bar');
  if (ev.color) {
    colorBar.style.backgroundColor = ev.color;
    colorBar.style.display = 'block';
  } else {
    colorBar.style.display = 'none';
  }

  let html = '<dl class="cal-detail-dl">';
  html += '<dt>Date</dt><dd>' + _calFormatDateRange(ev) + '</dd>';
  if (ev.time) {
    html += '<dt>Time</dt><dd>' + _calEscape(ev.time) +
      (ev.end_time ? ' – ' + _calEscape(ev.end_time) : '') + '</dd>';
  }
  if (ev.description) {
    html += '<dt>Description</dt><dd class="cal-detail-desc">' +
      _calEscape(ev.description).replace(/\n/g, '<br>') + '</dd>';
  }
  html += '</dl>';
  document.getElementById('cal-detail-body').innerHTML = html;

  const editBtn = document.getElementById('cal-detail-edit-btn');
  editBtn.style.display = getRole() === 'admin' ? '' : 'none';
  editBtn.dataset.id = id;

  _calShowModal('cal-detail-overlay', 'cal-detail-modal');
}

function calEditFromDetail() {
  const id = parseInt(document.getElementById('cal-detail-edit-btn').dataset.id);
  calCloseDetailModal();
  calOpenEditModal(id);
}

function calCloseDetailModal() {
  _calHideModal('cal-detail-overlay', 'cal-detail-modal');
}

function _calFormatDateRange(ev) {
  const start = _calPrettyDate(ev.date);
  if (!ev.end_date || ev.end_date === ev.date) return start;
  return start + ' – ' + _calPrettyDate(ev.end_date);
}

function _calPrettyDate(dateStr) {
  const [y, m, d] = dateStr.split('-').map(Number);
  return CAL_MONTH_NAMES[m - 1] + ' ' + d + ', ' + y;
}

function _calEscape(str) {
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ── Modal helpers ──────────────────────────────────────────────────────────
function _calShowModal(overlayId, modalId) {
  document.getElementById(overlayId).style.display = 'block';
  document.getElementById(modalId).style.display   = 'block';
  document.body.style.overflow = 'hidden';
}

function _calHideModal(overlayId, modalId) {
  document.getElementById(overlayId).style.display = 'none';
  document.getElementById(modalId).style.display   = 'none';
  document.body.style.overflow = '';
}

// ── Theme panel ────────────────────────────────────────────────────────────
async function calToggleThemePanel() {
  const panel   = document.getElementById('cal-theme-panel');
  const overlay = document.getElementById('cal-theme-overlay');
  const isOpen  = panel.classList.contains('open');
  if (isOpen) {
    panel.classList.remove('open');
    overlay.style.display = 'none';
  } else {
    await _calLoadThemes();
    panel.classList.add('open');
    overlay.style.display = 'block';
  }
}

async function _calLoadThemes() {
  try {
    const res  = await apiFetch('calendar.php?action=get-calendar-templates');
    if (!res.ok) return;
    const data = await res.json();
    const sel  = document.getElementById('cal-theme-select');
    const cur  = document.getElementById('cal-theme-link').href.split('/').pop().split('?')[0];
    sel.innerHTML = data.templates.map(t =>
      `<option value="${_calEscape(t)}"${t === cur ? ' selected' : ''}>${_calEscape(t.replace('.css', ''))}</option>`
    ).join('');
  } catch (_) {}
}

async function calSaveTheme() {
  const theme = document.getElementById('cal-theme-select').value;
  try {
    const res = await apiFetch('calendar.php?action=save-calendar-theme', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ theme }),
    });
    if (!res.ok) throw new Error('Failed to save theme');
    document.getElementById('cal-theme-link').href =
      'templates-calendar/' + encodeURIComponent(theme);
    calToggleThemePanel();
    calToast('Theme saved');
  } catch (err) {
    calToast(err.message, 'error');
  }
}

// ── Keyboard: Escape closes open modals / panels ───────────────────────────
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  if (document.getElementById('cal-form-modal').style.display === 'block')   calCloseFormModal();
  if (document.getElementById('cal-detail-modal').style.display === 'block') calCloseDetailModal();
  if (document.getElementById('cal-theme-panel').classList.contains('open')) calToggleThemePanel();
});
