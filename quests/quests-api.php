<?php
// ═══════════════════════════════════════════════════════════════════════════
// quests/quests-api.php — Quiz / Quest module for WeKickWiki
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

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/data.php';

// ── Data-file paths ──────────────────────────────────────────────────────────
define('QS_QUERIES_FILE',  __DIR__ . '/queries.json');
define('QS_QUESTS_FILE',   __DIR__ . '/quests.json');
define('QS_ATTEMPTS_FILE', __DIR__ . '/attempts.json');

// ═══════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════
/** Load all questions from disk. */
function qs_load_queries(): array
{
    return data_read(QS_QUERIES_FILE);
}
/** Load all quests from disk. */
function qs_load_quests(): array
{
    return data_read(QS_QUESTS_FILE);
}
/** Load all attempts from disk. */
function qs_load_attempts(): array
{
    return data_read(QS_ATTEMPTS_FILE);
}

/** Persist the questions array. */
function qs_save_queries(array $d): void
{
    data_write(QS_QUERIES_FILE,  $d);
}
/** Persist the quests array. */
function qs_save_quests(array $d): void
{
    data_write(QS_QUESTS_FILE,   $d);
}
/** Persist the attempts array. */
function qs_save_attempts(array $d): void
{
    data_write(QS_ATTEMPTS_FILE, $d);
}

/**
 * Return a lookup map of all enabled guest usernames (username => true).
 * Used to validate the 'allowed' field on quests.
 */
function qs_guest_usernames_lookup(): array
{
    $lookup = [];
    foreach (load_users() as $u) {
        if (!is_array($u)) continue;
        if (($u['role'] ?? 'guest') !== 'guest') continue;
        $username = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($u['username'] ?? ''))));
        if ($username !== '') $lookup[$username] = true;
    }
    return $lookup;
}

/**
 * Remove any non-guest or non-existent usernames from an 'allowed' array.
 * Returns ['all'] when the input is ['all'] or the filtered list is empty.
 *
 * @param string[] $allowed  Raw allowed list from the client
 * @return string[]          Validated allowed list
 */
function qs_filter_allowed_to_guests(array $allowed): array
{
    if (in_array('all', $allowed, true)) return ['all'];

    $guest_lookup = qs_guest_usernames_lookup();
    $filtered = [];
    foreach ($allowed as $u) {
        if (isset($guest_lookup[$u])) $filtered[] = $u;
    }
    $filtered = array_values(array_unique($filtered));
    return empty($filtered) ? ['all'] : $filtered;
}

/**
 * Strip HTML tags and decode entities from Moodle XML inner text.
 * Converts block-level tags to newlines before stripping.
 *
 * @param mixed $raw  Raw XML text content (may be a SimpleXMLElement)
 * @return string     Cleaned plain text
 */
function qs_moodle_inner_text($raw): string
{
    $text = trim((string)$raw);
    if ($text === '') return '';

    $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text);
    $text = preg_replace('/<\/\s*(p|div|li|h[1-6])\s*>/i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{2,}/u', "\n", $text);
    return trim($text);
}

/**
 * Derive label array from a Moodle category path string.
 * Strips the leading '$course$/top/' segment and splits on '/'.
 *
 * @param string $path  Raw category path from the XML
 * @return string[]     Cleaned label array
 */
function qs_moodle_labels_from_category(string $path): array
{
    $path = trim($path);
    if ($path === '') return [];
    $path = preg_replace('#^\$course\$/top/?#i', '', $path);
    $parts = array_filter(array_map('trim', explode('/', (string)$path)), fn($p) => $p !== '');
    return array_values(array_unique($parts));
}

/**
 * Parse a single Moodle <question> XML node into an internal query array.
 * Supports multichoice, truefalse, shortanswer, and matching types.
 * Returns null for unsupported types, multi-answer multiple-choice questions,
 * or questions with insufficient data.
 *
 * @param SimpleXMLElement $qNode  The <question> XML element
 * @param string[]         $labels Labels inherited from the last <category> node
 * @return array|null              Internal query array or null to skip
 */
function qs_moodle_parse_question(SimpleXMLElement $qNode, array $labels): ?array
{
    $type = strtolower(trim((string)($qNode['type'] ?? '')));
    $query = qs_moodle_inner_text($qNode->questiontext->text ?? '');
    if ($query === '') $query = qs_moodle_inner_text($qNode->name->text ?? '');
    if ($query === '') return null;

    if ($type === 'multichoice') {
        if (strtolower(trim((string)($qNode->single ?? 'true'))) === 'false') {
            return null; // This app only supports one correct option.
        }
        $options = [];
        $bestIdx = 0;
        $bestFraction = -INF;
        foreach ($qNode->answer as $ansNode) {
            $opt = qs_moodle_inner_text($ansNode->text ?? '');
            if ($opt === '') continue;
            $fraction = (float)($ansNode['fraction'] ?? 0);
            $idx = count($options);
            $options[] = $opt;
            if ($fraction > $bestFraction) {
                $bestFraction = $fraction;
                $bestIdx = $idx;
            }
        }
        if (empty($options)) return null;
        return [
            'type' => 'multiple_choice',
            'query' => $query,
            'labels' => $labels,
            'options' => $options,
            'answer' => (string)$bestIdx,
        ];
    }

    if ($type === 'truefalse') {
        $answer = null;
        foreach ($qNode->answer as $ansNode) {
            $fraction = (float)($ansNode['fraction'] ?? 0);
            if ($fraction <= 0) continue;
            $txt = strtolower(qs_moodle_inner_text($ansNode->text ?? ''));
            if (in_array($txt, ['true', 'verdadero', 'yes', 'si', 'sí', '1'], true)) {
                $answer = '1';
                break;
            }
            if (in_array($txt, ['false', 'falso', 'no', '0'], true)) {
                $answer = '0';
                break;
            }
        }
        if ($answer === null) {
            foreach ($qNode->answer as $i => $ansNode) {
                $fraction = (float)($ansNode['fraction'] ?? 0);
                if ($fraction > 0) {
                    $answer = ((int)$i === 0) ? '1' : '0';
                    break;
                }
            }
        }
        if ($answer === null) return null;
        return [
            'type' => 'binary',
            'query' => $query,
            'labels' => $labels,
            'answer' => $answer,
        ];
    }

    if ($type === 'shortanswer') {
        $options = [];
        foreach ($qNode->answer as $ansNode) {
            $fraction = (float)($ansNode['fraction'] ?? 0);
            if ($fraction <= 0) continue;
            $opt = qs_moodle_inner_text($ansNode->text ?? '');
            if ($opt === '' || strpos($opt, '*') !== false) continue;
            $options[] = $opt;
        }
        $options = array_values(array_unique($options));
        if (empty($options)) return null;
        return [
            'type' => 'gap_filling',
            'query' => $query,
            'labels' => $labels,
            'options' => $options,
        ];
    }

    if ($type === 'matching') {
        $pairs = [];
        foreach ($qNode->subquestion as $subNode) {
            $left = qs_moodle_inner_text($subNode->text ?? '');
            $right = qs_moodle_inner_text($subNode->answer->text ?? '');
            if ($left === '' || $right === '') continue;
            $pairs[$left] = $right;
        }
        if (empty($pairs)) return null;
        return [
            'type' => 'matching',
            'query' => $query,
            'labels' => $labels,
            'options' => $pairs,
        ];
    }

    return null;
}

/**
 * Parse a Moodle XML string into an array of internal query objects.
 * Category nodes update the running label list; unsupported question types are skipped.
 *
 * @param string $xmlRaw  Raw Moodle XML document
 * @return array          ['items' => [...], 'stats' => [...]] 
 * @throws RuntimeException  When SimpleXML is unavailable or XML is malformed
 */
function qs_parse_moodle_xml_to_queries(string $xmlRaw): array
{
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('SimpleXML extension is not enabled on this server');
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if (!$xml) {
        $errs = libxml_get_errors();
        $msg = !empty($errs) ? trim($errs[0]->message) : 'Malformed XML';
        libxml_clear_errors();
        throw new RuntimeException($msg);
    }
    libxml_clear_errors();

    $items = [];
    $labels = [];
    $stats = [
        'total' => 0,
        'categories' => 0,
        'imported' => 0,
        'skipped' => 0,
        'by_type' => ['multiple_choice' => 0, 'binary' => 0, 'gap_filling' => 0, 'matching' => 0],
    ];

    foreach (($xml->question ?? []) as $qNode) {
        $stats['total']++;
        $srcType = strtolower(trim((string)($qNode['type'] ?? '')));

        if ($srcType === 'category') {
            $stats['categories']++;
            $labels = qs_moodle_labels_from_category(qs_moodle_inner_text($qNode->category->text ?? ''));
            continue;
        }

        $parsed = qs_moodle_parse_question($qNode, $labels);
        if (!$parsed) continue;

        $items[] = $parsed;
        $stats['imported']++;
        $stats['by_type'][$parsed['type']] = ($stats['by_type'][$parsed['type']] ?? 0) + 1;
    }

    $stats['skipped'] = max(0, $stats['total'] - $stats['categories'] - $stats['imported']);
    return ['items' => $items, 'stats' => $stats];
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
        if (!$q) {
            $skipped++;
            continue;
        }
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
                if (($user_pairs[$k] ?? '') !== $v) {
                    $is_correct = false;
                    break;
                }
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
/**
 * Sanitise a raw client-supplied question payload into a safe internal array.
 * Validates type, truncates strings, and normalises answer fields per type.
 *
 * @param array $b  Raw decoded JSON body
 * @return array    Sanitised query array (without id)
 */
function qs_sanitize_query(array $b): array
{
    $type  = in_array($b['type'] ?? '', ['multiple_choice', 'binary', 'gap_filling', 'matching'], true) ? $b['type'] : 'multiple_choice';
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
/**
 * Sanitise a raw client-supplied quest payload into a safe internal array.
 * Validates name, date, status, wrong penalty, label groups, and allowed list.
 * The allowed list is cross-checked against actual guest accounts.
 *
 * @param array $b  Raw decoded JSON body
 * @return array    Sanitised quest array (without id)
 */
function qs_sanitize_quest(array $b): array
{
    $name   = trim(substr($b['name'] ?? '', 0, 256));
    $date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['date'] ?? '') ? $b['date'] : date('Y-m-d');
    $status = in_array($b['status'] ?? '', ['open', 'closed'], true) ? $b['status'] : 'closed';
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
    // allowed: ['all'] means any authenticated user; otherwise a list of specific usernames
    $allowed_raw = (array)($b['allowed'] ?? ['all']);
    if (in_array('all', $allowed_raw, true)) {
        $allowed = ['all'];
    } else {
        $allowed = [];
        foreach ($allowed_raw as $u) {
            $u = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$u)));
            if ($u !== '') $allowed[] = $u;
        }
        $allowed = array_values(array_unique($allowed));
        if (empty($allowed)) $allowed = ['all'];
    }
    $allowed = qs_filter_allowed_to_guests($allowed);
    return ['name' => $name, 'date' => $date, 'status' => $status, 'revisable' => $revisable, 'wrong' => $wrong, 'allowed' => $allowed, 'queries' => $groups];
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
                    if (($user_pairs[$k] ?? '') !== $v) {
                        $ok = false;
                        break;
                    }
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
        foreach ($queries as $i => $q) {
            if ((int)($q['id'] ?? 0) === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx === -1) json_out(404, ['error' => 'Query not found']);
        $clean['id'] = $id;
        $queries[$idx] = $clean;
    } else {
        // Insert
        $clean['id'] = data_next_id($queries);
        $queries[] = $clean;
    }
    qs_save_queries($queries);
    json_out(200, ['ok' => true, 'id' => $clean['id']]);
}

// POST ?action=import-moodle-xml  — multipart/form-data with file field "file"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'import-moodle-xml') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $f = $_FILES['file'] ?? null;
    if (!is_array($f)) json_out(400, ['error' => 'XML file is required']);

    $uploadErr = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadErr !== UPLOAD_ERR_OK) {
        json_out(400, ['error' => 'Upload failed (code ' . $uploadErr . ')']);
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) json_out(400, ['error' => 'Invalid uploaded file']);

    $xmlRaw = file_get_contents($tmp);
    if (!is_string($xmlRaw) || trim($xmlRaw) === '') json_out(400, ['error' => 'Uploaded file is empty']);

    try {
        $parsed = qs_parse_moodle_xml_to_queries($xmlRaw);
    } catch (Throwable $e) {
        json_out(400, ['error' => 'Invalid Moodle XML: ' . $e->getMessage()]);
    }

    $items = (array)($parsed['items'] ?? []);
    if (empty($items)) json_out(400, ['error' => 'No compatible questions found in XML']);

    $queries = qs_load_queries();
    $nextId = data_next_id($queries);
    $added = 0;

    foreach ($items as $item) {
        $clean = qs_sanitize_query((array)$item);
        if ($clean['query'] === '') continue;
        $clean['id'] = $nextId++;
        $queries[] = $clean;
        $added++;
    }

    if ($added === 0) json_out(400, ['error' => 'No valid questions could be imported']);

    qs_save_queries($queries);
    $stats = (array)($parsed['stats'] ?? []);
    $skipped = max(0, ((int)($stats['total'] ?? 0)) - ((int)($stats['categories'] ?? 0)) - $added);
    json_out(200, [
        'ok' => true,
        'imported' => $added,
        'total_xml_questions' => (int)($stats['total'] ?? 0),
        'categories' => (int)($stats['categories'] ?? 0),
        'skipped' => $skipped,
        'by_type' => $stats['by_type'] ?? [],
    ]);
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

    // Sort by quest date descending (newest first), then by id descending.
    usort($result, function ($a, $b) {
        $da = (string)($a['date'] ?? '');
        $db = (string)($b['date'] ?? '');
        $cmp = strcmp($db, $da);
        if ($cmp !== 0) return $cmp;
        return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
    });

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

        // Quests with attempts are immutable.
        $attempts = qs_load_attempts();
        foreach ($attempts as $a) {
            if ((int)($a['quest_id'] ?? 0) === $id) {
                json_out(409, ['error' => 'Quest has attempts and cannot be changed']);
            }
        }

        $idx = -1;
        foreach ($quests as $i => $q) {
            if ((int)($q['id'] ?? 0) === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx === -1) json_out(404, ['error' => 'Quest not found']);
        $clean['id'] = $id;
        $quests[$idx] = $clean;
    } else {
        $clean['id'] = data_next_id($quests);
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
        if ((int)($a['id'] ?? 0) === $id) {
            $found = true;
            break;
        }
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
    if (
        !is_string($theme) ||
        !preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme) ||
        !is_file(__DIR__ . '/templates-quests/' . $theme)
    ) {
        json_out(400, ['error' => 'Invalid theme']);
    }
    $raw = data_read(SETTINGS_FILE);
    $raw['questsTheme'] = $theme;
    data_write(SETTINGS_FILE, $raw);
    json_out(200, ['ok' => true]);
}

// GET ?action=get-quest-users  — returns guest users (for allowed-field UI)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-quest-users') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    $users = load_users();
    $list  = [];
    foreach ($users as $u) {
        if (!is_array($u)) continue;
        if (($u['role'] ?? 'guest') !== 'guest') continue;
        $list[] = [
            'username' => $u['username'] ?? '',
            'name'     => $u['name']     ?? '',
            'role'     => $u['role']     ?? 'guest',
        ];
    }
    json_out(200, $list);
}

// POST ?action=save-quest-allowed  — update only the allowed field (works even with attempts)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-quest-allowed') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_out(400, ['error' => 'Invalid JSON']);
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(400, ['error' => 'id required']);

    $allowed_raw = (array)($body['allowed'] ?? ['all']);
    if (in_array('all', $allowed_raw, true)) {
        $allowed = ['all'];
    } else {
        $allowed = [];
        foreach ($allowed_raw as $u) {
            $u = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$u)));
            if ($u !== '') $allowed[] = $u;
        }
        $allowed = array_values(array_unique($allowed));
        if (empty($allowed)) $allowed = ['all'];
    }
    $allowed = qs_filter_allowed_to_guests($allowed);

    $quests = qs_load_quests();
    $idx = -1;
    foreach ($quests as $i => $q) {
        if ((int)($q['id'] ?? 0) === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx === -1) json_out(404, ['error' => 'Quest not found']);

    // No se puede retirar el permiso a usuarios que ya han completado el cuestionario
    $attempts = qs_load_attempts();
    $guest_lookup = qs_guest_usernames_lookup();
    $completed_users = [];
    foreach ($attempts as $a) {
        if ((int)($a['quest_id'] ?? 0) === $id && ($a['status'] ?? '') === 'completed') {
            $u = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($a['username'] ?? ($a['usename'] ?? '')))));
            if (!isset($guest_lookup[$u])) continue;
            if ($u !== '') $completed_users[$u] = true;
        }
    }
    // Si algún usuario con intento completado no está en la nueva lista allowed (y no es 'all'), error
    if (!in_array('all', $allowed, true)) {
        $missing = [];
        foreach (array_keys($completed_users) as $u) {
            if (!in_array($u, $allowed, true)) $missing[] = $u;
        }
        if ($missing) {
            json_out(409, ['error' => 'No se puede retirar el permiso a usuarios que ya han completado el cuestionario: ' . implode(', ', $missing)]);
        }
    }

    $quests[$idx]['allowed'] = $allowed;
    qs_save_quests($quests);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// ── USER ENDPOINTS (admin + guest) ──────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════

// GET ?action=get-open-quests  — quests open and not yet completed by this user
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-open-quests') {
    $claims   = require_auth();
    if (($claims['role'] ?? '') === 'admin') json_out(403, ['error' => 'Admin users cannot take quests']);
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

    // Index pending attempts by quest_id for this user
    $pending_by_quest = [];
    foreach ($attempts as $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        if ($u === $username && ($a['status'] ?? '') === 'pending') {
            $pending_by_quest[(int)($a['quest_id'] ?? 0)] = (int)($a['id'] ?? 0);
        }
    }

    $result = [];
    foreach ($quests as $q) {
        if (($q['status'] ?? '') !== 'open') continue;
        $qid = (int)($q['id'] ?? 0);
        if (in_array($qid, $done, true)) continue;
        // Check authorization
        $allowed = (array)($q['allowed'] ?? ['all']);
        if (!in_array('all', $allowed, true) && !in_array($username, $allowed, true)) continue;
        // Estimate total questions
        $total = 0;
        foreach ($q['queries'] as $g) $total += (int)($g['queries'] ?? 0);
        $result[] = [
            'id'                => $q['id'],
            'name'              => $q['name'],
            'date'              => $q['date'],
            'total_q'           => $total,
            'revisable'         => $q['revisable'] ?? false,
            'pending_attempt_id' => $pending_by_quest[$qid] ?? null,
        ];
    }
    json_out(200, $result);
}

// POST ?action=start-quest  — body: {quest_id}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'start-quest') {
    $claims   = require_auth();
    if (($claims['role'] ?? '') === 'admin') json_out(403, ['error' => 'Admin users cannot take quests']);
    $username = $claims['sub'] ?? '';

    $body     = json_decode(file_get_contents('php://input'), true);
    $quest_id = (int)($body['quest_id'] ?? 0);
    if (!$quest_id) json_out(400, ['error' => 'quest_id required']);

    // Load quest
    $quests = qs_load_quests();
    $quest  = null;
    foreach ($quests as $q) {
        if ((int)($q['id'] ?? 0) === $quest_id) {
            $quest = $q;
            break;
        }
    }
    if (!$quest) json_out(404, ['error' => 'Quest not found']);
    if (($quest['status'] ?? '') !== 'open') json_out(403, ['error' => 'Quest is not open']);

    // Check authorization
    $allowed = (array)($quest['allowed'] ?? ['all']);
    if (!in_array('all', $allowed, true) && !in_array($username, $allowed, true)) {
        json_out(403, ['error' => 'You are not authorized to take this quest']);
    }

    // Check user hasn't already completed it
    $attempts = qs_load_attempts();
    foreach ($attempts as $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        if ($u === $username && (int)($a['quest_id'] ?? 0) === $quest_id && ($a['status'] ?? '') === 'completed') {
            json_out(409, ['error' => 'You have already completed this quest']);
        }
    }

    // Reuse an existing pending attempt for this user+quest (allows draft restoration after reload)
    $existing_attempt = null;
    foreach ($attempts as $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        if ($u === $username && (int)($a['quest_id'] ?? 0) === $quest_id && ($a['status'] ?? '') === 'pending') {
            $existing_attempt = $a;
            break;
        }
    }

    $all_queries = qs_load_queries();

    if ($existing_attempt) {
        // Resume the existing pending attempt with the same question set
        $attempt_id   = (int)$existing_attempt['id'];
        $question_ids = $existing_attempt['question_ids'] ?? [];
    } else {
        // Select questions and create a new pending attempt
        $question_ids = qs_select_questions($quest, $all_queries);
        if (empty($question_ids)) json_out(500, ['error' => 'No matching questions found for this quest']);

        $attempt_id = data_next_id($attempts);
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
    }

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
    if (($claims['role'] ?? '') === 'admin') json_out(403, ['error' => 'Admin users cannot take quests']);
    $username  = $claims['sub'] ?? '';

    $body      = json_decode(file_get_contents('php://input'), true);
    $attempt_id = (int)($body['attempt_id'] ?? 0);
    $answers    = (array)($body['answers'] ?? []);
    if (!$attempt_id) json_out(400, ['error' => 'attempt_id required']);

    $attempts = qs_load_attempts();
    $idx      = -1;
    foreach ($attempts as $i => $a) {
        $u = $a['username'] ?? ($a['usename'] ?? '');
        if ((int)($a['id'] ?? 0) === $attempt_id && $u === $username) {
            $idx = $i;
            break;
        }
    }
    if ($idx === -1) json_out(404, ['error' => 'Attempt not found']);
    if (($attempts[$idx]['status'] ?? '') === 'completed') json_out(409, ['error' => 'Already submitted']);

    $quest_id    = (int)($attempts[$idx]['quest_id'] ?? 0);
    $question_ids = (array)($attempts[$idx]['question_ids'] ?? []);

    $quests = qs_load_quests();
    $quest  = null;
    foreach ($quests as $q) {
        if ((int)($q['id'] ?? 0) === $quest_id) {
            $quest = $q;
            break;
        }
    }
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
    if (($claims['role'] ?? '') === 'admin') json_out(403, ['error' => 'Admin users cannot take quests']);
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
            'submitted_at' => $a['submitted_at'] ?? null,
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
            if ($username === $u || ($claims['role'] ?? '') === 'admin') {
                $attempt = $a;
                break;
            }
        }
    }
    if (!$attempt) json_out(404, ['error' => 'Attempt not found']);
    if (($attempt['status'] ?? '') !== 'completed') json_out(409, ['error' => 'Attempt not completed']);

    $quests = qs_load_quests();
    $quest  = null;
    foreach ($quests as $q) {
        if ((int)($q['id'] ?? 0) === (int)($attempt['quest_id'] ?? 0)) {
            $quest = $q;
            break;
        }
    }
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
