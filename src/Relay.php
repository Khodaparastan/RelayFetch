<?php
declare(strict_types=1);

/**
 * Relay an API request to the remote URL and stream the response back.
 * Preserves: HTTP status, Content-Type, and response body.
 * Applies the same SSRF/IP-pinning protections as the downloader.
 */
function relay_request(
  string $effectiveUrl,
  string $host,
  string $resolvedIp,
  array $forwardHeaders = [],
  array &$logRecord = [],
): void {
  $method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");

  // Read request body for methods that carry one
  $requestBody = null;
  if (in_array($method, ["POST", "PUT", "PATCH"], true)) {
    $requestBody = file_get_contents("php://input");
  }

  $urlPort = (int) (parse_url($effectiveUrl, PHP_URL_PORT) ?? 0) ?: null;

  // Flush any output buffers
  while (ob_get_level()) {
    ob_end_clean();
  }

  $responseHeaders = [];
  $responseCode = 200;

  $ch = curl_init($effectiveUrl);

  $opts = [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false, // redirects already resolved via HEAD + revalidate_redirect
    CURLOPT_TIMEOUT => DL_TIMEOUT,
    CURLOPT_SSL_VERIFYPEER => SSL_VERIFY_PEER,
    CURLOPT_SSL_VERIFYHOST => SSL_VERIFY_PEER ? 2 : 0,
    CURLOPT_USERAGENT => USER_AGENT,
    CURLOPT_RESOLVE => build_resolve_pins($host, $resolvedIp, $urlPort),
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HEADER => false,
    CURLOPT_BUFFERSIZE => CHUNK_SIZE,

    // Stream response body directly to client
    CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$logRecord): int {
      echo $chunk;
      flush();
      $len = strlen($chunk);
      $logRecord["bytes"] = ($logRecord["bytes"] ?? 0) + $len;
      return $len;
    },

    // Capture and forward response headers
    CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (
      &$responseHeaders,
      &$responseCode,
    ): int {
      $trimmed = trim($header);

      // Capture HTTP status line
      if (preg_match("/^HTTP\/[\d.]+ (\d+)/i", $trimmed, $m)) {
        $responseCode = (int) $m[1];
        http_response_code($responseCode);
        return strlen($header);
      }

      // Forward safe response headers
      if ($trimmed !== "" && str_contains($trimmed, ":")) {
        [$name] = explode(":", $trimmed, 2);
        $nameLower = strtolower(trim($name));

        $allowedResponseHeaders = [
          "content-type",
          "content-length",
          "content-encoding",
          "content-language",
          "content-range",
          "cache-control",
          "etag",
          "last-modified",
          "x-request-id",
          "x-ratelimit-limit",
          "x-ratelimit-remaining",
          "x-ratelimit-reset",
          "retry-after",
          "link",
          "location",
        ];

        if (in_array($nameLower, $allowedResponseHeaders, true)) {
          header($trimmed);
        }
      }

      return strlen($header);
    },
  ];

  // Attach request body
  if ($requestBody !== null && $requestBody !== "") {
    $opts[CURLOPT_POSTFIELDS] = $requestBody;
  }

  // Build forwarded headers
  $curlHeaders = build_relay_headers($forwardHeaders);
  if (!empty($curlHeaders)) {
    $opts[CURLOPT_HTTPHEADER] = $curlHeaders;
  }

  if (defined("SSL_CAINFO") && SSL_CAINFO !== "") {
    curl_setopt($ch, CURLOPT_CAINFO, SSL_CAINFO);
  }

  curl_setopt_array($ch, $opts);

  $ok = curl_exec($ch);
  $error = curl_error($ch);
  curl_close($ch);

  $logRecord["status"] = $responseCode;

  if (!$ok || !empty($error)) {
    error_log("[Relay] Request error for {$effectiveUrl} — {$error}");
    // If headers haven't been sent yet, return a 502 to the client.
    if (!headers_sent()) {
      http_response_code(502);
      $logRecord["status"] = 502;
    }
  }
}

/**
 * Parse headers to forward to the upstream API.
 *
 * Accepts two sources (merged, explicit JSON takes priority):
 *  1. ?headers={"Authorization":"Bearer ...","X-Api-Key":"..."} (JSON)
 *  2. Incoming request headers prefixed with X-Relay-*
 *     e.g. X-Relay-Authorization: Bearer ... → Authorization: ...
 *
 * Strips hop-by-hop and sensitive proxy headers before forwarding.
 */
function build_relay_headers(array $explicit = []): array
{
  $blocked = [
    "host",
    "connection",
    "transfer-encoding",
    "te",
    "trailer",
    "upgrade",
    "proxy-authorization",
    "proxy-authenticate",
    "x-forwarded-for",
    "x-forwarded-host",
    "x-forwarded-proto",
    "x-real-ip",
    "x-token", // our own auth header
  ];

  $headers = [];

  // Source 1 — X-Relay-* passthrough from incoming request
  foreach ($_SERVER as $key => $value) {
    if (!str_starts_with($key, "HTTP_X_RELAY_")) {
      continue;
    }
    // HTTP_X_RELAY_AUTHORIZATION → Authorization
    $name = str_replace("_", "-", substr($key, 13)); // strip HTTP_X_RELAY_
    $name = ucwords(strtolower($name), "-");
    if (!in_array(strtolower($name), $blocked, true)) {
      $headers[$name] = $value;
    }
  }

  // Source 2 — explicit ?headers= JSON (takes priority)
  foreach ($explicit as $name => $value) {
    $nameLower = strtolower($name);
    if (!in_array($nameLower, $blocked, true)) {
      $headers[$name] = $value;
    }
  }

  // Format as curl header array
  return array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), $headers);
}

/**
 * Detect whether the current request should be treated as an API relay
 * rather than a file download.
 *
 * Triggers when ANY of:
 *  - ?mode=api is present
 *  - Accept header contains application/json, application/xml, text/plain, etc.
 *    (but NOT the browser's default wildcard *\/*)
 *  - Request method is POST, PUT, PATCH, or DELETE
 */
function is_api_request(): bool
{
  if (isset($_GET["mode"]) && $_GET["mode"] === "api") {
    return true;
  }

  $method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
  if (in_array($method, ["POST", "PUT", "PATCH", "DELETE"], true)) {
    return true;
  }

  $accept = $_SERVER["HTTP_ACCEPT"] ?? "";
  $apiAcceptTypes = [
    "application/json",
    "application/xml",
    "application/x-www-form-urlencoded",
    "text/plain",
    "text/xml",
  ];
  foreach ($apiAcceptTypes as $type) {
    if (str_contains($accept, $type)) {
      return true;
    }
  }

  return false;
}
