<?php
// ═══════════════════════════════════════════════════════════════════════════
// lib/data.php — Centralised, flock-safe JSON file I/O helpers.
//
// Functions:
//   data_read(string $path): array        — shared-lock JSON read
//   data_write(string $path, mixed $data) — exclusive-lock JSON write
//   data_next_id(array $arr): int         — max(id)+1, or 1 if empty
// ═══════════════════════════════════════════════════════════════════════════

function data_read(string $path): array
{
    if (!is_file($path)) return [];
    $fp = fopen($path, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function data_write(string $path, mixed $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function data_next_id(array $arr): int
{
    $max = 0;
    foreach ($arr as $item) {
        if (isset($item['id']) && (int)$item['id'] > $max) $max = (int)$item['id'];
    }
    return $max + 1;
}
