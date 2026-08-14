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
require_once __DIR__ . '/../includes/recargas_api.php';
require_once __DIR__ . '/../includes/header.php';

// Mismas migraciones que api/pedidos.php: se repiten aquí para que esta
// página funcione aunque todavía no se haya creado ningún pedido nuevo.
function costos_ensure_schema(mysqli $mysqli): void {
    $columns = [
        'costo_unitario_base' => "ALTER TABLE pedidos ADD COLUMN costo_unitario_base DECIMAL(12,4) NULL AFTER precio_sin_drop",
        'precio_venta_unitario_base' => "ALTER TABLE pedidos ADD COLUMN precio_venta_unitario_base DECIMAL(12,4) NULL AFTER costo_unitario_base",
        'costo_fuente' => "ALTER TABLE pedidos ADD COLUMN costo_fuente VARCHAR(20) NULL AFTER precio_venta_unitario_base",
    ];
    foreach ($columns as $col => $sql) {
        $result = $mysqli->query("SHOW COLUMNS FROM pedidos LIKE '$col'");
        if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
            $mysqli->query($sql);
        }
    }
    $mysqli->query("CREATE TABLE IF NOT EXISTS costos_manuales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        juego_id INT NOT NULL,
        paquete_id INT NOT NULL,
        costo_base DECIMAL(12,4) NOT NULL,
        registrado_por INT NULL,
        vigente_desde TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_paquete_vigencia (paquete_id, vigente_desde)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
costos_ensure_schema($mysqli);

function costos_format_money($amount): string {
    return number_format((float) $amount, 4, '.', ',');
}

function costos_package_provider(array $row): string {
    $stored = strtolower(trim((string) ($row['api_provider'] ?? '')));
    if ($stored !== '') {
        return $stored;
    }
    if ((int) ($row['paquete_api'] ?? 0) > 0) {
        return 'giftven';
    }
    if (trim((string) ($row['monto_ff'] ?? '')) !== '') {
        return 'free_fire';
    }
    return 'manual';
}

$providerLabels = [
    'giftven' => 'TiendaGiftVen (automático)',
    'discord' => 'Discord (manual)',
    'free_fire' => 'Free Fire legado (manual)',
    'manual' => 'Precio manual',
];

$flashMessage = '';
$flashType = 'success';

// ── Acción: guardar costo manual (Discord / manual / Free Fire legado) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_costo') {
    $paqueteId = (int) ($_POST['paquete_id'] ?? 0);
    $costoBase = (float) str_replace(',', '.', trim((string) ($_POST['costo_base'] ?? '')));
    $aplicarHistorico = isset($_POST['aplicar_historico']);

    if ($paqueteId <= 0 || $costoBase <= 0) {
        $flashMessage = 'Debes indicar un paquete y un costo válido mayor a 0.';
        $flashType = 'danger';
    } else {
        $pkgStmt = $mysqli->prepare('SELECT juego_id FROM juego_paquetes WHERE id = ? LIMIT 1');
        $pkgStmt->bind_param('i', $paqueteId);
        $pkgStmt->execute();
        $pkgRow = $pkgStmt->get_result()->fetch_assoc();
        $pkgStmt->close();

        if (!$pkgRow) {
            $flashMessage = 'El paquete indicado no existe.';
            $flashType = 'danger';
        } else {
            $juegoId = (int) $pkgRow['juego_id'];
            $adminId = (int) ($_SESSION['auth_user']['id'] ?? 0);
            $insertStmt = $mysqli->prepare('INSERT INTO costos_manuales (juego_id, paquete_id, costo_base, registrado_por) VALUES (?, ?, ?, ?)');
            $insertStmt->bind_param('iidi', $juegoId, $paqueteId, $costoBase, $adminId);
            $insertStmt->execute();
            $insertStmt->close();

            $appliedCount = 0;
            if ($aplicarHistorico) {
                // precio_venta_unitario_base se reconstruye dividiendo el precio real cobrado
                // (exacto, ya guardado en el pedido) entre la tasa ACTUAL de esa moneda — no
                // existe una tasa histórica guardada, así que es una aproximación para pedidos
                // pagados en una moneda distinta a la base; los pagados en la moneda base son exactos.
                $updStmt = $mysqli->prepare(
                    "UPDATE pedidos p
                     INNER JOIN monedas m ON m.clave = p.moneda
                     SET p.costo_unitario_base = ?,
                         p.precio_venta_unitario_base = ROUND(p.precio / NULLIF(m.tasa, 0) / GREATEST(p.cantidad_compra, 1), 4),
                         p.costo_fuente = 'manual'
                     WHERE p.paquete_id = ? AND p.estado IN ('enviado','pagado') AND p.costo_unitario_base IS NULL"
                );
                $updStmt->bind_param('di', $costoBase, $paqueteId);
                $updStmt->execute();
                $appliedCount = $updStmt->affected_rows;
                $updStmt->close();
            }

            $flashMessage = 'Costo registrado correctamente.' . ($aplicarHistorico ? " Se aplicó a {$appliedCount} pedido(s) histórico(s) que no tenían costo." : '');
            $flashType = 'success';
        }
    }
}

// ── Acción: recalcular históricos de TiendaGiftVen ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recalcular_giftven') {
    $pendingStmt = $mysqli->query(
        "SELECT DISTINCT paquete_api FROM pedidos
         WHERE estado IN ('enviado','pagado') AND api_provider = 'giftven'
           AND paquete_api IS NOT NULL AND paquete_api > 0 AND costo_unitario_base IS NULL"
    );
    $totalApplied = 0;
    $totalFailed = 0;
    if ($pendingStmt instanceof mysqli_result) {
        while ($row = $pendingStmt->fetch_assoc()) {
            $apiId = (int) $row['paquete_api'];
            try {
                $product = recargas_api_fetch_product_by_id($apiId);
            } catch (Throwable $e) {
                $product = null;
            }
            if ($product === null || !isset($product['precio'])) {
                $totalFailed++;
                continue;
            }
            $cost = (float) $product['precio'];
            $updStmt = $mysqli->prepare(
                "UPDATE pedidos p
                 INNER JOIN monedas m ON m.clave = p.moneda
                 SET p.costo_unitario_base = ?,
                     p.precio_venta_unitario_base = ROUND(p.precio / NULLIF(m.tasa, 0) / GREATEST(p.cantidad_compra, 1), 4),
                     p.costo_fuente = 'giftven_api'
                 WHERE p.paquete_api = ? AND p.api_provider = 'giftven' AND p.estado IN ('enviado','pagado') AND p.costo_unitario_base IS NULL"
            );
            $updStmt->bind_param('di', $cost, $apiId);
            $updStmt->execute();
            $totalApplied += $updStmt->affected_rows;
            $updStmt->close();
        }
    }
    $flashMessage = "Recálculo completado: {$totalApplied} pedido(s) histórico(s) actualizados con su costo.";
    if ($totalFailed > 0) {
        $flashMessage .= " {$totalFailed} producto(s) ya no existen en el catálogo de la API y no se pudieron recalcular.";
        $flashType = 'warning';
    }
}

// ── Catálogo giftven en vivo (una sola llamada, cacheada por request) ────
$giftvenCostById = [];
if (recargas_api_is_configured()) {
    try {
        foreach (recargas_api_fetch_products() as $product) {
            if (is_array($product) && isset($product['id'])) {
                $giftvenCostById[(int) $product['id']] = (float) ($product['precio'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // Sin conexión con la API: los paquetes giftven se mostrarán sin costo disponible.
    }
}

// ── Último costo manual vigente por paquete ──────────────────────────────
$manualCostByPackage = [];
$manualHistoryByPackage = [];
$latestManualRes = $mysqli->query(
    "SELECT cm.paquete_id, cm.costo_base, cm.vigente_desde
     FROM costos_manuales cm
     INNER JOIN (SELECT paquete_id, MAX(id) AS max_id FROM costos_manuales GROUP BY paquete_id) latest
       ON latest.paquete_id = cm.paquete_id AND latest.max_id = cm.id"
);
if ($latestManualRes instanceof mysqli_result) {
    while ($row = $latestManualRes->fetch_assoc()) {
        $manualCostByPackage[(int) $row['paquete_id']] = [
            'costo' => (float) $row['costo_base'],
            'fecha' => (string) $row['vigente_desde'],
        ];
    }
}
$historyRes = $mysqli->query('SELECT paquete_id, costo_base, vigente_desde FROM costos_manuales ORDER BY paquete_id ASC, vigente_desde DESC, id DESC');
if ($historyRes instanceof mysqli_result) {
    while ($row = $historyRes->fetch_assoc()) {
        $manualHistoryByPackage[(int) $row['paquete_id']][] = [
            'costo' => (float) $row['costo_base'],
            'fecha' => (string) $row['vigente_desde'],
        ];
    }
}

// ── Listado de paquetes activos ──────────────────────────────────────────
$packages = [];
$pkgRes = $mysqli->query(
    "SELECT jp.id, jp.nombre AS paquete_nombre, jp.paquete_api, jp.api_provider, jp.monto_ff,
            j.id AS juego_id, j.nombre AS juego_nombre
     FROM juego_paquetes jp
     INNER JOIN juegos j ON j.id = jp.juego_id
     WHERE COALESCE(jp.activo, 1) = 1
     ORDER BY j.nombre ASC, jp.nombre ASC"
);
$automaticProviders = ['giftven'];
if ($pkgRes instanceof mysqli_result) {
    while ($row = $pkgRes->fetch_assoc()) {
        $provider = costos_package_provider($row);
        $packageId = (int) $row['id'];
        $currentCost = null;
        if ($provider === 'giftven') {
            $apiId = (int) ($row['paquete_api'] ?? 0);
            $currentCost = $giftvenCostById[$apiId] ?? null;
        } else {
            $currentCost = $manualCostByPackage[$packageId]['costo'] ?? null;
        }
        $packages[] = [
            'id' => $packageId,
            'juego_id' => (int) $row['juego_id'],
            'juego_nombre' => (string) $row['juego_nombre'],
            'paquete_nombre' => (string) $row['paquete_nombre'],
            'provider' => $provider,
            'is_auto' => in_array($provider, $automaticProviders, true),
            'costo_actual' => $currentCost,
            'historial' => $manualHistoryByPackage[$packageId] ?? [],
        ];
    }
}

$providerTabCounts = [
    'all' => count($packages),
    'giftven' => count(array_filter($packages, static fn (array $p): bool => $p['provider'] === 'giftven')),
    'discord' => count(array_filter($packages, static fn (array $p): bool => $p['provider'] === 'discord')),
    'auto' => count(array_filter($packages, static fn (array $p): bool => $p['is_auto'])),
    'manual' => count(array_filter($packages, static fn (array $p): bool => !$p['is_auto'])),
];

$pendingGiftvenCount = 0;
$pendingRes = $mysqli->query(
    "SELECT COUNT(*) AS total FROM pedidos
     WHERE estado IN ('enviado','pagado') AND api_provider = 'giftven'
       AND paquete_api IS NOT NULL AND paquete_api > 0 AND costo_unitario_base IS NULL"
);
if ($pendingRes instanceof mysqli_result) {
    $pendingGiftvenCount = (int) ($pendingRes->fetch_assoc()['total'] ?? 0);
}
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .costos-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.4rem; margin-bottom:1.5rem; }
    .costos-provider-badge { border-radius:999px; padding:0.15rem 0.6rem; font-size:0.72rem; font-weight:700; letter-spacing:0.02em; }
    .costos-provider-giftven { background:rgba(0,255,247,0.12); color:#00fff7; border:1px solid rgba(0,255,247,0.5); }
    .costos-provider-manual { background:rgba(192,132,252,0.12); color:#c084fc; border:1px solid rgba(192,132,252,0.5); }
    .costos-table { background:#181f2a; color:#e2e8f0; }
    .costos-table thead th { color:#00fff7; border-bottom:2px solid #00fff7; background:#181f2a; }
    .costos-table tbody tr { border-bottom:1px solid #222c3a; }
    .costos-input { background:#222c3a; color:#00fff7; border:1px solid #00fff7; width:110px; }
    .costos-search { background:#222c3a; color:#00fff7; border:1px solid #00fff7; max-width:320px; }
    .costos-history-btn { color:#8be9fd; font-size:0.78rem; text-decoration:underline; background:none; border:none; cursor:pointer; padding:0; }
    .costos-history-row { display:none; }
    .costos-history-row.is-visible { display:table-row; }
  </style>

  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Registrar Costos</h1>
      <p class="text-secondary">Costo de cada paquete para calcular la ganancia en Estadísticas.</p>
    </div>
  </div>

  <?php if ($flashMessage !== ''): ?>
  <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?> text-center" style="background:#181f2a;border:1px solid #00fff7;color:#e2e8f0;">
    <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
  </div>
  <?php endif; ?>

  <div class="costos-card">
    <h2 class="h5 text-info mb-2">TiendaGiftVen: costo automático</h2>
    <p class="text-secondary small mb-3">El costo de estos paquetes siempre viene de la API en vivo — no se puede editar manualmente. Si hay pedidos históricos sin costo registrado (de antes de activar esta función), puedes recalcularlos usando el costo actual.</p>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <span class="text-secondary">Pedidos históricos de TiendaGiftVen sin costo: <strong style="color:#00ffb3;"><?= $pendingGiftvenCount ?></strong></span>
      <form method="post" class="m-0">
        <input type="hidden" name="action" value="recalcular_giftven">
        <button type="submit" class="btn btn-info btn-sm fw-bold" style="background:#00fff7;color:#181f2a;border:none;box-shadow:0 0 8px #00fff7;" <?= $pendingGiftvenCount === 0 ? 'disabled' : '' ?>>Recalcular costos históricos</button>
      </form>
    </div>
  </div>

  <div class="costos-card">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="h5 text-info mb-0">Paquetes</h2>
      <input type="text" id="costos-search-input" class="form-control form-control-sm costos-search" placeholder="Buscar juego o paquete...">
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button type="button" class="btn btn-info fw-bold btn-sm costos-provider-tab-btn active" data-provider-tab="">Todos <span>(<?= $providerTabCounts['all'] ?>)</span></button>
      <button type="button" class="btn btn-outline-info fw-bold btn-sm costos-provider-tab-btn" data-provider-tab="giftven">TiendaGiftVen <span>(<?= $providerTabCounts['giftven'] ?>)</span></button>
      <button type="button" class="btn btn-outline-info fw-bold btn-sm costos-provider-tab-btn" data-provider-tab="discord">Discord <span>(<?= $providerTabCounts['discord'] ?>)</span></button>
      <button type="button" class="btn btn-outline-info fw-bold btn-sm costos-provider-tab-btn" data-provider-tab="auto">Automáticos <span>(<?= $providerTabCounts['auto'] ?>)</span></button>
      <button type="button" class="btn btn-outline-info fw-bold btn-sm costos-provider-tab-btn" data-provider-tab="manual">Manuales <span>(<?= $providerTabCounts['manual'] ?>)</span></button>
    </div>
    <?php if ($packages === []): ?>
      <p class="text-secondary text-center">No hay paquetes activos registrados.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle costos-table" id="costos-table">
        <thead>
          <tr>
            <th>Juego</th>
            <th>Paquete</th>
            <th>Origen</th>
            <th>Costo actual (USD)</th>
            <th>Registrar / actualizar costo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($packages as $p): ?>
          <tr class="costos-row" data-search="<?= htmlspecialchars(mb_strtolower($p['juego_nombre'] . ' ' . $p['paquete_nombre'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>" data-provider="<?= htmlspecialchars($p['provider'], ENT_QUOTES, 'UTF-8') ?>" data-auto="<?= $p['is_auto'] ? '1' : '0' ?>">
            <td><?= htmlspecialchars($p['juego_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($p['paquete_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <span class="costos-provider-badge <?= $p['provider'] === 'giftven' ? 'costos-provider-giftven' : 'costos-provider-manual' ?>">
                <?= htmlspecialchars($providerLabels[$p['provider']] ?? $p['provider'], ENT_QUOTES, 'UTF-8') ?>
              </span>
            </td>
            <td style="color:#00ffb3;font-weight:700;">
              <?= $p['costo_actual'] !== null ? '$' . costos_format_money($p['costo_actual']) : '<span class="text-secondary fw-normal">Sin dato</span>' ?>
            </td>
            <td>
              <?php if ($p['provider'] === 'giftven'): ?>
                <span class="text-secondary small">Automático — no editable</span>
              <?php else: ?>
                <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
                  <input type="hidden" name="action" value="guardar_costo">
                  <input type="hidden" name="paquete_id" value="<?= $p['id'] ?>">
                  <div class="input-group input-group-sm" style="width:auto;">
                    <span class="input-group-text" style="background:#222c3a;color:#00fff7;border:1px solid #00fff7;">$</span>
                    <input type="text" name="costo_base" class="form-control form-control-sm costos-input" placeholder="0.00" required>
                  </div>
                  <label class="d-flex align-items-center gap-1 mb-0 small" style="color:#8be9fd;cursor:pointer;">
                    <input type="checkbox" name="aplicar_historico" value="1">
                    Aplicar a pedidos pasados sin costo
                  </label>
                  <button type="submit" class="btn btn-sm" style="background:#00fff7;color:#181f2a;font-weight:700;border:none;">Guardar</button>
                  <?php if ($p['historial'] !== []): ?>
                    <button type="button" class="costos-history-btn" data-toggle-history="<?= $p['id'] ?>">Ver historial (<?= count($p['historial']) ?>)</button>
                  <?php endif; ?>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($p['historial'] !== []): ?>
          <tr class="costos-history-row" id="historial-<?= $p['id'] ?>">
            <td colspan="5" style="background:#0f1a28;">
              <div class="small text-secondary mb-1">Historial de costos registrados:</div>
              <ul class="mb-0 small" style="color:#e2e8f0;">
                <?php foreach ($p['historial'] as $h): ?>
                  <li>$<?= costos_format_money($h['costo']) ?> — desde <?= htmlspecialchars($h['fecha'], ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
              </ul>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</main>

<script>
(function () {
  var searchInput = document.getElementById('costos-search-input');
  var providerTabButtons = Array.from(document.querySelectorAll('.costos-provider-tab-btn'));
  var activeProviderTab = '';

  function rowMatchesTab(row, tab) {
    if (tab === '') return true;
    if (tab === 'auto') return row.dataset.auto === '1';
    if (tab === 'manual') return row.dataset.auto === '0';
    return row.dataset.provider === tab;
  }

  function applyFilters() {
    var term = searchInput ? searchInput.value.trim().toLowerCase() : '';
    document.querySelectorAll('.costos-row').forEach(function (row) {
      var matchesSearch = row.dataset.search.indexOf(term) !== -1;
      var matchesTab = rowMatchesTab(row, activeProviderTab);
      var matches = matchesSearch && matchesTab;
      row.style.display = matches ? '' : 'none';
      var historyRow = row.nextElementSibling;
      if (historyRow && historyRow.classList.contains('costos-history-row')) {
        if (!matches) historyRow.classList.remove('is-visible');
        historyRow.style.display = matches && historyRow.classList.contains('is-visible') ? '' : 'none';
      }
    });
  }

  providerTabButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      providerTabButtons.forEach(function (other) {
        other.classList.remove('btn-info', 'active');
        other.classList.add('btn-outline-info');
      });
      btn.classList.remove('btn-outline-info');
      btn.classList.add('btn-info', 'active');
      activeProviderTab = btn.dataset.providerTab || '';
      applyFilters();
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
  }

  document.querySelectorAll('[data-toggle-history]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.getElementById('historial-' + btn.dataset.toggleHistory);
      if (target) target.classList.toggle('is-visible');
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
