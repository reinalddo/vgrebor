
  (() => {
    const enabled = true;
    if (!enabled) {
      return;
    }

    const endpoint = "/api/recharge_notifications.php";
    const container = document.getElementById("live-recharge-notifications");
    if (!endpoint || !container) {
      return;
    }

    const queue = [];
    const queued = new Set();
    const storageKey = `vg-live-recharge-seen:${endpoint}`;
    const storageLimit = 60;
    let cursor = null;
    let active = false;
    let logoPath = "";

    const loadSeenIds = () => {
      try {
        const raw = window.sessionStorage.getItem(storageKey);
        if (!raw) {
          return [];
        }

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
          return [];
        }

        return parsed
          .map((value) => Number(value))
          .filter((value, index, values) => Number.isInteger(value) && value > 0 && values.indexOf(value) === index)
          .slice(-storageLimit);
      } catch (error) {
        return [];
      }
    };

    const seen = new Set(loadSeenIds());

    const persistSeenIds = () => {
      try {
        window.sessionStorage.setItem(storageKey, JSON.stringify(Array.from(seen).slice(-storageLimit)));
      } catch (error) {
      }
    };

    const markSeen = (id) => {
      if (!Number.isInteger(id) || id <= 0 || seen.has(id)) {
        return;
      }
      seen.add(id);
      persistSeenIds();
    };

    const escapeHtml = (value) => String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");

    const showNext = () => {
      if (active || queue.length === 0) {
        return;
      }

      const item = queue.shift();
      if (!item) {
        return;
      }

      const itemId = Number(item && item.id ? item.id : 0);
      if (itemId > 0) {
        queued.delete(itemId);
        markSeen(itemId);
      }

      active = true;
      const article = document.createElement("article");
      article.className = "live-recharge-toast";
      article.innerHTML = `
        <div class="live-recharge-toast__pulse" aria-hidden="true"></div>
        ${logoPath ? `<div class="live-recharge-toast__logo-wrap"><img src="${escapeHtml(logoPath)}" alt="Logo" class="live-recharge-toast__logo"></div>` : ""}
        <div class="live-recharge-toast__body">
          <div class="live-recharge-toast__title">${escapeHtml(item.title || "Nueva recarga")}</div>
          <div class="live-recharge-toast__detail">${escapeHtml(item.detail || "")}</div>
        </div>`;

      container.appendChild(article);
      requestAnimationFrame(() => {
        article.classList.add("is-visible");
      });

      window.setTimeout(() => {
        article.classList.remove("is-visible");
        article.classList.add("is-leaving");
        window.setTimeout(() => {
          article.remove();
          active = false;
          showNext();
        }, 320);
      }, 5000);
    };

    const enqueue = (items) => {
      items.forEach((item) => {
        const id = Number(item && item.id ? item.id : 0);
        if (!id || seen.has(id) || queued.has(id)) {
          return;
        }
        queued.add(id);
        queue.push(item);
      });
      showNext();
    };

    const poll = async (initial = false) => {
      try {
        const url = cursor === null
          ? endpoint
          : `${endpoint}${endpoint.includes("?") ? "&" : "?"}cursor=${encodeURIComponent(String(cursor))}`;
        const response = await fetch(url, {
          credentials: "same-origin",
          headers: { "Accept": "application/json" },
          cache: "no-store",
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          return;
        }
        if (typeof data.logo_path === "string" && data.logo_path.trim() !== "") {
          logoPath = data.logo_path.trim();
        }
        if (typeof data.cursor === "number") {
          cursor = data.cursor;
        }
        if (Array.isArray(data.notifications) && data.notifications.length > 0) {
          enqueue(data.notifications);
        }
      } catch (error) {
      }
    };

    poll(true);
    window.setInterval(() => {
      poll(false);
    }, 10000);

    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) {
        poll(false);
      }
    });
  })();

