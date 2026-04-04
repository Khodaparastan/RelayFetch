(function () {
  "use strict";

  const urlInput = document.getElementById("url");
  const fnInput = document.getElementById("filename");
  const form = document.getElementById("dlForm");
  const dlBtn = document.getElementById("dlBtn");
  const btnText = document.getElementById("btnText");
  const spinner = document.getElementById("btnSpinner");
  const notice = document.getElementById("notice");
  const tabs = document.getElementById("snippetTabs");
  const snippetBody = document.getElementById("snippetBody");
  const statusEl = document.getElementById("snippetStatus");
  const tokenInput = document.getElementById("token");

  /*── HTML escape ────────────────────────────────────────────────────────*/
  function esc(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  /*── Snippet generators ─────────────────────────────────────────────────*/
  const LANGS = {
    curl: function (p, r, fn, tok) {
      var hdr = tok ? "\n     -H 'X-Token: " + esc(tok) + "'" : "";
      return (
        '<span class="c-cmt"># Download via Relayfetch proxy (recommended)</span>\n' +
        '<span class="c-kw">curl</span> -L -O' +
        hdr +
        " \\\n" +
        '     <span class="c-str">\'' +
        esc(p) +
        "'</span>\n\n" +
        '<span class="c-cmt"># Resume a partial download</span>\n' +
        '<span class="c-kw">curl</span> -L -C - -O' +
        hdr +
        " \\\n" +
        '     <span class="c-str">\'' +
        esc(p) +
        "'</span>\n\n" +
        '<span class="c-cmt"># Save with custom name</span>\n' +
        '<span class="c-kw">curl</span> -L' +
        hdr +
        " \\\n" +
        '     -o <span class="c-str">\'' +
        esc(fn) +
        "'</span> \\\n" +
        '     <span class="c-str">\'' +
        esc(p) +
        "'</span>"
      );
    },

    wget: function (p, r, fn, tok) {
      var hdr = tok ? " \\\n     --header='X-Token: " + esc(tok) + "'" : "";
      return (
        '<span class="c-cmt"># Download via Relayfetch proxy</span>\n' +
        '<span class="c-kw">wget</span>' +
        hdr +
        " \\\n" +
        '     -O <span class="c-str">\'' +
        esc(fn) +
        "'</span> \\\n" +
        '     <span class="c-str">\'' +
        esc(p) +
        "'</span>\n\n" +
        '<span class="c-cmt"># Continue interrupted download</span>\n' +
        '<span class="c-kw">wget</span> -c' +
        hdr +
        " \\\n" +
        '     -O <span class="c-str">\'' +
        esc(fn) +
        "'</span> \\\n" +
        '     <span class="c-str">\'' +
        esc(p) +
        "'</span>"
      );
    },

    httpie: function (p, r, fn, tok) {
      var hdr = tok ? " X-Token:" + esc(tok) : "";
      return (
        '<span class="c-cmt"># HTTPie — human-friendly HTTP client</span>\n' +
        '<span class="c-kw">http</span> --download' +
        hdr +
        " \\\n" +
        '     <span class="c-str">\'' +
        esc(p) +
        "'</span>\n\n" +
        '<span class="c-cmt"># Save with explicit filename</span>\n' +
        '<span class="c-kw">http</span> --download --output=<span class="c-str">\'' +
        esc(fn) +
        "'</span>" +
        hdr +
        " \\\n" +
        '     <span class="c-str">\'' +
        esc(p) +
        "'</span>"
      );
    },

    aria2: function (p, r, fn, tok) {
      var hdr = tok ? " \\\n       --header='X-Token: " + esc(tok) + "'" : "";
      return (
        '<span class="c-cmt"># aria2c — multi-connection downloader</span>\n' +
        '<span class="c-kw">aria2c</span> -x 8 -s 8' +
        hdr +
        " \\\n" +
        '       --out=<span class="c-str">\'' +
        esc(fn) +
        "'</span> \\\n" +
        '       <span class="c-str">\'' +
        esc(p) +
        "'</span>\n\n" +
        '<span class="c-cmt"># Resume + multi-connection</span>\n' +
        '<span class="c-kw">aria2c</span> -x 8 -s 8 --continue=true' +
        hdr +
        " \\\n" +
        '       --out=<span class="c-str">\'' +
        esc(fn) +
        "'</span> \\\n" +
        '       <span class="c-str">\'' +
        esc(p) +
        "'</span>"
      );
    },

    python: function (p, r, fn, tok) {
      var hdrVal = tok ? esc(tok) : "";
      return (
        '<span class="c-kw">import</span> requests, shutil\n\n' +
        '<span class="c-kw">def</span> <span class="c-fn">download</span>(url, dest):\n' +
        '    headers = {<span class="c-str">"X-Token"</span>: <span class="c-str">"' +
        hdrVal +
        '"</span>}' +
        (tok ? "" : '  <span class="c-cmt"># omit if no token</span>') +
        "\n" +
        '    <span class="c-kw">with</span> requests.<span class="c-fn">get</span>(url, headers=headers, stream=<span class="c-kw">True</span>) <span class="c-kw">as</span> r:\n' +
        '        r.<span class="c-fn">raise_for_status</span>()\n' +
        '        <span class="c-kw">with</span> <span class="c-fn">open</span>(dest, <span class="c-str">"wb"</span>) <span class="c-kw">as</span> f:\n' +
        '            shutil.<span class="c-fn">copyfileobj</span>(r.raw, f)\n\n' +
        '<span class="c-fn">download</span>(<span class="c-str">"' +
        esc(p) +
        '"</span>, <span class="c-str">"' +
        esc(fn) +
        '"</span>)'
      );
    },

    node: function (p, r, fn, tok) {
      var hdr = tok ? '"X-Token": "' + esc(tok) + '"' : "";
      return (
        '<span class="c-cmt">// Node.js 18+ (native fetch)</span>\n' +
        '<span class="c-kw">import</span> { createWriteStream } <span class="c-kw">from</span> <span class="c-str">"fs"</span>;\n' +
        '<span class="c-kw">import</span> { Readable } <span class="c-kw">from</span> <span class="c-str">"stream"</span>;\n' +
        '<span class="c-kw">import</span> { pipeline } <span class="c-kw">from</span> <span class="c-str">"stream/promises"</span>;\n\n' +
        '<span class="c-kw">const</span> res = <span class="c-kw">await</span> <span class="c-fn">fetch</span>(<span class="c-str">"' +
        esc(p) +
        '"</span>, {\n' +
        "  headers: { " +
        hdr +
        " },\n" +
        "});\n" +
        '<span class="c-kw">if</span> (!res.ok) <span class="c-kw">throw new</span> <span class="c-fn">Error</span>(<span class="c-str">"HTTP " + res.status</span>);\n' +
        '<span class="c-kw">await</span> <span class="c-fn">pipeline</span>(Readable.<span class="c-fn">fromWeb</span>(res.body), <span class="c-fn">createWriteStream</span>(<span class="c-str">"' +
        esc(fn) +
        '"</span>));'
      );
    },

    go: function (p, r, fn, tok) {
      var hdrLine = tok
        ? '\n\treq.Header.Set(<span class="c-str">"X-Token"</span>, <span class="c-str">"' +
          esc(tok) +
          '"</span>)'
        : "";
      return (
        '<span class="c-kw">package</span> main\n\n' +
        '<span class="c-kw">import</span> (\n\t<span class="c-str">"io"</span>; <span class="c-str">"net/http"</span>; <span class="c-str">"os"</span>\n)\n\n' +
        '<span class="c-kw">func</span> <span class="c-fn">main</span>() {\n' +
        '\treq, _ := http.<span class="c-fn">NewRequest</span>(<span class="c-str">"GET"</span>, <span class="c-str">"' +
        esc(p) +
        '"</span>, <span class="c-kw">nil</span>)' +
        hdrLine +
        "\n" +
        '\tresp, _ := http.DefaultClient.<span class="c-fn">Do</span>(req)\n' +
        '\t<span class="c-kw">defer</span> resp.Body.<span class="c-fn">Close</span>()\n' +
        '\tf, _ := os.<span class="c-fn">Create</span>(<span class="c-str">"' +
        esc(fn) +
        '"</span>)\n' +
        '\t<span class="c-kw">defer</span> f.<span class="c-fn">Close</span>()\n' +
        '\tio.<span class="c-fn">Copy</span>(f, resp.Body)\n}'
      );
    },

    rust: function (p, r, fn, tok) {
      var hdrLine = tok
        ? '\n        .<span class="c-fn">header</span>(<span class="c-str">"X-Token"</span>, <span class="c-str">"' +
          esc(tok) +
          '"</span>)'
        : "";
      return (
        '<span class="c-cmt">// Cargo.toml: reqwest = { version = "0.12", features = ["blocking"] }</span>\n' +
        '<span class="c-kw">use</span> std::io;\n' +
        '<span class="c-kw">use</span> std::fs::File;\n\n' +
        '<span class="c-kw">fn</span> <span class="c-fn">main</span>() -> Result&lt;(), Box&lt;<span class="c-kw">dyn</span> std::error::Error&gt;&gt; {\n' +
        '    <span class="c-kw">let mut</span> resp = reqwest::blocking::Client::new()\n' +
        '        .<span class="c-fn">get</span>(<span class="c-str">"' +
        esc(p) +
        '"</span>)' +
        hdrLine +
        "\n" +
        '        .<span class="c-fn">send</span>()?;\n' +
        '    <span class="c-kw">let mut</span> file = File::<span class="c-fn">create</span>(<span class="c-str">"' +
        esc(fn) +
        '"</span>)?;\n' +
        '    io::<span class="c-fn">copy</span>(&amp;<span class="c-kw">mut</span> resp, &amp;<span class="c-kw">mut</span> file)?;\n' +
        "    Ok(())\n}"
      );
    },

    php: function (p, r, fn, tok) {
      var hdrLine = tok
        ? "\n    CURLOPT_HTTPHEADER => ['X-Token: " + esc(tok) + "'],"
        : "";
      return (
        '<span class="c-kw">&lt;?php</span>\n' +
        '<span class="c-var">$ch</span> = curl_init();\n' +
        'curl_setopt_array(<span class="c-var">$ch</span>, [\n' +
        '    CURLOPT_URL            => <span class="c-str">\'' +
        esc(p) +
        "'</span>,\n" +
        '    CURLOPT_FOLLOWLOCATION => <span class="c-kw">true</span>,' +
        hdrLine +
        "\n" +
        '    CURLOPT_FILE           => <span class="c-fn">fopen</span>(<span class="c-str">\'' +
        esc(fn) +
        "'</span>, <span class=\"c-str\">'wb'</span>),\n" +
        "]);\n" +
        'curl_exec(<span class="c-var">$ch</span>);\n' +
        'curl_close(<span class="c-var">$ch</span>);'
      );
    },

    powershell: function (p, r, fn, tok) {
      var hdrLine = tok
        ? '<span class="c-var">$headers</span> = @{ <span class="c-str">"X-Token"</span> = <span class="c-str">"' +
          esc(tok) +
          '"</span> }'
        : '<span class="c-var">$headers</span> = @{}';
      return (
        '<span class="c-cmt"># PowerShell 7+ / Windows PowerShell</span>\n' +
        hdrLine +
        "\n" +
        'Invoke-WebRequest <span class="c-str">"' +
        esc(p) +
        '"</span> `\n' +
        '    -Headers <span class="c-var">$headers</span> `\n' +
        '    -OutFile <span class="c-str">"' +
        esc(fn) +
        '"</span>\n\n' +
        '<span class="c-cmt"># Alternative: faster for large files</span>\n' +
        '(New-Object Net.WebClient).DownloadFile(<span class="c-str">"' +
        esc(p) +
        '"</span>, <span class="c-str">"' +
        esc(fn) +
        '"</span>)'
      );
    },

    ruby: function (p, r, fn, tok) {
      var hdrLine = tok ? ', "X-Token" => "' + esc(tok) + '"' : "";
      return (
        '<span class="c-kw">require</span> <span class="c-str">"open-uri"</span>\n' +
        '<span class="c-kw">require</span> <span class="c-str">"fileutils"</span>\n\n' +
        'URI.<span class="c-fn">open</span>(<span class="c-str">"' +
        esc(p) +
        '"</span>' +
        hdrLine +
        ') <span class="c-kw">do</span> |remote|\n' +
        '  File.<span class="c-fn">open</span>(<span class="c-str">"' +
        esc(fn) +
        '"</span>, <span class="c-str">"wb"</span>) { |f| f.<span class="c-fn">write</span>(remote.<span class="c-fn">read</span>) }\n' +
        '<span class="c-kw">end</span>'
      );
    },
  };

  /*── Helpers ────────────────────────────────────────────────────────────*/
  function buildProxyUrl(remoteUrl, filename, token) {
    var base = window.location.href.split("?")[0];
    var p = new URLSearchParams({ url: remoteUrl });
    if (filename) p.set("filename", filename);
    // The token is appended here only so the generated snippet URL is
    // self-contained for copy-paste use. The preferred delivery method for
    // programmatic clients is the X-Token request header (shown in snippets).
    if (token) p.set("token", token);
    return base + "?" + p.toString();
  }

  function guessFilename(urlStr) {
    try {
      var u = new URL(urlStr);
      var base = u.pathname.split("/").filter(Boolean).pop();
      if (base && base.indexOf(".") !== -1) return base;
    } catch (e) {}
    return "download";
  }

  /*── Snippet rendering ──────────────────────────────────────────────────*/
  var activeLang = "curl";

  function renderSnippets(remoteUrl, filename, token) {
    var proxyUrl = buildProxyUrl(remoteUrl, filename, token);
    var fn = filename || guessFilename(remoteUrl);

    var panes = Object.keys(LANGS)
      .map(function (lang) {
        var code = LANGS[lang](proxyUrl, remoteUrl, fn, token || "");
        return (
          '<div class="code-wrap">' +
          '<div class="code-block' +
          (lang === activeLang ? " active" : "") +
          '" data-lang="' +
          lang +
          '">' +
          code +
          "</div>" +
          '<div class="copy-btn-wrap"><button class="btn-sm" data-copy="' +
          lang +
          '">copy</button></div>' +
          "</div>"
        );
      })
      .join("");

    snippetBody.innerHTML = panes;
    tabs.hidden = false;

    tabs.querySelectorAll(".tab").forEach(function (t) {
      t.classList.toggle("active", t.dataset.lang === activeLang);
    });

    statusEl.textContent =
      proxyUrl.length > 60 ? proxyUrl.slice(0, 57) + "…" : proxyUrl;

    snippetBody.querySelectorAll("[data-copy]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var lang = btn.dataset.copy;
        var block = snippetBody.querySelector(
          '.code-block[data-lang="' + lang + '"]',
        );
        var text = block ? block.innerText : "";
        navigator.clipboard.writeText(text).then(function () {
          btn.textContent = "✓ copied";
          btn.classList.add("copied");
          setTimeout(function () {
            btn.textContent = "copy";
            btn.classList.remove("copied");
          }, 2000);
        });
      });
    });
  }

  /*── Tab switching ──────────────────────────────────────────────────────*/
  tabs.addEventListener("click", function (e) {
    var tab = e.target.closest(".tab");
    if (!tab) return;
    activeLang = tab.dataset.lang;
    tabs.querySelectorAll(".tab").forEach(function (t) {
      t.classList.toggle("active", t === tab);
    });
    snippetBody.querySelectorAll(".code-block").forEach(function (b) {
      b.classList.toggle("active", b.dataset.lang === activeLang);
    });
  });

  /*── Live update ────────────────────────────────────────────────────────*/
  var debounceTimer;
  function onUrlChange() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () {
      var url = urlInput.value.trim();
      if (!url.match(/^https?:\/\/.+/i)) {
        tabs.hidden = true;
        snippetBody.innerHTML =
          '<div class="snippet-placeholder"><span class="arrow">\u2192</span> Paste a URL in the field above to generate fetch commands</div>';
        statusEl.textContent = "enter a URL above \u2191";
        return;
      }
      var tok = tokenInput ? tokenInput.value.trim() : "";
      renderSnippets(url, fnInput.value.trim(), tok);
    }, 300);
  }

  urlInput.addEventListener("input", onUrlChange);
  fnInput.addEventListener("input", onUrlChange);
  if (tokenInput) tokenInput.addEventListener("input", onUrlChange);

  urlInput.addEventListener("blur", function () {
    if (fnInput.value) return;
    var g = guessFilename(urlInput.value);
    if (g !== "download") fnInput.value = g;
    onUrlChange();
  });

  /*── Form submit ────────────────────────────────────────────────────────*/
  form.addEventListener("submit", function (e) {
    var url = urlInput.value.trim();
    if (!url.match(/^https?:\/\/.+/i)) {
      e.preventDefault();
      urlInput.focus();
      return;
    }
    dlBtn.disabled = true;
    btnText.hidden = true;
    spinner.hidden = false;
    notice.hidden = false;
    setTimeout(function () {
      dlBtn.disabled = false;
      btnText.hidden = false;
      spinner.hidden = true;
    }, 8000);
  });
})();
