# About

This wiki is a minimalist project built with a single PHP file and Markdown.

## Features

- Pages written in **Markdown**
- Hierarchy reflected in the `pages/` directory structure
- Client-side routing based on the URL path
- No database, no server-side dependencies
- JWT-based authentication with admin and guest roles

## Adding pages

Create a `.md` file inside `pages/`. The file path determines the URL:

- `pages/new.md` → `new`
- `pages/section/new.md` → `section/new`
- `pages/section/sub/new.md` → `section/sub/new`

## Editing pages

Admin users can edit any page directly in the browser using the **Edit** button, or delete it with the **Delete** button.

Keyboard shortcuts while editing:

| Shortcut | Action |
|----------|--------|
| `Ctrl+S` | Save   |
| `Esc`    | Cancel |

---

[← Home](index) · [Markdown Syntax](syntax)
