<?php
declare(strict_types=1);
/**
 * Return all configurable settings as a flat array of current values.
 * Reads from defined constants so it always reflects the live config.
 */
function settings_current(): array
{
  return [
    "CHUNK_SIZE" => defined("CHUNK_SIZE") ? CHUNK_SIZE : 2097152,
    "HEAD_TIMEOUT" => defined("HEAD_TIMEOUT") ? HEAD_TIMEOUT : 15,
    "DL_TIMEOUT" => defined("DL_TIMEOUT") ? DL_TIMEOUT : 0,
    "DL_LOW_SPEED_LIMIT" => defined("DL_LOW_SPEED_LIMIT")
      ? DL_LOW_SPEED_LIMIT
      : 0,
    "DL_LOW_SPEED_TIME" => defined("DL_LOW_SPEED_TIME") ? DL_LOW_SPEED_TIME : 0,
    "MAX_REDIRECTS" => defined("MAX_REDIRECTS") ? MAX_REDIRECTS : 5,
    "MAX_DOWNLOAD_SIZE" => defined("MAX_DOWNLOAD_SIZE") ? MAX_DOWNLOAD_SIZE : 0,
    "SSL_VERIFY_PEER" => defined("SSL_VERIFY_PEER") ? SSL_VERIFY_PEER : true,
    "SSL_CAINFO" => defined("SSL_CAINFO") ? SSL_CAINFO : "",
    "ALLOWED_HOSTS" => defined("ALLOWED_HOSTS") ? ALLOWED_HOSTS : [],
    "BLOCKED_EXTENSIONS" => defined("BLOCKED_EXTENSIONS")
      ? BLOCKED_EXTENSIONS
      : [],
    "SECRET_TOKEN" => defined("SECRET_TOKEN") ? SECRET_TOKEN : "",
    "ADMIN_PASSWORD" => defined("ADMIN_PASSWORD") ? ADMIN_PASSWORD : "",
    "LOG_ENABLED" => defined("LOG_ENABLED") ? LOG_ENABLED : true,
    "LOG_MAX_LINES" => defined("LOG_MAX_LINES") ? LOG_MAX_LINES : 1000,
    "CACHE_ENABLED" => defined("CACHE_ENABLED") ? CACHE_ENABLED : false,
    "CACHE_DEFAULT_TTL" => defined("CACHE_DEFAULT_TTL")
      ? CACHE_DEFAULT_TTL
      : 300,
    "USER_AGENT" => defined("USER_AGENT") ? USER_AGENT : "",
  ];
}
/**
 * Validate POST data and rewrite config.php.
 * Returns [errors[], success bool].
 */
function settings_save(array $post): array
{
  $errors = [];
  $current = settings_current();

  // ── Integers — must be >= 0 ───────────────────────────────────────────────
  $intFields = [
    "CHUNK_SIZE",
    "HEAD_TIMEOUT",
    "DL_TIMEOUT",
    "DL_LOW_SPEED_LIMIT",
    "DL_LOW_SPEED_TIME",
    "MAX_REDIRECTS",
    "MAX_DOWNLOAD_SIZE",
    "LOG_MAX_LINES",
    "CACHE_DEFAULT_TTL",
  ];
  $ints = [];
  foreach ($intFields as $key) {
    $raw = trim($post[$key] ?? "");
    if (!ctype_digit($raw)) {
      $errors[] = "{$key} must be a non-negative integer.";
      continue;
    }
    $val = (int) $raw;
    if ($val < 0) {
      $errors[] = "{$key} must be >= 0.";
      continue;
    }
    $ints[$key] = $val;
  }

  // ── Booleans ──────────────────────────────────────────────────────────────
  $bools = [
    "SSL_VERIFY_PEER" =>
      !empty($post["SSL_VERIFY_PEER"]) && $post["SSL_VERIFY_PEER"] !== "0",
    "LOG_ENABLED" =>
      !empty($post["LOG_ENABLED"]) && $post["LOG_ENABLED"] !== "0",
    "CACHE_ENABLED" =>
      !empty($post["CACHE_ENABLED"]) && $post["CACHE_ENABLED"] !== "0",
  ];

  // ── Strings ───────────────────────────────────────────────────────────────
  $userAgent = trim($post["USER_AGENT"] ?? "");
  if (empty($userAgent)) {
    $errors[] = "USER_AGENT cannot be empty.";
  }

  $sslCainfo = trim($post["SSL_CAINFO"] ?? "");
  if ($sslCainfo !== "" && !file_exists($sslCainfo)) {
    $errors[] = "SSL_CAINFO path does not exist: {$sslCainfo}";
  }

  // ── Arrays ────────────────────────────────────────────────────────────────
  $allowedHosts = array_values(
    array_filter(array_map("trim", explode(",", $post["ALLOWED_HOSTS"] ?? ""))),
  );
  foreach ($allowedHosts as $h) {
    if (!preg_match('/^[\w.\-]+$/', $h)) {
      $errors[] = "Invalid host in ALLOWED_HOSTS: {$h}";
    }
  }

  $blockedExts = array_values(
    array_filter(
      array_map(
        fn($e) => strtolower(trim($e, " \t.")),
        explode(",", $post["BLOCKED_EXTENSIONS"] ?? ""),
      ),
    ),
  );
  foreach ($blockedExts as $ext) {
    if (!preg_match('/^[a-z0-9]+$/', $ext)) {
      $errors[] = "Invalid extension in BLOCKED_EXTENSIONS: {$ext}";
    }
  }

  // ── Secrets — blank means keep existing ───────────────────────────────────
  $secretToken = trim($post["SECRET_TOKEN"] ?? "");
  $adminPassword = trim($post["ADMIN_PASSWORD"] ?? "");

  $finalSecretToken =
    $secretToken !== "" ? $secretToken : $current["SECRET_TOKEN"];
  $finalAdminPassword =
    $adminPassword !== "" ? $adminPassword : $current["ADMIN_PASSWORD"];

  // ── Bail on validation errors ─────────────────────────────────────────────
  if (!empty($errors)) {
    return [$errors, false];
  }

  // ── Build and write config.php ────────────────────────────────────────────
  $configPath = __DIR__ . "/../config.php";
  $content = settings_build_config(
    array_merge($ints, $bools, [
      "USER_AGENT" => $userAgent,
      "SSL_CAINFO" => $sslCainfo,
      "ALLOWED_HOSTS" => $allowedHosts,
      "BLOCKED_EXTENSIONS" => $blockedExts,
      "SECRET_TOKEN" => $finalSecretToken,
      "ADMIN_PASSWORD" => $finalAdminPassword,
    ]),
  );

  $written = file_put_contents($configPath, $content, LOCK_EX);
  if ($written === false) {
    return [["Could not write config.php — check file permissions."], false];
  }

  return [[], true];
}
function settings_field(
  string $name,
  string $label,
  string $type,
  mixed $value,
  string $hint = "",
): string {
  $id = "f_" . $name;
  $safeVal = htmlspecialchars((string) $value);
  $safeHint = $hint
    ? '<span class="field-hint">' . htmlspecialchars($hint) . "</span>"
    : "";
  $min = $type === "number" ? ' min="0"' : "";

  return <<<HTML
  <div class="field">
    <label for="{$id}">{$label}</label>
    <div class="input-wrap">
      <input id="{$id}" name="{$name}" type="{$type}"{$min}
             value="{$safeVal}" autocomplete="off" spellcheck="false">
    </div>
    {$safeHint}
  </div>
  HTML;
}

function settings_toggle(
  string $name,
  string $label,
  bool $checked,
  string $hint = "",
): string {
  $chk = $checked ? " checked" : "";
  $safeHint = $hint
    ? '<span class="field-hint">' . htmlspecialchars($hint) . "</span>"
    : "";

  return <<<HTML
  <div class="field field--toggle">
    <label class="toggle-label">
      <input type="hidden"   name="{$name}" value="0">
      <input type="checkbox" name="{$name}" value="1" class="toggle-input"{$chk}>
      <span class="toggle-track"><span class="toggle-thumb"></span></span>
      <span class="toggle-text">{$label}</span>
    </label>
    {$safeHint}
  </div>
  HTML;
}

/**
 * Generate the full config.php content from a flat settings array.
 * Uses var_export() for arrays and proper PHP literal formatting.
 */
function settings_build_config(array $s): string
{
  $chunk = (int) $s["CHUNK_SIZE"];
  $headTimeout = (int) $s["HEAD_TIMEOUT"];
  $dlTimeout = (int) $s["DL_TIMEOUT"];
  $lowSpeedLim = (int) $s["DL_LOW_SPEED_LIMIT"];
  $lowSpeedTime = (int) $s["DL_LOW_SPEED_TIME"];
  $maxRedirs = (int) $s["MAX_REDIRECTS"];
  $maxDlSize = (int) $s["MAX_DOWNLOAD_SIZE"];
  $sslVerify = $s["SSL_VERIFY_PEER"] ? "true" : "false";
  $logEnabled = $s["LOG_ENABLED"] ? "true" : "false";
  $cacheEnabled = $s["CACHE_ENABLED"] ? "true" : "false";
  $logMax = (int) $s["LOG_MAX_LINES"];
  $cacheTtl = (int) $s["CACHE_DEFAULT_TTL"];
  $userAgent = var_export($s["USER_AGENT"], true);
  $sslCainfo = var_export($s["SSL_CAINFO"], true);

  // ← resolve before heredoc
  $savedAt = date("Y-m-d H:i:s", (int) ($_SERVER["REQUEST_TIME"] ?? time()));

  $allowedHosts = settings_format_array($s["ALLOWED_HOSTS"]);
  $blockedExts = settings_format_array($s["BLOCKED_EXTENSIONS"]);

  $secretTokenLine =
    $s["SECRET_TOKEN"] !== ""
      ? "define('SECRET_TOKEN', " . var_export($s["SECRET_TOKEN"], true) . ");"
      : "// define('SECRET_TOKEN', 'change_me');";

  $adminPasswordLine =
    $s["ADMIN_PASSWORD"] !== ""
      ? "define('ADMIN_PASSWORD', " . var_export($s["ADMIN_PASSWORD"], true) . ");"
      : "define('ADMIN_PASSWORD', '');";

  $sslCainfoLine =
    $s["SSL_CAINFO"] !== ""
      ? "define('SSL_CAINFO', " . $sslCainfo . ");"
      : "// define('SSL_CAINFO', '');";

  return <<<PHP
  <?php
  declare(strict_types=1);

  // ── Generated by RelayFetch Admin UI ─────────────────────────────────────
  // Last saved: {$savedAt}

  define('CHUNK_SIZE',          {$chunk});
  define('HEAD_TIMEOUT',        {$headTimeout});
  define('DL_TIMEOUT',          {$dlTimeout});
  define('DL_LOW_SPEED_LIMIT',  {$lowSpeedLim});
  define('DL_LOW_SPEED_TIME',   {$lowSpeedTime});
  define('MAX_REDIRECTS',       {$maxRedirs});
  define('USER_AGENT',          {$userAgent});
  define('MAX_DOWNLOAD_SIZE',   {$maxDlSize});

  define('ALLOWED_HOSTS',       {$allowedHosts});
  define('BLOCKED_EXTENSIONS',  {$blockedExts});

  define('SSL_VERIFY_PEER',     {$sslVerify});
  {$sslCainfoLine}

  define('LOG_ENABLED',         {$logEnabled});
  define('LOG_MAX_LINES',       {$logMax});

  define('CACHE_ENABLED',       {$cacheEnabled});
  define('CACHE_DEFAULT_TTL',   {$cacheTtl});
  define('CACHE_DIR',           __DIR__ . '/data/cache');
  define('DATA_DIR',            __DIR__ . '/data');

  define('ADMIN_SESSION_TTL',   3600);
  {$adminPasswordLine}
  {$secretTokenLine}

  PHP;
}
/**
 * Format a flat string array as a compact PHP array literal.
 * e.g. ['php', 'exe', 'sh']
 */
function settings_format_array(array $items): string
{
  if (empty($items)) {
    return "[]";
  }
  $escaped = array_map(fn($i) => var_export($i, true), $items);
  return "[" . implode(", ", $escaped) . "]";
}

function show_admin(): void
{
  header("X-Frame-Options: DENY");
  header("X-Content-Type-Options: nosniff");
  header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'");
  header("Referrer-Policy: same-origin");
  admin_gate();

  // ── POST actions ─────────────────────────────────────────────────────────
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_token_verify();
    $action = $_POST["action"] ?? "";

    switch ($action) {
      case "flush_cache":
        $n = cache_flush();
        admin_redirect("cache", "{$n} cache entries flushed.");
        break;

      case "flush_expired":
        $n = cache_flush_expired();
        admin_redirect("cache", "{$n} expired entries removed.");
        break;

      case "delete_cache_entry":
        $hash = preg_replace("/[^a-f0-9]/", "", $_POST["hash"] ?? "");
        if ($hash) {
          cache_delete($hash);
        }
        admin_redirect("cache", "Entry deleted.");
        break;

      case "clear_log":
        clear_log();
        admin_redirect("requests", "Request log cleared.");
        break;

      case "reset_metrics":
        reset_metrics();
        admin_redirect("dashboard", "Metrics reset.");
        break;

      case "add_token":
        tokens_add($_POST);
        admin_redirect("tokens", "Token added.");
        break;

      case "revoke_token":
        tokens_revoke($_POST["token_id"] ?? "");
        admin_redirect("tokens", "Token revoked.");
        break;
    }
  }

  $tab = preg_replace("/[^a-z]/", "", $_GET["tab"] ?? "dashboard");
  $flash = $_SESSION["admin_flash"] ?? "";
  unset($_SESSION["admin_flash"]);
  $metrics = read_metrics();

  $tabs = [
    "dashboard" => "⬡ Dashboard",
    "requests" => "⇅ Requests",
    "cache" => "◫ Cache",
    "tokens" => "⚿ Tokens",
    "settings" => "⚙ Settings",
  ];

  $navHtml = "";
  foreach ($tabs as $key => $label) {
    $active = $key === $tab ? " active" : "";
    $navHtml .= "<a href=\"?mode=admin&tab={$key}\" class=\"admin-tab{$active}\">{$label}</a>\n";
  }

  $flashHtml = $flash
    ? '<div class="admin-flash">' . htmlspecialchars($flash) . "</div>"
    : "";

  $content = match ($tab) {
    "requests" => admin_tab_requests(),
    "cache" => admin_tab_cache(),
    "tokens" => admin_tab_tokens(),
    "settings" => admin_tab_settings(),
    default => admin_tab_dashboard($metrics),
  };

  echo <<<HTML
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>RelayFetch Admin</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin.css">
  </head>
  <body>
  <div class="admin-layout">

    <aside class="admin-sidebar">
      <div class="admin-brand">
        <span style="color:var(--green)">$ </span><span style="color:var(--accent)">RelayFetch</span>
        <span class="admin-badge">admin</span>
      </div>
      <nav class="admin-nav">
        {$navHtml}
      </nav>
      <div class="admin-sidebar-footer">
        <a href="?" class="admin-tab">← Back to App</a>
        <form method="post" action="?mode=admin" style="display:inline">
          <input type="hidden" name="action" value="logout">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token_generate()) ?>">
          <button type="submit" class="admin-tab admin-logout" style="background:none;border:none;cursor:pointer;padding:0">Logout</button>
        </form>
      </div>
    </aside>

    <main class="admin-main">
      {$flashHtml}
      {$content}
    </main>

  </div>
  <script src="admin.js"></script>
  </body>
  </html>
  HTML;
}

// ── Tab: Dashboard ────────────────────────────────────────────────────────────
function admin_tab_dashboard(array $m): string
{
  $total = (int) ($m["requests_total"] ?? 0);
  $downloads = (int) ($m["requests_download"] ?? 0);
  $api = (int) ($m["requests_api"] ?? 0);
  $errors = (int) ($m["requests_error"] ?? 0);
  $bytes = format_bytes((int) ($m["bytes_proxied"] ?? 0));
  $cacheHits = (int) ($m["cache_hits"] ?? 0);
  $cacheMiss = (int) ($m["cache_misses"] ?? 0);
  $hitRate =
    $cacheHits + $cacheMiss > 0
      ? round(($cacheHits / ($cacheHits + $cacheMiss)) * 100, 1)
      : 0;
  $lastReset = $m["last_reset"]
    ? date("Y-m-d H:i:s", $m["last_reset"])
    : "never";

  // Last 50 requests for sparkline data
  $recent = read_log(50);
  $sparkData = json_encode(
    array_map(fn($r) => $r["status"] ?? 0, array_reverse($recent)),
  );

  // Error rate
  $errorRate = $total > 0 ? round(($errors / $total) * 100, 1) : 0;

  // ← resolve function call BEFORE heredoc
  $recentTableHtml = render_request_table(read_log(10));
  $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token_generate()) . '">';

  return <<<HTML
  <div class="admin-page-header">
    <h2>Dashboard</h2>
    <form method="post" action="?mode=admin&tab=dashboard" style="display:inline">
      {$csrfField}
      <input type="hidden" name="action" value="reset_metrics">
      <button class="btn-sm" onclick="return confirm('Reset all metrics?')">Reset Metrics</button>
    </form>
  </div>

  <div class="admin-metrics-grid">
    <div class="metric-card">
      <div class="metric-label">Total Requests</div>
      <div class="metric-value">{$total}</div>
    </div>
    <div class="metric-card">
      <div class="metric-label">Downloads</div>
      <div class="metric-value">{$downloads}</div>
    </div>
    <div class="metric-card">
      <div class="metric-label">API Relays</div>
      <div class="metric-value">{$api}</div>
    </div>
    <div class="metric-card metric-card--error">
      <div class="metric-label">Errors</div>
      <div class="metric-value">{$errors} <span class="metric-sub">{$errorRate}%</span></div>
    </div>
    <div class="metric-card">
      <div class="metric-label">Bytes Proxied</div>
      <div class="metric-value">{$bytes}</div>
    </div>
    <div class="metric-card">
      <div class="metric-label">Cache Hit Rate</div>
      <div class="metric-value">{$hitRate}%</div>
    </div>
  </div>

  <div class="admin-card" style="margin-top:1.5rem">
    <div class="admin-card-header">Last 50 Requests — Status Sparkline</div>
    <canvas id="sparkline" height="60" data-values='{$sparkData}'></canvas>
  </div>

  <div class="admin-card" style="margin-top:1rem">
    <div class="admin-card-header">Recent Activity</div>
    {$recentTableHtml}
  </div>

  <p class="admin-muted" style="margin-top:1rem">Metrics last reset: {$lastReset}</p>
  HTML;
}
// ── Tab: Requests ─────────────────────────────────────────────────────────────
function admin_tab_requests(): string
{
  $page = max(1, (int) ($_GET["page"] ?? 1));
  $limit = 25;
  $offset = ($page - 1) * $limit;

  $filterMethod = $_GET["method"] ?? "";
  $filterStatus = $_GET["status"] ?? "";
  $filterMode = $_GET["mode_f"] ?? "";

  $filters = array_filter([
    "method" => $filterMethod ?: null,
    "status" => $filterStatus ? (int) $filterStatus : null,
    "mode" => $filterMode ?: null,
  ]);

  $records = read_log($limit, $offset, $filters);
  $total = count_log($filters);
  $pages = (int) ceil($total / $limit);

  $tableHtml = render_request_table($records);

  $methodOpts = admin_select_options(
    ["", "GET", "POST", "PUT", "PATCH", "DELETE"],
    $filterMethod,
  );
  $statusOpts = admin_select_options(
    ["", "200", "302", "400", "403", "413", "500", "502"],
    $filterStatus,
  );
  $modeOpts = admin_select_options(["", "download", "api"], $filterMode);

  $pagerHtml = admin_pager(
    $page,
    $pages,
    "?mode=admin&tab=requests&method={$filterMethod}&status={$filterStatus}&mode_f={$filterMode}",
  );
  $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token_generate()) . '">';

  return <<<HTML
  <div class="admin-page-header">
    <h2>Request Log <span class="admin-badge">{$total}</span></h2>
    <form method="post" action="?mode=admin&tab=requests">
      {$csrfField}
      <input type="hidden" name="action" value="clear_log">
      <button class="btn-sm btn-danger" onclick="return confirm('Clear entire log?')">Clear Log</button>
    </form>
  </div>

  <form method="get" action="" class="admin-filter-bar">
    <input type="hidden" name="mode" value="admin">
    <input type="hidden" name="tab"  value="requests">
    <select name="method">{$methodOpts}</select>
    <select name="status">{$statusOpts}</select>
    <select name="mode_f">{$modeOpts}</select>
    <button class="btn-sm" type="submit">Filter</button>
    <a href="?mode=admin&tab=requests" class="btn-sm">Reset</a>
  </form>

  {$tableHtml}
  {$pagerHtml}
  HTML;
}

// ── Tab: Cache ────────────────────────────────────────────────────────────────
function admin_tab_cache(): string
{
  $entries = cache_list();
  $count = count($entries);
  $totalSize = format_bytes(cache_size());

  $rows = "";
  foreach ($entries as $e) {
    $url = htmlspecialchars($e["url"] ?? "");
    $hash = htmlspecialchars($e["hash"] ?? "");
    $size = format_bytes((int) ($e["size"] ?? 0));
    $ttlLeft = $e["expired"]
      ? '<span class="badge-red">expired</span>'
      : $e["ttl_left"] . "s";
    $cached = date("H:i:s", $e["cached_at"] ?? 0);
    $mime = htmlspecialchars($e["mime"] ?? "");

    $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token_generate()) . '">';
    $rows .= <<<HTML
    <tr>
      <td class="td-url" title="{$url}">{$url}</td>
      <td>{$mime}</td>
      <td>{$size}</td>
      <td>{$ttlLeft}</td>
      <td>{$cached}</td>
      <td>
        <form method="post" action="?mode=admin&tab=cache" style="display:inline">
          {$csrfField}
          <input type="hidden" name="action" value="delete_cache_entry">
          <input type="hidden" name="hash"   value="{$hash}">
          <button class="btn-sm btn-danger" type="submit">Delete</button>
        </form>
      </td>
    </tr>
    HTML;
  }

  if (!$rows) {
    $rows =
      '<tr><td colspan="6" class="admin-empty">No cache entries</td></tr>';
  }

  $cacheStatus = (defined("CACHE_ENABLED") && CACHE_ENABLED)
    ? '<span class="badge-green">enabled</span>'
    : '<span class="badge-red">disabled</span>';

  $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token_generate()) . '">';

  return <<<HTML
  <div class="admin-page-header">
    <h2>Cache {$cacheStatus}</h2>
    <div style="display:flex;gap:.5rem">
      <form method="post" action="?mode=admin&tab=cache">
        {$csrfField}
        <input type="hidden" name="action" value="flush_expired">
        <button class="btn-sm">Flush Expired</button>
      </form>
      <form method="post" action="?mode=admin&tab=cache">
        {$csrfField}
        <input type="hidden" name="action" value="flush_cache">
        <button class="btn-sm btn-danger" onclick="return confirm('Flush all cache?')">Flush All</button>
      </form>
    </div>
  </div>

  <div class="admin-metrics-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem">
    <div class="metric-card"><div class="metric-label">Entries</div><div class="metric-value">{$count}</div></div>
    <div class="metric-card"><div class="metric-label">Total Size</div><div class="metric-value">{$totalSize}</div></div>
    <div class="metric-card"><div class="metric-label">Cache Dir</div><div class="metric-value" style="font-size:.75rem">data/cache</div></div>
  </div>

  <div class="admin-card">
    <table class="admin-table">
      <thead>
        <tr>
          <th>URL</th><th>MIME</th><th>Size</th><th>TTL Left</th><th>Cached At</th><th></th>
        </tr>
      </thead>
      <tbody>{$rows}</tbody>
    </table>
  </div>
  HTML;
}

// ── Tab: Tokens ───────────────────────────────────────────────────────────────
function admin_tab_tokens(): string
{
  $tokens = tokens_list();
  $rows = "";

  foreach ($tokens as $t) {
    $id = htmlspecialchars($t["id"] ?? "");
    $label = htmlspecialchars($t["label"] ?? "");
    $token = htmlspecialchars($t["token"] ?? "");
    $hosts = htmlspecialchars(implode(", ", $t["hosts"] ?? ["*"]));
    $methods = htmlspecialchars(implode(", ", $t["methods"] ?? ["*"]));
    $created = date("Y-m-d", $t["created_at"] ?? 0);
    $uses = (int) ($t["uses"] ?? 0);

    $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token_generate()) . '">';
    $rows .= <<<HTML
    <tr>
      <td>{$label}</td>
      <td><code class="admin-token-val">{$token}</code></td>
      <td>{$hosts}</td>
      <td>{$methods}</td>
      <td>{$uses}</td>
      <td>{$created}</td>
      <td>
        <form method="post" action="?mode=admin&tab=tokens" style="display:inline">
          {$csrfField}
          <input type="hidden" name="action"   value="revoke_token">
          <input type="hidden" name="token_id" value="{$id}">
          <button class="btn-sm btn-danger" onclick="return confirm('Revoke token?')">Revoke</button>
        </form>
      </td>
    </tr>
    HTML;
  }

  if (!$rows) {
    $rows =
      '<tr><td colspan="7" class="admin-empty">No tokens defined</td></tr>';
  }

  $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token_generate()) . '">';

  return <<<HTML
  <div class="admin-page-header">
    <h2>Token Management</h2>
  </div>

  <div class="admin-card" style="margin-bottom:1.25rem">
    <div class="admin-card-header">Add Token</div>
    <form method="post" action="?mode=admin&tab=tokens" class="admin-token-form">
      {$csrfField}
      <input type="hidden" name="action" value="add_token">
      <div class="field">
        <label>Label</label>
        <div class="input-wrap">
          <span class="input-prefix">🏷</span>
          <input type="text" name="label" placeholder="my-service" required>
        </div>
      </div>
      <div class="field">
        <label>Token <span class="opt">(leave blank to auto-generate)</span></label>
        <div class="input-wrap">
          <span class="input-prefix">🔑</span>
          <input type="text" name="token" placeholder="auto-generated if empty" autocomplete="off">
        </div>
      </div>
      <div class="field-row" style="grid-template-columns:1fr 1fr auto">
        <div class="field">
          <label>Allowed Hosts <span class="opt">(comma-sep, * = all)</span></label>
          <div class="input-wrap">
            <span class="input-prefix">🌐</span>
            <input type="text" name="hosts" placeholder="* or cdn.example.com">
          </div>
        </div>
        <div class="field">
          <label>Allowed Methods <span class="opt">(comma-sep, * = all)</span></label>
          <div class="input-wrap">
            <span class="input-prefix">⚡</span>
            <input type="text" name="methods" placeholder="* or GET,POST">
          </div>
        </div>
        <button class="btn" type="submit">Add Token</button>
      </div>
    </form>
  </div>

  <div class="admin-card">
    <table class="admin-table">
      <thead>
        <tr><th>Label</th><th>Token</th><th>Hosts</th><th>Methods</th><th>Uses</th><th>Created</th><th></th></tr>
      </thead>
      <tbody>{$rows}</tbody>
    </table>
  </div>
  HTML;
}

// ── Tab: Settings ─────────────────────────────────────────────────────────────
function admin_tab_settings(): string
{
  $errors = [];
  $success = false;

  if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "save_settings"
  ) {
    [$errors, $success] = settings_save($_POST);
    if ($success) {
      admin_redirect("settings", "Settings saved successfully.");
    }
  }

  $v = settings_current();

  $errHtml = "";
  foreach ($errors as $e) {
    $errHtml .=
      '<div class="admin-flash admin-flash--error">' .
      htmlspecialchars($e) .
      "</div>";
  }

  $field = fn(
    string $name,
    string $label,
    string $type,
    string $hint = "",
  ) => settings_field($name, $label, $type, $v[$name] ?? "", $hint);

  $toggle = fn(
    string $name,
    string $label,
    string $hint = "",
  ) => settings_toggle($name, $label, (bool) ($v[$name] ?? false), $hint);

  $blockedVal = htmlspecialchars(implode(", ", $v["BLOCKED_EXTENSIONS"] ?? []));
  $allowedVal = htmlspecialchars(implode(", ", $v["ALLOWED_HOSTS"] ?? []));

  // ── resolve all ternaries and expressions before heredoc ─────────────────
  $tokenSet = !empty($v["SECRET_TOKEN"]);
  $adminPwSet = !empty($v["ADMIN_PASSWORD"]);
  $tokenStatus = $tokenSet ? "currently set" : "not set"; // ← fix
  $adminStatus = $adminPwSet ? "currently set" : "not set"; // ← fix
  $tokenPlaceholder = $tokenSet
    ? "(set — leave blank to keep unchanged)"
    : "leave blank to disable";
  $adminPlaceholder = $adminPwSet
    ? "(set — leave blank to keep unchanged)"
    : "leave blank to disable";

  // ── render field blocks before heredoc (closures can't be called in heredoc)
  $fieldsTimeouts =
    $field(
      "CHUNK_SIZE",
      "Chunk Size (bytes)",
      "number",
      "Streaming buffer size. Default: 2097152 (2 MiB)",
    ) .
    $field(
      "HEAD_TIMEOUT",
      "HEAD Timeout (seconds)",
      "number",
      "Timeout for the initial HEAD probe.",
    ) .
    $field(
      "DL_TIMEOUT",
      "Download Timeout (seconds)",
      "number",
      "0 = no limit.",
    ) .
    $field(
      "DL_LOW_SPEED_LIMIT",
      "Low Speed Limit (bytes/sec)",
      "number",
      "0 = disabled.",
    ) .
    $field(
      "DL_LOW_SPEED_TIME",
      "Low Speed Time (seconds)",
      "number",
      "0 = disabled.",
    ) .
    $field(
      "MAX_REDIRECTS",
      "Max Redirects",
      "number",
      "Maximum HTTP redirects to follow.",
    ) .
    $field(
      "MAX_DOWNLOAD_SIZE",
      "Max Download Size (bytes)",
      "number",
      "0 = unlimited. Example: 2147483648 = 2 GiB.",
    );

  $fieldsSslToggle = $toggle(
    "SSL_VERIFY_PEER",
    "Verify SSL Peer",
    "Enforce SSL certificate verification. Disable only for self-signed certs in trusted environments.",
  );
  $fieldsLogToggle = $toggle(
    "LOG_ENABLED",
    "Enable Request Logging",
    "Write every proxied request to data/requests.log.",
  );
  $fieldsCacheToggle = $toggle(
    "CACHE_ENABLED",
    "Enable Response Cache",
    "Cache upstream responses on disk.",
  );
  $fieldsLogCache =
    $fieldsLogToggle .
    $field(
      "LOG_MAX_LINES",
      "Max Log Lines",
      "number",
      "Rolling window — older lines are trimmed automatically.",
    ) .
    $fieldsCacheToggle .
    $field(
      "CACHE_DEFAULT_TTL",
      "Default Cache TTL (seconds)",
      "number",
      "How long to cache responses when no TTL is specified.",
    );
  $fieldsMisc = $field(
    "USER_AGENT",
    "User-Agent String",
    "text",
    "Sent with every upstream request.",
  );

  $sslCainfoVal = htmlspecialchars($v["SSL_CAINFO"] ?? "");
  $csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token_generate()) . '">';

  return <<<HTML
  <div class="admin-page-header">
    <h2>Settings</h2>
    <span class="admin-muted">Changes are written to <code>config.php</code> immediately.</span>
  </div>

  {$errHtml}

  <form method="post" action="?mode=admin&tab=settings" class="settings-form">
    {$csrfField}
    <input type="hidden" name="action" value="save_settings">

    <div class="settings-section">
      <div class="settings-section-title">Timeouts &amp; Limits</div>
      {$fieldsTimeouts}
    </div>

    <div class="settings-section">
      <div class="settings-section-title">Security</div>
      {$fieldsSslToggle}
      <div class="field">
        <label for="f_SSL_CAINFO">SSL CA Bundle Path <span class="opt">(optional)</span></label>
        <div class="input-wrap">
          <span class="input-prefix">📄</span>
          <input id="f_SSL_CAINFO" name="SSL_CAINFO" type="text"
                 value="{$sslCainfoVal}"
                 placeholder="/etc/ssl/certs/ca-certificates.crt">
        </div>
        <span class="field-hint">Path to a custom PEM CA bundle. Leave blank to use system default.</span>
      </div>
      <div class="field">
        <label for="f_ALLOWED_HOSTS">Allowed Hosts <span class="opt">(comma-separated, empty = all)</span></label>
        <div class="input-wrap">
          <span class="input-prefix">🌐</span>
          <input id="f_ALLOWED_HOSTS" name="ALLOWED_HOSTS" type="text"
                 value="{$allowedVal}"
                 placeholder="cdn.example.com, releases.example.org">
        </div>
        <span class="field-hint">Leave empty to allow all hosts. Not recommended for public instances.</span>
      </div>
      <div class="field">
        <label for="f_BLOCKED_EXTENSIONS">Blocked Extensions <span class="opt">(comma-separated)</span></label>
        <textarea id="f_BLOCKED_EXTENSIONS" name="BLOCKED_EXTENSIONS"
                  rows="3" class="settings-textarea">{$blockedVal}</textarea>
        <span class="field-hint">File extensions that will always be rejected.</span>
      </div>
    </div>

    <div class="settings-section">
      <div class="settings-section-title">Authentication</div>
      <div class="field">
        <label for="f_SECRET_TOKEN">
          API Secret Token
          <span class="opt">({$tokenStatus})</span>
        </label>
        <div class="input-wrap">
          <span class="input-prefix">🔑</span>
          <input id="f_SECRET_TOKEN" name="SECRET_TOKEN" type="password"
                 autocomplete="new-password" placeholder="{$tokenPlaceholder}">
        </div>
        <span class="field-hint">Accepted via X-Token header or ?token= param. Leave blank to keep current value.</span>
      </div>
      <div class="field">
        <label for="f_ADMIN_PASSWORD">
          Admin Password
          <span class="opt">({$adminStatus})</span>
        </label>
        <div class="input-wrap">
          <span class="input-prefix">🔒</span>
          <input id="f_ADMIN_PASSWORD" name="ADMIN_PASSWORD" type="password"
                 autocomplete="new-password" placeholder="{$adminPlaceholder}">
        </div>
        <span class="field-hint">Leave blank to keep current value. Clear the field and save to disable admin UI.</span>
      </div>
    </div>

    <div class="settings-section">
      <div class="settings-section-title">Logging &amp; Cache</div>
      {$fieldsLogCache}
    </div>

    <div class="settings-section">
      <div class="settings-section-title">Miscellaneous</div>
      {$fieldsMisc}
    </div>

    <div class="settings-actions">
      <button class="btn" type="submit">💾 Save Settings</button>
      <a href="?mode=admin&tab=settings" class="btn-sm">Discard Changes</a>
    </div>

  </form>
  HTML;
}
// ── Shared helpers ────────────────────────────────────────────────────────────

function render_request_table(array $records): string
{
  if (empty($records)) {
    return '<div class="admin-empty">No records found.</div>';
  }

  $rows = "";
  foreach ($records as $r) {
    $status = (int) ($r["status"] ?? 0);
    $cls =
      $status >= 500
        ? "badge-red"
        : ($status >= 400
          ? "badge-yellow"
          : "badge-green");
    $url = htmlspecialchars($r["url"] ?? "");
    $method = htmlspecialchars($r["method"] ?? "GET");
    $mode = htmlspecialchars($r["mode"] ?? "");
    $ip = htmlspecialchars($r["client_ip"] ?? "");
    $bytes = format_bytes((int) ($r["bytes"] ?? 0));
    $dur = isset($r["duration_ms"]) ? $r["duration_ms"] . "ms" : "—";
    $ts = isset($r["ts"]) ? date("H:i:s", $r["ts"]) : "—";
    $cached = !empty($r["cached"])
      ? ' <span class="badge-green">cached</span>'
      : "";
    $error = !empty($r["error"])
      ? '<span class="badge-red" title="' .
        htmlspecialchars((string) $r["error"]) .
        '">err</span>'
      : "";

    $rows .= <<<HTML
    <tr>
      <td>{$ts}</td>
      <td><span class="method-badge">{$method}</span></td>
      <td><span class="{$cls}">{$status}</span></td>
      <td class="td-url" title="{$url}">{$url}</td>
      <td>{$mode}{$cached}</td>
      <td>{$bytes}</td>
      <td>{$dur}</td>
      <td>{$ip}</td>
      <td>{$error}</td>
    </tr>
    HTML;
  }

  return <<<HTML
  <div class="admin-card">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Time</th><th>Method</th><th>Status</th><th>URL</th>
          <th>Mode</th><th>Bytes</th><th>Duration</th><th>Client IP</th><th></th>
        </tr>
      </thead>
      <tbody>{$rows}</tbody>
    </table>
  </div>
  HTML;
}

function admin_select_options(array $options, string $selected): string
{
  $html = "";
  foreach ($options as $opt) {
    $sel = $opt === $selected ? " selected" : "";
    $label = $opt === "" ? "All" : htmlspecialchars($opt);
    $html .=
      "<option value=\"" .
      htmlspecialchars($opt) .
      "\"{$sel}>{$label}</option>";
  }
  return $html;
}

function admin_pager(int $current, int $total, string $base): string
{
  if ($total <= 1) {
    return "";
  }
  $html = '<div class="admin-pager">';
  $safeBase = htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  for ($i = 1; $i <= $total; $i++) {
    $active = $i === $current ? " active" : "";
    $html .= "<a href=\"{$safeBase}&amp;page={$i}\" class=\"admin-page-btn{$active}\">{$i}</a>";
  }
  return $html . "</div>";
}

function admin_redirect(string $tab, string $flash = ""): void
{
  if ($flash !== "") {
    $_SESSION["admin_flash"] = $flash;
  }
  header("Location: ?mode=admin&tab={$tab}");
  exit();
}
