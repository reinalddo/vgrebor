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
// #13: mensaje de renovación POR PLATAFORMA (columna nueva; se crea sola la 1ª vez).
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN msg_renovacion TEXT NULL"); } catch (Throwable $e) {}
// #5: descripción y garantía por plataforma (visuales en la compra; no se envían).
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN descripcion_venta TEXT NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN garantia TEXT NULL"); } catch (Throwable $e) {}
// #8: unidad de venta — 'perfil' (por defecto) o 'cuenta' (cuenta completa). Cambia el texto en la compra.
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN unidad_venta VARCHAR(10) NOT NULL DEFAULT 'perfil'"); } catch (Throwable $e) {}
// E: modo_entrega existía como ENUM('perfil','email_manual'); se AMPLÍA a VARCHAR para aceptar los
// modos nuevos 'invitacion' y 'activacion'. (ADD COLUMN no basta: la columna ya existe como ENUM.)
try { $pdo->exec("ALTER TABLE streaming_plataformas MODIFY COLUMN modo_entrega VARCHAR(20) NOT NULL DEFAULT 'perfil'"); } catch (Throwable $e) {}
// E: para plataformas por invitación/activación no hay stock real → un interruptor MANUAL disponible/agotado.
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN disponible TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
// Precio de RENOVACIÓN por perfil (lo que se cobra al renovar; si está vacío, se cobra el precio de venta).
try { $pdo->exec("ALTER TABLE streaming_plataformas ADD COLUMN precio_renovacion DECIMAL(12,2) NULL"); } catch (Throwable $e) {}

/**
 * Publica/sincroniza una plataforma como PRODUCTO de la tienda (juego + paquete), para venderla en el
 * sitio público con entrega automática. Solo para la tienda (owner 0). Inserción dinámica (solo columnas
 * que existen) para no romper si el esquema de la tienda difiere. Si «En tienda» se apaga, desactiva el producto.
 */
function stream_sync_store_product(PDO $pdo, int $platId): void {
    if ($platId <= 0) return;
    try {
        $pl = $pdo->prepare("SELECT nombre, precio_publico, en_tienda, descripcion_venta FROM streaming_plataformas WHERE id=? AND owner_id=0");
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
        // Sin esto el juego de streaming NO sale en la GRILLA de la tienda: las secciones
        // (JUEGOS, GIFT CARD, OTROS SERVICIOS…) son categorías "destacadas" y un juego sin
        // categoría solo aparece en el carrusel de arriba. Lo metemos en una categoría "Streaming".
        $assignCat = static function (int $juegoId) use ($pdo, $cols): void {
            if ($juegoId <= 0) return;
            try { $pdo->query("SELECT 1 FROM juego_categorias LIMIT 1"); } catch (Throwable $e) { return; } // sin tabla: no romper
            try {
                $catId = (int) ($pdo->query("SELECT id FROM juego_categorias WHERE slug='streaming' LIMIT 1")->fetchColumn() ?: 0);
                if ($catId <= 0) {
                    $cc = $cols('juego_categorias');
                    $d = ['nombre' => 'Streaming', 'slug' => 'streaming'];
                    if (isset($cc['activa']))    { $d['activa'] = 1; }
                    if (isset($cc['destacada'])) { $d['destacada'] = 1; }   // destacada => aparece como sección en la tienda
                    if (isset($cc['orden']))     { $d['orden'] = 50; }
                    if (isset($cc['icono']))     { $d['icono'] = 'clapperboard'; }
                    $k = []; $ph = []; $v = [];
                    foreach ($d as $kk => $vv) { $k[] = "`$kk`"; $ph[] = '?'; $v[] = $vv; }
                    $pdo->prepare("INSERT INTO juego_categorias (" . implode(',', $k) . ") VALUES (" . implode(',', $ph) . ")")->execute($v);
                    $catId = (int) $pdo->lastInsertId();
                }
                if ($catId > 0) { $pdo->prepare("INSERT IGNORE INTO juego_categoria_asignada (juego_id, categoria_id) VALUES (?, ?)")->execute([$juegoId, $catId]); }
            } catch (Throwable $e) {}
        };
        // Auto-mostrar/ocultar el TÍTULO de la categoría "Streaming": si no le queda NINGÚN juego
        // activo asignado, se pone activa=0 (la sección/título desaparece de la tienda); si tiene al
        // menos uno, activa=1. Así "se puede deshabilitar el título cuando no está disponible".
        $syncCatVis = static function () use ($pdo, $cols): void {
            try {
                $sc = (int) ($pdo->query("SELECT id FROM juego_categorias WHERE slug='streaming' LIMIT 1")->fetchColumn() ?: 0);
                if ($sc <= 0) return;
                $cc = $cols('juego_categorias');
                if (!isset($cc['activa'])) return;
                $n = (int) $pdo->query("SELECT COUNT(*) FROM juego_categoria_asignada jca JOIN juegos j ON j.id=jca.juego_id WHERE jca.categoria_id=$sc AND COALESCE(j.activo,1)=1")->fetchColumn();
                $pdo->prepare("UPDATE juego_categorias SET activa=? WHERE id=?")->execute([$n > 0 ? 1 : 0, $sc]);
            } catch (Throwable $e) {}
        };
        $ex = $pdo->prepare("SELECT id, juego_id FROM juego_paquetes WHERE streaming_plataforma_id=? LIMIT 1");
        $ex->execute([$platId]);
        $row = $ex->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare("UPDATE juego_paquetes SET precio=?, activo=? WHERE id=?")->execute([$precio, $enTienda ? 1 : 0, (int) $row['id']]);
            try { $pdo->prepare("UPDATE juegos SET activo=? WHERE id=?")->execute([$enTienda ? 1 : 0, (int) $row['juego_id']]); } catch (Throwable $e) {}
            try { $dv = trim((string) ($plat['descripcion_venta'] ?? '')); if ($dv !== '') { $pdo->prepare("UPDATE juegos SET descripcion=? WHERE id=?")->execute([$dv, (int) $row['juego_id']]); } } catch (Throwable $e) {}
            if ($enTienda) {
                $assignCat((int) $row['juego_id']);   // por si fue creado antes sin categoría
            } else {
                // Al quitar de la tienda, des-asignar de la categoría "Streaming" para que la sección
                // se auto-oculte cuando no queda nada disponible. (Además se puede deshabilitar/borrar
                // la categoría a mano en Admin → Categorías; este sync ya NO la re-crea ni re-activa.)
                try {
                    $sc = (int) ($pdo->query("SELECT id FROM juego_categorias WHERE slug='streaming' LIMIT 1")->fetchColumn() ?: 0);
                    if ($sc > 0) { $pdo->prepare("DELETE FROM juego_categoria_asignada WHERE juego_id=? AND categoria_id=?")->execute([(int) $row['juego_id'], $sc]); }
                } catch (Throwable $e) {}
            }
            $syncCatVis();
            return;
        }
        if (!$enTienda || $precio <= 0) return;
        $jc = $cols('juegos'); $pc = $cols('juego_paquetes');
        $jd = ['nombre' => (string) $plat['nombre']];
        if (isset($jc['activo'])) { $jd['activo'] = 1; }
        if (isset($jc['slug'])) { $jd['slug'] = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $plat['nombre'])), '-') . '-str' . $platId; }
        if (isset($jc['descripcion'])) { $jd['descripcion'] = trim((string) ($plat['descripcion_venta'] ?? '')) ?: 'Servicio de streaming'; }
        $jgId = $ins('juegos', $jd);
        $pd = ['juego_id' => $jgId, 'nombre' => (string) $plat['nombre'] . ' · 1 mes', 'precio' => $precio];
        foreach (['activo' => 1, 'api_provider' => 'streaming', 'streaming_plataforma_id' => $platId] as $k => $val) { if (isset($pc[$k])) { $pd[$k] = $val; } }
        $ins('juego_paquetes', $pd);
        $assignCat($jgId);
        $syncCatVis();
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
      // Modo de entrega: perfil (stock+credenciales) · invitacion (el cliente da SU correo, tú lo invitas
      // ej. Canva) · activacion (el cliente da correo+clave, tú lo activas ej. Spotify/YouTube).
      // 'email_manual' se mantiene como alias histórico de 'invitacion'.
      $me = (string) ($_POST['modo_entrega'] ?? '');
      if ($me === 'email_manual') $me = 'invitacion';
      $modoEnt = in_array($me, ['invitacion', 'activacion'], true) ? $me : 'perfil';
      if ($pid) {
        $pdo->prepare("UPDATE streaming_plataformas SET nombre=?, categoria=?, etiqueta_comercial=?, precio_publico=?, precio_sugerido=?, condiciones_servicio=?, renovable_manual=?, en_tienda=? WHERE id=? AND owner_id=?")
            ->execute([$nombre, $categoria, $etiqueta, $pub, $pub, $cond, $renov, $tienda, $pid, $OWNER]);
      } else {
        $pdo->prepare("INSERT INTO streaming_plataformas (owner_id, nombre, categoria, etiqueta_comercial, precio_publico, precio_sugerido, condiciones_servicio, renovable_manual, en_tienda, activo, dias_default) VALUES (?,?,?,?,?,?,?,?,?,1,30)")
            ->execute([$OWNER, $nombre, $categoria, $etiqueta, $pub, $pub, $cond, $renov, $tienda]);
        $pid = (int) $pdo->lastInsertId();
      }
      // Modo de entrega (perfil = por asiento/stock · invitacion/activacion = manual al correo del cliente)
      try { $pdo->prepare("UPDATE streaming_plataformas SET modo_entrega=? WHERE id=? AND owner_id=?")->execute([$modoEnt, $pid, $OWNER]); } catch (Throwable $e) {}
      // E: disponibilidad manual (para invitación/activación, que no tienen stock real). Por defecto Disponible.
      $disp = (($_POST['disponible'] ?? '1') === '0') ? 0 : 1;
      try { $pdo->prepare("UPDATE streaming_plataformas SET disponible=? WHERE id=? AND owner_id=?")->execute([$disp, $pid, $OWNER]); } catch (Throwable $e) {}
      // #8: unidad de venta (perfil / cuenta completa) — cambia el texto en la compra.
      $unidad = (($_POST['unidad_venta'] ?? '') === 'cuenta') ? 'cuenta' : 'perfil';
      try { $pdo->prepare("UPDATE streaming_plataformas SET unidad_venta=? WHERE id=? AND owner_id=?")->execute([$unidad, $pid, $OWNER]); } catch (Throwable $e) {}
      // #13: mensaje de renovación propio de esta plataforma (opcional). Vacío = usa la plantilla general.
      try { $pdo->prepare("UPDATE streaming_plataformas SET msg_renovacion=? WHERE id=? AND owner_id=?")->execute([trim((string) ($_POST['msg_renovacion'] ?? '')) ?: null, $pid, $OWNER]); } catch (Throwable $e) {}
      // #5: descripción y garantía (se ven en la compra; no se envían por WhatsApp).
      try { $pdo->prepare("UPDATE streaming_plataformas SET descripcion_venta=?, garantia=? WHERE id=? AND owner_id=?")->execute([trim((string) ($_POST['descripcion_venta'] ?? '')) ?: null, trim((string) ($_POST['garantia'] ?? '')) ?: null, $pid, $OWNER]); } catch (Throwable $e) {}
      if ($verCostos) {
        $pdo->prepare("UPDATE streaming_plataformas SET costo=?, precio_distribuidor=? WHERE id=? AND owner_id=?")
            ->execute([
              ($_POST['costo'] ?? '') !== '' ? (float) $_POST['costo'] : null,
              ($_POST['precio_distribuidor'] ?? '') !== '' ? (float) $_POST['precio_distribuidor'] : null,
              $pid, $OWNER,
            ]);
      }
      // Precio de RENOVACIÓN (por perfil). Vacío = al renovar se cobra el precio de venta normal.
      try { $pdo->prepare("UPDATE streaming_plataformas SET precio_renovacion=? WHERE id=? AND owner_id=?")->execute([($_POST['precio_renovacion'] ?? '') !== '' ? (float) $_POST['precio_renovacion'] : null, $pid, $OWNER]); } catch (Throwable $e) {}
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
    elseif ($a === 'precios_manual_masivo') {
      // #4: precios en LOTE por plataforma — venta (precio_publico) y reventa (precio_distribuidor).
      // Solo se guardan los que escribiste (los vacíos se dejan igual).
      $pubs  = (array) ($_POST['pub'] ?? []);
      $dists = (array) ($_POST['dist'] ?? []);
      $upPub  = $pdo->prepare("UPDATE streaming_plataformas SET precio_publico=?, precio_sugerido=? WHERE id=? AND owner_id=?");
      $upDist = $pdo->prepare("UPDATE streaming_plataformas SET precio_distribuidor=? WHERE id=? AND owner_id=?");
      $n = 0; $ids = array_unique(array_merge(array_map('intval', array_keys($pubs)), array_map('intval', array_keys($dists))));
      foreach ($ids as $ppid) {
        $ppid = (int) $ppid; if ($ppid <= 0) continue;
        if (isset($pubs[$ppid]) && trim((string) $pubs[$ppid]) !== '') { $pv = (float) str_replace(',', '.', (string) $pubs[$ppid]); $upPub->execute([$pv, $pv, $ppid, $OWNER]); $n++; }
        if ($verCostos && isset($dists[$ppid]) && trim((string) $dists[$ppid]) !== '') { $dv = (float) str_replace(',', '.', (string) $dists[$ppid]); $upDist->execute([$dv, $ppid, $OWNER]); }
      }
      // Re-sincroniza el precio en la tienda para las plataformas que estén publicadas.
      if ((int) $OWNER === 0) { foreach ($ids as $ppid) { stream_sync_store_product($pdo, (int) $ppid); } }
      $msg = "✓ Precios actualizados en $n plataforma(s).";
    }
  } catch (Throwable $e) { $msg = '⚠ ' . $e->getMessage(); }
  header('Location: tipos.php?msg=' . urlencode($msg));
  exit;
}
$flash = (string) ($_GET['msg'] ?? '');

$cols = "id, nombre, categoria, etiqueta_comercial, precio_publico, precio_sugerido, precio_renovacion, condiciones_servicio, renovable_manual, en_tienda, logo_url, color";
if ($verCostos) $cols .= ", costo, precio_distribuidor";
$hasModoCol = false;
try { $hasModoCol = (bool) $pdo->query("SHOW COLUMNS FROM streaming_plataformas LIKE 'modo_entrega'")->fetch(); } catch (Throwable $e) {}
if ($hasModoCol) $cols .= ", modo_entrega";
$hasMsgCol = false;
try { $hasMsgCol = (bool) $pdo->query("SHOW COLUMNS FROM streaming_plataformas LIKE 'msg_renovacion'")->fetch(); } catch (Throwable $e) {}
if ($hasMsgCol) $cols .= ", msg_renovacion";
try { if ($pdo->query("SHOW COLUMNS FROM streaming_plataformas LIKE 'unidad_venta'")->fetch()) $cols .= ", unidad_venta"; } catch (Throwable $e) {}
try { if ($pdo->query("SHOW COLUMNS FROM streaming_plataformas LIKE 'descripcion_venta'")->fetch()) $cols .= ", descripcion_venta"; } catch (Throwable $e) {}
try { if ($pdo->query("SHOW COLUMNS FROM streaming_plataformas LIKE 'garantia'")->fetch()) $cols .= ", garantia"; } catch (Throwable $e) {}
try { if ($pdo->query("SHOW COLUMNS FROM streaming_plataformas LIKE 'disponible'")->fetch()) $cols .= ", disponible"; } catch (Throwable $e) {}
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

<?php if ($tipos): ?>
<details class="card" style="margin-bottom:14px">
  <summary style="cursor:pointer;padding:12px 16px;font-weight:700;font-size:13.5px"><i data-lucide="dollar-sign" style="width:15px;height:15px;vertical-align:-2px"></i> Cambiar precios en lote (venta<?= $verCostos ? ' y reventa' : '' ?>)</summary>
  <form method="post" style="padding:0 16px 16px">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="accion" value="precios_manual_masivo">
    <p style="font-size:11.5px;color:var(--faint);margin:0 0 10px">Cambia solo los que quieras y pulsa «Guardar precios». Los vacíos quedan igual. (El precio de <b>venta</b> es lo que paga el cliente en la tienda<?= $verCostos ? '; el de <b>reventa</b> es lo que le cobras al revendedor' : '' ?>.)</p>
    <div class="overflow-x-auto thin">
      <table class="dtable">
        <thead><tr><th>Plataforma</th><th style="text-align:right">Precio venta</th><?php if ($verCostos): ?><th style="text-align:right">Precio reventa</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($tipos as $t): ?>
          <tr>
            <td style="font-weight:650"><?= h($t['nombre']) ?></td>
            <td style="text-align:right"><input name="pub[<?= (int) $t['id'] ?>]" type="number" step="0.01" min="0" value="<?= h(number_format((float) ($t['precio_publico'] ?? 0), 2, '.', '')) ?>" class="input" style="width:110px;text-align:right;display:inline-block"></td>
            <?php if ($verCostos): ?><td style="text-align:right"><input name="dist[<?= (int) $t['id'] ?>]" type="number" step="0.01" min="0" value="<?= h(number_format((float) ($t['precio_distribuidor'] ?? 0), 2, '.', '')) ?>" class="input" style="width:110px;text-align:right;display:inline-block"></td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn primary"><i data-lucide="save"></i> Guardar precios</button></div>
  </form>
</details>
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

      <div class="banner" style="margin-top:14px;font-size:12.5px"><i data-lucide="info"></i><span id="unidadBanner">El streaming se vende <b>por perfil</b> → estos 3 precios son <b>por perfil</b>.</span></div>
      <div class="grid <?= $verCostos ? 'md:grid-cols-3' : 'md:grid-cols-1' ?> gap-3" style="margin-top:12px;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:12px">
        <?php if ($verCostos): ?>
        <div class="field" style="margin-bottom:0"><label>Costo / <span class="ul">perfil</span></label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--accent);color:#fff;border-radius:9px 0 0 9px;font-weight:700">$</span><input name="costo" id="t-costo" type="number" step="0.01" value="0.00" oninput="calc()" class="input" style="border-radius:0 9px 9px 0"></div><div style="font-size:11px;color:var(--muted);margin-top:3px" id="bs-costo"></div></div>
        <div class="field" style="margin-bottom:0"><label>Precio Revendedor / <span class="ul">perfil</span></label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--warn);color:#fff;border-radius:9px 0 0 9px;font-weight:700">$</span><input name="precio_distribuidor" id="t-dist" type="number" step="0.01" value="0.00" oninput="calc()" class="input" style="border-radius:0 9px 9px 0"></div><div style="font-size:11px;color:var(--warn);margin-top:3px" id="bs-dist"></div></div>
        <?php endif; ?>
        <div class="field" style="margin-bottom:0"><label>Precio Tienda / <span class="ul">perfil</span></label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--good);color:#fff;border-radius:9px 0 0 9px;font-weight:700">$</span><input name="precio_publico" id="t-pub" type="number" step="0.01" value="0.00" oninput="calc()" class="input" style="border-radius:0 9px 9px 0"></div><div style="font-size:11px;color:var(--good);margin-top:3px" id="bs-pub"></div></div>
        <div class="field" style="margin-bottom:0"><label>Precio Renovación / <span class="ul">perfil</span> <span style="color:var(--faint);font-weight:400">(opcional)</span></label><div style="display:flex"><span style="padding:0 10px;display:grid;place-items:center;background:var(--accent);color:#fff;border-radius:9px 0 0 9px;font-weight:700">$</span><input name="precio_renovacion" id="t-prenov" type="number" step="0.01" class="input" style="border-radius:0 9px 9px 0" placeholder="vacío = igual que venta"></div><div style="font-size:11px;color:var(--muted);margin-top:3px">Lo que se cobra al <b>renovar</b>. Vacío = mismo precio de venta.</div></div>
      </div>
      <?php if ($verCostos): ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-top:12px">
        <div style="border:1px solid var(--border);border-radius:12px;padding:12px"><div style="font-size:10.5px;color:var(--faint);text-transform:uppercase;font-weight:700">Ganancia Revendedor / <span class="ul">perfil</span></div><div style="display:flex;justify-content:space-between;font-size:13px;margin-top:5px"><span>% Ganancia</span><span style="font-weight:700;color:var(--good)" id="g-dist-pct">0.00%</span></div><div style="display:flex;justify-content:space-between;font-size:13px"><span>Ganancia</span><span style="font-weight:700;color:var(--good)" id="g-dist">$0.00</span></div></div>
        <div style="border:1px solid var(--border);border-radius:12px;padding:12px"><div style="font-size:10.5px;color:var(--faint);text-transform:uppercase;font-weight:700">Ganancia Tienda / <span class="ul">perfil</span></div><div style="display:flex;justify-content:space-between;font-size:13px;margin-top:5px"><span>% Ganancia</span><span style="font-weight:700;color:var(--good)" id="g-pub-pct">0.00%</span></div><div style="display:flex;justify-content:space-between;font-size:13px"><span>Ganancia</span><span style="font-weight:700;color:var(--good)" id="g-pub">$0.00</span></div></div>
      </div>
      <?php endif; ?>
      <div class="field" style="margin-top:12px;margin-bottom:0"><label>Condiciones del Servicio</label><textarea name="condiciones_servicio" id="t-cond" rows="3" class="input" placeholder="Ej: No compartir accesos, máximo 1 dispositivo, no hay reembolsos…"></textarea><p style="font-size:11px;color:var(--faint);margin:4px 0 0">Reutilizable como variable en tus mensajes personalizados.</p></div>
      <div class="field" style="margin-top:12px;margin-bottom:0"><label>Mensaje de renovación (solo para esta plataforma)</label><textarea name="msg_renovacion" id="t-msgrenov" rows="3" class="input" placeholder="Déjalo vacío para usar el mensaje general. Ej: ¡Hola {nombre}! Tu {plataforma} vence en {diasRestantes} día(s) ({fechaRenovacion}). ¿Te lo renovamos? 🙌"></textarea><p style="font-size:11px;color:var(--faint);margin:4px 0 0">Variables: <b>{nombre}</b>, <b>{plataforma}</b>, <b>{diasRestantes}</b>, <b>{fechaRenovacion}</b>, <b>{monto}</b>, <b>{perfil}</b>, <b>{pin}</b>. Vacío = usa el mensaje general.</p></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-top:12px">
        <div class="field" style="margin-bottom:0"><label>Descripción (se ve en la compra)</label><textarea name="descripcion_venta" id="t-desc" rows="3" class="input" placeholder="Ej: 📌 LA CUENTA COMPLETA · Entrega automática por email · 100% original…"></textarea></div>
        <div class="field" style="margin-bottom:0"><label>Garantía (se ve en la compra)</label><textarea name="garantia" id="t-garantia" rows="3" class="input" placeholder="Ej: Reposición del mismo (24/72h). No hay devolución de dinero."></textarea></div>
      </div>
      <p style="font-size:11px;color:var(--faint);margin:4px 0 0">La descripción y la garantía se muestran al comprar (no se envían por WhatsApp).</p>
      <div style="display:flex;flex-wrap:wrap;gap:16px;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:12px 16px;margin-top:12px">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--text)"><input type="checkbox" name="en_tienda" id="t-tienda" value="1" checked style="width:16px;height:16px;accent-color:var(--good)"> En tienda</label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--text)"><input type="checkbox" name="renovable_manual" id="t-renov" value="1" style="width:16px;height:16px;accent-color:var(--accent)"> Manual renovable</label>
      </div>
      <div class="field" style="margin-top:12px;margin-bottom:0"><label>Se vende como</label>
        <select name="unidad_venta" id="t-unidad" class="input" onchange="updateUnidad()">
          <option value="perfil">Por perfil (una pantalla/asiento de la cuenta)</option>
          <option value="cuenta">Cuenta completa (todo el acceso)</option>
        </select>
        <p style="font-size:11px;color:var(--faint);margin:4px 0 0">Cambia el texto que ve el cliente al comprar («perfil» o «cuenta completa») y cómo se listan en Perfiles.</p>
      </div>
      <div class="field" style="margin-top:12px;margin-bottom:0"><label>Modo de entrega</label>
        <select name="modo_entrega" id="t-modo" class="input" onchange="updateModo()">
          <option value="perfil">Por perfil / asiento (stock de cuentas · entrega credenciales)</option>
          <option value="invitacion">Por invitación · el cliente da SU correo y tú lo invitas (ej. Canva)</option>
          <option value="activacion">Por activación · el cliente da correo + clave y tú lo activas (ej. Spotify / YouTube)</option>
        </select>
        <p style="font-size:11px;color:var(--faint);margin:4px 0 0">«Invitación / Activación»: NO necesitas cargar cuentas ni credenciales. Al comprar, el cliente/revendedor escribe sus datos y a ti te llega un aviso para activarlo a mano.</p>
      </div>
      <div class="field" id="t-disp-wrap" style="margin-top:12px;margin-bottom:0;display:none"><label>Disponibilidad (solo invitación/activación)</label>
        <select name="disponible" id="t-disp" class="input">
          <option value="1">Disponible (se puede comprar)</option>
          <option value="0">Agotado (no se puede comprar por ahora)</option>
        </select>
        <p style="font-size:11px;color:var(--faint);margin:4px 0 0">Como estas plataformas no llevan stock, aquí marcas a mano si hay cupos. En «Agotado» el cliente no puede comprarla.</p>
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
    { const mr=document.getElementById('t-msgrenov'); if(mr) mr.value = t? (t.msg_renovacion||'') : ''; }
    { const de=document.getElementById('t-desc'); if(de) de.value = t? (t.descripcion_venta||'') : ''; const ga=document.getElementById('t-garantia'); if(ga) ga.value = t? (t.garantia||'') : ''; }
    document.getElementById('t-pub').value = t? (t.precio_publico||t.precio_sugerido||'0.00') : '0.00';
    document.getElementById('t-tienda').checked = t? (t.en_tienda==1) : true;
    document.getElementById('t-renov').checked = t? (t.renovable_manual==1) : false;
    if(document.getElementById('t-modo')){ var me=(t&&t.modo_entrega)?t.modo_entrega:'perfil'; if(me==='email_manual') me='invitacion'; document.getElementById('t-modo').value=me; }
    if(document.getElementById('t-disp')) document.getElementById('t-disp').value = (t && (t.disponible==0||t.disponible==='0')) ? '0' : '1';
    if(document.getElementById('t-unidad')) document.getElementById('t-unidad').value = (t&&t.unidad_venta)?t.unidad_venta:'perfil';
    if(document.getElementById('t-costo')){ document.getElementById('t-costo').value = t? (t.costo||'0.00') : '0.00'; document.getElementById('t-dist').value = t? (t.precio_distribuidor||'0.00') : '0.00'; }
    if(document.getElementById('t-prenov')) document.getElementById('t-prenov').value = (t && t.precio_renovacion!=null) ? t.precio_renovacion : '';
    document.getElementById('t-logourl').value='';
    const prev=document.getElementById('t-logoprev'); if(t&&t.logo_url){prev.src=t.logo_url; prev.classList.remove('hidden');}else{prev.classList.add('hidden');}
    calc(); updateUnidad(); updateModo(); ov.classList.remove('hidden'); ov.classList.add('flex'); lucide.createIcons();
  }
  // Muestra el selector Disponible/Agotado solo para invitación/activación (las que no llevan stock).
  function updateModo(){ var m=document.getElementById('t-modo'); var w=document.getElementById('t-disp-wrap'); if(!m||!w) return; var man=(m.value==='invitacion'||m.value==='activacion'); w.style.display=man?'block':'none'; }
  // Cambia los textos "perfil" ↔ "cuenta completa" del modal según "Se vende como".
  function updateUnidad(){
    var sel=document.getElementById('t-unidad'); if(!sel) return;
    var esCuenta = sel.value==='cuenta';
    var u = esCuenta ? 'cuenta' : 'perfil';
    document.querySelectorAll('#ov .ul, .ul').forEach(function(s){ s.textContent = u; });
    var b=document.getElementById('unidadBanner');
    if(b){ b.innerHTML = esCuenta
      ? 'Esta plataforma se vende <b>por cuenta completa</b> → estos precios son <b>por cuenta</b>.'
      : 'El streaming se vende <b>por perfil</b> → estos 3 precios son <b>por perfil</b>.'; }
  }
  function delTipo(id,nombre){ if(confirm('¿Eliminar "'+nombre+'"? Esta acción no se puede deshacer.')){ document.getElementById('del-id').value=id; document.getElementById('delform').submit(); } }
  document.getElementById('buscar')?.addEventListener('input',function(){ const q=this.value.toLowerCase().trim(); filtra(q,document.getElementById('fcat').value); });
  document.getElementById('fcat')?.addEventListener('change',function(){ filtra(document.getElementById('buscar').value.toLowerCase().trim(), this.value); });
  function filtra(q,cat){ document.querySelectorAll('#grid > div').forEach(d=>{ const okq=!q||(d.dataset.b||'').includes(q); const okc=!cat||(d.dataset.cat===cat); d.style.display=(okq&&okc)?'':'none'; }); }
</script>
<?php stream_foot(); ?>
