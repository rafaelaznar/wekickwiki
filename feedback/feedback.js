  // ═══════════════════════════════════════════════════════════════════════════
  // feedback.js — Feedback module app script
  // ═══════════════════════════════════════════════════════════════════════════

  // ── State ──────────────────────────────────────────────────────────────────
  let _allEvents      = [];   // admin events list cache
  let _openEvents     = [];   // user open events cache
  let _editingEventId = null; // null = adding, number = editing
  let _respondEventId = null; // event the user is currently responding to
  let _deleteEventId  = null; // event pending deletion
  let _fbToastTimer;

  // ── Toast notification ─────────────────────────────────────────────────────
  function fbToast(msg, type = 'success', ms = 3200) {
    const el = document.getElementById('fb-toast');
    el.textContent = msg;
    el.className = 'fb-toast show ' + type;
    clearTimeout(_fbToastTimer);
    _fbToastTimer = setTimeout(() => el.classList.remove('show'), ms);
  }

  function fbSetStatus(id, msg, type) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'fb-status ' + type;
    el.style.display = msg ? '' : 'none';
  }

  // ── HTML escaping ──────────────────────────────────────────────────────────
  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Date formatting ────────────────────────────────────────────────────────
  function fmtDate(d) {
    if (!d) return '—';
    try {
      return new Date(d).toLocaleDateString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
      });
    } catch { return d; }
  }

  function fmtScore(v) {
    if (v === null || v === undefined) return '—';
    return parseFloat(v).toFixed(1);
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // Auth & routing
  // ═══════════════════════════════════════════════════════════════════════════
  setOnUnauthorized(fbLogout);

  function fbLogout() {
    sessionStorage.clear();
    window.location.href = '../index.php';
  }

  function fbShowApp() {
    document.getElementById('fb-header').style.display = 'flex';
    document.getElementById('fb-screen').style.display = 'block';
    document.getElementById('fb-user-badge').textContent = getUser() + ' (' + getRole() + ')';
  }

  function fbRoute() {
    const role     = getRole();
    const themeBtn = document.getElementById('fb-theme-btn');
    if (themeBtn) themeBtn.style.display = role === 'admin' ? '' : 'none';

    if (role === 'admin') {
      document.getElementById('admin-panel').style.display = '';
      document.getElementById('user-panel').style.display  = 'none';
      fbShowTab('events');
    } else {
      document.getElementById('admin-panel').style.display = 'none';
      document.getElementById('user-panel').style.display  = '';
      fbLoadUserEvents();
    }
  }

  if (getToken()) { fbShowApp(); fbRoute(); }
  else { window.location.href = '../index.php'; }

  // ═══════════════════════════════════════════════════════════════════════════
  // Tab switching (admin)
  // ═══════════════════════════════════════════════════════════════════════════
  function fbShowTab(name) {
    document.querySelectorAll('.fb-tab').forEach(t => {
      t.classList.toggle('active', t.dataset.tab === name);
    });
    document.querySelectorAll('.fb-tab-panel').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
    if (name === 'events')   fbLoadEventsAdmin();
    if (name === 'settings') fbLoadTemplates();
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ADMIN — Events tab
  // ═══════════════════════════════════════════════════════════════════════════
  async function fbLoadEventsAdmin() {
    const wrap = document.getElementById('events-table-wrap');
    wrap.innerHTML = '<div class="fb-loading"><div class="fb-spinner"></div> Loading…</div>';
    try {
      const res = await apiFetch('feedback.php?action=get-events-admin');
      if (!res.ok) throw new Error('Failed to load events');
      _allEvents = await res.json();
      fbRenderEventsTable();
    } catch (err) {
      wrap.innerHTML = `<p class="fb-load-error">${esc(err.message)}</p>`;
    }
  }

  function fbRenderEventsTable() {
    const wrap = document.getElementById('events-table-wrap');
    if (!_allEvents.length) {
      wrap.innerHTML = '<p class="fb-empty">No events yet. Create one with "Add event".</p>';
      return;
    }

    const rows = _allEvents.map(ev => {
      const typeBadge   = `<span class="badge badge-type">${esc(ev.type)}</span>`;
      const statusBadge = ev.status === 'open'
        ? `<span class="badge badge-open">open</span>`
        : `<span class="badge badge-closed">closed</span>`;
      const anonBadge   = ev.anonymous
        ? `<span class="badge badge-anon">anonymous</span>`
        : `<span class="badge badge-named">named</span>`;
      const toggleLabel = ev.status === 'open' ? 'Close' : 'Open';

      return `<tr>
        <td>${esc(ev.title)}</td>
        <td>${typeBadge}</td>
        <td>${anonBadge}</td>
        <td>${statusBadge}</td>
        <td class="fb-cell-center">${ev.response_count ?? 0}</td>
        <td class="fb-cell-actions">
          <button class="btn btn-sm" onclick="fbOpenResponsesModal(${ev.id})" title="View responses">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Responses
          </button>
          <button class="btn btn-sm" onclick="fbToggleEventStatus(${ev.id})" title="Toggle status">
            <svg viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
            ${toggleLabel}
          </button>
          <button class="btn btn-sm" onclick="fbOpenEventModal(${ev.id})" title="Edit">
            <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit
          </button>
          <button class="btn btn-sm btn-danger" onclick="fbOpenDeleteModal(${ev.id})" title="Delete">
            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            Delete
          </button>
        </td>
      </tr>`;
    }).join('');

    wrap.innerHTML = `
      <table class="fb-table">
        <thead><tr>
          <th>Title</th>
          <th>Type</th>
          <th>Anonymity</th>
          <th>Status</th>
          <th>Responses</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>`;
  }

  // ── Event modal (create / edit) ────────────────────────────────────────────
  function fbOpenEventModal(id = null) {
    _editingEventId = id;
    document.getElementById('fb-event-modal-title').textContent = id ? 'Edit event' : 'Add event';

    if (id) {
      const ev = _allEvents.find(e => e.id === id);
      if (!ev) return;
      document.getElementById('fb-ev-title').value         = ev.title       ?? '';
      document.getElementById('fb-ev-desc').value          = ev.description ?? '';
      document.getElementById('fb-ev-type').value          = ev.type        ?? 'open';
      document.getElementById('fb-ev-status').value        = ev.status      ?? 'open';
      document.getElementById('fb-ev-anonymous').checked   = !!ev.anonymous;
      // Lock type if responses already exist
      document.getElementById('fb-ev-type').disabled = (ev.response_count ?? 0) > 0;
    } else {
      document.getElementById('fb-ev-title').value       = '';
      document.getElementById('fb-ev-desc').value        = '';
      document.getElementById('fb-ev-type').value        = 'open';
      document.getElementById('fb-ev-type').disabled     = false;
      document.getElementById('fb-ev-status').value      = 'open';
      document.getElementById('fb-ev-anonymous').checked = false;
    }

    fbSetStatus('fb-event-modal-status', '', '');
    document.getElementById('fb-event-overlay').style.display = 'block';
    document.getElementById('fb-event-modal').style.display   = 'block';
    document.getElementById('fb-ev-title').focus();
  }

  function fbCloseEventModal() {
    document.getElementById('fb-event-overlay').style.display = 'none';
    document.getElementById('fb-event-modal').style.display   = 'none';
    _editingEventId = null;
  }

  async function fbSaveEvent() {
    const title = document.getElementById('fb-ev-title').value.trim();
    if (!title) {
      fbSetStatus('fb-event-modal-status', 'Title is required.', 'error');
      return;
    }
    const body = {
      id:          _editingEventId ?? 0,
      title,
      description: document.getElementById('fb-ev-desc').value.trim(),
      type:        document.getElementById('fb-ev-type').value,
      status:      document.getElementById('fb-ev-status').value,
      anonymous:   document.getElementById('fb-ev-anonymous').checked,
    };
    fbSetStatus('fb-event-modal-status', 'Saving…', '');
    try {
      const res = await apiFetch('feedback.php?action=save-event', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error ?? 'Save failed');
      fbCloseEventModal();
      fbToast(_editingEventId ? 'Event updated.' : 'Event created.');
      fbLoadEventsAdmin();
    } catch (err) {
      fbSetStatus('fb-event-modal-status', err.message, 'error');
    }
  }

  // ── Toggle status ──────────────────────────────────────────────────────────
  async function fbToggleEventStatus(id) {
    try {
      const res = await apiFetch('feedback.php?action=toggle-event-status', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error ?? 'Failed to toggle status');
      fbToast('Status updated.');
      fbLoadEventsAdmin();
    } catch (err) {
      fbToast(err.message, 'error');
    }
  }

  // ── Delete modal ───────────────────────────────────────────────────────────
  function fbOpenDeleteModal(id) {
    _deleteEventId = id;
    const ev  = _allEvents.find(e => e.id === id);
    const cnt = ev?.response_count ?? 0;
    document.getElementById('fb-del-modal-msg').textContent =
      `Delete "${ev?.title ?? ''}"? This will also delete all ${cnt} response(s). This action cannot be undone.`;
    document.getElementById('fb-del-overlay').style.display = 'block';
    document.getElementById('fb-del-modal').style.display   = 'block';
  }

  function fbCloseDeleteModal() {
    document.getElementById('fb-del-overlay').style.display = 'none';
    document.getElementById('fb-del-modal').style.display   = 'none';
    _deleteEventId = null;
  }

  async function fbConfirmDelete() {
    const id = _deleteEventId;
    fbCloseDeleteModal();
    try {
      const res = await apiFetch('feedback.php?action=delete-event', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error ?? 'Delete failed');
      fbToast('Event deleted.');
      fbLoadEventsAdmin();
    } catch (err) {
      fbToast(err.message, 'error');
    }
  }

  // ── Responses modal ────────────────────────────────────────────────────────
  async function fbOpenResponsesModal(id) {
    const ev = _allEvents.find(e => e.id === id);
    document.getElementById('fb-resp-modal-title').textContent = `Responses — ${esc(ev?.title ?? '')}`;
    document.getElementById('fb-resp-table-wrap').innerHTML =
      '<div class="fb-loading"><div class="fb-spinner"></div> Loading…</div>';
    document.getElementById('fb-resp-modal-stats').innerHTML = '';
    document.getElementById('fb-resp-overlay').style.display = 'block';
    document.getElementById('fb-resp-modal').style.display   = 'block';

    try {
      const res = await apiFetch(`feedback.php?action=get-event-responses&id=${id}`);
      if (!res.ok) throw new Error('Failed to load responses');
      const { responses, stats } = await res.json();

      // Stats bar
      let statsHtml = `<span>${responses.length} response(s)</span>`;
      if (stats?.avg_score !== null && stats?.avg_score !== undefined) {
        statsHtml += ` <span>· Avg score: <strong>${fmtScore(stats.avg_score)}</strong></span>`;
      }
      document.getElementById('fb-resp-modal-stats').innerHTML = statsHtml;

      if (!responses.length) {
        document.getElementById('fb-resp-table-wrap').innerHTML =
          '<p class="fb-empty">No responses yet.</p>';
        return;
      }

      const isAnonymous = ev?.anonymous ?? false;
      const showScore   = ['closed', 'mixed'].includes(ev?.type);
      const showText    = ['open',   'mixed'].includes(ev?.type);

      let headers = '';
      if (!isAnonymous)  headers += '<th>User</th>';
      if (showScore)     headers += '<th>Score</th>';
      if (showText)      headers += '<th>Opinion</th>';
      headers += '<th>Date</th>';

      const rows = responses.map(r => {
        let cells = '';
        if (!isAnonymous) cells += `<td>${esc(r.username ?? '—')}</td>`;
        if (showScore)    cells += `<td class="fb-cell-center">${r.score !== null ? r.score : '—'}</td>`;
        if (showText)     cells += `<td class="fb-cell-text">${esc(r.text ?? '')}</td>`;
        cells += `<td class="fb-cell-date">${fmtDate(r.submitted_at)}</td>`;
        return `<tr>${cells}</tr>`;
      }).join('');

      document.getElementById('fb-resp-table-wrap').innerHTML = `
        <table class="fb-table">
          <thead><tr>${headers}</tr></thead>
          <tbody>${rows}</tbody>
        </table>`;
    } catch (err) {
      document.getElementById('fb-resp-table-wrap').innerHTML =
        `<p class="fb-load-error">${esc(err.message)}</p>`;
    }
  }

  function fbCloseResponsesModal() {
    document.getElementById('fb-resp-overlay').style.display = 'none';
    document.getElementById('fb-resp-modal').style.display   = 'none';
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ADMIN — Settings tab (theme picker)
  // ═══════════════════════════════════════════════════════════════════════════
  async function fbLoadTemplates() {
    const list = document.getElementById('fb-theme-list');
    list.innerHTML = '<div class="fb-loading"><div class="fb-spinner"></div> Loading…</div>';
    try {
      const res = await apiFetch('feedback.php?action=get-feedback-templates');
      if (!res.ok) throw new Error('Failed to load templates');
      const { templates } = await res.json();
      const current = document.getElementById('fb-theme-link')?.getAttribute('href')?.split('/').pop() ?? '';
      list.innerHTML = (templates ?? []).map(t => `
        <label class="fb-theme-option${t === current ? ' active' : ''}">
          <input type="radio" name="fb-theme" value="${esc(t)}" ${t === current ? 'checked' : ''}
                 onchange="fbSaveTheme('${esc(t)}')">
          ${esc(t.replace('.css', ''))}
        </label>`).join('');
    } catch (err) {
      list.innerHTML = `<p class="fb-load-error">${esc(err.message)}</p>`;
    }
  }

  async function fbSaveTheme(theme) {
    fbSetStatus('settings-status', 'Saving…', '');
    try {
      const res = await apiFetch('feedback.php?action=save-feedback-theme', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ theme }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error ?? 'Failed to save theme');
      document.getElementById('fb-theme-link').href = `templates-feedback/${theme}`;
      document.querySelectorAll('.fb-theme-option').forEach(el => {
        el.classList.toggle('active', el.querySelector('input')?.value === theme);
      });
      fbSetStatus('settings-status', 'Theme saved.', 'ok');
    } catch (err) {
      fbSetStatus('settings-status', err.message, 'error');
    }
  }

  // ── Header theme panel (quick-switcher) ────────────────────────────────────
  function fbToggleThemePanel() {
    const panel = document.getElementById('fb-theme-panel');
    if (!panel) return;
    if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
    fbLoadThemePanel();
    panel.style.display = '';
  }

  async function fbLoadThemePanel() {
    const list = document.getElementById('fb-theme-panel-list');
    list.innerHTML = '<div class="fb-loading"><div class="fb-spinner"></div></div>';
    try {
      const res = await apiFetch('feedback.php?action=get-feedback-templates');
      if (!res.ok) throw new Error();
      const { templates } = await res.json();
      const current = document.getElementById('fb-theme-link')?.getAttribute('href')?.split('/').pop() ?? '';
      list.innerHTML = (templates ?? []).map(t =>
        `<button class="fb-theme-panel-btn${t === current ? ' active' : ''}"
                 onclick="fbSaveTheme('${esc(t)}');fbToggleThemePanel()">
          ${esc(t.replace('.css', ''))}
         </button>`).join('');
    } catch { list.innerHTML = ''; }
  }

  // Close theme panel on outside click
  document.addEventListener('click', e => {
    const panel  = document.getElementById('fb-theme-panel');
    const btn    = document.getElementById('fb-theme-btn');
    if (!panel || panel.style.display === 'none') return;
    if (!panel.contains(e.target) && e.target !== btn && !btn?.contains(e.target)) {
      panel.style.display = 'none';
    }
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // USER — Events view
  // ═══════════════════════════════════════════════════════════════════════════
  async function fbLoadUserEvents() {
    const wrap = document.getElementById('user-events-wrap');
    wrap.innerHTML = '<div class="fb-loading"><div class="fb-spinner"></div> Loading…</div>';
    try {
      const [evRes, myRes] = await Promise.all([
        apiFetch('feedback.php?action=get-open-events'),
        apiFetch('feedback.php?action=get-my-responses'),
      ]);
      if (!evRes.ok) throw new Error('Failed to load events');
      _openEvents = await evRes.json();
      const myResponses  = myRes.ok ? await myRes.json() : [];
      const respondedIds = new Set(myResponses.map(r => r.event_id));
      fbRenderUserEvents(_openEvents, respondedIds);
    } catch (err) {
      wrap.innerHTML = `<p class="fb-load-error">${esc(err.message)}</p>`;
    }
  }

  function fbRenderUserEvents(events, respondedIds) {
    const wrap    = document.getElementById('user-events-wrap');
    const pending = events.filter(e => !respondedIds.has(e.id));
    const done    = events.filter(e =>  respondedIds.has(e.id));

    if (!events.length) {
      wrap.innerHTML = '<p class="fb-empty">No open events at the moment.</p>';
      return;
    }

    let html = '';

    if (pending.length) {
      html += '<h2 class="fb-section-title">Pending</h2><div class="fb-event-cards">';
      pending.forEach(ev => {
        const hint = ev.type === 'open'   ? 'Share your thoughts in text.'
                   : ev.type === 'closed' ? 'Rate the event with a score from 0 to 10.'
                   :                        'Rate with a score and share your thoughts.';
        html += `<div class="fb-event-card" id="ev-card-${ev.id}">
          <div class="fb-event-card-title">${esc(ev.title)}</div>
          ${ev.description ? `<div class="fb-event-card-desc">${esc(ev.description)}</div>` : ''}
          <div class="fb-event-card-meta">${esc(hint)}</div>
          <button class="btn btn-primary fb-respond-btn" onclick="fbOpenRespondModal(${ev.id})">
            <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            Respond
          </button>
        </div>`;
      });
      html += '</div>';
    }

    if (done.length) {
      html += '<h2 class="fb-section-title fb-section-done">Already responded</h2><div class="fb-event-cards">';
      done.forEach(ev => {
        html += `<div class="fb-event-card fb-event-card-done">
          <div class="fb-event-card-title">${esc(ev.title)}</div>
          ${ev.description ? `<div class="fb-event-card-desc">${esc(ev.description)}</div>` : ''}
          <div class="fb-thankyou">
            <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            Thank you for your feedback!
          </div>
        </div>`;
      });
      html += '</div>';
    }

    wrap.innerHTML = html;
  }

  // ── Respond modal ──────────────────────────────────────────────────────────
  function fbOpenRespondModal(id) {
    const ev = _openEvents.find(e => e.id === id);
    if (!ev) return;
    _respondEventId = id;

    document.getElementById('fb-respond-modal-title').textContent = ev.title;

    const descEl = document.getElementById('fb-respond-modal-desc');
    descEl.textContent    = ev.description ?? '';
    descEl.style.display  = ev.description ? '' : 'none';

    const showText  = ['open',   'mixed'].includes(ev.type);
    const showScore = ['closed', 'mixed'].includes(ev.type);

    const textField  = document.getElementById('fb-respond-text-field');
    const scoreField = document.getElementById('fb-respond-score-field');
    textField.style.display  = showText  ? '' : 'none';
    scoreField.style.display = showScore ? '' : 'none';

    if (showText)  document.getElementById('fb-respond-text').value = '';
    if (showScore) {
      document.getElementById('fb-respond-score-range').value = 5;
      document.getElementById('fb-respond-score').value       = 5;
    }

    fbSetStatus('fb-respond-status', '', '');
    document.getElementById('fb-respond-overlay').style.display = 'block';
    document.getElementById('fb-respond-modal').style.display   = 'block';
    if (showText) document.getElementById('fb-respond-text').focus();
  }

  function fbCloseRespondModal() {
    document.getElementById('fb-respond-overlay').style.display = 'none';
    document.getElementById('fb-respond-modal').style.display   = 'none';
    _respondEventId = null;
  }

  function fbSyncScore(val) {
    const v = Math.min(10, Math.max(0, parseInt(val, 10) || 0));
    document.getElementById('fb-respond-score-range').value = v;
    document.getElementById('fb-respond-score').value       = v;
  }

  async function fbSubmitResponse() {
    const id = _respondEventId;
    const ev = _openEvents.find(e => e.id === id);
    if (!ev) return;

    const body = { event_id: id };

    if (['open', 'mixed'].includes(ev.type)) {
      body.text = document.getElementById('fb-respond-text').value.trim();
      if (!body.text) {
        fbSetStatus('fb-respond-status', 'Please enter your opinion.', 'error');
        return;
      }
    }

    if (['closed', 'mixed'].includes(ev.type)) {
      const raw = parseInt(document.getElementById('fb-respond-score').value, 10);
      if (isNaN(raw) || raw < 0 || raw > 10) {
        fbSetStatus('fb-respond-status', 'Score must be between 0 and 10.', 'error');
        return;
      }
      body.score = raw;
    }

    fbSetStatus('fb-respond-status', 'Sending…', '');
    try {
      const res = await apiFetch('feedback.php?action=submit-response', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error ?? 'Failed to submit');

      fbCloseRespondModal();

      // Replace card with thank-you message inline
      const card = document.getElementById(`ev-card-${id}`);
      if (card) {
        card.classList.add('fb-event-card-done');
        card.innerHTML = `
          <div class="fb-event-card-title">${esc(ev.title)}</div>
          <div class="fb-thankyou">
            <svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            Thank you for your feedback!
          </div>`;
      }
    } catch (err) {
      fbSetStatus('fb-respond-status', err.message, 'error');
    }
  }
