    // ═══════════════════════════════════════════════════════════════════════════
    // lib/auth-client.js — Generic JWT auth utilities for the browser.
    //
    // Provides session-storage helpers, SHA-256 hashing and an authenticated
    // fetch wrapper that can be shared by any application using the same
    // wekickwiki JWT auth system.
    //
    // Load this file BEFORE the application's main JS file:
    //   <script src="lib/auth-client.js"></script>
    //   <script src="wiki.js"></script>
    //
    // Call setOnUnauthorized(fn) from the application script to customise
    // what happens when a 401 Unauthorized response is received:
    //   setOnUnauthorized(logout);  // wiki.js does this after defining logout()
    // ═══════════════════════════════════════════════════════════════════════════

    // ── Session storage helpers ──────────────────────────────────────────────────
    function getToken() {
      return sessionStorage.getItem('wkw_token');
    }

    function getRole() {
      return sessionStorage.getItem('wkw_role');
    }

    function getUser() {
      return sessionStorage.getItem('wkw_user');
    }

    function getName() {
      return sessionStorage.getItem('wkw_name');
    }

    function setToken(token) {
      sessionStorage.setItem('wkw_token', token);
      try {
        const payload = JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
        if (payload.sub)  sessionStorage.setItem('wkw_user', payload.sub);
        if (payload.role) sessionStorage.setItem('wkw_role', payload.role);
        if (payload.name) sessionStorage.setItem('wkw_name', payload.name);
      } catch {}
    }

    function clearToken() {
      sessionStorage.removeItem('wkw_token');
      sessionStorage.removeItem('wkw_role');
      sessionStorage.removeItem('wkw_user');
      sessionStorage.removeItem('wkw_name');
    }

    // ── SHA-256 ──────────────────────────────────────────────────────────────────
    async function sha256(msg) {
      // crypto.subtle requires a secure context (HTTPS/localhost).
      // Fall back to a pure-JS SHA-256 so the app works over plain HTTP too.
      if (typeof crypto !== 'undefined' && crypto.subtle) {
        const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(msg));
        return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
      }
      return sha256Fallback(msg);
    }

    // Pure-JS SHA-256 (RFC 6234 / FIPS 180-4) — used when crypto.subtle is unavailable.
    function sha256Fallback(msg) {
      const K = [
        0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
        0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
        0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
        0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
        0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
        0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
        0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
        0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2,
      ];
      const bytes = new TextEncoder().encode(msg);
      const bits  = bytes.length * 8;
      const padLen = ((bytes.length % 64) < 56 ? 56 : 120) - (bytes.length % 64);
      const buf    = new Uint8Array(bytes.length + padLen + 8);
      buf.set(bytes);
      buf[bytes.length] = 0x80;
      const dv = new DataView(buf.buffer);
      dv.setUint32(buf.length - 4, bits >>> 0,  false);
      dv.setUint32(buf.length - 8, Math.floor(bits / 2**32), false);

      let [h0,h1,h2,h3,h4,h5,h6,h7] =
        [0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];

      const rotr = (x, n) => (x >>> n) | (x << (32 - n));
      for (let i = 0; i < buf.length; i += 64) {
        const w = new Uint32Array(64);
        for (let j = 0; j < 16; j++) w[j] = dv.getUint32(i + j * 4, false);
        for (let j = 16; j < 64; j++) {
          const s0 = rotr(w[j-15],7)  ^ rotr(w[j-15],18) ^ (w[j-15] >>> 3);
          const s1 = rotr(w[j-2], 17) ^ rotr(w[j-2], 19) ^ (w[j-2]  >>> 10);
          w[j] = (w[j-16] + s0 + w[j-7] + s1) >>> 0;
        }
        let [a,b,c,d,e,f,g,h] = [h0,h1,h2,h3,h4,h5,h6,h7];
        for (let j = 0; j < 64; j++) {
          const S1  = rotr(e,6) ^ rotr(e,11) ^ rotr(e,25);
          const ch  = (e & f) ^ (~e & g);
          const t1  = (h + S1 + ch + K[j] + w[j]) >>> 0;
          const S0  = rotr(a,2) ^ rotr(a,13) ^ rotr(a,22);
          const maj = (a & b) ^ (a & c) ^ (b & c);
          const t2  = (S0 + maj) >>> 0;
          [h,g,f,e,d,c,b,a] = [g,f,e,(d+t1)>>>0,c,b,a,(t1+t2)>>>0];
        }
        h0=(h0+a)>>>0; h1=(h1+b)>>>0; h2=(h2+c)>>>0; h3=(h3+d)>>>0;
        h4=(h4+e)>>>0; h5=(h5+f)>>>0; h6=(h6+g)>>>0; h7=(h7+h)>>>0;
      }
      return [h0,h1,h2,h3,h4,h5,h6,h7].map(n => n.toString(16).padStart(8,'0')).join('');
    }

    // ── Authenticated fetch ──────────────────────────────────────────────────────
    // Callback invoked when the server returns 401 Unauthorized.
    // Override with setOnUnauthorized(fn) from the application script.
    // Default: clear session storage (no UI redirect).
    let _onUnauthorized = () => { sessionStorage.clear(); };

    /**
     * Replace the callback that fires when apiFetch() receives a 401 response.
     * Call this from the application script after defining the logout/redirect function:
     *   setOnUnauthorized(logout);
     * @param {Function} fn - Zero-argument callback
     */
    function setOnUnauthorized(fn) {
      _onUnauthorized = fn;
    }

    /**
     * Authenticated wrapper around the browser Fetch API.
     * Automatically injects the Authorization: Bearer header using the stored JWT.
     * On a 401 Unauthorized response the registered callback (_onUnauthorized) is
     * invoked so the application can redirect to its login screen.
     * @param {string}  url  - Request URL (relative or absolute)
     * @param {object}  opts - Optional fetch options (method, headers, body, …)
     * @returns {Promise<Response>} The raw fetch Response
     */
    async function apiFetch(url, opts = {}) {
      opts.headers = {
        ...(opts.headers || {}),
        'Authorization': 'Bearer ' + getToken()
      };
      const res = await fetch(url, opts);
      if (res.status === 401) {
        _onUnauthorized();
        return res;
      }
      return res;
    }

    /**
     * Redirect to loginUrl if no JWT is present in sessionStorage.
     * Call at the top of each module page (marks.php, quests.php, wiki.php).
     * @param {string} loginUrl - The login/hub page (default: 'index.php')
     */
    function requireAuth(loginUrl = 'index.php') {
      if (!getToken()) {
        window.location.href = loginUrl;
      }
    }
