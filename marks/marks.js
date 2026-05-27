  // ═══════════════════════════════════════════════════════════════════════
  // marks.js — inline app script
  // ═══════════════════════════════════════════════════════════════════════

  // ── State ──────────────────────────────────────────────────────────────
  /** Working copy of the items tree (mutated by the structure editor) */
  let pqItems = null;
  /** Cached list of enabled guest users from get-users */
  let pqUsers = [];
  /** Cached full marks array from get-all-marks */
  let pqAllMarks = [];
  /** Flat ordered list of leaf items (derived from pqItems) */
  let pqLeaves = [];

  // ── Toast ──────────────────────────────────────────────────────────────
  let _pqToastTimer;
  function pqToast(msg, type = 'success', ms = 3200) {
    const el = document.getElementById('pq-toast');
    el.textContent = msg;
    el.className = 'show ' + type;
    clearTimeout(_pqToastTimer);
    _pqToastTimer = setTimeout(() => el.classList.remove('show'), ms);
  }

  // ── Status helpers ─────────────────────────────────────────────────────
  function pqSetStatus(id, msg, type) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'pq-status ' + type;
    el.style.display = msg ? '' : 'none';
  }

  // ── Auth / routing ─────────────────────────────────────────────────────
  setOnUnauthorized(pqLogout);

  function pqLogout() {
    sessionStorage.clear();
    window.location.href = '../index.php';
  }

  function pqShowApp() {
    document.getElementById('pq-header').style.display = 'flex';
    document.getElementById('pq-screen').style.display = 'block';
    document.getElementById('pq-user-badge').textContent = getUser() + ' (' + getRole() + ')';
  }

  /** Route to admin or student view based on role */
  function pqRoute() {
    const role = getRole();
    const themeBtn = document.getElementById('pq-theme-btn');
    if (themeBtn) themeBtn.style.display = role === 'admin' ? '' : 'none';
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

  // Auto-restore session
  if (getToken()) {
    pqShowApp();
    pqRoute();
  } else {
    window.location.href = '../index.php';
  }

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
    pqSetStatus('structure-status', '', '');
    document.getElementById('structure-tree-wrap').innerHTML =
      '<div class="pq-loading"><div class="pq-spinner"></div> Loading…</div>';
    try {
      const res = await apiFetch('marks.php?action=get-structure');
      if (!res.ok) throw new Error('Failed to load structure');
      pqItems = await res.json();
      pqRenderStructure();
    } catch (err) {
      document.getElementById('structure-tree-wrap').innerHTML =
        '<p class="pq-load-error">' + err.message + '</p>';
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

  /** Build <ul class="pq-tree"> for a list of child nodes at the given path prefix */
  function pqBuildTreeUI(children, path) {
    const ul = document.createElement('ul');
    ul.className = 'pq-tree';
    ul.dataset.path = JSON.stringify(path);

    // Weight sum indicator for this group
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

      // Row
      const row = document.createElement('div');
      row.className = 'pq-item-row';

      // Name input
      const nameInput = document.createElement('input');
      nameInput.type = 'text';
      nameInput.className = 'pq-item-name';
      nameInput.value = child.name || '';
      nameInput.placeholder = 'Item name';
      nameInput.maxLength = 128;
      nameInput.dataset.path = JSON.stringify(childPath);
      nameInput.addEventListener('input', () => pqSetNodeField(childPath, 'name', nameInput.value));

      // Leaf badge
      const leafBadge = isLeaf ? (() => {
        const b = document.createElement('span');
        b.className = 'pq-leaf-badge';
        b.textContent = 'leaf';
        return b;
      })() : null;

      // Weight input + label
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

      // Add child button
      const addBtn = document.createElement('button');
      addBtn.className = 'btn btn-sm btn-ghost';
      addBtn.title = 'Add child item';
      addBtn.innerHTML = '<svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
      addBtn.addEventListener('click', () => pqAddChild(childPath));

      // Delete button
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

      // Recurse into children
      if (!isLeaf) {
        const childUl = pqBuildTreeUI(child.subitems, childPath);
        li.appendChild(childUl);
      }

      ul.appendChild(li);
    });

    // "Add item here" button at the bottom of each group
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

  // ── Tree mutation helpers (operate on pqItems in-memory) ─────────────────

  /** Navigate pqItems tree to the node at the given index path */
  function pqGetNode(path) {
    let node = pqItems;
    for (const idx of path) {
      node = node.subitems[idx];
    }
    return node;
  }

  /** Set a field on a node at the given path and re-render */
  function pqSetNodeField(path, field, value) {
    const node = pqGetNode(path);
    node[field] = value;
    // Name change: just update in-memory, no re-render needed (input keeps focus)
  }

  /** Add a new sibling item to the children list at the given parent path */
  function pqAddSibling(parentPath) {
    const parent = parentPath.length === 0 ? pqItems : pqGetNode(parentPath);
    if (!parent.subitems) parent.subitems = [];
    // Read current root name into pqItems before re-render
    pqSyncRootName();
    parent.subitems.push({ name: 'New item', weight: 0 });
    pqRenderStructure();
    pqUpdateAllWeightSums();
  }

  /** Add a child to the node at the given path (makes it non-leaf) */
  function pqAddChild(path) {
    pqSyncRootName();
    const node = pqGetNode(path);
    // If node was a leaf, remove any existing mark data conceptually
    if (!node.subitems) node.subitems = [];
    node.subitems.push({ name: 'New item', weight: 0 });
    pqRenderStructure();
    pqUpdateAllWeightSums();
  }

  /** Delete the node at the given index path */
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

  /** Sync the root name input into pqItems before re-render */
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

  /** Walk all non-leaf nodes calling fn(parentPath) */
  function pqWalkPaths(node, path, fn) {
    if (!node.subitems || node.subitems.length === 0) return;
    fn(path);
    node.subitems.forEach((child, idx) => pqWalkPaths(child, [...path, idx], fn));
  }

  // ── Save structure ──────────────────────────────────────────────────────

  async function pqSaveStructure() {
    pqSyncRootName();
    // Sync all name inputs into pqItems (user may not have tabbed out)
    document.querySelectorAll('.pq-item-name').forEach(input => {
      const path = JSON.parse(input.dataset.path);
      pqSetNodeField(path, 'name', input.value);
    });
    document.querySelectorAll('.pq-item-weight').forEach(input => {
      const path = JSON.parse(input.dataset.path);
      pqSetNodeField(path, 'weight', parseFloat(input.value) || 0);
    });

    pqSetStatus('structure-status', '', '');
    document.getElementById('structure-errors').style.display = 'none';

    try {
      const res = await apiFetch('marks.php?action=save-structure', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(pqItems)
      });
      const data = await res.json();

      if (res.status === 422 && data.details) {
        // Weight validation errors from server
        const errEl = document.getElementById('structure-errors');
        const listEl = document.getElementById('structure-errors-list');
        listEl.innerHTML = data.details.map(e => '<li>' + pqEsc(e) + '</li>').join('');
        errEl.style.display = '';
        errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        pqSetStatus('structure-status', 'Validation errors — please correct weights', 'err');
        return;
      }
      if (!res.ok) throw new Error(data.error || 'Save failed');

      pqToast('Structure saved successfully', 'success');
      pqSetStatus('structure-status', 'Saved', 'ok');
      setTimeout(() => pqSetStatus('structure-status', '', ''), 3000);
    } catch (err) {
      pqSetStatus('structure-status', err.message, 'err');
    }
  }

  // ═══════════════════════════════════════════════════════════════════════
  // ── MARKS EDITOR ───────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════

  async function pqLoadMarksTab() {
    pqSetStatus('marks-status', '', '');
    const wrap = document.getElementById('marks-table-wrap');
    wrap.innerHTML = '<div class="pq-loading"><div class="pq-spinner"></div> Loading…</div>';

    try {
      const [resUsers, resMarks] = await Promise.all([
        apiFetch('../index.php?action=get-users'),
        apiFetch('marks.php?action=get-all-marks')
      ]);
      if (!resUsers.ok) throw new Error('Failed to load users');
      if (!resMarks.ok)  throw new Error('Failed to load marks');

      const usersData = await resUsers.json();
      const marksData = await resMarks.json();

      // Only enabled guests
      pqUsers  = (usersData.guests || []).filter(u => u.enabled !== false);
      pqAllMarks = marksData.marks || [];
      pqItems  = marksData.items;
      pqLeaves = pqGetLeavesJS(pqItems);

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
      // Live subtotal recalculation on any mark input change
      wrap.addEventListener('input', function onMarksInput(e) {
        if (e.target.classList.contains('mark-input')) pqUpdateSubtotals();
      }, { capture: false });
    } catch (err) {
      wrap.innerHTML = '<p class="pq-load-error">' + pqEsc(err.message) + '</p>';
    }
  }

  /** Extract leaf items as flat array [{name, path:[...names]}] */
  function pqGetLeavesJS(node, path) {
    path = path || [];
    const cur = [...path, node.name];
    if (!node.subitems || node.subitems.length === 0) return [{ name: node.name, path: cur }];
    const leaves = [];
    (node.subitems || []).forEach(child => {
      leaves.push(...pqGetLeavesJS(child, cur));
    });
    return leaves;
  }

  /** Recompute and display all subtotal cells in the marks table */
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

  /** Walk pqItems tree to find the node matching path array */
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

  /**
   * Recursively compute weighted average for a node from current input values.
   * Returns a number (0–10) or null if no inputs have values.
   */
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

  /** Get a leaf mark value from a user's marks tree by leaf path */
  function pqGetLeafMark(marksNode, leafPath) {
    // leafPath[0] is the root name, skip it
    let node = marksNode;
    for (let i = 1; i < leafPath.length; i++) {
      const name = leafPath[i];
      if (node.subitems) {
        node = node.subitems.find(s => s.name === name);
        if (!node) return null;
      } else {
        return null;
      }
    }
    return node ? (node.mark ?? null) : null;
  }

  function pqBuildMarksTable() {
    const table = document.createElement('table');
    table.id = 'marks-table';

    // ── Header row: sticky corner + one column per student ────────────
    const thead = table.createTHead();
    const hrow = thead.insertRow();
    const th0 = document.createElement('th');
    th0.textContent = 'Item';
    hrow.appendChild(th0);
    pqUsers.forEach(user => {
      const th = document.createElement('th');
      th.className = 'col-student';
      th.innerHTML =
        '<span class="col-student-name">' + pqEsc(user.name || user.username) + '</span>' +
        '<span class="col-student-user">@' + pqEsc(user.username) + '</span>';
      hrow.appendChild(th);
    });

    // ── Body rows: items as rows, hierarchy shown via group/leaf rows ──
    const tbody = table.createTBody();
    pqBuildHierarchyRows(tbody, pqItems, 1, []);

    return table;
  }

  /**
   * Recursively walk the items tree and emit table rows into tbody.
   * depth 0 = root (skipped visually, just recurse into children)
   * Group rows (non-leaf): styled header with the group name
   * Leaf rows: item name cell (sticky) + one mark input per student
   */
  function pqBuildHierarchyRows(tbody, node, depth, path) {
    const currentPath = [...path, node.name];
    const isLeaf = !node.subitems || node.subitems.length === 0;

    const indent = 0.5 + (depth - 1) * 1.3; // rem

    if (!isLeaf) {
      // ── Group header row ──────────────────────────────────────────
      const row = tbody.insertRow();
      row.className = 'marks-group-row marks-depth-' + Math.min(depth, 3);

      // First sticky cell: group name with arrow + indentation
      const nameCell = row.insertCell();
      nameCell.style.paddingLeft = indent + 'rem';
      const wLabel = (node.weight !== undefined && node.weight !== null)
        ? '<span class="item-weight"> ' + node.weight + '%</span>' : '';
      nameCell.innerHTML = '<svg class="pq-tree-expand-icon" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>' + pqEsc(node.name) + wLabel;

      // Subtotal cells (one per student) — filled after table is built
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

      // Recurse into children
      (node.subitems || []).forEach(child =>
        pqBuildHierarchyRows(tbody, child, depth + 1, currentPath)
      );
    } else {
      // ── Leaf row ──────────────────────────────────────────────────
      const row = tbody.insertRow();
      row.className = 'marks-leaf-row';

      // First sticky cell: item name with indentation
      const nameCell = row.insertCell();
      nameCell.className = 'marks-item-cell';
      nameCell.style.paddingLeft = indent + 'rem';
      nameCell.title = currentPath.slice(1).join(' › ');
      const leafWLabel = (node.weight !== undefined && node.weight !== null)
        ? '<span class="item-weight"> ' + node.weight + '%</span>' : '';
      nameCell.innerHTML = pqEsc(node.name) + leafWLabel;

      // One mark input per student
      pqUsers.forEach(user => {
        const cell = row.insertCell();
        cell.className = 'marks-mark-cell';

        const userMarksObj = pqAllMarks.find(m => m.name === user.username) || null;
        const existingMark = userMarksObj ? pqGetLeafMark(userMarksObj, currentPath) : null;

        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'mark-input';
        input.min = 0;
        input.max = 10;
        input.step = 0.1;
        input.placeholder = '–';
        if (existingMark !== null && existingMark !== undefined) {
          input.value = existingMark;
        }
        input.dataset.username = user.username;
        input.dataset.leafPath = JSON.stringify(currentPath);
        cell.appendChild(input);
      });
    }
  }

  async function pqSaveAllMarks() {
    pqSetStatus('marks-status', '', '');

    // Collect all inputs and rebuild marks trees per user
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
      pqToast('Marks saved successfully', 'success');
      pqSetStatus('marks-status', 'Saved', 'ok');
      setTimeout(() => pqSetStatus('marks-status', '', ''), 3000);
    } catch (err) {
      pqSetStatus('marks-status', err.message, 'err');
    }
  }

  /** Build empty marks tree mirroring items tree */
  function pqBuildEmptyMarksTreeJS(node) {
    const result = { name: node.name };
    if (node.subitems && node.subitems.length > 0) {
      result.subitems = node.subitems.map(pqBuildEmptyMarksTreeJS);
    } else {
      result.mark = null;
    }
    return result;
  }

  /** Set a leaf mark value in a marks tree by path */
  function pqSetLeafMark(marksNode, leafPath, value) {
    // leafPath[0] = root name
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
      view.innerHTML = '<p class="pq-load-error">' + pqEsc(err.message) + '</p>';
    }
  }

  /** Render the full grade card for a student */
  function pqRenderGradeCard(computed) {
    const frag = document.createDocumentFragment();

    // Final grade box
    const finalAvg = computed.avg ?? null;
    const finalBox = document.createElement('div');
    finalBox.className = 'grade-final-box';
    finalBox.innerHTML =
      '<div><div class="gf-label">Final Grade</div>' +
      '<div class="gf-name">' + pqEsc(getName() || getUser()) + '</div>' +
      '<div class="gf-username">@' + pqEsc(getUser()) + '</div></div>' +
      '<div class="gf-value">' + pqFmtMark(finalAvg) + '</div>';
    frag.appendChild(finalBox);

    // Sections (top-level subitems)
    if (computed.subitems && computed.subitems.length > 0) {
      computed.subitems.forEach(section => {
        frag.appendChild(pqRenderSection(section, 1));
      });
    } else if (computed.mark !== undefined) {
      // Edge case: root itself is a leaf
      const p = document.createElement('p');
      p.innerHTML = '<span class="grade-leaf-name">' + pqEsc(computed.name) + '</span> ' +
        '<span class="grade-leaf-mark"' + pqGradeAttr(computed.mark) + '>' + pqFmtMark(computed.mark) + '</span>';
      frag.appendChild(p);
    }

    return frag;
  }

  /** Render a section (depth 1 = h2 level) */
  function pqRenderSection(node, depth) {
    const section = document.createElement('div');
    section.className = 'grade-section';

    const avg = node.avg ?? node.mark ?? null;

    const hdr = document.createElement('div');
    hdr.className = 'grade-section-header';
    hdr.innerHTML =
      '<span class="grade-section-title">' + pqEsc(node.name) +
        (node.weight !== undefined ? '<span class="grade-leaf-weight"> ' + node.weight + '%</span>' : '') +
      '</span>' +
      '<span class="grade-section-avg"' + pqGradeAttr(avg) + '>' +
        (avg !== null ? pqFmtMark(avg) : '<span class="mark-none">–</span>') +
      '</span>';
    section.appendChild(hdr);

    if (node.subitems && node.subitems.length > 0) {
      node.subitems.forEach(child => {
        section.appendChild(pqRenderSubsection(child, depth + 1));
      });
    } else {
      // Leaf at section level
      section.appendChild(pqLeafRow(node, depth));
    }

    return section;
  }

  /** Render a subsection (depth > 1) */
  function pqRenderSubsection(node, depth) {
    if (!node.subitems || node.subitems.length === 0) {
      // Pure leaf — render as a leaf row directly
      return pqLeafRow(node, depth);
    }

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
      '<span class="grade-subsection-title" style="font-size:' + sz + '">' + pqEsc(node.name) +
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

  /** Render a leaf row */
  function pqLeafRow(node, depth) {
    depth = depth || 2;
    const sz = pqDepthFontSize(depth);
    const markC = pqGradeColor(node.mark);
    let markStyle = 'font-size:' + sz + ';font-weight:700';
    if (markC) markStyle += ';color:' + markC;
    const row = document.createElement('div');
    row.className = 'grade-leaf';
    row.innerHTML =
      '<span class="grade-leaf-name" style="font-size:' + sz + '">' + pqEsc(node.name) +
        (node.weight !== undefined ? '<span class="grade-leaf-weight"> ' + node.weight + '%</span>' : '') +
      '</span>' +
      '<span class="grade-leaf-mark"' + (markC ? '' : ' class="mark-none"') + ' style="' + markStyle + '">' + pqFmtMark(node.mark) + '</span>';
    return row;
  }

  // ── Formatting helpers ──────────────────────────────────────────────────

  /**
   * Continuous grade color: green (>=5, darker toward 10)
   * or red (<5, darker toward 0). Returns CSS hsl string or null.
   */
  function pqDepthFontSize(depth) {
    // Font size decreases with each hierarchy level
    const sizes = ['1.15rem', '1.0rem', '.88rem', '.80rem'];
    return sizes[Math.min(depth - 1, sizes.length - 1)];
  }

  /**
   * Continuous grade color: green (>=5, darker toward 10)
   * or red (<5, darker toward 0). Returns CSS hsl string or null.
   */
  function pqGradeColor(val) {
    if (val === null || val === undefined) return null;
    const v = Math.max(0, Math.min(10, parseFloat(val)));
    if (v >= 5) {
      const t = (v - 5) / 5;  // 0 at 5, 1 at 10
      return 'hsl(120,' + Math.round(50 + 25 * t) + '%,' + Math.round(52 - 24 * t) + '%)';
    } else {
      const t = (5 - v) / 5;  // 0 at 5, 1 at 0
      return 'hsl(0,' + Math.round(50 + 25 * t) + '%,' + Math.round(52 - 22 * t) + '%)';
    }
  }

  /** Returns HTML attribute string: style="color:..." or class="mark-none" */
  function pqGradeAttr(val) {
    const c = pqGradeColor(val);
    return c ? ' style="color:' + c + ';font-weight:700"' : ' class="mark-none"';
  }

  function pqMarkClass(val) {
    if (val === null || val === undefined) return 'mark-none';
    if (val < 5)   return 'mark-red';
    if (val < 7)   return 'mark-orange';
    return 'mark-green';
  }

  function pqFmtMark(val) {
    if (val === null || val === undefined) return '–';
    return parseFloat(val).toFixed(2);
  }

  function pqEsc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Theme panel ────────────────────────────────────────────────────────────────────
  async function pqToggleThemePanel() {
    const panel   = document.getElementById('pq-theme-panel');
    const overlay = document.getElementById('pq-theme-overlay');
    const isOpen  = panel.classList.contains('is-open');
    if (!isOpen) {
      try {
        const res  = await apiFetch('marks.php?action=get-marks-templates');
        const data = await res.json();
        const sel  = document.getElementById('pq-theme-select');
        const link = document.getElementById('pq-theme-link');
        const current = link ? link.getAttribute('href').replace('templates-marks/', '') : '';
        sel.innerHTML = '';
        (data.templates || []).forEach(t => {
          const opt = document.createElement('option');
          opt.value = t;
          opt.textContent = t.replace('.css', '');
          if (t === current) opt.selected = true;
          sel.appendChild(opt);
        });
      } catch { /* ignore — panel still opens */ }
    }
    panel.classList.toggle('is-open');
    overlay.classList.toggle('is-open');
  }

  async function pqSaveTheme() {
    const theme = document.getElementById('pq-theme-select').value;
    try {
      const res  = await apiFetch('marks.php?action=save-marks-theme', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ theme })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Save failed');
      pqToast('Theme saved — reloading…', 'success');
      pqToggleThemePanel();
      setTimeout(() => location.reload(), 800);
    } catch (err) {
      pqToast(err.message, 'error');
    }
  }
