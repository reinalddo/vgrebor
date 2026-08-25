<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}
require_once __DIR__ . '/../includes/auth.php';
csrf_verify_soft();

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/referidos.php';
require_once __DIR__ . '/../includes/header.php';

referidos_ensure_schema();

$flashMessage = '';
$flashType = 'success';

// ── Acción: marcar como pagadas TODAS las comisiones pendientes de un referidor ──
// Se paga por lote (todo lo pendiente de ese referidor de una vez), no fila por
// fila — el admin ya transfirió el monto total por fuera del sistema (WhatsApp/
// transferencia), igual criterio que el panel de influencers.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'marcar_pagado_referidor') {
    $referidorId = (int) ($_POST['referidor_user_id'] ?? 0);
    if ($referidorId <= 0) {
        $flashMessage = 'Referidor inválido.';
        $flashType = 'danger';
    } else {
        $updStmt = $mysqli->prepare(
            "UPDATE referidos_comisiones SET estado_pago = 'pagado', pagado_en = NOW()
             WHERE referidor_user_id = ? AND estado_pago = 'pendiente'"
        );
        $updStmt->bind_param('i', $referidorId);
        $updStmt->execute();
        $affected = $updStmt->affected_rows;
        $updStmt->close();

        $flashMessage = $affected > 0
            ? "Se marcaron {$affected} comisión(es) como pagadas."
            : 'Este referidor no tenía comisiones pendientes.';
        $flashType = $affected > 0 ? 'success' : 'warning';
    }
}

// ── Acción: guardar configuración del cupón de bienvenida del invitado ──
// Solo estos 2 valores por ahora (a pedido explícito del cliente, 2026-08-24):
// el mínimo de retiro y los niveles del referidor quedaron para más adelante.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_configuracion_cupon_bienvenida') {
    $nuevoPorcentaje = (float) str_replace(',', '.', trim((string) ($_POST['cupon_porcentaje'] ?? '')));
    $nuevoMinimo = (float) str_replace(',', '.', trim((string) ($_POST['cupon_monto_minimo'] ?? '')));

    if ($nuevoPorcentaje <= 0 || $nuevoPorcentaje > 100) {
        $flashMessage = 'El porcentaje del cupón debe ser mayor a 0 y hasta 100.';
        $flashType = 'danger';
    } elseif ($nuevoMinimo < 0) {
        $flashMessage = 'El monto mínimo de compra no puede ser negativo.';
        $flashType = 'danger';
    } else {
        store_config_upsert('referidos_cupon_bienvenida_porcentaje', number_format($nuevoPorcentaje, 2, '.', ''), 'Sistema de Referidos: % de descuento del cupón de bienvenida del invitado.');
        store_config_upsert('referidos_cupon_bienvenida_monto_minimo', number_format($nuevoMinimo, 2, '.', ''), 'Sistema de Referidos: monto mínimo de recarga para que aplique el cupón de bienvenida del invitado (debe ser MAYOR a este monto, no igual).');
        $flashMessage = 'Configuración del cupón de bienvenida actualizada correctamente.';
        $flashType = 'success';
    }
}

// ── Acción: guardar el banner "Invita a un amigo" de la página de cada juego ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_configuracion_banner_referidos') {
    $nuevoTitulo = trim((string) ($_POST['banner_titulo'] ?? ''));
    $nuevoTipo = ($_POST['banner_icono_tipo'] ?? 'emoji') === 'imagen' ? 'imagen' : 'emoji';
    $nuevoEmoji = trim((string) ($_POST['banner_icono_emoji'] ?? ''));
    $quitarImagen = isset($_POST['banner_quitar_imagen']);

    if ($nuevoTitulo === '') {
        $flashMessage = 'El título del banner no puede quedar vacío.';
        $flashType = 'danger';
    } elseif ($nuevoTipo === 'emoji' && $nuevoEmoji === '') {
        $flashMessage = 'Debes indicar un emoji para el ícono del banner.';
        $flashType = 'danger';
    } else {
        $imagenActual = referidos_banner_icono_imagen();

        if ($quitarImagen) {
            referidos_banner_delete_image_file($imagenActual);
            $imagenActual = '';
            if ($nuevoTipo === 'imagen') {
                $nuevoTipo = 'emoji';
            }
        }

        $subidaOk = true;
        if ($nuevoTipo === 'imagen' && !empty($_FILES['banner_imagen']['tmp_name'])) {
            $upload = referidos_banner_store_image_upload($_FILES['banner_imagen']);
            if (!$upload['success']) {
                $flashMessage = $upload['message'];
                $flashType = 'danger';
                $subidaOk = false;
            } else {
                referidos_banner_delete_image_file($imagenActual);
                $imagenActual = $upload['path'];
            }
        }

        if ($subidaOk && $nuevoTipo === 'imagen' && $imagenActual === '') {
            $flashMessage = 'Debes subir una imagen antes de activar el modo "Imagen".';
            $flashType = 'danger';
            $subidaOk = false;
        }

        if ($subidaOk) {
            store_config_upsert('referidos_banner_titulo', $nuevoTitulo, 'Sistema de Referidos: título del banner "Invita a un amigo" en la página de cada juego.');
            store_config_upsert('referidos_banner_icono_tipo', $nuevoTipo, 'Sistema de Referidos: si el ícono del banner es "emoji" o "imagen".');
            store_config_upsert('referidos_banner_icono_emoji', $nuevoEmoji !== '' ? $nuevoEmoji : '🎁', 'Sistema de Referidos: emoji del banner (usado cuando el tipo de ícono es "emoji").');
            store_config_upsert('referidos_banner_icono_imagen', $imagenActual, 'Sistema de Referidos: ruta de la imagen del banner (usada cuando el tipo de ícono es "imagen").');
            $flashMessage = 'Banner de referidos actualizado correctamente.';
            $flashType = 'success';
        }
    }
}

// Valores actuales del cupón de bienvenida (para precargar el formulario).
$cuponPorcentajeActual = referidos_cupon_bienvenida_porcentaje();
$cuponMontoMinimoActual = referidos_cupon_bienvenida_monto_minimo();

// Valores actuales del banner (para precargar el formulario).
$bannerTituloActual = referidos_banner_titulo();
$bannerTipoActual = referidos_banner_icono_tipo();
$bannerEmojiActual = referidos_banner_icono_emoji();
$bannerImagenActual = referidos_banner_icono_imagen();

// ── Resumen global ──────────────────────────────────────────────────────
$globalTotals = ['pendiente' => 0.0, 'pagado' => 0.0];
$globalRes = $mysqli->query(
    "SELECT estado_pago, COALESCE(SUM(comision), 0) AS total FROM referidos_comisiones GROUP BY estado_pago"
);
if ($globalRes instanceof mysqli_result) {
    while ($row = $globalRes->fetch_assoc()) {
        if (($row['estado_pago'] ?? '') === 'pagado') {
            $globalTotals['pagado'] = round((float) $row['total'], 2);
        } else {
            $globalTotals['pendiente'] = round((float) $row['total'], 2);
        }
    }
}

// ── Listado agrupado por referidor ─────────────────────────────────────
$referidores = [];
$listRes = $mysqli->query(
    "SELECT rc.referidor_user_id, u.nombre, u.email,
            SUM(CASE WHEN rc.estado_pago = 'pendiente' THEN rc.comision ELSE 0 END) AS pendiente,
            SUM(CASE WHEN rc.estado_pago = 'pagado' THEN rc.comision ELSE 0 END) AS pagado,
            SUM(rc.monto_base) AS monto_acumulado,
            COUNT(DISTINCT rc.invitado_user_id) AS invitados,
            COUNT(*) AS total_pedidos,
            MAX(rc.creado_en) AS ultima_comision
     FROM referidos_comisiones rc
     LEFT JOIN usuarios u ON u.id = rc.referidor_user_id
     GROUP BY rc.referidor_user_id
     ORDER BY pendiente DESC, monto_acumulado DESC"
);
if ($listRes instanceof mysqli_result) {
    while ($row = $listRes->fetch_assoc()) {
        $montoAcumulado = round((float) ($row['monto_acumulado'] ?? 0), 2);
        $nivelInfo = referidos_nivel_para_monto($montoAcumulado);
        $referidores[] = [
            'id' => (int) ($row['referidor_user_id'] ?? 0),
            'nombre' => trim((string) ($row['nombre'] ?? '')) !== '' ? trim((string) $row['nombre']) : 'Usuario #' . (int) ($row['referidor_user_id'] ?? 0),
            'email' => trim((string) ($row['email'] ?? '')),
            'pendiente' => round((float) ($row['pendiente'] ?? 0), 2),
            'pagado' => round((float) ($row['pagado'] ?? 0), 2),
            'monto_acumulado' => $montoAcumulado,
            'invitados' => (int) ($row['invitados'] ?? 0),
            'total_pedidos' => (int) ($row['total_pedidos'] ?? 0),
            'ultima_comision' => (string) ($row['ultima_comision'] ?? ''),
            'nivel' => $nivelInfo,
        ];
    }
}

// ── Detalle de un referidor específico (opcional, vía ?referidor_id=) ──
$detalleReferidorId = (int) ($_GET['referidor_id'] ?? 0);
$detalleComisiones = [];
$detalleReferidorNombre = '';
if ($detalleReferidorId > 0) {
    $detalleComisiones = referidos_listar_comisiones($mysqli, $detalleReferidorId, 200);
    foreach ($referidores as $r) {
        if ($r['id'] === $detalleReferidorId) {
            $detalleReferidorNombre = $r['nombre'];
            break;
        }
    }
}
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .referidos-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.4rem; margin-bottom:1.5rem; }
    .referidos-table { background:#181f2a; color:#e2e8f0; }
    .referidos-table thead th { color:#00fff7; border-bottom:2px solid #00fff7; background:#181f2a; }
    .referidos-table tbody tr { border-bottom:1px solid #222c3a; }
    .referidos-nivel-badge { border-radius:999px; padding:0.15rem 0.6rem; font-size:0.72rem; font-weight:700; letter-spacing:0.02em; background:rgba(0,255,247,0.12); color:#00fff7; border:1px solid rgba(0,255,247,0.5); }
    .referidos-estado-badge { border-radius:999px; padding:0.15rem 0.6rem; font-size:0.72rem; font-weight:700; }
    .referidos-estado-pendiente { background:rgba(250,204,21,0.12); color:#facc15; border:1px solid rgba(250,204,21,0.5); }
    .referidos-estado-pagado { background:rgba(0,255,179,0.12); color:#00ffb3; border:1px solid rgba(0,255,179,0.5); }
    .referidos-input { background:#222c3a; color:#00fff7; border:1px solid #00fff7; }
    .referidos-input:focus { background:#222c3a; color:#00fff7; border-color:#00fff7; box-shadow:0 0 0 0.2rem rgba(0,255,247,0.25); }
  </style>

  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Referidos</h1>
      <p class="text-secondary">Comisiones ganadas por cada referidor y estado de pago.</p>
    </div>
  </div>

  <?php if ($flashMessage !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?> text-center" style="background:#181f2a;border:1px solid #00fff7;color:#e2e8f0;">
    <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
  </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4">
      <div class="referidos-card mb-0">
        <div class="text-secondary small text-uppercase mb-1">Total pendiente</div>
        <div class="h4 fw-bold text-warning mb-0">$<?= number_format($globalTotals['pendiente'], 2) ?></div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-4">
      <div class="referidos-card mb-0">
        <div class="text-secondary small text-uppercase mb-1">Total pagado</div>
        <div class="h4 fw-bold text-success mb-0">$<?= number_format($globalTotals['pagado'], 2) ?></div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-4">
      <div class="referidos-card mb-0">
        <div class="text-secondary small text-uppercase mb-1">Referidores con comisiones</div>
        <div class="h4 fw-bold text-light mb-0"><?= count($referidores) ?></div>
      </div>
    </div>
  </div>

  <div class="referidos-card">
    <h2 class="h5 text-info mb-2">Cupón de bienvenida del invitado</h2>
    <p class="text-secondary small mb-3">Descuento que recibe la persona invitada en su primera recarga (un solo uso). Solo aplica si la recarga es MAYOR al monto mínimo — cambiar estos valores no afecta a los cupones que ya se generaron antes, solo a los nuevos invitados a partir de ahora.</p>
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="action" value="guardar_configuracion_cupon_bienvenida">
      <div class="col-sm-4">
        <label for="cupon_porcentaje" class="form-label text-secondary small mb-1">Porcentaje de descuento</label>
        <div class="input-group">
          <input type="number" step="0.01" min="0.01" max="100" name="cupon_porcentaje" id="cupon_porcentaje" class="form-control referidos-input" style="width:auto;" value="<?= htmlspecialchars(number_format($cuponPorcentajeActual, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required>
          <span class="input-group-text">%</span>
        </div>
      </div>
      <div class="col-sm-4">
        <label for="cupon_monto_minimo" class="form-label text-secondary small mb-1">Monto mínimo de recarga (más de)</label>
        <div class="input-group">
          <span class="input-group-text">$</span>
          <input type="number" step="0.01" min="0" name="cupon_monto_minimo" id="cupon_monto_minimo" class="form-control referidos-input" style="width:auto;" value="<?= htmlspecialchars(number_format($cuponMontoMinimoActual, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
      </div>
      <div class="col-sm-4">
        <button type="submit" class="btn btn-info fw-bold w-100" style="background:#00fff7;color:#181f2a;border:none;box-shadow:0 0 8px #00fff7;">Guardar cambios</button>
      </div>
    </form>
  </div>

  <div class="referidos-card">
    <h2 class="h5 text-info mb-2">Banner "Invita a un amigo" (página de cada juego)</h2>
    <p class="text-secondary small mb-3">Se muestra debajo de la imagen del juego y arriba de "PASO 1" en cada página de recarga. Los porcentajes de la segunda línea siempre se calculan solos (según el cupón de bienvenida y los niveles), nunca son texto libre.</p>
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="action" value="guardar_configuracion_banner_referidos">
      <div class="col-12 col-md-6">
        <label for="banner_titulo" class="form-label text-secondary small mb-1">Título del banner</label>
        <input type="text" maxlength="120" name="banner_titulo" id="banner_titulo" class="form-control referidos-input" value="<?= htmlspecialchars($bannerTituloActual, ENT_QUOTES, 'UTF-8') ?>" required>
      </div>
      <div class="col-12">
        <label class="form-label text-secondary small mb-1 d-block">Ícono del banner</label>
        <div class="d-flex gap-4 flex-wrap mb-2">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="banner_icono_tipo" id="banner_tipo_emoji" value="emoji" data-banner-tipo-radio <?= $bannerTipoActual === 'emoji' ? 'checked' : '' ?>>
            <label class="form-check-label text-light" for="banner_tipo_emoji">Emoji</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="banner_icono_tipo" id="banner_tipo_imagen" value="imagen" data-banner-tipo-radio <?= $bannerTipoActual === 'imagen' ? 'checked' : '' ?>>
            <label class="form-check-label text-light" for="banner_tipo_imagen">Imagen</label>
          </div>
        </div>
        <div id="banner-emoji-field" style="<?= $bannerTipoActual === 'imagen' ? 'display:none;' : '' ?>">
          <input type="text" maxlength="8" name="banner_icono_emoji" id="banner_icono_emoji" class="form-control referidos-input" style="width:120px;font-size:1.4rem;text-align:center;" value="<?= htmlspecialchars($bannerEmojiActual, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div id="banner-imagen-field" style="<?= $bannerTipoActual === 'emoji' ? 'display:none;' : '' ?>">
          <?php if ($bannerImagenActual !== ''): ?>
            <div class="d-flex align-items-center gap-3 mb-2">
              <img src="<?= htmlspecialchars(app_path('/' . ltrim($bannerImagenActual, '/')), ENT_QUOTES, 'UTF-8') ?>" alt="Imagen actual del banner" style="width:56px;height:56px;object-fit:cover;border-radius:10px;border:1px solid #00fff7;">
              <div class="form-check m-0">
                <input class="form-check-input" type="checkbox" name="banner_quitar_imagen" id="banner_quitar_imagen" value="1">
                <label class="form-check-label text-warning small" for="banner_quitar_imagen">Quitar esta imagen (vuelve a usar el emoji)</label>
              </div>
            </div>
          <?php endif; ?>
          <input type="file" name="banner_imagen" id="banner_imagen" class="form-control referidos-input" accept="image/jpeg,image/png,image/webp,image/gif">
          <div class="form-text text-secondary">JPG, PNG, WEBP o GIF, máximo 4 MB. <?= $bannerImagenActual !== '' ? 'Deja vacío para conservar la imagen actual.' : '' ?></div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <button type="submit" class="btn btn-info fw-bold w-100" style="background:#00fff7;color:#181f2a;border:none;box-shadow:0 0 8px #00fff7;">Guardar banner</button>
      </div>
    </form>
  </div>

  <div class="d-flex gap-2 mb-3 flex-wrap">
    <button type="button" class="btn btn-sm btn-info active" data-referidos-tab="pendientes">Con pendiente</button>
    <button type="button" class="btn btn-sm btn-outline-info" data-referidos-tab="todos">Todos</button>
  </div>

  <div class="referidos-card">
    <?php if (empty($referidores)): ?>
      <p class="text-secondary text-center mb-0">Todavía no hay comisiones de referidos registradas.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle referidos-table">
          <thead>
            <tr>
              <th>Referidor</th>
              <th>Nivel</th>
              <th>Invitados</th>
              <th class="text-end">Pendiente</th>
              <th class="text-end">Pagado</th>
              <th>Última comisión</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($referidores as $r): ?>
              <tr data-referidos-estado="<?= $r['pendiente'] > 0 ? 'pendiente' : 'sin_pendiente' ?>">
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="small text-secondary"><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><span class="referidos-nivel-badge">Nivel <?= (int) $r['nivel']['nivel'] ?> — <?= htmlspecialchars($r['nivel']['nombre'], ENT_QUOTES, 'UTF-8') ?> (<?= (int) $r['nivel']['porcentaje'] ?>%)</span></td>
                <td><?= (int) $r['invitados'] ?></td>
                <td class="text-end fw-bold text-warning">$<?= number_format($r['pendiente'], 2) ?></td>
                <td class="text-end fw-bold text-success">$<?= number_format($r['pagado'], 2) ?></td>
                <td class="text-secondary small"><?= htmlspecialchars($r['ultima_comision'] !== '' ? substr($r['ultima_comision'], 0, 10) : '—', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-end">
                  <a href="?referidor_id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-info mb-1">Ver detalle</a>
                  <?php if ($r['pendiente'] > 0): ?>
                    <form method="post" class="d-inline m-0" onsubmit="return confirm('¿Confirmas que ya le pagaste $<?= number_format($r['pendiente'], 2) ?> a <?= htmlspecialchars(addslashes($r['nombre']), ENT_QUOTES, 'UTF-8') ?> por fuera del sistema?');">
                      <input type="hidden" name="action" value="marcar_pagado_referidor">
                      <input type="hidden" name="referidor_user_id" value="<?= (int) $r['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-success mb-1">Marcar pagado</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($detalleReferidorId > 0): ?>
  <div class="referidos-card">
    <h2 class="h5 text-info mb-3">Detalle de comisiones — <?= htmlspecialchars($detalleReferidorNombre, ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if (empty($detalleComisiones)): ?>
      <p class="text-secondary mb-0">Sin comisiones registradas.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle referidos-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Pedido</th>
              <th>Invitado</th>
              <th class="text-end">Recarga</th>
              <th>%</th>
              <th>Nivel</th>
              <th>Estado</th>
              <th class="text-end">Comisión</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($detalleComisiones as $c): ?>
              <tr>
                <td class="text-secondary small"><?= htmlspecialchars(substr($c['creado_en'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                <td>#<?= (int) $c['pedido_id'] ?></td>
                <td>
                  <div class="small"><?= htmlspecialchars($c['invitado_nombre'] !== '' ? $c['invitado_nombre'] : 'Usuario', ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="small text-secondary"><?= htmlspecialchars($c['invitado_email'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td class="text-end">$<?= number_format($c['monto_base'], 2) ?></td>
                <td><?= (int) $c['porcentaje_aplicado'] ?>%</td>
                <td>Nivel <?= (int) $c['nivel_en_momento'] ?></td>
                <td><span class="referidos-estado-badge referidos-estado-<?= $c['estado_pago'] === 'pagado' ? 'pagado' : 'pendiente' ?>"><?= $c['estado_pago'] === 'pagado' ? 'Pagado' : 'Pendiente' ?></span></td>
                <td class="text-end fw-bold text-success">$<?= number_format($c['comision'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</main>

<script>
(function () {
  var tabButtons = document.querySelectorAll('[data-referidos-tab]');
  var rows = document.querySelectorAll('[data-referidos-estado]');

  function applyFilter(tab) {
    rows.forEach(function (row) {
      var visible = tab === 'todos' || row.dataset.referidosEstado === 'pendiente';
      row.style.display = visible ? '' : 'none';
    });
  }

  tabButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      tabButtons.forEach(function (other) {
        other.classList.remove('btn-info', 'active');
        other.classList.add('btn-outline-info');
      });
      btn.classList.remove('btn-outline-info');
      btn.classList.add('btn-info', 'active');
      applyFilter(btn.dataset.referidosTab || 'pendientes');
    });
  });

  applyFilter('pendientes');

  var bannerTipoRadios = document.querySelectorAll('[data-banner-tipo-radio]');
  var bannerEmojiField = document.getElementById('banner-emoji-field');
  var bannerImagenField = document.getElementById('banner-imagen-field');
  function applyBannerTipo(tipo) {
    if (bannerEmojiField) bannerEmojiField.style.display = tipo === 'imagen' ? 'none' : '';
    if (bannerImagenField) bannerImagenField.style.display = tipo === 'imagen' ? '' : 'none';
  }
  bannerTipoRadios.forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (radio.checked) applyBannerTipo(radio.value);
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
