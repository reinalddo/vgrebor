
  const menuToggle = document.getElementById("menu-toggle");
  const menuOverlay = document.getElementById("menu-overlay");
  const menuPanel = document.getElementById("menu-panel");
  const menuClose = document.getElementById("menu-close");
  const authContainer = document.getElementById("auth-container");
  const authTrigger = document.getElementById("auth-trigger");
  const authMenu = document.getElementById("auth-menu");
  const userTrigger = document.getElementById("user-trigger");
  const userMenu = document.getElementById("user-menu");
  const authModal = document.getElementById("auth-modal");
  const authLogin = document.getElementById("auth-login");
  const authRegister = document.getElementById("auth-register");
  const authInitialMode = authModal ? (authModal.dataset.authInitialMode || "").trim() : "";
  const passwordToggles = document.querySelectorAll("[data-password-toggle]");
  const userOrdersModal = document.getElementById("user-orders-modal");
  const userRewardsModal = document.getElementById("user-rewards-modal");
  const userProfileModal = document.getElementById("user-profile-modal");
  const userOrdersList = document.getElementById("user-orders-list");
  const userOrdersTableBody = document.getElementById("user-orders-table-body");
  const userOrdersCards = document.getElementById("user-orders-cards");
  const userOrdersLoading = document.getElementById("user-orders-loading");
  const userOrdersEmpty = document.getElementById("user-orders-empty");
  const userOrdersFeedback = document.getElementById("user-orders-feedback");
  const userRewardsFeedback = document.getElementById("user-rewards-feedback");
  const userRewardsLoading = document.getElementById("user-rewards-loading");
  const userRewardsContent = document.getElementById("user-rewards-content");
  const userRewardsEmpty = document.getElementById("user-rewards-empty");
  const userRewardsTransactionsList = document.getElementById("user-rewards-transactions-list");
  const userRewardsTableBody = document.getElementById("user-rewards-table-body");
  const userRewardsCards = document.getElementById("user-rewards-cards");
  const userRewardsModalTitle = document.getElementById("user-rewards-modal-title");
  const userRewardsBalanceValue = document.getElementById("user-rewards-balance-value");
  const userRewardsExpirationValue = document.getElementById("user-rewards-expiration-value");
  const userRewardsEarnedValue = document.getElementById("user-rewards-earned-value");
  const userRewardsSpentValue = document.getElementById("user-rewards-spent-value");
  const userRewardsTransactionsValue = document.getElementById("user-rewards-transactions-value");
  const userProfileForm = document.getElementById("user-profile-form");
  const userProfileFeedback = document.getElementById("user-profile-feedback");
  const userProfileImageInput = document.getElementById("user-profile-image");
  const userProfilePreviewImage = document.getElementById("user-profile-preview-image");
  const userProfilePreviewFallback = document.getElementById("user-profile-preview-fallback");
  const userTriggerName = document.getElementById("user-trigger-name");
  const userTriggerInitials = document.getElementById("user-trigger-initials");
  const userTriggerInitialsText = document.getElementById("user-trigger-initials-text");
  const userTriggerAvatar = document.getElementById("user-trigger-avatar");
  const userMenuName = document.getElementById("user-menu-name");
  const userMenuEmail = document.getElementById("user-menu-email");
  const userMenuRewardsName = document.getElementById("user-menu-rewards-name");
  const userMenuRewardsBalance = document.getElementById("user-menu-rewards-balance");
  const userMenuRewardsExpiration = document.getElementById("user-menu-rewards-expiration");

  const showElement = (element, displayClass) => {
    if (!element) {
      return;
    }
    element.classList.remove("d-none");
    if (displayClass) {
      element.classList.add(displayClass);
    }
  };

  const hideElement = (element, displayClass) => {
    if (!element) {
      return;
    }
    element.classList.add("d-none");
    if (displayClass) {
      element.classList.remove(displayClass);
    }
  };

  const openMenu = () => {
    showElement(menuOverlay);
    showElement(menuPanel);
  };

  const closeMenu = () => {
    hideElement(menuOverlay);
    hideElement(menuPanel);
  };

  if (menuToggle) {
    menuToggle.addEventListener("click", openMenu);
  }
  if (menuClose) {
    menuClose.addEventListener("click", closeMenu);
  }
  if (menuOverlay) {
    menuOverlay.addEventListener("click", closeMenu);
  }

  const showAuthMenu = () => {
    showElement(authMenu);
  };

  const hideAuthMenu = () => {
    hideElement(authMenu);
  };

  const showUserMenu = () => {
    showElement(userMenu);
  };

  const hideUserMenu = () => {
    hideElement(userMenu);
  };

  const openUserModal = (modal) => {
    showElement(modal, "d-flex");
  };

  const closeUserModal = (modal) => {
    hideElement(modal, "d-flex");
  };

  const closeAllUserModals = () => {
    closeUserModal(userOrdersModal);
    closeUserModal(userRewardsModal);
    closeUserModal(userProfileModal);
  };

  const showFeedback = (element, message, variant) => {
    if (!element) {
      return;
    }
    element.textContent = message;
    element.className = `alert mb-3 py-2 alert-${variant}`;
    element.classList.remove("d-none");
  };

  const hideFeedback = (element) => {
    if (!element) {
      return;
    }
    element.classList.add("d-none");
    element.textContent = "";
  };

  const escapeHtml = (value) => {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  };

  const statusLabel = (status) => {
    const labels = {
      pendiente: "Pendiente",
      pagado: "Pagado",
      enviado: "Enviado",
      cancelado: "Cancelado",
    };
    return labels[status] || status || "Pendiente";
  };

  const rewardsTypeLabel = (type) => {
    const labels = {
      earn: "Ganado",
      redeem: "Canjeado",
      award_reversal: "Reverso de premio",
      redeem_refund: "Reembolso de canje",
      admin_adjustment: "Ajuste manual",
      expiration: "Vencimiento",
    };
    return labels[type] || type || "Movimiento";
  };

  const rewardsExpirationText = (summary, includeDate = false) => {
    const status = String(summary?.expiration_status || "").trim();
    const daysLabel = String(summary?.days_remaining_label || "").trim();
    const expiresLabel = String(summary?.expires_at_label || "").trim();
    if (status === "expired") {
      return includeDate && expiresLabel && expiresLabel !== "Sin saldo" ? `Vencidos | ${expiresLabel}` : "Vencidos";
    }
    if ((status === "active" || status === "warning") && daysLabel !== "") {
      return includeDate && expiresLabel && expiresLabel !== "Sin saldo"
        ? `Vence en ${daysLabel} | ${expiresLabel}`
        : `Vence en ${daysLabel}`;
    }
    return daysLabel || "Sin saldo";
  };

  const formatPointsNumber = (value) => {
    const numericValue = Number(value || 0);
    return Number.isFinite(numericValue)
      ? new Intl.NumberFormat("es-VE").format(numericValue)
      : "0";
  };

  const getInitials = (name, email) => {
    const source = (name || email || "US").trim();
    if (!source) {
      return "US";
    }
    const parts = source.split(/\s+/).filter(Boolean);
    const initials = parts.slice(0, 2).map((part) => part.charAt(0)).join("");
    return (initials || source.slice(0, 2) || "US").toUpperCase();
  };

  const setAvatarDisplay = (imageElement, fallbackElement, imageUrl, name, email) => {
    const initials = getInitials(name || "", email || "");
    if (fallbackElement) {
      fallbackElement.textContent = initials;
      fallbackElement.classList.toggle("d-none", Boolean(imageUrl));
    }
    if (imageElement) {
      if (imageUrl) {
        imageElement.src = imageUrl;
        imageElement.classList.remove("d-none");
      } else {
        imageElement.removeAttribute("src");
        imageElement.classList.add("d-none");
      }
    }
    if (!imageElement && fallbackElement && userTriggerInitials) {
      userTriggerInitials.setAttribute("aria-label", `Iniciales de ${name || email || "usuario"}`);
    }
  };

  const applyPersistedUserAvatar = (imageUrl, name, email) => {
    setAvatarDisplay(userTriggerAvatar, userTriggerInitialsText, imageUrl, name, email);
    setAvatarDisplay(userProfilePreviewImage, userProfilePreviewFallback, imageUrl, name, email);
    if (userProfileForm) {
      userProfileForm.dataset.profileImageUrl = imageUrl || "";
    }
  };

  const renderOrderCard = (order) => {
    const amount = order.paquete_cantidad ? ` <span class="text-secondary">(${escapeHtml(order.paquete_cantidad)})</span>` : "";
    return `
      <article class="rounded-4 border border-info p-3" style="background:rgba(8,15,24,0.78);box-shadow:0 0 16px rgba(34,211,238,0.08);">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
          <div>
            <div class="small text-uppercase text-info" style="letter-spacing:0.14em;">Pedido #${escapeHtml(order.id)}</div>
            <h4 class="h6 mb-0 text-white">${escapeHtml(order.juego_nombre)}</h4>
          </div>
          <span class="badge rounded-pill text-bg-dark border border-info-subtle text-info">${escapeHtml(statusLabel(order.estado))}</span>
        </div>
        <div class="small text-secondary mb-2">${escapeHtml(order.creado_en)}</div>
        <div class="mb-2 text-light"><strong>Paquete:</strong> ${escapeHtml(order.paquete_nombre)}${amount}</div>
        <div class="mb-2 text-light"><strong>Correo:</strong> ${escapeHtml(order.email)}</div>
        <div class="fw-bold text-info fs-5">${escapeHtml(order.moneda)} ${escapeHtml(order.precio)}</div>
      </article>`;
  };

  const renderOrderRow = (order) => {
    const amount = order.paquete_cantidad ? ` (${escapeHtml(order.paquete_cantidad)})` : "";
    return `
      <tr>
        <td class="bg-transparent border-bottom border-info-subtle text-info fw-semibold">#${escapeHtml(order.id)}<div class="small text-secondary fw-normal">${escapeHtml(order.creado_en)}</div></td>
        <td class="bg-transparent border-bottom border-info-subtle text-light fw-semibold">${escapeHtml(order.juego_nombre)}</td>
        <td class="bg-transparent border-bottom border-info-subtle text-light">${escapeHtml(order.paquete_nombre)}<span class="text-secondary">${amount}</span></td>
        <td class="bg-transparent border-bottom border-info-subtle text-light">${escapeHtml(order.email)}</td>
        <td class="bg-transparent border-bottom border-info-subtle"><span class="badge rounded-pill text-bg-dark border border-info-subtle text-info">${escapeHtml(statusLabel(order.estado))}</span></td>
        <td class="bg-transparent border-bottom border-info-subtle text-info fw-bold text-end">${escapeHtml(order.moneda)} ${escapeHtml(order.precio)}</td>
      </tr>`;
  };

  const renderRewardTransactionCard = (transaction) => {
    const delta = Number(transaction.points_delta || 0);
    const deltaClass = delta >= 0 ? "text-success" : "text-warning";
    const orderText = transaction.order_id ? `Pedido #${escapeHtml(transaction.order_id)}` : "Sin pedido asociado";
    return `
      <article class="rounded-4 border border-info p-3" style="background:rgba(8,15,24,0.78);box-shadow:0 0 16px rgba(34,211,238,0.08);">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
          <div>
            <div class="small text-uppercase text-info" style="letter-spacing:0.14em;">${escapeHtml(rewardsTypeLabel(transaction.transaction_type))}</div>
            <div class="small text-secondary">${escapeHtml(transaction.created_at || "")}</div>
          </div>
          <div class="fw-bold ${deltaClass}">${delta >= 0 ? "+" : ""}${escapeHtml(formatPointsNumber(delta))}</div>
        </div>
        <div class="text-light fw-semibold mb-1">${escapeHtml(transaction.description || orderText)}</div>
        <div class="small text-secondary mb-1">${escapeHtml(orderText)}</div>
        <div class="small text-info">Saldo luego del movimiento: ${escapeHtml(formatPointsNumber(transaction.balance_after || 0))}</div>
      </article>`;
  };

  const renderRewardTransactionRow = (transaction) => {
    const delta = Number(transaction.points_delta || 0);
    const deltaClass = delta >= 0 ? "text-success" : "text-warning";
    const detailParts = [transaction.description || ""];
    if (transaction.juego_nombre || transaction.paquete_nombre) {
      detailParts.push(`${transaction.juego_nombre || ""} ${transaction.paquete_nombre || ""}`.trim());
    }
    if (transaction.order_id) {
      detailParts.push(`Pedido #${transaction.order_id}`);
    }
    return `
      <tr>
        <td class="bg-transparent border-bottom border-info-subtle text-secondary">${escapeHtml(transaction.created_at || "")}</td>
        <td class="bg-transparent border-bottom border-info-subtle text-light fw-semibold">${escapeHtml(rewardsTypeLabel(transaction.transaction_type))}</td>
        <td class="bg-transparent border-bottom border-info-subtle text-light">${escapeHtml(detailParts.filter(Boolean).join(" | "))}</td>
        <td class="bg-transparent border-bottom border-info-subtle text-end fw-bold ${deltaClass}">${delta >= 0 ? "+" : ""}${escapeHtml(formatPointsNumber(delta))}</td>
        <td class="bg-transparent border-bottom border-info-subtle text-end text-info fw-bold">${escapeHtml(formatPointsNumber(transaction.balance_after || 0))}</td>
      </tr>`;
  };

  const updateRewardsPresentation = (payload) => {
    if (!payload) {
      return;
    }

    const config = payload.config || {};
    const summary = payload.summary || {};
    const programName = config.name || "Win Points";

    if (userMenuRewardsName) {
      userMenuRewardsName.textContent = programName;
    }
    if (userMenuRewardsBalance) {
      userMenuRewardsBalance.textContent = formatPointsNumber(summary.balance || 0);
    }
    if (userMenuRewardsExpiration) {
      userMenuRewardsExpiration.textContent = rewardsExpirationText(summary, false);
    }
    if (userRewardsModalTitle) {
      userRewardsModalTitle.textContent = `Mis ${programName}`;
    }
    if (userRewardsBalanceValue) {
      userRewardsBalanceValue.textContent = formatPointsNumber(summary.balance || 0);
    }
    if (userRewardsExpirationValue) {
      userRewardsExpirationValue.textContent = rewardsExpirationText(summary, true);
    }
    if (userRewardsEarnedValue) {
      userRewardsEarnedValue.textContent = formatPointsNumber(summary.earned || 0);
    }
    if (userRewardsSpentValue) {
      userRewardsSpentValue.textContent = formatPointsNumber(summary.spent || 0);
    }
    if (userRewardsTransactionsValue) {
      userRewardsTransactionsValue.textContent = formatPointsNumber(summary.transactions || 0);
    }
  };

  const loadUserOrders = async () => {
    if (!userOrdersList || !userOrdersLoading || !userOrdersEmpty || !userOrdersTableBody || !userOrdersCards) {
      return;
    }
    hideFeedback(userOrdersFeedback);
    userOrdersList.innerHTML = "";
    userOrdersList.innerHTML = `
                <div class="table-responsive d-none d-md-block rounded-4 border border-info-subtle overflow-hidden" style="background:rgba(8,15,24,0.82);">
                  <table class="table align-middle mb-0" style="--bs-table-bg:transparent;--bs-table-color:#e5f6ff;">
                    <thead>
                      <tr>
                        <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent">Pedido</th>
                        <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent">Juego</th>
                        <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent">Paquete</th>
                        <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent">Correo</th>
                        <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent">Estado</th>
                        <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent text-end">Total</th>
                      </tr>
                    </thead>
                    <tbody id="user-orders-table-body"></tbody>
                  </table>
                </div>
                <div id="user-orders-cards" class="d-grid d-md-none gap-3"></div>`;
    const tableBody = document.getElementById("user-orders-table-body");
    const cardsContainer = document.getElementById("user-orders-cards");
    hideElement(userOrdersList);
    hideElement(userOrdersEmpty);
    showElement(userOrdersLoading);
    userOrdersLoading.textContent = "Cargando pedidos...";

    try {
      const response = await fetch((window.__TVG_API_ACCOUNT || "/api/account.php") + "?action=orders", {
        credentials: "same-origin",
        headers: { "Accept": "application/json" },
      });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "No se pudo cargar el historial.");
      }

      hideElement(userOrdersLoading);
      if (!Array.isArray(data.orders) || data.orders.length === 0) {
        showElement(userOrdersEmpty);
        return;
      }

      tableBody.innerHTML = data.orders.map(renderOrderRow).join("");
      cardsContainer.innerHTML = data.orders.map(renderOrderCard).join("");
      showElement(userOrdersList);
    } catch (error) {
      hideElement(userOrdersLoading);
      showFeedback(userOrdersFeedback, error.message || "No se pudo cargar el historial.", "danger");
    }
  };

  const loadUserRewards = async () => {
    if (!userRewardsModal || !userRewardsLoading || !userRewardsContent || !userRewardsEmpty || !userRewardsTransactionsList || !userRewardsTableBody || !userRewardsCards) {
      return;
    }

    hideFeedback(userRewardsFeedback);
    hideElement(userRewardsContent);
    hideElement(userRewardsEmpty);
    hideElement(userRewardsTransactionsList);
    showElement(userRewardsLoading);
    userRewardsLoading.textContent = "Cargando saldo y movimientos...";

    try {
      const response = await fetch((window.__TVG_API_ACCOUNT || "/api/account.php") + "?action=rewards", {
        credentials: "same-origin",
        headers: { "Accept": "application/json" },
      });
      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "No se pudo cargar el panel de premios.");
      }

      hideElement(userRewardsLoading);
      updateRewardsPresentation(data);
      showElement(userRewardsContent);

      if (!data.enabled || !Array.isArray(data.transactions) || data.transactions.length === 0) {
        showElement(userRewardsEmpty);
        return;
      }

      userRewardsTableBody.innerHTML = data.transactions.map(renderRewardTransactionRow).join("");
      userRewardsCards.innerHTML = data.transactions.map(renderRewardTransactionCard).join("");
      showElement(userRewardsTransactionsList);
    } catch (error) {
      hideElement(userRewardsLoading);
      showFeedback(userRewardsFeedback, error.message || "No se pudo cargar el panel de premios.", "danger");
    }
  };

  const updateUserPresentation = (user) => {
    if (!user) {
      return;
    }
    if (userTriggerName) {
      userTriggerName.textContent = user.full_name || user.email || "Usuario";
    }
    if (userMenuName) {
      userMenuName.textContent = user.full_name || user.email || "Usuario";
    }
    if (userMenuEmail) {
      userMenuEmail.textContent = user.email || "";
    }
    applyPersistedUserAvatar(user.profile_image_url || "", user.full_name || "", user.email || "");
    const orderEmailField = document.querySelector('#order-form input[name="email"]');
    if (orderEmailField) {
      orderEmailField.value = user.email || "";
    }
  };

  const openAuthModal = (mode) => {
    if (!authModal || !authLogin || !authRegister) return;
    showElement(authModal, "d-flex");
    if (mode === "register") {
      hideElement(authLogin, "d-grid");
      showElement(authRegister, "d-grid");
    } else {
      hideElement(authRegister, "d-grid");
      showElement(authLogin, "d-grid");
    }
  };

  const closeAuthModal = () => {
    if (!authModal) return;
    hideElement(authModal, "d-flex");
  };

  const togglePassword = (inputId, button) => {
    const input = document.getElementById(inputId);
    if (!input) {
      return;
    }
    const showPassword = input.type === "password";
    input.type = showPassword ? "text" : "password";
    if (button) {
      const hiddenIcon = button.querySelector('[data-password-icon="hidden"]');
      const visibleIcon = button.querySelector('[data-password-icon="visible"]');
      if (hiddenIcon) {
        hiddenIcon.classList.toggle("d-none", showPassword);
      }
      if (visibleIcon) {
        visibleIcon.classList.toggle("d-none", !showPassword);
      }
      button.setAttribute("aria-pressed", showPassword ? "true" : "false");
      button.setAttribute("aria-label", showPassword ? (button.dataset.passwordLabelHide || "Ocultar contraseña") : (button.dataset.passwordLabelShow || "Mostrar contraseña"));
    }
  };

  window.openAuthModal = openAuthModal;
  window.togglePassword = togglePassword;

  if (authInitialMode === "login" || authInitialMode === "register") {
    openAuthModal(authInitialMode);
  }

  if (authTrigger && authMenu && authContainer) {
    authTrigger.addEventListener("click", (event) => {
      event.stopPropagation();
      hideUserMenu();
      if (authMenu.classList.contains("d-none")) {
        showAuthMenu();
      } else {
        hideAuthMenu();
      }
    });

    document.addEventListener("click", (event) => {
      if (!authContainer.contains(event.target)) {
        hideAuthMenu();
      }
    });
  }

  if (userTrigger && userMenu && authContainer) {
    userTrigger.addEventListener("click", (event) => {
      event.stopPropagation();
      hideAuthMenu();
      if (userMenu.classList.contains("d-none")) {
        showUserMenu();
      } else {
        hideUserMenu();
      }
    });

    document.addEventListener("click", (event) => {
      if (!authContainer.contains(event.target)) {
        hideUserMenu();
      }
    });
  }

  document.querySelectorAll("[data-auth-open]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      const mode = button.dataset.authOpen;
      hideAuthMenu();
      openAuthModal(mode);
    });
  });

  document.querySelectorAll("[data-auth-close]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      closeAuthModal();
    });
  });

  document.querySelectorAll("[data-auth-switch]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      openAuthModal(button.dataset.authSwitch);
    });
  });

  document.querySelectorAll("[data-user-open]").forEach((button) => {
    button.addEventListener("click", async (event) => {
      event.preventDefault();
      hideUserMenu();
      const target = button.dataset.userOpen;
      if (target === "orders") {
        openUserModal(userOrdersModal);
        await loadUserOrders();
        return;
      }
      if (target === "rewards") {
        openUserModal(userRewardsModal);
        await loadUserRewards();
        return;
      }
      if (target === "profile") {
        hideFeedback(userProfileFeedback);
        setAvatarDisplay(
          userProfilePreviewImage,
          userProfilePreviewFallback,
          (userProfileForm && userProfileForm.dataset.profileImageUrl) || "",
          userProfileForm?.elements?.name?.value || userTriggerName?.textContent || "",
          userProfileForm?.elements?.email?.value || userMenuEmail?.textContent || ""
        );
        openUserModal(userProfileModal);
        return;
      }
    });
  });

  document.querySelectorAll("[data-user-close]").forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      closeAllUserModals();
    });
  });

  passwordToggles.forEach((button) => {
    button.addEventListener("click", (event) => {
      event.preventDefault();
      togglePassword(button.dataset.passwordToggle, button);
    });
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      hideAuthMenu();
      hideUserMenu();
      closeAuthModal();
      closeAllUserModals();
      closeMenu();
    }
  });

  if (userProfileForm) {
    userProfileForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      hideFeedback(userProfileFeedback);
      const submitButton = userProfileForm.querySelector('button[type="submit"]');
      const formData = new FormData(userProfileForm);
      formData.append("action", "update_profile");

      if (submitButton) {
        submitButton.disabled = true;
      }

      try {
        const response = await fetch(window.__TVG_API_ACCOUNT || "/api/account.php", {
          method: "POST",
          body: formData,
          credentials: "same-origin",
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          throw new Error(data.message || "No se pudieron guardar los cambios.");
        }
        updateUserPresentation(data.user || null);
        userProfileForm.reset();
        userProfileForm.elements.name.value = (data.user && data.user.full_name) || "";
        userProfileForm.elements.email.value = (data.user && data.user.email) || "";
        userProfileForm.elements.phone.value = (data.user && data.user.phone) || "";
        applyPersistedUserAvatar((data.user && data.user.profile_image_url) || "", (data.user && data.user.full_name) || "", (data.user && data.user.email) || "");
        showFeedback(userProfileFeedback, data.message || "Datos guardados.", "success");
      } catch (error) {
        showFeedback(userProfileFeedback, error.message || "No se pudieron guardar los cambios.", "danger");
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
        }
      }
    });
  }

  if (userProfileImageInput) {
    userProfileImageInput.addEventListener("change", () => {
      const file = userProfileImageInput.files && userProfileImageInput.files[0];
      const currentImageUrl = (userProfileForm && userProfileForm.dataset.profileImageUrl) || "";
      const currentName = userProfileForm?.elements?.name?.value || userTriggerName?.textContent || "";
      const currentEmail = userProfileForm?.elements?.email?.value || userMenuEmail?.textContent || "";

      if (!file) {
        setAvatarDisplay(userProfilePreviewImage, userProfilePreviewFallback, currentImageUrl, currentName, currentEmail);
        return;
      }

      if (String(file.type || "").indexOf("image/") !== 0) {
        userProfileImageInput.value = "";
        setAvatarDisplay(userProfilePreviewImage, userProfilePreviewFallback, currentImageUrl, currentName, currentEmail);
        showFeedback(userProfileFeedback, "Debes seleccionar una imagen válida para el perfil.", "danger");
        return;
      }

      hideFeedback(userProfileFeedback);
      const reader = new FileReader();
      reader.onload = () => {
        setAvatarDisplay(userProfilePreviewImage, userProfilePreviewFallback, String(reader.result || ""), currentName, currentEmail);
      };
      reader.readAsDataURL(file);
    });
  }

