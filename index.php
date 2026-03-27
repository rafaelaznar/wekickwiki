<?php
// ═══════════════════════════════════════════════════════════════════════════
// JWT helpers
// ═══════════════════════════════════════════════════════════════════════════
function b64url(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

function jwt_make(array $payload): string {
    $h = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = b64url(json_encode($payload));
    $s = b64url(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    return "$h.$p.$s";
}

function jwt_verify(?string $token): ?array {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    if (!hash_equals(b64url(hash_hmac('sha256', "$h.$p", JWT_SECRET, true)), $s)) return null;
    $data = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    if (!is_array($data) || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}

function bearer_token(): ?string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    return preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;
}

function require_auth(): array {
    $claims = jwt_verify(bearer_token());
    if (!$claims) { json_out(401, ['error' => 'Unauthorized']); }
    return $claims;
}

function json_out(int $code, array $data): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// Config
// Passwords are SHA-256'd client-side, then HMAC'd server-side before compare.
// To regenerate a hash:
//   php -r "define('S','<JWT_SECRET>'); echo hash_hmac('sha256', hash('sha256','<PASSWORD>'), S);"
// ═══════════════════════════════════════════════════════════════════════════
define('JWT_SECRET', 'wkw_2026_S3cur3!K3y#R4nd0m$Phr4s3_xQz7');
define('TOKEN_TTL',  3600); // 1 hour

$USERS = [
    // admin:  password = admin123
    'admin'  => ['hash' => 'f102261abcb0b4fe003994b9e9f2f2efdd64a80b52ba930d401b5a2a694a0e61', 'role' => 'admin'],
    // guest: password = guest123
    'guest'  => ['hash' => '18fb145c0a15beae4d671e61688f90624c42e93872b642c346e4fc87f92fbbf4', 'role' => 'guest'],
];

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
    if (isset($USERS[$user]) && hash_equals($USERS[$user]['hash'], $serverHash)) {
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
    if (is_file($f)) { readfile($f); } else { http_response_code(404); echo "# 404\nPage not found."; }
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
    if (is_file($f)) { unlink($f); json_out(200, ['ok' => true]); }
    json_out(404, ['error' => 'Page not found']);
}

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
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $written = file_put_contents($f, $body['content'] ?? '');
    if ($written === false) { json_out(500, ['error' => 'Could not write page to disk']); }
    json_out(200, ['ok' => true]);
}
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WeKickWiki</title>
<base href="<?= htmlspecialchars($baseHref) ?>">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{line-height:1.6;color:#222;
    font: normal 87.5%/1.4 Arial, system-ui,sans-serif;
    /* default font size: 100% => 16px; 93.75% => 15px; 87.5% => 14px; 81.25% => 13px; 75% => 12px */
    -webkit-text-size-adjust: 100%;
}

/* ── Login screen ── */
#login-screen{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f4f4f5;padding:1rem}
#login-box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:2.5rem 2rem;width:100%;max-width:360px;box-shadow:0 4px 24px rgba(0,0,0,.07)}
#login-box h2{font-size:1.3rem;margin-bottom:1.5rem;text-align:center}
#login-box label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.9rem}
#login-box input{display:block;width:100%;margin-top:.25rem;padding:.55rem .75rem;border:1px solid #ccc;border-radius:4px;font-size:1rem;outline:none}
#login-box input:focus{border-color:#05c}
#login-box button{width:100%;margin-top:1.2rem;padding:.65rem;background:#05c;color:#fff;border:none;border-radius:4px;font-size:1rem;cursor:pointer;font-weight:600;display:flex;align-items:center;justify-content:center}
#login-box button:hover{background:#004ab3}
#login-error{margin-top:.75rem;font-size:.85rem;color:#c00;text-align:center;min-height:1.2rem}

/* ── Wiki screen ── */
#wiki-screen{max-width:860px;margin:0 auto;padding:1rem 1.5rem}
header{border-bottom:2px solid #222;padding-bottom:.5rem;margin-bottom:.75rem;display:flex;align-items:center;justify-content:space-between}
header a{font-size:1.4rem;font-weight:700;color:#111;text-decoration:none}
#header-right{display:flex;gap:.5rem;align-items:center}
#user-badge{font-size:.78rem;color:#666;padding:.2rem .6rem;background:#f0f0f0;border-radius:3px}
nav{font-size:.85rem;color:#666;margin-bottom:1.5rem;min-height:1.2rem}
nav a{color:#05c;text-decoration:none}
nav a:hover{text-decoration:underline}
/*
#content h1{font-size:1.9rem;margin:.25rem 0 .8rem}
#content h2{font-size:1.3rem;margin:1.5rem 0 .4rem;padding-bottom:.2rem;border-bottom:1px solid #eee}
#content h3{font-size:1.1rem;margin:1rem 0 .3rem}
*/
#content h1,h2,h3,h4,h5,h6 {font-weight: bold;padding: 0;line-height: 1.2;clear: left; color: #8A0808;font-family: Arial, sans-serif;clear: right;}
#content h1 {font-size: 1.9em;margin: 0 0 0.444em;text-align: center;border-style: solid;border-width: 2px;padding:0.45em 0;margin:0.6em 0 1em 0;background-color:#8A0808;color:#FFF;}
#content h2 {font-size: 1.7em;margin: 1em 0 0.5em;border-bottom-style: double;border-bottom-width: 6px; border-color: #8A0808 ;}
#content h3 {font-size: 1.6em;margin: 0 0 0.888em;}
#content h4 {font-size: 1.25em;margin: 0 0 1.0em;}
#content h5 {font-size: 1em;margin: 0 0 1.1428em;}
#content h6 {font-size: .75em;margin: 0 0 1.333em;}
/* --- */
#content p{margin:.5rem 0}
#content a{color:#05c}
#content ul,#content ol{margin:.4rem 0 .4rem 1.5rem}
#content li{margin:.15rem 0}
#content pre{background:#f5f5f5;padding:.8rem 1rem;border-radius:4px;overflow-x:auto;margin:.8rem 0}
#content code{background:#f0f0f0;padding:.1em .3em;border-radius:3px;font-size:.9em}
#content pre code{background:none;padding:0}
#content blockquote{border-left:3px solid #ccc;padding:.1rem .8rem;color:#555;margin:.8rem 0}
#content table{border-collapse:collapse;width:100%;margin:.8rem 0}
#content th,#content td{border:1px solid #ddd;padding:.35rem .7rem;text-align:left}
#content th{background:#f5f5f5}
#content img{max-width:100%;height:auto}
#content hr{border:none;border-top:1px solid #ddd;margin:1.2rem 0}
/* Editor */
#editor{display:none;flex-direction:column;gap:.5rem}
#editor textarea{width:100%;height:70vh;font-family:ui-monospace,monospace;font-size:.9rem;line-height:1.5;padding:.75rem;border:1px solid #ccc;border-radius:4px;resize:vertical;outline:none}
#editor textarea:focus{border-color:#05c}
#editor-bar{display:flex;gap:.5rem}
.btn{padding:.4rem .55rem;font-size:.85rem;border:1px solid #ccc;border-radius:4px;cursor:pointer;background:#fff;display:inline-flex;align-items:center;justify-content:center}
.btn:hover{background:#f5f5f5}
button svg{display:block;width:1rem;height:1rem;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;overflow:visible}
.btn-primary{background:#05c;color:#fff;border-color:#05c}
.btn-primary:hover{background:#004ab3}
.btn-danger{color:#c00;border-color:#c00}
.btn-danger:hover{background:#fff5f5}
#save-status{font-size:.8rem;color:#666;line-height:2}
/* Index panel */
#index-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:100}
#index-panel{display:none;position:fixed;top:0;right:0;bottom:0;width:min(340px,90vw);background:#fff;box-shadow:-4px 0 20px rgba(0,0,0,.15);overflow-y:auto;padding:1.25rem 1.5rem;z-index:101}
#index-panel h3{font-size:1rem;font-weight:700;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center}
#index-panel button.close-btn{background:none;border:none;font-size:1.2rem;cursor:pointer;color:#666;line-height:1}
#index-tree{font-size:.88rem}
#index-tree ul{list-style:none;padding-left:1rem;margin:0}
#index-tree>ul{padding-left:0}
#index-tree li{margin:.2rem 0}
#index-tree a{color:#05c;text-decoration:none}
#index-tree a:hover{text-decoration:underline}
#index-tree .folder{font-weight:600;color:#444;display:block;margin-top:.5rem}
/* TOC panel */
#toc-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:100}
#toc-panel{display:none;position:fixed;top:0;left:0;bottom:0;width:min(300px,90vw);background:#fff;box-shadow:4px 0 20px rgba(0,0,0,.15);overflow-y:auto;padding:1.25rem 1.5rem;z-index:101}
#toc-panel h3{font-size:1rem;font-weight:700;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center}
#toc-panel button.close-btn{background:none;border:none;font-size:1.2rem;cursor:pointer;color:#666;line-height:1}
#toc-list{font-size:.88rem}
#toc-list ul{list-style:none;padding-left:0;margin:0}
#toc-list li{margin:.2rem 0}
#toc-list a{color:#05c;text-decoration:none;display:block}
#toc-list a:hover{text-decoration:underline}
.toc-h1{font-weight:700;}
.toc-h2{padding-left:1.5rem}
.toc-h3{padding-left:2.5rem;font-size:.83rem;color:#555}
/* Toast notification */
#toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%) translateY(1rem);background:#1a7f37;color:#fff;padding:.6rem 1.2rem;border-radius:6px;font-size:.9rem;font-weight:500;box-shadow:0 4px 14px rgba(0,0,0,.2);opacity:0;pointer-events:none;transition:opacity .25s,transform .25s;z-index:200}
#toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
#toast.error{background:#c62828}

/* ── Guest mode ── */
.guest-mode header{border-bottom:none;padding-bottom:0;margin-bottom:0;min-height:0;position:fixed;top:.75rem;right:max(1.5rem,calc((100vw - 860px)/2 + 1.5rem));width:auto;z-index:50}
.guest-mode #header-title,
.guest-mode #user-badge{display:none}
.guest-mode #nav{display:none}
.guest-mode #content{margin-top:2.5rem}
</style>
</head>
<body>

<!-- ── Login screen ─────────────────────────────────────── -->
<div id="login-screen">
  <div id="login-box">
    <h2>WeKickWiki — Sign in</h2>
    <form id="login-form" novalidate>
      <label>Username
        <input id="login-user" type="text" autocomplete="username" required autofocus>
      </label>
      <label>Password
        <input id="login-pass" type="password" autocomplete="current-password" required>
      </label>
      <button type="submit" title="Sign in" aria-label="Sign in"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg></button>
      <p id="login-error"></p>
    </form>
  </div>
</div>

<!-- ── Wiki screen ──────────────────────────────────────── -->
<div id="wiki-screen" style="display:none">
  <header>
    <a id="header-title" href="" onclick="navigate('index');return false;">WeKickWiki</a>
    <div id="header-right">
      <span id="user-badge"></span>
      <button class="btn" id="toc-btn" title="Table of contents" aria-label="Table of contents" style="display:none" onclick="toggleToc()"><svg viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="5" x2="21" y2="5"/><line x1="6" y1="10" x2="21" y2="10"/><line x1="10" y1="15" x2="21" y2="15"/><line x1="6" y1="20" x2="21" y2="20"/></svg></button>
      <button class="btn" id="index-btn" title="Index" aria-label="Index" style="display:none" onclick="toggleIndex()"><svg viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <button class="btn" id="edit-btn" title="Edit" aria-label="Edit" style="display:none" onclick="toggleEdit()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
      <button class="btn btn-danger" id="delete-btn" title="Delete" aria-label="Delete" style="display:none" onclick="deletePage()"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
      <button class="btn btn-danger" id="logout-btn" title="Sign out" aria-label="Sign out" onclick="logout()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></button>
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
  <div id="editor">
    <div id="editor-bar">
      <button class="btn btn-primary" title="Save" aria-label="Save" onclick="save()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg></button>
      <button class="btn" title="Cancel" aria-label="Cancel" onclick="cancelEdit()"><svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
      <span id="save-status"></span>
    </div>
    <textarea id="editor-area" spellcheck="false"></textarea>
  </div>
</div>

<div id="toast"></div>

<script>
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
function getToken() { return sessionStorage.getItem('wkw_token'); }
function getRole()  { return sessionStorage.getItem('wkw_role'); }
function getUser()  { return sessionStorage.getItem('wkw_user'); }

async function sha256(msg) {
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(msg));
  return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
}

async function apiFetch(url, opts = {}) {
  opts.headers = { ...(opts.headers || {}), 'Authorization': 'Bearer ' + getToken() };
  const res = await fetch(url, opts);
  if (res.status === 401) { logout(); return res; }
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
  document.getElementById('toc-btn').style.display    = isGuest ? 'none' : '';
  document.getElementById('edit-btn').style.display   = isAdmin ? '' : 'none';
  document.getElementById('index-btn').style.display  = isAdmin ? '' : 'none';
  if (isGuest) {
    document.getElementById('wiki-screen').classList.add('guest-mode');
  }
}

// ── Login form ──────────────────────────────────────────────────────────────
document.getElementById('login-form').addEventListener('submit', async e => {
  e.preventDefault();
  const user  = document.getElementById('login-user').value.trim();
  const pass  = document.getElementById('login-pass').value;
  const errEl = document.getElementById('login-error');
  errEl.textContent = '';
  if (!user || !pass) { errEl.textContent = 'Please fill in all fields'; return; }

  const hash = await sha256(pass);
  try {
    const res  = await fetch('?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user, hash })
    });
    const data = await res.json();
    if (res.ok) {
      sessionStorage.setItem('wkw_token', data.token);
      sessionStorage.setItem('wkw_role',  data.role);
      sessionStorage.setItem('wkw_user',  user);
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
    document.getElementById('content').innerHTML =
      '<p style="color:#888;margin-bottom:.75rem">This page does not exist yet.</p>' +
      '<button class="btn btn-primary" title="Create page" aria-label="Create page" onclick="createPage()">' + ICON_PLUS + '</button>';
  } else {
    document.getElementById('delete-btn').style.display = isAdmin ? '' : 'none';
    document.getElementById('content').innerHTML = marked.parse(rawMd);
    addHeadingIds();
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
      a.addEventListener('click', e => { e.preventDefault(); navigate(target); });
    }
  });

  const h1 = document.querySelector('#content h1');
  document.title = (h1 ? h1.textContent : page) + ' — Wiki';
  window.scrollTo(0, 0);
}

function createPage() { rawMd = ''; isNewPage = true; openEdit(); }

// ── TOC panel ────────────────────────────────────────────────────────────────
function slugify(text) {
  return text.toLowerCase().replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-') || 'heading';
}

function addHeadingIds() {
  const seen = {};
  document.querySelectorAll('#content h1, #content h2, #content h3').forEach(h => {
    let slug = slugify(h.textContent);
    if (seen[slug]) { seen[slug]++; slug += '-' + seen[slug]; } else { seen[slug] = 1; }
    h.id = slug;
  });
}

let tocOpen = false;
function closeToc() {
  if (!tocOpen) return;
  tocOpen = false;
  document.getElementById('toc-overlay').style.display = 'none';
  document.getElementById('toc-panel').style.display   = 'none';
}

function toggleToc() {
  const overlay = document.getElementById('toc-overlay');
  const panel   = document.getElementById('toc-panel');
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
    const id  = h.id;
    html += '<li><a class="' + cls + '" href="#' + id + '" onclick="document.getElementById(\'' + id + '\').scrollIntoView({behavior:\'smooth\'});closeToc();return false;">' + h.textContent + '</a></li>';
  });
  html += '</ul>';
  document.getElementById('toc-list').innerHTML = html;
}

// ── Page index panel ────────────────────────────────────────────────────────
let indexOpen = false;
async function toggleIndex() {
  const overlay = document.getElementById('index-overlay');
  const panel   = document.getElementById('index-panel');
  indexOpen = !indexOpen;
  overlay.style.display = panel.style.display = indexOpen ? 'block' : 'none';
  if (!indexOpen) return;

  const res  = await apiFetch(BASE + '?action=index');
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
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ page: currentPage })
  });
  if (res && res.ok) {
    showToast('Page \u201c' + currentPage + '\u201d deleted.');
    navigate('index', true);
  } else {
    const data = await res.json().catch(() => ({}));
    showToast(data.error || 'Error deleting page', 'error');
  }
}

function toggleEdit() { editing ? cancelEdit() : openEdit(); }

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
  const status  = document.getElementById('save-status');
  const content = document.getElementById('editor-area').value;
  status.textContent = 'Saving…';
  const res = await apiFetch('?action=save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ page: currentPage, content })
  });
  if (res && res.ok) {
    const wasNew = isNewPage;
    rawMd = content;
    status.textContent = '';
    cancelEdit();
    document.getElementById('content').innerHTML = marked.parse(rawMd);
    addHeadingIds();
    if (getRole() === 'admin') document.getElementById('delete-btn').style.display = '';
    showToast(wasNew ? 'Page \u201c' + currentPage + '\u201d created.' : 'Page \u201c' + currentPage + '\u201d saved.');
  } else {
    const data = await res.json().catch(() => ({}));
    status.textContent = '';
    showToast(data.error || 'Could not save the page. Please try again.', 'error');
  }
}

document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key === 's' && editing) { e.preventDefault(); save(); }
  if (e.key === 'Escape' && editing) { cancelEdit(); }
});

// ── Router ──────────────────────────────────────────────────────────────────
function route() {
  if (!getToken()) { showLogin(); return; }
  showWiki();
  load(getPage());
}
window.addEventListener('popstate', route);
route();
</script>
</body>
</html>
