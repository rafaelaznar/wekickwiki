<?php
// ═══════════════════════════════════════════════════════════════════════════
// calendar/calendar-api.php — Calendar App
//
// Shares authentication (users.json, settings.json) with wekickwiki.
// Uses its own data:
//   events.json — array of calendar event objects
//
// API endpoints:
//   GET  ?action=get-events             — all authenticated users
//   POST ?action=add-event              — admin only
//   POST ?action=edit-event             — admin only (body must include id)
//   POST ?action=delete-event           — admin only (body: {id})
//   GET  ?action=get-calendar-templates — admin only
//   POST ?action=save-calendar-theme    — admin only (body: {theme})
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/data.php';

define('CAL_EVENTS_FILE', __DIR__ . '/events.json');

// ═══════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════

function cal_load_events(): array
{
    $data = data_read(CAL_EVENTS_FILE);
    return is_array($data) ? $data : [];
}

function cal_save_events(array $events): void
{
    data_write(CAL_EVENTS_FILE, array_values($events));
}

function cal_next_id(array $events): int
{
    if (empty($events)) return 1;
    return max(array_column($events, 'id')) + 1;
}

function cal_sanitize_event(mixed $body): ?array
{
    if (!is_array($body)) return null;

    $title = isset($body['title']) ? trim(substr((string)$body['title'], 0, 128)) : '';
    if ($title === '') return null;

    $date = $body['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;

    $description = isset($body['description'])
        ? trim(substr((string)$body['description'], 0, 2000))
        : '';

    $end_date = null;
    if (!empty($body['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['end_date'])) {
        $end_date = $body['end_date'];
        if ($end_date < $date) $end_date = null; // end before start → ignore
    }

    $time = null;
    if (!empty($body['time']) && preg_match('/^\d{2}:\d{2}$/', $body['time'])) {
        $time = $body['time'];
    }

    $end_time = null;
    if (!empty($body['end_time']) && preg_match('/^\d{2}:\d{2}$/', $body['end_time'])) {
        $end_time = $body['end_time'];
    }

    $color = null;
    if (!empty($body['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'])) {
        $color = $body['color'];
    }

    return [
        'title'       => $title,
        'description' => $description,
        'date'        => $date,
        'end_date'    => $end_date,
        'time'        => $time,
        'end_time'    => $end_time,
        'color'       => $color,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// API: GET ?action=get-events  (all authenticated users)
// Returns events sorted by date ascending
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-events') {
    require_auth();
    $events = cal_load_events();
    usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
    json_out(200, $events);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: POST ?action=add-event  (admin only)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'add-event') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body  = json_decode(file_get_contents('php://input'), true);
    $clean = cal_sanitize_event($body);
    if ($clean === null) json_out(400, ['error' => 'Invalid event data']);

    $events      = cal_load_events();
    $clean['id'] = cal_next_id($events);
    $events[]    = $clean;
    cal_save_events($events);
    json_out(201, $clean);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: POST ?action=edit-event  (admin only)
// Body must include 'id'
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'edit-event') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = isset($body['id']) ? (int)$body['id'] : 0;
    if ($id <= 0) json_out(400, ['error' => 'Missing or invalid id']);

    $clean = cal_sanitize_event($body);
    if ($clean === null) json_out(400, ['error' => 'Invalid event data']);

    $events = cal_load_events();
    $found  = false;
    foreach ($events as &$ev) {
        if ((int)($ev['id'] ?? 0) === $id) {
            $clean['id'] = $id;
            $ev          = $clean;
            $found       = true;
            break;
        }
    }
    unset($ev);
    if (!$found) json_out(404, ['error' => 'Event not found']);

    cal_save_events($events);
    json_out(200, $clean);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: POST ?action=delete-event  (admin only)
// Body: { id: integer }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete-event') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = isset($body['id']) ? (int)$body['id'] : 0;
    if ($id <= 0) json_out(400, ['error' => 'Missing or invalid id']);

    $events   = cal_load_events();
    $filtered = array_values(array_filter($events, fn($ev) => (int)($ev['id'] ?? 0) !== $id));
    if (count($filtered) === count($events)) json_out(404, ['error' => 'Event not found']);

    cal_save_events($filtered);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: GET ?action=get-calendar-templates  (admin only)
// Returns list of CSS files in templates-calendar/
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-calendar-templates') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    $dir   = __DIR__ . '/templates-calendar';
    $files = is_dir($dir) ? (glob($dir . '/*.css') ?: []) : [];
    sort($files);
    json_out(200, ['templates' => array_map('basename', $files)]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: POST ?action=save-calendar-theme  (admin only)
// Body: { theme: "filename.css" }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-calendar-theme') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    $body  = json_decode(file_get_contents('php://input'), true);
    $theme = $body['theme'] ?? '';
    if (!is_string($theme) ||
        !preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme) ||
        !is_file(__DIR__ . '/templates-calendar/' . $theme)) {
        json_out(400, ['error' => 'Invalid theme']);
    }
    $raw                    = data_read(SETTINGS_FILE);
    $raw['calendarTheme']   = $theme;
    data_write(SETTINGS_FILE, $raw);
    json_out(200, ['ok' => true]);
}
