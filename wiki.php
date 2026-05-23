<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/wiki-api.php';

$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$baseHref  = $scriptDir . '/';

$settings = load_settings();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($settings['wikiName']) ?></title>
  <base href="<?= htmlspecialchars($baseHref) ?>">
  <link rel="icon" type="image/svg+xml" href="icon.svg">
  <script src="vendor/marked.min.js"></script>
  <script src="vendor/highlight.min.js"></script>
  <link id="hljs-theme-link" rel="stylesheet" href="vendor/highlight-themes/<?= htmlspecialchars($settings['hljsTheme'], ENT_QUOTES) ?>">
  <link rel="stylesheet" href="templates/<?= htmlspecialchars($settings['theme'], ENT_QUOTES) ?>">
</head>

<body>

  <!-- ── Wiki screen ──────────────────────────────────────── -->
  <div id="wiki-screen">
    <header>
      <a id="header-title" href="wiki.php" onclick="navigate('index');return false;"><img src="icon.svg" style="display:inline; width:1.5rem; height:1.5rem; margin-right:0.5rem; vertical-align:middle;" alt=""><?= htmlspecialchars($settings['wikiName']) ?></a>
      <div id="header-right">
        <span id="user-badge"></span>
        <button class="btn" id="mobile-menu-btn" aria-label="Menu" aria-expanded="false" onclick="toggleMobileMenu()"><svg viewBox="0 0 24 24" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div id="header-buttons">
        <button class="btn" id="toc-btn" title="Table of contents" aria-label="Table of contents" style="display:none" onclick="toggleToc()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="3" y1="5" x2="21" y2="5" />
            <line x1="6" y1="10" x2="21" y2="10" />
            <line x1="10" y1="15" x2="21" y2="15" />
            <line x1="6" y1="20" x2="21" y2="20" />
          </svg></button>
        <button class="btn" id="index-btn" title="Index" aria-label="Index" style="display:none" onclick="toggleIndex()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="21" y1="5" x2="3" y2="5" />
            <line x1="18" y1="10" x2="3" y2="10" />
            <line x1="14" y1="15" x2="3" y2="15" />
            <line x1="18" y1="20" x2="3" y2="20" />
          </svg></button>
        <button class="btn" id="edit-btn" title="Edit" aria-label="Edit" style="display:none" onclick="toggleEdit()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
          </svg></button>
        <button class="btn btn-danger" id="delete-btn" title="Delete" aria-label="Delete" style="display:none" onclick="deletePage()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
            <path d="M10 11v6" />
            <path d="M14 11v6" />
            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
          </svg></button>
        <button class="btn" id="home-btn" title="Home" aria-label="Home" style="display:none" onclick="navigate('index')"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
          </svg></button>
        <button class="btn" id="top-btn" title="Go to top" aria-label="Go to top" style="display:none" onclick="window.scrollTo({top:0,behavior:'smooth'})"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="12" y1="19" x2="12" y2="5" />
            <polyline points="5 12 12 5 19 12" />
          </svg></button>
        <button class="btn" id="odt-btn" title="Download as ODT" aria-label="Download as ODT" style="display:none" onclick="downloadOdt()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
            <polyline points="14 2 14 8 20 8" />
            <line x1="12" y1="18" x2="12" y2="12" />
            <polyline points="9 15 12 18 15 15" />
          </svg></button>
        <button class="btn btn-danger" id="logout-btn" title="Sign out" aria-label="Sign out" onclick="window.location.href='index.php'"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <polyline points="16 17 21 12 16 7" />
            <line x1="21" y1="12" x2="9" y2="12" />
          </svg></button>
        <button class="btn" id="change-pass-btn" title="Change my password" aria-label="Change my password" style="display:none" onclick="toggleChangePasswordPanel()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg></button>
        <button class="btn" id="plugins-btn" title="Plugins" aria-label="Plugins" style="display:none" onclick="togglePluginsPanel()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
            <path d="M2 17l10 5 10-5"/>
            <path d="M2 12l10 5 10-5"/>
          </svg></button>
        </div>
      </div>
    </header>
    <nav id="nav"></nav>
    <main id="content"></main>

    <!-- Plugins panel -->
    <div id="plugins-overlay" onclick="togglePluginsPanel()"></div>
    <div id="plugins-panel">
      <h3>Plugins <button class="close-btn" onclick="togglePluginsPanel()">&times;</button></h3>
      <div id="plugins-list"></div>
    </div>

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

    <!-- Change password panel (guest self-service) -->
    <div id="change-password-overlay" onclick="toggleChangePasswordPanel()"></div>
    <div id="change-password-panel">
      <h3>Change my password <button class="close-btn" onclick="toggleChangePasswordPanel()">&times;</button></h3>
      <form id="change-password-form" novalidate>
        <label>New password
          <input type="password" id="change-pass-new" autocomplete="new-password" placeholder="••••••••">
        </label>
        <label>Confirm new password
          <input type="password" id="change-pass-confirm" autocomplete="new-password" placeholder="••••••••">
        </label>
        <div id="change-password-form-actions">
          <span id="change-password-status"></span>
          <button type="button" class="btn" onclick="toggleChangePasswordPanel()">Cancel</button>
          <button type="submit" class="btn btn-primary">Change password</button>
        </div>
      </form>
    </div>

    <!-- Confirm delete dialog -->
    <div id="confirm-delete-overlay"></div>
    <div id="confirm-delete-dialog" role="dialog" aria-modal="true">
      <p id="confirm-delete-msg"></p>
      <p class="confirm-delete-subtitle">This action cannot be undone.</p>
      <div class="confirm-delete-actions">
        <button class="btn" id="confirm-delete-cancel">Cancel</button>
        <button class="btn btn-danger" id="confirm-delete-ok">Delete</button>
      </div>
    </div>

    <div id="editor">
      <div id="editor-bar">
        <button class="btn btn-primary" title="Save" aria-label="Save" onclick="save()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
            <polyline points="17 21 17 13 7 13 7 21" />
            <polyline points="7 3 7 8 15 8" />
          </svg></button>
        <button class="btn" title="Cancel" aria-label="Cancel" onclick="cancelEdit()"><svg viewBox="0 0 24 24" aria-hidden="true">
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg></button>
        <span id="save-status"></span>
      </div>
      <textarea id="editor-area" spellcheck="false"></textarea>
    </div>
  </div>

  <div id="footer">
    <a href="https://github.com/rafaelaznar/wekickwiki"><?= htmlspecialchars($settings['wikiName']) ?> 2026 v2 | MIT Licensed | By Rafael Aznar</a>
  </div>

  <div id="toast"></div>

  <script>
    window.WKW_BASE = <?= json_encode($baseHref) ?>;
    window.WKW_CODE_LINE_NUMBERS = <?= $settings['codeLineNumbers'] ? 'true' : 'false' ?>;
    window.WKW_GUEST_ODT_DOWNLOAD = <?= $settings['guestOdtDownload'] ? 'true' : 'false' ?>;
    window.WKW_GUEST_TOC   = <?= $settings['guestToc']   ? 'true' : 'false' ?>;
    window.WKW_GUEST_INDEX = <?= $settings['guestIndex'] ? 'true' : 'false' ?>;
    window.WKW_API_BASE = 'wiki.php';
  </script>
  <script src="lib/auth-client.js?v=<?= filemtime(__DIR__ . '/lib/auth-client.js') ?>"></script>
  <script src="lib/app-client.js?v=<?= filemtime(__DIR__ . '/lib/app-client.js') ?>"></script>
  <script src="wiki.js?v=<?= filemtime(__DIR__ . '/wiki.js') ?>"></script>
  <?php foreach (front_plugins() as $pf): ?>
    <script src="front-plugins/<?= htmlspecialchars($pf, ENT_QUOTES) ?>"></script>
  <?php endforeach; ?>
</body>

</html>
