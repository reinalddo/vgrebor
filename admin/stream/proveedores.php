<?php
/**
 * Plataformas → Proveedores (estilo PAC). Control de contactos y DEUDA con proveedores.
 * Contacto admite WhatsApp o @Telegram. Ojito → cuentas compradas a ese proveedor + Total a Pagar.
 * Solo el DUEÑO (datos de costo). Mapea a streaming_proveedores + streaming_cuentas.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
admin_require_login();
if (!admin_es_admin()) { http_response_code(403); exit('Solo el dueño puede ver Proveedores (datos de costo).'); }
$pdo = db();
$OWNER = (int) stream_owner_id();

// ── Export CSV ──
if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="proveedores.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Nombre', 'Contacto', 'Metodo Pago', 'Cuentas', 'Deuda USD', 'Notas']);
  $rows = $pdo->query("SELECT pr.nombre, pr.contacto, pr.metodo_pago, pr.notas,
      (SELECT COUNT(*) FROM streaming_cuentas c WHERE c.proveedor_id=pr.id) cuentas,
      (SELECT COALESCE(SUM(c.costo),0) FROM streaming_cuentas c WHERE c.proveedor_id=pr.id AND COALESCE(c.costo_pagado,0)=0) deuda
      FROM streaming_proveedores pr WHERE pr.owner_id=$OWNER ORDER BY pr.nombre")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) fputcsv($out, [$r['nombre'], "\t" . ($r['contacto'] ?? ''), $r['metodo_pago'], $r['cuentas'], number_format((float) $r['deuda'], 2, '.', ''), $r['notas']]);
  fclose($out);
  exit;
}

// ── AJAX: cuentas + deuda de un proveedor ──
if (($_GET['ajax'] ?? '') === 'prov') {
  header('Content-Type: application/json; charset=utf-8');
  $id = (int) ($_GET['id'] ?? 0);
  $pr = $pdo->prepare("SELECT nombre, contacto, contacto_tipo, metodo_pago FROM streaming_proveedores WHERE id=? AND owner_id=?");
  $pr->execute([$id, $OWNER]); $pr = $pr->fetch(PDO::FETCH_ASSOC);
  $cu = $pdo->prepare("SELECT c.id, c.plataforma, c.correo, c.vencimiento, c.costo, COALESCE(c.costo_pagado,0) AS pagado,
                       (SELECT COUNT(*) FROM streaming_perfiles p WHERE p.cuenta_id=c.id) AS perf
                       FROM streaming_cuentas c WHERE c.proveedor_id=? AND c.owner_id=? ORDER BY c.vencimiento");
  $cu->execute([$id, $OWNER]); $cuentas = $cu->fetchAll(PDO::FETCH_ASSOC);
  $deuda = 0.0;
  foreach ($cuentas as $c) if (!$c['pagado'] && $c['costo'] !== null) $deuda += (float) $c['costo'];
  echo json_encode(['ok' => (bool) $pr, 'prov' => $pr, 'cuentas' => $cuentas, 'deuda' => $deuda]);
  exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['accion'] ?? '';
  // Marcar/desmarcar una cuenta como PAGADA al proveedor (AJAX, sin recargar).
  if ($a === 'costo_toggle') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = (int) ($_POST['cuenta_id'] ?? 0);
    $ok = false;
    try {
      $own = $pdo->query("SELECT owner_id FROM streaming_cuentas WHERE id=$cid")->fetchColumn();
      if ($own !== false && (int) $own === $OWNER) {
        $pdo->prepare("UPDATE streaming_cuentas SET costo_pagado = 1 - COALESCE(costo_pagado,0) WHERE id=? AND owner_id=?")->execute([$cid, $OWNER]);
        $ok = true;
      }
    } catch (Throwable $e) {}
    echo json_encode(['ok' => $ok]);
    exit;
  }
  $msg = '';
  try {
    if ($a === 'proveedor_save') {
      $pid = (int) ($_POST['id'] ?? 0);
      $nombre = trim((string) ($_POST['nombre'] ?? ''));
      if ($nombre === '') throw new Exception('El nombre es obligatorio.');
      $contacto = trim((string) ($_POST['contacto'] ?? '')) ?: null;
      $ctipo = in_array($_POST['contacto_tipo'] ?? 'whatsapp', ['whatsapp', 'telegram'], true) ? $_POST['contacto_tipo'] : 'whatsapp';
      $metodo = trim((string) ($_POST['metodo_pago'] ?? '')) ?: null;
      $notas = trim((string) ($_POST['notas'] ?? '')) ?: null;
      if ($pid) {
        $pdo->prepare("UPDATE streaming_proveedores SET nombre=?, contacto=?, notas=? WHERE id=? AND owner_id=?")->execute([$nombre, $contacto, $notas, $pid, $OWNER]);
      } else {
        $pdo->prepare("INSERT INTO streaming_proveedores (owner_id, nombre, contacto, notas, activo) VALUES (?,?,?,?,1)")->execute([$OWNER, $nombre, $contacto, $notas]);
        $pid = (int) $pdo->lastInsertId();
      }
      try { $pdo->prepare("UPDATE streaming_proveedores SET metodo_pago=?, contacto_tipo=? WHERE id=? AND owner_id=?")->execute([$metodo, $ctipo, $pid, $OWNER]); } catch (Throwable $e) { error_log('proveedor_save metodo_pago/contacto_tipo: ' . $e->getMessage()); }
      $msg = '✓ Proveedor guardado.';
    }
    elseif ($a === 'proveedor_del') {
      $pdo->prepare("DELETE FROM streaming_proveedores WHERE id=? AND owner_id=?")->execute([(int) $_POST['id'], $OWNER]);
      $msg = '✓ Proveedor eliminado.';
    }
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: proveedores.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

$provs = $pdo->query("SELECT pr.id, pr.nombre, pr.contacto, pr.contacto_tipo, pr.metodo_pago, pr.notas,
    (SELECT COUNT(*) FROM streaming_cuentas c WHERE c.proveedor_id=pr.id) AS cuentas,
    (SELECT COALESCE(SUM(c.costo),0) FROM streaming_cuentas c WHERE c.proveedor_id=pr.id AND COALESCE(c.costo_pagado,0)=0) AS deuda
    FROM streaming_proveedores pr WHERE pr.owner_id=$OWNER ORDER BY pr.nombre")->fetchAll(PDO::FETCH_ASSOC);
$deudaTotal = 0.0;
foreach ($provs as $p) $deudaTotal += (float) $p['deuda'];

stream_head('Proveedores', 'proveedores');
?>
<?php if ($flash): $flashMal = (mb_substr(trim((string) $flash), 0, 1) === '⚠'); ?><div class="banner" style="margin-bottom:16px<?= $flashMal ? ';background:var(--bad-soft);color:var(--bad);border-color:var(--bad)' : '' ?>"><i data-lucide="<?= $flashMal ? 'alert-triangle' : 'check-circle' ?>"></i><?= h($flash) ?></div><?php endif; ?>

<div class="pagehd">
  <div>
    <h1>Gestión de <span class="nm">Proveedores</span></h1>
    <p>Control de deudas y contactos con proveedores.</p>
  </div>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <button onclick="document.getElementById('m-deuda').classList.toggle('hidden')" class="btn ghost"><i data-lucide="receipt"></i> Reporte Deuda</button>
    <a href="?export=csv" class="btn ghost"><i data-lucide="download"></i> Exportar</a>
    <button onclick="abrirProv()" class="btn primary"><i data-lucide="user-plus"></i> Nuevo Proveedor</button>
  </div>
</div>

<div id="m-deuda" class="card hidden" style="margin-bottom:16px">
  <div class="card-bd" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
    <span style="font-weight:700;display:inline-flex;align-items:center;gap:8px"><i data-lucide="receipt" style="width:18px;height:18px;color:var(--accent)"></i> Deuda total con proveedores</span>
    <span class="tnum" style="font-size:22px;font-weight:800;color:var(--bad)">$<?= number_format($deudaTotal, 2) ?></span>
  </div>
</div>

<div class="card">
  <div class="card-hd">
    <i data-lucide="users"></i>
    <h2>Proveedores</h2>
    <span class="pill-count"><?= count($provs) ?></span>
    <div style="margin-left:auto">
      <input id="buscar" class="input" style="width:260px;max-width:55vw" placeholder="Buscar…">
    </div>
  </div>
  <div class="overflow-x-auto thin">
    <table class="dtable">
      <thead><tr>
        <th>Nombre</th><th>Contacto</th><th style="text-align:center">Cuentas</th><th>Método Pago</th><th style="text-align:center">Acciones</th>
      </tr></thead>
      <tbody id="tbody">
      <?php foreach ($provs as $p):
        $tg = ($p['contacto_tipo'] ?? '') === 'telegram';
        $json = h(json_encode($p, JSON_UNESCAPED_UNICODE)); ?>
        <tr data-b="<?= h(mb_strtolower($p['nombre'] . ' ' . ($p['contacto'] ?? ''))) ?>">
          <td style="font-weight:700;color:var(--accent)"><?= h($p['nombre']) ?></td>
          <td><?php if ($p['contacto']): ?><span style="display:inline-flex;align-items:center;gap:6px;color:<?= $tg ? 'var(--accent)' : 'var(--good)' ?>"><i data-lucide="<?= $tg ? 'send' : 'message-circle' ?>" style="width:15px;height:15px"></i><?= h($p['contacto']) ?></span><?php else: ?><span style="color:var(--faint)">—</span><?php endif; ?></td>
          <td style="text-align:center"><span class="tag" style="display:inline-flex;align-items:center;gap:5px"><i data-lucide="layers" style="width:12px;height:12px"></i><?= (int) $p['cuentas'] ?></span></td>
          <td style="color:var(--muted)"><?= h($p['metodo_pago'] ?: '—') ?></td>
          <td><div style="display:flex;align-items:center;justify-content:center;gap:6px">
            <button onclick="verProv(<?= (int) $p['id'] ?>)" class="btn ghost" style="padding:7px" title="Ver cuentas y deuda"><i data-lucide="eye"></i></button>
            <button onclick='abrirProv(<?= $json ?>)' class="btn ghost" style="padding:7px" title="Editar"><i data-lucide="pencil"></i></button>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$provs): ?><div class="empty">Sin proveedores. Crea el primero con "Nuevo Proveedor".</div><?php endif; ?>
  </div>
</div>

<!-- Modales -->
<div id="overlay" class="fixed inset-0 bg-black/50 z-40 hidden items-start justify-center overflow-y-auto p-4" onclick="if(event.target===this)cerrar()">
  <!-- Nuevo/Editar -->
  <div id="m-prov" class="hidden" style="background:var(--surface);border:1px solid var(--border);border-radius:14px;width:100%;max-width:440px;margin:32px 0;box-shadow:var(--shadow);overflow:hidden">
    <div class="card-hd" style="justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px"><i data-lucide="user-plus"></i><h2 id="p-title">Agregar Proveedor</h2></div>
      <button onclick="cerrar()" class="btn ghost" style="padding:7px"><i data-lucide="x"></i></button>
    </div>
    <form method="post" style="padding:16px"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="proveedor_save"><input type="hidden" name="id" id="p-id">
      <div class="field"><label>Nombre <span style="color:var(--bad)">*</span></label><input name="nombre" id="p-nombre" required class="input" placeholder="Ej: Netflix Store"></div>
      <div class="field"><label>Contacto</label>
        <div style="display:flex;gap:8px">
          <select name="contacto_tipo" id="p-ctipo" class="input" style="width:auto"><option value="whatsapp">WhatsApp</option><option value="telegram">Telegram</option></select>
          <input name="contacto" id="p-contacto" class="input" placeholder="+58 412… o @usuario">
        </div>
        <span style="font-size:11px;color:var(--faint)">Número de WhatsApp o @usuario de Telegram — cualquiera de los dos.</span></div>
      <div class="field"><label>Método de Pago</label>
        <input name="metodo_pago" id="p-metodo" list="metlist" class="input" placeholder="Transferencia Bancaria">
        <datalist id="metlist"><option value="Transferencia Bancaria"><option value="Binance"><option value="Zelle"><option value="Zinli"><option value="Pago Móvil"><option value="Efectivo"></datalist></div>
      <div class="field"><label>Observaciones</label><textarea name="notas" id="p-notas" rows="2" class="input"></textarea></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:4px"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary">Guardar</button></div>
    </form>
  </div>
  <!-- Ver cuentas + deuda -->
  <div id="m-ver" class="hidden" style="background:var(--surface);border:1px solid var(--border);border-radius:14px;width:100%;max-width:560px;margin:32px 0;box-shadow:var(--shadow);overflow:hidden">
    <div class="card-hd" style="justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px"><i data-lucide="list"></i><h2 id="v-nombre"></h2></div>
      <button onclick="cerrar()" class="btn ghost" style="padding:7px"><i data-lucide="x"></i></button>
    </div>
    <div style="padding:16px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
        <div class="kpi flag"><div class="k-top"><div class="k-ic"><i data-lucide="receipt"></i></div> Total a Pagar</div><h3 class="tnum" id="v-deuda">$0.00</h3></div>
        <div class="kpi acc"><div class="k-top"><div class="k-ic"><i data-lucide="layers"></i></div> Cuentas</div><h3 class="tnum" id="v-cuentas">0</h3></div>
      </div>
      <div id="v-lista" class="thin" style="max-height:288px;overflow-y:auto;display:flex;flex-direction:column;gap:6px"></div>
    </div>
    <div style="padding:0 16px 16px;display:flex;justify-content:flex-end"><button onclick="cerrar()" class="btn primary">Cerrar</button></div>
  </div>
</div>

<script>
  const ov=document.getElementById('overlay');
  const PROV_CSRF='<?= h(csrf_token()) ?>';
  const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function cerrar(){ ov.classList.add('hidden'); ov.classList.remove('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); }
  function abrir(id){ ov.classList.remove('hidden'); ov.classList.add('flex'); document.querySelectorAll('#overlay > div').forEach(m=>m.classList.add('hidden')); document.getElementById(id).classList.remove('hidden'); lucide.createIcons(); }
  function abrirProv(p){
    document.getElementById('p-title').textContent=p?'Editar Proveedor':'Agregar Proveedor';
    document.getElementById('p-id').value=p?p.id:''; document.getElementById('p-nombre').value=p?(p.nombre||''):'';
    document.getElementById('p-contacto').value=p?(p.contacto||''):''; document.getElementById('p-ctipo').value=p?(p.contacto_tipo||'whatsapp'):'whatsapp';
    document.getElementById('p-metodo').value=p?(p.metodo_pago||''):''; document.getElementById('p-notas').value=p?(p.notas||''):'';
    abrir('m-prov');
  }
  async function verProv(id){
    abrir('m-ver');
    document.getElementById('v-nombre').textContent='';
    document.getElementById('v-deuda').textContent='$0.00';
    document.getElementById('v-cuentas').textContent='0';
    document.getElementById('v-lista').innerHTML='<div class="empty">Cargando…</div>';
    const r=await fetch('?ajax=prov&id='+id).then(x=>x.json()); if(!r.ok){ document.getElementById('v-lista').innerHTML='<div class="empty" style="color:var(--bad)">No se pudo cargar.</div>'; return; }
    document.getElementById('v-nombre').textContent=r.prov.nombre;
    document.getElementById('v-deuda').textContent='$'+(r.deuda||0).toFixed(2);
    document.getElementById('v-cuentas').textContent=r.cuentas.length;
    const lista=document.getElementById('v-lista'); lista.innerHTML = r.cuentas.length? '' : '<div class="empty">Sin cuentas de este proveedor.</div>';
    r.cuentas.forEach(c=>{
      const venc=c.vencimiento?(''+c.vencimiento).slice(0,10).split('-').reverse().join('/'):'—';
      lista.insertAdjacentHTML('beforeend',
        `<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid var(--border);border-radius:9px;padding:9px 11px">
          <div><div style="font-weight:600;font-size:12.8px">${esc(c.plataforma)||''} <span style="font-size:11px;color:var(--faint)">(${c.perf} perf)</span></div><div style="font-size:11px;color:var(--faint)">${esc(c.correo)||'—'} · vence ${esc(venc)}</div></div>
          <div style="text-align:right"><div style="font-weight:700">$${(parseFloat(c.costo)||0).toFixed(2)}</div><button type="button" onclick="togglePago(${c.id}, ${id})" title="Clic para cambiar el estado de pago" style="cursor:pointer;background:none;border:0;padding:0;font-size:10.5px;font-weight:700;color:${c.pagado==1?'var(--good)':'var(--bad)'}">${c.pagado==1?'✓ Pagado':'Marcar pagado'}</button></div>
        </div>`);
    });
    lucide.createIcons();
  }
  async function togglePago(cuentaId, provId){
    try{
      const fd=new FormData(); fd.append('accion','costo_toggle'); fd.append('cuenta_id',cuentaId); fd.append('_csrf', PROV_CSRF);
      const r=await fetch('proveedores.php',{method:'POST',body:fd}).then(x=>x.json());
      if(r && r.ok) verProv(provId);   // refresca deuda + estados del modal
    }catch(e){}
  }
  document.getElementById('buscar')?.addEventListener('input',function(){ const q=this.value.toLowerCase().trim(); document.querySelectorAll('#tbody tr').forEach(tr=>tr.style.display=(!q||(tr.dataset.b||'').includes(q))?'':'none'); });
</script>
<?php stream_foot(); ?>
