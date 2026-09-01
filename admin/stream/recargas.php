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
            j.nombre AS juego_nombre, j.sticker_imagen AS juego_img, j.sticker_icono AS juego_icono, j.imagen AS juego_imagen, j.sticker_color_fondo AS juego_color
        FROM juego_paquetes p JOIN juegos j ON j.id = p.juego_id
        WHERE p.activo = 1 AND j.activo = 1 AND p.precio_revendedor IS NOT NULL AND p.precio_revendedor > 0
        ORDER BY j.nombre, p.orden, p.id")->fetchAll(PDO::FETCH_ASSOC);
    // Guarda la imagen/color del juego junto al grupo (para el icono tipo app antes del título).
    // El iconito usa: sticker_imagen → sticker_icono → IMAGEN del juego (la principal, fácil de subir en
    // Juegos). Así el admin solo pone "Imagen del juego" y ya sale, sin buscar el campo del sticker.
    foreach ($rows as $r) {
        $jn = $r['juego_nombre'];
        if (!isset($juegos[$jn])) $juegos[$jn] = ['img' => $r['juego_img'] ?? '', 'icono' => $r['juego_icono'] ?? '', 'imgppal' => $r['juego_imagen'] ?? '', 'color' => $r['juego_color'] ?? '', 'paquetes' => []];
        $juegos[$jn]['paquetes'][] = $r;
    }
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
  <?php foreach ($juegos as $juegoNombre => $g):
    $paquetes = $g['paquetes'];
    // Imagen del juego (tipo app) para el círculo antes del título: sticker → icono → IMAGEN principal.
    $jImg = trim((string) ($g['img'] ?? '')) ?: trim((string) ($g['icono'] ?? '')) ?: trim((string) ($g['imgppal'] ?? ''));
    $jColor = trim((string) ($g['color'] ?? '')) ?: '#3f4fb5';
    $jIni = mb_strtoupper(mb_substr($juegoNombre, 0, 1)); ?>
    <div class="card" style="margin-bottom:14px;padding:0;overflow:hidden">
      <div class="card-hd" style="padding:12px 16px;display:flex;align-items:center;gap:10px">
        <?php if ($jImg !== ''): ?>
          <img src="<?= h($jImg) ?>" alt="" style="width:34px;height:34px;border-radius:50%;object-fit:cover;background:var(--surface-2);border:1px solid var(--border)">
        <?php else: ?>
          <span style="width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-weight:800;color:#fff;background:<?= h($jColor) ?>;flex:0 0 auto"><?= h($jIni) ?></span>
        <?php endif; ?>
        <h2 style="margin:0"><?= h($juegoNombre) ?></h2><span class="pill-count"><?= count($paquetes) ?></span>
      </div>
      <div style="padding:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
        <?php foreach ($paquetes as $p): $pImg = trim((string) ($p['imagen_icono'] ?? '')); ?>
          <div class="card" style="padding:12px;display:flex;flex-direction:column;gap:8px">
            <div style="display:flex;align-items:center;gap:8px">
              <?php if ($pImg !== ''): ?><img src="<?= h($pImg) ?>" alt="" style="width:26px;height:26px;border-radius:7px;object-fit:cover;background:var(--surface-2);flex:0 0 auto">
              <?php elseif ($jImg !== ''): ?><img src="<?= h($jImg) ?>" alt="" style="width:26px;height:26px;border-radius:7px;object-fit:cover;background:var(--surface-2);flex:0 0 auto"><?php endif; ?>
              <div style="font-weight:800"><?= h($p['nombre']) ?></div>
            </div>
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
    <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">ID del jugador <span style="color:var(--faint);font-weight:400">(las gift cards no lo necesitan)</span></label>
    <input id="rcPlayer" placeholder="ID del jugador / usuario (vacío en gift cards)" style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
    <label style="display:block;font-size:12px;color:var(--muted);font-weight:600;margin-bottom:5px">Zona / ID de servidor <span style="color:var(--faint);font-weight:400">(solo si el juego lo pide, ej. Mobile Legends)</span></label>
    <input id="rcZone" placeholder="Ej: 2001 (déjalo vacío si no aplica)" style="width:100%;margin-bottom:12px;padding:9px;border:1px solid var(--border);border-radius:9px;background:var(--surface);color:var(--text)">
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
function rcEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
// Muestra el CÓDIGO entregado por el proveedor (gift cards / PIN), si el motor lo devolvió, con botón de copiar.
function rcCodeHtml(d2){
  var code = d2 && (d2.provider_code || d2.codigo || d2.code || d2.pin);
  var ref  = d2 && (d2.provider_reference || d2.reference);
  var out = '';
  if (code){ out += '<div style="margin-top:8px;padding:10px 12px;border:1px dashed var(--accent);border-radius:9px;background:var(--accent-soft)"><div style="font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--faint);letter-spacing:.03em">Código de la recarga</div><div style="font-family:ui-monospace,monospace;font-weight:800;font-size:15px;color:var(--text);word-break:break-all;cursor:pointer;margin-top:2px" title="Clic para copiar" onclick="rcCopy('+JSON.stringify(String(code))+',this)">'+rcEsc(code)+'</div></div>'; }
  if (ref){ out += '<div style="font-size:11px;color:var(--faint);margin-top:4px">Ref: '+rcEsc(ref)+'</div>'; }
  return out;
}
function rcCopy(txt, el){
  function done(){ if(el){ var o=el.textContent; el.textContent='¡Copiado!'; setTimeout(function(){ el.textContent=o; }, 1100); } }
  try{ if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(txt).then(done,done); return; } }catch(e){}
  try{ var t=document.createElement('textarea'); t.value=txt; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); }catch(e){}
  done();
}
function rcComprar(p){
  rcSel = p;
  document.getElementById('rcTitle').textContent = 'Recargar ' + p.juego;
  document.getElementById('rcInfo').textContent = p.paq + ' · $' + p.precio.toFixed(2) + ' por unidad (se descuenta de tu saldo).';
  document.getElementById('rcPlayer').value = '';
  { const z = document.getElementById('rcZone'); if (z) z.value = ''; }
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
  const zone = (document.getElementById('rcZone')?.value || '').trim();
  const qty = Math.max(1, parseInt(document.getElementById('rcQty').value||'1'));
  const res = document.getElementById('rcResult');
  let verifiedName = '';   // nombre del jugador verificado (se conserva para mostrarlo al final)
  // Zona/servidor (opcional): se envía con todos los alias que usa la tienda por si el juego lo pide.
  let pfieldsJson = '';
  if (zone){ pfieldsJson = JSON.stringify({ zone_id: zone, input2: zone, server_id: zone, zone: zone }); }
  const btn = document.getElementById('rcBtn'); btn.disabled = true;
  try {
    // 0) Verificar el ID del jugador SOLO si se escribió uno (las gift cards no llevan ID → se salta).
    if (player){
      res.innerHTML = 'Verificando el ID del jugador…';
      const fdv = new FormData();
      fdv.append('game_id', rcSel.gid); fdv.append('package_id', rcSel.pid); fdv.append('user_identifier', player);
      if (zone){ fdv.append('zone_id', zone); fdv.append('input2', zone); fdv.append('server_id', zone); if (pfieldsJson) fdv.append('player_fields_json', pfieldsJson); }
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
      verifiedName = (dv && dv.player_name) ? String(dv.player_name) : '';
      res.innerHTML = verifiedName ? '<span style="color:var(--good)">✓ Jugador: '+verifiedName+'</span> · Procesando pago…' : 'Procesando pago…';
    } else {
      res.innerHTML = 'Procesando pago…';
    }
    // 1) Descontar saldo + crear el pedido pagado.
    const fd = new FormData();
    fd.append('game_id', rcSel.gid); fd.append('package_id', rcSel.pid);
    fd.append('user_identifier', player); fd.append('quantity', qty);
    if (pfieldsJson){ fd.append('player_fields_json', pfieldsJson); }
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
    const nameLine = verifiedName ? '<div style="font-size:12px;color:var(--good);margin-bottom:4px">✓ Jugador: '+rcEsc(verifiedName)+'</div>' : '';
    const codeHtml = rcCodeHtml(d2);
    if (d2.estado === 'enviado'){
      res.innerHTML = nameLine + '<span style="color:var(--good)">✓ Recarga enviada. Saldo: $'+(d1.saldo).toFixed(2)+'</span>' + codeHtml;
    } else if (d2.ok || d2.manual){
      res.innerHTML = nameLine + '<span style="color:var(--warn)">✓ Pago hecho. La recarga se procesa (estado: '+(d2.estado||'pagado')+'). Saldo: $'+(d1.saldo).toFixed(2)+'</span>' + codeHtml;
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
