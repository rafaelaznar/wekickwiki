# WeKickWiki — Copilot Instructions

## Overview

**WeKickWiki** is a self-hosted, multi-module educational platform built with **PHP + Vanilla JavaScript**. There are no heavy frameworks: the backend is plain PHP serving JSON APIs, and the frontend is a vanilla JS SPA (Single Page Application) with DOM manipulation. The only vendor dependencies are `marked.min.js` (Markdown rendering) and `highlight.min.js` (syntax highlighting), both served locally from `vendor/`.

All data is stored as **JSON files on disk** (no database). File access is protected with `flock()` via centralized helpers in `lib/data.php`.

---

## Directory Structure

```
index.php                  ← Central auth hub (login screen + hub screen)
settings.json              ← Global app settings (themes, JWT secret, TTL, flags)
users.json                 ← User accounts array (username, hash, role, name, enabled)
lib/
  auth.php                 ← JWT helpers, require_auth(), json_out(), load_users()
  data.php                 ← data_read(), data_write(), data_next_id()
  users-api.php            ← All user-management API endpoints
  auth-client.js           ← Browser JWT utils: getToken/getRole/getUser/sha256/apiFetch
  hub.js                   ← Hub UI logic (login form, user panel, theme panel, security)
templates/                 ← Hub-level CSS themes (default.css, flower-power.css, impact.css)
vendor/
  marked.min.js
  highlight.min.js
  highlight-themes/        ← highlight.js CSS themes
wiki/
  wiki.php                 ← Wiki SPA shell (HTML + <script> includes)
  wiki-api.php             ← Wiki-specific API endpoints + load_settings()
  wiki.js                  ← Wiki SPA logic (WKW plugin engine, navigation, editor, ODT)
  pages/                   ← Markdown content files (arbitrary nesting)
  front-plugins/           ← User-installable JS plugins (*.js)
  templates-wiki/          ← Wiki CSS themes
projects/
  projects.php             ← Projects SPA shell
  projects-api.php         ← Projects/tasks/statuses API
  projects.js              ← Projects SPA logic
  projects.json            ← Project definitions
  tasks.json               ← All tasks (flat array, parent_id for nesting)
  task-statuses.json       ← Custom kanban status definitions
  templates-projects/
marks/
  marks.php                ← Qualifications SPA shell
  marks-api.php            ← Qualifications/marks API
  marks.js                 ← Qualifications SPA logic
  items.json               ← Recursive qualification structure (name, weight, subitems)
  marks.json               ← Per-user mark objects mirroring items structure
  templates-marks/
quests/
  quests.php               ← Quests SPA shell
  quests-api.php           ← Questions/quests/attempts API
  quests.js                ← Quests SPA logic
  queries.json             ← All questions
  quests.json              ← Quest definitions
  attempts.json            ← User attempt records
  templates-quests/
calendar/
  calendar.php             ← Calendar SPA shell
  calendar-api.php         ← Events API
  calendar.js              ← Calendar SPA logic
  events.json              ← Calendar event objects
  templates-calendar/
feedback/
  feedback.php             ← Feedback SPA shell
  feedback-api.php         ← Feedback events/responses API
  feedback.js              ← Feedback SPA logic
  feedback-events.json     ← Feedback event definitions
  feedback-responses.json  ← User responses
  templates-feedback/
```

---

## Authentication System

### Roles
Two roles exist: **`admin`** (one account) and **`guest`** (multiple accounts).

### Login Flow
1. Client computes `SHA-256(password)` in the browser (via `crypto.subtle` with a pure-JS fallback in `sha256Fallback()`).
2. Client POSTs `{user, hash}` to `?action=login`.
3. Server computes `HMAC-SHA256(clientHash, JWT_SECRET)` and compares it with the stored hash using `hash_equals()` (constant-time).
4. On success, server issues a JWT (HS256) containing `{sub, role, name, iat, exp}`.
5. Client stores token in `sessionStorage` under keys: `wkw_token`, `wkw_role`, `wkw_user`, `wkw_name`.

### JWT
- Built and verified in `lib/auth.php` using `jwt_make()` / `jwt_verify()`.
- Secret and TTL come from `settings.json` (`jwtSecret`, `tokenTtl`).
- Every authenticated API request sends `Authorization: Bearer <token>` header.
- `require_auth()` validates the token and returns the claims array; calls `json_out(401, ...)` on failure.

### users.json Format
```json
[
  { "username": "admin", "hash": "<hmac-sha256>", "role": "admin", "name": "Display Name" },
  { "username": "guest", "hash": "<hmac-sha256>", "role": "guest", "name": "Display Name", "enabled": true }
]
```
- `hash` is always `HMAC-SHA256(SHA-256(password), JWT_SECRET)` — never the bare SHA-256.
- Hashes are **never** returned in any API response.
- Guest login can be disabled globally (`guestLoginEnabled` in settings) or per-account (`enabled: false`).

### Client Auth Helpers (`lib/auth-client.js`)
```js
getToken()   // sessionStorage.getItem('wkw_token')
getRole()    // 'admin' | 'guest'
getUser()    // username string
getName()    // display name string
sha256(msg)  // async, returns hex digest
apiFetch(url, opts)  // fetch with Authorization: Bearer header; calls _onUnauthorized on 401
setOnUnauthorized(fn)  // register logout callback
```

---

## PHP Coding Conventions

### File Structure Pattern
Every module follows the same pattern:
```php
<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/module-api.php';

// Read settings for theme and app name
$_raw = data_read(SETTINGS_FILE);
$mod_app_name = 'Module Name';
if (!empty($_raw['wikiName'])) $mod_app_name = $_raw['wikiName'] . ' — Module Name';
// Validate theme filename before use (prevents path traversal)
$mod_theme = 'default.css';
if (!empty($_raw['moduleTheme']) &&
    preg_match('/^[a-zA-Z0-9_\-]+\.css$/', $_raw['moduleTheme']) &&
    is_file(__DIR__ . '/templates-module/' . $_raw['moduleTheme'])) {
    $mod_theme = $_raw['moduleTheme'];
}
unset($_raw);
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';
?>
```

### API File Structure Pattern
```php
<?php
// ═══════════════════════════════════════════════════════════════════════════
// module/module-api.php — Description
// ═══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/data.php';

define('MOD_FILE', __DIR__ . '/data.json');

function mod_load_data(): array { return data_read(MOD_FILE); }
function mod_save_data(array $d): void { data_write(MOD_FILE, $d); }

// ── API endpoint ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'action-name') {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);
    // ... handler body ...
    json_out(200, $result);
}
```

### Key PHP Rules
- **All API endpoints** are inline `if` blocks keyed on `$_SERVER['REQUEST_METHOD']` and `($_GET['action'] ?? '')`.
- **File paths** are always PHP constants: `define('MOD_FILE', __DIR__ . '/file.json')`.
- **Function prefixes** per module: `pt_*` (projects), `pq_*` (marks), `qs_*` (quests), `cal_*` (calendar), `fb_*` (feedback).
- **Data I/O** always uses `data_read()` / `data_write()` — never raw `file_get_contents` for JSON.
- **IDs** are integers, auto-incremented with `data_next_id(array $arr): int` (returns `max(id)+1` or `1`).
- **Input validation** uses regex whitelisting, never blacklisting.
- **HTML output** is always escaped with `htmlspecialchars()`.
- **Sensitive comparisons** always use `hash_equals()`.
- **API responses** terminate via `json_out(int $code, array $data): never` which calls `exit()`.
- **Settings loading**: always validate then sanitize — check `is_string`, apply regex, check `is_file` before using a theme name.
- **`$_raw` pattern**: read settings raw → extract needed values → `unset($_raw)`.

### Comment Style (PHP)
```php
// ═══════════════════════════════════════════════════════════════════════════
// Section title — description
// ═══════════════════════════════════════════════════════════════════════════

// ── Subsection title ─────────────────────────────────────────────────────────
```

---

## JavaScript Coding Conventions

### Module Naming
Every module prefixes all its functions and global variables:
- Hub: `hub*`
- Wiki: no prefix (top-level wiki functions)
- Projects: `pt*`
- Marks/Qualifications: `pq*`
- Quests: `qs*`
- Calendar: `cal*`
- Feedback: `fb*`

### SPA Pattern
All modules follow the same SPA pattern:
- Header is hidden (`style="display:none"`) until auth is confirmed.
- Login state is checked on page load; if a valid token exists in `sessionStorage`, skip the login screen.
- All API calls use `apiFetch()` from `lib/auth-client.js`.
- Logout clears `sessionStorage` and shows the login screen (or redirects to `../index.php`).
- 401 responses trigger `setOnUnauthorized(logoutFn)`.

### DOM Patterns
```js
// HTML escaping (always use for user-provided data in innerHTML)
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Show/hide panels
element.style.display = 'block'; // or 'none' or ''

// All authenticated API calls
const res  = await apiFetch('?action=action-name');
const data = await res.json();
if (!res.ok) { /* handle error */ return; }
```

### API Calls
- All URLs are relative query strings: `?action=action-name` (resolve to the module's `.php` file).
- POST bodies are `JSON.stringify({...})` with `Content-Type: application/json`.
- Reads use `GET`, mutations use `POST`.

### Comment Style (JS)
```js
// ══════════════════════════════════════════════════════════════════════════════
// Section Title — Description
// ══════════════════════════════════════════════════════════════════════════════

// ── Subsection title ─────────────────────────────────────────────────────────
```

---

## HTML Conventions

### Page Shell
Every module page follows this structure:
```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($app_name) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="../icon.svg">
  <link id="mod-theme-link" rel="stylesheet" href="templates-module/<?= htmlspecialchars($mod_theme, ENT_QUOTES) ?>">
</head>
<body>
  <!-- header hidden until auth -->
  <div id="mod-header" style="display:none">...</div>
  <!-- main app area -->
  <div id="mod-screen">...</div>
  <script src="../lib/auth-client.js"></script>
  <script src="module.js"></script>
</body>
</html>
```

### Buttons
- Standard: `<button class="btn" ...>`
- Primary action: `<button class="btn btn-primary" ...>`
- Destructive action: `<button class="btn btn-danger" ...>`
- All buttons have both `title="..."` and `aria-label="..."`.

### Icons
All icons are **inline SVG**, `viewBox="0 0 24 24"`, `aria-hidden="true"`. No icon fonts. Stroke-based icons use `fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"` on the `<svg>`. Icon-only buttons always carry an `aria-label`.

### Tab Panels
```html
<div class="mod-tabs">
  <div class="mod-tab active" data-tab="tab1" onclick="modShowTab('tab1')">Tab 1</div>
  <div class="mod-tab" data-tab="tab2" onclick="modShowTab('tab2')">Tab 2</div>
</div>
<div id="tab-tab1" class="mod-tab-panel active">...</div>
<div id="tab-tab2" class="mod-tab-panel">...</div>
```

---

## CSS Conventions

### Theme System
Each module has its own `templates-{module}/` directory with the same three theme files:
- `default.css` — blue (`#05c`) accent, light background
- `flower-power.css` — colorful/playful theme
- `impact.css` — high-contrast dark theme

The hub (index.php) uses `templates/`. The wiki also has `vendor/highlight-themes/` for code syntax themes.

### Base Reset
```css
* { box-sizing: border-box; margin: 0; padding: 0; }
```

### Scoped Selectors
Each module uses ID-prefixed selectors to avoid collisions:
- Hub: `#hub-*`, `#login-*`
- Wiki: `#wiki-*`, `header`, `#content`, `#nav`
- Projects: `#pt-*`, `.pt-*`
- Marks: `#pq-*`, `.pq-*`
- Quests: `#qs-*`, `.qs-*`
- Calendar: `#cal-*`, `.cal-*`
- Feedback: `#fb-*`, `.fb-*`

### Typography
`font: normal 87.5%/1.4 Arial, system-ui, sans-serif;` — base font on `body`.

---

## Module Details

### Wiki (`wiki/`)
- Pages stored as `.md` files in `wiki/pages/` with arbitrary subdirectory nesting.
- Routing uses the History API (no page reloads); URL path segment after `wiki.php` is the page key.
- Markdown rendered client-side by `marked.js`; code blocks highlighted by `highlight.js`.
- **Plugin engine** (`window.WKW`): WordPress-style hooks/filters (`registerPlugin`, `applyFilters`, `doAction`). Plugins loaded from `front-plugins/*.js`.
- **Symbol substitutions** applied as Markdown preprocessor: `---` → em-dash, `->` → →, `(c)` → ©, etc.
- Admin: create/edit/delete pages, backup/restore, settings, plugin management.
- Guest: read-only; optional TOC, index, and ODT download controlled by settings flags.
- Backup format: plain text with `===PAGE=== <path>` markers.

### Projects (`projects/`)
- **projects.json**: `[{id, name, description, start_date, end_date}]`
- **tasks.json**: flat array `[{id, project_id, parent_id, title, description, status, priority, assignee, due_date, integration_date, done, completed_at, ...}]`
  - `parent_id` enables unlimited nesting; `0` or absent means root task.
  - `priority` values: `low`, `medium`, `high`, `critical`.
  - `integration_date`: if past today → task auto-marked done.
- **task-statuses.json**: `[{key, name, order}]` — default keys: `todo`, `in_progress`, `in_review`, `done`, `blocked`, `cancelled`.
- Tabs: Projects, Tasks (tree), Status Board (kanban), Burndown chart, Statuses management, Settings.
- Both admin and guest can view; only admin can create/edit/delete.

### Marks / Qualifications (`marks/`)
- **items.json**: recursive tree `{name, weight, subitems: [...]}`. Weights at each sibling group **must sum to 100** (validated by `pq_validate_weights()`).
- **marks.json**: array of mark objects, one per student, mirroring the items tree with numeric values at leaf nodes.
- Admin: edit qualification structure, enter marks for any student.
- Guest: view only their own marks and computed weighted score.

### Quests (`quests/`)
- **queries.json**: questions with types `multiple_choice`, `binary`, `gap_filling`, `matching`.
- **quests.json**: `[{id, label, queries (ids), allowed (usernames|'all'), wrong_penalty, status}]`.
  - `allowed` list is **guest-only** — never include admin.
  - Admin users are blocked from quiz-taking endpoints (`get-open-quests`, `start-quest`, `submit-attempt`, `get-my-attempts`).
  - Users who have completed a quest cannot be removed from `allowed`.
- **attempts.json**: `[{id, quest_id, username, answers, score, submitted_at}]`.
- Supports **Moodle XML import** for questions.
- Admin tabs: Questions, Quests, Results.
- Guest view: list of open/assigned quests, attempt history.

### Calendar (`calendar/`)
- **events.json**: `[{id, title, date, end_date?, description, color?}]`
  - `date` and `end_date`: ISO format `YYYY-MM-DD`.
- Admin: add/edit/delete events, change theme.
- Guest: read-only calendar view and list view.
- Tabs: Calendar (grid), List (agenda), Settings (admin).

### Feedback (`feedback/`)
- **feedback-events.json**: `[{id, title, description, type, status, anonymous}]`
  - `type`: `open` (free text only), `closed` (predefined options only), `mixed` (both).
  - `status`: `open` | `closed`.
  - `anonymous`: when `true`, username is stripped from admin-facing responses.
- **feedback-responses.json**: `[{id, event_id, username, text?, option?, submitted_at}]`
  - `username` always stored internally (for duplicate prevention); hidden from admin when anonymous.
- Admin: create/edit/close events, view responses (with/without usernames).
- Guest: submit responses to open events, view own past responses.

---

## Data Conventions

| Convention | Detail |
|---|---|
| IDs | Always integers; `data_next_id()` → `max(id)+1` or `1` |
| Dates | ISO `YYYY-MM-DD` |
| Timestamps | ISO 8601 with TZ: `YYYY-MM-DDTHH:MM:SS+00:00` |
| JSON storage | `JSON_PRETTY_PRINT \| JSON_UNESCAPED_UNICODE` |
| Concurrent access | `flock()` via `data_read()` / `data_write()` |
| Empty arrays | Always `[]`, never `{}` for lists |

---

## Security Rules

1. **Never return** password hashes in any API response.
2. **Always validate** theme/plugin filenames with `preg_match('/^[a-zA-Z0-9_\-]+\.css$/', ...)` **and** `is_file(...)` before using in includes or HTML output — prevents path traversal.
3. **Always use `hash_equals()`** for credential comparisons — prevents timing attacks.
4. **Always use `htmlspecialchars()`** for all PHP output to HTML — prevents XSS.
5. **Always use `escHtml()`** for dynamic JS-generated HTML — prevents XSS.
6. **Username sanitization**: `preg_replace('/[^a-z0-9_]/', '', strtolower($user))`.
7. **Hash sanitization**: `preg_replace('/[^a-fA-F0-9]/', '', $hash)` + length check (64 chars).
8. **Generic auth errors**: never reveal whether username or password was wrong (`'Invalid credentials'`).
9. **Role checks**: always check `($claims['role'] ?? '') !== 'admin'` before admin operations.
10. **Input length limits**: truncate strings at reasonable lengths before storing.

---

## Settings (`settings.json`)

| Key | Type | Default | Description |
|---|---|---|---|
| `wikiName` | string | `"WeKickWiki"` | App display name |
| `hubTheme` | string | `"default.css"` | Hub login/home theme |
| `theme` | string | `"default.css"` | Wiki theme |
| `hljsTheme` | string | `"atom-one-light.css"` | Highlight.js theme |
| `codeLineNumbers` | bool | `true` | Show line numbers in code blocks |
| `guestOdtDownload` | bool | `true` | Allow guests to download ODT |
| `guestToc` | bool | `true` | Show TOC to guests |
| `guestIndex` | bool | `true` | Show page index to guests |
| `guestLoginEnabled` | bool | `true` | Allow any guest to log in |
| `jwtSecret` | string | (hardcoded fallback) | HMAC secret, min 16 chars |
| `tokenTtl` | int | `3600` | JWT lifetime in seconds, min 60 |
| `pqTheme` | string | `"default.css"` | Qualifications theme |
| `feedbackTheme` | string | `"default.css"` | Feedback theme |
| `projectsTheme` | string | `"default.css"` | Projects theme |
| `questsTheme` | string | `"default.css"` | Quests theme |
| `calendarTheme` | string | `"default.css"` | Calendar theme |

---

## Adding a New Module

When adding a new module, follow this checklist:

1. Create `newmodule/` directory with:
   - `newmodule.php` — SPA shell (auth + HTML), follow the `$_raw` + theme validation pattern.
   - `newmodule-api.php` — API endpoints, inline `if` blocks.
   - `newmodule.js` — SPA client logic, all functions prefixed `nm*`.
   - `templates-newmodule/` — with `default.css`, `flower-power.css`, `impact.css`.
   - Data JSON file(s) in the module directory.
2. Add a `newmoduleTheme` key to `settings.json` and the validation logic in `newmodule.php`.
3. Load `../lib/auth-client.js` before `newmodule.js` in the HTML shell.
4. Register a `?action=` endpoint for every CRUD operation.
5. Define all data-file paths as PHP constants (`define('NM_DATA_FILE', ...)`).
6. Add function prefixes: `nm_load_*`, `nm_save_*`, `nm_sanitize_*`.
7. Link the module card from `index.php` hub screen.
8. Add `nm_app_name = wikiName . ' — New Module'` display name pattern.
