(function () {
  "use strict";

  /* ── Toggle switches — sync .is-checked class so CSS works ─────────────── */
  document.querySelectorAll(".toggle-label").forEach((label) => {
    const cb = label.querySelector(".toggle-input");
    if (!cb) return;
    const sync = () => label.classList.toggle("is-checked", cb.checked);
    sync();
    cb.addEventListener("change", sync);
  });

  /* ── Sparkline ─────────────────────────────────────────────────────────── */
  function drawSparkline(canvas) {
    const values = JSON.parse(canvas.dataset.values || "[]");
    if (values.length < 2) return;

    const ctx = canvas.getContext("2d");
    const W = canvas.clientWidth || canvas.parentElement.clientWidth || 800;
    const H = 60;
    canvas.width = W;
    canvas.height = H;

    const step = W / (values.length - 1);
    const color = (v) =>
      v >= 500 ? "#f85149" : v >= 400 ? "#e3b341" : "#3fb950";

    // Map HTTP status to a 0–1 band: 2xx=top, 3xx=upper-mid, 4xx=lower-mid, 5xx=bottom
    const statusBand = (v) => {
      if (v >= 500) return 0.95;
      if (v >= 400) return 0.65;
      if (v >= 300) return 0.35;
      return 0.05;
    };

    const yOf = (v) => H - H * 0.15 - (1 - statusBand(v)) * (H * 0.7);

    ctx.lineWidth = 1.5;
    for (let i = 0; i < values.length - 1; i++) {
      ctx.beginPath();
      ctx.strokeStyle = color(values[i]);
      ctx.moveTo(i * step, yOf(values[i]));
      ctx.lineTo((i + 1) * step, yOf(values[i + 1]));
      ctx.stroke();
    }

    values.forEach((v, i) => {
      ctx.beginPath();
      ctx.arc(i * step, yOf(v), 3, 0, Math.PI * 2);
      ctx.fillStyle = color(v);
      ctx.fill();
    });
  }

  const sparkCanvas = document.getElementById("sparkline");
  const sparkEmpty = document.getElementById("sparkline-empty");
  if (sparkCanvas) {
    const values = JSON.parse(sparkCanvas.dataset.values || "[]");
    if (values.length < 2) {
      // Not enough data — hide canvas, show empty state
      sparkCanvas.style.display = "none";
      if (sparkEmpty) sparkEmpty.style.display = "flex";
    } else {
      if (sparkEmpty) sparkEmpty.style.display = "none";
      // Draw after layout is complete so clientWidth is available
      requestAnimationFrame(() => drawSparkline(sparkCanvas));

      // Redraw on container resize
      if (typeof ResizeObserver !== "undefined") {
        new ResizeObserver(() => drawSparkline(sparkCanvas)).observe(sparkCanvas);
      }
    }
  }

  /* ── Soft metrics refresh on dashboard (no full page reload) ───────────── */
  if (window.location.search.includes("tab=dashboard")) {
    let refreshTimer;

    function scheduleRefresh() {
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(() => {
        fetch(window.location.href, { credentials: "same-origin" })
          .then((r) => r.text())
          .then((html) => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, "text/html");

            // Replace metric cards
            const newGrid = doc.querySelector(".admin-metrics-grid");
            const curGrid = document.querySelector(".admin-metrics-grid");
            if (newGrid && curGrid) curGrid.replaceWith(newGrid);

            // Replace sparkline data and redraw
            const newCanvas = doc.getElementById("sparkline");
            if (newCanvas && sparkCanvas) {
              sparkCanvas.dataset.values = newCanvas.dataset.values;
              drawSparkline(sparkCanvas);
            }

            scheduleRefresh();
          })
          .catch(() => scheduleRefresh()); // retry silently on network error
      }, 30000);
    }

    scheduleRefresh();
  }

  /* ── Confirm dangerous actions ─────────────────────────────────────────── */
  document.querySelectorAll("[data-confirm]").forEach((el) => {
    el.addEventListener("click", (e) => {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });

  /* ── Token value reveal on click ───────────────────────────────────────── */
  document.querySelectorAll(".admin-token-val").forEach((el) => {
    const full = el.textContent;
    el.textContent = full.slice(0, 8) + "••••••••";
    el.style.cursor = "pointer";
    el.title = "Click to reveal";
    let revealed = false;
    el.addEventListener("click", () => {
      revealed = !revealed;
      el.textContent = revealed ? full : full.slice(0, 8) + "••••••••";
    });
  });
})();
