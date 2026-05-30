// ═══════════════════════════════════════════════════════════════════════════
// projects/projects.js — Software Project Management client
// ═══════════════════════════════════════════════════════════════════════════

// ── State ─────────────────────────────────────────────────────────────────────
let _ptProjects      = [];
let _ptTasks         = [];
let _ptUsers         = [];
let _ptStatuses      = [];
let _ptCurrentProjId = null;
let _ptEditingProjId = null;
let _ptEditingTaskId = null;
let _ptEditingStatusKey = null;
let _ptTaskParentId  = null;
let _ptConfirmFn     = null;
let _ptToastTimer    = null;
let _ptFilteredTasks = [];

// Dynamic helpers — derived from _ptStatuses loaded from the API
function ptStatusLabels() {
  const labels = {};
  _ptStatuses.forEach(s => { labels[s.key] = s.name; });
  return labels;
}
function ptStatusOrder() {
  return [..._ptStatuses].sort((a, b) => a.order - b.order).map(s => s.key);
}

const PT_PRIORITY_LABELS = {
  low:      'Low',
  medium:   'Medium',
  high:     'High',
  critical: 'Critical',
};

// ── Bootstrap ─────────────────────────────────────────────────────────────────
setOnUnauthorized(ptLogout);

function ptLogout() {
  sessionStorage.clear();
  window.location.href = '../index.php';
}

if (getToken()) {
  document.getElementById('pt-header').style.display = '';
  document.getElementById('pt-screen').style.display = '';
  ptRoute();
} else {
  window.location.href = '../index.php';
}

function ptRoute() {
  const role = getRole();
  document.getElementById('pt-user-badge').textContent = getUser() + ' (' + role + ')';
  if (role === 'admin') {
    document.getElementById('pt-theme-btn').style.display = '';
    document.getElementById('admin-panel').style.display  = '';
    ptLoadAdminData();
  } else {
    document.getElementById('user-panel').style.display = '';
    ptLoadUserData();
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// Toast
// ═══════════════════════════════════════════════════════════════════════════
function ptToast(msg, type = 'success', ms = 3200) {
  const el = document.getElementById('pt-toast');
  el.textContent = msg;
  el.className   = 'show ' + type;
  clearTimeout(_ptToastTimer);
  _ptToastTimer = setTimeout(() => el.classList.remove('show'), ms);
}

// ═══════════════════════════════════════════════════════════════════════════
// Tab navigation
// ═══════════════════════════════════════════════════════════════════════════
function ptShowTab(name) {
  document.querySelectorAll('#admin-panel .pt-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === name);
  });
  document.querySelectorAll('#admin-panel .pt-tab-panel').forEach(p => {
    p.classList.toggle('active', p.id === 'tab-' + name);
  });
  if (name === 'board')     ptRenderBoard();
  if (name === 'burndown')  ptRenderBurndown('burndown-wrap');
  if (name === 'settings')  ptLoadTemplates();
  if (name === 'statuses')  ptLoadStatuses();
}

function ptShowUserTab(name) {
  document.querySelectorAll('#user-panel .pt-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === name);
  });
  document.querySelectorAll('#user-panel .pt-tab-panel').forEach(p => {
    p.classList.toggle('active', p.id === 'tab-' + name);
  });
  if (name === 'u-burndown') ptRenderBurndown('u-burndown-wrap');
}

// ═══════════════════════════════════════════════════════════════════════════
// Project selector
// ═══════════════════════════════════════════════════════════════════════════
function ptPopulateProjectSelector(projects) {
  const sel = document.getElementById('pt-project-select');
  const cur = sel.value;
  sel.innerHTML = '<option value="">— select —</option>';
  projects.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p.id;
    opt.textContent = p.name;
    sel.appendChild(opt);
  });
  if (cur && projects.find(p => String(p.id) === cur)) {
    sel.value = cur;
  }
  // Sync state after selector rebuild
  _ptCurrentProjId = sel.value ? parseInt(sel.value) : null;
  ptUpdateProjectDates();
  document.getElementById('pt-project-bar').style.display = projects.length ? '' : 'none';
}

function ptOnProjectChange() {
  const sel = document.getElementById('pt-project-select');
  _ptCurrentProjId = sel.value ? parseInt(sel.value) : null;
  ptUpdateProjectDates();
  const role = getRole();
  if (role === 'admin') {
    document.getElementById('btn-add-root-task').disabled = !_ptCurrentProjId;
    if (_ptCurrentProjId) ptLoadTasks(_ptCurrentProjId);
    else {
      ptSetWrap('tasks-table-wrap', '<p class="pt-empty-msg">Select a project to view its tasks.</p>');
      ptSetWrap('board-wrap',       '<p class="pt-empty-msg">Select a project to view the status board.</p>');
      ptSetWrap('burndown-wrap',    '<p class="pt-empty-msg">Select a project to view the burndown chart.</p>');
    }
  } else {
    if (_ptCurrentProjId) {
      ptLoadUserTasks(_ptCurrentProjId);
      ptLoadAllTasksUser(_ptCurrentProjId);
    } else {
      ptSetWrap('u-mytasks-wrap',  '<p class="pt-empty-msg">Select a project to view your tasks.</p>');
      ptSetWrap('u-alltasks-wrap', '<p class="pt-empty-msg">Select a project to view all tasks.</p>');
      ptSetWrap('u-burndown-wrap', '<p class="pt-empty-msg">Select a project to view the burndown chart.</p>');
    }
  }
}

function ptUpdateProjectDates() {
  const el   = document.getElementById('pt-project-dates');
  const proj = _ptProjects.find(p => p.id === _ptCurrentProjId);
  if (proj && (proj.start_date || proj.end_date)) {
    el.textContent = [proj.start_date, proj.end_date].filter(Boolean).join(' → ');
  } else {
    el.textContent = '';
  }
}

function ptSetWrap(id, html) {
  const el = document.getElementById(id);
  if (el) el.innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN: Load data
// ═══════════════════════════════════════════════════════════════════════════
async function ptLoadAdminData() {
  try {
    const [projRes, userRes, statusRes] = await Promise.all([
      apiFetch('projects.php?action=get-all-projects'),
      apiFetch('../index.php?action=get-users'),
      apiFetch('projects.php?action=get-statuses'),
    ]);
    if (!projRes.ok) throw new Error('Failed to load projects');
    _ptProjects = await projRes.json();
    if (userRes.ok) {
      const ud = await userRes.json();
      _ptUsers = (ud.guests || []).concat(ud.admin ? [ud.admin] : []);
    }
    if (statusRes.ok) _ptStatuses = await statusRes.json();
    ptPopulateStatusSelects();
    ptRenderProjectsTable();
    ptPopulateProjectSelector(_ptProjects);
    document.getElementById('pt-project-bar').style.display = _ptProjects.length ? '' : 'none';
  } catch (e) {
    ptToast(e.message, 'error');
  }
}

async function ptLoadTasks(projectId) {
  ptSetWrap('tasks-table-wrap', '<div class="pt-loading"><div class="pt-spinner"></div> Loading…</div>');
  try {
    const res = await apiFetch('projects.php?action=get-all-tasks&project_id=' + projectId);
    if (!res.ok) throw new Error('Failed to load tasks');
    _ptTasks = await res.json();
    _ptFilteredTasks = _ptTasks.slice();
    ptRenderTasksTable(_ptFilteredTasks);
    // Refresh board/burndown if visible
    const activeTab = document.querySelector('#admin-panel .pt-tab.active');
    if (activeTab) {
      if (activeTab.dataset.tab === 'board')    ptRenderBoard();
      if (activeTab.dataset.tab === 'burndown') ptRenderBurndown('burndown-wrap');
    }
  } catch (e) {
    ptToast(e.message, 'error');
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN: Projects CRUD
// ═══════════════════════════════════════════════════════════════════════════
function ptRenderProjectsTable() {
  const wrap = document.getElementById('projects-table-wrap');
  if (!_ptProjects.length) {
    wrap.innerHTML = '<p class="pt-empty-msg">No projects yet. Create your first project.</p>';
    return;
  }
  let html = '<table class="pt-table"><thead><tr>'
    + '<th>Name</th><th>Description</th><th>Start</th><th>End</th><th>Actions</th>'
    + '</tr></thead><tbody>';
  _ptProjects.forEach(p => {
    html += `<tr>
      <td><strong>${esc(p.name)}</strong></td>
      <td class="pt-muted">${esc(p.description || '—')}</td>
      <td>${esc(p.start_date || '—')}</td>
      <td>${esc(p.end_date   || '—')}</td>
      <td class="pt-actions">
        <button class="btn btn-sm" onclick="ptOpenProjectModal(${p.id})">Edit</button>
        <button class="btn btn-sm btn-danger" onclick="ptConfirmDeleteProject(${p.id})">Delete</button>
      </td>
    </tr>`;
  });
  html += '</tbody></table>';
  wrap.innerHTML = html;
}

function ptOpenProjectModal(id) {
  _ptEditingProjId = id || null;
  const modal = document.getElementById('pt-project-modal');
  document.getElementById('project-modal-title').textContent = id ? 'Edit Project' : 'New Project';
  document.getElementById('pm-error').textContent = '';
  if (id) {
    const p = _ptProjects.find(x => x.id === id);
    if (!p) return;
    document.getElementById('pm-name').value        = p.name        || '';
    document.getElementById('pm-description').value = p.description || '';
    document.getElementById('pm-start-date').value  = p.start_date  || '';
    document.getElementById('pm-end-date').value    = p.end_date    || '';
  } else {
    document.getElementById('pm-name').value        = '';
    document.getElementById('pm-description').value = '';
    document.getElementById('pm-start-date').value  = '';
    document.getElementById('pm-end-date').value    = '';
  }
  document.getElementById('pt-project-overlay').style.display = '';
  modal.style.display = '';
  document.getElementById('pm-name').focus();
}

function ptCloseProjectModal() {
  document.getElementById('pt-project-overlay').style.display = 'none';
  document.getElementById('pt-project-modal').style.display   = 'none';
}

async function ptSubmitProject() {
  const name  = document.getElementById('pm-name').value.trim();
  const start = document.getElementById('pm-start-date').value;
  const end   = document.getElementById('pm-end-date').value;
  const errEl = document.getElementById('pm-error');
  errEl.textContent = '';
  if (!name) { errEl.textContent = 'Name is required.'; return; }
  if (start && end && end < start) { errEl.textContent = 'End date must be after start date.'; return; }
  const body = {
    id:          _ptEditingProjId || undefined,
    name,
    description: document.getElementById('pm-description').value.trim(),
    start_date:  start,
    end_date:    end,
  };
  try {
    const res = await apiFetch('projects.php?action=save-project', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) { const d = await res.json(); throw new Error(d.error || 'Save failed'); }
    ptCloseProjectModal();
    ptToast(_ptEditingProjId ? 'Project updated.' : 'Project created.');
    await ptLoadAdminData();
    if (_ptCurrentProjId) ptLoadTasks(_ptCurrentProjId);
  } catch (e) {
    errEl.textContent = e.message;
  }
}

function ptConfirmDeleteProject(id) {
  const p = _ptProjects.find(x => x.id === id);
  ptShowConfirm(
    `Delete project "${p ? p.name : id}" and all its tasks? This cannot be undone.`,
    async () => {
      try {
        const res = await apiFetch('projects.php?action=delete-project', { method: 'POST', body: JSON.stringify({ id }) });
        if (!res.ok) { const d = await res.json(); throw new Error(d.error || 'Delete failed'); }
        ptToast('Project deleted.');
        if (_ptCurrentProjId === id) { _ptCurrentProjId = null; _ptTasks = []; }
        await ptLoadAdminData();
      } catch (e) { ptToast(e.message, 'error'); }
    }
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN: Tasks CRUD
// ═══════════════════════════════════════════════════════════════════════════
function ptRenderTasksTable(tasks) {
  const wrap = document.getElementById('tasks-table-wrap');
  if (!_ptCurrentProjId) {
    wrap.innerHTML = '<p class="pt-empty-msg">Select a project to view its tasks.</p>';
    return;
  }
  if (!tasks.length) {
    wrap.innerHTML = '<p class="pt-empty-msg">No tasks found.</p>';
    return;
  }
  // Build tree, but render filtered list with hierarchy hints
  const taskMap = {};
  tasks.forEach(t => { taskMap[t.id] = t; });
  const allMap  = {};
  _ptTasks.forEach(t => { allMap[t.id] = t; });

  // Compute depth for each task using full task set
  function depth(t) {
    let d = 0, cur = t;
    while (cur.parent_id !== null && cur.parent_id !== undefined && allMap[cur.parent_id]) {
      d++; cur = allMap[cur.parent_id];
    }
    return d;
  }

  // Sort: by parent hierarchy then position
  const sorted = ptSortTasksTree(_ptTasks, null).filter(t => taskMap[t.id]);

  let html = '<table class="pt-table pt-tasks-table"><thead><tr>'
    + '<th>Task</th><th>Status</th><th>Priority</th><th>Pts</th>'
    + '<th>Assignees</th><th>Int. Date</th><th>Done</th><th>Branch</th><th>Actions</th>'
    + '</tr></thead><tbody>';

  sorted.forEach(t => {
    const d = depth(t);
    const indent = d * 1.4;
    const doneHtml = t.done
      ? '<span class="pt-done-badge" title="Done">✓</span>'
      : '<span class="pt-muted">—</span>';
    html += `<tr class="pt-task-row${t.done ? ' pt-task-done' : ''}" data-status="${esc(t.status)}" data-priority="${esc(t.priority)}">
      <td>
        <span class="pt-task-indent" style="padding-left:${indent}rem">
          ${d > 0 ? '<span class="pt-subtask-icon">↳</span>' : ''}
          <a href="#" class="pt-task-link" onclick="ptShowTaskDetail(${t.id});return false">${esc(t.name)}</a>
        </span>
      </td>
      <td>${ptStatusBadge(t.status)}</td>
      <td>${ptPriorityBadge(t.priority)}</td>
      <td class="pt-center">${t.points || 0}</td>
      <td class="pt-muted">${(t.assignees || []).map(esc).join(', ') || '—'}</td>
      <td class="pt-muted">${esc(t.integration_date || '—')}</td>
      <td class="pt-center">${doneHtml}</td>
      <td class="pt-muted pt-branch">${esc(t.integration_branch || '—')}</td>
      <td class="pt-actions">
        <button class="btn btn-sm" onclick="ptOpenTaskModal(${t.id}, null)">Edit</button>
        <button class="btn btn-sm" onclick="ptOpenTaskModal(null, ${t.id})" title="Add subtask">+ Sub</button>
        <button class="btn btn-sm btn-danger" onclick="ptConfirmDeleteTask(${t.id})">Del</button>
      </td>
    </tr>`;
  });
  html += '</tbody></table>';
  wrap.innerHTML = html;
}

// Return tasks sorted depth-first (parents before children, preserving creation order)
function ptSortTasksTree(tasks, parentId) {
  const children = tasks
    .filter(t => (t.parent_id === parentId) || (parentId === null && (t.parent_id === null || t.parent_id === undefined)))
    .sort((a, b) => a.id - b.id);
  let result = [];
  children.forEach(c => {
    result.push(c);
    result = result.concat(ptSortTasksTree(tasks, c.id));
  });
  return result;
}

function ptApplyTaskFilters() {
  const statusF   = document.getElementById('tf-status').value;
  const priorityF = document.getElementById('tf-priority').value;
  const searchF   = document.getElementById('tf-search').value.trim().toLowerCase();

  _ptFilteredTasks = _ptTasks.filter(t => {
    if (statusF   && t.status   !== statusF)   return false;
    if (priorityF && t.priority !== priorityF) return false;
    if (searchF   && !t.name.toLowerCase().includes(searchF) &&
        !(t.description || '').toLowerCase().includes(searchF)) return false;
    return true;
  });
  ptRenderTasksTable(_ptFilteredTasks);
}

function ptResetTaskFilters() {
  document.getElementById('tf-status').value   = '';
  document.getElementById('tf-priority').value = '';
  document.getElementById('tf-search').value   = '';
  _ptFilteredTasks = _ptTasks.slice();
  ptRenderTasksTable(_ptFilteredTasks);
}

function ptOpenTaskModal(taskId, parentId) {
  _ptEditingTaskId = taskId || null;
  _ptTaskParentId  = parentId || null;
  const modal = document.getElementById('pt-task-modal');
  document.getElementById('task-modal-title').textContent = taskId ? 'Edit Task' : (parentId ? 'New Subtask' : 'New Task');
  document.getElementById('tm-error').textContent = '';

  // Populate assignees checkboxes
  const wrap = document.getElementById('tm-assignees-wrap');
  wrap.innerHTML = '';
  _ptUsers.filter(u => u.enabled !== false).forEach(u => {
    const lbl = document.createElement('label');
    lbl.className = 'pt-assignee-check';
    lbl.innerHTML = `<input type="checkbox" value="${esc(u.username)}"> ${esc(u.name || u.username)}`;
    wrap.appendChild(lbl);
  });

  if (taskId) {
    const t = _ptTasks.find(x => x.id === taskId);
    if (!t) return;
    document.getElementById('tm-name').value               = t.name               || '';
    document.getElementById('tm-description').value        = t.description        || '';
    document.getElementById('tm-specification').value      = t.specification      || '';
    document.getElementById('tm-status').value             = t.status             || 'todo';
    document.getElementById('tm-priority').value           = t.priority           || 'medium';
    document.getElementById('tm-points').value             = t.points             || 0;
    document.getElementById('tm-assigned-date').value      = t.assigned_date      || '';
    document.getElementById('tm-integration-date').value   = t.integration_date   || '';
    document.getElementById('tm-integration-branch').value = t.integration_branch || '';
    document.getElementById('tm-done').checked             = !!t.done;
    // Restore assignees
    const assignees = t.assignees || [];
    wrap.querySelectorAll('input[type=checkbox]').forEach(cb => {
      cb.checked = assignees.includes(cb.value);
    });
  } else {
    document.getElementById('tm-name').value               = '';
    document.getElementById('tm-description').value        = '';
    document.getElementById('tm-specification').value      = '';
    document.getElementById('tm-status').value             = 'todo';
    document.getElementById('tm-priority').value           = 'medium';
    document.getElementById('tm-points').value             = 0;
    document.getElementById('tm-assigned-date').value      = '';
    document.getElementById('tm-integration-date').value   = '';
    document.getElementById('tm-integration-branch').value = '';
    document.getElementById('tm-done').checked             = false;
  }

  // Reset spec tab to edit
  ptSpecTab('edit');
  document.getElementById('pt-task-overlay').style.display = '';
  modal.style.display = '';
  document.getElementById('tm-name').focus();
}

function ptCloseTaskModal() {
  document.getElementById('pt-task-overlay').style.display = 'none';
  document.getElementById('pt-task-modal').style.display   = 'none';
}

function ptSpecTab(tab) {
  const editEl    = document.getElementById('tm-specification');
  const previewEl = document.getElementById('tm-spec-preview');
  document.querySelectorAll('.pt-spec-tab').forEach(t => {
    t.classList.toggle('active', t.textContent.toLowerCase() === tab);
  });
  if (tab === 'preview') {
    previewEl.innerHTML = typeof marked !== 'undefined'
      ? marked.parse(editEl.value || '')
      : '<em>Markdown renderer not available.</em>';
    editEl.style.display    = 'none';
    previewEl.style.display = '';
  } else {
    editEl.style.display    = '';
    previewEl.style.display = 'none';
  }
}

async function ptSubmitTask() {
  const name  = document.getElementById('tm-name').value.trim();
  const errEl = document.getElementById('tm-error');
  errEl.textContent = '';
  if (!name) { errEl.textContent = 'Task name is required.'; return; }
  if (!_ptCurrentProjId) { errEl.textContent = 'Select a project first.'; return; }

  const assignees = [];
  document.querySelectorAll('#tm-assignees-wrap input[type=checkbox]:checked').forEach(cb => {
    assignees.push(cb.value);
  });

  const body = {
    id:                 _ptEditingTaskId || undefined,
    project_id:         _ptCurrentProjId,
    parent_id:          _ptEditingTaskId ? undefined : (_ptTaskParentId || null),
    name,
    description:        document.getElementById('tm-description').value.trim(),
    specification:      document.getElementById('tm-specification').value,
    status:             document.getElementById('tm-status').value,
    priority:           document.getElementById('tm-priority').value,
    points:             parseInt(document.getElementById('tm-points').value) || 0,
    assignees,
    assigned_date:      document.getElementById('tm-assigned-date').value,
    integration_date:   document.getElementById('tm-integration-date').value,
    integration_branch: document.getElementById('tm-integration-branch').value.trim(),
    done:               document.getElementById('tm-done').checked,
  };

  try {
    const res = await apiFetch('projects.php?action=save-task', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) { const d = await res.json(); throw new Error(d.error || 'Save failed'); }
    ptCloseTaskModal();
    ptToast(_ptEditingTaskId ? 'Task updated.' : 'Task created.');
    await ptLoadTasks(_ptCurrentProjId);
  } catch (e) {
    errEl.textContent = e.message;
  }
}

function ptConfirmDeleteTask(id) {
  const t = _ptTasks.find(x => x.id === id);
  const childCount = _ptTasks.filter(x => x.parent_id === id).length;
  const extra = childCount > 0 ? ` This will also delete ${childCount} subtask(s).` : '';
  ptShowConfirm(
    `Delete task "${t ? t.name : id}"?${extra} This cannot be undone.`,
    async () => {
      try {
        const res = await apiFetch('projects.php?action=delete-task', { method: 'POST', body: JSON.stringify({ id }) });
        if (!res.ok) { const d = await res.json(); throw new Error(d.error || 'Delete failed'); }
        ptToast('Task deleted.');
        await ptLoadTasks(_ptCurrentProjId);
      } catch (e) { ptToast(e.message, 'error'); }
    }
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// Task detail (read-only view for both admin and users)
// ═══════════════════════════════════════════════════════════════════════════
function ptShowTaskDetail(taskId) {
  const t = _ptTasks.find(x => x.id === taskId);
  if (!t) return;

  const parent = t.parent_id ? _ptTasks.find(x => x.id === t.parent_id) : null;
  const proj   = _ptProjects.find(p => p.id === t.project_id);

  document.getElementById('detail-modal-title').textContent = t.name;

  let body = `<div class="pt-detail-meta">
    ${parent ? `<div class="pt-detail-row"><span class="pt-detail-label">Parent</span><span>${esc(parent.name)}</span></div>` : ''}
    ${proj ? `<div class="pt-detail-row"><span class="pt-detail-label">Project</span><span>${esc(proj.name)}</span></div>` : ''}
    <div class="pt-detail-row"><span class="pt-detail-label">Status</span>${ptStatusBadge(t.status)}</div>
    <div class="pt-detail-row"><span class="pt-detail-label">Priority</span>${ptPriorityBadge(t.priority)}</div>
    <div class="pt-detail-row"><span class="pt-detail-label">Points</span><span>${t.points || 0}</span></div>
    <div class="pt-detail-row"><span class="pt-detail-label">Assignees</span><span>${(t.assignees || []).map(esc).join(', ') || '—'}</span></div>
    <div class="pt-detail-row"><span class="pt-detail-label">Assigned date</span><span>${esc(t.assigned_date || '—')}</span></div>
    <div class="pt-detail-row"><span class="pt-detail-label">Integration date</span><span>${esc(t.integration_date || '—')}</span></div>
    <div class="pt-detail-row"><span class="pt-detail-label">Done</span><span>${t.done ? '<span class="pt-done-badge">✓ Done</span>' : '—'}</span></div>
    <div class="pt-detail-row"><span class="pt-detail-label">Branch</span><span class="pt-branch-text">${esc(t.integration_branch || '—')}</span></div>
  </div>`;

  if (t.description) {
    body += `<div class="pt-detail-section"><h4>Description</h4><p>${esc(t.description)}</p></div>`;
  }
  if (t.specification) {
    body += `<div class="pt-detail-section"><h4>Specification</h4><div class="pt-spec-preview pt-spec-preview-detail">${
      typeof marked !== 'undefined' ? marked.parse(t.specification) : esc(t.specification)
    }</div></div>`;
  }

  document.getElementById('pt-detail-body').innerHTML = body;

  // Footer: edit button for admin
  const footer = document.getElementById('pt-detail-footer');
  if (getRole() === 'admin') {
    footer.innerHTML = `<button class="btn btn-primary" onclick="ptCloseDetail();ptOpenTaskModal(${t.id},null)">Edit</button>`;
  } else {
    footer.innerHTML = '';
  }

  document.getElementById('pt-detail-overlay').style.display = '';
  document.getElementById('pt-detail-modal').style.display   = '';
}

function ptCloseDetail() {
  document.getElementById('pt-detail-overlay').style.display = 'none';
  document.getElementById('pt-detail-modal').style.display   = 'none';
}

// ═══════════════════════════════════════════════════════════════════════════
// Status Board
// ═══════════════════════════════════════════════════════════════════════════
function ptRenderBoard() {
  const wrap = document.getElementById('board-wrap');
  if (!_ptCurrentProjId) {
    wrap.innerHTML = '<p class="pt-empty-msg">Select a project to view the status board.</p>';
    return;
  }
  if (!_ptTasks.length) {
    wrap.innerHTML = '<p class="pt-empty-msg">No tasks in this project.</p>';
    return;
  }

  let html = '<div class="pt-board">';
  ptStatusOrder().forEach(status => {
    // Only show tasks that are NOT done in their status column
    const tasks = _ptTasks.filter(t => t.status === status && !t.done);
    html += `<div class="pt-board-col">
      <div class="pt-board-col-header">
        ${ptStatusBadge(status)}
        <span class="pt-board-count">${tasks.length}</span>
      </div>
      <div class="pt-board-cards">`;
    if (!tasks.length) {
      html += '<p class="pt-board-empty">—</p>';
    }
    tasks.sort((a, b) => {
      const po = { critical: 0, high: 1, medium: 2, low: 3 };
      return (po[a.priority] || 2) - (po[b.priority] || 2);
    }).forEach(t => {
      html += `<div class="pt-board-card">
        <div class="pt-board-card-title">
          <a href="#" class="pt-task-link" onclick="ptShowTaskDetail(${t.id});return false">${esc(t.name)}</a>
        </div>
        <div class="pt-board-card-meta">
          ${ptPriorityBadge(t.priority)}
          <span class="pt-board-pts">${t.points || 0} pts</span>
        </div>
        ${(t.assignees || []).length ? `<div class="pt-board-assignees">${(t.assignees || []).map(esc).join(', ')}</div>` : ''}
        ${t.integration_branch ? `<div class="pt-board-branch">${esc(t.integration_branch)}</div>` : ''}
      </div>`;
    });
    html += '</div></div>';
  });

  // Done column — all tasks with done = true regardless of status
  const doneTasks = _ptTasks.filter(t => t.done);
  html += `<div class="pt-board-col pt-board-col-done">
    <div class="pt-board-col-header">
      <span class="pt-badge pt-status-done">\u2713 Done</span>
      <span class="pt-board-count">${doneTasks.length}</span>
    </div>
    <div class="pt-board-cards">`;
  if (!doneTasks.length) {
    html += '<p class="pt-board-empty">—</p>';
  }
  doneTasks.sort((a, b) => {
    // Sort by completion date descending (most recently done first)
    const ca = a.completed_at || '', cb = b.completed_at || '';
    return cb.localeCompare(ca);
  }).forEach(t => {
    html += `<div class="pt-board-card pt-board-card-done">
      <div class="pt-board-card-title">
        <a href="#" class="pt-task-link" onclick="ptShowTaskDetail(${t.id});return false">${esc(t.name)}</a>
      </div>
      <div class="pt-board-card-meta">
        ${ptPriorityBadge(t.priority)}
        <span class="pt-board-pts">${t.points || 0} pts</span>
      </div>
      ${t.integration_date ? `<div class="pt-board-done-date">\u2713 ${esc(t.integration_date)}</div>` : ''}
      ${(t.assignees || []).length ? `<div class="pt-board-assignees">${(t.assignees || []).map(esc).join(', ')}</div>` : ''}
    </div>`;
  });
  html += '</div></div>';

  html += '</div>';
  wrap.innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════════════════
// Burndown chart (SVG)
// ═══════════════════════════════════════════════════════════════════════════
function ptRenderBurndown(wrapId) {
  const wrap = document.getElementById(wrapId);
  const proj = _ptProjects.find(p => p.id === _ptCurrentProjId);

  if (!proj) {
    wrap.innerHTML = '<p class="pt-empty-msg">Select a project to view the burndown chart.</p>';
    return;
  }
  if (!proj.start_date || !proj.end_date) {
    wrap.innerHTML = '<p class="pt-empty-msg">Set start and end dates on the project to display the burndown chart.</p>';
    return;
  }

  const activeTasks = _ptTasks.filter(t => t.status !== 'cancelled');
  const totalPoints = activeTasks.reduce((s, t) => s + (t.points || 0), 0);

  if (totalPoints === 0) {
    wrap.innerHTML = '<p class="pt-empty-msg">No story points assigned. Add points to tasks to see the burndown chart.</p>';
    return;
  }

  const startDate = new Date(proj.start_date + 'T00:00:00');
  const endDate   = new Date(proj.end_date   + 'T00:00:00');
  const today     = new Date(); today.setHours(0, 0, 0, 0);
  const todayIso  = today.toISOString();

  const MS_DAY   = 86400000;
  const totalMs  = endDate - startDate;
  const totalDays = Math.max(1, Math.round(totalMs / MS_DAY));

  // Generate days for actual line: start → min(today, end)
  const chartEnd  = today <= endDate ? today : endDate;
  const actualDays = [];
  for (let d = new Date(startDate); d <= chartEnd; d = new Date(d.getTime() + MS_DAY)) {
    const dIso = d.toISOString();
    const remaining = activeTasks.filter(t => {
      // A task is considered done on day D if:
      // - it has completed_at ≤ dIso, OR
      // - done = true but no completed_at (treat as done from today)
      if (t.done && !t.completed_at) return dIso < todayIso; // still remaining before today
      return !t.completed_at || t.completed_at > dIso;
    }).reduce((s, t) => s + (t.points || 0), 0);
    actualDays.push({ date: new Date(d), pts: remaining });
  }

  // SVG dimensions
  const W = 680, H = 320;
  const PAD = { top: 20, right: 30, bottom: 50, left: 56 };
  const CW  = W - PAD.left - PAD.right;
  const CH  = H - PAD.top  - PAD.bottom;

  const toX = (date) => PAD.left + (date - startDate) / totalMs * CW;
  const toY = (pts)  => PAD.top  + (1 - pts / totalPoints) * CH;

  // Ideal line points
  const idealX1 = PAD.left, idealY1 = toY(totalPoints);
  const idealX2 = PAD.left + CW, idealY2 = toY(0);

  // Actual polyline points
  const actualPts = actualDays.map(d => `${toX(d.date).toFixed(1)},${toY(d.pts).toFixed(1)}`).join(' ');

  // Today line
  const todayX    = toX(today).toFixed(1);
  const showToday = today >= startDate && today <= endDate;

  // Y-axis tick count
  const yTicks = 5;
  let yTicksHtml = '';
  for (let i = 0; i <= yTicks; i++) {
    const pts = Math.round(totalPoints * i / yTicks);
    const y   = toY(pts).toFixed(1);
    yTicksHtml += `<line x1="${PAD.left}" y1="${y}" x2="${PAD.left + CW}" y2="${y}" stroke="#eee" stroke-width="1"/>`;
    yTicksHtml += `<text x="${PAD.left - 6}" y="${parseFloat(y) + 4}" text-anchor="end" font-size="11" fill="#888">${pts}</text>`;
  }

  // X-axis ticks (every ~7 days if span < 60 days, else ~14 days)
  const tickInterval = totalDays <= 60 ? 7 : 14;
  let xTicksHtml = '';
  for (let day = 0; day <= totalDays; day += tickInterval) {
    const d = new Date(startDate.getTime() + day * MS_DAY);
    const x = toX(d).toFixed(1);
    const label = d.toLocaleDateString('en-CA').slice(5); // MM-DD
    xTicksHtml += `<line x1="${x}" y1="${PAD.top}" x2="${x}" y2="${PAD.top + CH}" stroke="#eee" stroke-width="1"/>`;
    xTicksHtml += `<text x="${x}" y="${PAD.top + CH + 18}" text-anchor="middle" font-size="11" fill="#888">${label}</text>`;
  }

  const currentPts = actualDays.length ? actualDays[actualDays.length - 1].pts : totalPoints;
  const pctDone    = totalPoints > 0 ? Math.round((totalPoints - currentPts) / totalPoints * 100) : 0;

  let svg = `<svg viewBox="0 0 ${W} ${H}" class="pt-burndown-svg" aria-label="Burndown chart">
  <!-- Grid -->
  ${yTicksHtml}
  ${xTicksHtml}
  <!-- Axes -->
  <line x1="${PAD.left}" y1="${PAD.top}" x2="${PAD.left}" y2="${PAD.top + CH}" stroke="#ccc" stroke-width="1.5"/>
  <line x1="${PAD.left}" y1="${PAD.top + CH}" x2="${PAD.left + CW}" y2="${PAD.top + CH}" stroke="#ccc" stroke-width="1.5"/>
  <!-- Ideal line -->
  <line x1="${idealX1}" y1="${idealY1}" x2="${idealX2}" y2="${idealY2}"
    stroke="#aaa" stroke-width="1.8" stroke-dasharray="6,4" opacity="0.8"/>
  <!-- Actual line -->
  ${actualPts ? `<polyline points="${actualPts}" fill="none" stroke="#1a6fa8" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>` : ''}
  <!-- Today marker -->
  ${showToday ? `<line x1="${todayX}" y1="${PAD.top}" x2="${todayX}" y2="${PAD.top + CH}" stroke="#e05" stroke-width="1.5" stroke-dasharray="3,3" opacity="0.7"/>
  <text x="${todayX}" y="${PAD.top - 5}" text-anchor="middle" font-size="10" fill="#e05">Today</text>` : ''}
  <!-- Y label -->
  <text transform="rotate(-90)" x="${-(PAD.top + CH/2)}" y="14" text-anchor="middle" font-size="11" fill="#888">Story Points</text>
  <!-- X label -->
  <text x="${PAD.left + CW/2}" y="${H - 6}" text-anchor="middle" font-size="11" fill="#888">Date</text>
  <!-- Legend -->
  <line x1="${W - 170}" y1="${PAD.top + 10}" x2="${W - 145}" y2="${PAD.top + 10}" stroke="#aaa" stroke-width="2" stroke-dasharray="6,4"/>
  <text x="${W - 140}" y="${PAD.top + 14}" font-size="11" fill="#666">Ideal</text>
  <line x1="${W - 170}" y1="${PAD.top + 26}" x2="${W - 145}" y2="${PAD.top + 26}" stroke="#1a6fa8" stroke-width="2.5"/>
  <text x="${W - 140}" y="${PAD.top + 30}" font-size="11" fill="#666">Actual</text>
</svg>`;

  wrap.innerHTML = `
  <div class="pt-burndown-header">
    <div class="pt-burndown-stat"><span class="pt-burndown-val">${totalPoints}</span><span class="pt-burndown-lbl">Total pts</span></div>
    <div class="pt-burndown-stat"><span class="pt-burndown-val">${currentPts}</span><span class="pt-burndown-lbl">Remaining</span></div>
    <div class="pt-burndown-stat"><span class="pt-burndown-val">${pctDone}%</span><span class="pt-burndown-lbl">Done</span></div>
  </div>
  <div class="pt-burndown-chart">${svg}</div>`;
}

// ═══════════════════════════════════════════════════════════════════════════
// Statuses CRUD (admin)
// ═══════════════════════════════════════════════════════════════════════════

// Populate the status <select> elements from _ptStatuses
function ptPopulateStatusSelects() {
  const sorted = [..._ptStatuses].sort((a, b) => a.order - b.order);

  // Task modal status select
  const tmStatus = document.getElementById('tm-status');
  if (tmStatus) {
    const cur = tmStatus.value;
    tmStatus.innerHTML = sorted.map(s =>
      `<option value="${esc(s.key)}">${esc(s.name)}</option>`
    ).join('');
    if (cur && sorted.find(s => s.key === cur)) tmStatus.value = cur;
    else if (sorted.length) tmStatus.value = sorted[0].key;
  }

  // Tasks filter status select
  const tfStatus = document.getElementById('tf-status');
  if (tfStatus) {
    const cur = tfStatus.value;
    tfStatus.innerHTML = '<option value="">All statuses</option>' +
      sorted.map(s => `<option value="${esc(s.key)}">${esc(s.name)}</option>`).join('');
    if (cur) tfStatus.value = cur;
  }
}

async function ptLoadStatuses() {
  try {
    const res = await apiFetch('projects.php?action=get-statuses');
    if (!res.ok) throw new Error('Failed to load statuses');
    _ptStatuses = await res.json();
    ptRenderStatusesTab();
    ptPopulateStatusSelects();
  } catch (e) {
    ptToast(e.message, 'error');
  }
}

function ptRenderStatusesTab() {
  const wrap = document.getElementById('statuses-table-wrap');
  if (!wrap) return;
  if (!_ptStatuses.length) {
    wrap.innerHTML = '<p class="pt-empty-msg">No statuses defined.</p>';
    return;
  }
  const sorted = [..._ptStatuses].sort((a, b) => a.order - b.order);
  let html = '<table class="pt-table"><thead><tr>'
    + '<th>Name</th><th>Key</th><th>Tasks</th><th>Actions</th>'
    + '</tr></thead><tbody>';
  sorted.forEach(s => {
    const inUse = (s.usage_count || 0) > 0;
    html += `<tr>
      <td><strong>${esc(s.name)}</strong> ${ptStatusBadge(s.key)}</td>
      <td class="pt-muted">${esc(s.key)}</td>
      <td class="pt-center">${s.usage_count || 0}</td>
      <td class="pt-actions">
        <button class="btn btn-sm" onclick="ptOpenStatusModal('${esc(s.key)}')">Rename</button>
        ${!inUse
          ? `<button class="btn btn-sm btn-danger" onclick="ptConfirmDeleteStatus('${esc(s.key)}')">Delete</button>`
          : `<span class="pt-muted" title="This status is assigned to tasks and cannot be deleted">In use</span>`
        }
      </td>
    </tr>`;
  });
  html += '</tbody></table>';
  wrap.innerHTML = html;
}

function ptOpenStatusModal(key) {
  _ptEditingStatusKey = key || null;
  document.getElementById('status-modal-title').textContent = key ? 'Rename Status' : 'New Status';
  document.getElementById('sm-error').textContent = '';
  if (key) {
    const s = _ptStatuses.find(x => x.key === key);
    document.getElementById('sm-name').value = s ? s.name : '';
  } else {
    document.getElementById('sm-name').value = '';
  }
  document.getElementById('pt-status-overlay').style.display = '';
  document.getElementById('pt-status-modal').style.display   = '';
  document.getElementById('sm-name').focus();
}

function ptCloseStatusModal() {
  document.getElementById('pt-status-overlay').style.display = 'none';
  document.getElementById('pt-status-modal').style.display   = 'none';
}

async function ptSubmitStatus() {
  const name  = document.getElementById('sm-name').value.trim();
  const errEl = document.getElementById('sm-error');
  errEl.textContent = '';
  if (!name) { errEl.textContent = 'Name is required.'; return; }

  try {
    const body = { key: _ptEditingStatusKey || '', name };
    const res  = await apiFetch('projects.php?action=save-status', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) { const d = await res.json(); throw new Error(d.error || 'Save failed'); }
    ptCloseStatusModal();
    ptToast(_ptEditingStatusKey ? 'Status renamed.' : 'Status created.');
    await ptLoadStatuses();
  } catch (e) {
    errEl.textContent = e.message;
  }
}

function ptConfirmDeleteStatus(key) {
  const s = _ptStatuses.find(x => x.key === key);
  ptShowConfirm(
    `Delete status "${s ? s.name : key}"? This cannot be undone.`,
    async () => {
      try {
        const res = await apiFetch('projects.php?action=delete-status', { method: 'POST', body: JSON.stringify({ key }) });
        if (!res.ok) { const d = await res.json(); throw new Error(d.error || 'Delete failed'); }
        ptToast('Status deleted.');
        await ptLoadStatuses();
      } catch (e) { ptToast(e.message, 'error'); }
    }
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// Settings: theme
// ═══════════════════════════════════════════════════════════════════════════
async function ptLoadTemplates() {
  try {
    const res  = await apiFetch('projects.php?action=get-projects-templates');
    const list = await res.json();
    const cur  = document.getElementById('pt-theme-link').href.split('/').pop();
    const wrap = document.getElementById('pt-theme-list');
    wrap.innerHTML = list.map(t => `
      <button class="pt-theme-option ${t === decodeURIComponent(cur) ? 'active' : ''}"
        onclick="ptApplyTheme('${esc(t)}')">${t.replace('.css', '')}</button>`
    ).join('');
  } catch (e) { /* silent */ }
}

async function ptApplyTheme(theme) {
  document.getElementById('pt-theme-link').href = 'templates-projects/' + encodeURIComponent(theme);
  document.querySelectorAll('.pt-theme-option').forEach(b => {
    b.classList.toggle('active', b.textContent.trim() + '.css' === theme || b.textContent.trim() === theme.replace('.css', ''));
  });
  try {
    const res = await apiFetch('projects.php?action=save-projects-theme', { method: 'POST', body: JSON.stringify({ theme }) });
    if (!res.ok) throw new Error('Save failed');
    ptToast('Theme saved.');
  } catch (e) { ptToast(e.message, 'error'); }
}

function ptToggleThemePanel() {
  ptShowTab('settings');
}

// ═══════════════════════════════════════════════════════════════════════════
// USER: Load data
// ═══════════════════════════════════════════════════════════════════════════
async function ptLoadUserData() {
  try {
    const [projRes, statusRes] = await Promise.all([
      apiFetch('projects.php?action=get-projects-for-user'),
      apiFetch('projects.php?action=get-statuses'),
    ]);
    if (!projRes.ok) throw new Error('Failed to load projects');
    _ptProjects = await projRes.json();
    if (statusRes.ok) _ptStatuses = await statusRes.json();
    ptPopulateProjectSelector(_ptProjects);
    document.getElementById('pt-project-bar').style.display = _ptProjects.length ? '' : 'none';
  } catch (e) {
    ptToast(e.message, 'error');
  }
}

async function ptLoadUserTasks(projectId) {
  ptSetWrap('u-mytasks-wrap', '<div class="pt-loading"><div class="pt-spinner"></div> Loading…</div>');
  ptSetWrap('u-alltasks-wrap', '<div class="pt-loading"><div class="pt-spinner"></div> Loading…</div>');
  try {
    const [myRes, allRes] = await Promise.all([
      apiFetch('projects.php?action=get-my-tasks&project_id=' + projectId),
      apiFetch('projects.php?action=get-project-tasks&project_id=' + projectId),
    ]);
    if (!myRes.ok || !allRes.ok) throw new Error('Failed to load tasks');
    const myTasks  = await myRes.json();
    _ptTasks       = await allRes.json();
    ptRenderUserMyTasks(myTasks);
    ptRenderUserAllTasks(_ptTasks);
    // Refresh burndown if that tab is currently visible
    const activeUserTab = document.querySelector('#user-panel .pt-tab.active');
    if (activeUserTab && activeUserTab.dataset.tab === 'u-burndown') {
      ptRenderBurndown('u-burndown-wrap');
    }
  } catch (e) {
    ptToast(e.message, 'error');
  }
}

async function ptLoadAllTasksUser(projectId) {
  // Already loaded by ptLoadUserTasks — no-op here
}

function ptRenderUserMyTasks(tasks) {
  const wrap = document.getElementById('u-mytasks-wrap');
  if (!tasks.length) {
    wrap.innerHTML = '<p class="pt-empty-msg">No tasks assigned to you in this project.</p>';
    return;
  }
  let html = '';
  ptStatusOrder().forEach(status => {
    const group = tasks.filter(t => t.status === status);
    if (!group.length) return;
    html += `<div class="pt-user-status-group">
      <div class="pt-user-group-header">${ptStatusBadge(status)} <span class="pt-group-count">${group.length}</span></div>`;
    group.forEach(t => {
      html += ptUserTaskCard(t);
    });
    html += '</div>';
  });
  wrap.innerHTML = html;
}

function ptRenderUserAllTasks(tasks) {
  const wrap = document.getElementById('u-alltasks-wrap');
  if (!tasks.length) {
    wrap.innerHTML = '<p class="pt-empty-msg">No tasks in this project.</p>';
    return;
  }
  const sorted = ptSortTasksTree(tasks, null);
  const taskMap = {};
  tasks.forEach(t => { taskMap[t.id] = t; });

  function depth(t) {
    let d = 0, cur = t;
    while (cur.parent_id !== null && cur.parent_id !== undefined && taskMap[cur.parent_id]) {
      d++; cur = taskMap[cur.parent_id];
    }
    return d;
  }

  let html = '<div class="pt-user-task-list">';
  sorted.forEach(t => {
    const d = depth(t);
    html += `<div class="pt-user-task-row" style="padding-left:${d * 1.4 + 0.5}rem">
      ${d > 0 ? '<span class="pt-subtask-icon">↳</span>' : ''}
      <a href="#" class="pt-task-link" onclick="ptShowTaskDetail(${t.id});return false">${esc(t.name)}</a>
      ${ptStatusBadge(t.status)} ${ptPriorityBadge(t.priority)}
      <span class="pt-muted">${t.points || 0} pts</span>
      ${(t.assignees || []).length ? `<span class="pt-muted">${(t.assignees).map(esc).join(', ')}</span>` : ''}
    </div>`;
  });
  html += '</div>';
  wrap.innerHTML = html;
}

function ptUserTaskCard(t) {
  return `<div class="pt-user-task-card" onclick="ptShowTaskDetail(${t.id})">
    <div class="pt-user-task-card-title">${esc(t.name)}</div>
    <div class="pt-user-task-card-meta">
      ${ptPriorityBadge(t.priority)}
      <span class="pt-muted">${t.points || 0} pts</span>
      ${t.integration_date ? `<span class="pt-muted">→ ${esc(t.integration_date)}</span>` : ''}
      ${t.integration_branch ? `<span class="pt-branch-text">${esc(t.integration_branch)}</span>` : ''}
    </div>
  </div>`;
}

// ═══════════════════════════════════════════════════════════════════════════
// Confirm dialog
// ═══════════════════════════════════════════════════════════════════════════
function ptShowConfirm(msg, fn) {
  _ptConfirmFn = fn;
  document.getElementById('pt-confirm-msg').textContent = msg;
  document.getElementById('pt-confirm-overlay').style.display = '';
  document.getElementById('pt-confirm-dialog').style.display  = '';
}

function ptCancelConfirm() {
  _ptConfirmFn = null;
  document.getElementById('pt-confirm-overlay').style.display = 'none';
  document.getElementById('pt-confirm-dialog').style.display  = 'none';
}

function ptDoConfirm() {
  ptCancelConfirm();
  if (typeof _ptConfirmFn === 'function') _ptConfirmFn();
}

// ═══════════════════════════════════════════════════════════════════════════
// Badge helpers
// ═══════════════════════════════════════════════════════════════════════════
function ptStatusBadge(status) {
  const label = ptStatusLabels()[status] || status;
  return `<span class="pt-badge pt-status-${esc(status)}">${esc(label)}</span>`;
}

function ptPriorityBadge(priority) {
  const label = PT_PRIORITY_LABELS[priority] || priority;
  return `<span class="pt-badge pt-priority-${priority}">${label}</span>`;
}

// ── HTML escape helper ────────────────────────────────────────────────────────
function esc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
