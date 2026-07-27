<?php
/**
 * admin/stream/precios-recargas.php — El admin fija el PRECIO DE REVENDEDOR de cada paquete de
 * recarga (columna juego_paquetes.precio_revendedor). Página propia del módulo para no tocar el
 * admin de la tienda. Solo admin/root de la tienda.
 */
define('CONEC_ADMIN', true);
require __DIR__ . '/../_auth.php';
require __DIR__ . '/_layout.php';
admin_require_login();

if (stream_ctx() !== 'admin' || !admin_es_admin()) {
    http_response_code(403);
    exit('Solo el administrador de la tienda puede fijar precios de revendedor.');
}
$pdo = db();

// Asegurar la columna (por si esta página se abre antes que otra del módulo).
try { $pdo->exec("ALTER TABLE juego_paquetes ADD COLUMN precio_revendedor DECIMAL(12,2) NULL"); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $msg = '';
    if (($_POST['accion'] ?? '') === 'guardar_precios') {
        $precios = (array) ($_POST['precio_rev'] ?? []);
        $up = $pdo->prepare("UPDATE juego_paquetes SET precio_revendedor = ? WHERE id = ?");
        $n = 0;
        foreach ($precios as $pid => $val) {
            $pid = (int) $pid;
            if ($pid <= 0) { continue; }
            $val = trim((string) $val);
            $precio = ($val === '') ? null : round((float) $val, 2);
            try { $up->execute([$precio, $pid]); $n++; } catch (Throwable $e) {}
        }
        $msg = "✓ Precios de revendedor guardados ($n paquete(s)).";
    }
    header('Location: precios-recargas.php?msg=' . urlencode($msg));
    exit;
}
$flash = (string) ($_GET['msg'] ?? '');

// Costo real de las recargas (lo que paga la tienda al proveedor): viene de la API giftven en vivo.
// Se cachea en una sola llamada. Si no hay API/credenciales, el costo queda vacío y se cae al precio tienda.
@require_once __DIR__ . '/../../includes/recargas_api.php';
$apiOk = false;
try { if (function_exists('recargas_api_fetch_products')) { recargas_api_fetch_products(); $apiOk = true; } } catch (Throwable $e) {}

// Juegos + sus paquetes (costo del proveedor, precio de tienda y precio de revendedor).
$juegos = [];
try {
    $rows = $pdo->query("SELECT p.id, p.juego_id, p.nombre, p.cantidad, p.precio, p.precio_revendedor, p.paquete_api, p.activo,
            j.nombre AS juego_nombre
        FROM juego_paquetes p JOIN juegos j ON j.id = p.juego_id
        WHERE p.activo = 1 AND j.activo = 1
        ORDER BY j.nombre, p.orden, p.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        // Costo del proveedor (giftven) por el id de producto = paquete_api.
        $r['costo'] = null;
        $papi = (int) ($r['paquete_api'] ?? 0);
        if ($papi > 0 && function_exists('recargas_api_fetch_product_by_id')) {
            try { $prod = recargas_api_fetch_product_by_id($papi); if (is_array($prod) && isset($prod['precio'])) { $r['costo'] = (float) $prod['precio']; } } catch (Throwable $e) {}
        }
        $juegos[$r['juego_nombre']][] = $r;
    }
} catch (Throwable $e) {}

stream_head('Precios de revendedor', 'precios-recargas');
?>
<?php if ($flash): ?><div class="banner" style="margin-bottom:16px"><i data-lucide="check-circle"></i><?= h($flash) ?></div><?php endif; ?>

<div class="pagehd">
  <div><h1>Precios de <span class="nm">revendedor (recargas)</span></h1>
  <p>Fija el precio que paga el revendedor por cada paquete. Vacío = no lo puede comprar como revendedor.</p></div>
</div>

<?php if (!$juegos): ?>
  <div class="card" style="padding:40px;text-align:center;color:var(--muted)">
    <div style="font-size:38px">🎮</div>
    No hay juegos/paquetes activos en la tienda todavía.
  </div>
<?php else: ?>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="accion" value="guardar_precios">
  <!-- Precio automático por porcentaje sobre el COSTO: llena todos los precios de revendedor de una vez -->
  <div class="card" style="padding:14px 16px;margin-bottom:14px;display:flex;flex-wrap:wrap;align-items:center;gap:10px">
    <div style="font-weight:700;font-size:13.5px"><i data-lucide="percent" style="width:15px;height:15px;vertical-align:-2px"></i> Precio automático</div>
    <span class="muted" style="font-size:12.5px">Precio revendedor = <b>costo</b> +</span>
    <input id="pctGlobal" type="number" step="0.1" min="0" max="1000" value="15" style="width:80px;text-align:right;padding:7px 9px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text)">
    <span class="muted" style="font-size:12.5px">%</span>
    <button type="button" class="btn ghost" onclick="aplicarPct()"><i data-lucide="wand-2"></i> Aplicar a todos</button>
    <span class="muted" style="font-size:11.5px;flex-basis:100%">Toma el <b>costo</b> del proveedor de cada paquete y le suma tu %, para que el revendedor pague menos que en tu tienda. Luego puedes ajustar alguno a mano y pulsar «Guardar todos». Los paquetes cuyo costo aún no está disponible (<b>—</b>) no se calculan solos: ponles el precio a mano.</span>
  </div>
  <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
    <button class="btn primary" type="submit"><i data-lucide="save"></i> Guardar todos</button>
  </div>
  <?php foreach ($juegos as $juegoNombre => $paquetes): ?>
    <div class="card" style="margin-bottom:14px;padding:0;overflow:hidden">
      <div class="card-hd" style="padding:12px 16px"><i data-lucide="gamepad-2"></i><h2><?= h($juegoNombre) ?></h2><span class="pill-count"><?= count($paquetes) ?></span></div>
      <div class="overflow-x-auto thin"><table class="dtable">
        <thead><tr><th>Paquete</th><th>Cantidad</th><th style="text-align:right">Costo</th><th style="text-align:right">Precio tienda</th><th style="text-align:right;width:180px">Precio revendedor ($)</th></tr></thead>
        <tbody>
        <?php foreach ($paquetes as $p): $costo = $p['costo']; $base = ($costo !== null && $costo > 0) ? (float) $costo : (float) $p['precio']; ?>
          <tr>
            <td style="font-weight:700"><?= h($p['nombre']) ?></td>
            <td class="muted"><?= h($p['cantidad'] ?: '—') ?></td>
            <td class="tnum" style="text-align:right;color:var(--good)"><?= $costo !== null ? '$' . number_format((float) $costo, 2) : '<span style="color:var(--faint)">—</span>' ?></td>
            <td class="tnum" style="text-align:right;color:var(--muted)">$<?= number_format((float) $p['precio'], 2) ?></td>
            <td style="text-align:right">
              <input type="number" step="0.01" min="0" name="precio_rev[<?= (int) $p['id'] ?>]"
                     data-costo="<?= ($costo !== null && $costo > 0) ? h(number_format((float) $costo, 2, '.', '')) : '' ?>"
                     value="<?= $p['precio_revendedor'] === null ? '' : h(number_format((float) $p['precio_revendedor'], 2, '.', '')) ?>"
                     placeholder="—" class="input precio-rev-input" style="width:130px;text-align:right;padding:7px 9px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text)">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  <?php endforeach; ?>
  <div style="display:flex;justify-content:flex-end;margin-top:4px">
    <button class="btn primary" type="submit"><i data-lucide="save"></i> Guardar todos</button>
  </div>
</form>
<script>
function aplicarPct(){
  var pct = parseFloat(document.getElementById('pctGlobal').value || '0');
  if (isNaN(pct) || pct < 0){ alert('Escribe un porcentaje válido.'); return; }
  var factor = 1 + (pct/100); // precio revendedor = costo + %
  var inputs = document.querySelectorAll('.precio-rev-input');
  inputs.forEach(function(inp){
    var costo = parseFloat(inp.getAttribute('data-costo') || '0');
    if (costo > 0){ inp.value = (costo * factor).toFixed(2); }
  });
}
</script>
<?php endif; ?>
<?php stream_foot();
