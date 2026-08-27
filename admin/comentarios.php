<?php
// MOCKUP visual — módulo "Comentarios" del dashboard. Los ~20 comentarios de
// abajo son datos de ejemplo fijos en PHP, NO vienen de base de datos. Los
// botones "Aprobar"/"Rechazar" son solo de demostración (JS local, no
// guardan nada). Si el cliente aprueba el diseño, esto se reemplaza por
// tablas reales + acciones POST, mismo patrón que admin/referidos.php.
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';

$mockupComentarios = [
    ['id' => 1,  'nombre' => 'Aullmaryz G.', 'inicial' => 'A', 'estrellas' => 5, 'texto' => 'Muy rápido y seguro 10/10 excelente plataforma de recargas', 'fecha' => '2026-08-20', 'estado' => 'aprobado'],
    ['id' => 2,  'nombre' => 'Gabriel M.',   'inicial' => 'G', 'estrellas' => 5, 'texto' => 'Genial todo muy bien', 'fecha' => '2026-08-20', 'estado' => 'aprobado'],
    ['id' => 3,  'nombre' => 'Fernando R.',  'inicial' => 'F', 'estrellas' => 5, 'texto' => 'Genial la seguiré usando, luego recargaré con más cantidades', 'fecha' => '2026-08-19', 'estado' => 'aprobado'],
    ['id' => 4,  'nombre' => 'Jose J.',      'inicial' => 'J', 'estrellas' => 5, 'texto' => '100% legal, si cumplen', 'fecha' => '2026-08-19', 'estado' => 'aprobado'],
    ['id' => 5,  'nombre' => 'Francy C.',    'inicial' => 'F', 'estrellas' => 5, 'texto' => 'Super rápido, llegó al instante', 'fecha' => '2026-08-18', 'estado' => 'aprobado'],
    ['id' => 6,  'nombre' => 'Jared L.',     'inicial' => 'J', 'estrellas' => 5, 'texto' => 'Fast and furious, como debe ser', 'fecha' => '2026-08-18', 'estado' => 'aprobado'],
    ['id' => 7,  'nombre' => 'Maria P.',     'inicial' => 'M', 'estrellas' => 5, 'texto' => 'Excelente atención y precios, totalmente recomendado', 'fecha' => '2026-08-17', 'estado' => 'aprobado'],
    ['id' => 8,  'nombre' => 'Carlos D.',    'inicial' => 'C', 'estrellas' => 4, 'texto' => 'Todo bien, tardó un poco más de lo esperado pero llegó', 'fecha' => '2026-08-21', 'estado' => 'pendiente'],
    ['id' => 9,  'nombre' => 'Andreina S.',  'inicial' => 'A', 'estrellas' => 5, 'texto' => 'La mejor tienda de recargas que he usado en Venezuela', 'fecha' => '2026-08-21', 'estado' => 'pendiente'],
    ['id' => 10, 'nombre' => 'Luis F.',      'inicial' => 'L', 'estrellas' => 5, 'texto' => 'Volví a comprar y de nuevo excelente servicio', 'fecha' => '2026-08-21', 'estado' => 'pendiente'],
    ['id' => 11, 'nombre' => 'Yolimar T.',   'inicial' => 'Y', 'estrellas' => 3, 'texto' => 'Bien pero la página se puso lenta al pagar', 'fecha' => '2026-08-22', 'estado' => 'pendiente'],
    ['id' => 12, 'nombre' => 'Kevin R.',     'inicial' => 'K', 'estrellas' => 5, 'texto' => 'Recomendado al 100%, ya voy por mi quinta recarga', 'fecha' => '2026-08-22', 'estado' => 'pendiente'],
    ['id' => 13, 'nombre' => 'Daniela V.',   'inicial' => 'D', 'estrellas' => 5, 'texto' => 'Rapidísimo, en menos de 5 minutos ya tenía mis diamantes', 'fecha' => '2026-08-22', 'estado' => 'pendiente'],
    ['id' => 14, 'nombre' => 'Anonimo123',   'inicial' => '?', 'estrellas' => 1, 'texto' => 'visiten mi canal de youtube gratis suscribanse', 'fecha' => '2026-08-16', 'estado' => 'rechazado'],
    ['id' => 15, 'nombre' => 'Usuario999',   'inicial' => 'U', 'estrellas' => 1, 'texto' => 'esto es una estafa no compren aca nunca me llego nada', 'fecha' => '2026-08-15', 'estado' => 'rechazado'],
    ['id' => 16, 'nombre' => 'Pedro G.',     'inicial' => 'P', 'estrellas' => 2, 'texto' => 'lenguaje inapropiado de ejemplo (comentario ofensivo)', 'fecha' => '2026-08-14', 'estado' => 'rechazado'],
    ['id' => 17, 'nombre' => 'Sofia M.',     'inicial' => 'S', 'estrellas' => 5, 'texto' => 'Excelente, el soporte me ayudó rapidísimo por WhatsApp', 'fecha' => '2026-08-13', 'estado' => 'aprobado'],
    ['id' => 18, 'nombre' => 'Ronald B.',    'inicial' => 'R', 'estrellas' => 5, 'texto' => 'Siempre uso esta tienda para mis recargas de Free Fire', 'fecha' => '2026-08-12', 'estado' => 'aprobado'],
    ['id' => 19, 'nombre' => 'Genesis H.',   'inicial' => 'G', 'estrellas' => 4, 'texto' => 'Buen precio y confiable, solo mejoraría el diseño móvil', 'fecha' => '2026-08-11', 'estado' => 'pendiente'],
    ['id' => 20, 'nombre' => 'Oswaldo N.',   'inicial' => 'O', 'estrellas' => 5, 'texto' => 'Tremenda plataforma, ya se la recomendé a todo mi clan', 'fecha' => '2026-08-10', 'estado' => 'aprobado'],
];

$conteoPorEstado = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0];
foreach ($mockupComentarios as $c) {
    $conteoPorEstado[$c['estado']]++;
}
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .com-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.4rem; margin-bottom:1.5rem; }
    .com-item { background:#141c28; border:1px solid #222c3a; border-radius:12px; padding:1rem 1.2rem; margin-bottom:0.85rem; display:flex; gap:1rem; align-items:flex-start; }
    .com-avatar { flex-shrink:0; width:42px; height:42px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; background:rgba(0,255,247,0.12); color:#00fff7; border:1px solid rgba(0,255,247,0.4); }
    .com-estrellas { color:#facc15; font-size:0.85rem; letter-spacing:0.08em; }
    .com-texto { color:#e2e8f0; font-size:0.92rem; margin:0.25rem 0 0; }
    .com-meta { color:#64748b; font-size:0.75rem; margin-top:0.35rem; }
    .com-estado-badge { border-radius:999px; padding:0.15rem 0.65rem; font-size:0.7rem; font-weight:700; letter-spacing:0.03em; text-transform:uppercase; }
    .com-estado-pendiente { background:rgba(250,204,21,0.12); color:#facc15; border:1px solid rgba(250,204,21,0.5); }
    .com-estado-aprobado { background:rgba(0,255,179,0.12); color:#00ffb3; border:1px solid rgba(0,255,179,0.5); }
    .com-estado-rechazado { background:rgba(248,113,113,0.12); color:#f87171; border:1px solid rgba(248,113,113,0.5); }
    .com-acciones { flex-shrink:0; display:flex; flex-direction:column; gap:0.4rem; }
    [data-com-tab-panel].d-none { display:none !important; }
  </style>

  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Comentarios</h1>
      <p class="text-secondary">Moderación de las reseñas que los clientes publican desde su cuenta. Los aprobados son los que se ven en la página principal.</p>
      <p class="text-warning small">🔧 Vista de ejemplo (mockup) — los datos son ficticios y los botones de Aprobar/Rechazar no guardan nada todavía.</p>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="com-card mb-0 text-center">
        <div class="text-secondary small text-uppercase mb-1">Pendientes</div>
        <div class="h4 fw-bold text-warning mb-0"><?= $conteoPorEstado['pendiente'] ?></div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="com-card mb-0 text-center">
        <div class="text-secondary small text-uppercase mb-1">Aprobados</div>
        <div class="h4 fw-bold text-success mb-0"><?= $conteoPorEstado['aprobado'] ?></div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="com-card mb-0 text-center">
        <div class="text-secondary small text-uppercase mb-1">Rechazados</div>
        <div class="h4 fw-bold text-danger mb-0"><?= $conteoPorEstado['rechazado'] ?></div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mb-3 flex-wrap">
    <button type="button" class="btn btn-sm btn-info active" data-com-tab="pendiente">Pendientes (<?= $conteoPorEstado['pendiente'] ?>)</button>
    <button type="button" class="btn btn-sm btn-outline-info" data-com-tab="aprobado">Aprobados (<?= $conteoPorEstado['aprobado'] ?>)</button>
    <button type="button" class="btn btn-sm btn-outline-info" data-com-tab="rechazado">Rechazados (<?= $conteoPorEstado['rechazado'] ?>)</button>
    <button type="button" class="btn btn-sm btn-outline-info" data-com-tab="todos">Todos (<?= count($mockupComentarios) ?>)</button>
  </div>

  <div class="com-card">
    <?php foreach (['pendiente', 'aprobado', 'rechazado', 'todos'] as $panel): ?>
    <div data-com-tab-panel="<?= $panel ?>" class="<?= $panel === 'pendiente' ? '' : 'd-none' ?>">
      <?php
      $itemsPanel = $panel === 'todos' ? $mockupComentarios : array_filter($mockupComentarios, fn($c) => $c['estado'] === $panel);
      if (empty($itemsPanel)):
      ?>
        <p class="text-secondary text-center mb-0">No hay comentarios en esta categoría.</p>
      <?php else: foreach ($itemsPanel as $c): ?>
        <div class="com-item" data-com-item="<?= (int) $c['id'] ?>">
          <span class="com-avatar"><?= htmlspecialchars($c['inicial'], ENT_QUOTES, 'UTF-8') ?></span>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="fw-semibold text-light"><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="com-estrellas"><?= str_repeat('★', $c['estrellas']) . str_repeat('☆', 5 - $c['estrellas']) ?></span>
              <span class="com-estado-badge com-estado-<?= $c['estado'] ?>" data-com-estado-label><?= ucfirst($c['estado']) ?></span>
            </div>
            <p class="com-texto"><?= htmlspecialchars($c['texto'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="com-meta">Publicado el <?= htmlspecialchars($c['fecha'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div class="com-acciones">
            <button type="button" class="btn btn-sm btn-success" data-com-accion="aprobado" <?= $c['estado'] === 'aprobado' ? 'disabled' : '' ?>>Aprobar</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-com-accion="rechazado" <?= $c['estado'] === 'rechazado' ? 'disabled' : '' ?>>Rechazar</button>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</main>

<script>
(function () {
  var tabButtons = document.querySelectorAll('[data-com-tab]');
  var panels = document.querySelectorAll('[data-com-tab-panel]');

  tabButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      tabButtons.forEach(function (other) {
        other.classList.remove('btn-info', 'active');
        other.classList.add('btn-outline-info');
      });
      btn.classList.remove('btn-outline-info');
      btn.classList.add('btn-info', 'active');
      var tab = btn.dataset.comTab;
      panels.forEach(function (panel) {
        panel.classList.toggle('d-none', panel.dataset.comTabPanel !== tab);
      });
    });
  });

  // Demo local: "Aprobar"/"Rechazar" solo cambian la insignia en pantalla,
  // no persisten nada (no hay backend todavía — ver comentario al inicio
  // del archivo).
  document.querySelectorAll('[data-com-accion]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item = btn.closest('[data-com-item]');
      if (!item) return;
      var nuevoEstado = btn.dataset.comAccion;
      var badge = item.querySelector('[data-com-estado-label]');
      if (badge) {
        badge.className = 'com-estado-badge com-estado-' + nuevoEstado;
        badge.setAttribute('data-com-estado-label', '');
        badge.textContent = nuevoEstado.charAt(0).toUpperCase() + nuevoEstado.slice(1);
      }
      item.querySelectorAll('[data-com-accion]').forEach(function (otro) {
        otro.disabled = otro.dataset.comAccion === nuevoEstado;
      });
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
