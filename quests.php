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
require_once __DIR__ . '/lib/data.php';
require_once __DIR__ . '/lib/users-api.php';

// ── Data-file paths ──────────────────────────────────────────────────────────
define('QS_QUERIES_FILE',  __DIR__ . '/queries.json');
define('QS_QUESTS_FILE',   __DIR__ . '/quests.json');
define('QS_ATTEMPTS_FILE', __DIR__ . '/attempts.json');

// ═══════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════
function qs_load_queries(): array { return data_read(QS_QUERIES_FILE); }
function qs_load_quests():  array { return data_read(QS_QUESTS_FILE);  }
function qs_load_attempts():array { return data_read(QS_ATTEMPTS_FILE); }

function qs_save_queries(array $d): void  { data_write(QS_QUERIES_FILE,  $d); }
function qs_save_quests(array $d):  void  { data_write(QS_QUESTS_FILE,   $d); }
function qs_save_attempts(array $d): void { data_write(QS_ATTEMPTS_FILE, $d); }

/** Returns max(id)+1 across an array of objects with an 'id' field, or 1 if empty. */
function qs_next_id(array $arr): int
{
    return data_next_id($arr);
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

    $queries  = qs_load_queries();
    $attempts = qs_load_attempts();

    // Build query lookup
    $q_map = [];
    foreach ($queries as $q) $q_map[(int)$q['id']] = $q;

    // Compute per-question stats from completed attempts
    $appeared          = [];  // query_id => count
    $stat_correct      = [];  // query_id => count
    $stat_wrong        = [];  // query_id => count
    $total_appearances = 0;

    foreach ($attempts as $a) {
        if (($a['status'] ?? '') !== 'completed') continue;
        $qids    = (array)($a['question_ids'] ?? []);
        $ans_map = [];
        foreach ((array)($a['queries'] ?? []) as $ans) {
            $ans_map[(int)($ans['id'] ?? 0)] = $ans['answer'] ?? null;
        }
        $total_appearances += count($qids);
        foreach ($qids as $qid) {
            $qid = (int)$qid;
            $appeared[$qid] = ($appeared[$qid] ?? 0) + 1;
            $dq       = $q_map[$qid] ?? null;
            $user_ans = $ans_map[$qid] ?? null;
            if (!$dq || $user_ans === null || $user_ans === '' || $user_ans === []) continue;
            $type = $dq['type'] ?? 'multiple_choice';
            if ($type === 'multiple_choice' || $type === 'binary') {
                $ok = ((string)$user_ans === (string)($dq['answer'] ?? ''));
            } elseif ($type === 'gap_filling') {
                $valid = array_map('strtolower', (array)($dq['options'] ?? []));
                $ok = in_array(strtolower(trim((string)$user_ans)), $valid, true);
            } elseif ($type === 'matching') {
                $ok = true;
                $user_pairs = (array)$user_ans;
                foreach ((array)($dq['options'] ?? []) as $k => $v) {
                    if (($user_pairs[$k] ?? '') !== $v) { $ok = false; break; }
                }
            } else {
                $ok = false;
            }
            if ($ok) $stat_correct[$qid] = ($stat_correct[$qid] ?? 0) + 1;
            else     $stat_wrong[$qid]   = ($stat_wrong[$qid] ?? 0) + 1;
        }
    }

    $result = array_map(function ($q) use ($appeared, $stat_correct, $stat_wrong, $total_appearances) {
        $qid      = (int)$q['id'];
        $app      = $appeared[$qid]     ?? 0;
        $cor      = $stat_correct[$qid] ?? 0;
        $wrg      = $stat_wrong[$qid]   ?? 0;
        $answered = $cor + $wrg;
        $q['stats'] = [
            'appeared'       => $app,
            'correct'        => $cor,
            'wrong'          => $wrg,
            'success_pct'    => $answered > 0 ? round($cor / $answered * 100, 1) : null,
            'appearance_pct' => $total_appearances > 0 ? round($app / $total_appearances * 100, 1) : null,
        ];
        return $q;
    }, $queries);

    json_out(200, $result);
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

    // Count attempts and accumulate scores per quest
    $counts = [];
    $scores = [];
    foreach ($attempts as $a) {
        $qid = (int)($a['quest_id'] ?? 0);
        if (($a['status'] ?? '') !== 'completed') continue;
        $counts[$qid] = ($counts[$qid] ?? 0) + 1;
        if (($a['score'] ?? null) !== null) $scores[$qid][] = (float)$a['score'];
    }
    $result = array_map(function ($q) use ($counts, $scores) {
        $qid = (int)($q['id'] ?? 0);
        $q['attempt_count'] = $counts[$qid] ?? 0;
        $arr = $scores[$qid] ?? [];
        $q['avg_score'] = count($arr) ? round(array_sum($arr) / count($arr), 2) : null;
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

// POST ?action=delete-attempt  — body: {id}  (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete-attempt') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($body['id'] ?? 0);
    if (!$id) json_out(400, ['error' => 'id required']);

    $attempts = qs_load_attempts();
    $found = false;
    foreach ($attempts as $a) {
        if ((int)($a['id'] ?? 0) === $id) { $found = true; break; }
    }
    if (!$found) json_out(404, ['error' => 'Attempt not found']);

    $attempts = array_values(array_filter($attempts, fn($a) => (int)($a['id'] ?? 0) !== $id));
    qs_save_attempts($attempts);
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
        'username'     => $attempt['username'] ?? ($attempt['usename'] ?? ''),
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
    if (!empty($_raw['theme']) &&
        preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['theme']) &&
        is_file(__DIR__ . '/templates/' . $_raw['theme'])) {
        $qs_theme = $_raw['theme'];
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
  <link id="qs-theme-link" rel="stylesheet" href="templates/<?= htmlspecialchars($qs_theme, ENT_QUOTES) ?>">
</head>
<body>

  <!-- ── App header ─────────────────────────────────────────────────── -->
  <header id="app-header" style="display:none">
    <a href="quests.php">
      <img src="icon.svg" style="display:inline;width:1.4rem;height:1.4rem;vertical-align:middle;margin-right:.4rem" alt="">
      <?= htmlspecialchars($qs_app_name) ?>
    </a>
    <nav class="app-nav">
      <a href="wiki.php">Wiki</a>
      <a href="marks.php">Marks</a>
      <a href="quests.php" class="active">Quests</a>
    </nav>
    <div id="app-header-right">
      <span id="qs-user-badge"></span>
      <button class="btn btn-sm" onclick="window.location.href='index.php'">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign out
      </button>
    </div>
  </header>

  <!-- ── App screen ─────────────────────────────────────────────────── -->
  <div id="quests-screen">

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
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="qsOpenQueryModal()">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add question
          </button>
          <span id="queries-status" class="qs-status" style="display:none"></span>
        </div>
        <!-- Filter bar -->
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap">
          <input id="qf-search" type="search" placeholder="Search question text…"
            style="flex:1;min-width:180px;max-width:320px;padding:.35rem .6rem;border:1px solid #ccc;border-radius:4px;font-size:.88rem"
            oninput="qsApplyQueryFilters()">
          <select id="qf-type" style="padding:.35rem .6rem;border:1px solid #ccc;border-radius:4px;font-size:.88rem"
            onchange="qsApplyQueryFilters()">
            <option value="">All types</option>
            <option value="multiple_choice">Multiple choice</option>
            <option value="binary">Binary</option>
            <option value="gap_filling">Gap filling</option>
            <option value="matching">Matching</option>
          </select>
          <input id="qf-label" type="search" list="qf-label-list" placeholder="Filter by label…"
            style="min-width:150px;padding:.35rem .6rem;border:1px solid #ccc;border-radius:4px;font-size:.88rem"
            oninput="qsApplyQueryFilters()">
          <datalist id="qf-label-list"></datalist>
          <span id="qf-count" style="font-size:.82rem;color:#888;white-space:nowrap"></span>
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
  </div><!-- #quests-screen -->

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
  <div id="app-toast"></div>

  <script src="lib/auth-client.js?v=<?= filemtime(__DIR__ . '/lib/auth-client.js') ?>"></script>
  <script src="lib/app-client.js?v=<?= filemtime(__DIR__ . '/lib/app-client.js') ?>"></script>
  <script src="quests.js?v=<?= filemtime(__DIR__ . '/quests.js') ?>"></script>
</body>
</html>
