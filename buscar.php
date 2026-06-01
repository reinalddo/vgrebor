<?php

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/public_catalog_search.php';

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchResults = public_catalog_search_results($mysqli, $searchQuery, 60);
$games = $searchResults['games'];
$packages = $searchResults['packages'];
$pageTitle = $searchQuery !== '' ? 'Resultados para ' . $searchQuery : 'Buscar';

require_once __DIR__ . '/includes/header.php';
?>

<section class="mt-4">
  <div class="theme-panel rounded-4 p-4 p-lg-5">
    <div class="d-flex flex-column gap-2">
      <p class="small text-uppercase text-info mb-0" style="letter-spacing:0.24em;">Buscador</p>
      <h1 class="fw-bold mb-0" style="font-family:'Oxanium',sans-serif;">
        <?= $searchQuery !== '' ? 'Resultados para "' . htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') . '"' : 'Explora juegos y paquetes' ?>
      </h1>
      <p class="text-secondary mb-0">Selecciona un juego o un paquete para ir directo a su ficha.</p>
    </div>
  </div>
</section>

<section class="mt-4">
  <div class="row g-4">
    <div class="col-12">
      <div class="theme-panel rounded-4 p-4">
        <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
          <div>
            <p class="small text-uppercase text-info mb-1" style="letter-spacing:0.22em;">Juegos coincidentes</p>
            <h2 class="h4 mb-0 fw-bold" style="font-family:'Oxanium',sans-serif;"><?= count($games) ?> resultado<?= count($games) === 1 ? '' : 's' ?></h2>
          </div>
        </div>
        <?php if (empty($games)): ?>
          <div class="rounded-4 border border-info-subtle px-4 py-4 text-secondary" style="background:rgba(var(--theme-surface-rgb),0.66);">No se encontraron juegos con ese criterio.</div>
        <?php else: ?>
          <div class="row row-cols-1 row-cols-lg-2 g-3">
            <?php foreach ($games as $item): ?>
              <div class="col">
                <a href="<?= htmlspecialchars((string) ($item['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none d-block h-100">
                  <article class="rounded-4 border h-100 p-3 d-flex align-items-center gap-3" style="background:rgba(var(--theme-surface-rgb),0.78);border-color:rgba(var(--theme-primary-rgb),0.3)!important;box-shadow:0 0 18px rgba(var(--theme-primary-rgb),0.08);">
                    <div class="rounded-4 overflow-hidden flex-shrink-0" style="width:76px;height:76px;background:rgba(var(--theme-bg-alt-rgb),0.92);border:1px solid rgba(var(--theme-primary-rgb),0.28);">
                      <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= htmlspecialchars((string) $item['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100 object-fit-cover">
                      <?php else: ?>
                        <div class="w-100 h-100 d-flex align-items-center justify-content-center text-info fw-bold">JG</div>
                      <?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-grow-1">
                      <div class="small text-uppercase text-info mb-1" style="letter-spacing:0.16em;"><?= htmlspecialchars((string) ($item['badge'] ?? 'Juego'), ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="fw-bold text-light text-truncate"><?= htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                      <?php if (!empty($item['price_label'])): ?>
                        <div class="small text-secondary mt-1"><?= htmlspecialchars((string) $item['price_label'], ENT_QUOTES, 'UTF-8') ?></div>
                      <?php endif; ?>
                    </div>
                  </article>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12">
      <div class="theme-panel rounded-4 p-4">
        <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
          <div>
            <p class="small text-uppercase text-info mb-1" style="letter-spacing:0.22em;">Paquetes coincidentes</p>
            <h2 class="h4 mb-0 fw-bold" style="font-family:'Oxanium',sans-serif;"><?= count($packages) ?> resultado<?= count($packages) === 1 ? '' : 's' ?></h2>
          </div>
        </div>
        <?php if (empty($packages)): ?>
          <div class="rounded-4 border border-info-subtle px-4 py-4 text-secondary" style="background:rgba(var(--theme-surface-rgb),0.66);">No se encontraron paquetes con ese criterio.</div>
        <?php else: ?>
          <div class="row row-cols-1 row-cols-lg-2 g-3">
            <?php foreach ($packages as $item): ?>
              <div class="col">
                <a href="<?= htmlspecialchars((string) ($item['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none d-block h-100">
                  <article class="rounded-4 border h-100 p-3 d-flex align-items-center gap-3" style="background:rgba(var(--theme-surface-rgb),0.78);border-color:rgba(var(--theme-primary-rgb),0.3)!important;box-shadow:0 0 18px rgba(var(--theme-primary-rgb),0.08);">
                    <div class="rounded-4 overflow-hidden flex-shrink-0" style="width:76px;height:76px;background:rgba(var(--theme-bg-alt-rgb),0.92);border:1px solid rgba(var(--theme-primary-rgb),0.28);">
                      <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= htmlspecialchars((string) $item['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100 object-fit-cover">
                      <?php else: ?>
                        <div class="w-100 h-100 d-flex align-items-center justify-content-center text-info fw-bold">PK</div>
                      <?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-grow-1">
                      <div class="small text-uppercase text-info mb-1" style="letter-spacing:0.16em;"><?= htmlspecialchars((string) ($item['badge'] ?? 'Paquete'), ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="fw-bold text-light text-truncate"><?= htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                      <div class="small text-secondary mt-1 text-truncate">Pertenece a <?= htmlspecialchars((string) ($item['game_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                      <?php if (!empty($item['price_label'])): ?>
                        <div class="small text-secondary mt-1">Precio: <?= htmlspecialchars((string) $item['price_label'], ENT_QUOTES, 'UTF-8') ?></div>
                      <?php endif; ?>
                    </div>
                  </article>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>