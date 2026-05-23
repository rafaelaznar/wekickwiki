<?php
// ═══════════════════════════════════════════════════════════════════════════
// marks.php — Student Qualifications App
//
// Shares authentication (users.json, settings.json) with wekickwiki.
// Uses its own data:
//   items.json  — recursive qualification structure (name, weight, subitems)
//   marks.json  — array of per-user mark objects mirroring items structure
//
// Authentication is replicated exactly from index.php / lib/users-api.php.
// Functions from lib/ are reused without modification.
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/data.php';
require_once __DIR__ . '/lib/users-api.php';  // handles ?action=login, get-users, etc.

// ── Data-file paths ──────────────────────────────────────────────────────────
define('PQ_ITEMS_FILE', __DIR__ . '/items.json');
define('PQ_MARKS_FILE', __DIR__ . '/marks.json');

// ─────────────────────────────────────────────────────────────────────────────
// items.json helpers
// ═══════════════════════════════════════════════════════════════════════════
function pq_load_items(): array
{
    if (!is_file(PQ_ITEMS_FILE)) {
        $default = ['name' => 'My Course', 'weight' => 100, 'subitems' => []];
        data_write(PQ_ITEMS_FILE, $default);
        return $default;
    }
    $data = data_read(PQ_ITEMS_FILE);
    return (isset($data['name'])) ? $data : ['name' => 'My Course', 'weight' => 100, 'subitems' => []];
}

function pq_save_items(array $data): void
{
    data_write(PQ_ITEMS_FILE, $data);
}

// ═══════════════════════════════════════════════════════════════════════════
// marks.json helpers
// ═══════════════════════════════════════════════════════════════════════════
function pq_load_marks(): array
{
    if (!is_file(PQ_MARKS_FILE)) {
        data_write(PQ_MARKS_FILE, []);
        return [];
    }
    $raw = data_read(PQ_MARKS_FILE);
    // Support legacy single-object format (upgrade to array on first access)
    if (isset($raw['name'])) return [$raw];
    return $raw;
}

function pq_save_marks(array $data): void
{
    data_write(PQ_MARKS_FILE, $data);
}

// ═══════════════════════════════════════════════════════════════════════════
// Weight validation: every sibling group must sum to 100
// Returns array of error strings (empty = valid)
// ═══════════════════════════════════════════════════════════════════════════
function pq_validate_weights(array $node, string $parentPath = 'root'): array
{
    $errors = [];
    if (!isset($node['subitems']) || !is_array($node['subitems']) || count($node['subitems']) === 0) {
        return $errors; // leaf node — no validation needed
    }
    $sum = 0;
    foreach ($node['subitems'] as $child) {
        $sum += (float)($child['weight'] ?? 0);
        // Recurse into non-leaf children
        $errors = array_merge($errors, pq_validate_weights($child, $node['name']));
    }
    // Allow floating-point tolerance of ±0.01
    if (abs($sum - 100) > 0.01) {
        $errors[] = 'Under "' . $node['name'] . '": weights sum to ' . round($sum, 2) . ' (must be 100)';
    }
    return $errors;
}

// ═══════════════════════════════════════════════════════════════════════════
// Get all leaf items as a flat list with their path (array of names)
// ═══════════════════════════════════════════════════════════════════════════
function pq_get_leaves(array $node, array $path = []): array
{
    $currentPath = array_merge($path, [$node['name']]);
    if (!isset($node['subitems']) || !is_array($node['subitems']) || count($node['subitems']) === 0) {
        return [['path' => $currentPath, 'name' => $node['name']]];
    }
    $leaves = [];
    foreach ($node['subitems'] as $child) {
        $leaves = array_merge($leaves, pq_get_leaves($child, $currentPath));
    }
    return $leaves;
}

// ═══════════════════════════════════════════════════════════════════════════
// Build an empty marks tree mirroring an items tree (null marks at leaves)
// ═══════════════════════════════════════════════════════════════════════════
function pq_build_empty_marks_tree(array $items_node): array
{
    $node = ['name' => $items_node['name']];
    if (isset($items_node['subitems']) && is_array($items_node['subitems']) && count($items_node['subitems']) > 0) {
        $node['subitems'] = array_map('pq_build_empty_marks_tree', $items_node['subitems']);
    } else {
        $node['mark'] = null;
    }
    return $node;
}

// ═══════════════════════════════════════════════════════════════════════════
// Find a user's marks index in the marks array (-1 if not found)
// ═══════════════════════════════════════════════════════════════════════════
function pq_find_user_marks_index(array $all_marks, string $username): int
{
    foreach ($all_marks as $i => $entry) {
        if (is_array($entry) && ($entry['name'] ?? '') === $username) return $i;
    }
    return -1;
}

// ═══════════════════════════════════════════════════════════════════════════
// Compute weighted averages: merges items tree (weights) + marks tree (marks)
// Returns a tree with added "avg" field at every non-leaf node, "mark" at leaves
// ═══════════════════════════════════════════════════════════════════════════
function pq_compute_averages(array $items_node, array $marks_node): array
{
    $result = ['name' => $items_node['name'], 'weight' => $items_node['weight'] ?? 100];

    // Leaf node
    if (!isset($items_node['subitems']) || !is_array($items_node['subitems']) || count($items_node['subitems']) === 0) {
        $result['mark'] = $marks_node['mark'] ?? null;
        return $result;
    }

    // Non-leaf: recurse and compute weighted average
    $result['subitems'] = [];
    $total = 0.0;
    $weightedCount = 0.0;

    foreach ($items_node['subitems'] as $i => $child_item) {
        $child_marks = $marks_node['subitems'][$i] ?? pq_build_empty_marks_tree($child_item);
        $child_result = pq_compute_averages($child_item, $child_marks);
        $result['subitems'][] = $child_result;

        $childAvg = $child_result['avg'] ?? $child_result['mark'] ?? null;
        if ($childAvg !== null) {
            $total += (float)($child_item['weight'] ?? 0) * (float)$childAvg / 100.0;
            $weightedCount += (float)($child_item['weight'] ?? 0);
        }
    }

    $result['avg'] = $weightedCount > 0 ? round($total / $weightedCount * 100, 4) : null;
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════
// Sanitize items tree coming from client (strip unknown fields, enforce types)
// ═══════════════════════════════════════════════════════════════════════════
function pq_sanitize_items(mixed $node): ?array
{
    if (!is_array($node) || !isset($node['name']) || !is_string($node['name'])) return null;
    $name   = trim(substr($node['name'], 0, 128));
    $weight = isset($node['weight']) ? (float)$node['weight'] : 0;
    if ($name === '') return null;

    $clean = ['name' => $name, 'weight' => $weight];
    if (isset($node['subitems']) && is_array($node['subitems']) && count($node['subitems']) > 0) {
        $subs = [];
        foreach ($node['subitems'] as $child) {
            $sc = pq_sanitize_items($child);
            if ($sc !== null) $subs[] = $sc;
        }
        if (count($subs) > 0) $clean['subitems'] = $subs;
    }
    return $clean;
}

// ═══════════════════════════════════════════════════════════════════════════
// Sanitize marks tree coming from client
// ═══════════════════════════════════════════════════════════════════════════
function pq_sanitize_marks_node(mixed $node): ?array
{
    if (!is_array($node) || !isset($node['name']) || !is_string($node['name'])) return null;
    $name  = trim(substr($node['name'], 0, 128));
    if ($name === '') return null;
    $clean = ['name' => $name];
    if (isset($node['subitems']) && is_array($node['subitems'])) {
        $subs = [];
        foreach ($node['subitems'] as $child) {
            $sc = pq_sanitize_marks_node($child);
            if ($sc !== null) $subs[] = $sc;
        }
        $clean['subitems'] = $subs;
    } else {
        $mark = $node['mark'] ?? null;
        $clean['mark'] = ($mark !== null) ? max(0.0, min(10.0, (float)$mark)) : null;
    }
    return $clean;
}

// ═══════════════════════════════════════════════════════════════════════════
// API: GET ?action=get-structure  (admin only)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-structure') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    json_out(200, pq_load_items());
}

// ═══════════════════════════════════════════════════════════════════════════
// API: POST ?action=save-structure  (admin only)
// Body: items tree JSON
// Validates that every sibling group's weights sum to 100 before saving.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-structure') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_out(400, ['error' => 'Invalid JSON body']);

    $clean = pq_sanitize_items($body);
    if ($clean === null) json_out(400, ['error' => 'Invalid structure: root must have a name']);

    // Force root weight to 100
    $clean['weight'] = 100;

    $errors = pq_validate_weights($clean);
    if (count($errors) > 0) {
        json_out(422, ['error' => 'Weight validation failed', 'details' => $errors]);
    }

    pq_save_items($clean);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: GET ?action=get-all-marks  (admin only)
// Returns full marks.json array plus leaf structure for convenience
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-all-marks') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    json_out(200, ['marks' => pq_load_marks(), 'items' => pq_load_items()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: POST ?action=save-all-marks  (admin only)
// Body: { marks: [ { name, subitems/mark... }, ... ] }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-all-marks') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['marks']) || !is_array($body['marks'])) {
        json_out(400, ['error' => 'Invalid body: expected {marks:[...]}']);
    }

    $sanitized = [];
    foreach ($body['marks'] as $entry) {
        if (!is_array($entry) || !isset($entry['name'])) continue;
        $username = preg_replace('/[^a-z0-9_]/', '', strtolower($entry['name'] ?? ''));
        if ($username === '') continue;
        $node = pq_sanitize_marks_node($entry);
        if ($node !== null) {
            $node['name'] = $username;
            $sanitized[] = $node;
        }
    }

    pq_save_marks($sanitized);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: GET ?action=get-my-marks  (authenticated users)
// Returns own computed marks with weighted averages
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-my-marks') {
    $claims = require_auth();
    $username  = $claims['sub'] ?? '';
    $all_marks = pq_load_marks();
    $items     = pq_load_items();

    $idx = pq_find_user_marks_index($all_marks, $username);
    $user_marks = ($idx !== -1) ? $all_marks[$idx] : pq_build_empty_marks_tree($items);

    $computed = pq_compute_averages($items, $user_marks);
    json_out(200, $computed);
}

// ═══════════════════════════════════════════════════════════════════════════
// Render HTML — load settings for app name / theme
// ═══════════════════════════════════════════════════════════════════════════
$pq_app_name = 'Qualifications';
$pq_theme    = 'default.css';
if (is_file(SETTINGS_FILE)) {
    $_raw = json_decode(file_get_contents(SETTINGS_FILE), true) ?? [];
    if (!empty($_raw['wikiName'])) $pq_app_name = $_raw['wikiName'] . ' — Qualifications';
    if (!empty($_raw['theme']) &&
        preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['theme']) &&
        is_file(__DIR__ . '/templates/' . $_raw['theme'])) {
        $pq_theme = $_raw['theme'];
    }
    unset($_raw);
}
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pq_app_name) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="icon.svg">
  <link id="pq-theme-link" rel="stylesheet" href="templates/<?= htmlspecialchars($pq_theme, ENT_QUOTES) ?>">
</head>
<body>

  <!-- ── App header ─────────────────────────────────────────────────── -->
  <header id="app-header" style="display:none">
    <a href="marks.php">
      <img src="icon.svg" style="display:inline;width:1.4rem;height:1.4rem;vertical-align:middle;margin-right:.4rem" alt="">
      <?= htmlspecialchars($pq_app_name) ?>
    </a>
    <nav class="app-nav">
      <a href="wiki.php">Wiki</a>
      <a href="marks.php" class="active">Marks</a>
      <a href="quests.php">Quests</a>
    </nav>
    <div id="app-header-right">
      <span id="pq-user-badge"></span>
      <button class="btn btn-sm" id="pq-logout-btn" onclick="window.location.href='index.php'">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign out
      </button>
    </div>
  </header>

  <div id="marks-screen">

    <!-- Admin panel -->
    <div id="admin-panel" style="display:none">
      <div class="pq-tabs">
        <div class="pq-tab active" onclick="pqShowTab('structure')">
          <svg style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2;vertical-align:middle;margin-right:.3em" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="17" x2="21" y2="17"/><line x1="17" y1="14" x2="17" y2="21"/></svg>
          Structure
        </div>
        <div class="pq-tab" onclick="pqShowTab('marks')">
          <svg style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2;vertical-align:middle;margin-right:.3em" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Marks
        </div>
      </div>

      <!-- ── Structure tab ───────────────────────────────────────── -->
      <div id="tab-structure" class="pq-tab-panel active">
        <p style="margin-bottom:.75rem;color:#555;font-size:.9rem">
          Build the qualification hierarchy. Each group of sub-items must have weights that sum exactly to <strong>100</strong>.
        </p>
        <label style="display:block;margin-bottom:.3rem;font-weight:600;font-size:.85rem">Course / root name</label>
        <input id="structure-root-name" type="text" maxlength="128" placeholder="e.g. Cooking Course">
        <div id="structure-tree-wrap">
          <!-- rendered by JS -->
        </div>
        <div id="structure-errors">
          <strong style="color:#c62828">Weight errors — please correct before saving:</strong>
          <ul id="structure-errors-list"></ul>
        </div>
        <div id="structure-action-bar">
          <button class="btn btn-primary" onclick="pqSaveStructure()">
            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save structure
          </button>
          <button class="btn" onclick="pqLoadStructure()">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.36"/></svg>
            Reload
          </button>
          <span id="structure-status" class="pq-status" style="display:none"></span>
        </div>
      </div>

      <!-- ── Marks tab ───────────────────────────────────────────── -->
      <div id="tab-marks" class="pq-tab-panel">
        <p style="margin-bottom:.75rem;color:#555;font-size:.9rem">
          Enter the grade (0–10) for each student in each leaf item. Leave blank if not yet graded.
        </p>
        <div id="marks-table-wrap">
          <div class="pq-loading"><div class="pq-spinner"></div> Loading…</div>
        </div>
        <div id="marks-action-bar">
          <button class="btn btn-primary" onclick="pqSaveAllMarks()">
            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save all marks
          </button>
          <button class="btn" onclick="pqLoadMarksTab()">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.36"/></svg>
            Reload
          </button>
          <span id="marks-status" class="pq-status" style="display:none"></span>
        </div>
      </div>
    </div>

    <!-- Student grade view -->
    <div id="pq-grade-view" style="display:none">
      <div class="pq-loading"><div class="pq-spinner"></div> Loading your grades…</div>
    </div>


  </div><!-- #marks-screen -->

  <div id="app-toast"></div>

  <script src="lib/auth-client.js?v=<?= filemtime(__DIR__ . '/lib/auth-client.js') ?>"></script>
  <script src="lib/app-client.js?v=<?= filemtime(__DIR__ . '/lib/app-client.js') ?>"></script>
  <script src="marks.js?v=<?= filemtime(__DIR__ . '/marks.js') ?>"></script>
</body>
</html>
