<?php
require_once __DIR__ . '/../includes/tenant.php';
tenant_start_session();
$adminRole = trim((string) ($_SESSION['auth_user']['rol'] ?? ''));
if (!isset($_SESSION['auth_user']) || !in_array($adminRole, ['admin', 'root'], true)) {
    header('Location: ' . app_path('/login.php'));
    exit();
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/header.php';

function stats_format_money($amount): string {
    return number_format((float) $amount, 2, '.', ',');
}

// ── Rango de fechas ──────────────────────────────────────────────────────
$preset = trim((string) ($_GET['preset'] ?? 'mes'));
$allowedPresets = ['hoy', 'semana', 'mes', 'personalizado'];
if (!in_array($preset, $allowedPresets, true)) {
    $preset = 'mes';
}

$today = new DateTimeImmutable('today');
switch ($preset) {
    case 'hoy':
        $dateFrom = $today;
        $dateTo = $today;
        break;
    case 'semana':
        $dateFrom = $today->modify('-6 days');
        $dateTo = $today;
        break;
    case 'personalizado':
        $rawFrom = trim((string) ($_GET['desde'] ?? ''));
        $rawTo = trim((string) ($_GET['hasta'] ?? ''));
        $parsedFrom = $rawFrom !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $rawFrom) : false;
        $parsedTo = $rawTo !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $rawTo) : false;
        $dateFrom = $parsedFrom ?: $today->modify('first day of this month');
        $dateTo = $parsedTo ?: $today;
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        break;
    case 'mes':
    default:
        $dateFrom = $today->modify('first day of this month');
        $dateTo = $today;
        break;
}

$dateFromStr = $dateFrom->format('Y-m-d');
$dateToStr = $dateTo->format('Y-m-d');
$rangeStartSql = $dateFromStr . ' 00:00:00';
$rangeEndSql = $dateToStr . ' 23:59:59';

// "Venta" = pedido verificado o entregado (misma convención que includes/win_points.php)
// ── Ventas totales por moneda ────────────────────────────────────────────
$totalsByCurrency = [];
$stmtTotals = $mysqli->prepare(
    "SELECT moneda, COUNT(*) AS pedidos, SUM(precio) AS total
     FROM pedidos
     WHERE estado IN ('enviado','pagado') AND creado_en BETWEEN ? AND ?
     GROUP BY moneda
     ORDER BY total DESC"
);
if ($stmtTotals) {
    $stmtTotals->bind_param('ss', $rangeStartSql, $rangeEndSql);
    $stmtTotals->execute();
    $resTotals = $stmtTotals->get_result();
    while ($row = $resTotals->fetch_assoc()) {
        $currencyCode = trim((string) ($row['moneda'] ?? '')) ?: 'N/D';
        $totalsByCurrency[] = [
            'moneda' => $currencyCode,
            'pedidos' => (int) $row['pedidos'],
            'total' => (float) $row['total'],
        ];
    }
    $stmtTotals->close();
}

$totalOrdersCount = array_sum(array_column($totalsByCurrency, 'pedidos'));
$availableCurrencies = array_column($totalsByCurrency, 'moneda');

// Moneda seleccionada para la gráfica de ventas por fecha (no se suman monedas distintas entre sí)
$selectedCurrency = trim((string) ($_GET['moneda'] ?? ''));
if ($selectedCurrency === '' || !in_array($selectedCurrency, $availableCurrencies, true)) {
    $selectedCurrency = $availableCurrencies[0] ?? '';
}

// ── Ventas por fecha (moneda seleccionada) ───────────────────────────────
$dailySeries = [];
if ($selectedCurrency !== '') {
    $stmtDaily = $mysqli->prepare(
        "SELECT DATE(creado_en) AS dia, SUM(precio) AS total, COUNT(*) AS pedidos
         FROM pedidos
         WHERE estado IN ('enviado','pagado') AND moneda = ? AND creado_en BETWEEN ? AND ?
         GROUP BY DATE(creado_en)
         ORDER BY dia ASC"
    );
    if ($stmtDaily) {
        $stmtDaily->bind_param('sss', $selectedCurrency, $rangeStartSql, $rangeEndSql);
        $stmtDaily->execute();
        $resDaily = $stmtDaily->get_result();
        $dailyByDate = [];
        while ($row = $resDaily->fetch_assoc()) {
            $dailyByDate[$row['dia']] = ['total' => (float) $row['total'], 'pedidos' => (int) $row['pedidos']];
        }
        $stmtDaily->close();

        // Rellenar días sin ventas con 0 para que la gráfica no salte fechas.
        $cursor = $dateFrom;
        $guard = 0;
        while ($cursor <= $dateTo && $guard < 400) {
            $key = $cursor->format('Y-m-d');
            $dailySeries[] = [
                'fecha' => $key,
                'total' => $dailyByDate[$key]['total'] ?? 0.0,
                'pedidos' => $dailyByDate[$key]['pedidos'] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
            $guard++;
        }
    }
}

// ── Productos vendidos: unidades (agnóstico de moneda) + ingreso por moneda ──
$productUnits = [];
$stmtUnits = $mysqli->prepare(
    "SELECT COALESCE(NULLIF(juego_nombre,''), 'Juego eliminado') AS juego,
            COALESCE(NULLIF(paquete_nombre,''), 'Paquete eliminado') AS paquete,
            SUM(COALESCE(cantidad_compra,1)) AS unidades,
            COUNT(*) AS pedidos
     FROM pedidos
     WHERE estado IN ('enviado','pagado') AND creado_en BETWEEN ? AND ?
     GROUP BY juego, paquete
     ORDER BY unidades DESC"
);
if ($stmtUnits) {
    $stmtUnits->bind_param('ss', $rangeStartSql, $rangeEndSql);
    $stmtUnits->execute();
    $resUnits = $stmtUnits->get_result();
    while ($row = $resUnits->fetch_assoc()) {
        $key = $row['juego'] . '|' . $row['paquete'];
        $productUnits[$key] = [
            'juego' => $row['juego'],
            'paquete' => $row['paquete'],
            'unidades' => (int) $row['unidades'],
            'pedidos' => (int) $row['pedidos'],
            'ingresos' => [],
        ];
    }
    $stmtUnits->close();
}

if ($productUnits !== []) {
    $stmtRevenue = $mysqli->prepare(
        "SELECT COALESCE(NULLIF(juego_nombre,''), 'Juego eliminado') AS juego,
                COALESCE(NULLIF(paquete_nombre,''), 'Paquete eliminado') AS paquete,
                moneda,
                SUM(precio) AS total
         FROM pedidos
         WHERE estado IN ('enviado','pagado') AND creado_en BETWEEN ? AND ?
         GROUP BY juego, paquete, moneda"
    );
    if ($stmtRevenue) {
        $stmtRevenue->bind_param('ss', $rangeStartSql, $rangeEndSql);
        $stmtRevenue->execute();
        $resRevenue = $stmtRevenue->get_result();
        while ($row = $resRevenue->fetch_assoc()) {
            $key = $row['juego'] . '|' . $row['paquete'];
            if (!isset($productUnits[$key])) {
                continue;
            }
            $currencyCode = trim((string) ($row['moneda'] ?? '')) ?: 'N/D';
            $productUnits[$key]['ingresos'][$currencyCode] = (float) $row['total'];
        }
        $stmtRevenue->close();
    }
}

$productList = array_values($productUnits); // ya viene ordenado por unidades desc
$topProducts = array_slice($productList, 0, 12);

$dailySeriesJson = json_encode($dailySeries, JSON_UNESCAPED_UNICODE);
$topProductsJson = json_encode(array_map(static function (array $p): array {
    return ['label' => $p['juego'] . ' — ' . $p['paquete'], 'unidades' => $p['unidades']];
}, $topProducts), JSON_UNESCAPED_UNICODE);

$presetLabels = ['hoy' => 'Hoy', 'semana' => 'Últimos 7 días', 'mes' => 'Este mes', 'personalizado' => 'Personalizado'];
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .stat-card { background:#181f2a; border:1px solid #00fff7; border-radius:12px; padding:1.1rem 0.75rem; box-shadow:0 0 12px rgba(0,255,247,0.08); height:100%; }
    .stat-card .stat-value { color:#00ffb3; font-size:1.35rem; font-weight:800; word-break:break-word; }
    .stat-card .stat-label { color:#8be9fd; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; }
    .preset-btn { border:1px solid #00fff7; color:#00fff7; background:#181f2a; border-radius:999px; padding:0.4rem 1.1rem; font-weight:700; font-size:0.85rem; }
    .preset-btn.is-active { background:#00fff7; color:#181f2a; box-shadow:0 0 10px rgba(0,255,247,0.5); }
    .chart-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.25rem; }
    .chart-toggle-btn { border:1px solid #00fff7; color:#00fff7; background:#181f2a; border-radius:999px; padding:0.3rem 0.9rem; font-size:0.78rem; font-weight:700; }
    .chart-toggle-btn.is-active { background:#00fff7; color:#181f2a; box-shadow:0 0 10px rgba(0,255,247,0.5); }
    .stats-table { background:#181f2a; color:#e2e8f0; }
    .stats-table thead th { color:#00fff7; border-bottom:2px solid #00fff7; background:#181f2a; }
    .stats-table tbody tr { border-bottom:1px solid #222c3a; }
    .stats-money-badge { background:#0f1a28; color:#00ffb3; border:1px solid #1e3a5f; border-radius:999px; padding:0.15rem 0.55rem; font-size:0.78rem; margin-right:0.3rem; display:inline-block; margin-bottom:0.2rem; }
  </style>

  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Estadísticas</h1>
      <p class="text-secondary">Ventas, productos más vendidos y tendencia por fechas.</p>
    </div>
  </div>

  <form method="get" action="<?= htmlspecialchars(app_path('/admin/estadisticas'), ENT_QUOTES, 'UTF-8') ?>" class="row g-2 justify-content-center align-items-end mb-4">
    <input type="hidden" name="moneda" value="<?= htmlspecialchars($selectedCurrency, ENT_QUOTES, 'UTF-8') ?>">
    <div class="col-auto d-flex gap-2 flex-wrap justify-content-center">
      <?php foreach ($presetLabels as $key => $label): ?>
        <button type="submit" name="preset" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" class="preset-btn <?= $preset === $key ? 'is-active' : '' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
      <?php endforeach; ?>
    </div>
    <?php if ($preset === 'personalizado'): ?>
    <div class="col-auto">
      <label class="form-label mb-0" style="color:#00fff7;">Desde</label>
      <input type="date" name="desde" value="<?= htmlspecialchars($dateFromStr, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
    </div>
    <div class="col-auto">
      <label class="form-label mb-0" style="color:#00fff7;">Hasta</label>
      <input type="date" name="hasta" value="<?= htmlspecialchars($dateToStr, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" style="background:#222c3a; color:#00fff7; border:1px solid #00fff7;">
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-info btn-sm fw-bold" style="background:#00fff7; color:#181f2a; border:none; box-shadow:0 0 8px #00fff7;">Aplicar</button>
    </div>
    <?php endif; ?>
  </form>

  <h2 class="h5 text-info mb-3 text-center">Ventas totales <small class="text-secondary"><?= htmlspecialchars($dateFromStr, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($dateToStr, ENT_QUOTES, 'UTF-8') ?></small></h2>
  <?php if ($totalsByCurrency === []): ?>
    <p class="text-secondary text-center mb-5">No hay ventas registradas en este rango.</p>
  <?php else: ?>
  <div class="row g-3 mb-5">
    <div class="col-6 col-md-3">
      <div class="stat-card text-center">
        <div class="stat-value"><?= $totalOrdersCount ?></div>
        <div class="stat-label">Pedidos vendidos</div>
      </div>
    </div>
    <?php foreach ($totalsByCurrency as $ct): ?>
    <div class="col-6 col-md-3">
      <div class="stat-card text-center">
        <div class="stat-value"><?= htmlspecialchars($ct['moneda'], ENT_QUOTES, 'UTF-8') ?> <?= stats_format_money($ct['total']) ?></div>
        <div class="stat-label"><?= $ct['pedidos'] ?> pedido<?= $ct['pedidos'] === 1 ? '' : 's' ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($selectedCurrency !== ''): ?>
  <div class="chart-card mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="h5 text-info mb-0">Ventas por fecha</h2>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if (count($availableCurrencies) > 1): ?>
        <select id="currency-select" class="form-select form-select-sm" style="width:auto;background:#222c3a;color:#00fff7;border:1px solid #00fff7;">
          <?php foreach ($availableCurrencies as $cur): ?>
            <option value="<?= htmlspecialchars($cur, ENT_QUOTES, 'UTF-8') ?>" <?= $cur === $selectedCurrency ? 'selected' : '' ?>><?= htmlspecialchars($cur, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="d-flex gap-1" data-chart-toggle="sales-by-date-chart">
          <button type="button" class="chart-toggle-btn is-active" data-type="line">Línea</button>
          <button type="button" class="chart-toggle-btn" data-type="bar">Barras</button>
          <button type="button" class="chart-toggle-btn" data-type="pie">Torta</button>
        </div>
      </div>
    </div>
    <canvas id="sales-by-date-chart" height="90"></canvas>
  </div>
  <?php endif; ?>

  <?php if ($topProducts !== []): ?>
  <div class="chart-card mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="h5 text-info mb-0">Productos más vendidos <small class="text-secondary">(unidades)</small></h2>
      <div class="d-flex gap-1" data-chart-toggle="top-products-chart">
        <button type="button" class="chart-toggle-btn is-active" data-type="bar">Barras</button>
        <button type="button" class="chart-toggle-btn" data-type="horizontalBar">Barras horiz.</button>
        <button type="button" class="chart-toggle-btn" data-type="pie">Torta</button>
      </div>
    </div>
    <canvas id="top-products-chart" height="110"></canvas>
  </div>
  <?php endif; ?>

  <h2 class="h5 text-info mb-3">Cantidad de venta de cada producto</h2>
  <?php if ($productList === []): ?>
    <p class="text-secondary text-center">No hay productos vendidos en este rango.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle stats-table">
      <thead>
        <tr>
          <th>Juego</th>
          <th>Paquete</th>
          <th class="text-center">Unidades vendidas</th>
          <th class="text-center">Pedidos</th>
          <th>Ingresos</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($productList as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['juego'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($p['paquete'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="text-center" style="color:#00ffb3;font-weight:700;"><?= $p['unidades'] ?></td>
          <td class="text-center"><?= $p['pedidos'] ?></td>
          <td>
            <?php foreach ($p['ingresos'] as $cur => $amt): ?>
              <span class="stats-money-badge"><?= htmlspecialchars($cur, ENT_QUOTES, 'UTF-8') ?> <?= stats_format_money($amt) ?></span>
            <?php endforeach; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
  var dailySeries = <?= $dailySeriesJson ?: '[]' ?>;
  var topProducts = <?= $topProductsJson ?: '[]' ?>;

  if (typeof Chart === 'undefined') {
    return;
  }

  function paletteColor(i, total) {
    var hue = Math.round((360 / Math.max(total, 1)) * i);
    return 'hsl(' + hue + ', 80%, 55%)';
  }

  function baseOptions(type, extra) {
    var isPie = type === 'pie';
    return Object.assign({
      responsive: true,
      plugins: {
        legend: { display: isPie, position: 'right', labels: { color: '#8be9fd', boxWidth: 12 } }
      },
      scales: isPie ? {} : {
        x: { ticks: { color: '#8be9fd' }, grid: { color: 'rgba(0,255,247,0.08)' } },
        y: { ticks: { color: '#8be9fd' }, grid: { color: 'rgba(0,255,247,0.08)' } }
      }
    }, extra || {});
  }

  function buildDailyDataset(type) {
    var labels = dailySeries.map(function (d) { return d.fecha.slice(5); });
    var data = dailySeries.map(function (d) { return d.total; });
    if (type === 'pie') {
      return { type: 'pie', data: { labels: labels, datasets: [{ data: data, backgroundColor: labels.map(function (_, i) { return paletteColor(i, labels.length); }) }] } };
    }
    if (type === 'bar') {
      return { type: 'bar', data: { labels: labels, datasets: [{ label: 'Ventas', data: data, backgroundColor: '#00fff7' }] } };
    }
    return { type: 'line', data: { labels: labels, datasets: [{ label: 'Ventas', data: data, borderColor: '#00fff7', backgroundColor: 'rgba(0,255,247,0.15)', fill: true, tension: 0.3, pointRadius: 2 }] } };
  }

  function buildProductsDataset(type) {
    var labels = topProducts.map(function (p) { return p.label; });
    var data = topProducts.map(function (p) { return p.unidades; });
    if (type === 'pie') {
      return { type: 'pie', data: { labels: labels, datasets: [{ data: data, backgroundColor: labels.map(function (_, i) { return paletteColor(i, labels.length); }) }] } };
    }
    if (type === 'horizontalBar') {
      return { type: 'bar', data: { labels: labels, datasets: [{ label: 'Unidades', data: data, backgroundColor: '#00ffb3' }] }, options: { indexAxis: 'y' } };
    }
    return { type: 'bar', data: { labels: labels, datasets: [{ label: 'Unidades', data: data, backgroundColor: '#00ffb3' }] } };
  }

  function makeSwitchableChart(canvasId, builder, defaultType) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var chart = null;
    function render(type) {
      var cfg = builder(type);
      var options = baseOptions(type, cfg.options);
      if (chart) chart.destroy();
      chart = new Chart(canvas.getContext('2d'), { type: cfg.type, data: cfg.data, options: options });
    }
    render(defaultType);
    var toggle = document.querySelector('[data-chart-toggle="' + canvasId + '"]');
    if (toggle) {
      toggle.querySelectorAll('.chart-toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          toggle.querySelectorAll('.chart-toggle-btn').forEach(function (b) { b.classList.remove('is-active'); });
          btn.classList.add('is-active');
          render(btn.dataset.type);
        });
      });
    }
  }

  makeSwitchableChart('sales-by-date-chart', buildDailyDataset, 'line');
  makeSwitchableChart('top-products-chart', buildProductsDataset, 'bar');

  var currencySelect = document.getElementById('currency-select');
  if (currencySelect) {
    currencySelect.addEventListener('change', function () {
      var url = new URL(window.location.href);
      url.searchParams.set('moneda', this.value);
      window.location.href = url.toString();
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
