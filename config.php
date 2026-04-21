<?php
declare(strict_types=1);

define("CHUNK_SIZE", 2 * 1024 * 1024); // 2 MiB — large buffer for maximum throughput
define("HEAD_TIMEOUT", 15);
define("DL_TIMEOUT", 0); // 0 = no hard wall-clock cap on downloads
define("DL_LOW_SPEED_LIMIT", 0); // 0 = disabled — no minimum speed enforcement
define("DL_LOW_SPEED_TIME", 0); // 0 = disabled — no stall detection timeout
define("MAX_REDIRECTS", 5);
define("USER_AGENT", "Mozilla/5.0 (compatible; StreamProxy/2.0)");
define("ALLOWED_HOSTS", []);
define("BLOCKED_EXTENSIONS", [
  // PHP family
  "php",
  "phtml",
  "phar",
  "php3",
  "php4",
  "php5",
  "php7",
  "phps",
  // Server-side scripts
  "asp",
  "aspx",
  "jsp",
  "jspx",
  "cgi",
  // Shell / scripting
  "sh",
  "bash",
  "zsh",
  "fish",
  "py",
  "rb",
  "pl",
  "lua",
  // Windows executables / scripts
  "exe",
  "bat",
  "cmd",
  "ps1",
  "vbs",
  "wsf",
  "hta",
  // Web-server config (can enable PHP execution, etc.)
  "htaccess",
  "htpasswd",
]);

// Maximum bytes to proxy (0 = unlimited). Example: 2 * 1024 * 1024 * 1024 = 2 GiB.
define("MAX_DOWNLOAD_SIZE", 0);

// Uncomment to require a secret token.
// Accepted via X-Token request header (preferred) or ?token= query parameter.
// define('SECRET_TOKEN', 'change_me');

// SSL verification. true = enforce peer verification (recommended for production).
// Set to false only in trusted private environments with self-signed certificates.
define("SSL_VERIFY_PEER", true);

// Optional path to a custom CA bundle (PEM file) for self-signed or private CAs.
// Example: define('SSL_CAINFO', '/etc/ssl/certs/my-ca.pem');
// define('SSL_CAINFO', '');
// ── Admin ─────────────────────────────────────────────────────────────────────
define("ADMIN_PASSWORD", ""); // empty = admin UI disabled — set a strong password before use
define("ADMIN_SESSION_TTL", 3600); // seconds before re-login required
define("LOG_MAX_LINES", 1000); // rolling window for requests.log
define("LOG_ENABLED", true);

// ── Cache ─────────────────────────────────────────────────────────────────────
define("CACHE_ENABLED", false);
define("CACHE_DIR", __DIR__ . "/data/cache");
define("CACHE_DEFAULT_TTL", 300); // seconds

// ── Data dir ──────────────────────────────────────────────────────────────────
define("DATA_DIR", __DIR__ . "/data");

// ── Proxy / network ───────────────────────────────────────────────────────────
// Set to true only when RelayFetch runs behind a trusted reverse proxy that
// sets X-Forwarded-For. When false (default), REMOTE_ADDR is always used.
define("TRUST_PROXY", false);
