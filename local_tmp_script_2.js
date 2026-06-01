
  // Todas las variables y lógica JS en un solo bloque
  const appBasePath = <?= json_encode($scriptDir, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const rememberLastPurchaseIdentifierEnabled = <?= $rememberLastPurchaseIdentifierEnabled ? 'true' : 'false' ?>;
  const defaultOrderEmail = <?= json_encode($loggedUserEmail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let defaultOrderUserIdentifier = <?= json_encode($loggedUserLastPurchaseIdentifier, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let defaultPaymentPhone = <?= json_encode($loggedUserLastPurchasePhone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paymentMethodsByCurrency = <?= json_encode($paymentMethodsByCurrency, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePayCheckoutEnabled = <?= $binancePayCheckoutEnabled ? 'true' : 'false' ?>;
  const paypalPayCheckoutEnabled = <?= $paypalPayCheckoutEnabled ? 'true' : 'false' ?>;
  const paymentMethodDiscountsEnabled = <?= $paymentMethodDiscountsEnabled ? 'true' : 'false' ?>;
  const binancePayDiscountPercentage = <?= json_encode((float) $binancePayDiscountPercentage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const accountSaleFeatureEnabled = <?= $accountSaleFeatureEnabled ? 'true' : 'false' ?>;
  const binancePayButtonLabel = 'Binance Pay';
  const binancePayImageUrl = <?= json_encode($binancePayImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePayCornerImageUrl = <?= json_encode($binancePayCornerImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paypalPayButtonLabel = 'PayPal';
  const paypalPayImageUrl = <?= json_encode($paypalPayImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paypalPayCornerImageUrl = <?= json_encode($paypalPayCornerImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paypalPayQrImageUrl = <?= json_encode($paypalPayQrImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paypalSupportedCurrencies = <?= json_encode($paypalSupportedCurrencies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paymentSupportWhatsappBase = <?= json_encode($paymentSupportWhatsappBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const winPointsState = <?= json_encode([
    'enabled' => $winPointsEnabled,
    'loggedIn' => $loggedUserId > 0,
    'name' => $winPointsProgramName,
    'iconUrl' => $winPointsIconUrl,
    'paymentImageUrl' => $winPointsPaymentImageUrl,
    'paymentCornerImageUrl' => $winPointsPaymentCornerImageUrl,
    'notificationLogoUrl' => $winPointsNotificationLogoUrl,
    'notificationPosition' => $winPointsNotificationPosition,
    'guestMessage' => $winPointsGuestMessage,
    'balance' => (int) ($winPointsUserSummary['balance'] ?? 0),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const gameUsesCatalogApi = <?= $usesCatalogApi ? 'true' : 'false' ?>;
  const paymentHeaderMinimalEnabled = <?= $paymentHeaderMinimalEnabled ? 'true' : 'false' ?>;
  const packageFeatureIconSvgMap = <?= json_encode(package_feature_icon_svg_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const packGrid = document.getElementById('pack-grid');
  const packCards2 = Array.from(document.querySelectorAll('.pack-card'));
  const packAccountPreviewButtons = Array.from(document.querySelectorAll('.pack-account-preview-btn'));
  const selectedPack = document.getElementById("selected-pack");
  const purchaseSummaryLayout = document.getElementById('purchase-summary-layout');
  const purchaseQuantityPanel = document.getElementById('purchase-quantity-panel');
  const orderQuantityDecreaseButton = document.getElementById('order-quantity-decrease');
  const orderQuantityIncreaseButton = document.getElementById('order-quantity-increase');
  const orderQuantityInput = document.getElementById('order-quantity');
  const orderQuantityHelp = document.getElementById('order-quantity-help');
  const selectedPrice = document.getElementById("selected-price");
  const selectedPriceDetail = document.getElementById('selected-price-detail');
  const selectedWinPointsTotal = document.getElementById('selected-win-points-total');
  const paymentDifferenceBanner = document.getElementById('payment-difference-banner');
  const publicOrderSummaryShell = document.getElementById('public-order-summary-shell');
  const publicOrderSummaryCoupon = document.getElementById('public-order-summary-coupon');
  const publicOrderSummaryCouponCopy = document.getElementById('public-order-summary-coupon-copy');
  const publicOrderSummaryPanel = document.getElementById('public-order-summary-panel');
  const publicOrderSummaryMethod = document.getElementById('public-order-summary-method');
  const publicOrderSummaryRows = document.getElementById('public-order-summary-rows');
  const publicOrderSummaryTotal = document.getElementById('public-order-summary-total');
  const orderForm = document.getElementById("order-form");
  const orderEmailInput = orderForm ? orderForm.querySelector('input[name="email"]') : null;
  const buyButton = document.getElementById("buy-button");
  const accountSaleNote = document.getElementById('account-sale-note');
  const defaultBuyButtonLabel = 'Comprar Ahora';
  const paymentDifferenceBlockedBuyButtonLabel = 'Selecciona un paquete mayor al saldo a favor';
  const defaultPaymentSubmitButtonLabel = 'Confirmar / Recargar';
  const completeRechargeButtonLabel = 'Completar Recarga';
  const verifyUserBuyButtonLabel = 'Debe Verificar El usuario para poder comprar';
  const playerPrimaryField = document.getElementById('player-primary-field');
  const playerPrimaryLabel = document.getElementById('player-primary-label');
  let playerPrimaryInput = document.getElementById('order-user-id');
  const extraPlayerFields = document.getElementById('extra-player-fields');
  const verifyPlayerButton = document.getElementById('verify-player-button');
  const playerVerificationFeedback = document.getElementById('player-verification-feedback');
  const playerVerificationConfig = <?= json_encode($playerVerificationConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const couponInput = document.getElementById('coupon-input');
  const couponModal = document.getElementById('coupon-modal');
  const loadingModal = document.getElementById('loading-modal');
  const loadingModalTitle = document.getElementById('loading-modal-title');
  const loadingModalMessage = document.getElementById('loading-modal-message');
  const paymentWindowThemeEnabled = <?php echo $paymentWindowConfigEnabled ? 'true' : 'false'; ?>;
  const paymentSendingOrderContent = {
    title: <?php echo json_encode($paymentSendingOrderTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    message: <?php echo json_encode($paymentSendingOrderMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
  };
  const paymentDifferenceFeatureEnabled = <?= $paymentDifferenceEnabled ? 'true' : 'false' ?>;
  const gameEntryWindowEnabled = <?= !empty($gameEntryWindowPayload['enabled']) ? 'true' : 'false' ?>;
  const currentGameName = <?= json_encode((string) ($game['nombre'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paymentSuccessContent = {
    title: <?php echo json_encode($paymentSuccessTitle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    extraMessage: <?php echo json_encode($paymentSuccessExtraMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
  };
  let paymentDifferenceCreditState = <?= json_encode($activePaymentDifferenceCredit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let publicCheckoutSummaryTotalText = '';
  let publicCheckoutSummaryAnimationKey = '';
  let appliedCouponSummary = {
    code: '',
    discountAmount: 0,
    originalAmount: 0,
    discountType: '',
    discountValue: 0,
  };
  const paymentStatusModal = document.getElementById('payment-status-modal');
  const paymentStatusModalTitle = document.getElementById('payment-status-modal-title');
  const paymentStatusModalMessage = document.getElementById('payment-status-modal-message');
  const paymentStatusModalExtraMessage = document.getElementById('payment-status-modal-extra-message');
  const paymentStatusModalReasons = document.getElementById('payment-status-modal-reasons');
  const paymentStatusModalActions = document.getElementById('payment-status-modal-actions');
  const paymentStatusModalAccept = document.getElementById('payment-status-modal-accept');
  const defaultPaymentStatusAcceptLabel = paymentStatusModalAccept ? paymentStatusModalAccept.textContent : 'Aceptar';
  const modalCouponName = document.getElementById('modal-coupon-name');
  const modalYes = document.getElementById('modal-yes');
  const modalNo = document.getElementById('modal-no');
  const modalCancel = document.getElementById('modal-cancel');
  const applyCouponButton = document.getElementById('apply-coupon-btn');
  const paymentModal = document.getElementById('payment-modal');
  const paymentModalContent = paymentModal ? paymentModal.querySelector('.payment-modal-content') : null;
  const paymentModalAlert = document.getElementById('payment-modal-alert');
  const paymentModalReasons = document.getElementById('payment-modal-reasons');
  const paymentModalActions = document.getElementById('payment-modal-actions');
  const accountGalleryModal = document.getElementById('account-gallery-modal');
  const accountGalleryModalTitle = document.getElementById('account-gallery-modal-title');
  const accountGalleryModalPrice = document.getElementById('account-gallery-modal-price');
  const accountGalleryModalImage = document.getElementById('account-gallery-modal-image');
  const accountGalleryModalPlaceholder = document.getElementById('account-gallery-modal-placeholder');
  const accountGalleryModalCaption = document.getElementById('account-gallery-modal-caption');
  const accountGalleryModalThumbs = document.getElementById('account-gallery-modal-thumbs');
  const accountGalleryModalClose = document.getElementById('account-gallery-modal-close');
  const accountGalleryModalBuy = document.getElementById('account-gallery-modal-buy');
  const gameEntryWindowModal = document.getElementById('game-entry-window-modal');
  const gameEntryWindowConfirmation = document.getElementById('game-entry-window-confirmation');
  const gameEntryWindowCheckbox = document.getElementById('game-entry-window-check');
  const gameEntryWindowContinueButton = document.getElementById('game-entry-window-continue');

  function buildAppUrl(path) {
    const normalizedPath = String(path || '').startsWith('/') ? String(path || '') : `/${String(path || '')}`;
    return `${appBasePath}${normalizedPath}`;
  }
  let paymentStatusPollTimer = null;
  const paymentTimerValue = document.getElementById('payment-timer-value');
  const paymentSummaryCard = document.querySelector('.payment-summary-card');
  const paymentSummaryUser = document.getElementById('payment-summary-user');
  const paymentSummaryProduct = document.getElementById('payment-summary-product');
  const paymentSummaryTotal = document.getElementById('payment-summary-total');
  const paymentSummaryDiscount = document.getElementById('payment-summary-discount');
  const paymentSummaryMinimalUser = document.getElementById('payment-summary-minimal-user');
  const paymentSummaryMinimalProduct = document.getElementById('payment-summary-minimal-product');
  const paymentSummaryMinimalTotal = document.getElementById('payment-summary-minimal-total');
  const paymentSummaryImage = document.getElementById('payment-summary-image');
  const paymentSummaryImagePlaceholder = document.getElementById('payment-summary-image-placeholder');
  const paymentSummaryFeatures = document.getElementById('payment-summary-features');
  const paymentMethodSelectWrap = document.getElementById('payment-method-select-wrap');
  const paymentMethodSelect = document.getElementById('payment-method-select');
  const paymentMethodCard = document.getElementById('payment-method-card');
  const paymentMethodTitle = document.getElementById('payment-method-title');
  const paymentMethodCurrency = document.getElementById('payment-method-currency');
  const paymentMethodDetails = document.getElementById('payment-method-details');
  const paymentMethodQrWrap = document.getElementById('payment-method-qr-wrap');
  const paymentMethodQrImage = document.getElementById('payment-method-qr-image');
  const paymentMethodDiscount = document.getElementById('payment-method-discount');
  const paymentWinPointsCard = document.getElementById('payment-win-points-card');
  const paymentWinPointsTitle = document.getElementById('payment-win-points-title');
  const paymentWinPointsCopy = document.getElementById('payment-win-points-copy');
  const paymentModeOptions = document.getElementById('payment-mode-options');
  const paymentMoneyPanel = document.getElementById('payment-money-panel');
  const paymentWinPointsBalance = document.getElementById('payment-win-points-balance');
  const paymentReferenceGroup = document.getElementById('payment-reference-group');
  const paymentReferenceInput = document.getElementById('payment-reference-input');
  const paymentReferenceHelp = document.getElementById('payment-reference-help');
  const paymentPhoneGroup = document.getElementById('payment-phone-group');
  const paymentPhoneInput = document.getElementById('payment-phone-input');
  const paymentMethodCatalogCopy = document.getElementById('payment-method-catalog-copy');
  const paymentMethodCatalogGrid = document.getElementById('payment-method-catalog-grid');
  const paymentSubmitButton = document.getElementById('payment-submit-btn');
  const paymentCancelOrderButton = document.getElementById('payment-cancel-order-btn');
  const paymentCancelConfirmModal = document.getElementById('payment-cancel-confirm-modal');
  const paymentCancelDismissButton = document.getElementById('payment-cancel-dismiss-btn');
  const paymentCancelConfirmButton = document.getElementById('payment-cancel-confirm-btn');
  let lastFocusedElement = null;
  let activePack = null;
  let activeAccountGalleryPreview = { pack: null, index: 0 };
  let selectedTotalValue = 0;
  let couponApplied = false;
  let couponValue = '';
  let activePaymentOrder = null;
  let paymentTimerInterval = null;
  let preferredCheckoutPaymentMode = '';
  let preferredCheckoutMethodId = '';
  let paymentDifferenceTicker = null;
  let gameEntryWindowAccepted = !gameEntryWindowEnabled;
  const defaultPrimaryField = {
    name: 'id_juego',
    label: 'ID de usuario',
    placeholder: 'Ej: 12345678',
    inputMode: 'text',
    maxLength: 150
  };

  function normalizeOrderQuantity(value) {
    const digitsOnly = String(value ?? '').replace(/\D+/g, '');
    const parsedValue = parseInt(digitsOnly, 10);
    return Number.isFinite(parsedValue) && parsedValue > 0 ? parsedValue : 1;
  }

  function getOrderQuantity() {
    if (activePack && isAccountSalePack(activePack)) {
      return 1;
    }
    return orderQuantityInput ? normalizeOrderQuantity(orderQuantityInput.value) : 1;
  }

  function getOrderQuantityBreakdownText(pack, quantity) {
    if (!pack) {
      return 'Selecciona un paquete para indicar la cantidad.';
    }

    if (isAccountSalePack(pack)) {
      return 'La compra de cuentas siempre es de 1 unidad.';
    }

    const safeQuantity = normalizeOrderQuantity(quantity);
    if (shouldDisplayPackTotalInPoints(pack)) {
      return `${safeQuantity} x ${formatWinPointsAmount(getPackRequiredPoints(pack, 1))}`;
    }

    const unitAmount = formatCurrencyAmount(Number(pack.priceValue || 0), Boolean(pack.showDecimals));
    const currencyCode = String(pack.moneda || monedaActualClave || '').trim();
    return currencyCode !== ''
      ? `${safeQuantity} x ${unitAmount} ${currencyCode}`
      : `${safeQuantity} x ${unitAmount}`;
  }

  function syncOrderQuantityInput(nextValue = null) {
    const quantityEnabled = Boolean(activePack) && !isAccountSalePack(activePack);
    const resolvedValue = quantityEnabled
      ? normalizeOrderQuantity(nextValue === null ? getOrderQuantity() : nextValue)
      : 1;
    if (orderQuantityInput) {
      orderQuantityInput.value = String(resolvedValue);
      orderQuantityInput.disabled = !quantityEnabled;
    }
    if (orderQuantityDecreaseButton) {
      orderQuantityDecreaseButton.disabled = !quantityEnabled;
    }
    if (orderQuantityIncreaseButton) {
      orderQuantityIncreaseButton.disabled = !quantityEnabled;
    }
    if (orderQuantityHelp) {
      orderQuantityHelp.textContent = getOrderQuantityBreakdownText(activePack, resolvedValue);
    }
    if (purchaseQuantityPanel) {
      purchaseQuantityPanel.classList.toggle('d-none', Boolean(activePack) && isAccountSalePack(activePack));
    }
    if (purchaseSummaryLayout) {
      purchaseSummaryLayout.classList.toggle(
        'purchase-summary-layout-single',
        !purchaseQuantityPanel || (Boolean(activePack) && isAccountSalePack(activePack))
      );
    }
    return resolvedValue;
  }

  function getPackTotalPrice(pack, quantity = getOrderQuantity()) {
    if (!pack) {
      return 0;
    }

    return normalizeCurrencyAmount(Number(pack.priceValue || 0) * normalizeOrderQuantity(quantity), pack.showDecimals);
  }

  function normalizeDiscountPercentage(value) {
    const numericValue = Number(String(value ?? '').replace(',', '.'));
    if (!Number.isFinite(numericValue) || numericValue <= 0) {
      return 0;
    }
    return Math.min(100, Math.round(numericValue * 100) / 100);
  }

  function formatDiscountPercentage(value) {
    const normalized = normalizeDiscountPercentage(value);
    if (normalized <= 0) {
      return '0%';
    }
    return `${String(normalized.toFixed(2)).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1')}%`;
  }

  function getPackRewardPoints(pack, quantity = getOrderQuantity()) {
    return Math.max(0, Number(pack && pack.rewardPoints ? pack.rewardPoints : 0)) * normalizeOrderQuantity(quantity);
  }

  function getPackRequiredPoints(pack, quantity = getOrderQuantity()) {
    return Math.max(0, Number(pack && pack.redeemRequiredPoints ? pack.redeemRequiredPoints : 0)) * normalizeOrderQuantity(quantity);
  }

  function shouldDisplayPackTotalInPoints(pack = activePack) {
    return Boolean(pack && resolvePreferredCheckoutSelection(pack).mode === 'points' && getPackRequiredPoints(pack) > 0);
  }

  function getCurrencyShowDecimals(currencyCode, fallback = monedaActualMostrarDecimales) {
    const target = String(currencyCode || '').trim().toUpperCase();
    if (target === '') {
      return fallback;
    }

    const currencyEntry = Object.values(monedas).find((item) => String(item && item.clave ? item.clave : '').trim().toUpperCase() === target);
    return currencyEntry ? Boolean(currencyEntry.mostrar_decimales) : fallback;
  }

  function normalizeCurrencyAlias(currencyCode) {
    const normalized = String(currencyCode || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '');
    if (!normalized) {
      return '';
    }

    if (
      normalized === 'BS'
      || normalized === 'BSS'
      || normalized.includes('VES')
      || normalized.includes('VEF')
      || normalized.includes('BOLIVAR')
      || normalized.includes('BOLIVARES')
      || normalized.endsWith('BS')
    ) {
      return 'VES';
    }

    return normalized;
  }

  function findCurrencyEntryByCode(currencyCode) {
    const rawTarget = String(currencyCode || '').trim().toUpperCase();
    const normalizedTarget = normalizeCurrencyAlias(currencyCode);
    const matchedEntry = Object.entries(monedas).find(([currencyId, item]) => {
      const rawCode = String(item && item.clave ? item.clave : '').trim().toUpperCase();
      return (rawTarget !== '' && rawCode === rawTarget) || (normalizedTarget !== '' && normalizeCurrencyAlias(rawCode) === normalizedTarget);
    });

    if (!matchedEntry) {
      return null;
    }

    return {
      id: String(matchedEntry[0] || '').trim(),
      ...(matchedEntry[1] || {}),
    };
  }

  function resolvePreferredDisplayCurrencyCode(mode, methodId = '', pack = activePack) {
    if (mode === 'money') {
      const methods = getPaymentMethodsForCurrency(pack ? pack.moneda : '');
      const selectedMethod = methods.find((method) => String(method.id) === String(methodId || '')) || null;
      return String(selectedMethod && selectedMethod.moneda_clave ? selectedMethod.moneda_clave : '').trim().toUpperCase();
    }

    if (mode === 'binance') {
      const preferredEntry = resolvePreferredBinanceCurrencyEntry();
      return String(preferredEntry && preferredEntry.clave ? preferredEntry.clave : '').trim().toUpperCase();
    }

    if (mode === 'paypal') {
      if (pack && pack.moneda) {
        return String(pack.moneda).trim().toUpperCase();
      }

      const preferredEntry = resolvePreferredPayPalCurrencyEntry();
      return String(preferredEntry && preferredEntry.clave ? preferredEntry.clave : '').trim().toUpperCase();
    }

    return '';
  }

  function syncVisibleCurrencyWithPreferredPayment(pack = activePack, options = {}) {
    const targetCurrencyCode = resolvePreferredDisplayCurrencyCode(preferredCheckoutPaymentMode, preferredCheckoutMethodId, pack);
    if (targetCurrencyCode === '') {
      return false;
    }

    const entry = findCurrencyEntryByCode(targetCurrencyCode);
    if (!entry || !entry.id) {
      return false;
    }

    return setVisibleCurrency(entry.id, options);
  }

  function resolvePreferredBinanceCurrencyEntry() {
    const currencyEntries = Object.values(monedas || {});
    if (!currencyEntries.length) {
      return null;
    }

    for (const preferredCode of ['USDT', 'USD', 'EUR', 'BRL', 'COP', 'MXN', 'CLP', 'PEN']) {
      const entry = findCurrencyEntryByCode(preferredCode);
      if (entry) {
        return entry;
      }
    }

    return currencyEntries.find((entry) => normalizeCurrencyAlias(entry && entry.clave ? entry.clave : '') !== 'VES') || currencyEntries[0] || null;
  }

  function resolvePreferredPayPalCurrencyEntry() {
    if (!Array.isArray(paypalSupportedCurrencies) || !paypalSupportedCurrencies.length) {
      return null;
    }

    const currentEntry = findCurrencyEntryByCode(monedaActualClave || '');
    if (currentEntry && paypalSupportedCurrencies.includes(String(currentEntry.clave || '').trim().toUpperCase())) {
      return currentEntry;
    }

    for (const preferredCode of ['USD', 'EUR', 'GBP', 'BRL', 'MXN', 'COP', 'CLP', 'PEN']) {
      if (!paypalSupportedCurrencies.includes(preferredCode)) {
        continue;
      }

      const entry = findCurrencyEntryByCode(preferredCode);
      if (entry) {
        return entry;
      }
    }

    for (const supportedCode of paypalSupportedCurrencies) {
      const entry = findCurrencyEntryByCode(supportedCode);
      if (entry) {
        return entry;
      }
    }

    return null;
  }

  function convertCurrencyAmountBetweenCodes(amount, fromCode, toCode) {
    const numericAmount = Number(amount || 0);
    if (!Number.isFinite(numericAmount) || numericAmount <= 0) {
      return 0;
    }

    const targetEntry = findCurrencyEntryByCode(toCode);
    if (!targetEntry) {
      return numericAmount;
    }

    const fromNormalized = normalizeCurrencyAlias(fromCode);
    const toNormalized = normalizeCurrencyAlias(toCode);
    if (fromNormalized !== '' && fromNormalized === toNormalized) {
      return normalizeCurrencyAmount(numericAmount, Boolean(targetEntry.mostrar_decimales));
    }

    const sourceEntry = findCurrencyEntryByCode(fromCode);
    if (!sourceEntry) {
      return normalizeCurrencyAmount(numericAmount, Boolean(targetEntry.mostrar_decimales));
    }

    const sourceRate = Number(sourceEntry.tasa || 0);
    const targetRate = Number(targetEntry.tasa || 0);
    if (!Number.isFinite(sourceRate) || sourceRate <= 0 || !Number.isFinite(targetRate) || targetRate <= 0) {
      return normalizeCurrencyAmount(numericAmount, Boolean(targetEntry.mostrar_decimales));
    }

    const baseAmount = numericAmount / sourceRate;
    return normalizeCurrencyAmount(baseAmount * targetRate, Boolean(targetEntry.mostrar_decimales));
  }

  function resolveBinanceDisplayMoney(pack, sourceAmountOverride = null) {
    const targetEntry = resolvePreferredBinanceCurrencyEntry();
    const sourceCurrency = String((pack && pack.moneda) || monedaActualClave || '').trim().toUpperCase();
    const sourceAmount = sourceAmountOverride === null
      ? Number(pack ? getPackTotalPrice(pack, Number(pack.purchaseQuantity || getOrderQuantity())) : 0)
      : Number(sourceAmountOverride || 0);
    if (!targetEntry) {
      return {
        currency: sourceCurrency,
        amount: normalizeCurrencyAmount(sourceAmount, Boolean(pack && pack.showDecimals)),
        text: formatPaymentDifferenceMoney(sourceCurrency, sourceAmount, pack && pack.showDecimals),
      };
    }

    const targetCurrency = String(targetEntry.clave || '').trim().toUpperCase();
    const amount = convertCurrencyAmountBetweenCodes(sourceAmount, sourceCurrency, targetCurrency);
    return {
      currency: targetCurrency,
      amount,
      text: formatPaymentDifferenceMoney(targetCurrency, amount, Boolean(targetEntry.mostrar_decimales)),
    };
  }

  function formatPaymentDifferenceMoney(currencyCode, amount, showDecimals = null) {
    const useDecimals = showDecimals === null ? getCurrencyShowDecimals(currencyCode) : Boolean(showDecimals);
    return `${String(currencyCode || '').trim().toUpperCase() || monedaActualClave} ${formatCurrencyAmount(amount, useDecimals)}`;
  }

  function formatPaymentDifferenceDuration(totalSeconds) {
    const normalizedSeconds = Math.max(0, Math.floor(Number(totalSeconds || 0)));
    const minutes = Math.floor(normalizedSeconds / 60);
    const seconds = normalizedSeconds % 60;
    if (minutes <= 0) {
      return `${seconds}s`;
    }
    if (seconds === 0) {
      return `${minutes} min`;
    }
    return `${minutes} min ${String(seconds).padStart(2, '0')}s`;
  }

  function normalizePaymentDifferenceCredit(rawCredit) {
    if (!paymentDifferenceFeatureEnabled || !rawCredit || typeof rawCredit !== 'object') {
      return null;
    }

    const availableAmount = normalizeCurrencyAmount(rawCredit.available_amount ?? rawCredit.overpayment_amount ?? 0, true);
    const currency = String(rawCredit.currency || '').trim().toUpperCase();
    const sourceOrderId = Number(rawCredit.source_order_id || 0);
    const remainingSeconds = Math.max(0, Math.floor(Number(rawCredit.remaining_seconds || 0)));

    if (!Number.isFinite(availableAmount) || availableAmount <= 0 || currency === '') {
      return null;
    }

    return {
      availableAmount,
      currency,
      sourceOrderId,
      remainingSeconds,
      status: String(rawCredit.status || '').trim().toLowerCase(),
      message: String(rawCredit.message || '').trim(),
    };
  }

  function getPaymentDifferenceBreakdown(pack, baseAmount = selectedTotalValue) {
    const currency = String((pack && pack.moneda) || monedaActualClave || '').trim().toUpperCase();
    const showDecimals = pack ? Boolean(pack.showDecimals) : getCurrencyShowDecimals(currency);
    const subtotalAmount = normalizeCurrencyAmount(baseAmount, showDecimals);
    const credit = paymentDifferenceCreditState
      && paymentDifferenceCreditState.currency === currency
      && Number(paymentDifferenceCreditState.remainingSeconds || 0) > 0
      ? paymentDifferenceCreditState
      : null;
    const appliedAmount = credit ? normalizeCurrencyAmount(Math.min(Number(credit.availableAmount || 0), subtotalAmount), showDecimals) : 0;

    return {
      currency,
      showDecimals,
      subtotalAmount,
      appliedAmount,
      finalAmount: normalizeCurrencyAmount(Math.max(subtotalAmount - appliedAmount, 0), showDecimals),
      hasCredit: Boolean(credit),
      availableAmount: credit ? normalizeCurrencyAmount(credit.availableAmount, showDecimals) : 0,
      remainingSeconds: credit ? Number(credit.remainingSeconds || 0) : 0,
      sourceOrderId: credit ? Number(credit.sourceOrderId || 0) : 0,
      blocksSelection: Boolean(credit && subtotalAmount > 0 && Number(credit.availableAmount || 0) + 0.0001 >= subtotalAmount),
      message: credit ? String(credit.message || '').trim() : '',
    };
  }

  function updateSelectedPriceDisplay(pack) {
    if (!pack) {
      selectedPrice.textContent = `${monedaActualClave} ${formatCurrencyAmount(0, monedaActualMostrarDecimales)}`;
      if (selectedPriceDetail) {
        selectedPriceDetail.textContent = '';
        selectedPriceDetail.classList.add('d-none');
      }
      refreshPaymentDifferenceBanner(null);
      return;
    }

    if (shouldDisplayPackTotalInPoints(pack)) {
      selectedPrice.textContent = formatWinPointsAmount(getPackRequiredPoints(pack, Number(pack.purchaseQuantity || getOrderQuantity())));
      if (selectedPriceDetail) {
        selectedPriceDetail.textContent = '';
        selectedPriceDetail.classList.add('d-none');
      }
      refreshPaymentDifferenceBanner(pack);
      return;
    }

    const breakdown = getPaymentDifferenceBreakdown(pack, selectedTotalValue);
    selectedPrice.textContent = formatPaymentDifferenceMoney(pack.moneda || monedaActualClave, breakdown.finalAmount, breakdown.showDecimals);

    if (selectedPriceDetail) {
      if (breakdown.appliedAmount > 0) {
        selectedPriceDetail.textContent = `Original ${formatPaymentDifferenceMoney(pack.moneda || monedaActualClave, breakdown.subtotalAmount, breakdown.showDecimals)} | Saldo aplicado ${formatPaymentDifferenceMoney(pack.moneda || monedaActualClave, breakdown.appliedAmount, breakdown.showDecimals)}`;
        selectedPriceDetail.classList.remove('d-none');
      } else {
        selectedPriceDetail.textContent = '';
        selectedPriceDetail.classList.add('d-none');
      }
    }

    refreshPaymentDifferenceBanner(pack);
  }

  function refreshPaymentDifferenceBanner(pack = activePack) {
    if (!paymentDifferenceBanner) {
      return;
    }

    if (shouldDisplayPackTotalInPoints(pack)) {
      paymentDifferenceBanner.className = 'd-none payment-difference-banner mt-3';
      paymentDifferenceBanner.innerHTML = '';
      return;
    }

    const activeCredit = normalizePaymentDifferenceCredit(paymentDifferenceCreditState);
    if (!paymentDifferenceFeatureEnabled || !activeCredit) {
      paymentDifferenceBanner.className = 'd-none payment-difference-banner mt-3';
      paymentDifferenceBanner.innerHTML = '';
      return;
    }

    const breakdown = getPaymentDifferenceBreakdown(pack, selectedTotalValue);
    const title = breakdown.blocksSelection
      ? 'Selecciona un paquete mayor al saldo a favor'
      : 'Saldo a favor disponible para una sola recarga';
    let summary = activeCredit.message || 'Puedes usar este monto restante una sola vez antes de que expire.';
    if (pack && breakdown.hasCredit && !breakdown.blocksSelection) {
      summary = `Se aplicarán ${formatPaymentDifferenceMoney(breakdown.currency, breakdown.appliedAmount, breakdown.showDecimals)} a este paquete. Solo pagarás ${formatPaymentDifferenceMoney(breakdown.currency, breakdown.finalAmount, breakdown.showDecimals)}.`;
    } else if (pack && breakdown.blocksSelection) {
      summary = `Tu saldo a favor actual es ${formatPaymentDifferenceMoney(breakdown.currency, breakdown.availableAmount, breakdown.showDecimals)}. Debes elegir un paquete cuyo total original sea mayor a ese monto.`;
    }

    const details = [
      `<div><strong>Disponible:</strong> ${escapePaymentHtml(formatPaymentDifferenceMoney(activeCredit.currency, activeCredit.availableAmount, getCurrencyShowDecimals(activeCredit.currency)))}</div>`,
      `<div><strong>Vence en:</strong> ${escapePaymentHtml(formatPaymentDifferenceDuration(activeCredit.remainingSeconds))}</div>`,
      `<div><strong>Pedido origen:</strong> #${escapePaymentHtml(String(activeCredit.sourceOrderId || '-'))}</div>`
    ];

    paymentDifferenceBanner.className = 'payment-difference-banner mt-3';
    paymentDifferenceBanner.dataset.variant = breakdown.blocksSelection ? 'warning' : 'active';
    paymentDifferenceBanner.innerHTML = `
      <div class="payment-difference-banner-title">${escapePaymentHtml(title)}</div>
      <div class="payment-difference-banner-copy">${escapePaymentHtml(summary)}</div>
      <div class="payment-difference-breakdown">${details.join('')}</div>
    `;
  }

  function startPaymentDifferenceTicker() {
    if (paymentDifferenceTicker) {
      clearInterval(paymentDifferenceTicker);
      paymentDifferenceTicker = null;
    }

    if (!normalizePaymentDifferenceCredit(paymentDifferenceCreditState)) {
      return;
    }

    paymentDifferenceTicker = window.setInterval(() => {
      const normalizedCredit = normalizePaymentDifferenceCredit(paymentDifferenceCreditState);
      if (!normalizedCredit) {
        clearInterval(paymentDifferenceTicker);
        paymentDifferenceTicker = null;
        paymentDifferenceCreditState = null;
        refreshPaymentDifferenceBanner(activePack);
        updateButtonState();
        return;
      }

      normalizedCredit.remainingSeconds = Math.max(0, normalizedCredit.remainingSeconds - 1);
      paymentDifferenceCreditState = normalizedCredit.remainingSeconds > 0 ? normalizedCredit : null;
      refreshPaymentDifferenceBanner(activePack);
      if (activePack) {
        updateSelectedPriceDisplay(activePack);
      }
      updateButtonState();
    }, 1000);
  }

  function setPaymentDifferenceCreditState(nextCredit) {
    paymentDifferenceCreditState = normalizePaymentDifferenceCredit(nextCredit);
    startPaymentDifferenceTicker();
    refreshPaymentDifferenceBanner(activePack);
    if (activePack) {
      updateSelectedPriceDisplay(activePack);
    }
    updateButtonState();
  }

  function syncActivePaymentOrderDeadline(remainingSeconds) {
    if (!activePaymentOrder) {
      return;
    }

    const safeRemainingSeconds = Math.max(0, Math.floor(Number(remainingSeconds || 0)));
    if (safeRemainingSeconds <= 0) {
      return;
    }

    activePaymentOrder.expiresAtMs = Date.now() + (safeRemainingSeconds * 1000);
    updatePaymentTimer();
  }

  function buildBinancePopupLoaderHtml() {
    return `<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Abriendo Binance Pay...</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;font-family:Arial,sans-serif;background:#081018;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}.card{max-width:480px;width:100%;background:#111827;border:1px solid #22d3ee;border-radius:18px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.35)}h1{margin:0 0 12px;font-size:24px;color:#22d3ee}p{margin:0 0 12px;line-height:1.6}.spinner{width:44px;height:44px;border-radius:999px;border:4px solid rgba(34,211,238,.18);border-top-color:#22d3ee;animation:spin .9s linear infinite;margin:0 0 20px}@keyframes spin{to{transform:rotate(360deg)}}</style></head><body><div class="card"><div class="spinner"></div><h1>Abriendo Binance Pay...</h1><p>Estamos conectando tu orden con CoinPal para mostrar el checkout de Binance Pay.</p><p>Si el redireccionamiento tarda unos segundos, deja esta ventana abierta.</p></div></body></html>`;
  }

  function buildPayPalPopupLoaderHtml() {
    return `<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Abriendo PayPal...</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;font-family:Arial,sans-serif;background:#081018;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}.card{max-width:480px;width:100%;background:#111827;border:1px solid #60a5fa;border-radius:18px;padding:28px;box-shadow:0 20px 60px rgba(0,0,0,.35)}h1{margin:0 0 12px;font-size:24px;color:#60a5fa}p{margin:0 0 12px;line-height:1.6}.spinner{width:44px;height:44px;border-radius:999px;border:4px solid rgba(96,165,250,.18);border-top-color:#60a5fa;animation:spin .9s linear infinite;margin:0 0 20px}@keyframes spin{to{transform:rotate(360deg)}}</style></head><body><div class="card"><div class="spinner"></div><h1>Abriendo PayPal...</h1><p>Estamos preparando tu orden para mostrar el checkout oficial de PayPal.</p><p>Si el redireccionamiento tarda unos segundos, deja esta ventana abierta.</p></div></body></html>`;
  }

  function openBinanceCheckoutPopup() {
    const popup = window.open('', '_blank');
    if (!popup) {
      return null;
    }

    try {
      popup.opener = null;
      popup.document.open();
      popup.document.write(buildBinancePopupLoaderHtml());
      popup.document.close();
    } catch (_) {
    }

    return popup;
  }

  function openPayPalCheckoutPopup() {
    const popup = window.open('', '_blank');
    if (!popup) {
      return null;
    }

    try {
      popup.opener = null;
      popup.document.open();
      popup.document.write(buildPayPalPopupLoaderHtml());
      popup.document.close();
    } catch (_) {
    }

    return popup;
  }

  function navigateBinanceCheckoutPopup(popup, checkoutUrl) {
    const targetUrl = normalizeCoinpalCheckoutUrl(checkoutUrl);
    if (!targetUrl) {
      return false;
    }

    if (popup && !popup.closed) {
      try {
        popup.location.replace(targetUrl);
        return true;
      } catch (_) {
      }
    }

    const reopened = window.open(targetUrl, '_blank', 'noopener');
    if (reopened) {
      try {
        reopened.opener = null;
      } catch (_) {
      }
      return true;
    }

    return false;
  }

  function navigatePayPalCheckoutPopup(popup, checkoutUrl) {
    const targetUrl = String(checkoutUrl || '').trim();
    if (!targetUrl) {
      return false;
    }

    if (popup && !popup.closed) {
      try {
        popup.location.replace(targetUrl);
        return true;
      } catch (_) {
      }
    }

    const reopened = window.open(targetUrl, '_blank', 'noopener');
    if (reopened) {
      try {
        reopened.opener = null;
      } catch (_) {
      }
      return true;
    }

    return false;
  }

  function normalizeCoinpalCheckoutUrl(checkoutUrl) {
    const targetUrl = String(checkoutUrl || '').trim();
    if (!targetUrl) {
      return '';
    }

    try {
      const parsed = new URL(targetUrl, window.location.origin);
      const host = String(parsed.hostname || '').toLowerCase();
      const path = String(parsed.pathname || '').toLowerCase();
      if ((host === 'pay.coinpal.io' || host.endsWith('.coinpal.io')) && path.includes('/cashier/')) {
        parsed.protocol = 'https:';
        parsed.hostname = 'pay.coinpal.io';
      }
      return parsed.toString();
    } catch (_) {
      return targetUrl;
    }
  }

  function isCoinpalCheckoutUrl(checkoutUrl) {
    const targetUrl = normalizeCoinpalCheckoutUrl(checkoutUrl);
    if (!targetUrl) {
      return false;
    }

    try {
      const parsed = new URL(targetUrl, window.location.origin);
      const host = String(parsed.hostname || '').toLowerCase();
      const path = String(parsed.pathname || '').toLowerCase();
      return host === 'pay.coinpal.io' && path.includes('/cashier/');
    } catch (_) {
      return false;
    }
  }

  async function reopenBinanceCheckout(checkoutUrl, reference, totalText) {
    const popup = openBinanceCheckoutPopup();
    const normalizedCheckoutUrl = normalizeCoinpalCheckoutUrl(checkoutUrl);

    if (isCoinpalCheckoutUrl(normalizedCheckoutUrl)) {
      const opened = navigateBinanceCheckoutPopup(popup, normalizedCheckoutUrl);
      if (opened) {
        return;
      }
    }

    if (!activePaymentOrder || !activePaymentOrder.orderId) {
      if (popup && !popup.closed) {
        popup.close();
      }
      setPaymentAlert('No hay una orden activa para reabrir el checkout de Binance Pay.', 'danger');
      return;
    }

    try {
      const response = await fetch(buildAppUrl('/api/pedidos.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: [
          'action=submit_payment',
          `order_id=${encodeURIComponent(activePaymentOrder.orderId)}`,
          'payment_mode=binance'
        ].join('&')
      });
      const data = await parseApiJsonResponse(response, 'No se pudo reabrir el checkout de Binance Pay en este momento.');
      if (!response.ok || !data.ok) {
        throw new Error((data && data.message) ? data.message : 'No se pudo reabrir el checkout de Binance Pay.');
      }

      const refreshedCheckoutUrl = normalizeCoinpalCheckoutUrl((data && data.checkout_url) || '');
      if (!isCoinpalCheckoutUrl(refreshedCheckoutUrl)) {
        throw new Error('CoinPal no devolvió una URL válida del cashier para Binance Pay.');
      }

      if (data && Number.isFinite(Number(data.remaining_seconds || 0))) {
        syncActivePaymentOrderDeadline(Number(data.remaining_seconds || 0));
      }

      renderBinancePaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, totalText || getConfirmedPaymentTotalText());

      const opened = navigateBinanceCheckoutPopup(popup, refreshedCheckoutUrl);
      if (!opened) {
        throw new Error('No pudimos abrir automáticamente Binance Pay.');
      }
    } catch (error) {
      if (popup && !popup.closed) {
        popup.close();
      }
      setPaymentAlert(normalizeApiRequestErrorMessage(error, 'No se pudo reabrir el checkout de Binance Pay en este momento.'), 'danger');
    }
  }

  async function reopenPayPalCheckout(checkoutUrl, reference, totalText) {
    const popup = openPayPalCheckoutPopup();

    if (String(checkoutUrl || '').trim() !== '') {
      const opened = navigatePayPalCheckoutPopup(popup, checkoutUrl);
      if (opened) {
        return;
      }
    }

    if (!activePaymentOrder || !activePaymentOrder.orderId) {
      if (popup && !popup.closed) {
        popup.close();
      }
      setPaymentAlert('No hay una orden activa para reabrir el checkout de PayPal.', 'danger');
      return;
    }

    try {
      const response = await fetch(buildAppUrl('/api/pedidos.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: [
          'action=submit_payment',
          `order_id=${encodeURIComponent(activePaymentOrder.orderId)}`,
          'payment_mode=paypal'
        ].join('&')
      });
      const data = await parseApiJsonResponse(response, 'No se pudo reabrir el checkout de PayPal en este momento.');
      if (!response.ok || !data.ok) {
        throw new Error((data && data.message) ? data.message : 'No se pudo reabrir el checkout de PayPal.');
      }

      const refreshedCheckoutUrl = String((data && data.checkout_url) || '').trim();
      if (refreshedCheckoutUrl === '') {
        throw new Error('PayPal no devolvió una URL válida para continuar el checkout.');
      }

      if (data && Number.isFinite(Number(data.remaining_seconds || 0))) {
        syncActivePaymentOrderDeadline(Number(data.remaining_seconds || 0));
      }

      renderPayPalPaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, totalText || getConfirmedPaymentTotalText());

      const opened = navigatePayPalCheckoutPopup(popup, refreshedCheckoutUrl);
      if (!opened) {
        throw new Error('No pudimos abrir automáticamente PayPal.');
      }
    } catch (error) {
      if (popup && !popup.closed) {
        popup.close();
      }
      setPaymentAlert(normalizeApiRequestErrorMessage(error, 'No se pudo reabrir el checkout de PayPal en este momento.'), 'danger');
    }
  }

  function setPaymentStatusAcceptHidden(isHidden) {
    if (!paymentStatusModalAccept) {
      return;
    }

    paymentStatusModalAccept.classList.toggle('d-none', !!isHidden);
    if (isHidden) {
      paymentStatusModalAccept.setAttribute('aria-hidden', 'true');
    } else {
      paymentStatusModalAccept.removeAttribute('aria-hidden');
    }
  }

  function renderPaymentActionButtons(actions, options = {}) {
    const variant = options && (options.variant === 'underpaid' || options.variant === 'overpaid')
      ? options.variant
      : '';
    const hideDefaultStatusAccept = !!(options && options.hideDefaultStatusAccept);

    const applyActions = (container) => {
      if (!container) {
        return;
      }

      container.innerHTML = '';
      if (!Array.isArray(actions) || actions.length === 0) {
        container.className = 'd-none payment-support-actions mb-4';
        container.removeAttribute('data-payment-difference-variant');
        return;
      }

      container.className = 'payment-support-actions payment-difference-actions mb-4';
      if (variant !== '') {
        container.setAttribute('data-payment-difference-variant', variant);
      } else {
        container.removeAttribute('data-payment-difference-variant');
      }
      actions.forEach((action) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn ${action.className || 'btn-info'} fw-bold payment-difference-action-btn`;
        button.textContent = action.label;
        button.addEventListener('click', action.onClick);
        container.appendChild(button);
      });
    };

    applyActions(paymentModalActions);
    applyActions(paymentStatusModalActions);
    setPaymentStatusAcceptHidden(hideDefaultStatusAccept);
  }

  function prepareSameOrderCompletion(message) {
    if (paymentStatusModal) {
      setOverlayVisible(paymentStatusModal, false);
    }
    setPaymentFormDisabled(false);
    setCancelOrderButtonMode('cancel');
    if (paymentSubmitButton) {
      paymentSubmitButton.textContent = completeRechargeButtonLabel;
    }
    if (paymentReferenceInput) {
      paymentReferenceInput.value = '';
      paymentReferenceInput.focus();
    }
    setPaymentAlert(message || 'Realiza el pago restante y luego registra la nueva referencia para completar esta recarga.', 'warning');
    scrollPaymentSubmitIntoView();
  }

  async function activatePaymentDifferenceCreditForCurrentOrder() {
    if (!activePaymentOrder || !activePaymentOrder.orderId) {
      showToast('No hay un pedido válido para activar el saldo a favor.', 'error');
      return;
    }

    setOverlayVisible(loadingModal, true);
    setLoadingModalContent('Activando saldo a favor...', 'Estamos preparando tu saldo a favor para completar otra recarga.', 'processing');

    try {
      const response = await fetch(buildAppUrl('/api/pedidos.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=activate_payment_difference_credit&order_id=${encodeURIComponent(activePaymentOrder.orderId)}`
      });
      const data = await parseApiJsonResponse(response, 'No se pudo activar el saldo a favor en este momento.');
      if (!response.ok || !data.ok) {
        throw new Error((data && data.message) ? data.message : 'No se pudo activar el saldo a favor.');
      }

      setPaymentDifferenceCreditState(data && data.payment_difference ? data.payment_difference : null);
      setOverlayVisible(loadingModal, false);
      if (paymentStatusModal) {
        setOverlayVisible(paymentStatusModal, false);
      }
      closePaymentModal(true);
      resetCheckoutState();
      showToast((data && data.message) ? data.message : 'Saldo a favor activado.', 'success');
      scrollToOrderForm();
    } catch (error) {
      setOverlayVisible(loadingModal, false);
      const errorMessage = normalizeApiRequestErrorMessage(error, 'No se pudo activar el saldo a favor en este momento.');
      setPaymentAlert(errorMessage, 'danger');
      showPaymentStatusModal('No se pudo activar el saldo a favor', errorMessage, 'danger');
    }
  }

  function renderUnderpaidPaymentDifference(data) {
    const difference = data && data.payment_difference ? data.payment_difference : null;
    if (!difference || String(difference.status || '').toLowerCase() !== 'underpaid') {
      return false;
    }

    const currency = String(difference.currency || (activePaymentOrder ? activePaymentOrder.currency : monedaActualClave)).trim().toUpperCase() || monedaActualClave;
    const showDecimals = activePaymentOrder && activePaymentOrder.pack ? Boolean(activePaymentOrder.pack.showDecimals) : getCurrencyShowDecimals(currency);
    const expectedTotal = normalizeCurrencyAmount(difference.expected_total || 0, showDecimals);
    const paidTotal = normalizeCurrencyAmount(difference.paid_total || 0, showDecimals);
    const remainingAmount = normalizeCurrencyAmount(difference.remaining_amount || 0, showDecimals);
    const summary = `Recibimos ${formatPaymentDifferenceMoney(currency, paidTotal, showDecimals)} de ${formatPaymentDifferenceMoney(currency, expectedTotal, showDecimals)}. Falta ${formatPaymentDifferenceMoney(currency, remainingAmount, showDecimals)} para completar esta misma recarga.`;

    syncActivePaymentOrderDeadline(data.remaining_seconds || difference.remaining_seconds || 0);
    renderSupportCard(paymentModalReasons, 'Pago recibido parcialmente', summary, [
      'Realiza otro pago por el monto restante para este mismo pedido.',
      'Ingresa la nueva referencia cuando el banco la refleje.',
      'No necesitas crear otra orden para completar esta recarga.'
    ], [], { variant: 'underpaid' });
    renderSupportCard(paymentStatusModalReasons, 'Pago recibido parcialmente', summary, [
      'Realiza otro pago por el monto restante para este mismo pedido.',
      'Ingresa la nueva referencia cuando el banco la refleje.',
      'No necesitas crear otra orden para completar esta recarga.'
    ], [], { variant: 'underpaid' });
    renderPaymentActionButtons([
      {
        label: completeRechargeButtonLabel,
        className: 'btn-info',
        onClick: () => prepareSameOrderCompletion('Realiza el pago restante y registra la nueva referencia para completar esta recarga.')
      }
    ], { variant: 'underpaid', hideDefaultStatusAccept: true });
    if (paymentSubmitButton) {
      paymentSubmitButton.textContent = completeRechargeButtonLabel;
    }
    setPaymentAlert(data.message || 'Tu pago fue recibido parcialmente. Completa el monto restante para procesar la recarga.', 'warning');
    setPaymentFormDisabled(false);
    showPaymentStatusModal('Pago pendiente por completar', data.message || summary, 'info');
    return true;
  }

  function renderOverpaidPaymentDifference(data) {
    const difference = data && data.payment_difference ? data.payment_difference : null;
    if (!difference || String(difference.status || '').toLowerCase() !== 'overpaid') {
      return false;
    }

    const currency = String(difference.currency || (activePaymentOrder ? activePaymentOrder.currency : monedaActualClave)).trim().toUpperCase() || monedaActualClave;
    const showDecimals = activePaymentOrder && activePaymentOrder.pack ? Boolean(activePaymentOrder.pack.showDecimals) : getCurrencyShowDecimals(currency);
    const overpaymentAmount = normalizeCurrencyAmount(difference.overpayment_amount || 0, showDecimals);
    if (overpaymentAmount <= 0) {
      return false;
    }

    const summary = `Tu pedido principal ya fue atendido y quedó un saldo a favor de ${formatPaymentDifferenceMoney(currency, overpaymentAmount, showDecimals)}.`;
    const steps = difference.can_activate_credit
      ? [
          'Si eliges Seguir con la Recarga, cerramos esta operación sin activar el saldo a favor.',
          'Si eliges Completar Recarga, activaremos el saldo restante durante 30 minutos para usarlo en otro paquete.'
        ]
      : ['Este pedido ya consumió su oportunidad de completar otra recarga con saldo a favor.'];

    if (!extractProviderCodes(data).length) {
      renderSupportCard(paymentModalReasons, 'Se detectó un monto mayor al esperado', summary, steps, [], { variant: 'overpaid' });
    }
    renderSupportCard(paymentStatusModalReasons, 'Se detectó un monto mayor al esperado', summary, steps, [], { variant: 'overpaid' });

    const actions = [
      {
        label: 'Seguir con la Recarga',
        className: 'btn-outline-light',
        onClick: () => {
          if (paymentStatusModal) {
            setOverlayVisible(paymentStatusModal, false);
          }
          closePaymentModal(true);
          resetCheckoutState();
            showToast('Tu recarga continuará con el proceso normal. El saldo a favor no fue activado.', 'success');
        }
      }
    ];

    if (difference.can_activate_credit) {
      actions.unshift({
        label: completeRechargeButtonLabel,
        className: 'btn-success',
        onClick: () => {
          activatePaymentDifferenceCreditForCurrentOrder();
        }
      });
    }

    renderPaymentActionButtons(actions, { variant: 'overpaid', hideDefaultStatusAccept: true });
    return true;
  }

  function restoreStoredPurchaseDefaults(force = false) {
    if (playerPrimaryInput) {
      if (playerPrimaryInput.tagName === 'SELECT') {
        const hasStoredOption = Array.from(playerPrimaryInput.options).some((option) => String(option.value) === String(defaultOrderUserIdentifier || ''));
        if ((force || !playerPrimaryInput.value) && hasStoredOption) {
          playerPrimaryInput.value = defaultOrderUserIdentifier || '';
        }
      } else if (force || playerPrimaryInput.value.trim() === '') {
        playerPrimaryInput.value = defaultOrderUserIdentifier || '';
      }
    }

    if (paymentPhoneInput && (force || paymentPhoneInput.value.trim() === '')) {
      paymentPhoneInput.value = defaultPaymentPhone || '';
    }
  }
  let playerVerificationState = {
    verified: false,
    playerName: '',
    signature: '',
    pending: false,
    serverUnavailable: false,
  };
  let playerVerificationAutoTimer = null;
  let playerVerificationRequestSeq = 0;
  let playerVerificationPendingSignature = '';

  function parseRequiredFields(rawValue) {
    try {
      const parsed = JSON.parse(String(rawValue || '[]'));
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }

  function parsePackageFeatures(rawValue) {
    try {
      const parsed = JSON.parse(String(rawValue || '[]'));
      return Array.isArray(parsed)
        ? parsed.filter((feature) => feature && typeof feature === 'object' && String(feature.name || '').trim() !== '')
        : [];
    } catch (error) {
      return [];
    }
  }

  function resolvePublicImageUrl(rawPath) {
    const trimmed = String(rawPath || '').trim();
    if (trimmed === '') {
      return '';
    }

    if (/^https?:\/\//i.test(trimmed)) {
      return trimmed;
    }

    return buildAppUrl(`/${trimmed.replace(/^\/+/, '')}`);
  }

  function parseAccountSaleGallery(rawValue) {
    try {
      const parsed = JSON.parse(String(rawValue || '[]'));
      return Array.isArray(parsed)
        ? parsed
            .filter((item) => item && typeof item === 'object')
            .map((item) => ({
              imageUrl: resolvePublicImageUrl(item.image_url || item.image_path || ''),
              description: String(item.description || '').trim(),
              order: Number(item.order || 0),
            }))
            .filter((item) => item.imageUrl !== '')
        : [];
    } catch (error) {
      return [];
    }
  }

  function isAccountSalePack(pack) {
    return Boolean(accountSaleFeatureEnabled && pack && pack.accountSale);
  }

  function setAccountSaleNote(pack) {
    if (!accountSaleNote) {
      return;
    }

    const visible = isAccountSalePack(pack);
    accountSaleNote.classList.toggle('d-none', !visible);
  }

  function getAccountSalePayload(data) {
    const payload = data && typeof data.account_sale === 'object' ? data.account_sale : null;
    if (!payload || !payload.enabled) {
      return null;
    }

    return {
      delivered: !!payload.delivered,
      accountText: String(payload.account_text || '').trim(),
      gallery: Array.isArray(payload.gallery)
        ? payload.gallery
            .filter((item) => item && typeof item === 'object')
            .map((item) => ({
              imageUrl: resolvePublicImageUrl(item.image_url || item.image_path || ''),
              description: String(item.description || '').trim(),
            }))
            .filter((item) => item.imageUrl !== '')
        : [],
    };
  }

  function buildPackStateFromCard(card) {
    return {
      id: card.dataset.packageId,
      provider: String(card.dataset.packageProvider || '').trim(),
      name: card.dataset.name,
      priceValue: Number(card.dataset.priceValue || 0),
      moneda: card.dataset.moneda,
      baseCurrency: String(card.dataset.baseCurrency || card.dataset.moneda || '').trim(),
      cantidad: card.dataset.cantidad,
      showDecimals: card.dataset.showDecimals === '1',
      rewardPoints: Number(card.dataset.winPointsReward || 0),
      redeemRequiredPoints: Number(card.dataset.winPointsRequired || 0),
      redeemActive: card.dataset.winPointsActive === '1',
      requiredFields: parseRequiredFields(card.dataset.requiredFields),
      imageUrl: String(card.dataset.packageImage || ''),
      features: parsePackageFeatures(card.dataset.packageFeatures),
      accountSale: card.dataset.accountSale === '1',
      accountGallery: parseAccountSaleGallery(card.dataset.accountGallery)
    };
  }

  function paymentSummaryFeatureIconMarkup(iconKey) {
    const safeKey = String(iconKey || 'sparkles').trim();
    return packageFeatureIconSvgMap[safeKey] || packageFeatureIconSvgMap.sparkles || '';
  }

  function renderPaymentSummary(pack, userId, totalText) {
    const safeUser = isAccountSalePack(pack) ? 'Entrega directa' : (userId || '-');
    const quantity = normalizeOrderQuantity(pack && pack.purchaseQuantity ? pack.purchaseQuantity : 1);
    const safeProduct = (pack && pack.name)
      ? (quantity > 1 ? `${pack.name} x${quantity}` : pack.name)
      : 'Producto';
    const safeTotal = totalText || '-';

    paymentSummaryUser.textContent = safeUser;
    paymentSummaryProduct.textContent = safeProduct;
    paymentSummaryTotal.textContent = safeTotal;

    if (!paymentHeaderMinimalEnabled || !paymentSummaryCard) {
      return;
    }

    if (paymentSummaryMinimalUser) {
      paymentSummaryMinimalUser.textContent = safeUser;
    }
    if (paymentSummaryMinimalProduct) {
      paymentSummaryMinimalProduct.textContent = safeProduct;
    }
    if (paymentSummaryMinimalTotal) {
      paymentSummaryMinimalTotal.textContent = safeTotal;
    }

    const imageUrl = String((pack && pack.imageUrl) || '').trim();
    if (paymentSummaryImage) {
      paymentSummaryImage.src = imageUrl;
      paymentSummaryImage.alt = safeProduct;
      paymentSummaryImage.classList.toggle('d-none', imageUrl === '');
    }
    if (paymentSummaryImagePlaceholder) {
      paymentSummaryImagePlaceholder.classList.toggle('d-none', imageUrl !== '');
    }

    if (paymentSummaryFeatures) {
      const features = Array.isArray(pack && pack.features) ? pack.features : [];
      if (features.length === 0) {
        paymentSummaryFeatures.innerHTML = '';
        paymentSummaryFeatures.classList.add('d-none');
      } else {
        paymentSummaryFeatures.innerHTML = features.map((feature) => {
          const iconMarkup = paymentSummaryFeatureIconMarkup(feature && feature.icon ? feature.icon : 'sparkles');
          return `<span class="payment-summary-feature"><span class="payment-summary-feature-icon" aria-hidden="true">${iconMarkup}</span><span>${escapePaymentHtml(feature && feature.name ? feature.name : '')}</span></span>`;
        }).join('');
        paymentSummaryFeatures.classList.remove('d-none');
      }
    }
  }

  function getConfirmedPaymentTotalText(fallbackText = '') {
    if (activePaymentOrder && typeof activePaymentOrder.confirmedTotalText === 'string') {
      const confirmedTotal = activePaymentOrder.confirmedTotalText.trim();
      if (confirmedTotal !== '') {
        return confirmedTotal;
      }
    }

    if (paymentSummaryTotal && typeof paymentSummaryTotal.textContent === 'string') {
      const summaryTotal = paymentSummaryTotal.textContent.trim();
      if (summaryTotal !== '') {
        return summaryTotal;
      }
    }

    return String(fallbackText || '').trim();
  }

  function formatWinPointsAmount(points) {
    return `${Number(points || 0).toLocaleString('en-US')} ${winPointsState.name || 'Win Points'}`;
  }

  function restartPublicCheckoutSummaryAnimation(key) {
    if (!publicOrderSummaryPanel) {
      return;
    }

    if (publicCheckoutSummaryAnimationKey === key && publicOrderSummaryPanel.classList.contains('is-active')) {
      return;
    }

    publicCheckoutSummaryAnimationKey = key;
    publicOrderSummaryPanel.classList.remove('is-active');
    void publicOrderSummaryPanel.offsetWidth;
    requestAnimationFrame(() => {
      publicOrderSummaryPanel.classList.add('is-active');
    });
  }

  function clearAppliedCouponSummary() {
    appliedCouponSummary = {
      code: '',
      discountAmount: 0,
      originalAmount: 0,
      discountType: '',
      discountValue: 0,
    };
  }

  function renderPublicOrderSummary(pack = activePack) {
    if (!publicOrderSummaryShell || !publicOrderSummaryRows || !publicOrderSummaryTotal || !buyButton) {
      return;
    }

    if (!pack) {
      publicOrderSummaryShell.classList.add('d-none');
      if (publicOrderSummaryPanel) {
        publicOrderSummaryPanel.classList.remove('is-active');
      }
      if (publicOrderSummaryMethod) {
        publicOrderSummaryMethod.textContent = '';
        publicOrderSummaryMethod.classList.add('d-none');
      }
      if (publicOrderSummaryCoupon && publicOrderSummaryCouponCopy) {
        publicOrderSummaryCoupon.classList.add('d-none');
        publicOrderSummaryCouponCopy.textContent = '';
      }
      publicOrderSummaryRows.innerHTML = '';
      publicOrderSummaryTotal.textContent = '-';
      publicCheckoutSummaryTotalText = '';
      return;
    }

    const selection = resolvePreferredCheckoutSelection(pack);
    if (!selection.mode) {
      publicOrderSummaryShell.classList.add('d-none');
      if (publicOrderSummaryPanel) {
        publicOrderSummaryPanel.classList.remove('is-active');
      }
      if (publicOrderSummaryCoupon && publicOrderSummaryCouponCopy) {
        publicOrderSummaryCoupon.classList.add('d-none');
        publicOrderSummaryCouponCopy.textContent = '';
      }
      publicCheckoutSummaryTotalText = '';
      return;
    }

    const selectedMethod = selection.mode === 'money'
      ? (selection.methods.find((method) => String(method.id) === String(selection.methodId || '')) || selection.methods[0] || null)
      : null;
    const pricing = resolvePaymentPricing(selection.mode, selectedMethod);
    const rows = [];
    const couponDiscountAmount = selection.mode === 'points'
      ? 0
      : normalizeCurrencyAmount(Number(appliedCouponSummary.discountAmount || 0), pricing.showDecimals);
    const couponCode = String(appliedCouponSummary.code || '').trim();
    const couponActive = couponApplied && couponCode !== '' && couponDiscountAmount > 0;
    const summaryBaseAmount = couponActive
      ? normalizeCurrencyAmount(pricing.baseAmount + couponDiscountAmount, pricing.showDecimals)
      : pricing.baseAmount;
    const couponDiscountText = formatPaymentDifferenceMoney(pricing.currencyCode, couponDiscountAmount, pricing.showDecimals);

    if (selection.mode === 'points') {
      if (pricing.baseAmount > 0) {
        rows.push({ label: 'Canje requerido', value: pricing.baseText, positive: false });
      }
    } else {
      if (summaryBaseAmount > 0) {
        rows.push({ label: couponActive ? 'Subtotal original' : 'Subtotal', value: formatPaymentDifferenceMoney(pricing.currencyCode, summaryBaseAmount, pricing.showDecimals), positive: false });
      }
      if (couponActive) {
        rows.push({ label: `Cupón ${couponCode}`, value: couponDiscountText, positive: true });
      }
      if (pricing.discountPercentage > 0) {
        rows.push({ label: 'Descuento', value: formatDiscountPercentage(pricing.discountPercentage), positive: true });
      }
      if (pricing.discountAmount > 0) {
        rows.push({ label: 'Tu ahorro', value: pricing.discountText, positive: true });
      }
    }

    if (pricing.totalAmount <= 0) {
      publicOrderSummaryShell.classList.add('d-none');
      if (publicOrderSummaryPanel) {
        publicOrderSummaryPanel.classList.remove('is-active');
      }
      if (publicOrderSummaryCoupon && publicOrderSummaryCouponCopy) {
        publicOrderSummaryCoupon.classList.add('d-none');
        publicOrderSummaryCouponCopy.textContent = '';
      }
      publicCheckoutSummaryTotalText = '';
      return;
    }

    publicOrderSummaryRows.innerHTML = rows.map((row) => `
      <div class="payment-order-summary-row">
        <span class="payment-order-summary-row-label">${escapePaymentHtml(row.label)}</span>
        <strong class="payment-order-summary-row-value${row.positive ? ' is-positive' : ''}">${escapePaymentHtml(row.value)}</strong>
      </div>`).join('');

    const methodLabel = selection.mode === 'points'
      ? String(winPointsState.name || 'Win Points')
      : (selection.mode === 'binance'
        ? String(binancePayButtonLabel || 'Binance Pay')
        : (selection.mode === 'paypal'
          ? String(paypalPayButtonLabel || 'PayPal')
          : String(selectedMethod && selectedMethod.nombre ? selectedMethod.nombre : 'Método de pago')));

    if (publicOrderSummaryMethod) {
      publicOrderSummaryMethod.textContent = methodLabel;
      publicOrderSummaryMethod.classList.remove('d-none');
    }

    if (publicOrderSummaryCoupon && publicOrderSummaryCouponCopy) {
      if (couponActive) {
        publicOrderSummaryCouponCopy.textContent = `${couponCode} aplicado. Ahorras ${couponDiscountText}`;
        publicOrderSummaryCoupon.classList.remove('d-none');
      } else {
        publicOrderSummaryCoupon.classList.add('d-none');
        publicOrderSummaryCouponCopy.textContent = '';
      }
    }

    publicCheckoutSummaryTotalText = pricing.totalText;
    publicOrderSummaryTotal.textContent = pricing.totalText;
    publicOrderSummaryShell.classList.remove('d-none');
    restartPublicCheckoutSummaryAnimation(`${selection.mode}:${selection.methodId || methodLabel}:${pricing.totalText}:${couponCode}:${couponDiscountText}`);
  }

  function formatWinPointsExpirationText(summary, includeDate = false) {
    const status = String((summary && summary.expiration_status) || '').trim();
    const daysLabel = String((summary && summary.days_remaining_label) || '').trim();
    const expiresLabel = String((summary && summary.expires_at_label) || '').trim();
    if (status === 'expired') {
      return includeDate && expiresLabel && expiresLabel !== 'Sin saldo' ? `Vencidos | ${expiresLabel}` : 'Vencidos';
    }
    if ((status === 'active' || status === 'warning') && daysLabel !== '') {
      return includeDate && expiresLabel && expiresLabel !== 'Sin saldo'
        ? `Vence en ${daysLabel} | ${expiresLabel}`
        : `Vence en ${daysLabel}`;
    }
    return daysLabel || 'Sin saldo';
  }

  function applyWinPointsUserSummary(summary) {
    if (!summary || !Number.isFinite(Number(summary.balance))) {
      return;
    }

    const refreshedBalance = Number(summary.balance);
    const userMenuRewardsBalance = document.getElementById('user-menu-rewards-balance');
    const userRewardsBalanceValue = document.getElementById('user-rewards-balance-value');
    const userMenuRewardsExpiration = document.getElementById('user-menu-rewards-expiration');
    const userRewardsExpirationValue = document.getElementById('user-rewards-expiration-value');

    winPointsState.balance = refreshedBalance;

    if (userMenuRewardsBalance) {
      userMenuRewardsBalance.textContent = refreshedBalance.toLocaleString('en-US');
    }
    if (userRewardsBalanceValue) {
      userRewardsBalanceValue.textContent = refreshedBalance.toLocaleString('en-US');
    }
    if (userMenuRewardsExpiration) {
      userMenuRewardsExpiration.textContent = formatWinPointsExpirationText(summary, false);
    }
    if (userRewardsExpirationValue) {
      userRewardsExpirationValue.textContent = formatWinPointsExpirationText(summary, true);
    }

    renderPublicPaymentMethodCatalog(activePack);
  }

  function buildWinPointsFloatingNotification(payload) {
    const notification = document.createElement('div');
    notification.className = 'win-points-live-notification';
    notification.dataset.position = String(winPointsState.notificationPosition || 'bottom-left');

    const notificationLogo = String(winPointsState.notificationLogoUrl || winPointsState.iconUrl || '');
    const iconMarkup = notificationLogo
      ? '<div class="win-points-live-notification__logo-wrap"><img src="' + escapePaymentHtml(notificationLogo) + '" alt="' + escapePaymentHtml(winPointsState.name || 'Win Points') + '" class="win-points-live-notification__logo"></div>'
      : '<div class="win-points-live-notification__logo-wrap"><span class="win-points-live-notification__logo-fallback">WP</span></div>';

    notification.innerHTML = ''
      + '<div class="win-points-live-notification__pulse" aria-hidden="true"></div>'
      + iconMarkup
      + '<div class="win-points-live-notification__body">'
      + '<div class="win-points-live-notification__title">' + escapePaymentHtml(payload.title || '') + '</div>'
      + '<div class="win-points-live-notification__detail">' + escapePaymentHtml(payload.detail || '') + '</div>'
      + '</div>';

    return notification;
  }

  function showWinPointsNotification(payload) {
    if (!winPointsState.enabled || !payload || !payload.title) {
      return;
    }

    const existing = document.querySelector('.win-points-live-notification[data-win-points-runtime="1"]');
    if (existing) {
      existing.remove();
    }

    const notification = buildWinPointsFloatingNotification(payload);
    notification.dataset.winPointsRuntime = '1';
    document.body.appendChild(notification);

    window.requestAnimationFrame(function () {
      notification.classList.add('is-visible');
    });

    window.setTimeout(function () {
      notification.classList.remove('is-visible');
      window.setTimeout(function () {
        notification.remove();
      }, 320);
    }, 5000);
  }

  function syncWinPointsSummaryFromResponse(summary, options = {}) {
    if (!summary || !Number.isFinite(Number(summary.balance))) {
      return;
    }

    const previousBalance = Number(winPointsState.balance || 0);
    const nextBalance = Number(summary.balance || 0);
    const spentPoints = Math.max(0, Number(summary.spent || 0));
    const earnedPoints = Math.max(0, nextBalance - previousBalance);

    applyWinPointsUserSummary(summary);

    if (options && options.silent) {
      return;
    }

    if (spentPoints > 0) {
      showWinPointsNotification({
        title: '-' + spentPoints + ' ' + (winPointsState.name || 'Win Points'),
        detail: 'Se descontaron de tu saldo para completar el canje del paquete seleccionado.'
      });
      return;
    }

    if (earnedPoints > 0) {
      showWinPointsNotification({
        title: '+' + earnedPoints + ' ' + (winPointsState.name || 'Win Points'),
        detail: 'Tu saldo fue actualizado correctamente con el premio de esta compra.'
      });
    }
  }

  function canRedeemPackWithPoints(pack) {
    return Boolean(
      winPointsState.enabled
      && winPointsState.loggedIn
      && pack
      && pack.redeemActive
      && getPackRequiredPoints(pack) > 0
      && Number(winPointsState.balance || 0) >= getPackRequiredPoints(pack)
    );
  }

  function canUseBinanceCheckout(pack) {
    return Boolean(binancePayCheckoutEnabled && pack && getPackTotalPrice(pack) > 0);
  }

  function canUsePayPalCheckout(pack) {
    const currencyCode = String((pack && pack.moneda) || '').trim().toUpperCase();
    return Boolean(
      paypalPayCheckoutEnabled
      && pack
      && getPackTotalPrice(pack) > 0
      && currencyCode !== ''
      && Array.isArray(paypalSupportedCurrencies)
      && paypalSupportedCurrencies.includes(currencyCode)
    );
  }

  function getPaymentModeButtons() {
    return paymentModeOptions ? Array.from(paymentModeOptions.querySelectorAll('.payment-mode-btn')) : [];
  }

  function resolvePaymentModeDiscountPercentage(mode, method = null) {
    if (!paymentMethodDiscountsEnabled) {
      return 0;
    }

    if (mode === 'money' && method) {
      return normalizeDiscountPercentage(method.descuento_porcentaje || 0);
    }

    if (mode === 'binance') {
      return normalizeDiscountPercentage(binancePayDiscountPercentage);
    }

    return 0;
  }

  function resolvePaymentPricing(mode = null, method = null) {
    const pack = activePaymentOrder && activePaymentOrder.pack ? activePaymentOrder.pack : activePack;
    const quantity = normalizeOrderQuantity(activePaymentOrder && activePaymentOrder.purchaseQuantity ? activePaymentOrder.purchaseQuantity : (pack && pack.purchaseQuantity ? pack.purchaseQuantity : getOrderQuantity()));
    if ((mode || (activePaymentOrder ? activePaymentOrder.paymentMode : 'money')) === 'points' && pack && getPackRequiredPoints(pack, quantity) > 0) {
      const pointsRequired = getPackRequiredPoints(pack, quantity);
      const pointsText = formatWinPointsAmount(pointsRequired);
      return {
        currencyCode: String(winPointsState.name || 'Win Points'),
        showDecimals: false,
        baseAmount: pointsRequired,
        discountPercentage: 0,
        discountAmount: 0,
        totalAmount: pointsRequired,
        baseText: pointsText,
        discountText: formatWinPointsAmount(0),
        totalText: pointsText,
      };
    }

    const currencyCode = String((activePaymentOrder && activePaymentOrder.currency) || (pack && pack.moneda) || monedaActualClave || '').trim().toUpperCase();
    const showDecimals = Boolean(pack && pack.showDecimals);
    const baseAmount = normalizeCurrencyAmount(Number(activePaymentOrder && activePaymentOrder.baseAmount !== undefined ? activePaymentOrder.baseAmount : selectedTotalValue), showDecimals);
    const discountPercentage = resolvePaymentModeDiscountPercentage(mode || (activePaymentOrder ? activePaymentOrder.paymentMode : 'money'), method);
    const discountAmount = discountPercentage > 0
      ? normalizeCurrencyAmount((baseAmount * discountPercentage) / 100, showDecimals)
      : 0;
    const totalAmount = normalizeCurrencyAmount(Math.max(0, baseAmount - discountAmount), showDecimals);

    return {
      currencyCode,
      showDecimals,
      baseAmount,
      discountPercentage,
      discountAmount,
      totalAmount,
      baseText: formatPaymentDifferenceMoney(currencyCode, baseAmount, showDecimals),
      discountText: formatPaymentDifferenceMoney(currencyCode, discountAmount, showDecimals),
      totalText: formatPaymentDifferenceMoney(currencyCode, totalAmount, showDecimals),
    };
  }

  function renderPaymentDiscountPanel(pricing, options = {}) {
    const variant = options.variant === 'method' ? 'method' : 'summary';
    const mode = options.mode || (activePaymentOrder ? activePaymentOrder.paymentMode : 'money');
    const methodName = mode === 'binance'
      ? String(binancePayButtonLabel || 'Binance Pay')
      : (mode === 'paypal'
        ? String(paypalPayButtonLabel || 'PayPal')
        : String(options.method && options.method.nombre ? options.method.nombre : 'Metodo de pago'));
    const badgeText = variant === 'summary' ? 'Metodo elegido' : '';
    const titleText = variant === 'method'
      ? `${methodName} mantiene tu bonus en esta orden`
      : methodName;
    const copyText = variant === 'method'
      ? `Precio real del paquete ${pricing.baseText}. Ahorras ${pricing.discountText} y cierras la compra pagando ${pricing.totalText}.`
      : `Precio real del paquete ${pricing.baseText}. ${methodName} aplica ${formatDiscountPercentage(pricing.discountPercentage)} de descuento, te ahorra ${pricing.discountText} y deja el total final en ${pricing.totalText}.`;
    const totalLabel = variant === 'method' ? 'Pagas hoy' : 'Total final';

    return `
      <div class="payment-discount-panel payment-discount-panel-${variant}">
        <div class="payment-discount-panel-head">
          ${badgeText !== '' ? `<span class="payment-discount-badge">${escapePaymentHtml(badgeText)}</span>` : '<span></span>'}
          <span class="payment-discount-chip">${escapePaymentHtml(formatDiscountPercentage(pricing.discountPercentage))} OFF</span>
        </div>
        <div class="payment-discount-panel-title">${escapePaymentHtml(titleText)}</div>
        <div class="payment-discount-panel-copy">${escapePaymentHtml(copyText)}</div>
        <div class="payment-discount-grid">
          <div class="payment-discount-stat">
            <span>Precio real</span>
            <strong>${escapePaymentHtml(pricing.baseText)}</strong>
          </div>
          <div class="payment-discount-stat">
            <span>Descuento</span>
            <strong>${escapePaymentHtml(formatDiscountPercentage(pricing.discountPercentage))}</strong>
          </div>
          <div class="payment-discount-stat">
            <span>Ahorras</span>
            <strong>${escapePaymentHtml(pricing.discountText)}</strong>
          </div>
          <div class="payment-discount-stat payment-discount-stat-highlight">
            <span>${escapePaymentHtml(totalLabel)}</span>
            <strong>${escapePaymentHtml(pricing.totalText)}</strong>
          </div>
        </div>
      </div>`;
  }

  function updatePaymentPricingUi(methodOverride = null) {
    if (!activePaymentOrder) {
      if (paymentSummaryDiscount) {
        paymentSummaryDiscount.innerHTML = '';
        paymentSummaryDiscount.classList.add('d-none');
      }
      if (paymentMethodDiscount) {
        paymentMethodDiscount.innerHTML = '';
        paymentMethodDiscount.classList.add('d-none');
      }
      return;
    }

    const resolvedMethod = methodOverride || resolveSelectedPaymentMethod(activePaymentOrder.currency, activePaymentOrder.selectedMethodId);
    const pricing = resolvePaymentPricing(activePaymentOrder.paymentMode, resolvedMethod);
    activePaymentOrder.confirmedTotalText = pricing.totalText;
    activePaymentOrder.discountPercentage = pricing.discountPercentage;
    activePaymentOrder.discountAmount = pricing.discountAmount;
    renderPaymentSummary(activePaymentOrder.pack, activePaymentOrder.userId, pricing.totalText);

    if (paymentSummaryDiscount) {
      if (pricing.discountPercentage > 0 && activePaymentOrder.paymentMode !== 'points') {
        paymentSummaryDiscount.innerHTML = renderPaymentDiscountPanel(pricing, {
          variant: 'summary',
          mode: activePaymentOrder.paymentMode,
          method: resolvedMethod,
        });
        paymentSummaryDiscount.classList.remove('d-none');
      } else {
        paymentSummaryDiscount.innerHTML = '';
        paymentSummaryDiscount.classList.add('d-none');
      }
    }

    if (paymentMethodDiscount) {
      paymentMethodDiscount.innerHTML = '';
      paymentMethodDiscount.classList.add('d-none');
    }
  }

  function resolveSelectedPaymentMethod(currencyCode, preferredMethodId) {
    const methods = getPaymentMethodsForCurrency(currencyCode);
    if (!methods.length) {
      return null;
    }
    if (preferredMethodId !== undefined && preferredMethodId !== null && String(preferredMethodId) !== '') {
      const matchedMethod = methods.find((method) => String(method.id) === String(preferredMethodId));
      if (matchedMethod) {
        return matchedMethod;
      }
    }
    return methods[0];
  }

  function paymentPointsOptionLabel(hasRule, requiredPoints) {
    return hasRule ? `Usar ${formatWinPointsAmount(requiredPoints)}` : 'Sin canje disponible';
  }

  function paymentOptionKey(mode, methodId = '') {
    if (mode === 'points') {
      return 'points';
    }
    if (mode === 'binance') {
      return 'binance';
    }
    if (mode === 'paypal') {
      return 'paypal';
    }
    return `money:${String(methodId || '')}`;
  }

  function storePreferredCheckoutPayment(mode, methodId = '') {
    const normalizedMethodId = String(methodId || '');
    if (mode === 'points') {
      preferredCheckoutPaymentMode = 'points';
      preferredCheckoutMethodId = '';
      return;
    }
    if (mode === 'binance') {
      preferredCheckoutPaymentMode = 'binance';
      preferredCheckoutMethodId = '';
      return;
    }
    if (mode === 'paypal') {
      preferredCheckoutPaymentMode = 'paypal';
      preferredCheckoutMethodId = '';
      return;
    }
    if (mode === 'money' && normalizedMethodId !== '') {
      preferredCheckoutPaymentMode = 'money';
      preferredCheckoutMethodId = normalizedMethodId;
      return;
    }

    preferredCheckoutPaymentMode = '';
    preferredCheckoutMethodId = '';
  }

  function resolvePreferredCheckoutSelection(pack) {
    const hasPack = Boolean(pack);
    const methodsCurrencyCode = String((pack && (pack.baseCurrency || pack.moneda)) || '').trim();
    const methods = getPaymentMethodsForCurrency(methodsCurrencyCode);
    const hasPointsRule = Boolean(hasPack && pack.redeemActive && getPackRequiredPoints(pack) > 0);
    const requiredPoints = hasPointsRule ? getPackRequiredPoints(pack) : 0;
    const canUsePointsNow = Boolean(hasPack && canRedeemPackWithPoints(pack));
    const showPointsOption = Boolean(hasPack && winPointsState.enabled && hasPointsRule);
    const canUseBinance = hasPack ? Boolean(canUseBinanceCheckout(pack)) : Boolean(binancePayCheckoutEnabled);
    const canUsePayPal = hasPack
      ? Boolean(canUsePayPalCheckout(pack))
      : Boolean(paypalPayCheckoutEnabled && resolvePreferredPayPalCurrencyEntry());
    let nextMode = preferredCheckoutPaymentMode;
    let nextMethodId = preferredCheckoutMethodId;

    if (nextMode === 'money') {
      const matchedMethod = methods.find((method) => String(method.id) === String(nextMethodId || ''));
      nextMethodId = matchedMethod ? String(matchedMethod.id) : '';
      if (nextMethodId === '') {
        nextMode = '';
      }
    }

    if (nextMode === 'binance' && !canUseBinance) {
      nextMode = '';
    }

    if (nextMode === 'paypal' && !canUsePayPal) {
      nextMode = '';
    }

    if (nextMode === 'points' && !showPointsOption) {
      nextMode = '';
    }

    return {
      mode: nextMode,
      methodId: nextMode === 'money' ? nextMethodId : '',
      methods,
      showPointsOption,
      canUsePointsNow,
      hasPointsRule,
      requiredPoints,
      canUseBinance,
      canUsePayPal,
    };
  }

  function shouldExpandSinglePaymentOption() {
    if (!activePaymentOrder) {
      return false;
    }

    const methods = getPaymentMethodsForCurrency(activePaymentOrder.currency);
    const usableOptionCount = methods.length + (activePaymentOrder.canUseBinance ? 1 : 0) + (activePaymentOrder.canUsePayPal ? 1 : 0) + (activePaymentOrder.canUsePoints ? 1 : 0);
    return usableOptionCount === 1;
  }

  function paymentMethodMetaLabel(method) {
    const currencyLabel = `${method.moneda_nombre || ''}${method.moneda_clave ? ` (${method.moneda_clave})` : ''}`.trim();
    return currencyLabel || 'Método de pago';
  }

  function paymentMethodPublicCornerMarkup(imageUrl) {
    const safeUrl = String(imageUrl || '').trim();
    if (safeUrl === '') {
      return '';
    }

    return `<span class="payment-method-public-corner-badge" aria-hidden="true"><img src="${escapePaymentHtml(safeUrl)}" alt=""></span>`;
  }

  function renderPublicPaymentMethodCatalog(pack = activePack) {
    if (!paymentMethodCatalogGrid || !paymentMethodCatalogCopy) {
      return;
    }

    const hasPack = Boolean(pack);
    const selection = resolvePreferredCheckoutSelection(pack);
    const cards = [];

    selection.methods.forEach((method) => {
      const methodId = String(method.id || '');
      const discountPercentage = resolvePaymentModeDiscountPercentage('money', method);
      const methodMeta = paymentMethodMetaLabel(method);
      const methodMetaText = discountPercentage > 0
        ? `${methodMeta} · ${formatDiscountPercentage(discountPercentage)} OFF`
        : methodMeta;
      const imageUrl = resolvePublicImageUrl(method.image_path || '');
      const cornerMarkup = paymentMethodPublicCornerMarkup(resolvePublicImageUrl(method.corner_image_path || ''));
      const imageMarkup = imageUrl !== ''
        ? `<img src="${escapePaymentHtml(imageUrl)}" alt="${escapePaymentHtml(method.nombre || 'Método de pago')}" class="payment-method-public-image"><span class="visually-hidden">${escapePaymentHtml(method.nombre || 'Método de pago')}</span>`
        : `<span class="payment-method-public-text"><span class="payment-method-public-name">${escapePaymentHtml(method.nombre || 'Método de pago')}</span><span class="payment-method-public-meta">${escapePaymentHtml(methodMetaText)}</span></span>`;
      const isSelected = selection.mode === 'money' && methodId === String(selection.methodId || '');
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="money" data-method-id="${escapePaymentHtml(methodId)}">${imageMarkup}</button>
          ${cornerMarkup}
        </div>`);
    });

    if (selection.canUseBinance) {
      const binanceDiscount = resolvePaymentModeDiscountPercentage('binance', null);
      const binanceMeta = binanceDiscount > 0
        ? `Checkout externo seguro · ${formatDiscountPercentage(binanceDiscount)} OFF`
        : 'Checkout externo seguro con CoinPal';
      const isSelected = selection.mode === 'binance';
      const binanceCornerMarkup = paymentMethodPublicCornerMarkup(String(binancePayCornerImageUrl || '').trim());
      const binanceMarkup = String(binancePayImageUrl || '').trim() !== ''
        ? `<img src="${escapePaymentHtml(String(binancePayImageUrl || '').trim())}" alt="${escapePaymentHtml(binancePayButtonLabel)}" class="payment-method-public-image"><span class="visually-hidden">${escapePaymentHtml(binancePayButtonLabel)}</span>`
        : `<span class="payment-method-public-text"><span class="payment-method-public-name">${escapePaymentHtml(binancePayButtonLabel)}</span><span class="payment-method-public-meta">${escapePaymentHtml(binanceMeta)}</span></span>`;
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="binance">
            ${binanceMarkup}
          </button>
          ${binanceCornerMarkup}
        </div>`);
    }

    if (selection.canUsePayPal) {
      const paypalMeta = 'Checkout oficial de PayPal con confirmación automática';
      const isSelected = selection.mode === 'paypal';
      const paypalCornerMarkup = paymentMethodPublicCornerMarkup(String(paypalPayCornerImageUrl || '').trim());
      const paypalMarkup = String(paypalPayImageUrl || '').trim() !== ''
        ? `<img src="${escapePaymentHtml(String(paypalPayImageUrl || '').trim())}" alt="${escapePaymentHtml(paypalPayButtonLabel)}" class="payment-method-public-image"><span class="visually-hidden">${escapePaymentHtml(paypalPayButtonLabel)}</span>`
        : `<span class="payment-method-public-text"><span class="payment-method-public-name">${escapePaymentHtml(paypalPayButtonLabel)}</span><span class="payment-method-public-meta">${escapePaymentHtml(paypalMeta)}</span></span>`;
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="paypal">
            ${paypalMarkup}
          </button>
          ${paypalCornerMarkup}
        </div>`);
    }

    if (selection.showPointsOption) {
      const pointsDisabled = !selection.hasPointsRule;
      const pointsNeedText = `Necesitas ${formatWinPointsAmount(selection.requiredPoints || 0)}`;
      let pointsMeta = pointsNeedText;
      if (!winPointsState.loggedIn) {
        pointsMeta = `${pointsNeedText} · Inicia sesión para usarlo`;
      } else if (!selection.hasPointsRule) {
        pointsMeta = 'Este paquete no admite canje';
      } else if (selection.canUsePointsNow) {
        pointsMeta = `${pointsNeedText} · Saldo actual ${formatWinPointsAmount(winPointsState.balance || 0)}`;
      } else {
        pointsMeta = `${pointsNeedText} · Saldo actual ${formatWinPointsAmount(winPointsState.balance || 0)}`;
      }
      const pointsImageUrl = String(winPointsState.paymentImageUrl || '').trim();
      const pointsCornerMarkup = paymentMethodPublicCornerMarkup(String(winPointsState.paymentCornerImageUrl || '').trim());
      const pointsMarkup = pointsImageUrl !== ''
        ? `<img src="${escapePaymentHtml(pointsImageUrl)}" alt="${escapePaymentHtml(winPointsState.name || 'Win Points')}" class="payment-method-public-image"><span class="payment-method-public-points-caption"><strong>${escapePaymentHtml(pointsNeedText)}</strong>${escapePaymentHtml(!winPointsState.loggedIn ? 'Inicia sesión para usarlo' : (selection.canUsePointsNow ? `Saldo actual ${formatWinPointsAmount(winPointsState.balance || 0)}` : `Saldo actual ${formatWinPointsAmount(winPointsState.balance || 0)}`))}</span><span class="visually-hidden">${escapePaymentHtml(winPointsState.name || 'Win Points')}</span>`
        : `<span class="payment-method-public-text"><span class="payment-method-public-name">${escapePaymentHtml(winPointsState.name || 'Win Points')}</span><span class="payment-method-public-meta">${escapePaymentHtml(pointsMeta)}</span></span>`;
      const isSelected = selection.mode === 'points';
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}${pointsDisabled ? ' is-disabled' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="points" ${pointsDisabled ? 'disabled' : ''}>${pointsMarkup}</button>
          ${pointsCornerMarkup}
        </div>`);
    }

    if (!cards.length) {
      paymentMethodCatalogGrid.innerHTML = '<div class="payment-method-public-card is-disabled"><div class="payment-method-public-text"><div class="payment-method-public-name">Sin métodos activos</div><div class="payment-method-public-meta">No hay métodos de pago activos disponibles en este momento.</div></div></div>';
      paymentMethodCatalogCopy.textContent = hasPack
        ? 'No hay métodos activos disponibles para la moneda del paquete seleccionado.'
        : 'No hay métodos activos configurados para esta tienda en este momento.';
      return;
    }

    paymentMethodCatalogGrid.innerHTML = cards.join('');

    if (selection.mode === 'money' && selection.methodId !== '') {
      const method = selection.methods.find((item) => String(item.id) === String(selection.methodId));
      paymentMethodCatalogCopy.textContent = method
        ? (hasPack
          ? `Seleccionado: ${method.nombre}. Esta opción se abrirá marcada al pagar.`
          : `Seleccionado: ${method.nombre}. Mostraremos los precios en ${method.moneda_clave || 'la moneda elegida'} mientras eliges el paquete.`)
        : (hasPack
          ? 'Selecciona un método de pago para mostrar el resumen de esta orden.'
          : 'Selecciona un método para ver los precios en su moneda antes de elegir el paquete.');
      return;
    }

    if (selection.mode === 'binance') {
      paymentMethodCatalogCopy.textContent = hasPack
        ? 'Seleccionado: Binance Pay. El checkout externo se abrirá ya preparado al confirmar la orden.'
        : 'Seleccionado: Binance Pay. Mostraremos los precios en la moneda preferida para este checkout mientras eliges el paquete.';
      return;
    }

    if (selection.mode === 'paypal') {
      paymentMethodCatalogCopy.textContent = hasPack
        ? 'Seleccionado: PayPal. Al confirmar, abriremos el checkout oficial para autorizar y capturar el pago.'
        : 'Seleccionado: PayPal. Mostraremos los precios en una moneda compatible con PayPal mientras eliges el paquete.';
      return;
    }

    if (selection.showPointsOption) {
      paymentMethodCatalogCopy.textContent = selection.canUsePointsNow
        ? `Seleccionado: ${winPointsState.name || 'Win Points'}. El sistema intentará el canje con tu saldo disponible.`
        : (!winPointsState.loggedIn
          ? `${winPointsState.name || 'Win Points'} está activo para este paquete. Inicia sesión para usarlo como método de pago.`
          : `${winPointsState.name || 'Win Points'} está activo para este paquete, pero necesitas ${formatWinPointsAmount(selection.requiredPoints || 0)} para usarlo.`);
      return;
    }

    paymentMethodCatalogCopy.textContent = hasPack
      ? 'Selecciona un método de pago para mostrar el resumen de esta orden.'
      : 'Selecciona cómo quieres pagar y mostraremos los precios en esa moneda antes de elegir el paquete.';
  }

  function paymentMethodAccordionMarkup(method) {
    const methodName = escapePaymentHtml(method.nombre || 'Método de pago');
    const methodMeta = escapePaymentHtml(paymentMethodMetaLabel(method));
    const methodDetails = escapePaymentHtml(method.datos || '').replace(/\n/g, '<br>');
    const discountPercentage = resolvePaymentModeDiscountPercentage('money', method);
    const discountMarkup = discountPercentage > 0
      ? `<div class="payment-mode-item-currency">Descuento disponible: ${escapePaymentHtml(formatDiscountPercentage(discountPercentage))}</div>`
      : '';
    return `<div class="payment-mode-item-card"><div class="payment-mode-item-card-title">Datos para ${methodName}</div><div class="payment-mode-item-currency">${methodMeta}</div>${discountMarkup}<div class="payment-mode-item-details">${methodDetails}</div></div>`;
  }

  function paymentPointsAccordionMarkup() {
    const copy = escapePaymentHtml(String(activePaymentOrder && activePaymentOrder.pointsCopy ? activePaymentOrder.pointsCopy : ''));
    const message = escapePaymentHtml(String(activePaymentOrder && activePaymentOrder.pointsMessage ? activePaymentOrder.pointsMessage : '')).replace(/\n/g, '<br>');
    return `<div class="payment-mode-item-card payment-mode-item-card-points"><div class="payment-mode-item-card-title">Canje con premios</div><div class="payment-mode-item-details">${copy}</div><div class="payment-win-points-message mt-3">${message}</div></div>`;
  }

  function paymentBinanceAccordionMarkup() {
    const pricing = resolvePaymentPricing('binance', null);
    const binanceMoney = resolveBinanceDisplayMoney(activePaymentOrder && activePaymentOrder.pack ? activePaymentOrder.pack : null, pricing.totalAmount);
    const totalText = escapePaymentHtml(String((binanceMoney && binanceMoney.text) || ''));
    const totalMarkup = totalText !== ''
      ? `<div class="payment-mode-item-currency">Total estimado en Binance Pay: ${totalText}</div>`
      : '';
    const discountMarkup = pricing.discountPercentage > 0
      ? `<div class="payment-mode-item-currency">Descuento disponible: ${escapePaymentHtml(formatDiscountPercentage(pricing.discountPercentage))}</div>`
      : '';
    return `<div class="payment-mode-item-card payment-mode-item-card-points"><div class="payment-mode-item-card-title">${escapePaymentHtml(binancePayButtonLabel)}</div>${totalMarkup}${discountMarkup}<div class="payment-mode-item-details">Paga de forma segura desde CoinPal usando tu cuenta de Binance Pay. Abriremos el checkout externo y esta ventana seguirá monitoreando la confirmación automáticamente.</div></div>`;
  }

  function paymentPayPalAccordionMarkup() {
    const pricing = resolvePaymentPricing('paypal', null);
    const totalText = escapePaymentHtml(String(pricing.totalText || ''));
    const totalMarkup = totalText !== ''
      ? `<div class="payment-mode-item-currency">Total estimado en PayPal: ${totalText}</div>`
      : '';
    return `<div class="payment-mode-item-card payment-mode-item-card-points"><div class="payment-mode-item-card-title">${escapePaymentHtml(paypalPayButtonLabel)}</div>${totalMarkup}<div class="payment-mode-item-details">Paga con tu cuenta, saldo o tarjeta a través del checkout oficial de PayPal. Abriremos una ventana externa y esta pantalla seguirá sincronizando el estado del pedido hasta que la confirmación quede registrada.</div></div>`;
  }

  function renderPaymentModeOptions() {
    if (!paymentModeOptions) {
      return;
    }

    if (!activePaymentOrder || !paymentWinPointsCard || paymentWinPointsCard.classList.contains('d-none')) {
      paymentModeOptions.innerHTML = '';
      return;
    }

    const methods = getPaymentMethodsForCurrency(activePaymentOrder.currency);
    const requiredPoints = Number(activePaymentOrder.pointsRequired || 0);
    const hasRule = !!(activePaymentOrder.pack && activePaymentOrder.pack.redeemActive && requiredPoints > 0);
    const showPointsOption = !!(winPointsState.enabled && winPointsState.loggedIn && hasRule);
    const buttonsHtml = methods.map((method) => {
      const methodId = escapePaymentHtml(String(method.id));
      const methodName = escapePaymentHtml(method.nombre || 'Método');
      const discountPercentage = resolvePaymentModeDiscountPercentage('money', method);
      const methodMeta = escapePaymentHtml(discountPercentage > 0 ? `${paymentMethodMetaLabel(method)} · ${formatDiscountPercentage(discountPercentage)} OFF` : paymentMethodMetaLabel(method));
      return `<div class="payment-mode-item" data-payment-option="money" data-method-id="${methodId}"><button type="button" class="payment-mode-btn" data-payment-option="money" data-method-id="${methodId}" aria-expanded="false"><span class="payment-mode-btn-main"><span class="payment-mode-btn-radio" aria-hidden="true"></span><span class="payment-mode-btn-text"><span class="payment-mode-btn-title">${methodName}</span><span class="payment-mode-btn-meta">${methodMeta}</span></span></span><span class="payment-mode-btn-caret" aria-hidden="true"></span></button><div class="payment-mode-item-body"><div class="payment-mode-item-body-inner">${paymentMethodAccordionMarkup(method)}</div></div></div>`;
    }).join('');
    const binanceHtml = activePaymentOrder.canUseBinance
      ? `<div class="payment-mode-item" data-payment-option="binance"><button type="button" class="payment-mode-btn" data-payment-option="binance" aria-expanded="false"><span class="payment-mode-btn-main"><span class="payment-mode-btn-radio" aria-hidden="true"></span><span class="payment-mode-btn-text"><span class="payment-mode-btn-title">${escapePaymentHtml(binancePayButtonLabel)}</span><span class="payment-mode-btn-meta">${escapePaymentHtml(resolvePaymentModeDiscountPercentage('binance', null) > 0 ? `Checkout externo seguro con CoinPal · ${formatDiscountPercentage(resolvePaymentModeDiscountPercentage('binance', null))} OFF` : 'Checkout externo seguro con CoinPal')}</span></span></span><span class="payment-mode-btn-caret" aria-hidden="true"></span></button><div class="payment-mode-item-body"><div class="payment-mode-item-body-inner">${paymentBinanceAccordionMarkup()}</div></div></div>`
      : '';
    const paypalHtml = activePaymentOrder.canUsePayPal
      ? `<div class="payment-mode-item" data-payment-option="paypal"><button type="button" class="payment-mode-btn" data-payment-option="paypal" aria-expanded="false"><span class="payment-mode-btn-main"><span class="payment-mode-btn-radio" aria-hidden="true"></span><span class="payment-mode-btn-text"><span class="payment-mode-btn-title">${escapePaymentHtml(paypalPayButtonLabel)}</span><span class="payment-mode-btn-meta">Checkout oficial seguro con captura automática</span></span></span><span class="payment-mode-btn-caret" aria-hidden="true"></span></button><div class="payment-mode-item-body"><div class="payment-mode-item-body-inner">${paymentPayPalAccordionMarkup()}</div></div></div>`
      : '';
    const pointsMeta = escapePaymentHtml(formatWinPointsAmount(winPointsState.balance || 0));
    const pointsHtml = `<div class="payment-mode-item" data-payment-option="points"><button type="button" class="payment-mode-btn" data-payment-option="points" aria-expanded="false"><span class="payment-mode-btn-main"><span class="payment-mode-btn-radio" aria-hidden="true"></span><span class="payment-mode-btn-text"><span class="payment-mode-btn-title">${escapePaymentHtml(paymentPointsOptionLabel(hasRule, requiredPoints))}</span><span class="payment-mode-btn-meta">Saldo disponible: ${pointsMeta}</span></span></span><span class="payment-mode-btn-caret" aria-hidden="true"></span></button><div class="payment-mode-item-body"><div class="payment-mode-item-body-inner">${paymentPointsAccordionMarkup()}</div></div></div>`;

    paymentModeOptions.innerHTML = `${buttonsHtml}${binanceHtml}${paypalHtml}${showPointsOption ? pointsHtml : ''}`;
    getPaymentModeButtons().forEach((button) => {
      button.addEventListener('click', function() {
        const buttonMode = button.dataset.paymentOption === 'points'
          ? 'points'
          : (button.dataset.paymentOption === 'binance' ? 'binance' : (button.dataset.paymentOption === 'paypal' ? 'paypal' : 'money'));
        const methodId = buttonMode === 'money' ? button.dataset.methodId || '' : '';
        setActivePaymentMode(buttonMode, methodId, { expandSelected: true });
      });
    });
  }

  function setActivePaymentMode(mode, preferredMethodId, options = {}) {
    if (!activePaymentOrder) {
      return;
    }

    const selectedMethod = resolveSelectedPaymentMethod(activePaymentOrder.currency, preferredMethodId || activePaymentOrder.selectedMethodId);
    const canUseMoney = !!selectedMethod && !!activePaymentOrder.canUseMoney;
    const canUseBinance = !!activePaymentOrder.canUseBinance;
    const canUsePayPal = !!activePaymentOrder.canUsePayPal;
    const canUsePoints = !!activePaymentOrder.canUsePoints;
    let nextMode = mode === 'points' ? 'points' : (mode === 'binance' ? 'binance' : (mode === 'paypal' ? 'paypal' : 'money'));

    activePaymentOrder.selectedMethodId = selectedMethod ? String(selectedMethod.id) : '';

    if (nextMode === 'points' && !canUsePoints) {
      nextMode = canUseMoney ? 'money' : (canUseBinance ? 'binance' : 'points');
    }
    if (nextMode === 'binance' && !canUseBinance) {
      nextMode = canUseMoney ? 'money' : (canUsePayPal ? 'paypal' : (canUsePoints ? 'points' : 'binance'));
    }
    if (nextMode === 'paypal' && !canUsePayPal) {
      nextMode = canUseMoney ? 'money' : (canUseBinance ? 'binance' : (canUsePoints ? 'points' : 'paypal'));
    }
    if (nextMode === 'money' && !canUseMoney) {
      nextMode = canUseBinance ? 'binance' : (canUsePayPal ? 'paypal' : (canUsePoints ? 'points' : 'money'));
    }

    activePaymentOrder.paymentMode = nextMode;
    const usingPoints = nextMode === 'points';
    const usingBinance = nextMode === 'binance';
    const usingPayPal = nextMode === 'paypal';
    const selectedOptionKey = paymentOptionKey(nextMode, selectedMethod ? selectedMethod.id : '');

    if (Object.prototype.hasOwnProperty.call(options, 'expandSelected')) {
      activePaymentOrder.expandedPaymentOptionKey = options.expandSelected ? selectedOptionKey : '';
    } else if (activePaymentOrder.expandedPaymentOptionKey === undefined) {
      activePaymentOrder.expandedPaymentOptionKey = '';
    }

    if (paymentMethodSelect) {
      paymentMethodSelect.value = selectedMethod ? String(selectedMethod.id) : '';
    }
    renderPaymentMethodDetails(selectedMethod || null, { mode: nextMode });
    updatePaymentPricingUi(usingBinance ? null : (selectedMethod || null));
    if (paymentMethodCard) {
      paymentMethodCard.classList.remove('d-none');
    }
    getPaymentModeButtons().forEach((button) => {
      const buttonMode = button.dataset.paymentOption === 'points'
        ? 'points'
        : (button.dataset.paymentOption === 'binance' ? 'binance' : (button.dataset.paymentOption === 'paypal' ? 'paypal' : 'money'));
      const buttonMethodId = button.dataset.methodId || '';
      const isSelected = buttonMode === 'points'
        ? usingPoints
        : (buttonMode === 'binance'
          ? usingBinance
          : (buttonMode === 'paypal'
            ? usingPayPal
            : (!usingPoints && !usingBinance && !usingPayPal && String(buttonMethodId) === String(activePaymentOrder.selectedMethodId || ''))));
      const isExpanded = paymentOptionKey(buttonMode, buttonMethodId) === String(activePaymentOrder.expandedPaymentOptionKey || '');
      const buttonItem = button.closest('.payment-mode-item');
      button.classList.toggle('is-active', isSelected);
      if (buttonItem) {
        buttonItem.classList.toggle('is-selected', isSelected);
        buttonItem.classList.toggle('is-expanded', isExpanded);
      }
      button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      button.disabled = buttonMode === 'points' ? !canUsePoints : (buttonMode === 'binance' ? !canUseBinance : (buttonMode === 'paypal' ? !canUsePayPal : !canUseMoney));
    });
    if (paymentReferenceGroup) {
      paymentReferenceGroup.classList.toggle('d-none', usingPoints || usingBinance || usingPayPal);
    }
    if (paymentPhoneGroup) {
      paymentPhoneGroup.classList.toggle('d-none', usingPoints || usingBinance || usingPayPal);
    }
    if (paymentMoneyPanel) {
      paymentMoneyPanel.classList.toggle('is-active', !usingPoints && (canUseMoney || canUseBinance || canUsePayPal));
    }
    if (paymentSubmitButton) {
      paymentSubmitButton.textContent = usingPoints
        ? `Canjear ${formatWinPointsAmount(activePaymentOrder.pointsRequired || 0)}`
        : (usingBinance ? 'Continuar con Binance Pay' : (usingPayPal ? 'Continuar con PayPal' : defaultPaymentSubmitButtonLabel));
    }
    activePaymentOrder.preferredMode = nextMode;
    storePreferredCheckoutPayment(nextMode, activePaymentOrder.selectedMethodId);
    renderPublicPaymentMethodCatalog(activePack);
  }

  function renderWinPointsPaymentState(pack, currentMethod) {
    if (!paymentWinPointsCard) {
      return;
    }

    if (!pack || !activePaymentOrder) {
      paymentWinPointsCard.classList.add('d-none');
      return;
    }

    const quantity = normalizeOrderQuantity(activePaymentOrder.purchaseQuantity || pack.purchaseQuantity || 1);
    const rewardPoints = getPackRewardPoints(pack, quantity);
    const requiredPoints = getPackRequiredPoints(pack, quantity);
    const hasRule = !!pack.redeemActive && requiredPoints > 0;
    const currentBalance = Number(winPointsState.balance || 0);
    const canUsePoints = hasRule && currentBalance >= requiredPoints;
    const canUseBinance = canUseBinanceCheckout(pack);
    const canUsePayPal = canUsePayPalCheckout(pack);
    const showRewardsState = !!(winPointsState.enabled && winPointsState.loggedIn);

    const resolvedMethod = resolveSelectedPaymentMethod(activePaymentOrder.currency, preferredCheckoutMethodId || (currentMethod ? currentMethod.id : ''));

    activePaymentOrder.canUseMoney = Boolean(resolvedMethod);
    activePaymentOrder.canUseBinance = canUseBinance;
    activePaymentOrder.canUsePayPal = canUsePayPal;
    activePaymentOrder.canUsePoints = showRewardsState ? canUsePoints : false;
    activePaymentOrder.pointsRequired = showRewardsState ? requiredPoints : 0;
    activePaymentOrder.purchaseQuantity = quantity;
    activePaymentOrder.selectedMethodId = resolvedMethod ? String(resolvedMethod.id) : '';
    activePaymentOrder.expandedPaymentOptionKey = '';

    paymentWinPointsCard.classList.remove('d-none');

    if (showRewardsState) {
      if (paymentWinPointsTitle) {
        paymentWinPointsTitle.textContent = 'Premios disponibles';
      }
      if (paymentWinPointsCopy) {
        paymentWinPointsCopy.textContent = (canUseBinance && canUsePayPal)
          ? 'Elige si deseas completar esta orden con transferencia, Binance Pay, PayPal o con tus premios acumulados.'
          : (canUseBinance
            ? 'Elige si deseas completar esta orden con transferencia, Binance Pay o con tus premios acumulados.'
            : (canUsePayPal
              ? 'Elige si deseas completar esta orden con transferencia, PayPal o con tus premios acumulados.'
              : 'Elige si deseas completar esta orden con transferencia o con tus premios acumulados.'));
      }
      paymentWinPointsBalance.textContent = formatWinPointsAmount(currentBalance);
      paymentWinPointsBalance.classList.remove('d-none');
    } else {
      if (paymentWinPointsTitle) {
        paymentWinPointsTitle.textContent = 'Metodos de pago disponibles';
      }
      if (paymentWinPointsCopy) {
        paymentWinPointsCopy.textContent = (canUseBinance && canUsePayPal)
          ? 'Elige si deseas completar esta orden manualmente, con Binance Pay o con PayPal.'
          : (canUseBinance
            ? 'Elige si deseas completar esta orden manualmente o con Binance Pay.'
            : (canUsePayPal
              ? 'Elige si deseas completar esta orden manualmente o con PayPal.'
              : 'Elige el metodo con el que deseas completar esta orden.'));
      }
      paymentWinPointsBalance.textContent = '';
      paymentWinPointsBalance.classList.add('d-none');
    }

    if (showRewardsState && rewardPoints > 0) {
      activePaymentOrder.pointsCopy = quantity > 1
        ? `Esta compra te entrega +${rewardPoints} ${winPointsState.name} cuando las ${quantity} recargas queden enviadas.`
        : `Este paquete te entrega +${rewardPoints} ${winPointsState.name} cuando la recarga quede enviada.`;
    } else {
      activePaymentOrder.pointsCopy = showRewardsState
        ? `Tu saldo disponible se puede usar en los paquetes que tengan canje activo.`
        : '';
    }

    if (showRewardsState && hasRule && canUsePoints) {
      activePaymentOrder.pointsMessage = quantity > 1
        ? `Puedes canjear ${quantity} recargas usando ${formatWinPointsAmount(requiredPoints)}.`
        : `Puedes canjear este paquete usando ${formatWinPointsAmount(requiredPoints)}.`;
    } else if (showRewardsState && hasRule) {
      activePaymentOrder.pointsMessage = `Necesitas ${formatWinPointsAmount(requiredPoints)} para canjear este paquete. Tu saldo actual es ${formatWinPointsAmount(currentBalance)}.`;
    } else {
      activePaymentOrder.pointsMessage = showRewardsState
        ? 'Este paquete no tiene una regla activa de canje por premios. Puedes pagar normal y seguir acumulando puntos.'
        : '';
    }

    if (paymentMethodSelectWrap) {
      paymentMethodSelectWrap.classList.add('d-none');
    }
    if (paymentModeOptions) {
      paymentModeOptions.innerHTML = '';
    }
    paymentWinPointsCard.classList.add('d-none');
    if (paymentMethodCard) {
      paymentMethodCard.classList.remove('d-none');
    }
    const preferredMode = String(activePaymentOrder.preferredMode || '').trim();
    const resolvedPreferredMode = showRewardsState
      ? (preferredMode === 'points' && activePaymentOrder.canUsePoints
        ? 'points'
        : (preferredMode === 'binance' && activePaymentOrder.canUseBinance
          ? 'binance'
          : (preferredMode === 'paypal' && activePaymentOrder.canUsePayPal
            ? 'paypal'
          : (preferredMode === 'money' && activePaymentOrder.canUseMoney
            ? 'money'
            : (activePaymentOrder.canUseMoney ? 'money' : (activePaymentOrder.canUsePayPal ? 'paypal' : (activePaymentOrder.canUsePoints ? 'points' : 'binance')))))))
      : (preferredMode === 'binance' && activePaymentOrder.canUseBinance
        ? 'binance'
        : (preferredMode === 'paypal' && activePaymentOrder.canUsePayPal
          ? 'paypal'
          : (activePaymentOrder.canUseMoney ? 'money' : (activePaymentOrder.canUsePayPal ? 'paypal' : 'binance'))));

    setActivePaymentMode(
      resolvedPreferredMode,
      activePaymentOrder.selectedMethodId,
      { expandSelected: false }
    );
  }

  function clearFieldValidation(field) {
    if (!field || !field.name) {
      return;
    }

    const errorElem = document.getElementById(field.name + '-error');
    if (errorElem) {
      errorElem.remove();
    }
  }

  function normalizeFieldOptions(fieldConfig) {
    const options = fieldConfig && Array.isArray(fieldConfig.options) ? fieldConfig.options : [];
    return options
      .map((option) => {
        if (option && typeof option === 'object') {
          return {
            value: String(option.value || '').trim(),
            label: String(option.label || option.value || '').trim()
          };
        }

        const normalized = String(option || '').trim();
        return { value: normalized, label: normalized };
      })
      .filter((option) => option.value !== '');
  }

  function sanitizeFieldPlaceholder(placeholder, fallback = 'Ingresa el dato') {
    const normalized = String(placeholder || '')
      .replace(/\bAPI\b/gi, ' ')
      .replace(/\s{2,}/g, ' ')
      .trim();

    return normalized || fallback;
  }

  function getPlayerVerificationDefaultFields() {
    if (!playerVerificationConfig || !Array.isArray(playerVerificationConfig.defaultFields)) {
      return [];
    }

    return playerVerificationConfig.defaultFields;
  }

  function createDynamicFieldControl(fieldConfig, fieldNamePrefix) {
    const options = normalizeFieldOptions(fieldConfig);
    const controlName = `${fieldNamePrefix}${fieldConfig.name || 'extra'}`;
    const hasOptions = options.length > 0;
    const control = document.createElement(hasOptions ? 'select' : 'input');

    if (hasOptions) {
      control.innerHTML = `<option value="">Selecciona una opcion</option>`;
      options.forEach((option) => {
        const optionElement = document.createElement('option');
        optionElement.value = option.value;
        optionElement.textContent = option.label || option.value;
        control.appendChild(optionElement);
      });
    } else {
      control.type = 'text';
      control.placeholder = sanitizeFieldPlaceholder(fieldConfig.placeholder, 'Ingresa el dato');
      control.inputMode = fieldConfig.inputMode || 'text';
      control.maxLength = Number(fieldConfig.maxLength || 180);
      if (fieldConfig.pattern) {
        control.pattern = String(fieldConfig.pattern);
      }
      if (fieldConfig.title) {
        control.title = String(fieldConfig.title);
      }
      if (fieldConfig.validationMessage) {
        control.dataset.validationMessage = String(fieldConfig.validationMessage);
      }
    }

    control.name = controlName;
    control.dataset.apiField = fieldConfig.name || '';
    control.className = hasOptions ? 'form-select bg-dark text-info border-info' : 'form-control bg-dark text-info border-info';
    control.required = true;

    return control;
  }

  function syncPrimaryControl(fieldConfig) {
    if (!playerPrimaryField || !playerPrimaryInput) {
      return;
    }

    const normalizedConfig = fieldConfig || defaultPrimaryField;
    const options = normalizeFieldOptions(normalizedConfig);
    const needsSelect = options.length > 0;
    const currentIsSelect = playerPrimaryInput.tagName === 'SELECT';

    if (needsSelect !== currentIsSelect) {
      const replacement = createDynamicFieldControl(normalizedConfig, 'user_');
      replacement.id = 'order-user-id';
      replacement.value = '';
      playerPrimaryInput.replaceWith(replacement);
      playerPrimaryInput = replacement;
    }

    playerPrimaryInput.name = 'user_id';
    playerPrimaryInput.dataset.apiField = normalizedConfig.name || defaultPrimaryField.name;
    playerPrimaryInput.required = true;
    if (playerPrimaryInput.tagName === 'SELECT') {
      playerPrimaryInput.className = 'form-select bg-dark text-info border-info';
    } else {
      playerPrimaryInput.className = 'form-control bg-dark text-info border-info';
      playerPrimaryInput.placeholder = sanitizeFieldPlaceholder(normalizedConfig.placeholder, defaultPrimaryField.placeholder);
      playerPrimaryInput.inputMode = normalizedConfig.inputMode || 'text';
      playerPrimaryInput.maxLength = Number(normalizedConfig.maxLength || defaultPrimaryField.maxLength);
      if (normalizedConfig.pattern) {
        playerPrimaryInput.pattern = String(normalizedConfig.pattern);
      } else {
        playerPrimaryInput.removeAttribute('pattern');
      }
      if (normalizedConfig.title) {
        playerPrimaryInput.title = String(normalizedConfig.title);
      } else {
        playerPrimaryInput.removeAttribute('title');
      }
      if (normalizedConfig.validationMessage) {
        playerPrimaryInput.dataset.validationMessage = String(normalizedConfig.validationMessage);
      } else {
        delete playerPrimaryInput.dataset.validationMessage;
      }
    }
  }

  function isCheckoutFieldValid(field) {
    if (!field) {
      return true;
    }

    const hasEnhancedValidation = Boolean(
      (field.dataset && field.dataset.validationMessage)
      || field.getAttribute('pattern')
    );
    if (!hasEnhancedValidation) {
      return true;
    }

    if (typeof field.setCustomValidity === 'function') {
      field.setCustomValidity('');
      if (field.dataset && field.dataset.validationMessage && field.value.trim() !== '' && !field.checkValidity()) {
        field.setCustomValidity(String(field.dataset.validationMessage));
      }
    }

    return typeof field.checkValidity === 'function' ? field.checkValidity() : field.value.trim() !== '';
  }

  function renderPlayerFields(pack) {
    const existingValues = collectPlayerFields();
    const packRequiredFields = pack && Array.isArray(pack.requiredFields) ? pack.requiredFields : [];
    const requiredFields = packRequiredFields.length ? packRequiredFields : getPlayerVerificationDefaultFields();
    const shouldShowPrimaryField = !isAccountSalePack(pack) && (!pack || pack.provider !== 'giftven' || requiredFields.length > 0);
    const primaryConfig = requiredFields[0] || defaultPrimaryField;
    setAccountSaleNote(pack);

    if (playerPrimaryField && playerPrimaryInput && playerPrimaryLabel) {
      syncPrimaryControl(primaryConfig);
      playerPrimaryField.classList.toggle('d-none', !shouldShowPrimaryField);
      playerPrimaryLabel.textContent = primaryConfig.label || defaultPrimaryField.label;
      playerPrimaryInput.dataset.apiField = primaryConfig.name || defaultPrimaryField.name;
      playerPrimaryInput.required = shouldShowPrimaryField;

      const primaryFieldName = String(primaryConfig.name || defaultPrimaryField.name);
      if (shouldShowPrimaryField && existingValues[primaryFieldName] && playerPrimaryInput.value.trim() === '') {
        playerPrimaryInput.value = existingValues[primaryFieldName];
      } else if (
        shouldShowPrimaryField
        && ['id_juego', 'id', 'uid'].includes(primaryFieldName)
        && defaultOrderUserIdentifier !== ''
        && playerPrimaryInput.value.trim() === ''
      ) {
        playerPrimaryInput.value = defaultOrderUserIdentifier;
      }

      if (!shouldShowPrimaryField) {
        playerPrimaryInput.value = '';
        clearFieldValidation(playerPrimaryInput);
      }
    }

    if (!extraPlayerFields) {
      return;
    }

    extraPlayerFields.innerHTML = '';
    requiredFields.slice(1).forEach((fieldConfig) => {
      const wrapper = document.createElement('div');
      wrapper.className = 'col-12';

      const label = document.createElement('label');
      label.className = 'form-label text-info';
      label.textContent = fieldConfig.label || 'Dato adicional';

      const input = createDynamicFieldControl(fieldConfig, 'player_field_');
      input.value = existingValues[fieldConfig.name || ''] || '';

      wrapper.appendChild(label);
      wrapper.appendChild(input);
      extraPlayerFields.appendChild(wrapper);
    });

    syncPlayerVerificationUi();
  }

  function collectPlayerFields() {
    const fields = {};

    if (playerPrimaryField && !playerPrimaryField.classList.contains('d-none') && playerPrimaryInput) {
      const fieldName = String(playerPrimaryInput.dataset.apiField || defaultPrimaryField.name);
      const fieldValue = playerPrimaryInput.value.trim();
      if (fieldValue !== '') {
        fields[fieldName] = fieldValue;
      }
    }

    if (extraPlayerFields) {
      extraPlayerFields.querySelectorAll('[data-api-field]').forEach((input) => {
        const fieldName = String(input.dataset.apiField || '');
        const fieldValue = input.value.trim();
        if (fieldName !== '' && fieldValue !== '') {
          fields[fieldName] = fieldValue;
        }
      });
    }

    return fields;
  }

  function buildPlayerVerificationPayload() {
    const userIdentifier = playerPrimaryInput ? playerPrimaryInput.value.trim() : '';
    const playerFields = collectPlayerFields();

    return {
      userIdentifier,
      playerFields,
      signature: JSON.stringify({
        gameKey: playerVerificationConfig ? playerVerificationConfig.gameKey : '',
        userIdentifier,
        playerFields,
      }),
    };
  }

  function getPlayerVerificationZoneValue(playerFields) {
    const fields = playerFields && typeof playerFields === 'object' ? playerFields : {};
    const candidates = ['input2', 'zone_id', 'zoneid', 'zone', 'server_id', 'serverid', 'server'];

    for (const candidate of candidates) {
      if (typeof fields[candidate] === 'string' && fields[candidate].trim() !== '') {
        return fields[candidate].trim();
      }
    }

    const extraValue = Object.entries(fields)
      .filter(([fieldName, fieldValue]) => String(fieldName || '') !== String(playerPrimaryInput ? playerPrimaryInput.dataset.apiField || '' : '') && String(fieldValue || '').trim() !== '')
      .map(([, fieldValue]) => String(fieldValue || '').trim())[0];

    return extraValue || '';
  }

  function hasPlayerVerificationInputs(payload) {
    if (!playerVerificationConfig) {
      return false;
    }

    const currentPayload = payload || buildPlayerVerificationPayload();
    if (currentPayload.userIdentifier === '') {
      return false;
    }

    if (playerVerificationConfig.requiresZone) {
      return getPlayerVerificationZoneValue(currentPayload.playerFields) !== '';
    }

    return true;
  }

  function clearPlayerVerificationFeedback() {
    if (!playerVerificationFeedback) {
      return;
    }

    playerVerificationFeedback.className = 'd-none mt-2';
    playerVerificationFeedback.textContent = '';
  }

  function setPlayerVerificationFeedback(type, message) {
    if (!playerVerificationFeedback) {
      return;
    }

    if (!message) {
      clearPlayerVerificationFeedback();
      return;
    }

    const alertType = type === 'success' ? 'success' : (type === 'info' ? 'info' : 'danger');
    playerVerificationFeedback.className = `alert alert-${alertType} py-2 px-3 mt-2 mb-0 small fw-semibold`;
    playerVerificationFeedback.textContent = message;
  }

  function clearPlayerVerificationAutoTimer() {
    if (playerVerificationAutoTimer) {
      window.clearTimeout(playerVerificationAutoTimer);
      playerVerificationAutoTimer = null;
    }
  }

  function invalidatePlayerVerificationRequests() {
    playerVerificationRequestSeq += 1;
    playerVerificationPendingSignature = '';
    clearPlayerVerificationAutoTimer();
  }

  function resetPlayerVerificationState(clearFeedback = true) {
    clearPlayerVerificationAutoTimer();
    playerVerificationPendingSignature = '';
    playerVerificationState = {
      verified: false,
      playerName: '',
      signature: '',
      pending: false,
      serverUnavailable: false,
    };

    if (clearFeedback) {
      clearPlayerVerificationFeedback();
    }
  }

  function setPlayerVerificationUnavailableState(signature, message) {
    clearPlayerVerificationAutoTimer();
    playerVerificationPendingSignature = '';
    playerVerificationState = {
      verified: false,
      playerName: '',
      signature: signature,
      pending: false,
      serverUnavailable: true,
    };

    const baseMessage = String(message || 'No se pudo verificar el jugador en este momento.').trim();
    setPlayerVerificationFeedback('info', `${baseMessage} Puedes continuar con la recarga normal.`);
  }

  function shouldAllowCheckoutOnVerificationFailure(status, message, httpStatus) {
    const normalizedStatus = String(status || '').trim().toLowerCase();
    const normalizedMessage = String(message || '').trim().toLowerCase();
    const numericHttpStatus = Number(httpStatus || 0);

    if (normalizedStatus === 'unavailable' || numericHttpStatus >= 500) {
      return true;
    }

    const temporaryFailureSnippets = [
      'no player data found for uid',
      'service unavailable',
      'temporarily unavailable',
      'internal server error',
      'gateway timeout',
      'bad gateway',
      'request timeout',
      'try again later',
      'connection refused',
      'connection reset',
      'upstream',
      'timeout',
    ];

    return temporaryFailureSnippets.some((snippet) => normalizedMessage.includes(snippet));
  }

  function activePackSupportsPlayerVerification() {
    if (!playerVerificationConfig || !activePack || isAccountSalePack(activePack)) {
      return false;
    }

    return String(activePack.provider || '').trim().toLowerCase() !== 'discord';
  }

  function requiresVerifiedPlayerForCheckout() {
    if (!activePackSupportsPlayerVerification()) {
      return false;
    }

    return Boolean(
      playerVerificationConfig
      && (playerVerificationState.pending || (!playerVerificationState.verified && !playerVerificationState.serverUnavailable))
    );
  }

  function syncPlayerVerificationUi() {
    if (!verifyPlayerButton) {
      return;
    }

    if (!activePackSupportsPlayerVerification()) {
      verifyPlayerButton.classList.add('d-none');
      return;
    }

    verifyPlayerButton.classList.remove('d-none');
    verifyPlayerButton.disabled = playerVerificationState.pending || !hasPlayerVerificationInputs();
    verifyPlayerButton.textContent = playerVerificationState.pending
      ? 'Verificando...'
      : (playerVerificationConfig.buttonLabel || 'Verificar nombre del jugador');
  }

  function handlePlayerVerificationFieldChange() {
    if (!activePackSupportsPlayerVerification()) {
      invalidatePlayerVerificationRequests();
      resetPlayerVerificationState();
      syncPlayerVerificationUi();
      return;
    }

    const payload = buildPlayerVerificationPayload();
    const hasInputs = hasPlayerVerificationInputs(payload);
    const currentSignature = String(payload.signature || '');

    if (!hasInputs) {
      invalidatePlayerVerificationRequests();
      resetPlayerVerificationState();
      syncPlayerVerificationUi();
      return;
    }

    if (playerVerificationPendingSignature !== '' && playerVerificationPendingSignature !== currentSignature) {
      playerVerificationRequestSeq += 1;
      playerVerificationPendingSignature = '';
    }

    if (playerVerificationState.signature !== '' && playerVerificationState.signature !== currentSignature) {
      resetPlayerVerificationState();
    }

    syncPlayerVerificationUi();

    const alreadyHandledCurrentSignature = currentSignature !== '' && (
      (playerVerificationState.signature === currentSignature && (playerVerificationState.verified || playerVerificationState.serverUnavailable))
      || playerVerificationPendingSignature === currentSignature
    );

    if (alreadyHandledCurrentSignature) {
      return;
    }

    clearPlayerVerificationAutoTimer();
    playerVerificationAutoTimer = window.setTimeout(() => {
      verifyCurrentPlayer({ autoTriggered: true, expectedSignature: currentSignature });
    }, 450);
  }

  async function verifyCurrentPlayer(options = {}) {
    if (!activePackSupportsPlayerVerification()) {
      return;
    }

    clearPlayerVerificationAutoTimer();

    const payload = buildPlayerVerificationPayload();
    if (options.expectedSignature && payload.signature !== options.expectedSignature) {
      return;
    }
    if (!hasPlayerVerificationInputs(payload)) {
      setPlayerVerificationFeedback('danger', playerVerificationConfig.requiresZone
        ? 'Debes ingresar el ID del jugador y la Zona ID para verificar.'
        : 'Debes ingresar el ID del jugador para verificar.');
      updateButtonState();
      return;
    }

    const requestId = ++playerVerificationRequestSeq;
    playerVerificationPendingSignature = payload.signature;

    playerVerificationState.pending = true;
    playerVerificationState.serverUnavailable = false;
    syncPlayerVerificationUi();
    setPlayerVerificationFeedback('info', 'Verificando nombre del jugador...');
    updateButtonState();

    try {
      const requestBody = new URLSearchParams();
      requestBody.set('game_id', "<?= (string) ($game['id'] ?? '') ?>");
      requestBody.set('package_id', String((activePack && activePack.id) || ''));
      requestBody.set('user_identifier', payload.userIdentifier);
      requestBody.set('player_fields_json', JSON.stringify(payload.playerFields));

      const response = await fetch(buildAppUrl('/api/verify_player.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: requestBody.toString(),
      });

      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }

      if (requestId !== playerVerificationRequestSeq) {
        return;
      }

      if (response.ok && data && data.ok) {
        playerVerificationState = {
          verified: true,
          playerName: String(data.player_name || ''),
          signature: payload.signature,
          pending: false,
          serverUnavailable: false,
        };
        setPlayerVerificationFeedback('success', String(data.message || 'Jugador encontrado.'));
      } else {
        const verificationStatus = String((data && data.status) || '').toLowerCase();
        const verificationMessage = String((data && data.message) || 'No se pudo verificar el jugador.');
        if (shouldAllowCheckoutOnVerificationFailure(verificationStatus, verificationMessage, response.status)) {
          setPlayerVerificationUnavailableState(payload.signature, verificationMessage);
        } else {
          resetPlayerVerificationState(false);
          setPlayerVerificationFeedback('danger', verificationMessage);
        }
      }
    } catch (error) {
      if (requestId !== playerVerificationRequestSeq) {
        return;
      }
      setPlayerVerificationUnavailableState(payload.signature, 'No se pudo verificar el jugador en este momento.');
    } finally {
      if (requestId !== playerVerificationRequestSeq) {
        return;
      }
      playerVerificationPendingSignature = '';
      playerVerificationState.pending = false;
      syncPlayerVerificationUi();
      updateButtonState();
    }
  }

  function scrollToOrderForm() {
    if (!orderForm) {
      return;
    }

    window.setTimeout(() => {
      scrollViewportToElement(orderForm, { duration: 520, offset: 18 });
    }, 120);
  }

  let activeViewportScrollFrame = null;

  function easeViewportScroll(progress) {
    if (progress <= 0) {
      return 0;
    }
    if (progress >= 1) {
      return 1;
    }
    return progress < 0.5
      ? 4 * progress * progress * progress
      : 1 - Math.pow(-2 * progress + 2, 3) / 2;
  }

  function scrollViewportToElement(targetElement, options = {}) {
    if (!(targetElement instanceof HTMLElement)) {
      return;
    }

    const scrollRoot = document.scrollingElement || document.documentElement;
    const startY = window.pageYOffset || scrollRoot.scrollTop || 0;
    const offset = Number.isFinite(Number(options.offset)) ? Number(options.offset) : 0;
    const maxScrollY = Math.max(0, scrollRoot.scrollHeight - window.innerHeight);
    const targetRect = targetElement.getBoundingClientRect();
    const targetY = Math.min(maxScrollY, Math.max(0, startY + targetRect.top - offset));
    const distance = targetY - startY;
    const duration = Math.max(260, Number.isFinite(Number(options.duration)) ? Number(options.duration) : 520);

    if (Math.abs(distance) < 2) {
      window.scrollTo(0, targetY);
      return;
    }

    if (activeViewportScrollFrame !== null) {
      window.cancelAnimationFrame(activeViewportScrollFrame);
      activeViewportScrollFrame = null;
    }

    const animationStart = window.performance && typeof window.performance.now === 'function'
      ? window.performance.now()
      : Date.now();

    const step = (timestamp) => {
      const now = Number.isFinite(timestamp) ? timestamp : Date.now();
      const elapsed = now - animationStart;
      const progress = Math.min(1, elapsed / duration);
      const easedProgress = easeViewportScroll(progress);
      window.scrollTo(0, startY + (distance * easedProgress));

      if (progress < 1) {
        activeViewportScrollFrame = window.requestAnimationFrame(step);
      } else {
        activeViewportScrollFrame = null;
        window.scrollTo(0, targetY);
      }
    };

    activeViewportScrollFrame = window.requestAnimationFrame(step);
  }

  function scrollToPackageSelectionDetails() {
    const scrollTarget = purchaseQuantityPanel && !purchaseQuantityPanel.classList.contains('d-none')
      ? purchaseQuantityPanel
      : (purchaseSummaryLayout || orderForm);
    if (!scrollTarget) {
      return;
    }

    window.setTimeout(() => {
      scrollViewportToElement(scrollTarget, { duration: 620, offset: 18 });
    }, 120);
  }

  function scrollToPackPricingSection() {
    const scrollTarget = packGrid || purchaseSummaryLayout;
    if (!scrollTarget) {
      return;
    }

    window.setTimeout(() => {
      scrollViewportToElement(scrollTarget, { duration: 560, offset: 18 });
    }, 120);
  }

  function syncOverlayState() {
    const overlayVisible = Boolean(document.querySelector('.app-overlay-modal.is-visible'));
    document.body.classList.toggle('overlay-open', overlayVisible);
    document.querySelectorAll('.floating-social-stack').forEach((element) => {
      if (!(element instanceof HTMLElement)) {
        return;
      }
      element.style.opacity = overlayVisible ? '0' : '';
      element.style.visibility = overlayVisible ? 'hidden' : '';
      element.style.pointerEvents = overlayVisible ? 'none' : '';
    });
  }

  function setOverlayVisible(modalElement, visible) {
    if (!modalElement) {
      return;
    }
    if (visible) {
      lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    } else if (modalElement.contains(document.activeElement) && document.activeElement instanceof HTMLElement) {
      document.activeElement.blur();
    }
    modalElement.classList.toggle('show', visible);
    modalElement.classList.toggle('is-visible', visible);
    modalElement.setAttribute('aria-hidden', visible ? 'false' : 'true');
    syncOverlayState();
    if (visible) {
      const autofocusTarget = modalElement.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (autofocusTarget instanceof HTMLElement) {
        setTimeout(() => autofocusTarget.focus(), 0);
      }
    } else if (lastFocusedElement instanceof HTMLElement && document.body.contains(lastFocusedElement)) {
      setTimeout(() => {
        if (lastFocusedElement instanceof HTMLElement && document.body.contains(lastFocusedElement)) {
          lastFocusedElement.focus();
        }
        lastFocusedElement = null;
      }, 0);
    } else {
      lastFocusedElement = null;
    }
  }

  function syncGameEntryWindowState() {
    if (!gameEntryWindowCheckbox || !gameEntryWindowContinueButton) {
      return;
    }

    if (gameEntryWindowConfirmation) {
      gameEntryWindowConfirmation.classList.toggle('is-checked', !!gameEntryWindowCheckbox.checked);
    }
    gameEntryWindowContinueButton.disabled = !gameEntryWindowCheckbox.checked;
  }

  function setGameEntryWindowChecked(checked) {
    if (!gameEntryWindowCheckbox) {
      return;
    }

    gameEntryWindowCheckbox.checked = !!checked;
    syncGameEntryWindowState();
  }

  window.toggleGameEntryWindowConfirmation = function (forceChecked) {
    if (!gameEntryWindowCheckbox) {
      return;
    }

    if (typeof forceChecked === 'boolean') {
      setGameEntryWindowChecked(forceChecked);
      return;
    }

    setGameEntryWindowChecked(!gameEntryWindowCheckbox.checked);
  };

  function acceptGameEntryWindow() {
    if (!gameEntryWindowCheckbox || !gameEntryWindowCheckbox.checked) {
      syncGameEntryWindowState();
      return false;
    }

    gameEntryWindowAccepted = true;
    setOverlayVisible(gameEntryWindowModal, false);
    if (gameEntryWindowContinueButton instanceof HTMLElement) {
      gameEntryWindowContinueButton.blur();
    }
    updateButtonState();
    return false;
  }

  window.acceptGameEntryWindow = acceptGameEntryWindow;

  function openGameEntryWindowIfNeeded() {
    if (!gameEntryWindowEnabled || !gameEntryWindowModal) {
      gameEntryWindowAccepted = true;
      updateButtonState();
      return;
    }

    gameEntryWindowAccepted = false;
    setGameEntryWindowChecked(false);
    setOverlayVisible(gameEntryWindowModal, true);
    updateButtonState();
  }

  if (gameEntryWindowCheckbox) {
    gameEntryWindowCheckbox.addEventListener('change', function () {
      syncGameEntryWindowState();
    });
  }

  if (gameEntryWindowContinueButton) {
    gameEntryWindowContinueButton.addEventListener('click', function (event) {
      event.preventDefault();
      acceptGameEntryWindow();
    });
  }

  function keepPaymentFieldVisible(target) {
    if (!(target instanceof HTMLElement) || !paymentModal || !paymentModal.classList.contains('is-visible')) {
      return;
    }

    if (!paymentModal.contains(target) || window.innerWidth > 575.98) {
      return;
    }

    window.setTimeout(() => {
      target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    }, 220);
  }

  if (paymentModal) {
    paymentModal.addEventListener('focusin', (event) => {
      keepPaymentFieldVisible(event.target);
    });
  }

  function removeBuySpinner() {
    const spinner = document.getElementById('spinner-compra');
    if (spinner) {
      spinner.remove();
    }
  }

  function setLoadingModalContent(title, message, state = 'processing') {
    if (loadingModalTitle) {
      loadingModalTitle.textContent = title || 'Procesando pedido...';
    }
    if (loadingModalMessage) {
      loadingModalMessage.textContent = message || 'Espera un momento mientras completamos la operación.';
    }
    if (loadingModal && paymentWindowThemeEnabled) {
      loadingModal.setAttribute('data-payment-loading-state', state === 'sending' ? 'sending' : 'processing');
    }
  }

  function scrollPaymentModalToTop() {
    if (paymentModalContent) {
      paymentModalContent.scrollTop = 0;
    }
    if (paymentModal) {
      paymentModal.scrollTop = 0;
    }
  }

  function scrollPaymentSubmitIntoView() {
    if (!paymentSubmitButton) {
      return;
    }

    window.setTimeout(() => {
      paymentSubmitButton.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    }, 120);
  }

  function showPaymentStatusModal(title, message, type, options = {}) {
    const normalizedType = type === 'success' || type === 'danger' ? type : 'info';
    const successExtraMessage = normalizedType === 'success'
      ? String(paymentSuccessContent.extraMessage || '').trim()
      : '';
    const contextualExtraMessage = normalizedType !== 'danger'
      ? String((options && options.extraMessage) || '').trim()
      : '';
    const extraMessageMarkup = [];
    if (successExtraMessage !== '') {
      extraMessageMarkup.push(`<span class="payment-status-extra-copy">${escapePaymentHtml(successExtraMessage)}</span>`);
    }
    if (contextualExtraMessage !== '') {
      extraMessageMarkup.push(`<span class="payment-status-extra-copy" style="display:block;margin-top:${successExtraMessage !== '' ? '0.5rem' : '0'};color:#22c55e;font-weight:700;opacity:1;">${escapePaymentHtml(contextualExtraMessage)}</span>`);
    }
    if (paymentStatusModalTitle) {
      const resolvedTitle = normalizedType === 'success'
        ? (String(paymentSuccessContent.title || '').trim() || title || 'Pago exitoso')
        : (title || 'Estado de la operación');
      paymentStatusModalTitle.textContent = resolvedTitle;
      paymentStatusModalTitle.classList.remove('text-info', 'text-success', 'text-danger');
      paymentStatusModalTitle.classList.add(normalizedType === 'success' ? 'text-success' : (normalizedType === 'danger' ? 'text-danger' : 'text-info'));
    }
    if (paymentStatusModalMessage) {
      paymentStatusModalMessage.textContent = message || 'Tu solicitud fue procesada.';
      paymentStatusModalMessage.classList.toggle('mb-2', extraMessageMarkup.length > 0);
      paymentStatusModalMessage.classList.toggle('mb-4', extraMessageMarkup.length === 0);
    }
    if (paymentStatusModalExtraMessage) {
      if (extraMessageMarkup.length > 0) {
        paymentStatusModalExtraMessage.innerHTML = extraMessageMarkup.join('');
        paymentStatusModalExtraMessage.classList.remove('d-none');
      } else {
        paymentStatusModalExtraMessage.textContent = '';
        paymentStatusModalExtraMessage.innerHTML = '';
        paymentStatusModalExtraMessage.classList.add('d-none');
      }
    }
    if (paymentStatusModal && paymentWindowThemeEnabled) {
      paymentStatusModal.setAttribute('data-payment-status-state', normalizedType);
    }
    scrollPaymentModalToTop();
    setOverlayVisible(paymentStatusModal, true);
  }

  function clearPaymentStatusPolling() {
    if (paymentStatusPollTimer) {
      clearTimeout(paymentStatusPollTimer);
      paymentStatusPollTimer = null;
    }
    if (paymentStatusModalAccept) {
      paymentStatusModalAccept.disabled = false;
      paymentStatusModalAccept.textContent = defaultPaymentStatusAcceptLabel;
    }
  }

  function setPaymentStatusWaiting(isWaiting) {
    if (!paymentStatusModalAccept) {
      return;
    }
    paymentStatusModalAccept.disabled = !!isWaiting;
    paymentStatusModalAccept.textContent = isWaiting ? 'Esperando confirmación...' : defaultPaymentStatusAcceptLabel;
  }

  async function pollOrderResolution(reference, totalText, attempt = 1) {
    if (!activePaymentOrder || !activePaymentOrder.orderId) {
      clearPaymentStatusPolling();
      return;
    }

    const maxAttempts = 15;
    const pollDelayMs = 4000;
    const payload = new URLSearchParams();
    payload.set('action', 'order_status');
    payload.set('order_id', String(activePaymentOrder.orderId));
    payload.set('attempt_sync', '1');
    if (activePaymentOrder.email) {
      payload.set('email', String(activePaymentOrder.email));
    }

    try {
      const response = await fetch(buildAppUrl('/api/pedidos.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString(),
      });
      const data = await parseApiJsonResponse(response, 'No se pudo consultar el estado del pedido en este momento.');
      if (!response.ok || !data.ok) {
        throw new Error((data && data.message) ? data.message : 'No se pudo consultar el estado del pedido.');
      }

      const nextState = String((data && data.estado) || '').toLowerCase();
      const providerFlow = String((data && data.provider_flow) || '').toLowerCase();
      if (nextState === 'enviado') {
        clearPaymentStatusPolling();
        renderDeliveredCodes(data);
        const successMessage = getAccountSalePayload(data)
          ? 'Pago verificado y cuenta entregada correctamente.'
          : 'Pago verificado y recarga procesada correctamente.';
        const successNote = buildBloodStrikeEliteDiscordSuccessNote(data);
        setPaymentAlert(successMessage, 'success', { extraMessage: successNote });
        setPaymentFormDisabled(true);
        clearPaymentTimer();
        setCancelOrderButtonMode('close');
        showPaymentStatusModal('Operación exitosa', successMessage, 'success', { extraMessage: successNote });
        return;
      }

      if (nextState === 'cancelado') {
        clearPaymentStatusPolling();
        const cancelMessage = (data && data.provider_message) ? data.provider_message : 'El proveedor canceló la compra.';
        setPaymentAlert(cancelMessage, 'danger');
        renderProviderPaymentDetails(data, reference, totalText);
        setPaymentFormDisabled(true);
        clearPaymentTimer();
        setCancelOrderButtonMode('close');
        showPaymentStatusModal('No se pudo completar la operación', cancelMessage, 'danger');
        return;
      }

      if (nextState === 'pagado') {
        const paidMessage = (data && data.provider_message) ? data.provider_message : 'El pago fue confirmado correctamente.';
        const hasProviderDetails = extractPaymentReasons(data).length > 0;
        const isAcceptedFlow = providerFlow === 'accepted' || providerFlow === 'tracking';
        const requiresManualReview = providerFlow === 'manual_review' || providerFlow === 'inventory_shortage' || (!isAcceptedFlow && hasProviderDetails);

        if (!isAcceptedFlow) {
          clearPaymentStatusPolling();
          const paidNote = requiresManualReview ? '' : buildBloodStrikeEliteDiscordSuccessNote(data);
          setPaymentAlert(paidMessage, requiresManualReview ? 'warning' : 'success', { extraMessage: paidNote });
          if (providerFlow === 'inventory_shortage') {
            renderProviderPaymentDetails(data, reference, totalText);
          } else {
            clearPaymentSupportUi();
          }
          setPaymentFormDisabled(true);
          clearPaymentTimer();
          setCancelOrderButtonMode('close');
          showPaymentStatusModal(requiresManualReview ? 'Revisión requerida' : 'Operación exitosa', paidMessage, requiresManualReview ? 'danger' : 'success', { extraMessage: paidNote });
          return;
        }
      }

      if (nextState === 'pagado') {
        const paidMessage = (data && data.provider_message) ? data.provider_message : 'El pago fue confirmado correctamente.';
        const hasProviderDetails = extractPaymentReasons(data).length > 0;
        const isAcceptedFlow = providerFlow === 'accepted' || providerFlow === 'tracking';
        const requiresManualReview = providerFlow === 'manual_review' || providerFlow === 'inventory_shortage' || (!isAcceptedFlow && hasProviderDetails);

        if (!isAcceptedFlow) {
          clearPaymentStatusPolling();
          const paidNote = requiresManualReview ? '' : buildBloodStrikeEliteDiscordSuccessNote(data);
          setPaymentAlert(paidMessage, requiresManualReview ? 'warning' : 'success', { extraMessage: paidNote });
          if (providerFlow === 'inventory_shortage') {
            renderProviderPaymentDetails(data, reference, totalText);
          } else {
            clearPaymentSupportUi();
          }
          setPaymentFormDisabled(true);
          clearPaymentTimer();
          setCancelOrderButtonMode('close');
          showPaymentStatusModal(requiresManualReview ? 'Revisión requerida' : 'Operación exitosa', paidMessage, requiresManualReview ? 'danger' : 'success', { extraMessage: paidNote });
          return;
        }
      }

      if (nextState === 'pendiente' && providerFlow === 'binance_checkout') {
        const pendingMessage = (data && data.provider_message) ? data.provider_message : 'Completa el pago en Binance Pay para continuar con tu pedido.';
        setPaymentAlert(pendingMessage, 'info');
        renderBinancePaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, totalText);
      }

      if (nextState === 'pendiente' && providerFlow === 'paypal_checkout') {
        const pendingMessage = (data && data.provider_message) ? data.provider_message : 'Completa el pago en PayPal para continuar con tu pedido.';
        setPaymentAlert(pendingMessage, 'info');
        renderPayPalPaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, totalText);
      }

      if (attempt >= maxAttempts) {
        clearPaymentStatusPolling();
        if (providerFlow === 'binance_checkout') {
          setPaymentAlert('El checkout sigue pendiente. Puedes completar el pago y volver a esta ventana para continuar el seguimiento.', 'info');
          renderBinancePaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, totalText);
          showPaymentStatusModal('Pago pendiente en Binance Pay', 'El checkout sigue pendiente. Puedes dejar esta ventana abierta mientras completas el pago.', 'info');
        } else if (providerFlow === 'paypal_checkout') {
          setPaymentAlert('El checkout de PayPal sigue pendiente. Puedes completar el pago y dejar esta ventana abierta para continuar el seguimiento.', 'info');
          renderPayPalPaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, totalText);
          showPaymentStatusModal('Pago pendiente en PayPal', 'El checkout de PayPal sigue pendiente. Puedes dejar esta ventana abierta mientras completas el pago.', 'info');
        } else {
          const successPresentation = successfulProviderPendingPresentation(providerFlow, data);
          setPaymentAlert(successPresentation.message, successPresentation.statusType || 'info');
          renderProviderPaymentDetails(data, reference, totalText);
          showPaymentStatusModal(successPresentation.title, successPresentation.message, successPresentation.statusType || 'info');
        }
        return;
      }

      if (providerFlow === 'binance_checkout') {
        renderBinancePaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, totalText);
      } else if (providerFlow === 'paypal_checkout') {
        renderPayPalPaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, totalText);
      } else {
        renderProviderPaymentDetails(data, reference, totalText);
      }
      setPaymentStatusWaiting(true);
      paymentStatusPollTimer = setTimeout(() => {
        pollOrderResolution(reference, totalText, attempt + 1);
      }, pollDelayMs);
    } catch (error) {
      if (attempt >= maxAttempts) {
        clearPaymentStatusPolling();
        return;
      }

      paymentStatusPollTimer = setTimeout(() => {
        pollOrderResolution(reference, totalText, attempt + 1);
      }, 5000);
    }
  }

  function showToast(msg, type) {
    const toast = document.createElement('div');
    toast.textContent = msg;
    toast.style.position = 'fixed';
    toast.style.top = '30px';
    toast.style.left = '50%';
    toast.style.transform = 'translateX(-50%)';
    toast.style.background = type === 'error' ? '#f87171' : '#34d399';
    toast.style.color = '#222';
    toast.style.padding = '12px 24px';
    toast.style.borderRadius = '8px';
    toast.style.fontWeight = 'bold';
    toast.style.zIndex = '9999';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
  }

  async function parseApiJsonResponse(response, fallbackMessage) {
    const rawText = await response.text();
    const trimmed = String(rawText || '').trim();

    if (trimmed === '') {
      if (response.ok) {
        return {};
      }
      throw new Error(fallbackMessage || 'No se pudo procesar la respuesta del servidor.');
    }

    try {
      return JSON.parse(trimmed);
    } catch (error) {
      throw new Error(fallbackMessage || 'No se pudo procesar la respuesta del servidor.');
    }
  }

  function normalizeApiRequestErrorMessage(error, fallbackMessage) {
    const rawMessage = String((error && error.message) || '').trim();
    if (rawMessage === '') {
      return fallbackMessage;
    }

    const loweredMessage = rawMessage.toLowerCase();
    if (loweredMessage.includes('signature verification failed')) {
      return 'No se pudo validar Binance Pay con la configuración actual de la tienda. Intenta de nuevo o contacta al administrador.';
    }

    if (loweredMessage.includes('signature verification failed')) {
      return 'No se pudo validar Binance Pay con la configuración actual de la tienda. Intenta de nuevo o contacta al administrador.';
    }

    if (
      loweredMessage === 'failed to fetch'
      || loweredMessage.includes('unexpected token')
      || loweredMessage.includes('is not valid json')
      || loweredMessage.includes('<!doctype')
      || loweredMessage.includes('<html')
    ) {
      return fallbackMessage;
    }

    return rawMessage;
  }

  function clearPaymentTimer() {
    if (paymentTimerInterval) {
      clearInterval(paymentTimerInterval);
      paymentTimerInterval = null;
    }
  }

  function escapePaymentHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function normalizePaymentContextText(value) {
    let normalized = String(value || '').trim();
    if (normalized === '') {
      return '';
    }

    if (typeof normalized.normalize === 'function') {
      normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    return normalized.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  }

  function buildBloodStrikeEliteDiscordSuccessNote(data) {
    const providerName = normalizePaymentContextText((activePack && activePack.provider) || '');
    if (providerName !== 'discord') {
      return '';
    }

    const normalizedGameName = normalizePaymentContextText((data && data.game_name) || currentGameName || '');
    if (!normalizedGameName.includes('blood strike') && !normalizedGameName.includes('bloodstriker')) {
      return '';
    }

    const packName = String((data && (data.pack_name || data.package_name)) || (activePack && activePack.name) || '').trim();
    const normalizedPackName = normalizePaymentContextText(packName);
    if (!normalizedPackName.includes('elite')) {
      return '';
    }

    const purchaseName = packName !== '' ? packName : 'tu compra';
    return `Luego de la compra, espera un aproximado de 15 min para que se ejecute ${purchaseName}.`;
  }

  function paymentReferencePlaceholder(method) {
    const digits = Number(method && method.referencia_digitos ? method.referencia_digitos : 0);
    if (digits > 0) {
      return `Inserte los últimos ${digits} dígitos de su referencia`;
    }
    return 'Inserte su número de referencia para comprobar el pago';
  }

  function paymentReferenceHelpText(method) {
    const digits = Number(method && method.referencia_digitos ? method.referencia_digitos : 0);
    if (digits > 0) {
      return `Solo debes escribir los últimos ${digits} dígitos de la referencia bancaria.`;
    }
    return 'Inserte su número de referencia para comprobar el pago.';
  }

  function getPaymentMethodsForCurrency(currencyCode) {
    const preferredCurrency = String(currencyCode || '').toUpperCase();
    const methods = [];
    const seenIds = new Set();

    const appendMethods = (items) => {
      (Array.isArray(items) ? items : []).forEach((method) => {
        const methodId = String(method && method.id ? method.id : '');
        if (!methodId || seenIds.has(methodId)) {
          return;
        }
        seenIds.add(methodId);
        methods.push(method);
      });
    };

    if (preferredCurrency) {
      appendMethods(paymentMethodsByCurrency[preferredCurrency]);
    }

    Object.keys(paymentMethodsByCurrency).forEach((currencyKey) => {
      if (currencyKey === preferredCurrency) {
        return;
      }
      appendMethods(paymentMethodsByCurrency[currencyKey]);
    });

    return methods;
  }

  function setPaymentAlert(message, type, options = {}) {
    if (!paymentModalAlert) {
      return;
    }
    if (!message) {
      paymentModalAlert.className = 'd-none alert mb-3';
      paymentModalAlert.textContent = '';
      paymentModalAlert.innerHTML = '';
      return;
    }
    const contextualExtraMessage = String((options && options.extraMessage) || '').trim();
    if (contextualExtraMessage !== '') {
      paymentModalAlert.innerHTML = `<div>${escapePaymentHtml(message)}</div><div class="small mt-2 fw-semibold" style="color:#22c55e;">${escapePaymentHtml(contextualExtraMessage)}</div>`;
    } else {
      paymentModalAlert.textContent = message;
    }
    paymentModalAlert.className = `alert mb-3 alert-${type || 'info'}`;
    scrollPaymentModalToTop();
  }

  function clearPaymentSupportUi() {
    clearPaymentStatusPolling();
    if (paymentModalReasons) {
      paymentModalReasons.className = 'd-none payment-reasons-card mb-3';
      paymentModalReasons.innerHTML = '';
      paymentModalReasons.removeAttribute('data-payment-difference-variant');
    }
    if (paymentModalActions) {
      paymentModalActions.className = 'd-none payment-support-actions mb-4';
      paymentModalActions.innerHTML = '';
      paymentModalActions.removeAttribute('data-payment-difference-variant');
    }
    if (paymentStatusModalReasons) {
      paymentStatusModalReasons.className = 'd-none payment-reasons-card mb-3 text-start';
      paymentStatusModalReasons.innerHTML = '';
      paymentStatusModalReasons.removeAttribute('data-payment-difference-variant');
    }
    if (paymentStatusModalActions) {
      paymentStatusModalActions.className = 'd-none payment-support-actions mb-4';
      paymentStatusModalActions.innerHTML = '';
      paymentStatusModalActions.removeAttribute('data-payment-difference-variant');
    }
    setPaymentStatusAcceptHidden(false);
    if (paymentSubmitButton) {
      paymentSubmitButton.textContent = defaultPaymentSubmitButtonLabel;
    }
  }

  if (paymentStatusModalAccept) {
    paymentStatusModalAccept.addEventListener('click', function() {
      clearPaymentStatusPolling();
      setOverlayVisible(paymentStatusModal, false);
      scrollPaymentSubmitIntoView();
    });
  }

  function buildPaymentSupportWhatsappUrl(orderId, reference, totalText) {
    if (!paymentSupportWhatsappBase) {
      return '';
    }

    const productName = paymentSummaryProduct ? paymentSummaryProduct.textContent : '';
    const userIdentifier = paymentSummaryUser ? paymentSummaryUser.textContent : '';
    const message = [
      'Hola, necesito apoyo para revisar manualmente un pago.',
      `Pedido: #${orderId || '-'}`,
      `Juego: ${currentGameName || '-'}`,
      `Producto: ${productName || '-'}`,
      `ID Jugador: ${userIdentifier || '-'}`,
      `Referencia: ${reference || '-'}`,
      `Monto: ${totalText || '-'}`,
      'Adjunto o enviaré captura del comprobante para revisión manual.'
    ].join('\n');
    return `${paymentSupportWhatsappBase}?text=${encodeURIComponent(message)}`;
  }

  function extractPaymentReasons(data) {
    const reasons = Array.isArray(data && data.reasons)
      ? data.reasons.map((reason) => String(reason || '').trim()).filter(Boolean)
      : [];
    const providerMessage = String((data && data.provider_message) || '').trim();

    if (providerMessage !== '' && !reasons.includes(providerMessage)) {
      reasons.unshift(providerMessage);
    }

    return reasons;
  }

  function normalizeProviderReasonsForDisplay(providerFlow, reasons) {
    const flow = String(providerFlow || '').toLowerCase();
    const list = Array.isArray(reasons) ? reasons.slice() : [];

    if (flow !== 'tracking') {
      return list;
    }

    const filtered = list.filter((reason) => !/json|timed out|timeout|0 bytes|respuesta vac[ií]a|incompleta|empty body|empty reply/i.test(String(reason || '')));
    if (filtered.length) {
      return filtered;
    }

    return ['La confirmación automática del proveedor quedó pendiente y será resuelta por webhook o por sincronización posterior.'];
  }

  function extractProviderCodes(data) {
    const raw = String((data && data.provider_code) || '').trim();
    if (raw === '') {
      return [];
    }

    return raw.split(/\r?\n+/).map((code) => String(code || '').trim()).filter(Boolean);
  }

  async function copyTextToClipboard(value) {
    const text = String(value || '');
    if (text.trim() === '') {
      return false;
    }

    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(text);
      return true;
    }

    const tempInput = document.createElement('textarea');
    tempInput.value = text;
    tempInput.setAttribute('readonly', 'readonly');
    tempInput.style.position = 'fixed';
    tempInput.style.opacity = '0';
    document.body.appendChild(tempInput);
    tempInput.focus();
    tempInput.select();

    let copied = false;
    try {
      copied = document.execCommand('copy');
    } finally {
      tempInput.remove();
    }

    return copied;
  }

  function renderDeliveredCodesCard(container, codes) {
    if (!container || !Array.isArray(codes) || !codes.length) {
      return;
    }

    const copyLabel = codes.length > 1 ? 'Copiar codigos' : 'Copiar codigo';
    container.className = `payment-reasons-card mb-3${container.id === 'payment-status-modal-reasons' ? ' text-start' : ''}`;
    container.innerHTML = `
      <div class="payment-reasons-title">${escapePaymentHtml(codes.length > 1 ? 'Codigos entregados' : 'Codigo entregado')}</div>
      <div class="payment-reasons-summary">Guarda esta informacion exactamente como aparece.</div>
      <ul>${codes.map((code) => `<li>${escapePaymentHtml(code)}</li>`).join('')}</ul>
      <button type="button" class="btn btn-info fw-bold w-100 mt-2 payment-copy-code-btn">${escapePaymentHtml(copyLabel)}</button>
    `;

    const copyButton = container.querySelector('.payment-copy-code-btn');
    if (copyButton) {
      copyButton.addEventListener('click', async () => {
        try {
          const copied = await copyTextToClipboard(codes.join('\n'));
          showToast(copied ? 'Codigo copiado.' : 'No se pudo copiar el codigo.', copied ? 'success' : 'error');
        } catch (error) {
          showToast('No se pudo copiar el codigo.', 'error');
        }
      });
    }
  }

  function renderAccountSaleDeliveryCard(container, payload) {
    if (!container || !payload) {
      return false;
    }

    const accountText = String(payload.accountText || '').trim();
    const gallery = Array.isArray(payload.gallery) ? payload.gallery : [];
    if (accountText === '' && gallery.length === 0) {
      return false;
    }

    container.className = `payment-reasons-card mb-3${container.id === 'payment-status-modal-reasons' ? ' text-start' : ''}`;
    container.innerHTML = `
      <div class="payment-reasons-title">Cuenta entregada</div>
      <div class="payment-reasons-summary">Guarda esta información. La cuenta ya quedó disponible para ti.</div>
      <div class="account-sale-delivery-card">
        ${accountText !== '' ? `<div class="account-sale-delivery-copy">${escapePaymentHtml(accountText)}</div>` : ''}
        ${accountText !== '' ? '<button type="button" class="btn btn-info fw-bold account-sale-copy-btn">Copiar datos de la cuenta</button>' : ''}
        ${gallery.length ? `<div class="account-sale-delivery-gallery">${gallery.map((item) => `
          <div class="account-sale-delivery-gallery-item">
            <img src="${escapePaymentHtml(item.imageUrl)}" alt="Vista de la cuenta">
            ${String(item.description || '').trim() !== '' ? `<span>${escapePaymentHtml(item.description)}</span>` : ''}
          </div>
        `).join('')}</div>` : ''}
      </div>
    `;

    const copyButton = container.querySelector('.account-sale-copy-btn');
    if (copyButton && accountText !== '') {
      copyButton.addEventListener('click', async () => {
        try {
          const copied = await copyTextToClipboard(accountText);
          showToast(copied ? 'Datos de la cuenta copiados.' : 'No se pudieron copiar los datos de la cuenta.', copied ? 'success' : 'error');
        } catch (error) {
          showToast('No se pudieron copiar los datos de la cuenta.', 'error');
        }
      });
    }

    return true;
  }

  function renderDeliveredCodes(data) {
    clearPaymentSupportUi();
    const accountSalePayload = getAccountSalePayload(data);
    if (accountSalePayload && renderAccountSaleDeliveryCard(paymentModalReasons, accountSalePayload)) {
      renderAccountSaleDeliveryCard(paymentStatusModalReasons, accountSalePayload);
      scrollPaymentModalToTop();
      return true;
    }

    const codes = extractProviderCodes(data);
    if (!codes.length) {
      return false;
    }

    renderDeliveredCodesCard(paymentModalReasons, codes);
    renderDeliveredCodesCard(paymentStatusModalReasons, codes);
    scrollPaymentModalToTop();
    return true;
  }

  function renderSupportCard(container, title, summary, steps, reasons, options = {}) {
    if (!container) {
      return;
    }

    const variant = options && (options.variant === 'underpaid' || options.variant === 'overpaid')
      ? options.variant
      : '';
    const reasonCaption = String((options && options.reasonCaption) || 'Detalle detectado por el sistema:').trim();
    const safeSummary = String(summary || '').trim();
    const safeSteps = Array.isArray(steps) ? steps.filter((step) => String(step || '').trim() !== '') : [];
    const safeReasons = Array.isArray(reasons) ? reasons.filter((reason) => String(reason || '').trim() !== '') : [];

    container.className = `payment-reasons-card mb-3${container.id === 'payment-status-modal-reasons' ? ' text-start' : ''}`;
    if (variant !== '') {
      container.setAttribute('data-payment-difference-variant', variant);
    } else {
      container.removeAttribute('data-payment-difference-variant');
    }
    container.innerHTML = `
      <div class="payment-reasons-title">${escapePaymentHtml(title)}</div>
      ${safeSummary !== '' ? `<div class="payment-reasons-summary">${escapePaymentHtml(safeSummary)}</div>` : ''}
      ${safeSteps.length ? `<ol class="payment-reasons-steps">${safeSteps.map((step) => `<li>${escapePaymentHtml(step)}</li>`).join('')}</ol>` : ''}
      ${safeReasons.length ? `
        <div class="payment-reasons-caption">${escapePaymentHtml(reasonCaption)}</div>
        <ul>${safeReasons.map((reason) => `<li>${escapePaymentHtml(reason)}</li>`).join('')}</ul>
      ` : ''}
    `;
  }

  function successfulProviderPendingPresentation(providerFlow, data = null) {
    const normalizedFlow = String(providerFlow || '').toLowerCase();
    const keepDetailedPassPresentation = buildBloodStrikeEliteDiscordSuccessNote(data) !== '';

    if (!keepDetailedPassPresentation) {
      return {
        title: 'Pago exitoso',
        summary: 'La recarga ya fue enviada al proveedor y está terminando su confirmación automática final.',
        message: 'Pago exitoso. Tu recarga fue procesada automáticamente y ya quedó enviada al proveedor.',
        steps: [
          'No necesitas volver a pagar ni repetir el proceso.',
          'Solo espera unos instantes mientras recibimos la confirmación final automática.'
        ],
        reasons: [],
        reasonCaption: '¿Qué significa este estado?',
        statusType: 'success'
      };
    }

    if (normalizedFlow === 'tracking') {
      return {
        title: 'Pago verificado, esperando confirmación',
        summary: 'Tu pago ya fue verificado y la orden fue enviada al proveedor. Ahora estamos esperando la confirmación automática final antes de marcar la recarga como completada.',
        message: 'Tu pago ya fue verificado y la orden fue enviada al proveedor. Estamos esperando la confirmación final automática antes de mostrarla como completada.',
        steps: [
          'La orden sigue activa en el sistema y continúa en validación automática.',
          'Puedes esperar unos instantes mientras continuamos consultando la confirmación final.',
          'Si la confirmación tarda más de lo habitual, podrás contactar al administrador con tu número de orden.'
        ],
        reasons: [
          'Tu pago ya fue verificado correctamente.',
          'La orden ya fue enviada al proveedor.',
          'La recarga sólo se marcará como completada cuando exista confirmación final del proveedor.'
        ],
        reasonCaption: '¿Qué significa este estado?',
        statusType: 'info'
      };
    }

    return {
      title: 'Pago verificado, esperando confirmación',
      summary: 'Tu pago ya fue verificado y la orden fue enviada al proveedor. Ahora estamos esperando la confirmación automática final antes de marcar la recarga como completada.',
      message: 'Tu pago ya fue verificado y la orden fue enviada al proveedor. Estamos esperando la confirmación final automática antes de mostrarla como completada.',
      steps: [
        'La orden ya fue enviada al proveedor y quedó registrada para seguimiento.',
        'Puedes esperar unos instantes mientras confirmamos el resultado final de forma automática.',
        'Si la confirmación tarda más de lo habitual, podrás contactar al administrador con tu número de orden.'
      ],
      reasons: [
        'Tu pago ya fue verificado correctamente.',
        'La orden ya fue enviada al proveedor.',
        'La recarga sólo se marcará como completada cuando exista confirmación final del proveedor.'
      ],
      reasonCaption: '¿Qué significa este estado?',
      statusType: 'info'
    };
  }

  function renderSupportActionLinks(reference, totalText) {
    const whatsappUrl = buildPaymentSupportWhatsappUrl(activePaymentOrder ? activePaymentOrder.orderId : '', reference, totalText);
    if (!whatsappUrl) {
      return;
    }

    const actionHtml = `<a href="${escapePaymentHtml(whatsappUrl)}" target="_blank" rel="noopener noreferrer" class="payment-support-link">Contactar al administrador por WhatsApp</a>`;
    if (paymentModalActions) {
      paymentModalActions.className = 'payment-support-actions mb-4';
      paymentModalActions.innerHTML = actionHtml;
    }
    if (paymentStatusModalActions) {
      paymentStatusModalActions.className = 'payment-support-actions mb-4';
      paymentStatusModalActions.innerHTML = actionHtml;
    }
  }

  function renderPaymentFailureDetails(data, reference, totalText) {
    clearPaymentSupportUi();
    const failureType = String((data && data.failure_type) || 'server_or_data_mismatch');
    const reasons = extractPaymentReasons(data);
    let title = 'Su Pago está en proceso, Espere 1 min y vuelva a intentar';
    let summary = '';
    let steps = [];
    let displayReasons = [];

    if (failureType === 'reference_mismatch') {
      title = 'La referencia no coincide';
      summary = 'La referencia ingresada no aparece igual en la respuesta del banco.';
      steps = [
        'Revisa que hayas escrito exactamente los dígitos solicitados de la referencia bancaria.',
        'Si la transferencia es reciente, espera 1 o 2 minutos y vuelve a intentar.',
        'Si el comprobante está correcto y el problema continúa, contacta al administrador por WhatsApp.'
      ];
    } else if (failureType === 'expired_reference') {
      title = 'La referencia ya caducó';
      summary = 'Los pagos reportados en la web solo son válidos el mismo día en que se realizan.';
      steps = [
        'La referencia que ingresaste pertenece a un pago de otro día y ya no puede reutilizarse en esta ventana.',
        'Comunícate con el administrador por WhatsApp y comparte tu comprobante para que revise el caso.',
        'Si necesitas completar una nueva compra, realiza un nuevo pago y registra una referencia del mismo día.'
      ];
    } else if (failureType === 'amount_mismatch') {
      title = 'El monto no coincide';
      summary = 'La referencia sí se encontró, pero el monto recibido por el banco no coincide con el total esperado del pedido.';
      steps = [
        'Verifica que el monto transferido corresponda al total del pedido.',
        'Si el banco aún no refleja el monto correcto, espera 1 o 2 minutos y vuelve a intentar.',
        'Si el cobro fue correcto y continúa el problema, contacta al administrador por WhatsApp con tu comprobante.'
      ];
    } else if (failureType === 'server_partial_response') {
      title = 'Su Pago está en proceso, Espere 1 min y vuelva a intentar';
      summary = '';
      steps = [];
    }

    if (failureType === 'server_or_data_mismatch' || failureType === 'server_partial_response') {
      displayReasons = [];
    } else {
      displayReasons = reasons;
    }

    renderSupportCard(paymentModalReasons, title, summary, steps, displayReasons);
    renderSupportCard(paymentStatusModalReasons, title, summary, steps, displayReasons);
    renderSupportActionLinks(reference, totalText);
    scrollPaymentModalToTop();
  }

  function renderProviderPaymentDetails(data, reference, totalText) {
    clearPaymentSupportUi();

    const providerFlow = String((data && data.provider_flow) || '').toLowerCase();
    let reasons = normalizeProviderReasonsForDisplay(providerFlow, extractPaymentReasons(data));
    let title = 'La recarga requiere revisión manual';
    let summary = 'El pago bancario fue verificado, pero el proveedor no confirmó una entrega automática.';
    let steps = [
      'Conserva el comprobante de pago y el número de referencia de esta orden.',
      'Nuestro equipo revisará el pedido; si deseas acelerar la revisión, contáctanos por WhatsApp con tu comprobante.'
    ];
    let reasonCaption = 'Detalle detectado por el sistema:';

    if (providerFlow === 'accepted') {
      const presentation = successfulProviderPendingPresentation(providerFlow, data);
      title = presentation.title;
      summary = presentation.summary;
      steps = presentation.steps;
      reasons = presentation.reasons;
      reasonCaption = presentation.reasonCaption;
    }

    if (providerFlow === 'tracking') {
      const presentation = successfulProviderPendingPresentation(providerFlow, data);
      title = presentation.title;
      summary = presentation.summary;
      steps = presentation.steps;
      reasons = presentation.reasons;
      reasonCaption = presentation.reasonCaption;
    }

    if (providerFlow === 'inventory_shortage') {
      title = 'No hay recargas suficientes en este momento';
      summary = 'Tu pago ya fue verificado, pero por los momentos no hay disponibilidad suficiente para completar la recarga automática.';
      steps = [
        'Tu pedido quedó en estado verificado y no necesitas volver a pagar.',
        'Nuestro equipo enviará la recarga en cuanto haya disponibilidad nuevamente.',
        'Si deseas acelerar la atención, contáctanos por WhatsApp y comparte tu comprobante.'
      ];
    }

    renderSupportCard(paymentModalReasons, title, summary, steps, reasons, { reasonCaption });
    renderSupportCard(paymentStatusModalReasons, title, summary, steps, reasons, { reasonCaption });
    renderSupportActionLinks(reference, totalText);

    scrollPaymentModalToTop();
  }

  function renderBinancePaymentDetails(data, reference, totalText) {
    clearPaymentSupportUi();

    const checkoutUrl = normalizeCoinpalCheckoutUrl((data && data.checkout_url) || '');
    const resolvedTotalText = String((data && data.binance_total_text) || totalText || '').trim();
    const reasons = filterBinanceReasons(data);
    const title = 'Completa el pago en Binance Pay';
    const summary = 'Abrimos un checkout externo de CoinPal para que completes el pago con Binance Pay mientras esta ventana sigue consultando la confirmación.';
    const steps = [
      'Abre la ventana de Binance Pay y completa el pago con tu cuenta o QR.',
      'Mantén esta ventana abierta: el sistema seguirá revisando la confirmación automáticamente.',
      'Si ya pagaste y el estado no cambia de inmediato, espera unos segundos mientras llega el webhook o la sincronización.'
    ];

    renderSupportCard(paymentModalReasons, title, summary, steps, reasons);
    renderSupportCard(paymentStatusModalReasons, title, summary, steps, reasons);

    const actions = [];
    if (checkoutUrl !== '') {
      actions.push({
        label: 'Abrir Binance Pay',
        className: 'btn-info',
        onClick: () => {
          reopenBinanceCheckout(checkoutUrl, reference, resolvedTotalText);
        },
      });
    }

    if (canSwitchFromBinanceToOtherPaymentMode()) {
      actions.push({
        label: 'Pagar con otro método',
        className: 'btn-outline-light',
        onClick: () => {
          switchFromBinanceToOtherPaymentMode();
        },
      });
    }

    actions.push({
      label: 'Cancelar operación',
      className: 'btn-danger',
      onClick: () => {
        openBinanceCancellationFlow();
      },
    });

    const whatsappUrl = buildPaymentSupportWhatsappUrl(activePaymentOrder ? activePaymentOrder.orderId : '', reference, resolvedTotalText);
    if (whatsappUrl) {
      actions.push({
        label: 'Contactar por WhatsApp',
        className: 'btn-outline-info',
        onClick: () => {
          window.open(whatsappUrl, '_blank', 'noopener');
        },
      });
    }

    renderPaymentActionButtons(actions, { hideDefaultStatusAccept: true });
    scrollPaymentModalToTop();
  }

  function renderPayPalPaymentDetails(data, reference, totalText) {
    clearPaymentSupportUi();

    const checkoutUrl = String((data && data.checkout_url) || '').trim();
    const resolvedTotalText = String(totalText || getConfirmedPaymentTotalText() || '').trim();
    const reasons = filterPayPalReasons(data);
    const title = 'Completa el pago en PayPal';
    const summary = 'Abrimos el checkout oficial de PayPal para que autorices el pago mientras esta ventana sigue consultando la confirmación.';
    const steps = [
      'Abre la ventana de PayPal y autoriza el pago con tu cuenta, saldo o tarjeta.',
      'Mantén esta ventana abierta: el sistema seguirá revisando la confirmación automáticamente.',
      'Si ya aprobaste el pago y el estado no cambia de inmediato, espera unos segundos mientras llega la sincronización o el webhook.'
    ];

    renderSupportCard(paymentModalReasons, title, summary, steps, reasons);
    renderSupportCard(paymentStatusModalReasons, title, summary, steps, reasons);

    const actions = [];
    if (checkoutUrl !== '') {
      actions.push({
        label: 'Abrir PayPal',
        className: 'btn-info',
        onClick: () => {
          reopenPayPalCheckout(checkoutUrl, reference, resolvedTotalText);
        },
      });
    }

    if (canSwitchFromBinanceToOtherPaymentMode()) {
      actions.push({
        label: 'Pagar con otro método',
        className: 'btn-outline-light',
        onClick: () => {
          switchFromBinanceToOtherPaymentMode();
        },
      });
    }

    actions.push({
      label: 'Cancelar operación',
      className: 'btn-danger',
      onClick: () => {
        openBinanceCancellationFlow();
      },
    });

    const whatsappUrl = buildPaymentSupportWhatsappUrl(activePaymentOrder ? activePaymentOrder.orderId : '', reference, resolvedTotalText);
    if (whatsappUrl) {
      actions.push({
        label: 'Contactar por WhatsApp',
        className: 'btn-outline-info',
        onClick: () => {
          window.open(whatsappUrl, '_blank', 'noopener');
        },
      });
    }

    renderPaymentActionButtons(actions, { hideDefaultStatusAccept: true });
    scrollPaymentModalToTop();
  }

  function renderPaymentServerFailure(errorMessage, reference, totalText) {
    renderPaymentFailureDetails({
      failure_type: 'server_or_data_mismatch',
      reasons: [errorMessage || 'No se recibió una respuesta válida del servidor bancario.']
    }, reference, totalText);
    scrollPaymentModalToTop();
  }

  function setCancelOrderButtonMode(mode) {
    if (!paymentCancelOrderButton) {
      return;
    }
    paymentCancelOrderButton.dataset.mode = mode;
    if (mode === 'close') {
      paymentCancelOrderButton.textContent = 'Cerrar ventana';
      paymentCancelOrderButton.classList.remove('btn-danger');
      paymentCancelOrderButton.classList.add('btn-outline-light');
      return;
    }
    paymentCancelOrderButton.textContent = 'Cancelar Orden';
    paymentCancelOrderButton.classList.remove('btn-outline-light');
    paymentCancelOrderButton.classList.add('btn-danger');
  }

  function setPaymentFormDisabled(disabled) {
    [paymentMethodSelect, paymentReferenceInput, paymentPhoneInput, paymentSubmitButton, ...getPaymentModeButtons()].forEach((field) => {
      if (field) {
        field.disabled = disabled;
      }
    });
  }

  function setPaymentMethodQrState(imageUrl = '', altText = 'QR del método de pago') {
    if (!paymentMethodQrWrap || !paymentMethodQrImage) {
      return;
    }

    const safeUrl = String(imageUrl || '').trim();
    if (safeUrl === '') {
      paymentMethodQrImage.removeAttribute('src');
      paymentMethodQrImage.alt = 'QR del método de pago';
      paymentMethodQrWrap.classList.add('d-none');
      return;
    }

    paymentMethodQrImage.src = safeUrl;
    paymentMethodQrImage.alt = String(altText || 'QR del método de pago');
    paymentMethodQrWrap.classList.remove('d-none');
  }

  function renderPaymentMethodDetails(method, options = {}) {
    const mode = options.mode === 'points'
      ? 'points'
      : (options.mode === 'binance' ? 'binance' : (options.mode === 'paypal' ? 'paypal' : 'money'));

    paymentMethodDetails.classList.remove('payment-method-details-rich');
    setPaymentMethodQrState('', 'QR del método de pago');

    if (mode === 'points') {
      const requiredPoints = Number(activePaymentOrder && activePaymentOrder.pointsRequired ? activePaymentOrder.pointsRequired : 0);
      const fallbackCopy = winPointsState.loggedIn
        ? `Saldo disponible: ${formatWinPointsAmount(winPointsState.balance || 0)}`
        : 'Inicia sesión para usar este método de canje.';
      paymentMethodTitle.textContent = `Canje con ${winPointsState.name || 'Win Points'}`;
      paymentMethodCurrency.textContent = requiredPoints > 0 ? `Canje requerido: ${formatWinPointsAmount(requiredPoints)}` : fallbackCopy;
      paymentMethodDetails.classList.add('payment-method-details-rich');
      paymentMethodDetails.innerHTML = `
        <div>
          <p>${escapePaymentHtml(String(activePaymentOrder && activePaymentOrder.pointsCopy ? activePaymentOrder.pointsCopy : fallbackCopy))}</p>
          <p class="mt-2 mb-0">${escapePaymentHtml(String(activePaymentOrder && activePaymentOrder.pointsMessage ? activePaymentOrder.pointsMessage : 'El canje se procesará directamente al confirmar si cumples con los requisitos.'))}</p>
        </div>`;
      paymentReferenceInput.placeholder = paymentReferencePlaceholder(null);
      paymentReferenceHelp.textContent = paymentReferenceHelpText(null);
      paymentReferenceInput.maxLength = 120;
      paymentReferenceInput.dataset.requiredDigits = '0';
      return;
    }

    if (mode === 'binance') {
      const pricing = resolvePaymentPricing('binance', null);
      const binanceMoney = resolveBinanceDisplayMoney(activePaymentOrder && activePaymentOrder.pack ? activePaymentOrder.pack : null, pricing.totalAmount);
      const totalLabel = String((binanceMoney && binanceMoney.text) || pricing.totalText || '').trim();
      paymentMethodTitle.textContent = String(binancePayButtonLabel || 'Binance Pay');
      paymentMethodCurrency.textContent = totalLabel !== '' ? `Total estimado en Binance Pay: ${totalLabel}` : 'Checkout externo seguro con CoinPal';
      paymentMethodDetails.classList.add('payment-method-details-rich');
      paymentMethodDetails.innerHTML = `
        <div>
          <p>Paga de forma segura desde CoinPal usando tu cuenta o QR de Binance Pay.</p>
          <ul>
            <li>La orden ya se abrirá con Binance Pay seleccionado desde el paso anterior.</li>
            <li>Al confirmar, abriremos el checkout externo y esta ventana seguirá monitoreando la confirmación.</li>
            <li>Si el checkout no se abre automáticamente, el sistema mostrará la opción para reintentarlo.</li>
          </ul>
        </div>`;
      paymentReferenceInput.placeholder = paymentReferencePlaceholder(null);
      paymentReferenceHelp.textContent = paymentReferenceHelpText(null);
      paymentReferenceInput.maxLength = 120;
      paymentReferenceInput.dataset.requiredDigits = '0';
      return;
    }

    if (mode === 'paypal') {
      const pricing = resolvePaymentPricing('paypal', null);
      paymentMethodTitle.textContent = String(paypalPayButtonLabel || 'PayPal');
      paymentMethodCurrency.textContent = pricing.totalText ? `Total estimado en PayPal: ${pricing.totalText}` : 'Checkout oficial seguro con PayPal';
      paymentMethodDetails.classList.add('payment-method-details-rich');
      paymentMethodDetails.innerHTML = `
        <div>
          <p>Te enviaremos al checkout oficial de PayPal para que autorices el pago con saldo, tarjeta o cuenta vinculada.</p>
          <ul>
            <li>La orden se abrirá en una ventana externa segura de PayPal.</li>
            <li>Al aprobar el pago, esta ventana seguirá sincronizando automáticamente el resultado.</li>
            <li>Si el checkout no se abre o lo cierras por error, el sistema mostrará la opción para reabrirlo.</li>
          </ul>
        </div>`;
      setPaymentMethodQrState(String(paypalPayQrImageUrl || '').trim(), 'QR o imagen de referencia para PayPal');
      paymentReferenceInput.placeholder = paymentReferencePlaceholder(null);
      paymentReferenceHelp.textContent = paymentReferenceHelpText(null);
      paymentReferenceInput.maxLength = 120;
      paymentReferenceInput.dataset.requiredDigits = '0';
      return;
    }

    if (!method) {
      paymentMethodTitle.textContent = 'Datos de pago';
      paymentMethodCurrency.textContent = '';
      paymentMethodDetails.innerHTML = 'No hay datos de pago disponibles.';
      paymentReferenceInput.placeholder = paymentReferencePlaceholder(null);
      paymentReferenceHelp.textContent = paymentReferenceHelpText(null);
      paymentReferenceInput.maxLength = 120;
      return;
    }

    const currencyLabel = `${method.moneda_nombre || ''}${method.moneda_clave ? ` (${method.moneda_clave})` : ''}`.trim();
    paymentMethodTitle.textContent = `Datos para ${method.nombre || 'el pago'}`;
    paymentMethodCurrency.textContent = currencyLabel;
    paymentMethodDetails.innerHTML = escapePaymentHtml(method.datos || '').replace(/\n/g, '<br>');
    setPaymentMethodQrState(resolvePublicImageUrl(method.qr_image_path || ''), `QR para ${method.nombre || 'el pago'}`);
    const digits = Number(method.referencia_digitos || 0);
    paymentReferenceInput.placeholder = paymentReferencePlaceholder(method);
    paymentReferenceHelp.textContent = paymentReferenceHelpText(method);
    paymentReferenceInput.maxLength = digits > 0 ? digits : 120;
    paymentReferenceInput.dataset.requiredDigits = String(digits > 0 ? digits : 0);
  }

  function renderPaymentMethodsByCurrency(currencyCode) {
    const methods = getPaymentMethodsForCurrency(currencyCode);
    if (!methods.length) {
      paymentMethodSelectWrap.classList.add('d-none');
      renderPaymentMethodDetails(null);
      return null;
    }

    const selectedMethod = resolveSelectedPaymentMethod(currencyCode, preferredCheckoutMethodId);

    paymentMethodSelect.innerHTML = methods.map((method) => `<option value="${method.id}">${escapePaymentHtml(method.nombre || 'Método')}</option>`).join('');
    paymentMethodSelect.value = selectedMethod ? String(selectedMethod.id) : String(methods[0].id);
    paymentMethodSelectWrap.classList.add('d-none');
    renderPaymentMethodDetails(selectedMethod || methods[0]);
    return selectedMethod || methods[0];
  }

  function resetCheckoutState() {
    orderForm.reset();
    orderForm.email.value = defaultOrderEmail || '';
    restoreStoredPurchaseDefaults(true);
    couponInput.value = '';
    couponInput.disabled = false;
    if (applyCouponButton) {
      applyCouponButton.disabled = false;
    }
    couponApplied = false;
    couponValue = '';
    clearAppliedCouponSummary();
    activePack = null;
    if (paymentWinPointsCard) {
      paymentWinPointsCard.classList.add('d-none');
    }
    if (paymentMethodCard) {
      paymentMethodCard.classList.remove('d-none');
    }
    if (paymentModeOptions) {
      paymentModeOptions.innerHTML = '';
    }
    resetPlayerVerificationState();
    packCards2.forEach((item) => item.classList.remove('neon-selected'));
    renderPlayerFields(null);
    updateResumenCompra(null);
    refreshPaymentDifferenceBanner(null);
    updateButtonState();
  }

  function closePaymentModal(resetState) {
    clearPaymentTimer();
    setOverlayVisible(paymentModal, false);
    setPaymentAlert('', 'info');
    if (resetState) {
      activePaymentOrder = null;
      paymentReferenceInput.value = '';
      paymentPhoneInput.value = defaultPaymentPhone || '';
      setPaymentMethodQrState('', 'QR del método de pago');
      clearPaymentSupportUi();
      setCancelOrderButtonMode('cancel');
      if (paymentWinPointsCard) {
        paymentWinPointsCard.classList.add('d-none');
      }
      if (paymentSubmitButton) {
        paymentSubmitButton.textContent = defaultPaymentSubmitButtonLabel;
      }
    }
  }

  async function expireActiveOrder() {
    if (!activePaymentOrder || activePaymentOrder.expiring) {
      return;
    }
    activePaymentOrder.expiring = true;
    clearPaymentTimer();
    setPaymentFormDisabled(true);
    setPaymentAlert('La orden expiró. Estamos cancelando el pedido y notificando por correo.', 'danger');
    try {
      const response = await fetch(buildAppUrl('/api/pedidos.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=expire_order&order_id=${encodeURIComponent(activePaymentOrder.orderId)}`
      });
      const data = await response.json();
      showToast((data && data.message) ? data.message : 'La orden expiró.', data && data.expired ? 'error' : 'info');
      setPaymentAlert((data && data.message) ? data.message : 'La orden expiró y fue cancelada automáticamente.', 'danger');
    } catch (error) {
      setPaymentAlert('La orden expiró. Si el estado no cambió todavía, vuelve a intentarlo.', 'danger');
    }
  }

  function updatePaymentTimer() {
    if (!activePaymentOrder) {
      paymentTimerValue.textContent = '30:00';
      return;
    }
    const remainingMs = activePaymentOrder.expiresAtMs - Date.now();
    if (remainingMs <= 0) {
      paymentTimerValue.textContent = '00:00';
      expireActiveOrder();
      return;
    }
    const totalSeconds = Math.floor(remainingMs / 1000);
    const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
    const seconds = String(totalSeconds % 60).padStart(2, '0');
    paymentTimerValue.textContent = `${minutes}:${seconds}`;
  }

  function openPaymentModal(orderId, expiresAt, remainingSeconds, pack, userId, totalText, orderEmail) {
    const preferredSelection = resolvePreferredCheckoutSelection(pack);
    if (!preferredSelection.mode) {
      showToast('Selecciona un metodo de pago antes de continuar.', 'error');
      return false;
    }
    const currentMethod = renderPaymentMethodsByCurrency(pack.moneda || '');
    const canUsePoints = canRedeemPackWithPoints(pack);
    const canUseBinance = canUseBinanceCheckout(pack);
    const canUsePayPal = canUsePayPalCheckout(pack);
    if (!currentMethod && !canUsePoints && !canUseBinance && !canUsePayPal) {
      showToast('No hay métodos de pago activos disponibles.', 'error');
      return false;
    }

    const safeRemainingSeconds = Number.isFinite(Number(remainingSeconds)) ? Math.max(0, Number(remainingSeconds)) : 1800;

    activePaymentOrder = {
      orderId,
      pack,
      userId,
      baseAmount: Number(selectedTotalValue || 0),
      expiresAtMs: Date.now() + (safeRemainingSeconds * 1000),
      expiresAt,
      currency: pack.moneda || '',
      email: orderEmail || '',
      canUseMoney: Boolean(currentMethod),
      canUseBinance,
      canUsePayPal,
      canUsePoints,
      paymentMode: preferredSelection.mode === 'points'
        ? 'points'
        : (preferredSelection.mode === 'binance'
          ? 'binance'
          : (preferredSelection.mode === 'paypal'
            ? 'paypal'
            : (currentMethod ? 'money' : (canUsePayPal ? 'paypal' : (canUsePoints ? 'points' : 'binance'))))),
      selectedMethodId: currentMethod ? String(currentMethod.id) : '',
      preferredMode: preferredSelection.mode || (currentMethod ? 'money' : (canUsePayPal ? 'paypal' : (canUsePoints ? 'points' : 'binance'))),
      pointsRequired: Number(pack.redeemRequiredPoints || 0),
      confirmedTotalText: String(totalText || '-').trim() || '-',
      expiring: false,
    };

    renderPaymentSummary(pack, userId, totalText);
    paymentReferenceInput.value = '';
    paymentPhoneInput.value = defaultPaymentPhone || '';
    setPaymentFormDisabled(false);
    setPaymentAlert('', 'info');
    clearPaymentSupportUi();
    if (paymentSubmitButton) {
      paymentSubmitButton.textContent = defaultPaymentSubmitButtonLabel;
    }
    renderWinPointsPaymentState(pack, currentMethod);
    setCancelOrderButtonMode('cancel');
    setOverlayVisible(paymentModal, true);
    scrollPaymentModalToTop();
    clearPaymentTimer();
    updatePaymentTimer();
    paymentTimerInterval = setInterval(updatePaymentTimer, 1000);
    return true;
  }

  function updatePackPrices() {
    packCards.forEach(card => {
      const base = parseFloat(card.getAttribute('data-base'));
      const precio = normalizeCurrencyAmount(base * monedaActualTasa, monedaActualMostrarDecimales);
      card.querySelector('.precio-label').textContent = formatCurrencyAmount(precio, monedaActualMostrarDecimales);
      card.querySelector('.moneda-label').textContent = monedaActualClave;
      card.setAttribute('data-price-value', String(precio));
      card.setAttribute('data-show-decimals', monedaActualMostrarDecimales ? '1' : '0');
      card.setAttribute('data-moneda', monedaActualClave);
    });
  }
  updatePackPrices();

  function updateButtonState() {
    // Solo controlar el estado del botón, no mostrar mensajes de error aquí
    const requiredFields = Array.from(orderForm.querySelectorAll("[required]"));
    let requiredFilled = true;
    requiredFields.forEach(field => {
      if (field.value.trim() === "" || !isCheckoutFieldValid(field)) {
        requiredFilled = false;
      }
    });
    if (!activePack) {
      selectedPack.style.color = "#f87171";
      selectedPack.textContent = "Debes seleccionar un paquete.";
    } else {
      selectedPack.style.color = "";
      selectedPack.textContent = activePack.name;
    }
    const paymentSelection = activePack ? resolvePreferredCheckoutSelection(activePack) : null;
    const hasPaymentSelection = Boolean(paymentSelection && paymentSelection.mode);
    const needsPlayerVerification = requiresVerifiedPlayerForCheckout();
    const paymentDifferenceBlocked = activePack ? getPaymentDifferenceBreakdown(activePack, selectedTotalValue).blocksSelection : false;
    const blockedByGameEntryWindow = !gameEntryWindowAccepted;
    buyButton.disabled = !activePack || !requiredFilled || !hasPaymentSelection || needsPlayerVerification || paymentDifferenceBlocked || blockedByGameEntryWindow;
    if (paymentDifferenceBlocked) {
      buyButton.textContent = paymentDifferenceBlockedBuyButtonLabel;
    } else if (activePack && !hasPaymentSelection) {
      buyButton.textContent = 'Selecciona un metodo de pago';
    } else if (blockedByGameEntryWindow) {
      buyButton.textContent = defaultBuyButtonLabel;
    } else {
      buyButton.textContent = needsPlayerVerification
        ? verifyUserBuyButtonLabel
        : (publicCheckoutSummaryTotalText !== '' ? `${defaultBuyButtonLabel} - ${publicCheckoutSummaryTotalText}` : defaultBuyButtonLabel);
    }
    syncPlayerVerificationUi();
  }
  function updateResumenCompra(pack) {
    const quantity = syncOrderQuantityInput();
    if (pack) {
      pack.purchaseQuantity = quantity;
      selectedPack.textContent = pack.name;
      selectedTotalValue = getPackTotalPrice(pack, quantity);
      updateSelectedPriceDisplay(pack);
      if (selectedWinPointsTotal) {
        const requiredPoints = getPackRequiredPoints(pack, quantity);
        const hasWinPointsRedemption = Boolean(pack.redeemActive) && requiredPoints > 0;
        const showWinPointsDetail = hasWinPointsRedemption && !shouldDisplayPackTotalInPoints(pack);
        selectedWinPointsTotal.textContent = showWinPointsDetail
          ? `Canje: ${formatWinPointsAmount(requiredPoints)}`
          : '';
        selectedWinPointsTotal.classList.toggle('d-none', !showWinPointsDetail);
      }
      renderPublicPaymentMethodCatalog(pack);
      renderPublicOrderSummary(pack);
    } else {
      selectedTotalValue = 0;
      selectedPack.textContent = 'Debes seleccionar un paquete.';
      syncOrderQuantityInput(1);
      updateSelectedPriceDisplay(null);
      if (selectedWinPointsTotal) {
        selectedWinPointsTotal.textContent = '';
        selectedWinPointsTotal.classList.add('d-none');
      }
      renderPublicPaymentMethodCatalog(null);
      renderPublicOrderSummary(null);
    }
  }

  function findPackCardById(packageId) {
    return packCards2.find((card) => String(card.dataset.packageId || '') === String(packageId || '')) || null;
  }

  function activatePackCard(card, options = {}) {
    if (!card) {
      return;
    }

    packCards2.forEach((item) => {
      item.classList.remove('neon-selected');
      item.setAttribute('aria-pressed', 'false');
    });
    card.classList.add('neon-selected');
    card.setAttribute('aria-pressed', 'true');
    activePack = buildPackStateFromCard(card);
    updateResumenCompra(activePack);
    renderPlayerFields(activePack);
    handlePlayerVerificationFieldChange();
    updateButtonState();
    if (options.scroll !== false) {
      scrollToPackageSelectionDetails();
    }
  }

  function focusAccountSaleEmailStep() {
    closeAccountGalleryModal();
    scrollToOrderForm();
    if (orderEmailInput) {
      if (!orderEmailInput.value.trim() && defaultOrderEmail) {
        orderEmailInput.value = defaultOrderEmail;
      }
      orderEmailInput.focus();
    }
  }

  function triggerAccountSaleBuyFlow(triggerButton = buyButton) {
    if (!activePack || !isAccountSalePack(activePack)) {
      return;
    }

    const loggedEmail = String(defaultOrderEmail || '').trim();
    if (!winPointsState.loggedIn || loggedEmail === '') {
      focusAccountSaleEmailStep();
      return;
    }

    if (orderEmailInput) {
      orderEmailInput.value = loggedEmail;
    }
    closeAccountGalleryModal();
    submitOrderCreationRequest({
      triggerButton,
      forceEmail: loggedEmail,
      forceUserId: '',
      forcePlayerFields: {}
    });
  }

  function renderAccountGalleryPreview(pack, activeIndex = 0) {
    if (!accountGalleryModal || !pack) {
      return;
    }

    const gallery = Array.isArray(pack.accountGallery) ? pack.accountGallery : [];
    const safeIndex = gallery.length ? Math.max(0, Math.min(activeIndex, gallery.length - 1)) : 0;
    const activeItem = gallery[safeIndex] || null;
    activeAccountGalleryPreview = { pack, index: safeIndex };

    if (accountGalleryModalTitle) {
      accountGalleryModalTitle.textContent = pack.name || 'Cuenta disponible';
    }
    if (accountGalleryModalPrice) {
      accountGalleryModalPrice.textContent = formatPaymentDifferenceMoney(pack.moneda || monedaActualClave, getPackTotalPrice(pack, Number(pack.purchaseQuantity || getOrderQuantity())), pack.showDecimals);
    }
    if (accountGalleryModalCaption) {
      accountGalleryModalCaption.textContent = activeItem && activeItem.description ? activeItem.description : '';
    }
    if (accountGalleryModalImage && accountGalleryModalPlaceholder) {
      if (activeItem && activeItem.imageUrl) {
        accountGalleryModalImage.src = activeItem.imageUrl;
        accountGalleryModalImage.classList.remove('d-none');
        accountGalleryModalPlaceholder.classList.add('d-none');
      } else {
        accountGalleryModalImage.src = '';
        accountGalleryModalImage.classList.add('d-none');
        accountGalleryModalPlaceholder.classList.remove('d-none');
      }
    }
    if (accountGalleryModalThumbs) {
      accountGalleryModalThumbs.innerHTML = gallery.map((item, index) => `
        <button type="button" class="account-gallery-thumb${index === safeIndex ? ' is-active' : ''}" data-account-thumb-index="${index}" aria-label="Vista previa ${index + 1}">
          <img src="${escapePaymentHtml(item.imageUrl)}" alt="Vista previa ${index + 1}">
        </button>
      `).join('');
      accountGalleryModalThumbs.querySelectorAll('[data-account-thumb-index]').forEach((button) => {
        button.addEventListener('click', () => {
          renderAccountGalleryPreview(pack, Number(button.getAttribute('data-account-thumb-index') || '0'));
        });
      });
    }
  }

  function openAccountGalleryModal(pack) {
    if (!accountGalleryModal || !pack || !isAccountSalePack(pack)) {
      return;
    }

    renderAccountGalleryPreview(pack, 0);
    setOverlayVisible(accountGalleryModal, true);
  }

  function closeAccountGalleryModal() {
    if (!accountGalleryModal) {
      return;
    }

    setOverlayVisible(accountGalleryModal, false);
  }

  packCards2.forEach((card) => {
    card.addEventListener("click", () => {
      activatePackCard(card);
    });
    card.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        activatePackCard(card);
      }
    });
  });
  packAccountPreviewButtons.forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const card = button.closest('.pack-card');
      if (!card) {
        return;
      }
      activatePackCard(card, { scroll: false });
      openAccountGalleryModal(activePack);
    });
  });
  if (packCards2.length) {
    const requestedPackCard = findPackCardById(<?= $requestedPackageId ?>);
    if (requestedPackCard) {
      activatePackCard(requestedPackCard, { scroll: false });
      requestedPackCard.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    }
  }
  syncOrderQuantityInput(1);
  renderPlayerFields(null);
  setAccountSaleNote(null);
  if (!activePack) {
    updateResumenCompra(null);
  }
  updateButtonState();
  if (verifyPlayerButton) {
    verifyPlayerButton.addEventListener('click', verifyCurrentPlayer);
  }
  if (accountGalleryModalClose) {
    accountGalleryModalClose.addEventListener('click', closeAccountGalleryModal);
  }
  if (accountGalleryModalBuy) {
    accountGalleryModalBuy.addEventListener('click', () => {
      triggerAccountSaleBuyFlow(accountGalleryModalBuy);
    });
  }
              function normalizeCouponCode(value) {
                return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
              }

              function resetCouponState(clearInput = false) {
                couponApplied = false;
                couponValue = '';
                clearAppliedCouponSummary();
                couponInput.disabled = false;
                if (clearInput && couponInput) {
                  couponInput.value = '';
                }
                if (applyCouponButton) {
                  applyCouponButton.disabled = false;
                }
                renderPublicOrderSummary(activePack);
                updateButtonState();
              }

              if (orderQuantityInput) {
                const triggerQuantityInputUpdate = function(nextQuantity) {
                  orderQuantityInput.value = String(normalizeOrderQuantity(nextQuantity));
                  orderQuantityInput.dispatchEvent(new Event('input', { bubbles: true }));
                };

                if (orderQuantityDecreaseButton) {
                  orderQuantityDecreaseButton.addEventListener('click', function() {
                    if (orderQuantityDecreaseButton.disabled) {
                      return;
                    }
                    triggerQuantityInputUpdate(Math.max(1, getOrderQuantity() - 1));
                    orderQuantityInput.focus();
                  });
                }

                if (orderQuantityIncreaseButton) {
                  orderQuantityIncreaseButton.addEventListener('click', function() {
                    if (orderQuantityIncreaseButton.disabled) {
                      return;
                    }
                    triggerQuantityInputUpdate(getOrderQuantity() + 1);
                    orderQuantityInput.focus();
                  });
                }

                orderQuantityInput.addEventListener('input', function() {
                  const quantity = syncOrderQuantityInput(orderQuantityInput.value);
                  if (couponInput.value.trim() !== '' || couponApplied) {
                    resetCouponState(true);
                  }
                  if (activePack) {
                    activePack.purchaseQuantity = quantity;
                    updateResumenCompra(activePack);
                  } else {
                    updateResumenCompra(null);
                  }
                  updateButtonState();
                });

                orderQuantityInput.addEventListener('blur', function() {
                  syncOrderQuantityInput(orderQuantityInput.value);
                });
              }

              if (paymentMethodSelect) {
                paymentMethodSelect.addEventListener('change', function() {
                  const methods = getPaymentMethodsForCurrency(activePaymentOrder ? activePaymentOrder.currency : (activePack ? activePack.moneda : ''));
                  const selectedMethod = methods.find((method) => String(method.id) === String(paymentMethodSelect.value)) || methods[0] || null;
                  if (activePaymentOrder) {
                    activePaymentOrder.selectedMethodId = selectedMethod ? String(selectedMethod.id) : '';
                  }
                  storePreferredCheckoutPayment('money', selectedMethod ? String(selectedMethod.id) : '');
                  if (activePaymentOrder && paymentWinPointsCard && !paymentWinPointsCard.classList.contains('d-none')) {
                    setActivePaymentMode('money', activePaymentOrder.selectedMethodId);
                    return;
                  }
                  renderPaymentMethodDetails(selectedMethod);
                  updatePaymentPricingUi(selectedMethod);
                  renderPublicPaymentMethodCatalog(activePack);
                });
              }

              if (paymentMethodCatalogGrid) {
                paymentMethodCatalogGrid.addEventListener('click', function(event) {
                  const button = event.target.closest('.payment-method-public-button');
                  if (!button || button.disabled) {
                    return;
                  }

                  const mode = button.dataset.paymentOption === 'points'
                    ? 'points'
                    : (button.dataset.paymentOption === 'binance'
                      ? 'binance'
                      : (button.dataset.paymentOption === 'paypal' ? 'paypal' : 'money'));
                  const methodId = button.dataset.methodId || '';
                  const previousCurrencyCode = String(monedaActualClave || '').trim().toUpperCase();
                  storePreferredCheckoutPayment(mode, methodId);
                  const switchedCurrency = syncVisibleCurrencyWithPreferredPayment(activePack, { resetCoupon: true });
                  const nextCurrencyCode = String(monedaActualClave || '').trim().toUpperCase();
                  if (!switchedCurrency) {
                    updateResumenCompra(activePack);
                  } else if (previousCurrencyCode !== nextCurrencyCode && nextCurrencyCode !== '') {
                    scrollToPackPricingSection();
                  }

                  if (activePaymentOrder) {
                    setActivePaymentMode(mode, methodId, { expandSelected: shouldExpandSinglePaymentOption() });
                  }
                });
              }

              if (paymentReferenceInput) {
                paymentReferenceInput.addEventListener('input', function() {
                  const digitsOnly = paymentReferenceInput.value.replace(/\D+/g, '');
                  const requiredDigits = Number(paymentReferenceInput.dataset.requiredDigits || '0');
                  paymentReferenceInput.value = requiredDigits > 0 ? digitsOnly.slice(0, requiredDigits) : digitsOnly.slice(0, 120);
                });
              }

              if (paymentCancelOrderButton) {
                paymentCancelOrderButton.addEventListener('click', function() {
                  const mode = paymentCancelOrderButton.dataset.mode || 'cancel';
                  if (mode === 'close') {
                    closePaymentModal(true);
                    resetCheckoutState();
                    return;
                  }
                  if (!activePaymentOrder) {
                    return;
                  }
                  setOverlayVisible(paymentCancelConfirmModal, false);
                  closePaymentModal(true);
                  resetCheckoutState();
                });
              }

              if (paymentCancelDismissButton) {
                paymentCancelDismissButton.addEventListener('click', function() {
                  setOverlayVisible(paymentCancelConfirmModal, false);
                });
              }

              if (paymentCancelConfirmButton) {
                paymentCancelConfirmButton.addEventListener('click', function() {
                  if (!activePaymentOrder) {
                    setOverlayVisible(paymentCancelConfirmModal, false);
                    return;
                  }
                  paymentCancelConfirmButton.disabled = true;
                  fetch(buildAppUrl('/api/pedidos.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cancel_order&order_id=${encodeURIComponent(activePaymentOrder.orderId)}`
                  })
                  .then(async (response) => {
                    const data = await parseApiJsonResponse(response, 'No se pudo cancelar la orden en este momento.');
                    if (!response.ok || !data.ok) {
                      throw new Error((data && data.message) ? data.message : 'No se pudo cancelar la orden.');
                    }
                    setOverlayVisible(paymentCancelConfirmModal, false);
                    showToast(data.message || 'Orden cancelada.', 'error');
                    closePaymentModal(true);
                    resetCheckoutState();
                  })
                  .catch((error) => {
                    setOverlayVisible(paymentCancelConfirmModal, false);
                    setPaymentAlert(normalizeApiRequestErrorMessage(error, 'No se pudo cancelar la orden en este momento.'), 'danger');
                  })
                  .finally(() => {
                    paymentCancelConfirmButton.disabled = false;
                  });
                });
              }

              if (paymentSubmitButton) {
                paymentSubmitButton.addEventListener('click', function() {
                  if (!activePaymentOrder) {
                    showToast('No hay una orden pendiente para confirmar.', 'error');
                    return;
                  }

                  const paymentMode = activePaymentOrder.paymentMode === 'points'
                    ? 'points'
                    : (activePaymentOrder.paymentMode === 'binance' ? 'binance' : (activePaymentOrder.paymentMode === 'paypal' ? 'paypal' : 'money'));
                  const methods = getPaymentMethodsForCurrency(activePaymentOrder.currency);
                  const selectedMethod = methods.find((method) => String(method.id) === String(activePaymentOrder.selectedMethodId || paymentMethodSelect.value)) || methods[0] || null;
                  if (paymentMode === 'money' && !selectedMethod) {
                    setPaymentAlert('No hay un método de pago disponible para esta orden.', 'danger');
                    return;
                  }

                  const reference = paymentMode === 'money' ? paymentReferenceInput.value.trim() : '';
                  const phone = paymentMode === 'money' ? paymentPhoneInput.value.trim() : '';
                  const requiredDigits = Number(selectedMethod ? (selectedMethod.referencia_digitos || 0) : 0);

                  if (paymentMode === 'points' && !activePaymentOrder.canUsePoints) {
                    setPaymentAlert('Este paquete no tiene un canje disponible con tus premios en este momento.', 'danger');
                    return;
                  }
                  if (paymentMode === 'binance' && !activePaymentOrder.canUseBinance) {
                    setPaymentAlert('Binance Pay no está disponible para esta orden.', 'danger');
                    return;
                  }
                  if (paymentMode === 'paypal' && !activePaymentOrder.canUsePayPal) {
                    setPaymentAlert('PayPal no está disponible para esta orden.', 'danger');
                    return;
                  }
                  if (paymentMode === 'money' && !reference) {
                    setPaymentAlert('Debes ingresar el número de referencia.', 'danger');
                    return;
                  }
                  if (paymentMode === 'money' && requiredDigits > 0 && reference.length !== requiredDigits) {
                    setPaymentAlert(`La referencia debe contener exactamente ${requiredDigits} dígitos.`, 'danger');
                    return;
                  }
                  if (paymentMode === 'money' && !phone) {
                    setPaymentAlert('Debes ingresar un número de teléfono para contactarte.', 'danger');
                    return;
                  }

                  setPaymentFormDisabled(true);
                  setPaymentAlert('', 'info');
                  let checkoutWindow = null;
                  if (paymentMode === 'binance') {
                    checkoutWindow = openBinanceCheckoutPopup();
                  } else if (paymentMode === 'paypal') {
                    checkoutWindow = openPayPalCheckoutPopup();
                  }
                  const loadingTitle = paymentMode === 'points'
                    ? 'Canjeando premios...'
                    : (paymentMode === 'binance'
                      ? 'Abriendo Binance Pay...'
                      : (paymentMode === 'paypal' ? 'Abriendo PayPal...' : (paymentSendingOrderContent.title || 'Enviando orden...')));
                  const loadingMessage = paymentMode === 'points'
                    ? 'Estamos validando tu saldo y procesando la recarga con tus premios. No cierres esta ventana.'
                    : (paymentMode === 'binance'
                      ? 'Estamos creando el checkout externo de Binance Pay. No cierres esta ventana.'
                      : (paymentMode === 'paypal'
                        ? 'Estamos creando el checkout oficial de PayPal. No cierres esta ventana.'
                        : (paymentSendingOrderContent.message || 'Estamos registrando tu comprobante y procesando la orden según la moneda del pedido. No cierres esta ventana.')));
                  const loadingState = paymentMode === 'points' ? 'processing' : ((paymentMode === 'binance' || paymentMode === 'paypal') ? 'processing' : 'sending');
                  setLoadingModalContent(loadingTitle, loadingMessage, loadingState);
                  setOverlayVisible(loadingModal, true);
                  fetch(buildAppUrl('/api/pedidos.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: [
                      'action=submit_payment',
                      `order_id=${encodeURIComponent(activePaymentOrder.orderId)}`,
                      `payment_mode=${encodeURIComponent(paymentMode)}`,
                      `payment_method_id=${encodeURIComponent(selectedMethod ? selectedMethod.id : '')}`,
                      `reference_number=${encodeURIComponent(reference)}`,
                      `phone=${encodeURIComponent(phone)}`
                    ].join('&')
                  })
                  .then(async (response) => {
                    const data = await parseApiJsonResponse(response, 'No pudimos validar tu pago en este momento. Espera 1 minuto y vuelve a intentarlo.');
                    if (!response.ok || !data.ok) {
                      throw new Error((data && data.message) ? data.message : 'No se pudieron guardar los datos del pago.');
                    }

                    if (data && data.win_points && Number.isFinite(Number(data.win_points.balance))) {
                      syncWinPointsSummaryFromResponse(data.win_points);
                      renderWinPointsPaymentState(activePaymentOrder.pack || activePack, selectedMethod);
                    }
                    if (paymentMode === 'money' && phone) {
                      defaultPaymentPhone = phone;
                    }

                    setOverlayVisible(loadingModal, false);

                    if ((paymentMode === 'binance' || paymentMode === 'paypal') && checkoutWindow && !checkoutWindow.closed) {
                      const checkoutUrl = paymentMode === 'binance'
                        ? normalizeCoinpalCheckoutUrl((data && data.checkout_url) || '')
                        : String((data && data.checkout_url) || '').trim();
                      if (checkoutUrl === '') {
                        checkoutWindow.close();
                      }
                    }

                    if (paymentMode === 'binance') {
                      const checkoutUrl = normalizeCoinpalCheckoutUrl((data && data.checkout_url) || '');
                      if (checkoutUrl !== '') {
                        const opened = navigateBinanceCheckoutPopup(checkoutWindow, checkoutUrl);
                        if (!opened) {
                          setPaymentAlert('No pudimos abrir automáticamente Binance Pay. Usa el botón "Abrir Binance Pay" para continuar.', 'warning');
                        }
                      }
                    }

                    if (paymentMode === 'paypal') {
                      const checkoutUrl = String((data && data.checkout_url) || '').trim();
                      if (checkoutUrl !== '') {
                        const opened = navigatePayPalCheckoutPopup(checkoutWindow, checkoutUrl);
                        if (!opened) {
                          setPaymentAlert('No pudimos abrir automáticamente PayPal. Usa el botón "Abrir PayPal" para continuar.', 'warning');
                        }
                      }
                    }

                    const nextState = String((data && data.estado) || '').toLowerCase();
                    const providerFlow = String((data && data.provider_flow) || '').toLowerCase();
                    if (nextState === 'enviado') {
                      const successMessage = data.message || (getAccountSalePayload(data)
                        ? (paymentMode === 'points' ? 'Canje realizado y cuenta entregada correctamente.' : 'La cuenta fue entregada correctamente.')
                        : (paymentMode === 'points'
                          ? 'Canje realizado y recarga procesada correctamente.'
                          : 'La recarga fue procesada correctamente.'));
                      const successNote = buildBloodStrikeEliteDiscordSuccessNote(data);
                      setPaymentAlert(successMessage, 'success', { extraMessage: successNote });
                      renderDeliveredCodes(data);
                      renderOverpaidPaymentDifference(data);
                      setPaymentFormDisabled(true);
                      clearPaymentTimer();
                      setCancelOrderButtonMode('close');
                      showPaymentStatusModal('Operación exitosa', successMessage, 'success', { extraMessage: successNote });
                      return;
                    }

                    if (nextState === 'cancelado') {
                      const cancelMessage = data.message || 'La orden fue cancelada.';
                      setPaymentAlert(cancelMessage, 'danger');
                      if (String((data && data.provider_flow) || '').trim() !== '') {
                        renderProviderPaymentDetails(data, reference, getConfirmedPaymentTotalText());
                      } else {
                        renderPaymentFailureDetails(data, reference, getConfirmedPaymentTotalText());
                      }
                      setPaymentFormDisabled(true);
                      clearPaymentTimer();
                      setCancelOrderButtonMode('close');
                      showPaymentStatusModal('No se pudo completar la operación', cancelMessage, 'danger');
                      return;
                    }

                    if (nextState === 'pendiente' && providerFlow === 'binance_checkout') {
                      const pendingMessage = data.message || 'Completa el pago en Binance Pay para continuar con tu pedido.';
                      setPaymentAlert(pendingMessage, 'info');
                      renderBinancePaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, getConfirmedPaymentTotalText());
                      setCancelOrderButtonMode('cancel');
                      showPaymentStatusModal('Completa el pago en Binance Pay', pendingMessage, 'info');
                      setPaymentStatusWaiting(true);
                      pollOrderResolution((data && data.provider_reference) ? data.provider_reference : reference, getConfirmedPaymentTotalText(), 1);
                      return;
                    }

                    if (nextState === 'pendiente' && providerFlow === 'paypal_checkout') {
                      const pendingMessage = data.message || 'Completa el pago en PayPal para continuar con tu pedido.';
                      setPaymentAlert(pendingMessage, 'info');
                      renderPayPalPaymentDetails(data, (data && data.provider_reference) ? data.provider_reference : reference, getConfirmedPaymentTotalText());
                      setCancelOrderButtonMode('cancel');
                      showPaymentStatusModal('Completa el pago en PayPal', pendingMessage, 'info');
                      setPaymentStatusWaiting(true);
                      pollOrderResolution((data && data.provider_reference) ? data.provider_reference : reference, getConfirmedPaymentTotalText(), 1);
                      return;
                    }

                    if (nextState === 'pagado') {
                      const paidMessage = data.message || 'El pago fue confirmado correctamente.';
                      const hasProviderDetails = extractPaymentReasons(data).length > 0;
                      const isAcceptedFlow = providerFlow === 'accepted' || providerFlow === 'tracking';
                      const requiresManualReview = providerFlow === 'manual_review' || (!isAcceptedFlow && hasProviderDetails);
                      const successPresentation = isAcceptedFlow ? successfulProviderPendingPresentation(providerFlow, data) : null;
                      const paidNote = requiresManualReview ? '' : buildBloodStrikeEliteDiscordSuccessNote(data);

                      setPaymentAlert(
                        successPresentation ? successPresentation.message : paidMessage,
                        requiresManualReview ? 'warning' : (successPresentation ? (successPresentation.statusType || 'info') : 'success'),
                        { extraMessage: paidNote }
                      );
                      if (hasProviderDetails || providerFlow === 'accepted') {
                        renderProviderPaymentDetails(data, reference, getConfirmedPaymentTotalText());
                      } else {
                        clearPaymentSupportUi();
                      }
                      renderOverpaidPaymentDifference(data);
                      setPaymentFormDisabled(true);
                      clearPaymentTimer();
                      setCancelOrderButtonMode('close');
                      showPaymentStatusModal(
                        requiresManualReview ? 'Revisión requerida' : (successPresentation ? successPresentation.title : 'Operación exitosa'),
                        successPresentation ? successPresentation.message : paidMessage,
                        requiresManualReview ? 'danger' : (successPresentation ? (successPresentation.statusType || 'info') : 'success'),
                        { extraMessage: paidNote }
                      );
                      if (providerFlow === 'accepted' || providerFlow === 'tracking') {
                        setPaymentStatusWaiting(true);
                        pollOrderResolution(reference, getConfirmedPaymentTotalText(), 1);
                      }
                      return;
                    }

                    if (nextState === 'pendiente' && data && data.bank_checked) {
                      if (renderUnderpaidPaymentDifference(data)) {
                        return;
                      }
                      const pendingMessage = data.message || 'No pudimos validar el pago automáticamente.';
                      setPaymentAlert(pendingMessage, 'danger');
                      renderPaymentFailureDetails(data, reference, getConfirmedPaymentTotalText());
                      setPaymentFormDisabled(false);
                      showPaymentStatusModal('Revisión requerida', pendingMessage, 'danger');
                      return;
                    }

                    closePaymentModal(true);
                    resetCheckoutState();
                  })
                  .catch((error) => {
                    setOverlayVisible(loadingModal, false);
                    if (checkoutWindow && !checkoutWindow.closed) {
                      checkoutWindow.close();
                    }
                    const errorMessage = normalizeApiRequestErrorMessage(
                      error,
                      'No pudimos validar tu pago en este momento. Espera 1 minuto y vuelve a intentarlo.'
                    );
                    setPaymentAlert(errorMessage, 'danger');
                    renderPaymentServerFailure(errorMessage, reference, getConfirmedPaymentTotalText());
                    setPaymentFormDisabled(false);
                    showPaymentStatusModal('No se pudo completar la validación', errorMessage, 'danger');
                    if (activePaymentOrder && activePaymentOrder.expiresAtMs <= Date.now()) {
                      expireActiveOrder();
                    }
                  });
                });
              }

              if (monedaSelect) {
                monedaSelect.addEventListener('change', function() {
                  setVisibleCurrency(monedaSelect.value, { syncSelect: false, resetCoupon: true });
                });
              }

              couponInput.addEventListener('input', function() {
                const normalized = normalizeCouponCode(couponInput.value);
                if (couponInput.value !== normalized) {
                  couponInput.value = normalized;
                }
              });

              // Validación de cupón por AJAX
              applyCouponButton.addEventListener('click', function() {
                const cupon = normalizeCouponCode(couponInput.value);
                couponInput.value = cupon;
                const pack = activePack;
                if (!pack) {
                  showToast('Selecciona un paquete antes de aplicar el cupón.', 'error');
                  return;
                }
                // Aseguramos que el precio sea un número puro
                const precioNumerico = String(getPackTotalPrice(pack));
                console.log('Enviando cupón:', cupon, 'Precio:', precioNumerico);
                if (!cupon) {
                  showToast('Ingresa un cupón.', 'error');
                  return;
                }
                fetch(buildAppUrl('/api/validar_cupon.php'), {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: `code=${encodeURIComponent(cupon)}&pack_price=${encodeURIComponent(precioNumerico)}&currency=${encodeURIComponent(pack.moneda || '')}&game_id=${encodeURIComponent("<?= (string) ($game['id'] ?? '') ?>")}`
                })
                .then(res => res.json())
                .then(data => {
                  console.log('Respuesta backend:', data);
                  if (data.success) {
                    couponApplied = true;
                    couponValue = cupon;
                    appliedCouponSummary = {
                      code: cupon,
                      discountAmount: normalizeCurrencyAmount(data.descuento, pack.showDecimals),
                      originalAmount: normalizeCurrencyAmount(Number(data.nuevo_total || 0) + Number(data.descuento || 0), pack.showDecimals),
                      discountType: String(data.tipo_descuento || ''),
                      discountValue: Number(data.valor_descuento || 0),
                    };
                    selectedTotalValue = normalizeCurrencyAmount(data.nuevo_total, pack.showDecimals);
                    pack.purchaseQuantity = getOrderQuantity();
                    updateSelectedPriceDisplay(pack);
                    couponInput.disabled = true;
                    applyCouponButton.disabled = true;
                    renderPublicOrderSummary(pack);
                    updateButtonState();
                    showToast(data.message + ` Descuento: ${formatCurrencyAmount(data.descuento, pack.showDecimals)}`,'success');
                  } else {
                    showToast(data.message, 'error');
                  }
                })
                .catch(() => {
                  showToast('Error de red al validar cupón.', 'error');
                });
              });
              modalNo.addEventListener('click', function() {
                couponApplied = false;
                couponValue = couponInput.value.trim();
                setOverlayVisible(couponModal, false);
                showToast('Compra sin cupón aplicado', 'info');
              });
              modalCancel.addEventListener('click', function() {
                setOverlayVisible(couponModal, false);
              });
              orderForm.addEventListener('input', function() {
                handlePlayerVerificationFieldChange();
                updateButtonState();
              });
              orderForm.addEventListener('change', function() {
                handlePlayerVerificationFieldChange();
                updateButtonState();
              });
              setPaymentDifferenceCreditState(paymentDifferenceCreditState);
              openGameEntryWindowIfNeeded();
              function submitOrderCreationRequest(options = {}) {
                const btn = options.triggerButton instanceof HTMLElement ? options.triggerButton : buyButton;
                const couponVal = normalizeCouponCode(couponInput.value);
                couponInput.value = couponVal;
                const pack = options.pack || activePack;
                const userId = typeof options.forceUserId === 'string'
                  ? options.forceUserId.trim()
                  : (playerPrimaryInput ? playerPrimaryInput.value.trim() : '');
                const playerFields = options.forcePlayerFields && typeof options.forcePlayerFields === 'object'
                  ? options.forcePlayerFields
                  : collectPlayerFields();
                const email = typeof options.forceEmail === 'string'
                  ? options.forceEmail.trim()
                  : (orderEmailInput ? orderEmailInput.value.trim() : '');

                if (orderEmailInput && email !== '') {
                  orderEmailInput.value = email;
                }

                if (!pack) {
                  showToast('Debes seleccionar un paquete.', 'error');
                  return;
                }
                const paymentSelection = resolvePreferredCheckoutSelection(pack);
                if (!paymentSelection.mode) {
                  showToast('Selecciona un metodo de pago antes de continuar.', 'error');
                  return;
                }
                const paymentMethods = getPaymentMethodsForCurrency(pack.moneda || '');
                const pointsCheckoutAvailable = canRedeemPackWithPoints(pack);
                const binanceCheckoutAvailable = canUseBinanceCheckout(pack);
                if (!paymentMethods.length && !pointsCheckoutAvailable && !binanceCheckoutAvailable) {
                  showToast('No hay métodos de pago activos disponibles.', 'error');
                  return;
                }

                const requiredFields = Array.from(orderForm.querySelectorAll('[required]'));
                let requiredFilled = true;
                requiredFields.forEach(field => {
                  const errorId = `${field.name}-error`;
                  let errorElem = document.getElementById(errorId);
                  const missingValue = field.value.trim() === '';
                  const invalidValue = !missingValue && !isCheckoutFieldValid(field);
                  if (missingValue || invalidValue) {
                    requiredFilled = false;
                    if (!errorElem) {
                      errorElem = document.createElement('div');
                      errorElem.id = errorId;
                      errorElem.style.color = '#f87171';
                      errorElem.style.fontSize = '12px';
                      errorElem.textContent = missingValue
                        ? 'Este campo es obligatorio.'
                        : (field.validationMessage || field.dataset.validationMessage || 'El valor ingresado no es válido.');
                      field.parentNode.appendChild(errorElem);
                    } else {
                      errorElem.textContent = missingValue
                        ? 'Este campo es obligatorio.'
                        : (field.validationMessage || field.dataset.validationMessage || 'El valor ingresado no es válido.');
                    }
                  } else if (errorElem) {
                    errorElem.remove();
                  }
                });

                if (!requiredFilled) {
                  return;
                }

                if (requiresVerifiedPlayerForCheckout()) {
                  setPlayerVerificationFeedback('danger', 'Debes verificar el nombre del jugador antes de comprar.');
                  return;
                }

                if (couponVal && !couponApplied) {
                  if (modalCouponName) {
                    modalCouponName.textContent = couponVal;
                  }
                  setOverlayVisible(couponModal, true);
                  modalYes.onclick = function() {
                    setOverlayVisible(couponModal, false);
                    applyCouponButton.click();
                    setTimeout(() => submitOrderCreationRequest(options), 150);
                  };
                  modalNo.onclick = function() {
                    setOverlayVisible(couponModal, false);
                    couponApplied = false;
                    couponInput.value = '';
                    setTimeout(() => submitOrderCreationRequest(options), 100);
                  };
                  modalCancel.onclick = function() {
                    setOverlayVisible(couponModal, false);
                  };
                  return;
                }

                let spinner = document.getElementById('spinner-compra');
                if (!spinner) {
                  spinner = document.createElement('span');
                  spinner.id = 'spinner-compra';
                  spinner.innerHTML = `<svg width="22" height="22" viewBox="0 0 50 50" style="vertical-align:middle;"><circle cx="25" cy="25" r="20" fill="none" stroke="#34d399" stroke-width="5" stroke-linecap="round" stroke-dasharray="31.4 31.4" transform="rotate(-90 25 25)"><animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="1s" repeatCount="indefinite"/></circle></svg>`;
                  spinner.style.marginLeft = '8px';
                  btn.appendChild(spinner);
                }

                const purchaseQuantity = getOrderQuantity();
                pack.purchaseQuantity = purchaseQuantity;
                const precioFinal = String(normalizeCurrencyAmount(selectedTotalValue, pack.showDecimals));
                const pedidoData = {
                  action: 'create',
                  game_id: "<?= $game['id'] ?>",
                  package_id: pack.id || '',
                  game_name: "<?= $game['nombre'] ?>",
                  pack_name: pack.name || '',
                  pack_amount: pack.cantidad || '',
                  quantity: String(purchaseQuantity),
                  currency: pack.moneda || '',
                  price: precioFinal,
                  pack_base: String(getPackTotalPrice(pack, purchaseQuantity)),
                  user_identifier: userId,
                  player_fields_json: JSON.stringify(playerFields),
                  email: email,
                  coupon: couponApplied ? couponVal : '',
                };

                console.log('Datos enviados a pedidos.php:', pedidoData);
                btn.disabled = true;
                setLoadingModalContent('Procesando pedido...', 'Estamos registrando tu pedido para abrir el formulario de pago.', 'processing');
                setOverlayVisible(loadingModal, true);

                fetch(buildAppUrl('/api/pedidos.php'), {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: Object.keys(pedidoData).map(k => `${encodeURIComponent(k)}=${encodeURIComponent(pedidoData[k])}`).join('&')
                })
                .then(async res => {
                  let data = null;
                  try {
                    data = await res.json();
                  } catch (e) {
                    if (res.ok) {
                      showToast('Pedido registrado correctamente', 'success');
                      resetCheckoutState();
                      return;
                    }
                    showToast('Error de red al registrar pedido', 'error');
                    return;
                  }
                  if (data && data.ok) {
                    if (rememberLastPurchaseIdentifierEnabled && userId) {
                      defaultOrderUserIdentifier = userId;
                    }
                    if (data.win_points && Number.isFinite(Number(data.win_points.balance))) {
                      syncWinPointsSummaryFromResponse(data.win_points, { silent: true });
                    }
                    if (data && data.payment_difference && String(data.payment_difference.status || '').toLowerCase() === 'credit_applied') {
                      setPaymentDifferenceCreditState(null);
                    }
                    const createdOrderTotalText = shouldDisplayPackTotalInPoints(pack)
                      ? formatWinPointsAmount(getPackRequiredPoints(pack, purchaseQuantity))
                      : (String((data && data.total_text) || '').trim() || (
                        data && data.payment_difference && String(data.payment_difference.status || '').toLowerCase() === 'credit_applied'
                          ? formatPaymentDifferenceMoney(pack.moneda || monedaActualClave, Number(data.payment_difference.remaining_amount || 0), pack.showDecimals)
                          : selectedPrice.textContent
                      ));
                    const opened = openPaymentModal(data.order_id, data.expires_at, data.remaining_seconds, pack, userId, createdOrderTotalText, email);
                    if (opened) {
                      showToast('Pedido registrado. Completa ahora los datos del pago.', 'success');
                    }
                  } else {
                    showToast((data && data.message) ? data.message : 'Error al registrar pedido', 'error');
                  }
                })
                .catch(() => {
                  showToast('Error de red al registrar pedido.', 'error');
                })
                .finally(() => {
                  btn.disabled = false;
                  removeBuySpinner();
                  setOverlayVisible(loadingModal, false);
                });
              }

              orderForm.addEventListener('submit', function(event) {
                event.preventDefault();
                submitOrderCreationRequest({ triggerButton: buyButton });
              });
              
