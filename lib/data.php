<?php
// ═══════════════════════════════════════════════════════════════════════════
// lib/data.php — Centralised, flock-safe JSON file I/O helpers.
//
// All JSON data files in the application are read/written exclusively through
// these three functions so that concurrent requests don't corrupt the files.
//
// Functions:
//   data_read(string $path): array        — shared-lock JSON read
//   data_write(string $path, mixed $data) — exclusive-lock JSON write
//   data_next_id(array $arr): int         — max(id)+1, or 1 if empty
// ═══════════════════════════════════════════════════════════════════════════

// Reads a JSON file under a shared (read) lock and returns its contents as an array.
// Multiple readers can hold a shared lock simultaneously; a shared lock blocks any
// exclusive (write) lock so a read never sees a partially-written file.
// Returns an empty array when the file does not exist, cannot be opened, or
// contains invalid JSON (fail-safe: callers can always iterate the result).
function data_read(string $path): array
{
    if (!is_file($path)) return [];
    $fp = fopen($path, 'r');
    if (!$fp) return [];
    // LOCK_SH: shared lock — allows concurrent readers, blocks writers
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

// Writes $data to $path as pretty-printed JSON under an exclusive (write) lock.
// LOCK_EX passed to file_put_contents() blocks both other writers and readers
// until the write is complete, preventing torn reads.
// JSON_UNESCAPED_UNICODE keeps non-ASCII characters (e.g. accented letters)
// in their readable form instead of \uXXXX escape sequences.
function data_write(string $path, mixed $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

// Returns the next available integer ID for a flat array of objects that each
// have an 'id' field.  The returned value is max(existing ids) + 1, or 1 when
// the array is empty.  This ensures IDs are always unique within a single file
// even if records are deleted (IDs are never reused).
function data_next_id(array $arr): int
{
    $max = 0;
    foreach ($arr as $item) {
        if (isset($item['id']) && (int)$item['id'] > $max) $max = (int)$item['id'];
    }
    return $max + 1;
}
