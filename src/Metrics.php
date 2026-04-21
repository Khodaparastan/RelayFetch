<?php
declare(strict_types=1);

/**
 * Atomically increment one or more metric counters.
 *
 * Usage:
 *   increment_metrics(['requests_total' => 1, 'bytes_proxied' => 1048576]);
 */
function increment_metrics(array $deltas): void
{
  ensure_data_dir();
  $path = DATA_DIR . "/metrics.json";

  $fh = fopen($path, "c+");
  if (!$fh) {
    return;
  }

  flock($fh, LOCK_EX);

  $size = fstat($fh)["size"] ?? 0;
  $current = $size > 0 ? json_decode(fread($fh, $size), true) : [];
  if (!is_array($current)) {
    $current = [];
  }

  foreach ($deltas as $key => $delta) {
    $current[$key] = ($current[$key] ?? 0) + $delta;
  }
  $current["last_updated"] = time();

  rewind($fh);
  ftruncate($fh, 0);
  fwrite(
    $fh,
    json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
  );
  flock($fh, LOCK_UN);
  fclose($fh);
}

/**
 * Read all metrics. Returns an array with defaults for missing keys.
 */
function read_metrics(): array
{
  $path = DATA_DIR . "/metrics.json";
  $defaults = [
    "requests_total" => 0,
    "requests_download" => 0,
    "requests_api" => 0,
    "requests_error" => 0,
    "bytes_proxied" => 0,
    "cache_hits" => 0,
    "cache_misses" => 0,
    "last_updated" => null,
    "last_reset" => null,
  ];

  if (!file_exists($path)) {
    return $defaults;
  }

  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

/**
 * Reset all metrics counters.
 */
function reset_metrics(): void
{
  ensure_data_dir();
  file_put_contents(
    DATA_DIR . "/metrics.json",
    json_encode(["last_reset" => time()], JSON_PRETTY_PRINT),
    LOCK_EX,
  );
}

/**
 * Format bytes into human-readable string.
 */
function format_bytes(int $bytes): string
{
  $units = ["B", "KiB", "MiB", "GiB", "TiB"];
  $i = 0;
  $b = (float) $bytes;
  while ($b >= 1024 && $i < count($units) - 1) {
    $b /= 1024;
    $i++;
  }
  return round($b, 2) . " " . $units[$i];
}
