<?php
declare(strict_types=1);

set_time_limit(0);
ini_set("memory_limit", "64M");

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/src/Helpers.php";
require_once __DIR__ . "/src/Security.php";
require_once __DIR__ . "/src/Downloader.php";
require_once __DIR__ . "/src/Relay.php";
require_once __DIR__ . "/src/UI.php";
require_once __DIR__ . "/src/Logger.php";
require_once __DIR__ . "/src/Metrics.php";
require_once __DIR__ . "/src/Cache.php";
require_once __DIR__ . "/src/Tokens.php";
require_once __DIR__ . "/src/AdminAuth.php";
require_once __DIR__ . "/src/AdminUI.php";

// ── Admin UI ──────────────────────────────────────────────────────────────────
if (($_GET["mode"] ?? "") === "admin") {
  show_admin();
  exit();
}

// ── Show UI when no URL provided ──────────────────────────────────────────────
if (empty($_GET["url"])) {
  show_ui();
  exit();
}

// ── Initialise request record ─────────────────────────────────────────────────
// This is the single source of truth for the log entry.
// Every stage below mutates it. The shutdown function writes it once.
$requestStartTime = hrtime(true);

$logRecord = [
  "id" => generate_id(),
  "ts" => time(),
  "method" => strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET"),
  "mode" => "download", // overwritten if API relay
  "client_ip" => resolve_client_ip(),
  "token" => resolve_token_label(),
  "url" => "", // filled after sanitisation
  "effective_url" => "", // filled after HEAD probe
  "status" => 0, // filled per exit path
  "mime" => "", // filled after MIME resolution
  "bytes" => 0, // filled after stream
  "duration_ms" => 0, // filled by shutdown function
  "cached" => false,
  "error" => null,
];

// Register shutdown — fires on every exit(), bail(), or fatal error.
// This is the single place that writes the log and updates metrics.
register_shutdown_function(function () use (
  &$logRecord,
  $requestStartTime,
): void {
  // Calculate wall-clock duration
  $logRecord["duration_ms"] = (int) ((hrtime(true) - $requestStartTime) / 1e6);

  // If status was never set (fatal/unexpected exit), mark it as 500
  if ($logRecord["status"] === 0) {
    $logRecord["status"] = 500;
    $logRecord["error"] = $logRecord["error"] ?? "Unexpected termination";
  }

  // Write log entry
  log_request($logRecord);

  // Update metrics counters
  $isError = $logRecord["status"] >= 400;
  $deltas = [
    "requests_total" => 1,
    "bytes_proxied" => $logRecord["bytes"],
  ];

  if ($logRecord["mode"] === "api") {
    $deltas["requests_api"] = 1;
  } else {
    $deltas["requests_download"] = 1;
  }

  if ($isError) {
    $deltas["requests_error"] = 1;
  }

  if ($logRecord["cached"]) {
    $deltas["cache_hits"] = 1;
  } elseif ($logRecord["mode"] === "download") {
    // Only download requests are cache-eligible; exclude api/redirect from miss count.
    $deltas["cache_misses"] = 1;
  }

  increment_metrics($deltas);
});

// Expose $logRecord to bail() via the global slot it reads.
$GLOBALS["RELAYFETCH_LOG_RECORD"] = &$logRecord;

// ── Token check ───────────────────────────────────────────────────────────────
verify_token();

// ── Sanitise raw inputs ───────────────────────────────────────────────────────
$url = trim(str_replace("\0", "", (string) $_GET["url"]));
$url = urldecode($url);

$logRecord["url"] = redact_url_secrets($url); // record URL with token redacted

// ── URL validation + SSRF check ───────────────────────────────────────────────
// bail() inside validate_url() will trigger the shutdown function,
// which will log the record with status=400 set by bail() below.
$security = validate_url($url);
$host = $security["host"];
$resolved = $security["resolvedIp"];
$parsed = $security["parsed"];

// ── HEAD probe ────────────────────────────────────────────────────────────────
$meta = head_probe($url, $host, $resolved);
$contentType = $meta["contentType"];
$contentLength = $meta["contentLength"];
$effectiveUrl = $meta["effectiveUrl"];

$logRecord["effective_url"] = $effectiveUrl;

// ── Re-validate host after redirects ─────────────────────────────────────────
$redirectSecurity = revalidate_redirect($effectiveUrl, $host, $resolved);
$host = $redirectSecurity["host"];
$resolved = $redirectSecurity["resolvedIp"];

// ── Webpage redirect ──────────────────────────────────────────────────────────
$rawMime = strtolower(trim(explode(";", $contentType)[0]));
$webpageMimes = ["text/html", "application/xhtml+xml"];
$effectivePath = parse_url($effectiveUrl, PHP_URL_PATH) ?? "/";
$isWebpageMime = in_array($rawMime, $webpageMimes, true);
$isRootPath =
  $effectivePath === "/" || $effectivePath === "" || $effectivePath === null;

if ($isWebpageMime || $isRootPath) {
  $logRecord["status"] = 302;
  $logRecord["mode"] = "redirect";
  header("Location: " . $effectiveUrl, true, 302);
  exit();
}

// ── API relay mode ────────────────────────────────────────────────────────────
if (is_api_request()) {
  $logRecord["mode"] = "api";

  $forwardHeaders = [];
  if (!empty($_GET["headers"])) {
    $decoded = json_decode(urldecode((string) $_GET["headers"]), true);
    if (is_array($decoded)) {
      $forwardHeaders = $decoded;
    }
  }

  // Capture the response code and byte count from the relay
  // by wrapping stream output counting into the relay call.
  // relay_request() sets $logRecord entries via the passed reference.
  relay_request(
    $effectiveUrl,
    $host,
    $resolved,
    $forwardHeaders,
    $logRecord, // ← pass by reference so relay can update status + bytes
  );

  exit();
}

// ── Resolve filename ──────────────────────────────────────────────────────────
if (!empty($_GET["filename"])) {
  $filename = urldecode(
    basename(str_replace("\0", "", (string) $_GET["filename"])),
  );
} else {
  $filename = fetch_filename_from_headers($effectiveUrl, $host, $resolved);
  if (empty($filename)) {
    $filename =
      basename(strtok(urldecode($parsed["path"] ?? ""), "?")) ?: "download";
  }
}

$filename = sanitize_filename($filename);

// ── Resolve MIME type ─────────────────────────────────────────────────────────
$mimeType = resolve_mime($contentType);
$logRecord["mime"] = $mimeType;

// ── Stream ────────────────────────────────────────────────────────────────────
// stream_file() returns ['bytes' => int, 'status' => int].
$streamResult = stream_file(
  $effectiveUrl,
  $host,
  $resolved,
  $filename,
  $mimeType,
  $contentLength,
);

$logRecord["status"] = $streamResult["status"];
$logRecord["bytes"] = $streamResult["bytes"];
// duration_ms and log write happen in the shutdown function
