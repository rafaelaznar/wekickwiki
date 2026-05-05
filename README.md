# Welcome to the WeKickWiki Wiki

**WeKickWiki** is a single-file PHP wiki with an AJAX JavaScript SPA frontend.

**Default Credentials**
- admin: admin123
- guest: guest123

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

**UI**
- Responsive, single-column layout (max 860px)
- Toast notifications for save/delete/error feedback
- SVG icon set throughout; icon.svg used as favicon and in login/header
- No external dependencies (all assets served locally from `vendor/`)
  
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

**Guest Login Control (Admin only)**
- The `guestLoginEnabled` flag in `users.json` controls whether guest user authentication is allowed
- When `guestLoginEnabled` is `false`, the guest login option is disabled
- When `guestLoginEnabled` is `true`, guest users can authenticate with their credentials
- This setting is managed in the `users.json` configuration file or in the popup from **Manage users** button in the admin toolbar

**`users.json` File Structure**

The `users.json` file stores user credentials and authentication settings:

```json
{
  "admin": {
    "hash": "f102261abcb0b4fe003994b9e9f2f2efdd64a80b52ba930d401b5a2a694a0e61",
    "role": "admin"
  },
  "guest": {
    "hash": "18fb145c0a15beae4d671e61688f90624c42e93872b642c346e4fc87f92fbbf4",
    "role": "guest"
  },
  "guestLoginEnabled": true
}
```

- **User entries** (e.g., `"admin"`, `"guest"`): Each username is a key with an object containing:
  - **`hash`** (string): SHA-256 HMAC hash of the password (computed client-side then server-side)
  - **`role`** (string): User role (`admin` or `guest`); determines access permissions
- **`guestLoginEnabled`** (boolean): 
  - `true`: Guest users can log in with their credentials; guest login button appears on login screen
  - `false`: Guest login is disabled; only admin login is available

**Modifying Guest Login Setting**:
- **Via UI**: Click **Manage users** → toggle the **Enable guest login** checkbox → save
- **Via File**: Manually edit `users.json`, change `"guestLoginEnabled": true` to `false` (or vice versa), and reload the page

## HTML Support in Markdown

WeKickWiki supports inline and block-level HTML mixed directly within Markdown content:
- **Inline HTML** (e.g., `<span style="color:red;">text</span>`) renders as-is in the page
- **Block HTML tables** (e.g., `<table>...</table>`) are recognized and preserved
- HTML blocks are passed through the `wkw.html.postrender` filter hook, allowing plugins to transform or enhance them
- Markdown preprocessing happens before HTML rendering, so symbol substitutions (like `->` → →) apply to Markdown content but not HTML blocks

### HTML Tables in ODT Export

HTML `<table>` elements with `colspan` and `rowspan` attributes can be exported to ODT (flat-ODT) format via the `table-colrowspan` plugin.
Without plugins, HTML tables are silently omitted from exports; plain GFM Markdown tables (using `|` separators) are exported as-is.

## Settings

The `settings.json` file stores global wiki configuration:

```json
{
  "wikiName": "WeKickWiki",
  "theme": "impact.css",
  "hljsTheme": "highlight-github.min.css",
  "disabledPlugins": []
}
```

- **`wikiName`** (string): The display name of the wiki, shown in the header
- **`theme`** (string): The active CSS template file name from the `templates/` directory (e.g., `default.css`, `impact.css`)
- **`hljsTheme`** (string): The active highlight.js theme file name from `vendor/highlight-themes/` (e.g., `highlight-github.min.css`, `monokai.css`)
- **`disabledPlugins`** (array of strings): List of plugin IDs that are disabled in memory. Plugins in this list load but their hooks are not executed

## Theme & Template Selection (Admin)

**Change Theme, Code Highlight Theme & Wiki Name (Admin only)**
- Click the **Settings** button (gear icon) in the toolbar
- A modal dialog opens with:
  - **Wiki Name** text field: modify the title displayed in the header
  - **Theme** dropdown: select from all available CSS templates in the `templates/` directory
  - **Code highlight theme** dropdown: select the highlight.js theme applied to all fenced code blocks; choices come from `vendor/highlight-themes/`
- Changes are persisted to `settings.json` immediately
- Page reloads after saving so the new theme and code highlight style take effect for all users

### Available Code Highlight Themes

Theme CSS files are stored in `vendor/highlight-themes/`. The following themes are included:

| File | Description |
|------|-------------|
| `atom-one-dark.css` | Dark background, vibrant colours |
| `atom-one-light.css` | Light background, Atom One palette |
| `highlight-github.min.css` | GitHub-style light theme (default) |
| `monokai.css` | Classic Monokai dark theme |
| `obsidian.css` | Dark high-contrast theme |
| `tomorrow-night-blue.css` | Blue-toned dark theme |
| `tomorrow-night-bright.css` | Bright Tomorrow Night variant |

To add a new theme, download any highlight.js theme CSS file into `vendor/highlight-themes/` — it will appear automatically in the dropdown.

## Creating a Custom Template

Templates are CSS files stored in the `templates/` directory. To create a custom template:

1. **Create a new `.css` file** in the `templates/` folder (e.g., `mytheme.css`)
2. **Define styles** for the wiki UI and content:
   - **Body/Layout**: Set max-width (default: 860px), margins, font family, colors
   - **Header**: Style `#header`, `#header h1`, navigation bar
   - **Navigation**: Style `.breadcrumb`, `.page-nav` buttons
   - **Content**: Style `#content`, headings, paragraphs, links, code blocks, blockquotes
   - **Editor**: Style `.editor-textarea`, `.editor-buttons`
   - **TOC/Panels**: Style `.toc`, `.panel`, sidebar elements
   - **Utilities**: Define `.toast`, `.show` (animations), button styles (`.btn`), etc.

3. **Reference standard elements**:
   ```css
   body { max-width: 860px; font-family: serif; background: #f5f5f5; }
   #header { background: linear-gradient(to right, #333, #555); color: white; padding: 1rem; }
   #content { line-height: 1.6; color: #333; }
   code { background: #f0f0f0; padding: 0.2em 0.4em; border-radius: 3px; }
   ```

4. **Select the template** via the Settings modal (Theme dropdown)
5. The new template is now active for all users

### Design Tips
- Use CSS variables for easy customization
- Ensure dark/light mode contrast compliance (WCAG AA or higher)
- Test responsive design on mobile (max-width: 600px breakpoint)
- Use semantic HTML5 elements for better accessibility

---

## Plugins

WeKickWiki features a plugin system inspired by WordPress hooks and filters, allowing developers to extend functionality without modifying core code.

### Plugin Architecture Overview

**WeKickWiki Plugin Engine** (`WKW`)
- Centralized plugin registry exposed globally as `window.WKW`
- Plugins register metadata and hook handlers via `WKW.registerPlugin()`
- Filters transform values: `WKW.applyFilters(hook, value, ...args)` → transformed value
- Actions trigger side effects: `WKW.doAction(hook, ...args)` → no return value
- Disabled plugins (listed in `settings.json:disabledPlugins`) load but their hooks are skipped
- Hooks execute in priority order (lower number = earlier execution)

### Plugin Structure

Each plugin is a JavaScript file in `front-plugins/` that declares itself via `WKW.registerPlugin()`:

```javascript
WKW.registerPlugin({
  id:          'my-plugin',           // Unique identifier
  name:        'My Plugin',           // Display name
  version:     '1.0.0',               // Semantic version
  description: 'Does something cool', // Short description
  author:      'Your Name',           // Author
  hooks:       ['hook.name', ...],    // Hook names used
  priority:    10                     // Optional: default priority (10)
}, {
  'hook.name': (value, ...args) => {
    // Transform or execute; return value for filters
    return value;
  }
});
```

### Available Hooks

#### Markdown & Rendering
- **`wkw.md.preprocess`** (filter)
  - Called before Markdown parsing
  - Signature: `(md) → transformed_md`
  - Use case: Pre-process Markdown syntax before rendering

- **`wkw.html.postrender`** (filter)
  - Called after Markdown is converted to HTML
  - Signature: `(html) → transformed_html`
  - Use case: Enhance or transform generated HTML (e.g., add classes, wrap elements)

#### Page Lifecycle
- **`wkw.page.afterLoad`** (action)
  - Fired after a page loads and renders
  - Signature: `(pagePath, contentElement)`
  - Parameters:
    - `pagePath` (string): Path to the loaded page
    - `contentElement` (DOM element): The `#content` div
  - Use case: Attach event listeners, enhance page elements (e.g., syntax highlighting)

#### ODT Export
- **`wkw.odt.htmlTable`** (filter)
  - Transforms HTML `<table>` blocks to ODT XML for flat-ODT export
  - Signature: `(current, html, ctx) → odt_xml_string`
  - Parameters:
    - `current` (string): Accumulated ODT XML from previous handlers (or empty string)
    - `html` (string): Raw HTML of the `<table>` block
    - `ctx` (object): Utility functions passed by the core:
      - `ctx.odtXmlEsc(s)` — XML-escape a string
      - `ctx.odtInline(text)` — Convert inline Markdown/HTML to ODT span elements
      - `ctx.nextTableName()` — Get the next unique table name ('Tbl1', 'Tbl2', …)
  - Return: ODT `<table:table>` XML element as a string, or empty string if not handled
  - Use case: Export HTML tables with complex formatting (colspan, rowspan) to ODT

- **`wkw.odt.styles`** (filter)
  - Add custom ODT styles (e.g., `<style:style>` elements) to the exported document
  - Signature: `() → odt_xml_styles_string`
  - Use case: Define custom text or paragraph styles for ODT export

### The `table-colrowspan` Plugin

**File**: `front-plugins/table-fodt-colrowspan.js`

**Purpose**: Renders HTML `<table>` elements with `colspan` and `rowspan` attributes as proper ODT table elements in flat-ODT (.fodt) exports.

**Why Needed**: 
- The base WeKickWiki only handles plain GFM Markdown tables (using `|` separators)
- HTML tables with merged cells are silently omitted from ODT exports without this plugin
- This plugin bridges the gap by parsing HTML table structure and generating valid ODT `<table:table>` XML

**Hook**: `wkw.odt.htmlTable`

**How It Works**:
1. **Parses the HTML table**: Extracts all `<tr>` and `<td>`/`<th>` elements
2. **Resolves cell placement**: Accounts for `colspan` and `rowspan` by tracking which columns are occupied by earlier rows
3. **Generates ODT XML**: Creates a properly structured `<table:table>` element with:
   - `<table:table-column>` for each column
   - `<table:table-row>` for each row
   - `<table:table-cell>` for each cell, respecting `colspan` and `rowspan`
4. **Handles content**: Converts cell HTML/Markdown to ODT inline elements via `ctx.odtInline()`

**Example Usage** (in Markdown):

```html
<table>
  <tr>
    <th colspan="2">Header 1</th>
    <th>Header 2</th>
  </tr>
  <tr>
    <td>Cell 1</td>
    <td rowspan="2">Spans 2 rows</td>
    <td>Cell 3</td>
  </tr>
  <tr>
    <td>Cell 4</td>
    <td>Cell 5</td>
  </tr>
</table>
```

When exported to ODT, this table renders with proper merged cells, preserving the visual structure.
