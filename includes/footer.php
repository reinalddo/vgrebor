<?php
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/store_config.php';
require_once __DIR__ . '/recharge_notifications.php';

$accountApiUrl = app_path('/api/account.php');
$accountApiUrlJs = json_encode($accountApiUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$rechargeNotificationsApiUrl = app_path('/api/recharge_notifications.php');
$rechargeNotificationsApiUrlJs = json_encode($rechargeNotificationsApiUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$rechargeNotificationsEnabled = recharge_notifications_is_enabled() && recharge_notifications_is_public_context();
$rechargeNotificationsEnabledJs = $rechargeNotificationsEnabled ? 'true' : 'false';
$rechargeNotificationPosition = trim((string) store_config_get('win_points_notification_position', 'bottom-left'));
$allowedRechargeNotificationPositions = [
  'bottom-left',
  'bottom-center',
  'bottom-right',
  'top-left',
  'top-center',
  'top-right',
  'middle-left',
  'middle-right',
];
if (!in_array($rechargeNotificationPosition, $allowedRechargeNotificationPositions, true)) {
  $rechargeNotificationPosition = 'bottom-left';
}

$facebookUrl = store_config_normalize_social_url(store_config_get('facebook', ''));
$instagramUrl = store_config_normalize_social_url(store_config_get('instagram', ''));
$tiktokUrl = store_config_normalize_social_url(store_config_get('tiktok', ''));
$whatsappValue = store_config_get('whatsapp', '');
$whatsappMessage = store_config_get('mensaje_whatsapp', '');
$whatsappFloatingEnabled = store_config_get('whatsapp_flotante_activo', '1') !== '0';
$whatsappUrl = store_config_whatsapp_link_with_message($whatsappValue, $whatsappMessage);
$whatsappChannelUrl = store_config_normalize_social_url(store_config_get('whatsapp_channel', ''));
$whatsappChannelFloatingEnabled = store_config_get('whatsapp_channel_flotante_activo', '1') !== '0';
$googleAnalyticsEnabled = store_config_get('google_analytics_activo', '0') === '1';
$googleAnalyticsScript = trim(store_config_get('google_analytics_script', ''));

$hasFacebook = store_config_is_valid_social_url($facebookUrl);
$hasInstagram = store_config_is_valid_social_url($instagramUrl);
$hasTiktok = store_config_is_valid_social_url($tiktokUrl);
$hasWhatsapp = $whatsappFloatingEnabled && $whatsappUrl !== '';
$hasWhatsappChannel = $whatsappChannelFloatingEnabled && store_config_is_valid_social_url($whatsappChannelUrl);

$menuScript = <<<'SCRIPT'
<script>
  const __ADMIN_WA_BASE_URL__ = "__ADMIN_WA_BASE_URL__";
  const __AUTH_USER_DISPLAY_NAME__ = "__AUTH_USER_DISPLAY_NAME__";
  const __WP_NAME__ = "__WP_NAME__";
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
  const userMissionsHistoryEmpty = document.getElementById("user-missions-history-empty");
  const userMissionsHistoryList = document.getElementById("user-missions-history-list");
  const userMissionsHistoryTableBody = document.getElementById("user-missions-history-table-body");
  const userMissionsHistoryCards = document.getElementById("user-missions-history-cards");
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
      mission_daily: "Misión diaria",
      purchase: "Compra",
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

  const dailyMissionRewardLabel = (prizeType) => {
    const labels = {
      winpoints: __WP_NAME__,
      coupon: "Cupón",
      immunity: "Escudo",
      streaming_ticket: "Ticket de streaming",
    };
    return labels[prizeType] || prizeType || "Premio";
  };

  const dailyMissionRewardStatusLabel = (status) => {
    const labels = {
      pending: "Pendiente",
      granted: "Otorgado",
      issued: "Emitido",
      assigned: "Asignado",
      claimed: "Reclamado",
      resolved: "Resuelto",
      failed: "Fallido",
    };
    return labels[status] || status || "Pendiente";
  };

  const formatMissionDateParts = (value) => {
    const rawValue = String(value || "").trim();
    if (!rawValue) {
      return { date: "", time: "" };
    }

    const [rawDate, rawTime = ""] = rawValue.split(" ");
    const dateParts = rawDate.split("-");
    const date = dateParts.length === 3
      ? `${dateParts[2]}/${dateParts[1]}/${dateParts[0]}`
      : rawDate;
    const time = rawTime ? rawTime.slice(0, 5) : "";

    return { date, time };
  };

  const missionEntryDateParts = (entry) => {
    const mDate = String(entry.mission_date || "").trim();
    const tSrc = String(entry.created_at || entry.claimed_at || entry.resolved_at || "").trim();
    const timePart = tSrc.includes(" ") ? tSrc.split(" ")[1] : "";
    if (mDate) {
      const dp = mDate.split("-");
      const date = dp.length === 3 ? `${dp[2]}/${dp[1]}/${dp[0]}` : mDate;
      return { date, time: timePart ? timePart.slice(0, 5) : "" };
    }
    return formatMissionDateParts(tSrc);
  };

  const renderDailyMissionHistoryCard = (entry) => {
    const dateParts = missionEntryDateParts(entry);
    const reason = entry.reason || entry.mission_key || "Premio diario";
    const status = dailyMissionRewardStatusLabel(entry.reward_status);
    const reclaimBtn = buildStreamingReclaimBtn(entry);
    return `
      <article class="rounded-4 border border-info-subtle p-3" style="background:rgba(8,15,24,0.78);box-shadow:0 0 16px rgba(34,211,238,0.08);">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
          <div>
            <div class="small text-secondary">${escapeHtml(dateParts.date)}${dateParts.time ? ` · ${escapeHtml(dateParts.time)}` : ""}</div>
          </div>
          <span class="badge rounded-pill text-bg-dark border border-info-subtle text-info">${escapeHtml(status)}</span>
        </div>
        <div class="mb-1">${buildMissionPrizeCell(entry)}</div>
        <div class="small text-secondary mb-1"><strong class="text-light">Motivo:</strong> ${escapeHtml(reason)}</div>
        ${reclaimBtn ? `<div class="mt-2">${reclaimBtn}</div>` : ""}
      </article>`;
  };

  const buildStreamingReclaimBtn = (entry) => {
    if (entry.prize_type !== 'streaming_ticket' || entry.reward_status !== 'pending') return '';
    const waBase = __ADMIN_WA_BASE_URL__;
    if (!waBase) return '';
    const userName = __AUTH_USER_DISPLAY_NAME__ || 'usuario';
    const levelLabels = { basic: 'Básico', intermediate: 'Intermedio', legendary: 'Legendario' };
    const levelLabel = levelLabels[entry.level_key] || entry.level_key || 'Básico';
    const msg = encodeURIComponent(`Hola soy el usuario ${userName} y gané una cuenta streaming del cofre ${levelLabel}`);
    return `<a href="${waBase}?text=${msg}" target="_blank" rel="noopener"
      style="display:inline-flex;align-items:center;gap:.4rem;background:#25d366;color:#fff;border:none;border-radius:.6rem;padding:.32rem .75rem;font-size:.78rem;font-weight:700;text-decoration:none;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.136.558 4.14 1.534 5.874L0 24l6.335-1.652A11.955 11.955 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.882a9.876 9.876 0 01-5.03-1.375l-.36-.214-3.732.977.995-3.633-.235-.374A9.862 9.862 0 012.118 12C2.118 6.531 6.531 2.118 12 2.118c5.469 0 9.882 4.413 9.882 9.882 0 5.469-4.413 9.882-9.882 9.882z"/></svg>
      Reclamar
    </a>`;
  };

  const buildMissionPrizeCell = (entry) => {
    const label = dailyMissionRewardLabel(entry.prize_type);
    if (entry.prize_type === 'winpoints' && Number(entry.points_amount || 0) > 0) {
      return `<span class="fw-semibold text-light">${escapeHtml(label)}</span><div class="small" style="color:#22d3ee">+${escapeHtml(formatPointsNumber(entry.points_amount))}</div>`;
    }
    if (entry.prize_type === 'coupon' && Number(entry.coupon_discount_percent || 0) > 0) {
      return `<span class="fw-semibold text-light">${escapeHtml(label)}</span><div class="small text-secondary">${escapeHtml(String(entry.coupon_discount_percent))}% desc.</div>`;
    }
    if (entry.prize_type === 'immunity' && Number(entry.immunity_days || 0) > 0) {
      const d = Number(entry.immunity_days);
      return `<span class="fw-semibold text-light">${escapeHtml(label)}</span><div class="small text-secondary">${d} día${d !== 1 ? "s" : ""}</div>`;
    }
    return `<span class="fw-semibold text-light">${escapeHtml(label)}</span>`;
  };

  const renderDailyMissionHistoryRow = (entry) => {
    const dateParts = missionEntryDateParts(entry);
    const reason = entry.reason || entry.mission_key || "Premio diario";
    const status = dailyMissionRewardStatusLabel(entry.reward_status);
    const reclaimBtn = buildStreamingReclaimBtn(entry);
    return `
      <tr>
        <td class="bg-transparent border-bottom border-info-subtle">${buildMissionPrizeCell(entry)}</td>
        <td class="bg-transparent border-bottom border-info-subtle text-light">${escapeHtml(reason)}</td>
        <td class="bg-transparent border-bottom border-info-subtle"><span class="badge rounded-pill text-bg-dark border border-info-subtle text-info">${escapeHtml(status)}</span></td>
        <td class="bg-transparent border-bottom border-info-subtle text-secondary">${escapeHtml(dateParts.date)}</td>
        <td class="bg-transparent border-bottom border-info-subtle text-secondary">${escapeHtml(dateParts.time)}</td>
        <td class="bg-transparent border-bottom border-info-subtle">${reclaimBtn}</td>
      </tr>`;
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

  const formatTxDate = (createdAt) => {
    const raw = String(createdAt || "").trim();
    if (!raw) return { date: "", time: "" };
    const [d, t = ""] = raw.split(" ");
    const dp = d.split("-");
    const date = dp.length === 3 ? `${dp[2]}/${dp[1]}/${dp[0]}` : d;
    return { date, time: t ? t.slice(0, 5) : "" };
  };

  const renderRewardTransactionCard = (transaction) => {
    const delta = Number(transaction.points_delta || 0);
    const deltaClass = delta >= 0 ? "text-success" : "text-warning";
    const orderText = transaction.order_id ? `Pedido #${escapeHtml(String(transaction.order_id))}` : "";
    const txDate = formatTxDate(transaction.created_at);
    const dateDisplay = txDate.date + (txDate.time ? ` · ${txDate.time}` : "");
    const desc = transaction.description || orderText || "—";
    return `
      <article class="rounded-4 border border-info p-3" style="background:rgba(8,15,24,0.78);box-shadow:0 0 16px rgba(34,211,238,0.08);">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
          <div>
            <div class="small text-uppercase text-info" style="letter-spacing:0.14em;">${escapeHtml(rewardsTypeLabel(transaction.transaction_type))}</div>
            <div class="small text-secondary">${escapeHtml(dateDisplay)}</div>
          </div>
          <div class="fw-bold ${deltaClass}">${delta >= 0 ? "+" : ""}${escapeHtml(formatPointsNumber(delta))}</div>
        </div>
        <div class="text-light fw-semibold mb-1">${escapeHtml(desc)}</div>
        ${orderText ? `<div class="small text-secondary mb-1">${escapeHtml(orderText)}</div>` : ""}
        <div class="small text-info">Saldo: ${escapeHtml(formatPointsNumber(transaction.balance_after || 0))}</div>
      </article>`;
  };

  const renderRewardTransactionRow = (transaction) => {
    const delta = Number(transaction.points_delta || 0);
    const deltaClass = delta >= 0 ? "text-success" : "text-warning";
    const txDate = formatTxDate(transaction.created_at);
    const detailParts = [transaction.description || ""];
    if (transaction.juego_nombre || transaction.paquete_nombre) {
      detailParts.push(`${transaction.juego_nombre || ""} ${transaction.paquete_nombre || ""}`.trim());
    }
    if (transaction.order_id) {
      detailParts.push(`Pedido #${transaction.order_id}`);
    }
    return `
      <tr>
        <td class="bg-transparent border-bottom border-info-subtle text-secondary">${escapeHtml(txDate.date)}${txDate.time ? `<div class="small" style="opacity:.65">${escapeHtml(txDate.time)}</div>` : ""}</td>
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
    const programName = config.name || __WP_NAME__;

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
      const response = await fetch((window.__TVG_API_ACCOUNT || __ACCOUNT_API_URL__) + "?action=orders", {
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
    if (!userRewardsModal || !userRewardsLoading || !userRewardsContent || !userRewardsEmpty || !userRewardsTransactionsList || !userRewardsTableBody || !userRewardsCards || !userMissionsHistoryEmpty || !userMissionsHistoryList || !userMissionsHistoryTableBody || !userMissionsHistoryCards) {
      return;
    }

    hideFeedback(userRewardsFeedback);
    hideElement(userRewardsContent);
    hideElement(userRewardsEmpty);
    hideElement(userRewardsTransactionsList);
    hideElement(userMissionsHistoryList);
    hideElement(userMissionsHistoryEmpty);
    showElement(userRewardsLoading);
    userRewardsLoading.textContent = "Cargando saldo, movimientos y misiones...";

    try {
      const response = await fetch((window.__TVG_API_ACCOUNT || __ACCOUNT_API_URL__) + "?action=rewards", {
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

      const transactions = Array.isArray(data.transactions) ? data.transactions : [];
      const dailyMissions = data.daily_missions && typeof data.daily_missions === "object" ? data.daily_missions : {};
      const missionHistory = Array.isArray(dailyMissions.history) ? dailyMissions.history : [];
      const hasTransactions = transactions.length > 0;
      const hasMissionHistory = missionHistory.length > 0;

      // ─── WP Movements: paginación + filtros ──────────────────────────────
      const WP_PER_PAGE = 10;
      let wpCurrentPage = 1;
      let wpCurrentFilter = 'all';

      const getFilteredTx = () => {
        if (wpCurrentFilter === 'all') return transactions;
        if (wpCurrentFilter === 'gained') return transactions.filter(t => Number(t.points_delta || 0) > 0);
        if (wpCurrentFilter === 'spent') return transactions.filter(t => Number(t.points_delta || 0) < 0);
        return transactions.filter(t => (t.transaction_type || '') === wpCurrentFilter);
      };

      const renderWpPage = () => {
        const filtered = getFilteredTx();
        const totalPages = Math.max(1, Math.ceil(filtered.length / WP_PER_PAGE));
        wpCurrentPage = Math.min(Math.max(1, wpCurrentPage), totalPages);
        const slice = filtered.slice((wpCurrentPage - 1) * WP_PER_PAGE, wpCurrentPage * WP_PER_PAGE);
        userRewardsTableBody.innerHTML = slice.map(renderRewardTransactionRow).join("");
        userRewardsCards.innerHTML = slice.map(renderRewardTransactionCard).join("");
        const pageInfo = document.getElementById('rewards-page-info');
        if (pageInfo) pageInfo.textContent = `Página ${wpCurrentPage} de ${totalPages} (${filtered.length} movimientos)`;
        const pagination = document.getElementById('user-rewards-pagination');
        const prevBtn = document.getElementById('rewards-prev-page');
        const nextBtn = document.getElementById('rewards-next-page');
        if (pagination) {
          if (filtered.length > WP_PER_PAGE) { pagination.classList.remove('d-none'); pagination.style.display = 'flex'; }
          else { pagination.classList.add('d-none'); }
        }
        if (prevBtn)  { prevBtn.disabled  = wpCurrentPage <= 1; }
        if (nextBtn)  { nextBtn.disabled  = wpCurrentPage >= totalPages; }
      };

      if (hasTransactions) {
        const filterBar = document.getElementById('user-rewards-filter-bar');
        if (filterBar) {
          filterBar.classList.remove('d-none');
          filterBar.querySelectorAll('[data-wp-filter]').forEach(btn => {
            btn.addEventListener('click', () => {
              filterBar.querySelectorAll('[data-wp-filter]').forEach(b => b.classList.remove('active'));
              btn.classList.add('active');
              wpCurrentFilter = btn.dataset.wpFilter;
              wpCurrentPage = 1;
              renderWpPage();
            });
          });
        }
        const prevBtnWp = document.getElementById('rewards-prev-page');
        const nextBtnWp = document.getElementById('rewards-next-page');
        if (prevBtnWp) prevBtnWp.addEventListener('click', () => { wpCurrentPage--; renderWpPage(); });
        if (nextBtnWp) nextBtnWp.addEventListener('click', () => { wpCurrentPage++; renderWpPage(); });
        renderWpPage();
        showElement(userRewardsTransactionsList);
      }

      if (hasMissionHistory) {
        const MISSIONS_PER_PAGE = 10;
        let missionsCurrentPage = 1;
        let missionsCurrentFilter = 'all';

        const getFilteredMissions = () => {
          if (missionsCurrentFilter === 'task') return missionHistory.filter(e => e.origin_type === 'task');
          if (missionsCurrentFilter === 'chest') return missionHistory.filter(e => e.origin_type === 'chest');
          return missionHistory;
        };

        const renderMissionsPage = () => {
          const filtered = getFilteredMissions();
          const totalPages = Math.max(1, Math.ceil(filtered.length / MISSIONS_PER_PAGE));
          missionsCurrentPage = Math.min(Math.max(1, missionsCurrentPage), totalPages);
          const slice = filtered.slice((missionsCurrentPage - 1) * MISSIONS_PER_PAGE, missionsCurrentPage * MISSIONS_PER_PAGE);
          userMissionsHistoryTableBody.innerHTML = slice.map(renderDailyMissionHistoryRow).join("");
          userMissionsHistoryCards.innerHTML = slice.map(renderDailyMissionHistoryCard).join("");
          const pageInfo = document.getElementById('missions-page-info');
          const pagination = document.getElementById('user-missions-pagination');
          const prevBtn = document.getElementById('missions-prev-page');
          const nextBtn = document.getElementById('missions-next-page');
          if (pageInfo) pageInfo.textContent = `Página ${missionsCurrentPage} de ${totalPages} (${filtered.length} entradas)`;
          if (pagination) {
            if (filtered.length > MISSIONS_PER_PAGE) { pagination.classList.remove('d-none'); pagination.style.display = 'flex'; }
            else { pagination.classList.add('d-none'); }
          }
          if (prevBtn) prevBtn.disabled = missionsCurrentPage <= 1;
          if (nextBtn) nextBtn.disabled = missionsCurrentPage >= totalPages;
        };

        const missionsFilterBar = document.getElementById('user-missions-filter-bar');
        if (missionsFilterBar) {
          missionsFilterBar.classList.remove('d-none');
          missionsFilterBar.querySelectorAll('[data-missions-filter]').forEach(btn => {
            btn.addEventListener('click', () => {
              missionsFilterBar.querySelectorAll('[data-missions-filter]').forEach(b => b.classList.remove('active'));
              btn.classList.add('active');
              missionsCurrentFilter = btn.dataset.missionsFilter;
              missionsCurrentPage = 1;
              renderMissionsPage();
            });
          });
        }
        const prevBtnM = document.getElementById('missions-prev-page');
        const nextBtnM = document.getElementById('missions-next-page');
        if (prevBtnM) prevBtnM.addEventListener('click', () => { missionsCurrentPage--; renderMissionsPage(); });
        if (nextBtnM) nextBtnM.addEventListener('click', () => { missionsCurrentPage++; renderMissionsPage(); });
        renderMissionsPage();
        showElement(userMissionsHistoryList);
      } else {
        showElement(userMissionsHistoryEmpty);
      }

      if (!hasTransactions && !hasMissionHistory) {
        showElement(userRewardsEmpty);
        return;
      }
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
      closeMenu();
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
        const response = await fetch(window.__TVG_API_ACCOUNT || __ACCOUNT_API_URL__, {
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
</script>
SCRIPT;
$adminWaBaseUrl     = store_config_whatsapp_link($whatsappValue);
$adminWaBaseUrlJs   = json_encode($adminWaBaseUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$footerAuthName     = trim((string) ($_SESSION['auth_user']['nombre'] ?? ($_SESSION['auth_user']['email'] ?? '')));
$footerAuthNameJs   = json_encode($footerAuthName, JSON_UNESCAPED_UNICODE);
$footerWpName   = win_points_program_name();
$footerWpNameJs = json_encode($footerWpName, JSON_UNESCAPED_UNICODE);
$menuScript = str_replace('__ACCOUNT_API_URL__', $accountApiUrlJs, $menuScript);
$menuScript = str_replace('"__ADMIN_WA_BASE_URL__"', $adminWaBaseUrlJs, $menuScript);
$menuScript = str_replace('"__AUTH_USER_DISPLAY_NAME__"', $footerAuthNameJs, $menuScript);
$menuScript = str_replace('"__WP_NAME__"', $footerWpNameJs, $menuScript);

$rechargeNotificationsScript = <<<'SCRIPT'
<script>
  (() => {
    const enabled = __LIVE_RECHARGE_ENABLED__;
    if (!enabled) {
      return;
    }

    const endpoint = __LIVE_RECHARGE_ENDPOINT__;
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
      const imageUrl = String((item && item.image_url) || logoPath || "").trim();
      const titleLabel = String((item && item.title) || "Recarga Exitosa").replace(/\s*✅+\s*/g, " ").trim() || "Recarga Exitosa";
      const userLabel = String((item && item.user_label) || "").trim();
      const rechargeLabel = String((item && item.recharge_label) || (item && item.detail) || "").trim();
      article.innerHTML = `
        <div class="live-recharge-toast__pulse" aria-hidden="true"></div>
        <div class="live-recharge-toast__media">${imageUrl ? `<img src="${escapeHtml(imageUrl)}" alt="Juego" class="live-recharge-toast__thumb">` : ""}</div>
        <div class="live-recharge-toast__body">
          <div class="live-recharge-toast__title">${escapeHtml(titleLabel)}</div>
          ${userLabel !== "" ? `<div class="live-recharge-toast__user">${escapeHtml(userLabel)}</div>` : ""}
          ${rechargeLabel !== "" ? `<div class="live-recharge-toast__detail">${escapeHtml(rechargeLabel)}</div>` : ""}
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
</script>
SCRIPT;
$rechargeNotificationsScript = str_replace('__LIVE_RECHARGE_ENDPOINT__', $rechargeNotificationsApiUrlJs, $rechargeNotificationsScript);
$rechargeNotificationsScript = str_replace('__LIVE_RECHARGE_ENABLED__', $rechargeNotificationsEnabledJs, $rechargeNotificationsScript);
?>
    </div>
  </div>
  <?php if ($hasFacebook || $hasInstagram || $hasTiktok): ?>
    <div class="social-footer-shell social-footer-shell-public mt-5" role="contentinfo">
      <div class="social-footer-card">
        <p class="social-footer-kicker mb-2">Redes oficiales</p>
        <div class="social-footer-links">
          <?php if ($hasFacebook): ?>
            <a href="<?php echo htmlspecialchars($facebookUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="social-footer-link social-footer-link-facebook" aria-label="Facebook">
              <span class="social-footer-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor" role="img"><path d="M13.5 22v-8h2.7l.5-3h-3.2V9.1c0-.9.3-1.6 1.7-1.6H17V4.8c-.3 0-1.3-.1-2.4-.1-2.4 0-4.1 1.5-4.1 4.3V11H8v3h2.5v8h3Z"/></svg>
              </span>
              <span>Facebook</span>
            </a>
          <?php endif; ?>
          <?php if ($hasInstagram): ?>
            <a href="<?php echo htmlspecialchars($instagramUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="social-footer-link social-footer-link-instagram" aria-label="Instagram">
              <span class="social-footer-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" role="img"><rect x="3.5" y="3.5" width="17" height="17" rx="5"></rect><circle cx="12" cy="12" r="4"></circle><circle cx="17.3" cy="6.7" r="1"></circle></svg>
              </span>
              <span>Instagram</span>
            </a>
          <?php endif; ?>
          <?php if ($hasTiktok): ?>
            <a href="<?php echo htmlspecialchars($tiktokUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="social-footer-link social-footer-link-tiktok" aria-label="TikTok">
              <span class="social-footer-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor" role="img"><path d="M14.57 3c.2 1.68 1.17 3.24 2.62 4.14.93.58 2 .88 3.08.88V11a8.1 8.1 0 0 1-3.37-.73v5.16a6.53 6.53 0 1 1-6.53-6.53c.28 0 .57.02.84.06v3.16a3.52 3.52 0 1 0 2.84 3.44V3h2.52Z"/></svg>
              </span>
              <span>TikTok</span>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($hasWhatsapp || $hasWhatsappChannel): ?>
    <div class="floating-social-stack" aria-label="Accesos rápidos de contacto">
      <?php if ($hasWhatsappChannel): ?>
        <a href="<?php echo htmlspecialchars($whatsappChannelUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="floating-social-button floating-social-button-channel" aria-label="Canal de difusión">
          <span class="floating-social-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor" role="img"><path d="M12 2a10 10 0 0 0-8.7 14.95L2 22l5.22-1.3A10 10 0 1 0 12 2Zm4.74 13.34c-.2.56-1.16 1.04-1.62 1.11-.42.06-.95.09-1.53-.1-.35-.11-.81-.26-1.39-.51-2.45-1.06-4.05-3.67-4.17-3.84-.12-.16-1-1.34-1-2.55s.63-1.79.86-2.03c.22-.24.48-.3.64-.3h.46c.14 0 .33-.05.52.39.2.47.67 1.62.73 1.74.06.12.1.27.02.43-.07.16-.11.26-.22.4-.11.13-.22.29-.31.39-.1.11-.2.22-.08.43.12.2.53.88 1.14 1.42.78.69 1.44.9 1.64 1 .2.1.31.08.43-.05.12-.13.49-.57.62-.76.13-.2.27-.16.45-.1.19.07 1.17.55 1.38.65.2.1.34.15.39.24.05.09.05.53-.15 1.09Z"/></svg>
          </span>
          <span class="floating-social-label" style="display:inline-block;white-space:nowrap;line-height:1.2;">Canal de difusión</span>
        </a>
      <?php endif; ?>
      <?php if ($hasWhatsapp): ?>
        <a href="<?php echo htmlspecialchars($whatsappUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="floating-social-button floating-social-button-whatsapp" aria-label="WhatsApp">
          <span class="floating-social-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="currentColor" role="img"><path d="M20.52 3.48A11.8 11.8 0 0 0 12.08 0C5.54 0 .22 5.32.22 11.86c0 2.09.55 4.13 1.58 5.93L0 24l6.39-1.67a11.8 11.8 0 0 0 5.69 1.45h.01c6.54 0 11.86-5.32 11.86-11.86 0-3.17-1.23-6.16-3.43-8.44ZM12.09 21.76h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.79.99 1.01-3.69-.23-.38A9.87 9.87 0 0 1 2.2 11.86C2.2 6.4 6.63 1.98 12.08 1.98c2.64 0 5.12 1.03 6.98 2.91a9.8 9.8 0 0 1 2.88 6.98c0 5.45-4.43 9.89-9.85 9.89Zm5.42-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.46-.15-.66.15-.2.3-.76.97-.93 1.17-.17.2-.34.22-.64.07-.3-.15-1.27-.47-2.41-1.49-.89-.8-1.49-1.79-1.67-2.09-.17-.3-.02-.47.13-.62.13-.13.3-.34.44-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.66-1.59-.9-2.17-.24-.58-.48-.5-.66-.5h-.56c-.2 0-.52.08-.79.37-.27.3-1.05 1.03-1.05 2.52 0 1.49 1.08 2.92 1.23 3.12.15.2 2.11 3.23 5.12 4.52.72.31 1.29.49 1.73.63.73.23 1.39.2 1.91.12.58-.09 1.76-.72 2.01-1.42.25-.69.25-1.29.17-1.42-.07-.12-.27-.2-.57-.35Z"/></svg>
          </span>
          <span class="floating-social-label" style="display:inline-block;white-space:nowrap;line-height:1.2;">Soporte</span>
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div id="live-recharge-notifications" class="live-recharge-stack" data-position="<?php echo htmlspecialchars($rechargeNotificationPosition, ENT_QUOTES, 'UTF-8'); ?>" aria-live="polite" aria-atomic="false"></div>
  <style>
    .live-recharge-stack {
      position: fixed;
      z-index: 1040;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      pointer-events: none;
      width: min(360px, calc(100vw - 32px));
      max-width: calc(100vw - 32px);
      --live-recharge-enter-x: -18px;
      --live-recharge-enter-y: 0px;
    }
    .live-recharge-stack[data-position="bottom-left"] {
      left: 16px;
      bottom: 16px;
    }
    .live-recharge-stack[data-position="bottom-center"] {
      left: 50%;
      bottom: 16px;
      transform: translateX(-50%);
      --live-recharge-enter-x: 0px;
      --live-recharge-enter-y: 18px;
    }
    .live-recharge-stack[data-position="bottom-right"] {
      right: 16px;
      bottom: 16px;
      --live-recharge-enter-x: 18px;
      --live-recharge-enter-y: 0px;
    }
    .live-recharge-stack[data-position="top-left"] {
      left: 16px;
      top: 16px;
    }
    .live-recharge-stack[data-position="top-center"] {
      left: 50%;
      top: 16px;
      transform: translateX(-50%);
      --live-recharge-enter-x: 0px;
      --live-recharge-enter-y: -18px;
    }
    .live-recharge-stack[data-position="top-right"] {
      right: 16px;
      top: 16px;
      --live-recharge-enter-x: 18px;
      --live-recharge-enter-y: 0px;
    }
    .live-recharge-stack[data-position="middle-left"] {
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
    }
    .live-recharge-stack[data-position="middle-right"] {
      right: 16px;
      top: 50%;
      transform: translateY(-50%);
      --live-recharge-enter-x: 18px;
      --live-recharge-enter-y: 0px;
    }
    .live-recharge-toast {
      display: grid;
      grid-template-columns: auto 52px minmax(0, 1fr);
      align-items: stretch;
      gap: 0.65rem;
      padding: 0.72rem 0.82rem 0.72rem 0.68rem;
      min-height: 74px;
      border-radius: 18px;
      border: 1px solid rgba(var(--theme-live-notification-border-rgb), 0.72);
      background: linear-gradient(135deg, rgba(var(--theme-live-notification-bg-rgb), 0.98), rgba(var(--theme-live-notification-border-rgb), 0.16));
      box-shadow: 0 18px 45px rgba(2, 6, 23, 0.42), 0 0 18px rgba(var(--theme-live-notification-border-rgb), 0.16);
      backdrop-filter: blur(14px);
      transform: translate3d(var(--live-recharge-enter-x), var(--live-recharge-enter-y), 0);
      opacity: 0;
      transition: opacity 0.28s ease, transform 0.28s ease;
      pointer-events: none;
    }
    .live-recharge-toast.is-visible {
      opacity: 1;
      transform: translate3d(0, 0, 0);
    }
    .live-recharge-toast.is-leaving {
      opacity: 0;
      transform: translate3d(var(--live-recharge-enter-x), var(--live-recharge-enter-y), 0);
    }
    .live-recharge-toast__pulse {
      width: 9px;
      height: 9px;
      align-self: center;
      border-radius: 999px;
      background: var(--theme-live-notification-accent);
      box-shadow: 0 0 0 0 rgba(var(--theme-live-notification-accent-rgb), 0.56);
      animation: live-recharge-pulse 1.9s ease-out infinite;
    }
    .live-recharge-toast__media {
      width: 52px;
      min-height: 100%;
      border-radius: 12px;
      overflow: hidden;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(var(--theme-live-notification-border-rgb), 0.34);
      display: flex;
      align-items: stretch;
      justify-content: center;
      box-shadow: inset 0 0 0 1px rgba(255,255,255,0.03);
    }
    .live-recharge-toast__body {
      min-width: 0;
      display: grid;
      grid-template-rows: repeat(3, auto);
      align-content: center;
      gap: 0.1rem;
    }
    .live-recharge-toast__title {
      color: var(--theme-live-notification-text);
      font-size: 0.9rem;
      font-weight: 800;
      line-height: 1.2;
      letter-spacing: 0.01em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .live-recharge-toast__user {
      color: #f8fafc;
      font-size: 0.8rem;
      font-weight: 700;
      line-height: 1.2;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .live-recharge-toast__thumb {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .live-recharge-toast__detail {
      color: var(--theme-live-notification-muted);
      font-size: 0.78rem;
      line-height: 1.25;
      min-width: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    @keyframes live-recharge-pulse {
      0% {
        box-shadow: 0 0 0 0 rgba(var(--theme-live-notification-accent-rgb), 0.56);
      }
      70% {
        box-shadow: 0 0 0 12px rgba(var(--theme-live-notification-accent-rgb), 0);
      }
      100% {
        box-shadow: 0 0 0 0 rgba(var(--theme-live-notification-accent-rgb), 0);
      }
    }
    @media (max-width: 575.98px) {
      .live-recharge-stack {
        gap: 0.55rem;
        width: min(188px, calc(100vw - 24px));
        max-width: calc(100vw - 24px);
      }
      .live-recharge-stack[data-position="bottom-left"] {
        left: 12px;
        bottom: 12px;
      }
      .live-recharge-stack[data-position="bottom-center"] {
        left: 50%;
        bottom: 12px;
      }
      .live-recharge-stack[data-position="bottom-right"] {
        right: 12px;
        bottom: 12px;
      }
      .live-recharge-stack[data-position="top-left"] {
        left: 12px;
        top: 12px;
      }
      .live-recharge-stack[data-position="top-center"] {
        left: 50%;
        top: 12px;
      }
      .live-recharge-stack[data-position="top-right"] {
        right: 12px;
        top: 12px;
      }
      .live-recharge-stack[data-position="middle-left"] {
        left: 12px;
        top: 50%;
      }
      .live-recharge-stack[data-position="middle-right"] {
        right: 12px;
        top: 50%;
      }
      .live-recharge-toast {
        grid-template-columns: auto 28px minmax(0, 1fr);
        padding: 0.42rem 0.46rem 0.42rem 0.4rem;
        gap: 0.36rem;
        min-height: 44px;
        border-radius: 12px;
      }
      .live-recharge-toast__pulse {
        width: 6px;
        height: 6px;
      }
      .live-recharge-toast__media {
        width: 28px;
        border-radius: 8px;
      }
      .live-recharge-toast__title {
        font-size: 0.6rem;
        line-height: 1.15;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .live-recharge-toast__user {
        font-size: 0.54rem;
        line-height: 1.15;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .live-recharge-toast__detail {
        font-size: 0.52rem;
        line-height: 1.12;
        overflow: hidden;
        text-overflow: ellipsis;
      }
    }
  </style>
  <?php
  $menuScriptVersioned = str_replace('<script', '<script', $menuScript);
  $menuScriptVersioned = str_replace('</script>', '</script>', $menuScriptVersioned);
  // Si hay scripts externos, agregar ?v=fecha
  echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
  echo $menuScriptVersioned;
  echo $rechargeNotificationsScript;
  ?>
  <?php
  if (!empty($pageScripts) && is_array($pageScripts)) {
    foreach ($pageScripts as $script) {
      echo $script;
    }
  }
  if ($googleAnalyticsEnabled && $googleAnalyticsScript !== '') {
    echo $googleAnalyticsScript;
  }
  ?>
</body>
</html>
