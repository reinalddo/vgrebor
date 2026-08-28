<?php
require_once __DIR__ . "/includes/tenant.php";
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/store_config.php";
require_once __DIR__ . "/includes/currency.php";
require_once __DIR__ . "/includes/payment_methods.php";
require_once __DIR__ . "/includes/recargas_api.php";
require_once __DIR__ . "/includes/recargasamerica_api.php";
require_once __DIR__ . "/includes/api_discord.php";
require_once __DIR__ . "/includes/slugify.php";
require_once __DIR__ . "/includes/player_verification.php";
require_once __DIR__ . "/includes/paso_estilos.php";
require_once __DIR__ . "/includes/package_features.php";
require_once __DIR__ . "/includes/payment_difference.php";
require_once __DIR__ . "/includes/blocked_players.php";
require_once __DIR__ . "/includes/game_entry_window_per_game.php";
require_once __DIR__ . "/includes/win_points.php";
require_once __DIR__ . "/includes/binance_pay.php";
require_once __DIR__ . "/includes/paypal_pay.php";
require_once __DIR__ . "/includes/package_account_sales.php";
require_once __DIR__ . "/includes/bs_pass_stock.php";
require_once __DIR__ . "/includes/levelpass_api.php";
require_once __DIR__ . "/includes/fullimpulso_api.php";
require_once __DIR__ . "/includes/package_categories.php";
require_once __DIR__ . "/includes/referidos.php";

// Presentación del rediseño configurable de "PASO 1/2/3" y del verificador
// de jugador (includes/paso_estilos.php solo tiene los getters de config —
// armar el HTML/CSS es cosa de esta página, mismo criterio que
// ayuda_fab_style_attr()/ayuda_fab_icon_html() en includes/footer.php).
if (!function_exists('paso_estilo_css_inline')) {
    function paso_estilo_css_inline(string $zona): string {
        if (!paso_estilo_esta_personalizado($zona)) {
            return '';
        }
        $css = 'background:' . paso_estilo_fondo_css($zona) . ';'
             . 'color:' . paso_estilo_color_texto($zona) . ';'
             . 'font-size:' . paso_estilo_fuente_tamano($zona) . ';';
        $fuenteCss = paso_estilo_fuente_familia_css($zona);
        if ($fuenteCss !== '') {
            $css .= 'font-family:' . $fuenteCss . ';';
        }
        if (paso_estilo_borde_neon_activo($zona)) {
            $colorBorde = paso_estilo_color_borde($zona);
            $grosor = paso_estilo_borde_grosor($zona);
            $brillo = paso_estilo_borde_brillo($zona);
            $css .= 'border:' . $grosor . 'px solid ' . $colorBorde . ';box-shadow:0 0 ' . $brillo . 'px ' . $colorBorde . ', inset 0 0 ' . (int) round($brillo / 2) . 'px ' . $colorBorde . ';';
        } else {
            $css .= 'border:1px solid transparent;';
        }
        return ' style="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '"';
    }
}

if (!function_exists('paso_linea_class')) {
    function paso_linea_class(string $zona): string {
        return paso_estilo_esta_personalizado($zona) ? 'paso-linea paso-linea-custom' : 'paso-linea';
    }
}

// Texto real del título "PASO N: ..." — el editado por el admin si la zona
// está en modo personalizado, o el de siempre si sigue en modo original
// (el texto también se puede "perder" al volver a original, igual que el
// resto de los valores de esta zona).
if (!function_exists('paso_linea_texto')) {
    function paso_linea_texto(string $zona): string {
        if (paso_estilo_esta_personalizado($zona)) {
            return paso_estilo_texto($zona);
        }
        $defaults = paso_estilo_defaults($zona);
        return $defaults['texto'] ?? '';
    }
}

// La insignia ("PASO 1") y el resto de la frase van en 2 <span> separados,
// cada uno con su propia zona de estilo (paso1/paso1_resto, etc.) — pedido
// explícito del cliente ("tienen que tener dos recuadros"). Se listan las 2
// mitades ya calculadas para no repetir el parseo en cada título.
if (!function_exists('paso_linea_partes')) {
    function paso_linea_partes(string $zonaBase): array {
        return paso_estilo_partir_texto_paso(paso_linea_texto($zonaBase));
    }
}

// Ícono de una zona (botón/éxito/fallo) ya resuelto a HTML — un emoji plano
// o un <img> si el admin subió una imagen. '' si la zona está en "ninguno"
// o sigue en modo original (el original nunca lleva ícono).
if (!function_exists('paso_estilo_icono_render')) {
    function paso_estilo_icono_render(string $zona, string $claseImg = ''): string {
        if (!paso_estilo_esta_personalizado($zona)) {
            return '';
        }
        $clave = paso_estilo_icono_clave($zona);
        if ($clave === 'ninguno') {
            return '';
        }
        if ($clave === 'imagen') {
            $ruta = paso_estilo_icono_imagen($zona);
            if ($ruta === '') {
                return '';
            }
            $src = htmlspecialchars(app_path('/' . ltrim($ruta, '/')), ENT_QUOTES, 'UTF-8');
            $clase = $claseImg !== '' ? ' class="' . htmlspecialchars($claseImg, ENT_QUOTES, 'UTF-8') . '"' : '';
            return '<img src="' . $src . '" alt=""' . $clase . '>';
        }
        $emoji = paso_estilo_icono_emoji($zona);
        return $emoji !== '' ? htmlspecialchars($emoji, ENT_QUOTES, 'UTF-8') : '';
    }
}

// Texto efectivo del botón de verificación: el que escribió el admin (modo
// personalizado) o el label dinámico por juego de siempre (modo original —
// cada juego puede pedir un ID distinto, por eso ese texto no es fijo).
if (!function_exists('paso_estilo_boton_texto_efectivo')) {
    function paso_estilo_boton_texto_efectivo(?array $playerVerificationConfig): string {
        $textoDefecto = (string) ($playerVerificationConfig['buttonLabel'] ?? 'Verificar nombre del jugador');
        if (!paso_estilo_esta_personalizado('boton')) {
            return $textoDefecto;
        }
        $texto = paso_estilo_texto('boton');
        return $texto !== '' ? $texto : $textoDefecto;
    }
}

// Mismos datos que paso_estilo_css_inline() pero en array, para las 2 zonas
// que el JS necesita armar dinámicamente (éxito/fallo del verificador,
// según la respuesta real de la API) en vez de imprimirlas ya resueltas en
// el HTML — ver setPlayerVerificationFeedback() más abajo en este archivo.
// El ícono viaja como {tipo, valor} porque puede ser un emoji o la URL de
// una imagen subida — el JS decide si imprime texto o un <img>.
if (!function_exists('paso_estilo_js_config')) {
    function paso_estilo_js_config(string $zona): array {
        $iconoClave = paso_estilo_icono_clave($zona);
        $icono = ['tipo' => 'ninguno', 'valor' => ''];
        if ($iconoClave === 'imagen') {
            $ruta = paso_estilo_icono_imagen($zona);
            if ($ruta !== '') {
                $icono = ['tipo' => 'imagen', 'valor' => app_path('/' . ltrim($ruta, '/'))];
            }
        } elseif ($iconoClave !== 'ninguno') {
            $emoji = paso_estilo_icono_emoji($zona);
            if ($emoji !== '') {
                $icono = ['tipo' => 'emoji', 'valor' => $emoji];
            }
        }

        $config = [
            'personalizado' => paso_estilo_esta_personalizado($zona),
            'fondo' => paso_estilo_fondo_css($zona),
            'colorTexto' => paso_estilo_color_texto($zona),
            'fuenteFamilia' => paso_estilo_fuente_familia_css($zona),
            'fuenteTamano' => paso_estilo_fuente_tamano($zona),
            'bordeActivo' => paso_estilo_borde_neon_activo($zona),
            'colorBorde' => paso_estilo_color_borde($zona),
            'bordeGrosor' => paso_estilo_borde_grosor($zona),
            'bordeBrillo' => paso_estilo_borde_brillo($zona),
            'icono' => $icono,
            'badgeTexto' => paso_estilo_badge_texto($zona),
            'badgeColorFondo' => paso_estilo_badge_color_fondo($zona),
            'badgeColorTexto' => paso_estilo_badge_color_texto($zona),
        ];

        if ($zona === 'fallo') {
            $config['mensajeModo'] = paso_estilo_mensaje_modo($zona);
            $config['mensajePersonalizado'] = paso_estilo_mensaje_personalizado($zona);
        }

        return $config;
    }
}

currency_ensure_schema();
if (trim((string) store_config_get('binance_pagonorte_activo', '0')) === '1') {
  currency_ensure_code('USDT', 'Tether USD', 1.0, true, true);
}
package_features_ensure_schema($mysqli);
package_account_sales_ensure_schema($mysqli);
levelpass_ensure_schema($mysqli);
fullimpulso_ensure_schema($mysqli);
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
    'nombre' => '',
    'cedula' => '',
    'zone_id' => '',
  ];

  if ($userId <= 0) {
    return $defaults;
  }

  $stmt = $mysqli->prepare('SELECT last_purchase_user_identifier, last_purchase_phone, last_purchase_nombre, last_purchase_cedula, last_purchase_zone_id FROM usuarios WHERE id = ? LIMIT 1');
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
  $defaults['nombre'] = trim((string) ($row['last_purchase_nombre'] ?? ''));
  $defaults['cedula'] = trim((string) ($row['last_purchase_cedula'] ?? ''));
  $defaults['zone_id'] = trim((string) ($row['last_purchase_zone_id'] ?? ''));

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
$loggedUserLastPurchaseNombre = '';
$loggedUserLastPurchaseCedula = '';
$loggedUserLastPurchaseZoneId = '';
$loggedUserRole = '';
$canSimulateDailyMissionPurchase = false;
$paymentDifferenceEnabled = false;
$activePaymentDifferenceCredit = null;
tenant_start_session();
if (!empty($_SESSION['auth_user']['id'])) {
  $loggedUserId = (int) $_SESSION['auth_user']['id'];
}
if (!empty($_SESSION['auth_user']['email'])) {
  $rawEmail = (string) $_SESSION['auth_user']['email'];
  $loggedUserEmail = filter_var($rawEmail, FILTER_VALIDATE_EMAIL) ? $rawEmail : '';
}
$loggedUserRole = strtolower(trim((string) ($_SESSION['auth_user']['rol'] ?? '')));
$canSimulateDailyMissionPurchase = in_array($loggedUserRole, ['admin', 'root'], true);
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
    $loggedUserLastPurchaseZoneId = $legacyPurchaseDefaults['zone_id'];
  }
  $loggedUserLastPurchasePhone = $legacyPurchaseDefaults['phone'];
  $loggedUserLastPurchaseNombre = $legacyPurchaseDefaults['nombre'];
  $loggedUserLastPurchaseCedula = $legacyPurchaseDefaults['cedula'];

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
$winPointsMonthlyStatus = $winPointsEnabled && $loggedUserId > 0
  ? win_points_user_monthly_minimum_status($mysqli, $loggedUserId)
  : ['met' => true, 'spent' => 0.0, 'required' => 0.0, 'restricted' => false];
$winPointsPackageRewards = $winPointsEnabled
  ? win_points_fetch_game_package_rewards($mysqli, (int) ($game['id'] ?? 0))
  : [];
$winPointsRedemptionRules = $winPointsEnabled
  ? win_points_fetch_game_redemption_rules($mysqli, (int) ($game['id'] ?? 0))
  : [];
$gameHasAnyRedemptionRule = !empty(array_filter(
  $winPointsRedemptionRules,
  fn($rule) => !empty($rule['activo']) && (int) ($rule['required_points'] ?? 0) > 0
));
$gameHasAnyAwardRule = !empty(array_filter($winPointsPackageRewards, fn($r) => (int) $r > 0));
$gameHasAnyPointsRule = $winPointsEnabled && ($gameHasAnyRedemptionRule || $gameHasAnyAwardRule);
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


<section class="container mt-5 mb-4" data-aos="fade-up">
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

<?php
  $referidosNivelesList = referidos_niveles();
  $referidosPorcentajeMin = !empty($referidosNivelesList) ? (float) reset($referidosNivelesList)['porcentaje'] : 0.0;
  $referidosPorcentajeMax = !empty($referidosNivelesList) ? (float) end($referidosNivelesList)['porcentaje'] : 0.0;
  $referidosCuponPorcentaje = referidos_cupon_bienvenida_porcentaje();
  $referidosBannerTitulo = referidos_banner_titulo();
  $referidosBannerTipoIcono = referidos_banner_icono_tipo();
  $referidosBannerImagen = referidos_banner_icono_imagen();
?>
<?php if ($referidosPorcentajeMax > 0): ?>
<section class="container mt-4 mb-0" data-aos="fade-up">
  <div class="referidos-banner-card d-flex align-items-center justify-content-between gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-3">
      <?php if ($referidosBannerTipoIcono === 'imagen' && $referidosBannerImagen !== ''): ?>
        <img src="<?= htmlspecialchars(app_path('/' . ltrim($referidosBannerImagen, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="" class="referidos-banner-image" aria-hidden="true">
      <?php else: ?>
        <span class="referidos-banner-icon" aria-hidden="true"><?= htmlspecialchars(referidos_banner_icono_emoji(), ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
      <div>
        <div class="referidos-banner-title"><?= htmlspecialchars($referidosBannerTitulo, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="referidos-banner-subtitle">Reciben <strong><?= (int) $referidosCuponPorcentaje ?>%</strong> de descuento + Tú obtienes <strong><?= (int) $referidosPorcentajeMin ?>% a <?= (int) $referidosPorcentajeMax ?>%</strong> de ganancias</div>
      </div>
    </div>
    <?php if ($authUser): ?>
      <button type="button" class="btn referidos-banner-btn fw-bold" data-user-open="referrals">Invita y gana</button>
    <?php else: ?>
      <button type="button" class="btn referidos-banner-btn fw-bold" data-auth-open="register">Invita y gana</button>
    <?php endif; ?>
  </div>
</section>
<style>
  .referidos-banner-card {
    background: linear-gradient(90deg, rgba(0,255,247,0.12) 0%, rgba(168,85,247,0.14) 100%);
    border: 1px solid rgba(0,255,247,0.5);
    border-radius: 14px;
    padding: 1rem 1.25rem;
    box-shadow: 0 0 16px rgba(0,255,247,0.12);
  }
  .referidos-banner-icon { font-size: 1.8rem; line-height: 1; }
  .referidos-banner-image { width: 44px; height: 44px; object-fit: cover; border-radius: 10px; flex-shrink: 0; }
  .referidos-banner-title { color: #00fff7; font-weight: 700; font-size: 1.05rem; }
  .referidos-banner-subtitle { color: #b2f6ff; font-size: 0.85rem; }
  .referidos-banner-btn {
    background: linear-gradient(90deg, #00fff7 0%, #a855f7 100%);
    color: #0b0f1a;
    border: none;
    white-space: nowrap;
    box-shadow: 0 0 10px rgba(0,255,247,0.4);
  }
  .referidos-banner-btn:hover { color: #0b0f1a; filter: brightness(1.08); }
</style>
<?php endif; ?>

<section id="player-step-section" class="container mt-4 mb-3" data-aos="fade-up">
  <h2 class="page-step-title text-info mb-0"><span class="<?= paso_linea_class('paso1') ?>" data-paso-linea<?= paso_estilo_css_inline('paso1') ?>><?= htmlspecialchars(paso_linea_partes('paso1')['badge'], ENT_QUOTES, 'UTF-8') ?></span><?php $paso1Resto = paso_linea_partes('paso1')['resto']; if ($paso1Resto !== ''): ?> <span class="<?= paso_linea_class('paso1_resto') ?>"<?= paso_estilo_css_inline('paso1_resto') ?>><?= htmlspecialchars($paso1Resto, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></h2>
</section>


<section id="player-info-section" class="container mt-3 mt-md-5 mb-2 mb-md-5 p-4 bg-dark bg-opacity-75 rounded-4 shadow" data-aos="fade-up">
  <form class="row g-3" id="order-form">
    <div class="col-12">
      <div class="row g-3" id="player-fields-row">
        <div class="col-md-6 col-12" id="player-primary-field">
          <label class="form-label text-info" id="player-primary-label">ID de usuario</label>
          <div class="d-flex flex-column flex-sm-row gap-2 align-items-stretch">
            <input type="text" id="order-user-id" name="user_id" placeholder="Ej: 12345678" value="<?= htmlspecialchars($loggedUserLastPurchaseIdentifier, ENT_QUOTES, 'UTF-8') ?>" class="form-control bg-dark text-info border-info"<?= paso_estilo_css_inline('campo') ?> required />
            <?php $botonIconoHtml = paso_estilo_icono_render('boton', 'paso-boton-icon-img'); ?>
            <button type="button" id="verify-player-button" class="btn btn-outline-info fw-bold text-nowrap d-none paso-boton-verif"<?= paso_estilo_css_inline('boton') ?>><?php if ($botonIconoHtml !== ''): ?><span class="paso-boton-icon" aria-hidden="true"><?= $botonIconoHtml ?></span><?php endif; ?><span id="verify-player-button-text"><?= htmlspecialchars(paso_estilo_boton_texto_efectivo($playerVerificationConfig), ENT_QUOTES, 'UTF-8') ?></span></button>
          </div>
          <div id="player-verification-feedback" class="d-none mt-2"></div>
        </div>
        <div id="extra-player-fields" class="col-md-6 col-12"></div>
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


<section id="game-packages-section" class="container mt-2 mt-md-4" data-aos="fade-up">
  <div class="row mb-2 align-items-center">
    <div class="col">
      <h2 class="page-step-title text-info mb-0"><span class="<?= paso_linea_class('paso2') ?>" data-paso-linea<?= paso_estilo_css_inline('paso2') ?>><?= htmlspecialchars(paso_linea_partes('paso2')['badge'], ENT_QUOTES, 'UTF-8') ?></span><?php $paso2Resto = paso_linea_partes('paso2')['resto']; if ($paso2Resto !== ''): ?> <span class="<?= paso_linea_class('paso2_resto') ?>"<?= paso_estilo_css_inline('paso2_resto') ?>><?= htmlspecialchars($paso2Resto, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></h2>
      <br>
    </div>
    <div class="col-auto">
      <span class="text-uppercase text-secondary small">elige</span>
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
    $apiProductsById = [];
    if ($usesCatalogApi && recargas_api_is_configured()) {
      try {
        foreach (recargas_api_fetch_products_by_category((string) $game['categoria_api']) as $apiProduct) {
          $apiProductsById[(int) ($apiProduct['id'] ?? 0)] = $apiProduct;
        }
      } catch (Throwable $e) {
        $apiProductsById = [];
      }
      $juegoCategoriaApi2Game = trim((string) ($game['categoria_api_2'] ?? ''));
      if ($juegoCategoriaApi2Game !== '') {
        try {
          foreach (recargas_api_fetch_products_by_category($juegoCategoriaApi2Game) as $apiProduct) {
            $apiProductsById[(int) ($apiProduct['id'] ?? 0)] = $apiProduct;
          }
        } catch (Throwable $e) {
          // keep existing products
        }
      }
      $juegoCategoriaApi3Game = trim((string) ($game['categoria_api_3'] ?? ''));
      if ($juegoCategoriaApi3Game !== '') {
        try {
          foreach (recargas_api_fetch_products_by_category($juegoCategoriaApi3Game) as $apiProduct) {
            $apiProductsById[(int) ($apiProduct['id'] ?? 0)] = $apiProduct;
          }
        } catch (Throwable $e) {
          // keep existing products
        }
      }
    }

    $resPaq = $mysqli->query("SELECT * FROM juego_paquetes WHERE juego_id=" . intval($game['id']) . " AND COALESCE(activo, 1) = 1 ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC");
    $paquetes = [];
    while ($pack = $resPaq->fetch_assoc()) {
      $paquetes[] = $pack;
    }
    // Mismo mecanismo de sincronización de precio que GiftVen (ver
    // $apiProductsById arriba), pero para RecargasAmérica: solo se consulta
    // el catálogo en vivo si este juego realmente tiene algún paquete de
    // ese proveedor — RecargasAmérica no filtra por categoría, así que su
    // catálogo es el completo (todos los juegos mezclados) y no vale la
    // pena pedirlo si este juego no lo usa.
    $recargasAmericaProductsById = [];
    $usesRecargasAmericaCatalogGame = false;
    foreach ($paquetes as $pack) {
      if (trim((string) ($pack['api_provider'] ?? '')) === 'recargasamerica') {
        $usesRecargasAmericaCatalogGame = true;
        break;
      }
    }
    if ($usesRecargasAmericaCatalogGame && recargasamerica_api_is_configured()) {
      try {
        foreach (recargasamerica_api_fetch_products_pins() as $raProduct) {
          $recargasAmericaProductsById[(int) ($raProduct['id'] ?? 0)] = $raProduct;
        }
      } catch (Throwable $e) {
        $recargasAmericaProductsById = [];
      }
    }
    // Un paquete asignado a una categoría desactivada no debe aparecer en la
    // tienda (ni en su tab ni en "Otros"), a diferencia de uno sin categoría.
    $allPackageCategoriesByIdForGame = [];
    foreach (package_category_list($mysqli, (int) $game['id']) as $pcatAllRow) {
      $allPackageCategoriesByIdForGame[(int) $pcatAllRow['id']] = $pcatAllRow;
    }
    $paquetes = array_values(array_filter($paquetes, static function (array $pack) use ($allPackageCategoriesByIdForGame): bool {
      $catId = (int) ($pack['categoria_paquete_id'] ?? 0);
      if ($catId <= 0) {
        return true;
      }
      return !isset($allPackageCategoriesByIdForGame[$catId]) || $allPackageCategoriesByIdForGame[$catId]['activa'];
    }));
    $packageAccountSaleGalleryMap = $accountSaleFeatureEnabled
      ? package_account_sales_fetch_gallery_map($mysqli, array_map(static fn (array $package): int => (int) ($package['id'] ?? 0), $paquetes))
      : [];
    $packageFeaturesByPackage = package_features_for_packages($mysqli, array_map(static fn (array $package): int => (int) ($package['id'] ?? 0), $paquetes));
  ?>
  <?php
    // Márgenes separados por proveedor: GiftVen y RecargasAmérica son
    // catálogos independientes con precios base distintos, así que no
    // pueden compartir un solo porcentaje de ganancia (instrucción
    // explícita del cliente).
    $gameMarkupPctGiftven = floatval($game['precio_markup_pct'] ?? 0);
    $gameMarkupPctRecargasamerica = floatval($game['precio_markup_pct_recargasamerica'] ?? 0);
  ?>
  <?php $priceSyncQueue = []; ?>
  <?php $bsPassStockPackageIds = []; ?>
  <?php $levelPassPackageIds = []; ?>
  <?php
    // ── Categorías de paquetes: si el juego tiene alguna, los paquetes se
    // agrupan en tabs (una por categoría + "Otros" para los que no tienen).
    // Si no hay ninguna categoría creada, todo se comporta igual que antes.
    $gamePackageCategories = package_categories_for_game($mysqli, (int) $game['id'], true);
    $packageCategoriesActive = !empty($gamePackageCategories);
    $validPackageCategoryIds = array_map(static fn (array $c): int => (int) $c['id'], $gamePackageCategories);
    $hasUncategorizedPackages = false;
    if ($packageCategoriesActive) {
      foreach ($paquetes as $pack) {
        $packCatIdCheck = (int) ($pack['categoria_paquete_id'] ?? 0);
        if ($packCatIdCheck <= 0 || !in_array($packCatIdCheck, $validPackageCategoryIds, true)) {
          $hasUncategorizedPackages = true;
          break;
        }
      }
    }
  ?>
  <?php if ($packageCategoriesActive): ?>
  <div class="pack-category-tabs" id="pack-category-tabs" role="tablist">
    <?php foreach ($gamePackageCategories as $pcatIdx => $pcat): ?>
      <?php $pcatShowIcon = in_array($pcat['mostrar_menu'], ['icono', 'icono_texto'], true) && $pcat['icono'] !== ''; ?>
      <?php $pcatShowText = in_array($pcat['mostrar_menu'], ['texto', 'icono_texto'], true) || !$pcatShowIcon; ?>
      <?php
        $pcatStyleVars = '';
        if ($pcat['color'] !== '') {
          $pcatStyleVars .= '--pack-cat-color:' . htmlspecialchars($pcat['color'], ENT_QUOTES, 'UTF-8') . ';';
        }
        if ($pcat['color_texto'] !== '') {
          $pcatStyleVars .= '--pack-cat-text:' . htmlspecialchars($pcat['color_texto'], ENT_QUOTES, 'UTF-8') . ';';
        }
      ?>
      <button type="button" class="pack-category-tab-btn<?= $pcatIdx === 0 ? ' active' : '' ?>" data-category-tab="<?= (int) $pcat['id'] ?>" role="tab" aria-selected="<?= $pcatIdx === 0 ? 'true' : 'false' ?>"<?= $pcatStyleVars !== '' ? ' style="' . $pcatStyleVars . '"' : '' ?>>
        <?php if ($pcatShowIcon): ?><span class="pack-category-tab-icon"><?= htmlspecialchars($pcat['icono'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        <?php if ($pcatShowText): ?><span class="pack-category-tab-label"><?= htmlspecialchars($pcat['nombre'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
      </button>
    <?php endforeach; ?>
    <?php if ($hasUncategorizedPackages): ?>
      <button type="button" class="pack-category-tab-btn" data-category-tab="otros" role="tab" aria-selected="false">
        <span class="pack-category-tab-label">Otros</span>
      </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-2 g-sm-3 mb-4" id="pack-grid">
    <?php foreach ($paquetes as $pack):
        $packApiId = (int) ($pack['paquete_api'] ?? 0);
        $packManualOverride = !empty($pack['precio_manual_override']);
        // Se distingue por api_provider ANTES de mirar los catálogos: los
        // IDs de producto de GiftVen y RecargasAmérica son de sistemas
        // externos independientes y pueden coincidir por coincidencia — sin
        // este chequeo, un paquete de RecargasAmérica con el mismo ID
        // numérico que un producto de GiftVen tomaría el precio equivocado.
        $packPricingProvider = trim((string) ($pack['api_provider'] ?? ''));
        if (!$packManualOverride && $packApiId > 0 && $packPricingProvider === 'recargasamerica' && isset($recargasAmericaProductsById[$packApiId])) {
            $packApiRawPrice = floatval($recargasAmericaProductsById[$packApiId]['price'] ?? 0);
            $packMarkupPct = $gameMarkupPctRecargasamerica;
        } else {
            $packApiRawPrice = (!$packManualOverride && $packApiId > 0 && $packPricingProvider !== 'recargasamerica' && isset($apiProductsById[$packApiId])) ? floatval($apiProductsById[$packApiId]['precio']) : null;
            $packMarkupPct = $gameMarkupPctGiftven;
        }
        $precio_base = ($packApiRawPrice !== null)
            ? max(0.0, round($packApiRawPrice * (1 + $packMarkupPct / 100), 2))
            : floatval($pack['precio']);
        if (!$packManualOverride && $packApiRawPrice !== null && abs($precio_base - floatval($pack['precio'])) > 0.00001) {
            $priceSyncQueue[] = ['id' => (int) ($pack['id'] ?? 0), 'precio' => $precio_base];
        }
        $precio_mostrar = $moneda_actual ? currency_convert_from_base($precio_base, $moneda_actual) : currency_apply_amount_rule($precio_base, null);
        $packDropPercent = max(0, min(99, (int) ($pack['descuento_destacado'] ?? 0)));
        $precio_mostrar_con_drop = $packDropPercent > 0
            ? ($moneda_actual
                ? currency_convert_from_base($precio_base * (1 - $packDropPercent / 100), $moneda_actual)
                : currency_apply_amount_rule($precio_base * (1 - $packDropPercent / 100), null))
            : $precio_mostrar;
        $clave_moneda = $moneda_actual['clave'] ?? 'USD';
        $mostrarDecimales = $moneda_actual ? currency_should_show_decimals($moneda_actual) : true;
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
          } elseif ((int) ($pack['fullimpulso_service_id'] ?? 0) > 0) {
            $packApiProvider = 'fullimpulso';
          } elseif (!empty($game['categoria_api_discord'])) {
            $packApiProvider = 'discord';
          }
        }
        if ($packApiProvider === 'giftven' && $packApiId > 0) {
          $bsPassCategory = trim((string) ($pack['api_source_key'] ?? ''));
          if ($bsPassCategory === '' && isset($apiProductsById[$packApiId])) {
            $bsPassCategory = trim((string) ($apiProductsById[$packApiId]['categoria'] ?? ''));
          }
          if (bs_pass_stock_is_pass_category($bsPassCategory)) {
            $bsPassStockPackageIds[] = $packId;
          }
        }
        $packLevelPassKey = levelpass_normalize_key($pack['levelpass_key'] ?? '');
        if ($packLevelPassKey !== '') {
          $levelPassPackageIds[$packId] = $packLevelPassKey;
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
        } elseif ($packApiProvider === 'discord') {
          $packApiSourceKey = trim((string) ($pack['api_source_key'] ?? ''));
          $discordCmdKeyForPack = $packApiSourceKey !== '' ? $packApiSourceKey : (string) ($game['categoria_api_discord'] ?? '');
          $packDiscordFields = api_discord_checkout_required_fields($discordCmdKeyForPack);
          if (!empty($packDiscordFields)) {
            $apiRequiredFields = $packDiscordFields;
          }
        } elseif ($packApiProvider === 'fullimpulso') {
          // Único campo requerido: el enlace al perfil/publicación (en vez
          // del ID de jugador numérico que usan los demás proveedores).
          $apiRequiredFields = [[
            'name' => 'link',
            'label' => 'Enlace de tu perfil o publicación',
            'placeholder' => 'https://instagram.com/tu_usuario',
            'inputMode' => 'url',
            'pattern' => 'https?://.+',
            'title' => 'Ingresa un enlace válido (debe empezar con http:// o https://).',
            'validationMessage' => 'Ingresa un enlace válido (debe empezar con http:// o https://).',
            'maxLength' => 500,
          ]];
        }
        $packFullimpulsoCustomComments = $packApiProvider === 'fullimpulso' && !empty($pack['fullimpulso_custom_comments']);
        $packFullimpulsoCantidad = (int) ($pack['fullimpulso_cantidad'] ?? 0);
        $img_paquete = !empty($pack['imagen_icono']) ? $pack['imagen_icono'] : (!empty($game['imagen_paquete']) ? $game['imagen_paquete'] : null);
        $packImageUrl = package_feature_public_asset_url($img_paquete);
        $packCategoryTabId = 'otros';
        if ($packageCategoriesActive) {
          $packCatIdForTab = (int) ($pack['categoria_paquete_id'] ?? 0);
          if ($packCatIdForTab > 0 && in_array($packCatIdForTab, $validPackageCategoryIds, true)) {
            $packCategoryTabId = (string) $packCatIdForTab;
          }
        }
    ?>
      <div class="col" data-package-category="<?= htmlspecialchars($packCategoryTabId, ENT_QUOTES, 'UTF-8') ?>"<?= $packLevelPassKey !== '' ? ' data-levelpass-key="' . htmlspecialchars($packLevelPassKey, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
        <article class="pack-card card border-info bg-dark text-start w-100 h-100 shadow-sm"
          data-package-id="<?= $packId ?>"
          data-package-provider="<?= htmlspecialchars($packApiProvider, ENT_QUOTES, 'UTF-8') ?>"
          data-base="<?= htmlspecialchars($precio_base) ?>"
          data-base-currency="<?= htmlspecialchars($clave_moneda) ?>"
          data-name="<?= htmlspecialchars($pack['nombre'], ENT_QUOTES, 'UTF-8') ?>"
          data-cantidad="<?= htmlspecialchars($pack['cantidad'], ENT_QUOTES, 'UTF-8') ?>"
          data-price-value="<?= htmlspecialchars((string) $precio_mostrar_con_drop, ENT_QUOTES, 'UTF-8') ?>"
          data-show-decimals="<?= $mostrarDecimales ? '1' : '0' ?>"
          data-required-fields="<?= htmlspecialchars(json_encode($apiRequiredFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
          data-win-points-reward="<?= $packWinPointsReward ?>"
          data-win-points-required="<?= $packWinPointsRequired ?>"
          data-win-points-active="<?= $packWinPointsRuleActive ? '1' : '0' ?>"
          data-drop-percent="<?= max(0, min(99, (int) ($pack['descuento_destacado'] ?? 0))) ?>"
          data-package-image="<?= htmlspecialchars($packImageUrl, ENT_QUOTES, 'UTF-8') ?>"
          data-package-features="<?= htmlspecialchars(json_encode($packFeatures, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
          data-account-sale="<?= $packIsAccountSale ? '1' : '0' ?>"
          data-account-gallery="<?= htmlspecialchars(json_encode($packAccountGalleryPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
          data-moneda="<?= htmlspecialchars($clave_moneda) ?>"
          data-fullimpulso-custom-comments="<?= $packFullimpulsoCustomComments ? '1' : '0' ?>"
          data-fullimpulso-cantidad="<?= $packFullimpulsoCantidad ?>"
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
              <?php $packInfoHtml = package_info_sanitize_html((string) ($pack['info_html'] ?? '')); ?>
              <?php if ($packInfoHtml !== ''): ?>
                <button type="button" class="pack-info-btn"
                        data-pack-info="<?= htmlspecialchars($packInfoHtml, ENT_QUOTES, 'UTF-8') ?>"
                        data-pack-info-title="<?= htmlspecialchars($pack['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="Información del paquete <?= htmlspecialchars($pack['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="18" x2="12" y2="10"/><line x1="12" y1="5.6" x2="12.01" y2="5.6"/></svg>
                </button>
              <?php endif; ?>
            </div>
            <?php if (!empty($packFeatures)): ?>
              <div class="pack-card-features" aria-hidden="true">
                <?php foreach ($packFeatures as $feature): ?>
                  <?php $featureStyleAttr = package_feature_badge_style_attr($feature); ?>
                  <span class="pack-card-feature-badge"<?= $featureStyleAttr !== '' ? ' style="' . htmlspecialchars($featureStyleAttr, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
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
                <div class="pack-card-price-block">
                  <?php if ($packDropPercent > 0): ?>
                    <div class="pack-card-drop-row">
                      <span class="pack-card-drop-badge">-<?= $packDropPercent ?>%</span>
                      <span class="precio-original-label"><?= currency_format_amount($precio_mostrar, $moneda_actual) ?></span>
                    </div>
                  <?php endif; ?>
                  <span class="precio-label"><?= currency_format_amount($precio_mostrar_con_drop, $moneda_actual) ?></span>
                </div>
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

  <!-- Multi-cart toggle (oculto, siempre activo) -->
  <div class="multi-cart-toggle-wrap" id="multi-cart-toggle-wrap" style="display:none">
    <label class="multi-cart-toggle-label" for="multi-cart-check">
      <input type="checkbox" id="multi-cart-check" class="multi-cart-toggle-input" />
      <span class="multi-cart-toggle-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
      </span>
      <span class="multi-cart-toggle-text">Quiero comprar más de 1 paquete</span>
    </label>
  </div>

  <?php
    // Sync fallback prices with last known API price (skip manual overrides)
    foreach ($priceSyncQueue as $syncEntry) {
        $stmtPriceSync = $mysqli->prepare("UPDATE juego_paquetes SET precio = ? WHERE id = ? AND precio_manual_override = 0 LIMIT 1");
        if ($stmtPriceSync) {
            $stmtPriceSync->bind_param('di', $syncEntry['precio'], $syncEntry['id']);
            $stmtPriceSync->execute();
            $stmtPriceSync->close();
        }
    }
  ?>
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
        const dropPercent = Math.max(0, Math.min(99, Number(card.getAttribute('data-drop-percent') || 0)));
        const precioBase = normalizeCurrencyAmount(base * monedaActualTasa, monedaActualMostrarDecimales);
        const precio = dropPercent > 0
          ? normalizeCurrencyAmount(base * monedaActualTasa * (1 - dropPercent / 100), monedaActualMostrarDecimales)
          : precioBase;
        card.querySelector('.precio-label').textContent = formatCurrencyAmount(precio, monedaActualMostrarDecimales);
        card.querySelector('.moneda-label').textContent = monedaActualClave;
        card.setAttribute('data-price-value', String(precio));
        card.setAttribute('data-show-decimals', monedaActualMostrarDecimales ? '1' : '0');
        card.setAttribute('data-moneda', monedaActualClave);
        const originalLabel = card.querySelector('.precio-original-label');
        if (originalLabel) {
          originalLabel.textContent = formatCurrencyAmount(precioBase, monedaActualMostrarDecimales);
        }
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

<section id="payment-step-section" class="container mt-3 mb-5" data-aos="fade-up">
  <h2 class="page-step-title text-info mb-0"><span class="<?= paso_linea_class('paso3') ?>" data-paso-linea<?= paso_estilo_css_inline('paso3') ?>><?= htmlspecialchars(paso_linea_partes('paso3')['badge'], ENT_QUOTES, 'UTF-8') ?></span><?php $paso3Resto = paso_linea_partes('paso3')['resto']; if ($paso3Resto !== ''): ?> <span class="<?= paso_linea_class('paso3_resto') ?>"<?= paso_estilo_css_inline('paso3_resto') ?>><?= htmlspecialchars($paso3Resto, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></h2>
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
        Continuar con la Compra
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

  <!-- ============================================================
       MULTI-CART MODAL
       ============================================================ -->
  <div id="multi-cart-modal" class="app-overlay-modal" tabindex="-1" aria-hidden="true" aria-label="Carrito de paquetes" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="width:min(95vw,540px);">
      <div class="modal-content bg-dark text-light rounded-4 p-0" style="border:1px solid #22d3ee;">
        <div class="multi-cart-modal-header">
          <div class="multi-cart-modal-title-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            <h3 class="multi-cart-modal-title">Tu Carrito</h3>
          </div>
          <button type="button" id="multi-cart-modal-close" class="btn-close btn-close-white" aria-label="Cerrar"></button>
        </div>
        <div class="multi-cart-modal-body" id="multi-cart-modal-body">
          <!-- Items rendered by JS -->
        </div>
        <div class="multi-cart-modal-footer">
          <div class="multi-cart-modal-total-row">
            <span class="multi-cart-modal-total-label">Total</span>
            <strong id="multi-cart-modal-total" class="multi-cart-modal-total-value">-</strong>
          </div>
          <div class="multi-cart-modal-actions">
            <button type="button" id="multi-cart-keep-shopping" class="btn btn-outline-info fw-bold">Seguir Seleccionando</button>
            <button type="button" id="multi-cart-proceed" class="btn btn-info fw-bold multi-cart-proceed-btn">
              <span id="multi-cart-proceed-label">Continuar con la compra</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================
       BATCH PROGRESS MODAL
       ============================================================ -->
  <div id="batch-progress-modal" class="app-overlay-modal" tabindex="-1" aria-hidden="true" aria-label="Procesando compra" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="width:min(95vw,500px);">
      <div class="modal-content bg-dark text-light rounded-4 p-0" style="border:1px solid #22d3ee;">
        <div class="batch-progress-modal-header">
          <h3 class="batch-progress-modal-title">Procesando tu compra</h3>
          <p id="batch-progress-current-label" class="batch-progress-current-label">Iniciando...</p>
        </div>
        <div class="batch-progress-bar-wrap">
          <div class="batch-progress-bar-track">
            <div id="batch-progress-bar" class="batch-progress-bar-fill" style="width:0%"></div>
          </div>
          <span id="batch-progress-fraction" class="batch-progress-fraction">0/0</span>
        </div>
        <div id="batch-progress-items" class="batch-progress-items">
          <!-- Items appended by JS -->
        </div>
        <div class="batch-progress-footer" id="batch-progress-footer" style="display:none;">
          <button type="button" id="batch-progress-close" class="btn btn-info fw-bold w-100 py-2">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Admin API Debug Modal (solo visible para admin/root) -->
  <div id="admin-api-debug-modal" class="app-overlay-modal" tabindex="-1" aria-hidden="true" role="dialog" style="z-index:99999!important;">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="width:min(96vw,720px);">
      <div class="modal-content bg-dark text-light rounded-4 p-0" style="border:1px solid #f59e0b;">
        <div class="d-flex align-items-center justify-content-between px-4 py-3" style="border-bottom:1px solid #f59e0b;">
          <h5 class="mb-0 fw-bold" style="color:#f59e0b;">🔍 Debug Error API (solo admins)</h5>
          <button type="button" id="admin-debug-modal-close" class="btn-close btn-close-white" aria-label="Cerrar"></button>
        </div>
        <div class="px-4 py-3" style="max-height:65vh;overflow-y:auto;">
          <pre id="admin-debug-json" class="text-warning mb-3" style="background:#1a1a2e;border-radius:8px;padding:1rem;font-size:12px;white-space:pre-wrap;word-break:break-all;max-height:55vh;overflow-y:auto;border:1px solid #374151;"></pre>
        </div>
        <div class="px-4 pb-4 d-flex gap-2">
          <button type="button" id="admin-debug-copy-btn" class="btn btn-warning fw-bold flex-grow-1">Copiar JSON</button>
          <button type="button" id="admin-debug-modal-close2" class="btn btn-outline-secondary">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

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
        <?php
        // Sistema de Comentarios: bloque post-compra. Se revela solo cuando
        // la compra termina bien (ver showPaymentStatusModal, type success).
        ?>
        <div id="cmt-postcompra" class="d-none mb-4">
          <div style="height:1px;background:rgba(var(--theme-primary-rgb),0.25);margin-bottom:1rem;"></div>
          <div class="fw-bold mb-1" style="color:var(--theme-primary);font-size:1rem;text-shadow:0 0 10px rgba(var(--theme-primary-rgb),0.45);">¡Gracias por su compra!</div>
          <div class="small text-light opacity-75 mb-3">Ven, califícanos y cuéntanos la experiencia de tu compra.</div>
          <button type="button" id="cmt-postcompra-btn" class="btn btn-outline-info fw-bold px-4">Deja tu comentario</button>
        </div>
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
  <!-- Modal Información del Paquete (ícono i) -->
  <div id="pack-info-modal" class="app-overlay-modal" tabindex="-1" aria-hidden="true" role="dialog" aria-label="Información del paquete">
    <div class="modal-dialog modal-dialog-centered" style="width:min(94vw, 480px);">
      <div class="modal-content bg-dark text-light rounded-4 p-4" style="border:1px solid rgba(var(--theme-primary-rgb), 0.6); box-shadow:0 0 28px rgba(var(--theme-primary-rgb), 0.18);">
        <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
          <h5 id="pack-info-modal-title" class="text-info fw-bold mb-0" style="font-family:'Oxanium',sans-serif;"></h5>
          <button type="button" id="pack-info-modal-close" class="btn btn-outline-info rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:38px;height:38px;" aria-label="Cerrar">&times;</button>
        </div>
        <div id="pack-info-modal-body" class="pack-info-modal-body" style="max-height:60vh;overflow-y:auto;line-height:1.65;"></div>
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
        <div id="payment-cart-summary" class="mb-3 p-3 rounded-3" style="background:rgba(34,211,238,.06);border:1px solid rgba(34,211,238,.22);">
          <p class="small text-info fw-bold mb-2">Paquetes a comprar:</p>
          <ul class="payment-cart-summary-list" id="payment-cart-summary-list"></ul>
          <div id="payment-cart-discount-section" style="display:none;border-top:1px solid rgba(34,211,238,.12);margin-top:0.5rem;padding-top:0.4rem;">
            <div class="d-flex justify-content-between" style="padding:0.18rem 0;font-size:0.8rem;">
              <span class="text-secondary">Subtotal</span>
              <span id="payment-cart-raw-total" class="fw-semibold" style="color:#94a3b8;">-</span>
            </div>
            <div class="d-flex justify-content-between" style="padding:0.18rem 0;font-size:0.8rem;">
              <span id="payment-cart-coupon-label" class="text-secondary">Cupón</span>
              <span id="payment-cart-discount-amount" class="fw-bold" style="color:#4ade80;">-</span>
            </div>
          </div>
          <div class="d-flex justify-content-between mt-2 pt-2" style="border-top:1px solid rgba(34,211,238,.18);">
            <span class="small text-secondary fw-semibold" id="payment-cart-total-label">Total del carrito</span>
            <strong id="payment-cart-summary-total" class="small" style="color:#22d3ee;">-</strong>
          </div>
        </div>
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
                <div class="mt-3 pt-3" style="border-top:1px solid rgba(34,211,238,.15);">
                  <div class="mb-2">
                    <label for="order-email-input" class="form-label text-info small mb-1">Correo electrónico</label>
                    <input type="email" id="order-email-input" name="email" placeholder="tu@email.com" value="<?= htmlspecialchars($loggedUserEmail, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" class="form-control bg-dark text-info border-info" />
                  </div>
                  <div class="email-disclaimer-card">
                    El correo electrónico ingresado será utilizado exclusivamente para el envío de su comprobante electrónico.
                  </div>
                </div>
              </div>
              <div id="payment-reference-group" class="mb-3" style="display:none;">
                <label id="payment-reference-label" class="form-label small mb-1" style="color:#22d3ee;">Número de referencia del pago</label>
                <input type="text" id="payment-reference-input" inputmode="numeric" autocomplete="off">
                <div id="payment-reference-help"></div>
              </div>
              <div id="payment-phone-group" style="display:none;">
                <input type="tel" id="payment-phone-input" autocomplete="tel" value="<?= htmlspecialchars($loggedUserLastPurchasePhone, ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <!-- Formulario de verificación avanzado -->
              <div id="payment-advanced-form">
                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label for="payment-nombre-input" class="form-label text-info">Nombre (TITULAR)</label>
                    <input type="text" id="payment-nombre-input" class="form-control bg-dark text-info border-info" autocomplete="off" placeholder="Ej: Juan Pérez" value="<?= htmlspecialchars($loggedUserLastPurchaseNombre, ENT_QUOTES, 'UTF-8') ?>">
                  </div>
                  <div class="col-6">
                    <label for="payment-cedula-input" class="form-label text-info">Cédula (TITULAR)</label>
                    <input type="text" id="payment-cedula-input" class="form-control bg-dark text-info border-info" autocomplete="off" placeholder="Ej: V-12345678" value="<?= htmlspecialchars($loggedUserLastPurchaseCedula, ENT_QUOTES, 'UTF-8') ?>">
                  </div>
                </div>
                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <label for="payment-phone-adv-input" class="form-label text-info">Número de Teléfono (TITULAR)</label>
                    <input type="tel" id="payment-phone-adv-input" class="form-control bg-dark text-info border-info" inputmode="numeric" autocomplete="off" placeholder="Ej: 0414-1234567" value="<?= htmlspecialchars($loggedUserLastPurchasePhone, ENT_QUOTES, 'UTF-8') ?>">
                  </div>
                  <div id="payment-adv-reference-group" class="col-6">
                    <label for="payment-adv-reference-input" id="payment-adv-reference-label" class="form-label text-info">Número de referencia del pago</label>
                    <input type="text" id="payment-adv-reference-input" class="form-control bg-dark text-info border-info" inputmode="numeric" autocomplete="off" placeholder="Número de referencia">
                    <div id="payment-adv-reference-help" class="form-text text-secondary">Ingresa el número de referencia de tu pago.</div>
                  </div>
                </div>
                <div id="payment-whatsapp-wrap" class="d-none">
                  <button type="button" id="payment-whatsapp-btn" class="btn btn-success w-100 fw-bold py-2 mb-3">
                    <i class="fa-brands fa-whatsapp me-2" aria-hidden="true"></i>Enviar Comprobante al Admin
                  </button>
                </div>
                <div class="d-flex gap-2 align-items-start p-3 rounded-3 mb-2" style="background:rgba(220,53,69,.13);border:1px solid rgba(220,53,69,.38);">
                  <i class="fa-solid fa-shield-halved text-danger flex-shrink-0 mt-1" aria-hidden="true"></i>
                  <div class="small" style="color:#f8a0a8;">
                    <strong>Aviso legal:</strong> Suministrar comprobantes y datos falsos o manipulados constituye fraude electrónico y puede ser penalizado conforme a la ley. Nos reservamos el derecho de reportar ante las autoridades competentes.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <button type="button" id="payment-submit-btn" class="btn btn-info w-100 fw-bold text-uppercase py-3 payment-submit-btn-theme<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>">Realizar Compra</button>
        <?php if ($canSimulateDailyMissionPurchase): ?>
        <button type="button" id="daily-mission-simulate-purchase-btn" class="btn btn-warning w-100 fw-bold text-uppercase py-3 mt-3">
          <i class="fa-solid fa-vial-circle-check me-2" aria-hidden="true"></i>Simular compra
        </button>
        <div class="small text-warning text-center mt-2">Solo completa la tarea diaria de compra.</div>
        <?php endif; ?>
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

  <div id="payment-whatsapp-confirm-modal" class="modal fade app-overlay-modal payment-confirm-overlay" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content bg-dark text-light p-4 rounded-4" style="border:1px solid rgba(37,211,102,.5);">
        <h4 class="fw-bold mb-3" style="color:#25d366;"><i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i>Envío de comprobante</h4>
        <p class="text-light mb-4">Serás redirigido al WhatsApp del administrador. <strong>Recuerda adjuntar la captura de tu pago</strong> en el chat y verifica que los datos enviados sean correctos antes de enviar.</p>
        <div class="d-flex gap-2 justify-content-end flex-wrap">
          <button type="button" id="payment-whatsapp-modal-cancel-btn" class="btn btn-outline-secondary">Cancelar</button>
          <button type="button" id="payment-whatsapp-modal-confirm-btn" class="btn fw-bold" style="background:#25d366;color:#000;">
            <i class="fa-brands fa-whatsapp me-2" aria-hidden="true"></i>Ir a WhatsApp
          </button>
        </div>
      </div>
    </div>
  </div>

  <div id="fullimpulso-comments-modal" class="modal fade app-overlay-modal payment-confirm-overlay" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:560px;">
      <div class="modal-content bg-dark text-light rounded-4 p-0" style="border:1px solid #22d3ee;">
        <div class="p-4 pb-3">
          <div class="fw-bold mb-2" style="color:#22d3ee;font-size:1.05rem;">📝 Escribe tus comentarios</div>
          <div class="small mb-3" style="color:#8be9fd;">Este paquete requiere que escribas cada comentario que quieres publicar, uno por línea.</div>
          <textarea id="fullimpulso-comments-textarea" class="form-control bg-black text-light" rows="10" style="border:1px solid #22d3ee;resize:vertical;font-size:0.95rem;" placeholder="Escribe un comentario por línea..."></textarea>
          <div class="small mt-2 d-flex justify-content-between" style="color:#8be9fd;">
            <span id="fullimpulso-comments-count">0 de 0 líneas</span>
            <span id="fullimpulso-comments-error" style="color:#f87171;"></span>
          </div>
          <div class="d-flex gap-2 flex-column mt-3">
            <button type="button" id="fullimpulso-comments-continue-btn" class="btn btn-info w-100 fw-bold text-uppercase py-2" disabled>Continuar</button>
            <button type="button" id="fullimpulso-comments-cancel-btn" class="btn btn-outline-secondary w-100 py-2">Volver</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div id="payment-pre-confirm-modal" class="modal fade app-overlay-modal payment-confirm-overlay" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:420px;">
      <div class="modal-content bg-dark text-light rounded-4 p-0" style="border:1px solid #22d3ee;">
        <div class="p-4 pb-3">
          <!-- Card de recordatorios -->
          <div class="rounded-3 p-3 mb-3" style="background:rgba(250,204,21,.08);border:1.5px solid #facc15;">
            <div class="fw-bold mb-2" style="color:#facc15;font-size:1rem;">⚠ Recordatorios Importantes antes de Pagar</div>
            <div class="small mb-1" style="color:#e2e8f0;">🟢 Debe pagar el <strong>monto exacto</strong> indicado para el producto seleccionado. No transfiera sin verificar el monto en pantalla.</div>
            <div class="small mb-1" style="color:#e2e8f0;">🕐 <strong>Registre su pago al momento de realizarlo.</strong> Los pagos de días anteriores <strong>NO son válidos</strong> y no serán procesados.</div>
            <div class="small" style="color:#e2e8f0;">🚫 <strong>No se realizan devoluciones ni se aceptan reclamos por errores del cliente.</strong> Verifique minuciosamente el ID de jugador y el producto antes de confirmar.</div>
          </div>
          <!-- Card legal fondos -->
          <div class="rounded-3 p-3 mb-3 text-center small" style="background:rgba(34,197,94,.08);border:1.5px solid rgba(34,197,94,.5);color:#d1fae5;">
            Al confirmar esta compra, declaras que tus fondos son <strong>de origen lícito, de tu propiedad exclusiva</strong> (no de terceros).
          </div>
          <!-- Checkbox aceptar condiciones -->
          <div class="d-flex align-items-center gap-3 rounded-3 px-3 py-2 mb-4" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);">
            <div class="form-check form-switch m-0">
              <input class="form-check-input" type="checkbox" role="switch" id="pre-confirm-tos-check" style="width:2.5em;height:1.4em;cursor:pointer;">
            </div>
            <label for="pre-confirm-tos-check" class="small mb-0" style="color:#e2e8f0;cursor:pointer;">He leído y acepto las condiciones del servicio.</label>
          </div>
          <!-- Botones -->
          <div class="d-flex gap-2 flex-column">
            <button type="button" id="pre-confirm-proceed-btn" class="btn btn-info w-100 fw-bold text-uppercase py-2 payment-submit-btn-theme<?= $paymentWindowConfigEnabled ? ' payment-window-theme-enabled' : '' ?>" disabled>
              CONFIRMAR COMPRA
            </button>
            <button type="button" id="pre-confirm-cancel-btn" class="btn btn-outline-secondary w-100 py-2">Volver</button>
          </div>
        </div>
      </div>
    </div>
  </div>

<style>
  /* ── Multi-cart toggle ─────────────────────────────────────── */
  .multi-cart-toggle-wrap {
    display: flex;
    justify-content: center;
    margin-bottom: 1.25rem;
  }
  .multi-cart-toggle-label {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    cursor: pointer;
    padding: 0.5rem 1.1rem;
    border-radius: 999px;
    background: rgba(34,211,238,.07);
    border: 1.5px solid rgba(34,211,238,.28);
    color: #22d3ee;
    font-size: 0.88rem;
    font-weight: 600;
    transition: background 0.18s, border-color 0.18s;
    user-select: none;
  }
  .multi-cart-toggle-label:hover {
    background: rgba(34,211,238,.14);
    border-color: rgba(34,211,238,.55);
  }
  .multi-cart-toggle-input {
    appearance: none;
    width: 2.4rem;
    height: 1.2rem;
    border-radius: 999px;
    border: 1.5px solid #22d3ee;
    background: transparent;
    position: relative;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.18s;
  }
  .multi-cart-toggle-input::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 2px;
    transform: translateY(-50%);
    width: 0.85rem;
    height: 0.85rem;
    border-radius: 50%;
    background: #22d3ee;
    transition: left 0.18s;
  }
  .multi-cart-toggle-input:checked {
    background: rgba(34,211,238,.25);
  }
  .multi-cart-toggle-input:checked::after {
    left: calc(100% - 0.9rem);
  }
  .multi-cart-toggle-icon {
    opacity: 0.85;
    display: flex;
    align-items: center;
  }
  /* ── Float cart FAB ─────────────────────────────────────── */
  /* Fallback: si el FAB no está dentro del .floating-social-stack lo posiciona bottom-right */
  #float-cart-fab:not(.floating-social-stack *) {
    position: fixed;
    right: 1rem;
    bottom: 1rem;
    z-index: 1050;
  }
  .float-cart-fab-btn {
    background: linear-gradient(135deg, rgba(99,102,241,.95), rgba(34,211,238,.88));
    border-color: rgba(99,102,241,.7);
    color: #fff;
    box-shadow: 0 0 18px rgba(99,102,241,.35), 0 0 40px rgba(34,211,238,.15);
  }
  .float-cart-fab-btn:hover {
    color: #fff;
    box-shadow: 0 0 28px rgba(99,102,241,.5), 0 0 60px rgba(34,211,238,.22);
  }
  .float-cart-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    background: #22d3ee;
    color: #0b0f18;
    font-size: 0.68rem;
    font-weight: 800;
    border-radius: 999px;
    min-width: 1.15rem;
    height: 1.15rem;
    line-height: 1.15rem;
    text-align: center;
    padding: 0 0.2rem;
  }
  /* ── Fly-to-cart ghost ──────────────────────────────────── */
  .fly-ghost {
    position: fixed;
    width: 56px;
    height: 56px;
    border-radius: 0.5rem;
    pointer-events: none;
    z-index: 9999;
    overflow: hidden;
    border: 1.5px solid rgba(34,211,238,.6);
    box-shadow: 0 0 14px rgba(34,211,238,.45);
  }
  .fly-ghost img { width: 100%; height: 100%; object-fit: cover; }
  @keyframes cartFabPop {
    0%   { transform: scale(1); }
    40%  { transform: scale(1.28); }
    100% { transform: scale(1); }
  }
  .cart-fab-pop { animation: cartFabPop 0.32s ease-out !important; }
  /* ── Pack card: account-sale disabled overlay in cart mode ── */
  .pack-card.cart-mode-account-disabled {
    opacity: 0.45;
    cursor: not-allowed;
    pointer-events: none;
  }
  .pack-card.cart-mode-account-disabled::after {
    content: 'No disponible en modo carrito';
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(5,10,20,.82);
    color: #f87171;
    font-size: 0.72rem;
    font-weight: 700;
    text-align: center;
    padding: 0.5rem;
    border-radius: inherit;
  }
  /* ── Cart Modal ────────────────────────────────────────────── */
  .multi-cart-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.25rem 0.75rem;
    border-bottom: 1px solid rgba(34,211,238,.2);
  }
  .multi-cart-modal-title-wrap {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #22d3ee;
  }
  .multi-cart-modal-title { font-size: 1.05rem; font-weight: 700; margin: 0; color: #22d3ee; }
  .multi-cart-modal-body { padding: 0.5rem 0; max-height: 60vh; overflow-y: auto; }
  .multi-cart-item {
    display: grid;
    grid-template-columns: 60px 1fr auto auto;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,.06);
  }
  .multi-cart-item:last-child { border-bottom: none; }
  .multi-cart-item-img {
    width: 60px; height: 60px;
    border-radius: 0.5rem;
    object-fit: cover;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(34,211,238,.2);
    flex-shrink: 0;
  }
  .multi-cart-item-img-placeholder {
    width: 60px; height: 60px;
    border-radius: 0.5rem;
    background: rgba(34,211,238,.07);
    border: 1px solid rgba(34,211,238,.2);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    color: rgba(34,211,238,.35);
  }
  .multi-cart-item-name { font-size: 0.95rem; font-weight: 700; color: #e2e8f0; line-height: 1.3; }
  .multi-cart-item-sub { font-size: 0.82rem; color: #22d3ee; font-weight: 600; margin-top: 0.2rem; }
  .multi-cart-item-stepper {
    display: flex;
    align-items: center;
    gap: 0.3rem;
    background: rgba(255,255,255,.06);
    border-radius: 0.5rem;
    padding: 0.25rem 0.4rem;
  }
  .multi-cart-item-stepper button {
    width: 1.7rem; height: 1.7rem;
    border: 1px solid rgba(34,211,238,.35);
    background: transparent;
    color: #22d3ee;
    border-radius: 0.35rem;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    line-height: 1;
    display: flex; align-items: center; justify-content: center;
    transition: background 0.14s;
  }
  .multi-cart-item-stepper button:hover { background: rgba(34,211,238,.15); }
  .multi-cart-item-qty { min-width: 1.6rem; text-align: center; font-size: 0.95rem; font-weight: 700; color: #e2e8f0; }
  .multi-cart-item-price { font-size: 0.95rem; font-weight: 700; color: #22d3ee; white-space: nowrap; text-align: right; }
  .multi-cart-item-del {
    background: transparent;
    border: none;
    color: #f87171;
    cursor: pointer;
    padding: 0.3rem 0.35rem;
    border-radius: 0.35rem;
    display: flex; align-items: center; justify-content: center;
    transition: background 0.14s;
  }
  .multi-cart-item-del:hover { background: rgba(248,113,113,.12); }
  .multi-cart-empty-state {
    text-align: center;
    padding: 2rem 1rem;
    color: #64748b;
    font-size: 0.88rem;
  }
  .multi-cart-modal-footer {
    padding: 0.75rem 1.25rem 1rem;
    border-top: 1px solid rgba(34,211,238,.2);
  }
  .multi-cart-modal-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
  }
  .multi-cart-modal-total-label { font-size: 0.88rem; color: #94a3b8; font-weight: 600; }
  .multi-cart-modal-total-value { font-size: 1.35rem; color: #22d3ee; font-weight: 800; }
  .multi-cart-modal-actions { display: flex; gap: 0.6rem; }
  .multi-cart-modal-actions .btn { flex: 1; }
  .multi-cart-proceed-btn { background: linear-gradient(90deg,#6366f1,#22d3ee); border: none; color: #fff; font-size: 0.85rem; }
  .multi-cart-proceed-btn:hover { opacity: 0.9; color: #fff; }

  /* ── Batch Progress Modal ──────────────────────────────────── */
  .batch-progress-modal-header {
    padding: 1.1rem 1.25rem 0.6rem;
    border-bottom: 1px solid rgba(34,211,238,.2);
  }
  .batch-progress-modal-title { font-size: 1.05rem; font-weight: 700; color: #22d3ee; margin: 0 0 0.25rem; }
  .batch-progress-current-label { font-size: 0.8rem; color: #94a3b8; margin: 0; }
  .batch-progress-bar-wrap {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,.06);
  }
  .batch-progress-bar-track { flex: 1; height: 6px; background: rgba(255,255,255,.08); border-radius: 999px; overflow: hidden; }
  .batch-progress-bar-fill { height: 100%; background: linear-gradient(90deg,#6366f1,#22d3ee); border-radius: 999px; transition: width 0.5s ease; }
  .batch-progress-fraction { font-size: 0.75rem; font-weight: 700; color: #94a3b8; white-space: nowrap; }
  .batch-progress-items { max-height: 42vh; overflow-y: auto; padding: 0.35rem 0; }
  .batch-progress-item {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem;
    padding: 0.55rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,.05);
    font-size: 0.83rem;
  }
  .batch-progress-item:last-child { border-bottom: none; }
  .batch-progress-item-icon { width: 1.25rem; text-align: center; font-size: 0.9rem; flex-shrink: 0; }
  .batch-progress-item-name { flex: 1; color: #e2e8f0; font-weight: 600; line-height: 1.2; }
  .batch-progress-item-qty { color: #94a3b8; flex-shrink: 0; }
  .batch-progress-item-status { flex-shrink: 0; font-weight: 700; font-size: 0.76rem; }
  .batch-status-pending { color: #94a3b8; }
  .batch-status-processing { color: #facc15; }
  .batch-status-done { color: #4ade80; }
  .batch-status-partial { color: #fb923c; }
  .batch-status-error { color: #f87171; }
  .batch-account-delivery { width: 100%; margin-top: 0.5rem; padding: 0.75rem; background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.3); border-radius: 8px; }
  .batch-account-text { font-family: monospace; font-size: 0.78rem; color: #d1fae5; white-space: pre-wrap; word-break: break-all; margin-bottom: 0.4rem; }
  .batch-account-copy-btn { margin-top: 0.3rem; padding: 0.25rem 0.7rem; font-size: 0.73rem; background: rgba(16,185,129,.2); color: #34d399; border: 1px solid rgba(16,185,129,.4); border-radius: 6px; cursor: pointer; }
  .batch-account-copy-btn:hover { background: rgba(16,185,129,.35); }
  .batch-account-gallery { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
  .batch-account-gallery img { max-width: 110px; border-radius: 6px; border: 1px solid rgba(255,255,255,.1); }
  .batch-accounts-footer-list { margin-bottom: 0.75rem; }
  .batch-accounts-footer-title { font-weight: 700; color: #22d3ee; font-size: 0.82rem; margin-bottom: 0.6rem; text-transform: uppercase; letter-spacing: .04em; }
  .batch-account-summary-item { padding: 0.6rem 0; border-bottom: 1px solid rgba(255,255,255,.06); }
  .batch-account-summary-item:last-child { border-bottom: none; }
  .batch-account-summary-name { font-size: 0.8rem; font-weight: 600; color: #94a3b8; margin-bottom: 0.35rem; }
  .cart-account-qty-fixed { color: #34d399; font-size: 0.78rem; font-weight: 700; padding: 0 0.2rem; }
  .batch-progress-footer { padding: 0.85rem 1.25rem; border-top: 1px solid rgba(34,211,238,.2); }
  /* ── Payment modal cart summary ────────────────────────────── */
  #payment-cart-summary { display: none; }
  #payment-cart-summary.is-visible { display: block; }
  .payment-cart-summary-list { list-style: none; margin: 0; padding: 0; }
  .payment-cart-summary-item {
    display: flex;
    justify-content: space-between;
    font-size: 0.8rem;
    color: #94a3b8;
    padding: 0.22rem 0;
    border-bottom: 1px solid rgba(255,255,255,.05);
  }
  .payment-cart-summary-item:last-child { border-bottom: none; }
  .payment-cart-summary-item span:last-child { font-weight: 600; color: #e2e8f0; }

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
    z-index: 13300;
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

  /* Título "PASO N: ..." completo — configurable desde /admin/diseno-pasos
     (paso1/paso2/paso3, cada uno independiente, con texto editable). En
     modo original no lleva clase extra (hereda el look de siempre de
     .page-step-title); en modo personalizado, includes/paso_estilos.php
     arma el style inline (fondo, letra, borde neón) sobre TODO el texto, y
     esta clase solo pone la forma de bloque redondeado que ese estilo va a
     rellenar. */
  .paso-linea-custom {
    display: inline-block;
    padding: 0.5rem 1.2rem;
    border-radius: 14px;
    /* line-height propio (no el 1.08 heredado de .page-step-title) porque el
       padding necesita más aire vertical para no verse apretado dentro del
       recuadro — vertical-align corrige el nivel contra el span vecino sin
       recuadro (el "resto" de la frase), que sí usa el line-height base. */
    line-height: 1.3;
    vertical-align: middle;
  }

  /* Botón de verificación con ícono a la izquierda del texto (opcional,
     solo en modo personalizado — ver includes/paso_estilos.php). */
  .paso-boton-verif {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
  }
  .paso-boton-icon-img {
    width: 1.2rem;
    height: 1.2rem;
    object-fit: contain;
    display: block;
  }

  /* Resultado del verificador de jugador (éxito/fallo), modo personalizado.
     En modo original sigue siendo el `alert alert-success/alert-danger`
     normal de Bootstrap — esta clase solo existe cuando hay diseño propio.
     3 columnas: ícono | mensaje (nombre del jugador o motivo de fallo) |
     insignia fija VERIFICADO/INVALIDO con su propio color, independiente
     del resto del banner. */
  .paso-verif-banner {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    border-radius: 12px;
    padding: 0.65rem 0.9rem;
  }
  .paso-verif-banner .paso-verif-icon {
    font-size: 1.4rem;
    line-height: 1;
    flex-shrink: 0;
  }
  .paso-verif-banner .paso-verif-icon-img {
    width: 1.6rem;
    height: 1.6rem;
    object-fit: contain;
    display: block;
  }
  .paso-verif-banner .paso-verif-text {
    flex: 1 1 auto;
    min-width: 0;
  }
  .paso-verif-banner .paso-verif-badge {
    flex-shrink: 0;
    font-weight: 900;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    white-space: nowrap;
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
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

  @keyframes badge-shine {
    0%, 55%  { transform: translateX(-220%); opacity: 0; }
    60%      { opacity: 1; }
    80%      { transform: translateX(280%); opacity: 1; }
    81%, 100%{ transform: translateX(280%); opacity: 0; }
  }
  @keyframes badge-glow-pulse {
    0%, 100% { box-shadow: 0 0 5px rgba(34,211,238,.18), 0 2px 8px rgba(0,0,0,.5), inset 0 1px 0 rgba(34,211,238,.1); }
    50%      { box-shadow: 0 0 14px rgba(34,211,238,.45), 0 2px 8px rgba(0,0,0,.5), inset 0 1px 0 rgba(34,211,238,.18); }
  }

  .payment-method-public-price-badge {
    position: absolute;
    bottom: 0.4rem;
    right: 0.5rem;
    z-index: 3;
    padding: 0.22rem 0.65rem;
    border-radius: 0.45rem;
    background: linear-gradient(135deg, rgba(2,8,28,.96) 0%, rgba(4,18,40,.94) 100%);
    border: 1px solid rgba(34,211,238,.55);
    color: #22d3ee;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    line-height: 1.4;
    pointer-events: none;
    backdrop-filter: blur(8px);
    white-space: nowrap;
    max-width: calc(100% - 1rem);
    overflow: hidden;
    text-overflow: ellipsis;
    text-shadow: 0 0 8px rgba(34,211,238,.9), 0 0 18px rgba(34,211,238,.4);
    animation: badge-glow-pulse 2.8s ease-in-out infinite;
  }
  .payment-method-public-price-badge::before {
    content: '';
    position: absolute;
    top: -10%;
    left: 0;
    width: 44%;
    height: 120%;
    background: linear-gradient(105deg, transparent 0%, rgba(255,255,255,.12) 35%, rgba(255,255,255,.38) 50%, rgba(255,255,255,.12) 65%, transparent 100%);
    transform: translateX(-220%);
    animation: badge-shine 5.5s ease-in-out infinite;
    pointer-events: none;
  }
  .payment-method-public-card:nth-child(1) .payment-method-public-price-badge::before { animation-delay: 0s; }
  .payment-method-public-card:nth-child(2) .payment-method-public-price-badge::before { animation-delay: 1.1s; }
  .payment-method-public-card:nth-child(3) .payment-method-public-price-badge::before { animation-delay: 2.2s; }
  .payment-method-public-card:nth-child(4) .payment-method-public-price-badge::before { animation-delay: 3.3s; }
  .payment-method-public-card:nth-child(5) .payment-method-public-price-badge::before { animation-delay: 4.4s; }

  .payment-method-public-card.is-selected .payment-method-public-price-badge {
    border-color: rgba(74,222,128,.7);
    color: #4ade80;
    text-shadow: 0 0 8px rgba(74,222,128,.9), 0 0 18px rgba(74,222,128,.4);
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

  .discount-winner-banner {
    margin: 0.5rem 0 0;
    padding: 0.55rem 1rem;
    background: rgba(34, 211, 238, 0.08);
    border: 1px solid rgba(34, 211, 238, 0.28);
    border-radius: 0.75rem;
    color: #22d3ee;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    text-align: center;
  }

  .win-points-restriction-badge {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    margin-top: 0.5rem;
    padding: 0.55rem 0.8rem;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 0.65rem;
    color: #fca5a5;
    font-size: 0.78rem;
    line-height: 1.4;
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

  /* Paquete bloqueado (pase de nivel no disponible / stock BS Pass ya
     usado / error del validador): se mantiene visible en la grilla, pero
     oscurecido, deshabilitado y con una etiqueta encima explicando por qué.
     El oscurecido (filter/opacity) se aplica SOLO al contenido interno
     (.card-body), nunca a .pack-card en sí: el filter de un elemento
     "arrastra" también a sus pseudo-elementos, así que si se aplicara sobre
     la tarjeta completa, la etiqueta de texto (::after) quedaría opacada y
     grisácea junto con la imagen, en vez de verse nítida por encima. */
  .pack-card.bs-pass-blocked,
  .pack-card.levelpass-locked {
    position: relative;
    cursor: not-allowed;
    pointer-events: none;
  }

  .pack-card.bs-pass-blocked > .card-body,
  .pack-card.levelpass-locked > .card-body {
    filter: grayscale(0.85);
    opacity: 0.5;
  }

  .pack-card.bs-pass-blocked::after,
  .pack-card.levelpass-locked::after {
    content: attr(data-lock-label);
    position: absolute;
    inset: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 0.75rem;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #22d3ee;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.85);
    background: rgba(8, 12, 20, 0.62);
    border-radius: inherit;
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
    left: -0.55rem;
    right: auto;
    z-index: 4;
    display: flex;
    flex-wrap: wrap;
    gap: 0.42rem;
    justify-content: flex-start;
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
    position: relative;
    overflow: hidden;
  }

  /* Destello de luz que recorre el badge de izquierda a derecha cada 5s */
  .pack-card-feature-badge::after {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    left: -80%;
    width: 55%;
    background: linear-gradient(105deg, transparent 0%, rgba(255, 255, 255, 0.06) 30%, rgba(255, 255, 255, 0.55) 50%, rgba(255, 255, 255, 0.06) 70%, transparent 100%);
    transform: skewX(-20deg);
    animation: packBadgeShine 2s linear infinite;
    pointer-events: none;
  }

  @keyframes packBadgeShine {
    0%   { left: -80%; }
    35%  { left: 140%; }
    100% { left: 140%; }
  }

  /* ── Botón "i" de información del paquete ── */
  .pack-info-btn {
    position: absolute;
    top: 0.4rem;
    right: 0.4rem;
    z-index: 5;
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.8);
    background: rgba(8, 15, 26, 0.82);
    color: #ffffff;
    cursor: pointer;
    padding: 0;
    backdrop-filter: blur(4px);
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.45), 0 0 8px rgba(255, 255, 255, 0.18);
    transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
  }
  .pack-info-btn:hover,
  .pack-info-btn:focus-visible {
    transform: scale(1.12);
    background: rgba(var(--theme-primary-rgb), 0.28);
    border-color: rgba(var(--theme-primary-rgb), 0.8);
  }

  #pack-info-modal .pack-info-modal-body font[size="1"] { font-size: 0.78rem; }
  #pack-info-modal .pack-info-modal-body font[size="4"] { font-size: 1.2rem; }
  #pack-info-modal .pack-info-modal-body font[size="6"] { font-size: 1.6rem; }

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

  .pack-card-price-block {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.1rem;
  }

  .pack-card-drop-row {
    display: flex;
    align-items: center;
    gap: 0.22rem;
  }

  .precio-original-label {
    color: var(--theme-price-muted);
    font-size: 0.65rem;
    font-weight: 600;
    text-decoration: line-through;
    opacity: 0.55;
    line-height: 1;
  }

  .pack-card-drop-badge {
    background: #ef4444;
    color: #fff;
    font-size: 0.55rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    padding: 0.1rem 0.28rem;
    border-radius: 0.25rem;
    line-height: 1.4;
    flex-shrink: 0;
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

  /* ── Tarjetas de paquetes con TAMAÑO FIJO ──────────────────────────
     Las cards no se estiran según la pantalla: mantienen un ancho fijo
     (250px desde desktop hasta tablet, 158px en móvil). La caja de la
     imagen usa la proporción real de las imágenes de paquetes
     (1511 × 704 ≈ 2.146:1) para que se vean SIEMPRE completas, sin
     cortes, en todos los tamaños. Filas alineadas a la izquierda. */
  #pack-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-start;
    gap: 1rem;
    margin-left: 0;
    margin-right: 0;
  }
  #pack-grid > * {
    flex: 0 0 auto;
    width: 250px;
    max-width: 250px;
    padding: 0;
    margin-top: 0;
  }
  .pack-category-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 2.1rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 0.2rem;
  }
  .pack-category-tab-btn {
    --pack-cat-color: rgb(var(--theme-button-primary-rgb));
    --pack-cat-text: var(--theme-bg-main, #fff);
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    flex-shrink: 0;
    padding: 0.45rem 1.05rem;
    border-radius: 999px;
    border: 1px solid rgba(var(--theme-button-primary-rgb), 0.32);
    background: rgba(var(--theme-button-surface-rgb), 0.6);
    color: var(--theme-text-muted);
    font-weight: 600;
    font-size: 0.88rem;
    letter-spacing: 0.01em;
    cursor: pointer;
    transition: background 0.22s ease, color 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
  }
  .pack-category-tab-btn:hover {
    border-color: var(--pack-cat-color);
    color: var(--theme-text);
  }
  .pack-category-tab-btn.active {
    background: var(--pack-cat-color);
    border-color: var(--pack-cat-color);
    color: var(--pack-cat-text);
    box-shadow: 0 0 16px var(--pack-cat-color);
  }
  .pack-category-tab-icon {
    font-size: 1.05em;
    line-height: 1;
  }
  #pack-grid .pack-card-media {
    height: 116px; /* 250px × 704/1511 */
    aspect-ratio: auto;
    min-height: 0;
  }
  #pack-grid .pack-card-image {
    object-fit: contain; /* imagen completa siempre visible, sin recortes */
  }
  #pack-grid .pack-card {
    min-height: 0;
  }
  #pack-grid .pack-card-name {
    min-height: 0; /* el nombre ocupa solo 1 línea, sin espacio reservado */
  }
  @media (max-width: 767.98px) {
    #pack-grid {
      gap: 0.5rem;
      /* Ancho exacto de 2 columnas, centrado: márgenes laterales simétricos
         y la card impar queda alineada bajo la primera columna. */
      max-width: calc(158px * 2 + 0.5rem);
      margin-left: auto;
      margin-right: auto;
    }
    #pack-grid > * {
      width: 158px;
      max-width: 158px;
    }
    #pack-grid .pack-card-media {
      height: 74px; /* 158px × 704/1511 — misma proporción reducida */
    }
  }

  @media (max-width: 575.98px) {
    .pack-card {
      min-height: 0;
      border-radius: 0.75rem;
    }
    .pack-card-media {
      aspect-ratio: 1 / 1;
      min-height: 0;
      border-radius: calc(0.75rem - 1px) calc(0.75rem - 1px) 0 0;
    }
    .pack-card-content {
      padding: 0.4rem 0.5rem 0.5rem;
      gap: 0.35rem;
    }
    .pack-card-name {
      font-size: 0.72rem;
      min-height: 0;
      line-height: 1.2;
    }
    .pack-card-footer {
      padding-top: 0.35rem;
      gap: 0.25rem;
    }
    .moneda-label {
      font-size: 0.6rem;
      letter-spacing: 0.08em;
    }
    .precio-label {
      font-size: 0.82rem;
    }
    .pack-card-feature-badge {
      padding: 0.18rem 0.38rem;
      min-height: 1.3rem;
      gap: 0.22rem;
    }
    .pack-card-feature-text {
      font-size: 0.6rem;
    }
    .pack-card:hover,
    .pack-card:focus-visible {
      transform: translateY(-4px) scale(1.01);
    }
    .neon-selected {
      transform: translateY(-4px) scale(1.012);
    }
    .neon-selected:hover,
    .neon-selected:focus-visible {
      transform: translateY(-6px) scale(1.016);
    }
    .pack-account-sale-meta {
      flex-direction: column;
      gap: 0.3rem;
      align-items: stretch;
    }
    .pack-account-sale-badge {
      font-size: 0.6rem;
      padding: 0.2rem 0.5rem;
      align-self: flex-start;
    }
    .pack-account-preview-btn {
      width: 100%;
      padding: 0.28rem 0.5rem;
      font-size: 0.65rem;
      letter-spacing: 0.04em;
    }
    .pack-win-points-badge {
      padding: 0.28rem 0.45rem;
      gap: 0.28rem;
      font-size: 0.62rem;
    }
    .pack-win-points-icon {
      width: 1.2rem;
      height: 1.2rem;
    }
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
      grid-template-columns: repeat(3, minmax(0, 1fr));
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
      left: -0.38rem;
      right: auto;
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

  /* Pre-confirm modal: layout compacto en pantallas con poca altura */
  @media (max-height: 680px) {
    #payment-pre-confirm-modal .modal-content > div {
      padding: 0.65rem !important;
    }
    #payment-pre-confirm-modal .modal-content > div > div:first-child {
      padding: 0.55rem 0.65rem !important;
      margin-bottom: 0.45rem !important;
    }
    #payment-pre-confirm-modal .modal-content > div > div:nth-child(2) {
      padding: 0.45rem 0.65rem !important;
      margin-bottom: 0.45rem !important;
    }
    #payment-pre-confirm-modal .modal-content > div > div:nth-child(3) {
      padding: 0.3rem 0.65rem !important;
      margin-bottom: 0.55rem !important;
    }
    #payment-pre-confirm-modal .modal-content > div > div:nth-child(4) {
      gap: 0.3rem !important;
    }
    #payment-pre-confirm-modal .modal-content .btn {
      padding-top: 0.3rem !important;
      padding-bottom: 0.3rem !important;
      font-size: 0.85rem !important;
    }
    #payment-pre-confirm-modal #pre-confirm-tos-check {
      width: 2em !important;
      height: 1.15em !important;
    }
    #payment-pre-confirm-modal .fw-bold[style*="font-size:1rem"] {
      font-size: 0.85rem !important;
    }
    #payment-pre-confirm-modal .small {
      font-size: 0.76rem !important;
    }
  }

  #payment-pre-confirm-modal.is-visible {
    display: flex !important;
    align-items: center;
  }
  #payment-pre-confirm-modal .modal-dialog {
    margin: 0.75rem auto;
    width: calc(100% - 2rem);
    max-width: 420px;
  }
</style>
<script>
  // Todas las variables y lógica JS en un solo bloque
  const appBasePath = <?= json_encode($scriptDir, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const rememberLastPurchaseIdentifierEnabled = <?= $rememberLastPurchaseIdentifierEnabled ? 'true' : 'false' ?>;
  const defaultOrderEmail = <?= json_encode($loggedUserEmail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let defaultOrderUserIdentifier = <?= json_encode($loggedUserLastPurchaseIdentifier, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || localStorage.getItem('rbs_player_id') || '';
  let defaultPaymentPhone = <?= json_encode($loggedUserLastPurchasePhone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let defaultPaymentNombre = <?= json_encode($loggedUserLastPurchaseNombre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let defaultPaymentCedula = <?= json_encode($loggedUserLastPurchaseCedula, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let defaultPlayerZoneId = <?= json_encode($loggedUserLastPurchaseZoneId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
  const currentUserIsAdmin = <?= json_encode(in_array($loggedUserRole, ['admin', 'root'], true)) ?>;
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
    'gameHasAnyRule' => $gameHasAnyPointsRule,
    'loggedIn' => $loggedUserId > 0,
    'name' => $winPointsProgramName,
    'iconUrl' => $winPointsIconUrl,
    'paymentImageUrl' => $winPointsPaymentImageUrl,
    'paymentCornerImageUrl' => $winPointsPaymentCornerImageUrl,
    'notificationLogoUrl' => $winPointsNotificationLogoUrl,
    'notificationPosition' => $winPointsNotificationPosition,
    'guestMessage' => $winPointsGuestMessage,
    'balance' => (int) ($winPointsUserSummary['balance'] ?? 0),
    'monthlyMinimumMet' => (bool) ($winPointsMonthlyStatus['met'] ?? true),
    'monthlyMinimumSpent' => (float) ($winPointsMonthlyStatus['spent'] ?? 0.0),
    'monthlyMinimumRequired' => (float) ($winPointsMonthlyStatus['required'] ?? 0.0),
    'isAdmin' => in_array($loggedUserRole, ['root', 'admin'], true),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const gameUsesCatalogApi = <?= $usesCatalogApi ? 'true' : 'false' ?>;
  const paymentHeaderMinimalEnabled = <?= $paymentHeaderMinimalEnabled ? 'true' : 'false' ?>;
  const packageFeatureIconSvgMap = <?= json_encode(package_feature_icon_svg_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const packGrid = document.getElementById('pack-grid');
  const packCards2 = Array.from(document.querySelectorAll('.pack-card'));
  const packAccountPreviewButtons = Array.from(document.querySelectorAll('.pack-account-preview-btn'));

  // Ocultar PASO 1 (ID jugador) y renumerar pasos para juegos tipo cuenta y giftcard
  (function adjustPlayerStep() {
    if (packCards2.length === 0) return;
    const allNoId = packCards2.every(function(card) {
      if (card.dataset.accountSale === '1') return true;
      try {
        const fields = JSON.parse(card.dataset.requiredFields || '[]');
        return card.dataset.packageProvider === 'giftven' && fields.length === 0;
      } catch (e) { return false; }
    });
    if (!allNoId) return;
    window.__gameNoPlayerIdRequired = true;
    const sec1 = document.getElementById('player-step-section');
    const sec2 = document.getElementById('player-info-section');
    if (sec1) sec1.style.display = 'none';
    if (sec2) {
      sec2.style.display = 'none';
      sec2.querySelectorAll('[required]').forEach(function(el) { el.removeAttribute('required'); });
    }
    // La insignia "PASO N" vive en su propio <span data-paso-linea> (el
    // resto de la frase es OTRO span aparte, sin este atributo — ver
    // includes/paso_estilos.php) así que acá solo hace falta renumerar ese
    // span, que contiene únicamente "PASO N" y nada más.
    const paso2Linea = document.querySelector('#game-packages-section .page-step-title [data-paso-linea]');
    const paso3Linea = document.querySelector('#payment-step-section .page-step-title [data-paso-linea]');
    if (paso2Linea) paso2Linea.textContent = paso2Linea.textContent.replace(/^PASO\s*2/, 'PASO 1');
    if (paso3Linea) paso3Linea.textContent = paso3Linea.textContent.replace(/^PASO\s*3/, 'PASO 2');
  })();
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
  const orderEmailInput = document.getElementById('order-email-input');
  const buyButton = document.getElementById("buy-button");
  const accountSaleNote = document.getElementById('account-sale-note');
  const defaultBuyButtonLabel = 'Continuar con la Compra';
  const paymentDifferenceBlockedBuyButtonLabel = 'Selecciona un paquete mayor al saldo a favor';
  const defaultPaymentSubmitButtonLabel = 'Realizar Compra';
  function buildConfirmButtonLabel(totalText) {
    const t = String(totalText || '').trim();
    return t ? 'Realizar Compra - ' + t : defaultPaymentSubmitButtonLabel;
  }
  const completeRechargeButtonLabel = 'Completar Recarga';
  const verifyUserBuyButtonLabel = 'Debe Verificar El usuario para poder comprar';
  const playerPrimaryField = document.getElementById('player-primary-field');
  const playerPrimaryLabel = document.getElementById('player-primary-label');
  let playerPrimaryInput = document.getElementById('order-user-id');
  const extraPlayerFields = document.getElementById('extra-player-fields');
  const verifyPlayerButton = document.getElementById('verify-player-button');
  const playerVerificationFeedback = document.getElementById('player-verification-feedback');
  const playerVerificationConfig = <?= json_encode($playerVerificationConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const pasoEstiloVerificacion = <?= json_encode([
    'exito' => paso_estilo_js_config('exito'),
    'fallo' => paso_estilo_js_config('fallo'),
    'boton' => [
        'personalizado' => paso_estilo_esta_personalizado('boton'),
        'texto' => paso_estilo_boton_texto_efectivo($playerVerificationConfig),
    ],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const bsPassStockConfig = <?= json_encode([
    'enabled' => bs_pass_stock_is_configured() && !empty($bsPassStockPackageIds) && $playerVerificationConfig !== null,
    'packageIds' => array_map('strval', $bsPassStockPackageIds ?? []),
    'gameId' => (int) ($game['id'] ?? 0),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const levelPassConfig = <?= json_encode([
    'enabled' => levelpass_is_configured() && !empty($levelPassPackageIds) && $playerVerificationConfig !== null,
    'packageIds' => array_map('strval', array_keys($levelPassPackageIds ?? [])),
    'gameId' => (int) ($game['id'] ?? 0),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
  const dailyMissionSimulatePurchaseButton = document.getElementById('daily-mission-simulate-purchase-btn');
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
  let paymentStatusShouldCloseAll = false;
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
  const paymentAdvancedForm = document.getElementById('payment-advanced-form');
  const paymentNombreInput = document.getElementById('payment-nombre-input');
  const paymentCedulaInput = document.getElementById('payment-cedula-input');
  const paymentPhoneAdvInput = document.getElementById('payment-phone-adv-input');
  const paymentAdvReferenceGroup = document.getElementById('payment-adv-reference-group');
  const paymentAdvReferenceInput = document.getElementById('payment-adv-reference-input');
  const paymentAdvReferenceHelp = document.getElementById('payment-adv-reference-help');
  const paymentWhatsappWrap = document.getElementById('payment-whatsapp-wrap');
  const paymentWhatsappBtn = document.getElementById('payment-whatsapp-btn');
  const paymentWhatsappConfirmModal = document.getElementById('payment-whatsapp-confirm-modal');
  const paymentWhatsappModalCancelBtn = document.getElementById('payment-whatsapp-modal-cancel-btn');
  const paymentWhatsappModalConfirmBtn = document.getElementById('payment-whatsapp-modal-confirm-btn');
  const paymentPreConfirmModal = document.getElementById('payment-pre-confirm-modal');
  const preConfirmTosCheck = document.getElementById('pre-confirm-tos-check');
  const preConfirmProceedBtn = document.getElementById('pre-confirm-proceed-btn');
  const preConfirmCancelBtn = document.getElementById('pre-confirm-cancel-btn');
  const fullimpulsoCommentsModal = document.getElementById('fullimpulso-comments-modal');
  const fullimpulsoCommentsTextarea = document.getElementById('fullimpulso-comments-textarea');
  const fullimpulsoCommentsCount = document.getElementById('fullimpulso-comments-count');
  const fullimpulsoCommentsError = document.getElementById('fullimpulso-comments-error');
  const fullimpulsoCommentsContinueBtn = document.getElementById('fullimpulso-comments-continue-btn');
  const fullimpulsoCommentsCancelBtn = document.getElementById('fullimpulso-comments-cancel-btn');
  let fullimpulsoCommentsOnContinue = null;
  let fullimpulsoCommentsOnCancel = null;
  let pendingWhatsappUrl = '';
  let pendingPaymentExecution = null;
  let pendingOpenModal = null;
  let lastFocusedElement = null;
  let activePack = null;
  // Cart mode state — declared at global scope so all functions can access it
  let cartMode = false;
  let cartItems = [];
  let cartTotalBlindado = null;
  let cartEffectiveTotal = null;
  let updateResumenCompraCart = null;
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
    setReferenceUsedState(false);
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
      accountGallery: parseAccountSaleGallery(card.dataset.accountGallery),
      dropPercent: Math.max(0, Math.min(99, Number(card.dataset.dropPercent || 0))),
      fullimpulsoCustomComments: card.dataset.fullimpulsoCustomComments === '1',
      fullimpulsoCantidad: Number(card.dataset.fullimpulsoCantidad || 0),
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
    // In cart mode keep the cart summary — don't let single-pack logic clear it
    if (typeof cartMode !== 'undefined' && cartMode) {
      if (typeof updateResumenCompraCart === 'function') updateResumenCompraCart();
      return;
    }

    if (!pack) {
      if (preferredCheckoutPaymentMode !== '') {
        publicOrderSummaryShell.classList.remove('d-none');
        if (publicOrderSummaryPanel) {
          publicOrderSummaryPanel.classList.add('is-active');
        }
        if (publicOrderSummaryMethod) {
          const allMethods = getPaymentMethodsForCurrency('');
          const selectedMethod = allMethods.find((m) => String(m.id) === String(preferredCheckoutMethodId || '')) || null;
          const methodLabel = selectedMethod ? String(selectedMethod.nombre || '') : '';
          publicOrderSummaryMethod.textContent = methodLabel;
          publicOrderSummaryMethod.classList.toggle('d-none', !methodLabel);
        }
        if (publicOrderSummaryCoupon && publicOrderSummaryCouponCopy) {
          publicOrderSummaryCoupon.classList.add('d-none');
          publicOrderSummaryCouponCopy.textContent = '';
        }
        publicOrderSummaryRows.innerHTML = '<div class="payment-order-summary-row"><span class="payment-order-summary-row-label" style="color:#94a3b8;font-style:italic;">Selecciona un paquete para ver el precio.</span></div>';
        publicOrderSummaryTotal.textContent = '—';
        publicCheckoutSummaryTotalText = '';
        return;
      }
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
    const pricing = resolvePaymentPricing(selection.mode, selectedMethod, pack);
    const rows = [];
    const couponDiscountAmount = (selection.mode === 'points' || pricing.couponSuppressedByPayment)
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

    // Mostrar mensaje del descuento ganador si hay competencia entre descuentos
    let discountBanner = document.getElementById('discount-winner-banner');
    if (pricing.discountWinnerMessage) {
      if (!discountBanner) {
        discountBanner = document.createElement('div');
        discountBanner.id = 'discount-winner-banner';
        discountBanner.className = 'discount-winner-banner';
        if (publicOrderSummaryShell && publicOrderSummaryShell.parentNode) {
          publicOrderSummaryShell.parentNode.insertBefore(discountBanner, publicOrderSummaryShell.nextSibling);
        }
      }
      discountBanner.textContent = pricing.discountWinnerMessage;
      discountBanner.style.display = '';
    } else if (discountBanner) {
      discountBanner.style.display = 'none';
    }
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
    const monthlyOk = winPointsState.isAdmin || winPointsState.monthlyMinimumMet !== false;
    return Boolean(
      winPointsState.enabled
      && winPointsState.loggedIn
      && monthlyOk
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

  function resolvePaymentModeTaxPercentage(mode, method = null) {
    if (mode === 'paypal') {
      return normalizeDiscountPercentage(paypalPayTaxPercentage);
    }
    if (mode === 'money' && method && Number(method.impuesto_porcentaje || 0) > 0) {
      return normalizeDiscountPercentage(method.impuesto_porcentaje);
    }
    return 0;
  }

  function resolvePaymentPricing(mode = null, method = null, packOverride = null) {
    const pack = packOverride || (activePaymentOrder && activePaymentOrder.pack ? activePaymentOrder.pack : activePack);
    const resolvedMode = mode || (activePaymentOrder ? activePaymentOrder.paymentMode : 'money');
    const quantity = normalizeOrderQuantity(activePaymentOrder && activePaymentOrder.purchaseQuantity ? activePaymentOrder.purchaseQuantity : (pack && pack.purchaseQuantity ? pack.purchaseQuantity : getOrderQuantity()));
    if (resolvedMode === 'points') {
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
    let baseAmount = normalizeCurrencyAmount(Number(activePaymentOrder && activePaymentOrder.baseAmount !== undefined ? activePaymentOrder.baseAmount : selectedTotalValue), showDecimals);
    let discountPercentage = resolvePaymentModeDiscountPercentage(resolvedMode, method);

    // Regla descuento máximo: Drop, Cupón y método de pago compiten; solo el mayor aplica.
    let couponSuppressedByPayment = false;
    let dropSuppressedByPayment = false;
    const dropPercent = pack ? Number(pack.dropPercent || 0) : 0;
    const couponIsActive = couponApplied && Number(appliedCouponSummary.discountAmount || 0) > 0;

    // Regla: paquetes drop mantienen su precio establecido — ningún descuento adicional aplica
    if (dropPercent > 0) {
      const dropBaseAmt = normalizeCurrencyAmount(getPackTotalPrice(pack, quantity), showDecimals);
      const dropTaxPct = resolvePaymentModeTaxPercentage(resolvedMode, method);
      const dropTaxAmt = dropTaxPct > 0 ? normalizeCurrencyAmount(dropBaseAmt * dropTaxPct / 100, showDecimals) : 0;
      const dropTotalAmt = normalizeCurrencyAmount(dropBaseAmt + dropTaxAmt, showDecimals);
      return {
        currencyCode, showDecimals,
        baseAmount: dropBaseAmt, discountPercentage: 0, discountAmount: 0,
        taxPercentage: dropTaxPct, taxAmount: dropTaxAmt, totalAmount: dropTotalAmt,
        couponSuppressedByPayment: couponIsActive, dropSavingsAmt: 0, discountWinnerMessage: '',
        baseText: formatPaymentDifferenceMoney(currencyCode, dropBaseAmt, showDecimals),
        discountText: formatPaymentDifferenceMoney(currencyCode, 0, showDecimals),
        taxText: formatPaymentDifferenceMoney(currencyCode, dropTaxAmt, showDecimals),
        totalText: formatPaymentDifferenceMoney(currencyCode, dropTotalAmt, true),
      };
    }

    // Cuando el cupón está activo, selectedTotalValue ya es el precio post-cupón.
    // Se recupera el precio del paquete ANTES del cupón para hacer comparaciones justas.
    const packPriceNoCoupon = couponIsActive && Number(appliedCouponSummary.originalAmount || 0) > 0
      ? normalizeCurrencyAmount(Number(appliedCouponSummary.originalAmount), showDecimals)
      : baseAmount;

    // Precio antes del drop (precio de lista real), calculado desde el precio sin cupón
    const priceBeforeDrop = dropPercent > 0 && packPriceNoCoupon > 0
      ? normalizeCurrencyAmount(packPriceNoCoupon / (1 - dropPercent / 100), showDecimals)
      : packPriceNoCoupon;
    const dropSavingsAmt = normalizeCurrencyAmount(Math.max(0, priceBeforeDrop - packPriceNoCoupon), showDecimals);

    // Usar el monto de descuento guardado del backend — calculado contra la base correcta
    const couponDiscountAmtFromOriginal = couponIsActive
      ? normalizeCurrencyAmount(Number(appliedCouponSummary.discountAmount || 0), showDecimals)
      : 0;

    // Mejor descuento pre-existente (el mayor entre drop y cupón)
    const bestExistingDiscount = Math.max(dropSavingsAmt, couponDiscountAmtFromOriginal);
    if (discountPercentage > 0 && priceBeforeDrop > 0) {
      const paymentDiscountAmt = normalizeCurrencyAmount((priceBeforeDrop * discountPercentage) / 100, showDecimals);
      if (paymentDiscountAmt > bestExistingDiscount) {
        // Método de pago gana: usar precio de lista como base para calcular descuento y total
        baseAmount = priceBeforeDrop;
        couponSuppressedByPayment = couponIsActive;
        dropSuppressedByPayment = dropSavingsAmt > 0;
      } else {
        discountPercentage = 0; // el descuento existente gana
      }
    }

    const discountAmount = discountPercentage > 0
      ? normalizeCurrencyAmount((baseAmount * discountPercentage) / 100, showDecimals)
      : 0;
    const subtotalAmount = normalizeCurrencyAmount(Math.max(0, baseAmount - discountAmount), showDecimals);
    const taxPercentage = resolvePaymentModeTaxPercentage(resolvedMode, method);
    const taxAmount = taxPercentage > 0
      ? normalizeCurrencyAmount((subtotalAmount * taxPercentage) / 100, showDecimals)
      : 0;
    const totalAmount = Number((subtotalAmount + taxAmount).toFixed(2));

    // Mensaje informativo sobre el descuento ganador
    let discountWinnerMessage = '';
    if (discountPercentage > 0 && (couponSuppressedByPayment || dropSuppressedByPayment)) {
      const methodLabel = resolvedMode === 'binance' ? 'Binance Pay' : (resolvedMode === 'binance_pagonorte' ? 'Binance' : 'el método de pago');
      if (couponSuppressedByPayment && dropSuppressedByPayment) {
        discountWinnerMessage = `El descuento de ${methodLabel} es mayor. Se aplicó el descuento de mayor valor.`;
      } else if (couponSuppressedByPayment) {
        discountWinnerMessage = `El descuento de ${methodLabel} supera tu cupón. Se aplicó el descuento de mayor valor.`;
      } else {
        discountWinnerMessage = `El descuento de ${methodLabel} supera el descuento del producto. Se aplicó el descuento de mayor valor.`;
      }
    } else if (discountPercentage === 0 && bestExistingDiscount > 0 && (couponIsActive || dropSavingsAmt > 0)) {
      if (couponIsActive && dropSavingsAmt > 0) {
        const label = couponDiscountAmtFromOriginal >= dropSavingsAmt ? 'tu cupón' : 'el descuento del producto';
        discountWinnerMessage = `Ya tienes el descuento de mayor valor aplicado (${label}).`;
      } else if (couponIsActive) {
        discountWinnerMessage = 'Tu cupón ya es el mejor descuento disponible.';
      } else if (dropSavingsAmt > 0) {
        discountWinnerMessage = 'El descuento del producto ya es el mejor precio disponible.';
      }
    }

    return {
      currencyCode,
      showDecimals,
      baseAmount,
      discountPercentage,
      discountAmount,
      taxPercentage,
      taxAmount,
      totalAmount,
      couponSuppressedByPayment,
      dropSavingsAmt,
      discountWinnerMessage,
      baseText: formatPaymentDifferenceMoney(currencyCode, baseAmount, showDecimals),
      discountText: formatPaymentDifferenceMoney(currencyCode, discountAmount, showDecimals),
      taxText: formatPaymentDifferenceMoney(currencyCode, taxAmount, showDecimals),
      totalText: formatPaymentDifferenceMoney(currencyCode, totalAmount, true),
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
    } else if (mode === 'binance_pagonorte') {
      preferredCheckoutPaymentMode = 'binance_pagonorte';
      preferredCheckoutMethodId = '';
    } else if (mode === 'binance') {
      preferredCheckoutPaymentMode = 'binance';
      preferredCheckoutMethodId = '';
    } else if (mode === 'paypal') {
      preferredCheckoutPaymentMode = 'paypal';
      preferredCheckoutMethodId = '';
    } else if (mode === 'money' && normalizedMethodId !== '') {
      preferredCheckoutPaymentMode = 'money';
      preferredCheckoutMethodId = normalizedMethodId;
    } else {
      preferredCheckoutPaymentMode = '';
      preferredCheckoutMethodId = '';
    }
    updatePackPrices();
    if (activePack) {
      updateSelectedPriceDisplay(activePack);
      renderPublicOrderSummary(activePack);
      renderPublicPaymentMethodCatalog(activePack);
    }
  }

  function autoSelectDefaultPaymentMethod() {
    if (preferredCheckoutPaymentMode !== '') {
      return;
    }
    const gameDefaultNormalized = normalizeCurrencyAlias(monedaActualClave || '');
    const VES_NORMALIZED = 'VES';
    const searchOrder = [];
    if (gameDefaultNormalized) {
      searchOrder.push(gameDefaultNormalized);
    }
    if (!searchOrder.includes(VES_NORMALIZED)) {
      searchOrder.push(VES_NORMALIZED);
    }
    let foundMethod = null;
    outer:
    for (const targetCode of searchOrder) {
      for (const currencyKey of Object.keys(paymentMethodsByCurrency)) {
        if (normalizeCurrencyAlias(currencyKey) === targetCode) {
          const methods = paymentMethodsByCurrency[currencyKey] || [];
          if (methods.length > 0) {
            foundMethod = methods[0];
            break outer;
          }
        }
      }
    }
    if (!foundMethod) {
      for (const currencyKey of Object.keys(paymentMethodsByCurrency)) {
        const methods = paymentMethodsByCurrency[currencyKey] || [];
        if (methods.length > 0) {
          foundMethod = methods[0];
          break;
        }
      }
    }
    if (!foundMethod) {
      return;
    }
    storePreferredCheckoutPayment('money', String(foundMethod.id));
    // storePreferredCheckoutPayment only re-renders when activePack is set.
    // Switch the visible currency to match the method and force a summary render.
    const switched = syncVisibleCurrencyWithPreferredPayment(null, { resetCoupon: false });
    if (!switched) {
      updateResumenCompra(null);
    }
  }

  function resolvePreferredCheckoutSelection(pack) {
    const hasPack = Boolean(pack);
    const methodsCurrencyCode = String((pack && (pack.baseCurrency || pack.moneda)) || '').trim();
    const methods = getPaymentMethodsForCurrency(methodsCurrencyCode);
    const hasPointsRule = Boolean(hasPack && pack.redeemActive && getPackRequiredPoints(pack) > 0);
    const requiredPoints = hasPointsRule ? getPackRequiredPoints(pack) : 0;
    const canUsePointsNow = Boolean(hasPack && canRedeemPackWithPoints(pack));
    const showPointsOption = Boolean(winPointsState.gameHasAnyRule);
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

    // If this specific pack has no redemption rule, points mode is not valid for it
    if (nextMode === 'points' && !hasPointsRule) {
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

  // Badge en modo carrito: calcula el total correcto separando drop vs no-drop
  function resolveCartModeBadgeText(mode, method, refPack) {
    if (!cartItems || cartItems.length === 0) return '';
    if (mode === 'points') {
      const totalPts = cartItems.reduce((s, ci) => s + getPackRequiredPoints(ci.pack, ci.quantity), 0);
      return totalPts > 0 ? formatWinPointsAmount(totalPts) : '';
    }
    let targetCode = '';
    let targetEntry = null;
    if (mode === 'money' && method) {
      targetCode = String(method.moneda_clave || '').trim().toUpperCase();
      targetEntry = findCurrencyEntryByCode(targetCode);
    } else if (mode === 'binance_pagonorte') {
      targetEntry = resolveBinancePagonorteCurrencyEntry();
      targetCode = targetEntry ? String(targetEntry.clave || 'USDT').toUpperCase() : 'USDT';
    } else if (mode === 'binance') {
      targetEntry = resolvePreferredBinanceCurrencyEntry();
      targetCode = targetEntry ? String(targetEntry.clave || '').toUpperCase() : '';
    } else if (mode === 'paypal') {
      targetEntry = resolvePreferredPayPalCurrencyEntry();
      targetCode = targetEntry ? String(targetEntry.clave || '').toUpperCase() : '';
    }
    if (!targetCode) return '';
    const sourceCode = String((refPack && refPack.moneda) || monedaActualClave || '').trim().toUpperCase();
    const tgtShow = targetEntry ? Boolean(targetEntry.mostrar_decimales) : true;
    // Split cart
    let nonDropSub = 0;
    let dropSub = 0;
    cartItems.forEach(ci => {
      const base = normalizeCurrencyAmount(parseFloat(ci.pack.priceValue || 0), ci.pack.showDecimals);
      const sub = normalizeCurrencyAmount(base * ci.quantity, ci.pack.showDecimals);
      if (Number(ci.pack.dropPercent || 0) > 0) dropSub += sub;
      else nonDropSub += sub;
    });
    // Winner discount for non-drop items: max(method%, coupon%)
    const methodPct = resolvePaymentModeDiscountPercentage(mode, method);
    const couponIsActive = couponApplied && Number(appliedCouponSummary.discountAmount || 0) > 0;
    let couponPct = 0;
    if (couponIsActive && nonDropSub > 0) {
      couponPct = (Number(appliedCouponSummary.discountAmount || 0) / nonDropSub) * 100;
    }
    const discountPct = Math.max(methodPct, couponPct);
    const discAmt = normalizeCurrencyAmount(nonDropSub * discountPct / 100, tgtShow);
    const nonDropAfter = normalizeCurrencyAmount(nonDropSub - discAmt, tgtShow);
    // Convert each part to target currency and sum
    const nonDropConverted = normalizeCurrencyAmount(convertCurrencyAmountBetweenCodes(nonDropAfter, sourceCode, targetCode), tgtShow);
    const dropConverted = normalizeCurrencyAmount(convertCurrencyAmountBetweenCodes(dropSub, sourceCode, targetCode), tgtShow);
    let total = normalizeCurrencyAmount(nonDropConverted + dropConverted, tgtShow);
    const taxPct = resolvePaymentModeTaxPercentage(mode, method);
    if (taxPct > 0) total = normalizeCurrencyAmount(total * (1 + taxPct / 100), tgtShow);
    return total > 0 ? formatPaymentDifferenceMoney(targetCode, total, tgtShow) : '';
  }

  function resolveMethodCardBadgeText(mode, method, pack) {
    if (!pack) return '';

    // Cart mode: use split calculation (drop at full price, non-drop with winner discount)
    if (typeof cartMode !== 'undefined' && cartMode && typeof cartItems !== 'undefined' && cartItems.length > 0) {
      return resolveCartModeBadgeText(mode, method, pack);
    }

    const quantity = getOrderQuantity();

    if (mode === 'points') {
      const pts = getPackRequiredPoints(pack, quantity);
      return pts > 0 ? formatWinPointsAmount(pts) : '';
    }

    let targetCode = '';
    let targetEntry = null;
    if (mode === 'money' && method) {
      targetCode = String(method.moneda_clave || '').trim().toUpperCase();
      targetEntry = findCurrencyEntryByCode(targetCode);
    } else if (mode === 'binance_pagonorte') {
      targetEntry = resolveBinancePagonorteCurrencyEntry();
      targetCode = targetEntry ? String(targetEntry.clave || 'USDT').toUpperCase() : 'USDT';
    } else if (mode === 'binance') {
      targetEntry = resolvePreferredBinanceCurrencyEntry();
      targetCode = targetEntry ? String(targetEntry.clave || '').toUpperCase() : '';
    } else if (mode === 'paypal') {
      targetEntry = resolvePreferredPayPalCurrencyEntry();
      targetCode = targetEntry ? String(targetEntry.clave || '').toUpperCase() : '';
    }
    if (!targetCode) return '';

    const showDecimals = targetEntry ? Boolean(targetEntry.mostrar_decimales) : true;
    const sourceCode = String(pack.moneda || monedaActualClave || '').trim().toUpperCase();
    const sourceBase = selectedTotalValue > 0
      ? selectedTotalValue
      : getPackTotalPrice(pack, quantity);

    let baseInTarget = normalizeCurrencyAmount(
      convertCurrencyAmountBetweenCodes(sourceBase, sourceCode, targetCode),
      showDecimals
    );

    // Single-pack drop: precio fijo, sin descuentos adicionales
    if (Number(pack.dropPercent || 0) > 0) {
      const taxPctDrop = resolvePaymentModeTaxPercentage(mode, method);
      if (taxPctDrop > 0) baseInTarget = normalizeCurrencyAmount(baseInTarget * (1 + taxPctDrop / 100), showDecimals);
      return formatPaymentDifferenceMoney(targetCode, baseInTarget, showDecimals);
    }

    // Apply winner discount: max(method%, coupon%)
    const methodDiscountPct = resolvePaymentModeDiscountPercentage(mode, method);
    const couponIsActiveBadge = couponApplied && Number(appliedCouponSummary.discountAmount || 0) > 0;
    let couponPctBadge = 0;
    if (couponIsActiveBadge && sourceBase > 0) {
      const originalBase = Number(appliedCouponSummary.originalAmount || 0) > 0
        ? Number(appliedCouponSummary.originalAmount)
        : sourceBase / (1 - (Number(appliedCouponSummary.discountAmount || 0) / (sourceBase + Number(appliedCouponSummary.discountAmount || 0))));
      couponPctBadge = originalBase > 0 ? (Number(appliedCouponSummary.discountAmount || 0) / originalBase) * 100 : 0;
    }
    const winnerPct = Math.max(methodDiscountPct, couponPctBadge);
    if (winnerPct > 0) {
      const originalInTarget = couponIsActiveBadge && Number(appliedCouponSummary.originalAmount || 0) > 0
        ? normalizeCurrencyAmount(convertCurrencyAmountBetweenCodes(Number(appliedCouponSummary.originalAmount), sourceCode, targetCode), showDecimals)
        : baseInTarget;
      baseInTarget = normalizeCurrencyAmount(Math.max(0, originalInTarget * (1 - winnerPct / 100)), showDecimals);
    }

    // Apply method tax
    const methodTaxPct = resolvePaymentModeTaxPercentage(mode, method);
    if (methodTaxPct > 0) {
      const tax = normalizeCurrencyAmount(baseInTarget * methodTaxPct / 100, showDecimals);
      baseInTarget = normalizeCurrencyAmount(baseInTarget + tax, showDecimals);
    }

    return formatPaymentDifferenceMoney(targetCode, baseInTarget, showDecimals);
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
      const badgeText = hasPack ? resolveMethodCardBadgeText('money', method, pack) : '';
      const priceBadge = badgeText ? `<span class="payment-method-public-price-badge">${escapePaymentHtml(badgeText)}</span>` : '';
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="money" data-method-id="${escapePaymentHtml(methodId)}">${imageMarkup}</button>
          ${cornerMarkup}${priceBadge}
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
      const bpBadgeText = hasPack ? resolveMethodCardBadgeText('binance_pagonorte', null, pack) : '';
      const bpPriceBadge = bpBadgeText ? `<span class="payment-method-public-price-badge">${escapePaymentHtml(bpBadgeText)}</span>` : '';
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="binance_pagonorte">
            ${binancePagonorteMarkup}
          </button>
          ${binancePagonorteCornerMarkup}${bpPriceBadge}
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
      const bnBadgeText = hasPack ? resolveMethodCardBadgeText('binance', null, pack) : '';
      const bnPriceBadge = bnBadgeText ? `<span class="payment-method-public-price-badge">${escapePaymentHtml(bnBadgeText)}</span>` : '';
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="binance">
            ${binanceMarkup}
          </button>
          ${binanceCornerMarkup}${bnPriceBadge}
        </div>`);
    }

    if (selection.canUsePayPal) {
      const paypalMeta = 'Checkout oficial de PayPal con confirmación automática';
      const isSelected = selection.mode === 'paypal';
      const paypalCornerMarkup = paymentMethodPublicCornerMarkup(String(paypalPayCornerImageUrl || '').trim());
      const paypalMarkup = paymentMethodPublicCardContent(String(paypalPayImageUrl || '').trim(), paypalPayButtonLabel, paypalMeta);
      const ppBadgeText = hasPack ? resolveMethodCardBadgeText('paypal', null, pack) : '';
      const ppPriceBadge = ppBadgeText ? `<span class="payment-method-public-price-badge">${escapePaymentHtml(ppBadgeText)}</span>` : '';
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="paypal">
            ${paypalMarkup}
          </button>
          ${paypalCornerMarkup}${ppPriceBadge}
        </div>`);
    }

    if (selection.showPointsOption) {
      const pointsDisabled = false; // always full color; buy button handles the blocked state
      let pointsMeta = '';
      if (!hasPack) {
        pointsMeta = `Saldo actual: ${formatWinPointsAmount(winPointsState.balance || 0)}`;
      } else {
        const pointsNeedText = `Necesitas ${formatWinPointsAmount(selection.requiredPoints || 0)}`;
        pointsMeta = pointsNeedText;
        if (!winPointsState.loggedIn) {
          pointsMeta = `${pointsNeedText} · Inicia sesión para usarlo`;
        } else if (!selection.hasPointsRule) {
          pointsMeta = 'Este paquete no admite canje';
        } else if (winPointsState.monthlyMinimumMet === false) {
          const required = Number(winPointsState.monthlyMinimumRequired || 5).toFixed(2);
          const spent = Number(winPointsState.monthlyMinimumSpent || 0).toFixed(2);
          pointsMeta = `Restringido · Recarga mínima $${required}/mes (llevas $${spent})`;
        } else if (selection.canUsePointsNow) {
          pointsMeta = `${pointsNeedText} · Saldo actual ${formatWinPointsAmount(winPointsState.balance || 0)}`;
        } else {
          pointsMeta = `${pointsNeedText} · Saldo actual ${formatWinPointsAmount(winPointsState.balance || 0)}`;
        }
      }
      const pointsImageUrl = String(winPointsState.paymentImageUrl || '').trim();
      const pointsCornerMarkup = paymentMethodPublicCornerMarkup(String(winPointsState.paymentCornerImageUrl || '').trim());
      const pointsMarkup = paymentMethodPublicCardContent(pointsImageUrl, winPointsState.name || 'Win Points', pointsMeta);
      const isSelected = selection.mode === 'points';
      // Disable the button entirely when this specific pack has no redemption rule (not just grayed)
      const pointsButtonDisabled = !selection.hasPointsRule;
      const ptsBadgeText = hasPack ? resolveMethodCardBadgeText('points', null, pack) : '';
      const ptsPriceBadge = ptsBadgeText ? `<span class="payment-method-public-price-badge">${escapePaymentHtml(ptsBadgeText)}</span>` : '';
      cards.push(`
        <div class="payment-method-public-card${isSelected ? ' is-selected' : ''}${pointsDisabled ? ' is-disabled' : ''}">
          <button type="button" class="payment-method-public-button" data-payment-option="points"${pointsButtonDisabled ? ' disabled' : ''}>${pointsMarkup}</button>
          ${pointsCornerMarkup}${ptsPriceBadge}
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

    if (selection.mode === 'points' && selection.canUsePointsNow) {
      paymentMethodCatalogCopy.textContent = hasPack
        ? `Seleccionado: ${winPointsState.name || 'Win Points'}. Al confirmar, se canjeará tu saldo de puntos para procesar la recarga.`
        : `Seleccionado: ${winPointsState.name || 'Win Points'}.`;
      return;
    }

    if (selection.showPointsOption && !selection.canUsePointsNow) {
      if (!hasPack) {
        paymentMethodCatalogCopy.textContent = `${winPointsState.name || 'Win Points'} seleccionado. Elige un paquete para ver cuántos puntos necesitas.`;
      } else if (!winPointsState.loggedIn) {
        paymentMethodCatalogCopy.textContent = `${winPointsState.name || 'Win Points'} está activo para este paquete. Inicia sesión para usarlo como método de pago.`;
      } else if (winPointsState.monthlyMinimumMet === false) {
        const required = Number(winPointsState.monthlyMinimumRequired || 5).toFixed(2);
        const spent = Number(winPointsState.monthlyMinimumSpent || 0).toFixed(2);
        paymentMethodCatalogCopy.textContent = `${winPointsState.name || 'Win Points'} no disponible este mes. Necesitas haber recargado $${required} en los últimos 30 días (llevas $${spent}).`;
      } else {
        paymentMethodCatalogCopy.textContent = `${winPointsState.name || 'Win Points'} está activo para este paquete, pero necesitas ${formatWinPointsAmount(selection.requiredPoints || 0)} para usarlo.`;
      }
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
    const isAdvancedFormActive = !!(selectedMethod && selectedMethod.formulario_verificacion);
    if (paymentReferenceGroup) {
      paymentReferenceGroup.classList.toggle('d-none', usingPoints || usingBinance || usingPayPal || isAdvancedFormActive);
    }
    if (paymentPhoneGroup) {
      paymentPhoneGroup.classList.toggle('d-none', usingPoints || usingBinance || usingPayPal || isAdvancedFormActive);
    }
    if (paymentMoneyPanel) {
      paymentMoneyPanel.classList.toggle('is-active', !usingPoints && (canUseMoney || canUseBinancePagonorte || canUseBinance || canUsePayPal));
    }
    if (paymentSubmitButton) {
      paymentSubmitButton.textContent = usingPoints
        ? `Canjear ${formatWinPointsAmount(activePaymentOrder.pointsRequired || 0)}`
        : (usingBinance ? 'Continuar con Binance Pay' : (usingPayPal ? 'Continuar con PayPal' : buildConfirmButtonLabel((activePaymentOrder && activePaymentOrder.confirmedTotalText) || '')));
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
    const monthlyMinimumMet = winPointsState.monthlyMinimumMet !== false;
    const canUsePoints = hasRule && currentBalance >= requiredPoints && monthlyMinimumMet;
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
    } else if (showRewardsState && hasRule && !monthlyMinimumMet) {
      const spent = Number(winPointsState.monthlyMinimumSpent || 0).toFixed(2);
      const required = Number(winPointsState.monthlyMinimumRequired || 5).toFixed(2);
      activePaymentOrder.pointsMessage = `🔒 Beneficio restringido. Para canjear ${winPointsState.name || 'puntos'} en recargas gratis necesitas haber recargado un mínimo de $${required} en los últimos 30 días. Llevas $${spent} recargados.`;
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
    control.required = !window.__gameNoPlayerIdRequired;

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
    playerPrimaryInput.required = !window.__gameNoPlayerIdRequired;
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
    let requiredFields = packRequiredFields.length ? packRequiredFields : getPlayerVerificationDefaultFields();
    if (!pack && requiredFields.length === 0 && packCards2.length > 0) {
      const firstCardFields = parseRequiredFields(packCards2[0].dataset.requiredFields);
      if (firstCardFields.length > 0) requiredFields = firstCardFields;
    }
    const shouldShowPrimaryField = !isAccountSalePack(pack) && (!pack || pack.provider !== 'giftven' || requiredFields.length > 0);
    const primaryConfig = requiredFields[0] || defaultPrimaryField;
    setAccountSaleNote(pack);

    if (playerPrimaryField && playerPrimaryInput && playerPrimaryLabel) {
      syncPrimaryControl(primaryConfig);
      playerPrimaryField.classList.toggle('d-none', !shouldShowPrimaryField);
      playerPrimaryLabel.textContent = primaryConfig.label || defaultPrimaryField.label;
      playerPrimaryInput.dataset.apiField = primaryConfig.name || defaultPrimaryField.name;
      playerPrimaryInput.required = shouldShowPrimaryField && !window.__gameNoPlayerIdRequired;

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
      const isZoneField = ['input2', 'zone_id', 'zoneid', 'zone', 'server_id', 'serverid', 'server'].includes(fieldConfig.name || '');
      input.value = existingValues[fieldConfig.name || ''] || (isZoneField && rememberLastPurchaseIdentifierEnabled ? defaultPlayerZoneId : '') || '';

      wrapper.appendChild(label);
      wrapper.appendChild(input);
      extraPlayerFields.appendChild(wrapper);
    });

    syncPlayerVerificationUi();
  }

  const ZONE_ID_ALIASES = ['input2', 'zone_id', 'zoneid', 'zone', 'server_id', 'serverid', 'server'];

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
        if (fieldValue !== '') {
          if (fieldName !== '') {
            fields[fieldName] = fieldValue;
          }
          // If this is a zone-ID-like field, mirror its value under all aliases so
          // a field name change between renders (e.g. zone_id → input2) still finds it.
          if (ZONE_ID_ALIASES.includes(fieldName)) {
            ZONE_ID_ALIASES.forEach((alias) => { fields[alias] = fieldValue; });
          }
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
    playerVerificationFeedback.removeAttribute('style');
    playerVerificationFeedback.textContent = '';
  }

  // Arma el `style` inline de una insignia de éxito/fallo del verificador a
  // partir de la config guardada en /admin/diseno-pasos (ver
  // paso_estilo_js_config() en el PHP de esta página). Si esa zona está en
  // modo "original" no se llama a esto — se sigue usando el alert simple de
  // Bootstrap de siempre.
  function pasoEstiloBuildCss(cfg) {
    var css = 'background:' + cfg.fondo + ';color:' + cfg.colorTexto + ';font-size:' + cfg.fuenteTamano + ';';
    if (cfg.fuenteFamilia) css += 'font-family:' + cfg.fuenteFamilia + ';';
    if (cfg.bordeActivo) {
      var brillo = cfg.bordeBrillo || 14;
      css += 'border:' + (cfg.bordeGrosor || 1) + 'px solid ' + cfg.colorBorde
        + ';box-shadow:0 0 ' + brillo + 'px ' + cfg.colorBorde + ', inset 0 0 ' + Math.round(brillo / 2) + 'px ' + cfg.colorBorde + ';';
    } else {
      css += 'border:1px solid transparent;';
    }
    return css;
  }

  // Ícono de la columna 1 ya resuelto a HTML — un emoji plano o un <img> si
  // el admin subió una imagen (ver paso_estilo_js_config() en el PHP).
  function pasoEstiloIconoHtml(icono) {
    if (!icono || icono.tipo === 'ninguno' || !icono.valor) return '';
    if (icono.tipo === 'imagen') {
      return '<img src="' + escapePaymentHtml(icono.valor) + '" alt="" class="paso-verif-icon-img">';
    }
    return escapePaymentHtml(icono.valor);
  }

  function setPlayerVerificationFeedback(type, message) {
    if (!playerVerificationFeedback) {
      return;
    }

    if (!message) {
      clearPlayerVerificationFeedback();
      return;
    }

    // Éxito y fallo tienen su propio diseño configurable en 3 columnas
    // (ícono | mensaje editable | insignia fija VERIFICADO/INVALIDO con su
    // propio color) cuando esa zona está en modo personalizado — si sigue
    // en modo original, se comporta EXACTO igual que antes (alerta simple
    // de Bootstrap con solo el mensaje).
    if (type === 'success' && pasoEstiloVerificacion.exito.personalizado) {
      const cfg = pasoEstiloVerificacion.exito;
      const nombre = (playerVerificationState.playerName || '').trim();
      playerVerificationFeedback.className = 'paso-verif-banner mt-2';
      playerVerificationFeedback.setAttribute('style', pasoEstiloBuildCss(cfg));
      playerVerificationFeedback.innerHTML =
        '<span class="paso-verif-icon" aria-hidden="true">' + pasoEstiloIconoHtml(cfg.icono) + '</span>'
        + '<span class="paso-verif-text"><strong>' + escapePaymentHtml(nombre || message) + '</strong></span>'
        + '<span class="paso-verif-badge" style="background:' + cfg.badgeColorFondo + ';color:' + cfg.badgeColorTexto + ';">' + escapePaymentHtml(cfg.badgeTexto) + '</span>';
      return;
    }

    if (type === 'danger' && pasoEstiloVerificacion.fallo.personalizado) {
      const cfg = pasoEstiloVerificacion.fallo;
      // Columna 2: el mensaje real de la API (por defecto) o el texto fijo
      // que haya escrito el admin — el formato (color/fuente) es el mismo
      // en los 2 casos, solo cambia el contenido.
      const mensajeColumna2 = (cfg.mensajeModo === 'personalizado' && cfg.mensajePersonalizado)
        ? cfg.mensajePersonalizado
        : message;
      playerVerificationFeedback.className = 'paso-verif-banner mt-2';
      playerVerificationFeedback.setAttribute('style', pasoEstiloBuildCss(cfg));
      playerVerificationFeedback.innerHTML =
        '<span class="paso-verif-icon" aria-hidden="true">' + pasoEstiloIconoHtml(cfg.icono) + '</span>'
        + '<span class="paso-verif-text">' + escapePaymentHtml(mensajeColumna2) + '</span>'
        + '<span class="paso-verif-badge" style="background:' + cfg.badgeColorFondo + ';color:' + cfg.badgeColorTexto + ';">' + escapePaymentHtml(cfg.badgeTexto) + '</span>';
      return;
    }

    const alertType = type === 'success' ? 'success' : (type === 'info' ? 'info' : 'danger');
    playerVerificationFeedback.removeAttribute('style');
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
    clearBsPassStock();
    clearLevelPassCheck();
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
    clearBsPassStock();
    clearLevelPassCheck();

    const baseMessage = String(message || 'No se pudo verificar el jugador en este momento.').trim();
    setPlayerVerificationFeedback('info', `${baseMessage} Puedes continuar con la recarga normal.`);
  }

  function shouldAllowCheckoutOnVerificationFailure(status, message, httpStatus) {
    const normalizedStatus = String(status || '').trim().toLowerCase();
    const numericHttpStatus = Number(httpStatus || 0);

    // El backend YA clasifica explícitamente cuando el proveedor respondió
    // con claridad que el ID no existe/es inválido ('not_found'/'invalid').
    // Eso NUNCA debe tratarse como caída temporal del servicio, sin importar
    // el texto del mensaje: algunos proveedores redactan su propio mensaje
    // de "no encontrado" con palabras como "no player data found for uid",
    // que antes coincidían por accidente con la lista de abajo y dejaban
    // pasar la compra con un ID inválido/inexistente EN CUALQUIER JUEGO —
    // bug crítico reportado por el cliente. El corte explícito aquí evita
    // que un mensaje de "no encontrado" se confunda con una caída real.
    if (normalizedStatus === 'not_found' || normalizedStatus === 'invalid') {
      return false;
    }

    if (normalizedStatus === 'unavailable' || normalizedStatus === 'unsupported' || numericHttpStatus >= 500) {
      return true;
    }

    const normalizedMessage = String(message || '').trim().toLowerCase();
    const temporaryFailureSnippets = [
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
    if (!playerVerificationConfig) {
      return false;
    }
    // Si no hay paquete seleccionado, mostrar igual (el jugador llena su info primero)
    if (!activePack) {
      return true;
    }
    if (isAccountSalePack(activePack)) {
      return false;
    }
    // La verificación de jugador solo aplica a paquetes de TiendaGiftVen, no Discord
    if (String(activePack.provider || '').trim() === 'discord') {
      return false;
    }
    return true;
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
    // El texto vive en un <span> propio (verify-player-button-text) y no en
    // el botón directamente, para no borrar el ícono al lado cada vez que
    // se actualiza — ver pasoEstiloVerificacion.boton en el PHP de arriba.
    const verifyPlayerButtonText = document.getElementById('verify-player-button-text');
    if (verifyPlayerButtonText) {
      verifyPlayerButtonText.textContent = playerVerificationState.pending
        ? 'Verificando...'
        : (pasoEstiloVerificacion.boton.texto || playerVerificationConfig.buttonLabel || 'Verificar nombre del jugador');
    }
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

    // Consulta de pases EN PARALELO con la verificación del nombre: ambas
    // peticiones viajan a la vez desde que el cliente escribe el ID, en vez
    // de esperar a que la verificación termine. Si el ID resulta inválido,
    // el reset de la verificación descarta el resultado en vuelo.
    runBsPassStockCheck(payload.userIdentifier);

    try {
      const requestBody = new URLSearchParams();
      requestBody.set('game_id', "<?= (string) ($game['id'] ?? '') ?>");
      requestBody.set('package_id', String((activePack && activePack.id) || ''));
      requestBody.set('user_identifier', payload.userIdentifier);
      requestBody.set('player_fields_json', JSON.stringify(payload.playerFields));

      const response = await fetch(buildAppUrl('/api/verify_player.php?game_id=<?= (int) ($game['id'] ?? 0) ?>'), {
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
        // Pase de Nivel SOLO se consulta aquí, tras confirmar el nombre del
        // jugador (nunca en paralelo, nunca si el ID no arrojó nombre real),
        // y únicamente si el cliente está dentro de la pestaña de "Pases de
        // nivel" — para no saturar la IP del validador con peticiones de
        // IDs falsos ni con consultas de clientes comprando otra cosa.
        if (activeTabHasLevelPassPackages()) {
          runLevelPassCheck(payload.userIdentifier);
        }
        setPlayerVerificationFeedback('success', String(data.message || 'Jugador encontrado.'));
        window.setTimeout(() => {
          const packSection = document.getElementById('game-packages-section');
          if (packSection) scrollViewportToElement(packSection, { duration: 560, offset: 18 });
        }, 350);
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

  // ── Stock de pases BLOOD STRIKE PASS ────────────────────────────────────
  // Se dispara en paralelo con la verificación del nombre (desde que el
  // cliente escribe el ID) y consulta qué pases (categoría BLOOD STRIKE PASS
  // de giftven) puede comprar ese jugador. Los ya adquiridos se ocultan;
  // cualquier falla del validador = no ocultar nada (fail-open).
  let bsPassStockSeq = 0;
  let bsPassBlockedIds = new Set();
  let bsPassStockNote = null;
  let bsPassCheckedPlayerId = '';
  let bsPassCheckedAt = 0;
  let bsPassPendingPlayerId = '';

  function getBsPassStockNote() {
    if (bsPassStockNote || !playerVerificationFeedback) {
      return bsPassStockNote;
    }
    bsPassStockNote = document.createElement('div');
    bsPassStockNote.id = 'bs-pass-stock-note';
    bsPassStockNote.className = 'd-none mt-2';
    playerVerificationFeedback.insertAdjacentElement('afterend', bsPassStockNote);
    return bsPassStockNote;
  }

  function setBsPassStockNote(kind, message) {
    if (!message && !bsPassStockNote) {
      return;
    }
    const note = getBsPassStockNote();
    if (!note) {
      return;
    }
    if (!message) {
      note.className = 'd-none mt-2';
      note.textContent = '';
      return;
    }
    note.className = `alert alert-${kind} py-2 px-3 mt-2 mb-0 small fw-semibold`;
    note.textContent = message;
  }

  // Visibilidad de cada columna del grid: solo depende del tab de categoría
  // activo. El bloqueo de stock BS Pass / Pase de Nivel YA NO oculta la
  // tarjeta — se mantiene visible pero oscurecida/deshabilitada (ver
  // blockPackCardForStock / blockPackCardForLevelPass), a pedido del
  // cliente: no quiere que las tarjetas desaparezcan de la grilla.
  function updateColumnVisibility(column) {
    if (!column) return;
    const tabHidden = column.dataset.tabHidden === '1';
    column.classList.toggle('d-none', tabHidden);
  }

  // El validador indica compras previas del jugador: los pases que ya
  // adquirió en el ciclo/evento actual se mantienen visibles pero
  // oscurecidos/deshabilitados con una etiqueta ("Ya tienes este paquete"),
  // y reaparecen normales cuando el validador vuelva a listarlos como
  // disponibles.
  function blockPackCardForStock(card, label) {
    if (!card) {
      return;
    }
    card.classList.add('bs-pass-blocked');
    card.setAttribute('aria-disabled', 'true');
    card.setAttribute('data-lock-label', label || 'Ya tienes este paquete');
  }

  function unblockPackCardForStock(card) {
    if (!card) {
      return;
    }
    card.classList.remove('bs-pass-blocked');
    card.removeAttribute('aria-disabled');
    card.removeAttribute('data-lock-label');
  }

  function deselectBlockedActivePack() {
    packCards2.forEach((item) => {
      item.classList.remove('neon-selected');
      item.setAttribute('aria-pressed', 'false');
    });
    activePack = null;
    updateResumenCompra(null);
    updateButtonState();
  }

  function applyBsPassStock(blockedIds) {
    bsPassBlockedIds = new Set((blockedIds || []).map(String));
    bsPassStockConfig.packageIds.forEach((packageId) => {
      const card = findPackCardById(packageId);
      if (!card) {
        return;
      }
      if (bsPassBlockedIds.has(String(packageId))) {
        blockPackCardForStock(card);
      } else {
        unblockPackCardForStock(card);
      }
    });

    let deselected = false;
    if (activePack && bsPassBlockedIds.has(String(activePack.id))) {
      deselectBlockedActivePack();
      deselected = true;
    }

    document.dispatchEvent(new CustomEvent('bs-pass-stock-blocked', {
      detail: { blockedIds: Array.from(bsPassBlockedIds) },
    }));

    return deselected;
  }

  function clearBsPassStock() {
    bsPassStockSeq += 1;
    bsPassCheckedPlayerId = '';
    bsPassCheckedAt = 0;
    bsPassPendingPlayerId = '';
    if (!bsPassStockConfig || !bsPassStockConfig.enabled) {
      return;
    }
    setBsPassStockNote('', '');
    if (bsPassBlockedIds.size === 0) {
      return;
    }
    bsPassBlockedIds = new Set();
    bsPassStockConfig.packageIds.forEach((packageId) => {
      unblockPackCardForStock(findPackCardById(packageId));
    });
  }

  async function runBsPassStockCheck(playerIdentifier) {
    if (!bsPassStockConfig || !bsPassStockConfig.enabled) {
      return;
    }
    const playerId = String(playerIdentifier || '').trim();
    if (!/^[0-9]{4,20}$/.test(playerId)) {
      return;
    }
    // Ya consultado hace menos de 60s (o en vuelo) para este mismo ID: no
    // repetir la llamada. Pasados 60s se permite refrescar (mismo TTL que
    // el caché del servidor), por si el jugador acaba de comprar un pase.
    const recentlyChecked = playerId === bsPassCheckedPlayerId && (Date.now() - bsPassCheckedAt) < 60000;
    if (recentlyChecked || playerId === bsPassPendingPlayerId) {
      return;
    }
    bsPassPendingPlayerId = playerId;

    const requestId = ++bsPassStockSeq;
    setBsPassStockNote('info', 'Comprobando disponibilidad de pases…');

    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const abortTimer = controller ? window.setTimeout(() => controller.abort(), 90000) : null;

    try {
      const requestBody = new URLSearchParams();
      requestBody.set('game_id', String(bsPassStockConfig.gameId));
      requestBody.set('player_id', playerId);

      const response = await fetch(buildAppUrl('/api/bs_pass_stock.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: requestBody.toString(),
        signal: controller ? controller.signal : undefined,
      });

      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }

      if (requestId !== bsPassStockSeq) {
        return;
      }

      if (data && data.ok && data.applicable) {
        bsPassCheckedPlayerId = playerId;
        bsPassCheckedAt = Date.now();
        const blocked = Array.isArray(data.blocked) ? data.blocked : [];
        const deselected = applyBsPassStock(blocked);
        if (deselected) {
          setBsPassStockNote('warning', 'El pase que habías seleccionado ya fue adquirido en esta cuenta; elige otro paquete.');
        } else {
          setBsPassStockNote('', '');
        }
      } else {
        applyBsPassStock([]);
        setBsPassStockNote('', '');
      }
    } catch (error) {
      if (requestId === bsPassStockSeq) {
        applyBsPassStock([]);
        setBsPassStockNote('', '');
      }
    } finally {
      if (bsPassPendingPlayerId === playerId) {
        bsPassPendingPlayerId = '';
      }
      if (abortTimer) {
        window.clearTimeout(abortTimer);
      }
    }
  }

  // ── Disponibilidad de Pase de Nivel ─────────────────────────────────────
  // Los 6 paquetes se muestran siempre por defecto (para que el cliente vea
  // qué existe antes de escribir su ID). En cuanto el validador responde
  // tras verificar el nombre, los que no correspondan a ese jugador se dejan
  // visibles pero oscurecidos/deshabilitados con la etiqueta "No disponible"
  // (nunca se ocultan de la grilla). Si el validador responde con un error
  // (IP bloqueada, servicio pausado, timeout, etc.) se bloquean TODOS con la
  // etiqueta "Error al validar" — fail-CLOSED visual, nunca fail-open: un
  // fallo del proveedor no debe habilitar la compra de paquetes no
  // verificados. La consulta se dispara desde dentro del bloque de éxito de
  // verifyCurrentPlayer (nunca en paralelo, nunca con un nombre no
  // verificado) para no saturar la IP del proveedor con IDs falsos/sin cuenta.
  let levelPassSeq = 0;

  function blockPackCardForLevelPass(card, label) {
    if (!card) {
      return;
    }
    card.classList.add('levelpass-locked');
    card.setAttribute('aria-disabled', 'true');
    card.setAttribute('data-lock-label', label || 'No disponible');
  }

  function unblockPackCardForLevelPass(card) {
    if (!card) {
      return;
    }
    card.classList.remove('levelpass-locked');
    card.removeAttribute('aria-disabled');
    card.removeAttribute('data-lock-label');
  }

  // lockLabel: si se pasa, TODOS los paquetes se bloquean con ese texto
  // (usado para el estado de error del validador) en vez de compararlos
  // contra availableIds.
  function applyLevelPassAvailability(availableIds, lockLabel) {
    const available = new Set((availableIds || []).map(String));
    let deselected = false;
    levelPassConfig.packageIds.forEach((packageId) => {
      const card = findPackCardById(packageId);
      if (!card) {
        return;
      }
      if (!lockLabel && available.has(String(packageId))) {
        unblockPackCardForLevelPass(card);
      } else {
        blockPackCardForLevelPass(card, lockLabel || 'No disponible');
        if (activePack && String(activePack.id) === String(packageId)) {
          deselected = true;
        }
      }
    });
    if (deselected) {
      deselectBlockedActivePack();
    }
    return deselected;
  }

  // Sin ID verificado no hay forma de saber cuáles corresponden: se muestran
  // los 6 de nuevo (mismo estado que el render inicial).
  function clearLevelPassCheck() {
    levelPassSeq += 1;
    if (!levelPassConfig || !levelPassConfig.enabled) {
      return;
    }
    levelPassConfig.packageIds.forEach((packageId) => {
      unblockPackCardForLevelPass(findPackCardById(packageId));
    });
  }

  async function runLevelPassCheck(playerIdentifier) {
    if (!levelPassConfig || !levelPassConfig.enabled) {
      return;
    }
    const playerId = String(playerIdentifier || '').trim();
    if (!/^[0-9]{4,20}$/.test(playerId)) {
      return;
    }

    const requestId = ++levelPassSeq;
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const abortTimer = controller ? window.setTimeout(() => controller.abort(), 45000) : null;

    try {
      const requestBody = new URLSearchParams();
      requestBody.set('game_id', String(levelPassConfig.gameId));
      requestBody.set('player_id', playerId);

      const response = await fetch(buildAppUrl('/api/levelpass_check.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: requestBody.toString(),
        signal: controller ? controller.signal : undefined,
      });

      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }

      if (requestId !== levelPassSeq) {
        return;
      }

      if (data && data.ok && data.applicable) {
        applyLevelPassAvailability(Array.isArray(data.available) ? data.available : []);
      } else if (data && data.ok && !data.applicable) {
        // El juego no tiene paquetes de pase de nivel configurados (no
        // debería pasar si levelPassConfig.enabled es true, pero no hay
        // nada que bloquear en ese caso).
        applyLevelPassAvailability(levelPassConfig.packageIds);
      } else {
        // Fail-closed visual: el validador respondió con un error (o la
        // respuesta no fue válida) — se bloquean todos con "Error al
        // validar" en vez de habilitarlos para la compra.
        applyLevelPassAvailability([], 'Error al validar');
      }
    } catch (error) {
      if (requestId !== levelPassSeq) {
        return;
      }
      // Fail-closed visual: cualquier error de red también bloquea todos.
      applyLevelPassAvailability([], 'Error al validar');
    } finally {
      if (abortTimer) {
        window.clearTimeout(abortTimer);
      }
    }
  }

  // ── Tabs de categorías de paquetes ───────────────────────────────────────
  // Si el juego tiene categorías de paquetes creadas, los paquetes se agrupan
  // en tabs (una por categoría + "Otros" para los que no tienen). Solo puede
  // haber paquetes seleccionados de UN tab a la vez: cambiar de tab reinicia
  // por completo la selección/carrito actual.
  let currentActivePackCategoryTab = null;

  function applyPackCategoryTab(tabId) {
    currentActivePackCategoryTab = tabId;
    packCards2.forEach((card) => {
      const column = card.closest('.col') || card;
      const cardTab = column.dataset.packageCategory || 'otros';
      column.dataset.tabHidden = (cardTab === tabId) ? '0' : '1';
      updateColumnVisibility(column);
    });
  }

  // El validador de Pase de Nivel solo debe consultarse cuando el cliente
  // está viendo la pestaña de categoría de "Pases de nivel" — si compra
  // recargas normales en otra pestaña del mismo juego, no debe dispararse.
  function activeTabHasLevelPassPackages() {
    if (!levelPassConfig || !levelPassConfig.enabled) {
      return false;
    }
    if (!packCategoryTabsBar) {
      // Este juego no usa pestañas de categoría: no hay forma de aislar por
      // pestaña, así que se mantiene el comportamiento previo (todo el
      // juego comparte un único formulario de verificación).
      return true;
    }
    if (!currentActivePackCategoryTab) {
      return false;
    }
    return Array.from(document.querySelectorAll('[data-package-category]')).some(
      (col) => col.dataset.packageCategory === currentActivePackCategoryTab && col.hasAttribute('data-levelpass-key')
    );
  }

  function resetSelectionForPackCategoryTabSwitch() {
    const multiCartCheckEl = document.getElementById('multi-cart-check');
    if (multiCartCheckEl) {
      // Reutiliza el reset completo del modo carrito (vacía cartItems, quita
      // neon-selected, oculta resúmenes, etc.) sin duplicar esa lógica aquí.
      multiCartCheckEl.dispatchEvent(new Event('change'));
    } else {
      cartItems = [];
      activePack = null;
      packCards2.forEach((card) => card.classList.remove('neon-selected'));
      updateButtonState();
    }

    // El ID de jugador es específico del juego, no de la categoría: al cambiar
    // de tab se borra para evitar comprar en la categoría nueva con un ID que
    // el cliente escribió pensando en los paquetes de la categoría anterior.
    // Se limpia DESPUÉS del dispatch de arriba porque ese 'change' dispara
    // renderPlayerFields(null), que re-rellena el campo con el último ID
    // recordado (defaultOrderUserIdentifier) si lo encuentra vacío.
    const playerIdInputEl = document.getElementById('order-user-id');
    if (playerIdInputEl) {
      playerIdInputEl.value = '';
    }
    resetPlayerVerificationState();
  }

  const packCategoryTabsBar = document.getElementById('pack-category-tabs');
  if (packCategoryTabsBar) {
    const packCategoryTabButtons = Array.from(packCategoryTabsBar.querySelectorAll('.pack-category-tab-btn'));
    packCategoryTabButtons.forEach((tabBtn) => {
      tabBtn.addEventListener('click', () => {
        if (tabBtn.classList.contains('active')) {
          return;
        }
        packCategoryTabButtons.forEach((otherBtn) => {
          otherBtn.classList.remove('active');
          otherBtn.setAttribute('aria-selected', 'false');
        });
        tabBtn.classList.add('active');
        tabBtn.setAttribute('aria-selected', 'true');
        applyPackCategoryTab(tabBtn.dataset.categoryTab || 'otros');
        resetSelectionForPackCategoryTabSwitch();
      });
    });
    if (packCategoryTabButtons.length) {
      applyPackCategoryTab(packCategoryTabButtons[0].dataset.categoryTab || 'otros');
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

  /* Si la API respondió que el usuario está bloqueado, muestra el aviso con
     el botón de WhatsApp del administrador y detiene el flujo de compra. */
  function handleBlockedUserResponse(data) {
    if (!data || !data.blocked) {
      return false;
    }
    const waUrl = String(data.whatsapp_url || '').trim();
    const msg = String(data.message || 'Usuario Bloqueado, Contacte al administrador para más información');
    renderPaymentActionButtons(waUrl !== '' ? [{
      label: 'Contactar al administrador por WhatsApp',
      className: 'btn-success',
      onClick: () => window.open(waUrl, '_blank', 'noopener')
    }] : []);
    showPaymentStatusModal('Usuario Bloqueado', msg, 'danger');
    return true;
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
    // Sistema de Comentarios: bloque post-compra, solo si la compra salió bien.
    aplicarBloquePostCompra(normalizedType === 'success');
    scrollPaymentModalToTop();
    setOverlayVisible(paymentStatusModal, true);
  }

  // Muestra "Gracias por su compra / déjanos tu comentario" al terminar bien
  // una recarga. Según el estado de sesión hace 3 cosas distintas:
  //   - logueado          -> abre directo el formulario de reseña
  //   - invitado          -> ofrece registrarse (con el comentario dentro del
  //                          registro) o iniciar sesión sin salir de aquí
  // El pedido se toma de activePaymentOrder, que es el que se acaba de pagar.
  function aplicarBloquePostCompra(esExito) {
    const bloque = document.getElementById('cmt-postcompra');
    if (!bloque) return;

    if (!esExito) {
      bloque.classList.add('d-none');
      return;
    }

    const pedidoId = (activePaymentOrder && activePaymentOrder.orderId) ? activePaymentOrder.orderId : 0;
    bloque.classList.remove('d-none');

    const btn = document.getElementById('cmt-postcompra-btn');
    if (!btn || btn.dataset.cmtListo === '1') return;
    btn.dataset.cmtListo = '1';

    btn.addEventListener('click', function () {
      const logueado = <?= isset($_SESSION['auth_user']['id']) ? 'true' : 'false' ?>;
      if (logueado) {
        // Ya tiene cuenta y sesión: se cierra esta ventana y se abre el
        // formulario normal de reseña.
        setOverlayVisible(paymentStatusModal, false);
        const abrir = document.querySelector('[data-cmt-abrir]');
        if (abrir) {
          abrir.click();
        } else {
          window.location.href = <?= json_encode(app_path('/') . '#resenas', JSON_UNESCAPED_SLASHES) ?>;
        }
        return;
      }
      // Invitado: registrarse comentando de una vez, o iniciar sesión aquí
      // mismo (al volver, el pedido queda vinculado a su cuenta).
      setOverlayVisible(paymentStatusModal, false);
      if (typeof window.cmtPrepararRegistroConComentario === 'function') {
        window.cmtPrepararRegistroConComentario(pedidoId);
      } else if (typeof window.openAuthModal === 'function') {
        window.openAuthModal('register');
      }
    });
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
        const isAccountSaleResult = !!getAccountSalePayload(data);
        const successMessage = isAccountSaleResult
          ? 'Pago verificado y cuenta entregada correctamente.'
          : 'Tu recarga fue procesada y enviada correctamente.';
        const successNote = buildBloodStrikeEliteDiscordSuccessNote(data);
        setPaymentAlert(successMessage, 'success', { extraMessage: successNote });
        setPaymentFormDisabled(true);
        clearPaymentTimer();
        setCancelOrderButtonMode('close');
        if (!isAccountSaleResult) {
          paymentStatusShouldCloseAll = true;
        }
        showPaymentStatusModal('¡Recarga Exitosa!', successMessage, 'success', { extraMessage: successNote });
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
          const completedMsg = 'Tu recarga fue procesada y enviada correctamente.';
          setPaymentAlert(completedMsg, 'success', { extraMessage: successNote });
          renderDeliveredCodes(data);
          if (!getAccountSalePayload(data)) {
            paymentStatusShouldCloseAll = true;
          }
          setPaymentFormDisabled(true);
          clearPaymentTimer();
          setCancelOrderButtonMode('close');
          showPaymentStatusModal('¡Recarga Exitosa!', completedMsg, 'success', { extraMessage: successNote });
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
          const completedMsg = 'Tu recarga fue procesada y enviada correctamente.';
          setPaymentAlert(completedMsg, 'success', { extraMessage: successNote });
          renderDeliveredCodes(data);
          if (!getAccountSalePayload(data)) {
            paymentStatusShouldCloseAll = true;
          }
          setPaymentFormDisabled(true);
          clearPaymentTimer();
          setCancelOrderButtonMode('close');
          showPaymentStatusModal('¡Recarga Exitosa!', completedMsg, 'success', { extraMessage: successNote });
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
      return `Últimos ${digits} dígitos de la referencia`;
    }
    return 'Número de referencia del pago';
  }

  function paymentReferenceHelpText(method) {
    const digits = Number(method && method.referencia_digitos ? method.referencia_digitos : 0);
    if (digits > 0) {
      return `Ingresa los últimos ${digits} dígitos. Si pegas la referencia completa, tomaremos los últimos ${digits} automáticamente.`;
    }
    return 'Ingresa el número de referencia de tu transferencia o pago.';
  }
  function binancePagonorteReferencePlaceholder() {
    const digits = Number(binancePagonorteReferenceDigits || 0);
    if (digits > 0) {
      return `Últimos ${digits} dígitos de la referencia`;
    }
    return 'Inserte su número de referencia para comprobar el pago';
  }

  function binancePagonorteReferenceHelpText() {
    const digits = Number(binancePagonorteReferenceDigits || 0);
    if (digits > 0) {
      return `Ingresa los últimos ${digits} dígitos. Si pegas la referencia completa, tomaremos los últimos ${digits} automáticamente.`;
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

  // Chequeo en vivo de "referencia ya usada" mientras el cliente escribe/pega
  // la referencia en el modal de pago — antes de darle clic a "Realizar
  // Compra". Reutiliza la MISMA regla del backend (find_reference_reuse_conflict,
  // sin importar el estado del pedido previo), así el cliente se entera de
  // inmediato en vez de recibir el error genérico recién al confirmar.
  let referenceAlreadyUsed = false;
  let referenceUsedCheckSeq = 0;
  let referenceUsedCheckTimer = null;
  let paymentSubmitButtonLabelBeforeUsedCheck = null;
  let referenceUsedMessage = 'Referencia ya Usada';

  function setReferenceUsedState(isUsed, message) {
    referenceAlreadyUsed = isUsed;
    if (isUsed) {
      referenceUsedMessage = String(message || 'Referencia ya usada').trim() || 'Referencia ya usada';
    }
    if (paymentSubmitButton) {
      paymentSubmitButton.disabled = isUsed;
      if (isUsed) {
        if (paymentSubmitButtonLabelBeforeUsedCheck === null) {
          paymentSubmitButtonLabelBeforeUsedCheck = paymentSubmitButton.textContent;
        }
        paymentSubmitButton.textContent = referenceUsedMessage;
      } else if (paymentSubmitButtonLabelBeforeUsedCheck !== null) {
        paymentSubmitButton.textContent = paymentSubmitButtonLabelBeforeUsedCheck;
        paymentSubmitButtonLabelBeforeUsedCheck = null;
      }
    }
    if (paymentReferenceHelp) {
      if (isUsed) {
        paymentReferenceHelp.textContent = referenceUsedMessage;
        paymentReferenceHelp.style.color = '#f87171';
      } else {
        paymentReferenceHelp.style.color = '';
      }
    }
  }

  function checkReferenceUsedLive() {
    if (!paymentReferenceInput) return;
    const requiredDigits = Number(paymentReferenceInput.dataset.requiredDigits || '0');
    const reference = paymentReferenceInput.value.trim();
    const minLen = requiredDigits > 0 ? requiredDigits : 4;
    if (reference.length < minLen) {
      if (referenceAlreadyUsed) setReferenceUsedState(false);
      return;
    }
    const seq = ++referenceUsedCheckSeq;
    const amount = Number(activePaymentOrder ? activePaymentOrder.baseAmount : 0) || 0;
    fetch(buildAppUrl('/api/pedidos.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=check_reference_used&reference=${encodeURIComponent(reference)}&required_digits=${encodeURIComponent(requiredDigits)}&amount=${encodeURIComponent(amount)}`
    })
      .then((response) => response.json())
      .then((data) => {
        if (seq !== referenceUsedCheckSeq) return; // el cliente siguió escribiendo, respuesta obsoleta
        setReferenceUsedState(!!(data && data.used), data && data.message);
      })
      .catch(() => {
        // Silencioso: si falla el chequeo en vivo no se bloquea nada — la
        // verificación real al confirmar el pago sigue intacta.
      });
  }

  function scheduleReferenceUsedCheck() {
    clearTimeout(referenceUsedCheckTimer);
    referenceUsedCheckTimer = setTimeout(checkReferenceUsedLive, 350);
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
      paymentSubmitButton.textContent = buildConfirmButtonLabel((activePaymentOrder && activePaymentOrder.confirmedTotalText) || '');
    }
  }

  if (paymentStatusModalAccept) {
    paymentStatusModalAccept.addEventListener('click', function() {
      clearPaymentStatusPolling();
      setOverlayVisible(paymentStatusModal, false);
      if (paymentStatusShouldCloseAll) {
        paymentStatusShouldCloseAll = false;
        closePaymentModal(true);
        resetCheckoutState();
      } else {
        scrollPaymentSubmitIntoView();
      }
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

  function showAdminApiDebugModal(detail) {
    const modal = document.getElementById('admin-api-debug-modal');
    const pre   = document.getElementById('admin-debug-json');
    if (!modal || !pre) return;
    pre.textContent = JSON.stringify(detail, null, 2);
    setOverlayVisible(modal, true);
  }

  function appendAdminDebugLink(container, detail) {
    if (!currentUserIsAdmin || !detail || !container) return;
    const existing = container.querySelector('.admin-debug-link-wrap');
    if (existing) existing.remove();
    const wrap = document.createElement('div');
    wrap.className = 'admin-debug-link-wrap';
    wrap.style.cssText = 'margin-top:12px;padding-top:10px;border-top:1px solid #374151;';
    const link = document.createElement('button');
    link.type = 'button';
    link.textContent = '🔍 Ver motivo error (admin)';
    link.style.cssText = 'background:none;border:none;color:#f59e0b;font-size:13px;cursor:pointer;text-decoration:underline;padding:0;';
    link.addEventListener('click', () => showAdminApiDebugModal(detail));
    wrap.appendChild(link);
    container.appendChild(wrap);
  }

  function renderPaymentFailureDetails(data, reference, totalText) {
    console.error('[VG] verificación de pago fallida:', { failure_type: (data && data.failure_type) || null, message: (data && data.message) || null, reasons: (data && data.reasons) || null, reference, totalText, full_response: data });
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
    const adminDetail = data && data.admin_error_detail ? data.admin_error_detail : null;
    appendAdminDebugLink(paymentModalReasons, adminDetail);
    appendAdminDebugLink(paymentStatusModalReasons, adminDetail);
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
    // No se usa renderPaymentFailureDetails() aquí: para el tipo
    // 'server_or_data_mismatch' esa función descarta "reasons" y siempre
    // muestra el título fijo "Su Pago está en proceso, Espere 1 min..." sin
    // contenido — un mensaje genérico y a veces contradictorio (ej. cuando
    // errorMessage ya es un motivo específico y distinto, como "la
    // referencia ya está asociada a un pedido cancelado"). El mensaje real
    // ya se muestra en el modal principal (setPaymentAlert/showPaymentStatusModal);
    // aquí solo se limpia cualquier tarjeta de soporte previa.
    clearPaymentSupportUi();
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
    [paymentMethodSelect, paymentReferenceInput, paymentPhoneInput, paymentSubmitButton,
      paymentNombreInput, paymentCedulaInput, paymentPhoneAdvInput, paymentAdvReferenceInput, paymentWhatsappBtn,
      ...getPaymentModeButtons()].forEach((field) => {
      if (field) {
        field.disabled = disabled;
      }
    });
  }

  function updateAdvancedFormVisibility(method) {
    const isAdvanced = !!(method && method.formulario_verificacion);
    if (paymentWhatsappWrap) paymentWhatsappWrap.classList.toggle('d-none', !isAdvanced);
    if (paymentAdvReferenceInput) {
      const digits = Number(method && method.referencia_digitos ? method.referencia_digitos : 0);
      paymentAdvReferenceInput.placeholder = paymentReferencePlaceholder(method);
      if (paymentAdvReferenceHelp) paymentAdvReferenceHelp.textContent = paymentReferenceHelpText(method);
      const advLabel = document.getElementById('payment-adv-reference-label');
      if (advLabel) {
        advLabel.textContent = digits > 0
          ? `Número de referencia (últimos ${digits} dígitos)`
          : 'Número de referencia del pago';
      }
      paymentAdvReferenceInput.maxLength = 120;
      paymentAdvReferenceInput.dataset.requiredDigits = String(digits > 0 ? digits : 0);
    }
  }

  function buildAdvancedVerificationWhatsappUrl() {
    if (!paymentSupportWhatsappBase) return '';
    const orderId = activePaymentOrder ? activePaymentOrder.orderId : '';
    const productName = paymentSummaryProduct ? paymentSummaryProduct.textContent : '';
    const userIdentifier = paymentSummaryUser ? paymentSummaryUser.textContent : '';
    const totalText = paymentSummaryTotal ? paymentSummaryTotal.textContent : '';
    const nombre = paymentNombreInput ? paymentNombreInput.value.trim() : '';
    const cedula = paymentCedulaInput ? paymentCedulaInput.value.trim() : '';
    const telefono = paymentPhoneAdvInput ? paymentPhoneAdvInput.value.trim() : '';
    const referencia = paymentAdvReferenceInput ? paymentAdvReferenceInput.value.trim() : '';
    const message = [
      'Hola, envío comprobante de pago para verificación.',
      `Pedido: #${orderId || '-'}`,
      `Juego: ${currentGameName || '-'}`,
      `Producto: ${productName || '-'}`,
      `ID Jugador: ${userIdentifier || '-'}`,
      `Nombre: ${nombre || '-'}`,
      `Cédula: ${cedula || '-'}`,
      `Teléfono: ${telefono || '-'}`,
      `Referencia: ${referencia || '-'}`,
      `Monto: ${totalText || '-'}`,
      '(Adjunto captura del comprobante de pago)'
    ].join('\n');
    return `${paymentSupportWhatsappBase}?text=${encodeURIComponent(message)}`;
  }

  if (paymentWhatsappBtn) {
    paymentWhatsappBtn.addEventListener('click', () => {
      const url = buildAdvancedVerificationWhatsappUrl();
      if (!url) {
        showToast('No hay número de WhatsApp configurado.', 'error');
        return;
      }
      pendingWhatsappUrl = url;
      setOverlayVisible(paymentWhatsappConfirmModal, true);
    });
  }

  if (paymentWhatsappModalCancelBtn) {
    paymentWhatsappModalCancelBtn.addEventListener('click', () => {
      pendingWhatsappUrl = '';
      setOverlayVisible(paymentWhatsappConfirmModal, false);
    });
  }

  if (paymentWhatsappModalConfirmBtn) {
    paymentWhatsappModalConfirmBtn.addEventListener('click', () => {
      setOverlayVisible(paymentWhatsappConfirmModal, false);
      if (pendingWhatsappUrl) {
        window.open(pendingWhatsappUrl, '_blank', 'noopener');
        pendingWhatsappUrl = '';
      }
    });
  }

  if (paymentAdvReferenceInput) {
    /* Igual que el campo principal: solo N dígitos; pegar conserva los últimos N. */
    let _advRefPrevLen = 0;
    paymentAdvReferenceInput.addEventListener('paste', (e) => {
      const requiredDigits = Number(paymentAdvReferenceInput.dataset.requiredDigits || '0');
      if (requiredDigits <= 0) return;
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData).getData('text');
      const digitsOnly = pasted.replace(/\D+/g, '');
      paymentAdvReferenceInput.value = digitsOnly.slice(-requiredDigits);
      _advRefPrevLen = paymentAdvReferenceInput.value.length;
      if (paymentReferenceInput) {
        paymentReferenceInput.value = paymentAdvReferenceInput.value;
        paymentReferenceInput.dataset.requiredDigits = paymentAdvReferenceInput.dataset.requiredDigits || '0';
      }
      scheduleReferenceUsedCheck();
    });
    paymentAdvReferenceInput.addEventListener('input', () => {
      const requiredDigits = Number(paymentAdvReferenceInput.dataset.requiredDigits || '0');
      const limit = requiredDigits > 0 ? requiredDigits : 120;
      let digitsOnly = paymentAdvReferenceInput.value.replace(/\D+/g, '');
      if (digitsOnly.length > limit) {
        const inserted = digitsOnly.length - _advRefPrevLen;
        digitsOnly = inserted > 1 ? digitsOnly.slice(-limit) : digitsOnly.slice(0, limit);
      }
      paymentAdvReferenceInput.value = digitsOnly;
      _advRefPrevLen = digitsOnly.length;
      if (paymentReferenceInput) {
        paymentReferenceInput.value = paymentAdvReferenceInput.value;
        paymentReferenceInput.dataset.requiredDigits = paymentAdvReferenceInput.dataset.requiredDigits || '0';
      }
      scheduleReferenceUsedCheck();
    });
  }

  if (paymentPhoneAdvInput) {
    paymentPhoneAdvInput.addEventListener('input', () => {
      if (paymentPhoneInput) {
        paymentPhoneInput.value = paymentPhoneAdvInput.value;
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
      updateAdvancedFormVisibility(null);
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
      updateAdvancedFormVisibility(null);
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
      paymentReferenceInput.dataset.requiredDigits = String(Number(binancePagonorteReferenceDigits || 0) > 0 ? Number(binancePagonorteReferenceDigits || 0) : 0);
      return;
    }

    if (mode === 'binance') {
      updateAdvancedFormVisibility(null);
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
      updateAdvancedFormVisibility(null);
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
      updateAdvancedFormVisibility(null);
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
    const refLabel = document.getElementById('payment-reference-label');
    if (refLabel) {
      refLabel.textContent = digits > 0
        ? `Número de referencia (últimos ${digits} dígitos)`
        : 'Número de referencia del pago';
    }
    paymentReferenceInput.maxLength = 120;
    paymentReferenceInput.dataset.requiredDigits = String(digits > 0 ? digits : 0);
    updateAdvancedFormVisibility(method);
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
    console.trace('[DEBUG resetCheckoutState] called!');
    orderForm.reset();
    if (orderEmailInput) orderEmailInput.value = defaultOrderEmail || '';
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
    updateAdvancedFormVisibility(null);
    if (paymentNombreInput) paymentNombreInput.value = '';
    if (paymentCedulaInput) paymentCedulaInput.value = '';
    if (paymentPhoneAdvInput) paymentPhoneAdvInput.value = '';
    if (paymentAdvReferenceInput) paymentAdvReferenceInput.value = '';
    pendingWhatsappUrl = '';
  }

  // ── Refresco de movimientos bancarios en segundo plano ────────────────────
  // Mientras el cliente tiene abierto el modal de pago (haciendo su transferencia
  // y copiando la referencia), se refresca la tabla de movimientos del servidor
  // cada ~20s. Así, cuando confirme el pago, el movimiento normalmente ya estará
  // sincronizado y la verificación (que no se toca) lo encontrará a la primera,
  // sin depender de que la API bancaria responda a tiempo en ese momento exacto.
  let bankMovementsRefreshTimer = null;

  function bankMovementsRefreshMode() {
    const mode = String((activePaymentOrder && activePaymentOrder.paymentMode) || '').trim();
    if (mode === 'binance_pagonorte') {
      return 'binance_pagonorte';
    }
    if (mode === '' || mode === 'money') {
      return 'bank';
    }
    return ''; // points / Binance Pay / PayPal: no usan movimientos bancarios
  }

  function requestBankMovementsRefresh() {
    const mode = bankMovementsRefreshMode();
    if (!mode) {
      return;
    }
    const body = new URLSearchParams();
    body.set('action', 'refresh_bank_movements');
    body.set('mode', mode);
    fetch(buildAppUrl('/api/pedidos.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).catch(() => {});
  }

  function startBankMovementsAutoRefresh() {
    stopBankMovementsAutoRefresh();
    requestBankMovementsRefresh();
    bankMovementsRefreshTimer = window.setInterval(requestBankMovementsRefresh, 20000);
  }

  function stopBankMovementsAutoRefresh() {
    if (bankMovementsRefreshTimer) {
      window.clearInterval(bankMovementsRefreshTimer);
      bankMovementsRefreshTimer = null;
    }
  }

  function closePaymentModal(resetState) {
    paymentStatusShouldCloseAll = false;
    clearPaymentTimer();
    stopBankMovementsAutoRefresh();
    setOverlayVisible(paymentModal, false);
    setPaymentAlert('', 'info');
    if (resetState) {
      activePaymentOrder = null;
      paymentReferenceInput.value = '';
      setReferenceUsedState(false);
      paymentPhoneInput.value = defaultPaymentPhone || '';
      setPaymentMethodQrState('', 'QR del método de pago');
      clearPaymentSupportUi();
      setCancelOrderButtonMode('cancel');
      if (paymentWinPointsCard) {
        paymentWinPointsCard.classList.add('d-none');
      }
      if (paymentSubmitButton) {
        paymentSubmitButton.textContent = buildConfirmButtonLabel((activePaymentOrder && activePaymentOrder.confirmedTotalText) || '');
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
    setReferenceUsedState(false);
    paymentPhoneInput.value = defaultPaymentPhone || '';
    if (paymentNombreInput && paymentNombreInput.value.trim() === '') paymentNombreInput.value = defaultPaymentNombre || '';
    if (paymentCedulaInput && paymentCedulaInput.value.trim() === '') paymentCedulaInput.value = defaultPaymentCedula || '';
    if (paymentPhoneAdvInput && paymentPhoneAdvInput.value.trim() === '') paymentPhoneAdvInput.value = defaultPaymentPhone || '';
    if (orderEmailInput && orderEmailInput.value.trim() === '') orderEmailInput.value = defaultOrderEmail || '';
    setPaymentFormDisabled(false);
    setPaymentAlert('', 'info');
    clearPaymentSupportUi();
    if (paymentSubmitButton) {
      paymentSubmitButton.textContent = buildConfirmButtonLabel(totalText);
    }
    renderWinPointsPaymentState(pack, currentMethod);
    setCancelOrderButtonMode('cancel');
    pendingOpenModal = function() {
      setOverlayVisible(paymentModal, true);
      scrollPaymentModalToTop();
      clearPaymentTimer();
      updatePaymentTimer();
      paymentTimerInterval = setInterval(updatePaymentTimer, 1000);
      startBankMovementsAutoRefresh();
    };
    if (preConfirmTosCheck) preConfirmTosCheck.checked = false;
    if (preConfirmProceedBtn) {
      preConfirmProceedBtn.disabled = true;
      preConfirmProceedBtn.textContent = totalText ? 'CONFIRMAR COMPRA - ' + totalText : 'CONFIRMAR COMPRA';
    }
    setOverlayVisible(paymentPreConfirmModal, true);
    if (paymentPreConfirmModal) paymentPreConfirmModal.scrollTop = 0;
    return true;
  }

  function updatePackPrices() {
    const showPointsPrices = preferredCheckoutPaymentMode === 'points';
    packCards.forEach(card => {
      const base = parseFloat(card.getAttribute('data-base'));
      const dropPercent = Math.max(0, Math.min(99, Number(card.getAttribute('data-drop-percent') || 0)));
      const precioBase = normalizeCurrencyAmount(base * monedaActualTasa, monedaActualMostrarDecimales);
      const precio = dropPercent > 0
        ? normalizeCurrencyAmount(base * monedaActualTasa * (1 - dropPercent / 100), monedaActualMostrarDecimales)
        : precioBase;
      const winPointsActive = card.dataset.winPointsActive === '1';
      const winPointsRequired = Number(card.dataset.winPointsRequired || 0);
      if (showPointsPrices && winPointsActive && winPointsRequired > 0) {
        card.querySelector('.precio-label').textContent = winPointsRequired.toLocaleString('en-US');
        card.querySelector('.moneda-label').textContent = winPointsState.name || 'Pts';
      } else {
        card.querySelector('.precio-label').textContent = formatCurrencyAmount(precio, monedaActualMostrarDecimales);
        card.querySelector('.moneda-label').textContent = monedaActualClave;
      }
      card.setAttribute('data-price-value', String(precio));
      card.setAttribute('data-show-decimals', monedaActualMostrarDecimales ? '1' : '0');
      card.setAttribute('data-moneda', monedaActualClave);
      const originalLabel = card.querySelector('.precio-original-label');
      if (originalLabel) {
        originalLabel.textContent = formatCurrencyAmount(precioBase, monedaActualMostrarDecimales);
      }
    });
  }
  updatePackPrices();

  function updateButtonState() {
    // Cart mode: activePack is null by design — use cart-specific logic instead
    if (typeof cartMode !== 'undefined' && cartMode) {
      if (buyButton) {
        const hasItems = typeof cartItems !== 'undefined' && cartItems.length > 0;
        const requiredFields = Array.from(orderForm.querySelectorAll('[required]'));
        const requiredFilled = window.__gameNoPlayerIdRequired || requiredFields.every(f => f.value.trim() !== '');
        const cartSel = hasItems ? resolvePreferredCheckoutSelection(cartItems[0].pack) : null;
        const cartPointsBlocked = Boolean(cartSel && cartSel.mode === 'points' && !cartSel.canUsePointsNow);
        // El carrito comparte el mismo campo de ID de jugador que la compra
        // individual: si ese ID no está verificado, el carrito debe
        // bloquearse igual (antes esta rama de cartMode nunca llamaba a
        // requiresVerifiedPlayerForCheckout(), así que un ID inválido no
        // bloqueaba nada al comprar por carrito — bug crítico reportado).
        const needsPlayerVerificationCart = requiresVerifiedPlayerForCheckout();
        buyButton.disabled = !hasItems || !requiredFilled || cartPointsBlocked || needsPlayerVerificationCart;
        if (needsPlayerVerificationCart) {
          buyButton.textContent = 'ID USUARIO INVÁLIDO';
        } else if (cartPointsBlocked) {
          const _wp = (typeof winPointsState !== 'undefined') ? winPointsState : {};
          if (!_wp.loggedIn) {
            buyButton.textContent = `Inicia sesión para usar ${_wp.name || 'Puntos'}`;
          } else if (_wp.monthlyMinimumMet === false) {
            const _minAmt = _wp.monthlyMinimumRequired > 0 ? ` $${Number(_wp.monthlyMinimumRequired).toFixed(2)}` : '';
            buyButton.textContent = `Recarga mín.${_minAmt} para usar ${_wp.name || 'RECoins'}`;
          } else {
            buyButton.textContent = `${_wp.name || 'RECoins'} insuficientes`;
          }
        } else if (typeof syncCartBuyButton === 'function') {
          // Sin bloqueo por verificación/puntos: restaurar la etiqueta normal
          // (total/"Continuar con la compra"). Antes, al pasar de bloqueado a
          // habilitado, el texto se quedaba pegado en "ID USUARIO INVÁLIDO"
          // porque esta rama nunca lo restauraba — solo lo hacía otra función
          // (syncCartBuyButton/el resumen del pedido) que no se re-ejecuta al
          // verificar el jugador.
          syncCartBuyButton();
        }
      }
      syncPlayerVerificationUi();
      return;
    }
    // Solo controlar el estado del botón, no mostrar mensajes de error aquí
    const requiredFields = Array.from(orderForm.querySelectorAll("[required]"));
    let requiredFilled = Boolean(window.__gameNoPlayerIdRequired);
    if (!requiredFilled) {
      requiredFilled = requiredFields.every(f => f.value.trim() !== '' && isCheckoutFieldValid(f));
    }
    if (!activePack) {
      selectedPack.style.color = "#f87171";
      selectedPack.textContent = "Debes seleccionar un paquete.";
    } else {
      selectedPack.style.color = "";
      selectedPack.textContent = activePack.name;
    }
    const paymentSelection = activePack ? resolvePreferredCheckoutSelection(activePack) : null;
    const hasPaymentSelection = Boolean(paymentSelection && paymentSelection.mode);
    const pointsModeSelected = Boolean(paymentSelection && paymentSelection.mode === 'points');
    const pointsBlockedNotLoggedIn = pointsModeSelected && !winPointsState.loggedIn;
    const pointsSelectedButBlocked = Boolean(pointsModeSelected && (!paymentSelection.canUsePointsNow || !winPointsState.loggedIn));
    const needsPlayerVerification = requiresVerifiedPlayerForCheckout();
    const paymentDifferenceBlocked = activePack ? getPaymentDifferenceBreakdown(activePack, selectedTotalValue).blocksSelection : false;
    const blockedByGameEntryWindow = !gameEntryWindowAccepted;
    buyButton.disabled = !activePack || !requiredFilled || !hasPaymentSelection || needsPlayerVerification || paymentDifferenceBlocked || blockedByGameEntryWindow || pointsSelectedButBlocked;
    if (paymentDifferenceBlocked) {
      buyButton.textContent = paymentDifferenceBlockedBuyButtonLabel;
    } else if (activePack && !hasPaymentSelection) {
      buyButton.textContent = 'Selecciona un metodo de pago';
    } else if (pointsBlockedNotLoggedIn) {
      buyButton.textContent = `Inicia sesión para usar ${winPointsState.name || 'Puntos'}`;
    } else if (pointsSelectedButBlocked) {
      buyButton.textContent = `${winPointsState.name || 'Puntos'} insuficientes`;
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
    // In cart mode, keep the cart summary visible and ignore single-pack state
    if (typeof cartMode !== 'undefined' && cartMode) {
      if (typeof updateResumenCompraCart === 'function') updateResumenCompraCart();
      if (typeof cartGrandTotal === 'function' && typeof cartItems !== 'undefined' && cartItems.length > 0) {
        selectedTotalValue = cartGrandTotal();
      }
      const refPack = (typeof cartItems !== 'undefined' && cartItems.length > 0) ? cartItems[0].pack : null;
      renderPublicPaymentMethodCatalog(refPack);
      return;
    }
    const quantity = syncOrderQuantityInput();
    if (pack) {
      pack.purchaseQuantity = quantity;
      selectedPack.textContent = pack.name;
      selectedTotalValue = getPackTotalPrice(pack, quantity);
      if (couponApplied && Number(appliedCouponSummary.discountAmount || 0) > 0) {
        selectedTotalValue = normalizeCurrencyAmount(
          Math.max(0, selectedTotalValue - Number(appliedCouponSummary.discountAmount)),
          Boolean(pack.showDecimals)
        );
      }
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
    if (card.classList.contains('bs-pass-blocked') || card.classList.contains('levelpass-locked')) {
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
    if (accountGalleryModalBuy) {
      accountGalleryModalBuy.textContent = cartMode ? 'Agregar al carrito' : 'Comprar';
    }
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
  autoSelectDefaultPaymentMethod();
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
      if (cartMode && activePack) {
        const card = findPackCardById(activePack.id);
        if (card) {
          closeAccountGalleryModal();
          card.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
          return;
        }
      }
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

                  if (!activePack) {
                    const selectedCard = packCards2.find(c => c.classList.contains('neon-selected'));
                    if (selectedCard) {
                      activePack = buildPackStateFromCard(selectedCard);
                    }
                  }
                  const mode = resolveCheckoutPaymentModeFromOption(button.dataset.paymentOption);
                  const methodId = button.dataset.methodId || '';
                  const previousCurrencyCode = String(monedaActualClave || '').trim().toUpperCase();
                  storePreferredCheckoutPayment(mode, methodId);
                  const switchedCurrency = syncVisibleCurrencyWithPreferredPayment(activePack, { resetCoupon: !couponApplied });
                  const nextCurrencyCode = String(monedaActualClave || '').trim().toUpperCase();
                  // A blank previousCurrencyCode means no payment was previously selected — not a real change
                  const currencyActuallyChanged = previousCurrencyCode !== '' && previousCurrencyCode !== nextCurrencyCode && nextCurrencyCode !== '';

                  // Coupon was calculated in the old currency — reset and auto-reapply in the new one
                  if (currencyActuallyChanged && couponApplied) {
                    const savedCouponCode = couponValue;
                    // In cart mode, restore the blindado lock to the raw item sum so the
                    // re-applied coupon uses the correct base, not the already-discounted total
                    if (cartMode && cartItems.length > 0) {
                      cartTotalBlindado = normalizeCurrencyAmount(
                        cartItems.reduce((s, ci) => s + cartItemSubtotal(ci), 0),
                        cartItems[0].pack.showDecimals
                      );
                    }
                    // Restore selectedTotalValue to the full pack price in the new currency
                    // before resetCouponState calls renderPublicOrderSummary internally
                    if (activePack) {
                      selectedTotalValue = getPackTotalPrice(activePack, getOrderQuantity());
                    }
                    resetCouponState(true);
                    if (savedCouponCode && couponInput && applyCouponButton) {
                      couponInput.value = savedCouponCode;
                      // Auto-reapply in the new currency so the user doesn't have to click again
                      setTimeout(() => applyCouponButton.click(), 0);
                    }
                  }

                  if (!switchedCurrency) {
                    updatePackPrices();
                    updateResumenCompra(activePack);
                  } else if (currencyActuallyChanged) {
                    scrollToPackPricingSection();
                  }

                  if (activePaymentOrder) {
                    setActivePaymentMode(mode, methodId, { expandSelected: shouldExpandSinglePaymentOption() });
                  }
                  updateButtonState();
                  // In cart mode, re-sync the summary and button after payment method selection
                  if (cartMode) {
                    updateResumenCompraCart();
                    syncCartBuyButton();
                  }
                });
              }

              if (paymentReferenceInput) {
                /* El campo acepta solo N dígitos (los configurados). Al PEGAR una
                   referencia más larga se conservan los ÚLTIMOS N; al teclear se
                   bloquea el exceso. Solo afecta la captura de la referencia. */
                let _refPrevLen = 0;
                paymentReferenceInput.addEventListener('paste', function(e) {
                  const requiredDigits = Number(paymentReferenceInput.dataset.requiredDigits || '0');
                  if (requiredDigits <= 0) return;
                  e.preventDefault();
                  const pasted = (e.clipboardData || window.clipboardData).getData('text');
                  const digitsOnly = pasted.replace(/\D+/g, '');
                  paymentReferenceInput.value = digitsOnly.slice(-requiredDigits);
                  _refPrevLen = paymentReferenceInput.value.length;
                  scheduleReferenceUsedCheck();
                });
                paymentReferenceInput.addEventListener('input', function() {
                  const requiredDigits = Number(paymentReferenceInput.dataset.requiredDigits || '0');
                  const limit = requiredDigits > 0 ? requiredDigits : 120;
                  let digitsOnly = paymentReferenceInput.value.replace(/\D+/g, '');
                  if (digitsOnly.length > limit) {
                    const inserted = digitsOnly.length - _refPrevLen;
                    digitsOnly = inserted > 1
                      ? digitsOnly.slice(-limit)   /* pegado sin evento paste: últimos N */
                      : digitsOnly.slice(0, limit); /* tecleo: bloquear el excedente */
                  }
                  paymentReferenceInput.value = digitsOnly;
                  _refPrevLen = digitsOnly.length;
                  scheduleReferenceUsedCheck();
                });
              }

              if (paymentCancelOrderButton) {
                paymentCancelOrderButton.addEventListener('click', function() {
                  closePaymentModal(true);
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
                  // Cart mode: handled by executeCartPurchase via onclick property
                  if (activePaymentOrder && activePaymentOrder.isCart) return;

                  if (referenceAlreadyUsed) {
                    setPaymentAlert(referenceUsedMessage + '. Ingresa la referencia correcta de tu pago.', 'danger');
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
                  const isAdvancedForm = !!(selectedMethod && selectedMethod.formulario_verificacion);
                  if (isAdvancedForm && paymentNombreInput && !paymentNombreInput.value.trim()) {
                    setPaymentAlert('Debes ingresar el nombre del titular.', 'danger');
                    paymentNombreInput.focus();
                    return;
                  }
                  if (isAdvancedForm && paymentCedulaInput && !paymentCedulaInput.value.trim()) {
                    setPaymentAlert('Debes ingresar tu número de cédula.', 'danger');
                    if (paymentCedulaInput) paymentCedulaInput.focus();
                    return;
                  }
                  if (isAdvancedForm && paymentPhoneAdvInput && !paymentPhoneAdvInput.value.trim()) {
                    setPaymentAlert('Debes ingresar el número de teléfono del titular.', 'danger');
                    paymentPhoneAdvInput.focus();
                    return;
                  }
                  if (requiresManualConfirmation && !reference) {
                    setPaymentAlert('Debes ingresar el número de referencia.', 'danger');
                    return;
                  }
                  if (paymentMode === 'money' && requiredDigits > 0 && reference.length < requiredDigits) {
                    setPaymentAlert(`La referencia debe contener al menos ${requiredDigits} dígitos.`, 'danger');
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

                  // Validate email in modal before submitting
                  const modalEmailVal = orderEmailInput ? orderEmailInput.value.trim() : '';
                  if (!modalEmailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(modalEmailVal)) {
                    setPaymentAlert('Debes ingresar un correo electrónico válido para recibir el comprobante.', 'danger');
                    if (orderEmailInput) orderEmailInput.focus();
                    return;
                  }
                  if (activePaymentOrder) activePaymentOrder.email = modalEmailVal;

                  pendingPaymentExecution = function() {
                  setPaymentFormDisabled(true);
                  setPaymentAlert('', 'info');
                  let _lastPaymentApiData = null;
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
                      `phone=${encodeURIComponent(phone)}`,
                      `nombre_titular=${encodeURIComponent(paymentNombreInput ? paymentNombreInput.value.trim() : '')}`,
                      `cedula_titular=${encodeURIComponent(paymentCedulaInput ? paymentCedulaInput.value.trim() : '')}`,
                      `email=${encodeURIComponent(modalEmailVal)}`
                    ].join('&')
                  })
                  .then(async (response) => {
                    const data = await parseApiJsonResponse(response, 'No pudimos validar tu pago en este momento. Espera 1 minuto y vuelve a intentarlo.');
                    _lastPaymentApiData = data;
                    if (data && data.api_error) {
                      console.log('Error API:', data.api_error);
                    }
                    if (!response.ok || !data.ok) {
                      if (handleBlockedUserResponse(data)) {
                        setOverlayVisible(loadingModal, false);
                        setPaymentFormDisabled(false);
                        return;
                      }
                      throw new Error((data && data.message) ? data.message : 'No se pudieron guardar los datos del pago.');
                    }

                    if (data && data.win_points && Number.isFinite(Number(data.win_points.balance))) {
                      syncWinPointsSummaryFromResponse(data.win_points);
                      renderWinPointsPaymentState(activePaymentOrder.pack || activePack, selectedMethod);
                    }
                    if (paymentMode === 'money' && phone) {
                      defaultPaymentPhone = phone;
                    }
                    const nombreVal = paymentNombreInput ? paymentNombreInput.value.trim() : '';
                    const cedulaVal = paymentCedulaInput ? paymentCedulaInput.value.trim() : '';
                    if (nombreVal) defaultPaymentNombre = nombreVal;
                    if (cedulaVal) defaultPaymentCedula = cedulaVal;
                    const lastUsedIdentifier = activePaymentOrder && activePaymentOrder.userId ? String(activePaymentOrder.userId).trim() : '';
                    if (lastUsedIdentifier) defaultOrderUserIdentifier = lastUsedIdentifier;

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
                      const isAccountSaleResult = !!getAccountSalePayload(data);
                      const successMessage = isAccountSaleResult
                        ? (paymentMode === 'points' ? 'Canje realizado y cuenta entregada correctamente.' : 'La cuenta fue entregada correctamente.')
                        : (paymentMode === 'points' ? 'Canje realizado y recarga procesada correctamente.' : 'Tu recarga fue procesada y enviada correctamente.');
                      const successNote = buildBloodStrikeEliteDiscordSuccessNote(data);
                      setPaymentAlert(successMessage, 'success', { extraMessage: successNote });
                      renderDeliveredCodes(data);
                      renderOverpaidPaymentDifference(data);
                      setPaymentFormDisabled(true);
                      clearPaymentTimer();
                      setCancelOrderButtonMode('close');
                      if (!isAccountSaleResult) {
                        paymentStatusShouldCloseAll = true;
                      }
                      showPaymentStatusModal('¡Recarga Exitosa!', successMessage, 'success', { extraMessage: successNote });
                      return;
                    }

                    if (nextState === 'cancelado') {
                      const cancelMessage = data.message || 'La orden fue cancelada.';
                      setPaymentAlert(cancelMessage, 'danger');
                      if (String((data && data.provider_flow) || '').trim() !== '') {
                        renderProviderPaymentDetails(data, reference, getConfirmedPaymentTotalText());
                      } else {
                        // No se llama a renderPaymentFailureDetails() aquí:
                        // esa función solo agrega una tarjeta genérica que,
                        // al no reconocer un failure_type específico para un
                        // pedido ya cancelado, cae en el texto por defecto
                        // "Su Pago está en proceso, Espere 1 min..." — un
                        // mensaje contradictorio junto al de cancelMessage
                        // (que ya es el motivo real y específico). Solo se
                        // limpia cualquier tarjeta de soporte previa.
                        clearPaymentSupportUi();
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

                      const completedMsg = 'Tu recarga fue procesada y enviada correctamente.';
                      setPaymentAlert(
                        isCompletedFlow ? completedMsg : (successPresentation ? successPresentation.message : paidMessage),
                        requiresManualReview ? 'warning' : (isCompletedFlow ? 'success' : (successPresentation ? (successPresentation.statusType || 'info') : 'success')),
                        { extraMessage: paidNote }
                      );
                      if (!isCompletedFlow && (hasProviderDetails || effectiveProviderFlow === 'accepted' || effectiveProviderFlow === 'tracking')) {
                        renderProviderPaymentDetails(data, reference, getConfirmedPaymentTotalText());
                      } else if (isCompletedFlow) {
                        renderDeliveredCodes(data);
                      } else {
                        clearPaymentSupportUi();
                      }
                      renderOverpaidPaymentDifference(data);
                      setPaymentFormDisabled(true);
                      clearPaymentTimer();
                      setCancelOrderButtonMode('close');
                      if (isCompletedFlow && !getAccountSalePayload(data)) {
                        paymentStatusShouldCloseAll = true;
                      }
                      showPaymentStatusModal(
                        requiresManualReview ? 'Revisión requerida' : (isCompletedFlow ? '¡Recarga Exitosa!' : (successPresentation ? successPresentation.title : 'Operación exitosa')),
                        isCompletedFlow ? completedMsg : (successPresentation ? successPresentation.message : paidMessage),
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
                    console.error('[VG] error en submit_payment:', { message: errorMessage, error, api_response: _lastPaymentApiData });
                    setPaymentAlert(errorMessage, 'danger');
                    const _apiAdminDetail = _lastPaymentApiData && _lastPaymentApiData.admin_error_detail ? _lastPaymentApiData.admin_error_detail : null;
                    renderPaymentServerFailure(errorMessage, reference, getConfirmedPaymentTotalText());
                    appendAdminDebugLink(paymentModalReasons, _apiAdminDetail);
                    appendAdminDebugLink(paymentStatusModalReasons, _apiAdminDetail);
                    setPaymentFormDisabled(false);
                    showPaymentStatusModal('No se pudo completar la validación', errorMessage, 'danger');
                    if (activePaymentOrder && activePaymentOrder.expiresAtMs <= Date.now()) {
                      expireActiveOrder();
                    }
                  });
                  }; // end pendingPaymentExecution
                  const _exec = pendingPaymentExecution;
                  pendingPaymentExecution = null;
                  if (_exec) _exec();
                });
              }

              if (preConfirmTosCheck && preConfirmProceedBtn) {
                preConfirmTosCheck.addEventListener('change', function() {
                  preConfirmProceedBtn.disabled = !preConfirmTosCheck.checked;
                  if (preConfirmTosCheck.checked) {
                    setTimeout(function() {
                      preConfirmProceedBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }, 80);
                  }
                });
              }
              if (preConfirmCancelBtn) {
                preConfirmCancelBtn.addEventListener('click', function() {
                  setOverlayVisible(paymentPreConfirmModal, false);
                  pendingOpenModal = null;
                });
              }
              if (preConfirmProceedBtn) {
                preConfirmProceedBtn.addEventListener('click', function() {
                  if (!pendingOpenModal) return;
                  setOverlayVisible(paymentPreConfirmModal, false);
                  const fn = pendingOpenModal;
                  pendingOpenModal = null;
                  fn();
                });
              }

              if (dailyMissionSimulatePurchaseButton) {
                dailyMissionSimulatePurchaseButton.addEventListener('click', function() {
                  const originalContent = dailyMissionSimulatePurchaseButton.innerHTML;
                  dailyMissionSimulatePurchaseButton.disabled = true;
                  dailyMissionSimulatePurchaseButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Simulando...';

                  fetch(buildAppUrl('/api/daily_missions.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=complete_task&mission_key=purchase_any'
                  })
                  .then(async (response) => {
                    const data = await parseApiJsonResponse(response, 'No se pudo simular la compra en este momento.');
                    if (!response.ok || !data.ok) {
                      throw new Error((data && data.message) ? data.message : 'No se pudo simular la compra.');
                    }

                    const result = data && data.result ? data.result : null;
                    showToast(result && result.already_completed ? 'La tarea diaria de compra ya estaba completada.' : 'Compra simulada: tarea diaria completada.', 'success');
                  })
                  .catch((error) => {
                    showToast(normalizeApiRequestErrorMessage(error, 'No se pudo simular la compra en este momento.'), 'error');
                  })
                  .finally(() => {
                    dailyMissionSimulatePurchaseButton.disabled = false;
                    dailyMissionSimulatePurchaseButton.innerHTML = originalContent;
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

                // Cart mode: validate items; single-pack mode: validate activePack
                if (cartMode) {
                  if (cartItems.length === 0) {
                    showToast('Selecciona al menos un paquete antes de aplicar el cupón.', 'error');
                    return;
                  }
                } else if (!pack) {
                  showToast('Selecciona un paquete antes de aplicar el cupón.', 'error');
                  return;
                }

                // Single-pack drop: coupons don't apply to special-price packs
                if (!cartMode && pack && Number(pack.dropPercent || 0) > 0) {
                  showToast('Los cupones no aplican a paquetes en oferta especial.', 'error');
                  return;
                }

                // In cart mode use cart totals; in single-pack mode use activePack
                const effectivePack = (cartMode && cartItems.length > 0) ? cartItems[0].pack : pack;

                // Cart mode: coupon base = non-drop items subtotal only
                let nonDropSubtotalCoupon = 0;
                if (cartMode) {
                  cartItems.forEach(ci => {
                    if (!Number(ci.pack.dropPercent || 0)) {
                      const base = normalizeCurrencyAmount(parseFloat(ci.pack.priceValue || 0), ci.pack.showDecimals);
                      nonDropSubtotalCoupon += normalizeCurrencyAmount(base * ci.quantity, ci.pack.showDecimals);
                    }
                  });
                  if (nonDropSubtotalCoupon <= 0) {
                    showToast('Los cupones no aplican a paquetes en oferta especial.', 'error');
                    return;
                  }
                }
                const precioNumerico = cartMode ? String(normalizeCurrencyAmount(nonDropSubtotalCoupon, effectivePack.showDecimals)) : String(getPackTotalPrice(pack));

                console.log('Enviando cupón:', cupon, 'Precio:', precioNumerico);
                if (!cupon) {
                  showToast('Ingresa un cupón.', 'error');
                  return;
                }
                const cuponUserIdEl = document.getElementById('order-user-id');
                const cuponUserId = cuponUserIdEl ? cuponUserIdEl.value.trim() : '';
                fetch(buildAppUrl('/api/validar_cupon.php'), {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: `code=${encodeURIComponent(cupon)}&pack_price=${encodeURIComponent(precioNumerico)}&currency=${encodeURIComponent(effectivePack.moneda || '')}&game_id=${encodeURIComponent("<?= (string) ($game['id'] ?? '') ?>")}&user_identifier=${encodeURIComponent(cuponUserId)}`
                })
                .then(res => res.json())
                .then(data => {
                  console.log('Respuesta backend:', data);
                  if (data.success) {
                    couponApplied = true;
                    couponValue = cupon;
                    appliedCouponSummary = {
                      code: cupon,
                      discountAmount: normalizeCurrencyAmount(data.descuento, effectivePack.showDecimals),
                      originalAmount: normalizeCurrencyAmount(Number(data.nuevo_total || 0) + Number(data.descuento || 0), effectivePack.showDecimals),
                      discountType: String(data.tipo_descuento || ''),
                      discountValue: Number(data.valor_descuento || 0),
                    };
                    selectedTotalValue = normalizeCurrencyAmount(data.nuevo_total, effectivePack.showDecimals);
                    couponInput.disabled = true;
                    applyCouponButton.disabled = true;
                    if (cartMode) {
                      // Coupon discount was calculated on non-drop subtotal only.
                      // Add drop items at full price so cartTotalBlindado reflects the correct cart total.
                      let dropSubtotalAfterCoupon = 0;
                      cartItems.forEach(ci => {
                        if (Number(ci.pack.dropPercent || 0) > 0) {
                          const base = normalizeCurrencyAmount(parseFloat(ci.pack.priceValue || 0), ci.pack.showDecimals);
                          dropSubtotalAfterCoupon += normalizeCurrencyAmount(base * ci.quantity, ci.pack.showDecimals);
                        }
                      });
                      cartTotalBlindado = normalizeCurrencyAmount(selectedTotalValue + normalizeCurrencyAmount(dropSubtotalAfterCoupon, effectivePack.showDecimals), effectivePack.showDecimals);
                      syncFloatCartFab(); // actualiza el FAB con el total con descuento de inmediato
                      updateResumenCompraCart();
                      renderPublicPaymentMethodCatalog(cartItems.length > 0 ? cartItems[0].pack : null);
                    } else {
                      pack.purchaseQuantity = getOrderQuantity();
                      updateSelectedPriceDisplay(pack);
                      renderPublicOrderSummary(pack);
                    }
                    updateButtonState();
                    showToast(data.message + ` Descuento: ${formatCurrencyAmount(data.descuento, effectivePack.showDecimals)}`, 'success');
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

              // ── Ventana de comentarios personalizados (FullImpulso) ──────
              // Solo para paquetes cuyo servicio de FullImpulso es "Custom
              // Comments": antes de pasar a la ventana de términos, se le pide
              // al cliente escribir cada comentario en su propia línea — la
              // cantidad de líneas debe coincidir exactamente con la cantidad
              // fija del paquete.
              function fullimpulsoCommentsRequiredLines(pack) {
                return Math.max(0, Number((pack && pack.fullimpulsoCantidad) || 0));
              }

              function updateFullimpulsoCommentsValidation(pack) {
                const requiredLines = fullimpulsoCommentsRequiredLines(pack);
                const lines = (fullimpulsoCommentsTextarea ? fullimpulsoCommentsTextarea.value : '')
                  .split('\n').map(line => line.trim()).filter(line => line !== '');
                if (fullimpulsoCommentsCount) {
                  fullimpulsoCommentsCount.textContent = `${lines.length} de ${requiredLines} líneas`;
                }
                const valid = requiredLines > 0 && lines.length === requiredLines;
                if (fullimpulsoCommentsError) {
                  fullimpulsoCommentsError.textContent = (!valid && lines.length > 0)
                    ? `Debes escribir exactamente ${requiredLines} línea(s).`
                    : '';
                }
                if (fullimpulsoCommentsContinueBtn) {
                  fullimpulsoCommentsContinueBtn.disabled = !valid;
                }
                return { valid, lines };
              }

              // Paquete asociado a la ventana de comentarios actualmente abierta.
              // NO se usa "activePack" aquí: en el flujo de carrito múltiple se
              // pide un set de comentarios por cada paquete del carrito, uno a
              // la vez, y activePack no corresponde necesariamente al paquete
              // que se está pidiendo en ese momento.
              let fullimpulsoCommentsActivePack = null;

              function openFullimpulsoCommentsModal(pack, onContinue, onCancel) {
                if (!fullimpulsoCommentsModal || !fullimpulsoCommentsTextarea) {
                  onContinue('');
                  return;
                }
                fullimpulsoCommentsActivePack = pack;
                fullimpulsoCommentsTextarea.value = '';
                fullimpulsoCommentsOnContinue = onContinue;
                fullimpulsoCommentsOnCancel = onCancel || null;
                updateFullimpulsoCommentsValidation(pack);
                setOverlayVisible(fullimpulsoCommentsModal, true);
                window.setTimeout(() => fullimpulsoCommentsTextarea.focus(), 50);
              }

              if (fullimpulsoCommentsTextarea) {
                fullimpulsoCommentsTextarea.addEventListener('input', () => updateFullimpulsoCommentsValidation(fullimpulsoCommentsActivePack));
              }
              if (fullimpulsoCommentsContinueBtn) {
                fullimpulsoCommentsContinueBtn.addEventListener('click', () => {
                  const { valid, lines } = updateFullimpulsoCommentsValidation(fullimpulsoCommentsActivePack);
                  if (!valid) return;
                  setOverlayVisible(fullimpulsoCommentsModal, false);
                  const callback = fullimpulsoCommentsOnContinue;
                  fullimpulsoCommentsOnContinue = null;
                  fullimpulsoCommentsOnCancel = null;
                  if (typeof callback === 'function') callback(lines.join('\n'));
                });
              }
              if (fullimpulsoCommentsCancelBtn) {
                fullimpulsoCommentsCancelBtn.addEventListener('click', () => {
                  setOverlayVisible(fullimpulsoCommentsModal, false);
                  const cancelCallback = fullimpulsoCommentsOnCancel;
                  fullimpulsoCommentsOnContinue = null;
                  fullimpulsoCommentsOnCancel = null;
                  if (typeof cancelCallback === 'function') cancelCallback();
                });
              }

              function submitOrderCreationRequest(options = {}) {
                const btn = options.triggerButton instanceof HTMLElement ? options.triggerButton : buyButton;
                const couponVal = normalizeCouponCode(couponInput.value);
                couponInput.value = couponVal;
                const pack = options.pack || activePack;
                const userId = typeof options.forceUserId === 'string'
                  ? options.forceUserId.trim()
                  : (playerPrimaryInput ? playerPrimaryInput.value.trim() : '');
                if (userId) { defaultOrderUserIdentifier = userId; localStorage.setItem('rbs_player_id', userId); }

                const BLOCKED_PLAYER_IDS = <?= json_encode(blocked_players_get_all_ids(), JSON_UNESCAPED_UNICODE) ?>;
                if (userId && BLOCKED_PLAYER_IDS.includes(userId)) {
                  showPaymentStatusModal(
                    '⚠️ Advertencia de Actividades Ilícitas',
                    'Este ID de jugador ha sido suspendido temporalmente por actividades ilícitas. Si crees que es un error, comunícate con el administrador.',
                    'danger'
                  );
                  return;
                }
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

                if (pack.fullimpulsoCustomComments && typeof options.forceComments !== 'string') {
                  openFullimpulsoCommentsModal(pack, function(commentsText) {
                    submitOrderCreationRequest(Object.assign({}, options, { forceComments: commentsText }));
                  });
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
                  fullimpulso_comments: typeof options.forceComments === 'string' ? options.forceComments : '',
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
                  } else if (!handleBlockedUserResponse(data)) {
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
                if (cartMode && cartItems.length > 0) {
                  submitCartCheckout();
                  return;
                }
                submitOrderCreationRequest({ triggerButton: buyButton });
              });

              // ============================================================
              // MULTI-CART SYSTEM
              // ============================================================
              cartMode = true;
              cartItems = []; // [{pack, quantity}]
              cartTotalBlindado = null; // locked after user clicks "Continuar" from cart modal

              const multiCartCheck      = document.getElementById('multi-cart-check');
              const multiCartModal      = document.getElementById('multi-cart-modal');
              const multiCartModalBody  = document.getElementById('multi-cart-modal-body');
              const multiCartModalTotal = document.getElementById('multi-cart-modal-total');
              const multiCartProceed    = document.getElementById('multi-cart-proceed');
              const multiCartProceedLbl = document.getElementById('multi-cart-proceed-label');
              const multiCartKeepShop   = document.getElementById('multi-cart-keep-shopping');
              const multiCartModalClose = document.getElementById('multi-cart-modal-close');
              const batchProgressModal  = document.getElementById('batch-progress-modal');
              const batchProgressBar    = document.getElementById('batch-progress-bar');
              const batchProgressFrac   = document.getElementById('batch-progress-fraction');
              const batchProgressItems  = document.getElementById('batch-progress-items');
              const batchProgressFooter = document.getElementById('batch-progress-footer');
              const batchProgressLabel  = document.getElementById('batch-progress-current-label');
              const batchProgressClose  = document.getElementById('batch-progress-close');
              const paymentCartSummary  = document.getElementById('payment-cart-summary');
              const paymentCartSumList  = document.getElementById('payment-cart-summary-list');
              const paymentCartSumTotal = document.getElementById('payment-cart-summary-total');

              const headerCartBtn   = null; // movido a fab flotante
              const cartBtnCount    = null;

              // ── Floating corner cart FAB ─────────────────────────────────
              // El elemento se resuelve en cada llamada porque está después del script en el DOM
              function syncFloatCartFab() {
                const fab   = document.getElementById('float-cart-fab');
                const badge = document.getElementById('float-cart-fab-badge');
                if (!fab) return;
                if (!fab.dataset.cartReady) {
                  fab.dataset.cartReady = '1';
                  fab.addEventListener('click', () => openCartModal());
                }
                const count = cartItems.length;
                const shouldShow = cartMode;
                if (shouldShow) {
                  if (badge) badge.textContent = count > 0 ? String(count) : '';
                  if (badge) badge.style.display = count > 0 ? '' : 'none';
                  const label = document.getElementById('float-cart-fab-label');
                  if (label) label.textContent = count > 0 ? cartGrandTotalText() : '';
                  const stack = document.querySelector('.floating-social-stack');
                  if (stack && !stack.contains(fab)) stack.prepend(fab);
                  fab.style.display = '';
                } else {
                  fab.style.display = 'none';
                }
              }

              // ── Update cart button state ─────────────────────────────────
              function syncCartHeaderButton() { syncFloatCartFab(); }

              // ── Fly-to-cart animation ────────────────────────────────────
              function flyPackToCart(card) {
                const cardRect = card.getBoundingClientRect();
                const fab = document.getElementById('float-cart-fab');
                const ghost = document.createElement('div');
                ghost.className = 'fly-ghost';
                const img = card.querySelector('img');
                if (img) {
                  const gi = document.createElement('img');
                  gi.src = img.src;
                  ghost.appendChild(gi);
                } else {
                  ghost.style.background = 'linear-gradient(135deg,#6366f1,#22d3ee)';
                }
                const startX = cardRect.left + cardRect.width  / 2 - 28;
                const startY = cardRect.top  + cardRect.height / 2 - 28;
                ghost.style.left = startX + 'px';
                ghost.style.top  = startY + 'px';
                document.body.appendChild(ghost);
                const fabRect  = fab ? fab.getBoundingClientRect() : null;
                const targetX  = fabRect ? fabRect.left + fabRect.width  / 2 - 28 : window.innerWidth  - 60;
                const targetY  = fabRect ? fabRect.top  + fabRect.height / 2 - 28 : window.innerHeight - 60;
                const dx = targetX - startX;
                const dy = targetY - startY;
                const anim = ghost.animate([
                  { transform: 'translate(0,0) scale(1)',                                    opacity: 1   },
                  { transform: `translate(${dx*.35}px,${dy*.05}px) scale(.75)`,             opacity: .9, offset: .35 },
                  { transform: `translate(${dx}px,${dy}px) scale(.12)`,                     opacity: 0   }
                ], { duration: 580, easing: 'cubic-bezier(.25,.46,.45,.94)', fill: 'forwards' });
                anim.onfinish = () => {
                  ghost.remove();
                  if (fab) { fab.classList.remove('cart-fab-pop'); void fab.offsetWidth; fab.classList.add('cart-fab-pop'); }
                };
              }

              // ── Cart price calculation ───────────────────────────────────
              // priceValue ya viene con el drop aplicado desde updatePackPrices(); no reaplicar.
              function cartItemSubtotal(cartItem) {
                const base = normalizeCurrencyAmount(parseFloat(cartItem.pack.priceValue || 0), cartItem.pack.showDecimals);
                return normalizeCurrencyAmount(base * cartItem.quantity, cartItem.pack.showDecimals);
              }

              function cartGrandTotal() {
                if (cartTotalBlindado !== null) return cartTotalBlindado;
                const showDec = cartItems.length > 0 ? cartItems[0].pack.showDecimals : monedaActualMostrarDecimales;
                return normalizeCurrencyAmount(
                  cartItems.reduce((s, ci) => s + cartItemSubtotal(ci), 0),
                  showDec
                );
              }

              function cartGrandTotalText() {
                const total   = cartEffectiveTotal !== null ? cartEffectiveTotal : cartGrandTotal();
                const showDec = cartItems.length > 0 ? cartItems[0].pack.showDecimals : monedaActualMostrarDecimales;
                const moneda  = cartItems.length > 0 ? cartItems[0].pack.moneda : monedaActualClave;
                return `${moneda} ${formatCurrencyAmount(total, showDec)}`;
              }

              // ── Render cart modal body ───────────────────────────────────
              function renderCartModal() {
                if (!multiCartModalBody) return;
                if (cartItems.length === 0) {
                  multiCartModalBody.innerHTML = '<div class="multi-cart-empty-state">No hay paquetes en el carrito.</div>';
                  if (multiCartModalTotal) multiCartModalTotal.textContent = '-';
                  if (multiCartProceedLbl) multiCartProceedLbl.textContent = 'Continuar con la compra';
                  if (multiCartProceed) multiCartProceed.disabled = true;
                  return;
                }
                const html = cartItems.map((ci, idx) => {
                  const sub       = cartItemSubtotal(ci);
                  const showDec   = ci.pack.showDecimals;
                  const moneda    = ci.pack.moneda;
                  const isAccount = Boolean(ci.pack.accountSale);
                  const imgUrl    = String(ci.pack.imageUrl || '').trim();
                  const imgHtml   = imgUrl
                    ? `<img class="multi-cart-item-img" src="${escapePaymentHtml(imgUrl)}" alt="${escapePaymentHtml(ci.pack.name)}" loading="lazy">`
                    : `<div class="multi-cart-item-img-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18M9 21V9"/></svg></div>`;
                  const stepperHtml = isAccount
                    ? `<span class="multi-cart-item-qty cart-account-qty-fixed">×1</span>`
                    : `<div class="multi-cart-item-stepper">
                        <button type="button" class="cart-qty-dec" data-idx="${idx}" aria-label="Disminuir">-</button>
                        <span class="multi-cart-item-qty">${ci.quantity}</span>
                        <button type="button" class="cart-qty-inc" data-idx="${idx}" aria-label="Aumentar">+</button>
                      </div>`;
                  return `<div class="multi-cart-item" data-cart-idx="${idx}">
                    ${imgHtml}
                    <div>
                      <div class="multi-cart-item-name">${escapePaymentHtml(ci.pack.name)}</div>
                      <div class="multi-cart-item-sub">${moneda} ${formatCurrencyAmount(sub, showDec)}</div>
                    </div>
                    ${stepperHtml}
                    <button type="button" class="multi-cart-item-del" data-idx="${idx}" aria-label="Eliminar paquete">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                  </div>`;
                }).join('');
                multiCartModalBody.innerHTML = html;

                // Bind stepper and delete buttons
                multiCartModalBody.querySelectorAll('.cart-qty-dec').forEach(btn => {
                  btn.addEventListener('click', () => {
                    const idx = parseInt(btn.dataset.idx);
                    if (cartItems[idx] && cartItems[idx].quantity > 1) {
                      cartItems[idx].quantity--;
                      cartTotalBlindado = null;
                      renderCartModal();
                      syncCartHeaderButton();
                    }
                  });
                });
                multiCartModalBody.querySelectorAll('.cart-qty-inc').forEach(btn => {
                  btn.addEventListener('click', () => {
                    const idx = parseInt(btn.dataset.idx);
                    if (cartItems[idx]) {
                      cartItems[idx].quantity++;
                      cartTotalBlindado = null;
                      renderCartModal();
                      syncCartHeaderButton();
                    }
                  });
                });
                multiCartModalBody.querySelectorAll('.multi-cart-item-del').forEach(btn => {
                  btn.addEventListener('click', () => {
                    const idx = parseInt(btn.dataset.idx);
                    if (cartItems[idx]) {
                      removeCartItemByIndex(idx);
                    }
                  });
                });

                // Update total
                const total   = cartGrandTotal();
                const showDec = cartItems.length > 0 ? cartItems[0].pack.showDecimals : monedaActualMostrarDecimales;
                const moneda  = cartItems.length > 0 ? cartItems[0].pack.moneda : monedaActualClave;
                const txt     = `${moneda} ${formatCurrencyAmount(total, showDec)}`;
                if (multiCartModalTotal) multiCartModalTotal.textContent = txt;
                if (multiCartProceedLbl) {multiCartProceedLbl.textContent = `Continuar con la compra - ${txt}`;}
                if (multiCartProceed) multiCartProceed.disabled = cartItems.length === 0;
              }

              function openCartModal() {
                renderCartModal();
                setOverlayVisible(multiCartModal, true);
              }

              // ── Remove cart item ─────────────────────────────────────────
              function removeCartItemByIndex(idx) {
                if (!cartItems[idx]) return;
                const packId = cartItems[idx].pack.id;
                cartItems.splice(idx, 1);
                cartTotalBlindado = null;
                // Deselect the card on the page
                const card = document.querySelector(`.pack-card[data-package-id="${packId}"]`);
                if (card) card.classList.remove('neon-selected');
                renderCartModal();
                syncCartHeaderButton();
                updateResumenCompraCart();
                syncCartBuyButton();
              }

              // Si el chequeo de stock de pases bloquea paquetes que ya
              // estaban en el carrito, se retiran automáticamente.
              document.addEventListener('bs-pass-stock-blocked', (event) => {
                const blockedIds = new Set(((event.detail && event.detail.blockedIds) || []).map(String));
                if (!blockedIds.size || !cartItems.length) return;
                for (let i = cartItems.length - 1; i >= 0; i--) {
                  if (blockedIds.has(String(cartItems[i].pack.id))) {
                    removeCartItemByIndex(i);
                  }
                }
              });

              // ── Cart mode toggle ─────────────────────────────────────────
              if (multiCartCheck) {
                multiCartCheck.checked = true;
                multiCartCheck.addEventListener('change', () => {
                  cartMode = multiCartCheck.checked;
                  cartItems = [];
                  cartTotalBlindado = null;

                  // Reset all pack card selections
                  packCards2.forEach(card => card.classList.remove('neon-selected'));

                  // Reset account-sale disabled state
                  packCards2.forEach(card => {
                    card.classList.remove('cart-mode-account-disabled');
                    card.style.pointerEvents = '';
                  });

                  if (cartMode) {
                    // Hide single-pack summary widgets (quantity + selected pack)
                    if (purchaseSummaryLayout) purchaseSummaryLayout.classList.add('d-none');
                    // Reset single-pack state and hide summary until packs are selected
                    activePack = null;
                    renderPlayerFields(null);
                    updateButtonState();
                    publicOrderSummaryShell.classList.add('d-none');
                    syncCartHeaderButton(); // muestra el FAB al activar el modo carrito
                  } else {
                    // Restore single-pack summary widgets
                    if (purchaseSummaryLayout) purchaseSummaryLayout.classList.remove('d-none');
                    syncCartHeaderButton();
                    // activePack is null after leaving cart mode, so hide the summary shell
                    renderPublicOrderSummary(null);
                    updateButtonState();
                  }
                });
                // Activar modo carrito desde el inicio
                multiCartCheck.dispatchEvent(new Event('change'));
              }

              // ── Pack card click in cart mode ─────────────────────────────
              // Intercept pack clicks when cartMode is on
              packCards2.forEach(card => {
                card.addEventListener('click', function(e) {
                  if (!cartMode) return; // normal flow handles it
                  if (e.target.closest('[data-pack-preview-trigger]')) return; // let preview button open gallery modal
                  if (e.target.closest('.pack-info-btn')) return; // let the "i" button open its info modal
                  if (card.classList.contains('bs-pass-blocked') || card.classList.contains('levelpass-locked')) { e.stopImmediatePropagation(); return; } // pase sin stock / nivel no disponible

                  e.stopImmediatePropagation(); // prevent original handler

                  const packId   = card.dataset.packageId;
                  const existing = cartItems.findIndex(ci => ci.pack.id === packId);

                  if (existing >= 0) {
                    // Toggle off: remove from cart
                    cartItems.splice(existing, 1);
                    card.classList.remove('neon-selected');
                  } else {
                    // Add to cart
                    const pack = buildPackStateFromCard(card);
                    cartItems.push({ pack, quantity: 1 });
                    card.classList.add('neon-selected');
                  }

                  cartTotalBlindado = null;
                  syncCartHeaderButton(); // muestra el fab antes de animar
                  if (typeof updateResumenCompraCart === 'function') updateResumenCompraCart();
                  selectedTotalValue = cartItems.length > 0 ? cartGrandTotal() : 0;
                  renderPublicPaymentMethodCatalog(cartItems.length > 0 ? cartItems[0].pack : null);

                  // Animación al agregar cualquier paquete al carrito
                  if (existing < 0 && cartItems.length >= 1) flyPackToCart(card);
                }, true); // capture phase to intercept before original handler
              });

              // ── Public order summary in cart mode ────────────────────────
              updateResumenCompraCart = function() {
                if (!cartMode || !publicOrderSummaryShell || !publicOrderSummaryRows || !publicOrderSummaryTotal) return;

                if (cartItems.length === 0) {
                  publicOrderSummaryShell.classList.add('d-none');
                  if (publicOrderSummaryMethod) publicOrderSummaryMethod.classList.add('d-none');
                  return;
                }

                // Don't show the summary until the user has selected a payment method
                const cartRefPack = cartItems[0].pack;
                const cartSelection = resolvePreferredCheckoutSelection(cartRefPack);
                if (!cartSelection || !cartSelection.mode) {
                  publicOrderSummaryShell.classList.add('d-none');
                  if (publicOrderSummaryMethod) publicOrderSummaryMethod.classList.add('d-none');
                  return;
                }

                // Refresh pack state from card DOM so prices reflect current currency
                const prevMoneda = cartItems.length > 0 ? cartItems[0].pack.moneda : null;
                cartItems = cartItems.map(ci => {
                  const card = document.querySelector(`.pack-card[data-package-id="${ci.pack.id}"]`);
                  return card ? { ...ci, pack: buildPackStateFromCard(card) } : ci;
                });
                // If currency changed, invalidate the blindado lock (it was in the old currency)
                const newMoneda = cartItems.length > 0 ? cartItems[0].pack.moneda : null;
                if (prevMoneda && newMoneda && prevMoneda !== newMoneda) {
                  cartTotalBlindado = null;
                }

                // Points (RECoins) mode: show points pricing instead of money
                // Block if any cart item has no redemption rule (required points = 0)
                if (cartSelection.mode === 'points' && cartItems.every(ci => getPackRequiredPoints(ci.pack) > 0)) {
                  const totalRecoins = cartItems.reduce((sum, ci) => sum + getPackRequiredPoints(ci.pack, ci.quantity), 0);
                  const recoinsText = formatWinPointsAmount(totalRecoins);
                  const rowsHtmlPts = cartItems.map(ci => {
                    const packRecoins = getPackRequiredPoints(ci.pack, ci.quantity);
                    const qLabel = ci.quantity > 1 ? ` ×${ci.quantity}` : '';
                    return `<div class="payment-order-summary-row"><span class="payment-order-summary-row-label">${escapePaymentHtml(ci.pack.name)}${qLabel}</span><strong class="payment-order-summary-row-value">${escapePaymentHtml(formatWinPointsAmount(packRecoins))}</strong></div>`;
                  }).join('');
                  publicOrderSummaryRows.innerHTML = rowsHtmlPts;
                  publicOrderSummaryTotal.textContent = recoinsText;
                  publicCheckoutSummaryTotalText = recoinsText;
                  publicOrderSummaryShell.classList.remove('d-none');
                  restartPublicCheckoutSummaryAnimation(`cart:points:${recoinsText}:${cartItems.length}`);
                  if (publicOrderSummaryMethod) {
                    publicOrderSummaryMethod.textContent = String((typeof winPointsState !== 'undefined' && winPointsState.name) || 'Win Points');
                    publicOrderSummaryMethod.classList.remove('d-none');
                  }
                  syncCartBuyButton();
                  return;
                }

                // Build individual pack rows
                const showDec  = cartItems[0].pack.showDecimals;
                const moneda   = cartItems[0].pack.moneda;
                let rowsHtml = cartItems.map(ci => {
                  const sub    = cartItemSubtotal(ci);
                  const qLabel = ci.quantity > 1 ? ` ×${ci.quantity}` : '';
                  return `<div class="payment-order-summary-row"><span class="payment-order-summary-row-label">${escapePaymentHtml(ci.pack.name)}${qLabel}</span><strong class="payment-order-summary-row-value">${moneda} ${formatCurrencyAmount(sub, showDec)}</strong></div>`;
                }).join('');

                // Discount rows: coupon and/or payment method — drop items excluded from discounts
                const cartCouponActive = couponApplied && appliedCouponSummary && Number(appliedCouponSummary.discountAmount || 0) > 0;
                const cartPaymentDiscPct = resolvePaymentModeDiscountPercentage(cartSelection.mode,
                  cartSelection.mode === 'money' ? (cartSelection.methods && (cartSelection.methods.find(m => String(m.id) === String(cartSelection.methodId || '')) || cartSelection.methods[0])) : null
                );
                let effectiveCartTotal = cartGrandTotal(); // updated below if a discount applies
                if (cartCouponActive || cartPaymentDiscPct > 0) {
                  const itemsSum = normalizeCurrencyAmount(cartItems.reduce((s, ci) => s + cartItemSubtotal(ci), 0), showDec);
                  // Non-drop subtotal: base for payment method discount calculation
                  const nonDropSum = normalizeCurrencyAmount(cartItems.reduce((s, ci) => Number(ci.pack.dropPercent || 0) > 0 ? s : s + cartItemSubtotal(ci), 0), showDec);
                  // Fila de subtotal antes del descuento
                  rowsHtml += `<div class="payment-order-summary-row" style="border-top:1px solid rgba(34,211,238,.12);margin-top:0.3rem;padding-top:0.3rem;"><span class="payment-order-summary-row-label" style="color:#94a3b8;">Subtotal</span><strong class="payment-order-summary-row-value" style="color:#94a3b8;">${moneda} ${formatCurrencyAmount(itemsSum, showDec)}</strong></div>`;
                  const couponAmt = cartCouponActive ? normalizeCurrencyAmount(Number(appliedCouponSummary.discountAmount || 0), showDec) : 0;
                  // Payment discount applies to non-drop subtotal only
                  const paymentAmt = cartPaymentDiscPct > 0 ? normalizeCurrencyAmount((nonDropSum * cartPaymentDiscPct) / 100, showDec) : 0;
                  // Best discount wins
                  const couponWins = couponAmt >= paymentAmt;
                  if (couponWins && cartCouponActive) {
                    rowsHtml += `<div class="payment-order-summary-row"><span class="payment-order-summary-row-label">Cupón ${escapePaymentHtml(String(appliedCouponSummary.code || ''))}</span><strong class="payment-order-summary-row-value is-positive">-${moneda} ${formatCurrencyAmount(couponAmt, showDec)}</strong></div>`;
                    // couponWins: cartTotalBlindado already reflects the discounted total
                  } else if (!couponWins && cartPaymentDiscPct > 0) {
                    rowsHtml += `<div class="payment-order-summary-row"><span class="payment-order-summary-row-label">Descuento</span><strong class="payment-order-summary-row-value is-positive">${formatDiscountPercentage(cartPaymentDiscPct)}</strong></div>`;
                    rowsHtml += `<div class="payment-order-summary-row"><span class="payment-order-summary-row-label">Tu ahorro</span><strong class="payment-order-summary-row-value is-positive">${moneda} ${formatCurrencyAmount(paymentAmt, showDec)}</strong></div>`;
                    // Payment wins: deduct from full items sum
                    effectiveCartTotal = normalizeCurrencyAmount(itemsSum - paymentAmt, showDec);
                  }
                }

                cartEffectiveTotal = effectiveCartTotal;
                publicOrderSummaryRows.innerHTML = rowsHtml;
                const total    = effectiveCartTotal;
                const totalTxt = `${moneda} ${formatCurrencyAmount(total, showDec)}`;
                publicOrderSummaryTotal.textContent = totalTxt;
                publicOrderSummaryShell.classList.remove('d-none');
                restartPublicCheckoutSummaryAnimation(`cart:${totalTxt}:${cartItems.length}`);

                // Show payment method label badge (mirrors what renderPublicOrderSummary does)
                if (publicOrderSummaryMethod) {
                  const cartSelectedMethod = cartSelection.mode === 'money'
                    ? (cartSelection.methods && cartSelection.methods.find(m => String(m.id) === String(cartSelection.methodId || '')) || (cartSelection.methods && cartSelection.methods[0]) || null)
                    : null;
                  const cartMethodLabel = cartSelection.mode === 'binance' ? (String(typeof binancePayButtonLabel !== 'undefined' ? binancePayButtonLabel : '') || 'Binance Pay')
                    : cartSelection.mode === 'paypal' ? (String(typeof paypalPayButtonLabel !== 'undefined' ? paypalPayButtonLabel : '') || 'PayPal')
                    : cartSelection.mode === 'points' ? String((typeof winPointsState !== 'undefined' && winPointsState.name) || 'Win Points')
                    : String(cartSelectedMethod && cartSelectedMethod.nombre ? cartSelectedMethod.nombre : 'Método de pago');
                  publicOrderSummaryMethod.textContent = cartMethodLabel;
                  publicOrderSummaryMethod.classList.remove('d-none');
                }

                // Update buy button label
                if (buyButton) {
                  if (requiresVerifiedPlayerForCheckout()) {
                    buyButton.textContent = 'ID USUARIO INVÁLIDO';
                    buyButton.disabled = true;
                  } else {
                    buyButton.textContent = `Continuar con la compra - ${totalTxt}`;
                    buyButton.disabled = false;
                  }
                }
                syncFloatCartFab(); // refresca el total del FAB al cambiar método/cupón/moneda
              };

              function syncCartBuyButton() {
                if (!buyButton || !cartMode) return;
                const hasItems   = cartItems.length > 0;
                const cartSel    = resolvePreferredCheckoutSelection(cartItems[0] ? cartItems[0].pack : null);
                const pointsMode = cartSel.mode === 'points';
                const wp         = (typeof winPointsState !== 'undefined') ? winPointsState : {};
                // Block if any item has no rule OR total required points exceed balance
                const cartAllHaveRules = !hasItems || cartItems.every(ci => getPackRequiredPoints(ci.pack) > 0);
                const totalRequiredPoints = (hasItems && pointsMode)
                  ? cartItems.reduce((sum, ci) => sum + getPackRequiredPoints(ci.pack, ci.quantity), 0)
                  : 0;
                const hasEnoughBalance = !pointsMode || Number(wp.balance || 0) >= totalRequiredPoints;
                const pointsBlocked = pointsMode && (
                  !cartAllHaveRules
                  || !wp.loggedIn
                  || (wp.monthlyMinimumMet === false && !wp.isAdmin)
                  || !hasEnoughBalance
                );
                const requiredOk = window.__gameNoPlayerIdRequired || (() => {
                  const fields = Array.from(orderForm.querySelectorAll('[required]'));
                  return fields.every(f => f.value.trim() !== '');
                })();
                const needsPlayerVerificationCartSync = requiresVerifiedPlayerForCheckout();
                buyButton.disabled = !hasItems || !requiredOk || pointsBlocked || needsPlayerVerificationCartSync;
                if (needsPlayerVerificationCartSync) {
                  buyButton.textContent = 'ID USUARIO INVÁLIDO';
                } else if (pointsBlocked && !wp.loggedIn) {
                  buyButton.textContent = `Inicia sesión para usar ${wp.name || 'Puntos'}`;
                } else if (pointsBlocked && wp.monthlyMinimumMet === false && !wp.isAdmin) {
                  const minAmt = wp.monthlyMinimumRequired > 0 ? ` $${Number(wp.monthlyMinimumRequired).toFixed(2)}` : '';
                  buyButton.textContent = `Recarga mín.${minAmt} para usar ${wp.name || 'RECoins'}`;
                } else if (pointsBlocked) {
                  const needed = totalRequiredPoints;
                  const has = Number(wp.balance || 0);
                  buyButton.textContent = `${wp.name || 'RECoins'} insuficientes · tienes ${has.toLocaleString('en-US')}, necesitas ${needed.toLocaleString('en-US')}`;
                } else if (hasItems) {
                  const totalTxt = pointsMode
                    ? formatWinPointsAmount(cartItems.reduce((sum, ci) => sum + getPackRequiredPoints(ci.pack, ci.quantity), 0))
                    : cartGrandTotalText();
                  buyButton.textContent = `Continuar con la compra - ${totalTxt}`;
                } else {
                  buyButton.textContent = 'Selecciona al menos un paquete';
                }
              }

              // ── Cart modal buttons ───────────────────────────────────────
              if (multiCartKeepShop) {
                multiCartKeepShop.addEventListener('click', () => setOverlayVisible(multiCartModal, false));
              }
              if (multiCartModalClose) {
                multiCartModalClose.addEventListener('click', () => setOverlayVisible(multiCartModal, false));
              }
              if (multiCartProceed) {
                multiCartProceed.addEventListener('click', () => {
                  // Lock total (BLINDADO)
                  cartTotalBlindado = cartGrandTotal();
                  setOverlayVisible(multiCartModal, false);
                  updateResumenCompraCart();
                  syncCartBuyButton();
                  setTimeout(() => {
                    // Si ya hay método de pago seleccionado → scroll al resumen
                    const summaryShell = document.getElementById('public-order-summary-shell');
                    const hasPaymentMethod = summaryShell && !summaryShell.classList.contains('d-none');
                    if (hasPaymentMethod) {
                      summaryShell.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else {
                      // Sin método de pago → scroll al selector de métodos
                      const methodCatalog = document.querySelector('.payment-method-catalog-shell');
                      const target = methodCatalog || orderForm;
                      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                  }, 120);
                });
              }

              // ── Progress modal close ─────────────────────────────────────
              if (batchProgressClose) {
                batchProgressClose.addEventListener('click', () => {
                  setOverlayVisible(batchProgressModal, false);
                  resetCartState();
                });
              }

              // ── Admin API Debug Modal ────────────────────────────────────
              const adminDebugModal  = document.getElementById('admin-api-debug-modal');
              const adminDebugClose  = document.getElementById('admin-debug-modal-close');
              const adminDebugClose2 = document.getElementById('admin-debug-modal-close2');
              const adminDebugCopy   = document.getElementById('admin-debug-copy-btn');
              const adminDebugPre    = document.getElementById('admin-debug-json');
              if (adminDebugClose)  adminDebugClose.addEventListener('click',  () => { if (adminDebugModal) setOverlayVisible(adminDebugModal, false); });
              if (adminDebugClose2) adminDebugClose2.addEventListener('click', () => { if (adminDebugModal) setOverlayVisible(adminDebugModal, false); });
              if (adminDebugCopy) {
                adminDebugCopy.addEventListener('click', () => {
                  const text = adminDebugPre ? adminDebugPre.textContent : '';
                  navigator.clipboard.writeText(text).then(() => {
                    adminDebugCopy.textContent = '✓ Copiado';
                    setTimeout(() => { adminDebugCopy.textContent = 'Copiar JSON'; }, 2000);
                  }).catch(() => {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    ta.remove();
                    adminDebugCopy.textContent = '✓ Copiado';
                    setTimeout(() => { adminDebugCopy.textContent = 'Copiar JSON'; }, 2000);
                  });
                });
              }
              window.showAdminApiDebugModal = showAdminApiDebugModal;

              // ── Sync currency with cart ──────────────────────────────────
              // When currency changes in cart mode, recalculate cart prices
              const _origSetVisibleCurrency = setVisibleCurrency;
              // Hook: after currency changes, update cart display (total isn't blindado yet)
              const origMonedaSelectHandler = monedaSelect ? monedaSelect.onchange : null;
              if (monedaSelect) {
                monedaSelect.addEventListener('change', () => {
                  if (cartMode) {
                    // Always invalidate the blindado lock on currency change and refresh
                    cartTotalBlindado = null;
                    cartItems = cartItems.map(ci => {
                      const card = document.querySelector(`.pack-card[data-package-id="${ci.pack.id}"]`);
                      if (!card) return ci;
                      return { ...ci, pack: buildPackStateFromCard(card) };
                    });
                    renderCartModal();
                    updateResumenCompraCart();
                    syncCartBuyButton();
                  }
                });
              }


              // ── Reset cart state ─────────────────────────────────────────
              function resetCartState() {
                cartItems = [];
                cartTotalBlindado = null;
                cartEffectiveTotal = null;
                packCards2.forEach(card => card.classList.remove('neon-selected'));
                if (multiCartCheck) multiCartCheck.checked = true;
                cartMode = true;
                packCards2.forEach(card => card.classList.remove('cart-mode-account-disabled'));
                syncCartHeaderButton();
                updateResumenCompraCart();
                if (buyButton) {
                  buyButton.textContent = defaultBuyButtonLabel;
                  buyButton.disabled = true;
                }
                if (publicOrderSummaryShell) publicOrderSummaryShell.classList.add('d-none');
              }

              // Paquetes FullImpulso "Comentarios personalizados" dentro del
              // carrito: se piden uno a la vez (misma ventana que la compra
              // individual) ANTES de armar el payload del carrito. Si el
              // cliente cancela cualquiera, se aborta todo el checkout.
              function collectCartFullimpulsoComments() {
                const pending = cartItems
                  .map((ci, idx) => ({ ci, idx }))
                  .filter(({ ci }) => ci.pack && ci.pack.fullimpulsoCustomComments && typeof ci.fullimpulsoComments !== 'string');
                if (pending.length === 0) {
                  return Promise.resolve(true);
                }
                return new Promise((resolve) => {
                  let i = 0;
                  function next() {
                    if (i >= pending.length) {
                      resolve(true);
                      return;
                    }
                    const { ci, idx } = pending[i];
                    openFullimpulsoCommentsModal(ci.pack, (commentsText) => {
                      cartItems[idx].fullimpulsoComments = commentsText;
                      i += 1;
                      next();
                    }, () => resolve(false));
                  }
                  next();
                });
              }

              // ── Submit cart checkout ─────────────────────────────────────
              async function submitCartCheckout() {
                if (cartItems.length === 0) {
                  showToast('No hay paquetes en el carrito.', 'error');
                  return;
                }

                // Mismo chequeo que submitOrderCreationRequest(): si el ID de
                // jugador no está verificado, no se procede — antes esta
                // función no llamaba a requiresVerifiedPlayerForCheckout() en
                // absoluto, así que comprar por carrito con un ID inválido no
                // se bloqueaba (bug crítico reportado por el cliente).
                if (requiresVerifiedPlayerForCheckout()) {
                  setPlayerVerificationFeedback('danger', 'Debes verificar el nombre del jugador antes de comprar.');
                  return;
                }

                if (!(await collectCartFullimpulsoComments())) {
                  return;
                }

                const userId = playerPrimaryInput ? playerPrimaryInput.value.trim() : '';
                const email  = orderEmailInput ? orderEmailInput.value.trim() : '';

                if (!userId && !window.__gameNoPlayerIdRequired) { showToast('Debes ingresar tu ID de jugador.', 'error'); return; }

                /* ID de jugador bloqueado: mismo chequeo que la compra individual */
                const CART_BLOCKED_PLAYER_IDS = <?= json_encode(blocked_players_get_all_ids(), JSON_UNESCAPED_UNICODE) ?>;
                if (userId && CART_BLOCKED_PLAYER_IDS.includes(userId)) {
                  showPaymentStatusModal(
                    '⚠️ Advertencia de Actividades Ilícitas',
                    'Este ID de jugador ha sido suspendido temporalmente por actividades ilícitas. Si crees que es un error, comunícate con el administrador.',
                    'danger'
                  );
                  return;
                }

                // Use pack currency/showDecimals from first item
                const refPack      = cartItems[0].pack;
                const showDec      = refPack.showDecimals;
                const currency     = refPack.moneda;
                const total        = cartEffectiveTotal !== null ? cartEffectiveTotal : (cartTotalBlindado !== null ? cartTotalBlindado : cartGrandTotal());
                const totalText    = `${currency} ${formatCurrencyAmount(total, showDec)}`;
                const rawTotal     = normalizeCurrencyAmount(cartItems.reduce((s, ci) => s + cartItemSubtotal(ci), 0), showDec);
                const hasDiscount  = rawTotal > total && (couponApplied || cartEffectiveTotal !== null);
                const discountAmt  = hasDiscount ? normalizeCurrencyAmount(rawTotal - total, showDec) : 0;

                const paymentSelection = resolvePreferredCheckoutSelection(refPack);
                if (!paymentSelection.mode) {
                  showToast('Selecciona un método de pago antes de continuar.', 'error');
                  return;
                }

                const playerFields = collectPlayerFields();
                const playerFieldsJson = JSON.stringify(playerFields);

                // RECoins (points) mode: skip payment form entirely
                if (paymentSelection.mode === 'points') {
                  if (!paymentSelection.canUsePointsNow) {
                    const _wp = (typeof winPointsState !== 'undefined') ? winPointsState : {};
                    const _minAmtTxt = (_wp.monthlyMinimumRequired > 0) ? ` de $${Number(_wp.monthlyMinimumRequired).toFixed(2)}` : '';
                    const _msg = !_wp.loggedIn
                      ? `Inicia sesión para usar ${_wp.name || 'RECoins'}.`
                      : _wp.monthlyMinimumMet === false
                        ? `Necesitas recargar mínimo${_minAmtTxt} en los últimos 30 días para usar ${_wp.name || 'RECoins'}.`
                        : `No tienes suficientes ${_wp.name || 'RECoins'}.`;
                    showToast(_msg, 'error');
                    return;
                  }
                  const totalRecoins = cartItems.reduce((sum, ci) => sum + getPackRequiredPoints(ci.pack, ci.quantity), 0);
                  const recoinsText  = formatWinPointsAmount(totalRecoins);
                  const cartPayloadPts = cartItems.map(ci => ({
                    package_id: ci.pack.id,
                    quantity: ci.quantity,
                    price: normalizeCurrencyAmount(cartItemSubtotal(ci), ci.pack.showDecimals),
                    moneda: ci.pack.moneda,
                    fullimpulso_comments: typeof ci.fullimpulsoComments === 'string' ? ci.fullimpulsoComments : '',
                  }));
                  const cartPseudoOrderPts = {
                    orderId: '__cart__',
                    pack: refPack,
                    confirmedTotalText: recoinsText,
                    expiresAtMs: Date.now() + 30 * 60 * 1000,
                    email,
                    isCart: true,
                    isPoints: true,
                    cartPayload: cartPayloadPts,
                    cartTotal: 0,
                    currency,
                    userId,
                    playerFieldsJson,
                    hasDiscount: false,
                    paymentSelection,
                  };
                  openCartPaymentModal(cartPseudoOrderPts);
                  return;
                }

                // Build cart items array for backend
                const cartPayload = cartItems.map(ci => ({
                  package_id: ci.pack.id,
                  quantity: ci.quantity,
                  price: normalizeCurrencyAmount(cartItemSubtotal(ci), ci.pack.showDecimals),
                  moneda: ci.pack.moneda,
                  fullimpulso_comments: typeof ci.fullimpulsoComments === 'string' ? ci.fullimpulsoComments : '',
                }));

                // Collect payment data from the payment form (same as normal flow)
                // We open the payment modal first so user can enter payment data
                // For cart mode we fake an order_id = 0 and batch_id will be returned after payment data entry

                // Unlock a synthetic order context for the payment modal
                const cartPseudoOrder = {
                  orderId: '__cart__',
                  pack: refPack,
                  confirmedTotalText: totalText,
                  expiresAtMs: Date.now() + 30 * 60 * 1000,
                  email,
                  isCart: true,
                  cartPayload,
                  cartTotal: total,
                  currency,
                  userId,
                  playerFieldsJson,
                  hasDiscount,
                  rawTotalText: hasDiscount ? `${currency} ${formatCurrencyAmount(rawTotal, showDec)}` : '',
                  discountAmountText: hasDiscount ? `${currency} ${formatCurrencyAmount(discountAmt, showDec)}` : '',
                  couponCode: hasDiscount && couponApplied ? String(couponValue || '') : '',
                  paymentSelection,
                };
                openCartPaymentModal(cartPseudoOrder);
              }

              // ── Open payment modal in cart mode ──────────────────────────
              function openCartPaymentModal(ctx) {
                // RECoins mode: skip payment form, show pre-confirm then execute directly
                if (ctx.isPoints) {
                  if (preConfirmTosCheck) preConfirmTosCheck.checked = false;
                  if (preConfirmProceedBtn) {
                    preConfirmProceedBtn.disabled = true;
                    preConfirmProceedBtn.textContent = ctx.confirmedTotalText ? 'CONFIRMAR CANJE - ' + ctx.confirmedTotalText : 'CONFIRMAR CANJE';
                  }
                  pendingOpenModal = function() {
                    executeCartPointsPurchase(ctx);
                  };
                  setOverlayVisible(paymentPreConfirmModal, true);
                  if (paymentPreConfirmModal) paymentPreConfirmModal.scrollTop = 0;
                  return;
                }

                // Show cart summary in modal header
                if (paymentCartSumList && ctx.cartPayload) {
                  paymentCartSumList.innerHTML = ctx.cartPayload.map(item => {
                    const ci = cartItems.find(c => String(c.pack.id) === String(item.package_id));
                    const name = ci ? ci.pack.name : `Paquete #${item.package_id}`;
                    const qty  = item.quantity > 1 ? ` ×${item.quantity}` : '';
                    return `<li class="payment-cart-summary-item"><span>${escapePaymentHtml(name)}${qty}</span><span>${item.moneda} ${formatCurrencyAmount(item.price, ctx.pack.showDecimals)}</span></li>`;
                  }).join('');
                }
                if (paymentCartSumTotal) paymentCartSumTotal.textContent = ctx.confirmedTotalText;
                // Mostrar desglose de descuento si hay cupón aplicado
                const discSection   = document.getElementById('payment-cart-discount-section');
                const rawTotalEl    = document.getElementById('payment-cart-raw-total');
                const couponLabelEl = document.getElementById('payment-cart-coupon-label');
                const discAmtEl     = document.getElementById('payment-cart-discount-amount');
                const totalLabelEl  = document.getElementById('payment-cart-total-label');
                if (discSection) {
                  if (ctx.hasDiscount) {
                    if (rawTotalEl)    rawTotalEl.textContent    = ctx.rawTotalText;
                    if (couponLabelEl) couponLabelEl.textContent = ctx.couponCode ? `Cupón ${ctx.couponCode}` : 'Descuento';
                    if (discAmtEl)     discAmtEl.textContent     = `-${ctx.discountAmountText}`;
                    if (totalLabelEl)  totalLabelEl.textContent  = 'Total a pagar';
                    discSection.style.display = '';
                  } else {
                    discSection.style.display = 'none';
                    if (totalLabelEl) totalLabelEl.textContent = 'Total del carrito';
                  }
                }
                if (paymentCartSummary) paymentCartSummary.classList.add('is-visible');

                // Hide single-product summary, show cart summary
                const singleSummaryCard = document.querySelector('.payment-summary-card');
                if (singleSummaryCard) singleSummaryCard.style.display = 'none';

                // Update total display
                if (paymentSummaryTotal) paymentSummaryTotal.textContent = ctx.confirmedTotalText;
                if (paymentSummaryMinimalTotal) paymentSummaryMinimalTotal.textContent = ctx.confirmedTotalText;
                updatePaymentSummaryCopyButtons(ctx.confirmedTotalText);

                // Use the normal openPaymentModal infrastructure but with cart context
                activePaymentOrder = {
                  orderId: '__cart__',
                  pack: ctx.pack,
                  confirmedTotalText: ctx.confirmedTotalText,
                  expiresAtMs: ctx.expiresAtMs,
                  email: ctx.email,
                  isCart: true,
                  cartCtx: ctx,
                };

                // Reset payment modal state from any previous purchase
                clearPaymentSupportUi();
                setPaymentFormDisabled(false);
                setPaymentAlert('', 'info');
                if (paymentMoneyPanel) paymentMoneyPanel.classList.add('is-active');

                // Render los datos del método SELECCIONADO en el catálogo.
                // Antes siempre se mostraba el método manual de la moneda
                // (ej. Pago Móvil) aunque el cliente hubiera elegido Binance.
                const cartSelectedMode = normalizeCheckoutPaymentMode(
                  ctx.paymentSelection && ctx.paymentSelection.mode ? ctx.paymentSelection.mode : 'money'
                );
                activePaymentOrder.paymentMode = cartSelectedMode;
                activePaymentOrder.currency = ctx.currency || '';
                activePaymentOrder.baseAmount = Number(ctx.cartTotal || 0);
                if (cartSelectedMode === 'money') {
                  renderPaymentMethodsByCurrency(ctx.currency || '');
                } else {
                  renderPaymentMethodDetails(null, { mode: cartSelectedMode });
                  // El carrito captura la referencia en el formulario avanzado:
                  // reflejar ahí el placeholder/ayuda del modo elegido.
                  if (cartSelectedMode === 'binance_pagonorte' && paymentAdvReferenceInput) {
                    paymentAdvReferenceInput.placeholder = binancePagonorteReferencePlaceholder();
                    if (paymentAdvReferenceHelp) paymentAdvReferenceHelp.textContent = binancePagonorteReferenceHelpText();
                    const advRefLabel = document.getElementById('payment-adv-reference-label');
                    const bpDigits = Number(paymentReferenceInput && paymentReferenceInput.dataset.requiredDigits ? paymentReferenceInput.dataset.requiredDigits : 0);
                    if (advRefLabel) {
                      advRefLabel.textContent = bpDigits > 0
                        ? `Número de referencia (últimos ${bpDigits} dígitos)`
                        : 'Número de referencia del pago';
                    }
                    paymentAdvReferenceInput.dataset.requiredDigits = String(bpDigits > 0 ? bpDigits : 0);
                  }
                }

                // Set the submit button to handle cart flow
                if (paymentSubmitButton) {
                  paymentSubmitButton.textContent = `Realizar Compra - ${ctx.confirmedTotalText}`;
                  paymentSubmitButton.onclick = () => executeCartPurchase(ctx);
                }

                if (orderEmailInput && orderEmailInput.value.trim() === '') orderEmailInput.value = defaultOrderEmail || '';

                // Show T&C pre-confirm modal before opening payment modal
                pendingOpenModal = function() {
                  clearPaymentTimer();
                  updatePaymentTimer();
                  paymentTimerInterval = setInterval(updatePaymentTimer, 1000);
                  setOverlayVisible(paymentModal, true);
                  scrollPaymentModalToTop();
                  startBankMovementsAutoRefresh();
                };
                if (preConfirmTosCheck) preConfirmTosCheck.checked = false;
                if (preConfirmProceedBtn) {
                  preConfirmProceedBtn.disabled = true;
                  preConfirmProceedBtn.textContent = ctx.confirmedTotalText ? 'CONFIRMAR COMPRA - ' + ctx.confirmedTotalText : 'CONFIRMAR COMPRA';
                }
                setOverlayVisible(paymentPreConfirmModal, true);
                if (paymentPreConfirmModal) paymentPreConfirmModal.scrollTop = 0;
              }

              // ── Execute cart purchase (after user fills payment data) ─────
              async function executeCartPurchase(ctx) {
                if (!paymentSubmitButton) return;

                // Validate email in modal before proceeding
                const cartModalEmail = orderEmailInput ? orderEmailInput.value.trim() : '';
                if (!cartModalEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cartModalEmail)) {
                  setPaymentAlert('Debes ingresar un correo electrónico válido para recibir el comprobante.', 'danger');
                  if (orderEmailInput) orderEmailInput.focus();
                  return;
                }

                // Collect payment data from modal fields
                const refNumber   = paymentAdvReferenceInput ? paymentAdvReferenceInput.value.trim()
                                  : (paymentReferenceInput   ? paymentReferenceInput.value.trim() : '');
                const phoneVal    = paymentPhoneAdvInput      ? paymentPhoneAdvInput.value.trim()
                                  : (paymentPhoneInput        ? paymentPhoneInput.value.trim() : '');
                const nombreVal   = paymentNombreInput        ? paymentNombreInput.value.trim() : '';
                const cedulaVal   = paymentCedulaInput        ? paymentCedulaInput.value.trim() : '';

                const preferredSel = resolvePreferredCheckoutSelection(ctx.pack);
                const payMode      = preferredSel.mode || 'money';
                const payMethodId  = preferredSel.methodId || 0;

                // Validate reference minimum digits before hitting the API
                if (payMode === 'money') {
                  const _selMethod = preferredSel.methods.find((m) => String(m.id) === String(payMethodId));
                  const _reqDigits = Number(_selMethod && _selMethod.referencia_digitos ? _selMethod.referencia_digitos : 0);
                  if (_reqDigits > 0 && refNumber.length < _reqDigits) {
                    setPaymentAlert(`La referencia debe contener al menos ${_reqDigits} dígitos para este método de pago.`, 'danger');
                    return;
                  }
                }
                if (payMode === 'binance_pagonorte') {
                  const _bpDigits = Number(<?= (int) $binancePagonorteReferenceDigits ?> || 0);
                  if (_bpDigits > 0 && refNumber.length < _bpDigits) {
                    setPaymentAlert(`Debes escribir la referencia completa o al menos los últimos ${_bpDigits} dígitos.`, 'danger');
                    return;
                  }
                }

                // Gather coupon
                const couponVal = normalizeCouponCode(couponInput ? couponInput.value : '');

                paymentSubmitButton.disabled = true;
                setLoadingModalContent('Registrando pedidos...', 'Estamos creando los pedidos del carrito.', 'processing');
                setOverlayVisible(loadingModal, true);

                let batchId, orderIds;
                let _batchApiData = null;
                try {
                  const body = new URLSearchParams();
                  body.set('action', 'batch_create_and_pay');
                  body.set('game_id', String(<?= (int) ($game['id'] ?? 0) ?>));
                  body.set('cart_items_json', JSON.stringify(ctx.cartPayload));
                  body.set('user_identifier', ctx.userId || '');
                  body.set('player_fields_json', ctx.playerFieldsJson || '');
                  body.set('email', cartModalEmail || ctx.email || '');
                  body.set('currency', ctx.currency || '');
                  body.set('total_price', String(ctx.cartTotal));
                  body.set('payment_method_id', String(payMethodId));
                  body.set('payment_mode', payMode);
                  body.set('reference_number', refNumber);
                  body.set('phone', phoneVal);
                  body.set('nombre_titular', nombreVal);
                  body.set('cedula_titular', cedulaVal);
                  body.set('coupon', couponVal);

                  const resp = await fetch(buildAppUrl('/api/pedidos.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                  });
                  const data = await resp.json();
                  _batchApiData = data;

                  if (!data || !data.ok) {
                    if (handleBlockedUserResponse(data)) {
                      setOverlayVisible(loadingModal, false);
                      paymentSubmitButton.disabled = false;
                      return;
                    }
                    throw new Error((data && data.message) ? data.message : 'No se pudieron crear los pedidos.');
                  }

                  batchId  = data.batch_id;
                  orderIds = Array.isArray(data.order_ids) ? data.order_ids : [];

                  if (orderIds.length === 0) {
                    throw new Error('No se registraron pedidos en el carrito.');
                  }
                } catch (err) {
                  setOverlayVisible(loadingModal, false);
                  paymentSubmitButton.disabled = false;
                  const _batchErrMsg = normalizeApiRequestErrorMessage(err, 'Error al registrar los pedidos.');
                  console.error('[VG] error en batch_create_and_pay:', { message: _batchErrMsg, error: err, api_response: _batchApiData });
                  showToast(_batchErrMsg, 'error');
                  setPaymentAlert(_batchErrMsg, 'danger');
                  renderPaymentServerFailure(_batchErrMsg, refNumber, ctx.confirmedTotalText || '');
                  const adminDetail = _batchApiData && _batchApiData.admin_error_detail ? _batchApiData.admin_error_detail : null;
                  appendAdminDebugLink(paymentModalReasons, adminDetail);
                  return;
                }

                // Close loading and payment modal, open progress modal
                setOverlayVisible(loadingModal, false);
                setOverlayVisible(paymentModal, false);
                stopBankMovementsAutoRefresh();
                // Restore payment modal to normal state
                if (paymentCartSummary) paymentCartSummary.classList.remove('is-visible');
                const singleSummaryCard = document.querySelector('.payment-summary-card');
                if (singleSummaryCard) singleSummaryCard.style.display = '';
                if (paymentSubmitButton) paymentSubmitButton.onclick = null;

                // Open progress modal and process items
                await runBatchProgress(batchId, orderIds);
              }

              // ── Execute cart purchase with RECoins (no payment form) ─────
              async function executeCartPointsPurchase(ctx) {
                setLoadingModalContent('Procesando canje...', 'Estamos deduciendo tus RECoins y procesando los pedidos.', 'processing');
                setOverlayVisible(loadingModal, true);

                let batchId, orderIds;
                let _apiData = null;
                try {
                  const body = new URLSearchParams();
                  body.set('action', 'batch_create_and_pay');
                  body.set('game_id', String(<?= (int) ($game['id'] ?? 0) ?>));
                  body.set('cart_items_json', JSON.stringify(ctx.cartPayload));
                  body.set('user_identifier', ctx.userId || '');
                  body.set('player_fields_json', ctx.playerFieldsJson || '');
                  body.set('email', ctx.email || '');
                  body.set('currency', ctx.currency || '');
                  body.set('total_price', '0');
                  body.set('payment_method_id', '0');
                  body.set('payment_mode', 'points');
                  body.set('reference_number', '');
                  body.set('phone', '');
                  body.set('nombre_titular', '');
                  body.set('cedula_titular', '');
                  body.set('coupon', '');

                  const resp = await fetch(buildAppUrl('/api/pedidos.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                  });
                  const data = await resp.json();
                  _apiData = data;

                  if (!data || !data.ok) {
                    throw new Error((data && data.message) ? data.message : 'No se pudo completar el canje.');
                  }

                  batchId  = data.batch_id;
                  orderIds = Array.isArray(data.order_ids) ? data.order_ids : [];

                  if (orderIds.length === 0) {
                    throw new Error('No se registraron pedidos.');
                  }
                } catch (err) {
                  setOverlayVisible(loadingModal, false);
                  const errMsg = normalizeApiRequestErrorMessage(err, 'Error al procesar el canje.');
                  showToast(errMsg, 'error');
                  return;
                }

                setOverlayVisible(loadingModal, false);
                await runBatchProgress(batchId, orderIds);
              }

              // ── Run progress modal and fulfill items ─────────────────────
              async function runBatchProgress(batchId, orderIds) {
                if (!batchProgressModal || !batchProgressItems) return;

                const total = orderIds.length;

                // Build initial item list with pending state
                batchProgressItems.innerHTML = cartItems.slice(0, total).map((ci, i) => {
                  const qty = ci.quantity > 1 ? ` ×${ci.quantity}` : '';
                  return `<div class="batch-progress-item" id="batch-item-${i}">
                    <span class="batch-progress-item-icon batch-status-pending">⏳</span>
                    <span class="batch-progress-item-name">${escapePaymentHtml(ci.pack.name)}</span>
                    <span class="batch-progress-item-qty">${qty}</span>
                    <span class="batch-progress-item-status batch-status-pending">Pendiente</span>
                  </div>`;
                }).join('');

                if (batchProgressBar) batchProgressBar.style.width = '0%';
                if (batchProgressFrac) batchProgressFrac.textContent = `0/${total}`;
                if (batchProgressLabel) batchProgressLabel.textContent = 'Iniciando recargas...';
                if (batchProgressFooter) batchProgressFooter.style.display = 'none';

                setOverlayVisible(batchProgressModal, true);

                let doneCount = 0;
                let allSuccess = true;
                let manualReviewWhatsappUrl = '';
                const deliveredAccounts = [];
                const deliveredCodes = [];

                for (let i = 0; i < orderIds.length; i++) {
                  const orderId = orderIds[i];
                  const ci      = cartItems[i] || cartItems[0];
                  const row     = document.getElementById(`batch-item-${i}`);

                  if (batchProgressLabel) {
                    batchProgressLabel.textContent = `Procesando ${i + 1}/${total}: ${ci ? ci.pack.name : ''}`;
                  }
                  if (row) {
                    row.querySelector('.batch-progress-item-icon').textContent = '⚙️';
                    row.querySelector('.batch-progress-item-icon').className = 'batch-progress-item-icon batch-status-processing';
                    row.querySelector('.batch-progress-item-status').textContent = 'Procesando...';
                    row.querySelector('.batch-progress-item-status').className = 'batch-progress-item-status batch-status-processing';
                  }

                  let result = null;
                  let batchFulfillHttpStatus = null;
                  try {
                    const body = new URLSearchParams();
                    body.set('action', 'batch_fulfill_item');
                    body.set('order_id', String(orderId));
                    body.set('batch_id', batchId);
                    const resp = await fetch(buildAppUrl('/api/pedidos.php'), {
                      method: 'POST',
                      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                      body: body.toString(),
                    });
                    batchFulfillHttpStatus = resp.status;
                    result = await resp.json();
                  } catch (err) {
                    result = { ok: false, message: 'Error de red.', estado: 'pagado', client_error: String((err && err.message) || err) };
                  }

                  doneCount++;
                  const pct = Math.round((doneCount / total) * 100);
                  if (batchProgressBar)  batchProgressBar.style.width = `${pct}%`;
                  if (batchProgressFrac) batchProgressFrac.textContent = `${doneCount}/${total}`;

                  const isDone     = result && (result.estado === 'enviado' || result.already_done);
                  const isPartial  = result && result.estado === 'pagado' && result.processing;
                  const isManual   = result && result.manual;
                  const isError    = !result || (!isDone && !isPartial && !isManual);

                  if (isManual && result.whatsapp_url && !manualReviewWhatsappUrl) {
                    manualReviewWhatsappUrl = String(result.whatsapp_url);
                  }

                  if (isError) console.error('[VG] error en batch_fulfill_item order_id=' + orderId + ':', result);

                  if (!isDone && !isPartial) allSuccess = false;

                  if (row) {
                    let icon, statusText, statusClass;
                    if (isDone) {
                      icon = '✅'; statusText = 'Enviado'; statusClass = 'batch-status-done';
                    } else if (isPartial) {
                      icon = '🔄'; statusText = 'En proceso'; statusClass = 'batch-status-partial';
                    } else if (isManual) {
                      icon = '⏰'; statusText = 'Manual'; statusClass = 'batch-status-partial';
                    } else {
                      icon = '❌'; statusText = result && result.message ? result.message.slice(0,32) : 'Error'; statusClass = 'batch-status-error';
                    }
                    row.querySelector('.batch-progress-item-icon').textContent = icon;
                    row.querySelector('.batch-progress-item-icon').className = `batch-progress-item-icon ${statusClass}`;
                    row.querySelector('.batch-progress-item-status').textContent = statusText;
                    row.querySelector('.batch-progress-item-status').className = `batch-progress-item-status ${statusClass}`;

                    // Botón para copiar el detalle exacto del error (para
                    // enviárselo al administrador) — la recarga puede haberse
                    // hecho igual del lado del proveedor aunque esto muestre
                    // error; este JSON es lo que permite diagnosticarlo sin
                    // adivinar a partir de un mensaje genérico.
                    if (isError) {
                      const errorDetailPayload = {
                        order_id: orderId,
                        batch_id: batchId,
                        package: ci ? ci.pack.name : '',
                        http_status: batchFulfillHttpStatus,
                        response: result,
                        timestamp: new Date().toISOString(),
                      };
                      const errInfoIcon = document.createElement('span');
                      errInfoIcon.textContent = 'ⓘ';
                      errInfoIcon.className = 'batch-error-info-icon';
                      errInfoIcon.title = 'Enviar detalles del error al administrador';
                      errInfoIcon.style.cursor = 'pointer';
                      errInfoIcon.style.marginLeft = '6px';
                      errInfoIcon.addEventListener('click', async () => {
                        const json = JSON.stringify(errorDetailPayload, null, 2);
                        try {
                          const copied = await copyTextToClipboard(json);
                          showToast(copied ? 'Detalles del error copiados. Envíalos al administrador.' : 'No se pudo copiar los detalles.', copied ? 'success' : 'error');
                        } catch (_) {
                          showToast('No se pudo copiar los detalles.', 'error');
                        }
                      });
                      row.appendChild(errInfoIcon);
                    }

                    // If account sale, show credentials inline below the progress row
                    if (isDone && result && result.account_sale && result.account_sale.enabled) {
                      const asSale = getAccountSalePayload(result);
                      if (asSale) {
                        const accountText = asSale.accountText;
                        const gallery     = asSale.gallery;
                        const packName    = ci ? ci.pack.name : '';
                        deliveredAccounts.push({ name: packName, accountText, gallery });

                        const detailEl = document.createElement('div');
                        detailEl.className = 'batch-account-delivery';
                        if (accountText) {
                          detailEl.innerHTML = `<div class="batch-account-text">${escapePaymentHtml(accountText)}</div>
                            <button type="button" class="batch-account-copy-btn" data-copy-text="${escapePaymentHtml(accountText)}">Copiar datos</button>`;
                          const copyBtn = detailEl.querySelector('.batch-account-copy-btn');
                          if (copyBtn) {
                            copyBtn.addEventListener('click', async () => {
                              try {
                                const ok = await copyTextToClipboard(accountText);
                                showToast(ok ? 'Datos copiados.' : 'No se pudo copiar.', ok ? 'success' : 'error');
                              } catch (_) { showToast('No se pudo copiar.', 'error'); }
                            });
                          }
                        }
                        if (gallery.length) {
                          const galleryEl = document.createElement('div');
                          galleryEl.className = 'batch-account-gallery';
                          gallery.forEach(item => {
                            const img = document.createElement('img');
                            img.src = item.imageUrl;
                            img.alt = 'Imagen de la cuenta';
                            img.loading = 'lazy';
                            galleryEl.appendChild(img);
                          });
                          detailEl.appendChild(galleryEl);
                        }
                        row.appendChild(detailEl);
                      }
                    }

                    // If giftcard/voucher, show code(s) inline below the progress row
                    if (isDone && result && !(result.account_sale && result.account_sale.enabled)) {
                      const batchCodes = extractProviderCodes(result);
                      if (batchCodes.length > 0) {
                        const packName = ci ? ci.pack.name : '';
                        deliveredCodes.push({ name: packName, codes: batchCodes });

                        const codeEl = document.createElement('div');
                        codeEl.className = 'batch-account-delivery';
                        const codesText = batchCodes.join('\n');
                        const copyLabel = batchCodes.length > 1 ? 'Copiar códigos' : 'Copiar código';
                        codeEl.innerHTML = `<div class="batch-account-text">${escapePaymentHtml(codesText)}</div>
                          <button type="button" class="batch-account-copy-btn">${escapePaymentHtml(copyLabel)}</button>`;
                        const copyBtn = codeEl.querySelector('.batch-account-copy-btn');
                        if (copyBtn) {
                          copyBtn.addEventListener('click', async () => {
                            try {
                              const ok = await copyTextToClipboard(codesText);
                              showToast(ok ? 'Código copiado.' : 'No se pudo copiar.', ok ? 'success' : 'error');
                            } catch (_) { showToast('No se pudo copiar.', 'error'); }
                          });
                        }
                        row.appendChild(codeEl);
                      }
                    }
                  }

                  // 1-second delay between items (not after last one)
                  if (i < orderIds.length - 1) {
                    await new Promise(resolve => setTimeout(resolve, 1000));
                  }
                }

                // Done
                if (batchProgressLabel) {
                  batchProgressLabel.textContent = manualReviewWhatsappUrl
                    ? 'Tu pago debe ser verificado por el administrador antes de procesar la recarga.'
                    : (allSuccess
                      ? `¡Listo! ${doneCount} recarga${doneCount !== 1 ? 's' : ''} procesada${doneCount !== 1 ? 's' : ''} correctamente.`
                      : `Proceso completado. Revisa el estado de cada paquete.`);
                }

                // Show delivered accounts summary in footer if any
                if (batchProgressFooter) {
                  if (deliveredAccounts.length > 0) {
                    const accountsHtml = deliveredAccounts.map((acc, idx) => {
                      const galleryHtml = acc.gallery.length
                        ? `<div class="batch-account-gallery">${acc.gallery.map(item => `<img src="${escapePaymentHtml(item.imageUrl)}" alt="Imagen de la cuenta" loading="lazy">`).join('')}</div>`
                        : '';
                      const textHtml = acc.accountText
                        ? `<div class="batch-account-text">${escapePaymentHtml(acc.accountText)}</div>
                           <button type="button" class="batch-account-copy-btn" id="batch-footer-copy-${idx}">Copiar datos</button>`
                        : '';
                      return `<div class="batch-account-summary-item">
                        <div class="batch-account-summary-name">📦 ${escapePaymentHtml(acc.name)}</div>
                        ${textHtml}${galleryHtml}
                      </div>`;
                    }).join('');

                    const existingFooterContent = batchProgressFooter.querySelector('.batch-accounts-footer-list');
                    if (!existingFooterContent) {
                      const accountsBlock = document.createElement('div');
                      accountsBlock.className = 'batch-accounts-footer-list';
                      accountsBlock.innerHTML = `<div class="batch-accounts-footer-title">Cuentas entregadas</div>${accountsHtml}`;
                      batchProgressFooter.insertBefore(accountsBlock, batchProgressFooter.firstChild);

                      deliveredAccounts.forEach((acc, idx) => {
                        if (!acc.accountText) return;
                        const btn = batchProgressFooter.querySelector(`#batch-footer-copy-${idx}`);
                        if (btn) {
                          btn.addEventListener('click', async () => {
                            try {
                              const ok = await copyTextToClipboard(acc.accountText);
                              showToast(ok ? 'Datos copiados.' : 'No se pudo copiar.', ok ? 'success' : 'error');
                            } catch (_) { showToast('No se pudo copiar.', 'error'); }
                          });
                        }
                      });
                    }
                  }

                  // Show delivered giftcard/voucher codes summary in footer if any
                  if (deliveredCodes.length > 0) {
                    const codesHtml = deliveredCodes.map((entry, idx) => {
                      const codesText = entry.codes.join('\n');
                      const copyLabel = entry.codes.length > 1 ? 'Copiar códigos' : 'Copiar código';
                      return `<div class="batch-account-summary-item">
                        <div class="batch-account-summary-name">🎁 ${escapePaymentHtml(entry.name)}</div>
                        <div class="batch-account-text">${escapePaymentHtml(codesText)}</div>
                        <button type="button" class="batch-account-copy-btn" id="batch-footer-code-copy-${idx}">${escapePaymentHtml(copyLabel)}</button>
                      </div>`;
                    }).join('');

                    const existingCodesFooter = batchProgressFooter.querySelector('.batch-codes-footer-list');
                    if (!existingCodesFooter) {
                      const codesBlock = document.createElement('div');
                      codesBlock.className = 'batch-codes-footer-list batch-accounts-footer-list';
                      codesBlock.innerHTML = `<div class="batch-accounts-footer-title">Códigos entregados</div>${codesHtml}`;
                      batchProgressFooter.insertBefore(codesBlock, batchProgressFooter.firstChild);

                      deliveredCodes.forEach((entry, idx) => {
                        const btn = batchProgressFooter.querySelector(`#batch-footer-code-copy-${idx}`);
                        if (btn) {
                          const codesText = entry.codes.join('\n');
                          btn.addEventListener('click', async () => {
                            try {
                              const ok = await copyTextToClipboard(codesText);
                              showToast(ok ? 'Código copiado.' : 'No se pudo copiar.', ok ? 'success' : 'error');
                            } catch (_) { showToast('No se pudo copiar.', 'error'); }
                          });
                        }
                      });
                    }
                  }

                  // Pago con un método sin verificación automática (ej. Zinli): no se
                  // recargó nada, el pedido queda para que el admin lo verifique. Se le
                  // avisa al cliente y se le da el botón de WhatsApp para que envíe su
                  // comprobante — mismo criterio que "Enviar Comprobante al Admin" del
                  // checkout de un solo paquete.
                  if (manualReviewWhatsappUrl && !batchProgressFooter.querySelector('.batch-manual-review-notice')) {
                    const noticeBlock = document.createElement('div');
                    noticeBlock.className = 'batch-manual-review-notice batch-accounts-footer-list';
                    noticeBlock.innerHTML = `<div class="batch-accounts-footer-title">Verificación pendiente</div>
                      <p style="margin:0 0 0.75rem;color:#e2e8f0;">Tu pago todavía no ha sido verificado. Comunícate con el administrador por WhatsApp para agilizar la revisión.</p>
                      <button type="button" id="batch-manual-review-whatsapp-btn" class="btn fw-bold w-100" style="background:#25d366;color:#000;">
                        <i class="fa-brands fa-whatsapp me-2" aria-hidden="true"></i>Contactar al administrador
                      </button>`;
                    batchProgressFooter.insertBefore(noticeBlock, batchProgressFooter.firstChild);
                    const waBtn = document.getElementById('batch-manual-review-whatsapp-btn');
                    if (waBtn) {
                      waBtn.addEventListener('click', () => window.open(manualReviewWhatsappUrl, '_blank', 'noopener'));
                    }
                  }

                  batchProgressFooter.style.display = '';
                }
              }

              <?php if ($requestedPackageId > 0): ?>
              // Auto-select GGDrop package_id en modo carrito (el init del carrito borra neon-selected del código anterior)
              (function() {
                if (!cartMode) return;
                const autoCard = findPackCardById(<?= (int) $requestedPackageId ?>);
                if (!autoCard || autoCard.dataset.accountSale === '1') return;
                const autoPack = buildPackStateFromCard(autoCard);
                cartItems.push({ pack: autoPack, quantity: 1 });
                autoCard.classList.add('neon-selected');
                syncCartHeaderButton();
                if (typeof updateResumenCompraCart === 'function') updateResumenCompraCart();
                setTimeout(() => autoCard.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' }), 150);
              })();
              <?php endif; ?>

              </script>
            </section>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>AOS.init({duration:750,easing:'ease-out-cubic',once:true,offset:60});</script>
<?php
// Sistema de Comentarios: sección completa ("Lo que opinan de nosotros",
// panel de calificación + lista paginada + botón "Deja un comentario"),
// filtrada a reseñas de compras de ESTE juego — la vitrina general con solo
// destacados de todos los juegos vive en el home (index.php).
require_once __DIR__ . "/includes/comentarios_ui.php";
comentarios_render_seccion($mysqli, (int) ($game['id'] ?? 0));

include __DIR__ . "/includes/footer.php";
?>
<button type="button" id="float-cart-fab" class="floating-social-button float-cart-fab-btn" aria-label="Ver carrito" style="display:none;">
  <span class="floating-social-icon" aria-hidden="true" style="position:relative;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
    <span id="float-cart-fab-badge" class="float-cart-badge">0</span>
  </span>
  <span class="floating-social-label" id="float-cart-fab-label"></span>
</button>
<script>if (typeof syncFloatCartFab === 'function') syncFloatCartFab();</script>
<script>
/* ── Ventana de información del paquete (ícono "i") ─────────────────────── */
(function () {
  const modal = document.getElementById('pack-info-modal');
  if (!modal) return;
  const title = document.getElementById('pack-info-modal-title');
  const body = document.getElementById('pack-info-modal-body');
  const closeBtn = document.getElementById('pack-info-modal-close');

  function openInfoModal(btn) {
    if (title) title.textContent = btn.getAttribute('data-pack-info-title') || 'Información del paquete';
    /* El HTML viene sanitizado desde el servidor (solo formato del editor) */
    if (body) body.innerHTML = btn.getAttribute('data-pack-info') || '';
    modal.classList.add('is-visible');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeInfoModal() {
    modal.classList.remove('is-visible');
    modal.setAttribute('aria-hidden', 'true');
  }

  document.querySelectorAll('.pack-info-btn').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      /* No seleccionar el paquete al tocar la "i" */
      e.preventDefault();
      e.stopPropagation();
      openInfoModal(btn);
    });
    btn.addEventListener('keydown', (e) => { e.stopPropagation(); });
  });

  if (closeBtn) closeBtn.addEventListener('click', closeInfoModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeInfoModal(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-visible')) closeInfoModal();
  });
}());
</script>
