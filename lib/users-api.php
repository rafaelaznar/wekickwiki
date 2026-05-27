<?php
// ═══════════════════════════════════════════════════════════════════════════
// lib/users-api.php — Shared user-management API endpoint handlers.
//
// Handles all authentication and user CRUD API requests:
//   POST ?action=login          — issue a JWT for valid credentials
//   GET  ?action=get-users      — list admin + guests (admin only)
//   POST ?action=save-users     — update admin account (admin only)
//   POST ?action=add-guest      — create a guest account (admin only)
//   POST ?action=edit-guest     — rename / update a guest (admin only)
//   POST ?action=delete-guest   — remove a guest account (admin only)
//   POST ?action=reset-password — admin resets any user's password
//   POST ?action=change-password — user changes their own password
//
// This file requires lib/auth.php (for JWT functions, load_users(),
// load_auth_settings(), USERS_FILE, JWT_SECRET, TOKEN_TTL, etc.).
// auth.php is required once here so this file can also be used standalone.
// ═══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/auth.php';

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
  $_users = load_users();
  $_idx = find_user_index($_users, $user);
  if ($_idx !== -1 && hash_equals($_users[$_idx]['hash'], $serverHash)) {
    $_u = $_users[$_idx];
    // Block guest logins when the admin has disabled them globally or individually
    if (($_u['role'] ?? '') === 'guest') {
      $_login_cfg = load_auth_settings();
      if (!($_login_cfg['guestLoginEnabled'] ?? true)) {
        json_out(401, ['error' => 'Guest login is currently disabled']);
      }
      if (!($_u['enabled'] ?? true)) {
        json_out(401, ['error' => 'This account is disabled']);
      }
    }
    // Issue a JWT embedding the user's role and display name; valid for TOKEN_TTL seconds from now
    $_name = $_u['name'] ?? $user;
    json_out(200, [
      'token' => jwt_make(['sub' => $user, 'role' => $_u['role'], 'name' => $_name, 'iat' => time(), 'exp' => time() + TOKEN_TTL]),
      'role'  => $_u['role'],
      'name'  => $_name,
    ]);
  }
  // Generic error message: never reveal whether the username or the password was wrong
  json_out(401, ['error' => 'Invalid credentials']);
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
  foreach ($users as $udata) {
    if (!is_array($udata)) continue;
    if (($udata['role'] ?? '') === 'admin') {
      $adminUser = $udata['username'] ?? '';
      $adminName = $udata['name'] ?? '';
    }
    if (($udata['role'] ?? '') === 'guest') {
      $guests[] = [
        'username' => $udata['username'] ?? '',
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
  foreach ($existing as $udata) {
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
  $newUsers = [['username' => $adminUser, 'hash' => $newAdminHash, 'role' => 'admin', 'name' => $adminName]];
  foreach ($existing as $udata) {
    if (!is_array($udata)) continue;
    if (($udata['role'] ?? '') === 'guest') $newUsers[] = $udata;
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
  if (find_user_index($users, $username) !== -1) json_out(409, ['error' => 'Username already exists']);
  $users[] = ['username' => $username, 'hash' => hash_hmac('sha256', $rawHash, JWT_SECRET), 'role' => 'guest', 'name' => $name, 'enabled' => true];
  if (file_put_contents(USERS_FILE, json_encode(array_values($users), JSON_PRETTY_PRINT), LOCK_EX) === false)
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
  $oldIdx = find_user_index($users, $oldUsername);
  if ($oldIdx === -1 || ($users[$oldIdx]['role'] ?? '') !== 'guest')
    json_out(404, ['error' => 'Guest user not found']);
  if ($newUsername !== $oldUsername && find_user_index($users, $newUsername) !== -1)
    json_out(409, ['error' => 'Username already exists']);
  $users[$oldIdx]['username'] = $newUsername;
  $users[$oldIdx]['name']     = $name;
  $users[$oldIdx]['enabled']  = $enabled;
  if (file_put_contents(USERS_FILE, json_encode(array_values($users), JSON_PRETTY_PRINT), LOCK_EX) === false)
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
  $delIdx = find_user_index($users, $username);
  if ($delIdx === -1 || ($users[$delIdx]['role'] ?? '') !== 'guest')
    json_out(404, ['error' => 'Guest user not found']);
  array_splice($users, $delIdx, 1);
  if (file_put_contents(USERS_FILE, json_encode(array_values($users), JSON_PRETTY_PRINT), LOCK_EX) === false)
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
  $rstIdx = find_user_index($users, $username);
  if ($rstIdx === -1) json_out(404, ['error' => 'User not found']);
  $users[$rstIdx]['hash'] = hash_hmac('sha256', $rawHash, JWT_SECRET);
  if (file_put_contents(USERS_FILE, json_encode(array_values($users), JSON_PRETTY_PRINT), LOCK_EX) === false)
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
  $chgIdx = find_user_index($users, $username);
  if ($chgIdx === -1) json_out(404, ['error' => 'User not found']);
  $users[$chgIdx]['hash'] = hash_hmac('sha256', $rawHash, JWT_SECRET);
  if (file_put_contents(USERS_FILE, json_encode(array_values($users), JSON_PRETTY_PRINT), LOCK_EX) === false)
    json_out(500, ['error' => 'Could not write users file']);
  json_out(200, ['ok' => true]);
}
