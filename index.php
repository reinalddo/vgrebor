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
require_once __DIR__ . "/includes/package_features.php";
require_once __DIR__ . "/includes/game_sticker.php";
currency_ensure_schema();
game_sticker_ensure_schema($mysqli);
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
require_once __DIR__ . '/includes/roulette.php';
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

// GG Drops — migración de columnas y carga de paquetes destacados
$ggDropsPackages = [];
$_ggCheckDest = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'destacado'");
if ($_ggCheckDest instanceof mysqli_result && $_ggCheckDest->num_rows === 0) {
  $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN destacado TINYINT(1) DEFAULT 0 NULL");
}
$_ggCheckDesc = $mysqli->query("SHOW COLUMNS FROM juego_paquetes LIKE 'descuento_destacado'");
if ($_ggCheckDesc instanceof mysqli_result && $_ggCheckDesc->num_rows === 0) {
  $mysqli->query("ALTER TABLE juego_paquetes ADD COLUMN descuento_destacado TINYINT UNSIGNED DEFAULT 0 NULL AFTER destacado");
}
$_ggDropsRes = $mysqli->query(
  "SELECT jp.id, jp.nombre, jp.precio, jp.imagen_icono, jp.juego_id,
          COALESCE(jp.descuento_destacado, 0) AS descuento_destacado,
          j.nombre AS juego_nombre, j.slug AS juego_slug, j.imagen AS juego_imagen,
          j.id AS game_id, j.moneda_fija_id
   FROM juego_paquetes jp
   INNER JOIN juegos j ON j.id = jp.juego_id
   WHERE jp.destacado = 1 AND COALESCE(jp.activo, 1) = 1 AND COALESCE(j.activo, 1) = 1
   ORDER BY jp.orden ASC, jp.id ASC
   LIMIT 20"
);
if ($_ggDropsRes instanceof mysqli_result) {
  while ($dpkg = $_ggDropsRes->fetch_assoc()) {
    $ggDropsPackages[] = $dpkg;
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

// Destacada categories: tabbed section that replaces "Más Juegos"
$todosCategory       = function_exists('game_category_get_or_create_todos') ? game_category_get_or_create_todos($mysqli) : null;
$destacadaCategories = function_exists('game_category_list_destacadas') ? game_category_list_destacadas($mysqli) : [];
$catGameIdMap = [];
if ($destacadaCategories !== []) {
  $destCatIds = implode(',', array_map(fn($c) => (int)$c['id'], $destacadaCategories));
  $destAssignRes = $mysqli->query("SELECT categoria_id, juego_id FROM juego_categoria_asignada WHERE categoria_id IN ($destCatIds)");
  if ($destAssignRes instanceof mysqli_result) {
    while ($ar = $destAssignRes->fetch_assoc()) {
      $catGameIdMap[(int)$ar['categoria_id']][] = (int)$ar['juego_id'];
    }
  }
  // Quitar categorías sin juegos asignados
  $destacadaCategories = array_values(array_filter(
    $destacadaCategories,
    fn($cat) => !empty($catGameIdMap[$cat['id']])
  ));
}
$todosActivo      = $todosCategory !== null && !empty($todosCategory['activa']);
$showDestSection  = $todosActivo || $destacadaCategories !== [];
$destDefaultCat   = $todosActivo ? 'all' : (!empty($destacadaCategories) ? (string)(int)$destacadaCategories[0]['id'] : 'all');
$gameCardsForJs = array_values(array_map(function ($gc) {
  $sticker = game_sticker_from_row($gc);
  return [
    'id'                 => (int)$gc['id'],
    'nombre'             => (string)($gc['nombre'] ?? ''),
    'url'                => app_path(game_route_path($gc)),
    'imagen_url'         => app_path('/' . ltrim((string)($gc['imagen'] ?? ''), '/')),
    'imagen_paquete_url' => !empty($gc['imagen_paquete']) ? app_path('/' . ltrim((string)$gc['imagen_paquete'], '/')) : '',
    'popular'            => !empty($gc['popular']),
    'min_price_label'    => (string)($gc['min_price_label'] ?? ''),
    'sticker_html'       => game_sticker_render($sticker),
  ];
}, $gameCards));

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

$rouletteUserId  = !empty($authUser['id']) ? (int) $authUser['id'] : 0;
$roulettePayload = roulette_public_payload($mysqli, $rouletteUserId);
$rouletteConfig  = $roulettePayload['config'];
$rouletteSections = $roulettePayload['sections'];
$rouletteBalance  = (int) ($roulettePayload['balance'] ?? 0);
$rouletteEnabled  = !empty($rouletteConfig['enabled']);
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
        @media (max-width: 575.98px) {
          .store-game-card { padding: 0.3rem !important; border-radius: 0.65rem !important; }
          .store-game-card .store-game-title { font-size: 0.72rem !important; margin-bottom: 0.15rem !important; min-height: 0; }
          .store-game-card .store-game-price-prefix { font-size: 0.65rem !important; }
          .store-game-card .store-game-price-prefix img { height: 14px !important; width: 14px !important; }
          .store-game-card .mt-2 { margin-top: 0.3rem !important; }
        }
        .game-sticker {
          position: absolute;
          top: -0.5rem;
          right: -0.45rem;
          z-index: 5;
          display: flex;
          align-items: center;
          gap: 0.25rem;
          padding: 0.22rem 0.5rem 0.22rem 0.35rem;
          border-radius: 0.5rem 0.35rem 0.35rem 0.5rem;
          background: var(--sticker-bg, #7b2fff);
          color: #fff;
          font-size: 0.72rem;
          font-weight: 700;
          line-height: 1.2;
          box-shadow: 0 2px 8px rgba(0,0,0,0.45), inset 0 0 0 1.5px rgba(255,255,255,0.1);
          pointer-events: none;
          overflow: hidden;
          white-space: nowrap;
          max-width: 90%;
        }
        .game-sticker::after {
          content: '';
          position: absolute;
          inset: 0;
          background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,0.22) 50%, transparent 70%);
          background-size: 200% 100%;
          animation: game-sticker-shimmer 2.4s ease-in-out infinite;
        }
        @keyframes game-sticker-shimmer {
          0%   { background-position: 200% 0; }
          100% { background-position: -200% 0; }
        }
        .game-sticker-text, .game-sticker-icon, .game-sticker-img {
          position: relative;
          z-index: 1;
        }
        .game-sticker-icon { font-style: normal; line-height: 1; }
        .game-sticker-img  { width: 1.1rem; height: 1.1rem; object-fit: contain; }
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
          bottom: 0.25rem;
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
          height: clamp(168px, 21vw, 322px);
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
          display: none;
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
            height: min(46vw, 294px);
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
        /* ═══════════════════════════════════════════════════════
           3D CHEST — pure CSS perspective chest
           JS adds/removes: is-open · is-spinning · girando · abierto
           on .daily-mission-chest-3d
           ═══════════════════════════════════════════════════════ */

        /* Color vars — locked/gray default (no glow, no animation) */
        .daily-mission-chest-3d {
          --dm-front-lg:    linear-gradient(160deg,#8892a0 0%,#5a6475 40%,#3a4150 70%,#252d38 100%);
          --dm-side-dark:   #252d38;
          --dm-side-light:  #4a5568;
          --dm-top-side:    #363f4e;
          --dm-top-light:   #6b7a8a;
          --dm-outline:     #7a8796;
          --dm-lock-bg:     linear-gradient(180deg,#b8bec8,#787f89);
          --dm-lock-border: #9ca3af;
          --dm-glow-clr:    rgba(0,0,0,0);
          --dm-led:         rgba(0,0,0,0);
          --dm-crys:        #9ca3af;
          --dm-diag:        rgba(0,0,0,0);
        }
        /* X pattern only shows on available (colored) chests */
        .daily-mission-chest-3d.is-open { --dm-diag: rgba(255,255,255,0.26); }

        /* Scene + cofre */
        .dm-scene {
          width: 200px; height: 150px;
          perspective: 900px;
          perspective-origin: 50% 35%;
          margin: 16px auto 8px;
          position: relative;
        }
        .dm-cofre {
          position: relative;
          width: 200px; height: 150px;
          transform-style: preserve-3d;
          transform: rotateX(-15deg) rotateY(-35deg) scale(1);
          transition: transform 0.5s ease;
        }

        /* Base + tapa containers */
        .dm-base, .dm-tapa {
          position: absolute;
          top: 0; left: 0;
          width: 100%; height: 100%;
          transform-style: preserve-3d;
        }
        .dm-tapa {
          transform-origin: 50% 50px -75px;
          transition: transform 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        /* Generic face */
        .dm-cara {
          position: absolute;
          box-sizing: border-box;
          border: 2px solid var(--dm-outline, #7a8796);
          box-shadow: inset 0 0 28px rgba(0,0,0,0.82), inset 0 0 5px rgba(255,255,255,0.04);
          transition: box-shadow 0.45s ease;
        }
        /* Glowing border when chest is available */
        .daily-mission-chest-3d.is-open .dm-cara {
          box-shadow:
            inset 0 0 28px rgba(0,0,0,0.78),
            inset 0 0 5px rgba(255,255,255,0.05),
            0 0 12px 4px var(--dm-glow-clr),
            0 0 26px 8px var(--dm-led);
        }
        /* Geometric/faceted face backgrounds — diamond X pattern (only on available chests) */
        .dm-madera {
          background:
            linear-gradient(135deg, transparent 46%, var(--dm-diag) 46%, var(--dm-diag) 48%, transparent 48%),
            linear-gradient(45deg,  transparent 46%, var(--dm-diag) 46%, var(--dm-diag) 48%, transparent 48%),
            linear-gradient(135deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0.15) 46%, transparent 54%),
            linear-gradient(315deg, rgba(0,0,0,0.28) 0%, rgba(0,0,0,0.28) 46%, transparent 54%),
            radial-gradient(ellipse 22% 22% at 50% 50%, rgba(255,255,255,0.1) 0%, transparent 100%),
            var(--dm-front-lg);
        }
        .dm-oscura {
          background:
            linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.1) 46%, transparent 54%),
            linear-gradient(315deg, rgba(0,0,0,0.22) 0%, rgba(0,0,0,0.22) 46%, transparent 54%),
            var(--dm-side-dark, #252d38);
        }
        .dm-clara  {
          background:
            linear-gradient(135deg, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0.18) 46%, transparent 54%),
            linear-gradient(315deg, rgba(0,0,0,0.18) 0%, rgba(0,0,0,0.18) 46%, transparent 54%),
            var(--dm-side-light, #4a5568);
        }

        /* Base faces */
        .dm-frente { width:200px; height:100px; top:50px; left:0;    transform:translateZ(75px); }
        .dm-atras  { width:200px; height:100px; top:50px; left:0;    transform:rotateY(180deg) translateZ(75px); }
        .dm-der    { width:150px; height:100px; top:50px; left:25px; transform:rotateY(90deg) translateZ(100px); }
        .dm-izq    { width:150px; height:100px; top:50px; left:25px; transform:rotateY(-90deg) translateZ(100px); }
        .dm-fondo  { width:200px; height:150px; top:50px; left:0;    transform:rotateX(-90deg) translateZ(50px);
                     background:var(--dm-side-dark,#374151) !important; border:none !important; box-shadow:none !important; }

        /* ── FLAT SQUARE LID faces ───────────────────────────── */
        .dm-tapa-frente { width:200px; height:50px; top:0; left:0;    transform:translateZ(75px); }
        .dm-tapa-atras  { width:200px; height:50px; top:0; left:0;    transform:rotateY(180deg) translateZ(75px); }
        .dm-tapa-der    { width:150px; height:50px; top:0; left:25px; transform:rotateY(90deg)  translateZ(100px); }
        .dm-tapa-izq    { width:150px; height:50px; top:0; left:25px; transform:rotateY(-90deg) translateZ(100px); }

        /* Flat horizontal lid top — spans full box XZ plane at y=0 */
        .dm-tapa-top {
          position:absolute;
          width:200px; height:150px; top:0; left:0;
          transform-origin:50% 0;
          transform:rotateX(-90deg) translateY(-75px);
          background:
            linear-gradient(135deg, transparent 46%, var(--dm-diag) 46%, var(--dm-diag) 48%, transparent 48%),
            linear-gradient(45deg,  transparent 46%, var(--dm-diag) 46%, var(--dm-diag) 48%, transparent 48%),
            linear-gradient(90deg,  transparent 46%, var(--dm-diag) 46%, var(--dm-diag) 48%, transparent 48%),
            linear-gradient(0deg,   transparent 46%, var(--dm-diag) 46%, var(--dm-diag) 48%, transparent 48%),
            linear-gradient(135deg, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0.18) 44%, transparent 56%),
            linear-gradient(315deg, rgba(0,0,0,0.28) 0%, rgba(0,0,0,0.28) 44%, transparent 56%),
            var(--dm-top-side,#363f4e) !important;
          border:2px solid var(--dm-outline,#7a8796) !important;
          box-shadow:inset 0 0 22px rgba(0,0,0,0.7), inset 0 0 5px rgba(255,255,255,0.03);
          transition:box-shadow 0.45s ease;
        }
        .daily-mission-chest-3d.is-open .dm-tapa-top {
          box-shadow:
            inset 0 0 22px rgba(0,0,0,0.65),
            inset 0 0 4px rgba(255,255,255,0.04),
            0 0 14px 4px var(--dm-glow-clr),
            0 0 28px 10px var(--dm-led) !important;
        }

        /* Corner studs — diamond accents at lid front corners */
        .dm-stud {
          position:absolute;
          width:14px; height:14px;
          background:linear-gradient(135deg, var(--dm-top-light,#a0aec0) 0%, var(--dm-outline,#7a8796) 100%);
          border:1.5px solid rgba(255,255,255,0.45);
          clip-path:polygon(50% 0%,100% 50%,50% 100%,0% 50%);
          box-shadow:0 0 10px var(--dm-glow-clr,transparent);
          z-index:6;
          transition:box-shadow 0.4s;
        }
        .daily-mission-chest-3d.is-open .dm-stud {
          box-shadow:0 0 18px 4px var(--dm-glow-clr);
        }
        .dm-stud-fl { top:-7px; left:-7px;   transform:translateZ(80px); }
        .dm-stud-fr { top:-7px; left:193px;  transform:translateZ(80px); }

        /* Clasp — flat rectangular lock at lid-body seam */
        .dm-cerradura {
          position:absolute;
          width:32px; height:14px;
          background:var(--dm-lock-bg,linear-gradient(180deg,#b8bec8,#787f89));
          border:2px solid var(--dm-lock-border,#9ca3af);
          border-radius:3px;
          top:43px; left:84px;
          transform:translateZ(78px);
          box-shadow:0 2px 8px rgba(0,0,0,0.55), 0 0 0px var(--dm-glow-clr);
          z-index:5;
          transition:box-shadow 0.4s;
        }
        .dm-cerradura::after {
          content:'';
          position:absolute;
          top:50%; left:50%;
          width:10px; height:6px;
          background:rgba(0,0,0,0.4);
          border-radius:2px;
          transform:translate(-50%,-50%);
        }
        .daily-mission-chest-3d.is-open .dm-cerradura {
          box-shadow:0 2px 8px rgba(0,0,0,0.55), 0 0 14px 4px var(--dm-glow-clr);
        }

        /* LED strip at TOP edge of lid front (rim between top and front) */
        .dm-tapa-frente::before {
          content:'';
          position:absolute;
          top:-1px; left:3px; right:3px; height:3px;
          background:var(--dm-led,transparent);
          box-shadow:0 0 12px 6px var(--dm-glow-clr,transparent);
          border-radius:2px 2px 0 0;
          pointer-events:none;
          opacity:0; transition:opacity 0.45s;
        }
        .daily-mission-chest-3d.is-open .dm-tapa-frente::before { opacity:1; }

        /* LED strip at BOTTOM edge of lid front (seam) */
        .dm-tapa-frente::after {
          content:'';
          position:absolute;
          bottom:-1px; left:3px; right:3px; height:3px;
          background:var(--dm-led,transparent);
          box-shadow:0 0 12px 6px var(--dm-glow-clr,transparent);
          border-radius:0 0 2px 2px;
          pointer-events:none;
          opacity:0; transition:opacity 0.45s;
        }
        .daily-mission-chest-3d.is-open .dm-tapa-frente::after { opacity:1; }

        /* Vertical LED on lid sides */
        .dm-tapa-der::after, .dm-tapa-izq::after {
          content:'';
          position:absolute;
          top:0; bottom:0; width:3px;
          background:linear-gradient(180deg,var(--dm-led,transparent) 0%,var(--dm-led,transparent) 100%);
          opacity:0; transition:opacity 0.45s;
          pointer-events:none;
        }
        .dm-tapa-der::after { right:0; }
        .dm-tapa-izq::after { left:0; }
        .daily-mission-chest-3d.is-open .dm-tapa-der::after,
        .daily-mission-chest-3d.is-open .dm-tapa-izq::after { opacity:0.75; }


        /* Glow ring — available state */
        .dm-glow-ring {
          position:absolute; inset:-20%;
          border-radius:50%; opacity:0;
          pointer-events:none; transition:opacity .5s ease;
          background:radial-gradient(circle at center,transparent 44%,var(--dm-glow-clr,rgba(245,197,24,.12)) 70%,transparent 100%);
        }
        .daily-mission-chest-3d.is-open .dm-glow-ring {
          opacity:1;
          animation:dm-glow-pulse 1.8s ease-in-out infinite;
        }
        @keyframes dm-glow-pulse {
          0%,100% {
            box-shadow:0 0 30px 12px var(--dm-glow-clr,rgba(245,197,24,.3)),
                       0 0 60px 24px var(--dm-led,rgba(245,197,24,.12));
            opacity:0.65;
          }
          50% {
            box-shadow:0 0 52px 22px var(--dm-glow-clr,rgba(245,197,24,.55)),
                       0 0 100px 44px var(--dm-led,rgba(245,197,24,.22));
            opacity:1;
          }
        }

        /* Face icon — star symbol on chest front panel */
        .dm-face-icon {
          position:absolute; inset:0;
          display:flex; align-items:center; justify-content:center;
          padding-bottom:8px;
          font-size:22px; pointer-events:none; user-select:none;
          color:var(--dm-outline,#7a8796);
          opacity:0.22;
          filter:drop-shadow(0 0 3px rgba(255,255,255,0.12));
          transition:opacity 0.45s, filter 0.45s;
        }
        .daily-mission-chest-3d.is-open .dm-face-icon {
          opacity:0.8;
          filter:drop-shadow(0 0 14px var(--dm-glow-clr,rgba(245,197,24,.7)));
          animation:dm-icon-pulse 2.2s ease-in-out infinite;
        }
        @keyframes dm-icon-pulse {
          0%,100% { filter:drop-shadow(0 0 10px var(--dm-glow-clr)); opacity:0.65; }
          50%     { filter:drop-shadow(0 0 24px var(--dm-glow-clr)); opacity:1; }
        }

        /* Ground aura beneath chest — positioned at ~bottom of dm-scene */
        .dm-aura {
          position:absolute;
          width:160px; height:16px;
          left:50%; bottom:18%;
          transform:translateX(-50%);
          border-radius:50%;
          background:var(--dm-glow-clr,transparent);
          filter:blur(16px);
          opacity:0; pointer-events:none;
          transition:opacity 0.5s ease;
        }
        .daily-mission-chest-3d.is-open .dm-aura {
          opacity:1;
          animation:dm-aura-breathe 2.2s ease-in-out infinite;
        }
        @keyframes dm-aura-breathe {
          0%,100% { opacity:0.65; transform:translateX(-50%) scaleX(0.85); }
          50%     { opacity:1;    transform:translateX(-50%) scaleX(1.22); }
        }

        /* LED strip — top edge of body front (seam between lid and body) */
        .dm-frente::before {
          content:'';
          position:absolute;
          top:-1px; left:3px; right:3px;
          height:3px;
          background:var(--dm-led,transparent);
          box-shadow:0 0 14px 7px var(--dm-glow-clr,transparent);
          border-radius:2px 2px 0 0;
          pointer-events:none;
          opacity:0; transition:opacity 0.45s;
        }
        .daily-mission-chest-3d.is-open .dm-frente::before { opacity:1; }

        /* LED strip — bottom edge of body front face */
        .dm-frente::after {
          content:'';
          position:absolute;
          bottom:-1px; left:3px; right:3px;
          height:3px;
          background:var(--dm-led,transparent);
          box-shadow:0 0 12px 6px var(--dm-glow-clr,transparent);
          border-radius:0 0 2px 2px;
          pointer-events:none;
          opacity:0; transition:opacity 0.45s;
        }
        .daily-mission-chest-3d.is-open .dm-frente::after { opacity:1; }

        /* Vertical glow strips on side faces */
        .dm-der::after, .dm-izq::after {
          content:'';
          position:absolute;
          top:0; bottom:0;
          width:3px;
          background:linear-gradient(180deg,transparent 0%,var(--dm-led,transparent) 50%,transparent 100%);
          opacity:0; transition:opacity 0.45s;
          pointer-events:none;
        }
        .dm-der::after { right:0; }
        .dm-izq::after { left:0; }
        .daily-mission-chest-3d.is-open .dm-der::after,
        .daily-mission-chest-3d.is-open .dm-izq::after { opacity:0.75; }

        /* ── VIDEO CHEST ──────────────────────────────────────────── */
        .dm-video-wrap {
          position:relative;
          display:flex; align-items:center; justify-content:center;
          width:100%; height:100%;
          transition:opacity 0.5s ease;
        }
        .dm-video {
          width:240px; height:240px;
          object-fit:contain;
          display:block;
          border-radius:6px;
          transition:filter 0.45s ease;
        }
        /* Locked: grayscale */
        .daily-mission-chest-3d:not(.is-open) .dm-video {
          filter:grayscale(1) brightness(0.5);
        }
        /* Available: colored with gentle pulse */
        .daily-mission-chest-3d.is-open:not(.dm-vid-playing):not(.abierto) .dm-video {
          filter:none;
          animation:dm-vid-chest-pulse 2.8s ease-in-out infinite;
        }
        @keyframes dm-vid-chest-pulse {
          0%,100% { filter:brightness(1); }
          50% { filter:brightness(1.1) drop-shadow(0 0 16px var(--dm-glow-clr,rgba(245,197,24,.65))); }
        }
        /* Playing */
        .daily-mission-chest-3d.dm-vid-playing .dm-video { filter:none; animation:none; }
        /* After opening: fade video out so prize shows from behind */
        .daily-mission-chest-3d.abierto .dm-video-wrap { opacity:0; pointer-events:none; }

        /* Prize reveal (modal) */
        .daily-mission-modal-sorpresa {
          position:absolute; top:50%; left:50%;
          transform:translate(-50%,-50%) rotateY(35deg) rotateX(15deg) scale(0);
          text-align:center; opacity:0; pointer-events:none; white-space:nowrap;
          transition:all .7s cubic-bezier(.175,.885,.32,1.275);
          z-index:20;
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
        /* Spin animation class rules (keyframes defined below) */
        .daily-mission-chest-3d.is-spinning .dm-cofre,
        .daily-mission-chest-3d.girando     .dm-cofre {
          animation:girarExplosion 1.8s cubic-bezier(0.4,0.1,0.6,0.9) forwards !important;
        }
        /* Lid opens ONLY inside the modal (page chest keeps lid closed — available state) */
        #daily-mission-modal .daily-mission-chest-3d.is-open  .dm-tapa,
        #daily-mission-modal .daily-mission-chest-3d.abierto  .dm-tapa {
          transform:rotateX(120deg);
        }
        /* Prize reveal triggers in modal */
        #daily-mission-modal .daily-mission-chest-3d.is-open  .daily-mission-modal-sorpresa,
        #daily-mission-modal .daily-mission-chest-3d.abierto  .daily-mission-modal-sorpresa {
          transform:translate(-50%,-190px) rotateY(35deg) rotateX(15deg) scale(1);
          opacity:1;
        }
        /* Spin animation — more explosive */
        @keyframes girarExplosion {
          0%   { transform:rotateX(-15deg) rotateY(-35deg) scale(1); }
          15%  { transform:rotateX(-22deg) rotateY(50deg) scale(1.04); }
          40%  { transform:rotateX(-20deg) rotateY(325deg) scale(1.09); }
          68%  { transform:rotateX(-10deg) rotateY(780deg) scale(1.16); }
          88%  { transform:rotateX(-18deg) rotateY(1100deg) scale(1.24) translateY(-6px); }
          100% { transform:rotateX(-15deg) rotateY(1405deg) scale(1.3) translateY(-5px); }
        }
        /* Flash burst on chest open */
        @keyframes dm-flash-burst {
          0%   { opacity:0.95; transform:scale(0.3); }
          55%  { opacity:0.6;  transform:scale(1.7); }
          100% { opacity:0;    transform:scale(2.8); }
        }
        /* Spark particles (created by JS) */
        .daily-mission-modal-spark {
          position:absolute; top:50px; left:100px;
          font-size:30px; opacity:0; pointer-events:none; z-index:15;
        }
        @keyframes estallar {
          0%   { transform:rotateY(35deg) translate3d(0,0,0) scale(0.2); opacity:1; filter:brightness(3); }
          40%  { opacity:1; filter:brightness(2); }
          100% { transform:rotateY(35deg) translate3d(var(--x),var(--y),200px) scale(1.3) rotate(220deg); opacity:0; filter:brightness(1); }
        }
        /* Available state: floating rock */
        @keyframes dm-rock {
          0%,100% { transform:rotateX(-15deg) rotateY(-35deg) scale(1) translateY(0); }
          25%     { transform:rotateX(-19deg) rotateY(-27deg) scale(1.04) translateY(-7px); }
          60%     { transform:rotateX(-11deg) rotateY(-43deg) scale(1.04) translateY(-5px); }
          85%     { transform:rotateX(-16deg) rotateY(-38deg) scale(1.02) translateY(-2px); }
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

      <style>
        #daily-missions-shell {
          --daily-chest-front-light: #9aa0a8;
          --daily-chest-front-dark: #5a606a;
          --daily-chest-top-light: #c1c6cf;
          --daily-chest-top-dark: #7f8792;
          --daily-chest-outline: #d7dce4;
        }
        #daily-missions-shell .daily-mission-panel {
          overflow: hidden;
          border-radius: 1.55rem;
          border: 1px solid rgba(34, 211, 238, 0.18);
          background:
            radial-gradient(circle at top, rgba(34, 211, 238, 0.08), transparent 34%),
            linear-gradient(180deg, rgba(7, 12, 20, 0.98), rgba(4, 8, 14, 0.99));
          box-shadow: 0 18px 40px rgba(0, 0, 0, 0.28), 0 0 24px rgba(34, 211, 238, 0.06);
        }
        #daily-missions-shell .daily-mission-toggle {
          gap: 1rem;
          align-items: flex-start;
          padding: 1rem 1.1rem;
          border: 0;
          background: linear-gradient(180deg, rgba(8, 15, 24, 0.96), rgba(4, 9, 16, 0.98));
          color: #fff;
          box-shadow: inset 0 0 0 1px rgba(34, 211, 238, 0.12);
        }
        #daily-missions-shell .daily-mission-toggle::after {
          margin-top: 0.25rem;
          filter: invert(1) brightness(1.5);
          opacity: 0.8;
          flex-shrink: 0;
        }
        #daily-missions-shell .daily-mission-header-copy {
          flex: 1 1 auto;
          min-width: 0;
          display: grid;
          gap: 0.25rem;
        }
        #daily-missions-shell .daily-mission-header-eyebrow {
          color: var(--daily-accent, #22d3ee);
          font-size: 0.78rem;
          font-weight: 800;
          letter-spacing: 0.22em;
          text-transform: uppercase;
        }
        #daily-missions-shell .daily-mission-header-title {
          font-family: 'Oxanium', sans-serif;
          font-size: clamp(1.12rem, 2.1vw, 1.8rem);
          line-height: 1.08;
          color: #fff;
        }
        #daily-missions-shell .daily-mission-header-subtitle {
          color: #cbd5e1;
          font-size: 0.94rem;
        }
        #daily-missions-shell .daily-mission-pill {
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
        #daily-missions-shell .daily-mission-pill small {
          font-size: 0.68rem;
          letter-spacing: 0.18em;
          text-transform: uppercase;
          color: #94a3b8;
        }
        #daily-missions-shell .daily-mission-pill strong {
          font-size: 0.92rem;
        }
        #daily-missions-shell .daily-mission-body {
          padding: 1.1rem;
          display: grid;
          gap: 1rem;
        }
        #daily-missions-shell .daily-mission-layout {
          display: grid;
          grid-template-columns: minmax(250px, 320px) minmax(0, 1fr);
          gap: 1rem;
          align-items: stretch;
        }
        @media (max-width: 991.98px) and (min-width: 768px) {
          #daily-missions-shell .daily-mission-layout {
            grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
            align-items: start;
          }
          #daily-missions-shell .daily-mission-chest-stage {
            min-height: 0;
            padding: 0.9rem 0.6rem;
          }
        }
        @media (max-width: 767.98px) {
          #daily-missions-shell .daily-mission-layout {
            grid-template-columns: 1fr;
            align-items: stretch;
          }
          #daily-missions-shell .daily-mission-chest-stage {
            min-height: 22rem;
            padding: 0.85rem;
          }
        }
        #daily-missions-shell .daily-mission-column {
          min-width: 0;
        }
        #daily-missions-shell .daily-mission-chest-column {
          display: flex;
          align-items: center;
          justify-content: center;
        }
        #daily-missions-shell .daily-mission-chest-stage {
          width: 100%;
          min-height: 26rem;
          padding: 1rem;
          border-radius: 1.35rem;
          border: 1px solid rgba(34, 211, 238, 0.12);
          background: radial-gradient(circle at center top, rgba(34, 211, 238, 0.06), transparent 42%), linear-gradient(180deg, rgba(3, 8, 15, 0.88), rgba(6, 10, 18, 0.98));
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          gap: 1rem;
        }
        #daily-missions-shell .daily-mission-chest-shell,
        #daily-missions-shell .daily-mission-chest-3d {
          position: relative;
          width: min(100%, 260px);
          aspect-ratio: 1 / 1;
          border: 0;
          padding: 0;
          background: transparent;
          display: block;
          margin: 0 auto;
          transform: none;
          transition: none;
        }
        #daily-missions-shell .daily-mission-chest-shell {
          cursor: pointer;
          touch-action: manipulation;
        }
        #daily-missions-shell .daily-mission-chest-shell:disabled {
          cursor: default;
        }
        #daily-missions-shell .daily-mission-chest-shell::before,
        #daily-missions-shell .daily-mission-chest-3d::before {
          content: '';
          position: absolute;
          inset: auto 14% 6% 14%;
          height: 10%;
          background: radial-gradient(circle, rgba(0, 0, 0, 0.45) 0%, rgba(0, 0, 0, 0.18) 42%, transparent 78%);
          filter: blur(14px);
          opacity: 0.7;
          transform: translateY(30%);
          pointer-events: none;
        }
        /* ─── dm-* color vars per level — apply when chest is available (.is-open) ─── */

        /* Basic — marrón profundo con bordes y brillo dorado */
        #daily-missions-shell .daily-mission-chest-3d[data-level="basic"].is-open,
        #daily-mission-modal  .daily-mission-chest-3d[data-level="basic"].is-open,
        #daily-mission-modal  .daily-mission-chest-3d[data-level="basic"].abierto {
          --dm-front-lg:    linear-gradient(160deg,#d07848 0%,#8a3d10 35%,#5a2208 68%,#2e1004 100%);
          --dm-side-dark:   #2e1004;
          --dm-side-light:  #9a4c20;
          --dm-top-side:    #5a2208;
          --dm-top-light:   #b06030;
          --dm-outline:     #f5c518;
          --dm-lock-bg:     linear-gradient(180deg,#ffd84d,#c8880e);
          --dm-lock-border: #f5c518;
          --dm-glow-clr:    rgba(245,197,24,0.55);
          --dm-led:         rgba(245,197,24,0.95);
          --dm-crys:        #ffd700;
        }

        /* Intermediate — azul profundo con bordes dorados y LED azul */
        #daily-missions-shell .daily-mission-chest-3d[data-level="intermediate"].is-open,
        #daily-mission-modal  .daily-mission-chest-3d[data-level="intermediate"].is-open,
        #daily-mission-modal  .daily-mission-chest-3d[data-level="intermediate"].abierto {
          --dm-front-lg:    linear-gradient(160deg,#4090f0 0%,#1e3f8a 35%,#0e1e60 68%,#060d30 100%);
          --dm-side-dark:   #060d30;
          --dm-side-light:  #2a55b0;
          --dm-top-side:    #0e2060;
          --dm-top-light:   #3a70d8;
          --dm-outline:     #f5c518;
          --dm-lock-bg:     linear-gradient(180deg,#ffd84d,#c8880e);
          --dm-lock-border: #f5c518;
          --dm-glow-clr:    rgba(59,130,246,0.55);
          --dm-led:         rgba(96,165,250,0.95);
          --dm-crys:        #60a5fa;
        }

        /* Legendary — morado oscuro con bordes dorados y LED violeta */
        #daily-missions-shell .daily-mission-chest-3d[data-level="legendary"].is-open,
        #daily-mission-modal  .daily-mission-chest-3d[data-level="legendary"].is-open,
        #daily-mission-modal  .daily-mission-chest-3d[data-level="legendary"].abierto {
          --dm-front-lg:    linear-gradient(160deg,#9840e8 0%,#4d1890 35%,#280a60 68%,#10023a 100%);
          --dm-side-dark:   #10023a;
          --dm-side-light:  #6830a8;
          --dm-top-side:    #280a60;
          --dm-top-light:   #8040c0;
          --dm-outline:     #f5c518;
          --dm-lock-bg:     linear-gradient(180deg,#ffd84d,#c8880e);
          --dm-lock-border: #f5c518;
          --dm-glow-clr:    rgba(168,85,247,0.55);
          --dm-led:         rgba(192,132,252,0.95);
          --dm-crys:        #c084fc;
        }

        /* Available state rock animation — only on page chest (not modal) */
        #daily-missions-shell .daily-mission-chest-3d.is-open:not(.is-spinning):not(.girando) .dm-cofre {
          animation: dm-rock 3.5s ease-in-out infinite;
        }
        #daily-missions-shell .daily-mission-chest-copy {
          display: grid;
          gap: 0.25rem;
          text-align: center;
          max-width: 24rem;
        }
        #daily-missions-shell .daily-mission-chest-copy strong {
          font-family: 'Oxanium', sans-serif;
          font-size: 1.08rem;
          color: #fff;
        }
        #daily-missions-shell .daily-mission-chest-copy span {
          color: #cbd5e1;
          font-size: 0.92rem;
          line-height: 1.45;
        }
        #daily-missions-shell .daily-mission-tasks-column {
          display: grid;
          gap: 1rem;
        }
        #daily-missions-shell .daily-mission-task-head {
          display: flex;
          align-items: flex-start;
          justify-content: space-between;
          gap: 1rem;
          flex-wrap: wrap;
        }
        #daily-missions-shell .daily-mission-task-head h3 {
          margin: 0;
          font-family: 'Oxanium', sans-serif;
          font-size: 1.15rem;
          color: #fff;
        }
        #daily-missions-shell .daily-mission-task-list {
          display: grid;
          gap: 0.75rem;
          margin: 0;
          padding: 0;
          list-style: none;
        }
        #daily-missions-shell .daily-mission-task-card {
          display: flex;
          align-items: flex-start;
          gap: 0.9rem;
          padding: 0.95rem 1rem;
          border-radius: 1rem;
          border: 1px solid rgba(34, 211, 238, 0.16);
          background: linear-gradient(180deg, rgba(9, 14, 22, 0.92), rgba(4, 8, 14, 0.98));
          box-shadow: 0 12px 26px rgba(0, 0, 0, 0.22);
        }
        #daily-missions-shell .daily-mission-task-card[data-completed="1"] {
          border-color: rgba(34, 197, 94, 0.28);
          box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.08), 0 10px 26px rgba(0, 0, 0, 0.2);
          opacity: 0.95;
        }
        #daily-missions-shell .daily-mission-task-number {
          width: 2.15rem;
          height: 2.15rem;
          flex: 0 0 auto;
          border-radius: 999px;
          display: grid;
          place-items: center;
          background: rgba(34, 211, 238, 0.12);
          color: var(--daily-accent, #22d3ee);
          font-family: 'Oxanium', sans-serif;
          font-weight: 700;
        }
        #daily-missions-shell .daily-mission-task-main {
          min-width: 0;
          flex: 1 1 auto;
          display: grid;
          gap: 0.6rem;
        }
        #daily-missions-shell .daily-mission-task-line {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 0.5rem;
        }
        #daily-missions-shell .daily-mission-task-icon {
          width: 1.85rem;
          height: 1.85rem;
          border-radius: 999px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          background: rgba(34, 211, 238, 0.1);
          color: var(--daily-accent, #22d3ee);
          flex: 0 0 auto;
        }
        #daily-missions-shell .daily-mission-task-link.daily-mission-mini-action {
          display: inline-flex;
          align-items: center;
          gap: 0.45rem;
          padding: 0;
          border: 0;
          background: transparent;
          color: #f8fafc;
          text-decoration: none;
          font-family: 'Space Grotesk', sans-serif;
          font-weight: 700;
          line-height: 1.2;
        }
        #daily-missions-shell .daily-mission-task-link.daily-mission-mini-action:hover {
          text-decoration: underline;
          color: #fff;
        }
        #daily-missions-shell .daily-mission-task-link.daily-mission-mini-action.is-disabled {
          opacity: 0.55;
          pointer-events: none;
        }
        #daily-missions-shell .daily-mission-social-icons {
          display: flex;
          flex-wrap: wrap;
          gap: 0.45rem;
          margin-left: auto;
        }
        #daily-missions-shell .daily-mission-social-icon.daily-mission-mini-action {
          width: 2.05rem;
          height: 2.05rem;
          padding: 0;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          border-radius: 999px;
          border: 1px solid rgba(34, 211, 238, 0.26);
          background: rgba(34, 211, 238, 0.08);
          color: #9ff7ff;
          text-decoration: none;
          transition: transform 0.2s ease, background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, opacity 0.2s ease;
        }
        #daily-missions-shell .daily-mission-social-icon.daily-mission-mini-action i {
          font-size: 0.92rem;
        }
        #daily-missions-shell .daily-mission-social-icon.daily-mission-mini-action:hover {
          transform: translateY(-1px);
          background: rgba(34, 211, 238, 0.18);
          color: #fff;
        }
        /* Counting state — spinner border, no text change */
        #daily-missions-shell .daily-mission-social-icon.is-counting {
          opacity: 0.65;
          pointer-events: none;
          border-color: var(--daily-accent, #22d3ee);
          animation: dm-social-spin 0.85s linear infinite;
        }
        @keyframes dm-social-spin {
          to { transform: rotate(360deg); }
        }
        /* ─── Task check box ─── */
        #daily-missions-shell .daily-mission-task-check-box {
          width: 1.85rem;
          height: 1.85rem;
          flex: 0 0 auto;
          border-radius: 0.45rem;
          border: 2px solid rgba(34, 211, 238, 0.22);
          background: rgba(34, 211, 238, 0.04);
          display: grid;
          place-items: center;
          align-self: center;
          transition: background 0.35s ease, border-color 0.35s ease, box-shadow 0.35s ease;
          position: relative;
          overflow: hidden;
        }
        #daily-missions-shell .daily-mission-task-check-box::after {
          content: '';
          display: block;
          width: 0.6rem;
          height: 0.34rem;
          border-left: 2.5px solid rgba(34, 211, 238, 0.3);
          border-bottom: 2.5px solid rgba(34, 211, 238, 0.3);
          transform: rotate(-45deg) translateY(-10%);
          transition: border-color 0.35s ease, filter 0.35s ease;
        }
        #daily-missions-shell .daily-mission-task-card[data-completed="1"] .daily-mission-task-check-box {
          background: linear-gradient(135deg, #22c55e 0%, #15803d 100%);
          border-color: #22c55e;
          box-shadow: 0 0 14px rgba(34,197,94,.5), 0 0 28px rgba(34,197,94,.2);
          animation: dm-check-glow 1.8s ease-in-out infinite;
        }
        #daily-missions-shell .daily-mission-task-card[data-completed="1"] .daily-mission-task-check-box::after {
          border-color: #fff;
          filter: drop-shadow(0 0 3px rgba(255,255,255,.65));
        }
        @keyframes dm-check-glow {
          0%,100% { box-shadow:0 0 14px rgba(34,197,94,.5),0 0 28px rgba(34,197,94,.2); }
          50%      { box-shadow:0 0 22px rgba(34,197,94,.75),0 0 44px rgba(34,197,94,.35); }
        }
        #daily-missions-shell .daily-mission-progress-shell {
          width: 100%;
          padding-top: 0.25rem;
        }
        #daily-missions-shell .daily-mission-progress {
          height: 0.92rem;
          border-radius: 999px;
          background: rgba(15, 23, 42, 0.9);
          border: 1px solid rgba(34, 211, 238, 0.12);
          overflow: hidden;
        }
        #daily-missions-shell .daily-mission-progress .progress-bar {
          background: linear-gradient(90deg, var(--daily-accent, #22d3ee), rgba(255, 255, 255, 0.95), var(--daily-accent, #22d3ee));
          box-shadow: 0 0 18px var(--daily-glow, rgba(34, 211, 238, 0.28));
        }
        @media (max-width: 575.98px) {
          #daily-missions-shell .daily-mission-body {
            padding: 0.9rem;
          }
          #daily-missions-shell .daily-mission-task-card {
            padding: 0.75rem 0.6rem;
          }
          #daily-missions-shell .daily-mission-social-icons {
            margin-left: 0;
          }
        }
        #daily-missions-shell .daily-mission-guest-banner {
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 0.6rem 0.9rem;
          padding: 0.85rem 1rem;
          border-radius: 0.75rem;
          background: rgba(245,158,11,0.07);
          border: 1px solid rgba(245,158,11,0.28);
          color: #fbbf24;
          font-size: 0.91rem;
          line-height: 1.4;
        }
        #daily-missions-shell .daily-mission-guest-banner i {
          font-size: 1.1rem;
          flex-shrink: 0;
        }
        #daily-missions-shell .daily-mission-guest-banner span {
          flex: 1;
          min-width: 180px;
        }
        #daily-missions-shell .daily-mission-guest-link {
          color: #fff;
          background: rgba(245,158,11,0.22);
          border: 1px solid rgba(245,158,11,0.45);
          padding: 0.3rem 1rem;
          border-radius: 0.4rem;
          text-decoration: none;
          font-weight: 600;
          font-size: 0.88rem;
          white-space: nowrap;
          transition: background 0.2s ease;
        }
        #daily-missions-shell .daily-mission-guest-link:hover {
          background: rgba(245,158,11,0.38);
          color: #fff;
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

      <?php if (!empty($dailyMissionsPayload['enabled'])): ?>
        <?php
            $dailyMissionsIsGuest = $dailyMissionsUserId === 0;
            $dailyMissionExploreTarget = $dailyMissionsExploreTargets[0] ?? app_path('/juegos');
            $dailyMissionSocialTargets = array_values($dailyMissionsSocialTargets);
            $dailyMissionPrimaryShareTarget = (string) ($dailyMissionSocialTargets[0]['url'] ?? $dailyMissionExploreTarget);
          $dailyMissionShareTargetsJson = json_encode($dailyMissionsSocialTargets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          $dailyMissionTaskCount = max(1, count($dailyMissionsTasks));
          $dailyMissionCompletedCount = $dailyMissionsIsGuest ? 0 : max(0, (int) ($dailyMissionsDay['completed_tasks_count'] ?? 0));
          $dailyMissionRemaining = max(0, $dailyMissionTaskCount - $dailyMissionCompletedCount);
          $dailyMissionStreak = $dailyMissionsIsGuest ? 0 : max(0, (int) ($dailyMissionsDay['current_streak_days'] ?? 0));
          $dailyMissionImmunity = $dailyMissionsIsGuest ? 0 : max(0, (int) ($dailyMissionsState['immunity_balance'] ?? 0));
          $dailyMissionRewardLabel = $dailyMissionsIsGuest ? 'Completa las tareas para abrirlo' : ($dailyMissionsCanOpenChest ? 'Cofre listo para abrir' : 'Completa las tareas para abrirlo');
          $dailyMissionRemainingLabel = $dailyMissionRemaining === 1 ? '1 tarea restante' : $dailyMissionRemaining . ' tareas restantes';
          $dailyMissionProgressLabel = $dailyMissionsIsGuest ? '0% completado' : ($dailyMissionsProgressPercent >= 100 ? '100% completado' : $dailyMissionsProgressPercent . '% completado');
        ?>
        <section
          id="daily-missions-shell"
          class="daily-mission-shell mt-5" data-aos="fade-up"
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
          data-user-id="<?php echo $dailyMissionsUserId; ?>"
        >
          <div class="accordion" id="daily-missions-accordion">
            <div class="accordion-item daily-mission-panel">
              <h2 class="accordion-header" id="daily-missions-heading">
                <button class="accordion-button collapsed daily-mission-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#daily-missions-collapse" aria-expanded="false" aria-controls="daily-missions-collapse">
                  <div class="daily-mission-header-copy">
                    <span class="daily-mission-header-eyebrow"><?php echo htmlspecialchars((string) ($dailyMissionsSettings['title'] ?? 'Mision diaria'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <h3 class="daily-mission-header-title mb-0"><?php echo htmlspecialchars((string) ($dailyMissionsSettings['subtitle'] ?? 'Completa las tareas, abre el cofre y gana Win Points.'), ENT_QUOTES, 'UTF-8'); ?></h3>
                  </div>
                  <span class="daily-mission-pill text-end">
                    <small>Progreso</small>
                    <strong id="daily-mission-pill-progress"><?php echo htmlspecialchars($dailyMissionProgressLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                  </span>
                </button>
              </h2>
              <div id="daily-missions-collapse" class="accordion-collapse collapse" aria-labelledby="daily-missions-heading" data-bs-parent="#daily-missions-accordion">
                <div class="daily-mission-body">
                  <?php if ($dailyMissionsIsGuest): ?>
                  <div class="daily-mission-guest-banner" role="alert">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span>Regístrate o loguéate para realizar misiones diarias y ganar premios</span>
                    <a href="<?php echo htmlspecialchars(app_path('/login.php'), ENT_QUOTES, 'UTF-8'); ?>" class="daily-mission-guest-link">Entrar</a>
                  </div>
                  <?php endif; ?>
                  <div class="daily-mission-topline">
                    <div class="daily-mission-topline-copy">
                      <div class="small text-uppercase" style="letter-spacing:0.28em;">Recompensas por <?php echo htmlspecialchars(win_points_program_name(), ENT_QUOTES, 'UTF-8'); ?></div>
                      <h3 class="mb-0">Racha actual: <span id="daily-mission-streak-text" class="text-info"><?php echo number_format($dailyMissionStreak); ?></span> dias</h3>
                      <div class="text-secondary small"><?php echo htmlspecialchars($dailyMissionRemainingLabel, ENT_QUOTES, 'UTF-8'); ?> | Inmunidad: <span id="daily-mission-immunity-text" class="text-warning"><?php echo number_format($dailyMissionImmunity); ?></span></div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <span class="badge rounded-pill text-bg-dark border border-info-subtle text-info px-3 py-2">Nivel <span id="daily-mission-summary-level"><?php echo htmlspecialchars((string) ($dailyMissionsPalette['label'] ?? 'Basico'), ENT_QUOTES, 'UTF-8'); ?></span></span>
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
                      <small>Nivel del cofre</small>
                      <strong id="daily-mission-summary-level"><?php echo htmlspecialchars((string) ($dailyMissionsPalette['label'] ?? 'Basico'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                  </div>

                  <div class="daily-mission-layout">
                    <div class="daily-mission-column daily-mission-chest-column">
                      <div class="daily-mission-chest-stage">
                        <button type="button" class="daily-mission-chest-shell<?php echo $dailyMissionsCanOpenChest ? ' is-open' : ''; ?>" id="daily-mission-chest-shell" data-daily-mission-chest data-level="<?php echo htmlspecialchars($dailyMissionsLevelKey, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Abrir cofre diario"<?php echo $dailyMissionsCanOpenChest ? '' : ' disabled'; ?>>
                          <div class="daily-mission-chest-3d<?php echo $dailyMissionsCanOpenChest ? ' is-open' : ''; ?>" id="daily-mission-chest-3d" data-level="<?php echo htmlspecialchars($dailyMissionsLevelKey, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php
                              $_dmVids = ['basic'=>'cofre basico rebor.mp4','intermediate'=>'cofre intermedio.mp4','legendary'=>'cofre legendario rebor.mp4'];
                              $_chestVid = htmlspecialchars(app_path('/assets/video/'.($_dmVids[$dailyMissionsLevelKey]??$_dmVids['basic'])),ENT_QUOTES,'UTF-8');
                            ?>
                            <div class="dm-video-wrap">
                              <video class="dm-video" src="<?php echo $_chestVid; ?>" muted playsinline preload="metadata"></video>
                            </div>
                            <div class="dm-glow-ring" aria-hidden="true"></div>
                          </div>
                        </button>
                        <div class="daily-mission-chest-copy text-center">
                          <small id="daily-mission-chest-label"><?php echo htmlspecialchars($dailyMissionRewardLabel, ENT_QUOTES, 'UTF-8'); ?></small>
                          <strong id="daily-mission-chest-title"><?php echo htmlspecialchars((string) ($dailyMissionsPalette['label'] ?? 'Basico'), ENT_QUOTES, 'UTF-8'); ?></strong>
                          <span id="daily-mission-chest-subtitle"><?php echo htmlspecialchars($dailyMissionsCanOpenChest ? 'Pulsa el cofre para reclamar el premio de hoy.' : 'Completa las tareas para desbloquearlo.', ENT_QUOTES, 'UTF-8'); ?></span>
                          <div class="small text-secondary mt-1" id="daily-mission-state-title"><?php echo htmlspecialchars($dailyMissionRewardLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                          <div class="small text-secondary" id="daily-mission-state-description"><?php echo htmlspecialchars($dailyMissionsCanOpenChest ? 'El cofre está listo para abrirse.' : 'El cofre se abre cuando todas las tareas del día están completas.', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                      </div>
                    </div>

                    <div class="daily-mission-column daily-mission-tasks-column">
                      <div class="daily-mission-task-head">
                        <div>
                          <div class="small text-uppercase" style="letter-spacing:0.24em;">Lista de tareas</div>
                          <h3 class="mb-0">Completa las tareas del día</h3>
                        </div>
                        <div class="text-secondary small">Solo el título se muestra en pantalla.</div>
                      </div>
                      <ul class="daily-mission-task-list" id="daily-mission-task-grid">
                        <?php foreach ($dailyMissionsTasks as $taskIndex => $task): ?>
                          <?php
                            $taskType = (string) ($task['task_type'] ?? '');
                            $taskCompleted = $dailyMissionsIsGuest ? false : !empty($task['completed_today']);
                            $taskDisabled = $dailyMissionsIsGuest || empty($task['active']);
                            $taskDescription = trim((string) ($task['description'] ?? ''));
                            $taskHref = app_path('/juegos');
                            $taskTargetAttr = '';
                            $taskRelAttr = '';
                            $taskIconClass = 'fa-circle';
                            $taskTimerValue = 0;
                            switch ($taskType) {
                              case 'login':
                                $taskHref = app_path('/login.php');
                                $taskIconClass = 'fa-right-to-bracket';
                                break;
                              case 'explore':
                                $taskHref = $dailyMissionExploreTarget !== '' ? $dailyMissionExploreTarget : app_path('/juegos');
                                $taskTargetAttr = '_blank';
                                $taskRelAttr = 'noopener noreferrer';
                                $taskIconClass = 'fa-gamepad';
                                $taskTimerValue = (int) ($dailyMissionsSettings['explore_timer_seconds'] ?? 7);
                                break;
                              case 'share':
                                $taskHref = $dailyMissionPrimaryShareTarget !== '' ? $dailyMissionPrimaryShareTarget : app_path('/juegos');
                                $taskTargetAttr = '_blank';
                                $taskRelAttr = 'noopener noreferrer';
                                $taskIconClass = 'fa-share-nodes';
                                $taskTimerValue = (int) ($dailyMissionsSettings['share_timer_seconds'] ?? 15);
                                break;
                              case 'purchase':
                                $taskHref = app_path('/juegos');
                                $taskIconClass = 'fa-cart-shopping';
                                break;
                            }
                            $taskShareTargets = $taskType === 'share' ? $dailyMissionShareTargetsJson : '';
                            $taskIconMap = [
                              'facebook' => 'fa-brands fa-facebook-f',
                              'instagram' => 'fa-brands fa-instagram',
                              'tiktok' => 'fa-brands fa-tiktok',
                            ];
                          ?>
                          <li class="daily-mission-task-card<?php echo $taskCompleted ? ' is-done' : ''; ?><?php echo $taskDisabled ? ' is-disabled' : ''; ?>" data-mission-key="<?php echo htmlspecialchars((string) ($task['mission_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-mission-type="<?php echo htmlspecialchars($taskType, ENT_QUOTES, 'UTF-8'); ?>" data-mission-timer="<?php echo $taskTimerValue; ?>" data-target-url="<?php echo htmlspecialchars((string) $taskHref, ENT_QUOTES, 'UTF-8'); ?>" data-share-targets="<?php echo htmlspecialchars((string) $taskShareTargets, ENT_QUOTES, 'UTF-8'); ?>" data-completed="<?php echo $taskCompleted ? '1' : '0'; ?>" data-disabled="<?php echo $taskDisabled ? '1' : '0'; ?>">
                            <div class="daily-mission-task-number"><?php echo str_pad((string) ($taskIndex + 1), 2, '0', STR_PAD_LEFT); ?></div>
                            <div class="daily-mission-task-main">
                              <div class="daily-mission-task-line">
                                <span class="daily-mission-task-icon"><i class="fa-solid <?php echo htmlspecialchars($taskIconClass, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i></span>
                                <?php if ($taskType === 'share'): ?>
                                  <span class="daily-mission-task-text<?php echo $taskCompleted ? ' is-primary' : ''; ?>" aria-hidden="false"><?php echo htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php else: ?>
                                  <a href="<?php echo htmlspecialchars((string) $taskHref, ENT_QUOTES, 'UTF-8'); ?>" class="daily-mission-mini-action daily-mission-task-link<?php echo $taskCompleted ? ' is-primary' : ''; ?>" data-task-key="<?php echo htmlspecialchars((string) ($task['mission_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-task-timer="<?php echo $taskTimerValue; ?>" data-target-url="<?php echo htmlspecialchars((string) $taskHref, ENT_QUOTES, 'UTF-8'); ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars((string) $taskDescription, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $taskDisabled ? ' aria-disabled="true" tabindex="-1"' : ''; ?><?php echo $taskTargetAttr !== '' ? ' target="' . htmlspecialchars((string) $taskTargetAttr, ENT_QUOTES, 'UTF-8') . '"' : ''; ?><?php echo $taskRelAttr !== '' ? ' rel="' . htmlspecialchars((string) $taskRelAttr, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars((string) ($task['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php endif; ?>
                                <?php if ($taskType === 'share'): ?>
                                  <div class="daily-mission-social-icons">
                                    <?php foreach ($dailyMissionsSocialTargets as $socialKey => $socialTarget): ?>
                                      <?php $socialIconClass = $taskIconMap[$socialKey] ?? 'fa-circle'; ?>
                                      <a href="<?php echo htmlspecialchars((string) ($socialTarget['url'] ?? $taskHref), ENT_QUOTES, 'UTF-8'); ?>" class="daily-mission-mini-action daily-mission-social-icon" data-daily-mission-social="<?php echo htmlspecialchars((string) $socialKey, ENT_QUOTES, 'UTF-8'); ?>" data-target-url="<?php echo htmlspecialchars((string) ($socialTarget['url'] ?? $taskHref), ENT_QUOTES, 'UTF-8'); ?>" data-task-key="<?php echo htmlspecialchars((string) ($task['mission_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-task-timer="<?php echo (int) ($dailyMissionsSettings['share_timer_seconds'] ?? 15); ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars((string) ($socialTarget['label'] ?? ucfirst($socialKey)), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"<?php echo $taskDisabled ? ' aria-disabled="true" tabindex="-1"' : ''; ?>>
                                        <i class="<?php echo htmlspecialchars($socialIconClass, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                                        <span class="visually-hidden"><?php echo htmlspecialchars((string) ($socialTarget['label'] ?? ucfirst($socialKey)), ENT_QUOTES, 'UTF-8'); ?></span>
                                      </a>
                                    <?php endforeach; ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                            </div>
                            <div class="daily-mission-task-check-box" aria-hidden="true"></div>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  </div>

                  <div class="daily-mission-progress-shell">
                    <div class="progress daily-mission-progress">
                      <div id="daily-mission-progress-bar" class="progress-bar" role="progressbar" style="width: <?php echo (int) $dailyMissionsProgressPercent; ?>%;" aria-valuenow="<?php echo (int) $dailyMissionsProgressPercent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                  </div>
                </div>
              </div>
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
            <div class="daily-mission-chest-3d">
              <div class="dm-video-wrap">
                <video class="dm-video" muted playsinline preload="auto"></video>
              </div>
              <div class="daily-mission-modal-sorpresa sorpresa">
                <div class="icono-s" id="daily-mission-modal-icono">🎁</div>
                <div class="texto-s" id="daily-mission-modal-texto">¡Sorpresa!</div>
              </div>
              <div class="dm-glow-ring" aria-hidden="true"></div>
            </div>
          </div>
          <div class="daily-mission-modal-body px-3 pb-3">
            <div class="daily-mission-modal-prize text-center">
              <div class="small text-uppercase text-secondary mb-1" id="daily-mission-modal-prize-type"></div>
              <h4 class="mb-1" id="daily-mission-modal-prize-label"></h4>
              <p class="small text-secondary mb-0" id="daily-mission-modal-prize-desc"></p>
            </div>
            <div class="daily-mission-modal-actions text-center mt-3">
              <button type="button" id="daily-mission-modal-continue" class="btn btn-outline-info">Continuar</button>
            </div>
          </div>
        </div>
      </div>
      <style>
        /* Modal stage sizing and sorpresa visual styles */
        #daily-mission-modal .daily-mission-modal-stage {
          min-height: 260px;
          position: relative;
          overflow: visible;
        }
        #daily-mission-modal .daily-mission-chest-3d {
          overflow: visible;
        }
        #daily-mission-modal .daily-mission-modal-sorpresa .icono-s {
          font-size: 76px;
          filter: drop-shadow(0 0 28px rgba(255,215,0,0.98)) drop-shadow(0 0 56px rgba(255,165,0,0.55));
          margin-bottom: 14px;
          display: block;
          animation: dm-prize-pop 0.65s cubic-bezier(0.175,0.885,0.32,1.5) both;
        }
        @keyframes dm-prize-pop {
          0%   { transform:scale(0) translateY(18px); opacity:0; }
          65%  { transform:scale(1.25) translateY(-5px); opacity:1; }
          100% { transform:scale(1) translateY(0); opacity:1; }
        }
        #daily-mission-modal .daily-mission-modal-sorpresa .texto-s {
          background: linear-gradient(135deg,#f5c518,#e67e22);
          padding: 11px 24px;
          border-radius: 12px;
          font-weight: 900;
          font-size: 1.08rem;
          letter-spacing: 0.05em;
          color: #1a0a00;
          border: 2px solid rgba(255,255,255,0.92);
          display: inline-block;
          box-shadow: 0 0 26px rgba(245,197,24,0.85), 0 0 52px rgba(245,197,24,0.38);
          text-shadow: 0 1px 4px rgba(255,255,255,0.45);
        }
      </style>

      <?php if ($rouletteEnabled): ?>
      <style>
        .roulette-shell { position: relative; z-index: 1; }
        .roulette-banner {
          display: grid;
          grid-template-columns: auto 1fr;
          gap: 0;
          border-radius: 1.5rem;
          border: 1px solid rgba(99,102,241,0.28);
          background:
            radial-gradient(ellipse at top left, rgba(99,102,241,0.14) 0%, transparent 55%),
            radial-gradient(ellipse at bottom right, rgba(34,211,238,0.08) 0%, transparent 55%),
            linear-gradient(160deg, rgba(8,12,22,0.98), rgba(4,8,16,0.99));
          box-shadow: 0 20px 48px rgba(0,0,0,0.36), 0 0 32px rgba(99,102,241,0.08), inset 0 0 0 1px rgba(99,102,241,0.1);
          overflow: hidden;
        }
        @media (max-width: 600px) {
          .roulette-banner { grid-template-columns: 1fr; }
        }

        /* Wheel side */
        .roulette-wheel-side {
          position: relative;
          width: 240px;
          flex-shrink: 0;
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: flex-end;
          background: linear-gradient(90deg, rgba(99,102,241,0.10) 0%, transparent 100%);
        }
        @media (max-width: 600px) {
          .roulette-wheel-side {
            width: 100%;
            border-bottom: 1px solid rgba(99,102,241,0.15);
          }
        }
        .roulette-pointer {
          position: absolute;
          bottom: 148px;
          left: 50%;
          transform: translateX(-50%);
          z-index: 10;
          font-size: 1.6rem;
          color: #fcd34d;
          filter: drop-shadow(0 0 8px rgba(252,211,77,0.8)) drop-shadow(0 0 18px rgba(252,211,77,0.4));
          line-height: 1;
          user-select: none;
        }
        @media (max-width: 600px) {
          .roulette-pointer { bottom: 138px; }
        }
        .roulette-clip-box {
          overflow: hidden;
          height: 148px;
          width: 240px;
          position: relative;
          display: flex;
          align-items: flex-end;
          justify-content: center;
        }
        @media (max-width: 600px) {
          .roulette-clip-box { height: 138px; }
        }
        .roulette-svg-wrap {
          position: absolute;
          bottom: -100px;
          left: 50%;
          transform: translateX(-50%);
          width: 240px;
          height: 240px;
          will-change: transform;
        }
        .roulette-svg {
          width: 100%;
          height: 100%;
          filter: drop-shadow(0 0 22px rgba(99,102,241,0.35));
        }
        .roulette-wheel-ring {
          position: absolute;
          bottom: -100px;
          left: 50%;
          transform: translateX(-50%);
          width: 244px; height: 244px;
          border-radius: 50%;
          border: 2px solid rgba(99,102,241,0.5);
          box-shadow: 0 0 24px rgba(99,102,241,0.25), inset 0 0 24px rgba(99,102,241,0.12);
          pointer-events: none;
          z-index: 5;
        }

        /* Info side */
        .roulette-info-side {
          padding: 1.5rem 1.6rem;
          display: flex;
          flex-direction: row;
          align-items: center;
          gap: 1.5rem;
        }
        .roulette-info-copy {
          flex: 1;
          min-width: 0;
          display: flex;
          flex-direction: column;
          gap: 0.55rem;
        }
        @media (max-width: 600px) {
          .roulette-info-side { flex-direction: column; align-items: flex-start; gap: 1rem; }
        }
        .roulette-title {
          font-family: 'Oxanium', sans-serif;
          font-size: clamp(1.3rem, 3vw, 2rem);
          font-weight: 900;
          line-height: 1.05;
          color: #fff;
          margin: 0;
          text-shadow: 0 0 20px rgba(99,102,241,0.4);
        }
        .roulette-sub {
          font-size: 0.88rem;
          color: #94a3b8;
          margin: 0;
        }
        .roulette-cost-row {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          flex-wrap: wrap;
          margin-top: 0.25rem;
        }
        .roulette-cost-badge {
          display: inline-flex;
          align-items: baseline;
          gap: 0.3rem;
          background: rgba(99,102,241,0.12);
          border: 1px solid rgba(99,102,241,0.28);
          border-radius: 0.6rem;
          padding: 0.28rem 0.75rem;
        }
        .roulette-cost-amount {
          font-size: 1.1rem;
          font-weight: 900;
          color: #818cf8;
          font-family: 'Oxanium', sans-serif;
        }
        .roulette-cost-unit {
          font-size: 0.72rem;
          color: #94a3b8;
        }
        .roulette-balance-badge {
          font-size: 0.78rem;
          color: #64748b;
        }
        .roulette-balance-badge strong { color: #e2e8f0; }

        /* Spin button */
        .roulette-btn-row {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          flex-wrap: wrap;
          flex-shrink: 0;
        }
        .roulette-btn {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 0.6rem;
          padding: 0.75rem 1.8rem;
          border-radius: 0.9rem;
          border: 1px solid rgba(99,102,241,0.5);
          background: linear-gradient(135deg, rgba(99,102,241,0.25), rgba(34,211,238,0.15));
          color: #e0e7ff;
          font-size: 0.95rem;
          font-weight: 800;
          letter-spacing: 0.08em;
          cursor: pointer;
          transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
          box-shadow: 0 0 16px rgba(99,102,241,0.2);
          text-decoration: none;
          align-self: flex-start;
        }
        .roulette-btn:hover:not(:disabled) {
          background: linear-gradient(135deg, rgba(99,102,241,0.45), rgba(34,211,238,0.3));
          box-shadow: 0 0 28px rgba(99,102,241,0.45), 0 0 8px rgba(34,211,238,0.2);
          transform: translateY(-1px);
          color: #fff;
        }
        .roulette-btn:disabled {
          opacity: 0.45;
          cursor: not-allowed;
          transform: none;
        }
        .roulette-btn.spinning .roulette-btn-icon { animation: rlt-spin-icon 0.6s linear infinite; }
        @keyframes rlt-spin-icon { to { transform: rotate(360deg); } }
        .roulette-btn-icon { font-size: 1.1rem; display: inline-block; }
        .rlt-btn-img { width: 1.2rem; height: 1.2rem; object-fit: cover; border-radius: 50%; vertical-align: middle; display: inline-block; }
        .roulette-btn-guest {
          background: rgba(51,65,85,0.4);
          border-color: rgba(255,255,255,0.1);
          color: #94a3b8;
        }

        .roulette-msg {
          font-size: 0.8rem;
          color: #f87171;
          min-height: 1.2em;
        }
        .roulette-msg.ok { color: #34d399; }

        /* Modal */
        .roulette-modal {
          position: fixed;
          inset: 0;
          z-index: 1085;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 1rem;
          pointer-events: auto;
        }
        .roulette-modal.is-hidden { display: none; }
        .roulette-modal-backdrop {
          position: absolute;
          inset: 0;
          background: rgba(2,6,14,0.72);
          backdrop-filter: blur(10px);
          cursor: pointer;
        }
        .roulette-modal-card {
          position: relative;
          z-index: 1;
          background: linear-gradient(160deg, rgba(12,14,28,0.98), rgba(6,8,18,0.99));
          border: 1px solid rgba(99,102,241,0.35);
          border-radius: 1.5rem;
          padding: 2rem 1.6rem 1.5rem;
          max-width: 360px;
          width: 100%;
          text-align: center;
          box-shadow: 0 24px 60px rgba(0,0,0,0.55), 0 0 40px rgba(99,102,241,0.18);
          animation: rlt-modal-in 0.45s cubic-bezier(0.175,0.885,0.32,1.5) both;
        }
        @keyframes rlt-modal-in {
          0%   { opacity:0; transform:scale(0.6) translateY(20px); }
          100% { opacity:1; transform:scale(1) translateY(0); }
        }
        .roulette-modal-head {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 1.5rem;
        }
        .roulette-modal-eyebrow {
          font-size: 0.7rem;
          font-weight: 800;
          letter-spacing: 0.24em;
          text-transform: uppercase;
          color: #818cf8;
        }
        .roulette-modal-close-btn {
          background: none;
          border: none;
          color: #475569;
          font-size: 1rem;
          cursor: pointer;
          padding: 0.2rem 0.4rem;
          border-radius: 0.3rem;
          line-height: 1;
        }
        .roulette-modal-close-btn:hover { color: #94a3b8; }
        .roulette-modal-prize-icon {
          font-size: 4.5rem;
          margin-bottom: 0.75rem;
          display: block;
          filter: drop-shadow(0 0 22px rgba(252,211,77,0.85));
          animation: rlt-prize-pop 0.65s cubic-bezier(0.175,0.885,0.32,1.5) both 0.1s;
        }
        @keyframes rlt-prize-pop {
          0%   { transform:scale(0) rotate(-20deg); opacity:0; }
          65%  { transform:scale(1.2) rotate(5deg); opacity:1; }
          100% { transform:scale(1) rotate(0); opacity:1; }
        }
        .roulette-modal-prize-label {
          font-family: 'Oxanium', sans-serif;
          font-size: 1.5rem;
          font-weight: 900;
          color: #fff;
          margin-bottom: 0.4rem;
          text-shadow: 0 0 18px rgba(99,102,241,0.5);
        }
        .roulette-modal-prize-desc {
          font-size: 0.88rem;
          color: #94a3b8;
          margin-bottom: 0.5rem;
        }
        .roulette-modal-coupon {
          font-size: 0.82rem;
          background: rgba(99,102,241,0.12);
          border: 1px solid rgba(99,102,241,0.28);
          border-radius: 0.5rem;
          padding: 0.4rem 0.9rem;
          color: #a5b4fc;
          margin-bottom: 0.5rem;
          word-break: break-all;
        }
        .roulette-modal-actions { margin-top: 1rem; }
        .roulette-btn-small {
          padding: 0.55rem 1.5rem;
          border-radius: 0.7rem;
          border: 1px solid rgba(99,102,241,0.4);
          background: rgba(99,102,241,0.18);
          color: #c7d2fe;
          font-size: 0.88rem;
          font-weight: 700;
          cursor: pointer;
          transition: background 0.15s;
        }
        .roulette-btn-small:hover { background: rgba(99,102,241,0.32); color: #fff; }

        /* Sparks animation for modal */
        .rlt-spark {
          position: fixed;
          pointer-events: none;
          z-index: 1090;
          font-size: 1.2rem;
          animation: rlt-spark-fly 1.1s ease-out forwards;
        }
        @keyframes rlt-spark-fly {
          0%   { opacity:1; transform: translate(0,0) scale(1); }
          100% { opacity:0; transform: translate(var(--sx),var(--sy)) scale(0.3); }
        }
      </style>

      <section class="mt-5 roulette-shell" id="roulette-shell" data-aos="fade-up">
        <div class="roulette-banner">
          <!-- Wheel side -->
          <div class="roulette-wheel-side">
            <div class="roulette-pointer" aria-hidden="true">▼</div>
            <div class="roulette-clip-box">
              <div class="roulette-svg-wrap" id="roulette-svg-wrap">
                <svg id="roulette-svg" class="roulette-svg" viewBox="0 0 240 240" xmlns="http://www.w3.org/2000/svg">
                  <g id="roulette-wheel-group"></g>
                </svg>
              </div>
              <div class="roulette-wheel-ring" aria-hidden="true"></div>
            </div>
          </div>

          <!-- Info side -->
          <div class="roulette-info-side">
            <div class="roulette-info-copy">
              <h2 class="roulette-title"><?= htmlspecialchars((string) ($rouletteConfig['title'] ?? 'Ruleta de Premios'), ENT_QUOTES, 'UTF-8') ?></h2>
              <p class="roulette-sub"><?= htmlspecialchars((string) ($rouletteConfig['subtitle'] ?? 'Gira y gana premios increíbles.'), ENT_QUOTES, 'UTF-8') ?></p>
              <div class="roulette-cost-row">
                <span class="roulette-cost-badge">
                  <span class="roulette-cost-amount"><?= (int) ($rouletteConfig['spin_cost'] ?? 10) ?></span>
                  <span class="roulette-cost-unit"><?= htmlspecialchars(win_points_program_name(), ENT_QUOTES, 'UTF-8') ?> / giro</span>
                </span>
                <?php if ($rouletteUserId > 0): ?>
                <span class="roulette-balance-badge">Saldo: <strong id="roulette-balance-val"><?= (int) $rouletteBalance ?></strong> <?= htmlspecialchars(win_points_program_name(), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="roulette-btn-row">
              <?php if ($rouletteUserId > 0): ?>
                <button type="button" id="roulette-spin-btn" class="roulette-btn"
                  data-spin-cost="<?= (int) ($rouletteConfig['spin_cost'] ?? 10) ?>"
                  data-api-url="<?= htmlspecialchars(app_path('/api/roulette.php'), ENT_QUOTES, 'UTF-8') ?>">
                  <?php
                    $rltBtnImg   = trim((string) ($rouletteConfig['btn_image_url'] ?? ''));
                    $rltBtnEmoji = trim((string) ($rouletteConfig['btn_emoji'] ?? '🎰')) ?: '🎰';
                    $rltBtnSzMap = ['small' => '0.85rem', 'medium' => '1.2rem', 'large' => '1.7rem'];
                    $rltBtnSz    = $rltBtnSzMap[$rouletteConfig['btn_image_size'] ?? 'medium'] ?? '1.2rem';
                  ?>
                  <span class="roulette-btn-icon" id="roulette-btn-icon"><?php if ($rltBtnImg): ?><img src="<?= htmlspecialchars($rltBtnImg, ENT_QUOTES, 'UTF-8') ?>" alt="" class="rlt-btn-img" style="width:<?= $rltBtnSz ?>;height:<?= $rltBtnSz ?>;"><?php else: ?><?= htmlspecialchars($rltBtnEmoji, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                  <span>GIRAR AHORA</span>
                </button>
                <div id="roulette-msg" class="roulette-msg" aria-live="polite"></div>
              <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:.4rem;">
                  <a href="<?= htmlspecialchars(app_path('/login.php'), ENT_QUOTES, 'UTF-8') ?>" class="roulette-btn roulette-btn-guest">
                    <span class="roulette-btn-icon">🔐</span>
                    <span>INICIA SESIÓN PARA GIRAR</span>
                  </a>
                  <span style="font-size:.75rem;color:#64748b;">Inicia sesión o regístrate para jugar y ganar premios.</span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Prize modal -->
        <div id="roulette-prize-modal" class="roulette-modal is-hidden" aria-hidden="true">
          <div class="roulette-modal-backdrop" id="roulette-modal-backdrop"></div>
          <div class="roulette-modal-card">
            <div class="roulette-modal-head">
              <span class="roulette-modal-eyebrow">🎰 RULETA — PREMIO</span>
              <button type="button" class="roulette-modal-close-btn" id="roulette-modal-close-btn" aria-label="Cerrar">✕</button>
            </div>
            <span class="roulette-modal-prize-icon" id="roulette-modal-icon">🎁</span>
            <div class="roulette-modal-prize-label" id="roulette-modal-label">¡Premio!</div>
            <div class="roulette-modal-prize-desc" id="roulette-modal-desc"></div>
            <div class="roulette-modal-coupon" id="roulette-modal-coupon" style="display:none">
              Tu cupón: <strong id="roulette-modal-coupon-code"></strong>
            </div>
            <div class="roulette-modal-actions">
              <button type="button" id="roulette-modal-ok" class="roulette-btn-small">¡Continuar!</button>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <?php if (!empty($ggDropsPackages)): ?>
      <section class="mt-5 gg-drops-section" data-aos="fade-up">
        <style>
          .gg-drops-track {
            display: flex;
            gap: 0.6rem;
            overflow-x: auto;
            scroll-behavior: smooth;
            scrollbar-width: none;
            -ms-overflow-style: none;
            padding-bottom: 0.25rem;
          }
          .gg-drops-track::-webkit-scrollbar { display: none; }
          .gg-drops-card {
            flex: 0 0 auto;
            width: 220px;
            background: #0b1420;
            border: 1px solid rgba(34,211,238,0.18);
            border-radius: 10px;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            transition: border-color 0.18s, box-shadow 0.18s, transform 0.16s;
            display: block;
          }
          .gg-drops-card:hover {
            border-color: rgba(34,211,238,0.65);
            box-shadow: 0 0 16px rgba(34,211,238,0.18);
            transform: translateY(-2px);
          }
          .gg-drops-card-top {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.55rem 0.6rem 0.45rem;
          }
          .gg-drops-thumb {
            width: 68px;
            height: 68px;
            flex-shrink: 0;
            border-radius: 8px;
            overflow: hidden;
            background: #111927;
            display: flex;
            align-items: center;
            justify-content: center;
          }
          .gg-drops-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
          }
          .gg-drops-info {
            flex: 1;
            min-width: 0;
          }
          .gg-drops-game-label {
            font-size: 0.58rem;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #22d3ee;
            margin-bottom: 0.18rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
          }
          .gg-drops-pkg-name {
            font-size: 0.85rem;
            font-weight: 700;
            color: #f1f5f9;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
          }
          .gg-drops-price-row {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.6rem 0.55rem;
            border-top: 1px solid rgba(34,211,238,0.1);
            flex-wrap: wrap;
          }
          .gg-drops-price-original {
            font-size: 0.72rem;
            color: #475569;
            text-decoration: line-through;
            white-space: nowrap;
          }
          .gg-drops-price-actual {
            font-size: 0.9rem;
            font-weight: 700;
            color: #22d3ee;
            white-space: nowrap;
          }
          .gg-drops-badge {
            font-size: 0.62rem;
            font-weight: 800;
            background: #dc2626;
            color: #fff;
            border-radius: 4px;
            padding: 0.1rem 0.3rem;
            margin-left: auto;
            white-space: nowrap;
          }
          .gg-drops-nav-btn {
            background: rgba(11,20,32,0.92);
            border: 1px solid rgba(34,211,238,0.3);
            color: #22d3ee;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            font-size: 1.1rem;
            line-height: 1;
            transition: background 0.15s, border-color 0.15s;
            padding: 0;
          }
          .gg-drops-nav-btn:hover { background: rgba(34,211,238,0.12); border-color: rgba(34,211,238,0.6); }
        </style>
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h2 class="fw-bold mb-0" style="font-family:'Oxanium',sans-serif;font-size:1.1rem;letter-spacing:0.04em;">
            <?= htmlspecialchars(store_config_get('gg_drops_nombre', 'GG DROPS'), ENT_QUOTES, 'UTF-8') ?> <span style="color:#22d3ee;">&#9889;</span>
          </h2>
          <div class="d-flex gap-2">
            <button type="button" class="gg-drops-nav-btn" id="gg-drops-prev" aria-label="Anterior">&#8249;</button>
            <button type="button" class="gg-drops-nav-btn" id="gg-drops-next" aria-label="Siguiente">&#8250;</button>
          </div>
        </div>
        <div class="gg-drops-track" id="gg-drops-track">
          <?php foreach ($ggDropsPackages as $dpkg):
            $dpkgImgSrc = '';
            $dpkgRawImg = trim((string) ($dpkg['imagen_icono'] ?? ''));
            if ($dpkgRawImg === '') {
              $dpkgRawImg = trim((string) ($dpkg['juego_imagen'] ?? ''));
            }
            if ($dpkgRawImg !== '') {
              $dpkgImgSrc = app_path('/' . ltrim($dpkgRawImg, '/'));
            }
            $dpkgGameRow = ['id' => (int) ($dpkg['game_id'] ?? 0), 'slug' => (string) ($dpkg['juego_slug'] ?? '')];
            $dpkgUrl = app_path(game_route_path($dpkgGameRow) . '?package_id=' . (int) ($dpkg['id'] ?? 0));
            $dpkgDescuento = (int) ($dpkg['descuento_destacado'] ?? 0);
            $dpkgPrecio = (float) ($dpkg['precio'] ?? 0);
            $dpkgCurrencyId = (int) ($dpkg['moneda_fija_id'] ?? 0);
            if ($dpkgCurrencyId > 0 && isset($gameCurrencyMap[$dpkgCurrencyId])) {
              $dpkgCurrency = $gameCurrencyMap[$dpkgCurrencyId];
              $dpkgConverted = currency_convert_from_base($dpkgPrecio, $dpkgCurrency);
              $dpkgPriceLabel = strtoupper($dpkgCurrency['clave']) . ' ' . currency_format_amount($dpkgConverted, $dpkgCurrency);
              if ($dpkgDescuento > 0 && $dpkgDescuento < 100) {
                $dpkgOriginal = $dpkgPrecio / (1 - $dpkgDescuento / 100);
                $dpkgOriginalConverted = currency_convert_from_base($dpkgOriginal, $dpkgCurrency);
                $dpkgOriginalLabel = strtoupper($dpkgCurrency['clave']) . ' ' . currency_format_amount($dpkgOriginalConverted, $dpkgCurrency);
              } else {
                $dpkgOriginalLabel = '';
              }
            } else {
              $dpkgPriceLabel = '$ ' . number_format($dpkgPrecio, 2);
              if ($dpkgDescuento > 0 && $dpkgDescuento < 100) {
                $dpkgOriginal = $dpkgPrecio / (1 - $dpkgDescuento / 100);
                $dpkgOriginalLabel = '$ ' . number_format($dpkgOriginal, 2);
              } else {
                $dpkgOriginalLabel = '';
              }
            }
          ?>
            <a href="<?= htmlspecialchars($dpkgUrl, ENT_QUOTES, 'UTF-8') ?>" class="gg-drops-card">
              <div class="gg-drops-card-top">
                <div class="gg-drops-thumb">
                  <?php if ($dpkgImgSrc !== ''): ?>
                    <img src="<?= htmlspecialchars($dpkgImgSrc, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars((string) ($dpkg['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                         loading="lazy">
                  <?php else: ?>
                    <span style="font-size:1.8rem;">&#127918;</span>
                  <?php endif; ?>
                </div>
                <div class="gg-drops-info">
                  <div class="gg-drops-game-label"><?= htmlspecialchars((string) ($dpkg['juego_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="gg-drops-pkg-name"><?= htmlspecialchars((string) ($dpkg['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
              </div>
              <div class="gg-drops-price-row">
                <?php if ($dpkgOriginalLabel !== ''): ?>
                  <span class="gg-drops-price-original"><?= htmlspecialchars($dpkgOriginalLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <span class="gg-drops-price-actual"><?= htmlspecialchars($dpkgPriceLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($dpkgDescuento > 0): ?>
                  <span class="gg-drops-badge">-<?= $dpkgDescuento ?>%</span>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <script>
        (function () {
          var track = document.getElementById('gg-drops-track');
          var btnPrev = document.getElementById('gg-drops-prev');
          var btnNext = document.getElementById('gg-drops-next');
          if (!track || !btnPrev || !btnNext) return;
          var scrollAmount = 232;
          btnPrev.addEventListener('click', function () {
            track.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
          });
          btnNext.addEventListener('click', function () {
            track.scrollBy({ left: scrollAmount, behavior: 'smooth' });
          });
        })();
        </script>
      </section>
      <?php endif; ?>

      <section class="mt-5" data-aos="fade-up">
        <div class="d-flex align-items-center justify-content-between">
          <h2 class="fw-bold" style="font-family:'Oxanium',sans-serif;font-size:1.1rem;">Juegos populares</h2>
          <a href="<?= htmlspecialchars(app_path('/populares'), ENT_QUOTES, 'UTF-8') ?>" class="small fw-semibold text-info text-uppercase">Ver todo</a>
        </div>
        <div class="mt-4 row row-cols-3 row-cols-sm-3 row-cols-lg-4 g-2 g-sm-3">
          <?php foreach ($popularGames as $game): ?>
            <?php $gameSticker = game_sticker_from_row($game); ?>
            <div class="col">
              <a href="<?= htmlspecialchars(app_path(game_route_path($game)), ENT_QUOTES, 'UTF-8') ?>" class="store-game-card d-block rounded-4 border bg-dark p-2 h-100 text-decoration-none">
                <div class="position-relative rounded-3" style="aspect-ratio:1/1;overflow:visible;">
                  <div class="overflow-hidden rounded-3 w-100 h-100" style="position:absolute;inset:0;">
                    <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) ($game['imagen'] ?? ''), '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($game['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="img-fluid w-100 h-100 object-fit-cover" style="aspect-ratio:1/1;" />
                  </div>
                  <span title="Popular" class="position-absolute top-0 end-0 text-success fs-4" style="text-shadow:0 0 4px #000;z-index:4;">★</span>
                  <?= game_sticker_render($gameSticker) ?>
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
        <section class="mt-5 featured-section-mobile" data-aos="fade-up">
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

      <?php if ($showDestSection): ?>
      <style>
        .dest-section-header { display:flex; align-items:center; gap:0.8rem; margin-bottom:1.1rem; }
        .dest-section-icon-emoji { font-size:2.6rem; line-height:1; flex-shrink:0; }
        .dest-section-img { width:60px; height:60px; object-fit:cover; border-radius:12px; flex-shrink:0; }
        .dest-section-title { font-family:'Oxanium',sans-serif; font-size:1.8rem; font-weight:800; color:#fff; margin:0; line-height:1.15; }
        .dest-category-section { padding-bottom:2rem; }
        .dest-sect-vermas-btn { border:1px solid #00fff7; color:#00fff7; background:transparent; min-width:130px; border-radius:8px; }
        @keyframes dest-fadein { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
        .dest-game-col { animation:dest-fadein 0.28s ease both; }
      </style>
      <div class="mt-5" id="destSections">
        <?php if ($todosActivo):
          $dtTodosImg = $todosCategory['imagen'] !== '' && in_array($todosCategory['mostrar_menu'], ['imagen', 'imagen_texto'], true);
        ?>
        <section class="dest-category-section mt-0 mb-5" data-sect-cat="all" data-aos="fade-up">
          <div class="dest-section-header">
            <?php if ($dtTodosImg): ?>
              <h2 class="dest-section-title"><?= htmlspecialchars($todosCategory['nombre'], ENT_QUOTES, 'UTF-8') ?></h2>
              <img class="dest-section-img" src="/<?= htmlspecialchars($todosCategory['imagen'], ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
              <h2 class="dest-section-title"><?= htmlspecialchars($todosCategory['nombre'], ENT_QUOTES, 'UTF-8') ?></h2>
              <?php if ($todosCategory['icono'] !== ''): ?>
                <span class="dest-section-icon-emoji"><?= htmlspecialchars($todosCategory['icono'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="row row-cols-3 row-cols-sm-3 row-cols-lg-4 g-2 g-sm-3 dest-sect-grid"></div>
          <div class="text-center mt-3 dest-sect-vermas-wrap" style="display:none;">
            <button class="btn dest-sect-vermas-btn" type="button">Ver más</button>
          </div>
        </section>
        <?php endif; ?>
        <?php foreach ($destacadaCategories as $dcat):
          $dtUsaImagen = $dcat['imagen'] !== '' && in_array($dcat['mostrar_menu'], ['imagen', 'imagen_texto'], true);
        ?>
        <section class="dest-category-section mb-5" data-sect-cat="<?= (int)$dcat['id'] ?>" data-aos="fade-up">
          <div class="dest-section-header">
            <?php if ($dtUsaImagen): ?>
              <h2 class="dest-section-title"><?= htmlspecialchars($dcat['nombre'], ENT_QUOTES, 'UTF-8') ?></h2>
              <img class="dest-section-img" src="/<?= htmlspecialchars($dcat['imagen'], ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
              <h2 class="dest-section-title"><?= htmlspecialchars($dcat['nombre'], ENT_QUOTES, 'UTF-8') ?></h2>
              <?php if ($dcat['icono'] !== ''): ?>
                <span class="dest-section-icon-emoji"><?= htmlspecialchars($dcat['icono'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="row row-cols-3 row-cols-sm-3 row-cols-lg-4 g-2 g-sm-3 dest-sect-grid"></div>
          <div class="text-center mt-3 dest-sect-vermas-wrap" style="display:none;">
            <button class="btn dest-sect-vermas-btn" type="button">Ver más</button>
          </div>
        </section>
        <?php endforeach; ?>
      </div>
      <script>
      (function () {
        var PAGE = window.innerWidth < 768 ? 6 : 8;
        var allGames = <?= json_encode($gameCardsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var catMap   = <?= json_encode($catGameIdMap,   JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        function esc(s) {
          return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function cardHtml(g, idx) {
          var delay = (idx * 0.04).toFixed(2) + 's';
          var h = '<div class="col dest-game-col" style="animation-delay:' + delay + '">';
          h += '<a href="' + esc(g.url) + '" class="store-game-card d-block rounded-4 border bg-dark p-2 h-100 text-decoration-none">';
          h += '<div class="position-relative rounded-3" style="aspect-ratio:1/1;overflow:visible;">';
          h += '<div class="overflow-hidden rounded-3 w-100 h-100" style="position:absolute;inset:0;">';
          h += '<img src="' + esc(g.imagen_url) + '" alt="' + esc(g.nombre) + '" class="img-fluid w-100 h-100 object-fit-cover" style="aspect-ratio:1/1;">';
          h += '</div>';
          if (g.popular) h += '<span title="Popular" class="position-absolute top-0 end-0 text-success fs-4" style="text-shadow:0 0 4px #000;z-index:4;">★</span>';
          h += (g.sticker_html || '');
          h += '</div><div class="mt-2">';
          h += '<p class="store-game-title fw-semibold d-flex align-items-center mb-1" style="font-size:1rem;">' + esc(g.nombre) + '</p>';
          h += '<p class="store-game-price-prefix small mb-0">';
          if (g.imagen_paquete_url) h += '<img src="' + esc(g.imagen_paquete_url) + '" alt="Paquete" class="img-fluid rounded me-1 align-middle" style="height:20px;width:20px;display:inline-block;">';
          if (g.min_price_label) h += 'Desde <span class="store-game-price">' + esc(g.min_price_label) + '</span>';
          h += '</p></div></a></div>';
          return h;
        }

        function gamesForCat(cat) {
          if (cat === 'all') return allGames;
          var ids = new Set(catMap[cat] || []);
          return allGames.filter(function (g) { return ids.has(g.id); });
        }

        document.querySelectorAll('.dest-category-section').forEach(function (section) {
          var rawCat = section.dataset.sectCat;
          var cat    = rawCat === 'all' ? 'all' : parseInt(rawCat, 10);
          var grid    = section.querySelector('.dest-sect-grid');
          var wrapBtn = section.querySelector('.dest-sect-vermas-wrap');
          var verMas  = section.querySelector('.dest-sect-vermas-btn');
          var list = gamesForCat(cat);
          var page = 0;

          function showMore() {
            var slice = list.slice(page * PAGE, (page + 1) * PAGE);
            var html = '';
            slice.forEach(function (g, i) { html += cardHtml(g, i); });
            grid.insertAdjacentHTML('beforeend', html);
            page++;
            wrapBtn.style.display = (page * PAGE < list.length) ? '' : 'none';
          }

          if (verMas) verMas.addEventListener('click', showMore);
          showMore();
        });
      })();
      </script>
      <?php else: ?>
      <section class="mt-5" data-aos="fade-up">
        <div class="d-flex align-items-center justify-content-between">
          <h2 class="fw-bold" style="font-family:'Oxanium',sans-serif;font-size:1.1rem;">Más juegos</h2>
          <a href="<?= htmlspecialchars(app_path('/juegos'), ENT_QUOTES, 'UTF-8') ?>" class="small fw-semibold text-info text-uppercase">Explorar</a>
        </div>
        <div class="mt-4 row row-cols-3 row-cols-sm-3 row-cols-lg-4 g-2 g-sm-3">
          <?php foreach ($moreGames as $game): ?>
            <?php $gameSticker = game_sticker_from_row($game); ?>
            <div class="col">
              <a href="<?= htmlspecialchars(app_path(game_route_path($game)), ENT_QUOTES, 'UTF-8') ?>" class="store-game-card d-block rounded-4 border bg-dark p-2 h-100 text-decoration-none">
                <div class="position-relative rounded-3" style="aspect-ratio:1/1;overflow:visible;">
                  <div class="overflow-hidden rounded-3 w-100 h-100" style="position:absolute;inset:0;">
                    <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) ($game['imagen'] ?? ''), '/')), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($game['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="img-fluid w-100 h-100 object-fit-cover" style="aspect-ratio:1/1;" />
                  </div>
                  <?php if (!empty($game['popular'])): ?>
                    <span title="Popular" class="position-absolute top-0 end-0 text-success fs-4" style="text-shadow:0 0 4px #000;z-index:4;">★</span>
                  <?php endif; ?>
                  <?= game_sticker_render($gameSticker) ?>
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
      <?php endif; ?>

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

    const createMissionSparks = (container) => {
      const target = container || (missionModalStage && missionModalStage.querySelector('.daily-mission-chest-3d')) || missionChest3d;
      if (!target) return;
      target.querySelectorAll('.daily-mission-modal-spark').forEach(s => s.remove());

      // Flash burst overlay
      const flash = document.createElement('div');
      flash.style.cssText = 'position:absolute;inset:-30px;border-radius:50%;background:radial-gradient(circle,rgba(255,240,160,.95) 0%,rgba(255,200,60,.55) 45%,transparent 75%);pointer-events:none;z-index:30;animation:dm-flash-burst 0.7s ease-out forwards;';
      target.style.position = 'relative';
      target.appendChild(flash);
      setTimeout(() => { try { flash.remove(); } catch(e){} }, 800);

      const emojis = ['💥', '🔥', '✨', '⚡', '🌟', '💫', '⭐'];
      const count = 42;
      for (let i = 0; i < count; i++) {
        const el = document.createElement('div');
        el.className = 'daily-mission-modal-spark';
        el.textContent = emojis[Math.floor(Math.random() * emojis.length)];

        const ang = Math.random() * Math.PI * 2;
        const dist = Math.random() * 340 + 90;
        const x = (Math.cos(ang) * dist) + 'px';
        const y = (Math.sin(ang) * dist - 120) + 'px';

        el.style.setProperty('--x', x);
        el.style.setProperty('--y', y);
        el.style.left = '50%';
        el.style.top = '50%';
        el.style.transform = 'translate(-50%, -50%)';
        const delay = (Math.random() * 0.18).toFixed(2);
        el.style.animation = `estallar 1.3s ${delay}s cubic-bezier(0.08,0.85,0.28,1) forwards`;

        target.appendChild(el);
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

    const galleryViewport = popup.querySelector(".startup-popup-gallery-viewport");
    if (galleryViewport && gallerySlides.length > 1) {
      let swipeStartX = null;
      let swipeStartY = null;
      galleryViewport.addEventListener("touchstart", (e) => {
        if (e.touches.length !== 1) return;
        swipeStartX = e.touches[0].clientX;
        swipeStartY = e.touches[0].clientY;
      }, { passive: true });
      galleryViewport.addEventListener("touchend", (e) => {
        if (swipeStartX === null || e.changedTouches.length !== 1) return;
        const dx = e.changedTouches[0].clientX - swipeStartX;
        const dy = e.changedTouches[0].clientY - swipeStartY;
        swipeStartX = null;
        swipeStartY = null;
        if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) return;
        setGalleryIndex(galleryIndex + (dx < 0 ? 1 : -1));
        startGalleryAutoplay();
      }, { passive: true });
    }

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

        const activeElement = document.activeElement;
        if (offset !== 0 && activeElement && typeof activeElement.blur === "function" && slide.contains(activeElement)) {
          activeElement.blur();
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
      // Remove spinning/open classes and sparks from both modal chest and page chest
      try {
        const modalChest = missionModalStage ? missionModalStage.querySelector('.daily-mission-chest-3d') : null;
        if (modalChest) {
          modalChest.classList.remove('is-spinning', 'is-open', 'cofre', 'girando', 'abierto', 'dm-vid-playing');
          modalChest.querySelectorAll('.daily-mission-modal-spark').forEach(s => s.remove());
          const mVid = modalChest.querySelector('.dm-video');
          if (mVid) { try { mVid.pause(); mVid.currentTime = 0; } catch(e) {} }
        }
      } catch (e) {}
      try {
        if (missionChest3d) {
          missionChest3d.classList.remove('is-spinning');
          missionChest3d.classList.remove('is-open');
          missionChest3d.querySelectorAll('.daily-mission-modal-spark').forEach(s => s.remove());
          missionChest3d.classList.remove('cofre');
          missionChest3d.classList.remove('girando');
          missionChest3d.classList.remove('abierto');
        }
      } catch (e) {}
    };

    const showMissionModal = (result, payload) => {
      if (!missionModal || !missionModalStage) {
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
      // Prefer the chest inside the modal when showing the modal (avoid duplicate-id issues)
      const modalChest = missionModalStage ? missionModalStage.querySelector('.daily-mission-chest-3d') : null;
      const activeChest = modalChest || missionChest3d;
      if (activeChest) activeChest.dataset.level = levelKey;
      if (missionChest3d && missionChest3d !== activeChest) missionChest3d.classList.remove('is-open');

      missionModal.classList.remove("is-hidden");
      missionModal.setAttribute("aria-hidden", "false");

      // Set the animated surprise content inside the modal chest (icon + label)
      try {
        const modalIcon = document.getElementById('daily-mission-modal-icono');
        const modalText = document.getElementById('daily-mission-modal-texto');
        const prizeIconMap = {
          winpoints: '💰',
          coupon: '🏷️',
          immunity: '🛡️',
          streaming_ticket: '🎟️'
        };
        if (modalIcon) modalIcon.textContent = (prize && prize.icon) ? prize.icon : (prizeIconMap[prizeType] || '🎁');
        if (modalText) modalText.textContent = prizeLabel;
      } catch (e) {}

      // Play chest video then reveal prize
      if (activeChest) {
        const dmVidMap = window._dmVidMap || {};
        const video = activeChest.querySelector('.dm-video');
        const vidSrc = dmVidMap[levelKey] || dmVidMap.basic;

        activeChest.classList.remove('is-open', 'abierto', 'dm-vid-playing');

        const revealPrize = () => {
          try { createMissionSparks(activeChest); } catch (e) {}
          activeChest.classList.remove('dm-vid-playing');
          activeChest.classList.add('is-open', 'abierto');
        };

        if (video) {
          video.src = vidSrc;
          video.currentTime = 0;
          activeChest.classList.add('dm-vid-playing');
          const onEnd = () => { video.removeEventListener('ended', onEnd); revealPrize(); };
          video.addEventListener('ended', onEnd);
          video.play().catch(() => { setTimeout(revealPrize, 5500); });
          // Safety fallback if 'ended' never fires
          setTimeout(() => {
            if (!activeChest.classList.contains('abierto')) {
              video.removeEventListener('ended', onEnd);
              try { video.pause(); } catch(e) {}
              revealPrize();
            }
          }, 7000);
        } else {
          setTimeout(revealPrize, 1000);
        }
      }
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
          loginAction.textContent = completed ? "Iniciar Sesión" : "Se activa al iniciar sesión";
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

      if (missionChest3d) missionChest3d.dataset.level = levelKey;
      if (missionChestShell) missionChestShell.dataset.level = levelKey;
      // Also update modal chest level if present
      try {
        const modalChest = missionModalStage ? missionModalStage.querySelector('.daily-mission-chest-3d') : null;
        if (modalChest) modalChest.dataset.level = levelKey;
      } catch (e) {}

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
      if (missionChest3d && !missionChest3d.classList.contains('abierto')) {
        missionChest3d.classList.toggle("is-open", canOpenChest);
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

      if (response.status === 401) {
        throw new Error('unauthenticated');
      }

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

      if (countdown.button) {
        countdown.button.classList.remove("is-counting");
        if (restoreLabel && countdown.button.dataset.originalLabel && !countdown.button.hasAttribute("data-daily-mission-social")) {
          countdown.button.textContent = countdown.button.dataset.originalLabel;
        }
        if (countdown.button.tagName === "BUTTON") {
          countdown.button.disabled = false;
        }
      }
    };

    const startCountdown = (button, taskKey, taskType, timerSeconds, targetUrl, extraBody) => {
      if (!button || !taskKey || missionCountdowns.has(taskKey)) {
        return;
      }

      const isSocialIcon = button.hasAttribute("data-daily-mission-social");
      const originalLabel = button.dataset.originalLabel || button.textContent.trim() || "Procesando";
      const duration = Math.max(1, safeNumber(timerSeconds, 1));
      let remaining = duration;

      button.dataset.originalLabel = originalLabel;
      if (button.tagName === "BUTTON") {
        button.disabled = true;
      }

      if (isSocialIcon) {
        button.classList.add("is-counting");
      }

      if (targetUrl && taskType !== "purchase") {
        openTargetUrl(targetUrl);
      }

      const updateLabel = () => {
        if (!isSocialIcon) {
          button.textContent = originalLabel + " (" + remaining + "s)";
        }
        if (remaining <= 0) {
          clearInterval(timerId);
          missionCountdowns.delete(taskKey);
          if (!isSocialIcon) {
            button.textContent = originalLabel;
          } else {
            button.classList.remove("is-counting");
          }
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
        const clickedChest = actionElement.closest('[data-daily-mission-chest]') || missionChestShell;
        if (!clickedChest || clickedChest.disabled || missionShell.dataset.canOpenChest !== "1") {
          return;
        }

        clickedChest.classList.add("is-spinning");
        clickedChest.disabled = true;
        clickedChest.setAttribute('aria-disabled', 'true');

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
              clickedChest.classList.remove("is-spinning");
              clickedChest.disabled = missionShell.dataset.canOpenChest !== "1";
              if (clickedChest.disabled) {
                clickedChest.setAttribute('aria-disabled', 'true');
              } else {
                clickedChest.removeAttribute('aria-disabled');
              }
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

    if (!parseInt(missionShell.dataset.userId || '0', 10)) {
      return;
    }

    try {
      const _dmBc = new BroadcastChannel('vg_daily_missions');
      _dmBc.onmessage = () => { refreshMissionStatus(); };
    } catch(e) {}

    refreshMissionStatus();
  })();
</script>
SCRIPT
];
$homeGalleryIntervalSeconds = max(1, min(60, (int) store_config_get('home_gallery_interval_seconds', '6')));
$homeGalleryIntervalMs = $homeGalleryIntervalSeconds * 1000;
array_unshift($pageScripts, '<script>window._dmVidMap={basic:' . json_encode(app_path('/assets/video/cofre basico rebor.mp4')) . ',intermediate:' . json_encode(app_path('/assets/video/cofre intermedio.mp4')) . ',legendary:' . json_encode(app_path('/assets/video/cofre legendario rebor.mp4')) . '};</script>');
array_unshift($pageScripts, '<script>window._vgHomeGalleryIntervalMs = ' . $homeGalleryIntervalMs . ';</script>');
if ($rouletteEnabled) {
  $rouletteScriptSections = array_map(fn($s) => [
    'section_order'           => (int) $s['section_order'],
    'prize_type'              => (string) $s['prize_type'],
    'prize_label'             => (string) $s['prize_label'],
    'points_amount'           => (int) $s['points_amount'],
    'coupon_discount_percent' => (int) $s['coupon_discount_percent'],
    'immunity_days'           => (int) $s['immunity_days'],
    'color'                   => (string) $s['color'],
    'icon_emoji'              => (string) $s['icon_emoji'],
    'active'                  => !empty($s['active']),
    'font_size'               => max(5, min(20, (int) ($s['font_size'] ?? 7))),
    'font_color'              => (string) ($s['font_color'] ?? '#ffffff'),
    'icon_image_url' => (string) ($s['icon_image_url'] ?? ''),
    'icon_size'      => (string) ($s['icon_size'] ?? 'medium'),
  ], $rouletteSections);
  array_unshift($pageScripts, '<script>window._rouletteData=' . json_encode([
    'sections'   => $rouletteScriptSections,
    'spin_cost'  => (int) ($rouletteConfig['spin_cost'] ?? 10),
    'balance'    => (int) $rouletteBalance,
    'enabled'    => true,
    'wp_name'    => win_points_program_name(),
    'ring_color' => (string) ($rouletteConfig['ring_color'] ?? '#6366f1'),
    'ring_width' => (int)    ($rouletteConfig['ring_width'] ?? 2),
    'ring_neon'         => !empty($rouletteConfig['ring_neon']),
    'center_emoji'     => (string) ($rouletteConfig['center_emoji'] ?? '⭐'),
    'center_image_url' => (string) ($rouletteConfig['center_image_url'] ?? ''),
    'center_size'      => (string) ($rouletteConfig['center_size'] ?? 'medium'),
    'btn_emoji'        => (string) ($rouletteConfig['btn_emoji'] ?? '🎰'),
    'btn_image_url'    => (string) ($rouletteConfig['btn_image_url'] ?? ''),
    'btn_image_size'   => (string) ($rouletteConfig['btn_image_size'] ?? 'medium'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>');
  $pageScripts[] = <<<'RLTSCRIPT'
<script>
(function () {
  'use strict';

  const data = window._rouletteData;
  if (!data || !data.enabled) return;

  const svgEl        = document.getElementById('roulette-wheel-group');
  const svgWrap      = document.getElementById('roulette-svg-wrap');
  const spinBtn      = document.getElementById('roulette-spin-btn');
  const spinIcon     = document.getElementById('roulette-btn-icon');
  const msgEl        = document.getElementById('roulette-msg');
  const balanceEl    = document.getElementById('roulette-balance-val');
  const modal        = document.getElementById('roulette-prize-modal');
  const modalIcon    = document.getElementById('roulette-modal-icon');
  const modalLabel   = document.getElementById('roulette-modal-label');
  const modalDesc    = document.getElementById('roulette-modal-desc');
  const modalCoupon  = document.getElementById('roulette-modal-coupon');
  const modalCouponCode = document.getElementById('roulette-modal-coupon-code');
  const modalOk      = document.getElementById('roulette-modal-ok');
  const modalClose   = document.getElementById('roulette-modal-close-btn');
  const modalBackdrop= document.getElementById('roulette-modal-backdrop');

  if (!svgEl || !svgWrap) return;

  const N = 8;
  const R = 120;
  const CX = 120, CY = 120;
  const TAU = Math.PI * 2;
  let currentRotation = 0;
  let isSpinning = false;

  // ─── Draw wheel ───────────────────────────────────────────────
  const svgNS = 'http://www.w3.org/2000/svg';
  function drawWheel(sections) {
    svgEl.innerHTML = '';
    const ringColor = data.ring_color || '#6366f1';
    const ringWidth = Math.max(1, parseInt(data.ring_width || 2, 10));
    const neon      = !!data.ring_neon;
    const strokeColor = ringColor;
    const strokeWidth = neon ? Math.max(1.5, ringWidth * 0.6) : 1.0;

    // SVG neon filter
    if (neon) {
      const defs = document.createElementNS(svgNS, 'defs');
      defs.innerHTML = `<filter id="rlt-neon" x="-30%" y="-30%" width="160%" height="160%">
        <feGaussianBlur in="SourceGraphic" stdDeviation="2.5" result="blur"/>
        <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>`;
      svgEl.appendChild(defs);
    }

    sections.forEach((sec, i) => {
      const startAng = (i / N) * TAU - Math.PI / 2;
      const endAng   = ((i + 1) / N) * TAU - Math.PI / 2;
      const x1 = CX + R * Math.cos(startAng);
      const y1 = CY + R * Math.sin(startAng);
      const x2 = CX + R * Math.cos(endAng);
      const y2 = CY + R * Math.sin(endAng);

      const path = document.createElementNS(svgNS, 'path');
      path.setAttribute('d', `M ${CX} ${CY} L ${x1.toFixed(2)} ${y1.toFixed(2)} A ${R} ${R} 0 0 1 ${x2.toFixed(2)} ${y2.toFixed(2)} Z`);
      const segColor = sec.color === 'transparent' ? 'transparent' : (sec.color || '#22d3ee');
      path.setAttribute('fill', segColor);
      path.setAttribute('fill-opacity', i % 2 === 0 ? '1' : '0.82');
      path.setAttribute('stroke', strokeColor);
      path.setAttribute('stroke-width', String(strokeWidth));
      if (neon) path.setAttribute('filter', 'url(#rlt-neon)');
      svgEl.appendChild(path);

      const midAng = (startAng + endAng) / 2;
      const lr = R * 0.62;
      const tx = CX + lr * Math.cos(midAng);
      const ty = CY + lr * Math.sin(midAng);
      const rotateDeg = (midAng * 180 / Math.PI) + 90;

      // Custom image or emoji
      const imgUrl = (sec.icon_image_url || '').trim();
      if (imgUrl) {
        const imgSize = sec.icon_size === 'small' ? 12 : sec.icon_size === 'large' ? 24 : 18;
        const imgEl = document.createElementNS(svgNS, 'image');
        imgEl.setAttribute('href', imgUrl);
        imgEl.setAttribute('x', (tx - imgSize / 2).toFixed(2));
        imgEl.setAttribute('y', (ty - 7 - imgSize / 2).toFixed(2));
        imgEl.setAttribute('width', String(imgSize));
        imgEl.setAttribute('height', String(imgSize));
        imgEl.setAttribute('transform', `rotate(${rotateDeg.toFixed(1)}, ${tx.toFixed(2)}, ${ty.toFixed(2)})`);
        svgEl.appendChild(imgEl);
      } else {
        const emojiEl = document.createElementNS(svgNS, 'text');
        emojiEl.setAttribute('x', tx.toFixed(2));
        emojiEl.setAttribute('y', (ty - 7).toFixed(2));
        emojiEl.setAttribute('text-anchor', 'middle');
        emojiEl.setAttribute('dominant-baseline', 'middle');
        emojiEl.setAttribute('font-size', '16');
        emojiEl.setAttribute('transform', `rotate(${rotateDeg.toFixed(1)}, ${tx.toFixed(2)}, ${ty.toFixed(2)})`);
        emojiEl.textContent = sec.icon_emoji || '🎁';
        svgEl.appendChild(emojiEl);
      }

      // Label with configurable font_size and font_color
      const labelR = R * 0.83;
      const lx = CX + labelR * Math.cos(midAng);
      const ly = CY + labelR * Math.sin(midAng);
      const labelEl = document.createElementNS(svgNS, 'text');
      labelEl.setAttribute('x', lx.toFixed(2));
      labelEl.setAttribute('y', ly.toFixed(2));
      labelEl.setAttribute('text-anchor', 'middle');
      labelEl.setAttribute('dominant-baseline', 'middle');
      labelEl.setAttribute('font-size', String(sec.font_size || 7));
      labelEl.setAttribute('fill', sec.font_color || '#ffffff');
      labelEl.setAttribute('font-weight', '800');
      labelEl.setAttribute('font-family', 'Oxanium, sans-serif');
      labelEl.setAttribute('transform', `rotate(${rotateDeg.toFixed(1)}, ${lx.toFixed(2)}, ${ly.toFixed(2)})`);
      const lbl = sec.prize_label || '';
      labelEl.textContent = lbl.length > 11 ? lbl.substring(0, 10) + '…' : lbl;
      svgEl.appendChild(labelEl);
    });

    // Outer ring with neon support
    const ringEl = document.createElementNS(svgNS, 'circle');
    ringEl.setAttribute('cx', CX); ringEl.setAttribute('cy', CY);
    ringEl.setAttribute('r', R - 0.5); ringEl.setAttribute('fill', 'none');
    ringEl.setAttribute('stroke', ringColor); ringEl.setAttribute('stroke-width', String(ringWidth));
    ringEl.setAttribute('stroke-opacity', neon ? '1' : '0.7');
    if (neon) ringEl.setAttribute('filter', 'url(#rlt-neon)');
    svgEl.appendChild(ringEl);

    // CSS ring glow
    const cssRing = document.querySelector('.roulette-wheel-ring');
    if (cssRing) {
      cssRing.style.border = `${ringWidth}px solid ${ringColor}`;
      if (neon) {
        cssRing.style.boxShadow = `0 0 12px 3px ${ringColor}, 0 0 40px ${ringColor}88, inset 0 0 20px ${ringColor}44`;
      } else {
        cssRing.style.boxShadow = `0 0 20px ${ringColor}55, inset 0 0 20px ${ringColor}22`;
      }
    }

    // Center circle — size driven by config (small=12, medium=20, large=30)
    const CENTER_R = data.center_size === 'small' ? 12 : data.center_size === 'large' ? 30 : 20;
    const centEl = document.createElementNS(svgNS, 'circle');
    centEl.setAttribute('cx', CX); centEl.setAttribute('cy', CY);
    centEl.setAttribute('r', String(CENTER_R)); centEl.setAttribute('fill', '#060c1c');
    centEl.setAttribute('stroke', ringColor); centEl.setAttribute('stroke-width', '2');
    svgEl.appendChild(centEl);

    const centerImgSrc = (data.center_image_url || '').trim();
    if (centerImgSrc) {
      // Inject clipPath into defs (or create defs if only neon was off)
      let defs = svgEl.querySelector('defs');
      if (!defs) { defs = document.createElementNS(svgNS, 'defs'); svgEl.insertBefore(defs, svgEl.firstChild); }
      const clipEl = document.createElementNS(svgNS, 'clipPath');
      clipEl.setAttribute('id', 'rlt-center-clip');
      const clipCirc = document.createElementNS(svgNS, 'circle');
      clipCirc.setAttribute('cx', CX); clipCirc.setAttribute('cy', CY); clipCirc.setAttribute('r', String(CENTER_R - 1));
      clipEl.appendChild(clipCirc);
      defs.appendChild(clipEl);

      const imgEl = document.createElementNS(svgNS, 'image');
      imgEl.setAttribute('href', centerImgSrc);
      imgEl.setAttribute('x', CX - CENTER_R + 1);
      imgEl.setAttribute('y', CY - CENTER_R + 1);
      imgEl.setAttribute('width', String((CENTER_R - 1) * 2));
      imgEl.setAttribute('height', String((CENTER_R - 1) * 2));
      imgEl.setAttribute('preserveAspectRatio', 'xMidYMid slice');
      imgEl.setAttribute('clip-path', 'url(#rlt-center-clip)');
      svgEl.appendChild(imgEl);
    } else {
      const starEl = document.createElementNS(svgNS, 'text');
      starEl.setAttribute('x', CX); starEl.setAttribute('y', CY);
      starEl.setAttribute('text-anchor', 'middle'); starEl.setAttribute('dominant-baseline', 'middle');
      starEl.setAttribute('font-size', '14');
      starEl.textContent = (data.center_emoji || '').trim() || '⭐';
      svgEl.appendChild(starEl);
    }
  }

  drawWheel(data.sections);

  // ─── Idle animation: vaivén lento → rotación completa → repite ───
  let idleAnimId    = null;
  let idleStartTime = null;
  let idlePhase     = 'sway';  // 'sway' | 'fullspin'
  let idleBaseAngle = 0;       // ángulo extra acumulado durante el idle

  const IDLE_SWAY_AMPLITUDE = 4;     // grados de oscilación izq/der
  const IDLE_SWAY_PERIOD    = 5000;  // ms por ciclo completo (izq→der→izq)
  const IDLE_SWAY_CYCLES    = 3;     // cuántos ciclos antes de girar completo
  const IDLE_SPIN_DURATION  = 7000;  // ms para la rotación completa de 360°

  function tickIdle(ts) {
    if (!idleStartTime) idleStartTime = ts;
    const elapsed = ts - idleStartTime;
    let extra;

    if (idlePhase === 'sway') {
      const t = (elapsed % IDLE_SWAY_PERIOD) / IDLE_SWAY_PERIOD;
      extra = idleBaseAngle + IDLE_SWAY_AMPLITUDE * Math.sin(2 * Math.PI * t);
      if (elapsed >= IDLE_SWAY_PERIOD * IDLE_SWAY_CYCLES) {
        idlePhase     = 'fullspin';
        idleStartTime = ts;
      }
    } else {
      const p     = Math.min(elapsed / IDLE_SPIN_DURATION, 1);
      const eased = p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2;
      extra = idleBaseAngle + 360 * eased;
      if (p >= 1) {
        idleBaseAngle += 360;
        idlePhase      = 'sway';
        idleStartTime  = ts;
      }
    }

    svgEl.style.transition = 'none';
    svgEl.style.transform  = `rotate(${currentRotation + extra}deg)`;
    idleAnimId = requestAnimationFrame(tickIdle);
  }

  function startIdle() {
    if (idleAnimId) return;
    svgEl.style.transformOrigin = CX + 'px ' + CY + 'px';
    idleStartTime = null;
    idlePhase     = 'sway';
    idleBaseAngle = 0;
    idleAnimId    = requestAnimationFrame(tickIdle);
  }
  function stopIdle() {
    if (idleAnimId) { cancelAnimationFrame(idleAnimId); idleAnimId = null; }
    idleBaseAngle = 0;
    idleStartTime = null;
  }
  startIdle();

  // ─── Spin animation ────────────────────────────────────────────
  function spinTo(winnerIndex, onDone) {
    stopIdle();
    const sectorAng   = (360 - ((winnerIndex * 45 + 22.5) % 360)) % 360;
    const currAng     = ((currentRotation % 360) + 360) % 360;
    let   diff        = ((sectorAng - currAng) + 360) % 360;
    if (diff < 1) diff = 360;
    const finalRot    = currentRotation + 5 * 360 + diff;

    svgEl.style.transformOrigin = CX + 'px ' + CY + 'px';
    svgEl.style.transition = 'none';
    void svgWrap.offsetWidth;
    svgEl.style.transition = 'transform 4.6s cubic-bezier(0.17,0.67,0.18,0.99)';
    svgEl.style.transform  = `rotate(${finalRot}deg)`;
    currentRotation = finalRot;

    const timeout = setTimeout(() => { onDone(); startIdle(); }, 4700);
    svgEl.addEventListener('transitionend', function handler() {
      clearTimeout(timeout);
      svgEl.removeEventListener('transitionend', handler);
      onDone();
      startIdle();
    }, { once: true });
  }

  // ─── Prize modal ──────────────────────────────────────────────
  function showPrizeModal(result) {
    if (!modal) return;
    const icons = { winpoints: '🪙', coupon: '🎟️', immunity: '🛡️', streaming_ticket: '🎬' };
    const icon = result.icon_emoji || icons[result.prize_type] || '🎁';
    modalIcon.textContent = icon;
    modalLabel.textContent = result.prize_label || '¡Premio!';

    let desc = '';
    if (result.prize_type === 'winpoints' && result.points_amount > 0) {
      desc = '+' + result.points_amount + ' ' + (data.wp_name || 'Win Points') + ' añadidos a tu saldo.';
    } else if (result.prize_type === 'coupon' && result.coupon_discount_percent > 0) {
      desc = 'Descuento del ' + result.coupon_discount_percent + '% en tu próxima compra.';
    } else if (result.prize_type === 'immunity' && result.immunity_days > 0) {
      desc = result.immunity_days + ' día(s) de protección de racha añadidos.';
    } else if (result.prize_type === 'streaming_ticket') {
      desc = 'Ticket de streaming asignado. El admin lo gestionará pronto.';
    }
    modalDesc.textContent = desc;

    if (result.coupon_code && result.prize_type === 'coupon') {
      modalCoupon.style.display = '';
      modalCouponCode.textContent = result.coupon_code;
    } else {
      modalCoupon.style.display = 'none';
    }

    modal.classList.remove('is-hidden');
    modal.removeAttribute('aria-hidden');
    burstSparks();
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.add('is-hidden');
    modal.setAttribute('aria-hidden', 'true');
  }

  if (modalOk) modalOk.addEventListener('click', closeModal);
  if (modalClose) modalClose.addEventListener('click', closeModal);
  if (modalBackdrop) modalBackdrop.addEventListener('click', closeModal);
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && modal && !modal.classList.contains('is-hidden')) closeModal();
  });

  // ─── Sparks ───────────────────────────────────────────────────
  function burstSparks() {
    const emojis = ['✨', '🌟', '💫', '⭐', '🎉', '🎊', '💥', '🔥'];
    const cx = window.innerWidth / 2, cy = window.innerHeight / 2;
    for (let i = 0; i < 28; i++) {
      const el = document.createElement('div');
      el.className = 'rlt-spark';
      el.textContent = emojis[Math.floor(Math.random() * emojis.length)];
      const ang = Math.random() * Math.PI * 2;
      const dist = Math.random() * 280 + 80;
      el.style.setProperty('--sx', (Math.cos(ang) * dist) + 'px');
      el.style.setProperty('--sy', (Math.sin(ang) * dist - 80) + 'px');
      el.style.left = cx + 'px';
      el.style.top  = cy + 'px';
      el.style.animationDelay = (Math.random() * 0.15) + 's';
      document.body.appendChild(el);
      setTimeout(() => el.remove(), 1400);
    }
  }

  // ─── Spin button ──────────────────────────────────────────────
  function setMsg(text, isOk) {
    if (!msgEl) return;
    msgEl.textContent = text;
    msgEl.className = 'roulette-msg' + (isOk ? ' ok' : '');
  }

  function setSpinning(on) {
    isSpinning = on;
    if (!spinBtn) return;
    spinBtn.disabled = on;
    if (spinIcon) spinIcon.style.animation = on ? 'rlt-spin-icon 0.5s linear infinite' : '';
  }

  if (spinBtn) {
    const apiUrl = spinBtn.dataset.apiUrl || '/api/roulette.php';

    spinBtn.addEventListener('click', () => {
      if (isSpinning) return;

      const curBalance = parseInt(balanceEl ? balanceEl.textContent : '0', 10) || 0;
      const spinCost   = parseInt(spinBtn.dataset.spinCost || '10', 10);
      if (curBalance < spinCost) {
        setMsg('No tienes suficientes puntos para girar. Necesitas ' + spinCost + '.', false);
        return;
      }

      setSpinning(true);
      setMsg('', false);

      const fd = new FormData();
      fd.append('action', 'spin');

      fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
          if (!data.ok) {
            setMsg(data.message || 'No se pudo girar la ruleta.', false);
            setSpinning(false);
            return;
          }
          const result = data.result;
          // Animate to winning section
          const winIdx = (result.section_order || 1) - 1;

          spinTo(winIdx, () => {
            setSpinning(false);
            if (balanceEl && data.payload && data.payload.balance !== undefined) {
              balanceEl.textContent = data.payload.balance;
            }
            showPrizeModal(result);
          });
        })
        .catch(() => {
          setMsg('Error de conexión. Inténtalo de nuevo.', false);
          setSpinning(false);
        });
    });
  }
})();
</script>
RLTSCRIPT;
}
?>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>AOS.init({duration:750,easing:'ease-out-cubic',once:true,offset:60});</script>
<?php
include __DIR__ . "/includes/footer.php";
?>
