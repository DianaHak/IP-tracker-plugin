(function () {
  "use strict";

  // -----------------------
  // Small helpers
  // -----------------------
  function debounce(fn, wait) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  }

  function requireAjax() {
    if (typeof IPG_AJAX === "undefined" || !IPG_AJAX.ajaxUrl || !IPG_AJAX.nonce) {
      console.error(
        "[IPG] IPG_AJAX is missing. admin.js is loaded but wp_localize_script did not run.",
      );
      return false;
    }
    return true;
  }

  // -----------------------
  // Helpers: top loading hint
  // -----------------------
  function ensureTopLoadingUI() {
    const sel = document.getElementById("ipg_top_range");
    if (!sel) return { sel: null, tip: null };

    if (!document.getElementById("ipg-top-loading-style")) {
      const style = document.createElement("style");
      style.id = "ipg-top-loading-style";
      style.textContent = `
        @keyframes ipgBlink { 0%,100% { opacity: .2; } 50% { opacity: 1; } }
        #ipg-top-loading.ipg-blink { animation: ipgBlink 0.9s infinite; }
      `;
      document.head.appendChild(style);
    }

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
  // Notes AJAX (new tab)
  // -----------------------
  async function postNotes(action, payload = {}) {
    if (!requireAjax()) return null;

    const body = new URLSearchParams();
    body.set("action", action);
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
        body: body.toString(),
      });
    } catch (err) {
      console.error("[IPG] Notes fetch failed:", err);
      return null;
    }

    const json = await res.json().catch(() => null);
    if (!json || !json.success) {
      console.error("[IPG] Notes AJAX error:", json?.data || json);
      return null;
    }
    return json.data || null;
  }

  function getNotesWrap() {
    return document.getElementById("ipg-notes-wrap");
  }

  async function loadNotes() {
    const wrap = getNotesWrap();
    if (!wrap) return;

    wrap.style.opacity = "0.6";
    const data = await postNotes("dia_ipg_notes_list");
    wrap.style.opacity = "1";

    if (data && data.html) wrap.innerHTML = data.html;
  }

  async function saveNote(row, ip, comment) {
    const wrap = getNotesWrap();
    if (wrap) wrap.style.opacity = "0.6";

    const data = await postNotes("dia_ipg_notes_save", { row, ip, comment });

    if (wrap) wrap.style.opacity = "1";
    if (data && data.html && wrap) wrap.innerHTML = data.html;
    return !!data;
  }

  async function deleteNote(row) {
    const wrap = getNotesWrap();
    if (wrap) wrap.style.opacity = "0.6";

    const data = await postNotes("dia_ipg_notes_delete", { row });

    if (wrap) wrap.style.opacity = "1";
    if (data && data.html && wrap) wrap.innerHTML = data.html;
    return !!data;
  }

  // -----------------------
  // Table AJAX
  // -----------------------
  async function postTable(payload) {
    if (!requireAjax()) return null;

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
        body: body.toString(),
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
  return key === "top5m" || key === "top1h" || key === "top24" || key === "top3d" || key === "top7d";
}

  function currentTopRangeKey() {
    const sel = document.getElementById("ipg_top_range");
    const val = sel ? sel.value : "top24";
    return isTopTableKey(val) ? val : "top24";
  }

  function getTopSearchInput() {
    return document.querySelector(".ipg-ip-search");
  }

  function getTopSearchValue() {
    const input = getTopSearchInput();
    return input ? (input.value || "").trim() : "";
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
      country: overrides.country ?? (wrap.dataset.country || ""),
      ip_search: overrides.ip_search ?? (wrap.dataset.ipSearch || ""),
    };

    wrap.style.opacity = "0.55";

    const html = await postTable({
      table: state.table,
      page: state.page,
      per_page: state.per_page,
      orderby: state.orderby,
      order: state.order,
      country: state.country || "",
      ip_search: state.ip_search || "",
      ...(state.table === "recent" ? { recent_hours: state.recent_hours } : {}),
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
    const ip_search =
      overrides.ip_search ?? (container.dataset.ipSearch || getTopSearchValue() || "");

    const input = getTopSearchInput();
    if (input) input.value = ip_search;

    setTopLoading(true);
    container.style.opacity = "0.55";

    try {
      const html = await postTable({
        table: tableKey,
        page: overrides.page ?? 1,
        per_page: overrides.per_page ?? 50,
        orderby: overrides.orderby ?? "hits",
        order: overrides.order ?? "DESC",
        country: country || "",
        ip_search: ip_search || "",
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
  const onSearch = debounce((input) => {
    const val = (input.value || "").trim();
    const container = document.getElementById("ipg-top-container");
    if (container) container.dataset.ipSearch = val;

    loadTopRange(currentTopRangeKey(), { ip_search: val, page: 1 });
  }, 350);

  document.addEventListener("input", function (e) {
    const ipInput = e.target.closest(".ipg-ip-search");
    if (!ipInput) return;
    onSearch(ipInput);
  });

  document.addEventListener("click", async function (e) {
    // -----------------------
    // Notes: Save / Delete (row-based)
    // -----------------------
    const noteSave = e.target.closest(".ipg-note-save");
    if (noteSave) {
      e.preventDefault();

      const tr = noteSave.closest("tr[data-note-row]");
      if (!tr) return;

      const ipEl = tr.querySelector(".ipg-note-ip");
      const cEl = tr.querySelector(".ipg-note-comment");
      const msg = tr.querySelector(".ipg-note-msg");

      const ip = (ipEl?.value || "").trim();
      const comment = (cEl?.value || "").trim();

      if (!ip) {
        alert("Enter an IP.");
        return;
      }

      // simple sanitize client-side (server should sanitize too)
      const safeIp = ip.replace(/[^0-9a-fA-F\.\:]/g, "");

      if (msg) {
        msg.style.display = "block";
        msg.textContent = "Saving…";
      }

      const row = tr.dataset.noteRow;
      const ok = await saveNote(row, safeIp, comment);

      if (msg) {
        msg.style.display = "block";
        msg.textContent = ok ? "Saved ✓" : "Save failed.";
        setTimeout(() => (msg.style.display = "none"), 1200);
      }
      return;
    }

    const noteDel = e.target.closest(".ipg-note-delete");
    if (noteDel) {
      e.preventDefault();

      const tr = noteDel.closest("tr[data-note-row]");
      if (!tr) return;

      const ipEl = tr.querySelector(".ipg-note-ip");
      const cEl = tr.querySelector(".ipg-note-comment");
      const msg = tr.querySelector(".ipg-note-msg");

      const ip = ((ipEl?.value || "").trim() || "").replace(/[^0-9a-fA-F\.\:]/g, "");

      // clear UI immediately
      if (ipEl) ipEl.value = "";
      if (cEl) cEl.value = "";

      if (msg) {
        msg.style.display = "block";
        msg.textContent = "Deleting…";
      }

      const row = tr.dataset.noteRow;
      const ok = await deleteNote(row);

      if (msg) {
        msg.style.display = "block";
        msg.textContent = ok ? "Deleted ✓" : "Delete failed.";
        setTimeout(() => (msg.style.display = "none"), 1200);
      }
      return;
    }

    // -----------------------
    // Refresh button
    // -----------------------
    const refreshBtn = e.target.closest(".ipg-refresh");
    if (refreshBtn) {
      e.preventDefault();

      const table = refreshBtn.dataset.table || "";

      // Refresh current top range
      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? container.dataset.country || "" : "";
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
        // Select all visible rows
    const selectAll = e.target.closest(".ipg-select-all-page");
    if (selectAll) {
      const wrap = e.target.closest(".ipg-table-wrap");
      if (!wrap) return;

      const boxes = wrap.querySelectorAll(".ipg-row-select");
      boxes.forEach((box) => {
        box.checked = selectAll.checked;
      });
      return;
    }

    // Bulk apply
    const bulkApply = e.target.closest("#ipg-bulk-apply");
    if (bulkApply) {
      e.preventDefault();

      const actionSel = document.getElementById("ipg-bulk-action");
      const action = actionSel ? actionSel.value : "";

      if (!action) {
        alert("Please choose a bulk action.");
        return;
      }

      let ips = [];

      if (action !== "unblock_all") {
        document.querySelectorAll(".ipg-row-select:checked").forEach((el) => {
          if (el.value) ips.push(el.value);
        });

        if (!ips.length) {
          alert("Please select at least one IP.");
          return;
        }
      }

      const body = new URLSearchParams();
      body.set("action", "dia_ipg_bulk_action");
      body.set("nonce", IPG_AJAX.nonce);
      body.set("bulk_action", action);
      ips.forEach((ip) => body.append("ips[]", ip));

      fetch(IPG_AJAX.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
      })
        .then((res) => res.json())
        .then((json) => {
          if (!json || !json.success) {
            alert((json && json.data && json.data.message) ? json.data.message : "Bulk action failed.");
            return;
          }

          // refresh current top table after bulk action
          const container = document.getElementById("ipg-top-container");
          const country = container ? (container.dataset.country || "") : "";
          loadTopRange(currentTopRangeKey(), {
            country,
            ip_search: getTopSearchValue(),
            page: 1
          });
        })
        .catch((err) => {
          console.error("[IPG] Bulk action failed:", err);
          alert("Bulk action failed.");
        });

      return;
    }

    // -----------------------
    // Export CSV / PDF (top tables)
    // -----------------------
    const exportCsv = e.target.closest(".ipg-export-csv");
    if (exportCsv) {
      e.preventDefault();

      if (!requireAjax()) return;

      const container = document.getElementById("ipg-top-container");
      const table = currentTopRangeKey();
      const wrap = container
        ? container.querySelector(`.ipg-table-wrap[data-ipg-table="${table}"]`)
        : null;

      const country = container ? container.dataset.country || "" : "";
      const orderby = wrap ? wrap.dataset.orderby || "hits" : "hits";
      const order = wrap ? wrap.dataset.order || "DESC" : "DESC";

      const url =
        IPG_AJAX.ajaxUrl +
        "?action=dia_ipg_export_csv" +
        "&nonce=" +
        encodeURIComponent(IPG_AJAX.nonce) +
        "&table=" +
        encodeURIComponent(table) +
        "&country=" +
        encodeURIComponent(country || "") +
        "&orderby=" +
        encodeURIComponent(orderby) +
        "&order=" +
        encodeURIComponent(order);

      window.location.href = url;
      return;
    }

    const exportPdf = e.target.closest(".ipg-export-pdf");
    if (exportPdf) {
      e.preventDefault();

      if (!requireAjax()) return;

      const container = document.getElementById("ipg-top-container");
      const table = currentTopRangeKey();
      const wrap = container
        ? container.querySelector(`.ipg-table-wrap[data-ipg-table="${table}"]`)
        : null;

      const country = container ? container.dataset.country || "" : "";
      const orderby = wrap ? wrap.dataset.orderby || "hits" : "hits";
      const order = wrap ? wrap.dataset.order || "DESC" : "DESC";

      const url =
        IPG_AJAX.ajaxUrl +
        "?action=dia_ipg_export_print" +
        "&nonce=" +
        encodeURIComponent(IPG_AJAX.nonce) +
        "&table=" +
        encodeURIComponent(table) +
        "&country=" +
        encodeURIComponent(country || "") +
        "&orderby=" +
        encodeURIComponent(orderby) +
        "&order=" +
        encodeURIComponent(order);

      window.open(url, "_blank", "noopener,noreferrer");
      return;
    }

    // -----------------------
    // Sort
    // -----------------------
    const sort = e.target.closest(".ipg-sort");
    if (sort) {
      e.preventDefault();

      const table = sort.dataset.table || "";

      // Top tables must refresh via container swap
      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? container.dataset.country || "" : "";
        loadTopRange(currentTopRangeKey(), {
          page: 1,
          orderby: sort.dataset.orderby,
          order: sort.dataset.order,
          country,
          ip_search: getTopSearchValue(),
        });
        return;
      }

      loadTable(table, {
        page: 1,
        orderby: sort.dataset.orderby,
        order: sort.dataset.order,
      });
      return;
    }

    // -----------------------
    // Pagination
    // -----------------------
    const pageBtn = e.target.closest(".ipg-page");
    if (pageBtn) {
      e.preventDefault();
      if (pageBtn.classList.contains("disabled")) return;

      const table = pageBtn.dataset.table || "";
      const page = parseInt(pageBtn.dataset.page || "1", 10);

      // Top tables pagination via container swap
      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? container.dataset.country || "" : "";
        loadTopRange(currentTopRangeKey(), {
          page,
          country,
          ip_search: getTopSearchValue(),
        });
        return;
      }

      loadTable(table, { page });
      return;
    }
  });

  document.addEventListener("change", function (e) {

        const rowBox = e.target.closest(".ipg-row-select");
    if (rowBox) {
      const wrap = rowBox.closest(".ipg-table-wrap");
      if (!wrap) return;

      const all = wrap.querySelector(".ipg-select-all-page");
      const boxes = [...wrap.querySelectorAll(".ipg-row-select")];

      if (all) {
        all.checked = boxes.length > 0 && boxes.every((b) => b.checked);
      }
      return;
    }
    // Rows per page
    const sel = e.target.closest(".ipg-per-page");
    if (sel) {
      const table = sel.dataset.table || "";
      const perPage = parseInt(sel.value || "50", 10);

      if (isTopTableKey(table)) {
        const container = document.getElementById("ipg-top-container");
        const country = container ? container.dataset.country || "" : "";
        loadTopRange(currentTopRangeKey(), {
          per_page: perPage,
          page: 1,
          country,
          ip_search: getTopSearchValue(),
        });
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
        loadTopRange(currentTopRangeKey(), {
          country,
          ip_search: getTopSearchValue(),
          page: 1,
        });
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
        const country = container ? container.dataset.country || "" : "";
        loadTopRange(val, { country, ip_search: getTopSearchValue(), page: 1 });
      } else {
        console.warn("[IPG] Unknown top range:", val);
      }
    }
  });

  // -----------------------
  // Initial loads
  // -----------------------
  document.addEventListener("DOMContentLoaded", function () {
    ensureTopLoadingUI();

    // Initial top table via AJAX
    const container = document.getElementById("ipg-top-container");
    if (container) {
      const country = container.dataset.country || "";
      loadTopRange(currentTopRangeKey(), {
        country,
        ip_search: getTopSearchValue(),
      });
    }

    // Notes tab: if wrap exists, load it
    if (getNotesWrap()) {
      loadNotes();
    }
  });
})();