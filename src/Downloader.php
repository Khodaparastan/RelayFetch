<?php
declare(strict_types=1);

/**
 * Build CURLOPT_RESOLVE entries that pin $host to $resolvedIp on every
 * common port plus the explicit port from the URL, closing the DNS-rebinding
 * window that exists when only ports 80 and 443 are pinned.
 */
function build_resolve_pins(string $host, string $resolvedIp, ?int $port = null): array
{
  $ports = [80, 443, 8080];
  if ($port !== null && !in_array($port, $ports, true)) {
    $ports[] = $port;
  }
  return array_map(fn(int $p) => "{$host}:{$p}:{$resolvedIp}", $ports);
}

/**
 * Run a HEAD probe against the URL and return metadata.
 * Calls bail() on failure.
 */
function head_probe(string $url, string $host, string $resolvedIp): array
{
  $urlPort = (int) (parse_url($url, PHP_URL_PORT) ?? 0) ?: null;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => MAX_REDIRECTS,
    CURLOPT_TIMEOUT => HEAD_TIMEOUT,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => USER_AGENT,
    CURLOPT_RESOLVE => build_resolve_pins($host, $resolvedIp, $urlPort),
  ]);
  curl_exec($ch);

  $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: "";
  $contentLength = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
  $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
  $error = curl_error($ch);
  curl_close($ch);

  if (!empty($error)) {
    bail(
      502,
      "Bad Gateway",
      "Could not reach the remote server: " . htmlspecialchars($error),
    );
  }
  if ($httpCode !== 200) {
    bail(502, "Bad Gateway", "Remote server responded with HTTP {$httpCode}.");
  }

  // Enforce optional size cap before streaming begins.
  if (MAX_DOWNLOAD_SIZE > 0 && $contentLength > MAX_DOWNLOAD_SIZE) {
    bail(
      413,
      "Payload Too Large",
      "The remote file exceeds the maximum allowed size.",
    );
  }

  return compact("contentType", "contentLength", "effectiveUrl");
}

/**
 * Attempt to extract a filename from Content-Disposition headers.
 * Pins the connection to the already-resolved IP to prevent TOCTOU/SSRF.
 */
function fetch_filename_from_headers(
  string $effectiveUrl,
  string $host,
  string $resolvedIp,
): string {
  $urlPort = (int) (parse_url($effectiveUrl, PHP_URL_PORT) ?? 0) ?: null;
  $ch = curl_init($effectiveUrl);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => MAX_REDIRECTS,
    CURLOPT_TIMEOUT => HEAD_TIMEOUT,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => USER_AGENT,
    CURLOPT_RESOLVE => build_resolve_pins($host, $resolvedIp, $urlPort),
  ]);
  $rawHeaders = curl_exec($ch);
  curl_close($ch);

  if (
    $rawHeaders &&
    preg_match(
      '/Content-Disposition:.*filename[^;=\n]*=\s*["\']?([^"\';\n]+)/i',
      $rawHeaders,
      $m,
    )
  ) {
    return trim($m[1], " \t\"'");
  }

  return "";
}

/**
 * Stream the remote file to the client.
 */
function stream_file(
  string $effectiveUrl,
  string $host,
  string $resolvedIp,
  string $filename,
  string $mimeType,
  int $contentLength,
): void {
  // Re-validate the effective URL's host after redirects to prevent SSRF via
  // open redirects pointing to internal addresses.
  $effectiveHost = strtolower(parse_url($effectiveUrl, PHP_URL_HOST) ?? "");
  if ($effectiveHost !== $host) {
    // The redirect landed on a different host — re-check it is public.
    $ip = gethostbyname($effectiveHost);
    if (
      $ip === $effectiveHost ||
      filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
      ) === false
    ) {
      bail(400, "Bad Request", "Redirect target is not allowed.");
    }
    $host       = $effectiveHost;
    $resolvedIp = $ip;
  }

  $isRangeRequest = isset($_SERVER["HTTP_RANGE"]);
  $rangeHeader = $isRangeRequest ? $_SERVER["HTTP_RANGE"] : "";

  // Flush output buffers
  while (ob_get_level()) {
    ob_end_clean();
  }

  // Response headers
  header("Content-Type: " . $mimeType);
  $encodedFilename = rawurlencode($filename);
  header(
    'Content-Disposition: attachment; filename="' .
      addslashes($filename) .
      '"; filename*=UTF-8\'\'' .
      $encodedFilename,
  );
  header("Content-Transfer-Encoding: binary");
  header("Cache-Control: no-store, no-cache, must-revalidate");
  header("Pragma: no-cache");
  header("Expires: 0");
  header("X-Content-Type-Options: nosniff");
  header("Accept-Ranges: bytes");

  if ($contentLength > 0 && !$isRangeRequest) {
    header("Content-Length: " . $contentLength);
  }

  $out = fopen("php://output", "wb");
  $dlCh = curl_init($effectiveUrl);

  $opts = [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => MAX_REDIRECTS,
    CURLOPT_TIMEOUT => DL_TIMEOUT,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => USER_AGENT,
    CURLOPT_HEADER => false,
    CURLOPT_BUFFERSIZE => CHUNK_SIZE,
    CURLOPT_ENCODING => "",
    CURLOPT_RESOLVE => build_resolve_pins(
      $host,
      $resolvedIp,
      (int) (parse_url($effectiveUrl, PHP_URL_PORT) ?? 0) ?: null,
    ),
    CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use ($out): int {
      $written = fwrite($out, $chunk);
      flush();
      return $written === false ? 0 : strlen($chunk);
    },
    CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (
      $isRangeRequest,
    ): int {
      if ($isRangeRequest) {
        if (preg_match("/^(Content-Range|Content-Length):/i", $header)) {
          header(trim($header));
        }
        if (preg_match("/^HTTP\/[\d.]+ 206/i", $header)) {
          http_response_code(206);
        }
      }
      return strlen($header);
    },
  ];

  if ($isRangeRequest) {
    // Validate Range header format before forwarding
    if (preg_match('/^bytes=(\d*-\d*(?:,\s*\d*-\d*)*)$/', $rangeHeader)) {
      $opts[CURLOPT_RANGE] = str_ireplace("bytes=", "", $rangeHeader);
    } else {
      // Invalid range — ignore and serve full content
      $isRangeRequest = false;
    }
  }

  curl_setopt_array($dlCh, $opts);

  $ok = curl_exec($dlCh);
  $error = curl_error($dlCh);
  curl_close($dlCh);
  fclose($out);

  if (!$ok || !empty($error)) {
    error_log("[Downloader] Stream error for {$effectiveUrl} — {$error}");
  }
}
