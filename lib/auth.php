<?php
// ═══════════════════════════════════════════════════════════════════════════
// lib/auth.php — Shared JWT, configuration and user-management helpers.
//
// Can be required from any application that shares users.json and
// settings.json with wekickwiki.  Pre-define USERS_FILE / SETTINGS_FILE
// before requiring this file to override the default paths (which assume
// this lib/ directory sits one level inside the wekickwiki root).
//
// Usage in another app:
//   define('USERS_FILE',    '/path/to/users.json');
//   define('SETTINGS_FILE', '/path/to/settings.json');
//   require_once '/path/to/wekickwiki/lib/auth.php';
// ═══════════════════════════════════════════════════════════════════════════

// ── File paths (define before requiring this file to override) ────────────
if (!defined('USERS_FILE'))    define('USERS_FILE',    dirname(__DIR__) . '/users.json');
if (!defined('SETTINGS_FILE')) define('SETTINGS_FILE', dirname(__DIR__) . '/settings.json');

// ── Bootstrap JWT_SECRET and TOKEN_TTL from settings.json ────────────────
// Values are read once at startup; PHP constants cannot be redefined later.
$_auth_cfg = is_file(SETTINGS_FILE) ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? []) : [];
define('JWT_SECRET', (is_string($_auth_cfg['jwtSecret'] ?? null) && $_auth_cfg['jwtSecret'] !== '') ? $_auth_cfg['jwtSecret'] : 'wkw_2026_S3cur3!K3y#R4nd0m$Phr4s3_xQz7');
define('TOKEN_TTL', (isset($_auth_cfg['tokenTtl']) && is_int($_auth_cfg['tokenTtl']) && $_auth_cfg['tokenTtl'] > 0) ? (int)$_auth_cfg['tokenTtl'] : 3600);
unset($_auth_cfg);

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
// Auth settings
// ═══════════════════════════════════════════════════════════════════════════

// Returns only the authentication-relevant fields from settings.json.
// Unlike load_settings() in index.php this function imposes no wiki-specific
// validation (theme paths, hljsTheme, etc.) so it is safe to call from any
// application that shares the same settings file.
function load_auth_settings(): array
{
    $data = is_file(SETTINGS_FILE) ? (json_decode(file_get_contents(SETTINGS_FILE), true) ?? []) : [];
    return [
        'guestLoginEnabled' => isset($data['guestLoginEnabled']) ? (bool)$data['guestLoginEnabled'] : true,
        'jwtSecret'         => (isset($data['jwtSecret']) && is_string($data['jwtSecret']) && strlen($data['jwtSecret']) >= 16) ? $data['jwtSecret'] : JWT_SECRET,
        'tokenTtl'          => (isset($data['tokenTtl']) && is_int($data['tokenTtl']) && $data['tokenTtl'] >= 60) ? (int)$data['tokenTtl'] : TOKEN_TTL,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// Users
// Passwords are SHA-256'd client-side, then HMAC'd server-side before compare.
// Users are stored in users.json (blocked from HTTP via .htaccess).
// ═══════════════════════════════════════════════════════════════════════════

// Default users (used on first run to seed users.json if it doesn't exist)
$USERS_DEFAULT = [
    ['username' => 'admin', 'hash' => 'f102261abcb0b4fe003994b9e9f2f2efdd64a80b52ba930d401b5a2a694a0e61', 'role' => 'admin', 'name' => 'Administrator'],
    ['username' => 'guest', 'hash' => '18fb145c0a15beae4d671e61688f90624c42e93872b642c346e4fc87f92fbbf4', 'role' => 'guest', 'name' => 'Guest', 'enabled' => true],
];

// Loads the user database from users.json.
// On first run (file absent), seeds the file with built-in defaults using an atomic write.
// Falls back to defaults if the file is corrupt or contains fewer than 2 entries.
// Returns a list (sequential array) of user objects, each containing a 'username' field.
function load_users(): array
{
    global $USERS_DEFAULT;
    // Seed users.json on first run; LOCK_EX prevents race conditions during concurrent writes
    if (!is_file(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode($USERS_DEFAULT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $USERS_DEFAULT;
    }
    $data = json_decode(file_get_contents(USERS_FILE), true);
    // Require a sequential array of at least 2 user objects to consider the file structurally valid
    return (is_array($data) && isset($data[0]) && count($data) >= 2) ? $data : $USERS_DEFAULT;
}

// Returns the index of the user with the given username in the users array, or -1 if not found.
function find_user_index(array $users, string $username): int
{
    foreach ($users as $i => $u) {
        if (is_array($u) && ($u['username'] ?? '') === $username) return $i;
    }
    return -1;
}
