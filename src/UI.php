<?php
declare(strict_types=1);

function show_ui(): void
{
  // Security headers for the UI page
  header("X-Frame-Options: DENY");
  header("X-Content-Type-Options: nosniff");
  header("Referrer-Policy: no-referrer");
  header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self';");

  $tokenField = (defined("SECRET_TOKEN") && SECRET_TOKEN !== "")
    ? '<div class="field">
        <label for="token">Access Token <span class="req">*</span></label>
        <div class="input-wrap">
          <span class="input-prefix">&#128273;</span>
          <input id="token" name="token" type="password" required
            placeholder="your-secret-token"
            autocomplete="off" spellcheck="false">
        </div>
      </div>'
    : "";

  $scheme =
    isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on" ? "https" : "http";
  $host = $_SERVER["HTTP_HOST"] ?? "localhost";
  $path = strtok($_SERVER["REQUEST_URI"] ?? "/", "?");
  $baseUrl = htmlspecialchars(
    $scheme . "://" . $host . $path,
    ENT_QUOTES,
    "UTF-8",
  );

  $authStatus = (defined("SECRET_TOKEN") && SECRET_TOKEN !== "") ? "token required" : "open";

  echo <<<HTML
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Relayfetch &mdash; remote file proxy</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body>
  <div class="page">

    <header>
      <div class="header-top">
        <span style="font-size:1.1rem">&#11015;</span>
        <h1><span class="prompt">$ </span><span class="cmd">RelayFetch</span></h1>
      </div>
      <p class="tagline">
        Remote file proxy &amp; stream server &middot;
        <span class="hl">SSRF-safe</span> &middot;
        <span class="hl">range-aware</span> &middot;
        <span class="hl">no size limits</span>
      </p>
    </header>

    <!-- Input card -->
    <div class="card">
      <form id="dlForm" method="get" action="">
        {$tokenField}

        <div class="field">
          <label for="url">Remote URL <span class="req">*</span></label>
          <div class="input-wrap">
            <span class="input-prefix">&#128279;</span>
            <input id="url" name="url" type="url" required
              placeholder="https://example.com/archive.tar.gz"
              autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div class="field-row">
          <div class="field">
            <label for="filename">Save As <span class="opt">(optional)</span></label>
            <div class="input-wrap">
              <span class="input-prefix">&#128196;</span>
              <input id="filename" name="filename" type="text"
                placeholder="archive.tar.gz"
                autocomplete="off" spellcheck="false">
            </div>
          </div>
          <button class="btn" type="submit" id="dlBtn">
            <span id="btnText">&#11015; Download</span>
            <span id="btnSpinner" hidden>&#9203; Starting&hellip;</span>
          </button>
        </div>
      </form>

      <div id="notice" class="notice" hidden>
        &#10003; &nbsp;<span><strong>Download started.</strong> Your browser will handle the rest.</span>
      </div>
    </div>

    <!-- Code snippet panel -->
    <div class="snippet-panel">
      <div class="snippet-header">
        <span class="snippet-title"><span class="dot"></span>Ready-to-use fetch commands</span>
        <span id="snippetStatus" style="font-family:var(--font-mono);font-size:.72rem;color:var(--text-muted)">
          enter a URL above &#8593;
        </span>
      </div>

      <div id="snippetTabs" class="tabs" hidden>
        <span class="tab active" data-lang="curl">curl</span>
        <span class="tab" data-lang="wget">wget</span>
        <span class="tab" data-lang="httpie">HTTPie</span>
        <span class="tab" data-lang="aria2">aria2c</span>
        <span class="tab" data-lang="python">Python</span>
        <span class="tab" data-lang="node">Node.js</span>
        <span class="tab" data-lang="go">Go</span>
        <span class="tab" data-lang="rust">Rust</span>
        <span class="tab" data-lang="php">PHP</span>
        <span class="tab" data-lang="powershell">PowerShell</span>
        <span class="tab" data-lang="ruby">Ruby</span>
      </div>

      <div id="snippetBody">
        <div class="snippet-placeholder">
          <span class="arrow">&#8594;</span> Paste a URL in the field above to generate fetch commands
        </div>
      </div>
    </div>

    <!-- Info grid -->
    <div class="info-grid">
      <div class="info-item">
        <div class="info-label">Endpoint</div>
        <div class="info-val">{$baseUrl}</div>
      </div>
      <div class="info-item">
        <div class="info-label">Auth</div>
        <div class="info-val">{$authStatus}</div>
      </div>
      <div class="info-item">
        <div class="info-label">Range requests</div>
        <div class="info-val">supported</div>
      </div>
      <div class="info-item">
        <div class="info-label">Max file size</div>
        <div class="info-val">unlimited</div>
      </div>
    </div>

    <footer>
      <span>Direct link:</span>
      <code>?url=https://&hellip;&amp;filename=out.zip</code>
      <span style="color:var(--border)">|</span>
      <span>Token header:</span>
      <code>X-Token: &hellip;</code>
    </footer>

  </div>
  <script src="fetcher.js"></script>
  </body>
  </html>
  HTML;
}
