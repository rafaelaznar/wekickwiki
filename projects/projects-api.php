<?php
// ═══════════════════════════════════════════════════════════════════════════
// projects/projects-api.php — Software Project Management module for WeKickWiki
//
// Shares authentication (users.json, settings.json) with wekickwiki.
// Uses its own data:
//   projects.json — project definitions (name, description, dates)
//   tasks.json    — all tasks flat array (parent_id for unlimited nesting)
//
// Authentication is identical to other modules:
//   lib/auth.php  — JWT helpers, load_users(), require_auth(), json_out()
//   lib/data.php  — data_read(), data_write(), data_next_id()
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/data.php';

// ── Data-file paths ──────────────────────────────────────────────────────────
define('PT_PROJECTS_FILE',  __DIR__ . '/projects.json');
define('PT_TASKS_FILE',     __DIR__ . '/tasks.json');
define('PT_STATUSES_FILE',  __DIR__ . '/task-statuses.json');

// ── Valid priorities ─────────────────────────────────────────────────────────
define('PT_PRIORITIES',  ['low', 'medium', 'high', 'critical']);

// ═══════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════

function pt_load_projects(): array
{
    return data_read(PT_PROJECTS_FILE);
}
function pt_load_tasks(): array
{
    return data_read(PT_TASKS_FILE);
}
function pt_load_statuses(): array
{
    $data = data_read(PT_STATUSES_FILE);
    if (!empty($data) && is_array($data)) return $data;
    // Fall back to built-in defaults if the file is missing/empty
    return [
        ['key' => 'todo',        'name' => 'To Do',      'order' => 0],
        ['key' => 'in_progress', 'name' => 'In Progress', 'order' => 1],
        ['key' => 'in_review',   'name' => 'In Review',   'order' => 2],
        ['key' => 'done',        'name' => 'Done',        'order' => 3],
        ['key' => 'blocked',     'name' => 'Blocked',     'order' => 4],
        ['key' => 'cancelled',   'name' => 'Cancelled',   'order' => 5],
    ];
}

function pt_save_projects(array $d): void
{
    data_write(PT_PROJECTS_FILE, $d);
}
function pt_save_tasks(array $d): void
{
    data_write(PT_TASKS_FILE,    $d);
}
function pt_save_statuses(array $d): void
{
    data_write(PT_STATUSES_FILE, $d);
}

// Returns the list of valid status keys from the JSON file.
function pt_status_keys(): array
{
    return array_column(pt_load_statuses(), 'key');
}

// If the task has an integration_date in the past, force done = true.
// Also sets completed_at to the integration_date if it wasn't already set.
function pt_apply_auto_done(array &$task): void
{
    $intDate = $task['integration_date'] ?? '';
    if ($intDate !== '' && $intDate <= date('Y-m-d')) {
        if (empty($task['done'])) {
            $task['done']         = true;
            $task['completed_at'] = $task['completed_at'] ?? ($intDate . 'T00:00:00+00:00');
        }
    }
}

// Recursively collect all descendant task IDs for a given parent ID.
function pt_descendant_ids(array $tasks, int $parentId): array
{
    $ids = [];
    foreach ($tasks as $t) {
        if ((int)($t['parent_id'] ?? 0) === $parentId) {
            $ids[] = (int)$t['id'];
            $ids   = array_merge($ids, pt_descendant_ids($tasks, (int)$t['id']));
        }
    }
    return $ids;
}

// Sanitise and validate a project payload. Returns cleaned array or null on failure.
function pt_sanitize_project(array $body): ?array
{
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') return null;

    $start = trim((string)($body['start_date'] ?? ''));
    $end   = trim((string)($body['end_date']   ?? ''));

    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return null;
    if ($end   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   return null;
    if ($start !== '' && $end !== '' && $end < $start) return null;

    return [
        'name'        => substr($name, 0, 200),
        'description' => substr(trim((string)($body['description'] ?? '')), 0, 2000),
        'start_date'  => $start,
        'end_date'    => $end,
    ];
}

// Sanitise and validate a task payload. Returns cleaned array or null on failure.
function pt_sanitize_task(array $body): ?array
{
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') return null;

    $status   = (string)($body['status']   ?? 'todo');
    $priority = (string)($body['priority'] ?? 'medium');
    if (!in_array($status,   pt_status_keys(), true)) $status   = 'todo';
    if (!in_array($priority, PT_PRIORITIES, true)) $priority = 'medium';

    $points = max(0, (int)($body['points'] ?? 0));

    $assignees = [];
    if (!empty($body['assignees']) && is_array($body['assignees'])) {
        $guestUsernames = pt_guest_usernames();
        foreach ($body['assignees'] as $a) {
            $a = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$a)));
            if ($a !== '' && isset($guestUsernames[$a])) {
                $assignees[] = $a;
            }
        }
        $assignees = array_values(array_unique($assignees));
    }

    $assignedDate    = trim((string)($body['assigned_date']    ?? ''));
    $integrationDate = trim((string)($body['integration_date'] ?? ''));
    $branch          = trim((string)($body['integration_branch'] ?? ''));

    if ($assignedDate    !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignedDate))    $assignedDate = '';
    if ($integrationDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $integrationDate)) $integrationDate = '';

    return [
        'name'               => substr($name, 0, 300),
        'description'        => substr(trim((string)($body['description']    ?? '')), 0, 2000),
        'specification'      => substr(trim((string)($body['specification']  ?? '')), 0, 50000),
        'status'             => $status,
        'priority'           => $priority,
        'points'             => $points,
        'assignees'          => $assignees,
        'assigned_date'      => $assignedDate,
        'integration_date'   => $integrationDate,
        'integration_branch' => substr($branch, 0, 200),
        'done'               => (bool)($body['done'] ?? false),
    ];
}

// Returns a lookup map of existing guest usernames.
function pt_guest_usernames(): array
{
    $map = [];
    foreach (load_users() as $u) {
        if (!is_array($u)) continue;
        if (($u['role'] ?? '') !== 'guest') continue;
        $un = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($u['username'] ?? ''))));
        if ($un !== '') $map[$un] = true;
    }
    return $map;
}

// ═══════════════════════════════════════════════════════════════════════════
// API router — only runs when this file is the HTTP entry point (not included)
// ═══════════════════════════════════════════════════════════════════════════
if (basename($_SERVER['SCRIPT_FILENAME']) !== 'projects.php') return;

$action = $_GET['action'] ?? '';
if ($action === '') return; // Plain page load — HTML rendering handled by projects.php

// ── get-all-projects (admin) ──────────────────────────────────────────────────
if ($action === 'get-all-projects') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    json_out(200, pt_load_projects());
}

// ── save-project (admin) ─────────────────────────────────────────────────────
if ($action === 'save-project' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $clean = pt_sanitize_project($body);
    if (!$clean) json_out(400, ['error' => 'Invalid project data']);

    $projects = pt_load_projects();
    $now      = date('c');
    $id       = isset($body['id']) ? (int)$body['id'] : 0;

    if ($id > 0) {
        // Update existing
        $found = false;
        foreach ($projects as &$p) {
            if ((int)$p['id'] === $id) {
                $p = array_merge($p, $clean, ['updated_at' => $now]);
                $found = true;
                break;
            }
        }
        unset($p);
        if (!$found) json_out(404, ['error' => 'Project not found']);
    } else {
        // Create new
        $new = array_merge(['id' => data_next_id($projects), 'created_at' => $now, 'updated_at' => $now], $clean);
        $projects[] = $new;
        $id = $new['id'];
    }

    pt_save_projects($projects);
    json_out(200, ['id' => $id]);
}

// ── delete-project (admin) ────────────────────────────────────────────────────
if ($action === 'delete-project' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) json_out(400, ['error' => 'Missing id']);

    $projects = array_values(array_filter(pt_load_projects(), fn($p) => (int)$p['id'] !== $id));
    pt_save_projects($projects);

    // Cascade: delete all tasks belonging to this project
    $tasks = array_values(array_filter(pt_load_tasks(), fn($t) => (int)($t['project_id'] ?? 0) !== $id));
    pt_save_tasks($tasks);

    json_out(200, ['ok' => true]);
}

// ── get-all-tasks (admin) ─────────────────────────────────────────────────────
if ($action === 'get-all-tasks') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $projectId = (int)($_GET['project_id'] ?? 0);
    $tasks = pt_load_tasks();
    if ($projectId > 0) {
        $tasks = array_values(array_filter($tasks, fn($t) => (int)($t['project_id'] ?? 0) === $projectId));
    }
    array_walk($tasks, 'pt_apply_auto_done');
    json_out(200, $tasks);
}

// ── save-task (admin) ─────────────────────────────────────────────────────────
if ($action === 'save-task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $clean = pt_sanitize_task($body);
    if (!$clean) json_out(400, ['error' => 'Invalid task data']);

    $projectId = (int)($body['project_id'] ?? 0);
    if ($projectId <= 0) json_out(400, ['error' => 'Missing project_id']);

    // Validate project exists
    $projects = pt_load_projects();
    $projectExists = false;
    foreach ($projects as $p) {
        if ((int)$p['id'] === $projectId) {
            $projectExists = true;
            break;
        }
    }
    if (!$projectExists) json_out(404, ['error' => 'Project not found']);

    $parentId = isset($body['parent_id']) && $body['parent_id'] !== null ? (int)$body['parent_id'] : null;
    $tasks    = pt_load_tasks();
    $now      = date('c');
    $id       = isset($body['id']) ? (int)$body['id'] : 0;

    if ($id > 0) {
        // Update existing
        $found = false;
        foreach ($tasks as &$t) {
            if ((int)$t['id'] === $id) {
                $prevStatus = $t['status'] ?? 'todo';
                $newStatus  = $clean['status'];
                // Auto-done: integration_date in the past forces done = true
                pt_apply_auto_done($clean);
                $becomesDone = !empty($clean['done']) || $newStatus === 'done';
                $wasDone     = !empty($t['done'])     || $prevStatus === 'done';
                // Manage completed_at timestamp
                if ($becomesDone && !$wasDone) {
                    // Task just became done
                    $clean['completed_at'] = $clean['completed_at'] ?? $now;
                } elseif (!$becomesDone && $wasDone) {
                    // Task un-done
                    $clean['completed_at'] = null;
                } else {
                    // No change in done state — preserve existing timestamp
                    $clean['completed_at'] = $t['completed_at'] ?? ($becomesDone ? $now : null);
                }
                $t = array_merge($t, $clean, ['updated_at' => $now]);
                $found = true;
                break;
            }
        }
        unset($t);
        if (!$found) json_out(404, ['error' => 'Task not found']);
    } else {
        // Create new
        pt_apply_auto_done($clean);
        $completedAt = ($clean['status'] === 'done' || !empty($clean['done']))
            ? ($clean['completed_at'] ?? $now)
            : null;
        $new = array_merge([
            'id'           => data_next_id($tasks),
            'project_id'   => $projectId,
            'parent_id'    => $parentId,
            'created_at'   => $now,
            'updated_at'   => $now,
            'completed_at' => $completedAt,
        ], $clean);
        $tasks[] = $new;
        $id = $new['id'];
    }

    pt_save_tasks($tasks);
    json_out(200, ['id' => $id]);
}

// ── delete-task (admin) ───────────────────────────────────────────────────────
if ($action === 'delete-task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) json_out(400, ['error' => 'Missing id']);

    $tasks       = pt_load_tasks();
    $toDelete    = array_merge([$id], pt_descendant_ids($tasks, $id));
    $toDeleteSet = array_flip($toDelete);
    $tasks       = array_values(array_filter($tasks, fn($t) => !isset($toDeleteSet[(int)$t['id']])));
    pt_save_tasks($tasks);

    json_out(200, ['ok' => true]);
}

// ── get-project-tasks (any authenticated user — read-only) ────────────────────
if ($action === 'get-project-tasks') {
    $claims    = require_auth();
    $projectId = (int)($_GET['project_id'] ?? 0);
    if ($projectId <= 0) json_out(400, ['error' => 'Missing project_id']);

    $tasks = array_values(array_filter(pt_load_tasks(), fn($t) => (int)($t['project_id'] ?? 0) === $projectId));
    array_walk($tasks, 'pt_apply_auto_done');
    json_out(200, $tasks);
}

// ── get-my-tasks (any authenticated user) ─────────────────────────────────────
if ($action === 'get-my-tasks') {
    $claims    = require_auth();
    $username  = $claims['sub'] ?? '';
    $projectId = (int)($_GET['project_id'] ?? 0);

    $tasks = pt_load_tasks();
    if ($projectId > 0) {
        $tasks = array_filter($tasks, fn($t) => (int)($t['project_id'] ?? 0) === $projectId);
    }
    $mine = array_values(array_filter($tasks, function ($t) use ($username) {
        return in_array($username, (array)($t['assignees'] ?? []), true);
    }));
    array_walk($mine, 'pt_apply_auto_done');
    json_out(200, $mine);
}

// ── get-projects-for-user (any authenticated user) ────────────────────────────
if ($action === 'get-projects-for-user') {
    $claims   = require_auth();
    $username = $claims['sub'] ?? '';
    $role     = $claims['role'] ?? 'guest';

    if ($role === 'admin') {
        json_out(200, pt_load_projects());
    }

    // For guests: only projects where they have at least one assigned task
    $tasks    = pt_load_tasks();
    $myProjIds = [];
    foreach ($tasks as $t) {
        if (in_array($username, (array)($t['assignees'] ?? []), true)) {
            $myProjIds[(int)$t['project_id']] = true;
        }
    }
    $projects = array_values(array_filter(pt_load_projects(), fn($p) => isset($myProjIds[(int)$p['id']])));
    json_out(200, $projects);
}

// ── get-projects-templates (admin) ────────────────────────────────────────────
if ($action === 'get-projects-templates') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $dir   = __DIR__ . '/templates-projects/';
    $files = glob($dir . '*.css') ?: [];
    $names = array_map('basename', $files);
    sort($names);
    json_out(200, $names);
}

// ── save-projects-theme (admin) ───────────────────────────────────────────────
if ($action === 'save-projects-theme' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $theme = trim((string)($body['theme'] ?? ''));

    if (
        !preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme) ||
        !is_file(__DIR__ . '/templates-projects/' . $theme)
    ) {
        json_out(400, ['error' => 'Invalid theme']);
    }

    $settings = is_file(SETTINGS_FILE)
        ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? [])
        : [];
    $settings['projectsTheme'] = $theme;
    file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    json_out(200, ['ok' => true]);
}

// ── get-statuses (any authenticated user) ────────────────────────────────────
if ($action === 'get-statuses') {
    $claims   = require_auth();
    $statuses = pt_load_statuses();
    // Admin: enrich each status with a usage count across all tasks
    if (($claims['role'] ?? '') === 'admin') {
        $tasks       = pt_load_tasks();
        $usageCounts = [];
        foreach ($tasks as $t) {
            $s = (string)($t['status'] ?? '');
            if ($s !== '') $usageCounts[$s] = ($usageCounts[$s] ?? 0) + 1;
        }
        foreach ($statuses as &$s) {
            $s['usage_count'] = $usageCounts[$s['key']] ?? 0;
        }
        unset($s);
    }
    json_out(200, $statuses);
}

// ── save-status (admin) ───────────────────────────────────────────────────────
if ($action === 'save-status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $key  = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($body['key'] ?? ''))));
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') json_out(400, ['error' => 'Name is required']);

    $statuses = pt_load_statuses();

    if ($key !== '') {
        // Rename existing status
        $found = false;
        foreach ($statuses as &$s) {
            if ($s['key'] === $key) {
                $s['name'] = substr($name, 0, 100);
                $found = true;
                break;
            }
        }
        unset($s);
        if (!$found) json_out(404, ['error' => 'Status not found']);
    } else {
        // Create new status — auto-generate slug from name
        $newKey  = preg_replace('/[^a-z0-9]+/', '_', strtolower($name));
        $newKey  = trim($newKey, '_');
        if ($newKey === '') $newKey = 'status_' . count($statuses);
        $existing = array_column($statuses, 'key');
        $base = $newKey;
        $i = 2;
        while (in_array($newKey, $existing, true)) {
            $newKey = $base . '_' . $i++;
        }
        $maxOrder   = $statuses ? max(array_column($statuses, 'order')) : -1;
        $statuses[] = ['key' => $newKey, 'name' => substr($name, 0, 100), 'order' => $maxOrder + 1];
        $key        = $newKey;
    }

    pt_save_statuses($statuses);
    json_out(200, ['key' => $key]);
}

// ── delete-status (admin) ─────────────────────────────────────────────────────
if ($action === 'delete-status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $key  = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($body['key'] ?? ''))));
    if ($key === '') json_out(400, ['error' => 'Missing key']);

    // Refuse deletion if any task still uses this status
    foreach (pt_load_tasks() as $t) {
        if (($t['status'] ?? '') === $key) {
            json_out(409, ['error' => 'Cannot delete: tasks are using this status']);
        }
    }

    $statuses = array_values(array_filter(pt_load_statuses(), fn($s) => $s['key'] !== $key));
    pt_save_statuses($statuses);
    json_out(200, ['ok' => true]);
}

json_out(400, ['error' => 'Unknown action']);
