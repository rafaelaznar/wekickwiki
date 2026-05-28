    // ═══════════════════════════════════════════════════════════════════════════
    // lib/hub.js — Central authentication hub client logic for WeKickWiki
    //
    // Handles:
    //   - Login form submission (POST ?action=login)
    //   - Session restore / logout
    //   - Admin user management panel (get-users, add-guest, edit-guest,
    //     delete-guest, reset-password, save-users)
    //   - Admin security panel (guest login toggle + token TTL)
    //   - Guest change-password panel (?action=change-password)
    //
    // All apiFetch calls use relative URLs (?action=…), which resolve to
    // index.php since this script is loaded from that page.
    //
    // Depends on lib/auth-client.js (loaded first):
    //   getToken, getRole, getUser, getName, sha256, apiFetch, setOnUnauthorized
    // ═══════════════════════════════════════════════════════════════════════════

    // ── HTML escaping helper ─────────────────────────────────────────────────────
    function escHtml(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Auth / routing ───────────────────────────────────────────────────────────
    setOnUnauthorized(hubLogout);

    function hubLogout() {
      sessionStorage.clear();
      hubShowLogin();
    }

    function hubShowLogin() {
      document.getElementById('login-screen').style.display = '';
      document.getElementById('hub-screen').style.display   = 'none';
      document.getElementById('login-error').textContent    = '';
      document.getElementById('login-pass').value           = '';
    }

    function hubShowApp() {
      document.getElementById('login-screen').style.display = 'none';
      document.getElementById('hub-screen').style.display   = 'block';
      document.getElementById('hub-user-badge').textContent = getUser() + ' (' + getRole() + ')';
      const isAdmin = getRole() === 'admin';
      const isGuest = getRole() === 'guest';
      document.getElementById('hub-users-btn').style.display      = isAdmin ? '' : 'none';
      document.getElementById('hub-themes-btn').style.display     = isAdmin ? '' : 'none';
      document.getElementById('hub-security-btn').style.display   = isAdmin ? '' : 'none';
      document.getElementById('hub-change-pass-btn').style.display = isGuest ? '' : 'none';
    }

    // ── Login form ───────────────────────────────────────────────────────────────
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
          sessionStorage.setItem('wkw_name',  data.name || user);
          hubShowApp();
        } else {
          errEl.textContent = data.error || 'Authentication error';
        }
      } catch {
        errEl.textContent = 'Connection error';
      }
    });

    // Auto-restore session
    if (getToken()) { hubShowApp(); }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Users management panel ──────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════
    let usersOpen = false;

    async function hubToggleUsersPanel() {
      const overlay = document.getElementById('users-overlay');
      const panel   = document.getElementById('users-panel');
      usersOpen = !usersOpen;
      overlay.style.display = panel.style.display = usersOpen ? 'block' : 'none';
      if (!usersOpen) return;
      await hubLoadUsersPanel();
    }

    async function hubLoadUsersPanel() {
      const grid = document.getElementById('users-grid');
      grid.innerHTML = '<p style="font-size:.82rem;color:#888;padding:.5rem 0">Loading…</p>';
      const res = await apiFetch('?action=get-users');
      if (!res || !res.ok) {
        grid.innerHTML = '<p style="font-size:.82rem;color:#c0392b;padding:.5rem 0">Could not load users.</p>';
        return;
      }
      const data = await res.json();
      grid.innerHTML = '';
      hubRenderAdminCard(data.adminUser || '', data.adminName || '');
      hubHideAddGuestForm();
      hubRenderGuestCards(data.guests || []);
    }

    function hubRenderAdminCard(adminUser, adminName) {
      const grid = document.getElementById('users-grid');
      const div  = document.createElement('div');
      div.className = 'user-card admin-card';
      div.id = 'user-card-__admin';
      div.innerHTML = `
        <div class="user-card-view">
          <div class="user-card-info">
            <strong class="user-card-name">${escHtml(adminName || adminUser)}</strong>
            <span class="user-card-username">@${escHtml(adminUser)}</span>
            <span class="user-card-badge">Admin</span>
          </div>
          <div class="user-card-controls">
            <button class="btn btn-sm" title="Change password" onclick="hubShowResetPasswordModal('${escHtml(adminUser)}')">&#128273;</button>
            <button class="btn btn-sm" title="Edit" onclick="hubStartEditAdmin()">&#9998;</button>
          </div>
        </div>
        <div class="user-card-edit" id="admin-card-edit" style="display:none">
          <label>Username
            <input type="text" id="users-admin-name" value="${escHtml(adminUser)}" autocomplete="off" maxlength="32" pattern="[a-z0-9_]+">
          </label>
          <label>Name
            <input type="text" id="users-admin-displayname" value="${escHtml(adminName)}" autocomplete="off" maxlength="64">
          </label>
          <span class="guest-edit-status" id="users-admin-status"></span>
          <div style="display:flex;gap:.4rem;margin-top:.4rem;justify-content:flex-end">
            <button class="btn btn-sm" onclick="hubCancelEditAdmin()">Cancel</button>
            <button class="btn btn-primary btn-sm" onclick="hubSubmitEditAdmin()">Save</button>
          </div>
        </div>`;
      grid.appendChild(div);
    }

    function hubRenderGuestCards(guests) {
      const grid = document.getElementById('users-grid');
      guests.forEach(g => {
        const div = document.createElement('div');
        div.className = 'user-card';
        div.id = 'user-card-' + g.username;
        div.innerHTML = `
          <div class="user-card-view">
            <label class="toggle-switch" title="${g.enabled ? 'Enabled – click to disable' : 'Disabled – click to enable'}" style="margin:0">
              <input type="checkbox" ${g.enabled ? 'checked' : ''} onchange="hubToggleGuestEnabled('${g.username}', this)">
              <span class="toggle-slider"></span>
            </label>
            <div class="user-card-info">
              <strong class="user-card-name">${escHtml(g.name)}</strong>
              <span class="user-card-username">@${escHtml(g.username)}</span>
            </div>
            <div class="user-card-controls">
              <button class="btn btn-sm" title="Reset password" onclick="hubShowResetPasswordModal('${g.username}')">&#128273;</button>
              <button class="btn btn-sm" title="Edit" onclick="hubStartEditGuest('${g.username}')">&#9998;</button>
              <button class="btn btn-danger btn-sm" title="Delete" onclick="hubDeleteGuest('${g.username}')">&#215;</button>
            </div>
          </div>
          <div class="user-card-edit" id="guest-edit-${g.username}" style="display:none">
            <label>Username
              <input type="text" id="guest-edit-username-${g.username}" value="${escHtml(g.username)}" maxlength="32" autocomplete="off">
            </label>
            <label>Name
              <input type="text" id="guest-edit-name-${g.username}" value="${escHtml(g.name)}" maxlength="64" autocomplete="off">
            </label>
            <span class="guest-edit-status" id="guest-edit-status-${g.username}"></span>
            <div style="display:flex;gap:.4rem;margin-top:.4rem;justify-content:flex-end">
              <button class="btn btn-sm" onclick="hubCancelEditGuest('${g.username}')">Cancel</button>
              <button class="btn btn-primary btn-sm" onclick="hubSubmitEditGuest('${g.username}')">Save</button>
            </div>
          </div>`;
        grid.appendChild(div);
      });
    }

    function hubShowAddGuestForm() {
      document.getElementById('guest-add-form').style.display = '';
      document.getElementById('guest-add-btn').style.display  = 'none';
      document.getElementById('guest-add-username').value = '';
      document.getElementById('guest-add-name').value     = '';
      document.getElementById('guest-add-pass').value     = '';
      document.getElementById('guest-add-pass2').value    = '';
      document.getElementById('guest-add-status').textContent = '';
      document.getElementById('guest-add-username').focus();
    }

    function hubHideAddGuestForm() {
      document.getElementById('guest-add-form').style.display = 'none';
      document.getElementById('guest-add-btn').style.display  = '';
    }

    async function hubSubmitAddGuest() {
      const statusEl  = document.getElementById('guest-add-status');
      const username  = document.getElementById('guest-add-username').value.trim().toLowerCase();
      const name      = document.getElementById('guest-add-name').value.trim();
      const pass      = document.getElementById('guest-add-pass').value;
      const pass2     = document.getElementById('guest-add-pass2').value;
      statusEl.textContent = '';
      if (!/^[a-z0-9_]{2,32}$/.test(username)) { statusEl.textContent = 'Username: 2–32 chars (a-z, 0-9, _)'; return; }
      if (!name)  { statusEl.textContent = 'Name cannot be empty.'; return; }
      if (!pass)  { statusEl.textContent = 'Password is required.'; return; }
      if (pass !== pass2) { statusEl.textContent = 'Passwords do not match.'; return; }
      const hash = await sha256(pass);
      statusEl.textContent = 'Adding…';
      try {
        const res  = await apiFetch('?action=add-guest', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username, name, hash })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          await hubLoadUsersPanel();
        } else {
          statusEl.textContent = data.error || 'Error adding guest.';
        }
      } catch { statusEl.textContent = 'Connection error.'; }
    }

    function hubStartEditGuest(username) {
      document.getElementById('guest-edit-' + username).style.display = '';
      document.getElementById('guest-edit-username-' + username).focus();
    }

    function hubCancelEditGuest(username) {
      document.getElementById('guest-edit-' + username).style.display = 'none';
      document.getElementById('guest-edit-status-' + username).textContent = '';
    }

    async function hubSubmitEditGuest(oldUsername) {
      const statusEl    = document.getElementById('guest-edit-status-' + oldUsername);
      const newUsername = document.getElementById('guest-edit-username-' + oldUsername).value.trim().toLowerCase();
      const name        = document.getElementById('guest-edit-name-' + oldUsername).value.trim();
      statusEl.textContent = '';
      if (!/^[a-z0-9_]{2,32}$/.test(newUsername)) { statusEl.textContent = 'Username: 2–32 chars (a-z, 0-9, _)'; return; }
      if (!name) { statusEl.textContent = 'Name cannot be empty.'; return; }
      const enabledEl = document.querySelector(`#user-card-${oldUsername} input[type="checkbox"]`);
      const enabled = enabledEl ? enabledEl.checked : true;
      statusEl.textContent = 'Saving…';
      try {
        const res  = await apiFetch('?action=edit-guest', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ oldUsername, newUsername, name, enabled })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          await hubLoadUsersPanel();
        } else {
          statusEl.textContent = data.error || 'Error updating guest.';
        }
      } catch { statusEl.textContent = 'Connection error.'; }
    }

    async function hubToggleGuestEnabled(username, checkbox) {
      const nameDisplay = document.querySelector('#user-card-' + username + ' .user-card-name');
      const nameEl = document.getElementById('guest-edit-name-' + username);
      const name = (nameEl && nameEl.style.display !== 'none' && nameEl.value.trim()) ||
                   (nameDisplay ? nameDisplay.textContent : username);
      const enabled = checkbox.checked;
      try {
        const res = await apiFetch('?action=edit-guest', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ oldUsername: username, newUsername: username, name, enabled })
        });
        if (!res.ok) {
          checkbox.checked = !enabled;
          const data = await res.json().catch(() => ({}));
          alert(data.error || 'Could not update status.');
        }
      } catch {
        checkbox.checked = !enabled;
        alert('Connection error.');
      }
    }

    async function hubDeleteGuest(username) {
      if (!confirm(`Delete guest "${username}"? This cannot be undone.`)) return;
      try {
        const res  = await apiFetch('?action=delete-guest', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          await hubLoadUsersPanel();
        } else {
          alert(data.error || 'Error deleting guest.');
        }
      } catch { alert('Connection error.'); }
    }

    // ── Themes panel (admin only) ──────────────────────────────────────────────
    const HUB_WIKI_SETTINGS_API = 'wiki/wiki-api.php';
    let themesOpen = false;

    const THEME_OPTIONS = ['default.css', 'flower-power.css', 'impact.css'];
    const THEME_MODULES = [
      { id: 'th-hub',      key: 'hubTheme' },
      { id: 'th-wiki',     key: 'theme' },
      { id: 'th-marks',    key: 'pqTheme' },
      { id: 'th-quests',   key: 'questsTheme' },
      { id: 'th-feedback', key: 'feedbackTheme' },
      { id: 'th-projects', key: 'projectsTheme' },
      { id: 'th-calendar', key: 'calendarTheme' },
    ];

    async function hubToggleThemesPanel() {
      const overlay = document.getElementById('themes-overlay');
      const panel   = document.getElementById('themes-panel');
      themesOpen = !themesOpen;
      overlay.style.display = panel.style.display = themesOpen ? 'block' : 'none';
      if (!themesOpen) {
        document.getElementById('themes-save-status').textContent = '';
        return;
      }
      await hubLoadThemesPanel();
    }

    async function hubLoadThemesPanel() {
      const statusEl = document.getElementById('themes-save-status');
      statusEl.style.color = '#c0392b';
      statusEl.textContent = 'Loading…';
      try {
        const res = await apiFetch(HUB_WIKI_SETTINGS_API + '?action=get-settings');
        if (!res || !res.ok) { statusEl.textContent = 'Could not load settings.'; return; }
        const settings = await res.json();
        for (const m of THEME_MODULES) {
          const sel = document.getElementById(m.id);
          if (!sel) continue;
          const val = settings[m.key] ?? 'default.css';
          sel.value = THEME_OPTIONS.includes(val) ? val : 'default.css';
        }
        statusEl.textContent = '';
      } catch { statusEl.textContent = 'Connection error.'; }
    }

    document.getElementById('themes-form').addEventListener('submit', async e => {
      e.preventDefault();
      const statusEl = document.getElementById('themes-save-status');
      statusEl.style.color = '#c0392b';
      statusEl.textContent = 'Saving…';
      try {
        const currentRes = await apiFetch(HUB_WIKI_SETTINGS_API + '?action=get-settings');
        if (!currentRes || !currentRes.ok) { statusEl.textContent = 'Could not load current settings.'; return; }
        const current = await currentRes.json();
        const payload = Object.assign({}, current);
        for (const m of THEME_MODULES) {
          const sel = document.getElementById(m.id);
          if (sel) payload[m.key] = sel.value;
        }
        payload.jwtSecret = '';
        payload.adminHash = null;
        const saveRes = await apiFetch(HUB_WIKI_SETTINGS_API + '?action=save-settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await saveRes.json().catch(() => ({}));
        if (saveRes.ok) {
          statusEl.style.color = '#2f7d32';
          statusEl.textContent = 'Themes saved.';
          // Apply hub theme live
          const hubTheme = document.getElementById('th-hub')?.value ?? 'default.css';
          const link = document.getElementById('hub-theme-link');
          if (link) link.href = 'templates/' + hubTheme;
          setTimeout(() => { if (themesOpen) document.getElementById('themes-save-status').textContent = ''; }, 2000);
        } else {
          statusEl.textContent = data.error || 'Error saving themes.';
        }
      } catch { statusEl.textContent = 'Connection error.'; }
    });

    // ── Security panel (admin only) ────────────────────────────────────────────
    let securityOpen = false;

    async function hubToggleSecurityPanel() {
      const overlay = document.getElementById('security-overlay');
      const panel   = document.getElementById('security-panel');
      securityOpen = !securityOpen;
      overlay.style.display = panel.style.display = securityOpen ? 'block' : 'none';
      if (!securityOpen) {
        document.getElementById('security-save-status').textContent = '';
        return;
      }
      await hubLoadSecurityPanel();
    }

    async function hubLoadSecurityPanel() {
      const statusEl = document.getElementById('security-save-status');
      statusEl.style.color = '#c0392b';
      statusEl.textContent = 'Loading…';
      try {
        const res = await apiFetch(HUB_WIKI_SETTINGS_API + '?action=get-settings');
        if (!res || !res.ok) {
          statusEl.textContent = 'Could not load security settings.';
          return;
        }
        const settings = await res.json();
        document.getElementById('security-guest-login-enabled').checked = settings.guestLoginEnabled !== false;
        document.getElementById('security-token-ttl').value = settings.tokenTtl ?? 3600;
        statusEl.textContent = '';
      } catch {
        statusEl.textContent = 'Connection error.';
      }
    }

    document.getElementById('security-form').addEventListener('submit', async e => {
      e.preventDefault();
      const statusEl = document.getElementById('security-save-status');
      statusEl.style.color = '#c0392b';
      statusEl.textContent = 'Saving…';

      const guestLoginEnabled = document.getElementById('security-guest-login-enabled').checked;
      const tokenTtl = parseInt(document.getElementById('security-token-ttl').value, 10);

      if (!Number.isInteger(tokenTtl) || tokenTtl < 60 || tokenTtl > 86400) {
        statusEl.textContent = 'Token TTL must be between 60 and 86400 seconds.';
        return;
      }

      try {
        const currentRes = await apiFetch(HUB_WIKI_SETTINGS_API + '?action=get-settings');
        if (!currentRes || !currentRes.ok) {
          statusEl.textContent = 'Could not load current settings.';
          return;
        }
        const current = await currentRes.json();
        const payload = {
          wikiName: (current.wikiName || '').trim(),
          theme: current.theme,
          hljsTheme: current.hljsTheme,
          codeLineNumbers: !!current.codeLineNumbers,
          guestOdtDownload: current.guestOdtDownload !== false,
          guestToc: current.guestToc !== false,
          guestIndex: current.guestIndex !== false,
          guestLoginEnabled,
          tokenTtl,
          jwtSecret: '',
          adminHash: null,
        };
        const saveRes = await apiFetch(HUB_WIKI_SETTINGS_API + '?action=save-settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await saveRes.json().catch(() => ({}));
        if (saveRes.ok) {
          statusEl.style.color = '#2f7d32';
          statusEl.textContent = 'Security settings saved.';
        } else {
          statusEl.textContent = data.error || 'Error saving security settings.';
        }
      } catch {
        statusEl.textContent = 'Connection error.';
      }
    });

    // ── Reset password dialog (admin → any user) ─────────────────────────────────
    let _resetPasswordTarget = '';

    function hubShowResetPasswordModal(username) {
      _resetPasswordTarget = username;
      document.getElementById('reset-password-title').textContent = `Reset password for @${username}`;
      document.getElementById('reset-pass-new').value     = '';
      document.getElementById('reset-pass-confirm').value = '';
      document.getElementById('reset-password-status').textContent = '';
      document.getElementById('reset-password-overlay').style.display = 'block';
      document.getElementById('reset-password-dialog').style.display  = 'block';
      document.getElementById('reset-pass-new').focus();
    }

    function hubHideResetPasswordModal() {
      _resetPasswordTarget = '';
      document.getElementById('reset-password-overlay').style.display = 'none';
      document.getElementById('reset-password-dialog').style.display  = 'none';
    }

    document.getElementById('reset-password-cancel').addEventListener('click', hubHideResetPasswordModal);
    document.getElementById('reset-password-overlay').addEventListener('click', hubHideResetPasswordModal);
    document.getElementById('reset-password-ok').addEventListener('click', async () => {
      const statusEl = document.getElementById('reset-password-status');
      const pass     = document.getElementById('reset-pass-new').value;
      const pass2    = document.getElementById('reset-pass-confirm').value;
      statusEl.textContent = '';
      if (!pass)  { statusEl.textContent = 'Password is required.'; return; }
      if (pass !== pass2) { statusEl.textContent = 'Passwords do not match.'; return; }
      const hash = await sha256(pass);
      statusEl.textContent = 'Resetting…';
      try {
        const res  = await apiFetch('?action=reset-password', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username: _resetPasswordTarget, hash })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          hubHideResetPasswordModal();
        } else {
          statusEl.textContent = data.error || 'Error resetting password.';
        }
      } catch { statusEl.textContent = 'Connection error.'; }
    });

    // ── Admin card inline edit ────────────────────────────────────────────────────
    function hubStartEditAdmin() {
      document.getElementById('admin-card-edit').style.display = '';
      document.getElementById('users-admin-name').focus();
    }

    function hubCancelEditAdmin() {
      document.getElementById('admin-card-edit').style.display = 'none';
      document.getElementById('users-admin-status').textContent = '';
    }

    async function hubSubmitEditAdmin() {
      const statusEl  = document.getElementById('users-admin-status');
      const adminUser = document.getElementById('users-admin-name').value.trim().toLowerCase();
      const adminName = document.getElementById('users-admin-displayname').value.trim();
      statusEl.textContent = '';
      if (!/^[a-z0-9_]{2,32}$/.test(adminUser)) {
        statusEl.textContent = 'Username: 2–32 chars, only a-z, 0-9, _';
        return;
      }
      if (!adminName) {
        statusEl.textContent = 'Name cannot be empty.';
        return;
      }
      statusEl.textContent = 'Saving…';
      try {
        const res = await apiFetch('?action=save-users', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ adminUser, adminName, adminHash: null })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          const currentUser = getUser();
          if (currentUser !== adminUser) {
            hubToggleUsersPanel();
            setTimeout(hubLogout, 500);
          } else {
            await hubLoadUsersPanel();
          }
        } else {
          statusEl.textContent = data.error || 'Error saving admin.';
        }
      } catch {
        statusEl.textContent = 'Connection error.';
      }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // ── Change-password panel (self-service) ────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════════
    let changePassOpen = false;

    function hubToggleChangePasswordPanel() {
      changePassOpen = !changePassOpen;
      document.getElementById('change-password-overlay').style.display = changePassOpen ? 'block' : 'none';
      document.getElementById('change-password-panel').style.display   = changePassOpen ? 'block' : 'none';
      if (changePassOpen) {
        document.getElementById('change-pass-new').value     = '';
        document.getElementById('change-pass-confirm').value = '';
        document.getElementById('change-password-status').textContent = '';
        document.getElementById('change-pass-new').focus();
      }
    }

    document.getElementById('change-password-form').addEventListener('submit', async e => {
      e.preventDefault();
      const statusEl = document.getElementById('change-password-status');
      const pass     = document.getElementById('change-pass-new').value;
      const pass2    = document.getElementById('change-pass-confirm').value;
      statusEl.textContent = '';
      if (!pass)  { statusEl.textContent = 'Password is required.'; return; }
      if (pass !== pass2) { statusEl.textContent = 'Passwords do not match.'; return; }
      const hash = await sha256(pass);
      statusEl.textContent = 'Saving…';
      try {
        const res  = await apiFetch('?action=change-password', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ hash })
        });
        const data = await res.json().catch(() => ({}));
        if (res.ok) {
          hubToggleChangePasswordPanel();
        } else {
          statusEl.textContent = data.error || 'Error changing password.';
        }
      } catch { statusEl.textContent = 'Connection error.'; }
    });
