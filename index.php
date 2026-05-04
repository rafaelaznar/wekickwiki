<?php
// ═══════════════════════════════════════════════════════════════════════════
// JWT helpers
// ═══════════════════════════════════════════════════════════════════════════
function b64url(string $d): string
{
  return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}

function jwt_make(array $payload): string
{
  $h = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
  $p = b64url(json_encode($payload));
  $s = b64url(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
  return "$h.$p.$s";
}

function jwt_verify(?string $token): ?array
{
  if (!$token) return null;
  $parts = explode('.', $token);
  if (count($parts) !== 3) return null;
  [$h, $p, $s] = $parts;
  if (!hash_equals(b64url(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), $s)) return null;
  $data = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
  if (!is_array($data) || ($data['exp'] ?? 0) < time()) return null;
  return $data;
}

function bearer_token(): ?string
{
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$auth && function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
  }
  return preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;
}

function require_auth(): array
{
  $claims = jwt_verify(bearer_token());
  if (!$claims) {
    json_out(401, ['error' => 'Unauthorized']);
  }
  return $claims;
}

function json_out(int $code, array $data): never
{
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// Config
// Passwords are SHA-256'd client-side, then HMAC'd server-side before compare.
// Users are stored in users.json (blocked from HTTP via .htaccess).
// ═══════════════════════════════════════════════════════════════════════════
define('JWT_SECRET',   'wkw_2026_S3cur3!K3y#R4nd0m$Phr4s3_xQz7');
define('TOKEN_TTL',    3600); // 1 hour
define('USERS_FILE',   __DIR__ . '/users.json');

// Default users (used on first run to seed users.json if it doesn't exist)
// guestLoginEnabled is stored as a top-level key alongside the user entries.
$USERS_DEFAULT = [
  'admin' => ['hash' => 'f102261abcb0b4fe003994b9e9f2f2efdd64a80b52ba930d401b5a2a694a0e61', 'role' => 'admin'],
  'guest' => ['hash' => '18fb145c0a15beae4d671e61688f90624c42e93872b642c346e4fc87f92fbbf4', 'role' => 'guest'],
  'guestLoginEnabled' => true,
];

function load_users(): array {
  global $USERS_DEFAULT;
  if (!is_file(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode($USERS_DEFAULT, JSON_PRETTY_PRINT), LOCK_EX);
    return $USERS_DEFAULT;
  }
  $data = json_decode(file_get_contents(USERS_FILE), true);
  return (is_array($data) && count($data) >= 2) ? $data : $USERS_DEFAULT;
}

$USERS = load_users();

// ═══════════════════════════════════════════════════════════════════════════
// Base path detection
// ═══════════════════════════════════════════════════════════════════════════
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';

// ═══════════════════════════════════════════════════════════════════════════
// API: Login  POST ?action=login  (no auth required)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'login') {
  $body = json_decode(file_get_contents('php://input'), true) ?? [];
  $user = preg_replace('/[^a-z0-9_]/', '', strtolower($body['user'] ?? ''));
  $hash = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $body['hash'] ?? ''));
  if (strlen($hash) !== 64) json_out(401, ['error' => 'Invalid credentials']);
  $serverHash = hash_hmac('sha256', $hash, JWT_SECRET);
  if (isset($USERS[$user]) && is_array($USERS[$user]) && hash_equals($USERS[$user]['hash'], $serverHash)) {
    if (($USERS[$user]['role'] ?? '') === 'guest' && !($USERS['guestLoginEnabled'] ?? true)) {
      json_out(401, ['error' => 'Guest login is currently disabled']);
    }
    json_out(200, [
      'token' => jwt_make(['sub' => $user, 'role' => $USERS[$user]['role'], 'iat' => time(), 'exp' => time() + TOKEN_TTL]),
      'role'  => $USERS[$user]['role'],
    ]);
  }
  json_out(401, ['error' => 'Invalid credentials']);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Read page  GET ?page=...  (any authenticated user)
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['page'])) {
  require_auth();
  $p    = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $_GET['page']);
  $base = __DIR__ . '/pages/' . trim($p, '/');
  $f    = $base . '.md';
  header('Content-Type: text/plain; charset=utf-8');
  if (is_file($f)) {
    readfile($f);
  } else {
    http_response_code(404);
    echo "# 404\nPage not found.";
  }
  exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// API: List pages  GET ?action=index  (admin only)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'index') {
  require_auth();
  $pagesDir = __DIR__ . '/pages';
  $result   = [];
  $iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'md') {
      $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($pagesDir) + 1));
      // strip .md extension; root index.md → 'index'
      $page = preg_replace('/\.md$/', '', $rel);
      if ($page === '') $page = 'index';
      $result[] = $page;
    }
  }
  sort($result);
  json_out(200, ['pages' => $result]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Delete page  POST ?action=delete  (admin only)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body = json_decode(file_get_contents('php://input'), true) ?? [];
  $p    = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $body['page'] ?? '');
  $base = __DIR__ . '/pages/' . trim($p, '/');
  $f    = $base . '.md';
  if (is_file($f)) {
    unlink($f);
    json_out(200, ['ok' => true]);
  }
  json_out(404, ['error' => 'Page not found']);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Save page  POST ?action=save  (admin only)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body = json_decode(file_get_contents('php://input'), true) ?? [];
  $p    = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $body['page'] ?? '');
  $base = __DIR__ . '/pages/' . trim($p, '/');
  $f = $base . '.md';
  $dir = dirname($f);
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  ob_start();
  $written = @file_put_contents($f, $body['content'] ?? '');
  ob_end_clean();
  if ($written === false) {
    json_out(500, ['error' =>
      'Could not write page to disk. ' .
      'Check that the web server user has write permission on pages/. ' .
      'Hint: run "chown -R www-data:www-data pages/" inside the container.'
    ]);
  }
  json_out(200, ['ok' => true]);
}
// ═══════════════════════════════════════════════════════════════════════════
// API: Get usernames  GET ?action=get-users  (admin only)
// Returns only usernames, never hashes.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-users') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $users  = load_users();
  $admin  = '';
  $guest  = '';
  foreach ($users as $uname => $udata) {
    if (!is_array($udata)) continue;
    if (($udata['role'] ?? '') === 'admin') $admin = $uname;
    if (($udata['role'] ?? '') === 'guest') $guest = $uname;
  }
  json_out(200, [
    'adminUser'         => $admin,
    'guestUser'         => $guest,
    'guestLoginEnabled' => (bool)($users['guestLoginEnabled'] ?? true),
  ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Save users  POST ?action=save-users  (admin only)
// Body: { adminUser, adminHash (sha256hex|null), guestUser, guestHash (sha256hex|null) }
// null hash means "keep existing password".
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-users') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

  $body      = json_decode(file_get_contents('php://input'), true) ?? [];
  $adminUser = preg_replace('/[^a-z0-9_]/', '', strtolower($body['adminUser'] ?? ''));
  $guestUser = preg_replace('/[^a-z0-9_]/', '', strtolower($body['guestUser'] ?? ''));

  if (strlen($adminUser) < 2 || strlen($adminUser) > 32) json_out(400, ['error' => 'Admin username must be 2–32 chars (a-z, 0-9, _)']);
  if (strlen($guestUser) < 2 || strlen($guestUser) > 32) json_out(400, ['error' => 'Guest username must be 2–32 chars (a-z, 0-9, _)']);
  if ($adminUser === $guestUser) json_out(400, ['error' => 'Admin and guest usernames must be different']);

  $existing = load_users();

  // Resolve existing hashes for each role
  $existingAdminHash = '';
  $existingGuestHash = '';
  foreach ($existing as $udata) {
    if (!is_array($udata)) continue;
    if (($udata['role'] ?? '') === 'admin') $existingAdminHash = $udata['hash'];
    if (($udata['role'] ?? '') === 'guest') $existingGuestHash = $udata['hash'];
  }

  // Validate and compute new hashes
  $rawAdminHash = $body['adminHash'] ?? null;
  $rawGuestHash = $body['guestHash'] ?? null;

  if ($rawAdminHash !== null) {
    $rawAdminHash = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawAdminHash));
    if (strlen($rawAdminHash) !== 64) json_out(400, ['error' => 'Invalid admin password hash']);
    $newAdminHash = hash_hmac('sha256', $rawAdminHash, JWT_SECRET);
  } else {
    $newAdminHash = $existingAdminHash;
  }

  if ($rawGuestHash !== null) {
    $rawGuestHash = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawGuestHash));
    if (strlen($rawGuestHash) !== 64) json_out(400, ['error' => 'Invalid guest password hash']);
    $newGuestHash = hash_hmac('sha256', $rawGuestHash, JWT_SECRET);
  } else {
    $newGuestHash = $existingGuestHash;
  }

  // Preserve guestLoginEnabled; allow updating it from body
  $guestLoginEnabled = isset($body['guestLoginEnabled'])
    ? (bool)$body['guestLoginEnabled']
    : (bool)($existing['guestLoginEnabled'] ?? true);

  $newUsers = [
    $adminUser          => ['hash' => $newAdminHash, 'role' => 'admin'],
    $guestUser          => ['hash' => $newGuestHash, 'role' => 'guest'],
    'guestLoginEnabled' => $guestLoginEnabled,
  ];

  $written = file_put_contents(USERS_FILE, json_encode($newUsers, JSON_PRETTY_PRINT), LOCK_EX);
  if ($written === false) json_out(500, ['error' => 'Could not write users file']);

  json_out(200, ['ok' => true, 'adminUser' => $adminUser, 'guestUser' => $guestUser]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Backup  GET ?action=backup  (admin only)
// Streams all pages as a single structured text file for download.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'backup') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

  $pagesDir = __DIR__ . '/pages';
  $files    = [];
  if (is_dir($pagesDir)) {
    $iter = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iter as $file) {
      if ($file->isFile() && $file->getExtension() === 'md') {
        $files[] = str_replace('\\', '/', $file->getPathname());
      }
    }
  }
  sort($files);

  $timestamp = gmdate('Y-m-d\TH:i:s\Z');
  $filename  = 'wkw-backup-' . gmdate('Ymd-His') . '.txt';
  $count     = count($files);

  header('Content-Type: text/plain; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-store');

  echo "# WeKickWiki Backup\n";
  echo "# Generated: $timestamp\n";
  echo "# Pages: $count\n";
  echo "# This file can be used to restore the wiki. Do not modify the page markers.\n";

  foreach ($files as $f) {
    $rel  = substr($f, strlen($pagesDir) + 1);
    $page = preg_replace('/\.md$/', '', $rel);
    echo "\n===PAGE=== $page\n";
    $content = file_get_contents($f);
    echo $content;
    // Ensure content ends with a newline so the next marker starts on its own line
    if (substr($content, -1) !== "\n") echo "\n";
  }
  exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// Helper: delete all contents of a directory but keep the directory itself.
// Returns true on success, false if any entry could not be removed.
// Uses @ to suppress PHP warnings; caller checks the return value.
// ═══════════════════════════════════════════════════════════════════════════
function clear_dir_contents(string $dir): bool {
  if (!is_dir($dir)) return true;
  $iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iter as $entry) {
    $ok = $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    if (!$ok) return false;
  }
  return true;
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Restore  POST ?action=restore  (admin only, multipart/form-data)
// Wipes pages/ directory and recreates all pages from the uploaded backup.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'restore') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

  if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
    json_out(400, ['error' => 'No backup file received or upload error']);
  }
  if ($_FILES['backup']['size'] > 10 * 1024 * 1024) {
    json_out(400, ['error' => 'Backup file exceeds 10 MB limit']);
  }

  $raw = file_get_contents($_FILES['backup']['tmp_name']);
  if ($raw === false) json_out(500, ['error' => 'Could not read uploaded file']);

  // Parse backup: split into pages
  $pages   = [];
  $curPage = null;
  $curLines = [];

  foreach (explode("\n", $raw) as $line) {
    if (preg_match('/^===PAGE=== (.+)$/', $line, $m)) {
      // Save previous page block
      if ($curPage !== null) {
        $pages[$curPage] = implode("\n", $curLines);
      }
      // Sanitize path: same rules as all other endpoints + no path traversal
      $p = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', trim($m[1]));
      $p = trim($p, '/');
      if ($p === '' || strpos($p, '..') !== false) {
        json_out(400, ['error' => 'Invalid page path in backup: ' . htmlspecialchars(trim($m[1]))]);
      }
      $curPage  = $p;
      $curLines = [];
    } else {
      if ($curPage !== null) {
        $curLines[] = $line;
      }
    }
  }
  // Save last page block
  if ($curPage !== null) {
    $pages[$curPage] = implode("\n", $curLines);
  }

  if (empty($pages)) {
    json_out(400, ['error' => 'No valid pages found in backup file']);
  }

  // Clear all existing pages (keep the pages/ directory itself so we don't
  // need write permission on its parent directory).
  // Output buffering ensures PHP warnings never corrupt the JSON response.
  $pagesDir = __DIR__ . '/pages';
  if (!is_dir($pagesDir)) @mkdir($pagesDir, 0755, true);
  ob_start();
  $cleared = clear_dir_contents($pagesDir);
  ob_end_clean();
  if (!$cleared) {
    json_out(500, ['error' =>
      'Cannot clear pages/ directory contents. ' .
      'Make sure the web server user has write permission on all files inside pages/. ' .
      'Hint: run "chown -R www-data:www-data pages/" inside the container.'
    ]);
  }

  // Recreate pages from backup
  $written = 0;
  foreach ($pages as $page => $content) {
    $f   = $pagesDir . '/' . $page . '.md';
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Strip the trailing newline added by the parser's line-joining
    $content = rtrim($content, "\n");
    if (@file_put_contents($f, $content) === false) {
      json_out(500, ['error' =>
        'Could not write page: ' . $page . '. ' .
        'Check web server write permissions on pages/.'
      ]);
    }
    $written++;
  }

  json_out(200, ['ok' => true, 'pages' => $written]);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>WeKickWiki</title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="icon.svg">
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    body {
      line-height: 1.6;
      color: #222;
      font: normal 87.5%/1.4 Arial, system-ui, sans-serif;
      /* default font size: 100% => 16px; 93.75% => 15px; 87.5% => 14px; 81.25% => 13px; 75% => 12px */
      -webkit-text-size-adjust: 100%;
      background: #fbfaf9;
    }

    /* ── Login screen ── */
    #login-screen {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: #f4f4f5;
      padding: 1rem
    }

    #login-box {
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 360px;
      box-shadow: 0 4px 24px rgba(0, 0, 0, .07)
    }

    #login-box h2 {
      font-size: 1.3rem;
      margin-bottom: 1.5rem;
      text-align: center
    }

    #login-box label {
      display: block;
      font-size: .85rem;
      font-weight: 600;
      margin-bottom: .9rem
    }

    #login-box input {
      display: block;
      width: 100%;
      margin-top: .25rem;
      padding: .55rem .75rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: 1rem;
      outline: none
    }

    #login-box input:focus {
      border-color: #05c
    }

    #login-box button {
      width: 100%;
      margin-top: 1.2rem;
      padding: .65rem;
      background: #05c;
      color: #fff;
      border: none;
      border-radius: 4px;
      font-size: 1rem;
      cursor: pointer;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: center
    }

    #login-box button:hover {
      background: #004ab3
    }

    #login-error {
      margin-top: .75rem;
      font-size: .85rem;
      color: #c00;
      text-align: center;
      min-height: 1.2rem
    }

    /* ── Wiki screen ── */
    #wiki-screen {
      max-width: 860px;
      margin: 50px auto;
      padding: 1rem 1.5rem 2rem;
      clear: both;
      background: #fff;
      color: inherit;
      border: 1px solid #eee;
      box-shadow: 0 0 .5em #999;
      border-radius: 2px;
      overflow: hidden;
      word-wrap: break-word;
    }

    header {
      border-bottom: 2px solid #222;
      padding-bottom: .5rem;
      margin-bottom: .75rem;
      display: flex;
      align-items: center;
      justify-content: space-between
    }

    header a {
      font-size: 1.4rem;
      font-weight: 700;
      color: #111;
      text-decoration: none
    }

    #header-right {
      display: flex;
      gap: .5rem;
      align-items: center
    }

    #user-badge {
      font-size: .78rem;
      color: #666;
      padding: .2rem .6rem;
      background: #f0f0f0;
      border-radius: 3px
    }

    nav {
      font-size: .85rem;
      color: #666;
      margin-bottom: 1.5rem;
      min-height: 1.2rem
    }

    nav a {
      color: #05c;
      text-decoration: none
    }

    nav a:hover {
      text-decoration: underline
    }

    /* ── headers ── */
    /*
#content h1{font-size:1.9rem;margin:.25rem 0 .8rem}
#content h2{font-size:1.3rem;margin:1.5rem 0 .4rem;padding-bottom:.2rem;border-bottom:1px solid #eee}
#content h3{font-size:1.1rem;margin:1rem 0 .3rem}
*/
    #content h1,
    h2,
    h3,
    h4,
    h5,
    h6 {
      font-weight: bold;
      padding: 0;
      line-height: 1.2;
      clear: left;
      color: #8A0808;
      font-family: Arial, sans-serif;
    }

    #content h1 {
      font-size: 1.9em;
      margin: 0 0 0.444em;
      text-align: center;
      border-style: solid;
      border-width: 2px;
      padding: 0.45em 1em;
      margin: 0.6em 0 1em 0;
      background-color: #8A0808;
      color: #FFF;
    }

    #content h2 {
      font-size: 1.7em;
      margin: 1em 0 0.5em;
      border-bottom-style: double;
      border-bottom-width: 6px;
      border-color: #8A0808;
    }

    #content h3 {
      font-size: 1.6em;
      margin: 0.5em 0;
    }

    #content h4 {
      font-size: 1.25em;
      margin: 0.4em 0;
    }

    #content h5 {
      font-size: 1em;
      margin: 0.3em 0;
    }

    #content h6 {
      font-size: .75em;
      margin: 0.3em 0;
    }

    /* --- */
    #content p {
      margin: .5rem 0
    }

    #content a {
      color: #05c
    }

    #content ul,
    #content ol {
      margin: 0rem 0 0rem 1.5rem
    }

    #content pre {
      background: #f5f5f5;
      padding: .8rem 1rem;
      border-radius: 4px;
      overflow-x: auto;
      margin: .8rem 0
    }

    #content code {
      background: #f0f0f0;
      padding: .1em .3em;
      border-radius: 3px;
      font-size: .9em
    }

    #content pre code {
      background: none;
      padding: 0
    }

    #content blockquote {
      border-left: 3px solid #ccc;
      padding: .1rem .8rem;
      color: #555;
      margin: .8rem 0
    }

    #content table {
      border-collapse: collapse;
      width: 100%;
      margin: .8rem 0
    }

    #content th,
    #content td {
      border: 1px solid #ddd;
      padding: .35rem .7rem;
      text-align: left
    }

    #content th {
      background: #f5f5f5
    }

    #content img {
      max-width: 100%;
      height: auto
    }

    #content hr {
      border: none;
      border-top: 1px solid #ddd;
      margin: 1.2rem 0
    }

    /* Editor */
    #editor {
      display: none;
      flex-direction: column;
      gap: .5rem
    }

    #editor textarea {
      width: 100%;
      height: 70vh;
      font-family: ui-monospace, monospace;
      font-size: .9rem;
      line-height: 1.5;
      padding: .75rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      resize: vertical;
      outline: none
    }

    #editor textarea:focus {
      border-color: #05c
    }

    #editor-bar {
      display: flex;
      gap: .5rem
    }

    .btn {
      padding: .4rem .55rem;
      font-size: .85rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      cursor: pointer;
      background: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center
    }

    .btn:hover {
      background: #f5f5f5
    }

    button svg {
      display: block;
      width: 1rem;
      height: 1rem;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
      overflow: visible
    }

    .btn-primary {
      background: #05c;
      color: #fff;
      border-color: #05c
    }

    .btn-primary:hover {
      background: #004ab3
    }

    .btn-danger {
      color: #c00;
      border-color: #c00
    }

    .btn-danger:hover {
      background: #fff5f5
    }

    #save-status {
      font-size: .8rem;
      color: #666;
      line-height: 2
    }

    /* Index panel */
    #index-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .35);
      z-index: 100
    }

    #index-panel {
      display: none;
      position: fixed;
      top: 0;
      right: 0;
      bottom: 0;
      width: min(340px, 90vw);
      background: #fff;
      box-shadow: -4px 0 20px rgba(0, 0, 0, .15);
      overflow-y: auto;
      padding: 1.25rem 1.5rem;
      z-index: 101
    }

    #index-panel h3 {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 1rem;
      display: flex;
      justify-content: space-between;
      align-items: center
    }

    #index-panel button.close-btn {
      background: none;
      border: none;
      font-size: 1.2rem;
      cursor: pointer;
      color: #666;
      line-height: 1
    }

    #index-tree {
      font-size: .88rem
    }

    #index-tree ul {
      list-style: none;
      padding-left: 1rem;
      margin: 0
    }

    #index-tree>ul {
      padding-left: 0
    }

    #index-tree li {
      margin: .2rem 0
    }

    #index-tree a {
      color: #05c;
      text-decoration: none
    }

    #index-tree a:hover {
      text-decoration: underline
    }

    #index-tree .folder {
      font-weight: 600;
      color: #444;
      display: block;
      margin-top: .5rem
    }

    /* TOC panel */
    #toc-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .35);
      z-index: 100
    }

    #toc-panel {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      width: min(300px, 90vw);
      background: #fff;
      box-shadow: 4px 0 20px rgba(0, 0, 0, .15);
      overflow-y: auto;
      padding: 1.25rem 1.5rem;
      z-index: 101
    }

    #toc-panel h3 {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 1rem;
      display: flex;
      justify-content: space-between;
      align-items: center
    }

    #toc-panel button.close-btn {
      background: none;
      border: none;
      font-size: 1.2rem;
      cursor: pointer;
      color: #666;
      line-height: 1
    }

    #toc-list {
      font-size: .88rem
    }

    #toc-list ul {
      list-style: none;
      padding-left: 0;
      margin: 0
    }

    #toc-list li {
      margin: .2rem 0
    }

    #toc-list a {
      color: #05c;
      text-decoration: none;
      display: block
    }

    #toc-list a:hover {
      text-decoration: underline
    }

    .toc-h1 {
      font-weight: 700;
    }

    .toc-h2 {
      padding-left: 1.5rem
    }

    .toc-h3 {
      padding-left: 2.5rem;
      font-size: .83rem;
      color: #555
    }

    /* Toast notification */
    #toast {
      position: fixed;
      bottom: 1.5rem;
      left: 50%;
      transform: translateX(-50%) translateY(1rem);
      background: #1a7f37;
      color: #fff;
      padding: .6rem 1.2rem;
      border-radius: 6px;
      font-size: .9rem;
      font-weight: 500;
      box-shadow: 0 4px 14px rgba(0, 0, 0, .2);
      opacity: 0;
      pointer-events: none;
      transition: opacity .25s, transform .25s;
      z-index: 200
    }

    #toast.show {
      opacity: 1;
      transform: translateX(-50%) translateY(0)
    }

    #toast.error {
      background: #c62828
    }

    /* ── Inline TOC (floats right alongside content) ── */
    #content {
      position: relative
    }

    #toc-inline {
      float: right;
      width: 200px;
      border: 1px solid #8A0808;
      background: #f7f7f7;
      padding: .55rem .85rem;
      font-size: .83rem;
      line-height: 1.5;
      border-radius: 10px;
      margin: 2px 2px 20px 20px;
    }

    #toc-inline ul {
      list-style: none;
      padding: 0;
      margin: 0
    }

    #toc-inline li {
      margin: .15rem 0
    }

    #toc-inline a {
      color: #05c;
      text-decoration: none
    }

    #toc-inline a:hover {
      text-decoration: underline
    }

    #toc-inline .toc-h1 {
      font-weight: 700
    }

    #toc-inline .toc-h2 {
      display: block;
      padding-left: 1rem
    }

    #toc-inline .toc-h3 {
      display: block;
      padding-left: 2rem;
      font-size: .78rem;
      color: #555
    }

    /* ── Guest mode ── */
    .guest-mode header {
      border-bottom: none;
      padding-bottom: 0;
      margin-bottom: 0;
      min-height: 0;
      position: fixed;
      top: .75rem;
      right: max(1.5rem, calc((100vw - 860px)/2 + 1.5rem));
      width: auto;
      z-index: 50
    }

    .guest-mode #header-title,
    .guest-mode #user-badge {
      display: none
    }

    .guest-mode #nav {
      display: none
    }

    .guest-mode #content {
      margin-top: 2.5rem
    }

    /* ── Users management panel ── */
    #users-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .45);
      z-index: 100
    }

    #users-panel {
      display: none;
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: min(380px, 92vw);
      background: #fff;
      box-shadow: 0 8px 40px rgba(0, 0, 0, .22);
      border-radius: 8px;
      padding: 1.5rem 1.75rem 1.75rem;
      z-index: 101
    }

    #users-panel h3 {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 1.25rem;
      display: flex;
      justify-content: space-between;
      align-items: center
    }

    #users-panel button.close-btn {
      background: none;
      border: none;
      font-size: 1.2rem;
      cursor: pointer;
      color: #666;
      line-height: 1
    }

    #users-form fieldset {
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      padding: .75rem 1rem .85rem;
      margin-bottom: 1rem
    }

    #users-form legend {
      font-size: .78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: #555;
      padding: 0 .35rem
    }

    #users-form label {
      display: block;
      font-size: .82rem;
      font-weight: 600;
      color: #333;
      margin-top: .6rem
    }

    #users-form label:first-of-type {
      margin-top: 0
    }

    #users-form input[type="text"],
    #users-form input[type="password"] {
      display: block;
      width: 100%;
      margin-top: .2rem;
      padding: .45rem .65rem;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: .92rem;
      outline: none;
      font-family: inherit
    }

    #users-form input:focus {
      border-color: #05c
    }

    #users-form-actions {
      display: flex;
      gap: .6rem;
      justify-content: flex-end;
      margin-top: .25rem
    }

    #users-save-status {
      font-size: .8rem;
      color: #666;
      flex: 1;
      line-height: 2.1
    }
  </style>
</head>

<body>

  <!-- ── Login screen ─────────────────────────────────────── -->
  <div id="login-screen">
    <div id="login-box">
      <h2><img src="icon.svg" style="width:2rem;height:2rem;vertical-align:middle;margin-right:.5rem;" alt="">WeKickWiki — Sign in</h2>
      <form id="login-form" novalidate>
        <label>Username
          <input id="login-user" type="text" autocomplete="username" required autofocus>
        </label>
        <label>Password
          <input id="login-pass" type="password" autocomplete="current-password" required>
        </label>
        <button type="submit" title="Sign in" aria-label="Sign in"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
            <polyline points="10 17 15 12 10 7" />
            <line x1="15" y1="12" x2="3" y2="12" />
          </svg></button>
        <p id="login-error"></p>
      </form>
    </div>
  </div>

  <!-- ── Wiki screen ──────────────────────────────────────── -->
  <div id="wiki-screen" style="display:none">
    <header>
      <a id="header-title" href="" onclick="navigate('index');return false;"><img src="icon.svg" style="display:inline; width:1.5rem; height:1.5rem; margin-right:0.5rem; vertical-align:middle;" alt="">WeKickWiki</a>
      <div id="header-right">
        <span id="user-badge"></span>
        <button class="btn" id="toc-btn" title="Table of contents" aria-label="Table of contents" style="display:none" onclick="toggleToc()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="3" y1="5" x2="21" y2="5" />
            <line x1="6" y1="10" x2="21" y2="10" />
            <line x1="10" y1="15" x2="21" y2="15" />
            <line x1="6" y1="20" x2="21" y2="20" />
          </svg></button>
        <button class="btn" id="index-btn" title="Index" aria-label="Index" style="display:none" onclick="toggleIndex()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="18" x2="21" y2="18" />
          </svg></button>
        <button class="btn" id="users-btn" title="Manage users" aria-label="Manage users" style="display:none" onclick="toggleUsersPanel()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="8" r="4" />
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
            <circle cx="19" cy="8" r="3" stroke-width="1.5" />
            <line x1="19" y1="11" x2="19" y2="14" />
            <line x1="17.5" y1="12.5" x2="20.5" y2="12.5" />
          </svg></button>
        <button class="btn" id="backup-btn" title="Download backup" aria-label="Download backup" style="display:none" onclick="downloadBackup()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <polyline points="8 17 12 21 16 17" />
            <line x1="12" y1="12" x2="12" y2="21" />
            <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29" />
          </svg></button>
        <button class="btn btn-danger" id="restore-btn" title="Restore backup" aria-label="Restore backup" style="display:none" onclick="restoreBackup()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <polyline points="16 16 12 12 8 16" />
            <line x1="12" y1="12" x2="12" y2="21" />
            <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29" />
          </svg></button>
        <input type="file" id="restore-input" accept=".txt" style="display:none">
        <button class="btn" id="edit-btn" title="Edit" aria-label="Edit" style="display:none" onclick="toggleEdit()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
          </svg></button>
        <button class="btn btn-danger" id="delete-btn" title="Delete" aria-label="Delete" style="display:none" onclick="deletePage()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
            <path d="M10 11v6" />
            <path d="M14 11v6" />
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
          </svg></button>
        <button class="btn" id="home-btn" title="Home" aria-label="Home" style="display:none" onclick="navigate('index')"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
          </svg></button>
        <button class="btn" id="top-btn" title="Ir arriba" aria-label="Ir arriba" style="display:none" onclick="window.scrollTo({top:0,behavior:'smooth'})"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="12" y1="19" x2="12" y2="5" />
            <polyline points="5 12 12 5 19 12" />
          </svg></button>
        <button class="btn" id="odt-btn" title="Download as ODT" aria-label="Download as ODT" style="display:none" onclick="downloadOdt()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="12" y1="18" x2="12" y2="12" />
            <polyline points="9 15 12 18 15 15" />
          </svg></button>
        <button class="btn btn-danger" id="logout-btn" title="Sign out" aria-label="Sign out" onclick="logout()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <polyline points="16 17 21 12 16 7" />
            <line x1="21" y1="12" x2="9" y2="12" />
          </svg></button>
      </div>
    </header>
    <nav id="nav"></nav>
    <main id="content"></main>

    <!-- TOC panel -->
    <div id="toc-overlay" onclick="toggleToc()"></div>
    <div id="toc-panel">
      <h3>Contents <button class="close-btn" onclick="toggleToc()">&times;</button></h3>
      <div id="toc-list"></div>
    </div>
    <!-- Index panel -->
    <div id="index-overlay" onclick="toggleIndex()"></div>
    <div id="index-panel">
      <h3>Pages <button class="close-btn" onclick="toggleIndex()">&times;</button></h3>
      <div id="index-tree"></div>
    </div>
    <!-- Users management panel -->
    <div id="users-overlay" onclick="toggleUsersPanel()"></div>
    <div id="users-panel">
      <h3>Manage users <button class="close-btn" onclick="toggleUsersPanel()">&times;</button></h3>
      <form id="users-form" novalidate>
        <fieldset>
          <legend>Admin</legend>
          <label>Username
            <input type="text" id="users-admin-name" autocomplete="off" maxlength="32" pattern="[a-z0-9_]+">
          </label>
          <label>New password <span style="font-weight:400;color:#888">(leave blank to keep)</span>
            <input type="password" id="users-admin-pass" autocomplete="new-password" placeholder="••••••••">
          </label>
        </fieldset>
        <fieldset>
          <legend>Guest</legend>
          <label>Username
            <input type="text" id="users-guest-name" autocomplete="off" maxlength="32" pattern="[a-z0-9_]+">
          </label>
          <label>New password <span style="font-weight:400;color:#888">(leave blank to keep)</span>
            <input type="password" id="users-guest-pass" autocomplete="new-password" placeholder="••••••••">
          </label>
        </fieldset>
        <div id="guest-login-setting" style="display:flex;align-items:center;gap:.6rem;margin-bottom:.85rem">
          <input type="checkbox" id="guest-login-enabled" style="width:auto;cursor:pointer">
          <label for="guest-login-enabled" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest login</label>
        </div>
        <div id="users-form-actions">
          <span id="users-save-status"></span>
          <button type="button" class="btn" onclick="toggleUsersPanel()">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
    <div id="editor">
      <div id="editor-bar">
        <button class="btn btn-primary" title="Save" aria-label="Save" onclick="save()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
            <polyline points="17 21 17 13 7 13 7 21" />
            <polyline points="7 3 7 8 15 8" />
          </svg></button>
        <button class="btn" title="Cancel" aria-label="Cancel" onclick="cancelEdit()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg></button>
        <span id="save-status"></span>
      </div>
      <textarea id="editor-area" spellcheck="false"></textarea>
    </div>
  </div>
  <div style="text-align:center;font-size:.75rem;color:#999;margin:1rem auto 2rem">    
    <a href="https://github.com/rafaelaznar/wekickwiki">2026 - WeKickWiki. MIT License. Rafael Aznar</a>
  </div>

  <div id="toast"></div>

  <script>
    // ── Symbol substitution ─────────────────────────────────────────────────────
    // Applied as a markdown preprocessor so it works with any marked version.
    // Longer/more-specific patterns are listed before shorter overlapping ones.
    const SYMBOL_SUBS = [
      // Must come before their shorter prefixes
      [/---/g,      '\u2014'],   // em dash
      [/--/g,       '\u2013'],   // en dash
      [/\.\.\./g,   '\u2026'],   // ellipsis
      [/<=>/g,      '\u21D4'],   // left-right double arrow
      [/=>/g,       '\u21D2'],   // right double arrow
      [/<=/g,       '\u21D0'],   // left double arrow
      [/->/g,       '\u2192'],   // right arrow
      [/<-/g,       '\u2190'],   // left arrow
      [/\+-/g,      '\u00B1'],   // plus-minus
      [/\(c\)/gi,   '\u00A9'],   // copyright
      [/\(r\)/gi,   '\u00AE'],   // registered
      [/\(tm\)/gi,  '\u2122'],   // trademark
      [/\(p\)/gi,   '\u2117'],   // sound-recording copyright
      [/\(e\)/gi,   '\u20AC'],   // euro
      [/\(deg\)/gi, '\u00B0'],   // degree
      [/\(1\/2\)/g, '\u00BD'],   // one-half
      [/\(1\/4\)/g, '\u00BC'],   // one-quarter
      [/\(3\/4\)/g, '\u00BE'],   // three-quarters
      [/\(x\)/gi,   '\u00D7'],   // multiplication sign
      [/!=|\/=/g,   '\u2260'],   // not equal
      [/>=/g,       '\u2265'],   // greater-or-equal
    ];

    function applySymbols(md) {
      // Split on fenced code blocks and inline code spans — leave those untouched.
      const parts = md.split(/(```[\s\S]*?```|`[^`]*`)/g);
      return parts.map((chunk, i) => {
        if (i % 2 === 1) return chunk; // inside code → leave untouched
        // Process line by line so table separator rows (|---|---|) are never touched.
        return chunk.split('\n').map(line => {
          // A table separator line only contains |, -, :, and spaces (may be indented).
          if (/^\s*\|[\s|:\-]+\|?\s*$/.test(line)) return line;
          for (const [re, ch] of SYMBOL_SUBS) line = line.replace(re, ch);
          return line;
        }).join('\n');
      }).join('');
    }

    function parseWiki(md) {
      return marked.parse(applySymbols(md));
    }

    // ── Toast ───────────────────────────────────────────────────────────────────
    let _toastTimer;

    function showToast(msg, type = 'success', duration = 3000) {
      const el = document.getElementById('toast');
      el.textContent = msg;
      el.className = type + ' show';
      clearTimeout(_toastTimer);
      _toastTimer = setTimeout(() => el.classList.remove('show'), duration);
    }

    // ── Icons ───────────────────────────────────────────────────────────────────
    const ICON_EDIT = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    const ICON_VIEW = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const ICON_PLUS = '<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';

    // ── ODT download (client-side generation) ───────────────────────────────────
    function odtXmlEsc(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function odtInline(text) {
      // Decode a handful of HTML entities that may come from applySymbols / inline HTML
      function decEnt(s) {
        return s.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>')
                .replace(/&quot;/g,'"').replace(/&apos;/g,"'").replace(/&nbsp;/g,'\u00a0')
                .replace(/&#(\d+);/g,(_,n)=>String.fromCodePoint(+n))
                .replace(/&#x([0-9a-f]+);/gi,(_,h)=>String.fromCodePoint(parseInt(h,16)));
      }
      let out = ''; let i = 0;
      while (i < text.length) {
        // HTML tags
        if (text[i] === '<') {
          const tag = text.slice(i).match(/^<(\/?)(strong|b|em|i|code|s|del|strike|u|ins|mark|br)(\s[^>]*)?\/?>|^<a(\s[^>]*)?>|^<\/a>/i);
          if (tag) {
            const full = tag[0];
            const closing = full.startsWith('</');
            const tagName = (tag[1] !== undefined ? tag[2] : (full.match(/<(a|br)/i)||[])[1]||'').toLowerCase();
            if (tagName === 'br') { out += '<text:line-break/>'; }
            else if (!closing) {
              const style = {strong:'C_Bold',b:'C_Bold',em:'C_Italic',i:'C_Italic',
                            code:'C_Code',s:'C_Strike',del:'C_Strike',strike:'C_Strike',
                            u:'C_Under',ins:'C_Under',mark:'C_Mark',a:''}[tagName];
              if (style) out += '<text:span text:style-name="'+style+'">';
              else if (tagName === 'a') out += '<text:span>'; // plain span for links
            } else {
              out += '</text:span>';
            }
            i += full.length; continue;
          }
          // HTML entity starting with &
          const ent = text.slice(i).match(/^&([a-zA-Z]+|#\d+|#x[0-9a-fA-F]+);/);
          if (ent) { out += odtXmlEsc(decEnt(ent[0])); i += ent[0].length; continue; }
        }
        if (text[i] === '&') {
          const ent = text.slice(i).match(/^&([a-zA-Z]+|#\d+|#x[0-9a-fA-F]+);/);
          if (ent) { out += odtXmlEsc(decEnt(ent[0])); i += ent[0].length; continue; }
        }
        if (text.startsWith('**', i)) {
          const j = text.indexOf('**', i + 2);
          if (j !== -1) { out += '<text:span text:style-name="C_Bold">' + odtInline(text.slice(i+2,j)) + '</text:span>'; i = j+2; continue; }
        }
        if (text.startsWith('__', i)) {
          const j = text.indexOf('__', i + 2);
          if (j !== -1) { out += '<text:span text:style-name="C_Bold">' + odtInline(text.slice(i+2,j)) + '</text:span>'; i = j+2; continue; }
        }
        if (text.startsWith('~~', i)) {
          const j = text.indexOf('~~', i + 2);
          if (j !== -1) { out += '<text:span text:style-name="C_Strike">' + odtInline(text.slice(i+2,j)) + '</text:span>'; i = j+2; continue; }
        }
        if (text[i] === '*' && !text.startsWith('**', i)) {
          const j = text.indexOf('*', i + 1);
          if (j !== -1 && !text.startsWith('**', j)) { out += '<text:span text:style-name="C_Italic">' + odtInline(text.slice(i+1,j)) + '</text:span>'; i = j+1; continue; }
        }
        if (text[i] === '_' && !text.startsWith('__', i)) {
          const j = text.indexOf('_', i + 1);
          if (j !== -1 && !text.startsWith('__', j)) { out += '<text:span text:style-name="C_Italic">' + odtInline(text.slice(i+1,j)) + '</text:span>'; i = j+1; continue; }
        }
        if (text[i] === '`') {
          const j = text.indexOf('`', i + 1);
          if (j !== -1) { out += '<text:span text:style-name="C_Code">' + odtXmlEsc(text.slice(i+1,j)) + '</text:span>'; i = j+1; continue; }
        }
        if (text[i] === '!' && text[i+1] === '[') {
          const m = text.slice(i).match(/^!\[([^\]]*)\]\([^)]*\)/);
          if (m) { if (m[1]) out += odtXmlEsc('['+m[1]+']'); i += m[0].length; continue; }
        }
        if (text[i] === '[') {
          const m = text.slice(i).match(/^\[([^\]]*)\]\([^)]*\)/);
          if (m) { out += odtInline(m[1]); i += m[0].length; continue; }
        }
        out += odtXmlEsc(text[i]); i++;
      }
      return out;
    }

    function odtBody(md, inQuote) {
      // Pre-process: collect GFM tables and blockquotes into token objects.
      function parseBlocks(rawLines) {
        const tokens = [];
        let j = 0;
        while (j < rawLines.length) {
          // Blockquote: one or more consecutive lines starting with >
          if (/^>/.test(rawLines[j])) {
            const qLines = [];
            while (j < rawLines.length && /^>/.test(rawLines[j])) {
              qLines.push(rawLines[j].replace(/^>\s?/, '')); j++;
            }
            tokens.push({ type: 'quote', content: qLines.join('\n') });
            continue;
          }
          // A GFM table needs at least 3 lines: header | sep | body...
          // Header: contains |   Sep: only |, -, :, space
          if (j + 2 < rawLines.length
              && /\|/.test(rawLines[j])
              && /^\s*\|?[\s|:\-]+\|?\s*$/.test(rawLines[j+1])) {
            const splitCells = line => line.replace(/^\|/,'').replace(/\|$/,'').split('|').map(c => c.trim());
            const headers = splitCells(rawLines[j]);
            let k = j + 2;
            const rows = [];
            while (k < rawLines.length && /\|/.test(rawLines[k])
                   && !/^\s*\|?[\s|:\-]+\|?\s*$/.test(rawLines[k])) {
              rows.push(splitCells(rawLines[k])); k++;
            }
            tokens.push({ type: 'table', headers, rows });
            j = k;
          } else {
            tokens.push({ type: 'line', text: rawLines[j] }); j++;
          }
        }
        return tokens;
      }
      const S_BODY = inQuote ? 'P_Quote' : 'P_Body';
      const S_PRE  = inQuote ? 'P_QuotePre' : 'P_Pre';
      const S_LI   = inQuote ? 'P_QuoteLi' : 'P_Li';

      let tableCounter = 0;
      function odtTable(headers, rows) {
        tableCounter++;
        const tname = 'Tbl' + tableCounter;
        const cols = headers.length;
        let t = '<table:table table:name="'+odtXmlEsc(tname)+'" table:style-name="T_Table">';
        for (let c = 0; c < cols; c++) t += '<table:table-column table:style-name="T_Col"/>';
        // header row
        t += '<table:table-header-rows><table:table-row>';
        for (const h of headers)
          t += '<table:table-cell table:style-name="T_CellH" office:value-type="string"><text:p text:style-name="P_TH">'+odtInline(h)+'</text:p></table:table-cell>';
        t += '</table:table-row></table:table-header-rows>';
        // body rows
        for (const row of rows) {
          t += '<table:table-row>';
          for (let c = 0; c < cols; c++) {
            const cell = row[c] !== undefined ? row[c] : '';
            t += '<table:table-cell table:style-name="T_Cell" office:value-type="string"><text:p text:style-name="P_TD">'+odtInline(cell)+'</text:p></table:table-cell>';
          }
          t += '</table:table-row>';
        }
        t += '</table:table>';
        return t;
      }

      const rawLines = md.replace(/\r\n/g,'\n').replace(/\r/g,'\n').split('\n');
      const tokens = parseBlocks(rawLines);
      const lines = tokens.map(t =>
        t.type === 'line'  ? t.text :
        t.type === 'table' ? '\x00TABLE\x00' + JSON.stringify({h:t.headers,r:t.rows}) :
        /* quote */          '\x00QUOTE\x00' + t.content
      );

      let out = '', i = 0, inList = false, lstType = '', inCode = false, codeBuf = [];
      while (i < lines.length) {
        const line = lines[i];
        if (/^```/.test(line)) {
          if (inCode) {
            inCode = false;
            if (inList) { out += '</text:list>'; inList = false; }
            for (const cl of codeBuf) out += '<text:p text:style-name="'+S_PRE+'">' + odtXmlEsc(cl) + '</text:p>';
            codeBuf = [];
          } else {
            if (inList) { out += '</text:list>'; inList = false; }
            inCode = true;
          }
          i++; continue;
        }
        if (inCode) { codeBuf.push(line); i++; continue; }
        const isUL = /^(\s*)[-*+]\s+(.*)$/.exec(line);
        const isOL = !isUL && /^\d+\.\s+(.*)$/.exec(line);
        if (inList && !isUL && !isOL && line.trim() !== '') { out += '</text:list>'; inList = false; }
        if (line.startsWith('\x00TABLE\x00')) {
          const td = JSON.parse(line.slice(7));
          out += odtTable(td.h, td.r);
        } else if (line.startsWith('\x00QUOTE\x00')) {
          if (inList) { out += '</text:list>'; inList = false; }
          out += odtBody(line.slice(7), true);
        } else {
          const hd = /^(#{1,6})\s+(.+)$/.exec(line);
          if (hd) {
            const lvl = hd[1].length;
            out += '<text:h text:style-name="P_H'+lvl+'" text:outline-level="'+lvl+'">'+odtInline(hd[2])+'</text:h>';
          } else if (/^(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
            out += '<text:p text:style-name="P_HR"/>';
          } else if (isUL) {
            if (!inList || lstType !== 'ul') { if (inList) out += '</text:list>'; out += '<text:list text:style-name="LS_Bullet">'; inList = true; lstType = 'ul'; }
            out += '<text:list-item><text:p text:style-name="'+S_LI+'">'+odtInline(isUL[2])+'</text:p></text:list-item>';
          } else if (isOL) {
            if (!inList || lstType !== 'ol') { if (inList) out += '</text:list>'; out += '<text:list text:style-name="LS_Number">'; inList = true; lstType = 'ol'; }
            out += '<text:list-item><text:p text:style-name="'+S_LI+'">'+odtInline(isOL[1])+'</text:p></text:list-item>';
          } else if (line.trim() === '') {
            // blank
          } else {
            const pl = [line];
            while (i+1 < lines.length && lines[i+1].trim() !== ''
              && !/^#{1,6}\s/.test(lines[i+1]) && !/^```/.test(lines[i+1])
              && !/^(\s*)[-*+]\s/.test(lines[i+1]) && !/^\d+\.\s/.test(lines[i+1])
              && !/^(-{3,}|\*{3,}|_{3,})\s*$/.test(lines[i+1])
              && !lines[i+1].startsWith('\x00TABLE\x00')
              && !lines[i+1].startsWith('\x00QUOTE\x00')
            ) { i++; pl.push(lines[i]); }
            out += '<text:p text:style-name="'+S_BODY+'">'+odtInline(pl.join(' '))+'</text:p>';
          }
        }
        i++;
      }
      if (inList) out += '</text:list>';
      return out;
    }

    function buildOdtManifest() { /* not used in flat-ODT mode */ }

    function buildOdtContent(md) {
      const xmlns =
        ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        +' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        +' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
        +' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
        +' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        +' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"';
      const astyles =
        '<style:style style:name="P_Body" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.1cm" fo:margin-bottom="0.1cm"/>'
        +'<style:text-properties fo:font-size="12pt"/>'
        +'</style:style>'
        +'<style:style style:name="P_H1" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.5cm" fo:margin-bottom="0.25cm"/>'
        +'<style:text-properties fo:font-size="18pt" fo:font-weight="bold" fo:color="#8A0808"/>'
        +'</style:style>'
        +'<style:style style:name="P_H2" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.4cm" fo:margin-bottom="0.2cm"/>'
        +'<style:text-properties fo:font-size="15pt" fo:font-weight="bold" fo:color="#8A0808"/>'
        +'</style:style>'
        +'<style:style style:name="P_H3" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.35cm" fo:margin-bottom="0.15cm"/>'
        +'<style:text-properties fo:font-size="13pt" fo:font-weight="bold" fo:color="#8A0808"/>'
        +'</style:style>'
        +'<style:style style:name="P_H4" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.3cm" fo:margin-bottom="0.1cm"/>'
        +'<style:text-properties fo:font-size="12pt" fo:font-weight="bold" fo:color="#8A0808"/>'
        +'</style:style>'
        +'<style:style style:name="P_H5" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.25cm" fo:margin-bottom="0.1cm"/>'
        +'<style:text-properties fo:font-size="11pt" fo:font-weight="bold"/>'
        +'</style:style>'
        +'<style:style style:name="P_H6" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-top="0.2cm" fo:margin-bottom="0.1cm"/>'
        +'<style:text-properties fo:font-size="10pt" fo:font-weight="bold"/>'
        +'</style:style>'
        +'<style:style style:name="P_Pre" style:family="paragraph">'
        +'<style:paragraph-properties fo:background-color="#f5f5f5" fo:padding="0.1cm" fo:margin-top="0cm" fo:margin-bottom="0cm"/>'
        +'<style:text-properties style:font-name="Liberation Mono" fo:font-family="Liberation Mono" fo:font-size="10pt"/>'
        +'</style:style>'
        +'<style:style style:name="P_HR" style:family="paragraph">'
        +'<style:paragraph-properties fo:border-bottom="0.05cm solid #cccccc" fo:padding-bottom="0.15cm" fo:margin-bottom="0.15cm"/>'
        +'</style:style>'
        +'<style:style style:name="P_Li" style:family="paragraph">'
        +'<style:text-properties fo:font-size="12pt"/>'
        +'</style:style>'
        +'<style:style style:name="C_Bold" style:family="text">'
        +'<style:text-properties fo:font-weight="bold"/>'
        +'</style:style>'
        +'<style:style style:name="C_Italic" style:family="text">'
        +'<style:text-properties fo:font-style="italic"/>'
        +'</style:style>'
        +'<style:style style:name="C_Code" style:family="text">'
        +'<style:text-properties style:font-name="Liberation Mono" fo:font-family="Liberation Mono" fo:font-size="10pt" fo:background-color="#f0f0f0"/>'
        +'</style:style>'
        +'<text:list-style style:name="LS_Bullet">'
        +'<text:list-level-style-bullet text:level="1" text:bullet-char="&#x2022;">'
        +'<style:list-level-properties text:space-before="0.5cm" text:min-label-width="0.5cm"/>'
        +'</text:list-level-style-bullet>'
        +'</text:list-style>'
        +'<text:list-style style:name="LS_Number">'
        +'<text:list-level-style-number text:level="1" style:num-format="1" style:num-suffix=".">'
        +'<style:list-level-properties text:space-before="0.5cm" text:min-label-width="0.5cm"/>'
        +'</text:list-level-style-number>'
        +'</text:list-style>'
        +'<style:style style:name="T_Table" style:family="table">'
        +'<style:table-properties style:width="16cm" fo:margin-top="0.3cm" fo:margin-bottom="0.3cm" table:border-model="collapsing"/>'
        +'</style:style>'
        +'<style:style style:name="T_Col" style:family="table-column">'
        +'<style:table-column-properties style:column-width="4cm"/>'
        +'</style:style>'
        +'<style:style style:name="T_CellH" style:family="table-cell">'
        +'<style:table-cell-properties fo:border="0.05cm solid #dddddd" fo:background-color="#f5f5f5" fo:padding="0.12cm"/>'
        +'</style:style>'
        +'<style:style style:name="T_Cell" style:family="table-cell">'
        +'<style:table-cell-properties fo:border="0.05cm solid #dddddd" fo:padding="0.12cm"/>'
        +'</style:style>'
        +'<style:style style:name="P_TH" style:family="paragraph">'
        +'<style:text-properties fo:font-weight="bold" fo:font-size="11pt"/>'
        +'</style:style>'
        +'<style:style style:name="P_TD" style:family="paragraph">'
        +'<style:text-properties fo:font-size="11pt"/>'
        +'</style:style>'
        +'<style:style style:name="P_Quote" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-left="0.8cm" fo:padding-left="0.3cm" fo:border-left="0.1cm solid #cccccc" fo:margin-top="0.05cm" fo:margin-bottom="0.05cm"/>'
        +'<style:text-properties fo:font-size="12pt" fo:color="#555555"/>'
        +'</style:style>'
        +'<style:style style:name="P_QuotePre" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-left="0.8cm" fo:padding-left="0.3cm" fo:border-left="0.1cm solid #cccccc" fo:background-color="#f5f5f5" fo:padding="0.1cm" fo:margin-top="0cm" fo:margin-bottom="0cm"/>'
        +'<style:text-properties style:font-name="Liberation Mono" fo:font-family="Liberation Mono" fo:font-size="10pt" fo:color="#555555"/>'
        +'</style:style>'
        +'<style:style style:name="P_QuoteLi" style:family="paragraph">'
        +'<style:paragraph-properties fo:margin-left="0.8cm"/>'
        +'<style:text-properties fo:font-size="12pt" fo:color="#555555"/>'
        +'</style:style>'
        +'<style:style style:name="C_Strike" style:family="text">'
        +'<style:text-properties style:text-line-through-style="solid"/>'
        +'</style:style>'
        +'<style:style style:name="C_Under" style:family="text">'
        +'<style:text-properties style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"/>'
        +'</style:style>'
        +'<style:style style:name="C_Mark" style:family="text">'
        +'<style:text-properties fo:background-color="#ffff00"/>'
        +'</style:style>';
      return '\x3C?xml version="1.0" encoding="UTF-8"?>'
        +'<office:document'
        +xmlns
        +' office:version="1.3"'
        +' office:mimetype="application/vnd.oasis.opendocument.text">'
        +'<office:font-face-decls>'
        +'<style:font-face style:name="Liberation Mono" svg:font-family="&apos;Liberation Mono&apos;" style:font-family-generic="modern" style:font-pitch="fixed"/>'
        +'</office:font-face-decls>'
        +'<office:automatic-styles>'+astyles+'</office:automatic-styles>'
        +'<office:body><office:text>'
        +odtBody(applySymbols(md))
        +'</office:text></office:body>'
        +'</office:document>';
    }

    async function downloadOdt() {
      if (!rawMd) return;
      const btn = document.getElementById('odt-btn');
      btn.disabled = true;
      try {
        const xml = buildOdtContent(rawMd);
        const blob = new Blob([xml], {type: 'application/vnd.oasis.opendocument.text-flat-xml'});
        const filename = currentPage.split('/').pop() + '.fodt';
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
        showToast('Downloaded: ' + filename);
      } catch(e) {
        console.error('ODT error:', e);
        showToast('Error generating ODT: ' + e.message, 'error');
      } finally {
        btn.disabled = false;
      }
    }

    // ── Backup & Restore ────────────────────────────────────────────────────────
    async function downloadBackup() {
      const btn = document.getElementById('backup-btn');
      btn.disabled = true;
      try {
        const res = await apiFetch('?action=backup');
        if (!res || !res.ok) {
          const data = await res.json().catch(() => ({}));
          showToast(data.error || 'Error generating backup', 'error');
          return;
        }
        // Derive filename from Content-Disposition header or build a fallback
        let filename = 'wkw-backup.txt';
        const cd = res.headers.get('Content-Disposition') || '';
        const m  = cd.match(/filename="?([^";\s]+)"?/);
        if (m) filename = m[1];

        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        showToast('Backup downloaded: ' + filename);
      } catch {
        showToast('Connection error during backup', 'error');
      } finally {
        btn.disabled = false;
      }
    }

    function restoreBackup() {
      document.getElementById('restore-input').click();
    }

    document.getElementById('restore-input').addEventListener('change', async function () {
      const file = this.files[0];
      if (!file) return;

      const confirmed = confirm(
        '⚠️ RESTORE BACKUP\n\n' +
        'This will permanently DELETE the entire pages/ directory and replace ALL wiki content with the uploaded backup.\n\n' +
        'This operation CANNOT be undone.\n\n' +
        'Are you sure you want to continue?'
      );
      this.value = ''; // reset input regardless
      if (!confirmed) return;

      const btn = document.getElementById('restore-btn');
      btn.disabled = true;
      const fd = new FormData();
      fd.append('backup', file);
      try {
        const res = await apiFetch('?action=restore', { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (res && res.ok) {
          showToast('Backup restored — ' + data.pages + ' page(s) recovered.', 'success', 5000);
          load(currentPage);
        } else {
          showToast(data.error || 'Error restoring backup', 'error');
        }
      } catch {
        showToast('Connection error during restore', 'error');
      } finally {
        btn.disabled = false;
      }
    });

    // ── Users management panel ──────────────────────────────────────────────────
    let usersOpen = false;

    async function toggleUsersPanel() {
      const overlay = document.getElementById('users-overlay');
      const panel   = document.getElementById('users-panel');
      usersOpen = !usersOpen;
      overlay.style.display = panel.style.display = usersOpen ? 'block' : 'none';
      if (!usersOpen) {
        document.getElementById('users-save-status').textContent = '';
        document.getElementById('users-admin-pass').value = '';
        document.getElementById('users-guest-pass').value = '';
        return;
      }
      // Pre-load current usernames from server
      const res = await apiFetch('?action=get-users');
      if (!res || !res.ok) {
        document.getElementById('users-save-status').textContent = 'Could not load users.';
        return;
      }
      const data = await res.json();
      document.getElementById('users-admin-name').value = data.adminUser || '';
      document.getElementById('users-guest-name').value = data.guestUser || '';
      document.getElementById('users-admin-pass').value = '';
      document.getElementById('users-guest-pass').value = '';
      document.getElementById('users-save-status').textContent = '';
      document.getElementById('guest-login-enabled').checked = data.guestLoginEnabled !== false;
    }

    document.getElementById('users-form').addEventListener('submit', async e => {
      e.preventDefault();
      const statusEl = document.getElementById('users-save-status');
      statusEl.textContent = 'Saving…';

      const adminUser = document.getElementById('users-admin-name').value.trim().toLowerCase();
      const guestUser = document.getElementById('users-guest-name').value.trim().toLowerCase();
      const adminPass = document.getElementById('users-admin-pass').value;
      const guestPass = document.getElementById('users-guest-pass').value;

      if (!/^[a-z0-9_]{2,32}$/.test(adminUser)) {
        statusEl.textContent = 'Admin username: 2–32 chars, only a-z, 0-9, _';
        return;
      }
      if (!/^[a-z0-9_]{2,32}$/.test(guestUser)) {
        statusEl.textContent = 'Guest username: 2–32 chars, only a-z, 0-9, _';
        return;
      }
      if (adminUser === guestUser) {
        statusEl.textContent = 'Admin and guest usernames must be different.';
        return;
      }

      const adminHash = adminPass ? await sha256(adminPass) : null;
      const guestHash = guestPass ? await sha256(guestPass) : null;
      const guestLoginEnabled = document.getElementById('guest-login-enabled').checked;

      try {
        const res = await apiFetch('?action=save-users', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ adminUser, adminHash, guestUser, guestHash, guestLoginEnabled })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          const currentUser = getUser();
          toggleUsersPanel();
          // If admin renamed themselves, force re-login
          if (currentUser !== adminUser) {
            showToast('Admin username changed — please sign in again.', 'success', 4000);
            setTimeout(logout, 500);
          } else {
            showToast('Users updated successfully.');
          }
        } else {
          statusEl.textContent = data.error || 'Error saving users.';
        }
      } catch {
        statusEl.textContent = 'Connection error.';
      }
    });

    // ── Base & routing helpers ──────────────────────────────────────────────────
    const BASE = <?= json_encode($baseHref) ?>;

    function getPage() {
      const p = decodeURIComponent(location.pathname);
      return (p.length > BASE.length ? p.slice(BASE.length) : '') || 'index';
    }

    function navigate(page, replace) {
      const url = BASE + (page === 'index' ? '' : page);
      replace ? history.replaceState(null, '', url) : history.pushState(null, '', url);
      load(page);
    }

    // ── Auth helpers ────────────────────────────────────────────────────────────
    function getToken() {
      return sessionStorage.getItem('wkw_token');
    }

    function getRole() {
      return sessionStorage.getItem('wkw_role');
    }

    function getUser() {
      return sessionStorage.getItem('wkw_user');
    }

    async function sha256(msg) {
      // crypto.subtle requires a secure context (HTTPS/localhost).
      // Fall back to a pure-JS SHA-256 so the app works over plain HTTP too.
      if (typeof crypto !== 'undefined' && crypto.subtle) {
        const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(msg));
        return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
      }
      return sha256Fallback(msg);
    }

    // Pure-JS SHA-256 (RFC 6234 / FIPS 180-4) — used when crypto.subtle is unavailable.
    function sha256Fallback(msg) {
      const K = [
        0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
        0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
        0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
        0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
        0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
        0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
        0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
        0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2,
      ];
      const bytes = new TextEncoder().encode(msg);
      const bits  = bytes.length * 8;
      const padLen = ((bytes.length % 64) < 56 ? 56 : 120) - (bytes.length % 64);
      const buf    = new Uint8Array(bytes.length + padLen + 8);
      buf.set(bytes);
      buf[bytes.length] = 0x80;
      const dv = new DataView(buf.buffer);
      dv.setUint32(buf.length - 4, bits >>> 0,  false);
      dv.setUint32(buf.length - 8, Math.floor(bits / 2**32), false);

      let [h0,h1,h2,h3,h4,h5,h6,h7] =
        [0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];

      const rotr = (x, n) => (x >>> n) | (x << (32 - n));
      for (let i = 0; i < buf.length; i += 64) {
        const w = new Uint32Array(64);
        for (let j = 0; j < 16; j++) w[j] = dv.getUint32(i + j * 4, false);
        for (let j = 16; j < 64; j++) {
          const s0 = rotr(w[j-15],7)  ^ rotr(w[j-15],18) ^ (w[j-15] >>> 3);
          const s1 = rotr(w[j-2], 17) ^ rotr(w[j-2], 19) ^ (w[j-2]  >>> 10);
          w[j] = (w[j-16] + s0 + w[j-7] + s1) >>> 0;
        }
        let [a,b,c,d,e,f,g,h] = [h0,h1,h2,h3,h4,h5,h6,h7];
        for (let j = 0; j < 64; j++) {
          const S1  = rotr(e,6) ^ rotr(e,11) ^ rotr(e,25);
          const ch  = (e & f) ^ (~e & g);
          const t1  = (h + S1 + ch + K[j] + w[j]) >>> 0;
          const S0  = rotr(a,2) ^ rotr(a,13) ^ rotr(a,22);
          const maj = (a & b) ^ (a & c) ^ (b & c);
          const t2  = (S0 + maj) >>> 0;
          [h,g,f,e,d,c,b,a] = [g,f,e,(d+t1)>>>0,c,b,a,(t1+t2)>>>0];
        }
        h0=(h0+a)>>>0; h1=(h1+b)>>>0; h2=(h2+c)>>>0; h3=(h3+d)>>>0;
        h4=(h4+e)>>>0; h5=(h5+f)>>>0; h6=(h6+g)>>>0; h7=(h7+h)>>>0;
      }
      return [h0,h1,h2,h3,h4,h5,h6,h7].map(n => n.toString(16).padStart(8,'0')).join('');
    }

    async function apiFetch(url, opts = {}) {
      opts.headers = {
        ...(opts.headers || {}),
        'Authorization': 'Bearer ' + getToken()
      };
      const res = await fetch(url, opts);
      if (res.status === 401) {
        logout();
        return res;
      }
      return res;
    }

    function logout() {
      sessionStorage.clear();
      showLogin();
    }

    function showLogin() {
      document.getElementById('login-screen').style.display = 'flex';
      document.getElementById('wiki-screen').style.display = 'none';
      document.getElementById('login-error').textContent = '';
      document.getElementById('login-pass').value = '';
    }

    function showWiki() {
      document.getElementById('login-screen').style.display = 'none';
      document.getElementById('wiki-screen').style.display = '';
      document.getElementById('user-badge').textContent = getUser();
      const isAdmin = getRole() === 'admin';
      const isGuest = getRole() === 'guest';
      document.getElementById('toc-btn').style.display = isGuest ? 'none' : '';
      document.getElementById('edit-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('index-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('users-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('backup-btn').style.display = isAdmin ? '' : 'none';
      document.getElementById('restore-btn').style.display = isAdmin ? '' : 'none';
      if (isGuest) {
        document.getElementById('wiki-screen').classList.add('guest-mode');
        document.getElementById('home-btn').style.display = '';
        document.getElementById('top-btn').style.display = '';
      }
    }

    // ── Login form ──────────────────────────────────────────────────────────────
    document.getElementById('login-form').addEventListener('submit', async e => {
      e.preventDefault();
      const user = document.getElementById('login-user').value.trim();
      const pass = document.getElementById('login-pass').value;
      const errEl = document.getElementById('login-error');
      errEl.textContent = '';
      if (!user || !pass) {
        errEl.textContent = 'Please fill in all fields';
        return;
      }

      const hash = await sha256(pass);
      try {
        const res = await fetch('?action=login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            user,
            hash
          })
        });
        const data = await res.json();
        if (res.ok) {
          sessionStorage.setItem('wkw_token', data.token);
          sessionStorage.setItem('wkw_role', data.role);
          sessionStorage.setItem('wkw_user', user);
          showWiki();
          route();
        } else {
          errEl.textContent = data.error || 'Authentication error';
        }
      } catch {
        errEl.textContent = 'Connection error';
      }
    });

    // ── Wiki ────────────────────────────────────────────────────────────────────
    let currentPage = 'index';
    let rawMd = '';
    let editing = false;
    let isNewPage = false;

    async function load(page) {
      currentPage = page;
      editing = false;
      closeToc();
      document.getElementById('editor').style.display = 'none';
      document.getElementById('content').style.display = '';
      document.getElementById('edit-btn').innerHTML = ICON_EDIT;
      document.getElementById('edit-btn').title = 'Edit';
      document.getElementById('save-status').textContent = '';

      const res = await apiFetch('?page=' + encodeURIComponent(page));
      if (!res) return;
      rawMd = await res.text();
      const isAdmin = getRole() === 'admin';
      if (res.status === 404 && isAdmin) {
        document.getElementById('delete-btn').style.display = 'none';
        document.getElementById('odt-btn').style.display = 'none';
        document.getElementById('content').innerHTML =
          '<p style="color:#888;margin-bottom:.75rem">This page does not exist yet.</p>' +
          '<button class="btn btn-primary" title="Create page" aria-label="Create page" onclick="createPage()">' + ICON_PLUS + '</button>';
      } else {
        document.getElementById('delete-btn').style.display = isAdmin ? '' : 'none';
        document.getElementById('odt-btn').style.display = '';
        document.getElementById('content').innerHTML = parseWiki(rawMd);
        addHeadingIds();
        buildInlineToc();
      }

      const parts = page === 'index' ? [] : page.split('/');
      let nav = '<a href="" onclick="navigate(\'index\');return false;">Home</a>';
      parts.forEach((p, i) => {
        const t = parts.slice(0, i + 1).join('/');
        nav += ' &rsaquo; <a href="' + BASE + t + '" onclick="navigate(\'' + t + '\');return false;">' + p + '</a>';
      });
      document.getElementById('nav').innerHTML = nav;

      function resolvePath(base, rel) {
        const parts = (base + rel).split('/');
        const out = [];
        for (const p of parts) {
          if (p === '..') out.pop();
          else if (p !== '.') out.push(p);
        }
        return out.join('/');
      }

      document.querySelectorAll('#content a[href]').forEach(a => {
        const h = a.getAttribute('href');
        if (h && !h.startsWith('http') && !h.startsWith('#') && !h.startsWith('mailto:')) {
          const base = page.includes('/') ? page.slice(0, page.lastIndexOf('/') + 1) : '';
          const target = resolvePath(base, h);
          a.href = BASE + target;
          a.addEventListener('click', e => {
            e.preventDefault();
            navigate(target);
          });
        }
      });

      const h1 = document.querySelector('#content h1');
      document.title = (h1 ? h1.textContent : page) + ' — Wiki';
      window.scrollTo(0, 0);
    }

    function createPage() {
      rawMd = '';
      isNewPage = true;
      openEdit();
    }

    // ── TOC panel ────────────────────────────────────────────────────────────────
    function slugify(text) {
      return text.toLowerCase().replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-') || 'heading';
    }

    function addHeadingIds() {
      const seen = {};
      document.querySelectorAll('#content h1, #content h2, #content h3').forEach(h => {
        let slug = slugify(h.textContent);
        if (seen[slug]) {
          seen[slug]++;
          slug += '-' + seen[slug];
        } else {
          seen[slug] = 1;
        }
        h.id = slug;
      });
    }

    function buildInlineToc() {
      const existing = document.getElementById('toc-inline');
      if (existing) existing.remove();
      if (getRole() !== 'guest') return;
      const headings = document.querySelectorAll('#content h1, #content h2, #content h3');
      if (headings.length < 2) return;
      let html = '<ul>';
      headings.forEach(h => {
        const cls = 'toc-' + h.tagName.toLowerCase();
        const id = h.id;
        html += '<li><a class="' + cls + '" href="#' + id + '" onclick="document.getElementById(\'' + id + '\').scrollIntoView({behavior:\'smooth\'});return false;">' + h.textContent + '</a></li>';
      });
      html += '</ul>';
      const div = document.createElement('div');
      div.id = 'toc-inline';
      const content = document.getElementById('content');
      div.innerHTML = html;
      content.insertBefore(div, content.firstChild);
    }

    let tocOpen = false;

    function closeToc() {
      if (!tocOpen) return;
      tocOpen = false;
      document.getElementById('toc-overlay').style.display = 'none';
      document.getElementById('toc-panel').style.display = 'none';
    }

    function toggleToc() {
      const overlay = document.getElementById('toc-overlay');
      const panel = document.getElementById('toc-panel');
      tocOpen = !tocOpen;
      overlay.style.display = panel.style.display = tocOpen ? 'block' : 'none';
      if (!tocOpen) return;

      const headings = document.querySelectorAll('#content h1, #content h2, #content h3');
      if (!headings.length) {
        document.getElementById('toc-list').innerHTML = '<p style="color:#888;font-size:.85rem;padding:.25rem 0">No headings on this page.</p>';
        return;
      }
      let html = '<ul>';
      headings.forEach(h => {
        const cls = 'toc-' + h.tagName.toLowerCase();
        const id = h.id;
        html += '<li><a class="' + cls + '" href="#' + id + '" onclick="document.getElementById(\'' + id + '\').scrollIntoView({behavior:\'smooth\'});closeToc();return false;">' + h.textContent + '</a></li>';
      });
      html += '</ul>';
      document.getElementById('toc-list').innerHTML = html;
    }

    // ── Page index panel ────────────────────────────────────────────────────────
    let indexOpen = false;
    async function toggleIndex() {
      const overlay = document.getElementById('index-overlay');
      const panel = document.getElementById('index-panel');
      indexOpen = !indexOpen;
      overlay.style.display = panel.style.display = indexOpen ? 'block' : 'none';
      if (!indexOpen) return;

      const res = await apiFetch(BASE + '?action=index');
      if (!res || !res.ok) return;
      const data = await res.json();

      // Build tree from flat list
      const tree = {};
      for (const page of data.pages) {
        const parts = page.split('/');
        let node = tree;
        for (const p of parts) {
          node[p] = node[p] || {};
          node = node[p];
        }
      }

      function renderTree(node, prefix) {
        const keys = Object.keys(node).sort();
        if (!keys.length) return '';
        let html = '<ul>';
        for (const key of keys) {
          const path = prefix ? prefix + '/' + key : key;
          const hasChildren = Object.keys(node[key]).length > 0;
          html += '<li>';
          html += '<a href="' + BASE + (path === 'index' ? '' : path) + '" onclick="navigate(\'' + path + '\');toggleIndex();return false;">' + key + '</a>';
          if (hasChildren) html += renderTree(node[key], path);
          html += '</li>';
        }
        html += '</ul>';
        return html;
      }

      document.getElementById('index-tree').innerHTML = renderTree(tree, '');
    }

    async function deletePage() {
      if (!confirm('Delete page "' + currentPage + '"? This action cannot be undone.')) return;
      const res = await apiFetch('?action=delete', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          page: currentPage
        })
      });
      if (res && res.ok) {
        showToast('Page \u201c' + currentPage + '\u201d deleted.');
        navigate('index', true);
      } else {
        const data = await res.json().catch(() => ({}));
        showToast(data.error || 'Error deleting page', 'error');
      }
    }

    function toggleEdit() {
      editing ? cancelEdit() : openEdit();
    }

    function openEdit() {
      editing = true;
      document.getElementById('editor-area').value = rawMd;
      document.getElementById('content').style.display = 'none';
      document.getElementById('editor').style.display = 'flex';
      document.getElementById('edit-btn').innerHTML = ICON_VIEW;
      document.getElementById('edit-btn').title = 'View';
      document.getElementById('editor-area').focus();
    }

    function cancelEdit() {
      editing = false;
      isNewPage = false;
      document.getElementById('editor').style.display = 'none';
      document.getElementById('content').style.display = '';
      document.getElementById('edit-btn').innerHTML = ICON_EDIT;
      document.getElementById('edit-btn').title = 'Edit';
    }

    async function save() {
      const status = document.getElementById('save-status');
      const content = document.getElementById('editor-area').value;
      status.textContent = 'Saving…';
      const res = await apiFetch('?action=save', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          page: currentPage,
          content
        })
      });
      if (res && res.ok) {
        const wasNew = isNewPage;
        rawMd = content;
        status.textContent = '';
        cancelEdit();
        document.getElementById('content').innerHTML = parseWiki(rawMd);
        addHeadingIds();
        buildInlineToc();
        if (getRole() === 'admin') document.getElementById('delete-btn').style.display = '';
        document.getElementById('odt-btn').style.display = '';
        showToast(wasNew ? 'Page \u201c' + currentPage + '\u201d created.' : 'Page \u201c' + currentPage + '\u201d saved.');
      } else {
        const data = await res.json().catch(() => ({}));
        status.textContent = '';
        showToast(data.error || 'Could not save the page. Please try again.', 'error');
      }
    }

    document.addEventListener('keydown', e => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's' && editing) {
        e.preventDefault();
        save();
      }
      if (e.key === 'Escape' && editing) {
        cancelEdit();
      }
    });

    // ── Router ──────────────────────────────────────────────────────────────────
    function route() {
      if (!getToken()) {
        showLogin();
        return;
      }
      showWiki();
      load(getPage());
    }
    window.addEventListener('popstate', route);
    route();
  </script>
</body>

</html>