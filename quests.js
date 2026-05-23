// quests.js — Quests module client script
// Requires: lib/auth-client.js, lib/app-client.js (loaded before this file)

requireAuth('index.php');

// ── State ──────────────────────────────────────────────────────────────────
let _editingQueryId  = null;
let _editingQuestId  = null;
let _deleteCallback  = null;
let _wizardData      = null;
let _wizardStep      = 0;
let _wizardAnswers   = {};
let _allQueriesCache = [];

// ── Auth & routing ─────────────────────────────────────────────────────────
setOnUnauthorized(() => { window.location.href = 'index.php'; });

function qsShowApp() {
  document.getElementById('app-header').style.display = 'flex';
  document.getElementById('quests-screen').style.display = 'block';
  const badge = document.getElementById('qs-user-badge');
  if (badge) badge.textContent = getUser() + ' (' + getRole() + ')';
}

function qsRoute() {
  const role = getRole();
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

// Auto-start (token already verified by requireAuth at top)
qsShowApp();
qsRoute();

// ── Tab switching ──────────────────────────────────────────────────────────
function qsShowTab(name) {
  document.querySelectorAll('.qs-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === name);
  });
  document.querySelectorAll('.qs-tab-panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById('tab-' + name);
  if (panel) panel.classList.add('active');
  if (name === 'questions') qsLoadQueries();
  if (name === 'quests')    qsLoadQuestsAdmin();
  if (name === 'results')   qsLoadResults();
}

// ── Score color helpers ────────────────────────────────────────────────────
function scoreClass(v) {
  if (v === null || v === undefined) return '';
  if (v >= 5) return 'score-high';
  if (v >= 3) return 'score-mid';
  return 'score-low';
}
function fmtScore(v) {
  if (v === null || v === undefined) return '—';
  return parseFloat(v).toFixed(2);
}
function fmtDate(d) {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit'
    });
  } catch { return d; }
}

// ═══════════════════════════════════════════════════════════════════════════
// ── ADMIN — QUESTIONS tab ───────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
async function qsLoadQueries() {
  const wrap = document.getElementById('queries-table-wrap');
  wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
  try {
    const res = await apiFetch('quests.php?action=get-queries');
    if (!res.ok) throw new Error('Failed to load questions');
    _allQueriesCache = await res.json();
    const allLabels = [...new Set(_allQueriesCache.flatMap(q => q.labels || []))].sort();
    const dl = document.getElementById('qf-label-list');
    if (dl) {
      dl.innerHTML = '';
      allLabels.forEach(l => {
        const o = document.createElement('option');
        o.value = l;
        dl.appendChild(o);
      });
    }
    qsApplyQueryFilters();
  } catch (err) {
    document.getElementById('queries-table-wrap').innerHTML =
      `<p style="padding:1rem;color:#c62828">${escHtml(err.message)}</p>`;
  }
}

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
    countEl.textContent = active ? `${filtered.length} / ${_allQueriesCache.length}` : '';
  }

  qsRenderQueriesTable(filtered);
}

function qsRenderQueriesTable(queries) {
  const wrap = document.getElementById('queries-table-wrap');
  wrap.innerHTML = '';
  if (!_allQueriesCache.length) {
    wrap.innerHTML = '<p class="qs-empty">No questions yet. Click "Add question" to create one.</p>';
    return;
  }
  if (!queries.length) {
    wrap.innerHTML = '<p class="qs-empty">No questions match the current filters.</p>';
    return;
  }
  const table = document.createElement('table');
  table.className = 'qs-table';
  table.innerHTML = `<thead><tr>
    <th style="width:3rem">ID</th>
    <th>Question</th>
    <th>Type</th>
    <th>Labels</th>
    <th style="width:68px" title="% answered correctly out of answered (excl. skipped)">Hit %</th>
    <th style="width:68px" title="% of total question appearances across all attempts">Freq %</th>
    <th style="width:110px">Actions</th>
  </tr></thead>`;
  const tbody = document.createElement('tbody');
  for (const q of queries) {
    const tr = document.createElement('tr');
    const labelsHtml = (q.labels || []).map(l => `<span class="badge badge-label">${escHtml(l)}</span>`).join(' ');
    const st  = q.stats || {};
    const hitPct  = st.success_pct    != null ? st.success_pct    + '%' : '—';
    const freqPct = st.appearance_pct != null ? st.appearance_pct + '%' : '—';
    const hitCls  = st.success_pct != null
      ? (st.success_pct >= 70 ? 'score-high' : st.success_pct >= 40 ? 'score-mid' : 'score-low')
      : '';
    tr.innerHTML = `
      <td style="text-align:center;color:#888">${q.id}</td>
      <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(q.query)}">${escHtml(q.query)}</td>
      <td><span class="badge badge-type">${escHtml(q.type)}</span></td>
      <td>${labelsHtml || '<span style="color:#bbb">—</span>'}</td>
      <td style="text-align:center" title="${st.correct ?? 0} correct / ${st.wrong ?? 0} wrong of ${st.appeared ?? 0} shown"><span class="${hitCls}">${hitPct}</span></td>
      <td style="text-align:center" title="${st.appeared ?? 0} appearances">${freqPct}</td>
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
function qsOpenQueryModal(queryData = null) {
  _editingQueryId = queryData ? queryData.id : null;
  document.getElementById('query-modal-title').textContent = queryData ? 'Edit question' : 'Add question';
  document.getElementById('qm-type').value   = queryData ? queryData.type : 'multiple_choice';
  document.getElementById('qm-query').value  = queryData ? queryData.query : '';
  document.getElementById('qm-labels').value = queryData ? (queryData.labels || []).join(', ') : '';
  qsRenderQueryTypeFields(queryData);
  document.getElementById('query-modal-overlay').classList.add('open');
}

function qsCloseQueryModal() {
  document.getElementById('query-modal-overlay').classList.remove('open');
}

async function qsEditQuery(id) {
  const res = await apiFetch('quests.php?action=get-queries');
  if (!res.ok) { showToast('Failed to load question', 'error'); return; }
  const queries = await res.json();
  const q = queries.find(x => x.id === id);
  if (!q) { showToast('Question not found', 'error'); return; }
  qsOpenQueryModal(q);
}

function qsRenderQueryTypeFields(data = null) {
  const type = document.getElementById('qm-type').value;
  const wrap = document.getElementById('qm-type-fields');
  wrap.innerHTML = '';

  if (type === 'multiple_choice') {
    const opts = data?.options || ['', '', '', ''];
    const ans  = data?.answer ?? '0';
    let html = `<div class="qs-field"><label>Options <span style="font-weight:400;color:#888">(mark correct with radio)</span></label><ul class="qs-options-list" id="mc-options-list">`;
    opts.forEach((o, i) => {
      html += `<li>
        <input type="radio" name="mc-correct" value="${i}" ${parseInt(ans) === i ? 'checked' : ''} title="Correct answer">
        <input type="text" class="mc-opt-input" value="${escHtml(o)}" placeholder="Option ${i+1}">
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
    let html = `<div class="qs-field"><label>Accepted answers <span style="font-weight:400;color:#888">(all are correct, case-insensitive)</span></label><ul class="qs-options-list" id="gf-options-list">`;
    opts.forEach(o => {
      html += `<li><input type="text" class="gf-opt-input" value="${escHtml(o)}" placeholder="Accepted answer…">
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
        <input type="text" class="mt-key" value="${escHtml(k)}" placeholder="Key (left)">
        <span class="qs-matching-sep">→</span>
        <input type="text" class="mt-val" value="${escHtml(v)}" placeholder="Value (right)">
        <button class="btn btn-sm btn-ghost" onclick="this.closest('li').remove()" type="button">✕</button>
      </li>`;
    });
    html += `</ul><button class="btn btn-sm" onclick="qsAddMtPair()" type="button">+ Add pair</button></div>`;
    wrap.innerHTML = html;
  }
}

function qsAddMcOption() {
  const list = document.getElementById('mc-options-list');
  const idx  = list.querySelectorAll('li').length;
  const li   = document.createElement('li');
  li.innerHTML = `<input type="radio" name="mc-correct" value="${idx}" title="Correct answer">
    <input type="text" class="mc-opt-input" placeholder="Option ${idx+1}">
    <button class="btn btn-sm btn-ghost" onclick="qsRemoveMcOption(this)" type="button">✕</button>`;
  list.appendChild(li);
}

function qsRemoveMcOption(btn) {
  const li   = btn.closest('li');
  const list = li.closest('ul');
  li.remove();
  list.querySelectorAll('li').forEach((l, i) => {
    const r = l.querySelector('input[type="radio"]');
    if (r) r.value = i;
  });
}

function qsAddGfOption() {
  const list = document.getElementById('gf-options-list');
  const li   = document.createElement('li');
  li.innerHTML = `<input type="text" class="gf-opt-input" placeholder="Accepted answer…">
    <button class="btn btn-sm btn-ghost" onclick="this.closest('li').remove()" type="button">✕</button>`;
  list.appendChild(li);
}

function qsAddMtPair() {
  const list = document.getElementById('mt-pairs-list');
  const li   = document.createElement('li');
  li.innerHTML = `<input type="text" class="mt-key" placeholder="Key (left)">
    <span class="qs-matching-sep">→</span>
    <input type="text" class="mt-val" placeholder="Value (right)">
    <button class="btn btn-sm btn-ghost" onclick="this.closest('li').remove()" type="button">✕</button>`;
  list.appendChild(li);
}

async function qsSaveQuery() {
  const type   = document.getElementById('qm-type').value;
  const query  = document.getElementById('qm-query').value.trim();
  const labels = document.getElementById('qm-labels').value.split(',').map(s => s.trim()).filter(Boolean);

  if (!query) { showToast('Question text is required', 'error'); return; }

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
    showToast(_editingQueryId ? 'Question updated' : 'Question added');
    qsLoadQueries();
  } else {
    showToast(data.error || 'Save failed', 'error');
  }
}

function qsConfirmDeleteQuery(id) {
  _deleteCallback = async () => {
    const res  = await apiFetch('quests.php?action=delete-query', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
    });
    const data = await res.json().catch(() => ({}));
    qsCloseDeleteModal();
    if (res.ok) { showToast('Question deleted'); qsLoadQueries(); }
    else showToast(data.error || 'Delete failed', 'error');
  };
  document.getElementById('delete-modal-title').textContent = 'Delete question';
  document.getElementById('delete-modal-msg').textContent   = `Delete question #${id}? This cannot be undone.`;
  document.getElementById('delete-modal-confirm').onclick   = _deleteCallback;
  document.getElementById('delete-modal-overlay').classList.add('open');
}

// ═══════════════════════════════════════════════════════════════════════════
// ── ADMIN — QUESTS tab ──────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
async function qsLoadQuestsAdmin() {
  const wrap = document.getElementById('quests-admin-table-wrap');
  wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
  try {
    const res    = await apiFetch('quests.php?action=get-quests-admin');
    if (!res.ok) throw new Error('Failed to load quests');
    const quests = await res.json();
    wrap.innerHTML = '';
    if (!quests.length) {
      wrap.innerHTML = '<p class="qs-empty">No quests yet. Click "Add quest" to create one.</p>';
      return;
    }
    const table = document.createElement('table');
    table.className = 'qs-table';
    table.innerHTML = `<thead><tr>
      <th>Name</th><th>Date</th><th>Status</th><th>Wrong</th><th>Revisable</th><th>Attempts</th><th style="width:80px">Avg score</th><th style="width:120px">Actions</th>
    </tr></thead>`;
    const tbody = document.createElement('tbody');
    for (const q of quests) {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><strong>${escHtml(q.name)}</strong></td>
        <td>${escHtml(q.date || '—')}</td>
        <td><span class="badge badge-${q.status}">${escHtml(q.status)}</span></td>
        <td>${q.wrong < 0 ? q.wrong : '—'}</td>
        <td>${q.revisable ? '✔' : '—'}</td>
        <td style="text-align:center">${q.attempt_count ?? 0}</td>
        <td style="text-align:center"><span class="${scoreClass(q.avg_score)}">${fmtScore(q.avg_score)}</span></td>
        <td>
          <button class="btn btn-sm" onclick="qsEditQuest(${q.id})">Edit</button>
          <button class="btn btn-sm btn-danger" onclick="qsConfirmDeleteQuest(${q.id})">Del</button>
        </td>`;
      tbody.appendChild(tr);
    }
    table.appendChild(tbody);
    wrap.appendChild(table);
  } catch (err) {
    wrap.innerHTML = `<p style="padding:1rem;color:#c62828">${escHtml(err.message)}</p>`;
  }
}

// ── Quest modal ────────────────────────────────────────────────────────────
function qsOpenQuestModal(questData = null) {
  _editingQuestId = questData ? questData.id : null;
  document.getElementById('quest-modal-title').textContent  = questData ? 'Edit quest' : 'Add quest';
  document.getElementById('qst-name').value      = questData?.name ?? '';
  document.getElementById('qst-date').value      = questData?.date ?? new Date().toISOString().slice(0,10);
  document.getElementById('qst-status').value    = questData?.status ?? 'closed';
  document.getElementById('qst-wrong').value     = questData?.wrong ?? 0;
  document.getElementById('qst-revisable').value = questData?.revisable ? '1' : '0';

  const groups = questData?.queries || [{labels:[], queries:1}];
  const wrap   = document.getElementById('qst-label-groups');
  wrap.innerHTML = '';
  groups.forEach(g => qsAddLabelGroup(g));

  document.getElementById('quest-modal-overlay').classList.add('open');
}

function qsCloseQuestModal() {
  document.getElementById('quest-modal-overlay').classList.remove('open');
}

async function qsEditQuest(id) {
  const res = await apiFetch('quests.php?action=get-quests-admin');
  if (!res.ok) { showToast('Failed to load quest', 'error'); return; }
  const quests = await res.json();
  const q  = quests.find(x => x.id === id);
  if (!q) { showToast('Quest not found', 'error'); return; }
  qsOpenQuestModal(q);
}

function qsAddLabelGroup(data = null) {
  const wrap = document.getElementById('qst-label-groups');
  const div  = document.createElement('div');
  div.className = 'qs-label-group';
  const labels = data?.labels?.join(', ') ?? '';
  const count  = data?.queries ?? 1;
  div.innerHTML = `
    <input type="text" placeholder="Labels (comma-separated, AND)" value="${escHtml(labels)}" style="flex:1">
    <input type="number" class="qs-label-group-qty" min="1" value="${count}" title="Number of questions">
    <span style="font-size:.78rem;color:#888;white-space:nowrap">questions</span>
    <button class="btn btn-sm btn-ghost" onclick="this.closest('.qs-label-group').remove()" type="button">✕</button>`;
  wrap.appendChild(div);
}

async function qsSaveQuest() {
  const name     = document.getElementById('qst-name').value.trim();
  const date     = document.getElementById('qst-date').value;
  const status   = document.getElementById('qst-status').value;
  const wrong    = parseFloat(document.getElementById('qst-wrong').value) || 0;
  const revisable= document.getElementById('qst-revisable').value === '1';

  if (!name) { showToast('Quest name is required', 'error'); return; }

  const groups = [];
  document.querySelectorAll('#qst-label-groups .qs-label-group').forEach(div => {
    const inputs = div.querySelectorAll('input');
    const labels = inputs[0].value.split(',').map(s=>s.trim()).filter(Boolean);
    const count  = parseInt(inputs[1].value) || 1;
    groups.push({ labels, queries: count });
  });

  const body = { name, date, status, wrong, revisable, queries: groups };
  if (_editingQuestId !== null) body.id = _editingQuestId;

  const res  = await apiFetch('quests.php?action=save-quest', {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
  });
  const data = await res.json().catch(() => ({}));
  if (res.ok) {
    qsCloseQuestModal();
    showToast(_editingQuestId ? 'Quest updated' : 'Quest added');
    qsLoadQuestsAdmin();
  } else {
    showToast(data.error || 'Save failed', 'error');
  }
}

function qsConfirmDeleteQuest(id) {
  _deleteCallback = async () => {
    const res  = await apiFetch('quests.php?action=delete-quest', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
    });
    const data = await res.json().catch(() => ({}));
    qsCloseDeleteModal();
    if (res.ok) { showToast('Quest deleted'); qsLoadQuestsAdmin(); }
    else showToast(data.error || 'Delete failed', 'error');
  };
  document.getElementById('delete-modal-title').textContent = 'Delete quest';
  document.getElementById('delete-modal-msg').textContent   = `Delete quest #${id}? All associated data will remain in the attempts file.`;
  document.getElementById('delete-modal-confirm').onclick   = _deleteCallback;
  document.getElementById('delete-modal-overlay').classList.add('open');
}

function qsConfirmDeleteAttempt(id) {
  _deleteCallback = async () => {
    const res  = await apiFetch('quests.php?action=delete-attempt', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
    });
    const data = await res.json().catch(() => ({}));
    qsCloseDeleteModal();
    if (res.ok) { showToast('Attempt deleted'); qsLoadResults(); }
    else showToast(data.error || 'Delete failed', 'error');
  };
  document.getElementById('delete-modal-title').textContent = 'Delete attempt';
  document.getElementById('delete-modal-msg').textContent   = `Delete attempt #${id}? This cannot be undone.`;
  document.getElementById('delete-modal-confirm').onclick   = _deleteCallback;
  document.getElementById('delete-modal-overlay').classList.add('open');
}

async function qsAdminReviewAttempt(attemptId) {
  const res  = await apiFetch(`quests.php?action=review-attempt&id=${attemptId}`);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) { showToast(data.error || 'Failed to load review', 'error'); return; }

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
// ── ADMIN — RESULTS tab ─────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
async function qsLoadResults() {
  const wrap = document.getElementById('results-table-wrap');
  wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
  try {
    const res   = await apiFetch('quests.php?action=get-all-attempts');
    if (!res.ok) throw new Error('Failed to load results');
    const items = await res.json();
    wrap.innerHTML = '';
    if (!items.length) {
      wrap.innerHTML = '<p class="qs-empty">No completed attempts yet.</p>';
      return;
    }

    const groupMap = new Map();
    for (const a of items) {
      if (!groupMap.has(a.quest_id)) groupMap.set(a.quest_id, { quest_name: a.quest_name, attempts: [] });
      groupMap.get(a.quest_id).attempts.push(a);
    }
    const groups = [...groupMap.values()].sort((a, b) => {
      const da = a.attempts[0]?.submitted_at ?? '';
      const db = b.attempts[0]?.submitted_at ?? '';
      return db.localeCompare(da);
    });

    for (const group of groups) {
      const hdr = document.createElement('div');
      hdr.style.cssText = 'margin:1.25rem 0 .4rem;display:flex;align-items:baseline;gap:.75rem';
      hdr.innerHTML = `<strong style="font-size:1rem">${escHtml(group.quest_name)}</strong>`
        + `<span style="font-size:.8rem;color:#888">${group.attempts.length} attempt${group.attempts.length !== 1 ? 's' : ''}</span>`;
      wrap.appendChild(hdr);

      const table = document.createElement('table');
      table.className = 'qs-table';
      table.innerHTML = `<thead><tr>
        <th>User</th><th>Date</th><th style="width:90px">Score / 10</th><th style="width:130px">Actions</th>
      </tr></thead>`;
      const tbody = document.createElement('tbody');
      for (const a of group.attempts) {
        const sc = a.score;
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${escHtml(a.username)}</strong></td>
          <td style="color:#888;font-size:.82rem">${fmtDate(a.submitted_at)}</td>
          <td style="text-align:center"><span class="${scoreClass(sc)}">${fmtScore(sc)}</span></td>
          <td>
            <button class="btn btn-sm" onclick="qsAdminReviewAttempt(${a.id})">Review</button>
            <button class="btn btn-sm btn-danger" onclick="qsConfirmDeleteAttempt(${a.id})">Del</button>
          </td>`;
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      wrap.appendChild(table);
    }
  } catch (err) {
    wrap.innerHTML = `<p style="padding:1rem;color:#c62828">${escHtml(err.message)}</p>`;
  }
}

// ── Delete modal helpers ───────────────────────────────────────────────────
function qsCloseDeleteModal() {
  document.getElementById('delete-modal-overlay').classList.remove('open');
}
document.getElementById('delete-modal-overlay').addEventListener('click', e => {
  if (e.target === document.getElementById('delete-modal-overlay')) qsCloseDeleteModal();
});

// ═══════════════════════════════════════════════════════════════════════════
// ── USER — Home (quest list + my attempts) ──────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
async function qsLoadUserHome() {
  qsUserShowView('quest-list');
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
            <div class="qs-card-name">${escHtml(q.name)}</div>
            <div class="qs-card-meta">
              <span>📅 ${escHtml(q.date || '—')}</span>
              <span>❓ ${q.total_q} questions</span>
            </div>
            <button class="btn btn-primary" onclick="qsStartQuest(${q.id})">Start quest</button>`;
          grid.appendChild(card);
        }
        wrap.appendChild(grid);
      }
    } catch (err) {
      wrap.innerHTML = `<p style="color:#c62828;padding:.5rem 0">${escHtml(err.message)}</p>`;
    }
  }
  {
    const wrap = document.getElementById('my-attempts-wrap');
    wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
    try {
      const res   = await apiFetch('quests.php?action=get-my-attempts');
      if (!res.ok) throw new Error('Failed to load your attempts');
      const items = await res.json();
      wrap.innerHTML = '';
      if (!items.length) {
        wrap.innerHTML = '<p class="qs-empty">You haven\'t completed any quests yet.</p>';
      } else {
        for (const a of items) {
          const div = document.createElement('div');
          div.className = 'qs-attempt-item';
          div.innerHTML = `
            <div class="qs-attempt-info">
              <div class="qs-attempt-name">${escHtml(a.quest_name)}</div>
              <div class="qs-attempt-meta">${fmtDate(a.submitted_at)}</div>
            </div>
            <div class="qs-attempt-score ${scoreClass(a.score)}">${fmtScore(a.score)}</div>
            <div style="padding-left:.75rem">
              ${a.revisable ? `<button class="btn btn-sm" onclick="qsReviewAttempt(${a.id})">Review</button>` : ''}
            </div>`;
          wrap.appendChild(div);
        }
      }
    } catch (err) {
      wrap.innerHTML = `<p style="color:#c62828;padding:.5rem 0">${escHtml(err.message)}</p>`;
    }
  }
}

function qsUserShowView(view) {
  document.getElementById('user-quest-list').style.display = view === 'quest-list' ? '' : 'none';
  document.getElementById('user-wizard').style.display     = view === 'wizard'     ? '' : 'none';
  document.getElementById('user-review').style.display     = view === 'review'     ? '' : 'none';
  document.getElementById('user-result').style.display     = view === 'result'     ? '' : 'none';
}

// ═══════════════════════════════════════════════════════════════════════════
// ── USER — Wizard ──────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
async function qsStartQuest(questId) {
  const res  = await apiFetch('quests.php?action=start-quest', {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({quest_id: questId})
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) { showToast(data.error || 'Failed to start quest', 'error'); return; }

  _wizardData    = data;
  _wizardStep    = 0;
  _wizardAnswers = {};

  qsUserShowView('wizard');
  qsRenderWizardStep();
}

function qsRenderWizardStep() {
  const wrap      = document.getElementById('user-wizard');
  const questions = _wizardData.questions;
  const total     = questions.length;
  const step      = _wizardStep;
  const q         = questions[step];
  const pct       = Math.round((step / total) * 100);

  wrap.innerHTML = `
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
      <button class="btn btn-sm btn-ghost" onclick="qsAbortWizard()">← Back to quests</button>
      <strong style="flex:1;font-size:1rem">${escHtml(_wizardData.quest_name)}</strong>
    </div>
    <div class="qs-wizard">
      <div class="qs-progress-bar-wrap">
        <div class="qs-progress-bar" style="width:${pct}%"></div>
      </div>
      <div class="qs-question-header">
        <span class="qs-question-num">Question ${step + 1} / ${total}</span>
        <span class="qs-question-type-badge">${escHtml(q.type.replace(/_/g,' '))}</span>
      </div>
      <div class="qs-question-text">${escHtml(q.query)}</div>
      <div id="wizard-answer-area"></div>
      <div class="qs-wizard-nav">
        <button class="btn" onclick="qsWizardPrev()" ${step === 0 ? 'disabled' : ''}>← Previous</button>
        <span style="font-size:.8rem;color:#888">${step+1} / ${total}</span>
        ${step < total - 1
          ? `<button class="btn btn-primary" onclick="qsWizardNext()">Next →</button>`
          : `<button class="btn btn-primary" onclick="qsSubmitWizard()">Submit quest</button>`
        }
      </div>
    </div>`;

  qsRenderAnswerArea(q, _wizardAnswers[q.id] ?? null, false);
}

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
      li.innerHTML = `<input type="radio" name="wz-mc" value="${i}" ${savedAnswer === String(i)?'checked':''} ${readonly?'disabled':''}> ${escHtml(opt)}`;
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
      li.innerHTML = `<span class="qs-matching-key">${escHtml(key)}</span><span class="qs-matching-arrow">→</span>`;
      li.appendChild(sel);
      ul.appendChild(li);
    });
    wrap.appendChild(ul);
  }
}

function qsWizardPrev() {
  if (_wizardStep > 0) { _wizardStep--; qsRenderWizardStep(); }
}

function qsWizardNext() {
  if (_wizardStep < _wizardData.questions.length - 1) { _wizardStep++; qsRenderWizardStep(); }
}

function qsAbortWizard() {
  if (!confirm('Are you sure you want to leave? Your progress will be lost.')) return;
  _wizardData    = null;
  _wizardAnswers = {};
  qsLoadUserHome();
}

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
  if (!res.ok) { showToast(data.error || 'Submit failed', 'error'); return; }

  qsUserShowView('result');
  const wrap = document.getElementById('user-result');
  wrap.innerHTML = `
    <div class="qs-score-box">
      <div>
        <div class="sb-label">Your score</div>
        <div class="sb-detail">${data.correct} correct · ${data.incorrect} incorrect · ${data.skipped} skipped · ${data.total} total</div>
      </div>
      <div class="sb-value">${fmtScore(data.score)}<span style="font-size:1.2rem;opacity:.7">/10</span></div>
    </div>
    <p style="color:#555;margin-bottom:1.5rem;font-size:.95rem">Quest: <strong>${escHtml(_wizardData.quest_name)}</strong></p>
    <button class="btn btn-primary" onclick="qsLoadUserHome()">Back to quests</button>`;
}

// ═══════════════════════════════════════════════════════════════════════════
// ── USER — Review ──────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
let _reviewData    = null;
let _reviewStep    = 0;
let _reviewIsAdmin = false;
let _reviewBackFn  = () => qsLoadUserHome();

async function qsReviewAttempt(attemptId) {
  const res  = await apiFetch(`quests.php?action=review-attempt&id=${attemptId}`);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) { showToast(data.error || 'Failed to load review', 'error'); return; }

  _reviewData    = data;
  _reviewStep    = 0;
  _reviewIsAdmin = false;
  _reviewBackFn  = () => qsLoadUserHome();
  qsUserShowView('review');
  qsRenderReviewStep();
}

function qsRenderReviewStep() {
  const wrap      = document.getElementById('user-review');
  const questions = _reviewData.questions;
  const total     = questions.length;
  const step      = _reviewStep;
  const q         = questions[step];
  const pct       = Math.round(((step + 1) / total) * 100);

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
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
      <button class="btn btn-sm btn-ghost" onclick="_reviewBackFn()">← Back</button>
      <strong style="flex:1;font-size:1rem">${escHtml(_reviewData.quest_name)} — Review${_reviewIsAdmin && _reviewData.username ? ' (' + escHtml(_reviewData.username) + ')' : ''}</strong>
      <span class="${scoreClass(_reviewData.score)}" style="font-size:1.1rem;font-weight:700">${fmtScore(_reviewData.score)}/10</span>
    </div>
    <div class="qs-wizard">
      <div class="qs-progress-bar-wrap">
        <div class="qs-progress-bar" style="width:${pct}%"></div>
      </div>
      <div class="qs-question-header">
        <span class="qs-question-num">Question ${step + 1} / ${total}</span>
        <span class="qs-question-type-badge">${escHtml(q.type.replace(/_/g,' '))}</span>
        <span class="${isCorrect ? 'score-high' : 'score-low'}" style="font-size:.8rem;font-weight:700">
          ${isCorrect ? '✔ Correct' : '✘ Incorrect'}
        </span>
      </div>
      <div class="qs-question-text">${escHtml(q.query)}</div>
      <div id="review-answer-area"></div>
      <div class="qs-wizard-nav">
        <button class="btn" onclick="qsReviewPrev()" ${step === 0 ? 'disabled' : ''}>← Previous</button>
        <span style="font-size:.8rem;color:#888">${step+1} / ${total}</span>
        ${step < total - 1
          ? `<button class="btn btn-primary" onclick="qsReviewNext()">Next →</button>`
          : `<button class="btn btn-primary" onclick="_reviewBackFn()">Finish review</button>`
        }
      </div>
    </div>`;

  qsRenderReviewAnswerArea(q);
}

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
      li.innerHTML  = `<input type="radio" disabled ${isUser?'checked':''}> ${escHtml(opt)}`;
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
    const isOk  = (q.options || []).map(s=>s.toLowerCase()).includes((userAns||'').toLowerCase().trim());
    const input = document.createElement('input');
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
      li.innerHTML = `<span class="qs-matching-key">${escHtml(key)}</span><span class="qs-matching-arrow">→</span>`;
      li.appendChild(sel);
      ul.appendChild(li);
      if (!isOk) {
        const hint = document.createElement('li');
        hint.style.cssText = 'grid-column:1/-1;margin-bottom:.4rem';
        hint.innerHTML = `<span class="qs-review-correct">✔ Correct: ${escHtml(key)} → ${escHtml(correctVal)}</span>`;
        ul.appendChild(hint);
      }
    });
    wrap.appendChild(ul);
  }
}

function qsReviewPrev() { if (_reviewStep > 0) { _reviewStep--; qsRenderReviewStep(); } }
function qsReviewNext() { if (_reviewStep < _reviewData.questions.length - 1) { _reviewStep++; qsRenderReviewStep(); } }
