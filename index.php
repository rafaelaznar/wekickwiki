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
define('SETTINGS_FILE', __DIR__ . '/settings.json');

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

function load_settings(): array {
  $defaults = ['wikiName' => 'WeKickWiki', 'theme' => 'default.css'];
  if (!is_file(SETTINGS_FILE)) return $defaults;
  $data = json_decode(file_get_contents(SETTINGS_FILE), true);
  if (!is_array($data)) return $defaults;
  $name  = (isset($data['wikiName']) && is_string($data['wikiName']) && $data['wikiName'] !== '') ? $data['wikiName'] : $defaults['wikiName'];
  $theme = (isset($data['theme']) && is_string($data['theme']) && preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $data['theme']) && is_file(__DIR__ . '/templates/' . $data['theme'])) ? $data['theme'] : $defaults['theme'];
  return ['wikiName' => $name, 'theme' => $theme];
}

function list_templates(): array {
  $dir = __DIR__ . '/templates';
  if (!is_dir($dir)) return ['default.css'];
  $files = glob($dir . '/*.css') ?: [];
  sort($files);
  return array_map('basename', $files);
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
// API: Get settings  GET ?action=get-settings  (admin only)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-settings') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  json_out(200, load_settings());
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Get templates  GET ?action=get-templates  (admin only)
// Lists all .css files found in the templates/ directory.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-templates') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  json_out(200, ['templates' => list_templates()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Save settings  POST ?action=save-settings  (admin only)
// Body: { wikiName: string, theme: string }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-settings') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body     = json_decode(file_get_contents('php://input'), true) ?? [];
  $wikiName = trim($body['wikiName'] ?? '');
  $theme    = basename($body['theme'] ?? '');
  if ($wikiName === '' || mb_strlen($wikiName) > 64) {
    json_out(400, ['error' => 'Wiki name must be between 1 and 64 characters']);
  }
  if (!preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme)) {
    json_out(400, ['error' => 'Invalid theme filename']);
  }
  if (!is_file(__DIR__ . '/templates/' . $theme)) {
    json_out(400, ['error' => 'Theme file not found']);
  }
  $newSettings = ['wikiName' => $wikiName, 'theme' => $theme];
  if (file_put_contents(SETTINGS_FILE, json_encode($newSettings, JSON_PRETTY_PRINT), LOCK_EX) === false) {
    json_out(500, ['error' => 'Could not write settings file']);
  }
  json_out(200, ['ok' => true]);
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

$settings = load_settings();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($settings['wikiName']) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="icon.svg">
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
  <link rel="stylesheet" href="templates/<?= htmlspecialchars($settings['theme'], ENT_QUOTES) ?>">
</head>

<body>

  <!-- ── Login screen ─────────────────────────────────────── -->
  <div id="login-screen">
    <div id="login-box">
      <h2><img src="icon.svg" style="width:2rem;height:2rem;vertical-align:middle;margin-right:.5rem;" alt=""><?= htmlspecialchars($settings['wikiName']) ?> — Sign in</h2>
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
      <a id="header-title" href="" onclick="navigate('index');return false;"><img src="icon.svg" style="display:inline; width:1.5rem; height:1.5rem; margin-right:0.5rem; vertical-align:middle;" alt=""><?= htmlspecialchars($settings['wikiName']) ?></a>
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
        <button class="btn" id="settings-btn" title="Settings" aria-label="Settings" style="display:none" onclick="toggleSettingsPanel()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
          </svg></button>
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
        <button class="btn" id="top-btn" title="Go to top" aria-label="Go to top" style="display:none" onclick="window.scrollTo({top:0,behavior:'smooth'})"><svg viewBox="0 0 24 24" aria-hidden="true">
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
    <!-- Settings panel -->
    <div id="settings-overlay" onclick="toggleSettingsPanel()"></div>
    <div id="settings-panel">
      <h3>Settings <button class="close-btn" onclick="toggleSettingsPanel()">&times;</button></h3>
      <form id="settings-form" novalidate>
        <label>Wiki name
          <input type="text" id="settings-wiki-name" autocomplete="off" maxlength="64">
        </label>
        <label>Theme
          <select id="settings-theme"></select>
        </label>
        <div id="settings-form-actions">
          <span id="settings-save-status"></span>
          <button type="button" class="btn" onclick="toggleSettingsPanel()">Cancel</button>
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

  <div id="footer">    
    <a href="https://github.com/rafaelaznar/wekickwiki">2026 - <?= htmlspecialchars($settings['wikiName']) ?>. MIT License. Rafael Aznar</a>
  </div>

  <div id="toast"></div>

  <script>window.WKW_BASE = <?= json_encode($baseHref) ?>;</script>
  <script src="wiki.js"></script>
</body>

</html>