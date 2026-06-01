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
foreach ($galleryItems as $item) {
  $banners[] = [
    'label' => $item['titulo'],
    'title' => $item['descripcion1'],
    'subtitle' => $item['descripcion2'],
    'image' => $item['imagen'],
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
    'image' => $galleryFeatured['imagen'],
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
        }
        .promo-slider-track {
          display: flex;
          gap: 0.75rem;
          overflow-x: auto;
          scroll-snap-type: x mandatory;
          scroll-behavior: smooth;
          scrollbar-width: none;
          -ms-overflow-style: none;
          overscroll-behavior-x: contain;
          touch-action: pan-x pinch-zoom;
        }
        .promo-slider-track::-webkit-scrollbar {
          display: none;
        }
        .promo-slide-card {
          position: relative;
          flex-shrink: 0;
          width: 100%;
          min-width: 82%;
          aspect-ratio: 1280 / 500;
          overflow: hidden;
          border-radius: 1.5rem;
          scroll-snap-align: start;
          background: rgba(8, 15, 24, 0.88);
        }
        .promo-slide-image,
        .featured-banner-image {
          width: 100%;
          height: 100%;
          object-fit: contain;
          object-position: center center;
          display: block;
        }
        .promo-slide-overlay,
        .featured-banner-overlay {
          position: absolute;
          inset: 0;
          background: transparent;
        }
        .promo-slide-content,
        .featured-banner-content {
          position: absolute;
          inset: 0;
          display: flex;
          flex-direction: column;
          justify-content: center;
          padding-inline: 1.5rem;
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
          .promo-slider-track {
            gap: 0;
          }
          .promo-slide-card,
          .featured-banner-card {
            min-width: 100%;
            width: 100%;
            border-radius: 0;
          }
          .promo-slide-card,
          .featured-banner-image {
            aspect-ratio: 1280 / 500;
          }
          .promo-slide-content,
          .featured-banner-content {
            padding-inline: 1rem;
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
            <div id="promo-slider" class="promo-slider-track">
              <?php foreach ($banners as $banner): ?>
                <?php
                  $accent = $banner["accent"] ?? "cyan";
                  $labelClass = $accentMap[$accent]["label"] ?? $accentMap["cyan"]["label"];
                  $gradientClass = $accentMap[$accent]["gradient"] ?? $accentMap["cyan"]["gradient"];
                  $bannerUrl = trim((string) ($banner['url'] ?? ''));
                  $bannerTarget = !empty($banner['open_in_new_tab']) ? '_blank' : '_self';
                ?>
                <<?= $bannerUrl !== '' ? 'a' : 'article' ?> class="promo-slide-card text-decoration-none"<?= $bannerUrl !== '' ? ' href="' . htmlspecialchars($bannerUrl, ENT_QUOTES, 'UTF-8') . '" target="' . htmlspecialchars($bannerTarget, ENT_QUOTES, 'UTF-8') . '"' . ($bannerTarget === '_blank' ? ' rel="noopener noreferrer"' : '') : '' ?>>
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
            <div class="position-absolute top-0 start-0 end-0 h-100 d-none d-md-flex align-items-center justify-content-between" style="pointer-events:none;">
              <button type="button" class="btn btn-outline-info rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px;pointer-events:auto;background:rgba(34,211,238,0.15);border:2px solid #22d3ee;color:#22d3ee;position:relative;z-index:2;" data-action="prev" aria-label="Anterior">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-chevron-left" viewBox="0 0 16 16">
                  <path fill-rule="evenodd" d="M11.354 1.646a.5.5 0 0 1 0 .708L5.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z"/>
                </svg>
              </button>
              <button type="button" class="btn btn-outline-info rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px;pointer-events:auto;background:rgba(34,211,238,0.15);border:2px solid #22d3ee;color:#22d3ee;position:relative;z-index:2;" data-action="next" aria-label="Siguiente">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-chevron-right" viewBox="0 0 16 16">
                  <path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/>
                </svg>
              </button>
            </div>
            <p class="mt-2 small text-secondary">Desliza para ver más promociones</p>
          </div>
        </section>
      <?php endif; ?>

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
  const dots = Array.from(document.querySelectorAll("#promo-dots [data-index]"));
  const slides = Array.from(slider.children);
  if (!slides.length) {
    return;
  }

  let currentIndex = 0;
  let scrollTimeout;
  let autoplayId;
  let isPaused = false;
  let touchStartX = null;
  let lastTouchX = null;

  const normalizeIndex = (index) => {
    const total = slides.length;
    return total ? ((index % total) + total) % total : 0;
  };

  const setActiveDot = (index) => {
    currentIndex = normalizeIndex(index);
    dots.forEach((dot, idx) => {
      const isActive = idx === currentIndex;
      dot.classList.toggle("is-active", isActive);
      dot.setAttribute("aria-current", isActive ? "true" : "false");
    });
  };

  const getClosestIndex = () => {
    let closestIndex = 0;
    let closestDistance = Infinity;

    slides.forEach((slide, index) => {
      const distance = Math.abs(slider.scrollLeft - slide.offsetLeft);
      if (distance < closestDistance) {
        closestDistance = distance;
        closestIndex = index;
      }
    });

    return closestIndex;
  };

  const scrollToIndex = (index, behavior = "smooth") => {
    const targetIndex = normalizeIndex(index);
    const target = slides[targetIndex];
    if (target) {
      slider.scrollTo({ left: target.offsetLeft, behavior });
      setActiveDot(targetIndex);
    }
  };

  slider.addEventListener("scroll", () => {
    window.clearTimeout(scrollTimeout);
    scrollTimeout = window.setTimeout(() => {
      setActiveDot(getClosestIndex());
    }, 70);
  });

  dots.forEach((dot) => {
    dot.addEventListener("click", () => {
      scrollToIndex(Number(dot.dataset.index));
    });
  });

  document.querySelectorAll("[data-action]").forEach((button) => {
    button.addEventListener("click", () => {
      const nextIndex = button.dataset.action === "next" ? currentIndex + 1 : currentIndex - 1;
      scrollToIndex(nextIndex);
    });
  });

  const startAutoplay = () => {
    if (autoplayId || slides.length <= 1) return;
    autoplayId = window.setInterval(() => {
      if (isPaused) return;
      scrollToIndex(currentIndex + 1);
    }, 4500);
  };

  slider.addEventListener("mouseenter", () => {
    isPaused = true;
  });

  slider.addEventListener("mouseleave", () => {
    isPaused = false;
  });

  slider.addEventListener("touchstart", (event) => {
    isPaused = true;
    touchStartX = event.changedTouches[0]?.clientX ?? null;
    lastTouchX = touchStartX;
  }, { passive: true });

  slider.addEventListener("touchmove", (event) => {
    lastTouchX = event.changedTouches[0]?.clientX ?? lastTouchX;
  }, { passive: true });

  slider.addEventListener("touchend", () => {
    isPaused = false;
    if (touchStartX !== null && lastTouchX !== null) {
      const deltaX = touchStartX - lastTouchX;
      if (Math.abs(deltaX) >= 40) {
        if (currentIndex === slides.length - 1 && deltaX > 0) {
          scrollToIndex(0);
        } else if (currentIndex === 0 && deltaX < 0) {
          scrollToIndex(slides.length - 1);
        }
      }
    }
    touchStartX = null;
    lastTouchX = null;
  });

  slider.addEventListener("touchcancel", () => {
    isPaused = false;
    touchStartX = null;
    lastTouchX = null;
  });

  slider.addEventListener("focusin", () => {
    isPaused = true;
  });

  slider.addEventListener("focusout", () => {
    isPaused = false;
  });

  const observer = new IntersectionObserver((entries) => {
    let bestIndex = null;
    let bestRatio = 0;

    entries.forEach((entry) => {
      if (!entry.isIntersecting) {
        return;
      }
      const index = slides.indexOf(entry.target);
      if (index !== -1 && entry.intersectionRatio > bestRatio) {
        bestRatio = entry.intersectionRatio;
        bestIndex = index;
      }
    });

    if (bestIndex !== null) {
      setActiveDot(bestIndex);
    }
  }, {
    root: slider,
    threshold: [0.55, 0.75, 0.95]
  });

  slides.forEach((slide) => observer.observe(slide));

  if (dots.length) {
    setActiveDot(0);
  }
  scrollToIndex(0, "auto");
  startAutoplay();
  })();
</script>
SCRIPT
];
include __DIR__ . "/includes/footer.php";
?>
