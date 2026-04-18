<?php
declare(strict_types=1);

/**
 * Return a cache entry for $url if it exists and hasn't expired.
 * Returns null on miss.
 */
function cache_get(string $url): ?array
{
  if (!CACHE_ENABLED) {
    return null;
  }

  $hash = cache_key($url);
  $metaPath = CACHE_DIR . "/" . $hash . ".meta";
  $bodyPath = CACHE_DIR . "/" . $hash . ".body";

  if (!file_exists($metaPath) || !file_exists($bodyPath)) {
    return null;
  }

  $meta = json_decode(file_get_contents($metaPath), true);
  if (!is_array($meta)) {
    return null;
  }

  // Expired?
  if (time() > ($meta["expires_at"] ?? 0)) {
    cache_delete($hash);
    return null;
  }

  return [
    "meta" => $meta,
    "body" => file_get_contents($bodyPath),
  ];
}

/**
 * Store a response in the cache.
 */
function cache_put(
  string $url,
  array $meta,
  string $body,
  int $ttl = CACHE_DEFAULT_TTL,
): void {
  if (!CACHE_ENABLED) {
    return;
  }

  ensure_cache_dir();

  $hash = cache_key($url);
  $meta["url"] = $url;
  $meta["cached_at"] = time();
  $meta["expires_at"] = time() + $ttl;
  $meta["ttl"] = $ttl;
  $meta["size"] = mb_strlen($body, '8bit');

  file_put_contents(
    CACHE_DIR . "/" . $hash . ".meta",
    json_encode($meta, JSON_PRETTY_PRINT),
    LOCK_EX,
  );
  file_put_contents(CACHE_DIR . "/" . $hash . ".body", $body, LOCK_EX);
}

/**
 * List all cache entries with metadata.
 */
function cache_list(): array
{
  ensure_cache_dir();

  $entries = [];
  foreach (glob(CACHE_DIR . "/*.meta") as $metaPath) {
    $meta = json_decode(file_get_contents($metaPath), true);
    if (!is_array($meta)) {
      continue;
    }
    $hash = basename($metaPath, ".meta");
    $meta["hash"] = $hash;
    $expiresAt = $meta["expires_at"] ?? 0;
    $meta["ttl_left"] = $expiresAt > 0 ? max(0, $expiresAt - time()) : 0;
    // Only mark expired if expires_at is a valid future timestamp that has passed.
    $meta["expired"] = $expiresAt > 0 && $meta["ttl_left"] === 0;
    $entries[] = $meta;
  }

  usort(
    $entries,
    fn($a, $b) => ($b["cached_at"] ?? 0) <=> ($a["cached_at"] ?? 0),
  );
  return $entries;
}

/**
 * Delete a single cache entry by hash.
 */
function cache_delete(string $hash): void
{
  @unlink(CACHE_DIR . "/" . $hash . ".meta");
  @unlink(CACHE_DIR . "/" . $hash . ".body");
}

/**
 * Flush all cache entries.
 */
function cache_flush(): int
{
  $count = 0;
  foreach (glob(CACHE_DIR . "/*.meta") as $f) {
    cache_delete(basename($f, ".meta"));
    $count++;
  }
  return $count;
}

/**
 * Flush only expired cache entries.
 */
function cache_flush_expired(): int
{
  $count = 0;
  foreach (cache_list() as $entry) {
    if ($entry["expired"]) {
      cache_delete($entry["hash"]);
      $count++;
    }
  }
  return $count;
}

/**
 * Total cache size in bytes.
 */
function cache_size(): int
{
  $total = 0;
  foreach (glob(CACHE_DIR . "/*.body") as $f) {
    $total += filesize($f) ?: 0;
  }
  return $total;
}

function cache_key(string $url): string
{
  return hash("sha256", $url);
}

function ensure_cache_dir(): void
{
  if (!is_dir(CACHE_DIR) && !mkdir(CACHE_DIR, 0750, true) && !is_dir(CACHE_DIR)) {
    throw new \RuntimeException("Cannot create cache directory: " . CACHE_DIR);
  }
  $htaccess = CACHE_DIR . "/.htaccess";
  if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Require all denied\n");
  }
}
