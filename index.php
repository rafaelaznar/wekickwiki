<?php
// ═══════════════════════════════════════════════════════════════════════════
// JWT helpers
// ═══════════════════════════════════════════════════════════════════════════
// Encodes binary data as Base64URL (RFC 4648 §5): replaces '+' with '-' and '/' with '_',
// then strips '=' padding. Produces URL-safe strings suitable for JWT segments.
function b64url(string $d): string
{
  return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}

// Builds a signed JWT (JSON Web Token) using HMAC-SHA256.
// A JWT is three dot-separated Base64URL segments: header.payload.signature.
function jwt_make(array $payload): string
{
  // Encode the standard header declaring the algorithm and token type
  $h = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
  // Encode the payload (claims: sub, role, iat, exp)
  $p = b64url(json_encode($payload));
  // Sign "header.payload" with HMAC-SHA256 using the server secret; raw binary output is then Base64URL-encoded
  $s = b64url(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
  return "$h.$p.$s";
}

// Verifies a JWT token and returns its decoded payload array, or null if invalid/expired.
// Uses hash_equals() for constant-time signature comparison to resist timing-based attacks.
function jwt_verify(?string $token): ?array
{
  if (!$token) return null;
  // A valid JWT must consist of exactly three dot-separated segments
  $parts = explode('.', $token);
  if (count($parts) !== 3) return null;
  [$h, $p, $s] = $parts;
  // Recompute the expected signature and compare in constant time to prevent timing leaks
  if (!hash_equals(b64url(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), $s)) return null;
  // Convert the Base64URL payload back to standard Base64 before decoding JSON
  $data = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
  // Reject if the payload is not a valid array or the token's expiry timestamp has passed
  if (!is_array($data) || ($data['exp'] ?? 0) < time()) return null;
  return $data;
}

// Extracts the Bearer token string from the incoming HTTP Authorization header.
// Falls back to getallheaders() for CGI/FastCGI environments where PHP may not populate $_SERVER.
// Returns the raw token, or null when the header is absent or does not carry a Bearer scheme.
function bearer_token(): ?string
{
  // Primary source: PHP populates HTTP_AUTHORIZATION or REDIRECT_HTTP_AUTHORIZATION in most setups
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  // Fallback for servers where mod_rewrite passes the header under a different key
  if (!$auth && function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
  }
  // Extract the token value that follows the "Bearer " prefix (case-insensitive)
  return preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;
}

// Validates the incoming Bearer JWT and returns its claims array.
// Terminates the request immediately with HTTP 401 if authentication fails.
function require_auth(): array
{
  $claims = jwt_verify(bearer_token());
  if (!$claims) {
    json_out(401, ['error' => 'Unauthorized']);
  }
  return $claims;
}

// Sends an HTTP response with the given status code and JSON-encoded body, then exits.
// Declared with return type 'never' because it always terminates script execution via exit().
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
define('USERS_FILE',    __DIR__ . '/users.json');
define('SETTINGS_FILE', __DIR__ . '/settings.json');
// JWT_SECRET and TOKEN_TTL are read exclusively from settings.json.
$_wkw_cfg = is_file(SETTINGS_FILE) ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? []) : [];
define('JWT_SECRET', (is_string($_wkw_cfg['jwtSecret'] ?? null) && $_wkw_cfg['jwtSecret'] !== '') ? $_wkw_cfg['jwtSecret'] : 'wkw_2026_S3cur3!K3y#R4nd0m$Phr4s3_xQz7');
define('TOKEN_TTL',  (isset($_wkw_cfg['tokenTtl']) && is_int($_wkw_cfg['tokenTtl']) && $_wkw_cfg['tokenTtl'] > 0) ? (int)$_wkw_cfg['tokenTtl'] : 3600);
unset($_wkw_cfg);

// Default users (used on first run to seed users.json if it doesn't exist)
$USERS_DEFAULT = [
  'admin' => ['hash' => 'f102261abcb0b4fe003994b9e9f2f2efdd64a80b52ba930d401b5a2a694a0e61', 'role' => 'admin', 'name' => 'Administrator'],
  'guest' => ['hash' => '18fb145c0a15beae4d671e61688f90624c42e93872b642c346e4fc87f92fbbf4', 'role' => 'guest', 'name' => 'Guest', 'enabled' => true],
];

// Loads the user database from users.json.
// On first run (file absent), seeds the file with built-in defaults using an atomic write.
// Falls back to defaults if the file is corrupt or contains fewer than 2 entries.
function load_users(): array
{
  global $USERS_DEFAULT;
  // Seed users.json on first run; LOCK_EX prevents race conditions during concurrent writes
  if (!is_file(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode($USERS_DEFAULT, JSON_PRETTY_PRINT), LOCK_EX);
    return $USERS_DEFAULT;
  }
  $data = json_decode(file_get_contents(USERS_FILE), true);
  // Require at least 2 entries (admin + guest) to consider the file structurally valid
  return (is_array($data) && count($data) >= 2) ? $data : $USERS_DEFAULT;
}

// Loads and validates wiki settings from settings.json.
// Each field is validated individually; invalid or missing values fall back to built-in defaults
// so the wiki always starts up safely even when the settings file is corrupt or incomplete.
function load_settings(): array
{
  $defaults = ['wikiName' => 'WeKickWiki', 'theme' => 'default.css', 'hljsTheme' => 'highlight-github.min.css', 'codeLineNumbers' => false, 'guestOdtDownload' => true, 'guestToc' => true, 'guestIndex' => true, 'guestLoginEnabled' => true, 'jwtSecret' => 'wkw_2026_S3cur3!K3y#R4nd0m$Phr4s3_xQz7', 'tokenTtl' => 3600];
  if (!is_file(SETTINGS_FILE)) return $defaults;
  $data = json_decode(file_get_contents(SETTINGS_FILE), true);
  if (!is_array($data)) return $defaults;
  // Accept wikiName only when it is a non-empty string
  $name            = (isset($data['wikiName']) && is_string($data['wikiName']) && $data['wikiName'] !== '') ? $data['wikiName'] : $defaults['wikiName'];
  // Validate theme: must match a safe filename pattern AND exist on disk to prevent path traversal
  $theme           = (isset($data['theme']) && is_string($data['theme']) && preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $data['theme']) && is_file(__DIR__ . '/templates/' . $data['theme'])) ? $data['theme'] : $defaults['theme'];
  // Same validation for the highlight.js theme (dots allowed for names like "atom-one-dark.min.css")
  $hljsTheme       = (isset($data['hljsTheme']) && is_string($data['hljsTheme']) && preg_match('/^[a-zA-Z0-9_\-\.]+\.css$/', $data['hljsTheme']) && is_file(__DIR__ . '/vendor/highlight-themes/' . $data['hljsTheme'])) ? $data['hljsTheme'] : $defaults['hljsTheme'];
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
  $dir = __DIR__ . '/templates';
  if (!is_dir($dir)) return ['default.css'];
  $files = glob($dir . '/*.css') ?: [];
  sort($files);
  return array_map('basename', $files);
}

// Returns basenames of all *.css files in vendor/highlight-themes/ (safe filenames only)
function list_hljs_themes(): array
{
  $dir = __DIR__ . '/vendor/highlight-themes';
  if (!is_dir($dir)) return ['highlight-github.min.css'];
  $files = glob($dir . '/*.css') ?: [];
  sort($files);
  return array_map('basename', $files);
}

// Returns basenames of all *.js files in front-plugins/ (safe filenames only)
function front_plugins(): array
{
  $dir = __DIR__ . '/front-plugins';
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

$USERS = load_users();

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
// Base path detection
// ═══════════════════════════════════════════════════════════════════════════
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';

// ═══════════════════════════════════════════════════════════════════════════
// API: Login  POST ?action=login  (no auth required)
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'login') {
  $body = json_decode(file_get_contents('php://input'), true) ?? [];
  // Sanitize username: strip everything except lowercase alphanumerics and underscores
  $user = preg_replace('/[^a-z0-9_]/', '', strtolower($body['user'] ?? ''));
  // Sanitize hash: the client sends a SHA-256 hex digest of the password, strip non-hex chars
  $hash = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $body['hash'] ?? ''));
  // SHA-256 hex output is always exactly 64 characters; reject anything that deviates
  if (strlen($hash) !== 64) json_out(401, ['error' => 'Invalid credentials']);
  // Double-hash pattern: HMAC the client-side SHA-256 with the server secret before comparing.
  // The stored value is therefore never the bare SHA-256, protecting it even if users.json leaks.
  $serverHash = hash_hmac('sha256', $hash, JWT_SECRET);
  if (isset($USERS[$user]) && is_array($USERS[$user]) && hash_equals($USERS[$user]['hash'], $serverHash)) {
    // Block guest logins when the admin has disabled them globally or individually
    if (($USERS[$user]['role'] ?? '') === 'guest') {
      $_login_cfg = load_settings();
      if (!($_login_cfg['guestLoginEnabled'] ?? true)) {
        json_out(401, ['error' => 'Guest login is currently disabled']);
      }
      if (!($USERS[$user]['enabled'] ?? true)) {
        json_out(401, ['error' => 'This account is disabled']);
      }
    }
    // Issue a JWT embedding the user's role; valid for TOKEN_TTL seconds from now
    json_out(200, [
      'token' => jwt_make(['sub' => $user, 'role' => $USERS[$user]['role'], 'iat' => time(), 'exp' => time() + TOKEN_TTL]),
      'role'  => $USERS[$user]['role'],
    ]);
  }
  // Generic error message: never reveal whether the username or the password was wrong
  json_out(401, ['error' => 'Invalid credentials']);
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
  $base = __DIR__ . '/pages/' . trim($p, '/');
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
  $pagesDir = __DIR__ . '/pages';
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
  // Sanitize the page slug: only path-safe characters; dots and spaces are intentionally excluded
  $p    = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $body['page'] ?? '');
  $base = __DIR__ . '/pages/' . trim($p, '/');
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
// API: Get users  GET ?action=get-users  (admin only)
// Returns admin info and array of all guest users (no hashes ever returned).
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-users') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $users     = load_users();
  $adminUser = '';
  $adminName = '';
  $guests    = [];
  foreach ($users as $uname => $udata) {
    if (!is_array($udata)) continue;
    if (($udata['role'] ?? '') === 'admin') {
      $adminUser = $uname;
      $adminName = $udata['name'] ?? '';
    }
    if (($udata['role'] ?? '') === 'guest') {
      $guests[] = [
        'username' => $uname,
        'name'     => $udata['name'] ?? '',
        'enabled'  => (bool)($udata['enabled'] ?? true),
      ];
    }
  }
  json_out(200, [
    'adminUser' => $adminUser,
    'adminName' => $adminName,
    'guests'    => $guests,
  ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Save admin+security  POST ?action=save-users  (admin only)
// Body: { adminUser, adminName, adminHash (sha256hex|null) }
// null hash means "keep existing password". Guest entries are preserved untouched.
// guestLoginEnabled, jwtSecret and tokenTtl are managed exclusively by save-settings.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'save-users') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

  $body      = json_decode(file_get_contents('php://input'), true) ?? [];
  $adminUser = preg_replace('/[^a-z0-9_]/', '', strtolower($body['adminUser'] ?? ''));
  $adminName = trim(substr($body['adminName'] ?? '', 0, 64));

  if (strlen($adminUser) < 2 || strlen($adminUser) > 32) json_out(400, ['error' => 'Admin username must be 2–32 chars (a-z, 0-9, _)']);
  if ($adminName === '') json_out(400, ['error' => 'Admin name cannot be empty']);

  $existing = load_users();

  // Resolve existing admin hash so we can preserve it when no new password is supplied
  $existingAdminHash = '';
  foreach ($existing as $uname => $udata) {
    if (!is_array($udata)) continue;
    if (($udata['role'] ?? '') === 'admin') $existingAdminHash = $udata['hash'];
  }

  $rawAdminHash = $body['adminHash'] ?? null;
  if ($rawAdminHash !== null) {
    $rawAdminHash = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawAdminHash));
    if (strlen($rawAdminHash) !== 64) json_out(400, ['error' => 'Invalid admin password hash']);
    $newAdminHash = hash_hmac('sha256', $rawAdminHash, JWT_SECRET);
  } else {
    $newAdminHash = $existingAdminHash;
  }

  // Rebuild: new admin entry + all existing guest entries preserved intact
  // guestLoginEnabled, jwtSecret and tokenTtl are NOT stored here — they live in settings.json
  $newUsers = [$adminUser => ['hash' => $newAdminHash, 'role' => 'admin', 'name' => $adminName]];
  foreach ($existing as $uname => $udata) {
    if (!is_array($udata)) continue;
    if (($udata['role'] ?? '') === 'guest') $newUsers[$uname] = $udata;
  }

  $written = file_put_contents(USERS_FILE, json_encode($newUsers, JSON_PRETTY_PRINT), LOCK_EX);
  if ($written === false) json_out(500, ['error' => 'Could not write users file']);

  json_out(200, ['ok' => true, 'adminUser' => $adminUser]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Add guest  POST ?action=add-guest  (admin only)
// Body: { username, name, hash (sha256hex) }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'add-guest') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body     = json_decode(file_get_contents('php://input'), true) ?? [];
  $username = preg_replace('/[^a-z0-9_]/', '', strtolower($body['username'] ?? ''));
  $name     = trim(substr($body['name'] ?? '', 0, 64));
  $rawHash  = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $body['hash'] ?? ''));
  if (strlen($username) < 2 || strlen($username) > 32) json_out(400, ['error' => 'Username must be 2–32 chars (a-z, 0-9, _)']);
  if ($name === '') json_out(400, ['error' => 'Name cannot be empty']);
  if (strlen($rawHash) !== 64) json_out(400, ['error' => 'A password is required']);
  $users = load_users();
  if (isset($users[$username])) json_out(409, ['error' => 'Username already exists']);
  $users[$username] = ['hash' => hash_hmac('sha256', $rawHash, JWT_SECRET), 'role' => 'guest', 'name' => $name, 'enabled' => true];
  if (file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX) === false)
    json_out(500, ['error' => 'Could not write users file']);
  json_out(201, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Edit guest  POST ?action=edit-guest  (admin only)
// Body: { oldUsername, newUsername, name, enabled }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'edit-guest') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body        = json_decode(file_get_contents('php://input'), true) ?? [];
  $oldUsername = preg_replace('/[^a-z0-9_]/', '', strtolower($body['oldUsername'] ?? ''));
  $newUsername = preg_replace('/[^a-z0-9_]/', '', strtolower($body['newUsername'] ?? ''));
  $name        = trim(substr($body['name'] ?? '', 0, 64));
  $enabled     = isset($body['enabled']) ? (bool)$body['enabled'] : true;
  if (strlen($newUsername) < 2 || strlen($newUsername) > 32) json_out(400, ['error' => 'Username must be 2–32 chars (a-z, 0-9, _)']);
  if ($name === '') json_out(400, ['error' => 'Name cannot be empty']);
  $users = load_users();
  if (!isset($users[$oldUsername]) || !is_array($users[$oldUsername]) || ($users[$oldUsername]['role'] ?? '') !== 'guest')
    json_out(404, ['error' => 'Guest user not found']);
  if ($newUsername !== $oldUsername && isset($users[$newUsername]))
    json_out(409, ['error' => 'Username already exists']);
  $entry = $users[$oldUsername];
  $entry['name']    = $name;
  $entry['enabled'] = $enabled;
  unset($users[$oldUsername]);
  $users[$newUsername] = $entry;
  if (file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX) === false)
    json_out(500, ['error' => 'Could not write users file']);
  json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Delete guest  POST ?action=delete-guest  (admin only)
// Body: { username }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete-guest') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body     = json_decode(file_get_contents('php://input'), true) ?? [];
  $username = preg_replace('/[^a-z0-9_]/', '', strtolower($body['username'] ?? ''));
  $users    = load_users();
  if (!isset($users[$username]) || !is_array($users[$username]) || ($users[$username]['role'] ?? '') !== 'guest')
    json_out(404, ['error' => 'Guest user not found']);
  unset($users[$username]);
  if (file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX) === false)
    json_out(500, ['error' => 'Could not write users file']);
  json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Reset password  POST ?action=reset-password  (admin only)
// Body: { username, hash (sha256hex) }
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'reset-password') {
  $claims = require_auth();
  if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
  $body     = json_decode(file_get_contents('php://input'), true) ?? [];
  $username = preg_replace('/[^a-z0-9_]/', '', strtolower($body['username'] ?? ''));
  $rawHash  = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $body['hash'] ?? ''));
  if ($username === '' || strlen($rawHash) !== 64) json_out(400, ['error' => 'Invalid request']);
  $users = load_users();
  if (!isset($users[$username]) || !is_array($users[$username])) json_out(404, ['error' => 'User not found']);
  $users[$username]['hash'] = hash_hmac('sha256', $rawHash, JWT_SECRET);
  if (file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX) === false)
    json_out(500, ['error' => 'Could not write users file']);
  json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Change own password  POST ?action=change-password  (any authenticated user)
// Body: { hash (sha256hex of new password) }
// Uses the JWT sub claim — a guest can only change their own password.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'change-password') {
  $claims   = require_auth();
  $username = $claims['sub'] ?? '';
  $body     = json_decode(file_get_contents('php://input'), true) ?? [];
  $rawHash  = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $body['hash'] ?? ''));
  if ($username === '' || strlen($rawHash) !== 64) json_out(400, ['error' => 'Invalid request']);
  $users = load_users();
  if (!isset($users[$username]) || !is_array($users[$username])) json_out(404, ['error' => 'User not found']);
  $users[$username]['hash'] = hash_hmac('sha256', $rawHash, JWT_SECRET);
  if (file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT), LOCK_EX) === false)
    json_out(500, ['error' => 'Could not write users file']);
  json_out(200, ['ok' => true]);
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
  if (!is_file(__DIR__ . '/templates/' . $theme)) {
    json_out(400, ['error' => 'Theme file not found']);
  }
  if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.css$/', $hljsTheme)) {
    json_out(400, ['error' => 'Invalid highlight theme filename']);
  }
  if (!is_file(__DIR__ . '/vendor/highlight-themes/' . $hljsTheme)) {
    json_out(400, ['error' => 'Highlight theme file not found']);
  }
  // Merge into the existing settings file so unmanaged keys are preserved across saves
  $existing = is_file(SETTINGS_FILE) ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? []) : [];
  if (!is_array($existing)) $existing = [];
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
    $rawAdminHash = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $body['adminHash'] ?? ''));
    if (strlen($rawAdminHash) !== 64) {
      json_out(400, ['error' => 'When changing the JWT secret, a new admin password is required']);
    }
    // Re-hash the admin password with the new secret and persist it
    $usersData = load_users();
    foreach ($usersData as $uname => $udata) {
      if (is_array($udata) && ($udata['role'] ?? '') === 'admin') {
        $usersData[$uname]['hash'] = hash_hmac('sha256', $rawAdminHash, $jwtSecret);
        break;
      }
    }
    file_put_contents(USERS_FILE, json_encode($usersData, JSON_PRETTY_PRINT), LOCK_EX);
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
  if (file_put_contents(SETTINGS_FILE, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX) === false) {
    json_out(500, ['error' => 'Could not write settings file']);
  }
  json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// API: Get plugin state  GET ?action=get-plugin-state  (any authenticated user)
// Returns the list of disabled plugin ids from settings.json.
// ═══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get-plugin-state') {
  require_auth();
  $data = is_file(SETTINGS_FILE) ? json_decode(file_get_contents(SETTINGS_FILE), true) : [];
  $disabled = (is_array($data) && is_array($data['disabledPlugins'] ?? null))
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
  $existing = is_file(SETTINGS_FILE) ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? []) : [];
  if (!is_array($existing)) $existing = [];
  $existing['disabledPlugins'] = $disabled;
  if (file_put_contents(SETTINGS_FILE, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX) === false) {
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
  $pagesDir = __DIR__ . '/pages';
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

// Load validated settings before rendering the HTML shell; values are PHP-escaped on output.
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
  <script src="vendor/marked.min.js"></script>
  <script src="vendor/highlight.min.js"></script>
  <link id="hljs-theme-link" rel="stylesheet" href="vendor/highlight-themes/<?= htmlspecialchars($settings['hljsTheme'], ENT_QUOTES) ?>">
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
        <button class="btn" id="mobile-menu-btn" aria-label="Menu" aria-expanded="false" onclick="toggleMobileMenu()"><svg viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div id="header-buttons">
        <button class="btn" id="toc-btn" title="Table of contents" aria-label="Table of contents" style="display:none" onclick="toggleToc()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="3" y1="5" x2="21" y2="5" />
            <line x1="6" y1="10" x2="21" y2="10" />
            <line x1="10" y1="15" x2="21" y2="15" />
            <line x1="6" y1="20" x2="21" y2="20" />
          </svg></button>
        <button class="btn" id="index-btn" title="Index" aria-label="Index" style="display:none" onclick="toggleIndex()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="21" y1="5" x2="3" y2="5" />
            <line x1="18" y1="10" x2="3" y2="10" />
            <line x1="14" y1="15" x2="3" y2="15" />
            <line x1="18" y1="20" x2="3" y2="20" />
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
        <button class="btn" id="plugins-btn" title="Plugins" aria-label="Plugins" style="display:none" onclick="togglePluginsPanel()"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z" />
            <line x1="16" y1="8" x2="2" y2="22" />
            <line x1="17.5" y1="15" x2="9" y2="15" />
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
        <button class="btn" id="change-pass-btn" title="Change my password" aria-label="Change my password" style="display:none" onclick="toggleChangePasswordPanel()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg></button>
        </div>
      </div>
    </header>
    <nav id="nav"></nav>
    <main id="content"></main>

    <!-- Plugins panel -->
    <div id="plugins-overlay" onclick="togglePluginsPanel()"></div>
    <div id="plugins-panel">
      <h3>Plugins <button class="close-btn" onclick="togglePluginsPanel()">&times;</button></h3>
      <div id="plugins-list"></div>
    </div>

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
      <div id="users-grid"></div>
      <div id="guest-add-form" style="display:none">
        <fieldset>
          <legend>Add guest</legend>
          <label>Username
            <input type="text" id="guest-add-username" autocomplete="off" maxlength="32" pattern="[a-z0-9_]+" placeholder="username">
          </label>
          <label>Name
            <input type="text" id="guest-add-name" autocomplete="off" maxlength="64" placeholder="Display name">
          </label>
          <label>Password
            <input type="password" id="guest-add-pass" autocomplete="new-password" placeholder="••••••••">
          </label>
          <label>Confirm password
            <input type="password" id="guest-add-pass2" autocomplete="new-password" placeholder="••••••••">
          </label>
          <div style="display:flex;gap:.5rem;margin-top:.6rem;align-items:center">
            <span id="guest-add-status" style="flex:1;font-size:.8rem;color:#c0392b"></span>
            <button type="button" class="btn" onclick="hideAddGuestForm()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitAddGuest()">Add</button>
          </div>
        </fieldset>
      </div>
      <button type="button" class="btn" id="guest-add-btn" onclick="showAddGuestForm()" style="margin-top:.75rem;width:100%">+ Add guest user</button>
    </div>

    <!-- Change password panel (guest self-service) -->
    <div id="change-password-overlay" onclick="toggleChangePasswordPanel()"></div>
    <div id="change-password-panel">
      <h3>Change my password <button class="close-btn" onclick="toggleChangePasswordPanel()">&times;</button></h3>
      <form id="change-password-form" novalidate>
        <label>New password
          <input type="password" id="change-pass-new" autocomplete="new-password" placeholder="••••••••">
        </label>
        <label>Confirm new password
          <input type="password" id="change-pass-confirm" autocomplete="new-password" placeholder="••••••••">
        </label>
        <div id="change-password-form-actions">
          <span id="change-password-status"></span>
          <button type="button" class="btn" onclick="toggleChangePasswordPanel()">Cancel</button>
          <button type="submit" class="btn btn-primary">Change password</button>
        </div>
      </form>
    </div>

    <!-- Reset password dialog (admin → any user) -->
    <div id="reset-password-overlay"></div>
    <div id="reset-password-dialog">
      <h4 id="reset-password-title">Reset password</h4>
      <label>New password
        <input type="password" id="reset-pass-new" autocomplete="new-password" placeholder="••••••••">
      </label>
      <label>Confirm password
        <input type="password" id="reset-pass-confirm" autocomplete="new-password" placeholder="••••••••">
      </label>
      <div id="reset-password-actions">
        <span id="reset-password-status"></span>
        <button type="button" class="btn" id="reset-password-cancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="reset-password-ok">Reset</button>
      </div>
    </div>
    <!-- Settings panel -->
    <div id="settings-overlay" onclick="toggleSettingsPanel()"></div>
    <!-- Confirm delete dialog -->
    <div id="confirm-delete-overlay"></div>
    <div id="confirm-delete-dialog" role="dialog" aria-modal="true">
      <p id="confirm-delete-msg"></p>
      <p class="confirm-delete-subtitle">This action cannot be undone.</p>
      <div class="confirm-delete-actions">
        <button class="btn" id="confirm-delete-cancel">Cancel</button>
        <button class="btn btn-danger" id="confirm-delete-ok">Delete</button>
      </div>
    </div>
    <div id="settings-panel">
      <h3>Settings <button class="close-btn" onclick="toggleSettingsPanel()">&times;</button></h3>
      <form id="settings-form" novalidate>
        <label>Wiki name
          <input type="text" id="settings-wiki-name" autocomplete="off" maxlength="64">
        </label>
        <label>Theme
          <select id="settings-theme"></select>
        </label>
        <label>Code highlight theme
          <select id="settings-hljs-theme"></select>
        </label>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.85rem">
          <input type="checkbox" id="settings-code-line-numbers" style="width:auto;cursor:pointer">
          <label for="settings-code-line-numbers" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Show line numbers in code blocks</label>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.85rem">
          <input type="checkbox" id="settings-guest-odt-download" style="width:auto;cursor:pointer">
          <label for="settings-guest-odt-download" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest to download ODT</label>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.85rem">
          <input type="checkbox" id="settings-guest-toc" style="width:auto;cursor:pointer">
          <label for="settings-guest-toc" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest to view Table of contents</label>
        </div>
        <div style="display:flex;align-items:center;gap:.6rem;margin-top:.85rem">
          <input type="checkbox" id="settings-guest-index" style="width:auto;cursor:pointer">
          <label for="settings-guest-index" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest to view Index</label>
        </div>
        <fieldset style="border:1px solid #e0e0e0;border-radius:6px;padding:.75rem 1rem .85rem;margin-top:1rem">
          <legend style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#555;padding:0 .35rem">Security</legend>
          <div style="display:flex;align-items:center;gap:.6rem;margin-top:.25rem">
            <input type="checkbox" id="settings-guest-login-enabled" style="width:auto;cursor:pointer">
            <label for="settings-guest-login-enabled" style="margin:0;font-weight:600;font-size:.82rem;color:#333;cursor:pointer">Allow guest logins</label>
          </div>
          <label style="margin-top:.85rem">JWT Secret <span class="hint">(leave blank to keep)</span>
            <input type="text" id="settings-jwt-secret" autocomplete="off" minlength="16" maxlength="128" placeholder="leave blank to keep" style="font-family:monospace;font-size:.85rem">
          </label>
          <label id="settings-admin-pass-label" style="display:none;margin-top:.6rem">Admin password <span class="hint">(required when changing JWT secret)</span>
            <input type="password" id="settings-admin-pass" autocomplete="new-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
          </label>
          <label style="margin-top:.6rem">Token TTL <span class="hint">(seconds, 60&#x2013;86400)</span>
            <input type="number" id="settings-token-ttl" min="60" max="86400" step="60">
          </label>
        </fieldset>
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
    <a href="https://github.com/rafaelaznar/wekickwiki"><?= htmlspecialchars($settings['wikiName']) ?> 2026 v2 | MIT Licensed | By Rafael Aznar</a>
  </div>

  <div id="toast"></div>

  <?php // Expose the base path and code-line-numbers flag to JavaScript before loading the main app 
  ?>
  <script>
    window.WKW_BASE = <?= json_encode($baseHref) ?>;
    window.WKW_CODE_LINE_NUMBERS = <?= $settings['codeLineNumbers'] ? 'true' : 'false' ?>;
    window.WKW_GUEST_ODT_DOWNLOAD = <?= $settings['guestOdtDownload'] ? 'true' : 'false' ?>;
    window.WKW_GUEST_TOC   = <?= $settings['guestToc']   ? 'true' : 'false' ?>;
    window.WKW_GUEST_INDEX = <?= $settings['guestIndex'] ? 'true' : 'false' ?>;
  </script>
  <script src="wiki.js?v=<?= filemtime(__DIR__ . '/wiki.js') ?>"></script>
  <?php // Dynamically inject each enabled front-end plugin as a <script> tag 
  ?>
  <?php foreach (front_plugins() as $pf): ?>
    <script src="front-plugins/<?= htmlspecialchars($pf, ENT_QUOTES) ?>"></script>
  <?php endforeach; ?>
</body>

</html>