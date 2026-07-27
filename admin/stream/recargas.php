<?php
/**
 * admin/stream/recargas.php — El REVENDEDOR compra recargas de juegos a precio de revendedor,
 * pagando con su saldo. Elige juego → paquete → ID del jugador → confirma. El frontend llama a
 * api/revendedor/recargar.php (descuenta saldo + crea el pedido pagado) y luego dispara el
 * fulfillment con el motor de la tienda. Solo contexto revendedor.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../../api/wallet/_helpers.php';
admin_require_login();

if (stream_ctx() !== 'revendedor') {
    header('Location: dashboard.php');
    exit;
}
$pdo = db();
$uid = (int) current_user_id();
$saldo = wallet_saldo($pdo, $uid);

// Juegos + paquetes CON precio de revendedor fijado (los que puede comprar).
$juegos = [];
try {
    $rows = $pdo->query("SELECT p.id, p.juego_id, p.nombre, p.cantidad, p.precio_revendedor, p.imagen_icono,
            j.nombre AS juego_nombre
        FROM juego_paquetes p JOIN juegos j ON j.id = p.juego_id
        WHERE p.activo = 1 AND j.activo = 1 AND p.precio_revendedor IS NOT NULL AND p.precio_revendedor > 0
        ORDER BY j.nombre, p.orden, p.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $juegos[$r['juego_nombre']][] = $r; }
} catch (Throwable $e) {}

$recargarUrl = function_exists('app_path') ? app_path('/api/revendedor/recargar.php') : '/api/revendedor/recargar.php';
$verifyUrl = function_exists('app_path') ? app_path('/api/verify_player.php') : '/api/verify_player.php';

stream_head('Recargas', 'recargas');
?>
<div class="pagehd">
  <div><h1>Recargas de <span class="nm">juegos</span></h1><p>Compra recargas a tu precio de revendedor, con tu saldo.</p></div>
  <div class="card" style="padding:10px 16px"><div style="font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase">Tu saldo</div>
    <div class="tnum" style="font-size:20px;font-weight:800;color:var(--accent)">$<?= number_format($saldo, 2) ?></div></div>
</div>

<?php if (!$juegos): ?>
  <div class="card" style="padding:40px;text-align:center;color:var(--muted)">
    <div style="font-size:38px">🎮</div>
    Aún no hay recargas con precio de revendedor. El administrador debe fijarlos en «Precios recargas».
  </div>
<?php else: ?>
  <?php foreach ($juegos as $juegoNombre => $paquetes): ?>
    <div class="card" style="margin-bottom:14px;padding:0;overflow:hidden">
      <div class="card-hd" style="padding:12px 16px"><i data-lucide="gamepad-2"></i><h2><?= h($juegoNombre) ?></h2><span class="pill-count"><?= count($paquetes) ?></span></div>
      <div style="padding:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
        <?php foreach ($paquetes as $p): ?>
          <div class="card" style="padding:12px;display:flex;flex-direction:column;gap:8px">
            <div style="font-weight:800"><?= h($p['nombre']) ?></div>
            <div class="muted" style="font-size:12px"><?= h($p['cantidad'] ?: '') ?></div>
            <div class="tnum" style="font-size:18px;font-weight:800">$<?= number_format((float) $p['precio_revendedor'], 2) ?></div>
            <button class="btn primary" style="padding:7px 10px"
              onclick='rcComprar(<?= json_encode(["gid"=>(int)$p["juego_id"],"pid"=>(int)$p["id"],"juego"=>$p["juego_nombre"],"paq"=>$p["nombre"],"precio"=>(float)$p["precio_revendedor"]], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
              <i data-lucide="zap"></i> Recargar</button>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<!-- Modal recarga -->
<div id="mRec" class="modal-bg" style="display:none;position:fixed;inset:0;background:#0008;align-items:flex-start;justify-content:center;padding:28px 14px;z-index:60;overflow:auto">
  <div class="card" style="max-width:440px;width:100%;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <h3 style="margin:0" id="rcTitle">Recargar</h3>
      <button onclick="rcCerrar()" class="iconbtn"><i data-lucide="x"></i></button>
    </div>
    <p class="muted" style="font-size:13px;margin:0 0 14px" id="rcInfo"></p>
    <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">ID del jugador</label>
    <input id="rcPlayer" placeholder="ID del jugador / usuario" style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
    <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Cantidad</label>
    <input id="rcQty" type="number" min="1" max="50" value="1" style="width:100%;margin-bottom:16px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
    <button id="rcBtn" class="btn primary" style="width:100%" onclick="rcConfirmar()"><i data-lucide="check"></i> Confirmar y pagar con saldo</button>
    <div id="rcResult" style="margin-top:12px;font-size:13px"></div>
  </div>
</div>

<script>
const RC_URL = <?= json_encode($recargarUrl) ?>;
const VERIFY_URL = <?= json_encode($verifyUrl) ?>;
let rcSel = null;
function rcComprar(p){
  rcSel = p;
  document.getElementById('rcTitle').textContent = 'Recargar ' + p.juego;
  document.getElementById('rcInfo').textContent = p.paq + ' · $' + p.precio.toFixed(2) + ' por unidad (se descuenta de tu saldo).';
  document.getElementById('rcPlayer').value = '';
  document.getElementById('rcQty').value = 1;
  document.getElementById('rcResult').innerHTML = '';
  document.getElementById('rcBtn').disabled = false;
  document.getElementById('mRec').style.display = 'flex';
  if (window.lucide) lucide.createIcons();
}
function rcCerrar(){ document.getElementById('mRec').style.display='none'; if(rcSel && rcSel._done) location.reload(); }
document.getElementById('mRec').addEventListener('click', e => { if (e.target.id==='mRec') rcCerrar(); });

async function rcConfirmar(){
  if (!rcSel) return;
  const player = document.getElementById('rcPlayer').value.trim();
  const qty = Math.max(1, parseInt(document.getElementById('rcQty').value||'1'));
  const res = document.getElementById('rcResult');
  if (!player){ res.innerHTML = '<span style="color:var(--bad)">Escribe el ID del jugador.</span>'; return; }
  const btn = document.getElementById('rcBtn'); btn.disabled = true;
  try {
    // 0) Verificar el ID del jugador (reusa el verificador de la tienda) antes de cobrar.
    res.innerHTML = 'Verificando el ID del jugador…';
    const fdv = new FormData();
    fdv.append('game_id', rcSel.gid); fdv.append('package_id', rcSel.pid); fdv.append('user_identifier', player);
    const rv = await fetch(VERIFY_URL, {method:'POST', body:fdv, headers:{'X-Requested-With':'XMLHttpRequest'}});
    let dv = {}; try { dv = await rv.json(); } catch(e){}
    // Bloquear SOLO si el verificador confirma que el ID es inválido/no existe.
    // (unsupported = el juego no tiene verificación automática · unavailable = servicio caído → dejar
    //  comprar igual, como hace la tienda pública. Antes bloqueaba TODO juego sin verificación.)
    const vstatus = (dv && dv.status ? String(dv.status) : '').toLowerCase();
    if (dv && dv.ok === false && (vstatus === 'not_found' || vstatus === 'invalid')){
      res.innerHTML = '<span style="color:var(--bad)">✗ '+(dv.message||'El ID del jugador no es válido. Revísalo.')+'</span>';
      btn.disabled = false; return;
    }
    if (dv && dv.player_name){
      res.innerHTML = '<span style="color:var(--good)">✓ Jugador: '+dv.player_name+'</span> · Procesando pago…';
    } else {
      res.innerHTML = 'Procesando pago…';
    }
    // 1) Descontar saldo + crear el pedido pagado.
    const fd = new FormData();
    fd.append('game_id', rcSel.gid); fd.append('package_id', rcSel.pid);
    fd.append('user_identifier', player); fd.append('quantity', qty);
    const r1 = await fetch(RC_URL, {method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
    const d1 = await r1.json();
    if (!d1.ok){ res.innerHTML = '<span style="color:var(--bad)">'+(d1.message||'No se pudo procesar.')+'</span>'; btn.disabled=false; return; }
    // 2) Disparar el fulfillment con el motor de la tienda.
    res.innerHTML = 'Pago hecho. Enviando la recarga…';
    const fd2 = new FormData();
    fd2.append('action','batch_fulfill_item'); fd2.append('order_id', d1.order_id); fd2.append('batch_id', d1.batch_id);
    const r2 = await fetch(d1.fulfill_url + '?action=batch_fulfill_item', {method:'POST', body:fd2, headers:{'X-Requested-With':'XMLHttpRequest'}});
    let d2 = {}; try { d2 = await r2.json(); } catch(e){}
    rcSel._done = true;
    if (d2.estado === 'enviado'){
      res.innerHTML = '<span style="color:var(--good)">✓ Recarga enviada. Saldo: $'+(d1.saldo).toFixed(2)+'</span>';
    } else if (d2.ok || d2.manual){
      res.innerHTML = '<span style="color:var(--warn)">✓ Pago hecho. La recarga se procesa (estado: '+(d2.estado||'pagado')+'). Saldo: $'+(d1.saldo).toFixed(2)+'</span>';
    } else {
      // La entrega falló de verdad → pedir REVERSO del saldo (no cobrar sin entregar).
      try {
        const fdr = new FormData(); fdr.append('action','reverso'); fdr.append('order_id', d1.order_id);
        const rr = await fetch(RC_URL, {method:'POST', body:fdr, headers:{'X-Requested-With':'XMLHttpRequest'}});
        let dr = {}; try { dr = await rr.json(); } catch(e){}
        if (dr && dr.ok){
          res.innerHTML = '<span style="color:var(--warn)">La recarga no se pudo entregar'+(d2.message?(': '+d2.message):'')+'. Se te devolvió el saldo (queda $'+(Number(dr.saldo)||0).toFixed(2)+').</span>';
        } else {
          res.innerHTML = '<span style="color:var(--warn)">Pago hecho, la recarga quedó pendiente. Saldo: $'+(d1.saldo).toFixed(2)+'. Avísale al admin.</span>';
        }
      } catch(e){
        res.innerHTML = '<span style="color:var(--warn)">La recarga quedó pendiente de envío. Avísale al admin.</span>';
      }
    }
  } catch(e){
    res.innerHTML = '<span style="color:var(--bad)">Error de red. Si te descontaron, el pedido quedó creado; avísale al admin.</span>';
  }
}
</script>
<?php stream_foot();
