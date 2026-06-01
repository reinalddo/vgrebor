<?php
require_once __DIR__ . '/tenant.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
  tenant_start_session();
}

require_once __DIR__ . '/store_config.php';
require_once __DIR__ . '/influencer_instructions.php';
require_once __DIR__ . '/win_points.php';
require_once __DIR__ . '/google_oauth.php';
require_once __DIR__ . '/auth.php';

if (!isset($brandPrefix)) {
  $brandPrefix = store_config_get('nombre_prefijo', 'TIENDA');
}
if (!isset($pageTitle)) {
  $pageTitle = store_config_get('nombre_tienda', 'TVirtualGaming');
}
if (!isset($brandName)) {
  $brandName = store_config_get('nombre_tienda', 'TVirtualGaming');
}

$defaultMetaTitle = store_config_get('nombre_tienda', 'TVirtualGaming') . ' | ' . store_config_get('nombre_tienda_subtitulo', 'Tienda de monedas digitales');
$pageMetaTitle = trim((string) store_config_get('meta_titulo', $defaultMetaTitle));
if ($pageMetaTitle === '') {
  $pageMetaTitle = $defaultMetaTitle;
}
$pageDescription = trim((string) store_config_get('meta_descripcion', 'Compra monedas y recargas digitales en TVirtualGaming. Recibe ofertas, promociones y novedades directamente en tu WhatsApp.'));
if ($pageDescription === '') {
  $pageDescription = 'Compra monedas y recargas digitales en TVirtualGaming. Recibe ofertas, promociones y novedades directamente en tu WhatsApp.';
}

$authUser = auth_sync_session_user();
$authUserName = trim((string) (($authUser['full_name'] ?? $authUser['nombre'] ?? $authUser['email'] ?? 'Usuario')));
$authUserEmail = trim((string) ($authUser['email'] ?? ''));
$authUserPhone = trim((string) ($authUser['telefono'] ?? ''));
$authUserProfileImage = trim((string) ($authUser['foto_perfil'] ?? ''));
$authUserProfileImageUrl = '';
if ($authUserProfileImage !== '') {
  if (preg_match('#^(?:https?:)?//#i', $authUserProfileImage) === 1 || str_starts_with($authUserProfileImage, 'data:')) {
    $authUserProfileImageUrl = $authUserProfileImage;
  } else {
    $authUserProfileImageUrl = app_path($authUserProfileImage);
    $authUserProfileImageAbsolutePath = tenant_resolve_public_path($authUserProfileImage);
    if ($authUserProfileImageAbsolutePath !== null && is_file($authUserProfileImageAbsolutePath)) {
      $authUserProfileImageUrl .= '?v=' . rawurlencode((string) filemtime($authUserProfileImageAbsolutePath));
    }
  }
}
$authUserRole = strtolower(trim((string) ($authUser['rol'] ?? '')));
$winPointsProgramEnabled = win_points_enabled();
$winPointsProgramConfig = $winPointsProgramEnabled ? win_points_config() : ['name' => 'Win Points', 'icon_url' => ''];
$winPointsUserSummary = win_points_empty_user_summary();
if ($winPointsProgramEnabled && $authUser && !empty($authUser['id'])) {
  $winPointsUserSummary = win_points_fetch_user_summary(win_points_db(), (int) $authUser['id']);
}
$winPointsExpirationStatus = trim((string) ($winPointsUserSummary['expiration_status'] ?? 'no_balance'));
$winPointsDaysLabel = trim((string) ($winPointsUserSummary['days_remaining_label'] ?? 'Sin saldo'));
$winPointsExpiresAtLabel = trim((string) ($winPointsUserSummary['expires_at_label'] ?? ''));
$winPointsMenuExpirationText = !empty($winPointsUserSummary['is_expired'])
  ? 'Vencidos'
  : (in_array($winPointsExpirationStatus, ['active', 'warning'], true) && $winPointsDaysLabel !== ''
    ? 'Vence en ' . $winPointsDaysLabel
    : ($winPointsDaysLabel !== '' ? $winPointsDaysLabel : 'Sin saldo'));
$winPointsModalExpirationText = !empty($winPointsUserSummary['is_expired'])
  ? ($winPointsExpiresAtLabel !== '' && $winPointsExpiresAtLabel !== 'Sin saldo' ? 'Vencidos | ' . $winPointsExpiresAtLabel : 'Vencidos')
  : (in_array($winPointsExpirationStatus, ['active', 'warning'], true) && $winPointsDaysLabel !== ''
    ? 'Vence en ' . $winPointsDaysLabel . ($winPointsExpiresAtLabel !== '' && $winPointsExpiresAtLabel !== 'Sin saldo' ? ' | ' . $winPointsExpiresAtLabel : '')
    : ($winPointsDaysLabel !== '' ? $winPointsDaysLabel : 'Sin saldo'));
$authUserCanAccessAdmin = in_array($authUserRole, ['admin', 'empleado', 'influencer', 'root'], true);
$authUserAdminHome = $authUserRole === 'influencer'
  ? app_path('/admin/cupones') . '?tab=influencers'
  : app_path('/admin/dashboard');
$authUserInitials = 'US';
if ($authUserName !== '') {
  $nameParts = preg_split('/\s+/', $authUserName);
  $initials = '';
  foreach ($nameParts as $part) {
    if ($part === '') {
      continue;
    }
    $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
    if (strlen($initials) >= 2) {
      break;
    }
  }
  if ($initials !== '') {
    $authUserInitials = strtoupper($initials);
  }
}

$brandLogo = store_config_get('logo_tienda', '');
$brandFavicon = '';
if ($brandLogo !== '') {
  $brandFavicon = $brandLogo;
  if (store_config_is_managed_logo_path($brandLogo)) {
    $brandLogoAbsolutePath = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $brandLogo);
    if (is_file($brandLogoAbsolutePath)) {
      $brandFavicon .= '?v=' . rawurlencode((string) filemtime($brandLogoAbsolutePath));
    }
  }
}

if (!function_exists('asset_version')) {
  function asset_version($absolutePath) {
    return is_file($absolutePath) ? (string) filemtime($absolutePath) : '1';
  }
}

$tenantSlugAttr = resolve_tenant_slug();
$homeUrl = app_path('/');
$popularUrl = app_path('/populares');
$gamesUrl = app_path('/juegos');
$logoutUrl = app_path('/logout');
$registerScriptUrl = app_path('/registro.js');
$registerEndpointUrl = app_path('/register_user.php');
$loginUrl = app_path('/login.php');
$resetUrl = app_path('/reset.php');
$adminDashboardUrl = app_path('/admin/dashboard');
$adminGamesUrl = app_path('/admin/juegos');
$adminCurrenciesUrl = app_path('/admin/monedas');
$adminOrdersUrl = app_path('/admin/pedidos');
$adminMovementsUrl = app_path('/admin/movimientos');
$adminUsersUrl = app_path('/admin/usuarios');
$adminCouponsUrl = app_path('/admin/cupones');
$adminConfigUrl = app_path('/admin/configuracion');
$adminExtraFeaturesUrl = app_path('/admin/comprar-funciones-extra');
$adminInfluencerInstructionsUrl = app_path('/admin/instrucciones-influencer');
$influencerJoinUrl = app_path('/quiero-unirme');
$topBarEnabled = trim((string) store_config_get('barra_superior', '0')) === '1';
$showMenuToggle = !$topBarEnabled || !$authUser || in_array($authUserRole, ['admin', 'root'], true);
$mobileOnlyMenuToggle = $topBarEnabled && $authUser && !in_array($authUserRole, ['admin', 'root'], true);
if ($mobileOnlyMenuToggle) {
  $showMenuToggle = true;
}
$searchEndpointUrl = app_path('/api/search_catalog.php');
$searchResultsUrl = app_path('/buscar');
$influencerInstructionsEnabled = store_config_get('instrucciones_influencer', '0') === '1';
$influencerInstructionsMenuLabel = 'Quiero Unirme';
if ($influencerInstructionsEnabled) {
  $influencerInstructionsData = influencer_instructions_get();
  $influencerInstructionsMenuLabel = trim((string) ($influencerInstructionsData['menu_label'] ?? 'Quiero Unirme')) ?: 'Quiero Unirme';
}
$mainStylesPath = __DIR__ . '/../assets/css/estilos.css';
$mainStylesVersion = asset_version($mainStylesPath);
$themeVariablesCss = store_theme_css_variables();
$requestUri = str_replace('\\', '/', (string) ($_SERVER['REQUEST_URI'] ?? ''));
$scriptName = str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? ''));
$isAdminInterface = preg_match('#/admin(?:/|$)#i', $requestUri) === 1 || preg_match('#/(admin[^/]*\.php)$#i', $scriptName) === 1;
$publicBackgroundSettings = store_config_public_background_settings();
$publicBackgroundMediaUrl = trim((string) ($publicBackgroundSettings['asset_path'] ?? ''));
$publicBackgroundMediaType = trim((string) ($publicBackgroundSettings['media_type'] ?? ''));
if ($publicBackgroundMediaUrl !== '' && store_config_is_managed_public_background_path($publicBackgroundMediaUrl)) {
  $publicBackgroundAbsolutePath = tenant_resolve_public_path($publicBackgroundMediaUrl);
  if ($publicBackgroundAbsolutePath !== null && is_file($publicBackgroundAbsolutePath)) {
    $publicBackgroundMediaUrl .= '?v=' . rawurlencode((string) filemtime($publicBackgroundAbsolutePath));
  }
}
$renderPublicMediaBackground = !$isAdminInterface
  && !empty($publicBackgroundSettings['enabled'])
  && ($publicBackgroundSettings['mode'] ?? 'normal') === 'media'
  && !empty($publicBackgroundSettings['has_media'])
  && $publicBackgroundMediaUrl !== ''
  && in_array($publicBackgroundMediaType, ['image', 'video'], true);
$publicBackgroundOverlay = store_theme_rgba(
  (string) ($publicBackgroundSettings['overlay_color'] ?? '#081018'),
  ((int) ($publicBackgroundSettings['overlay_opacity'] ?? 52)) / 100
);
$googleAuthEnabled = google_oauth_is_configured();
$googleAuthLoginUrl = $googleAuthEnabled ? google_oauth_login_url() : '';
$pageCanonicalUrl = app_url('/');
$pageOgImage = '';
if ($brandLogo !== '') {
  if (preg_match('#^https?://#i', $brandLogo) === 1) {
    $pageOgImage = $brandLogo;
  } else {
    $pageOgImage = app_url('/' . ltrim($brandLogo, '/'));
  }
}
$authModalState = $_SESSION['auth_modal_state'] ?? null;
if ($authModalState) {
  unset($_SESSION['auth_modal_state']);
}
$authModalInitialMode = trim((string) ($authModalState['mode'] ?? ''));
$authModalInlineMessage = trim((string) ($authModalState['message'] ?? ''));
$authModalLoginEmail = trim((string) ($authModalState['email'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
  <meta http-equiv="Pragma" content="no-cache" />
  <meta http-equiv="Expires" content="0" />
  <title><?php echo htmlspecialchars($pageMetaTitle, ENT_QUOTES, "UTF-8"); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta property="og:title" content="<?php echo htmlspecialchars($pageMetaTitle, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta property="og:url" content="<?php echo htmlspecialchars($pageCanonicalUrl, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta property="og:type" content="website" />
  <?php if ($pageOgImage !== ''): ?>
  <meta property="og:image" content="<?php echo htmlspecialchars($pageOgImage, ENT_QUOTES, 'UTF-8'); ?>" />
  <?php endif; ?>
  <meta name="twitter:card" content="<?php echo $pageOgImage !== '' ? 'summary_large_image' : 'summary'; ?>" />
  <meta name="twitter:title" content="<?php echo htmlspecialchars($pageMetaTitle, ENT_QUOTES, 'UTF-8'); ?>" />
  <meta name="twitter:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>" />
  <?php if ($pageOgImage !== ''): ?>
  <meta name="twitter:image" content="<?php echo htmlspecialchars($pageOgImage, ENT_QUOTES, 'UTF-8'); ?>" />
  <?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@400;600;700&family=Space+Grotesk:wght@400;500;600&display=swap" rel="stylesheet" />
  <?php if ($brandFavicon !== ''): ?>
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($brandFavicon, ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="shortcut icon" href="<?php echo htmlspecialchars($brandFavicon, ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($brandFavicon, ENT_QUOTES, 'UTF-8'); ?>" />
  <?php endif; ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <!--<link rel="stylesheet" href="/assets/css/estilos.css" />-->
  <link rel="stylesheet" href="/assets/css/estilos.css?v=<?php echo htmlspecialchars($mainStylesVersion, ENT_QUOTES, 'UTF-8'); ?>" />
  <style>
    :root {
<?php echo $themeVariablesCss; ?>
    }
    body {
      font-family: "Space Grotesk", "Oxanium", sans-serif;
      background: radial-gradient(circle at top, var(--theme-body-glow) 0%, var(--theme-bg-main) 48%, var(--theme-bg-deep) 100%);
      color: var(--theme-text);
    }
    body.site-topbar-enabled {
      --site-topbar-height: 92px;
    }
    body.site-media-background-active {
      background: var(--theme-bg-main);
    }
    .site-media-background {
      position: fixed;
      inset: 0;
      z-index: 0;
      overflow: hidden;
      pointer-events: none;
      background: var(--theme-bg-main);
    }
    .site-media-background__media,
    .site-media-background__overlay {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
    }
    .site-media-background__media {
      object-fit: cover;
      object-position: center center;
      filter: saturate(1.02);
    }
    .site-shell-frame {
      position: relative;
      z-index: 1;
    }
    .glow-ring {
      box-shadow: 0 0 0.75rem rgba(var(--theme-primary-rgb), 0.4), 0 0 2.2rem rgba(var(--theme-secondary-rgb), 0.2);
    }
    .theme-panel {
      background: linear-gradient(135deg, rgba(var(--theme-bg-alt-rgb), 0.96), rgba(var(--theme-surface-rgb), 0.94));
      border: 1px solid rgba(var(--theme-border-rgb), 0.64);
      box-shadow: 0 0 22px rgba(var(--theme-primary-rgb), 0.16);
    }
    .site-brand,
    .site-brand:hover,
    .site-brand:focus,
    .site-brand:active {
      color: inherit;
      text-decoration: none;
    }
    .site-topbar-enabled .store-shell {
      padding-top: calc(var(--site-topbar-height) + 1.65rem) !important;
    }
    .site-header-topbar {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      transform: none;
      width: 100%;
      z-index: 1045;
      padding: 0.9rem 1.25rem;
      border-radius: 0;
      border: 1px solid rgba(var(--theme-topbar-search-border-rgb), 0.28);
      background: rgba(var(--theme-topbar-bg-rgb), var(--site-topbar-opacity, 0.96));
      backdrop-filter: blur(16px);
      box-shadow: 0 16px 36px rgba(4, 10, 18, 0.28), 0 0 22px rgba(var(--theme-primary-rgb), 0.12);
      transition: background-color 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.85rem;
    }
    .site-topbar-enabled .site-brand {
      flex: 0 1 auto;
      justify-content: flex-start;
      min-width: 0;
      text-decoration: none;
    }
    .site-topbar-enabled .site-brand:hover {
      opacity: 0.96;
    }
    .site-topbar-enabled .site-brand-copy p,
    .site-topbar-enabled .site-brand-copy h1 {
      color: var(--theme-topbar-text) !important;
    }
    .site-topbar-enabled .site-brand-copy p {
      opacity: 0.72;
    }
    .site-topbar-enabled .site-brand-logo {
      width: 48px;
      height: 48px;
      border-color: rgba(var(--theme-topbar-search-border-rgb), 0.42) !important;
      box-shadow: 0 0 18px rgba(var(--theme-primary-rgb), 0.16);
    }
    .site-topbar-search {
      position: relative;
      flex: 1 1 360px;
      min-width: min(100%, 260px);
      max-width: 560px;
      margin-left: auto;
    }
    .site-topbar-search-form {
      position: relative;
    }
    .site-topbar-search-input {
      width: 100%;
      min-height: 48px;
      padding: 0.8rem 3rem 0.8rem 1rem;
      border-radius: 999px;
      border: 1px solid var(--theme-topbar-search-border);
      background: rgba(var(--theme-topbar-search-bg-rgb), 0.94);
      color: var(--theme-topbar-search-text);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.04), 0 0 0 1px rgba(var(--theme-topbar-search-border-rgb), 0.08);
      outline: none;
      transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }
    .site-topbar-search-input::placeholder {
      color: rgba(var(--theme-topbar-search-text-rgb), 0.64);
    }
    .site-topbar-search-input:focus {
      border-color: var(--theme-topbar-search-border);
      box-shadow: 0 0 0 3px rgba(var(--theme-topbar-search-border-rgb), 0.14), 0 0 18px rgba(var(--theme-topbar-search-border-rgb), 0.1);
    }
    .site-topbar-search-icon {
      position: absolute;
      right: 0.95rem;
      top: 50%;
      transform: translateY(-50%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: var(--theme-topbar-search-text);
      opacity: 0.75;
      pointer-events: none;
    }
    .site-topbar-search-dropdown {
      position: absolute;
      top: calc(100% + 0.45rem);
      left: 0;
      right: 0;
      display: none;
      padding: 0.5rem;
      border-radius: 1.1rem;
      border: 1px solid rgba(var(--theme-topbar-search-border-rgb), 0.34);
      background: rgba(var(--theme-topbar-bg-rgb), 0.98);
      box-shadow: 0 18px 36px rgba(6, 12, 18, 0.32), 0 0 16px rgba(var(--theme-primary-rgb), 0.08);
      backdrop-filter: blur(20px);
    }
    .site-topbar-search-dropdown.is-visible {
      display: block;
    }
    .site-topbar-search-status {
      padding: 0.8rem 0.9rem;
      color: rgba(var(--theme-topbar-search-text-rgb), 0.74);
      font-size: 0.9rem;
    }
    .site-topbar-search-list {
      display: grid;
      gap: 0.35rem;
    }
    .site-topbar-search-item {
      width: 100%;
      display: flex;
      align-items: center;
      gap: 0.85rem;
      padding: 0.7rem 0.8rem;
      border-radius: 0.95rem;
      border: 1px solid transparent;
      background: rgba(var(--theme-topbar-search-bg-rgb), 0.82);
      color: var(--theme-topbar-search-text);
      text-align: left;
      text-decoration: none;
      transition: border-color 0.16s ease, background 0.16s ease, transform 0.16s ease;
    }
    .site-topbar-search-item:hover,
    .site-topbar-search-item.is-active {
      border-color: rgba(var(--theme-topbar-search-border-rgb), 0.48);
      background: rgba(var(--theme-topbar-search-bg-rgb), 0.98);
      transform: translateY(-1px);
    }
    .site-topbar-search-thumb {
      width: 52px;
      height: 52px;
      border-radius: 0.95rem;
      overflow: hidden;
      flex-shrink: 0;
      background: rgba(var(--theme-bg-alt-rgb), 0.88);
      border: 1px solid rgba(var(--theme-topbar-search-border-rgb), 0.22);
    }
    .site-topbar-search-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .site-topbar-search-meta {
      min-width: 0;
      flex: 1 1 auto;
    }
    .site-topbar-search-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.18rem 0.45rem;
      border-radius: 999px;
      font-size: 0.68rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--theme-topbar-login-text);
      background: var(--theme-topbar-login-bg);
    }
    .site-topbar-search-title {
      display: block;
      margin-top: 0.18rem;
      font-weight: 700;
      color: var(--theme-topbar-search-text);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .site-topbar-search-subtitle,
    .site-topbar-search-price {
      display: block;
      font-size: 0.82rem;
      color: rgba(var(--theme-topbar-search-text-rgb), 0.72);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .site-topbar-enabled .site-auth-trigger,
    .site-topbar-enabled #user-trigger {
      min-width: 0;
      border-color: var(--theme-topbar-login-border) !important;
      background: var(--theme-topbar-login-bg) !important;
      color: var(--theme-topbar-login-text) !important;
      box-shadow: 0 0 18px rgba(var(--theme-topbar-login-border-rgb), 0.14);
    }
    .site-topbar-enabled .site-auth-label,
    .site-topbar-enabled #user-trigger-name,
    .site-topbar-enabled #user-trigger .small {
      color: inherit !important;
      text-shadow: none !important;
    }
    .site-topbar-enabled #user-trigger-initials {
      background: rgba(var(--theme-topbar-text-rgb), 0.12) !important;
      border-color: rgba(var(--theme-topbar-text-rgb), 0.16) !important;
      color: var(--theme-topbar-login-text) !important;
    }
    .site-topbar-enabled #auth-menu,
    .site-topbar-enabled #user-menu {
      border-color: rgba(var(--theme-topbar-search-border-rgb), 0.44) !important;
      box-shadow: 0 16px 34px rgba(6, 12, 18, 0.28), 0 0 16px rgba(var(--theme-primary-rgb), 0.08) !important;
    }
    .site-topbar-enabled #menu-panel {
      top: calc(var(--site-topbar-height) + 0.45rem) !important;
      max-height: calc(100vh - var(--site-topbar-height) - 1.2rem) !important;
    }
    .site-topbar-mobile-search-toggle {
      display: none;
      width: 44px;
      height: 44px;
      flex: 0 0 44px;
      border-color: var(--theme-topbar-search-border) !important;
      background: rgba(var(--theme-topbar-search-bg-rgb), 0.92) !important;
      color: var(--theme-topbar-search-text) !important;
      box-shadow: 0 0 18px rgba(var(--theme-primary-rgb), 0.14);
    }
    .site-topbar-mobile-search-toggle:hover,
    .site-topbar-mobile-search-toggle:focus {
      box-shadow: 0 0 0 3px rgba(var(--theme-topbar-search-border-rgb), 0.14), 0 0 18px rgba(var(--theme-topbar-search-border-rgb), 0.12);
    }
    .site-topbar-search-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 1060;
      background: rgba(3, 9, 17, 0.7);
      backdrop-filter: blur(8px);
    }
    .site-topbar-search-modal {
      position: fixed;
      left: 50%;
      top: calc(var(--site-topbar-height) + 0.55rem);
      transform: translateX(-50%);
      width: min(92vw, 420px);
      max-width: 92vw;
      max-height: calc(100dvh - var(--site-topbar-height) - 1rem);
      overflow-y: auto;
      z-index: 1065;
      padding: 1rem;
      border-radius: 1.35rem;
      border: 1px solid rgba(var(--theme-topbar-search-border-rgb), 0.44);
      background: rgba(var(--theme-topbar-bg-rgb), 0.98);
      box-shadow: 0 20px 40px rgba(6, 12, 18, 0.34), 0 0 18px rgba(var(--theme-primary-rgb), 0.12);
      backdrop-filter: blur(20px);
    }
    .site-topbar-search-modal-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.85rem;
    }
    .site-topbar-search-modal-title {
      color: var(--theme-topbar-text);
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.16em;
      text-transform: uppercase;
      opacity: 0.72;
    }
    .site-topbar-search-modal-close {
      width: 40px;
      height: 40px;
      flex: 0 0 40px;
    }
    .site-topbar-search-modal .site-topbar-search {
      display: block !important;
      min-width: 0;
      max-width: none;
      margin-left: 0;
    }
    .site-topbar-search-modal .site-topbar-search-dropdown {
      position: static;
      left: auto;
      right: auto;
      top: auto;
      margin-top: 0.6rem;
      max-height: min(54vh, 24rem);
      overflow-y: auto;
    }
    body.site-mobile-search-open {
      overflow: hidden;
    }
    .site-topbar-mobile-search,
    .site-topbar-mobile-auth {
      display: none;
    }
    .site-topbar-mobile-search-form {
      position: relative;
    }
    .site-topbar-mobile-search-input {
      width: 100%;
      min-height: 46px;
      padding: 0.8rem 2.9rem 0.8rem 0.95rem;
      border-radius: 999px;
      border: 1px solid var(--theme-topbar-search-border);
      background: rgba(var(--theme-topbar-search-bg-rgb), 0.94);
      color: var(--theme-topbar-search-text);
      outline: none;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.04), 0 0 0 1px rgba(var(--theme-topbar-search-border-rgb), 0.08);
    }
    .site-topbar-mobile-search-input::placeholder {
      color: rgba(var(--theme-topbar-search-text-rgb), 0.64);
    }
    .site-topbar-mobile-search-icon {
      position: absolute;
      right: 0.95rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--theme-topbar-search-text);
      opacity: 0.75;
      pointer-events: none;
    }
    .site-topbar-mobile-auth {
      gap: 0.65rem;
    }
    .site-topbar-mobile-auth .btn {
      width: 100%;
    }
    @media (min-width: 768px) {
      .site-mobile-only-toggle {
        display: none !important;
      }
    }
    #menu-panel {
      scrollbar-width: thin;
      scrollbar-color: rgba(var(--theme-primary-rgb), 0.45) transparent;
    }
    #menu-panel::-webkit-scrollbar {
      width: 10px;
    }
    #menu-panel::-webkit-scrollbar-track {
      background: linear-gradient(180deg, rgba(var(--theme-bg-alt-rgb), 0.9), rgba(var(--theme-bg-main-rgb), 0.62));
      border-radius: 999px;
      border: 1px solid rgba(var(--theme-border-rgb), 0.54);
    }
    #menu-panel::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, rgba(var(--theme-primary-rgb), 0.88), rgba(var(--theme-secondary-rgb), 0.88));
      border-radius: 999px;
      box-shadow: 0 0 12px rgba(var(--theme-primary-rgb), 0.35);
      border: 1px solid rgba(var(--theme-bg-alt-rgb), 0.9);
    }
    #menu-panel::-webkit-scrollbar-thumb:hover {
      background: linear-gradient(180deg, rgba(var(--theme-highlight-rgb), 1), rgba(var(--theme-secondary-rgb), 1));
    }
    @keyframes floaty {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-6px); }
    }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(14px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 991.98px) {
      .site-topbar-enabled {
        --site-topbar-height: 92px;
      }
      .site-header-topbar {
        padding: 0.85rem 0.9rem;
        flex-wrap: nowrap;
        gap: 0.75rem;
        align-items: center;
        justify-content: flex-start !important;
      }
      .site-topbar-enabled #menu-toggle,
      .site-topbar-enabled .site-header-topbar > div[aria-hidden="true"] {
        display: none !important;
      }
      .site-topbar-enabled .site-topbar-search {
        display: none;
      }
      .site-topbar-enabled .site-brand {
        order: 1;
        flex: 0 1 auto;
        flex-grow: 0 !important;
        min-width: 0;
        margin-right: auto;
        justify-content: flex-start !important;
      }
      .site-topbar-enabled .site-auth-container {
        order: 3;
        flex: 0 0 auto;
        margin-left: 0 !important;
        display: block;
      }
      .site-topbar-mobile-search-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        order: 2;
        margin-left: 0 !important;
        flex: 0 0 44px;
      }
      .site-topbar-enabled .site-auth-trigger,
      .site-topbar-enabled #user-trigger {
        width: auto;
        min-width: 0 !important;
      }
    }
    @media (max-width: 576px) {
      .site-header-topbar {
        padding-left: 0.75rem;
        padding-right: 0.75rem;
        gap: 0.55rem;
        justify-content: flex-start !important;
      }
      .site-topbar-enabled .site-brand {
        flex: 0 1 auto;
        flex-grow: 0 !important;
        margin-right: auto;
        justify-content: flex-start !important;
      }
      .site-topbar-enabled .site-auth-container {
        flex: 0 0 auto;
        margin-left: 0 !important;
      }
      .site-topbar-mobile-search-toggle {
        margin-left: 0 !important;
        flex: 0 0 44px;
      }
      .site-topbar-search-input {
        min-height: 44px;
        padding-right: 2.7rem;
      }
      .site-topbar-enabled .site-auth-trigger,
      .site-topbar-enabled #user-trigger {
        width: auto;
        min-width: 0 !important;
      }
      body.site-topbar-enabled:not(.site-user-authenticated) .site-auth-trigger {
        width: 44px;
        height: 44px;
        min-width: 44px !important;
        padding: 0 !important;
        justify-content: center;
      }
      body.site-topbar-enabled:not(.site-user-authenticated) .site-auth-label {
        display: none;
      }
    }
    @media (max-width: 767.98px) {
      .site-topbar-enabled {
        --site-topbar-height: 84px;
      }
      .site-topbar-enabled .store-shell {
        padding-top: calc(var(--site-topbar-height) + 1rem) !important;
      }
      .site-header-topbar {
        flex-wrap: nowrap;
        gap: 0.7rem;
        padding: 0.7rem 0.8rem;
        align-items: center;
      }
      .site-topbar-enabled .site-brand {
        flex: 1 1 auto;
        min-width: 0;
        gap: 0.7rem !important;
      }
      .site-topbar-enabled .site-brand-logo {
        width: 40px;
        height: 40px;
      }
      .site-topbar-enabled .site-brand-copy {
        text-align: left !important;
      }
      .site-topbar-enabled .site-brand-copy p {
        display: none;
      }
      .site-topbar-enabled .site-brand-copy h1 {
        font-size: 0.96rem !important;
        line-height: 1.15;
      }
      .site-topbar-enabled .site-topbar-search {
        display: none;
      }
      .site-topbar-enabled .site-auth-container {
        display: block;
        margin-left: 0;
        flex: 0 1 auto;
      }
      body.site-topbar-enabled.site-user-authenticated .site-brand {
        display: inline-flex !important;
        flex: 1 1 auto;
        min-width: 0;
      }
      body.site-topbar-enabled.site-user-authenticated .site-auth-container {
        display: block;
        margin-left: auto;
        flex: 0 1 auto;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger {
        width: auto;
        min-width: 0 !important;
        max-width: calc(100vw - 148px);
        padding: 0.55rem 0.9rem !important;
        gap: 0.7rem !important;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger .small {
        display: none;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger-name {
        max-width: 150px;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger-initials {
        width: 34px !important;
        height: 34px !important;
      }
      .site-topbar-enabled .site-auth-trigger {
        padding: 0.55rem 0.78rem !important;
        min-width: 0 !important;
      }
      .site-topbar-enabled .site-auth-label {
        font-size: 0.7rem;
        white-space: nowrap;
      }
      .site-topbar-mobile-auth {
        display: none;
      }
      .site-topbar-enabled #auth-menu,
      .site-topbar-enabled #user-menu {
        position: fixed !important;
        left: 50% !important;
        right: auto !important;
        top: calc(var(--site-topbar-height) + 0.55rem) !important;
        transform: translateX(-50%) !important;
        width: min(92vw, 360px) !important;
        min-width: min(92vw, 320px) !important;
        max-width: 92vw !important;
        max-height: calc(100dvh - var(--site-topbar-height) - 1rem) !important;
        overflow-y: auto !important;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
      }
    }
    @media (max-width: 576px) {
      body.site-topbar-enabled.site-user-authenticated .site-mobile-only-toggle {
        display: none !important;
      }
      body.site-topbar-enabled.site-user-authenticated .site-brand {
        flex: 0 1 auto;
        margin-right: auto;
      }
      body.site-topbar-enabled.site-user-authenticated .site-brand-copy {
        display: none !important;
      }
      body.site-topbar-enabled.site-user-authenticated .site-brand-logo {
        width: 42px;
        height: 42px;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger {
        max-width: calc(100vw - 128px);
        padding: 0.5rem 0.8rem !important;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger-name {
        max-width: 118px;
      }
    }
    @media (max-width: 399.98px) {
      .site-topbar-enabled .site-brand {
        flex: 0 1 auto;
      }
      .site-topbar-enabled .site-brand-copy {
        display: none !important;
      }
      .site-topbar-enabled .site-brand-logo {
        width: 42px;
        height: 42px;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger {
        max-width: calc(100vw - 118px);
        gap: 0.55rem !important;
        padding: 0.46rem 0.72rem !important;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger-name {
        max-width: 94px;
      }
      body.site-topbar-enabled.site-user-authenticated #user-trigger-initials {
        width: 32px !important;
        height: 32px !important;
      }
      body.site-topbar-enabled:not(.site-user-authenticated) .site-auth-trigger {
        width: 42px;
        height: 42px;
        min-width: 42px !important;
      }
    }
  </style>
  <script>
    window.__TVG_BASE_PATH = <?php echo json_encode(app_base_path(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    window.__TVG_API_PEDIDOS = <?php echo json_encode(app_path('/api/pedidos.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    window.__TVG_API_ACCOUNT = <?php echo json_encode(app_path('/api/account.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    window.__TVG_SEARCH_ENDPOINT = <?php echo json_encode($searchEndpointUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    window.__TVG_SEARCH_RESULTS = <?php echo json_encode($searchResultsUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    document.addEventListener('DOMContentLoaded', function() {
      var publicBackgroundVideo = document.querySelector('[data-site-background-video]');
      if (publicBackgroundVideo) {
        var desiredVolume = Number(publicBackgroundVideo.getAttribute('data-volume') || '0');
        var soundEnabled = publicBackgroundVideo.getAttribute('data-sound-enabled') === '1';
        var startBackgroundPlayback = function() {
          var playPromise = publicBackgroundVideo.play();
          if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(function() {});
          }
        };

        publicBackgroundVideo.volume = Math.max(0, Math.min(1, desiredVolume));
        publicBackgroundVideo.muted = !soundEnabled;
        startBackgroundPlayback();

        if (soundEnabled) {
          var unlockBackgroundAudio = function() {
            publicBackgroundVideo.muted = false;
            publicBackgroundVideo.volume = Math.max(0, Math.min(1, desiredVolume));
            startBackgroundPlayback();
          };

          document.addEventListener('click', unlockBackgroundAudio, { once: true });
          document.addEventListener('touchstart', unlockBackgroundAudio, { once: true });
          document.addEventListener('keydown', unlockBackgroundAudio, { once: true });
        }
      }

      var menuToggle = document.getElementById('menu-toggle');
      var menuPanel = document.getElementById('menu-panel');
      var menuOverlay = document.getElementById('menu-overlay');
      var menuClose = document.getElementById('menu-close');
      var mobileSearchToggle = document.getElementById('mobile-search-toggle');
      var mobileSearchOverlay = document.getElementById('mobile-search-overlay');
      var mobileSearchModal = document.getElementById('mobile-search-modal');
      var mobileSearchClose = document.getElementById('mobile-search-close');
      var openMobileSearchModal = function() {
        if (!mobileSearchOverlay || !mobileSearchModal) {
          return;
        }
        if (menuPanel) {
          menuPanel.classList.add('d-none');
        }
        if (menuOverlay) {
          menuOverlay.classList.add('d-none');
        }
        mobileSearchOverlay.classList.remove('d-none');
        mobileSearchModal.classList.remove('d-none');
        document.body.classList.add('site-mobile-search-open');
        var modalSearchInput = mobileSearchModal.querySelector('[data-public-search-input]');
        if (modalSearchInput) {
          window.setTimeout(function() {
            modalSearchInput.focus();
          }, 40);
        }
      };
      var closeMobileSearchModal = function() {
        if (!mobileSearchOverlay || !mobileSearchModal) {
          return;
        }
        mobileSearchOverlay.classList.add('d-none');
        mobileSearchModal.classList.add('d-none');
        document.body.classList.remove('site-mobile-search-open');
      };
      if (menuToggle && menuPanel && menuOverlay) {
        menuToggle.addEventListener('click', function() {
          menuPanel.classList.remove('d-none');
          menuOverlay.classList.remove('d-none');
        });
        menuOverlay.addEventListener('click', function() {
          menuPanel.classList.add('d-none');
          menuOverlay.classList.add('d-none');
        });
      }
      if (menuClose) {
        menuClose.addEventListener('click', function() {
          menuPanel.classList.add('d-none');
          menuOverlay.classList.add('d-none');
        });
      }
      if (mobileSearchToggle) {
        mobileSearchToggle.addEventListener('click', function() {
          openMobileSearchModal();
        });
      }
      if (mobileSearchOverlay) {
        mobileSearchOverlay.addEventListener('click', function() {
          closeMobileSearchModal();
        });
      }
      if (mobileSearchClose) {
        mobileSearchClose.addEventListener('click', function() {
          closeMobileSearchModal();
        });
      }

      var siteTopbar = document.querySelector('[data-site-topbar="1"]');
      if (siteTopbar) {
        var updateTopbarOpacity = function() {
          var scrollY = window.scrollY || window.pageYOffset || 0;
          var progress = Math.min(1, scrollY / 260);
          var opacity = (0.96 - (progress * 0.54)).toFixed(3);
          siteTopbar.style.setProperty('--site-topbar-opacity', opacity);
        };

        updateTopbarOpacity();
        window.addEventListener('scroll', updateTopbarOpacity, { passive: true });
      }

      Array.prototype.forEach.call(document.querySelectorAll('[data-public-search]'), function(searchRoot) {
        var searchForm = searchRoot.querySelector('[data-public-search-form]');
        var searchInput = searchRoot.querySelector('[data-public-search-input]');
        var searchDropdown = searchRoot.querySelector('[data-public-search-results]');
        var searchList = searchRoot.querySelector('[data-public-search-list]');
        var searchStatus = searchRoot.querySelector('[data-public-search-status]');
        var fetchTimer = 0;
        var activeIndex = -1;
        var searchItems = [];
        var searchController = null;

        var searchEscapeHtml = function(value) {
          return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
        };

        var hideSearchDropdown = function() {
          if (searchDropdown) {
            searchDropdown.classList.remove('is-visible');
          }
          activeIndex = -1;
        };

        var setSearchStatus = function(message) {
          if (searchStatus) {
            searchStatus.textContent = message;
          }
        };

        var navigateToSearchItem = function(index) {
          var item = searchItems[index] || null;
          if (!item || !item.url) {
            return;
          }
          window.location.href = item.url;
        };

        var highlightSearchItem = function(index) {
          activeIndex = index;
          if (!searchList) {
            return;
          }
          Array.prototype.forEach.call(searchList.querySelectorAll('[data-search-item-index]'), function(node) {
            var itemIndex = Number(node.getAttribute('data-search-item-index') || '-1');
            node.classList.toggle('is-active', itemIndex === activeIndex);
          });
        };

        var renderSearchItems = function(items) {
          searchItems = Array.isArray(items) ? items : [];
          activeIndex = -1;
          if (!searchList || !searchDropdown) {
            return;
          }
          if (!searchItems.length) {
            searchList.innerHTML = '';
            setSearchStatus('No hay coincidencias con ese texto.');
            searchDropdown.classList.add('is-visible');
            return;
          }

          setSearchStatus('Selecciona un resultado o presiona Enter para ver la búsqueda completa.');
          searchList.innerHTML = searchItems.map(function(item, index) {
            var subtitle = item.type === 'package' && item.game_name
              ? 'Paquete de ' + item.game_name
              : 'Juego disponible';
            var price = item.price_label ? '<span class="site-topbar-search-price">' + searchEscapeHtml(item.price_label) + '</span>' : '';
            var image = item.image_url
              ? '<img src="' + searchEscapeHtml(item.image_url) + '" alt="' + searchEscapeHtml(item.name || '') + '">'
              : '<div class="w-100 h-100 d-flex align-items-center justify-content-center fw-bold text-info">' + (item.type === 'package' ? 'PK' : 'JG') + '</div>';
            return '' +
              '<button type="button" class="site-topbar-search-item" data-search-item-index="' + index + '" data-search-item-href="' + searchEscapeHtml(item.url || '') + '">' +
                '<span class="site-topbar-search-thumb">' + image + '</span>' +
                '<span class="site-topbar-search-meta">' +
                  '<span class="site-topbar-search-badge">' + searchEscapeHtml(item.badge || '') + '</span>' +
                  '<span class="site-topbar-search-title">' + searchEscapeHtml(item.name || '') + '</span>' +
                  '<span class="site-topbar-search-subtitle">' + searchEscapeHtml(subtitle) + '</span>' +
                  price +
                '</span>' +
              '</button>';
          }).join('');

          Array.prototype.forEach.call(searchList.querySelectorAll('[data-search-item-index]'), function(button) {
            button.addEventListener('click', function() {
              var index = Number(button.getAttribute('data-search-item-index') || '-1');
              navigateToSearchItem(index);
            });
          });
          searchDropdown.classList.add('is-visible');
        };

        var requestSearchItems = function(term) {
          if (!searchList || !searchDropdown) {
            return;
          }
          if (term.length < 2) {
            searchList.innerHTML = '';
            setSearchStatus('Escribe al menos 2 letras para buscar juegos o paquetes.');
            hideSearchDropdown();
            return;
          }

          if (searchController && typeof searchController.abort === 'function') {
            searchController.abort();
          }
          searchController = typeof AbortController !== 'undefined' ? new AbortController() : null;
          setSearchStatus('Buscando coincidencias...');
          searchDropdown.classList.add('is-visible');

          var endpointUrl = new URL(window.__TVG_SEARCH_ENDPOINT || '', window.location.origin);
          endpointUrl.searchParams.set('q', term);
          endpointUrl.searchParams.set('limit', '8');

          fetch(endpointUrl.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: searchController ? searchController.signal : undefined
          })
            .then(function(response) { return response.json(); })
            .then(function(payload) {
              renderSearchItems(payload.items || []);
            })
            .catch(function(error) {
              if (error && error.name === 'AbortError') {
                return;
              }
              searchList.innerHTML = '';
              setSearchStatus('No se pudo cargar la búsqueda en este momento.');
              searchDropdown.classList.add('is-visible');
            });
        };

        if (searchInput) {
          searchInput.addEventListener('input', function() {
            var term = searchInput.value.trim();
            window.clearTimeout(fetchTimer);
            fetchTimer = window.setTimeout(function() {
              requestSearchItems(term);
            }, 180);
          });

          searchInput.addEventListener('focus', function() {
            if (searchInput.value.trim().length >= 2 && searchItems.length) {
              searchDropdown.classList.add('is-visible');
            }
          });

          searchInput.addEventListener('keydown', function(event) {
            if (!searchItems.length) {
              return;
            }

            if (event.key === 'ArrowDown') {
              event.preventDefault();
              highlightSearchItem((activeIndex + 1) % searchItems.length);
            } else if (event.key === 'ArrowUp') {
              event.preventDefault();
              highlightSearchItem(activeIndex <= 0 ? searchItems.length - 1 : activeIndex - 1);
            } else if (event.key === 'Enter' && activeIndex >= 0) {
              event.preventDefault();
              navigateToSearchItem(activeIndex);
            } else if (event.key === 'Escape') {
              hideSearchDropdown();
            }
          });
        }

        if (searchForm) {
          searchForm.addEventListener('submit', function() {
            hideSearchDropdown();
          });
        }

        document.addEventListener('click', function(event) {
          if (!searchRoot.contains(event.target)) {
            hideSearchDropdown();
          }
        });
      });

      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
          closeMobileSearchModal();
        }
      });
    });
  </script>
</head>
<body class="bg-dark text-light min-vh-100<?php echo $renderPublicMediaBackground ? ' site-media-background-active' : ''; ?><?php echo $topBarEnabled ? ' site-topbar-enabled' : ''; ?><?php echo $authUser ? ' site-user-authenticated' : ''; ?>">
  <?php if ($renderPublicMediaBackground): ?>
  <div class="site-media-background" aria-hidden="true">
    <?php if ($publicBackgroundMediaType === 'video'): ?>
      <video
        class="site-media-background__media"
        src="<?php echo htmlspecialchars($publicBackgroundMediaUrl, ENT_QUOTES, 'UTF-8'); ?>"
        autoplay
        loop
        playsinline
        preload="auto"
        data-site-background-video
        data-sound-enabled="<?php echo !empty($publicBackgroundSettings['sound_enabled']) ? '1' : '0'; ?>"
        data-volume="<?php echo htmlspecialchars((string) ($publicBackgroundSettings['volume_ratio'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
      ></video>
    <?php else: ?>
      <img class="site-media-background__media" src="<?php echo htmlspecialchars($publicBackgroundMediaUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
    <?php endif; ?>
    <div class="site-media-background__overlay" style="background:<?php echo htmlspecialchars($publicBackgroundOverlay, ENT_QUOTES, 'UTF-8'); ?>;"></div>
  </div>
  <?php endif; ?>
  <div class="position-relative min-vh-100 overflow-hidden site-shell-frame">
    <div class="position-absolute top-0 start-50 translate-middle-x rounded-circle" style="height:18rem;width:18rem;background:rgba(var(--theme-primary-rgb),0.15);filter:blur(48px);pointer-events:none;"></div>
    <div class="position-absolute bottom-0 end-0 rounded-circle" style="height:16rem;width:16rem;background:rgba(var(--theme-success-rgb),0.10);filter:blur(48px);pointer-events:none;"></div>

    <div class="container-lg store-shell position-relative pb-5 pt-4" data-tenant="<?php echo htmlspecialchars($tenantSlugAttr, ENT_QUOTES, "UTF-8"); ?>">
      <header class="site-header d-flex align-items-center justify-content-between gap-3<?php echo $topBarEnabled ? ' site-header-topbar' : ''; ?>"<?php echo $topBarEnabled ? ' data-site-topbar="1"' : ''; ?>>
        <?php if ($showMenuToggle): ?>
        <button id="menu-toggle" class="btn btn-outline-info rounded-circle d-flex align-items-center justify-content-center<?php echo $mobileOnlyMenuToggle ? ' site-mobile-only-toggle' : ''; ?>" style="width:44px;height:44px;" aria-label="Abrir menú">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" class="bi bi-list" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M2.5 12.5a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1h-10a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1h-10a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1h-10a.5.5 0 0 1-.5-.5z"/>
          </svg>
        </button>
        <?php elseif ($topBarEnabled): ?>
        <div aria-hidden="true" style="width:44px;height:44px;flex:0 0 44px;"></div>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>" class="site-brand d-flex align-items-center justify-content-center gap-3 flex-grow-1 flex-sm-grow-0">
          <?php if ($brandLogo !== ''): ?>
            <div class="site-brand-logo rounded-4 overflow-hidden border border-info glow-ring flex-shrink-0" style="width:52px;height:52px;background:rgba(var(--theme-bg-alt-rgb),0.82);">
              <img src="<?php echo htmlspecialchars($brandLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo de la tienda" class="w-100 h-100 object-fit-cover" />
            </div>
          <?php endif; ?>
          <div class="site-brand-copy text-center text-sm-start min-w-0">
            <p class="small text-uppercase text-info mb-0" style="letter-spacing:0.3em;"><?php echo htmlspecialchars($brandPrefix, ENT_QUOTES, 'UTF-8'); ?></p>
            <h1 class="fw-bold mb-0" style="font-family:'Oxanium', 'Space Grotesk', sans-serif;font-size:1.25rem;color:var(--theme-text);"><?php echo htmlspecialchars($brandName, ENT_QUOTES, "UTF-8"); ?></h1>
          </div>
        </a>
        <?php if ($topBarEnabled): ?>
          <div class="site-topbar-search" data-public-search>
            <form method="get" action="<?php echo htmlspecialchars($searchResultsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="site-topbar-search-form" data-public-search-form>
              <input type="search" name="q" class="site-topbar-search-input" data-public-search-input placeholder="Buscar juegos o paquetes" autocomplete="off">
              <span class="site-topbar-search-icon" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.85-5.15a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" />
                </svg>
              </span>
            </form>
            <div class="site-topbar-search-dropdown" data-public-search-results>
              <div class="site-topbar-search-status" data-public-search-status>Escribe al menos 2 letras para buscar juegos o paquetes.</div>
              <div class="site-topbar-search-list" data-public-search-list></div>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($topBarEnabled): ?>
        <button id="mobile-search-toggle" type="button" class="site-topbar-mobile-search-toggle btn btn-outline-info rounded-circle" aria-label="Abrir búsqueda">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.85-5.15a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" />
          </svg>
        </button>
        <?php endif; ?>
        <div id="auth-container" class="site-auth-container position-relative">
          <?php if (!$authUser): ?>
            <button id="auth-trigger" type="button" class="site-auth-trigger d-flex align-items-center gap-2 neon-btn border border-info bg-dark px-2 py-1 text-uppercase fw-bold text-info shadow-sm" style="font-size:11px;box-shadow:0 0 8px rgba(var(--theme-primary-rgb),0.8), 0 0 2px rgba(var(--theme-secondary-rgb),0.8);transition:box-shadow 0.2s;min-width:120px;">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="width:18px;height:18px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20.118a7.5 7.5 0 0115 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.5-1.632z" />
              </svg>
              <span class="site-auth-label" style="text-shadow:0 0 4px rgba(var(--theme-primary-rgb),0.92), 0 0 1px rgba(var(--theme-secondary-rgb),0.92);">Iniciar Sesión / Registrarse</span>
            </button>
            <div id="auth-menu" class="position-absolute end-0 mt-2 z-3 d-none" style="min-width:160px;max-width:220px;box-shadow:0 0 16px rgba(var(--theme-primary-rgb),0.72), 0 0 4px rgba(var(--theme-secondary-rgb),0.6);border-radius:0.75rem;border:1.5px solid var(--theme-primary);background:var(--theme-surface-alt);padding:0.75rem;">
              <button type="button" class="btn btn-info neon-btn-info w-100 rounded-3 border mb-2 fw-bold text-uppercase shadow-sm" style="font-size:12px;" data-auth-open="login">Iniciar sesión</button>
              <button type="button" class="btn btn-warning neon-btn w-100 rounded-3 border fw-bold text-uppercase shadow-sm" style="font-size:12px;" data-auth-open="register">Registrarse</button>
            </div>
          <?php else: ?>
            <button id="user-trigger" type="button" class="btn btn-admin d-inline-flex align-items-center gap-3 rounded-pill px-3 py-2 shadow-sm border border-info" style="background:linear-gradient(90deg,var(--theme-button-primary) 0%,var(--theme-button-secondary) 100%);color:var(--theme-button-text);min-width:210px;box-shadow:0 0 16px rgba(var(--theme-button-primary-rgb),0.28);">
              <span id="user-trigger-initials" class="d-inline-flex align-items-center justify-content-center rounded-circle fw-bold overflow-hidden" style="width:38px;height:38px;background:rgba(var(--theme-bg-main-rgb),0.18);border:1px solid rgba(var(--theme-bg-main-rgb),0.2);font-family:'Oxanium',sans-serif;">
                <img id="user-trigger-avatar" src="<?php echo htmlspecialchars($authUserProfileImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto de perfil" class="w-100 h-100 object-fit-cover<?php echo $authUserProfileImageUrl === '' ? ' d-none' : ''; ?>">
                <span id="user-trigger-initials-text" class="<?php echo $authUserProfileImageUrl !== '' ? 'd-none' : ''; ?>"><?php echo htmlspecialchars($authUserInitials, ENT_QUOTES, 'UTF-8'); ?></span>
              </span>
              <span class="d-flex flex-column align-items-start text-start lh-sm flex-grow-1 overflow-hidden">
                <span class="small text-uppercase fw-bold" style="letter-spacing:0.15em;opacity:0.7;">Mi cuenta</span>
                <span id="user-trigger-name" class="fw-bold text-truncate w-100"><?php echo htmlspecialchars($authUserName, ENT_QUOTES, 'UTF-8'); ?></span>
              </span>
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <div id="user-menu" class="position-absolute end-0 mt-2 z-3 d-none" style="min-width:240px;max-width:280px;box-shadow:0 0 16px rgba(var(--theme-primary-rgb),0.72), 0 0 4px rgba(var(--theme-secondary-rgb),0.6);border-radius:1rem;border:1.5px solid var(--theme-primary);background:var(--theme-surface-alt);padding:0.85rem;">
              <div class="px-2 pb-2 mb-2 border-bottom border-info-subtle">
                <div id="user-menu-name" class="fw-bold text-light"><?php echo htmlspecialchars($authUserName, ENT_QUOTES, 'UTF-8'); ?></div>
                <div id="user-menu-email" class="small text-info text-break"><?php echo htmlspecialchars($authUserEmail, ENT_QUOTES, 'UTF-8'); ?></div>
              </div>
              <?php if ($winPointsProgramEnabled): ?>
              <div id="user-menu-rewards-card" class="rounded-4 border border-info-subtle px-3 py-3 mb-2" style="background:rgba(8,15,24,0.82);box-shadow:0 0 14px rgba(var(--theme-primary-rgb),0.12);">
                <div class="d-flex align-items-center gap-2 min-w-0 mb-3">
                    <?php if (($winPointsProgramConfig['icon_url'] ?? '') !== ''): ?>
                      <img src="<?php echo htmlspecialchars((string) $winPointsProgramConfig['icon_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) ($winPointsProgramConfig['name'] ?? 'Win Points'), ENT_QUOTES, 'UTF-8'); ?>" style="width:30px;height:30px;border-radius:10px;object-fit:cover;border:1px solid rgba(var(--theme-primary-rgb),0.28);">
                    <?php else: ?>
                      <span class="d-inline-flex align-items-center justify-content-center rounded-3 fw-bold text-info" style="width:30px;height:30px;background:rgba(var(--theme-primary-rgb),0.12);border:1px solid rgba(var(--theme-primary-rgb),0.22);">WP</span>
                    <?php endif; ?>
                    <div id="user-menu-rewards-name" class="fw-semibold text-light text-truncate"><?php echo htmlspecialchars((string) ($winPointsProgramConfig['name'] ?? 'Win Points'), ENT_QUOTES, 'UTF-8'); ?></div>
                  </div>
                <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
                  <div class="small text-secondary">Saldo disponible</div>
                  <div id="user-menu-rewards-balance" class="fw-bold text-info"><?php echo number_format((int) ($winPointsUserSummary['balance'] ?? 0)); ?></div>
                </div>
                <div class="d-flex align-items-center justify-content-between gap-3">
                  <div class="small text-warning">Dias de vencimiento</div>
                  <div id="user-menu-rewards-expiration" class="small fw-semibold text-warning text-end"><?php echo htmlspecialchars($winPointsMenuExpirationText, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              </div>
              <button type="button" class="btn btn-outline-info w-100 rounded-3 border mb-2 fw-semibold" data-user-open="rewards">Mis <?php echo htmlspecialchars((string) ($winPointsProgramConfig['name'] ?? 'Win Points'), ENT_QUOTES, 'UTF-8'); ?></button>
              <?php endif; ?>
              <?php if ($topBarEnabled): ?>
                <a href="<?php echo htmlspecialchars($gamesUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-info w-100 rounded-3 border mb-2 fw-semibold">Juegos</a>
                <a href="<?php echo htmlspecialchars($influencerJoinUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-warning w-100 rounded-3 border mb-2 fw-semibold"><?php echo htmlspecialchars($influencerInstructionsMenuLabel !== '' ? $influencerInstructionsMenuLabel : 'Quiero Unirme', ENT_QUOTES, 'UTF-8'); ?></a>
                <?php if ($authUserCanAccessAdmin): ?>
                  <a href="<?php echo htmlspecialchars($authUserAdminHome, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-light w-100 rounded-3 border mb-2 fw-semibold">Dashboard</a>
                <?php endif; ?>
              <?php endif; ?>
              <button type="button" class="btn btn-admin w-100 rounded-3 border mb-2 fw-semibold" data-user-open="orders">Ver Pedidos</button>
              <button type="button" class="btn btn-outline-info w-100 rounded-3 border mb-2 fw-semibold" data-user-open="profile">Datos Usuario</button>
              <a href="<?php echo htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-danger w-100 rounded-3 border fw-semibold">Cerrar sesión</a>
            </div>
          <?php endif; ?>
        </div>
      </header>

      <?php
      $authFlash = $_SESSION["auth_flash"] ?? null;
      if ($authFlash) {
        unset($_SESSION["auth_flash"]);
      }
      ?>
      <?php if (!empty($authFlash["message"])): ?>
        <?php
          $flashType = $authFlash["type"] ?? "info";
          $flashClasses = $flashType === "success"
            ? "border-emerald-400/30 bg-emerald-500/10 text-emerald-200"
            : ($flashType === "error" ? "border-rose-400/30 bg-rose-500/10 text-rose-200" : "border-cyan-400/30 bg-cyan-500/10 text-cyan-200");
        ?>
        <div class="mt-4 rounded-3 border px-3 py-2 small <?php echo $flashClasses; ?>">
          <?php echo htmlspecialchars($authFlash["message"], ENT_QUOTES, "UTF-8"); ?>
        </div>
      <?php endif; ?>

        <div id="menu-overlay" class="position-fixed top-0 start-0 w-100 h-100 d-none" style="background:var(--theme-overlay-strong);backdrop-filter:blur(4px);z-index:1040;"></div>
        <nav id="menu-panel" class="position-fixed start-50 top-0 translate-middle-x d-none w-100" style="max-width:420px;max-height:calc(100vh - 96px);overflow-y:auto;border-radius:1.5rem;border:2px solid var(--theme-primary);background:var(--theme-panel-bg);padding:1.5rem;box-shadow:var(--theme-shadow-primary), var(--theme-shadow-secondary);z-index:1050;">
          <button id="menu-close" class="btn btn-outline-info rounded-circle position-absolute end-0 top-0 m-3 d-flex align-items-center justify-content-center" style="width:40px;height:40px;box-shadow:0 0 12px var(--theme-primary);z-index:1060;" aria-label="Cerrar menú">
          <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" class="bi bi-x" viewBox="0 0 16 16">
            <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
          </svg>
        </button>
        <div class="d-flex align-items-center justify-content-between">
          <p class="small text-uppercase text-secondary mb-0" style="letter-spacing:0.35em;">Menu</p>
        </div>
        <?php if ($topBarEnabled): ?>
          <?php if (!$authUser): ?>
            <div class="site-topbar-mobile-auth d-md-none">
              <button type="button" class="btn btn-info neon-btn-info rounded-3 border fw-bold text-uppercase shadow-sm" data-auth-open="login">Iniciar sesión</button>
              <button type="button" class="btn btn-warning neon-btn rounded-3 border fw-bold text-uppercase shadow-sm" data-auth-open="register">Registrarse</button>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="mt-4 d-grid gap-2">
          <a href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-dark border rounded-3 px-4 py-3 fw-semibold">Inicio</a>
          <a href="<?php echo htmlspecialchars($popularUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-dark border rounded-3 px-4 py-3 fw-semibold">Juegos populares</a>
          <a href="<?php echo htmlspecialchars($gamesUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-dark border rounded-3 px-4 py-3 fw-semibold">Juegos</a>
          <?php if ($influencerInstructionsEnabled): ?>
            <a href="<?php echo htmlspecialchars($influencerJoinUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn rounded-3 px-4 py-3 fw-semibold" style="background:linear-gradient(90deg,#f59e0b 0%,#facc15 100%); color:#111827; border:1px solid rgba(250,204,21,0.45); box-shadow:0 0 18px rgba(245,158,11,0.28);"><?php echo $influencerInstructionsMenuLabel; ?></a>
          <?php endif; ?>
          <?php if ($authUser): ?>
            <?php if ($authUserCanAccessAdmin): ?>
              <hr class="my-2 border-slate-700">
              <?php if ($authUserRole === 'influencer'): ?>
                <a href="<?php echo htmlspecialchars($authUserAdminHome, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Cupones</a>
              <?php else: ?>
                <a href="<?php echo htmlspecialchars($adminDashboardUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Dashboard</a>
                <a href="<?php echo htmlspecialchars($adminOrdersUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Pedidos</a>
                <a href="<?php echo htmlspecialchars($adminMovementsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Movimientos</a>
                <?php if (in_array($authUserRole, ['admin', 'root'], true)): ?>
                  <a href="<?php echo htmlspecialchars($adminGamesUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Juegos</a>
                  <a href="<?php echo htmlspecialchars($adminCurrenciesUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Monedas</a>
                  <a href="<?php echo htmlspecialchars($adminUsersUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Usuarios</a>
                  <a href="<?php echo htmlspecialchars($adminCouponsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Cupones</a>
                  <a href="<?php echo htmlspecialchars($adminExtraFeaturesUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Funciones Extra</a>
                  <?php if ($influencerInstructionsEnabled): ?>
                    <a href="<?php echo htmlspecialchars($adminInfluencerInstructionsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Instrucciones Influencer</a>
                  <?php endif; ?>
                  <a href="<?php echo htmlspecialchars($adminConfigUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Configuración</a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($authUserAdminHome, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-admin border rounded-3 px-4 py-3 fw-semibold">Ir al Admin</a>
              <?php endif; ?>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-danger border rounded-3 px-4 py-3 fw-semibold">Cerrar sesión</a>
          <?php endif; ?>
        </div>
      </nav>

      <?php if ($topBarEnabled): ?>
      <div id="mobile-search-overlay" class="site-topbar-search-modal-overlay d-none"></div>
      <div id="mobile-search-modal" class="site-topbar-search-modal d-none" role="dialog" aria-modal="true" aria-labelledby="mobile-search-title">
        <div class="site-topbar-search-modal-head">
          <div id="mobile-search-title" class="site-topbar-search-modal-title">Buscar</div>
          <button id="mobile-search-close" type="button" class="site-topbar-search-modal-close btn btn-outline-info rounded-circle d-inline-flex align-items-center justify-content-center" aria-label="Cerrar búsqueda">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
              <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
            </svg>
          </button>
        </div>
        <div class="site-topbar-search" data-public-search>
          <form method="get" action="<?php echo htmlspecialchars($searchResultsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="site-topbar-search-form" data-public-search-form>
            <input type="search" name="q" class="site-topbar-search-input" data-public-search-input placeholder="Buscar juegos o paquetes" autocomplete="off">
            <span class="site-topbar-search-icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0 1 14 0Z" />
              </svg>
            </span>
          </form>
          <div class="site-topbar-search-dropdown" data-public-search-results>
            <div class="site-topbar-search-status" data-public-search-status>Escribe al menos 2 letras para buscar juegos o paquetes.</div>
            <div class="site-topbar-search-list" data-public-search-list></div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div id="auth-modal" class="position-fixed top-0 start-0 w-100 h-100 d-none d-flex align-items-center justify-content-center px-4" style="z-index:13000;" data-auth-initial-mode="<?php echo htmlspecialchars($authModalInitialMode, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="position-absolute top-0 start-0 w-100 h-100" style="background:var(--theme-overlay-soft);backdrop-filter:blur(6px);box-shadow:var(--theme-shadow-primary), var(--theme-shadow-secondary);z-index:11000;" data-auth-close></div>
        <div class="position-relative w-100 neon-modal" style="max-width:420px;border-radius:1.5rem;border:2px solid var(--theme-primary);background:var(--theme-panel-gradient);padding:2rem 1.5rem;box-shadow:var(--theme-shadow-primary), var(--theme-shadow-secondary);animation:fadeUp 320ms ease-out both;z-index:12000;">
          <button type="button" data-auth-close class="position-absolute" style="top:18px;right:18px;width:48px;height:48px;border-radius:50%;background:var(--theme-primary-soft);border:2px solid var(--theme-primary);display:flex;align-items:center;justify-content:center;z-index:13001;box-shadow:0 0 12px var(--theme-primary);" aria-label="Cerrar">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="var(--theme-primary)" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <div id="auth-login" class="d-grid gap-4">
            <div>
              <p class="small text-uppercase text-neon mb-0" style="letter-spacing:0.35em;">Cuenta de usuario</p>
              <h2 class="mt-2 text-neon fw-bold" style="font-family:'Oxanium',sans-serif;font-size:2rem;text-shadow:0 0 8px var(--theme-primary);">Iniciar sesión</h2>
            </div>
            <form action="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>" method="post" class="d-grid gap-4" novalidate>
              <?php if ($authModalInlineMessage !== ''): ?>
                <div class="rounded-3 border px-3 py-2 small" style="border-color:rgba(248,113,113,0.45); background:rgba(127,29,29,0.22); color:#fecaca; box-shadow:0 0 14px rgba(248,113,113,0.08);">
                  <?php echo htmlspecialchars($authModalInlineMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
              <?php endif; ?>
              <div class="d-grid gap-3">
                <label class="form-label small text-neon">Correo electrónico</label>
                <input type="email" name="email" autocomplete="email" value="<?php echo htmlspecialchars($authModalLoginEmail, ENT_QUOTES, 'UTF-8'); ?>" class="form-control rounded-3 bg-dark text-neon border border-info" placeholder="nombre@correo.com" />
                <label class="form-label small text-neon">Contraseña</label>
                <div class="position-relative">
                  <input type="password" name="password" autocomplete="current-password" class="form-control rounded-3 bg-dark text-neon border border-info pe-5" placeholder="Ingresa tu contraseña" id="login-password" />
                  <button type="button" class="btn position-absolute end-0 top-50 translate-middle-y d-inline-flex align-items-center justify-content-center text-info" style="width:46px;height:46px;border:none;background:transparent;box-shadow:none;" data-password-toggle="login-password" data-password-label-show="Mostrar contraseña" data-password-label-hide="Ocultar contraseña" aria-label="Mostrar contraseña" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" data-password-icon="hidden">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12.001C3.226 16.273 7.322 19.5 12 19.5c1.658 0 3.237-.336 4.677-.947M6.228 6.228A9.956 9.956 0 0112 4.5c4.677 0 8.773 3.227 10.065 7.499a10.523 10.523 0 01-4.293 5.774M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" data-password-icon="visible" class="d-none">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  </button>
                </div>
              </div>
              <button type="submit" class="btn btn-info neon-btn-info w-100 rounded-3 px-4 py-2 fw-bold text-uppercase shadow">Iniciar sesión</button>
              <a href="<?php echo htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8'); ?>" class="d-block w-100 text-center small fw-bold text-neon">¿Has olvidado la contraseña?</a>
            </form>
            <?php if ($googleAuthEnabled): ?>
              <div class="d-grid gap-3">
                <div class="d-flex align-items-center gap-3 small text-neon" aria-hidden="true">
                  <span class="flex-grow-1" style="height:1px;background:rgba(var(--theme-primary-rgb),0.32);"></span>
                  <span>o</span>
                  <span class="flex-grow-1" style="height:1px;background:rgba(var(--theme-primary-rgb),0.32);"></span>
                </div>
                <a href="<?php echo htmlspecialchars($googleAuthLoginUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn w-100 rounded-3 px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center justify-content-center gap-2" style="background:#ffffff;color:#111827;border:1px solid rgba(255,255,255,0.78);">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303C33.654 32.657 29.233 36 24 36c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.27 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                    <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.27 4 24 4c-7.682 0-14.289 4.337-17.694 10.691z"/>
                    <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238C29.143 35.091 26.715 36 24 36c-5.212 0-9.62-3.329-11.283-7.946l-6.522 5.025C9.56 39.556 16.618 44 24 44z"/>
                    <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303c-.793 2.238-2.231 4.166-4.084 5.571.001-.001 6.19 5.238 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
                  </svg>
                  <span>Continuar con Google</span>
                </a>
              </div>
            <?php endif; ?>
            <button type="button" data-auth-switch="register" class="btn btn-link w-100 small fw-semibold text-info">¿No tienes una cuenta? Regístrate ahora</button>
          </div>

          <div id="auth-register" class="d-none d-grid gap-4">
            <div>
              <p class="small text-uppercase text-neon mb-0" style="letter-spacing:0.35em;">Cuenta</p>
              <h2 class="mt-2 text-neon fw-bold" style="font-family:'Oxanium',sans-serif;font-size:2rem;text-shadow:0 0 8px var(--theme-primary);">Crear cuenta</h2>
              <p class="mt-1 small text-neon">Regístrate para empezar a operar en <?php echo htmlspecialchars($brandName, ENT_QUOTES, "UTF-8"); ?>.</p>
            </div>
            <form id="registro-form" class="d-grid gap-4" novalidate autocomplete="off">
              <div class="d-grid gap-3">
                <label class="form-label small text-neon">Nombre completo</label>
                <input type="text" id="nombre" autocomplete="name" class="form-control rounded-3 bg-dark text-neon border border-info" placeholder="Ej. Juan Pérez" required />
                <label class="form-label small text-neon">Correo electrónico</label>
                <input type="email" id="correo" autocomplete="email" class="form-control rounded-3 bg-dark text-neon border border-info" placeholder="nombre@correo.com" required />
                <label class="form-label small text-neon">Número de teléfono</label>
                <input type="tel" id="telefono" autocomplete="tel" class="form-control rounded-3 bg-dark text-neon border border-info" placeholder="+58 412 0000000" />
                <label class="form-label small text-neon">Contraseña</label>
                <div class="position-relative">
                  <input type="password" id="contrasena" autocomplete="new-password" class="form-control rounded-3 bg-dark text-neon border border-info pe-5" placeholder="Crea una contraseña segura" required />
                  <button type="button" class="btn position-absolute end-0 top-50 translate-middle-y d-inline-flex align-items-center justify-content-center text-info" style="width:46px;height:46px;border:none;background:transparent;box-shadow:none;" data-password-toggle="contrasena" data-password-label-show="Mostrar contraseña" data-password-label-hide="Ocultar contraseña" aria-label="Mostrar contraseña" aria-pressed="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" data-password-icon="hidden">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12.001C3.226 16.273 7.322 19.5 12 19.5c1.658 0 3.237-.336 4.677-.947M6.228 6.228A9.956 9.956 0 0112 4.5c4.677 0 8.773 3.227 10.065 7.499a10.523 10.523 0 01-4.293 5.774M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" data-password-icon="visible" class="d-none">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  </button>
                </div>
              </div>
              <button type="submit" id="registro-btn" class="btn btn-info neon-btn-info w-100 rounded-3 px-4 py-2 fw-bold text-uppercase shadow">Registrarse ahora</button>
            </form>
            <?php if ($googleAuthEnabled): ?>
              <div class="d-grid gap-3">
                <div class="d-flex align-items-center gap-3 small text-neon" aria-hidden="true">
                  <span class="flex-grow-1" style="height:1px;background:rgba(var(--theme-primary-rgb),0.32);"></span>
                  <span>o</span>
                  <span class="flex-grow-1" style="height:1px;background:rgba(var(--theme-primary-rgb),0.32);"></span>
                </div>
                <a href="<?php echo htmlspecialchars($googleAuthLoginUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn w-100 rounded-3 px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center justify-content-center gap-2" style="background:#ffffff;color:#111827;border:1px solid rgba(255,255,255,0.78);">
                  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303C33.654 32.657 29.233 36 24 36c-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.27 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                    <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.27 4 24 4c-7.682 0-14.289 4.337-17.694 10.691z"/>
                    <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238C29.143 35.091 26.715 36 24 36c-5.212 0-9.62-3.329-11.283-7.946l-6.522 5.025C9.56 39.556 16.618 44 24 44z"/>
                    <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303c-.793 2.238-2.231 4.166-4.084 5.571.001-.001 6.19 5.238 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
                  </svg>
                  <span>Registrarme con Google</span>
                </a>
              </div>
            <?php endif; ?>
            <script src="<?php echo htmlspecialchars($registerScriptUrl . '?v=' . date('YmdHis'), ENT_QUOTES, 'UTF-8'); ?>" data-register-endpoint="<?php echo htmlspecialchars($registerEndpointUrl, ENT_QUOTES, 'UTF-8'); ?>" data-login-url="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
            <button type="button" data-auth-switch="login" class="btn btn-link w-100 small fw-bold text-neon">¿Ya tienes una cuenta? Inicia sesión</button>
          </div>
        </div>
      </div>

      <?php if ($authUser && $winPointsProgramEnabled): ?>
      <div id="user-rewards-modal" class="position-fixed top-0 start-0 w-100 h-100 d-none d-flex align-items-start align-items-md-center justify-content-center px-3 py-3 overflow-auto" style="z-index:13100;">
        <div class="position-absolute top-0 start-0 w-100 h-100" style="background:var(--theme-overlay-soft);backdrop-filter:blur(6px);" data-user-close></div>
        <div class="position-relative w-100" style="max-width:920px;z-index:1;">
          <div class="rounded-4 border border-info overflow-hidden" style="background:var(--theme-panel-gradient);box-shadow:0 0 32px var(--theme-primary-glow);">
            <div class="d-flex align-items-center justify-content-between gap-3 px-4 py-3 border-bottom border-info-subtle">
              <div>
                <div class="small text-uppercase text-info" style="letter-spacing:0.3em;">Mi cuenta</div>
                <h3 id="user-rewards-modal-title" class="h5 mb-0 text-white">Mis <?php echo htmlspecialchars((string) ($winPointsProgramConfig['name'] ?? 'Win Points'), ENT_QUOTES, 'UTF-8'); ?></h3>
              </div>
              <button type="button" class="btn btn-outline-info rounded-circle d-flex align-items-center justify-content-center" style="width:42px;height:42px;" data-user-close aria-label="Cerrar">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
            <div class="px-4 py-4" style="max-height:calc(100vh - 170px);overflow-y:auto;">
              <div id="user-rewards-feedback" class="d-none alert mb-3 py-2"></div>
              <div id="user-rewards-loading" class="text-center py-5 text-info">Cargando saldo y movimientos...</div>
              <div id="user-rewards-content" class="d-none">
                <div class="row g-3 mb-4">
                  <div class="col-sm-6 col-lg-3">
                    <div class="rounded-4 border border-info-subtle p-3 h-100" style="background:rgba(8,15,24,0.82);">
                      <div class="small text-uppercase text-secondary mb-1">Saldo</div>
                      <div id="user-rewards-balance-value" class="h4 fw-bold text-info mb-0"><?php echo number_format((int) ($winPointsUserSummary['balance'] ?? 0)); ?></div>
                      <div id="user-rewards-expiration-value" class="small text-secondary mt-2"><?php echo htmlspecialchars($winPointsModalExpirationText, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                  </div>
                  <div class="col-sm-6 col-lg-3">
                    <div class="rounded-4 border border-info-subtle p-3 h-100" style="background:rgba(8,15,24,0.82);">
                      <div class="small text-uppercase text-secondary mb-1">Ganados</div>
                      <div id="user-rewards-earned-value" class="h4 fw-bold text-success mb-0"><?php echo number_format((int) ($winPointsUserSummary['earned'] ?? 0)); ?></div>
                    </div>
                  </div>
                  <div class="col-sm-6 col-lg-3">
                    <div class="rounded-4 border border-info-subtle p-3 h-100" style="background:rgba(8,15,24,0.82);">
                      <div class="small text-uppercase text-secondary mb-1">Gastados</div>
                      <div id="user-rewards-spent-value" class="h4 fw-bold text-warning mb-0"><?php echo number_format((int) ($winPointsUserSummary['spent'] ?? 0)); ?></div>
                    </div>
                  </div>
                  <div class="col-sm-6 col-lg-3">
                    <div class="rounded-4 border border-info-subtle p-3 h-100" style="background:rgba(8,15,24,0.82);">
                      <div class="small text-uppercase text-secondary mb-1">Movimientos</div>
                      <div id="user-rewards-transactions-value" class="h4 fw-bold text-light mb-0"><?php echo number_format((int) ($winPointsUserSummary['transactions'] ?? 0)); ?></div>
                    </div>
                  </div>
                </div>

                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
                  <div>
                    <h4 class="h6 text-info fw-bold mb-1">Historial de movimientos</h4>
                    <p class="text-secondary small mb-0">Ganancias, canjes, reembolsos y ajustes relacionados con tus premios.</p>
                  </div>
                </div>

                <div id="user-rewards-empty" class="d-none text-center py-5 text-secondary">Todavia no tienes movimientos en este programa.</div>
                <div id="user-rewards-transactions-list" class="d-none">
                  <div class="table-responsive d-none d-md-block rounded-4 border border-info-subtle overflow-hidden mb-3" style="background:var(--theme-bg-elevated);">
                    <table class="table align-middle mb-0" style="--bs-table-bg:transparent;--bs-table-color:var(--theme-text);">
                      <thead>
                        <tr>
                          <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent">Fecha</th>
                          <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent">Tipo</th>
                          <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent">Detalle</th>
                          <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent text-end">Delta</th>
                          <th class="text-info text-uppercase small fw-bold border-bottom border-info-subtle bg-transparent text-end">Saldo</th>
                        </tr>
                      </thead>
                      <tbody id="user-rewards-table-body"></tbody>
                    </table>
                  </div>
                  <div id="user-rewards-cards" class="d-grid d-md-none gap-3"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($authUser): ?>
      <div id="user-orders-modal" class="position-fixed top-0 start-0 w-100 h-100 d-none d-flex align-items-start align-items-md-center justify-content-center px-3 py-3 overflow-auto" style="z-index:13100;">
        <div class="position-absolute top-0 start-0 w-100 h-100" style="background:var(--theme-overlay-soft);backdrop-filter:blur(6px);" data-user-close></div>
        <div class="position-relative w-100" style="max-width:820px;z-index:1;">
          <div class="rounded-4 border border-info overflow-hidden" style="background:var(--theme-panel-gradient);box-shadow:0 0 32px var(--theme-primary-glow);">
            <div class="d-flex align-items-center justify-content-between gap-3 px-4 py-3 border-bottom border-info-subtle">
              <div>
                <div class="small text-uppercase text-info" style="letter-spacing:0.3em;">Mi cuenta</div>
                <h3 class="h5 mb-0 text-white">Pedidos realizados</h3>
              </div>
              <button type="button" class="btn btn-outline-info rounded-circle d-flex align-items-center justify-content-center" style="width:42px;height:42px;" data-user-close aria-label="Cerrar">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
            <div class="px-4 py-4" style="max-height:calc(100vh - 170px);overflow-y:auto;">
              <div id="user-orders-feedback" class="d-none alert mb-3 py-2"></div>
              <div id="user-orders-loading" class="text-center py-5 text-info">Cargando pedidos...</div>
              <div id="user-orders-empty" class="d-none text-center py-5 text-secondary">Todavía no has realizado pedidos con esta cuenta.</div>
              <div id="user-orders-list" class="d-none">
                <div class="table-responsive d-none d-md-block rounded-4 border border-info-subtle overflow-hidden" style="background:var(--theme-bg-elevated);">
                  <table class="table align-middle mb-0" style="--bs-table-bg:transparent;--bs-table-color:var(--theme-text);">
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
                <div id="user-orders-cards" class="d-grid d-md-none gap-3"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div id="user-profile-modal" class="position-fixed top-0 start-0 w-100 h-100 d-none d-flex align-items-center justify-content-center px-3" style="z-index:13100;">
        <div class="position-absolute top-0 start-0 w-100 h-100" style="background:var(--theme-overlay-soft);backdrop-filter:blur(6px);" data-user-close></div>
        <div class="position-relative w-100" style="max-width:560px;z-index:1;">
          <div class="rounded-4 border border-info overflow-hidden" style="background:var(--theme-panel-gradient);box-shadow:0 0 32px var(--theme-primary-glow);">
            <div class="d-flex align-items-center justify-content-between gap-3 px-4 py-3 border-bottom border-info-subtle">
              <div>
                <div class="small text-uppercase text-info" style="letter-spacing:0.3em;">Mi cuenta</div>
                <h3 class="h5 mb-0 text-white">Datos de usuario</h3>
              </div>
              <button type="button" class="btn btn-outline-info rounded-circle d-flex align-items-center justify-content-center" style="width:42px;height:42px;" data-user-close aria-label="Cerrar">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
            <div class="px-4 py-4">
              <div id="user-profile-feedback" class="d-none alert mb-3 py-2"></div>
              <form id="user-profile-form" class="d-grid gap-3" novalidate data-profile-image-url="<?php echo htmlspecialchars($authUserProfileImageUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="d-flex flex-column align-items-center gap-3 pb-2">
                  <div class="d-inline-flex align-items-center justify-content-center rounded-circle overflow-hidden border border-info" style="width:88px;height:88px;background:rgba(var(--theme-bg-main-rgb),0.28);box-shadow:0 0 20px rgba(var(--theme-primary-rgb),0.14);">
                    <img id="user-profile-preview-image" src="<?php echo htmlspecialchars($authUserProfileImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Vista previa de foto de perfil" class="w-100 h-100 object-fit-cover<?php echo $authUserProfileImageUrl === '' ? ' d-none' : ''; ?>">
                    <span id="user-profile-preview-fallback" class="fw-bold text-info<?php echo $authUserProfileImageUrl !== '' ? ' d-none' : ''; ?>"><?php echo htmlspecialchars($authUserInitials, ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                  <div class="w-100">
                    <label class="form-label small text-info">Imagen de perfil</label>
                    <input type="file" id="user-profile-image" name="profile_image" class="form-control bg-dark text-info border-info" accept="image/png,image/jpeg,image/webp,image/gif">
                    <div class="small text-secondary mt-2">La vista previa se actualiza al instante. Formatos permitidos: JPG, PNG, WEBP o GIF.</div>
                  </div>
                </div>
                <div>
                  <label class="form-label small text-info">Nombre</label>
                  <input type="text" name="name" class="form-control bg-dark text-info border-info" value="<?php echo htmlspecialchars($authUserName, ENT_QUOTES, 'UTF-8'); ?>" required />
                </div>
                <div>
                  <label class="form-label small text-info">Correo</label>
                  <input type="email" name="email" class="form-control bg-dark text-info border-info" value="<?php echo htmlspecialchars($authUserEmail, ENT_QUOTES, 'UTF-8'); ?>" required />
                </div>
                <div>
                  <label class="form-label small text-info">Teléfono</label>
                  <input type="tel" name="phone" class="form-control bg-dark text-info border-info" value="<?php echo htmlspecialchars($authUserPhone, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="tel" placeholder="Ej. +58 412 0000000" />
                </div>
                <div>
                  <label class="form-label small text-info">Nueva contraseña</label>
                  <input type="password" name="password" class="form-control bg-dark text-info border-info" placeholder="Opcional" autocomplete="new-password" />
                </div>
                <div>
                  <label class="form-label small text-info">Confirmar contraseña</label>
                  <input type="password" name="password_confirm" class="form-control bg-dark text-info border-info" placeholder="Repite la contraseña nueva" autocomplete="new-password" />
                </div>
                <button type="submit" class="btn btn-admin w-100 rounded-3 py-2 fw-bold">Guardar cambios</button>
              </form>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
