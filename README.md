# RelayFetch

> A self-hosted, SSRF-safe remote file proxy and stream server for developers and sysadmins.
> Paste a URL, get a download — or grab the auto-generated fetch command for your preferred language or TUI tool.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)
![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [API & Direct Usage](#api--direct-usage)
- [Code Snippets UI](#code-snippets-ui)
- [Security Model](#security-model)
- [Project Structure](#project-structure)
- [Web Server Setup](#web-server-setup)
  - [Nginx](#nginx)
  - [Apache](#apache)
- [License](#license)

---

## Features

- 🛡️ **SSRF protection** — blocks private/reserved IPs, loopback hosts, and DNS rebinding via IP-pinning on every cURL call
- ↕️ **Range request support** — resume interrupted downloads; validated before forwarding
- ♾️ **No size limits** — streams in configurable chunks, never buffers the whole file in memory
- 📄 **Filename resolution** — reads `Content-Disposition` headers, falls back to URL path
- 🧹 **MIME sanitisation** — neutralises dangerous types (HTML, JS, PHP, XML…) → `application/octet-stream`
- 🚫 **Extension blocklist** — rejects `.php`, `.exe`, `.sh`, `.bat`, `.ps1`, and more
- 🔑 **Optional token auth** — protect the endpoint with a shared secret
- 🌐 **Host allowlist** — optionally restrict to a set of trusted upstream hosts
- 💻 **Dev-first UI** — dark terminal aesthetic with a live code-snippet panel (11 languages / TUI tools)

---

## Requirements

| Dependency | Minimum Version                   |
| :--------- | :-------------------------------- |
| PHP        | 8.0                               |
| ext-curl   | any                               |
| ext-filter | any (bundled)                     |
| Web server | Apache / Nginx / Caddy / `php -S` |

---

## Quick Start

```bash
# Clone
git clone https://github.com/khodaparastan/relayfetch.git
cd RelayFetch

# Serve locally (dev)
php -S localhost:8080

# Open in browser
open http://localhost:8080
```

> For production, point your web server document root at the project directory. No build step required.

---

## Configuration

All settings live in **`config.php`**.

```php
define('CHUNK_SIZE',         256 * 1024);  // streaming chunk size in bytes
define('HEAD_TIMEOUT',       15);          // seconds for HEAD probe
define('DL_TIMEOUT',         3600);        // seconds for full download
define('MAX_REDIRECTS',      5);           // max HTTP redirects to follow
define('USER_AGENT',         'Mozilla/5.0 (compatible; RelayFetch/1.0)');
define('ALLOWED_HOSTS',      []);          // empty = allow all (see below)
define('BLOCKED_EXTENSIONS', [            // file extensions always rejected
    'php', 'phtml', 'phar',
    'exe', 'sh', 'bat', 'cmd', 'ps1',
]);

// Uncomment to require a secret token → ?token=your_secret
// define('SECRET_TOKEN', 'change_me');
```

### Host Allowlist

Set `ALLOWED_HOSTS` to a non-empty array to restrict which upstream hosts RelayFetch will proxy. When empty, all hosts are permitted — useful for dev, **not recommended for production**.

```php
define('ALLOWED_HOSTS', [
    'releases.example.com',
    'cdn.example.org',
]);
```

### Token Authentication

Uncomment and set `SECRET_TOKEN` in `config.php`:

```php
define('SECRET_TOKEN', 'super-secret-value');
```

Then pass the token as a query parameter or `X-Token` header:

```
GET /?token=super-secret-value&url=https://…
```

---

## API & Direct Usage

```
GET /?url=<encoded-url>[&filename=<name>][&token=<token>]
```

| Parameter  | Required    | Description                                   |
| :--------- | :---------- | :-------------------------------------------- |
| `url`      | ✅ Yes      | Absolute `http://` or `https://` URL to proxy |
| `filename` | No          | Override the saved filename                   |
| `token`    | Conditional | Required when `SECRET_TOKEN` is defined       |

### Examples

```bash
# Basic download
curl -L -O 'http://localhost:8080/?url=https://example.com/archive.tar.gz'

# Custom filename
curl -L -o myfile.tar.gz \
     'http://localhost:8080/?url=https://example.com/archive.tar.gz&filename=myfile.tar.gz'

# With token auth
curl -L -O \
     'http://localhost:8080/?token=secret&url=https://example.com/file.zip'

# Resume an interrupted download (range request)
curl -L -C - -O \
     'http://localhost:8080/?url=https://example.com/large.iso'
```

---

## Code Snippets UI

The web UI auto-generates ready-to-copy fetch commands as you type a URL. Supported languages and tools:

| Tab          | Tool / Language                      |
| :----------- | :----------------------------------- |
| `curl`       | curl — download, resume, custom name |
| `wget`       | wget — download, continue            |
| `HTTPie`     | `http` CLI                           |
| `aria2c`     | aria2c multi-connection              |
| `Python`     | `requests` + streaming               |
| `Node.js`    | Node 18+ native `fetch`              |
| `Go`         | `net/http`                           |
| `Rust`       | `reqwest` async                      |
| `PHP`        | `file_get_contents` / stream         |
| `PowerShell` | `Invoke-WebRequest`                  |
| `Ruby`       | `Net::HTTP`                          |

> If a token is configured, it is injected automatically into every generated snippet.

---

## Security Model

| Threat                 | Mitigation                                                                  |
| :--------------------- | :-------------------------------------------------------------------------- |
| SSRF via private IP    | `gethostbyname()` + `FILTER_FLAG_NO_PRIV_RANGE \| FILTER_FLAG_NO_RES_RANGE` |
| DNS rebinding (TOCTOU) | Resolved IP pinned via `CURLOPT_RESOLVE` on every cURL handle               |
| Malicious redirects    | `CURLOPT_MAXREDIRS` capped; SSL peer/host verification enforced             |
| Dangerous MIME types   | Overridden to `application/octet-stream` before streaming                   |
| Dangerous extensions   | Blocked at URL validation and again at filename sanitisation                |
| Range header injection | Validated against `^bytes=\d*-\d*(,\s*\d*-\d*)*$` before forwarding         |
| Token leakage          | Never embedded in HTML; entered via `type="password"` field only            |
| XSS in error pages     | All user-supplied values passed through `htmlspecialchars()`                |

---

## Project Structure

```
RelayFetch/
├── config.php          # All tuneable constants
├── index.php           # Request router / orchestrator
├── styles.css          # Dark terminal UI stylesheet
├── fetcher.js          # Live snippet panel (vanilla JS)
└── src/
    ├── Downloader.php  # HEAD probe, filename resolution, streaming
    ├── Helpers.php     # bail(), sanitize_filename(), resolve_mime(), load_css()
    ├── Security.php    # validate_url(), verify_token()
    └── UI.php          # show_ui() — HTML shell
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

    # Block direct access to src/ files
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
