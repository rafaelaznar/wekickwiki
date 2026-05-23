<?php
// ═══════════════════════════════════════════════════════════════════════════
// lib/backup-api.php — Centralised backup / restore for all data files.
//
// Included by index.php (the admin hub).  All endpoints require admin role.
//
// Download endpoints (GET):
//   ?action=backup-users      → users.json
//   ?action=backup-settings   → settings.json
//   ?action=backup-items      → items.json
//   ?action=backup-marks      → marks.json
//   ?action=backup-queries    → queries.json
//   ?action=backup-quests     → quests.json
//   ?action=backup-attempts   → attempts.json
//   ?action=backup-pages      → wiki pages as a tar archive
//
// Restore endpoints (POST, multipart with file field "file"):
//   ?action=restore-users
//   ?action=restore-settings
//   ?action=restore-items
//   ?action=restore-marks
//   ?action=restore-queries
//   ?action=restore-quests
//   ?action=restore-attempts
//
// Note: lib/auth.php must be loaded before this file.
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/data.php';

// ── Download helpers ─────────────────────────────────────────────────────────

function backup_stream_json(string $path, string $filename): void
{
    if (!is_file($path)) json_out(404, ['error' => 'File not found']);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Restore helper ───────────────────────────────────────────────────────────

function backup_restore_json(string $dest_path, string $action): void
{
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        json_out(400, ['error' => 'No file uploaded']);
    }
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_out(400, ['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    }
    // Restore accepts arrays or objects (settings is an object / associative array)
    if (!is_array($data)) {
        json_out(400, ['error' => 'JSON root must be an array or object']);
    }
    data_write($dest_path, $data);
    json_out(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════════════════
// Download endpoints
// ═══════════════════════════════════════════════════════════════════════════

$_ba = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($_ba, [
    'backup-users','backup-settings','backup-items','backup-marks',
    'backup-queries','backup-quests','backup-attempts','backup-pages'
], true)) {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $map = [
        'backup-users'     => [USERS_FILE,                    'users.json'],
        'backup-settings'  => [SETTINGS_FILE,                 'settings.json'],
        'backup-items'     => [__DIR__ . '/../items.json',    'items.json'],
        'backup-marks'     => [__DIR__ . '/../marks.json',    'marks.json'],
        'backup-queries'   => [__DIR__ . '/../queries.json',  'queries.json'],
        'backup-quests'    => [__DIR__ . '/../quests.json',   'quests.json'],
        'backup-attempts'  => [__DIR__ . '/../attempts.json', 'attempts.json'],
    ];

    if (isset($map[$_ba])) {
        backup_stream_json($map[$_ba][0], $map[$_ba][1]);
    }

    // Pages backup: stream a simple JSON index + content archive
    if ($_ba === 'backup-pages') {
        $pagesDir = __DIR__ . '/../pages';
        $pages    = [];
        if (is_dir($pagesDir)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                    $rel  = ltrim(str_replace($pagesDir, '', $file->getPathname()), '/\\');
                    $pages[$rel] = file_get_contents($file->getPathname());
                }
            }
        }
        $ts = date('Y-m-d_H-i-s');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="pages-backup-' . $ts . '.json"');
        echo json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Restore endpoints
// ═══════════════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_ba, [
    'restore-users','restore-settings','restore-items','restore-marks',
    'restore-queries','restore-quests','restore-attempts','restore-pages'
], true)) {
    $claims = require_auth();
    if (($claims['role'] ?? '') !== 'admin') json_out(403, ['error' => 'Forbidden']);

    $map = [
        'restore-users'    => USERS_FILE,
        'restore-settings' => SETTINGS_FILE,
        'restore-items'    => __DIR__ . '/../items.json',
        'restore-marks'    => __DIR__ . '/../marks.json',
        'restore-queries'  => __DIR__ . '/../queries.json',
        'restore-quests'   => __DIR__ . '/../quests.json',
        'restore-attempts' => __DIR__ . '/../attempts.json',
    ];

    if (isset($map[$_ba])) {
        backup_restore_json($map[$_ba], $_ba);
    }

    // Pages restore: JSON object mapping relative paths → content
    if ($_ba === 'restore-pages') {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            json_out(400, ['error' => 'No file uploaded']);
        }
        $raw  = file_get_contents($_FILES['file']['tmp_name']);
        $data = json_decode($raw, true);
        if (!is_array($data)) json_out(400, ['error' => 'Invalid pages backup JSON']);

        $pagesDir = __DIR__ . '/../pages';
        $written  = 0;
        foreach ($data as $rel => $content) {
            // Safety: only .md files, no path traversal
            $rel = ltrim((string)$rel, '/\\');
            if (!preg_match('/^[a-zA-Z0-9_\-\/]+\.md$/', $rel)) continue;
            $dest = realpath($pagesDir . '/' . dirname($rel));
            if ($dest === false || strpos($dest . '/', realpath($pagesDir) . '/') !== 0) continue;
            $fullPath = $pagesDir . '/' . $rel;
            $dir      = dirname($fullPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($fullPath, (string)$content, LOCK_EX);
            $written++;
        }
        json_out(200, ['ok' => true, 'pages' => $written]);
    }
}
