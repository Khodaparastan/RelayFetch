<?php
declare(strict_types=1);

/**
 * Load the shared stylesheet as an inline <style> block.
 * Falls back to an empty string if the file is missing.
 */
function load_css(): string
{
  $path = __DIR__ . "/../styles.css";
  return file_exists($path) ? file_get_contents($path) : "";
}

/**
 * Send a styled HTML error page and exit.
 */

function bail(int $code, string $title, string $message): void
{
  http_response_code($code);
  $safeTitle = htmlspecialchars($title);
  $safeMessage = htmlspecialchars($message);

  echo <<<HTML
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Error {$code} — {$safeTitle}</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body class="center">
    <div class="card error-card">
      <span class="error-icon">⚠️</span>
      <h1>HTTP {$code}</h1>
      <p class="subtitle">{$safeTitle}</p>
      <p class="msg">{$safeMessage}</p>
      <a href="?" class="btn btn-ghost">← Go Back</a>
    </div>
  </body>
  </html>
  HTML;

  exit();
}

/**
 * Sanitize a filename: keep extension, replace unsafe chars.
 */
function sanitize_filename(string $filename): string
{
  $filename = preg_replace("/[^\w.\-]/u", "_", $filename);
  $filename = preg_replace("/_+/", "_", $filename);
  $filename = trim($filename, "_") ?: "download";

  // Prevent blocked extensions slipping through via ?filename=
  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  if (in_array($ext, BLOCKED_EXTENSIONS, true)) {
    $filename .= ".download";
  }

  return $filename;
}

/**
 * Resolve a safe MIME type, neutralizing executable types.
 */
function resolve_mime(string $contentType): string
{
  if (empty($contentType)) {
    return "application/octet-stream";
  }

  $mime = trim(explode(";", $contentType)[0]);

  $dangerousMimes = [
    "text/html",
    "application/xhtml+xml",
    "application/x-httpd-php",
    "application/x-php",
    "text/javascript",
    "application/javascript",
    "application/x-javascript",
    "text/xml",
    "application/xml",
  ];
  if (in_array(strtolower($mime), $dangerousMimes, true)) {
    return "application/octet-stream";
  }

  return $mime;
}
