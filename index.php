<?php
declare(strict_types=1);

set_time_limit(0);
ini_set("memory_limit", "64M");

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/src/Helpers.php";
require_once __DIR__ . "/src/Security.php";
require_once __DIR__ . "/src/Downloader.php";
require_once __DIR__ . "/src/UI.php";

// ── Show UI when no URL provided ──────────────────────────────────────────────
if (empty($_GET["url"])) {
  show_ui();
  exit();
}

// ── Token check ───────────────────────────────────────────────────────────────
verify_token();

// ── Sanitise raw inputs (strip null bytes) ────────────────────────────────────
$url = trim(str_replace("\0", "", (string) $_GET["url"]));
$security = validate_url($url);
$host = $security["host"];
$resolved = $security["resolvedIp"];
$parsed = $security["parsed"];

// ── HEAD probe ────────────────────────────────────────────────────────────────
$meta = head_probe($url, $host, $resolved);
$contentType = $meta["contentType"];
$contentLength = $meta["contentLength"];
$effectiveUrl = $meta["effectiveUrl"];

// ── Resolve filename ──────────────────────────────────────────────────────────
if (!empty($_GET["filename"])) {
  $filename = basename(str_replace("\0", "", (string) $_GET["filename"]));
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

// ── Stream ────────────────────────────────────────────────────────────────────
stream_file(
  $effectiveUrl,
  $host,
  $resolved,
  $filename,
  $mimeType,
  $contentLength,
);
