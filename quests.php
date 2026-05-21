<?php
// ═══════════════════════════════════════════════════════════════════════════
// quests.php — Quiz / Quest module for WeKickWiki
//
// Shares authentication (users.json, settings.json) with wekickwiki.
// Uses its own data:
//   queries.json  — all questions (multiple_choice, binary, gap_filling, matching)
//   quests.json   — quest definitions (label groups, wrong penalty, status)
//   attempts.json — user attempt records with answers and score
//
// Authentication is identical to marks.php / index.php:
//   lib/auth.php       — JWT helpers, load_users(), require_auth(), json_out()
//   lib/users-api.php  — handles ?action=login, get-users, etc.
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/users-api.php';

// ── Data-file paths ──────────────────────────────────────────────────────────
define('QS_QUERIES_FILE',  __DIR__ . '/queries.json');
define('QS_QUESTS_FILE',   __DIR__ . '/quests.json');
define('QS_ATTEMPTS_FILE', __DIR__ . '/attempts.json');

// ═══════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════
function qs_write_json(string $path, mixed $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function qs_load_json(string $path): array
{
    if (!is_file($path)) return [];
    $d = json_decode(file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function qs_load_queries(): array { return qs_load_json(QS_QUERIES_FILE); }
function qs_load_quests():  array { return qs_load_json(QS_QUESTS_FILE);  }
function qs_load_attempts():array { return qs_load_json(QS_ATTEMPTS_FILE); }

function qs_save_queries(array $d): void  { qs_write_json(QS_QUERIES_FILE,  $d); }
function qs_save_quests(array $d):  void  { qs_write_json(QS_QUESTS_FILE,   $d); }
function qs_save_attempts(array $d): void { qs_write_json(QS_ATTEMPTS_FILE, $d); }

/** Returns max(id)+1 across an array of objects with an 'id' field, or 1 if empty. */
function qs_next_id(array $arr): int
{
    $max = 0;
    foreach ($arr as $item) {
        if (isset($item['id']) && (int)$item['id'] > $max) $max = (int)$item['id'];
    }
    return $max + 1;
}

/**
 * Randomly select questions from $all_queries matching the quest's label groups.
 * Each group is an AND of labels (a question must have ALL labels in a group).
 * Returns an array of question ids (may have fewer than requested if not enough match).
 */
function qs_select_questions(array $quest, array $all_queries): array
{
    $selected_ids = [];
    foreach ($quest['queries'] as $group) {
        $labels   = (array)($group['labels'] ?? []);
        $count    = (int)($group['queries'] ?? 0);
        // Filter queries that have ALL required labels and haven't been selected yet
        $pool = array_filter($all_queries, function ($q) use ($labels, $selected_ids) {
            if (in_array((int)$q['id'], $selected_ids, true)) return false;
            $q_labels = (array)($q['labels'] ?? []);
            foreach ($labels as $l) {
                if (!in_array($l, $q_labels, true)) return false;
            }
            return true;
        });
        $pool = array_values($pool);
        shuffle($pool);
        $pick = array_slice($pool, 0, $count);
        foreach ($pick as $q) {
            $selected_ids[] = (int)$q['id'];
        }
    }
    return $selected_ids;
}

/**
 * Score an attempt.
 * $question_ids — ordered list of question ids in this attempt
 * $answers      — array of {id, answer} from the user (all types)
 * $quest        — quest object (for 'wrong' penalty)
 * Returns [correct, incorrect, skipped, score (0-10)]
 */
function qs_score_attempt(array $question_ids, array $answers_raw, array $all_queries, array $quest): array
{
    // Index answers by question id
    $ans_map = [];
    foreach ($answers_raw as $a) {
        $ans_map[(int)($a['id'] ?? 0)] = $a['answer'] ?? null;
    }
    // Index queries by id
    $q_map = [];
    foreach ($all_queries as $q) {
        $q_map[(int)$q['id']] = $q;
    }

    $n         = count($question_ids);
    $correct   = 0;
    $incorrect = 0;
    $skipped   = 0;

    foreach ($question_ids as $qid) {
        $q   = $q_map[$qid] ?? null;
        if (!$q) { $skipped++; continue; }
        $user_ans = $ans_map[$qid] ?? null;

        if ($user_ans === null || $user_ans === '' || $user_ans === []) {
            $skipped++;
            continue;
        }

        $type = $q['type'] ?? 'multiple_choice';

        if ($type === 'multiple_choice') {
            // answer is index (string) of correct option
            $is_correct = ((string)$user_ans === (string)($q['answer'] ?? ''));
        } elseif ($type === 'binary') {
            $is_correct = ((string)$user_ans === (string)($q['answer'] ?? ''));
        } elseif ($type === 'gap_filling') {
            $valid = array_map('strtolower', (array)($q['options'] ?? []));
            $is_correct = in_array(strtolower(trim((string)$user_ans)), $valid, true);
        } elseif ($type === 'matching') {
            // user_ans is {key: value} — must match ALL pairs exactly
            $correct_pairs = (array)($q['options'] ?? []);
            $user_pairs    = (array)$user_ans;
            $is_correct = true;
            foreach ($correct_pairs as $k => $v) {
                if (($user_pairs[$k] ?? '') !== $v) { $is_correct = false; break; }
            }
        } else {
            $is_correct = false;
        }

        if ($is_correct) $correct++;
        else             $incorrect++;
    }

    if ($n === 0) return [$correct, $incorrect, $skipped, 0.0];

    $wrong   = (float)($quest['wrong'] ?? 0);
    $base    = $correct * 10.0 / $n;
    $penalty = ($wrong < 0 && $incorrect > 0) ? ($incorrect * 10.0 / $n) * $wrong : 0.0;
    $score   = round(max(0.0, min(10.0, $base + $penalty)), 2);

    return [$correct, $incorrect, $skipped, $score];
}

// ═══════════════════════════════════════════════════════════════════════════
// Sanitise incoming query (question) object
// ═══════════════════════════════════════════════════════════════════════════
function qs_sanitize_query(array $b): array
{
    $type  = in_array($b['type'] ?? '', ['multiple_choice','binary','gap_filling','matching'], true) ? $b['type'] : 'multiple_choice';
    $query = trim(substr($b['query'] ?? '', 0, 1024));
    $labels = [];
    foreach ((array)($b['labels'] ?? []) as $l) {
        $l = trim(substr((string)$l, 0, 64));
        if ($l !== '') $labels[] = $l;
    }
    $clean = ['type' => $type, 'query' => $query, 'labels' => array_values(array_unique($labels))];

    if ($type === 'multiple_choice') {
        $opts = [];
        foreach ((array)($b['options'] ?? []) as $o) $opts[] = trim(substr((string)$o, 0, 256));
        $clean['options'] = $opts;
        $clean['answer']  = (string)(int)($b['answer'] ?? '0');
    } elseif ($type === 'binary') {
        $clean['answer'] = ($b['answer'] ?? '1') === '0' ? '0' : '1';
    } elseif ($type === 'gap_filling') {
        $opts = [];
        foreach ((array)($b['options'] ?? []) as $o) {
            $o = trim(substr((string)$o, 0, 256));
            if ($o !== '') $opts[] = $o;
        }
        $clean['options'] = $opts;
    } elseif ($type === 'matching') {
        $pairs = [];
        foreach ((array)($b['options'] ?? []) as $k => $v) {
            $k = trim(substr((string)$k, 0, 256));
            $v = trim(substr((string)$v, 0, 256));
            if ($k !== '') $pairs[$k] = $v;
        }
        $clean['options'] = $pairs;
    }
    return $clean;
}

// ═══════════════════════════════════════════════════════════════════════════
// Sanitise incoming quest object
// ═══════════════════════════════════════════════════════════════════════════
function qs_sanitize_quest(array $b): array
{
    $name   = trim(substr($b['name'] ?? '', 0, 256));
    $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['date'] ?? '') ? $b['date'] : date('Y-m-d');
    $status = in_array($b['status'] ?? '', ['open','closed'], true) ? $b['status'] : 'closed';
    $revisable = !empty($b['revisable']);
    $wrong  = isset($b['wrong']) ? max(-1.0, min(0.0, (float)$b['wrong'])) : 0.0;

    $groups = [];
    foreach ((array)($b['queries'] ?? []) as $g) {
        $labels = [];
        foreach ((array)($g['labels'] ?? []) as $l) {
            $l = trim(substr((string)$l, 0, 64));
            if ($l !== '') $labels[] = $l;
        }
        $count = max(1, (int)($g['queries'] ?? 1));
        $groups[] = ['labels' => array_values(array_unique($labels)), 'queries' => $count];
    }
    return ['name' => $name, 'date' => $date, 'status' => $status, 'revisable' => $revisable, 'wrong' => $wrong, 'queries' => $groups];
}


// ═══════════════════════════════════════════════════════════════════════════
// ── ADMIN ENDPOINTS ─────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

// GET ?action=get-queries
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-queries') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    json_out(200, qs_load_queries());
}

// POST ?action=save-query  — add (no id) or update (with id)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-query') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_out(400, ['error' => 'Invalid JSON']);

    $queries = qs_load_queries();
    $clean   = qs_sanitize_query($body);
    if ($clean['query'] === '') json_out(400, ['error' => 'Query text is required']);

    if (isset($body['id'])) {
        // Update
        $id  = (int)$body['id'];
        $idx = -1;
        foreach ($queries as $i => $q) { if ((int)($q['id'] ?? 0) === $id) { $idx = $i; break; } }
        if ($idx === -1) json_out(404, ['error' => 'Query not found']);
        $clean['id'] = $id;
        $queries[$idx] = $clean;
    } else {
        // Insert
        $clean['id'] = qs_next_id($queries);
        $queries[] = $clean;
    }
    qs_save_queries($queries);
    json_out(200, ['ok' => true, 'id' => $clean['id']]);
}

// POST ?action=delete-query  — body: {id}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete-query') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) json_out(400, ['error' => 'id required']);

    // Block if used in any completed attempt
    $attempts = qs_load_attempts();
    foreach ($attempts as $a) {
        if (($a['status'] ?? '') !== 'completed') continue;
        foreach ((array)($a['question_ids'] ?? []) as $qid) {
            if ((int)$qid === $id) json_out(409, ['error' => 'Query is used in a completed attempt and cannot be deleted']);
        }
    }

    $queries = qs_load_queries();
    $queries = array_values(array_filter($queries, fn($q) => (int)($q['id'] ?? 0) !== $id));
    qs_save_queries($queries);
    json_out(200, ['ok' => true]);
}

// GET ?action=get-quests-admin
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-quests-admin') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $quests   = qs_load_quests();
    $attempts = qs_load_attempts();

    // Count attempts per quest
    $counts = [];
    foreach ($attempts as $a) {
        $qid = (int)($a['quest_id'] ?? 0);
        if (($a['status'] ?? '') === 'completed') $counts[$qid] = ($counts[$qid] ?? 0) + 1;
    }
    $result = array_map(function ($q) use ($counts) {
        $q['attempt_count'] = $counts[(int)($q['id'] ?? 0)] ?? 0;
        return $q;
    }, $quests);
    json_out(200, $result);
}

// POST ?action=save-quest
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-quest') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_out(400, ['error' => 'Invalid JSON']);

    $quests = qs_load_quests();
    $clean  = qs_sanitize_quest($body);
    if ($clean['name'] === '') json_out(400, ['error' => 'Quest name is required']);

    if (isset($body['id'])) {
        $id  = (int)$body['id'];
        $idx = -1;
        foreach ($quests as $i => $q) { if ((int)($q['id'] ?? 0) === $id) { $idx = $i; break; } }
        if ($idx === -1) json_out(404, ['error' => 'Quest not found']);
        $clean['id'] = $id;
        $quests[$idx] = $clean;
    } else {
        $clean['id'] = qs_next_id($quests);
        $quests[] = $clean;
    }
    qs_save_quests($quests);
    json_out(200, ['ok' => true, 'id' => $clean['id']]);
}

// POST ?action=delete-quest
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete-quest') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) json_out(400, ['error' => 'id required']);

    $attempts = qs_load_attempts();
    foreach ($attempts as $a) {
        if ((int)($a['quest_id'] ?? 0) === $id) json_out(409, ['error' => 'Quest has attempts and cannot be deleted']);
    }

    $quests = qs_load_quests();
    $quests = array_values(array_filter($quests, fn($q) => (int)($q['id'] ?? 0) !== $id));
    qs_save_quests($quests);
    json_out(200, ['ok' => true]);
}

// GET ?action=get-all-attempts  (admin)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-all-attempts') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $attempts = qs_load_attempts();
    $quests   = qs_load_quests();
    $q_map = [];
    foreach ($quests as $q) $q_map[(int)$q['id']] = $q['name'] ?? '—';

    $result = [];
    foreach ($attempts as $a) {
        if (($a['status'] ?? '') !== 'completed') continue;
        $result[] = [
            'id'           => $a['id'],
            'username'     => $a['username'] ?? ($a['usename'] ?? '—'),
            'quest_id'     => $a['quest_id'],
            'quest_name'   => $q_map[(int)($a['quest_id'] ?? 0)] ?? '—',
            'score'        => $a['score'] ?? null,
            'submitted_at' => $a['submitted_at'] ?? ($a['started_at'] ?? null),
        ];
    }
    // Sort by date desc
    usort($result, fn($a, $b) => strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? ''));
    json_out(200, $result);
}

// GET ?action=get-quests-templates
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-quests-templates') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    $dir   = __DIR__ . '/templates-quests';
    $files = is_dir($dir) ? (glob($dir . '/*.css') ?: []) : [];
    sort($files);
    json_out(200, ['templates' => array_map('basename', $files)]);
}

// POST ?action=save-quests-theme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-quests-theme') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    $body  = json_decode(file_get_contents('php://input'), true);
    $theme = $body['theme'] ?? '';
    if (!is_string($theme) ||
        !preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme) ||
        !is_file(__DIR__ . '/templates-quests/' . $theme)) {
        json_out(400, ['error' => 'Invalid theme']);
    }
    $raw = is_file(SETTINGS_FILE) ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? []) : [];
    $raw['questsTheme'] = $theme;
    file_put_contents(SETTINGS_FILE, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ── USER ENDPOINTS (admin + guest) ──────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

// GET ?action=get-open-quests  — quests open and not yet completed by this user
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-open-quests') {
    $claims   = require_auth();
    $username = $claims['sub'] ?? '';

    $quests   = qs_load_quests();
    $attempts = qs_load_attempts();
    $all_q    = qs_load_queries();

    // Collect quest_ids already completed by this user
    $done = [];
    foreach ($attempts as $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        if ($u === $username && ($a['status'] ?? '') === 'completed') {
            $done[] = (int)($a['quest_id'] ?? 0);
        }
    }

    $result = [];
    foreach ($quests as $q) {
        if (($q['status'] ?? '') !== 'open') continue;
        if (in_array((int)($q['id'] ?? 0), $done, true)) continue;
        // Estimate total questions
        $total = 0;
        foreach ($q['queries'] as $g) $total += (int)($g['queries'] ?? 0);
        $result[] = [
            'id'         => $q['id'],
            'name'       => $q['name'],
            'date'       => $q['date'],
            'total_q'    => $total,
            'revisable'  => $q['revisable'] ?? false,
        ];
    }
    json_out(200, $result);
}

// POST ?action=start-quest  — body: {quest_id}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'start-quest') {
    $claims   = require_auth();
    $username = $claims['sub'] ?? '';

    $body     = json_decode(file_get_contents('php://input'), true);
    $quest_id = (int)($body['quest_id'] ?? 0);
    if (!$quest_id) json_out(400, ['error' => 'quest_id required']);

    // Load quest
    $quests = qs_load_quests();
    $quest  = null;
    foreach ($quests as $q) { if ((int)($q['id'] ?? 0) === $quest_id) { $quest = $q; break; } }
    if (!$quest) json_out(404, ['error' => 'Quest not found']);
    if (($quest['status'] ?? '') !== 'open') json_out(403, ['error' => 'Quest is not open']);

    // Check user hasn't already completed it
    $attempts = qs_load_attempts();
    foreach ($attempts as $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        if ($u === $username && (int)($a['quest_id'] ?? 0) === $quest_id && ($a['status'] ?? '') === 'completed') {
            json_out(409, ['error' => 'You have already completed this quest']);
        }
    }

    // Remove any stale pending attempt for this user+quest
    $attempts = array_values(array_filter($attempts, function ($a) use ($username, $quest_id) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        return !($u === $username && (int)($a['quest_id'] ?? 0) === $quest_id && ($a['status'] ?? '') === 'pending');
    }));

    // Select questions
    $all_queries  = qs_load_queries();
    $question_ids = qs_select_questions($quest, $all_queries);
    if (empty($question_ids)) json_out(500, ['error' => 'No matching questions found for this quest']);

    // Create pending attempt
    $attempt_id = qs_next_id($attempts);
    $attempts[] = [
        'id'           => $attempt_id,
        'username'     => $username,
        'quest_id'     => $quest_id,
        'status'       => 'pending',
        'question_ids' => $question_ids,
        'queries'      => [],
        'score'        => null,
        'started_at'   => date('c'),
        'submitted_at' => null,
    ];
    qs_save_attempts($attempts);

    // Return questions without correct answers
    $q_map = [];
    foreach ($all_queries as $q) $q_map[(int)$q['id']] = $q;

    $questions_out = [];
    foreach ($question_ids as $qid) {
        $q = $q_map[$qid] ?? null;
        if (!$q) continue;
        $out = ['id' => $q['id'], 'type' => $q['type'], 'query' => $q['query']];
        if ($q['type'] === 'multiple_choice') {
            $out['options'] = $q['options'];
        } elseif ($q['type'] === 'binary') {
            // no extra fields needed
        } elseif ($q['type'] === 'gap_filling') {
            // no options hint to user
        } elseif ($q['type'] === 'matching') {
            // shuffle the values so right column is randomised
            $keys   = array_keys($q['options']);
            $vals   = array_values($q['options']);
            shuffle($vals);
            $out['keys']   = $keys;
            $out['values'] = $vals;
        }
        $questions_out[] = $out;
    }

    json_out(200, [
        'attempt_id' => $attempt_id,
        'quest_name' => $quest['name'],
        'wrong'      => $quest['wrong'] ?? 0,
        'questions'  => $questions_out,
    ]);
}

// POST ?action=submit-attempt  — body: {attempt_id, answers:[{id, answer}]}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'submit-attempt') {
    $claims    = require_auth();
    $username  = $claims['sub'] ?? '';

    $body      = json_decode(file_get_contents('php://input'), true);
    $attempt_id = (int)($body['attempt_id'] ?? 0);
    $answers    = (array)($body['answers'] ?? []);
    if (!$attempt_id) json_out(400, ['error' => 'attempt_id required']);

    $attempts = qs_load_attempts();
    $idx      = -1;
    foreach ($attempts as $i => $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        if ((int)($a['id'] ?? 0) === $attempt_id && $u === $username) { $idx = $i; break; }
    }
    if ($idx === -1) json_out(404, ['error' => 'Attempt not found']);
    if (($attempts[$idx]['status'] ?? '') === 'completed') json_out(409, ['error' => 'Already submitted']);

    $quest_id    = (int)($attempts[$idx]['quest_id'] ?? 0);
    $question_ids = (array)($attempts[$idx]['question_ids'] ?? []);

    $quests = qs_load_quests();
    $quest  = null;
    foreach ($quests as $q) { if ((int)($q['id'] ?? 0) === $quest_id) { $quest = $q; break; } }
    if (!$quest) json_out(500, ['error' => 'Quest not found']);

    $all_queries = qs_load_queries();
    [$correct, $incorrect, $skipped, $score] = qs_score_attempt($question_ids, $answers, $all_queries, $quest);

    // Sanitise stored answers (strip unknown fields)
    $stored_answers = [];
    foreach ($answers as $a) {
        $stored_answers[] = ['id' => (int)($a['id'] ?? 0), 'answer' => $a['answer'] ?? null];
    }

    $attempts[$idx]['status']       = 'completed';
    $attempts[$idx]['queries']      = $stored_answers;
    $attempts[$idx]['score']        = $score;
    $attempts[$idx]['submitted_at'] = date('c');

    qs_save_attempts($attempts);

    json_out(200, [
        'score'     => $score,
        'correct'   => $correct,
        'incorrect' => $incorrect,
        'skipped'   => $skipped,
        'total'     => count($question_ids),
    ]);
}

// GET ?action=get-my-attempts
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-my-attempts') {
    $claims   = require_auth();
    $username = $claims['sub'] ?? '';

    $attempts = qs_load_attempts();
    $quests   = qs_load_quests();
    $q_map = [];
    foreach ($quests as $q) $q_map[(int)$q['id']] = $q;

    $result = [];
    foreach ($attempts as $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        if ($u !== $username || ($a['status'] ?? '') !== 'completed') continue;
        $quest = $q_map[(int)($a['quest_id'] ?? 0)] ?? null;
        $result[] = [
            'id'          => $a['id'],
            'quest_id'    => $a['quest_id'],
            'quest_name'  => $quest['name'] ?? '—',
            'score'       => $a['score'],
            'submitted_at'=> $a['submitted_at'] ?? null,
            'revisable'   => ($quest['revisable'] ?? false),
        ];
    }
    usort($result, fn($a, $b) => strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? ''));
    json_out(200, $result);
}

// GET ?action=review-attempt&id=N
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'review-attempt') {
    $claims     = require_auth();
    $username   = $claims['sub'] ?? '';
    $attempt_id = (int)($_GET['id'] ?? 0);
    if (!$attempt_id) json_out(400, ['error' => 'id required']);

    $attempts = qs_load_attempts();
    $attempt  = null;
    foreach ($attempts as $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        // Admin can review any; user can only review their own
        if ((int)($a['id'] ?? 0) === $attempt_id) {
            if ($username === $u || ($claims['role'] ?? '') === 'admin') { $attempt = $a; break; }
        }
    }
    if (!$attempt) json_out(404, ['error' => 'Attempt not found']);
    if (($attempt['status'] ?? '') !== 'completed') json_out(409, ['error' => 'Attempt not completed']);

    $quests = qs_load_quests();
    $quest  = null;
    foreach ($quests as $q) { if ((int)($q['id'] ?? 0) === (int)($attempt['quest_id'] ?? 0)) { $quest = $q; break; } }
    if (!$quest) json_out(404, ['error' => 'Quest not found']);
    if (!($quest['revisable'] ?? false) && ($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'This quest is not revisable']);

    // Build answer map
    $ans_map = [];
    foreach ((array)($attempt['queries'] ?? []) as $a) {
        $ans_map[(int)($a['id'] ?? 0)] = $a['answer'] ?? null;
    }

    // Load full questions WITH answers
    $all_queries = qs_load_queries();
    $q_map = [];
    foreach ($all_queries as $q) $q_map[(int)$q['id']] = $q;

    $questions_out = [];
    foreach ((array)($attempt['question_ids'] ?? []) as $qid) {
        $q = $q_map[(int)$qid] ?? null;
        if (!$q) continue;
        $user_ans = $ans_map[(int)$qid] ?? null;
        $out = [
            'id'          => $q['id'],
            'type'        => $q['type'],
            'query'       => $q['query'],
            'user_answer' => $user_ans,
        ];
        if ($q['type'] === 'multiple_choice') {
            $out['options'] = $q['options'];
            $out['answer']  = $q['answer'];
        } elseif ($q['type'] === 'binary') {
            $out['answer']  = $q['answer'];
        } elseif ($q['type'] === 'gap_filling') {
            $out['options'] = $q['options']; // correct values
        } elseif ($q['type'] === 'matching') {
            $out['options'] = $q['options']; // correct key->value map
        }
        $questions_out[] = $out;
    }

    json_out(200, [
        'attempt_id'   => $attempt['id'],
        'quest_name'   => $quest['name'],
        'score'        => $attempt['score'],
        'submitted_at' => $attempt['submitted_at'],
        'questions'    => $questions_out,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ── HTML RENDERING ──────────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════
$qs_theme    = 'default.css';
$qs_app_name = 'Quests';
if (is_file(SETTINGS_FILE)) {
    $_raw = json_decode(file_get_contents(SETTINGS_FILE), true) ?? [];
    if (!empty($_raw['wikiName'])) $qs_app_name = $_raw['wikiName'] . ' — Quests';
    if (!empty($_raw['questsTheme']) &&
        preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['questsTheme']) &&
        is_file(__DIR__ . '/templates-quests/' . $_raw['questsTheme'])) {
        $qs_theme = $_raw['questsTheme'];
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
  <title><?= htmlspecialchars($qs_app_name) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="icon.svg">
  <link id="qs-theme-link" rel="stylesheet" href="templates-quests/<?= htmlspecialchars($qs_theme, ENT_QUOTES) ?>">
</head>
<body>

  <!-- ── Login screen ─────────────────────────────────────────────────── -->
  <div id="login-screen">
    <div id="login-box">
      <h2>
        <img src="icon.svg" style="width:2rem;height:2rem;vertical-align:middle;margin-right:.5rem;" alt="">
        <?= htmlspecialchars($qs_app_name) ?> — Sign in
      </h2>
      <form id="login-form" novalidate>
        <label>Username
          <input id="login-user" type="text" autocomplete="username" required autofocus>
        </label>
        <label>Password
          <input id="login-pass" type="password" autocomplete="current-password" required>
        </label>
        <button type="submit">
          <svg viewBox="0 0 24 24" style="width:1.2rem;height:1.2rem;fill:none;stroke:currentColor;stroke-width:2;margin-right:.4rem" aria-hidden="true">
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

  <!-- ── App header ───────────────────────────────────────────────────── -->
  <div id="qs-header" style="display:none">
    <a href="quests.php">
      <img src="icon.svg" style="display:inline;width:1.4rem;height:1.4rem;vertical-align:middle;margin-right:.4rem" alt="">
      <?= htmlspecialchars($qs_app_name) ?>
    </a>
    <div id="qs-header-right">
      <span id="qs-user-badge"></span>
      <button class="btn btn-sm" id="qs-theme-btn" title="Theme" style="display:none" onclick="qsToggleThemePanel()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0 0 20c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
      </button>
      <button class="btn btn-sm" onclick="qsLogout()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign out
      </button>
    </div>
  </div>

  <!-- ── App screen ───────────────────────────────────────────────────── -->
  <div id="qs-screen">

    <!-- ADMIN PANEL -->
    <div id="admin-panel" style="display:none">
      <div class="qs-tabs">
        <div class="qs-tab active" data-tab="questions" onclick="qsShowTab('questions')">
          <svg style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2;vertical-align:middle;margin-right:.3em" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          Questions
        </div>
        <div class="qs-tab" data-tab="quests" onclick="qsShowTab('quests')">
          <svg style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2;vertical-align:middle;margin-right:.3em" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          Quests
        </div>
        <div class="qs-tab" data-tab="results" onclick="qsShowTab('results')">
          <svg style="width:.9em;height:.9em;fill:none;stroke:currentColor;stroke-width:2;vertical-align:middle;margin-right:.3em" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          Results
        </div>
      </div>

      <!-- Questions tab -->
      <div id="tab-questions" class="qs-tab-panel active">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="qsOpenQueryModal()">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add question
          </button>
          <span id="queries-status" class="qs-status" style="display:none"></span>
        </div>
        <div id="queries-table-wrap" class="qs-table-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
      </div>

      <!-- Quests tab -->
      <div id="tab-quests" class="qs-tab-panel">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="qsOpenQuestModal()">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add quest
          </button>
          <span id="quests-admin-status" class="qs-status" style="display:none"></span>
        </div>
        <div id="quests-admin-table-wrap" class="qs-table-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
      </div>

      <!-- Results tab -->
      <div id="tab-results" class="qs-tab-panel">
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
          <button class="btn" onclick="qsLoadResults()">
            <svg viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.36"/></svg>
            Reload
          </button>
        </div>
        <div id="results-table-wrap" class="qs-table-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
      </div>
    </div><!-- #admin-panel -->

    <!-- USER PANEL -->
    <div id="user-panel" style="display:none">
      <!-- Available quests -->
      <div id="user-quest-list">
        <h2 style="margin-bottom:1rem">Available quests</h2>
        <div id="open-quests-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
        <h2 style="margin:1.5rem 0 .75rem">My completed quests</h2>
        <div id="my-attempts-wrap">
          <div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>
        </div>
      </div>

      <!-- Wizard (hidden until quest started) -->
      <div id="user-wizard" style="display:none"></div>

      <!-- Review view -->
      <div id="user-review" style="display:none"></div>

      <!-- Score result screen -->
      <div id="user-result" style="display:none"></div>

    </div><!-- #user-panel -->

  </div><!-- #qs-screen -->

  <!-- ── Modals ────────────────────────────────────────────────────────── -->

  <!-- Query modal -->
  <div class="qs-overlay" id="query-modal-overlay" onclick="e => { if(e.target===this) qsCloseQueryModal(); }">
    <div class="qs-modal" onclick="event.stopPropagation()">
      <button class="qs-modal-close" onclick="qsCloseQueryModal()" aria-label="Close">&times;</button>
      <h3 id="query-modal-title">Add question</h3>
      <div class="qs-field">
        <label>Type</label>
        <select id="qm-type" onchange="qsRenderQueryTypeFields()">
          <option value="multiple_choice">Multiple choice</option>
          <option value="binary">Binary (Yes/No)</option>
          <option value="gap_filling">Gap filling (open answer)</option>
          <option value="matching">Matching</option>
        </select>
      </div>
      <div class="qs-field">
        <label>Question text</label>
        <textarea id="qm-query" placeholder="Enter the question…"></textarea>
      </div>
      <div class="qs-field">
        <label>Labels <span style="font-weight:400;color:#888">(comma-separated)</span></label>
        <input type="text" id="qm-labels" placeholder="e.g. mathematics, algebra">
      </div>
      <!-- Type-specific fields rendered here -->
      <div id="qm-type-fields"></div>
      <div class="qs-modal-actions">
        <button class="btn" onclick="qsCloseQueryModal()">Cancel</button>
        <button class="btn btn-primary" onclick="qsSaveQuery()">Save</button>
      </div>
    </div>
  </div>

  <!-- Quest modal -->
  <div class="qs-overlay" id="quest-modal-overlay" onclick="e => { if(e.target===this) qsCloseQuestModal(); }">
    <div class="qs-modal" onclick="event.stopPropagation()">
      <button class="qs-modal-close" onclick="qsCloseQuestModal()" aria-label="Close">&times;</button>
      <h3 id="quest-modal-title">Add quest</h3>
      <div class="qs-field">
        <label>Name</label>
        <input type="text" id="qst-name" placeholder="e.g. First semester final quest" maxlength="256">
      </div>
      <div class="qs-field-row">
        <div class="qs-field">
          <label>Date</label>
          <input type="date" id="qst-date">
        </div>
        <div class="qs-field">
          <label>Status</label>
          <select id="qst-status">
            <option value="open">Open</option>
            <option value="closed">Closed</option>
          </select>
        </div>
      </div>
      <div class="qs-field-row">
        <div class="qs-field">
          <label>Wrong answer penalty <span style="font-weight:400;color:#888">(0 = none, -0.25 = ¼ deduction)</span></label>
          <input type="number" id="qst-wrong" step="0.01" min="-1" max="0" value="0" placeholder="-0.25">
        </div>
        <div class="qs-field" style="flex:0 0 auto">
          <label>Revisable</label>
          <select id="qst-revisable">
            <option value="1">Yes</option>
            <option value="0">No</option>
          </select>
        </div>
      </div>
      <div class="qs-field">
        <label>Question groups <span style="font-weight:400;color:#888">(labels AND-matched, random pick)</span></label>
        <div id="qst-label-groups" class="qs-label-groups"></div>
        <button class="btn btn-sm" style="margin-top:.4rem" onclick="qsAddLabelGroup()">
          <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add group
        </button>
      </div>
      <div class="qs-modal-actions">
        <button class="btn" onclick="qsCloseQuestModal()">Cancel</button>
        <button class="btn btn-primary" onclick="qsSaveQuest()">Save</button>
      </div>
    </div>
  </div>

  <!-- Delete confirm modal -->
  <div class="qs-overlay" id="delete-modal-overlay">
    <div class="qs-modal" onclick="event.stopPropagation()" style="max-width:380px">
      <h3 id="delete-modal-title">Confirm delete</h3>
      <p id="delete-modal-msg" style="margin-bottom:1.25rem;font-size:.9rem;color:#555"></p>
      <div class="qs-modal-actions">
        <button class="btn" onclick="qsCloseDeleteModal()">Cancel</button>
        <button class="btn btn-danger" id="delete-modal-confirm">Delete</button>
      </div>
    </div>
  </div>

  <!-- Theme panel -->
  <div id="qs-theme-overlay" onclick="qsToggleThemePanel()"></div>
  <div id="qs-theme-panel">
    <h3>Theme <button class="close-btn" onclick="qsToggleThemePanel()" aria-label="Close">&times;</button></h3>
    <label>Quests theme
      <select id="qs-theme-select"></select>
    </label>
    <div id="qs-theme-panel-actions">
      <button class="btn" onclick="qsToggleThemePanel()">Cancel</button>
      <button class="btn btn-primary" onclick="qsSaveTheme()">Save</button>
    </div>
  </div>

  <div id="qs-toast"></div>

  <script src="lib/auth-client.js?v=<?= filemtime(__DIR__ . '/lib/auth-client.js') ?>"></script>
  <script>
  // ═══════════════════════════════════════════════════════════════════════════
  // quests.js — inline app script
  // ═══════════════════════════════════════════════════════════════════════════

  // ── State ──────────────────────────────────────────────────────────────────
  let _editingQueryId  = null; // null = adding, number = editing
  let _editingQuestId  = null;
  let _deleteCallback  = null;
  let _wizardData      = null; // {attempt_id, quest_name, wrong, questions}
  let _wizardStep      = 0;
  let _wizardAnswers   = {};   // {question_id: answer}

  // ── Toast ──────────────────────────────────────────────────────────────────
  let _qsToastTimer;
  function qsToast(msg, type = 'success', ms = 3200) {
    const el = document.getElementById('qs-toast');
    el.textContent = msg;
    el.className = 'show ' + type;
    clearTimeout(_qsToastTimer);
    _qsToastTimer = setTimeout(() => el.classList.remove('show'), ms);
  }

  function qsSetStatus(id, msg, type) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'qs-status ' + type;
    el.style.display = msg ? '' : 'none';
  }

  // ── Auth & routing ─────────────────────────────────────────────────────────
  setOnUnauthorized(qsLogout);

  function qsLogout() {
    sessionStorage.clear();
    document.getElementById('qs-header').style.display  = 'none';
    document.getElementById('qs-screen').style.display  = 'none';
    document.getElementById('login-screen').style.display = '';
    document.getElementById('login-user').value = '';
    document.getElementById('login-pass').value = '';
    document.getElementById('login-error').textContent = '';
  }

  function qsShowApp() {
    document.getElementById('login-screen').style.display = 'none';
    document.getElementById('qs-header').style.display = 'flex';
    document.getElementById('qs-screen').style.display = 'block';
    document.getElementById('qs-user-badge').textContent = getUser() + ' (' + getRole() + ')';
  }

  function qsRoute() {
    const role    = getRole();
    const themeBtn = document.getElementById('qs-theme-btn');
    if (themeBtn) themeBtn.style.display = role === 'admin' ? '' : 'none';
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

  // ── Login ──────────────────────────────────────────────────────────────────
  document.getElementById('login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const user  = document.getElementById('login-user').value.trim();
    const pass  = document.getElementById('login-pass').value;
    const errEl = document.getElementById('login-error');
    errEl.textContent = '';
    if (!user || !pass) { errEl.textContent = 'Please fill in all fields'; return; }
    const hash = await sha256(pass);
    try {
      const res  = await fetch('quests.php?action=login', {
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
        qsShowApp();
        qsRoute();
      } else {
        errEl.textContent = data.error || 'Authentication error';
      }
    } catch { errEl.textContent = 'Connection error'; }
  });

  if (getToken()) { qsShowApp(); qsRoute(); }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── ADMIN — Tab switching ───────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
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

  // ── Score color helper ─────────────────────────────────────────────────────
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
    try { return new Date(d).toLocaleDateString(undefined, {year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
    catch { return d; }
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
      const queries = await res.json();
      wrap.innerHTML = '';
      if (!queries.length) {
        wrap.innerHTML = '<p class="qs-empty">No questions yet. Click "Add question" to create one.</p>';
        return;
      }
      const table = document.createElement('table');
      table.className = 'qs-table';
      table.innerHTML = `<thead><tr>
        <th style="width:3rem">ID</th>
        <th>Question</th>
        <th>Type</th>
        <th>Labels</th>
        <th style="width:110px">Actions</th>
      </tr></thead>`;
      const tbody = document.createElement('tbody');
      for (const q of queries) {
        const tr = document.createElement('tr');
        const labelsHtml = (q.labels || []).map(l => `<span class="badge badge-label">${esc(l)}</span>`).join(' ');
        tr.innerHTML = `
          <td style="text-align:center;color:#888">${q.id}</td>
          <td style="max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(q.query)}">${esc(q.query)}</td>
          <td><span class="badge badge-type">${esc(q.type)}</span></td>
          <td>${labelsHtml || '<span style="color:#bbb">—</span>'}</td>
          <td>
            <button class="btn btn-sm" onclick="qsEditQuery(${q.id})">Edit</button>
            <button class="btn btn-sm btn-danger" onclick="qsConfirmDeleteQuery(${q.id})">Del</button>
          </td>`;
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      wrap.appendChild(table);
    } catch (err) {
      wrap.innerHTML = `<p style="padding:1rem;color:#c62828">${esc(err.message)}</p>`;
    }
  }

  // ── Query modal ────────────────────────────────────────────────────────────
  let _allQueriesCache = [];

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
    // Reload fresh data
    const res = await apiFetch('quests.php?action=get-queries');
    if (!res.ok) { qsToast('Failed to load question', 'error'); return; }
    const queries = await res.json();
    const q = queries.find(x => x.id === id);
    if (!q) { qsToast('Question not found', 'error'); return; }
    qsOpenQueryModal(q);
  }

  function qsRenderQueryTypeFields(data = null) {
    const type   = document.getElementById('qm-type').value;
    const wrap   = document.getElementById('qm-type-fields');
    wrap.innerHTML = '';

    if (type === 'multiple_choice') {
      const opts = data?.options || ['', '', '', ''];
      const ans  = data?.answer ?? '0';
      let html = `<div class="qs-field"><label>Options <span style="font-weight:400;color:#888">(mark correct with radio)</span></label><ul class="qs-options-list" id="mc-options-list">`;
      opts.forEach((o, i) => {
        html += `<li>
          <input type="radio" name="mc-correct" value="${i}" ${parseInt(ans) === i ? 'checked' : ''} title="Correct answer">
          <input type="text" class="mc-opt-input" value="${esc(o)}" placeholder="Option ${i+1}">
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
        html += `<li><input type="text" class="gf-opt-input" value="${esc(o)}" placeholder="Accepted answer…">
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
          <input type="text" class="mt-key" value="${esc(k)}" placeholder="Key (left)">
          <span class="qs-matching-sep">→</span>
          <input type="text" class="mt-val" value="${esc(v)}" placeholder="Value (right)">
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
    // Re-index radio values
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

    if (!query) { qsToast('Question text is required', 'error'); return; }

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
      qsToast(_editingQueryId ? 'Question updated' : 'Question added');
      qsLoadQueries();
    } else {
      qsToast(data.error || 'Save failed', 'error');
    }
  }

  function qsConfirmDeleteQuery(id) {
    _deleteCallback = async () => {
      const res  = await apiFetch('quests.php?action=delete-query', {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
      });
      const data = await res.json().catch(() => ({}));
      qsCloseDeleteModal();
      if (res.ok) { qsToast('Question deleted'); qsLoadQueries(); }
      else qsToast(data.error || 'Delete failed', 'error');
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
        <th>Name</th><th>Date</th><th>Status</th><th>Wrong</th><th>Revisable</th><th>Attempts</th><th style="width:120px">Actions</th>
      </tr></thead>`;
      const tbody = document.createElement('tbody');
      for (const q of quests) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${esc(q.name)}</strong></td>
          <td>${esc(q.date || '—')}</td>
          <td><span class="badge badge-${q.status}">${esc(q.status)}</span></td>
          <td>${q.wrong < 0 ? q.wrong : '—'}</td>
          <td>${q.revisable ? '✔' : '—'}</td>
          <td style="text-align:center">${q.attempt_count ?? 0}</td>
          <td>
            <button class="btn btn-sm" onclick="qsEditQuest(${q.id})">Edit</button>
            <button class="btn btn-sm btn-danger" onclick="qsConfirmDeleteQuest(${q.id})">Del</button>
          </td>`;
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      wrap.appendChild(table);
    } catch (err) {
      wrap.innerHTML = `<p style="padding:1rem;color:#c62828">${esc(err.message)}</p>`;
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

    // Render label groups
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
    if (!res.ok) { qsToast('Failed to load quest', 'error'); return; }
    const quests = await res.json();
    const q  = quests.find(x => x.id === id);
    if (!q) { qsToast('Quest not found', 'error'); return; }
    qsOpenQuestModal(q);
  }

  function qsAddLabelGroup(data = null) {
    const wrap = document.getElementById('qst-label-groups');
    const div  = document.createElement('div');
    div.className = 'qs-label-group';
    const labels = data?.labels?.join(', ') ?? '';
    const count  = data?.queries ?? 1;
    div.innerHTML = `
      <input type="text" placeholder="Labels (comma-separated, AND)" value="${esc(labels)}" style="flex:1">
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

    if (!name) { qsToast('Quest name is required', 'error'); return; }

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
      qsToast(_editingQuestId ? 'Quest updated' : 'Quest added');
      qsLoadQuestsAdmin();
    } else {
      qsToast(data.error || 'Save failed', 'error');
    }
  }

  function qsConfirmDeleteQuest(id) {
    _deleteCallback = async () => {
      const res  = await apiFetch('quests.php?action=delete-quest', {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
      });
      const data = await res.json().catch(() => ({}));
      qsCloseDeleteModal();
      if (res.ok) { qsToast('Quest deleted'); qsLoadQuestsAdmin(); }
      else qsToast(data.error || 'Delete failed', 'error');
    };
    document.getElementById('delete-modal-title').textContent = 'Delete quest';
    document.getElementById('delete-modal-msg').textContent   = `Delete quest #${id}? All associated data will remain in the attempts file.`;
    document.getElementById('delete-modal-confirm').onclick   = _deleteCallback;
    document.getElementById('delete-modal-overlay').classList.add('open');
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── ADMIN — RESULTS tab ─────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  async function qsLoadResults() {
    const wrap = document.getElementById('results-table-wrap');
    wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
    try {
      const res    = await apiFetch('quests.php?action=get-all-attempts');
      if (!res.ok) throw new Error('Failed to load results');
      const items  = await res.json();
      wrap.innerHTML = '';
      if (!items.length) {
        wrap.innerHTML = '<p class="qs-empty">No completed attempts yet.</p>';
        return;
      }
      const table = document.createElement('table');
      table.className = 'qs-table';
      table.innerHTML = `<thead><tr>
        <th>User</th><th>Quest</th><th>Date</th><th style="width:90px">Score / 10</th>
      </tr></thead>`;
      const tbody = document.createElement('tbody');
      for (const a of items) {
        const sc  = a.score;
        const tr  = document.createElement('tr');
        tr.innerHTML = `
          <td><strong>${esc(a.username)}</strong></td>
          <td>${esc(a.quest_name)}</td>
          <td style="color:#888;font-size:.82rem">${fmtDate(a.submitted_at)}</td>
          <td style="text-align:center"><span class="${scoreClass(sc)}">${fmtScore(sc)}</span></td>`;
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      wrap.appendChild(table);
    } catch (err) {
      wrap.innerHTML = `<p style="padding:1rem;color:#c62828">${esc(err.message)}</p>`;
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
    // Open quests
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
              <div class="qs-card-name">${esc(q.name)}</div>
              <div class="qs-card-meta">
                <span>📅 ${esc(q.date || '—')}</span>
                <span>❓ ${q.total_q} questions</span>
              </div>
              <button class="btn btn-primary" onclick="qsStartQuest(${q.id})">Start quest</button>`;
            grid.appendChild(card);
          }
          wrap.appendChild(grid);
        }
      } catch (err) {
        wrap.innerHTML = `<p style="color:#c62828;padding:.5rem 0">${esc(err.message)}</p>`;
      }
    }
    // My attempts
    {
      const wrap = document.getElementById('my-attempts-wrap');
      wrap.innerHTML = '<div class="qs-loading"><div class="qs-spinner"></div> Loading…</div>';
      try {
        const res     = await apiFetch('quests.php?action=get-my-attempts');
        if (!res.ok) throw new Error('Failed to load your attempts');
        const items   = await res.json();
        wrap.innerHTML = '';
        if (!items.length) {
          wrap.innerHTML = '<p class="qs-empty">You haven\'t completed any quests yet.</p>';
        } else {
          for (const a of items) {
            const div = document.createElement('div');
            div.className = 'qs-attempt-item';
            div.innerHTML = `
              <div class="qs-attempt-info">
                <div class="qs-attempt-name">${esc(a.quest_name)}</div>
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
        wrap.innerHTML = `<p style="color:#c62828;padding:.5rem 0">${esc(err.message)}</p>`;
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
    if (!res.ok) { qsToast(data.error || 'Failed to start quest', 'error'); return; }

    _wizardData    = data; // {attempt_id, quest_name, wrong, questions}
    _wizardStep    = 0;
    _wizardAnswers = {};

    qsUserShowView('wizard');
    qsRenderWizardStep();
  }

  function qsRenderWizardStep() {
    const wrap     = document.getElementById('user-wizard');
    const questions = _wizardData.questions;
    const total    = questions.length;
    const step     = _wizardStep;
    const q        = questions[step];
    const pct      = Math.round((step / total) * 100);

    wrap.innerHTML = `
      <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
        <button class="btn btn-sm btn-ghost" onclick="qsAbortWizard()">← Back to quests</button>
        <strong style="flex:1;font-size:1rem">${esc(_wizardData.quest_name)}</strong>
      </div>
      <div class="qs-wizard">
        <div class="qs-progress-bar-wrap">
          <div class="qs-progress-bar" style="width:${pct}%"></div>
        </div>
        <div class="qs-question-header">
          <span class="qs-question-num">Question ${step + 1} / ${total}</span>
          <span class="qs-question-type-badge">${esc(q.type.replace(/_/g,' '))}</span>
        </div>
        <div class="qs-question-text">${esc(q.query)}</div>
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
        li.innerHTML = `<input type="radio" name="wz-mc" value="${i}" ${savedAnswer === String(i)?'checked':''} ${readonly?'disabled':''}> ${esc(opt)}`;
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
        // Blank option
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
        li.innerHTML = `<span class="qs-matching-key">${esc(key)}</span><span class="qs-matching-arrow">→</span>`;
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
    if (!res.ok) { qsToast(data.error || 'Submit failed', 'error'); return; }

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
      <p style="color:#555;margin-bottom:1.5rem;font-size:.95rem">Quest: <strong>${esc(_wizardData.quest_name)}</strong></p>
      <button class="btn btn-primary" onclick="qsLoadUserHome()">Back to quests</button>`;
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── USER — Review ──────────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  let _reviewData  = null;
  let _reviewStep  = 0;

  async function qsReviewAttempt(attemptId) {
    const res  = await apiFetch(`quests.php?action=review-attempt&id=${attemptId}`);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { qsToast(data.error || 'Failed to load review', 'error'); return; }

    _reviewData = data;
    _reviewStep = 0;
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

    // Determine correctness for this question
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
        <button class="btn btn-sm btn-ghost" onclick="qsLoadUserHome()">← Back</button>
        <strong style="flex:1;font-size:1rem">${esc(_reviewData.quest_name)} — Review</strong>
        <span class="${scoreClass(_reviewData.score)}" style="font-size:1.1rem;font-weight:700">${fmtScore(_reviewData.score)}/10</span>
      </div>
      <div class="qs-wizard">
        <div class="qs-progress-bar-wrap">
          <div class="qs-progress-bar" style="width:${pct}%"></div>
        </div>
        <div class="qs-question-header">
          <span class="qs-question-num">Question ${step + 1} / ${total}</span>
          <span class="qs-question-type-badge">${esc(q.type.replace(/_/g,' '))}</span>
          <span class="${isCorrect ? 'score-high' : 'score-low'}" style="font-size:.8rem;font-weight:700">
            ${isCorrect ? '✔ Correct' : '✘ Incorrect'}
          </span>
        </div>
        <div class="qs-question-text">${esc(q.query)}</div>
        <div id="review-answer-area"></div>
        <div class="qs-wizard-nav">
          <button class="btn" onclick="qsReviewPrev()" ${step === 0 ? 'disabled' : ''}>← Previous</button>
          <span style="font-size:.8rem;color:#888">${step+1} / ${total}</span>
          ${step < total - 1
            ? `<button class="btn btn-primary" onclick="qsReviewNext()">Next →</button>`
            : `<button class="btn btn-primary" onclick="qsLoadUserHome()">Finish review</button>`
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
        li.innerHTML  = `<input type="radio" disabled ${isUser?'checked':''}> ${esc(opt)}`;
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
      const input = document.createElement('input');
      const isOk  = (q.options || []).map(s=>s.toLowerCase()).includes((userAns||'').toLowerCase().trim());
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
        li.innerHTML = `<span class="qs-matching-key">${esc(key)}</span><span class="qs-matching-arrow">→</span>`;
        li.appendChild(sel);
        ul.appendChild(li);
        if (!isOk) {
          const hint = document.createElement('li');
          hint.style.cssText = 'grid-column:1/-1;margin-bottom:.4rem';
          hint.innerHTML = `<span class="qs-review-correct">✔ Correct: ${esc(key)} → ${esc(correctVal)}</span>`;
          ul.appendChild(hint);
        }
      });
      wrap.appendChild(ul);
    }
  }

  function qsReviewPrev() { if (_reviewStep > 0) { _reviewStep--; qsRenderReviewStep(); } }
  function qsReviewNext() { if (_reviewStep < _reviewData.questions.length - 1) { _reviewStep++; qsRenderReviewStep(); } }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── THEME PANEL ────────────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  async function qsToggleThemePanel() {
    const panel   = document.getElementById('qs-theme-panel');
    const overlay = document.getElementById('qs-theme-overlay');
    const isOpen  = panel.classList.toggle('open');
    overlay.classList.toggle('open', isOpen);
    if (isOpen) {
      const res  = await apiFetch('quests.php?action=get-quests-templates');
      if (!res.ok) return;
      const data = await res.json();
      const sel  = document.getElementById('qs-theme-select');
      sel.innerHTML = '';
      (data.templates || []).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t; opt.textContent = t.replace('.css','');
        const cur = document.getElementById('qs-theme-link').href.split('/').pop();
        opt.selected = t === cur;
        sel.appendChild(opt);
      });
    }
  }

  function qsPreviewTheme(theme) {
    document.getElementById('qs-theme-link').href = 'templates-quests/' + theme;
  }

  document.getElementById('qs-theme-select')?.addEventListener('change', e => qsPreviewTheme(e.target.value));

  async function qsSaveTheme() {
    const theme = document.getElementById('qs-theme-select').value;
    const res   = await apiFetch('quests.php?action=save-quests-theme', {
      method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({theme})
    });
    const data  = await res.json().catch(() => ({}));
    if (res.ok) { qsToggleThemePanel(); qsToast('Theme saved'); }
    else qsToast(data.error || 'Failed to save theme', 'error');
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ── Utility ────────────────────────────────────────────────────────────────
  // ═══════════════════════════════════════════════════════════════════════════
  function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  </script>
</body>
</html>
