<?php
declare(strict_types=1);

function admin_session_start(): void
{
  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
      "lifetime" => 0,
      "path" => "/",
      "secure" => (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "" && $_SERVER["HTTPS"] !== "off") || (($_SERVER["SERVER_PORT"] ?? 80) == 443),
      "httponly" => true,
      "samesite" => "Strict",
    ]);
    session_start();
  }
}

function admin_is_authenticated(): bool
{
  admin_session_start();
  $authed = $_SESSION["admin_authed"] ?? false;
  $ts = $_SESSION["admin_ts"] ?? 0;

  if (!$authed) {
    return false;
  }
  if (time() - $ts > ADMIN_SESSION_TTL) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
      $p = session_get_cookie_params();
      setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
    return false;
  }
  return true;
}

function admin_login(string $password): bool
{
  if (!defined("ADMIN_PASSWORD") || ADMIN_PASSWORD === "") {
    return false;
  }
  if (!hash_equals(ADMIN_PASSWORD, $password)) {
    return false;
  }
  admin_session_start();
  session_regenerate_id(true);
  $_SESSION["admin_authed"] = true;
  $_SESSION["admin_ts"] = time();
  return true;
}

function admin_logout(): void
{
  admin_session_start();
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
  }
  session_destroy();
}

/**
 * Gate — call at the top of every admin handler.
 * Renders login form or exits if admin is disabled.
 */
function admin_gate(): void
{
  if (!defined("ADMIN_PASSWORD") || ADMIN_PASSWORD === "") {
    bail(
      403,
      "Forbidden",
      "Admin UI is not enabled. Set ADMIN_PASSWORD in config.php.",
    );
  }

  // Handle logout
  // Logout requires a POST with CSRF token to prevent CSRF via GET.
  if (isset($_POST["action"]) && $_POST["action"] === "logout") {
    csrf_token_verify();
    admin_logout();
    header("Location: ?mode=admin");
    exit();
  }

  // Handle login POST
  if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["admin_password"])
  ) {
    if (admin_login($_POST["admin_password"])) {
      header("Location: ?mode=admin");
      exit();
    }
    admin_show_login("Invalid password.");
    exit();
  }

  if (!admin_is_authenticated()) {
    admin_show_login();
    exit();
  }
}

function csrf_token_generate(): string
{
  admin_session_start();
  if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
  }
  return $_SESSION["csrf_token"];
}

function csrf_token_verify(): void
{
  admin_session_start();
  $provided = $_POST["csrf_token"] ?? "";
  $expected = $_SESSION["csrf_token"] ?? "";
  if ($expected === "" || !hash_equals($expected, $provided)) {
    http_response_code(403);
    exit("Invalid or missing CSRF token.");
  }
}

function admin_show_login(string $error = ""): void
{
  header("X-Frame-Options: DENY");
  header("X-Content-Type-Options: nosniff");
  header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'");
  header("Referrer-Policy: same-origin");
  $errHtml = $error
    ? '<p class="admin-login-error">⚠ ' . htmlspecialchars($error) . "</p>"
    : "";

  echo <<<HTML
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>RelayFetch Admin</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body class="center">
    <div class="card admin-login-card">
      <h1 style="font-family:var(--font-mono);color:var(--accent);margin-bottom:1rem">
        <span style="color:var(--green)">$ </span>Admin Login
      </h1>
      {$errHtml}
      <form method="post" action="?mode=admin">
        <div class="field">
          <label for="admin_password">Password</label>
          <div class="input-wrap">
            <span class="input-prefix">🔒</span>
            <input id="admin_password" name="admin_password" type="password"
                   autofocus autocomplete="current-password" required
                   placeholder="admin password">
          </div>
        </div>
        <button class="btn full" type="submit" style="margin-top:.75rem">Unlock</button>
      </form>
    </div>
  </body>
  </html>
  HTML;
}
