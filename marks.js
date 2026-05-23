// marks.js — Qualifications module client script
// Requires: lib/auth-client.js, lib/app-client.js (loaded before this file)

requireAuth('index.php');

// ── State ──────────────────────────────────────────────────────────────
let pqItems    = null;
let pqUsers    = [];
let pqAllMarks = [];
let pqLeaves   = [];

// ── Auth / routing ─────────────────────────────────────────────────────
setOnUnauthorized(() => { window.location.href = 'index.php'; });

function pqShowApp() {
  document.getElementById('app-header').style.display = 'flex';
  document.getElementById('marks-screen').style.display = 'block';
  const badge = document.getElementById('pq-user-badge');
  if (badge) badge.textContent = getUser() + ' (' + getRole() + ')';
}

function pqRoute() {
  const role = getRole();
  if (role === 'admin') {
    document.getElementById('admin-panel').style.display = '';
    document.getElementById('pq-grade-view').style.display = 'none';
    pqShowTab('structure');
    pqLoadStructure();
  } else {
    document.getElementById('admin-panel').style.display = 'none';
    document.getElementById('pq-grade-view').style.display = '';
    pqLoadStudentView();
  }
}

// Auto-start (token already verified by requireAuth at top)
pqShowApp();
pqRoute();

// ── Tab switching ──────────────────────────────────────────────────────
function pqShowTab(name) {
  document.querySelectorAll('.pq-tab').forEach((t, i) => {
    const names = ['structure', 'marks'];
    t.classList.toggle('active', names[i] === name);
  });
  document.querySelectorAll('.pq-tab-panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById('tab-' + name);
  if (panel) panel.classList.add('active');

  if (name === 'marks') pqLoadMarksTab();
}

// ═══════════════════════════════════════════════════════════════════════
// ── STRUCTURE EDITOR ───────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════

async function pqLoadStructure() {
  setStatus('structure-status', '', '');
  document.getElementById('structure-tree-wrap').innerHTML =
    '<div class="pq-loading"><div class="pq-spinner"></div> Loading…</div>';
  try {
    const res = await apiFetch('marks.php?action=get-structure');
    if (!res.ok) throw new Error('Failed to load structure');
    pqItems = await res.json();
    pqRenderStructure();
  } catch (err) {
    document.getElementById('structure-tree-wrap').innerHTML =
      '<p style="color:#c62828;padding:1rem 0">' + err.message + '</p>';
  }
}

function pqRenderStructure() {
  if (!pqItems) return;
  document.getElementById('structure-root-name').value = pqItems.name || '';
  document.getElementById('structure-tree-wrap').innerHTML = '';
  const ul = pqBuildTreeUI(pqItems.subitems || [], []);
  document.getElementById('structure-tree-wrap').appendChild(ul);
  pqUpdateAllWeightSums();
}

function pqBuildTreeUI(children, path) {
  const ul = document.createElement('ul');
  ul.className = 'pq-tree';
  ul.dataset.path = JSON.stringify(path);

  const sumEl = document.createElement('div');
  sumEl.className = 'pq-weight-sum bad';
  sumEl.dataset.sumFor = JSON.stringify(path);
  ul.appendChild(sumEl);

  children.forEach((child, idx) => {
    const childPath = [...path, idx];
    const isLeaf = !child.subitems || child.subitems.length === 0;

    const li = document.createElement('li');
    li.className = 'pq-item';
    li.dataset.path = JSON.stringify(childPath);

    const row = document.createElement('div');
    row.className = 'pq-item-row';

    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'pq-item-name';
    nameInput.value = child.name || '';
    nameInput.placeholder = 'Item name';
    nameInput.maxLength = 128;
    nameInput.dataset.path = JSON.stringify(childPath);
    nameInput.addEventListener('input', () => pqSetNodeField(childPath, 'name', nameInput.value));

    const leafBadge = isLeaf ? (() => {
      const b = document.createElement('span');
      b.className = 'pq-leaf-badge';
      b.textContent = 'leaf';
      return b;
    })() : null;

    const weightWrap = document.createElement('div');
    weightWrap.className = 'pq-item-weight-wrap';
    const weightInput = document.createElement('input');
    weightInput.type = 'number';
    weightInput.className = 'pq-item-weight';
    weightInput.value = child.weight ?? '';
    weightInput.min = 0;
    weightInput.max = 100;
    weightInput.step = 0.01;
    weightInput.dataset.path = JSON.stringify(childPath);
    weightInput.addEventListener('input', () => {
      pqSetNodeField(childPath, 'weight', parseFloat(weightInput.value) || 0);
      pqUpdateWeightSum(path);
    });
    const weightLabel = document.createElement('span');
    weightLabel.className = 'pq-weight-label';
    weightLabel.textContent = '%';
    weightWrap.appendChild(weightInput);
    weightWrap.appendChild(weightLabel);

    const addBtn = document.createElement('button');
    addBtn.className = 'btn btn-sm btn-ghost';
    addBtn.title = 'Add child item';
    addBtn.innerHTML = '<svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
    addBtn.addEventListener('click', () => pqAddChild(childPath));

    const delBtn = document.createElement('button');
    delBtn.className = 'btn btn-sm btn-ghost';
    delBtn.style.color = '#c62828';
    delBtn.title = 'Delete item';
    delBtn.innerHTML = '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
    delBtn.addEventListener('click', () => pqDeleteNode(childPath));

    row.appendChild(nameInput);
    if (leafBadge) row.appendChild(leafBadge);
    row.appendChild(weightWrap);
    row.appendChild(addBtn);
    row.appendChild(delBtn);
    li.appendChild(row);

    if (!isLeaf) {
      const childUl = pqBuildTreeUI(child.subitems, childPath);
      li.appendChild(childUl);
    }

    ul.appendChild(li);
  });

  const addLi = document.createElement('li');
  const addHereBtn = document.createElement('button');
  addHereBtn.className = 'btn btn-sm';
  addHereBtn.style.marginTop = '.35rem';
  addHereBtn.innerHTML = '<svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add item';
  addHereBtn.addEventListener('click', () => pqAddSibling(path));
  addLi.appendChild(addHereBtn);
  ul.appendChild(addLi);

  return ul;
}

// ── Tree mutation helpers ─────────────────────────────────────────────

function pqGetNode(path) {
  let node = pqItems;
  for (const idx of path) node = node.subitems[idx];
  return node;
}

function pqSetNodeField(path, field, value) {
  const node = pqGetNode(path);
  node[field] = value;
}

function pqAddSibling(parentPath) {
  const parent = parentPath.length === 0 ? pqItems : pqGetNode(parentPath);
  if (!parent.subitems) parent.subitems = [];
  pqSyncRootName();
  parent.subitems.push({ name: 'New item', weight: 0 });
  pqRenderStructure();
  pqUpdateAllWeightSums();
}

function pqAddChild(path) {
  pqSyncRootName();
  const node = pqGetNode(path);
  if (!node.subitems) node.subitems = [];
  node.subitems.push({ name: 'New item', weight: 0 });
  pqRenderStructure();
  pqUpdateAllWeightSums();
}

function pqDeleteNode(path) {
  if (!confirm('Delete this item and all its children?')) return;
  pqSyncRootName();
  const parentPath = path.slice(0, -1);
  const idx = path[path.length - 1];
  const parent = parentPath.length === 0 ? pqItems : pqGetNode(parentPath);
  parent.subitems.splice(idx, 1);
  pqRenderStructure();
  pqUpdateAllWeightSums();
}

function pqSyncRootName() {
  const nameInput = document.getElementById('structure-root-name');
  if (nameInput) pqItems.name = nameInput.value.trim() || pqItems.name;
}

// ── Weight sum indicators ────────────────────────────────────────────────

function pqUpdateWeightSum(parentPath) {
  const parent = parentPath.length === 0 ? pqItems : pqGetNode(parentPath);
  const subs = parent.subitems || [];
  const sum = subs.reduce((acc, c) => acc + (parseFloat(c.weight) || 0), 0);
  const rounded = Math.round(sum * 100) / 100;
  const sumEl = document.querySelector('[data-sum-for="' + JSON.stringify(parentPath) + '"]');
  if (sumEl) {
    sumEl.textContent = 'Σ = ' + rounded + ' / 100';
    sumEl.className = 'pq-weight-sum ' + (Math.abs(rounded - 100) < 0.01 ? 'ok' : 'bad');
  }
}

function pqUpdateAllWeightSums() {
  pqWalkPaths(pqItems, [], path => pqUpdateWeightSum(path));
}

function pqWalkPaths(node, path, fn) {
  if (!node.subitems || node.subitems.length === 0) return;
  fn(path);
  node.subitems.forEach((child, idx) => pqWalkPaths(child, [...path, idx], fn));
}

// ── Save structure ──────────────────────────────────────────────────────

async function pqSaveStructure() {
  pqSyncRootName();
  document.querySelectorAll('.pq-item-name').forEach(input => {
    const path = JSON.parse(input.dataset.path);
    pqSetNodeField(path, 'name', input.value);
  });
  document.querySelectorAll('.pq-item-weight').forEach(input => {
    const path = JSON.parse(input.dataset.path);
    pqSetNodeField(path, 'weight', parseFloat(input.value) || 0);
  });

  setStatus('structure-status', '', '');
  document.getElementById('structure-errors').style.display = 'none';

  try {
    const res = await apiFetch('marks.php?action=save-structure', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(pqItems)
    });
    const data = await res.json();

    if (res.status === 422 && data.details) {
      const errEl = document.getElementById('structure-errors');
      const listEl = document.getElementById('structure-errors-list');
      listEl.innerHTML = data.details.map(e => '<li>' + escHtml(e) + '</li>').join('');
      errEl.style.display = '';
      errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      setStatus('structure-status', 'Validation errors — please correct weights', 'err');
      return;
    }
    if (!res.ok) throw new Error(data.error || 'Save failed');

    showToast('Structure saved successfully', 'success');
    setStatus('structure-status', 'Saved', 'ok');
    setTimeout(() => setStatus('structure-status', '', ''), 3000);
  } catch (err) {
    setStatus('structure-status', err.message, 'err');
  }
}

// ═══════════════════════════════════════════════════════════════════════
// ── MARKS EDITOR ───────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════

async function pqLoadMarksTab() {
  setStatus('marks-status', '', '');
  const wrap = document.getElementById('marks-table-wrap');
  wrap.innerHTML = '<div class="pq-loading"><div class="pq-spinner"></div> Loading…</div>';

  try {
    const [resUsers, resMarks] = await Promise.all([
      apiFetch('marks.php?action=get-users'),
      apiFetch('marks.php?action=get-all-marks')
    ]);
    if (!resUsers.ok) throw new Error('Failed to load users');
    if (!resMarks.ok)  throw new Error('Failed to load marks');

    const usersData = await resUsers.json();
    const marksData = await resMarks.json();

    pqUsers    = (usersData.guests || []).filter(u => u.enabled !== false);
    pqAllMarks = marksData.marks || [];
    pqItems    = marksData.items;
    pqLeaves   = pqGetLeavesJS(pqItems);

    wrap.innerHTML = '';
    if (pqLeaves.length === 0) {
      wrap.innerHTML = '<p class="pq-empty">No leaf items defined. Please set up the qualification structure first.</p>';
      return;
    }
    if (pqUsers.length === 0) {
      wrap.innerHTML = '<p class="pq-empty">No enabled student accounts found. Add guest users in the main wiki.</p>';
      return;
    }

    wrap.appendChild(pqBuildMarksTable());
    pqUpdateSubtotals();
    wrap.addEventListener('input', function onMarksInput(e) {
      if (e.target.classList.contains('mark-input')) pqUpdateSubtotals();
    }, { capture: false });
  } catch (err) {
    wrap.innerHTML = '<p style="color:#c62828;padding:1rem 0">' + escHtml(err.message) + '</p>';
  }
}

function pqGetLeavesJS(node, path) {
  path = path || [];
  const cur = [...path, node.name];
  if (!node.subitems || node.subitems.length === 0) return [{ name: node.name, path: cur }];
  const leaves = [];
  (node.subitems || []).forEach(child => { leaves.push(...pqGetLeavesJS(child, cur)); });
  return leaves;
}

function pqUpdateSubtotals() {
  document.querySelectorAll('.marks-subtotal-cell').forEach(cell => {
    const path = JSON.parse(cell.dataset.subPath);
    const username = cell.dataset.subUser;
    const node = pqFindNodeByPath(pqItems, path);
    const val = node ? pqComputeNodeSubtotal(node, path, username) : null;
    const span = cell.querySelector('.subtotal-val');
    if (!span) return;
    if (val === null) {
      span.textContent = '–';
      span.className = 'subtotal-val mark-none';
    } else {
      span.textContent = val.toFixed(2);
      span.className = 'subtotal-val ' + pqMarkClass(val);
    }
  });
}

function pqFindNodeByPath(root, path) {
  if (!root || root.name !== path[0]) return null;
  let node = root;
  for (let i = 1; i < path.length; i++) {
    if (!node.subitems) return null;
    node = node.subitems.find(s => s.name === path[i]);
    if (!node) return null;
  }
  return node;
}

function pqComputeNodeSubtotal(node, nodePath, username) {
  const isLeaf = !node.subitems || node.subitems.length === 0;
  if (isLeaf) {
    const pathStr = JSON.stringify(nodePath);
    let val = null;
    document.querySelectorAll('#marks-table input.mark-input').forEach(inp => {
      if (inp.dataset.username === username && inp.dataset.leafPath === pathStr) {
        val = inp.value !== '' ? parseFloat(inp.value) : null;
      }
    });
    return val;
  }
  let weightedSum = 0;
  let usedWeight = 0;
  (node.subitems || []).forEach(child => {
    const childPath = [...nodePath, child.name];
    const childVal = pqComputeNodeSubtotal(child, childPath, username);
    if (childVal !== null) {
      weightedSum += childVal * (child.weight || 0);
      usedWeight  += (child.weight || 0);
    }
  });
  return usedWeight > 0 ? weightedSum / usedWeight : null;
}

function pqGetLeafMark(marksNode, leafPath) {
  let node = marksNode;
  for (let i = 1; i < leafPath.length; i++) {
    const name = leafPath[i];
    if (node.subitems) {
      node = node.subitems.find(s => s.name === name);
      if (!node) return null;
    } else { return null; }
  }
  return node ? (node.mark ?? null) : null;
}

function pqBuildMarksTable() {
  const table = document.createElement('table');
  table.id = 'marks-table';

  const thead = table.createTHead();
  const hrow = thead.insertRow();
  const th0 = document.createElement('th');
  th0.textContent = 'Item';
  hrow.appendChild(th0);
  pqUsers.forEach(user => {
    const th = document.createElement('th');
    th.className = 'col-student';
    th.innerHTML =
      '<span class="col-student-name">' + escHtml(user.name || user.username) + '</span>' +
      '<span class="col-student-user">@' + escHtml(user.username) + '</span>';
    hrow.appendChild(th);
  });

  const tbody = table.createTBody();
  pqBuildHierarchyRows(tbody, pqItems, 1, []);

  return table;
}

function pqBuildHierarchyRows(tbody, node, depth, path) {
  const currentPath = [...path, node.name];
  const isLeaf = !node.subitems || node.subitems.length === 0;
  const indent = 0.5 + (depth - 1) * 1.3;

  if (!isLeaf) {
    const row = tbody.insertRow();
    row.className = 'marks-group-row marks-depth-' + Math.min(depth, 3);

    const nameCell = row.insertCell();
    nameCell.style.paddingLeft = indent + 'rem';
    const wLabel = (node.weight !== undefined && node.weight !== null)
      ? '<span class="item-weight"> ' + node.weight + '%</span>' : '';
    nameCell.innerHTML = '<svg style="width:.8em;height:.8em;fill:none;stroke:currentColor;stroke-width:2.5;vertical-align:middle;margin-right:.3em" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>' + escHtml(node.name) + wLabel;

    pqUsers.forEach(user => {
      const cell = row.insertCell();
      cell.className = 'marks-subtotal-cell';
      cell.dataset.subPath = JSON.stringify(currentPath);
      cell.dataset.subUser = user.username;
      const span = document.createElement('span');
      span.className = 'subtotal-val mark-none';
      span.textContent = '–';
      cell.appendChild(span);
    });

    (node.subitems || []).forEach(child =>
      pqBuildHierarchyRows(tbody, child, depth + 1, currentPath)
    );
  } else {
    const row = tbody.insertRow();
    row.className = 'marks-leaf-row';

    const nameCell = row.insertCell();
    nameCell.className = 'marks-item-cell';
    nameCell.style.paddingLeft = indent + 'rem';
    nameCell.title = currentPath.slice(1).join(' › ');
    const leafWLabel = (node.weight !== undefined && node.weight !== null)
      ? '<span class="item-weight"> ' + node.weight + '%</span>' : '';
    nameCell.innerHTML = escHtml(node.name) + leafWLabel;

    pqUsers.forEach(user => {
      const cell = row.insertCell();
      cell.style.textAlign = 'center';

      const userMarksObj = pqAllMarks.find(m => m.name === user.username) || null;
      const existingMark = userMarksObj ? pqGetLeafMark(userMarksObj, currentPath) : null;

      const input = document.createElement('input');
      input.type = 'number';
      input.className = 'mark-input';
      input.min = 0;
      input.max = 10;
      input.step = 0.1;
      input.placeholder = '–';
      if (existingMark !== null && existingMark !== undefined) input.value = existingMark;
      input.dataset.username = user.username;
      input.dataset.leafPath = JSON.stringify(currentPath);
      cell.appendChild(input);
    });
  }
}

async function pqSaveAllMarks() {
  setStatus('marks-status', '', '');

  const userMarksMap = {};
  document.querySelectorAll('#marks-table input.mark-input').forEach(input => {
    const username = input.dataset.username;
    const leafPath = JSON.parse(input.dataset.leafPath);
    const val = input.value.trim();
    const mark = val === '' ? null : Math.max(0, Math.min(10, parseFloat(val)));

    if (!userMarksMap[username]) {
      userMarksMap[username] = pqBuildEmptyMarksTreeJS(pqItems);
      userMarksMap[username].name = username;
    }
    pqSetLeafMark(userMarksMap[username], leafPath, mark);
  });

  const marks = Object.values(userMarksMap);

  try {
    const res = await apiFetch('marks.php?action=save-all-marks', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ marks })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Save failed');
    showToast('Marks saved successfully', 'success');
    setStatus('marks-status', 'Saved', 'ok');
    setTimeout(() => setStatus('marks-status', '', ''), 3000);
  } catch (err) {
    setStatus('marks-status', err.message, 'err');
  }
}

function pqBuildEmptyMarksTreeJS(node) {
  const result = { name: node.name };
  if (node.subitems && node.subitems.length > 0) {
    result.subitems = node.subitems.map(pqBuildEmptyMarksTreeJS);
  } else {
    result.mark = null;
  }
  return result;
}

function pqSetLeafMark(marksNode, leafPath, value) {
  let node = marksNode;
  for (let i = 1; i < leafPath.length; i++) {
    const name = leafPath[i];
    if (node.subitems) {
      node = node.subitems.find(s => s.name === name);
      if (!node) return;
    }
  }
  if (node) node.mark = value;
}

// ═══════════════════════════════════════════════════════════════════════
// ── STUDENT GRADE VIEW ─────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════

async function pqLoadStudentView() {
  const view = document.getElementById('pq-grade-view');
  view.innerHTML = '<div class="pq-loading"><div class="pq-spinner"></div> Loading your grades…</div>';
  try {
    const res = await apiFetch('marks.php?action=get-my-marks');
    if (!res.ok) {
      const d = await res.json().catch(() => ({}));
      throw new Error(d.error || 'Failed to load grades');
    }
    const computed = await res.json();
    view.innerHTML = '';
    view.appendChild(pqRenderGradeCard(computed));
  } catch (err) {
    view.innerHTML = '<p style="color:#c62828;padding:1.5rem 0">' + escHtml(err.message) + '</p>';
  }
}

function pqRenderGradeCard(computed) {
  const frag = document.createDocumentFragment();

  const finalAvg = computed.avg ?? null;
  const finalBox = document.createElement('div');
  finalBox.className = 'grade-final-box';
  finalBox.innerHTML =
    '<div><div class="gf-label">Final Grade</div>' +
    '<div class="gf-name">' + escHtml(getName() || getUser()) + '</div>' +
    '<div class="gf-username">@' + escHtml(getUser()) + '</div></div>' +
    '<div class="gf-value">' + pqFmtMark(finalAvg) + '</div>';
  frag.appendChild(finalBox);

  if (computed.subitems && computed.subitems.length > 0) {
    computed.subitems.forEach(section => { frag.appendChild(pqRenderSection(section, 1)); });
  } else if (computed.mark !== undefined) {
    const p = document.createElement('p');
    p.innerHTML = '<span class="grade-leaf-name">' + escHtml(computed.name) + '</span> ' +
      '<span class="grade-leaf-mark"' + pqGradeAttr(computed.mark) + '>' + pqFmtMark(computed.mark) + '</span>';
    frag.appendChild(p);
  }

  return frag;
}

function pqRenderSection(node, depth) {
  const section = document.createElement('div');
  section.className = 'grade-section';

  const avg = node.avg ?? node.mark ?? null;
  const hdr = document.createElement('div');
  hdr.className = 'grade-section-header';
  hdr.innerHTML =
    '<span class="grade-section-title">' + escHtml(node.name) +
      (node.weight !== undefined ? '<span class="grade-leaf-weight"> ' + node.weight + '%</span>' : '') +
    '</span>' +
    '<span class="grade-section-avg"' + pqGradeAttr(avg) + '>' +
      (avg !== null ? pqFmtMark(avg) : '<span class="mark-none">–</span>') +
    '</span>';
  section.appendChild(hdr);

  if (node.subitems && node.subitems.length > 0) {
    node.subitems.forEach(child => { section.appendChild(pqRenderSubsection(child, depth + 1)); });
  } else {
    section.appendChild(pqLeafRow(node, depth));
  }

  return section;
}

function pqRenderSubsection(node, depth) {
  if (!node.subitems || node.subitems.length === 0) return pqLeafRow(node, depth);

  const avg = node.avg ?? null;
  const wrap = document.createElement('div');
  wrap.className = 'grade-subsection';

  const sz = pqDepthFontSize(depth);
  const avgC = pqGradeColor(avg);
  let avgStyle = 'font-size:' + sz + ';font-weight:700';
  if (avgC) avgStyle += ';color:' + avgC;

  const hdr = document.createElement('div');
  hdr.className = 'grade-subsection-header';
  hdr.innerHTML =
    '<span class="grade-subsection-title" style="font-size:' + sz + '">' + escHtml(node.name) +
      (node.weight !== undefined ? '<span class="grade-leaf-weight"> ' + node.weight + '%</span>' : '') +
    '</span>' +
    '<span class="grade-subsection-avg"' + (avgC ? '' : ' class="mark-none"') + ' style="' + avgStyle + '">' +
      (avg !== null ? pqFmtMark(avg) : '<span class="mark-none">–</span>') +
    '</span>';
  wrap.appendChild(hdr);

  const body = document.createElement('div');
  body.className = 'grade-subsection-body';
  node.subitems.forEach(child => {
    if (child.subitems && child.subitems.length > 0) {
      body.appendChild(pqRenderSubsection(child, depth + 1));
    } else {
      body.appendChild(pqLeafRow(child, depth + 1));
    }
  });
  wrap.appendChild(body);
  return wrap;
}

function pqLeafRow(node, depth) {
  depth = depth || 2;
  const sz = pqDepthFontSize(depth);
  const markC = pqGradeColor(node.mark);
  let markStyle = 'font-size:' + sz + ';font-weight:700';
  if (markC) markStyle += ';color:' + markC;
  const row = document.createElement('div');
  row.className = 'grade-leaf';
  row.innerHTML =
    '<span class="grade-leaf-name" style="font-size:' + sz + '">' + escHtml(node.name) +
      (node.weight !== undefined ? '<span class="grade-leaf-weight"> ' + node.weight + '%</span>' : '') +
    '</span>' +
    '<span class="grade-leaf-mark"' + (markC ? '' : ' class="mark-none"') + ' style="' + markStyle + '">' + pqFmtMark(node.mark) + '</span>';
  return row;
}

// ── Formatting helpers ─────────────────────────────────────────────────

function pqDepthFontSize(depth) {
  const sizes = ['1.15rem', '1.0rem', '.88rem', '.80rem'];
  return sizes[Math.min(depth - 1, sizes.length - 1)];
}

function pqGradeColor(val) {
  if (val === null || val === undefined) return null;
  const v = Math.max(0, Math.min(10, parseFloat(val)));
  if (v >= 5) {
    const t = (v - 5) / 5;
    return 'hsl(120,' + Math.round(50 + 25 * t) + '%,' + Math.round(52 - 24 * t) + '%)';
  } else {
    const t = (5 - v) / 5;
    return 'hsl(0,' + Math.round(50 + 25 * t) + '%,' + Math.round(52 - 22 * t) + '%)';
  }
}

function pqGradeAttr(val) {
  const c = pqGradeColor(val);
  return c ? ' style="color:' + c + ';font-weight:700"' : ' class="mark-none"';
}

function pqMarkClass(val) {
  if (val === null || val === undefined) return 'mark-none';
  if (val < 5)  return 'mark-red';
  if (val < 7)  return 'mark-orange';
  return 'mark-green';
}

function pqFmtMark(val) {
  if (val === null || val === undefined) return '–';
  return parseFloat(val).toFixed(2);
}
