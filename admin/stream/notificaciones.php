<?php
/**
 * NOTIFICACIONES / HISTORIAL DE CAMBIOS (Fase 3).
 * Lista en línea todo lo que pasó: recargas de saldo, compras del stock, ventas, renovaciones,
 * solicitudes de cambio de perfil/PIN, ediciones de cuentas completas y cambios de credenciales.
 *   - Panel del ADMIN (owner 0): ve lo que hacen sus revendedores.
 *   - Panel del REVENDEDOR: ve lo que le afecta a él (asignaciones, cambios de clave, sus compras).
 * Aislado por owner_id del contexto: nadie ve las notificaciones de otro.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../_streaming.php';
require_once __DIR__ . '/../../api/_rev_avisos.php';
admin_require_login();
if (!admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$pdo = db();
$OWNER = (int) stream_owner_id();
$csrf = csrf_token();
$msg = '';

// AJAX (sondeo en tiempo real del layout): no leídas totales + de tickets + la última, para el tono/aviso.
if (($_GET['ajax'] ?? '') === 'count') {
  header('Content-Type: application/json; charset=utf-8');
  $tot = 0; $tk = 0; $last = null;
  try { $tot = (int) stream_notif_no_leidas($pdo, $OWNER); } catch (Throwable $e) {}
  try { $st = $pdo->prepare("SELECT COUNT(*) FROM stream_notificaciones WHERE owner_id=? AND leido=0 AND tipo='ticket'"); $st->execute([$OWNER]); $tk = (int) $st->fetchColumn(); } catch (Throwable $e) {}
  try { $st = $pdo->prepare("SELECT titulo, url, tipo FROM stream_notificaciones WHERE owner_id=? AND leido=0 ORDER BY id DESC LIMIT 1"); $st->execute([$OWNER]); $last = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
  echo json_encode(['total' => $tot, 'ticket' => $tk, 'last' => $last]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = (string) ($_POST['accion'] ?? '');
  if ($a === 'marcar_todas') {
    stream_notif_marcar_leidas($pdo, $OWNER);
    $msg = '✓ Todas marcadas como leídas.';
  } elseif ($a === 'marcar_una') {
    stream_notif_marcar_leidas($pdo, $OWNER, (int) ($_POST['id'] ?? 0));
    $msg = '✓ Marcada como leída.';
  }
  header('Location: notificaciones.php?msg=' . urlencode($msg));
  exit;
}

$flash = (string) ($_GET['msg'] ?? '');
$filtro = (string) ($_GET['t'] ?? '');
// Filtro por RANGO DE FECHAS (para el historial de compras y demás). Formato AAAA-MM-DD.
$fDesde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['desde'] ?? '')) ? $_GET['desde'] : '';
$fHasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['hasta'] ?? '')) ? $_GET['hasta'] : '';
$todas  = stream_notif_listar($pdo, $OWNER, 800);
if ($filtro !== '') { $todas = array_values(array_filter($todas, static fn($n) => (string) $n['tipo'] === $filtro)); }
if ($fDesde !== '') { $todas = array_values(array_filter($todas, static fn($n) => substr((string) $n['creado_en'], 0, 10) >= $fDesde)); }
if ($fHasta !== '') { $todas = array_values(array_filter($todas, static fn($n) => substr((string) $n['creado_en'], 0, 10) <= $fHasta)); }
$noLeidas = 0;
foreach ($todas as $n) { if ((int) $n['leido'] === 0) $noLeidas++; }

/** Estilo por tipo de evento. */
function ntf_estilo(string $t): array {
  switch ($t) {
    case 'recarga':    return ['💰', '#16a34a', 'Recarga de saldo'];
    case 'compra':     return ['🛒', '#2563eb', 'Compra del stock'];
    case 'venta':      return ['🏷', '#7c3aed', 'Venta'];
    case 'renovacion': return ['🔄', '#0891b2', 'Renovación'];
    case 'solicitud':  return ['✋', '#d97706', 'Solicitud'];
    case 'cuenta':     return ['🔧', '#db2777', 'Cambio en cuenta'];
    case 'credenciales': return ['🔑', '#dc2626', 'Cambio de credenciales'];
    case 'asignacion': return ['📦', '#059669', 'Asignación'];
    case 'ticket':     return ['🎫', '#0891b2', 'Soporte'];
    case 'recarga_juego': return ['🎮', '#7c3aed', 'Recarga de juego'];
    default:           return ['🔔', '#64748b', 'Aviso'];
  }
}
$tipos = [];
foreach (stream_notif_listar($pdo, $OWNER, 400) as $n) { $tipos[(string) $n['tipo']] = true; }

stream_head('Notificaciones', 'notificaciones');
?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px">
  <div>
    <h1 style="margin:0;font-size:20px">Notificaciones</h1>
    <p style="margin:4px 0 0;color:var(--muted);font-size:13px">Historial de todo lo que pasa: recargas, compras, ventas, renovaciones, solicitudes y cambios.</p>
  </div>
  <?php if ($noLeidas > 0): ?>
    <form method="post"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="marcar_todas">
      <button class="btn primary"><i data-lucide="check-check"></i> Marcar todas como leídas (<?= (int) $noLeidas ?>)</button></form>
  <?php endif; ?>
</div>

<?php if ($flash !== ''): ?>
  <div class="card" style="padding:10px 14px;margin:12px 0;border-left:3px solid <?= str_starts_with($flash, '⚠') ? 'var(--bad)' : 'var(--good)' ?>"><?= h($flash) ?></div>
<?php endif; ?>

<div style="display:flex;gap:6px;flex-wrap:wrap;margin:14px 0">
  <a href="notificaciones.php<?= $fDesde || $fHasta ? '?desde=' . h($fDesde) . '&hasta=' . h($fHasta) : '' ?>" class="btn <?= $filtro === '' ? 'primary' : 'ghost' ?>" style="padding:6px 12px;font-size:12.5px">Todas</a>
  <?php foreach (array_keys($tipos) as $t): $e = ntf_estilo($t); ?>
    <a href="notificaciones.php?t=<?= urlencode($t) ?><?= $fDesde ? '&desde=' . h($fDesde) : '' ?><?= $fHasta ? '&hasta=' . h($fHasta) : '' ?>" class="btn <?= $filtro === $t ? 'primary' : 'ghost' ?>" style="padding:6px 12px;font-size:12.5px"><?= $e[0] ?> <?= h($e[2]) ?></a>
  <?php endforeach; ?>
</div>
<!-- Filtro por rango de fechas (historial). Mantiene el filtro de tipo activo. -->
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0 0 14px">
  <?php if ($filtro !== ''): ?><input type="hidden" name="t" value="<?= h($filtro) ?>"><?php endif; ?>
  <div><label style="display:block;font-size:11px;color:var(--faint);font-weight:600;margin-bottom:3px">Desde</label><input type="date" name="desde" value="<?= h($fDesde) ?>" class="input" style="padding:6px 8px"></div>
  <div><label style="display:block;font-size:11px;color:var(--faint);font-weight:600;margin-bottom:3px">Hasta</label><input type="date" name="hasta" value="<?= h($fHasta) ?>" class="input" style="padding:6px 8px"></div>
  <button class="btn ghost" style="padding:7px 12px;font-size:12.5px"><i data-lucide="filter" style="width:14px;height:14px"></i> Filtrar</button>
  <?php if ($fDesde || $fHasta): ?><a href="notificaciones.php<?= $filtro !== '' ? '?t=' . urlencode($filtro) : '' ?>" class="btn ghost" style="padding:7px 12px;font-size:12.5px">Limpiar fechas</a><?php endif; ?>
</form>

<?php if (!$todas): ?>
  <div class="card" style="padding:34px;text-align:center;color:var(--muted)">
    <div style="font-size:34px;margin-bottom:8px">🔔</div>
    <b style="color:var(--text)">Aún no hay notificaciones</b>
    <p style="margin:6px 0 0;font-size:13px">Aquí irá apareciendo cada recarga, compra, venta, renovación o solicitud.</p>
  </div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <?php foreach ($todas as $n):
      $e = ntf_estilo((string) $n['tipo']);
      $leido = (int) $n['leido'] === 1;
      $url = trim((string) ($n['url'] ?? ''));
    ?>
      <div style="display:flex;gap:12px;padding:13px 16px;border-bottom:1px solid var(--border);<?= $leido ? '' : 'background:var(--surface-2)' ?>">
        <div style="flex:0 0 34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;background:<?= h($e[1]) ?>1a"><?= $e[0] ?></div>
        <div style="flex:1;min-width:0;overflow-wrap:anywhere">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <b style="font-size:13.5px"><?= h((string) $n['titulo']) ?></b>
            <?php if (!$leido): ?><span style="font-size:10px;font-weight:700;color:#fff;background:var(--accent);padding:1px 7px;border-radius:20px">NUEVO</span><?php endif; ?>
            <span style="font-size:11px;color:<?= h($e[1]) ?>;font-weight:600"><?= h($e[2]) ?></span>
          </div>
          <?php if (!empty($n['mensaje'])): ?>
            <div style="font-size:12.5px;color:var(--muted);margin-top:3px;white-space:pre-line"><?= h((string) $n['mensaje']) ?></div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--faint);margin-top:5px">
            <?= h(date('d/m/Y H:i', strtotime((string) $n['creado_en']))) ?>
            <?php if (!empty($n['actor_nombre'])): ?> · por <?= h((string) $n['actor_nombre']) ?><?php endif; ?>
          </div>
        </div>
        <div style="flex:0 0 auto;display:flex;gap:6px;align-items:flex-start">
          <?php if ($url !== ''): ?><a href="<?= h($url) ?>" class="btn ghost" style="padding:5px 10px;font-size:12px">Ver</a><?php endif; ?>
          <?php if (!$leido): ?>
            <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="marcar_una"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
              <button class="iconbtn" title="Marcar como leída"><i data-lucide="check"></i></button></form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php stream_foot();
