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

function stats_build_url(string $base, array $params): string {
    $query = array_filter($params, static function ($v) {
        return $v !== null && $v !== '';
    });
    return $base . ($query !== [] ? '?' . http_build_query($query) : '');
}

$baseUrl = app_path('/admin/estadisticas');

// ── Pestaña activa ────────────────────────────────────────────────────────
$activeTab = trim((string) ($_GET['tab'] ?? 'resumen'));
$allowedTabs = ['resumen', 'productos', 'detalle', 'ganancia'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'resumen';
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

// ── Juegos disponibles en el rango (para el filtro de "Productos más vendidos") ──
$availableGames = [];
$stmtGames = $mysqli->prepare(
    "SELECT DISTINCT COALESCE(NULLIF(juego_nombre,''), 'Juego eliminado') AS juego
     FROM pedidos
     WHERE estado IN ('enviado','pagado') AND creado_en BETWEEN ? AND ?
     ORDER BY juego ASC"
);
if ($stmtGames) {
    $stmtGames->bind_param('ss', $rangeStartSql, $rangeEndSql);
    $stmtGames->execute();
    $resGames = $stmtGames->get_result();
    while ($row = $resGames->fetch_assoc()) {
        $availableGames[] = $row['juego'];
    }
    $stmtGames->close();
}

$selectedGame = trim((string) ($_GET['juego'] ?? ''));
if ($selectedGame !== '' && !in_array($selectedGame, $availableGames, true)) {
    $selectedGame = '';
}

// ── Productos vendidos: unidades (agnóstico de moneda) + ingreso por moneda ──
$productUnits = [];
$unitsSql = "SELECT COALESCE(NULLIF(juego_nombre,''), 'Juego eliminado') AS juego,
            COALESCE(NULLIF(paquete_nombre,''), 'Paquete eliminado') AS paquete,
            SUM(COALESCE(cantidad_compra,1)) AS unidades,
            COUNT(*) AS pedidos
     FROM pedidos
     WHERE estado IN ('enviado','pagado') AND creado_en BETWEEN ? AND ?";
$unitsParamTypes = 'ss';
$unitsParams = [$rangeStartSql, $rangeEndSql];
if ($selectedGame !== '') {
    $unitsSql .= " AND COALESCE(NULLIF(juego_nombre,''), 'Juego eliminado') = ?";
    $unitsParamTypes .= 's';
    $unitsParams[] = $selectedGame;
}
$unitsSql .= ' GROUP BY juego, paquete ORDER BY unidades DESC';

$stmtUnits = $mysqli->prepare($unitsSql);
if ($stmtUnits) {
    $stmtUnits->bind_param($unitsParamTypes, ...$unitsParams);
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
    $revenueSql = "SELECT COALESCE(NULLIF(juego_nombre,''), 'Juego eliminado') AS juego,
                COALESCE(NULLIF(paquete_nombre,''), 'Paquete eliminado') AS paquete,
                moneda,
                SUM(precio) AS total
         FROM pedidos
         WHERE estado IN ('enviado','pagado') AND creado_en BETWEEN ? AND ?";
    $revenueParamTypes = 'ss';
    $revenueParams = [$rangeStartSql, $rangeEndSql];
    if ($selectedGame !== '') {
        $revenueSql .= " AND COALESCE(NULLIF(juego_nombre,''), 'Juego eliminado') = ?";
        $revenueParamTypes .= 's';
        $revenueParams[] = $selectedGame;
    }
    $revenueSql .= ' GROUP BY juego, paquete, moneda';

    $stmtRevenue = $mysqli->prepare($revenueSql);
    if ($stmtRevenue) {
        $stmtRevenue->bind_param($revenueParamTypes, ...$revenueParams);
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
$topProductsLimit = $selectedGame !== '' ? 20 : 12;
$topProducts = array_slice($productList, 0, $topProductsLimit);

$dailySeriesJson = json_encode($dailySeries, JSON_UNESCAPED_UNICODE);
$topProductsJson = json_encode(array_map(static function (array $p) use ($selectedGame): array {
    $label = $selectedGame !== '' ? $p['paquete'] : ($p['juego'] . ' — ' . $p['paquete']);
    return ['label' => $label, 'unidades' => $p['unidades']];
}, $topProducts), JSON_UNESCAPED_UNICODE);

// ── Ganancia (venta - costo, siempre en USD/base) ────────────────────────
// Solo cuenta pedidos con costo capturado; los que no tienen quedan fuera
// y se informan como "sin costo registrado" para transparencia.
$profitDailySeries = [];
$profitTotal = 0.0;
$ordersWithCost = 0;
$ordersWithoutCost = 0;
$stmtProfitTotals = $mysqli->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN costo_unitario_base IS NOT NULL THEN 1 ELSE 0 END) AS con_costo,
            SUM(CASE WHEN costo_unitario_base IS NOT NULL THEN (precio_venta_unitario_base - costo_unitario_base) * cantidad_compra ELSE 0 END) AS ganancia
     FROM pedidos
     WHERE estado IN ('enviado','pagado') AND creado_en BETWEEN ? AND ?"
);
if ($stmtProfitTotals) {
    $stmtProfitTotals->bind_param('ss', $rangeStartSql, $rangeEndSql);
    $stmtProfitTotals->execute();
    $profitTotalsRow = $stmtProfitTotals->get_result()->fetch_assoc();
    $stmtProfitTotals->close();
    if ($profitTotalsRow) {
        $totalOrdersForProfit = (int) ($profitTotalsRow['total'] ?? 0);
        $ordersWithCost = (int) ($profitTotalsRow['con_costo'] ?? 0);
        $ordersWithoutCost = max(0, $totalOrdersForProfit - $ordersWithCost);
        $profitTotal = (float) ($profitTotalsRow['ganancia'] ?? 0);
    }
}

$stmtProfitDaily = $mysqli->prepare(
    "SELECT DATE(creado_en) AS dia, SUM((precio_venta_unitario_base - costo_unitario_base) * cantidad_compra) AS ganancia
     FROM pedidos
     WHERE estado IN ('enviado','pagado') AND costo_unitario_base IS NOT NULL AND creado_en BETWEEN ? AND ?
     GROUP BY DATE(creado_en)
     ORDER BY dia ASC"
);
if ($stmtProfitDaily) {
    $stmtProfitDaily->bind_param('ss', $rangeStartSql, $rangeEndSql);
    $stmtProfitDaily->execute();
    $resProfitDaily = $stmtProfitDaily->get_result();
    $profitByDate = [];
    while ($row = $resProfitDaily->fetch_assoc()) {
        $profitByDate[$row['dia']] = (float) $row['ganancia'];
    }
    $stmtProfitDaily->close();

    $cursorProfit = $dateFrom;
    $guardProfit = 0;
    while ($cursorProfit <= $dateTo && $guardProfit < 400) {
        $key = $cursorProfit->format('Y-m-d');
        $profitDailySeries[] = ['fecha' => $key, 'ganancia' => $profitByDate[$key] ?? 0.0];
        $cursorProfit = $cursorProfit->modify('+1 day');
        $guardProfit++;
    }
}
$profitDailySeriesJson = json_encode($profitDailySeries, JSON_UNESCAPED_UNICODE);

$presetLabels = ['hoy' => 'Hoy', 'semana' => 'Últimos 7 días', 'mes' => 'Este mes', 'personalizado' => 'Personalizado'];

$clearGameParams = $_GET;
unset($clearGameParams['juego']);
$clearGameUrl = stats_build_url($baseUrl, $clearGameParams);
?>
<main class="container-lg mt-5 mb-5 px-2">
  <style>
    .stat-card { background:#181f2a; border:1px solid #00fff7; border-radius:12px; padding:1.1rem 0.75rem; box-shadow:0 0 12px rgba(0,255,247,0.08); height:100%; }
    .stat-card .stat-value { color:#00ffb3; font-size:1.35rem; font-weight:800; word-break:break-word; }
    .stat-card .stat-label { color:#8be9fd; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; }

    .preset-btn { border:1px solid #00fff7; color:#00fff7; background:#181f2a; border-radius:999px; padding:0.4rem 1.1rem; font-weight:700; font-size:0.85rem; }
    .preset-btn.is-active { background:#00fff7; color:#181f2a; box-shadow:0 0 10px rgba(0,255,247,0.5); }

    .stats-tab-btn { border:1px solid #00fff7; color:#00fff7; background:#181f2a; border-radius:999px; padding:0.55rem 1.35rem; font-weight:700; font-size:0.9rem; transition:all .15s ease; cursor:pointer; }
    .stats-tab-btn.is-active { background:linear-gradient(90deg,#00fff7,#00ffb3); color:#0b1220; box-shadow:0 0 14px rgba(0,255,247,0.5); border-color:transparent; }
    .stats-tab-btn:hover:not(.is-active) { border-color:#00ffb3; color:#00ffb3; }

    .chart-card { background:#181f2a; border:1px solid #00fff7; border-radius:14px; padding:1.4rem; position:relative; overflow:hidden; box-shadow:0 0 18px rgba(0,255,247,0.06); }
    .chart-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,#00fff7,#7b2fff,#00ffb3); }
    .chart-card-header { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:0.75rem; margin-bottom:1.1rem; }
    .chart-card-title { color:#00fff7; font-weight:800; font-size:1.05rem; margin:0; }
    .chart-canvas-wrap { position:relative; height:360px; }

    .chart-toggle-btn { border:1px solid #00fff7; color:#00fff7; background:#181f2a; border-radius:999px; padding:0.3rem 0.9rem; font-size:0.78rem; font-weight:700; }
    .chart-toggle-btn.is-active { background:#00fff7; color:#181f2a; box-shadow:0 0 10px rgba(0,255,247,0.5); }

    .stats-table { background:#181f2a; color:#e2e8f0; }
    .stats-table thead th { color:#00fff7; border-bottom:2px solid #00fff7; background:#181f2a; }
    .stats-table tbody tr { border-bottom:1px solid #222c3a; }
    .stats-money-badge { background:#0f1a28; color:#00ffb3; border:1px solid #1e3a5f; border-radius:999px; padding:0.15rem 0.55rem; font-size:0.78rem; margin-right:0.3rem; display:inline-block; margin-bottom:0.2rem; }

    .stats-filter-select { width:auto; background:#222c3a; color:#00fff7; border:1px solid #00fff7; }
    .stats-panel { display:none; }
    .stats-panel.is-active { display:block; }
  </style>

  <div class="row mb-4">
    <div class="col-12 text-center">
      <p class="text-uppercase text-info mb-1">Panel</p>
      <h1 class="display-5 fw-bold text-info mb-2">Estadísticas</h1>
      <p class="text-secondary">Ventas, productos más vendidos y tendencia por fechas.</p>
    </div>
  </div>

  <form method="get" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-2 justify-content-center align-items-end mb-4">
    <input type="hidden" name="moneda" value="<?= htmlspecialchars($selectedCurrency, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="juego" value="<?= htmlspecialchars($selectedGame, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="tab" id="tab-state-input" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
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

  <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
    <button type="button" class="stats-tab-btn <?= $activeTab === 'resumen' ? 'is-active' : '' ?>" data-tab="resumen">📊 Resumen</button>
    <button type="button" class="stats-tab-btn <?= $activeTab === 'productos' ? 'is-active' : '' ?>" data-tab="productos">🏆 Productos más vendidos</button>
    <button type="button" class="stats-tab-btn <?= $activeTab === 'detalle' ? 'is-active' : '' ?>" data-tab="detalle">📋 Detalle por producto</button>
    <button type="button" class="stats-tab-btn <?= $activeTab === 'ganancia' ? 'is-active' : '' ?>" data-tab="ganancia">💰 Ganancia</button>
  </div>

  <!-- ── Resumen ─────────────────────────────────────────────────────── -->
  <section class="stats-panel <?= $activeTab === 'resumen' ? 'is-active' : '' ?>" data-panel="resumen">
    <h2 class="h5 text-info mb-3 text-center">Ventas totales <small class="text-secondary"><?= htmlspecialchars($dateFromStr, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($dateToStr, ENT_QUOTES, 'UTF-8') ?></small></h2>
    <?php if ($totalsByCurrency === []): ?>
      <p class="text-secondary text-center mb-5">No hay ventas registradas en este rango.</p>
    <?php else: ?>
    <div class="row g-3 mb-4">
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
    <div class="chart-card">
      <div class="chart-card-header">
        <h3 class="chart-card-title">Ventas por fecha</h3>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <?php if (count($availableCurrencies) > 1): ?>
          <select id="currency-select" class="form-select form-select-sm stats-filter-select">
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
      <div class="chart-canvas-wrap"><canvas id="sales-by-date-chart"></canvas></div>
    </div>
    <?php endif; ?>
  </section>

  <!-- ── Productos más vendidos ──────────────────────────────────────── -->
  <section class="stats-panel <?= $activeTab === 'productos' ? 'is-active' : '' ?>" data-panel="productos">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <label class="form-label mb-0" style="color:#00fff7;">Filtrar por juego:</label>
      <select class="form-select form-select-sm stats-filter-select game-filter-select">
        <option value="">Todos los juegos</option>
        <?php foreach ($availableGames as $g): ?>
          <option value="<?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?>" <?= $g === $selectedGame ? 'selected' : '' ?>><?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($selectedGame !== ''): ?>
        <a href="<?= htmlspecialchars($clearGameUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-info btn-sm" style="border-color:#00fff7;color:#00fff7;">✕ Quitar filtro</a>
      <?php endif; ?>
    </div>

    <?php if ($topProducts !== []): ?>
    <div class="chart-card">
      <div class="chart-card-header">
        <h3 class="chart-card-title">
          Productos más vendidos <small class="text-secondary">(unidades<?= $selectedGame !== '' ? ' — ' . htmlspecialchars($selectedGame, ENT_QUOTES, 'UTF-8') : '' ?>)</small>
        </h3>
        <div class="d-flex gap-1" data-chart-toggle="top-products-chart">
          <button type="button" class="chart-toggle-btn is-active" data-type="bar">Barras</button>
          <button type="button" class="chart-toggle-btn" data-type="horizontalBar">Barras horiz.</button>
          <button type="button" class="chart-toggle-btn" data-type="pie">Torta</button>
        </div>
      </div>
      <div class="chart-canvas-wrap"><canvas id="top-products-chart"></canvas></div>
    </div>
    <?php else: ?>
      <p class="text-secondary text-center">No hay productos vendidos en este rango<?= $selectedGame !== '' ? ' para ' . htmlspecialchars($selectedGame, ENT_QUOTES, 'UTF-8') : '' ?>.</p>
    <?php endif; ?>
  </section>

  <!-- ── Detalle por producto ────────────────────────────────────────── -->
  <section class="stats-panel <?= $activeTab === 'detalle' ? 'is-active' : '' ?>" data-panel="detalle">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <label class="form-label mb-0" style="color:#00fff7;">Filtrar por juego:</label>
      <select class="form-select form-select-sm stats-filter-select game-filter-select">
        <option value="">Todos los juegos</option>
        <?php foreach ($availableGames as $g): ?>
          <option value="<?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?>" <?= $g === $selectedGame ? 'selected' : '' ?>><?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($selectedGame !== ''): ?>
        <a href="<?= htmlspecialchars($clearGameUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-info btn-sm" style="border-color:#00fff7;color:#00fff7;">✕ Quitar filtro</a>
      <?php endif; ?>
    </div>

    <?php if ($productList === []): ?>
      <p class="text-secondary text-center">No hay productos vendidos en este rango<?= $selectedGame !== '' ? ' para ' . htmlspecialchars($selectedGame, ENT_QUOTES, 'UTF-8') : '' ?>.</p>
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
  </section>

  <!-- ── Ganancia ─────────────────────────────────────────────────────── -->
  <section class="stats-panel <?= $activeTab === 'ganancia' ? 'is-active' : '' ?>" data-panel="ganancia">
    <h2 class="h5 text-info mb-3 text-center">Ganancia (USD) <small class="text-secondary"><?= htmlspecialchars($dateFromStr, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($dateToStr, ENT_QUOTES, 'UTF-8') ?></small></h2>
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-4">
        <div class="stat-card text-center">
          <div class="stat-value">$<?= stats_format_money($profitTotal) ?></div>
          <div class="stat-label">Ganancia total</div>
        </div>
      </div>
      <div class="col-6 col-md-4">
        <div class="stat-card text-center">
          <div class="stat-value"><?= $ordersWithCost ?></div>
          <div class="stat-label">Pedidos con costo registrado</div>
        </div>
      </div>
      <div class="col-6 col-md-4">
        <div class="stat-card text-center">
          <div class="stat-value" style="color:<?= $ordersWithoutCost > 0 ? '#facc15' : '#00ffb3' ?>;"><?= $ordersWithoutCost ?></div>
          <div class="stat-label">Pedidos sin costo (no cuentan)</div>
        </div>
      </div>
    </div>
    <?php if ($ordersWithoutCost > 0): ?>
      <p class="text-center small mb-4" style="color:#facc15;">Hay <?= $ordersWithoutCost ?> pedido(s) en este rango sin costo registrado — no se incluyen en la ganancia. Revisa <a href="<?= htmlspecialchars(app_path('/admin/costos'), ENT_QUOTES, 'UTF-8') ?>" style="color:#00fff7;">Registrar Costos</a> para completarlos.</p>
    <?php endif; ?>

    <?php if ($profitDailySeries !== []): ?>
    <div class="chart-card">
      <div class="chart-card-header">
        <h3 class="chart-card-title">Ganancia por fecha</h3>
        <div class="d-flex gap-1" data-chart-toggle="profit-by-date-chart">
          <button type="button" class="chart-toggle-btn is-active" data-type="line">Línea</button>
          <button type="button" class="chart-toggle-btn" data-type="bar">Barras</button>
          <button type="button" class="chart-toggle-btn" data-type="pie">Torta</button>
        </div>
      </div>
      <div class="chart-canvas-wrap"><canvas id="profit-by-date-chart"></canvas></div>
    </div>
    <?php endif; ?>
  </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
  var dailySeries = <?= $dailySeriesJson ?: '[]' ?>;
  var topProducts = <?= $topProductsJson ?: '[]' ?>;
  var profitDailySeries = <?= $profitDailySeriesJson ?: '[]' ?>;

  // ── Pestañas ──────────────────────────────────────────────────────────
  var tabButtons = Array.from(document.querySelectorAll('.stats-tab-btn'));
  var panels = Array.from(document.querySelectorAll('.stats-panel'));
  var tabStateInput = document.getElementById('tab-state-input');

  function activateTab(tab) {
    panels.forEach(function (p) { p.classList.toggle('is-active', p.dataset.panel === tab); });
    tabButtons.forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === tab); });
    if (tabStateInput) tabStateInput.value = tab;
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tab);
      window.history.replaceState({}, '', url.toString());
    } catch (e) { /* noop */ }
  }
  tabButtons.forEach(function (btn) {
    btn.addEventListener('click', function () { activateTab(btn.dataset.tab); });
  });

  function navigateWithParam(name, value) {
    var url = new URL(window.location.href);
    url.searchParams.set(name, value);
    if (tabStateInput) url.searchParams.set('tab', tabStateInput.value);
    window.location.href = url.toString();
  }
  document.querySelectorAll('.game-filter-select').forEach(function (sel) {
    sel.addEventListener('change', function () { navigateWithParam('juego', this.value); });
  });
  var currencySelect = document.getElementById('currency-select');
  if (currencySelect) {
    currencySelect.addEventListener('change', function () { navigateWithParam('moneda', this.value); });
  }

  // ── Gráficas ──────────────────────────────────────────────────────────
  if (typeof Chart === 'undefined') {
    return;
  }

  var THEME_COLORS = ['#00fff7', '#00ffb3', '#7b2fff', '#facc15', '#38bdf8', '#f472b6', '#a3e635', '#fb923c', '#c084fc', '#34d399', '#fca5a5', '#60a5fa'];
  function themeColor(i) { return THEME_COLORS[i % THEME_COLORS.length]; }

  function baseOptions(type, extra) {
    var isCircular = (type === 'pie' || type === 'doughnut');
    return Object.assign({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: isCircular,
          position: 'bottom',
          labels: { color: '#8be9fd', usePointStyle: true, boxWidth: 8, padding: 14, font: { size: 11 } }
        },
        tooltip: {
          backgroundColor: '#0f1a28',
          borderColor: '#00fff7',
          borderWidth: 1,
          titleColor: '#00fff7',
          bodyColor: '#e2e8f0',
          padding: 10,
          cornerRadius: 8
        }
      },
      scales: isCircular ? {} : {
        x: { ticks: { color: '#8be9fd', font: { size: 11 } }, grid: { color: 'rgba(0,255,247,0.06)' } },
        y: { beginAtZero: true, ticks: { color: '#8be9fd', font: { size: 11 } }, grid: { color: 'rgba(0,255,247,0.06)' } }
      }
    }, extra || {});
  }

  function buildDailyDataset(type) {
    var labels = dailySeries.map(function (d) { return d.fecha.slice(5); });
    var data = dailySeries.map(function (d) { return d.total; });
    if (type === 'pie') {
      return {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: labels.map(function (_, i) { return themeColor(i); }), borderColor: '#181f2a', borderWidth: 2 }] },
        options: { cutout: '58%' }
      };
    }
    if (type === 'bar') {
      return { type: 'bar', data: { labels: labels, datasets: [{ label: 'Ventas', data: data, backgroundColor: '#00fff7', hoverBackgroundColor: '#00ffb3', borderRadius: 8, maxBarThickness: 46 }] } };
    }
    return {
      type: 'line',
      data: { labels: labels, datasets: [{ label: 'Ventas', data: data, borderColor: '#00fff7', backgroundColor: 'rgba(0,255,247,0.18)', borderWidth: 2.5, fill: true, tension: 0.35, pointRadius: 3, pointBackgroundColor: '#00fff7', pointBorderColor: '#0f1a28', pointBorderWidth: 1.5, pointHoverRadius: 6 }] }
    };
  }

  function buildProductsDataset(type) {
    var labels = topProducts.map(function (p) { return p.label; });
    var data = topProducts.map(function (p) { return p.unidades; });
    if (type === 'pie') {
      return {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: labels.map(function (_, i) { return themeColor(i); }), borderColor: '#181f2a', borderWidth: 2 }] },
        options: { cutout: '58%' }
      };
    }
    if (type === 'horizontalBar') {
      return {
        type: 'bar',
        data: { labels: labels, datasets: [{ label: 'Unidades', data: data, backgroundColor: '#00ffb3', hoverBackgroundColor: '#00fff7', borderRadius: 8, maxBarThickness: 26 }] },
        options: { indexAxis: 'y' }
      };
    }
    return { type: 'bar', data: { labels: labels, datasets: [{ label: 'Unidades', data: data, backgroundColor: '#00ffb3', hoverBackgroundColor: '#00fff7', borderRadius: 8, maxBarThickness: 46 }] } };
  }

  function buildProfitDataset(type) {
    var labels = profitDailySeries.map(function (d) { return d.fecha.slice(5); });
    var data = profitDailySeries.map(function (d) { return d.ganancia; });
    if (type === 'pie') {
      return {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: labels.map(function (_, i) { return themeColor(i); }), borderColor: '#181f2a', borderWidth: 2 }] },
        options: { cutout: '58%' }
      };
    }
    if (type === 'bar') {
      return { type: 'bar', data: { labels: labels, datasets: [{ label: 'Ganancia', data: data, backgroundColor: '#facc15', hoverBackgroundColor: '#00ffb3', borderRadius: 8, maxBarThickness: 46 }] } };
    }
    return {
      type: 'line',
      data: { labels: labels, datasets: [{ label: 'Ganancia', data: data, borderColor: '#facc15', backgroundColor: 'rgba(250,204,21,0.18)', borderWidth: 2.5, fill: true, tension: 0.35, pointRadius: 3, pointBackgroundColor: '#facc15', pointBorderColor: '#0f1a28', pointBorderWidth: 1.5, pointHoverRadius: 6 }] }
    };
  }

  function makeSwitchableChart(canvasId, builder, defaultType) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var wrap = canvas.closest('.chart-canvas-wrap');
    var chart = null;
    function render(type) {
      var cfg = builder(type);
      var options = baseOptions(type, cfg.options);
      if (wrap) {
        if (type === 'horizontalBar') {
          var count = (cfg.data.labels || []).length;
          wrap.style.height = Math.min(760, Math.max(320, count * 32)) + 'px';
        } else {
          wrap.style.height = '';
        }
      }
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
  makeSwitchableChart('profit-by-date-chart', buildProfitDataset, 'line');
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
