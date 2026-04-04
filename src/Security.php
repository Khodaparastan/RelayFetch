<?php
declare(strict_types=1);

/**
 * Validate the URL and perform SSRF checks.
 * Returns ['host' => string, 'resolvedIp' => string] on success.
 * Calls bail() and exits on failure.
 */
function validate_url(string $url): array
{
  if (
    !filter_var($url, FILTER_VALIDATE_URL) ||
    !preg_match("/^https?:\/\//i", $url)
  ) {
    bail(400, "Bad Request", "Only absolute HTTP/HTTPS URLs are accepted.");
  }

  $parsed = parse_url($url);
  $host = strtolower($parsed["host"] ?? "");

  // Block loopback hostnames
  $ssrfBlockedHosts = ["localhost", "ip6-localhost", "ip6-loopback"];
  if (in_array($host, $ssrfBlockedHosts, true)) {
    bail(400, "Bad Request", "Requests to that host are not allowed.");
  }

  // Resolve ALL addresses for the host and block if any is private/reserved.
  // Using dns_get_record covers multiple A/AAAA records and closes the
  // single-record gap left by gethostbyname().
  $dnsRecords = @dns_get_record($host, DNS_A | DNS_AAAA);
  if (empty($dnsRecords)) {
    // Fall back to gethostbyname for hosts not in DNS (e.g. /etc/hosts).
    $fallback = gethostbyname($host);
    if ($fallback === $host) {
      bail(400, "Bad Request", "Could not resolve the hostname.");
    }
    $dnsRecords = [["type" => "A", "ip" => $fallback]];
  }

  $resolvedIp = null;
  foreach ($dnsRecords as $rec) {
    $ip = $rec["ip"] ?? $rec["ipv6"] ?? null;
    if ($ip === null) {
      continue;
    }
    if (
      filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
      ) === false
    ) {
      bail(
        400,
        "Bad Request",
        "Requests to private or reserved IP addresses are not allowed.",
      );
    }
    // Use the first public IP for connection pinning.
    if ($resolvedIp === null) {
      $resolvedIp = $ip;
    }
  }

  if ($resolvedIp === null) {
    bail(400, "Bad Request", "Could not resolve the hostname to a public IP.");
  }

  // Optional host whitelist
  if (!empty(ALLOWED_HOSTS) && !in_array($host, ALLOWED_HOSTS, true)) {
    bail(403, "Forbidden", "This host is not on the allowed list.");
  }

  // Block dangerous extensions
  $urlPath = $parsed["path"] ?? "";
  $urlExt = strtolower(ltrim(pathinfo($urlPath, PATHINFO_EXTENSION), "."));
  if (in_array($urlExt, BLOCKED_EXTENSIONS, true)) {
    bail(400, "Bad Request", "This file type is not allowed.");
  }

  return ["host" => $host, "resolvedIp" => $resolvedIp, "parsed" => $parsed];
}

/**
 * Verify the secret token if SECRET_TOKEN is defined.
 * Accepts the token via X-Token request header or ?token= query parameter.
 */
function verify_token(): void
{
  if (!defined("SECRET_TOKEN")) {
    return;
  }

  // Prefer the X-Token header; fall back to the query-string parameter.
  $headerToken = $_SERVER["HTTP_X_TOKEN"] ?? "";
  $queryToken  = $_GET["token"] ?? "";
  $provided    = $headerToken !== "" ? $headerToken : $queryToken;

  if ($provided === "" || !hash_equals(SECRET_TOKEN, $provided)) {
    bail(403, "Forbidden", "Invalid or missing access token.");
  }
}
