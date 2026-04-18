(function () {
  "use strict";

  /* ── Sparkline ─────────────────────────────────────────────────────────── */
  const canvas = document.getElementById("sparkline");
  if (canvas) {
    const values = JSON.parse(canvas.dataset.values || "[]");
    const ctx = canvas.getContext("2d");
    const W = canvas.offsetWidth || 800;
    const H = canvas.offsetHeight || 60;
    canvas.width = W;
    canvas.height = H;

    if (values.length > 1) {
      const step = W / (values.length - 1);
      const color = (v) =>
        v >= 500 ? "#f85149" : v >= 400 ? "#e3b341" : "#3fb950";

      ctx.lineWidth = 1.5;
      ctx.strokeStyle = "#58a6ff";
      ctx.beginPath();

      // Map HTTP status to a 0–1 band: 2xx=top, 3xx=upper-mid, 4xx=lower-mid, 5xx=bottom
      const statusBand = (v) => {
        if (v >= 500) return 0.95;
        if (v >= 400) return 0.65;
        if (v >= 300) return 0.35;
        return 0.05; // 2xx
      };

      values.forEach((v, i) => {
        const x = i * step;
        const y = H - H * 0.15 - (1 - statusBand(v)) * (H * 0.7);
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
      });
      ctx.stroke();

      // Dots coloured by status class
      values.forEach((v, i) => {
        const x = i * step;
        const y = H - H * 0.15 - (1 - statusBand(v)) * (H * 0.7);
        ctx.beginPath();
        ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fillStyle = color(v);
        ctx.fill();
      });
    }
  }

  /* ── Auto-refresh metrics every 30s on dashboard only ─────────────────── */
  if (window.location.search.includes("tab=dashboard")) {
    setTimeout(() => window.location.reload(), 30000);
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
