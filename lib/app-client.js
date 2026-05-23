// ═══════════════════════════════════════════════════════════════════════════
// lib/app-client.js — Shared browser utilities for all three modules.
//
// Requires lib/auth-client.js to be loaded first (uses escHtml here, but
// auth-client.js globals getToken/apiFetch are used by each module's own JS).
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Escape a string for safe HTML insertion.
 * @param {any} str
 * @returns {string}
 */
function escHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/**
 * Show a transient toast notification.
 * @param {string}  msg   - Message text
 * @param {string}  type  - 'success' | 'error' | 'info'  (default 'success')
 * @param {number}  ms    - Auto-hide delay in milliseconds (default 3200)
 * @param {string}  id    - Toast element id (default 'app-toast')
 */
function showToast(msg, type = 'success', ms = 3200, id = 'app-toast') {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.className   = 'show' + (type !== 'success' ? ' ' + type : '');
  clearTimeout(el._toastTimer);
  el._toastTimer = setTimeout(() => { el.className = ''; }, ms);
}

/**
 * Set a status indicator element.
 * @param {string} id     - Element id
 * @param {string} msg    - Status text ('' clears)
 * @param {string} type   - 'ok' | 'err' | ''
 */
function setStatus(id, msg, type) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.className   = msg ? ('app-status ' + (type || '')) : '';
}
