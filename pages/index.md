# Welcome to the WeKickWiki Wiki

**WeKickWiki** is a single-file PHP wiki with a JavaScript SPA frontend.

**Authentication**
- JWT-based login with configurable users/roles (`admin`, `guest`)
- Passwords are SHA-256 hashed client-side, then HMAC'd server-side before comparison
- Tokens expire after 1 hour; stored in `sessionStorage`

**Content**
- Pages stored as Markdown files under pages with arbitrary folder nesting
- Markdown rendered client-side via [marked.js](https://marked.js.org/)
- Automatic symbol substitution in Markdown (e.g. `->` → →, `---` → —, `(c)` → ©)

**Navigation**
- SPA routing using the History API (no page reloads)
- Breadcrumb trail for nested pages
- Relative Markdown links resolved and handled client-side
- Page title updated from the first `h1`

**Admin features**
- Create, edit, and delete pages via an in-page editor (textarea)
- Ctrl+S / Cmd+S to save, Escape to cancel
- Slide-in page index panel showing the full page tree
- TOC panel (side drawer) listing headings of current page
- **Manage users**: change admin and guest usernames/passwords
- **Download backup**: export all wiki pages to a single timestamped text file with page markers and metadata
- **Restore backup**: upload a backup file to overwrite all wiki pages (with destructive warning confirmation)

**Guest features**
- Read-only access
- Inline floating TOC (auto-generated from headings, rendered inside the content area)
- Minimal UI ("guest mode"): header hidden, home/top buttons visible

## Backup & Restore

**Backup (Admin only)**
Download all wiki pages as a single timestamped text file:
- File name format: `wkw-backup-YYYYMMDD-HHMMSS.txt` (UTC time)
- Header includes generation timestamp, page count, and instructions
- Each page prefixed with `===PAGE=== <path>` marker followed by raw Markdown content
- Human-readable format; can be manually edited if needed

**Restore (Admin only)**
Upload a previously downloaded backup to overwrite all wiki pages:
- Wipes the entire `pages/` directory
- Re-expands all pages from the backup file
- **⚠️ Destructive operation**: shows confirmation dialog before proceeding
- Pages are restored with exact content from backup (no modifications)

## User Management

**Change Credentials (Admin only)**
Update admin and guest usernames and/or passwords:
- Click the **Manage users** button (person+ icon) in the toolbar
- Modify username and password fields as needed
- Leave password blank to keep the current password unchanged
- Both usernames must be 2–32 characters (lowercase alphanumeric + underscore)
- Admin and guest usernames must be different
- Changes take effect immediately; if admin username is changed, you will be logged out and must sign in again

**UI**
- Responsive, single-column layout (max 860px)
- Toast notifications for save/delete/error feedback
- SVG icon set throughout; icon.svg used as favicon and in login/header
- No external dependencies except marked.js CDN

## Pages

This is the home page. Browse using the links below or use the **Index** button in the top bar to see all pages.

- [About](about)
- [Markdown Syntax](syntax)
- [Markdown Demo](demo)

## Directory structure

Pages mirror the file hierarchy inside `pages/`:

| File                              | URL                        |
|-----------------------------------|----------------------------|
| `pages/index.md`                  | `/`                        |
| `pages/about.md`                  | `about`                    |
| `pages/syntax.md`                 | `syntax`                   |
| `pages/demo.md`                   | `demo`                     |
| `pages/docs/overview.md`          | `docs/overview`            |
| `pages/docs/guide/intro.md`       | `docs/guide/intro`         |
| `pages/docs/guide/advanced/tips.md` | `docs/guide/advanced/tips` |

See the three-level example under [docs/overview](docs/overview).

Relative links in Markdown are resolved automatically.
