<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  require_once __DIR__ . '/includes/tenant.php';
  tenant_start_session();
}

require_once __DIR__ . '/includes/store_config.php';
require_once __DIR__ . '/includes/home_gallery.php';
require_once __DIR__ . '/includes/payment_methods.php';
require_once __DIR__ . '/includes/google_oauth.php';
require_once __DIR__ . '/includes/win_points.php';
require_once __DIR__ . '/includes/api_discord.php';

$cfg = store_config_all();
$paymentMethodDiscountsEnabled = trim((string) ($cfg['descuento_metodo_pago'] ?? store_config_get('descuento_metodo_pago', '0'))) === '1';
$activeTab = defined('ADMIN_CONFIG_ACTIVE_TAB') ? ADMIN_CONFIG_ACTIVE_TAB : ($_GET['tab'] ?? 'correo');
$startupPopupNormalEnabled = ($cfg['inicio_popup_activo'] ?? '1') === '1';
$startupPopupVideoEnabled = ($cfg['inicio_popup_video_activo'] ?? '0') === '1';
$startupPopupGalleryEnabled = ($cfg['inicio_popup_galeria'] ?? '0') === '1';
$startupPopupTabEnabled = store_config_get('inicio_popup_tab_habilitado', '1') === '1'
  || $startupPopupNormalEnabled
  || $startupPopupVideoEnabled
  || $startupPopupGalleryEnabled;
$rechargeNotificationsTabEnabled = ($cfg['notificaciones_recargas'] ?? '0') === '1';
$binanceApiTabEnabled = store_config_get('api_binance', '0') === '1';
$binancePagonorteTabEnabled = store_config_get('api_binance_pagonorte', '0') === '1';
$paypalTabEnabled = store_config_get('pago_paypal', '0') === '1';
$discordApiTabEnabled = store_config_get('api_discord', '0') === '1';
$allowedTabs = ['correo', 'cabecera', 'sociales', 'api-banco', 'api-free-fire', 'personalizar-colores', 'galeria', 'metodos-pago'];
if ($binanceApiTabEnabled) {
  $allowedTabs[] = 'api-binance';
}
if ($binancePagonorteTabEnabled) {
  $allowedTabs[] = 'verificacion-binance';
}
if ($paypalTabEnabled) {
  $allowedTabs[] = 'paypal';
}
if ($discordApiTabEnabled) {
  $allowedTabs[] = 'api-discord';
}
if ($rechargeNotificationsTabEnabled) {
  $allowedTabs[] = 'notificaciones-recargas';
}
if ($startupPopupTabEnabled) {
  $allowedTabs[] = 'ventana-inicial';
}
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'correo';
}

home_gallery_ensure_table();
payment_methods_ensure_table();
$logoTienda = trim((string) ($cfg['logo_tienda'] ?? ''));
$publicBackgroundSettings = store_config_public_background_settings();
$publicAnimatedBackgroundEnabled = !empty($publicBackgroundSettings['enabled']);
$publicBackgroundMode = $publicBackgroundSettings['mode'];
$publicBackgroundMedia = trim((string) ($publicBackgroundSettings['asset_path'] ?? ''));
$publicBackgroundHasMedia = !empty($publicBackgroundSettings['has_media']);
$publicBackgroundMediaType = trim((string) ($publicBackgroundSettings['media_type'] ?? ''));
$rechargeNotificationsLogo = trim((string) ($cfg['recarga_notificaciones_logo'] ?? ''));
$rechargeNotificationsEffectiveLogo = $rechargeNotificationsLogo !== '' ? $rechargeNotificationsLogo : $logoTienda;
$winPointsNotificationPosition = win_points_notification_position();
$winPointsNotificationPositions = win_points_notification_position_options();
$winPointsNotificationPreviewName = win_points_program_name();
$winPointsNotificationPreviewIcon = $rechargeNotificationsEffectiveLogo;
$winPointsEnabled = win_points_enabled();
$galleryItems = home_gallery_all();
$paymentCurrencies = payment_methods_currency_options();
$galleryEditId = isset($_GET['editar_galeria']) ? intval($_GET['editar_galeria']) : 0;
$galleryEditItem = $galleryEditId > 0 ? home_gallery_find($galleryEditId) : null;
$galleryForm = [
    'titulo' => $galleryEditItem['titulo'] ?? '',
    'descripcion1' => $galleryEditItem['descripcion1'] ?? '',
    'descripcion2' => $galleryEditItem['descripcion2'] ?? '',
    'url' => $galleryEditItem['url'] ?? '',
    'abrir_nueva_pestana' => !empty($galleryEditItem['abrir_nueva_pestana']),
    'destacado' => !empty($galleryEditItem['destacado']),
    'imagen' => $galleryEditItem['imagen'] ?? '',
];
  $paymentMethods = payment_methods_all();
  $paymentMethodEditId = isset($_GET['editar_metodo_pago']) ? intval($_GET['editar_metodo_pago']) : 0;
  $paymentMethodEditItem = $paymentMethodEditId > 0 ? payment_methods_find($paymentMethodEditId) : null;
  $paymentMethodForm = [
    'nombre' => $paymentMethodEditItem['nombre'] ?? '',
    'datos' => $paymentMethodEditItem['datos'] ?? '',
    'image_path' => $paymentMethodEditItem['image_path'] ?? '',
    'qr_image_path' => $paymentMethodEditItem['qr_image_path'] ?? '',
    'corner_image_path' => $paymentMethodEditItem['corner_image_path'] ?? '',
    'moneda_id' => isset($paymentMethodEditItem['moneda_id']) ? (int) $paymentMethodEditItem['moneda_id'] : 0,
    'referencia_digitos' => isset($paymentMethodEditItem['referencia_digitos']) ? max(0, (int) $paymentMethodEditItem['referencia_digitos']) : 0,
    'descuento_porcentaje' => $paymentMethodDiscountsEnabled && isset($paymentMethodEditItem['descuento_porcentaje']) ? (float) $paymentMethodEditItem['descuento_porcentaje'] : 0,
    'activo' => !array_key_exists('activo', $paymentMethodEditItem ?? []) ? true : !empty($paymentMethodEditItem['activo']),
  ];
$themeDefinitions = store_theme_definitions();
$themeBaseValues = store_theme_base_values();
$themeValues = store_theme_values();
$accountSaleFeatureEnabled = trim((string) ($cfg['vender_cuentas'] ?? store_config_get('vender_cuentas', '0'))) === '1';
$topBarFeatureEnabled = trim((string) ($cfg['barra_superior'] ?? store_config_get('barra_superior', '0'))) === '1';
$paymentHeaderMinimalEnabled = ($cfg['encabezado_pago'] ?? '0') === '1';
$paymentDifferenceConfigEnabled = ($cfg['diferencia_pago'] ?? '0') === '1';
$paymentWindowConfigEnabled = ($cfg['ventana_pago_config'] ?? '0') === '1';
$paymentWindowSendingTitle = trim((string) ($cfg['ventana_pago_enviando_titulo'] ?? 'Enviando orden...'));
if ($paymentWindowSendingTitle === '') {
  $paymentWindowSendingTitle = 'Enviando orden...';
}
$paymentWindowSendingMessage = trim((string) ($cfg['ventana_pago_enviando_mensaje'] ?? 'Estamos registrando tu comprobante y procesando la orden según la moneda del pedido. No cierres esta ventana.'));
if ($paymentWindowSendingMessage === '') {
  $paymentWindowSendingMessage = 'Estamos registrando tu comprobante y procesando la orden según la moneda del pedido. No cierres esta ventana.';
}
$paymentWindowSuccessTitle = trim((string) ($cfg['ventana_pago_exitoso_titulo'] ?? 'Pago exitoso'));
if ($paymentWindowSuccessTitle === '') {
  $paymentWindowSuccessTitle = 'Pago exitoso';
}
$paymentWindowSuccessExtraMessage = trim((string) ($cfg['ventana_pago_exitoso_mensaje_extra'] ?? ''));
$currentPublicUrl = rtrim(app_url('/'), '/');
$paymentWindowThemeGroups = [
  'Ventana de pago principal' => [
    'theme_payment_main_overlay_bg',
    'theme_payment_main_modal_bg',
    'theme_payment_main_modal_border',
    'theme_payment_main_title',
    'theme_payment_main_text',
    'theme_payment_main_timer_bg',
    'theme_payment_main_timer_border',
    'theme_payment_main_timer_text',
    'theme_payment_main_card_bg',
    'theme_payment_main_card_border',
    'theme_payment_main_input_bg',
    'theme_payment_main_input_border',
    'theme_payment_main_input_text',
    'theme_payment_main_button_bg',
    'theme_payment_main_button_text',
    'theme_payment_main_cancel_bg',
    'theme_payment_main_cancel_text',
  ],
  'Procesando pedido' => [
    'theme_payment_processing_overlay_bg',
    'theme_payment_processing_modal_bg',
    'theme_payment_processing_modal_border',
    'theme_payment_processing_spinner',
    'theme_payment_processing_title',
    'theme_payment_processing_text',
  ],
  'Enviando orden' => [
    'theme_payment_sending_overlay_bg',
    'theme_payment_sending_modal_bg',
    'theme_payment_sending_modal_border',
    'theme_payment_sending_spinner',
    'theme_payment_sending_title',
    'theme_payment_sending_text',
  ],
  'Pago exitoso y estado final' => [
    'theme_payment_status_overlay_bg',
    'theme_payment_status_modal_bg',
    'theme_payment_status_modal_border',
    'theme_payment_status_text',
    'theme_payment_status_title_info',
    'theme_payment_status_title_success',
    'theme_payment_status_title_danger',
    'theme_payment_status_button_bg',
    'theme_payment_status_button_text',
  ],
];
$paymentDifferenceThemeGroups = [
  'Diferencia de pagos' => [
    'theme_payment_difference_underpaid_card_bg',
    'theme_payment_difference_underpaid_text',
    'theme_payment_difference_underpaid_button_bg',
    'theme_payment_difference_underpaid_button_text',
    'theme_payment_difference_underpaid_button_hover_bg',
    'theme_payment_difference_underpaid_button_hover_text',
    'theme_payment_difference_overpaid_card_bg',
    'theme_payment_difference_overpaid_text',
    'theme_payment_difference_overpaid_button_bg',
    'theme_payment_difference_overpaid_button_text',
    'theme_payment_difference_overpaid_button_hover_bg',
    'theme_payment_difference_overpaid_button_hover_text',
  ],
];
$paymentWindowThemeKeys = [];
foreach ($paymentWindowThemeGroups as $groupKeys) {
  foreach ($groupKeys as $groupKey) {
    $paymentWindowThemeKeys[] = $groupKey;
  }
}
$paymentDifferenceThemeKeys = [];
foreach ($paymentDifferenceThemeGroups as $groupKeys) {
  foreach ($groupKeys as $groupKey) {
    $paymentDifferenceThemeKeys[] = $groupKey;
  }
}
$themeFieldGroups = [
  'Fondos y paneles' => ['theme_bg_main', 'theme_bg_alt', 'theme_surface', 'theme_surface_alt', 'theme_border'],
  'Neón y acciones' => ['theme_primary', 'theme_highlight', 'theme_secondary', 'theme_success'],
  'Botones y paquetes' => ['theme_button_primary', 'theme_button_secondary', 'theme_button_surface'],
  'Características de juegos' => ['theme_game_feature_bg', 'theme_game_feature_border', 'theme_game_feature_text'],
  'Botones flotantes' => ['theme_float_whatsapp_bg', 'theme_float_whatsapp_text', 'theme_float_channel_bg', 'theme_float_channel_text'],
  'Notificaciones de recargas' => ['theme_live_notification_bg', 'theme_live_notification_border', 'theme_live_notification_accent', 'theme_live_notification_text', 'theme_live_notification_muted'],
  'Ventana inicial' => ['theme_startup_popup_surface', 'theme_startup_popup_border', 'theme_startup_popup_accent', 'theme_startup_popup_chip', 'theme_startup_popup_button_text'],
  'Ventana inicial con video' => ['theme_startup_video_popup_surface', 'theme_startup_video_popup_border', 'theme_startup_video_popup_accent', 'theme_startup_video_popup_button_bg', 'theme_startup_video_popup_button_text'],
];
if ($accountSaleFeatureEnabled) {
  $themeFieldGroups['Botón -Ver Más- para vender cuentas'] = [
    'theme_account_preview_button_bg',
    'theme_account_preview_button_border',
    'theme_account_preview_button_text',
    'theme_account_preview_button_shadow',
  ];
}
if ($topBarFeatureEnabled) {
  $themeFieldGroups['Barra superior'] = [
    'theme_topbar_bg',
    'theme_topbar_text',
    'theme_topbar_search_border',
    'theme_topbar_search_bg',
    'theme_topbar_search_text',
    'theme_topbar_login_bg',
    'theme_topbar_login_border',
    'theme_topbar_login_text',
  ];
}
if ($paymentHeaderMinimalEnabled) {
  $themeFieldGroups['Características de paquetes'] = ['theme_package_feature_bg', 'theme_package_feature_border', 'theme_package_feature_text'];
}
if ($paymentWindowConfigEnabled) {
  foreach ($paymentWindowThemeGroups as $paymentWindowGroupTitle => $paymentWindowGroupKeys) {
    $themeFieldGroups[$paymentWindowGroupTitle] = $paymentWindowGroupKeys;
  }
}
if ($paymentDifferenceConfigEnabled) {
  foreach ($paymentDifferenceThemeGroups as $paymentDifferenceGroupTitle => $paymentDifferenceGroupKeys) {
    $themeFieldGroups[$paymentDifferenceGroupTitle] = $paymentDifferenceGroupKeys;
  }
}
$themeFieldGroups['Textos y estados'] = ['theme_text', 'theme_text_muted', 'theme_price_text', 'theme_price_muted', 'theme_warning', 'theme_danger'];
$startupPopupMode = trim((string) ($cfg['inicio_popup_modo'] ?? ''));
$startupPopupAvailableModes = ['none' => 'No mostrar ninguna ventana inicial'];
if ($startupPopupNormalEnabled) {
  $startupPopupAvailableModes['normal'] = 'Mostrar ventana inicial normal';
}
if ($startupPopupVideoEnabled) {
  $startupPopupAvailableModes['video'] = 'Mostrar ventana inicial con video';
}
if ($startupPopupGalleryEnabled) {
  $startupPopupAvailableModes['gallery'] = 'Mostrar ventana inicial de galería';
}
if (!array_key_exists($startupPopupMode, $startupPopupAvailableModes)) {
  if ($startupPopupVideoEnabled) {
    $startupPopupMode = 'video';
  } elseif ($startupPopupGalleryEnabled) {
    $startupPopupMode = 'gallery';
  } elseif ($startupPopupNormalEnabled) {
    $startupPopupMode = 'normal';
  } else {
    $startupPopupMode = 'none';
  }
}
$startupPopupVideoUrl = store_config_normalize_youtube_url((string) ($cfg['inicio_popup_video_url'] ?? ''));
$startupPopupChannelUrl = store_config_normalize_social_url((string) ($cfg['whatsapp_channel'] ?? ''));
$startupPopupChannelReady = store_config_is_valid_social_url($startupPopupChannelUrl);
$startupPopupGalleryImages = store_config_startup_popup_gallery_images((string) ($cfg['inicio_popup_galeria_imagenes'] ?? '[]'));
$googleCallbackUrl = google_oauth_callback_url();
$apiDiscordPriceCommands = api_discord_price_commands();
$apiDiscordSelectedProbeKey = trim((string) ($cfg['api_discord_probe_command'] ?? 'mobile_legends_price'));
$apiDiscordSelectedProbe = api_discord_find_command($apiDiscordSelectedProbeKey);
if (!$apiDiscordSelectedProbe && count($apiDiscordPriceCommands) > 0) {
  $apiDiscordSelectedProbe = $apiDiscordPriceCommands[0];
  $apiDiscordSelectedProbeKey = (string) ($apiDiscordSelectedProbe['key'] ?? '');
}
$apiDiscordSelectedProbeSample = $apiDiscordSelectedProbe ? api_discord_sample_command_text($apiDiscordSelectedProbe) : '';
$apiDiscordListenerToken = api_discord_normalize_listener_token((string) ($cfg['api_discord_listener_token'] ?? ''));
$apiDiscordListenerUrl = rtrim($currentPublicUrl, '/') . '/api/pedidos.php?action=discord_listener';
$apiDiscordListenerExampleToken = 'TU_TOKEN_DEL_LISTENER';
$paypalWebhookUrl = rtrim($currentPublicUrl, '/') . '/api/pedidos.php?action=paypal_webhook';
$paypalReturnUrl = rtrim($currentPublicUrl, '/') . '/api/pedidos.php?action=paypal_return';
$paypalCancelUrl = rtrim($currentPublicUrl, '/') . '/api/pedidos.php?action=paypal_cancel';
?>
<style>
  .neon-card {
    background: #181f2a !important;
    border-radius: 18px !important;
    border: 2px solid #00fff7 !important;
    box-shadow: 0 0 32px #00fff733, 0 0 8px #00fff7;
    color: #00fff7;
    font-family: 'Oxanium', 'Montserrat', 'Arial', sans-serif;
  }
  .neon-card .form-label,
  .neon-card .form-check-label,
  .neon-card .form-text,
  .neon-card .table,
  .neon-card .table td,
  .neon-card .table th {
    color: #c9f9ff !important;
  }
  .neon-card .form-control,
  .neon-card .form-select {
    background: #222c3a !important;
    color: #e9fdff !important;
    border: 1px solid #00fff7 !important;
    border-radius: 12px !important;
    box-shadow: 0 0 8px #00fff733;
  }
  .neon-card .form-control:focus,
  .neon-card .form-select:focus {
    border-color: #34d399 !important;
    box-shadow: 0 0 16px #34d39999;
    outline: none;
  }
  .neon-btn {
    background: linear-gradient(90deg, var(--theme-button-primary) 0%, var(--theme-button-secondary) 100%);
    color: var(--theme-button-text) !important;
    font-weight: bold;
    border-radius: 16px !important;
    box-shadow: 0 0 16px rgba(var(--theme-button-primary-rgb), 0.95), 0 0 32px rgba(var(--theme-button-secondary-rgb), 0.6);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border: none;
    transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
  }
  .neon-btn:hover {
    background: linear-gradient(90deg, var(--theme-button-secondary) 0%, var(--theme-button-primary) 100%);
    box-shadow: 0 0 32px rgba(var(--theme-button-primary-rgb), 0.95), 0 0 16px rgba(var(--theme-button-secondary-rgb), 0.6);
    transform: translateY(-1px);
  }
  .neon-tabs-wrap {
    border: 1px solid rgba(34, 211, 238, 0.22);
    border-radius: 20px;
    background: rgba(15, 23, 42, 0.72);
    box-shadow: inset 0 0 0 1px rgba(45, 212, 191, 0.08), 0 0 28px rgba(34, 211, 238, 0.08);
    padding: 0.5rem;
  }
  .neon-tabs-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
  }
  .neon-tabs-item {
    flex: 1 1 220px;
    min-width: 220px;
  }
  .neon-tab-link {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 52px;
    border: 1px solid rgba(34, 211, 238, 0.24);
    border-radius: 16px;
    background: rgba(15, 23, 42, 0.76);
    color: #9be7ff;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-decoration: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
  }
  .neon-tab-link:hover {
    color: #d8fbff;
    border-color: rgba(45, 212, 191, 0.58);
    box-shadow: 0 0 18px rgba(34, 211, 238, 0.14);
    transform: translateY(-1px);
  }
  .neon-tab-link.active {
    background: linear-gradient(135deg, rgba(34, 211, 238, 0.22), rgba(52, 211, 153, 0.12));
    color: #ffffff;
    border-color: rgba(34, 211, 238, 0.7);
    box-shadow: 0 0 18px rgba(34, 211, 238, 0.22), inset 0 0 12px rgba(34, 211, 238, 0.08);
  }
  .config-section-note {
    border-radius: 16px;
    border: 1px solid rgba(34, 211, 238, 0.2);
    background: rgba(15, 23, 42, 0.55);
    color: rgba(216, 251, 255, 0.82);
    padding: 1rem;
  }
  .header-logo-preview,
  .gallery-image-preview {
    width: 100%;
    border-radius: 18px;
    border: 1px solid rgba(34, 211, 238, 0.48);
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.9));
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 18px rgba(34, 211, 238, 0.16);
  }
  .header-logo-preview {
    max-width: 128px;
    aspect-ratio: 1 / 1;
  }
  .gallery-image-preview {
    aspect-ratio: 1280 / 500;
    max-width: none;
  }
  .header-logo-preview img,
  .gallery-image-preview img,
  .gallery-image-preview video {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
  }
  .header-logo-empty,
  .gallery-image-empty {
    color: rgba(155, 231, 255, 0.72);
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
  }
  .config-live-notification-card {
    margin-top: 1.5rem;
    border-radius: 20px;
    border: 1px solid rgba(var(--theme-primary-rgb), 0.24);
    background: linear-gradient(180deg, rgba(var(--theme-bg-alt-rgb), 0.84), rgba(var(--theme-surface-rgb), 0.72));
    box-shadow: 0 0 26px rgba(var(--theme-primary-rgb), 0.08);
    padding: 1.25rem;
  }
  .config-live-notification-header {
    display: grid;
    gap: 0.35rem;
    margin-bottom: 1rem;
  }
  .config-live-notification-title {
    margin: 0;
    color: var(--theme-highlight);
    font-family: 'Oxanium', 'Montserrat', 'Arial', sans-serif;
    font-size: 1.15rem;
    font-weight: 700;
  }
  .config-live-notification-copy {
    margin: 0;
    color: rgba(var(--theme-text-muted-rgb), 0.94);
  }
  .config-live-notification-actions {
    display: grid;
    gap: 0.75rem;
  }
  .config-live-notification-save-wrap {
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
  }
  .config-live-notification-save {
    width: min(100%, 220px);
    min-height: 52px;
    border-radius: 14px;
  }
  .config-live-notification-simulate {
    width: 100%;
    min-height: 58px;
    border: 1px solid rgba(var(--theme-border-rgb), 0.7);
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(var(--theme-button-primary-rgb), 0.98), rgba(var(--theme-button-secondary-rgb), 0.92));
    color: var(--theme-button-text-strong) !important;
    box-shadow: 0 16px 32px rgba(var(--theme-button-primary-rgb), 0.24), 0 0 0 1px rgba(var(--theme-primary-rgb), 0.22);
    transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
  }
  .config-live-notification-simulate:hover,
  .config-live-notification-simulate:focus {
    color: var(--theme-button-text-strong) !important;
    filter: brightness(1.04);
    transform: translateY(-1px);
    box-shadow: 0 20px 38px rgba(var(--theme-button-primary-rgb), 0.3), 0 0 0 1px rgba(var(--theme-highlight-rgb), 0.3);
  }
  .config-live-notification-help {
    margin: 0;
    color: var(--theme-text-muted);
    font-size: 0.88rem;
    line-height: 1.45;
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
    z-index: 1200;
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
  .win-points-live-notification.is-visible[data-position="middle-left"],
  .win-points-live-notification.is-visible[data-position="middle-right"] {
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
  .gallery-table-wrap {
    border: 1px solid rgba(34, 211, 238, 0.2);
    border-radius: 18px;
    background: rgba(15, 23, 42, 0.58);
    padding: 1rem;
    box-shadow: 0 0 24px rgba(34, 211, 238, 0.08);
  }
  .gallery-table-wrap .table {
    margin-bottom: 0;
    --bs-table-bg: transparent;
    --bs-table-striped-bg: rgba(34, 211, 238, 0.04);
    --bs-table-striped-color: #e9fdff;
    --bs-table-border-color: rgba(34, 211, 238, 0.15);
  }
  .gallery-thumb {
    width: 72px;
    height: 72px;
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid rgba(34, 211, 238, 0.42);
    box-shadow: 0 0 14px rgba(34, 211, 238, 0.16);
    background: #0f172a;
  }
  .gallery-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .payment-method-thumb {
    width: 88px;
    height: 56px;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid rgba(34, 211, 238, 0.36);
    background: rgba(15, 23, 42, 0.92);
    box-shadow: 0 0 14px rgba(34, 211, 238, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .payment-method-thumb img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
  }
  .payment-method-thumb.payment-method-thumb-qr {
    width: 64px;
    height: 64px;
  }
  .payment-method-thumb-stack {
    display: grid;
    gap: 0.45rem;
    justify-items: start;
  }
  .payment-method-thumb-caption {
    color: rgba(203, 213, 225, 0.78);
    font-size: 0.72rem;
    line-height: 1.2;
  }
  .payment-method-mobile-previews {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.75rem;
  }
  .gallery-card-mobile {
    border-radius: 18px;
    border: 1px solid rgba(34, 211, 238, 0.28);
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.92), rgba(30, 41, 59, 0.78));
    box-shadow: 0 0 22px rgba(34, 211, 238, 0.08);
    padding: 1rem;
  }
  .gallery-badge-neon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid rgba(34, 211, 238, 0.5);
    padding: 0.25rem 0.6rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: #9be7ff;
    background: rgba(34, 211, 238, 0.08);
  }
  .startup-popup-gallery-manager {
    display: grid;
    gap: 0.85rem;
  }
  .startup-popup-gallery-upload-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
  }
  .startup-popup-gallery-upload-btn {
    min-width: min(100%, 240px);
    padding-inline: 1.25rem;
  }
  .startup-popup-gallery-status {
    color: rgba(216, 251, 255, 0.78);
    font-size: 0.82rem;
    line-height: 1.4;
  }
  .startup-popup-gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 0.9rem;
  }
  .startup-popup-gallery-card {
    position: relative;
    border-radius: 18px;
    border: 1px solid rgba(34, 211, 238, 0.24);
    background: rgba(15, 23, 42, 0.52);
    padding: 0.55rem;
    box-shadow: 0 0 18px rgba(34, 211, 238, 0.08);
  }
  .startup-popup-gallery-thumb {
    position: relative;
    aspect-ratio: 4 / 5;
    overflow: hidden;
    border-radius: 14px;
    background: rgba(15, 23, 42, 0.7);
  }
  .startup-popup-gallery-thumb img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
  }
  .startup-popup-gallery-delete {
    position: absolute;
    top: 0.55rem;
    right: 0.55rem;
    z-index: 2;
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 999px;
    background: rgba(185, 28, 28, 0.92);
    color: #fff;
    font-size: 1rem;
    font-weight: 800;
    line-height: 1;
    box-shadow: 0 10px 18px rgba(127, 29, 29, 0.24);
  }
  .startup-popup-gallery-delete:hover {
    background: rgba(220, 38, 38, 0.96);
    color: #fff;
  }
  .startup-popup-gallery-meta {
    display: grid;
    gap: 0.45rem;
    margin-top: 0.6rem;
  }
  .startup-popup-gallery-name {
    color: #d8fbff;
    font-size: 0.76rem;
    line-height: 1.3;
    word-break: break-word;
    min-height: 2rem;
  }
  .startup-popup-gallery-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
  }
  .startup-popup-gallery-order {
    color: #9be7ff;
    font-size: 0.74rem;
    font-weight: 700;
  }
  .startup-popup-gallery-move-group {
    display: inline-flex;
    gap: 0.35rem;
  }
  .startup-popup-gallery-move {
    min-width: 34px;
    height: 34px;
    border: 1px solid rgba(34, 211, 238, 0.28);
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.8);
    color: #d8fbff;
    font-size: 0.92rem;
    font-weight: 700;
    line-height: 1;
  }
  .startup-popup-gallery-move:hover {
    background: rgba(34, 211, 238, 0.12);
    color: #ffffff;
  }
  .startup-popup-gallery-link-fields {
    display: grid;
    gap: 0.45rem;
  }
  .startup-popup-gallery-link-input,
  .startup-popup-gallery-target-select {
    min-height: 36px;
    font-size: 0.78rem;
  }
  .startup-popup-gallery-save {
    width: 100%;
    min-height: 36px;
    border: 1px solid rgba(34, 211, 238, 0.28);
    border-radius: 10px;
    background: rgba(8, 145, 178, 0.18);
    color: #d8fbff;
    font-size: 0.78rem;
    font-weight: 700;
  }
  .startup-popup-gallery-save:hover {
    background: rgba(34, 211, 238, 0.18);
    color: #ffffff;
  }
  .startup-popup-gallery-link-hint {
    color: rgba(216, 251, 255, 0.68);
    font-size: 0.72rem;
    line-height: 1.35;
  }
  .startup-popup-gallery-empty {
    border: 1px dashed rgba(34, 211, 238, 0.24);
    border-radius: 18px;
    padding: 1rem;
    color: rgba(216, 251, 255, 0.68);
    text-align: center;
    font-size: 0.82rem;
  }
  .theme-swatch-card {
    height: 100%;
    border-radius: 18px;
    border: 1px solid rgba(var(--theme-primary-rgb), 0.2);
    background: rgba(var(--theme-bg-alt-rgb), 0.58);
    padding: 1rem;
    box-shadow: 0 0 20px rgba(var(--theme-primary-rgb), 0.08);
  }
  .theme-swatch-preview {
    width: 100%;
    height: 4.5rem;
    border-radius: 14px;
    border: 1px solid rgba(var(--theme-text-rgb), 0.14);
    box-shadow: inset 0 0 0 1px rgba(var(--theme-text-rgb), 0.04);
  }
  .theme-swatch-card .form-control-color {
    width: 100%;
    height: 3rem;
    padding: 0.25rem;
    border-radius: 12px;
  }
  .theme-group-title {
    color: var(--theme-highlight);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-size: 0.85rem;
    font-weight: 700;
  }
  .theme-accordion {
    display: grid;
    gap: 0.9rem;
  }
  .theme-accordion-item {
    border-radius: 18px;
    border: 1px solid rgba(var(--theme-primary-rgb), 0.2);
    background: rgba(var(--theme-bg-alt-rgb), 0.42);
    box-shadow: 0 0 20px rgba(var(--theme-primary-rgb), 0.06);
    overflow: hidden;
  }
  .theme-accordion-trigger {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.15rem;
    border: 0;
    background: linear-gradient(180deg, rgba(var(--theme-bg-alt-rgb), 0.84), rgba(var(--theme-surface-rgb), 0.72));
    color: var(--theme-text);
    text-align: left;
  }
  .theme-accordion-trigger:hover {
    background: linear-gradient(180deg, rgba(var(--theme-bg-alt-rgb), 0.94), rgba(var(--theme-surface-rgb), 0.82));
  }
  .theme-accordion-trigger:focus-visible {
    outline: 2px solid rgba(var(--theme-primary-rgb), 0.88);
    outline-offset: -2px;
  }
  .theme-accordion-trigger::after {
    content: '';
    flex: 0 0 auto;
    width: 0.72rem;
    height: 0.72rem;
    border-right: 2px solid rgba(var(--theme-primary-rgb), 0.92);
    border-bottom: 2px solid rgba(var(--theme-primary-rgb), 0.92);
    transform: rotate(45deg);
    transition: transform 0.18s ease;
    margin-right: 0.2rem;
  }
  .theme-accordion-item.is-open .theme-accordion-trigger::after {
    transform: rotate(-135deg);
    margin-top: 0.3rem;
  }
  .theme-accordion-label {
    display: flex;
    flex-direction: column;
    gap: 0.18rem;
    min-width: 0;
  }
  .theme-accordion-title {
    color: var(--theme-highlight);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-size: 0.85rem;
    font-weight: 700;
  }
  .theme-accordion-copy {
    color: rgba(var(--theme-text-muted-rgb), 0.92);
    font-size: 0.8rem;
  }
  .theme-accordion-panel {
    display: none;
    padding: 0 1.15rem 1.15rem;
  }
  .theme-accordion-item.is-open .theme-accordion-panel {
    display: block;
  }
  .theme-default-note {
    color: rgba(var(--theme-text-muted-rgb), 0.92);
    font-size: 0.84rem;
  }
  .theme-action-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 1.5rem;
  }
  .theme-action-row > * {
    flex: 1 1 260px;
  }
  .theme-reset-btn {
    border-radius: 16px !important;
    min-height: 60px;
    border: 1px solid rgba(var(--theme-warning-rgb), 0.5) !important;
    background: rgba(var(--theme-warning-rgb), 0.12) !important;
    color: var(--theme-text) !important;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    box-shadow: 0 0 16px rgba(var(--theme-warning-rgb), 0.16);
  }
  .theme-reset-btn:hover {
    background: rgba(var(--theme-warning-rgb), 0.2) !important;
    border-color: rgba(var(--theme-warning-rgb), 0.72) !important;
  }
  .neon-card {
    background: var(--theme-surface-alt) !important;
    border-color: var(--theme-highlight) !important;
    box-shadow: 0 0 32px rgba(var(--theme-highlight-rgb), 0.2), 0 0 8px rgba(var(--theme-highlight-rgb), 0.95);
    color: var(--theme-highlight);
  }
  .neon-card .form-control,
  .neon-card .form-select {
    background: rgba(var(--theme-bg-alt-rgb), 0.92) !important;
    color: var(--theme-text) !important;
    border-color: var(--theme-highlight) !important;
    box-shadow: 0 0 8px rgba(var(--theme-highlight-rgb), 0.2);
  }
  .neon-card .form-control:focus,
  .neon-card .form-select:focus {
    border-color: var(--theme-success) !important;
    box-shadow: 0 0 16px rgba(var(--theme-success-rgb), 0.6);
  }
  .neon-tabs-wrap,
  .config-section-note,
  .gallery-table-wrap,
  .gallery-card-mobile,
  .header-logo-preview,
  .gallery-image-preview,
  .gallery-thumb,
  .gallery-badge-neon,
  .neon-tab-link,
  .neon-tab-link.active,
  .neon-tab-link:hover {
    border-color: rgba(var(--theme-primary-rgb), 0.24) !important;
  }
  @media (max-width: 575.98px) {
    .neon-tabs-item {
      min-width: 100%;
    }
    .config-live-notification-save {
      width: 100%;
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
</style>
<div class="container mt-5 mb-5">
  <div class="row justify-content-center">
    <div class="col-lg-10 col-xl-9">
      <div class="neon-tabs-wrap mb-4">
        <div class="neon-tabs-grid">
          <div class="neon-tabs-item">
            <a href="/admin/configuracion?tab=correo" class="neon-tab-link <?= $activeTab === 'correo' ? 'active' : '' ?>">Configuración de correo</a>
          </div>
          <div class="neon-tabs-item">
            <a href="/admin/configuracion?tab=cabecera" class="neon-tab-link <?= $activeTab === 'cabecera' ? 'active' : '' ?>">Datos de cabecera</a>
          </div>
          <?php if ($rechargeNotificationsTabEnabled): ?>
            <div class="neon-tabs-item">
              <a href="/admin/configuracion?tab=notificaciones-recargas" class="neon-tab-link <?= $activeTab === 'notificaciones-recargas' ? 'active' : '' ?>">Notificaciones Recargas</a>
            </div>
          <?php endif; ?>
          <div class="neon-tabs-item">
            <a href="/admin/configuracion?tab=sociales" class="neon-tab-link <?= $activeTab === 'sociales' ? 'active' : '' ?>">Redes Sociales</a>
          </div>
          <div class="neon-tabs-item">
            <a href="/admin/configuracion?tab=api-banco" class="neon-tab-link <?= $activeTab === 'api-banco' ? 'active' : '' ?>">Datos conexión Banco</a>
          </div>
          <div class="neon-tabs-item">
            <a href="/admin/configuracion?tab=api-free-fire" class="neon-tab-link <?= $activeTab === 'api-free-fire' ? 'active' : '' ?>">Datos API</a>
          </div>
          <?php if ($binanceApiTabEnabled): ?>
            <div class="neon-tabs-item">
              <a href="/admin/configuracion?tab=api-binance" class="neon-tab-link <?= $activeTab === 'api-binance' ? 'active' : '' ?>">API Binance Pay</a>
            </div>
          <?php endif; ?>
          <?php if ($binancePagonorteTabEnabled): ?>
            <div class="neon-tabs-item">
              <a href="/admin/configuracion?tab=verificacion-binance" class="neon-tab-link <?= $activeTab === 'verificacion-binance' ? 'active' : '' ?>">Verificación Binance</a>
            </div>
          <?php endif; ?>
            <?php if ($paypalTabEnabled): ?>
            <div class="neon-tabs-item">
              <a href="/admin/configuracion?tab=paypal" class="neon-tab-link <?= $activeTab === 'paypal' ? 'active' : '' ?>">Paypal</a>
            </div>
            <?php endif; ?>
          <?php if ($discordApiTabEnabled): ?>
            <div class="neon-tabs-item">
              <a href="/admin/configuracion?tab=api-discord" class="neon-tab-link <?= $activeTab === 'api-discord' ? 'active' : '' ?>">API Discord</a>
            </div>
          <?php endif; ?>
          <div class="neon-tabs-item">
            <a href="/admin/configuracion?tab=personalizar-colores" class="neon-tab-link <?= $activeTab === 'personalizar-colores' ? 'active' : '' ?>">Personalizar Colores</a>
          </div>
          <?php if ($startupPopupTabEnabled): ?>
            <div class="neon-tabs-item">
              <a href="/admin/configuracion?tab=ventana-inicial" class="neon-tab-link <?= $activeTab === 'ventana-inicial' ? 'active' : '' ?>">Ventana Inicial</a>
            </div>
          <?php endif; ?>
          <div class="neon-tabs-item">
            <a href="/admin/configuracion?tab=galeria" class="neon-tab-link <?= $activeTab === 'galeria' ? 'active' : '' ?>">Galería</a>
          </div>
          <div class="neon-tabs-item">
            <a href="/admin/configuracion?tab=metodos-pago" class="neon-tab-link <?= $activeTab === 'metodos-pago' ? 'active' : '' ?>">Métodos de Pago</a>
          </div>
        </div>
      </div>

      <div class="card neon-card mb-4">
        <div class="card-header text-center py-4" style="background: linear-gradient(90deg, var(--theme-highlight) 0%, var(--theme-success) 100%); color: var(--theme-button-text-strong); border-radius: 16px 16px 0 0;">
          <h2 class="h4 fw-bold mb-0" style="font-family: 'Oxanium', 'Montserrat', 'Arial', sans-serif; letter-spacing: 0.08em;">
            <?php if ($activeTab === 'correo'): ?>Configuración de correo corporativo<?php elseif ($activeTab === 'cabecera'): ?>Datos de cabecera<?php elseif ($activeTab === 'notificaciones-recargas'): ?>Notificaciones Recargas<?php elseif ($activeTab === 'sociales'): ?>Redes Sociales<?php elseif ($activeTab === 'api-banco'): ?>Datos conexión Banco<?php elseif ($activeTab === 'api-free-fire'): ?>Datos API<?php elseif ($activeTab === 'api-binance'): ?>API Binance Pay<?php elseif ($activeTab === 'verificacion-binance'): ?>Verificación Binance<?php elseif ($activeTab === 'paypal'): ?>Paypal<?php elseif ($activeTab === 'api-discord'): ?>API Discord<?php elseif ($activeTab === 'personalizar-colores'): ?>Personalizar Colores<?php elseif ($activeTab === 'ventana-inicial'): ?>Ventana Inicial<?php elseif ($activeTab === 'galeria'): ?>Galería principal del index<?php else: ?>Métodos de Pago<?php endif; ?>
          </h2>
        </div>
        <div class="card-body p-4">
          <?php if ($activeTab === 'correo'): ?>
            <form method="post">
              <input type="hidden" name="config_section" value="correo">
              <div class="config-section-note mb-4">Configura aquí el correo corporativo y los parámetros SMTP usados por la tienda.</div>
              <div class="mb-3">
                <label class="form-label">Correo corporativo</label>
                <input type="email" name="correo_corporativo" value="<?= htmlspecialchars($cfg['correo_corporativo'] ?? '') ?>" required class="form-control" placeholder="correo@tudominio.com">
              </div>
              <div class="mb-3">
                <label class="form-label">SMTP Host</label>
                <input type="text" name="smtp_host" value="<?= htmlspecialchars($cfg['smtp_host'] ?? '') ?>" required class="form-control" placeholder="smtp.tuservidor.com">
              </div>
              <div class="mb-3">
                <label class="form-label">SMTP User</label>
                <input type="text" name="smtp_user" value="<?= htmlspecialchars($cfg['smtp_user'] ?? '') ?>" required class="form-control" placeholder="usuario@tudominio.com">
              </div>
              <div class="mb-3">
                <label class="form-label">SMTP Password</label>
                <input type="password" name="smtp_pass" value="<?= htmlspecialchars($cfg['smtp_pass'] ?? '') ?>" class="form-control" placeholder="••••••••">
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">SMTP Port</label>
                  <input type="number" name="smtp_port" value="<?= htmlspecialchars($cfg['smtp_port'] ?? 587) ?>" required class="form-control" placeholder="587">
                </div>
                <div class="col-md-6">
                  <label class="form-label">SMTP Secure</label>
                  <select name="smtp_secure" class="form-select">
                    <option value="tls" <?= ($cfg['smtp_secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl" <?= ($cfg['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                  </select>
                </div>
              </div>
              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar configuración de correo</button>
            </form>
          <?php elseif ($activeTab === 'cabecera'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="config_section" value="cabecera">
              <div class="config-section-note mb-4">Controla el prefijo, nombre y logo de la tienda. El mismo logo también se usa como favicon.</div>
              <div class="row g-4 align-items-start">
                <div class="col-md-8">
                  <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="googleAnalyticsActivo" name="google_analytics_activo" value="1" <?= ($cfg['google_analytics_activo'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="googleAnalyticsActivo">Insertar script de Google Analytics en el footer público</label>
                  </div>
                  <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="instruccionesInfluencerActivas" name="instrucciones_influencer" value="1" <?= ($cfg['instrucciones_influencer'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="instruccionesInfluencerActivas">Activar modulo Instrucciones Influencer en menu publico y admin</label>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Nombre Prefijo</label>
                    <input type="text" name="nombre_prefijo" value="<?= htmlspecialchars($cfg['nombre_prefijo'] ?? 'TIENDA') ?>" required class="form-control" placeholder="TIENDA">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Nombre Tienda</label>
                    <input type="text" name="nombre_tienda" value="<?= htmlspecialchars($cfg['nombre_tienda'] ?? 'TVirtualGaming') ?>" required class="form-control" placeholder="TVirtualGaming">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Subtítulo del navegador / instalación</label>
                    <input type="text" name="nombre_tienda_subtitulo" value="<?= htmlspecialchars($cfg['nombre_tienda_subtitulo'] ?? 'Tienda de monedas digitales') ?>" required class="form-control" placeholder="Tienda de monedas digitales">
                    <div class="form-text mt-2">Este texto se usa en el título del inicio y puede aparecer en el aviso de instalar la app en el navegador.</div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Meta título SEO</label>
                    <input type="text" name="meta_titulo" value="<?= htmlspecialchars($cfg['meta_titulo'] ?? 'TVirtualGaming | Tienda de monedas digitales') ?>" required maxlength="160" class="form-control" placeholder="TVirtualGaming | Tienda de monedas digitales">
                    <div class="form-text mt-2">Este título se usa en la etiqueta title, en Google y al compartir en redes. Idealmente mantenlo entre 50 y 60 caracteres.</div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Meta descripción SEO</label>
                    <textarea name="meta_descripcion" rows="4" required maxlength="320" class="form-control" placeholder="Describe la tienda en una o dos frases para Google y redes sociales."><?= htmlspecialchars($cfg['meta_descripcion'] ?? 'Compra monedas y recargas digitales en TVirtualGaming. Recibe ofertas, promociones y novedades directamente en tu WhatsApp.') ?></textarea>
                    <div class="form-text mt-2">Google suele usar este texto como descripción del resultado de búsqueda. Intenta mantenerlo entre 140 y 160 caracteres para un snippet más estable.</div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Script de Google Analytics / Google Tag</label>
                    <textarea name="google_analytics_script" rows="7" class="form-control" placeholder="Pega aquí el código completo que te entrega Google, incluyendo las etiquetas <script>."><?= htmlspecialchars($cfg['google_analytics_script'] ?? '') ?></textarea>
                    <div class="form-text mt-2">Si el interruptor está activo y este campo tiene contenido, el script se insertará al final del sitio, antes de cerrar el body.</div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Logo tienda</label>
                    <input type="file" name="logo_tienda" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control">
                    <div class="form-text mt-2">Formatos permitidos: JPG, PNG, WEBP o GIF. Tamaño máximo: 2 MB.</div>
                  </div>
                  <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" value="1" id="eliminarLogoTienda" name="eliminar_logo_tienda">
                    <label class="form-check-label" for="eliminarLogoTienda">Eliminar logo actual</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <label class="form-label d-block">Vista previa del logo</label>
                  <div class="header-logo-preview">
                    <?php if ($logoTienda !== ''): ?>
                      <img src="<?= htmlspecialchars($logoTienda, ENT_QUOTES, 'UTF-8') ?>" alt="Logo de la tienda">
                    <?php else: ?>
                      <span class="header-logo-empty">Sin logo</span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php if ($publicAnimatedBackgroundEnabled): ?>
                <hr class="my-4 border-info-subtle">
                <div class="row g-4 align-items-start">
                  <div class="col-md-8">
                    <div class="mb-3">
                      <label class="form-label">Modo de fondo del sitio público</label>
                      <select name="fondo_publico_modo" class="form-select">
                        <option value="normal" <?= $publicBackgroundMode === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="media" <?= $publicBackgroundMode === 'media' ? 'selected' : '' ?>>Multimedia fija</option>
                      </select>
                      <div class="form-text mt-2">En modo Normal la tienda mantiene exactamente el fondo actual. En Multimedia fija se mostrará una imagen, GIF o video en toda la página pública, fijo durante el scroll.</div>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Archivo de fondo multimedia</label>
                      <input type="file" name="fondo_publico_media" accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/webm,video/ogg" class="form-control">
                      <div class="form-text mt-2">Formatos permitidos: MP4, WEBM, OGG, JPG, PNG, WEBP o GIF. Tamaño máximo: 25 MB.</div>
                    </div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="eliminarFondoPublicoMedia" name="eliminar_fondo_publico_media">
                      <label class="form-check-label" for="eliminarFondoPublicoMedia">Eliminar fondo multimedia actual</label>
                    </div>
                    <div class="row g-3 mt-1">
                      <div class="col-md-6">
                        <label class="form-label">Color de overlay</label>
                        <input type="color" name="fondo_publico_overlay_color" value="<?= htmlspecialchars($publicBackgroundSettings['overlay_color'] ?? '#081018', ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color">
                        <div class="form-text mt-2">Capa colocada sobre el fondo para conservar legibilidad sin tapar del todo el archivo.</div>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Opacidad del overlay (%)</label>
                        <input type="number" name="fondo_publico_overlay_opacity" min="0" max="100" step="1" value="<?= (int) ($publicBackgroundSettings['overlay_opacity'] ?? 52) ?>" class="form-control">
                      </div>
                    </div>
                    <div class="form-check form-switch mt-4 mb-3">
                      <input class="form-check-input" type="checkbox" role="switch" id="fondoPublicoAudioActivo" name="fondo_publico_audio_activo" value="1" <?= !empty($publicBackgroundSettings['sound_enabled']) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="fondoPublicoAudioActivo">Intentar reproducir audio cuando el fondo sea un video</label>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Volumen del video (%)</label>
                      <input type="number" name="fondo_publico_volumen" min="0" max="100" step="1" value="<?= (int) ($publicBackgroundSettings['volume'] ?? 35) ?>" class="form-control">
                      <div class="form-text mt-2">Los navegadores pueden bloquear el autoplay con sonido hasta que el usuario interactúe con la página. El sistema hará el intento automáticamente.</div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label d-block">Vista previa del fondo público</label>
                    <div class="gallery-image-preview">
                      <?php if ($publicBackgroundHasMedia && $publicBackgroundMediaType === 'video'): ?>
                        <video src="<?= htmlspecialchars($publicBackgroundMedia, ENT_QUOTES, 'UTF-8') ?>" muted loop autoplay playsinline></video>
                      <?php elseif ($publicBackgroundHasMedia): ?>
                        <img src="<?= htmlspecialchars($publicBackgroundMedia, ENT_QUOTES, 'UTF-8') ?>" alt="Fondo multimedia público">
                      <?php else: ?>
                        <span class="gallery-image-empty">Sin fondo multimedia</span>
                      <?php endif; ?>
                    </div>
                    <div class="form-text mt-3">
                      <?= $publicBackgroundMode === 'media' ? 'El fondo multimedia solo se mostrará en páginas públicas; el panel admin seguirá usando el fondo normal.' : 'Actualmente la tienda pública seguirá usando el fondo normal del tema.' ?>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar datos de cabecera</button>
            </form>
          <?php elseif ($activeTab === 'notificaciones-recargas'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="config_section" value="notificaciones-recargas">
              <div class="config-section-note mb-4">Activa o desactiva los avisos globales de recargas, y define un logo exclusivo para la notificación. Si no subes uno, se usará el logo configurado en Datos de cabecera.</div>
              <div class="row g-4 align-items-start">
                <div class="col-md-8">
                  <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="recargaNotificacionesActivas" name="recarga_notificaciones_activas" value="1" <?= ($cfg['recarga_notificaciones_activas'] ?? '1') !== '0' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="recargaNotificacionesActivas">Mostrar notificaciones de recargas en todo el sitio público</label>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Logo de la notificación</label>
                    <input type="file" name="recarga_notificaciones_logo" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control">
                    <div class="form-text mt-2">Si lo dejas vacío, la notificación usará automáticamente el logo principal de la tienda.</div>
                  </div>
                  <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" value="1" id="eliminarLogoNotificacionRecarga" name="eliminar_recarga_notificaciones_logo">
                    <label class="form-check-label" for="eliminarLogoNotificacionRecarga">Eliminar logo propio y volver a usar el logo principal</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <label class="form-label d-block">Vista previa del logo</label>
                  <div class="header-logo-preview">
                    <?php if ($rechargeNotificationsEffectiveLogo !== ''): ?>
                      <img src="<?= htmlspecialchars($rechargeNotificationsEffectiveLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo de notificación de recarga">
                    <?php else: ?>
                      <span class="header-logo-empty">Sin logo</span>
                    <?php endif; ?>
                  </div>
                  <div class="form-text mt-3">
                    <?= $rechargeNotificationsLogo !== '' ? 'Se está usando un logo exclusivo para esta notificación.' : 'Actualmente se usará el logo principal configurado en la cabecera.' ?>
                  </div>
                </div>
              </div>
              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar notificaciones de recargas</button>
            </form>

            <div class="config-live-notification-card">
              <div class="config-live-notification-header">
                <h3 class="config-live-notification-title">Posición de notificaciones flotantes</h3>
                <p class="config-live-notification-copy">Configura dónde aparecerán las notificaciones flotantes públicas de recargas y Win Points usando el mismo estilo visual definido en este módulo.</p>
                <?php if (!$winPointsEnabled): ?>
                  <p class="config-live-notification-copy">Win Points está desactivado actualmente. Esta posición seguirá aplicándose a las notificaciones de recargas y quedará lista para Win Points cuando el módulo esté activo.</p>
                <?php endif; ?>
              </div>

              <form method="post" class="row g-3" data-win-points-notification-config>
                <input type="hidden" name="config_section" value="notificaciones-recargas">
                <input type="hidden" name="save_win_points_notification_position" value="1">
                <div class="col-lg-8">
                  <label class="form-label">Posición de la notificación</label>
                  <select name="win_points_notification_position" class="form-select" data-win-points-notification-position-select>
                    <?php foreach ($winPointsNotificationPositions as $positionValue => $positionLabel): ?>
                      <option value="<?= htmlspecialchars($positionValue, ENT_QUOTES, 'UTF-8') ?>" <?= $winPointsNotificationPosition === $positionValue ? 'selected' : '' ?>><?= htmlspecialchars($positionLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text mt-2">La simulación usa la posición seleccionada y el estilo visual configurado en Personalizar Colores → Notificaciones de recargas.</div>
                </div>
                <div class="col-lg-4 config-live-notification-save-wrap">
                  <button type="submit" class="btn neon-btn px-4 py-2 config-live-notification-save">Guardar posición</button>
                </div>
                <div class="col-12">
                  <div class="config-live-notification-actions">
                    <button type="button" class="btn fw-bold config-live-notification-simulate" data-win-points-simulate-notification>Simular Notificación</button>
                    <p class="config-live-notification-help">Este botón muestra una vista previa inmediata de la posición elegida. Esa misma posición también se aplica a las notificaciones públicas de recargas.</p>
                  </div>
                </div>
              </form>
            </div>
          <?php elseif ($activeTab === 'sociales'): ?>
            <form method="post">
              <input type="hidden" name="config_section" value="sociales">
              <div class="config-section-note mb-4">Registra los enlaces oficiales de la tienda para mostrarlos o reutilizarlos desde otras secciones del sitio.</div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Facebook</label>
                  <input type="url" name="facebook" value="<?= htmlspecialchars($cfg['facebook'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://facebook.com/tupagina">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Instagram</label>
                  <input type="url" name="instagram" value="<?= htmlspecialchars($cfg['instagram'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://instagram.com/tucuenta">
                </div>
                <div class="col-md-6">
                  <label class="form-label">TikTok</label>
                  <input type="url" name="tiktok" value="<?= htmlspecialchars($cfg['tiktok'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://tiktok.com/@tuusuario">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Whatsapp</label>
                  <input type="tel" name="whatsapp" value="<?= htmlspecialchars($cfg['whatsapp'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="+584121234567" pattern="^\+?[1-9]\d{9,14}$" inputmode="tel">
                  <div class="form-text">Ingresa solo el número en formato internacional, con código de país y sin enlaces. Ejemplo: +584121234567.</div>
                  <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="whatsappFlotanteActivo" name="whatsapp_flotante_activo" value="1" <?= ($cfg['whatsapp_flotante_activo'] ?? '1') !== '0' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="whatsappFlotanteActivo">Mostrar botón flotante de WhatsApp en la página pública</label>
                  </div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Whatsapp Channel</label>
                  <input type="url" name="whatsapp_channel" value="<?= htmlspecialchars($cfg['whatsapp_channel'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://whatsapp.com/channel/...">
                  <div class="form-check form-switch mt-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="whatsappChannelFlotanteActivo" name="whatsapp_channel_flotante_activo" value="1" <?= ($cfg['whatsapp_channel_flotante_activo'] ?? '1') !== '0' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="whatsappChannelFlotanteActivo">Mostrar botón flotante del canal en la página pública</label>
                  </div>
                </div>
                <div class="col-12">
                  <label class="form-label">Mensaje del botón de Whatsapp</label>
                  <textarea name="mensaje_whatsapp" rows="3" class="form-control" placeholder="Hola, quiero información sobre sus productos."><?= htmlspecialchars($cfg['mensaje_whatsapp'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                  <div class="form-text">Este texto se enviará automáticamente al abrir el flotante de WhatsApp.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Google Client ID</label>
                  <input type="text" name="google_client_id" value="<?= htmlspecialchars($cfg['google_client_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="xxxxxxxx.apps.googleusercontent.com">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Google Client Secret</label>
                  <input type="password" name="google_client_secret" value="<?= htmlspecialchars($cfg['google_client_secret'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="GOCSPX-...">
                </div>
                <div class="col-12">
                  <label class="form-label">Callback autorizado para Google Cloud</label>
                  <input type="text" value="<?= htmlspecialchars($googleCallbackUrl, ENT_QUOTES, 'UTF-8') ?>" class="form-control" readonly>
                  <div class="form-text">Copia esta URL exactamente en Google Cloud Console > OAuth 2.0 Client IDs > Authorized redirect URIs.</div>
                </div>
              </div>
              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar redes sociales</button>
            </form>
          <?php elseif ($activeTab === 'api-banco'): ?>
            <form method="post">
              <input type="hidden" name="config_section" value="api-banco">
              <div class="config-section-note mb-4">Configura aquí los datos de conexión al banco usados para verificar automáticamente los pagos.</div>

              <?php
                $bankPreviewBaseUrl = store_config_normalize_bank_api_base_url((string) ($cfg['ff_bank_api_base_url'] ?? 'https://pagonorte.net'));
                if ($bankPreviewBaseUrl === '') {
                  $bankPreviewBaseUrl = 'https://pagonorte.net';
                }
                $bankPreviewPassword = trim((string) ($cfg['ff_bank_clave'] ?? '')) !== '' ? '********' : '';
                $bankPreviewUrl = store_config_build_bank_movements_url($bankPreviewBaseUrl, [
                  'posicion' => trim((string) ($cfg['ff_bank_posicion'] ?? '')),
                  'token' => trim((string) ($cfg['ff_bank_token'] ?? '')),
                  'password' => $bankPreviewPassword,
                ]);
              ?>

              <?php $bankAvailableDays = trim((string) ($cfg['ff_bank_dias_disponibles'] ?? '')); ?>
              <?php if ($bankAvailableDays !== ''): ?>
                <div class="alert alert-info rounded-4 mb-4" role="status">
                  La API bancaria reporta actualmente <strong><?= htmlspecialchars($bankAvailableDays, ENT_QUOTES, 'UTF-8') ?> días disponibles</strong> en la consulta de movimientos. Este dato se actualiza cuando se verifica un pago o se sincronizan movimientos manualmente.
                </div>
              <?php endif; ?>

              <div class="gallery-table-wrap mb-2">
                <h3 class="h5 fw-bold text-info mb-3">Datos para conexión al banco</h3>
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label">Enlace base API banco</label>
                    <input type="url" name="ff_bank_api_base_url" value="<?= htmlspecialchars($cfg['ff_bank_api_base_url'] ?? 'https://pagonorte.net', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://pagonorte.net">
                    <div class="form-text">Aquí puedes colocar la IP o el dominio base del servidor bancario.</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Posicion</label>
                    <select name="ff_bank_posicion" class="form-select">
                      <option value="" <?= (string) ($cfg['ff_bank_posicion'] ?? '') === '' ? 'selected' : '' ?>>Selecciona una posicion</option>
                      <?php for ($position = 0; $position <= 5; $position++): ?>
                        <option value="<?= $position ?>" <?= (string) ($cfg['ff_bank_posicion'] ?? '') === (string) $position ? 'selected' : '' ?>><?= $position ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Token</label>
                    <input type="text" name="ff_bank_token" value="<?= htmlspecialchars($cfg['ff_bank_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Clave</label>
                    <input type="password" name="ff_bank_clave" value="<?= htmlspecialchars($cfg['ff_bank_clave'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" pattern="^[A-Za-z0-9._!-]+$" autocomplete="new-password" spellcheck="false">
                    <div class="form-text">Solo letras, números y estos caracteres especiales: . - _ ! sin espacios.</div>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Enlace final enviado a la API</label>
                    <input type="text" id="bank-api-preview-url" value="<?= htmlspecialchars($bankPreviewUrl, ENT_QUOTES, 'UTF-8') ?>" class="form-control" readonly onclick="this.select()">
                    <div class="form-text">Este campo es solo de lectura y se actualiza automáticamente. La clave se oculta por seguridad.</div>
                  </div>
                </div>
              </div>

              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar datos de conexión del banco</button>
            </form>
            <script>
              (function() {
                const baseInput = document.querySelector('input[name="ff_bank_api_base_url"]');
                const positionInput = document.querySelector('select[name="ff_bank_posicion"]');
                const tokenInput = document.querySelector('input[name="ff_bank_token"]');
                const passwordInput = document.querySelector('input[name="ff_bank_clave"]');
                const previewInput = document.getElementById('bank-api-preview-url');

                if (!baseInput || !positionInput || !tokenInput || !passwordInput || !previewInput) {
                  return;
                }

                const buildPreviewUrl = function() {
                  let baseUrl = String(baseInput.value || '').trim();
                  if (baseUrl === '') {
                    baseUrl = 'https://pagonorte.net';
                  }
                  baseUrl = baseUrl.replace(/\/+$/, '');
                  let endpointUrl = baseUrl;
                  try {
                    const parsedUrl = new URL(baseUrl);
                    endpointUrl = /\.jsp$/i.test(parsedUrl.pathname || '')
                      ? parsedUrl.toString().replace(/\?$/, '')
                      : (baseUrl + '/recargas/movimientos.jsp');
                  } catch (error) {
                    endpointUrl = /\.jsp$/i.test(baseUrl)
                      ? baseUrl
                      : (baseUrl + '/recargas/movimientos.jsp');
                  }
                  const maskedPassword = String(passwordInput.value || '').trim() !== '' ? '********' : '';
                  const query = new URLSearchParams({
                    posicion: String(positionInput.value || '').trim(),
                    token: String(tokenInput.value || '').trim(),
                    password: maskedPassword
                  });
                  previewInput.value = endpointUrl + '?' + query.toString();
                };

                [baseInput, positionInput, tokenInput, passwordInput].forEach((field) => {
                  field.addEventListener('input', buildPreviewUrl);
                  field.addEventListener('change', buildPreviewUrl);
                });

                buildPreviewUrl();
              })();
            </script>
          <?php elseif ($activeTab === 'api-free-fire'): ?>
            <form method="post">
              <input type="hidden" name="config_section" value="api-free-fire">
              <div class="config-section-note mb-4">Registra aquí tu API KEY para la integración automática de recargas.</div>

              <div class="gallery-table-wrap mb-2">
                <h3 class="h5 fw-bold text-info mb-3">Datos API</h3>
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label">API KEY</label>
                    <input type="text" name="recargas_api_key" value="<?= htmlspecialchars($cfg['recargas_api_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Pega aquí tu API KEY">
                  </div>
                </div>
              </div>

              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar datos API</button>
            </form>
          <?php elseif ($activeTab === 'api-binance'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="config_section" value="api-binance">
              <div class="config-section-note mb-4">Configura aquí las credenciales de CoinPal usadas para cobrar exclusivamente con Binance Pay. En VirtualGaming este tab solo se muestra cuando la función <strong>api_binance</strong> está activa para el tenant.</div>

              <div class="gallery-table-wrap mb-2">
                <h3 class="h5 fw-bold text-info mb-3">Credenciales CoinPal / Binance Pay</h3>
                <div class="row g-3">
                  <div class="col-12">
                    <input type="hidden" name="api_binance_usuario_present" value="1">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" id="api-binance-usuario" name="api_binance_usuario" value="1" <?= ($cfg['api_binance_usuario'] ?? '1') === '1' ? 'checked' : '' ?>>
                      <label class="form-check-label fw-semibold" for="api-binance-usuario">Habilitar Binance Pay para clientes</label>
                    </div>
                    <div class="form-text mt-2">Este interruptor activa o desactiva el proceso visible para clientes, checkout, polling, webhook y sincronización. El tab seguirá visible mientras <strong>api_binance</strong> esté activo para el tenant.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Merchant No</label>
                    <input type="text" name="binance_pay_merchant_no" value="<?= htmlspecialchars($cfg['binance_pay_merchant_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="1000...">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Secret Key</label>
                    <input type="password" name="binance_pay_secret_key" value="<?= htmlspecialchars($cfg['binance_pay_secret_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Pega aquí tu Secret Key">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Store ID</label>
                    <input type="text" name="binance_pay_store_id" value="<?= htmlspecialchars($cfg['binance_pay_store_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="1000...">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Access Token</label>
                    <input type="text" name="binance_pay_access_token" value="<?= htmlspecialchars($cfg['binance_pay_access_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Token del Store en CoinPal">
                  </div>
                  <?php if ($paymentMethodDiscountsEnabled): ?>
                  <div class="col-md-6">
                    <label class="form-label">Descuento Binance Pay (%)</label>
                    <input type="number" name="binance_pay_descuento" min="0" max="100" step="0.01" value="<?= htmlspecialchars($cfg['binance_pay_descuento'] ?? '0', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="0.00">
                    <div class="form-text mt-2">Se aplicará automáticamente cuando el cliente complete la orden con Binance Pay.</div>
                  </div>
                  <?php endif; ?>
                  <div class="col-12">
                    <label class="form-label">Store URL registrado en CoinPal</label>
                    <input type="url" name="binance_pay_store_url" value="<?= htmlspecialchars($cfg['binance_pay_store_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://tudominio.com">
                    <div class="form-text mt-2">Debe coincidir con el dominio agregado en <strong>CoinPal Merchant Dashboard &gt; My Account &gt; My Store</strong>.</div>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label">Imagen del método de pago</label>
                    <input type="file" name="binance_pay_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control">
                    <div class="form-text mt-2">Esta imagen se mostrará en los paquetes como opción de Binance Pay. Tamaño recomendado: 1200x480 px.</div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="removeBinancePayImage" name="remove_binance_pay_image">
                      <label class="form-check-label" for="removeBinancePayImage">Eliminar imagen actual</label>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Vista previa</label>
                    <div class="config-section-note h-100 d-flex align-items-center justify-content-center p-3">
                      <?php $binancePayImagePath = trim((string) ($cfg['binance_pay_image'] ?? '')); ?>
                      <?php if ($binancePayImagePath !== ''): ?>
                        <img src="<?= htmlspecialchars(app_path('/' . ltrim($binancePayImagePath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa Binance Pay" class="img-fluid rounded-4 border border-info-subtle">
                      <?php else: ?>
                        <span class="text-secondary">Sin imagen cargada</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label">Imagen promocional de esquina</label>
                    <input type="file" name="binance_pay_corner_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control">
                    <div class="form-text mt-2">Aparecerá mitad dentro y mitad fuera de la card pública para destacar promociones. Tamaño recomendado: 320x320 px con transparencia.</div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="removeBinancePayCornerImage" name="remove_binance_pay_corner_image">
                      <label class="form-check-label" for="removeBinancePayCornerImage">Eliminar imagen promocional actual</label>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Vista previa promo esquina</label>
                    <div class="config-section-note h-100 d-flex align-items-center justify-content-center p-3">
                      <?php $binancePayCornerImagePath = trim((string) ($cfg['binance_pay_corner_image'] ?? '')); ?>
                      <?php if ($binancePayCornerImagePath !== ''): ?>
                        <img src="<?= htmlspecialchars(app_path('/' . ltrim($binancePayCornerImagePath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa promo Binance Pay" class="img-fluid rounded-4 border border-info-subtle" style="max-width: 180px;">
                      <?php else: ?>
                        <span class="text-secondary">Sin imagen cargada</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Método de pago configurado</label>
                    <input type="text" class="form-control" value="Binance Pay" readonly>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">URL pública detectada actualmente</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($currentPublicUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                  </div>
                </div>
              </div>

              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar configuración de Binance Pay</button>
            </form>
          <?php elseif ($activeTab === 'verificacion-binance'): ?>
            <?php
              $binancePagonorteToken = trim((string) ($cfg['binance_pagonorte_token'] ?? ''));
              $binancePagonorteMovementsUrl = store_config_build_binance_pagonorte_movements_url($binancePagonorteToken);
              $binancePagonorteBalanceUrl = store_config_build_binance_pagonorte_balance_url($binancePagonorteToken);
              $binancePagonorteAvailableDays = trim((string) ($cfg['binance_pagonorte_dias_disponibles'] ?? ''));
              $binancePagonorteCutoffDate = trim((string) ($cfg['binance_pagonorte_fecha_corte'] ?? ''));
              $binancePagonorteEnabled = trim((string) ($cfg['binance_pagonorte_activo'] ?? '0')) === '1';
              $binancePagonorteDiscount = trim((string) ($cfg['binance_pagonorte_descuento'] ?? '0'));
              $binancePagonorteReferenceDigits = max(0, (int) ($cfg['binance_pagonorte_referencia_digitos'] ?? 0));
              $binancePagonorteData = trim((string) ($cfg['binance_pagonorte_datos'] ?? ''));
              $binancePagonorteImagePath = trim((string) ($cfg['binance_pagonorte_image'] ?? ''));
              $binancePagonorteCornerImagePath = trim((string) ($cfg['binance_pagonorte_corner_image'] ?? ''));
              $binancePagonorteQrImagePath = trim((string) ($cfg['binance_pagonorte_qr_image'] ?? ''));
            ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="config_section" value="verificacion-binance">
              <div class="config-section-note mb-4">Configura aquí el token, la presentación pública y los datos de cobro de Binance. Este tab solo se muestra cuando la función <strong>api_binance_pagonorte</strong> está activa para el tenant.</div>

              <?php if ($binancePagonorteAvailableDays !== '' || $binancePagonorteCutoffDate !== ''): ?>
                <div class="alert alert-info rounded-4 mb-4" role="status">
                  <?php if ($binancePagonorteAvailableDays !== ''): ?>
                    La API Binance reporta actualmente <strong><?= htmlspecialchars($binancePagonorteAvailableDays, ENT_QUOTES, 'UTF-8') ?> días disponibles</strong>
                  <?php else: ?>
                    La API Binance ya respondió el último estado de saldo de esta integración
                  <?php endif; ?>
                  <?php if ($binancePagonorteCutoffDate !== ''): ?>
                    y la <strong>fecha de corte</strong> registrada es <strong><?= htmlspecialchars($binancePagonorteCutoffDate, ENT_QUOTES, 'UTF-8') ?></strong>.
                  <?php else: ?>
                    para esta integración.
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="gallery-table-wrap mb-2">
                <h3 class="h5 fw-bold text-info mb-3">Datos de verificación Binance</h3>
                <div class="row g-3">
                  <div class="col-12">
                    <input type="hidden" name="binance_pagonorte_activo_present" value="1">
                    <div class="form-check form-switch mt-1">
                      <input class="form-check-input" type="checkbox" role="switch" id="binancePagonorteEnabled" name="binance_pagonorte_activo" value="1" <?= $binancePagonorteEnabled ? 'checked' : '' ?>>
                      <label class="form-check-label fw-semibold" for="binancePagonorteEnabled">Activar método de pago Binance en la web pública</label>
                    </div>
                    <div class="form-text mt-2">Cuando esté activo, este método aparecerá en los juegos y permitirá cobrar en USDT con validación automática por referencia.</div>
                  </div>
                  <div class="col-md-8">
                    <label class="form-label">Token</label>
                    <input type="text" id="binance-pagonorte-token" name="binance_pagonorte_token" value="<?= htmlspecialchars($binancePagonorteToken, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Pega aquí el token de Binance">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Descuento (%)</label>
                    <input type="number" name="binance_pagonorte_descuento" min="0" max="100" step="0.01" value="<?= htmlspecialchars($binancePagonorteDiscount, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="0.00">
                    <div class="form-text mt-2">Porcentaje que se descontará al usar este método de pago.</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Comparar últimos dígitos de referencia</label>
                    <input type="number" name="binance_pagonorte_referencia_digitos" min="0" max="120" step="1" value="<?= htmlspecialchars((string) $binancePagonorteReferenceDigits, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="0">
                    <div class="form-text mt-2">Si colocas <strong>0</strong>, se comparará la referencia completa. Si colocas <strong>5</strong>, el cliente podrá escribir la referencia completa o los últimos 5 dígitos.</div>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Datos de transferencia</label>
                    <textarea name="binance_pagonorte_datos" rows="5" class="form-control" placeholder="Ejemplo:&#10;Correo Binance: ...&#10;ID Binance Pay: ...&#10;Nombre del beneficiario: ..."><?= htmlspecialchars($binancePagonorteData, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="form-text mt-2">Estos datos se mostrarán junto al QR dentro de la ventana de pago, tal como ocurre con los pagos manuales en Bs.</div>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Enlace para verificar movimientos</label>
                    <input type="text" id="binance-pagonorte-movements-url" value="<?= htmlspecialchars($binancePagonorteMovementsUrl, ENT_QUOTES, 'UTF-8') ?>" class="form-control" readonly onclick="this.select()">
                  </div>
                  <div class="col-12">
                    <label class="form-label">Enlace para verificar saldo</label>
                    <input type="text" id="binance-pagonorte-balance-url" value="<?= htmlspecialchars($binancePagonorteBalanceUrl, ENT_QUOTES, 'UTF-8') ?>" class="form-control" readonly onclick="this.select()">
                  </div>
                  <div class="col-md-7">
                    <label class="form-label">Imagen del método de pago</label>
                    <input type="file" name="binance_pagonorte_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control">
                    <div class="form-text mt-2">Se mostrará en la card pública del método Binance. Tamaño recomendado: 1200x480 px.</div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="removeBinancePagonorteImage" name="remove_binance_pagonorte_image">
                      <label class="form-check-label" for="removeBinancePagonorteImage">Eliminar imagen actual</label>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Vista previa</label>
                    <div class="config-section-note h-100 d-flex align-items-center justify-content-center p-3">
                      <?php if ($binancePagonorteImagePath !== ''): ?>
                        <img src="<?= htmlspecialchars(app_path('/' . ltrim($binancePagonorteImagePath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa Binance" class="img-fluid rounded-4 border border-info-subtle">
                      <?php else: ?>
                        <span class="text-secondary">Sin imagen cargada</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label">Imagen promocional de esquina</label>
                    <input type="file" name="binance_pagonorte_corner_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control">
                    <div class="form-text mt-2">Aparecerá flotando sobre la card pública para destacar promociones o campañas activas.</div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="removeBinancePagonorteCornerImage" name="remove_binance_pagonorte_corner_image">
                      <label class="form-check-label" for="removeBinancePagonorteCornerImage">Eliminar imagen promocional actual</label>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Vista previa promo esquina</label>
                    <div class="config-section-note h-100 d-flex align-items-center justify-content-center p-3">
                      <?php if ($binancePagonorteCornerImagePath !== ''): ?>
                        <img src="<?= htmlspecialchars(app_path('/' . ltrim($binancePagonorteCornerImagePath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa promo Binance" class="img-fluid rounded-4 border border-info-subtle" style="max-width: 180px;">
                      <?php else: ?>
                        <span class="text-secondary">Sin imagen cargada</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label">Imagen QR</label>
                    <input type="file" name="binance_pagonorte_qr_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control">
                    <div class="form-text mt-2">Este QR se mostrará al cliente junto con los datos de transferencia durante el checkout.</div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="removeBinancePagonorteQrImage" name="remove_binance_pagonorte_qr_image">
                      <label class="form-check-label" for="removeBinancePagonorteQrImage">Eliminar QR actual</label>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Vista previa QR</label>
                    <div class="config-section-note h-100 d-flex align-items-center justify-content-center p-3">
                      <?php if ($binancePagonorteQrImagePath !== ''): ?>
                        <img src="<?= htmlspecialchars(app_path('/' . ltrim($binancePagonorteQrImagePath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa QR Binance" class="img-fluid rounded-4 border border-info-subtle" style="max-width: 220px;">
                      <?php else: ?>
                        <span class="text-secondary">Sin QR cargado</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar configuración de Verificación Binance</button>
            </form>
            <script>
              (function() {
                const tokenInput = document.getElementById('binance-pagonorte-token');
                const movementsUrlInput = document.getElementById('binance-pagonorte-movements-url');
                const balanceUrlInput = document.getElementById('binance-pagonorte-balance-url');

                if (!tokenInput || !movementsUrlInput || !balanceUrlInput) {
                  return;
                }

                const buildUrl = function(endpoint, token) {
                  const query = new URLSearchParams({ token: String(token || '').trim() });
                  return `https://apicentral.pro/apis/${endpoint}?${query.toString()}`;
                };

                const syncUrls = function() {
                  const token = String(tokenInput.value || '').trim();
                  movementsUrlInput.value = buildUrl('movimientos_binance.jsp', token);
                  balanceUrlInput.value = buildUrl('saldo_binance.jsp', token);
                };

                tokenInput.addEventListener('input', syncUrls);
                tokenInput.addEventListener('change', syncUrls);
                syncUrls();
              })();
            </script>
          <?php elseif ($activeTab === 'paypal'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="config_section" value="paypal">
              <div class="config-section-note mb-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                <div>Configura aquí la base de PayPal Checkout para esta tienda. Este tab solo se muestra cuando la función <strong>pago_paypal</strong> está activa en el tenant. La integración final usará webhook para confirmar el pago y disparar la recarga automática.</div>
                <button type="button" id="paypal-docs-trigger" class="btn btn-outline-info btn-sm px-4 py-2 align-self-start align-self-lg-center" data-bs-toggle="modal" data-bs-target="#paypal-docs-modal">Instrucciones para conectar</button>
              </div>

              <div class="alert alert-warning rounded-4 mb-4" role="alert">
                <div class="fw-semibold mb-2">Si el sitio público sigue mostrando errores viejos después de un push</div>
                <div class="mb-3">Usa este refresco para invalidar <strong>game.php</strong> y los helpers de pago en el servidor publicado. Es útil cuando Hostinger sigue sirviendo una versión vieja del checkout de PayPal o Binance.</div>
                <button type="submit" name="refresh_runtime_code" value="1" class="btn btn-outline-warning fw-bold px-4 py-2">Refrescar código publicado ahora</button>
              </div>

              <div class="gallery-table-wrap mb-2">
                <h3 class="h5 fw-bold text-info mb-3">Credenciales y enlaces PayPal</h3>
                <div class="row g-3">
                  <div class="col-12">
                    <input type="hidden" name="paypal_activo_present" value="1">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" id="paypal-activo" name="paypal_activo" value="1" <?= ($cfg['paypal_activo'] ?? '1') === '1' ? 'checked' : '' ?>>
                      <label class="form-check-label fw-semibold" for="paypal-activo">Habilitar PayPal para clientes</label>
                    </div>
                    <div class="form-text mt-2">Si lo desactivas, PayPal dejará de mostrarse en los métodos de pago de los juegos. El tab seguirá visible mientras <strong>pago_paypal</strong> esté activo para el tenant.</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Entorno</label>
                    <select name="paypal_environment" class="form-select">
                      <option value="sandbox" <?= ($cfg['paypal_environment'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                      <option value="live" <?= ($cfg['paypal_environment'] ?? 'sandbox') === 'live' ? 'selected' : '' ?>>Live</option>
                    </select>
                    <div class="form-text mt-2">Usa <strong>Sandbox</strong> para pruebas y <strong>Live</strong> solo cuando tu app real ya esté aprobada en PayPal Developer Dashboard.</div>
                  </div>
                  <div class="col-md-8">
                    <label class="form-label">Brand Name</label>
                    <input type="text" name="paypal_brand_name" value="<?= htmlspecialchars($cfg['paypal_brand_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="TVirtualGaming Store Local">
                    <div class="form-text mt-2">Este nombre se envía a PayPal para que el cliente vea la marca correcta durante el checkout.</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Impuesto Paypal</label>
                    <input type="number" name="paypal_impuesto" min="0" max="100" step="0.01" value="<?= htmlspecialchars($cfg['paypal_impuesto'] ?? '0', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="0.00">
                    <div class="form-text mt-2">Porcentaje extra que se sumará al total cuando el cliente elija PayPal. Ejemplo: si el juego cuesta 100 y aquí colocas 10, PayPal mostrará 110.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Client ID</label>
                    <input type="text" name="paypal_client_id" value="<?= htmlspecialchars($cfg['paypal_client_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="AQ...">
                    <div class="form-text mt-2">Consíguelo en <strong>PayPal Developer Dashboard &gt; Apps & Credentials</strong>, dentro de tu app REST.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Client Secret</label>
                    <input type="password" name="paypal_client_secret" value="<?= htmlspecialchars($cfg['paypal_client_secret'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Pega aquí tu Client Secret">
                    <div class="form-text mt-2">Está en la misma app REST. Debe coincidir con el entorno elegido arriba: sandbox o live.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Webhook ID</label>
                    <input type="text" name="paypal_webhook_id" value="<?= htmlspecialchars($cfg['paypal_webhook_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="8JU...">
                    <div class="form-text mt-2">Después de crear el webhook en PayPal, copia aquí el <strong>Webhook ID</strong> para validar la firma enviada por PayPal.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Webhook que debes registrar en PayPal</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($paypalWebhookUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                    <div class="form-text mt-2">Pega este enlace en <strong>PayPal Developer Dashboard &gt; Webhooks</strong>. PayPal notificará aquí cuando el pago cambie a completado.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Return URL informativa</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($paypalReturnUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                    <div class="form-text mt-2">Esta URL se usará cuando PayPal devuelva al cliente a la tienda después de aprobar el pago.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Cancel URL informativa</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($paypalCancelUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                    <div class="form-text mt-2">Esta URL se usará cuando el cliente cancele el checkout desde PayPal.</div>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label">Imagen del método de pago</label>
                    <input type="file" name="paypal_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control">
                    <div class="form-text mt-2">Se mostrará en la card pública de métodos de pago del paquete. Tamaño recomendado: 1200x480 px.</div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="removePaypalImage" name="remove_paypal_image">
                      <label class="form-check-label" for="removePaypalImage">Eliminar imagen actual</label>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Vista previa</label>
                    <div class="config-section-note h-100 d-flex align-items-center justify-content-center p-3">
                      <?php $paypalImagePath = trim((string) ($cfg['paypal_image'] ?? '')); ?>
                      <?php if ($paypalImagePath !== ''): ?>
                        <img src="<?= htmlspecialchars(app_path('/' . ltrim($paypalImagePath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa PayPal" class="img-fluid rounded-4 border border-info-subtle">
                      <?php else: ?>
                        <span class="text-secondary">Sin imagen cargada</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label">Imagen promocional de esquina</label>
                    <input type="file" name="paypal_corner_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control">
                    <div class="form-text mt-2">Aparecerá flotando sobre la card pública de PayPal para destacar promociones o campañas.</div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="removePaypalCornerImage" name="remove_paypal_corner_image">
                      <label class="form-check-label" for="removePaypalCornerImage">Eliminar imagen promocional actual</label>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Vista previa promo esquina</label>
                    <div class="config-section-note h-100 d-flex align-items-center justify-content-center p-3">
                      <?php $paypalCornerImagePath = trim((string) ($cfg['paypal_corner_image'] ?? '')); ?>
                      <?php if ($paypalCornerImagePath !== ''): ?>
                        <img src="<?= htmlspecialchars(app_path('/' . ltrim($paypalCornerImagePath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa promo PayPal" class="img-fluid rounded-4 border border-info-subtle" style="max-width: 180px;">
                      <?php else: ?>
                        <span class="text-secondary">Sin imagen cargada</span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-7">
                    <label class="form-label">QR de PayPal</label>
                    <input type="file" name="paypal_qr_image" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="form-control">
                    <div class="form-text mt-2">Úsalo si quieres mostrar un QR informativo o una pieza gráfica promocional dentro del modal de pago de PayPal.</div>
                    <div class="form-check mt-3">
                      <input class="form-check-input" type="checkbox" value="1" id="removePaypalQrImage" name="remove_paypal_qr_image">
                      <label class="form-check-label" for="removePaypalQrImage">Eliminar QR actual</label>
                    </div>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Vista previa QR</label>
                    <div class="config-section-note h-100 d-flex align-items-center justify-content-center p-3">
                      <?php $paypalQrImagePath = trim((string) ($cfg['paypal_qr_image'] ?? '')); ?>
                      <?php if ($paypalQrImagePath !== ''): ?>
                        <img src="<?= htmlspecialchars(app_path('/' . ltrim($paypalQrImagePath, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa QR PayPal" class="img-fluid rounded-4 border border-info-subtle" style="max-width: 220px;">
                      <?php else: ?>
                        <span class="text-secondary">Sin QR cargado</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>

              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar configuración de Paypal</button>
            </form>
            <div class="modal fade" id="paypal-docs-modal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-scrollable" style="max-width:min(1480px,96vw); margin:1rem auto;">
                <div class="modal-content" style="background:#181f2a; border:2px solid #60a5fa; color:#e6fbff; box-shadow:0 0 24px rgba(96,165,250,0.22); min-height:calc(100vh - 2rem);">
                  <div class="modal-header" style="border-bottom:1px solid rgba(96,165,250,0.28);">
                    <div>
                      <h3 class="modal-title h4 fw-bold text-info mb-1">Instrucciones para conectar PayPal</h3>
                      <p class="mb-0" style="color:#c7e9ff;">Guía completa, exacta y sin omisiones para dejar este checkout funcionando con Sandbox o Live.</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" data-paypal-docs-close="1" aria-label="Cerrar"></button>
                  </div>
                  <div class="modal-body">
                    <div class="row g-4">
                      <div class="col-12">
                        <div class="gallery-table-wrap">
                          <h4 class="h5 fw-bold text-info mb-3">0. Qué necesitas antes de empezar</h4>
                          <ol class="mb-0" style="color:#d9f4ff; line-height:1.8;">
                            <li>Tener una cuenta <strong>PayPal Business</strong> o una cuenta personal que luego conviertas a Business. Para cobrar en una tienda real no basta con una cuenta cualquiera sin acceso a herramientas de negocio.</li>
                            <li>Tener acceso a <strong>PayPal Developer Dashboard</strong> con el mismo correo o con una cuenta autorizada sobre el negocio.</li>
                            <li>Tener esta tienda publicada en una <strong>URL pública HTTPS real</strong>. No uses localhost, 127.0.0.1, IP privada o enlaces temporales que PayPal no pueda alcanzar desde internet.</li>
                            <li>Tener claro si vas a trabajar primero en <strong>Sandbox</strong> o en <strong>Live</strong>. No mezcles credenciales de un entorno con URLs o webhooks del otro.</li>
                          </ol>
                        </div>
                      </div>

                      <div class="col-12 col-xl-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">1. Cuándo usar Sandbox y cuándo usar Live</h4>
                          <div class="table-responsive">
                            <table class="table table-dark table-sm align-middle mb-0">
                              <thead>
                                <tr>
                                  <th>Entorno</th>
                                  <th>Cuándo usarlo</th>
                                  <th>Qué ocurre</th>
                                </tr>
                              </thead>
                              <tbody>
                                <tr>
                                  <td>Sandbox</td>
                                  <td>Durante configuración, pruebas internas, validación de webhook y pruebas de retorno/cancelación.</td>
                                  <td>No mueve dinero real. Usa cuentas de prueba creadas dentro de PayPal Developer.</td>
                                </tr>
                                <tr>
                                  <td>Live</td>
                                  <td>Solo cuando ya validaste todo en Sandbox y quieres cobrar a clientes reales.</td>
                                  <td>Los pagos son reales. Un error aquí afecta órdenes reales, webhook real y dinero real.</td>
                                </tr>
                              </tbody>
                            </table>
                          </div>
                          <div class="form-text mt-3">Regla exacta: si el campo <strong>Entorno</strong> de esta tienda está en <strong>Sandbox</strong>, el <strong>Client ID</strong>, el <strong>Client Secret</strong> y el <strong>Webhook ID</strong> también deben ser Sandbox. Si está en <strong>Live</strong>, todo debe venir de Live.</div>
                        </div>
                      </div>

                      <div class="col-12 col-xl-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">2. Dónde crear la app y sacar las credenciales</h4>
                          <ol class="mb-0" style="color:#d9f4ff; line-height:1.8;">
                            <li>Entra a <a href="https://developer.paypal.com/" target="_blank" rel="noopener noreferrer" style="color:#7dd3fc; text-decoration:underline;"><strong>https://developer.paypal.com/</strong></a> e inicia sesión.</li>
                            <li>Abre <strong>Dashboard &gt; Apps &amp; Credentials</strong>.</li>
                            <li>En la parte superior elige <strong>Sandbox</strong> o <strong>Live</strong>, según lo que quieras configurar en esta tienda.</li>
                            <li>Dentro de <strong>REST API apps</strong>, pulsa <strong>Create App</strong>.</li>
                            <li>Escribe un nombre claro, por ejemplo: <strong>TVirtualGaming Checkout</strong> o el nombre real de la tienda.</li>
                            <li>Si estás en Sandbox, PayPal te pedirá elegir una cuenta business de pruebas. Selecciónala y crea la app.</li>
                            <li>Al entrar a la app verás el <strong>Client ID</strong> y el botón para mostrar el <strong>Secret</strong>. Esos dos valores son los que copias a esta tienda.</li>
                          </ol>
                        </div>
                      </div>

                      <div class="col-12">
                        <div class="gallery-table-wrap">
                          <h4 class="h5 fw-bold text-info mb-3">3. Qué valores debes copiar de PayPal hacia esta tienda</h4>
                          <div class="table-responsive">
                            <table class="table table-dark table-sm align-middle mb-0">
                              <thead>
                                <tr>
                                  <th>Campo de esta tienda</th>
                                  <th>Qué pegas aquí</th>
                                  <th>Dónde lo encuentras en PayPal</th>
                                </tr>
                              </thead>
                              <tbody>
                                <tr>
                                  <td>Entorno</td>
                                  <td><strong>Sandbox</strong> o <strong>Live</strong> según la app que abriste.</td>
                                  <td>Se decide arriba en <strong>Apps &amp; Credentials</strong>.</td>
                                </tr>
                                <tr>
                                  <td>Brand Name</td>
                                  <td>El nombre comercial que quieres mostrar al cliente durante el checkout.</td>
                                  <td>No siempre viene listo desde PayPal. Normalmente lo defines tú manualmente para que coincida con la tienda.</td>
                                </tr>
                                <tr>
                                  <td>Client ID</td>
                                  <td>El identificador público de la app REST.</td>
                                  <td><strong>PayPal Developer &gt; Apps &amp; Credentials &gt; tu app &gt; Client ID</strong>.</td>
                                </tr>
                                <tr>
                                  <td>Client Secret</td>
                                  <td>La clave secreta de esa misma app REST.</td>
                                  <td><strong>PayPal Developer &gt; Apps &amp; Credentials &gt; tu app &gt; Secret</strong>.</td>
                                </tr>
                                <tr>
                                  <td>Webhook ID</td>
                                  <td>El ID interno del webhook que crearás para esta tienda.</td>
                                  <td>Dentro de la configuración del webhook de la app, después de guardarlo. Debes copiar el <strong>ID</strong>, no la URL.</td>
                                </tr>
                              </tbody>
                            </table>
                          </div>
                        </div>
                      </div>

                      <div class="col-12">
                        <div class="gallery-table-wrap">
                          <h4 class="h5 fw-bold text-info mb-3">4. Qué valores debes copiar de esta tienda hacia PayPal</h4>
                          <div class="row g-3">
                            <div class="col-md-4">
                              <label class="form-label">URL pública de la tienda</label>
                              <input type="text" class="form-control" value="<?= htmlspecialchars($currentPublicUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                              <div class="form-text mt-2"><a href="<?= htmlspecialchars($currentPublicUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="color:#7dd3fc; text-decoration:underline;">Abrir URL pública</a></div>
                            </div>
                            <div class="col-md-4">
                              <label class="form-label">Webhook URL</label>
                              <input type="text" class="form-control" value="<?= htmlspecialchars($paypalWebhookUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                              <div class="form-text mt-2"><a href="<?= htmlspecialchars($paypalWebhookUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="color:#7dd3fc; text-decoration:underline;">Abrir webhook URL</a></div>
                            </div>
                            <div class="col-md-4">
                              <label class="form-label">Return URL</label>
                              <input type="text" class="form-control" value="<?= htmlspecialchars($paypalReturnUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                              <div class="form-text mt-2"><a href="<?= htmlspecialchars($paypalReturnUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="color:#7dd3fc; text-decoration:underline;">Abrir return URL</a></div>
                            </div>
                            <div class="col-md-4">
                              <label class="form-label">Cancel URL</label>
                              <input type="text" class="form-control" value="<?= htmlspecialchars($paypalCancelUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                              <div class="form-text mt-2"><a href="<?= htmlspecialchars($paypalCancelUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="color:#7dd3fc; text-decoration:underline;">Abrir cancel URL</a></div>
                            </div>
                            <div class="col-md-8">
                              <div class="form-text mt-2">Estos enlaces salen de la tienda y son los que debes usar cuando PayPal te pida dónde notificar eventos o a dónde devolver al comprador. No los inventes ni los escribas manualmente si no es necesario: copia exactamente estos valores.</div>
                            </div>
                            <div class="col-12">
                              <div class="table-responsive mt-2">
                                <table class="table table-dark table-striped align-middle mb-0">
                                  <thead>
                                    <tr>
                                      <th>Valor de esta tienda</th>
                                      <th>En dónde se usa dentro de PayPal</th>
                                      <th>Campo exacto</th>
                                      <th>Qué debes hacer exactamente</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    <tr>
                                      <td>URL pública de la tienda</td>
                                      <td>No va en un campo obligatorio del webhook ni de la app REST.</td>
                                      <td>No aplica como pegado directo.</td>
                                      <td>Úsala como referencia para comprobar que el <strong>Webhook URL</strong>, el <strong>Return URL</strong> y el <strong>Cancel URL</strong> pertenecen al mismo dominio de tu tienda.</td>
                                    </tr>
                                    <tr>
                                      <td>Webhook URL</td>
                                      <td><strong>PayPal Developer &gt; Apps &amp; Credentials &gt; tu app &gt; Webhooks &gt; Add webhook</strong>.</td>
                                      <td>Campo <strong>Webhook URL</strong> o <strong>URL</strong> del formulario del webhook.</td>
                                      <td>Pega allí exactamente el valor mostrado en esta tienda y luego selecciona los eventos a suscribir.</td>
                                    </tr>
                                    <tr>
                                      <td>Return URL</td>
                                      <td>No se pega manualmente en el formulario de <strong>Webhooks</strong> ni en <strong>Apps &amp; Credentials</strong> para esta integración.</td>
                                      <td>La tienda la envía automáticamente a PayPal dentro de la orden, en <strong>experience_context.return_url</strong>.</td>
                                      <td>No debes buscar un campo en el dashboard para pegarla. Solo verifica que esta URL sea correcta para tu dominio. Si alguna configuración adicional de PayPal te pidiera una URL de retorno, usa exactamente esta.</td>
                                    </tr>
                                    <tr>
                                      <td>Cancel URL</td>
                                      <td>No se pega manualmente en el formulario de <strong>Webhooks</strong> ni en <strong>Apps &amp; Credentials</strong> para esta integración.</td>
                                      <td>La tienda la envía automáticamente a PayPal dentro de la orden, en <strong>experience_context.cancel_url</strong>.</td>
                                      <td>No debes buscar un campo en el dashboard para pegarla. Solo verifica que esta URL sea correcta para tu dominio. Si alguna configuración adicional de PayPal te pidiera una URL de cancelación, usa exactamente esta.</td>
                                    </tr>
                                  </tbody>
                                </table>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="col-12 col-xl-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">5. Cómo crear el webhook correctamente</h4>
                          <ol class="mb-3" style="color:#d9f4ff; line-height:1.8;">
                            <li>Dentro de tu app REST en PayPal Developer, busca la sección <strong>Webhooks</strong>.</li>
                            <li>Haz clic en <strong>Add webhook</strong> o <strong>Create webhook</strong>.</li>
                            <li>En el campo de URL pega exactamente <a href="<?= htmlspecialchars($paypalWebhookUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="color:#7dd3fc; text-decoration:underline;"><strong><?= htmlspecialchars($paypalWebhookUrl, ENT_QUOTES, 'UTF-8') ?></strong></a>.</li>
                            <li>En ese formulario de PayPal <strong>no</strong> debes pegar ahí el <strong>Return URL</strong> ni el <strong>Cancel URL</strong>; ese formulario solo corresponde al webhook y a sus eventos.</li>
                            <li>Guarda el webhook y luego entra al detalle del webhook creado.</li>
                            <li>Copia el <strong>Webhook ID</strong> y pégalo en el campo <strong>Webhook ID</strong> de esta tienda.</li>
                          </ol>
                          <div class="form-text">Si creas el webhook pero no copias el <strong>Webhook ID</strong> en esta tienda, PayPal puede enviar eventos, pero la tienda no podrá validar correctamente la firma.</div>
                        </div>
                      </div>

                      <div class="col-12 col-xl-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">6. Eventos recomendados para suscribir</h4>
                          <ul class="mb-0" style="color:#d9f4ff; line-height:1.8;">
                            <li><strong>CHECKOUT.ORDER.APPROVED</strong></li>
                            <li><strong>CHECKOUT.ORDER.COMPLETED</strong></li>
                            <li><strong>PAYMENT.CAPTURE.COMPLETED</strong></li>
                            <li><strong>PAYMENT.CAPTURE.DENIED</strong></li>
                            <li><strong>PAYMENT.CAPTURE.DECLINED</strong></li>
                            <li><strong>PAYMENT.CAPTURE.PENDING</strong></li>
                            <li><strong>PAYMENT.CAPTURE.REFUNDED</strong></li>
                            <li><strong>PAYMENT.CAPTURE.REVERSED</strong></li>
                          </ul>
                          <div class="form-text mt-3">Con este conjunto cubres aprobación, captura exitosa, pendientes, rechazos y movimientos posteriores. La tienda usa estos eventos para sincronizar el pedido incluso si el cliente cierra la ventana del navegador.</div>
                        </div>
                      </div>

                      <div class="col-12">
                        <div class="gallery-table-wrap">
                          <h4 class="h5 fw-bold text-info mb-3">7. Orden exacto para conectar sin fallar</h4>
                          <ol class="mb-0" style="color:#d9f4ff; line-height:1.85;">
                            <li>Primero define si vas a usar <strong>Sandbox</strong> o <strong>Live</strong>.</li>
                            <li>Crea la app REST en ese mismo entorno dentro de PayPal Developer.</li>
                            <li>Copia <strong>Client ID</strong> y <strong>Client Secret</strong> desde PayPal a esta tienda.</li>
                            <li>Escribe el <strong>Brand Name</strong> que verá el cliente.</li>
                            <li>Copia desde esta tienda el <strong>Webhook URL</strong> hacia PayPal y crea el webhook.</li>
                            <li>Selecciona los eventos recomendados.</li>
                            <li>Guarda el webhook en PayPal.</li>
                            <li>Vuelve al detalle del webhook en PayPal y copia el <strong>Webhook ID</strong>.</li>
                            <li>Pega ese <strong>Webhook ID</strong> en esta tienda.</li>
                            <li>Verifica que <strong>Return URL</strong> y <strong>Cancel URL</strong> se correspondan con esta misma tienda y dominio. En esta integración no se registran manualmente en el formulario del webhook; la tienda los envía automáticamente al crear la orden.</li>
                            <li>Guarda esta configuración en el panel.</li>
                            <li>Haz una prueba real del flujo en el entorno seleccionado antes de anunciarlo como activo.</li>
                          </ol>
                        </div>
                      </div>

                      <div class="col-12 col-xl-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">8. Cómo probar en Sandbox sin dejar huecos</h4>
                          <ol class="mb-0" style="color:#d9f4ff; line-height:1.8;">
                            <li>Deja el campo <strong>Entorno</strong> en <strong>Sandbox</strong>.</li>
                            <li>Usa <strong>Client ID</strong>, <strong>Secret</strong> y <strong>Webhook ID</strong> de Sandbox.</li>
                            <li>Crea o usa una <strong>Business sandbox account</strong> para la app y una <strong>Personal sandbox account</strong> para comprar.</li>
                            <li>Desde la tienda pública, selecciona un paquete con moneda compatible y elige PayPal.</li>
                            <li>Confirma que se abra la ventana de PayPal Sandbox.</li>
                            <li>Aprueba el pago con la cuenta personal sandbox.</li>
                            <li>Verifica que la orden en la tienda pase de <strong>pendiente</strong> a <strong>pagado</strong> o <strong>enviado</strong> según el tipo de producto.</li>
                            <li>Si el cliente regresa correctamente pero la orden no cambia, revisa primero: URL pública, Webhook ID, eventos suscritos y que el entorno sea el mismo en ambos lados.</li>
                          </ol>
                        </div>
                      </div>

                      <div class="col-12 col-xl-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">9. Cómo pasar a Live sin mezclar credenciales</h4>
                          <ol class="mb-0" style="color:#d9f4ff; line-height:1.8;">
                            <li>Confirma que ya probaste apertura del checkout, retorno, cancelación y webhook en Sandbox.</li>
                            <li>En PayPal Developer cambia al entorno <strong>Live</strong>.</li>
                            <li>Crea una app Live nueva o usa la app Live correcta del negocio.</li>
                            <li>Crea un <strong>webhook Live</strong> nuevo. No reutilices el de Sandbox.</li>
                            <li>Vuelve a este panel y cambia <strong>Entorno</strong> a <strong>Live</strong>.</li>
                            <li>Pega aquí el <strong>Client ID Live</strong>, el <strong>Client Secret Live</strong> y el <strong>Webhook ID Live</strong>.</li>
                            <li>Guarda y prueba una compra real pequeña controlada antes de abrirlo a todos los clientes.</li>
                          </ol>
                        </div>
                      </div>

                      <div class="col-12">
                        <div class="gallery-table-wrap">
                          <h4 class="h5 fw-bold text-info mb-3">10. Errores típicos que bloquean la integración</h4>
                          <ul class="mb-0" style="color:#d9f4ff; line-height:1.8;">
                            <li>Usar <strong>Client ID Sandbox</strong> con <strong>Entorno Live</strong> o viceversa.</li>
                            <li>Crear el webhook pero olvidar copiar el <strong>Webhook ID</strong> a esta tienda.</li>
                            <li>Probar desde un dominio que no es accesible públicamente o que no tiene HTTPS.</li>
                            <li>Pegar una URL del webhook escrita manualmente en vez de copiar la que muestra esta tienda.</li>
                            <li>Probar Live sin haber verificado antes el flujo completo en Sandbox.</li>
                            <li>Suponer que Return URL y Cancel URL reemplazan el webhook. No lo reemplazan: el webhook sigue siendo la vía crítica para sincronización robusta.</li>
                          </ul>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php elseif ($activeTab === 'api-discord'): ?>
            <form method="post">
              <input type="hidden" name="config_section" value="api-discord">
              <div class="config-section-note mb-4 d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3">
                <div>Aquí configuras la conexión general con Discord para esta tienda: guardas el webhook, defines cómo se enviarán los mensajes de prueba y verificas que el canal reciba comandos seguros de precio. Después, en la sección de juegos, cada juego decidirá si usa <strong>Juegos API TiendaGiftVen</strong> o <strong>Juegos API Discord</strong>.</div>
                <button type="button" class="btn btn-outline-info btn-sm px-4 py-2 align-self-start align-self-lg-center" data-bs-toggle="modal" data-bs-target="#api-discord-docs-modal">Documentación</button>
              </div>

              <div class="gallery-table-wrap mb-4">
                <h3 class="h5 fw-bold text-info mb-3">Conexión general del webhook</h3>
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label">Webhook de Discord</label>
                    <input type="url" name="api_discord_webhook_url" value="<?= htmlspecialchars($cfg['api_discord_webhook_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://discord.com/api/webhooks/...">
                    <div class="form-text mt-2">Coloca aquí el webhook del canal donde escucha Mobentas. Sin esta URL no se pueden enviar mensajes de prueba ni futuros comandos automáticos.</div>
                    <div class="form-text mt-2">Si abres ese enlace en el navegador, Discord devuelve metadatos como <strong>id</strong>, <strong>channel_id</strong>, <strong>guild_id</strong>, <strong>name</strong>, <strong>type</strong>, <strong>application_id</strong>, <strong>avatar</strong>, <strong>token</strong> y <strong>url</strong>. La tienda no necesita esos valores por separado: con la URL completa del webhook es suficiente para enviar mensajes.</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Timeout HTTP (segundos)</label>
                    <input type="number" min="3" max="30" step="1" name="api_discord_timeout" value="<?= htmlspecialchars($cfg['api_discord_timeout'] ?? '10', ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Nombre visible del mensaje</label>
                    <input type="text" name="api_discord_username" value="<?= htmlspecialchars($cfg['api_discord_username'] ?? 'VirtualGaming API', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="VirtualGaming API">
                    <div class="form-text mt-2">Opcional. Este valor no sale del JSON del webhook: se envía en cada POST como <code>username</code> para cambiar el nombre visible del mensaje. Discord no permite usar la palabra Discord dentro de este nombre.</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Avatar del mensaje (opcional)</label>
                    <input type="url" name="api_discord_avatar_url" value="<?= htmlspecialchars($cfg['api_discord_avatar_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://.../avatar.png">
                    <div class="form-text mt-2">Opcional. Este valor tampoco viene del JSON del webhook: se envía en cada POST como <code>avatar_url</code> para personalizar la imagen del mensaje.</div>
                  </div>
                  <div class="col-12">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" role="switch" id="api-discord-dry-run" name="api_discord_dry_run" value="1" <?= ($cfg['api_discord_dry_run'] ?? '1') === '1' ? 'checked' : '' ?>>
                      <label class="form-check-label fw-semibold" for="api-discord-dry-run">Mantener modo preventivo (bloquea recargas reales y solo permite pruebas seguras de precio)</label>
                    </div>
                    <div class="form-text mt-2">Dejalo activo mientras estes validando conexion y respuestas del canal. Asi la tienda solo enviara comandos de consulta de precios y evitara disparar recargas reales por accidente. Para que las recargas Discord salgan automaticas, desactivalo y vuelve a guardar.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">URL del listener de Discord</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($apiDiscordListenerUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                    <div class="form-text mt-2">Este endpoint queda listo para que un relay o bot externo envíe correlaciones de Discord usando el token secreto.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Token secreto del listener</label>
                    <input type="text" name="api_discord_listener_token" value="<?= htmlspecialchars($apiDiscordListenerToken, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Se genera automáticamente al guardar si está vacío">
                    <div class="form-text mt-2">Compártelo solo con el servicio que vaya a reportar confirmaciones del canal. Si lo dejas vacío al guardar por primera vez, el sistema generará uno automáticamente.</div>
                  </div>
                </div>
              </div>

              <div class="gallery-table-wrap mb-2">
                <h3 class="h5 fw-bold text-info mb-3">Prueba segura del webhook</h3>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Comando de prueba</label>
                    <select class="form-select" name="api_discord_probe_command" id="api-discord-probe-command">
                      <?php foreach ($apiDiscordPriceCommands as $priceCommand): ?>
                        <?php $probeKey = (string) ($priceCommand['key'] ?? ''); ?>
                        <?php $probeSample = api_discord_sample_command_text($priceCommand); ?>
                        <option value="<?= htmlspecialchars($probeKey, ENT_QUOTES, 'UTF-8') ?>" data-sample="<?= htmlspecialchars($probeSample, ENT_QUOTES, 'UTF-8') ?>" <?= $apiDiscordSelectedProbeKey === $probeKey ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string) ($priceCommand['label'] ?? $probeKey), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="form-text mt-2">Aquí solo aparecen comandos de precio. Sirven para comprobar que el webhook publica en el canal correcto y que Mobentas puede responder sin ejecutar recargas reales.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Vista previa del comando</label>
                    <input type="text" id="api-discord-probe-preview" class="form-control" value="<?= htmlspecialchars($apiDiscordSelectedProbeSample, ENT_QUOTES, 'UTF-8') ?>" readonly>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Catálogo de comandos detectado</label>
                    <input type="text" class="form-control" value="<?= count(api_discord_load_commands()) ?> comandos cargados desde includes/api_discord_commands.json" readonly>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Comandos listos para asignar a juegos</label>
                    <input type="text" class="form-control" value="<?= count(api_discord_topup_commands()) ?> comandos de juego listos para el select Juegos API Discord" readonly>
                  </div>
                </div>
              </div>

              <div class="d-flex flex-column flex-md-row gap-3 mt-4">
                <button type="submit" class="neon-btn flex-fill py-3">Guardar configuración API Discord</button>
                <button type="submit" name="api_discord_probe_submit" value="1" class="neon-btn flex-fill py-3" style="background:linear-gradient(90deg,#34d399 0%,#22d3ee 100%);">Guardar y enviar prueba webhook</button>
              </div>
            </form>
            <div class="modal fade" id="api-discord-docs-modal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content" style="background:#181f2a; border:2px solid #00fff7; color:#e6fbff; box-shadow:0 0 24px #00fff733;">
                  <div class="modal-header" style="border-bottom:1px solid rgba(0,255,247,0.25);">
                    <div>
                      <h3 class="modal-title h4 fw-bold text-info mb-1">Documentación API Discord</h3>
                      <p class="mb-0" style="color:#b2f6ff;">Contrato operativo para el relay o bot externo que reporta el resultado de Discord a la tienda.</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                  </div>
                  <div class="modal-body">
                    <div class="row g-4">
                      <div class="col-12">
                        <div class="gallery-table-wrap">
                          <h4 class="h5 fw-bold text-info mb-3">0. Qué significa el JSON del webhook</h4>
                          <div class="table-responsive">
                            <table class="table table-dark table-sm align-middle mb-0">
                              <thead>
                                <tr>
                                  <th>Campo</th>
                                  <th>Qué representa</th>
                                  <th>Se usa en la tienda</th>
                                </tr>
                              </thead>
                              <tbody>
                                <tr><td>id</td><td>ID interno del webhook en Discord.</td><td>No se guarda por separado; ya viene dentro de la URL completa.</td></tr>
                                <tr><td>token</td><td>Secreto del webhook que autoriza publicar mensajes.</td><td>No se separa en otro campo; ya forma parte de la URL. Trátalo como credencial sensible.</td></tr>
                                <tr><td>url</td><td>URL completa lista para enviar <code>POST</code>.</td><td>Sí. Este es el valor que debes pegar en <strong>Webhook de Discord</strong>.</td></tr>
                                <tr><td>channel_id</td><td>ID del canal de Discord al que publica el webhook.</td><td>No. Solo sirve como dato descriptivo.</td></tr>
                                <tr><td>guild_id</td><td>ID del servidor de Discord donde vive el canal.</td><td>No. Solo sirve como dato descriptivo.</td></tr>
                                <tr><td>name</td><td>Nombre actual del webhook creado en Discord.</td><td>No. La tienda puede sobrescribir el nombre visible de cada mensaje con <code>username</code>.</td></tr>
                                <tr><td>avatar</td><td>Avatar base configurado en el webhook dentro de Discord.</td><td>No. La tienda puede sobrescribir la imagen del mensaje con <code>avatar_url</code>.</td></tr>
                                <tr><td>type</td><td>Tipo de webhook que devuelve Discord.</td><td>No.</td></tr>
                                <tr><td>application_id</td><td>ID de aplicación asociada si el webhook pertenece a una app o integración.</td><td>No. Puede venir <code>null</code> en webhooks normales.</td></tr>
                              </tbody>
                            </table>
                          </div>
                          <div class="form-text mt-3">Resumen práctico: para esta integración solo hace falta la <strong>URL completa del webhook</strong>. Luego la tienda envía JSON tipo <code>{"content":"...","username":"...","avatar_url":"..."}</code> cuando publica mensajes.</div>
                        </div>
                      </div>
                      <div class="col-12 col-lg-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">1. Endpoint y autenticación</h4>
                          <div class="mb-3">
                            <label class="form-label">URL del listener</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($apiDiscordListenerUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
                          </div>
                          <div class="mb-3">
                            <label class="form-label">Método</label>
                            <input type="text" class="form-control" value="POST" readonly>
                          </div>
                          <div class="mb-3">
                            <label class="form-label">Header recomendado</label>
                            <input type="text" class="form-control" value="Authorization: Bearer <?= htmlspecialchars($apiDiscordListenerExampleToken, ENT_QUOTES, 'UTF-8') ?>" readonly>
                          </div>
                          <div class="form-text mt-2">Headers aceptados: <strong>Authorization: Bearer TOKEN</strong>, <strong>X-Discord-Listener-Token: TOKEN</strong> o <strong>X-API-Discord-Token: TOKEN</strong>. El token en body se admite por compatibilidad, pero no es la vía recomendada.</div>
                        </div>
                      </div>
                      <div class="col-12 col-lg-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">2. Campos del payload</h4>
                          <div class="table-responsive">
                            <table class="table table-dark table-sm align-middle mb-0">
                              <thead>
                                <tr>
                                  <th>Campo</th>
                                  <th>Obligatorio</th>
                                  <th>Uso</th>
                                </tr>
                              </thead>
                              <tbody>
                                <tr><td>status</td><td>Sí</td><td>Estado Discord: <code>queued</code>, <code>sent</code>, <code>processing</code>, <code>confirmed</code>, <code>review</code>, <code>failed</code> o <code>cancelled</code>.</td></tr>
                                <tr><td>order_id</td><td>Sí, si lo conoces</td><td>ID local del pedido. Si no lo tienes, usa <code>source_message_id</code> o <code>message_id</code>.</td></tr>
                                <tr><td>source_message_id</td><td>Sí, si no envías <code>order_id</code></td><td>Message ID de Discord guardado por la tienda para correlacionar la orden.</td></tr>
                                <tr><td>provider_message</td><td>No</td><td>Detalle legible para admin y cliente. Si no se envía, la tienda usa el mensaje por defecto del estado.</td></tr>
                                <tr><td>requires_review</td><td>No</td><td>Fuerza bandera de revisión manual. Si se omite, la tienda la infiere para <code>review</code> y <code>failed</code>.</td></tr>
                                <tr><td>http_status</td><td>No</td><td>Código HTTP o estado técnico del relay si deseas guardarlo en el tracking.</td></tr>
                                <tr><td>local_status</td><td>No</td><td>Sobrescribe el estado local sólo si necesitas forzarlo. Por defecto, <code>confirmed</code> pasa a <code>enviado</code> y <code>cancelled</code> a <code>cancelado</code>.</td></tr>
                              </tbody>
                            </table>
                          </div>
                        </div>
                      </div>
                      <div class="col-12">
                        <div class="gallery-table-wrap">
                          <h4 class="h5 fw-bold text-info mb-3">3. Ejemplos JSON</h4>
                          <div class="row g-3">
                            <div class="col-12 col-lg-6">
                              <label class="form-label">Confirmación exitosa</label>
                              <pre class="form-control" style="height:auto; min-height:220px; white-space:pre-wrap; background:#0f172a; color:#e2f8ff;">{
  "order_id": 1234,
  "status": "confirmed",
  "source_message_id": "1369458123456789012",
  "provider_message": "Recarga confirmada por el bot",
  "requires_review": 0,
  "http_status": 200
}</pre>
                            </div>
                            <div class="col-12 col-lg-6">
                              <label class="form-label">Caso de revisión manual</label>
                              <pre class="form-control" style="height:auto; min-height:220px; white-space:pre-wrap; background:#0f172a; color:#e2f8ff;">{
  "source_message_id": "1369458123456789012",
  "status": "review",
  "provider_message": "El bot pide validación manual",
  "requires_review": 1,
  "http_status": 202
}</pre>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div class="col-12 col-lg-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">4. Ejemplo cURL</h4>
                          <pre class="form-control" style="height:auto; min-height:240px; white-space:pre-wrap; background:#0f172a; color:#e2f8ff;">curl -X POST "<?= htmlspecialchars($apiDiscordListenerUrl, ENT_QUOTES, 'UTF-8') ?>" \
  -H "Authorization: Bearer <?= htmlspecialchars($apiDiscordListenerExampleToken, ENT_QUOTES, 'UTF-8') ?>" \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1234,
    "status": "confirmed",
    "source_message_id": "1369458123456789012",
    "provider_message": "Recarga confirmada por el bot"
  }'</pre>
                          <div class="form-text mt-2">El relay puede usar también <strong>message_id</strong> o <strong>discord_message_id</strong> como alias de correlación.</div>
                        </div>
                      </div>
                      <div class="col-12 col-lg-6">
                        <div class="gallery-table-wrap h-100">
                          <h4 class="h5 fw-bold text-info mb-3">5. Reglas operativas y seguridad</h4>
                          <ul class="mb-0" style="color:#d9faff; line-height:1.7;">
                            <li><strong>Para enviar recargas automaticamente</strong> basta con webhook valido, comando/template correcto por juego y <strong>modo preventivo desactivado</strong>.</li>
                            <li><strong>confirmed</strong> mueve la orden a <strong>enviado</strong> y dispara notificaciones de éxito si la orden no había sido cerrada aún.</li>
                            <li><strong>cancelled</strong> mueve la orden a <strong>cancelado</strong> y dispara la notificación de cancelación sólo en la primera transición.</li>
                            <li><strong>review</strong> y <strong>failed</strong> dejan la orden pagada con revisión manual activa.</li>
                            <li><strong>Para cerrar la orden automaticamente como TiendaGiftVen</strong>, el bot o relay externo debe avisar a este listener con estados como <strong>confirmed</strong>, <strong>review</strong>, <strong>failed</strong> o <strong>cancelled</strong>.</li>
                            <li>Guarda y comparte el token sólo con el relay. Si se expone, genera uno nuevo desde este panel y vuelve a desplegar el bot.</li>
                            <li>Usa siempre HTTPS y headers para el token. Evita enviarlo en el body salvo compatibilidad temporal.</li>
                            <li>Si el relay no responde, el admin puede actualizar manualmente el estado Discord desde el módulo de pedidos.</li>
                          </ul>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <script>
              (function() {
                const select = document.getElementById('api-discord-probe-command');
                const preview = document.getElementById('api-discord-probe-preview');
                if (!select || !preview) {
                  return;
                }

                const updatePreview = function() {
                  const option = select.options[select.selectedIndex];
                  preview.value = option ? (option.getAttribute('data-sample') || '') : '';
                };

                select.addEventListener('change', updatePreview);
                updatePreview();
              })();
            </script>
          <?php elseif ($activeTab === 'personalizar-colores'): ?>
            <form method="post">
              <input type="hidden" name="config_section" value="personalizar-colores">
              <?php if (!$topBarFeatureEnabled): ?>
                <input type="hidden" name="theme_topbar_bg" value="<?= htmlspecialchars($themeValues['theme_topbar_bg'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_topbar_text" value="<?= htmlspecialchars($themeValues['theme_topbar_text'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_topbar_search_border" value="<?= htmlspecialchars($themeValues['theme_topbar_search_border'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_topbar_search_bg" value="<?= htmlspecialchars($themeValues['theme_topbar_search_bg'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_topbar_search_text" value="<?= htmlspecialchars($themeValues['theme_topbar_search_text'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_topbar_login_bg" value="<?= htmlspecialchars($themeValues['theme_topbar_login_bg'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_topbar_login_border" value="<?= htmlspecialchars($themeValues['theme_topbar_login_border'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_topbar_login_text" value="<?= htmlspecialchars($themeValues['theme_topbar_login_text'], ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
              <?php if (!$accountSaleFeatureEnabled): ?>
                <input type="hidden" name="theme_account_preview_button_bg" value="<?= htmlspecialchars($themeValues['theme_account_preview_button_bg'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_account_preview_button_border" value="<?= htmlspecialchars($themeValues['theme_account_preview_button_border'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_account_preview_button_text" value="<?= htmlspecialchars($themeValues['theme_account_preview_button_text'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_account_preview_button_shadow" value="<?= htmlspecialchars($themeValues['theme_account_preview_button_shadow'], ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
              <?php if (!$paymentHeaderMinimalEnabled): ?>
                <input type="hidden" name="theme_package_feature_bg" value="<?= htmlspecialchars($themeValues['theme_package_feature_bg'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_package_feature_border" value="<?= htmlspecialchars($themeValues['theme_package_feature_border'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="theme_package_feature_text" value="<?= htmlspecialchars($themeValues['theme_package_feature_text'], ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
              <?php if (!$paymentWindowConfigEnabled): ?>
                <?php foreach ($paymentWindowThemeKeys as $paymentWindowThemeKey): ?>
                  <input type="hidden" name="<?= htmlspecialchars($paymentWindowThemeKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($themeValues[$paymentWindowThemeKey], ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
              <?php endif; ?>
              <?php if (!$paymentDifferenceConfigEnabled): ?>
                <?php foreach ($paymentDifferenceThemeKeys as $paymentDifferenceThemeKey): ?>
                  <input type="hidden" name="<?= htmlspecialchars($paymentDifferenceThemeKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($themeValues[$paymentDifferenceThemeKey], ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
              <?php endif; ?>
              <div class="config-section-note mb-4">Los valores `theme_*` quedan como base fija. Aquí solo editas una copia activa de esa paleta. Si el cliente quiere volver al diseño original, puedes restaurar la copia editable desde los valores base.</div>
              <div class="theme-accordion" data-theme-accordion>
                <?php foreach ($themeFieldGroups as $groupTitle => $groupKeys): ?>
                  <div class="theme-accordion-item" data-theme-accordion-item data-theme-accordion-key="<?= htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" class="theme-accordion-trigger" data-theme-accordion-trigger aria-expanded="false">
                      <span class="theme-accordion-label">
                        <span class="theme-accordion-title"><?= htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="theme-accordion-copy"><?= count($groupKeys) ?> colores disponibles en esta sección.</span>
                      </span>
                    </button>
                    <div class="theme-accordion-panel" data-theme-accordion-panel>
                      <div class="row g-3">
                        <?php foreach ($groupKeys as $themeKey): ?>
                          <?php $definition = $themeDefinitions[$themeKey]; ?>
                          <div class="col-md-6 col-xl-4">
                            <div class="theme-swatch-card">
                              <div class="theme-swatch-preview mb-3" style="background: <?= htmlspecialchars($themeValues[$themeKey], ENT_QUOTES, 'UTF-8') ?>;"></div>
                              <label class="form-label fw-semibold"><?= htmlspecialchars($definition['label'], ENT_QUOTES, 'UTF-8') ?></label>
                              <input type="color" name="<?= htmlspecialchars($themeKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($themeValues[$themeKey], ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-color mb-2">
                              <div class="small text-info mb-1">Editable: <?= htmlspecialchars($themeValues[$themeKey], ENT_QUOTES, 'UTF-8') ?></div>
                              <div class="theme-default-note mb-2">Base fija: <?= htmlspecialchars($themeBaseValues[$themeKey], ENT_QUOTES, 'UTF-8') ?></div>
                              <div class="form-text"><?= htmlspecialchars($definition['description'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                        <?php if ($paymentWindowConfigEnabled && $groupTitle === 'Enviando orden'): ?>
                          <div class="col-12">
                            <div class="theme-swatch-card">
                              <label class="form-label fw-semibold">Texto principal del modal</label>
                              <input type="text" name="ventana_pago_enviando_titulo" value="<?= htmlspecialchars($paymentWindowSendingTitle, ENT_QUOTES, 'UTF-8') ?>" class="form-control mb-3" maxlength="120" placeholder="Enviando orden...">
                              <div class="form-text mb-3">Este texto reemplaza el título del modal mostrado mientras se envía la orden.</div>
                              <label class="form-label fw-semibold">Texto explicativo debajo del título</label>
                              <textarea name="ventana_pago_enviando_mensaje" class="form-control" rows="3" maxlength="500" placeholder="Explica aquí qué está ocurriendo mientras se procesa la orden."><?= htmlspecialchars($paymentWindowSendingMessage, ENT_QUOTES, 'UTF-8') ?></textarea>
                              <div class="form-text">Este texto se mostrará debajo del título del modal Enviando orden.</div>
                            </div>
                          </div>
                        <?php endif; ?>
                        <?php if ($paymentWindowConfigEnabled && $groupTitle === 'Pago exitoso y estado final'): ?>
                          <div class="col-12">
                            <div class="theme-swatch-card">
                              <label class="form-label fw-semibold">Título cuando el pago es exitoso</label>
                              <input type="text" name="ventana_pago_exitoso_titulo" value="<?= htmlspecialchars($paymentWindowSuccessTitle, ENT_QUOTES, 'UTF-8') ?>" class="form-control mb-3" maxlength="120" placeholder="Pago exitoso">
                              <div class="form-text mb-3">Este texto reemplaza el título del modal final cuando la operación termina con éxito.</div>
                              <label class="form-label fw-semibold">Texto adicional debajo del mensaje principal</label>
                              <textarea name="ventana_pago_exitoso_mensaje_extra" class="form-control" rows="3" maxlength="500" placeholder="Agrega aquí un texto complementario opcional para el cliente."><?= htmlspecialchars($paymentWindowSuccessExtraMessage, ENT_QUOTES, 'UTF-8') ?></textarea>
                              <div class="form-text">Este texto aparecerá debajo del mensaje principal del modal final solo cuando el pago sea exitoso.</div>
                            </div>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="theme-action-row">
                <button type="submit" class="neon-btn py-3">Guardar paleta de colores</button>
                <button type="submit" name="restore_theme_defaults" value="1" class="btn theme-reset-btn" onclick="return confirm('Esto reemplazará la paleta editable actual por los valores base. ¿Deseas continuar?');">Restaurar a default</button>
              </div>
            </form>
            <script>
              (function () {
                const accordion = document.querySelector('[data-theme-accordion]');
                if (!accordion) {
                  return;
                }

                const items = Array.from(accordion.querySelectorAll('[data-theme-accordion-item]'));
                const storageKey = 'theme-color-accordion-open:' + window.location.pathname;
                const closeAllItems = () => {
                  items.forEach((item) => {
                    const trigger = item.querySelector('[data-theme-accordion-trigger]');
                    item.classList.remove('is-open');
                    if (trigger) {
                      trigger.setAttribute('aria-expanded', 'false');
                    }
                  });
                };
                const setOpenItem = (targetItem) => {
                  closeAllItems();
                  if (!targetItem) {
                    sessionStorage.removeItem(storageKey);
                    return;
                  }

                  const trigger = targetItem.querySelector('[data-theme-accordion-trigger]');
                  targetItem.classList.add('is-open');
                  if (trigger) {
                    trigger.setAttribute('aria-expanded', 'true');
                  }
                  sessionStorage.setItem(storageKey, targetItem.getAttribute('data-theme-accordion-key') || '');
                };

                items.forEach((item) => {
                  const trigger = item.querySelector('[data-theme-accordion-trigger]');
                  if (!trigger) {
                    return;
                  }

                  trigger.addEventListener('click', function () {
                    if (item.classList.contains('is-open')) {
                      setOpenItem(null);
                      return;
                    }
                    setOpenItem(item);
                  });
                });

                const savedKey = sessionStorage.getItem(storageKey);
                if (!savedKey) {
                  closeAllItems();
                  return;
                }

                const savedItem = items.find((item) => item.getAttribute('data-theme-accordion-key') === savedKey);
                if (savedItem) {
                  setOpenItem(savedItem);
                } else {
                  closeAllItems();
                }
              }());
            </script>
          <?php elseif ($activeTab === 'ventana-inicial'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="config_section" value="ventana-inicial">
              <div class="config-section-note mb-4">Controla la ventana emergente inicial del index. Puedes mostrar la ventana normal, la ventana con video o ninguna, pero nunca ambas al mismo tiempo. El botón principal usa automáticamente el enlace configurado en Redes Sociales, en el campo Whatsapp Channel.</div>
              <div class="row g-4 align-items-start">
                <div class="col-lg-7">
                  <div class="gallery-table-wrap">
                    <div class="mb-4">
                      <label class="form-label d-block">Tipo de ventana inicial</label>
                      <div class="d-grid gap-3">
                        <label class="rounded-4 border p-3" style="border-color: rgba(34, 211, 238, 0.24); background: rgba(15, 23, 42, 0.48);">
                          <div class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="inicio_popup_modo" id="inicioPopupModeNone" value="none" <?= $startupPopupMode === 'none' ? 'checked' : '' ?>>
                            <span class="form-check-label fw-semibold">No mostrar ninguna ventana inicial</span>
                          </div>
                        </label>
                        <?php if ($startupPopupNormalEnabled): ?>
                          <label class="rounded-4 border p-3" style="border-color: rgba(34, 211, 238, 0.24); background: rgba(15, 23, 42, 0.48);">
                            <div class="form-check mb-0">
                              <input class="form-check-input" type="radio" name="inicio_popup_modo" id="inicioPopupModeNormal" value="normal" <?= $startupPopupMode === 'normal' ? 'checked' : '' ?>>
                              <span class="form-check-label fw-semibold">Mostrar ventana inicial normal</span>
                            </div>
                          </label>
                        <?php endif; ?>
                        <?php if ($startupPopupVideoEnabled): ?>
                          <label class="rounded-4 border p-3" style="border-color: rgba(34, 211, 238, 0.24); background: rgba(15, 23, 42, 0.48);">
                            <div class="form-check mb-0">
                              <input class="form-check-input" type="radio" name="inicio_popup_modo" id="inicioPopupModeVideo" value="video" <?= $startupPopupMode === 'video' ? 'checked' : '' ?>>
                              <span class="form-check-label fw-semibold">Mostrar ventana inicial con video</span>
                            </div>
                            <div class="form-text mt-2">Esta opción solo puede activarse cuando el enlace de YouTube esté completo y válido.</div>
                          </label>
                        <?php endif; ?>
                        <?php if ($startupPopupGalleryEnabled): ?>
                          <label class="rounded-4 border p-3" style="border-color: rgba(34, 211, 238, 0.24); background: rgba(15, 23, 42, 0.48);">
                            <div class="form-check mb-0">
                              <input class="form-check-input" type="radio" name="inicio_popup_modo" id="inicioPopupModeGallery" value="gallery" <?= $startupPopupMode === 'gallery' ? 'checked' : '' ?>>
                              <span class="form-check-label fw-semibold">Mostrar ventana inicial de galería</span>
                            </div>
                            <div class="form-text mt-2">Usa una o varias imágenes en formato cover dentro de una ventana fija con controles debajo.</div>
                          </label>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="mb-4">
                      <label class="form-label">Nombre del canal</label>
                      <input type="text" name="inicio_popup_nombre_canal" value="<?= htmlspecialchars($cfg['inicio_popup_nombre_canal'] ?? 'DanisA Gamer Store', ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="DanisA Gamer Store">
                      <div class="form-text">Este nombre se usa en la ventana inicial normal.</div>
                    </div>
                    <?php if ($startupPopupVideoEnabled): ?>
                      <div class="mb-4">
                        <label class="form-label">Enlace de YouTube para la ventana con video</label>
                        <input type="url" name="inicio_popup_video_url" id="inicioPopupVideoUrl" value="<?= htmlspecialchars($startupPopupVideoUrl, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="https://www.youtube.com/shorts/...">
                        <div class="form-text">Acepta enlaces de YouTube Shorts, watch, embed o youtu.be. Si este campo está vacío, la ventana con video no puede seleccionarse.</div>
                      </div>
                    <?php endif; ?>
                    <?php if ($startupPopupGalleryEnabled): ?>
                      <div class="mb-4">
                        <label class="form-label">Imágenes de la galería</label>
                        <div class="startup-popup-gallery-manager" data-startup-popup-gallery-manager>
                          <input type="file" name="inicio_popup_galeria_imagenes[]" id="startupPopupGalleryInput" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                          <div class="startup-popup-gallery-upload-row">
                            <button type="button" class="neon-btn startup-popup-gallery-upload-btn" id="startupPopupGalleryUploadButton">Subir imágenes seleccionadas</button>
                            <div class="startup-popup-gallery-status" id="startupPopupGalleryStatus">Puedes seleccionar imágenes de distintas carpetas en tandas separadas y subirlas una a una sin perder las que ya estén cargadas.</div>
                          </div>
                          <div>
                            <label class="form-label d-block">Imágenes actuales</label>
                            <div class="startup-popup-gallery-grid" id="startupPopupGalleryGrid" data-endpoint="<?= htmlspecialchars(app_path('/admin/configuracion?tab=ventana-inicial'), ENT_QUOTES, 'UTF-8') ?>" data-count-target="startupPopupGallerySummaryCount">
                              <?php foreach ($startupPopupGalleryImages as $galleryIndex => $galleryImage): ?>
                                <?php
                                  $galleryImagePath = trim((string) ($galleryImage['path'] ?? ''));
                                  $galleryLinkUrl = trim((string) ($galleryImage['link_url'] ?? ''));
                                  $galleryLinkTarget = trim((string) ($galleryImage['link_target'] ?? '_self')) === '_blank' ? '_blank' : '_self';
                                ?>
                                <div class="startup-popup-gallery-card" data-gallery-path="<?= htmlspecialchars($galleryImagePath, ENT_QUOTES, 'UTF-8') ?>">
                                  <button type="button" class="startup-popup-gallery-delete" data-gallery-action="delete" data-gallery-path="<?= htmlspecialchars($galleryImagePath, ENT_QUOTES, 'UTF-8') ?>" aria-label="Eliminar imagen">X</button>
                                  <div class="startup-popup-gallery-thumb">
                                    <img src="<?= htmlspecialchars($galleryImagePath, ENT_QUOTES, 'UTF-8') ?>" alt="Imagen <?= $galleryIndex + 1 ?> de la galería inicial">
                                  </div>
                                  <div class="startup-popup-gallery-meta">
                                    <div class="startup-popup-gallery-name"><?= htmlspecialchars(basename($galleryImagePath), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="startup-popup-gallery-controls">
                                      <span class="startup-popup-gallery-order">Orden <?= $galleryIndex + 1 ?></span>
                                      <div class="startup-popup-gallery-move-group">
                                        <button type="button" class="startup-popup-gallery-move" data-gallery-action="move" data-gallery-direction="left" data-gallery-path="<?= htmlspecialchars($galleryImagePath, ENT_QUOTES, 'UTF-8') ?>" aria-label="Mover a la izquierda">←</button>
                                        <button type="button" class="startup-popup-gallery-move" data-gallery-direction="right" data-gallery-action="move" data-gallery-path="<?= htmlspecialchars($galleryImagePath, ENT_QUOTES, 'UTF-8') ?>" aria-label="Mover a la derecha">→</button>
                                      </div>
                                    </div>
                                    <div class="startup-popup-gallery-link-fields">
                                      <input type="text" class="form-control form-control-sm startup-popup-gallery-link-input" data-gallery-link-url placeholder="https://... o /ruta-interna" value="<?= htmlspecialchars($galleryLinkUrl, ENT_QUOTES, 'UTF-8') ?>">
                                      <select class="form-select form-select-sm startup-popup-gallery-target-select" data-gallery-link-target>
                                        <option value="_self" <?= $galleryLinkTarget === '_self' ? 'selected' : '' ?>>Abrir en la misma pestaña</option>
                                        <option value="_blank" <?= $galleryLinkTarget === '_blank' ? 'selected' : '' ?>>Abrir en una pestaña nueva</option>
                                      </select>
                                    </div>
                                    <button type="button" class="startup-popup-gallery-save" data-gallery-action="update-meta" data-gallery-path="<?= htmlspecialchars($galleryImagePath, ENT_QUOTES, 'UTF-8') ?>">Guardar enlace</button>
                                    <div class="startup-popup-gallery-link-hint">Opcional. Acepta `https://...`, `http://...` o una ruta interna que empiece por `/`.</div>
                                  </div>
                                </div>
                              <?php endforeach; ?>
                            </div>
                            <div class="startup-popup-gallery-empty<?= !empty($startupPopupGalleryImages) ? ' d-none' : '' ?>" id="startupPopupGalleryEmpty">Aún no hay imágenes cargadas en la galería de la ventana inicial.</div>
                          </div>
                        </div>
                        <div class="form-text">La ventana usa tamaño fijo y cada imagen se adapta con cover para llenar todo el espacio. Usa el botón Subir para agregar nuevas tandas desde distintas carpetas.</div>
                      </div>
                    <?php endif; ?>
                    <div>
                      <label class="form-label">Frecuencia de aparición</label>
                      <select name="inicio_popup_frecuencia" class="form-select">
                        <option value="always" <?= ($cfg['inicio_popup_frecuencia'] ?? 'per_session') === 'always' ? 'selected' : '' ?>>Siempre que se navegue en el inicio</option>
                        <option value="per_entry" <?= ($cfg['inicio_popup_frecuencia'] ?? 'per_session') === 'per_entry' ? 'selected' : '' ?>>1 vez cada vez que se entre a la tienda</option>
                        <option value="per_session" <?= ($cfg['inicio_popup_frecuencia'] ?? 'per_session') === 'per_session' ? 'selected' : '' ?>>1 vez por sesion</option>
                      </select>
                    </div>
                  </div>
                </div>
                <div class="col-lg-5">
                  <div class="config-section-note h-100">
                    <div class="fw-semibold text-info mb-2">Resumen</div>
                    <div class="small">Modo seleccionado: <?php if ($startupPopupMode === 'normal'): ?>Ventana normal<?php elseif ($startupPopupMode === 'video'): ?>Ventana con video<?php elseif ($startupPopupMode === 'gallery'): ?>Ventana de galería<?php else: ?>Ninguna<?php endif; ?>.</div>
                    <div class="small mt-2">Modos disponibles: <?= htmlspecialchars(implode(', ', array_values($startupPopupAvailableModes)), ENT_QUOTES, 'UTF-8') ?>.</div>
                    <div class="small mt-2">Canal mostrado: <?= htmlspecialchars($cfg['inicio_popup_nombre_canal'] ?? 'DanisA Gamer Store', ENT_QUOTES, 'UTF-8') ?>.</div>
                    <div class="small mt-2">Enlace del canal: <?= $startupPopupChannelReady ? htmlspecialchars($startupPopupChannelUrl, ENT_QUOTES, 'UTF-8') : 'No configurado aún en Redes Sociales' ?></div>
                    <?php if ($startupPopupVideoEnabled): ?>
                      <div class="small mt-2">Video de YouTube: <?= $startupPopupVideoUrl !== '' ? htmlspecialchars($startupPopupVideoUrl, ENT_QUOTES, 'UTF-8') : 'No configurado' ?></div>
                    <?php endif; ?>
                    <?php if ($startupPopupGalleryEnabled): ?>
                      <div class="small mt-2">Imágenes en galería: <span id="startupPopupGallerySummaryCount"><?= count($startupPopupGalleryImages) ?></span></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <button type="submit" class="neon-btn w-100 py-3 mt-4">Guardar ventana inicial</button>
            </form>
          <?php elseif ($activeTab === 'galeria'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="config_section" value="galeria">
              <input type="hidden" name="gallery_id" value="<?= $galleryEditItem ? (int) $galleryEditItem['id'] : 0 ?>">
              <div class="config-section-note mb-4">Administra el slider principal del index. Si marcas un elemento como destacado, también aparecerá en el bloque inferior y se desmarcará cualquier otro destacado existente. Recomendación: sube imágenes en tamaño 1280x500px para obtener el mejor resultado tanto en desktop como en responsive.</div>
              <div class="row g-4 align-items-start">
                <div class="col-12">
                  <label class="form-label d-block">Vista previa de imagen</label>
                  <div class="gallery-image-preview mb-2" id="gallery-image-preview" data-original-src="<?= htmlspecialchars($galleryForm['imagen'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($galleryForm['imagen'] !== ''): ?>
                      <img src="<?= htmlspecialchars($galleryForm['imagen'], ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa de galería" id="gallery-image-preview-img">
                    <?php else: ?>
                      <span class="gallery-image-empty" id="gallery-image-preview-empty">Sin imagen</span>
                    <?php endif; ?>
                  </div>
                  <div class="form-text">La vista previa usa proporción 1280x500 para acercarse a cómo se verá en el inicio.</div>
                </div>
                <div class="col-lg-8">
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label">Título</label>
                      <input type="text" name="titulo" value="<?= htmlspecialchars($galleryForm['titulo']) ?>" class="form-control" placeholder="Bienvenida">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Descripción 1</label>
                      <input type="text" name="descripcion1" value="<?= htmlspecialchars($galleryForm['descripcion1']) ?>" class="form-control" placeholder="+10% en tu primera compra">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Descripción 2</label>
                      <input type="text" name="descripcion2" value="<?= htmlspecialchars($galleryForm['descripcion2']) ?>" class="form-control" placeholder="Usa el código START10">
                    </div>
                    <div class="col-md-7">
                      <label class="form-label">URL</label>
                      <input type="url" name="url" value="<?= htmlspecialchars($galleryForm['url']) ?>" class="form-control" placeholder="https://tusitio.com/promocion">
                      <div class="form-text">Si la dejas vacía, la imagen no tendrá enlace.</div>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label">Comportamiento del enlace</label>
                      <select name="abrir_nueva_pestana" class="form-select">
                        <option value="0" <?= !$galleryForm['abrir_nueva_pestana'] ? 'selected' : '' ?>>Abrir en la misma página</option>
                        <option value="1" <?= $galleryForm['abrir_nueva_pestana'] ? 'selected' : '' ?>>Abrir en otra pestaña</option>
                      </select>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Imagen</label>
                      <input type="file" name="imagen" id="gallery-image-input" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control" <?= $galleryEditItem ? '' : 'required' ?>>
                      <div class="form-text">Formatos permitidos: JPG, PNG, WEBP o GIF. Tamaño máximo: 4 MB. Tamaño recomendado: 1280x500px.</div>
                    </div>
                    <div class="col-12">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="destacadoGaleria" name="destacado" <?= $galleryForm['destacado'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="destacadoGaleria">Marcar como destacado</label>
                      </div>
                      <div class="form-text mt-2">El orden se asigna automáticamente al crear. Luego puedes ajustarlo directamente en la tabla inferior.</div>
                    </div>
                  </div>
                </div>
                <div class="col-lg-4">
                  <?php if ($galleryEditItem): ?>
                    <a href="/admin/configuracion?tab=galeria" class="btn btn-outline-info w-100 rounded-4">Cancelar edición</a>
                  <?php endif; ?>
                </div>
              </div>
              <button type="submit" class="neon-btn w-100 py-3 mt-4"><?= $galleryEditItem ? 'Actualizar elemento de galería' : 'Crear elemento de galería' ?></button>
            </form>

            <div class="mt-5">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <h3 class="h5 fw-bold mb-0 text-info">Elementos registrados</h3>
                <span class="gallery-badge-neon"><?= count($galleryItems) ?> elementos</span>
              </div>
              <?php if (empty($galleryItems)): ?>
                <div class="config-section-note">Aún no hay elementos en la galería. Crea el primero para que aparezca en el slider del index.</div>
              <?php else: ?>
                <div class="gallery-table-wrap d-none d-md-block">
                  <div class="table-responsive">
                    <table class="table table-striped align-middle">
                      <thead>
                        <tr>
                          <th>Imagen</th>
                          <th>Título</th>
                          <th>Textos</th>
                          <th>Orden</th>
                          <th>URL</th>
                          <th>Destino</th>
                          <th>Destacado</th>
                          <th class="text-end">Acciones</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($galleryItems as $item): ?>
                          <tr>
                            <td>
                              <div class="gallery-thumb">
                                <img src="<?= htmlspecialchars($item['imagen'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                              </div>
                            </td>
                            <td class="fw-bold"><?= htmlspecialchars($item['titulo'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                              <div><?= htmlspecialchars($item['descripcion1'], ENT_QUOTES, 'UTF-8') ?></div>
                              <div class="small text-secondary"><?= htmlspecialchars($item['descripcion2'], ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td>
                              <form method="post" class="d-inline-flex align-items-center gap-2 m-0 js-gallery-order-form">
                                <input type="hidden" name="ajax" value="1">
                                <input type="hidden" name="config_section" value="galeria">
                                <input type="hidden" name="gallery_order_update" value="1">
                                <input type="hidden" name="gallery_order_id" value="<?= (int) $item['id'] ?>">
                                <input type="number" name="gallery_order" min="1" value="<?= max(1, (int) ($item['orden'] ?? 0)) ?>" class="form-control form-control-sm text-center js-gallery-order-input" style="width:84px;">
                              </form>
                            </td>
                            <td>
                              <?php if (!empty($item['url'])): ?>
                                <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="text-info text-break"><?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?></a>
                              <?php else: ?>
                                <span class="text-secondary">Sin URL</span>
                              <?php endif; ?>
                            </td>
                            <td><?= !empty($item['abrir_nueva_pestana']) ? 'Nueva pestaña' : 'Misma página' ?></td>
                            <td><?= !empty($item['destacado']) ? '<span class="gallery-badge-neon">Sí</span>' : '<span class="text-secondary">No</span>' ?></td>
                            <td class="text-end">
                              <div class="d-inline-flex gap-2">
                                <a href="/admin/configuracion?tab=galeria&editar_galeria=<?= (int) $item['id'] ?>" class="btn btn-outline-info btn-sm rounded-4">Editar</a>
                                <a href="/admin/configuracion?tab=galeria&eliminar_galeria=<?= (int) $item['id'] ?>" class="btn btn-outline-danger btn-sm rounded-4" onclick="return confirm('¿Eliminar este elemento de galería?');">Eliminar</a>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div class="d-grid gap-3 d-md-none">
                  <?php foreach ($galleryItems as $item): ?>
                    <div class="gallery-card-mobile">
                      <div class="d-flex gap-3 align-items-start">
                        <div class="gallery-thumb flex-shrink-0">
                          <img src="<?= htmlspecialchars($item['imagen'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['titulo'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="flex-grow-1">
                          <div class="d-flex justify-content-between gap-2 align-items-start">
                            <h4 class="h6 fw-bold mb-1 text-info"><?= htmlspecialchars($item['titulo'], ENT_QUOTES, 'UTF-8') ?></h4>
                            <?php if (!empty($item['destacado'])): ?>
                              <span class="gallery-badge-neon">Destacado</span>
                            <?php endif; ?>
                          </div>
                          <div class="small text-light"><?= htmlspecialchars($item['descripcion1'], ENT_QUOTES, 'UTF-8') ?></div>
                          <div class="small text-secondary"><?= htmlspecialchars($item['descripcion2'], ENT_QUOTES, 'UTF-8') ?></div>
                          <div class="small mt-2 text-light">Orden: <?= max(1, (int) ($item['orden'] ?? 0)) ?></div>
                          <div class="small mt-2 text-info-emphasis"><?= !empty($item['url']) ? htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') : 'Sin URL' ?></div>
                          <div class="small text-secondary mt-1"><?= !empty($item['abrir_nueva_pestana']) ? 'Nueva pestaña' : 'Misma página' ?></div>
                        </div>
                      </div>
                      <form method="post" class="d-flex gap-2 mt-3 align-items-center js-gallery-order-form">
                        <input type="hidden" name="ajax" value="1">
                        <input type="hidden" name="config_section" value="galeria">
                        <input type="hidden" name="gallery_order_update" value="1">
                        <input type="hidden" name="gallery_order_id" value="<?= (int) $item['id'] ?>">
                        <input type="number" name="gallery_order" min="1" value="<?= max(1, (int) ($item['orden'] ?? 0)) ?>" class="form-control form-control-sm js-gallery-order-input" style="max-width:110px;">
                      </form>
                      <div class="d-flex gap-2 mt-3">
                        <a href="/admin/configuracion?tab=galeria&editar_galeria=<?= (int) $item['id'] ?>" class="btn btn-outline-info btn-sm rounded-4 flex-fill">Editar</a>
                        <a href="/admin/configuracion?tab=galeria&eliminar_galeria=<?= (int) $item['id'] ?>" class="btn btn-outline-danger btn-sm rounded-4 flex-fill" onclick="return confirm('¿Eliminar este elemento de galería?');">Eliminar</a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="config_section" value="metodos-pago">
              <input type="hidden" name="payment_method_id" value="<?= $paymentMethodEditItem ? (int) $paymentMethodEditItem['id'] : 0 ?>">
              <input type="hidden" name="existing_payment_method_image_path" value="<?= htmlspecialchars((string) $paymentMethodForm['image_path'], ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="existing_payment_method_qr_image_path" value="<?= htmlspecialchars((string) $paymentMethodForm['qr_image_path'], ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="existing_payment_method_corner_image_path" value="<?= htmlspecialchars((string) $paymentMethodForm['corner_image_path'], ENT_QUOTES, 'UTF-8') ?>">
              <div class="config-section-note mb-4">Registra los métodos de pago disponibles para transferencias, con el nombre visible al cliente y los datos exactos donde debe realizar el pago.</div>
              <div class="row g-4 align-items-start">
                <div class="col-lg-8">
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label">Nombre Método de Pago</label>
                      <input type="text" name="nombre_metodo_pago" value="<?= htmlspecialchars($paymentMethodForm['nombre'], ENT_QUOTES, 'UTF-8') ?>" required class="form-control" placeholder="Mercantil, Binance, Zelle">
                    </div>
                    <div class="col-12">
                      <label class="form-label">Datos Método de Pago</label>
                      <textarea name="datos_metodo_pago" rows="6" required class="form-control" placeholder="Titular, número de cuenta, correo, teléfono o cualquier dato necesario para transferir."><?= htmlspecialchars($paymentMethodForm['datos'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Moneda del Método</label>
                      <select name="moneda_metodo_pago" required class="form-select">
                        <option value="">Selecciona una moneda</option>
                        <?php foreach ($paymentCurrencies as $currency): ?>
                          <option value="<?= (int) $currency['id'] ?>" <?= (int) $paymentMethodForm['moneda_id'] === (int) $currency['id'] ? 'selected' : '' ?>><?= htmlspecialchars($currency['nombre'] . ' (' . $currency['clave'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Dígitos de referencia permitidos</label>
                      <input type="number" name="referencia_digitos_metodo_pago" min="0" step="1" value="<?= (int) $paymentMethodForm['referencia_digitos'] ?>" class="form-control" placeholder="0 = sin límite">
                      <div class="form-text">Si colocas `0` o lo dejas vacío, el número de referencia será sin límite. Si colocas `5`, se validarán 5 dígitos; si colocas `7`, se validarán 7, y así sucesivamente.</div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Imagen del método</label>
                      <input type="file" name="imagen_metodo_pago" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control">
                      <div class="form-text">Esta imagen se usará en el catálogo público de pago. Tamaño recomendado: 1200x480 px. Formato horizontal, limpia y sin bordes externos.</div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Imagen QR</label>
                      <input type="file" name="imagen_qr_metodo_pago" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control">
                      <div class="form-text">Se almacenará para usarla más adelante dentro del flujo de pago. Tamaño recomendado: 1000x1000 px para conservar buena lectura.</div>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Imagen promocional de esquina</label>
                      <input type="file" name="imagen_promocion_metodo_pago" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control">
                      <div class="form-text">Flotará en la esquina de la card pública para resaltar promociones o descuentos. Tamaño recomendado: 320x320 px con fondo transparente.</div>
                    </div>
                    <?php if ($paymentMethodDiscountsEnabled): ?>
                    <div class="col-md-6">
                      <label class="form-label">Descuento disponible (%)</label>
                      <input type="number" name="descuento_metodo_pago_porcentaje" min="0" max="100" step="0.01" value="<?= htmlspecialchars(number_format((float) $paymentMethodForm['descuento_porcentaje'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="0.00">
                      <div class="form-text">Ejemplo: `3` aplica 3% de descuento cuando el cliente elige este método.</div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="activoMetodoPago" name="activo_metodo_pago" <?= $paymentMethodForm['activo'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="activoMetodoPago">Método de pago activo</label>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-lg-4">
                  <div class="config-section-note">
                    Usa este tab para crear, editar o desactivar cuentas receptoras como bancos, billeteras o servicios de pago.
                  </div>
                  <?php if (!empty($paymentMethodForm['image_path']) || !empty($paymentMethodForm['qr_image_path']) || !empty($paymentMethodForm['corner_image_path'])): ?>
                    <div class="config-section-note mt-3">
                      <div class="d-grid gap-3">
                        <?php if (!empty($paymentMethodForm['image_path'])): ?>
                          <div>
                            <div class="small text-info fw-semibold mb-2">Vista previa método</div>
                            <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $paymentMethodForm['image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa método" class="img-fluid rounded-4 border border-info-subtle">
                          </div>
                        <?php endif; ?>
                        <?php if (!empty($paymentMethodForm['qr_image_path'])): ?>
                          <div>
                            <div class="small text-info fw-semibold mb-2">Vista previa QR</div>
                            <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $paymentMethodForm['qr_image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa QR" class="img-fluid rounded-4 border border-info-subtle">
                          </div>
                        <?php endif; ?>
                        <?php if (!empty($paymentMethodForm['corner_image_path'])): ?>
                          <div>
                            <div class="small text-info fw-semibold mb-2">Vista previa promo esquina</div>
                            <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $paymentMethodForm['corner_image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa promo esquina" class="img-fluid rounded-4 border border-info-subtle" style="max-width: 180px;">
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                  <?php if ($paymentMethodEditItem): ?>
                    <a href="/admin/configuracion?tab=metodos-pago" class="btn btn-outline-info w-100 rounded-4 mt-3">Cancelar edición</a>
                  <?php endif; ?>
                </div>
              </div>
              <button type="submit" class="neon-btn w-100 py-3 mt-4"><?= $paymentMethodEditItem ? 'Actualizar método de pago' : 'Crear método de pago' ?></button>
            </form>

            <div class="mt-5">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <h3 class="h5 fw-bold mb-0 text-info">Métodos registrados</h3>
                <span class="gallery-badge-neon"><?= count($paymentMethods) ?> métodos</span>
              </div>
              <?php if (empty($paymentMethods)): ?>
                <div class="config-section-note">Aún no hay métodos de pago registrados. Crea el primero para empezar a administrarlos.</div>
              <?php else: ?>
                <div class="gallery-table-wrap d-none d-md-block">
                  <div class="table-responsive">
                    <table class="table table-striped align-middle">
                      <thead>
                        <tr>
                          <th>Nombre</th>
                          <th>Imagen</th>
                          <th>QR</th>
                          <th>Moneda</th>
                          <?php if ($paymentMethodDiscountsEnabled): ?><th>Descuento</th><?php endif; ?>
                          <th>Dígitos Ref.</th>
                          <th>Datos</th>
                          <th>Estado</th>
                          <th class="text-end">Acciones</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($paymentMethods as $method): ?>
                          <tr>
                            <td class="fw-bold"><?= htmlspecialchars($method['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                              <?php if (!empty($method['image_path']) || !empty($method['corner_image_path'])): ?>
                                <div class="payment-method-thumb-stack">
                                  <?php if (!empty($method['image_path'])): ?>
                                    <div class="payment-method-thumb">
                                      <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $method['image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars('Vista previa ' . (string) $method['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <span class="payment-method-thumb-caption">Catálogo</span>
                                  <?php endif; ?>
                                  <?php if (!empty($method['corner_image_path'])): ?>
                                    <div class="payment-method-thumb" style="max-width: 120px;">
                                      <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $method['corner_image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars('Promo ' . (string) $method['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <span class="payment-method-thumb-caption">Promo esquina</span>
                                  <?php endif; ?>
                                </div>
                              <?php else: ?>
                                <span class="text-secondary">Sin imagen</span>
                              <?php endif; ?>
                            </td>
                            <td>
                              <?php if (!empty($method['qr_image_path'])): ?>
                                <div class="payment-method-thumb-stack">
                                  <div class="payment-method-thumb payment-method-thumb-qr">
                                    <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $method['qr_image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars('Vista previa QR ' . (string) $method['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                                  </div>
                                  <span class="payment-method-thumb-caption">Vista previa</span>
                                </div>
                              <?php else: ?>
                                <span class="text-secondary">Sin QR</span>
                              <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(trim((string) (($method['moneda_nombre'] ?? '') . ' ' . (!empty($method['moneda_clave']) ? '(' . $method['moneda_clave'] . ')' : ''))) ?: 'Sin moneda', ENT_QUOTES, 'UTF-8') ?></td>
                            <?php if ($paymentMethodDiscountsEnabled): ?><td><?= (float) ($method['descuento_porcentaje'] ?? 0) > 0 ? htmlspecialchars(rtrim(rtrim(number_format((float) $method['descuento_porcentaje'], 2, '.', ''), '0'), '.') . '%', ENT_QUOTES, 'UTF-8') : 'Sin descuento' ?></td><?php endif; ?>
                            <td><?= !empty($method['referencia_digitos']) ? (int) $method['referencia_digitos'] : 'Sin límite' ?></td>
                            <td style="white-space: pre-line;"><?= htmlspecialchars($method['datos'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($method['activo']) ? '<span class="gallery-badge-neon">Activo</span>' : '<span class="text-secondary">Inactivo</span>' ?></td>
                            <td class="text-end">
                              <div class="d-inline-flex gap-2 flex-wrap justify-content-end">
                                <a href="/admin/configuracion?tab=metodos-pago&editar_metodo_pago=<?= (int) $method['id'] ?>" class="btn btn-outline-info btn-sm rounded-4">Editar</a>
                                <a href="/admin/configuracion?tab=metodos-pago&toggle_metodo_pago=<?= (int) $method['id'] ?>" class="btn btn-outline-secondary btn-sm rounded-4"><?= !empty($method['activo']) ? 'Desactivar' : 'Activar' ?></a>
                                <a href="/admin/configuracion?tab=metodos-pago&eliminar_metodo_pago=<?= (int) $method['id'] ?>" class="btn btn-outline-danger btn-sm rounded-4" onclick="return confirm('¿Eliminar este método de pago?');">Eliminar</a>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div class="d-grid gap-3 d-md-none">
                  <?php foreach ($paymentMethods as $method): ?>
                    <div class="gallery-card-mobile">
                      <div class="d-flex justify-content-between gap-2 align-items-start">
                        <h4 class="h6 fw-bold mb-1 text-info"><?= htmlspecialchars($method['nombre'], ENT_QUOTES, 'UTF-8') ?></h4>
                        <?= !empty($method['activo']) ? '<span class="gallery-badge-neon">Activo</span>' : '<span class="text-secondary small">Inactivo</span>' ?>
                      </div>
                      <div class="small text-info-emphasis mt-2"><?= htmlspecialchars(trim((string) (($method['moneda_nombre'] ?? '') . ' ' . (!empty($method['moneda_clave']) ? '(' . $method['moneda_clave'] . ')' : ''))) ?: 'Sin moneda', ENT_QUOTES, 'UTF-8') ?></div>
                      <?php if (!empty($method['image_path']) || !empty($method['qr_image_path']) || !empty($method['corner_image_path'])): ?>
                        <div class="payment-method-mobile-previews">
                          <?php if (!empty($method['image_path'])): ?>
                            <div class="payment-method-thumb-stack">
                              <div class="payment-method-thumb">
                                <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $method['image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars('Vista previa ' . (string) $method['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                              </div>
                              <span class="payment-method-thumb-caption">Imagen catálogo</span>
                            </div>
                          <?php endif; ?>
                          <?php if (!empty($method['qr_image_path'])): ?>
                            <div class="payment-method-thumb-stack">
                              <div class="payment-method-thumb payment-method-thumb-qr">
                                <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $method['qr_image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars('Vista previa QR ' . (string) $method['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                              </div>
                              <span class="payment-method-thumb-caption">Imagen QR</span>
                            </div>
                          <?php endif; ?>
                          <?php if (!empty($method['corner_image_path'])): ?>
                            <div class="payment-method-thumb-stack">
                              <div class="payment-method-thumb" style="max-width: 120px;">
                                <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $method['corner_image_path'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars('Promo ' . (string) $method['nombre'], ENT_QUOTES, 'UTF-8') ?>">
                              </div>
                              <span class="payment-method-thumb-caption">Promo esquina</span>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <div class="small text-secondary mt-1">Sin imágenes cargadas</div>
                      <?php endif; ?>
                      <?php if ($paymentMethodDiscountsEnabled): ?><div class="small text-success mt-1">Descuento: <?= (float) ($method['descuento_porcentaje'] ?? 0) > 0 ? htmlspecialchars(rtrim(rtrim(number_format((float) $method['descuento_porcentaje'], 2, '.', ''), '0'), '.') . '%', ENT_QUOTES, 'UTF-8') : 'Sin descuento' ?></div><?php endif; ?>
                      <div class="small text-secondary mt-1">Dígitos de referencia: <?= !empty($method['referencia_digitos']) ? (int) $method['referencia_digitos'] : 'Sin límite' ?></div>
                      <div class="small text-light mt-2" style="white-space: pre-line;"><?= htmlspecialchars($method['datos'], ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="d-flex gap-2 mt-3 flex-wrap">
                        <a href="/admin/configuracion?tab=metodos-pago&editar_metodo_pago=<?= (int) $method['id'] ?>" class="btn btn-outline-info btn-sm rounded-4 flex-fill">Editar</a>
                        <a href="/admin/configuracion?tab=metodos-pago&toggle_metodo_pago=<?= (int) $method['id'] ?>" class="btn btn-outline-secondary btn-sm rounded-4 flex-fill"><?= !empty($method['activo']) ? 'Desactivar' : 'Activar' ?></a>
                        <a href="/admin/configuracion?tab=metodos-pago&eliminar_metodo_pago=<?= (int) $method['id'] ?>" class="btn btn-outline-danger btn-sm rounded-4 flex-fill" onclick="return confirm('¿Eliminar este método de pago?');">Eliminar</a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
  (() => {
    const notificationPositionSelect = document.querySelector('[data-win-points-notification-position-select]');
    const simulateNotificationButton = document.querySelector('[data-win-points-simulate-notification]');
    if (!notificationPositionSelect || !simulateNotificationButton) {
      return;
    }

    const previewConfig = {
      programName: <?= json_encode($winPointsNotificationPreviewName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      iconSrc: <?= json_encode($winPointsNotificationPreviewIcon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      points: 3,
    };
    let simulationTimer = null;

    const escapeHtml = (value) => String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

    const buildNotificationElement = () => {
      const notification = document.createElement('div');
      notification.className = 'win-points-live-notification';
      notification.dataset.position = notificationPositionSelect.value || 'bottom-left';

      const iconMarkup = previewConfig.iconSrc
        ? '<div class="win-points-live-notification__logo-wrap"><img src="' + escapeHtml(previewConfig.iconSrc) + '" alt="' + escapeHtml(previewConfig.programName) + '" class="win-points-live-notification__logo"></div>'
        : '<div class="win-points-live-notification__logo-wrap"><span class="win-points-live-notification__logo-fallback">WP</span></div>';

      notification.innerHTML = ''
        + '<div class="win-points-live-notification__pulse" aria-hidden="true"></div>'
        + iconMarkup
        + '<div class="win-points-live-notification__body">'
        + '<div class="win-points-live-notification__title">+' + previewConfig.points + ' ' + escapeHtml(previewConfig.programName || 'Win Points') + '</div>'
        + '<div class="win-points-live-notification__detail">Vista previa exacta de la notificación que verá el cliente al recibir premios por recarga.</div>'
        + '</div>';

      return notification;
    };

    const showSimulation = () => {
      const existing = document.querySelector('.win-points-live-notification[data-admin-simulation="1"]');
      if (existing) {
        existing.remove();
      }

      if (simulationTimer) {
        window.clearTimeout(simulationTimer);
        simulationTimer = null;
      }

      const notification = buildNotificationElement();
      notification.dataset.adminSimulation = '1';
      document.body.appendChild(notification);

      window.requestAnimationFrame(() => {
        notification.classList.add('is-visible');
      });

      simulationTimer = window.setTimeout(() => {
        notification.classList.remove('is-visible');
        window.setTimeout(() => {
          notification.remove();
        }, 320);
      }, 5000);
    };

    simulateNotificationButton.addEventListener('click', showSimulation);
  })();

  (() => {
    const paypalDocsTrigger = document.getElementById('paypal-docs-trigger');
    const paypalDocsModal = document.getElementById('paypal-docs-modal');
    const paypalDocsCloseButtons = paypalDocsModal ? Array.from(paypalDocsModal.querySelectorAll('[data-paypal-docs-close]')) : [];
    if (!paypalDocsTrigger || !paypalDocsModal) {
      return;
    }

    if (paypalDocsModal.parentElement !== document.body) {
      document.body.appendChild(paypalDocsModal);
    }

    const showPaypalDocsFallback = () => {
      const dialog = paypalDocsModal.querySelector('.modal-dialog');
      const body = paypalDocsModal.querySelector('.modal-body');
      const content = paypalDocsModal.querySelector('.modal-content');

      paypalDocsModal.style.display = 'block';
      paypalDocsModal.style.position = 'fixed';
      paypalDocsModal.style.inset = '0';
      paypalDocsModal.style.background = 'rgba(3, 8, 18, 0.72)';
      paypalDocsModal.style.zIndex = '2000';
      paypalDocsModal.style.pointerEvents = 'auto';
      paypalDocsModal.style.overflowY = 'auto';
      paypalDocsModal.style.padding = '1rem';
      paypalDocsModal.removeAttribute('aria-hidden');
      paypalDocsModal.setAttribute('aria-modal', 'true');
      paypalDocsModal.setAttribute('role', 'dialog');
      paypalDocsModal.classList.add('show');

      if (dialog) {
        dialog.style.pointerEvents = 'auto';
        dialog.style.maxWidth = 'min(1480px, 96vw)';
        dialog.style.margin = '1rem auto';
        dialog.style.minHeight = 'calc(100vh - 2rem)';
        dialog.style.display = 'flex';
        dialog.style.alignItems = 'stretch';
      }

      if (content) {
        content.style.pointerEvents = 'auto';
        content.style.maxHeight = 'calc(100vh - 2rem)';
      }

      if (body) {
        body.style.maxHeight = 'calc(100vh - 9rem)';
        body.style.overflowY = 'auto';
        body.style.pointerEvents = 'auto';
      }

      document.body.classList.add('modal-open');
      document.body.style.overflow = 'hidden';
    };

    const hidePaypalDocsFallback = () => {
      paypalDocsModal.classList.remove('show');
      paypalDocsModal.setAttribute('aria-hidden', 'true');
      paypalDocsModal.removeAttribute('aria-modal');
      paypalDocsModal.style.display = 'none';
      paypalDocsModal.style.removeProperty('background');
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('overflow');
    };

    paypalDocsTrigger.addEventListener('click', (event) => {
      event.preventDefault();
      if (window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(paypalDocsModal).show();
        return;
      }

      showPaypalDocsFallback();
    });

    paypalDocsCloseButtons.forEach((button) => {
      button.addEventListener('click', (event) => {
        if (window.bootstrap && window.bootstrap.Modal) {
          return;
        }

        event.preventDefault();
        hidePaypalDocsFallback();
      });
    });

    paypalDocsModal.addEventListener('click', (event) => {
      if (window.bootstrap && window.bootstrap.Modal) {
        return;
      }

      if (event.target === paypalDocsModal) {
        hidePaypalDocsFallback();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (window.bootstrap && window.bootstrap.Modal) {
        return;
      }

      if (event.key === 'Escape' && paypalDocsModal.classList.contains('show')) {
        hidePaypalDocsFallback();
      }
    });
  })();

  (() => {
    const fileInput = document.getElementById('gallery-image-input');
    const previewContainer = document.getElementById('gallery-image-preview');
    if (!fileInput || !previewContainer) {
      return;
    }

    const originalSrc = previewContainer.dataset.originalSrc || '';
    let objectUrl = null;

    const renderPreview = (src) => {
      previewContainer.innerHTML = '';
      if (!src) {
        const empty = document.createElement('span');
        empty.className = 'gallery-image-empty';
        empty.id = 'gallery-image-preview-empty';
        empty.textContent = 'Sin imagen';
        previewContainer.appendChild(empty);
        return;
      }

      const image = document.createElement('img');
      image.id = 'gallery-image-preview-img';
      image.alt = 'Vista previa de galería';
      image.src = src;
      previewContainer.appendChild(image);
    };

    const clearObjectUrl = () => {
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
      }
    };

    fileInput.addEventListener('change', () => {
      const [file] = fileInput.files || [];
      clearObjectUrl();

      if (!file) {
        renderPreview(originalSrc);
        return;
      }

      if (!file.type.startsWith('image/')) {
        renderPreview(originalSrc);
        return;
      }

      objectUrl = URL.createObjectURL(file);
      renderPreview(objectUrl);
    });

    renderPreview(originalSrc);
  })();

  (() => {
    const noneOption = document.getElementById('inicioPopupModeNone');
    const videoOption = document.getElementById('inicioPopupModeVideo');
    const videoUrlInput = document.getElementById('inicioPopupVideoUrl');
    if (!noneOption || !videoOption || !videoUrlInput) {
      return;
    }

    const syncVideoModeAvailability = () => {
      const hasVideoUrl = videoUrlInput.value.trim() !== '';
      videoOption.disabled = !hasVideoUrl;
      if (!hasVideoUrl && videoOption.checked) {
        noneOption.checked = true;
      }
    };

    videoUrlInput.addEventListener('input', syncVideoModeAvailability);
    syncVideoModeAvailability();
  })();

  (() => {
    const form = document.querySelector('form[enctype="multipart/form-data"] input[name="config_section"][value="ventana-inicial"]')?.form;
    const fileInput = document.getElementById('startupPopupGalleryInput');
    const uploadButton = document.getElementById('startupPopupGalleryUploadButton');
    const status = document.getElementById('startupPopupGalleryStatus');
    const grid = document.getElementById('startupPopupGalleryGrid');
    const emptyState = document.getElementById('startupPopupGalleryEmpty');
    const countTarget = document.getElementById(grid?.dataset.countTarget || '');
    if (!form || !fileInput || !uploadButton || !grid || !emptyState || !status) {
      return;
    }

    const endpoint = grid.dataset.endpoint || window.location.href;
    let busy = false;

    const escapeHtml = (value) => String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

    const setBusy = (nextBusy) => {
      busy = nextBusy;
      uploadButton.disabled = nextBusy;
      grid.querySelectorAll('button, input, select').forEach((field) => {
        field.disabled = nextBusy;
      });
    };

    const setStatus = (message, isError = false) => {
      status.textContent = message;
      status.style.color = isError ? '#fda4af' : 'rgba(216, 251, 255, 0.78)';
    };

    const renderItems = (items) => {
      grid.innerHTML = '';
      if (!Array.isArray(items) || items.length === 0) {
        emptyState.classList.remove('d-none');
        if (countTarget) {
          countTarget.textContent = '0';
        }
        return;
      }

      emptyState.classList.add('d-none');
      items.forEach((item) => {
        const path = String(item.path || '').trim();
        const imageUrl = String(item.url || path || '').trim();
        const fileName = String(item.name || '').trim();
        const order = Number(item.order || 0) || 0;
        const linkUrl = String(item.link_url || '').trim();
        const linkTarget = String(item.link_target || '').trim() === '_blank' ? '_blank' : '_self';
        const card = document.createElement('div');
        card.className = 'startup-popup-gallery-card';
        card.dataset.galleryPath = path;
        card.innerHTML = `
          <button type="button" class="startup-popup-gallery-delete" data-gallery-action="delete" data-gallery-path="${escapeHtml(path)}" aria-label="Eliminar imagen">X</button>
          <div class="startup-popup-gallery-thumb">
            <img src="${escapeHtml(imageUrl)}" alt="Imagen ${order} de la galería inicial">
          </div>
          <div class="startup-popup-gallery-meta">
            <div class="startup-popup-gallery-name">${escapeHtml(fileName)}</div>
            <div class="startup-popup-gallery-controls">
              <span class="startup-popup-gallery-order">Orden ${order}</span>
              <div class="startup-popup-gallery-move-group">
                <button type="button" class="startup-popup-gallery-move" data-gallery-action="move" data-gallery-direction="left" data-gallery-path="${escapeHtml(path)}" aria-label="Mover a la izquierda">←</button>
                <button type="button" class="startup-popup-gallery-move" data-gallery-action="move" data-gallery-direction="right" data-gallery-path="${escapeHtml(path)}" aria-label="Mover a la derecha">→</button>
              </div>
            </div>
            <div class="startup-popup-gallery-link-fields">
              <input type="text" class="form-control form-control-sm startup-popup-gallery-link-input" data-gallery-link-url placeholder="https://... o /ruta-interna" value="${escapeHtml(linkUrl)}">
              <select class="form-select form-select-sm startup-popup-gallery-target-select" data-gallery-link-target>
                <option value="_self"${linkTarget === '_self' ? ' selected' : ''}>Abrir en la misma pestaña</option>
                <option value="_blank"${linkTarget === '_blank' ? ' selected' : ''}>Abrir en una pestaña nueva</option>
              </select>
            </div>
            <button type="button" class="startup-popup-gallery-save" data-gallery-action="update-meta" data-gallery-path="${escapeHtml(path)}">Guardar enlace</button>
            <div class="startup-popup-gallery-link-hint">Opcional. Acepta https://..., http://... o una ruta interna que empiece por /.</div>
          </div>
        `;
        grid.appendChild(card);
      });

      if (countTarget) {
        countTarget.textContent = String(items.length);
      }
    };

    const sendGalleryRequest = async (formData) => {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json, text/plain, */*'
        },
        body: formData
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.ok !== true) {
        throw new Error(payload && payload.message ? payload.message : 'No se pudo actualizar la galería de la ventana inicial.');
      }
      return payload;
    };

    uploadButton.addEventListener('click', async () => {
      if (busy) {
        return;
      }
      if (!fileInput.files || fileInput.files.length === 0) {
        setStatus('Selecciona al menos una imagen antes de subirla.', true);
        return;
      }

      const formData = new FormData();
      formData.append('config_section', 'ventana-inicial');
      formData.append('startup_popup_gallery_action', 'upload');
      formData.append('ajax', '1');
      Array.from(fileInput.files).forEach((file) => {
        formData.append('inicio_popup_galeria_imagenes[]', file);
      });

      setBusy(true);
      setStatus('Subiendo imágenes seleccionadas...');
      try {
        const payload = await sendGalleryRequest(formData);
        renderItems(payload.items || []);
        fileInput.value = '';
        setStatus('Imágenes subidas correctamente. Puedes seleccionar otra carpeta y volver a subir.');
      } catch (error) {
        setStatus(error.message, true);
      } finally {
        setBusy(false);
      }
    });

    grid.addEventListener('click', async (event) => {
      const button = event.target.closest('button[data-gallery-action]');
      if (!button || busy) {
        return;
      }

      const action = button.dataset.galleryAction || '';
      const path = button.dataset.galleryPath || '';
      if (!action || !path) {
        return;
      }

      const formData = new FormData();
      formData.append('config_section', 'ventana-inicial');
      formData.append('startup_popup_gallery_action', action);
      formData.append('startup_popup_gallery_image', path);
      formData.append('ajax', '1');
      if (action === 'update-meta') {
        const card = button.closest('.startup-popup-gallery-card');
        const linkInput = card ? card.querySelector('[data-gallery-link-url]') : null;
        const targetSelect = card ? card.querySelector('[data-gallery-link-target]') : null;
        formData.append('startup_popup_gallery_link_url', linkInput ? linkInput.value : '');
        formData.append('startup_popup_gallery_link_target', targetSelect ? targetSelect.value : '_self');
      }
      if (action === 'move') {
        formData.append('startup_popup_gallery_direction', button.dataset.galleryDirection || '');
      }

      setBusy(true);
      setStatus(
        action === 'delete'
          ? 'Eliminando imagen...'
          : (action === 'update-meta' ? 'Guardando enlace de la imagen...' : 'Reordenando galería...')
      );
      try {
        const payload = await sendGalleryRequest(formData);
        renderItems(payload.items || []);
        setStatus(
          action === 'delete'
            ? 'Imagen eliminada de la galería.'
            : (action === 'update-meta' ? 'Enlace de la imagen actualizado.' : 'Orden de la galería actualizado.')
        );
      } catch (error) {
        setStatus(error.message, true);
      } finally {
        setBusy(false);
      }
    });

    form.addEventListener('submit', (event) => {
      if (fileInput.files && fileInput.files.length > 0) {
        event.preventDefault();
        setStatus('Tienes imágenes seleccionadas sin subir. Usa primero el botón Subir imágenes seleccionadas.', true);
      }
    });
  })();

  (() => {
    const submitGalleryOrderForm = async (form) => {
      const response = await fetch(form.action || window.location.href, {
        method: (form.method || 'POST').toUpperCase(),
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json, text/plain, */*'
        },
        body: new FormData(form)
      });

      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload || payload.ok !== true) {
        throw new Error(payload && payload.message ? payload.message : 'No se pudo guardar el orden de la galería.');
      }

      return payload;
    };

    document.querySelectorAll('.js-gallery-order-form').forEach((form) => {
      const input = form.querySelector('.js-gallery-order-input');
      if (!input) {
        return;
      }

      input.dataset.lastValue = input.value;
      input.addEventListener('change', async () => {
        const normalized = String(Math.max(1, parseInt(input.value || '1', 10) || 1));
        if (normalized === input.dataset.lastValue) {
          input.value = normalized;
          return;
        }

        input.value = normalized;
        input.readOnly = true;
        try {
          const payload = await submitGalleryOrderForm(form);
          input.dataset.lastValue = String(payload.orden || normalized);
          input.value = input.dataset.lastValue;
        } catch (error) {
          input.value = input.dataset.lastValue || '1';
          window.alert(error.message);
        } finally {
          input.readOnly = false;
        }
      });
    });
  })();
</script>
<?php if (!defined('ADMIN_LAYOUT_EMBEDDED')) include __DIR__ . '/includes/footer.php'; ?>
