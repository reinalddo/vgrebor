<?php
/**
 * admin/stream/comprar.php — El REVENDEDOR compra un perfil del STOCK DEL ADMIN (owner 0)
 * a precio de revendedor (precio_distribuidor), pagando con su SALDO. Portado de CONEC
 * (api/revendedor/comprar-streaming.php): claim atómico del perfil + débito condicionado del saldo.
 * Solo tiene sentido en el panel del revendedor; en el del admin redirige (el admin dueño no se
 * compra a sí mismo).
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../_streaming.php';
require __DIR__ . '/../../api/wa/_wa.php';
require __DIR__ . '/../../api/wallet/_helpers.php';
admin_require_login();

if (stream_ctx() !== 'revendedor') {
    header('Location: dashboard.php');
    exit;
}

$pdo = db();
$uid = (int) current_user_id();
const STREAM_ADMIN_OWNER = 0; // el stock se compra al dueño de la tienda

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $msg = '';
    if (($_POST['accion'] ?? '') === 'comprar') {
        $platId = (int) ($_POST['plataforma_id'] ?? 0);
        $cliId = ((int) ($_POST['cliente_id'] ?? 0)) ?: null;
        $precioVenta = ($_POST['precio_venta_cliente'] ?? '') !== '' ? round((float) $_POST['precio_venta_cliente'], 2) : null;

        // Plataforma del ADMIN (owner 0), activa y publicada; el precio lo manda el servidor.
        $pl = $pdo->prepare("SELECT id, nombre, precio_distribuidor, dias_default, COALESCE(modo_entrega,'perfil') AS modo
                             FROM streaming_plataformas WHERE id=? AND owner_id=" . STREAM_ADMIN_OWNER . " AND activo=1 AND en_tienda=1");
        $pl->execute([$platId]);
        $plat = $pl->fetch(PDO::FETCH_ASSOC);

        if (!$plat) {
            $msg = '⚠ Esa plataforma no está disponible.';
        } elseif ((float) ($plat['precio_distribuidor'] ?? 0) <= 0) {
            $msg = '⚠ Esa plataforma no tiene precio de revendedor configurado. Pídeselo al administrador.';
        } else {
            $precio = (float) $plat['precio_distribuidor'];
            $dias = (int) ($plat['dias_default'] ?: 30);

            // Cliente del revendedor (opcional) — debe pertenecerle (owner = uid).
            $cliNombre = null; $cliWa = null;
            if ($cliId) {
                $c = $pdo->prepare("SELECT nombre, wa FROM streaming_clientes WHERE id=? AND owner_id=?");
                $c->execute([$cliId, $uid]);
                $cc = $c->fetch(PDO::FETCH_ASSOC);
                if (!$cc) { $cliId = null; } else { $cliNombre = $cc['nombre']; $cliWa = $cc['wa']; }
            }

            $pdo->beginTransaction();
            try {
                // 1) Tomar (lock) un perfil libre de una cuenta ACTIVA del ADMIN para esa plataforma.
                $sel = $pdo->prepare("SELECT p.id AS pid, p.etiqueta, p.pin AS ppin, c.id AS cuenta_id, c.correo, c.clave
                                      FROM streaming_perfiles p
                                      JOIN streaming_cuentas c ON c.id = p.cuenta_id
                                      WHERE c.owner_id=" . STREAM_ADMIN_OWNER . " AND c.plataforma_id=? AND p.estado='libre' AND c.estado='activa'
                                      ORDER BY (c.vencimiento IS NULL), c.vencimiento ASC
                                      LIMIT 1 FOR UPDATE");
                $sel->execute([$platId]);
                $p = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$p) { throw new RuntimeException('sin_stock'); }

                // 2) Débito ATÓMICO del saldo del revendedor (solo si alcanza).
                if (!wallet_debitar($pdo, $uid, $precio, 'compra_streaming', 'Streaming mayorista · ' . $plat['nombre'] . ($cliNombre ? ' · ' . $cliNombre : ''))) {
                    throw new RuntimeException('saldo_insuficiente');
                }

                // 3) Crear la venta del REVENDEDOR (owner_id = uid). cuenta_id apunta a la cuenta del admin.
                $venc = date('Y-m-d', strtotime("+$dias days"));
                $ins = $pdo->prepare("INSERT INTO streaming_ventas
                    (owner_id, plataforma, tipo, cuenta_id, revendedor_id, cliente_id, cliente_nombre, cliente_wa, correo, clave, perfil, pin, precio, precio_venta_cliente, fecha_inicio, fecha_vencimiento, estado, entregada, creado_por)
                    VALUES (?,?, 'perfil', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activa', 1, ?)");
                $ins->execute([$uid, $plat['nombre'], $p['cuenta_id'], $uid, $cliId, $cliNombre, $cliWa, $p['correo'], $p['clave'], $p['etiqueta'], $p['ppin'], $precio, $precioVenta, date('Y-m-d'), $venc, $uid]);
                $vid = (int) $pdo->lastInsertId();

                // 4) Marcar el perfil vendido (ya bloqueado en el paso 1).
                $up = $pdo->prepare("UPDATE streaming_perfiles SET estado='vendido', venta_id=? WHERE id=? AND estado='libre'");
                $up->execute([$vid, $p['pid']]);
                if ($up->rowCount() < 1) { throw new RuntimeException('sin_stock'); }

                try {
                    $pdo->prepare("INSERT INTO streaming_venta_registro (venta_id, evento, descripcion, usuario_id) VALUES (?,?,?,?)")
                        ->execute([$vid, 'creada', 'Compra mayorista del stock de la tienda', $uid]);
                } catch (Throwable $e) {}

                $pdo->commit();
                $msg = '✓ ¡Compra realizada! ' . $plat['nombre'] . ' · Correo: ' . $p['correo'] . ' · Clave: ' . $p['clave']
                     . ' · Perfil: ' . ($p['etiqueta'] ?: '—') . ($p['ppin'] ? ' · PIN: ' . $p['ppin'] : '')
                     . ' · Vence: ' . $venc . '. Lo tienes en «Ventas».';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $m = $e->getMessage();
                $msg = $m === 'sin_stock' ? '⚠ No hay stock disponible de esa plataforma ahora mismo. Intenta más tarde.'
                     : ($m === 'saldo_insuficiente' ? '⚠ Saldo insuficiente. Recarga tu saldo para comprar.'
                     : '⚠ No se pudo completar la compra. Intenta de nuevo.');
            }
        }
        header('Location: comprar.php?msg=' . urlencode($msg));
        exit;
    }
}

$flash = (string) ($_GET['msg'] ?? '');
$saldo = wallet_saldo($pdo, $uid);

// Catálogo del STOCK DEL ADMIN con precio de revendedor y stock libre en vivo.
$stock = $pdo->query("SELECT pl.id, pl.nombre, pl.emoji, pl.color, pl.logo_url, pl.precio_distribuidor, pl.dias_default,
        (SELECT COUNT(*) FROM streaming_perfiles p JOIN streaming_cuentas c ON c.id=p.cuenta_id
          WHERE c.owner_id=" . STREAM_ADMIN_OWNER . " AND c.plataforma_id=pl.id AND p.estado='libre' AND c.estado='activa') AS libres
    FROM streaming_plataformas pl
    WHERE pl.owner_id=" . STREAM_ADMIN_OWNER . " AND pl.activo=1 AND pl.en_tienda=1 AND pl.precio_distribuidor>0
    ORDER BY pl.orden, pl.nombre")->fetchAll(PDO::FETCH_ASSOC);

$misClientes = st_clientes($pdo); // clientes del revendedor (owner = uid)

stream_head('Comprar del stock', 'comprar');
?>
<?php if ($flash): ?><div class="banner" style="margin-bottom:16px;white-space:normal"><i data-lucide="info"></i><?= h($flash) ?></div><?php endif; ?>

<div class="pagehd">
  <div>
    <h1>Comprar del <span class="nm">stock de la tienda</span></h1>
    <p>Compra un perfil disponible del inventario de la tienda a tu precio de revendedor. Se descuenta de tu saldo.</p>
  </div>
  <div style="display:flex;align-items:center;gap:10px">
    <div class="card" style="padding:10px 16px"><div style="font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase">Tu saldo</div>
      <div class="tnum" style="font-size:20px;font-weight:800;color:var(--accent)">$<?= number_format($saldo, 2) ?></div></div>
    <a href="saldo.php" class="btn primary"><i data-lucide="wallet"></i> Recargar saldo</a>
  </div>
</div>

<?php if (!$stock): ?>
  <div class="card" style="padding:40px;text-align:center;color:var(--muted)">
    <div style="font-size:38px">🛒</div>
    La tienda aún no tiene plataformas disponibles para revendedores (con precio de revendedor y stock).
  </div>
<?php else: ?>
  <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px">
    <?php foreach ($stock as $s): $sinStock = (int) $s['libres'] <= 0; ?>
      <div class="card" style="padding:16px;display:flex;flex-direction:column;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
          <span style="width:34px;height:34px;border-radius:9px;display:grid;place-items:center;font-weight:800;color:#fff;background:<?= h($s['color'] ?: '#3f4fb5') ?>"><?= h($s['emoji'] ?: mb_substr($s['nombre'], 0, 1)) ?></span>
          <div><div style="font-weight:800"><?= h($s['nombre']) ?></div>
            <div style="font-size:12px;color:var(--muted)"><?= (int) $s['dias_default'] ?> días · <span style="color:<?= $sinStock ? 'var(--bad)' : 'var(--good)' ?>;font-weight:700"><?= (int) $s['libres'] ?> disponible(s)</span></div></div>
        </div>
        <div style="display:flex;align-items:baseline;gap:6px">
          <span class="tnum" style="font-size:22px;font-weight:800">$<?= number_format((float) $s['precio_distribuidor'], 2) ?></span>
          <span style="font-size:12px;color:var(--faint)">/ perfil</span>
        </div>
        <?php if ($sinStock): ?>
          <button class="btn ghost" disabled style="opacity:.6;cursor:not-allowed">Sin stock</button>
        <?php else: ?>
          <button class="btn primary" onclick='abrirComprar(<?= json_encode(["id"=>(int)$s["id"],"nombre"=>$s["nombre"],"precio"=>(float)$s["precio_distribuidor"]], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i data-lucide="shopping-cart"></i> Comprar</button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Modal comprar -->
<div id="mComprar" class="modal-bg" style="display:none;position:fixed;inset:0;background:#0008;align-items:flex-start;justify-content:center;padding:28px 14px;z-index:60;overflow:auto">
  <div class="card" style="max-width:440px;width:100%;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="margin:0" id="mcTitle">Comprar</h3>
      <button onclick="cerrarComprar()" class="iconbtn"><i data-lucide="x"></i></button>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="accion" value="comprar">
      <input type="hidden" name="plataforma_id" id="mc-plat">
      <p style="color:var(--muted);font-size:13px;margin:0 0 12px" id="mcInfo"></p>
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Cliente (opcional)</label>
      <select name="cliente_id" style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
        <option value="">— Sin asignar —</option>
        <?php foreach ($misClientes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h($c['nombre']) ?></option><?php endforeach; ?>
      </select>
      <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">¿A cuánto se lo vendes a tu cliente? (opcional)</label>
      <input type="number" step="0.01" min="0" name="precio_venta_cliente" id="mc-pvc" oninput="mcCalcGan(this.value)" placeholder="Ej: 3.50" style="width:100%;margin-bottom:8px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
      <div id="mcGanancia" style="display:none;font-size:12.5px;font-weight:650;margin:0 0 14px"></div>
      <button class="btn primary" type="submit" style="width:100%"><i data-lucide="check"></i> Confirmar compra</button>
    </form>
  </div>
</div>

<script>
function abrirComprar(p){
  document.getElementById('mc-plat').value = p.id;
  document.getElementById('mcTitle').textContent = 'Comprar ' + p.nombre;
  document.getElementById('mcInfo').textContent = 'Se descontarán $' + p.precio.toFixed(2) + ' de tu saldo y recibirás los datos de acceso al instante.';
  window._mcCosto = Number(p.precio) || 0;
  var pvc = document.getElementById('mc-pvc'); if (pvc) pvc.value = '';
  var g = document.getElementById('mcGanancia'); if (g) g.style.display = 'none';
  document.getElementById('mComprar').style.display = 'flex';
  if (window.lucide) lucide.createIcons();
}
function mcCalcGan(v){
  var g = document.getElementById('mcGanancia'); if (!g) return;
  var venta = parseFloat(v), costo = window._mcCosto || 0;
  if (isNaN(venta) || v === '') { g.style.display = 'none'; return; }
  var gan = venta - costo;
  g.style.display = 'block';
  if (gan >= 0) { g.textContent = '💰 Ganancia: $' + gan.toFixed(2); g.style.color = 'var(--good)'; }
  else { g.textContent = '⚠ Pierdes $' + Math.abs(gan).toFixed(2) + ' (le cobras menos de lo que te cuesta)'; g.style.color = 'var(--bad)'; }
}
function cerrarComprar(){ document.getElementById('mComprar').style.display = 'none'; }
document.getElementById('mComprar').addEventListener('click', e => { if (e.target.id === 'mComprar') cerrarComprar(); });
</script>
<?php stream_foot();
