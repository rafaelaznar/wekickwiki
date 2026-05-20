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
require_once __DIR__ . '/lib/users-api.php';  // handles ?action=login, get-users, etc.

// ── Data-file paths ──────────────────────────────────────────────────────────
define('PQ_ITEMS_FILE', __DIR__ . '/items.json');
define('PQ_MARKS_FILE', __DIR__ . '/marks.json');

// ═══════════════════════════════════════════════════════════════════════════
// Helper: atomic JSON write
// ═══════════════════════════════════════════════════════════════════════════
function pq_write_json(string $path, mixed $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ═══════════════════════════════════════════════════════════════════════════
// items.json helpers
// ═══════════════════════════════════════════════════════════════════════════
function pq_load_items(): array
{
    if (!is_file(PQ_ITEMS_FILE)) {
        $default = ['name' => 'My Course', 'weight' => 100, 'subitems' => []];
        pq_write_json(PQ_ITEMS_FILE, $default);
        return $default;
    }
    $data = json_decode(file_get_contents(PQ_ITEMS_FILE), true);
    return (is_array($data) && isset($data['name'])) ? $data : ['name' => 'My Course', 'weight' => 100, 'subitems' => []];
}

function pq_save_items(array $data): void
{
    pq_write_json(PQ_ITEMS_FILE, $data);
}

// ═══════════════════════════════════════════════════════════════════════════
// marks.json helpers
// ═══════════════════════════════════════════════════════════════════════════
function pq_load_marks(): array
{
    if (!is_file(PQ_MARKS_FILE)) {
        pq_write_json(PQ_MARKS_FILE, []);
        return [];
    }
    $raw = json_decode(file_get_contents(PQ_MARKS_FILE), true);
    // Support legacy single-object format (upgrade to array on first access)
    if (is_array($raw) && isset($raw['name'])) return [$raw];
    return is_array($raw) ? $raw : [];
}

function pq_save_marks(array $data): void
{
    pq_write_json(PQ_MARKS_FILE, $data);
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
// API: GET ?action=get-marks-templates  (admin only)
// Returns the list of CSS files in templates-marks/
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-marks-templates') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    $dir   = __DIR__ . '/templates-marks';
    $files = is_dir($dir) ? (glob($dir . '/*.css') ?: []) : [];
    sort($files);
    json_out(200, ['templates' => array_map('basename', $files)]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: POST ?action=save-marks-theme  (admin only)
// Body: { theme: "filename.css" }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-marks-theme') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    $body  = json_decode(file_get_contents('php://input'), true);
    $theme = $body['theme'] ?? '';
    if (!is_string($theme) ||
        !preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme) ||
        !is_file(__DIR__ . '/templates-marks/' . $theme)) {
        json_out(400, ['error' => 'Invalid theme']);
    }
    $raw = is_file(SETTINGS_FILE)
        ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? [])
        : [];
    $raw['pqTheme'] = $theme;
    file_put_contents(SETTINGS_FILE, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// Render HTML — load settings for app name display
// ═══════════════════════════════════════════════════════════════════════════
$pq_settings = load_auth_settings();
$pq_app_name = 'Qualifications';
// Try to get the wikiName from settings if available (re-read raw settings.json)
$pq_theme = 'default.css';
if (is_file(SETTINGS_FILE)) {
    $_raw = json_decode(file_get_contents(SETTINGS_FILE), true) ?? [];
    if (!empty($_raw['wikiName'])) $pq_app_name = $_raw['wikiName'] . ' — Qualifications';
    if (!empty($_raw['pqTheme']) &&
        preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['pqTheme']) &&
        is_file(__DIR__ . '/templates-marks/' . $_raw['pqTheme'])) {
        $pq_theme = $_raw['pqTheme'];
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
  <style>
    /* ── PasQ app-specific styles ─────────────────────────────────── */

    /* Header reuse */
    #pq-header {
      border-bottom: 2px solid #222;
      padding: .5rem 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: fixed;
      top: 0; left: 0; right: 0;
      background: #fff;
      z-index: 1000;
      box-shadow: 0 2px 5px rgba(0,0,0,.1);
      min-height: 50px;
    }
    #pq-header a {
      font-size: 1.3rem;
      font-weight: 700;
      color: #111;
      text-decoration: none;
    }
    #pq-header-right {
      display: flex;
      gap: .5rem;
      align-items: center;
    }

    /* Main content area */
    #pq-screen {
      max-width: 960px;
      margin: 70px auto 50px;
      padding: 1rem 1.5rem 2rem;
      background: #fff;
      border: 1px solid #eee;
      box-shadow: 0 0 .5em #999;
      border-radius: 2px;
      display: none;
    }

    /* Tabs (admin) */
    .pq-tabs {
      display: flex;
      gap: 0;
      border-bottom: 2px solid #8A0808;
      margin-bottom: 1.5rem;
    }
    .pq-tab {
      padding: .55rem 1.4rem;
      cursor: pointer;
      font-weight: 600;
      font-size: .9rem;
      color: #555;
      border: 1px solid transparent;
      border-bottom: none;
      border-radius: 4px 4px 0 0;
      background: #f4f4f5;
      margin-right: 3px;
      transition: background .15s, color .15s;
    }
    .pq-tab.active {
      background: #8A0808;
      color: #fff;
      border-color: #8A0808;
    }
    .pq-tab:hover:not(.active) {
      background: #f0e0e0;
      color: #8A0808;
    }
    .pq-tab-panel { display: none; }
    .pq-tab-panel.active { display: block; }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .45rem .9rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      background: #fafafa;
      cursor: pointer;
      font-size: .85rem;
      font-weight: 600;
      color: #333;
      text-decoration: none;
      transition: background .15s, border-color .15s;
    }
    .btn:hover { background: #eee; border-color: #bbb; }
    .btn-primary { background: #8A0808; color: #fff; border-color: #8A0808; }
    .btn-primary:hover { background: #6a0606; border-color: #6a0606; }
    .btn-sm { padding: .25rem .55rem; font-size: .78rem; }
    .btn-danger { background: #c0392b; color: #fff; border-color: #c0392b; }
    .btn-danger:hover { background: #a93226; border-color: #a93226; }
    .btn-ghost { background: transparent; border-color: transparent; color: #8A0808; }
    .btn-ghost:hover { background: #f0e0e0; border-color: #f0e0e0; }
    .btn svg { width: 1em; height: 1em; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    /* ── Structure editor ─────────────────────────────────────────── */
    #structure-root-name {
      width: 100%;
      max-width: 420px;
      padding: .5rem .75rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: 1rem;
      margin-bottom: 1rem;
    }
    #structure-root-name:focus { outline: none; border-color: #8A0808; }

    .pq-tree { list-style: none; padding: 0; margin: 0; }
    .pq-tree .pq-tree { padding-left: 1.6rem; margin-top: .35rem; }

    .pq-item {
      margin: .3rem 0;
    }
    .pq-item-row {
      display: flex;
      align-items: center;
      gap: .4rem;
      background: #fafafa;
      border: 1px solid #e8e8e8;
      border-radius: 4px;
      padding: .35rem .5rem;
    }
    .pq-item-row:focus-within {
      border-color: #8A0808;
      background: #fff;
    }
    .pq-item-name {
      flex: 1;
      min-width: 0;
      padding: .3rem .5rem;
      border: 1px solid transparent;
      border-radius: 3px;
      font-size: .9rem;
      background: transparent;
    }
    .pq-item-name:focus {
      outline: none;
      border-color: #ccc;
      background: #fff;
    }
    .pq-item-weight-wrap {
      display: flex;
      align-items: center;
      gap: .3rem;
      white-space: nowrap;
    }
    .pq-item-weight {
      width: 58px;
      padding: .3rem .4rem;
      border: 1px solid #ccc;
      border-radius: 3px;
      font-size: .9rem;
      text-align: right;
    }
    .pq-item-weight:focus { outline: none; border-color: #8A0808; }
    .pq-weight-label { font-size: .78rem; color: #888; }

    /* Per-sibling weight indicator */
    .pq-weight-sum {
      font-size: .75rem;
      font-weight: 700;
      padding: .15rem .45rem;
      border-radius: 3px;
      margin: .2rem 0 .4rem 0;
      display: inline-block;
    }
    .pq-weight-sum.ok { background: #e8f5e9; color: #2e7d32; }
    .pq-weight-sum.bad { background: #fdecea; color: #c62828; }

    /* Leaf badge */
    .pq-leaf-badge {
      font-size: .7rem;
      background: #e3f0ff;
      color: #1a5faa;
      border-radius: 3px;
      padding: .1rem .4rem;
      font-weight: 600;
      white-space: nowrap;
    }

    /* Structure action bar */
    #structure-action-bar {
      display: flex;
      align-items: flex-start;
      gap: .75rem;
      margin-top: 1.5rem;
      flex-wrap: wrap;
    }
    #structure-errors {
      background: #fdecea;
      border: 1px solid #f5c6c6;
      border-radius: 4px;
      padding: .65rem 1rem;
      margin-top: .75rem;
      display: none;
    }
    #structure-errors ul { margin: .3rem 0 0 1.2rem; }
    #structure-errors li { font-size: .88rem; color: #c62828; margin: .15rem 0; }

    /* ── Marks editor table ───────────────────────────────────────── */
    #marks-table-wrap {
      overflow-x: auto;
      overflow-y: auto;
      max-height: 72vh;
      margin-top: 1rem;
      border: 1px solid #ddd;
      border-radius: 4px;
    }
    #marks-table {
      /* separate + spacing:0 is required for sticky positioning to work */
      border-collapse: separate;
      border-spacing: 0;
      font-size: .85rem;
      min-width: 100%;
    }

    /* ── Header row (sticky top) ── */
    #marks-table thead th {
      position: sticky;
      top: 0;
      z-index: 2;
      background: #8A0808;
      color: #fff;
      padding: .5rem .7rem;
      text-align: center;
      font-weight: 600;
      white-space: nowrap;
      border-right: 1px solid #6a0606;
      border-bottom: 2px solid #5a0606;
    }
    /* Corner cell: sticky both top and left */
    #marks-table thead th:first-child {
      position: sticky;
      left: 0;
      z-index: 4;
      text-align: left;
      min-width: 220px;
      max-width: 320px;
    }
    /* Student header */
    #marks-table thead th.col-student {
      min-width: 88px;
    }
    .col-student-name {
      display: block;
      font-weight: 700;
    }
    .col-student-user {
      display: block;
      font-size: .72rem;
      opacity: .78;
      font-weight: 400;
    }

    /* ── Body cells ── */
    #marks-table tbody td {
      padding: .3rem .5rem;
      border-right: 1px solid #e8e8e8;
      border-bottom: 1px solid #e8e8e8;
      vertical-align: middle;
      background: #fff;
    }
    /* Sticky first column in body */
    #marks-table tbody td:first-child {
      position: sticky;
      left: 0;
      z-index: 1;
      border-right: 2px solid #ccc;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 320px;
    }

    /* ── Group header rows ── */
    .marks-group-row td:first-child {
      font-weight: 700;
      font-size: .88rem;
      color: #fff !important;
      background: #8A0808 !important;
      border-bottom: 1px solid #6a0606 !important;
    }
    .marks-depth-1 td:first-child { background: #8A0808 !important; }
    .marks-depth-2 td:first-child { background: #a03030 !important; color: #fff !important; }
    .marks-depth-3 td:first-child { background: #c05050 !important; color: #fff !important; }
    .marks-group-row td { background: #f8f0f0; }
    .marks-depth-1 td { background: #f4eded; }
    .marks-depth-2 td { background: #faf4f4; }

    /* ── Leaf rows ── */
    #marks-table tbody tr.marks-leaf-row:hover td { background: #fff8f8; }
    #marks-table tbody tr.marks-leaf-row:hover td:first-child { background: #f5e8e8 !important; }
    .marks-item-cell {
      font-size: .85rem;
      color: #333;
    }
    .item-weight {
      font-size: .75rem;
      opacity: .6;
      font-weight: 400;
      margin-left: .25em;
    }

    /* ── Grade inputs ── */
    .mark-input {
      width: 66px;
      padding: .28rem .3rem;
      border: 1px solid #ddd;
      border-radius: 3px;
      font-size: .88rem;
      text-align: center;
      background: #fff;
    }
    .mark-input:focus { outline: none; border-color: #8A0808; box-shadow: 0 0 0 2px rgba(138,8,8,.12); }

    /* ── Subtotal cells (group rows) ── */
    .marks-subtotal-cell {
      text-align: center;
      min-width: 72px;
    }
    .subtotal-val {
      display: inline-block;
      font-weight: 700;
      font-size: .85rem;
      min-width: 2.8em;
      padding: .12rem .35rem;
      border-radius: 3px;
      background: rgba(255,255,255,.25);
      letter-spacing: .01em;
    }
    .subtotal-val.mark-red    { color: #c62828; background: rgba(255,255,255,.30); }
    .subtotal-val.mark-orange { color: #e65100; background: rgba(255,255,255,.30); }
    .subtotal-val.mark-green  { color: #1b5e20; background: rgba(255,255,255,.30); }
    .subtotal-val.mark-none   { color: rgba(255,255,255,.55); font-weight: 400; }

    /* Marks action bar */
    #marks-action-bar {
      display: flex;
      align-items: center;
      gap: .75rem;
      margin-top: 1.25rem;
    }

    /* ── Student grade view ───────────────────────────────────────── */
    #pq-grade-view { padding-top: .5rem; }

    .grade-final-box {
      background: linear-gradient(135deg, #8A0808 0%, #b01010 100%);
      color: #fff;
      border-radius: 8px;
      padding: 1.5rem 2rem;
      margin-bottom: 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 16px rgba(138,8,8,.25);
    }
    .grade-final-box .gf-label {
      font-size: 1rem;
      font-weight: 600;
      opacity: .85;
      text-transform: uppercase;
      letter-spacing: .06em;
    }
    .grade-final-box .gf-value {
      font-size: 3rem;
      font-weight: 700;
      line-height: 1;
      letter-spacing: -.02em;
    }
    .grade-final-box .gf-name {
      font-size: 1.4rem;
      font-weight: 600;
      opacity: .9;
    }
    .grade-final-box .gf-username {
      font-size: .95rem;
      font-weight: 400;
      opacity: .65;
      margin-top: .15rem;
    }

    /* Sections */
    .grade-section {
      margin-bottom: 1.5rem;
    }
    .grade-section-header {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      border-bottom: 3px double #8A0808;
      padding-bottom: .35rem;
      margin-bottom: .75rem;
    }
    .grade-section-title {
      font-size: 1.15rem;
      font-weight: 700;
      color: #8A0808;
    }
    .grade-section-avg {
      font-size: 1.05rem;
      font-weight: 700;
    }

    /* Subsection */
    .grade-subsection {
      background: #fafafa;
      border: 1px solid #eee;
      border-radius: 4px;
      margin-bottom: .6rem;
      overflow: hidden;
    }
    .grade-subsection-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: .5rem .75rem;
      background: #f4eeee;
      border-bottom: 1px solid #eee;
    }
    .grade-subsection-title {
      font-weight: 600;
      font-size: .95rem;
      color: #444;
    }
    .grade-subsection-avg {
      font-weight: 700;
      font-size: .95rem;
    }
    .grade-subsection-body { padding: .4rem .75rem; }

    /* Leaf rows */
    .grade-leaf {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: .3rem .25rem;
      border-bottom: 1px solid #f0f0f0;
    }
    .grade-leaf:last-child { border-bottom: none; }
    .grade-leaf-name { font-size: .88rem; color: #555; }
    .grade-leaf-mark { font-weight: 700; font-size: .95rem; }
    .grade-leaf-weight { font-size: .78rem; color: #999; margin-left: .5rem; }

    /* Mark color coding */
    .mark-red   { color: #c0392b; }
    .mark-orange { color: #d35400; }
    .mark-green  { color: #27ae60; }
    .mark-none   { color: #aaa; font-style: italic; }

    /* Shared status / toast */
    .pq-status {
      font-size: .85rem;
      padding: .3rem .6rem;
      border-radius: 3px;
    }
    .pq-status.ok { background: #e8f5e9; color: #2e7d32; }
    .pq-status.err { background: #fdecea; color: #c62828; }

    #pq-toast {
      position: fixed;
      bottom: 1.5rem;
      left: 50%;
      transform: translateX(-50%) translateY(60px);
      background: #222;
      color: #fff;
      padding: .6rem 1.4rem;
      border-radius: 6px;
      font-size: .9rem;
      z-index: 9999;
      opacity: 0;
      transition: transform .25s, opacity .25s;
      pointer-events: none;
      white-space: nowrap;
    }
    #pq-toast.show {
      transform: translateX(-50%) translateY(0);
      opacity: 1;
    }
    #pq-toast.success { background: #2e7d32; }
    #pq-toast.error   { background: #c62828; }

    /* Loading spinner */
    .pq-loading {
      display: flex;
      align-items: center;
      gap: .6rem;
      color: #888;
      font-size: .9rem;
      padding: 1.5rem 0;
    }
    .pq-spinner {
      width: 20px; height: 20px;
      border: 2px solid #ddd;
      border-top-color: #8A0808;
      border-radius: 50%;
      animation: pq-spin .7s linear infinite;
    }
    @keyframes pq-spin { to { transform: rotate(360deg); } }

    /* User badge */
    #pq-user-badge {
      font-size: .78rem;
      color: #666;
      padding: .2rem .6rem;
      background: #f0f0f0;
      border-radius: 3px;
    }

    /* Empty state */
    .pq-empty {
      text-align: center;
      color: #aaa;
      padding: 2rem;
      font-size: .95rem;
    }

    /* Responsive */
    @media (max-width: 680px) {
      #pq-screen { margin-top: 60px; padding: .75rem 1rem 1.5rem; }
      .grade-final-box { flex-direction: column; gap: .75rem; text-align: center; }
      .grade-final-box .gf-value { font-size: 2.2rem; }
    }
  </style>
  <link id="pq-theme-link" rel="stylesheet" href="templates-marks/<?= htmlspecialchars($pq_theme, ENT_QUOTES) ?>">
</head>
<body>

  <!-- ── Login screen (exact replica of index.php) ────────────────── -->
  <div id="login-screen">
    <div id="login-box">
      <h2>
        <img src="icon.svg" style="width:2rem;height:2rem;vertical-align:middle;margin-right:.5rem;" alt="">
        <?= htmlspecialchars($pq_app_name) ?> — Sign in
      </h2>
      <form id="login-form" novalidate>
        <label>Username
          <input id="login-user" type="text" autocomplete="username" required autofocus>
        </label>
        <label>Password
          <input id="login-pass" type="password" autocomplete="current-password" required>
        </label>
        <button type="submit" title="Sign in" aria-label="Sign in">
          <svg viewBox="0 0 24 24" aria-hidden="true" style="width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:2;margin-right:.4rem">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
            <polyline points="10 17 15 12 10 7"/>
            <line x1="15" y1="12" x2="3" y2="12"/>
          </svg>
          Sign in
        </button>
        <p id="login-error"></p>
      </form>
    </div>
  </div>

  <!-- ── App screen ────────────────────────────────────────────────── -->
  <div id="pq-header" style="display:none">
    <a href="marks.php">
      <img src="icon.svg" style="display:inline;width:1.4rem;height:1.4rem;vertical-align:middle;margin-right:.4rem" alt="">
      <?= htmlspecialchars($pq_app_name) ?>
    </a>
    <div id="pq-header-right">
      <span id="pq-user-badge"></span>
      <button class="btn btn-sm" id="pq-theme-btn" title="Change theme" aria-label="Change theme" style="display:none" onclick="pqToggleThemePanel()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0 0 20c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
      </button>
      <button class="btn btn-sm" id="pq-logout-btn" onclick="pqLogout()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign out
      </button>
    </div>
  </div>

  <div id="pq-screen">

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

  </div><!-- #pq-screen -->

  <div id="pq-theme-overlay" onclick="pqToggleThemePanel()"></div>
  <div id="pq-theme-panel">
    <h3>Theme <button class="close-btn" onclick="pqToggleThemePanel()" aria-label="Close">&times;</button></h3>
    <label>Marks theme
      <select id="pq-theme-select"></select>
    </label>
    <div id="pq-theme-panel-actions">
      <button type="button" class="btn" onclick="pqToggleThemePanel()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="pqSaveTheme()">Save</button>
    </div>
  </div>

  <div id="pq-toast"></div>

  <script src="lib/auth-client.js?v=<?= filemtime(__DIR__ . '/lib/auth-client.js') ?>"></script>
  <script>
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
    document.getElementById('pq-header').style.display = 'none';
    document.getElementById('pq-screen').style.display = 'none';
    document.getElementById('login-screen').style.display = '';
    document.getElementById('login-user').value = '';
    document.getElementById('login-pass').value = '';
    document.getElementById('login-error').textContent = '';
  }

  function pqShowApp() {
    document.getElementById('login-screen').style.display = 'none';
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

  // ── Login form (exact pattern from wiki.js) ────────────────────────────
  document.getElementById('login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const user  = document.getElementById('login-user').value.trim();
    const pass  = document.getElementById('login-pass').value;
    const errEl = document.getElementById('login-error');
    errEl.textContent = '';
    if (!user || !pass) { errEl.textContent = 'Please fill in all fields'; return; }

    const hash = await sha256(pass);
    try {
      const res  = await fetch('marks.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user, hash })
      });
      const data = await res.json();
      if (res.ok) {
        sessionStorage.setItem('wkw_token', data.token);
        sessionStorage.setItem('wkw_role',  data.role);
        sessionStorage.setItem('wkw_user',  user);
        sessionStorage.setItem('wkw_name',  data.name || user);
        pqShowApp();
        pqRoute();
      } else {
        errEl.textContent = data.error || 'Authentication error';
      }
    } catch {
      errEl.textContent = 'Connection error';
    }
  });

  // Auto-restore session
  if (getToken()) {
    pqShowApp();
    pqRoute();
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
        apiFetch('marks.php?action=get-users'),
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
      wrap.innerHTML = '<p style="color:#c62828;padding:1rem 0">' + pqEsc(err.message) + '</p>';
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
      nameCell.innerHTML = '<svg style="width:.8em;height:.8em;fill:none;stroke:currentColor;stroke-width:2.5;vertical-align:middle;margin-right:.3em" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>' + pqEsc(node.name) + wLabel;

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
      view.innerHTML = '<p style="color:#c62828;padding:1.5rem 0">' + pqEsc(err.message) + '</p>';
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

  </script>
</body>
</html>
