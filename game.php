<?php
require_once __DIR__ . "/includes/tenant.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/store_config.php";
require_once __DIR__ . "/includes/currency.php";
require_once __DIR__ . "/includes/payment_methods.php";
require_once __DIR__ . "/includes/recargas_api.php";
require_once __DIR__ . "/includes/api_discord.php";
require_once __DIR__ . "/includes/slugify.php";
require_once __DIR__ . "/includes/player_verification.php";
require_once __DIR__ . "/includes/package_features.php";
require_once __DIR__ . "/includes/payment_difference.php";
require_once __DIR__ . "/includes/game_entry_window_per_game.php";
require_once __DIR__ . "/includes/win_points.php";
require_once __DIR__ . "/includes/binance_pay.php";
require_once __DIR__ . "/includes/paypal_pay.php";
require_once __DIR__ . "/includes/package_account_sales.php";
currency_ensure_schema();
if (trim((string) store_config_get('binance_pagonorte_activo', '0')) === '1') {
  currency_ensure_code('USDT', 'Tether USD', 1.0, true, true);
}
package_features_ensure_schema($mysqli);
package_account_sales_ensure_schema($mysqli);
$paymentSupportWhatsappBase = store_config_whatsapp_link(store_config_get('whatsapp', ''));
$binancePayCheckoutEnabled = binance_pay_is_enabled() && binance_pay_is_configured();
$paypalPayCheckoutEnabled = paypal_pay_checkout_is_enabled() && paypal_pay_is_configured();
$paymentMethodDiscountsEnabled = trim((string) store_config_get('descuento_metodo_pago', '0')) === '1';
$binancePayDiscountPercentage = payment_methods_normalize_discount_percentage(store_config_get('binance_pay_descuento', '0'));
$rememberLastPurchaseIdentifierEnabled = trim((string) store_config_get('guardar_ultimo_id', '0')) === '1';
$packageQuantityPurchaseEnabled = trim((string) store_config_get('cantidad_paquetes', '0')) === '1';
$accountSaleFeatureEnabled = trim((string) store_config_get('vender_cuentas', '0')) === '1';

function fetch_user_legacy_purchase_defaults(mysqli $mysqli, int $userId): array {
  $defaults = [
    'user_identifier' => '',
    'phone' => '',
  ];

  if ($userId <= 0) {
    return $defaults;
  }

  $stmt = $mysqli->prepare('SELECT last_purchase_user_identifier, last_purchase_phone FROM usuarios WHERE id = ? LIMIT 1');
  if (!$stmt) {
    return $defaults;
  }

  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();

  if (!is_array($row)) {
    return $defaults;
  }

  $defaults['user_identifier'] = trim((string) ($row['last_purchase_user_identifier'] ?? ''));
  $defaults['phone'] = trim((string) ($row['last_purchase_phone'] ?? ''));

  return $defaults;
}

function fetch_user_game_purchase_defaults(mysqli $mysqli, int $userId, int $gameId): array {
  $defaults = [
    'has_history' => false,
    'user_identifier' => '',
    'phone' => '',
  ];

  if ($userId <= 0 || $gameId <= 0) {
    return $defaults;
  }

  $tableResult = $mysqli->query("SHOW TABLES LIKE 'pedidos'");
  if (!($tableResult instanceof mysqli_result) || $tableResult->num_rows === 0) {
    return $defaults;
  }

  $stmt = $mysqli->prepare(
    "SELECT
        EXISTS(
          SELECT 1
          FROM pedidos p
          WHERE p.cliente_usuario_id = ? AND p.juego_id = ?
          LIMIT 1
        ) AS has_history,
        (
          SELECT TRIM(p.user_identifier)
          FROM pedidos p
          WHERE p.cliente_usuario_id = ?
            AND p.juego_id = ?
            AND p.user_identifier IS NOT NULL
            AND TRIM(p.user_identifier) <> ''
          ORDER BY p.actualizado_en DESC, p.id DESC
          LIMIT 1
        ) AS user_identifier,
        (
          SELECT TRIM(p.telefono_contacto)
          FROM pedidos p
          WHERE p.cliente_usuario_id = ?
            AND p.juego_id = ?
            AND p.telefono_contacto IS NOT NULL
            AND TRIM(p.telefono_contacto) <> ''
          ORDER BY p.actualizado_en DESC, p.id DESC
          LIMIT 1
        ) AS phone"
  );
  if (!$stmt) {
    return $defaults;
  }

  $stmt->bind_param('iiiiii', $userId, $gameId, $userId, $gameId, $userId, $gameId);
  $stmt->execute();
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();

  if (!is_array($row)) {
    return $defaults;
  }

  $defaults['has_history'] = !empty($row['has_history']);
  $defaults['user_identifier'] = trim((string) ($row['user_identifier'] ?? ''));
  $defaults['phone'] = trim((string) ($row['phone'] ?? ''));

  return $defaults;
}

$loggedUserId = 0;
$loggedUserEmail = '';
$loggedUserLastPurchaseIdentifier = '';
$loggedUserLastPurchasePhone = '';
$paymentDifferenceEnabled = false;
$activePaymentDifferenceCredit = null;
tenant_start_session();
if (!empty($_SESSION['auth_user']['id'])) {
  $loggedUserId = (int) $_SESSION['auth_user']['id'];
}
if (!empty($_SESSION['auth_user']['email'])) {
  $loggedUserEmail = (string) $_SESSION['auth_user']['email'];
}
$paymentDifferenceEnabled = payment_difference_feature_enabled();
$activePaymentDifferenceCredit = $paymentDifferenceEnabled ? payment_difference_get_credit() : null;
payment_methods_ensure_table();
$paymentMethodsByCurrency = payment_methods_active_by_currency();
$game = null;
$requestedGame = isset($_GET['slug']) || isset($_GET['id']);
$requestedPackageId = isset($_GET['package_id']) ? max(0, (int) $_GET['package_id']) : 0;
$requestedSlugSegment = trim((string) ($_GET['requested_slug'] ?? ''));
$requestedSlugSegment = $requestedSlugSegment !== '' ? slugify($requestedSlugSegment) : '';
if ($requestedSlugSegment === 'n-a') {
  $requestedSlugSegment = '';
}
if (isset($_GET['slug'])) {
  $slug = slugify((string) $_GET['slug']);
  if ($slug !== 'n-a') {
    $stmt = $mysqli->prepare("SELECT * FROM juegos WHERE slug=? AND COALESCE(activo, 1) = 1 ORDER BY id ASC LIMIT 1");
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $res = $stmt->get_result();
    $game = $res->fetch_assoc();
    $stmt->close();
  }
} elseif (isset($_GET['id'])) {
  $id = intval($_GET['id']);
  $stmt = $mysqli->prepare("SELECT * FROM juegos WHERE id=? AND COALESCE(activo, 1) = 1 LIMIT 1");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $game = $res->fetch_assoc();
  $stmt->close();
}
if (!$game && !$requestedGame) {
  // Si no se encuentra, mostrar el primero
  $res = $mysqli->query("SELECT * FROM juegos WHERE COALESCE(activo, 1) = 1 ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC LIMIT 1");
  $game = $res ? $res->fetch_assoc() : null;
}
if (!$game) {
  die('Juego no encontrado.');
}

$gameEntryWindowPayload = game_entry_window_public_payload($mysqli, (int) ($game['id'] ?? 0));

if ($loggedUserId > 0) {
  $legacyPurchaseDefaults = fetch_user_legacy_purchase_defaults($mysqli, $loggedUserId);
  if ($rememberLastPurchaseIdentifierEnabled) {
    $loggedUserLastPurchaseIdentifier = $legacyPurchaseDefaults['user_identifier'];
  }
  $loggedUserLastPurchasePhone = $legacyPurchaseDefaults['phone'];

  $gamePurchaseDefaults = fetch_user_game_purchase_defaults($mysqli, $loggedUserId, (int) ($game['id'] ?? 0));
  if (!empty($gamePurchaseDefaults['has_history'])) {
    if ($rememberLastPurchaseIdentifierEnabled) {
      $loggedUserLastPurchaseIdentifier = $gamePurchaseDefaults['user_identifier'];
    }
    $loggedUserLastPurchasePhone = $gamePurchaseDefaults['phone'];
  }
}

if ($requestedGame) {
  $canonicalSlug = game_resolve_slug($game);
  $requiresCanonicalRedirect = isset($_GET['slug']) || $requestedSlugSegment !== $canonicalSlug;
  if ($requiresCanonicalRedirect) {
    $canonicalUrl = app_path(game_route_path($game));
    if ($requestedPackageId > 0) {
      $canonicalUrl .= '?package_id=' . rawurlencode((string) $requestedPackageId);
    }
    header('Location: ' . $canonicalUrl, true, 301);
    exit;
  }
}

$playerVerificationConfig = player_verification_frontend_config($game);
$winPointsConfig = win_points_config();
$winPointsEnabled = !empty($winPointsConfig['enabled']);
$winPointsProgramName = (string) ($winPointsConfig['name'] ?? 'Win Points');
$winPointsIconUrl = (string) ($winPointsConfig['icon_url'] ?? '');
$winPointsPaymentImageUrl = (string) ($winPointsConfig['payment_image_url'] ?? '');
$winPointsPaymentCornerImageUrl = (string) ($winPointsConfig['payment_corner_image_url'] ?? '');
$binancePayImageUrl = trim((string) store_config_get('binance_pay_image', ''));
if ($binancePayImageUrl !== '' && preg_match('#^https?://#i', $binancePayImageUrl) !== 1) {
  $binancePayImageUrl = function_exists('app_path') ? app_path('/' . ltrim($binancePayImageUrl, '/')) : '/' . ltrim($binancePayImageUrl, '/');
}
$binancePayCornerImageUrl = trim((string) store_config_get('binance_pay_corner_image', ''));
if ($binancePayCornerImageUrl !== '' && preg_match('#^https?://#i', $binancePayCornerImageUrl) !== 1) {
  $binancePayCornerImageUrl = function_exists('app_path') ? app_path('/' . ltrim($binancePayCornerImageUrl, '/')) : '/' . ltrim($binancePayCornerImageUrl, '/');
}
$binancePagonorteCheckoutEnabled = trim((string) store_config_get('binance_pagonorte_activo', '0')) === '1'
  && trim((string) store_config_get('binance_pagonorte_token', '')) !== '';
$binancePagonorteDiscountPercentage = payment_methods_normalize_discount_percentage(store_config_get('binance_pagonorte_descuento', '0'));
$binancePagonorteReferenceDigits = max(0, (int) store_config_get('binance_pagonorte_referencia_digitos', '0'));
$paypalPayTaxPercentage = payment_methods_normalize_discount_percentage(store_config_get('paypal_impuesto', '0'));
$binancePagonorteTransferData = trim((string) store_config_get('binance_pagonorte_datos', ''));
$binancePagonorteImageUrl = trim((string) store_config_get('binance_pagonorte_image', ''));
if ($binancePagonorteImageUrl !== '' && preg_match('#^https?://#i', $binancePagonorteImageUrl) !== 1) {
  $binancePagonorteImageUrl = function_exists('app_path') ? app_path('/' . ltrim($binancePagonorteImageUrl, '/')) : '/' . ltrim($binancePagonorteImageUrl, '/');
}
$binancePagonorteCornerImageUrl = trim((string) store_config_get('binance_pagonorte_corner_image', ''));
if ($binancePagonorteCornerImageUrl !== '' && preg_match('#^https?://#i', $binancePagonorteCornerImageUrl) !== 1) {
  $binancePagonorteCornerImageUrl = function_exists('app_path') ? app_path('/' . ltrim($binancePagonorteCornerImageUrl, '/')) : '/' . ltrim($binancePagonorteCornerImageUrl, '/');
}
$binancePagonorteQrImageUrl = trim((string) store_config_get('binance_pagonorte_qr_image', ''));
if ($binancePagonorteQrImageUrl !== '' && preg_match('#^https?://#i', $binancePagonorteQrImageUrl) !== 1) {
  $binancePagonorteQrImageUrl = function_exists('app_path') ? app_path('/' . ltrim($binancePagonorteQrImageUrl, '/')) : '/' . ltrim($binancePagonorteQrImageUrl, '/');
}
$paypalPayImageUrl = trim((string) store_config_get('paypal_image', ''));
if ($paypalPayImageUrl !== '' && preg_match('#^https?://#i', $paypalPayImageUrl) !== 1) {
  $paypalPayImageUrl = function_exists('app_path') ? app_path('/' . ltrim($paypalPayImageUrl, '/')) : '/' . ltrim($paypalPayImageUrl, '/');
}
$paypalPayCornerImageUrl = trim((string) store_config_get('paypal_corner_image', ''));
if ($paypalPayCornerImageUrl !== '' && preg_match('#^https?://#i', $paypalPayCornerImageUrl) !== 1) {
  $paypalPayCornerImageUrl = function_exists('app_path') ? app_path('/' . ltrim($paypalPayCornerImageUrl, '/')) : '/' . ltrim($paypalPayCornerImageUrl, '/');
}
$paypalPayQrImageUrl = trim((string) store_config_get('paypal_qr_image', ''));
if ($paypalPayQrImageUrl !== '' && preg_match('#^https?://#i', $paypalPayQrImageUrl) !== 1) {
  $paypalPayQrImageUrl = function_exists('app_path') ? app_path('/' . ltrim($paypalPayQrImageUrl, '/')) : '/' . ltrim($paypalPayQrImageUrl, '/');
}
$paypalSupportedCurrencies = array_values(array_map(
  static function ($currencyCode) {
    return strtoupper(trim((string) $currencyCode));
  },
  paypal_pay_supported_currencies()
));
$winPointsNotificationLogoUrl = trim((string) store_config_get('recarga_notificaciones_logo', ''));
if ($winPointsNotificationLogoUrl === '') {
  $winPointsNotificationLogoUrl = trim((string) store_config_get('logo_tienda', ''));
}
$winPointsBadgeBackgroundColor = (string) ($winPointsConfig['badge_background_color'] ?? '#3E2D07');
$winPointsBadgeTextColor = (string) ($winPointsConfig['badge_text_color'] ?? '#FCD34D');
$winPointsNotificationPosition = (string) ($winPointsConfig['notification_position'] ?? 'bottom-left');
$winPointsBadgeBorderColor = win_points_hex_to_rgba($winPointsBadgeTextColor, 0.25);
$winPointsBadgeInsetColor = win_points_hex_to_rgba($winPointsBadgeTextColor, 0.08);
$winPointsGuestMessage = (string) ($winPointsConfig['guest_message'] ?? '');
$winPointsUserSummary = $winPointsEnabled && $loggedUserId > 0
  ? win_points_fetch_user_summary($mysqli, $loggedUserId)
  : win_points_empty_user_summary();
$winPointsPackageRewards = $winPointsEnabled
  ? win_points_fetch_game_package_rewards($mysqli, (int) ($game['id'] ?? 0))
  : [];
$winPointsRedemptionRules = $winPointsEnabled
  ? win_points_fetch_game_redemption_rules($mysqli, (int) ($game['id'] ?? 0))
  : [];
$paymentHeaderMinimalEnabled = store_config_get('encabezado_pago', '0') === '1';
$paymentWindowConfigEnabled = store_config_get('ventana_pago_config', '0') === '1';
$paymentSendingOrderTitle = trim((string) store_config_get('ventana_pago_enviando_titulo', 'Enviando orden...'));
if ($paymentSendingOrderTitle === '') {
  $paymentSendingOrderTitle = 'Enviando orden...';
}
$paymentSendingOrderMessage = trim((string) store_config_get('ventana_pago_enviando_mensaje', 'Estamos registrando tu comprobante y procesando la orden según la moneda del pedido. No cierres esta ventana.'));
if ($paymentSendingOrderMessage === '') {
  $paymentSendingOrderMessage = 'Estamos registrando tu comprobante y procesando la orden según la moneda del pedido. No cierres esta ventana.';
}
$paymentSuccessTitle = trim((string) store_config_get('ventana_pago_exitoso_titulo', 'Pago exitoso'));
if ($paymentSuccessTitle === '') {
  $paymentSuccessTitle = 'Pago exitoso';
}
$paymentSuccessExtraMessage = trim((string) store_config_get('ventana_pago_exitoso_mensaje_extra', ''));

$scriptDir = app_base_path();
$gameHeroImagePath = trim((string) ($game['imagen_hero'] ?? ''));
if ($gameHeroImagePath === '') {
  $gameHeroImagePath = trim((string) ($game['imagen'] ?? ''));
}
$gameHeroImageUrl = $gameHeroImagePath !== ''
  ? app_path('/' . ltrim($gameHeroImagePath, '/'))
  : '';
$pageTitle = store_config_get('nombre_tienda', 'TVirtualGaming') . " | " . ($game["nombre"] ?? "Juego");
include __DIR__ . "/includes/header.php";
?>


<section class="container mt-5 mb-4">
  <div class="game-hero-card shadow">
    <div class="game-hero-media" aria-hidden="true">
      <?php if ($gameHeroImageUrl !== ''): ?>
        <img src="<?= htmlspecialchars($gameHeroImageUrl, ENT_QUOTES, "UTF-8") ?>" alt="" class="game-hero-image-backdrop" />
        <img src="<?= htmlspecialchars($gameHeroImageUrl, ENT_QUOTES, "UTF-8") ?>" alt="<?= htmlspecialchars($game["nombre"] ?? '', ENT_QUOTES, "UTF-8") ?>" class="game-hero-image" />
      <?php else: ?>
        <div class="game-hero-fallback"></div>
      <?php endif; ?>
    </div>
    <div class="game-hero-overlay"></div>
    <?php if (!empty($game['popular'])): ?>
      <span title="Popular" class="game-hero-popular">★ Popular</span>
    <?php endif; ?>
    <div class="game-hero-content">
      <div class="game-hero-title-box">
        <h1 class="game-hero-title"><?= htmlspecialchars($game["nombre"] ?? '', ENT_QUOTES, "UTF-8") ?></h1>
      </div>
      <div class="game-hero-features text-secondary small">
        <?php 
          $carRes = $mysqli->query("SELECT caracteristica FROM juego_caracteristicas WHERE juego_id=" . intval($game['id']));
          while ($row = $carRes->fetch_assoc()) {
            echo '<span class="game-feature-badge">' . htmlspecialchars($row['caracteristica']) . '</span>';
          }
        ?>
      </div>
    </div>
  </div>
</section>

<section class="container mt-4">
  <div class="row mb-2 align-items-center">
    <div class="col">
      <h2 class="page-step-title text-info mb-0">PASO 1: Seleccione su paquete</h2>
    </div>
    <div class="col-auto">
      <span class="text-uppercase text-secondary small">elige uno</span>
    </div>
  </div>
  <?php
    // Obtener todas las monedas
    $monedas = [];
    $resAllMon = $mysqli->query("SELECT * FROM monedas ORDER BY es_base DESC, nombre ASC");
    while ($row = $resAllMon->fetch_assoc()) {
      $monedas[] = $row;
    }
    $is_variable = empty($game['moneda_fija_id']);
    $moneda_actual_id = $is_variable ? ($monedas[0]['id'] ?? null) : $game['moneda_fija_id'];
    $moneda_actual = null;
    foreach ($monedas as $m) {
      if ($m['id'] == $moneda_actual_id) {
        $moneda_actual = $m;
        break;
      }
    }
    if (!$moneda_actual && count($monedas)) $moneda_actual = $monedas[0];
  ?>
  <?php if ($is_variable && count($monedas) > 1): ?>
    <div class="mb-4">
      <label for="moneda-select" class="form-label text-info">Selecciona la moneda:</label>
      <select id="moneda-select" class="form-select bg-dark text-info border-info" style="min-width:180px">
        <?php foreach ($monedas as $m): ?>
          <option value="<?= $m['id'] ?>" data-tasa="<?= htmlspecialchars($m['tasa']) ?>" data-clave="<?= htmlspecialchars($m['clave']) ?>" <?= $m['id'] == $moneda_actual['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <?php
    $usesCatalogApi = trim((string) ($game['categoria_api'] ?? '')) !== '';
    $discordCheckoutRequiredFields = api_discord_checkout_required_fields((string) ($game['categoria_api_discord'] ?? ''));
    $apiProductsById = [];
    if ($usesCatalogApi && recargas_api_is_configured()) {
      try {
        foreach (recargas_api_fetch_products_by_category((string) $game['categoria_api']) as $apiProduct) {
          $apiProductsById[(int) ($apiProduct['id'] ?? 0)] = $apiProduct;
        }
      } catch (Throwable $e) {
        $apiProductsById = [];
      }
    }

    $resPaq = $mysqli->query("SELECT * FROM juego_paquetes WHERE juego_id=" . intval($game['id']) . " AND COALESCE(activo, 1) = 1 ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC");
    $paquetes = [];
    while ($pack = $resPaq->fetch_assoc()) {
      $paquetes[] = $pack;
    }
    $packageAccountSaleGalleryMap = $accountSaleFeatureEnabled
      ? package_account_sales_fetch_gallery_map($mysqli, array_map(static fn (array $package): int => (int) ($package['id'] ?? 0), $paquetes))
      : [];
    $packageFeaturesByPackage = package_features_for_packages($mysqli, array_map(static fn (array $package): int => (int) ($package['id'] ?? 0), $paquetes));
  ?>
  <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3 mb-4" id="pack-grid">
    <?php foreach ($paquetes as $pack):
        $precio_base = floatval($pack['precio']);
        $precio_mostrar = $moneda_actual ? currency_convert_from_base($precio_base, $moneda_actual) : currency_apply_amount_rule($precio_base, null);
        $clave_moneda = $moneda_actual['clave'] ?? 'USD';
        $mostrarDecimales = $moneda_actual ? currency_should_show_decimals($moneda_actual) : true;
        $packApiId = (int) ($pack['paquete_api'] ?? 0);
        $packId = (int) ($pack['id'] ?? 0);
        $packWinPointsReward = (int) ($winPointsPackageRewards[$packId] ?? 0);
        $packWinPointsRule = $winPointsRedemptionRules[$packId] ?? null;
        $packWinPointsRequired = max(0, (int) (($packWinPointsRule['required_points'] ?? 0)));
        $packWinPointsRuleActive = is_array($packWinPointsRule) && !empty($packWinPointsRule['activo']) && $packWinPointsRequired > 0;
        $apiRequiredFields = [];
        $packFeatures = $packageFeaturesByPackage[$packId] ?? [];
        $packIsAccountSale = package_account_sales_is_enabled_for_package($pack, $accountSaleFeatureEnabled);
        $packApiProvider = strtolower(trim((string) ($pack['api_provider'] ?? '')));
        if ($packApiProvider === '') {
          if ($packApiId > 0) {
            $packApiProvider = 'giftven';
          } elseif (!empty($pack['monto_ff'])) {
            $packApiProvider = 'free_fire';
          } elseif (!empty($game['categoria_api_discord'])) {
            $packApiProvider = 'discord';
          }
        }
        $packAccountGallery = $packIsAccountSale ? ($packageAccountSaleGalleryMap[$packId] ?? []) : [];
        $packAccountGalleryPayload = array_values(array_map(static function (array $item): array {
          $imageUrl = package_feature_public_asset_url((string) ($item['image_path'] ?? ''));
          return [
            'image_url' => $imageUrl,
            'description' => package_account_sales_normalize_caption((string) ($item['description'] ?? '')),
            'order' => max(1, (int) ($item['order'] ?? 1)),
          ];
        }, array_filter($packAccountGallery, static function (array $item): bool {
          return trim((string) ($item['image_path'] ?? '')) !== '';
        })));
        if ($packApiProvider === 'giftven' && $packApiId > 0 && isset($apiProductsById[$packApiId])) {
          $apiRequiredFields = recargas_api_describe_required_fields($apiProductsById[$packApiId]);
        } elseif ($packApiProvider === 'discord' && !empty($discordCheckoutRequiredFields)) {
          $apiRequiredFields = $discordCheckoutRequiredFields;
        }
        $img_paquete = !empty($pack['imagen_icono']) ? $pack['imagen_icono'] : (!empty($game['imagen_paquete']) ? $game['imagen_paquete'] : null);
        $packImageUrl = package_feature_public_asset_url($img_paquete);
    ?>
      <div class="col">
        <article class="pack-card card border-info bg-dark text-start w-100 h-100 shadow-sm"
          data-package-id="<?= $packId ?>"
          data-package-provider="<?= htmlspecialchars($packApiProvider, ENT_QUOTES, 'UTF-8') ?>"
          data-base="<?= htmlspecialchars($precio_base) ?>"
          data-base-currency="<?= htmlspecialchars($clave_moneda) ?>"
          data-name="<?= htmlspecialchars($pack['nombre'], ENT_QUOTES, 'UTF-8') ?>"
          data-cantidad="<?= htmlspecialchars($pack['cantidad'], ENT_QUOTES, 'UTF-8') ?>"
          data-price-value="<?= htmlspecialchars((string) $precio_mostrar, ENT_QUOTES, 'UTF-8') ?>"
          data-show-decimals="<?= $mostrarDecimales ? '1' : '0' ?>"
          data-required-fields="<?= htmlspecialchars(json_encode($apiRequiredFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
          data-win-points-reward="<?= $packWinPointsReward ?>"
          data-win-points-required="<?= $packWinPointsRequired ?>"
          data-win-points-active="<?= $packWinPointsRuleActive ? '1' : '0' ?>"
          data-package-image="<?= htmlspecialchars($packImageUrl, ENT_QUOTES, 'UTF-8') ?>"
          data-package-features="<?= htmlspecialchars(json_encode($packFeatures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
          data-account-sale="<?= $packIsAccountSale ? '1' : '0' ?>"
          data-account-gallery="<?= htmlspecialchars(json_encode($packAccountGalleryPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
          data-moneda="<?= htmlspecialchars($clave_moneda) ?>"
          tabindex="0"
          role="button"
          aria-pressed="false"
          aria-label="Seleccionar paquete <?= htmlspecialchars($pack['nombre'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="card-body p-0 d-flex flex-column">
            <div class="pack-card-media">
              <?php if ($img_paquete): ?>
                <img src="<?= htmlspecialchars($packImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($pack['nombre'], ENT_QUOTES, 'UTF-8') ?>" class="pack-card-image" />
              <?php else: ?>
                <span class="pack-card-placeholder">PK</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($packFeatures)): ?>
              <div class="pack-card-features" aria-hidden="true">
                <?php foreach ($packFeatures as $feature): ?>
                  <span class="pack-card-feature-badge">
                    <?= package_feature_render_icon((string) ($feature['icon'] ?? 'sparkles'), 'pack-card-feature-icon') ?>
                    <span class="pack-card-feature-text"><?= htmlspecialchars((string) ($feature['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="pack-card-content">
              <p class="pack-card-name mb-0 fw-semibold"><?= htmlspecialchars($pack['nombre'], ENT_QUOTES, 'UTF-8') ?></p>
              <?php if ($packIsAccountSale): ?>
                <div class="pack-account-sale-meta">
                  <span class="pack-account-sale-badge">Cuenta</span>
                  <button type="button" class="pack-account-preview-btn" data-pack-preview-trigger="1">Ver más</button>
                </div>
              <?php endif; ?>
              <div class="pack-card-footer">
                <span class="moneda-label"><?= htmlspecialchars($clave_moneda) ?></span>
                <span class="precio-label">
                  <?= currency_format_amount($precio_mostrar, $moneda_actual) ?>
                </span>
              </div>
              <?php if ($winPointsEnabled && $packWinPointsReward > 0): ?>
                <div class="pack-win-points-badge">
                  <?php if ($winPointsIconUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($winPointsIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($winPointsProgramName, ENT_QUOTES, 'UTF-8') ?>" class="pack-win-points-icon" />
                  <?php endif; ?>
                  <span>+<?= $packWinPointsReward ?> <?= htmlspecialchars($winPointsProgramName, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </article>
      </div>
    <?php endforeach; ?>
  </div>
  <?php 
    $monedas_js = [];
    foreach ($monedas as $m) {
      $monedas_js[$m['id']] = [
        'tasa' => floatval($m['tasa']),
        'clave' => $m['clave'],
        'mostrar_decimales' => !empty($m['mostrar_decimales']),
      ];
    }
  ?>
  <script>
    const monedas = <?= json_encode($monedas_js) ?>;
    let monedaActualId = "<?= $moneda_actual['id'] ?? '' ?>";
    let monedaActualClave = "<?= $moneda_actual['clave'] ?? 'USD' ?>";
    let monedaActualTasa = <?= $moneda_actual['tasa'] ?? 1 ?>;
    let monedaActualMostrarDecimales = <?= $moneda_actual ? (currency_should_show_decimals($moneda_actual) ? 'true' : 'false') : 'true' ?>;
    const monedaSelect = document.getElementById('moneda-select');
    const packCards = Array.from(document.querySelectorAll('.pack-card'));
    const normalizeCurrencyAmount = (amount, showDecimals) => {
      const numericAmount = Number(amount || 0);
      if (!Number.isFinite(numericAmount)) {
        return 0;
      }
      return showDecimals ? Number(numericAmount.toFixed(2)) : Math.trunc(numericAmount);
    };
    const formatCurrencyAmount = (amount, showDecimals) => {
      const normalized = normalizeCurrencyAmount(amount, showDecimals);
      return normalized.toLocaleString('en-US', {
        minimumFractionDigits: showDecimals ? 2 : 0,
        maximumFractionDigits: showDecimals ? 2 : 0,
      });
    };
    function setVisibleCurrency(currencyId, options = {}) {
      const nextId = String(currencyId || '').trim();
      if (nextId === '' || !monedas[nextId]) {
        return false;
      }

      monedaActualId = nextId;
      monedaActualClave = monedas[nextId].clave || 'USD';
      monedaActualTasa = parseFloat(monedas[nextId].tasa || '1');
      monedaActualMostrarDecimales = Boolean(monedas[nextId] && monedas[nextId].mostrar_decimales);

      if (options.syncSelect !== false && monedaSelect && String(monedaSelect.value) !== nextId) {
        monedaSelect.value = nextId;
      }

      updatePackPrices();

      if (activePack) {
        const selectedCard = packCards2.find((card) => card.classList.contains('neon-selected'));
        if (selectedCard) {
          activePack = buildPackStateFromCard(selectedCard);
          updateResumenCompra(activePack);
          renderPlayerFields(activePack);
        }
      } else {
        renderPlayerFields(null);
        updateResumenCompra(null);
      }

      if (options.resetCoupon !== false) {
        if (couponInput && couponInput.value.trim() !== '') {
          couponInput.value = '';
        }
        resetCouponState();
      }

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
  </script>
</section>


  <div class="container mb-4">
    <div id="purchase-summary-layout" class="purchase-summary-layout<?= $packageQuantityPurchaseEnabled ? '' : ' purchase-summary-layout-single' ?>">
      <?php if ($packageQuantityPurchaseEnabled): ?>
      <div class="purchase-summary-column purchase-summary-column-quantity">
        <div id="purchase-quantity-panel" class="purchase-quantity-panel">
          <label for="order-quantity" class="purchase-quantity-label">Cantidad a comprar</label>
          <div class="purchase-quantity-stepper">
            <button type="button" id="order-quantity-decrease" class="purchase-quantity-btn" aria-label="Disminuir cantidad" disabled>-</button>
            <input type="number" id="order-quantity" min="1" step="1" value="1" inputmode="numeric" class="purchase-quantity-input" disabled>
            <button type="button" id="order-quantity-increase" class="purchase-quantity-btn" aria-label="Aumentar cantidad" disabled>+</button>
          </div>
          <div id="order-quantity-help" class="purchase-quantity-help">Selecciona un paquete para indicar la cantidad.</div>
        </div>
      </div>
      <?php endif; ?>
      <div class="purchase-summary-column purchase-summary-column-result">
        <div class="purchase-summary-pack-copy purchase-summary-pack-card">
          <div>
            <p class="purchase-summary-card-label mb-1">Paquete seleccionado</p>
            <p id="selected-pack" class="purchase-summary-pack-name mb-0">Debes seleccionar un paquete.</p>
          </div>
          <div class="purchase-summary-total-block">
            <p class="purchase-summary-card-label mb-1">Total</p>
            <p id="selected-price" class="purchase-summary-total-value mb-0"><?= ($moneda_actual['clave'] ?? 'Bs.') . ' ' . currency_format_amount(0, $moneda_actual) ?></p>
          </div>
          <p id="selected-price-detail" class="small text-secondary mb-0 d-none"></p>
          <p id="selected-win-points-total" class="small fw-semibold text-warning mb-0 d-none"></p>
          <div id="payment-difference-banner" class="d-none payment-difference-banner mt-3"></div>
        </div>
      </div>
    </div>
  </div>
</section>


<section class="container mt-4 mb-3">
  <h2 class="page-step-title text-info mb-0">PASO 2: Ingrese su información de jugador</h2>
</section>


<section class="container mt-5 mb-5 p-4 bg-dark bg-opacity-75 rounded-4 shadow">
  <form class="row g-3" id="order-form">
    <div class="col-12">
      <div class="row g-3" id="player-fields-row">
        <div class="col-md-6 col-12" id="player-primary-field">
          <label class="form-label text-info" id="player-primary-label">ID de usuario</label>
          <div class="d-flex flex-column flex-sm-row gap-2 align-items-stretch">
            <input type="text" id="order-user-id" name="user_id" placeholder="Ej: 12345678" value="<?= htmlspecialchars($loggedUserLastPurchaseIdentifier, ENT_QUOTES, 'UTF-8') ?>" class="form-control bg-dark text-info border-info" required />
            <button type="button" id="verify-player-button" class="btn btn-outline-info fw-bold text-nowrap d-none"><?= htmlspecialchars((string) ($playerVerificationConfig['buttonLabel'] ?? 'Verificar nombre del jugador'), ENT_QUOTES, 'UTF-8') ?></button>
          </div>
          <div id="player-verification-feedback" class="d-none mt-2"></div>
        </div>
        <div id="extra-player-fields" class="col-md-6 col-12"></div>
      </div>
    </div>
    <div class="col-md-6">
      <label class="form-label text-info">Correo</label>
      <input type="email" name="email" placeholder="tu@email.com" value="<?= htmlspecialchars($loggedUserEmail, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" class="form-control bg-dark text-info border-info" required />
    </div>
    <div class="col-md-6">
      <label class="form-label text-info">Información importante</label>
      <div class="email-disclaimer-card">
        El correo electronico ingresado sera utilizado exclusivamente, para el envio de su comprobante electronico
      </div>
    </div>
    <div class="col-12">
      <div id="account-sale-note" class="d-none alert account-sale-note mb-0">
        Al verificar el pago te mostraremos los datos completos de la cuenta comprada junto con su galería registrada.
      </div>
    </div>
    <div class="col-12">
    </div>
  </form>
</section>

<section class="container mt-3 mb-5">
  <h2 class="page-step-title text-info mb-0">PASO 3: Configure su pago y continúe con la compra</h2>
  <div class="payment-coupon-shell mt-4">
    <div class="payment-coupon-panel">
      <label class="form-label text-info mb-2">Cupón</label>
      <div class="input-group">
        <input type="text" name="coupon" id="coupon-input" placeholder="Código opcional" pattern="[A-Za-z0-9]+" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" title="Solo letras y números, sin espacios ni caracteres especiales." class="form-control bg-dark text-info border-info" />
        <button type="button" id="apply-coupon-btn" class="btn btn-info fw-bold">Activar Código</button>
      </div>
    </div>
  </div>
  <div class="payment-method-catalog-shell mt-4">
    <div class="payment-method-catalog-panel">
      <div class="payment-method-catalog-head">
        <h3 class="payment-method-catalog-title mb-0">Métodos de pago disponibles</h3>
        <p id="payment-method-catalog-copy" class="payment-method-catalog-copy mb-0">Selecciona cómo quieres pagar y mostraremos los precios en esa moneda incluso antes de elegir el paquete.</p>
      </div>
      <div id="payment-method-catalog-grid" class="payment-method-catalog-grid"></div>
    </div>
  </div>
  <div id="public-order-summary-shell" class="payment-order-summary-shell mt-4 d-none">
    <div id="public-order-summary-coupon" class="payment-order-summary-coupon d-none">
      <span class="payment-order-summary-coupon-badge">Cupón activo</span>
      <strong id="public-order-summary-coupon-copy" class="payment-order-summary-coupon-copy"></strong>
    </div>
    <div id="public-order-summary-panel" class="payment-order-summary-panel">
      <div class="payment-order-summary-head">
        <div>
          <h3 class="payment-order-summary-title mb-0">Resumen del Pedido</h3>
        </div>
        <div id="public-order-summary-method" class="payment-order-summary-method d-none"></div>
      </div>
      <div id="public-order-summary-rows" class="payment-order-summary-rows"></div>
      <div class="payment-order-summary-total-wrap">
        <span class="payment-order-summary-total-label">Total</span>
        <strong id="public-order-summary-total" class="payment-order-summary-total-value">-</strong>
      </div>
      <button type="submit" id="buy-button" form="order-form" class="payment-order-summary-buy-btn" disabled>
        Comprar Ahora
      </button>
      <?php if ($winPointsEnabled && $loggedUserId <= 0): ?>
        <div id="win-points-guest-hint" class="win-points-guest-hint mt-3">
          <?= htmlspecialchars($winPointsGuestMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>


  <?php if (!empty($gameEntryWindowPayload['enabled'])): ?>
  <div id="game-entry-window-modal" class="app-overlay-modal game-entry-window-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="game-entry-window-modal-content" style="--entry-window-background: <?= htmlspecialchars((string) ($gameEntryWindowPayload['modal_background'] ?? '#18101e'), ENT_QUOTES, 'UTF-8') ?>; --entry-window-border-color: <?= htmlspecialchars((string) ($gameEntryWindowPayload['modal_border_color'] ?? '#fb923c'), ENT_QUOTES, 'UTF-8') ?>; --entry-window-title-color: <?= htmlspecialchars((string) ($gameEntryWindowPayload['title_color'] ?? '#f8b53d'), ENT_QUOTES, 'UTF-8') ?>; --entry-window-check-color: <?= htmlspecialchars((string) ($gameEntryWindowPayload['check_text_color'] ?? '#e2e8f0'), ENT_QUOTES, 'UTF-8') ?>; --entry-window-check-background: <?= htmlspecialchars((string) ($gameEntryWindowPayload['check_background_color'] ?? '#1e293b'), ENT_QUOTES, 'UTF-8') ?>; --entry-window-button-color: <?= htmlspecialchars((string) ($gameEntryWindowPayload['button_text_color'] ?? '#0b0f18'), ENT_QUOTES, 'UTF-8') ?>; --entry-window-button-background: <?= htmlspecialchars((string) ($gameEntryWindowPayload['button_background_color'] ?? '#c99712'), ENT_QUOTES, 'UTF-8') ?>; --entry-window-button-disabled-color: <?= htmlspecialchars((string) ($gameEntryWindowPayload['button_disabled_text_color'] ?? '#0b0f18'), ENT_QUOTES, 'UTF-8') ?>; --entry-window-button-disabled-background: <?= htmlspecialchars((string) ($gameEntryWindowPayload['button_disabled_background_color'] ?? '#c99712'), ENT_QUOTES, 'UTF-8') ?>;">
        <div class="game-entry-window-modal-header">
          <div class="game-entry-window-modal-media">
            <?php if (!empty($gameEntryWindowPayload['icon'])): ?>
              <img src="<?= htmlspecialchars((string) $gameEntryWindowPayload['icon'], ENT_QUOTES, 'UTF-8') ?>" alt="Ventana inicial en juegos" class="game-entry-window-modal-image">
            <?php else: ?>
              <span class="game-entry-window-modal-media-fallback">VG</span>
            <?php endif; ?>
          </div>
          <h3 class="game-entry-window-modal-heading"><?= htmlspecialchars((string) ($gameEntryWindowPayload['title'] ?? 'ANTES DE CONTINUAR'), ENT_QUOTES, 'UTF-8') ?></h3>
          <p class="game-entry-window-modal-copy"><?= htmlspecialchars((string) ($gameEntryWindowPayload['copy_text'] ?? 'Lee la información antes de continuar con la recarga.'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="game-entry-window-modal-cards">
          <?php foreach (($gameEntryWindowPayload['cards'] ?? []) as $entryCard): ?>
            <article class="game-entry-window-info-card" style="--entry-card-color: <?= htmlspecialchars((string) ($entryCard['color'] ?? '#233A73'), ENT_QUOTES, 'UTF-8') ?>; --entry-card-background: <?= htmlspecialchars((string) ($entryCard['background_color'] ?? '#121a2f'), ENT_QUOTES, 'UTF-8') ?>; --entry-card-glow: <?= htmlspecialchars(game_entry_window_hex_to_rgba((string) ($entryCard['color'] ?? '#233A73'), 0.18), ENT_QUOTES, 'UTF-8') ?>;">
              <?= game_entry_window_render_card_markup(is_array($entryCard) ? $entryCard : []) ?>
            </article>
          <?php endforeach; ?>
        </div>
        <div id="game-entry-window-confirmation" class="game-entry-window-confirmation">
          <label class="game-entry-window-confirmation-toggle" for="game-entry-window-check">
            <input type="checkbox" id="game-entry-window-check" class="game-entry-window-confirmation-input" onchange="window.toggleGameEntryWindowConfirmation && window.toggleGameEntryWindowConfirmation(this.checked);">
            <span class="game-entry-window-confirmation-text"><?= htmlspecialchars((string) ($gameEntryWindowPayload['check_text'] ?? 'He leído y entiendo las condiciones del servicio'), ENT_QUOTES, 'UTF-8') ?></span>
          </label>
        </div>
        <button type="button" id="game-entry-window-continue" class="btn btn-warning fw-bold text-uppercase py-3" onclick="return window.acceptGameEntryWindow ? window.acceptGameEntryWindow() : false;" disabled><?= htmlspecialchars((string) ($gameEntryWindowPayload['button_text'] ?? 'Aceptar y continuar'), ENT_QUOTES, 'UTF-8') ?></button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Modal Loading Bootstrap -->
  <div id="loading-modal" class="modal fade app-overlay-modal<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>" tabindex="-1" aria-hidden="true" data-payment-loading-state="processing">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark border-info text-center p-4 payment-loading-modal-content<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>">
        <div class="mb-3">
          <svg width="48" height="48" viewBox="0 0 50 50">
            <circle id="loading-modal-spinner-circle" cx="25" cy="25" r="20" fill="none" stroke="#34d399" stroke-width="5" stroke-linecap="round" stroke-dasharray="31.4 31.4" transform="rotate(-90 25 25)">
              <animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="1s" repeatCount="indefinite"/>
            </circle>
          </svg>
        </div>
        <h4 id="loading-modal-title" class="fw-bold text-info mb-2 payment-loading-modal-title">Procesando pedido...</h4>
        <p id="loading-modal-message" class="text-light mb-0 small payment-loading-modal-message">Espera un momento mientras completamos la operación.</p>
      </div>
    </div>
  </div>
  <div id="payment-status-modal" class="modal fade app-overlay-modal<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>" tabindex="-1" aria-hidden="true" data-payment-status-state="info">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark border-info text-center p-4 payment-status-modal-content<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>">
        <h4 id="payment-status-modal-title" class="fw-bold text-info mb-3 payment-status-modal-title">Estado de la operación</h4>
        <p id="payment-status-modal-message" class="text-light mb-4 small payment-status-modal-message">Tu solicitud fue procesada.</p>
        <p id="payment-status-modal-extra-message" class="d-none text-light opacity-75 mb-4 small payment-status-modal-extra-message"></p>
        <div id="payment-status-modal-reasons" class="d-none payment-reasons-card mb-3 text-start"></div>
        <div id="payment-status-modal-actions" class="d-none payment-support-actions mb-4"></div>
        <button type="button" id="payment-status-modal-accept" class="btn btn-info fw-bold px-4 payment-status-modal-accept-btn<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>">Aceptar</button>
      </div>
    </div>
  </div>
  <div id="account-gallery-modal" class="modal fade app-overlay-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
      <div class="modal-content bg-dark border-info text-light p-0 account-gallery-modal-content">
        <div class="account-gallery-modal-header">
          <div>
            <p class="account-gallery-modal-eyebrow mb-1">Vista previa</p>
            <h4 id="account-gallery-modal-title" class="fw-bold text-info mb-0">Cuenta disponible</h4>
          </div>
          <button type="button" id="account-gallery-modal-close" class="btn btn-outline-light btn-sm">Cerrar</button>
        </div>
        <div class="account-gallery-modal-body">
          <div class="account-gallery-modal-details">
            <p id="account-gallery-modal-price" class="account-gallery-modal-price mb-0"></p>
            <p class="account-gallery-modal-copy mb-0">La entrega de credenciales se mostrará después de verificar el pago.</p>
          </div>
          <div class="account-gallery-main-frame">
            <img id="account-gallery-modal-image" src="" alt="Vista previa de la cuenta" class="account-gallery-main-image d-none" />
            <div id="account-gallery-modal-placeholder" class="account-gallery-main-placeholder">Sin imágenes registradas</div>
            <p id="account-gallery-modal-caption" class="account-gallery-modal-caption mb-0"></p>
          </div>
          <div id="account-gallery-modal-thumbs" class="account-gallery-thumbs"></div>
        </div>
        <div class="account-gallery-modal-actions">
          <button type="button" id="account-gallery-modal-buy" class="btn btn-info fw-bold">Comprar</button>
        </div>
      </div>
    </div>
  </div>
  <!-- Modal Cupón Bootstrap -->
  <div id="coupon-modal" class="modal fade app-overlay-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark border-info text-center p-4">
        <h4 class="fw-bold text-info mb-2">¿Desea aplicar el cupón <span id="modal-coupon-name" class="text-success"></span>?</h4>
        <div class="d-flex gap-2 justify-content-center mt-4">
          <button type="button" id="modal-yes" class="btn btn-success">Sí</button>
          <button type="button" id="modal-no" class="btn btn-info">No</button>
          <button type="button" id="modal-cancel" class="btn btn-secondary">Cancelar</button>
        </div>
      </div>
    </div>
  </div>

  <div id="payment-modal" class="modal fade app-overlay-modal<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled payment-main-modal-theme-enabled' : '' ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered payment-modal-dialog">
      <div class="modal-content payment-modal-content text-light border-info<?= $paymentWindowConfigEnabled ? ' payment-modal-skin-enabled' : '' ?>">
        <div class="payment-expiration-banner" id="payment-expiration-banner">
          <span>La orden expira en:</span>
          <strong id="payment-timer-value">30:00</strong>
        </div>
        <div id="payment-modal-alert" class="d-none alert mb-3"></div>
        <div id="payment-modal-reasons" class="d-none payment-reasons-card mb-3"></div>
        <div id="payment-modal-actions" class="d-none payment-support-actions mb-4"></div>
        <div class="payment-summary-card mb-4<?= $paymentHeaderMinimalEnabled ? ' payment-summary-card--minimal' : '' ?>">
          <div class="payment-summary-minimal">
            <div class="payment-summary-minimal-media">
              <img id="payment-summary-image" src="" alt="Paquete" class="payment-summary-minimal-image d-none" />
              <span id="payment-summary-image-placeholder" class="payment-summary-minimal-placeholder">PK</span>
            </div>
            <div class="payment-summary-minimal-copy">
              <h3 id="payment-summary-minimal-product" class="payment-summary-minimal-title">-</h3>
              <div id="payment-summary-features" class="payment-summary-features d-none"></div>
              <div class="payment-summary-minimal-user">ID Jugador: <strong id="payment-summary-minimal-user">-</strong></div>
            </div>
            <div class="payment-summary-minimal-price">
              <div class="payment-summary-minimal-total-wrap">
                <div id="payment-summary-minimal-total" class="payment-summary-minimal-total">-</div>
                <button type="button" id="payment-summary-minimal-total-copy" class="payment-summary-copy-btn" aria-label="Copiar monto" title="Copiar monto" data-copy-tooltip="Copiar monto" disabled>
                  <span class="payment-summary-copy-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" role="img"><rect x="9" y="7" width="10" height="12" rx="2"></rect><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"></path></svg></span>
                </button>
              </div>
            </div>
          </div>
          <h3 class="payment-summary-card-title h5 fw-bold text-white mb-3">Resumen de Pago</h3>
          <div class="payment-summary-row"><span>ID Jugador:</span><strong id="payment-summary-user">-</strong></div>
          <div class="payment-summary-row"><span>Producto:</span><strong id="payment-summary-product">-</strong></div>
          <div class="payment-summary-row payment-summary-total"><span>Total a pagar:</span><span class="payment-summary-total-actions"><strong id="payment-summary-total">-</strong><button type="button" id="payment-summary-total-copy" class="payment-summary-copy-btn" aria-label="Copiar monto" title="Copiar monto" data-copy-tooltip="Copiar monto" disabled><span class="payment-summary-copy-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" role="img"><rect x="9" y="7" width="10" height="12" rx="2"></rect><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"></path></svg></span></button></span></div>
          <div id="payment-summary-discount" class="payment-summary-discount d-none"></div>
        </div>
        <div id="payment-win-points-card" class="payment-win-points-card d-none">
          <div class="payment-win-points-header">
            <div>
              <div id="payment-win-points-title" class="payment-win-points-title">Premios disponibles</div>
              <div id="payment-win-points-copy" class="payment-win-points-copy">Elige si deseas completar esta orden con transferencia o con tus premios acumulados.</div>
            </div>
            <div id="payment-win-points-balance" class="payment-win-points-balance">0</div>
          </div>
          <div id="payment-mode-options" class="payment-win-points-actions"></div>
        </div>
        <div class="payment-mode-panels mb-4">
          <div id="payment-money-panel" class="payment-mode-panel is-active">
            <div class="payment-mode-panel-inner">
              <div id="payment-method-card" class="payment-method-card">
                <div id="payment-method-select-wrap" class="mb-3 d-none">
                  <label for="payment-method-select" class="form-label text-info">Método de pago</label>
                  <select id="payment-method-select" class="form-select bg-dark text-info border-info"></select>
                </div>
                <h4 id="payment-method-title" class="h6 fw-bold text-white mb-2">Datos de pago</h4>
                <div id="payment-method-currency" class="small text-info mb-2"></div>
                <div id="payment-method-details" class="small text-light payment-method-details"></div>
                <div id="payment-method-qr-wrap" class="payment-method-qr-wrap d-none">
                  <div class="payment-method-qr-label">QR del método de pago</div>
                  <img id="payment-method-qr-image" src="" alt="QR del método de pago" class="payment-method-qr-image">
                </div>
                <div id="payment-method-discount" class="payment-method-discount d-none"></div>
              </div>
              <div id="payment-reference-group" class="mb-3">
                <label for="payment-reference-input" class="form-label text-info">Número de Referencia</label>
                <input type="text" id="payment-reference-input" class="form-control bg-dark text-info border-info" inputmode="numeric" autocomplete="off" placeholder="Inserte su número de referencia para comprobar el pago">
                <div id="payment-reference-help" class="form-text text-secondary">Inserte su número de referencia para comprobar el pago.</div>
              </div>
              <div id="payment-phone-group">
                <label for="payment-phone-input" class="form-label text-info">Número de teléfono real para contactarte</label>
                <input type="tel" id="payment-phone-input" class="form-control bg-dark text-info border-info" autocomplete="tel" value="<?= htmlspecialchars($loggedUserLastPurchasePhone, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ej: 04121234567">
              </div>
            </div>
          </div>
        </div>
        <button type="button" id="payment-submit-btn" class="btn btn-info w-100 fw-bold text-uppercase py-3 payment-submit-btn-theme<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>">Confirmar / Recargar</button>
        <button type="button" id="payment-cancel-order-btn" class="btn btn-danger w-100 fw-bold text-uppercase py-3 mt-3 payment-cancel-btn-theme<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>">Cancelar Orden</button>
      </div>
    </div>
  </div>

  <div id="payment-cancel-confirm-modal" class="modal fade app-overlay-modal payment-confirm-overlay" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark border-danger text-light p-4 rounded-4">
        <h4 class="fw-bold text-danger mb-3">¿Deseas cancelar esta orden?</h4>
        <p class="text-light mb-4">La orden se marcará como cancelada y deberás generar una nueva si quieres continuar con la compra.</p>
        <div class="d-flex gap-2 justify-content-end flex-wrap">
          <button type="button" id="payment-cancel-dismiss-btn" class="btn btn-outline-info">Volver</button>
          <button type="button" id="payment-cancel-confirm-btn" class="btn btn-danger">Sí, cancelar orden</button>
        </div>
      </div>
    </div>
  </div>

<style>
  .app-overlay-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 13200;
    opacity: 0;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(5, 10, 20, 0.78);
    backdrop-filter: blur(4px);
    overflow-y: auto;
    overscroll-behavior-y: contain;
    -webkit-overflow-scrolling: touch;
  }

  .app-overlay-modal.is-visible {
    display: flex !important;
    opacity: 1 !important;
  }

  #loading-modal {
    z-index: 13225;
  }

  #payment-status-modal {
    z-index: 13230;
  }

  .game-entry-window-modal {
    z-index: 13240;
  }

  .game-entry-window-modal .modal-dialog {
    width: min(94vw, 42rem);
  }

  .game-entry-window-modal-content {
    border-radius: 1.75rem;
    padding: 1rem;
    pointer-events: auto;
    background: radial-gradient(circle at top, rgba(251, 191, 36, 0.16), transparent 34%), linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01)), var(--entry-window-background, #18101e);
    border: 1px solid var(--entry-window-border-color, #fb923c);
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.48);
  }

  .game-entry-window-modal-header {
    text-align: center;
    margin-bottom: 0.75rem;
  }

  .game-entry-window-modal-media {
    width: 64px;
    height: 64px;
    margin: 0 auto 0.6rem;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.12);
    background: linear-gradient(135deg, rgba(250, 204, 21, 0.18), rgba(34, 211, 238, 0.18));
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    box-shadow: 0 0 0 8px rgba(255,255,255,0.04);
  }

  .game-entry-window-modal-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .game-entry-window-modal-media-fallback {
    color: #fde68a;
    font-size: 1.4rem;
    font-weight: 800;
    letter-spacing: 0.12em;
  }

  .game-entry-window-modal-heading {
    margin: 0;
    color: var(--entry-window-title-color, #fbbf24);
    font-size: clamp(1.2rem, 3vw, 1.58rem);
    font-weight: 800;
    letter-spacing: 0.03em;
    line-height: 1.04;
  }

  .game-entry-window-modal-copy {
    margin: 0.35rem auto 0;
    max-width: 25rem;
    color: rgba(226, 232, 240, 0.84);
    font-size: 0.86rem;
    line-height: 1.45;
  }

  .game-entry-window-modal-cards {
    display: grid;
    gap: 1rem;
    margin-bottom: 1rem;
    max-height: min(48vh, 28rem);
    overflow-y: auto;
    padding-right: 0.25rem;
  }

  .game-entry-window-info-card {
    padding: 1rem 1rem 1rem 1.1rem;
    border-radius: 1.05rem;
    background: var(--entry-card-background, #121a2f);
    border: 1px solid var(--entry-card-color, #233A73);
    box-shadow: 0 12px 28px var(--entry-card-glow, rgba(35, 58, 115, 0.18));
    color: #e2e8f0;
  }

  .game-entry-window-info-card p:last-child,
  .game-entry-window-info-card ul:last-child,
  .game-entry-window-info-card ol:last-child,
  .game-entry-window-info-card blockquote:last-child,
  .game-entry-window-info-card h2:last-child,
  .game-entry-window-info-card h3:last-child {
    margin-bottom: 0;
  }

  .game-entry-window-card-media {
    margin-bottom: 0.9rem;
    border-radius: 0.9rem;
    overflow: hidden;
    background: rgba(2, 6, 23, 0.35);
  }

  .game-entry-window-card-image,
  .game-entry-window-card-video,
  .game-entry-window-card-embed {
    width: 100%;
    display: block;
    border: 0;
  }

  .game-entry-window-card-image,
  .game-entry-window-card-video {
    max-height: 320px;
    object-fit: cover;
  }

  .game-entry-window-card-embed {
    min-height: 240px;
    aspect-ratio: 16 / 9;
    background: #020617;
  }

  .game-entry-window-card-embed-tiktok {
    min-height: 520px;
    aspect-ratio: auto;
  }

  .game-entry-window-confirmation {
    display: flex;
    gap: 0.6rem;
    align-items: center;
    position: relative;
    z-index: 3;
    pointer-events: auto;
    padding: 0.72rem 0.82rem;
    margin-bottom: 0.8rem;
    border-radius: 0.82rem;
    background: var(--entry-window-check-background, #1e293b);
    border: 1px solid rgba(255,255,255,0.08);
    color: var(--entry-window-check-color, #e2e8f0);
    cursor: pointer;
  }

  .game-entry-window-confirmation-toggle {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    width: 100%;
    margin: 0;
    color: inherit;
    cursor: pointer;
  }

  .game-entry-window-confirmation-text {
    flex: 1 1 auto;
    margin: 0;
    font-size: 0.8rem;
    line-height: 1.38;
  }

  .game-entry-window-confirmation-input {
    appearance: none;
    -webkit-appearance: none;
    pointer-events: auto;
    width: 2.2rem;
    height: 1.15rem;
    margin: 0;
    flex: 0 0 auto;
    cursor: pointer;
    background-color: rgba(7, 18, 28, 0.85);
    border-color: rgba(255,255,255,0.24);
    border: 1px solid rgba(255,255,255,0.24);
    border-radius: 999px;
    box-shadow: none;
    position: relative;
    transition: background-color 0.2s ease, border-color 0.2s ease;
  }

  .game-entry-window-confirmation-input::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 2px;
    width: 0.82rem;
    height: 0.82rem;
    border-radius: 999px;
    background: #f8fafc;
    transform: translateY(-50%);
    transition: transform 0.2s ease;
  }

  .game-entry-window-confirmation .game-entry-window-confirmation-input:focus {
    box-shadow: 0 0 0 0.2rem rgba(245, 158, 11, 0.18);
  }

  .game-entry-window-confirmation .game-entry-window-confirmation-input:checked {
    background-color: var(--entry-window-button-background, #c99712);
    border-color: var(--entry-window-button-background, #c99712);
  }

  .game-entry-window-confirmation .game-entry-window-confirmation-input:checked::after {
    transform: translate(1rem, -50%);
  }

  .game-entry-window-confirmation.is-checked {
    border-color: rgba(245, 158, 11, 0.55);
    box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.18);
  }

  #game-entry-window-continue {
    position: relative;
    z-index: 3;
    background: var(--entry-window-button-disabled-background, #c99712);
    border-color: transparent;
    color: var(--entry-window-button-disabled-color, #0b0f18);
    min-height: 2.8rem;
    font-size: 0.88rem;
    transition: background 0.2s ease, color 0.2s ease, opacity 0.2s ease;
  }

  @media (max-width: 575.98px) {
    .game-entry-window-modal-content {
      padding: 0.9rem;
      border-radius: 1.5rem;
    }

    .game-entry-window-modal-media {
      width: 56px;
      height: 56px;
      margin-bottom: 0.5rem;
    }

    .game-entry-window-modal-copy {
      font-size: 0.8rem;
    }

    .game-entry-window-confirmation {
      padding: 0.66rem 0.72rem;
    }

    .game-entry-window-card-embed-tiktok {
      min-height: 460px;
    }
  }

  #game-entry-window-continue:disabled {
    opacity: 0.7;
    cursor: not-allowed;
  }

  #game-entry-window-continue:not(:disabled) {
    background: var(--entry-window-button-background, #c99712);
    color: var(--entry-window-button-color, #0b0f18);
    opacity: 1;
  }

  .app-overlay-modal .modal-dialog {
    width: min(92vw, 28rem);
    margin: 0;
  }

  .payment-modal-dialog {
    width: min(94vw, 34rem) !important;
    margin: auto;
  }

  .payment-modal-content {
    position: relative;
    padding: 1.25rem;
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    border-radius: 1.5rem;
    background: linear-gradient(180deg, rgba(31, 41, 55, 0.98), rgba(17, 24, 39, 0.98));
    box-shadow: 0 0 28px rgba(34, 211, 238, 0.16);
  }

  .payment-expiration-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 3.4rem;
    margin-bottom: 1rem;
    border: 1px solid rgba(248, 113, 113, 0.45);
    border-radius: 1rem;
    background: rgba(127, 29, 29, 0.12);
    color: #f87171;
    font-weight: 700;
  }

  .payment-summary-card,
  .payment-method-card {
    padding: 1rem;
    border-radius: 1rem;
    background: rgba(8, 15, 24, 0.74);
    border: 1px solid rgba(34, 211, 238, 0.15);
  }

  .payment-summary-card-title {
    margin-bottom: 1rem;
  }

  .payment-summary-minimal {
    display: none;
    grid-template-columns: 78px minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: start;
  }

  .payment-summary-card--minimal .payment-summary-minimal {
    display: grid;
  }

  .payment-summary-card--minimal .payment-summary-card-title,
  .payment-summary-card--minimal .payment-summary-row {
    display: none;
  }

  .payment-summary-minimal-media {
    width: 78px;
    height: 78px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 1.35rem;
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(22, 78, 99, 0.48));
    border: 1px solid rgba(34, 211, 238, 0.28);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
    overflow: hidden;
  }

  .payment-summary-minimal-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .payment-summary-minimal-placeholder {
    font-size: 1.1rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    color: #67e8f9;
  }

  .payment-summary-minimal-copy {
    min-width: 0;
  }

  .payment-summary-minimal-title {
    margin: 0;
    color: #f8fafc;
    font-size: 1.08rem;
    font-weight: 700;
  }

  .payment-summary-minimal-price {
    display: flex;
    align-items: flex-start;
    justify-content: flex-end;
    min-width: max-content;
    padding-top: 0.15rem;
  }

  .payment-summary-minimal-total-wrap,
  .payment-summary-total-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    min-width: 0;
  }

  .payment-summary-minimal-total {
    color: #22d3ee;
    font-size: 1.35rem;
    font-weight: 800;
    line-height: 1;
    text-align: right;
    white-space: nowrap;
  }

  .payment-summary-minimal-user {
    margin-top: 0.8rem;
    color: #cbd5e1;
    font-size: 0.92rem;
  }

  .payment-summary-minimal-user strong {
    color: #f8fafc;
  }

  .game-hero-card {
    position: relative;
    min-height: clamp(210px, 27vw, 300px);
    border-radius: 1.75rem;
    overflow: hidden;
    border: 1px solid rgba(34, 211, 238, 0.42);
    background: linear-gradient(135deg, rgba(8, 15, 28, 0.96), rgba(5, 10, 22, 0.92));
    box-shadow: 0 28px 60px rgba(0, 0, 0, 0.36), inset 0 0 0 1px rgba(255, 255, 255, 0.04);
  }

  .game-hero-media,
  .game-hero-overlay,
  .game-hero-fallback {
    position: absolute;
    inset: 0;
  }

  .game-hero-media {
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(4, 10, 24, 0.92);
  }

  .game-hero-image-backdrop {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: blur(26px) saturate(1.08);
    transform: scale(1.12);
    opacity: 0.9;
  }

  .game-hero-image {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: center center;
    transform: none;
  }

  .game-hero-fallback {
    background: radial-gradient(circle at top, rgba(34, 211, 238, 0.2), transparent 45%), linear-gradient(135deg, rgba(15, 23, 42, 0.98), rgba(8, 47, 73, 0.92));
  }

  .game-hero-overlay {
    background:
      linear-gradient(180deg, rgba(4, 9, 19, 0.08) 0%, rgba(4, 9, 19, 0.18) 42%, rgba(4, 9, 19, 0.78) 100%),
      radial-gradient(circle at center, rgba(34, 211, 238, 0.14), transparent 56%);
  }

  .game-hero-content {
    position: relative;
    z-index: 2;
    min-height: inherit;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    justify-content: flex-end;
    gap: 0;
    padding: 3rem 0 0;
    text-align: center;
  }

  .game-hero-title-box {
    width: 100%;
    margin-top: auto;
    display: flex;
    justify-content: center;
    padding: 1rem 1.5rem 1.05rem;
    border-radius: 0;
    border: 0;
    border-top: 1px solid rgba(125, 211, 252, 0.34);
    background: linear-gradient(180deg, rgba(7, 14, 26, 0.18), rgba(7, 14, 26, 0.92) 38%, rgba(7, 14, 26, 0.98) 100%);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
  }

  .game-hero-title {
    margin: 0;
    color: #ffffff;
    font-size: clamp(1.35rem, 3vw, 2.2rem);
    font-weight: 900;
    line-height: 1.1;
    letter-spacing: 0.05em;
    text-shadow: 0 1px 0 rgba(4, 10, 24, 0.98), 0 8px 24px rgba(0, 0, 0, 0.44);
    -webkit-text-stroke: 0;
  }

  .game-hero-features {
    position: absolute;
    top: 1rem;
    left: 1rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-start;
    gap: 0.65rem;
    width: min(calc(100% - 7rem), 34rem);
  }

  .game-hero-features .game-feature-badge {
    border-color: rgba(34, 211, 238, 0.42);
    background: rgba(8, 15, 28, 0.58);
    color: #f8fdff;
    box-shadow: 0 10px 26px rgba(0, 0, 0, 0.22), inset 0 0 0 1px rgba(255, 255, 255, 0.04);
  }

  .game-hero-popular {
    position: absolute;
    top: 1rem;
    right: 1rem;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.8rem;
    border-radius: 999px;
    border: 1px solid rgba(250, 204, 21, 0.42);
    background: rgba(12, 18, 31, 0.72);
    color: #fde047;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.2);
  }

  .game-feature-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.34rem 0.7rem;
    border-radius: 999px;
    border: 1px solid var(--theme-game-feature-border, #164E63);
    background: var(--theme-game-feature-bg, #0E1722);
    color: var(--theme-game-feature-text, #22D3EE);
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1.1;
    box-shadow: inset 0 0 0 1px rgba(var(--theme-game-feature-border-rgb, 22, 78, 99), 0.14);
  }

  @media (max-width: 767.98px) {
    .game-hero-card {
      min-height: 0;
      aspect-ratio: auto;
      background: transparent;
    }

    .game-hero-media {
      position: relative;
      inset: auto;
      min-height: 180px;
      background: transparent;
    }

    .game-hero-image-backdrop {
      filter: blur(20px) saturate(1.02);
      transform: scale(1.08);
    }

    .game-hero-image {
      display: block;
      object-fit: contain;
      object-position: center center;
      height: auto;
      transform: none;
      background: transparent;
    }

    .game-hero-content {
      position: absolute;
      inset: 0;
      min-height: 0;
      padding: 0;
      justify-content: flex-end;
    }

    .game-hero-title-box {
      margin-top: 0;
      padding: 0.45rem 0.72rem 0.58rem;
      border-top: 0;
      background: linear-gradient(180deg, rgba(7, 14, 26, 0) 0%, rgba(7, 14, 26, 0.64) 40%, rgba(7, 14, 26, 0.92) 100%);
      box-shadow: none;
    }

    .game-hero-title {
      font-size: clamp(0.9rem, 3.8vw, 1.2rem);
      line-height: 1.06;
      letter-spacing: 0.02em;
      text-wrap: balance;
    }

    .game-hero-features {
      top: 0.55rem;
      left: 0.55rem;
      gap: 0.36rem;
      width: min(calc(100% - 5.1rem), 16rem);
      z-index: 3;
    }

    .game-feature-badge {
      padding: 0.24rem 0.54rem;
      font-size: 0.64rem;
      line-height: 1.02;
    }

    .game-hero-popular {
      top: 0.55rem;
      right: 0.55rem;
      padding: 0.28rem 0.52rem;
      font-size: 0.62rem;
      letter-spacing: 0.04em;
    }
  }

  .payment-summary-features {
    display: flex;
    flex-wrap: wrap;
    gap: 0.42rem;
    margin-top: 0.62rem;
  }

  .payment-summary-feature {
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
    padding: 0.34rem 0.64rem;
    border-radius: 999px;
    background: var(--theme-package-feature-bg, #0F172A);
    border: 1px solid var(--theme-package-feature-border, #164E63);
    color: var(--theme-package-feature-text, #D8FBFF);
    font-size: 0.76rem;
    font-weight: 600;
    line-height: 1.05;
  }

  .payment-summary-feature-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 0.82rem;
    min-height: 0.82rem;
    line-height: 1;
    color: var(--theme-package-feature-text, #D8FBFF);
  }

  .payment-summary-feature-icon svg {
    width: 100%;
    height: 100%;
  }

  @media (max-width: 575.98px) {
    .game-hero-card {
      min-height: 0;
      border-radius: 1.35rem;
    }

    .game-hero-title-box {
      padding: 0.38rem 0.56rem 0.5rem;
    }

    .game-hero-title {
      font-size: clamp(0.78rem, 3.7vw, 0.98rem);
      letter-spacing: 0.02em;
      -webkit-text-stroke: 0;
    }

    .game-hero-features {
      top: 0.5rem;
      left: 0.5rem;
      gap: 0.3rem;
      width: min(calc(100% - 4.6rem), 14rem);
    }

    .game-hero-popular {
      top: 0.5rem;
      right: 0.5rem;
      padding: 0.24rem 0.46rem;
      font-size: 0.58rem;
    }
  }

  @media (max-width: 480px) {
    .payment-summary-minimal {
      grid-template-columns: 68px minmax(0, 1fr);
      gap: 0.85rem;
    }

    .payment-summary-minimal-media {
      width: 68px;
      height: 68px;
    }

    .payment-summary-minimal-price {
      grid-column: 2;
      justify-content: flex-start;
      padding-top: 0;
    }

    .payment-summary-minimal-total {
      text-align: left;
      font-size: 1.22rem;
    }

    .payment-summary-minimal-total-wrap,
    .payment-summary-total-actions {
      gap: 0.35rem;
    }

    .payment-summary-copy-btn {
      width: 1.78rem;
      height: 1.78rem;
    }
  }

  .payment-summary-row {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.5rem;
    color: #cbd5e1;
  }

  .payment-summary-row strong {
    color: #f8fafc;
    text-align: right;
  }

  .payment-summary-total {
    margin-top: 0.8rem;
    padding-top: 0.8rem;
    border-top: 1px solid rgba(148, 163, 184, 0.18);
  }

  .payment-summary-total strong {
    color: #22d3ee;
    font-size: 1.2rem;
  }

  .payment-summary-copy-btn {
    position: relative;
    width: 1.95rem;
    height: 1.95rem;
    padding: 0;
    border-radius: 999px;
    border: 1px solid rgba(34, 211, 238, 0.36);
    background: rgba(8, 15, 24, 0.72);
    color: #7dd3fc;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.03);
    transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, color 0.18s ease;
  }

  .payment-summary-copy-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 0.88rem;
    height: 0.88rem;
  }

  .payment-summary-copy-icon svg {
    width: 100%;
    height: 100%;
    display: block;
  }

  .payment-summary-copy-btn::after {
    content: attr(data-copy-tooltip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 0.45rem);
    transform: translate(-50%, 4px);
    padding: 0.28rem 0.46rem;
    border-radius: 0.45rem;
    background: rgba(8, 15, 24, 0.96);
    border: 1px solid rgba(34, 211, 238, 0.28);
    color: #f8fafc;
    font-size: 0.66rem;
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    box-shadow: 0 10px 24px rgba(2, 6, 23, 0.28);
    transition: opacity 0.16s ease, transform 0.16s ease;
  }

  .payment-summary-copy-btn:hover:not(:disabled),
  .payment-summary-copy-btn:focus-visible:not(:disabled) {
    transform: translateY(-1px);
    border-color: rgba(34, 211, 238, 0.78);
    color: #ffffff;
    box-shadow: 0 0 14px rgba(34, 211, 238, 0.2);
    outline: none;
  }

  .payment-summary-copy-btn:hover:not(:disabled)::after,
  .payment-summary-copy-btn:focus-visible:not(:disabled)::after {
    opacity: 1;
    transform: translate(-50%, 0);
  }

  .payment-summary-copy-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    box-shadow: none;
  }

  .payment-summary-discount {
    margin-top: 0.85rem;
  }

  .payment-discount-panel {
    position: relative;
    overflow: hidden;
    padding: 1rem;
    border-radius: 1.05rem;
    border: 1px solid rgba(34, 211, 238, 0.24);
    background:
      radial-gradient(circle at top right, rgba(74, 222, 128, 0.2), transparent 34%),
      linear-gradient(135deg, rgba(6, 78, 59, 0.3), rgba(8, 47, 73, 0.42) 48%, rgba(15, 23, 42, 0.96));
    box-shadow: 0 18px 34px rgba(2, 6, 23, 0.34), inset 0 0 0 1px rgba(255, 255, 255, 0.04);
  }

  .payment-discount-panel::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 1px;
    background: linear-gradient(90deg, rgba(34, 211, 238, 0), rgba(34, 211, 238, 0.9), rgba(74, 222, 128, 0));
  }

  .payment-discount-panel-method {
    background:
      radial-gradient(circle at top right, rgba(34, 211, 238, 0.16), transparent 34%),
      linear-gradient(135deg, rgba(8, 47, 73, 0.52), rgba(15, 23, 42, 0.95));
  }

  .payment-discount-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 0.85rem;
  }

  .payment-discount-badge,
  .payment-discount-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 0.35rem 0.8rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
  }

  .payment-discount-badge {
    border: 1px solid rgba(34, 211, 238, 0.34);
    background: rgba(8, 47, 73, 0.55);
    color: #cffafe;
  }

  .payment-discount-chip {
    border: 1px solid rgba(74, 222, 128, 0.4);
    background: rgba(20, 83, 45, 0.58);
    color: #dcfce7;
    box-shadow: 0 0 18px rgba(74, 222, 128, 0.18);
  }

  .payment-discount-chip.is-tax {
    border-color: rgba(251, 191, 36, 0.45);
    background: rgba(120, 53, 15, 0.5);
    color: #fef3c7;
    box-shadow: 0 0 18px rgba(251, 191, 36, 0.16);
  }

  .payment-discount-panel-title {
    color: #f8fafc;
    font-weight: 800;
    font-size: 1rem;
    line-height: 1.35;
  }

  .payment-discount-panel-copy {
    margin-top: 0.4rem;
    color: #dbeafe;
    font-size: 0.92rem;
    line-height: 1.6;
  }

  .payment-discount-grid {
    margin-top: 0.9rem;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.65rem;
  }

  .payment-discount-stat {
    padding: 0.78rem 0.82rem;
    border-radius: 0.9rem;
    border: 1px solid rgba(148, 163, 184, 0.18);
    background: rgba(15, 23, 42, 0.44);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02);
  }

  .payment-discount-stat span {
    display: block;
    color: #94a3b8;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .payment-discount-stat strong {
    display: block;
    margin-top: 0.3rem;
    color: #f8fafc;
    font-size: 0.98rem;
    line-height: 1.25;
  }

  .payment-discount-stat-highlight {
    border-color: rgba(74, 222, 128, 0.35);
    background: linear-gradient(135deg, rgba(20, 83, 45, 0.48), rgba(6, 78, 59, 0.18));
  }

  .payment-discount-stat-highlight strong {
    color: #86efac;
  }

  .payment-discount-stat-warning strong {
    color: #fbbf24;
  }

  @media (max-width: 575.98px) {
    .payment-discount-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  .payment-difference-banner {
    padding: 0.95rem 1rem;
    border-radius: 1rem;
    border: 1px solid rgba(45, 212, 191, 0.32);
    background: linear-gradient(135deg, rgba(8, 47, 73, 0.38), rgba(15, 23, 42, 0.92));
    box-shadow: inset 0 0 0 1px rgba(34, 211, 238, 0.05);
  }

  .payment-difference-banner[data-variant="warning"] {
    border-color: rgba(251, 191, 36, 0.34);
    background: linear-gradient(135deg, rgba(120, 53, 15, 0.34), rgba(15, 23, 42, 0.94));
  }

  .payment-difference-banner-title {
    color: #f8fafc;
    font-weight: 700;
    margin-bottom: 0.25rem;
  }

  .payment-difference-banner-copy {
    color: #cbd5e1;
    line-height: 1.5;
    font-size: 0.92rem;
  }

  .payment-difference-breakdown {
    margin-top: 0.6rem;
    display: grid;
    gap: 0.25rem;
    color: #e2e8f0;
    font-size: 0.85rem;
  }

  .payment-difference-breakdown strong {
    color: #f8fafc;
  }

  .payment-difference-actions {
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  }

  .payment-method-details {
    white-space: pre-line;
    line-height: 1.7;
  }

  .payment-method-details.payment-method-details-rich {
    white-space: normal;
  }

  .payment-method-details.payment-method-details-rich p:last-child {
    margin-bottom: 0;
  }

  .payment-method-details.payment-method-details-rich ul {
    margin: 0.75rem 0 0;
    padding-left: 1.15rem;
  }

  .payment-method-details.payment-method-details-rich li + li {
    margin-top: 0.35rem;
  }

  .payment-transfer-copy-list {
    display: grid;
    gap: 0.7rem;
  }

  .payment-transfer-copy-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.7rem;
    padding: 0.78rem 0.85rem;
    border-radius: 0.95rem;
    border: 1px solid rgba(34, 211, 238, 0.16);
    background: linear-gradient(180deg, rgba(8, 15, 24, 0.92), rgba(15, 23, 42, 0.88));
  }

  .payment-transfer-copy-line {
    min-width: 0;
    color: #e2e8f0;
    font-size: 0.92rem;
    font-weight: 600;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
  }

  .payment-transfer-copy-btn,
  .payment-transfer-copy-all-btn {
    white-space: nowrap;
  }

  .payment-transfer-copy-btn {
    justify-self: end;
  }

  .payment-transfer-copy-actions {
    margin-top: 0.85rem;
  }

  .payment-transfer-copy-note {
    margin-top: 0.8rem;
    color: #cbd5e1;
  }

  .payment-method-qr-wrap {
    margin-top: 0.95rem;
    padding: 0.9rem;
    border-radius: 1rem;
    border: 1px solid rgba(34, 211, 238, 0.16);
    background: linear-gradient(180deg, rgba(8, 15, 24, 0.92), rgba(15, 23, 42, 0.88));
    text-align: center;
  }

  .payment-method-qr-label {
    margin-bottom: 0.65rem;
    color: #cbd5e1;
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
  }

  .payment-method-qr-image {
    display: block;
    width: min(100%, 220px);
    margin: 0 auto;
    border-radius: 0.9rem;
    border: 1px solid rgba(255,255,255,0.08);
    background: #fff;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.24);
  }

  .payment-method-discount {
    margin-top: 0.95rem;
  }

  .payment-modal-content .form-control::placeholder {
    color: rgba(148, 163, 184, 0.7) !important;
  }

  .payment-reasons-card {
    padding: 0.95rem 1rem;
    border-radius: 1rem;
    border: 1px solid rgba(248, 113, 113, 0.35);
    background: rgba(127, 29, 29, 0.12);
  }

  .payment-reasons-title {
    color: #f8fafc;
    font-weight: 700;
    margin-bottom: 0.45rem;
  }

  .payment-reasons-summary {
    color: #e2e8f0;
    margin-bottom: 0.75rem;
    line-height: 1.55;
  }

  .payment-reasons-steps {
    margin: 0;
    padding-left: 1.15rem;
    color: #e2e8f0;
  }

  .payment-reasons-steps li + li {
    margin-top: 0.4rem;
  }

  .payment-reasons-caption {
    margin-top: 0.85rem;
    color: #fecaca;
    font-size: 0.92rem;
    font-weight: 700;
  }

  .payment-reasons-card ul {
    margin: 0.65rem 0 0;
    padding-left: 1.15rem;
    color: #fecaca;
  }

  .payment-support-actions {
    display: grid;
    gap: 0.75rem;
  }

  .payment-support-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 3rem;
    padding: 0.8rem 1rem;
    border-radius: 999px;
    border: 1px solid rgba(45, 212, 191, 0.65);
    background: linear-gradient(135deg, rgba(6, 78, 59, 0.9), rgba(16, 185, 129, 0.82));
    color: #f0fdf4;
    text-decoration: none;
    font-weight: 700;
    box-shadow: 0 0 18px rgba(16, 185, 129, 0.18);
  }

  .payment-support-link:hover {
    color: #ffffff;
    box-shadow: 0 0 22px rgba(16, 185, 129, 0.28);
  }

  #payment-modal.payment-main-modal-theme-enabled {
    background: rgba(var(--theme-payment-main-overlay-bg-rgb, 5, 10, 20), 0.78);
  }

  .payment-modal-content.payment-modal-skin-enabled {
    color: var(--theme-payment-main-text, #CBD5E1);
    background: linear-gradient(180deg, rgba(var(--theme-payment-main-modal-bg-rgb, 17, 24, 39), 0.98), rgba(var(--theme-payment-main-modal-bg-rgb, 17, 24, 39), 0.94));
    border: 1px solid rgba(var(--theme-payment-main-modal-border-rgb, 34, 211, 238), 0.56);
    box-shadow: 0 0 28px rgba(var(--theme-payment-main-modal-border-rgb, 34, 211, 238), 0.16);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-expiration-banner {
    border-color: rgba(var(--theme-payment-main-timer-border-rgb, 248, 113, 113), 0.5);
    background: rgba(var(--theme-payment-main-timer-bg-rgb, 127, 29, 29), 0.2);
    color: var(--theme-payment-main-timer-text, #F87171);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-summary-card,
  .payment-modal-content.payment-modal-skin-enabled .payment-method-card,
  .payment-modal-content.payment-modal-skin-enabled .payment-win-points-card,
  .payment-modal-content.payment-modal-skin-enabled .payment-mode-item,
  .payment-modal-content.payment-modal-skin-enabled .payment-mode-item-card,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card {
    background: rgba(var(--theme-payment-main-card-bg-rgb, 8, 15, 24), 0.82);
    border-color: rgba(var(--theme-payment-main-card-border-rgb, 22, 78, 99), 0.46);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-summary-card-title,
  .payment-modal-content.payment-modal-skin-enabled .payment-summary-minimal-title,
  .payment-modal-content.payment-modal-skin-enabled .payment-summary-row strong,
  .payment-modal-content.payment-modal-skin-enabled .payment-summary-minimal-user strong,
  .payment-modal-content.payment-modal-skin-enabled #payment-method-title,
  .payment-modal-content.payment-modal-skin-enabled .payment-win-points-title,
  .payment-modal-content.payment-modal-skin-enabled .payment-mode-item-card-title,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-title,
  .payment-modal-content.payment-modal-skin-enabled .form-label {
    color: var(--theme-payment-main-title, #F8FAFC) !important;
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-summary-row,
  .payment-modal-content.payment-modal-skin-enabled .payment-summary-minimal-user,
  .payment-modal-content.payment-modal-skin-enabled .payment-method-details,
  .payment-modal-content.payment-modal-skin-enabled .payment-win-points-copy,
  .payment-modal-content.payment-modal-skin-enabled .payment-mode-item-currency,
  .payment-modal-content.payment-modal-skin-enabled .payment-mode-item-details,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-summary,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-steps,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card ul,
  .payment-modal-content.payment-modal-skin-enabled .form-text {
    color: var(--theme-payment-main-text, #CBD5E1) !important;
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-summary-total {
    border-top-color: rgba(var(--theme-payment-main-card-border-rgb, 22, 78, 99), 0.3);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-discount-panel {
    border-color: rgba(var(--theme-payment-main-card-border-rgb, 22, 78, 99), 0.56);
    background:
      radial-gradient(circle at top right, rgba(var(--theme-payment-main-card-border-rgb, 22, 78, 99), 0.24), transparent 36%),
      linear-gradient(135deg, rgba(var(--theme-payment-main-card-border-rgb, 22, 78, 99), 0.2), var(--theme-payment-main-card-bg, #111827));
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-discount-badge {
    border-color: rgba(var(--theme-payment-main-card-border-rgb, 22, 78, 99), 0.5);
    color: var(--theme-payment-main-title, #F8FAFC);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-discount-chip {
    color: var(--theme-payment-main-title, #F8FAFC);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-discount-panel-title,
  .payment-modal-content.payment-modal-skin-enabled .payment-discount-stat strong {
    color: var(--theme-payment-main-title, #F8FAFC);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-discount-panel-copy,
  .payment-modal-content.payment-modal-skin-enabled .payment-discount-stat span {
    color: var(--theme-payment-main-text, #CBD5E1);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-summary-total strong,
  .payment-modal-content.payment-modal-skin-enabled .payment-summary-minimal-total,
  .payment-modal-content.payment-modal-skin-enabled .payment-method-currency,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-caption {
    color: var(--theme-payment-main-title, #F8FAFC) !important;
  }

  .payment-modal-content.payment-modal-skin-enabled .form-control,
  .payment-modal-content.payment-modal-skin-enabled .form-select {
    background: var(--theme-payment-main-input-bg, #111827) !important;
    border-color: var(--theme-payment-main-input-border, #22D3EE) !important;
    color: var(--theme-payment-main-input-text, #22D3EE) !important;
  }

  .payment-modal-content.payment-modal-skin-enabled .form-control::placeholder {
    color: rgba(var(--theme-payment-main-input-text-rgb, 34, 211, 238), 0.65) !important;
  }

  .payment-submit-btn-theme.payment-window-theme-enabled {
    background: var(--theme-payment-main-button-bg, #22D3EE) !important;
    border-color: rgba(var(--theme-payment-main-button-bg-rgb, 34, 211, 238), 0.88) !important;
    color: var(--theme-payment-main-button-text, #081018) !important;
  }

  .payment-cancel-btn-theme.payment-window-theme-enabled {
    background: var(--theme-payment-main-cancel-bg, #F87171) !important;
    border-color: rgba(var(--theme-payment-main-cancel-bg-rgb, 248, 113, 113), 0.88) !important;
    color: var(--theme-payment-main-cancel-text, #F8FAFC) !important;
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-support-link {
    border-color: rgba(var(--theme-payment-main-button-bg-rgb, 34, 211, 238), 0.65);
    background: linear-gradient(135deg, rgba(var(--theme-payment-main-button-bg-rgb, 34, 211, 238), 0.88), rgba(var(--theme-payment-main-button-bg-rgb, 34, 211, 238), 0.72));
    color: var(--theme-payment-main-button-text, #081018);
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="processing"] {
    background: rgba(var(--theme-payment-processing-overlay-bg-rgb, 5, 10, 20), 0.78);
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="sending"] {
    background: rgba(var(--theme-payment-sending-overlay-bg-rgb, 5, 10, 20), 0.78);
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="processing"] .payment-loading-modal-content {
    background: linear-gradient(180deg, rgba(var(--theme-payment-processing-modal-bg-rgb, 17, 24, 39), 0.98), rgba(var(--theme-payment-processing-modal-bg-rgb, 17, 24, 39), 0.94));
    border: 1px solid rgba(var(--theme-payment-processing-modal-border-rgb, 34, 211, 238), 0.56);
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="processing"] #loading-modal-spinner-circle {
    stroke: var(--theme-payment-processing-spinner, #34D399);
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="processing"] .payment-loading-modal-title {
    color: var(--theme-payment-processing-title, #22D3EE) !important;
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="processing"] .payment-loading-modal-message {
    color: var(--theme-payment-processing-text, #F8FAFC) !important;
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="sending"] .payment-loading-modal-content {
    background: linear-gradient(180deg, rgba(var(--theme-payment-sending-modal-bg-rgb, 17, 24, 39), 0.98), rgba(var(--theme-payment-sending-modal-bg-rgb, 17, 24, 39), 0.94));
    border: 1px solid rgba(var(--theme-payment-sending-modal-border-rgb, 34, 211, 238), 0.56);
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="sending"] #loading-modal-spinner-circle {
    stroke: var(--theme-payment-sending-spinner, #22D3EE);
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="sending"] .payment-loading-modal-title {
    color: var(--theme-payment-sending-title, #22D3EE) !important;
  }

  #loading-modal.payment-window-theme-enabled[data-payment-loading-state="sending"] .payment-loading-modal-message {
    color: var(--theme-payment-sending-text, #F8FAFC) !important;
  }

  #payment-status-modal.payment-window-theme-enabled {
    background: rgba(var(--theme-payment-status-overlay-bg-rgb, 5, 10, 20), 0.78);
  }

  #payment-status-modal.payment-window-theme-enabled .payment-status-modal-content {
    background: linear-gradient(180deg, rgba(var(--theme-payment-status-modal-bg-rgb, 17, 24, 39), 0.98), rgba(var(--theme-payment-status-modal-bg-rgb, 17, 24, 39), 0.94));
    border: 1px solid rgba(var(--theme-payment-status-modal-border-rgb, 34, 211, 238), 0.56);
  }

  #payment-status-modal.payment-window-theme-enabled .payment-status-modal-message {
    color: var(--theme-payment-status-text, #F8FAFC) !important;
  }

  #payment-status-modal.payment-window-theme-enabled[data-payment-status-state="info"] .payment-status-modal-title {
    color: var(--theme-payment-status-title-info, #22D3EE) !important;
  }

  #payment-status-modal.payment-window-theme-enabled[data-payment-status-state="success"] .payment-status-modal-title {
    color: var(--theme-payment-status-title-success, #34D399) !important;
  }

  #payment-status-modal.payment-window-theme-enabled[data-payment-status-state="danger"] .payment-status-modal-title {
    color: var(--theme-payment-status-title-danger, #F87171) !important;
  }

  .payment-status-modal-accept-btn.payment-window-theme-enabled {
    background: var(--theme-payment-status-button-bg, #22D3EE) !important;
    border-color: rgba(var(--theme-payment-status-button-bg-rgb, 34, 211, 238), 0.88) !important;
    color: var(--theme-payment-status-button-text, #081018) !important;
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"],
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] {
    background: linear-gradient(180deg, rgba(var(--theme-payment-difference-underpaid-card-bg-rgb, 120, 53, 15), 0.34), rgba(var(--theme-payment-main-card-bg-rgb, 8, 15, 24), 0.92));
    border-color: rgba(var(--theme-payment-difference-underpaid-card-bg-rgb, 120, 53, 15), 0.78);
    box-shadow: inset 0 0 0 1px rgba(var(--theme-payment-difference-underpaid-card-bg-rgb, 120, 53, 15), 0.12);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] .payment-reasons-title,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] .payment-reasons-summary,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] .payment-reasons-steps,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] .payment-reasons-caption,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] ul,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] .payment-reasons-title,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] .payment-reasons-summary,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] .payment-reasons-steps,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] .payment-reasons-caption,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="underpaid"] ul {
    color: var(--theme-payment-difference-underpaid-text, #FDE68A) !important;
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"],
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] {
    background: linear-gradient(180deg, rgba(var(--theme-payment-difference-overpaid-card-bg-rgb, 6, 78, 59), 0.34), rgba(var(--theme-payment-main-card-bg-rgb, 8, 15, 24), 0.92));
    border-color: rgba(var(--theme-payment-difference-overpaid-card-bg-rgb, 6, 78, 59), 0.78);
    box-shadow: inset 0 0 0 1px rgba(var(--theme-payment-difference-overpaid-card-bg-rgb, 6, 78, 59), 0.12);
  }

  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] .payment-reasons-title,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] .payment-reasons-summary,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] .payment-reasons-steps,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] .payment-reasons-caption,
  .payment-modal-content.payment-modal-skin-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] ul,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] .payment-reasons-title,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] .payment-reasons-summary,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] .payment-reasons-steps,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] .payment-reasons-caption,
  #payment-status-modal.payment-window-theme-enabled .payment-reasons-card[data-payment-difference-variant="overpaid"] ul {
    color: var(--theme-payment-difference-overpaid-text, #D1FAE5) !important;
  }

  .payment-difference-action-btn {
    transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
  }

  .payment-window-theme-enabled .payment-difference-actions[data-payment-difference-variant="underpaid"] .payment-difference-action-btn {
    background: var(--theme-payment-difference-underpaid-button-bg, #F59E0B) !important;
    border-color: rgba(var(--theme-payment-difference-underpaid-button-bg-rgb, 245, 158, 11), 0.88) !important;
    color: var(--theme-payment-difference-underpaid-button-text, #111827) !important;
    box-shadow: 0 0 18px rgba(var(--theme-payment-difference-underpaid-button-bg-rgb, 245, 158, 11), 0.22);
  }

  .payment-window-theme-enabled .payment-difference-actions[data-payment-difference-variant="underpaid"] .payment-difference-action-btn:hover,
  .payment-window-theme-enabled .payment-difference-actions[data-payment-difference-variant="underpaid"] .payment-difference-action-btn:focus {
    background: var(--theme-payment-difference-underpaid-button-hover-bg, #FBBF24) !important;
    border-color: rgba(var(--theme-payment-difference-underpaid-button-hover-bg-rgb, 251, 191, 36), 0.92) !important;
    color: var(--theme-payment-difference-underpaid-button-hover-text, #111827) !important;
  }

  .payment-window-theme-enabled .payment-difference-actions[data-payment-difference-variant="overpaid"] .payment-difference-action-btn {
    background: var(--theme-payment-difference-overpaid-button-bg, #10B981) !important;
    border-color: rgba(var(--theme-payment-difference-overpaid-button-bg-rgb, 16, 185, 129), 0.88) !important;
    color: var(--theme-payment-difference-overpaid-button-text, #052E16) !important;
    box-shadow: 0 0 18px rgba(var(--theme-payment-difference-overpaid-button-bg-rgb, 16, 185, 129), 0.22);
  }

  .payment-window-theme-enabled .payment-difference-actions[data-payment-difference-variant="overpaid"] .payment-difference-action-btn:hover,
  .payment-window-theme-enabled .payment-difference-actions[data-payment-difference-variant="overpaid"] .payment-difference-action-btn:focus {
    background: var(--theme-payment-difference-overpaid-button-hover-bg, #34D399) !important;
    border-color: rgba(var(--theme-payment-difference-overpaid-button-hover-bg-rgb, 52, 211, 153), 0.92) !important;
    color: var(--theme-payment-difference-overpaid-button-hover-text, #022C22) !important;
  }

  .payment-confirm-overlay {
    z-index: 1115;
    background: rgba(5, 10, 20, 0.38);
    backdrop-filter: blur(2px);
  }

  .win-points-guest-hint {
    margin-top: 0.85rem;
    padding: 0.9rem 1rem;
    border-radius: 0.95rem;
    border: 1px solid rgba(250, 204, 21, 0.42);
    background: linear-gradient(135deg, rgba(120, 53, 15, 0.34), rgba(146, 64, 14, 0.18));
    color: #fde68a;
    font-weight: 700;
    font-size: 0.94rem;
    text-align: center;
    box-shadow: 0 0 18px rgba(251, 191, 36, 0.12);
  }

  .payment-win-points-card {
    margin-bottom: 1rem;
    padding: 1rem;
    border-radius: 1rem;
    border: 1px solid rgba(45, 212, 191, 0.25);
    background: linear-gradient(180deg, rgba(9, 24, 34, 0.95), rgba(8, 18, 28, 0.92));
    box-shadow: inset 0 0 0 1px rgba(34, 211, 238, 0.06);
  }

  .payment-win-points-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.9rem;
    margin-bottom: 0.9rem;
  }

  .payment-win-points-title {
    color: #f8fafc;
    font-weight: 700;
    margin-bottom: 0.2rem;
  }

  .payment-win-points-copy {
    color: #cbd5e1;
    font-size: 0.9rem;
    line-height: 1.45;
  }

  .payment-win-points-balance {
    padding: 0.55rem 0.8rem;
    border-radius: 999px;
    border: 1px solid rgba(34, 197, 94, 0.42);
    background: rgba(6, 78, 59, 0.32);
    color: #86efac;
    font-weight: 700;
    white-space: nowrap;
  }

  .payment-win-points-actions {
    display: grid;
    gap: 0.65rem;
    grid-template-columns: 1fr;
  }

  .payment-mode-item {
    border-radius: 1rem;
    border: 1px solid rgba(56, 189, 248, 0.18);
    background: rgba(15, 23, 42, 0.48);
    box-shadow: inset 0 0 0 1px rgba(34, 211, 238, 0.04);
    overflow: hidden;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
  }

  .payment-mode-item.is-selected {
    border-color: rgba(34, 211, 238, 0.68);
    background: linear-gradient(180deg, rgba(8, 47, 73, 0.54), rgba(15, 23, 42, 0.9));
    box-shadow: 0 0 20px rgba(34, 211, 238, 0.12);
  }

  .payment-mode-btn {
    width: 100%;
    min-height: 3.65rem;
    padding: 0.95rem 1rem;
    border: 0;
    background: transparent;
    color: #cbd5e1;
    font-weight: 700;
    text-align: left;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.9rem;
    transition: color 0.2s ease;
  }

  .payment-mode-item.is-selected .payment-mode-btn {
    color: #ecfeff;
  }

  .payment-mode-btn-main {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    min-width: 0;
    flex: 1 1 auto;
  }

  .payment-mode-btn-radio {
    width: 1.15rem;
    height: 1.15rem;
    border-radius: 999px;
    border: 2px solid rgba(148, 163, 184, 0.65);
    background: rgba(15, 23, 42, 0.85);
    flex: 0 0 auto;
    position: relative;
    transition: border-color 0.2s ease, background 0.2s ease;
  }

  .payment-mode-btn-radio::after {
    content: '';
    position: absolute;
    inset: 0.18rem;
    border-radius: 999px;
    background: #22d3ee;
    transform: scale(0);
    transition: transform 0.18s ease;
  }

  .payment-mode-item.is-selected .payment-mode-btn-radio {
    border-color: rgba(34, 211, 238, 0.92);
  }

  .payment-mode-item.is-selected .payment-mode-btn-radio::after {
    transform: scale(1);
  }

  .payment-mode-btn-text {
    display: grid;
    gap: 0.14rem;
    min-width: 0;
  }

  .payment-mode-btn-title {
    font-size: 1rem;
    line-height: 1.2;
  }

  .payment-mode-btn-meta {
    color: #93c5fd;
    font-size: 0.84rem;
    font-weight: 500;
    line-height: 1.3;
  }

  .payment-mode-btn-caret {
    width: 0.72rem;
    height: 0.72rem;
    border-right: 2px solid currentColor;
    border-bottom: 2px solid currentColor;
    transform: rotate(45deg);
    transition: transform 0.22s ease;
    opacity: 0.82;
    flex: 0 0 auto;
    margin-right: 0.15rem;
  }

  .payment-mode-item.is-expanded .payment-mode-btn-caret {
    transform: rotate(-135deg);
  }

  .payment-mode-item-body {
    display: grid;
    grid-template-rows: 0fr;
    transition: grid-template-rows 0.24s ease;
  }

  .payment-mode-item.is-expanded .payment-mode-item-body {
    grid-template-rows: 1fr;
  }

  .payment-mode-item-body-inner {
    overflow: hidden;
    padding: 0 1rem;
    opacity: 0;
    transform: translateY(-6px);
    transition: padding 0.24s ease, opacity 0.2s ease, transform 0.2s ease;
  }

  .payment-mode-item.is-expanded .payment-mode-item-body-inner {
    padding: 0 1rem 1rem;
    opacity: 1;
    transform: translateY(0);
  }

  .payment-mode-item-card {
    padding: 0.95rem 1rem;
    border-radius: 0.95rem;
    border: 1px solid rgba(56, 189, 248, 0.18);
    background: rgba(8, 20, 36, 0.88);
    box-shadow: inset 0 0 0 1px rgba(34, 211, 238, 0.05);
  }

  .payment-mode-item-card.payment-mode-item-card-points {
    border-color: rgba(34, 197, 94, 0.2);
    background: linear-gradient(180deg, rgba(6, 36, 27, 0.92), rgba(8, 20, 36, 0.92));
  }

  .payment-mode-item-card-title {
    color: #f8fafc;
    font-weight: 700;
    margin-bottom: 0.4rem;
  }

  .payment-mode-item-currency {
    color: #22d3ee;
    font-size: 0.92rem;
    font-weight: 600;
    margin-bottom: 0.6rem;
  }

  .payment-mode-item-details {
    color: #e2e8f0;
    font-size: 0.92rem;
    line-height: 1.55;
  }

  @media (max-width: 575.98px) {
    .payment-transfer-copy-row {
      grid-template-columns: 1fr;
      align-items: stretch;
      gap: 0.55rem;
      padding: 0.72rem 0.75rem;
    }

    .payment-transfer-copy-all-btn {
      width: 100%;
    }

    .payment-transfer-copy-btn {
      justify-self: end;
    }

    .payment-transfer-copy-line {
      font-size: 0.88rem;
      line-height: 1.42;
    }
  }

  .payment-mode-btn:disabled {
    cursor: not-allowed;
    opacity: 0.58;
  }

  .payment-win-points-message {
    margin-top: 0.85rem;
    color: #93c5fd;
    font-size: 0.92rem;
    line-height: 1.45;
  }

  .payment-mode-panels {
    display: grid;
    gap: 0.9rem;
  }

  .payment-mode-panel {
    display: grid;
    grid-template-rows: 0fr;
    opacity: 0;
    transform: translateY(12px);
    transition: grid-template-rows 0.28s ease, opacity 0.24s ease, transform 0.24s ease;
    pointer-events: none;
  }

  .payment-mode-panel.is-active {
    grid-template-rows: 1fr;
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
  }

  .payment-mode-panel-inner {
    overflow: hidden;
  }

  .payment-points-card {
    padding: 1rem;
    border-radius: 1rem;
    border: 1px solid rgba(56, 189, 248, 0.22);
    background: linear-gradient(180deg, rgba(8, 20, 36, 0.94), rgba(11, 30, 48, 0.9));
    box-shadow: inset 0 0 0 1px rgba(34, 211, 238, 0.05);
  }

  .pack-win-points-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    width: fit-content;
    max-width: 100%;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    border: 1px solid <?= htmlspecialchars($winPointsBadgeBorderColor, ENT_QUOTES, 'UTF-8') ?>;
    background: <?= htmlspecialchars($winPointsBadgeBackgroundColor, ENT_QUOTES, 'UTF-8') ?>;
    color: <?= htmlspecialchars($winPointsBadgeTextColor, ENT_QUOTES, 'UTF-8') ?>;
    font-size: 0.82rem;
    font-weight: 700;
    line-height: 1.15;
    box-shadow: inset 0 0 0 1px <?= htmlspecialchars($winPointsBadgeInsetColor, ENT_QUOTES, 'UTF-8') ?>;
  }

  .pack-win-points-icon {
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    object-fit: cover;
    flex: 0 0 auto;
  }

  .win-points-live-notification {
    position: fixed;
    width: min(360px, calc(100vw - 1.5rem));
    display: grid;
    grid-template-columns: auto auto 1fr;
    align-items: center;
    gap: 0.85rem;
    padding: 0.8rem 0.95rem;
    border-radius: 18px;
    border: 1px solid rgba(var(--theme-live-notification-border-rgb), 0.72);
    background: linear-gradient(135deg, rgba(var(--theme-live-notification-bg-rgb), 0.98), rgba(var(--theme-live-notification-border-rgb), 0.16));
    box-shadow: 0 18px 45px rgba(2, 6, 23, 0.42), 0 0 18px rgba(var(--theme-live-notification-border-rgb), 0.16);
    backdrop-filter: blur(14px);
    opacity: 0;
    transition: opacity 0.28s ease, transform 0.28s ease;
    z-index: 9999;
    pointer-events: none;
  }

  .win-points-live-notification.is-visible {
    opacity: 1;
  }

  .win-points-live-notification[data-position="bottom-left"] {
    left: 24px;
    bottom: 24px;
    transform: translate3d(0, 18px, 0);
  }

  .win-points-live-notification[data-position="bottom-center"] {
    left: 50%;
    bottom: 24px;
    transform: translate3d(-50%, 18px, 0);
  }

  .win-points-live-notification[data-position="bottom-right"] {
    right: 24px;
    bottom: 24px;
    transform: translate3d(0, 18px, 0);
  }

  .win-points-live-notification[data-position="top-left"] {
    left: 24px;
    top: 24px;
    transform: translate3d(0, -18px, 0);
  }

  .win-points-live-notification[data-position="top-center"] {
    left: 50%;
    top: 24px;
    transform: translate3d(-50%, -18px, 0);
  }

  .win-points-live-notification[data-position="top-right"] {
    right: 24px;
    top: 24px;
    transform: translate3d(0, -18px, 0);
  }

  .win-points-live-notification[data-position="middle-right"] {
    right: 24px;
    top: 50%;
    transform: translate3d(18px, -50%, 0);
  }

  .win-points-live-notification[data-position="middle-left"] {
    left: 24px;
    top: 50%;
    transform: translate3d(-18px, -50%, 0);
  }

  .win-points-live-notification.is-visible[data-position="bottom-left"],
  .win-points-live-notification.is-visible[data-position="bottom-right"],
  .win-points-live-notification.is-visible[data-position="top-left"],
  .win-points-live-notification.is-visible[data-position="top-right"] {
    transform: translate3d(0, 0, 0);
  }

  .win-points-live-notification.is-visible[data-position="bottom-center"],
  .win-points-live-notification.is-visible[data-position="top-center"] {
    transform: translate3d(-50%, 0, 0);
  }

  .win-points-live-notification.is-visible[data-position="middle-right"] {
    transform: translate3d(0, -50%, 0);
  }

  .win-points-live-notification.is-visible[data-position="middle-left"] {
    transform: translate3d(0, -50%, 0);
  }

  .win-points-live-notification__pulse {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: var(--theme-live-notification-accent);
    box-shadow: 0 0 0 0 rgba(var(--theme-live-notification-accent-rgb), 0.56);
    animation: win-points-live-pulse 1.9s ease-out infinite;
  }

  .win-points-live-notification__logo-wrap {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(var(--theme-live-notification-border-rgb), 0.34);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .win-points-live-notification__logo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .win-points-live-notification__logo-fallback {
    color: var(--theme-live-notification-text);
    font-weight: 800;
    letter-spacing: 0.05em;
    font-size: 0.82rem;
  }

  .win-points-live-notification__body {
    min-width: 0;
  }

  .win-points-live-notification__title {
    color: var(--theme-live-notification-text);
    font-size: 0.9rem;
    font-weight: 800;
    line-height: 1.2;
    margin-bottom: 0.12rem;
    letter-spacing: 0.01em;
  }

  .win-points-live-notification__detail {
    color: var(--theme-live-notification-muted);
    font-size: 0.78rem;
    line-height: 1.35;
  }

  @keyframes win-points-live-pulse {
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
    .app-overlay-modal {
      align-items: flex-start;
      padding: 0.55rem 0.55rem calc(1rem + env(safe-area-inset-bottom));
    }

    .app-overlay-modal .modal-dialog,
    .payment-modal-dialog {
      width: min(100%, 34rem) !important;
      margin: 0 auto;
    }

    .payment-modal-dialog {
      display: flex;
      align-items: flex-start;
      min-height: calc(100dvh - 1.1rem);
    }

    .payment-modal-content {
      padding: 1rem;
      width: 100%;
      max-height: none;
      overflow: visible;
      overscroll-behavior: auto;
      padding-bottom: calc(1.25rem + env(safe-area-inset-bottom));
      border-radius: 1.1rem;
    }

    .payment-expiration-banner {
      font-size: 0.92rem;
    }

    .payment-win-points-header,
    .payment-win-points-actions {
      grid-template-columns: 1fr;
      display: grid;
    }

    .payment-mode-panel {
      transform: translateY(8px);
    }

    .payment-win-points-balance {
      white-space: normal;
    }

    .win-points-live-notification {
      width: min(312px, calc(100vw - 72px));
      max-width: calc(100vw - 24px);
      gap: 0.62rem;
      padding: 0.64rem 0.75rem;
      border-radius: 16px;
    }

    .win-points-live-notification[data-position="bottom-left"],
    .win-points-live-notification[data-position="top-left"],
    .win-points-live-notification[data-position="middle-left"] {
      left: 12px;
      right: auto;
    }

    .win-points-live-notification[data-position="bottom-right"],
    .win-points-live-notification[data-position="top-right"],
    .win-points-live-notification[data-position="middle-right"] {
      left: auto;
      right: 12px;
    }

    .win-points-live-notification[data-position="bottom-center"] {
      left: 50%;
      right: auto;
      bottom: calc(0.75rem + env(safe-area-inset-bottom));
      transform: translate3d(-50%, 18px, 0);
    }

    .win-points-live-notification[data-position="bottom-left"],
    .win-points-live-notification[data-position="bottom-right"] {
      bottom: calc(0.75rem + env(safe-area-inset-bottom));
      transform: translate3d(0, 18px, 0);
    }

    .win-points-live-notification.is-visible[data-position="bottom-left"],
    .win-points-live-notification.is-visible[data-position="bottom-right"] {
      transform: translate3d(0, 0, 0);
    }

    .win-points-live-notification.is-visible[data-position="bottom-center"] {
      transform: translate3d(-50%, 0, 0);
    }

    .win-points-live-notification[data-position="top-center"] {
      left: 50%;
      right: auto;
      top: calc(0.75rem + env(safe-area-inset-top));
      transform: translate3d(-50%, -18px, 0);
    }

    .win-points-live-notification[data-position="top-left"],
    .win-points-live-notification[data-position="top-right"] {
      top: calc(0.75rem + env(safe-area-inset-top));
      transform: translate3d(0, -18px, 0);
    }

    .win-points-live-notification.is-visible[data-position="top-left"],
    .win-points-live-notification.is-visible[data-position="top-right"] {
      transform: translate3d(0, 0, 0);
    }

    .win-points-live-notification.is-visible[data-position="top-center"] {
      transform: translate3d(-50%, 0, 0);
    }

    .win-points-live-notification[data-position="middle-left"],
    .win-points-live-notification[data-position="middle-right"] {
      top: 50%;
    }

    .win-points-live-notification[data-position="middle-left"] {
      transform: translate3d(-18px, -50%, 0);
    }

    .win-points-live-notification[data-position="middle-right"] {
      transform: translate3d(18px, -50%, 0);
    }

    .win-points-live-notification.is-visible[data-position="middle-left"],
    .win-points-live-notification.is-visible[data-position="middle-right"] {
      transform: translate3d(0, -50%, 0);
    }

    .win-points-live-notification__pulse {
      width: 8px;
      height: 8px;
    }

    .win-points-live-notification__logo-wrap {
      width: 36px;
      height: 36px;
      border-radius: 12px;
    }

    .win-points-live-notification__logo-fallback {
      font-size: 0.72rem;
    }

    .win-points-live-notification__title {
      font-size: 0.82rem;
      margin-bottom: 0.06rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .win-points-live-notification__detail {
      font-size: 0.72rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
  }

  body.overlay-open {
    overflow: hidden;
  }

  .purchase-summary-layout {
    display: grid;
    grid-template-columns: 17rem 32rem;
    gap: 1rem;
    align-items: stretch;
    justify-content: center;
  }

  .page-step-title {
    font-size: clamp(1.7rem, 3.1vw, 2.35rem);
    font-weight: 900;
    letter-spacing: 0.02em;
    line-height: 1.08;
    text-shadow: 0 0 18px rgba(var(--theme-button-primary-rgb), 0.16);
  }

  .purchase-summary-layout-single {
    grid-template-columns: 32rem;
  }

  @media (max-width: 767.98px) {
    .purchase-summary-layout,
    .purchase-summary-layout-single {
      grid-template-columns: minmax(0, 1fr);
      justify-content: stretch;
    }

    .purchase-summary-column-result {
      width: 100%;
    }

    .purchase-summary-pack-card {
      padding: 0.95rem 1rem;
    }

    .purchase-summary-pack-name,
    .purchase-quantity-help {
      overflow-wrap: anywhere;
    }

    .purchase-quantity-panel {
      max-width: none;
    }
  }

  .email-disclaimer-card {
    min-height: 100%;
    padding: 0.95rem 1rem;
    border-radius: 0.95rem;
    border: 1px solid rgba(var(--theme-button-primary-rgb), 0.22);
    background: linear-gradient(135deg, rgba(var(--theme-button-surface-rgb), 0.78), rgba(var(--theme-bg-main-rgb), 0.94));
    color: #d6eef5;
    font-size: 0.95rem;
    line-height: 1.45;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02);
  }

  @media (min-width: 768px) {
    .email-disclaimer-card {
      min-height: calc(2.375rem + 2px);
      padding: 0.55rem 0.9rem;
      display: flex;
      align-items: center;
      font-size: 0.74rem;
      line-height: 1.15;
    }
  }

  .payment-coupon-shell {
    display: flex;
    justify-content: center;
  }

  .payment-coupon-panel {
    width: min(100%, 38rem);
    padding: 1rem 1.1rem;
    border-radius: 1rem;
    border: 1px solid rgba(var(--theme-button-primary-rgb), 0.3);
    background: linear-gradient(135deg, rgba(var(--theme-bg-main-rgb), 0.92), rgba(var(--theme-button-surface-rgb), 0.84) 58%, rgba(var(--theme-bg-main-rgb), 0.96));
    box-shadow: 0 0 18px rgba(var(--theme-button-primary-rgb), 0.12), inset 0 0 0 1px rgba(255,255,255,0.03);
  }

  .payment-method-catalog-shell {
    display: flex;
    justify-content: center;
  }

  .payment-method-catalog-panel {
    width: min(100%, 64rem);
    padding: 1rem 1.1rem 1.15rem;
    border-radius: 1rem;
    border: 1px solid rgba(var(--theme-button-primary-rgb), 0.18);
    background: linear-gradient(135deg, rgba(var(--theme-bg-main-rgb), 0.88), rgba(var(--theme-button-surface-rgb), 0.76) 58%, rgba(var(--theme-bg-main-rgb), 0.96));
    box-shadow: 0 0 18px rgba(var(--theme-button-primary-rgb), 0.08), inset 0 0 0 1px rgba(255,255,255,0.02);
  }

  .payment-method-catalog-head {
    display: grid;
    gap: 0.18rem;
    margin-bottom: 0.9rem;
  }

  .payment-method-catalog-title {
    color: #f8fafc;
    font-size: 1rem;
    font-weight: 800;
  }

  .payment-method-catalog-copy {
    color: #93c5fd;
    font-size: 0.88rem;
    line-height: 1.45;
  }

  .payment-method-catalog-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.9rem;
  }

  .payment-method-public-card {
    position: relative;
    width: 100%;
    min-height: 104px;
    aspect-ratio: 5 / 2;
    padding: 0;
    border-radius: 1rem;
    border: 1px solid rgba(34, 211, 238, 0.22);
    background: linear-gradient(135deg, rgba(8, 20, 36, 0.96), rgba(15, 23, 42, 0.92) 62%, rgba(8, 47, 73, 0.32));
    overflow: visible;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
  }

  .payment-method-public-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg, transparent 18%, rgba(34, 211, 238, 0.14) 50%, transparent 82%);
    transform: translateX(-120%);
    transition: transform 0.42s ease;
    pointer-events: none;
  }

  .payment-method-public-card:hover::before,
  .payment-method-public-card:focus-visible::before {
    transform: translateX(120%);
  }

  .payment-method-public-card:hover,
  .payment-method-public-card:focus-visible {
    transform: translateY(-2px);
    border-color: rgba(34, 211, 238, 0.68);
    box-shadow: 0 0 18px rgba(34, 211, 238, 0.14);
    outline: none;
  }

  .payment-method-public-card.is-selected {
    border-color: rgba(34, 197, 94, 0.98);
    box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.38), 0 0 0 5px rgba(34, 197, 94, 0.14), 0 0 34px rgba(34, 197, 94, 0.36);
    background: linear-gradient(135deg, rgba(6, 26, 18, 0.98), rgba(15, 23, 42, 0.94) 58%, rgba(21, 128, 61, 0.2));
  }

  .payment-method-public-card.is-disabled {
    opacity: 0.52;
    cursor: not-allowed;
  }

  .payment-method-public-button {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 100%;
    min-height: 0;
    display: flex;
    align-items: stretch;
    justify-content: center;
    padding: 0;
    border: 0;
    border-radius: inherit;
    background: transparent;
    text-align: center;
    color: inherit;
    overflow: hidden;
  }

  .payment-method-public-button:disabled {
    cursor: not-allowed;
  }

  .payment-method-public-image {
    width: 100%;
    height: 100%;
    min-height: 0;
    max-height: none;
    object-fit: cover;
    display: block;
  }

  .payment-method-public-image-caption {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 2;
    display: grid;
    gap: 0.18rem;
    padding: 0.8rem 0.95rem 0.75rem;
    background: linear-gradient(180deg, rgba(2, 6, 23, 0) 0%, rgba(2, 6, 23, 0.78) 38%, rgba(2, 6, 23, 0.94) 100%);
    text-align: left;
    pointer-events: none;
  }

  .payment-method-public-image-caption .payment-method-public-name,
  .payment-method-public-image-caption .payment-method-public-meta {
    color: #f8fafc;
    text-shadow: 0 1px 8px rgba(2, 6, 23, 0.75);
  }

  .payment-method-public-corner-badge {
    position: absolute;
    top: -0.95rem;
    right: -0.35rem;
    z-index: 4;
    width: clamp(56px, 18vw, 82px);
    aspect-ratio: 1;
    pointer-events: none;
    filter: drop-shadow(0 12px 18px rgba(2, 6, 23, 0.42));
    transition: transform 0.18s ease, filter 0.18s ease;
  }

  .payment-method-public-corner-badge img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
  }

  .payment-method-public-card.is-selected .payment-method-public-corner-badge {
    transform: scale(1.06);
    filter: drop-shadow(0 0 16px rgba(34, 197, 94, 0.42)) drop-shadow(0 12px 18px rgba(2, 6, 23, 0.48));
  }

  .payment-method-public-text {
    display: grid;
    gap: 0.2rem;
    width: 100%;
    padding: 1rem 1.1rem;
    align-self: center;
  }

  .payment-method-public-name {
    color: #f8fafc;
    font-size: 1rem;
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: 0.02em;
  }

  .payment-method-public-meta {
    color: #93c5fd;
    font-size: 0.8rem;
    line-height: 1.35;
    font-weight: 600;
  }

  .payment-method-public-points-caption {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 2;
    padding: 0.7rem 0.9rem 0.75rem;
    background: linear-gradient(180deg, rgba(2, 6, 23, 0) 0%, rgba(2, 6, 23, 0.84) 42%, rgba(2, 6, 23, 0.95) 100%);
    color: #f8fafc;
    font-size: 0.78rem;
    line-height: 1.35;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.65);
    text-align: left;
  }

  .payment-method-public-points-caption strong {
    display: block;
    color: #fde68a;
    font-size: 0.84rem;
  }

  .payment-order-summary-shell {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.8rem;
  }

  .payment-order-summary-coupon {
    width: min(100%, 35rem);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.9rem;
    padding: 0.8rem 1rem;
    border-radius: 1rem;
    border: 1px solid rgba(52, 211, 153, 0.34);
    background: linear-gradient(135deg, rgba(6, 78, 59, 0.94), rgba(6, 46, 32, 0.92) 55%, rgba(10, 24, 20, 0.98));
    box-shadow: 0 0 22px rgba(16, 185, 129, 0.14), inset 0 0 0 1px rgba(255,255,255,0.04);
  }

  .payment-order-summary-coupon-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.36rem 0.68rem;
    border-radius: 999px;
    border: 1px solid rgba(167, 243, 208, 0.25);
    background: rgba(5, 150, 105, 0.16);
    color: #d1fae5;
    font-size: 0.72rem;
    font-weight: 900;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    white-space: nowrap;
  }

  .payment-order-summary-coupon-copy {
    color: #ecfdf5;
    font-size: 0.9rem;
    font-weight: 800;
    line-height: 1.35;
    text-align: right;
  }

  .payment-order-summary-panel {
    width: min(100%, 35rem);
    padding: 1.15rem 1.1rem 1.15rem;
    border-radius: 1.15rem;
    border: 1px solid rgba(34, 211, 238, 0.26);
    background:
      radial-gradient(circle at top center, rgba(34, 211, 238, 0.12), transparent 34%),
      linear-gradient(180deg, rgba(6, 14, 26, 0.96), rgba(10, 20, 38, 0.94) 52%, rgba(8, 12, 22, 0.98));
    box-shadow: 0 0 28px rgba(34, 211, 238, 0.12), inset 0 0 0 1px rgba(255,255,255,0.03);
    opacity: 0;
    transform: translateY(-18px) scale(0.985);
    transition: opacity 0.28s ease, transform 0.32s ease, box-shadow 0.24s ease, border-color 0.24s ease;
  }

  .payment-order-summary-panel.is-active {
    opacity: 1;
    transform: translateY(0) scale(1);
  }

  .payment-order-summary-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.9rem;
    margin-bottom: 1rem;
  }

  .payment-order-summary-eyebrow {
    color: #7dd3fc;
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.14em;
    text-transform: uppercase;
  }

  .payment-order-summary-title {
    color: #f8fafc;
    font-size: clamp(1.15rem, 2vw, 1.45rem);
    font-weight: 900;
    letter-spacing: 0.02em;
    text-shadow: 0 0 16px rgba(34, 211, 238, 0.12);
  }

  .payment-order-summary-method {
    align-self: center;
    padding: 0.45rem 0.8rem;
    border-radius: 999px;
    border: 1px solid rgba(34, 197, 94, 0.34);
    background: linear-gradient(180deg, rgba(20, 83, 45, 0.28), rgba(6, 22, 14, 0.92));
    color: #dcfce7;
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    box-shadow: 0 0 14px rgba(34, 197, 94, 0.14);
  }

  .payment-order-summary-rows {
    display: grid;
    gap: 0.55rem;
  }

  .payment-order-summary-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.85rem;
    align-items: center;
    padding: 0.72rem 0.9rem;
    border-radius: 0.9rem;
    background: linear-gradient(180deg, rgba(13, 22, 38, 0.92), rgba(8, 13, 24, 0.96));
    border: 1px solid rgba(59, 130, 246, 0.12);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.02);
  }

  .payment-order-summary-row-label {
    color: #cbd5e1;
    font-size: 0.86rem;
    font-weight: 700;
    letter-spacing: 0.02em;
  }

  .payment-order-summary-row-value {
    color: #f8fafc;
    font-size: 0.95rem;
    font-weight: 900;
    text-align: right;
  }

  .payment-order-summary-row-value.is-positive {
    color: #4ade80;
    text-shadow: 0 0 12px rgba(34, 197, 94, 0.16);
  }

  .payment-order-summary-total-wrap {
    margin-top: 1rem;
    padding: 1rem 1rem 0;
    border-top: 1px solid rgba(34, 211, 238, 0.16);
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
  }

  .payment-order-summary-total-label {
    color: #cbd5e1;
    font-size: 0.84rem;
    font-weight: 800;
    letter-spacing: 0.14em;
    text-transform: uppercase;
  }

  .payment-order-summary-total-value {
    color: #f8fafc;
    font-size: clamp(1.45rem, 3.2vw, 2rem);
    font-weight: 900;
    line-height: 1;
    text-align: right;
    text-shadow: 0 0 18px rgba(34, 211, 238, 0.18);
  }

  .payment-order-summary-buy-btn {
    width: 100%;
    margin-top: 1rem;
    min-height: 3.65rem;
    border: 0;
    border-radius: 0.95rem;
    background: linear-gradient(135deg, rgba(30, 64, 175, 0.98), rgba(168, 85, 247, 0.98) 56%, rgba(236, 72, 153, 0.98));
    color: #f8fafc;
    font-size: 0.98rem;
    font-weight: 900;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    box-shadow: 0 14px 30px rgba(168, 85, 247, 0.24), 0 0 0 1px rgba(255,255,255,0.08), inset 0 1px 0 rgba(255,255,255,0.18);
    transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease, opacity 0.18s ease;
  }

  .payment-order-summary-buy-btn:hover:not(:disabled),
  .payment-order-summary-buy-btn:focus-visible:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 18px 34px rgba(168, 85, 247, 0.28), 0 0 22px rgba(236, 72, 153, 0.18);
    filter: saturate(1.08);
    outline: none;
  }

  .payment-order-summary-buy-btn:disabled {
    opacity: 0.72;
    cursor: not-allowed;
    box-shadow: none;
    filter: grayscale(0.08);
  }

  .purchase-summary-column {
    min-width: 0;
  }

  .purchase-summary-column-quantity {
    width: 100%;
  }

  .purchase-summary-column-result {
    min-width: 0;
    width: 32rem;
  }

  .purchase-summary-pack-copy {
    min-width: 0;
  }

  .purchase-summary-pack-card {
    height: 100%;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 1rem 1.15rem;
    border-radius: 1rem;
    border: 1px solid rgba(var(--theme-button-primary-rgb), 0.65);
    background:
      radial-gradient(circle at top right, rgba(var(--theme-button-primary-rgb), 0.15), transparent 34%),
      linear-gradient(135deg, rgba(var(--theme-bg-main-rgb), 0.92), rgba(var(--theme-button-surface-rgb), 0.82) 55%, rgba(var(--theme-bg-main-rgb), 0.98));
    box-shadow: 0 0 0 1px rgba(var(--theme-button-primary-rgb), 0.14), 0 0 22px rgba(var(--theme-button-primary-rgb), 0.14), inset 0 0 18px rgba(255, 255, 255, 0.02);
  }

  .purchase-summary-card-label {
    color: var(--theme-text-muted);
    font-size: 0.78rem;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }

  .purchase-summary-pack-name {
    color: #f8fafc;
    font-size: clamp(1.05rem, 2.2vw, 1.35rem);
    font-weight: 800;
    line-height: 1.15;
  }

  .purchase-summary-total-block {
    margin-top: auto;
    padding-top: 0.85rem;
    border-top: 1px solid rgba(var(--theme-button-primary-rgb), 0.24);
  }

  .purchase-summary-total-value {
    color: var(--theme-price-text);
    font-size: clamp(1.5rem, 3vw, 2rem);
    font-weight: 900;
    line-height: 1;
    text-shadow: 0 0 14px rgba(var(--theme-button-primary-rgb), 0.18);
  }

  .purchase-quantity-panel {
    width: 100%;
    max-width: 17rem;
    height: 100%;
    padding: 0.9rem;
    border-radius: 1rem;
    border: 1px solid rgba(var(--theme-button-primary-rgb), 0.28);
    background: linear-gradient(180deg, rgba(var(--theme-button-surface-rgb), 0.82), rgba(var(--theme-bg-main-rgb), 0.94));
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
    display: flex;
    flex-direction: column;
    justify-content: center;
  }

  .purchase-quantity-label {
    display: block;
    margin-bottom: 0.55rem;
    color: var(--theme-button-primary);
    font-size: 0.84rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
  }

  .purchase-quantity-stepper {
    display: grid;
    grid-template-columns: 3.25rem minmax(0, 1fr) 3.25rem;
    gap: 0.55rem;
    align-items: center;
  }

  .purchase-quantity-btn,
  .purchase-quantity-input {
    border-radius: 0.9rem;
    border: 1px solid rgba(var(--theme-button-primary-rgb), 0.65);
  }

  .purchase-quantity-btn {
    min-height: 4rem;
    padding: 0;
    background: linear-gradient(180deg, rgba(var(--theme-button-primary-rgb), 0.22), rgba(var(--theme-button-primary-rgb), 0.1));
    color: var(--theme-button-primary);
    font-size: 1.9rem;
    font-weight: 800;
    line-height: 1;
    box-shadow: 0 0.75rem 1.75rem rgba(0, 0, 0, 0.2);
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, opacity 0.18s ease;
  }

  .purchase-quantity-btn:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 1rem 2rem rgba(var(--theme-button-primary-rgb), 0.18);
    border-color: rgba(var(--theme-button-primary-rgb), 0.95);
  }

  .purchase-quantity-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    box-shadow: none;
  }

  .purchase-quantity-input {
    min-height: 4rem;
    width: 100%;
    padding: 0.55rem 0.75rem;
    background: rgba(var(--theme-bg-main-rgb), 0.88);
    color: var(--theme-price-text);
    font-size: 1.65rem;
    font-weight: 800;
    text-align: center;
    letter-spacing: 0.04em;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02);
    appearance: textfield;
  }

  .purchase-quantity-input:focus {
    outline: none;
    border-color: rgba(var(--theme-button-primary-rgb), 0.98);
    box-shadow: 0 0 0 0.2rem rgba(var(--theme-button-primary-rgb), 0.14);
  }

  .purchase-quantity-input::-webkit-outer-spin-button,
  .purchase-quantity-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
  }

  .purchase-quantity-help {
    margin-top: 0.55rem;
    color: var(--theme-text-muted);
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1.35;
  }

  .pack-card {
    min-height: 15rem;
    position: relative;
    padding: 0;
    border-radius: 1.1rem;
    border: 0 !important;
    overflow: visible;
    isolation: isolate;
    appearance: none;
    background:
      radial-gradient(circle at top, rgba(var(--theme-button-primary-rgb), 0.18), transparent 45%),
      linear-gradient(180deg, rgba(var(--theme-button-surface-rgb), 0.98), rgba(var(--theme-bg-main-rgb), 0.98));
    box-shadow: 0 0 0 1px rgba(var(--theme-button-primary-rgb), 0.1), 0 0 20px rgba(var(--theme-button-primary-rgb), 0.12), 0 0 38px rgba(var(--theme-button-secondary-rgb), 0.06);
    will-change: transform, box-shadow;
    transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.28s ease, border-color 0.28s ease, background 0.28s ease;
  }

  .pack-card::before {
    content: "";
    position: absolute;
    inset: -22%;
    border-radius: inherit;
    background:
      radial-gradient(circle at top, rgba(var(--theme-button-secondary-rgb), 0.24), transparent 48%),
      radial-gradient(circle at bottom, rgba(var(--theme-button-primary-rgb), 0.16), transparent 52%);
    opacity: 0;
    transform: translate3d(0, 18px, 0) scale(0.92);
    transition: opacity 0.28s ease, transform 0.36s cubic-bezier(0.22, 1, 0.36, 1);
    pointer-events: none;
    z-index: 0;
  }

  .pack-card::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    box-shadow: inset 0 0 0 1px rgba(var(--theme-button-primary-rgb), 1), 0 0 18px rgba(var(--theme-button-primary-rgb), 0.16), 0 0 30px rgba(var(--theme-button-secondary-rgb), 0.08);
    pointer-events: none;
  }

  .pack-card:hover,
  .pack-card:focus-visible {
    transform: translateY(-9px) scale(1.02);
    box-shadow: 0 1.4rem 2.6rem rgba(var(--theme-button-primary-rgb), 0.24), 0 0 0 1px rgba(var(--theme-button-secondary-rgb), 0.3), 0 0 28px rgba(var(--theme-button-primary-rgb), 0.2), 0 0 48px rgba(var(--theme-button-secondary-rgb), 0.12);
    outline: none;
  }

  .pack-card:hover::before,
  .pack-card:focus-visible::before {
    opacity: 1;
    transform: translate3d(0, 0, 0) scale(1);
  }

  .pack-card .card-body {
    min-height: 100%;
    display: flex;
    flex-direction: column;
    position: relative;
    border-radius: inherit;
  }

  .pack-card {
    cursor: pointer;
  }

  .pack-card-media {
    width: 100%;
    margin: 0;
    min-height: 8.5rem;
    aspect-ratio: 16 / 9;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
    border-radius: calc(1.1rem - 1px) calc(1.1rem - 1px) 0 0;
    will-change: transform, box-shadow;
    transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.28s ease, border-color 0.28s ease, background 0.28s ease;
    background: linear-gradient(180deg, rgba(var(--theme-bg-main-rgb), 0.45), rgba(var(--theme-bg-main-rgb), 0.05));
    flex-shrink: 0;
  }

  .pack-card-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.36s cubic-bezier(0.22, 1, 0.36, 1), filter 0.3s ease;
    border-radius: 0;
  }

  .pack-card:hover .pack-card-image,
  .pack-card:focus-visible .pack-card-image {
    transform: scale(1.07);
    filter: saturate(1.08) brightness(1.05);
  }

  .pack-card-glow {
    position: absolute;
    inset: auto 0 0 0;
    height: 55%;
    background: linear-gradient(180deg, rgba(3, 7, 18, 0) 0%, rgba(3, 7, 18, 0.8) 78%, rgba(3, 7, 18, 0.98) 100%);
  }

  .pack-card-placeholder {
    color: var(--theme-button-primary);
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.18em;
  }

  .pack-card-features {
    position: absolute;
    top: -0.72rem;
    right: -0.55rem;
    left: auto;
    z-index: 4;
    display: flex;
    flex-wrap: wrap;
    gap: 0.42rem;
    justify-content: flex-end;
    transition: transform 0.36s cubic-bezier(0.22, 1, 0.36, 1), filter 0.3s ease;
    align-items: flex-start;
    align-content: flex-start;
    max-width: calc(100% - 1.2rem);
    pointer-events: none;
  }

  .pack-card-feature-badge {
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    gap: 0.42rem;
    max-width: 100%;
    min-height: 1.65rem;
    padding: 0.3rem 0.62rem;
    border-radius: 999px;
    border: 1px solid rgba(var(--theme-button-primary-rgb), 0.72);
    background: linear-gradient(135deg, rgba(9, 18, 34, 0.96), rgba(15, 23, 42, 0.9));
    color: #f8fbff;
    box-shadow: 0 12px 26px rgba(0, 0, 0, 0.34), 0 0 18px rgba(var(--theme-button-primary-rgb), 0.24), 0 0 32px rgba(var(--theme-button-secondary-rgb), 0.14), inset 0 0 12px rgba(var(--theme-button-primary-rgb), 0.12);
    transition: transform 0.24s ease, box-shadow 0.24s ease, background 0.24s ease;
  }

  .pack-card:hover .pack-card-feature-badge,
  .pack-card:focus-visible .pack-card-feature-badge {
    transform: translateY(-2px);
    box-shadow: 0 16px 34px rgba(2, 6, 23, 0.34), 0 0 20px rgba(var(--theme-button-primary-rgb), 0.32), 0 0 40px rgba(var(--theme-button-secondary-rgb), 0.18), inset 0 0 14px rgba(var(--theme-button-primary-rgb), 0.14);
    background: linear-gradient(135deg, rgba(10, 22, 40, 0.98), rgba(17, 30, 55, 0.92));
  }

  .pack-card-feature-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 0.95rem;
    min-height: 0.95rem;
    flex: 0 0 auto;
    line-height: 1;
    color: var(--theme-package-feature-text, #D8FBFF);
  }

  .pack-card-feature-text {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.72rem;
    font-weight: 700;
    line-height: 1.08;
    letter-spacing: 0.01em;
  }

  .pack-card-content {
    display: grid;
    gap: 0.75rem;
    padding: 0.9rem 0.95rem 1rem;
    margin-top: auto;
  }

  .pack-card-name {
    color: var(--theme-text);
    min-height: 2.4rem;
    display: flex;
    align-items: flex-start;
    justify-content: flex-start;
    text-align: left;
    line-height: 1.15;
    width: 100%;
    font-size: 0.98rem;
    letter-spacing: 0.01em;
    text-shadow: 0 0 10px rgba(var(--theme-button-primary-rgb), 0.18);
  }

  .pack-card-footer {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 0.65rem;
    border-top: 1px solid rgba(var(--theme-button-primary-rgb), 0.18);
    padding-top: 0.65rem;
  }

  .moneda-label {
    color: var(--theme-price-muted);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    opacity: 0.92;
  }

  .precio-label {
    color: var(--theme-price-text);
    font-size: 1.1rem;
    font-weight: 800;
    line-height: 1;
    text-shadow: 0 0 12px rgba(var(--theme-price-text-rgb), 0.16);
  }

  .neon-selected {
    transform: translateY(-9px) scale(1.022);
    box-shadow: 0 0 0 2px rgba(var(--theme-button-primary-rgb), 1), 0 0 0 7px rgba(var(--theme-button-primary-rgb), 0.38), 0 0 30px 8px rgba(var(--theme-button-primary-rgb), 0.74), 0 0 64px 16px rgba(var(--theme-button-secondary-rgb), 0.62);
    background: linear-gradient(180deg, rgba(var(--theme-button-surface-rgb), 1), rgba(var(--theme-bg-main-rgb), 0.98)) !important;
    transition: transform 0.24s ease, box-shadow 0.24s ease, border-color 0.24s ease;
    z-index: 3;
  }

  .neon-selected::after {
    box-shadow: inset 0 0 0 3px rgba(var(--theme-button-primary-rgb), 1), inset 0 0 0 7px rgba(var(--theme-button-secondary-rgb), 0.28);
  }

  .neon-selected::before {
    opacity: 1;
    transform: translate3d(0, 0, 0) scale(1.02);
  }

  .neon-selected .pack-card-media {
    box-shadow: inset 0 0 0 2px rgba(var(--theme-button-primary-rgb), 0.82), 0 0 24px rgba(var(--theme-button-primary-rgb), 0.24);
  }

  .neon-selected .pack-card-content {
    background: linear-gradient(180deg, rgba(var(--theme-button-primary-rgb), 0.08), rgba(var(--theme-bg-main-rgb), 0));
  }

  .neon-selected:hover,
  .neon-selected:focus-visible {
    transform: translateY(-11px) scale(1.026);
    box-shadow: 0 0 0 2px rgba(var(--theme-button-primary-rgb), 1), 0 0 0 8px rgba(var(--theme-button-primary-rgb), 0.44), 0 0 36px 9px rgba(var(--theme-button-primary-rgb), 0.84), 0 0 72px 18px rgba(var(--theme-button-secondary-rgb), 0.66);
  }

  .neon-selected .pack-card-footer {
    border-top-color: rgba(var(--theme-button-secondary-rgb), 0.48);
  }

  @media (max-width: 575.98px) {
    .purchase-summary-layout {
      grid-template-columns: 1fr;
      justify-content: stretch;
    }

    .purchase-summary-column-result {
      width: 100%;
    }

    .purchase-summary-pack-card {
      padding: 0.85rem 0.9rem;
      gap: 0.65rem;
    }

    .purchase-summary-pack-name {
      font-size: clamp(0.98rem, 4.2vw, 1.18rem);
    }

    .purchase-summary-total-value {
      font-size: clamp(1.35rem, 6.4vw, 1.75rem);
    }

    .payment-coupon-panel {
      width: 100%;
      padding: 0.9rem 0.95rem;
    }

    .payment-method-catalog-panel {
      width: 100%;
      padding: 0.9rem 0.95rem 1rem;
    }

    .payment-method-catalog-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.65rem;
    }

    .payment-method-public-card {
      min-height: 82px;
      border-radius: 0.82rem;
    }

    .payment-method-public-corner-badge {
      top: -0.62rem;
      right: -0.22rem;
      width: clamp(42px, 15vw, 56px);
    }

    .payment-method-public-text {
      padding: 0.72rem 0.75rem;
    }

    .payment-method-public-name {
      font-size: 0.84rem;
    }

    .payment-method-public-meta {
      font-size: 0.68rem;
      line-height: 1.22;
    }

    .payment-method-public-points-caption {
      padding: 0.5rem 0.58rem 0.56rem;
      font-size: 0.64rem;
      line-height: 1.22;
    }

    .payment-method-public-points-caption strong {
      font-size: 0.7rem;
    }

    .payment-order-summary-panel {
      padding: 1rem 0.9rem 1rem;
    }

    .payment-order-summary-coupon {
      padding: 0.8rem 0.9rem;
      display: grid;
      justify-items: start;
    }

    .payment-order-summary-coupon-copy {
      text-align: left;
    }

    .payment-order-summary-head {
      display: grid;
      gap: 0.75rem;
    }

    .payment-order-summary-method {
      justify-self: start;
    }

    .payment-order-summary-total-wrap {
      flex-direction: column;
      align-items: flex-start;
    }

    .payment-order-summary-total-value {
      text-align: left;
    }

    .page-step-title {
      font-size: clamp(1.35rem, 6vw, 1.8rem);
    }

    .purchase-quantity-panel {
      max-width: none;
      padding: 0.8rem;
    }

    .purchase-quantity-stepper {
      grid-template-columns: 3rem minmax(0, 1fr) 3rem;
      gap: 0.45rem;
    }

    .purchase-quantity-btn,
    .purchase-quantity-input {
      min-height: 3.5rem;
    }

    .purchase-quantity-input {
      font-size: 1.45rem;
    }

    .purchase-quantity-help {
      font-size: 0.78rem;
      line-height: 1.3;
      overflow-wrap: anywhere;
    }

    .pack-card {
      min-height: 13.75rem;
    }

    .pack-card-media {
      min-height: 7.3rem;
      aspect-ratio: 16 / 10;
      padding: 0;
    }

    .pack-card-features {
      top: -0.55rem;
      right: -0.38rem;
      left: auto;
      gap: 0.28rem;
      max-width: calc(100% - 0.9rem);
    }

    .pack-card-feature-badge {
      min-height: 1.5rem;
      padding: 0.24rem 0.5rem;
    }

    .pack-card-feature-text {
      max-width: 7.2rem;
      font-size: 0.66rem;
    }


  @media (max-width: 399.98px) {
    .purchase-summary-pack-card {
      padding: 0.8rem 0.8rem 0.9rem;
    }

    .purchase-summary-card-label {
      font-size: 0.72rem;
    }

    .purchase-summary-total-block {
      padding-top: 0.7rem;
    }

    .purchase-quantity-panel {
      padding: 0.72rem;
    }

    .purchase-quantity-label {
      font-size: 0.76rem;
      margin-bottom: 0.45rem;
    }

    .purchase-quantity-stepper {
      grid-template-columns: 2.6rem minmax(0, 1fr) 2.6rem;
      gap: 0.35rem;
    }

    .purchase-quantity-btn,
    .purchase-quantity-input {
      min-height: 3rem;
      border-radius: 0.8rem;
    }

    .purchase-quantity-btn {
      font-size: 1.5rem;
    }

    .purchase-quantity-input {
      padding: 0.45rem 0.5rem;
      font-size: 1.2rem;
    }

    .purchase-quantity-help {
      margin-top: 0.45rem;
      font-size: 0.74rem;
    }
  }
    .pack-card-content {
      padding: 0.8rem 0.8rem 0.9rem;
      gap: 0.55rem;
    }

    .pack-card-name {
      font-size: 0.9rem;
      min-height: 2.1rem;
    }

    .precio-label {
      font-size: 1rem;
    }
  }

  .pack-account-sale-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.7rem;
  }

  .pack-account-sale-badge {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    padding: 0.3rem 0.7rem;
    border-radius: 999px;
    border: 1px solid rgba(34, 211, 238, 0.45);
    background: rgba(8, 15, 24, 0.95);
    color: #67e8f9;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .pack-account-preview-btn {
    appearance: none;
    border: 1px solid var(--theme-account-preview-button-border);
    border-radius: 999px;
    padding: 0.42rem 0.92rem;
    background: var(--theme-account-preview-button-bg);
    color: var(--theme-account-preview-button-text);
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    line-height: 1;
    box-shadow: 0 10px 22px rgba(var(--theme-account-preview-button-shadow-rgb), 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.12);
    transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, color 0.18s ease, background 0.18s ease;
  }

  .pack-account-preview-btn:hover,
  .pack-account-preview-btn:focus-visible {
    transform: translateY(-1px);
    border-color: var(--theme-account-preview-button-border);
    background: var(--theme-account-preview-button-bg);
    box-shadow: 0 14px 28px rgba(var(--theme-account-preview-button-shadow-rgb), 0.3), 0 0 0 3px rgba(var(--theme-account-preview-button-shadow-rgb), 0.16);
    color: var(--theme-account-preview-button-text);
    filter: brightness(1.08);
  }

  .account-sale-note {
    border: 1px solid rgba(34, 211, 238, 0.35);
    background: linear-gradient(135deg, rgba(8, 15, 24, 0.96), rgba(17, 24, 39, 0.98));
    color: #c7f9ff;
  }

  .account-gallery-modal-content {
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
  }

  #account-gallery-modal .modal-dialog {
    width: min(96vw, 80rem);
    max-width: 80rem;
    margin: auto;
  }

  .account-gallery-modal-header,
  .account-gallery-modal-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.1rem 1.35rem;
    background: linear-gradient(135deg, rgba(8, 15, 24, 0.96), rgba(17, 24, 39, 0.98));
  }

  .account-gallery-modal-eyebrow {
    color: #67e8f9;
    font-size: 0.78rem;
    letter-spacing: 0.18em;
    text-transform: uppercase;
  }

  .account-gallery-modal-body {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
    padding: 1.35rem;
    background: #0b1220;
  }

  .account-gallery-modal-details {
    display: grid;
    gap: 0.55rem;
  }

  .account-gallery-main-frame {
    min-height: clamp(320px, 52vh, 620px);
    max-height: min(68vh, 620px);
    padding: clamp(0.45rem, 1vw, 0.85rem);
    position: relative;
    border-radius: 22px;
    border: 1px solid rgba(34, 211, 238, 0.2);
    background: radial-gradient(circle at top, rgba(14, 165, 233, 0.18), rgba(2, 6, 23, 0.98));
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  .account-gallery-main-image {
    display: block;
    width: 100%;
    height: 100%;
    min-height: 0;
    max-height: min(66vh, 600px);
    object-fit: contain;
    border-radius: 18px;
  }

  .account-gallery-main-placeholder {
    color: #94a3b8;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }

  .account-gallery-modal-price {
    color: #22d3ee;
    font-size: 1.25rem;
    font-weight: 800;
  }

  .account-gallery-modal-copy,
  .account-gallery-modal-caption {
    color: #cbd5e1;
    line-height: 1.65;
  }

  .account-gallery-modal-caption {
    position: absolute;
    left: 1rem;
    bottom: 1rem;
    z-index: 2;
    max-width: min(78%, 26rem);
    padding: 0.65rem 0.85rem;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(8, 15, 24, 0.88), rgba(15, 23, 42, 0.78));
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(10px);
    color: #f8fafc;
    font-weight: 600;
    line-height: 1.45;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.65);
  }

  .account-gallery-modal-caption:empty {
    display: none;
  }

  .account-gallery-thumbs {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(92px, 1fr));
    gap: 0.7rem;
  }

  .account-gallery-thumb {
    width: 100%;
    border: 1px solid rgba(34, 211, 238, 0.22);
    background: #081018;
    border-radius: 16px;
    padding: 0.3rem;
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
  }

  .account-gallery-thumb.is-active {
    border-color: rgba(34, 211, 238, 0.9);
    box-shadow: 0 0 0 2px rgba(34, 211, 238, 0.12);
    transform: translateY(-2px);
  }

  .account-gallery-thumb img {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 10;
    object-fit: contain;
    border-radius: 12px;
    background: rgba(2, 6, 23, 0.82);
  }

  .account-sale-delivery-card {
    display: grid;
    gap: 1rem;
  }

  .account-sale-delivery-copy {
    padding: 1rem 1.05rem;
    border-radius: 16px;
    border: 1px solid rgba(52, 211, 153, 0.25);
    background: rgba(8, 15, 24, 0.9);
    color: #e2e8f0;
    white-space: pre-wrap;
    line-height: 1.65;
  }

  .account-sale-delivery-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 0.75rem;
  }

  .account-sale-delivery-gallery-item {
    display: grid;
    gap: 0.4rem;
  }

  .account-sale-delivery-gallery-item img {
    width: 100%;
    border-radius: 14px;
    border: 1px solid rgba(34, 211, 238, 0.18);
    aspect-ratio: 1 / 1;
    object-fit: cover;
  }

  .account-sale-delivery-gallery-item span {
    color: #cbd5e1;
    font-size: 0.8rem;
    line-height: 1.45;
  }

  .account-sale-copy-btn {
    margin-top: 0.75rem;
    justify-self: start;
  }

  @media (max-width: 767.98px) {
    .account-gallery-main-frame {
      min-height: 240px;
      max-height: none;
    }

    .account-gallery-main-image {
      max-height: 56vh;
    }

    .pack-account-sale-meta {
      align-items: stretch;
    }

    .pack-account-preview-btn {
      width: 100%;
    }
  }
</style>
<script>
  // Todas las variables y lógica JS en un solo bloque
  const appBasePath = <?= json_encode($scriptDir, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const rememberLastPurchaseIdentifierEnabled = <?= $rememberLastPurchaseIdentifierEnabled ? 'true' : 'false' ?>;
  const defaultOrderEmail = <?= json_encode($loggedUserEmail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let defaultOrderUserIdentifier = <?= json_encode($loggedUserLastPurchaseIdentifier, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let defaultPaymentPhone = <?= json_encode($loggedUserLastPurchasePhone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paymentMethodsByCurrency = <?= json_encode($paymentMethodsByCurrency, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePayCheckoutEnabled = <?= $binancePayCheckoutEnabled ? 'true' : 'false' ?>;
  const binancePagonorteCheckoutEnabled = <?= $binancePagonorteCheckoutEnabled ? 'true' : 'false' ?>;
  const paypalPayCheckoutEnabled = <?= $paypalPayCheckoutEnabled ? 'true' : 'false' ?>;
  const paymentMethodDiscountsEnabled = <?= $paymentMethodDiscountsEnabled ? 'true' : 'false' ?>;
  const binancePayDiscountPercentage = <?= json_encode((float) $binancePayDiscountPercentage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePagonorteDiscountPercentage = <?= json_encode((float) $binancePagonorteDiscountPercentage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePagonorteReferenceDigits = <?= json_encode((int) $binancePagonorteReferenceDigits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const paypalPayTaxPercentage = <?= json_encode((float) $paypalPayTaxPercentage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const accountSaleFeatureEnabled = <?= $accountSaleFeatureEnabled ? 'true' : 'false' ?>;
  const binancePayButtonLabel = 'Binance Pay';
  const binancePayImageUrl = <?= json_encode($binancePayImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePayCornerImageUrl = <?= json_encode($binancePayCornerImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePagonorteButtonLabel = 'Binance';
  const binancePagonorteImageUrl = <?= json_encode($binancePagonorteImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePagonorteCornerImageUrl = <?= json_encode($binancePagonorteCornerImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePagonorteQrImageUrl = <?= json_encode($binancePagonorteQrImageUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const binancePagonorteTransferData = <?= json_encode($binancePagonorteTransferData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
  const paymentSummaryTotalCopyButton = document.getElementById('payment-summary-total-copy');
  const paymentSummaryDiscount = document.getElementById('payment-summary-discount');
  const paymentSummaryMinimalUser = document.getElementById('payment-summary-minimal-user');
  const paymentSummaryMinimalProduct = document.getElementById('payment-summary-minimal-product');
  const paymentSummaryMinimalTotal = document.getElementById('payment-summary-minimal-total');
  const paymentSummaryMinimalTotalCopyButton = document.getElementById('payment-summary-minimal-total-copy');
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

    if (mode === 'binance_pagonorte') {
      return 'USDT';
    }

    if (mode === 'binance') {
      const preferredEntry = resolvePreferredBinanceCurrencyEntry();
      return String(preferredEntry && preferredEntry.clave ? preferredEntry.clave : '').trim().toUpperCase();
    }

    if (mode === 'paypal') {
      const preferredEntry = resolvePreferredPayPalCurrencyEntry();
      if (preferredEntry && preferredEntry.clave) {
        return String(preferredEntry.clave).trim().toUpperCase();
      }

      return String(pack && pack.moneda ? pack.moneda : '').trim().toUpperCase();
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

  function resolveBinancePagonorteCurrencyEntry() {
    return findCurrencyEntryByCode('USDT');
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

    const reopened = window.open(targetUrl, '_blank');
    if (reopened) {
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
    updatePaymentSummaryCopyButtons(safeTotal);

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
      if (pricing.taxPercentage > 0) {
        rows.push({ label: 'Impuesto PayPal', value: formatDiscountPercentage(pricing.taxPercentage), positive: false });
      }
      if (pricing.taxAmount > 0) {
        rows.push({ label: 'Aumento', value: pricing.taxText, positive: false });
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

  function canUseBinancePagonorteCheckout(pack) {
    return Boolean(binancePagonorteCheckoutEnabled && pack && getPackTotalPrice(pack) > 0 && resolveBinancePagonorteCurrencyEntry());
  }

  function canUsePayPalCheckout(pack) {
    const preferredPayPalCurrency = resolvePreferredPayPalCurrencyEntry();
    return Boolean(
      paypalPayCheckoutEnabled
      && pack
      && getPackTotalPrice(pack) > 0
      && preferredPayPalCurrency
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

    if (mode === 'binance_pagonorte') {
      return normalizeDiscountPercentage(binancePagonorteDiscountPercentage);
    }

    if (mode === 'binance') {
      return normalizeDiscountPercentage(binancePayDiscountPercentage);
    }

    return 0;
  }

  function resolvePaymentModeTaxPercentage(mode) {
    if (mode === 'paypal') {
      return normalizeDiscountPercentage(paypalPayTaxPercentage);
    }

    return 0;
  }

  function resolvePaymentPricing(mode = null, method = null) {
    const pack = activePaymentOrder && activePaymentOrder.pack ? activePaymentOrder.pack : activePack;
    const resolvedMode = mode || (activePaymentOrder ? activePaymentOrder.paymentMode : 'money');
    const quantity = normalizeOrderQuantity(activePaymentOrder && activePaymentOrder.purchaseQuantity ? activePaymentOrder.purchaseQuantity : (pack && pack.purchaseQuantity ? pack.purchaseQuantity : getOrderQuantity()));
    if (resolvedMode === 'points' && pack && getPackRequiredPoints(pack, quantity) > 0) {
      const pointsRequired = getPackRequiredPoints(pack, quantity);
      const pointsText = formatWinPointsAmount(pointsRequired);
      return {
        currencyCode: String(winPointsState.name || 'Win Points'),
        showDecimals: false,
        baseAmount: pointsRequired,
        discountPercentage: 0,
        discountAmount: 0,
        taxPercentage: 0,
        taxAmount: 0,
        totalAmount: pointsRequired,
        baseText: pointsText,
        discountText: formatWinPointsAmount(0),
        taxText: formatWinPointsAmount(0),
        totalText: pointsText,
      };
    }

    const preferredCurrencyCode = resolvePreferredDisplayCurrencyCode(resolvedMode, method && method.id ? String(method.id) : '', pack);
    const currencyCode = String(preferredCurrencyCode || (activePaymentOrder && activePaymentOrder.currency) || (pack && pack.moneda) || monedaActualClave || '').trim().toUpperCase();
    const showDecimals = Boolean(pack && pack.showDecimals);
    const baseAmount = normalizeCurrencyAmount(Number(activePaymentOrder && activePaymentOrder.baseAmount !== undefined ? activePaymentOrder.baseAmount : selectedTotalValue), showDecimals);
    const discountPercentage = resolvePaymentModeDiscountPercentage(resolvedMode, method);
    const discountAmount = discountPercentage > 0
      ? normalizeCurrencyAmount((baseAmount * discountPercentage) / 100, showDecimals)
      : 0;
    const subtotalAmount = normalizeCurrencyAmount(Math.max(0, baseAmount - discountAmount), showDecimals);
    const taxPercentage = resolvePaymentModeTaxPercentage(resolvedMode);
    const taxAmount = taxPercentage > 0
      ? normalizeCurrencyAmount((subtotalAmount * taxPercentage) / 100, showDecimals)
      : 0;
    const totalAmount = normalizeCurrencyAmount(subtotalAmount + taxAmount, showDecimals);

    return {
      currencyCode,
      showDecimals,
      baseAmount,
      discountPercentage,
      discountAmount,
      taxPercentage,
      taxAmount,
      totalAmount,
      baseText: formatPaymentDifferenceMoney(currencyCode, baseAmount, showDecimals),
      discountText: formatPaymentDifferenceMoney(currencyCode, discountAmount, showDecimals),
      taxText: formatPaymentDifferenceMoney(currencyCode, taxAmount, showDecimals),
      totalText: formatPaymentDifferenceMoney(currencyCode, totalAmount, showDecimals),
    };
  }

  function renderPaymentDiscountPanel(pricing, options = {}) {
    const variant = options.variant === 'method' ? 'method' : 'summary';
    const mode = options.mode || (activePaymentOrder ? activePaymentOrder.paymentMode : 'money');
    const methodName = mode === 'binance'
      ? String(binancePayButtonLabel || 'Binance Pay')
      : (mode === 'binance_pagonorte'
        ? String(binancePagonorteButtonLabel || 'Binance')
      : (mode === 'paypal'
        ? String(paypalPayButtonLabel || 'PayPal')
        : String(options.method && options.method.nombre ? options.method.nombre : 'Metodo de pago')));
    const hasTax = Number(pricing.taxPercentage || 0) > 0 && Number(pricing.taxAmount || 0) > 0;
    const badgeText = variant === 'summary' ? 'Metodo elegido' : '';
    const titleText = variant === 'method'
      ? (hasTax ? `${methodName} suma un impuesto a esta orden` : `${methodName} mantiene tu bonus en esta orden`)
      : methodName;
    const copyText = variant === 'method'
      ? (hasTax
        ? `Precio real del paquete ${pricing.baseText}. ${methodName} suma ${pricing.taxText} de impuesto y cierras la compra pagando ${pricing.totalText}.`
        : `Precio real del paquete ${pricing.baseText}. Ahorras ${pricing.discountText} y cierras la compra pagando ${pricing.totalText}.`)
      : (hasTax
        ? `Precio real del paquete ${pricing.baseText}. ${methodName} aplica ${formatDiscountPercentage(pricing.taxPercentage)} de impuesto, añade ${pricing.taxText} y deja el total final en ${pricing.totalText}.`
        : `Precio real del paquete ${pricing.baseText}. ${methodName} aplica ${formatDiscountPercentage(pricing.discountPercentage)} de descuento, te ahorra ${pricing.discountText} y deja el total final en ${pricing.totalText}.`);
    const chipText = hasTax ? `${formatDiscountPercentage(pricing.taxPercentage)} Impuesto` : `${formatDiscountPercentage(pricing.discountPercentage)} OFF`;
    const chipClass = hasTax ? 'payment-discount-chip is-tax' : 'payment-discount-chip';
    const percentageLabel = hasTax ? 'Impuesto' : 'Descuento';
    const percentageValue = hasTax ? formatDiscountPercentage(pricing.taxPercentage) : formatDiscountPercentage(pricing.discountPercentage);
    const amountLabel = hasTax ? 'Aumenta' : 'Ahorras';
    const amountValue = hasTax ? pricing.taxText : pricing.discountText;
    const amountStatClass = hasTax ? 'payment-discount-stat payment-discount-stat-warning' : 'payment-discount-stat';
    const totalLabel = variant === 'method' ? 'Pagas hoy' : 'Total final';

    return `
      <div class="payment-discount-panel payment-discount-panel-${variant}">
        <div class="payment-discount-panel-head">
          ${badgeText !== '' ? `<span class="payment-discount-badge">${escapePaymentHtml(badgeText)}</span>` : '<span></span>'}
          <span class="${escapePaymentHtml(chipClass)}">${escapePaymentHtml(chipText)}</span>
        </div>
        <div class="payment-discount-panel-title">${escapePaymentHtml(titleText)}</div>
        <div class="payment-discount-panel-copy">${escapePaymentHtml(copyText)}</div>
        <div class="payment-discount-grid">
          <div class="payment-discount-stat">
            <span>Precio real</span>
            <strong>${escapePaymentHtml(pricing.baseText)}</strong>
          </div>
          <div class="payment-discount-stat">
            <span>${escapePaymentHtml(percentageLabel)}</span>
            <strong>${escapePaymentHtml(percentageValue)}</strong>
          </div>
          <div class="${escapePaymentHtml(amountStatClass)}">
            <span>${escapePaymentHtml(amountLabel)}</span>
            <strong>${escapePaymentHtml(amountValue)}</strong>
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
    activePaymentOrder.taxPercentage = pricing.taxPercentage;
    activePaymentOrder.taxAmount = pricing.taxAmount;
    renderPaymentSummary(activePaymentOrder.pack, activePaymentOrder.userId, pricing.totalText);

    if (paymentSummaryDiscount) {
      if ((pricing.discountPercentage > 0 || pricing.taxPercentage > 0) && activePaymentOrder.paymentMode !== 'points') {
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
    if (mode === 'binance_pagonorte') {
      return 'binance_pagonorte';
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
    if (mode === 'binance_pagonorte') {
      preferredCheckoutPaymentMode = 'binance_pagonorte';
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
    const canUseBinancePagonorte = hasPack ? Boolean(canUseBinancePagonorteCheckout(pack)) : Boolean(binancePagonorteCheckoutEnabled && resolveBinancePagonorteCurrencyEntry());
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

    if (nextMode === 'binance_pagonorte' && !canUseBinancePagonorte) {
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
      canUseBinancePagonorte,
      canUseBinance,
      canUsePayPal,
    };
  }

  function shouldExpandSinglePaymentOption() {
    if (!activePaymentOrder) {
      return false;
    }

    const methods = getPaymentMethodsForCurrency(activePaymentOrder.currency);
    const usableOptionCount = methods.length + (activePaymentOrder.canUseBinancePagonorte ? 1 : 0) + (activePaymentOrder.canUseBinance ? 1 : 0) + (activePaymentOrder.canUsePayPal ? 1 : 0) + (activePaymentOrder.canUsePoints ? 1 : 0);
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

  function paymentMethodPublicCardContent(imageUrl, title, meta) {
    const safeImageUrl = String(imageUrl || '').trim();
    const safeTitle = escapePaymentHtml(title || 'Método de pago');
    const safeMeta = escapePaymentHtml(meta || '');
    const textMarkup = `<span class="payment-method-public-text"><span class="payment-method-public-name">${safeTitle}</span><span class="payment-method-public-meta">${safeMeta}</span></span>`;
    if (safeImageUrl === '') {
      return textMarkup;
    }

    return `<img src="${escapePaymentHtml(safeImageUrl)}" alt="${safeTitle}" class="payment-method-public-image">`;
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
      const imageMarkup = paymentMethodPublicCardContent(imageUrl, method.nombre || 'Método de pago', methodMetaText);
      const isSelected = selection.mode === 'money' && methodId === String(selection.methodId || '');
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="money" data-method-id="${escapePaymentHtml(methodId)}">${imageMarkup}</button>
          ${cornerMarkup}
        </div>`);
    });

    if (selection.canUseBinancePagonorte) {
      const binancePagonorteDiscount = resolvePaymentModeDiscountPercentage('binance_pagonorte', null);
      const binancePagonorteMeta = binancePagonorteDiscount > 0
        ? `USDT con verificación automática · ${formatDiscountPercentage(binancePagonorteDiscount)} OFF`
        : 'Pago en USDT con verificación automática por referencia';
      const isSelected = selection.mode === 'binance_pagonorte';
      const binancePagonorteCornerMarkup = paymentMethodPublicCornerMarkup(String(binancePagonorteCornerImageUrl || '').trim());
      const binancePagonorteMarkup = paymentMethodPublicCardContent(String(binancePagonorteImageUrl || '').trim(), binancePagonorteButtonLabel, binancePagonorteMeta);
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="binance_pagonorte">
            ${binancePagonorteMarkup}
          </button>
          ${binancePagonorteCornerMarkup}
        </div>`);
    }

    if (selection.canUseBinance) {
      const binanceDiscount = resolvePaymentModeDiscountPercentage('binance', null);
      const binanceMeta = binanceDiscount > 0
        ? `Checkout externo seguro · ${formatDiscountPercentage(binanceDiscount)} OFF`
        : 'Checkout externo seguro con CoinPal';
      const isSelected = selection.mode === 'binance';
      const binanceCornerMarkup = paymentMethodPublicCornerMarkup(String(binancePayCornerImageUrl || '').trim());
      const binanceMarkup = paymentMethodPublicCardContent(String(binancePayImageUrl || '').trim(), binancePayButtonLabel, binanceMeta);
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
      const paypalMarkup = paymentMethodPublicCardContent(String(paypalPayImageUrl || '').trim(), paypalPayButtonLabel, paypalMeta);
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
      const pointsMarkup = paymentMethodPublicCardContent(pointsImageUrl, winPointsState.name || 'Win Points', pointsMeta);
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

    if (selection.mode === 'binance_pagonorte') {
      paymentMethodCatalogCopy.textContent = hasPack
        ? 'Seleccionado: Binance. Confirmarás con referencia y teléfono después de ver los datos y el QR en USDT.'
        : 'Seleccionado: Binance. Mostraremos los precios en USDT mientras eliges el paquete.';
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

  function paymentBinancePagonorteAccordionMarkup() {
    const pricing = resolvePaymentPricing('binance_pagonorte', null);
    const totalText = escapePaymentHtml(String(pricing.totalText || ''));
    const totalMarkup = totalText !== ''
      ? `<div class="payment-mode-item-currency">Total esperado en USDT: ${totalText}</div>`
      : '';
    const discountMarkup = pricing.discountPercentage > 0
      ? `<div class="payment-mode-item-currency">Descuento disponible: ${escapePaymentHtml(formatDiscountPercentage(pricing.discountPercentage))}</div>`
      : '';
    const transferCopy = escapePaymentHtml(String(binancePagonorteTransferData || 'Realiza el pago en Binance y luego confirma tu referencia para validarla automáticamente.')).replace(/\n/g, '<br>');
    return `<div class="payment-mode-item-card payment-mode-item-card-points"><div class="payment-mode-item-card-title">${escapePaymentHtml(binancePagonorteButtonLabel)}</div>${totalMarkup}${discountMarkup}<div class="payment-mode-item-details">${transferCopy}</div></div>`;
  }

  function paymentPayPalAccordionMarkup() {
    const pricing = resolvePaymentPricing('paypal', null);
    const totalText = escapePaymentHtml(String(pricing.totalText || ''));
    const totalMarkup = totalText !== ''
      ? `<div class="payment-mode-item-currency">Total estimado en PayPal: ${totalText}</div>`
      : '';
    const taxMarkup = pricing.taxPercentage > 0
      ? `<div class="payment-mode-item-currency">Impuesto PayPal: ${escapePaymentHtml(formatDiscountPercentage(pricing.taxPercentage))}</div>`
      : '';
    return `<div class="payment-mode-item-card payment-mode-item-card-points"><div class="payment-mode-item-card-title">${escapePaymentHtml(paypalPayButtonLabel)}</div>${totalMarkup}${taxMarkup}<div class="payment-mode-item-details">Paga con tu cuenta, saldo o tarjeta a través del checkout oficial de PayPal. Abriremos una ventana externa y esta pantalla seguirá sincronizando el estado del pedido hasta que la confirmación quede registrada.</div></div>`;
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
    const binancePagonorteHtml = activePaymentOrder.canUseBinancePagonorte
      ? `<div class="payment-mode-item" data-payment-option="binance_pagonorte"><button type="button" class="payment-mode-btn" data-payment-option="binance_pagonorte" aria-expanded="false"><span class="payment-mode-btn-main"><span class="payment-mode-btn-radio" aria-hidden="true"></span><span class="payment-mode-btn-text"><span class="payment-mode-btn-title">${escapePaymentHtml(binancePagonorteButtonLabel)}</span><span class="payment-mode-btn-meta">${escapePaymentHtml(resolvePaymentModeDiscountPercentage('binance_pagonorte', null) > 0 ? `USDT con verificación automática · ${formatDiscountPercentage(resolvePaymentModeDiscountPercentage('binance_pagonorte', null))} OFF` : 'USDT con verificación automática por referencia')}</span></span></span><span class="payment-mode-btn-caret" aria-hidden="true"></span></button><div class="payment-mode-item-body"><div class="payment-mode-item-body-inner">${paymentBinancePagonorteAccordionMarkup()}</div></div></div>`
      : '';
    const binanceHtml = activePaymentOrder.canUseBinance
      ? `<div class="payment-mode-item" data-payment-option="binance"><button type="button" class="payment-mode-btn" data-payment-option="binance" aria-expanded="false"><span class="payment-mode-btn-main"><span class="payment-mode-btn-radio" aria-hidden="true"></span><span class="payment-mode-btn-text"><span class="payment-mode-btn-title">${escapePaymentHtml(binancePayButtonLabel)}</span><span class="payment-mode-btn-meta">${escapePaymentHtml(resolvePaymentModeDiscountPercentage('binance', null) > 0 ? `Checkout externo seguro con CoinPal · ${formatDiscountPercentage(resolvePaymentModeDiscountPercentage('binance', null))} OFF` : 'Checkout externo seguro con CoinPal')}</span></span></span><span class="payment-mode-btn-caret" aria-hidden="true"></span></button><div class="payment-mode-item-body"><div class="payment-mode-item-body-inner">${paymentBinanceAccordionMarkup()}</div></div></div>`
      : '';
    const paypalHtml = activePaymentOrder.canUsePayPal
      ? `<div class="payment-mode-item" data-payment-option="paypal"><button type="button" class="payment-mode-btn" data-payment-option="paypal" aria-expanded="false"><span class="payment-mode-btn-main"><span class="payment-mode-btn-radio" aria-hidden="true"></span><span class="payment-mode-btn-text"><span class="payment-mode-btn-title">${escapePaymentHtml(paypalPayButtonLabel)}</span><span class="payment-mode-btn-meta">Checkout oficial seguro con captura automática</span></span></span><span class="payment-mode-btn-caret" aria-hidden="true"></span></button><div class="payment-mode-item-body"><div class="payment-mode-item-body-inner">${paymentPayPalAccordionMarkup()}</div></div></div>`
      : '';
    const pointsMeta = escapePaymentHtml(formatWinPointsAmount(winPointsState.balance || 0));
    const pointsHtml = `<div class="payment-mode-item" data-payment-option="points"><button type="button" class="payment-mode-btn" data-payment-option="points" aria-expanded="false"><span class="payment-mode-btn-main"><span class="payment-mode-btn-radio" aria-hidden="true"></span><span class="payment-mode-btn-text"><span class="payment-mode-btn-title">${escapePaymentHtml(paymentPointsOptionLabel(hasRule, requiredPoints))}</span><span class="payment-mode-btn-meta">Saldo disponible: ${pointsMeta}</span></span></span><span class="payment-mode-btn-caret" aria-hidden="true"></span></button><div class="payment-mode-item-body"><div class="payment-mode-item-body-inner">${paymentPointsAccordionMarkup()}</div></div></div>`;

    paymentModeOptions.innerHTML = `${buttonsHtml}${binancePagonorteHtml}${binanceHtml}${paypalHtml}${showPointsOption ? pointsHtml : ''}`;
    getPaymentModeButtons().forEach((button) => {
      button.addEventListener('click', function() {
        const buttonMode = resolveCheckoutPaymentModeFromOption(button.dataset.paymentOption);
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
    const canUseBinancePagonorte = !!activePaymentOrder.canUseBinancePagonorte;
    const canUseBinance = !!activePaymentOrder.canUseBinance;
    const canUsePayPal = !!activePaymentOrder.canUsePayPal;
    const canUsePoints = !!activePaymentOrder.canUsePoints;
    let nextMode = normalizeCheckoutPaymentMode(mode);

    activePaymentOrder.selectedMethodId = selectedMethod ? String(selectedMethod.id) : '';

    if (nextMode === 'points' && !canUsePoints) {
      nextMode = canUseMoney ? 'money' : (canUseBinancePagonorte ? 'binance_pagonorte' : (canUseBinance ? 'binance' : 'points'));
    }
    if (nextMode === 'binance_pagonorte' && !canUseBinancePagonorte) {
      nextMode = canUseMoney ? 'money' : (canUseBinance ? 'binance' : (canUsePayPal ? 'paypal' : (canUsePoints ? 'points' : 'binance_pagonorte')));
    }
    if (nextMode === 'binance' && !canUseBinance) {
      nextMode = canUseMoney ? 'money' : (canUseBinancePagonorte ? 'binance_pagonorte' : (canUsePayPal ? 'paypal' : (canUsePoints ? 'points' : 'binance')));
    }
    if (nextMode === 'paypal' && !canUsePayPal) {
      nextMode = canUseMoney ? 'money' : (canUseBinancePagonorte ? 'binance_pagonorte' : (canUseBinance ? 'binance' : (canUsePoints ? 'points' : 'paypal')));
    }
    if (nextMode === 'money' && !canUseMoney) {
      nextMode = canUseBinancePagonorte ? 'binance_pagonorte' : (canUseBinance ? 'binance' : (canUsePayPal ? 'paypal' : (canUsePoints ? 'points' : 'money')));
    }

    activePaymentOrder.paymentMode = nextMode;
    const usingPoints = nextMode === 'points';
    const usingBinancePagonorte = nextMode === 'binance_pagonorte';
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
      const buttonMode = resolveCheckoutPaymentModeFromOption(button.dataset.paymentOption);
      const buttonMethodId = button.dataset.methodId || '';
      const isSelected = buttonMode === 'points'
        ? usingPoints
        : (buttonMode === 'binance_pagonorte'
          ? usingBinancePagonorte
        : (buttonMode === 'binance'
          ? usingBinance
          : (buttonMode === 'paypal'
            ? usingPayPal
            : (!usingPoints && !usingBinancePagonorte && !usingBinance && !usingPayPal && String(buttonMethodId) === String(activePaymentOrder.selectedMethodId || '')))));
      const isExpanded = paymentOptionKey(buttonMode, buttonMethodId) === String(activePaymentOrder.expandedPaymentOptionKey || '');
      const buttonItem = button.closest('.payment-mode-item');
      button.classList.toggle('is-active', isSelected);
      if (buttonItem) {
        buttonItem.classList.toggle('is-selected', isSelected);
        buttonItem.classList.toggle('is-expanded', isExpanded);
      }
      button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      button.disabled = buttonMode === 'points' ? !canUsePoints : (buttonMode === 'binance_pagonorte' ? !canUseBinancePagonorte : (buttonMode === 'binance' ? !canUseBinance : (buttonMode === 'paypal' ? !canUsePayPal : !canUseMoney)));
    });
    if (paymentReferenceGroup) {
      paymentReferenceGroup.classList.toggle('d-none', usingPoints || usingBinance || usingPayPal);
    }
    if (paymentPhoneGroup) {
      paymentPhoneGroup.classList.toggle('d-none', usingPoints || usingBinance || usingPayPal);
    }
    if (paymentMoneyPanel) {
      paymentMoneyPanel.classList.toggle('is-active', !usingPoints && (canUseMoney || canUseBinancePagonorte || canUseBinance || canUsePayPal));
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

  function resolveWinPointsRewardsCopy(canUseBinancePagonorte, canUseBinance, canUsePayPal) {
    if (canUseBinancePagonorte && canUseBinance && canUsePayPal) {
      return 'Elige si deseas completar esta orden con transferencia, Binance, Binance Pay, PayPal o con tus premios acumulados.';
    }
    if (canUseBinancePagonorte && canUseBinance) {
      return 'Elige si deseas completar esta orden con transferencia, Binance o Binance Pay o con tus premios acumulados.';
    }
    if (canUseBinancePagonorte && canUsePayPal) {
      return 'Elige si deseas completar esta orden con transferencia, Binance o PayPal o con tus premios acumulados.';
    }
    if (canUseBinancePagonorte) {
      return 'Elige si deseas completar esta orden con transferencia, Binance o con tus premios acumulados.';
    }
    if (canUseBinance && canUsePayPal) {
      return 'Elige si deseas completar esta orden con transferencia, Binance Pay, PayPal o con tus premios acumulados.';
    }
    if (canUseBinance) {
      return 'Elige si deseas completar esta orden con transferencia, Binance Pay o con tus premios acumulados.';
    }
    if (canUsePayPal) {
      return 'Elige si deseas completar esta orden con transferencia, PayPal o con tus premios acumulados.';
    }

    return 'Elige si deseas completar esta orden con transferencia o con tus premios acumulados.';
  }

  function resolveWinPointsManualCopy(canUseBinancePagonorte, canUseBinance, canUsePayPal) {
    if (canUseBinancePagonorte && canUseBinance && canUsePayPal) {
      return 'Elige si deseas completar esta orden manualmente, con Binance, Binance Pay o con PayPal.';
    }
    if (canUseBinancePagonorte && canUseBinance) {
      return 'Elige si deseas completar esta orden manualmente, con Binance o con Binance Pay.';
    }
    if (canUseBinancePagonorte && canUsePayPal) {
      return 'Elige si deseas completar esta orden manualmente, con Binance o con PayPal.';
    }
    if (canUseBinancePagonorte) {
      return 'Elige si deseas completar esta orden manualmente o con Binance.';
    }
    if (canUseBinance && canUsePayPal) {
      return 'Elige si deseas completar esta orden manualmente, con Binance Pay o con PayPal.';
    }
    if (canUseBinance) {
      return 'Elige si deseas completar esta orden manualmente o con Binance Pay.';
    }
    if (canUsePayPal) {
      return 'Elige si deseas completar esta orden manualmente o con PayPal.';
    }

    return 'Elige el metodo con el que deseas completar esta orden.';
  }

  function resolvePreferredModeForPaymentState(preferredMode, showRewardsState, orderState) {
    const normalizedMode = String(preferredMode || '').trim();

    if (showRewardsState) {
      if (normalizedMode === 'points' && orderState.canUsePoints) {
        return 'points';
      }
      if (normalizedMode === 'binance_pagonorte' && orderState.canUseBinancePagonorte) {
        return 'binance_pagonorte';
      }
      if (normalizedMode === 'binance' && orderState.canUseBinance) {
        return 'binance';
      }
      if (normalizedMode === 'paypal' && orderState.canUsePayPal) {
        return 'paypal';
      }
      if (normalizedMode === 'money' && orderState.canUseMoney) {
        return 'money';
      }

      if (orderState.canUseMoney) {
        return 'money';
      }
      if (orderState.canUseBinancePagonorte) {
        return 'binance_pagonorte';
      }
      if (orderState.canUsePayPal) {
        return 'paypal';
      }
      if (orderState.canUsePoints) {
        return 'points';
      }

      return 'binance';
    }

    if (normalizedMode === 'binance_pagonorte' && orderState.canUseBinancePagonorte) {
      return 'binance_pagonorte';
    }
    if (normalizedMode === 'binance' && orderState.canUseBinance) {
      return 'binance';
    }
    if (normalizedMode === 'paypal' && orderState.canUsePayPal) {
      return 'paypal';
    }
    if (orderState.canUseMoney) {
      return 'money';
    }
    if (orderState.canUseBinancePagonorte) {
      return 'binance_pagonorte';
    }
    if (orderState.canUsePayPal) {
      return 'paypal';
    }

    return 'binance';
  }

  function resolveInitialOrderPaymentMode(preferredMode, hasMoneyMethod, canUseBinancePagonorte, canUseBinance, canUsePayPal, canUsePoints) {
    const normalizedMode = String(preferredMode || '').trim();

    if (normalizedMode === 'points' && canUsePoints) {
      return 'points';
    }
    if (normalizedMode === 'binance_pagonorte' && canUseBinancePagonorte) {
      return 'binance_pagonorte';
    }
    if (normalizedMode === 'binance' && canUseBinance) {
      return 'binance';
    }
    if (normalizedMode === 'paypal' && canUsePayPal) {
      return 'paypal';
    }
    if (normalizedMode === 'money' && hasMoneyMethod) {
      return 'money';
    }

    if (hasMoneyMethod) {
      return 'money';
    }
    if (canUseBinancePagonorte) {
      return 'binance_pagonorte';
    }
    if (canUsePayPal) {
      return 'paypal';
    }
    if (canUsePoints) {
      return 'points';
    }
    if (canUseBinance) {
      return 'binance';
    }

    return 'money';
  }

  function normalizeCheckoutPaymentMode(mode) {
    const normalizedMode = String(mode || '').trim();
    if (normalizedMode === 'points') {
      return 'points';
    }
    if (normalizedMode === 'binance_pagonorte') {
      return 'binance_pagonorte';
    }
    if (normalizedMode === 'binance') {
      return 'binance';
    }
    if (normalizedMode === 'paypal') {
      return 'paypal';
    }

    return 'money';
  }

  function resolveCheckoutPaymentModeFromOption(optionValue) {
    return normalizeCheckoutPaymentMode(optionValue);
  }

  function resolvePaymentLoadingTitle(mode) {
    if (mode === 'points') {
      return 'Canjeando premios...';
    }
    if (mode === 'binance') {
      return 'Abriendo Binance Pay...';
    }
    if (mode === 'paypal') {
      return 'Abriendo PayPal...';
    }

    return paymentSendingOrderContent.title || 'Enviando orden...';
  }

  function resolvePaymentLoadingMessage(mode) {
    if (mode === 'points') {
      return 'Estamos validando tu saldo y procesando la recarga con tus premios. No cierres esta ventana.';
    }
    if (mode === 'binance') {
      return 'Estamos creando el checkout externo de Binance Pay. No cierres esta ventana.';
    }
    if (mode === 'binance_pagonorte') {
      return 'Estamos validando tu referencia y comparando los movimientos de Binance. No cierres esta ventana.';
    }
    if (mode === 'paypal') {
      return 'Estamos creando el checkout oficial de PayPal. No cierres esta ventana.';
    }

    return paymentSendingOrderContent.message || 'Estamos registrando tu comprobante y procesando la orden según la moneda del pedido. No cierres esta ventana.';
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
    const canUseBinancePagonorte = canUseBinancePagonorteCheckout(pack);
    const canUseBinance = canUseBinanceCheckout(pack);
    const canUsePayPal = canUsePayPalCheckout(pack);
    const showRewardsState = !!(winPointsState.enabled && winPointsState.loggedIn);

    const resolvedMethod = resolveSelectedPaymentMethod(activePaymentOrder.currency, preferredCheckoutMethodId || (currentMethod ? currentMethod.id : ''));

    activePaymentOrder.canUseMoney = Boolean(resolvedMethod);
    activePaymentOrder.canUseBinancePagonorte = canUseBinancePagonorte;
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
        paymentWinPointsCopy.textContent = resolveWinPointsRewardsCopy(canUseBinancePagonorte, canUseBinance, canUsePayPal);
      }
      paymentWinPointsBalance.textContent = formatWinPointsAmount(currentBalance);
      paymentWinPointsBalance.classList.remove('d-none');
    } else {
      if (paymentWinPointsTitle) {
        paymentWinPointsTitle.textContent = 'Metodos de pago disponibles';
      }
      if (paymentWinPointsCopy) {
        paymentWinPointsCopy.textContent = resolveWinPointsManualCopy(canUseBinancePagonorte, canUseBinance, canUsePayPal);
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
    const resolvedPreferredMode = resolvePreferredModeForPaymentState(preferredMode, showRewardsState, activePaymentOrder);

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
        const effectiveProviderFlow = resolveDiscordAwareProviderFlow(data, providerFlow);
        const isAcceptedFlow = effectiveProviderFlow === 'accepted' || effectiveProviderFlow === 'tracking';
        const isCompletedFlow = effectiveProviderFlow === 'completed';
        const requiresManualReview = effectiveProviderFlow === 'manual_review' || effectiveProviderFlow === 'inventory_shortage' || (!isAcceptedFlow && !isCompletedFlow && hasProviderDetails);

        if (isCompletedFlow) {
          clearPaymentStatusPolling();
          const successNote = buildBloodStrikeEliteDiscordSuccessNote(data);
          setPaymentAlert(paidMessage, 'success', { extraMessage: successNote });
          clearPaymentSupportUi();
          setPaymentFormDisabled(true);
          clearPaymentTimer();
          setCancelOrderButtonMode('close');
          showPaymentStatusModal('Operación exitosa', paidMessage, 'success', { extraMessage: successNote });
          return;
        }

        if (!isAcceptedFlow) {
          clearPaymentStatusPolling();
          const paidNote = requiresManualReview ? '' : buildBloodStrikeEliteDiscordSuccessNote(data);
          setPaymentAlert(paidMessage, requiresManualReview ? 'warning' : 'success', { extraMessage: paidNote });
          if (effectiveProviderFlow === 'inventory_shortage') {
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
        const effectiveProviderFlow = resolveDiscordAwareProviderFlow(data, providerFlow);
        const isAcceptedFlow = effectiveProviderFlow === 'accepted' || effectiveProviderFlow === 'tracking';
        const isCompletedFlow = effectiveProviderFlow === 'completed';
        const requiresManualReview = effectiveProviderFlow === 'manual_review' || effectiveProviderFlow === 'inventory_shortage' || (!isAcceptedFlow && !isCompletedFlow && hasProviderDetails);

        if (isCompletedFlow) {
          clearPaymentStatusPolling();
          const successNote = buildBloodStrikeEliteDiscordSuccessNote(data);
          setPaymentAlert(paidMessage, 'success', { extraMessage: successNote });
          clearPaymentSupportUi();
          setPaymentFormDisabled(true);
          clearPaymentTimer();
          setCancelOrderButtonMode('close');
          showPaymentStatusModal('Operación exitosa', paidMessage, 'success', { extraMessage: successNote });
          return;
        }

        if (!isAcceptedFlow) {
          clearPaymentStatusPolling();
          const paidNote = requiresManualReview ? '' : buildBloodStrikeEliteDiscordSuccessNote(data);
          setPaymentAlert(paidMessage, requiresManualReview ? 'warning' : 'success', { extraMessage: paidNote });
          if (effectiveProviderFlow === 'inventory_shortage') {
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

  function triggerPayPalReturnSync(payload = {}) {
    if (!activePaymentOrder || !activePaymentOrder.orderId) {
      return;
    }

    const safePayload = payload && typeof payload === 'object' ? payload : {};
    const payloadOrderId = Number(safePayload.order_id || 0);
    if (payloadOrderId > 0 && payloadOrderId !== Number(activePaymentOrder.orderId)) {
      return;
    }

    const bridgeState = String(safePayload.state || '').trim().toLowerCase();
    if (bridgeState === 'cancelado') {
      setPaymentAlert('PayPal informó que el checkout fue cancelado. Estamos actualizando el pedido.', 'warning');
    } else {
      setPaymentAlert('PayPal devolvió el checkout. Estamos confirmando el resultado final del pago.', 'info');
    }

    clearPaymentStatusPolling();
    setPaymentStatusWaiting(true);
    pollOrderResolution(
      String(safePayload.paypal_order_id || activePaymentOrder.orderId || '').trim(),
      String(activePaymentOrder.confirmedTotalText || getConfirmedPaymentTotalText() || '').trim(),
      1
    );
  }

  window.addEventListener('message', (event) => {
    const message = event && event.data && typeof event.data === 'object' ? event.data : null;
    if (!message || String(message.source || '').trim() !== 'paypal-checkout') {
      return;
    }

    triggerPayPalReturnSync(message.payload && typeof message.payload === 'object' ? message.payload : {});
  });

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

  function extractDiscordOrderStatus(data) {
    const discordPayload = data && typeof data === 'object' && data.discord && typeof data.discord === 'object'
      ? data.discord
      : null;
    const status = String((discordPayload && discordPayload.status) || '').trim().toLowerCase();
    if (['ready', 'queued', 'sent', 'processing', 'confirmed', 'failed', 'review', 'cancelled'].includes(status)) {
      return status;
    }
    return '';
  }

  function resolveDiscordAwareProviderFlow(data, fallbackFlow) {
    const discordStatus = extractDiscordOrderStatus(data);
    if (discordStatus === '') {
      return String(fallbackFlow || '').toLowerCase();
    }

    if (discordStatus === 'confirmed') {
      return 'completed';
    }
    if (discordStatus === 'processing' || discordStatus === 'queued') {
      return 'tracking';
    }
    if (discordStatus === 'sent') {
      return 'accepted';
    }
    if (discordStatus === 'cancelled') {
      return 'cancelled';
    }
    if (discordStatus === 'failed' || discordStatus === 'review' || discordStatus === 'ready') {
      return 'manual_review';
    }

    return String(fallbackFlow || '').toLowerCase();
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
  function binancePagonorteReferencePlaceholder() {
    const digits = Number(binancePagonorteReferenceDigits || 0);
    if (digits > 0) {
      return `Inserte todos o los últimos ${digits} dígitos de referencia`;
    }
    return 'Inserte su número de referencia para comprobar el pago';
  }

  function binancePagonorteReferenceHelpText() {
    const digits = Number(binancePagonorteReferenceDigits || 0);
    if (digits > 0) {
      return `Puedes escribir la referencia completa o solo los últimos ${digits} dígitos para compararla con los movimientos de Binance.`;
    }
    return 'Inserte su número de referencia para comprobar el pago en Binance.';
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

  function filterCheckoutReasons(data, genericPatterns = []) {
    const checkoutUrl = String((data && data.checkout_url) || '').trim();
    const normalizedPatterns = Array.isArray(genericPatterns)
      ? genericPatterns.filter((pattern) => pattern instanceof RegExp)
      : [];

    return extractPaymentReasons(data).filter((reason) => {
      const normalizedReason = String(reason || '').trim();
      if (normalizedReason === '') {
        return false;
      }

      if (checkoutUrl !== '' && normalizedReason === checkoutUrl) {
        return false;
      }

      return !normalizedPatterns.some((pattern) => pattern.test(normalizedReason));
    });
  }

  function filterBinanceReasons(data) {
    return filterCheckoutReasons(data, [
      /completa el pago en binance pay/i,
      /checkout externo de coinpal/i,
      /abrir binance pay/i,
    ]);
  }

  function filterPayPalReasons(data) {
    return filterCheckoutReasons(data, [
      /completa el pago en paypal/i,
      /checkout oficial de paypal/i,
      /abrir paypal/i,
      /payer_action_required/i,
      /la orden de paypal qued[oó] en estado payer_action_required\./i,
    ]);
  }

  function canSwitchFromBinanceToOtherPaymentMode() {
    if (!activePaymentOrder) {
      return false;
    }

    const availableModes = [
      !!activePaymentOrder.canUseMoney,
      !!activePaymentOrder.canUseBinancePagonorte,
      !!activePaymentOrder.canUseBinance,
      !!activePaymentOrder.canUsePayPal,
      !!activePaymentOrder.canUsePoints,
    ].filter(Boolean).length;

    return availableModes > 1;
  }

  function switchFromBinanceToOtherPaymentMode() {
    if (!activePaymentOrder) {
      return;
    }

    clearPaymentStatusPolling();
    setOverlayVisible(paymentStatusModal, false);
    setPaymentFormDisabled(false);
    clearPaymentSupportUi();

    const currentMode = String(activePaymentOrder.paymentMode || 'money');
    let nextMode = currentMode;

    if (currentMode !== 'money' && activePaymentOrder.canUseMoney) {
      nextMode = 'money';
    } else if (currentMode !== 'binance_pagonorte' && activePaymentOrder.canUseBinancePagonorte) {
      nextMode = 'binance_pagonorte';
    } else if (currentMode !== 'paypal' && activePaymentOrder.canUsePayPal) {
      nextMode = 'paypal';
    } else if (currentMode !== 'binance' && activePaymentOrder.canUseBinance) {
      nextMode = 'binance';
    } else if (currentMode !== 'points' && activePaymentOrder.canUsePoints) {
      nextMode = 'points';
    }

    setActivePaymentMode(nextMode, activePaymentOrder.selectedMethodId, { expandSelected: true });
    setCancelOrderButtonMode('cancel');
    setPaymentAlert('', 'info');
    scrollPaymentSubmitIntoView();
  }

  function openBinanceCancellationFlow() {
    clearPaymentStatusPolling();
    setOverlayVisible(paymentStatusModal, false);
    if (activePaymentOrder && paymentCancelConfirmModal) {
      setOverlayVisible(paymentCancelConfirmModal, true);
    }
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

  function resolveCopyableAmountValue(totalText) {
    const rawText = String(totalText || '').trim();
    if (rawText === '') {
      return '';
    }

    const matches = rawText.match(/\d[\d.,]*/g);
    const numericChunk = Array.isArray(matches) && matches.length > 0
      ? String(matches[matches.length - 1] || '').trim()
      : '';
    if (numericChunk === '') {
      return '';
    }

    const sanitized = numericChunk.replace(/[^\d.,]/g, '');
    if (sanitized === '') {
      return '';
    }

    const lastComma = sanitized.lastIndexOf(',');
    const lastDot = sanitized.lastIndexOf('.');
    const separatorIndex = Math.max(lastComma, lastDot);
    const digitsOnly = sanitized.replace(/\D+/g, '');
    if (separatorIndex === -1) {
      return digitsOnly;
    }

    const fractionPart = sanitized.slice(separatorIndex + 1).replace(/\D+/g, '');
    const integerPart = sanitized.slice(0, separatorIndex).replace(/\D+/g, '');
    const commaCount = (sanitized.match(/,/g) || []).length;
    const dotCount = (sanitized.match(/\./g) || []).length;
    const hasMixedSeparators = commaCount > 0 && dotCount > 0;
    const shouldKeepDecimals = fractionPart !== '' && (hasMixedSeparators || fractionPart.length <= 2);

    if (!shouldKeepDecimals) {
      return digitsOnly;
    }

    return `${integerPart !== '' ? integerPart : '0'}.${fractionPart}`;
  }

  function updatePaymentSummaryCopyButtons(totalText) {
    const copyValue = resolveCopyableAmountValue(totalText);
    [paymentSummaryTotalCopyButton, paymentSummaryMinimalTotalCopyButton].forEach((button) => {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }
      button.dataset.paymentCopyAmount = copyValue;
      button.disabled = copyValue === '';
    });
  }

  function bindPaymentSummaryCopyButton(button) {
    if (!(button instanceof HTMLButtonElement) || button.dataset.copyBound === '1') {
      return;
    }

    button.dataset.copyBound = '1';
    button.addEventListener('click', async () => {
      const copyValue = String(button.dataset.paymentCopyAmount || '').trim();
      if (copyValue === '') {
        return;
      }

      try {
        const copied = await copyTextToClipboard(copyValue);
        if (typeof showToast === 'function') {
          showToast(copied ? 'Monto copiado.' : 'No se pudo copiar el monto.', copied ? 'success' : 'danger');
        }
      } catch (_) {
        if (typeof showToast === 'function') {
          showToast('No se pudo copiar el monto.', 'danger');
        }
      }
    });
  }

  bindPaymentSummaryCopyButton(paymentSummaryTotalCopyButton);
  bindPaymentSummaryCopyButton(paymentSummaryMinimalTotalCopyButton);

  function paymentSummaryCopyIconMarkup() {
    return '<span class="payment-summary-copy-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" role="img"><rect x="9" y="7" width="10" height="12" rx="2"></rect><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"></path></svg></span>';
  }

  function encodePaymentCopyText(value) {
    return encodeURIComponent(String(value || ''));
  }

  function decodePaymentCopyText(value) {
    try {
      return decodeURIComponent(String(value || ''));
    } catch (_) {
      return String(value || '');
    }
  }

  function normalizePaymentTransferRawText(value) {
    return String(value || '').replace(/\r\n?/g, '\n');
  }

  function paymentTransferDisplayLines(rawText) {
    return normalizePaymentTransferRawText(rawText)
      .split('\n')
      .map((line) => String(line || '').trim())
      .filter(Boolean);
  }

  function paymentTransferLineParts(rawLine) {
    const normalizedLine = String(rawLine || '')
      .replace(/^[\s\u2705\u2714\u25AA\u25CF\u2022\uFE0F\u{1F300}-\u{1FAFF}]+/gu, '')
      .trim();
    const colonIndex = normalizedLine.indexOf(':');
    if (colonIndex === -1) {
      return {
        label: '',
        value: normalizedLine,
      };
    }

    return {
      label: normalizedLine.slice(0, colonIndex).trim(),
      value: normalizedLine.slice(colonIndex + 1).trim(),
    };
  }

  function resolvePaymentTransferCopyValue(rawLine) {
    const parts = paymentTransferLineParts(rawLine);
    const label = normalizePaymentContextText(parts.label);
    const rawValue = String(parts.value || '').trim();
    const compactDigits = rawValue.replace(/\D+/g, '');
    const compactAlphaNumeric = rawValue.replace(/[^0-9a-z]/gi, '').toUpperCase();

    if (rawValue === '') {
      return String(rawLine || '').trim();
    }

    if (/(telefono|celular|movil|whatsapp|phone)/.test(label)) {
      return compactDigits || rawValue;
    }

    if (/(cedula|documento|dni|identidad|identificacion|rif)/.test(label)) {
      return compactAlphaNumeric || rawValue;
    }

    if (/(binance)/.test(label) && /\bid\b/.test(label)) {
      return compactAlphaNumeric || rawValue.replace(/\s+/g, '');
    }

    if (/(referencia|reference|cuenta|account|iban|clabe|cvu|wallet)/.test(label)) {
      return compactAlphaNumeric || rawValue;
    }

    if (rawValue.includes('@')) {
      return rawValue.replace(/\s+/g, '');
    }

    if (/^[\d\s\-+().]+$/.test(rawValue)) {
      return compactDigits || rawValue;
    }

    return rawValue;
  }

  function buildPaymentTransferCopyMarkup(rawText, options = {}) {
    const normalizedRawText = normalizePaymentTransferRawText(rawText);
    const lines = paymentTransferDisplayLines(normalizedRawText);
    const noteText = String((options && options.noteText) || '').trim();
    const emptyText = String((options && options.emptyText) || 'No hay datos de transferencia disponibles.').trim();
    const copyAllLabel = String((options && options.copyAllLabel) || 'Copiar todos los datos').trim();

    if (!lines.length) {
      return `<p>${escapePaymentHtml(emptyText)}</p>`;
    }

    const rowsMarkup = lines.map((line) => {
      const copyValue = resolvePaymentTransferCopyValue(line);
      return `
        <div class="payment-transfer-copy-row">
          <div class="payment-transfer-copy-line">${escapePaymentHtml(line)}</div>
          <button type="button" class="payment-summary-copy-btn payment-transfer-copy-btn" aria-label="Copiar dato" title="Copiar dato" data-copy-tooltip="Copiar dato" data-payment-copy-text="${escapePaymentHtml(encodePaymentCopyText(copyValue))}">${paymentSummaryCopyIconMarkup()}</button>
        </div>`;
    }).join('');

    return `
      <div class="payment-transfer-copy-list">${rowsMarkup}</div>
      <div class="payment-transfer-copy-actions">
        <button type="button" class="btn btn-info fw-bold w-100 payment-transfer-copy-all-btn" data-payment-copy-text="${escapePaymentHtml(encodePaymentCopyText(normalizedRawText))}">${escapePaymentHtml(copyAllLabel)}</button>
      </div>
      ${noteText !== '' ? `<p class="payment-transfer-copy-note mb-0">${escapePaymentHtml(noteText)}</p>` : ''}`;
  }

  function buildPaymentInfoCopyMarkup(lines, options = {}) {
    const normalizedLines = (Array.isArray(lines) ? lines : [])
      .map((line) => String(line || '').trim())
      .filter(Boolean);
    const copyAllLabel = String((options && options.copyAllLabel) || 'Copiar información').trim();
    if (!normalizedLines.length) {
      return '<p>No hay información disponible.</p>';
    }

    const copyAllText = normalizedLines.join('\n');
    return `
      <div>
        ${normalizedLines.map((line) => `<p>${escapePaymentHtml(line)}</p>`).join('')}
        <div class="payment-transfer-copy-actions">
          <button type="button" class="btn btn-info fw-bold w-100 payment-transfer-copy-all-btn" data-payment-copy-text="${escapePaymentHtml(encodePaymentCopyText(copyAllText))}">${escapePaymentHtml(copyAllLabel)}</button>
        </div>
      </div>`;
  }

  function bindPaymentTransferCopyButtons(container) {
    if (!container) {
      return;
    }

    container.querySelectorAll('[data-payment-copy-text]').forEach((button) => {
      button.addEventListener('click', async () => {
        const copyValue = decodePaymentCopyText(button.getAttribute('data-payment-copy-text') || '');
        try {
          const copied = await copyTextToClipboard(copyValue);
          showToast(copied ? 'Dato copiado.' : 'No se pudo copiar el dato.', copied ? 'success' : 'error');
        } catch (_) {
          showToast('No se pudo copiar el dato.', 'error');
        }
      });
    });
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
      <button type="button" class="payment-summary-copy-btn payment-copy-code-btn" aria-label="${escapePaymentHtml(copyLabel)}" title="${escapePaymentHtml(copyLabel)}" data-copy-tooltip="${escapePaymentHtml(copyLabel)}">${paymentSummaryCopyIconMarkup()}</button>
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
        ${accountText !== '' ? '<button type="button" class="payment-summary-copy-btn account-sale-copy-btn" aria-label="Copiar datos de la cuenta" title="Copiar datos de la cuenta" data-copy-tooltip="Copiar datos de la cuenta">' + paymentSummaryCopyIconMarkup() + '</button>' : ''}
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
          'La recarga llegará al instante luego de unos segundos al confirmar el pedido.'
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
    const mode = normalizeCheckoutPaymentMode(options.mode);

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

    if (mode === 'binance_pagonorte') {
      const pricing = resolvePaymentPricing('binance_pagonorte', null);
      paymentMethodTitle.textContent = String(binancePagonorteButtonLabel || 'Binance');
      paymentMethodCurrency.textContent = pricing.totalText ? `Total esperado en USDT: ${pricing.totalText}` : 'Pago en USDT con verificación automática';
      paymentMethodDetails.classList.add('payment-method-details-rich');
      if (String(binancePagonorteTransferData || '').trim() !== '') {
        paymentMethodDetails.innerHTML = buildPaymentTransferCopyMarkup(binancePagonorteTransferData, {
          noteText: 'Después de pagar, escribe tu referencia y tu teléfono de contacto para comparar el movimiento y aprobar la recarga.',
          copyAllLabel: 'Copiar todos los datos de Binance'
        });
        bindPaymentTransferCopyButtons(paymentMethodDetails);
      } else {
        paymentMethodDetails.innerHTML = `
          <div>
            <p>Realiza tu transferencia en Binance, luego confirma la referencia para validar el movimiento automáticamente.</p>
            <p class="mt-2 mb-0">Después de pagar, escribe tu referencia y tu teléfono de contacto para comparar el movimiento y aprobar la recarga.</p>
          </div>`;
      }
      setPaymentMethodQrState(String(binancePagonorteQrImageUrl || '').trim(), 'QR para Binance');
      paymentReferenceInput.placeholder = binancePagonorteReferencePlaceholder();
      paymentReferenceHelp.textContent = binancePagonorteReferenceHelpText();
      paymentReferenceInput.maxLength = 120;
      paymentReferenceInput.dataset.requiredDigits = '0';
      return;
    }

    if (mode === 'binance') {
      const pricing = resolvePaymentPricing('binance', null);
      const binanceMoney = resolveBinanceDisplayMoney(activePaymentOrder && activePaymentOrder.pack ? activePaymentOrder.pack : null, pricing.totalAmount);
      const totalLabel = String((binanceMoney && binanceMoney.text) || pricing.totalText || '').trim();
      const binanceInfoLines = [
        'Paga de forma segura desde CoinPal usando tu cuenta o QR de Binance Pay.',
        'La orden ya se abrirá con Binance Pay seleccionado desde el paso anterior.',
        'Al confirmar, abriremos el checkout externo y esta ventana seguirá monitoreando la confirmación.',
        'Si el checkout no se abre automáticamente, el sistema mostrará la opción para reintentarlo.'
      ];
      paymentMethodTitle.textContent = String(binancePayButtonLabel || 'Binance Pay');
      paymentMethodCurrency.textContent = totalLabel !== '' ? `Total estimado en Binance Pay: ${totalLabel}` : 'Checkout externo seguro con CoinPal';
      paymentMethodDetails.classList.add('payment-method-details-rich');
      paymentMethodDetails.innerHTML = buildPaymentInfoCopyMarkup(binanceInfoLines, {
        copyAllLabel: 'Copiar información de Binance Pay'
      });
      bindPaymentTransferCopyButtons(paymentMethodDetails);
      paymentReferenceInput.placeholder = paymentReferencePlaceholder(null);
      paymentReferenceHelp.textContent = paymentReferenceHelpText(null);
      paymentReferenceInput.maxLength = 120;
      paymentReferenceInput.dataset.requiredDigits = '0';
      return;
    }

    if (mode === 'paypal') {
      const pricing = resolvePaymentPricing('paypal', null);
      const payPalInfoLines = [
        'Te enviaremos al checkout oficial de PayPal para que autorices el pago con saldo, tarjeta o cuenta vinculada.',
        'La orden se abrirá en una ventana externa segura de PayPal.',
        'Al aprobar el pago, esta ventana seguirá sincronizando automáticamente el resultado.',
        'Si el checkout no se abre o lo cierras por error, el sistema mostrará la opción para reabrirlo.'
      ];
      paymentMethodTitle.textContent = String(paypalPayButtonLabel || 'PayPal');
      paymentMethodCurrency.textContent = pricing.totalText ? `Total estimado en PayPal: ${pricing.totalText}` : 'Checkout oficial seguro con PayPal';
      paymentMethodDetails.classList.add('payment-method-details-rich');
      paymentMethodDetails.innerHTML = buildPaymentInfoCopyMarkup(payPalInfoLines, {
        copyAllLabel: 'Copiar información de PayPal'
      });
      bindPaymentTransferCopyButtons(paymentMethodDetails);
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
    paymentMethodDetails.classList.add('payment-method-details-rich');
    paymentMethodDetails.innerHTML = buildPaymentTransferCopyMarkup(String(method.datos || ''), {
      copyAllLabel: 'Copiar todos los datos del método'
    });
    bindPaymentTransferCopyButtons(paymentMethodDetails);
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
    const canUseBinancePagonorte = canUseBinancePagonorteCheckout(pack);
    const canUseBinance = canUseBinanceCheckout(pack);
    const canUsePayPal = canUsePayPalCheckout(pack);
    if (!currentMethod && !canUsePoints && !canUseBinancePagonorte && !canUseBinance && !canUsePayPal) {
      showToast('No hay métodos de pago activos disponibles.', 'error');
      return false;
    }

    const safeRemainingSeconds = Number.isFinite(Number(remainingSeconds)) ? Math.max(0, Number(remainingSeconds)) : 1800;
    const resolvedInitialPaymentMode = resolveInitialOrderPaymentMode(
      preferredSelection.mode,
      Boolean(currentMethod),
      canUseBinancePagonorte,
      canUseBinance,
      canUsePayPal,
      canUsePoints
    );

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
      canUseBinancePagonorte,
      canUseBinance,
      canUsePayPal,
      canUsePoints,
      paymentMode: resolvedInitialPaymentMode,
      selectedMethodId: currentMethod ? String(currentMethod.id) : '',
      preferredMode: resolvedInitialPaymentMode,
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

                  const mode = resolveCheckoutPaymentModeFromOption(button.dataset.paymentOption);
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

                  const paymentMode = normalizeCheckoutPaymentMode(activePaymentOrder.paymentMode);
                  const methods = getPaymentMethodsForCurrency(activePaymentOrder.currency);
                  const selectedMethod = methods.find((method) => String(method.id) === String(activePaymentOrder.selectedMethodId || paymentMethodSelect.value)) || methods[0] || null;
                  if (paymentMode === 'money' && !selectedMethod) {
                    setPaymentAlert('No hay un método de pago disponible para esta orden.', 'danger');
                    return;
                  }

                  const requiresManualConfirmation = paymentMode === 'money' || paymentMode === 'binance_pagonorte';
                  const reference = requiresManualConfirmation ? paymentReferenceInput.value.trim() : '';
                  const phone = requiresManualConfirmation ? paymentPhoneInput.value.trim() : '';
                  const requiredDigits = Number(selectedMethod ? (selectedMethod.referencia_digitos || 0) : 0);

                  if (paymentMode === 'points' && !activePaymentOrder.canUsePoints) {
                    setPaymentAlert('Este paquete no tiene un canje disponible con tus premios en este momento.', 'danger');
                    return;
                  }
                  if (paymentMode === 'binance' && !activePaymentOrder.canUseBinance) {
                    setPaymentAlert('Binance Pay no está disponible para esta orden.', 'danger');
                    return;
                  }
                  if (paymentMode === 'binance_pagonorte' && !activePaymentOrder.canUseBinancePagonorte) {
                    setPaymentAlert('Binance no está disponible para esta orden.', 'danger');
                    return;
                  }
                  if (paymentMode === 'paypal' && !activePaymentOrder.canUsePayPal) {
                    setPaymentAlert('PayPal no está disponible para esta orden.', 'danger');
                    return;
                  }
                  if (requiresManualConfirmation && !reference) {
                    setPaymentAlert('Debes ingresar el número de referencia.', 'danger');
                    return;
                  }
                  if (paymentMode === 'money' && requiredDigits > 0 && reference.length !== requiredDigits) {
                    setPaymentAlert(`La referencia debe contener exactamente ${requiredDigits} dígitos.`, 'danger');
                    return;
                  }
                  if (paymentMode === 'binance_pagonorte' && Number(binancePagonorteReferenceDigits || 0) > 0 && reference.length < Number(binancePagonorteReferenceDigits || 0)) {
                    setPaymentAlert(`Debes escribir la referencia completa o al menos los últimos ${Number(binancePagonorteReferenceDigits || 0)} dígitos.`, 'danger');
                    return;
                  }
                  if (requiresManualConfirmation && !phone) {
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
                  const loadingTitle = resolvePaymentLoadingTitle(paymentMode);
                  const loadingMessage = resolvePaymentLoadingMessage(paymentMode);
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
                      const effectiveProviderFlow = resolveDiscordAwareProviderFlow(data, providerFlow);
                      const isAcceptedFlow = effectiveProviderFlow === 'accepted' || effectiveProviderFlow === 'tracking';
                      const isCompletedFlow = effectiveProviderFlow === 'completed';
                      const requiresManualReview = effectiveProviderFlow === 'manual_review' || (!isAcceptedFlow && !isCompletedFlow && hasProviderDetails);
                      const successPresentation = isAcceptedFlow ? successfulProviderPendingPresentation(effectiveProviderFlow, data) : null;
                      const paidNote = requiresManualReview ? '' : buildBloodStrikeEliteDiscordSuccessNote(data);

                      setPaymentAlert(
                        isCompletedFlow ? paidMessage : (successPresentation ? successPresentation.message : paidMessage),
                        requiresManualReview ? 'warning' : (isCompletedFlow ? 'success' : (successPresentation ? (successPresentation.statusType || 'info') : 'success')),
                        { extraMessage: paidNote }
                      );
                      if (!isCompletedFlow && (hasProviderDetails || effectiveProviderFlow === 'accepted' || effectiveProviderFlow === 'tracking')) {
                        renderProviderPaymentDetails(data, reference, getConfirmedPaymentTotalText());
                      } else {
                        clearPaymentSupportUi();
                      }
                      renderOverpaidPaymentDifference(data);
                      setPaymentFormDisabled(true);
                      clearPaymentTimer();
                      setCancelOrderButtonMode('close');
                      showPaymentStatusModal(
                        requiresManualReview ? 'Revisión requerida' : (isCompletedFlow ? 'Operación exitosa' : (successPresentation ? successPresentation.title : 'Operación exitosa')),
                        isCompletedFlow ? paidMessage : (successPresentation ? successPresentation.message : paidMessage),
                        requiresManualReview ? 'danger' : (isCompletedFlow ? 'success' : (successPresentation ? (successPresentation.statusType || 'info') : 'success')),
                        { extraMessage: paidNote }
                      );
                      if (effectiveProviderFlow === 'accepted' || effectiveProviderFlow === 'tracking') {
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
                const binancePagonorteCheckoutAvailable = canUseBinancePagonorteCheckout(pack);
                const binanceCheckoutAvailable = canUseBinanceCheckout(pack);
                const paypalCheckoutAvailable = canUsePayPalCheckout(pack);
                if (!paymentMethods.length && !pointsCheckoutAvailable && !binancePagonorteCheckoutAvailable && !binanceCheckoutAvailable && !paypalCheckoutAvailable) {
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
              </script>
            </section>
<?php
include __DIR__ . "/includes/footer.php";
?>
