<?php
// ═══════════════════════════════════════════════════════════════════════════
// marks/marks-api.php — Student Qualifications App
//
// Shares authentication (users.json, settings.json) with wekickwiki.
// Uses its own data:
//   items.json  — recursive qualification structure (name, weight, subitems)
//   marks.json  — array of per-user mark objects mirroring items structure
//
// Authentication is replicated exactly from index.php / lib/users-api.php.
// Functions from lib/ are reused without modification.
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/data.php';

// ── Data-file paths ──────────────────────────────────────────────────────────
define('PQ_ITEMS_FILE', __DIR__ . '/items.json');
define('PQ_MARKS_FILE', __DIR__ . '/marks.json');

// ═══════════════════════════════════════════════════════════════════════════
// items.json helpers
// ═══════════════════════════════════════════════════════════════════════════
function pq_load_items(): array
{
    $data = data_read(PQ_ITEMS_FILE);
    if (isset($data['name'])) return $data;
    $default = ['name' => 'My Course', 'weight' => 100, 'subitems' => []];
    data_write(PQ_ITEMS_FILE, $default);
    return $default;
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
    if (
        !is_string($theme) ||
        !preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme) ||
        !is_file(__DIR__ . '/templates-marks/' . $theme)
    ) {
        json_out(400, ['error' => 'Invalid theme']);
    }
    $raw = data_read(SETTINGS_FILE);
    $raw['pqTheme'] = $theme;
    data_write(SETTINGS_FILE, $raw);
    json_out(200, ['ok' => true]);
}
