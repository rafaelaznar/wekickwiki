<?php
// ═══════════════════════════════════════════════════════════════════════════
// feedback/feedback-api.php — Feedback module for WeKickWiki
//
// Shares authentication (users.json, settings.json) with wekickwiki.
// Uses its own data:
//   feedback-events.json   — feedback event definitions
//   feedback-responses.json — user responses
//
// Usernames are always stored internally (for duplicate prevention and
// "get-my-responses" tracking). For anonymous events, the username is
// stripped from admin-facing API responses at presentation time.
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/data.php';

// ── Data-file paths ──────────────────────────────────────────────────────────
define('FB_EVENTS_FILE',    __DIR__ . '/feedback-events.json');
define('FB_RESPONSES_FILE', __DIR__ . '/feedback-responses.json');

// ═══════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════
/** Load all feedback events from disk. */
function fb_load_events(): array         { return data_read(FB_EVENTS_FILE);    }
/** Persist the feedback events array. */
function fb_save_events(array $d): void  { data_write(FB_EVENTS_FILE,    $d);   }
/** Load all feedback responses from disk. */
function fb_load_responses(): array      { return data_read(FB_RESPONSES_FILE); }
/** Persist the feedback responses array. */
function fb_save_responses(array $d): void { data_write(FB_RESPONSES_FILE, $d); }

/**
 * Sanitise and validate a raw feedback event payload from the client.
 * Validates type ('open' | 'closed' | 'mixed') and status ('open' | 'closed'),
 * truncates title and description, and casts anonymous to bool.
 *
 * @param mixed $raw  Decoded JSON body
 * @return array|null  Sanitised event array, or null if $raw is not an array
 */
function fb_sanitize_event(mixed $raw): ?array
{
    if (!is_array($raw)) return null;
    $type = (string)($raw['type'] ?? 'open');
    if (!in_array($type, ['open', 'closed', 'mixed'], true)) $type = 'open';
    $status = (string)($raw['status'] ?? 'open');
    if (!in_array($status, ['open', 'closed'], true)) $status = 'open';
    return [
        'id'          => (int)($raw['id'] ?? 0),
        'title'       => trim((string)($raw['title'] ?? '')),
        'description' => trim((string)($raw['description'] ?? '')),
        'type'        => $type,
        'status'      => $status,
        'anonymous'   => (bool)($raw['anonymous'] ?? false),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN — get-events-admin
// Returns all events with a computed response_count per event.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-events-admin') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $events    = fb_load_events();
    $responses = fb_load_responses();

    $counts = [];
    foreach ($responses as $r) {
        $eid = (int)($r['event_id'] ?? 0);
        $counts[$eid] = ($counts[$eid] ?? 0) + 1;
    }
    foreach ($events as &$ev) {
        $ev['response_count'] = $counts[(int)$ev['id']] ?? 0;
    }
    unset($ev);

    json_out(200, $events);
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN — save-event (create id=0, update otherwise)
// Block type change when responses already exist (would make them inconsistent).
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-event') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    $item = fb_sanitize_event($body);
    if (!$item || $item['title'] === '') json_out(400, ['error' => 'Title is required']);

    $events = fb_load_events();

    if ($item['id'] === 0) {
        // Create new event
        $item['id']         = data_next_id($events);
        $item['created_at'] = date('c');
        $item['created_by'] = $claims['sub'] ?? '';
        $events[]           = $item;
    } else {
        // Update existing event
        $responses    = fb_load_responses();
        $hasResponses = !empty(array_filter(
            $responses,
            fn($r) => (int)($r['event_id'] ?? 0) === $item['id']
        ));

        $found = false;
        foreach ($events as &$ev) {
            if ((int)$ev['id'] !== $item['id']) continue;
            // Block type change if responses already exist
            if ($hasResponses && $ev['type'] !== $item['type']) {
                json_out(400, ['error' => 'Cannot change event type once responses exist']);
            }
            $ev['title']       = $item['title'];
            $ev['description'] = $item['description'];
            $ev['type']        = $item['type'];
            $ev['status']      = $item['status'];
            $ev['anonymous']   = $item['anonymous'];
            $found = true;
            break;
        }
        unset($ev);
        if (!$found) json_out(404, ['error' => 'Event not found']);
    }

    fb_save_events($events);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN — delete-event (cascades to responses)
// Both the event and all its responses are removed atomically.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete-event') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) json_out(400, ['error' => 'id required']);

    $events = fb_load_events();
    $events = array_values(array_filter($events, fn($e) => (int)$e['id'] !== $id));
    fb_save_events($events);

    $responses = fb_load_responses();
    $responses = array_values(array_filter($responses, fn($r) => (int)($r['event_id'] ?? 0) !== $id));
    fb_save_responses($responses);

    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN — toggle-event-status (open ↔ closed)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'toggle-event-status') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) json_out(400, ['error' => 'id required']);

    $events = fb_load_events();
    $found  = false;
    foreach ($events as &$ev) {
        if ((int)$ev['id'] !== $id) continue;
        $ev['status'] = ($ev['status'] === 'open') ? 'closed' : 'open';
        $found = true;
        break;
    }
    unset($ev);
    if (!$found) json_out(404, ['error' => 'Event not found']);

    fb_save_events($events);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN — get-event-responses (strips username for anonymous events)
// Username is always stored internally; stripped at presentation time when anonymous=true.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-event-responses') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_out(400, ['error' => 'id required']);

    $events = fb_load_events();
    $event  = null;
    foreach ($events as $ev) {
        if ((int)$ev['id'] === $id) { $event = $ev; break; }
    }
    if (!$event) json_out(404, ['error' => 'Event not found']);

    $responses = fb_load_responses();
    $filtered  = array_values(array_filter(
        $responses,
        fn($r) => (int)($r['event_id'] ?? 0) === $id
    ));

    $isAnonymous = (bool)($event['anonymous'] ?? false);

    $out = [];
    foreach ($filtered as $r) {
        $out[] = [
            'id'           => $r['id']           ?? null,
            'event_id'     => $r['event_id']     ?? null,
            'username'     => $isAnonymous ? null : ($r['username'] ?? null),
            'text'         => $r['text']         ?? null,
            'score'        => $r['score']        ?? null,
            'submitted_at' => $r['submitted_at'] ?? null,
        ];
    }

    $scores    = array_values(array_filter(array_column($filtered, 'score'), fn($s) => $s !== null));
    $avg_score = count($scores) ? round(array_sum($scores) / count($scores), 2) : null;

    json_out(200, [
        'responses' => $out,
        'stats'     => ['avg_score' => $avg_score, 'count' => count($filtered)],
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN — get-feedback-templates
// Lists *.css filenames from templates-feedback/ for the theme picker.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-feedback-templates') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $dir   = __DIR__ . '/templates-feedback';
    $files = is_dir($dir) ? (glob($dir . '/*.css') ?: []) : [];
    sort($files);
    json_out(200, ['templates' => array_map('basename', $files)]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN — save-feedback-theme
// Validates filename against regex + is_file() to prevent path traversal.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-feedback-theme') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body  = json_decode(file_get_contents('php://input'), true);
    $theme = $body['theme'] ?? '';
    if (!is_string($theme) ||
        !preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme) ||
        !is_file(__DIR__ . '/templates-feedback/' . $theme)) {
        json_out(400, ['error' => 'Invalid theme']);
    }
    $raw = data_read(SETTINGS_FILE);
    $raw['feedbackTheme'] = $theme;
    data_write(SETTINGS_FILE, $raw);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// USER — get-open-events
// Returns a safe subset of fields (no internal flags like created_by).
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-open-events') {
    $claims = require_auth();

    $events = fb_load_events();
    $open   = array_values(array_filter($events, fn($e) => ($e['status'] ?? '') === 'open'));

    $out = [];
    foreach ($open as $ev) {
        $out[] = [
            'id'          => (int)$ev['id'],
            'title'       => $ev['title']       ?? '',
            'description' => $ev['description'] ?? '',
            'type'        => $ev['type']        ?? 'open',
            'anonymous'   => (bool)($ev['anonymous'] ?? false),
        ];
    }
    json_out(200, $out);
}

// ═══════════════════════════════════════════════════════════════════════════
// USER — submit-response
// Validates event is open, prevents duplicate responses (409 Conflict),
// and enforces type-appropriate fields (text, score, or both).
// Username always stored internally for duplicate-prevention tracking.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'submit-response') {
    $claims   = require_auth();
    $username = $claims['sub'] ?? '';

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_out(400, ['error' => 'Invalid JSON']);

    $event_id = (int)($body['event_id'] ?? 0);
    if (!$event_id) json_out(400, ['error' => 'event_id required']);

    // Load and validate event
    $events = fb_load_events();
    $event  = null;
    foreach ($events as $ev) {
        if ((int)$ev['id'] === $event_id) { $event = $ev; break; }
    }
    if (!$event)                              json_out(404, ['error' => 'Event not found']);
    if (($event['status'] ?? '') !== 'open') json_out(400, ['error' => 'Event is not open']);

    // Check for duplicate response (username always stored internally)
    $responses = fb_load_responses();
    foreach ($responses as $r) {
        if ((int)($r['event_id'] ?? 0) === $event_id && ($r['username'] ?? '') === $username) {
            json_out(409, ['error' => 'You have already submitted feedback for this event']);
        }
    }

    // Validate fields by event type
    $text  = null;
    $score = null;

    if (in_array($event['type'], ['open', 'mixed'], true)) {
        $text = trim((string)($body['text'] ?? ''));
        if ($text === '') json_out(400, ['error' => 'Text is required for this event type']);
    }

    if (in_array($event['type'], ['closed', 'mixed'], true)) {
        if (!isset($body['score']) || !is_numeric($body['score'])) {
            json_out(400, ['error' => 'Score is required for this event type']);
        }
        $score = (int)$body['score'];
        if ($score < 0 || $score > 10) json_out(400, ['error' => 'Score must be between 0 and 10']);
    }

    $new = [
        'id'           => data_next_id($responses),
        'event_id'     => $event_id,
        'username'     => $username,   // always stored; stripped from admin view when anonymous
        'text'         => $text,
        'score'        => $score,
        'submitted_at' => date('c'),
    ];
    $responses[] = $new;
    fb_save_responses($responses);

    json_out(201, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// USER — get-my-responses (event_id list for "already responded" detection)
// Returns only event_id fields — no response content is exposed.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-my-responses') {
    $claims   = require_auth();
    $username = $claims['sub'] ?? '';

    $responses = fb_load_responses();
    $my = array_values(array_filter($responses, fn($r) => ($r['username'] ?? '') === $username));
    $out = array_map(fn($r) => ['event_id' => (int)($r['event_id'] ?? 0)], $my);

    json_out(200, $out);
}
