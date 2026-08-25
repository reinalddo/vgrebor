<?php
/**
 * Dashboard del Gestor de Streaming — sistema de diseño índigo (igual al panel de recargas).
 * KPIs + gráficos (Chart.js) + Últimas Ventas + Top Productos + Mis Tareas + Centro de Alertas.
 * Los widgets de DINERO se ocultan a empleados ($verCostos = solo dueño). Lógica de datos intacta.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../_streaming.php';
admin_require_login();
if (!admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$verCostos = admin_es_admin();
$pdo = db();
$OWNER = (int) stream_owner_id();
$uid = current_user_id();

// ── POST: Mis Tareas ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['accion'] ?? '';
  try {
    if ($a === 'tarea_add') {
      $t = trim((string) ($_POST['texto'] ?? ''));
      if ($t !== '') $pdo->prepare("INSERT INTO streaming_tareas (usuario_id,texto) VALUES (?,?)")->execute([$uid, $t]);
    } elseif ($a === 'tarea_toggle') {
      $pdo->prepare("UPDATE streaming_tareas SET hecho=1-hecho WHERE id=? AND usuario_id=?")->execute([(int) $_POST['id'], $uid]);
    } elseif ($a === 'tarea_del') {
      $pdo->prepare("DELETE FROM streaming_tareas WHERE id=? AND usuario_id=?")->execute([(int) $_POST['id'], $uid]);
    }
  } catch (Throwable $e) {}
  header('Location: dashboard.php');
  exit;
}

$k = st_kpis($pdo);
$totalClientes = (int) ($pdo->query("SELECT COUNT(*) FROM streaming_clientes WHERE owner_id=$OWNER")->fetchColumn() ?: 0);
$perfVendidos = max(0, (int) $k['perfiles'] - (int) $k['libres']);
$proyeccion = (float) ($pdo->query("SELECT COALESCE(SUM(precio),0) FROM streaming_ventas WHERE owner_id=$OWNER AND estado='activa'")->fetchColumn() ?: 0);
$masVendido = $pdo->query("SELECT plataforma, COUNT(*) n FROM streaming_ventas WHERE owner_id=$OWNER AND estado<>'cancelada' AND MONTH(creado_en)=MONTH(NOW()) AND YEAR(creado_en)=YEAR(NOW()) GROUP BY plataforma ORDER BY n DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: ['plataforma' => '—', 'n' => 0];
$tasaAband = ($k['activas'] + $k['vencidas']) > 0 ? round($k['vencidas'] / ($k['activas'] + $k['vencidas']) * 100, 1) : 0;
$nuevosMes = (int) ($pdo->query("SELECT COUNT(*) FROM streaming_clientes WHERE owner_id=$OWNER AND MONTH(creado_en)=MONTH(NOW()) AND YEAR(creado_en)=YEAR(NOW())")->fetchColumn() ?: 0);
$nuevosPrev = (int) ($pdo->query("SELECT COUNT(*) FROM streaming_clientes WHERE owner_id=$OWNER AND creado_en >= DATE_FORMAT(DATE_SUB(NOW(),INTERVAL 1 MONTH),'%Y-%m-01') AND creado_en < DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn() ?: 0);

// Gráficos
$ing12 = array_fill(0, 12, 0.0); $ing12lbl = [];
for ($i = 11; $i >= 0; $i--) $ing12lbl[] = date('M', strtotime("first day of -$i month"));
try {
  foreach ($pdo->query("SELECT DATE_FORMAT(creado_en,'%Y-%m') ym, COALESCE(SUM(precio),0) s FROM streaming_ventas WHERE owner_id=$OWNER AND estado<>'cancelada' AND creado_en >= DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY ym ORDER BY ym") as $r) {
    $idx = 11 - ((((int) date('Y')) * 12 + (int) date('n')) - (((int) substr($r['ym'], 0, 4)) * 12 + (int) substr($r['ym'], 5, 2)));
    if ($idx >= 0 && $idx < 12) $ing12[$idx] = (float) $r['s'];
  }
} catch (Throwable $e) {}
$top = $pdo->query("SELECT plataforma, COUNT(*) n FROM streaming_ventas WHERE owner_id=$OWNER AND estado<>'cancelada' GROUP BY plataforma ORDER BY n DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$ultimas = $pdo->query("SELECT v.id, COALESCE(cl.nombre,v.cliente_nombre) cliente, v.plataforma, v.precio FROM streaming_ventas v LEFT JOIN streaming_clientes cl ON cl.id=v.cliente_id WHERE v.owner_id=$OWNER AND v.estado<>'cancelada' ORDER BY v.id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$stockBajo = $pdo->query("SELECT c.plataforma, SUM(CASE WHEN p.estado='libre' THEN 1 ELSE 0 END) libres FROM streaming_cuentas c LEFT JOIN streaming_perfiles p ON p.cuenta_id=c.id WHERE c.owner_id=$OWNER GROUP BY c.plataforma HAVING libres <= 1 ORDER BY libres ASC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$tareas = $pdo->prepare("SELECT id, texto, hecho FROM streaming_tareas WHERE usuario_id=? ORDER BY hecho, id DESC LIMIT 20");
$tareas->execute([$uid]); $tareas = $tareas->fetchAll(PDO::FETCH_ASSOC);
$tareasPend = count(array_filter($tareas, fn($t) => !$t['hecho']));

$hora = (int) date('H');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$u = get_current_user_data() ?: [];
$primer = trim(explode(' ', trim((string) ($u['nombre'] ?? 'Conec')))[0] ?? '');
$diasSem = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

// KPIs: [label, valor, icono, clase, sub]
$cards = [];
if ($verCostos) $cards[] = ['Ventas del mes',     '$' . number_format((float) $k['mes'], 2),          'dollar-sign',   'acc',  'este mes'];
if ($verCostos) $cards[] = ['Ganancia del mes',   '$' . number_format((float) $k['ganancia_mes'], 2), 'scale',         'acc',  'costo $' . number_format((float) $k['costo_mes'], 2)];
$cards[] = ['Más vendido (mes)',      $masVendido['plataforma'] . ' (' . (int) $masVendido['n'] . ')', 'star',        '',     'este mes'];
$cards[] = ['Total clientes',         (string) $totalClientes, 'users',        '',     '+' . $nuevosMes . ' nuevos este mes'];
$cards[] = ['Total cuentas',          (string) $k['cuentas'],  'monitor',      '',     'en inventario'];
$cards[] = ['Perfiles disponibles',   (string) $k['libres'],   'box',          '',     'de ' . (int) $k['perfiles'] . ' · ' . $perfVendidos . ' vendidos'];
$cards[] = ['Perfiles vendidos',      (string) $perfVendidos,  'shopping-cart','',     'activos'];
$cards[] = ['Próximas renovaciones',  (string) $k['semana'],   'alarm-clock',  'flag', 'vencen en 7 días'];
$cards[] = ['Cobros vencidos',        (string) $k['vencidas'], 'credit-card',  'flag', 'por renovar'];
$cards[] = ['Tasa de abandono',       $tasaAband . '%',        'user-minus',   '',     'del total de ventas'];
if ($verCostos) $cards[] = ['Proyección activa', '$' . number_format($proyeccion, 2), 'trending-up', 'acc', 'ventas vigentes'];

stream_head('Dashboard', 'dashboard');
?>
<div class="pagehd">
  <div>
    <h1><?= h($saludo) ?>, <span class="nm"><?= h($primer ?: 'Conec') ?></span> 👋</h1>
    <p>Resumen de ingresos, perfiles y salud de las ventas de streaming.</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="datechip"><i data-lucide="calendar"></i> <?= h($diasSem[(int) date('w')]) ?>, <?= date('d/m/Y') ?></span>
    <a href="dashboard.php" class="btn primary"><i data-lucide="refresh-cw"></i> Actualizar</a>
  </div>
</div>

<!-- KPIs -->
<div class="kpis" style="margin-bottom:16px">
  <?php foreach ($cards as [$lbl, $val, $icon, $cls, $sub]): ?>
    <div class="kpi <?= $cls ?>">
      <div class="k-top"><div class="k-ic"><i data-lucide="<?= h($icon) ?>"></i></div> <?= h($lbl) ?></div>
      <h3 class="tnum"<?= mb_strlen($val) > 9 ? ' style="font-size:18px"' : '' ?>><?= h($val) ?></h3>
      <div class="k-sub"><?= h($sub) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Gráficos -->
<div class="cols" style="margin-bottom:16px">
  <div class="card">
    <div class="card-hd"><i data-lucide="trending-up"></i><h2>Ingresos · últimos 12 meses</h2></div>
    <div class="card-bd"><canvas id="chIng" height="110"></canvas></div>
  </div>
  <div class="card">
    <div class="card-hd"><i data-lucide="<?= $verCostos ? 'pie-chart' : 'bar-chart-3' ?>"></i><h2><?= $verCostos ? 'Ingresos vs Gastos (mes)' : 'Top plataformas' ?></h2></div>
    <div class="card-bd"><canvas id="<?= $verCostos ? 'chVs' : 'chTop2' ?>" height="150"></canvas></div>
  </div>
</div>

<div class="cols3" style="margin-bottom:16px">
  <!-- Últimas ventas -->
  <div class="card">
    <div class="card-hd"><i data-lucide="receipt"></i><h2>Últimas ventas</h2></div>
    <?php if ($ultimas): foreach ($ultimas as $v): ?>
      <div class="lrow">
        <div class="l-main"><b><?= h($v['cliente'] ?: '—') ?></b><span><?= h($v['plataforma']) ?> · <span style="font-family:ui-monospace,monospace;color:var(--accent)"><?= h(stream_cod_pedido((int) $v['id'])) ?></span></span></div>
        <span class="amt">$<?= number_format((float) $v['precio'], 2) ?></span>
      </div>
    <?php endforeach; else: ?><div class="empty">Sin ventas todavía.</div><?php endif; ?>
  </div>
  <!-- Top productos -->
  <div class="card">
    <div class="card-hd"><i data-lucide="bar-chart-3"></i><h2>Top plataformas</h2></div>
    <div class="card-bd"><canvas id="chTop" height="160"></canvas></div>
  </div>
  <!-- Mis tareas -->
  <div class="card">
    <div class="card-hd"><i data-lucide="list-checks"></i><h2>Mis tareas</h2><span class="pill-count"><?= $tareasPend ?> pendientes</span></div>
    <form method="post" class="taskform">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="tarea_add">
      <input name="texto" placeholder="Ej: Comprar pantallas Netflix…">
      <button class="addbtn" type="submit"><i data-lucide="plus"></i></button>
    </form>
    <div class="thin" style="max-height:220px;overflow-y:auto">
      <?php foreach ($tareas as $t): ?>
        <div class="task <?= $t['hecho'] ? 'done' : '' ?>">
          <form method="post" style="display:contents"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="tarea_toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="chk <?= $t['hecho'] ? 'done' : '' ?>" type="submit"><?php if ($t['hecho']): ?><i data-lucide="check"></i><?php endif; ?></button>
          </form>
          <span class="t-tx"><?= h($t['texto']) ?></span>
          <form method="post" style="display:contents"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="tarea_del"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <button class="t-del" type="submit"><i data-lucide="x"></i></button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$tareas): ?><div class="empty">¡Todo al día!</div><?php endif; ?>
    </div>
  </div>
</div>

<!-- Centro de Alertas -->
<div class="card">
  <div class="card-hd"><i data-lucide="bell"></i><h2>Centro de alertas</h2>
    <div style="margin-left:auto;display:flex;gap:8px">
      <span class="pill wait">Cobros: <?= (int) $k['vencidas'] ?></span>
      <span class="pill err">Stock: <?= count($stockBajo) ?></span>
    </div>
  </div>
  <div class="alertgrid">
    <div class="alertbox warn">
      <div class="ah"><i data-lucide="credit-card"></i> Cobros pendientes</div>
      <div><?= (int) $k['vencidas'] ?> venta(s) vencida(s) por renovar · <a href="ventas.php?f=vencidas">ver vencimientos →</a></div>
    </div>
    <div class="alertbox bad">
      <div class="ah"><i data-lucide="package"></i> Stock crítico</div>
      <?php if ($stockBajo): ?>
        <div><?php foreach ($stockBajo as $i => $s): echo ($i ? ' · ' : '') . '<b>' . h($s['plataforma']) . '</b>: ' . (int) $s['libres'] . ' disp.'; endforeach; ?></div>
      <?php else: ?><div>Sin stock crítico 👍</div><?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
  (function(){
    var dark = document.documentElement.getAttribute('data-theme')==='dark';
    if(window.Chart){ Chart.defaults.color = dark ? '#98a1b1' : '#5c6674'; Chart.defaults.borderColor = dark ? '#232834' : '#e7eaf0'; Chart.defaults.font.family = 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif'; }
  })();
  const opt={responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:document.documentElement.getAttribute('data-theme')==='dark'?'#232834':'#eef1f6'}},x:{grid:{display:false}}}};
  new Chart(document.getElementById('chIng'),{type:'line',data:{labels:<?= json_encode($ing12lbl) ?>,datasets:[{data:<?= json_encode(array_values($ing12)) ?>,borderColor:'#3f4fb5',backgroundColor:'rgba(63,79,181,.14)',fill:true,tension:.35,pointRadius:0,pointHoverRadius:4}]},options:opt});
  const topL=<?= json_encode(array_column($top, 'plataforma')) ?>, topN=<?= json_encode(array_map('intval', array_column($top, 'n'))) ?>;
  const topCfg={type:'bar',data:{labels:topL,datasets:[{data:topN,backgroundColor:'#5866c9',borderRadius:6,maxBarThickness:34}]},options:opt};
  if(document.getElementById('chTop')) new Chart(document.getElementById('chTop'),topCfg);
  if(document.getElementById('chTop2')) new Chart(document.getElementById('chTop2'),topCfg);
  <?php if ($verCostos): ?>
  new Chart(document.getElementById('chVs'),{type:'doughnut',data:{labels:['Ingresos','Gastos'],datasets:[{data:[<?= (float) $k['mes'] ?>,<?= (float) $k['costo_mes'] ?>],backgroundColor:['#12915a','#cf3a53'],borderWidth:0}]},options:{responsive:true,cutout:'62%',plugins:{legend:{position:'bottom'}}}});
  <?php endif; ?>
  if(window.lucide) lucide.createIcons();
</script>
<?php stream_foot(); ?>
