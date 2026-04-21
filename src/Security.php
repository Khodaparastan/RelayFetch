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

  // Block loopback hostnames and known IPv6 loopback/link-local literals.
  $ssrfBlockedHosts = ["localhost", "ip6-localhost", "ip6-loopback", "::1", "0:0:0:0:0:0:0:1", "::ffff:127.0.0.1", "fe80::1"];
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
 * Re-validate the host after redirects and return updated security context.
 * If $effectiveUrl has a different host than $originalHost, the new host is
 * fully SSRF-checked (all DNS records, private-range filter, optional
 * whitelist, blocked extensions). Calls bail() on any violation.
 *
 * Returns ['host' => string, 'resolvedIp' => string] — either the original
 * values (no redirect) or the newly validated ones.
 */
function revalidate_redirect(
  string $effectiveUrl,
  string $originalHost,
  string $originalIp,
): array {
  $effectiveHost = strtolower(parse_url($effectiveUrl, PHP_URL_HOST) ?? "");

  if ($effectiveHost === "" || $effectiveHost === $originalHost) {
    return ["host" => $originalHost, "resolvedIp" => $originalIp];
  }

  // Block loopback hostnames and known IPv6 loopback/link-local literals.
  $ssrfBlockedHosts = ["localhost", "ip6-localhost", "ip6-loopback", "::1", "0:0:0:0:0:0:0:1", "::ffff:127.0.0.1", "fe80::1"];
  if (in_array($effectiveHost, $ssrfBlockedHosts, true)) {
    bail(400, "Bad Request", "Redirect target host is not allowed.");
  }

  // Resolve ALL addresses for the redirect host and block if any is private.
  $dnsRecords = @dns_get_record($effectiveHost, DNS_A | DNS_AAAA);
  if (empty($dnsRecords)) {
    $fallback = gethostbyname($effectiveHost);
    if ($fallback === $effectiveHost) {
      bail(400, "Bad Request", "Could not resolve the redirect target hostname.");
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
        "Redirect target resolves to a private or reserved IP address.",
      );
    }
    if ($resolvedIp === null) {
      $resolvedIp = $ip;
    }
  }

  if ($resolvedIp === null) {
    bail(400, "Bad Request", "Could not resolve the redirect target to a public IP.");
  }

  // Optional host whitelist applies to redirect targets too.
  if (!empty(ALLOWED_HOSTS) && !in_array($effectiveHost, ALLOWED_HOSTS, true)) {
    bail(403, "Forbidden", "Redirect target host is not on the allowed list.");
  }

  // Block dangerous extensions on the final URL path.
  $urlPath = parse_url($effectiveUrl, PHP_URL_PATH) ?? "";
  $urlExt  = strtolower(ltrim(pathinfo($urlPath, PATHINFO_EXTENSION), "."));
  if (in_array($urlExt, BLOCKED_EXTENSIONS, true)) {
    bail(400, "Bad Request", "Redirect target file type is not allowed.");
  }

  return ["host" => $effectiveHost, "resolvedIp" => $resolvedIp, "parsed" => parse_url($effectiveUrl)];
}

/**
 * Verify the secret token if SECRET_TOKEN is defined.
 * Accepts the token via X-Token request header or ?token= query parameter.
 * Checks the global SECRET_TOKEN first, then the per-token store.
 */
function verify_token(): void
{
  // If no token is configured (or it's empty), auth is disabled.
  if (!defined("SECRET_TOKEN") || SECRET_TOKEN === "") {
    return;
  }

  // Prefer the X-Token header; fall back to the query-string parameter.
  $headerToken = $_SERVER["HTTP_X_TOKEN"] ?? "";
  $queryToken  = $_GET["token"] ?? "";
  $provided    = $headerToken !== "" ? $headerToken : $queryToken;

  if ($provided === "") {
    bail(403, "Forbidden", "Invalid or missing access token.");
  }

  // Accept the global SECRET_TOKEN.
  if (hash_equals(SECRET_TOKEN, $provided)) {
    return;
  }

  // Also accept any token from the per-token store.
  if (function_exists("tokens_verify") && tokens_verify($provided) !== null) {
    return;
  }

  bail(403, "Forbidden", "Invalid or missing access token.");
}
