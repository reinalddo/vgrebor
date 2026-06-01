<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  require_once __DIR__ . '/includes/tenant.php';
  tenant_start_session();
}

require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/store_config.php";
require_once __DIR__ . "/includes/currency.php";
require_once __DIR__ . "/includes/home_gallery.php";
require_once __DIR__ . "/includes/slugify.php";
currency_ensure_schema();
$pageTitle = store_config_get('nombre_tienda', 'TVirtualGaming') . " | " . store_config_get('nombre_tienda_subtitulo', 'Tienda de monedas digitales');
$startupPopupConfig = store_config_all();
$startupPopupNormalEnabled = trim((string) ($startupPopupConfig['inicio_popup_activo'] ?? '1')) === '1';
$startupPopupVideoEnabled = trim((string) ($startupPopupConfig['inicio_popup_video_activo'] ?? '0')) === '1';
$startupPopupGalleryEnabled = trim((string) ($startupPopupConfig['inicio_popup_galeria'] ?? '0')) === '1';
$startupPopupTabEnabled = trim((string) ($startupPopupConfig['inicio_popup_tab_habilitado'] ?? '1')) === '1'
  || $startupPopupNormalEnabled
  || $startupPopupVideoEnabled
  || $startupPopupGalleryEnabled;
$startupPopupNormalEnabled = $startupPopupTabEnabled && $startupPopupNormalEnabled;
$startupPopupVideoEnabled = $startupPopupTabEnabled && $startupPopupVideoEnabled;
$startupPopupGalleryEnabled = $startupPopupTabEnabled && $startupPopupGalleryEnabled;
$startupPopupFrequency = store_config_get('inicio_popup_frecuencia', 'per_session');
if (!in_array($startupPopupFrequency, ['always', 'per_entry', 'per_session'], true)) {
  $startupPopupFrequency = 'per_session';
}
$startupPopupChannelName = trim(store_config_get('inicio_popup_nombre_canal', 'DanisA Gamer Store'));
if ($startupPopupChannelName === '') {
  $startupPopupChannelName = 'DanisA Gamer Store';
}
$startupPopupChannelUrl = store_config_normalize_social_url(store_config_get('whatsapp_channel', ''));
$startupPopupChannelValid = store_config_is_valid_social_url($startupPopupChannelUrl);
$startupPopupVideoUrl = store_config_normalize_youtube_url(store_config_get('inicio_popup_video_url', ''));
$startupPopupVideoEmbedUrl = store_config_youtube_embed_url($startupPopupVideoUrl);
$startupPopupGalleryImages = store_config_startup_popup_gallery_images((string) store_config_get('inicio_popup_galeria_imagenes', '[]'));
$startupPopupMode = trim(store_config_get('inicio_popup_modo', ''));
if (!in_array($startupPopupMode, ['none', 'normal', 'video', 'gallery'], true)
  || ($startupPopupMode === 'normal' && !$startupPopupNormalEnabled)
  || ($startupPopupMode === 'video' && !$startupPopupVideoEnabled)
  || ($startupPopupMode === 'gallery' && !$startupPopupGalleryEnabled)) {
  if ($startupPopupVideoEnabled) {
    $startupPopupMode = 'video';
  } elseif ($startupPopupNormalEnabled) {
    $startupPopupMode = 'normal';
  } elseif ($startupPopupGalleryEnabled) {
    $startupPopupMode = 'gallery';
  } else {
    $startupPopupMode = 'none';
  }
}
$startupPopupShouldRender = false;
if ($startupPopupMode === 'video') {
  $startupPopupShouldRender = $startupPopupChannelValid && $startupPopupVideoEmbedUrl !== '';
} elseif ($startupPopupMode === 'normal') {
  $startupPopupShouldRender = $startupPopupChannelValid;
} elseif ($startupPopupMode === 'gallery') {
  $startupPopupShouldRender = $startupPopupChannelValid && count($startupPopupGalleryImages) > 0;
}
$startupPopupShouldOpen = false;
if ($startupPopupShouldRender) {
  if ($startupPopupFrequency === 'per_session') {
    $startupPopupShouldOpen = empty($_SESSION['startup_popup_seen']);
    $_SESSION['startup_popup_seen'] = 1;
  } else {
    $startupPopupShouldOpen = true;
  }
}
include __DIR__ . "/includes/header.php";
home_gallery_ensure_table();
$galleryItems = home_gallery_all();
$galleryFeatured = home_gallery_featured();

$banners = [];
// Helper to normalize legacy tenant upload paths (tenants/{slug}/uploads/...) -> /uploads/...
$normalize_public_image = static function ($path) {
  $p = trim((string) $path);
  if ($p === '') {
    return '';
  }
  if (preg_match('#^https?://#i', $p) === 1) {
    return $p;
  }
  // strip any query string or fragment
  $urlPath = parse_url($p, PHP_URL_PATH) ?: $p;
  $mapped = preg_replace('#/tenants/[^/]+/uploads/#', '/uploads/', $urlPath);
  $mapped = preg_replace('#^tenants/[^/]+/uploads/#', '/uploads/', $mapped);
  if ($mapped === '') {
    return '';
  }
  // ensure leading slash
  if ($mapped[0] !== '/') {
    $mapped = '/' . $mapped;
  }
  return $mapped;
};

foreach ($galleryItems as $item) {
  $imagePath = $normalize_public_image($item['imagen'] ?? '');
  $banners[] = [
    'label' => $item['titulo'],
    'title' => $item['descripcion1'],
    'subtitle' => $item['descripcion2'],
    'image' => $imagePath,
    'url' => $item['url'],
    'open_in_new_tab' => !empty($item['abrir_nueva_pestana']),
  ];
}

$featured = [];
if (!empty($galleryFeatured)) {
  $featured = [
    'label' => $galleryFeatured['titulo'],
    'title' => $galleryFeatured['descripcion1'],
    'subtitle' => $galleryFeatured['descripcion2'],
    'image' => ($normalize_public_image)($galleryFeatured['imagen'] ?? ''),
    'url' => $galleryFeatured['url'],
    'open_in_new_tab' => !empty($galleryFeatured['abrir_nueva_pestana']),
  ];
}

$gameCurrencyMap = [];
$resCurrencies = $mysqli->query("SELECT id, tasa, clave, mostrar_decimales FROM monedas");
if ($resCurrencies instanceof mysqli_result) {
  while ($currency = $resCurrencies->fetch_assoc()) {
    $gameCurrencyMap[(int) $currency['id']] = [
      'tasa' => (float) ($currency['tasa'] ?? 0),
      'clave' => (string) ($currency['clave'] ?? ''),
      'mostrar_decimales' => (int) ($currency['mostrar_decimales'] ?? 1),
    ];
  }
}

$gameCards = [];
$resGames = $mysqli->query(
  "SELECT j.*, COUNT(jp.id) AS paquetes_total, MIN(jp.precio) AS precio_minimo\n"
  . "FROM juegos j\n"
  . "INNER JOIN juego_paquetes jp ON jp.juego_id = j.id AND COALESCE(jp.activo, 1) = 1\n"
  . "WHERE COALESCE(j.activo, 1) = 1\n"
  . "GROUP BY j.id\n"
  . "ORDER BY CASE WHEN j.orden IS NULL THEN 1 ELSE 0 END, j.orden ASC, j.id ASC"
);
if ($resGames instanceof mysqli_result) {
  while ($game = $resGames->fetch_assoc()) {
    $currency = null;
    $minPriceLabel = null;
    $currencyId = (int) ($game['moneda_fija_id'] ?? 0);
    if ($currencyId > 0 && isset($gameCurrencyMap[$currencyId])) {
      $currency = $gameCurrencyMap[$currencyId];
      $convertedPrice = currency_convert_from_base((float) ($game['precio_minimo'] ?? 0), $currency);
      $minPriceLabel = strtoupper($currency['clave']) . ' ' . currency_format_amount($convertedPrice, $currency);
    }

    $game['paquetes_total'] = (int) ($game['paquetes_total'] ?? 0);
    $game['min_price_label'] = $minPriceLabel;
    $gameCards[] = $game;
  }
}

$popularGames = array_values(array_filter($gameCards, static fn ($game) => !empty($game['popular'])));
$moreGames = $gameCards;
$accentMap = [
  "cyan" => [
    "label" => "text-cyan-300/70",
    "gradient" => "from-slate-950/90 via-slate-950/30 to-transparent"
  ],
  "emerald" => [
    "label" => "text-emerald-300/70",
    "gradient" => "from-slate-950/85 via-transparent to-slate-950/80"
  ]
];

$dailyMissionsUserId = !empty($authUser['id']) ? (int) $authUser['id'] : 0;
$dailyMissionsPayload = daily_missions_public_payload($mysqli, $dailyMissionsUserId, 80);
$dailyMissionsSettings = is_array($dailyMissionsPayload['settings'] ?? null) ? $dailyMissionsPayload['settings'] : daily_missions_default_settings();
$dailyMissionsTasks = array_values(array_filter(
  is_array($dailyMissionsPayload['tasks'] ?? null) ? $dailyMissionsPayload['tasks'] : [],
  static fn (array $task): bool => !empty($task['active'])
));
$dailyMissionsDay = is_array($dailyMissionsPayload['day'] ?? null) ? $dailyMissionsPayload['day'] : [];
$dailyMissionsState = is_array($dailyMissionsPayload['state'] ?? null) ? $dailyMissionsPayload['state'] : [];
$dailyMissionsHistory = is_array($dailyMissionsPayload['history'] ?? null) ? $dailyMissionsPayload['history'] : [];
$dailyMissionsLevelKey = (string) ($dailyMissionsPayload['chest_level'] ?? 'basic');
$dailyMissionsPalette = daily_missions_level_palette($dailyMissionsLevelKey);
$dailyMissionsProgressPercent = max(0, min(100, (int) ($dailyMissionsPayload['progress_percent'] ?? 0)));
$dailyMissionsCanOpenChest = !empty($dailyMissionsPayload['can_open_chest']);
$dailyMissionsToday = (string) ($dailyMissionsPayload['today'] ?? date('Y-m-d'));
$dailyMissionsExploreTargets = [];
foreach ($gameCards as $gameCard) {
  $targetUrl = app_path(game_route_path($gameCard));
  if ($targetUrl !== '') {
    $dailyMissionsExploreTargets[] = $targetUrl;
  }
}
if (empty($dailyMissionsExploreTargets)) {
  $dailyMissionsExploreTargets[] = app_path('/juegos');
}
shuffle($dailyMissionsExploreTargets);
$dailyMissionsSocialTargets = [];
foreach ([
  'facebook' => ['label' => 'Facebook', 'url' => store_config_normalize_social_url(store_config_get('facebook', ''))],
  'instagram' => ['label' => 'Instagram', 'url' => store_config_normalize_social_url(store_config_get('instagram', ''))],
  'tiktok' => ['label' => 'TikTok', 'url' => store_config_normalize_social_url(store_config_get('tiktok', ''))],
] as $socialKey => $socialTarget) {
  if (store_config_is_valid_social_url((string) ($socialTarget['url'] ?? ''))) {
    $dailyMissionsSocialTargets[$socialKey] = $socialTarget;
  }
}
$dailyMissionsScriptPayload = [
  'enabled' => !empty($dailyMissionsPayload['enabled']),
  'settings' => $dailyMissionsSettings,
  'day' => $dailyMissionsDay,
  'state' => $dailyMissionsState,
  'history' => $dailyMissionsHistory,
  'tasks' => $dailyMissionsTasks,
  'level_key' => $dailyMissionsLevelKey,
  'palette' => $dailyMissionsPalette,
  'progress_percent' => $dailyMissionsProgressPercent,
  'can_open_chest' => $dailyMissionsCanOpenChest,
  'today' => $dailyMissionsToday,
  'explore_targets' => $dailyMissionsExploreTargets,
  'social_targets' => $dailyMissionsSocialTargets,
  'api_url' => app_path('/api/daily_missions.php'),
];
?>

      <style>
        .startup-popup-shell {
          position: fixed;
          inset: 0;
          z-index: 1080;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 1rem;
          background: radial-gradient(circle at top, rgba(var(--theme-startup-popup-accent-rgb), 0.16), rgba(0, 0, 0, 0) 34%), rgba(2, 6, 12, 0.74);
          backdrop-filter: blur(12px);
          pointer-events: auto;
        }
        .startup-popup-shell.is-hidden {
          display: none;
        }
        .startup-popup-card {
          position: relative;
          width: min(100%, 292px);
          padding: 0.82rem 0.82rem 0.92rem;
          border-radius: 22px;
          border: 1px solid rgba(var(--theme-startup-popup-border-rgb), 0.95);
          background:
            radial-gradient(circle at top, rgba(var(--theme-startup-popup-accent-rgb), 0.12), transparent 30%),
            linear-gradient(180deg, rgba(var(--theme-startup-popup-surface-rgb), 0.98), rgba(12, 10, 10, 0.98));
          box-shadow: 0 18px 62px rgba(0, 0, 0, 0.58), 0 0 36px rgba(var(--theme-startup-popup-accent-rgb), 0.16), inset 0 0 0 1px rgba(255, 255, 255, 0.03);
          overflow: hidden;
          pointer-events: auto;
        }
        .startup-popup-card::before {
          content: "";
          position: absolute;
          inset: auto -10% -12% -10%;
          height: 110px;
          background: radial-gradient(circle, rgba(var(--theme-startup-popup-accent-rgb), 0.18), transparent 70%);
          pointer-events: none;
        }
        .startup-popup-close {
          position: absolute;
          top: 10px;
          right: 10px;
          width: 28px;
          height: 28px;
          border: 1px solid rgba(255, 255, 255, 0.08);
          border-radius: 999px;
          background: rgba(255, 255, 255, 0.05);
          color: rgba(255, 255, 255, 0.58);
          display: inline-flex;
          align-items: center;
          justify-content: center;
          transition: background-color 0.2s ease, color 0.2s ease, transform 0.2s ease;
        }
        .startup-popup-close:hover {
          background: rgba(255, 255, 255, 0.1);
          color: rgba(255, 255, 255, 0.88);
          transform: scale(1.03);
        }
        .store-game-card {
          transition: transform 0.35s ease;
        }
        .store-game-card:hover {
          transform: translateY(-6px);
        }
        .startup-popup-logo {
          width: 58px;
          height: 58px;
          margin: 0 auto;
          border-radius: 999px;
          display: flex;
          align-items: center;
          justify-content: center;
          color: var(--theme-startup-popup-button-text);
          background: linear-gradient(180deg, rgba(var(--theme-startup-popup-accent-rgb), 0.92), rgba(var(--theme-startup-popup-accent-rgb), 0.82));
          box-shadow: 0 0 0 6px rgba(var(--theme-startup-popup-accent-rgb), 0.08), 0 0 22px rgba(var(--theme-startup-popup-accent-rgb), 0.34);
        }
        .startup-popup-badge {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          margin: 0.78rem auto 0;
          padding: 0.22rem 0.68rem;
          border-radius: 999px;
          border: 1px solid rgba(var(--theme-startup-popup-accent-rgb), 0.24);
          background: linear-gradient(180deg, rgba(var(--theme-startup-popup-chip-rgb), 0.96), rgba(var(--theme-startup-popup-chip-rgb), 0.78));
          color: rgba(var(--theme-startup-popup-accent-rgb), 0.94);
          font-size: 0.54rem;
          font-weight: 800;
          letter-spacing: 0.18em;
          text-transform: uppercase;
        }
        .startup-popup-title {
          margin: 0.78rem 0 0;
          color: #f7f7f7;
          font-family: 'Oxanium', 'Space Grotesk', sans-serif;
          font-size: 1.55rem;
          line-height: 1.06;
          text-align: center;
          font-weight: 700;
        }
        .startup-popup-title strong {
          display: block;
          color: rgba(var(--theme-startup-popup-accent-rgb), 0.98);
        }
        .startup-popup-subtitle {
          margin: 0.7rem auto 0;
          max-width: 220px;
          color: rgba(248, 250, 252, 0.62);
          text-align: center;
          font-size: 0.76rem;
          line-height: 1.4;
        }
        .startup-popup-list {
          display: grid;
          gap: 0.55rem;
          margin: 0.92rem 0 0;
          padding: 0;
          list-style: none;
        }
        .startup-popup-list-item {
          display: flex;
          align-items: center;
          gap: 0.58rem;
          min-height: 40px;
          padding: 0.62rem 0.72rem;
          border-radius: 11px;
          border: 1px solid rgba(var(--theme-startup-popup-border-rgb), 0.72);
          background: linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.02));
          color: rgba(248, 250, 252, 0.88);
          box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.015);
        }
        .startup-popup-list-icon {
          font-size: 0.92rem;
          line-height: 1;
          width: 18px;
          text-align: center;
          flex: 0 0 18px;
        }
        .startup-popup-list-text {
          font-size: 0.76rem;
          line-height: 1.22;
          color: rgba(248, 250, 252, 0.82);
        }
        .startup-popup-link {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 0.54rem;
          width: 100%;
          margin-top: 1rem;
          padding: 0.74rem 0.82rem;
          border-radius: 13px;
          border: 0;
          background: linear-gradient(180deg, rgba(var(--theme-startup-popup-accent-rgb), 1), rgba(var(--theme-startup-popup-accent-rgb), 0.88));
          color: var(--theme-startup-popup-button-text);
          font-size: 0.82rem;
          font-weight: 800;
          letter-spacing: 0.08em;
          text-transform: uppercase;
          text-decoration: none;
          box-shadow: 0 12px 24px rgba(var(--theme-startup-popup-accent-rgb), 0.22), 0 0 14px rgba(var(--theme-startup-popup-accent-rgb), 0.22);
          transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .startup-popup-link:hover {
          color: var(--theme-startup-popup-button-text);
          transform: translateY(-1px);
        }
        .startup-popup-dismiss {
          display: block;
          margin-top: 0.68rem;
          border: 0;
          background: transparent;
          width: 100%;
          color: rgba(248, 250, 252, 0.38);
          font-size: 0.76rem;
        }
        .startup-popup-card-video {
          width: 100%;
          max-width: 356px;
          height: min(calc(100vh - 2rem), 860px);
          height: min(calc(100dvh - 2rem), 860px);
          padding: 0.78rem 0.78rem 0.8rem;
          border-radius: 22px;
          border: 1px solid rgba(var(--theme-startup-video-popup-border-rgb), 0.95);
          background:
            radial-gradient(circle at top, rgba(var(--theme-startup-video-popup-accent-rgb), 0.12), transparent 28%),
            linear-gradient(180deg, rgba(var(--theme-startup-video-popup-surface-rgb), 0.99), rgba(10, 14, 20, 0.99));
          box-shadow: 0 18px 62px rgba(0, 0, 0, 0.58), 0 0 36px rgba(var(--theme-startup-video-popup-border-rgb), 0.18), inset 0 0 0 1px rgba(255, 255, 255, 0.03);
          display: flex;
          flex-direction: column;
          overflow: hidden;
        }
        .startup-popup-card-video .startup-popup-close {
          border-color: rgba(var(--theme-startup-video-popup-accent-rgb), 0.3);
          color: rgba(var(--theme-startup-video-popup-accent-rgb), 0.92);
          background: rgba(var(--theme-startup-video-popup-accent-rgb), 0.08);
        }
        .startup-popup-video-title {
          margin: 0;
          padding-right: 2rem;
          color: #f8fafc;
          font-family: 'Oxanium', 'Space Grotesk', sans-serif;
          font-size: 1.28rem;
          line-height: 1.12;
          text-align: center;
          font-weight: 700;
          flex: 0 0 auto;
        }
        .startup-popup-video-subtitle {
          margin: 0.45rem auto 0;
          max-width: 250px;
          color: rgba(226, 232, 240, 0.76);
          text-align: center;
          font-size: 0.72rem;
          line-height: 1.32;
          flex: 0 0 auto;
        }
        .startup-popup-video-frame {
          position: relative;
          width: 100%;
          margin-top: 0.72rem;
          flex: 1 1 auto;
          min-height: 0;
          overflow: hidden;
          border-radius: 16px;
          border: 1px solid rgba(var(--theme-startup-video-popup-border-rgb), 0.86);
          background: #05070b;
          box-shadow: 0 0 22px rgba(var(--theme-startup-video-popup-border-rgb), 0.2);
        }
        .startup-popup-video-frame iframe {
          width: 100%;
          height: 100%;
          border: 0;
          display: block;
        }
        .startup-popup-video-link {
          margin-top: 0.72rem;
          background: linear-gradient(180deg, rgba(var(--theme-startup-video-popup-button-bg-rgb), 1), rgba(var(--theme-startup-video-popup-button-bg-rgb), 0.9));
          color: var(--theme-startup-video-popup-button-text);
          box-shadow: 0 12px 24px rgba(var(--theme-startup-video-popup-button-bg-rgb), 0.24), 0 0 14px rgba(var(--theme-startup-video-popup-button-bg-rgb), 0.18);
          flex: 0 0 auto;
        }
        .startup-popup-video-link svg {
          width: 21px;
          height: 21px;
          flex: 0 0 21px;
        }
        .startup-popup-video-link:hover {
          color: var(--theme-startup-video-popup-button-text);
        }
        .startup-popup-video-dismiss {
          margin-top: 0.52rem;
          background: linear-gradient(180deg, rgba(239, 68, 68, 0.98), rgba(185, 28, 28, 0.94));
          color: #fff5f5;
          box-shadow: 0 12px 24px rgba(185, 28, 28, 0.28), 0 0 14px rgba(239, 68, 68, 0.18);
          flex: 0 0 auto;
        }
        .startup-popup-video-dismiss:hover {
          color: #fff5f5;
        }
        .startup-popup-gallery-stage {
          display: flex;
          flex-direction: column;
          align-items: center;
          width: min(100%, 390px);
          pointer-events: auto;
        }
        .startup-popup-card-gallery {
          width: 100%;
          height: min(calc(100vh - 9.5rem), 540px);
          height: min(calc(100dvh - 9.5rem), 540px);
          padding: 0;
          border-radius: 28px;
          overflow: hidden;
          background: rgba(8, 12, 18, 0.98);
          box-shadow: 0 22px 60px rgba(0, 0, 0, 0.58), 0 0 30px rgba(var(--theme-startup-popup-accent-rgb), 0.18);
        }
        .startup-popup-gallery-viewport {
          position: relative;
          width: 100%;
          height: 100%;
          overflow: hidden;
          background: rgba(8, 12, 18, 0.98);
        }
        .startup-popup-gallery-slide {
          position: absolute;
          inset: 0;
          opacity: 0;
          transition: opacity 0.35s ease;
          pointer-events: none;
          display: block;
          color: inherit;
          text-decoration: none;
        }
        .startup-popup-gallery-slide.is-active {
          opacity: 1;
          pointer-events: auto;
        }
        .startup-popup-gallery-slide img {
          width: 100%;
          height: 100%;
          display: block;
          object-fit: cover;
        }
        .startup-popup-gallery-dots {
          position: absolute;
          left: 50%;
          bottom: 1rem;
          transform: translateX(-50%);
          display: inline-flex;
          align-items: center;
          gap: 0.45rem;
          padding: 0.38rem 0.6rem;
          border-radius: 999px;
          background: rgba(5, 10, 16, 0.54);
          backdrop-filter: blur(10px);
          z-index: 2;
        }
        .startup-popup-gallery-dot {
          width: 10px;
          height: 10px;
          border: 0;
          border-radius: 999px;
          background: rgba(255, 255, 255, 0.58);
          padding: 0;
          transition: transform 0.2s ease, background-color 0.2s ease;
        }
        .startup-popup-gallery-dot.is-active {
          transform: scale(1.18);
          background: rgba(var(--theme-startup-popup-accent-rgb), 1);
        }
        .startup-popup-gallery-actions {
          width: 100%;
          display: flex;
          flex-direction: column;
          align-items: center;
          gap: 0.72rem;
          margin-top: 0.9rem;
          pointer-events: auto;
        }
        .startup-popup-gallery-link {
          width: min(100%, 320px);
          pointer-events: auto;
        }
        .startup-popup-gallery-close {
          position: relative;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          min-width: 72px;
          min-height: 58px;
          padding: 0.95rem 1.5rem;
          border: 0;
          border-radius: 999px;
          background: linear-gradient(180deg, rgba(239, 68, 68, 0.98), rgba(185, 28, 28, 0.94));
          color: #fff5f5;
          font-size: 1.3rem;
          font-weight: 800;
          line-height: 1;
          box-shadow: 0 14px 24px rgba(185, 28, 28, 0.28), 0 0 14px rgba(239, 68, 68, 0.18);
          transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
          pointer-events: auto;
        }
        .startup-popup-gallery-close:hover {
          color: #fff5f5;
          transform: translateY(-1px);
        }
        .startup-popup-gallery-close:disabled,
        .startup-popup-gallery-close.is-locked {
          cursor: not-allowed;
          opacity: 0.9;
          transform: none;
        }
        .startup-popup-gallery-close::before {
          content: "";
          position: absolute;
          inset: -4px;
          border-radius: inherit;
          border: 3px solid rgba(255, 255, 255, 0.18);
          border-top-color: rgba(var(--theme-startup-popup-accent-rgb), 1);
          opacity: 0;
          transition: opacity 0.2s ease;
        }
        .startup-popup-gallery-close.is-locked::before {
          opacity: 1;
          animation: startup-popup-gallery-close-spin 0.95s linear infinite;
        }
        .startup-popup-gallery-close-label {
          position: relative;
          z-index: 1;
        }
        @keyframes startup-popup-gallery-close-spin {
          to {
            transform: rotate(360deg);
          }
        }
        @media (max-width: 420px) {
          .startup-popup-shell {
            padding: 0.32rem;
          }
          .startup-popup-card {
            border-radius: 20px;
            padding: 0.78rem 0.78rem 0.92rem;
          }
          .startup-popup-title {
            font-size: 1.42rem;
          }
          .startup-popup-card-video {
            max-width: none;
            height: calc(100vh - 0.64rem);
            height: calc(100dvh - 0.64rem);
            padding: 0.68rem 0.68rem 0.72rem;
            border-radius: 20px;
          }
          .startup-popup-video-title {
            font-size: 1.14rem;
          }
          .startup-popup-video-subtitle {
            margin-top: 0.38rem;
            font-size: 0.68rem;
            line-height: 1.26;
          }
          .startup-popup-video-frame {
            margin-top: 0.58rem;
            border-radius: 14px;
          }
          .startup-popup-video-link,
          .startup-popup-video-dismiss {
            margin-top: 0.5rem;
            padding: 0.68rem 0.74rem;
            font-size: 0.76rem;
          }
          .startup-popup-gallery-stage {
            width: 100%;
          }
          .startup-popup-card-gallery {
            height: min(calc(100vh - 8.8rem), 500px);
            height: min(calc(100dvh - 8.8rem), 500px);
            border-radius: 24px;
          }
          .startup-popup-gallery-actions {
            margin-top: 0.78rem;
            gap: 0.6rem;
          }
          .startup-popup-gallery-link {
            width: 100%;
          }
          .startup-popup-gallery-close {
            min-width: 68px;
            min-height: 54px;
            padding: 0.88rem 1.3rem;
          }
        }
        .promo-section-mobile,
        .featured-section-mobile {
          position: relative;
        }
        .promo-slider-shell {
          position: relative;
          /* fixed responsive height so side and center share the same baseline */
          height: clamp(240px, 30vw, 460px);
          overflow: visible;
        }
        .promo-slider-track {
          position: relative;
          width: 100%;
          height: 100%;
          overflow: visible;
          touch-action: pan-y pinch-zoom;
          user-select: none;
          -webkit-user-select: none;
          -ms-user-select: none;
        }
        .promo-slide-card {
          position: absolute;
          top: 0;
          left: 15%;
          width: 70%;
          height: 100%;
          overflow: hidden;
          border-radius: 0;
          display: block;
          background: rgba(8, 15, 24, 0.88);
          opacity: 0;
          transition: left 360ms ease, right 360ms ease, width 360ms ease, height 360ms ease, top 360ms ease, opacity 220ms ease;
          cursor: pointer;
          pointer-events: none;
          z-index: 1;
        }

        /* center slide occupies 70% width and visually stands out */
        .promo-slide-card.is-center {
          left: 15%;
          top: -2.5%;
          width: 70%;
          height: 105%;
          border-radius: 1.5rem;
          opacity: 1;
          pointer-events: auto;
          z-index: 3;
        }
        .promo-slide-card.is-prev {
          left: 0;
          width: 15%;
          border-top-left-radius: 1.5rem;
          border-bottom-left-radius: 1.5rem;
          opacity: 0.5;
          pointer-events: auto;
          z-index: 2;
        }
        .promo-slide-card.is-next {
          left: auto;
          right: 0;
          width: 15%;
          border-top-right-radius: 1.5rem;
          border-bottom-right-radius: 1.5rem;
          opacity: 0.5;
          pointer-events: auto;
          z-index: 2;
        }
        .promo-slide-card.is-hidden {
          opacity: 0;
          pointer-events: none;
          z-index: 0;
        }
        .promo-slide-image,
        .featured-banner-image {
          width: 100%;
          height: 100%;
          object-fit: cover;
          object-position: center top;
          display: block;
        }
        .promo-slide-overlay,
        .featured-banner-overlay {
          position: absolute;
          inset: 0;
          background: transparent;
          pointer-events: none;
        }
        .promo-slide-content,
        .featured-banner-content {
          position: absolute;
          inset: 0;
          display: flex;
          flex-direction: column;
          justify-content: center;
          padding-inline: 1.5rem;
          transition: opacity 220ms ease;
        }
        .promo-slide-card:not(.is-center) .promo-slide-content {
          opacity: 0;
        }
        .promo-slide-content > p,
        .promo-slide-content > h2,
        .featured-banner-content > p,
        .featured-banner-content > h3 {
          text-shadow: 0 2px 10px rgba(3, 7, 18, 0.82), 0 0 18px rgba(3, 7, 18, 0.45);
        }
        .promo-slide-content .small.text-secondary,
        .featured-banner-content .small.text-secondary {
          color: #e2e8f0 !important;
          text-shadow: 0 2px 8px rgba(3, 7, 18, 0.9), 0 0 14px rgba(3, 7, 18, 0.4);
        }
        .promo-dots {
          display: flex;
          align-items: center;
          gap: 0.5rem;
        }
        .promo-dot {
          appearance: none;
          border: 0;
          padding: 0;
          width: 16px;
          height: 6px;
          border-radius: 999px;
          background: #334155;
          transition: width 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
        }
        .promo-dot.is-active {
          width: 24px;
          background: #22d3ee;
          box-shadow: 0 0 14px rgba(34, 211, 238, 0.3);
        }
        .featured-banner-card {
          position: relative;
          display: block;
          overflow: hidden;
          border-radius: 1.5rem;
          text-decoration: none;
          background: rgba(8, 15, 24, 0.88);
        }
        .featured-banner-image {
          aspect-ratio: 1280 / 500;
        }
        @media (min-width: 768px) {
          .promo-slide-card,
          .featured-banner-image {
            aspect-ratio: 1280 / 500;
          }
        }
        @media (max-width: 767.98px) {
          .promo-section-mobile,
          .featured-section-mobile {
            width: calc(100% + var(--bs-gutter-x, 1.5rem));
            margin-left: calc(var(--bs-gutter-x, 1.5rem) * -0.5);
            margin-right: calc(var(--bs-gutter-x, 1.5rem) * -0.5);
          }
          .promo-slider-shell {
            height: min(66vw, 420px);
          }
          .promo-slide-card {
            left: 0;
            width: 100% !important;
            height: 100% !important;
            border-radius: 1.2rem;
          }
          .promo-slide-card.is-center {
            left: 0;
            top: 0;
            width: 100% !important;
            height: 100% !important;
            border-radius: 1.2rem;
            transform: none;
          }
          .promo-slide-card.is-prev {
            left: -100%;
            right: auto;
            border-radius: 1.2rem;
            opacity: 0;
          }
          .promo-slide-card.is-next {
            left: 100%;
            right: auto;
            border-radius: 1.2rem;
            opacity: 0;
          }
          .promo-slide-card.is-hidden {
            opacity: 0;
          }
          .promo-slide-content,
          .featured-banner-content {
            padding-inline: 1rem;
          }
        }
      </style>

      <style>
        .daily-mission-shell {
          position: relative;
          z-index: 1;
        }
        .daily-mission-panel {
          overflow: hidden;
          border-radius: 1.5rem;
          border: 1px solid rgba(34, 211, 238, 0.18);
          background:
            radial-gradient(circle at top, rgba(34, 211, 238, 0.1), transparent 42%),
            linear-gradient(180deg, rgba(7, 12, 20, 0.98), rgba(4, 8, 14, 0.99));
          box-shadow: 0 18px 40px rgba(0, 0, 0, 0.28), 0 0 24px rgba(34, 211, 238, 0.06);
        }
        .daily-mission-toggle {
          gap: 1rem;
          align-items: flex-start;
          padding: 1rem 1.1rem;
          border: 0;
          background: linear-gradient(180deg, rgba(8, 15, 24, 0.96), rgba(4, 9, 16, 0.98));
          color: #fff;
          box-shadow: inset 0 0 0 1px rgba(34, 211, 238, 0.12);
        }
        .daily-mission-toggle::after {
          margin-top: 0.25rem;
          filter: invert(1) brightness(1.5);
          opacity: 0.8;
          flex-shrink: 0;
        }
        .daily-mission-header-copy {
          flex: 1 1 auto;
          min-width: 0;
          display: grid;
          gap: 0.25rem;
        }
        .daily-mission-header-eyebrow {
          color: var(--daily-accent, #22d3ee);
          font-size: 0.78rem;
          font-weight: 800;
          letter-spacing: 0.22em;
          text-transform: uppercase;
        }
        .daily-mission-header-title {
          font-family: 'Oxanium', sans-serif;
          font-size: clamp(1.12rem, 2.1vw, 1.8rem);
          line-height: 1.08;
          color: #fff;
        }
        .daily-mission-header-subtitle {
          color: #cbd5e1;
          font-size: 0.94rem;
        }
        .daily-mission-pill {
          flex: 0 0 auto;
          min-width: 126px;
          padding: 0.72rem 0.9rem;
          border-radius: 1rem;
          border: 1px solid rgba(34, 211, 238, 0.18);
          background: rgba(12, 18, 28, 0.9);
          color: #d9fbff;
          display: grid;
          gap: 0.12rem;
        }
        .daily-mission-pill small {
          font-size: 0.68rem;
          letter-spacing: 0.18em;
          text-transform: uppercase;
          color: #94a3b8;
        }
        .daily-mission-pill strong {
          font-size: 0.92rem;
        }
        .daily-mission-body {
          padding: 1.1rem;
          display: grid;
          gap: 1rem;
        }
        .daily-mission-topline {
          display: flex;
          align-items: flex-start;
          justify-content: space-between;
          gap: 1rem;
          flex-wrap: wrap;
        }
        .daily-mission-summary-grid {
          display: grid;
          grid-template-columns: repeat(4, minmax(0, 1fr));
          gap: 0.75rem;
        }
        @media (max-width: 991.98px) {
          .daily-mission-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
          }
        }
        .daily-mission-summary-card {
          border-radius: 1rem;
          padding: 0.85rem 0.95rem;
          border: 1px solid rgba(148, 163, 184, 0.15);
          background: rgba(9, 14, 22, 0.9);
        }
        .daily-mission-summary-card.is-accent {
          border-color: rgba(34, 211, 238, 0.28);
          box-shadow: 0 0 18px rgba(34, 211, 238, 0.08);
        }
        .daily-mission-summary-card small {
          display: block;
          margin-bottom: 0.28rem;
          color: #94a3b8;
          font-size: 0.7rem;
          letter-spacing: 0.18em;
          text-transform: uppercase;
        }
        .daily-mission-summary-card strong {
          display: block;
          color: #fff;
          font-family: 'Oxanium', sans-serif;
          font-size: 1.02rem;
        }
        .daily-mission-chest-stage {
          display: grid;
          grid-template-columns: minmax(220px, 320px) 1fr;
          gap: 1rem;
          align-items: center;
          padding: 1rem;
          border-radius: 1.25rem;
          border: 1px solid rgba(34, 211, 238, 0.12);
          background: linear-gradient(180deg, rgba(3, 8, 15, 0.88), rgba(6, 10, 18, 0.98));
        }
        @media (max-width: 991.98px) {
          .daily-mission-chest-stage {
            grid-template-columns: 1fr;
          }
        }
        .daily-mission-chest-card {
          display: grid;
          justify-items: center;
          gap: 0.75rem;
          text-align: center;
        }
        .daily-mission-chest-shell,
        .daily-mission-chest-3d {
          position: relative;
          width: min(100%, 280px);
          aspect-ratio: 1 / 1;
          border: 0;
          padding: 0;
          background: transparent;
          display: block;
        }
        .daily-mission-chest-shell {
          cursor: pointer;
          transition: transform 0.2s ease, filter 0.2s ease;
        }
        .daily-mission-chest-shell:not(:disabled):hover {
          transform: translateY(-2px);
        }
        .daily-mission-chest-shell:disabled {
          cursor: not-allowed;
        }
        .daily-mission-chest-shell:not(.is-open),
        .daily-mission-chest-3d:not(.is-open) {
          filter: grayscale(1) saturate(0.2) brightness(0.78);
        }
        .daily-mission-chest-shell.is-open,
        .daily-mission-chest-3d.is-open {
          animation: daily-mission-chest-pulse 2.4s ease-in-out infinite;
          filter: none;
        }
        .daily-mission-modal-sparks,
        .daily-mission-modal-lid,
        .daily-mission-modal-band,
        .daily-mission-modal-base,
        .daily-mission-modal-lock {
          position: absolute;
          inset: 0;
        }
        .daily-mission-modal-sparks {
          inset: -6%;
          border-radius: inherit;
          background:
            radial-gradient(circle at 50% 18%, rgba(255, 255, 255, 0.6), transparent 26%),
            radial-gradient(circle at 18% 26%, rgba(34, 211, 238, 0.48), transparent 18%),
            radial-gradient(circle at 82% 24%, rgba(250, 204, 21, 0.45), transparent 18%);
          opacity: 0;
          transform: scale(0.92);
          transition: opacity 0.2s ease, transform 0.2s ease;
          pointer-events: none;
        }
        .daily-mission-chest-shell.is-open .daily-mission-modal-sparks,
        .daily-mission-chest-3d.is-open .daily-mission-modal-sparks {
          opacity: 1;
          transform: scale(1);
          animation: daily-mission-sparks 1.8s linear infinite;
        }
        .daily-mission-modal-lid {
          left: 12%;
          right: 12%;
          top: 14%;
          height: 30%;
          border-radius: 1rem 1rem 0.6rem 0.6rem;
          background: linear-gradient(180deg, #475569, #1e293b);
          border: 1px solid rgba(255, 255, 255, 0.08);
          box-shadow: inset 0 -8px 18px rgba(0, 0, 0, 0.22);
          transform-origin: bottom center;
          transition: transform 0.32s ease, filter 0.32s ease;
          z-index: 2;
        }
        .daily-mission-chest-shell.is-open .daily-mission-modal-lid,
        .daily-mission-chest-3d.is-open .daily-mission-modal-lid {
          transform: translateY(-12%) rotate(-7deg);
        }
        .daily-mission-modal-base {
          left: 12%;
          right: 12%;
          bottom: 12%;
          height: 42%;
          border-radius: 1rem;
          background: linear-gradient(180deg, #0f172a, #1f2937);
          border: 1px solid rgba(255, 255, 255, 0.08);
          box-shadow: inset 0 -10px 20px rgba(0, 0, 0, 0.35);
          z-index: 1;
        }
        .daily-mission-modal-band.is-top {
          left: 12%;
          right: 12%;
          top: 40%;
          height: 9%;
          border-radius: 999px;
          background: linear-gradient(90deg, rgba(148, 163, 184, 0.65), rgba(226, 232, 240, 0.88), rgba(148, 163, 184, 0.65));
          z-index: 3;
          box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.08);
        }
        .daily-mission-chest-shell.is-open .daily-mission-modal-band.is-top,
        .daily-mission-chest-3d.is-open .daily-mission-modal-band.is-top {
          background: linear-gradient(90deg, var(--daily-accent, #22d3ee), rgba(255, 255, 255, 0.95), var(--daily-accent, #22d3ee));
          box-shadow: 0 0 18px var(--daily-glow, rgba(34, 211, 238, 0.28));
        }
        .daily-mission-modal-band.is-bottom {
          left: 16%;
          right: 16%;
          bottom: 22%;
          height: 8%;
          border-radius: 999px;
          background: rgba(15, 23, 42, 0.85);
          z-index: 3;
        }
        .daily-mission-modal-lock {
          left: 50%;
          top: 51%;
          width: 18%;
          aspect-ratio: 1;
          transform: translate(-50%, -5%);
          border-radius: 50%;
          background: radial-gradient(circle at 35% 30%, #f8fafc, #94a3b8 70%, #475569 100%);
          box-shadow: inset 0 -5px 10px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.08);
          z-index: 4;
        }
        .daily-mission-chest-shell.is-open .daily-mission-modal-lock,
        .daily-mission-chest-3d.is-open .daily-mission-modal-lock {
          background: radial-gradient(circle at 35% 30%, #fff9c4, #facc15 70%, #b45309 100%);
          box-shadow: 0 0 18px rgba(250, 204, 21, 0.3), inset 0 -5px 10px rgba(0, 0, 0, 0.25);
        }
        .daily-mission-progress {
          height: 0.9rem;
          border-radius: 999px;
          background: rgba(15, 23, 42, 0.9);
          border: 1px solid rgba(34, 211, 238, 0.12);
          overflow: hidden;
        }
        .daily-mission-progress .progress-bar {
          background: linear-gradient(90deg, var(--daily-accent, #22d3ee), rgba(255, 255, 255, 0.95), var(--daily-accent, #22d3ee));
          box-shadow: 0 0 18px var(--daily-glow, rgba(34, 211, 238, 0.28));
        }
        .daily-mission-task-grid {
          display: grid;
          grid-template-columns: repeat(2, minmax(0, 1fr));
          gap: 0.9rem;
        }
        @media (max-width: 991.98px) {
          .daily-mission-task-grid {
            grid-template-columns: 1fr;
          }
        }
        .daily-mission-task-card {
          border-radius: 1.1rem;
          padding: 1rem;
          border: 1px solid rgba(34, 211, 238, 0.16);
          background: linear-gradient(180deg, rgba(9, 14, 22, 0.92), rgba(4, 8, 14, 0.98));
          box-shadow: 0 12px 26px rgba(0, 0, 0, 0.22);
          display: grid;
          gap: 0.75rem;
        }
        .daily-mission-task-card.is-done {
          border-color: rgba(34, 197, 94, 0.28);
          box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.08), 0 10px 26px rgba(0, 0, 0, 0.2);
        }
        .daily-mission-task-card.is-disabled {
          opacity: 0.74;
        }
        .daily-mission-task-kicker {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 0.75rem;
        }
        .daily-mission-task-number {
          width: 2.2rem;
          height: 2.2rem;
          border-radius: 999px;
          display: grid;
          place-items: center;
          background: rgba(34, 211, 238, 0.12);
          color: var(--daily-accent, #22d3ee);
          font-family: 'Oxanium', sans-serif;
          font-weight: 700;
        }
        .daily-mission-task-state {
          padding: 0.34rem 0.7rem;
          border-radius: 999px;
          background: rgba(15, 23, 42, 0.92);
          color: #cbd5e1;
          font-size: 0.72rem;
          letter-spacing: 0.16em;
          text-transform: uppercase;
        }
        .daily-mission-task-state.is-done {
          background: rgba(22, 163, 74, 0.16);
          color: #86efac;
        }
        .daily-mission-task-title {
          margin: 0;
          color: #fff;
          font-size: 1.02rem;
          font-weight: 700;
        }
        .daily-mission-task-description {
          color: #cbd5e1;
          font-size: 0.92rem;
          line-height: 1.4;
        }
        .daily-mission-task-actions {
          display: flex;
          flex-wrap: wrap;
          gap: 0.55rem;
        }
        .daily-mission-mini-action {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          padding: 0.55rem 0.9rem;
          border-radius: 999px;
          border: 1px solid rgba(34, 211, 238, 0.3);
          background: rgba(34, 211, 238, 0.08);
          color: #8cf6ff;
          font-weight: 700;
          text-decoration: none;
          transition: transform 0.2s ease, background-color 0.2s ease, color 0.2s ease;
        }
        .daily-mission-mini-action:hover {
          transform: translateY(-1px);
          color: #fff;
          background: rgba(34, 211, 238, 0.18);
        }
        .daily-mission-mini-action.is-primary {
          background: linear-gradient(180deg, rgba(34, 211, 238, 0.94), rgba(14, 165, 233, 0.9));
          color: #03111d;
          border-color: transparent;
        }
        .daily-mission-mini-action.is-disabled {
          opacity: 0.5;
          pointer-events: none;
        }
        .daily-mission-modal {
          position: fixed;
          inset: 0;
          z-index: 1085;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 1rem;
          background: rgba(2, 6, 23, 0.78);
          backdrop-filter: blur(10px);
          transition: opacity 0.2s ease, visibility 0.2s ease;
          opacity: 1;
          visibility: visible;
        }
        .daily-mission-modal.is-hidden {
          opacity: 0;
          visibility: hidden;
          pointer-events: none;
        }
        .daily-mission-modal-card {
          width: min(100%, 560px);
          border-radius: 1.5rem;
          border: 1px solid rgba(34, 211, 238, 0.2);
          background: linear-gradient(180deg, rgba(6, 10, 18, 0.98), rgba(4, 8, 14, 0.99));
          box-shadow: 0 24px 60px rgba(0, 0, 0, 0.46), 0 0 28px rgba(34, 211, 238, 0.1);
          padding: 1rem;
        }
        .daily-mission-modal-head {
          display: flex;
          align-items: flex-start;
          justify-content: space-between;
          gap: 1rem;
          margin-bottom: 1rem;
        }
        .daily-mission-modal-head h3 {
          margin: 0;
          color: #fff;
          font-family: 'Oxanium', sans-serif;
          font-size: 1.25rem;
        }
        .daily-mission-modal-close {
          width: 2.25rem;
          height: 2.25rem;
          border: 1px solid rgba(34, 211, 238, 0.24);
          border-radius: 999px;
          background: rgba(34, 211, 238, 0.08);
          color: #8cf6ff;
          display: grid;
          place-items: center;
          transition: background-color 0.2s ease, transform 0.2s ease;
        }
        .daily-mission-modal-close:hover {
          transform: translateY(-1px);
          background: rgba(34, 211, 238, 0.14);
        }
        .daily-mission-modal-stage {
          display: grid;
          justify-items: center;
          gap: 1rem;
        }
        @keyframes daily-mission-chest-pulse {
          0%, 100% { transform: scale(1); }
          50% { transform: scale(1.02); }
        }
        @keyframes daily-mission-sparks {
          0% { filter: brightness(1); }
          50% { filter: brightness(1.2); }
          100% { filter: brightness(1); }
        }
        @media (max-width: 767.98px) {
          .daily-mission-toggle,
          .daily-mission-body {
            padding-left: 0.9rem;
            padding-right: 0.9rem;
          }
          .daily-mission-chest-stage {
            padding: 0.85rem;
          }
        }
      </style>

      <?php if ($startupPopupShouldRender): ?>
        <div id="startup-popup" class="startup-popup-shell is-hidden" data-frequency="<?= htmlspecialchars($startupPopupFrequency, ENT_QUOTES, 'UTF-8') ?>" data-should-open="<?= $startupPopupShouldOpen ? '1' : '0' ?>" data-mode="<?= htmlspecialchars($startupPopupMode, ENT_QUOTES, 'UTF-8') ?>" data-close-delay="<?= $startupPopupMode === 'gallery' ? '2000' : '0' ?>" aria-hidden="true">
          <?php if ($startupPopupMode === 'video'): ?>
            <div class="startup-popup-card startup-popup-card-video">
              <button type="button" class="startup-popup-close" id="startup-popup-close" aria-label="Cerrar ventana inicial">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                  <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
                </svg>
              </button>
              <h2 class="startup-popup-video-title">🎮 Cómo recargar en la página</h2>
              <p class="startup-popup-video-subtitle">Vean el video completo, allí muestro todos los pasos para recargar correctamente</p>
              <div class="startup-popup-video-frame">
                <iframe src="<?= htmlspecialchars($startupPopupVideoEmbedUrl, ENT_QUOTES, 'UTF-8') ?>" data-embed-src="<?= htmlspecialchars($startupPopupVideoEmbedUrl, ENT_QUOTES, 'UTF-8') ?>" title="Cómo recargar en la página" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
              </div>
              <a href="<?= htmlspecialchars($startupPopupChannelUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="startup-popup-link startup-popup-video-link" id="startup-popup-link">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M20.52 3.48A11.8 11.8 0 0 0 12.08 0C5.54 0 .22 5.32.22 11.86c0 2.09.55 4.13 1.58 5.93L0 24l6.39-1.67a11.8 11.8 0 0 0 5.69 1.45h.01c6.54 0 11.86-5.32 11.86-11.86 0-3.17-1.23-6.16-3.43-8.44ZM12.09 21.76h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.79.99 1.01-3.69-.23-.38A9.87 9.87 0 0 1 2.2 11.86C2.2 6.4 6.63 1.98 12.08 1.98c2.64 0 5.12 1.03 6.98 2.91a9.8 9.8 0 0 1 2.88 6.98c0 5.45-4.43 9.89-9.85 9.89Zm5.42-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.46-.15-.66.15-.2.3-.76.97-.93 1.17-.17.2-.34.22-.64.07-.3-.15-1.27-.47-2.41-1.49-.89-.8-1.49-1.79-1.67-2.09-.17-.3-.02-.47.13-.62.13-.13.3-.34.44-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.66-1.59-.9-2.17-.24-.58-.48-.5-.66-.5h-.56c-.2 0-.52.08-.79.37-.27.3-1.05 1.03-1.05 2.52 0 1.49 1.08 2.92 1.23 3.12.15.2 2.11 3.23 5.12 4.52.72.31 1.29.49 1.73.63.73.23 1.39.2 1.91.12.58-.09 1.76-.72 2.01-1.42.25-.69.25-1.29.17-1.42-.07-.12-.27-.2-.57-.35Z"/></svg>
                <span>📢 Canal de WhatsApp</span>
              </a>
              <button type="button" class="startup-popup-link startup-popup-video-dismiss" id="startup-popup-dismiss">Cerrar y recargar</button>
            </div>
          <?php elseif ($startupPopupMode === 'gallery'): ?>
            <div class="startup-popup-gallery-stage">
              <div class="startup-popup-card startup-popup-card-gallery">
                <div class="startup-popup-gallery-viewport">
                  <?php foreach ($startupPopupGalleryImages as $galleryIndex => $galleryImage): ?>
                    <?php
                      $galleryImagePath = trim((string) ($galleryImage['path'] ?? ''));
                      $galleryLinkUrl = trim((string) ($galleryImage['link_url'] ?? ''));
                      $galleryLinkTarget = trim((string) ($galleryImage['link_target'] ?? '_self')) === '_blank' ? '_blank' : '_self';
                    ?>
                    <<?= $galleryLinkUrl !== '' ? 'a' : 'div' ?> class="startup-popup-gallery-slide<?= $galleryIndex === 0 ? ' is-active' : '' ?>" data-startup-gallery-slide<?= $galleryLinkUrl !== '' ? ' href="' . htmlspecialchars($galleryLinkUrl, ENT_QUOTES, 'UTF-8') . '" target="' . htmlspecialchars($galleryLinkTarget, ENT_QUOTES, 'UTF-8') . '"' . ($galleryLinkTarget === '_blank' ? ' rel="noopener noreferrer"' : '') : '' ?>>
                      <img src="<?= htmlspecialchars($galleryImagePath, ENT_QUOTES, 'UTF-8') ?>" alt="Imagen <?= $galleryIndex + 1 ?> de la ventana inicial">
                    </<?= $galleryLinkUrl !== '' ? 'a' : 'div' ?>>
                  <?php endforeach; ?>
                  <?php if (count($startupPopupGalleryImages) > 1): ?>
                    <div class="startup-popup-gallery-dots" aria-label="Indicadores de la galería inicial">
                      <?php foreach ($startupPopupGalleryImages as $galleryIndex => $galleryImage): ?>
                        <button type="button" class="startup-popup-gallery-dot<?= $galleryIndex === 0 ? ' is-active' : '' ?>" data-startup-gallery-dot data-index="<?= $galleryIndex ?>" aria-label="Imagen <?= $galleryIndex + 1 ?>" aria-current="<?= $galleryIndex === 0 ? 'true' : 'false' ?>"></button>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="startup-popup-gallery-actions">
                <a href="<?= htmlspecialchars($startupPopupChannelUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="startup-popup-link startup-popup-gallery-link" id="startup-popup-link">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M20.52 3.48A11.8 11.8 0 0 0 12.08 0C5.54 0 .22 5.32.22 11.86c0 2.09.55 4.13 1.58 5.93L0 24l6.39-1.67a11.8 11.8 0 0 0 5.69 1.45h.01c6.54 0 11.86-5.32 11.86-11.86 0-3.17-1.23-6.16-3.43-8.44ZM12.09 21.76h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.79.99 1.01-3.69-.23-.38A9.87 9.87 0 0 1 2.2 11.86C2.2 6.4 6.63 1.98 12.08 1.98c2.64 0 5.12 1.03 6.98 2.91a9.8 9.8 0 0 1 2.88 6.98c0 5.45-4.43 9.89-9.85 9.89Zm5.42-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.46-.15-.66.15-.2.3-.76.97-.93 1.17-.17.2-.34.22-.64.07-.3-.15-1.27-.47-2.41-1.49-.89-.8-1.49-1.79-1.67-2.09-.17-.3-.02-.47.13-.62.13-.13.3-.34.44-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.66-1.59-.9-2.17-.24-.58-.48-.5-.66-.5h-.56c-.2 0-.52.08-.79.37-.27.3-1.05 1.03-1.05 2.52 0 1.49 1.08 2.92 1.23 3.12.15.2 2.11 3.23 5.12 4.52.72.31 1.29.49 1.73.63.73.23 1.39.2 1.91.12.58-.09 1.76-.72 2.01-1.42.25-.69.25-1.29.17-1.42-.07-.12-.27-.2-.57-.35Z"/></svg>
                  <span>Unirse al canal de WhatsApp</span>
                </a>
                <button type="button" class="startup-popup-gallery-close is-locked" id="startup-popup-dismiss" data-startup-gallery-close aria-label="Cerrar ventana inicial">
                  <span class="startup-popup-gallery-close-label">X</span>
                </button>
              </div>
            </div>
          <?php else: ?>
            <div class="startup-popup-card">
              <button type="button" class="startup-popup-close" id="startup-popup-close" aria-label="Cerrar ventana inicial">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                  <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
                </svg>
              </button>
              <div class="startup-popup-logo" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor" role="img"><path d="M20.52 3.48A11.8 11.8 0 0 0 12.08 0C5.54 0 .22 5.32.22 11.86c0 2.09.55 4.13 1.58 5.93L0 24l6.39-1.67a11.8 11.8 0 0 0 5.69 1.45h.01c6.54 0 11.86-5.32 11.86-11.86 0-3.17-1.23-6.16-3.43-8.44ZM12.09 21.76h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.79.99 1.01-3.69-.23-.38A9.87 9.87 0 0 1 2.2 11.86C2.2 6.4 6.63 1.98 12.08 1.98c2.64 0 5.12 1.03 6.98 2.91a9.8 9.8 0 0 1 2.88 6.98c0 5.45-4.43 9.89-9.85 9.89Zm5.42-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.46-.15-.66.15-.2.3-.76.97-.93 1.17-.17.2-.34.22-.64.07-.3-.15-1.27-.47-2.41-1.49-.89-.8-1.49-1.79-1.67-2.09-.17-.3-.02-.47.13-.62.13-.13.3-.34.44-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.66-1.59-.9-2.17-.24-.58-.48-.5-.66-.5h-.56c-.2 0-.52.08-.79.37-.27.3-1.05 1.03-1.05 2.52 0 1.49 1.08 2.92 1.23 3.12.15.2 2.11 3.23 5.12 4.52.72.31 1.29.49 1.73.63.73.23 1.39.2 1.91.12.58-.09 1.76-.72 2.01-1.42.25-.69.25-1.29.17-1.42-.07-.12-.27-.2-.57-.35Z"/></svg>
              </div>
              <div class="startup-popup-badge">Canal oficial</div>
              <h2 class="startup-popup-title">Unete al canal de <strong><?= htmlspecialchars($startupPopupChannelName, ENT_QUOTES, 'UTF-8') ?></strong></h2>
              <p class="startup-popup-subtitle">Recibe ofertas exclusivas, promociones y novedades directamente en tu WhatsApp.</p>
              <ul class="startup-popup-list">
                <li class="startup-popup-list-item">
                  <span class="startup-popup-list-icon" aria-hidden="true">🎮</span>
                  <span class="startup-popup-list-text">Nuevos juegos y productos disponibles</span>
                </li>
                <li class="startup-popup-list-item">
                  <span class="startup-popup-list-icon" aria-hidden="true">🔥</span>
                  <span class="startup-popup-list-text">Promociones y codigos de descuento</span>
                </li>
                <li class="startup-popup-list-item">
                  <span class="startup-popup-list-icon" aria-hidden="true">⚡</span>
                  <span class="startup-popup-list-text">Avisos de mantenimiento y novedades</span>
                </li>
              </ul>
              <a href="<?= htmlspecialchars($startupPopupChannelUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="startup-popup-link" id="startup-popup-link">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M20.52 3.48A11.8 11.8 0 0 0 12.08 0C5.54 0 .22 5.32.22 11.86c0 2.09.55 4.13 1.58 5.93L0 24l6.39-1.67a11.8 11.8 0 0 0 5.69 1.45h.01c6.54 0 11.86-5.32 11.86-11.86 0-3.17-1.23-6.16-3.43-8.44ZM12.09 21.76h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.79.99 1.01-3.69-.23-.38A9.87 9.87 0 0 1 2.2 11.86C2.2 6.4 6.63 1.98 12.08 1.98c2.64 0 5.12 1.03 6.98 2.91a9.8 9.8 0 0 1 2.88 6.98c0 5.45-4.43 9.89-9.85 9.89Zm5.42-7.41c-.3-.15-1.76-.87-2.03-.97-.27-.1-.46-.15-.66.15-.2.3-.76.97-.93 1.17-.17.2-.34.22-.64.07-.3-.15-1.27-.47-2.41-1.49-.89-.8-1.49-1.79-1.67-2.09-.17-.3-.02-.47.13-.62.13-.13.3-.34.44-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.66-1.59-.9-2.17-.24-.58-.48-.5-.66-.5h-.56c-.2 0-.52.08-.79.37-.27.3-1.05 1.03-1.05 2.52 0 1.49 1.08 2.92 1.23 3.12.15.2 2.11 3.23 5.12 4.52.72.31 1.29.49 1.73.63.73.23 1.39.2 1.91.12.58-.09 1.76-.72 2.01-1.42.25-.69.25-1.29.17-1.42-.07-.12-.27-.2-.57-.35Z"/></svg>
                <span>Unirse al canal</span>
              </a>
              <button type="button" class="startup-popup-dismiss" id="startup-popup-dismiss">Ahora no</button>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($banners)): ?>
        <section class="mt-4 promo-section-mobile" style="animation: fadeUp 650ms ease-out both;">
          <div class="promo-slider-shell">
            <div id="promo-slider" class="promo-slider-track" tabindex="0">
              <?php $bannerCount = count($banners); ?>
              <?php foreach ($banners as $index => $banner): ?>
                <?php
                  $accent = $banner["accent"] ?? "cyan";
                  $labelClass = $accentMap[$accent]["label"] ?? $accentMap["cyan"]["label"];
                  $gradientClass = $accentMap[$accent]["gradient"] ?? $accentMap["cyan"]["gradient"];
                  $bannerUrl = trim((string) ($banner['url'] ?? ''));
                  $bannerTarget = !empty($banner['open_in_new_tab']) ? '_blank' : '_self';
                  $bannerClass = 'promo-slide-card text-decoration-none';
                  if ($index === 0) {
                    $bannerClass .= ' is-center';
                  } elseif ($bannerCount > 1 && $index === 1) {
                    $bannerClass .= ' is-next';
                  } elseif ($bannerCount > 2 && $index === $bannerCount - 1) {
                    $bannerClass .= ' is-prev';
                  } else {
                    $bannerClass .= ' is-hidden';
                  }
                ?>
                <<?= $bannerUrl !== '' ? 'a' : 'article' ?> class="<?= htmlspecialchars($bannerClass, ENT_QUOTES, 'UTF-8') ?>"<?= $bannerUrl !== '' ? ' href="' . htmlspecialchars($bannerUrl, ENT_QUOTES, 'UTF-8') . '" target="' . htmlspecialchars($bannerTarget, ENT_QUOTES, 'UTF-8') . '"' . ($bannerTarget === '_blank' ? ' rel="noopener noreferrer"' : '') : '' ?>>
                  <img src="<?php echo htmlspecialchars($banner["image"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($banner["title"], ENT_QUOTES, "UTF-8"); ?>" class="promo-slide-image" />
                  <div class="promo-slide-overlay"></div>
                  <div class="promo-slide-content">
                    <p class="small text-uppercase text-info mb-0" style="letter-spacing:0.35em;">
                      <?php echo htmlspecialchars($banner["label"], ENT_QUOTES, "UTF-8"); ?>
                    </p>
                    <h2 class="mt-1 fw-bold" style="font-family:'Oxanium',sans-serif;font-size:1.25rem;color:#fff;">
                      <?php echo htmlspecialchars($banner["title"], ENT_QUOTES, "UTF-8"); ?>
                    </h2>
                    <p class="mt-1 small text-secondary">
                      <?php echo htmlspecialchars($banner["subtitle"], ENT_QUOTES, "UTF-8"); ?>
                    </p>
                  </div>
                </<?= $bannerUrl !== '' ? 'a' : 'article' ?>>
              <?php endforeach; ?>
            </div>
            <div id="promo-dots" class="promo-dots mt-3">
              <?php foreach ($banners as $index => $banner): ?>
                <?php $isActive = $index === 0; ?>
                <button type="button" class="promo-dot<?php echo $isActive ? ' is-active' : ''; ?>" data-index="<?php echo $index; ?>" aria-label="Banner <?php echo $index + 1; ?>" aria-current="<?php echo $isActive ? 'true' : 'false'; ?>"></button>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($dailyMissionsUserId > 0): ?>
        <?php
          $dailyMissionExploreTarget = $dailyMissionsExploreTargets[0] ?? app_path('/juegos');
          $dailyMissionShareTargetsJson = json_encode($dailyMissionsSocialTargets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          $dailyMissionTaskCount = max(1, count($dailyMissionsTasks));
          $dailyMissionCompletedCount = max(0, (int) ($dailyMissionsDay['completed_tasks_count'] ?? 0));
          $dailyMissionRemaining = max(0, $dailyMissionTaskCount - $dailyMissionCompletedCount);
          $dailyMissionStreak = max(0, (int) ($dailyMissionsDay['current_streak_days'] ?? 0));
          $dailyMissionImmunity = max(0, (int) ($dailyMissionsState['immunity_balance'] ?? 0));
          $dailyMissionRewardLabel = $dailyMissionsCanOpenChest ? 'Cofre listo para abrir' : 'Completa las tareas para abrirlo';
          $dailyMissionRemainingLabel = $dailyMissionRemaining === 1 ? '1 tarea restante' : $dailyMissionRemaining . ' tareas restantes';
          $dailyMissionProgressLabel = $dailyMissionsProgressPercent >= 100 ? '100% completado' : $dailyMissionsProgressPercent . '% completado';
        ?>
        <section
          id="daily-missions-shell"
          class="daily-mission-shell mt-5"
          style="--daily-accent:<?php echo htmlspecialchars((string) ($dailyMissionsPalette['accent'] ?? '#22d3ee'), ENT_QUOTES, 'UTF-8'); ?>;--daily-glow:<?php echo htmlspecialchars((string) ($dailyMissionsPalette['glow'] ?? 'rgba(34,211,238,0.28)'), ENT_QUOTES, 'UTF-8'); ?>;--daily-border:<?php echo htmlspecialchars((string) ($dailyMissionsPalette['border'] ?? 'rgba(34,211,238,0.38)'), ENT_QUOTES, 'UTF-8'); ?>;--daily-surface:<?php echo htmlspecialchars((string) ($dailyMissionsPalette['surface'] ?? 'rgba(8,14,24,0.96)'), ENT_QUOTES, 'UTF-8'); ?>;"
          data-api-url="<?php echo htmlspecialchars((string) $dailyMissionsScriptPayload['api_url'], ENT_QUOTES, 'UTF-8'); ?>"
          data-progress="<?php echo (int) $dailyMissionsProgressPercent; ?>"
          data-can-open-chest="<?php echo $dailyMissionsCanOpenChest ? '1' : '0'; ?>"
          data-level="<?php echo htmlspecialchars($dailyMissionsLevelKey, ENT_QUOTES, 'UTF-8'); ?>"
          data-explore-timer="<?php echo (int) ($dailyMissionsSettings['explore_timer_seconds'] ?? 7); ?>"
          data-share-timer="<?php echo (int) ($dailyMissionsSettings['share_timer_seconds'] ?? 15); ?>"
          data-explore-target="<?php echo htmlspecialchars($dailyMissionExploreTarget, ENT_QUOTES, 'UTF-8'); ?>"
          data-share-targets="<?php echo htmlspecialchars((string) $dailyMissionShareTargetsJson, ENT_QUOTES, 'UTF-8'); ?>"
          data-task-count="<?php echo $dailyMissionTaskCount; ?>"
          data-completed-count="<?php echo $dailyMissionCompletedCount; ?>"
          data-remaining-count="<?php echo $dailyMissionRemaining; ?>"
          data-streak="<?php echo $dailyMissionStreak; ?>"
          data-immunity="<?php echo $dailyMissionImmunity; ?>"
        >
          <div class="accordion" id="daily-missions-accordion">
            <div class="accordion-item daily-mission-panel">
              <h2 class="accordion-header" id="daily-missions-heading">
                <button class="accordion-button collapsed daily-mission-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#daily-missions-collapse" aria-expanded="false" aria-controls="daily-missions-collapse">
                  <div class="daily-mission-header-copy">
                    <span class="daily-mission-header-eyebrow"><?php echo htmlspecialchars((string) ($dailyMissionsSettings['title'] ?? 'Mision diaria'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <h3 class="daily-mission-header-title mb-0"><?php echo htmlspecialchars((string) ($dailyMissionsSettings['subtitle'] ?? 'Completa las tareas, abre el cofre y gana Win Points.'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <span class="daily-mission-header-subtitle"><?php echo htmlspecialchars($dailyMissionRewardLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                  <span class="daily-mission-pill text-end">
                    <small>Progreso</small>
                    <strong id="daily-mission-pill-progress"><?php echo htmlspecialchars($dailyMissionProgressLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                  </span>
                </button>
              </h2>
              <div id="daily-missions-collapse" class="accordion-collapse collapse" aria-labelledby="daily-missions-heading" data-bs-parent="#daily-missions-accordion">
                <div class="daily-mission-body">
                  <div class="daily-mission-topline">
                    <div class="daily-mission-topline-copy">
                      <div class="small text-uppercase" style="letter-spacing:0.28em;">Recompensas por Win Points</div>
                      <h3 class="mb-0">Racha actual: <span id="daily-mission-streak-text" class="text-info"><?php echo number_format($dailyMissionStreak); ?></span> dias</h3>
                      <div class="text-secondary small"><?php echo htmlspecialchars($dailyMissionRemainingLabel, ENT_QUOTES, 'UTF-8'); ?> | Inmunidad: <span id="daily-mission-immunity-text" class="text-warning"><?php echo number_format($dailyMissionImmunity); ?></span></div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <span class="badge rounded-pill text-bg-dark border border-info-subtle text-info px-3 py-2">Nivel <?php echo htmlspecialchars($dailyMissionsPalette['label'] ?? 'Basico', ENT_QUOTES, 'UTF-8'); ?></span>
                      <span class="badge rounded-pill text-bg-dark border border-success-subtle text-success px-3 py-2" id="daily-mission-completed-badge"><?php echo htmlspecialchars($dailyMissionCompletedCount . '/' . $dailyMissionTaskCount, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                  </div>

                  <div class="daily-mission-summary-grid">
                    <div class="daily-mission-summary-card is-accent">
                      <small>Progreso</small>
                      <strong id="daily-mission-summary-progress"><?php echo htmlspecialchars($dailyMissionProgressLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="daily-mission-summary-card">
                      <small>Tareas completadas</small>
                      <strong id="daily-mission-summary-completed"><?php echo number_format($dailyMissionCompletedCount); ?></strong>
                    </div>
                    <div class="daily-mission-summary-card">
                      <small>Racha activa</small>
                      <strong id="daily-mission-summary-streak"><?php echo number_format($dailyMissionStreak); ?></strong>
                    </div>
                    <div class="daily-mission-summary-card">
                      <small>Chest bonus</small>
                      <strong id="daily-mission-summary-level"><?php echo htmlspecialchars($dailyMissionsPalette['label'] ?? 'Basico', ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                  </div>

                  <div class="daily-mission-chest-stage">
                    <div class="daily-mission-chest-card">
                      <button type="button" class="daily-mission-chest-shell<?php echo $dailyMissionsCanOpenChest ? ' is-open' : ''; ?>" id="daily-mission-chest-shell" data-daily-mission-chest<?php echo $dailyMissionsCanOpenChest ? '' : ' disabled'; ?> aria-label="Abrir cofre diario" <?php echo $dailyMissionsCanOpenChest ? '' : 'disabled'; ?>>
                        <div class="daily-mission-modal-sparks"></div>
                        <div class="daily-mission-modal-lid"></div>
                        <div class="daily-mission-modal-band is-top"></div>
                        <div class="daily-mission-modal-band is-bottom"></div>
                        <div class="daily-mission-modal-base"></div>
                        <div class="daily-mission-modal-lock"></div>
                      </button>
                    </div>
                    <div class="daily-mission-prize-copy align-self-center text-md-start">
                      <small>Estado actual</small>
                      <strong id="daily-mission-state-title"><?php echo htmlspecialchars($dailyMissionRewardLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                      <span id="daily-mission-state-description">El cofre se abre cuando todas las tareas del dia estan completas.</span>
                      <div class="progress daily-mission-progress mt-2">
                        <div id="daily-mission-progress-bar" class="progress-bar" role="progressbar" style="width: <?php echo (int) $dailyMissionsProgressPercent; ?>%;" aria-valuenow="<?php echo (int) $dailyMissionsProgressPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                      </div>
                    </div>
                  </div>

                  <div class="daily-mission-task-grid" id="daily-mission-task-grid">
                    <?php foreach ($dailyMissionsTasks as $taskIndex => $task): ?>
                      <?php
                        $taskType = (string) ($task['task_type'] ?? '');
                        $taskCompleted = !empty($task['completed_today']);
                        $taskDisabled = empty($task['active']);
                        $taskPoints = max(0, (int) ($task['base_points'] ?? 0));
                        $taskTimer = max(0, (int) ($task['timer_seconds'] ?? 0));
                        $taskActionLabel = trim((string) ($task['action_label'] ?? 'Realizar'));
                        $taskActionUrl = trim((string) ($task['action_url'] ?? ''));
                        if ($taskType === 'explore' && $dailyMissionExploreTarget !== '') {
                          $taskActionUrl = $dailyMissionExploreTarget;
                        } elseif ($taskType === 'share') {
                          $taskActionUrl = '#';
                        } elseif ($taskType === 'purchase' && $taskActionUrl === '') {
                          $taskActionUrl = app_path('/juegos');
                        }
                        $taskBadgeText = daily_missions_task_type_label($taskType);
                        $taskShareTargets = $taskType === 'share' ? $dailyMissionShareTargetsJson : '';
                      ?>
                      <div class="daily-mission-task-card<?php echo $taskCompleted ? ' is-done' : ''; ?><?php echo $taskDisabled ? ' is-disabled' : ''; ?>" data-mission-key="<?php echo htmlspecialchars((string) ($task['mission_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-mission-type="<?php echo htmlspecialchars($taskType, ENT_QUOTES, 'UTF-8'); ?>" data-mission-timer="<?php echo $taskTimer; ?>" data-target-url="<?php echo htmlspecialchars($taskActionUrl, ENT_QUOTES, 'UTF-8'); ?>" data-share-targets="<?php echo htmlspecialchars($taskShareTargets, ENT_QUOTES, 'UTF-8'); ?>" data-completed="<?php echo $taskCompleted ? '1' : '0'; ?>" data-disabled="<?php echo $taskDisabled ? '1' : '0'; ?>">
                        <div class="daily-mission-task-kicker">
                          <div class="daily-mission-task-number"><?php echo str_pad((string) ($taskIndex + 1), 2, '0', STR_PAD_LEFT); ?></div>
                          <span class="daily-mission-task-state<?php echo $taskCompleted ? ' is-done' : ''; ?>"><?php echo $taskCompleted ? 'Completada' : ($taskDisabled ? 'Inactiva' : 'Activa'); ?></span>
                        </div>
                        <h3 class="daily-mission-task-title"><?php echo htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="daily-mission-task-description mb-0"><?php echo htmlspecialchars((string) ($task['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                          <span class="badge rounded-pill text-bg-dark border border-info-subtle text-info">+<?php echo number_format($taskPoints); ?> WP</span>
                          <span class="text-secondary small text-uppercase" style="letter-spacing:0.18em;"><?php echo htmlspecialchars($taskBadgeText, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="daily-mission-task-actions">
                          <?php if ($taskType === 'share'): ?>
                            <?php foreach ($dailyMissionsSocialTargets as $socialKey => $socialTarget): ?>
                              <button type="button" class="daily-mission-mini-action<?php echo $taskCompleted ? ' is-primary' : ''; ?>" data-daily-mission-social="<?php echo htmlspecialchars($socialKey, ENT_QUOTES, 'UTF-8'); ?>" data-target-url="<?php echo htmlspecialchars((string) ($socialTarget['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-task-key="<?php echo htmlspecialchars((string) ($task['mission_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-task-timer="<?php echo (int) ($taskTimer > 0 ? $taskTimer : (int) ($dailyMissionsSettings['share_timer_seconds'] ?? 15)); ?>"<?php echo $taskCompleted || $taskDisabled || empty($socialTarget['url']) ? ' disabled' : ''; ?>>
                                <?php echo htmlspecialchars((string) ($socialTarget['label'] ?? ucfirst($socialKey)), ENT_QUOTES, 'UTF-8'); ?>
                              </button>
                            <?php endforeach; ?>
                          <?php elseif ($taskType === 'login'): ?>
                            <span class="daily-mission-mini-action is-primary<?php echo $taskCompleted ? '' : ' opacity-75'; ?>" aria-disabled="true"><?php echo $taskCompleted ? 'Bono reclamado' : 'Se activa al iniciar sesion'; ?></span>
                          <?php else: ?>
                            <a href="<?php echo htmlspecialchars($taskActionUrl !== '' ? $taskActionUrl : app_path('/juegos'), ENT_QUOTES, 'UTF-8'); ?>" class="daily-mission-mini-action<?php echo $taskCompleted ? ' is-primary' : ''; ?>" data-task-key="<?php echo htmlspecialchars((string) ($task['mission_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-task-timer="<?php echo $taskTimer; ?>"<?php echo $taskDisabled ? ' aria-disabled="true" tabindex="-1"' : ''; ?>><?php echo htmlspecialchars($taskActionLabel !== '' ? $taskActionLabel : 'Abrir', ENT_QUOTES, 'UTF-8'); ?></a>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      <?php else: ?>
        <section class="mt-5">
          <div class="rounded-4 border border-info-subtle p-4" style="background:linear-gradient(180deg, rgba(8,14,24,0.96), rgba(5,9,16,0.98));box-shadow:0 16px 40px rgba(0,0,0,0.32);">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
              <div>
                <div class="small text-uppercase text-info" style="letter-spacing:0.28em;">Mision diaria</div>
                <h2 class="h4 mb-1 text-white">Activa tus misiones diarias iniciando sesión</h2>
                <p class="text-secondary mb-0">Inicia sesión para ver las tareas, ganar Win Points y abrir el cofre.</p>
              </div>
              <a href="<?php echo htmlspecialchars(app_path('/login.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info fw-bold px-4 rounded-pill">Iniciar sesión</a>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <div id="daily-mission-modal" class="daily-mission-modal is-hidden" aria-hidden="true">
        <div class="position-absolute top-0 start-0 w-100 h-100" data-daily-mission-modal-close></div>
        <div class="daily-mission-modal-card position-relative">
          <div class="daily-mission-modal-head">
            <div>
              <div class="small text-uppercase text-info" style="letter-spacing:0.28em;">Cofre diario</div>
              <h3 id="daily-mission-modal-title">Premio desbloqueado</h3>
            </div>
            <button type="button" class="daily-mission-modal-close" data-daily-mission-modal-close aria-label="Cerrar">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <div class="daily-mission-modal-stage" id="daily-mission-modal-stage" data-level="<?php echo htmlspecialchars($dailyMissionsLevelKey, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="daily-mission-chest-3d" id="daily-mission-chest-3d">
              <div class="daily-mission-modal-sparks"></div>
              <div class="daily-mission-modal-lid"></div>
              <div class="daily-mission-modal-band is-top"></div>
              <div class="daily-mission-modal-band is-bottom"></div>
              <div class="daily-mission-modal-base"></div>
              <div class="daily-mission-modal-lock"></div>
            </div>
          </div>
        </div>
      </div>

      <section class="mt-5">
        <div class="d-flex align-items-center justify-content-between">
          <h2 class="fw-bold" style="font-family:'Oxanium',sans-serif;font-size:1.1rem;">Juegos populares</h2>
          <a href="<?= htmlspecialchars(app_path('/populares'), ENT_QUOTES, 'UTF-8') ?>" class="small fw-semibold text-info text-uppercase">Ver todo</a>
        </div>
        <div class="mt-4 row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
          <?php foreach ($popularGames as $game): ?>
            <div class="col">
              <a href="<?= htmlspecialchars(app_path(game_route_path($game)), ENT_QUOTES, 'UTF-8') ?>" class="store-game-card d-block rounded-4 border bg-dark p-2 h-100 text-decoration-none">
                <div class="position-relative overflow-hidden rounded-3" style="aspect-ratio:1/1;">
                  <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) ($game['imagen'] ?? ''), '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($game['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="img-fluid w-100 h-100 object-fit-cover" style="aspect-ratio:1/1;" />
                  <span title="Popular" class="position-absolute top-0 end-0 text-success fs-4" style="text-shadow:0 0 4px #000;">★</span>
                </div>
                <div class="mt-2">
                  <p class="store-game-title fw-semibold d-flex align-items-center mb-1" style="font-size:1rem;">
                    <?= htmlspecialchars($game['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                  </p>
                  <p class="store-game-price-prefix small mb-0">
                    <?php if (!empty($game['imagen_paquete'])): ?>
                      <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $game['imagen_paquete'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Paquete" class="img-fluid rounded me-1 align-middle" style="height:20px;width:20px;display:inline-block;" />
                    <?php endif; ?>
                    <?php if (!empty($game['min_price_label'])): ?>
                      Desde <span class="store-game-price"><?= htmlspecialchars($game['min_price_label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </p>
                </div>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if (!empty($featured)): ?>
        <section class="mt-5 featured-section-mobile">
          <?php
            $featuredUrl = trim((string) ($featured['url'] ?? ''));
            $featuredTarget = !empty($featured['open_in_new_tab']) ? '_blank' : '_self';
          ?>
          <<?= $featuredUrl !== '' ? 'a' : 'div' ?> class="featured-banner-card"<?= $featuredUrl !== '' ? ' href="' . htmlspecialchars($featuredUrl, ENT_QUOTES, 'UTF-8') . '" target="' . htmlspecialchars($featuredTarget, ENT_QUOTES, 'UTF-8') . '"' . ($featuredTarget === '_blank' ? ' rel="noopener noreferrer"' : '') : '' ?>>
            <img src="<?php echo htmlspecialchars($featured["image"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($featured["title"], ENT_QUOTES, "UTF-8"); ?>" class="featured-banner-image" />
            <div class="featured-banner-overlay"></div>
            <div class="featured-banner-content">
              <p class="small text-uppercase text-info mb-0" style="letter-spacing:0.35em;"><?php echo htmlspecialchars($featured["label"], ENT_QUOTES, "UTF-8"); ?></p>
              <h3 class="mt-1 fw-bold" style="font-family:'Oxanium',sans-serif;font-size:1.25rem;"><?php echo htmlspecialchars($featured["title"], ENT_QUOTES, "UTF-8"); ?></h3>
              <p class="mt-1 small text-secondary"><?php echo htmlspecialchars($featured["subtitle"], ENT_QUOTES, "UTF-8"); ?></p>
            </div>
          </<?= $featuredUrl !== '' ? 'a' : 'div' ?>>
        </section>
      <?php endif; ?>

      <section class="mt-5">
        <div class="d-flex align-items-center justify-content-between">
          <h2 class="fw-bold" style="font-family:'Oxanium',sans-serif;font-size:1.1rem;">Más juegos</h2>
          <a href="<?= htmlspecialchars(app_path('/juegos'), ENT_QUOTES, 'UTF-8') ?>" class="small fw-semibold text-info text-uppercase">Explorar</a>
        </div>
        <div class="mt-4 row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
          <?php foreach ($moreGames as $game): ?>
            <div class="col">
              <a href="<?= htmlspecialchars(app_path(game_route_path($game)), ENT_QUOTES, 'UTF-8') ?>" class="store-game-card d-block rounded-4 border bg-dark p-2 h-100 text-decoration-none">
                <div class="position-relative overflow-hidden rounded-3" style="aspect-ratio:1/1;">
                  <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) ($game['imagen'] ?? ''), '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($game['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="img-fluid w-100 h-100 object-fit-cover" style="aspect-ratio:1/1;" />
                  <?php if (!empty($game['popular'])): ?>
                    <span title="Popular" class="position-absolute top-0 end-0 text-success fs-4" style="text-shadow:0 0 4px #000;">★</span>
                  <?php endif; ?>
                </div>
                <div class="mt-2">
                  <p class="store-game-title fw-semibold d-flex align-items-center mb-1" style="font-size:1rem;">
                    <?= htmlspecialchars($game['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                  </p>
                  <p class="store-game-price-prefix small mb-0">
                    <?php if (!empty($game['imagen_paquete'])): ?>
                      <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $game['imagen_paquete'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Paquete" class="img-fluid rounded me-1 align-middle" style="height:20px;width:20px;display:inline-block;" />
                    <?php endif; ?>
                    <?php if (!empty($game['min_price_label'])): ?>
                      Desde <span class="store-game-price"><?= htmlspecialchars($game['min_price_label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </p>
                </div>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

<?php
$pageScripts = [
  <<<'SCRIPT'
<script>
  (() => {
    const popup = document.getElementById("startup-popup");
    if (!popup) {
      return;
    }

    const popupCard = popup.querySelector(".startup-popup-card");
    const closeButton = document.getElementById("startup-popup-close");
    const dismissButton = document.getElementById("startup-popup-dismiss");
    const videoFrame = popup.querySelector("iframe[data-embed-src]");
    const gallerySlides = Array.from(popup.querySelectorAll("[data-startup-gallery-slide]"));
    const galleryDots = Array.from(popup.querySelectorAll("[data-startup-gallery-dot]"));
    const galleryCloseButton = popup.querySelector("[data-startup-gallery-close]");
    const popupFrequency = popup.dataset.frequency || "per_session";
    const popupShouldOpen = popup.dataset.shouldOpen === "1";
    const popupMode = popup.dataset.mode || "none";
    const closeDelayMs = Number.parseInt(popup.dataset.closeDelay || "0", 10);
    const perEntryStorageKey = "vg_startup_popup_seen";
    let galleryIndex = 0;
    let galleryAutoplayId = 0;
    let galleryUnlockTimeoutId = 0;
    let popupCanClose = popupMode !== "gallery" || closeDelayMs <= 0;

    const stopVideoPlayback = () => {
      if (!videoFrame) {
        return;
      }
      if (videoFrame.src !== "about:blank") {
        videoFrame.src = "about:blank";
      }
    };

    const restoreVideoPlayback = () => {
      if (!videoFrame) {
        return;
      }
      const embedSrc = videoFrame.dataset.embedSrc || "";
      if (embedSrc && videoFrame.src !== embedSrc) {
        videoFrame.src = embedSrc;
      }
    };

    const stopGalleryAutoplay = () => {
      if (galleryAutoplayId) {
        window.clearInterval(galleryAutoplayId);
        galleryAutoplayId = 0;
      }
    };

    const setGalleryIndex = (index) => {
      if (!gallerySlides.length) {
        return;
      }

      galleryIndex = ((index % gallerySlides.length) + gallerySlides.length) % gallerySlides.length;
      gallerySlides.forEach((slide, slideIndex) => {
        slide.classList.toggle("is-active", slideIndex === galleryIndex);
      });
      galleryDots.forEach((dot, dotIndex) => {
        const isActive = dotIndex === galleryIndex;
        dot.classList.toggle("is-active", isActive);
        dot.setAttribute("aria-current", isActive ? "true" : "false");
      });
    };

    const startGalleryAutoplay = () => {
      if (gallerySlides.length < 2) {
        return;
      }

      stopGalleryAutoplay();
      galleryAutoplayId = window.setInterval(() => {
        setGalleryIndex(galleryIndex + 1);
      }, 3200);
    };

    const lockGalleryClose = () => {
      if (!galleryCloseButton || popupMode !== "gallery") {
        popupCanClose = true;
        return;
      }

      window.clearTimeout(galleryUnlockTimeoutId);
      popupCanClose = closeDelayMs <= 0;
      galleryCloseButton.classList.remove("is-ready", "is-locked");

      if (popupCanClose) {
        galleryCloseButton.disabled = false;
        galleryCloseButton.removeAttribute("aria-disabled");
        galleryCloseButton.classList.add("is-ready");
        return;
      }

      galleryCloseButton.disabled = true;
      galleryCloseButton.setAttribute("aria-disabled", "true");
      void galleryCloseButton.offsetWidth;
      galleryCloseButton.classList.add("is-locked");
      galleryUnlockTimeoutId = window.setTimeout(() => {
        popupCanClose = true;
        galleryCloseButton.disabled = false;
        galleryCloseButton.removeAttribute("aria-disabled");
        galleryCloseButton.classList.remove("is-locked");
        galleryCloseButton.classList.add("is-ready");
      }, closeDelayMs);
    };

    const hidePopup = (force = false) => {
      if (!force && !popupCanClose) {
        return;
      }

      stopGalleryAutoplay();
      window.clearTimeout(galleryUnlockTimeoutId);
      stopVideoPlayback();
      popup.classList.add("is-hidden");
      popup.setAttribute("aria-hidden", "true");
    };

    const showPopup = () => {
      if (!popupCard) {
        hidePopup();
        return;
      }

      restoreVideoPlayback();
      if (popupMode === "gallery") {
        setGalleryIndex(0);
        startGalleryAutoplay();
        lockGalleryClose();
      } else {
        popupCanClose = true;
      }
      popup.classList.remove("is-hidden");
      popup.setAttribute("aria-hidden", "false");

      window.requestAnimationFrame(() => {
        const rect = popupCard.getBoundingClientRect();
        if (rect.width < 40 || rect.height < 40) {
          hidePopup();
        }
      });
    };

    let mustShow = popupShouldOpen;
    if (popupFrequency === "per_entry") {
      try {
        mustShow = window.sessionStorage.getItem(perEntryStorageKey) !== "1";
        if (mustShow) {
          window.sessionStorage.setItem(perEntryStorageKey, "1");
        }
      } catch (error) {
        mustShow = popupShouldOpen;
      }
    }

    if (popupFrequency === "always") {
      mustShow = true;
    }

    if (mustShow) {
      showPopup();
    } else {
      hidePopup();
    }

    [closeButton, dismissButton].forEach((button) => {
      if (!button) {
        return;
      }
      button.addEventListener("click", () => hidePopup());
    });

    galleryDots.forEach((dot) => {
      dot.addEventListener("click", () => {
        const nextIndex = Number.parseInt(dot.dataset.index || "0", 10);
        if (!Number.isNaN(nextIndex)) {
          setGalleryIndex(nextIndex);
          startGalleryAutoplay();
        }
      });
    });

    popup.addEventListener("click", (event) => {
      if (event.target === popup) {
        hidePopup();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !popup.classList.contains("is-hidden")) {
        hidePopup();
      }
    });

    document.addEventListener("visibilitychange", () => {
      if (document.hidden && !popup.classList.contains("is-hidden")) {
        hidePopup();
      }
    });

    window.addEventListener("pagehide", () => hidePopup(true));
  })();
</script>
SCRIPT,
  <<<'SCRIPT'
<script>
  (() => {
    const slider = document.getElementById("promo-slider");
    if (!slider) {
      return;
    }

    const slides = Array.from(slider.querySelectorAll(".promo-slide-card"));
    const dots = Array.from(document.querySelectorAll("#promo-dots [data-index]"));
    if (!slides.length) {
      return;
    }

    const mobileQuery = window.matchMedia("(max-width: 767.98px)");

    let currentIndex = 0;
    let autoplayId = 0;
    let isPaused = false;
    let renderFrame = 0;
    let touchStartX = null;
    let touchStartY = null;
    let touchMoved = false;
    let suppressClickUntil = 0;

    const normalizeIndex = (index) => {
      const total = slides.length;
      return total ? ((index % total) + total) % total : 0;
    };

    const getSignedOffset = (index) => {
      const total = slides.length;
      if (!total) {
        return 0;
      }

      let offset = index - currentIndex;
      const half = total / 2;
      if (offset > half) {
        offset -= total;
      } else if (offset < -half) {
        offset += total;
      }

      return offset;
    };

    const syncDots = () => {
      dots.forEach((dot, idx) => {
        const isActive = idx === currentIndex;
        dot.classList.toggle("is-active", isActive);
        dot.setAttribute("aria-current", isActive ? "true" : "false");
      });
    };

    const applySlideState = () => {
      const isMobile = mobileQuery.matches;

      slides.forEach((slide, index) => {
        const offset = getSignedOffset(index);
        slide.classList.remove("is-prev", "is-center", "is-next", "is-hidden");
        slide.style.left = "";
        slide.style.right = "";
        slide.style.top = "";
        slide.style.width = isMobile ? "100%" : "15%";
        slide.style.height = isMobile ? "100%" : "100%";
        slide.style.opacity = "0";
        slide.style.pointerEvents = "none";
        slide.style.zIndex = "0";

        if (isMobile) {
          if (offset === 0) {
            slide.classList.add("is-center");
            slide.style.left = "0";
            slide.style.opacity = "1";
            slide.style.pointerEvents = "auto";
            slide.style.zIndex = "3";
          } else if (offset === -1) {
            slide.classList.add("is-prev");
            slide.style.left = "-100%";
            slide.style.opacity = "0";
          } else if (offset === 1) {
            slide.classList.add("is-next");
            slide.style.left = "100%";
            slide.style.opacity = "0";
          } else {
            slide.classList.add("is-hidden");
            slide.style.left = offset < 0 ? "-200%" : "200%";
          }
        } else {
          if (offset === 0) {
            slide.classList.add("is-center");
            slide.style.left = "15%";
            slide.style.top = "-2.5%";
            slide.style.width = "70%";
            slide.style.height = "105%";
            slide.style.opacity = "1";
            slide.style.pointerEvents = "auto";
            slide.style.zIndex = "3";
          } else if (offset === -1) {
            slide.classList.add("is-prev");
            slide.style.left = "0";
            slide.style.width = "15%";
            slide.style.opacity = "0.5";
            slide.style.pointerEvents = "auto";
            slide.style.zIndex = "2";
          } else if (offset === 1) {
            slide.classList.add("is-next");
            slide.style.left = "85%";
            slide.style.width = "15%";
            slide.style.opacity = "0.5";
            slide.style.pointerEvents = "auto";
            slide.style.zIndex = "2";
          } else {
            slide.classList.add("is-hidden");
            slide.style.left = offset < 0 ? "-20%" : "120%";
          }
        }

        const content = slide.querySelector(".promo-slide-content");
        if (content) {
          content.style.opacity = offset === 0 ? "1" : "0";
        }

        slide.setAttribute("aria-hidden", offset === 0 ? "false" : "true");
        slide.tabIndex = offset === 0 ? 0 : -1;
      });

      syncDots();
    };

    const render = () => {
      window.cancelAnimationFrame(renderFrame);
      renderFrame = window.requestAnimationFrame(applySlideState);
    };

    const stopAutoplay = () => {
      if (autoplayId) {
        window.clearInterval(autoplayId);
        autoplayId = 0;
      }
    };

    const restartAutoplay = () => {
      stopAutoplay();
      if (slides.length <= 1) {
        return;
      }

      const intervalMs = Number.parseInt(window._vgHomeGalleryIntervalMs || "4500", 10) || 4500;
      autoplayId = window.setInterval(() => {
        if (isPaused) {
          return;
        }

        setCurrentIndex(currentIndex + 1, { restart: false });
      }, intervalMs);
    };

    const setCurrentIndex = (index, options = {}) => {
      currentIndex = normalizeIndex(index);
      render();

      if (options.restart !== false) {
        restartAutoplay();
      }
    };

    const goToDirection = (direction) => {
      setCurrentIndex(currentIndex + direction);
    };

    const handleSlideClick = (event) => {
      const slide = event.target.closest(".promo-slide-card");
      if (!slide) {
        return;
      }

      if (Date.now() < suppressClickUntil) {
        event.preventDefault();
        return;
      }

      const slideIndex = slides.indexOf(slide);
      if (slideIndex === -1) {
        return;
      }

      if (slideIndex === currentIndex) {
        const rect = slide.getBoundingClientRect();
        const pointerX = event.clientX;
        const edgeZone = Math.max(40, rect.width * 0.28);

        if (pointerX < rect.left + edgeZone) {
          event.preventDefault();
          goToDirection(-1);
          return;
        }

        if (pointerX > rect.right - edgeZone) {
          event.preventDefault();
          goToDirection(1);
          return;
        }
      }

      if (slideIndex !== currentIndex) {
        event.preventDefault();
        setCurrentIndex(slideIndex);
      }
    };

    slider.addEventListener("click", handleSlideClick);

    slider.addEventListener("keydown", (event) => {
      if (event.key === "ArrowLeft") {
        event.preventDefault();
        goToDirection(-1);
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        goToDirection(1);
      }
    });

    slider.addEventListener("mouseenter", () => {
      isPaused = true;
    });

    slider.addEventListener("mouseleave", () => {
      isPaused = false;
    });

    slider.addEventListener("touchstart", (event) => {
      const touch = event.changedTouches[0];
      if (!touch) {
        return;
      }

      isPaused = true;
      touchStartX = touch.clientX;
      touchStartY = touch.clientY;
      touchMoved = false;
    }, { passive: true });

    slider.addEventListener("touchmove", (event) => {
      const touch = event.changedTouches[0];
      if (!touch || touchStartX === null || touchStartY === null) {
        return;
      }

      const deltaX = touch.clientX - touchStartX;
      const deltaY = touch.clientY - touchStartY;
      if (Math.abs(deltaX) > 8 || Math.abs(deltaY) > 8) {
        touchMoved = true;
      }
    }, { passive: true });

    slider.addEventListener("touchend", (event) => {
      isPaused = false;

      if (touchStartX !== null && touchMoved) {
        const touch = event.changedTouches[0];
        const endX = touch ? touch.clientX : touchStartX;
        const deltaX = touchStartX - endX;

        if (Math.abs(deltaX) >= 40) {
          suppressClickUntil = Date.now() + 300;
          goToDirection(deltaX > 0 ? 1 : -1);
        }
      }

      touchStartX = null;
      touchStartY = null;
      touchMoved = false;
    });

    slider.addEventListener("touchcancel", () => {
      isPaused = false;
      touchStartX = null;
      touchStartY = null;
      touchMoved = false;
    });

    slider.addEventListener("focusin", () => {
      isPaused = true;
    });

    slider.addEventListener("focusout", () => {
      isPaused = false;
    });

    if (mobileQuery.addEventListener) {
      mobileQuery.addEventListener("change", render);
    } else if (mobileQuery.addListener) {
      mobileQuery.addListener(render);
    }

    window.addEventListener("resize", render, { passive: true });
    window.addEventListener("orientationchange", render);

    setCurrentIndex(0, { restart: false });
    restartAutoplay();
  })();
</script>
SCRIPT,
  <<<'SCRIPT'
<script>
  (() => {
    const missionShell = document.getElementById("daily-missions-shell");
    if (!missionShell) {
      return;
    }

    const missionApiUrl = window.__TVG_API_DAILY_MISSIONS || missionShell.dataset.apiUrl || "";
    if (!missionApiUrl) {
      return;
    }

    const missionModal = document.getElementById("daily-mission-modal");
    const missionModalStage = document.getElementById("daily-mission-modal-stage");
    const missionModalTitle = document.getElementById("daily-mission-modal-title");
    const missionModalPrizeType = document.getElementById("daily-mission-modal-prize-type");
    const missionModalPrizeLabel = document.getElementById("daily-mission-modal-prize-label");
    const missionModalPrizeDesc = document.getElementById("daily-mission-modal-prize-desc");
    const missionModalContinue = document.getElementById("daily-mission-modal-continue");
    const missionChest3d = document.getElementById("daily-mission-chest-3d");
    const missionChestShell = document.getElementById("daily-mission-chest-shell");
    const missionChestTitle = document.getElementById("daily-mission-chest-title");
    const missionChestSubtitle = document.getElementById("daily-mission-chest-subtitle");
    const missionChestLabel = document.getElementById("daily-mission-chest-label");
    const missionStateTitle = document.getElementById("daily-mission-state-title");
    const missionStateDescription = document.getElementById("daily-mission-state-description");
    const missionProgressBar = document.getElementById("daily-mission-progress-bar");
    const missionPillProgress = document.getElementById("daily-mission-pill-progress");
    const missionSummaryProgress = document.getElementById("daily-mission-summary-progress");
    const missionSummaryCompleted = document.getElementById("daily-mission-summary-completed");
    const missionSummaryStreak = document.getElementById("daily-mission-summary-streak");
    const missionSummaryLevel = document.getElementById("daily-mission-summary-level");
    const missionCompletedBadge = document.getElementById("daily-mission-completed-badge");
    const missionStreakText = document.getElementById("daily-mission-streak-text");
    const missionImmunityText = document.getElementById("daily-mission-immunity-text");
    const missionTaskCards = Array.from(missionShell.querySelectorAll(".daily-mission-task-card"));
    const missionCardMap = new Map();
    const missionCountdowns = new Map();
    const levelLabels = {
      basic: "Básico",
      intermediate: "Intermedio",
      legendary: "Legendario",
    };
    const prizeLabels = {
      winpoints: "Win Points",
      coupon: "Cupón",
      immunity: "Escudo",
      streaming_ticket: "Ticket de streaming",
    };

    missionTaskCards.forEach((card) => {
      if (card.dataset.missionKey) {
        missionCardMap.set(card.dataset.missionKey, card);
      }
    });

    const safeNumber = (value, fallback = 0) => {
      const parsed = Number.parseInt(value, 10);
      return Number.isNaN(parsed) ? fallback : parsed;
    };

    const formatNumber = (value) => safeNumber(value, 0).toLocaleString("es-ES");

    const setText = (element, value) => {
      if (element) {
        element.textContent = value;
      }
    };

    const parseJson = (value, fallback) => {
      if (!value) {
        return fallback;
      }

      try {
        return JSON.parse(value);
      } catch (error) {
        return fallback;
      }
    };

    const getLevelLabel = (levelKey) => levelLabels[levelKey] || (levelKey ? levelKey : "Básico");

    const getPrizeLabel = (prizeType) => prizeLabels[prizeType] || (prizeType ? prizeType : "Premio");

    const openTargetUrl = (targetUrl) => {
      if (!targetUrl || targetUrl === "#") {
        return;
      }

      try {
        const popup = window.open(targetUrl, "_blank", "noopener,noreferrer");
        if (popup) {
          popup.opener = null;
        }
      } catch (error) {
      }
    };

    const closeMissionModal = () => {
      if (!missionModal) {
        return;
      }

      missionModal.classList.add("is-hidden");
      missionModal.setAttribute("aria-hidden", "true");
      if (missionChest3d) {
        missionChest3d.classList.remove("is-spinning");
        missionChest3d.classList.remove("is-open");
      }
    };

    const showMissionModal = (result, payload) => {
      if (!missionModal || !missionModalStage || !missionChest3d) {
        return;
      }

      const prize = result && result.prize && typeof result.prize === "object" ? result.prize : {};
      const reward = result && result.reward && typeof result.reward === "object" ? result.reward : {};
      const levelKey = (result && result.level_key) || (payload && payload.chest_level) || missionShell.dataset.level || "basic";
      const prizeType = (prize.prize_type || reward.prize_type || "winpoints").toString();
      const prizeLabel = (prize.prize_label || reward.prize_label || "Premio desbloqueado").toString();
      const chestLabel = getLevelLabel(levelKey);
      let prizeDescription = result && result.message ? result.message : "Tu recompensa ya quedó registrada.";

      if (prizeType === "winpoints") {
        const amount = safeNumber(prize.points_amount || reward.points_amount || 0, 0);
        prizeDescription = amount > 0 ? "Se acreditaron " + formatNumber(amount) + " Win Points en tu saldo." : prizeDescription;
      } else if (prizeType === "coupon") {
        const couponCode = (reward.coupon_code || prize.coupon_code || "").toString();
        prizeDescription = couponCode !== "" ? "Cupón generado: " + couponCode + ". Revisa tu historial para usarlo." : prizeDescription;
      } else if (prizeType === "immunity") {
        const immunityDays = safeNumber(prize.immunity_days || reward.immunity_days || 1, 1);
        prizeDescription = "Recibiste " + formatNumber(immunityDays) + " día(s) de inmunidad para conservar tu racha.";
      } else if (prizeType === "streaming_ticket") {
        prizeDescription = "Se asignó un ticket de streaming como premio especial de hoy.";
      }

      setText(missionModalTitle, result && result.message ? result.message : "Premio desbloqueado");
      setText(missionModalPrizeType, chestLabel + " · " + getPrizeLabel(prizeType));
      setText(missionModalPrizeLabel, prizeLabel);
      setText(missionModalPrizeDesc, prizeDescription);

      missionModalStage.dataset.level = levelKey;
      missionChest3d.classList.remove("is-open");
      missionChest3d.classList.add("is-spinning");
      missionModal.classList.remove("is-hidden");
      missionModal.setAttribute("aria-hidden", "false");

      window.setTimeout(() => {
        missionChest3d.classList.add("is-open");
      }, 220);
      window.setTimeout(() => {
        missionChest3d.classList.remove("is-spinning");
      }, 1350);
    };

    const syncTaskCard = (card, task) => {
      if (!card || !task) {
        return;
      }

      const completed = Boolean(task.completed_today);
      const active = task.active !== false && task.active !== 0 && task.active !== "0";
      const taskType = (task.task_type || card.dataset.missionType || "").toString();

      card.dataset.completed = completed ? "1" : "0";
      card.dataset.disabled = active ? "0" : "1";
      card.classList.toggle("is-done", completed);
      card.classList.toggle("is-disabled", !active);

      const state = card.querySelector(".daily-mission-task-state");
      if (state) {
        state.textContent = completed ? "Completada" : active ? "Activa" : "Inactiva";
        state.classList.toggle("is-done", completed);
      }

      const actionElements = Array.from(card.querySelectorAll(".daily-mission-mini-action"));
      actionElements.forEach((actionElement) => {
        if (actionElement.tagName === "BUTTON") {
          actionElement.disabled = completed || !active || actionElement.dataset.targetUrl === "";
        } else if (actionElement.tagName === "A") {
          if (completed || !active) {
            actionElement.classList.add("is-disabled");
            actionElement.setAttribute("aria-disabled", "true");
            actionElement.tabIndex = -1;
          } else {
            actionElement.classList.remove("is-disabled");
            actionElement.removeAttribute("aria-disabled");
            actionElement.tabIndex = 0;
          }
        }

        if (completed) {
          actionElement.classList.add("is-primary");
        }
      });

      if (taskType === "login") {
        const loginAction = card.querySelector(".daily-mission-mini-action");
        if (loginAction) {
          loginAction.textContent = completed ? "Bono reclamado" : "Se activa al iniciar sesión";
        }
      }
    };

    const applyMissionPayload = (payload) => {
      if (!payload || typeof payload !== "object") {
        return;
      }

      const day = payload.day && typeof payload.day === "object" ? payload.day : {};
      const state = payload.state && typeof payload.state === "object" ? payload.state : {};
      const tasks = Array.isArray(payload.tasks) ? payload.tasks : [];
      const taskMap = new Map();
      const progressPercent = Math.max(0, Math.min(100, safeNumber(payload.progress_percent || 0, 0)));
      const totalTasks = safeNumber(day.required_tasks_count || missionShell.dataset.taskCount || tasks.length || 4, 4) || 4;
      const completedTasks = safeNumber(day.completed_tasks_count || missionShell.dataset.completedCount || 0, 0);
      const remainingTasks = Math.max(0, totalTasks - completedTasks);
      const currentStreak = safeNumber(day.current_streak_days || state.current_streak_days || missionShell.dataset.streak || 0, 0);
      const immunityBalance = safeNumber(state.immunity_balance || missionShell.dataset.immunity || 0, 0);
      const levelKey = (payload.chest_level || day.chest_level || missionShell.dataset.level || "basic").toString();
      const canOpenChest = Boolean(payload.can_open_chest || (!day.chest_claimed_at && day.is_completed));
      const isClaimed = Boolean(day.chest_claimed_at);
      const rewardLabel = canOpenChest ? "Cofre listo para abrir" : isClaimed ? "Cofre reclamado" : "Completa las tareas para abrirlo";

      tasks.forEach((task) => {
        if (task && task.mission_key) {
          taskMap.set(task.mission_key, task);
        }
      });

      missionShell.dataset.progress = String(progressPercent);
      missionShell.dataset.canOpenChest = canOpenChest ? "1" : "0";
      missionShell.dataset.level = levelKey;
      missionShell.dataset.completedCount = String(completedTasks);
      missionShell.dataset.remainingCount = String(remainingTasks);
      missionShell.dataset.streak = String(currentStreak);
      missionShell.dataset.immunity = String(immunityBalance);

      if (missionProgressBar) {
        missionProgressBar.style.width = progressPercent + "%";
        missionProgressBar.setAttribute("aria-valuenow", String(progressPercent));
      }

      setText(missionPillProgress, progressPercent + "% completado");
      setText(missionSummaryProgress, progressPercent + "% completado");
      setText(missionSummaryCompleted, formatNumber(completedTasks));
      setText(missionSummaryStreak, formatNumber(currentStreak));
      setText(missionSummaryLevel, getLevelLabel(levelKey));
      setText(missionCompletedBadge, formatNumber(completedTasks) + "/" + formatNumber(totalTasks));
      setText(missionStreakText, formatNumber(currentStreak));
      setText(missionImmunityText, formatNumber(immunityBalance));
      setText(missionChestTitle, getLevelLabel(levelKey));
      setText(missionChestLabel, rewardLabel);
      setText(missionStateTitle, rewardLabel);
      setText(missionChestSubtitle, canOpenChest ? "Pulsa el cofre para reclamar el premio de hoy." : isClaimed ? "Cofre reclamado. Vuelve mañana para un nuevo premio." : "Completa las 4 tareas para desbloquearlo.");
      setText(missionStateDescription, canOpenChest ? "El cofre está listo para abrirse." : isClaimed ? "El premio del día ya fue reclamado." : "El cofre se abre cuando todas las tareas del día están completas.");

      if (missionChestShell) {
        missionChestShell.classList.toggle("is-open", canOpenChest);
        missionChestShell.toggleAttribute("disabled", !canOpenChest);
        missionChestShell.setAttribute("aria-disabled", canOpenChest ? "false" : "true");
      }

      missionTaskCards.forEach((card) => {
        const task = taskMap.get(card.dataset.missionKey || "");
        if (task) {
          syncTaskCard(card, task);
        }
      });
    };

    const requestMissionUpdate = async (action, body) => {
      const response = await fetch(missionApiUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: new URLSearchParams(Object.assign({ action: action }, body || {})).toString(),
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) {
        throw new Error(data.message || "No se pudo actualizar la misión.");
      }

      return data;
    };

    const clearCountdown = (taskKey, restoreLabel) => {
      const countdown = missionCountdowns.get(taskKey);
      if (!countdown) {
        return;
      }

      window.clearInterval(countdown.timerId);
      missionCountdowns.delete(taskKey);

      if (restoreLabel && countdown.button && countdown.button.dataset.originalLabel) {
        countdown.button.textContent = countdown.button.dataset.originalLabel;
      }

      if (countdown.button && countdown.button.tagName === "BUTTON") {
        countdown.button.disabled = false;
      }
    };

    const startCountdown = (button, taskKey, taskType, timerSeconds, targetUrl, extraBody) => {
      if (!button || !taskKey || missionCountdowns.has(taskKey)) {
        return;
      }

      const originalLabel = button.dataset.originalLabel || button.textContent.trim() || "Procesando";
      const duration = Math.max(1, safeNumber(timerSeconds, 1));
      let remaining = duration;

      button.dataset.originalLabel = originalLabel;
      if (button.tagName === "BUTTON") {
        button.disabled = true;
      }

      if (targetUrl && taskType !== "purchase") {
        openTargetUrl(targetUrl);
      }

      const updateLabel = () => {
        button.textContent = originalLabel + " (" + remaining + "s)";
        if (remaining <= 0) {
          clearInterval(timerId);
          missionCountdowns.delete(taskKey);
          button.textContent = originalLabel;
          if (button.tagName === "BUTTON") {
            button.disabled = false;
          }

          requestMissionUpdate("complete_task", Object.assign({ mission_key: taskKey }, extraBody || {}))
            .then((data) => {
              applyMissionPayload(data.payload || {});
            })
            .catch((error) => {
              setText(missionStateDescription, error.message || "No se pudo completar la misión.");
              clearCountdown(taskKey, true);
            });
          return;
        }

        remaining -= 1;
      };

      updateLabel();
      const timerId = window.setInterval(updateLabel, 1000);
      missionCountdowns.set(taskKey, {
        timerId: timerId,
        button: button,
      });
    };

    const refreshMissionStatus = () => {
      requestMissionUpdate("status", {})
        .then((data) => {
          applyMissionPayload(data.payload || {});
        })
        .catch(() => {
          applyMissionPayload({
            day: {
              required_tasks_count: safeNumber(missionShell.dataset.taskCount || 4, 4),
              completed_tasks_count: safeNumber(missionShell.dataset.completedCount || 0, 0),
              current_streak_days: safeNumber(missionShell.dataset.streak || 0, 0),
              chest_level: missionShell.dataset.level || "basic",
            },
            state: {
              current_streak_days: safeNumber(missionShell.dataset.streak || 0, 0),
              immunity_balance: safeNumber(missionShell.dataset.immunity || 0, 0),
            },
            progress_percent: safeNumber(missionShell.dataset.progress || 0, 0),
            can_open_chest: missionShell.dataset.canOpenChest === "1",
            chest_level: missionShell.dataset.level || "basic",
            tasks: [],
          });
        });
    };

    missionShell.addEventListener("click", (event) => {
      const actionElement = event.target.closest(".daily-mission-mini-action, [data-daily-mission-chest]");
      if (!actionElement || !missionShell.contains(actionElement)) {
        return;
      }

      if (actionElement.hasAttribute("data-daily-mission-chest")) {
        event.preventDefault();
        if (!missionChestShell || missionChestShell.disabled || missionShell.dataset.canOpenChest !== "1") {
          return;
        }

        missionChestShell.classList.add("is-spinning");
        missionChestShell.disabled = true;

        requestMissionUpdate("open_chest", {})
          .then((data) => {
            applyMissionPayload(data.payload || {});
            showMissionModal(data.result || {}, data.payload || {});
          })
          .catch((error) => {
            setText(missionStateDescription, error.message || "No se pudo abrir el cofre.");
          })
          .finally(() => {
            window.setTimeout(() => {
              missionChestShell.classList.remove("is-spinning");
              missionChestShell.disabled = missionShell.dataset.canOpenChest !== "1";
            }, 1250);
          });
        return;
      }

      const card = actionElement.closest(".daily-mission-task-card");
      if (!card) {
        return;
      }

      const taskKey = card.dataset.missionKey || actionElement.dataset.taskKey || "";
      const taskType = card.dataset.missionType || "";
      const taskTimer = safeNumber(actionElement.dataset.taskTimer || card.dataset.missionTimer || (taskType === "share" ? missionShell.dataset.shareTimer : missionShell.dataset.exploreTimer), 1);
      const targetUrl = actionElement.dataset.targetUrl || card.dataset.targetUrl || "";
      const isCompleted = card.dataset.completed === "1";
      const isDisabled = card.dataset.disabled === "1";

      if (isCompleted || isDisabled) {
        if (actionElement.tagName === "A") {
          event.preventDefault();
        }
        return;
      }

      if (taskType === "purchase" || taskType === "login") {
        return;
      }

      event.preventDefault();
      startCountdown(actionElement, taskKey, taskType, taskTimer, targetUrl, {
        source: "index_missions",
        mission_type: taskType,
      });
    });

    if (missionModal) {
      missionModal.addEventListener("click", (event) => {
        const closeTrigger = event.target.closest("[data-daily-mission-modal-close]");
        if (closeTrigger) {
          event.preventDefault();
          closeMissionModal();
        }
      });
    }

    if (missionModalContinue) {
      missionModalContinue.addEventListener("click", closeMissionModal);
    }

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && missionModal && !missionModal.classList.contains("is-hidden")) {
        closeMissionModal();
      }
    });

    refreshMissionStatus();
  })();
</script>
SCRIPT
];
$homeGalleryIntervalSeconds = max(1, min(60, (int) store_config_get('home_gallery_interval_seconds', '6')));
$homeGalleryIntervalMs = $homeGalleryIntervalSeconds * 1000;
array_unshift($pageScripts, '<script>window._vgHomeGalleryIntervalMs = ' . $homeGalleryIntervalMs . ';</script>');
include __DIR__ . "/includes/footer.php";
?>
