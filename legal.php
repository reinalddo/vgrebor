<?php

require_once __DIR__ . '/includes/store_config.php';

$legalPages = store_config_legal_pages();
$legalSlug  = trim((string) ($_GET['p'] ?? ''));

if (!isset($legalPages[$legalSlug])) {
    http_response_code(404);
    $pageTitle = 'Página no encontrada';
    require_once __DIR__ . '/includes/header.php';
    ?>
    <section class="mt-4">
      <div class="theme-panel rounded-4 p-4 p-lg-5 text-center">
        <h1 class="fw-bold mb-2" style="font-family:'Oxanium',sans-serif;">404</h1>
        <p class="text-secondary mb-0">La página que buscas no existe.</p>
      </div>
    </section>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$legalInfo    = $legalPages[$legalSlug];
$legalTitle   = $legalInfo['title'];
$legalContent = trim((string) store_config_get($legalInfo['key'], ''));
$pageTitle    = $legalTitle;

require_once __DIR__ . '/includes/header.php';
?>

<section class="mt-4">
  <div class="theme-panel rounded-4 p-4 p-lg-5 tvg-legal-page">
    <p class="small text-uppercase text-info mb-2" style="letter-spacing:0.24em;">Soporte y Legal</p>
    <h1 class="fw-bold mb-4" style="font-family:'Oxanium',sans-serif;"><?= htmlspecialchars($legalTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($legalContent !== ''): ?>
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">
      <div class="ql-editor tvg-legal-content text-secondary p-0"><?= $legalContent ?></div>
    <?php else: ?>
      <p class="text-secondary mb-0">Aún no se ha publicado el contenido de esta página.</p>
    <?php endif; ?>
  </div>
</section>

<style>
  .tvg-legal-content { line-height: 1.75; font-size: 1rem; }
  .tvg-legal-content h1, .tvg-legal-content h2, .tvg-legal-content h3,
  .tvg-legal-content h4, .tvg-legal-content h5, .tvg-legal-content h6 {
    color: var(--theme-text, #f8fafc);
    font-family: 'Oxanium', sans-serif;
  }
  .tvg-legal-content a { color: var(--theme-primary, #22d3ee); }
  .tvg-legal-content strong, .tvg-legal-content b { color: var(--theme-text, #f8fafc); }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
