# Welcome to the WeKickWiki Wiki

**WeKickWiki** is a single-file PHP wiki with a JavaScript SPA frontend.

**Authentication**
- JWT-based login with configurable users/roles (`admin`, `guest`)
- Passwords are SHA-256 hashed client-side, then HMAC'd server-side before comparison
- Tokens expire after 1 hour; stored in `sessionStorage`
- Credentials: admin/admin123, guest/guest123

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

**Guest features**
- Read-only access
- Inline floating TOC (auto-generated from headings, rendered inside the content area)
- Minimal UI ("guest mode"): header hidden, home/top buttons visible

**UI**
- Responsive, single-column layout (max 860px)
- Toast notifications for save/delete/error feedback
- SVG icon set throughout; icon.svg used as favicon and in login/header
- No external dependencies except marked.js CDN