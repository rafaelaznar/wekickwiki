  // ═══════════════════════════════════════════════════════════════════════════
  // quests.js — inline app script
  // ═══════════════════════════════════════════════════════════════════════════

  // ── State ──────────────────────────────────────────────────────────────────
  let _editingQueryId  = null; // null = adding, number = editing
  let _editingQuestId  = null;
  let _deleteCallback  = null;
  let _wizardData      = null; // {attempt_id, quest_name, wrong, questions}
  let _wizardStep      = 0;
  let _wizardAnswers   = {};   // {question_id: answer}

  // ── Toast ──────────────────────────────────────────────────────────────────
  let _qsToastTimer;
  /**
   * Show a temporary toast notification.
   * @param {string} msg  - Message to display
   * @param {string} [type='success'] - CSS modifier ('success' | 'error')
   * @param {number} [ms=3200]  - Duration before auto-dismiss
   */
  function qsToast(msg, type = 'success', ms = 3200) {
    const el = document.getElementById('qs-toast');
    el.textContent = msg;
    el.className = 'show ' + type;
    clearTimeout(_qsToastTimer);
    _qsToastTimer = setTimeout(() => el.classList.remove('show'), ms);
  }

  /**
   * Set or clear an inline status element.
   * @param {string} id   - Element ID
   * @param {string} msg  - Message text (empty to hide)
   * @param {string} type - CSS class suffix ('ok' | 'err' | 'info' | 'success')
   */
  function qsSetStatus(id, msg, type) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'qs-status ' + type;
    el.style.display = msg ? '' : 'none';
  }

  // ── Auth & routing ─────────────────────────────────────────────────────────
  setOnUnauthorized(qsLogout);

  /** Clear session storage and redirect to the hub. */
  function qsLogout() {
    sessionStorage.clear();
    window.location.href = '../index.php';
  }

  /** Show the app chrome (header, screen) after successful auth. */
  function qsShowApp() {
    document.getElementById('qs-header').style.display = 'flex';
    document.getElementById('qs-screen').style.display = 'block';
    document.getElementById('qs-user-badge').textContent = getUser() + ' (' + getRole() + ')';
  }

  /**
   * Dispatch the user to either the admin panel (Questions/Quests/Results tabs)
   * or the guest panel (quest list + wizard) based on their role.
   */
  function qsRoute() {
    const role    = getRole();
    const themeBtn = document.getElementById('qs-theme-btn');
    if (themeBtn) themeBtn.style.display = role === 'admin' ? '' : 'none';
    if (role === 'admin') {
      document.getElementById('admin-panel').style.display = '';
      document.getElementById('user-panel').style.display  = 'none';
      qsShowTab('questions');
    } else {
      document.getElementById('admin-panel').style.display = 'none';
      document.getElementById('user-panel').style.display  = '';
      qsLoadUserHome();
    }
  }

  if (getToken()) { qsShowApp(); qsRoute(); }
  else { window.location.href = '../index.php'; }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── ADMIN — Tab switching ───────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  /**
   * Switch the active admin tab and trigger data loading for it.
   * @param {string} name - 'questions' | 'quests' | 'results'
   */
  function qsShowTab(name) {
      t.classList.toggle('active', t.dataset.tab === name);
    });
    document.querySelectorAll('.qs-tab-panel').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('tab-' + name);
    if (panel) panel.classList.add('active');
    if (name === 'questions') qsLoadQueries();
    if (name === 'quests')    qsLoadQuestsAdmin();
    if (name === 'results')   qsLoadResults();
  }

  // ── Score color helper ─────────────────────────────────────────────────────
  /**
   * Return a CSS class for colouring a score value.
   * @param {number|null} v - Score (0–10)
   * @returns {'score-high'|'score-mid'|'score-low'|''}
   */
  function scoreClass(v) {
    if (v === null || v === undefined) return '';
    if (v >= 5) return 'score-high';
    if (v >= 3) return 'score-mid';
    return 'score-low';
  }
  /**
   * Format a score as a 2-decimal string, or '—' if null.
   * @param {number|null} v
   * @returns {string}
   */
  function fmtScore(v) {
    if (v === null || v === undefined) return '—';
    return parseFloat(v).toFixed(2);
  }
  /**
   * Format an ISO timestamp string into a readable local date/time.
   * Falls back to the raw string if Date parsing fails.
   * @param {string|null} d - ISO timestamp
   * @returns {string}
   */
  function fmtDate(d) {
    if (!d) return '—';
    try { return new Date(d).toLocaleDateString(undefined, {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
    catch { return d; }
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── ADMIN — QUESTIONS tab ───────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  /**
   * Fetch all questions from the server, populate the label datalist, and apply filters.
   */
  async function qsLoadQueries() {
    const wrap = document.getElementById('queries-table-wrap');
    wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
    try {
      const res = await apiFetch('quests.php?action=get-queries');
      if (!res.ok) throw new Error('Failed to load questions');
      _allQueriesCache = await res.json();
      // Populate label datalist with all unique labels
      const allLabels = [...new Set(_allQueriesCache.flatMap(q => q.labels || []))].sort();
      const dl = document.getElementById('qf-label-list');
      if (dl) {
        dl.innerHTML = '';
        allLabels.forEach(l => { const o = document.createElement('option'); o.value = l; dl.appendChild(o); });
      }
      qsApplyQueryFilters();
    } catch (err) {
      document.getElementById('queries-table-wrap').innerHTML =
        `<p class="qs-load-error">${esc(err.message)}</p>`;
    }
  }

  /**
   * Filter the cached questions array using the current search/type/label inputs
   * and re-render the table.
   */
  function qsApplyQueryFilters() {
    const search = (document.getElementById('qf-search')?.value ?? '').toLowerCase().trim();
    const type   =  document.getElementById('qf-type')?.value  ?? '';
    const label  = (document.getElementById('qf-label')?.value  ?? '').toLowerCase().trim();

    const filtered = _allQueriesCache.filter(q => {
      if (type   && q.type !== type) return false;
      if (search && !q.query.toLowerCase().includes(search)) return false;
      if (label  && !(q.labels || []).some(l => l.toLowerCase().includes(label))) return false;
      return true;
    });

    const countEl = document.getElementById('qf-count');
    if (countEl) {
      const active = search || type || label;
      countEl.textContent = active ? `${filtered.length} / ${_allQueriesCache.length}` : '';
    }

    qsRenderQueriesTable(filtered);
  }

  /** Reset all question filter controls and re-render the full table. */
  function qsResetQueryFilters() {
    const type = document.getElementById('qf-type');
    const label = document.getElementById('qf-label');
    if (search) search.value = '';
    if (type) type.value = '';
    if (label) label.value = '';
    qsApplyQueryFilters();
  }

  /** Programmatically click the hidden file input to open the Moodle XML picker. */
  function qsPickMoodleXmlFile() {
    const input = document.getElementById('qm-xml-file');
    if (!input) return;
    input.value = '';
    input.click();
  }

  /**
   * Read the selected Moodle XML file and upload it to the server for import.
   * Reports counts of imported and skipped questions via toast.
   * @param {HTMLInputElement} input - The file input element
   */
  async function qsHandleMoodleXmlFile(input) {
    const file = input?.files?.[0];
    if (!file) return;

    qsSetStatus('queries-status', 'Importing XML…', 'info');

    const fd = new FormData();
    fd.append('file', file, file.name || 'questions.xml');

    try {
      const res = await apiFetch('quests.php?action=import-moodle-xml', {
        method: 'POST',
        body: fd
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok) {
        qsSetStatus('queries-status', '', '');
        qsToast(data.error || 'Import failed', 'error');
        return;
      }

      const imported = Number(data.imported ?? 0);
      const skipped = Number(data.skipped ?? 0);
      qsSetStatus('queries-status', `Imported ${imported} question${imported !== 1 ? 's' : ''}`, 'success');
      qsToast(`XML import completed: ${imported} imported, ${skipped} skipped`);
      qsLoadQueries();
    } catch (err) {
      qsSetStatus('queries-status', '', '');
      qsToast('Import failed', 'error');
    } finally {
      input.value = '';
    }
  }

  /**
   * Render the questions table with hit% and frequency% statistics badges.
   * Shows empty-state messages when the full cache or filtered set is empty.
   * @param {Array} queries - Filtered question objects to render
   */
  function qsRenderQueriesTable(queries) {
    const wrap = document.getElementById('queries-table-wrap');
    wrap.innerHTML = '';
    if (!_allQueriesCache.length) {
      wrap.innerHTML = '<p class="qs-empty">No questions yet. Click “Add question” to create one.</p>';
      return;
    }
    if (!queries.length) {
      wrap.innerHTML = '<p class="qs-empty">No questions match the current filters.</p>';
      return;
    }
    const table = document.createElement('table');
    table.className = 'qs-table';
    table.innerHTML = `<thead><tr>
      <th class="qs-th-id">ID</th>
      <th>Question</th>
      <th>Type</th>
      <th>Labels</th>
      <th class="qs-th-stat" title="% answered correctly out of answered (excl. skipped)">Hit %</th>
      <th class="qs-th-stat" title="% of total question appearances across all attempts">Freq %</th>
      <th class="qs-th-actions-sm">Actions</th>
    </tr></thead>`;
    const tbody = document.createElement('tbody');
    for (const q of queries) {
      const tr = document.createElement('tr');
      const labelsHtml = (q.labels || []).map(l => `<span class="badge badge-label">${esc(l)}</span>`).join(' ');
      const st  = q.stats || {};
      const hitPct  = st.success_pct    != null ? st.success_pct    + '%' : '—';
      const freqPct = st.appearance_pct != null ? st.appearance_pct + '%' : '—';
      const hitCls  = st.success_pct != null
        ? (st.success_pct >= 70 ? 'score-high' : st.success_pct >= 40 ? 'score-mid' : 'score-low')
        : '';
      tr.innerHTML = `
        <td class="qs-id-cell">${q.id}</td>
        <td class="qs-query-text-cell" title="${esc(q.query)}">${esc(q.query)}</td>
        <td><span class="badge badge-type">${esc(q.type)}</span></td>
        <td>${labelsHtml || '<span class="qs-empty-val">—</span>'}</td>
        <td class="qs-center-td" title="${st.correct ?? 0} correct / ${st.wrong ?? 0} wrong of ${st.appeared ?? 0} shown"><span class="${hitCls}">${hitPct}</span></td>
        <td class="qs-center-td" title="${st.appeared ?? 0} appearances">${freqPct}</td>
        <td>
          <button class="btn btn-sm" onclick="qsEditQuery(${q.id})">Edit</button>
          <button class="btn btn-sm btn-danger" onclick="qsConfirmDeleteQuery(${q.id})">Del</button>
        </td>`;
      tbody.appendChild(tr);
    }
    table.appendChild(tbody);
    wrap.appendChild(table);
  }

  // ── Query modal ────────────────────────────────────────────────────────────
  let _allQueriesCache = [];

  /**
   * Open the add/edit question modal, pre-filling fields from queryData.
   * @param {Object|null} queryData - Existing question to edit, or null to create
   */
  function qsOpenQueryModal(queryData = null) {
    _editingQueryId = queryData ? queryData.id : null;
    document.getElementById('query-modal-title').textContent = queryData ? 'Edit question' : 'Add question';
    document.getElementById('qm-type').value   = queryData ? queryData.type : 'multiple_choice';
    document.getElementById('qm-query').value  = queryData ? queryData.query : '';
    document.getElementById('qm-labels').value = queryData ? (queryData.labels || []).join(', ') : '';
    qsRenderQueryTypeFields(queryData);
    document.getElementById('query-modal-overlay').classList.add('open');
  }

  /** Close the question creation/edit modal. */
  function qsCloseQueryModal() {
    document.getElementById('query-modal-overlay').classList.remove('open');
  }

  /**
   * Fetch the latest question data for the given ID and open the edit modal.
   * @param {number} id - Question ID to edit
   */
  async function qsEditQuery(id) {
    // Reload fresh data
    const res = await apiFetch('quests.php?action=get-queries');
    if (!res.ok) { qsToast('Failed to load question', 'error'); return; }
    const queries = await res.json();
    const q = queries.find(x => x.id === id);
    if (!q) { qsToast('Question not found', 'error'); return; }
    qsOpenQueryModal(q);
  }

  /**
   * Render type-specific form fields inside the question modal.
   * Branches on the current <select> value: multiple_choice, binary, gap_filling, matching.
   * @param {Object|null} data - Existing question data for pre-fill, or null
   */
  function qsRenderQueryTypeFields(data = null) {
    const type   = document.getElementById('qm-type').value;
    const wrap   = document.getElementById('qm-type-fields');
    wrap.innerHTML = '';

    if (type === 'multiple_choice') {
      const opts = data?.options || ['', '', '', ''];
      const ans  = data?.answer ?? '0';
      let html = `<div class="qs-field"><label>Options <span class="qs-label-hint">(mark correct with radio)</span></label><ul class="qs-options-list" id="mc-options-list">`;
      opts.forEach((o, i) => {
        html += `<li>
          <input type="radio" name="mc-correct" value="${i}" ${parseInt(ans) === i ? 'checked' : ''} title="Correct answer">
          <input type="text" class="mc-opt-input" value="${esc(o)}" placeholder="Option ${i+1}">
          <button class="btn btn-sm btn-ghost" onclick="qsRemoveMcOption(this)" type="button">✕</button>
        </li>`;
      });
      html += `</ul><button class="btn btn-sm" onclick="qsAddMcOption()" type="button">+ Add option</button></div>`;
      wrap.innerHTML = html;

    } else if (type === 'binary') {
      const ans = data?.answer ?? '1';
      wrap.innerHTML = `<div class="qs-field"><label>Correct answer</label>
        <select id="binary-answer">
          <option value="1" ${ans==='1'?'selected':''}>Yes / True</option>
          <option value="0" ${ans==='0'?'selected':''}>No / False</option>
        </select></div>`;

    } else if (type === 'gap_filling') {
      const opts = data?.options || [''];
      let html = `<div class="qs-field"><label>Accepted answers <span class="qs-label-hint">(all are correct, case-insensitive)</span></label><ul class="qs-options-list" id="gf-options-list">`;
      opts.forEach(o => {
        html += `<li><input type="text" class="gf-opt-input" value="${esc(o)}" placeholder="Accepted answer…">
          <button class="btn btn-sm btn-ghost" onclick="this.closest('li').remove()" type="button">✕</button></li>`;
      });
      html += `</ul><button class="btn btn-sm" onclick="qsAddGfOption()" type="button">+ Add accepted answer</button></div>`;
      wrap.innerHTML = html;

    } else if (type === 'matching') {
      const opts = data?.options || {};
      const pairs = Object.entries(opts).length ? Object.entries(opts) : [['', '']];
      let html = `<div class="qs-field"><label>Key → Value pairs</label><ul class="qs-matching-list" id="mt-pairs-list">`;
      pairs.forEach(([k, v]) => {
        html += `<li>
          <input type="text" class="mt-key" value="${esc(k)}" placeholder="Key (left)">
          <span class="qs-matching-sep">→</span>
          <input type="text" class="mt-val" value="${esc(v)}" placeholder="Value (right)">
          <button class="btn btn-sm btn-ghost" onclick="this.closest('li').remove()" type="button">✕</button>
        </li>`;
      });
      html += `</ul><button class="btn btn-sm" onclick="qsAddMtPair()" type="button">+ Add pair</button></div>`;
      wrap.innerHTML = html;
    }
  }

  /** Append a new blank option row to the multiple-choice options list. */
  function qsAddMcOption() {
    const list = document.getElementById('mc-options-list');
    const idx  = list.querySelectorAll('li').length;
    const li   = document.createElement('li');
    li.innerHTML = `<input type="radio" name="mc-correct" value="${idx}" title="Correct answer">
      <input type="text" class="mc-opt-input" placeholder="Option ${idx+1}">
      <button class="btn btn-sm btn-ghost" onclick="qsRemoveMcOption(this)" type="button">✕</button>`;
    list.appendChild(li);
  }

  /**
   * Remove an option row from the multiple-choice list and re-index radio values.
   * @param {HTMLButtonElement} btn - The remove button clicked
   */
  function qsRemoveMcOption(btn) {
    const li   = btn.closest('li');
    const list = li.closest('ul');
    li.remove();
    // Re-index radio values
    list.querySelectorAll('li').forEach((l, i) => {
      const r = l.querySelector('input[type="radio"]');
      if (r) r.value = i;
    });
  }

  /** Append a new blank accepted-answer row to the gap-filling list. */
  function qsAddGfOption() {
    const list = document.getElementById('gf-options-list');
    const li   = document.createElement('li');
    li.innerHTML = `<input type="text" class="gf-opt-input" placeholder="Accepted answer…">
      <button class="btn btn-sm btn-ghost" onclick="this.closest('li').remove()" type="button">✕</button>`;
    list.appendChild(li);
  }

  /** Append a new blank key→value pair row to the matching pairs list. */
  function qsAddMtPair() {
    const list = document.getElementById('mt-pairs-list');
    const li   = document.createElement('li');
    li.innerHTML = `<input type="text" class="mt-key" placeholder="Key (left)">
      <span class="qs-matching-sep">→</span>
      <input type="text" class="mt-val" placeholder="Value (right)">
      <button class="btn btn-sm btn-ghost" onclick="this.closest('li').remove()" type="button">✕</button>`;
    list.appendChild(li);
  }

  /**
   * Validate and POST the current question modal form as a create or update request.
   * Collects type-specific answer data before sending.
   */
  async function qsSaveQuery() {
    const type   = document.getElementById('qm-type').value;
    const query  = document.getElementById('qm-query').value.trim();
    const labels = document.getElementById('qm-labels').value.split(',').map(s => s.trim()).filter(Boolean);

    if (!query) { qsToast('Question text is required', 'error'); return; }

    const body = { type, query, labels };
    if (_editingQueryId !== null) body.id = _editingQueryId;

    if (type === 'multiple_choice') {
      const opts    = [...document.querySelectorAll('.mc-opt-input')].map(i => i.value.trim());
      const correct = document.querySelector('input[name="mc-correct"]:checked')?.value ?? '0';
      body.options = opts;
      body.answer  = correct;
    } else if (type === 'binary') {
      body.answer = document.getElementById('binary-answer').value;
    } else if (type === 'gap_filling') {
      body.options = [...document.querySelectorAll('.gf-opt-input')].map(i => i.value.trim()).filter(Boolean);
    } else if (type === 'matching') {
      const opts = {};
      document.querySelectorAll('#mt-pairs-list li').forEach(li => {
        const k = li.querySelector('.mt-key')?.value.trim();
        const v = li.querySelector('.mt-val')?.value.trim();
        if (k) opts[k] = v || '';
      });
      body.options = opts;
    }

    const res = await apiFetch('quests.php?action=save-query', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      qsCloseQueryModal();
      qsToast(_editingQueryId ? 'Question updated' : 'Question added');
      qsLoadQueries();
    } else {
      qsToast(data.error || 'Save failed', 'error');
    }
  }

  /**
   * Set up the generic confirm-delete modal and hook it to the delete-query endpoint.
   * @param {number} id - Question ID to delete
   */
  function qsConfirmDeleteQuery(id) {
    _deleteCallback = async () => {
      const res  = await apiFetch('quests.php?action=delete-query', {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
      });
      const data = await res.json().catch(() => ({}));
      qsCloseDeleteModal();
      if (res.ok) { qsToast('Question deleted'); qsLoadQueries(); }
      else qsToast(data.error || 'Delete failed', 'error');
    };
    document.getElementById('delete-modal-title').textContent = 'Delete question';
    document.getElementById('delete-modal-msg').textContent   = `Delete question #${id}? This cannot be undone.`;
    document.getElementById('delete-modal-confirm').onclick   = _deleteCallback;
    document.getElementById('delete-modal-overlay').classList.add('open');
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── ADMIN — QUESTS tab ──────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  let _questsAllItems = [];

  /**
   * Return the abbreviated month name for a 1-based month number.
   * @param {number} monthNum - Month (1–12)
   * @returns {string}
   */
  function qsMonthName(monthNum) {
    const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return names[monthNum - 1] || String(monthNum);
  }

  /**
   * Populate the month/year filter <select> elements from the full quests list,
   * preserving the currently selected values where possible.
   * @param {Array} items - All quest objects
   */
  function qsPopulateQuestFilters(items) {
    const monthSel = document.getElementById('qsf-month');
    const yearSel  = document.getElementById('qsf-year');
    if (!monthSel || !yearSel) return;

    const keepMonth = monthSel.value;
    const keepYear  = yearSel.value;

    const months = [...new Set(items.map(q => {
      const d = String(q.date ?? '');
      return d.length >= 7 ? d.slice(0, 7) : '';
    }).filter(Boolean))].sort((a, b) => b.localeCompare(a));
    const years = [...new Set(items.map(q => {
      const d = String(q.date ?? '');
      return d.length >= 4 ? d.slice(0, 4) : '';
    }).filter(Boolean))].sort((a, b) => b.localeCompare(a));

    monthSel.innerHTML = '<option value="">All months</option>'
      + months.map(m => {
        const monthNum = parseInt(m.slice(5, 7), 10);
        const yearNum  = m.slice(0, 4);
        const label = `${qsMonthName(monthNum)} ${yearNum}`;
        return `<option value="${esc(m)}">${esc(label)}</option>`;
      }).join('');
    yearSel.innerHTML = '<option value="">All years</option>'
      + years.map(y => `<option value="${esc(y)}">${esc(y)}</option>`).join('');

    monthSel.value = months.includes(keepMonth) ? keepMonth : '';
    yearSel.value  = years.includes(keepYear) ? keepYear : '';
  }

  /**
   * Render the quests admin table for the given (filtered) quests.
   * Edit/Delete buttons are hidden for quests that already have attempts.
   * @param {Array} quests - Quest objects to render
   */
  function qsRenderQuestsAdminTable(quests) {
    const wrap = document.getElementById('quests-admin-table-wrap');
    if (!wrap) return;

    wrap.innerHTML = '';
    if (!quests.length) {
      wrap.innerHTML = '<p class="qs-empty">No quests match the selected filters.</p>';
      return;
    }

    const table = document.createElement('table');
    table.className = 'qs-table';
    table.innerHTML = `<thead><tr>
      <th>Name</th><th>Date</th><th>Status</th><th>Wrong</th><th>Revisable</th><th>Allowed</th><th>Attempts</th><th class="qs-th-avgscore">Avg score</th><th class="qs-th-actions-md">Actions</th>
    </tr></thead>`;
    const tbody = document.createElement('tbody');
    for (const q of quests) {
      const hasAttempts = (q.attempt_count ?? 0) > 0;
      const allowedVal  = q.allowed ?? ['all'];
      const allowedHtml = allowedVal.includes('all')
        ? '<span class="badge badge-open">All</span>'
        : allowedVal.map(u => `<span class="badge badge-label">${esc(u)}</span>`).join(' ');
      const allowedJson = JSON.stringify(allowedVal).replace(/'/g, '&#39;');
      const editDel = hasAttempts ? '' :
        `<button class="btn btn-sm" onclick="qsEditQuest(${q.id})">Edit</button>
        <button class="btn btn-sm btn-danger" onclick="qsConfirmDeleteQuest(${q.id})">Del</button>`;
      const actionsHtml = editDel +
        `<button class="btn btn-sm" onclick='qsOpenAccessModal(${q.id}, ${allowedJson})'>Access</button>`;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><strong>${esc(q.name)}</strong></td>
        <td>${esc(q.date || '—')}</td>
        <td><span class="badge badge-${q.status}">${esc(q.status)}</span></td>
        <td>${q.wrong < 0 ? q.wrong : '—'}</td>
        <td>${q.revisable ? '✔' : '—'}</td>
        <td>${allowedHtml}</td>
        <td class="qs-center-td">${q.attempt_count ?? 0}</td>
        <td class="qs-center-td"><span class="${scoreClass(q.avg_score)}">${fmtScore(q.avg_score)}</span></td>
        <td>${actionsHtml}</td>`;
      tbody.appendChild(tr);
    }
    table.appendChild(tbody);
    wrap.appendChild(table);
  }

  /**
   * Filter the cached quests list using the current status/attempts/score/date controls
   * and re-render. Shows count and grouped results.
   */
  function qsApplyQuestFilters() {
    const status = document.getElementById('qsf-status')?.value ?? '';
    const attemptsFilter = document.getElementById('qsf-attempts')?.value ?? '';
    const avgScoreFilter = document.getElementById('qsf-avgscore')?.value ?? '';
    const month  = document.getElementById('qsf-month')?.value ?? ''; // YYYY-MM
    const year   = document.getElementById('qsf-year')?.value ?? '';  // YYYY
    const countEl = document.getElementById('qsf-count');

    const filtered = _questsAllItems.filter(q => {
      if (status && String(q.status ?? '') !== status) return false;

      const attempts = Number(q.attempt_count ?? 0);
      if (attemptsFilter === '0' && attempts !== 0) return false;
      if (attemptsFilter === 'gt0' && !(attempts > 0)) return false;

      const avgScore = Number(q.avg_score ?? NaN);
      if (avgScoreFilter === 'lt5' && !(Number.isFinite(avgScore) && avgScore < 5)) return false;
      if (avgScoreFilter === 'ge5' && !(Number.isFinite(avgScore) && avgScore >= 5)) return false;

      const d = String(q.date ?? '');
      if (month && !d.startsWith(month)) return false;
      if (year && !d.startsWith(year)) return false;
      return true;
    }).sort((a, b) => {
      const cmpDate = String(b.date ?? '').localeCompare(String(a.date ?? ''));
      if (cmpDate !== 0) return cmpDate;
      return Number(b.id ?? 0) - Number(a.id ?? 0);
    });

    if (countEl) countEl.textContent = `${filtered.length} quest${filtered.length !== 1 ? 's' : ''}`;
    qsRenderQuestsAdminTable(filtered);
  }

  /** Reset all quest filter controls and re-render the full list. */
  function qsResetQuestFilters() {
    const attempts = document.getElementById('qsf-attempts');
    const avgscore = document.getElementById('qsf-avgscore');
    const month = document.getElementById('qsf-month');
    const year = document.getElementById('qsf-year');
    if (status) status.value = '';
    if (attempts) attempts.value = '';
    if (avgscore) avgscore.value = '';
    if (month) month.value = '';
    if (year) year.value = '';
    qsApplyQuestFilters();
  }

  /**
   * Fetch all quests with attempt counts from the server, populate filters, and render.
   */
  async function qsLoadQuestsAdmin() {
    const wrap = document.getElementById('quests-admin-table-wrap');
    wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
    try {
      const res    = await apiFetch('quests.php?action=get-quests-admin');
      if (!res.ok) throw new Error('Failed to load quests');
      const quests = await res.json();
      _questsAllItems = Array.isArray(quests) ? quests : [];
      qsPopulateQuestFilters(_questsAllItems);
      qsApplyQuestFilters();
    } catch (err) {
      wrap.innerHTML = `<p class="qs-load-error">${esc(err.message)}</p>`;
      const countEl = document.getElementById('qsf-count');
      if (countEl) countEl.textContent = '';
    }
  }

  // ── Quest modal ────────────────────────────────────────────────────────────
  /**
   * Open the add/edit quest modal, pre-filling all fields from questData.
   * Renders label groups and the allowed-users field.
   * @param {Object|null} questData - Existing quest to edit, or null to create
   */
  async function qsOpenQuestModal(questData = null) {
    _editingQuestId = questData ? questData.id : null;
    document.getElementById('quest-modal-title').textContent  = questData ? 'Edit quest' : 'Add quest';
    document.getElementById('qst-name').value      = questData?.name ?? '';
    document.getElementById('qst-date').value      = questData?.date ?? new Date().toISOString().slice(0,10);
    document.getElementById('qst-status').value    = questData?.status ?? 'closed';
    document.getElementById('qst-wrong').value     = questData?.wrong ?? 0;
    document.getElementById('qst-revisable').value = questData?.revisable ? '1' : '0';

    // Render label groups
    const groups = questData?.queries || [{labels:[], queries:1}];
    const wrap   = document.getElementById('qst-label-groups');
    wrap.innerHTML = '';
    groups.forEach(g => qsAddLabelGroup(g));

    // Render allowed field
    await qsRenderAllowedField('qst', questData?.allowed ?? ['all']);

    document.getElementById('quest-modal-overlay').classList.add('open');
  }

  /** Close the quest creation/edit modal. */
  function qsCloseQuestModal() {
    document.getElementById('quest-modal-overlay').classList.remove('open');
  }

  /**
   * Fetch fresh quest data for the given ID and open the edit modal.
   * @param {number} id - Quest ID
   */
  async function qsEditQuest(id) {
    const res = await apiFetch('quests.php?action=get-quests-admin');
    if (!res.ok) { qsToast('Failed to load quest', 'error'); return; }
    const quests = await res.json();
    const q  = quests.find(x => x.id === id);
    if (!q) { qsToast('Quest not found', 'error'); return; }
    qsOpenQuestModal(q);
  }

  /**
   * Append a new label-group row to the quest modal label groups container.
   * @param {Object|null} data - Existing group data to pre-fill ({labels, queries}), or null
   */
  function qsAddLabelGroup(data = null) {
    const wrap = document.getElementById('qst-label-groups');
    const div  = document.createElement('div');
    div.className = 'qs-label-group';
    const labels = data?.labels?.join(', ') ?? '';
    const count  = data?.queries ?? 1;
    div.innerHTML = `
      <input type="text" class="qs-label-group-label-input" placeholder="Labels (comma-separated, AND)" value="${esc(labels)}">
      <input type="number" class="qs-label-group-qty" min="1" value="${count}" title="Number of questions">
      <span class="qs-qty-label">questions</span>
      <button class="btn btn-sm btn-ghost" onclick="this.closest('.qs-label-group').remove()" type="button">✕</button>`;
    wrap.appendChild(div);
  }

  /**
   * Validate and POST the quest form as a create or update request.
   * Collects label groups and the allowed-users configuration.
   */
  async function qsSaveQuest() {
    const name     = document.getElementById('qst-name').value.trim();
    const date     = document.getElementById('qst-date').value;
    const status   = document.getElementById('qst-status').value;
    const wrong    = parseFloat(document.getElementById('qst-wrong').value) || 0;
    const revisable= document.getElementById('qst-revisable').value === '1';

    if (!name) { qsToast('Quest name is required', 'error'); return; }

    const groups = [];
    document.querySelectorAll('#qst-label-groups .qs-label-group').forEach(div => {
      const inputs = div.querySelectorAll('input');
      const labels = inputs[0].value.split(',').map(s=>s.trim()).filter(Boolean);
      const count  = parseInt(inputs[1].value) || 1;
      groups.push({ labels, queries: count });
    });

    // Read allowed field
    const allowedMode = document.querySelector('input[name="qst-allowed-mode"]:checked')?.value;
    let allowed;
    if (allowedMode === 'specific') {
      allowed = [...document.querySelectorAll('input[name="qst-user-cb"]:checked')].map(cb => cb.value);
      if (!allowed.length) allowed = ['all'];
    } else {
      allowed = ['all'];
    }

    const body = { name, date, status, wrong, revisable, allowed, queries: groups };
    if (_editingQuestId !== null) body.id = _editingQuestId;

    const res  = await apiFetch('quests.php?action=save-quest', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      qsCloseQuestModal();
      qsToast(_editingQuestId ? 'Quest updated' : 'Quest added');
      qsLoadQuestsAdmin();
    } else {
      qsToast(data.error || 'Save failed', 'error');
    }
  }

  /**
   * Open the confirm-delete modal targeting the delete-quest endpoint.
   * @param {number} id - Quest ID
   */
  function qsConfirmDeleteQuest(id) {
    _deleteCallback = async () => {
      const res  = await apiFetch('quests.php?action=delete-quest', {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
      });
      const data = await res.json().catch(() => ({}));
      qsCloseDeleteModal();
      if (res.ok) { qsToast('Quest deleted'); qsLoadQuestsAdmin(); }
      else qsToast(data.error || 'Delete failed', 'error');
    };
    document.getElementById('delete-modal-title').textContent = 'Delete quest';
    document.getElementById('delete-modal-msg').textContent   = `Delete quest #${id}? All associated data will remain in the attempts file.`;
    document.getElementById('delete-modal-confirm').onclick   = _deleteCallback;
    document.getElementById('delete-modal-overlay').classList.add('open');
  }

  /**
   * Open the confirm-delete modal targeting the delete-attempt endpoint.
   * @param {number} id - Attempt ID
   */
  function qsConfirmDeleteAttempt(id) {
    _deleteCallback = async () => {
      const res  = await apiFetch('quests.php?action=delete-attempt', {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
      });
      const data = await res.json().catch(() => ({}));
      qsCloseDeleteModal();
      if (res.ok) { qsToast('Attempt deleted'); qsLoadResults(); }
      else qsToast(data.error || 'Delete failed', 'error');
    };
    document.getElementById('delete-modal-title').textContent = 'Delete attempt';
    document.getElementById('delete-modal-msg').textContent   = `Delete attempt #${id}? This cannot be undone.`;
    document.getElementById('delete-modal-confirm').onclick   = _deleteCallback;
    document.getElementById('delete-modal-overlay').classList.add('open');
  }

  /**
   * Load an attempt for admin review and switch to the review step-through UI.
   * Replaces the back-button target with the Results tab.
   * @param {number} attemptId
   */
  async function qsAdminReviewAttempt(attemptId) {
    const res  = await apiFetch(`quests.php?action=review-attempt&id=${attemptId}`);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { qsToast(data.error || 'Failed to load review', 'error'); return; }

    _reviewData    = data;
    _reviewStep    = 0;
    _reviewIsAdmin = true;
    _reviewBackFn  = () => {
      document.getElementById('admin-panel').style.display = '';
      document.getElementById('user-panel').style.display  = 'none';
      qsShowTab('results');
    };
    document.getElementById('admin-panel').style.display = 'none';
    document.getElementById('user-panel').style.display  = '';
    qsUserShowView('review');
    qsRenderReviewStep();
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── ADMIN — Allowed users helpers ───────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  /**
   * Cached enabled guest users for the allowed-users checkboxes.
   * Lazy-loaded by qsGetQuestUsers().
   */
  let _questUsersCache = null;

  /**
   * Return the list of eligible guest users for quest access control.
   * Results are cached in _questUsersCache after the first request.
   * @returns {Promise<Array>}
   */
  async function qsGetQuestUsers() {
    if (_questUsersCache) return _questUsersCache;
    const res = await apiFetch('quests.php?action=get-quest-users');
    if (res.ok) _questUsersCache = await res.json();
    return _questUsersCache || [];
  }

  /**
   * Render the allowed-users radio+checkboxes into the element with id `${prefix}-allowed-wrap`.
   * prefix is 'qst' (quest modal) or 'acc' (access modal).
   */
  async function qsRenderAllowedField(prefix, allowed) {
    const wrap  = document.getElementById(prefix + '-allowed-wrap');
    if (!wrap) return;
    const isAll = (allowed ?? ['all']).includes('all');
    const users = await qsGetQuestUsers();

    let html = `
      <div class="qs-allowed-mode">
        <label><input type="radio" name="${prefix}-allowed-mode" value="all" ${isAll ? 'checked' : ''}
          onchange="qsToggleAllowedUsers('${prefix}')"> All guest users</label>
        <label><input type="radio" name="${prefix}-allowed-mode" value="specific" ${!isAll ? 'checked' : ''}
          onchange="qsToggleAllowedUsers('${prefix}')"> Specific users</label>
      </div>
      <div id="${prefix}-allowed-users" class="qs-allowed-users-list" ${isAll ? 'style="display:none"' : ''}>`;
    if (!users.length) {
      html += '<p class="qs-empty">No guest users found.</p>';
    } else {
      for (const u of users) {
        const checked = !isAll && (allowed ?? []).includes(u.username);
        html += `<label class="qs-allowed-user">
          <input type="checkbox" name="${prefix}-user-cb" value="${esc(u.username)}" ${checked ? 'checked' : ''}>
          ${esc(u.name || u.username)}
          <span class="qs-allowed-uname">(${esc(u.username)})</span>
        </label>`;
      }
    }
    html += '</div>';
    wrap.innerHTML = html;
  }

  /**
   * Show or hide the specific-users checkbox list based on the selected radio.
   * @param {string} prefix - 'qst' or 'acc'
   */
  function qsToggleAllowedUsers(prefix) {
    const mode = document.querySelector(`input[name="${prefix}-allowed-mode"]:checked`)?.value;
    const usersDiv = document.getElementById(prefix + '-allowed-users');
    if (usersDiv) usersDiv.style.display = mode === 'specific' ? '' : 'none';
  }

  // ── Access modal (for managing allowed on quests with attempts) ─────────────
  let _accessModalQuestId = null;

  /**
   * Open the access modal for a quest that already has attempts (edit/delete disabled).
   * @param {number}   id             - Quest ID
   * @param {string[]} currentAllowed - Current allowed value (['all'] or username list)
   */
  async function qsOpenAccessModal(id, currentAllowed) {
    _accessModalQuestId = id;
    document.getElementById('access-modal-title').textContent = 'Manage access — quest #' + id;
    document.getElementById('access-modal-content').innerHTML =
      '<div id="acc-allowed-wrap"><div class="qs-loading"><div class="qs-spinner"></div> Loading…</div></div>';
    document.getElementById('access-modal-overlay').classList.add('open');
    await qsRenderAllowedField('acc', currentAllowed);
  }

  /** Close the quest access modal and reset the stored quest ID. */
  function qsCloseAccessModal() {
    document.getElementById('access-modal-overlay').classList.remove('open');
    _accessModalQuestId = null;
  }

  /**
   * Persist the allowed-users selection from the access modal to the server.
   */
  async function qsSaveAccess() {
    const mode = document.querySelector('input[name="acc-allowed-mode"]:checked')?.value;
    let allowed;
    if (mode === 'specific') {
      allowed = [...document.querySelectorAll('input[name="acc-user-cb"]:checked')].map(cb => cb.value);
      if (!allowed.length) allowed = ['all'];
    } else {
      allowed = ['all'];
    }
    const res  = await apiFetch('quests.php?action=save-quest-allowed', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ id: _accessModalQuestId, allowed })
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      qsCloseAccessModal();
      qsToast('Access updated');
      qsLoadQuestsAdmin();
    } else {
      qsToast(data.error || 'Save failed', 'error');
    }
  }

  document.getElementById('access-modal-overlay').addEventListener('click', e => {
    if (e.target === document.getElementById('access-modal-overlay')) qsCloseAccessModal();
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // ── ADMIN — RESULTS tab ─────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  let _resultsAllItems = [];

  /**
   * Populate the results filter <select> elements from all attempt data,
   * preserving current selections.
   * @param {Array} items - All attempt objects
   */
  function qsPopulateResultsFilters(items) {
    const questSel = document.getElementById('rf-quest');
    const userSel  = document.getElementById('rf-user');
    const monthSel = document.getElementById('rf-month');
    const yearSel  = document.getElementById('rf-year');
    if (!questSel || !userSel || !monthSel || !yearSel) return;

    const keepQuest = questSel.value;
    const keepUser  = userSel.value;
    const keepMonth = monthSel.value;
    const keepYear  = yearSel.value;

    const quests = [...new Set(items.map(a => String(a.quest_name ?? '')).filter(Boolean))].sort((a, b) => a.localeCompare(b));
    const users  = [...new Set(items.map(a => String(a.username ?? '')).filter(Boolean))].sort((a, b) => a.localeCompare(b));
    const months = [...new Set(items.map(a => {
      const d = String(a.submitted_at ?? '');
      return d.length >= 7 ? d.slice(0, 7) : '';
    }).filter(Boolean))].sort((a, b) => b.localeCompare(a));
    const years  = [...new Set(items.map(a => {
      const d = String(a.submitted_at ?? '');
      return d.length >= 4 ? d.slice(0, 4) : '';
    }).filter(Boolean))].sort((a, b) => b.localeCompare(a));

    questSel.innerHTML = '<option value="">All quests</option>'
      + quests.map(q => `<option value="${esc(q)}">${esc(q)}</option>`).join('');
    userSel.innerHTML = '<option value="">All users</option>'
      + users.map(u => `<option value="${esc(u)}">${esc(u)}</option>`).join('');
    monthSel.innerHTML = '<option value="">All months</option>'
      + months.map(m => {
        const monthNum = parseInt(m.slice(5, 7), 10);
        const yearNum  = m.slice(0, 4);
        const label = `${qsMonthName(monthNum)} ${yearNum}`;
        return `<option value="${esc(m)}">${esc(label)}</option>`;
      }).join('');
    yearSel.innerHTML = '<option value="">All years</option>'
      + years.map(y => `<option value="${esc(y)}">${esc(y)}</option>`).join('');

    questSel.value = quests.includes(keepQuest) ? keepQuest : '';
    userSel.value  = users.includes(keepUser) ? keepUser : '';
    monthSel.value = months.includes(keepMonth) ? keepMonth : '';
    yearSel.value  = years.includes(keepYear) ? keepYear : '';
  }

  /**
   * Filter and render the results table, grouping rows by quest and displaying
   * aggregate average score in the counter.
   */
  function qsApplyResultsFilters() {
    const wrap = document.getElementById('results-table-wrap');
    const countEl = document.getElementById('rf-count');
    if (!wrap) return;

    const quest = document.getElementById('rf-quest')?.value ?? '';
    const user  = document.getElementById('rf-user')?.value ?? '';
    const month = document.getElementById('rf-month')?.value ?? ''; // YYYY-MM
    const year  = document.getElementById('rf-year')?.value ?? '';  // YYYY
    const score = document.getElementById('rf-score')?.value ?? '';

    const filtered = _resultsAllItems.filter(a => {
      if (quest && String(a.quest_name ?? '') !== quest) return false;
      if (user && String(a.username ?? '') !== user) return false;

      const submitted = String(a.submitted_at ?? '');
      if (month && !submitted.startsWith(month)) return false;
      if (year && !submitted.startsWith(year)) return false;

      const sc = Number(a.score ?? NaN);
      if (score === 'lt5' && !(sc < 5)) return false;
      if (score === 'ge5' && !(sc >= 5)) return false;
      return true;
    }).sort((a, b) => String(b.submitted_at ?? '').localeCompare(String(a.submitted_at ?? '')));

    wrap.innerHTML = '';
    if (countEl) {
      const scores = filtered.map(a => Number(a.score)).filter(s => !isNaN(s));
      const avgTxt = scores.length
        ? ` — avg: <span class="${scoreClass(scores.reduce((s, v) => s + v, 0) / scores.length)}">${fmtScore(scores.reduce((s, v) => s + v, 0) / scores.length)}</span>`
        : '';
      countEl.innerHTML = `${filtered.length} result${filtered.length !== 1 ? 's' : ''}${avgTxt}`;
    }
    if (!filtered.length) {
      wrap.innerHTML = '<p class="qs-empty">No results match the selected filters.</p>';
      return;
    }

    // Group by quest_id preserving first appearance in date-desc list
    const groupMap = new Map();
    for (const a of filtered) {
      if (!groupMap.has(a.quest_id)) groupMap.set(a.quest_id, { quest_name: a.quest_name, attempts: [] });
      groupMap.get(a.quest_id).attempts.push(a);
    }
    const groups = [...groupMap.values()];

    for (const group of groups) {
      const hdr = document.createElement('div');
      hdr.className = 'qs-results-group-hdr';
      hdr.innerHTML = `<strong class="qs-results-quest-name">${esc(group.quest_name)}</strong>`
        + `<span class="qs-results-attempt-count">${group.attempts.length} attempt${group.attempts.length !== 1 ? 's' : ''}</span>`;
      wrap.appendChild(hdr);

      const table = document.createElement('table');
      table.className = 'qs-table';
      table.innerHTML = `<thead><tr>
        <th>Quest</th><th>User</th><th>Date</th><th class="qs-th-score">Score / 10</th><th class="qs-th-actions-lg">Actions</th>
      </tr></thead>`;
      const tbody = document.createElement('tbody');
      for (const a of group.attempts) {
        const sc = a.score;
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${esc(a.quest_name)}</strong></td>
          <td><strong>${esc(a.username)}</strong></td>
          <td class="qs-date-td">${fmtDate(a.submitted_at)}</td>
          <td class="qs-center-td"><span class="${scoreClass(sc)}">${fmtScore(sc)}</span></td>
          <td>
            <button class="btn btn-sm" onclick="qsAdminReviewAttempt(${a.id})">Review</button>
            <button class="btn btn-sm btn-danger" onclick="qsConfirmDeleteAttempt(${a.id})">Del</button>
          </td>`;
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      wrap.appendChild(table);
    }
  }

  /** Reset all results filter controls and re-render. */
  function qsResetResultsFilters() {
    const user = document.getElementById('rf-user');
    const month = document.getElementById('rf-month');
    const year = document.getElementById('rf-year');
    const score = document.getElementById('rf-score');
    if (quest) quest.value = '';
    if (user) user.value = '';
    if (month) month.value = '';
    if (year) year.value = '';
    if (score) score.value = '';
    qsApplyResultsFilters();
  }

  /**
   * Fetch all attempt records from the server, populate filters, and render grouped.
   */
  async function qsLoadResults() {
    const wrap = document.getElementById('results-table-wrap');
    wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
    try {
      const res   = await apiFetch('quests.php?action=get-all-attempts');
      if (!res.ok) throw new Error('Failed to load results');
      const items = await res.json();
      _resultsAllItems = (Array.isArray(items) ? items : []).sort((a, b) =>
        String(b.submitted_at ?? '').localeCompare(String(a.submitted_at ?? ''))
      );

      qsPopulateResultsFilters(_resultsAllItems);
      qsApplyResultsFilters();
    } catch (err) {
      wrap.innerHTML = `<p class="qs-load-error">${esc(err.message)}</p>`;
      const countEl = document.getElementById('rf-count');
      if (countEl) countEl.textContent = '';
    }
  }

  // ── Delete modal helpers ───────────────────────────────────────────────────
  /** Close the confirm-delete modal. */
  function qsCloseDeleteModal() {
    document.getElementById('delete-modal-overlay').classList.remove('open');
  }
  document.getElementById('delete-modal-overlay').addEventListener('click', e => {
    if (e.target === document.getElementById('delete-modal-overlay')) qsCloseDeleteModal();
  });

  // ═══════════════════════════════════════════════════════════════════════════
  // ── USER — Home (quest list + my attempts) ──────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  /**
   * Load the user home view: open quests grid and past attempts list in parallel.
   * Only quests the current user is allowed to take are shown.
   */
  async function qsLoadUserHome() {
    qsUserShowView('quest-list');
    // Open quests
    {
      const wrap = document.getElementById('open-quests-wrap');
      wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
      try {
        const res    = await apiFetch('quests.php?action=get-open-quests');
        if (!res.ok) throw new Error('Failed to load quests');
        const quests = await res.json();
        wrap.innerHTML = '';
        if (!quests.length) {
          wrap.innerHTML = '<p class="qs-empty">No open quests available for you right now.</p>';
        } else {
          const grid = document.createElement('div');
          grid.className = 'qs-quest-cards';
          for (const q of quests) {
            const card = document.createElement('div');
            card.className = 'qs-quest-card';
            card.innerHTML = `
              <div class="qs-card-name">${esc(q.name)}</div>
              <div class="qs-card-meta">
                <span>📅 ${esc(q.date || '—')}</span>
                <span>❓ ${q.total_q} questions</span>
              </div>
              <button class="btn btn-primary" onclick="qsStartQuest(${q.id})">Start quest</button>`;
            grid.appendChild(card);
          }
          wrap.appendChild(grid);
        }
      } catch (err) {
        wrap.innerHTML = `<p class="qs-load-error-sm">${esc(err.message)}</p>`;
      }
    }
    // My attempts
    {
      const wrap = document.getElementById('my-attempts-wrap');
      wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
      try {
        const res     = await apiFetch('quests.php?action=get-my-attempts');
        if (!res.ok) throw new Error('Failed to load your attempts');
        const items   = await res.json();
        wrap.innerHTML = '';
        if (!items.length) {
          wrap.innerHTML = '<p class="qs-empty">You haven\'t completed any quests yet.</p>';
        } else {
          for (const a of items) {
            const div = document.createElement('div');
            div.className = 'qs-attempt-item';
            div.innerHTML = `
              <div class="qs-attempt-info">
                <div class="qs-attempt-name">${esc(a.quest_name)}</div>
                <div class="qs-attempt-meta">${fmtDate(a.submitted_at)}</div>
              </div>
              <div class="qs-attempt-score ${scoreClass(a.score)}">${fmtScore(a.score)}</div>
              <div class="qs-attempt-actions">
                ${a.revisable ? `<button class="btn btn-sm" onclick="qsReviewAttempt(${a.id})">Review</button>` : ''}
              </div>`;
            wrap.appendChild(div);
          }
        }
      } catch (err) {
        wrap.innerHTML = `<p class="qs-load-error-sm">${esc(err.message)}</p>`;
      }
    }
  }

  /**
   * Switch between the four user-facing sub-views.
   * @param {'quest-list'|'wizard'|'review'|'result'} view
   */
  function qsUserShowView(view) {
    document.getElementById('user-quest-list').style.display = view === 'quest-list' ? '' : 'none';
    document.getElementById('user-wizard').style.display     = view === 'wizard'     ? '' : 'none';
    document.getElementById('user-review').style.display     = view === 'review'     ? '' : 'none';
    document.getElementById('user-result').style.display     = view === 'result'     ? '' : 'none';
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── USER — Wizard ──────────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  /**
   * Start a quest wizard: POST to start-quest, store server-returned data
   * (attempt_id, questions, penalty) and render the first step.
   * @param {number} questId
   */
  async function qsStartQuest(questId) {
    const res  = await apiFetch('quests.php?action=start-quest', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({quest_id: questId})
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { qsToast(data.error || 'Failed to start quest', 'error'); return; }

    _wizardData    = data; // {attempt_id, quest_name, wrong, questions}
    _wizardStep    = 0;
    _wizardAnswers = {};

    qsUserShowView('wizard');
    qsRenderWizardStep();
  }

  /**
   * Re-render the current wizard question and navigation controls.
   * Restores any previously saved answer for the question via qsRenderAnswerArea().
   */
  function qsRenderWizardStep() {
    const wrap     = document.getElementById('user-wizard');
    const questions = _wizardData.questions;
    const total    = questions.length;
    const step     = _wizardStep;
    const q        = questions[step];
    const pct      = Math.round((step / total) * 100);

    wrap.innerHTML = `
      <div class="qs-view-header">
        <button class="btn btn-sm btn-ghost" onclick="qsAbortWizard()">← Back to quests</button>
        <strong class="qs-view-title">${esc(_wizardData.quest_name)}</strong>
      </div>
      <div class="qs-wizard">
        <div class="qs-progress-bar-wrap">
          <div class="qs-progress-bar" style="width:${pct}%"></div>
        </div>
        <div class="qs-question-header">
          <span class="qs-question-num">Question ${step + 1} / ${total}</span>
          <span class="qs-question-type-badge">${esc(q.type.replace(/_/g,' '))}</span>
        </div>
        <div class="qs-question-text">${esc(q.query)}</div>
        <div id="wizard-answer-area"></div>
        <div class="qs-wizard-nav">
          <button class="btn" onclick="qsWizardPrev()" ${step === 0 ? 'disabled' : ''}>← Previous</button>
          <span class="qs-progress-label">${step+1} / ${total}</span>
          ${step < total - 1
            ? `<button class="btn btn-primary" onclick="qsWizardNext()">Next →</button>`
            : `<button class="btn btn-primary" onclick="qsSubmitWizard()">Submit quest</button>`
          }
        </div>
      </div>`;

    qsRenderAnswerArea(q, _wizardAnswers[q.id] ?? null, false);
  }

  /**
   * Render the answer input area for a single question.
   * Handles all four question types: multiple_choice, binary, gap_filling, matching.
   * When readonly=true, disables inputs (used in review mode).
   * @param {Object}       q          - Question object from the server
   * @param {*}            savedAnswer - Previously chosen answer (or null)
   * @param {boolean}      readonly   - True for review, false for active quiz
   */
  function qsRenderAnswerArea(q, savedAnswer, readonly) {
    const wrap = document.getElementById('wizard-answer-area');
    if (!wrap) return;

    if (q.type === 'multiple_choice') {
      const ul = document.createElement('ul');
      ul.className = 'qs-options';
      q.options.forEach((opt, i) => {
        const li = document.createElement('li');
        li.className = 'qs-option' + (savedAnswer === String(i) ? ' selected' : '');
        li.dataset.val = i;
        li.innerHTML = `<input type="radio" name="wz-mc" value="${i}" ${savedAnswer === String(i)?'checked':''} ${readonly?'disabled':''}> ${esc(opt)}`;
        if (!readonly) {
          li.addEventListener('click', () => {
            wrap.querySelectorAll('.qs-option').forEach(x => x.classList.remove('selected'));
            li.classList.add('selected');
            li.querySelector('input').checked = true;
            _wizardAnswers[q.id] = String(i);
          });
        }
        ul.appendChild(li);
      });
      wrap.appendChild(ul);

    } else if (q.type === 'binary') {
      const ul = document.createElement('ul');
      ul.className = 'qs-options';
      [['1','Yes / True'], ['0','No / False']].forEach(([val, label]) => {
        const li = document.createElement('li');
        li.className = 'qs-option' + (savedAnswer === val ? ' selected' : '');
        li.innerHTML = `<input type="radio" name="wz-bin" value="${val}" ${savedAnswer===val?'checked':''} ${readonly?'disabled':''}> ${label}`;
        if (!readonly) {
          li.addEventListener('click', () => {
            wrap.querySelectorAll('.qs-option').forEach(x => x.classList.remove('selected'));
            li.classList.add('selected');
            li.querySelector('input').checked = true;
            _wizardAnswers[q.id] = val;
          });
        }
        ul.appendChild(li);
      });
      wrap.appendChild(ul);

    } else if (q.type === 'gap_filling') {
      const input = document.createElement('input');
      input.type      = 'text';
      input.className = 'qs-gap-input';
      input.placeholder = 'Type your answer…';
      input.value     = savedAnswer ?? '';
      input.disabled  = readonly;
      input.addEventListener('input', () => { _wizardAnswers[q.id] = input.value; });
      wrap.appendChild(input);

    } else if (q.type === 'matching') {
      const ul = document.createElement('ul');
      ul.className = 'qs-matching-rows';
      const savedPairs = (typeof savedAnswer === 'object' && savedAnswer) ? savedAnswer : {};
      q.keys.forEach(key => {
        const li  = document.createElement('li');
        li.className = 'qs-matching-row';
        const sel = document.createElement('select');
        sel.className = 'qs-matching-select';
        sel.disabled  = readonly;
        // Blank option
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '— select —';
        sel.appendChild(blank);
        q.values.forEach(val => {
          const opt = document.createElement('option');
          opt.value       = val;
          opt.textContent = val;
          opt.selected    = savedPairs[key] === val;
          sel.appendChild(opt);
        });
        sel.addEventListener('change', () => {
          if (!_wizardAnswers[q.id] || typeof _wizardAnswers[q.id] !== 'object')
            _wizardAnswers[q.id] = {};
          _wizardAnswers[q.id][key] = sel.value;
        });
        li.innerHTML = `<span class="qs-matching-key">${esc(key)}</span><span class="qs-matching-arrow">→</span>`;
        li.appendChild(sel);
        ul.appendChild(li);
      });
      wrap.appendChild(ul);
    }
  }

  /** Move back one question step without clearing the saved answer. */
  function qsWizardPrev() {
    if (_wizardStep > 0) { _wizardStep--; qsRenderWizardStep(); }
  }

  /** Move forward one question step without clearing the saved answer. */
  function qsWizardNext() {
    if (_wizardStep < _wizardData.questions.length - 1) { _wizardStep++; qsRenderWizardStep(); }
  }

  /** Prompt the user for confirmation before discarding wizard progress. */
  function qsAbortWizard() {
    if (!confirm('Are you sure you want to leave? Your progress will be lost.')) return;
    _wizardData    = null;
    _wizardAnswers = {};
    qsLoadUserHome();
  }

  /**
   * Collect all wizard answers and POST them to submit-attempt.
   * On success, shows the score result screen.
   */
  async function qsSubmitWizard() {
    if (!confirm('Submit this quest? You cannot change your answers afterwards.')) return;

    const answers = _wizardData.questions.map(q => ({
      id: q.id,
      answer: _wizardAnswers[q.id] ?? null
    }));

    const res  = await apiFetch('quests.php?action=submit-attempt', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ attempt_id: _wizardData.attempt_id, answers })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { qsToast(data.error || 'Submit failed', 'error'); return; }

    qsUserShowView('result');
    const wrap = document.getElementById('user-result');
    wrap.innerHTML = `
      <div class="qs-score-box">
        <div>
          <div class="sb-label">Your score</div>
          <div class="sb-detail">${data.correct} correct · ${data.incorrect} incorrect · ${data.skipped} skipped · ${data.total} total</div>
        </div>
        <div class="sb-value">${fmtScore(data.score)}<span class="qs-score-suffix">/10</span></div>
      </div>
      <p class="qs-result-text">Quest: <strong>${esc(_wizardData.quest_name)}</strong></p>
      <button class="btn btn-primary" onclick="qsLoadUserHome()">Back to quests</button>`;
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── USER — Review ──────────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  let _reviewData    = null;
  let _reviewStep    = 0;
  let _reviewIsAdmin = false;
  let _reviewBackFn  = () => qsLoadUserHome();

  /**
   * Load a past attempt for step-through review (user-facing).
   * @param {number} attemptId
   */
  async function qsReviewAttempt(attemptId) {
    const res  = await apiFetch(`quests.php?action=review-attempt&id=${attemptId}`);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { qsToast(data.error || 'Failed to load review', 'error'); return; }

    _reviewData    = data;
    _reviewStep    = 0;
    _reviewIsAdmin = false;
    _reviewBackFn  = () => qsLoadUserHome();
    qsUserShowView('review');
    qsRenderReviewStep();
  }

  /**
   * Render the review screen for the current step, showing correctness indicators.
   * Used by both guest review and admin review (distinguished by _reviewIsAdmin).
   */
  function qsRenderReviewStep() {
    const wrap      = document.getElementById('user-review');
    const questions = _reviewData.questions;
    const total     = questions.length;
    const step      = _reviewStep;
    const q         = questions[step];
    const pct       = Math.round(((step + 1) / total) * 100);

    // Determine correctness for this question
    const userAns = q.user_answer;
    let isCorrect = false;
    if (q.type === 'multiple_choice') {
      isCorrect = String(userAns) === String(q.answer);
    } else if (q.type === 'binary') {
      isCorrect = String(userAns) === String(q.answer);
    } else if (q.type === 'gap_filling') {
      isCorrect = (q.options || []).map(s => s.toLowerCase()).includes((userAns || '').toLowerCase().trim());
    } else if (q.type === 'matching') {
      isCorrect = Object.entries(q.options || {}).every(([k, v]) => (userAns || {})[k] === v);
    }

    wrap.innerHTML = `
      <div class="qs-view-header">
        <button class="btn btn-sm btn-ghost" onclick="_reviewBackFn()">← Back</button>
        <strong class="qs-view-title">${esc(_reviewData.quest_name)} — Review${_reviewIsAdmin && _reviewData.username ? ' (' + esc(_reviewData.username) + ')' : ''}</strong>
        <span class="${scoreClass(_reviewData.score)} qs-review-score">${fmtScore(_reviewData.score)}/10</span>
      </div>
      <div class="qs-wizard">
        <div class="qs-progress-bar-wrap">
          <div class="qs-progress-bar" style="width:${pct}%"></div>
        </div>
        <div class="qs-question-header">
          <span class="qs-question-num">Question ${step + 1} / ${total}</span>
          <span class="qs-question-type-badge">${esc(q.type.replace(/_/g,' '))}</span>
          <span class="${isCorrect ? 'score-high' : 'score-low'} qs-correct-indicator">
            ${isCorrect ? '✔ Correct' : '✘ Incorrect'}
          </span>
        </div>
        <div class="qs-question-text">${esc(q.query)}</div>
        <div id="review-answer-area"></div>
        <div class="qs-wizard-nav">
          <button class="btn" onclick="qsReviewPrev()" ${step === 0 ? 'disabled' : ''}>← Previous</button>
          <span class="qs-progress-label">${step+1} / ${total}</span>
          ${step < total - 1
            ? `<button class="btn btn-primary" onclick="qsReviewNext()">Next →</button>`
            : `<button class="btn btn-primary" onclick="_reviewBackFn()">Finish review</button>`
          }
        </div>
      </div>`;

    qsRenderReviewAnswerArea(q);
  }

  /**
   * Render the review answer area for a single question, highlighting correct/wrong answers.
   * @param {Object} q - Question object from review-attempt response
   */
  function qsRenderReviewAnswerArea(q) {
    const wrap    = document.getElementById('review-answer-area');
    const userAns = q.user_answer;

    if (q.type === 'multiple_choice') {
      const ul = document.createElement('ul');
      ul.className = 'qs-options';
      const correctIdx = String(q.answer);
      q.options.forEach((opt, i) => {
        const li = document.createElement('li');
        const isUser    = String(userAns) === String(i);
        const isCorrect = String(i) === correctIdx;
        let cls = 'qs-option';
        if (isCorrect && isUser) cls += ' correct';
        else if (isUser && !isCorrect) cls += ' wrong';
        else if (isCorrect && !isUser) cls += ' miss';
        li.className = cls;
        li.innerHTML  = `<input type="radio" disabled ${isUser?'checked':''}> ${esc(opt)}`;
        ul.appendChild(li);
      });
      wrap.appendChild(ul);
      if (String(userAns) !== correctIdx) {
        const hint = document.createElement('div');
        hint.className = 'qs-review-correct';
        hint.textContent = '✔ Correct answer: ' + (q.options[parseInt(correctIdx)] || '—');
        wrap.appendChild(hint);
      }

    } else if (q.type === 'binary') {
      const ul = document.createElement('ul');
      ul.className = 'qs-options';
      [['1','Yes / True'], ['0','No / False']].forEach(([val, label]) => {
        const li = document.createElement('li');
        const isUser    = String(userAns) === val;
        const isCorrect = String(q.answer) === val;
        let cls = 'qs-option';
        if (isCorrect && isUser) cls += ' correct';
        else if (isUser) cls += ' wrong';
        else if (isCorrect) cls += ' miss';
        li.className = cls;
        li.innerHTML  = `<input type="radio" disabled ${isUser?'checked':''}> ${label}`;
        ul.appendChild(li);
      });
      wrap.appendChild(ul);

    } else if (q.type === 'gap_filling') {
      const input = document.createElement('input');
      const isOk  = (q.options || []).map(s=>s.toLowerCase()).includes((userAns||'').toLowerCase().trim());
      input.type      = 'text';
      input.className = 'qs-gap-input ' + (userAns ? (isOk ? 'correct' : 'wrong') : '');
      input.value     = userAns ?? '';
      input.disabled  = true;
      wrap.appendChild(input);
      if (!isOk) {
        const hint = document.createElement('div');
        hint.className = 'qs-review-correct';
        hint.textContent = '✔ Accepted answers: ' + (q.options || []).join(' / ');
        wrap.appendChild(hint);
      }

    } else if (q.type === 'matching') {
      const correctPairs = q.options || {};
      const userPairs    = (typeof userAns === 'object' && userAns) ? userAns : {};
      const ul  = document.createElement('ul');
      ul.className = 'qs-matching-rows';
      Object.entries(correctPairs).forEach(([key, correctVal]) => {
        const li  = document.createElement('li');
        li.className = 'qs-matching-row';
        const userVal = userPairs[key] ?? '';
        const isOk = userVal === correctVal;
        const allVals = Object.values(correctPairs);
        const sel = document.createElement('select');
        sel.className = 'qs-matching-select ' + (userVal ? (isOk ? 'correct' : 'wrong') : '');
        sel.disabled  = true;
        allVals.forEach(val => {
          const opt = document.createElement('option');
          opt.value = val; opt.textContent = val;
          opt.selected = userVal === val;
          sel.appendChild(opt);
        });
        li.innerHTML = `<span class="qs-matching-key">${esc(key)}</span><span class="qs-matching-arrow">→</span>`;
        li.appendChild(sel);
        ul.appendChild(li);
        if (!isOk) {
          const hint = document.createElement('li');
          hint.className = 'qs-matching-hint-row';
          hint.innerHTML = `<span class="qs-review-correct">✔ Correct: ${esc(key)} → ${esc(correctVal)}</span>`;
          ul.appendChild(hint);
        }
      });
      wrap.appendChild(ul);
    }
  }

  /** Move back one step in the review. */
  function qsReviewPrev() { if (_reviewStep > 0) { _reviewStep--; qsRenderReviewStep(); } }
  /** Move forward one step in the review. */
  function qsReviewNext() { if (_reviewStep < _reviewData.questions.length - 1) { _reviewStep++; qsRenderReviewStep(); } }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── THEME PANEL ────────────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  /**
   * Toggle the theme selection panel open or closed.
   * When opening, fetches available templates and pre-selects the current one.
   */
  async function qsToggleThemePanel() {
    const panel   = document.getElementById('qs-theme-panel');
    const overlay = document.getElementById('qs-theme-overlay');
    const isOpen  = panel.classList.toggle('open');
    overlay.classList.toggle('open', isOpen);
    if (isOpen) {
      const res  = await apiFetch('quests.php?action=get-quests-templates');
      if (!res.ok) return;
      const data = await res.json();
      const sel  = document.getElementById('qs-theme-select');
      sel.innerHTML = '';
      (data.templates || []).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t; opt.textContent = t.replace('.css','');
        const cur = document.getElementById('qs-theme-link').href.split('/').pop();
        opt.selected = t === cur;
        sel.appendChild(opt);
      });
    }
  }

  /**
   * Apply a theme CSS file immediately (live preview before saving).
   * @param {string} theme - Filename, e.g. 'impact.css'
   */
  function qsPreviewTheme(theme) {
    document.getElementById('qs-theme-link').href = 'templates-quests/' + theme;
  }

  document.getElementById('qs-theme-select')?.addEventListener('change', e => qsPreviewTheme(e.target.value));

  /**
   * Persist the selected theme to the server.
   */
  async function qsSaveTheme() { {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({theme})
    });
    const data  = await res.json().catch(() => ({}));
    if (res.ok) { qsToggleThemePanel(); qsToast('Theme saved'); }
    else qsToast(data.error || 'Failed to save theme', 'error');
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── Utility ────────────────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  /**
   * Escape a string for safe insertion into HTML.
   * @param {*} str
   * @returns {string} HTML-escaped string
   */
  function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
