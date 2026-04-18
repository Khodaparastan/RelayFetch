<?php
declare(strict_types=1);

/**
 * Append a structured request record to the rolling NDJSON log.
 */
function log_request(array $record): void
{
    if (!LOG_ENABLED) {
        return;
    }

    ensure_data_dir();

    $record['id'] ??= generate_id();
    $record['ts'] ??= time();

    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $path = DATA_DIR . '/requests.log';

    // Exclusive lock — safe for concurrent requests.
    // Trim is done inside the same lock to prevent race conditions.
    $fh = fopen($path, 'c+');
    if ($fh && flock($fh, LOCK_EX)) {
        // Append new line
        fseek($fh, 0, SEEK_END);
        fwrite($fh, $line);

        // Rolling trim — keep only last LOG_MAX_LINES lines
        fseek($fh, 0);
        $contents = stream_get_contents($fh);
        $lines = array_filter(explode("\n", $contents), fn($l) => $l !== '');
        if (count($lines) > LOG_MAX_LINES) {
            $trimmed = implode("\n", array_slice($lines, -LOG_MAX_LINES)) . "\n";
            ftruncate($fh, 0);
            fseek($fh, 0);
            fwrite($fh, $trimmed);
        }

        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Read the log and return records newest-first.
 * Optionally filter by field values: ['method' => 'POST', 'status' => 200]
 */
function read_log(int $limit = 100, int $offset = 0, array $filters = []): array
{
    $path = DATA_DIR . '/requests.log';
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }

    // Newest first
    $lines = array_reverse($lines);

    $records = [];
    $skipped = 0;
    $count   = 0;

    foreach ($lines as $line) {
        $rec = json_decode($line, true);
        if (!is_array($rec)) {
            continue;
        }

        // Apply filters
        foreach ($filters as $key => $value) {
            if (($rec[$key] ?? null) != $value) {
                continue 2;
            }
        }

        if ($skipped < $offset) {
            $skipped++;
            continue;
        }

        $records[] = $rec;
        $count++;

        if ($count >= $limit) {
            break;
        }
    }

    return $records;
}

/**
 * Count total log entries, optionally filtered.
 */
function count_log(array $filters = []): int
{
    $path = DATA_DIR . '/requests.log';
    if (!file_exists($path)) {
        return 0;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return 0;
    }

    if (empty($filters)) {
        return count($lines);
    }

    $count = 0;
    foreach ($lines as $line) {
        $rec = json_decode($line, true);
        if (!is_array($rec)) {
            continue;
        }
        foreach ($filters as $key => $value) {
            if (($rec[$key] ?? null) != $value) {
                continue 2;
            }
        }
        $count++;
    }

    return $count;
}

/**
 * Clear the entire log.
 */
function clear_log(): void
{
    $path = DATA_DIR . '/requests.log';
    if (file_exists($path)) {
        file_put_contents($path, '', LOCK_EX);
    }
}

/**
 * Generate a short unique request ID.
 */
function generate_id(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * Ensure the data directory exists and is not web-accessible.
 */
function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
        throw new \RuntimeException("Cannot create data directory: " . DATA_DIR);
    }
    $htaccess = DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
}
