<?php
/**
 * Plataformas → Tipos de Plataforma (estilo PAC). Catálogo de servicios: logo, categoría,
 * etiqueta comercial, costo / precio distribuidor / precio público (con % ganancia) y condiciones.
 * Mapea a streaming_plataformas. Costos/precio distribuidor: solo dueño ($verCostos).
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
admin_require_login();
if (!admin_es_admin() && !in_array(admin_wa_area(), ['streaming', 'ambas'], true)) {
  http_response_code(403);
  exit('Esta sección es del área de Streaming.');
}
$verCostos = admin_es_admin();
$pdo = db();
$OWNER = (int) stream_owner_id();
// Misma tasa que usan las recargas/checkout: la clave 'tasa_bcv' de la tabla `configuracion`
// (la que el dueño gestiona), no el fallback fijo. Así el Bs de streaming cuadra con la tienda.
$tasa = (float) ($pdo->query("SELECT valor FROM configuracion WHERE clave='tasa_bcv'")->fetchColumn() ?: 0);
if ($tasa <= 0) $tasa = (float) (config()['tasa_bcv_default'] ?? 36.50);

/**
 * Publica/sincroniza una plataforma como PRODUCTO de la tienda (juego + paquete), para venderla en el
 * sitio público con entrega automática. Solo para la tienda (owner 0). Inserción dinámica (solo columnas
 * que existen) para no romper si el esquema de la tienda difiere. Si «En tienda» se apaga, desactiva el producto.
 */
function stream_sync_store_product(PDO $pdo, int $platId): void {
    if ($platId <= 0) return;
    try {
        $pl = $pdo->prepare("SELECT nombre, precio_publico, en_tienda FROM streaming_plataformas WHERE id=? AND owner_id=0");
        $pl->execute([$platId]);
        $plat = $pl->fetch(PDO::FETCH_ASSOC);
        if (!$plat) return;
        $enTienda = (int) ($plat['en_tienda'] ?? 0) === 1;
        $precio = round((float) ($plat['precio_publico'] ?? 0), 2);
        $cols = static function (string $t) use ($pdo): array { $out = []; try { foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) { $out[$c['Field']] = true; } } catch (Throwable $e) {} return $out; };
        $ins = static function (string $t, array $data) use ($pdo): int {
            $c = []; $ph = []; $v = [];
            foreach ($data as $k => $val) { $c[] = "`$k`"; $ph[] = '?'; $v[] = $val; }
            $pdo->prepare("INSERT INTO `$t` (" . implode(',', $c) . ") VALUES (" . implode(',', $ph) . ")")->execute($v);
            return (int) $pdo->lastInsertId();
        };
        $ex = $pdo->prepare("SELECT id, juego_id FROM juego_paquetes WHERE streaming_plataforma_id=? LIMIT 1");
        $ex->execute([$platId]);
        $row = $ex->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare("UPDATE juego_paquetes SET precio=?, activo=? WHERE id=?")->execute([$precio, $enTienda ? 1 : 0, (int) $row['id']]);
            try { $pdo->prepare("UPDATE juegos SET activo=? WHERE id=?")->execute([$enTienda ? 1 : 0, (int) $row['juego_id']]); } catch (Throwable $e) {}
            return;
        }
        if (!$enTienda || $precio <= 0) return;
        $jc = $cols('juegos'); $pc = $cols('juego_paquetes');
        $jd = ['nombre' => (string) $plat['nombre']];
        if (isset($jc['activo'])) { $jd['activo'] = 1; }
        if (isset($jc['slug'])) { $jd['slug'] = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $plat['nombre'])), '-') . '-str' . $platId; }
        if (isset($jc['descripcion'])) { $jd['descripcion'] = 'Servicio de streaming'; }
        $jgId = $ins('juegos', $jd);
        $pd = ['juego_id' => $jgId, 'nombre' => (string) $plat['nombre'] . ' · 1 mes', 'precio' => $precio];
        foreach (['activo' => 1, 'api_provider' => 'streaming', 'streaming_plataforma_id' => $platId] as $k => $val) { if (isset($pc[$k])) { $pd[$k] = $val; } }
        $ins('juego_paquetes', $pd);
    } catch (Throwable $e) {}
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['accion'] ?? '';
  $msg = '';
  try {
    if ($a === 'tipo_save') {
      $pid = (int) ($_POST['id'] ?? 0);
      $nombre = trim((string) ($_POST['nombre'] ?? ''));
      if ($nombre === '') throw new Exception('El nombre es obligatorio.');
      $categoria = trim((string) ($_POST['categoria'] ?? '')) ?: null;
      $etiqueta  = trim((string) ($_POST['etiqueta_comercial'] ?? '')) ?: null;
      $cond      = trim((string) ($_POST['condiciones_servicio'] ?? '')) ?: null;
      $pub       = ($_POST['precio_publico'] ?? '') !== '' ? (float) $_POST['precio_publico'] : null;
      $renov     = !empty($_POST['renovable_manual']) ? 1 : 0;
      $tienda    = !empty($_POST['en_tienda']) ? 1 : 0;
      $modoEnt   = (($_POST['modo_entrega'] ?? '') === 'email_manual') ? 'email_manual' : 'perfil';
      if ($pid) {
        $pdo->prepare("UPDATE streaming_plataformas SET nombre=?, categoria=?, etiqueta_comercial=?, precio_publico=?, precio_sugerido=?, condiciones_servicio=?, renovable_manual=?, en_tienda=? WHERE id=? AND owner_id=?")
            ->execute([$nombre, $categoria, $etiqueta, $pub, $pub, $cond, $renov, $tienda, $pid, $OWNER]);
      } else {
        $pdo->prepare("INSERT INTO streaming_plataformas (owner_id, nombre, categoria, etiqueta_comercial, precio_publico, precio_sugerido, condiciones_servicio, renovable_manual, en_tienda, activo, dias_default) VALUES (?,?,?,?,?,?,?,?,?,1,30)")
            ->execute([$OWNER, $nombre, $categoria, $etiqueta, $pub, $pub, $cond, $renov, $tienda]);
        $pid = (int) $pdo->lastInsertId();
      }
      // Modo de entrega (perfil = por asiento/stock · email_manual = se activa al correo del cliente, ej. Canva)
      try { $pdo->prepare("UPDATE streaming_plataformas SET modo_entrega=? WHERE id=? AND owner_id=?")->execute([$modoEnt, $pid, $OWNER]); } catch (Throwable $e) {}
      if ($verCostos) {
        $pdo->prepare("UPDATE streaming_plataformas SET costo=?, precio_distribuidor=? WHERE id=? AND owner_id=?")
            ->execute([
              ($_POST['costo'] ?? '') !== '' ? (float) $_POST['costo'] : null,
              ($_POST['precio_distribuidor'] ?? '') !== '' ? (float) $_POST['precio_distribuidor'] : null,
              $pid, $OWNER,
            ]);
      }
      // Logo: archivo subido (prioridad) o URL.
      if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name']) && (int) $_FILES['logo']['error'] === 0) {
        $info = @getimagesize($_FILES['logo']['tmp_name']);
        $mimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if ($info && isset($mimes[$info['mime']]) && (int) $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
          $dir = __DIR__ . '/../../uploads/streaming-logos';
          @mkdir($dir, 0755, true);
          $fn = 'plat-' . $pid . '.' . $mimes[$info['mime']];
          if (@move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $fn)) {
            $pdo->prepare("UPDATE streaming_plataformas SET logo_url=? WHERE id=? AND owner_id=?")->execute(['/uploads/streaming-logos/' . $fn . '?v=' . time(), $pid, $OWNER]);
          }
        } else { $msg = '⚠ Logo no válido (usa PNG/JPG/WEBP ≤2MB). El resto se guardó. '; }
      } elseif (trim((string) ($_POST['logo_url'] ?? '')) !== '') {
        $pdo->prepare("UPDATE streaming_plataformas SET logo_url=? WHERE id=? AND owner_id=?")->execute([trim((string) $_POST['logo_url']), $pid, $OWNER]);
      }
      // Sincroniza el producto de la tienda (venta automática en el sitio) — solo la tienda (owner 0).
      if ((int) $OWNER === 0) { stream_sync_store_product($pdo, (int) $pid); }
      $msg .= '✓ Tipo de plataforma guardado.' . (((int) $OWNER === 0) ? (!empty($_POST['en_tienda']) ? ' Publicado en la tienda.' : ' Retirado de la tienda (ya no aparece en el sitio).') : '');
    }
    elseif ($a === 'tipo_del') {
      $pdo->prepare("DELETE FROM streaming_plataformas WHERE id=? AND owner_id=?")->execute([(int) $_POST['id'], $OWNER]);
      $msg = '✓ Tipo eliminado.';
    }
    elseif ($a === 'precio_rev_masivo' && $verCostos) {
      // Precio de revendedor AUTOMÁTICO = costo × (1 + %/100), para TODAS las plataformas con costo cargado.
      $pct = max(0, min(100000, (float) str_replace(',', '.', (string) ($_POST['pct'] ?? 0))));
      $upd = $pdo->prepare("UPDATE streaming_plataformas SET precio_distribuidor = ROUND(costo * (1 + ?/100), 2) WHERE owner_id=? AND costo IS NOT NULL AND costo > 0");
      $upd->execute([$pct, $OWNER]);
      $msg = '✓ Precio de revendedor = costo + ' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '% aplicado a ' . $upd->rowCount() . ' plataforma(s).';
    }
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: tipos.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

$cols = "id, nombre, categoria, etiqueta_comercial, precio_publico, precio_sugerido, condiciones_servicio, renovable_manual, en_tienda, logo_url, color";
if ($verCostos) $cols .= ", costo, precio_distribuidor";
$hasModoCol = false;
try { $hasModoCol = (bool) $pdo->query("SHOW COLUMNS FROM streaming_plataformas LIKE 'modo_entrega'")->fetch(); } catch (Throwable $e) {}
if ($hasModoCol) $cols .= ", modo_entrega";
$tipos = $pdo->query("SELECT $cols FROM streaming_plataformas WHERE owner_id=$OWNER ORDER BY (categoria IS NULL), categoria, nombre")->fetchAll(PDO::FETCH_ASSOC);
$cats = [];
foreach ($tipos as $t) { $c = trim((string) ($t['categoria'] ?? '')); if ($c !== '' && !in_array($c, $cats, true)) $cats[] = $c; }

stream_head('Tipos de Plataforma', 'tipos');
?>
<?php if ($flash): $flashMal = (mb_substr(trim((string) $flash), 0, 1) === '⚠'); ?><div class="banner" style="margin-bottom:16px<?= $flashMal ? ';background:var(--bad-soft);color:var(--bad);border-color:var(--bad)' : '' ?>"><i data-lucide="<?= $flashMal ? 'alert-triangle' : 'check-circle' ?>"></i><span><?= h($flash) ?></span></div><?php endif; ?>

<div class="pagehd">
  <div>
    <h1>Tipos de <span class="nm">Plataformas</span></h1>
    <p>Catálogo de servicios, logos y precios.</p>
  </div>
  <button onclick="abrirTipo()" class="btn primary"><i data-lucide="plus-circle"></i> Nuevo Tipo</button>
</div>

<?php if ($verCostos): ?>
<form method="post" class="card" style="padding:14px 16px;margin-bottom:14px;display:flex;flex-wrap:wrap;align-items:center;gap:10px" onsubmit="return confirm('¿Aplicar este % a TODAS las plataformas con costo? Sobrescribe el precio de revendedor actual.')">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="accion" value="precio_rev_masivo">
  <div style="font-weight:700;font-size:13.5px"><i data-lucide="percent" style="width:15px;height:15px;vertical-align:-2px"></i> Precio revendedor automático</div>
  <span class="muted" style="font-size:12.5px">Precio revendedor = costo +</span>
  <input name="pct" type="number" step="0.1" min="0" value="15" style="width:82px;text-align:right;padding:7px 9px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text)">
  <span class="muted" style="font-size:12.5px">%</span>
  <button class="btn ghost" type="submit"><i data-lucide="wand-2"></i> Aplicar a todas</button>
  <span class="muted" style="font-size:11.5px;flex-basis:100%">Calcula el precio de revendedor de TODAS las plataformas que tengan costo cargado, de una sola vez. Igual puedes ajustar alguna a mano después.</span>
</form>
<?php endif; ?>

<div class="grid md:grid-cols-3 gap-3" style="margin-bottom:16px">
  <div class="md:col-span-1" style="position:relative">
    <i data-lucide="search" style="width:16px;height:16px;position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--faint)"></i>
    <input id="buscar" class="input" style="padding-left:34px" placeholder="Buscar por nombre de plataforma…">
  </div>
  <select id="fcat" class="input"><option value="">Todas las Categorías</option><?php foreach ($cats as $c): ?><option value="<?= h(mb_strtolower($c)) ?>"><?= h($c) ?></option><?php endforeach; ?></select>
</div>

<div id="grid" class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
  <?php foreach ($tipos as $t):
    $busca = mb_strtolower(($t['nombre'] ?? '') . ' ' . ($t['categoria'] ?? ''));
    $json = h(json_encode($t, JSON_UNESCAPED_UNICODE)); ?>
    <div class="card" style="padding:16px;display:flex;flex-direction:column;align-items:center;text-align:center" data-b="<?= h($busca) ?>" data-cat="<?= h(mb_strtolower((string) ($t['categoria'] ?? ''))) ?>">
      <?php if (!empty($t['logo_url'])): ?><img src="<?= h($t['logo_url']) ?>" style="width:64px;height:64px;border-radius:14px;object-fit:contain;background:var(--surface-2);border:1px solid var(--border);margin-bottom:8px" alt="">
      <?php else: ?><div style="width:64px;height:64px;border-radius:14px;display:grid;place-items:center;color:#fff;font-weight:800;font-size:22px;margin-bottom:8px;background:<?= h($t['color'] ?: '#3f4fb5') ?>"><?= h(mb_strtoupper(mb_substr($t['nombre'] ?? '?', 0, 1))) ?></div><?php endif; ?>
      <?php if (!empty($t['categoria'])): ?><span class="tag" style="margin-bottom:5px"><?= h($t['categoria']) ?></span><?php endif; ?>
      <div style="font-weight:700;color:var(--accent)"><?= h($t['nombre']) ?></div>
      <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:5px;margin:7px 0">
        <?php if ((int) ($t['renovable_manual'] ?? 0)): ?><span class="pill acc">Manual renovable</span>
        <?php else: ?><span class="tag">Stock</span><?php endif; ?>
        <?php if ((int) ($t['en_tienda'] ?? 1)): ?><span class="pill ok">En tienda</span><?php endif; ?>
      </div>
      <?php if (!empty($t['etiqueta_comercial'])): ?><div style="font-size:11px;color:var(--faint);margin-bottom:10px"><?= h($t['etiqueta_comercial']) ?></div><?php else: ?><div style="font-size:11px;color:var(--faint);margin-bottom:10px">Servicio estándar</div><?php endif; ?>
      <div style="display:flex;gap:8px;margin-top:2px">
        <button onclick='abrirTipo(<?= $json ?>)' class="btn primary" style="padding:8px" title="Editar"><i data-lucide="pencil"></i></button>
        <button onclick="delTipo(<?= (int) $t['id'] ?>,'<?= h($t['nombre']) ?>')" class="btn danger" style="padding:8px" title="Eliminar"><i data-lucide="trash-2"></i></button>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$tipos): ?><div class="empty" style="padding:40px 16px">Sin tipos todavía. Crea el primero con "Nuevo Tipo".</div><?php endif; ?>

<!-- Modal Nuevo/Editar Tipo -->
<div id="overlay" class="fixed inset-0 z-40 hidden items-start justify-center overflow-y-auto p-4" style="background:rgba(4,7,13,.55)" onclick="if(event.target===this)cerrar()">
  <div class="card" style="width:100%;max-width:42rem;margin:2rem 0">
    <div class="card-hd" style="justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px"><i data-lucide="tag"></i><h2 id="t-title">Nuevo Tipo de Plataforma</h2></div>
      <button onclick="cerrar()" class="iconbtn" style="width:32px;height:32px"><i data-lucide="x"></i></button>
    </div>
    <form method="post" enctype="multipart/form-data" class="card-bd">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="tipo_save"><input type="hidden" name="id" id="t-id">
      <div class="grid md:grid-cols-3 gap-3">
        <div class="field" style="margin-bottom:0"><label>Nombre de la plataforma</label><input name="nombre" id="t-nombre" required class="input" placeholder="Ej: Disney+"></div>
        <div class="field" style="margin-bottom:0"><label>Categoría</label><input name="categoria" id="t-cat" list="catlist" class="input" placeholder="Streaming"><datalist id="catlist"><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?><option value="Streaming"><option value="Utilidades"><option value="Música"></datalist></div>
        <div class="field" style="margin-bottom:0"><label>Etiqueta comercial</label><input name="etiqueta_comercial" id="t-etq" class="input" placeholder="Sin etiqueta"></div>
      </div>
      <div class="field" style="margin-top:12px;margin-bottom:0"><label>Logo de la plataforma</label>
        <div style="display:flex;align-items:center;gap:12px;margin-top:2px"><img id="t-logoprev" src="" style="width:48px;height:48px;border-radius:9px;object-fit:contain;background:var(--surface-2);border:1px solid var(--border)" class="hidden"><input type="file" id="t-logofile" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" style="font-size:13px;color:var(--muted)"></div>
        <input name="logo_url" id="t-logourl" class="input" style="margin-top:8px;font-size:12px" placeholder="…o pega una URL de logo (opcional)">
        <p style="font-size:11px;color:var(--faint);margin:4px 0 0">Imagen cuadrada recomendada (PNG/JPG/WEBP ≤2MB).</p></div>

      <div class="banner" style="margin-top:14px;font-size:12.5px"><i data-lucide="info"></i><span>El streaming se vende <b>por perfil</b> → estos 3 precios son <b>por perfil</b>.</span></div>
      <div class="grid <?= $verCostos ? 'md:grid-cols-3' : 'md:grid-cols-1' ?> gap-3" style="margin-top:12px;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:12px">
        <?php if ($verCostos): ?>
        <div class="field" style="margin-bottom:0"><label>Costo / perfil</label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--accent);color:#fff;border-radius:9px 0 0 9px;font-weight:700">$</span><input name="costo" id="t-costo" type="number" step="0.01" value="0.00" oninput="calc()" class="input" style="border-radius:0 9px 9px 0"></div><div style="font-size:11px;color:var(--muted);margin-top:3px" id="bs-costo"></div></div>
        <div class="field" style="margin-bottom:0"><label>Precio Revendedor / perfil</label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--warn);color:#fff;border-radius:9px 0 0 9px;font-weight:700">$</span><input name="precio_distribuidor" id="t-dist" type="number" step="0.01" value="0.00" oninput="calc()" class="input" style="border-radius:0 9px 9px 0"></div><div style="font-size:11px;color:var(--warn);margin-top:3px" id="bs-dist"></div></div>
        <?php endif; ?>
        <div class="field" style="margin-bottom:0"><label>Precio Tienda / perfil</label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--good);color:#fff;border-radius:9px 0 0 9px;font-weight:700">$</span><input name="precio_publico" id="t-pub" type="number" step="0.01" value="0.00" oninput="calc()" class="input" style="border-radius:0 9px 9px 0"></div><div style="font-size:11px;color:var(--good);margin-top:3px" id="bs-pub"></div></div>
      </div>
      <?php if ($verCostos): ?>
      <div class="grid grid-cols-2 gap-3" style="margin-top:12px">
        <div style="border:1px solid var(--border);border-radius:12px;padding:12px"><div style="font-size:10.5px;color:var(--faint);text-transform:uppercase;font-weight:700">Ganancia Revendedor / perfil</div><div style="display:flex;justify-content:space-between;font-size:13px;margin-top:5px"><span>% Ganancia</span><span style="font-weight:700;color:var(--good)" id="g-dist-pct">0.00%</span></div><div style="display:flex;justify-content:space-between;font-size:13px"><span>Ganancia</span><span style="font-weight:700;color:var(--good)" id="g-dist">$0.00</span></div></div>
        <div style="border:1px solid var(--border);border-radius:12px;padding:12px"><div style="font-size:10.5px;color:var(--faint);text-transform:uppercase;font-weight:700">Ganancia Tienda / perfil</div><div style="display:flex;justify-content:space-between;font-size:13px;margin-top:5px"><span>% Ganancia</span><span style="font-weight:700;color:var(--good)" id="g-pub-pct">0.00%</span></div><div style="display:flex;justify-content:space-between;font-size:13px"><span>Ganancia</span><span style="font-weight:700;color:var(--good)" id="g-pub">$0.00</span></div></div>
      </div>
      <?php endif; ?>
      <div class="field" style="margin-top:12px;margin-bottom:0"><label>Condiciones del Servicio</label><textarea name="condiciones_servicio" id="t-cond" rows="3" class="input" placeholder="Ej: No compartir accesos, máximo 1 dispositivo, no hay reembolsos…"></textarea><p style="font-size:11px;color:var(--faint);margin:4px 0 0">Reutilizable como variable en tus mensajes personalizados.</p></div>
      <div style="display:flex;flex-wrap:wrap;gap:16px;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:12px 16px;margin-top:12px">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--text)"><input type="checkbox" name="en_tienda" id="t-tienda" value="1" checked style="width:16px;height:16px;accent-color:var(--good)"> En tienda</label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--text)"><input type="checkbox" name="renovable_manual" id="t-renov" value="1" style="width:16px;height:16px;accent-color:var(--accent)"> Manual renovable</label>
      </div>
      <div class="field" style="margin-top:12px;margin-bottom:0"><label>Modo de entrega</label>
        <select name="modo_entrega" id="t-modo" class="input">
          <option value="perfil">Por perfil / asiento (stock de cuentas · entrega credenciales)</option>
          <option value="email_manual">Al correo del cliente · activación manual (ej. Canva)</option>
        </select>
        <p style="font-size:11px;color:var(--faint);margin:4px 0 0">«Al correo»: el cliente da su correo y tu empleado lo activa a mano; no entrega credenciales.</p>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px"><button type="button" onclick="cerrar()" class="btn ghost">Cancelar</button><button class="btn primary"><i data-lucide="save"></i> Guardar</button></div>
    </form>
  </div>
</div>

<form method="post" id="delform" class="hidden"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="tipo_del"><input type="hidden" name="id" id="del-id"></form>

<script>
  const TASA = <?= json_encode($tasa) ?>;
  const ov = document.getElementById('overlay');
  function cerrar(){ ov.classList.add('hidden'); ov.classList.remove('flex'); }
  function bs(v){ return TASA>0 && v>0 ? '≈ Bs '+(v*TASA).toLocaleString('es-VE',{minimumFractionDigits:2,maximumFractionDigits:2})+' VES' : ''; }
  function calc(){
    const costo=parseFloat(document.getElementById('t-costo')?.value)||0;
    const dist=parseFloat(document.getElementById('t-dist')?.value)||0;
    const pub=parseFloat(document.getElementById('t-pub')?.value)||0;
    const sc=document.getElementById('bs-costo'); if(sc) sc.textContent=bs(costo);
    const sd=document.getElementById('bs-dist'); if(sd) sd.textContent=bs(dist);
    document.getElementById('bs-pub').textContent=bs(pub);
    const gd=document.getElementById('g-dist'); if(gd){
      const gdist=dist-costo, gpub=pub-costo;
      gd.textContent='$'+gdist.toFixed(2);
      document.getElementById('g-dist-pct').textContent=(costo>0?(gdist/costo*100):0).toFixed(2)+'%';
      document.getElementById('g-pub').textContent='$'+gpub.toFixed(2);
      document.getElementById('g-pub-pct').textContent=(costo>0?(gpub/costo*100):0).toFixed(2)+'%';
    }
  }
  function abrirTipo(t){
    document.getElementById('t-logofile').value='';
    document.getElementById('t-title').textContent = t?'Editar Tipo de Plataforma':'Nuevo Tipo de Plataforma';
    document.getElementById('t-id').value = t? t.id : '';
    document.getElementById('t-nombre').value = t? (t.nombre||'') : '';
    document.getElementById('t-cat').value = t? (t.categoria||'') : '';
    document.getElementById('t-etq').value = t? (t.etiqueta_comercial||'') : '';
    document.getElementById('t-cond').value = t? (t.condiciones_servicio||'') : '';
    document.getElementById('t-pub').value = t? (t.precio_publico||t.precio_sugerido||'0.00') : '0.00';
    document.getElementById('t-tienda').checked = t? (t.en_tienda==1) : true;
    document.getElementById('t-renov').checked = t? (t.renovable_manual==1) : false;
    if(document.getElementById('t-modo')) document.getElementById('t-modo').value = (t&&t.modo_entrega)?t.modo_entrega:'perfil';
    if(document.getElementById('t-costo')){ document.getElementById('t-costo').value = t? (t.costo||'0.00') : '0.00'; document.getElementById('t-dist').value = t? (t.precio_distribuidor||'0.00') : '0.00'; }
    document.getElementById('t-logourl').value='';
    const prev=document.getElementById('t-logoprev'); if(t&&t.logo_url){prev.src=t.logo_url; prev.classList.remove('hidden');}else{prev.classList.add('hidden');}
    calc(); ov.classList.remove('hidden'); ov.classList.add('flex'); lucide.createIcons();
  }
  function delTipo(id,nombre){ if(confirm('¿Eliminar "'+nombre+'"? Esta acción no se puede deshacer.')){ document.getElementById('del-id').value=id; document.getElementById('delform').submit(); } }
  document.getElementById('buscar')?.addEventListener('input',function(){ const q=this.value.toLowerCase().trim(); filtra(q,document.getElementById('fcat').value); });
  document.getElementById('fcat')?.addEventListener('change',function(){ filtra(document.getElementById('buscar').value.toLowerCase().trim(), this.value); });
  function filtra(q,cat){ document.querySelectorAll('#grid > div').forEach(d=>{ const okq=!q||(d.dataset.b||'').includes(q); const okc=!cat||(d.dataset.cat===cat); d.style.display=(okq&&okc)?'':'none'; }); }
</script>
<?php stream_foot(); ?>
