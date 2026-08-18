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
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
