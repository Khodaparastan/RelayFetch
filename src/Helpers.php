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
  // If request logging is active, persist the real error status/message
  // so the shutdown logger does not misclassify this as a 500.
  if (isset($GLOBALS["RELAYFETCH_LOG_RECORD"]) && is_array($GLOBALS["RELAYFETCH_LOG_RECORD"])) {
    $GLOBALS["RELAYFETCH_LOG_RECORD"]["status"] = $code;
    $GLOBALS["RELAYFETCH_LOG_RECORD"]["error"] = $title . ": " . $message;
  }

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
 * Redact sensitive query parameters and URL credentials before logging.
 */
function redact_url_secrets(string $url): string
{
  $parts = parse_url($url);
  if ($parts === false) {
    return $url;
  }

  $query = [];
  if (!empty($parts["query"])) {
    parse_str($parts["query"], $query);
    $secretKeys = ["token", "apikey", "api_key", "key", "secret", "auth", "sig", "signature", "password", "pass", "access_token", "client_secret"];
    foreach ($secretKeys as $k) {
      if (array_key_exists($k, $query)) {
        $query[$k] = "[REDACTED]";
      }
    }
  }

  $scheme = isset($parts["scheme"]) ? $parts["scheme"] . "://" : "";
  // Redact URL userinfo (user:pass@host)
  $auth = "";
  if (isset($parts["user"])) {
    $auth = "[REDACTED]";
    if (isset($parts["pass"])) {
      $auth .= ":" . "[REDACTED]";
    }
    $auth .= "@";
  }
  $host = $parts["host"] ?? "";
  $port = isset($parts["port"]) ? ":" . $parts["port"] : "";
  $path = $parts["path"] ?? "";
  $qs = !empty($query) ? "?" . http_build_query($query) : (isset($parts["query"]) ? "?" . $parts["query"] : "");
  $frag = isset($parts["fragment"]) ? "#" . $parts["fragment"] : "";

  return $scheme . $auth . $host . $port . $path . $qs . $frag;
}

/**
 * Resolve the client IP address.
 * X-Forwarded-For is only trusted when TRUST_PROXY is defined and true;
 * otherwise REMOTE_ADDR is always used.
 */
function resolve_client_ip(): string
{
  $remoteAddr = $_SERVER["REMOTE_ADDR"] ?? "";
  if (defined("TRUST_PROXY") && TRUST_PROXY) {
    $xff = trim($_SERVER["HTTP_X_FORWARDED_FOR"] ?? "");
    if ($xff !== "") {
      // Take the leftmost (client) entry from the XFF chain.
      $first = trim(explode(",", $xff)[0]);
      if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
        return $first;
      }
    }
  }
  return $remoteAddr;
}

/**
 * Resolve a human-readable label for the token used in this request.
 * Returns the token label if found, "anonymous" if no token required,
 * or "unknown" if a token was provided but not matched.
 */
function resolve_token_label(): string
{
  if (!defined("SECRET_TOKEN") || SECRET_TOKEN === "") {
    return "anonymous";
  }
  $headerToken = $_SERVER["HTTP_X_TOKEN"] ?? "";
  $queryToken  = $_GET["token"] ?? "";
  $provided    = $headerToken !== "" ? $headerToken : $queryToken;
  if ($provided === "") {
    return "anonymous";
  }
  // Look up label from token store if available.
  if (function_exists("tokens_list")) {
    foreach (tokens_list() as $t) {
      if (isset($t["token"]) && hash_equals($t["token"], $provided)) {
        return $t["label"] ?? "token";
      }
    }
  }
  return "token";
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
