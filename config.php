<?php
declare(strict_types=1);

define("CHUNK_SIZE", 256 * 1024);
define("HEAD_TIMEOUT", 15);
define("DL_TIMEOUT", 3600);
define("MAX_REDIRECTS", 5);
define("USER_AGENT", "Mozilla/5.0 (compatible; StreamProxy/2.0)");
define("ALLOWED_HOSTS", []);
define("BLOCKED_EXTENSIONS", [
  // PHP family
  "php", "phtml", "phar", "php3", "php4", "php5", "php7", "phps",
  // Server-side scripts
  "asp", "aspx", "jsp", "jspx", "cgi",
  // Shell / scripting
  "sh", "bash", "zsh", "fish", "py", "rb", "pl", "lua",
  // Windows executables / scripts
  "exe", "bat", "cmd", "ps1", "vbs", "wsf", "hta",
  // Web-server config (can enable PHP execution, etc.)
  "htaccess", "htpasswd",
]);

// Maximum bytes to proxy (0 = unlimited). Example: 2 * 1024 * 1024 * 1024 = 2 GiB.
define("MAX_DOWNLOAD_SIZE", 0);

// Uncomment to require a secret token.
// Accepted via X-Token request header (preferred) or ?token= query parameter.
// define('SECRET_TOKEN', 'change_me');
