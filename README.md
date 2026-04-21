# RelayFetch

> A self-hosted PHP proxy that fetches remote files on your behalf — SSRF-safe, streaming, range-aware, with an optional Admin UI and API relay mode.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)
![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)

---

## Table of Contents

- [What It Does](#what-it-does)
- [Features](#features)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [How It Works](#how-it-works)
  - [Download Mode](#download-mode)
  - [API Relay Mode](#api-relay-mode)
  - [Redirect Mode](#redirect-mode)
  - [Cache Layer](#cache-layer)
- [Web UI](#web-ui)
- [Admin UI](#admin-ui)
  - [Requests Tab](#requests-tab)
  - [Metrics Tab](#metrics-tab)
  - [Cache Tab](#cache-tab)
  - [Tokens Tab](#tokens-tab)
  - [Settings Tab](#settings-tab)
- [API & Direct Usage](#api--direct-usage)
  - [Examples](#examples)
- [Code Snippets Panel](#code-snippets-panel)
- [Configuration](#configuration)
  - [Download & Streaming](#download--streaming)
  - [Access Control](#access-control)
  - [SSL](#ssl)
  - [Admin UI (config)](#admin-ui-config)
  - [Cache](#cache)
  - [Proxy / Network](#proxy--network)
  - [Host Allowlist](#host-allowlist)
  - [Token Authentication](#token-authentication)
- [Security Model](#security-model)
- [Project Structure](#project-structure)
- [Web Server Setup](#web-server-setup)
  - [Nginx](#nginx)
  - [Apache](#apache)
- [License](#license)

---

## What It Does

RelayFetch sits between your users (or scripts) and any remote HTTP resource. You give it a URL; it fetches the file server-side, validates it, and streams it straight to the client — no temp files, no full buffering, no size limits.

**Why use a proxy instead of a direct download?**

- Your users are behind a firewall that blocks external traffic, but your server is not.
- You want to enforce an allowlist of trusted upstream hosts.
- You need to strip dangerous MIME types or file extensions before delivery.
- You want a single authenticated endpoint for all external assets.
- You need to cache expensive upstream responses and serve them locally.
- You want to relay JSON/XML API calls through your server (CORS proxy, auth injection).

---

## Features

| Category | What you get |
|:---|:---|
| 🛡️ **SSRF protection** | Blocks private/reserved IPs, all loopback variants (IPv4 + IPv6), DNS rebinding via IP-pinning on every cURL call |
| ↕️ **Range requests** | Resume interrupted downloads; `Range` header validated and forwarded |
| ♾️ **True streaming** | Configurable chunk size, never loads the whole file into memory |
| 📄 **Filename resolution** | Reads `Content-Disposition`, falls back to URL path, sanitises the result |
| 🧹 **MIME sanitisation** | Dangerous types (HTML, JS, PHP, XML…) overridden to `application/octet-stream` |
| 🚫 **Extension blocklist** | `.php`, `.exe`, `.sh`, `.bat`, `.ps1`, and many more rejected at validation |
| 🔑 **Token auth** | Global `SECRET_TOKEN` **or** per-token store with per-token method/host restrictions |
| 🌐 **Host allowlist** | Optionally restrict to a set of trusted upstream hosts |
| 🔀 **API relay mode** | Transparently proxies JSON/XML API requests, forwarding request and response headers |
| 💾 **Response cache** | Optional disk cache with configurable TTL; cache hits served without hitting upstream |
| 📊 **Admin UI** | Password-protected dashboard: request log, live metrics, cache manager, token manager, in-browser settings editor |
| 💻 **Snippet panel** | Dark terminal UI with live-generated fetch commands for 11 languages and TUI tools |

---

## Requirements

| Dependency | Minimum |
|:---|:---|
| PHP | 8.0 |
| ext-curl | any |
| ext-filter | any (bundled) |
| Web server | Apache / Nginx / Caddy / `php -S` |

---

## Quick Start

```bash
git clone https://github.com/khodaparastan/relayfetch.git
cd RelayFetch

# Dev server — no config needed
php -S localhost:8080

# Open the UI
open http://localhost:8080
```

For production, point your web server document root at the project directory. No build step, no Composer, no Node.

---

## How It Works

Every request to `index.php` goes through the same pipeline:

```
Client request
    │
    ▼
Token check (if SECRET_TOKEN or per-token store is active)
    │
    ▼
URL validation + SSRF check (gethostbyname → IP range filter)
    │
    ├─► Redirect?   → 301 to sanitised URL, done
    │
    ├─► API request? → Relay mode (forward headers, stream response)
    │
    └─► Download?
            │
            ├─► Cache hit?  → stream from disk, done
            │
            └─► Cache miss → HEAD probe → stream from upstream → optionally cache
```

### Download Mode

The default mode. RelayFetch:

1. Runs a HEAD probe to resolve the filename, content type, and content length.
2. Validates the resolved IP against SSRF blocklists (private ranges, loopback, link-local).
3. Pins the resolved IP via `CURLOPT_RESOLVE` so DNS cannot be rebound mid-transfer.
4. Sanitises the MIME type and rejects blocked extensions.
5. Streams the response body in configurable chunks directly to the client.

The client receives the file with the correct `Content-Disposition`, `Content-Type`, and `Content-Length` headers — indistinguishable from a direct download.

```bash
# The client downloads large.iso as if it came directly from upstream
curl -L -O 'https://relay.example.com/?url=https://releases.example.com/large.iso'

# Resume an interrupted download
curl -L -C - -O 'https://relay.example.com/?url=https://releases.example.com/large.iso'
```

### API Relay Mode

When the incoming request carries an API-style `Accept` header (`application/json`, `application/xml`, `text/xml`, `application/x-www-form-urlencoded`), RelayFetch switches to relay mode:

- Forwards `Accept`, `Content-Type`, `Authorization`, and `X-*` request headers upstream.
- Streams the upstream response body back with its original status code and content type.
- Does **not** force a `Content-Disposition: attachment` header.

This makes RelayFetch a drop-in CORS proxy or auth-injecting API gateway:

```bash
# Relay a JSON API call through your server
curl -H 'Accept: application/json' \
     'https://relay.example.com/?url=https://api.example.com/v1/items'

# The response is the raw JSON from the upstream API
```

### Redirect Mode

If the URL resolves to a web page (HTML content type) and no explicit download is requested, RelayFetch issues a `301` redirect to the validated URL rather than proxying the HTML. This prevents accidental HTML proxying while still being useful for link-shortening or URL normalisation workflows.

### Cache Layer

Enable caching in `config.php`:

```php
define('CACHE_ENABLED',     true);
define('CACHE_DEFAULT_TTL', 300);   // seconds
define('CACHE_DIR',         __DIR__ . '/data/cache');
```

On a cache **miss**: the file is fetched from upstream and written to `CACHE_DIR` alongside a metadata sidecar (URL, headers, TTL, size). On a cache **hit**: the stored body is streamed directly to the client — no upstream request at all. Cache entries can be inspected and flushed individually from the Admin UI **Cache** tab.

---

## Web UI

Open RelayFetch in a browser to get the interactive download UI:

```
┌─────────────────────────────────────────────────────────┐
│  $ RelayFetch                                           │
│  Remote file proxy · SSRF-safe · range-aware · no limits│
├─────────────────────────────────────────────────────────┤
│  Remote URL *                                           │
│  🔗 [ https://example.com/archive.tar.gz             ] │
│                                                         │
│  Save As (optional)          [⬇ Download]              │
│  📄 [ archive.tar.gz      ]                            │
├─────────────────────────────────────────────────────────┤
│  ● Ready-to-use fetch commands                          │
│  curl  wget  HTTPie  aria2c  Python  Node.js  Go  …    │
│                                                         │
│  curl -L -O 'https://relay.example.com/?url=…'         │
└─────────────────────────────────────────────────────────┘
```

- Paste any URL → the snippet panel instantly generates copy-ready fetch commands for every supported language.
- Hit **Download** → the browser triggers the proxied download directly.
- The info bar at the bottom shows the current endpoint, auth status, and range-request support.

---

## Admin UI

Enable by setting a password in `config.php`:

```php
define('ADMIN_PASSWORD', 'your-strong-password');
```

Then visit `/?mode=admin`. The session expires after `ADMIN_SESSION_TTL` seconds (default: 1 hour). All forms are CSRF-protected.

### Requests Tab

A paginated, filterable log of every proxied request:

```
Method  Status  Mode      URL                                   Bytes      Time
GET     200     download  https://releases.example.com/v2.tar…  142.3 MiB  1.2 s
GET     200     cache     https://releases.example.com/v2.tar…  142.3 MiB  0.0 s
GET     200     relay     https://api.example.com/v1/items       4.1 KiB    0.1 s
GET     403     blocked   https://192.168.1.1/secret             —          —
```

Filter by HTTP method, status code, or mode (`download` / `cache` / `relay` / `redirect` / `blocked`). Pagination is automatic.

### Metrics Tab

Live counters aggregated from the request log:

- **Total requests** and **bytes served**
- **Cache hits vs misses** and hit-rate percentage
- **Error rate** (4xx + 5xx responses)
- **Top upstream hosts** by request count
- Sparkline charts for request volume and status distribution over time

### Cache Tab

Browse every entry currently on disk:

```
URL                                    Size       TTL remaining  Actions
https://releases.example.com/v2.tar…  142.3 MiB  4m 12s         [Flush]
https://cdn.example.org/logo.png        18.4 KiB  1m 03s         [Flush]
```

Flush individual entries or clear the entire cache. TTL countdown is shown in real time.

### Tokens Tab

Manage per-token access credentials without touching `config.php`:

```
Token           Methods  Hosts                   Uses  Expires     Actions
tok_abc123…     GET      releases.example.com    47    2026-12-31  [Revoke]
tok_def456…     *        *                       12    never       [Revoke]
```

Each token can be restricted to specific HTTP methods and upstream hosts. Use counts are tracked automatically. Tokens are accepted via `?token=` query parameter or `X-Token` header, alongside the global `SECRET_TOKEN`.

### Settings Tab

Edit every `config.php` constant from the browser — no SSH required:

- Chunk size, timeouts, max download size, User-Agent
- Toggle cache on/off, change TTL and cache directory
- Update `ADMIN_PASSWORD` and session TTL
- Enable/disable request logging
- Add or remove blocked extensions

Changes are written back to `config.php` atomically and take effect on the next request.

---

## API & Direct Usage

```
GET /?url=<encoded-url>[&filename=<name>][&token=<token>]
```

| Parameter | Required | Description |
|:---|:---|:---|
| `url` | ✅ Yes | Absolute `http://` or `https://` URL to proxy |
| `filename` | No | Override the saved filename |
| `token` | Conditional | Required when `SECRET_TOKEN` is defined |

Token can also be passed as a header (preferred — keeps it out of server logs):

```
X-Token: your-secret-token
```

### Examples

```bash
# Basic download
curl -L -O 'http://localhost:8080/?url=https://example.com/archive.tar.gz'

# Custom filename
curl -L -o myfile.tar.gz \
     'http://localhost:8080/?url=https://example.com/archive.tar.gz&filename=myfile.tar.gz'

# With token auth (query param)
curl -L -O 'http://localhost:8080/?token=secret&url=https://example.com/file.zip'

# With token auth (header — preferred, keeps token out of logs)
curl -L -O -H 'X-Token: secret' \
     'http://localhost:8080/?url=https://example.com/file.zip'

# Resume an interrupted download
curl -L -C - -O 'http://localhost:8080/?url=https://example.com/large.iso'

# API relay — get JSON from an upstream API
curl -H 'Accept: application/json' \
     'http://localhost:8080/?url=https://api.example.com/v1/status'
```

---

## Code Snippets Panel

As you type a URL into the web UI, the snippet panel generates ready-to-copy commands for every supported tool. If a token is configured, it is injected automatically into every snippet (as an `X-Token` header, not a query parameter).

| Tab | Tool / Language |
|:---|:---|
| `curl` | curl — download, resume, custom filename |
| `wget` | wget — download, continue |
| `HTTPie` | `http` CLI |
| `aria2c` | aria2c multi-connection |
| `Python` | `requests` + streaming write |
| `Node.js` | Node 18+ native `fetch` |
| `Go` | `net/http` |
| `Rust` | `reqwest` async |
| `PHP` | stream-to-file |
| `PowerShell` | `Invoke-WebRequest` |
| `Ruby` | `Net::HTTP` |

---

## Configuration

All settings live in **`config.php`**. The Admin UI **Settings** tab can edit them from the browser.

### Download & Streaming

```php
define('CHUNK_SIZE',          2 * 1024 * 1024); // streaming chunk size in bytes; must be > 0
define('HEAD_TIMEOUT',        15);               // seconds for the initial HEAD probe
define('DL_TIMEOUT',          0);                // 0 = no wall-clock cap on downloads
define('DL_LOW_SPEED_LIMIT',  0);                // 0 = no minimum speed (bytes/s)
define('DL_LOW_SPEED_TIME',   0);                // 0 = no stall detection (seconds)
define('MAX_REDIRECTS',       5);                // max HTTP redirects to follow
define('MAX_DOWNLOAD_SIZE',   0);                // 0 = unlimited; e.g. 2*1024*1024*1024 = 2 GiB
define('USER_AGENT',          'Mozilla/5.0 (compatible; RelayFetch/1.0)');
```

### Access Control

```php
define('ALLOWED_HOSTS',      []);    // empty = allow all hosts; restrict for production
define('BLOCKED_EXTENSIONS', [...]); // extensions always rejected (php, exe, sh, bat, …)

// Uncomment to require a secret token on every request.
// define('SECRET_TOKEN', 'change_me');
```

### SSL

```php
define('SSL_VERIFY_PEER', true);  // set false only for private CAs / self-signed certs
// define('SSL_CAINFO', '/etc/ssl/certs/my-ca.pem');  // custom CA bundle
```

### Admin UI (config)

```php
define('ADMIN_PASSWORD',    '');    // empty = admin UI disabled; set a strong password
define('ADMIN_SESSION_TTL', 3600);  // seconds before re-login is required
define('LOG_MAX_LINES',     1000);  // rolling window size for requests.log
define('LOG_ENABLED',       true);
```

### Cache

```php
define('CACHE_ENABLED',     false);
define('CACHE_DIR',         __DIR__ . '/data/cache');
define('CACHE_DEFAULT_TTL', 300);   // seconds
```

### Proxy / Network

```php
// Set true only when RelayFetch runs behind a trusted reverse proxy
// that injects X-Forwarded-For. When false (default), REMOTE_ADDR is always used.
define('TRUST_PROXY', false);
```

### Host Allowlist

Leave `ALLOWED_HOSTS` empty to allow all upstream hosts (fine for dev). In production, restrict it:

```php
define('ALLOWED_HOSTS', [
    'releases.example.com',
    'cdn.example.org',
]);
```

Requests to any other host will be rejected with `403`.

### Token Authentication

**Single global token** — uncomment in `config.php`:

```php
define('SECRET_TOKEN', 'super-secret-value');
```

Pass it as a header (preferred) or query parameter:

```bash
curl -H 'X-Token: super-secret-value' \
     'https://relay.example.com/?url=https://example.com/file.zip'
```

**Per-token store** — use the Admin UI **Tokens** tab to create tokens with individual method/host restrictions, expiry dates, and usage tracking. Per-token store tokens are accepted alongside `SECRET_TOKEN`.

---

## Security Model

| Threat | Mitigation |
|:---|:---|
| SSRF via private IP | `gethostbyname()` + `FILTER_FLAG_NO_PRIV_RANGE \| FILTER_FLAG_NO_RES_RANGE` |
| SSRF via IPv6 loopback | Explicit blocklist: `::1`, `0:0:0:0:0:0:0:1`, `::ffff:127.0.0.1`, `fe80::1` |
| DNS rebinding (TOCTOU) | Resolved IP pinned via `CURLOPT_RESOLVE` on every cURL handle |
| Malicious redirects | `CURLOPT_MAXREDIRS` capped; redirect targets re-validated for SSRF |
| Dangerous MIME types | Overridden to `application/octet-stream` before streaming |
| Dangerous extensions | Blocked at URL validation and again at filename sanitisation |
| Range header injection | Validated against `^bytes=\d*-\d*(,\s*\d*-\d*)*$` before forwarding |
| IP spoofing via XFF | `X-Forwarded-For` only trusted when `TRUST_PROXY = true` |
| Admin CSRF | All state-changing admin forms carry a session-bound CSRF token |
| Login CSRF | Login form carries a CSRF token; token regenerated after successful login |
| Admin session fixation | `session_regenerate_id(true)` called on every successful login |
| XSS in error/admin pages | All user-supplied values passed through `htmlspecialchars()` |

---

## Project Structure

```
RelayFetch/
├── config.php          # All tuneable constants
├── index.php           # Request router and orchestrator
├── styles.css          # Dark terminal UI stylesheet
├── admin.css           # Admin dashboard stylesheet
├── fetcher.js          # Live snippet panel (vanilla JS)
├── admin.js            # Admin UI dashboard scripts
└── src/
    ├── AdminAuth.php   # Admin session, login/logout, CSRF helpers
    ├── AdminUI.php     # Admin panel tabs and settings writer
    ├── Cache.php       # Disk response cache (get / put / flush)
    ├── Downloader.php  # HEAD probe, filename resolution, streaming
    ├── Helpers.php     # bail(), sanitize_filename(), resolve_mime()
    ├── Logger.php      # Rolling NDJSON request log
    ├── Metrics.php     # Atomic metrics counters, format_bytes()
    ├── Relay.php       # API relay mode, header forwarding
    ├── Security.php    # validate_url(), verify_token(), SSRF checks
    ├── Tokens.php      # Per-token store (add / revoke / verify)
    └── UI.php          # show_ui() — HTML shell for the web UI
```

---

## Web Server Setup

### Nginx

```nginx
server {
    listen 443 ssl;
    server_name relayfetch.example.com;

    root /var/www/relayfetch;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block direct access to src/ files
    location ~ ^/src/ {
        deny all;
    }
}
```

### Apache

```apache
<VirtualHost *:80>
    DocumentRoot /var/www/relayfetch
    DirectoryIndex index.php

    <Directory /var/www/relayfetch>
        AllowOverride All
        Require all granted
    </Directory>

    <DirectoryMatch "^/var/www/relayfetch/src/">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

> **Tip:** Add an `.htaccess` in `src/` as a fallback:
>
> ```apache
> Require all denied
> ```

---

## License

[MIT](LICENSE) © 2026 Khodaparastan
