<?php
/**
 * admin/stream/conec.php — Proveedor MAYORISTA CONEC (coneclatam.com).
 * El dueño (admin) ve su SALDO, busca el CATÁLOGO (con su precio), y despacha RECARGAS por la API
 * (idempotentes por merchant_ref). NO toca api/pedidos.php: usa el cliente includes/conec_recargas.php.
 * La API key vive en configuración (no en el código). Sandbox (rk_test_) no mueve dinero.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
require __DIR__ . '/../../includes/conec_recargas.php';
admin_require_login();

$pdo = db();
// Acceso: el DUEÑO (contexto admin) Y los revendedores pueden ver el catálogo/saldo y recargar.
// Pero la CONFIGURACIÓN del proveedor (la API key) es SOLO del dueño → se gatea por CONTEXTO
// (el revendedor nunca está en contexto admin), no por admin_es_admin() que no distingue el contexto.
$esAdmin = !(function_exists('stream_ctx') && stream_ctx() === 'revendedor');

/** Tabla de órdenes CONEC (historial + idempotencia por merchant_ref estable). */
function conec_ensure_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS conec_ordenes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            merchant_ref VARCHAR(80) NOT NULL UNIQUE,
            usuario_id INT NULL,
            product_id VARCHAR(120) NULL,
            product_name VARCHAR(190) NULL,
            variant_id VARCHAR(60) NULL,
            variant_name VARCHAR(190) NULL,
            game_id VARCHAR(150) NULL,
            extra_json TEXT NULL,
            quantity INT NOT NULL DEFAULT 1,
            precio DECIMAL(12,4) NULL,
            estado VARCHAR(24) NOT NULL DEFAULT 'enviando',
            clase VARCHAR(20) NULL,
            codigos TEXT NULL,
            nick VARCHAR(190) NULL,
            error VARCHAR(255) NULL,
            modo VARCHAR(16) NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}
conec_ensure_schema($pdo);

/** Catálogo cacheado en un archivo temporal (son ~700 juegos / 1.5MB → no pegarle a la API en cada carga). */
function conec_catalog_cached(PDO $pdo, int $maxAge = 600, bool $force = false): array {
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/conec_catalog_' . md5(conec_config($pdo)['api_key'] . '|' . conec_config($pdo)['base']) . '.json';
    if (!$force && is_file($file) && (time() - filemtime($file)) < $maxAge) {
        $raw = @file_get_contents($file);
        $d = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($d) && !empty($d['games'])) { $d['cached'] = true; return $d; }
    }
    $cat = conec_catalog($pdo);
    if (!empty($cat['ok'])) { @file_put_contents($file, json_encode($cat)); }
    $cat['cached'] = false;
    return $cat;
}

// ───────────────────────────── AJAX ─────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $a = (string) $_GET['ajax'];

    if ($a === 'balance') { echo json_encode(conec_balance($pdo)); exit; }

    if ($a === 'catalog') {
        $cat = conec_catalog_cached($pdo, 600, isset($_GET['refresh']));
        if (empty($cat['ok'])) { echo json_encode(['ok' => false, 'error' => $cat['error'] ?? 'No se pudo leer el catálogo.']); exit; }
        // Índice LIGERO (nombre + id + requiere_id + nº variantes) para buscar sin cargar 1.5MB.
        $idx = [];
        foreach ($cat['games'] as $g) {
            $idx[] = [
                'product_id' => (string) ($g['product_id'] ?? ''),
                'name'       => (string) ($g['name'] ?? ''),
                'category'   => (string) ($g['category'] ?? ''),
                'requires_game_id' => !empty($g['requires_game_id']),
                'nv'         => is_array($g['variants'] ?? null) ? count($g['variants']) : 0,
            ];
        }
        echo json_encode(['ok' => true, 'games' => $idx, 'cached' => !empty($cat['cached']), 'mode' => $cat['mode'] ?? '']); exit;
    }

    if ($a === 'game') {
        $pid = (string) ($_GET['product_id'] ?? '');
        $cat = conec_catalog_cached($pdo, 600);
        foreach (($cat['games'] ?? []) as $g) {
            if ((string) ($g['product_id'] ?? '') === $pid) { echo json_encode(['ok' => true, 'game' => $g]); exit; }
        }
        echo json_encode(['ok' => false, 'error' => 'Producto no encontrado.']); exit;
    }

    if ($a === 'recharge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // SEGURIDAD: la recarga gasta el saldo mayorista del DUEÑO → por ahora SOLO el dueño puede recargar
        // (el revendedor ve el catálogo/precios pero no gasta tu saldo). El cobro al revendedor es un paso aparte.
        if (!$esAdmin) { echo json_encode(['ok' => false, 'error' => 'Por ahora solo el dueño puede recargar por CONEC.']); exit; }
        if (function_exists('csrf_check')) { try { csrf_check(); } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => 'Sesión expirada, recarga la página.']); exit; } }
        $variant = trim((string) ($_POST['variant_id'] ?? ''));
        $gameId  = trim((string) ($_POST['game_id'] ?? ''));
        $pname   = trim((string) ($_POST['product_name'] ?? ''));
        $pid     = trim((string) ($_POST['product_id'] ?? ''));
        $vname   = trim((string) ($_POST['variant_name'] ?? ''));
        $qty     = max(1, min(10, (int) ($_POST['quantity'] ?? 1)));
        $price   = ($_POST['price'] ?? '') !== '' ? (float) $_POST['price'] : null;
        $extra   = [];
        foreach (($_POST['field'] ?? []) as $k => $v) { $k = trim((string) $k); $v = trim((string) $v); if ($k !== '' && $v !== '') { $extra[$k] = $v; } }
        if ($variant === '') { echo json_encode(['ok' => false, 'error' => 'Falta el producto (variant_id).']); exit; }

        // 1) Crea la orden LOCAL primero → merchant_ref ESTABLE = idempotencia real en reintentos.
        conec_ensure_schema($pdo);
        $uid = (int) (function_exists('current_user_id') ? current_user_id() : 0);
        $ins = $pdo->prepare("INSERT INTO conec_ordenes (merchant_ref, usuario_id, product_id, product_name, variant_id, variant_name, game_id, extra_json, quantity, precio, estado, modo)
            VALUES ('', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'enviando', ?)");
        $ins->execute([$uid, $pid, $pname, $variant, $vname, $gameId, $extra ? json_encode($extra) : null, $qty, $price, conec_config($pdo)['sandbox'] ? 'sandbox' : 'real']);
        $oid = (int) $pdo->lastInsertId();
        $ref = conec_merchant_ref($oid);
        $pdo->prepare("UPDATE conec_ordenes SET merchant_ref=? WHERE id=?")->execute([$ref, $oid]);

        // 2) Despacha por CONEC (idempotente).
        $r = conec_recharge($pdo, $variant, $gameId, $ref, $qty, $extra);
        $norm = $r['norm'];
        $pdo->prepare("UPDATE conec_ordenes SET estado=?, clase=?, codigos=?, nick=?, error=? WHERE id=?")->execute([
            $norm['clase'] === 'entregado' ? 'entregado' : ($norm['clase'] === 'en_curso' ? 'en_curso' : 'fallido'),
            $norm['clase'], $norm['codigos'] ? implode(' | ', $norm['codigos']) : null,
            $norm['confirmacion'] ?: null, $r['error'] ?: null, $oid,
        ]);
        echo json_encode([
            'ok'      => $r['ok'],
            'clase'   => $r['clase'],
            'codigos' => $norm['codigos'],
            'nick'    => $norm['confirmacion'],
            'duplicado' => $norm['duplicado'],
            'error'   => $r['error'],
            'merchant_ref' => $ref,
            'order_id' => $oid,
        ]); exit;
    }

    if ($a === 'order_status') {
        $ref = trim((string) ($_GET['merchant_ref'] ?? ''));
        if ($ref === '') { echo json_encode(['ok' => false, 'error' => 'falta ref']); exit; }
        $st = conec_order_status($pdo, $ref);
        if (!empty($st['ok'])) {
            $n = $st['norm'];
            $pdo->prepare("UPDATE conec_ordenes SET estado=?, clase=?, codigos=COALESCE(?,codigos), nick=COALESCE(?,nick) WHERE merchant_ref=?")->execute([
                $n['clase'] === 'entregado' ? 'entregado' : ($n['clase'] === 'en_curso' ? 'en_curso' : ($n['clase'] === 'fallido' ? 'fallido' : 'en_curso')),
                $n['clase'], $n['codigos'] ? implode(' | ', $n['codigos']) : null, $n['confirmacion'] ?: null, $ref,
            ]);
            echo json_encode(['ok' => true, 'clase' => $n['clase'], 'codigos' => $n['codigos'], 'nick' => $n['confirmacion']]); exit;
        }
        echo json_encode(['ok' => false, 'error' => $st['error']]); exit;
    }

    echo json_encode(['ok' => false, 'error' => 'acción desconocida']); exit;
}

// ───────────────────────── Guardar configuración ─────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_config') {
    if (!$esAdmin) { header('Location: conec.php'); exit; }   // solo el dueño configura la llave
    if (function_exists('csrf_check')) { try { csrf_check(); } catch (Throwable $e) { header('Location: conec.php?err=csrf'); exit; } }
    $set = function (string $clave, string $valor) use ($pdo) {
        $pdo->prepare("INSERT INTO configuracion_general (clave, valor) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valor=VALUES(valor)")->execute([$clave, $valor]);
    };
    // La llave solo se sobreescribe si escribieron una nueva (para no borrarla al guardar otros campos).
    $newKey = trim((string) ($_POST['conec_api_key'] ?? ''));
    if ($newKey !== '' && strpos($newKey, '•') === false) { $set('conec_api_key', $newKey); }
    $set('conec_base_url', trim((string) ($_POST['conec_base_url'] ?? '')) ?: CONEC_BASE_DEFAULT);
    $set('conec_enabled', ($_POST['conec_enabled'] ?? '') === '1' ? '1' : '0');
    header('Location: conec.php?ok=1'); exit;
}

$cfg = conec_config($pdo);
$keyMask = $cfg['api_key'] !== '' ? (substr($cfg['api_key'], 0, 8) . str_repeat('•', 10) . substr($cfg['api_key'], -4)) : '';
$csrf = function_exists('csrf_token') ? csrf_token() : '';
$ordenes = [];
try { $ordenes = $pdo->query("SELECT * FROM conec_ordenes ORDER BY id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

stream_head('Recargas CONEC', 'conec');
?>
<div class="pagehd">
  <div><h1>Recargas <span class="nm">CONEC</span></h1><p>Proveedor mayorista (coneclatam). Saldo, catálogo y recargas por su API.</p></div>
  <div class="card" style="padding:10px 16px;min-width:180px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
      <div style="font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase">Saldo CONEC</div>
      <button onclick="cnBalance()" class="iconbtn" title="Actualizar"><i data-lucide="refresh-cw" style="width:14px;height:14px"></i></button>
    </div>
    <div class="tnum" id="cnBal" style="font-size:20px;font-weight:800;color:var(--accent)">—</div>
    <div id="cnBalSub" style="font-size:11px;color:var(--faint)"></div>
  </div>
</div>

<?php if (!$cfg['enabled'] || $cfg['api_key'] === ''): ?>
  <div class="card" style="padding:14px 16px;margin-bottom:14px;border-left:3px solid var(--warn)">
    <b>Falta configurar el proveedor.</b> Pega tu API key de CONEC abajo y activa el proveedor.
  </div>
<?php elseif ($cfg['sandbox']): ?>
  <div class="card" style="padding:10px 16px;margin-bottom:14px;border-left:3px solid var(--warn)">
    🧪 <b>Modo PRUEBAS (sandbox)</b> — las recargas NO mueven dinero ni entregan de verdad. Pon la llave real para producción.
  </div>
<?php endif; ?>

<!-- Buscador + catálogo -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:14px">
  <div class="card-hd" style="padding:12px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <div style="font-weight:800">Catálogo</div>
    <input id="cnSearch" placeholder="Buscar juego…" oninput="cnFiltrar()" class="input" style="flex:1;min-width:160px">
    <button onclick="cnCargarCatalogo(true)" class="btn" title="Refrescar catálogo"><i data-lucide="refresh-cw"></i> Refrescar</button>
  </div>
  <div id="cnList" style="padding:12px">Cargando catálogo…</div>
</div>

<?php if ($esAdmin): ?>
<!-- Historial (solo el dueño: muestra códigos entregados) -->
<div class="card" style="padding:0;overflow:hidden">
  <div class="card-hd" style="padding:12px 16px;font-weight:800">Últimas recargas CONEC</div>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:640px">
      <thead><tr style="text-align:left;color:var(--faint)">
        <th style="padding:8px 12px">Fecha</th><th style="padding:8px 12px">Producto</th><th style="padding:8px 12px">ID</th>
        <th style="padding:8px 12px">Estado</th><th style="padding:8px 12px">Código / Nick</th><th style="padding:8px 12px">Ref</th>
      </tr></thead>
      <tbody id="cnHist">
      <?php foreach ($ordenes as $o):
        $badge = $o['clase'] === 'entregado' ? 'good' : ($o['clase'] === 'en_curso' ? 'warn' : 'bad'); ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:8px 12px;white-space:nowrap"><?= h(date('d/m H:i', strtotime((string) $o['creado_en']))) ?></td>
          <td style="padding:8px 12px"><?= h($o['product_name'] ?: $o['product_id']) ?><div style="color:var(--faint);font-size:11px"><?= h($o['variant_name'] ?: $o['variant_id']) ?></div></td>
          <td style="padding:8px 12px"><?= h($o['game_id'] ?: '—') ?></td>
          <td style="padding:8px 12px"><span class="pill" style="background:var(--<?= $badge ?>-soft);color:var(--<?= $badge ?>)"><?= h($o['estado']) ?></span></td>
          <td style="padding:8px 12px;font-family:ui-monospace,monospace"><?= h($o['codigos'] ?: ($o['nick'] ?: ($o['error'] ?: '—'))) ?></td>
          <td style="padding:8px 12px;color:var(--faint);font-family:ui-monospace,monospace"><?= h($o['merchant_ref']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ordenes): ?><tr><td colspan="6" style="padding:18px;text-align:center;color:var(--faint)">Aún no hay recargas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Configuración (solo el dueño) -->
<div class="card" style="padding:16px;margin-top:14px">
  <div style="font-weight:800;margin-bottom:10px">Configuración del proveedor</div>
  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="accion" value="guardar_config">
    <div>
      <label class="flbl">API key <span style="color:var(--faint);font-weight:400">(rk_test_… pruebas · rk_… real)</span></label>
      <input name="conec_api_key" class="input" autocomplete="off" placeholder="<?= $keyMask !== '' ? h($keyMask) . ' (déjalo así para no cambiarla)' : 'Pega tu API key' ?>">
    </div>
    <div>
      <label class="flbl">URL base</label>
      <input name="conec_base_url" class="input" value="<?= h($cfg['base']) ?>">
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <label class="flex items-center gap-2" style="font-weight:600"><input type="checkbox" name="conec_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?> class="accent-teal-500"> Proveedor activo</label>
    </div>
    <div style="display:flex;align-items:end"><button class="btn primary"><i data-lucide="save"></i> Guardar</button></div>
  </form>
  <p class="muted" style="font-size:12px;margin:10px 0 0">La llave se guarda en el servidor (config), nunca en el código. Estado actual: <b><?= $cfg['api_key'] === '' ? 'sin llave' : ($cfg['sandbox'] ? 'PRUEBAS (sandbox)' : 'REAL') ?></b> · <?= $cfg['enabled'] ? 'activo' : 'inactivo' ?>.</p>
</div>
<?php endif; ?>

<!-- Modal recarga -->
<div id="cnMod" class="modal-bg" style="display:none;position:fixed;inset:0;background:#0008;align-items:flex-start;justify-content:center;padding:28px 14px;z-index:60;overflow:auto">
  <div class="card" style="max-width:460px;width:100%;padding:22px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <h3 style="margin:0" id="cnTitle">Recargar</h3>
      <button onclick="cnCerrar()" class="iconbtn"><i data-lucide="x"></i></button>
    </div>
    <p class="muted" style="font-size:13px;margin:0 0 14px" id="cnInfo"></p>
    <div id="cnFields"></div>
    <label class="flbl">Cantidad</label>
    <input id="cnQty" type="number" min="1" max="10" value="1" class="input" style="margin-bottom:16px">
    <button id="cnBtn" class="btn primary" style="width:100%" onclick="cnConfirmar()"><i data-lucide="zap"></i> Recargar por CONEC</button>
    <div id="cnResult" style="margin-top:12px;font-size:13px"></div>
  </div>
</div>

<script>
const CN_CSRF = <?= json_encode($csrf) ?>;
const CN_IS_OWNER = <?= $esAdmin ? 'true' : 'false' ?>;   // el revendedor VE el catálogo/precios pero NO recarga (por ahora)
let CN_GAMES = [];      // índice ligero
let cnSel = null;       // {product_id, variant, ...}
function cnEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function cnBalance(){
  const el=document.getElementById('cnBal'), sub=document.getElementById('cnBalSub'); el.textContent='…';
  try{ const r=await fetch('?ajax=balance').then(x=>x.json());
    if(r.ok){ el.textContent='$'+Number(r.balance).toFixed(2); sub.textContent=(r.reseller||'')+(r.mode?(' · '+r.mode):''); }
    else { el.textContent='—'; sub.innerHTML='<span style="color:var(--bad)">'+cnEsc(r.error||'error')+'</span>'; }
  }catch(e){ el.textContent='—'; sub.textContent='sin conexión'; }
}

async function cnCargarCatalogo(refresh){
  const box=document.getElementById('cnList'); box.textContent='Cargando catálogo…';
  try{ const r=await fetch('?ajax=catalog'+(refresh?'&refresh=1':'')).then(x=>x.json());
    if(!r.ok){ box.innerHTML='<span style="color:var(--bad)">'+cnEsc(r.error||'No se pudo cargar el catálogo')+'</span>'; return; }
    CN_GAMES=r.games||[]; cnFiltrar();
  }catch(e){ box.innerHTML='<span style="color:var(--bad)">Error de red al cargar el catálogo.</span>'; }
}

function cnFiltrar(){
  const q=(document.getElementById('cnSearch').value||'').toLowerCase().trim();
  const box=document.getElementById('cnList');
  let games=CN_GAMES;
  if(q){ games=games.filter(g=>(g.name||'').toLowerCase().includes(q)||(g.product_id||'').toLowerCase().includes(q)); }
  const total=games.length; games=games.slice(0,120);   // no pintar 700 de golpe
  if(!total){ box.innerHTML='<div style="color:var(--faint);padding:12px">Sin resultados.</div>'; return; }
  box.innerHTML='<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2">'+games.map(g=>
    '<button class="card" style="padding:10px;text-align:left;cursor:pointer;display:flex;flex-direction:column;gap:3px" onclick="cnAbrirJuego('+JSON.stringify(g.product_id)+')">'
    +'<div style="font-weight:700;font-size:13px">'+cnEsc(g.name)+'</div>'
    +'<div style="font-size:11px;color:var(--faint)">'+cnEsc(g.category||'')+' · '+g.nv+' paq.'+(g.requires_game_id?' · pide ID':'')+'</div>'
    +'</button>').join('')+'</div>'
    +(total>120?'<div style="padding:10px;color:var(--faint);font-size:12px">Mostrando 120 de '+total+'. Afina la búsqueda.</div>':'');
  if(window.lucide) lucide.createIcons();
}

async function cnAbrirJuego(pid){
  const box=document.getElementById('cnList'); box.innerHTML='Cargando paquetes…';
  try{ const r=await fetch('?ajax=game&product_id='+encodeURIComponent(pid)).then(x=>x.json());
    if(!r.ok){ box.innerHTML='<span style="color:var(--bad)">'+cnEsc(r.error||'error')+'</span>'; return; }
    const g=r.game; const vs=g.variants||[];
    box.innerHTML='<button class="btn" onclick="cnFiltrar()" style="margin-bottom:10px"><i data-lucide="arrow-left"></i> Volver</button>'
      +'<div style="font-weight:800;margin-bottom:8px">'+cnEsc(g.name)+(g.requires_game_id?' <span class="pill" style="background:var(--accent-soft);color:var(--accent)">pide ID de jugador</span>':'')+'</div>'
      +(CN_IS_OWNER?'':'<div class="muted" style="font-size:12px;margin-bottom:8px">Aquí ves el catálogo y los precios. Por ahora solo el dueño puede recargar por CONEC.</div>')
      +'<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2">'+vs.map(function(v){
        var inner='<div style="font-weight:700;font-size:13px">'+cnEsc(v.name)+'</div>'
          +'<div class="tnum" style="font-weight:800;color:var(--accent);margin-top:3px">$'+Number(v.price).toFixed(4)+'</div>';
        if(!CN_IS_OWNER){ return '<div class="card" style="padding:10px">'+inner+'</div>'; }
        var meta=JSON.stringify(JSON.stringify({pid:g.product_id,pname:g.name,rid:!!g.requires_game_id,vid:v.variant_id,vname:v.name,price:v.price,fields:v.fields||[]}));
        return '<button class="card" style="padding:10px;text-align:left;cursor:pointer" onclick=\'cnRecargar('+meta+')\'>'+inner+'</button>';
      }).join('')+'</div>';
    if(window.lucide) lucide.createIcons();
  }catch(e){ box.innerHTML='<span style="color:var(--bad)">Error de red.</span>'; }
}

function cnRecargar(json){
  cnSel=JSON.parse(json);
  document.getElementById('cnTitle').textContent='Recargar '+cnSel.pname;
  document.getElementById('cnInfo').textContent=cnSel.vname+' · costo $'+Number(cnSel.price).toFixed(4)+' (se descuenta de tu saldo CONEC).';
  let html='';
  if(cnSel.rid){ html+='<label class="flbl">ID del jugador</label><input id="cnGid" class="input" style="margin-bottom:12px" placeholder="ID del jugador">'; }
  (cnSel.fields||[]).forEach(f=>{ html+='<label class="flbl">'+cnEsc(f.label||f.key)+(f.required?' *':'')+'</label><input class="input cn-field" data-key="'+cnEsc(f.key)+'" style="margin-bottom:12px" placeholder="'+cnEsc(f.label||f.key)+'">'; });
  document.getElementById('cnFields').innerHTML=html;
  document.getElementById('cnQty').value=1;
  document.getElementById('cnResult').innerHTML='';
  document.getElementById('cnBtn').disabled=false;
  document.getElementById('cnMod').style.display='flex';
  if(window.lucide) lucide.createIcons();
}
function cnCerrar(){ document.getElementById('cnMod').style.display='none'; if(cnSel&&cnSel._done) location.reload(); }
document.getElementById('cnMod').addEventListener('click',e=>{ if(e.target.id==='cnMod') cnCerrar(); });

async function cnConfirmar(){
  if(!cnSel) return;
  const res=document.getElementById('cnResult'); const btn=document.getElementById('cnBtn'); btn.disabled=true;
  const gid=(document.getElementById('cnGid')?.value||'').trim();
  if(cnSel.rid && !gid){ res.innerHTML='<span style="color:var(--bad)">Este producto pide ID del jugador.</span>'; btn.disabled=false; return; }
  const fd=new FormData();
  fd.append('_csrf',CN_CSRF); fd.append('variant_id',cnSel.vid); fd.append('product_id',cnSel.pid);
  fd.append('product_name',cnSel.pname); fd.append('variant_name',cnSel.vname); fd.append('price',cnSel.price);
  fd.append('game_id',gid); fd.append('quantity',Math.max(1,parseInt(document.getElementById('cnQty').value||'1')));
  let faltan=false;
  document.querySelectorAll('#cnFields .cn-field').forEach(i=>{ const k=i.dataset.key,v=i.value.trim(); if(v)fd.append('field['+k+']',v); });
  res.innerHTML='Enviando la recarga…';
  try{
    const r=await fetch('?ajax=recharge',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}}).then(x=>x.json());
    if(r.clase==='entregado'){
      let out='<div style="color:var(--good);font-weight:700">✅ Entregada'+(r.duplicado?' (ya existía, no se cobró dos veces)':'')+'.</div>';
      if(r.nick) out+='<div style="margin-top:4px">Jugador: <b>'+cnEsc(r.nick)+'</b></div>';
      if(r.codigos&&r.codigos.length) out+='<div style="margin-top:8px;padding:10px 12px;border:1px dashed var(--accent);border-radius:9px;background:var(--accent-soft)"><div style="font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--faint)">Código</div><div style="font-family:ui-monospace,monospace;font-weight:800;word-break:break-all">'+r.codigos.map(cnEsc).join('<br>')+'</div></div>';
      res.innerHTML=out; cnSel._done=true; cnBalance();
    } else if(r.clase==='en_curso'){
      res.innerHTML='<div style="color:var(--warn);font-weight:700">⏳ En proceso con el proveedor.</div><div style="font-size:12px;color:var(--faint)">Se confirmará sola. Ref: '+cnEsc(r.merchant_ref||'')+'</div>'; cnSel._done=true;
    } else {
      res.innerHTML='<div style="color:var(--bad);font-weight:700">❌ '+cnEsc(r.error||'No se pudo recargar')+'</div>'; btn.disabled=false;
    }
  }catch(e){ res.innerHTML='<span style="color:var(--bad)">Error de red.</span>'; btn.disabled=false; }
}

cnBalance(); cnCargarCatalogo(false);
</script>
<?php stream_foot();
