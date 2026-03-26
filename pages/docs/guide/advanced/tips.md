# Advanced Tips

This page lives at `docs/guide/advanced/tips` — the **third level** of the directory tree.

## Keyboard shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl+S` | Save the current page |
| `Esc`    | Cancel editing |

## Relative links

Links in Markdown are resolved relative to the current page's path. Use `../` to go up one level:

```
[Introduction](../intro)           ← sibling in docs/guide/
[Docs overview](../../overview)    ← up to docs/
[Home](../../../index)             ← up to root
```

## Linking across levels

From this page (`docs/guide/advanced/tips`):

| Destination | Link |
|-------------|------|
| `docs/guide/intro` | `[Intro](../intro)` |
| `docs/overview` | `[Overview](../../overview)` |
| `syntax` | `[Syntax](../../../syntax)` |
| `index` | `[Home](../../../index)` |

---

[← Introduction](../intro) · [← Docs overview](../../overview) · [← Home](../../../index)
