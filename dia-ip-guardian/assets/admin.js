(function () {
  // -----------------------
  // Helpers: loading hint
  // -----------------------
  function ensureTopLoadingUI() {
    const sel = document.getElementById("ipg_top_range");
    if (!sel) return { sel: null, tip: null };

    // Ensure CSS exists
    if (!document.getElementById("ipg-top-loading-style")) {
      const style = document.createElement("style");
      style.id = "ipg-top-loading-style";
      style.textContent = `
        @keyframes ipgBlink { 0%,100% { opacity: .2; } 50% { opacity: 1; } }
        #ipg-top-loading.ipg-blink { animation: ipgBlink 0.9s infinite; }
      `;
      document.head.appendChild(style);
    }

    // Ensure tip exists
    let tip = document.getElementById("ipg-top-loading");
    if (!tip) {
      tip = document.createElement("span");
      tip.id = "ipg-top-loading";
      tip.textContent = "Loading… this may take a few moments";
      tip.style.display = "none";
      tip.style.marginLeft = "10px";
      tip.style.fontSize = "12px";
      tip.style.opacity = "0.85";
      sel.insertAdjacentElement("afterend", tip);
    }

    return { sel, tip };
  }

  function setTopLoading(isLoading) {
    const { sel, tip } = ensureTopLoadingUI();
    if (!sel || !tip) return;

    if (isLoading) {
      tip.style.display = "inline";
      tip.classList.add("ipg-blink");
      sel.disabled = true;
    } else {
      tip.classList.remove("ipg-blink");
      tip.style.display = "none";
      sel.disabled = false;
    }
  }

  // -----------------------
  // AJAX
  // -----------------------
  async function postTable(payload) {
    if (typeof IPG_AJAX === "undefined" || !IPG_AJAX.ajaxUrl || !IPG_AJAX.nonce) {
      console.error("[IPG] IPG_AJAX is missing. admin.js is loaded but wp_localize_script did not run.");
      return null;
    }

    const body = new URLSearchParams();
    body.set("action", "dia_ipg_table");
    body.set("nonce", IPG_AJAX.nonce);

    Object.keys(payload).forEach((k) => {
      if (payload[k] !== undefined && payload[k] !== null) {
        body.set(k, String(payload[k]));
      }
    });

    let res;
    try {
      res = await fetch(IPG_AJAX.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString()
      });
    } catch (err) {
      console.error("[IPG] Fetch failed:", err);
      return null;
    }

    const json = await res.json().catch(() => null);
    if (!json) {
      console.error("[IPG] Bad JSON response.");
      return null;
    }

    if (!json.success) {
      console.error("[IPG] AJAX error:", json.data || json);
      return null;
    }

    if (!json.data || !json.data.html) {
      console.error("[IPG] Missing html in response:", json);
      return null;
    }

    return json.data.html;
  }

  function getWrap(tableKey) {
    return document.querySelector(`.ipg-table-wrap[data-ipg-table="${tableKey}"]`);
  }

  function isTopTableKey(key) {
    return key === "top24" || key === "top3d" || key === "top7d";
  }

  function currentTopRangeKey() {
    const sel = document.getElementById("ipg_top_range");
    const val = sel ? sel.value : "top24";
    return isTopTableKey(val) ? val : "top24";
  }

  // -----------------------
  // Load single table wrap
  // -----------------------
  async function loadTable(tableKey, overrides = {}) {
    const wrap = getWrap(tableKey);
    if (!wrap) return;

    const state = {
      table: tableKey,
      page: overrides.page ?? parseInt(wrap.dataset.page || "1", 10),
      per_page: overrides.per_page ?? parseInt(wrap.dataset.perPage || "50", 10),
      orderby: overrides.orderby ?? (wrap.dataset.orderby || ""),
      order: overrides.order ?? (wrap.dataset.order || "DESC"),
      recent_hours: parseInt(wrap.dataset.recentHours || "24", 10),
      country: overrides.country ?? (wrap.dataset.country || "")
    };

    wrap.style.opacity = "0.55";

    const html = await postTable({
      table: state.table,
      page: state.page,
      per_page: state.per_page,
      orderby: state.orderby,
      order: state.order,
      country: state.country || "",
      ...(state.table === "recent" ? { recent_hours: state.recent_hours } : {})
    });

    wrap.style.opacity = "1";
    if (!html) return;

    wrap.outerHTML = html;
  }

  // -----------------------
  // Load top range (container swap)
  // -----------------------
  let topRangeLoading = false;

  async function loadTopRange(tableKey, overrides = {}) {
    const container = document.getElementById("ipg-top-container");
    if (!container) return;

    if (topRangeLoading) return;
    topRangeLoading = true;

    const country = overrides.country ?? (container.dataset.country || "");

    setTopLoading(true);
    container.style.opacity = "0.55";

    try {
      const html = await postTable({
        table: tableKey,
        page: overrides.page ?? 1,
        per_page: overrides.per_page ?? 50,
        orderby: overrides.orderby ?? "hits",
        order: overrides.order ?? "DESC",
        country: country || ""
      });

      container.style.opacity = "1";
      if (!html) return;

      container.innerHTML = html;

      // keep selected country in UI
      const sel = container.querySelector('.ipg-country-filter[data-table="' + tableKey + '"]');
      if (sel) sel.value = country || "";
    } finally {
      setTopLoading(false);
      topRangeLoading = false;
    }
  }

  // -----------------------
  // Events
  // -----------------------
  document.addEventListener("click", function (e) {
    // ✅ Refresh button
    const refreshBtn = e.target.closest(".ipg-refresh");
    if (refreshBtn) {
      e.preventDefault();

      const table = refreshBtn.dataset.table || "";

      // Refresh current top range
      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? (container.dataset.country || "") : "";
        loadTopRange(currentTopRangeKey(), { country });
        return;
      }

      // Refresh normal table
      const wrap = table ? getWrap(table) : null;
      const msg = wrap ? wrap.querySelector(".ipg-refresh-msg") : null;
      if (msg) msg.style.display = "inline";

      loadTable(table).finally(() => {
        if (msg) msg.style.display = "none";
      });

      return;
    }

    // Sort
    const sort = e.target.closest(".ipg-sort");
    if (sort) {
      e.preventDefault();

      const table = sort.dataset.table || "";

      // ✅ Top tables must refresh via container swap
      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? (container.dataset.country || "") : "";
        loadTopRange(currentTopRangeKey(), {
          page: 1,
          orderby: sort.dataset.orderby,
          order: sort.dataset.order,
          country
        });
        return;
      }

      // Normal tables
      loadTable(table, {
        page: 1,
        orderby: sort.dataset.orderby,
        order: sort.dataset.order
      });
      return;
    }

    // Pagination
    const pageBtn = e.target.closest(".ipg-page");
    if (pageBtn) {
      e.preventDefault();
      if (pageBtn.classList.contains("disabled")) return;

      const table = pageBtn.dataset.table || "";
      const page = parseInt(pageBtn.dataset.page || "1", 10);

      // ✅ Top tables pagination via container swap
      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? (container.dataset.country || "") : "";
        loadTopRange(currentTopRangeKey(), { page, country });
        return;
      }

      loadTable(table, { page });
      return;
    }
  });

  document.addEventListener("change", function (e) {
    // Rows per page
    const sel = e.target.closest(".ipg-per-page");
    if (sel) {
      const table = sel.dataset.table || "";
      const perPage = parseInt(sel.value || "50", 10);

      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? (container.dataset.country || "") : "";
        loadTopRange(currentTopRangeKey(), { per_page: perPage, page: 1, country });
        return;
      }

      loadTable(table, { page: 1, per_page: perPage });
      return;
    }

    // Country filter
    const csel = e.target.closest(".ipg-country-filter");
    if (csel) {
      const table = csel.dataset.table || "";
      const country = (csel.value || "").toUpperCase();

      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        if (container) container.dataset.country = country;
        loadTopRange(currentTopRangeKey(), { country, page: 1 });
        return;
      }

      loadTable(table, { page: 1, country });
      return;
    }

    // Top range dropdown
    const rangeSel = e.target.closest("#ipg_top_range");
    if (rangeSel) {
      const val = rangeSel.value;

      if (isTopTableKey(val)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? (container.dataset.country || "") : "";
        loadTopRange(val, { country, page: 1 });
      } else {
        console.warn("[IPG] Unknown top range:", val);
      }
    }
  });

  // ✅ Important: on page load, fetch initial top table via AJAX
  document.addEventListener("DOMContentLoaded", function () {
    ensureTopLoadingUI();

    const container = document.getElementById("ipg-top-container");
    if (container) {
      const country = container.dataset.country || "";
      loadTopRange(currentTopRangeKey(), { country });
    }
  });
})();