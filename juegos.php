
<?php
require_once __DIR__ . "/includes/db_connect.php";
require_once __DIR__ . "/includes/currency.php";
require_once __DIR__ . "/includes/slugify.php";
require_once __DIR__ . "/includes/package_features.php";
require_once __DIR__ . "/includes/game_sticker.php";
currency_ensure_schema();
game_sticker_ensure_schema($mysqli);
$pageTitle = "TVirtualGaming | Juegos";
include __DIR__ . "/includes/header.php";

$res = $mysqli->query("SELECT * FROM juegos WHERE COALESCE(activo, 1) = 1 ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC");
$allGames = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
?>

<style>
  .store-game-card {
    transition: transform 0.35s ease;
  }

  .store-game-card:hover {
    transform: translateY(-6px);
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

  .game-sticker-text,
  .game-sticker-icon,
  .game-sticker-img {
    position: relative;
    z-index: 1;
  }

  .game-sticker-icon {
    font-style: normal;
    line-height: 1;
  }

  .game-sticker-img {
    width: 1.1rem;
    height: 1.1rem;
    object-fit: contain;
  }
</style>


<section class="mt-6">
  <div class="flex items-center justify-between">
    <div>
      <p class="text-xs uppercase tracking-[0.35em] text-cyan-300/70">catálogo</p>
      <h2 class="game-section-title">Todos los juegos</h2>
    </div>
    <a href="<?= htmlspecialchars(app_path('/populares'), ENT_QUOTES, 'UTF-8') ?>" class="text-xs font-semibold uppercase tracking-wide text-cyan-300">Ver populares</a>
  </div>
  <div class="mt-4 row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
    <?php foreach ($allGames as $game): ?>
      <?php
        $resPaqCount = $mysqli->query("SELECT COUNT(*) as total FROM juego_paquetes WHERE juego_id=" . intval($game['id']) . " AND COALESCE(activo, 1) = 1");
        $paqCount = $resPaqCount ? $resPaqCount->fetch_assoc()['total'] : 0;
        if ($paqCount == 0) continue;
        $gameSticker = game_sticker_from_row($game);
      ?>
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
            <p class="fw-semibold d-flex align-items-center mb-1" style="font-size:1rem;">
              <?= htmlspecialchars($game['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </p>
            <p class="small text-secondary mb-0">
              <?php if (!empty($game['imagen_paquete'])): ?>
                <img src="<?= htmlspecialchars(app_path('/' . ltrim((string) $game['imagen_paquete'], '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Paquete" class="img-fluid rounded me-1 align-middle" style="height:20px;width:20px;display:inline-block;" />
              <?php endif; ?>
              <?php
                $min_precio_bs = null;
                if (!empty($game['moneda_fija_id'])) {
                  $resPaq = $mysqli->query("SELECT precio FROM juego_paquetes WHERE juego_id=" . intval($game['id']) . " AND COALESCE(activo, 1) = 1 ORDER BY CASE WHEN orden IS NULL THEN 1 ELSE 0 END, orden ASC, id ASC LIMIT 1");
                  $paq = $resPaq ? $resPaq->fetch_assoc() : null;
                  if ($paq) {
                    $resMon = $mysqli->query("SELECT tasa, clave, mostrar_decimales FROM monedas WHERE id=" . intval($game['moneda_fija_id']) . " LIMIT 1");
                    $mon = $resMon ? $resMon->fetch_assoc() : null;
                    if ($mon) {
                      $min_precio_bs = currency_convert_from_base((float) $paq['precio'], $mon);
                    }
                  }
                }
              ?>
              <?php if ($min_precio_bs !== null && isset($mon['clave'])): ?>
                Desde <span class="text-info"><?= htmlspecialchars(strtoupper($mon['clave'])) ?> <?= currency_format_amount((float) $min_precio_bs, $mon) ?></span>
              <?php endif; ?>
            </p>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php include __DIR__ . "/includes/footer.php"; ?>
