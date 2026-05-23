<?php
// ═══════════════════════════════════════════════════════════════════════════
// lib/wiki-api.php — Wiki-specific helper functions and API endpoint handlers.
//
// Provides:
//   · load_settings() / list_templates() / list_hljs_themes()
//   · front_plugins() / clear_dir_contents()
//   · GET  ?page=...              — read a wiki page (Markdown)
//   · GET  ?action=index          — list all pages
//   · POST ?action=delete         — delete a page
//   · POST ?action=save           — create / update a page
//   · GET  ?action=backup         — stream all pages as a backup file
//   · POST ?action=restore        — wipe & restore from backup
//   · GET  ?action=get-settings   — return current settings
//   · POST ?action=save-settings  — persist settings
//   · GET  ?action=get-templates  — list CSS theme files
//   · GET  ?action=get-hljs-themes — list highlight.js themes
//   · GET  ?action=get-plugin-state  — disabled plugin ids
//   · POST ?action=save-plugin-state — update disabled plugin ids
//
// Requires lib/auth.php (automatically required below if not already loaded).
// ═══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/data.php';

// Loads and validates wiki settings from settings.json.
// Each field is validated individually; invalid or missing values fall back to built-in defaults
// so the wiki always starts up safely even when the settings file is corrupt or incomplete.
function load_settings(): array
{
  $defaults = ['wikiName' => 'WeKickWiki', 'theme' => 'default.css', 'hljsTheme' => 'highlight-github.min.css', 'codeLineNumbers' => false, 'guestOdtDownload' => true, 'guestToc' => true, 'guestIndex' => true, 'guestLoginEnabled' => true, 'jwtSecret' => 'wkw_2026_S3cur3!K3y#R4nd0m$Phr4s3_xQz7', 'tokenTtl' => 3600];
  $data = data_read(SETTINGS_FILE);
  if (empty($data)) return $defaults;
  // Accept wikiName only when it is a non-empty string
  $name            = (isset($data['wikiName']) && is_string($data['wikiName']) && $data['wikiName'] !== '') ? $data['wikiName'] : $defaults['wikiName'];
  // Validate theme: must match a safe filename pattern AND exist on disk to prevent path traversal
  $theme           = (isset($data['theme']) && is_string($data['theme']) && preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $data['theme']) && is_file(dirname(__DIR__) . '/templates/' . $data['theme'])) ? $data['theme'] : $defaults['theme'];
  // Same validation for the highlight.js theme (dots allowed for names like "atom-one-dark.min.css")
  $hljsTheme       = (isset($data['hljsTheme']) && is_string($data['hljsTheme']) && preg_match('/^[a-zA-Z0-9_\-\.]+\.css$/', $data['hljsTheme']) && is_file(dirname(__DIR__) . '/vendor/highlight-themes/' . $data['hljsTheme'])) ? $data['hljsTheme'] : $defaults['hljsTheme'];
  $codeLineNumbers = isset($data['codeLineNumbers']) ? (bool)$data['codeLineNumbers'] : $defaults['codeLineNumbers'];
  $guestOdtDownload = isset($data['guestOdtDownload']) ? (bool)$data['guestOdtDownload'] : $defaults['guestOdtDownload'];
  $guestToc   = isset($data['guestToc'])   ? (bool)$data['guestToc']   : $defaults['guestToc'];
  $guestIndex = isset($data['guestIndex']) ? (bool)$data['guestIndex'] : $defaults['guestIndex'];
  $guestLoginEnabled = isset($data['guestLoginEnabled']) ? (bool)$data['guestLoginEnabled'] : $defaults['guestLoginEnabled'];
  $jwtSecret = (isset($data['jwtSecret']) && is_string($data['jwtSecret']) && strlen($data['jwtSecret']) >= 16) ? $data['jwtSecret'] : $defaults['jwtSecret'];
  $tokenTtl  = (isset($data['tokenTtl'])  && is_int($data['tokenTtl'])  && $data['tokenTtl'] >= 60)            ? (int)$data['tokenTtl'] : $defaults['tokenTtl'];
  return ['wikiName' => $name, 'theme' => $theme, 'hljsTheme' => $hljsTheme, 'codeLineNumbers' => $codeLineNumbers, 'guestOdtDownload' => $guestOdtDownload, 'guestToc' => $guestToc, 'guestIndex' => $guestIndex, 'guestLoginEnabled' => $guestLoginEnabled, 'jwtSecret' => $jwtSecret, 'tokenTtl' => $tokenTtl];
}

function list_templates(): array
{
  $dir = dirname(__DIR__) . '/templates';
  if (!is_dir($dir)) return ['default.css'];
  $files = glob($dir . '/*.css') ?: [];
  sort($files);
  return array_map('basename', $files);
}

// Returns basenames of all *.css files in vendor/highlight-themes/ (safe filenames only)
function list_hljs_themes(): array
{
  $dir = dirname(__DIR__) . '/vendor/highlight-themes';
  if (!is_dir($dir)) return ['highlight-github.min.css'];
  $files = glob($dir . '/*.css') ?: [];
  sort($files);
  return array_map('basename', $files);
}

// Returns basenames of all *.js files in front-plugins/ (safe filenames only)
function front_plugins(): array
{
  $dir = dirname(__DIR__) . '/front-plugins';
  if (!is_dir($dir)) return [];
  $files = glob($dir . '/*.js') ?: [];
  sort($files);
  // Whitelist filenames to alphanumeric, hyphens, and underscores only.
  // This prevents path traversal or script injection when the name is rendered in a <script src> tag.
  return array_values(array_filter(
    array_map('basename', $files),
    fn($f) => (bool) preg_match('/^[a-zA-Z0-9_\-]+\.js$/', $f)
  ));
}

// ═══════════════════════════════════════════════════════════════════════════
// Helper: delete all contents of a directory but keep the directory itself.
// Returns true on success, false if any entry could not be removed.
// Uses @ to suppress PHP warnings; caller checks the return value.
// ═══════════════════════════════════════════════════════════════════════════
function clear_dir_contents(string $dir): bool
{
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
// API: Read page  GET ?page=...  (any authenticated user)
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['page'])) {
  $claims = require_auth();

  // If format=fodt is requested, check if guest is allowed to download
  if (($_GET['format'] ?? '') === 'fodt') {
    $settings = load_settings();
    if (($claims['role'] ?? '') === 'guest' && !($settings['guestOdtDownload'] ?? true)) {
      json_out(403, ['error' => 'Guest ODT downloads are disabled']);
    }
  }

  // Strip all characters except path-safe ones to prevent directory traversal attacks
  $p    = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $_GET['page']);
  // Trim leading/trailing slashes so the result is always relative to pages/
  $base = dirname(__DIR__) . '/pages/' . trim($p, '/');
  $f    = $base . '.md';
  // Return raw Markdown; rendering is handled client-side by marked.js
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
  $pagesDir = dirname(__DIR__) . '/pages';
  $result   = [];
  // RecursiveIteratorIterator walks the entire pages/ tree, including nested subdirectories
  $iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'md') {
      // Build a forward-slash relative path (normalises Windows backslashes) and strip the pages/ prefix
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
  $base = dirname(__DIR__) . '/pages/' . trim($p, '/');
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
  // Sanitize the page slug: only path-safe characters; dots and spaces are intentionally excluded
  $p    = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $body['page'] ?? '');
  $base = dirname(__DIR__) . '/pages/' . trim($p, '/');
  $f = $base . '.md';
  $dir = dirname($f);
  // Auto-create intermediate directories (e.g. pages/docs/guide/) when saving a nested page
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  // Wrap the write in output buffering so any PHP permission warnings don't corrupt the JSON response
  ob_start();
  $written = @file_put_contents($f, $body['content'] ?? '');
  ob_end_clean();
  if ($written === false) {
    json_out(500, [
      'error' =>
      'Could not write page to disk. ' .
        'Check that the web server user has write permission on pages/. ' .
        'Hint: run "chown -R www-data:www-data pages/" inside the container.'
    ]);
  }
  json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Backup  GET ?action=backup  (admin only)
// Streams all pages as a single structured text file for download.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'backup') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

  $pagesDir = dirname(__DIR__) . '/pages';
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
    // Compute the page slug (e.g. "docs/guide/intro") from the full filesystem path
    $rel  = substr($f, strlen($pagesDir) + 1);
    $page = preg_replace('/\.md$/', '', $rel);
    // Each page section begins with an ===PAGE=== marker that the restore endpoint uses to split pages
    echo "\n===PAGE=== $page\n";
    $content = file_get_contents($f);
    echo $content;
    // Ensure content ends with a newline so the next marker starts on its own line
    if (substr($content, -1) !== "\n") echo "\n";
  }
  exit;
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
// API: Get hljs themes  GET ?action=get-hljs-themes  (admin only)
// Lists all .css files found in the vendor/highlight-themes/ directory.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-hljs-themes') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  json_out(200, ['themes' => list_hljs_themes()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Save settings  POST ?action=save-settings  (admin only)
// Body: { wikiName: string, theme: string, hljsTheme: string, codeLineNumbers: bool, guestOdtDownload: bool, guestToc: bool, guestIndex: bool }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-settings') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body             = json_decode(file_get_contents('php://input'), true) ?? [];
  $wikiName         = trim($body['wikiName'] ?? '');
  $theme            = basename($body['theme'] ?? '');
  $hljsTheme        = basename($body['hljsTheme'] ?? '');
  $codeLineNumbers  = isset($body['codeLineNumbers']) ? (bool)$body['codeLineNumbers'] : false;
  $guestOdtDownload = isset($body['guestOdtDownload']) ? (bool)$body['guestOdtDownload'] : true;
  $guestToc         = isset($body['guestToc'])         ? (bool)$body['guestToc']         : true;
  $guestIndex       = isset($body['guestIndex'])       ? (bool)$body['guestIndex']       : true;
  $guestLoginEnabled = isset($body['guestLoginEnabled']) ? (bool)$body['guestLoginEnabled'] : true;
  // JWT Secret: blank = keep existing; non-blank requires admin password to re-hash with new secret
  $jwtSecret = trim($body['jwtSecret'] ?? '');
  $tokenTtl  = isset($body['tokenTtl']) ? (int)$body['tokenTtl'] : 0;
  if ($wikiName === '' || strlen($wikiName) > 64) {
    json_out(400, ['error' => 'Wiki name must be between 1 and 64 characters']);
  }
  if (!preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $theme)) {
    json_out(400, ['error' => 'Invalid theme filename']);
  }
  if (!is_file(dirname(__DIR__) . '/templates/' . $theme)) {
    json_out(400, ['error' => 'Theme file not found']);
  }
  if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.css$/', $hljsTheme)) {
    json_out(400, ['error' => 'Invalid highlight theme filename']);
  }
  if (!is_file(dirname(__DIR__) . '/vendor/highlight-themes/' . $hljsTheme)) {
    json_out(400, ['error' => 'Highlight theme file not found']);
  }
  // Merge into the existing settings file so unmanaged keys are preserved across saves
  $existing = data_read(SETTINGS_FILE);
  $existing['wikiName']        = $wikiName;
  $existing['theme']           = $theme;
  $existing['hljsTheme']       = $hljsTheme;
  $existing['codeLineNumbers'] = $codeLineNumbers;
  $existing['guestOdtDownload'] = $guestOdtDownload;
  $existing['guestToc']         = $guestToc;
  $existing['guestIndex']       = $guestIndex;
  $existing['guestLoginEnabled'] = $guestLoginEnabled;
  if ($jwtSecret !== '') {
    if (strlen($jwtSecret) < 16 || strlen($jwtSecret) > 128) {
      json_out(400, ['error' => 'JWT secret must be between 16 and 128 characters']);
    }
    // Block secret change if guest users exist — their hashes would stop working
    $usersData = load_users();
    foreach ($usersData as $uname => $udata) {
      if (is_array($udata) && ($udata['role'] ?? '') === 'guest') {
        json_out(400, ['error' => 'Cannot change the JWT secret while guest users exist. Delete all guests first.']);
      }
    }
    $rawAdminHash = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $body['adminHash'] ?? ''));
    if (strlen($rawAdminHash) !== 64) {
      json_out(400, ['error' => 'When changing the JWT secret, a new admin password is required']);
    }
    // Re-hash the admin password with the new secret and persist it
    foreach ($usersData as $uname => $udata) {
      if (is_array($udata) && ($udata['role'] ?? '') === 'admin') {
        $usersData[$uname]['hash'] = hash_hmac('sha256', $rawAdminHash, $jwtSecret);
        break;
      }
    }
    data_write(USERS_FILE, $usersData);
    $existing['jwtSecret'] = $jwtSecret;
  } else {
    $existing['jwtSecret'] = (isset($existing['jwtSecret']) && is_string($existing['jwtSecret']) && strlen($existing['jwtSecret']) >= 16)
      ? $existing['jwtSecret'] : JWT_SECRET;
  }
  if ($tokenTtl >= 60 && $tokenTtl <= 86400) {
    $existing['tokenTtl'] = $tokenTtl;
  } elseif (!isset($existing['tokenTtl'])) {
    $existing['tokenTtl'] = 3600;
  }
  data_write(SETTINGS_FILE, $existing);
  json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Get plugin state  GET ?action=get-plugin-state  (any authenticated user)
// Returns the list of disabled plugin ids from settings.json.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-plugin-state') {
  require_auth();
  $data = data_read(SETTINGS_FILE);
  $disabled = (is_array($data['disabledPlugins'] ?? null))
    ? array_values(array_filter($data['disabledPlugins'], fn($v) => is_string($v) && preg_match('/^[a-zA-Z0-9_\-]+$/', $v)))
    : [];
  json_out(200, ['disabled' => $disabled]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Save plugin state  POST ?action=save-plugin-state  (admin only)
// Body: { disabled: string[] }  — array of disabled plugin ids
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-plugin-state') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body     = json_decode(file_get_contents('php://input'), true) ?? [];
  $disabled = array_values(array_filter(
    $body['disabled'] ?? [],
    fn($v) => is_string($v) && preg_match('/^[a-zA-Z0-9_\-]+$/', $v)
  ));
  $existing = data_read(SETTINGS_FILE);
  $existing['disabledPlugins'] = $disabled;
  data_write(SETTINGS_FILE, $existing);
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

  // Parse backup: split on ===PAGE=== markers using a simple line-by-line accumulation loop.
  // Lines before the first marker (e.g. comment header lines) are silently discarded.
  $pages   = [];
  $curPage = null;
  $curLines = [];

  foreach (explode("\n", $raw) as $line) {
    if (preg_match('/^===PAGE=== (.+)$/', $line, $m)) {
      // Flush the previously accumulated page block before starting the next one
      if ($curPage !== null) {
        $pages[$curPage] = implode("\n", $curLines);
      }
      // Sanitize path: same rules as all other endpoints + explicit path traversal check
      $p = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', trim($m[1]));
      $p = trim($p, '/');
      // Reject empty or traversal paths even after sanitization (defence-in-depth)
      if ($p === '' || strpos($p, '..') !== false) {
        json_out(400, ['error' => 'Invalid page path in backup: ' . htmlspecialchars(trim($m[1]))]);
      }
      $curPage  = $p;
      $curLines = [];
    } else {
      // Accumulate content lines for the current page
      if ($curPage !== null) {
        $curLines[] = $line;
      }
    }
  }
  // Flush the final page block (there is no trailing ===PAGE=== marker after the last page)
  if ($curPage !== null) {
    $pages[$curPage] = implode("\n", $curLines);
  }

  if (empty($pages)) {
    json_out(400, ['error' => 'No valid pages found in backup file']);
  }

  // Clear all existing pages (keep the pages/ directory itself so we don't
  // need write permission on its parent directory).
  // Output buffering ensures PHP warnings never corrupt the JSON response.
  $pagesDir = dirname(__DIR__) . '/pages';
  if (!is_dir($pagesDir)) @mkdir($pagesDir, 0755, true);
  ob_start();
  $cleared = clear_dir_contents($pagesDir);
  ob_end_clean();
  if (!$cleared) {
    json_out(500, [
      'error' =>
      'Cannot clear pages/ directory contents. ' .
        'Make sure the web server user has write permission on all files inside pages/. ' .
        'Hint: run "chown -R www-data:www-data pages/" inside the container.'
    ]);
  }

  // Recreate pages from backup; write each .md file and auto-create subdirectories as needed
  $written = 0;
  foreach ($pages as $page => $content) {
    $f   = $pagesDir . '/' . $page . '.md';
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // Strip the trailing newline added by the parser's line-joining
    $content = rtrim($content, "\n");
    if (@file_put_contents($f, $content) === false) {
      json_out(500, [
        'error' =>
        'Could not write page: ' . $page . '. ' .
          'Check web server write permissions on pages/.'
      ]);
    }
    $written++;
  }

  json_out(200, ['ok' => true, 'pages' => $written]);
}
